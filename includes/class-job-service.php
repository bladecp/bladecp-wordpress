<?php
/**
 * Wires the job with the real WordPress dependencies.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\Gzip_Member_Sink;
use BladeCP\Migrator\Package\Package_Layout;
use BladeCP\Migrator\Package\Wpdb_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Job service.
 */
final class Job_Service {

	/**
	 * Job storage.
	 */
	public static function store(): Job_Store {
		return new Option_Job_Store();
	}

	/**
	 * Lock.
	 */
	public static function lock(): Lock {
		return new Option_Lock();
	}

	/**
	 * Authenticated API client, or null when not connected.
	 */
	public static function api(): ?Api_Client {
		if ( ! Connection::is_connected() ) {
			return null;
		}
		$key = Connection::api_key( Crypto::for_site() );
		if ( null === $key ) {
			return null;
		}
		return new Api_Client( new Wp_Http_Transport(), Config::api_base(), $key, self::language() );
	}

	/**
	 * Client for the public token exchange.
	 */
	public static function public_api(): Api_Client {
		return new Api_Client( new Wp_Http_Transport(), Config::api_base(), null, self::language() );
	}

	/**
	 * Accept-Language from the admin locale (pt_BR becomes pt-BR).
	 */
	public static function language(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return str_replace( '_', '-', (string) $locale );
	}

	/**
	 * Builds the context for one step.
	 */
	public static function context_factory(): callable {
		return static function ( array $job, Lock $lock, string $token ): Job_Context {
			global $wpdb;
			$work_dir        = Work_Dir::for_site();
			$ctx             = new Job_Context();
			$ctx->work_dir   = $work_dir;
			$ctx->site_root  = Site::root();
			$ctx->excluded   = Site::excluded_paths( $work_dir );
			$ctx->db         = new Wpdb_Source( $wpdb );
			$ctx->api        = self::api();
			$part_size       = is_array( $job['upload'] ) && (int) $job['upload']['part_size'] > 0 ? (int) $job['upload']['part_size'] : Part_Putter_Factory::DEFAULT_PART_BYTES;
			$ctx->putter     = Part_Putter_Factory::create( $part_size );
			$ctx->clock      = 'time';
			$ctx->free_space = array( Environment::class, 'free_space' );
			$last            = 0;
			$ctx->heartbeat  = static function ( int $bytes ) use ( $lock, $token, &$last ): void {
				$now = time();
				if ( $now - $last >= 5 ) {
					$last = $now;
					$lock->refresh(
						$token,
						Job_Runner::LOCK_TTL,
						array(
							'part_bytes' => $bytes,
							'at'         => $now,
						)
					);
				}
			};
			return $ctx;
		};
	}

	/**
	 * Runs one step now (AJAX or cron) and keeps the cron fallback scheduled.
	 */
	public static function run_step(): array {
		Environment::prepare_long_step();
		$seconds = Environment::step_seconds( Environment::max_execution_time() );
		$result  = Job_Runner::run( self::store(), self::lock(), self::context_factory(), $seconds );
		self::schedule_if_active( $result['job'] );
		if ( ! Migration_Job::is_active( $result['job'] ) ) {
			wp_clear_scheduled_hook( Plugin::CRON_HOOK );
		}
		if ( $result['ran'] && is_array( $result['job'] ) && Migration_Job::STAGE_DONE === $result['job']['stage'] ) {
			// The remote phase just moved past "uploading": never show a stale one.
			Remote::forget();
		}
		return $result;
	}

	/**
	 * Schedules the background tick while the job is active.
	 *
	 * @param array|null $job Job.
	 */
	public static function schedule_if_active( ?array $job ): void {
		if ( Migration_Job::is_active( $job ) && false === wp_next_scheduled( Plugin::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, Plugin::CRON_HOOK );
		}
	}

	/**
	 * Creates and stores a new job for the connected migration.
	 *
	 * @param string $migration_uuid Migration.
	 * @return string[] Problems (empty on success).
	 */
	public static function start( string $migration_uuid ): array {
		$facts    = Site::package_facts();
		$problems = Package_Layout::validate_facts( $facts );
		if ( ! empty( $problems ) ) {
			return $problems;
		}
		self::discard();
		$job = Migration_Job::create( $facts, $migration_uuid, Gzip_Member_Sink::is_available(), time() );
		self::store()->save( $job );
		self::schedule_if_active( $job );
		return array();
	}

	/**
	 * Deletes the job and every file it created.
	 */
	public static function discard(): void {
		$job = self::store()->load();
		self::store()->clear();
		$work_dir = Work_Dir::for_site();
		if ( null !== $job && isset( $job['files'] ) ) {
			foreach ( (array) $job['files'] as $name ) {
				$work_dir->delete( (string) $name );
			}
		}
		wp_clear_scheduled_hook( Plugin::CRON_HOOK );
	}
}
