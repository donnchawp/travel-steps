<?php

namespace TravelSteps;

/**
 * Chooses which of a step's photos to show. Pure functions, no WordPress dependencies.
 *
 * Favourites are the signal: if a step has any, exactly those are shown. A step with
 * none gets an automatic pick spread across the time spent there, with bursts of
 * near-identical shots collapsed to one. Pinned photos are always added; hidden ones
 * never shown. Star ratings are deliberately ignored (they're often workflow states).
 */
class Selection {
	const BURST_GAP_S = 15;

	/**
	 * @param array $photos Photo rows for one step, sorted by time (see ImmichClient::normalise).
	 * @param array $pinned Asset ids to always show.
	 * @param array $hidden Asset ids to never show.
	 * @param int   $limit  How many to auto-pick when the step has no favourites.
	 * @return array{shown: array, rest: array, hidden: array}
	 */
	public static function for_step( array $photos, array $pinned, array $hidden, int $limit ): array {
		$pinned  = array_flip( $pinned );
		$hidden  = array_flip( $hidden );
		$visible = [];
		$gone    = [];
		foreach ( $photos as $photo ) {
			if ( isset( $hidden[ $photo['id'] ] ) ) {
				$gone[] = $photo;
			} else {
				$visible[] = $photo;
			}
		}

		$favourites = array_values( array_filter( $visible, fn( $p ) => ! empty( $p['fav'] ) ) );
		$base       = $favourites ? $favourites : self::spread( $visible, $limit );
		$chosen     = array_flip( array_column( $base, 'id' ) );
		foreach ( $visible as $photo ) {
			if ( isset( $pinned[ $photo['id'] ] ) ) {
				$chosen[ $photo['id'] ] = true;
			}
		}

		$shown = [];
		$rest  = [];
		foreach ( $visible as $photo ) {
			if ( isset( $chosen[ $photo['id'] ] ) ) {
				$shown[] = $photo;
			} else {
				$rest[] = $photo;
			}
		}
		return [
			'shown'  => $shown,
			'rest'   => $rest,
			'hidden' => $gone,
		];
	}

	/**
	 * Collapse bursts, then take $limit photos evenly spaced through the step.
	 */
	public static function spread( array $photos, int $limit ): array {
		if ( $limit <= 0 || ! $photos ) {
			return [];
		}
		$reps  = array_map( fn( $burst ) => $burst[ intdiv( count( $burst ) - 1, 2 ) ], self::bursts( $photos ) );
		$count = count( $reps );
		if ( $count <= $limit ) {
			return $reps;
		}
		$out = [];
		for ( $i = 0; $i < $limit; $i++ ) {
			$out[] = $reps[ (int) floor( ( $i + 0.5 ) * $count / $limit ) ];
		}
		return $out;
	}

	/**
	 * Group photos taken within BURST_GAP_S of the previous one.
	 *
	 * @return array<int, array> List of bursts, each a list of photos.
	 */
	public static function bursts( array $photos ): array {
		$bursts = [];
		$prev   = null;
		foreach ( $photos as $photo ) {
			if ( null !== $prev && $photo['t'] - $prev <= self::BURST_GAP_S ) {
				$bursts[ count( $bursts ) - 1 ][] = $photo;
			} else {
				$bursts[] = [ $photo ];
			}
			$prev = $photo['t'];
		}
		return $bursts;
	}
}
