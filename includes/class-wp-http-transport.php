<?php
/**
 * HTTP transport over the WordPress HTTP API.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * wp_remote_request() transport.
 */
final class Wp_Http_Transport implements Http_Transport {

	/**
	 * Performs a request.
	 *
	 * @param string      $method  HTTP method.
	 * @param string      $url     Absolute URL.
	 * @param array       $headers Headers.
	 * @param string|null $body    Body.
	 * @param int         $timeout Seconds.
	 * @throws Api_Error On transport failure.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): Http_Response {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			// The WP_Error text can carry the URL but never the Authorization header.
			throw Api_Error::network( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
		}
		$headers_out = array();
		$raw_headers = wp_remote_retrieve_headers( $response );
		// A Requests CaseInsensitiveDictionary is iterable but must not be cast to array.
		$raw_headers = is_array( $raw_headers ) || $raw_headers instanceof \Traversable ? $raw_headers : array();
		foreach ( $raw_headers as $name => $value ) {
			$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return new Http_Response( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $headers_out );
	}
}
