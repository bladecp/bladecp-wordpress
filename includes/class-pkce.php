<?php
/**
 * PKCE (RFC 7636) helpers for the "Connect to BladeCP" flow.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Verifier, challenge and state generation.
 */
final class Pkce {

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 encoding.
	}

	/**
	 * 32 random bytes encoded as 43 base64url characters.
	 */
	public static function create_verifier(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * Opaque state: 32 random bytes as 43 base64url characters. Never purely
	 * numeric (the Hub parses query values as JSON, so a digits-only value
	 * would turn into a number), which is checked rather than assumed.
	 */
	public static function create_state(): string {
		do {
			$state = self::base64url( random_bytes( 32 ) );
		} while ( ! self::is_valid_state( $state ) );
		return $state;
	}

	/**
	 * State syntax accepted by this plugin: 43 base64url characters with at
	 * least one non-digit.
	 *
	 * @param string $state Candidate.
	 */
	public static function is_valid_state( string $state ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $state ) && 1 === preg_match( '/[^0-9]/', $state );
	}

	/**
	 * S256 code challenge for a verifier.
	 *
	 * @param string $verifier Code verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * RFC 7636 verifier syntax: 43 to 128 unreserved characters.
	 *
	 * @param string $verifier Candidate.
	 */
	public static function is_valid_verifier( string $verifier ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier );
	}
}
