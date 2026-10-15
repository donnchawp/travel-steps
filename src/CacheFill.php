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
	/**
	 * Cache the preview of every photo visitors can see on a trip that isn't cached yet.
	 */
	public static function run( int $trip_id ): void {
		foreach ( self::photo_ids( $trip_id ) as $asset ) {
			if ( ! RestController::cached( $asset, 'preview' ) ) {
				RestController::fetch( $trip_id, $asset, 'preview' );
			}
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
