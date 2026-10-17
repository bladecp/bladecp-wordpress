<?php
/**
 * Pre-migration checks: pure evaluation of gathered facts (Site::preflight_facts()).
 *
 * Each check: id, label, value, status (ok, warning, blocker, pending) and an
 * optional plain-language message. The site files check also lists the
 * entries the package will skip (items, up to five, and how many more). Any
 * blocker disables Connect and Start.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\File_Scanner;
use BladeCP\Migrator\Package\Package_Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight.
 */
final class Preflight {

	const OK      = 'ok';
	const WARNING = 'warning';
	const BLOCKER = 'blocker';
	const PENDING = 'pending';

	const MAX_PACKAGE_BYTES = 53687091200;
	const LOW_MEMORY_BYTES  = 67108864;

	/**
	 * Evaluates the checks.
	 *
	 * @param array $f Facts.
	 * @return array<int,array{id:string,label:string,value:string,status:string,message:string}>
	 */
	public static function evaluate( array $f ): array {
		$checks = array();

		$checks[] = self::check( 'wordpress', __( 'WordPress', 'bladecp-migrator' ), (string) $f['wp_version'], self::OK );
		$checks[] = self::check( 'php', __( 'PHP', 'bladecp-migrator' ), (string) $f['php_version'], self::OK );

		if ( ! empty( $f['multisite'] ) ) {
			$checks[] = self::check( 'site_type', __( 'Site type', 'bladecp-migrator' ), __( 'Multisite network', 'bladecp-migrator' ), self::BLOCKER, __( 'This plugin moves a single WordPress site. Multisite networks are not supported by this plugin yet.', 'bladecp-migrator' ) );
		} else {
			$checks[] = self::check( 'site_type', __( 'Site type', 'bladecp-migrator' ), __( 'Single site', 'bladecp-migrator' ), self::OK );
		}

		$checks[] = self::address_check( $f );

		if ( empty( $f['redirect_uri'] ) ) {
			$checks[] = self::check( 'https', __( 'Secure connection', 'bladecp-migrator' ), __( 'Not available', 'bladecp-migrator' ), self::BLOCKER, __( 'BladeCP can only connect to a WordPress dashboard served over HTTPS. Open this page with https:// (install an SSL certificate first if your site has none).', 'bladecp-migrator' ) );
		} else {
			$local    = 0 === strpos( (string) $f['redirect_uri'], 'http://' );
			$checks[] = self::check( 'https', __( 'Secure connection', 'bladecp-migrator' ), $local ? __( 'Local test address (HTTP)', 'bladecp-migrator' ) : __( 'HTTPS', 'bladecp-migrator' ), self::OK );
		}

		if ( (int) $f['int_size'] < 8 ) {
			$checks[] = self::check( 'php64', __( 'PHP build', 'bladecp-migrator' ), __( '32-bit', 'bladecp-migrator' ), self::BLOCKER, __( 'A 64-bit PHP is required to build packages larger than 2 GB. Ask your host to switch to 64-bit PHP.', 'bladecp-migrator' ) );
		}

		$checks[] = self::encryption_check( $f );

		if ( ! empty( $f['has_curl'] ) ) {
			$checks[] = self::check( 'upload', __( 'Upload method', 'bladecp-migrator' ), __( 'Streaming (cURL)', 'bladecp-migrator' ), self::OK );
		} elseif ( (int) $f['memory_headroom'] >= (int) ( (int) $f['part_size'] * 2.5 ) ) {
			$checks[] = self::check( 'upload', __( 'Upload method', 'bladecp-migrator' ), __( 'In memory', 'bladecp-migrator' ), self::WARNING, __( 'The PHP cURL extension is missing, so each 64 MB part is uploaded from memory. It works, but cURL is lighter.', 'bladecp-migrator' ) );
		} else {
			$checks[] = self::check( 'upload', __( 'Upload method', 'bladecp-migrator' ), __( 'Not available', 'bladecp-migrator' ), self::BLOCKER, __( 'The PHP cURL extension is missing and the memory limit is too low to upload 64 MB parts. Ask your host to enable cURL.', 'bladecp-migrator' ) );
		}

		if ( ! empty( $f['has_zlib'] ) ) {
			$checks[] = self::check( 'compression', __( 'Compression', 'bladecp-migrator' ), __( 'gzip', 'bladecp-migrator' ), self::OK );
		} else {
			$checks[] = self::check( 'compression', __( 'Compression', 'bladecp-migrator' ), __( 'Off', 'bladecp-migrator' ), self::WARNING, __( 'The PHP zlib extension is missing, so the package is not compressed and will take longer to upload.', 'bladecp-migrator' ) );
		}

		$checks[] = self::limits_check( $f );
		$checks[] = self::files_check( $f );
		$checks[] = self::database_check( $f );
		$checks[] = self::disk_check( $f );

		if ( ! empty( $f['cron_disabled'] ) ) {
			$checks[] = self::check( 'background', __( 'Background work', 'bladecp-migrator' ), __( 'WP-Cron off', 'bladecp-migrator' ), self::WARNING, __( 'WP-Cron is disabled on this site. Keep this page open while the package is built and uploaded.', 'bladecp-migrator' ) );
		} else {
			$checks[] = self::check( 'background', __( 'Background work', 'bladecp-migrator' ), __( 'Available', 'bladecp-migrator' ), self::OK, __( 'Keeping this page open is fastest. If you close it, the work continues more slowly in the background.', 'bladecp-migrator' ) );
		}

		return $checks;
	}

	/**
	 * Whether any check blocks the migration.
	 *
	 * @param array $checks Checks.
	 */
	public static function has_blockers( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( self::BLOCKER === $check['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Labels of the blocking checks, in the order they are shown.
	 *
	 * @param array $checks Checks.
	 * @return string[]
	 */
	public static function blocker_labels( array $checks ): array {
		$labels = array();
		foreach ( $checks as $check ) {
			$label = (string) $check['label'];
			if ( self::BLOCKER === $check['status'] && ! in_array( $label, $labels, true ) ) {
				$labels[] = $label;
			}
		}
		return $labels;
	}

	/**
	 * Site address check.
	 *
	 * @param array $f Facts.
	 */
	private static function address_check( array $f ): array {
		$label  = __( 'Site address', 'bladecp-migrator' );
		$domain = (string) $f['domain'];
		if ( '' !== trim( (string) $f['home_path'], '/' ) ) {
			return self::check( 'address', $label, (string) $f['home_url'], self::BLOCKER, __( 'Sites served from a subfolder (for example example.com/blog) are not supported yet.', 'bladecp-migrator' ) );
		}
		if ( ! empty( $f['split_install'] ) ) {
			return self::check( 'address', $label, (string) $f['home_url'], self::BLOCKER, __( 'This site keeps WordPress in its own folder, apart from the site address. This setup is not supported yet.', 'bladecp-migrator' ) );
		}
		if ( ! Package_Layout::is_valid_hostname( $domain ) ) {
			return self::check( 'address', $label, (string) $f['home_url'], self::BLOCKER, __( 'The site address cannot be used for a migration: use a regular domain name of up to 63 characters.', 'bladecp-migrator' ) );
		}
		return self::check( 'address', $label, $domain, self::OK );
	}

	/**
	 * How the BladeCP key is stored: OpenSSL AES-256-GCM, or sodium when
	 * OpenSSL cannot. Blocks only when neither exists.
	 *
	 * @param array $f Facts (has_crypto, and crypto_engine when known).
	 */
	private static function encryption_check( array $f ): array {
		$label = __( 'Encryption', 'bladecp-migrator' );
		if ( empty( $f['has_crypto'] ) ) {
			return self::check( 'encryption', $label, __( 'Not available', 'bladecp-migrator' ), self::BLOCKER, __( 'PHP has neither OpenSSL with AES-256-GCM nor sodium, so the BladeCP connection could not be stored safely. Ask your host to enable one of them.', 'bladecp-migrator' ) );
		}
		switch ( (string) ( $f['crypto_engine'] ?? '' ) ) {
			case Crypto::ENGINE_OPENSSL:
				$value = __( 'OpenSSL (AES-256-GCM)', 'bladecp-migrator' );
				break;
			case Crypto::ENGINE_SODIUM:
				$value = __( 'sodium', 'bladecp-migrator' );
				break;
			default:
				$value = __( 'Available', 'bladecp-migrator' );
		}
		return self::check( 'encryption', $label, $value, self::OK );
	}

	/**
	 * PHP limits.
	 *
	 * @param array $f Facts.
	 */
	private static function limits_check( array $f ): array {
		$memory = (int) $f['memory_limit'];
		$time   = (int) $f['max_execution_time'];
		$value  = sprintf(
			/* translators: 1: memory limit (for example 256 MB), 2: time limit in seconds or "none". */
			__( 'Memory %1$s, time limit %2$s', 'bladecp-migrator' ),
			$memory < 0 ? __( 'unlimited', 'bladecp-migrator' ) : self::size( $memory ),
			$time <= 0 ? __( 'none', 'bladecp-migrator' ) : sprintf( /* translators: %d: seconds. */ __( '%d s', 'bladecp-migrator' ), $time )
		);
		if ( $memory > 0 && $memory < self::LOW_MEMORY_BYTES ) {
			return self::check( 'limits', __( 'PHP limits', 'bladecp-migrator' ), $value, self::WARNING, __( 'The memory limit is low. The plugin works in small steps, but raising it to 128 MB or more makes the migration faster and safer.', 'bladecp-migrator' ) );
		}
		return self::check( 'limits', __( 'PHP limits', 'bladecp-migrator' ), $value, self::OK, __( 'The package is built in short steps, so low limits only make it slower.', 'bladecp-migrator' ) );
	}

	/**
	 * Site files size.
	 *
	 * @param array $f Facts.
	 */
	private static function files_check( array $f ): array {
		$label = __( 'Site files', 'bladecp-migrator' );
		if ( empty( $f['files_measured'] ) ) {
			return self::check( 'files', $label, __( 'Measuring...', 'bladecp-migrator' ), self::PENDING );
		}
		$value = sprintf(
			/* translators: 1: number of files, 2: total size. */
			_n( '%1$s file, %2$s', '%1$s files, %2$s', (int) $f['files_count'], 'bladecp-migrator' ),
			self::number( (int) $f['files_count'] ),
			self::size( (int) $f['files_bytes'] )
		);
		$skipped = (int) $f['symlinks'] + (int) $f['unreadable'];
		if ( $skipped > 0 ) {
			$sample  = isset( $f['skipped_sample'] ) && is_array( $f['skipped_sample'] ) ? $f['skipped_sample'] : array();
			$items   = self::skipped_items( $skipped, $sample );
			$message = sprintf(
				/* translators: %s: number of items. */
				_n( '%s item cannot be copied (a symbolic link or an unreadable file) and will be skipped.', '%s items cannot be copied (symbolic links or unreadable files) and will be skipped.', $skipped, 'bladecp-migrator' ),
				self::number( $skipped )
			);
			$check          = self::check( 'files', $label, $value, self::WARNING, $message );
			$check['items'] = $items;
			$check['more']  = $skipped - count( $items );
			return $check;
		}
		return self::check( 'files', $label, $value, self::OK );
	}

	/**
	 * The first skipped entries (the measurement keeps a few), so the person can
	 * tell at once whether they matter. A measurement saved before the sample
	 * existed gives none. Malformed entries are ignored; the screen escapes the
	 * paths.
	 *
	 * @param int   $skipped Symbolic links plus unreadable entries.
	 * @param array $sample  Entries in walk order: type (link or unreadable) and path.
	 * @return array<int,array{type:string,path:string}>
	 */
	private static function skipped_items( int $skipped, array $sample ): array {
		$items = array();
		foreach ( $sample as $entry ) {
			if ( count( $items ) >= $skipped ) {
				break;
			}
			if ( ! is_array( $entry ) || ! isset( $entry['path'] ) || ! is_string( $entry['path'] ) ) {
				continue;
			}
			$items[] = array(
				'type' => File_Scanner::SAMPLE_LINK === ( $entry['type'] ?? '' ) ? File_Scanner::SAMPLE_LINK : File_Scanner::SAMPLE_UNREADABLE,
				'path' => $entry['path'],
			);
		}
		return $items;
	}

	/**
	 * Database size and identifiers.
	 *
	 * @param array $f Facts.
	 */
	private static function database_check( array $f ): array {
		$label = __( 'Database', 'bladecp-migrator' );
		$value = sprintf(
			/* translators: 1: size, 2: number of tables. */
			_n( '%1$s in %2$s table', '%1$s in %2$s tables', (int) $f['db_tables'], 'bladecp-migrator' ),
			self::size( (int) $f['db_bytes'] ),
			self::number( (int) $f['db_tables'] )
		);
		if ( empty( $f['prefix_valid'] ) ) {
			return self::check( 'database', $label, $value, self::BLOCKER, __( 'The database table prefix uses characters the migration does not accept (only letters, digits and underscores).', 'bladecp-migrator' ) );
		}
		if ( empty( $f['charset_valid'] ) ) {
			return self::check( 'database', $label, $value, self::BLOCKER, __( 'The database character set or collation in wp-config.php uses characters the migration does not accept.', 'bladecp-migrator' ) );
		}
		return self::check( 'database', $label, $value, self::OK );
	}

	/**
	 * Free space and size limit.
	 *
	 * @param array $f Facts.
	 */
	private static function disk_check( array $f ): array {
		$label = __( 'Free disk space', 'bladecp-migrator' );
		if ( empty( $f['work_dir_writable'] ) ) {
			return self::check( 'disk', $label, __( 'Not writable', 'bladecp-migrator' ), self::BLOCKER, __( 'The plugin cannot write to the uploads folder (wp-content/uploads), where the package is built. Check the folder permissions.', 'bladecp-migrator' ) );
		}
		$free  = $f['free_space'];
		$value = null === $free ? __( 'Unknown', 'bladecp-migrator' ) : self::size( (int) $free );
		if ( empty( $f['files_measured'] ) ) {
			return self::check( 'disk', $label, $value, self::PENDING );
		}
		$needed = (int) $f['files_bytes'] + (int) $f['db_bytes'];
		if ( $needed > self::MAX_PACKAGE_BYTES ) {
			return self::check( 'disk', $label, $value, self::BLOCKER, __( 'This site is larger than the 50 GB limit of a migration package.', 'bladecp-migrator' ) );
		}
		$message = sprintf(
			/* translators: %s: estimated package size. */
			__( 'The package is built here before it is sent, so it needs up to %s of free space.', 'bladecp-migrator' ),
			self::size( $needed )
		);
		if ( null === $free ) {
			return self::check( 'disk', $label, $value, self::WARNING, __( 'This server does not report its free space.', 'bladecp-migrator' ) . ' ' . $message );
		}
		if ( (int) $free < $needed ) {
			return self::check( 'disk', $label, $value, self::BLOCKER, __( 'There is not enough free disk space.', 'bladecp-migrator' ) . ' ' . $message );
		}
		return self::check( 'disk', $label, $value, self::OK, $message );
	}

	/**
	 * Builds a check row.
	 *
	 * @param string $id      Id.
	 * @param string $label   Label.
	 * @param string $value   Value.
	 * @param string $status  Status.
	 * @param string $message Message.
	 */
	private static function check( string $id, string $label, string $value, string $status, string $message = '' ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'value'   => $value,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Human size.
	 *
	 * @param int $bytes Bytes.
	 */
	private static function size( int $bytes ): string {
		return Migration_Job::size( $bytes );
	}

	/**
	 * Localized number.
	 *
	 * @param int $n Number.
	 */
	private static function number( int $n ): string {
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $n ) : (string) $n;
	}
}
