<?php

namespace TravelSteps;

/**
 * How a trip's print version lays out photos. Each photo is printed large or medium,
 * and the photos of a place go into rows the printer never splits across pages:
 *
 * - medium landscape photos side by side in pairs, each row a third of a page tall, so
 *   six to a page;
 * - medium photos side by side where either isn't landscape, half a page tall, so four
 *   to a page;
 * - a large landscape photo across the page, half a page tall, so two to a page;
 * - a large portrait photo on a page of its own.
 *
 * Rows keep the photos in the order they were taken. Pure functions, no WordPress
 * dependencies: the print page applies the travel_steps_print_size filter on top.
 */
class PrintLayout {
	const LARGE  = 'large';
	const MEDIUM = 'medium';

	// Row layouts, which the print stylesheet sizes: .ts-print-row.is-pair and so on.
	const LOW  = 'low';
	const PAIR = 'pair';
	const WIDE = 'wide';
	const TALL = 'tall';

	// A photo at least this many times as wide as it's tall is a panorama, printed large.
	const PANORAMA_RATIO = 2.0;
	// An original whose long side is shorter than this is never printed large: across the
	// page it would print at well under 150 dpi and look soft.
	const MIN_LARGE_PX = 1200;

	/**
	 * Whether a photo prints large or medium: large when it's the only photo of its place
	 * (as the trip page shows it on its own, across the column) or a panorama, as long as
	 * its original has the pixels for it. A photo whose size isn't known prints large only
	 * when it's alone.
	 *
	 * @param array $photo    A photo row, with w and h as displayed (after rotation).
	 * @param int   $of_place How many photos its place has in the print.
	 */
	public static function size( array $photo, int $of_place ): string {
		$w = (int) ( $photo['w'] ?? 0 );
		$h = (int) ( $photo['h'] ?? 0 );
		if ( ! $w || ! $h ) {
			return 1 === $of_place ? self::LARGE : self::MEDIUM;
		}
		if ( max( $w, $h ) < self::MIN_LARGE_PX ) {
			return self::MEDIUM;
		}
		return 1 === $of_place || $w >= $h * self::PANORAMA_RATIO ? self::LARGE : self::MEDIUM;
	}

	/**
	 * A place's photos as rows, in order. Medium photos taken one after another share
	 * rows two at a time; a large photo between them ends the pair it interrupts, which
	 * then prints as a row of one.
	 *
	 * @param array    $photos Photo rows, in the order to print them.
	 * @param callable $size   fn( array $photo ): string, LARGE or MEDIUM.
	 * @return list<array{layout: string, photos: array}>
	 */
	public static function rows( array $photos, callable $size ): array {
		$rows = [];
		$pair = [];
		foreach ( $photos as $photo ) {
			if ( self::LARGE !== $size( $photo ) ) {
				$pair[] = $photo;
				if ( 2 === count( $pair ) ) {
					$rows[] = self::side_by_side( $pair );
					$pair   = [];
				}
				continue;
			}
			if ( $pair ) {
				$rows[] = self::side_by_side( $pair );
				$pair   = [];
			}
			$portrait = (int) ( $photo['h'] ?? 0 ) > (int) ( $photo['w'] ?? 0 );
			$rows[]   = [ 'layout' => $portrait ? self::TALL : self::WIDE, 'photos' => [ $photo ] ];
		}
		if ( $pair ) {
			$rows[] = self::side_by_side( $pair );
		}
		return $rows;
	}

	/**
	 * The image size a row's photos print at. Side by side, the proxy's medium size still
	 * gives about 180 dpi or better, and a PDF is much smaller for it; across or down a
	 * page they need the preview.
	 *
	 * @param string $layout A row's layout, from rows().
	 */
	public static function image_size( string $layout ): string {
		return self::LOW === $layout || self::PAIR === $layout ? 'medium' : 'preview';
	}

	/**
	 * A row of medium photos: low if all are known to be landscape, as half a page would
	 * leave a blank band around them. A photo whose size isn't known gets the taller row.
	 */
	private static function side_by_side( array $photos ): array {
		foreach ( $photos as $photo ) {
			if ( (int) ( $photo['w'] ?? 0 ) <= (int) ( $photo['h'] ?? 0 ) ) {
				return [ 'layout' => self::PAIR, 'photos' => $photos ];
			}
		}
		return [ 'layout' => self::LOW, 'photos' => $photos ];
	}
}
