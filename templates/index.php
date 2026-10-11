<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\App;
use TravelSteps\Trips;
use TravelSteps\View;

$can_edit   = current_user_can( 'edit_posts' );
$trips      = get_posts(
	[
		'post_type'      => Trips::TRIP,
		'post_status'    => $can_edit ? [ 'publish', 'draft', 'private', 'pending' ] : 'publish',
		'posts_per_page' => -1,
	]
);
$trips      = array_values( array_filter( $trips, [ Trips::class, 'can_view' ] ) );
// Newest trip first; undated drafts on top.
usort( $trips, fn( $a, $b ) => strcmp( get_post_meta( $b->ID, Trips::META_START, true ) ?: '9999', get_post_meta( $a->ID, Trips::META_START, true ) ?: '9999' ) );
$page_title = __( 'Trips', 'travel-steps' );
include __DIR__ . '/_header.php';
?>
<div class="ts-page">
	<div class="ts-page-head">
		<h1><?php esc_html_e( 'Trips', 'travel-steps' ); ?></h1>
		<?php if ( $can_edit ) : ?>
			<a class="ts-button" href="<?php echo esc_url( App::url( 'new' ) ); ?>"><?php esc_html_e( 'New trip', 'travel-steps' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( ! $trips ) : ?>
		<p class="ts-empty"><?php esc_html_e( 'No trips yet.', 'travel-steps' ); ?></p>
	<?php endif; ?>

	<div class="ts-cards">
		<?php foreach ( $trips as $trip ) : ?>
			<?php $cover = View::cover_url( $trip->ID ); ?>
			<a class="ts-card" href="<?php echo esc_url( Trips::url( $trip ) ); ?>">
				<div class="ts-card-cover">
					<?php if ( $cover ) : ?>
						<img src="<?php echo esc_url( $cover ); ?>" alt="" loading="lazy">
					<?php endif; ?>
					<?php if ( 'publish' !== $trip->post_status ) : ?>
						<span class="ts-badge"><?php echo esc_html( get_post_status_object( $trip->post_status )->label ?? $trip->post_status ); ?></span>
					<?php endif; ?>
				</div>
				<div class="ts-card-body">
					<h2><?php echo esc_html( get_the_title( $trip ) ); ?></h2>
					<p class="ts-muted"><?php echo esc_html( View::date_range( $trip->ID ) ); ?></p>
					<p class="ts-card-stats">
						<?php foreach ( View::stats( $trip->ID ) as $label => $value ) : ?>
							<span><strong><?php echo esc_html( $value ); ?></strong> <?php echo esc_html( $label ); ?></span>
						<?php endforeach; ?>
					</p>
				</div>
			</a>
		<?php endforeach; ?>
	</div>
</div>
<?php
include __DIR__ . '/_footer.php';
