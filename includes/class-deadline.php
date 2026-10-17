<?php
/**
 * Time and memory budget of one step.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Step budget.
 */
final class Deadline {

	/**
	 * End time (microtime).
	 *
	 * @var float
	 */
	private $ends_at;

	/**
	 * Memory ceiling in bytes (0 = none).
	 *
	 * @var int
	 */
	private $memory_ceiling;

	/**
	 * Constructor.
	 *
	 * @param float $seconds        Seconds from now.
	 * @param int   $memory_ceiling Bytes (0 = derive from memory_limit).
	 */
	public function __construct( float $seconds, int $memory_ceiling = 0 ) {
		$this->ends_at = microtime( true ) + $seconds;
		if ( 0 === $memory_ceiling ) {
			$limit          = Environment::memory_limit_bytes();
			$memory_ceiling = $limit > 0 ? (int) ( $limit * 0.8 ) : 0;
		}
		$this->memory_ceiling = $memory_ceiling;
	}

	/**
	 * Whether the step should stop now.
	 */
	public function reached(): bool {
		if ( microtime( true ) >= $this->ends_at ) {
			return true;
		}
		return $this->memory_ceiling > 0 && memory_get_usage() >= $this->memory_ceiling;
	}

	/**
	 * Seconds left (may be negative).
	 */
	public function remaining(): float {
		return $this->ends_at - microtime( true );
	}
}
