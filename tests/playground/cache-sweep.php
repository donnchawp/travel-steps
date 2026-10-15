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
