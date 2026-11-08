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
$id      = fn( int $n ) => photo( $n )['id'];
$nums    = fn( array $photos ) => array_map( fn( $p ) => (int) substr( $p['id'], -12 ), $photos );
$layouts = fn( array $pages ) => array_column( $pages, 'layout' );

// lead_first()
check( 'lead is first chosen, others by time', [ 3, 1, 2 ] === $nums( PrintLayout::lead_first( [ photo( 1 ), photo( 2 ), photo( 3 ) ], [ $id( 3 ), $id( 2 ), $id( 1 ) ] ) ) );
check( 'unchosen photos drop out', [ 2 ] === $nums( PrintLayout::lead_first( [ photo( 1 ), photo( 2 ) ], [ $id( 2 ) ] ) ) );
check( 'ids not in the section are ignored', [ 1 ] === $nums( PrintLayout::lead_first( [ photo( 1 ) ], [ $id( 9 ), $id( 1 ) ] ) ) );
check( 'nothing chosen: nothing', [] === PrintLayout::lead_first( [ photo( 1 ) ], [] ) );

// pages()
check( 'one photo: alone', [ 'landscape' ] === $layouts( PrintLayout::pages( [ photo( 1 ) ] ) ) );
check( 'portrait alone', [ 'portrait' ] === $layouts( PrintLayout::pages( [ photo( 1, 2000, 3000 ) ] ) ) );
check( 'size unknown: landscape', [ 'landscape' ] === $layouts( PrintLayout::pages( [ [ 'id' => 'x' ] ] ) ) );
check( 'two photos: each alone', [ 'landscape', 'portrait' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2, 2000, 3000 ) ] ) ) );
check( 'three: lead, then two landscapes stacked', [ 'landscape', 'stack' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2 ), photo( 3 ) ] ) ) );
check( 'three: two portraits side by side', [ 'landscape', 'pair' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2, 2000, 3000 ), photo( 3, 2000, 3000 ) ] ) ) );
check( 'three: a landscape and a portrait side by side', [ 'landscape', 'pair' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2 ), photo( 3, 2000, 3000 ) ] ) ) );
check( 'four: lead, two together, then one alone', [ 'landscape', 'stack', 'landscape' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2 ), photo( 3 ), photo( 4 ) ] ) ) );
check( 'five: three pages, the lead, then two and two', [ 'landscape', 'stack', 'stack' ] === $layouts( PrintLayout::pages( array_map( 'photo', [ 1, 2, 3, 4, 5 ] ) ) ) );
check( 'six: a fourth page, for the one left over', [ 'landscape', 'stack', 'stack', 'landscape' ] === $layouts( PrintLayout::pages( array_map( 'photo', [ 1, 2, 3, 4, 5, 6 ] ) ) ) );
check( 'the lead keeps its place', [ 7 ] === $nums( PrintLayout::pages( [ photo( 7 ), photo( 1 ) ] )[0]['photos'] ) );
check( 'no photos: no pages', [] === PrintLayout::pages( [] ) );

// after_removal(): chosen out of time order, 3, then 2, then 1.
$list    = [ $id( 3 ), $id( 2 ), $id( 1 ) ];
$section = [ photo( 1 ), photo( 2 ), photo( 3 ) ];
check( 'removing the lead: next by time leads', [ $id( 1 ), $id( 2 ) ] === PrintLayout::after_removal( $list, $id( 3 ), $section ) );
check( 'removing another keeps the lead', [ $id( 3 ), $id( 1 ) ] === PrintLayout::after_removal( $list, $id( 2 ), $section ) );
check( 'removing the only one leaves another section alone', [ $id( 9 ) ] === PrintLayout::after_removal( [ $id( 3 ), $id( 9 ) ], $id( 3 ), [ photo( 3 ) ] ) );

exit( $failures ? 1 : 0 );
