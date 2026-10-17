<?php
/**
 * Tools > BladeCP Migrator: preflight, connection, migration.
 *
 * Every state change is a POST to admin-post.php with a nonce and a
 * manage_options check, followed by a redirect back to the page
 * (post/redirect/get). The Hub callback (code + state) is handled before
 * any output, on the page's load hook.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\Package_Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
final class Admin_Page {

	const FLASH           = 'bladecp_migrator_flash_';
	const ACTIONS         = array( 'connect', 'disconnect', 'start', 'stop', 'resume', 'review', 'approve', 'cancel', 'retry', 'remeasure' );
	const CONNECT_NOTE_ID = 'bcpm-connect-blocked';

	/**
	 * Icon of each preflight check (Icons::PATHS key), by check id.
	 */
	const CHECK_ICONS = array(
		'wordpress'   => 'app-window',
		'php'         => 'code',
		'site_type'   => 'layers',
		'address'     => 'globe',
		'https'       => 'lock',
		'php64'       => 'cpu',
		'encryption'  => 'shield-check',
		'upload'      => 'cloud-upload',
		'compression' => 'archive',
		'limits'      => 'gauge',
		'files'       => 'folder',
		'database'    => 'database',
		'disk'        => 'hard-drive',
		'background'  => 'clock',
	);

	/**
	 * Icon of the situation shown for each remote phase.
	 */
	const PHASE_ICONS = array(
		'draft'             => 'package',
		'queued'            => 'hourglass',
		'discovery_review'  => 'scan-search',
		'awaiting_approval' => 'eye',
		'failed'            => 'circle-x',
		'cancelled'         => 'ban',
	);

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Registers hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_bladecp_migrator_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( BLADECP_MIGRATOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds Tools > BladeCP Migrator.
	 */
	public static function add_menu(): void {
		$hook = add_management_page(
			__( 'BladeCP Migrator', 'bladecp-migrator' ),
			__( 'BladeCP Migrator', 'bladecp-migrator' ),
			Config::CAPABILITY,
			Config::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
			add_action( 'load-' . $hook, array( __CLASS__, 'on_load' ) );
		}
	}

	/**
	 * "Migrate to BladeCP" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Migrate to BladeCP', 'bladecp-migrator' ) . '</a>' );
		return $links;
	}

	/**
	 * Page URL.
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . Config::PAGE_SLUG );
	}

	/**
	 * Scripts and styles on this page only.
	 *
	 * @param string $hook_suffix Current page.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		$base = plugins_url( '', BLADECP_MIGRATOR_FILE );
		wp_enqueue_style( 'bladecp-migrator', $base . '/assets/css/admin.css', array(), BLADECP_MIGRATOR_VERSION );
		wp_enqueue_script( 'bladecp-migrator', $base . '/assets/js/admin.js', array(), BLADECP_MIGRATOR_VERSION, true );
		wp_localize_script(
			'bladecp-migrator',
			'bladecpMigrator',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					'measuring' => __( 'Measuring your site files...', 'bladecp-migrator' ),
					/* translators: 1: number of files, 2: size so far. */
					'measured'  => __( '%1$s files, %2$s so far', 'bladecp-migrator' ),
					'offline'   => __( 'Lost contact with your site. Retrying...', 'bladecp-migrator' ),
				),
			)
		);
	}

	/**
	 * Before output: handles the Hub callback.
	 */
	public static function on_load(): void {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth-style callback: the single-use per-user state is the CSRF check.
		if ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) {
			return;
		}
		$state = self::token_param( 'state' );
		$code  = self::token_param( 'code' );
		$error = self::token_param( 'error' );
		// phpcs:enable
		self::handle_callback( $state, $code, $error );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Validates the state and exchanges the code for the key.
	 *
	 * @param string $state State.
	 * @param string $code  Code.
	 * @param string $error Error code from the Hub.
	 */
	public static function handle_callback( string $state, string $code, string $error ): void {
		$outcome = Connect_Flow::finish( get_current_user_id(), $state, $code, $error, Job_Service::public_api(), Crypto::for_site(), Config::api_base(), time() );
		if ( $outcome['connected'] ) {
			Remote::forget();
			$store = Job_Service::store();
			$job   = $store->load();
			$uuid  = Connection::migration_uuid();
			if ( null !== $job && null !== $uuid ) {
				$job = Migration_Job::adopt_migration( $job, $uuid );
				$store->save( $job );
				Job_Service::schedule_if_active( $job );
			}
		}
		self::flash( $outcome['type'], $outcome['message'] );
	}

	/**
	 * Starts the connection: redirects to the Hub.
	 */
	public static function handle_connect(): void {
		self::guard( 'connect' );
		$redirect_uri = Site::redirect_uri();
		if ( null === $redirect_uri ) {
			self::done( 'error', __( 'BladeCP can only connect to a WordPress dashboard served over HTTPS.', 'bladecp-migrator' ) );
		}
		if ( ! Crypto::is_available() ) {
			self::done( 'error', __( 'PHP has neither OpenSSL with AES-256-GCM nor sodium, so the BladeCP connection could not be stored safely. Ask your host to enable one of them.', 'bladecp-migrator' ) );
		}
		$url = Connect_Flow::begin( get_current_user_id(), Package_Layout::normalize_domain( Site::host() ), (string) $redirect_uri, time() );
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed BladeCP Hub host (Config::hub_base()).
		exit;
	}

	/**
	 * Removes the key and the local package.
	 */
	public static function handle_disconnect(): void {
		self::guard( 'disconnect' );
		Job_Service::discard();
		Connection::forget();
		Remote::forget();
		self::done( 'success', __( 'Disconnected. The BladeCP key was removed from this site.', 'bladecp-migrator' ) );
	}

	/**
	 * One click: builds and sends the package.
	 */
	public static function handle_start(): void {
		self::guard( 'start' );
		$uuid = Connection::migration_uuid();
		if ( null === $uuid ) {
			self::done( 'error', __( 'Connect to BladeCP first.', 'bladecp-migrator' ) );
		}
		$checks = Preflight::evaluate( Site::preflight_facts( Work_Dir::for_site(), Measure::get() ) );
		if ( Preflight::has_blockers( $checks ) ) {
			self::done( 'error', __( 'Fix the problems listed under "Check your site" first.', 'bladecp-migrator' ) );
		}
		$remote = Remote::migration( true );
		if ( null !== $remote['error'] ) {
			self::done( 'error', $remote['error']->user_message() );
		}
		if ( ! Phases::accepts_package( (string) ( $remote['data']['phase'] ?? '' ) ) ) {
			self::done( 'error', __( 'This migration no longer accepts a new package. Open it in BladeCP to see its current state.', 'bladecp-migrator' ) );
		}
		$problems = Job_Service::start( (string) $uuid );
		if ( ! empty( $problems ) ) {
			self::done( 'error', __( 'Some site settings cannot be migrated. See "Check your site".', 'bladecp-migrator' ) );
		}
		self::done( 'success', __( 'Migration started. Keep this page open for the fastest progress.', 'bladecp-migrator' ) );
	}

	/**
	 * Stops the local work and deletes the package.
	 */
	public static function handle_stop(): void {
		self::guard( 'stop' );
		Job_Service::discard();
		self::done( 'success', __( 'Stopped. The local package was deleted. Your BladeCP migration was not cancelled.', 'bladecp-migrator' ) );
	}

	/**
	 * Resumes a stopped job from where it failed.
	 */
	public static function handle_resume(): void {
		self::guard( 'resume' );
		$store = Job_Service::store();
		$job   = $store->load();
		if ( null !== $job ) {
			$job = Migration_Job::resume( $job );
			$store->save( $job );
			Job_Service::schedule_if_active( $job );
		}
		self::done( 'success', __( 'Trying again.', 'bladecp-migrator' ) );
	}

	/**
	 * Confirms the primary domain (discovery review).
	 */
	public static function handle_review(): void {
		self::guard( 'review' );
		$raw    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$domain = self::clean_domain( $raw );
		if ( '' === $domain ) {
			self::done( 'error', __( 'Enter a valid domain, for example example.com.', 'bladecp-migrator' ) );
		}
		self::remote_action(
			static function ( Api_Client $api, string $uuid ) use ( $domain ) {
				return $api->discovery_review( $uuid, $domain );
			},
			__( 'Domain confirmed. BladeCP continues the migration.', 'bladecp-migrator' )
		);
	}

	/**
	 * Approves the migration.
	 */
	public static function handle_approve(): void {
		self::guard( 'approve' );
		self::remote_action(
			static function ( Api_Client $api, string $uuid ) {
				return $api->approve( $uuid );
			},
			__( 'Migration approved. BladeCP is preparing your site for your domain.', 'bladecp-migrator' )
		);
	}

	/**
	 * Cancels the migration and deletes the local package.
	 */
	public static function handle_cancel(): void {
		self::guard( 'cancel' );
		Job_Service::discard();
		self::remote_action(
			static function ( Api_Client $api, string $uuid ) {
				return $api->cancel( $uuid, 'Cancelled from the WordPress plugin' );
			},
			__( 'Migration cancelled. Your current site was not changed.', 'bladecp-migrator' )
		);
	}

	/**
	 * Retries a failed migration (BladeCP keeps the package).
	 */
	public static function handle_retry(): void {
		self::guard( 'retry' );
		self::remote_action(
			static function ( Api_Client $api, string $uuid ) {
				$data = $api->retry( $uuid );
				if ( isset( $data['migrationUuid'] ) && is_string( $data['migrationUuid'] ) && strtolower( $data['migrationUuid'] ) !== $uuid ) {
					Connection::switch_migration( $data['migrationUuid'] );
				}
				return $data;
			},
			__( 'BladeCP is trying the migration again.', 'bladecp-migrator' )
		);
	}

	/**
	 * Measures the site files again.
	 */
	public static function handle_remeasure(): void {
		self::guard( 'remeasure' );
		Measure::reset();
		self::done( 'info', __( 'Measuring your site again.', 'bladecp-migrator' ) );
	}

	/**
	 * Runs one remote action and redirects with its outcome.
	 *
	 * @param callable $call    function( Api_Client $api, string $uuid ).
	 * @param string   $success Success message.
	 */
	private static function remote_action( callable $call, string $success ): void {
		$api  = Job_Service::api();
		$uuid = Connection::migration_uuid();
		if ( null === $api || null === $uuid ) {
			self::done( 'error', __( 'Connect to BladeCP first.', 'bladecp-migrator' ) );
		}
		try {
			call_user_func( $call, $api, (string) $uuid );
		} catch ( Api_Error $e ) {
			Remote::forget();
			self::done( 'error', $e->user_message() );
		}
		Remote::forget();
		self::done( 'success', $success );
	}

	/**
	 * Capability and nonce check for a POST action.
	 *
	 * @param string $action Action.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bladecp-migrator' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bladecp_migrator_' . $action );
	}

	/**
	 * Flash message and redirect back to the page.
	 *
	 * @param string $type    success, error or info.
	 * @param string $message Message.
	 */
	private static function done( string $type, string $message ): void {
		self::flash( $type, $message );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Stores a one-time message for the current user.
	 *
	 * @param string $type    Type.
	 * @param string $message Message.
	 */
	private static function flash( string $type, string $message ): void {
		set_transient(
			self::FLASH . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			120
		);
	}

	/**
	 * Takes the one-time message.
	 */
	private static function take_flash(): ?array {
		$key   = self::FLASH . get_current_user_id();
		$flash = get_transient( $key );
		if ( false !== $flash ) {
			delete_transient( $key );
		}
		return is_array( $flash ) ? $flash : null;
	}

	/**
	 * Opaque token from the query string (code, state, error).
	 *
	 * @param string $name Parameter.
	 */
	private static function token_param( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see on_load().
		$value = isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9._~-]{1,512}$/', $value ) ? $value : '';
	}

	/**
	 * Normalizes a typed domain; empty when invalid.
	 *
	 * @param string $raw Input.
	 */
	public static function clean_domain( string $raw ): string {
		$raw = trim( strtolower( $raw ) );
		$raw = (string) preg_replace( '#^[a-z]+://#', '', $raw );
		$raw = (string) preg_replace( '#[/?\#].*$#', '', $raw );
		$raw = rtrim( $raw, '.' );
		if ( 1 === preg_match( '/[^\x20-\x7e]/', $raw ) && function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
			$ascii = idn_to_ascii( $raw, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$raw   = is_string( $ascii ) ? strtolower( $ascii ) : $raw;
		}
		return Package_Layout::is_valid_hostname( $raw ) ? $raw : '';
	}


	/**
	 * Renders the page: gathers the state, then draws it with render_view().
	 */
	public static function render(): void {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bladecp-migrator' ) );
		}
		$work_dir   = Work_Dir::for_site();
		$measure    = Measure::get();
		$connection = Connection::get();
		$job        = Job_Service::store()->load();
		$remote     = null;
		$error      = null;
		if ( null !== $connection ) {
			$result = Remote::migration();
			$remote = $result['data'];
			$error  = $result['error'];
		}
		$phase = is_array( $remote ) ? (string) ( $remote['phase'] ?? '' ) : '';
		if ( Phases::accepts_package( $phase ) && null !== $job && Migration_Job::STAGE_DONE === $job['stage'] && $job['migration_uuid'] === Connection::migration_uuid() ) {
			// The package was confirmed a moment ago; BladeCP is about to report it.
			$remote['phase'] = 'queued';
		}
		self::render_view(
			array(
				'checks'     => Preflight::evaluate( Site::preflight_facts( $work_dir, $measure ) ),
				'measured'   => Measure::is_fresh( $measure, time() ),
				'connection' => $connection,
				'job'        => $job,
				'progress'   => Job_Presenter::present( $job, Job_Service::lock()->read(), time() ),
				'remote'     => $remote,
				'error'      => $error,
				'uuid'       => (string) Connection::migration_uuid(),
				'host'       => Package_Layout::normalize_domain( Site::host() ),
				'flash'      => self::take_flash(),
				'override'   => Config::uses_override(),
			)
		);
	}

	/**
	 * Draws the screen from a state, with no reads of its own: the dark header
	 * with the three steps, then one card per step on the light sheet.
	 *
	 * @param array $view checks, measured, connection, job, progress (Job_Presenter), remote, error (Api_Error|null), uuid, host, flash, override.
	 */
	public static function render_view( array $view ): void {
		$checks     = (array) $view['checks'];
		$blocked    = Preflight::has_blockers( $checks );
		$measured   = ! empty( $view['measured'] );
		$connection = isset( $view['connection'] ) && is_array( $view['connection'] ) ? $view['connection'] : null;
		$job        = isset( $view['job'] ) && is_array( $view['job'] ) ? $view['job'] : null;
		$progress   = (array) $view['progress'];
		$remote     = isset( $view['remote'] ) && is_array( $view['remote'] ) ? $view['remote'] : null;
		$error      = isset( $view['error'] ) && $view['error'] instanceof Api_Error ? $view['error'] : null;
		$uuid       = (string) ( $view['uuid'] ?? '' );
		$phase      = null !== $remote ? (string) ( $remote['phase'] ?? '' ) : '';
		$connected  = null !== $connection;
		$local_view = self::is_local_view( $job );
		$remote_ok  = $connected && ! $local_view && null === $error && null !== $remote;
		$poll       = $remote_ok && '' !== $phase && ! in_array( $phase, Phases::TERMINAL, true ) && ! Phases::accepts_package( $phase );
		$journey    = isset( $progress['journey'] ) && null !== $progress['journey'] ? (string) (int) $progress['journey'] : '';

		echo '<div class="wrap bcpm" data-measure="' . ( ! $measured && empty( $progress['active'] ) ? '1' : '0' ) . '" data-job="' . ( ! empty( $progress['active'] ) ? '1' : '0' ) . '" data-stage="' . esc_attr( (string) ( $progress['stage'] ?? 'none' ) ) . '" data-phase="' . esc_attr( $phase ) . '" data-poll="' . ( $poll ? '1' : '0' ) . '" data-journey="' . esc_attr( $journey ) . '">';
		// WordPress moves every admin notice right after this marker, above the dark header.
		echo '<hr class="wp-header-end">';
		$flash = isset( $view['flash'] ) && is_array( $view['flash'] ) ? $view['flash'] : null;
		if ( null !== $flash ) {
			$class = 'error' === $flash['type'] ? 'notice-error' : ( 'success' === $flash['type'] ? 'notice-success' : 'notice-info' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $flash['message'] ) . '</p></div>';
		}
		if ( ! empty( $view['override'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'A custom BladeCP address is set in wp-config.php. This is meant for testing only.', 'bladecp-migrator' ) . '</p></div>';
		}

		// The current step: migrate once connected, else the check until it passes, else connect.
		$check_done = $measured && ! $blocked;
		$complete   = $remote_ok && Phases::is_complete( $phase );
		$current    = $connected ? 3 : ( $check_done ? 2 : 1 );
		$blockers   = count( Preflight::blocker_labels( $checks ) );
		$steps      = array(
			array( 'check', 'clipboard-check', __( 'Check', 'bladecp-migrator' ), $check_done, $blockers > 0 ? array( $blockers, sprintf( /* translators: %s: number of preflight items to fix. */ _n( '%s item must be fixed', '%s items must be fixed', $blockers, 'bladecp-migrator' ), number_format_i18n( $blockers ) ) ) : null ),
			array( 'connect', 'link', __( 'Connect', 'bladecp-migrator' ), $connected, null ),
			array( 'migrate', 'rocket', __( 'Migrate', 'bladecp-migrator' ), $complete, $remote_ok && self::is_your_turn( $remote ) ? array( 1, __( 'Needs your action', 'bladecp-migrator' ) ) : null ),
		);

		echo '<div class="bcpm-app">';
		self::render_band( $steps, $current, $connection );
		echo '<div class="bcpm-sheet">';
		self::render_checks( $checks, $blocked, $measured, $connected && ! $blocked );
		self::render_connection( $connection, $checks, $job, (string) ( $view['host'] ?? '' ) );
		self::render_migration( $connection, $job, $progress, $remote, $error, $blocked, $uuid );
		echo '</div>';
		echo '<footer class="bcpm-foot">' . Icons::mark( 'bcpm-mark' ) . '<span>' . esc_html__( 'BladeCP Migrator', 'bladecp-migrator' ) . ' ' . esc_html( BLADECP_MIGRATOR_VERSION ) . '</span></footer>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the mark is a fixed SVG.
		echo '</div></div>';
	}

	/**
	 * Whether the migration card shows the local package work.
	 *
	 * @param array|null $job Local job.
	 */
	private static function is_local_view( ?array $job ): bool {
		return null !== $job && ( Migration_Job::is_active( $job ) || Migration_Job::STAGE_FAILED === $job['stage'] );
	}

	/**
	 * Whether the remote migration waits for the person. A domain already
	 * confirmed waits for BladeCP, not for the person.
	 *
	 * @param array|null $remote Remote migration.
	 */
	public static function is_your_turn( ?array $remote ): bool {
		$phase = null !== $remote ? (string) ( $remote['phase'] ?? '' ) : '';
		if ( ! Phases::needs_action( $phase ) ) {
			return false;
		}
		return ! ( 'discovery_review' === $phase && isset( $remote['primaryDomain'] ) && is_string( $remote['primaryDomain'] ) && '' !== $remote['primaryDomain'] );
	}

	/**
	 * The dark header: the brand (the page h1), help, the connection and the
	 * three steps anchored on its straight base.
	 *
	 * @param array      $steps      key, icon, label, done, counter (array( n, title ) or null).
	 * @param int        $current    Current step (1 to 3).
	 * @param array|null $connection Connection.
	 */
	private static function render_band( array $steps, int $current, ?array $connection ): void {
		echo '<header class="bcpm-band">';
		echo '<div class="bcpm-hz" aria-hidden="true"><span class="bcpm-hz-atmo"></span><span class="bcpm-hz-planet"></span><span class="bcpm-hz-rimwrap"><span class="bcpm-hz-rim"></span></span><span class="bcpm-hz-sun"></span></div>';
		echo '<div class="bcpm-band-row">';
		echo '<h1 class="bcpm-title">' . Icons::logo( 'bcpm-logo' ) . '<span class="bcpm-title-sep" aria-hidden="true"></span><span class="bcpm-title-name">' . esc_html__( 'Migrator', 'bladecp-migrator' ) . '</span></h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the logo is a fixed SVG.
		echo '<div class="bcpm-band-meta"><p class="bcpm-help"><span class="bcpm-help-q">' . esc_html__( 'Need help?', 'bladecp-migrator' ) . '</span>' . self::support_link( '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- support_link() escapes its parts.
		if ( null !== $connection ) {
			$email = trim( (string) ( $connection['email'] ?? '' ) );
			$mail  = 1 === preg_match( '/^[^\s@]{1,64}@[^\s@]{1,253}$/', $email ) ? '<span class="bcpm-conn-mail bcpm-ltr">' . esc_html( $email ) . '</span>' : '';
			echo '<a class="bcpm-conn" href="#bcpm-step-connect"><span class="bcpm-conn-dot" aria-hidden="true"></span><span>' . esc_html__( 'Connected', 'bladecp-migrator' ) . '</span>' . $mail . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		} else {
			echo '<a class="bcpm-conn is-off" href="#bcpm-step-connect"><span class="bcpm-conn-dot" aria-hidden="true"></span><span>' . esc_html__( 'Not connected', 'bladecp-migrator' ) . '</span></a>';
		}
		echo '</div></div>';
		echo '<p class="bcpm-tagline">' . esc_html__( 'Move this site to BladeCP in three steps: check, connect, migrate. Your current site keeps working until you point your domain to BladeCP.', 'bladecp-migrator' ) . '</p>';
		echo '<nav class="bcpm-steps" aria-label="' . esc_attr__( 'Migration steps', 'bladecp-migrator' ) . '"><ol>';
		foreach ( $steps as $index => $step ) {
			list( $key, $icon, $label, $done, $counter ) = $step;
			$is_current = $index + 1 === $current;
			echo '<li><a href="#bcpm-step-' . esc_attr( $key ) . '"' . ( $done ? ' class="is-done"' : '' ) . ( $is_current ? ' aria-current="step"' : '' ) . '>';
			echo Icons::svg( $done ? 'check' : $icon ) . '<span class="bcpm-step-l">' . esc_html( $label ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
			if ( $done ) {
				echo ' <span class="screen-reader-text">(' . esc_html__( 'step done', 'bladecp-migrator' ) . ')</span>';
			}
			if ( null !== $counter ) {
				echo '<span class="bcpm-tab-n" title="' . esc_attr( $counter[1] ) . '" aria-hidden="true">' . esc_html( number_format_i18n( (int) $counter[0] ) ) . '</span><span class="screen-reader-text">(' . esc_html( $counter[1] ) . ')</span>';
			}
			echo '</a></li>';
		}
		echo '</ol></nav></header>';
	}

	/**
	 * Opens a step card and prints its head.
	 *
	 * @param string $key    check, connect or migrate.
	 * @param int    $number Step number.
	 * @param string $icon   Icon of the step.
	 * @param string $title  Title.
	 * @param string $state  now, done or muted.
	 * @param string $desc   Line under the title (HTML, escaped by the caller).
	 * @param string $meta   Link at the end of the head (HTML, escaped by the caller).
	 */
	private static function card_open( string $key, int $number, string $icon, string $title, string $state, string $desc = '', string $meta = '' ): void {
		$class = 'bcpm-card' . ( 'done' === $state ? ' is-done' : '' ) . ( 'muted' === $state ? ' is-muted' : '' );
		$id    = 'bcpm-step-' . $key;
		echo '<section class="' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-title' ) . '"><header class="bcpm-card-head">';
		echo '<span class="bcpm-tile">' . Icons::svg( $icon ) . '<span class="bcpm-tile-n" aria-hidden="true">' . ( 'done' === $state ? Icons::svg( 'check' ) : esc_html( number_format_i18n( $number ) ) ) . '</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '<div class="bcpm-card-main"><h2 class="bcpm-card-title" id="' . esc_attr( $id . '-title' ) . '">' . esc_html( $title ) . '</h2>' . $desc . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( '' !== $meta ) {
			echo '<div class="bcpm-card-meta">' . $meta . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</header>';
	}

	/**
	 * Step 1: preflight. After connecting, the list folds into one line.
	 *
	 * @param array $checks    Checks.
	 * @param bool  $blocked   Any blocker.
	 * @param bool  $measured  Files measured.
	 * @param bool  $collapsed Fold the list.
	 */
	private static function render_checks( array $checks, bool $blocked, bool $measured, bool $collapsed = false ): void {
		if ( $blocked ) {
			$desc = '<div class="bcpm-card-desc bcpm-t-danger">' . Icons::svg( 'circle-alert' ) . '<p>' . esc_html__( 'Fix the problems marked below before migrating.', 'bladecp-migrator' ) . '</p></div>';
		} elseif ( $measured ) {
			$desc = '<div class="bcpm-card-desc bcpm-t-success">' . Icons::svg( 'circle-check' ) . '<p>' . esc_html__( 'Everything needed for the migration is in place.', 'bladecp-migrator' ) . '</p></div>';
		} else {
			$desc = '<div class="bcpm-card-desc bcpm-t-neutral">' . Icons::svg( 'loader', 'bcpm-spin' ) . '<p id="bcpm-measure-status">' . esc_html__( 'Measuring your site files...', 'bladecp-migrator' ) . '</p></div>'
				. '<noscript><p class="bcpm-card-desc">' . esc_html__( 'Turn on JavaScript to measure your site and follow the progress on this page.', 'bladecp-migrator' ) . '</p></noscript>';
		}
		self::card_open( 'check', 1, 'clipboard-check', __( 'Check your site', 'bladecp-migrator' ), $measured && ! $blocked ? 'done' : 'now', $desc );
		echo '<div class="bcpm-card-body">';
		if ( $collapsed ) {
			echo '<details class="bcpm-more"><summary>' . esc_html__( 'See the checked items', 'bladecp-migrator' ) . Icons::svg( 'chevron-down' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		}
		self::render_check_list( $checks );
		if ( $measured ) {
			echo '<div class="bcpm-card-foot">';
			self::action_form( 'remeasure', __( 'Measure again', 'bladecp-migrator' ), 'bcpm-btn-link', array(), '', array(), 'refresh' );
			echo '</div>';
		}
		if ( $collapsed ) {
			echo '</details>';
		}
		echo '</div></section>';
	}

	/**
	 * The checks as icon cards: what blocks first, then what needs attention,
	 * then the rest in the order Preflight gives them.
	 *
	 * @param array $checks Checks.
	 */
	private static function render_check_list( array $checks ): void {
		$groups = array(
			Preflight::BLOCKER => array(),
			Preflight::WARNING => array(),
			''                 => array(),
		);
		foreach ( $checks as $check ) {
			$groups[ isset( $groups[ $check['status'] ] ) ? $check['status'] : '' ][] = $check;
		}
		echo '<ul class="bcpm-checks">';
		foreach ( array_merge( $groups[ Preflight::BLOCKER ], $groups[ Preflight::WARNING ], $groups[''] ) as $check ) {
			$status = (string) $check['status'];
			$tone   = Preflight::BLOCKER === $status ? ' bcpm-t-danger is-flag' : ( Preflight::WARNING === $status ? ' bcpm-t-warning is-flag' : '' );
			$value  = esc_html( (string) $check['value'] );
			if ( 'address' === $check['id'] ) {
				$value = '<span class="bcpm-ltr">' . $value . '</span>';
			}
			echo '<li class="bcpm-check' . esc_attr( $tone ) . '"><span class="bcpm-check-ic">' . Icons::svg( self::CHECK_ICONS[ $check['id'] ] ?? 'info' ) . '</span><div class="bcpm-check-body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
			echo '<p class="bcpm-check-label">' . esc_html( (string) $check['label'] ) . '</p><p class="bcpm-check-value">' . $value . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			if ( '' !== (string) $check['message'] ) {
				echo '<p class="bcpm-check-msg">' . esc_html( (string) $check['message'] ) . '</p>';
			}
			if ( ! empty( $check['items'] ) ) {
				self::render_skipped( (array) $check['items'], (int) ( $check['more'] ?? 0 ) );
			}
			echo '</div><span class="bcpm-check-status">' . self::status_chip( $status ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_chip() escapes its parts.
		}
		echo '</ul>';
	}

	/**
	 * Entries the package will skip, as path tags, and how many more.
	 *
	 * @param array $items Entries: type (link or unreadable) and path.
	 * @param int   $more  Skipped entries not named.
	 */
	private static function render_skipped( array $items, int $more ): void {
		echo '<ul class="bcpm-skipped">';
		foreach ( $items as $item ) {
			/* translators: %s: path of a symbolic link, relative to the WordPress folder. */
			$link = __( '%s (link)', 'bladecp-migrator' );
			/* translators: %s: path of a file or folder PHP cannot read, relative to the WordPress folder. */
			$unreadable = __( '%s (unreadable)', 'bladecp-migrator' );
			$format     = 'link' === ( $item['type'] ?? '' ) ? $link : $unreadable;
			$parts      = explode( '%s', $format, 2 );
			$path       = '<code>' . esc_html( (string) ( $item['path'] ?? '' ) ) . '</code>';
			echo '<li>' . ( 2 === count( $parts ) ? esc_html( $parts[0] ) . $path . esc_html( $parts[1] ) : $path ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		if ( $more > 0 ) {
			/* translators: %s: number of other skipped items. */
			echo '<li class="bcpm-more-n">' . esc_html( sprintf( _n( '%s more', '%s more', $more, 'bladecp-migrator' ), number_format_i18n( $more ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Status of a check: a green check for OK, a named chip otherwise.
	 *
	 * @param string $status Status.
	 */
	private static function status_chip( string $status ): string {
		switch ( $status ) {
			case Preflight::BLOCKER:
				return '<span class="bcpm-chip bcpm-t-danger">' . Icons::svg( 'circle-x' ) . esc_html__( 'Must be fixed', 'bladecp-migrator' ) . '</span>';
			case Preflight::WARNING:
				return '<span class="bcpm-chip bcpm-t-warning">' . Icons::svg( 'triangle-alert' ) . esc_html__( 'Warning', 'bladecp-migrator' ) . '</span>';
			case Preflight::PENDING:
				return '<span class="bcpm-chip bcpm-t-neutral">' . Icons::svg( 'loader', 'bcpm-spin' ) . esc_html__( 'Checking', 'bladecp-migrator' ) . '</span>';
		}
		return '<span class="bcpm-check-ok">' . Icons::svg( 'circle-check' ) . '<span class="screen-reader-text">' . esc_html__( 'OK', 'bladecp-migrator' ) . '</span></span>';
	}

	/**
	 * Step 2: connection.
	 *
	 * @param array|null $connection Connection.
	 * @param array      $checks     Preflight checks.
	 * @param array|null $job        Local job.
	 * @param string     $host       This site's domain, shown in the drawing.
	 */
	private static function render_connection( ?array $connection, array $checks, ?array $job, string $host = '' ): void {
		if ( null !== $connection ) {
			self::card_open( 'connect', 2, 'link', __( 'Connect to BladeCP', 'bladecp-migrator' ), 'done' );
			$line    = self::connected_text( $connection );
			$expires = strtotime( (string) $connection['expires_at'] );
			if ( false !== $expires ) {
				$line .= ' ' . sprintf(
					/* translators: %s: date. */
					__( 'The connection is valid until %s.', 'bladecp-migrator' ),
					self::date_text( $expires )
				);
			}
			echo '<div class="bcpm-card-body"><div class="bcpm-connected"><div class="bcpm-connected-text">';
			echo '<p class="bcpm-connected-line">' . Icons::svg( 'circle-check' ) . '<span>' . esc_html( $line ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
			echo '<p class="bcpm-connected-note">' . esc_html__( 'This connection can only see and manage the migration you chose in BladeCP. The key is stored encrypted and never shown.', 'bladecp-migrator' ) . '</p></div>';
			self::action_form( 'disconnect', __( 'Disconnect', 'bladecp-migrator' ), 'bcpm-btn', array(), __( 'Remove the BladeCP key from this site? A package being built here is deleted. The migration in BladeCP is not cancelled.', 'bladecp-migrator' ), array(), 'unplug' );
			echo '</div></div></section>';
			return;
		}
		$gate = self::connect_gate( $checks );
		self::card_open( 'connect', 2, 'link', __( 'Connect to BladeCP', 'bladecp-migrator' ), 'now' );
		echo '<div class="bcpm-card-body"><div class="bcpm-connect"><div>';
		if ( null !== $job && ! empty( $job['reconnect'] ) ) {
			echo '<p>' . esc_html__( 'Connect again to continue. The package already built on this site is kept.', 'bladecp-migrator' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'You will sign in to BladeCP, choose the server that receives this site and come back here automatically.', 'bladecp-migrator' ) . '</p>';
		}
		self::action_form( 'connect', __( 'Connect to BladeCP', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-primary bcpm-btn-hero', array(), '', $gate['attrs'], 'log-in' );
		if ( '' !== $gate['note'] ) {
			echo '<p class="bcpm-blocked" id="' . esc_attr( self::CONNECT_NOTE_ID ) . '">' . Icons::svg( 'circle-alert' ) . '<span>' . esc_html( $gate['note'] ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		}
		echo '</div>';
		// This site and BladeCP, joined by a dashed line (decorative).
		echo '<div class="bcpm-art" aria-hidden="true"><span class="bcpm-art-node"><span class="bcpm-art-box">' . Icons::svg( 'app-window' ) . '</span>' . ( '' !== $host ? '<span class="bcpm-art-cap">' . esc_html( $host ) . '</span>' : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '<span class="bcpm-art-line"></span><span class="bcpm-art-node bcpm-art-brand"><span class="bcpm-art-box">' . Icons::mark( 'bcpm-mark' ) . '</span><span class="bcpm-art-cap">BladeCP</span></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '</div></div></section>';
	}

	/**
	 * The "Connect to BladeCP" button while checks block it: disabled, and
	 * described by a sentence that names the checks marked in red. Nothing
	 * changes when no check blocks.
	 *
	 * @param array $checks Preflight checks.
	 * @return array{attrs:array<string,string>,note:string}
	 */
	public static function connect_gate( array $checks ): array {
		$labels = Preflight::blocker_labels( $checks );
		if ( array() === $labels ) {
			return array(
				'attrs' => array(),
				'note'  => '',
			);
		}
		$list = function_exists( 'wp_sprintf' ) ? (string) wp_sprintf( '%l', $labels ) : implode( ', ', $labels );
		return array(
			'attrs' => array(
				'disabled'         => 'disabled',
				'aria-describedby' => self::CONNECT_NOTE_ID,
			),
			'note'  => sprintf(
				/* translators: %s: names of the checks to fix, as shown under "Check your site" (for example "Secure connection"). */
				_n( 'To connect, first fix the item marked in red under "Check your site": %s.', 'To connect, first fix the items marked in red under "Check your site": %s.', count( $labels ), 'bladecp-migrator' ),
				$list
			),
		);
	}

	/**
	 * "Connected" sentence. BladeCP sends a masked email (for example
	 * j***@example.com) and no name; anything that does not look like an
	 * email is left out.
	 *
	 * @param array $connection Connection metadata.
	 */
	public static function connected_text( array $connection ): string {
		$email = trim( (string) ( $connection['email'] ?? '' ) );
		if ( 1 === preg_match( '/^[^\s@]{1,64}@[^\s@]{1,253}$/', $email ) ) {
			/* translators: %s: account email, usually masked (for example j***@example.com). */
			return sprintf( __( 'Connected to the BladeCP account %s.', 'bladecp-migrator' ), $email );
		}
		return __( 'Connected to your BladeCP account.', 'bladecp-migrator' );
	}

	/**
	 * Step 3: the migration.
	 *
	 * @param array|null     $connection Connection.
	 * @param array|null     $job        Local job.
	 * @param array          $progress   Local progress.
	 * @param array|null     $remote     Remote migration.
	 * @param Api_Error|null $error      Remote error.
	 * @param bool           $blocked    Any blocker.
	 * @param string         $uuid       Migration UUID.
	 */
	private static function render_migration( ?array $connection, ?array $job, array $progress, ?array $remote, ?Api_Error $error, bool $blocked, string $uuid = '' ): void {
		$connected  = null !== $connection;
		$local_view = self::is_local_view( $job );
		$phase      = null !== $remote ? (string) ( $remote['phase'] ?? '' ) : '';
		$state      = ! $connected && ! $local_view ? 'muted' : ( $connected && ! $local_view && null === $error && Phases::is_complete( $phase ) ? 'done' : 'now' );
		$meta       = '';
		if ( $connected && ! $local_view && null === $error && '' !== $uuid ) {
			$meta = '<a class="bcpm-link" href="' . esc_url( Config::hub_migration_url( $uuid ) ) . '" target="_blank" rel="noopener noreferrer"><span>' . esc_html__( 'Open this migration in BladeCP', 'bladecp-migrator' ) . '</span>' . Icons::svg( 'external-link' ) . '</a>';
		}
		self::card_open( 'migrate', 3, 'rocket', __( 'Migrate', 'bladecp-migrator' ), $state, '', $meta );
		echo '<div class="bcpm-card-body">';
		if ( $local_view ) {
			self::render_local( (array) $job, $progress, $connected );
		} elseif ( ! $connected ) {
			self::journey( null, 'info' );
			echo '<p class="bcpm-placeholder">' . Icons::svg( 'lock' ) . '<span>' . esc_html__( 'Connect to BladeCP to continue.', 'bladecp-migrator' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		} elseif ( null !== $error ) {
			self::alert( 'danger', 'circle-alert', $error->user_message() );
			if ( $error->requires_reconnect() || Api_Error::KIND_NOT_FOUND === $error->kind() ) {
				self::note( 'info', __( 'Disconnect and connect again to start a new migration.', 'bladecp-migrator' ) );
			}
		} elseif ( Phases::accepts_package( $phase ) ) {
			self::journey( null, 'info' );
			echo '<div class="bcpm-start"><p>' . esc_html__( 'One click builds a package with your files and database, sends it to BladeCP and starts the migration. Large sites can take a while.', 'bladecp-migrator' ) . '</p>';
			self::action_form( 'start', __( 'Start migration', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-primary bcpm-btn-hero', array(), '', $blocked ? array( 'disabled' => 'disabled' ) : array(), 'rocket' );
			echo '</div>';
		} else {
			self::render_remote( (array) $remote );
		}
		echo '</div></section>';
	}

	/**
	 * The 6-stage trail. With a stage: the stages before it are done and the
	 * stage itself is lit in the tone; STAGE_COUNT (complete) lights every stage
	 * green. Without one (idle) it only names the stages ahead. The percentage
	 * is printed only when there is a real one: the local package work, or 100
	 * once the migration is complete.
	 *
	 * @param int|null $stage    Current stage (0 to 5), Phases::STAGE_COUNT when complete, null when idle.
	 * @param string   $tone     info, warning, danger or success.
	 * @param bool     $working  BladeCP or this site is working on it now.
	 * @param int|null $percent  Real percentage, or null.
	 * @param string   $label_id Id of the title that names the percentage.
	 */
	private static function journey( ?int $stage, string $tone, bool $working = false, ?int $percent = null, string $label_id = 'bcpm-progress-title' ): void {
		$labels = Phases::stage_labels();
		$count  = count( $labels );
		$live   = null !== $stage && $stage >= $count;
		$class  = 'bcpm-journey bcpm-t-' . $tone . ( $working ? ' is-working' : '' ) . ( $live ? ' is-live' : '' ) . ( null === $stage ? ' is-idle' : '' );
		echo '<div class="' . esc_attr( $class ) . '"><div class="bcpm-journey-top">';
		if ( null === $stage ) {
			echo '<p class="bcpm-journey-now"><strong>' . esc_html__( 'Migration stages', 'bladecp-migrator' ) . '</strong></p>';
		} else {
			$index = min( $stage, $count - 1 );
			/* translators: 1: number of the current stage, 2: number of stages. */
			$of = sprintf( __( 'Stage %1$s of %2$s', 'bladecp-migrator' ), number_format_i18n( $index + 1 ), number_format_i18n( $count ) );
			echo '<p class="bcpm-journey-now"><span>' . esc_html( $of ) . '</span><strong>' . esc_html( $labels[ $index ] ) . '</strong></p>';
		}
		if ( null !== $percent ) {
			// Only the local work updates the percentage, through #bcpm-progress-pct.
			$pct_id = 'bcpm-progress-title' === $label_id ? ' id="bcpm-progress-pct"' : '';
			echo '<span class="bcpm-journey-pct"' . $pct_id . ' role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $percent ) . '" aria-labelledby="' . esc_attr( $label_id ) . '">' . esc_html( $percent . '%' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
		}
		echo '</div><ol class="bcpm-segs" aria-label="' . esc_attr__( 'Migration stages', 'bladecp-migrator' ) . '">';
		foreach ( $labels as $i => $label ) {
			$done = null !== $stage && ( $live || $i < $stage );
			$now  = null !== $stage && ! $live && $i === $stage;
			echo '<li class="bcpm-seg' . ( $done ? ' is-done' : '' ) . ( $now ? ' is-now' : '' ) . '"' . ( $now ? ' aria-current="step"' : '' ) . '><span class="bcpm-seg-bar"></span><span class="bcpm-seg-l">' . esc_html( $label );
			if ( $done ) {
				echo ' <span class="screen-reader-text">(' . esc_html__( 'stage done', 'bladecp-migrator' ) . ')</span>';
			}
			echo '</span></li>';
		}
		echo '</ol></div>';
	}

	/**
	 * The current situation: a tone tile, a title and up to two lines.
	 *
	 * @param string $icon   Icon.
	 * @param string $tone   Tone.
	 * @param string $title  Title.
	 * @param string $desc   Description, or ''.
	 * @param string $detail Detail line, or ''.
	 * @param bool   $local  Local job: the title and detail carry the ids the script updates.
	 * @param string $id     Id of the title, or '' (the local job always uses bcpm-progress-title).
	 */
	private static function status( string $icon, string $tone, string $title, string $desc = '', string $detail = '', bool $local = false, string $id = '' ): void {
		$spin = 'loader' === $icon ? 'bcpm-spin' : '';
		$id   = $local ? 'bcpm-progress-title' : $id;
		echo '<div class="bcpm-status bcpm-t-' . esc_attr( $tone ) . '"><span class="bcpm-status-ic">' . Icons::svg( $icon, $spin ) . '</span><div class="bcpm-status-body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '<h3 class="bcpm-status-title"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</h3>';
		if ( '' !== $desc ) {
			echo '<p class="bcpm-status-desc">' . esc_html( $desc ) . '</p>';
		}
		if ( $local || '' !== $detail ) {
			echo '<p class="bcpm-status-detail"' . ( $local ? ' id="bcpm-progress-detail"' : '' ) . '>' . esc_html( $detail ) . '</p>';
		}
		echo '</div></div>';
	}

	/**
	 * A message inside a card. Its own class, never .notice: WordPress moves
	 * every .notice to the top of the page.
	 *
	 * @param string $tone Tone.
	 * @param string $icon Icon.
	 * @param string $text Text.
	 * @param string $key  Short label before the text, or ''.
	 */
	private static function alert( string $tone, string $icon, string $text, string $key = '' ): void {
		echo '<div class="bcpm-alert bcpm-t-' . esc_attr( $tone ) . '">' . Icons::svg( $icon ) . '<p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		if ( '' !== $key ) {
			echo '<span class="bcpm-alert-k">' . esc_html( $key ) . '</span>';
		}
		echo esc_html( $text ) . '</p></div>';
	}

	/**
	 * A quiet line with an icon.
	 *
	 * @param string $icon Icon.
	 * @param string $text Text.
	 */
	private static function note( string $icon, string $text ): void {
		echo '<p class="bcpm-note">' . Icons::svg( $icon ) . '<span>' . esc_html( $text ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
	}

	/**
	 * Local package progress.
	 *
	 * @param array $job       Job.
	 * @param array $progress  Progress.
	 * @param bool  $connected Connected.
	 */
	private static function render_local( array $job, array $progress, bool $connected ): void {
		$failed  = Migration_Job::STAGE_FAILED === $job['stage'];
		$waiting = ! $failed && ! empty( $progress['waiting'] );
		$tone    = $failed ? 'danger' : ( $waiting ? 'warning' : 'info' );
		$stage   = isset( $progress['journey'] ) && null !== $progress['journey'] ? (int) $progress['journey'] : null;
		if ( null !== $stage ) {
			self::journey( $stage, $tone, ! $failed && ! $waiting, $failed ? null : (int) $progress['percent'] );
		}
		self::status( $failed ? 'circle-x' : ( $waiting ? 'rotate-ccw' : 'loader' ), $tone, (string) $progress['title'], '', (string) $progress['detail'], true );
		if ( '' !== $progress['error'] ) {
			echo '<div id="bcpm-progress-error">';
			self::alert( $failed ? 'danger' : 'warning', $failed ? 'circle-alert' : 'triangle-alert', (string) $progress['error'] );
			echo '</div>';
		}
		if ( $failed ) {
			echo '<div class="bcpm-actions">';
			if ( empty( $job['reconnect'] ) || $connected ) {
				self::action_form( 'resume', __( 'Try again', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-primary', array(), '', array(), 'rotate-ccw' );
			}
			self::action_form( 'stop', __( 'Start over', 'bladecp-migrator' ), 'bcpm-btn', array(), __( 'Delete the package built so far?', 'bladecp-migrator' ) );
			echo '</div>';
			return;
		}
		self::note( 'info', __( 'Keep this page open for the fastest progress. If you close it, the work continues more slowly in the background.', 'bladecp-migrator' ) );
		echo '<div class="bcpm-actions">';
		self::action_form( 'stop', __( 'Stop', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-danger', array(), __( 'Stop and delete the package built so far? Your BladeCP migration is not cancelled.', 'bladecp-migrator' ), array(), 'circle-stop' );
		echo '</div>';
	}

	/**
	 * Remote migration status and actions.
	 *
	 * @param array $remote Remote migration.
	 */
	private static function render_remote( array $remote ): void {
		$phase = (string) ( $remote['phase'] ?? '' );
		if ( Phases::is_complete( $phase ) ) {
			self::render_complete( $remote );
			return;
		}
		$your_turn = self::is_your_turn( $remote );
		$tone      = 'discovery_review' === $phase && ! $your_turn ? 'info' : Phases::tone( $phase );
		$working   = Phases::is_working( $phase ) || ( 'discovery_review' === $phase && ! $your_turn );
		$stage     = Phases::stage( $phase );
		if ( null !== $stage ) {
			self::journey( $stage, $tone, $working );
		}
		$icon = $working && 'queued' !== $phase ? 'loader' : ( self::PHASE_ICONS[ $phase ] ?? 'loader' );
		self::status( $icon, $tone, Phases::label( $phase ), Phases::description( $phase ) );

		$message = isset( $remote['message'] ) && is_string( $remote['message'] ) ? trim( $remote['message'] ) : '';
		if ( '' !== $message && 'failed' === $phase ) {
			self::alert( 'danger', 'info', $message, __( 'Details from BladeCP:', 'bladecp-migrator' ) );
		}
		if ( 'discovery_review' === $phase ) {
			self::render_review( $remote );
		}

		// One row, one height: the preview, then approve, retry, support or cancel.
		$staging = self::staging_url( $remote );
		$show    = '' !== $staging && in_array( $phase, array( 'awaiting_approval', 'syncing' ), true );
		$approve = 'awaiting_approval' === $phase;
		$retry   = 'failed' === $phase && ! empty( $remote['retry']['allowed'] );
		$support = 'failed' === $phase;
		$cancel  = Phases::can_cancel( $phase );
		if ( ! $show && ! $approve && ! $retry && ! $support && ! $cancel ) {
			return;
		}
		echo '<div class="bcpm-actions">';
		if ( $show ) {
			echo '<a class="bcpm-btn" href="' . esc_url( $staging ) . '" target="_blank" rel="noopener noreferrer"><span>' . esc_html__( 'Open the preview', 'bladecp-migrator' ) . '</span>' . Icons::svg( 'external-link' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		}
		if ( $approve ) {
			self::action_form( 'approve', __( 'Approve migration', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-primary', array(), __( 'Approve the migration? BladeCP then prepares your site to answer on your own domain.', 'bladecp-migrator' ), array(), 'check' );
		}
		if ( $retry ) {
			self::action_form( 'retry', __( 'Try again', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-primary', array(), '', array(), 'rotate-ccw' );
		}
		if ( $support ) {
			echo self::support_link( 'bcpm-btn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- support_link() escapes its parts.
		}
		if ( $cancel ) {
			// Alone it starts the row; after other actions it ends it.
			self::action_form( 'cancel', __( 'Cancel migration', 'bladecp-migrator' ), 'bcpm-btn bcpm-btn-danger', array(), __( 'Cancel this migration? Your current site is not changed.', 'bladecp-migrator' ), array(), '', $show || $approve || $retry || $support ? 'bcpm-push' : '' );
		}
		echo '</div>';
	}

	/**
	 * Domain confirmation form.
	 *
	 * @param array $remote Remote migration.
	 */
	private static function render_review( array $remote ): void {
		$chosen = isset( $remote['primaryDomain'] ) && is_string( $remote['primaryDomain'] ) ? $remote['primaryDomain'] : '';
		if ( '' !== $chosen ) {
			/* translators: %s: domain. */
			self::note( 'circle-check', sprintf( __( 'Domain confirmed: %s. Waiting for BladeCP to continue.', 'bladecp-migrator' ), $chosen ) );
			return;
		}
		$suggested = '';
		foreach ( array( 'sourcePrimaryDomain', 'requestedPrimaryDomain' ) as $key ) {
			if ( '' === $suggested && isset( $remote[ $key ] ) && is_string( $remote[ $key ] ) ) {
				$suggested = $remote[ $key ];
			}
		}
		if ( '' === $suggested ) {
			$suggested = Package_Layout::normalize_domain( Site::host() );
		}
		$discovery = isset( $remote['discovery'] ) && is_array( $remote['discovery'] ) ? $remote['discovery'] : null;
		if ( null !== $discovery ) {
			self::note(
				'search',
				sprintf(
					/* translators: 1: number of files, 2: size, 3: PHP version. */
					__( 'BladeCP found %1$s files (%2$s) and PHP %3$s.', 'bladecp-migrator' ),
					number_format_i18n( (int) ( $discovery['fileCount'] ?? 0 ) ),
					Migration_Job::size( (int) ( $discovery['fileBytes'] ?? 0 ) ),
					(string) ( $discovery['phpVersion'] ?? '' )
				)
			);
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bcpm-domain-form">';
		echo '<input type="hidden" name="action" value="bladecp_migrator_review">';
		wp_nonce_field( 'bladecp_migrator_review' );
		echo '<p class="bcpm-field"><label class="bcpm-label" for="bcpm-domain">' . esc_html__( 'Domain of your site', 'bladecp-migrator' ) . '</label>';
		echo '<input type="text" class="bcpm-input" id="bcpm-domain" name="domain" value="' . esc_attr( $suggested ) . '" autocomplete="off" spellcheck="false" required></p>';
		echo '<button type="submit" class="bcpm-btn bcpm-btn-primary">' . Icons::svg( 'check' ) . '<span>' . esc_html__( 'Confirm domain', 'bladecp-migrator' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '</form>';
	}

	/**
	 * The preview address BladeCP sent, when it is safe to link.
	 *
	 * @param array $remote Remote migration.
	 */
	private static function staging_url( array $remote ): string {
		$staging = isset( $remote['stagingUrl'] ) && is_string( $remote['stagingUrl'] ) ? $remote['stagingUrl'] : '';
		return '' !== $staging && Config::is_acceptable_url( $staging ) ? $staging : '';
	}

	/**
	 * The migrated site's domain, when it is a valid one.
	 *
	 * @param array $remote Remote migration.
	 */
	private static function remote_domain( array $remote ): string {
		$domain = isset( $remote['primaryDomain'] ) && is_string( $remote['primaryDomain'] ) ? strtolower( trim( $remote['primaryDomain'] ) ) : '';
		return Package_Layout::is_valid_hostname( $domain ) ? $domain : '';
	}

	/**
	 * A sentence with one value in bold: the text around the %s is escaped,
	 * the value is escaped and wrapped.
	 *
	 * @param string $format Translated sentence with one %s.
	 * @param string $value  Value.
	 * @param string $class  Class of the bold value.
	 */
	private static function emphasize( string $format, string $value, string $class = '' ): string {
		$strong = '<strong' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $value ) . '</strong>';
		$parts  = explode( '%s', $format, 2 );
		return 2 === count( $parts ) ? esc_html( $parts[0] ) . $strong . esc_html( $parts[1] ) : esc_html( $format ) . ' ' . $strong;
	}

	/**
	 * The migration is complete once approved: every stage green at 100%,
	 * "Migration completed", the optional domain card (or the note that the
	 * domain already points to BladeCP) and the link to the site or to the
	 * migrated copy. Pointing the domain is never a required step.
	 *
	 * @param array $remote Remote migration.
	 */
	private static function render_complete( array $remote ): void {
		$phase  = (string) ( $remote['phase'] ?? '' );
		$live   = 'live' === $phase;
		$domain = self::remote_domain( $remote );
		self::journey( Phases::STAGE_COUNT, 'success', false, 100, 'bcpm-status-title' );
		/* translators: %s: domain of the migrated site. */
		$desc = '' !== $domain ? sprintf( __( '%s is already on your BladeCP server.', 'bladecp-migrator' ), $domain ) : Phases::description( $phase );
		self::status( 'circle-check', 'success', Phases::label( $phase ), $desc, '', false, 'bcpm-status-title' );
		if ( $live ) {
			if ( '' !== $domain ) {
				/* translators: %s: domain of the migrated site. */
				echo '<p class="bcpm-pointed">' . Icons::svg( 'globe' ) . '<span>' . self::emphasize( __( '%s already points to BladeCP.', 'bladecp-migrator' ), $domain, 'bcpm-ltr' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- emphasize() escapes its parts.
			}
		} else {
			self::render_dns( $remote, $domain );
		}
		$staging = self::staging_url( $remote );
		if ( $live && '' !== $domain ) {
			$link = array( 'https://' . $domain . '/', 'external-link', __( 'Open site', 'bladecp-migrator' ) );
		} elseif ( ! $live && '' !== $staging ) {
			$link = array( $staging, 'eye', __( 'View the migrated site', 'bladecp-migrator' ) );
		} else {
			return;
		}
		echo '<div class="bcpm-actions"><a class="bcpm-btn bcpm-btn-primary" href="' . esc_url( $link[0] ) . '" target="_blank" rel="noopener noreferrer">' . Icons::svg( $link[1] ) . '<span>' . esc_html( $link[2] ) . '</span></a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
	}

	/**
	 * "Point the domain whenever you want": optional, in the pattern of the
	 * BladeCP Hub. The nameservers first, each with a copy button and one for
	 * both, the hint for .br domains, then the A record as an alternative, and
	 * the note when the automatic check has ended.
	 *
	 * @param array  $remote Remote migration.
	 * @param string $domain Domain of the migrated site, or ''.
	 */
	private static function render_dns( array $remote, string $domain ): void {
		$stalled = 'cutover_stalled' === ( $remote['phase'] ?? '' );
		$target  = isset( $remote['cutoverTarget'] ) && is_string( $remote['cutoverTarget'] ) ? trim( $remote['cutoverTarget'] ) : '';
		$copied  = __( 'Copied', 'bladecp-migrator' );
		$icons   = Icons::svg( 'copy', 'bcpm-i-copy' ) . Icons::svg( 'check', 'bcpm-i-done' );
		echo '<section class="bcpm-dns" aria-labelledby="bcpm-dns-title"><div class="bcpm-dns-head">' . Icons::svg( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '<h4 class="bcpm-dns-title" id="bcpm-dns-title">' . esc_html__( 'Point the domain whenever you want', 'bladecp-migrator' ) . '</h4>';
		echo '<span class="bcpm-dns-opt">' . esc_html__( 'Optional. Meanwhile, the old site stays online.', 'bladecp-migrator' ) . '</span>';
		echo '<button type="button" class="bcpm-btn-link bcpm-copy" data-copy="' . esc_attr( implode( "\n", Config::NAMESERVERS ) ) . '" data-copied="' . esc_attr( $copied ) . '">' . $icons . '<span>' . esc_html__( 'Copy both', 'bladecp-migrator' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		echo '<dl class="bcpm-ns">';
		foreach ( Config::NAMESERVERS as $index => $nameserver ) {
			/* translators: %s: a nameserver, such as kant.ns.bladecp.com. */
			$label = sprintf( __( 'Copy %s', 'bladecp-migrator' ), $nameserver );
			echo '<div class="bcpm-ns-item"><dt>NS' . esc_html( (string) ( $index + 1 ) ) . '</dt><dd><code>' . esc_html( $nameserver ) . '</code>';
			echo '<button type="button" class="bcpm-icon-btn bcpm-copy" data-copy="' . esc_attr( $nameserver ) . '" data-copied="' . esc_attr( $copied ) . '" aria-label="' . esc_attr( $label ) . '">' . $icons . '</button></dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		}
		echo '</dl>';
		echo '<p class="bcpm-dns-hint">' . esc_html( $stalled ? __( 'Change them at the provider where you registered the domain.', 'bladecp-migrator' ) : __( 'Change them at the provider where you registered the domain. We check on our own and let you know here when it points.', 'bladecp-migrator' ) ) . '</p>';
		if ( '.br' === substr( $domain, -3 ) ) {
			/* translators: %s: name of the Registro.br button, "Alterar servidores DNS" (kept in Portuguese, as Registro.br shows it). */
			echo '<p class="bcpm-dns-br"><span>' . self::emphasize( __( '.br domain: at Registro.br, choose the domain and use %s', 'bladecp-migrator' ), 'Alterar servidores DNS' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- emphasize() escapes its parts.
			echo '<a class="bcpm-link" href="https://registro.br" target="_blank" rel="noopener noreferrer"><span>' . esc_html__( 'Open Registro.br', 'bladecp-migrator' ) . '</span>' . Icons::svg( 'external-link' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		}
		if ( '' !== $target && '' !== $domain ) {
			echo '<p class="bcpm-dns-alt">' . esc_html__( 'Or keep your DNS where it is and point the A record instead:', 'bladecp-migrator' ) . '</p>';
			$hosts = array( $domain => false );
			if ( 0 !== strpos( $domain, 'www.' ) ) {
				$hosts[ 'www.' . $domain ] = true;
			}
			$type  = __( 'Type', 'bladecp-migrator' );
			$name  = __( 'Name', 'bladecp-migrator' );
			$value = __( 'Value', 'bladecp-migrator' );
			$copy  = __( 'Copy', 'bladecp-migrator' );
			echo '<table class="bcpm-records"><thead><tr><th scope="col">' . esc_html( $type ) . '</th><th scope="col">' . esc_html( $name ) . '</th><th scope="col">' . esc_html( $value ) . '</th><th scope="col" class="bcpm-col-copy"><span class="screen-reader-text">' . esc_html( $copy ) . '</span></th></tr></thead><tbody>';
			foreach ( $hosts as $host => $optional ) {
				echo '<tr><td data-label="' . esc_attr( $type ) . '"><span class="bcpm-rtype">A</span></td>';
				echo '<td data-label="' . esc_attr( $name ) . '"><span><bdi>' . esc_html( $host ) . '</bdi>' . ( $optional ? ' <span class="bcpm-tag">' . esc_html__( 'Optional', 'bladecp-migrator' ) . '</span>' : '' ) . '</span></td>';
				echo '<td data-label="' . esc_attr( $value ) . '"><code class="bcpm-rvalue">' . esc_html( $target ) . '</code></td>';
				echo '<td class="bcpm-col-copy"><button type="button" class="bcpm-btn bcpm-btn-sm bcpm-copy" data-copy="' . esc_attr( $target ) . '" data-copied="' . esc_attr( $copied ) . '">' . $icons . '<span>' . esc_html( $copy ) . '</span></button></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
			}
			echo '</tbody></table>';
		}
		if ( $stalled ) {
			echo '<p class="bcpm-dns-note">' . Icons::svg( 'info' ) . '<span>' . esc_html__( 'We have not detected the domain pointing to BladeCP yet, and the automatic check has ended. This does not affect the migration. If you point it later and it does not show as connected, contact support.', 'bladecp-migrator' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
		}
		echo '<p class="screen-reader-text" aria-live="polite" id="bcpm-copy-status"></p></section>';
	}

	/**
	 * A date written the way the screen's language writes dates ("October 23,
	 * 2026", "23 de outubro de 2026"). The site's own date format follows the
	 * language the site was installed in, not the one this screen is read in.
	 *
	 * @param int $time Unix time.
	 */
	public static function date_text( int $time ): string {
		/* translators: PHP date() format for a full date, as in "October 23, 2026". Escape literal words with backslashes, as in "j \d\e F \d\e Y". */
		return (string) wp_date( _x( 'F j, Y', 'full date format', 'bladecp-migrator' ), $time );
	}

	/**
	 * A one-button POST form.
	 *
	 * @param string $action     Action name (without prefix).
	 * @param string $label      Button label.
	 * @param string $class      Button classes.
	 * @param array  $fields     Hidden fields.
	 * @param string $confirm    Confirmation question (JavaScript).
	 * @param array  $attrs      Extra button attributes.
	 * @param string $icon       Icon before the label (an Icons::PATHS key), or none.
	 * @param string $form_class Extra classes of the form (bcpm-push ends a row of actions).
	 */
	private static function action_form( string $action, string $label, string $class, array $fields = array(), string $confirm = '', array $attrs = array(), string $icon = '', string $form_class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( trim( 'bcpm-inline ' . $form_class ) ) . '"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'bladecp_migrator_' . $action ) . '">';
		wp_nonce_field( 'bladecp_migrator_' . $action );
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		$extra = '';
		foreach ( $attrs as $name => $value ) {
			$extra .= ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . $extra . '>' . Icons::svg( $icon ) . '<span>' . esc_html( $label ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above, the icon is a fixed SVG.
		echo '</form>';
	}

	/**
	 * "Contact support" link to the Hub support page, opened in a new tab.
	 *
	 * @param string $class CSS class ('' for a text link, 'bcpm-btn' in a row of actions).
	 */
	public static function support_link( string $class ): string {
		return '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( Config::hub_support_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span>' . esc_html__( 'Contact support', 'bladecp-migrator' ) . '</span>' . Icons::svg( 'external-link' ) . '</a>';
	}
}
