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
		$start = (string) get_post_meta( $trip_id, Trips::META_START, true );
		$end   = (string) get_post_meta( $trip_id, Trips::META_END, true );
		return $start && $end ? Days::day_number( $start, $end ) : 0;
	}

	/**
	 * A warning for editors when published steps can't be seen because their trip isn't
	 * public, else ''. A private trip is hidden on purpose, and a scheduled one goes
	 * public by itself, so neither needs it.
	 */
	public static function unseen_notice( \WP_Post $trip, array $steps ): string {
		$unseen = count( array_filter( $steps, fn( $step ) => 'publish' === $step->post_status ) );
		if ( ! $unseen || in_array( $trip->post_status, [ 'publish', 'private', 'future' ], true ) ) {
			return '';
		}
		return sprintf(
			/* translators: %s: number of published steps */
			_n( 'This trip isn’t public, so visitors can’t see its %s published step. Set its visibility to Public to show it.', 'This trip isn’t public, so visitors can’t see its %s published steps. Set its visibility to Public to show them.', $unseen, 'travel-steps' ),
			number_format_i18n( $unseen )
		);
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
	 * Cover image: the photo chosen for it, else the first landscape favourite shown on
	 * a published step, else the first landscape photo shown there.
	 */
	public static function cover_url( int $trip_id, string $size = 'preview' ): string {
		$authed = 'publish' !== get_post_status( $trip_id );
		$chosen = Trips::cover( $trip_id );
		if ( '' !== $chosen ) {
			return RestController::photo_url( $trip_id, $chosen, $size, $authed );
		}
		$shown = [];
		foreach ( Trips::photos_by_step( $trip_id, Trips::steps( $trip_id ) ) as $groups ) {
			$shown = array_merge( $shown, $groups['shown'] );
		}
		$landscape  = array_values( array_filter( $shown, fn( $p ) => ( $p['w'] ?? 0 ) >= ( $p['h'] ?? 0 ) ) );
		$favourites = array_values( array_filter( $landscape, fn( $p ) => ! empty( $p['fav'] ) ) );
		$photo      = $favourites[0] ?? $landscape[0] ?? null;
		if ( ! $photo ) {
			return '';
		}
		return RestController::photo_url( $trip_id, $photo['id'], $size, $authed );
	}

	/**
	 * Echo a step's story with its photos placed under the place headings they belong
	 * to: each heading gets the photos taken from its time until the next heading's.
	 * Photos from before the first heading, or in a story without place headings,
	 * go with the text before the first heading.
	 *
	 * A section's photos go where its first Trip photos block is, else after its text.
	 * Further photos blocks in the same section show nothing.
	 *
	 * @param array    $photos Photos to show, sorted by time.
	 * @param callable $grid   Echoes a photo grid: fn( array $photos ).
	 */
	public static function render_story( \WP_Post $step, array $photos, callable $grid ): void {
		$starts = Days::anchor_times( $step->post_content );
		sort( $starts );
		$sections = [];
		foreach ( $photos as $photo ) {
			$sections[ Days::section_index( $starts, (int) $photo['t'] ) ][] = $photo;
		}

		// Split the story's top-level blocks at each place heading.
		$chunks = [ [ 'section' => 0, 'blocks' => [] ] ];
		foreach ( parse_blocks( $step->post_content ) as $block ) {
			$t = 'core/heading' === $block['blockName'] ? Days::place_anchor( $block['innerHTML'] ) : null;
			if ( null !== $t ) {
				$chunks[] = [ 'section' => (int) array_search( $t, $starts, true ) + 1, 'blocks' => [] ];
			}
			$chunks[ count( $chunks ) - 1 ]['blocks'][] = $block;
		}

		foreach ( $chunks as $chunk ) {
			// Text before the photos, then text after them.
			$parts  = [ [], [] ];
			$placed = false;
			foreach ( $chunk['blocks'] as $block ) {
				if ( Days::PHOTOS_BLOCK === $block['blockName'] ) {
					$placed = true;
					continue;
				}
				$parts[ (int) $placed ][] = $block;
			}

			self::render_blocks( $parts[0] );
			if ( ! empty( $sections[ $chunk['section'] ] ) ) {
				$grid( $sections[ $chunk['section'] ] );
				unset( $sections[ $chunk['section'] ] );
			}
			self::render_blocks( $parts[1] );
		}
		// Headings nested inside other blocks: their photos go at the end.
		foreach ( $sections as $rest ) {
			$grid( $rest );
		}
	}

	private static function render_blocks( array $blocks ): void {
		$html = trim( serialize_blocks( $blocks ) );
		if ( '' !== $html ) {
			echo '<div class="ts-content">' . apply_filters( 'the_content', $html ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core content filter.
		}
	}

	/**
	 * Map markers for a step: one per place heading in its story, at the first located
	 * favourite (else photo) taken under it, labelled 3a, 3b…; or one for the step.
	 *
	 * @param array $photos The step's visible photos, sorted by time.
	 * @return array<int, array{step: int, anchor: string, label: string, lat: float, lon: float, title: string}>
	 */
	public static function markers( \WP_Post $step, array $photos, int $number ): array {
		$title   = html_entity_decode( wp_strip_all_tags( get_the_title( $step ) ), ENT_QUOTES, 'UTF-8' );
		$starts  = Days::anchor_times( $step->post_content );
		sort( $starts );
		$markers = [];
		foreach ( $starts as $i => $start ) {
			$located = array_values(
				array_filter(
					$photos,
					fn( $p ) => null !== $p['lat'] && Days::section_index( $starts, (int) $p['t'] ) === $i + 1
				)
			);
			$favourites = array_values( array_filter( $located, fn( $p ) => ! empty( $p['fav'] ) ) );
			$photo      = $favourites[0] ?? $located[0] ?? null;
			if ( $photo ) {
				$markers[] = [
					'step'   => $step->ID,
					'anchor' => Days::ANCHOR_PREFIX . $start,
					'label'  => $number . chr( ord( 'a' ) + min( 25, count( $markers ) ) ),
					'lat'    => (float) $photo['lat'],
					'lon'    => (float) $photo['lon'],
					'title'  => $title,
				];
			}
		}
		if ( 1 === count( $markers ) ) {
			$markers[0]['label'] = (string) $number;
		}
		$lat = get_post_meta( $step->ID, Trips::META_LAT, true );
		if ( ! $markers && '' !== $lat ) {
			$markers[] = [
				'step'   => $step->ID,
				'anchor' => 'step-' . $step->ID,
				'label'  => (string) $number,
				'lat'    => (float) $lat,
				'lon'    => (float) get_post_meta( $step->ID, Trips::META_LON, true ),
				'title'  => $title,
			];
		}
		return $markers;
	}

	/**
	 * The trip form's "Photos" choices for the trip's author: the trip's uploads, all
	 * their Immich photos from its dates, or one Immich album. Empty when they can use
	 * neither.
	 *
	 * @param array|\WP_Error|null $albums The author's Immich albums, the error listing
	 *                                     them, or null when they have no Immich key.
	 * @return list<array{value: string, label: string, selected: bool, disabled: bool}>
	 */
	public static function photo_choices( ?\WP_Post $trip, $albums ): array {
		$can_upload = Uploads::available();
		if ( null === $albums && ! $can_upload ) {
			return [];
		}
		$saved_album = $trip ? (string) get_post_meta( $trip->ID, Trips::META_ALBUM, true ) : '';
		if ( ! $trip ) {
			// A new trip: uploads when the author can't use Immich, else all their Immich photos.
			$current = null === $albums ? Trips::SOURCE_UPLOADS : Trips::SOURCE_IMMICH;
		} elseif ( Trips::uses_uploads( $trip->ID ) ) {
			$current = Trips::SOURCE_UPLOADS;
		} elseif ( '' !== $saved_album && ! is_wp_error( $albums ) ) {
			$current = $saved_album;
		} else {
			// When Immich can't list albums, the album id is typed beside the select.
			$current = Trips::SOURCE_IMMICH;
		}
		$all_immich = __( 'All Immich photos taken during the trip', 'travel-steps' );
		$choice     = fn( string $value, string $label, bool $disabled = false ) => [
			'value'    => $value,
			'label'    => $label,
			'selected' => $value === $current,
			'disabled' => $disabled,
		];

		$choices = [ $choice( Trips::SOURCE_UPLOADS, __( 'Photos uploaded to this trip', 'travel-steps' ), ! $can_upload ) ];
		if ( null === $albums ) {
			// Without a key the author can't choose Immich, but saving the form mustn't
			// switch an Immich trip to uploads.
			if ( Trips::SOURCE_UPLOADS !== $current ) {
				$choices[] = $choice(
					$current,
					Trips::SOURCE_IMMICH === $current
						? $all_immich
						/* translators: %s: Immich album id */
						: sprintf( __( 'Immich album %s', 'travel-steps' ), $current )
				);
			}
			return $choices;
		}
		$choices[] = $choice( Trips::SOURCE_IMMICH, $all_immich );
		$listed    = is_array( $albums ) ? $albums : [];
		// An album Immich no longer lists (deleted, or not visible to this key) stays
		// selected, so saving the form for something else doesn't drop it.
		if ( $current === $saved_album && ! in_array( $saved_album, array_column( $listed, 'id' ), true ) ) {
			/* translators: %s: Immich album id */
			$choices[] = $choice( $saved_album, sprintf( __( 'Album not found in Immich (%s)', 'travel-steps' ), $saved_album ) );
		}
		foreach ( $listed as $album ) {
			$choices[] = $choice( $album['id'], sprintf( '%s (%s)', $album['name'], number_format_i18n( $album['count'] ) ) );
		}
		return $choices;
	}
}
