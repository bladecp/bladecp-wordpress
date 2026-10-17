<?php
/**
 * Option names and non-autoloaded persistence.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Every option the plugin writes is listed here (uninstall.php mirrors it).
 */
final class Options {

	const CONNECTION = 'bladecp_migrator_connection';
	const JOB        = 'bladecp_migrator_job';
	const LOCK       = 'bladecp_migrator_lock';
	const MEASURE    = 'bladecp_migrator_measure';
	const WORK_DIR   = 'bladecp_migrator_work_dir';

	/**
	 * All option names.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::CONNECTION, self::JOB, self::LOCK, self::MEASURE, self::WORK_DIR );
	}

	/**
	 * Stores a value without autoload.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	public static function set( string $name, $value ): void {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', 'no' );
			return;
		}
		update_option( $name, $value, false );
	}

	/**
	 * Reads an array option.
	 *
	 * @param string $name Option name.
	 */
	public static function get_array( string $name ): ?array {
		$value = get_option( $name, null );
		return is_array( $value ) ? $value : null;
	}
}
