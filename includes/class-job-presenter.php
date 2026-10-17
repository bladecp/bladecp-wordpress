<?php
/**
 * Local job progress in plain language (page render and AJAX).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Job presenter.
 */
final class Job_Presenter {

	/**
	 * Progress summary.
	 *
	 * @param array|null $job  Job.
	 * @param array|null $lock Lock holder (upload progress details).
	 * @param int        $now  Now.
	 * @return array{stage:string,active:bool,percent:int,title:string,detail:string,error:string,waiting:bool,journey:int|null}
	 */
	public static function present( ?array $job, ?array $lock, int $now ): array {
		$out = array(
			'stage'   => null === $job ? 'none' : (string) $job['stage'],
			'active'  => Migration_Job::is_active( $job ),
			'percent' => 0,
			'title'   => '',
			'detail'  => '',
			'error'   => '',
			'waiting' => false,
			'journey' => null,
		);
		if ( null === $job ) {
			return $out;
		}
		// Stage of the 7-stage trail: building is 0, sending is 1, a failure keeps its stage.
		$out['journey'] = Phases::local_stage( (string) $job['stage'], (string) ( $job['failed_stage'] ?? '' ) );
		if ( is_array( $job['error'] ) ) {
			$out['error'] = (string) $job['error']['message'];
		}
		$out['waiting'] = Migration_Job::is_waiting( $job, $now );
		$facts          = $job['facts'];
		switch ( $job['stage'] ) {
			case Migration_Job::STAGE_SCANNING:
				$out['percent'] = 2;
				$out['title']   = __( 'Listing your site files', 'bladecp-migrator' );
				$out['detail']  = sprintf(
					/* translators: %s: number of files. */
					__( '%s files found so far', 'bladecp-migrator' ),
					self::number( (int) $job['scan']['files'] )
				);
				break;
			case Migration_Job::STAGE_DUMPING:
				$total          = max( 1, count( (array) $job['dump']['tables'] ) );
				$done           = min( $total, (int) $job['dump']['table_index'] );
				$out['percent'] = 5 + (int) floor( 15 * $done / $total );
				$out['title']   = __( 'Exporting your database', 'bladecp-migrator' );
				$out['detail']  = sprintf(
					/* translators: 1: tables done, 2: total tables. */
					__( 'Table %1$s of %2$s', 'bladecp-migrator' ),
					self::number( min( $total, $done + 1 ) ),
					self::number( $total )
				);
				break;
			case Migration_Job::STAGE_PACKING:
				$expected       = max( 1, (int) $facts['bytes'] + (int) $facts['dump_bytes'] );
				$written        = (int) $job['pack']['tar_bytes'];
				$out['percent'] = 20 + (int) floor( 40 * min( 1, $written / $expected ) );
				$out['title']   = __( 'Building the package', 'bladecp-migrator' );
				$out['detail']  = sprintf(
					/* translators: 1: bytes packed, 2: total bytes. */
					__( '%1$s of %2$s packed', 'bladecp-migrator' ),
					Migration_Job::size( min( $written, $expected ) ),
					Migration_Job::size( $expected )
				);
				break;
			case Migration_Job::STAGE_UPLOADING:
				$session = (array) $job['upload'];
				if ( is_array( $lock ) && isset( $lock['extra']['part_bytes'] ) ) {
					$session['part_progress'] = (int) $lock['extra']['part_bytes'];
				}
				$size           = max( 1, (int) $job['package_size'] );
				$sent           = isset( $session['etags'] ) ? Upload_Session::uploaded_bytes( $session ) : 0;
				$out['percent'] = 60 + (int) floor( 39 * min( 1, $sent / $size ) );
				if ( ! empty( $session['completed'] ) ) {
					$out['title']  = __( 'Confirming with BladeCP', 'bladecp-migrator' );
					$out['detail'] = '';
				} else {
					$out['title']  = __( 'Sending the package to BladeCP', 'bladecp-migrator' );
					$out['detail'] = sprintf(
						/* translators: 1: bytes sent, 2: package size. */
						__( '%1$s of %2$s sent', 'bladecp-migrator' ),
						Migration_Job::size( min( $sent, $size ) ),
						Migration_Job::size( $size )
					);
				}
				break;
			case Migration_Job::STAGE_DONE:
				$out['percent'] = 100;
				$out['title']   = __( 'Package sent to BladeCP', 'bladecp-migrator' );
				break;
			case Migration_Job::STAGE_FAILED:
				$out['title'] = __( 'The package could not be finished', 'bladecp-migrator' );
				break;
		}
		if ( $out['waiting'] ) {
			$out['detail'] = sprintf(
				/* translators: %d: seconds. */
				_n( 'Trying again in %d second.', 'Trying again in %d seconds.', max( 1, (int) $job['retry_at'] - $now ), 'bladecp-migrator' ),
				max( 1, (int) $job['retry_at'] - $now )
			);
		}
		return $out;
	}

	/**
	 * Localized number.
	 *
	 * @param int $n Number.
	 */
	private static function number( int $n ): string {
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $n ) : (string) $n;
	}
}
