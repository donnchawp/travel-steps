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
		if ( ! self::fills( $trip_id ) ) {
			return;
		}
		$time_limit ??= (int) ini_get( 'max_execution_time' );
		$deadline     = $time_limit > 0 ? microtime( true ) + min( $budget, $time_limit / 2 ) : INF;
		// Every photo visitors can see once its step is published, drafts included.
		$visible  = array_keys( Trips::visible_photo_ids( $trip_id, Trips::steps( $trip_id, true ) ) );
		// A photo uploaded to the trip, left over from before it switched to Immich, is
		// served from the upload, so it isn't asked of Immich.
		$left = array_filter( $visible, fn( $asset ) => ! RestController::cached( $asset, 'preview' ) && ! Uploads::attachment( $trip_id, $asset ) );
		// In a new order each run, so photos that always fail (deleted in Immich since the
		// sync, or with no preview made), or one that always fetches slowly, can't stop
		// every run at the same place. shuffle() also numbers them from 0 again.
		shuffle( $left );
		$failures   = 0;
		$cached_any = false;
		foreach ( $left as $i => $asset ) {
			// What was fetched but couldn't be stored (a full disk) is a failure too.
			$cached     = ! is_wp_error( RestController::fetch( $trip_id, $asset, 'preview' ) ) && RestController::cached( $asset, 'preview' );
			$cached_any = $cached_any || $cached;
			$failures   = $cached ? 0 : $failures + 1;
			if ( $failures >= self::MAX_FAILURES ) {
				return;
			}
			// A run that cached nothing by its deadline stops: a fetch slower than the
			// budget (Immich unreachable, the connection hanging) would be retried forever.
			if ( microtime( true ) >= $deadline && isset( $left[ $i + 1 ] ) ) {
				if ( $cached_any ) {
					self::schedule( $trip_id );
				}
				return;
			}
		}
	}

	/**
	 * Fill a trip's cache in the background, unless that's already due.
	 */
	public static function schedule( int $trip_id ): void {
		if ( self::fills( $trip_id ) && ! wp_next_scheduled( self::HOOK, [ $trip_id ] ) ) {
			wp_schedule_single_event( time(), self::HOOK, [ $trip_id ] );
		}
	}

	/**
	 * Whether a trip's photos are cached ahead: those of an Immich trip. A trip of
	 * uploads has its copies here already.
	 */
	private static function fills( int $trip_id ): bool {
		return Trips::TRIP === get_post_type( $trip_id ) && ! Trips::uses_uploads( $trip_id );
	}
}
