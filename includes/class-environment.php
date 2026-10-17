<?php
/**
 * PHP limits and server facts.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Environment helpers.
 */
final class Environment {

	const MAX_STEP_SECONDS = 20;
	const MIN_STEP_SECONDS = 1;

	/**
	 * Parses a php.ini size ("128M", "1G", "-1").
	 *
	 * @param string $value Ini value.
	 * @return int Bytes; -1 for unlimited.
	 */
	public static function ini_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}
		return (int) $number;
	}

	/**
	 * memory_limit in bytes (-1 unlimited).
	 */
	public static function memory_limit_bytes(): int {
		return self::ini_bytes( (string) ini_get( 'memory_limit' ) );
	}

	/**
	 * max_execution_time in seconds (0 unlimited).
	 */
	public static function max_execution_time(): int {
		return (int) ini_get( 'max_execution_time' );
	}

	/**
	 * Seconds one step may work: half the execution limit (read after
	 * prepare_long_step() tried to raise it), at most 20 and never less than 1.
	 *
	 * @param int $max_execution_time Limit (0 = unlimited).
	 */
	public static function step_seconds( int $max_execution_time ): int {
		if ( $max_execution_time <= 0 ) {
			return self::MAX_STEP_SECONDS;
		}
		return (int) max( self::MIN_STEP_SECONDS, min( self::MAX_STEP_SECONDS, floor( $max_execution_time / 2 ) ) );
	}

	/**
	 * Free disk space, or null when the host does not tell.
	 *
	 * @param string $path Directory.
	 */
	public static function free_space( string $path ): ?int {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable it and warn.
		return false === $free ? null : (int) $free;
	}

	/**
	 * Whether a PHP function is callable (not disabled).
	 *
	 * @param string $name Function.
	 */
	public static function function_enabled( string $name ): bool {
		if ( ! function_exists( $name ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( $name, $disabled, true );
	}

	/**
	 * Lets one step run as long as allowed and keep going if the browser
	 * disconnects (the state is saved at the end of the step).
	 */
	public static function prepare_long_step(): void {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( self::function_enabled( 'set_time_limit' ) && self::max_execution_time() > 0 ) {
			@set_time_limit( max( 120, self::max_execution_time() ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be refused by the host.
		}
	}
}
