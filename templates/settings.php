<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only status flag from redirect.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\Settings;
use TravelSteps\Uploads;
use TravelSteps\View;

$account    = Settings::account( get_current_user_id() );
$settings   = Settings::all();
$page_title = __( 'Travel Steps settings', 'travel-steps' );
include __DIR__ . '/_header.php';
?>
<div class="ts-page ts-narrow">
	<h1><?php echo esc_html( $page_title ); ?></h1>
	<?php if ( isset( $_GET['invalid'] ) && 'home' === $_GET['invalid'] ) : ?>
		<p class="ts-notice is-error"><?php esc_html_e( 'Home location not saved: it needs both a latitude and a longitude in decimal degrees, like 51.8985 and -8.4756 (west and south are negative). You can also paste a "51.8985, -8.4756" pair from a map into either field. Everything else was saved.', 'travel-steps' ); ?></p>
	<?php elseif ( isset( $_GET['saved'] ) ) : ?>
		<p class="ts-notice"><?php esc_html_e( 'Settings saved.', 'travel-steps' ); ?></p>
	<?php endif; ?>
	<?php if ( ! Uploads::available() ) : ?>
		<p class="ts-notice is-warning"><?php esc_html_e( 'This site’s PHP has no exif extension, so trips can only use Immich photos. Uploading needs it to read when and where photos were taken.', 'travel-steps' ); ?></p>
	<?php endif; ?>

	<form class="ts-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="travel_steps_account">
		<?php wp_nonce_field( 'travel_steps_account' ); ?>
		<h2><?php esc_html_e( 'Personal settings', 'travel-steps' ); ?></h2>
		<p class="ts-muted"><?php esc_html_e( 'Your trips use your own keys and home, also when visitors look at them. Nobody else can see or change them, administrators included.', 'travel-steps' ); ?></p>

		<fieldset>
			<legend><?php esc_html_e( 'Dawarich (optional)', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Your location history draws the route between photos. Without it, the route joins the places your photos were taken, so stretches without photos are straight lines and the distance comes out short.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'API key', 'travel-steps' ); ?></span>
				<input type="password" name="dawarich_key" autocomplete="off" placeholder="<?php echo $account['dawarich_key'] ? esc_attr__( '•••••• saved – leave blank to keep', 'travel-steps' ) : ''; ?>">
				<small class="ts-muted"><?php esc_html_e( 'Find it in Dawarich under Settings → Account.', 'travel-steps' ); ?></small>
			</label>
			<?php if ( $account['dawarich_key'] ) : ?>
				<label class="ts-check">
					<input type="checkbox" name="dawarich_key_remove" value="1">
					<span><?php esc_html_e( 'Remove the saved key', 'travel-steps' ); ?></span>
				</label>
			<?php endif; ?>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Immich (optional)', 'travel-steps' ); ?></legend>
			<?php if ( Uploads::available() ) : ?>
				<p class="ts-muted"><?php esc_html_e( 'Without a key, upload photos to your trips instead.', 'travel-steps' ); ?></p>
			<?php endif; ?>
			<label>
				<span><?php esc_html_e( 'API key', 'travel-steps' ); ?></span>
				<input type="password" name="immich_key" autocomplete="off" placeholder="<?php echo $account['immich_key'] ? esc_attr__( '•••••• saved – leave blank to keep', 'travel-steps' ) : ''; ?>">
				<small class="ts-muted">
					<?php
					esc_html_e( 'Your own key, from Immich under Account Settings → API Keys. It needs asset.read, asset.view and album.read permissions.', 'travel-steps' );
					?>
				</small>
			</label>
			<?php if ( $account['immich_key'] ) : ?>
				<label class="ts-check">
					<input type="checkbox" name="immich_key_remove" value="1">
					<span><?php esc_html_e( 'Remove the saved key', 'travel-steps' ); ?></span>
				</label>
			<?php endif; ?>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Privacy', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Route points and photos near your home are left out of your trips.', 'travel-steps' ); ?></p>
			<p class="ts-muted"><?php esc_html_e( 'To find your home’s coordinates, right-click it in Google Maps (long-press in the app) and click the numbers at the top of the menu to copy them. On openstreetmap.org, right-click and choose “Show address”; the numbers appear in the search box. Paste the pair into either field.', 'travel-steps' ); ?></p>
			<div class="ts-form-row">
				<label>
					<span><?php esc_html_e( 'Home latitude', 'travel-steps' ); ?></span>
					<input type="text" name="home_lat" value="<?php echo esc_attr( $account['home_lat'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Home longitude', 'travel-steps' ); ?></span>
					<input type="text" name="home_lon" value="<?php echo esc_attr( $account['home_lon'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Home radius (km)', 'travel-steps' ); ?></span>
					<input type="number" step="0.1" min="0" name="home_radius_km" value="<?php echo esc_attr( $account['home_radius_km'] ); ?>">
				</label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Who sees your password-protected trips', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'People you tick here see all your password-protected trips without the password, once they’re logged in. Each trip can let in more people of its own.', 'travel-steps' ); ?></p>
			<?php
			$me = get_current_user_id();
			View::viewer_boxes( $me, 0, Settings::viewers( $me ) );
			?>
		</fieldset>

		<div class="ts-form-actions">
			<button type="submit" class="ts-button"><?php esc_html_e( 'Save', 'travel-steps' ); ?></button>
		</div>
	</form>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
	<form class="ts-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="travel_steps_settings">
		<?php wp_nonce_field( 'travel_steps_settings' ); ?>
		<h2><?php esc_html_e( 'Site', 'travel-steps' ); ?></h2>

		<fieldset>
			<legend><?php esc_html_e( 'Servers', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Everyone who makes trips uses these servers with their own API key.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Immich URL (as seen from the WordPress server)', 'travel-steps' ); ?></span>
				<input type="url" name="immich_url" value="<?php echo esc_attr( $settings['immich_url'] ); ?>" placeholder="<?php echo esc_attr( Settings::immich_url() ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Dawarich URL (as seen from the WordPress server)', 'travel-steps' ); ?></span>
				<input type="url" name="dawarich_url" value="<?php echo esc_attr( $settings['dawarich_url'] ); ?>">
			</label>
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
			<p class="ts-muted"><?php esc_html_e( 'Favourite photos in Immich to choose what each step shows. A step with no favourites gets an automatic pick spread across your time there, one per burst. Pin or hide photos on the trip page to adjust. The rest stay behind "Show more". Star ratings are ignored. For a trip with uploaded photos, every upload is shown; hide the ones you don’t want.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Photos to pick for steps without favourites', 'travel-steps' ); ?></span>
				<input type="number" min="1" max="50" name="auto_pick" value="<?php echo esc_attr( $settings['auto_pick'] ); ?>">
			</label>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Cached photos', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Photos are cached on this site when they’re shown, and after a sync. Once a day, cached photos that no trip uses any more are deleted.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Delete cached photos after (days)', 'travel-steps' ); ?></span>
				<input type="number" min="0" step="1" name="cache_days" value="<?php echo esc_attr( $settings['cache_days'] ); ?>">
				<small class="ts-muted"><?php esc_html_e( '0 keeps them. An expired photo is cached again when someone next views it: an uploaded one from its original on this site, and others from Immich, which needs Immich and the trip author’s key to be working.', 'travel-steps' ); ?></small>
			</label>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Subscriptions', 'travel-steps' ); ?></legend>
			<p class="ts-muted"><?php esc_html_e( 'Visitors can subscribe to a published trip. Once a day, each trip with new days or photos emails its subscribers a list of them, through this site’s mail setup.', 'travel-steps' ); ?></p>
			<label>
				<span><?php esc_html_e( 'Send at (hour of the day, site time)', 'travel-steps' ); ?></span>
				<input type="number" min="0" max="23" step="1" name="roundup_hour" value="<?php echo esc_attr( $settings['roundup_hour'] ); ?>">
				<small class="ts-muted"><?php esc_html_e( 'From 0 to 23. On a site whose scheduled tasks only run when someone visits, the emails may go later.', 'travel-steps' ); ?></small>
			</label>
		</fieldset>

		<div class="ts-form-actions">
			<button type="submit" class="ts-button"><?php esc_html_e( 'Save site settings', 'travel-steps' ); ?></button>
		</div>
	</form>
	<?php endif; ?>
</div>
<?php
include __DIR__ . '/_footer.php';
