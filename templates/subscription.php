<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The link's key is checked instead: it comes from an email.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\App;
use TravelSteps\Roundup;
use TravelSteps\Subscriptions;

// Each visit acts, so no cache may keep the answer.
nocache_headers();
$result = Subscriptions::handle_link( wp_unslash( $_GET ) );
$trip   = $result['trip'];
$shown  = $trip && Subscriptions::offered( $trip );
$texts  = [
	'confirmed'    => [ __( 'You’re subscribed', 'travel-steps' ), __( 'You’ll get an email on days when new days or photos are added to this trip. Each one has a link to unsubscribe.', 'travel-steps' ) ],
	'unsubscribed' => [ __( 'You’re unsubscribed', 'travel-steps' ), __( 'You won’t get any more emails about this trip.', 'travel-steps' ) ],
	'expired'      => [ __( 'This link has expired', 'travel-steps' ), __( 'Confirmation links work for 7 days. Sign up again on the trip’s page to get a new one.', 'travel-steps' ) ],
	'invalid'      => [ __( 'This link doesn’t work', 'travel-steps' ), __( 'It may have been used already. If you’re still getting emails, use the unsubscribe link in the latest one.', 'travel-steps' ) ],
];
[ $page_title, $message ] = $texts[ $result['state'] ];
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
	<?php if ( $shown ) : ?>
		<p><a class="ts-button" href="<?php echo esc_url( Roundup::trip_link( $trip ) ); ?>"><?php esc_html_e( 'View the trip', 'travel-steps' ); ?></a></p>
	<?php else : ?>
		<p><a href="<?php echo esc_url( App::url() ); ?>"><?php esc_html_e( 'All trips', 'travel-steps' ); ?></a></p>
	<?php endif; ?>
</div>
<?php
include __DIR__ . '/_footer.php';
