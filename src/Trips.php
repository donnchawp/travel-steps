<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trip and step post types, and the form handlers that edit them.
 *
 * A trip is a `ts_trip` post. Each step is a `ts_step` post whose post_parent is the
 * trip, so its story can be written in the block editor.
 */
class Trips {
	const TRIP  = 'ts_trip';
	const STEP  = 'ts_step';
	const PLACE = 'ts_place';

	// Trip meta.
	const META_START  = '_ts_start';       // Y-m-d, site timezone.
	const META_END    = '_ts_end';         // Y-m-d, inclusive.
	const META_ALBUM  = '_ts_album';       // Immich album id (optional).
	const META_DATES_AUTO = '_ts_dates_auto'; // [ first, last ]: the dates sync took from a trip's uploads, '' for one set by hand.
	const META_SOURCE = '_ts_source';      // SOURCE_UPLOADS or SOURCE_IMMICH; empty means Immich.
	const SOURCE_UPLOADS = 'uploads';
	const SOURCE_IMMICH  = 'immich';
	const META_ROUTE  = '_ts_route';       // JSON: list of segments, each a list of [lat, lon].
	const META_PHOTOS = '_ts_photos';      // JSON: list of photo rows (see PhotoSource::photos) + step.
	const META_HIDDEN = '_ts_hidden';      // array of hidden photo ids.
	const META_PINNED = '_ts_pinned';      // array of photo ids always shown.
	const META_COVER  = '_ts_cover';       // photo id chosen for the header, else automatic.
	const META_STATS  = '_ts_stats';       // array: distance_km, points, photos, countries.
	const META_SYNCED = '_ts_synced';      // unix timestamp of last sync.
	const META_ERROR  = '_ts_sync_error';  // last sync error message.

	// Step meta. A step is a day: the date part of its post date.
	const META_LAT  = '_ts_lat';
	const META_LON  = '_ts_lon';
	const META_DAY  = '_ts_day';   // Y-m-d sync created the step for; kept even if you move it.
	const META_AUTO = '_ts_auto';  // 1 when created by sync.

	public static function register_post_types(): void {
		register_post_type(
			self::TRIP,
			[
				'labels'       => [
					'name'          => __( 'Trips', 'travel-steps' ),
					'singular_name' => __( 'Trip', 'travel-steps' ),
					'add_new_item'  => __( 'Add trip', 'travel-steps' ),
					'edit_item'     => __( 'Edit trip', 'travel-steps' ),
				],
				// Rendered by the app at /travel-steps/trip/{slug}, not by the theme.
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-location-alt',
				'supports'     => [ 'title', 'editor', 'excerpt', 'custom-fields', 'thumbnail' ],
				'rewrite'      => false,
			]
		);
		register_post_type(
			self::STEP,
			[
				'labels'       => [
					'name'          => __( 'Steps', 'travel-steps' ),
					'singular_name' => __( 'Step', 'travel-steps' ),
					'edit_item'     => __( 'Edit step', 'travel-steps' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . self::TRIP,
				'show_in_rest' => true,
				// Core REST would otherwise serve published steps of draft trips to anyone.
				'rest_controller_class' => StepsRestController::class,
				'supports'     => [ 'title', 'editor', 'custom-fields' ],
				'taxonomies'   => [ self::PLACE ],
				'rewrite'      => false,
			]
		);
		register_taxonomy(
			self::PLACE,
			self::STEP,
			[
				'labels'            => [
					'name'          => __( 'Places', 'travel-steps' ),
					'singular_name' => __( 'Place', 'travel-steps' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				// Needed for the block editor's panel; reads are limited to editors.
				'show_in_rest'      => true,
				'rest_controller_class' => PlacesRestController::class,
				'rewrite'           => false,
			]
		);

		// Exposed over REST so the step can be repositioned programmatically.
		foreach ( [ self::META_LAT => 'number', self::META_LON => 'number' ] as $key => $type ) {
			register_post_meta(
				self::STEP,
				$key,
				[
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				]
			);
		}
	}

	/**
	 * Who may make trips: authors and up. Each uses their own Immich and Dawarich keys.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'travel_steps_capability', 'publish_posts' );
	}

	/**
	 * Whether the current user may edit a trip: it is one, they can edit it, and they
	 * have the travel-steps capability.
	 */
	public static function can_edit_trip( int $trip_id ): bool {
		return self::TRIP === get_post_type( $trip_id ) && current_user_can( 'edit_post', $trip_id ) && current_user_can( self::capability() );
	}

	/**
	 * Whether the current user may change what a trip pulls in (its dates and photos) and
	 * sync it: only its author may, as that uses their keys, library and location history.
	 * Others who can edit the trip keep the rest.
	 */
	public static function can_sync_trip( int $trip_id ): bool {
		return self::can_edit_trip( $trip_id ) && (int) get_post_field( 'post_author', $trip_id ) === get_current_user_id();
	}

	/**
	 * Whether a user can take a trip's photos from Immich. The site always has an Immich
	 * URL (it falls back to the default container), so it's their key that decides.
	 */
	public static function can_use_immich( int $user_id ): bool {
		return '' !== Settings::account( $user_id )['immich_key'];
	}

	public static function find_by_slug( string $slug ): ?\WP_Post {
		$posts = get_posts(
			[
				'post_type'      => self::TRIP,
				'name'           => sanitize_title( $slug ),
				'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'posts_per_page' => 1,
			]
		);
		if ( $posts ) {
			return $posts[0];
		}
		// A trip without a slug has its post ID in its URL. New slugs are never all
		// digits (see unique_slug()), but ones saved earlier might be, so they win.
		if ( ctype_digit( $slug ) ) {
			$post = get_post( (int) $slug );
			return $post && self::TRIP === $post->post_type ? $post : null;
		}
		return null;
	}

	/**
	 * Keep trip slugs from being all digits, which would read as a post ID in the URL:
	 * a trip called "2025" gets "trip-2025".
	 */
	public static function unique_slug( string $slug, $post_id, $post_status, $post_type, $post_parent ): string {
		if ( self::TRIP !== $post_type || ! ctype_digit( $slug ) ) {
			return $slug;
		}
		return wp_unique_post_slug( 'trip-' . $slug, $post_id, $post_status, $post_type, $post_parent );
	}

	/**
	 * Published trips are public; anything else needs edit rights.
	 */
	public static function can_view( \WP_Post $trip ): bool {
		if ( self::TRIP !== $trip->post_type ) {
			return false;
		}
		return 'publish' === $trip->post_status || current_user_can( 'edit_post', $trip->ID );
	}

	public static function url( \WP_Post $trip, string $suffix = '' ): string {
		$slug = $trip->post_name ? $trip->post_name : (string) $trip->ID;
		return App::url( 'trip/' . $slug . ( $suffix ? '/' . $suffix : '' ) );
	}

	public static function arrived( \WP_Post $step ): int {
		return (int) strtotime( get_gmt_from_date( $step->post_date ) . ' UTC' );
	}

	/**
	 * The day a step covers (Y-m-d): the date part of its post date.
	 */
	public static function day( \WP_Post $step ): string {
		return substr( $step->post_date, 0, 10 );
	}

	/**
	 * Steps shown on the trip page: published ones, plus drafts and unplaced steps for editors.
	 *
	 * @return \WP_Post[]
	 */
	public static function timeline_steps( \WP_Post $trip, bool $can_edit ): array {
		return array_values(
			array_filter(
				self::steps( $trip->ID, $can_edit ),
				fn( $step ) => $can_edit || '' !== get_post_meta( $step->ID, self::META_LAT, true )
			)
		);
	}

	/**
	 * @return \WP_Post[] Steps in chronological order.
	 */
	public static function steps( int $trip_id, bool $include_drafts = false ): array {
		return get_posts(
			[
				'post_type'      => self::STEP,
				'post_parent'    => $trip_id,
				'post_status'    => $include_drafts ? [ 'publish', 'draft', 'private', 'pending' ] : 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			]
		);
	}

	/**
	 * Unix range covered by a trip: start of the first day to the end of the last, site timezone.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function range( int $trip_id ): ?array {
		$start = (string) get_post_meta( $trip_id, self::META_START, true );
		$end   = (string) get_post_meta( $trip_id, self::META_END, true );
		if ( ! $start || ! $end ) {
			return null;
		}
		$tz   = wp_timezone();
		$from = date_create_immutable( $start . ' 00:00:00', $tz );
		$to   = date_create_immutable( $end . ' 23:59:59', $tz );
		if ( ! $from || ! $to || $to < $from ) {
			return null;
		}
		return [ $from->getTimestamp(), $to->getTimestamp() ];
	}

	/**
	 * Whether the trip's photos are the ones uploaded to it, rather than Immich's.
	 */
	public static function uses_uploads( int $trip_id ): bool {
		return self::SOURCE_UPLOADS === get_post_meta( $trip_id, self::META_SOURCE, true );
	}

	/**
	 * Where the trip's photos come from: its uploads, or Immich with the trip author's
	 * key, whoever is asking.
	 */
	public static function source( int $trip_id ): PhotoSource {
		return self::uses_uploads( $trip_id ) ? new MediaSource() : self::immich( $trip_id );
	}

	/**
	 * The trip's photos in Immich, with its author's key.
	 */
	public static function immich( int $trip_id ): ImmichSource {
		return new ImmichSource( ImmichClient::for_user( (int) get_post_field( 'post_author', $trip_id ) ) );
	}

	public static function photos( int $trip_id ): array {
		$photos = json_decode( (string) get_post_meta( $trip_id, self::META_PHOTOS, true ), true );
		return is_array( $photos ) ? $photos : [];
	}

	public static function hidden( int $trip_id ): array {
		$hidden = get_post_meta( $trip_id, self::META_HIDDEN, true );
		return is_array( $hidden ) ? $hidden : [];
	}

	/**
	 * The photo chosen for the trip's header, if it's still one of the trip's photos.
	 */
	public static function cover( int $trip_id ): string {
		$asset = (string) get_post_meta( $trip_id, self::META_COVER, true );
		return '' !== $asset && in_array( $asset, array_column( self::photos( $trip_id ), 'id' ), true ) ? $asset : '';
	}

	public static function pinned( int $trip_id ): array {
		$pinned = get_post_meta( $trip_id, self::META_PINNED, true );
		return is_array( $pinned ) ? $pinned : [];
	}

	/**
	 * The local day a photo row was taken. Rows synced before days existed lack it.
	 */
	public static function photo_day( array $photo ): string {
		return $photo['day'] ?? Days::local_day( null, (int) $photo['t'], wp_timezone() );
	}

	/**
	 * Photos per step, matched by day and split into shown / rest / hidden by
	 * Selection. Photos from days without one of $steps aren't included.
	 *
	 * @param \WP_Post[] $steps
	 * @return array<int, array{shown: array, rest: array, hidden: array}> Keyed by step id.
	 */
	public static function photos_by_step( int $trip_id, array $steps ): array {
		$step_for_day = [];
		foreach ( $steps as $step ) {
			$step_for_day[ self::day( $step ) ] ??= $step->ID;
		}
		$grouped = [];
		foreach ( self::photos( $trip_id ) as $photo ) {
			$step_id = $step_for_day[ self::photo_day( $photo ) ] ?? 0;
			if ( $step_id ) {
				$grouped[ $step_id ][] = $photo;
			}
		}
		$pinned = self::pinned( $trip_id );
		$hidden = self::hidden( $trip_id );
		$limit  = (int) Settings::get( 'auto_pick' );
		return array_map( fn( $photos ) => Selection::for_step( $photos, $pinned, $hidden, $limit ), $grouped );
	}

	/**
	 * Asset ids anyone may fetch, whoever is asking: non-hidden photos of a published
	 * trip's published steps, and its chosen cover.
	 *
	 * @return array<string, true>
	 */
	public static function public_photo_ids( \WP_Post $trip ): array {
		if ( self::TRIP !== $trip->post_type || 'publish' !== $trip->post_status ) {
			return [];
		}
		$ids = [];
		foreach ( self::photos_by_step( $trip->ID, self::steps( $trip->ID ) ) as $groups ) {
			foreach ( array_merge( $groups['shown'], $groups['rest'] ) as $photo ) {
				$ids[ $photo['id'] ] = true;
			}
		}
		// The chosen cover is public with the trip, whichever step it's from.
		$cover = self::cover( $trip->ID );
		if ( '' !== $cover ) {
			$ids[ $cover ] = true;
		}
		return $ids;
	}

	private function trip_from_request(): \WP_Post {
		$trip = get_post( absint( $_POST['trip_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by callers.
		if ( ! $trip || ! self::can_edit_trip( $trip->ID ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this trip.', 'travel-steps' ), 403 );
		}
		return $trip;
	}

	public function handle_save_trip(): void {
		check_admin_referer( 'travel_steps_save_trip' );
		$input   = wp_unslash( $_POST );
		$trip_id = absint( $input['trip_id'] ?? 0 );

		if ( $trip_id ) {
			$this->trip_from_request();
			if ( ! self::can_sync_trip( $trip_id ) && array_intersect_key( $input, array_flip( [ 'start', 'end', 'album', 'photos', 'sync' ] ) ) ) {
				wp_die( esc_html__( 'Only the trip’s author can change its dates or photos, or sync it.', 'travel-steps' ), 403 );
			}
		} elseif ( ! current_user_can( 'edit_posts' ) || ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create trips.', 'travel-steps' ), 403 );
		}

		$status = in_array( $input['status'] ?? '', [ 'draft', 'publish', 'private' ], true ) ? $input['status'] : 'draft';
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'draft';
		}
		$postarr = [
			'ID'           => $trip_id,
			'post_type'    => self::TRIP,
			'post_title'   => sanitize_text_field( $input['title'] ?? '' ) ?: __( 'Untitled trip', 'travel-steps' ),
			'post_content' => wp_kses_post( $input['intro'] ?? '' ),
			'post_status'  => $status,
		];
		// WordPress leaves drafts without a slug; give the trip one so its URL is stable.
		$current = $trip_id ? get_post( $trip_id ) : null;
		if ( ! $current || ! $current->post_name ) {
			$postarr['post_name'] = wp_unique_post_slug( sanitize_title( $postarr['post_title'] ), $trip_id, 'publish', self::TRIP, 0 );
		}
		$result  = $trip_id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$trip_id = (int) $result;

		// Only the author sends these (others' forms leave them out).
		if ( self::can_sync_trip( $trip_id ) ) {
			foreach ( [ 'start' => self::META_START, 'end' => self::META_END ] as $field => $key ) {
				$date = sanitize_text_field( $input[ $field ] ?? '' );
				// A real date, not just its shape: 2025-06-32 would leave the trip without a range.
				$ymd = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
				update_post_meta( $trip_id, $key, $ymd ? $date : '' );
			}
			// The "Photos" select: uploads, all Immich photos, or an album id. When Immich
			// can't list albums, an album id can be typed beside it. Left alone when the form
			// doesn't offer the choice; uploads keep the album, for switching back.
			if ( isset( $input['photos'] ) ) {
				$photos  = (string) $input['photos'];
				$uploads = self::SOURCE_UPLOADS === $photos;
				update_post_meta( $trip_id, self::META_SOURCE, $uploads ? self::SOURCE_UPLOADS : self::SOURCE_IMMICH );
				if ( ! $uploads ) {
					$album = self::SOURCE_IMMICH === $photos ? (string) ( $input['album'] ?? '' ) : $photos;
					update_post_meta( $trip_id, self::META_ALBUM, preg_replace( '/[^a-f0-9-]/i', '', $album ) );
				}
			}
		}

		$trip = get_post( $trip_id );
		if ( ! empty( $input['sync'] ) ) {
			$this->run_sync_and_redirect( $trip );
		}
		// A new trip of uploads has none yet: its edit page opens the media modal.
		$args = [ 'saved' => '1' ] + ( ! $current && self::uses_uploads( $trip_id ) ? [ 'add' => '1' ] : [] );
		wp_safe_redirect( add_query_arg( $args, self::url( $trip, 'edit' ) ) );
		exit;
	}

	public function handle_sync(): void {
		check_admin_referer( 'travel_steps_sync' );
		$trip = $this->trip_from_request();
		if ( ! self::can_sync_trip( $trip->ID ) ) {
			wp_die( esc_html__( 'Only the trip’s author can sync it.', 'travel-steps' ), 403 );
		}
		$this->run_sync_and_redirect( $trip );
	}

	private function run_sync_and_redirect( \WP_Post $trip ): void {
		$result = ( new TripSync() )->sync( $trip->ID );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $trip->ID, self::META_ERROR, $result->get_error_message() );
			wp_safe_redirect( add_query_arg( 'synced', 'error', self::url( $trip, 'edit' ) ) );
			exit;
		}
		delete_post_meta( $trip->ID, self::META_ERROR );
		$args = [
			'synced'       => '1',
			'new_steps'    => (int) $result['new_steps'],
			'skipped_days' => (int) $result['skipped_days'],
		];
		// For a trip of uploads: what sync left out or couldn't place, for the notice.
		if ( isset( $result['left_out'] ) ) {
			$args += array_map( 'intval', $result['left_out'] ) + [
				'unlocated' => (int) $result['unlocated'],
				'no_places' => (int) $result['no_places'],
			];
		}
		wp_safe_redirect( add_query_arg( $args, self::url( $trip, 'edit' ) ) );
		exit;
	}

	/**
	 * Create an empty draft step and open it in the block editor.
	 */
	public function handle_add_step(): void {
		check_admin_referer( 'travel_steps_add_step' );
		$trip    = $this->trip_from_request();
		$range   = self::range( $trip->ID );
		$arrived = $range ? $range[0] + 12 * HOUR_IN_SECONDS : time();
		// Set the post date to when you arrived; the next sync places the step on the
		// map from your Dawarich location at that time.
		$step_id = wp_insert_post(
			[
				'post_type'     => self::STEP,
				'post_parent'   => $trip->ID,
				'post_title'    => __( 'New step', 'travel-steps' ),
				'post_status'   => 'draft',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $arrived ),
				'post_date'     => wp_date( 'Y-m-d H:i:s', $arrived ),
				'edit_date'     => true,
			],
			true
		);
		if ( is_wp_error( $step_id ) ) {
			wp_die( esc_html( $step_id->get_error_message() ) );
		}
		wp_safe_redirect( get_edit_post_link( $step_id, 'raw' ) );
		exit;
	}
}
