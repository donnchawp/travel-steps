<?php
/**
 * The photo cache: cached Immich photos are named after a keyed hash of their id, never
 * the id in their proxy URL, and caches made before that are moved once. Required at the
 * end of smoke.php, in its scope: uses check(), $alice, $jpeg and $cache_path.
 */
use TravelSteps\RestController;
use TravelSteps\Trips;

$pc_base = wp_get_upload_dir()['basedir'] . '/travel-steps-cache';
// Every file under the cache, by path from its top.
$pc_files = function () use ( &$pc_base ) {
	if ( ! is_dir( $pc_base ) ) {
		return [];
	}
	$files = [];
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pc_base, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$files[ substr( $file->getPathname(), strlen( $pc_base ) + 1 ) ] = file_get_contents( $file->getPathname() );
	}
	ksort( $files );
	return $files;
};
// Every request to Immich for a photo.
$pc_fetched = [];
$pc_immich  = function ( $pre, $args, $url ) use ( &$pc_fetched, $jpeg ) {
	$pc_fetched[] = $url;
	return [ 'headers' => [ 'content-type' => 'image/jpeg' ], 'body' => $jpeg( 1440, 1080 ), 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $pc_immich, 10, 3 );

wp_set_current_user( 1 );
$pc_trip  = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Cached', 'post_status' => 'publish', 'post_author' => $alice ] );
$pc_photo = '0cac4e00-0000-4000-8000-000000000001';

// Serving a photo caches it under a name no visitor can see.
foreach ( [ 'preview', 'medium' ] as $size ) {
	$image = RestController::fetch( $pc_trip, $pc_photo, $size );
	check( "an Immich photo is served at $size", ! is_wp_error( $image ), is_wp_error( $image ) ? $image->get_error_message() : '' );
}
$pc_named = array_filter( array_keys( $pc_files() ), fn( $path ) => false !== strpos( $path, $pc_photo ) );
check( 'no cached file has the photo’s id in its path', [] === $pc_named, $pc_named );
check( '…they’re under its keyed hash, at both sizes, with their types', file_exists( $cache_path( $pc_photo, 'preview' ) ) && file_exists( $cache_path( $pc_photo, 'medium' ) ) && 'image/jpeg' === file_get_contents( $cache_path( $pc_photo, 'medium' ) . '.type' ), array_keys( $pc_files() ) );
$pc_fetched = [];
$image      = RestController::fetch( $pc_trip, $pc_photo, 'medium' );
check( 'a photo cached once is served from the cache, without asking Immich', [] === $pc_fetched && ( $image['file'] ?? '' ) === $cache_path( $pc_photo, 'medium' ), [ $pc_fetched, $image ] );

remove_filter( 'pre_http_request', $pc_immich, 10 );
