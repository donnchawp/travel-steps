<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sweeps the photo cache once a day: photos no trip or upload uses any more go, and so,
 * when an administrator sets an age, do photos cached longer ago than that, to be
 * fetched again when someone next views them.
 */
class CacheSweep {
	const HOOK = 'travel_steps_sweep_cache';
	// A photo cached this recently stays, used or not: a sync, or a cache fill, may have
	// just cached it for a trip whose photos aren't saved yet.
	const GRACE_S = DAY_IN_SECONDS;

	/**
	 * Sweep daily, unless that's scheduled already. Each site of a network sweeps its own
	 * cache, in its own uploads folder.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	public static function run(): void {
		$now     = time();
		$files   = self::in_use_files();
		$max_age = max( 0, (int) Settings::get( 'cache_days' ) ) * DAY_IN_SECONDS;
		foreach ( glob( RestController::cache_root() . '/*/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
			foreach ( scandir( $dir ) ?: [] as $name ) {
				$file = "$dir/$name";
				// Dot files and index.html are what keeps the cache private.
				if ( '.' === $name[0] || 'index.html' === $name || ! is_file( $file ) ) {
					continue;
				}
				$age = $now - (int) filemtime( $file );
				// A type goes with its photo, or on its own if the photo was never written.
				if ( str_ends_with( $name, '.type' ) ) {
					if ( $age > self::GRACE_S && ! file_exists( substr( $file, 0, -5 ) ) ) {
						wp_delete_file( $file );
					}
					continue;
				}
				// A half-written file (.tmp) is never in use.
				if ( ( ! isset( $files[ $file ] ) && $age > self::GRACE_S ) || ( $max_age && $age > $max_age ) ) {
					wp_delete_file( $file );
					wp_delete_file( "$file.type" );
				}
			}
		}
	}

	/**
	 * Where the photos in use are cached, at every size, as RestController::cache_file()
	 * names them: the photo ids aren't in the file names, so they can't be read back.
	 *
	 * @return array<string, true>
	 */
	private static function in_use_files(): array {
		$files = [];
		foreach ( self::in_use_ids() as $asset => $_ ) {
			foreach ( RestController::SIZES as $size ) {
				$files[ RestController::cache_file( (string) $asset, $size ) ] = true;
			}
		}
		return $files;
	}

	/**
	 * The ids of every photo in a trip, whatever its status (a binned one can be
	 * restored), and of every photo uploaded to one, synced or not.
	 *
	 * @return array<string, true>
	 */
	private static function in_use_ids(): array {
		global $wpdb;
		$ids = array_fill_keys( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Uploads::META_PHOTO_ID ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$trips = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Trips::TRIP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// One trip at a time, past the object cache, which would keep all of every trip's meta, routes included.
		foreach ( $trips as $trip_id ) {
			$photos = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $trip_id, Trips::META_PHOTOS ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $photos ) ? $photos : [] as $photo ) {
				if ( isset( $photo['id'] ) ) {
					$ids[ (string) $photo['id'] ] = true;
				}
			}
		}
		return $ids;
	}
}
