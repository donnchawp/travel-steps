<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills the photo cache for an Immich trip in the background, so a trip keeps working
 * when Immich, or its author's key, doesn't: the proxy then serves every photo visitors
 * can see from the cache, and makes medium from the cached preview.
 */
class CacheFill {
	const HOOK = 'travel_steps_fill_cache';
	// How long a run fetches for when PHP has a time limit, before leaving the rest to another.
	const BUDGET_S = 20;
	// Fetches failing in a row that stop a run: a revoked key, or Immich down.
	const MAX_FAILURES = 3;

	/**
	 * Cache the preview of every photo visitors can see on a trip that isn't cached yet.
	 * Under a PHP time limit, a run stops after its budget, or half the limit if that's
	 * less, and schedules another for the rest. Without one (WP-Cron run by PHP CLI), it
	 * carries on to the end: the next run could be a whole cron interval away.
	 *
	 * @param int|null $time_limit PHP's max_execution_time, 0 for none; null reads it.
	 * @param float    $budget     Seconds a run fetches for under a time limit.
	 */
	public static function run( int $trip_id, ?int $time_limit = null, float $budget = self::BUDGET_S ): void {
		$time_limit ??= (int) ini_get( 'max_execution_time' );
		$budget       = min( $budget, $time_limit / 2 );
		$start        = microtime( true );
		$left         = array_values( array_filter( self::photo_ids( $trip_id ), fn( $asset ) => ! RestController::cached( $asset, 'preview' ) ) );
		$failures     = 0;
		foreach ( $left as $i => $asset ) {
			$failures = is_wp_error( RestController::fetch( $trip_id, $asset, 'preview' ) ) ? $failures + 1 : 0;
			if ( $failures >= self::MAX_FAILURES ) {
				return;
			}
			if ( $time_limit > 0 && microtime( true ) - $start >= $budget && isset( $left[ $i + 1 ] ) ) {
				self::schedule( $trip_id );
				return;
			}
		}
	}

	/**
	 * Fill a trip's cache in the background, unless that's already due.
	 */
	public static function schedule( int $trip_id ): void {
		if ( ! wp_next_scheduled( self::HOOK, [ $trip_id ] ) ) {
			wp_schedule_single_event( time(), self::HOOK, [ $trip_id ] );
		}
	}

	/**
	 * Every photo visitors can see once its step is published: the hidden ones left out,
	 * on every step, drafts included, and the chosen cover.
	 *
	 * @return string[]
	 */
	private static function photo_ids( int $trip_id ): array {
		$ids = [];
		foreach ( Trips::photos_by_step( $trip_id, Trips::steps( $trip_id, true ) ) as $groups ) {
			foreach ( array_merge( $groups['shown'], $groups['rest'] ) as $photo ) {
				$ids[ $photo['id'] ] = true;
			}
		}
		$cover = Trips::cover( $trip_id );
		if ( '' !== $cover ) {
			$ids[ $cover ] = true;
		}
		return array_keys( $ids );
	}
}
