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

	public static function run(): void {
		$now = time();
		foreach ( glob( RestController::cache_root() . '/*/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
			foreach ( scandir( $dir ) ?: [] as $name ) {
				$file = "$dir/$name";
				if ( in_array( $name, [ '.', '..', 'index.html' ], true ) || ! is_file( $file ) || str_ends_with( $name, '.type' ) ) {
					continue;
				}
				if ( filemtime( $file ) < $now - self::GRACE_S ) {
					wp_delete_file( $file );
					wp_delete_file( "$file.type" );
				}
			}
		}
	}
}
