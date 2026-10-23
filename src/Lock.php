<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lock kept in an option, so a job (a trip's sync, moving the photo cache) runs only
 * once at a time.
 *
 * Its row is written with queries that fail when another request got there first, in
 * place of add_option(), update_option() and delete_option(): those go by this request's
 * option caches, and overwrite or delete whatever the row holds. Its value is when it was
 * taken and a token of the run that holds it.
 */
class Lock {
	/** A lock held this long was left behind by a request that died, and is taken over. */
	const STALE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Run $job and return what it returns, or $busy if another request holds the lock.
	 *
	 * @param mixed $busy
	 * @return mixed
	 */
	public static function run( string $name, callable $job, $busy = null ) {
		$value = time() . ' ' . wp_generate_uuid4();
		if ( ! self::take( $name, $value ) ) {
			return $busy;
		}
		try {
			return $job();
		} finally {
			self::release( $name, $value );
		}
	}

	private static function take( string $name, string $value ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The lock has to be read and written past the option caches, and never cached.
		// Fails on the unique option_name when the row exists. Quietly: that's a held lock, not an error.
		$quiet = $wpdb->suppress_errors();
		$taken = (bool) $wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => $value, 'autoload' => 'off' ] );
		$wpdb->suppress_errors( $quiet );
		if ( ! $taken ) {
			$held  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			// Taken over only from the value read, so two requests can't both take over a dead lock.
			$taken = null !== $held && (int) $held <= time() - self::STALE
				&& 1 === $wpdb->update( $wpdb->options, [ 'option_value' => $value ], [ 'option_name' => $name, 'option_value' => $held ] );
		}
		// phpcs:enable
		self::forget( $name );
		return $taken;
	}

	/** Deletes the row only while it's still this run's, not after another request took it over. */
	private static function release( string $name, string $value ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name, 'option_value' => $value ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See take().
		self::forget( $name );
	}

	/** Drops what the option caches hold for the lock, so get_option() asks the database. */
	private static function forget( string $name ): void {
		wp_cache_delete( $name, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}
}
