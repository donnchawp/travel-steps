<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestController {
	const NS = 'travel-steps/v1';

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
			'/trips/(?P<id>\d+)/photo/(?P<asset>[a-f0-9-]{36})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'serve_photo' ],
				'permission_callback' => [ $this, 'can_view_trip' ],
				'args'                => [
					'size' => [
						'type'    => 'string',
						'enum'    => ImmichClient::SIZES,
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
	 * Stream an Immich thumbnail. Only assets that belong to the trip are served, so
	 * the Immich server and its API key never have to be reachable from outside.
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
		$cache     = self::cache_file( $asset, $size );
		if ( ! is_readable( $cache['file'] ) ) {
			$image = ( new ImmichClient() )->thumbnail( $asset, $size );
			if ( is_wp_error( $image ) ) {
				return new \WP_Error( 'travel_steps_unavailable', 'Unavailable', [ 'status' => 502 ] );
			}
			wp_mkdir_p( dirname( $cache['file'] ) );
			file_put_contents( $cache['file'], $image['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $cache['file'] . '.type', $image['type'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$type = is_readable( $cache['file'] . '.type' ) ? trim( (string) file_get_contents( $cache['file'] . '.type' ) ) : 'image/jpeg'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		header( 'Content-Type: ' . ( preg_match( '#^image/[a-z0-9.+-]+$#', $type ) ? $type : 'image/jpeg' ) );
		header( 'Content-Length: ' . filesize( $cache['file'] ) );
		header( 'Cache-Control: ' . ( $is_public ? 'public, max-age=604800, immutable' : 'private, max-age=3600' ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $cache['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Cached thumbnails sit behind a deny rule (Apache) and unguessable asset ids, so
	 * they're meant to be reached only through the checks above.
	 */
	private static function cache_file( string $asset, string $size ): array {
		$uploads = wp_upload_dir();
		$root    = trailingslashit( $uploads['basedir'] ) . 'travel-steps-cache';
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			wp_mkdir_p( $root );
			file_put_contents( $root . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return [ 'file' => $root . '/' . $size . '/' . substr( $asset, 0, 2 ) . '/' . $asset ];
	}
}
