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
$refused = fn( int $status ) => in_array( $status, [ 401, 403, 404 ], true );
check( 'password: REST, the photo proxy and core REST for its steps refuse a visitor', $refused( $seen['rest']->get_status() ) && $refused( $seen['proxy']->get_status() ) && '' === $seen['photo'] && '' === $seen['cover'] && $refused( $seen['step'] ), [ $seen['rest']->get_status(), $seen['proxy']->get_status(), $seen['photo'], $seen['cover'], $seen['step'] ] );
check( 'password: not listed to a visitor', false === strpos( $seen['list'], 'Hidden Lisbon' ) );

// A trip page render that redirects: where to, or null if it rendered.
$pw_redirect = function ( $trip ) use ( $render ) {
	$to    = null;
	$catch = function ( $location ) use ( &$to ) {
		$to = $location;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $catch );
	$level = ob_get_level();
	try {
		$render( 'trip.php', (string) $trip );
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
check( '…its photos and REST are private', 'private' === $seen['photo'] && 'private' === $seen['cover'] && 0 === strpos( $pw_cache( $seen['rest'] ), 'private' ), [ $seen['photo'], $seen['cover'], $pw_cache( $seen['rest'] ) ] );
check( '…and its photo URLs need no nonce', false !== strpos( $seen['page'], $pw_photo ) && false === strpos( $seen['page'], '_wpnonce' ) && false !== strpos( $seen['page'], 'data-nonce=""' ) );
check( '…but the landing page still doesn’t list it', false === strpos( $seen['list'], 'Hidden Lisbon' ) );

// The form posts to the trip page, which sets core's cookie as wp-login.php?action=postpass
// would and sends the visitor back flagged. Returns where to, and the Set-Cookie header for
// core's cookie, or null if it set none.
$pw_post = function ( $trip, $password, array $extra = [] ) use ( $pw_redirect ) {
	header_remove( 'Set-Cookie' );
	$_POST    = $_REQUEST = [ 'post_password' => $password ] + $extra;
	$to       = $pw_redirect( $trip );
	$_POST    = $_REQUEST = [];
	$cookie   = null;
	foreach ( headers_list() as $header ) {
		if ( 0 === strpos( $header, 'Set-Cookie: wp-postpass_' . COOKIEHASH . '=' ) ) {
			$cookie = $header;
		}
	}
	header_remove( 'Set-Cookie' );
	return [ $to, $cookie ];
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
$_GET = [ 'tried' => '1' ];
$pw_to = $pw_redirect( $pw_a );
$_GET = [];
check( '…and the flagged page then drops the flag', Trips::url( get_post( $pw_a ) ) === $pw_to, $pw_to );
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
$_SERVER['HTTP_REFERER'] = 'http://example.com/';
[ $pw_to ] = $pw_post( $pw_a, 'open sesame', [ 'redirect_to' => 'http://example.com/', '_wp_http_referer' => home_url( '/elsewhere' ) ] );
unset( $_SERVER['HTTP_REFERER'] );
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
	[ $pw_to, $pw_cookie ] = $pw_post( $slug, 'open sesame' );
	$same = false;
	if ( null === $pw_to ) {
		$_POST = $_REQUEST = [ 'post_password' => 'open sesame' ];
		$posted = $render( 'trip.php', $slug );
		$_POST = $_REQUEST = [];
		$same = $render( 'trip.php', $slug ) === $posted;
	}
	check( "posting to $what: no cookie, the page as for a visit", null === $pw_to && null === $pw_cookie && $same, [ $pw_to, $pw_cookie ] );
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
wp_set_current_user( 1 );
