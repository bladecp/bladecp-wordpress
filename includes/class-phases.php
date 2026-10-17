<?php
/**
 * Remote migration phases in plain language.
 *
 * Phase list: draft, uploading, queued, parsing, discovery_review,
 * provisioning, importing, validating, awaiting_approval, syncing,
 * cutover_pending, live, failed, cancelled, cutover_stalled.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Phase presentation.
 */
final class Phases {

	const CANCELLABLE = array( 'draft', 'uploading', 'queued', 'parsing', 'discovery_review', 'provisioning', 'importing', 'validating', 'awaiting_approval' );
	const TERMINAL    = array( 'live', 'failed', 'cancelled', 'cutover_stalled' );
	const PRE_UPLOAD  = array( 'draft', 'uploading' );
	const YOUR_TURN   = array( 'discovery_review', 'awaiting_approval' );
	const COMPLETE    = array( 'cutover_pending', 'cutover_stalled', 'live' );
	const STAGE_COUNT = 6;

	/**
	 * All known phases.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( 'draft', 'uploading', 'queued', 'parsing', 'discovery_review', 'provisioning', 'importing', 'validating', 'awaiting_approval', 'syncing', 'cutover_pending', 'live', 'failed', 'cancelled', 'cutover_stalled' );
	}

	/**
	 * Short label.
	 *
	 * @param string $phase Phase.
	 */
	public static function label( string $phase ): string {
		switch ( $phase ) {
			case 'draft':
				return __( 'Waiting for your site package', 'bladecp-migrator' );
			case 'uploading':
				return __( 'Receiving your site package', 'bladecp-migrator' );
			case 'queued':
				return __( 'Waiting in line', 'bladecp-migrator' );
			case 'parsing':
				return __( 'Reading your site package', 'bladecp-migrator' );
			case 'discovery_review':
				return __( 'Confirm your domain', 'bladecp-migrator' );
			case 'provisioning':
				return __( 'Preparing your new server', 'bladecp-migrator' );
			case 'importing':
				return __( 'Copying your files and database', 'bladecp-migrator' );
			case 'validating':
				return __( 'Checking the migrated site', 'bladecp-migrator' );
			case 'awaiting_approval':
				return __( 'Ready for your review', 'bladecp-migrator' );
			case 'syncing':
				return __( 'Applying your approval', 'bladecp-migrator' );
			case 'cutover_pending':
			case 'cutover_stalled':
			case 'live':
				// Pointing the domain is optional: the migration is complete here.
				return __( 'Migration completed', 'bladecp-migrator' );
			case 'failed':
				return __( 'The migration stopped', 'bladecp-migrator' );
			case 'cancelled':
				return __( 'Migration cancelled', 'bladecp-migrator' );
		}
		return __( 'Working on your migration', 'bladecp-migrator' );
	}

	/**
	 * One-sentence explanation.
	 *
	 * @param string $phase Phase.
	 */
	public static function description( string $phase ): string {
		switch ( $phase ) {
			case 'draft':
				return __( 'BladeCP is ready. Click "Start migration" to build and send the package.', 'bladecp-migrator' );
			case 'uploading':
				return __( 'The package is being sent to BladeCP.', 'bladecp-migrator' );
			case 'queued':
			case 'parsing':
				return __( 'BladeCP received the package and is reading it. This usually takes a few minutes.', 'bladecp-migrator' );
			case 'discovery_review':
				return __( 'Check the domain BladeCP found in your site and confirm it to continue.', 'bladecp-migrator' );
			case 'provisioning':
			case 'importing':
			case 'validating':
				return __( 'BladeCP is setting up a copy of your site. Your current site stays online and unchanged.', 'bladecp-migrator' );
			case 'awaiting_approval':
				return __( 'Open the preview, check that everything looks right, then approve the migration.', 'bladecp-migrator' );
			case 'syncing':
				return __( 'BladeCP is preparing your site to answer on your own domain.', 'bladecp-migrator' );
			case 'cutover_pending':
			case 'cutover_stalled':
			case 'live':
				return __( 'Your site is already on your BladeCP server.', 'bladecp-migrator' );
			case 'failed':
				return __( 'BladeCP could not finish the migration. Your current site was not changed.', 'bladecp-migrator' );
			case 'cancelled':
				return __( 'Nothing was changed on your current site. Connect again to start a new migration.', 'bladecp-migrator' );
		}
		return '';
	}

	/**
	 * Stage of the 6-stage trail (0 package, 1 upload, 2 analysis, 3 import,
	 * 4 preview, 5 complete), from the phase BladeCP reports. Once approved the
	 * migration is complete: pointing the domain is optional, never a stage, so
	 * cutover_pending, cutover_stalled and live give STAGE_COUNT (every stage
	 * done). A phase whose stage is not known (failed, cancelled or a phase this
	 * version does not know) gives null, so the screen shows no trail instead of
	 * a guess.
	 *
	 * @param string $phase Phase.
	 */
	public static function stage( string $phase ): ?int {
		switch ( $phase ) {
			case 'draft':
				return 0;
			case 'uploading':
				return 1;
			case 'queued':
			case 'parsing':
			case 'discovery_review':
				return 2;
			case 'provisioning':
			case 'importing':
			case 'validating':
				return 3;
			case 'awaiting_approval':
			case 'syncing':
				return 4;
		}
		return self::is_complete( $phase ) ? self::STAGE_COUNT : null;
	}

	/**
	 * Whether the migration is complete: approved and on the BladeCP server.
	 * Pointing the domain afterwards is optional.
	 *
	 * @param string $phase Phase.
	 */
	public static function is_complete( string $phase ): bool {
		return in_array( $phase, self::COMPLETE, true );
	}

	/**
	 * Stage of the local package work: building it is stage 0, sending it is
	 * stage 1. A failed job reports the stage it failed in; null when unknown.
	 *
	 * @param string $stage        Local job stage (Migration_Job::STAGE_*).
	 * @param string $failed_stage Stage a failed job stopped in.
	 */
	public static function local_stage( string $stage, string $failed_stage = '' ): ?int {
		if ( 'failed' === $stage ) {
			return '' === $failed_stage || 'failed' === $failed_stage ? null : self::local_stage( $failed_stage );
		}
		switch ( $stage ) {
			case 'scanning':
			case 'dumping':
			case 'packing':
				return 0;
			case 'uploading':
			case 'done':
				return 1;
		}
		return null;
	}

	/**
	 * Names of the 6 stages, in order.
	 *
	 * @return string[]
	 */
	public static function stage_labels(): array {
		return array(
			__( 'Package', 'bladecp-migrator' ),
			__( 'Upload', 'bladecp-migrator' ),
			__( 'Analysis', 'bladecp-migrator' ),
			__( 'Import', 'bladecp-migrator' ),
			__( 'Preview', 'bladecp-migrator' ),
			/* translators: last stage of the migration trail, as in "Stage 6 of 6: Complete". */
			__( 'Complete', 'bladecp-migrator' ),
		);
	}

	/**
	 * Tone of the phase on the screen: success (complete), danger (stopped),
	 * neutral (cancelled), warning (waiting for the person) or info (BladeCP is
	 * working).
	 *
	 * @param string $phase Phase.
	 */
	public static function tone( string $phase ): string {
		if ( self::is_complete( $phase ) ) {
			return 'success';
		}
		if ( 'failed' === $phase ) {
			return 'danger';
		}
		if ( 'cancelled' === $phase ) {
			return 'neutral';
		}
		return self::needs_action( $phase ) ? 'warning' : 'info';
	}

	/**
	 * Whether the phase waits for something only the person can do.
	 *
	 * @param string $phase Phase.
	 */
	public static function needs_action( string $phase ): bool {
		return in_array( $phase, self::YOUR_TURN, true );
	}

	/**
	 * Whether BladeCP is working on the migration right now.
	 *
	 * @param string $phase Phase.
	 */
	public static function is_working( string $phase ): bool {
		return in_array( $phase, array( 'uploading', 'queued', 'parsing', 'provisioning', 'importing', 'validating', 'syncing' ), true );
	}

	/**
	 * Whether the person can cancel in this phase.
	 *
	 * @param string $phase Phase.
	 */
	public static function can_cancel( string $phase ): bool {
		return in_array( $phase, self::CANCELLABLE, true );
	}

	/**
	 * Whether the remote migration still waits for the package.
	 *
	 * @param string $phase Phase.
	 */
	public static function accepts_package( string $phase ): bool {
		return in_array( $phase, self::PRE_UPLOAD, true );
	}
}
