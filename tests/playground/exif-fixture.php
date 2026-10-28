<?php
/**
 * Test photos: a JPEG with an EXIF block written into it, as a phone or camera would.
 */

/**
 * @param array $tags Any of make, model, taken ("Y:m:d H:i:s"), offset ("+01:00"),
 *                    lat and lon (degrees), orientation, and xmp_lat and xmp_lon for
 *                    a position written only in XMP, as photo editors do.
 */
function travel_steps_tagged_jpeg( string $jpeg, array $tags ): string {
	if ( isset( $tags['xmp_lat'], $tags['xmp_lon'] ) ) {
		$xmp  = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="' . $tags['xmp_lat'] . '" exif:GPSLongitude="' . $tags['xmp_lon'] . '"/>'
			. '</rdf:RDF></x:xmpmeta>';
		$jpeg = travel_steps_app1( $jpeg, "http://ns.adobe.com/xap/1.0/\0" . $xmp );
	}
	$ifd0 = [];
	$exif = [];
	$gps  = [];
	foreach ( [ 'make' => 0x010F, 'model' => 0x0110 ] as $key => $tag ) {
		if ( isset( $tags[ $key ] ) ) {
			$ifd0[ $tag ] = [ 2, $tags[ $key ] ];
		}
	}
	if ( isset( $tags['orientation'] ) ) {
		$ifd0[0x0112] = [ 3, $tags['orientation'] ];
	}
	if ( isset( $tags['taken'] ) ) {
		$exif[0x9003] = [ 2, $tags['taken'] ];
	}
	if ( isset( $tags['offset'] ) ) {
		$exif[0x9011] = [ 2, $tags['offset'] ];
	}
	if ( isset( $tags['lat'], $tags['lon'] ) ) {
		$gps = [
			0x0001 => [ 2, $tags['lat'] < 0 ? 'S' : 'N' ],
			0x0002 => [ 5, travel_steps_dms( $tags['lat'] ) ],
			0x0003 => [ 2, $tags['lon'] < 0 ? 'W' : 'E' ],
			0x0004 => [ 5, travel_steps_dms( $tags['lon'] ) ],
		];
	}
	if ( ! $ifd0 && ! $exif && ! $gps ) {
		return $jpeg;
	}
	// The pointers to the Exif and GPS folders are a fixed size, so lay out IFD0 with
	// placeholders first to find where they go.
	if ( $exif ) {
		$ifd0[0x8769] = [ 4, 0 ];
	}
	if ( $gps ) {
		$ifd0[0x8825] = [ 4, 0 ];
	}
	$exif_at = 8 + strlen( travel_steps_ifd( $ifd0, 8 ) );
	$exif_ifd = $exif ? travel_steps_ifd( $exif, $exif_at ) : '';
	$gps_at   = $exif_at + strlen( $exif_ifd );
	if ( $exif ) {
		$ifd0[0x8769] = [ 4, $exif_at ];
	}
	if ( $gps ) {
		$ifd0[0x8825] = [ 4, $gps_at ];
	}
	$tiff = "II*\0" . pack( 'V', 8 ) . travel_steps_ifd( $ifd0, 8 ) . $exif_ifd . ( $gps ? travel_steps_ifd( $gps, $gps_at ) : '' );
	return travel_steps_app1( $jpeg, "Exif\0\0" . $tiff );
}

/**
 * A JPEG with an APP1 segment (where EXIF and XMP go) added after its start marker.
 */
function travel_steps_app1( string $jpeg, string $payload ): string {
	return substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . substr( $jpeg, 2 );
}

/**
 * One little-endian TIFF folder of [ tag => [ type, value ] ] at $offset in the TIFF
 * block, followed by the values that don't fit in their entry. Types: 2 text, 3 short,
 * 4 long, 5 a list of [ numerator, denominator ].
 */
function travel_steps_ifd( array $entries, int $offset ): string {
	ksort( $entries );
	$data_at = $offset + 2 + 12 * count( $entries ) + 4;
	$head    = pack( 'v', count( $entries ) );
	$data    = '';
	foreach ( $entries as $tag => [ $type, $value ] ) {
		switch ( $type ) {
			case 2:
				$bytes = $value . "\0";
				$count = strlen( $bytes );
				break;
			case 3:
				$bytes = pack( 'v', $value );
				$count = 1;
				break;
			case 4:
				$bytes = pack( 'V', $value );
				$count = 1;
				break;
			default:
				$bytes = implode( '', array_map( fn( $r ) => pack( 'VV', $r[0], $r[1] ), $value ) );
				$count = count( $value );
		}
		if ( strlen( $bytes ) <= 4 ) {
			$field = str_pad( $bytes, 4, "\0" );
		} else {
			$field = pack( 'V', $data_at + strlen( $data ) );
			$data .= $bytes . ( strlen( $bytes ) % 2 ? "\0" : '' );
		}
		$head .= pack( 'vvV', $tag, $type, $count ) . $field;
	}
	return $head . pack( 'V', 0 ) . $data;
}

function travel_steps_dms( float $degrees ): array {
	$degrees = abs( $degrees );
	$d       = (int) floor( $degrees );
	$m       = (int) floor( ( $degrees - $d ) * 60 );
	$s       = (int) round( ( ( $degrees - $d ) * 60 - $m ) * 60 * 100 );
	return [ [ $d, 1 ], [ $m, 1 ], [ $s, 100 ] ];
}
