<?php
/**
 * Resumable walk of the site files.
 *
 * Breadth-first: a queue file holds directories still to read and a list
 * file receives one line per entry ("d <path>" or "f <path>", relative to
 * the site root). Both files are append-only; their committed sizes are
 * saved with the state at the end of each step and the files are truncated
 * back to them when a step starts, so a step killed halfway is simply redone.
 *
 * Skipped and counted: symbolic links (never followed, so no loops and no
 * files from outside the site), unreadable entries, names containing line
 * breaks, sockets and other special files, and the excluded paths. The first
 * symbolic links and unreadable entries are also kept, in walk order, in a
 * small sample saved with the state, so the preflight can name them.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

use BladeCP\Migrator\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * File scanner.
 */
final class File_Scanner {

	/**
	 * Most entries kept in the sample of skipped entries.
	 */
	const SAMPLE_SIZE = 5;

	/**
	 * Sample entry types: a symbolic link, or an entry PHP cannot read.
	 */
	const SAMPLE_LINK       = 'link';
	const SAMPLE_UNREADABLE = 'unreadable';

	/**
	 * Site root without trailing slash.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Queue file path.
	 *
	 * @var string
	 */
	private $queue_file;

	/**
	 * List file path (null when only counting).
	 *
	 * @var string|null
	 */
	private $list_file;

	/**
	 * Excluded relative paths.
	 *
	 * @var string[]
	 */
	private $excluded;

	/**
	 * Constructor.
	 *
	 * @param string      $root       Site root.
	 * @param string      $queue_file Queue file.
	 * @param string|null $list_file  List file, or null to only count.
	 * @param string[]    $excluded   Relative paths to skip (files or folders).
	 */
	public function __construct( string $root, string $queue_file, ?string $list_file, array $excluded ) {
		$this->root       = rtrim( $root, '/\\' );
		$this->queue_file = $queue_file;
		$this->list_file  = $list_file;
		$this->excluded   = array();
		foreach ( $excluded as $path ) {
			$path = trim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( '' !== $path ) {
				$this->excluded[ $path ] = true;
			}
		}
	}

	/**
	 * Initial state.
	 */
	public static function initial_state(): array {
		return array(
			'started'    => false,
			'done'       => false,
			'queue_pos'  => 0,
			'queue_size' => 0,
			'list_size'  => 0,
			'files'      => 0,
			'bytes'      => 0,
			'dirs'       => 0,
			'symlinks'   => 0,
			'unreadable' => 0,
			'skipped'    => 0,
			// First entries counted in symlinks and unreadable, in walk order:
			// each one is array( 'type' => SAMPLE_*, 'path' => display_path() ).
			'sample'     => array(),
		);
	}

	/**
	 * Converts absolute paths to exclusions relative to the root (paths
	 * outside the root are ignored).
	 *
	 * @param string   $root      Site root.
	 * @param string[] $absolutes Absolute paths.
	 * @return string[]
	 */
	public static function relative_exclusions( string $root, array $absolutes ): array {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$out  = array();
		foreach ( $absolutes as $absolute ) {
			$absolute = rtrim( str_replace( '\\', '/', (string) $absolute ), '/' );
			if ( 0 === strpos( $absolute . '/', $root ) && $absolute . '/' !== $root ) {
				$out[] = substr( $absolute, strlen( $root ) );
			}
		}
		return $out;
	}

	/**
	 * Runs until the deadline (always at least one directory).
	 *
	 * @param array    $state    State.
	 * @param Deadline $deadline Budget.
	 * @throws \RuntimeException When the work files cannot be written.
	 */
	public function step( array $state, Deadline $deadline ): array {
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		if ( empty( $state['started'] ) ) {
			$state = self::initial_state();
			self::truncate( $this->queue_file, 0 );
			if ( null !== $this->list_file ) {
				self::truncate( $this->list_file, 0 );
			}
			$queue = self::open( $this->queue_file, 'ab' );
			Plain_Sink::write_all( $queue, "\n" );
			$state['started']    = true;
			$state['queue_size'] = 1;
		} else {
			// Drop whatever a step killed halfway wrote after the last commit.
			self::truncate( $this->queue_file, (int) $state['queue_size'] );
			if ( null !== $this->list_file ) {
				self::truncate( $this->list_file, (int) $state['list_size'] );
			}
			$queue = self::open( $this->queue_file, 'ab' );
		}
		$list    = null === $this->list_file ? null : self::open( $this->list_file, 'ab' );
		$reader  = self::open( $this->queue_file, 'rb' );
		$working = $state;
		$first   = true;
		while ( true ) {
			if ( ! $first && $deadline->reached() ) {
				break;
			}
			fflush( $queue );
			fseek( $reader, (int) $working['queue_pos'] );
			$line = fgets( $reader );
			if ( false === $line ) {
				break;
			}
			$first                 = false;
			$working['queue_pos'] += strlen( $line );
			$working               = $this->read_directory( rtrim( $line, "\n" ), $working, $queue, $list );
		}
		fflush( $queue );
		$working['queue_size'] = self::size_of( $queue );
		if ( null !== $list ) {
			fflush( $list );
			$working['list_size'] = self::size_of( $list );
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		fclose( $queue ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $reader ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$working['done'] = $working['queue_pos'] >= $working['queue_size'];
		return $working;
	}

	/**
	 * Reads one directory: subfolders go to the queue, entries to the list.
	 *
	 * @param string        $relative Directory relative to the root ('' = root).
	 * @param array         $state    State.
	 * @param resource      $queue    Queue handle.
	 * @param resource|null $list     List handle.
	 */
	private function read_directory( string $relative, array $state, $queue, $list ): array {
		$absolute = '' === $relative ? $this->root : $this->root . '/' . $relative;
		$handle   = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable folders are counted, not fatal.
		if ( false === $handle ) {
			return self::skip( $state, self::SAMPLE_UNREADABLE, $relative );
		}
		$names = array();
		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' !== $name && '..' !== $name ) {
				$names[] = $name;
			}
		}
		closedir( $handle );
		sort( $names, SORT_STRING );
		foreach ( $names as $name ) {
			if ( false !== strpbrk( $name, "\n\r" ) ) {
				++$state['skipped'];
				continue;
			}
			$child = '' === $relative ? $name : $relative . '/' . $name;
			if ( isset( $this->excluded[ $child ] ) ) {
				continue;
			}
			$path = $absolute . '/' . $name;
			if ( is_link( $path ) ) {
				$state = self::skip( $state, self::SAMPLE_LINK, $child );
				continue;
			}
			if ( is_dir( $path ) ) {
				Plain_Sink::write_all( $queue, $child . "\n" );
				if ( null !== $list ) {
					Plain_Sink::write_all( $list, 'd ' . $child . "\n" );
				}
				++$state['dirs'];
				continue;
			}
			if ( ! is_file( $path ) ) {
				++$state['skipped'];
				continue;
			}
			if ( ! is_readable( $path ) ) {
				$state = self::skip( $state, self::SAMPLE_UNREADABLE, $child );
				continue;
			}
			$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- vanished files are skipped.
			if ( false === $size ) {
				$state = self::skip( $state, self::SAMPLE_UNREADABLE, $child );
				continue;
			}
			if ( null !== $list ) {
				Plain_Sink::write_all( $list, 'f ' . $child . "\n" );
			}
			++$state['files'];
			$state['bytes'] += (int) $size;
		}
		return $state;
	}

	/**
	 * Counts a symbolic link or an unreadable entry and keeps it in the sample
	 * while there is room. A state saved before the sample existed starts an
	 * empty one here, so a resumed walk keeps working.
	 *
	 * @param array  $state    State.
	 * @param string $type     self::SAMPLE_LINK or self::SAMPLE_UNREADABLE.
	 * @param string $relative Path relative to the root ('' = root).
	 */
	private static function skip( array $state, string $type, string $relative ): array {
		if ( self::SAMPLE_LINK === $type ) {
			++$state['symlinks'];
		} else {
			++$state['unreadable'];
		}
		$sample = isset( $state['sample'] ) && is_array( $state['sample'] ) ? $state['sample'] : array();
		if ( count( $sample ) < self::SAMPLE_SIZE ) {
			$sample[] = array(
				'type' => $type,
				'path' => self::display_path( $relative ),
			);
		}
		$state['sample'] = $sample;
		return $state;
	}

	/**
	 * A relative path as text that can be saved in an option and shown on
	 * screen. Names on disk are raw bytes: every byte that is not printable
	 * UTF-8 of up to three bytes becomes U+FFFD (invalid sequences, control
	 * characters, and characters beyond the Basic Multilingual Plane, which
	 * utf8mb3 tables reject and would make saving the state fail). The root
	 * itself is ".".
	 *
	 * @param string $relative Path relative to the root, as read from disk.
	 */
	public static function display_path( string $relative ): string {
		if ( '' === $relative ) {
			return '.';
		}
		$text = preg_replace_callback(
			'/[\x20-\x7E]+|\xC2[\xA0-\xBF]|[\xC3-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|(\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|.)/s',
			static function ( array $match ): string {
				return isset( $match[1] ) ? "\u{FFFD}" : $match[0];
			},
			$relative
		);
		return is_string( $text ) ? $text : '?';
	}

	/**
	 * Opens a work file.
	 *
	 * @param string $path Path.
	 * @param string $mode fopen mode.
	 * @return resource
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	public static function open( string $path, string $mode ) {
		$handle = @fopen( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local work file.
		if ( false === $handle ) {
			throw new \RuntimeException( 'work_file_unwritable' );
		}
		return $handle;
	}

	/**
	 * Truncates (or creates) a work file to a committed size.
	 *
	 * @param string $path Path.
	 * @param int    $size Size.
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	public static function truncate( string $path, int $size ): void {
		$handle = self::open( $path, 'c+b' );
		ftruncate( $handle, $size );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		clearstatcache( true, $path );
	}

	/**
	 * Current size of an open file.
	 *
	 * @param resource $handle Handle.
	 */
	public static function size_of( $handle ): int {
		$stat = fstat( $handle );
		return is_array( $stat ) ? (int) $stat['size'] : 0;
	}
}
