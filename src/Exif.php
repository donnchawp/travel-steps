<?php

namespace TravelSteps;

/**
 * What a photo's EXIF says about when and where it was taken, and with what, and
 * what its file name says when that's all there is. No WordPress dependencies so it
 * can be tested standalone.
 */
class Exif {
	/**
	 * The photo's size isn't here: WordPress's attachment metadata has it as displayed,
	 * once core has turned the copies (or the decoder has, for HEIF).
	 *
	 * @param array $exif Tags by name, as exif_read_data() gives them (or from_properties()).
	 * @return array{taken: string, offset: string, lat: ?float, lon: ?float, camera: string}
	 *         taken is the camera's wall-clock time, Y-m-d H:i:s, or empty; offset is
	 *         its UTC offset, such as +01:00, or empty if the camera didn't record one.
	 */
	public static function parse( array $exif ): array {
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
		return [
			'taken'  => $taken,
			'offset' => '' !== $taken && preg_match( '/^[+-]\d\d:\d\d$/', $offset ) ? $offset : '',
			'lat'    => $lat,
			'lon'    => $lon,
			'camera' => '' !== $make && 0 !== stripos( $model, $make ) ? trim( "$make $model" ) : $model,
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

	/**
	 * When a file's name says it was taken: a Mac screenshot's, an Android camera's
	 * (PXL_ or IMG_ and the date and time) or WhatsApp's (IMG- and the date only).
	 *
	 * @param string $name The name it was uploaded with, without its extension.
	 * @return array{0: string, 1: string}|null The date (Y-m-d) and time (H:i, or empty
	 *         if the name has none), or null if the name has no date.
	 */
	public static function from_name( string $name ): ?array {
		$patterns = [
			'/^Screenshot (\d{4})-(\d\d)-(\d\d) at (\d\d)\.(\d\d)\.\d\d/',
			'/^(?:PXL|IMG)_(\d{4})(\d\d)(\d\d)_(\d\d)(\d\d)\d\d/',
			'/^IMG-(\d{4})(\d\d)(\d\d)-WA\d+/',
		];
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $name, $m ) ) {
				continue;
			}
			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return null;
			}
			$time = isset( $m[5] ) && (int) $m[4] < 24 && (int) $m[5] < 60 ? "$m[4]:$m[5]" : '';
			return [ "$m[1]-$m[2]-$m[3]", $time ];
		}
		return null;
	}

	private static function text( $value ): string {
		return is_string( $value ) ? trim( $value, " \0" ) : '';
	}

	private static function time( $value ): string {
		$text = self::text( $value );
		$time = \DateTimeImmutable::createFromFormat( '!Y:m:d H:i:s', $text );
		// Rolled-over dates (month 13, 99 o'clock) and all-zero ones are no time.
		return $time && $time->format( 'Y:m:d H:i:s' ) === $text && (int) $time->format( 'Y' ) > 0
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
