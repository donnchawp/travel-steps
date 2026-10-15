<?php
/**
 * Deleting the plugin deletes the photos it cached, on every site of a network, and
 * the cron events that fill and sweep that cache. Trips, steps, settings, keys and the
 * photos uploaded to trips (uploads/travel-steps/) stay.
 *
 * WordPress runs this without loading the plugin, so the names are spelt out here:
 * RestController::cache_root(), CacheSweep::HOOK and CacheFill::HOOK.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( function () {
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
		wp_unschedule_hook( 'travel_steps_sweep_cache' );
		wp_unschedule_hook( 'travel_steps_fill_cache' );
		$root = wp_get_upload_dir()['basedir'] . '/travel-steps-cache';
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
