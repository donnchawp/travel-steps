<?php
/**
 * Password-protected trips: the password gate, the viewer lists that skip it, and the
 * forms that set them. Required at the end of smoke.php, in its scope: uses check(),
 * row(), $render, $get, $stopped, $person, $alice, $bob and $eddie.
 */
use TravelSteps\RestController;
use TravelSteps\Settings;
use TravelSteps\Trips;

require_once ABSPATH . WPINC . '/class-phpass.php';
// Core's password cookie, as wp-login.php?action=postpass sets it; null clears it.
$pw_enter = function ( ?string $password ) {
	if ( null === $password ) {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		return;
	}
	$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( $password );
};
$pw_photo = row( 41, '2025-05-01', '09:00', 38.7, -9.1, 'Lisbon', true )['id'];
$pw_cover = row( 42, '2025-05-01', '10:00', 38.7, -9.1, 'Lisbon', true )['id'];
// A published trip of Alice's with a published step, its story, photos and a chosen cover.
$pw_trip = function ( string $title ) use ( $alice, $pw_cover ) {
	wp_set_current_user( 1 );
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $alice ] );
	update_post_meta( $id, Trips::META_START, '2025-05-01' );
	update_post_meta( $id, Trips::META_END, '2025-05-01' );
	update_post_meta( $id, Trips::META_PHOTOS, wp_slash( wp_json_encode( [ row( 41, '2025-05-01', '09:00', 38.7, -9.1, 'Lisbon', true ), row( 42, '2025-05-01', '10:00', 38.7, -9.1, 'Lisbon', true ) ] ) ) );
	update_post_meta( $id, Trips::META_COVER, $pw_cover );
	$step = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $id, 'post_title' => 'Lisbon day', 'post_status' => 'publish', 'post_author' => $alice, 'post_date' => '2025-05-01 12:00:00', 'post_content' => '<!-- wp:paragraph --><p>Secret story</p><!-- /wp:paragraph -->' ] );
	update_post_meta( $step, Trips::META_LAT, 38.7 );
	update_post_meta( $step, Trips::META_LON, -9.1 );
	return [ $id, $step ];
};
[ $pw_a, $pw_a_step ] = $pw_trip( 'Hidden Lisbon' );
$pw_cache = fn( $res ) => (string) ( $res->get_headers()['Cache-Control'] ?? '' );
$pw_set   = function ( int $trip, string $password ) {
	wp_update_post( [ 'ID' => $trip, 'post_password' => $password ] );
	clean_post_cache( $trip );
};
// What a visitor can reach of a trip: page, REST, photo proxy, core REST for its step, the list.
$pw_reach = function ( int $trip, int $step ) use ( $render, $get, $pw_photo, $pw_cover ) {
	return [
		'page'  => $render( 'trip.php', (string) $trip ),
		'rest'  => $get( '/travel-steps/v1/trips/' . $trip ),
		'proxy' => $get( '/travel-steps/v1/trips/' . $trip . '/photo/' . $pw_photo ),
		'photo' => RestController::access( $trip, $pw_photo ),
		'cover' => RestController::access( $trip, $pw_cover ),
		'step'  => $get( '/wp/v2/ts_step/' . $step )->get_status(),
		'list'  => $render( 'index.php', '' ),
	];
};

// Without a password, a published trip is public as before.
wp_set_current_user( 0 );
$pw_enter( null );
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'no password: visitors see the whole trip', false !== strpos( $seen['page'], 'Secret story' ) && 200 === $seen['rest']->get_status() && 200 === $seen['step'] );
check( 'no password: its photos and REST stay in shared caches', 'public' === $seen['photo'] && 'public' === $seen['cover'] && 0 === strpos( $pw_cache( $seen['rest'] ), 'public' ), [ $seen['photo'], $seen['cover'], $pw_cache( $seen['rest'] ) ] );
check( 'no password: listed on the landing page', false !== strpos( $seen['list'], 'Hidden Lisbon' ) );

// With one, a visitor without it gets the title and a password form, and nothing else.
$pw_set( $pw_a, 'open sesame' );
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'password: the trip page shows its title and a password form', 1 === preg_match( '#<h1>Hidden Lisbon</h1>#', $seen['page'] ) && 1 === preg_match( '#<form[^>]*action="[^"]*wp-login\.php\?action=postpass#', $seen['page'] ) && false !== strpos( $seen['page'], 'name="post_password"' ), substr( $seen['page'], strpos( $seen['page'], '<main' ) ?: 0, 800 ) );
check( '…titled without “Protected:”', 1 === preg_match( '#<title>Hidden Lisbon\b#', $seen['page'] ) && false === strpos( $seen['page'], 'Protected:' ), substr( $seen['page'], 0, 400 ) );
check( '…and nothing else of the trip', false === strpos( $seen['page'], 'Secret story' ) && false === strpos( $seen['page'], 'Lisbon day' ) && false === strpos( $seen['page'], 'ts-map' ) && false === strpos( $seen['page'], $pw_photo ) && false === strpos( $seen['page'], $pw_cover ) && false === strpos( $seen['page'], 'ts-hero-img' ) && false === strpos( $seen['page'], 'ts-stats' ) );
check( '…no wrong-password message on a first visit', false === strpos( $seen['page'], 'That password isn’t right' ) );
$refused = fn( int $status ) => in_array( $status, [ 401, 403, 404 ], true );
check( 'password: REST, the photo proxy and core REST for its steps refuse a visitor', $refused( $seen['rest']->get_status() ) && $refused( $seen['proxy']->get_status() ) && '' === $seen['photo'] && '' === $seen['cover'] && $refused( $seen['step'] ), [ $seen['rest']->get_status(), $seen['proxy']->get_status(), $seen['photo'], $seen['cover'], $seen['step'] ] );
check( 'password: not listed to a visitor', false === strpos( $seen['list'], 'Hidden Lisbon' ) );

// A wrong password shows the form again, saying so; core's form handler adds "tried".
$pw_enter( 'open sesame?' );
$_GET = [ 'tried' => '1' ];
$page = $render( 'trip.php', (string) $pw_a );
check( 'wrong password: the form again, saying so', false !== strpos( $page, 'name="post_password"' ) && false !== strpos( $page, 'That password isn’t right' ) && false === strpos( $page, 'Secret story' ) );
$_GET = [];
check( '…but not without the flag, as with another trip’s password', false === strpos( $render( 'trip.php', (string) $pw_a ), 'That password isn’t right' ) );
check( 'the form sends the visitor back to the trip, flagged', 1 === preg_match( '#name="redirect_to" value="' . preg_quote( esc_url( add_query_arg( 'tried', '1', Trips::url( get_post( $pw_a ) ) ) ), '#' ) . '"#', $page ) );

// The right one shows the whole trip, its photos kept out of shared caches.
$pw_enter( 'open sesame' );
$_GET = [ 'tried' => '1' ];
$seen = $pw_reach( $pw_a, $pw_a_step );
$_GET = [];
check( 'right password: the whole trip, and no message', false !== strpos( $seen['page'], 'Secret story' ) && false === strpos( $seen['page'], 'That password isn’t right' ) && false === strpos( $seen['page'], 'name="post_password"' ) );
check( '…REST and its steps let them in', 200 === $seen['rest']->get_status() && 200 === $seen['step'], [ $seen['rest']->get_status(), $seen['step'] ] );
check( '…its photos and REST are private', 'private' === $seen['photo'] && 'private' === $seen['cover'] && 0 === strpos( $pw_cache( $seen['rest'] ), 'private' ), [ $seen['photo'], $seen['cover'], $pw_cache( $seen['rest'] ) ] );
check( '…and its photo URLs need no nonce', false !== strpos( $seen['page'], $pw_photo ) && false === strpos( $seen['page'], '_wpnonce' ) && false !== strpos( $seen['page'], 'data-nonce=""' ) );
check( '…but the landing page still doesn’t list it', false === strpos( $seen['list'], 'Hidden Lisbon' ) );

// Changing the password locks out those who had the old one.
$pw_set( $pw_a, 'new sesame' );
check( 'a changed password locks out the old one', ! Trips::can_view( get_post( $pw_a ) ) );
$pw_enter( null );

// Its editors see it without a password, and the landing page badges it for them.
foreach ( [ 'author' => $alice, 'editor' => $eddie ] as $role => $user ) {
	wp_set_current_user( $user );
	$seen = $pw_reach( $pw_a, $pw_a_step );
	check( "the trip’s $role sees it without the password", false !== strpos( $seen['page'], 'Secret story' ) && 200 === $seen['rest']->get_status() && 'private' === $seen['photo'] );
	check( "…listed to them with a “Password protected” badge", 1 === preg_match( '#<span class="ts-badge">Password protected</span>(?:(?!</a>).)*Hidden Lisbon#s', $seen['list'] ) );
}

// Logged-in users REST knows only by a nonce, so their photo URLs carry one, the cover's too.
$pw_nonced = fn( string $html, string $asset ) => 1 === preg_match( '#/photo/' . $asset . '\?[^"]*_wpnonce=#', $html );
foreach ( [ 'author' => $alice, 'editor' => $eddie ] as $role => $user ) {
	wp_set_current_user( $user );
	$seen = $pw_reach( $pw_a, $pw_a_step );
	check( "the {$role}’s cover and photo URLs carry a nonce", $pw_nonced( $seen['list'], $pw_cover ) && $pw_nonced( $seen['page'], $pw_cover ) && $pw_nonced( $seen['page'], $pw_photo ) );
}

// Viewer lists: the author's covers all their trips, a trip's only that trip.
$frank = $person( 'frank', 'subscriber' ); // On Alice's list.
$gina  = $person( 'gina', 'subscriber' );  // On Hidden Lisbon's list.
$hal   = $person( 'hal', 'subscriber' );   // On neither.
[ $pw_b, $pw_b_step ] = $pw_trip( 'Hidden Porto' );
$pw_set( $pw_b, 'porto' );
Settings::update_account( $alice, [ 'viewers' => [ $frank, 999999 ] ] + Settings::account( $alice ) );
update_post_meta( $pw_a, Trips::META_VIEWERS, [ $gina ] );
$pw_sees = function ( int $user, int $trip, int $step ) use ( $pw_reach ) {
	wp_set_current_user( $user );
	$seen = $pw_reach( $trip, $step );
	return false !== strpos( $seen['page'], 'Secret story' ) && 200 === $seen['rest']->get_status() && 'private' === $seen['photo'] && 200 === $seen['step'];
};
$pw_listed = function ( int $user, string $title ) use ( $render ) {
	wp_set_current_user( $user );
	return false !== strpos( $render( 'index.php', '' ), $title );
};
check( 'on the author’s list: all their protected trips, without a password', $pw_sees( $frank, $pw_a, $pw_a_step ) && $pw_sees( $frank, $pw_b, $pw_b_step ) );
check( '…listed, without a badge', $pw_listed( $frank, 'Hidden Lisbon' ) && $pw_listed( $frank, 'Hidden Porto' ) && false === strpos( $render( 'index.php', '' ), 'Password protected' ) );
wp_set_current_user( $frank );
$page = $render( 'trip.php', (string) $pw_a );
check( '…with nonces on its photos and REST', $pw_nonced( $page, $pw_photo ) && $pw_nonced( $page, $pw_cover ) && 1 === preg_match( '#data-nonce="[a-f0-9]+"#', $page ) && $pw_nonced( $render( 'index.php', '' ), $pw_cover ) );
check( 'on a trip’s list: that trip without a password', $pw_sees( $gina, $pw_a, $pw_a_step ) && $pw_listed( $gina, 'Hidden Lisbon' ) );
check( '…but not the author’s other protected trips', ! $pw_sees( $gina, $pw_b, $pw_b_step ) && ! $pw_listed( $gina, 'Hidden Porto' ) );
check( 'on neither list: the password form', ! $pw_sees( $hal, $pw_a, $pw_a_step ) && false !== strpos( $render( 'trip.php', (string) $pw_a ), 'name="post_password"' ) && ! $pw_listed( $hal, 'Hidden Lisbon' ) );
$pw_enter( 'new sesame' );
check( '…in with the password, but still not listed to them', $pw_sees( $hal, $pw_a, $pw_a_step ) && ! $pw_listed( $hal, 'Hidden Lisbon' ) );
$pw_enter( null );
check( 'logged out, a listed user is a visitor', ! $pw_sees( 0, $pw_a, $pw_a_step ) );
