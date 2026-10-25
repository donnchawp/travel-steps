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
$trips      = array_values( array_filter( $trips, [ Trips::class, 'listed' ] ) );
// Newest trip first by first day, undated drafts on top; trips starting the same day run most recently made (highest ID) first.
$start      = [];
foreach ( $trips as $trip ) {
	$start[ $trip->ID ] = get_post_meta( $trip->ID, Trips::META_START, true ) ?: '9999';
}
usort( $trips, fn( $a, $b ) => [ $start[ $b->ID ], $b->ID ] <=> [ $start[ $a->ID ], $a->ID ] );
$page_title = __( 'Trips', 'travel-steps-storyteller' );
include __DIR__ . '/_header.php';
?>
<div class="ts-page">
	<div class="ts-page-head">
		<h1><?php esc_html_e( 'Trips', 'travel-steps-storyteller' ); ?></h1>
		<?php if ( current_user_can( Trips::capability() ) ) : ?>
			<a class="ts-button" href="<?php echo esc_url( App::url( 'new' ) ); ?>"><?php esc_html_e( 'New trip', 'travel-steps-storyteller' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( ! $trips ) : ?>
		<p class="ts-empty"><?php esc_html_e( 'No trips yet.', 'travel-steps-storyteller' ); ?></p>
	<?php endif; ?>

	<div class="ts-cards">
		<?php foreach ( $trips as $trip ) : ?>
			<?php $cover = View::cover_url( $trip->ID ); ?>
			<a class="ts-card" href="<?php echo esc_url( Trips::url( $trip ) ); ?>">
				<div class="ts-card-cover">
					<?php if ( $cover ) : ?>
						<img src="<?php echo esc_url( $cover ); ?>" alt="" loading="lazy">
					<?php endif; ?>
					<?php
					$badges = array_filter(
						[
							'publish' !== $trip->post_status ? ( get_post_status_object( $trip->post_status )->label ?? $trip->post_status ) : '',
							Trips::is_protected( $trip ) && current_user_can( 'edit_post', $trip->ID ) ? __( 'Password protected', 'travel-steps-storyteller' ) : '',
						]
					);
					?>
					<?php if ( $badges ) : ?>
						<span class="ts-card-badges">
							<?php foreach ( $badges as $badge ) : ?>
								<span class="ts-badge"><?php echo esc_html( $badge ); ?></span>
							<?php endforeach; ?>
						</span>
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
