<?php
/**
 * Prints a copy of a gallery blueprint that takes the release's files from dist/
 * instead of the latest release, then runs check.php. Used by `make release-check`:
 *
 *     php tests/release/blueprint.php demo/blueprint.json > blueprint.json
 *
 * A blueprint that installs demo-setup.php gets the demo's checks too. Playground mounts
 * dist/ at /dist and this folder at /release-check. Everything else in the blueprint
 * (PHP and WordPress versions, networking, login, landing page, steps) stays as it is.
 */

const RELEASE = 'https://github.com/donnchawp/travel-steps/releases/latest/download/';

function fail( string $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$file      = $argv[1] ?? '';
// Decoded as objects, so an empty {} stays an object rather than becoming [].
$blueprint = json_decode( (string) @file_get_contents( $file ) );
if ( ! $blueprint instanceof stdClass || ! isset( $blueprint->steps ) ) {
	fail( "Usage: php tests/release/blueprint.php <blueprint.json>. Not a blueprint: $file" );
}

// Swaps every release URL resource, however deep, for the same file in /dist.
$names = [];
$swap  = function ( $node ) use ( &$swap, &$names ) {
	if ( is_array( $node ) ) {
		return array_map( $swap, $node );
	}
	if ( ! $node instanceof stdClass ) {
		return $node;
	}
	if ( 'url' === ( $node->resource ?? '' ) && 0 === strpos( (string) ( $node->url ?? '' ), RELEASE ) ) {
		$names[] = substr( $node->url, strlen( RELEASE ) );
		return (object) [ 'resource' => 'vfs', 'path' => '/dist/' . end( $names ) ];
	}
	return (object) array_map( $swap, get_object_vars( $node ) );
};
$blueprint->steps = $swap( $blueprint->steps );
if ( ! $names ) {
	fail( "$file installs nothing from the release, so it would test nothing" );
}

$kind               = in_array( 'demo-setup.php', $names, true ) ? 'demo' : 'plugin';
$blueprint->steps[] = [
	'step' => 'runPHP',
	'code' => sprintf( "<?php \$release_check = [ 'kind' => %s, 'landing' => %s ]; require '/release-check/check.php';", var_export( $kind, true ), var_export( (string) ( $blueprint->landingPage ?? '/' ), true ) ),
];
echo json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
