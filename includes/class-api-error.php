<?php
/**
 * BladeCP API error with a plain-language message.
 *
 * The API error shape is { error: true, message?, code? }. Some routes put a
 * machine code in `message` (for example MIGRATION_UPLOAD_NOT_ALLOWED), so a
 * code-looking message is treated as the code.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * API error.
 */
final class Api_Error extends \RuntimeException {

	const KIND_NETWORK             = 'network';
	const KIND_INVALID_GRANT       = 'invalid_grant';
	const KIND_AUTH                = 'auth';
	const KIND_COMMERCE_RESTRICTED = 'commerce_restricted';
	const KIND_BOUND               = 'bound';
	const KIND_FORBIDDEN           = 'forbidden';
	const KIND_NOT_FOUND           = 'not_found';
	const KIND_CONFLICT            = 'conflict';
	const KIND_TOO_LARGE           = 'too_large';
	const KIND_RATE_LIMITED        = 'rate_limited';
	const KIND_SERVER              = 'server';
	const KIND_INVALID_REQUEST     = 'invalid_request';
	const KIND_INVALID_RESPONSE    = 'invalid_response';
	const KIND_UNKNOWN             = 'unknown';

	/**
	 * HTTP status (0 without response).
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Machine code from the API, if any.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Classification.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Human message from the API (never shown raw without escaping).
	 *
	 * @var string
	 */
	private $api_message;

	/**
	 * Constructor.
	 *
	 * @param string $kind        Kind.
	 * @param int    $status      HTTP status.
	 * @param string $error_code  Code.
	 * @param string $api_message API message.
	 */
	public function __construct( string $kind, int $status = 0, string $error_code = '', string $api_message = '' ) {
		parent::__construct( sprintf( 'BladeCP API error: %s (HTTP %d%s)', $kind, $status, '' !== $error_code ? ', ' . $error_code : '' ) );
		$this->kind        = $kind;
		$this->status      = $status;
		$this->error_code  = $error_code;
		$this->api_message = $api_message;
	}

	/**
	 * Builds an error from an HTTP response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	public static function from_response( int $status, string $body ): self {
		$decoded = json_decode( $body, true );
		$code    = '';
		$message = '';
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['code'] ) && is_string( $decoded['code'] ) ) {
				$code = $decoded['code'];
			}
			if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$message = $decoded['message'];
			}
			if ( '' === $code && isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				$code = $decoded['error'];
			}
		}
		if ( '' === $code && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{2,63}$/', $message ) ) {
			$code = $message;
		}
		$code    = substr( (string) preg_replace( '/[^A-Za-z0-9_.-]/', '', $code ), 0, 64 );
		$message = substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', ' ', $message ), 0, 300 );
		return new self( self::classify( $status, $code, $message ), $status, $code, $message );
	}

	/**
	 * Transport failure.
	 *
	 * @param string $detail Detail (not shown to the person).
	 */
	public static function network( string $detail = '' ): self {
		return new self( self::KIND_NETWORK, 0, '', substr( $detail, 0, 300 ) );
	}

	/**
	 * Successful status but unusable body.
	 *
	 * @param string $detail Detail.
	 */
	public static function invalid_response( string $detail = '' ): self {
		return new self( self::KIND_INVALID_RESPONSE, 0, '', substr( $detail, 0, 300 ) );
	}

	/**
	 * Maps status and code to a kind.
	 *
	 * @param int    $status  Status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	public static function classify( int $status, string $code, string $message ): string {
		$upper = strtoupper( $code );
		if ( 0 === $status ) {
			return self::KIND_NETWORK;
		}
		if ( 'INVALID_GRANT' === $upper ) {
			return self::KIND_INVALID_GRANT;
		}
		if ( 401 === $status ) {
			return self::KIND_AUTH;
		}
		if ( 'COMMERCE_RESTRICTED' === $upper ) {
			return self::KIND_COMMERCE_RESTRICTED;
		}
		if ( 'KEY_BOUND_TO_MIGRATION' === $upper ) {
			return self::KIND_BOUND;
		}
		if ( 403 === $status ) {
			return self::KIND_FORBIDDEN;
		}
		if ( 404 === $status ) {
			return self::KIND_NOT_FOUND;
		}
		if ( 413 === $status || false !== stripos( $message, '50 GB' ) ) {
			return self::KIND_TOO_LARGE;
		}
		if ( 409 === $status ) {
			return self::KIND_CONFLICT;
		}
		if ( 429 === $status ) {
			return self::KIND_RATE_LIMITED;
		}
		if ( $status >= 500 ) {
			return self::KIND_SERVER;
		}
		if ( $status >= 400 ) {
			return self::KIND_INVALID_REQUEST;
		}
		return self::KIND_UNKNOWN;
	}

	/**
	 * Kind.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * API code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * API message.
	 */
	public function api_message(): string {
		return $this->api_message;
	}

	/**
	 * Whether trying again later can succeed without any action.
	 */
	public function is_retryable(): bool {
		return in_array( $this->kind, array( self::KIND_NETWORK, self::KIND_RATE_LIMITED, self::KIND_SERVER ), true );
	}

	/**
	 * Whether the stored key is no longer usable.
	 */
	public function requires_reconnect(): bool {
		return in_array( $this->kind, array( self::KIND_AUTH, self::KIND_FORBIDDEN ), true );
	}

	/**
	 * Seconds to wait before trying again.
	 */
	public function retry_after(): int {
		if ( self::KIND_RATE_LIMITED === $this->kind ) {
			return 60;
		}
		return self::KIND_SERVER === $this->kind ? 30 : 15;
	}

	/**
	 * Plain-language message for the person.
	 */
	public function user_message(): string {
		switch ( strtoupper( $this->error_code ) ) {
			case 'MIGRATION_UPLOAD_NOT_ALLOWED':
				return __( 'This migration no longer accepts a new package. Open it in BladeCP to see its current state.', 'bladecp-migrator' );
			case 'MIGRATION_DOMAIN_UNAVAILABLE':
				return __( 'This domain is already in use in BladeCP. Choose another domain or remove the existing one in BladeCP.', 'bladecp-migrator' );
			case 'MIGRATION_DISCOVERY_CHOICE_CONFLICT':
				return __( 'A different domain was already confirmed for this migration.', 'bladecp-migrator' );
			case 'MIGRATION_DISCOVERY_REVIEW_UNAVAILABLE':
			case 'MIGRATION_DISCOVERY_REVIEW_PENDING':
				return __( 'The domain cannot be confirmed right now. Wait a moment and try again.', 'bladecp-migrator' );
			case 'MIGRATION_RETRY_UNAVAILABLE':
			case 'MIGRATION_BACKUP_UNAVAILABLE':
				return __( 'This migration cannot be retried. Connect again to start a new migration.', 'bladecp-migrator' );
			case 'CONNECT_PENDING_LIMIT':
				return Connect_Flow::pending_limit_message();
			case 'MIGRATION_BACKUP_STORAGE_UNAVAILABLE':
				return __( 'BladeCP storage is temporarily unavailable. The plugin will try again.', 'bladecp-migrator' );
		}
		switch ( $this->kind ) {
			case self::KIND_NETWORK:
				return __( 'Could not reach BladeCP. Check that this site can make outgoing HTTPS connections and try again.', 'bladecp-migrator' );
			case self::KIND_INVALID_GRANT:
				return __( 'The authorization expired, was already used or was cancelled. Click "Connect to BladeCP" to start again.', 'bladecp-migrator' );
			case self::KIND_AUTH:
				return __( 'Your BladeCP connection expired or was removed. Connect to BladeCP again.', 'bladecp-migrator' );
			case self::KIND_COMMERCE_RESTRICTED:
				return __( 'Migrations are not open for your BladeCP account yet. Contact BladeCP support.', 'bladecp-migrator' );
			case self::KIND_BOUND:
				return __( 'This connection only works for the migration chosen in BladeCP. Connect again to start another migration.', 'bladecp-migrator' );
			case self::KIND_FORBIDDEN:
				return __( 'This connection is not allowed to do this. Connect to BladeCP again.', 'bladecp-migrator' );
			case self::KIND_NOT_FOUND:
				return __( 'This migration was not found in your BladeCP account. Connect again to start a new migration.', 'bladecp-migrator' );
			case self::KIND_CONFLICT:
				return __( 'The migration changed in the meantime. The screen now shows its current state.', 'bladecp-migrator' );
			case self::KIND_TOO_LARGE:
				return __( 'The site package is larger than the 50 GB limit.', 'bladecp-migrator' );
			case self::KIND_RATE_LIMITED:
				return __( 'BladeCP received too many requests from this site. The plugin will try again in a minute.', 'bladecp-migrator' );
			case self::KIND_SERVER:
				return __( 'BladeCP is temporarily unavailable. The plugin will try again.', 'bladecp-migrator' );
			case self::KIND_INVALID_RESPONSE:
				return __( 'BladeCP sent a response the plugin did not understand. Try again in a few minutes.', 'bladecp-migrator' );
			case self::KIND_INVALID_REQUEST:
				return __( 'BladeCP did not accept the request. Try again, and contact BladeCP support if it keeps happening.', 'bladecp-migrator' );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'BladeCP returned an unexpected error (HTTP %d).', 'bladecp-migrator' ), $this->status );
	}
}
