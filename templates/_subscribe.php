<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flag from the form's redirect.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * The trip page's subscribe form, or its Unsubscribe button for a subscriber. Included by
 * trip.php with $trip set, for a trip the visitor can see.
 */
use TravelSteps\Roundup;
use TravelSteps\Subscriptions;
use TravelSteps\Trips;

$subscription = Subscriptions::current( $trip->ID );
// A logged-in subscriber who no longer gets the roundup (the password changed) may subscribe again.
$subscribed = $subscription && Roundup::reaches( $trip, $subscription );

$notice  = isset( $_GET[ Subscriptions::NOTICE_ARG ] ) && is_string( $_GET[ Subscriptions::NOTICE_ARG ] ) ? sanitize_key( $_GET[ Subscriptions::NOTICE_ARG ] ) : '';
$notices = [
	'check'        => __( 'Thanks. Check your email for a link to confirm your subscription. If you were subscribed already, nothing changes.', 'travel-steps' ),
	'subscribed'   => __( 'You’re subscribed. You’ll get an email on days when new days or photos are added.', 'travel-steps' ),
	'unsubscribed' => __( 'You’re unsubscribed from this trip.', 'travel-steps' ),
];
?>
<section class="ts-subscribe" id="ts-subscribe">
	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<p class="ts-notice" role="status"><?php echo esc_html( $notices[ $notice ] ); ?></p>
	<?php endif; ?>
	<form class="ts-subscribe-form" method="post" action="<?php echo esc_url( Trips::url( $trip ) . '#ts-subscribe' ); ?>">
		<?php if ( is_user_logged_in() ) : ?>
			<?php wp_nonce_field( 'travel_steps_subscription_' . $trip->ID, Subscriptions::NONCE_FIELD ); ?>
		<?php endif; ?>
		<?php if ( $subscribed ) : ?>
			<p class="ts-muted"><?php esc_html_e( 'You get an email on days when new days or photos are added to this trip.', 'travel-steps' ); ?></p>
			<button type="submit" class="ts-button ts-button-secondary" name="ts_subscription" value="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'travel-steps' ); ?></button>
		<?php elseif ( is_user_logged_in() ) : ?>
			<p class="ts-muted">
				<?php
				/* translators: %s: email address */
				echo esc_html( sprintf( __( 'Get an email at %s on days when new days or photos are added to this trip.', 'travel-steps' ), wp_get_current_user()->user_email ) );
				?>
			</p>
			<button type="submit" class="ts-button" name="ts_subscription" value="subscribe"><?php esc_html_e( 'Subscribe', 'travel-steps' ); ?></button>
		<?php else : ?>
			<label for="ts-subscribe-email"><?php esc_html_e( 'Get an email on days when new days or photos are added to this trip.', 'travel-steps' ); ?></label>
			<div class="ts-subscribe-row">
				<input type="email" id="ts-subscribe-email" name="ts_email" required autocomplete="email" placeholder="<?php esc_attr_e( 'Your email address', 'travel-steps' ); ?>">
				<button type="submit" class="ts-button" name="ts_subscription" value="subscribe"><?php esc_html_e( 'Subscribe', 'travel-steps' ); ?></button>
			</div>
			<?php // People don't see this field; bots fill it in. ?>
			<p class="ts-subscribe-trap" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this empty', 'travel-steps' ); ?> <input type="text" name="ts_website" tabindex="-1" autocomplete="off"></label>
			</p>
		<?php endif; ?>
	</form>
</section>
