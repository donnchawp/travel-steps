<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only status flag from redirect.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\Settings;

$settings      = Settings::all();
$picker        = get_option( 'immich_settings', [] );
$picker_has_it = ! empty( $picker['api_key'] );
$page_title    = __( 'Travel Steps settings', 'travel-steps' );
include __DIR__ . '/_header.php';
?>
<div class="ts-page ts-narrow">
	<h1><?php echo esc_html( $page_title ); ?></h1>
	<?php if ( isset( $_GET['invalid'] ) && 'home' === $_GET['invalid'] ) : ?>
		<p class="ts-notice is-error"><?php esc_html_e( 'Home location not saved: it needs both a latitude and a longitude in decimal degrees, like 51.8985 and -8.4756 (west and south are negative). You can also paste a "51.8985, -8.4756" pair from a map into either field. Everything else was saved.', 'travel-steps' ); ?></p>
	<?php elseif ( isset( $_GET['saved'] ) ) : ?>
		<p class="ts-notice"><?php esc_html_e( 'Settings saved.', 'travel-steps' ); ?></p>
	<?php endif; ?>

	<form class="ts-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="travel_steps_settings">
		<?php wp_nonce_field( 'travel_steps_settings' ); ?>

		<fieldset>
			<legend><?php esc_html_e( 'Dawarich (optional)', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Your location history draws the route between photos. Without it, the route joins the places your photos were taken, so stretches without photos are straight lines and the distance comes out short.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'URL (as seen from the WordPress server)', 'travel-steps' ); ?></span>
				<input type="url" name="dawarich_url" value="<?php echo esc_attr( $settings['dawarich_url'] ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'API key', 'travel-steps' ); ?></span>
				<input type="password" name="dawarich_key" autocomplete="off" placeholder="<?php echo $settings['dawarich_key'] ? esc_attr__( '•••••• saved – leave blank to keep', 'travel-steps' ) : ''; ?>">
				<small class="ts-muted"><?php esc_html_e( 'Find it in Dawarich under Settings → Account.', 'travel-steps' ); ?></small>
			</label>
			<?php if ( $settings['dawarich_key'] ) : ?>
				<label class="ts-check">
					<input type="checkbox" name="dawarich_key_remove" value="1">
					<span><?php esc_html_e( 'Remove the saved key', 'travel-steps' ); ?></span>
				</label>
			<?php endif; ?>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Immich', 'travel-steps' ); ?></legend>
			<label>
				<span><?php esc_html_e( 'URL (as seen from the WordPress server)', 'travel-steps' ); ?></span>
				<input type="url" name="immich_url" value="<?php echo esc_attr( $settings['immich_url'] ); ?>" placeholder="<?php echo esc_attr( Settings::immich_url() ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'API key', 'travel-steps' ); ?></span>
				<input type="password" name="immich_key" autocomplete="off" placeholder="<?php echo $settings['immich_key'] ? esc_attr__( '•••••• saved – leave blank to keep', 'travel-steps' ) : ''; ?>">
				<small class="ts-muted">
					<?php
					if ( $picker_has_it && ! $settings['immich_key'] ) {
						esc_html_e( 'Using the URL and key from Media Picker for Immich. Fill these in to override.', 'travel-steps' );
					} else {
						esc_html_e( 'Needs asset.read, asset.view and album.read permissions.', 'travel-steps' );
					}
					?>
				</small>
			</label>
			<?php if ( $settings['immich_key'] ) : ?>
				<label class="ts-check">
					<input type="checkbox" name="immich_key_remove" value="1">
					<span><?php esc_html_e( 'Remove the saved key', 'travel-steps' ); ?></span>
				</label>
			<?php endif; ?>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Privacy', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Route points and photos near home are left out of every trip.', 'travel-steps' ); ?></p>
			<p class="ts-muted"><?php esc_html_e( 'To find your home’s coordinates, right-click it in Google Maps (long-press in the app) and click the numbers at the top of the menu to copy them. On openstreetmap.org, right-click and choose “Show address”; the numbers appear in the search box. Paste the pair into either field.', 'travel-steps' ); ?></p>
			<div class="ts-form-row">
				<label>
					<span><?php esc_html_e( 'Home latitude', 'travel-steps' ); ?></span>
					<input type="text" name="home_lat" value="<?php echo esc_attr( $settings['home_lat'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Home longitude', 'travel-steps' ); ?></span>
					<input type="text" name="home_lon" value="<?php echo esc_attr( $settings['home_lon'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Radius (km)', 'travel-steps' ); ?></span>
					<input type="number" step="0.1" min="0" name="home_radius_km" value="<?php echo esc_attr( $settings['home_radius_km'] ); ?>">
				</label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Steps', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Each day with favourites becomes a draft step. Favourites further apart than this, in different towns, start a new place: a heading in the story and a place tag.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Place radius (km)', 'travel-steps' ); ?></span>
				<input type="number" step="0.1" min="0.1" name="place_radius_km" value="<?php echo esc_attr( $settings['place_radius_km'] ); ?>">
			</label>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Photos', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Favourite photos in Immich to choose what each step shows. A step with no favourites gets an automatic pick spread across your time there, one per burst. Pin or hide photos on the trip page to adjust. The rest stay behind "Show more". Star ratings are ignored.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Photos to pick for steps without favourites', 'travel-steps' ); ?></span>
				<input type="number" min="1" max="50" name="auto_pick" value="<?php echo esc_attr( $settings['auto_pick'] ); ?>">
			</label>
		</fieldset>

		<div class="ts-form-actions">
			<button type="submit" class="ts-button"><?php esc_html_e( 'Save settings', 'travel-steps' ); ?></button>
		</div>
	</form>
</div>
<?php
include __DIR__ . '/_footer.php';
