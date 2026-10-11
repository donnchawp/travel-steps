<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls a trip's route from Dawarich and photos from Immich, then creates steps.
 *
 * A step is a day: each day with favourites gets a draft step, seeded once with a
 * heading and a place tag per place. After that the step is yours: sync never touches its title,
 * story, date or status, only its map position.
 */
class TripSync {
	const ROUTE_TOLERANCE_M = 25;
	const ROUTE_MAX_POINTS  = 8000;
	const PHOTO_MAX_GAP_S   = HOUR_IN_SECONDS;
	// The widest UTC offset: a photo taken on a trip day can be this far outside it in the site's zone.
	const ZONE_MARGIN_S     = 14 * HOUR_IN_SECONDS;

	private DawarichClient $dawarich;
	private ImmichClient $immich;

	public function __construct( ?DawarichClient $dawarich = null, ?ImmichClient $immich = null ) {
		$this->dawarich = $dawarich ?? new DawarichClient();
		$this->immich   = $immich ?? new ImmichClient();
	}

	/**
	 * @return array{new_steps: int, points: int, photos: int}|\WP_Error
	 */
	public function sync( int $trip_id ) {
		// add_option() is an atomic insert, so a double-clicked sync can't create a day twice.
		$lock = 'travel_steps_sync_lock_' . $trip_id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 10 * MINUTE_IN_SECONDS ) {
				return new \WP_Error( 'travel_steps_sync_running', __( 'A sync of this trip is already running.', 'travel-steps' ) );
			}
			update_option( $lock, time(), false ); // Left behind by a sync that died.
		}
		try {
			return $this->run( $trip_id );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * @return array{new_steps: int, points: int, photos: int}|\WP_Error
	 */
	private function run( int $trip_id ) {
		$range = Trips::range( $trip_id );
		if ( ! $range ) {
			return new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps' ) );
		}
		[ $start, $end ] = $range;
		$home            = Settings::home();

		$points = $this->dawarich->points( $start, $end );
		if ( is_wp_error( $points ) ) {
			return $points;
		}
		$points = Geo::exclude_near( $points, $home );

		// Trip dates are local to wherever you were, so ask for more and keep photos by local day.
		$photos = $this->immich->photos( $start - self::ZONE_MARGIN_S, $end + self::ZONE_MARGIN_S, (string) get_post_meta( $trip_id, Trips::META_ALBUM, true ) );
		if ( is_wp_error( $photos ) ) {
			return $photos;
		}
		$first  = (string) get_post_meta( $trip_id, Trips::META_START, true );
		$last   = (string) get_post_meta( $trip_id, Trips::META_END, true );
		$photos = array_values( array_filter( $photos, fn( $p ) => $p['day'] >= $first && $p['day'] <= $last ) );
		$photos = $this->locate_photos( $photos, $points, $home );

		// No location history for these dates? Fall back to the photos' own positions.
		$track = $points;
		if ( ! $track ) {
			foreach ( $photos as $photo ) {
				if ( null !== $photo['lat'] ) {
					$track[] = [ $photo['lat'], $photo['lon'], $photo['t'], $photo['city'], $photo['country'] ];
				}
			}
		}

		[ $new_steps, $skipped_days ] = $this->sync_steps( $trip_id, $track, $photos );

		$segments = Geo::split_segments( $track );
		$route    = $this->simplify_route( $segments );
		update_post_meta( $trip_id, Trips::META_ROUTE, wp_slash( wp_json_encode( $route ) ) );
		update_post_meta( $trip_id, Trips::META_PHOTOS, wp_slash( wp_json_encode( $photos ) ) );

		$distance = 0.0;
		foreach ( $segments as $segment ) {
			$distance += Geo::track_length( $segment );
		}
		$countries = array_values( array_unique( array_filter( array_merge( array_column( $track, 4 ), array_column( $photos, 'country' ) ) ) ) );
		update_post_meta(
			$trip_id,
			Trips::META_STATS,
			[
				'distance_km' => round( $distance / 1000 ),
				'points'      => count( $points ),
				'photos'      => count( $photos ),
				'countries'   => $countries,
			]
		);
		update_post_meta( $trip_id, Trips::META_SYNCED, time() );

		return [
			'new_steps'    => $new_steps,
			'skipped_days' => $skipped_days,
			'points'       => count( $points ),
			'photos'       => count( $photos ),
		];
	}

	/**
	 * Give photos without GPS the position you were at when they were taken, and drop
	 * any taken near home. Images with no camera in their EXIF (screenshots, saved
	 * downloads) are dropped too, unless you favourited them.
	 */
	private function locate_photos( array $photos, array $points, ?array $home ): array {
		$out = [];
		foreach ( $photos as $photo ) {
			if ( '' === $photo['camera'] && ! $photo['fav'] ) {
				continue;
			}
			if ( null === $photo['lat'] ) {
				$index = Geo::nearest_in_time( $points, $photo['t'], self::PHOTO_MAX_GAP_S );
				if ( null !== $index ) {
					$photo['lat'] = $points[ $index ][0];
					$photo['lon'] = $points[ $index ][1];
				}
			}
			if ( $home && null !== $photo['lat'] && Geo::distance( $photo['lat'], $photo['lon'], $home['lat'], $home['lon'] ) <= $home['radius_m'] ) {
				continue;
			}
			$out[] = $photo;
		}
		return $out;
	}

	/**
	 * Create a draft step for each new day with favourites, and position every step.
	 *
	 * @return array{0: int, 1: int} Steps created, and days with favourites skipped
	 *                               because their step is in the trash.
	 */
	private function sync_steps( int $trip_id, array $track, array $photos ): array {
		$radius = (float) Settings::get( 'place_radius_km' ) * 1000;
		$by_day = [];
		foreach ( $photos as $photo ) {
			$by_day[ $photo['day'] ][] = $photo;
		}

		$steps = Trips::steps( $trip_id, true );
		// Steps you've binned still hold their day, so sync doesn't bring them back.
		$binned = get_posts(
			[
				'post_type'      => Trips::STEP,
				'post_parent'    => $trip_id,
				'post_status'    => 'trash',
				'posts_per_page' => -1,
			]
		);
		$taken = [];
		foreach ( $steps as $step ) {
			$taken[ Trips::day( $step ) ] = true;
			$day = (string) get_post_meta( $step->ID, Trips::META_DAY, true );
			if ( '' !== $day ) {
				$taken[ $day ] = true;
			}
		}
		$in_trash = [];
		foreach ( $binned as $step ) {
			$day = (string) get_post_meta( $step->ID, Trips::META_DAY, true );
			if ( '' !== $day ) {
				$in_trash[ $day ] = true;
			}
		}

		// Positions are derived data, so every step's is refreshed; titles and stories never are.
		foreach ( $steps as $step ) {
			$this->place_step( $step, $by_day[ Trips::day( $step ) ] ?? [], $track );
		}

		$first   = (string) get_post_meta( $trip_id, Trips::META_START, true );
		$created = 0;
		$skipped = 0;
		foreach ( $by_day as $day => $day_photos ) {
			$favourites = array_values( array_filter( $day_photos, fn( $p ) => ! empty( $p['fav'] ) ) );
			if ( isset( $taken[ $day ] ) || ! $favourites ) {
				continue;
			}
			if ( isset( $in_trash[ $day ] ) ) {
				++$skipped;
				continue;
			}
			$places = Days::places( $favourites, $radius );
			$date   = $day . ' 12:00:00';
			$step_id = wp_insert_post(
				[
					'post_type'     => Trips::STEP,
					'post_parent'   => $trip_id,
					/* translators: %d: day number of the trip */
					'post_title'    => Days::title( $places, sprintf( __( 'Day %d', 'travel-steps' ), Days::day_number( $first, $day ) ) ),
					'post_content'  => Days::seed_content( $places, __( 'Somewhere new', 'travel-steps' ) ),
					'post_status'   => 'draft',
					'post_date'     => $date,
					'post_date_gmt' => get_gmt_from_date( $date ),
					'edit_date'     => true,
				],
				true
			);
			if ( is_wp_error( $step_id ) ) {
				continue;
			}
			update_post_meta( $step_id, Trips::META_DAY, $day );
			update_post_meta( $step_id, Trips::META_AUTO, 1 );
			wp_set_object_terms( $step_id, array_values( array_unique( array_filter( array_column( $places, 'city' ) ) ) ), Trips::PLACE );
			$this->place_step( get_post( $step_id ), $day_photos, $track );
			++$created;
		}
		return [ $created, $skipped ];
	}

	/**
	 * Put a step at its day's first located favourite, else its first located photo,
	 * else wherever Dawarich says you were around its post date.
	 */
	private function place_step( \WP_Post $step, array $day_photos, array $track ): void {
		$located    = array_values( array_filter( $day_photos, fn( $p ) => null !== $p['lat'] ) );
		$favourites = array_values( array_filter( $located, fn( $p ) => ! empty( $p['fav'] ) ) );
		$photo      = $favourites[0] ?? $located[0] ?? null;
		if ( $photo ) {
			[ $lat, $lon ] = [ $photo['lat'], $photo['lon'] ];
		} else {
			$index = Geo::nearest_in_time( $track, Trips::arrived( $step ), 12 * HOUR_IN_SECONDS );
			if ( null === $index ) {
				return;
			}
			[ $lat, $lon ] = [ $track[ $index ][0], $track[ $index ][1] ];
		}
		update_post_meta( $step->ID, Trips::META_LAT, round( $lat, 6 ) );
		update_post_meta( $step->ID, Trips::META_LON, round( $lon, 6 ) );
	}

	/**
	 * Simplify each segment, loosening the tolerance until the route fits the budget.
	 *
	 * @return array<int, array<int, array{0: float, 1: float}>>
	 */
	private function simplify_route( array $segments ): array {
		$tolerance = self::ROUTE_TOLERANCE_M;
		do {
			$route = [];
			$total = 0;
			foreach ( $segments as $segment ) {
				$simple = array_map(
					fn( $p ) => [ round( $p[0], 5 ), round( $p[1], 5 ) ],
					Geo::simplify( $segment, $tolerance )
				);
				$total  += count( $simple );
				$route[] = $simple;
			}
			$tolerance *= 2;
		} while ( $total > self::ROUTE_MAX_POINTS && $tolerance < 5000 );
		return $route;
	}
}
