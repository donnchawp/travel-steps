<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trip and step post types, and the form handlers that edit them.
 *
 * A trip is a `travel_steps_trip` post. Each step is a `travel_steps_step` post whose
 * post_parent is the trip, so its story can be written in the block editor.
 */
class Trips {
	const TRIP  = 'travel_steps_trip';
	const STEP  = 'travel_steps_step';
	const PLACE = 'travel_steps_place';
	// Set once migrate_names() has moved the old ts_ names.
	const NAMES_MOVED = 'travel_steps_names_moved';

	// Trip meta.
	const META_START  = '_travel_steps_start';          // Y-m-d, site timezone.
	const META_END    = '_travel_steps_end';            // Y-m-d, inclusive.
	const META_ALBUM  = '_travel_steps_album';          // Immich album id (optional).
	const META_DATES_AUTO = '_travel_steps_dates_auto'; // [ first, last ]: the dates sync took from a trip's uploads or album, '' for one set by hand.
	const META_SOURCE = '_travel_steps_source';         // SOURCE_UPLOADS or SOURCE_IMMICH; empty means Immich.
	const SOURCE_UPLOADS = 'uploads';
	const SOURCE_IMMICH  = 'immich';
	const META_ROUTE  = '_travel_steps_route';          // JSON: list of segments, each a list of [lat, lon, t] ([lat, lon] if synced before 0.6).
	const META_BOXES  = '_travel_steps_boxes';          // JSON: the route's track as Geo::time_boxes() rows.
	const META_PHOTOS = '_travel_steps_photos';         // JSON: list of photo rows (see PhotoSource::photos) + step.
	const META_HIDDEN = '_travel_steps_hidden';         // array of hidden photo ids.
	const META_PINNED = '_travel_steps_pinned';         // array of photo ids always shown.
	const META_COVER  = '_travel_steps_cover';          // photo id chosen for the header, else automatic.
	const META_STATS  = '_travel_steps_stats';          // array: distance_km, points, photos, countries (ISO codes, else case-folded names).
	const META_SYNCED = '_travel_steps_synced';         // unix timestamp of last sync.
	const META_ERROR  = '_travel_steps_sync_error';     // last sync error message.
	const META_VIEWERS = '_travel_steps_viewers';       // array of user ids who see the trip without its password.
	// The query argument a share link carries its code in.
	const SHARE_ARG = 'share';
	// The inline tags a trip's intro can have and still be edited as text on its edit page.
	const INTRO_TAGS = [ 'a', 'abbr', 'b', 'br', 'code', 'em', 'i', 'kbd', 'mark', 's', 'span', 'strong', 'sub', 'sup' ];
	// What can follow a < that starts a tag or comment. Any other < in an intro is text, so
	// "</3" and "<?" aren't read as broken tags and dropped.
	const TAG_START = '(?:[a-z]|/[a-z]|!--)';

	// Step meta. A step is a day: the date part of its post date.
	const META_LAT  = '_travel_steps_lat';
	const META_LON  = '_travel_steps_lon';
	const META_DAY  = '_travel_steps_day'; // Y-m-d sync created the step for; kept even if you move it.

	// WP_Query's single-post-ID args, each with the conversion it applies. Any of them wins
	// over post__in, so exclude_from_query() checks them and match_nothing() drops them.
	private const SINGLE_IDS = [ 'p' => 'intval', 'page_id' => 'absint', 'attachment_id' => 'absint', 'subpost_id' => 'absint' ];

	private ?TripSync $sync;

	/**
	 * @param TripSync|null $sync What the sync form runs; by default, one with each trip's own sources.
	 */
	public function __construct( ?TripSync $sync = null ) {
		$this->sync = $sync;
	}

	public static function register_post_types(): void {
		register_post_type(
			self::TRIP,
			[
				'labels'       => [
					'name'          => __( 'Trips', 'travel-steps-storyteller' ),
					'singular_name' => __( 'Trip', 'travel-steps-storyteller' ),
					'add_new_item'  => __( 'Add trip', 'travel-steps-storyteller' ),
					'edit_item'     => __( 'Edit trip', 'travel-steps-storyteller' ),
				],
				// Rendered by the app at /travel-steps/trip/{slug}, not by the theme.
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				// Core REST would otherwise show a password-protected trip's title to anyone.
				'rest_controller_class' => TripsRestController::class,
				'menu_icon'    => 'dashicons-location-alt',
				'supports'     => [ 'title', 'editor', 'excerpt', 'custom-fields', 'thumbnail' ],
				'rewrite'      => false,
			]
		);
		register_post_type(
			self::STEP,
			[
				'labels'       => [
					'name'          => __( 'Steps', 'travel-steps-storyteller' ),
					'singular_name' => __( 'Step', 'travel-steps-storyteller' ),
					'edit_item'     => __( 'Edit step', 'travel-steps-storyteller' ),
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
					'name'          => __( 'Places', 'travel-steps-storyteller' ),
					'singular_name' => __( 'Place', 'travel-steps-storyteller' ),
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

		// Exposed over REST so the step can be repositioned programmatically. The next sync
		// overwrites the position when it finds one and clears it when it doesn't.
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
	 * Once, on upgrade from 0.6.1 or earlier: move the post types, the taxonomy and the
	 * meta keys from their old ts_ prefix to travel_steps_, so they can't clash with
	 * another plugin's.
	 *
	 * Each query only finds old names, so a migration that fails or dies part-way runs
	 * again: the flag is set last, and only if every query worked.
	 */
	public static function migrate_names(): void {
		if ( get_option( self::NAMES_MOVED ) ) {
			return;
		}
		global $wpdb;
		$types = [ 'ts_trip' => self::TRIP, 'ts_step' => self::STEP, 'ts_subscription' => Subscriptions::TYPE ];
		$trips = [ 'ts_trip', self::TRIP ];
		// The keys as they were named up to 0.6.1, only on this plugin's posts and on photos
		// uploaded to a trip, so another plugin's _ts_ meta stays. Meta goes first: until a
		// post has its new type, nothing can give it meta under the new keys.
		$keys = [
			'start', 'end', 'album', 'dates_auto', 'source', 'route', 'boxes', 'photos', 'hidden', 'pinned', 'cover', 'stats',
			'synced', 'sync_error', 'viewers', 'lat', 'lon', 'day', 'photo_id', 'exif', 'taken', 'checked', 'version',
			'email', 'user', 'status', 'token', 'mailed', 'share', 'roundup_state', 'roundup_last',
		];
		$keys  = array_map( fn( $key ) => '_ts_' . $key, $keys );
		$owned = array_merge( array_keys( $types ), array_values( $types ) );
		$in    = fn( array $list ) => implode( ',', array_fill( 0, count( $list ), '%s' ) );
		$set   = "SET pm.meta_key = CONCAT( '_travel_steps_', SUBSTRING( pm.meta_key, 5 ) ) WHERE pm.meta_key IN ( {$in( $keys )} )";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// Two queries, not a LEFT JOIN: Playground's SQLite driver drops the posts without a parent.
		$moved = [
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id $set AND p.post_type IN ( {$in( $owned )} )", array_merge( $keys, $owned ) ) ),
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id JOIN {$wpdb->posts} t ON t.ID = p.post_parent $set AND 'attachment' = p.post_type AND t.post_type IN ( {$in( $trips )} )", array_merge( $keys, $trips ) ) ),
		];
		foreach ( $types as $old => $new ) {
			$moved[] = $wpdb->update( $wpdb->posts, [ 'post_type' => $new ], [ 'post_type' => $old ] );
		}
		$moved[] = $wpdb->update( $wpdb->term_taxonomy, [ 'taxonomy' => self::PLACE ], [ 'taxonomy' => 'ts_place' ] );
		// phpcs:enable
		// Cached posts, terms and meta still have the old names.
		if ( array_filter( $moved ) ) {
			wp_cache_flush();
		}
		if ( in_array( false, $moved, true ) ) {
			return;
		}
		add_option( self::NAMES_MOVED, time() );
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

	/**
	 * A step only goes into a trip its saver can edit. Core lets anyone who can write a
	 * step set its parent (wp-admin's parent_id, XML-RPC's post_parent), and a published
	 * step shows on the trip and makes the trip's photos from its day public. Without a
	 * user (cron, WP-CLI), the code saving it is trusted.
	 */
	public static function step_parent( array $data, array $postarr ): array {
		if ( self::STEP !== $data['post_type'] || ! get_current_user_id() ) {
			return $data;
		}
		$saved  = empty( $postarr['ID'] ) ? 0 : (int) get_post_field( 'post_parent', (int) $postarr['ID'] );
		$parent = (int) $data['post_parent'];
		if ( $parent && $parent !== $saved && ! self::can_edit_trip( $parent ) ) {
			$data['post_parent'] = $saved;
		}
		return $data;
	}

	/**
	 * Core makes a slug unique only once a post is published, private or scheduled, so a
	 * draft trip could be saved with another trip's slug and take over its URL. A trip's
	 * slug is its URL from the start, so it's made unique whatever the status.
	 */
	public static function draft_slug( array $data, array $postarr ): array {
		if ( self::TRIP === $data['post_type'] && '' !== $data['post_name'] && in_array( $data['post_status'], [ 'draft', 'pending' ], true ) ) {
			$data['post_name'] = wp_unique_post_slug( $data['post_name'], (int) ( $postarr['ID'] ?? 0 ), 'publish', self::TRIP, 0 );
		}
		return $data;
	}

	/**
	 * Steps have no password of their own: a trip's password covers its steps, and the
	 * trip page, its map data and its photos never ask about a step's. Core's editor
	 * offers one for any post, so it's dropped whoever saves it.
	 */
	public static function step_password( array $data ): array {
		if ( self::STEP === $data['post_type'] ) {
			$data['post_password'] = '';
		}
		return $data;
	}

	/**
	 * Trips saved before draft_slug() may share a slug: a published, private or scheduled
	 * one keeps its URL, then comes a trip the visitor may edit, then the oldest.
	 */
	public static function find_by_slug( string $slug ): ?\WP_Post {
		$posts = get_posts(
			[
				'post_type'      => self::TRIP,
				'name'           => sanitize_title( $slug ),
				'post_status'    => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		$picks = [
			fn( \WP_Post $post ) => ! in_array( $post->post_status, [ 'draft', 'pending' ], true ),
			fn( \WP_Post $post ) => current_user_can( 'edit_post', $post->ID ),
			'__return_true',
		];
		foreach ( $picks as $pick ) {
			foreach ( $posts as $post ) {
				if ( $pick( $post ) ) {
					return $post;
				}
			}
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
	 * Published trips are public, unless they need a password the visitor hasn't given;
	 * anything else needs edit rights.
	 */
	public static function can_view( \WP_Post $trip ): bool {
		if ( self::TRIP !== $trip->post_type ) {
			return false;
		}
		return current_user_can( 'edit_post', $trip->ID ) || ( 'publish' === $trip->post_status && ! self::needs_password( $trip ) );
	}

	/**
	 * Trips can_view() refuses the current user, for leaving them out of a query. Only
	 * unpublished trips and published ones with a password are asked about: the rest are
	 * public. $statuses narrows that to the trips a query for those statuses could match;
	 * empty or 'any' means every status.
	 *
	 * @param string[] $statuses
	 * @return int[]
	 */
	public static function hidden_trip_ids( array $statuses = [] ): array {
		if ( ! $statuses || in_array( 'any', $statuses, true ) ) {
			$statuses = get_post_stati();
		}
		$candidates  = [
			'post_type'              => self::TRIP,
			'numberposts'            => -1,
			'orderby'                => 'none',
			'update_post_term_cache' => false, // can_view() reads no terms.
		];
		$unpublished = array_diff( $statuses, [ 'publish' ] );
		// can_view() decides an unpublished trip without its meta, so none is loaded for
		// it. The exception is a trashed trip of the current user's: map_meta_cap() reads
		// its trash status, which loads that trip's meta on its own. A published trip's
		// meta is loaded only for a logged-in user: needs_password() may read their viewer
		// lists, and loading one key lazily would fetch every row anyway. For a visitor
		// it reads none.
		$trips = array_merge(
			$unpublished ? get_posts( [ 'post_status' => $unpublished, 'update_post_meta_cache' => false ] + $candidates ) : [],
			in_array( 'publish', $statuses, true ) ? get_posts( [ 'post_status' => 'publish', 'has_password' => true, 'update_post_meta_cache' => is_user_logged_in() ] + $candidates ) : []
		);
		return array_column( array_filter( $trips, fn( $trip ) => ! self::can_view( $trip ) ), 'ID' );
	}

	/**
	 * The parents of steps that aren't in a trip: 0, a trip deleted since, or another
	 * post. Such a step is visible to nobody but its editors.
	 *
	 * @return int[]
	 */
	public static function stray_step_parents(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WP_Query asks this.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT s.post_parent FROM {$wpdb->posts} s LEFT JOIN {$wpdb->posts} t ON t.ID = s.post_parent AND t.post_type = %s WHERE s.post_type = %s AND t.ID IS NULL", self::TRIP, self::STEP ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Query args that match none of $ids by $field ('post' or 'post_parent'), on top of
	 * any exclusions they had. WP_Query ignores {$field}__not_in once a single ID (p,
	 * page_id, attachment_id, subpost_id; a numeric post_parent) or {$field}__in is set,
	 * so a single ID among $ids matches nothing, and the IDs come out of {$field}__in,
	 * which matches nothing if none are left. Each value is read as WP_Query reads it:
	 * (int) for p, absint for the other single IDs and for each entry of {$field}__in.
	 * By post_parent, a page_id becomes p: WP_Query replaces its whole WHERE clause with
	 * one for page_id, post_parent__not_in and all, but p keeps it. As in core, a page_id
	 * wins over p unless it reads as 0.
	 *
	 * @param int[] $ids
	 */
	public static function exclude_from_query( array $args, string $field, array $ids ): array {
		if ( ! $ids ) {
			return $args;
		}
		if ( 'post_parent' === $field && isset( $args['page_id'] ) ) {
			$page_id = is_scalar( $args['page_id'] ) ? absint( $args['page_id'] ) : 0;
			unset( $args['page_id'] );
			if ( $page_id ) {
				$args['p'] = $page_id;
			}
		}
		$singles = 'post' === $field ? self::SINGLE_IDS : [ 'post_parent' => fn( $id ) => is_numeric( $id ) ? (int) $id : null ];
		foreach ( $singles as $single => $read ) {
			if ( isset( $args[ $single ] ) && is_scalar( $args[ $single ] ) && in_array( $read( $args[ $single ] ), $ids, true ) ) {
				return self::match_nothing( $args );
			}
		}
		$in = $field . '__in';
		if ( ! empty( $args[ $in ] ) ) {
			$args[ $in ] = array_values( array_diff( array_map( 'absint', (array) $args[ $in ] ), $ids ) );
			if ( ! $args[ $in ] ) {
				return self::match_nothing( $args );
			}
		}
		$not_in          = $field . '__not_in';
		$args[ $not_in ] = array_merge( (array) ( $args[ $not_in ] ?? [] ), $ids );
		return $args;
	}

	/**
	 * Query args that match no post: post__in [0], with the single IDs that would win
	 * over it taken out. page_id has to go rather than be outranked: WP_Query replaces
	 * its whole WHERE clause with one for page_id.
	 */
	private static function match_nothing( array $args ): array {
		return [ 'post__in' => [ 0 ] ] + array_diff_key( $args, self::SINGLE_IDS );
	}

	/**
	 * Whether a trip has a password: core's post password. Empty as core's
	 * post_password_required() sees it, so "0" is no password.
	 */
	public static function is_protected( \WP_Post $trip ): bool {
		return ! empty( $trip->post_password );
	}

	/**
	 * Whether anyone may see a trip: it's published without a password.
	 */
	public static function is_public( \WP_Post $trip ): bool {
		return 'publish' === $trip->post_status && ! self::is_protected( $trip );
	}

	/**
	 * Whether shared caches may keep what the current user is sent of a trip (its REST
	 * response and photos): it's public, and they can't edit it. Editors' REST response
	 * holds what only editors see, the markers of draft steps and can_edit.
	 */
	public static function shared_cacheable( \WP_Post $trip ): bool {
		return self::is_public( $trip ) && ! current_user_can( 'edit_post', $trip->ID );
	}

	/**
	 * Whether the current user has to type a trip's password to see it: it's published
	 * with one, they may not skip it, and neither core's cookie nor the trip's share cookie
	 * holds it (see unlocked()).
	 */
	public static function needs_password( \WP_Post $trip ): bool {
		return 'publish' === $trip->post_status && self::is_protected( $trip ) && ! self::skips_password( $trip ) && post_password_required( $trip );
	}

	/**
	 * The name of core's post password cookie, which handle_password_post() sets and the
	 * password form reads.
	 */
	public static function password_cookie(): string {
		return 'wp-postpass_' . COOKIEHASH;
	}

	/**
	 * The password form posts to the trip page, so a protected trip needs no route besides
	 * its own: a site may close wp-login.php to visitors. This does what core's
	 * wp-login.php?action=postpass does: sets its cookie to a hash of whatever was typed,
	 * without checking it, and sends the visitor back to the trip with "tried", where
	 * needs_password() decides. No nonce, as in core: a forged post can only set a cookie
	 * for a password the forger already knows. Returns if there's nothing to handle.
	 */
	public static function handle_password_post( \WP_Post $trip ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- As core's form handler.
		if ( ! isset( $_POST['post_password'] ) || 'publish' !== $trip->post_status || ! self::is_protected( $trip ) ) {
			return;
		}
		$to = add_query_arg( 'tried', '1', self::url( $trip ) );
		if ( is_string( $_POST['post_password'] ) ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Hashed, as core does: sanitising would change the password.
			self::set_cookie( self::password_cookie(), ( new \PasswordHash( 8, true ) )->HashPassword( wp_unslash( $_POST['post_password'] ) ), $to );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * A protected trip's share code: a keyed hash of its password, so a link made with it
	 * stops working when the password changes, and gives nothing of the password away.
	 * Empty for a trip with no password to skip.
	 */
	public static function share_code( \WP_Post $trip ): string {
		return 'publish' === $trip->post_status && self::is_protected( $trip ) ? wp_hash( 'share|' . $trip->ID . '|' . $trip->post_password ) : '';
	}

	/**
	 * The link that opens a protected trip without its password, or empty if it has none.
	 */
	public static function share_url( \WP_Post $trip ): string {
		$code = self::share_code( $trip );
		return '' === $code ? '' : add_query_arg( self::SHARE_ARG, $code, self::url( $trip ) );
	}

	/**
	 * Whether a value from the request is the trip's share code.
	 *
	 * @param mixed $code
	 */
	public static function share_matches( \WP_Post $trip, $code ): bool {
		if ( ! is_string( $code ) || '' === $code ) {
			return false;
		}
		$expected = self::share_code( $trip );
		return '' !== $expected && hash_equals( $expected, $code );
	}

	/**
	 * The name of the cookie a trip's share link sets. It starts as core's does, so page
	 * caches that skip visitors with core's cookie skip these too, but never equals it.
	 */
	public static function share_cookie( \WP_Post $trip ): string {
		return 'wp-postpass_travel-steps_' . $trip->ID . '_' . COOKIEHASH;
	}

	/**
	 * Core's post_password_required filter: a trip is unlocked for a visitor who opened
	 * its current share link before, whose cookie holds the trip's code. On the filter,
	 * so core's own checks (its REST content, say) agree with the trip page's.
	 */
	public static function unlocked( $required, $post ): bool {
		$post = get_post( $post );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with the code.
		return (bool) $required && ! ( $post && self::TRIP === $post->post_type && self::share_matches( $post, wp_unslash( $_COOKIE[ self::share_cookie( $post ) ] ?? '' ) ) );
	}

	/**
	 * Lets the visitor in if the request carries the trip's share code: sets the trip's
	 * share cookie, and the request's copy of it, so the trip shows in this request. A
	 * preview fetcher keeps no cookie across a redirect, so there's none. Its own cookie,
	 * holding the code, not core's, which holds a hash of the password. Returns whether
	 * it did.
	 */
	public static function handle_share_link( \WP_Post $trip ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with the code.
		$code = wp_unslash( $_GET[ self::SHARE_ARG ] ?? '' );
		if ( ! self::share_matches( $trip, $code ) ) {
			return false;
		}
		self::set_cookie(
			self::share_cookie( $trip ),
			$code,
			self::url( $trip ),
			[
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		// The page's requests carry only the origin, not the code: the map's tile server
		// refuses a request with no referrer at all.
		header( 'Referrer-Policy: strict-origin' );
		return true;
	}

	/**
	 * Sets a cookie that unlocks a trip, as wp-login.php sets core's post password
	 * cookie, for the request as well as the browser.
	 *
	 * @param string $url     Where the visitor goes next: the cookie is secure if that's https.
	 * @param array  $options More of setcookie()'s options.
	 */
	private static function set_cookie( string $name, string $value, string $url, array $options = [] ): void {
		/** This filter is documented in wp-login.php */
		$expire = apply_filters( 'post_password_expires', time() + 10 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, so a site's cookie lifetime applies here too.
		setcookie(
			$name,
			$value,
			$options + [
				'expires' => $expire,
				'path'    => COOKIEPATH,
				'domain'  => COOKIE_DOMAIN,
				'secure'  => 'https' === wp_parse_url( $url, PHP_URL_SCHEME ),
			]
		);
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Whether the current user, or $user_id, may see a protected trip without typing its
	 * password: they can edit it, or they're logged in and on its author's viewer list or
	 * its own.
	 */
	public static function skips_password( \WP_Post $trip, ?int $user_id = null ): bool {
		$can_edit = null === $user_id ? current_user_can( 'edit_post', $trip->ID ) : user_can( $user_id, 'edit_post', $trip->ID );
		if ( $can_edit ) {
			return true;
		}
		$user = $user_id ?? get_current_user_id();
		return $user && in_array( $user, array_merge( Settings::viewers( (int) $trip->post_author ), self::viewers( $trip->ID ) ), true );
	}

	/**
	 * Whether the current user's requests for a trip's REST must carry a REST nonce,
	 * which REST needs to know who they are: they see it by their login, as it isn't
	 * public. Visitors with a protected trip's password need none: core's cookie, and a
	 * share link's, is read without one. Photos need none either: see RestController::serve_photo().
	 */
	public static function by_login( \WP_Post $trip ): bool {
		return ! self::is_public( $trip ) && self::skips_password( $trip );
	}

	/**
	 * Users who see the trip without its password, besides its author's viewer list.
	 *
	 * @return int[]
	 */
	public static function viewers( int $trip_id ): array {
		return self::user_ids( self::id_list( $trip_id, self::META_VIEWERS ) );
	}

	/**
	 * A list of user ids from stored or submitted values.
	 *
	 * @param mixed $ids
	 * @return int[]
	 */
	public static function user_ids( $ids ): array {
		return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : [];
	}

	/**
	 * Whether the landing page lists a trip to the current user: one they can see, and for
	 * a protected trip, only if they see it without typing its password.
	 */
	public static function listed( \WP_Post $trip ): bool {
		return self::can_view( $trip ) && ( ! self::is_protected( $trip ) || self::skips_password( $trip ) );
	}

	/**
	 * A trip's title as it is, without core's "Protected:" in front: the password form
	 * and the landing page's badge say so themselves.
	 */
	public static function protected_title_format( string $format, \WP_Post $post ): string {
		return self::TRIP === $post->post_type ? '%s' : $format;
	}

	public static function url( \WP_Post $trip, string $suffix = '' ): string {
		$slug = $trip->post_name ? $trip->post_name : (string) $trip->ID;
		return App::url( 'trip/' . $slug . ( $suffix ? '/' . $suffix : '' ) );
	}

	public static function arrived( \WP_Post $step ): int {
		return (int) get_post_timestamp( $step );
	}

	/**
	 * The day a step covers (Y-m-d): the date part of its post date.
	 */
	public static function day( \WP_Post $step ): string {
		return substr( $step->post_date, 0, 10 );
	}

	/**
	 * A step's day of the trip, the start date being day 1; 0 if the trip has no start date.
	 */
	public static function day_of_trip( int $trip_id, \WP_Post $step ): int {
		$first = (string) get_post_meta( $trip_id, self::META_START, true );
		return $first ? Days::day_number( $first, self::day( $step ) ) : 0;
	}

	/**
	 * Steps shown on the trip page: published ones, plus unpublished steps for editors. A step with
	 * no position is shown too, with no marker on the map.
	 *
	 * @return \WP_Post[]
	 */
	public static function timeline_steps( \WP_Post $trip, bool $can_edit ): array {
		return self::steps( $trip->ID, $can_edit );
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
	 * The error for a trip that needs dates and hasn't got usable ones.
	 */
	public static function no_dates(): \WP_Error {
		return new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps-storyteller' ) );
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
		return self::decode_photos( (string) get_post_meta( $trip_id, self::META_PHOTOS, true ) );
	}

	/**
	 * A box per 10 minutes of the trip's track, as sync left them; none before its first
	 * sync with them.
	 */
	public static function boxes( int $trip_id ): array {
		$boxes = json_decode( (string) get_post_meta( $trip_id, self::META_BOXES, true ), true );
		return is_array( $boxes ) ? $boxes : [];
	}

	/**
	 * A trip's photos from the JSON stored in its META_PHOTOS.
	 */
	public static function decode_photos( string $json ): array {
		$photos = json_decode( $json, true );
		return is_array( $photos ) ? $photos : [];
	}

	public static function hidden( int $trip_id ): array {
		return self::id_list( $trip_id, self::META_HIDDEN );
	}

	/**
	 * The photo chosen for the trip's header, if it's still one of the trip's photos.
	 */
	public static function cover( int $trip_id ): string {
		$asset = (string) get_post_meta( $trip_id, self::META_COVER, true );
		return '' !== $asset && in_array( $asset, array_column( self::photos( $trip_id ), 'id' ), true ) ? $asset : '';
	}

	public static function pinned( int $trip_id ): array {
		return self::id_list( $trip_id, self::META_PINNED );
	}

	private static function id_list( int $trip_id, string $key ): array {
		$ids = get_post_meta( $trip_id, $key, true );
		return is_array( $ids ) ? $ids : [];
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
	 * Asset ids anyone who can see a published trip may fetch: non-hidden photos of its
	 * published steps, and its chosen cover.
	 *
	 * @return array<string, true>
	 */
	public static function shown_photo_ids( \WP_Post $trip ): array {
		if ( self::TRIP !== $trip->post_type || 'publish' !== $trip->post_status ) {
			return [];
		}
		return self::visible_photo_ids( $trip->ID, self::steps( $trip->ID ) );
	}

	/**
	 * Asset ids of a trip's photos visitors see on $steps once they're published: the
	 * non-hidden photos of those steps, and the chosen cover.
	 *
	 * @param \WP_Post[] $steps
	 * @return array<string, true>
	 */
	public static function visible_photo_ids( int $trip_id, array $steps ): array {
		$ids = self::photo_id_set( self::visible_photos_by_step( self::photos_by_step( $trip_id, $steps ) ) );
		// The chosen cover is public with the trip, whichever step it's from.
		$cover = self::cover( $trip_id );
		if ( '' !== $cover ) {
			$ids[ $cover ] = true;
		}
		return $ids;
	}

	/**
	 * Asset ids of the photos visitors see on each step once it's published, from
	 * photos_by_step(): its non-hidden photos, shown or behind "Show more".
	 *
	 * @param array<int, array{shown: array, rest: array, hidden: array}> $by_step
	 * @return array<int, string[]> Keyed by step id, as $by_step; [] for a step whose photos are all hidden.
	 */
	public static function visible_photos_by_step( array $by_step ): array {
		return array_map( fn( $groups ) => array_column( array_merge( $groups['shown'], $groups['rest'] ), 'id' ), $by_step );
	}

	/**
	 * Asset ids of the photos hidden from visitors on each step, from photos_by_step().
	 *
	 * @param array<int, array{shown: array, rest: array, hidden: array}> $by_step
	 * @return array<int, string[]> Keyed by step id; a step with none hidden is left out.
	 */
	public static function hidden_photos_by_step( array $by_step ): array {
		return array_filter( array_map( fn( $groups ) => array_column( $groups['hidden'], 'id' ), $by_step ) );
	}

	/**
	 * Every asset id in a map of step id to asset ids, as a set.
	 *
	 * @param array<int, string[]> $by_step
	 * @return array<string, true>
	 */
	public static function photo_id_set( array $by_step ): array {
		return array_fill_keys( array_merge( [], ...array_values( $by_step ) ), true );
	}

	private function trip_from_request(): \WP_Post {
		$trip = get_post( absint( $_POST['trip_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by callers.
		if ( ! $trip || ! self::can_edit_trip( $trip->ID ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this trip.', 'travel-steps-storyteller' ), 403 );
		}
		return $trip;
	}

	/**
	 * A trip's intro as the edit form's plain text: its paragraphs, a blank line apart, with
	 * their inline HTML kept. Null when it has anything else (an image, a heading, a list, a
	 * paragraph with settings of its own), which only the block editor can change safely.
	 */
	public static function intro_text( string $content ): ?string {
		$paragraphs = [];
		foreach ( parse_blocks( str_replace( "\r\n", "\n", $content ) ) as $block ) {
			$html = trim( $block['innerHTML'] );
			if ( null === $block['blockName'] ) {
				// Classic content: paragraphs as <p>, or plain text with blank lines between them.
				$html = str_ireplace( [ '<p>', '</p>' ], [ '', "\n\n" ], $html );
			} elseif ( 'core/paragraph' === $block['blockName'] && ! $block['attrs'] && preg_match( '#^<p>(.*)</p>$#is', $html, $m ) ) {
				$html = $m[1];
			} else {
				return null;
			}
			// Two line breaks in a row would read back as a blank line, splitting the paragraph.
			if ( strip_tags( $html, self::INTRO_TAGS ) !== $html || preg_match( '#<br\s*/?>\s*<br#i', $html ) ) {
				return null;
			}
			// An ampersand shows as typed; kses encodes it again on save.
			$html = preg_replace( [ '#<br\s*/?>\n?#i', '/&amp;(?![a-z0-9#]+;)/i' ], [ "\n", '&' ], $html );
			// So does a < or > that starts no tag, as intro_content() stores it, outside tags only:
			// a > typed in an attribute would end its tag. A tag's name written out, such as
			// &lt;b&gt;, stays as it is: typed, it would be a tag.
			$html = preg_replace_callback(
				'#(<[^>]*>|&lt;' . self::TAG_START . '[^&<>]*&gt;)|&lt;(?!' . self::TAG_START . ')|&gt;#i',
				fn( $m ) => $m[1] ?? ( '&gt;' === strtolower( $m[0] ) ? '>' : '<' ),
				$html,
				flags: PREG_UNMATCHED_AS_NULL
			);
			foreach ( preg_split( '/\n\s*\n/', $html ) as $paragraph ) {
				$paragraph = trim( $paragraph );
				if ( '' !== $paragraph ) {
					$paragraphs[] = $paragraph;
				}
			}
		}
		return implode( "\n\n", $paragraphs );
	}

	/**
	 * The edit form's plain text as the intro: a paragraph block for each paragraph, so the
	 * block editor opens it as paragraphs. A single newline is a line break. Only the tags
	 * intro_text() shows are kept, so whatever is saved can be edited as text again.
	 */
	public static function intro_content( string $text ): string {
		$allowed = array_intersect_key( wp_kses_allowed_html( 'post' ), array_flip( self::INTRO_TAGS ) );
		$blocks  = [];
		foreach ( preg_split( '/\n\s*\n/', str_replace( "\r\n", "\n", $text ) ) as $paragraph ) {
			// A < that can't start a tag is text, or kses reads "a < b >" as <b>.
			$paragraph = preg_replace( '#<(?!' . self::TAG_START . ')#i', '&lt;', $paragraph );
			// Balanced, so a tag left open doesn't run past its paragraph block.
			$paragraph = trim( force_balance_tags( wp_kses( $paragraph, $allowed ) ) );
			if ( '' !== $paragraph ) {
				$blocks[] = "<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', $paragraph ) . "</p>\n<!-- /wp:paragraph -->";
			}
		}
		return implode( "\n\n", $blocks );
	}

	public function handle_save_trip(): void {
		check_admin_referer( 'travel_steps_save_trip' );
		$input   = wp_unslash( $_POST );
		$trip_id = absint( $input['trip_id'] ?? 0 );

		if ( $trip_id ) {
			$this->trip_from_request();
			if ( ! self::can_sync_trip( $trip_id ) && array_intersect_key( $input, array_flip( [ 'start', 'end', 'album', 'photos', 'taken', 'sync' ] ) ) ) {
				wp_die( esc_html__( 'Only the trip’s author can change its dates or photos, or sync it.', 'travel-steps-storyteller' ), 403 );
			}
		} elseif ( ! current_user_can( 'edit_posts' ) || ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create trips.', 'travel-steps-storyteller' ), 403 );
		}

		$status = in_array( $input['status'] ?? '', [ 'draft', 'publish', 'private' ], true ) ? $input['status'] : 'draft';
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'draft';
		}
		$current = $trip_id ? get_post( $trip_id ) : null;
		$postarr = [
			'ID'          => $trip_id,
			'post_type'   => self::TRIP,
			'post_title'  => sanitize_text_field( $input['title'] ?? '' ) ?: __( 'Untitled trip', 'travel-steps-storyteller' ),
			'post_status' => $status,
		];
		// An intro with more than paragraphs has no field on the form; only the block editor changes it.
		if ( isset( $input['intro'] ) && ( ! $current || null !== self::intro_text( $current->post_content ) ) ) {
			$postarr['post_content'] = self::intro_content( (string) $input['intro'] );
		}
		// Trimmed, so a stray space doesn't lock visitors out, but otherwise kept as typed, so it
		// matches what visitors type into the password form. The column holds 255 characters.
		if ( isset( $input['password'] ) ) {
			$postarr['post_password'] = mb_substr( trim( (string) $input['password'] ), 0, 255 );
		}
		// WordPress leaves drafts without a slug; give the trip one so its URL is stable.
		if ( ! $current || ! $current->post_name ) {
			$postarr['post_name'] = wp_unique_post_slug( sanitize_title( $postarr['post_title'] ), $trip_id, 'publish', self::TRIP, 0 );
		}
		// Slashed as wp_insert_post() expects, so a backslash typed in the title, intro or password is kept.
		$postarr = wp_slash( $postarr );
		$result  = $trip_id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$trip_id = (int) $result;
		// Checkboxes: none ticked sends nothing.
		update_post_meta( $trip_id, self::META_VIEWERS, self::user_ids( $input['viewers'] ?? [] ) );

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
			// Times given to uploads with none taken, in the Photos panel.
			if ( is_array( $input['taken'] ?? null ) ) {
				Uploads::save_times( $trip_id, $input['taken'] );
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
			wp_die( esc_html__( 'Only the trip’s author can sync it.', 'travel-steps-storyteller' ), 403 );
		}
		$this->run_sync_and_redirect( $trip );
	}

	private function run_sync_and_redirect( \WP_Post $trip ): void {
		$result = ( $this->sync ?? new TripSync() )->sync( $trip->ID );
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
				'at_home'   => (int) $result['at_home'],
				'unlocated' => (int) $result['unlocated'],
			];
		}
		// For any trip: photos that needed a place name went without.
		if ( $result['no_places'] ) {
			$args['no_places'] = 1;
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
				'post_title'    => __( 'New step', 'travel-steps-storyteller' ),
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
