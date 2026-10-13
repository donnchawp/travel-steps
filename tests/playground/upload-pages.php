<?php
/**
 * The trip pages for trips of uploads: the Photos select, the Photos panel, the sync
 * notice and wording by source. Required after upload-trips.php, in smoke.php's scope:
 * uses check(), $render, $stopped, $person, $servers, $status, $trip_by, $alice,
 * $carol, $carols, $alices, $eddie and upload-trips.php's $ut_* helpers.
 */
use TravelSteps\Settings;
use TravelSteps\Trips;

// The "Photos" select's options: value => the rest of the option tag.
$up_choices = function ( string $html ): array {
	if ( ! preg_match( '/<select name="photos">(.*?)<\/select>/s', $html, $select ) ) {
		return [];
	}
	preg_match_all( '/<option value="([^"]*)"([^>]*)>/', $select[1], $options );
	return array_combine( $options[1], $options[2] );
};
// Runs $fn with the stand-in Immich and Dawarich servers answering.
$up_with_servers = function ( callable $fn ) use ( $servers ) {
	add_filter( 'pre_http_request', $servers, 10, 3 );
	try {
		return $fn();
	} finally {
		remove_filter( 'pre_http_request', $servers, 10 );
	}
};
// Runs $fn as if the site's PHP had no exif extension.
$up_no_exif = function ( callable $fn ) {
	add_filter( 'travel_steps_can_upload', '__return_false' );
	try {
		return $fn();
	} finally {
		remove_filter( 'travel_steps_can_upload', '__return_false' );
	}
};
// A trip's edit page as $user, with the stand-in servers; '' is the new-trip page.
$up_edit = function ( int $user, string $slug = '' ) use ( $up_with_servers, $render ): string {
	wp_set_current_user( $user );
	return $up_with_servers( fn() => $render( 'trip-edit.php', $slug ) );
};
// A photo taken in Dingle at $taken (EXIF format).
$up_photo = fn( int $trip_id, string $taken ) => $ut_photo( $trip_id, [ 'taken' => $taken, 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
// Syncs a trip through the form handler; where it redirects to.
$up_sync = function ( int $trip_id ) use ( $ut_location ): string {
	$_POST = $_REQUEST = [ 'trip_id' => $trip_id, '_wpnonce' => wp_create_nonce( 'travel_steps_sync' ) ];
	$to    = $ut_location( [ new Trips(), 'handle_sync' ] );
	$_POST = $_REQUEST = [];
	return $to;
};
$up_required = fn( string $html ) => preg_match_all( '/<input type="date"[^>]*required/', $html );
$up_nokey    = $person( 'nokey', 'author' );

// The "Photos" select, by what the author can use.
$up_html = $up_edit( $alice );
$up_opts = $up_choices( $up_html );
check( 'an author with an Immich key: uploads, then all Immich photos, chosen', [ 'uploads', 'immich' ] === array_keys( $up_opts ) && false !== strpos( $up_opts['immich'], 'selected' ), $up_opts );
check( '…the dates are required, with the hint hidden', 2 === $up_required( $up_html ) && 1 === preg_match( '/<small[^>]*ts-dates-hint[^>]*hidden/', $up_html ) );
check( '…and Save says Save', 1 === preg_match( '/class="ts-button ts-save"[^>]*>Save</', $up_html ) );
$up_html = $up_edit( $up_nokey );
$up_opts = $up_choices( $up_html );
check( 'an author without a key: only uploads, chosen, and a line about the key', [ 'uploads' ] === array_keys( $up_opts ) && false !== strpos( $up_opts['uploads'], 'selected' ) && false !== strpos( $up_html, 'Add an Immich key in settings' ), $up_opts );
check( '…the dates are optional, with the hint shown', 0 === $up_required( $up_html ) && 1 === preg_match( '/<small[^>]*ts-dates-hint[^>]*>/', $up_html, $up_hint ) && false === strpos( $up_hint[0], 'hidden' ) );
check( '…Save says it adds photos, and there’s no Save and sync', 1 === preg_match( '/class="ts-button ts-save"[^>]*>Save and add photos</', $up_html ) && 1 === preg_match( '/<button[^>]*name="sync"[^>]*hidden/', $up_html ) );
$up_html = $up_no_exif( fn() => $up_edit( $alice ) );
$up_opts = $up_choices( $up_html );
check( 'without exif: the uploads option is disabled, with the reason', false !== strpos( $up_opts['uploads'] ?? '', 'disabled' ) && false !== strpos( $up_opts['immich'] ?? '', 'selected' ) && false !== strpos( $up_html, 'Uploading photos needs PHP’s exif extension' ), $up_opts );
$up_html = $up_no_exif( fn() => $up_edit( $up_nokey ) );
check( 'with neither: no select, and a line saying what’s needed', [] === $up_choices( $up_html ) && false !== strpos( $up_html, 'ask your site’s administrator for PHP’s exif extension' ) );

// An Immich trip whose author has no key keeps Immich, so saving it doesn't switch it to uploads.
$up_opts = $up_choices( $up_edit( $carol, (string) $carols ) );
check( 'an Immich trip whose author has no key keeps Immich chosen', [ 'uploads', 'immich' ] === array_keys( $up_opts ) && false !== strpos( $up_opts['immich'], 'selected' ), $up_opts );

// Immich can't list albums: the album id stays typed beside "All Immich photos".
$up_offline = $person( 'offline', 'author' );
Settings::update_account( $up_offline, [ 'immich_key' => 'offline-immich' ] + Settings::ACCOUNT_DEFAULTS );
$up_offline_trip = $trip_by( $up_offline );
update_post_meta( $up_offline_trip, Trips::META_ALBUM, '11111111-2222-4333-8444-555555555555' );
$status  = 500;
$up_html = $up_edit( $up_offline, (string) $up_offline_trip );
$status  = 200;
$up_opts = $up_choices( $up_html );
check( 'Immich can’t list albums: all Immich photos chosen, the album id typed beside it', false !== strpos( $up_opts['immich'] ?? '', 'selected' ) && 1 === preg_match( '/name="album"[^>]*value="11111111-2222-4333-8444-555555555555"/', $up_html ) && false !== strpos( $up_html, 'Could not list Immich albums' ), $up_opts );
wp_set_current_user( 1 );

// The Photos panel: how many uploads, and a button to add more, for anyone who can edit the trip.
$up_panel = $ut_trip( 'Panel', $ut_author, '2025-06-01', '2025-06-02' );
$up_photo( $up_panel, '2025:06:01 10:00:00' );
wp_set_current_user( $ut_author );
$_GET    = [ 'saved' => '1', 'add' => '1' ];
$up_html = $render( 'trip-edit.php', (string) $up_panel );
$_GET    = [];
check( 'an upload trip’s edit page: its photo count and Add photos', false !== strpos( $up_html, '1 photo uploaded.' ) && false !== strpos( $up_html, 'ts-add-photos' ), substr( $up_html, strpos( $up_html, 'ts-uploads' ) ?: 0, 600 ) );
check( '…?add=1 opens the modal, whose templates are on the page', 1 === preg_match( '/class="ts-panel ts-uploads"[^>]*data-open="1"/', $up_html ) && false !== strpos( $up_html, 'tmpl-media-modal' ) );
check( '…and the author’s page has the sync form that closing it submits', false !== strpos( $up_html, 'ts-sync-form' ) );
// Another plugin's media source (such as an Immich picker) would add photos that aren't trip photos.
$up_other = 0;
add_action( 'wp_enqueue_media', function () use ( &$up_other ) {
	++$up_other;
} );
// wp_enqueue_media() runs once a request, and earlier pages here have run it.
unset( $GLOBALS['wp_actions']['wp_enqueue_media'] );
$up_html = $render( 'trip-edit.php', (string) $up_panel );
check( '…other plugins’ additions to the media modal don’t load on it', 0 === $up_other && false !== strpos( $up_html, 'tmpl-media-modal' ), $up_other );
wp_set_current_user( $eddie );
$_GET    = [ 'photos' => '1' ];
$up_html = $render( 'trip-edit.php', (string) $up_panel );
$_GET    = [];
check( 'another editor can add photos but has no sync form, and is told who syncs', false !== strpos( $up_html, 'ts-add-photos' ) && false === strpos( $up_html, 'ts-sync-form' ) && false !== strpos( $up_html, 'Wanderer needs to sync it' ), substr( $up_html, 0, 2000 ) );
wp_set_current_user( $ut_author );
$up_html = $up_no_exif( fn() => $render( 'trip-edit.php', (string) $up_panel ) );
check( 'without exif, the panel says why there’s no Add photos', false !== strpos( $up_html, '1 photo uploaded.' ) && false === strpos( $up_html, 'ts-add-photos' ) && false !== strpos( $up_html, 'can’t take uploads' ) );
$up_html = $up_edit( $alice, (string) $alices );
check( 'an Immich trip’s edit page has no Photos panel', false === strpos( $up_html, 'ts-uploads' ) && false !== strpos( $up_html, 'ts-sync-form' ) );
wp_set_current_user( 1 );
wp_delete_post( $up_panel, true );

// The sync notice: what sync left out of a trip of uploads, and why.
wp_set_current_user( $ut_author );
$up_result = ( new TravelSteps\TripSync() )->sync( $ut_tour );
$up_to     = $up_sync( $ut_tour );
parse_str( (string) wp_parse_url( $up_to, PHP_URL_QUERY ), $up_args );
$up_want = array_map( 'strval', $up_result['left_out'] + [ 'unlocated' => $up_result['unlocated'], 'no_places' => (int) $up_result['no_places'] ] );
check( 'syncing a trip of uploads passes what it left out to the notice', $up_want === array_intersect_key( $up_args, $up_want ) && array_filter( $up_want ), [ $up_want, $up_to ] );
$_GET    = [ 'synced' => '1', 'new_steps' => '0', 'outside' => '1', 'untimed' => '1', 'home' => '1', 'unlocated' => '1', 'no_places' => '0' ];
$up_html = $render( 'trip-edit.php', (string) $ut_tour );
check( 'the notice: what was left out, and why', false !== strpos( $up_html, 'Left out: 1 outside the trip’s dates, 1 with no time taken (often sent through a messaging app, or a screenshot), 1 taken at home.' ), substr( $up_html, strpos( $up_html, 'Synced.' ) ?: 0, 800 ) );
check( '…and the photo with no location', false !== strpos( $up_html, '1 photo has no location, so it isn’t on the map. iPhone browsers drop it unless you turn on Options → Location before uploading; messaging apps strip it.' ) );
$_GET    = [ 'synced' => '1', 'new_steps' => '0', 'untimed' => '2', 'no_places' => '1' ];
$up_html = $render( 'trip-edit.php', (string) $ut_tour );
$_GET    = [];
check( '…only counts above zero, and missing place names', false !== strpos( $up_html, 'Left out: 2 with no time taken (often sent through a messaging app, or a screenshot).' ) && false === strpos( $up_html, 'outside the trip’s dates' ) && false === strpos( $up_html, 'no location' ) && false !== strpos( $up_html, 'Place names are unavailable: the plugin’s geonames data is missing.' ) );
wp_set_current_user( $alice );
$up_to = $up_with_servers( fn() => $up_sync( $alices ) );
check( 'an Immich trip’s sync passes no left-out counts', false !== strpos( $up_to, 'synced=1' ) && false === strpos( $up_to, 'untimed' ), $up_to );

// Wording on a trip of uploads, where every upload is a favourite and Immich plays no part.
wp_set_current_user( $ut_author );
$up_html = $render( 'trip.php', (string) $ut_tour );
check( 'no “Favourite in Immich” heart on a trip of uploads', false !== strpos( $up_html, 'ts-photo-tools' ) && false === strpos( $up_html, 'Favourite in Immich' ) );
$up_bare = $ut_trip( 'Bare', $up_nokey );
wp_set_current_user( $up_nokey );
$up_html = $render( 'trip-edit.php', (string) $up_bare );
check( 'a trip of uploads: no Immich key warning and no photo limit', false === strpos( $up_html, 'saved an Immich API key' ) && false === strpos( $up_html, 'ignores the rest' ), substr( $up_html, 0, 2000 ) );
check( '…and its Sync and Steps panels say what syncing uploads does', false !== strpos( $up_html, 'Syncing makes a draft step for each day with photos, named after where they were taken.' ) && false !== strpos( $up_html, 'Add photos and sync: each day with photos gets a step.' ) );
$up_step = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $up_bare, 'post_title' => 'Day', 'post_status' => 'draft', 'post_author' => $up_nokey ] );
$GLOBALS['post'] = get_post( $up_step );
check( 'the photos block on a trip of uploads asks for uploads', false !== strpos( TravelSteps\PhotosBlock::render( [] ), 'No photos for this place yet. Add photos to the trip.' ) );
wp_set_current_user( 1 );
wp_delete_post( $up_step, true );
wp_delete_post( $up_bare, true );

// Settings: Immich is optional, and a site without exif says what that means.
$up_html = $render( 'settings.php', '' );
check( 'settings: Immich is optional, and a trip of uploads shows every upload', false !== strpos( $up_html, 'Immich (optional)' ) && false !== strpos( $up_html, 'For a trip with uploaded photos, every upload is shown; hide the ones you don’t want.' ) && false === strpos( $up_html, 'no exif extension' ) );
$up_html = $up_no_exif( fn() => $render( 'settings.php', '' ) );
check( 'settings without exif: trips can only use Immich', false !== strpos( $up_html, 'This site’s PHP has no exif extension, so trips can only use Immich photos.' ) );

// The modal's attachment details link nowhere that names the file: core's attachment
// screen prints the original's URL and name, so trip photos get no link to it.
$up_priv = $ut_trip( 'Private screen', $ut_author );
wp_set_current_user( $ut_author );
$up_pid = $up_photo( $up_priv, '2025:08:01 10:00:00' );
$up_js  = wp_prepare_attachment_for_js( $up_pid );
check( 'the modal has no Edit Image or edit-screen link for a trip photo', '' === ( $up_js['editLink'] ?? '' ) && empty( $up_js['nonces']['update'] ) && empty( $up_js['nonces']['edit'] ), [ $up_js['editLink'] ?? null, $up_js['nonces'] ?? null ] );
check( '…but can still delete it', ! empty( $up_js['nonces']['delete'] ) );
$_GET  = [ 'post' => (string) $up_pid, 'action' => 'edit' ];
$up_to = $ut_location( [ TravelSteps\Uploads::class, 'edit_screen' ] );
check( 'core’s attachment screen for a trip photo sends you to its trip instead', false !== strpos( $up_to, '/trip/private-screen/edit' ), $up_to );
$_GET = [ 'post' => (string) $alices, 'action' => 'edit' ];
check( '…and leaves any other post alone', 'returned' === $stopped( [ TravelSteps\Uploads::class, 'edit_screen' ] ) );
wp_set_current_user( $up_nokey );
$_GET = [ 'post' => (string) $up_pid, 'action' => 'edit' ];
check( '…and someone who can’t edit the photo isn’t told where its trip is', 'returned' === $stopped( [ TravelSteps\Uploads::class, 'edit_screen' ] ) );
$_GET = [];
// The old uploader's detail form (async-upload.php?fetch=) prints a file's name and URL
// to anyone who can upload: not for trip photos.
$_REQUEST = [ 'attachment_id' => (string) $up_pid, 'fetch' => '3' ];
check( 'the old uploader’s detail form refuses a trip photo', 'die' === $stopped( [ TravelSteps\Uploads::class, 'fetch_form' ] ) );
$_REQUEST = [ 'attachment_id' => (string) $up_pid ];
check( '…but not the upload it’s part of', 'returned' === $stopped( [ TravelSteps\Uploads::class, 'fetch_form' ] ) );
$_REQUEST = [];
// Its attachment page would redirect to the file (or show it): a trip photo has none.
wp_set_current_user( $ut_author );
$up_query = $GLOBALS['wp_query'];
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( [ 'attachment_id' => $up_pid ] );
$up_was_attachment   = is_attachment();
TravelSteps\Uploads::attachment_page();
check( 'a trip photo’s attachment page is a 404, even for its uploader', $up_was_attachment && is_404() && ! is_attachment() );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $up_query;
wp_set_current_user( 1 );
wp_delete_post( $up_priv, true );

// Dates sync took from the photos follow them, so photos added on later days aren't left out.
$up_grow  = $ut_trip( 'Growing', $ut_author );
$up_dates = fn() => [ get_post_meta( $up_grow, Trips::META_START, true ), get_post_meta( $up_grow, Trips::META_END, true ) ];
wp_set_current_user( $ut_author );
$up_photo( $up_grow, '2025:08:01 10:00:00' );
( new TravelSteps\TripSync() )->sync( $up_grow );
// The author saves the form, which sends back the dates it shows.
$_POST = $_REQUEST = [ 'trip_id' => $up_grow, 'title' => 'Growing', 'start' => '2025-08-01', 'end' => '2025-08-01', 'photos' => Trips::SOURCE_UPLOADS, 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
$stopped( [ new Trips(), 'handle_save_trip' ] );
$_POST = $_REQUEST = [];
$up_photo( $up_grow, '2025:08:03 10:00:00' );
$up_result = ( new TravelSteps\TripSync() )->sync( $up_grow );
check( 'dates taken from the photos follow them: a later photo moves the last day', [ '2025-08-01', '2025-08-03' ] === $up_dates() && 0 === ( $up_result['left_out']['outside'] ?? -1 ), [ $up_dates(), $up_result ] );
update_post_meta( $up_grow, Trips::META_END, '2025-08-02' );
$up_photo( $up_grow, '2025:08:05 10:00:00' );
$up_result = ( new TravelSteps\TripSync() )->sync( $up_grow );
check( '…but a date the author set stays put', [ '2025-08-01', '2025-08-02' ] === $up_dates() && 2 === ( $up_result['left_out']['outside'] ?? -1 ), [ $up_dates(), $up_result ] );
wp_set_current_user( 1 );
foreach ( Trips::steps( $up_grow, true ) as $up_step_post ) {
	wp_delete_post( $up_step_post->ID, true );
}
wp_delete_post( $up_grow, true );
