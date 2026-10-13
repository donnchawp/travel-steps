<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos from Immich: the trip's album if it has one, whatever the trip's dates, else
 * everything taken during it.
 */
class ImmichSource implements PhotoSource {
	private ImmichClient $client;

	public function __construct( ImmichClient $client ) {
		$this->client = $client;
	}

	public function photos( int $trip_id, ?array $range ) {
		$album = (string) get_post_meta( $trip_id, Trips::META_ALBUM, true );
		if ( ! $range && '' === $album ) {
			return new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps' ) );
		}
		[ $start, $end ] = $range ?? [ 0, 0 ]; // An album is fetched whole.
		return $this->client->photos( $start, $end, $album );
	}

	public function image( string $photo_id, string $size ) {
		return $this->client->thumbnail( $photo_id, $size );
	}
}
