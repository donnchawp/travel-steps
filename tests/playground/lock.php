<?php
/**
 * Lock::run(), with another request's moves made by hand in its database row and option
 * caches. Required at the end of smoke.php, in its scope: uses check().
 */
use TravelSteps\Lock;

$lk_name = 'travel_steps_smoke_lock';
// The lock's row as another request sees it, null when there's none.
$lk_row = fn() => $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", $lk_name ) );
// Another request writes the lock's row, behind this request's option caches.
$lk_set = function ( ?string $value ) use ( $lk_name ) {
	global $wpdb;
	$wpdb->delete( $wpdb->options, [ 'option_name' => $lk_name ] );
	if ( null !== $value ) {
		$wpdb->insert( $wpdb->options, [ 'option_name' => $lk_name, 'option_value' => $value, 'autoload' => 'off' ] );
	}
};
$lk_ran = fn( $job = null ) => Lock::run( $lk_name, $job ?? fn() => 'ran', 'busy' );

$lk_set( null );
check( 'lock: a job runs when no one holds the lock', 'ran' === $lk_ran() );
check( '…and lets go of it after', null === $lk_row(), $lk_row() );
try {
	$lk_ran( fn() => throw new RuntimeException( 'job failed' ) );
} catch ( RuntimeException $e ) {
	unset( $e );
}
check( '…even when the job throws', null === $lk_row(), $lk_row() );

$lk_set( (string) time() );
check( 'lock: a job is refused while another request holds the lock', 'busy' === $lk_ran() );
check( '…which keeps it', (string) time() === $lk_row() || (string) ( time() - 1 ) === $lk_row(), $lk_row() );

// This request last saw no lock; another one took it a second before.
$lk_set( null );
get_option( $lk_name );
$lk_set( (string) ( time() - 1 ) );
check( 'lock: refused when another request takes it after this one last looked', 'busy' === $lk_ran() );

$lk_set( (string) ( time() - 11 * MINUTE_IN_SECONDS ) );
check( 'lock: a lock left 10 minutes ago by a request that died is taken over', 'ran' === $lk_ran() );
check( '…and let go of after', null === $lk_row(), $lk_row() );

// This request saw the dead lock; another one took it over since.
$lk_set( (string) ( time() - 11 * MINUTE_IN_SECONDS ) );
wp_cache_delete( $lk_name, 'options' );
wp_cache_delete( 'notoptions', 'options' );
get_option( $lk_name );
$lk_set( time() . ' other' );
check( 'lock: a dead lock another request has taken over isn’t taken again', 'busy' === $lk_ran() );

// A job that runs past 10 minutes: another request takes over, and this one ends after.
$lk_set( null );
$lk_ran( fn() => $lk_set( time() . ' other' ) );
check( 'lock: a run doesn’t let go of a lock another request has taken over', time() . ' other' === $lk_row(), $lk_row() );

$lk_set( null );
wp_cache_delete( $lk_name, 'options' );
wp_cache_delete( 'notoptions', 'options' );
