<?php

namespace TravelSteps;

/**
 * What a photo's EXIF says about when and where it was taken, and with what. No
 * WordPress dependencies so it can be tested standalone.
 */
class Exif {
	/**
	 * @param array $exif  Tags by name, as exif_read_data() gives them (or from_properties()).
	 * @param int   $width The image's stored width and height, before rotation.
	 * @return array{taken: string, offset: string, lat: ?float, lon: ?float, camera: string, w: int, h: int}
	 *         taken is the camera's wall-clock time, Y-m-d H:i:s, or empty; offset is
	 *         its UTC offset, such as +01:00, or empty if the camera didn't record one;
	 *         w and h are as displayed.
	 */
	public static function parse( array $exif, int $width, int $height ): array {
		$kind  = isset( $exif['DateTimeOriginal'] ) ? 'Original' : 'Digitized';
		$taken = self::time( $exif[ 'DateTime' . $kind ] ?? '' );
		$lat   = self::coordinate( $exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? '', 90 );
		$lon   = self::coordinate( $exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? '', 180 );
		// Cameras without a fix write 0, 0.
		if ( null === $lat || null === $lon || ( 0.0 === $lat && 0.0 === $lon ) ) {
			$lat = null;
			$lon = null;
		}
		$offset = self::text( $exif[ 'OffsetTime' . $kind ] ?? '' );
		$make   = self::text( $exif['Make'] ?? '' );
		$model  = self::text( $exif['Model'] ?? '' );
		// Orientations 5 to 8 turn the photo on its side.
		if ( in_array( (int) ( $exif['Orientation'] ?? 1 ), [ 5, 6, 7, 8 ], true ) ) {
			[ $width, $height ] = [ $height, $width ];
		}
		return [
			'taken'  => $taken,
			'offset' => '' !== $taken && preg_match( '/^[+-]\d\d:\d\d$/', $offset ) ? $offset : '',
			'lat'    => $lat,
			'lon'    => $lon,
			'camera' => '' !== $make && 0 !== stripos( $model, $make ) ? trim( "$make $model" ) : $model,
			'w'      => $width,
			'h'      => $height,
		];
	}

	/**
	 * A photo's EXIF tags: from exif_read_data(), or from Imagick for a file it finds
	 * nothing useful in (such as HEIC, on PHP versions that can't read it).
	 */
	public static function read( string $file ): array {
		$exif = function_exists( 'exif_read_data' ) ? @exif_read_data( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warns on any malformed tag.
		$tags = [ 'DateTimeOriginal', 'DateTimeDigitized', 'GPSLatitude', 'Make', 'Model' ];
		if ( is_array( $exif ) && array_intersect_key( $exif, array_flip( $tags ) ) ) {
			return $exif;
		}
		return self::imagick( $file ) ?: ( is_array( $exif ) ? $exif : [] );
	}

	public static function imagick( string $file ): array {
		if ( ! class_exists( 'Imagick', false ) ) {
			return [];
		}
		try {
			$image = new \Imagick();
			$image->pingImage( $file );
			return self::from_properties( $image->getImageProperties( 'exif:*' ) );
		} catch ( \Exception $e ) {
			return [];
		}
	}

	/**
	 * Imagick's exif:* image properties as exif_read_data() would name them.
	 */
	public static function from_properties( array $properties ): array {
		$exif = [];
		foreach ( $properties as $name => $value ) {
			if ( 0 === strpos( $name, 'exif:' ) ) {
				$exif[ substr( $name, 5 ) ] = $value;
			}
		}
		return $exif;
	}

	private static function text( $value ): string {
		return is_string( $value ) ? trim( $value, " \0" ) : '';
	}

	private static function time( $value ): string {
		$time = \DateTimeImmutable::createFromFormat( '!Y:m:d H:i:s', self::text( $value ) );
		// Rolled-over dates (month 13, 99 o'clock) and all-zero ones are no time.
		return $time && $time->format( 'Y:m:d H:i:s' ) === self::text( $value ) && (int) $time->format( 'Y' ) > 0
			? $time->format( 'Y-m-d H:i:s' )
			: '';
	}

	/**
	 * Degrees from EXIF's degrees, minutes and seconds (rationals such as "1890/100",
	 * or one comma-separated string of them), negative to the south and west.
	 */
	private static function coordinate( $dms, $ref, int $max ): ?float {
		$parts = is_string( $dms ) ? explode( ',', $dms ) : $dms;
		if ( ! is_array( $parts ) || 3 !== count( $parts ) ) {
			return null;
		}
		$degrees = 0.0;
		foreach ( array_values( $parts ) as $i => $part ) {
			$fraction = explode( '/', trim( (string) $part ) );
			$num      = $fraction[0];
			$den      = $fraction[1] ?? '1';
			if ( 2 < count( $fraction ) || ! is_numeric( $num ) || ! is_numeric( $den ) || 0.0 === (float) $den ) {
				return null;
			}
			$degrees += $num / $den / 60 ** $i;
		}
		$degrees *= in_array( self::text( $ref ), [ 'S', 'W' ], true ) ? -1 : 1;
		return abs( $degrees ) <= $max ? $degrees : null;
	}
}
