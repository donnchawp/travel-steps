<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
status_header( 404 );
$page_title = __( 'Trip not found', 'travel-steps-storyteller' );
include __DIR__ . '/_header.php';
?>
<div class="ts-page">
	<h1><?php esc_html_e( 'Trip not found', 'travel-steps-storyteller' ); ?></h1>
	<p><a href="<?php echo esc_url( \TravelSteps\App::url() ); ?>"><?php esc_html_e( 'All trips', 'travel-steps-storyteller' ); ?></a></p>
</div>
<?php
include __DIR__ . '/_footer.php';
