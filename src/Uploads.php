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
	const META_EXIF     = '_ts_exif'; // When, where and with what it was taken: see Exif::parse().
	const META_TAKEN    = '_ts_taken'; // A time the author gave one with none in its EXIF: Y-m-d H:i:s, wall clock.
	const META_CHECKED  = '_ts_checked'; // Copies has_gps() found none in: file name => "mtime:size".
	const META_VERSION  = '_ts_version'; // Counts new sets of copies, for the proxy's ETag.
	const DIR           = 'travel-steps';
	// What a trip photo may be: the types browsers show, and HEIC, which WordPress converts.
	const PHOTO_TYPES   = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif' ];
	// The photo proxy's sizes, as Immich makes them: at least this on the short side.
	const SHORT_PX = [
		'thumbnail' => 250,
		'medium'    => RestController::MEDIUM_SHORT_PX,
		'preview'   => 1440,
	];

	// The upload in progress (one file per upload request): its photo id, and the
	// name it was uploaded with, before prefilter() gave it a random one.
	private static ?string $photo_id = null;
	private static string $uploaded_as = '';

	public static function register(): void {
		add_filter( 'wp_handle_upload_prefilter', [ self::class, 'prefilter' ] );
		add_filter( 'wp_handle_sideload_prefilter', [ self::class, 'prefilter' ] );
		add_filter( 'upload_dir', [ self::class, 'upload_dir' ] );
		add_filter( 'wp_image_editors', [ self::class, 'image_editors' ] );
		add_filter( 'wp_insert_attachment_data', [ self::class, 'attachment_data' ], 10, 4 );
		add_action( 'add_attachment', [ self::class, 'add_attachment' ] );
		add_filter( 'wp_prepare_attachment_for_js', [ self::class, 'prepare_for_js' ], 10, 2 );
		add_filter( 'xmlrpc_prepare_media_item', [ self::class, 'xmlrpc_item' ], 10, 2 );
		add_filter( 'rest_prepare_attachment', [ self::class, 'rest_item' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_src', [ self::class, 'image_src' ], 10, 2 );
		add_filter( 'ajax_query_attachments_args', [ self::class, 'modal_query' ] );
		add_filter( 'rest_attachment_query', [ self::class, 'exclude' ] );
		add_action( 'load-upload.php', fn() => add_action( 'pre_get_posts', [ self::class, 'library_list' ] ) );
		add_action( 'load-post.php', [ self::class, 'edit_screen' ] );
		add_action( 'load-async-upload.php', [ self::class, 'fetch_form' ] );
		// Before redirect_canonical(), which would send it to the file.
		add_action( 'template_redirect', [ self::class, 'attachment_page' ], 1 );
		add_action( 'before_delete_post', [ self::class, 'delete_trip' ] );
		add_action( 'delete_attachment', [ self::class, 'forget_cached' ] );
		add_filter( 'wp_update_attachment_metadata', [ self::class, 'new_copies' ], 10, 2 );
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
		return Trips::can_edit_trip( $trip_id ) ? $trip_id : null;
	}

	/**
	 * Whether photos can be uploaded to trips on this site. Without PHP's exif extension
	 * there's no knowing when a photo was taken, so no day to put it on.
	 */
	public static function available(): bool {
		return (bool) apply_filters( 'travel_steps_can_upload', function_exists( 'exif_read_data' ) );
	}

	/**
	 * Give a trip photo its id and a random file name, keeping its extension. A request
	 * that asks to be a trip photo but isn't allowed fails, rather than becoming public media.
	 */
	public static function prefilter( array $file ): array {
		$trip_id = self::upload_trip();
		if ( null === $trip_id ) {
			$file['error'] = __( 'You can’t add photos to this trip.', 'travel-steps' );
		} elseif ( $trip_id && ! self::available() ) {
			$file['error'] = __( 'Photos can’t be added to trips on this site: its PHP has no exif extension, which reads when and where they were taken.', 'travel-steps' );
		} elseif ( $trip_id && ! in_array( wp_get_image_mime( (string) $file['tmp_name'] ), self::PHOTO_TYPES, true ) ) {
			// Read from its content, not its name: Imagick opens PDFs, videos, PSDs and more.
			$file['error'] = __( 'Only photos can be added to a trip.', 'travel-steps' );
		} elseif ( $trip_id ) {
			self::$photo_id    = wp_generate_uuid4();
			self::$uploaded_as = pathinfo( (string) $file['name'], PATHINFO_FILENAME );
			$ext               = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			$file['name']      = wp_generate_uuid4() . ( '' !== $ext ? '.' . $ext : '' );
		}
		return $file;
	}

	public static function upload_dir( array $dirs ): array {
		$trip_id = self::upload_trip();
		if ( ! $trip_id ) {
			return $dirs;
		}
		$dirs['subdir'] = self::subdir( $trip_id );
		$dirs['path']   = self::protect( $dirs['basedir'] . '/' . self::DIR, (string) $trip_id );
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	private static function subdir( int $trip_id ): string {
		return '/' . self::DIR . '/' . $trip_id;
	}

	/**
	 * Whether a file is in a trip's photo folder.
	 */
	public static function is_trip_file( string $file ): bool {
		return 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( wp_get_upload_dir()['basedir'] ) . '/' . self::DIR . '/' );
	}

	/**
	 * Use ImagickEditor wherever core's Imagick editor would be used.
	 */
	public static function image_editors( array $editors ): array {
		return array_map( fn( $editor ) => 'WP_Image_Editor_Imagick' === $editor ? ImagickEditor::class : $editor, $editors );
	}

	/**
	 * Make the folders from $root down to $root/$path, so that their files can only be
	 * reached through the plugin: a deny rule at the root for Apache (it covers the
	 * folders inside), and in every folder an empty index.html, so a server that lists
	 * directories shows nothing; on nginx the file names are the secret. Plain HTML,
	 * as uploads often aren't allowed to run PHP.
	 *
	 * @return string The innermost folder.
	 */
	public static function protect( string $root, string $path = '' ): string {
		$dir = $root;
		self::unlisted( $dir );
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		foreach ( array_filter( explode( '/', $path ) ) as $folder ) {
			$dir .= '/' . $folder;
			self::unlisted( $dir );
		}
		return $dir;
	}

	/**
	 * Remove a folder protect() made once nothing but its index.html is left in it.
	 */
	public static function remove_empty( string $dir ): void {
		$left = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( false !== $left && [] === array_diff( $left, [ '.', '..', 'index.html' ] ) ) {
			wp_delete_file( $dir . '/index.html' );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	private static function unlisted( string $dir ): void {
		if ( ! file_exists( $dir . '/index.html' ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public static function attachment_data( array $data, array $postarr, array $unsanitized, bool $update ): array {
		$trip_id = self::$photo_id ? self::upload_trip() : 0;
		if ( ! $update && $trip_id ) {
			$data['post_status'] = 'private';
			// A REST upload names its parent separately, but its file is already in this trip's folder.
			$data['post_parent'] = $trip_id;
			// Core names the attachment after its file, which must stay secret.
			$data['post_name'] = self::$photo_id;
			if ( pathinfo( (string) $data['guid'], PATHINFO_FILENAME ) === $data['post_title'] ) {
				$data['post_title'] = self::$uploaded_as;
			}
		}
		return $data;
	}

	public static function add_attachment( int $attachment_id ): void {
		if ( ! self::$photo_id || ! self::upload_trip() ) {
			return;
		}
		update_post_meta( $attachment_id, self::META_PHOTO_ID, self::$photo_id );
		self::$photo_id = null;
		// Core hasn't made any copies yet, so this is the original.
		update_post_meta( $attachment_id, self::META_EXIF, Exif::parse( Exif::read( (string) get_attached_file( $attachment_id ) ) ) );
	}

	public static function photo_id( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_PHOTO_ID, true );
	}

	/**
	 * The photo uploaded to a trip with this photo id, or 0.
	 */
	public static function attachment( int $trip_id, string $photo_id ): int {
		if ( ! $trip_id || '' === $photo_id ) {
			return 0;
		}
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $trip_id,
				'post_status'    => [ 'private', 'inherit' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_PHOTO_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $photo_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * A trip photo at one of the photo proxy's sizes, never the original: the smallest
	 * copy core made that's at least SHORT_PX on its short side, or one made at that
	 * size from it (when it's more than twice that) or from the original (when there's
	 * none), never enlarged. A copy with GPS in it is refused; each of core's is checked
	 * once, and those the proxy makes are cached.
	 *
	 * @return array{file: string, type: string}|array{body: string, type: string}|\WP_Error
	 */
	public static function image( int $attachment_id, string $size ) {
		$short = self::SHORT_PX[ $size ] ?? self::SHORT_PX['preview'];
		$fit   = null;
		foreach ( self::copies( $attachment_id ) as $copy ) {
			if ( $copy['short'] >= $short && ( ! $fit || $copy['short'] < $fit['short'] ) ) {
				$fit = $copy;
			}
		}
		$gps = new \WP_Error( 'travel_steps_photo_gps', 'A copy of the photo has its location in it.' );
		if ( $fit && $fit['short'] <= 2 * $short ) {
			$image   = [
				'file' => $fit['file'],
				'type' => $fit['type'],
			];
			$checked = get_post_meta( $attachment_id, self::META_CHECKED, true ) ?: [];
			$name    = wp_basename( $fit['file'] );
			// Rewritten without core knowing (regenerated with the plugin off, optimised), it's checked again.
			$stamp = filemtime( $fit['file'] ) . ':' . filesize( $fit['file'] );
			if ( ( $checked[ $name ] ?? '' ) !== $stamp ) {
				if ( self::has_gps( $image ) ) {
					return $gps;
				}
				$checked[ $name ] = $stamp;
				update_post_meta( $attachment_id, self::META_CHECKED, $checked );
			}
			return $image;
		}
		$from  = $fit['file'] ?? (string) wp_get_original_image_path( $attachment_id );
		$image = is_readable( $from ) ? RestController::shrink_file( $from, $short ) : new \WP_Error( 'travel_steps_no_file', 'The photo’s file is missing.' );
		return ! is_wp_error( $image ) && self::has_gps( $image ) ? $gps : $image;
	}

	/**
	 * New sizes for a trip photo (made, regenerated, or the photo edited) replace the
	 * copies the proxy made and need checking again.
	 *
	 * @param array|false $data
	 * @return array|false
	 */
	public static function new_copies( $data, $attachment_id ) {
		if ( '' !== self::photo_id( (int) $attachment_id ) ) {
			self::forget_cached( (int) $attachment_id );
			delete_post_meta( (int) $attachment_id, self::META_CHECKED );
			update_post_meta( (int) $attachment_id, self::META_VERSION, 1 + (int) get_post_meta( (int) $attachment_id, self::META_VERSION, true ) );
		}
		return $data;
	}

	/**
	 * The copies core made of a trip photo that show all of it: its sizes but cropped
	 * ones, and its own file when core kept the original aside ("-scaled", "-rotated").
	 *
	 * @return array<array{file: string, type: string, short: int}>
	 */
	private static function copies( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$file = (string) get_attached_file( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return [];
		}
		$sizes = $meta['sizes'] ?? [];
		if ( ! empty( $meta['original_image'] ) ) {
			$sizes[] = [
				'file'      => wp_basename( $file ),
				'mime-type' => wp_check_filetype( $file )['type'],
			] + $meta;
		}
		$copies = [];
		foreach ( $sizes as $copy ) {
			$path = dirname( $file ) . '/' . $copy['file'];
			if ( wp_image_matches_ratio( $meta['width'], $meta['height'], $copy['width'], $copy['height'] ) && is_readable( $path ) ) {
				$copies[] = [
					'file'  => $path,
					'type'  => (string) $copy['mime-type'],
					'short' => (int) min( $copy['width'], $copy['height'] ),
				];
			}
		}
		return $copies;
	}

	/**
	 * Whether a copy of a photo may have its location in it: GPS in XMP, GPS that
	 * exif_read_data() finds, or EXIF it can't read. The copies trip photos get have no
	 * EXIF or XMP at all, so this only turns away what another image editor made.
	 */
	public static function has_gps( array $image ): bool {
		$bytes = $image['body'] ?? (string) file_get_contents( $image['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// XMP spells it out (exif:GPSLatitude) where it's plain text, as in JPEG, WebP and HEIF.
		if ( false !== strpos( $bytes, 'GPSLatitude' ) ) {
			return true;
		}
		$exif = false;
		if ( function_exists( 'exif_read_data' ) ) {
			$stream = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $stream, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			rewind( $stream );
			$exif = @exif_read_data( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warns on any malformed tag.
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		// It reads a file with corrupt EXIF, or none, as one without the EXIF sections.
		if ( is_array( $exif ) && false !== strpos( (string) ( $exif['SectionsFound'] ?? '' ), 'IFD0' ) ) {
			return (bool) preg_grep( '/^GPS/', array_keys( $exif ) );
		}
		// EXIF it couldn't read (in JPEG, HEIC and AVIF, after "Exif\0\0"; in WebP and PNG, a chunk).
		return false !== strpos( $bytes, "Exif\0\0" )
			|| ( 0 === strpos( $bytes, 'RIFF' ) && false !== strpos( $bytes, 'EXIF' ) )
			|| ( 0 === strpos( $bytes, "\x89PNG" ) && false !== strpos( $bytes, 'eXIf' ) );
	}

	/**
	 * The media modal can't load a trip photo's own URLs (they're denied), so it gets
	 * the photo proxy's, for the signed-in editor. Nothing in it names the file: on
	 * nginx, its URL would be a lasting link to the untouched original.
	 */
	public static function prepare_for_js( array $response, \WP_Post $attachment ): array {
		$photo_id = self::photo_id( $attachment->ID );
		if ( '' === $photo_id ) {
			return $response;
		}
		$trip_id         = (int) $attachment->post_parent;
		$response['url'] = RestController::photo_url( $trip_id, $photo_id, 'preview' );
		$response['link'] = '';
		$response['filename'] = '';
		unset( $response['originalImageURL'], $response['originalImageName'] );
		// Core's attachment screen, where the Edit Image and edit links lead, prints the
		// file's URL and name: a trip photo gets neither link.
		$response['editLink'] = '';
		unset( $response['nonces']['update'], $response['nonces']['edit'] );
		foreach ( array_keys( $response['sizes'] ?? [] ) as $size ) {
			$proxy                              = in_array( $size, RestController::SIZES, true ) ? $size : 'preview';
			$response['sizes'][ $size ]['url'] = RestController::photo_url( $trip_id, $photo_id, $proxy );
		}
		return $response;
	}

	/**
	 * Core's attachment screen prints a file's URL and name. For a trip photo, send its
	 * editors to the trip instead.
	 */
	public static function edit_screen(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which post the screen is for.
		// Core refuses anyone who can't edit it; they aren't told where its trip is.
		if ( ! $post_id || '' === self::photo_id( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$trip = get_post( (int) get_post_field( 'post_parent', $post_id ) );
		wp_safe_redirect( $trip ? Trips::url( $trip, 'edit' ) : admin_url() );
		exit;
	}

	/**
	 * The old uploader's detail form (async-upload.php?fetch=) prints a file's name and
	 * URL to anyone who can upload. Not for a trip photo.
	 */
	public static function fetch_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core checks nothing more for this form; this only refuses it.
		$attachment_id = isset( $_REQUEST['attachment_id'] ) ? absint( $_REQUEST['attachment_id'] ) : 0;
		if ( $attachment_id && ! empty( $_REQUEST['fetch'] ) && '' !== self::photo_id( $attachment_id ) ) {
			wp_die( esc_html__( 'Trip photos are managed on their trip’s page.', 'travel-steps' ), 403 );
		}
		// phpcs:enable
	}

	/**
	 * A trip photo's attachment page would redirect to its file, or show it: it gets a
	 * 404 instead, whoever is asking.
	 */
	public static function attachment_page(): void {
		global $wp_query;
		if ( is_attachment() && '' !== self::photo_id( (int) get_queried_object_id() ) ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * XML-RPC's wp.getMediaItem gives any author any attachment, with its URL and file
	 * names; for a trip photo, leave those out.
	 */
	public static function xmlrpc_item( array $item, \WP_Post $attachment ): array {
		if ( '' !== self::photo_id( $attachment->ID ) ) {
			$item['link']      = '';
			$item['thumbnail'] = '';
			$item['metadata']  = [];
		}
		return $item;
	}

	/**
	 * Core renders any image for anyone who can edit some post: as a featured image
	 * (set_post_thumbnail() checks only that it renders) and in its answer, with the
	 * file's URLs. A trip photo renders as nothing, so it can't be one. The modal is
	 * left alone: it reads image_downsize(), and prepare_for_js() replaces its URLs.
	 *
	 * @param array|false $image
	 * @return array|false
	 */
	public static function image_src( $image, $attachment_id ) {
		return '' !== self::photo_id( (int) $attachment_id ) ? false : $image;
	}

	/**
	 * Core REST gives anyone who can edit a trip photo its file URL and names. Leave
	 * those out, as for the modal: on nginx they'd lead to the untouched original.
	 */
	public static function rest_item( \WP_REST_Response $response, \WP_Post $attachment ): \WP_REST_Response {
		if ( '' === self::photo_id( $attachment->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		unset( $data['source_url'], $data['media_details'], $data['missing_image_sizes'], $data['guid'], $data['filename'] );
		$data['link'] = '';
		if ( isset( $data['description']['rendered'] ) ) {
			$data['description']['rendered'] = '';
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * The trip's photo modal lists that trip's photos and nothing else; every other
	 * modal, and the Media Library grid, lists everything but trip photos.
	 */
	public static function modal_query( array $query ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core checks the nonce and capability before this filter runs.
		$asked   = ! empty( $_REQUEST['query'][ self::REQUEST_FLAG ] );
		$trip_id = absint( $_REQUEST['query']['post_parent'] ?? 0 );
		// phpcs:enable
		if ( ! $asked ) {
			return self::exclude( $query );
		}
		if ( ! Trips::can_edit_trip( $trip_id ) ) {
			$query['post__in'] = [ 0 ];
			return $query;
		}
		$query['post_parent'] = $trip_id;
		// Trip photos are private; core lists those only for users who can read others' private posts.
		$query['post_status'] = 'inherit,private';
		$query['meta_query']  = [ [ 'key' => self::META_PHOTO_ID, 'compare' => 'EXISTS' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $query;
	}

	/**
	 * Leave trip photos out of a media query.
	 */
	public static function exclude( array $query ): array {
		$query['meta_query']   = (array) ( ( $query['meta_query'] ?? [] ) ?: [] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$query['meta_query'][] = [ 'key' => self::META_PHOTO_ID, 'compare' => 'NOT EXISTS' ];
		return $query;
	}

	/**
	 * The Media Library's list view, which doesn't go through the modal's query.
	 */
	public static function library_list( \WP_Query $query ): void {
		if ( $query->is_main_query() ) {
			$query->set( 'meta_query', self::exclude( $query->query_vars )['meta_query'] );
		}
	}

	/**
	 * The attachment ids of the photos uploaded to a trip, oldest first.
	 *
	 * @param string[] $statuses
	 * @return int[]
	 */
	public static function trip_photos( int $trip_id, array $statuses = [ 'private', 'inherit' ] ): array {
		return get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $trip_id,
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::META_PHOTO_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
	}

	/**
	 * Of a trip's uploads (from trip_photos()), those with no time taken in their EXIF,
	 * and the time their author gave each ('' if none).
	 *
	 * @param int[] $ids
	 * @return array<int, string> Attachment id => Y-m-d H:i:s or ''.
	 */
	public static function untimed( array $ids ): array {
		update_meta_cache( 'post', $ids );
		$taken = [];
		foreach ( $ids as $id ) {
			if ( '' === ( get_post_meta( $id, self::META_EXIF, true )['taken'] ?? '' ) ) {
				$taken[ $id ] = (string) get_post_meta( $id, self::META_TAKEN, true );
			}
		}
		return $taken;
	}

	/**
	 * Keep the times the author gave the trip's uploads with none in their EXIF. A date
	 * and a time keep one, both empty forget it, and anything else is left alone: a
	 * date filled in from the file name with no time shouldn't stop the trip saving.
	 *
	 * @param array $taken From the trip form: photo id => [ 'date' => Y-m-d, 'time' => H:i ].
	 */
	public static function save_times( int $trip_id, array $taken ): void {
		foreach ( self::untimed( self::trip_photos( $trip_id ) ) as $attachment_id => $stored ) {
			$given = $taken[ self::photo_id( $attachment_id ) ] ?? null;
			if ( ! is_array( $given ) ) {
				continue;
			}
			[ $date, $time ] = array_map( fn( $key ) => is_string( $given[ $key ] ?? null ) ? trim( $given[ $key ] ) : '', [ 'date', 'time' ] );
			if ( '' === $date && '' === $time ) {
				if ( '' !== $stored ) {
					delete_post_meta( $attachment_id, self::META_TAKEN );
				}
				continue;
			}
			// Exactly as typed: rolled-over dates and times (30 February, 25:00) aren't any.
			$at = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', "$date $time" );
			if ( $at && $at->format( 'Y-m-d H:i' ) === "$date $time" ) {
				update_post_meta( $attachment_id, self::META_TAKEN, $at->format( 'Y-m-d H:i:s' ) );
			}
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
		// Binned too, if media goes to the bin (MEDIA_TRASH).
		foreach ( self::trip_photos( $post_id, [ 'private', 'inherit', 'trash' ] ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		self::remove_empty( wp_get_upload_dir()['basedir'] . self::subdir( $post_id ) );
	}

	public static function forget_cached( int $attachment_id ): void {
		$photo_id = self::photo_id( $attachment_id );
		if ( '' !== $photo_id ) {
			RestController::delete_cached( $photo_id );
		}
	}
}
