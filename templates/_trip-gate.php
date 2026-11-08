<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flag from the password form's redirect.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Who may see the trip in the URL, for every page that shows a whole trip: the trip page
 * and its print version. Returns the trip for a visitor who may see it, and sets $shared
 * when a share link let them in. Otherwise it shows the password form or "not found" and
 * returns null, and the page includes nothing else. A page other than the trip's own sets
 * $trip_suffix to its Trips::url() suffix, so the password form brings them back to it:
 *
 *     $trip_suffix = 'print';
 *     $trip        = include __DIR__ . '/_trip-gate.php';
 *     if ( ! $trip ) {
 *         return;
 *     }
 */
use TravelSteps\Trips;

$trip_suffix = $trip_suffix ?? '';
$trip        = Trips::find_by_slug( (string) get_query_var( 'slug' ) );
// Core does this for a singular post with a password; this route isn't one, so a page
// cache could otherwise keep the unlocked trip and serve it to everyone. The password
// form posts to the page it's on: that sets core's cookie and redirects. A share link
// sets its own cookie, and shows the trip in the same request.
$shared = false;
if ( $trip && Trips::is_protected( $trip ) ) {
	nocache_headers();
	// For page caches that go by this rather than the headers.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page caches' own constant.
	}
	Trips::handle_password_post( $trip, $trip_suffix );
	$shared = Trips::handle_share_link( $trip );
}
if ( $trip && Trips::needs_password( $trip ) ) {
	include __DIR__ . '/_password.php';
	return null;
}
if ( ! $trip || ! Trips::can_view( $trip ) ) {
	include __DIR__ . '/_notfound.php';
	return null;
}
// In with the password: drop the form's flag, so a copied link doesn't carry it.
if ( isset( $_GET['tried'] ) ) {
	wp_safe_redirect( Trips::url( $trip, $trip_suffix ) );
	exit;
}
return $trip;
