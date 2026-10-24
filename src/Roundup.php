<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The daily email to a trip's subscribers listing what's new on it.
 */
class Roundup {
	/**
	 * Records a trip's current state as sent, if nothing has been recorded for it yet.
	 */
	public static function baseline( int $trip_id ): void {
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
