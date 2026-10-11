<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImmichClient {
	const PAGE_SIZE = 1000;
	const MAX_PAGES = 20;
	const SIZES     = [ 'thumbnail', 'preview' ];

	private string $base_url;
	private string $api_key;

	public function __construct( ?string $base_url = null, ?string $api_key = null ) {
		$this->base_url = rtrim( $base_url ?? Settings::immich_url(), '/' );
		$this->api_key  = $api_key ?? Settings::immich_key();
	}

	/**
	 * @return array|\WP_Error Decoded JSON.
	 */
	private function request( string $endpoint, string $method = 'GET', ?array $body = null ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'travel_steps_no_immich_key', __( 'No Immich API key configured.', 'travel-steps' ) );
		}
		$args = [
			'method'  => $method,
			'headers' => [
				'x-api-key'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'timeout' => 60,
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $this->base_url . $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'travel_steps_immich_error', sprintf( __( 'Immich returned HTTP %d.', 'travel-steps' ), $code ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) ? $json : [];
	}

	/**
	 * @return array<int, array{id: string, name: string, count: int}>|\WP_Error
	 */
	public function albums() {
		$albums = $this->request( '/api/albums' );
		if ( is_wp_error( $albums ) ) {
			return $albums;
		}
		$out = [];
		foreach ( $albums as $album ) {
			$out[] = [
				'id'    => (string) $album['id'],
				'name'  => (string) ( $album['albumName'] ?? '' ),
				'count' => (int) ( $album['assetCount'] ?? 0 ),
			];
		}
		usort( $out, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * Photos in an album, or taken between two unix timestamps when no album is given.
	 *
	 * @return array<int, array>|\WP_Error Normalised photo rows, oldest first.
	 */
	public function photos( int $start, int $end, string $album_id = '' ) {
		$query = [
			'withExif' => true,
			'size'     => self::PAGE_SIZE,
		];
		if ( '' !== $album_id ) {
			$query['albumIds'] = [ $album_id ];
		} else {
			$query['takenAfter']  = gmdate( 'c', $start );
			$query['takenBefore'] = gmdate( 'c', $end );
		}

		$photos = [];
		for ( $i = 0; $i < self::MAX_PAGES; $i++ ) {
			$result = $this->request( '/api/search/metadata', 'POST', $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( $result['assets']['items'] ?? [] as $asset ) {
				$photo = self::normalise( $asset );
				if ( $photo ) {
					$photos[] = $photo;
				}
			}
			// Immich 3.2+ pages with a cursor; older servers with a page number.
			if ( ! empty( $result['assets']['nextCursor'] ) ) {
				$query['cursor'] = $result['assets']['nextCursor'];
			} elseif ( ! empty( $result['assets']['nextPage'] ) ) {
				$query['page'] = (int) $result['assets']['nextPage'];
			} else {
				break;
			}
		}

		usort( $photos, fn( $a, $b ) => $a['t'] <=> $b['t'] );
		return $photos;
	}

	private static function normalise( array $asset ): ?array {
		if ( 'IMAGE' !== ( $asset['type'] ?? '' ) || ! empty( $asset['isTrashed'] ) ) {
			return null;
		}
		$exif  = $asset['exifInfo'] ?? [];
		$taken = $exif['dateTimeOriginal'] ?? $asset['fileCreatedAt'] ?? null;
		$time  = $taken ? strtotime( $taken ) : false;
		if ( false === $time ) {
			return null;
		}
		$width  = (int) ( $asset['width'] ?? $exif['exifImageWidth'] ?? 0 );
		$height = (int) ( $asset['height'] ?? $exif['exifImageHeight'] ?? 0 );
		// EXIF dimensions are pre-rotation.
		if ( empty( $asset['width'] ) && in_array( (string) ( $exif['orientation'] ?? '' ), [ '5', '6', '7', '8' ], true ) ) {
			[ $width, $height ] = [ $height, $width ];
		}
		return [
			'id'      => (string) $asset['id'],
			't'       => $time,
			'day'     => Days::local_day( $asset['localDateTime'] ?? null, $time, wp_timezone() ),
			'lat'     => isset( $exif['latitude'] ) ? (float) $exif['latitude'] : null,
			'lon'     => isset( $exif['longitude'] ) ? (float) $exif['longitude'] : null,
			'w'       => $width,
			'h'       => $height,
			'city'    => (string) ( $exif['city'] ?? '' ),
			'country' => (string) ( $exif['country'] ?? '' ),
			'caption' => (string) ( $exif['description'] ?? '' ),
			'fav'     => ! empty( $asset['isFavorite'] ),
			'camera'  => trim( ( $exif['make'] ?? '' ) . ' ' . ( $exif['model'] ?? '' ) ),
		];
	}

	/**
	 * Raw thumbnail bytes for an asset.
	 *
	 * @return array{body: string, type: string}|\WP_Error
	 */
	public function thumbnail( string $asset_id, string $size ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'travel_steps_no_immich_key', __( 'No Immich API key configured.', 'travel-steps' ) );
		}
		$response = wp_remote_get(
			$this->base_url . '/api/assets/' . rawurlencode( $asset_id ) . '/thumbnail?size=' . rawurlencode( $size ),
			[
				'headers' => [ 'x-api-key' => $this->api_key ],
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'travel_steps_immich_error', 'Thumbnail unavailable' );
		}
		return [
			'body' => wp_remote_retrieve_body( $response ),
			'type' => strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ?: 'image/jpeg',
		];
	}
}
