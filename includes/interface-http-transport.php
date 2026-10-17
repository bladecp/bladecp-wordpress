<?php
/**
 * HTTP transport used by the API client (swapped by fakes in tests).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one HTTP request.
 */
interface Http_Transport {

	/**
	 * Performs a request.
	 *
	 * @param string      $method  HTTP method.
	 * @param string      $url     Absolute URL.
	 * @param array       $headers Header name to value.
	 * @param string|null $body    Body.
	 * @param int         $timeout Seconds.
	 * @throws Api_Error On transport failure (no HTTP response).
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): Http_Response;
}
