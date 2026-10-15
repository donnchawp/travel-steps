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
RestController::fetch( $pc_trip, $pc_photo, 'thumbnail' );
RestController::delete_cached( $pc_photo );
$pc_left = array_filter( RestController::SIZES, fn( $size ) => file_exists( $cache_path( $pc_photo, $size ) ) || file_exists( $cache_path( $pc_photo, $size ) . '.type' ) );
check( 'deleting a cached photo deletes it at every size, with its types', [] === $pc_left, $pc_left );

// Caches made before photos were named after a hash: moved once, on the first request after the update.
check( 'the cache layout is recorded when the plugin loads', RestController::CACHE_LAYOUT_VERSION === (int) get_option( RestController::CACHE_LAYOUT ), get_option( RestController::CACHE_LAYOUT ) );
$pc_old    = fn( string $asset, string $size ) => "$pc_base/$size/" . substr( $asset, 0, 2 ) . "/$asset";
$pc_store  = function ( string $file, string $body ) {
	wp_mkdir_p( dirname( $file ) );
	file_put_contents( $file, $body );
	file_put_contents( "$file.type", 'image/webp' );
};
$pc_a      = 'aa000000-0000-4000-8000-000000000001';
$pc_b      = 'bb000000-0000-4000-8000-000000000002';
$pc_taken  = 'cc000000-0000-4000-8000-000000000003';
$pc_moving = [ [ $pc_a, 'thumbnail' ], [ $pc_a, 'preview' ], [ $pc_b, 'thumbnail' ], [ $pc_b, 'preview' ] ];
foreach ( $pc_moving as [ $asset, $size ] ) {
	$pc_store( $pc_old( $asset, $size ), "old $asset $size" );
}
$pc_tmp = $pc_old( $pc_a, 'thumbnail' ) . '.Ab3dE5gH.tmp';
file_put_contents( $pc_tmp, 'half' );
// One already cached under its new name too: the new copy is kept, the old one deleted.
$pc_store( $pc_old( $pc_taken, 'preview' ), 'old copy' );
$pc_store( $cache_path( $pc_taken, 'preview' ), 'new copy' );
delete_option( RestController::CACHE_LAYOUT );
RestController::migrate_cache();

$pc_wrong = array_filter( $pc_moving, fn( $m ) => "old {$m[0]} {$m[1]}" !== @file_get_contents( $cache_path( ...$m ) ) || 'image/webp' !== @file_get_contents( $cache_path( ...$m ) . '.type' ) || file_exists( $pc_old( ...$m ) ) || file_exists( $pc_old( ...$m ) . '.type' ) );
check( 'upgrade: each cached photo and its type move to their new names', [] === $pc_wrong, [ $pc_wrong, array_keys( $pc_files() ) ] );
check( '…a half-written file is deleted', ! file_exists( $pc_tmp ) );
check( '…a photo already under its new name keeps that copy, and the old one is deleted', 'new copy' === file_get_contents( $cache_path( $pc_taken, 'preview' ) ) && ! file_exists( $pc_old( $pc_taken, 'preview' ) ) && ! file_exists( $pc_old( $pc_taken, 'preview' ) . '.type' ) );
$pc_named = array_filter( array_keys( $pc_files() ), fn( $path ) => preg_match( '/[a-f0-9]{8}-[a-f0-9]{4}-/', $path ) );
check( '…no file is left under a photo id', [] === $pc_named, $pc_named );
// An old folder stays only if a moved photo's hash starts the same way.
$pc_now_used = array_map( fn( $m ) => dirname( $cache_path( ...$m ) ), array_merge( $pc_moving, [ [ $pc_taken, 'preview' ] ] ) );
$pc_folders  = array_filter( array_map( fn( $m ) => dirname( $pc_old( ...$m ) ), array_merge( $pc_moving, [ [ $pc_taken, 'preview' ] ] ) ), fn( $dir ) => is_dir( $dir ) && ! in_array( $dir, $pc_now_used, true ) );
check( '…emptied old folders are removed', [] === $pc_folders, $pc_folders );
$pc_unlisted = array_filter( array_unique( $pc_now_used ), fn( $dir ) => ! file_exists( "$dir/index.html" ) );
check( '…and new folders can’t be listed', [] === $pc_unlisted, $pc_unlisted );
check( '…and the new layout is recorded', RestController::CACHE_LAYOUT_VERSION === (int) get_option( RestController::CACHE_LAYOUT ) );
$pc_fetched = [];
$image      = RestController::fetch( $pc_trip, $pc_a, 'preview' );
check( '…and the proxy serves the moved photos without asking Immich', [] === $pc_fetched && "old $pc_a preview" === file_get_contents( $image['file'] ?? '' ) && 'image/webp' === $image['type'], [ $pc_fetched, $image ] );

$pc_before = $pc_files();
delete_option( RestController::CACHE_LAYOUT );
RestController::migrate_cache();
check( 'upgrade: running it again changes nothing', $pc_before === $pc_files() );
// Another migration still running: this request leaves it to finish.
$pc_store( $pc_old( $pc_a, 'medium' ), 'busy' );
delete_option( RestController::CACHE_LAYOUT );
add_option( RestController::CACHE_LAYOUT . '_lock', time() );
RestController::migrate_cache();
check( 'upgrade: runs only once at a time', file_exists( $pc_old( $pc_a, 'medium' ) ) && false === get_option( RestController::CACHE_LAYOUT ) );
delete_option( RestController::CACHE_LAYOUT . '_lock' );
RestController::migrate_cache();
check( '…and the next request after it finishes runs it', ! file_exists( $pc_old( $pc_a, 'medium' ) ) && false === get_option( RestController::CACHE_LAYOUT . '_lock' ) );
// A site that never cached a photo: nothing to move.
$pc_empty   = get_temp_dir() . 'ts-no-cache-' . wp_generate_password( 6, false );
$pc_uploads = function ( $dirs ) use ( $pc_empty ) {
	$dirs['basedir'] = $pc_empty;
	return $dirs;
};
add_filter( 'upload_dir', $pc_uploads );
delete_option( RestController::CACHE_LAYOUT );
RestController::migrate_cache();
remove_filter( 'upload_dir', $pc_uploads );
check( 'upgrade: a site without a cache just records the new layout', RestController::CACHE_LAYOUT_VERSION === (int) get_option( RestController::CACHE_LAYOUT ) && ! file_exists( $pc_empty ) );

remove_filter( 'pre_http_request', $pc_immich, 10 );
