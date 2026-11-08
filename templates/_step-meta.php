<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * A step's day, date and any badge, on the trip page and in its print version. Set $trip,
 * $step and $day before including.
 */
use TravelSteps\Trips;
use TravelSteps\View;

$badge = View::step_badge( $trip, $step );
?>
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
