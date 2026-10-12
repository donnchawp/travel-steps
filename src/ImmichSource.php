<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos from Immich: the trip's album if it has one, else everything taken during it.
 */
class ImmichSource implements PhotoSource {
	private ImmichClient $client;

	public function __construct( ImmichClient $client ) {
		$this->client = $client;
	}

	public function photos( int $trip_id, int $start, int $end ) {
		return $this->client->photos( $start, $end, (string) get_post_meta( $trip_id, Trips::META_ALBUM, true ) );
	}

	public function image( string $photo_id, string $size ) {
		return $this->client->thumbnail( $photo_id, $size );
	}
}
