<?php
/**
 * Fallback part upload through the WordPress HTTP API, used only when cURL
 * is missing. It must hold the whole part in memory, so it is chosen only
 * when the memory limit leaves enough room (see Part_Putter_Factory).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * In-memory part uploader.
 */
final class Wp_Part_Putter implements Part_Putter {

	/**
	 * PUTs a byte range.
	 *
	 * @param string        $url         Presigned URL.
	 * @param string        $file        Local file.
	 * @param int           $offset      Offset.
	 * @param int           $length      Length.
	 * @param callable|null $on_progress Progress callback (called once at the end).
	 */
	public function put( string $url, string $file, int $offset, int $length, ?callable $on_progress = null ): array {
		$body = file_get_contents( $file, false, null, $offset, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $body || strlen( $body ) !== $length ) {
			return array(
				'status' => 0,
				'etag'   => '',
				'error'  => 'package file is not readable',
			);
		}
		$response = wp_remote_request(
			$url,
			array(
				'method'      => 'PUT',
				'body'        => $body,
				'timeout'     => 600,
				'redirection' => 0,
				'headers'     => array( 'Content-Length' => (string) $length ),
			)
		);
		unset( $body );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'etag'   => '',
				'error'  => $response->get_error_message(),
			);
		}
		if ( null !== $on_progress ) {
			$on_progress( $length );
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'etag'   => trim( (string) wp_remote_retrieve_header( $response, 'etag' ) ),
			'error'  => '',
		);
	}
}
