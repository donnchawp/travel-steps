<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small formatting helpers shared by templates.
 */
class View {
	public static function date_range( int $trip_id ): string {
		$start = (string) get_post_meta( $trip_id, Trips::META_START, true );
		$end   = (string) get_post_meta( $trip_id, Trips::META_END, true );
		if ( ! $start ) {
			return '';
		}
		$tz   = wp_timezone();
		$from = date_create_immutable( $start, $tz );
		$to   = $end ? date_create_immutable( $end, $tz ) : $from;
		if ( ! $from || ! $to ) {
			return '';
		}
		$format = get_option( 'date_format' );
		if ( $from == $to ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- DateTime value comparison.
			return wp_date( $format, $from->getTimestamp() );
		}
		return wp_date( $format, $from->getTimestamp() ) . ' – ' . wp_date( $format, $to->getTimestamp() );
	}

	public static function days( int $trip_id ): int {
		$range = Trips::range( $trip_id );
		return $range ? (int) ceil( ( $range[1] - $range[0] ) / DAY_IN_SECONDS ) : 0;
	}

	/**
	 * @return array<string, string> Label => value, for the trip stats strip.
	 */
	public static function stats( int $trip_id ): array {
		$stats = get_post_meta( $trip_id, Trips::META_STATS, true );
		$stats = is_array( $stats ) ? $stats : [];
		$out   = [];
		$days  = self::days( $trip_id );
		if ( $days ) {
			$out[ _n( 'day', 'days', $days, 'travel-steps' ) ] = number_format_i18n( $days );
		}
		if ( ! empty( $stats['distance_km'] ) ) {
			$out[ __( 'km', 'travel-steps' ) ] = number_format_i18n( $stats['distance_km'] );
		}
		$steps = count( Trips::steps( $trip_id ) );
		if ( $steps ) {
			$out[ _n( 'step', 'steps', $steps, 'travel-steps' ) ] = number_format_i18n( $steps );
		}
		if ( ! empty( $stats['photos'] ) ) {
			$out[ _n( 'photo', 'photos', $stats['photos'], 'travel-steps' ) ] = number_format_i18n( $stats['photos'] );
		}
		if ( ! empty( $stats['countries'] ) ) {
			$count = count( $stats['countries'] );
			$out[ _n( 'country', 'countries', $count, 'travel-steps' ) ] = number_format_i18n( $count );
		}
		return $out;
	}

	/**
	 * Cover image: the first landscape favourite, else the first landscape photo, never a hidden one.
	 */
	public static function cover_url( int $trip_id, string $size = 'preview' ): string {
		$hidden    = Trips::hidden( $trip_id );
		$landscape = array_values(
			array_filter(
				Trips::photos( $trip_id ),
				fn( $p ) => ! in_array( $p['id'], $hidden, true ) && ( $p['w'] ?? 0 ) >= ( $p['h'] ?? 0 )
			)
		);
		$favourites = array_values( array_filter( $landscape, fn( $p ) => ! empty( $p['fav'] ) ) );
		$photo      = $favourites[0] ?? $landscape[0] ?? null;
		if ( ! $photo ) {
			return '';
		}
		return RestController::photo_url( $trip_id, $photo['id'], $size, 'publish' !== get_post_status( $trip_id ) );
	}
}
