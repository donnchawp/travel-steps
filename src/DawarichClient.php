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

	public function __construct( ?string $base_url = null, ?string $api_key = null ) {
		$this->base_url = rtrim( $base_url ?? (string) Settings::get( 'dawarich_url' ), '/' );
		$this->api_key  = $api_key ?? (string) Settings::get( 'dawarich_key' );
	}

	/**
	 * All location points between two unix timestamps, oldest first.
	 *
	 * @return array<int, array{0: float, 1: float, 2: int, 3: string, 4: string}>|\WP_Error
	 *         Rows of [ lat, lon, timestamp, city, country ].
	 */
	public function points( int $start, int $end ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'travel_steps_no_dawarich_key', __( 'No Dawarich API key configured.', 'travel-steps' ) );
		}

		$points = [];
		$page   = 1;
		do {
			$url      = add_query_arg(
				[
					'start_at' => gmdate( 'c', $start ),
					'end_at'   => gmdate( 'c', $end ),
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
