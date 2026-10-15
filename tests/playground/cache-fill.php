<?php
/**
 * Filling the photo cache in the background after a trip syncs, so a published trip keeps
 * working without Immich. Required at the end of smoke.php, in its scope: uses check(),
 * row(), FakeDawarich, FakeSource, $alice (who has an Immich key), $jpeg, $cache_path
 * and $rest_post.
 */
use TravelSteps\{CacheFill, RestController, Settings, TripSync, Trips, Uploads};

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
// Which of these photos are cached at preview.
$cf_has      = fn( array $ids ) => array_values( array_filter( $ids, $cf_cached ) );
$cf_all      = array_map( $cf_id, range( 1801, 1806 ) );
$cf_new_trip = function ( string $title ) use ( $alice ) {
	$trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $alice ] );
	update_post_meta( $trip, Trips::META_START, '2025-07-01' );
	update_post_meta( $trip, Trips::META_END, '2025-07-03' );
	return $trip;
};

wp_set_current_user( $alice );
$cf_trip         = $cf_new_trip( 'Cache me' );
$cf_source       = new FakeSource();
$cf_source->rows = [
	row( 1801, '2025-07-01', '09:00', 38.71, -9.13, 'Lisbon', true ),  // Day 1, published: shown.
	row( 1802, '2025-07-01', '10:00', 38.71, -9.13, 'Lisbon', false ), // …the rest.
	row( 1803, '2025-07-02', '09:00', 37.01, -7.93, 'Faro', true ),    // Day 2, a draft: shown.
	row( 1804, '2025-07-02', '10:00', 37.01, -7.93, 'Faro', false ),   // …hidden.
	row( 1805, '2025-07-03', '09:00', 37.01, -7.93, 'Faro', true ),    // Day 3, step binned: the chosen cover.
	row( 1806, '2025-07-03', '10:00', 37.01, -7.93, 'Faro', false ),   // …on no step.
];
$cf_sync = fn( int $trip ) => ( new TripSync( new FakeDawarich( 'http://x', 'k' ), $cf_source ) )->sync( $trip );
$r       = $cf_sync( $cf_trip );
[ $cf_day1, , $cf_day3 ] = Trips::steps( $cf_trip, true ) + [ null, null, null ];
check( 'cache fill: the trip syncs with three steps', ! is_wp_error( $r ) && $cf_day3, $r );
wp_publish_post( $cf_day1->ID );
wp_trash_post( $cf_day3->ID );
update_post_meta( $cf_trip, Trips::META_HIDDEN, [ $cf_id( 1804 ) ] );
update_post_meta( $cf_trip, Trips::META_COVER, $cf_id( 1805 ) );

$cf_fetched = [];
CacheFill::run( $cf_trip, 0 );
$cf_want = array_map( $cf_id, [ 1801, 1802, 1803, 1805 ] );
check( 'cache fill: every photo visitors can see, on published and draft steps, and the cover, is cached at preview', $cf_want === $cf_has( $cf_all ), [ $cf_has( $cf_all ), $cf_fetched ] );
check( '…fetched from Immich at preview only', [] === array_diff( $cf_fetched, array_map( fn( $id ) => "$id preview", $cf_want ) ) && 4 === count( $cf_fetched ), $cf_fetched );
$cf_fetched = [];
CacheFill::run( $cf_trip, 0 );
check( 'cache fill: a photo already cached isn’t fetched again', [] === $cf_fetched, $cf_fetched );
$cf_served = [ RestController::fetch( $cf_trip, $cf_id( 1802 ), 'preview' ), RestController::fetch( $cf_trip, $cf_id( 1802 ), 'medium' ) ];
check( '…and the proxy serves its preview and medium without asking Immich', [] === $cf_fetched && ! array_filter( $cf_served, 'is_wp_error' ), [ $cf_fetched, $cf_served ] );
// The author's key revoked after a fill: a filled photo is still served, with no HTTP call.
$cf_account = Settings::account( $alice );
Settings::update_account( $alice, [ 'immich_key' => '' ] + $cf_account );
$cf_fetched = [];
$cf_served  = [ RestController::fetch( $cf_trip, $cf_id( 1803 ), 'preview' ), RestController::fetch( $cf_trip, $cf_id( 1803 ), 'medium' ) ];
Settings::update_account( $alice, $cf_account );
check( 'cache fill: with the author’s Immich key removed, the proxy serves a filled photo’s preview and medium with no HTTP call', [] === $cf_fetched && ! array_filter( $cf_served, 'is_wp_error' ), [ $cf_fetched, $cf_served ] );

// Fetches that fail: one is skipped, three in a row stop the run until the next trigger.
$cf_reset = function () use ( $cf_all, $cf_trip ) {
	foreach ( $cf_all as $asset ) {
		RestController::delete_cached( $asset );
	}
	wp_clear_scheduled_hook( CacheFill::HOOK, [ $cf_trip ] );
};
$cf_reset();
$cf_failing = [ $cf_id( 1801 ) ];
CacheFill::run( $cf_trip, 0 );
check( 'cache fill: a photo that fails to fetch is skipped, and the rest are cached', array_map( $cf_id, [ 1802, 1803, 1805 ] ) === $cf_has( $cf_all ) );
// Immich unreachable, or the key wrong: every fetch fails.
$cf_reset();
$cf_failing = $cf_want;
$cf_fetched = [];
CacheFill::run( $cf_trip, 300 );
check( '…three failures in a row stop the run', 3 === count( $cf_fetched ) && [] === $cf_has( $cf_want ), $cf_fetched );
check( '…with nothing rescheduled', false === wp_next_scheduled( CacheFill::HOOK, [ $cf_trip ] ) );
// Photos that always fail (deleted in Immich since the sync, or with no preview made)
// can't stop every run at the same place: a later run gets past them. Seeded, so the
// order the runs take is the same every time.
$cf_reset();
$cf_failing = array_map( $cf_id, [ 1801, 1802, 1803 ] );
mt_srand( 75 );
for ( $cf_runs = 0; $cf_runs < 10 && ! $cf_cached( $cf_id( 1805 ) ); ++$cf_runs ) {
	CacheFill::run( $cf_trip, 300 );
}
mt_srand();
check( 'cache fill: three photos that always fail at the front of the list don’t stop later photos being cached', $cf_cached( $cf_id( 1805 ) ), $cf_runs );
// A fetch slower than the budget (Immich unreachable, the connection hanging) leaves a
// run with nothing cached by its deadline: rescheduling would retry it forever.
$cf_reset();
$cf_failing = $cf_want;
$cf_fetched = [];
CacheFill::run( $cf_trip, 300, 0 );
check( 'cache fill: a run that hits its budget having cached nothing schedules nothing', 1 === count( $cf_fetched ) && false === wp_next_scheduled( CacheFill::HOOK, [ $cf_trip ] ), $cf_fetched );
$cf_failing = [];
// A photo fetched but not cached (a full disk, an unwritable cache) is a failure too.
$cf_reset();
$cf_blocked = array_map( fn( $asset ) => $cache_path( $asset, 'preview' ) . '.type', $cf_want );
array_map( 'wp_mkdir_p', $cf_blocked );
$cf_fetched = [];
CacheFill::run( $cf_trip, 0 );
check( 'cache fill: three photos in a row that can’t be cached stop the run', 3 === count( $cf_fetched ), $cf_fetched );
array_map( 'rmdir', $cf_blocked );
// A photo uploaded to the trip, left in its photos after it switched to Immich, is
// served from the upload: it's never asked of Immich.
$cf_reset();
$cf_upload = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => $cf_trip, 'post_title' => 'upload' ] );
update_post_meta( $cf_upload, Uploads::META_PHOTO_ID, $cf_id( 1802 ) );
$cf_fetched = [];
CacheFill::run( $cf_trip, 0 );
check( 'cache fill: a photo uploaded to the trip isn’t fetched from Immich', ! in_array( $cf_id( 1802 ) . ' preview', $cf_fetched, true ) && 3 === count( $cf_fetched ), $cf_fetched );
wp_delete_post( $cf_upload, true );

// Under a PHP time limit, a run stops after its budget and leaves the rest to another.
$cf_reset();
$cf_runs = 0;
do {
	wp_clear_scheduled_hook( CacheFill::HOOK, [ $cf_trip ] );
	CacheFill::run( $cf_trip, 300, 0 );
	++$cf_runs;
	if ( 1 === $cf_runs ) {
		check( 'cache fill: with a tiny budget, a run caches a photo and schedules another', 1 === count( $cf_has( $cf_want ) ) && wp_next_scheduled( CacheFill::HOOK, [ $cf_trip ] ) );
	}
} while ( wp_next_scheduled( CacheFill::HOOK, [ $cf_trip ] ) && $cf_runs < 10 );
check( '…and later runs finish the trip, the last scheduling nothing', 4 === $cf_runs && $cf_want === $cf_has( $cf_want ), $cf_runs );
// Without a PHP time limit (WP-Cron run from a system crontab, by PHP CLI), the next run
// could be a whole cron interval away, so there's no budget.
$cf_reset();
CacheFill::run( $cf_trip, 0, 0 );
check( 'cache fill: with no PHP time limit, one run caches every photo even with a tiny budget', $cf_want === $cf_has( $cf_want ) );
check( '…and schedules nothing', false === wp_next_scheduled( CacheFill::HOOK, [ $cf_trip ] ) );
// A PHP time limit under twice the budget: a run stops at half the limit.
$cf_reset();
$cf_slow = function ( $pre ) {
	usleep( 600000 );
	return $pre;
};
add_filter( 'pre_http_request', $cf_slow, 5 );
CacheFill::run( $cf_trip, 1 );
remove_filter( 'pre_http_request', $cf_slow, 5 );
check( 'cache fill: a run stops at half a PHP time limit lower than its budget', 1 === count( $cf_has( $cf_want ) ) && wp_next_scheduled( CacheFill::HOOK, [ $cf_trip ] ) );
$cf_reset();

// When a run is scheduled: one event per trip, after a sync or a change to what it shows.
$cf_events = fn( int $trip_id ) => count( array_filter( _get_cron_array(), fn( $hooks ) => isset( $hooks[ CacheFill::HOOK ][ md5( serialize( [ $trip_id ] ) ) ] ) ) );
check( 'cache fill: WP-Cron runs it', false !== has_action( CacheFill::HOOK, [ CacheFill::class, 'run' ] ) );
CacheFill::schedule( $cf_trip );
CacheFill::schedule( $cf_trip );
check( 'cache fill: scheduling twice for a trip leaves a single event', 1 === $cf_events( $cf_trip ), $cf_events( $cf_trip ) );
$cf_reset();
$cf_sync( $cf_trip );
check( 'cache fill: a sync schedules a run', 1 === $cf_events( $cf_trip ) );
$cf_reset();
check( '…changing a photo’s state too', 200 === $rest_post( "/travel-steps/v1/trips/$cf_trip/photo-state", [ 'asset' => $cf_id( 1804 ), 'state' => 'default' ] )->get_status() && 1 === $cf_events( $cf_trip ) );
$cf_reset();
check( '…and choosing the cover', 200 === $rest_post( "/travel-steps/v1/trips/$cf_trip/cover", [ 'asset' => $cf_id( 1801 ) ] )->get_status() && 1 === $cf_events( $cf_trip ) );
$cf_reset();
$cf_uploads = $cf_new_trip( 'Uploaded' );
update_post_meta( $cf_uploads, Trips::META_SOURCE, Trips::SOURCE_UPLOADS );
$r = $cf_sync( $cf_uploads );
check( 'cache fill: syncing a trip of uploads schedules nothing', ! is_wp_error( $r ) && 0 === $cf_events( $cf_uploads ), $r );
foreach ( Trips::steps( $cf_uploads, true ) as $cf_step ) {
	wp_delete_post( $cf_step->ID, true );
}
wp_delete_post( $cf_uploads, true );

remove_filter( 'pre_http_request', $cf_immich, 10 );
