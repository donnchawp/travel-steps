<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The link's key is checked instead: it comes from an email.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\App;
use TravelSteps\Roundup;
use TravelSteps\Subscriptions;
use TravelSteps\Trips;

// Opening the link only asks: mail scanners open every link in an email. The button
// (a form with no action, so it posts to this same link), or a mail client's one-click
// unsubscribe, POSTs back to it, and that acts. No cache may keep either answer.
nocache_headers();
$posted = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
// The page the button's unsubscribe sends the browser on to (below) only says it's done.
$result = 'unsubscribed' === ( $_GET[ Subscriptions::NOTICE_ARG ] ?? '' )
	? [
		'state' => 'unsubscribed',
		'trip'  => null,
		'sub'   => 0,
	]
	: Subscriptions::handle_link( wp_unslash( $_GET ), $posted );
// The subscription is gone after the button unsubscribes, so reloading would post again and
// find nothing: send the browser on. A mail client's one-click POST (RFC 8058) doesn't press
// the button, so it gets the page.
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reads whether the button was pressed; the link's key was checked.
if ( $posted && 'unsubscribed' === $result['state'] && isset( $_POST['travel_steps_button'] ) ) {
	wp_safe_redirect( add_query_arg( Subscriptions::NOTICE_ARG, 'unsubscribed', App::url( 'subscription' ) ), 303 );
	exit;
}
$trip   = $result['trip'];
$shown  = $trip && Subscriptions::offered( $trip );
// The share code only for a subscription that still gets the roundup: a logged-in
// subscriber cut off by a password change keeps the links in their old emails.
$sub    = 'confirmed' === $result['state'] ? Subscriptions::get( $result['sub'] ) : null;
$link   = $shown && $sub && Roundup::reaches( $trip, $sub ) ? Roundup::trip_link( $trip ) : ( $shown ? Trips::url( $trip ) : '' );
$texts  = [
	'confirm'      => [ __( 'Confirm your subscription', 'travel-steps-storyteller' ), __( 'Press the button to get an email on days when new days or photos are added to this trip.', 'travel-steps-storyteller' ), __( 'Confirm subscription', 'travel-steps-storyteller' ) ],
	'unsubscribe'  => [ __( 'Stop emails about this trip?', 'travel-steps-storyteller' ), __( 'Press the button to stop getting emails about this trip.', 'travel-steps-storyteller' ), __( 'Unsubscribe', 'travel-steps-storyteller' ) ],
	'confirmed'    => [ __( 'You’re subscribed', 'travel-steps-storyteller' ), __( 'You’ll get an email on days when new days or photos are added to this trip. Each one has a link to unsubscribe.', 'travel-steps-storyteller' ) ],
	'unsubscribed' => [ __( 'You’re unsubscribed', 'travel-steps-storyteller' ), __( 'You won’t get any more emails about this trip.', 'travel-steps-storyteller' ) ],
	'expired'      => [ __( 'This link has expired', 'travel-steps-storyteller' ), __( 'Confirmation links work for 7 days. Sign up again on the trip’s page to get a new one.', 'travel-steps-storyteller' ) ],
	'invalid'      => [ __( 'This link doesn’t work', 'travel-steps-storyteller' ), __( 'It may have been used already. If you’re still getting emails, use the unsubscribe link in the latest one.', 'travel-steps-storyteller' ) ],
];
// A state with a third entry asks: that is its button's label.
[ $page_title, $message, $button ] = $texts[ $result['state'] ] + [ 2 => '' ];
if ( 'invalid' === $result['state'] ) {
	status_header( 404 );
}
include __DIR__ . '/_header.php';
?>
<div class="ts-page ts-narrow">
	<h1><?php echo esc_html( $page_title ); ?></h1>
	<?php if ( $shown ) : ?>
		<p class="ts-muted"><?php echo esc_html( get_the_title( $trip ) ); ?></p>
	<?php endif; ?>
	<p><?php echo esc_html( $message ); ?></p>
	<?php if ( $button ) : ?>
		<form method="post">
			<p><button type="submit" class="ts-button" name="travel_steps_button" value="1"><?php echo esc_html( $button ); ?></button></p>
		</form>
	<?php elseif ( $shown ) : ?>
		<p><a class="ts-button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View the trip', 'travel-steps-storyteller' ); ?></a></p>
	<?php else : ?>
		<p><a href="<?php echo esc_url( App::url() ); ?>"><?php esc_html_e( 'All trips', 'travel-steps-storyteller' ); ?></a></p>
	<?php endif; ?>
</div>
<?php
include __DIR__ . '/_footer.php';
