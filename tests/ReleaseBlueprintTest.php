<?php
/**
 * Standalone checks for the blueprint rewrite behind `make release-check`: php tests/ReleaseBlueprintTest.php
 */

$failures = 0;
function check( string $name, bool $ok ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	$failures += $ok ? 0 : 1;
}
// Runs the rewrite as `make release-check` does; returns [ exit code, decoded blueprint ].
function rewrite( string $blueprint, string $kind ): array {
	exec( 'php ' . escapeshellarg( __DIR__ . '/release/blueprint.php' ) . ' ' . escapeshellarg( $blueprint ) . ' ' . escapeshellarg( $kind ) . ' 2>/dev/null', $lines, $status );
	return [ $status, json_decode( implode( "\n", $lines ), true ) ];
}

$demo_file      = __DIR__ . '/../demo/blueprint.json';
$gallery        = json_decode( file_get_contents( $demo_file ), true );
[ $status, $demo ] = rewrite( $demo_file, 'demo' );
check( 'demo: rewrites cleanly', 0 === $status && is_array( $demo ) );

$encoded = json_encode( $demo );
check( 'demo: no release URL left', false === strpos( $encoded, 'releases/latest/download' ) );
check( 'demo: the plugin comes from dist', [ 'resource' => 'vfs', 'path' => '/dist/travel-steps.zip' ] === $demo['steps'][0]['pluginData'] );
check( 'demo: the stand-in servers come from dist', [ 'resource' => 'vfs', 'path' => '/dist/demo-fake-services.php' ] === $demo['steps'][2]['data'] );
check( 'demo: the setup script comes from dist', [ 'resource' => 'vfs', 'path' => '/dist/demo-setup.php' ] === $demo['steps'][3]['data'] );

$same = $demo;
unset( $same['steps'] );
$expected = $gallery;
unset( $expected['steps'] );
check( 'demo: everything but the steps is the gallery’s', $expected === $same );
check( 'demo: the gallery’s steps keep their order and options', array_column( $gallery['steps'], 'step' ) === array_column( array_slice( $demo['steps'], 0, count( $gallery['steps'] ) ), 'step' ) && $gallery['steps'][0]['options'] === $demo['steps'][0]['options'] );

$last = end( $demo['steps'] );
check( 'demo: the checks run last', count( $gallery['steps'] ) + 1 === count( $demo['steps'] ) && 'runPHP' === $last['step'] );
check( 'demo: the checks know the blueprint and its landing page', false !== strpos( $last['code'], "'demo'" ) && false !== strpos( $last['code'], "'/travel-steps/trip/west-of-ireland/'" ) && false !== strpos( $last['code'], "require '/release-check/check.php'" ) );

[ $status, $plugin ] = rewrite( __DIR__ . '/../demo/plugin-blueprint.json', 'plugin' );
check( 'plugin: rewrites cleanly', 0 === $status && is_array( $plugin ) );
check( 'plugin: installs from dist', [ 'resource' => 'vfs', 'path' => '/dist/travel-steps.zip' ] === $plugin['steps'][0]['pluginData'] );
check( 'plugin: the checks know the landing page', false !== strpos( end( $plugin['steps'] )['code'], "'/travel-steps/'" ) );

// A blueprint that installs nothing from the release would test nothing.
$tmp = tempnam( sys_get_temp_dir(), 'bp' );
file_put_contents( $tmp, json_encode( [ 'landingPage' => '/', 'steps' => [ [ 'step' => 'login' ] ] ] ) );
check( 'no release files: refused', 0 !== rewrite( $tmp, 'plugin' )[0] );
file_put_contents( $tmp, json_encode( [ 'landingPage' => '/', 'steps' => [ [ 'step' => 'installPlugin', 'pluginData' => [ 'resource' => 'url', 'url' => 'https://github.com/donnchawp/travel-steps/releases/latest/download/other.zip' ] ] ] ] ) );
check( 'a release file dist doesn’t build: refused', 0 !== rewrite( $tmp, 'plugin' )[0] );
unlink( $tmp );
check( 'an unknown kind: refused', 0 !== rewrite( $demo_file, 'other' )[0] );

exit( $failures ? 1 : 0 );
