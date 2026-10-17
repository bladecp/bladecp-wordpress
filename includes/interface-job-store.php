<?php
/**
 * Job persistence.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Job storage.
 */
interface Job_Store {

	/**
	 * Current job, read fresh (no stale cache), or null.
	 */
	public function load(): ?array;

	/**
	 * Saves the job.
	 *
	 * @param array $job Job.
	 */
	public function save( array $job ): void;

	/**
	 * Removes the job.
	 */
	public function clear(): void;
}
