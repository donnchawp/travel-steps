<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Shared header partial. Set $page_title before including.
 */
wp_app_enqueue_style(
	'travel-steps',
	TRAVEL_STEPS_PLUGIN_URL . 'assets/travel-steps.css',
	[],
	filemtime( TRAVEL_STEPS_PLUGIN_DIR . 'assets/travel-steps.css' ),
	'travel-steps'
);
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php wp_app_the_title( isset( $page_title ) ? $page_title : '' ); ?></title>
	<?php wp_app_head(); ?>
</head>
<body class="wp-app-body ts-body">
	<?php wp_app_body_open(); ?>
	<main class="ts-main">
