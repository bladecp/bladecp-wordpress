<?php
/**
 * Minimal autoloader (no Composer).
 *
 * Maps BladeCP\Migrator\Package\Tar_Writer to
 * includes/package/class-tar-writer.php, following the WordPress file naming
 * convention (class-, interface-).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for the plugin namespace.
 */
final class Autoloader {

	const PREFIX = 'BladeCP\\Migrator\\';

	/**
	 * Base directory of the includes folder.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Registers the autoloader once.
	 *
	 * @param string $base Absolute path of the includes folder.
	 */
	public static function register( string $base ): void {
		if ( '' !== self::$base ) {
			return;
		}
		self::$base = rtrim( $base, '/\\' );
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class of the plugin namespace.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}
		$relative = substr( $class, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = self::$base;
		foreach ( $parts as $part ) {
			$dir .= '/' . strtolower( str_replace( '_', '-', $part ) );
		}
		$slug = strtolower( str_replace( '_', '-', $name ) );
		foreach ( array( 'class-', 'interface-' ) as $kind ) {
			$file = $dir . '/' . $kind . $slug . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
