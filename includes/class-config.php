<?php
/**
 * Fixed endpoints and identifiers.
 *
 * The plugin only talks to the BladeCP API and opens the BladeCP Hub. Both
 * bases are fixed; an administrator can point them elsewhere for internal
 * testing with constants in wp-config.php.
 * Overrides must be https, or http on a loopback host.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration values.
 */
final class Config {

	/**
	 * BladeCP nameservers, for pointing a domain once the migration is complete
	 * (the same pair the BladeCP Hub shows).
	 */
	const NAMESERVERS = array( 'kant.ns.bladecp.com', 'hegel.ns.bladecp.com' );

	const API_BASE     = 'https://api.bladecp.com';
	const HUB_BASE     = 'https://hub.bladecp.com';
	const API_PREFIX   = '/api/v1';
	const CLIENT       = 'wordpress';
	const INTENT       = 'migration';
	const PAGE_SLUG    = 'bladecp-migrator';
	const CAPABILITY   = 'manage_options';
	const TEXT_DOMAIN  = 'bladecp-migrator';
	const API_CONSTANT = 'BLADECP_MIGRATOR_API_URL';
	const HUB_CONSTANT = 'BLADECP_MIGRATOR_HUB_URL';

	/**
	 * API base URL without trailing slash.
	 */
	public static function api_base(): string {
		return self::resolve_base( self::API_CONSTANT, self::API_BASE );
	}

	/**
	 * Hub base URL without trailing slash.
	 */
	public static function hub_base(): string {
		return self::resolve_base( self::HUB_CONSTANT, self::HUB_BASE );
	}

	/**
	 * Whether an override constant is active and valid.
	 */
	public static function uses_override(): bool {
		return self::api_base() !== self::API_BASE || self::hub_base() !== self::HUB_BASE;
	}

	/**
	 * Hub page of one migration.
	 *
	 * @param string $migration_uuid Migration UUID.
	 */
	public static function hub_migration_url( string $migration_uuid ): string {
		return self::hub_base() . '/deploy/migrate/' . rawurlencode( $migration_uuid );
	}

	/**
	 * Hub support page. It knows who signs in: a direct customer sees BladeCP
	 * support, a white-label partner's customer sees the partner's channel.
	 */
	public static function hub_support_url(): string {
		return self::hub_base() . '/support';
	}

	/**
	 * Returns the override when it is a valid base URL, otherwise the default.
	 *
	 * @param string $constant Constant name.
	 * @param string $fallback Default base URL.
	 */
	public static function resolve_base( string $constant, string $fallback ): string {
		if ( defined( $constant ) ) {
			$value = constant( $constant );
			if ( is_string( $value ) ) {
				$normalized = self::normalize_base( $value );
				if ( null !== $normalized ) {
					return $normalized;
				}
			}
		}
		return $fallback;
	}

	/**
	 * Validates and normalizes a base URL: scheme and host (and port) only.
	 *
	 * @param string $url Candidate URL.
	 * @return string|null Normalized base or null when not acceptable.
	 */
	public static function normalize_base( string $url ): ?string {
		$parts = parse_url( trim( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure parsing, also used outside WordPress in tests.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		if ( isset( $parts['path'] ) && '' !== trim( $parts['path'], '/' ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::is_loopback_host( $host ) ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}

	/**
	 * Whether a URL is acceptable for a request made by the plugin: https, or
	 * http on a loopback host (local development).
	 *
	 * @param string $url URL.
	 */
	public static function is_acceptable_url( string $url ): bool {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure parsing.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		return 'https' === $scheme || ( 'http' === $scheme && self::is_loopback_host( strtolower( $parts['host'] ) ) );
	}

	/**
	 * Loopback hosts where plain http is tolerated (local testing only; the
	 * Hub accepts http redirect URIs only for these two hosts).
	 *
	 * @param string $host Lowercase host.
	 */
	public static function is_loopback_host( string $host ): bool {
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true );
	}
}
