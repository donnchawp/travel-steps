<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flag from core's password form redirect.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * A published trip's title and password form, for a visitor who needs its password.
 * Set $trip before including. Core's form handler sets its cookie and sends the visitor
 * back with "tried" added, so a visit that still needs the password after it was wrong.
 */
use TravelSteps\Trips;

$back       = add_query_arg( 'tried', '1', Trips::url( $trip ) );
$page_title = get_the_title( $trip );
include __DIR__ . '/_header.php';
?>
<div class="ts-page ts-narrow">
	<h1><?php echo esc_html( $page_title ); ?></h1>
	<form class="ts-form ts-password-form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php?action=postpass', 'login_post' ) ); ?>">
		<?php // redirect_to since WordPress 6.8; earlier versions go back to the referer. ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $back ); ?>">
		<input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( $back ); ?>">
		<?php if ( isset( $_GET['tried'] ) ) : ?>
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
