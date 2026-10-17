<?php
/**
 * Encryption at rest for the BladeCP key, keyed by this site's salts.
 *
 * New values use OpenSSL AES-256-GCM ("bcp1g:"). libsodium secretbox
 * ("bcp1s:") is only the fallback, for a PHP without OpenSSL or without the
 * aes-256-gcm cipher. Both formats stay readable while the mechanism they were
 * written with exists (WordPress defines the sodium functions with its own
 * polyfill when the extension is missing). Both are authenticated: a changed
 * byte, a truncated value or another key reads as null, never as a wrong key.
 *
 * The key never leaves the database in clear text and is never autoloaded.
 * Changing the WordPress salts, or losing the mechanism a value was written
 * with, makes the stored key unreadable: the plugin then asks the person to
 * connect again. A stored value is never rewritten or removed here.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption: OpenSSL AES-256-GCM first, libsodium secretbox as the fallback.
 */
final class Crypto {

	const GCM_PREFIX    = 'bcp1g:';
	const SODIUM_PREFIX = 'bcp1s:';

	const ENGINE_OPENSSL = 'openssl';
	const ENGINE_SODIUM  = 'sodium';

	const GCM_CIPHER         = 'aes-256-gcm';
	const GCM_IV_BYTES       = 12;
	const GCM_TAG_BYTES      = 16;
	const SODIUM_NONCE_BYTES = 24;
	const SODIUM_MAC_BYTES   = 16;

	/**
	 * 32-byte key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Mechanisms this cipher may use, in preference order.
	 *
	 * @var string[]
	 */
	private $engines;

	/**
	 * Builds a cipher from secret material (the site salts).
	 *
	 * @param string        $material Secret material.
	 * @param string[]|null $only     Mechanisms to allow (null: every one this
	 *                                PHP has). It only narrows the list: a
	 *                                mechanism this PHP lacks is never used.
	 */
	public function __construct( string $material, ?array $only = null ) {
		$this->key     = hash_hmac( 'sha256', 'bladecp-migrator/v1/at-rest', $material, true );
		$this->engines = self::usable( $only );
	}

	/**
	 * Cipher keyed by this site's salts.
	 */
	public static function for_site(): self {
		return new self( wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Mechanisms this PHP has, in preference order.
	 *
	 * @return string[]
	 */
	public static function detected(): array {
		$found = array();
		if ( self::sodium_available() ) {
			$found[] = self::ENGINE_SODIUM;
		}
		if ( self::gcm_available() ) {
			$found[] = self::ENGINE_OPENSSL;
		}
		return self::by_preference( $found );
	}

	/**
	 * Known mechanism names in preference order: OpenSSL AES-256-GCM, then
	 * sodium. Pure: it does not look at this PHP.
	 *
	 * @param string[] $engines Mechanism names.
	 * @return string[]
	 */
	public static function by_preference( array $engines ): array {
		return array_values( array_intersect( array( self::ENGINE_OPENSSL, self::ENGINE_SODIUM ), $engines ) );
	}

	/**
	 * Mechanism new values are encrypted with, or null when there is none.
	 *
	 * @param string[]|null $only Mechanisms to allow, as in the constructor.
	 */
	public static function preferred_engine( ?array $only = null ): ?string {
		$engines = self::usable( $only );
		return $engines[0] ?? null;
	}

	/**
	 * Whether this PHP can encrypt at all.
	 *
	 * @param string[]|null $only Mechanisms to allow, as in the constructor.
	 */
	public static function is_available( ?array $only = null ): bool {
		return null !== self::preferred_engine( $only );
	}

	/**
	 * Mechanism this cipher encrypts with, or null when there is none.
	 */
	public function engine(): ?string {
		return $this->engines[0] ?? null;
	}

	/**
	 * Encrypts a value with the preferred mechanism.
	 *
	 * @param string $plain Clear text.
	 * @throws \RuntimeException When no mechanism is available or the result
	 *                           does not read back (nothing must be stored).
	 */
	public function encrypt( string $plain ): string {
		$engine = $this->engine();
		if ( null === $engine ) {
			throw new \RuntimeException( 'no cipher available' );
		}
		$blob = self::ENGINE_OPENSSL === $engine ? $this->seal_gcm( $plain ) : $this->seal_sodium( $plain );
		// Fail closed: a value is only handed out when it reads back to the same text.
		$back = null === $blob ? null : $this->decrypt( $blob );
		if ( null === $blob || null === $back || ! hash_equals( $plain, $back ) ) {
			throw new \RuntimeException( 'encryption failed' );
		}
		return $blob;
	}

	/**
	 * Decrypts a value; null when tampered, truncated, keyed differently or
	 * written with a mechanism this PHP does not have.
	 *
	 * @param string $blob Stored value.
	 */
	public function decrypt( string $blob ): ?string {
		if ( 0 === strpos( $blob, self::GCM_PREFIX ) ) {
			return $this->allows( self::ENGINE_OPENSSL ) ? $this->open_gcm( self::payload( $blob, self::GCM_PREFIX ) ) : null;
		}
		if ( 0 === strpos( $blob, self::SODIUM_PREFIX ) ) {
			return $this->allows( self::ENGINE_SODIUM ) ? $this->open_sodium( self::payload( $blob, self::SODIUM_PREFIX ) ) : null;
		}
		return null;
	}

	/**
	 * AES-256-GCM: prefix + base64( iv(12) . tag(16) . ciphertext ).
	 *
	 * @param string $plain Clear text.
	 */
	private function seal_gcm( string $plain ): ?string {
		$iv     = random_bytes( self::GCM_IV_BYTES );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::GCM_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_BYTES );
		if ( false === $cipher || self::GCM_TAG_BYTES !== strlen( (string) $tag ) ) {
			return null;
		}
		return self::GCM_PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ciphertext transport.
	}

	/**
	 * Opens an AES-256-GCM payload; the tag check rejects any change.
	 *
	 * @param string|null $raw Decoded payload.
	 */
	private function open_gcm( ?string $raw ): ?string {
		if ( null === $raw || strlen( $raw ) < self::GCM_IV_BYTES + self::GCM_TAG_BYTES ) {
			return null;
		}
		$plain = openssl_decrypt(
			(string) substr( $raw, self::GCM_IV_BYTES + self::GCM_TAG_BYTES ),
			self::GCM_CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			substr( $raw, 0, self::GCM_IV_BYTES ),
			substr( $raw, self::GCM_IV_BYTES, self::GCM_TAG_BYTES )
		);
		return false === $plain ? null : $plain;
	}

	/**
	 * Secretbox: prefix + base64( nonce(24) . box ).
	 *
	 * @param string $plain Clear text.
	 */
	private function seal_sodium( string $plain ): ?string {
		try {
			$nonce = random_bytes( self::SODIUM_NONCE_BYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, $this->key );
		} catch ( \Throwable $e ) {
			return null;
		}
		return self::SODIUM_PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ciphertext transport.
	}

	/**
	 * Opens a secretbox payload; the MAC check rejects any change.
	 *
	 * @param string|null $raw Decoded payload.
	 */
	private function open_sodium( ?string $raw ): ?string {
		if ( null === $raw || strlen( $raw ) < self::SODIUM_NONCE_BYTES + self::SODIUM_MAC_BYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, self::SODIUM_NONCE_BYTES ), substr( $raw, 0, self::SODIUM_NONCE_BYTES ), $this->key );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $plain ) ? $plain : null;
	}

	/**
	 * Decoded payload after the prefix, or null when it is not strict base64.
	 *
	 * @param string $blob   Stored value.
	 * @param string $prefix Format prefix.
	 */
	private static function payload( string $blob, string $prefix ): ?string {
		$raw = base64_decode( substr( $blob, strlen( $prefix ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- ciphertext transport.
		return false === $raw ? null : $raw;
	}

	/**
	 * Whether this cipher may use a mechanism.
	 *
	 * @param string $engine Mechanism.
	 */
	private function allows( string $engine ): bool {
		return in_array( $engine, $this->engines, true );
	}

	/**
	 * Mechanisms this PHP has, narrowed to the allowed names.
	 *
	 * @param string[]|null $only Mechanisms to allow (null: all).
	 * @return string[]
	 */
	private static function usable( ?array $only ): array {
		$found = self::detected();
		return null === $only ? $found : array_values( array_intersect( $found, $only ) );
	}

	/**
	 * Whether OpenSSL with the aes-256-gcm cipher is usable.
	 */
	private static function gcm_available(): bool {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
			return false;
		}
		return in_array( self::GCM_CIPHER, array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	/**
	 * Whether the secretbox functions exist (the extension, or the polyfill
	 * WordPress loads without it; both read the same format).
	 */
	private static function sodium_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}
}
