<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * A trip laid out for paper, to print or save as a PDF: a cover page, then each step on
 * a new page with its story, and its photos under the place headings they belong to,
 * printed large or medium (see PrintLayout). No map, lightbox or photo tools.
 *
 * Large photos are the preview size, the largest the photo proxy serves, and medium ones
 * the medium size (see PrintLayout::image_size()), which the proxy makes from the preview.
 * CacheFill keeps the preview, so the print works from the cache when Immich can't be reached.
 */
use TravelSteps\PrintLayout;
use TravelSteps\RestController;
use TravelSteps\Trips;
use TravelSteps\View;

$trip_suffix = 'print';
$trip        = include __DIR__ . '/_trip-gate.php';
if ( ! $trip ) {
	return;
}

// What the trip page shows them: editors see unpublished steps, marked as on the page.
$can_edit = current_user_can( 'edit_post', $trip->ID );
$steps    = Trips::timeline_steps( $trip, $can_edit );
$by_step  = Trips::photos_by_step( $trip->ID, $steps );
$cover    = View::cover_url( $trip->ID, 'preview' );

$render_photo = function ( array $photo, string $image_size ) use ( $trip ) {
	$caption = (string) ( $photo['caption'] ?? '' );
	?>
	<figure class="ts-print-photo">
		<img src="<?php echo esc_url( RestController::photo_url( $trip->ID, $photo['id'], $image_size ) ); ?>"
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
// A place's photos, in rows the printer keeps whole.
$render_grid = function ( array $photos ) use ( $render_photo ) {
	$of_place = count( $photos );
	$size     = function ( array $photo ) use ( $of_place ) {
		/**
		 * Whether a photo prints large or medium in a trip's print version. Large photos
		 * print one (portrait) or two (landscape) to a page; medium ones six (landscape)
		 * or four to a page.
		 *
		 * @param string $size     PrintLayout::LARGE or PrintLayout::MEDIUM.
		 * @param array  $photo    The photo row: id, w and h (pixels, as displayed), t,
		 *                         fav, caption, city, country…
		 * @param int    $of_place How many photos the photo's place has in the print.
		 */
		$size = apply_filters( 'travel_steps_print_size', PrintLayout::size( $photo, $of_place ), $photo, $of_place );
		return PrintLayout::LARGE === $size ? PrintLayout::LARGE : PrintLayout::MEDIUM;
	};
	foreach ( PrintLayout::rows( $photos, $size ) as $row ) {
		?>
		<div class="ts-print-row is-<?php echo esc_attr( $row['layout'] ); ?>">
			<?php
			foreach ( $row['photos'] as $photo ) {
				$render_photo( $photo, PrintLayout::image_size( $row['layout'] ) );
			}
			?>
		</div>
		<?php
	}
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

	<?php
	foreach ( $steps as $step ) :
		$day    = Trips::day_of_trip( $trip->ID, $step );
		$places = get_the_terms( $step, Trips::PLACE );
		$shown  = $by_step[ $step->ID ]['shown'] ?? [];
		?>
		<section class="ts-print-step">
			<p class="ts-step-meta">
				<?php if ( $day > 0 ) : ?>
					<?php /* translators: %d: day number of the trip */ ?>
					<span><?php echo esc_html( sprintf( __( 'Day %d', 'travel-steps-storyteller' ), $day ) ); ?></span>
				<?php endif; ?>
				<time datetime="<?php echo esc_attr( Trips::day( $step ) ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $step->post_date ) ); ?></time>
				<?php $badge = View::step_badge( $trip, $step ); ?>
				<?php if ( $badge ) : ?>
					<span class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['text'] ); ?></span>
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
			<?php View::render_story( $step, $shown, $render_grid ); ?>
		</section>
	<?php endforeach; ?>
</article>
<?php
include __DIR__ . '/_footer.php';
