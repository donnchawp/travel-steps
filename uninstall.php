<?php
/**
 * Deleting the plugin deletes the photos it cached, on every site of a network, and
 * the cron events that fill and sweep that cache. Trips, steps, settings, keys and the
 * photos uploaded to trips (uploads/travel-steps/) stay.
 *
 * WordPress runs this without loading the plugin. Its autoloader is enough: the classes
 * do nothing until called, so the names below come from them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( function () {
	// Without it, the plugin never ran, so it left nothing to delete.
	if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		return;
	}
	require_once __DIR__ . '/vendor/autoload.php';
	$sites = is_multisite() ? get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) : [ 0 ];
	foreach ( $sites as $site ) {
		if ( $site ) {
			switch_to_blog( $site );
		}
		// A network deactivation only unschedules them on the site it ran on.
		wp_unschedule_hook( TravelSteps\CacheSweep::HOOK );
		wp_unschedule_hook( TravelSteps\CacheFill::HOOK );
		$root = TravelSteps\RestController::cache_root();
		if ( is_dir( $root ) && ! is_link( $root ) ) {
			$inside = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $inside as $file ) {
				if ( $file->isDir() && ! $file->isLink() ) {
					rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				} else {
					wp_delete_file( $file->getPathname() );
				}
			}
			rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		if ( $site ) {
			restore_current_blog();
		}
	}
} )();
