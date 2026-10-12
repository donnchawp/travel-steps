<?php

namespace TravelSteps;

/**
 * Names the town nearest a point, offline, from GeoNames' cities500 (CC BY 4.0) in
 * data/geonames; `make geonames` rebuilds it. No WordPress dependencies.
 *
 * Places are kept in 1°×1° tiles. A lookup reads only the tiles within MAX_DISTANCE_M
 * of the point, and each tile is read once per Geocoder, so keep one for a whole sync.
 */
class Geocoder {
	const TILES = 180 * 360;
	// As Immich: a photo farther than this from any town gets no place name.
	const MAX_DISTANCE_M = 25000;

	private string $dir;
	/** @var array<int, string> Each tile's rows, as stored. */
	private array $tiles = [];
	/** @var array<string, string>|null */
	private ?array $countries = null;
	/** @var resource|false|null */
	private $index = null;
	/** @var resource|false|null */
	private $places = null;

	public function __construct( ?string $dir = null ) {
		$this->dir = $dir ?? dirname( __DIR__ ) . '/data/geonames';
	}

	/**
	 * Whether the data files are there. Without them every point is unnamed, like open
	 * sea, so a broken deploy would otherwise go unnoticed.
	 */
	public function available(): bool {
		foreach ( [ 'index.dat', 'places.tsv', 'countries.tsv' ] as $file ) {
			if ( ! is_readable( $this->dir . '/' . $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The nearest town and its country, or empty strings if there's none within
	 * MAX_DISTANCE_M, or the point isn't one (EXIF can hold NaN, from 0/0).
	 *
	 * @return array{city: string, country: string}
	 */
	public function nearest( float $lat, float $lon ): array {
		if ( ! is_finite( $lat ) || ! is_finite( $lon ) || abs( $lat ) > 90 || abs( $lon ) > 180 ) {
			return [ 'city' => '', 'country' => '' ];
		}
		$best   = [ 'city' => '', 'code' => '' ];
		$best_m = self::MAX_DISTANCE_M;
		foreach ( self::tiles_near( $lat, $lon ) as $tile ) {
			$rows = $this->rows( $tile );
			if ( '' === $rows ) {
				continue;
			}
			foreach ( explode( "\n", rtrim( $rows, "\n" ) ) as $row ) {
				[ $name, $code, $place_lat, $place_lon ] = explode( "\t", $row );
				$m = Geo::distance( $lat, $lon, (float) $place_lat, (float) $place_lon );
				if ( $m <= $best_m ) {
					$best   = [ 'city' => $name, 'code' => $code ];
					$best_m = $m;
				}
			}
		}
		return [ 'city' => $best['city'], 'country' => $this->countries()[ $best['code'] ] ?? '' ];
	}

	/**
	 * The tile a point is in: rows of 360 from the south pole, columns east from 180°W.
	 */
	public static function tile( float $lat, float $lon ): int {
		return self::row( (int) floor( $lat ) ) * 360 + self::col( (int) floor( $lon ) );
	}

	private static function row( int $degree ): int {
		return min( 179, max( 0, $degree + 90 ) );
	}

	private static function col( int $degree ): int {
		return ( ( $degree + 180 ) % 360 + 360 ) % 360;
	}

	/**
	 * Every tile with ground within MAX_DISTANCE_M of a point, across the ±180° line
	 * if need be, and all the way round near a pole. For a real point: |lat| ≤ 90.
	 *
	 * @return int[]
	 */
	public static function tiles_near( float $lat, float $lon ): array {
		$angle = self::MAX_DISTANCE_M / Geo::EARTH_RADIUS_M;
		$d_lat = rad2deg( $angle );
		$south = max( -90, $lat - $d_lat );
		$north = min( 90, $lat + $d_lat );
		// The widest longitude a great circle reaches within that distance; near a pole
		// (where the circle takes in the pole itself), all of them.
		$sin   = sin( $angle ) / cos( deg2rad( $lat ) );
		$d_lon = $sin >= 1 ? 180 : rad2deg( asin( $sin ) );

		$cols = [];
		for ( $c = (int) floor( $lon - $d_lon ); $c <= (int) floor( $lon + $d_lon ); $c++ ) {
			$cols[ self::col( $c ) ] = true;
		}
		$tiles = [];
		for ( $r = self::row( (int) floor( $south ) ); $r <= self::row( (int) floor( $north ) ); $r++ ) {
			foreach ( array_keys( $cols ) as $col ) {
				$tiles[] = $r * 360 + $col;
			}
		}
		return $tiles;
	}

	private function rows( int $tile ): string {
		if ( isset( $this->tiles[ $tile ] ) ) {
			return $this->tiles[ $tile ];
		}
		$this->index  ??= @fopen( $this->dir . '/index.dat', 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$this->places ??= @fopen( $this->dir . '/places.tsv', 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$rows = '';
		if ( $this->index && $this->places && 0 === fseek( $this->index, 4 * $tile ) ) {
			$range = unpack( 'Nstart/Nend', (string) fread( $this->index, 8 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( $range && $range['end'] > $range['start'] && 0 === fseek( $this->places, $range['start'] ) ) {
				$rows = (string) fread( $this->places, $range['end'] - $range['start'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			}
		}
		return $this->tiles[ $tile ] = $rows;
	}

	/**
	 * @return array<string, string> English names by ISO 3166 code.
	 */
	private function countries(): array {
		if ( null === $this->countries ) {
			$this->countries = [];
			foreach ( (array) @file( $this->dir . '/countries.tsv', FILE_IGNORE_NEW_LINES ) as $line ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				[ $code, $name ] = explode( "\t", $line ) + [ '', '' ];
				$this->countries[ $code ] = $name;
			}
		}
		return $this->countries;
	}
}
