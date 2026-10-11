<?php
/**
 * End-to-end checks inside WordPress Playground, with fake Dawarich and Immich clients:
 * sync, re-sync, the REST gate, story rendering and the trip pages. Run with `make smoke`.
 * Results go to /out/result.txt, one "ok" or "FAIL" line per check.
 */
require '/wordpress/wp-load.php';

use TravelSteps\{TripSync, Trips, View, DawarichClient, ImmichClient, Days};

$out = [];
function check( string $name, bool $ok, $detail = '' ) {
	global $out;
	$out[] = ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  -- ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) );
}

try {
check( 'plugin active and classes load', class_exists( TripSync::class ) && post_type_exists( Trips::STEP ) && taxonomy_exists( Trips::PLACE ) );

class FakeDawarich extends DawarichClient {
	public function points( int $start, int $end ) {
		$pts = [];
		for ( $t = $start; $t < $end; $t += 1800 ) {
			$pts[] = [ 38.7139, -9.1394, $t, 'Lisbon', 'Portugal' ];
		}
		return $pts;
	}
}
class FakeImmich extends ImmichClient {
	public array $rows = [];
	public array $asked = [];
	public function photos( int $start, int $end, string $album_id = '' ) {
		$this->asked = [ $start, $end ];
		return $this->rows;
	}
}
function row( $n, $day, $hm, $lat, $lon, $city, $fav ) {
	$t = strtotime( "$day $hm UTC" );
	return [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', $n ), 't' => $t, 'day' => $day, 'lat' => $lat, 'lon' => $lon,
		'w' => 400, 'h' => 300, 'city' => $city, 'country' => 'Portugal', 'caption' => '', 'fav' => $fav, 'camera' => 'Fuji X100' ];
}
$immich       = new FakeImmich( 'http://x', 'k' );
$immich->rows = [
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

$sync = new TripSync( new FakeDawarich( 'http://x', 'k' ), $immich );
$r    = $sync->sync( $trip_id );
check( 'first sync creates a step per day with favourites', ! is_wp_error( $r ) && 2 === $r['new_steps'], $r );

$steps = Trips::steps( $trip_id, true );
check( 'two steps', 2 === count( $steps ), count( $steps ) );
[ $from, $to ] = Trips::range( $trip_id );
check( 'photos asked for 14h either side of the trip', [ $from - 14 * HOUR_IN_SECONDS, $to + 14 * HOUR_IN_SECONDS ] === $immich->asked, [ $from, $to, $immich->asked ] );
check( 'no photos kept from before the trip', ! in_array( row( 7, '2025-04-30', '20:00', 0, 0, '', 1 )['id'], array_column( Trips::photos( $trip_id ), 'id' ), true ) );
$d1 = $steps[0]; $d2 = $steps[1] ?? null;
check( 'steps are drafts', 'draft' === $d1->post_status && 'draft' === $d2->post_status );
check( 'day 1 title names both places', 'Lisbon, Sintra' === $d1->post_title, $d1->post_title );
check( 'day 1 dated 1 May', '2025-05-01' === Trips::day( $d1 ), $d1->post_date );
check( 'day 1 seeded with two place headings', 2 === count( Days::anchor_times( $d1->post_content ) ), $d1->post_content );
check( 'day 2 has no headings', '' === $d2->post_content, $d2->post_content );
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
wp_untrash_post( $d2->ID );
wp_update_post( [ 'ID' => $d2->ID, 'post_status' => 'draft' ] );

// Moving an auto step to another date doesn't bring back its original day.
wp_update_post( [ 'ID' => $d2->ID, 'post_date' => '2025-05-03 12:00:00', 'edit_date' => true ] );
$r = $sync->sync( $trip_id );
check( 'moved step keeps its original day taken', 0 === $r['new_steps'], $r );
wp_update_post( [ 'ID' => $d2->ID, 'post_date' => '2025-05-02 12:00:00', 'edit_date' => true ] );

// Lock.
add_option( 'travel_steps_sync_lock_' . $trip_id, time(), '', false );
$r = $sync->sync( $trip_id );
check( 'concurrent sync refused', is_wp_error( $r ) && 'travel_steps_sync_running' === $r->get_error_code() );
delete_option( 'travel_steps_sync_lock_' . $trip_id );

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
check( 'anon: no photos of a draft trip', [] === Trips::viewable_photo_ids( get_post( $trip_id ) ) );

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
$ids = Trips::viewable_photo_ids( get_post( $trip_id ) );
check( 'anon: photos only from the published day', isset( $ids[ row( 1, '2025-05-01', '09:00', 0, 0, '', 1 )['id'] ] ) && ! isset( $ids[ row( 5, '2025-05-02', '11:00', 0, 0, '', 1 )['id'] ] ), array_keys( $ids ) );

$trip = $get( '/travel-steps/v1/trips/' . $trip_id );
$data = $trip->get_data();
check( 'trip API: markers 1a/1b for the two places', [ '1a', '1b' ] === array_column( $data['markers'] ?? [], 'label' ), $data );
check( 'trip API: no per-photo data', ! isset( $data['steps'] ) );

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

// Full templates render without errors.
$render = function ( $tpl, $slug ) {
	set_query_var( 'slug', $slug );
	ob_start();
	include '/wordpress/wp-content/plugins/travel-steps/templates/' . $tpl;
	return ob_get_clean();
};
wp_publish_post( $d2->ID );
wp_set_current_user( 1 );
$edit = $render( 'trip-edit.php', (string) $trip_id );
check( 'edit page renders step list', false !== strpos( $edit, 'ts-step-list' ), [ 'len' => strlen( $edit ), 'notfound' => false !== strpos( $edit, 'Trip not found' ), 'nosteps' => false !== strpos( $edit, 'No steps yet' ), 'panel' => substr_count( $edit, 'ts-panel' ), 'tail' => substr( preg_replace( '/\s+/', ' ', strip_tags( substr( $edit, strpos( $edit, '<main' ) ?: 0 ) ) ), 0, 900 ) ] );
$html = $render( 'trip.php', (string) $trip_id );
check( 'editor trip page: place tags', false !== strpos( $html, 'class="ts-places"' ) && false !== strpos( $html, '<li>Sintra</li>' ), substr( $html, 0, 300 ) );
check( 'editor trip page: Day 2 shown', false !== strpos( $html, 'Day 2' ) );
check( 'editor trip page: photo grid under heading', preg_match( '/Sintra<\/h2>.*?ts-photos/s', $html ) === 1 );
wp_set_current_user( 0 );
$html = $render( 'trip.php', (string) $trip_id );
check( 'anon trip page renders steps', 2 === substr_count( $html, 'class="ts-step"' ), substr_count( $html, 'class="ts-step"' ) );
check( 'anon trip page has no edit tools', false === strpos( $html, 'ts-photo-state' ) );
} catch ( Throwable $e ) {
	$out[] = 'FAIL exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
file_put_contents( '/out/result.txt', implode( "\n", $out ) . "\n" );
