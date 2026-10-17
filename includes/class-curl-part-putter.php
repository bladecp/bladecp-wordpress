<?php
/**
 * Streaming PUT with cURL: the part is read from disk in small chunks by a
 * read callback, so a 64 MiB part never sits in PHP memory.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * cURL part uploader.
 */
final class Curl_Part_Putter implements Part_Putter {

	/**
	 * CA bundle path, or empty for the system default.
	 *
	 * @var string
	 */
	private $ca_bundle;

	/**
	 * Constructor.
	 *
	 * @param string $ca_bundle CA bundle (WordPress ships one).
	 */
	public function __construct( string $ca_bundle = '' ) {
		$this->ca_bundle = $ca_bundle;
	}

	/**
	 * Whether cURL is usable.
	 */
	public static function is_available(): bool {
		return function_exists( 'curl_init' ) && function_exists( 'curl_setopt_array' ) && defined( 'CURLOPT_READFUNCTION' );
	}

	/**
	 * PUTs a byte range.
	 *
	 * @param string        $url         Presigned URL.
	 * @param string        $file        Local file.
	 * @param int           $offset      Offset.
	 * @param int           $length      Length.
	 * @param callable|null $on_progress Progress callback.
	 */
	public function put( string $url, string $file, int $offset, int $length, ?callable $on_progress = null ): array {
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local file into cURL.
		if ( false === $handle ) {
			return array(
				'status' => 0,
				'etag'   => '',
				'error'  => 'package file is not readable',
			);
		}
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array(
				'status' => 0,
				'etag'   => '',
				'error'  => 'seek failed',
			);
		}
		$remaining = $length;
		$etag      = '';
		$curl      = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- streaming upload, see class comment.
		$options   = array(
			CURLOPT_UPLOAD         => true,
			CURLOPT_INFILE         => $handle,
			CURLOPT_INFILESIZE     => $length,
			CURLOPT_READFUNCTION   => static function ( $curl_handle, $stream, $max ) use ( &$remaining ) {
				if ( $remaining <= 0 ) {
					return '';
				}
				$chunk = fread( $stream, (int) min( $max, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk ) {
					return '';
				}
				$remaining -= strlen( $chunk );
				return $chunk;
			},
			CURLOPT_HEADERFUNCTION => static function ( $curl_handle, $line ) use ( &$etag ) {
				if ( 0 === stripos( $line, 'etag:' ) ) {
					$etag = trim( substr( $line, 5 ) );
				}
				return strlen( $line );
			},
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => array( 'Expect:', 'Content-Type:' ),
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_LOW_SPEED_LIMIT => 1024,
			CURLOPT_LOW_SPEED_TIME => 120,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		);
		if ( '' !== $this->ca_bundle && is_readable( $this->ca_bundle ) ) {
			$options[ CURLOPT_CAINFO ] = $this->ca_bundle;
		}
		if ( null !== $on_progress ) {
			$options[ CURLOPT_NOPROGRESS ]       = false;
			$options[ CURLOPT_PROGRESSFUNCTION ] = static function ( $curl_handle, $download_total, $downloaded, $upload_total, $uploaded ) use ( $on_progress ) {
				$on_progress( (int) $uploaded );
				return 0;
			};
		}
		curl_setopt_array( $curl, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		curl_exec( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$errno  = curl_errno( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		$error  = $errno ? curl_error( $curl ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'status' => $status,
			'etag'   => $etag,
			'error'  => (string) $error,
		);
	}
}
