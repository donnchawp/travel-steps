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
	// The cache layout a site has: 2 names photos after a keyed hash of their id, not the id.
	const CACHE_LAYOUT         = 'travel_steps_cache_layout';
	const CACHE_LAYOUT_VERSION = 2;
	// An Immich asset id, or the photo id of an upload: a UUID.
	const ASSET_ID = '[a-f0-9-]{36}';

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
						'pattern'  => '^' . self::ASSET_ID . '$',
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
						'pattern'  => '^(' . self::ASSET_ID . ')?$',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/trips/(?P<id>\d+)/photo/(?P<asset>' . self::ASSET_ID . ')',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'moved_photo' ],
				// It only says where the photo is now, which serve_photo() checks.
				'permission_callback' => '__return_true',
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
	 * A photo's URL, the same for everyone: serve_photo() works out who's asking.
	 */
	public static function photo_url( int $trip_id, string $asset_id, string $size ): string {
		return App::url( 'photo/' . $trip_id . '/' . $asset_id . '/' . $size );
	}

	/**
	 * Serve a request for photo_url() as soon as WordPress has parsed it, before the main
	 * query, template_redirect and the app's router, which a photo needs none of.
	 */
	public static function maybe_serve_photo( \WP $wp ): void {
		$photo = self::photo_request( $wp->request );
		if ( $photo ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with the trip's share code.
			self::serve_photo( $photo[0], $photo[1], $photo[2], wp_unslash( $_GET[ Trips::SHARE_ARG ] ?? '' ) );
		}
	}

	/**
	 * The trip, asset and size a request path (relative to home, as WP::$request has it)
	 * asks for, if it's photo_url()'s; photo_answer() checks them.
	 *
	 * @return array{0: int, 1: string, 2: string}|null
	 */
	public static function photo_request( string $path ): ?array {
		return preg_match( '#^' . App::URL_PATH . '/photo/(\d+)/([^/]+)/([^/]+)$#', trim( $path, '/' ), $m ) ? [ (int) $m[1], $m[2], $m[3] ] : null;
	}

	/**
	 * Photos were served through REST before, at URLs that may still be in posts and
	 * caches: send those to where photos are now.
	 */
	public function moved_photo( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			null,
			301,
			[
				'Location'      => self::photo_url( (int) $request['id'], (string) $request['asset'], (string) $request['size'] ),
				'Cache-Control' => 'public, max-age=3600',
			]
		);
	}

	public function get_trip( \WP_REST_Request $request ): \WP_REST_Response {
		$trip     = get_post( (int) $request['id'] );
		$can_edit = current_user_can( 'edit_post', $trip->ID );
		$route    = (array) json_decode( (string) get_post_meta( $trip->ID, Trips::META_ROUTE, true ), true );
		$steps    = Trips::timeline_steps( $trip, $can_edit );
		$by_step  = Trips::photos_by_step( $trip->ID, $steps );

		$boxes   = Trips::boxes( $trip->ID );
		$markers = [];
		$areas   = [];
		$legs    = [];
		foreach ( $steps as $index => $step ) {
			// Same label as the timeline: the day number.
			$number  = Trips::day_of_trip( $trip->ID, $step ) ?: $index + 1;
			$groups  = $by_step[ $step->ID ] ?? [ 'shown' => [], 'rest' => [] ];
			$photos  = array_merge( $groups['shown'], $groups['rest'] );
			usort( $photos, fn( $a, $b ) => $a['t'] <=> $b['t'] );
			$markers = array_merge( $markers, View::markers( $step, $photos, $number ) );
			$areas   = array_merge( $areas, View::areas( $step, $photos, $boxes ) );
			$on_day  = View::legs( $step, $photos, $route );
			if ( $on_day ) {
				$legs[ 'step-' . $step->ID ] = $on_day;
			}
		}

		$response = new \WP_REST_Response(
			[
				'id'       => $trip->ID,
				// Positions only: the times stay on the server.
				'route'    => array_map( fn( $segment ) => array_map( fn( $p ) => [ $p[0], $p[1] ], $segment ), $route ),
				'markers'  => $markers,
				'areas'    => (object) $areas,
				'legs'     => (object) $legs,
				'can_edit' => $can_edit,
			]
		);
		$response->header( 'Cache-Control', Trips::shared_cacheable( $trip ) ? 'public, max-age=300' : 'private, no-store' );
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
		CacheFill::schedule( $trip_id );
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
		CacheFill::schedule( $trip_id );
		return new \WP_REST_Response(
			[
				'asset' => Trips::cover( $trip_id ),
				'url'   => View::cover_url( $trip_id ),
			]
		);
	}

	/**
	 * Who may fetch a photo through a trip: "public" for one anyone may see, fetched by
	 * someone who can't edit the trip (see Trips::shared_cacheable()); "private" for one
	 * only some may see, or fetched by anyone who can edit the trip (for
	 * those a protected trip lets in, its shown photos; for editors, any synced photo;
	 * for the trip's managers, any photo uploaded to it, synced or not); "" if they may
	 * not. Whether shared caches may keep it is photo_cache()'s call. A protected trip's
	 * share code lets in its cover, privately, for a link preview, which has no cookie.
	 *
	 * @param mixed $share The share code the request carries, if any.
	 */
	public static function access( int $trip_id, string $asset, $share = '' ): string {
		$trip = get_post( $trip_id );
		if ( ! $trip ) {
			return '';
		}
		if ( Trips::can_view( $trip ) && isset( Trips::shown_photo_ids( $trip )[ $asset ] ) ) {
			// Only a chosen cover can be hidden and shown.
			return Trips::shared_cacheable( $trip ) && ! in_array( $asset, Trips::hidden( $trip_id ), true ) ? 'public' : 'private';
		}
		if ( ! current_user_can( 'edit_post', $trip_id ) ) {
			return Trips::share_matches( $trip, $share ) && View::cover_id( $trip_id ) === $asset ? 'private' : '';
		}
		$synced = in_array( $asset, array_column( Trips::photos( $trip_id ), 'id' ), true );
		return $synced || ( Trips::can_edit_trip( $trip_id ) && Uploads::attachment( $trip_id, $asset ) ) ? 'private' : '';
	}

	/**
	 * Send a photo from the trip's source, or from the copies WordPress made of one
	 * uploaded to it, and exit. Only assets that belong to the trip are served, so the
	 * source (an Immich server and its API key) never has to be reachable from outside.
	 *
	 * Not through REST: REST takes anyone without a nonce for a visitor, and a nonce in an
	 * <img> URL would let anyone the URL reached, copied from the media modal say, act as
	 * them all over REST. Outside it, the login cookie, core's password cookie and share
	 * links' cookies are read as on any page.
	 */
	public static function serve_photo( int $trip_id, string $asset, string $size, $share = '' ): void {
		$answer = self::photo_answer( $trip_id, $asset, $size, sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ), $share );
		status_header( $answer['status'] );
		header( 'Cache-Control: ' . $answer['cache'] );
		header( 'X-Content-Type-Options: nosniff' );
		// Another site's page can't load one only some may see, so whether it loads tells it nothing.
		if ( 0 !== strpos( $answer['cache'], 'public' ) ) {
			header( 'Cross-Origin-Resource-Policy: same-site' );
		}
		if ( isset( $answer['etag'] ) ) {
			header( 'ETag: ' . $answer['etag'] );
		}
		$image = $answer['image'] ?? null;
		if ( $image ) {
			self::send_type( $image['type'] );
			if ( isset( $image['handle'] ) ) {
				header( 'Content-Length: ' . fstat( $image['handle'] )['size'] );
				fpassthru( $image['handle'] );
			} else {
				header( 'Content-Length: ' . strlen( $image['body'] ) );
				echo $image['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		exit;
	}

	/**
	 * What serve_photo() answers: its status and Cache-Control, and for a photo, its ETag
	 * and, unless it's a 304, the image (its bytes, or a copy WordPress made of an upload,
	 * opened). Every refusal is the same 404, kept out of caches, so it tells another site
	 * nothing about who's asking.
	 *
	 * @param mixed $share The share code the request carries, if any: see access().
	 * @return array{status: int, cache: string, etag?: string, image?: array{body: string, type: string}|array{handle: resource, type: string}}
	 */
	public static function photo_answer( int $trip_id, string $asset, string $size, string $if_none_match, $share = '' ): array {
		$valid  = 1 === preg_match( '/^' . self::ASSET_ID . '$/', $asset ) && in_array( $size, self::SIZES, true );
		$access = $valid ? self::access( $trip_id, $asset, $share ) : '';
		if ( '' === $access ) {
			return [
				'status' => 404,
				'cache'  => 'no-store',
			];
		}
		$cache  = self::photo_cache( $access );
		$upload = Uploads::attachment( $trip_id, $asset );
		$etag   = self::etag( $asset, $size, $upload );
		// Revalidation runs the access check above every time, but costs no image bytes.
		if ( self::not_modified( $etag, $if_none_match ) ) {
			return [
				'status' => 304,
				'cache'  => $cache,
				'etag'   => $etag,
			];
		}
		$image       = self::fetch( $trip_id, $asset, $size, $upload );
		$unavailable = [
			'status' => 502,
			'cache'  => 'no-store',
		];
		if ( is_wp_error( $image ) ) {
			return $unavailable;
		}
		if ( isset( $image['file'] ) ) {
			// Opened before any header is sent: a file deleted after that is still read whole.
			$handle = @fopen( $image['file'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle ) {
				return $unavailable;
			}
			$image = [
				'handle' => $handle,
				'type'   => $image['type'],
			];
		}
		return [
			'status' => 200,
			'cache'  => $cache,
			'etag'   => $etag,
			'image'  => $image,
		];
	}

	/**
	 * The Cache-Control a photo and its 304 are sent with: shared caches may keep it only
	 * if access() says "public".
	 *
	 * Unpublishing a step or hiding a photo has to reach shared caches (CDNs, proxies)
	 * soon, so they keep a public photo 5 minutes; browsers, which already have it, an
	 * hour.
	 *
	 * @param string $access As access() gives it, for a photo the current user may fetch.
	 */
	public static function photo_cache( string $access ): string {
		return 'public' === $access ? 'public, max-age=3600, s-maxage=300' : 'private, max-age=3600';
	}

	/**
	 * A photo at a size, as the proxy sends it: its cached copy, else fetched from its
	 * source, an Immich photo being cached on the way. Checks no access: photo_answer() does.
	 * Only a copy WordPress made of an upload comes back as a file.
	 *
	 * @return array{body: string, type: string}|array{file: string, type: string}|\WP_Error
	 */
	public static function fetch( int $trip_id, string $asset, string $size, int $upload = 0 ) {
		$cached = self::read_cached( self::cache_file( $asset, $size ) );
		if ( $cached ) {
			return $cached;
		}
		$image = self::image( $trip_id, $asset, $size, $upload );
		if ( is_wp_error( $image ) || '' === ( $image['body'] ?? $image['file'] ) ) {
			return self::unavailable();
		}
		// A copy WordPress made of an upload is served as it is, not cached again. What
		// was fetched from Immich is served even if it couldn't be cached.
		if ( isset( $image['body'] ) ) {
			self::cache( $asset, $size, $image );
		}
		return $image;
	}

	/**
	 * A photo's ETag at a size. An Immich thumbnail doesn't change; an upload's copies
	 * do when it's edited, so its version is part of the tag.
	 */
	public static function etag( string $asset, string $size, int $upload = 0 ): string {
		$version = $upload ? (string) get_post_meta( $upload, Uploads::META_VERSION, true ) : '';
		return '"' . md5( $asset . '/' . $size . ( '' !== $version ? '/' . $version : '' ) ) . '"';
	}

	// What the proxy answers when it can't get a photo.
	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'travel_steps_unavailable', 'Unavailable', [ 'status' => 502 ] );
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
		$image = self::read_cached( self::cache_file( $asset, 'preview' ) );
		if ( ! $image ) {
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
	 * A cached photo and its type, or null if it isn't cached. The sweep, or an upload's
	 * edit, can delete it at any moment, so it's read, not checked for: one that's found,
	 * then gone (or empty) when read, is null too.
	 *
	 * The type, which store_cached() writes first and delete_cached_file() deletes last,
	 * is read first: if the photo is still there after that, the type read is its own.
	 *
	 * @return array{body: string, type: string}|null
	 */
	private static function read_cached( string $file ): ?array {
		$type = trim( (string) @file_get_contents( $file . '.type' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return '' === $body ? null : [
			'body' => $body,
			'type' => '' === $type ? 'image/jpeg' : $type,
		];
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
	 * Cached photos sit behind a deny rule (Apache), in folders that can't be listed,
	 * under a keyed hash of their id (nginx), which no visitor sees: the id is in the
	 * proxy URL. So they're meant to be reached only through the checks above.
	 */
	public static function cache_root(): string {
		return wp_get_upload_dir()['basedir'] . '/travel-steps-cache';
	}

	/**
	 * The folder under cache_root() a photo is cached in at a size, by its cache_name().
	 */
	private static function cache_folder( string $name, string $size ): string {
		return $size . '/' . substr( $name, 0, 2 );
	}

	private static function cache_name( string $asset ): string {
		return wp_hash( $asset );
	}

	/**
	 * Whether a photo is cached at a size, so fetch() would send it without its source.
	 */
	public static function cached( string $asset, string $size ): bool {
		return is_readable( self::cache_file( $asset, $size ) );
	}

	public static function cache_file( string $asset, string $size ): string {
		$name = self::cache_name( $asset );
		return self::cache_root() . '/' . self::cache_folder( $name, $size ) . '/' . $name;
	}

	/**
	 * Every folder photos are cached in, one for each size and cache_folder().
	 *
	 * @return string[]
	 */
	public static function cache_folders(): array {
		return glob( self::cache_root() . '/*/*', GLOB_ONLYDIR ) ?: [];
	}

	/**
	 * cache_file(), its folders made and protected first, ready to be written.
	 */
	private static function cache_target( string $asset, string $size ): string {
		$name = self::cache_name( $asset );
		return Uploads::protect( self::cache_root(), self::cache_folder( $name, $size ) ) . '/' . $name;
	}

	private static function cache( string $asset, string $size, array $image ): void {
		self::store_cached( self::cache_target( $asset, $size ), $image['body'], $image['type'] );
	}

	/**
	 * Move photos cached under their id (in its proxy URL, so a visitor can find them on
	 * nginx) to their hashed names, once. A photo that can't be moved is deleted instead:
	 * it's fetched from Immich again when it's next viewed. Half-written files are deleted
	 * too, and emptied folders removed.
	 */
	public static function migrate_cache(): void {
		if ( (int) get_option( self::CACHE_LAYOUT ) >= self::CACHE_LAYOUT_VERSION ) {
			return;
		}
		// So two requests can't both move the files.
		Lock::run(
			self::CACHE_LAYOUT . '_lock',
			function () {
				foreach ( self::cache_folders() as $dir ) {
					self::migrate_cache_folder( $dir );
				}
				update_option( self::CACHE_LAYOUT, self::CACHE_LAYOUT_VERSION );
			}
		);
	}

	private static function migrate_cache_folder( string $dir ): void {
		$size = basename( dirname( $dir ) );
		// Descending, so a photo's type moves before the photo, as store_cached() writes them.
		foreach ( scandir( $dir, SCANDIR_SORT_DESCENDING ) ?: [] as $name ) {
			// A photo, its type, or a temporary file left by a write that never finished.
			if ( ! preg_match( '/^(' . self::ASSET_ID . ')(\.type)?(\.[A-Za-z0-9]+\.tmp)?$/', $name, $m ) ) {
				continue;
			}
			if ( isset( $m[3] ) ) {
				wp_delete_file( "$dir/$name" );
				continue;
			}
			$to = self::cache_target( $m[1], $size ) . ( $m[2] ?? '' );
			if ( file_exists( $to ) || ! rename( "$dir/$name", $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				wp_delete_file( "$dir/$name" );
			}
		}
		Uploads::remove_empty( $dir );
	}

	/**
	 * Remove every cached size of a photo.
	 */
	public static function delete_cached( string $asset ): void {
		foreach ( self::SIZES as $size ) {
			self::delete_cached_file( self::cache_file( $asset, $size ) );
		}
	}

	/**
	 * Remove a cached photo and the type store_cached() wrote beside it.
	 */
	public static function delete_cached_file( string $file ): void {
		wp_delete_file( $file );
		wp_delete_file( $file . '.type' );
	}
}
