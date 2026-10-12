<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a trip's photos come from, for sync. The photo proxy finds each photo's image
 * itself: from WordPress for an upload, else from Immich (RestController::image()).
 */
interface PhotoSource {
	/**
	 * The trip's photos: those taken in $range (unix times, already widened for time
	 * zones), or all of the trip's own photos (an album, its uploads) whenever they
	 * were taken. Sync keeps the ones on the trip's days. $range is null when the trip
	 * has no dates; a source that needs them returns travel_steps_no_dates.
	 *
	 * Each row is:
	 * - id:      string, a lowercase 36-character UUID, as the photo route accepts (the
	 *            thumbnail cache is keyed by it alone);
	 * - local:   ?int, the wall-clock time it was taken, as a unix time read as UTC; null
	 *            if the photo doesn't say (sync leaves it out);
	 * - zoned:   bool, whether t is exact because the photo recorded its UTC offset;
	 * - t:       ?int, unix time it was taken when zoned, else local: sync works it out
	 *            with Days::resolve_times();
	 * - day:     string, Y-m-d where it was taken, empty if local is null;
	 * - lat/lon: float|null;
	 * - w/h:     int, as displayed (after rotation);
	 * - city, country, caption: string, may be empty;
	 * - fav:     bool;
	 * - camera:  string, make and model, empty for screenshots and downloads.
	 *
	 * @param array{0: int, 1: int}|null $range
	 * @return array<int, array>|\WP_Error Photo rows, in any order.
	 */
	public function photos( int $trip_id, ?array $range );
}
