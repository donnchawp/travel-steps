<?php
/**
 * Standalone checks for day steps and places: php tests/DaysTest.php
 */
require __DIR__ . '/../src/Geo.php';
require __DIR__ . '/../src/Days.php';

use TravelSteps\Days;

$failures = 0;
function check( string $name, bool $ok ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	$failures += $ok ? 0 : 1;
}
function fav( int $t, ?float $lat, ?float $lon, string $city = '' ): array {
	return [ 'id' => 'p' . $t, 't' => $t, 'lat' => $lat, 'lon' => $lon, 'city' => $city, 'fav' => true ];
}

$dublin = new DateTimeZone( 'Europe/Dublin' );

// Local day: Immich's localDateTime is wall-clock time written as if it were UTC.
check( 'local day from localDateTime', '2025-05-01' === Days::local_day( '2025-05-01T23:30:00.000Z', 1746142200, $dublin ) );
// 2025-05-01 23:30 UTC is already 2 May in Dublin (IST, UTC+1).
check( 'local day falls back to the site timezone', '2025-05-02' === Days::local_day( null, gmmktime( 23, 30, 0, 5, 1, 2025 ), $dublin ) );
check( 'empty localDateTime falls back too', '2025-05-02' === Days::local_day( '', gmmktime( 23, 30, 0, 5, 1, 2025 ), $dublin ) );

// Photo times: Immich stores a photo with no time zone (a camera without GPS or an
// offset tag) as its wall-clock time written as UTC, so its instant is off by the local
// offset. Borrow the offset from the nearest photo that has a zone, else the site's.
function shot( string $id, string $local, ?int $offset_h ): array {
	$wall = strtotime( $local . ' UTC' );
	return [ 'id' => $id, 't' => null === $offset_h ? $wall : $wall - $offset_h * 3600, 'local' => $wall, 'zoned' => null !== $offset_h ];
}
$tokyo = Days::resolve_times(
	[
		shot( 'phone', '2025-05-01 09:00', 9 ),     // Phone with GPS: Immich knows it's Tokyo.
		shot( 'camera', '2025-05-01 10:00', null ), // Camera, no zone.
	],
	$dublin
);
$by_id = array_column( $tokyo, null, 'id' );
check( 'zoned photo keeps its time', gmmktime( 0, 0, 0, 5, 1, 2025 ) === $by_id['phone']['t'] );
check( 'unzoned photo borrows the nearest zoned offset', gmmktime( 1, 0, 0, 5, 1, 2025 ) === $by_id['camera']['t'] );
check( 'helper fields are dropped', ! isset( $by_id['camera']['local'] ) && ! isset( $by_id['camera']['zoned'] ) );

// Two zones in one trip: each camera shot takes the offset of the closest phone shot.
$flight = array_column(
	Days::resolve_times(
		[
			shot( 'p1', '2025-05-01 09:00', 1 ),  // Lisbon (WEST, UTC+1).
			shot( 'c1', '2025-05-01 11:00', null ),
			shot( 'p2', '2025-05-02 20:00', 9 ),  // Tokyo.
			shot( 'c2', '2025-05-02 19:00', null ),
		],
		$dublin
	),
	null,
	'id'
);
check( 'camera in Lisbon uses the Lisbon offset', gmmktime( 10, 0, 0, 5, 1, 2025 ) === $flight['c1']['t'] );
check( 'camera in Tokyo uses the Tokyo offset', gmmktime( 10, 0, 0, 5, 2, 2025 ) === $flight['c2']['t'] );

// No zoned photo near enough: the site time zone, DST included (Dublin is UTC+1 in May).
$alone = Days::resolve_times( [ shot( 'c', '2025-05-01 10:00', null ) ], $dublin );
check( 'unzoned photo alone uses the site zone', gmmktime( 9, 0, 0, 5, 1, 2025 ) === $alone[0]['t'] );
$far = array_column( Days::resolve_times( [ shot( 'p', '2025-05-01 09:00', 9 ), shot( 'c', '2025-05-05 10:00', null ) ], $dublin ), null, 'id' );
check( 'a zoned photo days away is ignored', gmmktime( 9, 0, 0, 5, 5, 2025 ) === $far['c']['t'] );

// Day numbers are calendar arithmetic, so DST changes don't shift them.
check( 'first day is day 1', 1 === Days::day_number( '2025-03-29', '2025-03-29' ) );
check( 'day after the clocks change is day 3', 3 === Days::day_number( '2025-03-29', '2025-03-31' ) );
check( 'day before the trip is 0', 0 === Days::day_number( '2025-03-29', '2025-03-28' ) );

// Places: Lisbon in the morning, Sintra (~20 km) in the afternoon, back to Lisbon.
$t      = 1746090000;
$places = Days::places(
	[
		fav( $t, 38.7139, -9.1394, 'Lisbon' ),
		fav( $t + 1800, 38.6972, -9.2063, 'Lisbon' ),  // Belém, ~6 km away but still Lisbon.
		fav( $t + 3600, null, null ),                  // No position: joins the current place.
		fav( $t + 7200, 38.7979, -9.3880, 'Sintra' ),
		fav( $t + 9000, 38.7876, -9.3904, 'Sintra' ),
		fav( $t + 18000, 38.7110, -9.1366, 'Lisbon' ),
	],
	2000
);
check( 'three places: Lisbon, Sintra, Lisbon', [ 'Lisbon', 'Sintra', 'Lisbon' ] === array_column( $places, 'city' ) );
check( 'a place starts at its first favourite', [ $t, $t + 7200, $t + 18000 ] === array_column( $places, 't' ) );
check( 'place position is the centroid of its photos', abs( $places[1]['lat'] - 38.79275 ) < 0.0001 );

// Without city names, distance alone splits places.
$unnamed = Days::places( [ fav( $t, 38.7139, -9.1394 ), fav( $t + 60, 38.7150, -9.1400 ), fav( $t + 7200, 38.7979, -9.3880 ) ], 2000 );
check( 'unnamed places split by distance', 2 === count( $unnamed ) );

// A favourite with no position at all still makes a place, just without coordinates.
$nowhere = Days::places( [ fav( $t, null, null ) ], 2000 );
check( 'unlocated favourite makes a place without coordinates', 1 === count( $nowhere ) && null === $nowhere[0]['lat'] );
check( 'no favourites, no places', [] === Days::places( [], 2000 ) );

// Titles name the day's places in order, once each.
check( 'title lists distinct places', 'Lisbon, Sintra' === Days::title( $places, 'Day 3' ) );
check( 'title falls back when nothing is named', 'Day 3' === Days::title( $unnamed, 'Day 3' ) );

// Seeded content: one heading per place, each with an anchor holding its start time.
$content = Days::seed_content( $places );
check( 'seed has a heading per place', 3 === substr_count( $content, '<!-- wp:heading' ) );
check( 'seed has a photos block per place', 3 === substr_count( $content, '<!-- wp:' . Days::PHOTOS_BLOCK . ' /-->' ) );
check( 'each photos block follows its heading', 1 === preg_match( '/Sintra<\/h2>.*?<!-- wp:paragraph -->.*?<!-- wp:' . preg_quote( Days::PHOTOS_BLOCK, '/' ) . ' \/-->.*?Lisbon<\/h2>/s', $content ) );
check( 'seed anchors parse back to start times', [ $t, $t + 7200, $t + 18000 ] === Days::anchor_times( $content ) );
check( 'two headings with the same start time count once', [ 5, 9 ] === Days::anchor_times( '<h2 id="ts-place-5">A</h2><h2 id="ts-place-9">B</h2><h2 id="ts-place-5">C</h2>' ) );
check( 'seed escapes place names', false === strpos( Days::seed_content( [ [ 't' => 1, 'city' => '<b>', 'lat' => null, 'lon' => null ], [ 't' => 2, 'city' => 'x', 'lat' => null, 'lon' => null ] ] ), '<b>' ) );
$single = Days::seed_content( [ $places[0] ] );
check( 'one place needs no headings', false === strpos( $single, 'wp:heading' ) );
check( 'one place still gets a paragraph and a photos block', 1 === substr_count( $single, '<!-- wp:paragraph' ) && 1 === substr_count( $single, Days::PHOTOS_BLOCK ) );
check( 'no places, nothing to seed', '' === Days::seed_content( [] ) );
check( 'place_anchor reads a heading id', 42 === Days::place_anchor( '<h2 class="wp-block-heading" id="ts-place-42">X</h2>' ) );
check( 'place_anchor ignores other headings', null === Days::place_anchor( '<h2 id="intro">X</h2>' ) );

// The "Trip place" pattern: a place heading waiting for its first photo, which the trip
// page ignores until one is picked (#143).
$pattern = Days::place_pattern( 'New place' );
check( 'place pattern: a heading marked to pick its first photo', 1 === preg_match( '/<!-- wp:heading -->\s*<h2 class="wp-block-heading" id="ts-place-pick">New place<\/h2>\s*<!-- \/wp:heading -->/', $pattern ), $pattern );
check( 'place pattern: then a paragraph and a photos block', 1 === preg_match( '/<\/h2>.*?<!-- wp:paragraph -->.*?<!-- wp:' . preg_quote( Days::PHOTOS_BLOCK, '/' ) . ' \/-->\s*$/s', $pattern ) );
check( 'place pattern escapes its heading', false === strpos( Days::place_pattern( '<b>' ), '<b>' ) );
check( 'a heading waiting for its first photo is no place heading yet', [] === Days::anchor_times( $pattern ) && null === Days::place_anchor( '<h2 id="ts-place-pick">X</h2>' ) );

// Sections: photos before the first heading are section 0; then each heading owns
// everything up to the next one.
$starts = [ 100, 200 ];
check( 'before the first heading is section 0', 0 === Days::section_index( $starts, 50 ) );
check( 'at a heading starts its section', 1 === Days::section_index( $starts, 100 ) );
check( 'between headings', 1 === Days::section_index( $starts, 199 ) );
check( 'after the last heading', 2 === Days::section_index( $starts, 500 ) );
check( 'no headings: everything in section 0', 0 === Days::section_index( [], 500 ) );

exit( $failures ? 1 : 0 );
