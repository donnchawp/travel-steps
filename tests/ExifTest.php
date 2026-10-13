<?php
/**
 * Standalone checks for reading photo metadata: php tests/ExifTest.php
 */
require __DIR__ . '/../src/Exif.php';

use TravelSteps\Exif;

$failures = 0;
function check( string $name, bool $ok, $detail = '' ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  -- ' . json_encode( $detail ) ) . "\n";
	$failures += $ok ? 0 : 1;
}

// As exif_read_data() gives it for an iPhone photo taken in Doolin.
$iphone = [
	'Make'               => 'Apple',
	'Model'              => 'iPhone 15 Pro',
	'Orientation'        => 1,
	'DateTimeOriginal'   => '2025:05:01 14:03:22',
	'OffsetTimeOriginal' => '+01:00',
	'GPSLatitudeRef'     => 'N',
	'GPSLatitude'        => [ '53/1', '1/1', '1890/100' ],
	'GPSLongitudeRef'    => 'W',
	'GPSLongitude'       => [ '9/1', '22/1', '4500/100' ],
];
$m = Exif::parse( $iphone );
check( 'capture time as the camera wrote it', '2025-05-01 14:03:22' === $m['taken'], $m );
check( 'its offset', '+01:00' === $m['offset'], $m );
check( 'latitude in degrees', abs( $m['lat'] - 53.0219167 ) < 1e-6, $m );
check( 'western longitude is negative', abs( $m['lon'] + 9.3791667 ) < 1e-6, $m );
check( 'camera make and model', 'Apple iPhone 15 Pro' === $m['camera'], $m );

$south = Exif::parse( [ 'GPSLatitudeRef' => 'S', 'GPSLatitude' => [ '33/1', '51/1', '0/1' ], 'GPSLongitudeRef' => 'E', 'GPSLongitude' => [ '151/1', '12/1', '0/1' ] ] );
check( 'southern latitude is negative, eastern longitude positive', abs( $south['lat'] + 33.85 ) < 1e-6 && abs( $south['lon'] - 151.2 ) < 1e-6, $south );

$none = Exif::parse( [] );
check( 'nothing known: empty time, offset and camera, no position', [ '', '', null, null, '' ] === [ $none['taken'], $none['offset'], $none['lat'], $none['lon'], $none['camera'] ], $none );

check( 'no offset', '' === Exif::parse( array_diff_key( $iphone, [ 'OffsetTimeOriginal' => 1 ] ) )['offset'] );
check( 'a malformed offset is ignored', '' === Exif::parse( [ 'OffsetTimeOriginal' => '1:00' ] + $iphone )['offset'] );
check( 'an empty capture time is none', '' === Exif::parse( [ 'DateTimeOriginal' => '0000:00:00 00:00:00' ] + $iphone )['taken'] );
check( 'a garbled capture time is none', '' === Exif::parse( [ 'DateTimeOriginal' => '2025:13:45 99:00:00' ] + $iphone )['taken'] );
$digitized = Exif::parse( [ 'DateTimeDigitized' => '2025:05:02 08:00:00', 'OffsetTimeDigitized' => '-04:00' ] );
check( 'without an original time, the digitised one and its offset', [ '2025-05-02 08:00:00', '-04:00' ] === [ $digitized['taken'], $digitized['offset'] ], $digitized );

$zero_den = Exif::parse( [ 'GPSLatitude' => [ '53/1', '1/0', '0/1' ] ] + $iphone );
check( 'a zero denominator means no position', null === $zero_den['lat'] && null === $zero_den['lon'], $zero_den );
$null_island = Exif::parse( [ 'GPSLatitude' => [ '0/1', '0/1', '0/1' ], 'GPSLongitude' => [ '0/1', '0/1', '0/1' ] ] + $iphone );
check( '0, 0 is a camera without a fix', null === $null_island['lat'] && null === $null_island['lon'], $null_island );
$half = Exif::parse( array_diff_key( $iphone, [ 'GPSLongitude' => 1 ] ) );
check( 'latitude without longitude is no position', null === $half['lat'] && null === $half['lon'], $half );
$out = Exif::parse( [ 'GPSLatitude' => [ '95/1', '0/1', '0/1' ] ] + $iphone );
check( 'a latitude past the pole is no position', null === $out['lat'], $out );

check( 'a model that names its make is not doubled', 'Canon EOS R5' === Exif::parse( [ 'Make' => 'Canon', 'Model' => 'Canon EOS R5' ] )['camera'] );
check( 'padding is trimmed', 'FUJIFILM X100V' === Exif::parse( [ 'Make' => "FUJIFILM \0", 'Model' => "X100V\0\0" ] )['camera'] );
check( 'a screenshot has no camera', '' === Exif::parse( [ 'DateTimeOriginal' => '2025:05:01 14:03:22' ] )['camera'] );

// Imagick's exif:* properties, for HEIC files exif_read_data() can't read.
$props = [
	'exif:Make'               => 'Apple',
	'exif:Model'              => 'iPhone 15 Pro',
	'exif:DateTimeOriginal'   => '2025:05:01 14:03:22',
	'exif:OffsetTimeOriginal' => '+01:00',
	'exif:GPSLatitudeRef'     => 'N',
	'exif:GPSLatitude'        => '53/1, 1/1, 1890/100',
	'exif:GPSLongitudeRef'    => 'W',
	'exif:GPSLongitude'       => '9/1, 22/1, 4500/100',
	'exif:Orientation'        => '1',
	'date:create'             => '2026-10-12T10:00:00+00:00',
];
check( 'Imagick’s properties read the same as exif_read_data()', Exif::parse( Exif::from_properties( $props ) ) === $m, Exif::parse( Exif::from_properties( $props ) ) );

// When a file's name says it was taken, for photos whose EXIF doesn't.
check( 'a Mac screenshot’s name: its date and time', [ '2025-03-18', '17:42' ] === Exif::from_name( 'Screenshot 2025-03-18 at 17.42.01' ) );
check( 'a Pixel photo’s name is UTC, so no time of its own', null === Exif::from_name( 'PXL_20250318_174201123' ) );
check( 'an Android camera’s IMG_ name', [ '2025-03-18', '17:42' ] === Exif::from_name( 'IMG_20250318_174201' ) );
check( 'a WhatsApp name: its date, no time', [ '2025-03-18', '' ] === Exif::from_name( 'IMG-20250318-WA0001' ) );
check( 'a camera’s counter is no date', null === Exif::from_name( 'IMG_1234' ) );
check( 'a UUID is no date', null === Exif::from_name( '0b1f5c3e-8d2a-4f6b-9c7e-1a2b3c4d5e6f' ) );
check( 'a date that doesn’t exist is none', null === Exif::from_name( 'IMG-20251318-WA0001' ) );
check( 'a time that doesn’t exist leaves the date', [ '2025-03-18', '' ] === Exif::from_name( 'IMG_20250318_256001' ) );

exit( $failures ? 1 : 0 );
