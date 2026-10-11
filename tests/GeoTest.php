<?php
/**
 * Standalone checks for the geometry helpers: php tests/GeoTest.php
 */
require __DIR__ . '/../src/Geo.php';

use TravelSteps\Geo;

$failures = 0;
function check( string $name, bool $ok ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	$failures += $ok ? 0 : 1;
}

// Dublin -> Galway is ~186 km as the crow flies.
$d = Geo::distance( 53.3498, -6.2603, 53.2707, -9.0568 );
check( 'distance Dublin-Galway ~186km', abs( $d - 186000 ) < 3000 );

// Simplify: a straight line collapses to its endpoints; a corner survives.
$line = [];
for ( $i = 0; $i <= 100; $i++ ) { $line[] = [ 53.0 + $i * 0.001, -6.0, $i ]; }
check( 'straight line simplifies to 2 points', 2 === count( Geo::simplify( $line, 10 ) ) );
$corner = array_merge( $line, [ [ 53.1, -5.9, 101 ], [ 53.1, -5.8, 102 ] ] );
check( 'corner kept', 3 === count( Geo::simplify( $corner, 10 ) ) );

// Split at a flight-sized gap.
$flight = [ [ 53.42, -6.27, 0 ], [ 53.43, -6.26, 60 ], [ 40.47, -3.56, 9000 ], [ 40.46, -3.57, 9060 ] ];
check( 'flight splits route into 2 segments', 2 === count( Geo::split_segments( $flight ) ) );
$len = Geo::track_length( $flight );
check( 'track length counts the flight (Dublin-Madrid ~1450km)', $len > 1400000 && $len < 1500000 );
$glitch = [ [ 53.42, -6.27, 0 ], [ 53.43, -6.26, 60 ], [ 51.50, -0.12, 90 ], [ 53.44, -6.25, 120 ] ];
$len    = Geo::track_length( $glitch );
check( 'track length skips a fix faster than a plane (Dublin-London in 30s)', $len < 5000 );

// Nearest in time.
check( 'nearest_in_time finds closest', 1 === Geo::nearest_in_time( $flight, 70, 3600 ) );
check( 'nearest_in_time respects max gap', null === Geo::nearest_in_time( $flight, 5000, 600 ) );
check( 'nearest_in_time on empty track', null === Geo::nearest_in_time( [], 5, 600 ) );

// Home exclusion.
$home = [ 'lat' => 53.42, 'lon' => -6.27, 'radius_m' => 2000 ];
check( 'points near home excluded', 2 === count( Geo::exclude_near( $flight, $home ) ) );

// Big track performance: 100k points.
$big = []; $t = 0;
for ( $i = 0; $i < 100000; $i++ ) { $big[] = [ 50 + sin( $i / 500 ) * 0.5, 5 + $i * 0.00002, $t += 30 ]; }
$start = microtime( true );
$s     = Geo::simplify( $big, 25 );
printf( "     100k points: simplify -> %d pts, %.2fs\n", count( $s ), microtime( true ) - $start );

exit( $failures ? 1 : 0 );
