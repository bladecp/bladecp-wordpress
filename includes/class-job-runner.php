<?php
/**
 * Runs one locked, time-boxed job step (from AJAX or WP-Cron).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Job runner.
 */
final class Job_Runner {

	const LOCK_TTL = 120;

	/**
	 * Runs one step when the job is active and nobody else is running it.
	 *
	 * The job is saved only if it is still the stored job after the step: a
	 * reset or cancel done meanwhile wins, and this step's files are removed.
	 *
	 * @param Job_Store $store           Job storage.
	 * @param Lock      $lock            Lock.
	 * @param callable  $context_factory function( array $job, Lock $lock, string $token ): Job_Context.
	 * @param int       $seconds         Time budget.
	 * @return array{ran:bool,busy:bool,job:array|null}
	 */
	public static function run( Job_Store $store, Lock $lock, callable $context_factory, int $seconds ): array {
		$job = $store->load();
		if ( ! Migration_Job::is_active( $job ) ) {
			return array(
				'ran'  => false,
				'busy' => false,
				'job'  => $job,
			);
		}
		$token = $lock->acquire( self::LOCK_TTL );
		if ( null === $token ) {
			return array(
				'ran'  => false,
				'busy' => true,
				'job'  => $job,
			);
		}
		// A step killed by a fatal error (time limit) still releases the lock,
		// so the next step can redo the work from the last commit right away.
		$released = false;
		register_shutdown_function(
			static function () use ( $lock, $token, &$released ): void {
				if ( ! $released ) {
					$lock->release( $token );
				}
			}
		);
		try {
			$job = $store->load();
			if ( ! Migration_Job::is_active( $job ) ) {
				return array(
					'ran'  => false,
					'busy' => false,
					'job'  => $job,
				);
			}
			$ctx    = call_user_func( $context_factory, $job, $lock, $token );
			$result = Migration_Job::step( $job, $ctx, new Deadline( (float) $seconds ) );
			$latest = $store->load();
			if ( null === $latest || $latest['id'] !== $job['id'] ) {
				foreach ( (array) $job['files'] as $name ) {
					$ctx->work_dir->delete( (string) $name );
				}
				return array(
					'ran'  => true,
					'busy' => false,
					'job'  => $latest,
				);
			}
			$store->save( $result );
			return array(
				'ran'  => true,
				'busy' => false,
				'job'  => $result,
			);
		} finally {
			$lock->release( $token );
			$released = true;
		}
	}
}
