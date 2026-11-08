<?php
/**
 * Standalone checks for the print layout: php tests/PrintLayoutTest.php
 */
require __DIR__ . '/../src/PrintLayout.php';

use TravelSteps\PrintLayout;

$failures = 0;
function check( string $name, bool $ok ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	$failures += $ok ? 0 : 1;
}
function photo( int $n, int $w = 3000, int $h = 2000 ): array {
	return [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', $n ), 'w' => $w, 'h' => $h ];
}
$ids     = fn( array $row ) => array_map( fn( $p ) => (int) substr( $p['id'], -12 ), $row['photos'] );
$layouts = fn( array $rows ) => array_column( $rows, 'layout' );
// Large for the photos listed, medium for the rest.
$large_for = fn( int ...$large ) => fn( array $p ) => in_array( (int) substr( $p['id'], -12 ), $large, true ) ? PrintLayout::LARGE : PrintLayout::MEDIUM;

// size()
check( 'alone in its place: large', PrintLayout::LARGE === PrintLayout::size( photo( 1 ), 1 ) );
check( 'one of several: medium', PrintLayout::MEDIUM === PrintLayout::size( photo( 1 ), 4 ) );
check( 'a panorama: large among others', PrintLayout::LARGE === PrintLayout::size( photo( 1, 6000, 2000 ), 4 ) );
check( 'just under panorama: medium', PrintLayout::MEDIUM === PrintLayout::size( photo( 1, 3999, 2000 ), 4 ) );
check( 'too few pixels: medium, even alone', PrintLayout::MEDIUM === PrintLayout::size( photo( 1, 1000, 750 ), 1 ) );
check( 'too few pixels: medium, even a panorama', PrintLayout::MEDIUM === PrintLayout::size( photo( 1, 1100, 400 ), 3 ) );
check( 'size unknown: large alone', PrintLayout::LARGE === PrintLayout::size( [ 'id' => 'x' ], 1 ) );
check( 'size unknown: medium among others', PrintLayout::MEDIUM === PrintLayout::size( [ 'id' => 'x', 'w' => 0, 'h' => 0 ], 2 ) );

// rows()
$all_medium = $large_for();
$rows       = PrintLayout::rows( [ photo( 1 ), photo( 2 ), photo( 3 ), photo( 4 ) ], $all_medium );
check( 'four landscape mediums: two low rows', [ 'low', 'low' ] === $layouts( $rows ) && [ 1, 2 ] === $ids( $rows[0] ) && [ 3, 4 ] === $ids( $rows[1] ) );
$rows = PrintLayout::rows( [ photo( 1 ), photo( 2 ), photo( 3 ) ], $all_medium );
check( 'odd one out: a low row of one at the end', [ 'low', 'low' ] === $layouts( $rows ) && [ 3 ] === $ids( $rows[1] ) );
$rows = PrintLayout::rows( [ photo( 1 ), photo( 2, 2000, 3000 ), photo( 3, 2000, 3000 ), photo( 4, 2000, 3000 ) ], $all_medium );
check( 'a portrait in the pair: the taller row', [ 'pair', 'pair' ] === $layouts( $rows ) );
$rows = PrintLayout::rows( [ photo( 1 ), photo( 2, 2000, 2000 ) ], $all_medium );
check( 'a square in the pair: the taller row', [ 'pair' ] === $layouts( $rows ) );
$rows = PrintLayout::rows( [ photo( 1 ), [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', 2 ) ] ], $all_medium );
check( 'a size unknown in the pair: the taller row', [ 'pair' ] === $layouts( $rows ) );
$rows = PrintLayout::rows( [ photo( 1, 2000, 3000 ) ], $all_medium );
check( 'a portrait alone: the taller row', [ 'pair' ] === $layouts( $rows ) );

$rows = PrintLayout::rows( [ photo( 1 ), photo( 2, 2000, 3000 ) ], $large_for( 1, 2 ) );
check( 'large landscape is wide, large portrait tall', [ 'wide', 'tall' ] === $layouts( $rows ) );
$rows = PrintLayout::rows( [ photo( 1, 2000, 2000 ) ], $large_for( 1 ) );
check( 'a large square prints wide', [ 'wide' ] === $layouts( $rows ) );

// image_size()
check( 'side by side: the medium image', 'medium' === PrintLayout::image_size( 'low' ) && 'medium' === PrintLayout::image_size( 'pair' ) );
check( 'across or down the page: the preview', 'preview' === PrintLayout::image_size( 'wide' ) && 'preview' === PrintLayout::image_size( 'tall' ) );

// Order is kept: a large photo between mediums ends the pair before it.
$rows = PrintLayout::rows( [ photo( 1 ), photo( 2 ), photo( 3 ), photo( 4 ), photo( 5 ) ], $large_for( 2 ) );
check( 'large in the middle splits the mediums around it', [ 'low', 'wide', 'low', 'low' ] === $layouts( $rows ) );
check( '…in the order taken', [ [ 1 ], [ 2 ], [ 3, 4 ], [ 5 ] ] === array_map( $ids, $rows ) );
check( 'no photos: no rows', [] === PrintLayout::rows( [], $all_medium ) );

exit( $failures ? 1 : 0 );
