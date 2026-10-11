<?php

namespace TravelSteps;

/**
 * Pure geometry helpers. No WordPress dependencies so they can be tested standalone.
 *
 * Points are arrays whose first three entries are [ lat, lon, unix timestamp ].
 */
class Geo {
	const EARTH_RADIUS_M = 6371000;

	public static function distance( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lon = deg2rad( $lon2 - $lon1 );
		$a     = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lon / 2 ) ** 2;
		return 2 * self::EARTH_RADIUS_M * asin( min( 1, sqrt( $a ) ) );
	}

	/**
	 * Drop points within $radius_m of $home.
	 */
	public static function exclude_near( array $points, ?array $home ): array {
		if ( ! $home || $home['radius_m'] <= 0 ) {
			return $points;
		}
		return array_values(
			array_filter(
				$points,
				fn( $p ) => self::distance( $p[0], $p[1], $home['lat'], $home['lon'] ) > $home['radius_m']
			)
		);
	}

	/**
	 * Split a track into segments wherever consecutive points are further apart than
	 * $max_jump_m (flights, ferries, GPS off), so those can be drawn differently.
	 *
	 * @return array<int, array> List of point lists.
	 */
	public static function split_segments( array $points, float $max_jump_m = 20000 ): array {
		$segments = [];
		$current  = [];
		$prev     = null;
		foreach ( $points as $p ) {
			if ( $prev && self::distance( $prev[0], $prev[1], $p[0], $p[1] ) > $max_jump_m ) {
				$segments[] = $current;
				$current    = [];
			}
			$current[] = $p;
			$prev      = $p;
		}
		if ( $current ) {
			$segments[] = $current;
		}
		return $segments;
	}

	/**
	 * Iterative Douglas-Peucker on lat/lon using an equirectangular projection, which is
	 * plenty accurate for the short distances between GPS fixes.
	 *
	 * @param float $tolerance_m Maximum deviation in metres.
	 */
	public static function simplify( array $points, float $tolerance_m ): array {
		$count = count( $points );
		if ( $count < 3 ) {
			return $points;
		}
		$keep               = array_fill( 0, $count, false );
		$keep[0]            = true;
		$keep[ $count - 1 ] = true;
		$stack              = [ [ 0, $count - 1 ] ];

		while ( $stack ) {
			[ $first, $last ] = array_pop( $stack );
			$max_dist         = 0;
			$index            = 0;
			for ( $i = $first + 1; $i < $last; $i++ ) {
				$dist = self::perpendicular_distance( $points[ $i ], $points[ $first ], $points[ $last ] );
				if ( $dist > $max_dist ) {
					$max_dist = $dist;
					$index    = $i;
				}
			}
			if ( $max_dist > $tolerance_m ) {
				$keep[ $index ] = true;
				$stack[]        = [ $first, $index ];
				$stack[]        = [ $index, $last ];
			}
		}

		$out = [];
		foreach ( $points as $i => $p ) {
			if ( $keep[ $i ] ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private static function perpendicular_distance( array $p, array $a, array $b ): float {
		$cos = cos( deg2rad( $a[0] ) );
		$m   = M_PI / 180 * self::EARTH_RADIUS_M;
		$px  = ( $p[1] - $a[1] ) * $cos * $m;
		$py  = ( $p[0] - $a[0] ) * $m;
		$bx  = ( $b[1] - $a[1] ) * $cos * $m;
		$by  = ( $b[0] - $a[0] ) * $m;
		$len = $bx * $bx + $by * $by;
		if ( 0.0 === $len ) {
			return sqrt( $px * $px + $py * $py );
		}
		$t  = max( 0, min( 1, ( $px * $bx + $py * $by ) / $len ) );
		$dx = $px - $t * $bx;
		$dy = $py - $t * $by;
		return sqrt( $dx * $dx + $dy * $dy );
	}

	/**
	 * Index of the point closest in time to $t (binary search), or null if none within $max_gap.
	 */
	public static function nearest_in_time( array $points, int $t, int $max_gap ): ?int {
		$lo = 0;
		$hi = count( $points ) - 1;
		if ( $hi < 0 ) {
			return null;
		}
		while ( $lo < $hi ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( $points[ $mid ][2] < $t ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		$best = $lo;
		if ( $lo > 0 && abs( $points[ $lo - 1 ][2] - $t ) < abs( $points[ $lo ][2] - $t ) ) {
			$best = $lo - 1;
		}
		return abs( $points[ $best ][2] - $t ) <= $max_gap ? $best : null;
	}

	/**
	 * Metres along a track, gaps included: a flight with the phone off counts as a
	 * straight line.
	 */
	public static function track_length( array $points ): float {
		$total = 0.0;
		for ( $i = 1, $n = count( $points ); $i < $n; $i++ ) {
			$total += self::distance( $points[ $i - 1 ][0], $points[ $i - 1 ][1], $points[ $i ][0], $points[ $i ][1] );
		}
		return $total;
	}

	/**
	 * Drop GPS glitches from a track of [ lat, lon, timestamp ] fixes: any fix you
	 * couldn't have reached, without outrunning an airliner, from most of the two fixes
	 * on either side of it. Asking neighbours on both sides catches a bad first fix,
	 * or one after a long gap, which a check against the previous fix alone would
	 * accept before rejecting the real track after it.
	 */
	public static function drop_glitches( array $points, float $max_speed_ms = 300 ): array {
		$points = array_values( $points );
		$count  = count( $points );
		if ( $count < 3 ) {
			return $points; // Two fixes that disagree don't say which is wrong.
		}
		// $reach[ $k ][ $i ]: whether fixes $i and $i + $k can both be real.
		$reach = [ 1 => [], 2 => [] ];
		foreach ( [ 1, 2 ] as $k ) {
			for ( $i = 0; $i + $k < $count; $i++ ) {
				[ $a, $b ]        = [ $points[ $i ], $points[ $i + $k ] ];
				$reach[ $k ][ $i ] = self::distance( $a[0], $a[1], $b[0], $b[1] ) <= $max_speed_ms * max( 1, abs( $b[2] - $a[2] ) );
			}
		}
		$kept = [];
		foreach ( $points as $i => $point ) {
			$votes = 0;
			$agree = 0;
			foreach ( [ $reach[1][ $i - 1 ] ?? null, $reach[1][ $i ] ?? null, $reach[2][ $i - 2 ] ?? null, $reach[2][ $i ] ?? null ] as $vote ) {
				if ( null !== $vote ) {
					++$votes;
					$agree += (int) $vote;
				}
			}
			// A tie among four drops the fix: two fixes before a long gap vouch for
			// anything. Losing a real fix there costs metres; a bad one, thousands of km.
			if ( $agree * 2 > $votes || ( $agree * 2 === $votes && $votes < 4 ) ) {
				$kept[] = $point;
			}
		}
		return $kept;
	}
}
