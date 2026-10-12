<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DawarichClient {
	const PER_PAGE  = 10000;
	const MAX_PAGES = 50;

	private string $base_url;
	private string $api_key;
	// Whose key this is, to name in errors.
	private string $owner = '';

	public function __construct( ?string $base_url = null, string $api_key = '' ) {
		$this->base_url = rtrim( $base_url ?? (string) Settings::get( 'dawarich_url' ), '/' );
		$this->api_key  = $api_key;
	}

	/**
	 * A client with a user's own key, never anyone else's.
	 */
	public static function for_user( int $user_id ): self {
		$client        = new self( null, Settings::account( $user_id )['dawarich_key'] );
		$client->owner = (string) get_the_author_meta( 'display_name', $user_id );
		return $client;
	}

	/**
	 * Dawarich is optional: without it, trips are mapped from their photos' locations.
	 */
	public function configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * All location points between two unix timestamps, oldest first.
	 *
	 * @return array<int, array{0: float, 1: float, 2: int, 3: string, 4: string}>|\WP_Error
	 *         Rows of [ lat, lon, timestamp, city, country ].
	 */
	public function points( int $start, int $end ) {
		// No location history without Dawarich; sync maps trips from photos instead.
		if ( ! $this->configured() ) {
			return [];
		}

		$points = [];
		$page   = 1;
		do {
			// add_query_arg() doesn't encode values, and a bare "+" in "+00:00" reads as a space.
			$url      = add_query_arg(
				[
					'start_at' => rawurlencode( gmdate( 'c', $start ) ),
					'end_at'   => rawurlencode( gmdate( 'c', $end ) ),
					'order'    => 'asc',
					'page'     => $page,
					'per_page' => self::PER_PAGE,
				],
				$this->base_url . '/api/v1/points'
			);
			$response = wp_remote_get(
				$url,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $this->api_key,
						'Accept'        => 'application/json',
					],
					'timeout' => 60,
				]
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, [ 401, 403 ], true ) && '' !== $this->owner ) {
				/* translators: 1: a user's name, 2: HTTP status code */
				return new \WP_Error( 'travel_steps_dawarich_key_refused', sprintf( __( 'Dawarich refused %1$s’s API key (HTTP %2$d).', 'travel-steps' ), $this->owner, $code ) );
			}
			if ( 200 !== $code ) {
				/* translators: %d: HTTP status code */
				return new \WP_Error( 'travel_steps_dawarich_error', sprintf( __( 'Dawarich returned HTTP %d.', 'travel-steps' ), $code ) );
			}

			$rows = json_decode( wp_remote_retrieve_body( $response ), true );
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				if ( ! isset( $row['latitude'], $row['longitude'], $row['timestamp'] ) ) {
					continue;
				}
				$points[] = [
					(float) $row['latitude'],
					(float) $row['longitude'],
					(int) $row['timestamp'],
					(string) ( $row['city'] ?? '' ),
					(string) ( $row['country_name'] ?? $row['country'] ?? '' ),
				];
			}

			$total_pages = (int) wp_remote_retrieve_header( $response, 'x-total-pages' );
			++$page;
		} while ( $page <= $total_pages && $page <= self::MAX_PAGES );

		usort( $points, fn( $a, $b ) => $a[2] <=> $b[2] );
		return $points;
	}
}
