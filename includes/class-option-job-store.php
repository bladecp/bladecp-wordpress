<?php
/**
 * Job stored in a non-autoloaded option.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Option job store.
 */
final class Option_Job_Store implements Job_Store {

	/**
	 * Current job.
	 */
	public function load(): ?array {
		wp_cache_delete( Options::JOB, 'options' );
		return Options::get_array( Options::JOB );
	}

	/**
	 * Saves the job.
	 *
	 * @param array $job Job.
	 */
	public function save( array $job ): void {
		Options::set( Options::JOB, $job );
	}

	/**
	 * Removes the job.
	 */
	public function clear(): void {
		delete_option( Options::JOB );
	}
}
