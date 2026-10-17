<?php
/**
 * The local migration job: build the package in resumable steps, upload it,
 * confirm it. Plain array state, persisted between steps.
 *
 * Stages: scanning -> dumping -> packing -> uploading -> done. Any stage can
 * end in "failed" (with the stage to resume from). Transient problems
 * (network, BladeCP busy) keep the stage and wait with backoff.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\File_Scanner;
use BladeCP\Migrator\Package\Package_Layout;
use BladeCP\Migrator\Package\Package_Packer;
use BladeCP\Migrator\Package\Sql_Dumper;

defined( 'ABSPATH' ) || exit;

/**
 * Migration job.
 */
final class Migration_Job {

	const STAGE_SCANNING  = 'scanning';
	const STAGE_DUMPING   = 'dumping';
	const STAGE_PACKING   = 'packing';
	const STAGE_UPLOADING = 'uploading';
	const STAGE_DONE      = 'done';
	const STAGE_FAILED    = 'failed';

	const MAX_FAILURES     = 8;
	const MAX_UPLOAD_BYTES = 53687091200;

	/**
	 * Remote phases that mean the package was already received.
	 */
	const RECEIVED_PHASES = array( 'queued', 'parsing', 'discovery_review', 'provisioning', 'importing', 'validating', 'awaiting_approval', 'syncing', 'cutover_pending', 'live', 'cutover_stalled' );

	/**
	 * New job.
	 *
	 * @param array  $facts          Site facts (Site::package_facts()).
	 * @param string $migration_uuid Migration.
	 * @param bool   $gzip           Compress the package.
	 * @param int    $now            Current time.
	 */
	public static function create( array $facts, string $migration_uuid, bool $gzip, int $now ): array {
		$id                    = bin2hex( random_bytes( 8 ) );
		$facts['generated_at'] = $now;
		return array(
			'id'             => $id,
			'version'        => 1,
			'stage'          => self::STAGE_SCANNING,
			'failed_stage'   => '',
			'migration_uuid' => strtolower( $migration_uuid ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'gzip'           => $gzip,
			'facts'          => $facts,
			'files'          => array(
				'queue'   => 'queue-' . $id . '.txt',
				'list'    => 'files-' . $id . '.txt',
				'dump'    => 'database-' . $id . '.sql',
				'package' => 'package-' . $id . ( $gzip ? '.tar.gz' : '.tar' ),
			),
			'scan'           => File_Scanner::initial_state(),
			'dump'           => Sql_Dumper::initial_state(),
			'pack'           => Package_Packer::initial_state(),
			'package_size'   => 0,
			'upload'         => null,
			'error'          => null,
			'failures'       => 0,
			'retry_at'       => 0,
			'reconnect'      => false,
		);
	}

	/**
	 * Whether the job still has work to do.
	 *
	 * @param array|null $job Job.
	 */
	public static function is_active( ?array $job ): bool {
		return null !== $job && in_array( $job['stage'], array( self::STAGE_SCANNING, self::STAGE_DUMPING, self::STAGE_PACKING, self::STAGE_UPLOADING ), true );
	}

	/**
	 * Runs one step.
	 *
	 * @param array       $job      Job.
	 * @param Job_Context $ctx      Dependencies.
	 * @param Deadline    $deadline Budget.
	 */
	public static function step( array $job, Job_Context $ctx, Deadline $deadline ): array {
		$now = $ctx->now();
		if ( ! self::is_active( $job ) || (int) $job['retry_at'] > $now ) {
			return $job;
		}
		try {
			switch ( $job['stage'] ) {
				case self::STAGE_SCANNING:
					$job = self::scan( $job, $ctx, $deadline );
					break;
				case self::STAGE_DUMPING:
					$job = self::dump( $job, $ctx, $deadline );
					break;
				case self::STAGE_PACKING:
					$job = self::pack( $job, $ctx, $deadline );
					break;
				case self::STAGE_UPLOADING:
					$job = self::upload( $job, $ctx, $deadline );
					break;
			}
			if ( self::STAGE_FAILED !== $job['stage'] && ! self::is_waiting( $job, $now ) ) {
				$job['error']    = null;
				$job['failures'] = 0;
			}
		} catch ( Api_Error $e ) {
			$job = self::api_failure( $job, $e, $now );
		} catch ( \Throwable $e ) {
			$job = self::fail( $job, self::local_message( $e->getMessage() ), $now );
		}
		$job['updated_at'] = $now;
		return $job;
	}

	/**
	 * Resumes a failed job from the stage it stopped in.
	 *
	 * @param array $job Job.
	 */
	public static function resume( array $job ): array {
		if ( self::STAGE_FAILED === $job['stage'] && '' !== $job['failed_stage'] ) {
			$job['stage'] = $job['failed_stage'];
		}
		$job['failed_stage'] = '';
		$job['error']        = null;
		$job['failures']     = 0;
		$job['retry_at']     = 0;
		$job['reconnect']    = false;
		return $job;
	}

	/**
	 * Points the job to another migration (after connecting again): the
	 * built package is kept, the upload starts over for the new migration.
	 *
	 * @param array  $job  Job.
	 * @param string $uuid Migration.
	 */
	public static function adopt_migration( array $job, string $uuid ): array {
		$uuid = strtolower( $uuid );
		if ( $job['migration_uuid'] === $uuid ) {
			return $job['reconnect'] ? self::resume( $job ) : $job;
		}
		$job['migration_uuid'] = $uuid;
		if ( is_array( $job['upload'] ) ) {
			$job['upload'] = Upload_Session::fresh( (int) $job['package_size'] );
		}
		if ( self::STAGE_DONE === $job['stage'] ) {
			return $job;
		}
		return self::resume( $job );
	}

	/**
	 * Files listing.
	 *
	 * @param array       $job      Job.
	 * @param Job_Context $ctx      Dependencies.
	 * @param Deadline    $deadline Budget.
	 */
	private static function scan( array $job, Job_Context $ctx, Deadline $deadline ): array {
		$ctx->work_dir->ensure();
		$scanner     = new File_Scanner( $ctx->site_root, $ctx->work_dir->file( $job['files']['queue'] ), $ctx->work_dir->file( $job['files']['list'] ), $ctx->excluded );
		$job['scan'] = $scanner->step( $job['scan'], $deadline );
		if ( empty( $job['scan']['done'] ) ) {
			return $job;
		}
		$job['facts']['files']    = (int) $job['scan']['files'];
		$job['facts']['bytes']    = (int) $job['scan']['bytes'];
		$job['facts']['excluded'] = array_values( $ctx->excluded );
		$ctx->work_dir->delete( $job['files']['queue'] );
		$needed = (int) $job['scan']['bytes'] + (int) ( $job['facts']['db_estimate'] ?? 0 );
		$free   = null === $ctx->free_space ? null : call_user_func( $ctx->free_space, $ctx->work_dir->path() );
		if ( null !== $free && $free < $needed ) {
			return self::fail(
				$job,
				sprintf(
					/* translators: 1: free disk space, 2: space needed. */
					__( 'Not enough free disk space to build the package: %1$s free, about %2$s needed. Free some space or ask your host for more, then try again.', 'bladecp-migrator' ),
					self::size( (int) $free ),
					self::size( $needed )
				),
				$ctx->now()
			);
		}
		$job['stage'] = self::STAGE_DUMPING;
		return $job;
	}

	/**
	 * Database dump.
	 *
	 * @param array       $job      Job.
	 * @param Job_Context $ctx      Dependencies.
	 * @param Deadline    $deadline Budget.
	 */
	private static function dump( array $job, Job_Context $ctx, Deadline $deadline ): array {
		$facts  = $job['facts'];
		$dumper = new Sql_Dumper(
			$ctx->db,
			$ctx->work_dir->file( $job['files']['dump'] ),
			array_merge(
				array(
					'prefix'         => (string) $facts['table_prefix'],
					'database'       => (string) $facts['db_name'],
					'charset'        => (string) $facts['db_charset'],
					'plugin_version' => (string) $facts['plugin_version'],
					'now'            => (int) $facts['generated_at'],
					'row_filter'     => self::own_rows_filter( (string) $facts['table_prefix'] ),
				),
				$ctx->dump_options
			)
		);
		$job['dump'] = $dumper->step( $job['dump'], $deadline );
		if ( empty( $job['dump']['done'] ) ) {
			return $job;
		}
		$job['facts']['db_engine']  = Sql_Dumper::engine( (string) $job['dump']['server_version'] );
		$job['facts']['db_version'] = Sql_Dumper::major_minor( (string) $job['dump']['server_version'] );
		$job['facts']['tables']     = count( (array) $job['dump']['tables'] );
		$job['facts']['dump_bytes'] = (int) $job['dump']['size'];
		$job['stage']               = self::STAGE_PACKING;
		return $job;
	}

	/**
	 * Package writing.
	 *
	 * @param array       $job      Job.
	 * @param Job_Context $ctx      Dependencies.
	 * @param Deadline    $deadline Budget.
	 */
	private static function pack( array $job, Job_Context $ctx, Deadline $deadline ): array {
		$packer      = new Package_Packer(
			$job['facts'],
			$ctx->site_root,
			$ctx->work_dir->file( $job['files']['list'] ),
			$ctx->work_dir->file( $job['files']['dump'] ),
			$ctx->work_dir->file( $job['files']['package'] ),
			(bool) $job['gzip']
		);
		$job['pack'] = $packer->step( $job['pack'], $deadline );
		if ( empty( $job['pack']['done'] ) ) {
			return $job;
		}
		$job['package_size'] = (int) $job['pack']['size'];
		$ctx->work_dir->delete( $job['files']['list'] );
		$ctx->work_dir->delete( $job['files']['dump'] );
		if ( $job['package_size'] > self::MAX_UPLOAD_BYTES ) {
			$ctx->work_dir->delete( $job['files']['package'] );
			return self::fail( $job, __( 'The site package is larger than the 50 GB limit.', 'bladecp-migrator' ), $ctx->now() );
		}
		$job['upload'] = Upload_Session::fresh( $job['package_size'] );
		$job['stage']  = self::STAGE_UPLOADING;
		return $job;
	}

	/**
	 * Upload, complete and confirm.
	 *
	 * @param array       $job      Job.
	 * @param Job_Context $ctx      Dependencies.
	 * @param Deadline    $deadline Budget.
	 * @throws Api_Error When BladeCP refuses.
	 */
	private static function upload( array $job, Job_Context $ctx, Deadline $deadline ): array {
		if ( null === $ctx->api ) {
			throw new Api_Error( Api_Error::KIND_AUTH, 401, 'NOT_CONNECTED' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
		}
		$api     = $ctx->api;
		$uuid    = (string) $job['migration_uuid'];
		$session = $job['upload'];
		$now     = $ctx->now();
		$file    = $ctx->work_dir->file( $job['files']['package'] );

		if ( ! empty( $session['completed'] ) ) {
			$api->confirm_upload( $uuid );
			return self::finish( $job, $ctx );
		}

		if ( Upload_Session::all_parts_sent( $session ) ) {
			try {
				$api->complete_upload( $uuid, Upload_Session::complete_payload( $session ) );
			} catch ( Api_Error $e ) {
				if ( Api_Error::KIND_INVALID_REQUEST === $e->kind() && (int) $session['resets'] < 2 ) {
					// Stale or broken multipart upload: start the upload again.
					$job['upload'] = Upload_Session::reset( $session );
					return $job;
				}
				throw $e;
			}
			$session['completed'] = true;
			$job['upload']        = $session;
			if ( ! $deadline->reached() ) {
				$api->confirm_upload( $uuid );
				return self::finish( $job, $ctx );
			}
			return $job;
		}

		if ( Upload_Session::needs_urls( $session, $now ) ) {
			clearstatcache( true, $file );
			if ( ! is_file( $file ) || filesize( $file ) !== (int) $job['package_size'] ) {
				return self::fail( $job, __( 'The local package is missing or changed. Start the migration again.', 'bladecp-migrator' ), $now );
			}
			try {
				$data = $api->request_upload_urls( $uuid, (int) $job['package_size'], Package_Layout::package_filename( (string) $job['facts']['user'], (bool) $job['gzip'] ), true );
			} catch ( Api_Error $e ) {
				if ( Api_Error::KIND_CONFLICT === $e->kind() ) {
					$remote = $api->get_migration( $uuid );
					if ( in_array( (string) ( $remote['phase'] ?? '' ), self::RECEIVED_PHASES, true ) ) {
						return self::finish( $job, $ctx );
					}
				}
				throw $e;
			}
			try {
				$job['upload'] = Upload_Session::apply_api_response( $session, $data, $now );
			} catch ( \UnexpectedValueException $e ) {
				throw Api_Error::invalid_response( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: a fixed kind, an integer status and a code limited to [A-Za-z0-9_.-]; the screen shows Api_Error::user_message() escaped.
			}
			return $job;
		}

		if ( null === $ctx->putter ) {
			return self::fail( $job, __( 'This server cannot upload the package: the PHP cURL extension is missing and the memory limit is too low. Ask your host to enable cURL.', 'bladecp-migrator' ), $now );
		}
		$part = Upload_Session::next_pending_part( $session );
		if ( null === $part ) {
			return $job;
		}
		list( $offset, $length ) = Upload_Session::part_range( $part, (int) $session['part_size'], (int) $session['size'] );
		$result                  = $ctx->putter->put( (string) $session['urls'][ $part ], $file, $offset, $length, $ctx->heartbeat );
		if ( $result['status'] >= 200 && $result['status'] < 300 && '' !== $result['etag'] ) {
			$job['upload'] = Upload_Session::record_etag( $session, $part, $result['etag'] );
			return $job;
		}
		if ( in_array( (int) $result['status'], array( 400, 403 ), true ) ) {
			// Expired or rejected signature: ask BladeCP for fresh URLs.
			$job['upload'] = Upload_Session::drop_urls( $session );
		}
		return self::transient( $job, __( 'Uploading a part of the package failed. The plugin will try again.', 'bladecp-migrator' ), $now );
	}

	/**
	 * Upload confirmed: remove local files.
	 *
	 * @param array       $job Job.
	 * @param Job_Context $ctx Dependencies.
	 */
	private static function finish( array $job, Job_Context $ctx ): array {
		foreach ( $job['files'] as $name ) {
			$ctx->work_dir->delete( (string) $name );
		}
		if ( is_array( $job['upload'] ) ) {
			$job['upload']['completed'] = true;
			$job['upload']['confirmed'] = true;
		}
		$job['stage'] = self::STAGE_DONE;
		$job['error'] = null;
		return $job;
	}

	/**
	 * Drops the plugin's own options (and transients) from the dump: the key
	 * and the job state must not travel to the new site.
	 *
	 * @param string $prefix Table prefix.
	 */
	public static function own_rows_filter( string $prefix ): callable {
		$options_table = $prefix . 'options';
		return static function ( string $table, array $columns, array $row ) use ( $options_table ): bool {
			if ( $table !== $options_table ) {
				return true;
			}
			$index = array_search( 'option_name', $columns, true );
			if ( false === $index || ! isset( $row[ $index ] ) ) {
				return true;
			}
			return 1 !== preg_match( '/^(_transient_(timeout_)?)?bladecp_migrator_/', (string) $row[ $index ] );
		};
	}

	/**
	 * Whether a transient error is waiting for its retry time.
	 *
	 * @param array $job Job.
	 * @param int   $now Now.
	 */
	public static function is_waiting( array $job, int $now ): bool {
		return (int) $job['retry_at'] > $now;
	}

	/**
	 * Maps an API error to a transient wait or a failure.
	 *
	 * @param array     $job Job.
	 * @param Api_Error $e   Error.
	 * @param int       $now Now.
	 */
	private static function api_failure( array $job, Api_Error $e, int $now ): array {
		if ( $e->is_retryable() ) {
			return self::transient( $job, $e->user_message(), $now, $e->retry_after() );
		}
		$job              = self::fail( $job, $e->user_message(), $now );
		$job['reconnect'] = $e->requires_reconnect() || Api_Error::KIND_NOT_FOUND === $e->kind() || Api_Error::KIND_BOUND === $e->kind();
		return $job;
	}

	/**
	 * Records a transient problem and schedules the retry (exponential
	 * backoff, at most 5 minutes). Too many in a row becomes a failure.
	 *
	 * @param array  $job     Job.
	 * @param string $message Message.
	 * @param int    $now     Now.
	 * @param int    $base    Base delay.
	 */
	private static function transient( array $job, string $message, int $now, int $base = 15 ): array {
		$job['failures'] = (int) $job['failures'] + 1;
		if ( $job['failures'] > self::MAX_FAILURES ) {
			return self::fail( $job, $message, $now );
		}
		$job['error']    = array(
			'message'   => $message,
			'retryable' => true,
		);
		$job['retry_at'] = $now + (int) min( 300, $base * pow( 2, $job['failures'] - 1 ) );
		return $job;
	}

	/**
	 * Stops the job with a message; "Try again" resumes the same stage.
	 *
	 * @param array  $job     Job.
	 * @param string $message Message.
	 * @param int    $now     Now.
	 */
	private static function fail( array $job, string $message, int $now ): array {
		if ( self::STAGE_FAILED !== $job['stage'] ) {
			$job['failed_stage'] = $job['stage'];
		}
		$job['stage']      = self::STAGE_FAILED;
		$job['error']      = array(
			'message'   => $message,
			'retryable' => false,
		);
		$job['retry_at']   = 0;
		$job['updated_at'] = $now;
		return $job;
	}

	/**
	 * Plain-language message for a local failure code.
	 *
	 * @param string $code Exception message.
	 */
	public static function local_message( string $code ): string {
		switch ( $code ) {
			case 'work_dir_unwritable':
			case 'work_file_unwritable':
				return __( 'The plugin cannot write to the uploads folder. Check its permissions and free space, then try again.', 'bladecp-migrator' );
			case 'disk_write_failed':
				return __( 'Writing the package failed. The disk may be full. Free some space, then try again.', 'bladecp-migrator' );
			case 'database_error':
				return __( 'Reading the database failed. Try again, and contact your host if it keeps happening.', 'bladecp-migrator' );
			case 'dump_missing':
				return __( 'The database export is missing. Start the migration again.', 'bladecp-migrator' );
			case 'deflate_init_failed':
			case 'deflate_failed':
				return __( 'Compressing the package failed. Try again.', 'bladecp-migrator' );
		}
		return __( 'Something went wrong while preparing the package. Try again.', 'bladecp-migrator' );
	}

	/**
	 * Human size.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function size( int $bytes ): string {
		if ( function_exists( 'size_format' ) ) {
			return (string) size_format( $bytes, 1 );
		}
		return $bytes . ' B';
	}
}
