<?php
/**
 * End-to-end checks inside WordPress Playground, with fake Dawarich and Immich clients:
 * sync, re-sync, the REST gate, story rendering and the trip pages. Run with `make smoke`.
 * Results go to /out/result.txt, one "ok" or "FAIL" line per check.
 */
require '/wordpress/wp-load.php';

use TravelSteps\{TripSync, Trips, View, DawarichClient, ImmichClient, Days};

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
check( 'cover: visitors can load the chosen cover', isset( Trips::viewable_photo_ids( get_post( $trip_id ) )[ $faro ] ) );
wp_set_current_user( 1 );
$html = $render( 'trip.php', (string) $trip_id );
check( 'cover: its button is pressed on the trip page', 1 === preg_match( '/data-asset="' . $faro . '"[^>]*>(?:(?!<\/figure>).)*ts-photo-cover[^>]*aria-pressed="true"/s', $html ) );
$res = $cover( '' );
check( 'cover: pressing again goes back to automatic', 200 === $res->get_status() && false !== strpos( View::cover_url( $trip_id ), $lis ) );
wp_set_current_user( 0 );
check( 'cover: an unchosen draft photo is private again', ! isset( Trips::viewable_photo_ids( get_post( $trip_id ) )[ $faro ] ) );

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
check( 'edit page notes the sync limits', false !== strpos( $render( 'trip-edit.php', (string) $trip_id ), 'at most 20,000 photos and 500,000 location points' ), '' );
check( 'edit page renders step list', false !== strpos( $edit, 'ts-step-list' ), [ 'len' => strlen( $edit ), 'notfound' => false !== strpos( $edit, 'Trip not found' ), 'nosteps' => false !== strpos( $edit, 'No steps yet' ), 'panel' => substr_count( $edit, 'ts-panel' ), 'tail' => substr( preg_replace( '/\s+/', ' ', strip_tags( substr( $edit, strpos( $edit, '<main' ) ?: 0 ) ) ), 0, 900 ) ] );
$html = $render( 'trip.php', (string) $trip_id );
check( 'editor trip page: place tags', false !== strpos( $html, 'class="ts-places"' ) && false !== strpos( $html, '<li>Sintra</li>' ), substr( $html, 0, 300 ) );
check( 'editor trip page: Day 2 shown', false !== strpos( $html, 'Day 2' ) );
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

update_option( TravelSteps\Settings::OPTION, array_merge( (array) get_option( TravelSteps\Settings::OPTION, [] ), [ 'immich_url' => 'http://immich.test', 'immich_key' => 'key' ] ) );
$gone = '22222222-2222-4222-8222-222222222222';
update_post_meta( $trip_id, Trips::META_ALBUM, $gone );
wp_set_current_user( 1 );
$edit = $render( 'trip-edit.php', (string) $trip_id );
check( 'a saved album missing from Immich stays selected', 1 === preg_match( '/<option value="' . $gone . '"[^>]*selected/', $edit ), substr( $edit, strpos( $edit, '<select name="album"' ) ?: 0, 600 ) );

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
$_POST = $_REQUEST = [];
wp_set_current_user( 1 );
check( 'administrators have the travel-steps capability', current_user_can( Trips::capability() ) );
add_filter( 'travel_steps_capability', fn() => 'edit_posts' );
wp_set_current_user( $contributor );
check( 'the capability can be widened with a filter', current_user_can( Trips::capability() ) );
remove_all_filters( 'travel_steps_capability' );
wp_set_current_user( 1 );
} catch ( Throwable $e ) {
	$out[] = 'FAIL exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
