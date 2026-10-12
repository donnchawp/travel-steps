<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImmichClient {
	const PAGE_SIZE = 1000;
	const MAX_PAGES = 20;
	// The edit page lists albums on every load, so a slow Immich mustn't hold it up.
	const ALBUMS_TIMEOUT_S = 5;
	const ALBUMS_TTL_S     = 5 * MINUTE_IN_SECONDS;
	const ALBUMS_ERROR_S   = MINUTE_IN_SECONDS;

	private string $base_url;
	private string $api_key;
	// Whose key this is, to name in errors.
	private string $owner = '';

	public function __construct( ?string $base_url = null, string $api_key = '' ) {
		$this->base_url = rtrim( $base_url ?? Settings::immich_url(), '/' );
		$this->api_key  = $api_key;
	}

	/**
	 * A client with a user's own key, never anyone else's.
	 */
	public static function for_user( int $user_id ): self {
		$client        = new self( null, Settings::account( $user_id )['immich_key'] );
		$client->owner = (string) get_the_author_meta( 'display_name', $user_id );
		return $client;
	}

	private function no_key(): \WP_Error {
		return new \WP_Error(
			'travel_steps_no_immich_key',
			'' === $this->owner
				? __( 'No Immich API key configured.', 'travel-steps' )
				/* translators: %s: a user's name */
				: sprintf( __( '%s hasn’t saved an Immich API key in Travel Steps settings.', 'travel-steps' ), $this->owner )
		);
	}

	/**
	 * @return array|\WP_Error Decoded JSON.
	 */
	private function request( string $endpoint, string $method = 'GET', ?array $body = null, int $timeout = 60 ) {
		if ( '' === $this->api_key ) {
			return $this->no_key();
		}
		$args = [
			'method'  => $method,
			'headers' => [
				'x-api-key'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'timeout' => $timeout,
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $this->base_url . $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, [ 401, 403 ], true ) && '' !== $this->owner ) {
			/* translators: 1: a user's name, 2: HTTP status code */
			return new \WP_Error( 'travel_steps_immich_key_refused', sprintf( __( 'Immich refused %1$s’s API key (HTTP %2$d).', 'travel-steps' ), $this->owner, $code ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'travel_steps_immich_error', sprintf( __( 'Immich returned HTTP %d.', 'travel-steps' ), $code ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) ? $json : [];
	}

	/**
	 * Albums by name, cached for a few minutes per server and key. A failure is cached
	 * too, briefly, so an unresponsive Immich costs one short wait, not one per page load.
	 *
	 * @return array<int, array{id: string, name: string, count: int}>|\WP_Error
	 */
	public function albums() {
		$key    = 'travel_steps_albums_' . md5( $this->base_url . '|' . $this->api_key );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] ) ? new \WP_Error( 'travel_steps_immich_error', $cached['error'] ) : $cached['albums'];
		}

		$albums = $this->request( '/api/albums', 'GET', null, self::ALBUMS_TIMEOUT_S );
		if ( is_wp_error( $albums ) ) {
			// A refusal is quick to repeat, and names the key's owner: others may share the key.
			if ( 'travel_steps_immich_key_refused' !== $albums->get_error_code() ) {
				set_transient( $key, [ 'error' => $albums->get_error_message() ], self::ALBUMS_ERROR_S );
			}
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
		set_transient( $key, [ 'albums' => $out ], self::ALBUMS_TTL_S );
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

		$photos = Days::resolve_times( $photos, wp_timezone() );
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
		$local  = isset( $asset['localDateTime'] ) ? strtotime( $asset['localDateTime'] ) : false;
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
			// Without a zone, Immich's time is wall-clock time as UTC; resolve_times() fixes it.
			'local'   => false === $local ? $time : $local,
			'zoned'   => ! empty( $exif['timeZone'] ),
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
			return $this->no_key();
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
