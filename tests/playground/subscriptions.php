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
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
	$to                        = null;
	$catch                     = function ( $location ) use ( &$to ) {
		$to = $location;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $catch );
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
	$_POST                     = [];
	$_GET                      = [];
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
