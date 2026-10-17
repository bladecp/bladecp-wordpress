<?php
/**
 * Chooses how parts are uploaded on this server.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Part uploader selection.
 */
final class Part_Putter_Factory {

	const DEFAULT_PART_BYTES = 67108864;

	/**
	 * Streaming cURL when available; in-memory fallback only when the memory
	 * limit can hold a part a couple of times over.
	 *
	 * @param int $part_size Expected part size.
	 */
	public static function create( int $part_size = self::DEFAULT_PART_BYTES ): ?Part_Putter {
		if ( Curl_Part_Putter::is_available() ) {
			$bundle = defined( 'ABSPATH' ) && defined( 'WPINC' ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';
			return new Curl_Part_Putter( $bundle );
		}
		if ( self::memory_headroom() >= (int) ( $part_size * 2.5 ) ) {
			return new Wp_Part_Putter();
		}
		return null;
	}

	/**
	 * Free bytes under the memory limit (PHP_INT_MAX when unlimited).
	 */
	public static function memory_headroom(): int {
		$limit = Environment::memory_limit_bytes();
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}
		return max( 0, $limit - memory_get_usage( true ) );
	}
}
