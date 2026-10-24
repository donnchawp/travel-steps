<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscriptions to a trip's daily roundup (see Roundup).
 *
 * Each is a hidden post whose post_parent is the trip, as a step's is. A visitor signs up
 * with an email address, and is pending until they open the link emailed to them. A
 * logged-in visitor subscribes their account at once: the roundup goes to its current
 * address. A visitor is recognised afterwards by a cookie set when they confirm, a
 * logged-in one by their account.
 */
class Subscriptions {
	const TYPE = 'ts_subscription';

	const META_EMAIL  = '_ts_email';  // Lower-cased address of a visitor's subscription.
	const META_USER   = '_ts_user';   // User id of a logged-in visitor's subscription.
	const META_STATUS = '_ts_status'; // PENDING or CONFIRMED.
	const META_TOKEN  = '_ts_token';  // Random; the confirm and unsubscribe links carry it.
	const META_MAILED = '_ts_mailed'; // When the last confirmation email went, unix time.
	const META_SHARE  = '_ts_share';  // The trip's share code when a logged-in visitor subscribed with its password.

	const PENDING   = 'pending';
	const CONFIRMED = 'confirmed';

	// A pending subscription whose confirmation email went longer ago than this is dropped.
	const EXPIRE_S = 7 * DAY_IN_SECONDS;
	// At most one confirmation email per address per trip in this long.
	const RESEND_S = DAY_IN_SECONDS;

	// The query argument the trip page's notices come in.
	const NOTICE_ARG = 'subscription';

	public static function register(): void {
		// Nobody, administrators included, reaches them through wp-admin, REST or XML-RPC:
		// they hold visitors' addresses. The trip's edit page lists them to its editors.
		$nobody = array_fill_keys( [ 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'create_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'read' ], 'do_not_allow' );
		register_post_type(
			self::TYPE,
			[
				'label'               => __( 'Trip subscriptions', 'travel-steps' ),
				'public'              => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'supports'            => [],
				'capabilities'        => $nobody,
				'map_meta_cap'        => false,
			]
		);
	}

	/**
	 * A trip's subscriptions, oldest first.
	 *
	 * @return array<int, array{id: int, trip: int, status: string, email: string, user: int, token: string, mailed: int, share: string}>
	 */
	public static function for_trip( int $trip_id ): array {
		$posts = get_posts(
			[
				'post_type'        => self::TYPE,
				'post_parent'      => $trip_id,
				'post_status'      => 'private',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);
		return array_map( [ self::class, 'row' ], $posts );
	}

	/**
	 * A subscription by its id, or null if there's none.
	 */
	public static function get( int $id ): ?array {
		$post = $id ? get_post( $id ) : null;
		return $post && self::TYPE === $post->post_type ? self::row( $post ) : null;
	}

	private static function row( \WP_Post $post ): array {
		$meta = fn( $key ) => (string) get_post_meta( $post->ID, $key, true );
		return [
			'id'     => $post->ID,
			'trip'   => (int) $post->post_parent,
			'status' => self::CONFIRMED === $meta( self::META_STATUS ) ? self::CONFIRMED : self::PENDING,
			'email'  => $meta( self::META_EMAIL ),
			'user'   => (int) $meta( self::META_USER ),
			'token'  => $meta( self::META_TOKEN ),
			'mailed' => (int) $meta( self::META_MAILED ),
			'share'  => $meta( self::META_SHARE ),
		];
	}

	/**
	 * Adds a subscription to a trip.
	 *
	 * @param array<string, string|int> $meta
	 */
	private static function add( int $trip_id, array $meta ): int {
		$id = wp_insert_post(
			[
				'post_type'   => self::TYPE,
				'post_parent' => $trip_id,
				'post_status' => 'private',
				'post_title'  => '',
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		foreach ( $meta + [ self::META_TOKEN => wp_generate_password( 32, false ) ] as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	public static function delete( int $id ): void {
		if ( self::get( $id ) ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Every subscription to a trip, when it's deleted.
	 */
	public static function delete_for_trip( int $trip_id ): void {
		if ( Trips::TRIP !== get_post_type( $trip_id ) ) {
			return;
		}
		foreach ( self::for_trip( $trip_id ) as $sub ) {
			wp_delete_post( $sub['id'], true );
		}
	}

	/**
	 * The link that confirms ($do 'confirm') or ends ('unsubscribe') a subscription.
	 */
	public static function link( array $sub, string $do ): string {
		return add_query_arg(
			[
				'sub' => $sub['id'],
				'key' => $sub['token'],
				'do'  => $do,
			],
			App::url( 'subscription' )
		);
	}

	/**
	 * The name of the cookie that recognises a visitor's confirmed subscription to a trip.
	 */
	public static function cookie( int $trip_id ): string {
		return 'ts_subscribed_' . $trip_id . '_' . COOKIEHASH;
	}

	private static function set_cookie( array $sub ): void {
		self::send_cookie( self::cookie( $sub['trip'] ), $sub['id'] . '.' . $sub['token'], time() + YEAR_IN_SECONDS );
	}

	private static function clear_cookie( int $trip_id ): void {
		self::send_cookie( self::cookie( $trip_id ), '', time() - YEAR_IN_SECONDS );
	}

	private static function send_cookie( string $name, string $value, int $expires ): void {
		if ( ! headers_sent() ) {
			setcookie(
				$name,
				$value,
				[
					'expires'  => $expires,
					'path'     => COOKIEPATH,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		if ( '' === $value ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = $value;
		}
	}

	/**
	 * The current visitor's subscription to a trip: by their account if they're logged in,
	 * else a confirmed one the cookie names. Null if they have none.
	 */
	public static function current( int $trip_id ): ?array {
		$user = get_current_user_id();
		if ( $user ) {
			return current( wp_list_filter( self::for_trip( $trip_id ), [ 'user' => $user ] ) ) ?: null;
		}
		$cookie = isset( $_COOKIE[ self::cookie( $trip_id ) ] ) && is_string( $_COOKIE[ self::cookie( $trip_id ) ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::cookie( $trip_id ) ] ) ) : '';
		$parts  = explode( '.', $cookie, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$sub = self::get( (int) $parts[0] );
		return $sub && $trip_id === $sub['trip'] && self::CONFIRMED === $sub['status'] && hash_equals( $sub['token'], $parts[1] ) ? $sub : null;
	}

	/**
	 * Whether the trip page offers the current visitor a way to subscribe: the trip is
	 * published, and they can see it (the page checks that before it gets here).
	 */
	public static function offered( \WP_Post $trip ): bool {
		return 'publish' === $trip->post_status;
	}

	/**
	 * The form the trip page posts to subscribe or unsubscribe, which goes back to the
	 * trip with a notice. Only reached by visitors who can see the trip. Returns if
	 * there's nothing to handle.
	 */
	public static function handle_post( \WP_Post $trip ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A visitor's form has no nonce: a page cache would keep it. A logged-in visitor's is checked below.
		$action = isset( $_POST['ts_subscription'] ) && is_string( $_POST['ts_subscription'] ) ? sanitize_key( $_POST['ts_subscription'] ) : '';
		if ( ! in_array( $action, [ 'subscribe', 'unsubscribe' ], true ) || ! self::offered( $trip ) ) {
			return;
		}
		$notice = 'check';
		if ( is_user_logged_in() ) {
			check_admin_referer( 'travel_steps_subscription_' . $trip->ID );
			$notice = 'subscribe' === $action ? self::subscribe_user( $trip ) : self::unsubscribe_current( $trip );
		} elseif ( 'unsubscribe' === $action ) {
			$notice = self::unsubscribe_current( $trip );
		} else {
			$email = isset( $_POST['ts_email'] ) && is_string( $_POST['ts_email'] ) ? sanitize_email( wp_unslash( $_POST['ts_email'] ) ) : '';
			// The honeypot: people don't see it, so whatever fills it in is a bot.
			$bot = ! empty( $_POST['ts_website'] );
			if ( ! $bot && is_email( $email ) ) {
				self::signup( $trip, $email );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $notice, Trips::url( $trip ) ) );
		exit;
	}

	/**
	 * A visitor's signup: a pending subscription and a confirmation email, unless the
	 * address is subscribed already or was sent one within the day. The visitor is told
	 * the same either way, so the form can't show who follows a trip.
	 */
	public static function signup( \WP_Post $trip, string $email ): void {
		$email    = strtolower( $email );
		$existing = current( wp_list_filter( self::for_trip( $trip->ID ), [ 'email' => $email ] ) );
		if ( $existing && ( self::CONFIRMED === $existing['status'] || $existing['mailed'] > time() - self::RESEND_S ) ) {
			return;
		}
		if ( $existing ) {
			update_post_meta( $existing['id'], self::META_MAILED, time() );
			$sub = self::get( $existing['id'] );
		} else {
			$sub = self::get(
				self::add(
					$trip->ID,
					[
						self::META_EMAIL  => $email,
						self::META_STATUS => self::PENDING,
						self::META_MAILED => time(),
					]
				)
			);
		}
		if ( $sub ) {
			self::send_confirmation( $trip, $sub );
		}
	}

	private static function send_confirmation( \WP_Post $trip, array $sub ): void {
		$site  = self::plain( get_bloginfo( 'name' ) );
		$title = self::plain( get_the_title( $trip ) );
		/* translators: %s: trip title */
		$subject = sprintf( __( 'Confirm your subscription to %s', 'travel-steps' ), $title );
		$body    = sprintf(
			/* translators: 1: trip title, 2: site name */
			__( 'Someone, probably you, asked for an email from %2$s when new days or photos are added to “%1$s”. At most one a day.', 'travel-steps' ),
			$title,
			$site
		) . "\n\n" . __( 'To confirm, open this link:', 'travel-steps' ) . "\n" . self::link( $sub, 'confirm' ) . "\n\n"
			. __( 'If it wasn’t you, ignore this email and you won’t get any more. The link works for 7 days.', 'travel-steps' ) . "\n";
		wp_mail( $sub['email'], $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	/**
	 * Text for a plain-text email: no tags, entities decoded, on one line.
	 */
	public static function plain( string $text ): string {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Subscribes the logged-in visitor's account, confirmed at once.
	 */
	private static function subscribe_user( \WP_Post $trip ): string {
		$user  = get_current_user_id();
		$share = Trips::skips_password( $trip ) ? '' : Trips::share_code( $trip );
		$sub   = self::current( $trip->ID );
		if ( $sub ) {
			// Seeing the trip again with a new password brings back a subscription it had stopped.
			update_post_meta( $sub['id'], self::META_SHARE, $share );
		} else {
			self::add(
				$trip->ID,
				[
					self::META_USER   => $user,
					self::META_STATUS => self::CONFIRMED,
					self::META_SHARE  => $share,
				]
			);
			Roundup::baseline( $trip->ID );
		}
		return 'subscribed';
	}

	/**
	 * Ends the current visitor's subscription: their account's, or the one their cookie names.
	 */
	private static function unsubscribe_current( \WP_Post $trip ): string {
		$sub = self::current( $trip->ID );
		if ( $sub ) {
			self::delete( $sub['id'] );
		}
		if ( ! is_user_logged_in() ) {
			self::clear_cookie( $trip->ID );
		}
		return 'unsubscribed';
	}

	/**
	 * What a confirm or unsubscribe link does: 'confirmed', 'unsubscribed', 'expired' (a
	 * pending subscription confirmed too late, now dropped) or 'invalid'. Also answers a
	 * mail client's one-click unsubscribe, a POST to the same link.
	 *
	 * @param array<string, mixed> $query The link's query arguments.
	 * @return array{state: string, trip: ?\WP_Post}
	 */
	public static function handle_link( array $query ): array {
		$do  = is_string( $query['do'] ?? null ) ? $query['do'] : '';
		$key = is_string( $query['key'] ?? null ) ? $query['key'] : '';
		$sub = is_scalar( $query['sub'] ?? null ) ? self::get( absint( $query['sub'] ) ) : null;
		if ( ! $sub || '' === $key || ! hash_equals( $sub['token'], $key ) || ! in_array( $do, [ 'confirm', 'unsubscribe' ], true ) ) {
			return [
				'state' => 'invalid',
				'trip'  => null,
			];
		}
		$trip = get_post( $sub['trip'] );
		$trip = $trip && Trips::TRIP === $trip->post_type ? $trip : null;
		if ( 'unsubscribe' === $do ) {
			self::delete( $sub['id'] );
			if ( $sub['email'] ) {
				self::clear_cookie( $sub['trip'] );
			}
			$state = 'unsubscribed';
		} elseif ( self::PENDING === $sub['status'] && $sub['mailed'] < time() - self::EXPIRE_S ) {
			self::delete( $sub['id'] );
			$state = 'expired';
		} else {
			if ( self::PENDING === $sub['status'] ) {
				update_post_meta( $sub['id'], self::META_STATUS, self::CONFIRMED );
				Roundup::baseline( $sub['trip'] );
			}
			if ( $sub['email'] ) {
				self::set_cookie( $sub );
			}
			$state = 'confirmed';
		}
		return [
			'state' => $state,
			'trip'  => $trip,
		];
	}

	/**
	 * Drops pending subscriptions whose confirmation email went out too long ago.
	 */
	public static function expire_pending(): void {
		$old = get_posts(
			[
				'post_type'        => self::TYPE,
				'post_status'      => 'private',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Once a day, on a small table.
				'meta_query'       => [
					[
						'key'   => self::META_STATUS,
						'value' => self::PENDING,
					],
					[
						'key'     => self::META_MAILED,
						'value'   => time() - self::EXPIRE_S,
						'compare' => '<',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
		foreach ( $old as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
