<?php
/**
 * BladeCP Customer API client (only the routes this plugin needs).
 *
 * Every request goes to Config::api_base(). The key is sent only in the
 * Authorization header and never appears in errors or logs.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * API client.
 */
final class Api_Client {

	const TIMEOUT = 30;

	/**
	 * Transport.
	 *
	 * @var Http_Transport
	 */
	private $http;

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * API key (null for the public token exchange).
	 *
	 * @var string|null
	 */
	private $api_key;

	/**
	 * Accept-Language value.
	 *
	 * @var string
	 */
	private $language;

	/**
	 * Constructor.
	 *
	 * @param Http_Transport $http     Transport.
	 * @param string         $base     API base URL.
	 * @param string|null    $api_key  Key.
	 * @param string         $language Accept-Language (for example pt-BR).
	 */
	public function __construct( Http_Transport $http, string $base, ?string $api_key, string $language = 'en' ) {
		$this->http     = $http;
		$this->base     = rtrim( $base, '/' );
		$this->api_key  = $api_key;
		$this->language = $language;
	}

	/**
	 * POST /customer/connect/token (public).
	 *
	 * @param string $code         One-time code.
	 * @param string $verifier     PKCE verifier.
	 * @param string $redirect_uri The redirect URI used to start the flow.
	 */
	public function exchange_code( string $code, string $verifier, string $redirect_uri ): array {
		return $this->call(
			'POST',
			'/customer/connect/token',
			array(
				'client'       => Config::CLIENT,
				'code'         => $code,
				'codeVerifier' => $verifier,
				'redirectUri'  => $redirect_uri,
			),
			false
		);
	}

	/**
	 * GET /customer/me/migrations/{uuid}.
	 *
	 * @param string $uuid Migration.
	 */
	public function get_migration( string $uuid ): array {
		return $this->call( 'GET', self::migration_path( $uuid ), null );
	}

	/**
	 * POST /customer/me/migrations/{uuid}/upload-url.
	 *
	 * @param string $uuid       Migration.
	 * @param int    $size_bytes Final package size.
	 * @param string $filename   Package file name.
	 * @param bool   $resume     Reuse an open upload of the same size.
	 */
	public function request_upload_urls( string $uuid, int $size_bytes, string $filename, bool $resume = true ): array {
		return $this->call(
			'POST',
			self::migration_path( $uuid ) . '/upload-url',
			array(
				'sizeBytes' => $size_bytes,
				'filename'  => $filename,
				'resume'    => $resume,
			)
		);
	}

	/**
	 * POST /customer/me/migrations/{uuid}/complete-upload.
	 *
	 * @param string $uuid  Migration.
	 * @param array  $parts List of { partNumber, etag }.
	 */
	public function complete_upload( string $uuid, array $parts ): array {
		return $this->call( 'POST', self::migration_path( $uuid ) . '/complete-upload', array( 'parts' => $parts ) );
	}

	/**
	 * POST /customer/me/migrations/{uuid}/confirm-upload.
	 *
	 * @param string $uuid Migration.
	 */
	public function confirm_upload( string $uuid ): array {
		return $this->call( 'POST', self::migration_path( $uuid ) . '/confirm-upload', new \stdClass() );
	}

	/**
	 * POST /customer/me/migrations/{uuid}/discovery-review.
	 *
	 * @param string $uuid   Migration.
	 * @param string $domain Confirmed primary domain.
	 */
	public function discovery_review( string $uuid, string $domain ): array {
		return $this->call( 'POST', self::migration_path( $uuid ) . '/discovery-review', array( 'primaryDomain' => $domain ) );
	}

	/**
	 * POST /customer/me/migrations/{uuid}/approve.
	 *
	 * @param string $uuid Migration.
	 */
	public function approve( string $uuid ): array {
		return $this->call( 'POST', self::migration_path( $uuid ) . '/approve', new \stdClass() );
	}

	/**
	 * POST /customer/me/migrations/{uuid}/cancel.
	 *
	 * @param string $uuid   Migration.
	 * @param string $reason Optional reason.
	 */
	public function cancel( string $uuid, string $reason = '' ): array {
		$body = '' === $reason ? new \stdClass() : array( 'reason' => $reason );
		return $this->call( 'POST', self::migration_path( $uuid ) . '/cancel', $body );
	}

	/**
	 * POST /customer/me/migrations/{uuid}/retry.
	 *
	 * @param string $uuid Migration.
	 */
	public function retry( string $uuid ): array {
		return $this->call( 'POST', self::migration_path( $uuid ) . '/retry', new \stdClass() );
	}

	/**
	 * Path of one migration.
	 *
	 * @param string $uuid Migration.
	 * @throws Api_Error When the UUID is malformed.
	 */
	public static function migration_path( string $uuid ): string {
		if ( 1 !== preg_match( Connection::UUID_PATTERN, $uuid ) ) {
			throw new Api_Error( Api_Error::KIND_INVALID_REQUEST, 0, 'INVALID_MIGRATION_UUID' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
		}
		return '/customer/me/migrations/' . strtolower( $uuid );
	}

	/**
	 * Request headers (exposed for tests; contains the key).
	 *
	 * @param bool $authenticated Whether to send the key.
	 */
	public function headers( bool $authenticated ): array {
		$headers = array(
			'Accept'          => 'application/json',
			'Content-Type'    => 'application/json',
			'Accept-Language' => $this->language,
			'User-Agent'      => 'BladeCP-Migrator/' . ( defined( 'BLADECP_MIGRATOR_VERSION' ) ? BLADECP_MIGRATOR_VERSION : 'dev' ),
		);
		if ( $authenticated && null !== $this->api_key && '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}
		return $headers;
	}

	/**
	 * Performs a JSON call and returns `data`.
	 *
	 * @param string            $method        Method.
	 * @param string            $path          Path below /api/v1.
	 * @param array|object|null $body          JSON body.
	 * @param bool              $authenticated Send the key.
	 * @throws Api_Error On any failure.
	 */
	private function call( string $method, string $path, $body, bool $authenticated = true ): array {
		if ( $authenticated && ( null === $this->api_key || '' === $this->api_key ) ) {
			throw new Api_Error( Api_Error::KIND_AUTH, 401, 'NOT_CONNECTED' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
		}
		$encoded = null;
		if ( null !== $body ) {
			$encoded = (string) wp_json_encode( $body );
		}
		$response = $this->http->request( $method, $this->base . Config::API_PREFIX . $path, $this->headers( $authenticated ), $encoded, self::TIMEOUT );
		if ( $response->status < 200 || $response->status >= 300 ) {
			throw Api_Error::from_response( $response->status, $response->body ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
		}
		$decoded = json_decode( $response->body, true );
		if ( ! is_array( $decoded ) ) {
			throw Api_Error::invalid_response( 'body is not JSON' );
		}
		if ( ! empty( $decoded['error'] ) ) {
			throw Api_Error::from_response( $response->status, $response->body ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
		}
		$data = $decoded['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}
}
