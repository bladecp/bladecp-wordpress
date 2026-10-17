<?php
/**
 * THE package format contract: a minimal cPanel full backup (cpmove) layout.
 *
 * This is the only place that knows the archive structure. It mirrors the
 * cPanel full backup layout that the BladeCP migration engine reads today.
 * A change here must be supported by the engine before it ships, so keep
 * the layout minimal and covered by the package tests.
 *
 * Emitted, in this order (user = account_user(domain)):
 *
 *   cpmove-<user>/                                  dir
 *   cpmove-<user>/cp/                               dir
 *   cpmove-<user>/cp/<user>                         KEY=VALUE: DNS=<domain>, USER=<user>
 *   cpmove-<user>/userdata/                         dir
 *   cpmove-<user>/userdata/main                     YAML: main_domain
 *   cpmove-<user>/userdata/<domain>                 YAML: documentroot, phpversion (ea-phpXY), serveralias
 *   cpmove-<user>/bladecp/                          dir
 *   cpmove-<user>/bladecp/manifest.json             informational, ignored by the parser
 *   cpmove-<user>/mysql/                            dir
 *   cpmove-<user>/mysql/<db>.sql                    SQL dump, mysqldump-compatible header
 *   cpmove-<user>/homedir/                          dir
 *   cpmove-<user>/homedir/public_html/              dir
 *   cpmove-<user>/homedir/public_html/wp-config.php GENERATED (no password, no salts)
 *   cpmove-<user>/homedir/public_html/<site files>  dirs and regular files of ABSPATH
 *
 * Not emitted: homedir/etc (mail accounts), homedir/mail (Maildir), any
 * other cPanel metadata. The parser treats them as optional.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Layout of the cpmove package.
 */
final class Package_Layout {

	const FORMAT         = 'cpanel-cpmove';
	const FORMAT_VERSION = 1;
	const DOCROOT        = 'homedir/public_html';
	const MANIFEST       = 'bladecp/manifest.json';
	const MAX_IDENTIFIER = 64;
	const MAX_HOSTNAME   = 63;

	/**
	 * cPanel-like account name derived from the domain: lowercase letters
	 * and digits, starting with a letter, at most 8 characters.
	 *
	 * @param string $domain Normalized domain.
	 */
	public static function account_user( string $domain ): string {
		$alnum = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $domain ) );
		if ( '' === $alnum ) {
			return 'wpsite';
		}
		if ( ! ctype_alpha( $alnum[0] ) ) {
			$alnum = 'wp' . $alnum;
		}
		return substr( $alnum, 0, 8 );
	}

	/**
	 * Archive root directory name.
	 *
	 * @param string $user Account name.
	 */
	public static function root( string $user ): string {
		return 'cpmove-' . $user;
	}

	/**
	 * Archive path of a site file (relative to ABSPATH).
	 *
	 * @param string $user     Account name.
	 * @param string $relative Relative path.
	 */
	public static function docroot_path( string $user, string $relative ): string {
		return self::root( $user ) . '/' . self::DOCROOT . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Archive path of the SQL dump.
	 *
	 * @param string $user      Account name.
	 * @param string $dump_name Sanitized database name.
	 */
	public static function dump_path( string $user, string $dump_name ): string {
		return self::root( $user ) . '/mysql/' . $dump_name . '.sql';
	}

	/**
	 * File name sent with upload-url.
	 *
	 * @param string $user Account name.
	 * @param bool   $gzip Whether the tar is gzip-compressed.
	 */
	public static function package_filename( string $user, bool $gzip ): string {
		return 'cpmove-' . $user . ( $gzip ? '.tar.gz' : '.tar' );
	}

	/**
	 * Primary domain from the site host: lowercase, IDN to ASCII, no
	 * trailing dot, no leading "www." (cPanel accounts never carry www; it
	 * is declared as a serveralias instead).
	 *
	 * @param string $host Host of home_url().
	 */
	public static function normalize_domain( string $host ): string {
		$host = rtrim( strtolower( trim( $host ) ), '.' );
		if ( 1 === preg_match( '/[^\x20-\x7e]/', $host ) && function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( is_string( $ascii ) ) {
				$host = strtolower( $ascii );
			}
		}
		if ( 0 === strpos( $host, 'www.' ) && substr_count( $host, '.' ) >= 2 ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * Same rule as the parser's validateHostname: RFC 1123 lowercase labels,
	 * 63 characters at most in total.
	 *
	 * @param string $domain Domain.
	 */
	public static function is_valid_hostname( string $domain ): bool {
		if ( '' === $domain || strlen( $domain ) > self::MAX_HOSTNAME ) {
			return false;
		}
		foreach ( explode( '.', $domain ) as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Same rule as the parser's reSQLWord (table prefix, charset, collation).
	 *
	 * @param string $value Value.
	 */
	public static function is_valid_sql_word( string $value ): bool {
		return strlen( $value ) <= self::MAX_IDENTIFIER && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $value );
	}

	/**
	 * Database name used in the generated wp-config.php and the dump file
	 * name. The parser selects the dump whose name equals DB_NAME, and
	 * accepts only ^[A-Za-z0-9_$]+$, so other characters become "_".
	 *
	 * @param string $db_name DB_NAME of this site.
	 */
	public static function dump_name( string $db_name ): string {
		$name = substr( (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $db_name ), 0, self::MAX_IDENTIFIER );
		return '' === trim( $name, '_' ) ? 'wordpress' : $name;
	}

	/**
	 * cPanel EasyApache PHP identifier: "8.2.31" becomes "ea-php82".
	 *
	 * @param string $php_version PHP version.
	 */
	public static function php_version_tag( string $php_version ): string {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)/', $php_version, $m ) ) {
			return '';
		}
		return 'ea-php' . $m[1] . $m[2];
	}

	/**
	 * Problems that would make the parser reject the package.
	 *
	 * @param array $facts Site facts.
	 * @return string[] Problem codes.
	 */
	public static function validate_facts( array $facts ): array {
		$problems = array();
		if ( ! self::is_valid_hostname( (string) ( $facts['domain'] ?? '' ) ) ) {
			$problems[] = 'domain';
		}
		if ( ! self::is_valid_sql_word( (string) ( $facts['table_prefix'] ?? '' ) ) ) {
			$problems[] = 'table_prefix';
		}
		if ( ! self::is_valid_sql_word( (string) ( $facts['db_charset'] ?? '' ) ) ) {
			$problems[] = 'db_charset';
		}
		$collate = (string) ( $facts['db_collate'] ?? '' );
		if ( '' !== $collate && ! self::is_valid_sql_word( $collate ) ) {
			$problems[] = 'db_collate';
		}
		return $problems;
	}

	/**
	 * Regular files the parser needs (relative to the archive root).
	 *
	 * @param string $user      Account name.
	 * @param string $domain    Domain.
	 * @param string $dump_name Dump name.
	 * @return string[]
	 */
	public static function required_files( string $user, string $domain, string $dump_name ): array {
		$root = self::root( $user );
		return array(
			$root . '/cp/' . $user,
			$root . '/userdata/main',
			$root . '/userdata/' . $domain,
			$root . '/' . self::DOCROOT . '/wp-config.php',
			self::dump_path( $user, $dump_name ),
		);
	}

	/**
	 * Ordered entries written before the site files. Type "dump" marks
	 * where the SQL dump file is streamed.
	 *
	 * @param array $facts Site facts (see Package_Builder::site_facts()).
	 * @return array<int,array{type:string,path:string,content?:string}>
	 */
	public static function metadata_entries( array $facts ): array {
		$user = (string) $facts['user'];
		$root = self::root( $user );
		return array(
			array(
				'type' => 'dir',
				'path' => $root . '/',
			),
			array(
				'type' => 'dir',
				'path' => $root . '/cp/',
			),
			array(
				'type'    => 'file',
				'path'    => $root . '/cp/' . $user,
				'content' => self::cp_user_file( $facts ),
			),
			array(
				'type' => 'dir',
				'path' => $root . '/userdata/',
			),
			array(
				'type'    => 'file',
				'path'    => $root . '/userdata/main',
				'content' => self::userdata_main( $facts ),
			),
			array(
				'type'    => 'file',
				'path'    => $root . '/userdata/' . $facts['domain'],
				'content' => self::userdata_domain( $facts ),
			),
			array(
				'type' => 'dir',
				'path' => $root . '/bladecp/',
			),
			array(
				'type'    => 'file',
				'path'    => $root . '/' . self::MANIFEST,
				'content' => self::manifest( $facts ),
			),
			array(
				'type' => 'dir',
				'path' => $root . '/mysql/',
			),
			array(
				'type' => 'dump',
				'path' => self::dump_path( $user, (string) $facts['db_name'] ),
			),
			array(
				'type' => 'dir',
				'path' => $root . '/homedir/',
			),
			array(
				'type' => 'dir',
				'path' => $root . '/' . self::DOCROOT . '/',
			),
			array(
				'type'    => 'file',
				'path'    => $root . '/' . self::DOCROOT . '/wp-config.php',
				'content' => self::wp_config( $facts ),
			),
		);
	}

	/**
	 * cp/<user>: the parser reads DNS= as the fallback primary domain.
	 *
	 * @param array $facts Site facts.
	 */
	public static function cp_user_file( array $facts ): string {
		return 'DNS=' . $facts['domain'] . "\n" . 'USER=' . $facts['user'] . "\n";
	}

	/**
	 * userdata/main: the parser reads main_domain (preferred source).
	 *
	 * @param array $facts Site facts.
	 */
	public static function userdata_main( array $facts ): string {
		return "---\n"
			. "addon_domains: {}\n"
			. 'main_domain: ' . $facts['domain'] . "\n"
			. "parked_domains: []\n"
			. "sub_domains: []\n";
	}

	/**
	 * userdata/<domain>: the parser reads phpversion (ea-phpXY) and the
	 * serveralias list.
	 *
	 * @param array $facts Site facts.
	 */
	public static function userdata_domain( array $facts ): string {
		$user = (string) $facts['user'];
		$lines = array(
			'---',
			'documentroot: /home/' . $user . '/public_html',
			'homedir: /home/' . $user,
		);
		$tag = self::php_version_tag( (string) $facts['php_version'] );
		if ( '' !== $tag ) {
			$lines[] = 'phpversion: ' . $tag;
		}
		$lines[] = 'serveralias: www.' . $facts['domain'];
		$lines[] = 'servername: ' . $facts['domain'];
		$lines[] = 'user: ' . $user;
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Generated wp-config.php. The parser reads DB_NAME, DB_CHARSET,
	 * DB_COLLATE, WP_HOME, $table_prefix and the MULTISITE defines with
	 * regular expressions, so every value is a plain quoted literal. The
	 * original file is not shipped: it holds the source database password
	 * and salts, and the engine writes a new wp-config.php anyway.
	 *
	 * @param array $facts Site facts.
	 */
	public static function wp_config( array $facts ): string {
		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Generated by BladeCP Migrator ' . preg_replace( '/[^0-9A-Za-z.+-]/', '', (string) $facts['plugin_version'] ) . ' for the BladeCP migration engine.';
		$lines[] = ' * The original configuration file is not included: BladeCP writes a new';
		$lines[] = ' * one with the destination database credentials and fresh security keys.';
		$lines[] = ' */';
		$lines[] = self::define_line( 'DB_NAME', (string) $facts['db_name'] );
		$lines[] = self::define_line( 'DB_USER', '' );
		$lines[] = self::define_line( 'DB_PASSWORD', '' );
		$lines[] = self::define_line( 'DB_HOST', 'localhost' );
		$lines[] = self::define_line( 'DB_CHARSET', (string) $facts['db_charset'] );
		$lines[] = self::define_line( 'DB_COLLATE', (string) $facts['db_collate'] );
		foreach ( array(
			'WP_HOME'    => 'home_url',
			'WP_SITEURL' => 'site_url',
		) as $constant => $key ) {
			$url = (string) ( $facts[ $key ] ?? '' );
			if ( '' !== $url && 1 !== preg_match( '/[\'"\\\\\x00-\x1F]/', $url ) ) {
				$lines[] = self::define_line( $constant, $url );
			}
		}
		$lines[] = "\$table_prefix = '" . self::php_literal_safe( (string) $facts['table_prefix'] ) . "';";
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\tdefine( 'ABSPATH', __DIR__ . '/' );";
		$lines[] = '}';
		$lines[] = "require_once ABSPATH . 'wp-settings.php';";
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * bladecp/manifest.json: informational (ignored by today's parser) so the
	 * engine can tell plugin packages apart and support can read the source
	 * facts without unpacking the site.
	 *
	 * @param array $facts Site facts.
	 */
	public static function manifest( array $facts ): string {
		$manifest = array(
			'format'        => 'bladecp-wordpress-package',
			'formatVersion' => self::FORMAT_VERSION,
			'layout'        => self::FORMAT,
			'generator'     => array(
				'name'    => 'BladeCP Migrator',
				'version' => (string) $facts['plugin_version'],
			),
			'generatedAt'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) $facts['generated_at'] ),
			'site'          => array(
				'primaryDomain'    => (string) $facts['domain'],
				'homeUrl'          => (string) $facts['home_url'],
				'siteUrl'          => (string) $facts['site_url'],
				'wordpressVersion' => (string) $facts['wp_version'],
				'phpVersion'       => (string) $facts['php_version'],
				'multisite'        => false,
			),
			'database'      => array(
				'name'        => (string) $facts['db_name'],
				'engine'      => (string) $facts['db_engine'],
				'version'     => (string) $facts['db_version'],
				'tablePrefix' => (string) $facts['table_prefix'],
				'charset'     => (string) $facts['db_charset'],
				'collate'     => (string) $facts['db_collate'],
				'tables'      => (int) ( $facts['tables'] ?? 0 ),
				'dumpBytes'   => (int) ( $facts['dump_bytes'] ?? 0 ),
				'dumpPath'    => 'mysql/' . $facts['db_name'] . '.sql',
			),
			'files'         => array(
				'docroot'  => self::DOCROOT,
				'count'    => (int) ( $facts['files'] ?? 0 ),
				'bytes'    => (int) ( $facts['bytes'] ?? 0 ),
				'excluded' => array_values( array_map( 'strval', (array) ( $facts['excluded'] ?? array() ) ) ),
			),
		);
		return (string) json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- deterministic output, no WordPress dependency.
	}

	/**
	 * define( 'NAME', 'value' ); with a value that cannot break the literal.
	 *
	 * @param string $name  Constant.
	 * @param string $value Value.
	 */
	private static function define_line( string $name, string $value ): string {
		return "define( '" . $name . "', '" . self::php_literal_safe( $value ) . "' );";
	}

	/**
	 * Escapes a value for a single-quoted PHP literal.
	 *
	 * @param string $value Value.
	 */
	private static function php_literal_safe( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}
}
