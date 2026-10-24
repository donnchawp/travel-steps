<?php
/**
 * Subscriptions to a trip and the daily roundup emailed to them: signing up from the trip
 * page, confirming and unsubscribing by link, what a roundup lists and who gets it.
 * Required at the end of smoke.php, in its scope: uses check(), row(), $render and $stopped.
 */
use TravelSteps\Roundup;
use TravelSteps\Settings;
use TravelSteps\Subscriptions;
use TravelSteps\Trips;

require_once ABSPATH . WPINC . '/class-phpass.php';

// Every email the site would send, in place of sending it.
$sb_mails = [];
add_filter(
	'pre_wp_mail',
	function ( $sent, $atts ) use ( &$sb_mails ) {
		$sb_mails[] = $atts;
		return true;
	},
	10,
	2
);
$sb_author = wp_insert_user( [ 'user_login' => 'sb_author', 'user_email' => 'author@example.org', 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
$sb_friend = wp_insert_user( [ 'user_login' => 'sb_friend', 'user_email' => 'friend@example.org', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'display_name' => 'Friendly Fiona' ] );
// A published trip with a published step on 1 May and a draft step on 2 May, each with photos.
$sb_rows  = [
	row( 61, '2025-05-01', '09:00', 38.7, -9.1, 'Lisbon', true ),
	row( 62, '2025-05-02', '09:00', 38.7, -9.1, 'Lisbon', true ),
	row( 63, '2025-05-03', '09:00', 38.7, -9.1, 'Lisbon', true ),
];
$sb_trip = function ( string $title, string $password = '' ) use ( $sb_author, $sb_rows ) {
	wp_set_current_user( 1 );
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $sb_author, 'post_password' => $password ] );
	update_post_meta( $id, Trips::META_START, '2025-05-01' );
	update_post_meta( $id, Trips::META_END, '2025-05-03' );
	update_post_meta( $id, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_slice( $sb_rows, 0, 2 ) ) ) );
	$steps = [];
	foreach ( [ [ '2025-05-01', 'publish', 'Alfama' ], [ '2025-05-02', 'draft', 'Draft day' ] ] as [ $day, $status, $name ] ) {
		$steps[] = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $id, 'post_title' => $name, 'post_status' => $status, 'post_author' => $sb_author, 'post_date' => "$day 12:00:00", 'edit_date' => true, 'post_content' => '<!-- wp:paragraph --><p>Story</p><!-- /wp:paragraph -->' ] );
	}
	wp_set_current_user( 0 );
	return [ $id, $steps ];
};
// A trip page request: the page, or where it redirected to.
$sb_page = function ( int $trip, array $post = [], array $get = [], array $headers = [] ) use ( $render ) {
	$_SERVER                   = $headers + $_SERVER;
	$_POST                     = $post;
	$_GET                      = $get;
	$_REQUEST                  = $post + $get;
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
	$to                        = null;
	$die                       = fn() => function () use ( &$to ) {
		$to = 'die';
		throw new RuntimeException( 'die' );
	};
	$catch                     = function ( $location ) use ( &$to ) {
		$to = $location;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $catch );
	add_filter( 'wp_die_handler', $die );
	$level = ob_get_level();
	try {
		$page = $render( 'trip.php', (string) $trip );
	} catch ( RuntimeException $e ) {
		$page = null;
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	remove_filter( 'wp_redirect', $catch );
	remove_filter( 'wp_die_handler', $die );
	$_POST                     = [];
	$_GET                      = [];
	$_REQUEST                  = [];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	foreach ( array_keys( $headers ) as $header ) {
		unset( $_SERVER[ $header ] );
	}
	return $to ?? $page;
};
// The page a link in an email opens, given its URL; or, with a POST, the page its
// button leads to ($post is the body: none from the button, RFC 8058's from a mail client).
$sb_link = function ( string $url, string $method = 'GET', array $post = [] ) use ( $render ) {
	wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );
	$_GET                      = $query;
	$_POST                     = $post;
	$_SERVER['REQUEST_METHOD'] = $method;
	$page                      = $render( 'subscription.php', '' );
	$_GET                      = [];
	$_POST                     = [];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $page;
};
// Opening a link in an email and pressing its button.
$sb_press = fn( string $url ) => $sb_link( $url, 'POST' );
// The form on a link's page, if it has one: [ action, method, button text ].
$sb_form = function ( string $page ) {
	if ( ! preg_match( '#<form[^>]*method="post"[^>]*action="([^"]*)"[^>]*>.*?<button[^>]*>(.*?)</button>.*?</form>#s', $page, $m ) ) {
		return null;
	}
	return [ html_entity_decode( $m[1] ), 'post', trim( wp_strip_all_tags( $m[2] ) ) ];
};
$sb_subs   = fn( int $trip, string $status = '' ) => array_values(
	array_filter(
		Subscriptions::for_trip( $trip ),
		fn( $s ) => '' === $status || $status === $s['status']
	)
);
$sb_url_in = fn( string $text, string $do ) => preg_match( '#https?://\S*do=' . $do . '\S*#', $text, $m ) ? $m[0] : '';

// Signing up as a visitor.
[ $sb_pub, $sb_pub_steps ] = $sb_trip( 'Lisbon in May' );
$page = $sb_page( $sb_pub );
check( 'subscribe: a public trip’s page has the email form', false !== strpos( $page, 'name="ts_email"' ) && false !== strpos( $page, 'name="ts_website"' ), substr( $page, 0, 300 ) );
$sb_mails = [];
$to       = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => ' Mary@Example.org ', 'ts_website' => '' ] );
check( '…signing up goes back to the trip with “check your email”', is_string( $to ) && false !== strpos( $to, 'subscription=check' ), $to );
$pending = $sb_subs( $sb_pub );
check( '…and makes a pending subscription for the address', 1 === count( $pending ) && 'pending' === $pending[0]['status'] && 'mary@example.org' === $pending[0]['email'], $pending );
check( '…and sends one confirmation email to it', 1 === count( $sb_mails ) && 'mary@example.org' === $sb_mails[0]['to'], $sb_mails );
$confirm = $sb_url_in( $sb_mails[0]['message'] ?? '', 'confirm' );
check( '…with a confirmation link', '' !== $confirm, $sb_mails[0]['message'] ?? '' );
$page = $sb_page( $sb_pub, [], [ 'subscription' => 'check' ] );
check( '…and the trip page says to check your email', false !== strpos( $page, 'ts-notice' ) && false !== stripos( $page, 'check your email' ) );

$sb_mails = [];
foreach ( [ 'Mary@example.org', 'MARY@example.org' ] as $again ) {
	$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => $again, 'ts_website' => '' ] );
}
check( 'subscribe: signing up again the same day sends nothing more', [] === $sb_mails && 1 === count( $sb_subs( $sb_pub ) ), $sb_mails );
$to = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'bot@example.org', 'ts_website' => 'http://spam.example' ] );
check( 'subscribe: a filled-in honeypot gets the same answer and nothing is sent or kept', false !== strpos( (string) $to, 'subscription=check' ) && [] === $sb_mails && 1 === count( $sb_subs( $sb_pub ) ), [ $to, $sb_mails ] );
$to = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'not an address', 'ts_website' => '' ] );
check( 'subscribe: an address that isn’t one gets the same answer, nothing sent', false !== strpos( (string) $to, 'subscription=check' ) && [] === $sb_mails && 1 === count( $sb_subs( $sb_pub ) ), [ $to, $sb_mails ] );

// Confirming: opening the link only asks, so a mail scanner fetching it changes nothing.
$page = $sb_link( $confirm );
check( 'confirm: opening the link leaves the subscription pending', 'pending' === ( $sb_subs( $sb_pub )[0]['status'] ?? '' ), $sb_subs( $sb_pub ) );
check( '…sets no cookie', empty( $_COOKIE[ Subscriptions::cookie( $sb_pub ) ] ) );
$form = $sb_form( (string) $page );
check( '…and shows a Confirm subscription button that posts back to the link', $form && html_entity_decode( $confirm ) === $form[0] && 'Confirm subscription' === $form[2], [ $form, substr( (string) $page, 0, 600 ) ] );
$page = $sb_press( $confirm );
check( 'confirm: the button confirms the subscription', 'confirmed' === ( $sb_subs( $sb_pub )[0]['status'] ?? '' ) && false !== stripos( $page, 'subscribed' ), [ $sb_subs( $sb_pub ), substr( wp_strip_all_tags( $page ), 0, 300 ) ] );
check( '…and sets a cookie that recognises the subscriber on this browser', ! empty( $_COOKIE[ Subscriptions::cookie( $sb_pub ) ] ) );
$page = $sb_page( $sb_pub );
check( '…so the trip page offers Unsubscribe in place of the form', false === strpos( $page, 'name="ts_email"' ) && false !== strpos( $page, 'value="unsubscribe"' ) );
$sb_mails = [];
$to       = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'mary@example.org', 'ts_website' => '' ] );
check( 'subscribe: an address already subscribed gets the same answer, and no email', false !== strpos( (string) $to, 'subscription=check' ) && [] === $sb_mails && 'confirmed' === $sb_subs( $sb_pub )[0]['status'] );
$bad = preg_replace( '/key=[^&]+/', 'key=wrong', $confirm );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'jo@example.org', 'ts_website' => '' ] );
$jo_confirm = $sb_url_in( end( $sb_mails )['message'] ?? '', 'confirm' );
$page = $sb_link( preg_replace( '/key=[^&]+/', 'key=wrong', $jo_confirm ) );
check( 'confirm: opening a link with a wrong key says it doesn’t work, with no button', false !== stripos( (string) $page, 'doesn’t work' ) && null === $sb_form( (string) $page ) );
$sb_press( preg_replace( '/key=[^&]+/', 'key=wrong', $jo_confirm ) );
check( 'confirm: a wrong key confirms nothing, opened or pressed', 'pending' === ( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'jo@example.org' ] ) ? array_values( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'jo@example.org' ] ) )[0]['status'] : '' ) );

// Unsubscribing with the button, as the visitor the cookie names.
$to = $sb_page( $sb_pub, [ 'ts_subscription' => 'unsubscribe' ] );
check( 'unsubscribe: the button drops the subscription the cookie names', false !== strpos( (string) $to, 'subscription=unsubscribed' ) && ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'mary@example.org' ] ), [ $to, $sb_subs( $sb_pub ) ] );
check( '…and the page has the form again', false !== strpos( $sb_page( $sb_pub ), 'name="ts_email"' ) );

// Logged-in visitors subscribe their account with a button.
wp_set_current_user( $sb_friend );
$page = $sb_page( $sb_pub );
check( 'logged in: the page has a Subscribe button and no address field', false !== strpos( $page, 'value="subscribe"' ) && false === strpos( $page, 'name="ts_email"' ) );
$sb_mails = [];
$nonce    = wp_create_nonce( 'travel_steps_subscription_' . $sb_pub );
$to       = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => $nonce ] );
$mine     = wp_list_filter( $sb_subs( $sb_pub ), [ 'user' => $sb_friend ] );
check( 'logged in: Subscribe subscribes the account at once, with no email', false !== strpos( (string) $to, 'subscription=subscribed' ) && 1 === count( $mine ) && 'confirmed' === current( $mine )['status'] && [] === $sb_mails, [ $to, $mine, $sb_mails ] );
check( '…and the page then offers Unsubscribe', false !== strpos( $sb_page( $sb_pub ), 'value="unsubscribe"' ) );
$to = $sb_page( $sb_pub, [ 'ts_subscription' => 'unsubscribe', Subscriptions::NONCE_FIELD => $nonce ] );
check( 'logged in: Unsubscribe ends it', false !== strpos( (string) $to, 'subscription=unsubscribed' ) && ! wp_list_filter( $sb_subs( $sb_pub ), [ 'user' => $sb_friend ] ) );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => $nonce ] );
wp_set_current_user( 0 );

// The unsubscribe link in an email, opened or posted by a mail client's button.
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'link@example.org', 'ts_website' => '' ] );
$sb_press( $sb_url_in( end( $sb_mails )['message'], 'confirm' ) );
$link_sub = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'link@example.org' ] ) );
$sb_unsub  = Subscriptions::link( $link_sub, 'unsubscribe' );
$page      = $sb_link( $sb_unsub );
check( 'unsubscribe link: opening it keeps the subscription', (bool) wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'link@example.org' ] ) && false === stripos( $page, 'unsubscribed' ), $sb_subs( $sb_pub ) );
$form = $sb_form( (string) $page );
check( '…and shows an Unsubscribe button that posts back to the link', $form && $sb_unsub === $form[0] && 'Unsubscribe' === $form[2], $form );
$page = $sb_press( $sb_unsub );
check( 'unsubscribe link: the button ends the subscription', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'link@example.org' ] ) && false !== stripos( $page, 'unsubscribed' ) );
$page = $sb_link( $sb_unsub );
check( '…and opening the link again says it doesn’t work', false !== stripos( $page, 'doesn’t work' ) && null === $sb_form( (string) $page ) );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'post@example.org', 'ts_website' => '' ] );
$post_sub = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'post@example.org' ] ) );
$sb_link( Subscriptions::link( $post_sub, 'unsubscribe' ), 'POST', [ 'List-Unsubscribe' => 'One-Click' ] );
check( 'unsubscribe link: a mail client’s one-click POST ends it too', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'post@example.org' ] ) );

// A pending subscription expires after 7 days.
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'late@example.org', 'ts_website' => '' ] );
$late = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'late@example.org' ] ) );
update_post_meta( $late['id'], Subscriptions::META_MAILED, time() - 8 * DAY_IN_SECONDS );
$page = $sb_link( Subscriptions::link( $late, 'confirm' ) );
check( 'confirm: opening a link older than 7 days says it has expired, and keeps the signup for the daily run', false !== stripos( $page, 'expired' ) && null === $sb_form( (string) $page ) && wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'late@example.org' ] ) );
$page = $sb_press( Subscriptions::link( $late, 'confirm' ) );
check( 'confirm: pressing on a link older than 7 days says it has expired, and the signup is gone', false !== stripos( $page, 'expired' ) && ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'late@example.org' ] ) );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'later@example.org', 'ts_website' => '' ] );
$later = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'later@example.org' ] ) );
update_post_meta( $later['id'], Subscriptions::META_MAILED, time() - 8 * DAY_IN_SECONDS );
Subscriptions::expire_pending();
check( '…and the daily run drops expired signups nobody confirmed', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'later@example.org' ] ) && wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'jo@example.org' ] ) );

// Another site's form posting here.
$sb_home  = wp_parse_url( home_url() );
$sb_home  = $sb_home['scheme'] . '://' . $sb_home['host'] . ( isset( $sb_home['port'] ) ? ':' . $sb_home['port'] : '' );
$sb_mails = [];
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'crosssite@example.org', 'ts_website' => '' ], [], [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ] );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'origin@example.org', 'ts_website' => '' ], [], [ 'HTTP_ORIGIN' => 'https://elsewhere.example' ] );
check( 'a post from another site’s page is refused, by Sec-Fetch-Site or by Origin', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'crosssite@example.org' ] ) && ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'origin@example.org' ] ) && [] === $sb_mails, $sb_mails );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'sameorigin@example.org', 'ts_website' => '' ], [], [ 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => $sb_home ] );
check( '…while one from the trip page itself is taken', (bool) wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'sameorigin@example.org' ] ) && 1 === count( $sb_mails ), $sb_mails );

// Protected trips.
$sb_enter = function ( ?string $password ) {
	unset( $_COOKIE[ Trips::password_cookie() ] );
	if ( null !== $password ) {
		$_COOKIE[ Trips::password_cookie() ] = ( new PasswordHash( 8, true ) )->HashPassword( $password );
	}
};
[ $sb_prot, $sb_prot_steps ] = $sb_trip( 'Secret Lisbon', 'tagus' );
$sb_enter( null );
$page = $sb_page( $sb_prot );
check( 'protected: without the password there’s no form', false === strpos( (string) $page, 'name="ts_email"' ) && false !== strpos( (string) $page, 'name="post_password"' ) );
$sb_mails = [];
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'sneaky@example.org', 'ts_website' => '' ] );
check( '…and posting to it subscribes nobody', [] === $sb_subs( $sb_prot ) && [] === $sb_mails );
$sb_enter( 'tagus' );
check( 'protected: with the password there’s the form', false !== strpos( $sb_page( $sb_prot ), 'name="ts_email"' ) );
check( '…carrying a nonce, since a protected trip’s page is never cached', false !== strpos( $sb_page( $sb_prot ), 'name="' . Subscriptions::NONCE_FIELD . '"' ) );
check( '…and no referer field, which would carry a share link’s code', false === strpos( $sb_page( $sb_prot ), '_wp_http_referer' ) );
$sb_mails = [];
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'forged@example.org', 'ts_website' => '' ] );
check( 'protected: a post without the nonce, as another site’s form would send, subscribes nobody and sends nothing', ! wp_list_filter( $sb_subs( $sb_prot ), [ 'email' => 'forged@example.org' ] ) && [] === $sb_mails, $sb_mails );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'kin@example.org', 'ts_website' => '', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
$sb_press( $sb_url_in( end( $sb_mails )['message'], 'confirm' ) );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'pending@example.org', 'ts_website' => '', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
wp_set_current_user( $sb_friend );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
wp_set_current_user( 0 );
check( 'protected: visitors with the password subscribe', 3 === count( $sb_subs( $sb_prot ) ), $sb_subs( $sb_prot ) );

// Changing the password drops email-only subscriptions, from any editor.
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_prot, 'post_password' => 'douro' ] );
wp_set_current_user( 0 );
$left = $sb_subs( $sb_prot );
check( 'password change: email-only subscriptions are deleted, the account’s kept', 1 === count( $left ) && $sb_friend === $left[0]['user'], $left );
check( '…and the logged-in subscriber no longer gets the roundup', ! Roundup::reaches( get_post( $sb_prot ), $left[0] ) );
wp_set_current_user( $sb_friend );
$sb_enter( 'douro' );
check( '…so they’re offered Subscribe again', false !== strpos( $sb_page( $sb_prot ), 'value="subscribe"' ) );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
check( '…which brings it back', 1 === count( $sb_subs( $sb_prot ) ) && Roundup::reaches( get_post( $sb_prot ), $sb_subs( $sb_prot )[0] ) );
wp_set_current_user( 0 );
$sb_enter( null );
$sb_page_before = count( $sb_subs( $sb_pub ) );
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_pub, 'post_title' => 'Lisbon in May, again' ] );
wp_update_post( [ 'ID' => $sb_pub, 'post_title' => 'Lisbon in May' ] );
wp_set_current_user( 0 );
check( 'password change: other edits keep subscriptions', count( $sb_subs( $sb_pub ) ) === $sb_page_before );

// The daily roundup.
$sb_run = function () use ( &$sb_mails ) {
	$sb_mails = [];
	Roundup::run();
	return $sb_mails;
};
$sb_to   = fn( array $mails ) => array_column( $mails, 'to' );
$sb_add_photos = function ( int $trip, array $rows ) {
	update_post_meta( $trip, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_merge( Trips::photos( $trip ), $rows ) ) ) );
};
[ $sb_rt, [ $sb_rt_day1, $sb_rt_day2 ] ] = $sb_trip( 'Roundup Lisbon' );
$sb_page( $sb_rt, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'ana@example.org', 'ts_website' => '' ] );
$sb_press( $sb_url_in( end( $sb_mails )['message'], 'confirm' ) );
$sb_page( $sb_rt, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'unconfirmed@example.org', 'ts_website' => '' ] );
wp_set_current_user( $sb_friend );
$sb_page( $sb_rt, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_rt ) ] );
wp_set_current_user( 0 );
check( 'roundup: nothing is sent for what the trip had when they subscribed', [] === $sb_run() );

wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_rt_day1, 'post_content' => '<!-- wp:paragraph --><p>Story, longer</p><!-- /wp:paragraph -->' ] );
check( 'roundup: a day with only a text edit sends nothing', [] === $sb_run() );

wp_update_post( [ 'ID' => $sb_rt_day2, 'post_title' => 'Belém', 'post_status' => 'publish' ] );
$secret_draft = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $sb_rt, 'post_title' => 'Secret draft', 'post_status' => 'draft', 'post_date' => '2025-05-03 12:00:00', 'edit_date' => true ] );
$scheduled    = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $sb_rt, 'post_title' => 'Scheduled day', 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ] );
wp_set_current_user( 0 );
$mails = $sb_run();
check( 'roundup: a published step sends one email to each confirmed subscriber', [ 'ana@example.org', 'friend@example.org' ] === $sb_to( $mails ), $sb_to( $mails ) );
$ana  = $mails[0] ?? [ 'subject' => '', 'message' => '', 'headers' => [] ];
$body = $ana['message'];
check( '…its subject has the trip and what’s new', 'Roundup Lisbon: 1 new day' === $ana['subject'], $ana['subject'] );
check( '…it lists the new day with its link', false !== strpos( $body, 'Belém' ) && false !== strpos( $body, Trips::url( get_post( $sb_rt ) ) . '#step-' . $sb_rt_day2 ), $body );
check( '…and the earlier text edit under “Also updated”', 1 === preg_match( '/Also updated:\s+.*Alfama/s', $body ), $body );
check( '…but no draft or scheduled step', false === strpos( $body, 'Secret draft' ) && false === strpos( $body, 'Scheduled day' ) );
$ana_sub = current( wp_list_filter( $sb_subs( $sb_rt ), [ 'email' => 'ana@example.org' ] ) );
check( '…with the recipient’s own unsubscribe link', false !== strpos( $body, Subscriptions::link( $ana_sub, 'unsubscribe' ) ) && false === strpos( $mails[1]['message'] ?? '', Subscriptions::link( $ana_sub, 'unsubscribe' ) ) );
$headers = implode( "\n", (array) $ana['headers'] );
check( '…and the one-click unsubscribe headers', false !== strpos( $headers, 'List-Unsubscribe: <' . Subscriptions::link( $ana_sub, 'unsubscribe' ) . '>' ) && false !== strpos( $headers, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ), $headers );
check( '…in plain text', false !== stripos( $headers, 'text/plain' ) && false === strpos( $body, '<' ), $headers );
check( 'roundup: the next run, with nothing new, sends nothing', [] === $sb_run() );

// New photos.
$sb_hidden = row( 65, '2025-05-01', '15:00', 38.7, -9.1, 'Lisbon', true );
$sb_add_photos( $sb_rt, [ $sb_hidden ] );
update_post_meta( $sb_rt, Trips::META_HIDDEN, [ $sb_hidden['id'] ] );
check( 'roundup: a hidden photo sends nothing', [] === $sb_run() );
$sb_add_photos( $sb_rt, [ row( 66, '2025-05-03', '15:00', 38.7, -9.1, 'Lisbon', true ) ] );
check( '…nor a photo on a draft step’s day', [] === $sb_run() );
$sb_add_photos( $sb_rt, [ row( 67, '2025-05-01', '16:00', 38.7, -9.1, 'Lisbon', true ) ] );
$mails = $sb_run();
check( 'roundup: a new visible photo on a published step sends one', 2 === count( $mails ) && 1 === preg_match( '/New photos on:\s+.*Alfama/s', $mails[0]['message'] ?? '' ), $mails );
check( '…saying so in the subject', 'Roundup Lisbon: new photos' === ( $mails[0]['subject'] ?? '' ), $mails[0]['subject'] ?? '' );
update_post_meta( $sb_rt, Trips::META_HIDDEN, [] );
check( 'roundup: unhiding a photo shows it, and sends it as new', 1 === preg_match( '/New photos on:/', $sb_run()[0]['message'] ?? '' ) );
$sb_last = fn() => array_intersect_key( (array) get_post_meta( $sb_rt, Roundup::META_LAST, true ), array_flip( [ 'recipients', 'sent', 'failed' ] ) );
check( '…and records each email as sent', [ 'recipients' => 2, 'sent' => 2, 'failed' => 0 ] === $sb_last(), $sb_last() );
$sb_panel_of = fn( string $edit ) => substr( $edit, strpos( $edit, 'ts-subscribers' ) ?: 0, 1200 );
$sb_panel    = function () use ( $render, $sb_author, $sb_rt, $sb_panel_of ) {
	wp_set_current_user( $sb_author );
	$edit = $render( 'trip-edit.php', (string) $sb_rt );
	wp_set_current_user( 0 );
	return $sb_panel_of( $edit );
};
check( '…so the edit page names no failures', false === strpos( $sb_panel(), 'couldn’t be sent' ) && false === strpos( $sb_panel(), 'didn’t go out' ), $sb_panel() );

// Mail the site couldn't send is counted, never named.
$sb_refuse = fn( $sent, $atts ) => 'ana@example.org' === $atts['to'] ? false : $sent;
add_filter( 'pre_wp_mail', $sb_refuse, 20, 2 );
$sb_add_photos( $sb_rt, [ row( 68, '2025-05-01', '17:00', 38.7, -9.1, 'Lisbon', true ) ] );
$sb_run();
remove_filter( 'pre_wp_mail', $sb_refuse, 20 );
check( 'roundup: an email the site refuses is counted as failed', [ 'recipients' => 2, 'sent' => 1, 'failed' => 1 ] === $sb_last(), $sb_last() );
check( '…and not by address', false === strpos( (string) wp_json_encode( get_post_meta( $sb_rt, Roundup::META_LAST, true ) ), 'ana@' ) );
check( '…and the edit page says so', false !== strpos( $sb_panel(), '1 of 2 couldn’t be sent. Check the site’s mail settings.' ), $sb_panel() );
check( '…and it isn’t sent again', [] === $sb_run() );

// A run that dies part-way leaves the emails it didn't get to counted as not sent.
$sb_die = function ( $sent, $atts ) {
	if ( 'friend@example.org' === $atts['to'] ) {
		throw new \RuntimeException( 'timed out' );
	}
	return $sent;
};
add_filter( 'pre_wp_mail', $sb_die, 20, 2 );
$sb_add_photos( $sb_rt, [ row( 69, '2025-05-01', '18:00', 38.7, -9.1, 'Lisbon', true ) ] );
try {
	$sb_run();
} catch ( \RuntimeException ) {
	// The run died part-way, as meant.
}
remove_filter( 'pre_wp_mail', $sb_die, 20 );
check( 'roundup: a run that dies part-way records what it sent', [ 'recipients' => 2, 'sent' => 1, 'failed' => 0 ] === $sb_last(), $sb_last() );
check( '…and the edit page counts the rest as not sent', false !== strpos( $sb_panel(), '1 of 2 didn’t go out.' ), $sb_panel() );
check( '…without blaming the site’s mail', false === strpos( $sb_panel(), 'mail settings' ), $sb_panel() );

// A private trip sends nothing, and keeps its subscribers.
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_rt, 'post_status' => 'private' ] );
wp_update_post( [ 'ID' => $secret_draft, 'post_status' => 'publish' ] );
wp_set_current_user( 0 );
check( 'roundup: a private trip sends nothing', [] === $sb_run() && 3 === count( $sb_subs( $sb_rt ) ) );
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_rt, 'post_status' => 'publish' ] );
wp_set_current_user( 0 );
check( '…and once published again, sends what was added meanwhile', 'Roundup Lisbon: 1 new day' === ( $sb_run()[0]['subject'] ?? '' ) );

// A protected trip's links carry its share code, and a subscriber who lost access is skipped.
$sb_enter( 'douro' );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'kin@example.org', 'ts_website' => '', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
$sb_kin_confirm = $sb_url_in( end( $sb_mails )['message'], 'confirm' );
$sb_enter( null );
$page = $sb_link( $sb_kin_confirm );
check( 'protected: opening a confirm link shows no share code', null !== $sb_form( (string) $page ) && false === strpos( (string) $page, Trips::share_code( get_post( $sb_prot ) ) ), $page );
$page = $sb_press( $sb_kin_confirm );
check( '…while pressing its button leads to the trip with it', false !== strpos( (string) $page, Trips::share_code( get_post( $sb_prot ) ) ), $page );
$page = $sb_link( $sb_kin_confirm );
check( '…and opening it again, once confirmed, still shows none', null !== $sb_form( (string) $page ) && false === strpos( (string) $page, Trips::share_code( get_post( $sb_prot ) ) ), $page );
$sb_enter( null );
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_prot_steps[1], 'post_status' => 'publish' ] );
wp_set_current_user( 0 );
$mails = $sb_run();
check( 'roundup: a protected trip’s subscribers get it', [ 'friend@example.org', 'kin@example.org' ] === $sb_to( $mails ), $sb_to( $mails ) );
check( '…with the share code in its links', false !== strpos( $mails[0]['message'] ?? '', Trips::share_url( get_post( $sb_prot ) ) . '#step-' . $sb_prot_steps[1] ), $mails[0]['message'] ?? '' );
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_prot, 'post_password' => 'mondego' ] );
$sb_prot_day3 = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $sb_prot, 'post_title' => 'Day three', 'post_status' => 'publish', 'post_date' => '2025-05-03 12:00:00' ] );
wp_set_current_user( 0 );
check( 'roundup: after a password change the logged-in subscriber is skipped, and kept', [] === $sb_run() && 1 === count( $sb_subs( $sb_prot ) ) );
// The links in their old email mustn't hand them the new share code.
$sb_new_code = Trips::share_code( get_post( $sb_prot ) );
$sb_old_link = $sb_url_in( $mails[0]['message'] ?? '', 'unsubscribe' );
$page        = $sb_press( str_replace( 'do=unsubscribe', 'do=confirm', $sb_old_link ) );
check( 'password change: an old email’s link, as confirm, doesn’t show the new share code', '' !== $sb_old_link && false === strpos( (string) $page, $sb_new_code ) && false !== strpos( (string) $page, 'View the trip' ), $page );

// One address with an account subscription and an email-only one gets one email, and one unsubscribe ends both.
[ $sb_two, [ , $sb_two_day2 ] ] = $sb_trip( 'Both ways Lisbon' );
$sb_both = function () use ( $sb_two, $sb_page, $sb_press, $sb_url_in, &$sb_mails, $sb_friend ) {
	$sb_page( $sb_two, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'Friend@example.org', 'ts_website' => '' ] );
	$sb_press( $sb_url_in( end( $sb_mails )['message'] ?? '', 'confirm' ) );
	wp_set_current_user( $sb_friend );
	$sb_page( $sb_two, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_two ) ] );
	wp_set_current_user( 0 );
};
$sb_new_day = function ( string $title, string $date ) use ( $sb_two ) {
	wp_set_current_user( 1 );
	wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $sb_two, 'post_title' => $title, 'post_status' => 'publish', 'post_date' => "$date 12:00:00", 'edit_date' => true ] );
	wp_set_current_user( 0 );
};
$sb_both();
check( 'one address: an account and an email-only subscription to the same trip', 2 === count( $sb_subs( $sb_two, 'confirmed' ) ), $sb_subs( $sb_two ) );
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_two_day2, 'post_status' => 'publish' ] );
wp_set_current_user( 0 );
$mails = $sb_run();
check( '…get one email between them', [ 'friend@example.org' ] === $sb_to( $mails ), $sb_to( $mails ) );
$sb_press( $sb_url_in( $mails[0]['message'] ?? '', 'unsubscribe' ) );
check( '…and its unsubscribe link ends both', [] === $sb_subs( $sb_two ), $sb_subs( $sb_two ) );
$sb_new_day( 'Sintra', '2025-05-03' );
check( '…so the next roundup goes to nobody', [] === $sb_run() );
$sb_both();
wp_set_current_user( $sb_friend );
$sb_page( $sb_two, [ 'ts_subscription' => 'unsubscribe', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_two ) ] );
wp_set_current_user( 0 );
check( 'one address: the logged-in Unsubscribe button ends both too', [] === $sb_subs( $sb_two ), $sb_subs( $sb_two ) );
$sb_new_day( 'Cascais', '2025-05-04' );
check( '…so the next roundup goes to nobody', [] === $sb_run() );
$sb_both();
$sb_link( Subscriptions::link( $sb_subs( $sb_two )[0], 'unsubscribe' ), 'POST', [ 'List-Unsubscribe' => 'One-Click' ] );
check( 'one address: a mail client’s one-click unsubscribe ends both too', [] === $sb_subs( $sb_two ), $sb_subs( $sb_two ) );

// Deleting a user deletes their account's subscriptions.
$sb_gone = wp_insert_user( [ 'user_login' => 'sb_gone', 'user_email' => 'gone@example.org', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
wp_set_current_user( $sb_gone );
$sb_page( $sb_two, [ 'ts_subscription' => 'subscribe', Subscriptions::NONCE_FIELD => wp_create_nonce( 'travel_steps_subscription_' . $sb_two ) ] );
wp_set_current_user( 1 );
$sb_gone_subs = wp_list_filter( $sb_subs( $sb_two ), [ 'user' => $sb_gone ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sb_gone );
wp_set_current_user( 0 );
check( 'deleting a user deletes their subscriptions', 1 === count( $sb_gone_subs ) && ! wp_list_filter( $sb_subs( $sb_two ), [ 'user' => $sb_gone ] ), $sb_subs( $sb_two ) );

// When it runs.
wp_clear_scheduled_hook( Roundup::HOOK );
Roundup::schedule();
check( 'schedule: the roundup is hooked and scheduled for its next hour', false !== has_action( Roundup::HOOK, [ Roundup::class, 'run' ] ) && wp_next_scheduled( Roundup::HOOK ) === Roundup::next_time() );
$sb_tz = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Asia/Kolkata' );
check( 'schedule: changing the site’s timezone moves the next run', wp_next_scheduled( Roundup::HOOK ) === Roundup::next_time() );
update_option( 'timezone_string', 'Europe/Lisbon' );
$sb_at = fn( string $local ) => ( new DateTimeImmutable( $local, new DateTimeZone( 'Europe/Lisbon' ) ) )->getTimestamp();
check( 'schedule: by default at 08:00 site time, later the same day', $sb_at( '2026-07-01 08:00' ) === Roundup::next_time( $sb_at( '2026-07-01 07:30' ) ) );
check( '…or the next day, once it’s past', $sb_at( '2026-07-02 08:00' ) === Roundup::next_time( $sb_at( '2026-07-01 08:00' ) ) );
check( '…at 08:00 across the change of clocks too', $sb_at( '2026-10-26 08:00' ) === Roundup::next_time( $sb_at( '2026-10-25 09:00' ) ) );
update_option( Settings::OPTION, Settings::sanitize_site( [ 'roundup_hour' => '19' ] ) );
check( 'schedule: at the hour in the site settings', $sb_at( '2026-07-01 19:00' ) === Roundup::next_time( $sb_at( '2026-07-01 07:30' ) ) );
check( '…which is an hour of the day', [ 0, 23, 8 ] === array_map( fn( $h ) => Settings::sanitize_site( [ 'roundup_hour' => $h ] )['roundup_hour'], [ '-3', '30', 'x' ] ) );
Roundup::reschedule();
check( '…and changing it moves the next run', wp_next_scheduled( Roundup::HOOK ) === Roundup::next_time() );
update_option( Settings::OPTION, Settings::sanitize_site( [] ) );
update_option( 'timezone_string', $sb_tz );
Roundup::reschedule();

// The author's panel on the edit page.
wp_set_current_user( $sb_author );
$edit = $render( 'trip-edit.php', (string) $sb_rt );
check( 'edit page: lists subscribers by address, or name for an account', false !== strpos( $edit, 'ana@example.org' ) && false !== strpos( $edit, 'Friendly Fiona' ) && false !== strpos( $edit, 'unconfirmed@example.org' ) );
check( '…marks pending ones', 1 === preg_match( '/unconfirmed@example\.org.*?Pending/s', $edit ) );
check( '…counts them', false !== strpos( $edit, '2 subscribers' ), $sb_panel_of( $edit ) );
$edit = $render( 'trip-edit.php', (string) $sb_prot );
check( '…but not one who can’t see the trip any more', false !== strpos( $edit, '0 subscribers get' ) && false !== strpos( $edit, 'Friendly Fiona' ), $sb_panel_of( $edit ) );
check( '…and says when the last roundup went', false !== stripos( $edit, 'last roundup' ) );
wp_set_current_user( 0 );
$page = $sb_press( $sb_old_link );
check( 'password change: an old email’s unsubscribe link doesn’t show the new share code', false === strpos( (string) $page, $sb_new_code ) && 0 === count( $sb_subs( $sb_prot ) ), $page );
wp_set_current_user( $sb_author );
$sb_remove = function ( int $user, int $sub ) use ( $stopped ) {
	wp_set_current_user( $user );
	$_POST = $_REQUEST = [ 'subscription' => $sub, '_wpnonce' => wp_create_nonce( 'travel_steps_remove_subscriber' ) ];
	$result = $stopped( [ Subscriptions::class, 'handle_remove' ] );
	$_POST = $_REQUEST = [];
	return $result;
};
$ana_sub = current( wp_list_filter( $sb_subs( $sb_rt ), [ 'email' => 'ana@example.org' ] ) );
check( 'edit page: someone who can’t edit the trip can’t remove a subscriber', 'die' === $sb_remove( $sb_friend, $ana_sub['id'] ) && Subscriptions::get( $ana_sub['id'] ) );
check( '…but its author can', 'redirect' === $sb_remove( $sb_author, $ana_sub['id'] ) && ! Subscriptions::get( $ana_sub['id'] ) );
wp_set_current_user( $sb_friend );
check( 'edit page: no subscriber list for anyone else', false === strpos( $render( 'trip-edit.php', (string) $sb_rt ), 'Friendly Fiona' ) );
wp_set_current_user( 0 );

// Personal data: exported and erased by address.
$sb_exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
$sb_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
check( 'privacy: an exporter and an eraser are registered', isset( $sb_exporters['travel-steps-subscriptions'], $sb_erasers['travel-steps-subscriptions'] ) );
$sb_export = call_user_func( $sb_exporters['travel-steps-subscriptions']['callback'], 'friend@example.org', 1 );
check( '…the export lists an account’s subscriptions by its address', in_array( 'Roundup Lisbon', wp_list_pluck( array_merge( ...array_column( $sb_export['data'], 'data' ) ), 'value' ), true ), $sb_export );
$sb_erased = call_user_func( $sb_erasers['travel-steps-subscriptions']['callback'], 'unconfirmed@example.org', 1 );
check( '…and erasing an address deletes its subscriptions', $sb_erased['items_removed'] && ! wp_list_filter( $sb_subs( $sb_rt ), [ 'email' => 'unconfirmed@example.org' ] ), $sb_erased );
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$GLOBALS['current_screen']      = WP_Screen::get( 'dashboard' );
$GLOBALS['wp_current_filter'][] = 'admin_init';
TravelSteps\App::suggest_privacy_policy();
array_pop( $GLOBALS['wp_current_filter'] );
$GLOBALS['current_screen'] = null;
check( 'privacy: the policy text mentions subscriptions', false !== stripos( implode( ' ', wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text' ) ), 'subscribe' ) );

// Deleting a trip, and the plugin.
wp_set_current_user( 1 );
$sb_pub_subs = wp_list_pluck( $sb_subs( $sb_pub ), 'id' );
wp_delete_post( $sb_pub, true );
check( 'deleting a trip deletes its subscriptions', $sb_pub_subs && ! array_filter( array_map( [ Subscriptions::class, 'get' ], $sb_pub_subs ) ) );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'travel-steps/travel-steps.php' );
}
require WP_PLUGIN_DIR . '/travel-steps/uninstall.php';
check( 'uninstall: every subscription is deleted, and the roundup unscheduled', [] === get_posts( [ 'post_type' => Subscriptions::TYPE, 'post_status' => 'any', 'numberposts' => -1 ] ) && ! wp_next_scheduled( Roundup::HOOK ) );
wp_set_current_user( 0 );
