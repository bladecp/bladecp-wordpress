<?php
/**
 * Plain HTTP response value.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP response.
 */
final class Http_Response {

	/**
	 * Status code (0 when no response was received).
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Lowercase header name to value.
	 *
	 * @var array<string,string>
	 */
	public $headers;

	/**
	 * Body.
	 *
	 * @var string
	 */
	public $body;

	/**
	 * Constructor.
	 *
	 * @param int    $status  Status.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 */
	public function __construct( int $status, string $body, array $headers = array() ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}
}
