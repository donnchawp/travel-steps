<?php
/**
 * The daily sweep of the photo cache: it deletes cached photos no trip or upload uses,
 * and, at an age an administrator sets, those older than that. Required at the end of
 * smoke.php, in its scope: uses check(), $alice, $base and $cache_path.
 */
use TravelSteps\{CacheSweep, RestController, Trips};

$cs_id = fn( int $n ) => sprintf( '5eeb0000-0000-4000-8000-%012d', $n );
// Cache a photo at a size, its files last written $days ago.
$cs_cache = function ( string $asset, string $size = 'preview', float $days = 0 ) use ( $cache_path ) {
	$file = $cache_path( $asset, $size );
	RestController::store_cached( $file, 'IMAGE', 'image/jpeg' );
	$at = (int) ( time() - $days * DAY_IN_SECONDS );
	touch( $file, $at );
	touch( "$file.type", $at );
	return $file;
};
// Whether a photo's cached file and its type are both there (true) or both gone (false); null for one without the other.
$cs_kept = function ( string $file ) {
	$there = [ file_exists( $file ), file_exists( "$file.type" ) ];
	return $there[0] === $there[1] ? $there[0] : null;
};

$cs_unused_old = $cs_cache( $cs_id( 1 ), 'preview', 2 );
$cs_unused_new = $cs_cache( $cs_id( 2 ), 'thumbnail', 0.5 );
CacheSweep::run();
check( 'cache sweep: a photo no trip or upload uses, cached more than a day ago, is deleted with its type', false === $cs_kept( $cs_unused_old ) );
check( '…one cached less than a day ago is kept', true === $cs_kept( $cs_unused_new ) );

// Photos in use: in a trip's photos, whatever its status, or uploaded and not synced yet.
$cs_trip = function ( string $status, array $assets ) use ( $alice ) {
	$trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Swept', 'post_status' => $status, 'post_author' => $alice ] );
	update_post_meta( $trip, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_map( fn( $id ) => [ 'id' => $id, 't' => 0 ], $assets ) ) ) );
	return $trip;
};
$cs_binned  = $cs_trip( 'trash', [ $cs_id( 3 ) ] );
$cs_upload  = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => $cs_binned, 'post_title' => 'upload' ] );
update_post_meta( $cs_upload, TravelSteps\Uploads::META_PHOTO_ID, $cs_id( 4 ) );
$cs_shared  = [ $cs_trip( 'publish', [ $cs_id( 5 ), $cs_id( 6 ) ] ), $cs_trip( 'draft', [ $cs_id( 5 ) ] ) ];
wp_delete_post( $cs_shared[1], true );
$cs_in_use  = [
	'a binned trip uses'                            => $cs_cache( $cs_id( 3 ), 'medium', 2 ),
	'only an unsynced upload uses'                  => $cs_cache( $cs_id( 4 ), 'thumbnail', 2 ),
	'a trip uses after another using it is deleted' => $cs_cache( $cs_id( 5 ), 'preview', 2 ),
];
CacheSweep::run();
foreach ( $cs_in_use as $why => $file ) {
	check( "cache sweep: keeps a photo $why", true === $cs_kept( $file ) );
}
