<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lock kept in an option, so a job (a trip's sync, moving the photo cache) runs only
 * once at a time.
 */
class Lock {
	/**
	 * Run $job and return what it returns, or $busy if another request holds the lock.
	 * A lock held for 10 minutes was left behind by a request that died, and is taken over.
	 *
	 * @param mixed $busy
	 * @return mixed
	 */
	public static function run( string $name, callable $job, $busy = null ) {
		// add_option() is an atomic insert, so two requests can't both take the lock.
		if ( ! add_option( $name, time(), '', false ) ) {
			if ( (int) get_option( $name ) > time() - 10 * MINUTE_IN_SECONDS ) {
				return $busy;
			}
			update_option( $name, time(), false );
		}
		try {
			return $job();
		} finally {
			delete_option( $name );
		}
	}
}
