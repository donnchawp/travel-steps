<?php
/**
 * Checks a gallery blueprint's site after it has installed Travel Steps from dist/.
 * blueprint.php adds this as the blueprint's last step, with $release_check set to
 * [ 'kind' => 'plugin'|'demo', 'landing' => the blueprint's landing page ].
 * Run with `make release-check`. Results go to /out/result.txt, one "ok" or "FAIL" line per check.
 */
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use TravelSteps\{Geocoder, Trips};

$out      = [];
$finished = false;
// Write results even if a fatal error or an exit ends the run, with that as a failure.
register_shutdown_function(
	function () {
		global $out, $finished;
		$error = error_get_last();
		if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			$out[] = 'FAIL fatal: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line'];
		} elseif ( ! $finished ) {
			$out[] = 'FAIL the checks stopped before the end';
		}
		file_put_contents( '/out/result.txt', implode( "\n", $out ) . "\n" );
	}
);
function check( string $name, bool $ok, $detail = '' ) {
	global $out;
	$out[] = ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  -- ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) );
}

// Fetches a page over HTTP, as a visitor's browser would, with errors.php recording its PHP errors.
function check_page( string $path, string $expect ): string {
	@unlink( '/out/errors.txt' );
	$response = wp_remote_get( home_url( $path ), [ 'timeout' => 120 ] );
	$errors   = file_exists( '/out/errors.txt' ) ? trim( file_get_contents( '/out/errors.txt' ) ) : '';
	$code     = wp_remote_retrieve_response_code( $response );
	$body     = wp_remote_retrieve_body( $response );
	check( "$path returns 200", 200 === $code, is_wp_error( $response ) ? $response->get_error_message() : $code );
	check( "$path raises no PHP error or warning", '' === $errors, $errors );
	check( "$path renders the app", false !== strpos( $body, $expect ), substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ), 0, 300 ) );
	check_files( $path, $body );
	return $body;
}

// Every plugin file a page links to, and every file those stylesheets use, is in the zip.
// Assets with a fixed version, like Leaflet, raise no warning when they're missing.
function check_files( string $path, string $body ) {
	$dir = WP_PLUGIN_DIR . '/travel-steps/';
	preg_match_all( '/\b(?:src|href)=["\']([^"\']+)["\']/', $body, $links );
	$files = [];
	foreach ( $links[1] as $url ) {
		$url = html_entity_decode( $url );
		if ( 0 === strpos( $url, TRAVEL_STEPS_PLUGIN_URL ) ) {
			$files[] = strtok( substr( $url, strlen( TRAVEL_STEPS_PLUGIN_URL ) ), '?#' );
		}
	}
	foreach ( $files as $file ) {
		if ( '.css' === substr( $file, -4 ) && is_file( $dir . $file ) ) {
			preg_match_all( '/url\(\s*[\'"]?([^\'")]+)/', file_get_contents( $dir . $file ), $refs );
			foreach ( $refs[1] as $ref ) {
				if ( ! preg_match( '~^(data:|[a-z]+://|//|/|#)~i', $ref ) ) {
					$files[] = dirname( $file ) . '/' . strtok( $ref, '?#' );
				}
			}
		}
	}
	$files   = array_unique( $files );
	$missing = array_values( array_filter( $files, fn( $file ) => ! is_file( $dir . $file ) ) );
	check( "$path has every plugin file it links to", $files && ! $missing, $files ? $missing : 'it links to none' );
}

[ 'kind' => $kind, 'landing' => $landing ] = $release_check;
$dir = WP_PLUGIN_DIR . '/travel-steps/';

check( 'Travel Steps is active', is_plugin_active( 'travel-steps/travel-steps.php' ), array_keys( get_plugins() ) );
$loaded_from = class_exists( Trips::class ) ? ( new ReflectionClass( Trips::class ) )->getFileName() : 'not loaded';
check( 'it runs from wp-content/plugins/travel-steps/', 0 === strpos( $loaded_from, $dir ), $loaded_from );
check( 'that folder is the zip, not a checkout', ! file_exists( $dir . 'Makefile' ) && ! file_exists( $dir . 'tests' ) );
// Uploaded photos get their place names from the bundled GeoNames data, which the demo's sync doesn't use.
$place = class_exists( Geocoder::class ) ? ( new Geocoder() )->nearest( 52.1409, -10.2689 ) : 'not loaded';
check( 'the bundled place names load', [ 'city' => 'Dingle', 'country' => 'Ireland' ] === $place, $place );

$mu_plugin = WPMU_PLUGIN_DIR . '/release-check-errors.php';
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( $mu_plugin, "<?php require '/release-check/errors.php';\n" );

if ( 'demo' === $kind ) {
	$trip = get_page_by_path( 'west-of-ireland', OBJECT, Trips::TRIP );
	check( 'the west-of-ireland trip is published', $trip && 'publish' === $trip->post_status, $trip ? $trip->post_status : 'missing' );
	$id    = $trip ? $trip->ID : 0;
	$route = json_decode( (string) get_post_meta( $id, Trips::META_ROUTE, true ), true );
	check( 'it has steps', count( Trips::steps( $id ) ) > 0 );
	check( 'it has photos', count( Trips::photos( $id ) ) > 0 );
	check( 'it has a route', ! empty( $route[0] ) );
	$html = check_page( $landing, 'class="ts-step"' );
	check( 'the trip page shows the trip', false !== strpos( $html, 'West of Ireland' ) );
	$index = check_page( '/travel-steps/', '<h1>Trips</h1>' );
	check( '/travel-steps/ lists the trip', $trip && false !== strpos( $index, esc_url( Trips::url( $trip ) ) ) );
} else {
	check_page( $landing, '<h1>Trips</h1>' );
}

unlink( $mu_plugin );
$finished = true;
