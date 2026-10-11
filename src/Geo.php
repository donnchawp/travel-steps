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
	 * Find places where the track lingered: runs of points staying within $radius_m of
	 * their running centroid for at least $min_seconds. Consecutive stays at the same
	 * place (a hotel either side of a day trip that never left the radius) are merged.
	 *
	 * @return array<int, array{lat: float, lon: float, arrived: int, departed: int, first: int, last: int}>
	 *         first/last are indexes into $points.
	 */
	public static function detect_stays( array $points, float $radius_m, int $min_seconds ): array {
		$stays = [];
		$count = count( $points );
		$i     = 0;
		while ( $i < $count ) {
			$lat_sum = $points[ $i ][0];
			$lon_sum = $points[ $i ][1];
			$n       = 1;
			$j       = $i + 1;
			while ( $j < $count ) {
				$c_lat = $lat_sum / $n;
				$c_lon = $lon_sum / $n;
				if ( self::distance( $c_lat, $c_lon, $points[ $j ][0], $points[ $j ][1] ) > $radius_m ) {
					break;
				}
				$lat_sum += $points[ $j ][0];
				$lon_sum += $points[ $j ][1];
				++$n;
				++$j;
			}
			$last = $j - 1;
			if ( $points[ $last ][2] - $points[ $i ][2] >= $min_seconds ) {
				$stays[] = [
					'lat'      => $lat_sum / $n,
					'lon'      => $lon_sum / $n,
					'arrived'  => $points[ $i ][2],
					'departed' => $points[ $last ][2],
					'first'    => $i,
					'last'     => $last,
				];
				$i = $j;
			} else {
				++$i;
			}
		}

		$merged = [];
		foreach ( $stays as $stay ) {
			$prev = end( $merged );
			if ( $prev && self::distance( $prev['lat'], $prev['lon'], $stay['lat'], $stay['lon'] ) <= $radius_m ) {
				$merged[ key( $merged ) ]['departed'] = $stay['departed'];
				$merged[ key( $merged ) ]['last']     = $stay['last'];
				continue;
			}
			$merged[] = $stay;
		}
		return $merged;
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

	public static function track_length( array $points ): float {
		$total = 0.0;
		for ( $i = 1, $n = count( $points ); $i < $n; $i++ ) {
			$total += self::distance( $points[ $i - 1 ][0], $points[ $i - 1 ][1], $points[ $i ][0], $points[ $i ][1] );
		}
		return $total;
	}
}
