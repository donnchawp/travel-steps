<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flag from the password form's redirect.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * A published trip's title and password form, for a visitor who needs its password.
 * Set $trip before including. The form posts to the trip page, where
 * Trips::handle_password_post() sets core's cookie and sends the visitor back with "tried"
 * added, so a visit with that flag and the cookie which still needs the password follows a
 * wrong one. Without the cookie it's a copied link, not a wrong guess.
 */
use TravelSteps\Trips;

$page_title = get_the_title( $trip );
include __DIR__ . '/_header.php';
?>
<div class="ts-page ts-narrow">
	<h1><?php echo esc_html( $page_title ); ?></h1>
	<form class="ts-form ts-password-form" method="post" action="<?php echo esc_url( Trips::url( $trip ) ); ?>">
		<?php if ( isset( $_GET['tried'], $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] ) ) : ?>
			<p class="ts-notice is-error" role="alert"><?php esc_html_e( 'That password isn’t right.', 'travel-steps' ); ?></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'This trip is password protected. Enter its password to see it.', 'travel-steps' ); ?></p>
		<label>
			<span><?php esc_html_e( 'Password', 'travel-steps' ); ?></span>
			<input type="password" name="post_password" required autocomplete="current-password" spellcheck="false">
		</label>
		<div class="ts-form-actions">
			<button type="submit" class="ts-button"><?php esc_html_e( 'See the trip', 'travel-steps' ); ?></button>
		</div>
	</form>
</div>
<?php
include __DIR__ . '/_footer.php';
