<?php
/**
 * Sets up the Playground demo: points Travel Steps at the fake services in
 * fake-services.php, creates the trip, syncs it, then does what an author would:
 * names the days, writes a few lines, publishes, and picks a cover photo.
 *
 * Run after WordPress has loaded, with the plugin and fake-services.php active.
 */

namespace TravelStepsDemo;

use TravelSteps\Settings;
use TravelSteps\Trips;
use TravelSteps\TripSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_set_current_user( 1 );
update_option( 'timezone_string', ZONE );
update_option( 'blogname', 'Travel Steps demo' );
update_option( 'permalink_structure', '/%postname%/' );
update_option(
	Settings::OPTION,
	array_merge(
		Settings::all(),
		[
			'immich_url'   => IMMICH,
			'immich_key'   => 'demo',
			'dawarich_url' => DAWARICH,
			'dawarich_key' => 'demo',
		]
	)
);

$trip_id = wp_insert_post(
	[
		'post_type'    => Trips::TRIP,
		'post_title'   => 'West of Ireland',
		'post_name'    => 'west-of-ireland',
		'post_status'  => 'publish',
		'post_content' => "<!-- wp:paragraph -->\n<p>A week from Dublin to Kerry, the long way round: Galway, Connemara, the Aran Islands, the Burren and the Cliffs of Moher, then down to Dingle and Killarney.</p>\n<!-- /wp:paragraph -->",
	]
);
update_post_meta( $trip_id, Trips::META_START, '2025-06-09' );
update_post_meta( $trip_id, Trips::META_END, '2025-06-15' );
update_post_meta( $trip_id, Trips::META_ALBUM, ALBUM );

$result = ( new TripSync() )->sync( $trip_id );
if ( is_wp_error( $result ) ) {
	throw new \RuntimeException( 'Demo sync failed: ' . $result->get_error_message() );
}

// What an author would write: a title for each day, and a few lines for each place.
$stories = [
	'2025-06-09' => [ 'Dublin to Galway', [ 'Coffee in Temple Bar before the crowds, then one last look at the Liffey.', 'Across the country in an afternoon. Galway was busy and loud in the best way, and the light by the Spanish Arch lasted until nearly eleven.' ] ],
	'2025-06-10' => [ 'Connemara', [ 'The road out through Oughterard and Maam Cross gets emptier with every mile. Derryclare Lough was flat calm.', 'Kylemore Abbey looks unreal from across the lake.', 'A walk in the national park, with more sheep than people.' ] ],
	'2025-06-11' => [ 'Inis Mór', [ 'The ferry from Rossaveal, then bikes from the pier in Kilronan.', 'Dún Aonghasa sits right on the edge of a 100 metre drop. No railings.' ] ],
	'2025-06-12' => [ 'The Burren and the Cliffs of Moher', [ 'Limestone pavement as far as you can see, and the Poulnabrone dolmen in the middle of it.', 'The cliffs are every bit as big as people say.', 'Music in Doolin until late.' ] ],
	'2025-06-13' => [ 'Dingle', [ 'South over the Shannon on the Killimer ferry, then the long road out to Dingle. Fish and chips on the pier.' ] ],
	'2025-06-14' => [ 'Slea Head', [ 'The Slea Head drive, with the Blaskets offshore the whole way round.', 'Back through Ballyferriter, where the sea was a colour Ireland isn’t supposed to have.' ] ],
	'2025-06-15' => [ 'Killarney', [ 'Through the Gap of Dunloe on foot, then a boat across the lakes.', 'Ross Castle, Muckross House and Torc Waterfall, all in an afternoon.', 'Ladies View on the way back, and the end of the road.' ] ],
];
foreach ( Trips::steps( $trip_id, true ) as $step ) {
	[ $title, $paragraphs ] = $stories[ Trips::day( $step ) ] ?? [ $step->post_title, [] ];
	$content                = $step->post_content;
	foreach ( $paragraphs as $text ) {
		$content = preg_replace( '#<p></p>#', '<p>' . esc_html( $text ) . '</p>', $content, 1 );
	}
	wp_update_post(
		[
			'ID'           => $step->ID,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		]
	);
}

update_post_meta( $trip_id, Trips::META_COVER, asset_id( 'Zf9bvJ6182k' ) );
flush_rewrite_rules();
