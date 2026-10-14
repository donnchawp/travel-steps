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
// Core's password cookie, as the password form sets it; null clears it.
$pw_enter = function ( ?string $password ) {
	if ( null === $password ) {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		return;
	}
	$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( $password );
};
$pw_rows  = [ row( 41, '2025-05-01', '09:00', 38.7, -9.1, 'Lisbon', true ), row( 42, '2025-05-01', '10:00', 38.7, -9.1, 'Lisbon', true ) ];
$pw_photo = $pw_rows[0]['id'];
$pw_cover = $pw_rows[1]['id'];
// A published trip of Alice's with a published step, its story, photos and a chosen cover.
$pw_trip = function ( string $title ) use ( $alice, $pw_rows, $pw_cover ) {
	wp_set_current_user( 1 );
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $alice ] );
	update_post_meta( $id, Trips::META_START, '2025-05-01' );
	update_post_meta( $id, Trips::META_END, '2025-05-01' );
	update_post_meta( $id, Trips::META_PHOTOS, wp_slash( wp_json_encode( $pw_rows ) ) );
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
$refused = fn( int $status ) => in_array( $status, [ 401, 403, 404 ], true );
// What core REST does with a trip: 'open' if it serves and lists it, 'shut' if it does neither.
$pw_core = function ( int $trip ) use ( $get, $refused ) {
	$status = $get( '/wp/v2/ts_trip/' . $trip )->get_status();
	$listed = in_array( $trip, array_column( $get( '/wp/v2/ts_trip', [ 'include' => [ $trip ] ] )->get_data(), 'id' ), true );
	if ( 200 === $status && $listed ) {
		return 'open';
	}
	if ( $refused( $status ) && ! $listed ) {
		return 'shut';
	}
	return 'status ' . $status . ', ' . ( $listed ? 'listed' : 'not listed' );
};
// What a visitor can reach of a trip: page, REST, photo proxy, core REST for its step, the list, and core REST for the trip.
$pw_reach = function ( int $trip, int $step ) use ( $render, $get, $pw_core, $pw_photo, $pw_cover ) {
	return [
		'page'  => $render( 'trip.php', (string) $trip ),
		'rest'  => $get( '/travel-steps/v1/trips/' . $trip ),
		'proxy' => $get( '/travel-steps/v1/trips/' . $trip . '/photo/' . $pw_photo ),
		'photo' => RestController::access( $trip, $pw_photo ),
		'cover' => RestController::access( $trip, $pw_cover ),
		'step'  => $get( '/wp/v2/ts_step/' . $step )->get_status(),
		'list'  => $render( 'index.php', '' ),
		'core'  => $pw_core( $trip ),
	];
};

// Whether rendering a trip page sends core's no-cache headers.
$pw_nocache = function ( int $trip ) use ( $render ) {
	$sent = false;
	$spy  = function ( $headers ) use ( &$sent ) {
		$sent = true;
		return $headers;
	};
	add_filter( 'nocache_headers', $spy );
	$render( 'trip.php', (string) $trip );
	remove_filter( 'nocache_headers', $spy );
	return $sent;
};
// Without a password, a published trip is public as before.
wp_set_current_user( 0 );
$pw_enter( null );
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'no password: visitors see the whole trip', false !== strpos( $seen['page'], 'Secret story' ) && 200 === $seen['rest']->get_status() && 200 === $seen['step'] );
check( 'no password: its photos and REST stay in shared caches', 'public' === $seen['photo'] && 'public' === $seen['cover'] && 0 === strpos( $pw_cache( $seen['rest'] ), 'public' ), [ $seen['photo'], $seen['cover'], $pw_cache( $seen['rest'] ) ] );
check( 'no password: listed on the landing page', false !== strpos( $seen['list'], 'Hidden Lisbon' ) );
check( '…and served and listed by core REST', 'open' === $seen['core'], $seen['core'] );
check( '…and left to page caches', ! $pw_nocache( $pw_a ) );

// "0" is no password to core, so it's none to the trip either: public, not half-protected.
$pw_set( $pw_a, '0' );
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'password “0”: public, as core treats it', false !== strpos( $seen['page'], 'Secret story' ) && 'public' === $seen['photo'] && false !== strpos( $seen['list'], 'Hidden Lisbon' ), [ $seen['photo'] ] );

// With one, a visitor without it gets the title and a password form, and nothing else.
$pw_set( $pw_a, 'open sesame' );
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'password: the trip page shows its title and a password form', 1 === preg_match( '#<h1>Hidden Lisbon</h1>#', $seen['page'] ) && false !== strpos( $seen['page'], 'name="post_password"' ), substr( $seen['page'], strpos( $seen['page'], '<main' ) ?: 0, 800 ) );
check( '…posting to the trip page, never wp-login.php', 1 === preg_match( '#<form[^>]*class="[^"]*ts-password-form[^"]*"[^>]*action="' . preg_quote( esc_url( Trips::url( get_post( $pw_a ) ) ), '#' ) . '"#', $seen['page'] ) && false === strpos( $seen['page'], 'wp-login.php' ), substr( $seen['page'], strpos( $seen['page'], '<form' ) ?: 0, 400 ) );
check( '…titled without “Protected:”', 1 === preg_match( '#<title>Hidden Lisbon\b#', $seen['page'] ) && false === strpos( $seen['page'], 'Protected:' ), substr( $seen['page'], 0, 400 ) );
check( '…and nothing else of the trip', false === strpos( $seen['page'], 'Secret story' ) && false === strpos( $seen['page'], 'Lisbon day' ) && false === strpos( $seen['page'], 'ts-map' ) && false === strpos( $seen['page'], $pw_photo ) && false === strpos( $seen['page'], $pw_cover ) && false === strpos( $seen['page'], 'ts-hero-img' ) && false === strpos( $seen['page'], 'ts-stats' ) );
check( '…no wrong-password message on a first visit', false === strpos( $seen['page'], 'That password isn’t right' ) );
check( 'password: REST, the photo proxy and core REST for its steps refuse a visitor', $refused( $seen['rest']->get_status() ) && $refused( $seen['proxy']->get_status() ) && '' === $seen['photo'] && '' === $seen['cover'] && $refused( $seen['step'] ), [ $seen['rest']->get_status(), $seen['proxy']->get_status(), $seen['photo'], $seen['cover'], $seen['step'] ] );
check( 'password: not listed to a visitor', false === strpos( $seen['list'], 'Hidden Lisbon' ) );
check( '…and core REST neither serves nor lists it to them', 'shut' === $seen['core'], $seen['core'] );
// Core's ?password= isn't a way in: the trip page's cookie is the only one.
$res = $get( '/wp/v2/ts_trip/' . $pw_a, [ 'password' => 'open sesame' ] );
check( '…not even with core’s ?password= and the right password', $refused( $res->get_status() ), [ $res->get_status(), $res->get_data() ] );

// A trip page render that redirects: where to, or null if it rendered, leaving the page in $page.
$pw_redirect = function ( $trip, &$page = null ) use ( $render ) {
	$to    = null;
	$catch = function ( $location ) use ( &$to ) {
		$to = $location;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $catch );
	$level = ob_get_level();
	$page  = null;
	try {
		$page = $render( 'trip.php', (string) $trip );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	remove_filter( 'wp_redirect', $catch );
	return $to;
};
check( 'password: the form page is kept out of page caches', $pw_nocache( $pw_a ) );

// A shared link with the flag, from someone who got in, shows a first visitor no message.
$_GET = [ 'tried' => '1' ];
check( '…no wrong-password message for a flagged link without core’s cookie', false === strpos( $render( 'trip.php', (string) $pw_a ), 'That password isn’t right' ) );
$_GET = [];

// A wrong password shows the form again, saying so; the form's handler adds "tried".
$pw_enter( 'open sesame?' );
$_GET = [ 'tried' => '1' ];
$page = $render( 'trip.php', (string) $pw_a );
check( 'wrong password: the form again, saying so', false !== strpos( $page, 'name="post_password"' ) && false !== strpos( $page, 'That password isn’t right' ) && false === strpos( $page, 'Secret story' ) );
$_GET = [];
check( '…but not without the flag, as with another trip’s password', false === strpos( $render( 'trip.php', (string) $pw_a ), 'That password isn’t right' ) );
$pw_seen = $pw_core( $pw_a );
check( 'wrong password: core REST neither serves nor lists it', 'shut' === $pw_seen, $pw_seen );

// The right one lands on the flagged URL, which drops the flag so it can't be shared.
$pw_enter( 'open sesame' );
$_GET = [ 'tried' => '1' ];
$pw_to = $pw_redirect( $pw_a );
$_GET = [];
check( 'right password: the flagged URL redirects to the plain trip URL', Trips::url( get_post( $pw_a ) ) === $pw_to, $pw_to );
check( '…the unlocked page is kept out of page caches too', $pw_nocache( $pw_a ) );

// There it shows the whole trip, its photos kept out of shared caches.
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'right password: the whole trip, and no message', false !== strpos( $seen['page'], 'Secret story' ) && false === strpos( $seen['page'], 'That password isn’t right' ) && false === strpos( $seen['page'], 'name="post_password"' ) );
check( '…REST and its steps let them in', 200 === $seen['rest']->get_status() && 200 === $seen['step'], [ $seen['rest']->get_status(), $seen['step'] ] );
check( '…and core REST serves and lists it', 'open' === $seen['core'], $seen['core'] );
check( '…its photos and REST are private', 'private' === $seen['photo'] && 'private' === $seen['cover'] && 0 === strpos( $pw_cache( $seen['rest'] ), 'private' ), [ $seen['photo'], $seen['cover'], $pw_cache( $seen['rest'] ) ] );
check( '…and its photo URLs need no nonce', false !== strpos( $seen['page'], $pw_photo ) && false === strpos( $seen['page'], '_wpnonce' ) && false !== strpos( $seen['page'], 'data-nonce=""' ) );
check( '…but the landing page still doesn’t list it', false === strpos( $seen['list'], 'Hidden Lisbon' ) );

// The form posts to the trip page, which sets core's cookie as wp-login.php?action=postpass
// would and sends the visitor back flagged. Returns where to, the Set-Cookie header for
// core's cookie, or null if it set none, and the page if it rendered one.
$pw_post = function ( $trip, $password, array $extra = [] ) use ( $pw_redirect ) {
	header_remove( 'Set-Cookie' );
	$_POST    = $_REQUEST = [ 'post_password' => $password ] + $extra;
	$to       = $pw_redirect( $trip, $page );
	$_POST    = $_REQUEST = [];
	$cookie   = null;
	foreach ( headers_list() as $header ) {
		if ( 0 === strpos( $header, 'Set-Cookie: wp-postpass_' . COOKIEHASH . '=' ) ) {
			$cookie = $header;
		}
	}
	header_remove( 'Set-Cookie' );
	return [ $to, $cookie, $page ];
};
// Takes the cookie a post set, as the browser would.
$pw_keep = function ( ?string $header ) {
	preg_match( '#=([^;]*)#', (string) $header, $m );
	$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = urldecode( $m[1] ?? '' );
};
$pw_flagged = add_query_arg( 'tried', '1', Trips::url( get_post( $pw_a ) ) );
$pw_enter( null );
[ $pw_to, $pw_cookie ] = $pw_post( $pw_a, 'open sesame' );
check( 'posting the right password: back to the trip, flagged, with core’s cookie', $pw_flagged === $pw_to && null !== $pw_cookie, [ $pw_to, $pw_cookie ] );
$pw_keep( $pw_cookie );
check( '…which core takes as the trip’s password', ! post_password_required( get_post( $pw_a ) ) );
$_GET  = [ 'tried' => '1' ];
$pw_to = $pw_redirect( $pw_a );
$_GET  = [];
check( '…whose flagged URL then drops the flag', Trips::url( get_post( $pw_a ) ) === $pw_to, $pw_to );
check( '…to show the trip', false !== strpos( $render( 'trip.php', (string) $pw_a ), 'Secret story' ) );
$pw_enter( null );

// A wrong one is set all the same, as core does, and the flagged page says so.
[ $pw_to, $pw_cookie ] = $pw_post( $pw_a, 'open sesame?' );
$pw_keep( $pw_cookie );
$_GET = [ 'tried' => '1' ];
$page = $render( 'trip.php', (string) $pw_a );
$_GET = [];
check( 'posting a wrong password: back flagged, with core’s cookie, and the form says so', $pw_flagged === $pw_to && null !== $pw_cookie && false !== strpos( $page, 'That password isn’t right' ) && false === strpos( $page, 'Secret story' ), [ $pw_to, $pw_cookie ] );
$pw_enter( null );

// Not a string: no cookie, back flagged, as core does.
[ $pw_to, $pw_cookie ] = $pw_post( $pw_a, [ 'x' ] );
check( 'posting post_password[]: back flagged, without a cookie', $pw_flagged === $pw_to && null === $pw_cookie, [ $pw_to, $pw_cookie ] );

// Where to is always the trip: nothing in the request picks it.
$pw_referer              = $_SERVER['HTTP_REFERER'] ?? null;
$_SERVER['HTTP_REFERER'] = 'http://example.com/';
[ $pw_to ] = $pw_post( $pw_a, 'open sesame', [ 'redirect_to' => 'http://example.com/', '_wp_http_referer' => home_url( '/elsewhere' ) ] );
if ( null === $pw_referer ) {
	unset( $_SERVER['HTTP_REFERER'] );
} else {
	$_SERVER['HTTP_REFERER'] = $pw_referer;
}
check( '…a redirect_to or referer in the request is ignored', $pw_flagged === $pw_to, $pw_to );

// Core's cookie: its 10 days, core's filter on them, path, and secure on an https site.
$pw_attr = function ( ?string $cookie, string $name ) {
	return preg_match( '#;\s*' . $name . '(?:=([^;]*))?#i', (string) $cookie, $m ) ? ( $m[1] ?? true ) : null;
};
[ , $pw_cookie ] = $pw_post( $pw_a, 'open sesame' );
check( 'the cookie lasts 10 days, at core’s path, not secure over http', abs( (int) $pw_attr( $pw_cookie, 'Max-Age' ) - 10 * DAY_IN_SECONDS ) < 5 && COOKIEPATH === $pw_attr( $pw_cookie, 'path' ) && null === $pw_attr( $pw_cookie, 'secure' ), $pw_cookie );
$pw_expires = fn() => time() + HOUR_IN_SECONDS;
add_filter( 'post_password_expires', $pw_expires );
[ , $pw_cookie ] = $pw_post( $pw_a, 'open sesame' );
remove_filter( 'post_password_expires', $pw_expires );
check( '…post_password_expires changes that', abs( (int) $pw_attr( $pw_cookie, 'Max-Age' ) - HOUR_IN_SECONDS ) < 5, $pw_cookie );
add_filter( 'post_password_expires', '__return_zero' );
[ , $pw_cookie ] = $pw_post( $pw_a, 'open sesame' );
remove_filter( 'post_password_expires', '__return_zero' );
check( '…to a session cookie with 0', null !== $pw_cookie && null === $pw_attr( $pw_cookie, 'expires' ) && null === $pw_attr( $pw_cookie, 'Max-Age' ), $pw_cookie );
$pw_https = fn( $url ) => set_url_scheme( $url, 'https' );
add_filter( 'home_url', $pw_https );
[ $pw_to, $pw_cookie ] = $pw_post( $pw_a, 'open sesame' );
remove_filter( 'home_url', $pw_https );
check( '…and secure on an https site', 0 === strpos( (string) $pw_to, 'https://' ) && true === $pw_attr( $pw_cookie, 'secure' ), [ $pw_to, $pw_cookie ] );

// A post to a trip without a password, a draft or no trip is just a visit: no cookie, the same page.
[ $pw_open ] = $pw_trip( 'Open Lisbon' );
[ $pw_draft ] = $pw_trip( 'Draft Lisbon' );
wp_update_post( [ 'ID' => $pw_draft, 'post_status' => 'draft', 'post_password' => 'draft sesame' ] );
wp_set_current_user( 0 );
foreach ( [ 'a trip without a password' => (string) $pw_open, 'a draft with one' => (string) $pw_draft, 'no trip' => 'no-such-trip' ] as $what => $slug ) {
	[ $pw_to, $pw_cookie, $posted ] = $pw_post( $slug, 'open sesame' );
	check( "posting to $what: no cookie, the page as for a visit", null === $pw_to && null === $pw_cookie && $render( 'trip.php', $slug ) === $posted, [ $pw_to, $pw_cookie ] );
}

// Changing the password locks out those who had the old one.
$pw_set( $pw_a, 'new sesame' );
check( 'a changed password locks out the old one', ! Trips::can_view( get_post( $pw_a ) ) );
$pw_enter( null );

// Its editors see it without a password, and the landing page badges it for them.
// Logged-in users REST knows only by a nonce, so their photo URLs carry one, the cover's too.
$pw_nonced = fn( string $html, string $asset ) => 1 === preg_match( '#/photo/' . $asset . '\?[^"]*_wpnonce=#', $html );
foreach ( [ 'author' => $alice, 'editor' => $eddie ] as $role => $user ) {
	wp_set_current_user( $user );
	$seen = $pw_reach( $pw_a, $pw_a_step );
	check( "the trip’s $role sees it without the password", false !== strpos( $seen['page'], 'Secret story' ) && 200 === $seen['rest']->get_status() && 'private' === $seen['photo'] );
	$edit = $get( '/wp/v2/ts_trip/' . $pw_a, [ 'context' => 'edit' ] );
	check( "…core REST serves and lists it to them, for editing too", 'open' === $seen['core'] && 200 === $edit->get_status() && 'new sesame' === ( $edit->get_data()['password'] ?? null ), [ $seen['core'], $edit->get_status() ] );
	check( "…listed to them with a “Password protected” badge", 1 === preg_match( '#<span class="ts-badge">Password protected</span>(?:(?!</a>).)*Hidden Lisbon#s', $seen['list'] ) );
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
	return false !== strpos( $seen['page'], 'Secret story' ) && 200 === $seen['rest']->get_status() && 'private' === $seen['photo'] && 200 === $seen['step'] && 'open' === $seen['core'];
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
wp_set_current_user( $hal );
$pw_seen = $pw_core( $pw_a );
check( '…and core REST neither serves nor lists it to them', 'shut' === $pw_seen, $pw_seen );
$res = $get( '/wp/v2/ts_trip/' . $pw_a, [ 'password' => 'new sesame' ] );
check( '…not even with core’s ?password= and the right password', $refused( $res->get_status() ), [ $res->get_status(), $res->get_data() ] );
$pw_enter( 'new sesame' );
check( '…in with the password, but still not listed to them', $pw_sees( $hal, $pw_a, $pw_a_step ) && ! $pw_listed( $hal, 'Hidden Lisbon' ) );
$pw_enter( null );
check( 'logged out, a listed user is a visitor', ! $pw_sees( 0, $pw_a, $pw_a_step ) );

// The author's list: on their settings page, checkboxes of everyone who can't already edit their trips.
$pw_box = fn( string $html, int $user ) => preg_match( '#<input type="checkbox" name="viewers\[\]" value="' . $user . '"( checked)?#', $html, $m ) ? ( isset( $m[1] ) ? 'checked' : 'box' ) : 'none';
wp_set_current_user( $alice );
$page = $render( 'settings.php', '' );
$pw_boxes = array_map( fn( $u ) => $pw_box( $page, $u ), [ $frank, $gina, $hal, $alice, $eddie, 1 ] );
check( 'author’s list: a checkbox per user, theirs ticked', [ 'checked', 'box', 'box', 'none', 'none', 'none' ] === $pw_boxes, $pw_boxes );
wp_set_current_user( $bob );
check( '…only the author sees it', 'box' === $pw_box( $render( 'settings.php', '' ), $frank ) );
$_POST = $_REQUEST = [ 'viewers' => [ (string) $gina ], '_wpnonce' => wp_create_nonce( 'travel_steps_account' ) ];
$stopped( [ Settings::class, 'handle_save_account' ] );
check( '…and only they change it', [ $frank, 999999 ] === Settings::viewers( $alice ) && [ $gina ] === Settings::viewers( $bob ), [ Settings::viewers( $alice ), Settings::viewers( $bob ) ] );
wp_set_current_user( $alice );
$_POST = $_REQUEST = [ 'viewers' => [ (string) $gina, (string) $hal ], '_wpnonce' => wp_create_nonce( 'travel_steps_account' ) ];
check( 'author’s list: saved', 'redirect' === $stopped( [ Settings::class, 'handle_save_account' ] ) && [ $gina, $hal ] === Settings::viewers( $alice ), Settings::viewers( $alice ) );
$_POST = $_REQUEST = [ '_wpnonce' => wp_create_nonce( 'travel_steps_account' ) ];
check( '…and cleared', 'redirect' === $stopped( [ Settings::class, 'handle_save_account' ] ) && [] === Settings::viewers( $alice ), Settings::viewers( $alice ) );
Settings::update_account( $bob, [ 'viewers' => [] ] + Settings::account( $bob ) );

// The trip's password and list: on its edit form, for anyone who can edit it.
wp_set_current_user( $eddie );
$edit = $render( 'trip-edit.php', (string) $pw_a );
check( 'trip form: its password, in plain text', 1 === preg_match( '#<input type="text" name="password"[^>]*value="new sesame"#', $edit ), substr( $edit, strpos( $edit, 'name="password"' ) ?: 0, 300 ) );
$pw_boxes = array_map( fn( $u ) => $pw_box( $edit, $u ), [ $gina, $frank, $hal, $alice, $eddie, 1 ] );
check( 'trip form: a checkbox per user who can’t already edit it, its list ticked', [ 'checked', 'box', 'box', 'none', 'none', 'none' ] === $pw_boxes, $pw_boxes );
$pw_save = function ( array $fields ) use ( $pw_a, $stopped ) {
	$_POST = $_REQUEST = $fields + [ 'trip_id' => $pw_a, 'title' => 'Hidden Lisbon', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
	$result = $stopped( [ new Trips(), 'handle_save_trip' ] );
	$_POST = $_REQUEST = [];
	clean_post_cache( $pw_a );
	return $result;
};
$quoted = 'it\'s "a\\b" <ok>';
check( 'trip form: an editor sets its password and list', 'redirect' === $pw_save( [ 'password' => wp_slash( $quoted ), 'viewers' => [ (string) $hal ] ] ) && $quoted === get_post( $pw_a )->post_password && [ $hal ] === Trips::viewers( $pw_a ), [ get_post( $pw_a )->post_password, Trips::viewers( $pw_a ) ] );
// Typed into the form, slashed as PHP's request handling in WordPress does, it unlocks the trip.
wp_set_current_user( 0 );
[ , $pw_cookie ] = $pw_post( $pw_a, wp_slash( $quoted ) );
$pw_keep( $pw_cookie );
check( '…a password with quotes and a backslash, typed into the form, unlocks the trip', ! Trips::needs_password( get_post( $pw_a ) ) );
$pw_enter( null );
wp_set_current_user( $bob );
check( '…anyone else is refused', 'die' === $pw_save( [ 'password' => '', 'viewers' => [ (string) $bob ] ] ) && $quoted === get_post( $pw_a )->post_password && [ $hal ] === Trips::viewers( $pw_a ) );
wp_set_current_user( $eddie );
check( 'trip form: an editor clears both', 'redirect' === $pw_save( [ 'password' => '' ] ) && '' === get_post( $pw_a )->post_password && [] === Trips::viewers( $pw_a ) );
check( 'trip form: a private trip keeps no password', 'redirect' === $pw_save( [ 'status' => 'private', 'password' => 'secret' ] ) && '' === get_post( $pw_a )->post_password, get_post( $pw_a )->post_password );
$pw_save( [ 'status' => 'publish' ] );

// Without its password the trip is public again, whatever the lists say.
update_post_meta( $pw_a, Trips::META_VIEWERS, [ $gina ] );
Settings::update_account( $alice, [ 'viewers' => [ $frank ] ] + Settings::account( $alice ) );
wp_set_current_user( 0 );
$seen = $pw_reach( $pw_a, $pw_a_step );
check( 'password removed: public again', false !== strpos( $seen['page'], 'Secret story' ) && 'public' === $seen['photo'] && 0 === strpos( $pw_cache( $seen['rest'] ), 'public' ) && false !== strpos( $seen['list'], 'Hidden Lisbon' ) );
wp_set_current_user( $frank );
check( '…and a listed user’s photos carry no nonce', false === strpos( $render( 'trip.php', (string) $pw_a ), '_wpnonce' ) );

// Core REST counts a collection (X-WP-Total) before it drops what a user can't see, so a
// trip or step they can't see must not match the query at all, or the count answers
// questions about it: a guessed slug, a date range, a word in it.
$ct_trip = function ( string $title, string $word, string $date ) use ( $pw_trip ) {
	[ $id, $step ] = $pw_trip( $title );
	wp_update_post( [ 'ID' => $id, 'post_date' => $date, 'post_date_gmt' => $date, 'post_content' => "<!-- wp:paragraph --><p>$word trip</p><!-- /wp:paragraph -->" ] );
	wp_update_post( [ 'ID' => $step, 'post_content' => "<!-- wp:paragraph --><p>$word step</p><!-- /wp:paragraph -->" ] );
	return [ $id, $step ];
};
[ $ct_hidden, $ct_hidden_step ] = $ct_trip( 'Quokka Island', 'Quokka', '2024-02-03 04:05:06' );
[ $ct_draft, $ct_draft_step ]   = $ct_trip( 'Numbat Bay', 'Numbat', '2024-03-03 04:05:06' );
[ $ct_open, $ct_open_step ]     = $ct_trip( 'Dingo Creek', 'Dingo', '2024-04-03 04:05:06' );
$pw_set( $ct_hidden, 'quokka' );
wp_update_post( [ 'ID' => $ct_draft, 'post_status' => 'draft' ] );
update_post_meta( $ct_hidden, Trips::META_VIEWERS, [ $gina ] );
Settings::update_account( $alice, [ 'viewers' => [ $frank ] ] + Settings::account( $alice ) );
$ct_total = function ( string $route, array $params ) use ( $get ) {
	$res = $get( $route, $params );
	return 200 === $res->get_status() ? (int) ( $res->get_headers()['X-WP-Total'] ?? -1 ) : 'status ' . $res->get_status();
};
// X-WP-Total for a trip, asked about by its right slug, a minute around its date, and a word from it.
$ct_asks = function ( int $trip, string $word ) use ( $ct_total ) {
	$post = get_post( $trip );
	$at   = strtotime( $post->post_date . ' UTC' );
	return [
		'slug'   => $ct_total( '/wp/v2/ts_trip', [ 'include' => [ $trip ], 'slug' => [ $post->post_name ] ] ),
		'dates'  => $ct_total( '/wp/v2/ts_trip', [ 'include' => [ $trip ], 'after' => gmdate( 'Y-m-d\TH:i:s', $at - 60 ), 'before' => gmdate( 'Y-m-d\TH:i:s', $at + 60 ) ] ),
		'search' => $ct_total( '/wp/v2/ts_trip', [ 'include' => [ $trip ], 'search' => $word, 'search_columns' => [ 'post_content' ] ] ),
	];
};
$ct_none = [ 'slug' => 0, 'dates' => 0, 'search' => 0 ];
$ct_one  = [ 'slug' => 1, 'dates' => 1, 'search' => 1 ];
// X-WP-Total for a step, found by a word from its story, with and without include.
$ct_step = fn( int $step, string $word ) => [ $ct_total( '/wp/v2/ts_step', [ 'search' => $word ] ), $ct_total( '/wp/v2/ts_step', [ 'search' => $word, 'include' => [ $step ] ] ) ];

foreach ( [ 'a visitor' => [ 0, null ], 'a visitor with a wrong password' => [ 0, 'quokka?' ], 'a user on neither list' => [ $hal, null ] ] as $who => [ $user, $password ] ) {
	wp_set_current_user( $user );
	$pw_enter( $password );
	$ct_seen = $ct_asks( $ct_hidden, 'Quokka' );
	check( "core REST’s trip count: nothing of a protected trip for $who", $ct_none === $ct_seen, $ct_seen );
}
$pw_enter( null );
foreach ( [ 'a visitor' => 0, 'a user who can’t see the trip' => $hal ] as $who => $user ) {
	wp_set_current_user( $user );
	$ct_seen = [ 'protected' => $ct_step( $ct_hidden_step, 'Quokka' ), 'draft' => $ct_step( $ct_draft_step, 'Numbat' ) ];
	check( "core REST’s step count: nothing of a protected or draft trip’s steps for $who", [ 'protected' => [ 0, 0 ], 'draft' => [ 0, 0 ] ] === $ct_seen, $ct_seen );
}

foreach ( [ 'its author' => $alice, 'an editor' => $eddie, 'a user on the author’s list' => $frank, 'a user on its list' => $gina ] as $who => $user ) {
	wp_set_current_user( $user );
	$ct_seen = $ct_asks( $ct_hidden, 'Quokka' );
	check( "core REST’s trip count: a protected trip counts for $who", $ct_one === $ct_seen, $ct_seen );
	$ct_seen = $ct_step( $ct_hidden_step, 'Quokka' );
	check( "…and its step", [ 1, 1 ] === $ct_seen, $ct_seen );
}
foreach ( [ 'its author' => $alice, 'an editor' => $eddie ] as $who => $user ) {
	wp_set_current_user( $user );
	$ct_seen = $ct_step( $ct_draft_step, 'Numbat' );
	check( "core REST’s step count: a draft trip’s step counts for $who", [ 1, 1 ] === $ct_seen, $ct_seen );
}
// Asking by its status or by 'any': another author may, but someone else's draft,
// private or scheduled trip isn't counted.
[ $ct_private ] = $ct_trip( 'Wombat Hill', 'Wombat', '2024-06-03 04:05:06' );
[ $ct_future ]  = $ct_trip( 'Emu Plains', 'Emu', '2099-01-01 00:00:00' ); // Published with a future date, so scheduled.
wp_update_post( [ 'ID' => $ct_private, 'post_status' => 'private' ] );
$ct_seen = [];
foreach ( [ 'draft' => $ct_draft, 'private' => $ct_private, 'future' => $ct_future ] as $status => $trip ) {
	foreach ( [ 'another author' => $bob, 'an editor' => $eddie ] as $who => $user ) {
		wp_set_current_user( $user );
		foreach ( [ $status, 'any' ] as $asked ) {
			$ct_seen[ get_post_status( $trip ) ][ $who ][] = $ct_total( '/wp/v2/ts_trip', [ 'status' => [ $asked ], 'include' => [ $trip ] ] );
		}
	}
}
// By its status, then by 'any'.
$ct_want = array_fill_keys( [ 'draft', 'private', 'future' ], [ 'another author' => [ 0, 0 ], 'an editor' => [ 1, 1 ] ] );
check( 'core REST’s trip count by status: a draft, private or scheduled trip counts for an editor, not another author, by its status or any', $ct_want === $ct_seen, $ct_seen );
// can_view() reads no meta of an unpublished trip, so finding the hidden ones doesn't
// load it; a password trip's viewer lists are read, so its meta is loaded with it.
wp_set_current_user( 0 );
wp_cache_delete( $ct_draft, 'post_meta' );
wp_cache_delete( $ct_hidden, 'post_meta' );
$ct_seen = [
	'draft hidden'     => in_array( $ct_draft, Trips::hidden_trip_ids( [ 'draft' ] ), true ),
	'draft meta'       => false !== wp_cache_get( $ct_draft, 'post_meta' ),
	'protected hidden' => in_array( $ct_hidden, Trips::hidden_trip_ids( [ 'publish' ] ), true ),
	'protected meta'   => false !== wp_cache_get( $ct_hidden, 'post_meta' ),
];
check( 'hidden trips: an unpublished trip’s meta isn’t loaded to find them, a protected one’s is', [ 'draft hidden' => true, 'draft meta' => false, 'protected hidden' => true, 'protected meta' => true ] === $ct_seen, $ct_seen );
// Trashing a trip leaves its steps published, but they go with it.
[ $ct_trashed, $ct_trashed_step ] = $ct_trip( 'Koala Ridge', 'Koala', '2024-08-03 04:05:06' );
wp_trash_post( $ct_trashed );
wp_set_current_user( 0 );
$ct_seen = [ get_post_status( $ct_trashed_step ), $ct_step( $ct_trashed_step, 'Koala' ) ];
check( 'core REST’s step count: nothing of a trashed trip’s steps for a visitor', [ 'publish', [ 0, 0 ] ] === $ct_seen, $ct_seen );
// Core leaves a trip with a password out of a logged-out user's searches whatever they know.
wp_set_current_user( 0 );
$pw_enter( 'quokka' );
$ct_seen = $ct_asks( $ct_hidden, 'Quokka' );
check( 'core REST’s trip count: a protected trip counts for a visitor with its password, but for core’s search', [ 'slug' => 1, 'dates' => 1, 'search' => 0 ] === $ct_seen, $ct_seen );
$ct_seen = $ct_step( $ct_hidden_step, 'Quokka' );
check( '…and its step', [ 1, 1 ] === $ct_seen, $ct_seen );
$pw_enter( null );

$ct_seen = [ $ct_asks( $ct_open, 'Dingo' ), $ct_step( $ct_open_step, 'Dingo' ) ];
check( 'core REST’s counts: a trip without a password and its step count for a visitor', [ $ct_one, [ 1, 1 ] ] === $ct_seen, $ct_seen );
$ct_seen = [
	'only hidden' => $ct_total( '/wp/v2/ts_trip', [ 'include' => [ $ct_hidden ] ] ),
	'mixed'       => array_column( $get( '/wp/v2/ts_trip', [ 'include' => [ $ct_hidden, $ct_open ] ] )->get_data(), 'id' ),
	'step'        => $ct_total( '/wp/v2/ts_step', [ 'include' => [ $ct_hidden_step, $ct_draft_step ] ] ),
];
check( '…include naming only hidden posts matches nothing, and drops them from the rest', [ 'only hidden' => 0, 'mixed' => [ $ct_open ], 'step' => 0 ] === $ct_seen, $ct_seen );
$ct_seen = $ct_total( '/wp/v2/ts_trip', [ 'exclude' => [ $ct_open ], 'slug' => [ get_post( $ct_open )->post_name, get_post( $ct_hidden )->post_name ] ] );
check( '…and an exclude in the request still applies alongside the hidden trips', 0 === $ct_seen, $ct_seen );
// Steps have no parent parameter, but a plugin's rest_ts_step_query filter may add one.
$ct_parent = fn( $args ) => [ 'post_parent__in' => [ $ct_hidden, $ct_draft ] ] + $args;
add_filter( 'rest_' . Trips::STEP . '_query', $ct_parent );
$ct_seen = [ $ct_step( $ct_hidden_step, 'Quokka' ), $ct_total( '/wp/v2/ts_step', [] ) ];
remove_filter( 'rest_' . Trips::STEP . '_query', $ct_parent );
check( '…and steps asked for by hidden trips match nothing', [ [ 0, 0 ], 0 ] === $ct_seen, $ct_seen );
// p, page_id and attachment_id win over post__in, and a numeric post_parent over
// post_parent__in, so one set by a filter to a hidden trip must match nothing too.
$ct_by = function ( string $route, string $type, array $vars ) use ( $ct_total ) {
	$set = fn( $args ) => $vars + $args;
	add_filter( "rest_{$type}_query", $set );
	$total = $ct_total( $route, [] );
	remove_filter( "rest_{$type}_query", $set );
	return $total;
};
$ct_seen = [
	'p'             => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'p' => $ct_hidden ] ),
	'page_id'       => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'page_id' => $ct_hidden ] ),
	'attachment_id' => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'attachment_id' => $ct_hidden ] ),
	'post_parent'   => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'post_parent' => $ct_hidden ] ),
	'open p'        => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'p' => $ct_open ] ),
	'open parent'   => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'post_parent' => $ct_open ] ),
];
check( '…and a hidden trip named by p, page_id, attachment_id or post_parent matches nothing', [ 'p' => 0, 'page_id' => 0, 'attachment_id' => 0, 'post_parent' => 0, 'open p' => 1, 'open parent' => 1 ] === $ct_seen, $ct_seen );
// WP_Query replaces its whole WHERE clause with one for page_id, post_parent__not_in and
// all, so a step named by it must still be left out when its trip is hidden. As in core,
// page_id wins when p is set too.
$ct_seen = [
	'page_id'      => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'page_id' => $ct_hidden_step ] ),
	'open page_id' => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'page_id' => $ct_open_step ] ),
	'p, page_id'   => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'p' => $ct_open_step, 'page_id' => $ct_hidden_step ] ),
	'page_id, p'   => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'p' => $ct_hidden_step, 'page_id' => $ct_open_step ] ),
];
check( '…and a step of a hidden trip named by page_id matches nothing, whatever p says', [ 'page_id' => 0, 'open page_id' => 1, 'p, page_id' => 0, 'page_id, p' => 1 ] === $ct_seen, $ct_seen );
// WP_Query turns these into the hidden trip's ID: (int) for p, subpost_id through
// attachment_id, and absint for each entry of an __in list.
$ct_seen = [
	'p'               => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'p' => "{$ct_hidden}abc" ] ),
	'subpost_id'      => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'subpost_id' => $ct_hidden ] ),
	'post__in'        => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'post__in' => [ "0{$ct_hidden}" ] ] ),
	'post__in space'  => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'post__in' => [ "{$ct_hidden} " ] ] ),
	'post_parent__in' => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'post_parent__in' => [ "0{$ct_hidden}" ] ] ),
	'open p'          => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'p' => "{$ct_open}abc" ] ),
	'open subpost_id' => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'subpost_id' => $ct_open ] ),
	'open post__in'   => $ct_by( '/wp/v2/ts_trip', Trips::TRIP, [ 'post__in' => [ "0{$ct_open}", "0{$ct_hidden}" ] ] ),
	'open parent__in' => $ct_by( '/wp/v2/ts_step', Trips::STEP, [ 'post_parent__in' => [ "0{$ct_open}" ] ] ),
];
$ct_want = [ 'p' => 0, 'subpost_id' => 0, 'post__in' => 0, 'post__in space' => 0, 'post_parent__in' => 0, 'open p' => 1, 'open subpost_id' => 1, 'open post__in' => 1, 'open parent__in' => 1 ];
check( '…and so does one named the way WP_Query reads it: a p of "<id>abc", a subpost_id, or an __in entry of "0<id>"', $ct_want === $ct_seen, $ct_seen );
// A step of no trip, of a trip deleted since, or of another post is nobody's but its editors'.
wp_set_current_user( 1 );
[ $ct_gone, $ct_gone_step ] = $ct_trip( 'Bunyip Gorge', 'Bunyip', '2024-05-03 04:05:06' );
wp_delete_post( $ct_gone, true );
wp_set_current_user( 0 ); // So Trips::step_parent() lets a step go on a page.
$ct_page   = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Not a trip', 'post_status' => 'publish' ] );
$ct_strays = [
	$ct_gone_step,
	wp_insert_post( [ 'post_type' => Trips::STEP, 'post_title' => 'Bunyip alone', 'post_status' => 'publish' ] ),
	wp_insert_post( [ 'post_type' => Trips::STEP, 'post_title' => 'Bunyip on a page', 'post_status' => 'publish', 'post_parent' => $ct_page ] ),
];
$ct_seen = $ct_total( '/wp/v2/ts_step', [ 'search' => 'Bunyip' ] );
check( 'core REST’s step count: nothing of steps without a trip', 0 === $ct_seen && $ct_page === get_post( $ct_strays[2] )->post_parent, $ct_seen );
foreach ( array_merge( $ct_strays, [ $ct_page ] ) as $ct_post ) {
	wp_delete_post( $ct_post, true );
}
wp_set_current_user( 1 );
