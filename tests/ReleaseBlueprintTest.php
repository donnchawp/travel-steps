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
// Runs the rewrite as `make release-check` does; returns [ exit code, decoded blueprint, JSON ].
function rewrite( string $blueprint ): array {
	exec( 'php ' . escapeshellarg( __DIR__ . '/release/blueprint.php' ) . ' ' . escapeshellarg( $blueprint ) . ' 2>/dev/null', $lines, $status );
	return [ $status, json_decode( implode( "\n", $lines ), true ), implode( "\n", $lines ) ];
}
function vfs( string $name ): array {
	return [ 'resource' => 'vfs', 'path' => '/dist/' . $name ];
}
// Every resource in a blueprint's steps, however deep.
function resources( $node ): array {
	if ( ! is_array( $node ) ) {
		return [];
	}
	return isset( $node['resource'] ) ? [ $node ] : array_merge( [], ...array_values( array_map( 'resources', $node ) ) );
}
// The rewritten blueprint's /dist files are exactly the release files the original asks for.
function from_dist( array $original, array $rewritten ): bool {
	$release = array_filter( resources( $original['steps'] ), fn( $r ) => 'url' === $r['resource'] && false !== strpos( $r['url'], '/releases/latest/download/' ) );
	$want    = array_map( fn( $r ) => vfs( basename( $r['url'] ) ), array_values( $release ) );
	$got     = array_values( array_filter( resources( $rewritten['steps'] ), fn( $r ) => 'vfs' === $r['resource'] ) );
	sort( $want );
	sort( $got );
	return [] !== $want && $want === $got;
}

$demo_file         = __DIR__ . '/../demo/blueprint.json';
$gallery           = json_decode( file_get_contents( $demo_file ), true );
[ $status, $demo ] = rewrite( $demo_file );
check( 'demo: rewrites cleanly', 0 === $status && is_array( $demo ) );

check( 'demo: no release URL left', false === strpos( json_encode( $demo ), 'releases/latest/download' ) );
check( 'demo: the plugin, the stand-in servers and the setup script come from dist', from_dist( $gallery, $demo ) && 3 === count( resources( $demo['steps'] ) ) );
check( 'demo: everything but the steps is the gallery’s', array_diff_key( $gallery, [ 'steps' => 0 ] ) === array_diff_key( $demo, [ 'steps' => 0 ] ) );
$kept = array_slice( $demo['steps'], 0, count( $gallery['steps'] ) );
check( 'demo: the gallery’s steps keep their order and options', array_column( $gallery['steps'], 'step' ) === array_column( $kept, 'step' ) && array_column( $gallery['steps'], 'options' ) === array_column( $kept, 'options' ) );

$last = end( $demo['steps'] );
check( 'demo: the checks run last', count( $gallery['steps'] ) + 1 === count( $demo['steps'] ) && 'runPHP' === $last['step'] );
check( 'demo: the checks know the blueprint and its landing page', false !== strpos( $last['code'], "'demo'" ) && false !== strpos( $last['code'], "'/travel-steps/trip/west-of-ireland/'" ) && false !== strpos( $last['code'], "require '/release-check/check.php'" ) );

$plugin_file         = __DIR__ . '/../demo/plugin-blueprint.json';
[ $status, $plugin ] = rewrite( $plugin_file );
check( 'plugin: rewrites cleanly', 0 === $status && is_array( $plugin ) );
check( 'plugin: installs from dist', from_dist( json_decode( file_get_contents( $plugin_file ), true ), $plugin ) && in_array( vfs( 'travel-steps.zip' ), resources( $plugin['steps'] ), true ) );
check( 'plugin: the checks know the blueprint and its landing page', false !== strpos( end( $plugin['steps'] )['code'], "'plugin'" ) && false !== strpos( end( $plugin['steps'] )['code'], "'/travel-steps/'" ) );

// A blueprint that installs nothing from the release would test nothing.
$tmp = tempnam( sys_get_temp_dir(), 'bp' );
file_put_contents( $tmp, json_encode( [ 'landingPage' => '/', 'steps' => [ [ 'step' => 'login' ] ] ] ) );
check( 'no release files: refused', 0 !== rewrite( $tmp )[0] );

// Empty objects stay objects, in the blueprint and in its steps.
file_put_contents( $tmp, '{"constants":{},"steps":[{"step":"installPlugin","pluginData":{"resource":"url","url":"https://github.com/donnchawp/travel-steps/releases/latest/download/travel-steps.zip"},"options":{}}]}' );
$json = json_decode( rewrite( $tmp )[2] );
check( 'empty objects: kept as objects', $json && $json->constants instanceof stdClass && $json->steps[0]->options instanceof stdClass && 'vfs' === $json->steps[0]->pluginData->resource );
unlink( $tmp );

exit( $failures ? 1 : 0 );
