<?php
/**
 * Resumable writer of the package: metadata entries, the SQL dump and the
 * scanned site files, as one tar stream compressed in gzip members.
 *
 * Each step appends one gzip member and saves the committed file size with
 * the state. A step killed halfway is cut back to the last commit and
 * redone, so the archive on disk is always a valid prefix of the stream.
 * A single large file may span many steps (the state keeps the offset).
 *
 * Files that change while being copied keep the size recorded in their
 * header: a file that shrinks is padded with zeros, a file that grows is
 * cut at the recorded size. Both are counted in "changed".
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

use BladeCP\Migrator\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Package writer.
 */
final class Package_Packer {

	const CHUNK = 1048576;

	/**
	 * Site facts.
	 *
	 * @var array
	 */
	private $facts;

	/**
	 * Site root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Scanner list file.
	 *
	 * @var string
	 */
	private $list_file;

	/**
	 * SQL dump file.
	 *
	 * @var string
	 */
	private $dump_file;

	/**
	 * Package file.
	 *
	 * @var string
	 */
	private $package_file;

	/**
	 * Gzip members (true) or plain tar (false).
	 *
	 * @var bool
	 */
	private $gzip;

	/**
	 * Constructor.
	 *
	 * @param array  $facts        Site facts.
	 * @param string $root         Site root.
	 * @param string $list_file    Scanner list.
	 * @param string $dump_file    SQL dump.
	 * @param string $package_file Output package.
	 * @param bool   $gzip         Compress.
	 */
	public function __construct( array $facts, string $root, string $list_file, string $dump_file, string $package_file, bool $gzip ) {
		$this->facts        = $facts;
		$this->root         = rtrim( $root, '/\\' );
		$this->list_file    = $list_file;
		$this->dump_file    = $dump_file;
		$this->package_file = $package_file;
		$this->gzip         = $gzip;
	}

	/**
	 * Initial state.
	 */
	public static function initial_state(): array {
		return array(
			'started'    => false,
			'done'       => false,
			'size'       => 0,
			'tar_bytes'  => 0,
			'meta_index' => 0,
			'meta_done'  => false,
			'list_pos'   => 0,
			'files_done' => false,
			'current'    => null,
			'files'      => 0,
			'bytes'      => 0,
			'changed'    => 0,
			'vanished'   => 0,
		);
	}

	/**
	 * Runs until the deadline (always at least one unit of work).
	 *
	 * @param array    $state    State.
	 * @param Deadline $deadline Budget.
	 * @throws \RuntimeException On disk errors.
	 */
	public function step( array $state, Deadline $deadline ): array {
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		if ( empty( $state['started'] ) ) {
			$state            = self::initial_state();
			$state['started'] = true;
			File_Scanner::truncate( $this->package_file, 0 );
		} else {
			File_Scanner::truncate( $this->package_file, (int) $state['size'] );
		}
		$handle  = File_Scanner::open( $this->package_file, 'ab' );
		$sink    = $this->gzip ? new Gzip_Member_Sink( $handle ) : new Plain_Sink( $handle );
		$entries = Package_Layout::metadata_entries( $this->facts );
		$list    = null;
		$first   = true;
		while ( true ) {
			if ( ! $first && $deadline->reached() ) {
				break;
			}
			$first = false;
			if ( null !== $state['current'] ) {
				$state = $this->copy( $state, $sink, $deadline );
				continue;
			}
			if ( empty( $state['meta_done'] ) ) {
				$state = $this->write_metadata( $state, $sink, $entries[ (int) $state['meta_index'] ] );
				++$state['meta_index'];
				$state['meta_done'] = $state['meta_index'] >= count( $entries );
				continue;
			}
			if ( empty( $state['files_done'] ) ) {
				if ( null === $list ) {
					$list = File_Scanner::open( $this->list_file, 'rb' );
				}
				fseek( $list, (int) $state['list_pos'] );
				$line = fgets( $list );
				if ( false === $line ) {
					$state['files_done'] = true;
					continue;
				}
				$state['list_pos'] += strlen( $line );
				$state              = $this->start_site_entry( $state, $sink, rtrim( $line, "\n" ) );
				continue;
			}
			$this->emit( $state, $sink, Tar_Writer::end_of_archive( (int) $state['tar_bytes'] ) );
			$state['done'] = true;
			break;
		}
		if ( null !== $list ) {
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$sink->close();
		fflush( $handle );
		$state['size'] = File_Scanner::size_of( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $state;
	}

	/**
	 * Writes one metadata entry (or starts the dump).
	 *
	 * @param array       $state State.
	 * @param Output_Sink $sink  Sink.
	 * @param array       $entry Entry from Package_Layout.
	 * @throws \RuntimeException When the dump is missing.
	 */
	private function write_metadata( array $state, Output_Sink $sink, array $entry ): array {
		$mtime = (int) $this->facts['generated_at'];
		if ( 'dir' === $entry['type'] ) {
			$this->emit( $state, $sink, Tar_Writer::dir_header( $entry['path'], $mtime ) );
			return $state;
		}
		if ( 'file' === $entry['type'] ) {
			$content = (string) $entry['content'];
			$this->emit( $state, $sink, Tar_Writer::file_header( $entry['path'], strlen( $content ), $mtime ) . $content . Tar_Writer::padding( strlen( $content ) ) );
			return $state;
		}
		clearstatcache( true, $this->dump_file );
		$size = @filesize( $this->dump_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
		if ( false === $size ) {
			throw new \RuntimeException( 'dump_missing' );
		}
		// The committed dump size wins over whatever is on disk now.
		if ( (int) ( $this->facts['dump_bytes'] ?? 0 ) > 0 ) {
			$size = min( (int) $size, (int) $this->facts['dump_bytes'] );
		}
		$this->emit( $state, $sink, Tar_Writer::file_header( $entry['path'], (int) $size, $mtime ) );
		$state['current'] = array(
			'path'   => $this->dump_file,
			'size'   => (int) $size,
			'offset' => 0,
			'kind'   => 'dump',
		);
		return $this->finish_if_empty( $state, $sink );
	}

	/**
	 * Writes a directory header or starts copying a file.
	 *
	 * @param array       $state State.
	 * @param Output_Sink $sink  Sink.
	 * @param string      $line  List line ("d path" or "f path").
	 */
	private function start_site_entry( array $state, Output_Sink $sink, string $line ): array {
		$type     = substr( $line, 0, 1 );
		$relative = substr( $line, 2 );
		if ( '' === $relative || ( 'd' !== $type && 'f' !== $type ) ) {
			return $state;
		}
		$path = $this->root . '/' . $relative;
		$name = Package_Layout::docroot_path( (string) $this->facts['user'], $relative );
		if ( 'd' === $type ) {
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- folder may be gone.
			$this->emit( $state, $sink, Tar_Writer::dir_header( $name . '/', false === $mtime ? (int) $this->facts['generated_at'] : (int) $mtime ) );
			return $state;
		}
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			++$state['vanished'];
			return $state;
		}
		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
		if ( false === $size ) {
			++$state['vanished'];
			return $state;
		}
		$this->emit( $state, $sink, Tar_Writer::file_header( $name, (int) $size, false === $mtime ? (int) $this->facts['generated_at'] : (int) $mtime ) );
		$state['current'] = array(
			'path'   => $path,
			'size'   => (int) $size,
			'offset' => 0,
			'kind'   => 'file',
		);
		return $this->finish_if_empty( $state, $sink );
	}

	/**
	 * Closes an entry with no content right away.
	 *
	 * @param array       $state State.
	 * @param Output_Sink $sink  Sink.
	 */
	private function finish_if_empty( array $state, Output_Sink $sink ): array {
		if ( 0 === (int) $state['current']['size'] ) {
			if ( 'file' === $state['current']['kind'] ) {
				++$state['files'];
			}
			$state['current'] = null;
		}
		return $state;
	}

	/**
	 * Copies the current entry's content until done or out of time.
	 *
	 * @param array       $state    State.
	 * @param Output_Sink $sink     Sink.
	 * @param Deadline    $deadline Budget.
	 */
	private function copy( array $state, Output_Sink $sink, Deadline $deadline ): array {
		$current = $state['current'];
		$handle  = @fopen( $current['path'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- vanished files are zero-filled.
		if ( false !== $handle && 0 !== fseek( $handle, (int) $current['offset'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$handle = false;
		}
		$short = ! empty( $current['short'] );
		while ( $current['offset'] < $current['size'] ) {
			$want = (int) min( self::CHUNK, $current['size'] - $current['offset'] );
			$data = false === $handle ? '' : fread( $handle, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $data || '' === $data ) {
				$data  = str_repeat( "\0", $want );
				$short = true;
			}
			$this->emit( $state, $sink, $data );
			$current['offset'] += strlen( $data );
			if ( $current['offset'] < $current['size'] && $deadline->reached() ) {
				break;
			}
		}
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $current['offset'] < $current['size'] ) {
			$current['short'] = $short;
			$state['current'] = $current;
			return $state;
		}
		$this->emit( $state, $sink, Tar_Writer::padding( (int) $current['size'] ) );
		if ( 'file' === $current['kind'] ) {
			++$state['files'];
			$state['bytes'] += (int) $current['size'];
			clearstatcache( true, $current['path'] );
			if ( $short || @filesize( $current['path'] ) !== (int) $current['size'] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- file may be gone.
				++$state['changed'];
			}
		}
		$state['current'] = null;
		return $state;
	}

	/**
	 * Writes tar bytes and counts them.
	 *
	 * @param array       $state State (tar_bytes updated).
	 * @param Output_Sink $sink  Sink.
	 * @param string      $bytes Bytes.
	 */
	private function emit( array &$state, Output_Sink $sink, string $bytes ): void {
		$sink->write( $bytes );
		$state['tar_bytes'] += strlen( $bytes );
	}
}
