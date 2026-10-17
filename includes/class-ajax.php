<?php
/**
 * AJAX endpoints driving the work while the page is open.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers.
 */
final class Ajax {

	const NONCE = 'bladecp_migrator_ajax';

	/**
	 * Registers the handlers (logged-in only).
	 */
	public static function register(): void {
		add_action( 'wp_ajax_bladecp_migrator_step', array( __CLASS__, 'step' ) );
		add_action( 'wp_ajax_bladecp_migrator_measure', array( __CLASS__, 'measure' ) );
		add_action( 'wp_ajax_bladecp_migrator_status', array( __CLASS__, 'status' ) );
	}

	/**
	 * Runs one job step and reports progress.
	 */
	public static function step(): void {
		self::guard();
		$result   = Job_Service::run_step();
		$progress = Job_Presenter::present( $result['job'], Job_Service::lock()->read(), time() );
		wp_send_json_success(
			array(
				'busy'     => $result['busy'],
				'progress' => $progress,
			)
		);
	}

	/**
	 * Runs one measuring step.
	 */
	public static function measure(): void {
		self::guard();
		$measure = Measure::step();
		wp_send_json_success(
			array(
				'done'  => ! empty( $measure['done'] ),
				'files' => number_format_i18n( (int) ( $measure['files'] ?? 0 ) ),
				'bytes' => Migration_Job::size( (int) ( $measure['bytes'] ?? 0 ) ),
			)
		);
	}

	/**
	 * Remote phase (for reloading the page when it changes).
	 */
	public static function status(): void {
		self::guard();
		$remote = Remote::migration();
		if ( null !== $remote['error'] ) {
			wp_send_json_error( array( 'message' => $remote['error']->user_message() ) );
		}
		wp_send_json_success( array( 'phase' => (string) ( $remote['data']['phase'] ?? '' ) ) );
	}

	/**
	 * Nonce and capability.
	 */
	private static function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( Config::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'bladecp-migrator' ) ), 403 );
		}
	}
}
