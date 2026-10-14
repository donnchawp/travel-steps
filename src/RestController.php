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
				'permission_callback' => fn( $request ) => Trips::can_edit_trip( (int) $request['id'] ),
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
				'permission_callback' => fn( $request ) => Trips::can_edit_trip( (int) $request['id'] ),
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

		$markers = [];
		foreach ( $steps as $index => $step ) {
			// Same label as the timeline: the day number.
			$number  = Trips::day_of_trip( $trip->ID, $step ) ?: $index + 1;
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
		$public = Trips::is_public( $trip ) && ! $can_edit;
		$response->header( 'Cache-Control', $public ? 'public, max-age=300' : 'private, no-store' );
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
	 * Who may fetch a photo through a trip: "public" for one anyone may see, which
	 * shared caches may keep; "private" for one only the current user may (for those a
	 * protected trip lets in, its shown photos; for editors, any synced photo; for the
	 * trip's managers, any photo uploaded to it, synced or not); "" if they may not.
	 */
	public static function access( int $trip_id, string $asset ): string {
		$trip = get_post( $trip_id );
		if ( ! $trip ) {
			return '';
		}
		if ( Trips::can_view( $trip ) && isset( Trips::shown_photo_ids( $trip )[ $asset ] ) ) {
			// Only a chosen cover can be hidden and shown.
			return Trips::is_public( $trip ) && ! in_array( $asset, Trips::hidden( $trip_id ), true ) ? 'public' : 'private';
		}
		if ( ! current_user_can( 'edit_post', $trip_id ) ) {
			return '';
		}
		$synced = in_array( $asset, array_column( Trips::photos( $trip_id ), 'id' ), true );
		return $synced || ( Trips::can_edit_trip( $trip_id ) && Uploads::attachment( $trip_id, $asset ) ) ? 'private' : '';
	}

	/**
	 * Stream a photo from the trip's source, or from the copies WordPress made of one
	 * uploaded to it. Only assets that belong to the trip are served, so the source (an
	 * Immich server and its API key) never has to be reachable from outside.
	 *
	 * @return \WP_Error|void
	 */
	public function serve_photo( \WP_REST_Request $request ) {
		$trip_id = (int) $request['id'];
		$asset   = (string) $request['asset'];
		$size    = (string) $request['size'];

		$access = self::access( $trip_id, $asset );
		if ( '' === $access ) {
			return new \WP_Error( 'travel_steps_not_found', 'Not found', [ 'status' => 404 ] );
		}
		$is_public = 'public' === $access;
		$upload = Uploads::attachment( $trip_id, $asset );
		$etag   = self::etag( $asset, $size, $upload );
		// Revalidation runs the access check above every time, but costs no image bytes.
		if ( self::not_modified( $etag, (string) $request->get_header( 'if_none_match' ) ) ) {
			self::send_cache_headers( $is_public, $etag );
			status_header( 304 );
			exit;
		}

		$cache = self::cache_file( $asset, $size );
		if ( ! is_readable( $cache ) ) {
			$image = self::image( $trip_id, $asset, $size, $upload );
			if ( is_wp_error( $image ) || '' === ( $image['body'] ?? $image['file'] ) ) {
				return new \WP_Error( 'travel_steps_unavailable', 'Unavailable', [ 'status' => 502 ] );
			}
			// A copy WordPress made of an upload is served as it is, not cached again.
			if ( isset( $image['file'] ) ) {
				self::send_file( $is_public, $etag, $image['file'], $image['type'] );
			}
			// Serve what we fetched even if it couldn't be cached.
			self::cache( $asset, $size, $image );
			self::send_cache_headers( $is_public, $etag );
			self::send_type( $image['type'] );
			header( 'Content-Length: ' . strlen( $image['body'] ) );
			echo $image['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		self::send_file( $is_public, $etag, $cache, self::cached_type( $cache ) );
	}

	/**
	 * A photo's ETag at a size. An Immich thumbnail doesn't change; an upload's copies
	 * do when it's edited, so its version is part of the tag.
	 */
	public static function etag( string $asset, string $size, int $upload = 0 ): string {
		$version = $upload ? (string) get_post_meta( $upload, Uploads::META_VERSION, true ) : '';
		return '"' . md5( $asset . '/' . $size . ( '' !== $version ? '/' . $version : '' ) ) . '"';
	}

	private static function send_file( bool $is_public, string $etag, string $file, string $type ): void {
		self::send_cache_headers( $is_public, $etag );
		self::send_type( $type );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// Only for a photo actually served, so errors don't land in shared caches.
	private static function send_cache_headers( bool $is_public, string $etag ): void {
		header( 'Cache-Control: ' . self::photo_cache_control( $is_public ) );
		header( 'ETag: ' . $etag );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * A photo's image at a size: an upload's from WordPress's copies, else Immich's.
	 * Every synced photo that isn't an upload came from Immich, even on a trip that has
	 * since switched to uploads and not synced yet.
	 *
	 * @return array{body: string, type: string}|array{file: string, type: string}|\WP_Error
	 */
	public static function image( int $trip_id, string $asset, string $size, int $upload ) {
		if ( $upload ) {
			return Uploads::image( $upload, $size );
		}
		$source = Trips::immich( $trip_id );
		return 'medium' === $size ? self::medium( $source, $asset ) : $source->image( $asset, $size );
	}

	/**
	 * Medium size, made from the preview, which is cached on the way so the lightbox
	 * can use it.
	 *
	 * @return array{body: string, type: string}|\WP_Error
	 */
	private static function medium( ImmichSource $source, string $asset ) {
		$preview = self::cache_file( $asset, 'preview' );
		if ( is_readable( $preview ) ) {
			$image = [ 'body' => (string) file_get_contents( $preview ), 'type' => self::cached_type( $preview ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
		try {
			file_put_contents( $in, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return self::shrink_file( $in, self::MEDIUM_SHORT_PX, $type );
		} finally {
			wp_delete_file( $in );
		}
	}

	/**
	 * An image file scaled down to $short px on its short side, never up, in $type or
	 * else the type the image editor saves this one as.
	 *
	 * @return array{body: string, type: string}|\WP_Error
	 */
	public static function shrink_file( string $file, int $short, ?string $type = null ) {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		// A JPEG whose EXIF says it's turned (an original), as core turns its copies: the
		// copy keeps no orientation tag to turn it by.
		$editor->maybe_exif_rotate();
		$size = $editor->get_size();
		if ( min( $size['width'], $size['height'] ) > $short ) {
			$resized = $size['width'] >= $size['height'] ? $editor->resize( null, $short ) : $editor->resize( $short, null );
			if ( is_wp_error( $resized ) ) {
				return $resized;
			}
		}
		$editor->set_quality( 82 );
		// The editor may change the extension (a HEIC is saved as JPEG).
		$saved = $editor->save( get_temp_dir() . 'ts-photo-' . wp_generate_password( 12, false ) . '.' . pathinfo( $file, PATHINFO_EXTENSION ), $type );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		try {
			return [ 'body' => (string) file_get_contents( $saved['path'] ), 'type' => $saved['mime-type'] ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} finally {
			wp_delete_file( $saved['path'] );
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
	 * The type stored beside a cached image by store_cached().
	 */
	private static function cached_type( string $file ): string {
		return is_readable( $file . '.type' ) ? trim( (string) file_get_contents( $file . '.type' ) ) : 'image/jpeg'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
