<?php
/**
 * End-to-end checks inside WordPress Playground, with a fake Dawarich client and photo source:
 * sync, re-sync, the REST gate, story rendering and the trip pages. Run with `make smoke`.
 * Results go to /out/result.txt, one "ok" or "FAIL" line per check.
 */
require '/wordpress/wp-load.php';

use TravelSteps\{TripSync, Trips, View, DawarichClient, ImmichClient, ImmichSource, PhotoSource, Days, Settings};

$out = [];
// Write results even if a fatal error ends the run, with the error as a failure.
register_shutdown_function(
	function () {
		global $out;
		$error = error_get_last();
		if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			$out[] = 'FAIL fatal: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line'];
		}
		file_put_contents( '/out/result.txt', implode( "\n", $out ) . "\n" );
	}
);
function check( string $name, bool $ok, $detail = '' ) {
	global $out;
	$out[] = ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  -- ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) );
}

try {
check( 'plugin active and classes load', class_exists( TripSync::class ) && post_type_exists( Trips::STEP ) && taxonomy_exists( Trips::PLACE ) );

// Upgrading from site-wide keys before any trip exists: every administrator gets them.
delete_option( Settings::MIGRATED );
update_option( Settings::OPTION, [ 'immich_url' => 'http://immich.test', 'immich_key' => 'site-immich', 'dawarich_key' => 'site-dawarich', 'home_lat' => '53.3', 'home_lon' => '-6.2', 'home_radius_km' => 2 ] );
$second_admin = wp_insert_user( [ 'user_login' => 'admin2', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
Settings::migrate();
$moved = [ Settings::account( 1 ), Settings::account( $second_admin ) ];
check( 'upgrade with no trips: every administrator gets the site’s keys and home', [ 'site-immich', 'site-dawarich', '53.3', '-6.2', 2 ] === array_values( array_intersect_key( $moved[0], array_flip( [ 'immich_key', 'dawarich_key', 'home_lat', 'home_lon', 'home_radius_km' ] ) ) ) && $moved[0] === $moved[1], $moved );
check( 'upgrade: the site settings keep the URLs but no keys or home', [ 'immich_url' => 'http://immich.test' ] === get_option( Settings::OPTION ), get_option( Settings::OPTION ) );
delete_user_option( 1, Settings::ACCOUNT );
delete_user_option( $second_admin, Settings::ACCOUNT );

class FakeDawarich extends DawarichClient {
	public function points( int $start, int $end ) {
		$pts = [];
		for ( $t = $start; $t < $end; $t += 1800 ) {
			$pts[] = [ 38.7139, -9.1394, $t, 'Lisbon', 'Portugal' ];
		}
		return $pts;
	}
}
// At home in Lisbon (with one bad fix half an hour before leaving), then off to Sintra.
class HomeDawarich extends DawarichClient {
	public function points( int $start, int $end ) {
		$pts = [];
		for ( $t = $start; $t < $start + 6 * HOUR_IN_SECONDS; $t += 600 ) {
			$pts[] = [ 38.7139, -9.1394, $t, 'Lisbon', 'Portugal' ];
		}
		$pts[] = [ 40.4168, -3.7038, $t - 1800 + 60, 'Madrid', 'Spain' ];
		usort( $pts, fn( $a, $b ) => $a[2] <=> $b[2] );
		for ( $i = 1; $i <= 12; $i++ ) {
			$pts[] = [ 38.7139 + $i * 0.007, -9.1394 - $i * 0.021, $t + $i * 600, 'Sintra', 'Portugal' ];
		}
		return $pts;
	}
}
// Returns whatever track it's given.
class TrackDawarich extends DawarichClient {
	public array $pts = [];
	public function points( int $start, int $end ) {
		return $this->pts;
	}
}
class FakeSource implements PhotoSource {
	public array $rows = [];
	public ?array $asked = [];
	public function photos( int $trip_id, ?array $range ) {
		$this->asked = $range;
		return $this->rows;
	}
	public function needs_dates( int $trip_id ): bool {
		return true;
	}
}
function row( $n, $day, $hm, $lat, $lon, $city, $fav ) {
	$t = strtotime( "$day $hm UTC" );
	return [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', $n ), 't' => $t, 'day' => $day, 'local' => $t, 'zoned' => true, 'lat' => $lat, 'lon' => $lon,
		'w' => 400, 'h' => 300, 'city' => $city, 'country' => 'Portugal', 'caption' => '', 'fav' => $fav, 'camera' => 'Fuji X100' ];
}
$source       = new FakeSource();
$source->rows = [
	row( 1, '2025-05-01', '09:00', 38.7139, -9.1394, 'Lisbon', true ),
	row( 2, '2025-05-01', '09:30', 38.7140, -9.1390, 'Lisbon', false ),
	row( 3, '2025-05-01', '14:00', 38.7979, -9.3880, 'Sintra', true ),
	row( 4, '2025-05-01', '14:20', 38.7976, -9.3885, 'Sintra', false ),
	row( 5, '2025-05-02', '11:00', 37.0194, -7.9304, 'Faro', true ),
	row( 6, '2025-05-03', '10:00', 37.0194, -7.9304, 'Faro', false ), // No favourites that day.
	row( 7, '2025-04-30', '20:00', 38.7139, -9.1394, 'Lisbon', true ),  // Before the trip.
];

wp_set_current_user( 1 );
$trip_id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Portugal', 'post_status' => 'draft' ] );
update_post_meta( $trip_id, Trips::META_START, '2025-05-01' );
update_post_meta( $trip_id, Trips::META_END, '2025-05-03' );

$sync = new TripSync( new FakeDawarich( 'http://x', 'k' ), $source );
$r    = $sync->sync( $trip_id );
check( 'first sync creates a step per day with favourites', ! is_wp_error( $r ) && 2 === $r['new_steps'], $r );

$steps = Trips::steps( $trip_id, true );
check( 'two steps', 2 === count( $steps ), count( $steps ) );
[ $from, $to ] = Trips::range( $trip_id );
check( 'photos asked for 14h either side of the trip', [ $from - 14 * HOUR_IN_SECONDS, $to + 14 * HOUR_IN_SECONDS ] === $source->asked, [ $from, $to, $source->asked ] );
check( 'no photos kept from before the trip', ! in_array( row( 7, '2025-04-30', '20:00', 0, 0, '', 1 )['id'], array_column( Trips::photos( $trip_id ), 'id' ), true ) );
$d1 = $steps[0]; $d2 = $steps[1] ?? null;
check( 'steps are drafts', 'draft' === $d1->post_status && 'draft' === $d2->post_status );
check( 'day 1 title names both places', 'Lisbon, Sintra' === $d1->post_title, $d1->post_title );
check( 'day 1 dated 1 May', '2025-05-01' === Trips::day( $d1 ), $d1->post_date );
check( 'day 1 seeded with two place headings', 2 === count( Days::anchor_times( $d1->post_content ) ), $d1->post_content );
check( 'day 2 has no headings', false === strpos( $d2->post_content, 'wp:heading' ), $d2->post_content );
check( 'day 2 has a photos block', has_block( Days::PHOTOS_BLOCK, $d2->post_content ), $d2->post_content );
check( 'day 1 tagged with places', [ 'Lisbon', 'Sintra' ] === wp_list_pluck( wp_get_object_terms( $d1->ID, Trips::PLACE, [ 'orderby' => 'name' ] ), 'name' ) );
check( 'day 1 positioned at its first favourite', '38.7139' === (string) get_post_meta( $d1->ID, Trips::META_LAT, true ), get_post_meta( $d1->ID, Trips::META_LAT, true ) );
check( 'draft step has a real GMT date', '0000-00-00 00:00:00' !== $d1->post_date_gmt, $d1->post_date_gmt );

// User edits survive a re-sync.
wp_update_post( [ 'ID' => $d1->ID, 'post_title' => 'My Lisbon day', 'post_content' => $d1->post_content . "\n<!-- wp:paragraph --><p>Tram 28.</p><!-- /wp:paragraph -->" ] );
$r = $sync->sync( $trip_id );
check( 're-sync creates nothing', 0 === $r['new_steps'], $r );
check( 're-sync keeps the edited title', 'My Lisbon day' === get_post( $d1->ID )->post_title );
check( 're-sync keeps the edited story', false !== strpos( get_post( $d1->ID )->post_content, 'Tram 28' ) );

// Binned day stays binned.
wp_trash_post( $d2->ID );
$r = $sync->sync( $trip_id );
check( 'binned day is not recreated', 0 === $r['new_steps'] && 1 === count( Trips::steps( $trip_id, true ) ), $r );
check( 'sync counts the day skipped for the trash', 1 === ( $r['skipped_days'] ?? null ), $r );
wp_untrash_post( $d2->ID );
wp_update_post( [ 'ID' => $d2->ID, 'post_status' => 'draft' ] );

// Moving an auto step to another date doesn't bring back its original day.
wp_update_post( [ 'ID' => $d2->ID, 'post_date' => '2025-05-03 12:00:00', 'edit_date' => true ] );
$r = $sync->sync( $trip_id );
check( 'moved step keeps its original day taken', 0 === $r['new_steps'], $r );
check( 'a moved step is not counted as skipped', 0 === ( $r['skipped_days'] ?? null ), $r );
wp_update_post( [ 'ID' => $d2->ID, 'post_date' => '2025-05-02 12:00:00', 'edit_date' => true ] );

// Lock.
add_option( 'travel_steps_sync_lock_' . $trip_id, time(), '', false );
$r = $sync->sync( $trip_id );
check( 'concurrent sync refused', is_wp_error( $r ) && 'travel_steps_sync_running' === $r->get_error_code() );
delete_option( 'travel_steps_sync_lock_' . $trip_id );

// A step's position is derived data: each sync refreshes it, and clears it when there's none to be had.
$stale_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Stale', 'post_status' => 'draft' ] );
update_post_meta( $stale_trip, Trips::META_START, '2025-06-01' );
update_post_meta( $stale_trip, Trips::META_END, '2025-06-02' );
$stale_source       = new FakeSource();
$stale_source->rows = [ row( 51, '2025-06-01', '10:00', 38.7139, -9.1394, 'Lisbon', true ), row( 52, '2025-06-02', '10:00', 37.0194, -7.9304, 'Faro', true ) ];
$stale_no_track     = new TripSync( new DawarichClient( 'http://x', '' ), $stale_source );
$stale_no_track->sync( $stale_trip );
[ $stale_d1, $stale_d2 ] = array_column( Trips::steps( $stale_trip, true ), 'ID' ) + [ 0, 0 ];
$stale_at = fn( int $id ) => array_map( 'strval', [ get_post_meta( $id, Trips::META_LAT, true ), get_post_meta( $id, Trips::META_LON, true ) ] );
$unplaced = fn( int $id ) => ! metadata_exists( 'post', $id, Trips::META_LAT ) && ! metadata_exists( 'post', $id, Trips::META_LON );
check( 'stale: both days placed by the first sync', [ [ '38.7139', '-9.1394' ], [ '37.0194', '-7.9304' ] ] === [ $stale_at( $stale_d1 ), $stale_at( $stale_d2 ) ], [ $stale_at( $stale_d1 ), $stale_at( $stale_d2 ) ] );
// Day 1's photo loses its position and there's no track; day 2's photo moves.
$stale_source->rows = [ row( 51, '2025-06-01', '10:00', null, null, '', true ), row( 52, '2025-06-02', '10:00', 37.1, -7.9, 'Faro', true ) ];
$stale_no_track->sync( $stale_trip );
check( 'a step sync finds no position for loses the one an earlier sync stored', 0 !== $stale_d1 && $unplaced( $stale_d1 ), $stale_at( $stale_d1 ) );
check( '…while one placed from a photo follows it', [ '37.1', '-7.9' ] === $stale_at( $stale_d2 ), $stale_at( $stale_d2 ) );
// Day 1's photo goes, so only the track can place its step.
$stale_source->rows = [ $stale_source->rows[1] ];
( new TripSync( new FakeDawarich( 'http://x', 'k' ), $stale_source ) )->sync( $stale_trip );
check( '…and one with a track point near its day is placed from the track again', [ '38.7139', '-9.1394' ] === $stale_at( $stale_d1 ), $stale_at( $stale_d1 ) );
foreach ( Trips::steps( $stale_trip, true ) as $stale_step ) {
	wp_delete_post( $stale_step->ID, true );
}
wp_delete_post( $stale_trip, true );

// A trip's photos come from Immich, from its album when it has one.
class AlbumImmich extends ImmichClient {
	public ?string $album = null;
	public array $rows    = [];
	public function photos( int $start, int $end, string $album_id = '' ) {
		$this->album = $album_id;
		return $this->rows;
	}
}
check( 'a trip uses Immich for its photos', Trips::source( $trip_id ) instanceof ImmichSource );
$album_client = new AlbumImmich( 'http://x', 'k' );
update_post_meta( $trip_id, Trips::META_ALBUM, '11111111-1111-4111-8111-111111111111' );
( new ImmichSource( $album_client ) )->photos( $trip_id, [ 0, 1 ] );
delete_post_meta( $trip_id, Trips::META_ALBUM );
check( 'Immich is asked for the trip’s album', '11111111-1111-4111-8111-111111111111' === $album_client->album, $album_client->album );
$no_dates = ( new ImmichSource( new AlbumImmich( 'http://x', 'k' ) ) )->photos( $trip_id, null );
check( 'Immich needs the trip’s dates', is_wp_error( $no_dates ) && 'travel_steps_no_dates' === $no_dates->get_error_code() );
// With an album it doesn't: the album says which photos belong to the trip.
update_post_meta( $trip_id, Trips::META_ALBUM, '11111111-1111-4111-8111-111111111111' );
$album_client = new AlbumImmich( 'http://x', 'k' );
$album_rows   = ( new ImmichSource( $album_client ) )->photos( $trip_id, null );
delete_post_meta( $trip_id, Trips::META_ALBUM );
check( '…but with an album, Immich fetches it without them', [] === $album_rows && '11111111-1111-4111-8111-111111111111' === $album_client->album, $album_rows );

// An Immich trip with an album takes its missing dates from the album's photos, as a
// trip of uploads does, and they follow the album until the author changes them.
$album_immich       = new AlbumImmich( 'http://x', 'k' );
$album_immich->rows = [
	row( 31, '2025-05-01', '10:00', 38.7139, -9.1394, 'Lisbon', true ),
	row( 32, '2025-05-02', '11:00', 37.0194, -7.9304, 'Faro', true ),
];
$album_sync = new TripSync( new FakeDawarich( 'http://x', 'k' ), new ImmichSource( $album_immich ) );
$album_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Album', 'post_status' => 'draft' ] );
update_post_meta( $album_trip, Trips::META_ALBUM, '11111111-1111-4111-8111-111111111111' );
$album_dates = fn() => [ get_post_meta( $album_trip, Trips::META_START, true ), get_post_meta( $album_trip, Trips::META_END, true ) ];
$r = $album_sync->sync( $album_trip );
check( 'an album trip with no dates syncs, taking them from the album', ! is_wp_error( $r ) && 2 === $r['new_steps'] && [ '2025-05-01', '2025-05-02' ] === $album_dates(), [ is_wp_error( $r ) ? $r->get_error_message() : $r, $album_dates() ] );
check( '…and reports no left-out counts', is_array( $r ) && ! isset( $r['left_out'] ), $r );
$album_immich->rows[] = row( 33, '2025-05-04', '09:00', 37.0194, -7.9304, 'Faro', true );
$album_sync->sync( $album_trip );
check( '…they follow a photo added to the album on a later day', [ '2025-05-01', '2025-05-04' ] === $album_dates(), $album_dates() );
update_post_meta( $album_trip, Trips::META_END, '2025-05-03' );
$album_immich->rows[] = row( 34, '2025-04-30', '09:00', 38.7139, -9.1394, 'Lisbon', true );
$album_sync->sync( $album_trip );
check( '…a date the author typed stays, while the other still follows', [ '2025-04-30', '2025-05-03' ] === $album_dates(), $album_dates() );
delete_post_meta( $album_trip, Trips::META_ALBUM );
$album_immich->rows[] = row( 35, '2025-05-06', '09:00', 37.0194, -7.9304, 'Faro', true );
$r = $album_sync->sync( $album_trip );
check( '…and switched to all Immich photos, the trip keeps them as they are', ! is_wp_error( $r ) && [ '2025-04-30', '2025-05-03' ] === $album_dates(), [ is_wp_error( $r ) ? $r->get_error_message() : $r, $album_dates() ] );
$album_empty = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Empty album', 'post_status' => 'draft' ] );
update_post_meta( $album_empty, Trips::META_ALBUM, '11111111-1111-4111-8111-111111111111' );
$r = ( new TripSync( new FakeDawarich( 'http://x', 'k' ), new ImmichSource( new AlbumImmich( 'http://x', 'k' ) ) ) )->sync( $album_empty );
check( 'an empty album with no dates asks for them', is_wp_error( $r ) && 'The album has no photos, so set the trip’s dates.' === $r->get_error_message(), is_wp_error( $r ) ? $r->get_error_message() : $r );
delete_post_meta( $album_empty, Trips::META_ALBUM );
$r = ( new TripSync( new FakeDawarich( 'http://x', 'k' ), new ImmichSource( $album_immich ) ) )->sync( $album_empty );
check( 'an Immich trip with no album and no dates still needs them', is_wp_error( $r ) && 'Set the trip start and end dates first.' === $r->get_error_message(), is_wp_error( $r ) ? $r->get_error_message() : $r );
foreach ( Trips::steps( $album_trip, true ) as $album_step ) {
	wp_delete_post( $album_step->ID, true );
}
wp_delete_post( $album_trip, true );
wp_delete_post( $album_empty, true );

// Sources hand over wall-clock times; sync works out each photo's zone, and the order.
$zone_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Zones', 'post_status' => 'draft' ] );
update_post_meta( $zone_trip, Trips::META_START, '2025-05-01' );
update_post_meta( $zone_trip, Trips::META_END, '2025-05-01' );
$zone_known          = row( 21, '2025-05-01', '12:00', 38.7139, -9.1394, 'Lisbon', true );
$zone_known['local'] = $zone_known['t'] + HOUR_IN_SECONDS; // 13:00 where it was taken: UTC+1.
$zone_bare           = row( 22, '2025-05-01', '10:00', 38.7139, -9.1394, 'Lisbon', true );
$zone_bare['zoned']  = false; // 10:00 local, no offset: it borrows +1 from the photo beside it.
$zone_source         = new FakeSource();
$zone_source->rows   = [ $zone_known, $zone_bare ];
( new TripSync( new DawarichClient( 'http://x', '' ), $zone_source ) )->sync( $zone_trip );
$zone_times = array_column( Trips::photos( $zone_trip ), 't', 'id' );
check( 'sync resolves a time without an offset from its neighbours, and sorts', [ $zone_bare['id'], $zone_known['id'] ] === array_keys( $zone_times ) && strtotime( '2025-05-01 09:00 UTC' ) === $zone_times[ $zone_bare['id'] ], $zone_times );
// Its step would show up in the step listings checked below.
foreach ( Trips::steps( $zone_trip, true ) as $zone_step ) {
	wp_delete_post( $zone_step->ID, true );
}
wp_delete_post( $zone_trip, true );

// Without Dawarich, the photos' own positions make the route.
$solo_id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Photos only', 'post_status' => 'draft' ] );
update_post_meta( $solo_id, Trips::META_START, '2025-05-01' );
update_post_meta( $solo_id, Trips::META_END, '2025-05-03' );
$r = ( new TripSync( new DawarichClient( 'http://x', '' ), $source ) )->sync( $solo_id );
check( 'sync works without a Dawarich key', ! is_wp_error( $r ) && 2 === $r['new_steps'] && 0 === $r['points'], is_wp_error( $r ) ? $r->get_error_message() : $r );

// Dawarich gets times it can read back: an unencoded "+00:00" means " 00:00" in a query string.
$asked   = '';
$capture = function ( $preempt, $args, $url ) use ( &$asked ) {
	$asked = $url;
	return [ 'headers' => [], 'body' => '[]', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $capture, 10, 3 );
( new DawarichClient( 'http://dawarich.test', 'k' ) )->points( 1749427200, 1749513600 );
remove_filter( 'pre_http_request', $capture, 10 );
parse_str( (string) wp_parse_url( $asked, PHP_URL_QUERY ), $sent );
check( 'Dawarich times survive URL decoding', 1749427200 === strtotime( $sent['start_at'] ?? '' ) && 1749513600 === strtotime( $sent['end_at'] ?? '' ), $asked );
$solo_route = json_decode( (string) get_post_meta( $solo_id, Trips::META_ROUTE, true ), true );
check( 'route drawn from photo positions', is_array( $solo_route ) && count( array_merge( ...$solo_route ) ) >= 5, $solo_route );
$solo_stats = get_post_meta( $solo_id, Trips::META_STATS, true );
// Lisbon → Sintra → Faro is about 25 + 200 km in straight lines, all of it gaps over 20 km.
check( 'distance counts the gaps between photos',( $solo_stats['distance_km'] ?? 0 ) >= 200, $solo_stats );
$solo_steps = Trips::steps( $solo_id, true );
check( 'steps placed from photo GPS', '38.7139' === (string) get_post_meta( $solo_steps[0]->ID ?? 0, Trips::META_LAT, true ) );
check( 'countries come from photos', [ 'PT' ] === ( $solo_stats['countries'] ?? null ), $solo_stats );
foreach ( array_merge( $solo_steps, [ get_post( $solo_id ) ] ) as $post ) {
	wp_delete_post( $post->ID, true );
}

// Countries are counted once each, however the track and the photos name them.
$countries_trip = function ( array $track, array $photos ) {
	$id                 = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Countries', 'post_status' => 'draft' ] );
	$dawarich           = new TrackDawarich( 'http://x', 'k' );
	$dawarich->pts      = $track;
	$photo_source       = new FakeSource();
	$photo_source->rows = $photos;
	update_post_meta( $id, Trips::META_START, '2025-05-01' );
	update_post_meta( $id, Trips::META_END, '2025-05-01' );
	$r     = ( new TripSync( $dawarich, $photo_source ) )->sync( $id );
	$stats = [ get_post_meta( $id, Trips::META_STATS, true )['countries'] ?? null, View::stats( $id ) ];
	foreach ( array_merge( Trips::steps( $id, true ), [ get_post( $id ) ] ) as $post ) {
		wp_delete_post( $post->ID, true );
	}
	return is_wp_error( $r ) ? $r->get_error_message() : $stats;
};
$t0 = strtotime( '2025-05-01 09:00 UTC' );
$r  = $countries_trip(
	[ [ 40.7128, -74.0060, $t0, 'New York', 'United States' ], [ 40.7306, -73.9352, $t0 + 3600, 'New York', 'United States' ] ],
	[ array_merge( row( 30, '2025-05-01', '09:30', 40.7128, -74.0060, 'New York', true ), [ 'country' => 'United States of America' ] ) ]
);
check( 'a country named two ways counts once, and the trip says “1 country”', is_array( $r ) && [ 'US' ] === $r[0] && '1' === ( $r[1]['country'] ?? null ), $r );
$r = $countries_trip(
	[ [ 42.0, -8.6, $t0, 'Valença', 'Portugal' ], [ 42.05, -8.64, $t0 + 3600, 'Tui', 'Spain' ] ],
	[ row( 31, '2025-05-01', '09:30', 42.0, -8.6, 'Valença', true ) ]
);
check( 'two countries named the same way by both still count two', is_array( $r ) && [ 'PT', 'ES' ] === $r[0] && '2' === ( $r[1]['countries'] ?? null ), $r );

// REST: publish the steps, keep the trip a draft.
wp_publish_post( $d1->ID );
wp_publish_post( $d2->ID );
$get = function ( $route, $params = [] ) {
	$req = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	return rest_do_request( $req );
};
wp_set_current_user( 0 );
$list = $get( '/wp/v2/ts_step' );
check( 'anon: no steps of a draft trip in the list', 200 === $list->get_status() && 0 === count( $list->get_data() ), [ $list->get_status(), count( (array) $list->get_data() ) ] );
$one = $get( '/wp/v2/ts_step/' . $d1->ID );
check( 'anon: single step of a draft trip refused', $one->get_status() >= 400, $one->get_status() );
$places = $get( '/wp/v2/ts_place' );
check( 'anon: place terms refused', $places->get_status() >= 400, $places->get_status() );
check( 'anon: no photos of a draft trip', [] === Trips::shown_photo_ids( get_post( $trip_id ) ) );

wp_set_current_user( 1 );
$list = $get( '/wp/v2/ts_step', [ 'parent' => $trip_id, 'status' => 'any', 'context' => 'edit' ] );
check( 'editor: sees steps of the draft trip', 200 === $list->get_status() && 2 === count( $list->get_data() ), [ $list->get_status(), $list->get_data() ] );
check( 'editor: place terms readable', 200 === $get( '/wp/v2/ts_place' )->get_status() );

// Publish the trip; unpublish day 2.
wp_publish_post( $trip_id );
wp_update_post( [ 'ID' => $d2->ID, 'post_status' => 'draft' ] );
wp_set_current_user( 0 );
$list = $get( '/wp/v2/ts_step' );
check( 'anon: published step of a published trip is listed', 1 === count( $list->get_data() ), count( (array) $list->get_data() ) );
$ids = Trips::shown_photo_ids( get_post( $trip_id ) );
check( 'anon: photos only from the published day', isset( $ids[ row( 1, '2025-05-01', '09:00', 0, 0, '', 1 )['id'] ] ) && ! isset( $ids[ row( 5, '2025-05-02', '11:00', 0, 0, '', 1 )['id'] ] ), array_keys( $ids ) );

$trip = $get( '/travel-steps/v1/trips/' . $trip_id );
$data = $trip->get_data();
check( 'trip API: markers 1a/1b for the two places', [ '1a', '1b' ] === array_column( $data['markers'] ?? [], 'label' ), $data );
check( 'trip API: no per-photo data', ! isset( $data['steps'] ) );

// Renders a whole template, as the app would for that URL.
$render = function ( $tpl, $slug ) {
	set_query_var( 'slug', $slug );
	ob_start();
	include '/wordpress/wp-content/plugins/travel-steps/templates/' . $tpl;
	return ob_get_clean();
};

// Cover: an editor can pick any of the trip's photos; it's then public with the trip.
$faro  = row( 5, '2025-05-02', '11:00', 0, 0, '', 1 )['id']; // On day 2, a draft step.
$lis   = row( 1, '2025-05-01', '09:00', 0, 0, '', 1 )['id'];
$cover = function ( $asset ) use ( $trip_id ) {
	$req = new WP_REST_Request( 'POST', '/travel-steps/v1/trips/' . $trip_id . '/cover' );
	$req->set_param( 'asset', $asset );
	return rest_do_request( $req );
};
check( 'cover: automatic is the first shown landscape favourite', false !== strpos( View::cover_url( $trip_id ), $lis ) );
check( 'cover: visitors may not choose one', $cover( $faro )->get_status() >= 400 );
wp_set_current_user( 1 );
$res = $cover( $faro );
check( 'cover: an editor chooses a photo', 200 === $res->get_status() && false !== strpos( $res->get_data()['url'] ?? '', $faro ), [ $res->get_status(), $res->get_data() ] );
check( 'cover: the header uses it', false !== strpos( View::cover_url( $trip_id ), $faro ) );
check( 'cover: only the trip’s own photos', 400 === $cover( '99999999-9999-4999-8999-999999999999' )->get_status() );
wp_set_current_user( 0 );
check( 'cover: visitors can load the chosen cover', isset( Trips::shown_photo_ids( get_post( $trip_id ) )[ $faro ] ) );
wp_set_current_user( 1 );
$html = $render( 'trip.php', (string) $trip_id );
check( 'cover: its button is pressed on the trip page', 1 === preg_match( '/data-asset="' . $faro . '"[^>]*>(?:(?!<\/figure>).)*ts-photo-cover[^>]*aria-pressed="true"/s', $html ) );
$res = $cover( '' );
check( 'cover: pressing again goes back to automatic', 200 === $res->get_status() && false !== strpos( View::cover_url( $trip_id ), $lis ) );
wp_set_current_user( 0 );
check( 'cover: an unchosen draft photo is private again', ! isset( Trips::shown_photo_ids( get_post( $trip_id ) )[ $faro ] ) );

// Photo caching: shared caches must drop a photo soon after it's unpublished or hidden.
$public = TravelSteps\RestController::photo_cache_control( true );
check( 'public photos are not cached as immutable', false === strpos( $public, 'immutable' ), $public );
check( 'shared caches keep public photos 5 minutes at most', 1 === preg_match( '/s-maxage=(\d+)/', $public, $m ) && (int) $m[1] <= 300, $public );
check( 'draft photos stay out of shared caches', 0 === strpos( TravelSteps\RestController::photo_cache_control( false ), 'private' ) );
check( 'revalidation matches the ETag', TravelSteps\RestController::not_modified( '"abc"', 'W/"abc", "def"' ) && ! TravelSteps\RestController::not_modified( '"abc"', '"abd"' ) && ! TravelSteps\RestController::not_modified( '"abc"', '' ) );

// Thumbnail cache: written whole or not at all.
$file = get_temp_dir() . 'ts-cache-test/ab/abc';
check( 'cache stores a thumbnail', TravelSteps\RestController::store_cached( $file, 'IMAGE', 'image/webp' ) && 'IMAGE' === file_get_contents( $file ) && 'image/webp' === file_get_contents( $file . '.type' ) );
check( 'cache leaves no temporary files', [] === glob( dirname( $file ) . '/*.tmp' ) );
$root = get_temp_dir() . 'ts-protect-test';
check( 'protected folders: made as asked', "$root/a/b" === TravelSteps\Uploads::protect( $root, 'a/b' ) && is_dir( "$root/a/b" ) );
check( 'protected folders: a deny rule at the top, and none can be listed', "Require all denied\n" === @file_get_contents( "$root/.htaccess" ) && file_exists( "$root/index.html" ) && file_exists( "$root/a/index.html" ) && file_exists( "$root/a/b/index.html" ) );
check( 'cache skips an empty thumbnail', ! TravelSteps\RestController::store_cached( $file . '-empty', '', 'image/webp' ) && ! file_exists( $file . '-empty' ) );

// Medium photos: Immich's preview scaled down for the grid, never up.
$jpeg = function ( $w, $h ) {
	$im = imagecreatetruecolor( $w, $h );
	ob_start();
	imagejpeg( $im );
	return ob_get_clean();
};
$medium = TravelSteps\RestController::shrink( $jpeg( 1440, 1080 ), 'image/jpeg' );
$dims   = is_wp_error( $medium ) ? [] : getimagesizefromstring( $medium['body'] );
check( 'medium photo is 640px on its short side', [ 853, 640 ] === array_slice( $dims ?: [], 0, 2 ), is_wp_error( $medium ) ? $medium->get_error_message() : $dims );
$portrait = TravelSteps\RestController::shrink( $jpeg( 1080, 1440 ), 'image/jpeg' );
check( 'portrait medium photo too', [ 640, 853 ] === array_slice( getimagesizefromstring( $portrait['body'] ) ?: [], 0, 2 ) );
$small = TravelSteps\RestController::shrink( $jpeg( 300, 200 ), 'image/jpeg' );
check( 'small photos are not enlarged', [ 300, 200 ] === array_slice( getimagesizefromstring( $small['body'] ) ?: [], 0, 2 ) );

// Story rendering: photos under the heading they belong to.
$by_step = Trips::photos_by_step( $trip_id, Trips::steps( $trip_id ) );
ob_start();
View::render_story( get_post( $d1->ID ), array_merge( $by_step[ $d1->ID ]['shown'], $by_step[ $d1->ID ]['rest'] ), function ( $photos ) {
	echo '[GRID ' . implode( ',', array_map( fn( $p ) => (int) substr( $p['id'], -3 ), $photos ) ) . ']';
} );
$html = ob_get_clean();
$lis  = strpos( $html, '>Lisbon</h2>' );
$sin  = strpos( $html, '>Sintra</h2>' );
check( 'story: Lisbon photos after Lisbon heading', false !== $lis && strpos( $html, '[GRID 1,2]' ) > $lis && strpos( $html, '[GRID 1,2]' ) < $sin, $html );
check( 'story: Sintra photos after Sintra heading', false !== $sin && strpos( $html, '[GRID 3,4]' ) > $sin, $html );
check( 'story: written text kept', false !== strpos( $html, 'Tram 28' ) );
check( 'story: Sintra photos sit where its photos block is, before later text', strpos( $html, '[GRID 3,4]' ) < strpos( $html, 'Tram 28' ), $html );
check( 'story: no photos block markup left in the page', false === strpos( $html, 'travel-steps/photos' ) && false === strpos( $html, 'wp-block-travel-steps' ), $html );

// Editor preview of a photos block: the photos of the section it's in, for editors only.
$sintra_t = Days::anchor_times( get_post( $d1->ID )->post_content )[1];
$preview  = function ( $attrs ) use ( $get, $d1 ) {
	return $get( '/wp/v2/block-renderer/' . Days::PHOTOS_BLOCK, [ 'context' => 'edit', 'post_id' => $d1->ID, 'attributes' => $attrs ] );
};
wp_set_current_user( 1 );
$res = $preview( [ 'start' => $sintra_t ] );
$rendered = $res->get_data()['rendered'] ?? '';
check( 'preview: Sintra block shows the Sintra favourite', 200 === $res->get_status() && false !== strpos( $rendered, row( 3, '2025-05-01', '14:00', 0, 0, '', 1 )['id'] ) && false === strpos( $rendered, row( 1, '2025-05-01', '09:00', 0, 0, '', 1 )['id'] ), [ $res->get_status(), $rendered ] );
$rendered = $preview( [ 'start' => 0, 'end' => $sintra_t ] )->get_data()['rendered'] ?? '';
check( 'preview: first block shows the Lisbon favourite', false !== strpos( $rendered, row( 1, '2025-05-01', '09:00', 0, 0, '', 1 )['id'] ) && false === strpos( $rendered, row( 3, '2025-05-01', '14:00', 0, 0, '', 1 )['id'] ), $rendered );
$GLOBALS['post'] = get_post( $d1->ID );
wp_set_current_user( 0 );
check( 'photos block renders nothing for visitors outside the trip page', '' === trim( render_block( [ 'blockName' => Days::PHOTOS_BLOCK, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] ) ) );
wp_set_current_user( 1 );

// Full templates render without errors.
wp_publish_post( $d2->ID );
wp_set_current_user( 1 );
$edit = $render( 'trip-edit.php', (string) $trip_id );
$_GET = [ 'synced' => '1', 'new_steps' => '0', 'skipped_days' => '3' ];
$notice = $render( 'trip-edit.php', (string) $trip_id );
$_GET   = [];
check( 'edit page says how many days were skipped for the trash', false !== strpos( $notice, '3 days with favourites were skipped' ) && false !== strpos( $notice, 'post_status=trash' ), substr( $notice, strpos( $notice, 'ts-notice' ) ?: 0, 400 ) );
$limits = $render( 'trip-edit.php', (string) $trip_id );
check( 'edit page notes the sync limits', false !== strpos( $limits, 'at most 20,000 photos per trip' ), '' );
check( 'without Dawarich, the limits leave out location points', false === strpos( $limits, 'location points per trip' ) && false !== strpos( $limits, 'far too big to show online' ) );
check( 'edit page renders step list', false !== strpos( $edit, 'ts-step-list' ), [ 'len' => strlen( $edit ), 'notfound' => false !== strpos( $edit, 'Trip not found' ), 'nosteps' => false !== strpos( $edit, 'No steps yet' ), 'panel' => substr_count( $edit, 'ts-panel' ), 'tail' => substr( preg_replace( '/\s+/', ' ', strip_tags( substr( $edit, strpos( $edit, '<main' ) ?: 0 ) ) ), 0, 900 ) ] );
$html = $render( 'trip.php', (string) $trip_id );
check( 'published trip: no draft warning', false === strpos( $html . $edit, 'This trip isn’t public' ) );
wp_update_post( [ 'ID' => $trip_id, 'post_status' => 'draft' ] );
check( 'draft trip with published steps: trip page warns', false !== strpos( $render( 'trip.php', (string) $trip_id ), 'This trip isn’t public' ) );
check( 'draft trip with published steps: edit page warns', false !== strpos( $render( 'trip-edit.php', (string) $trip_id ), 'This trip isn’t public' ) );
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, [ 'post_status' => 'future' ], [ 'ID' => $trip_id ] );
clean_post_cache( $trip_id );
check( 'scheduled trip: no warning, its steps go public with it', false === strpos( $render( 'trip-edit.php', (string) $trip_id ), 'This trip isn’t public' ) );
wp_update_post( [ 'ID' => $trip_id, 'post_status' => 'publish' ] );
check( 'editor trip page: place tags', false !== strpos( $html, 'class="ts-places"' ) && false !== strpos( $html, '<li>Sintra</li>' ), substr( $html, 0, 300 ) );
check( 'editor trip page: Day 2 shown', false !== strpos( $html, 'Day 2' ) );
// Day 1 shows its two favourites; the expander holds only the other two.
preg_match_all( '#<summary>\s*(.*?)\s*</summary>#s', $html, $summaries );
check( 'expander counts only the photos it reveals', 'Show 2 more photos' === ( $summaries[1][0] ?? '' ), $summaries[1] );
check( 'editor trip page: block editor opens in a new tab', 1 === preg_match( '/<a href="[^"]*post\.php\?post=\d+&(amp;|#038;)?action=edit" target="_blank" rel="noopener">/', $html ) );
check( 'editor trip page: photo grid under heading', preg_match( '/Sintra<\/h2>.*?ts-photos/s', $html ) === 1 );
wp_set_current_user( 0 );
$html = $render( 'trip.php', (string) $trip_id );
check( 'anon trip page renders steps', 2 === substr_count( $html, 'class="ts-step"' ), substr_count( $html, 'class="ts-step"' ) );
check( 'anon trip page has no edit tools', false === strpos( $html, 'ts-photo-state' ) );
ob_start();
do_action( wp_app_get_scoped_hook_name( 'wp_app_head_styles', 'travel-steps' ) );
$styles  = ob_get_clean();
$leaflet = wp_scripts()->registered['leaflet']->src ?? '';
check( 'trip page loads Leaflet from the plugin, not a CDN', str_ends_with( $leaflet, 'travel-steps/assets/vendor/leaflet/leaflet.js' ) && false !== strpos( $styles, 'travel-steps/assets/vendor/leaflet/leaflet.css' ) && false === strpos( $styles, 'unpkg.com' ), [ $leaflet, $styles ] );

// All-digit slugs would read as post IDs in trip URLs.
wp_set_current_user( 1 );
$year = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => '2025', 'post_status' => 'publish' ] );
check( 'a trip titled 2025 gets a slug that is not all digits', ! ctype_digit( get_post( $year )->post_name ), get_post( $year )->post_name );
check( 'its URL finds it', $year === Trips::find_by_slug( basename( Trips::url( get_post( $year ) ) ) )?->ID, Trips::url( get_post( $year ) ) );
$draft = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'No slug yet', 'post_status' => 'draft' ] );
check( 'a draft without a slug is found by ID', $draft === Trips::find_by_slug( (string) $draft )?->ID );
global $wpdb;
$wpdb->update( $wpdb->posts, [ 'post_name' => (string) $draft ], [ 'ID' => $year ] ); // A slug saved before this fix.
clean_post_cache( $year );
check( 'an existing all-digit slug still finds its trip', $year === Trips::find_by_slug( (string) $draft )?->ID );

// Edit page: the album list is quick, cached, and never drops a saved album.
$album_calls = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$album_calls ) {
	if ( false === strpos( $url, '/api/albums' ) ) {
		return $pre;
	}
	$album_calls[] = $args['timeout'];
	$body          = [ [ 'id' => '11111111-1111-4111-8111-111111111111', 'albumName' => 'Portugal', 'assetCount' => 3 ] ];
	return [ 'headers' => [], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
}, 10, 3 );
$client = new ImmichClient( 'http://immich.test', 'key' );
$first  = $client->albums();
$client->albums();
check( 'albums are listed', ! is_wp_error( $first ) && 'Portugal' === $first[0]['name'], $first );
check( 'albums are asked for once, then cached', 1 === count( $album_calls ), $album_calls );
check( 'albums request gives up quickly', ( $album_calls[0] ?? 99 ) <= 5, $album_calls );
( new ImmichClient( 'http://other.test', 'key' ) )->albums();
check( 'another Immich server is asked separately', 2 === count( $album_calls ), $album_calls );

// Keys are write-only: blank keeps the saved one, and only "Remove" clears it.
$saved = array_merge( TravelSteps\Settings::ACCOUNT_DEFAULTS, [ 'dawarich_key' => 'dkey', 'immich_key' => 'ikey' ] );
$kept  = TravelSteps\Settings::sanitize_account( [ 'dawarich_key' => '', 'immich_key' => 'new' ], $saved );
check( 'a blank key field keeps the saved key', 'dkey' === $kept['dawarich_key'] && 'new' === $kept['immich_key'], $kept );
$gone = TravelSteps\Settings::sanitize_account( [ 'dawarich_key' => '', 'dawarich_key_remove' => '1' ], $saved );
check( 'remove clears a saved key, and only that one', '' === $gone['dawarich_key'] && 'ikey' === $gone['immich_key'], $gone );

// Home: a "lat, lon" pair pasted from a map is split; anything else unreadable is an error.
$saved  = array_merge( $saved, [ 'home_lat' => '53.3', 'home_lon' => '-6.2' ] );
$errors = [];
$home   = TravelSteps\Settings::sanitize_account( [ 'home_lat' => ' 51.8985, -8.4756 ', 'home_lon' => '' ], $saved, $errors );
check( 'pasted lat, lon pair is split', '51.8985' === $home['home_lat'] && '-8.4756' === $home['home_lon'] && ! $errors, [ $home['home_lat'], $home['home_lon'], $errors ] );
$errors = [];
$home   = TravelSteps\Settings::sanitize_account( [ 'home_lat' => '51°53′N', 'home_lon' => '8°28′W' ], $saved, $errors );
check( 'unreadable home is an error and keeps the saved one', [ 'home' ] === $errors && '53.3' === $home['home_lat'] && '-6.2' === $home['home_lon'], [ $home['home_lat'], $home['home_lon'], $errors ] );
$errors = [];
$home   = TravelSteps\Settings::sanitize_account( [ 'home_lat' => '51.8985', 'home_lon' => '' ], $saved, $errors );
check( 'half a home is an error', [ 'home' ] === $errors && '53.3' === $home['home_lat'], $errors );
foreach ( [ [ '48,85', '' ], [ '48,85', '2,35' ], [ '53,35', '-6,26' ] ] as [ $lat, $lon ] ) {
	$errors = [];
	$home   = TravelSteps\Settings::sanitize_account( [ 'home_lat' => $lat, 'home_lon' => $lon ], $saved, $errors );
	check( "decimal comma ($lat / $lon) is an error, not a pair", [ 'home' ] === $errors && '53.3' === $home['home_lat'], [ $home['home_lat'], $home['home_lon'], $errors ] );
}
foreach ( [ '51.8985,-8.4756', '51.8985 -8.4756', '51.8985; -8.4756' ] as $pair ) {
	$errors = [];
	$home   = TravelSteps\Settings::sanitize_account( [ 'home_lat' => $pair, 'home_lon' => '' ], $saved, $errors );
	check( "pair \"$pair\" is split", '51.8985' === $home['home_lat'] && '-8.4756' === $home['home_lon'] && ! $errors, [ $home['home_lat'], $home['home_lon'], $errors ] );
}
$errors = [];
$home   = TravelSteps\Settings::sanitize_account( [ 'home_lat' => '', 'home_lon' => '' ], $saved, $errors );
check( 'emptying both clears home', '' === $home['home_lat'] && '' === $home['home_lon'] && ! $errors, $errors );

update_option( TravelSteps\Settings::OPTION, array_merge( (array) get_option( TravelSteps\Settings::OPTION, [] ), [ 'immich_url' => 'http://immich.test' ] ) );
TravelSteps\Settings::update_account( 1, [ 'immich_key' => 'admin-immich-key' ] + TravelSteps\Settings::ACCOUNT_DEFAULTS );
// The trips list runs oldest first by start date, with undated drafts on top.
$later   = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Later trip', 'post_status' => 'publish' ] );
$earlier = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Earlier trip', 'post_status' => 'publish' ] );
$undated = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Undated trip', 'post_status' => 'draft' ] );
update_post_meta( $later, Trips::META_START, '2026-09-13' );
update_post_meta( $earlier, Trips::META_START, '2026-09-04' );
$list  = $render( 'index.php', '' );
$order = array_map( fn( $t ) => strpos( $list, $t ), [ 'Undated trip', 'Earlier trip', 'Later trip' ] );
check( 'trips list: undated, then oldest start first', ! in_array( false, $order, true ) && $order === array_values( array_unique( $order ) ) && $order[0] < $order[1] && $order[1] < $order[2], $order );
foreach ( [ $later, $earlier, $undated ] as $id ) {
	wp_delete_post( $id, true );
}

// A bad fix just before leaving home is caught: the home fixes around it are witnesses.
// Home is the trip author's (Lisbon), not that of whoever syncs (Dublin).
$traveller = wp_insert_user( [ 'user_login' => 'traveller', 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
TravelSteps\Settings::update_account( $traveller, [ 'home_lat' => '38.7139', 'home_lon' => '-9.1394', 'home_radius_km' => 1 ] + TravelSteps\Settings::ACCOUNT_DEFAULTS );
TravelSteps\Settings::update_account( 1, [ 'home_lat' => '53.35', 'home_lon' => '-6.26', 'home_radius_km' => 1 ] + TravelSteps\Settings::account( 1 ) );
$away_id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Day trip', 'post_status' => 'draft', 'post_author' => $traveller ] );
update_post_meta( $away_id, Trips::META_START, '2025-05-01' );
update_post_meta( $away_id, Trips::META_END, '2025-05-01' );
$r          = ( new TripSync( new HomeDawarich( 'http://x', 'k' ), $source ) )->sync( $away_id );
$away_stats = get_post_meta( $away_id, Trips::META_STATS, true );
check( 'a bad fix at home is dropped before home is cut out', ! is_wp_error( $r ) && [ 'PT' ] === ( $away_stats['countries'] ?? null ) && ( $away_stats['distance_km'] ?? 999 ) < 50, [ is_wp_error( $r ) ? $r->get_error_message() : $r, $away_stats ] );
check( 'sync cuts out the trip author’s home, not the home of whoever syncs', ! is_wp_error( $r ) && $r['points'] <= 12, is_wp_error( $r ) ? $r->get_error_message() : $r );
$away_ids = array_column( Trips::photos( $away_id ), 'id' );
check( 'an Immich trip still leaves out the photos taken at home, and doesn’t count them', [ row( 3, '2025-05-01', '14:00', 0, 0, '', 1 )['id'], row( 4, '2025-05-01', '14:20', 0, 0, '', 1 )['id'] ] === $away_ids && ! isset( $r['at_home'] ), [ $away_ids, $r ] );
foreach ( array_merge( Trips::steps( $away_id, true ), [ get_post( $away_id ) ] ) as $post ) {
	wp_delete_post( $post->ID, true );
}
TravelSteps\Settings::update_account( 1, [ 'home_lat' => '', 'home_lon' => '' ] + TravelSteps\Settings::account( 1 ) );

// A synced trip whose photos have no favourites says why it has no steps.
$plain_id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'No favourites', 'post_status' => 'draft' ] );
update_post_meta( $plain_id, Trips::META_PHOTOS, wp_slash( wp_json_encode( [ row( 1, '2025-05-01', '09:00', 38.7, -9.1, 'Lisbon', false ), row( 2, '2025-05-01', '10:00', 38.7, -9.1, 'Lisbon', false ) ] ) ) );
update_post_meta( $plain_id, Trips::META_SYNCED, time() );
$page = $render( 'trip-edit.php', (string) $plain_id );
check( 'no favourites: edit page explains why there are no steps', false !== strpos( $page, 'None of the 2 photos' ), substr( $page, strpos( $page, '<h1' ) ?: 0, 600 ) );
update_post_meta( $plain_id, Trips::META_PHOTOS, wp_slash( wp_json_encode( [ row( 1, '2025-05-01', '09:00', 38.7, -9.1, 'Lisbon', false ) ] ) ) );
check( 'no favourites, one photo: reads naturally', false !== strpos( $render( 'trip-edit.php', (string) $plain_id ), 'only photo isn’t a favourite' ) );
check( 'favourites present: no warning about them', false === strpos( $edit, 'a favourite in Immich' ) && false === strpos( $edit, 'isn’t a favourite' ) );
wp_delete_post( $plain_id, true );

$_GET = [ 'invalid' => 'home' ];
check( 'settings page explains an unreadable home', false !== strpos( $render( 'settings.php', '' ), 'Home location not saved' ) );
$_GET = [];
$page = $render( 'settings.php', '' );
check( 'remove checkbox only for a saved key', false !== strpos( $page, 'name="immich_key_remove"' ) && false === strpos( $page, 'name="dawarich_key_remove"' ), substr( $page, 0, 300 ) );
$gone = '22222222-2222-4222-8222-222222222222';
update_post_meta( $trip_id, Trips::META_ALBUM, $gone );
wp_set_current_user( 1 );
$edit = $render( 'trip-edit.php', (string) $trip_id );
check( 'a saved album missing from Immich stays selected', 1 === preg_match( '/<option value="' . $gone . '"[^>]*selected/', $edit ), substr( $edit, strpos( $edit, '<select name="photos"' ) ?: 0, 600 ) );

// Only users with the travel-steps capability may use the site's Immich and Dawarich
// credentials: a contributor can't create or sync a trip.
$contributor = wp_insert_user( [ 'user_login' => 'contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
$own_trip    = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Mine', 'post_status' => 'draft', 'post_author' => $contributor ] );
$stopped     = function ( callable $handler ) {
	$die      = fn() => function () { throw new RuntimeException( 'die' ); };
	$redirect = function ( $location ) { throw new RuntimeException( 'redirect' ); };
	add_filter( 'wp_die_handler', $die );
	add_filter( 'wp_redirect', $redirect );
	try {
		$handler();
		$result = 'returned';
	} catch ( RuntimeException $e ) {
		$result = $e->getMessage();
	}
	remove_filter( 'wp_die_handler', $die );
	remove_filter( 'wp_redirect', $redirect );
	return $result;
};
wp_set_current_user( $contributor );
check( 'contributor owns their trip', current_user_can( 'edit_post', $own_trip ) );
$_POST = $_REQUEST = [ 'trip_id' => $own_trip, '_wpnonce' => wp_create_nonce( 'travel_steps_sync' ) ];
$synced = get_post_meta( $own_trip, Trips::META_SYNCED, true );
check( 'contributor cannot sync a trip', 'die' === $stopped( [ new Trips(), 'handle_sync' ] ) && $synced === get_post_meta( $own_trip, Trips::META_SYNCED, true ) );
$_POST = $_REQUEST = [ 'trip_id' => 0, 'title' => 'Sneaky', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
$before = count( get_posts( [ 'post_type' => Trips::TRIP, 'post_status' => 'any', 'posts_per_page' => -1 ] ) );
check( 'contributor cannot create a trip', 'die' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && $before === count( get_posts( [ 'post_type' => Trips::TRIP, 'post_status' => 'any', 'posts_per_page' => -1 ] ) ) );
$rest_post = function ( $route, $params ) {
	$req = new WP_REST_Request( 'POST', $route );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	return rest_do_request( $req );
};
$own_photo = '00000000-0000-4000-8000-000000000001';
check( 'contributor cannot pin, hide or choose the cover of their trip', 403 === $rest_post( "/travel-steps/v1/trips/$own_trip/photo-state", [ 'asset' => $own_photo, 'state' => 'hidden' ] )->get_status() && 403 === $rest_post( "/travel-steps/v1/trips/$own_trip/cover", [ 'asset' => '' ] )->get_status() );
check( 'contributor’s trip page offers no Edit trip button', false === strpos( $render( 'trip.php', (string) $own_trip ), 'Edit trip' ) );
$_POST = $_REQUEST = [];
wp_set_current_user( 1 );
check( 'administrators have the travel-steps capability', current_user_can( Trips::capability() ) );
add_filter( 'travel_steps_capability', fn() => 'edit_posts' );
wp_set_current_user( $contributor );
check( 'the capability can be widened with a filter', current_user_can( Trips::capability() ) );
remove_all_filters( 'travel_steps_capability' );
wp_set_current_user( 1 );

// Trip photos: uploaded from the trip's photo modal, stored privately and named by a UUID.
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$photos_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Uploads', 'post_status' => 'publish' ] );
$other_post  = wp_insert_post( [ 'post_title' => 'A post', 'post_status' => 'publish' ] );
$as_photo    = [ 'post_id' => $photos_trip, TravelSteps\Uploads::REQUEST_FLAG => '1' ];
require_once '/smoke/exif-fixture.php';
// Big enough that core keeps the original and makes a "-scaled" copy to use.
$sideload    = function ( array $request, int $post_id, string $name, string $bytes ) {
	$_REQUEST = $request;
	$tmp      = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	$id       = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $post_id );
	$_REQUEST = [];
	return $id;
};
$upload      = fn( array $request, int $post_id, int $width = 3000, int $height = 2000, array $tags = [] ) => $sideload( $request, $post_id, 'IMG_1234.jpg', ts_tagged_jpeg( $jpeg( $width, $height ), $tags ) );
$is_media = fn( $id ) => is_int( $id ) && 0 !== strpos( (string) get_post_meta( $id, '_wp_attached_file', true ), 'travel-steps/' ) && '' === TravelSteps\Uploads::photo_id( $id ) && 'inherit' === get_post( $id )->post_status;
$base     = wp_get_upload_dir()['basedir'];
$photo    = $upload( $as_photo, $photos_trip );
$photo_id = is_wp_error( $photo ) ? '' : TravelSteps\Uploads::photo_id( $photo );
$dir      = "$base/travel-steps/$photos_trip";
check( 'trip photo: gets a random UUID as its photo id', wp_is_uuid( $photo_id, 4 ), is_wp_error( $photo ) ? $photo->get_error_message() : $photo_id );
$stored = (string) get_post_meta( $photo, '_wp_attached_file', true );
$name   = basename( $stored, '-scaled.jpg' );
// The photo id is public in proxy URLs, so it mustn't lead to the file (nginx ignores the deny rule).
check( 'trip photo: stored in the trip’s folder under a random name that isn’t its id', wp_is_uuid( $name, 4 ) && "travel-steps/$photos_trip/$name-scaled.jpg" === $stored && "$name.jpg" === ( wp_get_attachment_metadata( $photo )['original_image'] ?? '' ) && false === strpos( $stored, $photo_id ), $stored );
$sizes = wp_list_pluck( wp_get_attachment_metadata( $photo )['sizes'] ?? [], 'file' );
$files = array_map( 'basename', glob( "$dir/*" ) ?: [] );
check( 'trip photo: its sizes are beside it, under the same name', $sizes && ! array_diff( $sizes, $files ) && [ 'index.html' ] === array_values( preg_grep( "/^$name\\b/", $files, PREG_GREP_INVERT ) ), [ $sizes, $files ] );
check( 'trip photo: its folders have a deny rule and can’t be listed', "Require all denied\n" === @file_get_contents( "$base/travel-steps/.htaccess" ) && file_exists( "$base/travel-steps/index.html" ) && file_exists( "$dir/index.html" ) );
check( 'trip photo: a private attachment', 'private' === get_post( $photo )->post_status );
check( 'trip photo: titled by the name it was uploaded with, not its file name', 'IMG_1234' === get_post( $photo )->post_title && $photo_id === get_post( $photo )->post_name, [ get_post( $photo )->post_title, get_post( $photo )->post_name ] );
wp_set_current_user( 0 );
check( 'trip photo: visitors can’t read it from core REST', $get( '/wp/v2/media/' . $photo )->get_status() >= 400 );
wp_set_current_user( 1 );
$described = $upload( [ 'post_id' => $photos_trip ], $photos_trip );
check( 'an image for the trip’s description (no photo flag) is ordinary media', $is_media( $described ), is_wp_error( $described ) ? $described->get_error_message() : get_post_meta( $described, '_wp_attached_file', true ) );
$posted = $upload( [ 'post_id' => $other_post ], $other_post );
check( 'an upload to a post is ordinary media', $is_media( $posted ) );
$loose = $upload( [], 0 );
check( 'an upload to nothing is ordinary media', $is_media( $loose ) );
check( 'a photo flag on a post that isn’t a trip is refused', is_wp_error( $upload( [ 'post_id' => $other_post, TravelSteps\Uploads::REQUEST_FLAG => '1' ], $other_post ) ) );
wp_set_current_user( $contributor );
check( 'a trip photo from someone who can’t edit trips is refused', is_wp_error( $upload( $as_photo, $photos_trip ) ) );
wp_set_current_user( 1 );

// Media listings: the trip's photo modal shows its photos, nothing else shows them.
$modal = function ( array $asked ) {
	// As core does: the raw query is in the request, only known keys reach the filter,
	// and private attachments are listed only for users who can read others' private posts.
	$status   = current_user_can( get_post_type_object( 'attachment' )->cap->read_private_posts ) ? 'inherit,private' : 'inherit';
	$_REQUEST = [ 'query' => $asked ];
	$args     = apply_filters( 'ajax_query_attachments_args', array_intersect_key( $asked, [ 'post_parent' => 1 ] ) + [ 'post_type' => 'attachment', 'post_status' => $status, 'posts_per_page' => -1, 'fields' => 'ids' ] );
	$_REQUEST = [];
	return ( new WP_Query( $args ) )->posts;
};
$library = $modal( [] );
check( 'Media Library: ordinary media, no trip photos', ! in_array( $photo, $library, true ) && in_array( $described, $library, true ) && in_array( $loose, $library, true ), $library );
check( 'the trip’s own media modal: no trip photos', [ $described ] === $modal( [ 'post_parent' => $photos_trip ] ) );
check( 'the trip’s photo modal: only its photos', [ $photo ] === $modal( [ 'post_parent' => $photos_trip, TravelSteps\Uploads::REQUEST_FLAG => '1' ] ) );
check( 'a photo modal for something that isn’t a trip: nothing', [] === $modal( [ 'post_parent' => $other_post, TravelSteps\Uploads::REQUEST_FLAG => '1' ] ) );
$listed = wp_list_pluck( $get( '/wp/v2/media' )->get_data(), 'id' );
check( 'REST media list: no trip photos, even for editors', ! in_array( $photo, $listed, true ) && in_array( $loose, $listed, true ) );
$urls = wp_prepare_attachment_for_js( $photo );
$js   = wp_json_encode( $urls, JSON_UNESCAPED_SLASHES );
check( 'trip photo: the modal loads it through the photo proxy', false !== strpos( $urls['url'], "/travel-steps/v1/trips/$photos_trip/photo/$photo_id" ) && false !== strpos( $urls['sizes']['thumbnail']['url'] ?? '', "/photo/$photo_id" ) && false === strpos( $js, wp_get_upload_dir()['baseurl'] . '/travel-steps/' ) && false === strpos( $js, $name ), $js );
$xmlrpc = apply_filters( 'xmlrpc_prepare_media_item', [ 'link' => wp_get_attachment_url( $photo ), 'thumbnail' => wp_get_attachment_url( $photo ), 'metadata' => wp_get_attachment_metadata( $photo ) ], get_post( $photo ), 'thumbnail' );
check( 'trip photo: XML-RPC gives no URL or file names for it', [ '', '', [] ] === [ $xmlrpc['link'], $xmlrpc['thumbnail'], $xmlrpc['metadata'] ], $xmlrpc );

// An author allowed to make trips sees their own trip photos, though core lists
// private attachments only to editors.
$author = wp_insert_user( [ 'user_login' => 'author', 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
add_filter( 'travel_steps_capability', fn() => 'upload_files' );
wp_set_current_user( $author );
$authors_trip  = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'An author’s trip', 'post_status' => 'draft' ] );
$authors_photo = $upload( [ 'post_id' => $authors_trip, TravelSteps\Uploads::REQUEST_FLAG => '1' ], $authors_trip, 1600 );
check( 'an author’s trip photo modal lists their photo', is_int( $authors_photo ) && [ $authors_photo ] === $modal( [ 'post_parent' => $authors_trip, TravelSteps\Uploads::REQUEST_FLAG => '1' ] ), is_wp_error( $authors_photo ) ? $authors_photo->get_error_message() : $authors_photo );
wp_set_current_user( 1 );
remove_all_filters( 'travel_steps_capability' );
wp_delete_post( $authors_trip, true );

// A REST upload names its parent in the body, not post_id; it still belongs to the trip whose folder it's in.
$_REQUEST = [ 'post_id' => $photos_trip, TravelSteps\Uploads::REQUEST_FLAG => '1' ];
$req      = new WP_REST_Request( 'POST', '/wp/v2/media' );
$req->set_header( 'Content-Type', 'image/jpeg' );
$req->set_header( 'Content-Disposition', 'attachment; filename=IMG_5678.jpg' );
$req->set_body( $jpeg( 800, 600 ) );
$res      = rest_do_request( $req );
$_REQUEST = [];
$rest_photo = (int) ( $res->get_data()['id'] ?? 0 );
check( 'a REST trip photo upload belongs to the trip', 201 === $res->get_status() && $photos_trip === (int) get_post( $rest_photo )->post_parent && '' !== TravelSteps\Uploads::photo_id( $rest_photo ), [ $res->get_status(), $res->get_data()['message'] ?? '' ] );

// What a trip photo says about itself, read from the original, which is never served:
// only copies with no GPS in them are. Imagick keeps EXIF in the copies core makes.
check( 'the plugin’s Imagick editor stands in for core’s', [ TravelSteps\ImagickEditor::class, 'WP_Image_Editor_GD' ] === apply_filters( 'wp_image_editors', [ 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ] ) );
// Playground's Imagick reports its version as "@PACKAGE_VERSION@", so core's test() turns it down.
trait InPlayground {
	public static function test( $args = [] ) {
		return class_exists( 'Imagick', false );
	}
}
class PlaygroundImagick extends TravelSteps\ImagickEditor {
	use InPlayground;
}
class CoreImagick extends WP_Image_Editor_Imagick {
	use InPlayground;
}
$editor = PlaygroundImagick::class;
$use    = function () use ( &$editor ) {
	return [ $editor ];
};
add_filter( 'wp_image_editors', $use, 20 );
$upload_photo = fn( array $tags, int $width = 3000, int $height = 2000 ) => $upload( $as_photo, $photos_trip, $width, $height, $tags );
$gps_in = function ( string $file ) {
	$exif = @exif_read_data( $file );
	return is_array( $exif ) && isset( $exif['GPSLatitude'] );
};
// Every file core made from an upload: its sizes, and the "-scaled" copy it uses as the attachment's file.
$derived = function ( int $id ) {
	$dir = dirname( get_attached_file( $id ) );
	return array_merge( [ get_attached_file( $id ) ], array_map( fn( $s ) => "$dir/{$s['file']}", wp_get_attachment_metadata( $id )['sizes'] ?? [] ) );
};
// What the proxy would send for each size: a file, or bytes made on demand.
$served = function ( int $id ) {
	$images = [];
	foreach ( TravelSteps\RestController::SIZES as $size ) {
		$images[ $size ] = TravelSteps\Uploads::image( $id, $size );
	}
	return $images;
};
$served_gps = function ( array $images ) use ( $gps_in ) {
	$found = [];
	foreach ( $images as $size => $image ) {
		if ( is_wp_error( $image ) ) {
			$found[] = $size;
			continue;
		}
		$file = $image['file'] ?? get_temp_dir() . 'ts-served-' . $size;
		if ( isset( $image['body'] ) ) {
			file_put_contents( $file, $image['body'] );
		}
		if ( $gps_in( $file ) ) {
			$found[] = $size;
		}
	}
	return $found;
};
$doolin = [ 'make' => 'Apple', 'model' => 'iPhone 15 Pro', 'taken' => '2025:05:01 14:03:22', 'offset' => '+01:00', 'lat' => 53.0219, 'lon' => -9.3792 ];

$editor = CoreImagick::class;
$leaky  = $upload_photo( $doolin );
check( 'core’s Imagick editor keeps GPS in the copies it makes (so the checks below can fail)', is_int( $leaky ) && $gps_in( get_attached_file( $leaky ) ), is_wp_error( $leaky ) ? $leaky->get_error_message() : get_attached_file( $leaky ) );
check( 'a copy with GPS in it is never served', is_wp_error( TravelSteps\Uploads::image( $leaky, 'preview' ) ) );
$guard = fn( string $body ) => TravelSteps\Uploads::has_gps( [ 'body' => $body, 'type' => 'image/jpeg' ] );
check( 'GPS guard: finds GPS in EXIF', $guard( ts_tagged_jpeg( $jpeg( 30, 20 ), $doolin ) ) );
check( 'GPS guard: finds GPS in XMP', $guard( ts_tagged_jpeg( $jpeg( 30, 20 ), [ 'xmp_lat' => '53,1.314N', 'xmp_lon' => '9,22.752W' ] ) ) );
check( 'GPS guard: doesn’t trust EXIF it can’t read', $guard( ts_app1( $jpeg( 30, 20 ), "Exif\0\0\xFF\xFF" ) ) );
$png = ( function () {
	ob_start();
	imagepng( imagecreatetruecolor( 30, 20 ) );
	return ob_get_clean();
} )();
// A PNG's EXIF is a chunk after its header, with no "Exif" marker and nothing exif_read_data() reads.
$exif_chunk = "II*\0" . pack( 'V', 8 );
check( 'GPS guard: doesn’t trust EXIF in a PNG', TravelSteps\Uploads::has_gps( [ 'body' => substr( $png, 0, 33 ) . pack( 'N', strlen( $exif_chunk ) ) . 'eXIf' . $exif_chunk . pack( 'N', crc32( 'eXIf' . $exif_chunk ) ) . substr( $png, 33 ), 'type' => 'image/png' ] ) && ! TravelSteps\Uploads::has_gps( [ 'body' => $png, 'type' => 'image/png' ] ) );
check( 'GPS guard: lets through a photo without EXIF, or with EXIF but no GPS', ! $guard( $jpeg( 30, 20 ) ) && ! $guard( ts_tagged_jpeg( $jpeg( 30, 20 ), array_diff_key( $doolin, [ 'lat' => 1, 'lon' => 1 ] ) ) ) );

$editor = PlaygroundImagick::class;
$tagged = $upload_photo( $doolin );
$meta   = get_post_meta( $tagged, TravelSteps\Uploads::META_EXIF, true );
check( 'trip photo: capture time, offset and camera stored', is_array( $meta ) && [ '2025-05-01 14:03:22', '+01:00', 'Apple iPhone 15 Pro' ] === [ $meta['taken'], $meta['offset'], $meta['camera'] ], $meta );
check( 'trip photo: its position stored', is_array( $meta ) && abs( $meta['lat'] - 53.0219 ) < 1e-6 && abs( $meta['lon'] + 9.3792 ) < 1e-6, $meta );
$original = (string) wp_get_original_image_path( $tagged );
check( 'trip photo: the original is untouched', $gps_in( $original ) && $original !== get_attached_file( $tagged ), $original );
check( 'trip photo: no copy core made has GPS', [] === array_filter( $derived( $tagged ), $gps_in ), array_map( 'basename', array_filter( $derived( $tagged ), $gps_in ) ) );
$images = $served( $tagged );
check( 'trip photo: every size is served, none of them the original', [] === array_filter( $images, 'is_wp_error' ) && ! in_array( $original, array_column( $images, 'file' ), true ), array_map( fn( $i ) => is_wp_error( $i ) ? $i->get_error_message() : basename( $i['file'] ?? 'bytes' ), $images ) );
check( 'trip photo: no size served has GPS', [] === $served_gps( $images ), $served_gps( $images ) );
check( 'trip photo: each of core’s copies served is checked once', count( (array) get_post_meta( $tagged, TravelSteps\Uploads::META_CHECKED, true ) ) === count( array_filter( array_column( $images, 'file' ) ) ), get_post_meta( $tagged, TravelSteps\Uploads::META_CHECKED, true ) );
$turned = $upload_photo( $doolin + [ 'orientation' => 6 ], 900, 600 );
check( 'trip photo turned on its side: WordPress has its size as displayed', [ 600, 900 ] === [ wp_get_attachment_metadata( $turned )['width'] ?? 0, wp_get_attachment_metadata( $turned )['height'] ?? 0 ], wp_get_attachment_metadata( $turned ) );
// Too small for a preview or medium of core's, so the proxy makes them, upright.
$upright = array_map( fn( $i ) => array_slice( getimagesizefromstring( $i['body'] ?? '' ) ?: [], 0, 2 ), array_intersect_key( $served( $turned ), [ 'medium' => 1, 'preview' => 1 ] ) );
check( 'trip photo turned on its side: sizes the proxy makes are upright too', [ 'medium' => [ 600, 900 ], 'preview' => [ 600, 900 ] ] === $upright, $upright );
$preview = TravelSteps\Uploads::image( $tagged, 'preview' );
file_put_contents( $preview['file'], ts_tagged_jpeg( $jpeg( 40, 30 ), $doolin ) );
check( 'a checked copy that changes on disk is checked again', is_wp_error( TravelSteps\Uploads::image( $tagged, 'preview' ) ) );
check( 'trip photo: Imagick reads the same metadata as exif_read_data()', TravelSteps\Exif::parse( exif_read_data( $original ) ) === TravelSteps\Exif::parse( TravelSteps\Exif::imagick( $original ) ), TravelSteps\Exif::imagick( $original ) );

add_filter( 'image_strip_meta', '__return_false' );
$unstripped = $upload_photo( $doolin );
remove_filter( 'image_strip_meta', '__return_false' );
check( 'trip photo: no GPS in copies even if image_strip_meta is off', is_int( $unstripped ) && [] === array_filter( $derived( $unstripped ), $gps_in ) && [] === $served_gps( $served( $unstripped ) ) );

$small  = $upload_photo( $doolin, 800, 600 );
$images = $served( $small );
check( 'small trip photo: a preview is made for it, as big as the photo and without GPS', isset( $images['preview']['body'] ) && [ 800, 600 ] === array_slice( getimagesizefromstring( $images['preview']['body'] ) ?: [], 0, 2 ) && [] === $served_gps( $images ), array_map( fn( $i ) => is_wp_error( $i ) ? $i->get_error_message() : array_keys( $i ), $images ) );

$exif_of = fn( array $tags ) => get_post_meta( $upload_photo( $tags, 600, 400 ), TravelSteps\Uploads::META_EXIF, true );
$meta   = $exif_of( array_diff_key( $doolin, [ 'offset' => 1 ] ) );
check( 'trip photo without an offset: its time, and no offset', '2025-05-01 14:03:22' === ( $meta['taken'] ?? null ) && '' === $meta['offset'], $meta );
$meta = $exif_of( array_diff_key( $doolin, [ 'lat' => 1, 'lon' => 1 ] ) );
check( 'trip photo without GPS: no position', is_array( $meta ) && null === $meta['lat'] && null === $meta['lon'] && '' !== $meta['taken'], $meta );
$meta = $exif_of( array_diff_key( $doolin, [ 'taken' => 1, 'offset' => 1 ] ) );
check( 'trip photo without a capture time: no time', is_array( $meta ) && '' === $meta['taken'] && '' === $meta['offset'] && null !== $meta['lat'], $meta );
remove_filter( 'wp_image_editors', $use, 20 );

// Editors can see a photo as soon as it's uploaded, before a sync lists it.
$tagged_id = TravelSteps\Uploads::photo_id( $tagged );
check( 'an editor can fetch a trip photo before it’s synced', '' !== TravelSteps\RestController::access( $photos_trip, $tagged_id ) );
check( 'a trip photo is found by its id, under its own trip only', $tagged === TravelSteps\Uploads::attachment( $photos_trip, $tagged_id ) && 0 === TravelSteps\Uploads::attachment( $trip_id, $tagged_id ) );
check( '…not through another trip', '' === TravelSteps\RestController::access( $trip_id, $tagged_id ) );
check( '…and, as only editors can see it, not into shared caches, though the trip is published', 'publish' === get_post_status( $photos_trip ) && 'private' === TravelSteps\RestController::access( $photos_trip, $tagged_id ) );
$cached_copy = "$base/travel-steps-cache/medium/" . substr( $tagged_id, 0, 2 ) . "/$tagged_id";
TravelSteps\RestController::store_cached( $cached_copy, 'IMAGE', 'image/jpeg' );
wp_update_attachment_metadata( $tagged, wp_get_attachment_metadata( $tagged ) );
check( 'new sizes for a trip photo (regenerated, edited) replace its cached copies and are checked again', ! file_exists( $cached_copy ) && '' === get_post_meta( $tagged, TravelSteps\Uploads::META_CHECKED, true ) );
wp_set_current_user( 0 );
check( 'visitors can’t fetch a trip photo sync hasn’t published', '' === TravelSteps\RestController::access( $photos_trip, $tagged_id ) );
wp_set_current_user( 1 );

// Deleting a trip for good deletes its photos; binning it doesn't.
$cached = "$base/travel-steps-cache/thumbnail/" . substr( $photo_id, 0, 2 ) . "/$photo_id";
TravelSteps\RestController::store_cached( $cached, 'IMAGE', 'image/jpeg' );
wp_trash_post( $photos_trip );
check( 'binning a trip keeps its photos', get_post( $photo ) && file_exists( "$base/$stored" ) && file_exists( $cached ) );
wp_delete_post( $photos_trip, true );
check( 'deleting a trip deletes its photos, their files and cached copies', null === get_post( $photo ) && null === get_post( $rest_photo ) && ! is_dir( $dir ) && ! file_exists( $cached ) && ! file_exists( "$cached.type" ), glob( "$dir/*" ) );
check( '…but not ordinary media uploaded to it', null !== get_post( $described ) && file_exists( (string) get_attached_file( $described ) ) );
foreach ( [ $described, $posted, $loose ] as $id ) {
	wp_delete_attachment( $id, true );
}
wp_delete_post( $other_post, true );

// Each author's own keys and home: a trip always uses its author's, whoever syncs or views it.
check( 'authors may make trips by default; contributors may not', 'publish_posts' === Trips::capability() && user_can( $author, Trips::capability() ) && ! user_can( $contributor, Trips::capability() ) );
$person = fn( $login, $role ) => wp_insert_user( [ 'user_login' => $login, 'display_name' => ucfirst( $login ), 'user_pass' => wp_generate_password(), 'role' => $role ] );
$alice  = $person( 'alice', 'author' );
$bob    = $person( 'bob', 'author' );
$carol  = $person( 'carol', 'author' );
$eddie  = $person( 'eddie', 'editor' );
Settings::update_account( $alice, [ 'immich_key' => 'alice-immich', 'dawarich_key' => 'alice-dawarich' ] + Settings::ACCOUNT_DEFAULTS );
Settings::update_account( $bob, [ 'immich_key' => 'bob-immich' ] + Settings::ACCOUNT_DEFAULTS );
$trip_by = function ( int $user_id ) {
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'A trip', 'post_status' => 'publish', 'post_author' => $user_id ] );
	update_post_meta( $id, Trips::META_START, '2025-05-01' );
	update_post_meta( $id, Trips::META_END, '2025-05-02' );
	return $id;
};
[ $alices, $bobs, $carols ] = array_map( $trip_by, [ $alice, $bob, $carol ] );
// Every request to Immich or Dawarich, by the key it carried.
$keys_used = [];
$status    = 200;
$servers   = function ( $pre, $args, $url ) use ( &$keys_used, &$status ) {
	$keys_used[] = $args['headers']['x-api-key'] ?? str_replace( 'Bearer ', '', $args['headers']['Authorization'] ?? '' );
	$body        = false !== strpos( $url, '/api/search' ) ? '{"assets":{"items":[]}}' : ( false !== strpos( $url, '/thumbnail' ) ? 'IMAGE' : '[]' );
	return [ 'headers' => [ 'content-type' => 'image/jpeg' ], 'body' => $body, 'response' => [ 'code' => $status, 'message' => '' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $servers, 10, 3 );
wp_set_current_user( 1 );
$r = ( new TripSync() )->sync( $alices );
check( 'syncing a trip uses only its author’s keys', ! is_wp_error( $r ) && $keys_used && [] === array_diff( array_unique( $keys_used ), [ 'alice-immich', 'alice-dawarich' ] ), [ is_wp_error( $r ) ? $r->get_error_message() : $r, $keys_used ] );
$keys_used = [];
$r         = ( new TripSync() )->sync( $bobs );
check( '…and another author’s trip only theirs (without Dawarich, none)', ! is_wp_error( $r ) && [ 'bob-immich' ] === array_values( array_unique( $keys_used ) ), $keys_used );
$keys_used = [];
wp_set_current_user( 0 );
Trips::immich( $alices )->image( '00000000-0000-4000-8000-000000000001', 'thumbnail' );
check( 'a visitor’s photo is fetched with the trip author’s key', [ 'alice-immich' ] === $keys_used, $keys_used );
wp_set_current_user( 1 );
$r = ( new TripSync() )->sync( $carols );
check( 'a trip whose author has no Immich key fails to sync, naming them', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Carol' ), is_wp_error( $r ) ? $r->get_error_message() : $r );
$status = 401;
$r      = ( new TripSync() )->sync( $bobs );
$status = 200;
check( 'a key Immich refuses fails the sync, naming whose it is', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Bob' ), is_wp_error( $r ) ? $r->get_error_message() : $r );
// Another author with the same key mustn't be shown "Immich refused Bob's key" from the cache.
$status = 401;
ImmichClient::for_user( $bob )->albums();
$status = 200;
$albums = ImmichClient::for_user( $bob )->albums();
check( 'a refused key’s album error isn’t cached', ! is_wp_error( $albums ), is_wp_error( $albums ) ? $albums->get_error_message() : '' );

// Only the author changes what a trip pulls in, or syncs it; other editors keep the rest.
wp_set_current_user( $eddie );
check( 'an editor can edit another author’s trip, but not sync it', Trips::can_edit_trip( $alices ) && ! Trips::can_sync_trip( $alices ) );
$_POST = $_REQUEST = [ 'trip_id' => $alices, '_wpnonce' => wp_create_nonce( 'travel_steps_sync' ) ];
check( '…the sync handler refuses them', 'die' === $stopped( [ new Trips(), 'handle_sync' ] ) );
$_POST = $_REQUEST = [ 'trip_id' => $alices, 'title' => 'Renamed', 'start' => '2024-01-01', 'end' => '2024-01-02', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( '…as does saving new dates', 'die' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && '2025-05-01' === get_post_meta( $alices, Trips::META_START, true ) );
$_POST = $_REQUEST = [ 'trip_id' => $alices, 'title' => 'Renamed', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( '…but they can still rename it', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && 'Renamed' === get_the_title( $alices ) && '2025-05-01' === get_post_meta( $alices, Trips::META_START, true ) );
$_POST = $_REQUEST = [];
$keys_used = [];
$edit      = $render( 'trip-edit.php', (string) $alices );
check( '…and their edit page has no Sync button and no album list, and asks Immich nothing', false === strpos( $edit, 'travel_steps_sync' ) && false === strpos( $edit, 'name="sync"' ) && false === strpos( $edit, 'name="photos"' ) && [] === $keys_used, substr( $edit, strpos( $edit, '<form' ) ?: 0, 1500 ) );
check( '…with the dates shown but not editable', 1 === preg_match( '/name="start"[^>]*disabled|disabled[^>]*name="start"/', $edit ) );
check( '…and they can still pin, hide and choose the cover', 200 === $rest_post( "/travel-steps/v1/trips/$alices/photo-state", [ 'asset' => $own_photo, 'state' => 'hidden' ] )->get_status() );
check( 'an editor of a trip whose author has no Immich key is told so', false !== strpos( $render( 'trip-edit.php', (string) $carols ), 'Carol hasn’t saved an Immich API key' ) );
wp_set_current_user( $alice );
$keys_used = [];
$edit      = $render( 'trip-edit.php', (string) $alices );
check( 'the author’s own edit page lists their albums, and offers to sync', false !== strpos( $edit, 'name="photos"' ) && false !== strpos( $edit, 'travel_steps_sync' ) && [ 'alice-immich' ] === array_values( array_unique( $keys_used ) ), $keys_used );
remove_filter( 'pre_http_request', $servers, 10 );

// Settings: your own accounts for anyone who makes trips; the site's for administrators.
$settings_page = $render( 'settings.php', '' );
check( 'an author’s settings page: only their accounts, and no key printed', false !== strpos( $settings_page, 'name="immich_key"' ) && false === strpos( $settings_page, 'name="immich_url"' ) && false === strpos( $settings_page, 'alice-immich' ) && false === strpos( $settings_page, 'alice-dawarich' ) );
$_POST = $_REQUEST = [ 'dawarich_url' => 'http://evil.test', '_wpnonce' => wp_create_nonce( 'travel_steps_settings' ) ];
$site_before = get_option( Settings::OPTION );
check( 'an author can’t save the site settings', 'die' === $stopped( [ Settings::class, 'handle_save_site' ] ) && $site_before === get_option( Settings::OPTION ) );
$_POST = $_REQUEST = [ 'immich_key' => 'alice-new', 'home_lat' => '', 'home_lon' => '', '_wpnonce' => wp_create_nonce( 'travel_steps_account' ) ];
check( 'an author saves their own key, and only theirs', 'redirect' === $stopped( [ Settings::class, 'handle_save_account' ] ) && 'alice-new' === Settings::account( $alice )['immich_key'] && 'alice-dawarich' === Settings::account( $alice )['dawarich_key'] && 'bob-immich' === Settings::account( $bob )['immich_key'] && $site_before === get_option( Settings::OPTION ) );
$me = $get( '/wp/v2/users/me', [ 'context' => 'edit' ] );
check( 'no key in the REST user record', 200 === $me->get_status() && false === strpos( wp_json_encode( $me->get_data() ), 'alice-' ), $me->get_status() );
wp_set_current_user( 1 );
$settings_page = $render( 'settings.php', '' );
check( 'an administrator’s settings page: their accounts and the site’s', false !== strpos( $settings_page, 'name="immich_key"' ) && false !== strpos( $settings_page, 'name="immich_url"' ) && false === strpos( $settings_page, 'admin-immich-key' ) && false === strpos( $settings_page, 'alice-' ) );
$account_before = Settings::account( 1 );
$_POST = $_REQUEST = [ 'immich_url' => 'http://photos.test', 'dawarich_url' => 'http://dawarich.test', 'place_radius_km' => '3', 'auto_pick' => '6', '_wpnonce' => wp_create_nonce( 'travel_steps_settings' ) ];
check( 'saving the site settings leaves the administrator’s own untouched', 'redirect' === $stopped( [ Settings::class, 'handle_save_site' ] ) && 'http://photos.test' === Settings::immich_url() && $account_before === Settings::account( 1 ) );
$_POST = $_REQUEST = [];

// Upgrading from site-wide keys: each trip author gets them, unless they have their own.
$dave = $person( 'dave', 'author' );
$trip_by( $dave );
$trip_with = function ( $login, $role, $status ) use ( $person ) {
	$id = $person( $login, $role );
	wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'A trip', 'post_status' => $status, 'post_author' => $id ] );
	return $id;
};
$drafter     = $trip_with( 'drafter', 'author', 'draft' );
$autodrafter = $trip_with( 'autodrafter', 'author', 'auto-draft' );
$trasher     = $trip_with( 'trasher', 'author', 'trash' );
$contributor = $trip_with( 'contributor', 'contributor', 'draft' );
$ghost       = 999999;
wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'A trip', 'post_status' => 'publish', 'post_author' => $ghost ] );
// A migration that dies part-way (here, saving Dave's) is tried again on the next request.
delete_option( Settings::MIGRATED );
update_option( Settings::OPTION, [ 'immich_url' => 'http://immich.test', 'dawarich_key' => 'site-dawarich' ] );
$crash = function ( $meta_id, $user_id ) use ( $dave ) {
	if ( $dave === $user_id ) {
		throw new RuntimeException( 'crash' );
	}
};
add_action( 'added_user_meta', $crash, 10, 2 );
try {
	Settings::migrate();
} catch ( RuntimeException $e ) {
	unset( $e );
}
remove_action( 'added_user_meta', $crash, 10 );
delete_user_option( $dave, Settings::ACCOUNT );
Settings::migrate();
check( 'upgrade: a migration that failed part-way runs again', 'site-dawarich' === Settings::account( $dave )['dawarich_key'] && get_option( Settings::MIGRATED ), Settings::account( $dave ) );
foreach ( [ $dave, $drafter, $autodrafter, $trasher, $contributor ] as $id ) {
	delete_user_option( $id, Settings::ACCOUNT );
}
$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $ghost ] );
delete_option( Settings::MIGRATED );
update_option( Settings::OPTION, [ 'immich_url' => 'http://immich.test', 'dawarich_key' => 'site-dawarich', 'home_lat' => '53.3', 'home_lon' => '-6.2' ] );
update_option( 'immich_settings', [ 'api_url' => 'http://picker.test', 'api_key' => 'picker-key' ] );
Settings::migrate();
check( 'upgrade: a trip author gets the key in effect, Media Picker’s included, and the home', [ 'picker-key', 'site-dawarich', '53.3' ] === [ Settings::account( $dave )['immich_key'], Settings::account( $dave )['dawarich_key'], Settings::account( $dave )['home_lat'] ], Settings::account( $dave ) );
check( 'upgrade: an author with their own keeps them', 'alice-new' === Settings::account( $alice )['immich_key'] );
check( 'upgrade: no keys or home left in the site settings', [ 'immich_url' => 'http://immich.test' ] === get_option( Settings::OPTION ), get_option( Settings::OPTION ) );
check( 'upgrade: the author of a draft trip gets them', 'picker-key' === Settings::account( $drafter )['immich_key'] );
check( 'upgrade: authors of only an auto-draft or a trashed trip, or who can’t make trips, don’t', [ false, false, false ] === array_map( fn( $id ) => get_user_option( Settings::ACCOUNT, $id ), [ $autodrafter, $trasher, $contributor ] ) );
check( 'upgrade: nothing is saved for a trip author who no longer exists', null === $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d", $ghost ) ) );
update_option( Settings::OPTION, [ 'immich_key' => 'later' ] );
Settings::update_account( $dave, Settings::ACCOUNT_DEFAULTS );
Settings::migrate();
check( 'upgrade: runs once', '' === Settings::account( $dave )['immich_key'] );
check( 'Media Picker’s key is never used in place of an author’s own', '' === ( new ReflectionProperty( ImmichClient::class, 'api_key' ) )->getValue( TravelSteps\ImmichClient::for_user( $dave ) ) );
delete_option( 'immich_settings' );
update_option( Settings::OPTION, [ 'immich_url' => 'http://immich.test' ] );

// Suggested privacy policy text, shown under Settings → Privacy.
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
check( 'privacy policy suggestion is hooked', false !== has_action( 'admin_init', [ TravelSteps\App::class, 'suggest_privacy_policy' ] ) );
// WordPress only accepts suggestions in wp-admin during admin_init.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' );
$GLOBALS['wp_current_filter'][] = 'admin_init';
TravelSteps\App::suggest_privacy_policy();
array_pop( $GLOBALS['wp_current_filter'] );
$GLOBALS['current_screen'] = null;
$suggested = implode( ' ', wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text' ) );
check( 'privacy policy suggestion names OpenStreetMap', false !== strpos( $suggested, 'OpenStreetMap' ) && false !== strpos( $suggested, 'osmfoundation.org/wiki/Privacy_Policy' ), substr( $suggested, 0, 200 ) );

// A step belongs to a trip its author can edit: anyone else's published step would
// show on the trip and make its photos from that day public.
$step_parent = function ( int $user, array $postarr ) {
	wp_set_current_user( $user );
	$id = isset( $postarr['ID'] ) ? wp_update_post( $postarr ) : wp_insert_post( $postarr + [ 'post_type' => Trips::STEP, 'post_title' => 'Mine', 'post_status' => 'publish' ] );
	wp_set_current_user( 1 );
	return (int) get_post_field( 'post_parent', $id );
};
check( 'another author can’t add a step to a trip', $bobs !== $step_parent( $alice, [ 'post_parent' => $bobs ] ) );
$alice_step = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_title' => 'Mine', 'post_status' => 'publish', 'post_author' => $alice, 'post_parent' => $alices ] );
check( '…or move their own step into it, which keeps its trip', $alices === $step_parent( $alice, [ 'ID' => $alice_step, 'post_parent' => $bobs ] ) );
check( '…but the trip’s author can', $bobs === $step_parent( $bob, [ 'post_parent' => $bobs ] ) );
check( '…and so can an editor who can edit the trip', $bobs === $step_parent( $eddie, [ 'post_parent' => $bobs ] ) );
check( '…and moving a step out of a trip is allowed', 0 === $step_parent( $alice, [ 'ID' => $alice_step, 'post_parent' => 0 ] ) );

require '/smoke/upload-trips.php';
require '/smoke/upload-pages.php';
require '/smoke/password-trips.php';
} catch ( Throwable $e ) {
	$out[] = 'FAIL exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
