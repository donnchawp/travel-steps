<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * A short book of the photos chosen on the trip page, to print or save as a PDF: a cover
 * page, then for each place with photos chosen, its text on a page, its lead photo alone,
 * then its other photos together (see PrintLayout). Logged-in users only.
 *
 * Every photo is the preview size, the largest the photo proxy serves and the one
 * CacheFill keeps, so the print works from the cache when Immich can't be reached.
 */
use TravelSteps\PrintLayout;
use TravelSteps\RestController;
use TravelSteps\Trips;
use TravelSteps\View;

// What this page shows depends on who's logged in, so no page cache may keep it.
nocache_headers();
if ( ! is_user_logged_in() ) {
	include __DIR__ . '/_notfound.php';
	return;
}
$trip_suffix = 'print';
$trip        = include __DIR__ . '/_trip-gate.php';
if ( ! $trip ) {
	return;
}

// Editors also get unpublished steps, marked as on the trip page.
$can_edit = current_user_can( 'edit_post', $trip->ID );
$book     = View::print_book( $trip, Trips::timeline_steps( $trip, $can_edit ) );
$cover    = View::cover_url( $trip->ID, 'preview' );

$render_photo = function ( array $photo ) use ( $trip ) {
	$caption = (string) ( $photo['caption'] ?? '' );
	?>
	<figure class="ts-print-photo">
		<img src="<?php echo esc_url( RestController::photo_url( $trip->ID, $photo['id'], 'preview' ) ); ?>"
			alt="<?php echo esc_attr( $caption ); ?>" decoding="async"
			<?php if ( ! empty( $photo['w'] ) && ! empty( $photo['h'] ) ) : ?>
				width="<?php echo (int) $photo['w']; ?>" height="<?php echo (int) $photo['h']; ?>"
			<?php endif; ?>>
		<?php if ( '' !== $caption ) : ?>
			<figcaption><?php echo esc_html( $caption ); ?></figcaption>
		<?php endif; ?>
	</figure>
	<?php
};

wp_app_enqueue_style(
	'travel-steps-print',
	TRAVEL_STEPS_PLUGIN_URL . 'assets/travel-steps-print.css',
	[ 'travel-steps' ],
	filemtime( TRAVEL_STEPS_PLUGIN_DIR . 'assets/travel-steps-print.css' ),
	'travel-steps'
);
wp_app_enqueue_script(
	'travel-steps-print',
	TRAVEL_STEPS_PLUGIN_URL . 'assets/travel-steps-print.js',
	[],
	filemtime( TRAVEL_STEPS_PLUGIN_DIR . 'assets/travel-steps-print.js' ),
	true,
	'travel-steps'
);

$page_title = get_the_title( $trip );
include __DIR__ . '/_header.php';
?>
<div class="ts-print-bar">
	<a href="<?php echo esc_url( Trips::url( $trip ) ); ?>">← <?php esc_html_e( 'Back to the trip', 'travel-steps-storyteller' ); ?></a>
	<?php // The script fills this in as the photos load. ?>
	<span class="ts-print-status" aria-live="polite"
		data-loading="<?php esc_attr_e( 'Loading photos…', 'travel-steps-storyteller' ); ?>"
		data-failed="<?php esc_attr_e( 'Some photos couldn’t be loaded and will be missing from the print.', 'travel-steps-storyteller' ); ?>"
		data-tip="<?php esc_attr_e( 'To keep a copy, choose “Save as PDF” as the printer.', 'travel-steps-storyteller' ); ?>"></span>
	<button type="button" class="ts-button ts-print-button"><?php esc_html_e( 'Print or save as PDF', 'travel-steps-storyteller' ); ?></button>
</div>

<article class="ts-print"
	<?php if ( $shared ) : ?>
		data-clean-url="<?php echo esc_url( Trips::url( $trip, 'print' ) ); ?>"
	<?php endif; ?>>
	<header class="ts-print-cover">
		<?php if ( $cover ) : ?>
			<img class="ts-print-cover-img" src="<?php echo esc_url( $cover ); ?>" alt="" decoding="async">
		<?php endif; ?>
		<p class="ts-print-dates"><?php echo esc_html( View::date_range( $trip->ID ) ); ?></p>
		<h1><?php echo esc_html( get_the_title( $trip ) ); ?></h1>
		<ul class="ts-stats">
			<?php foreach ( View::stats( $trip->ID ) as $label => $value ) : ?>
				<li><strong><?php echo esc_html( $value ); ?></strong> <?php echo esc_html( $label ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( trim( $trip->post_content ) ) : ?>
			<div class="ts-intro ts-content"><?php echo apply_filters( 'the_content', $trip->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core content filter. ?></div>
		<?php endif; ?>
	</header>

	<?php if ( ! $book && $can_edit ) : ?>
		<p class="ts-notice is-warning ts-print-note">
			<?php esc_html_e( 'No photos are chosen for printing yet.', 'travel-steps-storyteller' ); ?>
			<a href="<?php echo esc_url( Trips::url( $trip ) ); ?>"><?php esc_html_e( 'Choose them on the trip page.', 'travel-steps-storyteller' ); ?></a>
		</p>
	<?php endif; ?>

	<?php
	foreach ( $book as $entry ) :
		$step  = $entry['step'];
		$day   = Trips::day_of_trip( $trip->ID, $step );
		$badge = View::step_badge( $trip, $step );
		foreach ( $entry['sections'] as $section ) :
			?>
			<?php if ( $section['text_page'] ) : ?>
				<section class="ts-book-text">
					<p class="ts-step-meta">
						<?php if ( $day > 0 ) : ?>
							<?php /* translators: %d: day number of the trip */ ?>
							<span><?php echo esc_html( sprintf( __( 'Day %d', 'travel-steps-storyteller' ), $day ) ); ?></span>
						<?php endif; ?>
						<time datetime="<?php echo esc_attr( Trips::day( $step ) ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $step->post_date ) ); ?></time>
						<?php if ( $badge ) : ?>
							<span class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['text'] ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $section['first'] ) : ?>
						<h2 class="ts-book-title"><?php echo esc_html( get_the_title( $step ) ); ?></h2>
					<?php endif; ?>
					<?php View::render_section_text( $section ); ?>
				</section>
			<?php endif; ?>
			<?php foreach ( PrintLayout::pages( $section['chosen'] ) as $page ) : ?>
				<section class="ts-book-photos is-<?php echo esc_attr( $page['layout'] ); ?>">
					<?php
					foreach ( $page['photos'] as $photo ) {
						$render_photo( $photo );
					}
					?>
				</section>
			<?php endforeach; ?>
			<?php
		endforeach;
	endforeach;
	?>
</article>
<?php
include __DIR__ . '/_footer.php';
