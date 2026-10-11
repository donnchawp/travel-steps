<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls a trip's route from Dawarich and photos from Immich, then creates or updates steps.
 *
 * Steps you've written stay yours: sync never touches a step's title or content, and
 * only moves auto-created steps' times and positions.
 */
class TripSync {
	const ROUTE_TOLERANCE_M = 25;
	const ROUTE_MAX_POINTS  = 8000;
	const PHOTO_MAX_GAP_S   = HOUR_IN_SECONDS;

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

		$photos = $this->immich->photos( $start, $end, (string) get_post_meta( $trip_id, Trips::META_ALBUM, true ) );
		if ( is_wp_error( $photos ) ) {
			return $photos;
		}
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

		$new_steps = $this->sync_steps( $trip_id, $track, $photos );
		$photos    = $this->assign_photos( $trip_id, $photos );

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
			'new_steps' => $new_steps,
			'points'    => count( $points ),
			'photos'    => count( $photos ),
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
	 * Detect stays and turn new ones into steps. Returns the number of steps created.
	 */
	private function sync_steps( int $trip_id, array $track, array $photos ): int {
		$radius   = (float) Settings::get( 'stay_radius_m' );
		$min_secs = (int) Settings::get( 'stay_min_minutes' ) * MINUTE_IN_SECONDS;
		$stays    = Geo::detect_stays( $track, $radius, $min_secs );
		$existing = Trips::steps( $trip_id, true );

		// Place manual steps (no coordinates yet) where you were at their post date.
		foreach ( $existing as $step ) {
			if ( '' === get_post_meta( $step->ID, Trips::META_LAT, true ) ) {
				$index = Geo::nearest_in_time( $track, Trips::arrived( $step ), 6 * HOUR_IN_SECONDS );
				if ( null !== $index ) {
					update_post_meta( $step->ID, Trips::META_LAT, $track[ $index ][0] );
					update_post_meta( $step->ID, Trips::META_LON, $track[ $index ][1] );
				}
			}
		}

		// Steps you've binned still count as matches, so sync doesn't bring them back.
		$binned = get_posts(
			[
				'post_type'      => Trips::STEP,
				'post_parent'    => $trip_id,
				'post_status'    => 'trash',
				'posts_per_page' => -1,
			]
		);

		$created = 0;
		foreach ( $stays as $stay ) {
			$match = $this->matching_step( array_merge( $existing, $binned ), $stay, $radius );
			if ( $match && 'trash' === $match->post_status ) {
				continue;
			}
			if ( $match ) {
				if ( get_post_meta( $match->ID, Trips::META_AUTO, true ) ) {
					$this->set_step_time( $match->ID, $stay['arrived'] );
					update_post_meta( $match->ID, Trips::META_DEPARTED, $stay['departed'] );
				}
				continue;
			}

			$step_id = wp_insert_post(
				[
					'post_type'   => Trips::STEP,
					'post_parent' => $trip_id,
					'post_title'  => $this->place_name( $stay, $track, $photos ),
					'post_status' => 'publish',
				],
				true
			);
			if ( is_wp_error( $step_id ) ) {
				continue;
			}
			$this->set_step_time( $step_id, $stay['arrived'] );
			update_post_meta( $step_id, Trips::META_LAT, round( $stay['lat'], 6 ) );
			update_post_meta( $step_id, Trips::META_LON, round( $stay['lon'], 6 ) );
			update_post_meta( $step_id, Trips::META_DEPARTED, $stay['departed'] );
			update_post_meta( $step_id, Trips::META_AUTO, 1 );
			$existing[] = get_post( $step_id );
			++$created;
		}
		return $created;
	}

	private function set_step_time( int $step_id, int $arrived ): void {
		wp_update_post(
			[
				'ID'            => $step_id,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $arrived ),
				'post_date'     => wp_date( 'Y-m-d H:i:s', $arrived ),
				'edit_date'     => true,
			]
		);
	}

	/**
	 * An existing step close to the stay in both place and time.
	 */
	private function matching_step( array $steps, array $stay, float $radius ): ?\WP_Post {
		foreach ( $steps as $step ) {
			$lat = get_post_meta( $step->ID, Trips::META_LAT, true );
			if ( '' === $lat ) {
				continue;
			}
			$near    = Geo::distance( (float) $lat, (float) get_post_meta( $step->ID, Trips::META_LON, true ), $stay['lat'], $stay['lon'] ) <= 2 * $radius;
			$arrived = Trips::arrived( $step );
			$during  = $arrived >= $stay['arrived'] - 3 * HOUR_IN_SECONDS && $arrived <= $stay['departed'] + 3 * HOUR_IN_SECONDS;
			if ( $near && $during ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * Name a stay after the most common city among its photos, then its location points.
	 */
	private function place_name( array $stay, array $track, array $photos ): string {
		$names = [];
		foreach ( $photos as $photo ) {
			if ( $photo['t'] >= $stay['arrived'] && $photo['t'] <= $stay['departed'] && $photo['city'] ) {
				$names[] = $photo['city'];
			}
		}
		if ( ! $names ) {
			for ( $i = $stay['first']; $i <= $stay['last']; $i++ ) {
				if ( ! empty( $track[ $i ][3] ) ) {
					$names[] = $track[ $i ][3];
				}
			}
		}
		if ( $names ) {
			$counts = array_count_values( $names );
			arsort( $counts );
			return (string) array_key_first( $counts );
		}
		/* translators: %s: date */
		return sprintf( __( 'Stop on %s', 'travel-steps' ), wp_date( get_option( 'date_format' ), $stay['arrived'] ) );
	}

	/**
	 * Attach each photo to the step you were at when you took it: the latest step that
	 * started before the photo (Polarsteps-style), or the first step for earlier photos.
	 */
	private function assign_photos( int $trip_id, array $photos ): array {
		$steps = array_values(
			array_filter(
				Trips::steps( $trip_id, true ),
				fn( $s ) => '' !== get_post_meta( $s->ID, Trips::META_LAT, true )
			)
		);
		$times = array_map( [ Trips::class, 'arrived' ], $steps );

		foreach ( $photos as &$photo ) {
			$photo['step'] = $steps ? $steps[0]->ID : 0;
			foreach ( $times as $i => $arrived ) {
				if ( $arrived <= $photo['t'] ) {
					$photo['step'] = $steps[ $i ]->ID;
				}
			}
		}
		return $photos;
	}

	/**
	 * Simplify each segment, tightening the tolerance until the route fits the budget.
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
