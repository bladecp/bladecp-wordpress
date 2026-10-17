<?php
/**
 * Uninstall: removes every option, transient, scheduled event and file the
 * plugin created. Self-contained on purpose (no plugin classes loaded).
 *
 * @package BladeCP\Migrator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes a working folder (only folders named bladecp-migrator-*).
 *
 * @param string $dir Folder.
 */
function bladecp_migrator_uninstall_remove_tree( $dir ) {
	$dir = rtrim( $dir, '/\\' );
	if ( 0 !== strpos( basename( $dir ), 'bladecp-migrator-' ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

global $wpdb;

// Working folders (the current one and any left by an older token).
$bladecp_migrator_uploads = wp_upload_dir( null, false );
if ( ! empty( $bladecp_migrator_uploads['basedir'] ) && is_dir( $bladecp_migrator_uploads['basedir'] ) ) {
	foreach ( (array) glob( trailingslashit( $bladecp_migrator_uploads['basedir'] ) . 'bladecp-migrator-*', GLOB_ONLYDIR ) as $bladecp_migrator_dir ) {
		bladecp_migrator_uninstall_remove_tree( (string) $bladecp_migrator_dir );
	}
}

// Options (the key, the job, the lock, the measurement, the folder token).
foreach ( array( 'bladecp_migrator_connection', 'bladecp_migrator_job', 'bladecp_migrator_lock', 'bladecp_migrator_measure', 'bladecp_migrator_work_dir' ) as $bladecp_migrator_option ) {
	delete_option( $bladecp_migrator_option );
}

// Transients: per-user connection attempts and messages, remote cache, measuring flag.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup by prefix.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bladecp_migrator_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bladecp_migrator_' ) . '%'
	)
);
// With a persistent object cache, transients live in the cache instead.
delete_transient( 'bladecp_migrator_remote' );
delete_transient( 'bladecp_migrator_measuring' );
foreach ( get_users(
	array(
		'capability' => 'manage_options',
		'fields'     => 'ID',
	)
) as $bladecp_migrator_user_id ) {
	delete_transient( 'bladecp_migrator_connect_' . (int) $bladecp_migrator_user_id );
	delete_transient( 'bladecp_migrator_flash_' . (int) $bladecp_migrator_user_id );
}

// Scheduled background step.
wp_clear_scheduled_hook( 'bladecp_migrator_tick' );
