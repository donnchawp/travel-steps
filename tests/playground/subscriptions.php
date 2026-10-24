<?php
/**
 * Subscriptions to a trip and the daily roundup emailed to them: signing up from the trip
 * page, confirming and unsubscribing by link, what a roundup lists and who gets it.
 * Required at the end of smoke.php, in its scope: uses check() and $render.
 */
use TravelSteps\Roundup;
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
		$steps[] = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $id, 'post_title' => $name, 'post_status' => $status, 'post_author' => $sb_author, 'post_date' => "$day 12:00:00", 'post_content' => '<!-- wp:paragraph --><p>Story</p><!-- /wp:paragraph -->' ] );
	}
	wp_set_current_user( 0 );
	return [ $id, $steps ];
};
// A trip page request: the page, or where it redirected to.
$sb_page = function ( int $trip, array $post = [], array $get = [] ) use ( $render ) {
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
	return $to ?? $page;
};
// The page a link in an email opens, given its URL.
$sb_link = function ( string $url, string $method = 'GET' ) use ( $render ) {
	wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );
	$_GET                      = $query;
	$_SERVER['REQUEST_METHOD'] = $method;
	$page                      = $render( 'subscription.php', '' );
	$_GET                      = [];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $page;
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

// Confirming.
$page = $sb_link( $confirm );
check( 'confirm: the link confirms the subscription', 'confirmed' === ( $sb_subs( $sb_pub )[0]['status'] ?? '' ) && false !== stripos( $page, 'subscribed' ), [ $sb_subs( $sb_pub ), substr( wp_strip_all_tags( $page ), 0, 300 ) ] );
check( '…and sets a cookie that recognises the subscriber on this browser', ! empty( $_COOKIE[ Subscriptions::cookie( $sb_pub ) ] ) );
$page = $sb_page( $sb_pub );
check( '…so the trip page offers Unsubscribe in place of the form', false === strpos( $page, 'name="ts_email"' ) && false !== strpos( $page, 'value="unsubscribe"' ) );
$sb_mails = [];
$to       = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'mary@example.org', 'ts_website' => '' ] );
check( 'subscribe: an address already subscribed gets the same answer, and no email', false !== strpos( (string) $to, 'subscription=check' ) && [] === $sb_mails && 'confirmed' === $sb_subs( $sb_pub )[0]['status'] );
$bad = preg_replace( '/key=[^&]+/', 'key=wrong', $confirm );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'jo@example.org', 'ts_website' => '' ] );
$jo_confirm = $sb_url_in( end( $sb_mails )['message'] ?? '', 'confirm' );
$sb_link( preg_replace( '/key=[^&]+/', 'key=wrong', $jo_confirm ) );
check( 'confirm: a wrong key confirms nothing', 'pending' === ( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'jo@example.org' ] ) ? array_values( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'jo@example.org' ] ) )[0]['status'] : '' ) );

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
$to       = $sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', '_wpnonce' => $nonce ] );
$mine     = wp_list_filter( $sb_subs( $sb_pub ), [ 'user' => $sb_friend ] );
check( 'logged in: Subscribe subscribes the account at once, with no email', false !== strpos( (string) $to, 'subscription=subscribed' ) && 1 === count( $mine ) && 'confirmed' === current( $mine )['status'] && [] === $sb_mails, [ $to, $mine, $sb_mails ] );
check( '…and the page then offers Unsubscribe', false !== strpos( $sb_page( $sb_pub ), 'value="unsubscribe"' ) );
$to = $sb_page( $sb_pub, [ 'ts_subscription' => 'unsubscribe', '_wpnonce' => $nonce ] );
check( 'logged in: Unsubscribe ends it', false !== strpos( (string) $to, 'subscription=unsubscribed' ) && ! wp_list_filter( $sb_subs( $sb_pub ), [ 'user' => $sb_friend ] ) );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', '_wpnonce' => $nonce ] );
wp_set_current_user( 0 );

// The unsubscribe link in an email, opened or posted by a mail client's button.
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'link@example.org', 'ts_website' => '' ] );
$sb_link( $sb_url_in( end( $sb_mails )['message'], 'confirm' ) );
$link_sub = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'link@example.org' ] ) );
$page     = $sb_link( Subscriptions::link( $link_sub, 'unsubscribe' ) );
check( 'unsubscribe link: one click ends the subscription', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'link@example.org' ] ) && false !== stripos( $page, 'unsubscribed' ) );
$page = $sb_link( Subscriptions::link( $link_sub, 'unsubscribe' ) );
check( '…and a second click says the link doesn’t work', false !== stripos( $page, 'doesn’t work' ) );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'post@example.org', 'ts_website' => '' ] );
$post_sub = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'post@example.org' ] ) );
$sb_link( Subscriptions::link( $post_sub, 'unsubscribe' ), 'POST' );
check( 'unsubscribe link: a mail client’s one-click POST ends it too', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'post@example.org' ] ) );

// A pending subscription expires after 7 days.
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'late@example.org', 'ts_website' => '' ] );
$late = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'late@example.org' ] ) );
update_post_meta( $late['id'], Subscriptions::META_MAILED, time() - 8 * DAY_IN_SECONDS );
$page = $sb_link( Subscriptions::link( $late, 'confirm' ) );
check( 'confirm: a link older than 7 days has expired, and the signup is gone', false !== stripos( $page, 'expired' ) && ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'late@example.org' ] ) );
$sb_page( $sb_pub, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'later@example.org', 'ts_website' => '' ] );
$later = current( wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'later@example.org' ] ) );
update_post_meta( $later['id'], Subscriptions::META_MAILED, time() - 8 * DAY_IN_SECONDS );
Subscriptions::expire_pending();
check( '…and the daily run drops expired signups nobody confirmed', ! wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'later@example.org' ] ) && wp_list_filter( $sb_subs( $sb_pub ), [ 'email' => 'jo@example.org' ] ) );

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
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'kin@example.org', 'ts_website' => '' ] );
$sb_link( $sb_url_in( end( $sb_mails )['message'], 'confirm' ) );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', 'ts_email' => 'pending@example.org', 'ts_website' => '' ] );
wp_set_current_user( $sb_friend );
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', '_wpnonce' => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
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
$sb_page( $sb_prot, [ 'ts_subscription' => 'subscribe', '_wpnonce' => wp_create_nonce( 'travel_steps_subscription_' . $sb_prot ) ] );
check( '…which brings it back', 1 === count( $sb_subs( $sb_prot ) ) && Roundup::reaches( get_post( $sb_prot ), $sb_subs( $sb_prot )[0] ) );
wp_set_current_user( 0 );
$sb_enter( null );
$sb_page_before = count( $sb_subs( $sb_pub ) );
wp_set_current_user( 1 );
wp_update_post( [ 'ID' => $sb_pub, 'post_title' => 'Lisbon in May, again' ] );
wp_update_post( [ 'ID' => $sb_pub, 'post_title' => 'Lisbon in May' ] );
wp_set_current_user( 0 );
check( 'password change: other edits keep subscriptions', count( $sb_subs( $sb_pub ) ) === $sb_page_before );
