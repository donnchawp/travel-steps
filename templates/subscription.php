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
$result = Subscriptions::handle_link( wp_unslash( $_GET ), 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
$trip   = $result['trip'];
$shown  = $trip && Subscriptions::offered( $trip );
// The share code only for a subscription that still gets the roundup: a logged-in
// subscriber cut off by a password change keeps the links in their old emails.
$sub    = 'confirmed' === $result['state'] ? Subscriptions::get( $result['sub'] ) : null;
$link   = $shown && $sub && Roundup::reaches( $trip, $sub ) ? Roundup::trip_link( $trip ) : ( $shown ? Trips::url( $trip ) : '' );
$texts  = [
	'confirm'      => [ __( 'Confirm your subscription', 'travel-steps' ), __( 'Press the button to get an email on days when new days or photos are added to this trip.', 'travel-steps' ), __( 'Confirm subscription', 'travel-steps' ) ],
	'unsubscribe'  => [ __( 'Unsubscribe', 'travel-steps' ), __( 'Press the button to stop getting emails about this trip.', 'travel-steps' ), __( 'Unsubscribe', 'travel-steps' ) ],
	'confirmed'    => [ __( 'You’re subscribed', 'travel-steps' ), __( 'You’ll get an email on days when new days or photos are added to this trip. Each one has a link to unsubscribe.', 'travel-steps' ) ],
	'unsubscribed' => [ __( 'You’re unsubscribed', 'travel-steps' ), __( 'You won’t get any more emails about this trip.', 'travel-steps' ) ],
	'expired'      => [ __( 'This link has expired', 'travel-steps' ), __( 'Confirmation links work for 7 days. Sign up again on the trip’s page to get a new one.', 'travel-steps' ) ],
	'invalid'      => [ __( 'This link doesn’t work', 'travel-steps' ), __( 'It may have been used already. If you’re still getting emails, use the unsubscribe link in the latest one.', 'travel-steps' ) ],
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
			<p><button type="submit" class="ts-button"><?php echo esc_html( $button ); ?></button></p>
		</form>
	<?php elseif ( $shown ) : ?>
		<p><a class="ts-button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View the trip', 'travel-steps' ); ?></a></p>
	<?php else : ?>
		<p><a href="<?php echo esc_url( App::url() ); ?>"><?php esc_html_e( 'All trips', 'travel-steps' ); ?></a></p>
	<?php endif; ?>
</div>
<?php
include __DIR__ . '/_footer.php';
