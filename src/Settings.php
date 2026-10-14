<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site settings, which administrators set: the Immich and Dawarich servers, and how
 * steps and photos are picked. And each user's own account settings: their API keys,
 * which reach their whole photo library and location history, and their home. A
 * trip always uses its author's.
 */
class Settings {
	const OPTION = 'travel_steps_settings';
	// A user option, so on multisite each site keeps its own.
	const ACCOUNT  = 'travel_steps_account';
	const MIGRATED = 'travel_steps_keys_moved';

	const DEFAULTS = [
		// Container names on the shared docker network; WordPress talks to them directly.
		'dawarich_url'    => 'http://dawarich_app:3000',
		'immich_url'      => '',
		'place_radius_km' => 2,
		'auto_pick'       => 8,
	];

	const ACCOUNT_DEFAULTS = [
		'immich_key'     => '',
		'dawarich_key'   => '',
		'home_lat'       => '',
		'home_lon'       => '',
		'home_radius_km' => 1,
		// User ids who see all this user's password-protected trips without the password.
		'viewers'        => [],
	];

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : [] );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Immich URL, falling back to the Media Picker for Immich plugin's settings.
	 */
	public static function immich_url(): string {
		$url = (string) self::get( 'immich_url' );
		if ( '' === $url ) {
			$picker = get_option( 'immich_settings', [] );
			$url    = $picker['api_url'] ?? 'http://immich_server:2283';
		}
		return rtrim( $url, '/' );
	}

	/**
	 * A user's own keys and home.
	 *
	 * @return array{immich_key: string, dawarich_key: string, home_lat: string, home_lon: string, home_radius_km: float|int, viewers: int[]}
	 */
	public static function account( int $user_id ): array {
		$saved = $user_id ? get_user_option( self::ACCOUNT, $user_id ) : [];
		return array_merge( self::ACCOUNT_DEFAULTS, is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Users who see all of a user's password-protected trips without the password.
	 *
	 * @return int[]
	 */
	public static function viewers( int $user_id ): array {
		return Trips::user_ids( self::account( $user_id )['viewers'] );
	}

	public static function update_account( int $user_id, array $account ): void {
		update_user_option( $user_id, self::ACCOUNT, array_intersect_key( $account, self::ACCOUNT_DEFAULTS ) );
	}

	/**
	 * A user's home, which sync hides from the start and end of their trips, or null when unset.
	 */
	public static function home( int $user_id ): ?array {
		$account = self::account( $user_id );
		if ( '' === $account['home_lat'] || '' === $account['home_lon'] ) {
			return null;
		}
		return [
			'lat'      => (float) $account['home_lat'],
			'lon'      => (float) $account['home_lon'],
			'radius_m' => max( 0, (float) $account['home_radius_km'] ) * 1000,
		];
	}

	/**
	 * Save the current user's own keys and home.
	 */
	public static function handle_save_account(): void {
		if ( ! current_user_can( Trips::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'travel-steps' ), 403 );
		}
		check_admin_referer( 'travel_steps_account' );

		$errors  = [];
		$user_id = get_current_user_id();
		self::update_account( $user_id, self::sanitize_account( wp_unslash( $_POST ), self::account( $user_id ), $errors ) );
		$args = in_array( 'home', $errors, true ) ? [ 'invalid' => 'home' ] : [ 'saved' => '1' ];
		wp_safe_redirect( add_query_arg( $args, App::url( 'settings' ) ) );
		exit;
	}

	/**
	 * Save the site's settings, which only administrators may change.
	 */
	public static function handle_save_site(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'travel-steps' ), 403 );
		}
		check_admin_referer( 'travel_steps_settings' );

		update_option( self::OPTION, self::sanitize_site( wp_unslash( $_POST ) ), false );
		wp_safe_redirect( add_query_arg( 'saved', '1', App::url( 'settings' ) ) );
		exit;
	}

	public static function sanitize_site( array $input ): array {
		return [
			'dawarich_url'    => esc_url_raw( trim( $input['dawarich_url'] ?? '' ) ),
			'immich_url'      => esc_url_raw( trim( $input['immich_url'] ?? '' ) ),
			'place_radius_km' => max( 0.1, (float) ( $input['place_radius_km'] ?? 2 ) ),
			'auto_pick'       => min( 50, max( 1, (int) ( $input['auto_pick'] ?? 8 ) ) ),
		];
	}

	/**
	 * A user's account settings to store from a submitted form. A field that can't be
	 * read keeps its saved value and is named in $errors.
	 */
	public static function sanitize_account( array $input, array $existing, array &$errors = [] ): array {
		$home = self::home_input( (string) ( $input['home_lat'] ?? '' ), (string) ( $input['home_lon'] ?? '' ) );
		if ( null === $home ) {
			$errors[] = 'home';
			$home     = [ $existing['home_lat'], $existing['home_lon'] ];
		}
		$new = [
			'home_lat'       => $home[0],
			'home_lon'       => $home[1],
			'home_radius_km' => max( 0, (float) ( $input['home_radius_km'] ?? 1 ) ),
		];
		// Keys are write-only in the form: an empty field keeps the stored key, unless
		// it's ticked for removal.
		foreach ( [ 'dawarich_key', 'immich_key' ] as $key ) {
			$value = trim( sanitize_text_field( $input[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$new[ $key ] = $value;
			} else {
				$new[ $key ] = empty( $input[ $key . '_remove' ] ) ? $existing[ $key ] : '';
			}
		}
		return $new;
	}

	/**
	 * Home as [ lat, lon ] decimal-degree strings, both '' to clear it, or null when it
	 * can't be read. Either field takes a "lat, lon" pair as copied from a map.
	 */
	private static function home_input( string $lat, string $lon ): ?array {
		$lat = trim( $lat );
		$lon = trim( $lon );
		foreach ( [ $lat, $lon ] as $field ) {
			// "51.89, -8.47", "51.89,-8.47", "51.89 -8.47" or "51.89; -8.47", but not
			// "48,85": that's a decimal comma.
			if ( preg_match( '/^(-?\d+(?:\.\d+)?)\s*(?:,\s+|;\s*|\s+)(-?\d+(?:\.\d+)?)$/', $field, $pair ) || preg_match( '/^(-?\d+\.\d+),(-?\d+\.\d+)$/', $field, $pair ) ) {
				[ , $lat, $lon ] = $pair;
				break;
			}
		}
		if ( '' === $lat && '' === $lon ) {
			return [ '', '' ];
		}
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) || abs( (float) $lat ) > 90 || abs( (float) $lon ) > 180 ) {
			return null;
		}
		return [ (string) (float) $lat, (string) (float) $lon ];
	}

	/**
	 * Once, on upgrade from site-wide keys: give the site's keys and home to everyone
	 * who has written a trip that isn't trashed or an auto-draft and may still make
	 * trips (or, with none, every administrator) and hasn't saved their own, then take
	 * them out of the site settings, so existing trips keep working.
	 *
	 * The flag is set last, so a migration that dies part-way runs again. Two requests
	 * running it at once is harmless: nobody's saved account is overwritten.
	 */
	public static function migrate(): void {
		if ( get_option( self::MIGRATED ) ) {
			return;
		}
		$saved  = get_option( self::OPTION, [] );
		$saved  = is_array( $saved ) ? $saved : [];
		$picker = get_option( 'immich_settings', [] );
		// The key in effect before: Travel Steps' own, else Media Picker for Immich's.
		$account = array_intersect_key( $saved, self::ACCOUNT_DEFAULTS );
		if ( empty( $account['immich_key'] ) ) {
			$account['immich_key'] = (string) ( $picker['api_key'] ?? '' );
		}
		if ( array_filter( array_diff_key( $account, [ 'home_radius_km' => 1 ] ) ) ) {
			global $wpdb;
			$authors = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft' )", Trips::TRIP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$authors = array_filter( array_map( 'intval', $authors ), fn( $id ) => user_can( $id, Trips::capability() ) );
			$users   = $authors ?: get_users(
				[
					'capability' => 'manage_options',
					'fields'     => 'ID',
				]
			);
			foreach ( array_map( 'intval', $users ) as $user_id ) {
				if ( false === get_user_option( self::ACCOUNT, $user_id ) ) {
					self::update_account( $user_id, $account + self::ACCOUNT_DEFAULTS );
				}
			}
		}
		update_option( self::OPTION, array_intersect_key( $saved, self::DEFAULTS ), false );
		add_option( self::MIGRATED, time() );
	}
}
