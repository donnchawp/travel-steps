<?php
/**
 * Prints a copy of a gallery blueprint that takes the release's files from dist/
 * instead of the latest release, then runs check.php. Used by `make release-check`:
 *
 *     php tests/release/blueprint.php demo/blueprint.json demo > blueprint.json
 *
 * The second argument says which checks to run: "plugin" or "demo". Playground mounts
 * dist/ at /dist and this folder at /release-check. Everything else in the blueprint
 * (PHP and WordPress versions, networking, login, landing page, steps) stays as it is.
 */

const RELEASE = 'https://github.com/donnchawp/travel-steps/releases/latest/download/';
const ASSETS  = [ 'travel-steps.zip', 'demo-setup.php', 'demo-fake-services.php' ]; // What `make dist` builds.

function fail( string $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

[ , $file, $kind ] = $argv + [ '', '', '' ];
if ( ! in_array( $kind, [ 'plugin', 'demo' ], true ) ) {
	fail( 'Usage: php tests/release/blueprint.php <blueprint.json> plugin|demo' );
}
$blueprint = json_decode( (string) @file_get_contents( $file ), true );
if ( ! is_array( $blueprint ) || ! isset( $blueprint['steps'] ) ) {
	fail( "Not a blueprint: $file" );
}

// Swaps every release URL resource, however deep, for the same file in /dist.
$swapped = 0;
$swap    = function ( $node ) use ( &$swap, &$swapped ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	if ( 'url' === ( $node['resource'] ?? '' ) && 0 === strpos( (string) ( $node['url'] ?? '' ), RELEASE ) ) {
		$name = substr( $node['url'], strlen( RELEASE ) );
		if ( ! in_array( $name, ASSETS, true ) ) {
			fail( "The blueprint wants $name, which make dist doesn't build" );
		}
		++$swapped;
		return [ 'resource' => 'vfs', 'path' => '/dist/' . $name ];
	}
	return array_map( $swap, $node );
};
$blueprint['steps'] = $swap( $blueprint['steps'] );
if ( ! $swapped ) {
	fail( "$file installs nothing from the release, so it would test nothing" );
}

$blueprint['steps'][] = [
	'step' => 'runPHP',
	'code' => sprintf( "<?php \$release_check = [ 'kind' => %s, 'landing' => %s ]; require '/release-check/check.php';", var_export( $kind, true ), var_export( (string) ( $blueprint['landingPage'] ?? '/' ), true ) ),
];
echo json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
