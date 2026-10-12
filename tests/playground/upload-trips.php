<?php
/**
 * Trips whose photos are uploaded to WordPress. Required at the end of smoke.php, in
 * its scope: uses check(), $upload, $sideload, $servers, $keys_used, $rest_post, $get,
 * $stopped, $person and $eddie.
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

// Only the author changes the source, with the "Photos" select: uploads, all Immich photos, or an album.
$ut_save = function ( array $fields ) use ( $ut_rows_trip, $stopped ) {
	$_POST  = $_REQUEST = $fields + [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
	$result = $stopped( [ new Trips(), 'handle_save_trip' ] );
	$_POST  = $_REQUEST = [];
	return $result;
};
$ut_album  = fn() => get_post_meta( $ut_rows_trip, Trips::META_ALBUM, true );
$ut_albumA = '11111111-2222-4333-8444-555555555555';
$ut_albumB = '22222222-3333-4444-8555-666666666666';
wp_set_current_user( $ut_author );
check( 'the author can switch a trip to Immich', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_IMMICH ] ) && ! Trips::uses_uploads( $ut_rows_trip ) && '' === $ut_album() );
check( '…and back to uploads', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_UPLOADS ] ) && Trips::uses_uploads( $ut_rows_trip ) );
check( 'a form without the select leaves the source alone', 'redirect' === $ut_save( [ 'start' => '', 'end' => '' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
check( 'an album chosen in the select: the trip uses Immich, with that album', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => $ut_albumA ] ) && ! Trips::uses_uploads( $ut_rows_trip ) && $ut_albumA === $ut_album() );
check( 'switching to uploads keeps the album, for switching back', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_UPLOADS ] ) && Trips::uses_uploads( $ut_rows_trip ) && $ut_albumA === $ut_album() );
check( 'a form without the select leaves the album alone', 'redirect' === $ut_save( [ 'start' => '', 'end' => '' ] ) && $ut_albumA === $ut_album() );
check( 'all Immich photos with an album id typed beside it: that album', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_IMMICH, 'album' => $ut_albumB ] ) && ! Trips::uses_uploads( $ut_rows_trip ) && $ut_albumB === $ut_album() );
check( 'a typed album id is cleaned', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_IMMICH, 'album' => '"><x>' . $ut_albumA ] ) && $ut_albumA === $ut_album() );
$ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_UPLOADS ] );
wp_set_current_user( $eddie );
check( 'another editor can’t change the source', 'die' === $ut_save( [ 'photos' => Trips::SOURCE_IMMICH ] ) && Trips::uses_uploads( $ut_rows_trip ) );
wp_set_current_user( 1 );

// Where a form handler redirects to; 'die' if it stops instead.
$ut_location = function ( callable $handler ) use ( $stopped ): string {
	$to   = '';
	$keep = function ( $location ) use ( &$to ) {
		$to = $location;
		return $location;
	};
	add_filter( 'wp_redirect', $keep, 1 );
	$result = $stopped( $handler );
	remove_filter( 'wp_redirect', $keep, 1 );
	return 'redirect' === $result ? $to : $result;
};

// A new trip of uploads has none yet: saving it opens its edit page ready to add some.
wp_set_current_user( $ut_author );
$ut_create = function ( string $title, string $photos ) use ( $ut_location ) {
	$_POST = $_REQUEST = [ 'trip_id' => 0, 'title' => $title, 'start' => '', 'end' => '', 'photos' => $photos, 'status' => 'draft', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
	$to    = $ut_location( [ new Trips(), 'handle_save_trip' ] );
	$_POST = $_REQUEST = [];
	$trip  = Trips::find_by_slug( sanitize_title( $title ) );
	if ( $trip ) {
		wp_delete_post( $trip->ID, true );
	}
	return $to;
};
$ut_to = $ut_create( 'Fresh uploads', Trips::SOURCE_UPLOADS );
check( 'a new trip of uploads opens its edit page with ?add=1', false !== strpos( $ut_to, '/edit' ) && false !== strpos( $ut_to, 'add=1' ), $ut_to );
$ut_to = $ut_create( 'Fresh Immich', Trips::SOURCE_IMMICH );
check( '…a new Immich trip doesn’t', false !== strpos( $ut_to, 'saved=1' ) && false === strpos( $ut_to, 'add=1' ), $ut_to );
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
wp_delete_attachment( Uploads::attachment( $ut_tour, $ut_ids['doolin_pm'] ), true );
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
$ut_first = Trips::photos( $ut_tour )[0] ?? [];
check( '…and so are its photos', [ 'Lisdoonvarna', 'Ireland' ] === [ $ut_first['city'] ?? '', $ut_first['country'] ?? '' ], $ut_first );
check( '…with no warning about place names', false === ( ( new TripSync() )->sync( $ut_tour )['no_places'] ?? null ) );
$ut_result = ( new TripSync( null, null, new TravelSteps\Geocoder( '/nowhere' ) ) )->sync( $ut_tour );
check( 'missing geocoder data is reported', true === ( $ut_result['no_places'] ?? null ), $ut_result );

// Every upload is shown; pin, hide and cover work on them as on Immich photos.
// The ids in one of Selection's groups (shown, rest, hidden), across every step.
$ut_in     = fn( string $group ) => array_column( array_merge( ...array_column( Trips::photos_by_step( $ut_tour, Trips::steps( $ut_tour, true ) ), $group ) ), 'id' );
check( 'every upload on a step is shown, none behind “Show more”', 4 === count( $ut_in( 'shown' ) ) && [] === $ut_in( 'rest' ), [ $ut_in( 'shown' ), $ut_in( 'rest' ) ] );
$rest_post( "/travel-steps/v1/trips/$ut_tour/photo-state", [ 'asset' => $ut_ids['dingle'], 'state' => 'hidden' ] );
$rest_post( "/travel-steps/v1/trips/$ut_tour/photo-state", [ 'asset' => $ut_ids['paris'], 'state' => 'pinned' ] );
$ut_cover = $rest_post( "/travel-steps/v1/trips/$ut_tour/cover", [ 'asset' => $ut_ids['paris'] ] );
$ut_hidden = $ut_in( 'hidden' );
check( 'an upload can be hidden, pinned and made the cover', [ $ut_ids['dingle'] ] === $ut_hidden && in_array( $ut_ids['paris'], Trips::pinned( $ut_tour ), true ) && 200 === $ut_cover->get_status() && $ut_ids['paris'] === Trips::cover( $ut_tour ), [ $ut_hidden, $ut_cover->get_data() ] );

// Trip photos must be images: Imagick would otherwise open a PDF or a video to read it.
$ut_as_photo = [ 'post_id' => $ut_tour, Uploads::REQUEST_FLAG => '1' ];
$ut_pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
check( 'a PDF can’t be a trip photo', is_wp_error( $sideload( $ut_as_photo, $ut_tour, 'notes.pdf', $ut_pdf ) ) );
check( '…even named like a photo', is_wp_error( $sideload( $ut_as_photo, $ut_tour, 'IMG_0001.jpg', $ut_pdf ) ) );

// Core REST gives editors an attachment's file URL and names (source_url, media_details,
// guid, the description's <img>); on nginx those lead to the untouched original.
$ut_rest_id = $ut_photo( $ut_tour, $ut_doolin + $ut_iphone );
$ut_file    = pathinfo( (string) get_post_meta( $ut_rest_id, '_wp_attached_file', true ), PATHINFO_FILENAME );
$ut_file    = preg_replace( '/-scaled$/', '', $ut_file );
$ut_json    = wp_json_encode( $get( "/wp/v2/media/$ut_rest_id", [ 'context' => 'edit' ] )->get_data(), JSON_UNESCAPED_SLASHES );
check( 'core REST names no file for a trip photo, even for editors', false === strpos( $ut_json, $ut_file ) && false === strpos( $ut_json, '/uploads/travel-steps/' ), substr( $ut_json, 0, 800 ) );

// An edited upload gets a new ETag, so browsers that revalidate fetch the new image.
$ut_asset   = Uploads::photo_id( $ut_rest_id );
$ut_before  = TravelSteps\RestController::etag( $ut_asset, 'thumbnail', $ut_rest_id );
wp_update_attachment_metadata( $ut_rest_id, wp_get_attachment_metadata( $ut_rest_id ) );
check( 'an upload’s ETag changes with its copies', $ut_before !== TravelSteps\RestController::etag( $ut_asset, 'thumbnail', $ut_rest_id ) );
check( 'an Immich photo’s ETag is as before', '"' . md5( '00000000-0000-4000-8000-000000000001/thumbnail' ) . '"' === TravelSteps\RestController::etag( '00000000-0000-4000-8000-000000000001', 'thumbnail' ) );

// A typed date the photos fall on the wrong side of isn't overwritten or paired backwards.
$ut_late = $ut_trip( 'Late start', $ut_author, '2025-07-10' );
$ut_photo( $ut_late, [ 'taken' => '2025:07:04 09:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
$ut_result = ( new TripSync() )->sync( $ut_late );
check( 'a first day after every photo: an error that says so, and no last day saved', is_wp_error( $ut_result ) && 'travel_steps_photos_outside' === $ut_result->get_error_code() && '' === get_post_meta( $ut_late, Trips::META_END, true ), [ is_wp_error( $ut_result ) ? $ut_result->get_error_code() : $ut_result, get_post_meta( $ut_late, Trips::META_END, true ) ] );
$ut_result = ( new TripSync() )->sync( $ut_trip( 'Backwards', $ut_author, '2025-07-10', '2025-07-01' ) );
check( 'dates set backwards on an upload trip: set the dates, not add photos', is_wp_error( $ut_result ) && 'travel_steps_no_dates' === $ut_result->get_error_code(), $ut_result );

// Switched from Immich to uploads, a trip's synced Immich photos still load until it syncs again.
Settings::update_account( $ut_author, [ 'immich_key' => 'wanderer-immich' ] + Settings::account( $ut_author ) );
$ut_switched = $ut_trip( 'Switched', $ut_author, '2025-06-01', '2025-06-03' );
$keys_used   = [];
add_filter( 'pre_http_request', $servers, 10, 3 );
$ut_image = TravelSteps\RestController::image( $ut_switched, '00000000-0000-4000-8000-000000000099', 'thumbnail', 0 );
remove_filter( 'pre_http_request', $servers, 10 );
check( 'a photo that isn’t an upload is fetched from Immich, whatever the trip uses now', ! is_wp_error( $ut_image ) && [ 'wanderer-immich' ] === $keys_used, [ is_wp_error( $ut_image ) ? $ut_image->get_error_message() : 'ok', $keys_used ] );

// Only the image types a trip shows: TIFF, PSD and BMP would still go through Imagick.
ob_start();
imagebmp( imagecreatetruecolor( 8, 8 ) );
check( 'a BMP can’t be a trip photo', is_wp_error( $sideload( $ut_as_photo, $ut_tour, 'scan.bmp', ob_get_clean() ) ) );

// A date that isn't one: refused when saved, and a sync error rather than a crash if stored.
$ut_bad = $ut_trip( 'Bad date', $ut_author, '2025-06-32' );
$ut_photo( $ut_bad, [ 'taken' => '2025:07:04 09:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
$ut_result = ( new TripSync() )->sync( $ut_bad );
check( 'a stored date that doesn’t exist fails the sync with an error', is_wp_error( $ut_result ), $ut_result );
wp_set_current_user( $ut_author );
$_POST = $_REQUEST = [ 'trip_id' => $ut_bad, 'title' => 'Bad date', 'start' => '2025-13-01', 'end' => '2025-02-30', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
$stopped( [ new Trips(), 'handle_save_trip' ] );
$_POST = $_REQUEST = [];
wp_set_current_user( 1 );
check( 'dates that don’t exist aren’t saved', [ '', '' ] === [ get_post_meta( $ut_bad, Trips::META_START, true ), get_post_meta( $ut_bad, Trips::META_END, true ) ] );
