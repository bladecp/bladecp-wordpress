<?php
/**
 * Plugin bootstrap: hooks only.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin.
 */
final class Plugin {

	const CRON_HOOK = 'bladecp_migrator_tick';

	/**
	 * Registers hooks.
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
		if ( is_admin() ) {
			Admin_Page::register();
			Ajax::register();
		}
	}

	/**
	 * Loads the bundled translations (pt_BR, es_ES).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'bladecp-migrator', false, dirname( plugin_basename( BLADECP_MIGRATOR_FILE ) ) . '/languages' );
	}

	/**
	 * Background step when the page is closed.
	 */
	public static function cron_tick(): void {
		Job_Service::run_step();
	}

	/**
	 * Activation: nothing to set up until the person opens the page.
	 */
	public static function activate(): void {
	}

	/**
	 * Deactivation: stop the background tick (data stays for a later reactivation;
	 * uninstall removes everything).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
