<?php

namespace TravelSteps;

/**
 * A step is a day. Within a day, favourites are grouped into places, which become
 * headings in the step's story. Pure functions, no WordPress dependencies.
 *
 * Seeded headings carry an anchor holding the place's start time (ts-place-<unix>),
 * so photos can be shown under the heading they belong to however the story is
 * edited afterwards: each heading owns the photos taken from its time until the next.
 */
class Days {
	const ANCHOR_PREFIX = 'ts-place-';
	// A place heading waiting for its first photo to be picked in the editor. Not a
	// number, so the trip page treats it as a plain heading until then.
	const PICK_ANCHOR = 'ts-place-pick';
	const PHOTOS_BLOCK  = 'travel-steps/photos';

	// How far, in local time, a photo with a time zone can lend it to one without.
	const ZONE_BORROW_MAX_S = 36 * 3600;

	/**
	 * The local date a photo was taken: Immich's localDateTime is the wall-clock time
	 * written as if it were UTC. Without it, use $tz.
	 */
	public static function local_day( ?string $local_datetime, int $t, \DateTimeZone $tz ): string {
		if ( $local_datetime && preg_match( '/^\d{4}-\d{2}-\d{2}/', $local_datetime, $m ) ) {
			return $m[0];
		}
		return ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->format( 'Y-m-d' );
	}

	/**
	 * Fix the instants of photos Immich has no time zone for. Immich stores those (a
	 * camera without GPS or an offset tag) as their wall-clock time written as UTC, so
	 * abroad they're hours off. Each borrows the UTC offset of the nearest photo, by
	 * local time, that has a zone (usually a phone's), or else the site's zone.
	 *
	 * @param array $photos Rows with t, local (wall-clock time as if UTC) and zoned.
	 * @return array The same rows with t fixed and local and zoned removed.
	 */
	public static function resolve_times( array $photos, \DateTimeZone $fallback ): array {
		$zoned = [];
		foreach ( $photos as $photo ) {
			if ( $photo['zoned'] ) {
				$zoned[] = [ $photo['local'], $photo['local'] - $photo['t'] ];
			}
		}

		foreach ( $photos as &$photo ) {
			if ( ! $photo['zoned'] ) {
				$offset = null;
				$best   = self::ZONE_BORROW_MAX_S;
				foreach ( $zoned as [ $local, $zoned_offset ] ) {
					$gap = abs( $local - $photo['local'] );
					if ( $gap <= $best ) {
						$best   = $gap;
						$offset = $zoned_offset;
					}
				}
				$photo['t'] = null === $offset
					? ( new \DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $photo['local'] ), $fallback ) )->getTimestamp()
					: $photo['local'] - $offset;
			}
			unset( $photo['local'], $photo['zoned'] );
		}
		unset( $photo );

		return $photos;
	}

	/**
	 * Day N of a trip starting on $start (both Y-m-d); 0 for days before it.
	 */
	public static function day_number( string $start, string $day ): int {
		$utc  = new \DateTimeZone( 'UTC' );
		$diff = ( new \DateTimeImmutable( $day, $utc ) )->getTimestamp() - ( new \DateTimeImmutable( $start, $utc ) )->getTimestamp();
		return max( 0, intdiv( $diff, 86400 ) + 1 );
	}

	/**
	 * Group one day's favourites, sorted by time, into places. A new place starts when a
	 * photo is more than $radius_m from the current place's centroid and isn't in the
	 * same named city, so a day walking around one city stays one place. Photos without
	 * a position join the current place.
	 *
	 * @return array<int, array{t: int, lat: ?float, lon: ?float, city: string}>
	 */
	public static function places( array $favourites, float $radius_m ): array {
		$places = [];
		$cur    = null;
		foreach ( $favourites as $photo ) {
			$located = null !== $photo['lat'];
			$city    = (string) ( $photo['city'] ?? '' );
			if ( $cur && $located && $cur['n'] ) {
				$far       = Geo::distance( $cur['lat_sum'] / $cur['n'], $cur['lon_sum'] / $cur['n'], $photo['lat'], $photo['lon'] ) > $radius_m;
				$same_city = '' !== $city && $city === $cur['city'];
				if ( $far && ! $same_city ) {
					$places[] = $cur;
					$cur      = null;
				}
			}
			if ( ! $cur ) {
				$cur = [ 't' => $photo['t'], 'lat_sum' => 0.0, 'lon_sum' => 0.0, 'n' => 0, 'city' => '' ];
			}
			if ( $located ) {
				$cur['lat_sum'] += $photo['lat'];
				$cur['lon_sum'] += $photo['lon'];
				++$cur['n'];
			}
			if ( '' === $cur['city'] && '' !== $city ) {
				$cur['city'] = $city;
			}
		}
		if ( $cur ) {
			$places[] = $cur;
		}

		return array_map(
			fn( $p ) => [
				't'    => $p['t'],
				'lat'  => $p['n'] ? $p['lat_sum'] / $p['n'] : null,
				'lon'  => $p['n'] ? $p['lon_sum'] / $p['n'] : null,
				'city' => $p['city'],
			],
			$places
		);
	}

	/**
	 * The day's named places in order, each once: "Lisbon, Sintra".
	 */
	public static function title( array $places, string $fallback ): string {
		$names = array_values( array_unique( array_filter( array_column( $places, 'city' ) ) ) );
		return $names ? implode( ', ', $names ) : $fallback;
	}

	/**
	 * Block markup for a new step: per place a heading, an empty paragraph to write in
	 * and a photos block. A day with one place gets no heading; the title names it.
	 */
	public static function seed_content( array $places, string $unnamed = 'Place' ): string {
		$blocks = [];
		foreach ( $places as $place ) {
			if ( count( $places ) > 1 ) {
				$blocks[] = self::heading( self::ANCHOR_PREFIX . (int) $place['t'], '' !== $place['city'] ? $place['city'] : $unnamed );
			}
			$blocks[] = self::section_body();
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Block markup for the "Trip place" pattern: a seeded place's blocks, with a heading
	 * that waits for its first photo to be picked in the editor.
	 */
	public static function place_pattern( string $name ): string {
		return self::heading( self::PICK_ANCHOR, $name ) . "\n\n" . self::section_body();
	}

	private static function heading( string $anchor, string $name ): string {
		$name = htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' );
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\" id=\"{$anchor}\">{$name}</h2>\n<!-- /wp:heading -->";
	}

	// An empty paragraph to write in, then where the place's photos go.
	private static function section_body(): string {
		return "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n\n<!-- wp:" . self::PHOTOS_BLOCK . ' /-->';
	}

	/**
	 * Start time from a place heading's anchor, or null for any other markup.
	 */
	public static function place_anchor( string $html ): ?int {
		return preg_match( '/\sid="' . self::ANCHOR_PREFIX . '(\d+)"/', $html, $m ) ? (int) $m[1] : null;
	}

	/**
	 * Start times of the place headings in a story, in order of appearance.
	 *
	 * @return int[]
	 */
	public static function anchor_times( string $content ): array {
		preg_match_all( '/\sid="' . self::ANCHOR_PREFIX . '(\d+)"/', $content, $m );
		return array_map( 'intval', $m[1] );
	}

	/**
	 * Which section a photo taken at $t belongs to: 0 before the first heading, then i
	 * for the i-th heading (1-based) until the next one starts.
	 *
	 * @param int[] $starts Heading start times, ascending.
	 */
	public static function section_index( array $starts, int $t ): int {
		$index = 0;
		foreach ( $starts as $start ) {
			if ( $t < $start ) {
				break;
			}
			++$index;
		}
		return $index;
	}
}
