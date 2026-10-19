<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use TravelSteps\RestController;

// The router's $wp_app_route holds the URL's parts: see RestController::photo_url().
$params = $wp_app_route['params'] ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variable.
RestController::serve_photo( (int) ( $params['trip_id'] ?? 0 ), (string) ( $params['asset'] ?? '' ), (string) ( $params['size'] ?? '' ) );
