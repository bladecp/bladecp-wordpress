<?php
/**
 * The stored BladeCP connection: an encrypted key bound to one migration.
 *
 * The key is stored encrypted (see Crypto), never autoloaded, never rendered
 * and never logged. Only the non-secret metadata is exposed to the screen.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Connection storage.
 */
final class Connection {

	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Validates the token exchange response and stores it.
	 *
	 * @param array  $data     `data` of POST /customer/connect/token.
	 * @param Crypto $crypto   Cipher.
	 * @param string $api_base API base the key belongs to.
	 * @param int    $user_id  Who connected.
	 * @param int    $now      Current time.
	 * @throws \UnexpectedValueException When the response is not usable.
	 */
	public static function save_from_exchange( array $data, Crypto $crypto, string $api_base, int $user_id, int $now ): void {
		$api_key   = isset( $data['apiKey'] ) && is_string( $data['apiKey'] ) ? $data['apiKey'] : '';
		$migration = isset( $data['migrationUuid'] ) && is_string( $data['migrationUuid'] ) ? $data['migrationUuid'] : '';
		if ( 0 !== strpos( $api_key, 'bcp_' ) || strlen( $api_key ) > 512 ) {
			throw new \UnexpectedValueException( 'missing or malformed key' );
		}
		if ( 1 !== preg_match( self::UUID_PATTERN, $migration ) ) {
			throw new \UnexpectedValueException( 'missing migration' );
		}
		$customer = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		Options::set(
			Options::CONNECTION,
			array(
				'key'            => $crypto->encrypt( $api_key ),
				'key_uuid'       => isset( $data['keyUuid'] ) ? self::text( $data['keyUuid'], 64 ) : '',
				'migration_uuid' => strtolower( $migration ),
				'scopes'         => isset( $data['scopes'] ) && is_array( $data['scopes'] ) ? array_values( array_map( array( __CLASS__, 'scope' ), $data['scopes'] ) ) : array(),
				'expires_at'     => isset( $data['expiresAt'] ) ? self::text( $data['expiresAt'], 40 ) : '',
				// Masked by BladeCP (for example j***@example.com); the name is not kept.
				'email'          => isset( $customer['email'] ) ? self::text( $customer['email'], 254 ) : '',
				'api_base'       => $api_base,
				'connected_at'   => $now,
				'connected_by'   => $user_id,
			)
		);
	}

	/**
	 * Non-secret connection metadata, or null when not connected (or when the
	 * key belongs to another API base).
	 */
	public static function get(): ?array {
		$data = Options::get_array( Options::CONNECTION );
		if ( null === $data || empty( $data['key'] ) || empty( $data['migration_uuid'] ) ) {
			return null;
		}
		if ( ( $data['api_base'] ?? '' ) !== Config::api_base() ) {
			return null;
		}
		unset( $data['key'] );
		return $data;
	}

	/**
	 * Whether a usable connection exists.
	 */
	public static function is_connected(): bool {
		return null !== self::get();
	}

	/**
	 * Decrypted key, or null when missing or unreadable.
	 *
	 * @param Crypto $crypto Cipher.
	 */
	public static function api_key( Crypto $crypto ): ?string {
		$data = Options::get_array( Options::CONNECTION );
		if ( null === $data || empty( $data['key'] ) || ( $data['api_base'] ?? '' ) !== Config::api_base() ) {
			return null;
		}
		return $crypto->decrypt( (string) $data['key'] );
	}

	/**
	 * Migration this connection is bound to.
	 */
	public static function migration_uuid(): ?string {
		$data = self::get();
		return null === $data ? null : (string) $data['migration_uuid'];
	}

	/**
	 * Follows a retry that created a new migration execution.
	 *
	 * @param string $uuid New migration UUID.
	 */
	public static function switch_migration( string $uuid ): void {
		$data = Options::get_array( Options::CONNECTION );
		if ( null === $data || 1 !== preg_match( self::UUID_PATTERN, $uuid ) ) {
			return;
		}
		$data['migration_uuid'] = strtolower( $uuid );
		Options::set( Options::CONNECTION, $data );
	}

	/**
	 * Removes the key (disconnect).
	 */
	public static function forget(): void {
		delete_option( Options::CONNECTION );
	}

	/**
	 * Plain bounded text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 */
	private static function text( $value, int $max ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return substr( (string) $value, 0, $max );
	}

	/**
	 * Scope token.
	 *
	 * @param mixed $scope Scope.
	 */
	private static function scope( $scope ): string {
		return is_string( $scope ) ? (string) preg_replace( '/[^a-z:_]/', '', strtolower( $scope ) ) : '';
	}
}
