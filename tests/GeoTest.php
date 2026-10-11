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

// Synthetic day: 3h in Dublin (jittering), a drive west, 4h in Galway, 20 min at a petrol stop.
$t      = 1700000000;
$points = [];
mt_srand( 1 );
$jitter = fn() => ( mt_rand( -100, 100 ) / 100000 ); // up to ~110 m.
for ( $i = 0; $i < 36; $i++ ) { $points[] = [ 53.3498 + $jitter(), -6.2603 + $jitter(), $t ]; $t += 300; }
for ( $i = 1; $i < 40; $i++ ) { $points[] = [ 53.3498 - 0.002 * $i, -6.2603 - 0.07 * $i, $t ]; $t += 60; } // driving.
for ( $i = 0; $i < 4; $i++ ) { $points[] = [ 53.27 + $jitter(), -9.0 + $jitter(), $t ]; $t += 300; }   // short stop.
for ( $i = 0; $i < 48; $i++ ) { $points[] = [ 53.2707 + $jitter(), -9.0568 + $jitter(), $t ]; $t += 300; }

$stays = Geo::detect_stays( $points, 300, 90 * 60 );
check( 'two stays detected (petrol stop ignored)', 2 === count( $stays ) );
check( 'first stay is Dublin', isset( $stays[0] ) && Geo::distance( $stays[0]['lat'], $stays[0]['lon'], 53.3498, -6.2603 ) < 100 );
check( 'second stay is Galway', isset( $stays[1] ) && Geo::distance( $stays[1]['lat'], $stays[1]['lon'], 53.2707, -9.0568 ) < 100 );

// Hotel, short wander inside the radius-breaking zone, back to hotel: merged into one stay.
$t = 1700000000; $hotel = [];
for ( $i = 0; $i < 30; $i++ ) { $hotel[] = [ 48.8566, 2.3522, $t ]; $t += 300; }
$hotel[] = [ 48.8606, 2.3376, $t ]; $t += 300; // ~1.1 km away, one fix.
for ( $i = 0; $i < 30; $i++ ) { $hotel[] = [ 48.8566, 2.3522, $t ]; $t += 300; }
check( 'stays at the same place merge', 1 === count( Geo::detect_stays( $hotel, 300, 90 * 60 ) ) );

// Simplify: a straight line collapses to its endpoints; a corner survives.
$line = [];
for ( $i = 0; $i <= 100; $i++ ) { $line[] = [ 53.0 + $i * 0.001, -6.0, $i ]; }
check( 'straight line simplifies to 2 points', 2 === count( Geo::simplify( $line, 10 ) ) );
$corner = array_merge( $line, [ [ 53.1, -5.9, 101 ], [ 53.1, -5.8, 102 ] ] );
check( 'corner kept', 3 === count( Geo::simplify( $corner, 10 ) ) );

// Split at a flight-sized gap.
$flight = [ [ 53.42, -6.27, 0 ], [ 53.43, -6.26, 60 ], [ 40.47, -3.56, 9000 ], [ 40.46, -3.57, 9060 ] ];
check( 'flight splits route into 2 segments', 2 === count( Geo::split_segments( $flight ) ) );

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
$stays = Geo::detect_stays( $big, 300, 5400 );
printf( "     100k points: simplify -> %d pts, stays -> %d, %.2fs\n", count( $s ), count( $stays ), microtime( true ) - $start );

exit( $failures ? 1 : 0 );
