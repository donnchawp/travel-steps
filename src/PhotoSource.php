<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a trip's photos come from. Sync asks it for the photos, the photo proxy for
 * their images; nothing else needs to know which source a trip uses.
 */
interface PhotoSource {
	/**
	 * Photos taken between two unix timestamps, or all of the trip's own photos (an
	 * Immich album) whenever they were taken: sync keeps the ones on the trip's days.
	 *
	 * Each row is:
	 * - id:      string, a 36-character UUID (the thumbnail cache is keyed by it alone);
	 * - t:       int, unix time it was taken;
	 * - day:     string, Y-m-d where it was taken;
	 * - lat/lon: float|null;
	 * - w/h:     int, as displayed (after rotation);
	 * - city, country, caption: string, may be empty;
	 * - fav:     bool;
	 * - camera:  string, make and model, empty for screenshots and downloads.
	 *
	 * @return array<int, array>|\WP_Error Photo rows, oldest first.
	 */
	public function photos( int $trip_id, int $start, int $end );

	/**
	 * A photo's image at a size: "thumbnail" or "preview". The proxy makes "medium" from the preview.
	 *
	 * @return array{body: string, type: string}|\WP_Error
	 */
	public function image( string $photo_id, string $size );
}
