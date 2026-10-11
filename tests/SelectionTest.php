<?php
/**
 * Standalone checks for photo selection: php tests/SelectionTest.php
 */
require __DIR__ . '/../src/Selection.php';

use TravelSteps\Selection;

$failures = 0;
function check( string $name, bool $ok ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	$failures += $ok ? 0 : 1;
}
function photo( int $n, int $t, bool $fav = false ): array {
	return [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', $n ), 't' => $t, 'fav' => $fav ];
}
$ids = fn( $photos ) => array_map( fn( $p ) => (int) substr( $p['id'], -12 ), $photos );

// A busy day: 300 photos over 10 hours, many in bursts of 5 shots 2s apart.
$day = []; $t = 0; $n = 0;
for ( $b = 0; $b < 60; $b++ ) {
	for ( $i = 0; $i < 5; $i++ ) { $day[] = photo( $n++, $t ); $t += 2; }
	$t += 600;
}

$r = Selection::for_step( $day, [], [], 8 );
check( 'no favourites: auto-picks the limit', 8 === count( $r['shown'] ) );
check( 'rest holds everything else', 292 === count( $r['rest'] ) );
$bursts = array_map( fn( $id ) => intdiv( $id, 5 ), $ids( $r['shown'] ) );
check( 'one photo per burst', count( array_unique( $bursts ) ) === 8 );
check( 'picks span the whole day', min( $bursts ) < 8 && max( $bursts ) > 52 );

// Favourites win outright, however many there are.
$fav = $day;
foreach ( [ 3, 50, 51, 199, 250 ] as $i ) { $fav[ $i ]['fav'] = true; }
$r = Selection::for_step( $fav, [], [], 8 );
check( 'favourites only when there are any', [ 3, 50, 51, 199, 250 ] === $ids( $r['shown'] ) );

// Pinned photos are added to the selection; hidden ones removed everywhere.
$pinned = [ $day[100]['id'] ];
$hidden = [ $day[3]['id'] ];
$r      = Selection::for_step( $fav, $pinned, $hidden, 8 );
check( 'pinned added to favourites', [ 50, 51, 100, 199, 250 ] === $ids( $r['shown'] ) );
check( 'hidden favourite dropped from shown and rest', ! in_array( 3, $ids( $r['shown'] ), true ) && ! in_array( 3, $ids( $r['rest'] ), true ) );
check( 'hidden listed separately', [ 3 ] === $ids( $r['hidden'] ) );

// Pinning on a step with no favourites adds to the auto pick.
$r = Selection::for_step( $day, $pinned, [], 8 );
check( 'pinned added to auto pick', 9 === count( $r['shown'] ) && in_array( 100, $ids( $r['shown'] ), true ) );

// Few photos: all shown, bursts collapsed.
$few = [ photo( 1, 0 ), photo( 2, 3 ), photo( 3, 5 ), photo( 4, 1000 ) ];
$r   = Selection::for_step( $few, [], [], 8 );
check( 'small step: burst of 3 collapses to its middle shot', [ 2, 4 ] === $ids( $r['shown'] ) );

// Hiding every photo leaves nothing shown.
$r = Selection::for_step( $few, [], array_column( $few, 'id' ), 8 );
check( 'all hidden: nothing shown', [] === $r['shown'] && [] === $r['rest'] && 4 === count( $r['hidden'] ) );

check( 'empty step', [] === Selection::for_step( [], [], [], 8 )['shown'] );

exit( $failures ? 1 : 0 );
