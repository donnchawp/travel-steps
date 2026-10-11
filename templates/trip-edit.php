<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only status flags from redirects.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\App;
use TravelSteps\ImmichClient;
use TravelSteps\Trips;

$slug = (string) get_query_var( 'slug' );
$trip = $slug ? Trips::find_by_slug( $slug ) : null;
if ( $slug && ( ! $trip || ! current_user_can( 'edit_post', $trip->ID ) ) ) {
	include __DIR__ . '/_notfound.php';
	return;
}

$trip_id = $trip ? $trip->ID : 0;
$meta    = fn( $key ) => $trip_id ? (string) get_post_meta( $trip_id, $key, true ) : '';
$albums  = ( new ImmichClient() )->albums();
$synced  = (int) $meta( Trips::META_SYNCED );
$error   = $meta( Trips::META_ERROR );
$stats   = $trip_id ? get_post_meta( $trip_id, Trips::META_STATS, true ) : [];
$steps   = $trip_id ? Trips::steps( $trip_id, true ) : [];

$page_title = $trip ? sprintf( /* translators: %s: trip title */ __( 'Edit %s', 'travel-steps' ), get_the_title( $trip ) ) : __( 'New trip', 'travel-steps' );
include __DIR__ . '/_header.php';
?>
<div class="ts-page ts-narrow">
	<div class="ts-page-head">
		<h1><?php echo esc_html( $page_title ); ?></h1>
		<?php if ( $trip ) : ?>
			<a class="ts-button ts-button-secondary" href="<?php echo esc_url( Trips::url( $trip ) ); ?>"><?php esc_html_e( 'View trip', 'travel-steps' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<p class="ts-notice"><?php esc_html_e( 'Trip saved.', 'travel-steps' ); ?></p>
	<?php endif; ?>
	<?php if ( isset( $_GET['synced'] ) && '1' === $_GET['synced'] ) : ?>
		<p class="ts-notice">
			<?php
			$new_steps = absint( $_GET['new_steps'] ?? 0 );
			/* translators: %d: number of steps */
			echo esc_html( sprintf( _n( 'Synced. %d new draft step.', 'Synced. %d new draft steps.', $new_steps, 'travel-steps' ), $new_steps ) );
			?>
		</p>
	<?php elseif ( $error ) : ?>
		<p class="ts-notice is-error"><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'Sync failed: %s', 'travel-steps' ), $error ) ); ?></p>
	<?php endif; ?>

	<form class="ts-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="travel_steps_save_trip">
		<input type="hidden" name="trip_id" value="<?php echo (int) $trip_id; ?>">
		<?php wp_nonce_field( 'travel_steps_save_trip' ); ?>

		<label>
			<span><?php esc_html_e( 'Title', 'travel-steps' ); ?></span>
			<input type="text" name="title" required value="<?php echo esc_attr( $trip ? $trip->post_title : '' ); ?>">
		</label>

		<div class="ts-form-row">
			<label>
				<span><?php esc_html_e( 'First day', 'travel-steps' ); ?></span>
				<input type="date" name="start" required value="<?php echo esc_attr( $meta( Trips::META_START ) ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Last day', 'travel-steps' ); ?></span>
				<input type="date" name="end" required value="<?php echo esc_attr( $meta( Trips::META_END ) ); ?>">
			</label>
		</div>

		<label>
			<span><?php esc_html_e( 'Photos', 'travel-steps' ); ?></span>
			<?php if ( is_wp_error( $albums ) ) : ?>
				<input type="text" name="album" placeholder="<?php esc_attr_e( 'Immich album id', 'travel-steps' ); ?>" value="<?php echo esc_attr( $meta( Trips::META_ALBUM ) ); ?>">
				<small class="ts-muted"><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'Could not list Immich albums: %s', 'travel-steps' ), $albums->get_error_message() ) ); ?></small>
			<?php else : ?>
				<select name="album">
					<option value=""><?php esc_html_e( 'All photos taken during the trip', 'travel-steps' ); ?></option>
					<?php
					// An album Immich no longer lists (deleted, or not visible to this key) stays
					// selected, so saving the form for something else doesn't drop it.
					$saved_album = $meta( Trips::META_ALBUM );
					if ( $saved_album && ! in_array( $saved_album, array_column( $albums, 'id' ), true ) ) :
						?>
						<option value="<?php echo esc_attr( $saved_album ); ?>" selected>
							<?php echo esc_html( sprintf( /* translators: %s: Immich album id */ __( 'Album not found in Immich (%s)', 'travel-steps' ), $saved_album ) ); ?>
						</option>
					<?php endif; ?>
					<?php foreach ( $albums as $album ) : ?>
						<option value="<?php echo esc_attr( $album['id'] ); ?>" <?php selected( $meta( Trips::META_ALBUM ), $album['id'] ); ?>>
							<?php echo esc_html( sprintf( '%s (%s)', $album['name'], number_format_i18n( $album['count'] ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<small class="ts-muted"><?php esc_html_e( 'Pick an Immich album to choose exactly which photos appear.', 'travel-steps' ); ?></small>
			<?php endif; ?>
		</label>

		<label>
			<span><?php esc_html_e( 'Introduction', 'travel-steps' ); ?></span>
			<textarea name="intro" rows="4"><?php echo esc_textarea( $trip ? $trip->post_content : '' ); ?></textarea>
		</label>

		<label>
			<span><?php esc_html_e( 'Visibility', 'travel-steps' ); ?></span>
			<select name="status">
				<?php
				$statuses = [
					'draft'   => __( 'Draft – only you', 'travel-steps' ),
					'private' => __( 'Private – logged-in editors', 'travel-steps' ),
					'publish' => __( 'Public', 'travel-steps' ),
				];
				foreach ( $statuses as $value => $label ) :
					?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $trip ? $trip->post_status : 'draft', $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<div class="ts-form-actions">
			<button type="submit" class="ts-button"><?php esc_html_e( 'Save', 'travel-steps' ); ?></button>
			<button type="submit" class="ts-button ts-button-secondary" name="sync" value="1"><?php esc_html_e( 'Save and sync', 'travel-steps' ); ?></button>
		</div>
	</form>

	<?php if ( $trip ) : ?>
		<section class="ts-panel">
			<h2><?php esc_html_e( 'Sync', 'travel-steps' ); ?></h2>
			<p class="ts-muted">
				<?php
				if ( $synced ) {
					/* translators: 1: time since sync, 2: location points, 3: photos */
					echo esc_html( sprintf( __( 'Last synced %1$s ago: %2$s location points, %3$s photos.', 'travel-steps' ), human_time_diff( $synced ), number_format_i18n( $stats['points'] ?? 0 ), number_format_i18n( $stats['photos'] ?? 0 ) ) );
				} else {
					esc_html_e( 'Not synced yet. Syncing pulls your route from Dawarich and photos from Immich, and adds a draft step for each day with favourites.', 'travel-steps' );
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="travel_steps_sync">
				<input type="hidden" name="trip_id" value="<?php echo (int) $trip_id; ?>">
				<?php wp_nonce_field( 'travel_steps_sync' ); ?>
				<button type="submit" class="ts-button ts-button-secondary"><?php esc_html_e( 'Sync now', 'travel-steps' ); ?></button>
			</form>
		</section>

		<section class="ts-panel">
			<div class="ts-page-head">
				<h2><?php esc_html_e( 'Steps', 'travel-steps' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="travel_steps_add_step">
					<input type="hidden" name="trip_id" value="<?php echo (int) $trip_id; ?>">
					<?php wp_nonce_field( 'travel_steps_add_step' ); ?>
					<button type="submit" class="ts-button ts-button-secondary"><?php esc_html_e( 'Add step', 'travel-steps' ); ?></button>
				</form>
			</div>
			<?php if ( ! $steps ) : ?>
				<p class="ts-muted"><?php esc_html_e( 'No steps yet. Favourite photos in Immich and sync, or add one by hand.', 'travel-steps' ); ?></p>
			<?php else : ?>
				<p class="ts-muted"><?php esc_html_e( 'Each step is a day, written in the block editor. New steps are drafts with a heading per place; publish the ones you want shown. Photos from that date appear under the headings. Move a step to the bin to drop it; sync won\'t bring it back.', 'travel-steps' ); ?></p>
				<ol class="ts-step-list">
					<?php foreach ( $steps as $step ) : ?>
						<li>
							<a href="<?php echo esc_url( get_edit_post_link( $step->ID ) ); ?>"><?php echo esc_html( get_the_title( $step ) ); ?></a>
							<span class="ts-muted"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $step->post_date ) ); ?></span>
							<?php if ( 'publish' !== $step->post_status ) : ?>
								<span class="ts-badge"><?php echo esc_html( get_post_status_object( $step->post_status )->label ?? $step->post_status ); ?></span>
							<?php endif; ?>
							<?php if ( '' === get_post_meta( $step->ID, Trips::META_LAT, true ) ) : ?>
								<span class="ts-badge is-warning"><?php esc_html_e( 'No location', 'travel-steps' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<p class="ts-muted"><a href="<?php echo esc_url( App::url( 'settings' ) ); ?>"><?php esc_html_e( 'Dawarich & Immich settings', 'travel-steps' ); ?></a></p>
	<?php endif; ?>
</div>
<?php
include __DIR__ . '/_footer.php';
