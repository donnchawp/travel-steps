<?php
/**
 * The map's areas: sync keeps a box per 10 minutes of the track, and the trip's map data
 * gives each place heading the area of its stretch of route. Required at the end of
 * smoke.php, in its scope: uses check(), row(), TrackDawarich, FakeSource and $get.
 */
use TravelSteps\Days;
use TravelSteps\Geo;
use TravelSteps\Settings;
use TravelSteps\Trips;
use TravelSteps\TripSync;

wp_set_current_user( 1 );

// A day from home to Cork Airport, a flight to Málaga, a drive to Cómpeta and a walk there.
$ma_at = fn( $hm ) => strtotime( "2025-06-01 $hm UTC" );
$ma_line = function ( $from, $to, $t0, $t1, $every ) {
	$pts = [];
	for ( $t = $t0; $t <= $t1; $t += $every ) {
		$f     = $t1 > $t0 ? ( $t - $t0 ) / ( $t1 - $t0 ) : 0;
		$pts[] = [ $from[0] + ( $to[0] - $from[0] ) * $f, $from[1] + ( $to[1] - $from[1] ) * $f, $t, '', '' ];
	}
	return $pts;
};
$cork_airport   = [ 51.8413, -8.4911 ];
$malaga_airport = [ 36.6749, -4.4991 ];
$competa        = [ 36.8345, -3.9686 ];
// Home is 3 km north of where the track starts.
$home_lat      = 51.957;
$home_lon      = -8.40;
$home_radius_m = 2000;
$ma_track      = array_merge(
	[ [ $home_lat, $home_lon, $ma_at( '05:50' ), '', '' ], [ $home_lat + 0.001, $home_lon, $ma_at( '05:55' ), '', '' ] ],
	$ma_line( [ 51.93, -8.40 ], $cork_airport, $ma_at( '06:00' ), $ma_at( '07:00' ), 120 ),
	$ma_line( $cork_airport, $cork_airport, $ma_at( '07:10' ), $ma_at( '08:30' ), 600 ),
	$ma_line( $malaga_airport, $malaga_airport, $ma_at( '12:00' ), $ma_at( '12:15' ), 300 ),
	$ma_line( $malaga_airport, $competa, $ma_at( '12:20' ), $ma_at( '13:40' ), 120 )
);
for ( $t = $ma_at( '14:00' ); $t <= $ma_at( '16:58' ); $t += 120 ) {
	$ma_track[] = [ $competa[0] + 0.003 * sin( $t / 600 ), $competa[1] + 0.003 * cos( $t / 900 ), $t, '', '' ];
}
// A long drive the next day, zigzagging so the route has to be simplified hard to fit.
$filler = strtotime( '2025-06-02 00:00 UTC' );
for ( $i = 0; $i < 9000; $i++ ) {
	$ma_track[] = [ 37.2 + ( $i % 2 ) * 0.005, -3.6 + $i * 0.00002, $filler + $i * 9, '', '' ];
}

$ma_account = Settings::account( 1 );
Settings::update_account( 1, [ 'home_lat' => (string) $home_lat, 'home_lon' => (string) $home_lon, 'home_radius_km' => $home_radius_m / 1000 ] + $ma_account );

$ma_trip            = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Cork to Cómpeta', 'post_status' => 'draft' ] );
$ma_dawarich        = new TrackDawarich( 'http://x', 'k' );
$ma_dawarich->pts   = $ma_track;
$ma_source          = new FakeSource();
$ma_source->rows    = [
	array_merge( row( 501, '2025-06-01', '07:30', $cork_airport[0], $cork_airport[1], 'Cork', true ), [ 'country' => 'Ireland' ] ),
	array_merge( row( 502, '2025-06-01', '12:20', $malaga_airport[0], $malaga_airport[1], 'Málaga', true ), [ 'country' => 'Spain' ] ),
	array_merge( row( 503, '2025-06-01', '14:00', $competa[0], $competa[1], 'Cómpeta', true ), [ 'country' => 'Spain' ] ),
];
update_post_meta( $ma_trip, Trips::META_START, '2025-06-01' );
update_post_meta( $ma_trip, Trips::META_END, '2025-06-02' );
$ma_result = ( new TripSync( $ma_dawarich, $ma_source ) )->sync( $ma_trip );
Settings::update_account( 1, $ma_account );

$ma_boxes = Trips::boxes( $ma_trip );
check( 'map areas: sync keeps a box per 10 minutes of the track', ! is_wp_error( $ma_result ) && count( $ma_boxes ) > 0 && 6 === count( $ma_boxes[0] ), is_wp_error( $ma_result ) ? $ma_result->get_error_message() : count( $ma_boxes ) );
check( '…from before the route is simplified, so the walk keeps its shape', 18 === count( array_filter( $ma_boxes, fn( $b ) => $b[0] >= $ma_at( '14:00' ) && $b[0] < $ma_at( '17:00' ) ) ) );
check( '…on separate pieces either side of the flight', count( array_unique( array_column( $ma_boxes, 5 ) ) ) >= 2 );
check( '…and with home cut out', [] === array_filter( $ma_boxes, fn( $b ) => Geo::distance( $b[1], $b[2], $home_lat, $home_lon ) < $home_radius_m || Geo::distance( $b[3], $b[4], $home_lat, $home_lon ) < $home_radius_m ) );

// The map data gives each place heading the area of its stretch of the route.
$ma_steps = Trips::steps( $ma_trip, true );
$ma_step  = $ma_steps[0] ?? null;
check( 'map areas: one step, for the day with favourites', 1 === count( $ma_steps ), count( $ma_steps ) );
wp_publish_post( $ma_trip );
wp_publish_post( $ma_step->ID );
$ma_read = function () use ( $get, $ma_trip ) {
	clean_post_cache( $ma_trip );
	$res = $get( '/travel-steps/v1/trips/' . $ma_trip );
	return [ $res->get_status(), $res->get_data() ];
};
$ma_starts = Days::anchor_times( get_post( $ma_step->ID )->post_content );
sort( $ma_starts );
[ , $ma_data ] = $ma_read();
$ma_areas      = (array) ( $ma_data['areas'] ?? [] );
[ $cork, $malaga, $competa_area ] = array_values( $ma_areas ) + [ null, null, null ];
check( 'map areas: one per place heading, keyed by its anchor', 3 === count( $ma_starts ) && array_map( fn( $t ) => Days::ANCHOR_PREFIX . $t, $ma_starts ) === array_keys( $ma_areas ), array_keys( $ma_areas ) );
check( '…Cork Airport’s covers the drive from home to the airport, and nothing in Spain', $cork && $cork[0][0] > 51.8 && $cork[1][0] >= 51.92 && $cork[0][1] <= -8.49, $cork );
check( '…Málaga’s covers the drive to Cómpeta', $malaga && $malaga[0][1] <= -4.49 && $malaga[1][1] >= -3.97 && $malaga[1][0] < 37, $malaga );
check( '…and Cómpeta’s is just the walk', $competa_area && $competa_area[1][0] - $competa_area[0][0] < 0.01 && $competa_area[1][1] - $competa_area[0][1] < 0.01, $competa_area );
check( '…though the route was simplified to fit', array_sum( array_map( 'count', $ma_data['route'] ) ) <= 8000 );
check( '…and no track times or boxes are sent', ! isset( $ma_data['boxes'] ) && false === strpos( wp_json_encode( $ma_data ), (string) $ma_at( '12:00' ) ) );

// A story edited after sync gets areas for the headings it has now.
wp_update_post( [ 'ID' => $ma_step->ID, 'post_content' => "<!-- wp:paragraph -->\n<p>A long day.</p>\n<!-- /wp:paragraph -->" ] );
[ , $ma_data ] = $ma_read();
$ma_areas      = (array) ( $ma_data['areas'] ?? [] );
$ma_day        = $ma_areas[ 'step-' . $ma_step->ID ] ?? null;
check( 'map areas: a step without headings gets one area, for its day', [ 'step-' . $ma_step->ID ] === array_keys( $ma_areas ) && $ma_day && $ma_day[0][0] < 37 && $ma_day[1][0] > 51.9, $ma_areas );
$ma_early = $ma_at( '00:00' ) - 1800;
wp_update_post( [ 'ID' => $ma_step->ID, 'post_content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\" id=\"" . Days::ANCHOR_PREFIX . $ma_early . "\">Off</h2>\n<!-- /wp:heading -->" ] );
[ , $ma_data ] = $ma_read();
$ma_off        = ( (array) ( $ma_data['areas'] ?? [] ) )[ Days::ANCHOR_PREFIX . $ma_early ] ?? null;
check( '…and a heading timed the day before still gets an area, Cork included', $ma_off && $ma_off[1][0] > 51.9, $ma_data['areas'] ?? null );

// A trip synced before boxes were kept.
delete_post_meta( $ma_trip, Trips::META_BOXES );
[ $ma_status, $ma_data ] = $ma_read();
check( 'map areas: a trip without boxes has none, and no error', 200 === $ma_status && '{}' === wp_json_encode( $ma_data['areas'] ?? null ), $ma_data['areas'] ?? null );

foreach ( array_merge( Trips::steps( $ma_trip, true ), [ get_post( $ma_trip ) ] ) as $post ) {
	wp_delete_post( $post->ID, true );
}
