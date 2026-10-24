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
	// The logged-in form's nonce field: not _wpnonce, which the trip page's photo URLs must never carry.
	const NONCE_FIELD = 'ts_subscription_nonce';

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
		$posts = self::query(
			[
				'post_parent' => $trip_id,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			]
		);
		return array_map( [ self::class, 'row' ], $posts );
	}

	/**
	 * A trip's oldest subscription with a meta value: an account's or an address's.
	 *
	 * @param string|int $value
	 */
	private static function find( int $trip_id, string $key, $value ): ?array {
		$posts = self::query(
			[
				'post_parent' => $trip_id,
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Within one trip's subscriptions.
				'meta_value'  => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return $posts ? self::row( $posts[0] ) : null;
	}

	/**
	 * Subscription posts: every one, unless $args narrows them.
	 *
	 * @param array<string, mixed> $args get_posts() arguments.
	 * @return \WP_Post[]|int[]
	 */
	private static function query( array $args ): array {
		return get_posts(
			$args + [
				'post_type'        => self::TYPE,
				'post_status'      => 'private',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);
	}

	/**
	 * Every subscription on the site, when the plugin is deleted.
	 */
	public static function delete_all(): void {
		foreach ( self::query( [ 'fields' => 'ids' ] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
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
	 * Every subscription of a user, when they're deleted.
	 */
	public static function delete_for_user( int $user_id ): void {
		$ids = self::query(
			[
				'fields'     => 'ids',
				'meta_key'   => self::META_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only when a user is deleted.
				'meta_value' => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * The address a subscription's emails go to: its own, or its account's. Empty if the
	 * account is gone.
	 */
	public static function address( array $sub ): string {
		if ( ! $sub['user'] ) {
			return $sub['email'];
		}
		$user = get_userdata( $sub['user'] );
		return $user ? strtolower( $user->user_email ) : '';
	}

	/**
	 * Ends a subscription, and any other to the same trip with the same address: they
	 * share one roundup email, so unsubscribing from it has to stop it.
	 */
	private static function end( array $sub ): void {
		$address = self::address( $sub );
		foreach ( self::for_trip( $sub['trip'] ) as $other ) {
			if ( $other['id'] === $sub['id'] || ( '' !== $address && self::address( $other ) === $address ) ) {
				wp_delete_post( $other['id'], true );
			}
		}
	}

	/**
	 * Drops a trip's email-only subscriptions when it gets a new password, wherever it's
	 * changed (the plugin's form, the block editor, wp-admin, REST): changing it is how
	 * access is revoked, so it stops the emails too. Logged-in subscribers are kept, and
	 * skipped while they can't see the trip (see Roundup::reaches()). Taking the password
	 * off revokes nobody, so it keeps them all.
	 */
	public static function password_changed( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( Trips::TRIP !== $after->post_type || $after->post_password === $before->post_password || ! Trips::is_protected( $after ) ) {
			return;
		}
		foreach ( self::for_trip( $post_id ) as $sub ) {
			if ( ! $sub['user'] ) {
				wp_delete_post( $sub['id'], true );
			}
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
			return self::find( $trip_id, self::META_USER, $user );
		}
		$value  = $_COOKIE[ self::cookie( $trip_id ) ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised below.
		$cookie = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
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
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A public trip's form has no nonce for visitors: a page cache would keep it. needs_nonce() says when it's checked.
		$action = isset( $_POST['ts_subscription'] ) && is_string( $_POST['ts_subscription'] ) ? sanitize_key( $_POST['ts_subscription'] ) : '';
		if ( ! in_array( $action, [ 'subscribe', 'unsubscribe' ], true ) || ! self::offered( $trip ) || self::cross_site() ) {
			return;
		}
		if ( self::needs_nonce( $trip ) ) {
			check_admin_referer( 'travel_steps_subscription_' . $trip->ID, self::NONCE_FIELD );
		}
		$notice = 'check';
		if ( is_user_logged_in() ) {
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
	 * Whether the subscribe form carries a nonce that's checked: for a logged-in visitor,
	 * and on a protected trip, whose page no cache keeps. There the visitor's way in is a
	 * password cookie, which browsers can send with another site's form (#123).
	 */
	public static function needs_nonce( \WP_Post $trip ): bool {
		return is_user_logged_in() || Trips::is_protected( $trip );
	}

	/**
	 * Whether the request came from another site's page, as the browser tells it: by
	 * Sec-Fetch-Site, else by Origin. A request with neither is let through.
	 */
	private static function cross_site(): bool {
		$fetch = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) && is_string( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( '' !== $fetch ) {
			return ! in_array( $fetch, [ 'same-origin', 'none' ], true );
		}
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) && is_string( $_SERVER['HTTP_ORIGIN'] ) ? wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
		if ( '' === $origin ) {
			return false;
		}
		$from = wp_parse_url( $origin );
		$home = wp_parse_url( home_url() );
		$part = fn( $url, $key, $default = '' ) => is_array( $url ) && isset( $url[ $key ] ) ? strtolower( (string) $url[ $key ] ) : $default;
		$port = fn( $url ) => $part( $url, 'port', 'https' === $part( $url, 'scheme' ) ? '443' : '80' );
		return [ $part( $from, 'scheme' ), $part( $from, 'host' ), $port( $from ) ] !== [ $part( $home, 'scheme' ), $part( $home, 'host' ), $port( $home ) ];
	}

	/**
	 * A visitor's signup: a pending subscription and a confirmation email, unless the
	 * address is subscribed already or was sent one within the day. The visitor is told
	 * the same either way, so the form can't show who follows a trip.
	 */
	public static function signup( \WP_Post $trip, string $email ): void {
		// Two signups for the address at once would both find none and both send an email.
		// The one that finds the lock taken sends nothing.
		Lock::run( self::signup_lock( $trip->ID, $email ), fn() => self::signup_now( $trip, strtolower( trim( $email ) ) ) );
	}

	/**
	 * The lock a signup for the address holds. Named by a keyed hash, so the address
	 * isn't in the options table.
	 */
	public static function signup_lock( int $trip_id, string $email ): string {
		return 'travel_steps_signup_lock_' . $trip_id . '_' . wp_hash( strtolower( trim( $email ) ) );
	}

	private static function signup_now( \WP_Post $trip, string $email ): void {
		$existing = self::find( $trip->ID, self::META_EMAIL, $email );
		if ( $existing && ( self::CONFIRMED === $existing['status'] || $existing['mailed'] > time() - self::RESEND_S ) ) {
			return;
		}
		if ( $existing ) {
			update_post_meta( $existing['id'], self::META_MAILED, time() );
			$sub = $existing;
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
	 * Ends the current visitor's subscription: their account's, or the one their cookie names
	 * (and any other with its address, see end()).
	 */
	private static function unsubscribe_current( \WP_Post $trip ): string {
		$sub = self::current( $trip->ID );
		if ( $sub ) {
			self::end( $sub );
		}
		if ( ! is_user_logged_in() ) {
			self::clear_cookie( $trip->ID );
		}
		return 'unsubscribed';
	}

	/**
	 * A confirm or unsubscribe link from an email. Opening it ($act false) changes nothing,
	 * since mail scanners open every link: it answers 'confirm' or 'unsubscribe', the button
	 * to show, or 'expired' or 'invalid'. Pressing the button, or a mail client's one-click
	 * unsubscribe, POSTs to the same link ($act true), which does it: 'confirmed',
	 * 'unsubscribed', 'expired' (a pending subscription confirmed too late, now dropped) or
	 * 'invalid'.
	 *
	 * @param array<string, mixed> $query The link's query arguments.
	 * @param bool                 $act   Whether to do what the link says, or only say what it would do.
	 * @return array{state: string, trip: ?\WP_Post, sub: int}
	 */
	public static function handle_link( array $query, bool $act ): array {
		$do  = is_string( $query['do'] ?? null ) ? $query['do'] : '';
		$key = is_string( $query['key'] ?? null ) ? $query['key'] : '';
		$sub = is_scalar( $query['sub'] ?? null ) ? self::get( absint( $query['sub'] ) ) : null;
		if ( ! $sub || '' === $key || ! hash_equals( $sub['token'], $key ) || ! in_array( $do, [ 'confirm', 'unsubscribe' ], true ) ) {
			return [
				'state' => 'invalid',
				'trip'  => null,
				'sub'   => 0,
			];
		}
		$trip    = get_post( $sub['trip'] );
		$trip    = $trip && Trips::TRIP === $trip->post_type ? $trip : null;
		$expired = 'confirm' === $do && self::PENDING === $sub['status'] && $sub['mailed'] < time() - self::EXPIRE_S;
		if ( ! $act ) {
			$state = $expired ? 'expired' : $do;
		} elseif ( 'unsubscribe' === $do ) {
			self::end( $sub );
			if ( $sub['email'] ) {
				self::clear_cookie( $sub['trip'] );
			}
			$state = 'unsubscribed';
		} elseif ( $expired ) {
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
			'sub'   => $sub['id'],
		];
	}

	/**
	 * Drops pending subscriptions whose confirmation email went out too long ago.
	 */
	public static function expire_pending(): void {
		$old = self::query(
			[
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Once a day, on a small table.
				'meta_query' => [
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

	/**
	 * The edit page's Remove button: someone who can edit the trip takes a subscriber off it.
	 */
	public static function handle_remove(): void {
		check_admin_referer( 'travel_steps_remove_subscriber' );
		$sub  = self::get( absint( $_POST['subscription'] ?? 0 ) );
		$trip = $sub ? get_post( $sub['trip'] ) : null;
		if ( ! $sub || ! $trip || ! Trips::can_edit_trip( $trip->ID ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this trip.', 'travel-steps' ), 403 );
		}
		self::delete( $sub['id'] );
		wp_safe_redirect( add_query_arg( 'removed', '1', Trips::url( $trip, 'edit' ) ) . '#ts-subscribers' );
		exit;
	}

	/**
	 * The subscriptions of an email address: those made with it, and those of the account
	 * that has it.
	 *
	 * @return array[]
	 */
	public static function for_address( string $email ): array {
		$email = strtolower( trim( $email ) );
		$user  = '' === $email ? false : get_user_by( 'email', $email );
		$subs  = [];
		foreach ( array_filter( [ self::META_EMAIL => $email, self::META_USER => $user ? $user->ID : 0 ] ) as $key => $value ) {
			$posts = self::query(
				[
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only for personal data requests.
					'meta_value' => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
			foreach ( $posts as $post ) {
				$subs[ $post->ID ] ??= self::row( $post );
			}
		}
		return array_values( $subs );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['travel-steps-subscriptions'] = [
			'exporter_friendly_name' => __( 'Travel Steps trip subscriptions', 'travel-steps' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['travel-steps-subscriptions'] = [
			'eraser_friendly_name' => __( 'Travel Steps trip subscriptions', 'travel-steps' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * The personal data exporter: each subscription of the address, with its trip.
	 */
	public static function export( string $email, int $page = 1 ): array {
		$data = [];
		foreach ( self::for_address( $email ) as $sub ) {
			$data[] = [
				'group_id'    => 'travel-steps-subscriptions',
				'group_label' => __( 'Trip subscriptions', 'travel-steps' ),
				'item_id'     => 'travel-steps-subscription-' . $sub['id'],
				'data'        => [
					[
						'name'  => __( 'Trip', 'travel-steps' ),
						'value' => self::plain( get_the_title( $sub['trip'] ) ),
					],
					[
						'name'  => __( 'Email address', 'travel-steps' ),
						'value' => $sub['email'] ? $sub['email'] : __( 'Your account’s', 'travel-steps' ),
					],
					[
						'name'  => __( 'Status', 'travel-steps' ),
						'value' => self::CONFIRMED === $sub['status'] ? __( 'Confirmed', 'travel-steps' ) : __( 'Pending', 'travel-steps' ),
					],
					[
						'name'  => __( 'Subscribed', 'travel-steps' ),
						'value' => (string) get_post_field( 'post_date', $sub['id'] ),
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * The personal data eraser: deletes each subscription of the address.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$subs = self::for_address( $email );
		foreach ( $subs as $sub ) {
			wp_delete_post( $sub['id'], true );
		}
		return [
			'items_removed'  => count( $subs ),
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
