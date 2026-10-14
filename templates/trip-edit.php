<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only status flags from redirects.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\App;
use TravelSteps\DawarichClient;
use TravelSteps\Exif;
use TravelSteps\ImmichClient;
use TravelSteps\RestController;
use TravelSteps\Settings;
use TravelSteps\Trips;
use TravelSteps\Uploads;
use TravelSteps\View;

$slug = (string) get_query_var( 'slug' );
$trip = $slug ? Trips::find_by_slug( $slug ) : null;
if ( $slug && ( ! $trip || ! current_user_can( 'edit_post', $trip->ID ) ) ) {
	include __DIR__ . '/_notfound.php';
	return;
}

$trip_id = $trip ? $trip->ID : 0;
$meta    = fn( $key ) => $trip_id ? (string) get_post_meta( $trip_id, $key, true ) : '';
// What the trip pulls in uses its author's keys, so only they change it or sync it.
$author   = $trip ? (int) $trip->post_author : get_current_user_id();
$is_owner = ! $trip || Trips::can_sync_trip( $trip_id );
$account  = Settings::account( $author );
// Null when the author has no Immich key: Immich isn't offered then.
$albums  = $is_owner && Trips::can_use_immich( $author ) ? ImmichClient::for_user( $author )->albums() : null;
$choices = $is_owner ? View::photo_choices( $trip, $albums ) : [];
$picked        = current( wp_list_filter( $choices, [ 'selected' => true ] ) ) ?: [];
$picks_uploads = Trips::SOURCE_UPLOADS === ( $picked['value'] ?? '' );
// Then sync can take the dates from the photos.
$picks_photos  = ! empty( $picked['dates_optional'] );
$uses_uploads  = $trip && Trips::uses_uploads( $trip_id );
$can_upload    = Uploads::available();
// Anyone who can edit the trip may add photos to it; only its author syncs.
$can_add = $uses_uploads && $can_upload;
if ( $can_add ) {
	// Core hooks nothing here; other plugins' media sources (an Immich picker, say) would
	// add photos that aren't trip photos, and some expect wp-admin and fail on this page.
	remove_all_actions( 'wp_enqueue_media' );
	wp_enqueue_media( [ 'post' => $trip_id ] );
}
$synced  = (int) $meta( Trips::META_SYNCED );
$error   = $meta( Trips::META_ERROR );
$stats   = $trip_id ? get_post_meta( $trip_id, Trips::META_STATS, true ) : [];
$steps   = $trip_id ? Trips::steps( $trip_id, true ) : [];
$photos  = $synced && ! $steps ? Trips::photos( $trip_id ) : [];

$has_dawarich = '' !== $account['dawarich_key'];

wp_app_enqueue_script(
	'travel-steps-edit',
	TRAVEL_STEPS_PLUGIN_URL . 'assets/travel-steps-edit.js',
	$can_add ? [ 'media-views' ] : [],
	filemtime( TRAVEL_STEPS_PLUGIN_DIR . 'assets/travel-steps-edit.js' ),
	true,
	'travel-steps'
);

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
			$skipped_days = absint( $_GET['skipped_days'] ?? 0 );
			if ( $skipped_days ) :
				echo ' ' . esc_html( sprintf( /* translators: %d: number of days */ _n( '%d day with favourites was skipped because its step is in the trash.', '%d days with favourites were skipped because their steps are in the trash.', $skipped_days, 'travel-steps' ), $skipped_days ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_status=trash&post_type=' . Trips::STEP ) ); ?>"><?php esc_html_e( 'Restore them, or empty the trash and sync again to recreate them.', 'travel-steps' ); ?></a>
				<?php
			endif;
			?>
		</p>
		<?php
		$left_out = [];
		foreach (
			[
				/* translators: %s: number of photos */
				'outside' => __( '%s outside the trip’s dates', 'travel-steps' ),
				/* translators: %s: number of photos */
				'untimed' => __( '%s with no time taken (often sent through a messaging app, or a screenshot)', 'travel-steps' ),
			] as $key => $format
		) {
			$count = absint( $_GET[ $key ] ?? 0 );
			if ( $count ) {
				$text = esc_html( sprintf( $format, number_format_i18n( $count ) ) );
				// They're listed in the Photos panel, to be given times.
				$left_out[] = 'untimed' === $key ? '<a href="#ts-untimed">' . $text . '</a>' : $text;
			}
		}
		$at_home   = absint( $_GET['at_home'] ?? 0 );
		$unlocated = absint( $_GET['unlocated'] ?? 0 );
		?>
		<?php if ( $left_out ) : ?>
			<p class="ts-notice is-warning"><?php echo wp_kses( sprintf( /* translators: %s: a list of counts, such as "1 outside the trip's dates, 2 with no time taken" */ esc_html__( 'Left out: %s.', 'travel-steps' ), implode( ', ', $left_out ) ), [ 'a' => [ 'href' => true ] ] ); ?></p>
		<?php endif; ?>
		<?php if ( $at_home ) : ?>
			<p class="ts-notice">
				<?php
				/* translators: %s: number of photos */
				echo esc_html( sprintf( _n( '%s photo taken at home is shown without a place.', '%s photos taken at home are shown without a place.', $at_home, 'travel-steps' ), number_format_i18n( $at_home ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( $unlocated ) : ?>
			<p class="ts-notice is-warning">
				<?php
				/* translators: %s: number of photos */
				echo esc_html( sprintf( _n( '%s photo has no location, so it isn’t on the map.', '%s photos have no location, so they aren’t on the map.', $unlocated, 'travel-steps' ), number_format_i18n( $unlocated ) ) );
				echo ' ' . esc_html__( 'iPhone browsers drop it unless you turn on Options → Location before uploading; messaging apps strip it.', 'travel-steps' );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['no_places'] ) ) : ?>
			<p class="ts-notice is-warning"><?php esc_html_e( 'Place names are unavailable: the plugin’s geonames data is missing.', 'travel-steps' ); ?></p>
		<?php endif; ?>
	<?php elseif ( $error ) : ?>
		<p class="ts-notice is-error"><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'Sync failed: %s', 'travel-steps' ), $error ) ); ?></p>
	<?php endif; ?>
	<?php if ( isset( $_GET['photos'] ) && ! $is_owner ) : ?>
		<p class="ts-notice"><?php echo esc_html( sprintf( /* translators: %s: the trip author's name */ __( 'The trip’s photos changed. %s needs to sync it to update its steps.', 'travel-steps' ), get_the_author_meta( 'display_name', $author ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( $trip && ! $uses_uploads && ! Trips::can_use_immich( $author ) ) : ?>
		<p class="ts-notice is-warning">
			<?php if ( $is_owner ) : ?>
				<?php esc_html_e( 'You haven’t saved an Immich API key, so this trip can’t sync.', 'travel-steps' ); ?>
				<a href="<?php echo esc_url( App::url( 'settings' ) ); ?>"><?php esc_html_e( 'Add it in settings', 'travel-steps' ); ?></a>
			<?php else : ?>
				<?php echo esc_html( sprintf( /* translators: %s: the trip author's name */ __( '%s hasn’t saved an Immich API key in Travel Steps settings, so this trip can’t sync.', 'travel-steps' ), get_the_author_meta( 'display_name', $author ) ) ); ?>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php $unseen = $trip ? View::unseen_notice( $trip, $steps ) : ''; ?>
	<?php if ( $unseen ) : ?>
		<p class="ts-notice is-warning"><?php echo esc_html( $unseen ); ?></p>
	<?php endif; ?>
	<?php if ( ! $uses_uploads && $photos && ! array_filter( array_column( $photos, 'fav' ) ) ) : ?>
		<p class="ts-notice is-warning">
			<?php
			if ( 1 === count( $photos ) ) {
				esc_html_e( 'The trip’s only photo isn’t a favourite in Immich, so sync made no steps. Favourite it, then sync again.', 'travel-steps' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: %s: number of photos, always more than one */
						__( 'None of the %s photos is a favourite in Immich, so sync made no steps. Favourite the photos you want to show, then sync again.', 'travel-steps' ),
						number_format_i18n( count( $photos ) )
					)
				);
			}
			?>
		</p>
	<?php endif; ?>

	<form class="ts-form ts-trip-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
				<input type="date" name="start" <?php echo $picks_photos ? '' : 'required'; ?> value="<?php echo esc_attr( $meta( Trips::META_START ) ); ?>" <?php disabled( ! $is_owner ); ?>>
			</label>
			<label>
				<span><?php esc_html_e( 'Last day', 'travel-steps' ); ?></span>
				<input type="date" name="end" <?php echo $picks_photos ? '' : 'required'; ?> value="<?php echo esc_attr( $meta( Trips::META_END ) ); ?>" <?php disabled( ! $is_owner ); ?>>
			</label>
		</div>
		<?php if ( $is_owner ) : ?>
			<small class="ts-muted ts-dates-hint" <?php echo $picks_photos ? '' : 'hidden'; ?>><?php esc_html_e( 'Leave empty to take them from the photos.', 'travel-steps' ); ?></small>
		<?php endif; ?>

		<?php if ( ! $is_owner ) : ?>
			<p class="ts-muted"><?php echo esc_html( sprintf( /* translators: %s: the trip author's name */ __( 'The trip uses %s’s photos and location history, so only they can change its dates or photos, or sync it.', 'travel-steps' ), get_the_author_meta( 'display_name', $author ) ) ); ?></p>
		<?php elseif ( ! $choices ) : ?>
			<p class="ts-notice is-warning">
				<?php esc_html_e( 'To add photos to a trip, add an Immich key in settings, or ask your site’s administrator for PHP’s exif extension, which uploading needs.', 'travel-steps' ); ?>
				<a href="<?php echo esc_url( App::url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'travel-steps' ); ?></a>
			</p>
		<?php else : ?>
		<label>
			<span><?php esc_html_e( 'Photos', 'travel-steps' ); ?></span>
			<select name="photos">
				<?php foreach ( $choices as $choice ) : ?>
					<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( $choice['selected'] ); ?> <?php disabled( $choice['disabled'] ); ?> <?php echo $choice['dates_optional'] ? 'data-dates-optional' : ''; ?>><?php echo esc_html( $choice['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! $can_upload ) : ?>
				<small class="ts-muted"><?php esc_html_e( 'Uploading photos needs PHP’s exif extension, which reads when and where they were taken. This site doesn’t have it.', 'travel-steps' ); ?></small>
			<?php endif; ?>
			<?php if ( null === $albums ) : ?>
				<small class="ts-muted"><a href="<?php echo esc_url( App::url( 'settings' ) ); ?>"><?php esc_html_e( 'Add an Immich key in settings to use Immich photos.', 'travel-steps' ); ?></a></small>
			<?php elseif ( is_wp_error( $albums ) ) : ?>
				<input type="text" name="album" placeholder="<?php esc_attr_e( 'Immich album id', 'travel-steps' ); ?>" value="<?php echo esc_attr( $meta( Trips::META_ALBUM ) ); ?>">
				<small class="ts-muted"><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'Could not list Immich albums: %s', 'travel-steps' ), $albums->get_error_message() ) ); ?></small>
			<?php else : ?>
				<small class="ts-muted"><?php esc_html_e( 'Pick an Immich album to choose exactly which photos appear.', 'travel-steps' ); ?></small>
			<?php endif; ?>
		</label>
		<?php endif; ?>

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

		<fieldset>
			<legend><?php esc_html_e( 'Password protection', 'travel-steps' ); ?></legend>
			<label>
				<span><?php esc_html_e( 'Password (optional)', 'travel-steps' ); ?></span>
				<input type="text" name="password" autocomplete="off" spellcheck="false" maxlength="255" value="<?php echo esc_attr( $trip ? $trip->post_password : '' ); ?>">
				<small class="ts-muted"><?php esc_html_e( 'Visitors to a public trip need it to see the trip. Use a long one: nothing stops anyone trying passwords. Leave it empty, or “0”, for no password. A private trip can’t have one.', 'travel-steps' ); ?></small>
			</label>
			<p class="ts-muted">
				<?php
				echo esc_html( sprintf( /* translators: %s: the trip author's name */ __( 'People ticked here see the trip without the password once they’re logged in, as do those on %s’s list in Travel Steps settings and anyone who can edit the trip.', 'travel-steps' ), get_the_author_meta( 'display_name', $author ) ) );
				?>
			</p>
			<?php View::viewer_boxes( $author, $trip_id, $trip ? Trips::viewers( $trip_id ) : [] ); ?>
		</fieldset>

		<?php if ( $uses_uploads ) : ?>
		<section class="ts-panel ts-uploads" data-open="<?php echo isset( $_GET['add'] ) ? '1' : ''; ?>" data-title="<?php esc_attr_e( 'Photos for this trip', 'travel-steps' ); ?>" data-done="<?php esc_attr_e( 'Done', 'travel-steps' ); ?>" data-finishing="<?php esc_attr_e( 'Finishing uploads…', 'travel-steps' ); ?>">
			<div class="ts-page-head">
				<h2><?php esc_html_e( 'Photos', 'travel-steps' ); ?></h2>
				<?php if ( $can_add ) : ?>
					<button type="button" class="ts-button ts-button-secondary ts-add-photos"><?php esc_html_e( 'Add photos', 'travel-steps' ); ?></button>
				<?php endif; ?>
			</div>
			<p class="ts-muted" aria-live="polite">
				<?php
				$uploads  = Uploads::trip_photos( $trip_id );
				$uploaded = count( $uploads );
				echo esc_html(
					$uploaded
						/* translators: %s: number of photos */
						? sprintf( _n( '%s photo uploaded.', '%s photos uploaded.', $uploaded, 'travel-steps' ), number_format_i18n( $uploaded ) )
						: __( 'No photos uploaded yet.', 'travel-steps' )
				);
				if ( ! $can_add ) {
					echo ' ' . esc_html__( 'This site can’t take uploads: its PHP has no exif extension, which reads when and where photos were taken.', 'travel-steps' );
				}
				?>
			</p>
			<?php
			// Uploads with no time taken in their EXIF, and the times their author gave them.
			$taken = Uploads::untimed( $uploads );
			_prime_post_caches( array_keys( $taken ), false, false ); // Their names, in one query.
			?>
			<?php if ( $taken ) : ?>
				<details class="ts-untimed" id="ts-untimed" <?php echo in_array( '', $taken, true ) ? 'open' : ''; ?>>
					<summary>
						<?php
						/* translators: %s: number of photos */
						echo esc_html( sprintf( _n( '%s with no time taken', '%s with no time taken', count( $taken ), 'travel-steps' ), number_format_i18n( count( $taken ) ) ) );
						?>
					</summary>
					<?php if ( $is_owner ) : ?>
						<p class="ts-muted"><?php esc_html_e( 'Give each a date and time to put it on that day’s step. Times from a file name are kept once you save.', 'travel-steps' ); ?></p>
					<?php endif; ?>
					<ul class="ts-untimed-list">
						<?php
						foreach ( $taken as $attachment_id => $given ) :
							$photo_id = Uploads::photo_id( $attachment_id );
							$name     = (string) get_post_field( 'post_title', $attachment_id );
							$named    = '' === $given ? Exif::from_name( $name ) : null;
							[ $date, $time ] = $named ?? [ substr( $given, 0, 10 ), substr( $given, 11, 5 ) ];
							$field    = 'taken[' . $photo_id . ']';
							?>
							<li class="ts-untimed-row">
								<img src="<?php echo esc_url( RestController::photo_url( $trip_id, $photo_id, 'thumbnail', true ) ); ?>" alt="" width="64" height="64" loading="lazy">
								<span class="ts-untimed-name">
									<?php echo esc_html( $name ); ?>
									<?php if ( $named ) : ?>
										<small class="ts-muted"><?php esc_html_e( 'From the file name', 'travel-steps' ); ?></small>
									<?php endif; ?>
								</span>
								<span class="ts-untimed-when">
									<?php /* translators: %s: the name a photo was uploaded with */ ?>
									<input type="date" name="<?php echo esc_attr( $field ); ?>[date]" value="<?php echo esc_attr( $date ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Day %s was taken', 'travel-steps' ), $name ) ); ?>" <?php disabled( ! $is_owner ); ?>>
									<?php /* translators: %s: the name a photo was uploaded with */ ?>
									<input type="time" name="<?php echo esc_attr( $field ); ?>[time]" value="<?php echo esc_attr( $time ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Time %s was taken', 'travel-steps' ), $name ) ); ?>" <?php disabled( ! $is_owner ); ?>>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<div class="ts-form-actions">
			<?php
			$save_label = __( 'Save', 'travel-steps' );
			$add_label  = __( 'Save and add photos', 'travel-steps' );
			// A new trip of uploads has nothing to sync yet: saving it opens the media modal.
			$adds = ! $trip && $picks_uploads;
			?>
			<button type="submit" class="ts-button ts-save" data-label="<?php echo esc_attr( $save_label ); ?>" data-uploads-label="<?php echo esc_attr( $add_label ); ?>"><?php echo esc_html( $adds ? $add_label : $save_label ); ?></button>
			<?php if ( $is_owner ) : ?>
				<button type="submit" class="ts-button ts-button-secondary" name="sync" value="1" <?php echo $adds ? 'hidden' : ''; ?>><?php esc_html_e( 'Save and sync', 'travel-steps' ); ?></button>
			<?php endif; ?>
		</div>
	</form>

	<?php if ( $trip ) : ?>
		<section class="ts-panel">
			<h2><?php esc_html_e( 'Sync', 'travel-steps' ); ?></h2>
			<p class="ts-muted">
				<?php
				if ( $synced && ! empty( $stats['points'] ) ) {
					/* translators: 1: time since sync, 2: location points, 3: photos */
					echo esc_html( sprintf( __( 'Last synced %1$s ago: %2$s location points, %3$s photos.', 'travel-steps' ), human_time_diff( $synced ), number_format_i18n( $stats['points'] ?? 0 ), number_format_i18n( $stats['photos'] ?? 0 ) ) );
				} elseif ( $synced ) {
					/* translators: 1: time since sync, 2: photos */
					echo esc_html( sprintf( __( 'Last synced %1$s ago: %2$s photos.', 'travel-steps' ), human_time_diff( $synced ), number_format_i18n( $stats['photos'] ?? 0 ) ) );
				} elseif ( $uses_uploads ) {
					esc_html_e( 'Not synced yet. Syncing makes a draft step for each day with photos, named after where they were taken.', 'travel-steps' );
				} elseif ( $has_dawarich ) {
					esc_html_e( 'Not synced yet. Syncing pulls your route from Dawarich and photos from Immich, and adds a draft step for each day with favourites.', 'travel-steps' );
				} else {
					esc_html_e( 'Not synced yet. Syncing pulls photos from Immich, maps the trip from where they were taken, and adds a draft step for each day with favourites.', 'travel-steps' );
				}
				?>
			</p>
			<?php if ( $is_owner ) : ?>
				<form class="ts-sync-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="travel_steps_sync">
					<input type="hidden" name="trip_id" value="<?php echo (int) $trip_id; ?>">
					<?php wp_nonce_field( 'travel_steps_sync' ); ?>
					<button type="submit" class="ts-button ts-button-secondary"><?php esc_html_e( 'Sync now', 'travel-steps' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( ! $uses_uploads ) : ?>
			<p class="ts-muted">
				<?php
				$max_photos = number_format_i18n( ImmichClient::PAGE_SIZE * ImmichClient::MAX_PAGES );
				echo esc_html(
					$has_dawarich
						? sprintf(
							/* translators: 1: maximum photos, 2: maximum location points */
							__( 'Sync uses at most %1$s photos and %2$s location points per trip, and ignores the rest without warning.', 'travel-steps' ),
							$max_photos,
							number_format_i18n( DawarichClient::PER_PAGE * DawarichClient::MAX_PAGES )
						)
						: sprintf(
							/* translators: %s: maximum photos */
							__( 'Sync uses at most %s photos per trip, and ignores the rest without warning.', 'travel-steps' ),
							$max_photos
						)
				);
				echo ' ';
				/* translators: follows the sentence giving the most photos sync uses per trip. */
				esc_html_e( 'A trip with that many photos would be far too big to show online anyway: pick an album, or split the trip.', 'travel-steps' );
				?>
			</p>
			<?php endif; ?>
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
				<p class="ts-muted">
					<?php
					echo esc_html(
						$uses_uploads
							? __( 'No steps yet. Add photos and sync: each day with photos gets a step.', 'travel-steps' )
							: __( 'No steps yet. Favourite photos in Immich and sync, or add one by hand.', 'travel-steps' )
					);
					?>
				</p>
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
