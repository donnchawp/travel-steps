<?php
/**
 * Builds the geocoder's data from GeoNames: php tools/geonames.php cities500.txt countryInfo.txt data/geonames
 * Run by `make geonames`, which downloads the two files first.
 *
 * Writes, for src/Geocoder.php:
 * - places.tsv:    name, country code, lat, lon; grouped by 1°×1° tile, then by GeoNames id.
 * - index.dat:     Geocoder::TILES + 1 big-endian uint32 byte offsets into places.tsv; tile k
 *                  is the bytes from offset k to offset k + 1.
 * - countries.tsv: country code, English name.
 *
 * Both inputs are read in full before anything is written, so a missing one leaves
 * the data as it was.
 */
require __DIR__ . '/../src/Geocoder.php';

use TravelSteps\Geocoder;

[ , $cities, $countries, $out ] = $argv + [ null, null, null, null ];
if ( ! $out ) {
	fwrite( STDERR, "Usage: php tools/geonames.php cities500.txt countryInfo.txt <output dir>\n" );
	exit( 1 );
}
foreach ( [ $cities, $countries ] as $input ) {
	if ( ! is_readable( $input ) ) {
		fwrite( STDERR, "Can't read $input\n" );
		exit( 1 );
	}
}

$names = '';
foreach ( file( $countries, FILE_IGNORE_NEW_LINES ) as $line ) {
	$f = explode( "\t", $line );
	if ( '' === $line || '#' === $line[0] || count( $f ) < 5 || '' === trim( $f[4] ) ) {
		continue;
	}
	$names .= $f[0] . "\t" . trim( $f[4] ) . "\n";
}

$tiles = [];
foreach ( new SplFileObject( $cities ) as $line ) {
	$f = explode( "\t", rtrim( (string) $line, "\n" ) );
	if ( count( $f ) < 9 ) {
		continue;
	}
	// As Immich does: no neighbourhoods (except Australia's suburbs) and no historical places.
	if ( ( 'PPLX' === $f[7] && 'AU' !== $f[8] ) || 'PPLH' === $f[7] ) {
		continue;
	}
	// Unlike Immich, numbered arrondissements ("Paris 16 Passy", "Marseille 02") are
	// named after their city: a walk across it would otherwise be a string of places.
	// They're kept, not dropped, or the city's edges would go to its suburbs.
	$name = 'FR' === $f[8] ? preg_replace( '/^(Paris|Marseille) \d.*$/', '$1', $f[1] ) : $f[1];
	$lat  = round( (float) $f[4], 4 );
	$lon  = round( (float) $f[5], 4 );
	$tiles[ Geocoder::tile( $lat, $lon ) ][ (int) $f[0] ] = str_replace( "\t", ' ', $name ) . "\t" . $f[8] . "\t" . $lat . "\t" . $lon . "\n";
}

@mkdir( $out, 0755, true );
$data   = fopen( "$out/places.tsv", 'wb' );
$index  = '';
$offset = 0;
$rows   = 0;
for ( $k = 0; $k < Geocoder::TILES; $k++ ) {
	$index .= pack( 'N', $offset );
	if ( isset( $tiles[ $k ] ) ) {
		ksort( $tiles[ $k ] );
		$chunk   = implode( '', $tiles[ $k ] );
		$offset += strlen( $chunk );
		$rows   += count( $tiles[ $k ] );
		fwrite( $data, $chunk );
	}
}
$index .= pack( 'N', $offset );
fclose( $data );
file_put_contents( "$out/index.dat", $index );
file_put_contents( "$out/countries.tsv", $names );

echo "$rows places in " . count( $tiles ) . " tiles, $offset bytes\n";
