<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The daily email to a trip's subscribers listing what's new on it.
 *
 * Once a day, at the hour in the site settings, each published trip is compared with what
 * was recorded at its last roundup: its published steps, when each was last changed, and
 * the photos visitors could see on each. A roundup goes out only for a newly published
 * step or new visible photos; text edits are listed with those, never on their own.
 */
class Roundup {
	const HOOK = 'travel_steps_roundup';
	const LOCK = 'travel_steps_roundup_lock';

	// Trip meta.
	const META_STATE = '_ts_roundup_state'; // array{steps: array<int, string>, photos: string[]}: as at the last roundup.
	const META_LAST  = '_ts_roundup_last';  // array{time: int, days: int, photos: int, updated: int, recipients: int}: the last roundup sent.

	/**
	 * Run at the next hour the site settings name, unless that's scheduled already. A
	 * single event each day, not a daily one, so the hour holds across daylight saving.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( self::next_time(), self::HOOK );
		}
	}

	/**
	 * Schedule again from scratch, after the hour changes.
	 */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		self::schedule();
	}

	/**
	 * The next time the clock in the site's timezone shows the roundup hour, after $now.
	 */
	public static function next_time( ?int $now = null ): int {
		$now  = $now ?? time();
		$hour = min( 23, max( 0, (int) Settings::get( 'roundup_hour' ) ) );
		$day  = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		$at   = $day->setTime( $hour, 0 );
		if ( $at->getTimestamp() <= $now ) {
			$at = $day->modify( '+1 day' )->setTime( $hour, 0 );
		}
		return $at->getTimestamp();
	}

	/**
	 * The cron job: tomorrow's run is scheduled, expired signups dropped, and each trip's
	 * roundup sent.
	 */
	public static function run(): void {
		self::schedule();
		Subscriptions::expire_pending();
		Lock::run( self::LOCK, [ self::class, 'send_all' ] );
	}

	/**
	 * Every published trip that has had subscribers: its roundup, if there's anything new.
	 * Trips that aren't published send nothing and keep what they last recorded, so what
	 * they gained meanwhile goes out when they're published again.
	 */
	public static function send_all(): void {
		$trips = get_posts(
			[
				'post_type'        => Trips::TRIP,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'meta_key'         => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once a day.
			]
		);
		foreach ( $trips as $trip_id ) {
			$trip = get_post( (int) $trip_id );
			if ( $trip ) {
				self::send( $trip );
			}
		}
	}

	/**
	 * Records a trip's current state as sent, if nothing has been recorded for it yet: a
	 * first subscriber hears only of what comes after.
	 */
	public static function baseline( int $trip_id ): void {
		if ( '' === get_post_meta( $trip_id, self::META_STATE, true ) ) {
			update_post_meta( $trip_id, self::META_STATE, self::state( $trip_id ) );
		}
	}

	/**
	 * What visitors see of a trip now: when each published step was last changed (in
	 * GMT), and the photos they see on each.
	 *
	 * @return array{steps: array<int, string>, photos: array<int, string[]>}
	 */
	public static function state( int $trip_id ): array {
		$steps = Trips::steps( $trip_id );
		return [
			'steps'  => array_column( array_map( fn( $step ) => [ $step->ID, $step->post_modified_gmt ], $steps ), 1, 0 ),
			'photos' => Trips::visible_photos_by_step( $trip_id, $steps ),
		];
	}

	/**
	 * The step ids a roundup lists, from the state at the last one and now: newly
	 * published steps, steps with photos visitors hadn't seen anywhere on the trip, and
	 * other steps changed since. Each step is listed once, under the first that applies.
	 *
	 * @return array{days: int[], photos: int[], updated: int[]}
	 */
	public static function changes( array $before, array $now ): array {
		$seen    = array_fill_keys( array_merge( [], ...array_values( (array) ( $before['photos'] ?? [] ) ) ), true );
		$changes = [
			'days'    => [],
			'photos'  => [],
			'updated' => [],
		];
		foreach ( $now['steps'] as $step_id => $modified ) {
			if ( ! isset( $before['steps'][ $step_id ] ) ) {
				$changes['days'][] = $step_id;
			} elseif ( array_diff_key( array_fill_keys( $now['photos'][ $step_id ] ?? [], true ), $seen ) ) {
				$changes['photos'][] = $step_id;
			} elseif ( $before['steps'][ $step_id ] !== $modified ) {
				$changes['updated'][] = $step_id;
			}
		}
		return $changes;
	}

	/**
	 * A trip's roundup, if it has new days or photos and anyone to send it to. What was
	 * sent is recorded before it goes, so a run that dies part-way doesn't send it twice.
	 * Nothing is recorded when nothing is sent.
	 */
	public static function send( \WP_Post $trip ): void {
		if ( 'publish' !== $trip->post_status ) {
			return;
		}
		$before  = get_post_meta( $trip->ID, self::META_STATE, true );
		$now     = self::state( $trip->ID );
		$changes = self::changes( is_array( $before ) ? $before : [], $now );
		// Text edits wait, unrecorded, for a roundup that lists them.
		if ( ! $changes['days'] && ! $changes['photos'] ) {
			return;
		}
		update_post_meta( $trip->ID, self::META_STATE, $now );
		$recipients = self::recipients( $trip );
		update_post_meta(
			$trip->ID,
			self::META_LAST,
			[
				'time'       => time(),
				'days'       => count( $changes['days'] ),
				'photos'     => count( $changes['photos'] ),
				'updated'    => count( $changes['updated'] ),
				'recipients' => count( $recipients ),
			]
		);
		if ( ! $recipients ) {
			return;
		}
		$subject = self::subject( $trip, $changes );
		$body    = self::body( $trip, $changes );
		$footer  = "\n-- \n" . sprintf(
			/* translators: %s: site name */
			__( 'You subscribed to this trip on %s.', 'travel-steps' ),
			Subscriptions::plain( get_bloginfo( 'name' ) )
		) . "\n" . __( 'Unsubscribe:', 'travel-steps' ) . ' ';
		foreach ( $recipients as $address => $sub ) {
			$unsubscribe = Subscriptions::link( $sub, 'unsubscribe' );
			wp_mail(
				$address,
				$subject,
				$body . $footer . $unsubscribe . "\n",
				[
					'Content-Type: text/plain; charset=UTF-8',
					'List-Unsubscribe: <' . $unsubscribe . '>',
					'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
				]
			);
		}
	}

	/**
	 * Who gets a trip's roundup, by address: each confirmed subscription that reaches()
	 * it, once per address.
	 *
	 * @return array<string, array> The subscription each address's unsubscribe link ends.
	 */
	private static function recipients( \WP_Post $trip ): array {
		$recipients = [];
		foreach ( Subscriptions::for_trip( $trip->ID ) as $sub ) {
			if ( ! self::reaches( $trip, $sub ) ) {
				continue;
			}
			$address = strtolower( $sub['user'] ? (string) get_userdata( $sub['user'] )->user_email : $sub['email'] );
			$recipients[ $address ] ??= $sub;
		}
		return $recipients;
	}

	private static function subject( \WP_Post $trip, array $changes ): string {
		$days  = count( $changes['days'] );
		$parts = [];
		if ( $days ) {
			/* translators: %s: number of days */
			$parts[] = sprintf( _n( '%s new day', '%s new days', $days, 'travel-steps' ), number_format_i18n( $days ) );
		}
		if ( $changes['photos'] ) {
			$parts[] = __( 'new photos', 'travel-steps' );
		}
		/* translators: 1: trip title, 2: what's new, such as "2 new days and new photos" */
		return sprintf( __( '%1$s: %2$s', 'travel-steps' ), Subscriptions::plain( get_the_title( $trip ) ), implode( __( ' and ', 'travel-steps' ), $parts ) );
	}

	private static function body( \WP_Post $trip, array $changes ): string {
		/* translators: %s: trip title */
		$body     = sprintf( __( 'What’s new on “%s”:', 'travel-steps' ), Subscriptions::plain( get_the_title( $trip ) ) ) . "\n";
		$sections = [
			'days'    => __( 'New days:', 'travel-steps' ),
			'photos'  => __( 'New photos on:', 'travel-steps' ),
			'updated' => __( 'Also updated:', 'travel-steps' ),
		];
		foreach ( $sections as $key => $heading ) {
			if ( ! $changes[ $key ] ) {
				continue;
			}
			$body .= "\n" . $heading . "\n";
			foreach ( $changes[ $key ] as $step_id ) {
				$step = get_post( $step_id );
				if ( ! $step ) {
					continue;
				}
				$day   = Trips::day_of_trip( $trip->ID, $step );
				$date  = mysql2date( get_option( 'date_format' ), $step->post_date );
				$when  = $day > 0 ? sprintf( /* translators: 1: day number of the trip, 2: date */ __( 'Day %1$d, %2$s', 'travel-steps' ), $day, $date ) : $date;
				$body .= '- ' . $when . ': ' . Subscriptions::plain( get_the_title( $step ) ) . "\n  " . self::trip_link( $trip, $step_id ) . "\n";
			}
		}
		return $body;
	}

	/**
	 * Whether a subscription gets the trip's roundup: it's confirmed, and a logged-in
	 * visitor's account still sees the trip, by its login or with the password it had
	 * when they subscribed.
	 */
	public static function reaches( \WP_Post $trip, array $sub ): bool {
		if ( Subscriptions::CONFIRMED !== $sub['status'] || 'publish' !== $trip->post_status ) {
			return false;
		}
		if ( ! $sub['user'] ) {
			return '' !== $sub['email'];
		}
		$user = get_userdata( $sub['user'] );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		if ( ! Trips::is_protected( $trip ) ) {
			return true;
		}
		if ( '' !== $sub['share'] && hash_equals( Trips::share_code( $trip ), $sub['share'] ) ) {
			return true;
		}
		$was = get_current_user_id();
		wp_set_current_user( $sub['user'] );
		$skips = Trips::skips_password( $trip );
		wp_set_current_user( $was );
		return $skips;
	}

	/**
	 * A link to a trip, or to one of its steps, that opens it for a subscriber: a
	 * protected trip's carries its share code.
	 */
	public static function trip_link( \WP_Post $trip, int $step_id = 0 ): string {
		$url = Trips::share_url( $trip ) ?: Trips::url( $trip );
		return $url . ( $step_id ? '#step-' . $step_id : '' );
	}
}
