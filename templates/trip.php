<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\Days;
use TravelSteps\RestController;
use TravelSteps\Trips;
use TravelSteps\View;

$trip = Trips::find_by_slug( (string) get_query_var( 'slug' ) );
if ( ! $trip || ! Trips::can_view( $trip ) ) {
	include __DIR__ . '/_notfound.php';
	return;
}

$can_edit = current_user_can( 'edit_post', $trip->ID );
$authed   = $can_edit || 'publish' !== $trip->post_status;
$steps    = Trips::timeline_steps( $trip, $can_edit );
$by_step  = Trips::photos_by_step( $trip->ID, $steps );
$pinned   = array_flip( Trips::pinned( $trip->ID ) );

// Grids use the medium size; a photo shown alone fills the column, so it gets the preview.
$cover_id     = Trips::cover( $trip->ID );
$render_photo = function ( array $photo, string $state, string $size = 'medium' ) use ( $trip, $authed, $can_edit, $cover_id ) {
	?>
	<figure class="ts-photo is-<?php echo esc_attr( $state ); ?>" data-asset="<?php echo esc_attr( $photo['id'] ); ?>" data-state="<?php echo esc_attr( $state ); ?>">
		<button type="button" class="ts-photo-open"
			data-full="<?php echo esc_url( RestController::photo_url( $trip->ID, $photo['id'], 'preview', $authed ) ); ?>"
			data-caption="<?php echo esc_attr( $photo['caption'] ?? '' ); ?>"
			aria-label="<?php esc_attr_e( 'Open photo', 'travel-steps' ); ?>">
			<img src="<?php echo esc_url( RestController::photo_url( $trip->ID, $photo['id'], $size, $authed ) ); ?>"
				alt="<?php echo esc_attr( $photo['caption'] ?? '' ); ?>" loading="lazy"
				<?php if ( ! empty( $photo['w'] ) && ! empty( $photo['h'] ) ) : ?>
					width="<?php echo (int) $photo['w']; ?>" height="<?php echo (int) $photo['h']; ?>"
				<?php endif; ?>>
		</button>
		<?php if ( $can_edit ) : ?>
			<span class="ts-photo-tools">
				<?php if ( ! empty( $photo['fav'] ) ) : ?>
					<span class="ts-photo-fav" title="<?php esc_attr_e( 'Favourite in Immich', 'travel-steps' ); ?>">♥</span>
				<?php endif; ?>
				<button type="button" class="ts-photo-state" data-set="pinned" aria-pressed="<?php echo 'pinned' === $state ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Always show this photo', 'travel-steps' ); ?>"><?php esc_html_e( 'Pin', 'travel-steps' ); ?></button>
				<button type="button" class="ts-photo-state" data-set="hidden" aria-pressed="<?php echo 'hidden' === $state ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Never show this photo', 'travel-steps' ); ?>"><?php esc_html_e( 'Hide', 'travel-steps' ); ?></button>
				<button type="button" class="ts-photo-cover" aria-pressed="<?php echo $cover_id === $photo['id'] ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Use as the trip’s header photo', 'travel-steps' ); ?>"><?php esc_html_e( 'Cover', 'travel-steps' ); ?></button>
			</span>
		<?php endif; ?>
	</figure>
	<?php
};
$render_grid = function ( array $photos ) use ( $render_photo, $pinned ) {
	?>
	<div class="ts-photos<?php echo count( $photos ) === 1 ? ' is-single' : ''; ?>">
		<?php
		foreach ( $photos as $photo ) {
			$render_photo( $photo, isset( $pinned[ $photo['id'] ] ) ? 'pinned' : 'default', count( $photos ) === 1 ? 'preview' : 'medium' );
		}
		?>
	</div>
	<?php
};
$cover = View::cover_url( $trip->ID, 'preview' );

// Leaflet ships with the plugin, so no third-party script runs on a page that carries a REST nonce.
wp_app_enqueue_style( 'leaflet', TRAVEL_STEPS_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', [], '1.9.4', 'travel-steps' );
wp_app_enqueue_script( 'leaflet', TRAVEL_STEPS_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true, 'travel-steps' );
wp_app_enqueue_script(
	'travel-steps-trip',
	TRAVEL_STEPS_PLUGIN_URL . 'assets/travel-steps-trip.js',
	[ 'leaflet' ],
	filemtime( TRAVEL_STEPS_PLUGIN_DIR . 'assets/travel-steps-trip.js' ),
	true,
	'travel-steps'
);

$page_title = get_the_title( $trip );
$body_class = 'ts-trip-page';
include __DIR__ . '/_header.php';
?>
<article class="ts-trip"
	data-api="<?php echo esc_url( rest_url( RestController::NS . '/trips/' . $trip->ID ) ); ?>"
	data-nonce="<?php echo esc_attr( $can_edit ? wp_create_nonce( 'wp_rest' ) : '' ); ?>">

	<header class="ts-hero<?php echo $cover ? ' has-cover' : ''; ?>">
		<?php if ( $cover ) : ?>
			<img class="ts-hero-img" src="<?php echo esc_url( $cover ); ?>" alt="">
		<?php endif; ?>
		<div class="ts-hero-text">
			<p class="ts-hero-dates"><?php echo esc_html( View::date_range( $trip->ID ) ); ?></p>
			<h1><?php echo esc_html( get_the_title( $trip ) ); ?></h1>
			<ul class="ts-stats">
				<?php foreach ( View::stats( $trip->ID ) as $label => $value ) : ?>
					<li><strong><?php echo esc_html( $value ); ?></strong> <?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $can_edit && current_user_can( Trips::capability() ) ) : ?>
				<p><a class="ts-button ts-button-light" href="<?php echo esc_url( Trips::url( $trip, 'edit' ) ); ?>"><?php esc_html_e( 'Edit trip', 'travel-steps' ); ?></a></p>
			<?php endif; ?>
		</div>
	</header>

	<div class="ts-layout">
		<div class="ts-map-wrap">
			<div id="ts-map" class="ts-map" role="region" aria-label="<?php esc_attr_e( 'Trip map', 'travel-steps' ); ?>"></div>
		</div>

		<div class="ts-timeline">
			<?php $unseen = $can_edit ? View::unseen_notice( $trip, $steps ) : ''; ?>
			<?php if ( $unseen ) : ?>
				<p class="ts-notice is-warning">
					<?php echo esc_html( $unseen ); ?>
					<?php if ( current_user_can( Trips::capability() ) ) : ?>
						<a href="<?php echo esc_url( Trips::url( $trip, 'edit' ) ); ?>"><?php esc_html_e( 'Edit trip', 'travel-steps' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( trim( $trip->post_content ) ) : ?>
				<div class="ts-intro ts-content"><?php echo apply_filters( 'the_content', $trip->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core content filter. ?></div>
			<?php endif; ?>

			<?php if ( ! $steps ) : ?>
				<p class="ts-empty"><?php esc_html_e( 'No steps yet.', 'travel-steps' ); ?></p>
			<?php endif; ?>

			<?php
			$first_day = (string) get_post_meta( $trip->ID, Trips::META_START, true );
			foreach ( $steps as $index => $step ) :
				$day     = $first_day ? Days::day_number( $first_day, Trips::day( $step ) ) : 0;
				$places  = get_the_terms( $step, Trips::PLACE );
				$groups  = $by_step[ $step->ID ] ?? [ 'shown' => [], 'rest' => [], 'hidden' => [] ];
				$more    = $can_edit ? array_merge( $groups['rest'], $groups['hidden'] ) : $groups['rest'];
				?>
				<section class="ts-step" id="step-<?php echo (int) $step->ID; ?>" data-step="<?php echo (int) $step->ID; ?>">
					<div class="ts-step-marker" aria-hidden="true"><?php echo (int) $day ?: (int) $index + 1; ?></div>
					<div class="ts-step-body">
						<p class="ts-step-meta">
							<?php if ( $day > 0 ) : ?>
								<?php /* translators: %d: day number of the trip */ ?>
								<span><?php echo esc_html( sprintf( __( 'Day %d', 'travel-steps' ), $day ) ); ?></span>
							<?php endif; ?>
							<time datetime="<?php echo esc_attr( Trips::day( $step ) ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $step->post_date ) ); ?></time>
							<?php if ( 'publish' !== $step->post_status ) : ?>
								<span class="ts-badge"><?php echo esc_html( get_post_status_object( $step->post_status )->label ?? $step->post_status ); ?></span>
							<?php endif; ?>
						</p>
						<h2><?php echo esc_html( get_the_title( $step ) ); ?></h2>
						<?php if ( $places && ! is_wp_error( $places ) ) : ?>
							<ul class="ts-places">
								<?php foreach ( $places as $place ) : ?>
									<li><?php echo esc_html( $place->name ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( ! wp_strip_all_tags( $step->post_content ) && $can_edit ) : ?>
							<p class="ts-muted"><?php esc_html_e( 'No story yet.', 'travel-steps' ); ?></p>
						<?php endif; ?>
						<?php View::render_story( $step, $groups['shown'], $render_grid ); ?>
						<?php if ( $can_edit ) : ?>
							<p class="ts-step-actions"><a href="<?php echo esc_url( get_edit_post_link( $step->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Write in the block editor', 'travel-steps' ); ?></a></p>
						<?php endif; ?>
						<?php if ( $more ) : ?>
							<details class="ts-more-photos">
								<summary>
									<?php
									// Count what opening it reveals: the photos already shown stay where they are.
									$count = count( $more );
									echo esc_html(
										$groups['shown']
											/* translators: %s: number of photos */
											? sprintf( _n( 'Show %s more photo', 'Show %s more photos', $count, 'travel-steps' ), number_format_i18n( $count ) )
											/* translators: %s: number of photos */
											: sprintf( _n( 'Show %s hidden photo', 'Show %s hidden photos', $count, 'travel-steps' ), number_format_i18n( $count ) )
									);
									?>
								</summary>
								<div class="ts-photos ts-photos-more">
									<?php
									foreach ( $groups['rest'] as $photo ) {
										$render_photo( $photo, isset( $pinned[ $photo['id'] ] ) ? 'pinned' : 'default' );
									}
									if ( $can_edit ) {
										foreach ( $groups['hidden'] as $photo ) {
											$render_photo( $photo, 'hidden' );
										}
									}
									?>
								</div>
							</details>
						<?php endif; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
	</div>

	<dialog class="ts-lightbox">
		<button type="button" class="ts-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'travel-steps' ); ?>">×</button>
		<button type="button" class="ts-lightbox-prev" aria-label="<?php esc_attr_e( 'Previous photo', 'travel-steps' ); ?>">‹</button>
		<figure>
			<img alt="">
			<figcaption></figcaption>
		</figure>
		<button type="button" class="ts-lightbox-next" aria-label="<?php esc_attr_e( 'Next photo', 'travel-steps' ); ?>">›</button>
	</dialog>
</article>
<?php
include __DIR__ . '/_footer.php';
