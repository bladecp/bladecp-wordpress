<?php
/**
 * Lock stored as an option row, changed with atomic SQL (INSERT IGNORE and
 * compare-and-swap UPDATE/DELETE on the exact stored value), so two requests
 * can never both hold it, even with a persistent object cache.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Option lock.
 */
final class Option_Lock implements Lock {

	/**
	 * Takes the lock.
	 *
	 * @param int $ttl Seconds.
	 */
	public function acquire( int $ttl ): ?string {
		$token = bin2hex( random_bytes( 8 ) );
		if ( $this->insert( $token, $ttl ) ) {
			return $token;
		}
		$raw     = $this->raw();
		$current = null === $raw ? null : maybe_unserialize( $raw );
		if ( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) > time() ) {
			return null;
		}
		if ( null !== $raw && ! $this->delete_if( $raw ) ) {
			return null;
		}
		return $this->insert( $token, $ttl ) ? $token : null;
	}

	/**
	 * Extends the lock.
	 *
	 * @param string $token Token.
	 * @param int    $ttl   Seconds.
	 * @param array  $extra Details.
	 */
	public function refresh( string $token, int $ttl, array $extra = array() ): void {
		global $wpdb;
		$raw     = $this->raw();
		$current = null === $raw ? null : maybe_unserialize( $raw );
		if ( ! is_array( $current ) || ( $current['token'] ?? '' ) !== $token ) {
			return;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic compare-and-swap.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( self::value( $token, $ttl, $extra ) ),
				Options::LOCK,
				$raw
			)
		);
		wp_cache_delete( Options::LOCK, 'options' );
	}

	/**
	 * Releases the lock if ours.
	 *
	 * @param string $token Token.
	 */
	public function release( string $token ): void {
		$raw     = $this->raw();
		$current = null === $raw ? null : maybe_unserialize( $raw );
		if ( is_array( $current ) && ( $current['token'] ?? '' ) === $token ) {
			$this->delete_if( $raw );
		}
	}

	/**
	 * Current holder.
	 */
	public function read(): ?array {
		$raw     = $this->raw();
		$current = null === $raw ? null : maybe_unserialize( $raw );
		return is_array( $current ) && (int) ( $current['expires'] ?? 0 ) > time() ? $current : null;
	}

	/**
	 * INSERT IGNORE of a fresh lock row.
	 *
	 * @param string $token Token.
	 * @param int    $ttl   Seconds.
	 */
	private function insert( string $token, int $ttl ): bool {
		global $wpdb;
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic insert.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				Options::LOCK,
				maybe_serialize( self::value( $token, $ttl, array() ) )
			)
		);
		wp_cache_delete( Options::LOCK, 'options' );
		return 1 === (int) $inserted;
	}

	/**
	 * DELETE only if the stored value is unchanged.
	 *
	 * @param string $raw Stored value.
	 */
	private function delete_if( string $raw ): bool {
		global $wpdb;
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic compare-and-delete.
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", Options::LOCK, $raw )
		);
		wp_cache_delete( Options::LOCK, 'options' );
		return 1 === (int) $deleted;
	}

	/**
	 * Raw stored value (bypassing caches).
	 */
	private function raw(): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", Options::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- must bypass the object cache.
		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Lock value.
	 *
	 * @param string $token Token.
	 * @param int    $ttl   Seconds.
	 * @param array  $extra Details.
	 */
	private static function value( string $token, int $ttl, array $extra ): array {
		return array(
			'token'   => $token,
			'expires' => time() + $ttl,
			'extra'   => $extra,
		);
	}
}
