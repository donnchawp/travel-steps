<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small formatting helpers shared by templates.
 */
class View {
	// How close a stretch of track must pass to a heading's photos to be part of its area.
	const AREA_NEAR_M = 2000;

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
	 * A warning for editors when visitors can't see some of a trip's steps, else ''.
	 *
	 * On a published trip (with or without a password), it counts the steps that aren't
	 * published. On a trip that isn't public, it counts the published steps, which
	 * visitors can't see either. A private trip is hidden on purpose, and a scheduled one
	 * goes public by itself, so neither gets a warning.
	 *
	 * @param \WP_Post[] $steps The editor's timeline: published, draft, pending and
	 *                          private steps (Trips::steps() leaves scheduled ones out).
	 */
	public static function unseen_notice( \WP_Post $trip, array $steps ): string {
		$published = count( array_filter( $steps, fn( $step ) => 'publish' === $step->post_status ) );
		if ( 'publish' === $trip->post_status ) {
			return self::hidden_steps_notice( $published, count( $steps ) - $published );
		}
		if ( ! $published || in_array( $trip->post_status, [ 'private', 'future' ], true ) ) {
			return '';
		}
		return sprintf(
			/* translators: %s: number of published steps */
			_n( 'This trip isn’t public, so visitors can’t see its %s published step. Set its visibility to Public to show it.', 'This trip isn’t public, so visitors can’t see its %s published steps. Set its visibility to Public to show them.', $published, 'travel-steps' ),
			number_format_i18n( $published )
		);
	}

	/**
	 * Days with photos but no step, because none of their photos is a favourite: sync
	 * only makes a step for a day with one. Keyed by Y-m-d, with how many photos each has.
	 *
	 * @param \WP_Post[] $steps The editor's timeline, as for unseen_notice().
	 * @return array<string, int>
	 */
	public static function stepless_days( int $trip_id, array $steps ): array {
		$taken = array_flip( array_map( [ Trips::class, 'day' ], $steps ) );
		$days  = [];
		foreach ( Trips::photos( $trip_id ) as $photo ) {
			$day          = Trips::photo_day( $photo );
			$days[ $day ] = [ ( $days[ $day ][0] ?? 0 ) + 1, ( $days[ $day ][1] ?? false ) || ! empty( $photo['fav'] ) ];
		}
		ksort( $days );
		$stepless = array_filter( $days, fn( $count, $day ) => ! $count[1] && ! isset( $taken[ $day ] ), ARRAY_FILTER_USE_BOTH );
		return array_map( fn( $count ) => $count[0], $stepless );
	}

	/**
	 * The status badge for a step on a trip's timeline or step list, or null for a
	 * published step. On a published trip, every other step is hidden from visitors
	 * and gets a warning.
	 *
	 * @return array{class: string, text: string}|null
	 */
	public static function step_badge( \WP_Post $trip, \WP_Post $step ): ?array {
		if ( 'publish' === $step->post_status ) {
			return null;
		}
		$label = get_post_status_object( $step->post_status )->label ?? $step->post_status;
		if ( 'publish' !== $trip->post_status ) {
			return [ 'class' => 'ts-badge', 'text' => $label ];
		}
		return [
			'class' => 'ts-badge is-warning',
			// "Private, not public" would say it twice.
			'text'  => 'private' === $step->post_status
				? $label
				/* translators: %s: a step's status, such as Draft or Pending */
				: sprintf( __( '%s, not public', 'travel-steps' ), $label ),
		];
	}

	/**
	 * The warning for a published trip with steps that aren't published, else ''.
	 */
	private static function hidden_steps_notice( int $published, int $hidden ): string {
		if ( ! $hidden ) {
			return '';
		}
		$total = $published + $hidden;
		return sprintf(
			/* translators: 1: number of published steps, 2: number of steps on the trip */
			_n( 'Visitors see %1$s of %2$s step.', 'Visitors see %1$s of %2$s steps.', $total, 'travel-steps' ),
			number_format_i18n( $published ),
			number_format_i18n( $total )
		) . ' ' . sprintf(
			/* translators: %s: number of steps that aren't published */
			_n( 'The other %s isn’t published, so only the trip’s editors can see it.', 'The other %s aren’t published, so only the trip’s editors can see them.', $hidden, 'travel-steps' ),
			number_format_i18n( $hidden )
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
		$asset = self::cover_id( $trip_id );
		return '' === $asset ? '' : RestController::photo_url( $trip_id, $asset, $size );
	}

	/**
	 * The tags a link preview reads, by property: the trip's title, summary and cover.
	 * Opened from a share link, its URL and cover carry the code, as the app that fetches
	 * them has no cookie.
	 *
	 * @return array<string, string>
	 */
	public static function preview_meta( \WP_Post $trip, bool $shared ): array {
		$meta  = [
			'og:type'        => 'website',
			'og:title'       => get_the_title( $trip ),
			'og:url'         => $shared ? Trips::share_url( $trip ) : Trips::url( $trip ),
			'og:description' => self::preview_description( $trip ),
		];
		$cover = self::cover_url( $trip->ID, 'medium' );
		if ( '' !== $cover ) {
			$meta['og:image'] = $shared ? add_query_arg( Trips::SHARE_ARG, Trips::share_code( $trip ), $cover ) : $cover;
		}
		$meta['twitter:card'] = isset( $meta['og:image'] ) ? 'summary_large_image' : 'summary';
		return array_filter( $meta, fn( $content ) => '' !== $content );
	}

	/**
	 * A trip's intro as plain text, cut at a word to 200 characters at most, else its dates.
	 */
	public static function preview_description( \WP_Post $trip ): string {
		// A line or paragraph break is a space, or stripping the tags would join its words.
		$html = preg_replace( '#<br\s*/?>|</(?:p|li|h[1-6]|div)>#i', ' $0', $trip->post_content );
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( '' === $text ) {
			return self::date_range( $trip->ID );
		}
		if ( mb_strlen( $text ) <= 200 ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, 200 );
		return rtrim( mb_substr( $cut, 0, (int) mb_strrpos( $cut, ' ' ) ), ' ,.;:' ) . '…';
	}

	/**
	 * The asset cover_url() shows, or empty if there's none.
	 */
	public static function cover_id( int $trip_id ): string {
		$chosen = Trips::cover( $trip_id );
		if ( '' !== $chosen ) {
			return $chosen;
		}
		$shown = [];
		foreach ( Trips::photos_by_step( $trip_id, Trips::steps( $trip_id ) ) as $groups ) {
			$shown = array_merge( $shown, $groups['shown'] );
		}
		$photo = Selection::lead( array_filter( $shown, fn( $p ) => ( $p['w'] ?? 0 ) >= ( $p['h'] ?? 0 ) ) );
		return $photo ? $photo['id'] : '';
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
			$photo = Selection::lead(
				array_filter(
					$photos,
					fn( $p ) => null !== $p['lat'] && Days::section_index( $starts, (int) $p['t'] ) === $i + 1
				)
			);
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
	 * The area of the route under each place heading of a step, for the map to zoom to as
	 * it's read: element id => [ [ south, west ], [ north, east ] ], from the story's
	 * headings as they are now. A heading's stretch runs from its time to the next one's,
	 * the first from the start of the day and the last to its end; a step without headings
	 * has the whole day. Only the track near a heading's photos counts.
	 *
	 * @param array $photos The step's visible photos.
	 * @param array $boxes  Trips::boxes().
	 */
	public static function areas( \WP_Post $step, array $photos, array $boxes ): array {
		$located = array_filter( $photos, fn( $p ) => null !== $p['lat'] );
		// A step with no position, such as a day at home, has no marker to zoom to, and its
		// track would only frame the area around home.
		if ( ! $boxes || ( ! $located && '' === get_post_meta( $step->ID, Trips::META_LAT, true ) ) ) {
			return [];
		}
		$starts = Days::anchor_times( $step->post_content );
		sort( $starts );
		// The step's day, stretched to take in a heading or photo that falls outside it.
		$day_from = ( new \DateTimeImmutable( Trips::day( $step ), wp_timezone() ) )->getTimestamp();
		$day_to   = $day_from + DAY_IN_SECONDS;
		$day_from = min( $day_from, $starts[0] ?? $day_from );
		$day_to   = max( $day_to, 1 + (int) max( array_merge( [ 0 ], $starts, array_column( $photos, 't' ) ) ) );

		$spans = [];
		foreach ( $starts as $i => $start ) {
			$spans[ Days::ANCHOR_PREFIX . $start ] = [ $i ? $start : $day_from, $starts[ $i + 1 ] ?? $day_to, $i ];
		}
		if ( ! $spans ) {
			$spans[ 'step-' . $step->ID ] = [ $day_from, $day_to, null ];
		}

		$areas = [];
		foreach ( $spans as $id => [ $from, $to, $i ] ) {
			// The first heading also owns the photos taken before it.
			$own = array_filter(
				$located,
				function ( $p ) use ( $starts, $i ) {
					$section = Days::section_index( $starts, (int) $p['t'] );
					return null === $i || $section === $i + 1 || ( 0 === $i && 0 === $section );
				}
			);
			// Its favourites make the place: other photos under it can be from the next one,
			// such as those taken after landing, before the first favourite there.
			$favourites = array_filter( $own, fn( $p ) => ! empty( $p['fav'] ) );
			$near       = array_map( fn( $p ) => [ (float) $p['lat'], (float) $p['lon'] ], array_values( $favourites ?: $own ) );
			$area       = Geo::span_area( $boxes, $from, $to, $near, self::AREA_NEAR_M );
			if ( $area ) {
				$areas[ $id ] = $area;
			}
		}
		return $areas;
	}

	/**
	 * Checkboxes named viewers[] for a viewer list, $listed ticked: one per user, by display
	 * name, but for those who see the trips anyway. For a trip, they're those who can edit
	 * it; for the author's list, or a trip not yet saved, the author and those who can edit
	 * others' trips.
	 *
	 * @param int[] $listed
	 */
	public static function viewer_boxes( int $author, int $trip_id, array $listed ): void {
		// Whole users, which primes their meta for the capability checks in one go.
		$users = array_filter(
			get_users( [ 'orderby' => 'display_name' ] ),
			fn( \WP_User $user ) => ! ( $trip_id ? user_can( $user, 'edit_post', $trip_id ) : $author === $user->ID || user_can( $user, 'edit_others_posts' ) )
		);
		if ( ! $users ) {
			echo '<p class="ts-muted">' . esc_html__( 'Nobody else on this site can be added.', 'travel-steps' ) . '</p>';
			return;
		}
		echo '<div class="ts-viewers">';
		foreach ( $users as $user ) {
			printf(
				'<label class="ts-check"><input type="checkbox" name="viewers[]" value="%d"%s> <span>%s</span></label>',
				(int) $user->ID,
				in_array( (int) $user->ID, $listed, true ) ? ' checked' : '',
				esc_html( $user->display_name )
			);
		}
		echo '</div>';
	}

	/**
	 * The trip form's "Photos" choices for the trip's author: the trip's uploads, all
	 * their Immich photos from its dates, or one Immich album. Empty when they can use
	 * neither.
	 *
	 * @param array|\WP_Error|null $albums The author's Immich albums, the error listing
	 *                                     them, or null when they have no Immich key.
	 * @return list<array{value: string, label: string, selected: bool, disabled: bool, dates_optional: bool}>
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
			'value'          => $value,
			'label'          => $label,
			'selected'       => $value === $current,
			'disabled'       => $disabled,
			// Uploads or an album can give the trip its dates; all Immich photos can't.
			'dates_optional' => Trips::SOURCE_IMMICH !== $value,
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
