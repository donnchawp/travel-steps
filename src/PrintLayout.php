<?php

namespace TravelSteps;

/**
 * How a trip's print version lays out the photos chosen for a section: its lead alone on
 * a page, then the others two to a page. So the five that work best fill three pages,
 * and with its text page a section takes four. Pure functions, no WordPress dependencies.
 *
 * Photos print in the order they were chosen, so a section's lead is the first of them.
 */
class PrintLayout {
	// Page layouts, which the print stylesheet sizes: .ts-book-photos.is-stack and so on.
	const LANDSCAPE = 'landscape';
	const PORTRAIT  = 'portrait';
	const STACK     = 'stack';
	const PAIR      = 'pair';

	// How many photos a page after the lead's holds.
	const MAX_ON_PAGE = 2;
	// How many photos a section is best with. More are allowed, and the trip page says so.
	const BEST = 5;

	/**
	 * A section's chosen photos, in the order they were chosen, the first being its lead.
	 *
	 * @param array    $photos    The section's photos.
	 * @param string[] $print_ids The trip's chosen photo ids, in order.
	 * @return array Photo rows.
	 */
	public static function in_print_order( array $photos, array $print_ids ): array {
		$rank   = array_flip( $print_ids );
		$chosen = array_filter( $photos, fn( $photo ) => isset( $rank[ $photo['id'] ] ) );
		usort( $chosen, fn( $a, $b ) => $rank[ $a['id'] ] <=> $rank[ $b['id'] ] );
		return $chosen;
	}

	/**
	 * A section's pages of photos, each with the layout that prints them.
	 *
	 * @param array $chosen Photos from in_print_order().
	 * @return list<array{layout: string, photos: array}>
	 */
	public static function pages( array $chosen ): array {
		if ( ! $chosen ) {
			return [];
		}
		$pages = [ self::page( [ array_shift( $chosen ) ] ) ];
		foreach ( array_chunk( $chosen, self::MAX_ON_PAGE ) as $photos ) {
			$pages[] = self::page( $photos );
		}
		return $pages;
	}

	/**
	 * A page of one or two photos. A photo whose size isn't known counts as landscape.
	 */
	private static function page( array $photos ): array {
		$portrait = fn( array $photo ) => (int) ( $photo['h'] ?? 0 ) > (int) ( $photo['w'] ?? 0 );
		if ( 1 === count( $photos ) ) {
			$layout = $portrait( $photos[0] ) ? self::PORTRAIT : self::LANDSCAPE;
		} else {
			$layout = $portrait( $photos[0] ) || $portrait( $photos[1] ) ? self::PAIR : self::STACK;
		}
		return [ 'layout' => $layout, 'photos' => $photos ];
	}
}
