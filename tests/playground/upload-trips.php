<?php
/**
 * Trips whose photos are uploaded to WordPress. Required at the end of smoke.php, in
 * its scope: uses check(), $upload, $rest_post, $get, $stopped, $person and $eddie.
 */
use TravelSteps\MediaSource;
use TravelSteps\Settings;
use TravelSteps\Trips;
use TravelSteps\TripSync;
use TravelSteps\Uploads;

wp_set_current_user( 1 );
$ut_author = $person( 'wanderer', 'author' );
// Home in Dublin: photos taken there are left out.
Settings::update_account( $ut_author, [ 'home_lat' => '53.3498', 'home_lon' => '-6.2603', 'home_radius_km' => 1 ] + Settings::ACCOUNT_DEFAULTS );
$ut_trip = function ( string $title, int $author, string $start = '', string $end = '' ) {
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $author ] );
	update_post_meta( $id, Trips::META_SOURCE, Trips::SOURCE_UPLOADS );
	update_post_meta( $id, Trips::META_START, $start );
	update_post_meta( $id, Trips::META_END, $end );
	return $id;
};
$ut_photo = fn( int $trip_id, array $tags ) => $upload( [ 'post_id' => $trip_id, Uploads::REQUEST_FLAG => '1' ], $trip_id, 600, 400, $tags );
$ut_iphone = [ 'make' => 'Apple', 'model' => 'iPhone 15' ];
$ut_doolin = [ 'lat' => 53.0158, 'lon' => -9.3769 ];
$ut_dingle = [ 'lat' => 52.1409, 'lon' => -10.2689 ];
$ut_paris  = [ 'lat' => 48.8566, 'lon' => 2.3522 ];

// Which source a trip uses.
$ut_rows_trip = $ut_trip( 'Rows', $ut_author );
check( 'an upload trip uses its uploads', Trips::uses_uploads( $ut_rows_trip ) && Trips::source( $ut_rows_trip ) instanceof MediaSource );
$ut_zoned = $ut_photo( $ut_rows_trip, [ 'taken' => '2025:06:01 10:00:00', 'offset' => '+01:00' ] + $ut_doolin + $ut_iphone );
$ut_bare  = $ut_photo( $ut_rows_trip, [ 'taken' => '2025:06:01 11:00:00' ] );
$ut_none  = $ut_photo( $ut_rows_trip, [] );
$ut_rows  = array_column( ( new MediaSource() )->photos( $ut_rows_trip, null ), null, 'id' );
$ut_row   = $ut_rows[ Uploads::photo_id( $ut_zoned ) ] ?? [];
check(
	'an upload’s row: its time, zone, day, place, size and camera, and a favourite',
	3 === count( $ut_rows )
		&& strtotime( '2025-06-01 10:00:00 UTC' ) === $ut_row['local'] && true === $ut_row['zoned'] && strtotime( '2025-06-01 09:00:00 UTC' ) === $ut_row['t']
		&& '2025-06-01' === $ut_row['day'] && 53.0158 === round( $ut_row['lat'], 4 ) && -9.3769 === round( $ut_row['lon'], 4 )
		&& 600 === $ut_row['w'] && 400 === $ut_row['h'] && 'Apple iPhone 15' === $ut_row['camera'] && true === $ut_row['fav']
		&& '' === $ut_row['city'] && '' === $ut_row['caption'],
	$ut_row
);
$ut_row = $ut_rows[ Uploads::photo_id( $ut_bare ) ] ?? [];
check( 'an upload without an offset: wall-clock time, not zoned', false === $ut_row['zoned'] && $ut_row['local'] === $ut_row['t'] && null === $ut_row['lat'], $ut_row );
$ut_row = $ut_rows[ Uploads::photo_id( $ut_none ) ] ?? [];
check( 'an upload without a time: no local time and no day', null === $ut_row['local'] && '' === $ut_row['day'], $ut_row );

// Only the author changes the source.
wp_set_current_user( $ut_author );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'start' => '', 'end' => '', 'status' => 'publish', 'source' => Trips::SOURCE_IMMICH, '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( 'the author can switch a trip to Immich', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && ! Trips::uses_uploads( $ut_rows_trip ) );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'start' => '', 'end' => '', 'status' => 'publish', 'source' => Trips::SOURCE_UPLOADS, '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( '…and back to uploads', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'start' => '', 'end' => '', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( 'a form without the source leaves it alone', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
wp_set_current_user( $eddie );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'status' => 'publish', 'source' => Trips::SOURCE_IMMICH, '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( 'another editor can’t change the source', 'die' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
$_POST = $_REQUEST = [];
wp_set_current_user( 1 );
