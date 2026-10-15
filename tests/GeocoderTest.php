<?php
/**
 * Standalone checks for the offline geocoder, against the bundled data: php tests/GeocoderTest.php
 */
require __DIR__ . '/../src/Geo.php';
require __DIR__ . '/../src/Geocoder.php';

use TravelSteps\Geocoder;

$failures = 0;
function check( string $name, bool $ok, $detail = '' ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  -- ' . json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) . "\n";
	$failures += $ok ? 0 : 1;
}

$geo = new Geocoder();
$r   = $geo->nearest( 53.0158, -9.3769 );
check( 'Doolin is nearest Lisdoonvarna', [ 'city' => 'Lisdoonvarna', 'country' => 'Ireland' ] === $r, $r );
$r = $geo->nearest( 52.1409, -10.2689 );
check( 'Dingle is Dingle', [ 'city' => 'Dingle', 'country' => 'Ireland' ] === $r, $r );
$r = $geo->nearest( 66.3232, -179.1184 );
check( 'a town just east of 180° is found', [ 'city' => 'Egvekinot', 'country' => 'Russia' ] === $r, $r );
$r = $geo->nearest( 48.8566, 2.3522 );
check( 'central Paris is Paris, not an arrondissement', [ 'city' => 'Paris', 'country' => 'France' ] === $r, $r );
$r = $geo->nearest( 43.2965, 5.3698 );
check( 'central Marseille is Marseille', [ 'city' => 'Marseille', 'country' => 'France' ] === $r, $r );
// Far from the city's centre, but in the city: not named after a suburb next door.
$r = array_column( [ $geo->nearest( 48.8584, 2.2945 ), $geo->nearest( 48.8867, 2.3431 ), $geo->nearest( 48.8614, 2.3933 ), $geo->nearest( 43.3606, 5.3122 ) ], 'city' );
check( 'the Eiffel Tower, Sacré-Cœur, Père Lachaise and L’Estaque are in Paris and Marseille', [ 'Paris', 'Paris', 'Paris', 'Marseille' ] === $r, $r );
$none = [ 'city' => '', 'country' => '' ];
$r    = $geo->nearest( 0.0, -30.0 );
check( 'mid-Atlantic has no name', $none === $r, $r );
$r = [ $geo->nearest( 10.0, 180.0 ), $geo->nearest( 10.0, -180.0 ), $geo->nearest( 90.0, 0.0 ), $geo->nearest( -90.0, 45.0 ) ];
check( 'the 180° line and the poles are quiet', [ $none, $none, $none, $none ] === $r, $r );
$r = [ $geo->nearest( NAN, 0.0 ), $geo->nearest( 0.0, INF ), $geo->nearest( 95.0, 0.0 ), $geo->nearest( 53.0, 350.0 ) ];
check( 'NaN, infinity and out-of-range points have no name', [ $none, $none, $none, $none ] === $r, $r );

// A lookup reads only the tiles around the point.
$tiles = Geocoder::tiles_near( 53.0158, -9.3769 );
check( 'Doolin needs at most 4 tiles, including its own', count( $tiles ) <= 4 && in_array( Geocoder::tile( 53.0158, -9.3769 ), $tiles, true ), $tiles );
$tiles = Geocoder::tiles_near( 70.5, 25.5 );
check( 'tiles further north are wider, so a few more', count( $tiles ) <= 6, $tiles );
$tiles = Geocoder::tiles_near( 66.3, 179.99 );
check( 'tiles wrap across 180°', in_array( Geocoder::tile( 66.3, 179.5 ), $tiles, true ) && in_array( Geocoder::tile( 66.3, -179.5 ), $tiles, true ), $tiles );
check( 'a tile at the pole goes all the way round', 360 === count( Geocoder::tiles_near( 89.9, 0 ) ) );
check( 'lon 180 is lon -180', Geocoder::tile( 10, 180 ) === Geocoder::tile( 10, -180 ) );

// Across the 180° line, from a tiny dataset built the way the real one is.
$dir = sys_get_temp_dir() . '/ts-geocoder-' . getmypid();
@mkdir( $dir );
$row = fn( $id, $name, $lat, $lon, $feature = 'PPL' ) => implode( "\t", [ $id, $name, $name, '', $lat, $lon, 'P', $feature, 'FJ', '', '', '', '', '', 600, '', '', 'Pacific/Fiji', '2025-01-01' ] ) . "\n";
// The suburb is a neighbourhood and the old town historical: both are left out.
file_put_contents( "$dir/cities.txt", $row( 1, 'West', -16.8, 179.95 ) . $row( 2, 'Suburb', -16.8, -179.97, 'PPLX' ) . $row( 3, 'Old town', -16.8, -179.96, 'PPLH' ) );
file_put_contents( "$dir/countries.txt", "#ISO\tISO3\tISO-Numeric\tfips\tCountry\nFJ\tFJI\t242\tFJ\tFiji\n" );
exec( PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/../tools/geonames.php' ) . ' ' . escapeshellarg( "$dir/cities.txt" ) . ' ' . escapeshellarg( "$dir/countries.txt" ) . ' ' . escapeshellarg( $dir ), $output, $status );
$r = ( new Geocoder( $dir ) )->nearest( -16.8, -179.98 );
check( 'the nearest town can be across 180°', 0 === $status && [ 'city' => 'West', 'country' => 'Fiji' ] === $r, [ $status, $output, $r ] );
array_map( 'unlink', glob( "$dir/*" ) );
rmdir( $dir );

// A trip's worth of lookups: tiles are kept, and memory stays small.
$geo = new Geocoder();
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage(); // So an earlier peak can't hide what the lookups use.
}
$before = memory_get_usage();
$start  = microtime( true );
$named  = 0;
for ( $i = 0; $i < 250; $i++ ) {
	// Back and forth across Ireland, Galway to Dublin and Kerry to Donegal.
	$named += '' !== $geo->nearest( 51.6 + 3.7 * ( $i % 50 ) / 50, -10.2 + 4.2 * ( $i % 7 ) / 7 )['city'] ? 1 : 0;
}
$used = memory_get_peak_usage() - $before;
check( '250 lookups across Ireland find towns', $named > 200, $named );
check( '…and use under 8 MB', $used < 8 * 1024 * 1024, $used );
printf( "     (%.2f s, %.1f MB)\n", microtime( true ) - $start, $used / 1048576 );

// Countries are counted by ISO code, however a source names them.
$geo = new Geocoder();
$r   = array_map( [ $geo, 'country_key' ], [ 'United States of America', 'United States', 'US', 'us' ] );
check( 'Immich’s and GeoNames’ names and the code, in any case, are all US', [ 'US', 'US', 'US', 'US' ] === $r, $r );
$r = array_map( [ $geo, 'country_key' ], [ 'Ireland', '  ireland ', 'NEW ZEALAND', ' united states of america', "Côte d'Ivoire", 'CÔTE D’IVOIRE' ] );
check( 'names and other spellings match in any case, with spaces around them', [ 'IE', 'IE', 'NZ', 'US', 'CI', 'CI' ] === $r, $r );
$r = array_map( [ $geo, 'country_key' ], [ 'Atlantis', 'ATLANTIS ', '', '  ' ] );
check( 'an unknown name is kept, case-folded, and an empty one is nothing', [ 'atlantis', 'atlantis', '', '' ] === $r, $r );
// A broken deploy, without the data files, still counts sensibly.
$r = array_map( [ new Geocoder( __DIR__ . '/no-such-dir' ), 'country_key' ], [ 'us', 'United States of America', 'UK', 'Ireland', ' IRELAND', '' ] );
check( 'without the data, codes and other spellings still resolve and names are case-folded', [ 'US', 'US', 'GB', 'ireland', 'ireland', '' ] === $r, $r );

check( 'the bundled data is available', ( new Geocoder() )->available() );
check( 'missing data is reported, not mistaken for open sea', ! ( new Geocoder( __DIR__ . '/no-such-dir' ) )->available() );

exit( $failures ? 1 : 0 );
