<?php
/**
 * "Connect to BladeCP": Hub URL, per-user PKCE state and callback validation.
 *
 * Flow:
 * 1. begin() stores a short-lived, per-user verifier + state and returns the
 *    Hub URL (client=wordpress, intent=migration, S256 challenge).
 * 2. The person picks the destination server in the Hub and confirms.
 * 3. The Hub redirects back to wp-admin/admin.php?page=bladecp-migrator with
 *    code + state (or error=access_denied + state).
 * 4. consume() validates the state for the same user (single use) and hands
 *    back the verifier + redirect URI for the token exchange.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Connect flow helpers.
 */
final class Connect_Flow {

	const TRANSIENT_PREFIX    = 'bladecp_migrator_connect_';
	const TTL                 = 900;
	const ERROR_CANCELLED     = 'access_denied';
	const ERROR_PENDING_LIMIT = 'connect_pending_limit';

	/**
	 * Handles the return from the Hub (code + state, or error + state) and
	 * stores the connection on success.
	 *
	 * An error return changes nothing on the site, so its message is shown
	 * even when the state is stale (the person may click the Hub's "back to
	 * WordPress" link long after cancelling). Every outcome other than success
	 * leaves the site disconnected, with "Connect to BladeCP" available again.
	 *
	 * @param int        $user_id  Current user.
	 * @param string     $state    State from the query string.
	 * @param string     $code     Code from the query string.
	 * @param string     $error    Error from the query string.
	 * @param Api_Client $api      Public API client (token exchange).
	 * @param Crypto     $crypto   Cipher for the key.
	 * @param string     $api_base API base the key belongs to.
	 * @param int        $now      Current time.
	 * @return array{type:string,message:string,connected:bool}
	 */
	public static function finish( int $user_id, string $state, string $code, string $error, Api_Client $api, Crypto $crypto, string $api_base, int $now ): array {
		$pending = self::consume( $user_id, $state, $now );
		if ( '' !== $error ) {
			return self::hub_error( $error );
		}
		if ( null === $pending ) {
			return self::outcome( 'error', __( 'This connection link expired or was already used. Click "Connect to BladeCP" again.', 'bladecp-migrator' ) );
		}
		if ( '' === $code ) {
			return self::outcome( 'error', __( 'BladeCP could not complete the connection. Click "Connect to BladeCP" to try again.', 'bladecp-migrator' ) );
		}
		try {
			$data = $api->exchange_code( $code, $pending['verifier'], $pending['redirect_uri'] );
			Connection::save_from_exchange( $data, $crypto, $api_base, $user_id, $now );
		} catch ( Api_Error $e ) {
			return self::outcome( 'error', self::exchange_message( $e ) );
		} catch ( \UnexpectedValueException $e ) {
			return self::outcome( 'error', __( 'BladeCP sent a response the plugin did not understand. Try again in a few minutes.', 'bladecp-migrator' ) );
		} catch ( \RuntimeException $e ) {
			// The key could not be encrypted (Crypto fails closed): nothing was stored.
			return self::outcome(
				'error',
				Crypto::is_available()
					? __( 'The BladeCP connection could not be stored safely, so nothing was saved. Click "Connect to BladeCP" to try again.', 'bladecp-migrator' )
					: __( 'PHP has neither OpenSSL with AES-256-GCM nor sodium, so the BladeCP connection could not be stored safely. Ask your host to enable one of them.', 'bladecp-migrator' )
			);
		}
		return array(
			'type'      => 'success',
			'message'   => __( 'Connected to BladeCP. You can start the migration.', 'bladecp-migrator' ),
			'connected' => true,
		);
	}

	/**
	 * Message for an error sent back by the Hub.
	 *
	 * @param string $error Error code (access_denied, connect_pending_limit, ...).
	 * @return array{type:string,message:string,connected:bool}
	 */
	public static function hub_error( string $error ): array {
		switch ( strtolower( $error ) ) {
			case self::ERROR_CANCELLED:
				return self::outcome( 'info', __( 'The connection was cancelled in BladeCP. Nothing was changed. Click "Connect to BladeCP" when you want to try again.', 'bladecp-migrator' ) );
			case self::ERROR_PENDING_LIMIT:
				return self::outcome( 'error', self::pending_limit_message() );
		}
		return self::outcome( 'error', __( 'BladeCP could not complete the connection. Click "Connect to BladeCP" to try again.', 'bladecp-migrator' ) );
	}

	/**
	 * Message for a failed token exchange. An expired, used or cancelled
	 * connection request (the draft migration is cancelled when it expires)
	 * just needs a new connection.
	 *
	 * @param Api_Error $e Error.
	 */
	public static function exchange_message( Api_Error $e ): string {
		if ( self::ERROR_PENDING_LIMIT === strtolower( $e->error_code() ) ) {
			return self::pending_limit_message();
		}
		if ( in_array( $e->kind(), array( Api_Error::KIND_INVALID_GRANT, Api_Error::KIND_NOT_FOUND, Api_Error::KIND_CONFLICT, Api_Error::KIND_INVALID_REQUEST ), true ) ) {
			return __( 'This connection request expired or was cancelled in BladeCP. Click "Connect to BladeCP" to start again.', 'bladecp-migrator' );
		}
		return $e->user_message();
	}

	/**
	 * Too many unfinished WordPress connections on the account.
	 */
	public static function pending_limit_message(): string {
		return __( 'Your BladeCP account has too many unfinished connections. Finish or cancel them in BladeCP, or wait a few minutes, then click "Connect to BladeCP" again.', 'bladecp-migrator' );
	}

	/**
	 * Non-success outcome.
	 *
	 * @param string $type    error or info.
	 * @param string $message Message.
	 */
	private static function outcome( string $type, string $message ): array {
		return array(
			'type'      => $type,
			'message'   => $message,
			'connected' => false,
		);
	}

	/**
	 * Starts a connection attempt and returns the Hub URL to open.
	 *
	 * @param int    $user_id      Current user.
	 * @param string $site_host    Host shown in the Hub (this site).
	 * @param string $redirect_uri Fixed admin.php URL of this site.
	 * @param int    $now          Current time.
	 */
	public static function begin( int $user_id, string $site_host, string $redirect_uri, int $now ): string {
		$verifier = Pkce::create_verifier();
		$state    = Pkce::create_state();
		set_transient(
			self::key( $user_id ),
			array(
				'state'        => $state,
				'verifier'     => $verifier,
				'redirect_uri' => $redirect_uri,
				'created'      => $now,
			),
			self::TTL
		);
		return self::build_url( Config::hub_base(), $site_host, $redirect_uri, $state, Pkce::challenge( $verifier ) );
	}

	/**
	 * Builds the Hub connect URL.
	 *
	 * @param string $hub_base     Hub base URL.
	 * @param string $site_host    Site host.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $state        Opaque state.
	 * @param string $challenge    S256 challenge.
	 */
	public static function build_url( string $hub_base, string $site_host, string $redirect_uri, string $state, string $challenge ): string {
		$query = array(
			'client'                => Config::CLIENT,
			'intent'                => Config::INTENT,
			'site'                  => $site_host,
			'redirect_uri'          => $redirect_uri,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		return rtrim( $hub_base, '/' ) . '/connect?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Validates the state returned by the Hub. Single use: a matching state is
	 * deleted, so a replayed callback fails. A mismatch keeps the pending
	 * attempt (the real callback can still arrive).
	 *
	 * @param int    $user_id Current user.
	 * @param string $state   State from the query string.
	 * @param int    $now     Current time.
	 * @return array|null Pending attempt (verifier, redirect_uri) or null.
	 */
	public static function consume( int $user_id, string $state, int $now ): ?array {
		$key     = self::key( $user_id );
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || empty( $pending['state'] ) || ! Pkce::is_valid_state( $state ) ) {
			return null;
		}
		if ( ! hash_equals( (string) $pending['state'], $state ) ) {
			return null;
		}
		delete_transient( $key );
		if ( $now - (int) $pending['created'] > self::TTL ) {
			return null;
		}
		if ( empty( $pending['verifier'] ) || ! Pkce::is_valid_verifier( (string) $pending['verifier'] ) ) {
			return null;
		}
		return array(
			'verifier'     => (string) $pending['verifier'],
			'redirect_uri' => (string) $pending['redirect_uri'],
		);
	}

	/**
	 * Forgets a pending attempt.
	 *
	 * @param int $user_id User.
	 */
	public static function forget( int $user_id ): void {
		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Transient key for a user.
	 *
	 * @param int $user_id User.
	 */
	public static function key( int $user_id ): string {
		return self::TRANSIENT_PREFIX . $user_id;
	}

	/**
	 * Fixed redirect URI: this site's wp-admin/admin.php?page=bladecp-migrator.
	 * https is required by BladeCP except on loopback hosts.
	 *
	 * @param string $admin_url admin_url( 'admin.php' ) of this site.
	 * @return string|null Redirect URI or null when the admin is not reachable over https.
	 */
	public static function redirect_uri( string $admin_url ): ?string {
		$parts = parse_url( $admin_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure parsing.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		$path = $parts['path'];
		if ( '/wp-admin/admin.php' !== substr( $path, -19 ) ) {
			return null;
		}
		$scheme = Config::is_loopback_host( $host ) ? strtolower( (string) ( $parts['scheme'] ?? 'http' ) ) : 'https';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port . $path . '?page=' . Config::PAGE_SLUG;
	}
}
