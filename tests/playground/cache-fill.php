<?php
/**
 * Filling the photo cache in the background after a trip syncs, so a published trip keeps
 * working without Immich. Required at the end of smoke.php, in its scope: uses check(),
 * row(), FakeDawarich, FakeSource, $alice (who has an Immich key), $jpeg and $cache_path.
 */
use TravelSteps\{CacheFill, RestController, TripSync, Trips};

// Every request to Immich for a photo, as "<asset> <size>"; the assets in $cf_failing fail.
$cf_fetched = [];
$cf_failing = [];
$cf_immich  = function ( $pre, $args, $url ) use ( &$cf_fetched, &$cf_failing, $jpeg ) {
	preg_match( '#/api/assets/([^/]+)/thumbnail\?size=(\w+)#', $url, $m );
	$cf_fetched[] = ( $m[1] ?? '' ) . ' ' . ( $m[2] ?? '' );
	$code         = in_array( $m[1] ?? '', $cf_failing, true ) ? 500 : 200;
	return [ 'headers' => [ 'content-type' => 'image/jpeg' ], 'body' => $jpeg( 1440, 1080 ), 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $cf_immich, 10, 3 );
$cf_cached = fn( string $asset ) => file_exists( $cache_path( $asset, 'preview' ) );
$cf_id     = fn( int $n ) => row( $n, '2025-07-01', '09:00', 0, 0, '', false )['id'];

wp_set_current_user( $alice );
$cf_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Cache me', 'post_status' => 'publish', 'post_author' => $alice ] );
update_post_meta( $cf_trip, Trips::META_START, '2025-07-01' );
update_post_meta( $cf_trip, Trips::META_END, '2025-07-03' );
$cf_source       = new FakeSource();
$cf_source->rows = [
	row( 1801, '2025-07-01', '09:00', 38.71, -9.13, 'Lisbon', true ),  // Day 1, published: shown.
	row( 1802, '2025-07-01', '10:00', 38.71, -9.13, 'Lisbon', false ), // …the rest.
	row( 1803, '2025-07-02', '09:00', 37.01, -7.93, 'Faro', true ),    // Day 2, a draft: shown.
	row( 1804, '2025-07-02', '10:00', 37.01, -7.93, 'Faro', false ),   // …hidden.
	row( 1805, '2025-07-03', '09:00', 37.01, -7.93, 'Faro', true ),    // Day 3, step binned: the chosen cover.
	row( 1806, '2025-07-03', '10:00', 37.01, -7.93, 'Faro', false ),   // …on no step.
];
$r = ( new TripSync( new FakeDawarich( 'http://x', 'k' ), $cf_source ) )->sync( $cf_trip );
[ $cf_day1, , $cf_day3 ] = Trips::steps( $cf_trip, true ) + [ null, null, null ];
check( 'cache fill: the trip syncs with three steps', ! is_wp_error( $r ) && $cf_day3, $r );
wp_publish_post( $cf_day1->ID );
wp_trash_post( $cf_day3->ID );
update_post_meta( $cf_trip, Trips::META_HIDDEN, [ $cf_id( 1804 ) ] );
update_post_meta( $cf_trip, Trips::META_COVER, $cf_id( 1805 ) );

$cf_fetched = [];
CacheFill::run( $cf_trip, 0 );
$cf_want = array_map( $cf_id, [ 1801, 1802, 1803, 1805 ] );
$cf_has  = array_values( array_filter( array_map( $cf_id, range( 1801, 1806 ) ), $cf_cached ) );
check( 'cache fill: every photo visitors can see, on published and draft steps, and the cover, is cached at preview', $cf_want === $cf_has, [ $cf_has, $cf_fetched ] );
check( '…fetched from Immich at preview only', [] === array_diff( $cf_fetched, array_map( fn( $id ) => "$id preview", $cf_want ) ) && 4 === count( $cf_fetched ), $cf_fetched );

remove_filter( 'pre_http_request', $cf_immich, 10 );
