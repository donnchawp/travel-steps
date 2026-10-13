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
		if ( ! $range && $this->needs_dates( $trip_id ) ) {
			return new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps' ) );
		}
		// The client ignores the range for an album.
		return $this->client->photos( $range[0] ?? 0, $range[1] ?? 0, self::album( $trip_id ) );
	}

	/**
	 * All Immich photos are picked by the trip's dates; an album's aren't.
	 */
	public function needs_dates( int $trip_id ): bool {
		return '' === self::album( $trip_id );
	}

	private static function album( int $trip_id ): string {
		return (string) get_post_meta( $trip_id, Trips::META_ALBUM, true );
	}

	public function image( string $photo_id, string $size ) {
		return $this->client->thumbnail( $photo_id, $size );
	}
}
