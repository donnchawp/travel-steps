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
	const TRIP = 'ts_trip';
	const STEP = 'ts_step';

	// Trip meta.
	const META_START  = '_ts_start';       // Y-m-d, site timezone.
	const META_END    = '_ts_end';         // Y-m-d, inclusive.
	const META_ALBUM  = '_ts_album';       // Immich album id (optional).
	const META_ROUTE  = '_ts_route';       // JSON: list of segments, each a list of [lat, lon].
	const META_PHOTOS = '_ts_photos';      // JSON: list of photo rows (see ImmichClient::normalise) + step.
	const META_HIDDEN = '_ts_hidden';      // array of hidden Immich asset ids.
	const META_PINNED = '_ts_pinned';      // array of Immich asset ids always shown.
	const META_STATS  = '_ts_stats';       // array: distance_km, points, photos, countries.
	const META_SYNCED = '_ts_synced';      // unix timestamp of last sync.
	const META_ERROR  = '_ts_sync_error';  // last sync error message.

	// Step meta.
	const META_LAT      = '_ts_lat';
	const META_LON      = '_ts_lon';
	const META_DEPARTED = '_ts_departed';  // unix. Arrival is the step's post date.
	const META_AUTO     = '_ts_auto';      // 1 when created by sync.

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
				'supports'     => [ 'title', 'editor', 'custom-fields' ],
				'rewrite'      => false,
			]
		);

		// Exposed over REST so the step can be repositioned programmatically. A step's
		// arrival time is its post date, which is editable in the block editor.
		foreach ( [ self::META_LAT => 'number', self::META_LON => 'number', self::META_DEPARTED => 'integer' ] as $key => $type ) {
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

	public static function find_by_slug( string $slug ): ?\WP_Post {
		// Drafts have no slug until published, so their URLs use the post ID.
		if ( ctype_digit( $slug ) ) {
			$post = get_post( (int) $slug );
			return $post && self::TRIP === $post->post_type ? $post : null;
		}
		$posts = get_posts(
			[
				'post_type'      => self::TRIP,
				'name'           => sanitize_title( $slug ),
				'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'posts_per_page' => 1,
			]
		);
		return $posts[0] ?? null;
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
		return (int) strtotime( $step->post_date_gmt . ' UTC' );
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

	public static function photos( int $trip_id ): array {
		$photos = json_decode( (string) get_post_meta( $trip_id, self::META_PHOTOS, true ), true );
		return is_array( $photos ) ? $photos : [];
	}

	public static function hidden( int $trip_id ): array {
		$hidden = get_post_meta( $trip_id, self::META_HIDDEN, true );
		return is_array( $hidden ) ? $hidden : [];
	}

	public static function pinned( int $trip_id ): array {
		$pinned = get_post_meta( $trip_id, self::META_PINNED, true );
		return is_array( $pinned ) ? $pinned : [];
	}

	/**
	 * Photos per step, split into shown / rest / hidden by Selection.
	 *
	 * @return array<int, array{shown: array, rest: array, hidden: array}> Keyed by step id.
	 */
	public static function photos_by_step( int $trip_id ): array {
		$grouped = [];
		foreach ( self::photos( $trip_id ) as $photo ) {
			$grouped[ (int) ( $photo['step'] ?? 0 ) ][] = $photo;
		}
		$pinned = self::pinned( $trip_id );
		$hidden = self::hidden( $trip_id );
		$limit  = (int) Settings::get( 'auto_pick' );
		return array_map( fn( $photos ) => Selection::for_step( $photos, $pinned, $hidden, $limit ), $grouped );
	}

	private function trip_from_request(): \WP_Post {
		$trip = get_post( absint( $_POST['trip_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by callers.
		if ( ! $trip || self::TRIP !== $trip->post_type || ! current_user_can( 'edit_post', $trip->ID ) ) {
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
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
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

		foreach ( [ 'start' => self::META_START, 'end' => self::META_END ] as $field => $key ) {
			$date = sanitize_text_field( $input[ $field ] ?? '' );
			update_post_meta( $trip_id, $key, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );
		}
		update_post_meta( $trip_id, self::META_ALBUM, preg_replace( '/[^a-f0-9-]/i', '', (string) ( $input['album'] ?? '' ) ) );

		$trip = get_post( $trip_id );
		if ( ! empty( $input['sync'] ) ) {
			$this->run_sync_and_redirect( $trip );
		}
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url( $trip, 'edit' ) ) );
		exit;
	}

	public function handle_sync(): void {
		check_admin_referer( 'travel_steps_sync' );
		$this->run_sync_and_redirect( $this->trip_from_request() );
	}

	private function run_sync_and_redirect( \WP_Post $trip ): void {
		$result = ( new TripSync() )->sync( $trip->ID );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $trip->ID, self::META_ERROR, $result->get_error_message() );
			wp_safe_redirect( add_query_arg( 'synced', 'error', self::url( $trip, 'edit' ) ) );
			exit;
		}
		delete_post_meta( $trip->ID, self::META_ERROR );
		wp_safe_redirect(
			add_query_arg(
				[
					'synced'    => '1',
					'new_steps' => (int) $result['new_steps'],
				],
				self::url( $trip, 'edit' )
			)
		);
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
