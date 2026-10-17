<?php
/**
 * Facts about this WordPress site (the only place reading globals for the
 * package and the preflight).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\Gzip_Member_Sink;
use BladeCP\Migrator\Package\Package_Layout;
use BladeCP\Migrator\Package\Wpdb_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Site facts.
 */
final class Site {

	const MEASURE_TTL = 3600;

	/**
	 * Folders never packaged (relative to the site root): caches and other
	 * backup plugins' archives. Filter: bladecp_migrator_excluded_paths.
	 */
	const DEFAULT_EXCLUDED = array(
		'wp-config.php',
		'wp-content/cache',
		'wp-content/upgrade',
		'wp-content/updraft',
		'wp-content/ai1wm-backups',
		'wp-content/backups-dup-lite',
		'wp-content/backups-dup-pro',
		'wp-content/wpvividbackups',
		'wp-content/backup-db',
		'wp-content/uploads/backwpup-temp',
	);

	/**
	 * Site root (ABSPATH) without trailing slash.
	 */
	public static function root(): string {
		return rtrim( ABSPATH, '/\\' );
	}

	/**
	 * Host of the site address.
	 */
	public static function host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Exclusions relative to the root (the working folder included).
	 *
	 * @param Work_Dir $work_dir Working folder.
	 * @return string[]
	 */
	public static function excluded_paths( Work_Dir $work_dir ): array {
		$excluded = self::DEFAULT_EXCLUDED;
		foreach ( \BladeCP\Migrator\Package\File_Scanner::relative_exclusions( self::root(), array( $work_dir->path() ) ) as $relative ) {
			$excluded[] = $relative;
		}
		/**
		 * Filters the paths (relative to the WordPress folder) left out of the package.
		 *
		 * @param string[] $excluded Relative paths.
		 */
		$filtered = apply_filters( 'bladecp_migrator_excluded_paths', $excluded );
		$filtered = is_array( $filtered ) ? $filtered : $excluded;
		// The working folder and wp-config.php are always excluded.
		return array_values( array_unique( array_merge( array_map( 'strval', $filtered ), array( 'wp-config.php' ), \BladeCP\Migrator\Package\File_Scanner::relative_exclusions( self::root(), array( $work_dir->path() ) ) ) ) );
	}

	/**
	 * Facts written into the package (Package_Layout).
	 */
	public static function package_facts(): array {
		global $wpdb;
		$domain  = Package_Layout::normalize_domain( self::host() );
		$charset = defined( 'DB_CHARSET' ) && '' !== DB_CHARSET ? (string) DB_CHARSET : (string) ( $wpdb->charset ?: 'utf8mb4' );
		$collate = defined( 'DB_COLLATE' ) ? (string) DB_COLLATE : '';
		$size    = ( new Wpdb_Source( $wpdb ) )->data_size( (string) $wpdb->base_prefix );
		return array(
			'user'           => Package_Layout::account_user( $domain ),
			'domain'         => $domain,
			'home_url'       => (string) home_url(),
			'site_url'       => (string) site_url(),
			'db_name'        => Package_Layout::dump_name( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ),
			'table_prefix'   => (string) $wpdb->base_prefix,
			'db_charset'     => $charset,
			'db_collate'     => $collate,
			'php_version'    => PHP_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'plugin_version' => BLADECP_MIGRATOR_VERSION,
			'db_engine'      => '',
			'db_version'     => '',
			'db_estimate'    => (int) $size['bytes'],
			'files'          => 0,
			'bytes'          => 0,
			'tables'         => (int) $size['tables'],
			'dump_bytes'     => 0,
			'excluded'       => array(),
			'generated_at'   => 0,
		);
	}

	/**
	 * Facts for Preflight::evaluate().
	 *
	 * @param Work_Dir   $work_dir Working folder.
	 * @param array|null $measure  Files measurement (Options::MEASURE).
	 */
	public static function preflight_facts( Work_Dir $work_dir, ?array $measure ): array {
		global $wpdb;
		$home      = (string) home_url();
		$siteurl   = (string) site_url();
		$home_path = (string) wp_parse_url( $home, PHP_URL_PATH );
		$facts     = self::package_facts();
		$writable  = true;
		try {
			$work_dir->ensure();
		} catch ( \RuntimeException $e ) {
			$writable = false;
		}
		$measured = is_array( $measure ) && ! empty( $measure['done'] ) && ( time() - (int) ( $measure['at'] ?? 0 ) ) < self::MEASURE_TTL;
		return array(
			'wp_version'         => (string) get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'multisite'          => is_multisite(),
			'home_url'           => $home,
			'home_path'          => $home_path,
			'split_install'      => untrailingslashit( set_url_scheme( $home, 'https' ) ) !== untrailingslashit( set_url_scheme( $siteurl, 'https' ) ),
			'domain'             => $facts['domain'],
			'redirect_uri'       => self::redirect_uri(),
			'int_size'           => PHP_INT_SIZE,
			'has_crypto'         => Crypto::is_available(),
			'crypto_engine'      => Crypto::preferred_engine(),
			'has_curl'           => Curl_Part_Putter::is_available(),
			'has_zlib'           => Gzip_Member_Sink::is_available(),
			'memory_headroom'    => Part_Putter_Factory::memory_headroom(),
			'part_size'          => Part_Putter_Factory::DEFAULT_PART_BYTES,
			'memory_limit'       => Environment::memory_limit_bytes(),
			'max_execution_time' => Environment::max_execution_time(),
			'files_measured'     => $measured,
			'files_count'        => $measured ? (int) $measure['files'] : 0,
			'files_bytes'        => $measured ? (int) $measure['bytes'] : 0,
			'symlinks'           => $measured ? (int) $measure['symlinks'] : 0,
			'unreadable'         => $measured ? (int) $measure['unreadable'] : 0,
			// Measurements saved before the sample existed have none.
			'skipped_sample'     => $measured && isset( $measure['skipped_sample'] ) && is_array( $measure['skipped_sample'] ) ? $measure['skipped_sample'] : array(),
			'db_bytes'           => (int) $facts['db_estimate'],
			'db_tables'          => (int) $facts['tables'],
			'prefix_valid'       => Package_Layout::is_valid_sql_word( (string) $wpdb->base_prefix ),
			'charset_valid'      => Package_Layout::is_valid_sql_word( (string) $facts['db_charset'] ) && ( '' === $facts['db_collate'] || Package_Layout::is_valid_sql_word( (string) $facts['db_collate'] ) ),
			'work_dir_writable'  => $writable,
			'free_space'         => $writable ? Environment::free_space( $work_dir->path() ) : null,
			'cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/**
	 * Fixed redirect URI, or null when the dashboard is not on https.
	 */
	public static function redirect_uri(): ?string {
		$admin = admin_url( 'admin.php' );
		$host  = strtolower( (string) wp_parse_url( $admin, PHP_URL_HOST ) );
		if ( ! Config::is_loopback_host( $host ) && ! is_ssl() && 'https' !== wp_parse_url( $admin, PHP_URL_SCHEME ) ) {
			return null;
		}
		return Connect_Flow::redirect_uri( $admin );
	}
}
