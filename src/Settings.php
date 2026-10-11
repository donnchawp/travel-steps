<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	const OPTION = 'travel_steps_settings';

	const DEFAULTS = [
		// Container names on the shared docker network; WordPress talks to them directly.
		'dawarich_url'      => 'http://dawarich_app:3000',
		'dawarich_key'      => '',
		'immich_url'        => '',
		'immich_key'        => '',
		'home_lat'          => '',
		'home_lon'          => '',
		'home_radius_km'    => 1,
		'place_radius_km'   => 2,
		'auto_pick'         => 8,
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

	public static function immich_key(): string {
		$key = (string) self::get( 'immich_key' );
		if ( '' === $key ) {
			$picker = get_option( 'immich_settings', [] );
			$key    = $picker['api_key'] ?? '';
		}
		return $key;
	}

	/**
	 * Home location used to hide the start and end of every route, or null when unset.
	 */
	public static function home(): ?array {
		$all = self::all();
		if ( '' === $all['home_lat'] || '' === $all['home_lon'] ) {
			return null;
		}
		return [
			'lat'      => (float) $all['home_lat'],
			'lon'      => (float) $all['home_lon'],
			'radius_m' => max( 0, (float) $all['home_radius_km'] ) * 1000,
		];
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'travel-steps' ), 403 );
		}
		check_admin_referer( 'travel_steps_settings' );

		$existing = self::all();
		$input    = wp_unslash( $_POST );
		$new      = [
			'dawarich_url'     => esc_url_raw( trim( $input['dawarich_url'] ?? '' ) ),
			'immich_url'       => esc_url_raw( trim( $input['immich_url'] ?? '' ) ),
			'home_lat'         => self::coordinate( $input['home_lat'] ?? '', 90 ),
			'home_lon'         => self::coordinate( $input['home_lon'] ?? '', 180 ),
			'home_radius_km'   => max( 0, (float) ( $input['home_radius_km'] ?? 1 ) ),
			'place_radius_km'  => max( 0.1, (float) ( $input['place_radius_km'] ?? 2 ) ),
			'auto_pick'        => min( 50, max( 1, (int) ( $input['auto_pick'] ?? 8 ) ) ),
		];
		// Keys are write-only in the form: an empty field keeps the stored key.
		foreach ( [ 'dawarich_key', 'immich_key' ] as $key ) {
			$value       = trim( sanitize_text_field( $input[ $key ] ?? '' ) );
			$new[ $key ] = '' !== $value ? $value : $existing[ $key ];
		}

		update_option( self::OPTION, $new, false );
		wp_safe_redirect( add_query_arg( 'saved', '1', App::url( 'settings' ) ) );
		exit;
	}

	private static function coordinate( $value, float $max ): string {
		$value = trim( (string) $value );
		if ( '' === $value || ! is_numeric( $value ) || abs( (float) $value ) > $max ) {
			return '';
		}
		return (string) (float) $value;
	}
}
