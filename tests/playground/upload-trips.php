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

// Three days of uploads become three steps; what sync can't use is counted.
$ut_tour = $ut_trip( 'Tour', $ut_author, '2025-06-01', '2025-06-03' );
$ut_ids  = [];
foreach (
	[
		'doolin_am' => [ 'taken' => '2025:06:01 10:00:00', 'offset' => '+01:00' ] + $ut_doolin + $ut_iphone,
		'doolin_pm' => [ 'taken' => '2025:06:01 15:00:00', 'offset' => '+01:00' ] + $ut_doolin + $ut_iphone,
		'dingle'    => [ 'taken' => '2025:06:02 11:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone,
		'paris'     => [ 'taken' => '2025:06:03 12:00:00', 'offset' => '+02:00' ] + $ut_paris + $ut_iphone,
		'no_gps'    => [ 'taken' => '2025:06:03 09:00:00', 'offset' => '+02:00' ], // No camera either: still kept.
		'home'      => [ 'taken' => '2025:06:02 18:00:00', 'offset' => '+01:00', 'lat' => 53.3498, 'lon' => -6.2603 ] + $ut_iphone,
		'later'     => [ 'taken' => '2025:06:10 12:00:00', 'offset' => '+01:00' ] + $ut_paris + $ut_iphone,
		'untimed'   => $ut_doolin + $ut_iphone,
	] as $ut_name => $ut_tags
) {
	$ut_ids[ $ut_name ] = Uploads::photo_id( $ut_photo( $ut_tour, $ut_tags ) );
}
// Left over from when it used Immich: replaced by the uploads.
update_post_meta( $ut_tour, Trips::META_PHOTOS, wp_slash( wp_json_encode( [ row( 99, '2025-06-01', '10:00', 53.0, -9.3, 'Doolin', true ) ] ) ) );
$ut_result = ( new TripSync() )->sync( $ut_tour );
check( 'an upload trip over three days syncs into three steps', ! is_wp_error( $ut_result ) && 3 === $ut_result['new_steps'], is_wp_error( $ut_result ) ? $ut_result->get_error_message() : $ut_result );
$ut_kept = array_column( Trips::photos( $ut_tour ), 'id' );
check( '…keeping every upload on its days, without a camera too, and nothing from Immich', [] === array_diff( [ $ut_ids['doolin_am'], $ut_ids['doolin_pm'], $ut_ids['dingle'], $ut_ids['paris'], $ut_ids['no_gps'] ], $ut_kept ) && 5 === count( $ut_kept ), $ut_kept );
check( '…and counting those left out: one outside the dates, one untimed, one at home', [ 'outside' => 1, 'untimed' => 1, 'home' => 1 ] === ( $ut_result['left_out'] ?? null ) && 1 === ( $ut_result['unlocated'] ?? null ), $ut_result );
check( 'syncing it again adds no steps', 0 === ( ( new TripSync() )->sync( $ut_tour )['new_steps'] ?? -1 ) && 3 === count( Trips::steps( $ut_tour, true ) ) );
wp_delete_attachment( (int) Uploads::attachment( $ut_tour, $ut_ids['doolin_pm'] ), true );
( new TripSync() )->sync( $ut_tour );
check( 'a deleted upload leaves the trip at the next sync', ! in_array( $ut_ids['doolin_pm'], array_column( Trips::photos( $ut_tour ), 'id' ), true ) );

// A trip without dates takes them from its uploads.
$ut_undated = $ut_trip( 'Undated', $ut_author );
$ut_photo( $ut_undated, [ 'taken' => '2025:07:04 09:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
$ut_photo( $ut_undated, [ 'taken' => '2025:07:06 20:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
$ut_result = ( new TripSync() )->sync( $ut_undated );
check( 'a trip without dates gets them from its uploads', ! is_wp_error( $ut_result ) && [ '2025-07-04', '2025-07-06' ] === [ get_post_meta( $ut_undated, Trips::META_START, true ), get_post_meta( $ut_undated, Trips::META_END, true ) ] && 2 === $ut_result['new_steps'], is_wp_error( $ut_result ) ? $ut_result->get_error_message() : $ut_result );
$ut_half = $ut_trip( 'Half', $ut_author, '2025-07-01' );
$ut_photo( $ut_half, [ 'taken' => '2025:07:04 09:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
( new TripSync() )->sync( $ut_half );
check( 'with only a first day, sync fills in the last and keeps the first', [ '2025-07-01', '2025-07-04' ] === [ get_post_meta( $ut_half, Trips::META_START, true ), get_post_meta( $ut_half, Trips::META_END, true ) ] );
$ut_untimed_only = $ut_trip( 'Untimed', $ut_author );
$ut_photo( $ut_untimed_only, $ut_doolin );
$ut_result = ( new TripSync() )->sync( $ut_untimed_only );
check( 'a trip whose uploads have no times asks for dates', is_wp_error( $ut_result ) && 'travel_steps_no_dates' === $ut_result->get_error_code(), $ut_result );
$ut_result = ( new TripSync() )->sync( $ut_trip( 'Empty', $ut_author ) );
check( 'an upload trip with no uploads and no dates says to add photos', is_wp_error( $ut_result ) && 'travel_steps_no_photos' === $ut_result->get_error_code(), $ut_result );
$ut_immich = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Immich, undated', 'post_status' => 'draft', 'post_author' => $ut_author ] );
$ut_result = ( new TripSync() )->sync( $ut_immich );
check( 'an Immich trip without dates still fails as before', is_wp_error( $ut_result ) && 'travel_steps_no_dates' === $ut_result->get_error_code() );

// Place names for uploads come from the offline geocoder.
$ut_places = [];
foreach ( Trips::steps( $ut_tour, true ) as $ut_step ) {
	$ut_places[ Trips::day( $ut_step ) ] = wp_get_object_terms( $ut_step->ID, Trips::PLACE, [ 'fields' => 'names' ] );
}
ksort( $ut_places );
check( 'an upload trip’s steps are named by the geocoder', [ '2025-06-01' => [ 'Lisdoonvarna' ], '2025-06-02' => [ 'Dingle' ], '2025-06-03' => [ 'Paris' ] ] === $ut_places, $ut_places );
check( '…and so are its photos', [ 'Lisdoonvarna', 'Ireland' ] === [ Trips::photos( $ut_tour )[0]['city'] ?? '', Trips::photos( $ut_tour )[0]['country'] ?? '' ], Trips::photos( $ut_tour )[0] ?? [] );
check( '…with no warning about place names', false === ( ( new TripSync() )->sync( $ut_tour )['no_places'] ?? null ) );
$ut_result = ( new TripSync( null, null, new TravelSteps\Geocoder( '/nowhere' ) ) )->sync( $ut_tour );
check( 'missing geocoder data is reported', true === ( $ut_result['no_places'] ?? null ), $ut_result );

// Every upload is shown; pin, hide and cover work on them as on Immich photos.
$ut_groups = Trips::photos_by_step( $ut_tour, Trips::steps( $ut_tour, true ) );
$ut_shown  = array_merge( ...array_map( fn( $g ) => array_column( $g['shown'], 'id' ), array_values( $ut_groups ) ) );
check( 'every upload on a step is shown, none behind “Show more”', 4 === count( $ut_shown ) && [] === array_merge( ...array_map( fn( $g ) => $g['rest'], array_values( $ut_groups ) ) ), $ut_groups );
$rest_post( "/travel-steps/v1/trips/$ut_tour/photo-state", [ 'asset' => $ut_ids['dingle'], 'state' => 'hidden' ] );
$rest_post( "/travel-steps/v1/trips/$ut_tour/photo-state", [ 'asset' => $ut_ids['paris'], 'state' => 'pinned' ] );
$ut_cover = $rest_post( "/travel-steps/v1/trips/$ut_tour/cover", [ 'asset' => $ut_ids['paris'] ] );
$ut_groups = Trips::photos_by_step( $ut_tour, Trips::steps( $ut_tour, true ) );
$ut_hidden = array_merge( ...array_map( fn( $g ) => array_column( $g['hidden'], 'id' ), array_values( $ut_groups ) ) );
check( 'an upload can be hidden, pinned and made the cover', [ $ut_ids['dingle'] ] === $ut_hidden && in_array( $ut_ids['paris'], Trips::pinned( $ut_tour ), true ) && 200 === $ut_cover->get_status() && $ut_ids['paris'] === Trips::cover( $ut_tour ), [ $ut_hidden, $ut_cover->get_data() ] );

// Trip photos must be images: Imagick would otherwise open a PDF or a video to read it.
$ut_sideload = function ( string $name, string $bytes ) use ( $ut_tour ) {
	$_REQUEST = [ 'post_id' => $ut_tour, Uploads::REQUEST_FLAG => '1' ];
	$tmp      = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	$id       = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $ut_tour );
	$_REQUEST = [];
	return $id;
};
$ut_pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
check( 'a PDF can’t be a trip photo', is_wp_error( $ut_sideload( 'notes.pdf', $ut_pdf ) ) );
check( '…even named like a photo', is_wp_error( $ut_sideload( 'IMG_0001.jpg', $ut_pdf ) ) );
