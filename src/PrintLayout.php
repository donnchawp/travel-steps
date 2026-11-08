<?php

namespace TravelSteps;

/**
 * How a trip's print version lays out the photos chosen for a section: its lead alone on
 * a page, then the others two to a page. So the five that work best fill three pages,
 * and with its text page a section takes four. Pure functions, no WordPress dependencies.
 *
 * A section's lead is whichever of its photos comes first in the trip's list of chosen
 * photos. Its others print in the order they were taken.
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
	 * A section's chosen photos: its lead, then the others in time order.
	 *
	 * @param array    $photos    The section's photos, by time.
	 * @param string[] $print_ids The trip's chosen photo ids, in order.
	 * @return array Photo rows.
	 */
	public static function lead_first( array $photos, array $print_ids ): array {
		$rank   = array_flip( array_values( $print_ids ) );
		$chosen = array_values( array_filter( $photos, fn( $photo ) => isset( $rank[ $photo['id'] ] ) ) );
		if ( ! $chosen ) {
			return [];
		}
		$lead = 0;
		foreach ( $chosen as $index => $photo ) {
			if ( $rank[ $photo['id'] ] < $rank[ $chosen[ $lead ]['id'] ] ) {
				$lead = $index;
			}
		}
		$first = array_splice( $chosen, $lead, 1 );
		return array_merge( $first, $chosen );
	}

	/**
	 * A section's pages of photos, each with the layout that prints them.
	 *
	 * @param array $chosen Photos from lead_first().
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
	 * The list of chosen photos without $asset. If it was its section's lead, the section's
	 * next chosen photo by time becomes the lead, wherever it was in the list.
	 *
	 * @param string[] $print_ids      The trip's chosen photo ids, in order.
	 * @param array    $section_photos The photos of $asset's section, by time.
	 * @return string[]
	 */
	public static function after_removal( array $print_ids, string $asset, array $section_photos ): array {
		$left   = array_values( array_diff( $print_ids, [ $asset ] ) );
		$chosen = self::lead_first( $section_photos, $print_ids );
		if ( ! isset( $chosen[1] ) || $asset !== $chosen[0]['id'] ) {
			return $left;
		}
		return array_merge( [ $chosen[1]['id'] ], array_values( array_diff( $left, [ $chosen[1]['id'] ] ) ) );
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
