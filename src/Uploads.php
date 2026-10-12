<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos uploaded to a trip, kept private: they may be on draft steps, hidden, or
 * taken at home, so they never get a public, guessable URL.
 *
 * An upload is a trip photo when it's made from the trip's photo modal, which sends
 * the trip as post_id and the REQUEST_FLAG. Other uploads to a trip (images for its
 * description, its featured image) are ordinary media.
 *
 * Trip photos are stored in uploads/travel-steps/<trip id>/ behind a deny rule
 * (Apache). Each gets a random UUID as its photo id and another as its file name:
 * the photo id is in public proxy URLs, so on nginx, where the unguessable file
 * name is all that keeps the original private, it mustn't lead to the file.
 * They're private attachments, so core REST and attachment pages don't show them to
 * visitors, and they're left out of every media listing but their own trip's modal.
 */
class Uploads {
	const REQUEST_FLAG  = 'travel_steps_photo';
	const META_PHOTO_ID = '_ts_photo_id';
	const DIR           = 'travel-steps';

	// The photo id for the upload in progress: one file per upload request.
	private static ?string $photo_id = null;

	public static function register(): void {
		add_filter( 'wp_handle_upload_prefilter', [ self::class, 'prefilter' ] );
		add_filter( 'wp_handle_sideload_prefilter', [ self::class, 'prefilter' ] );
		add_filter( 'upload_dir', [ self::class, 'upload_dir' ] );
		add_filter( 'wp_insert_attachment_data', [ self::class, 'attachment_data' ], 10, 4 );
		add_action( 'add_attachment', [ self::class, 'add_attachment' ] );
		add_filter( 'wp_prepare_attachment_for_js', [ self::class, 'prepare_for_js' ], 10, 2 );
		add_filter( 'ajax_query_attachments_args', [ self::class, 'modal_query' ] );
		add_filter( 'rest_attachment_query', [ self::class, 'exclude' ] );
		add_action( 'pre_get_posts', [ self::class, 'library_list' ] );
		add_action( 'before_delete_post', [ self::class, 'delete_trip' ] );
		add_action( 'delete_attachment', [ self::class, 'forget_cached' ] );
	}

	/**
	 * The trip this request uploads a photo to, 0 if it isn't a trip photo upload, or
	 * null if it asks to be one but isn't allowed.
	 */
	public static function upload_trip(): ?int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The upload handlers check the nonce; this only reads which folder to use.
		if ( empty( $_REQUEST[ self::REQUEST_FLAG ] ) ) {
			return 0;
		}
		$trip_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		// phpcs:enable
		$allowed = $trip_id && Trips::TRIP === get_post_type( $trip_id )
			&& current_user_can( Trips::capability() ) && current_user_can( 'edit_post', $trip_id );
		return $allowed ? $trip_id : null;
	}

	/**
	 * Give a trip photo its id and a random file name, keeping its extension. A request
	 * that asks to be a trip photo but isn't allowed fails, rather than becoming public media.
	 */
	public static function prefilter( array $file ): array {
		$trip_id = self::upload_trip();
		if ( null === $trip_id ) {
			$file['error'] = __( 'You can’t add photos to this trip.', 'travel-steps' );
		} elseif ( $trip_id ) {
			self::$photo_id = wp_generate_uuid4();
			$ext            = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			$file['name']   = wp_generate_uuid4() . ( '' !== $ext ? '.' . $ext : '' );
		}
		return $file;
	}

	public static function upload_dir( array $dirs ): array {
		$trip_id = self::upload_trip();
		if ( ! $trip_id ) {
			return $dirs;
		}
		self::protect( $dirs['basedir'] . '/' . self::DIR );
		$dirs['subdir'] = '/' . self::DIR . '/' . $trip_id;
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		self::unlisted( $dirs['path'] );
		return $dirs;
	}

	/**
	 * Keep a folder's files from being served directly: a deny rule for Apache (it
	 * covers the folders inside too), and an index against directory listings.
	 */
	public static function protect( string $dir ): void {
		self::unlisted( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Create a folder with an empty index.html, so a server that lists directories
	 * shows nothing of it, deny rule or not; on nginx the file names are the secret.
	 * Plain HTML, as uploads often aren't allowed to run PHP.
	 */
	public static function unlisted( string $dir ): void {
		if ( ! file_exists( $dir . '/index.html' ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public static function attachment_data( array $data, array $postarr, array $unsanitized, bool $update ): array {
		if ( ! $update && self::$photo_id && self::upload_trip() ) {
			$data['post_status'] = 'private';
		}
		return $data;
	}

	public static function add_attachment( int $attachment_id ): void {
		if ( ! self::$photo_id || ! self::upload_trip() ) {
			return;
		}
		update_post_meta( $attachment_id, self::META_PHOTO_ID, self::$photo_id );
		self::$photo_id = null;
	}

	public static function photo_id( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_PHOTO_ID, true );
	}

	/**
	 * The media modal can't load a trip photo's own URLs (they're denied), so it gets
	 * the photo proxy's, for the signed-in editor.
	 */
	public static function prepare_for_js( array $response, \WP_Post $attachment ): array {
		$photo_id = self::photo_id( $attachment->ID );
		if ( '' === $photo_id ) {
			return $response;
		}
		$trip_id         = (int) $attachment->post_parent;
		$response['url'] = RestController::photo_url( $trip_id, $photo_id, 'preview', true );
		$response['link'] = '';
		foreach ( array_keys( $response['sizes'] ?? [] ) as $size ) {
			$proxy                              = in_array( $size, [ 'thumbnail', 'medium' ], true ) ? $size : 'preview';
			$response['sizes'][ $size ]['url'] = RestController::photo_url( $trip_id, $photo_id, $proxy, true );
		}
		return $response;
	}

	/**
	 * The trip's photo modal lists that trip's photos and nothing else; every other
	 * modal, and the Media Library grid, lists everything but trip photos.
	 */
	public static function modal_query( array $query ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core checks the nonce and capability before this filter runs.
		$asked   = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : [];
		// phpcs:enable
		$trip_id = absint( $asked['post_parent'] ?? 0 );
		if ( empty( $asked[ self::REQUEST_FLAG ] ) ) {
			return self::exclude( $query );
		}
		if ( ! $trip_id || Trips::TRIP !== get_post_type( $trip_id ) || ! current_user_can( 'edit_post', $trip_id ) ) {
			$query['post__in'] = [ 0 ];
			return $query;
		}
		$query['post_parent'] = $trip_id;
		$query['meta_query'] = [ [ 'key' => self::META_PHOTO_ID, 'compare' => 'EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $query;
	}

	/**
	 * Leave trip photos out of a media query.
	 */
	public static function exclude( array $query ): array {
		$query['meta_query']   = (array) ( $query['meta_query'] ?? [] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$query['meta_query'][] = [ 'key' => self::META_PHOTO_ID, 'compare' => 'NOT EXISTS' ];
		return $query;
	}

	/**
	 * The Media Library's list view, which doesn't go through the modal's query.
	 */
	public static function library_list( \WP_Query $query ): void {
		global $pagenow;
		if ( is_admin() && $query->is_main_query() && 'upload.php' === $pagenow ) {
			$query->set( 'meta_query', self::exclude( [ 'meta_query' => $query->get( 'meta_query' ) ?: [] ] )['meta_query'] );
		}
	}

	/**
	 * Deleting a trip for good deletes its photos, their sizes and cached copies.
	 * Binning it deletes nothing.
	 */
	public static function delete_trip( int $post_id ): void {
		if ( Trips::TRIP !== get_post_type( $post_id ) ) {
			return;
		}
		$photos = get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $post_id,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_PHOTO_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		foreach ( $photos as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		$dir = wp_get_upload_dir()['basedir'] . '/' . self::DIR . '/' . $post_id;
		if ( is_dir( $dir ) && [ $dir . '/index.html' ] === glob( $dir . '/*' ) ) {
			wp_delete_file( $dir . '/index.html' );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	public static function forget_cached( int $attachment_id ): void {
		$photo_id = self::photo_id( $attachment_id );
		if ( '' !== $photo_id ) {
			RestController::delete_cached( $photo_id );
		}
	}
}
