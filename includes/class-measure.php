<?php
/**
 * Preflight measurement of the site files (count and size), in the same
 * resumable steps as the package scan but without writing a file list.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\File_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Files measurement.
 */
final class Measure {

	const QUEUE_FILE = 'measure-queue.txt';
	const BUSY       = 'bladecp_migrator_measuring';

	/**
	 * Stored measurement, if any.
	 */
	public static function get(): ?array {
		return Options::get_array( Options::MEASURE );
	}

	/**
	 * Whether a fresh, finished measurement exists.
	 *
	 * @param array|null $measure Measurement.
	 * @param int        $now     Now.
	 */
	public static function is_fresh( ?array $measure, int $now ): bool {
		return is_array( $measure ) && ! empty( $measure['done'] ) && ( $now - (int) ( $measure['at'] ?? 0 ) ) < Site::MEASURE_TTL;
	}

	/**
	 * Runs one measuring step.
	 */
	public static function step(): array {
		$measure = self::get();
		$now     = time();
		if ( self::is_fresh( $measure, $now ) ) {
			return $measure;
		}
		if ( false !== get_transient( self::BUSY ) ) {
			return is_array( $measure ) ? $measure : array( 'done' => false );
		}
		set_transient( self::BUSY, 1, 60 );
		try {
			if ( ! is_array( $measure ) || ! empty( $measure['done'] ) || ! isset( $measure['scan'] ) ) {
				$measure = array(
					'done' => false,
					'at'   => 0,
					'scan' => File_Scanner::initial_state(),
				);
			}
			Environment::prepare_long_step();
			$work_dir = Work_Dir::for_site();
			$work_dir->ensure();
			$scanner         = new File_Scanner( Site::root(), $work_dir->file( self::QUEUE_FILE ), null, Site::excluded_paths( $work_dir ) );
			$measure['scan'] = $scanner->step( $measure['scan'], new Deadline( (float) Environment::step_seconds( Environment::max_execution_time() ) ) );
			$measure         = self::summarize( $measure );
			if ( ! empty( $measure['done'] ) ) {
				$measure['at'] = time();
				$work_dir->delete( self::QUEUE_FILE );
			}
			Options::set( Options::MEASURE, $measure );
		} finally {
			delete_transient( self::BUSY );
		}
		return $measure;
	}

	/**
	 * Copies the scanner totals and the sample of skipped entries to the top
	 * level (a walk saved before the sample existed may have none yet).
	 *
	 * @param array $measure Measurement.
	 */
	private static function summarize( array $measure ): array {
		$scan                      = $measure['scan'];
		$measure['done']           = ! empty( $scan['done'] );
		$measure['files']          = (int) $scan['files'];
		$measure['bytes']          = (int) $scan['bytes'];
		$measure['symlinks']       = (int) $scan['symlinks'];
		$measure['unreadable']     = (int) $scan['unreadable'];
		$measure['skipped_sample'] = isset( $scan['sample'] ) && is_array( $scan['sample'] ) ? $scan['sample'] : array();
		return $measure;
	}

	/**
	 * Forgets the measurement.
	 */
	public static function reset(): void {
		delete_option( Options::MEASURE );
		delete_transient( self::BUSY );
	}
}
