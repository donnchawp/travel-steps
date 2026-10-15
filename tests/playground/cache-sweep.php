<?php
/**
 * The daily sweep of the photo cache: it deletes cached photos no trip or upload uses,
 * and, at an age an administrator sets, those older than that. Required at the end of
 * smoke.php, in its scope: uses check(), $alice, $cache_path, $render and $stopped.
 */
use TravelSteps\{CacheSweep, RestController, Settings, Trips};

$cs_id = fn( int $n ) => sprintf( '5eeb0000-0000-4000-8000-%012d', $n );
// Cache a photo at a size, its files last written $days ago.
$cs_cache = function ( string $asset, string $size = 'preview', float $days = 0 ) use ( $cache_path ) {
	$file = $cache_path( $asset, $size );
	// Its folders as the proxy makes them, protected.
	TravelSteps\Uploads::protect( RestController::cache_root(), substr( dirname( $file ), strlen( RestController::cache_root() ) + 1 ) );
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

// Photos in use: in a trip's photos, whatever its status, or uploaded and not synced yet.
$cs_trip = function ( string $status, array $assets ) use ( $alice ) {
	$trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Swept', 'post_status' => $status, 'post_author' => $alice ] );
	update_post_meta( $trip, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_map( fn( $id ) => [ 'id' => $id, 't' => 0 ], $assets ) ) ) );
	return $trip;
};
$cs_binned  = $cs_trip( 'trash', [ $cs_id( 3 ) ] );
$cs_upload  = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => $cs_binned, 'post_title' => 'upload' ] );
update_post_meta( $cs_upload, TravelSteps\Uploads::META_PHOTO_ID, $cs_id( 4 ) );
$cs_shared  = [ $cs_trip( 'publish', [ $cs_id( 5 ), $cs_id( 6 ) ] ), $cs_trip( 'draft', [ $cs_id( 5 ) ] ) ];
wp_delete_post( $cs_shared[1], true );
$cs_in_use  = [
	'a binned trip uses'                            => $cs_cache( $cs_id( 3 ), 'medium', 2 ),
	'only an unsynced upload uses'                  => $cs_cache( $cs_id( 4 ), 'thumbnail', 2 ),
	'a trip uses after another using it is deleted' => $cs_cache( $cs_id( 5 ), 'preview', 2 ),
];
CacheSweep::run();
foreach ( $cs_in_use as $why => $file ) {
	check( "cache sweep: keeps a photo $why", true === $cs_kept( $file ) );
}

// An age set by an administrator: photos in use cached longer ago than that go too.
$cs_trip( 'publish', [ $cs_id( 7 ), $cs_id( 8 ) ] );
$cs_aged = function ( int $days ) use ( $cs_cache, $cs_id, $cs_kept ) {
	update_option( Settings::OPTION, [ 'cache_days' => $days ] + Settings::all() );
	$files = [ $cs_cache( $cs_id( 7 ), 'preview', 4 ), $cs_cache( $cs_id( 8 ), 'medium', 2 ) ];
	CacheSweep::run();
	return array_map( $cs_kept, $files );
};
check( 'cache sweep: deleting after 3 days, a photo in use cached 4 days ago goes, and one cached 2 days ago stays', [ false, true ] === $cs_aged( 3 ) );
check( '…and never deleting, both stay', [ true, true ] === $cs_aged( 0 ) );

// What writes left behind: half-written files, and a type whose photo was never written.
$cs_dir      = dirname( $cs_cache( $cs_id( 9 ) ) );
$cs_leftover = function ( string $name, float $days ) use ( $cs_dir ) {
	file_put_contents( "$cs_dir/$name", 'half' );
	touch( "$cs_dir/$name", (int) ( time() - $days * DAY_IN_SECONDS ) );
	return "$cs_dir/$name";
};
$cs_old_tmp  = $cs_leftover( wp_hash( $cs_id( 10 ) ) . '.Ab3dE5gH.tmp', 2 );
$cs_new_tmp  = $cs_leftover( wp_hash( $cs_id( 11 ) ) . '.type.Ab3dE5gH.tmp', 0.5 );
$cs_lone     = $cs_leftover( wp_hash( $cs_id( 12 ) ) . '.type', 2 );
CacheSweep::run();
check( 'cache sweep: a half-written file more than a day old is deleted, one newer is kept', ! file_exists( $cs_old_tmp ) && file_exists( $cs_new_tmp ) );
check( '…so is a type more than a day old without its photo', ! file_exists( $cs_lone ) );
check( '…and the .htaccess and every index.html stay', file_exists( RestController::cache_root() . '/.htaccess' ) && file_exists( RestController::cache_root() . '/index.html' ) && file_exists( "$cs_dir/index.html" ) && file_exists( dirname( $cs_dir ) . '/index.html' ) );

// Scheduling: one daily event, from the first request after the plugin loads.
$cs_events = fn() => count( array_filter( _get_cron_array(), fn( $hooks ) => isset( $hooks[ CacheSweep::HOOK ] ) ) );
check( 'cache sweep: WP-Cron runs it once a day', 'daily' === wp_get_schedule( CacheSweep::HOOK ) && false !== has_action( CacheSweep::HOOK, [ CacheSweep::class, 'run' ] ), wp_get_schedule( CacheSweep::HOOK ) );
CacheSweep::schedule();
check( '…scheduling it again leaves a single event', 1 === $cs_events(), $cs_events() );
$cs_plugin = plugin_basename( TRAVEL_STEPS_PLUGIN_FILE );
do_action( "deactivate_$cs_plugin" );
check( '…deactivating the plugin unschedules it', 0 === $cs_events() );
do_action( "activate_$cs_plugin" );
check( '…and activating it schedules it', 1 === $cs_events() && 'daily' === wp_get_schedule( CacheSweep::HOOK ) );

// The setting: in the Site form, for administrators only, saved as a whole number of days.
$cs_save = function ( $days ) use ( $stopped ) {
	$_POST = $_REQUEST = [ 'immich_url' => 'http://immich.test', 'cache_days' => $days, '_wpnonce' => wp_create_nonce( 'travel_steps_settings' ) ];
	$result = $stopped( [ Settings::class, 'handle_save_site' ] );
	$_POST  = $_REQUEST = [];
	return 'redirect' === $result ? Settings::get( 'cache_days' ) : $result;
};
wp_set_current_user( 1 );
check( 'cache sweep setting: never, until an administrator chooses an age', 0 === Settings::DEFAULTS['cache_days'] );
check( '…an administrator saves it in days', 30 === $cs_save( '30' ) );
check( '…negative or non-numeric, it’s saved as never', [ 0, 0, 0 ] === [ $cs_save( '-3' ), $cs_save( 'soon' ), $cs_save( '' ) ] );
$cs_page = $render( 'settings.php', '' );
check( '…it’s in the Site form, which says expired photos are fetched from Immich again', 1 === preg_match( '/name="cache_days"[^>]*value="0"/', $cs_page ) && false !== strpos( $cs_page, 'fetched from Immich again' ) );
wp_set_current_user( $alice );
check( '…which authors don’t see', false === strpos( $render( 'settings.php', '' ), 'name="cache_days"' ) );
wp_set_current_user( 1 );
