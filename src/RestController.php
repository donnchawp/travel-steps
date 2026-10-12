<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestController {
	const NS = 'travel-steps/v1';
	// A photo source's sizes, plus medium: its preview scaled down to this on the short side, for grids.
	const SIZES           = [ 'thumbnail', 'medium', 'preview' ];
	const MEDIUM_SHORT_PX = 640;

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/trips/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_trip' ],
				'permission_callback' => [ $this, 'can_view_trip' ],
			]
		);
		register_rest_route(
			self::NS,
			'/trips/(?P<id>\d+)/photo-state',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_photo_state' ],
				'permission_callback' => fn( $request ) => Trips::TRIP === get_post_type( (int) $request['id'] ) && current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => [
					'asset' => [
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9-]{36}$',
					],
					'state' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'default', 'pinned', 'hidden' ],
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/trips/(?P<id>\d+)/cover',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_cover' ],
				'permission_callback' => fn( $request ) => Trips::TRIP === get_post_type( (int) $request['id'] ) && current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => [
					// An asset id, or empty to go back to the automatic cover.
					'asset' => [
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^([a-f0-9-]{36})?$',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/trips/(?P<id>\d+)/photo/(?P<asset>[a-f0-9-]{36})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'serve_photo' ],
				'permission_callback' => [ $this, 'can_view_trip' ],
				'args'                => [
					'size' => [
						'type'    => 'string',
						'enum'    => self::SIZES,
						'default' => 'thumbnail',
					],
				],
			]
		);
	}

	public function can_view_trip( \WP_REST_Request $request ): bool {
		$trip = get_post( (int) $request['id'] );
		return $trip && Trips::can_view( $trip );
	}

	/**
	 * @param bool $authed Add a REST nonce so <img> requests are cookie-authenticated
	 *                     (needed for drafts and hidden photos).
	 */
	public static function photo_url( int $trip_id, string $asset_id, string $size, bool $authed = false ): string {
		$args = [ 'size' => $size ];
		if ( $authed ) {
			$args['_wpnonce'] = wp_create_nonce( 'wp_rest' );
		}
		return add_query_arg( $args, rest_url( self::NS . '/trips/' . $trip_id . '/photo/' . $asset_id ) );
	}

	public function get_trip( \WP_REST_Request $request ): \WP_REST_Response {
		$trip     = get_post( (int) $request['id'] );
		$can_edit = current_user_can( 'edit_post', $trip->ID );
		$route    = json_decode( (string) get_post_meta( $trip->ID, Trips::META_ROUTE, true ), true );
		$steps    = Trips::timeline_steps( $trip, $can_edit );
		$by_step  = Trips::photos_by_step( $trip->ID, $steps );

		$first   = (string) get_post_meta( $trip->ID, Trips::META_START, true );
		$markers = [];
		foreach ( $steps as $index => $step ) {
			// Same label as the timeline: the day number.
			$number  = ( $first ? Days::day_number( $first, Trips::day( $step ) ) : 0 ) ?: $index + 1;
			$groups  = $by_step[ $step->ID ] ?? [ 'shown' => [], 'rest' => [] ];
			$photos  = array_merge( $groups['shown'], $groups['rest'] );
			usort( $photos, fn( $a, $b ) => $a['t'] <=> $b['t'] );
			$markers = array_merge( $markers, View::markers( $step, $photos, $number ) );
		}

		$response = new \WP_REST_Response(
			[
				'id'       => $trip->ID,
				'route'    => is_array( $route ) ? $route : [],
				'markers'  => $markers,
				'can_edit' => $can_edit,
			]
		);
		$response->header( 'Cache-Control', 'publish' === $trip->post_status && ! $can_edit ? 'public, max-age=300' : 'private, no-store' );
		return $response;
	}

	public function set_photo_state( \WP_REST_Request $request ): \WP_REST_Response {
		$trip_id = (int) $request['id'];
		$asset   = (string) $request['asset'];
		$state   = (string) $request['state'];
		$lists   = [
			'pinned' => [ Trips::META_PINNED, Trips::pinned( $trip_id ) ],
			'hidden' => [ Trips::META_HIDDEN, Trips::hidden( $trip_id ) ],
		];
		// A photo is in at most one list: pinning unhides and hiding unpins.
		foreach ( $lists as $name => [ $key, $ids ] ) {
			$ids = array_values( array_diff( $ids, [ $asset ] ) );
			if ( $name === $state ) {
				$ids[] = $asset;
			}
			update_post_meta( $trip_id, $key, $ids );
		}
		return new \WP_REST_Response(
			[
				'asset' => $asset,
				'state' => $state,
			]
		);
	}

	/**
	 * Choose the trip's header photo from its own photos, or clear it.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_cover( \WP_REST_Request $request ) {
		$trip_id = (int) $request['id'];
		$asset   = (string) $request['asset'];
		if ( '' === $asset ) {
			delete_post_meta( $trip_id, Trips::META_COVER );
		} elseif ( in_array( $asset, array_column( Trips::photos( $trip_id ), 'id' ), true ) ) {
			update_post_meta( $trip_id, Trips::META_COVER, $asset );
		} else {
			return new \WP_Error( 'travel_steps_not_in_trip', 'That photo isn’t part of this trip.', [ 'status' => 400 ] );
		}
		return new \WP_REST_Response(
			[
				'asset' => Trips::cover( $trip_id ),
				'url'   => View::cover_url( $trip_id ),
			]
		);
	}

	/**
	 * Stream a photo from the trip's source. Only assets that belong to the trip are
	 * served, so the source (an Immich server and its API key) never has to be
	 * reachable from outside.
	 *
	 * @return \WP_Error|void
	 */
	public function serve_photo( \WP_REST_Request $request ) {
		$trip_id = (int) $request['id'];
		$asset   = (string) $request['asset'];
		$size    = (string) $request['size'];

		// Only photos shown on a published step for the public; anything synced for editors.
		if ( ! isset( Trips::viewable_photo_ids( get_post( $trip_id ) )[ $asset ] ) ) {
			return new \WP_Error( 'travel_steps_not_found', 'Not found', [ 'status' => 404 ] );
		}
		$hidden = in_array( $asset, Trips::hidden( $trip_id ), true );

		$is_public = 'publish' === get_post_status( $trip_id ) && ! $hidden;
		// An asset's thumbnail doesn't change, so its tag needn't depend on the cache file.
		$etag = '"' . md5( $asset . '/' . $size ) . '"';
		// Revalidation runs the access check above every time, but costs no image bytes.
		if ( self::not_modified( $etag, (string) $request->get_header( 'if_none_match' ) ) ) {
			self::send_cache_headers( $is_public, $etag );
			status_header( 304 );
			exit;
		}

		$cache = self::cache_file( $asset, $size );
		if ( ! is_readable( $cache ) ) {
			$source = Trips::source( $trip_id );
			$image  = 'medium' === $size ? self::medium( $source, $asset ) : $source->image( $asset, $size );
			if ( is_wp_error( $image ) || '' === $image['body'] ) {
				return new \WP_Error( 'travel_steps_unavailable', 'Unavailable', [ 'status' => 502 ] );
			}
			// Serve what we fetched even if it couldn't be cached.
			self::cache( $asset, $size, $image );
			self::send_cache_headers( $is_public, $etag );
			self::send_type( $image['type'] );
			header( 'Content-Length: ' . strlen( $image['body'] ) );
			echo $image['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		self::send_cache_headers( $is_public, $etag );
		self::send_type( is_readable( $cache . '.type' ) ? trim( (string) file_get_contents( $cache . '.type' ) ) : 'image/jpeg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		header( 'Content-Length: ' . filesize( $cache ) );
		readfile( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// Only for a photo actually served, so errors don't land in shared caches.
	private static function send_cache_headers( bool $is_public, string $etag ): void {
		header( 'Cache-Control: ' . self::photo_cache_control( $is_public ) );
		header( 'ETag: ' . $etag );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Medium size, made from the preview, which is cached on the way so the lightbox
	 * can use it.
	 *
	 * @return array{body: string, type: string}|\WP_Error
	 */
	private static function medium( PhotoSource $source, string $asset ) {
		$preview = self::cache_file( $asset, 'preview' );
		if ( is_readable( $preview ) ) {
			$type  = is_readable( $preview . '.type' ) ? trim( (string) file_get_contents( $preview . '.type' ) ) : 'image/jpeg'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$image = [ 'body' => (string) file_get_contents( $preview ), 'type' => $type ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$image = $source->image( $asset, 'preview' );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			self::cache( $asset, 'preview', $image );
		}
		$medium = self::shrink( $image['body'], $image['type'] );
		// Without an image editor, the preview will do.
		return is_wp_error( $medium ) ? $image : $medium;
	}

	/**
	 * Scale an image down to MEDIUM_SHORT_PX on its short side, never up.
	 *
	 * @return array{body: string, type: string}|\WP_Error
	 */
	public static function shrink( string $body, string $type ) {
		$ext = wp_get_default_extension_for_mime_type( $type ) ?: 'jpg';
		$in  = get_temp_dir() . 'ts-photo-' . wp_generate_password( 12, false ) . '.' . $ext;
		$out = substr( $in, 0, -strlen( $ext ) - 1 ) . '-medium.' . $ext;
		try {
			file_put_contents( $in, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$editor = wp_get_image_editor( $in );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			$size  = $editor->get_size();
			$short = self::MEDIUM_SHORT_PX;
			if ( min( $size['width'], $size['height'] ) > $short ) {
				$resized = $size['width'] >= $size['height'] ? $editor->resize( null, $short ) : $editor->resize( $short, null );
				if ( is_wp_error( $resized ) ) {
					return $resized;
				}
			}
			$editor->set_quality( 82 );
			$saved = $editor->save( $out, $type );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return [ 'body' => (string) file_get_contents( $saved['path'] ), 'type' => $saved['mime-type'] ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} finally {
			wp_delete_file( $in );
			wp_delete_file( $out );
		}
	}

	private static function send_type( string $type ): void {
		header( 'Content-Type: ' . ( preg_match( '#^image/[a-z0-9.+-]+$#', $type ) ? $type : 'image/jpeg' ) );
	}

	/**
	 * Cache a thumbnail so it's never seen half-written: each file is written under a
	 * temporary name and renamed into place, the type first, so a reader that finds the
	 * image also finds its type. An empty thumbnail isn't cached.
	 */
	public static function store_cached( string $file, string $body, string $type ): bool {
		if ( '' === $body ) {
			return false;
		}
		wp_mkdir_p( dirname( $file ) );
		foreach ( [ $file . '.type' => $type, $file => $body ] as $path => $data ) {
			$tmp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
			if ( strlen( $data ) !== file_put_contents( $tmp, $data ) || ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename
				wp_delete_file( $tmp );
				return false;
			}
		}
		return true;
	}

	/**
	 * Unpublishing a step or hiding a photo has to reach shared caches (CDNs, proxies)
	 * soon, so they keep a public photo 5 minutes; browsers, which already have it, an
	 * hour. Photos of drafts never go into a shared cache.
	 */
	public static function photo_cache_control( bool $is_public ): string {
		return $is_public ? 'public, max-age=3600, s-maxage=300' : 'private, max-age=3600';
	}

	/**
	 * Whether an If-None-Match header names $etag (weak or strong, or *).
	 */
	public static function not_modified( string $etag, string $if_none_match ): bool {
		foreach ( explode( ',', $if_none_match ) as $tag ) {
			$tag = trim( $tag );
			if ( '*' === $tag || ( '' !== $tag && preg_replace( '#^W/#', '', $tag ) === $etag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cached thumbnails sit behind a deny rule (Apache), in folders that can't be
	 * listed, under unguessable asset ids, so they're meant to be reached only through
	 * the checks above.
	 */
	private static function cache_folder( string $asset, string $size ): string {
		return $size . '/' . substr( $asset, 0, 2 );
	}

	private static function cache_file( string $asset, string $size ): string {
		return wp_get_upload_dir()['basedir'] . '/travel-steps-cache/' . self::cache_folder( $asset, $size ) . '/' . $asset;
	}

	private static function cache( string $asset, string $size, array $image ): void {
		Uploads::protect( wp_get_upload_dir()['basedir'] . '/travel-steps-cache', self::cache_folder( $asset, $size ) );
		self::store_cached( self::cache_file( $asset, $size ), $image['body'], $image['type'] );
	}

	/**
	 * Remove every cached size of a photo.
	 */
	public static function delete_cached( string $asset ): void {
		foreach ( self::SIZES as $size ) {
			$file = self::cache_file( $asset, $size );
			wp_delete_file( $file );
			wp_delete_file( $file . '.type' );
		}
	}
}
