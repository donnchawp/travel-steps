<?php
/**
 * Plugin Name: Travel Steps demo services
 * Description: Stands in for Immich and Dawarich in the Playground demo: a week in the west of Ireland, with photos from Unsplash.
 *
 * Travel Steps talks to these fake servers exactly as it would to real ones, so
 * syncing works in the demo. Photos are served from images.unsplash.com.
 */

namespace TravelStepsDemo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IMMICH   = 'https://immich.demo.invalid';
const DAWARICH = 'https://dawarich.demo.invalid';
const ALBUM    = '6f1c2a10-1d3e-4b7a-9c55-2e8f0a7b3c41';
const ZONE     = 'Europe/Dublin';

/**
 * The week, a day at a time: where you were and when, local time. Photos are
 * waypoints too. Each photo is [ Unsplash id, image path, width, height,
 * photographer, favourite ].
 */
function days(): array {
	return [
		'2025-06-09' => [
			[ '09:00', 53.3438, -6.2675, 'Dublin' ],
			[ '09:40', 53.3455, -6.2643, 'Dublin', [ '-ABmxr3Yo4Y', 'photo-1717147610812-0ff15f6e44f9', 4781, 3346, 'Sarah', true ] ],
			[ '10:15', 53.3464, -6.2631, 'Dublin', [ 'blDB0HbjB1k', 'photo-1663509851482-56ffbd1cf076', 4865, 3456, 'Sophie Popplewell', false ] ],
			[ '10:45', 53.3480, -6.2900, 'Dublin' ],
			[ '11:30', 53.4540, -7.1000, 'Kinnegad' ],
			[ '12:15', 53.4230, -7.9400, 'Athlone' ],
			[ '13:15', 53.4230, -7.9400, 'Athlone' ],
			[ '13:45', 53.3290, -8.2200, 'Ballinasloe' ],
			[ '14:30', 53.2680, -8.9260, 'Oranmore' ],
			[ '15:00', 53.2740, -9.0490, 'Galway' ],
			[ '16:30', 53.2723, -9.0526, 'Galway', [ 'R92aLUSZXP0', 'photo-1693824113742-268c5308c81f', 6016, 4016, 'Daniel Zbroja', true ] ],
			[ '17:10', 53.2693, -9.0536, 'Galway', [ 'gFBLNZT8Dvk', 'photo-1755961067947-21f721a34452', 6000, 4000, 'Anna Hunko', true ] ],
			[ '17:20', 53.2697, -9.0560, 'Galway', [ 'TeUg9x6cxOs', 'photo-1755961067750-6b6bcdcbf098', 4000, 6000, 'Anna Hunko', false ] ],
			[ '22:30', 53.2735, -9.0560, 'Galway', [ 'C-VJl12EhCM', 'photo-1692804239075-4d4dc0cd565b', 6016, 4016, 'Daniel Zbroja', true ] ],
		],
		'2025-06-10' => [
			[ '09:00', 53.2740, -9.0490, 'Galway' ],
			[ '09:25', 53.3380, -9.1800, 'Moycullen' ],
			[ '09:45', 53.4280, -9.3200, 'Oughterard' ],
			[ '10:05', 53.4570, -9.5460, 'Maam Cross' ],
			[ '10:20', 53.4130, -9.7340, 'Recess' ],
			[ '10:40', 53.4720, -9.7980, 'Derryclare', [ '2GSTQyqlIxQ', 'photo-1638207618450-c68541438292', 4032, 3024, 'Colin C Murphy', true ] ],
			[ '11:05', 53.5200, -9.8300, 'Inagh Valley' ],
			[ '11:20', 53.5600, -9.8600, 'Kylemore' ],
			[ '11:45', 53.5617, -9.8890, 'Kylemore', [ '3CGHb6ItH3c', 'photo-1570426606723-9c8991639efd', 5893, 3929, 'Max Sandelin', true ] ],
			[ '12:10', 53.5605, -9.8920, 'Kylemore', [ 'hLqJHN01XMU', 'photo-1686586351889-3dd1d9562913', 3600, 2400, 'Alex Grodkiewicz', false ] ],
			[ '13:30', 53.5530, -9.9460, 'Letterfrack' ],
			[ '15:00', 53.5480, -9.9400, 'Letterfrack', [ 'g-O9LQV51Qc', 'photo-1596557770430-92f21e94ee47', 3817, 2540, 'J. Schiemann', true ] ],
			[ '16:30', 53.5530, -9.9460, 'Letterfrack' ],
			[ '16:50', 53.5960, -9.6970, 'Leenaun' ],
			[ '17:10', 53.5160, -9.5640, 'Maam' ],
			[ '17:30', 53.4280, -9.3200, 'Oughterard' ],
			[ '18:00', 53.2740, -9.0490, 'Galway' ],
		],
		'2025-06-11' => [
			[ '08:45', 53.2740, -9.0490, 'Galway' ],
			[ '09:15', 53.2430, -9.3060, 'Spiddal' ],
			[ '09:35', 53.2350, -9.4760, 'Inverin' ],
			[ '09:50', 53.2670, -9.5620, 'Rossaveal' ],
			[ '10:30', 53.2670, -9.5620, 'Rossaveal' ],
			[ '11:10', 53.1225, -9.6680, 'Kilronan' ],
			[ '11:40', 53.1220, -9.6690, 'Kilronan', [ 'l6yc1FmFfec', 'photo-1759252313472-02f1be63dfc5', 5461, 8192, 'Jonathan Borba', true ] ],
			[ '12:30', 53.1290, -9.7200, 'Kilronan', [ 'qNuu9xz16lY', 'photo-1749031604145-edad40c30b66', 6000, 4000, 'Klaus Birner', false ] ],
			[ '12:50', 53.1300, -9.7500, 'Kilmurvey' ],
			[ '13:40', 53.1258, -9.7676, 'Dún Aonghasa', [ 'aAjAFjPmdQ0', 'photo-1749031603758-4b1e01119129', 5775, 3850, 'Klaus Birner', true ] ],
			[ '13:55', 53.1262, -9.7670, 'Dún Aonghasa', [ 'HjkEnc4uSOk', 'photo-1660913382625-3983609f2974', 6024, 4020, 'Francesco Dondi', true ] ],
			[ '15:00', 53.1310, -9.7350, 'Kilmurvey', [ 'R6QzDjJ8H7I', 'photo-1630404764856-1ed2f5295618', 3177, 2717, 'Paolo Gregotti', false ] ],
			[ '15:30', 53.1250, -9.7000, 'Kilronan', [ 'hsdgOE7Fhx0', 'photo-1759503613277-20a95441a01a', 5195, 7793, 'Jonathan Borba', false ] ],
			[ '16:15', 53.1225, -9.6680, 'Kilronan' ],
			[ '17:00', 53.1225, -9.6680, 'Kilronan' ],
			[ '17:40', 53.2670, -9.5620, 'Rossaveal' ],
			[ '18:00', 53.2350, -9.4760, 'Inverin' ],
			[ '18:15', 53.2430, -9.3060, 'Spiddal' ],
			[ '18:40', 53.2740, -9.0490, 'Galway' ],
		],
		'2025-06-12' => [
			[ '09:00', 53.2740, -9.0490, 'Galway' ],
			[ '09:20', 53.2680, -8.9260, 'Oranmore' ],
			[ '09:40', 53.1400, -8.9370, 'Kinvara' ],
			[ '10:00', 53.1150, -9.1500, 'Ballyvaughan' ],
			[ '10:30', 53.0487, -9.1400, 'Burren', [ 'BjBSGvabLeA', 'photo-1702563269837-010f43aafd93', 3008, 2000, 'Ulrike R. Donohue', true ] ],
			[ '10:50', 53.0600, -9.1600, 'Burren', [ 'Tzt_JDrxVZc', 'photo-1702563357512-b21e5e7a3a1c', 3008, 2000, 'Ulrike R. Donohue', false ] ],
			[ '11:30', 53.0300, -9.2900, 'Lisdoonvarna' ],
			[ '12:00', 52.9715, -9.4250, 'Cliffs of Moher' ],
			[ '12:30', 52.9740, -9.4300, 'Cliffs of Moher', [ 'Zf9bvJ6182k', 'photo-1588952917149-7ac721bbc1e0', 5184, 3456, 'Jarek Skowron', true ] ],
			[ '12:50', 52.9700, -9.4310, 'Cliffs of Moher', [ 'icS9hs42LE8', 'photo-1590505787849-4f73eec6a390', 5507, 3671, 'Lukas Bato', true ] ],
			[ '13:10', 52.9800, -9.4250, 'Cliffs of Moher', [ 'sKm0RZjOgIE', 'photo-1602354817989-b9664561ae84', 3719, 2281, 'Sean Kuriyan', false ] ],
			[ '13:30', 52.9650, -9.4330, 'Cliffs of Moher', [ 'AEallbg9q_A', 'photo-1591387091135-1bd5a83d27ab', 6219, 4147, 'Federico Beccari', true ] ],
			[ '15:00', 53.0167, -9.4031, 'Doolin' ],
			[ '15:30', 53.0180, -9.4000, 'Doolin', [ '5ncpOfvJc_0', 'photo-1570222772987-eb5666043ecf', 5715, 3473, 'Magdalena Smolnicka', true ] ],
			[ '18:30', 53.0157, -9.4030, 'Doolin', [ 'WjyaIj6Gykg', 'photo-1639442232940-29a560e38bc4', 5184, 3456, 'Lynnette Greenslade', true ] ],
		],
		'2025-06-13' => [
			[ '09:30', 53.0167, -9.4031, 'Doolin' ],
			[ '10:00', 52.9330, -9.3440, 'Lahinch' ],
			[ '10:30', 52.8550, -9.4000, 'Miltown Malbay' ],
			[ '11:00', 52.6400, -9.4830, 'Kilrush' ],
			[ '11:15', 52.6170, -9.3900, 'Killimer' ],
			[ '11:30', 52.6170, -9.3900, 'Killimer' ],
			[ '11:50', 52.5740, -9.3740, 'Tarbert' ],
			[ '12:20', 52.4460, -9.4850, 'Listowel' ],
			[ '13:15', 52.4460, -9.4850, 'Listowel' ],
			[ '13:45', 52.2710, -9.7000, 'Tralee' ],
			[ '14:15', 52.2200, -9.9000, 'Camp' ],
			[ '14:40', 52.1500, -10.0570, 'Annascaul' ],
			[ '15:10', 52.1405, -10.2690, 'Dingle' ],
			[ '16:00', 52.1390, -10.2700, 'Dingle', [ 'a32aN5NfLYg', 'photo-1695755191597-3131548bbe4e', 3984, 2656, 'Dahlia E. Akhaine', true ] ],
			[ '16:30', 52.1410, -10.2680, 'Dingle', [ 'Nhvr0RtU7Ws', 'photo-1581939612436-469accdbc5df', 4608, 3072, 'luana niemann', true ] ],
			[ '17:00', 52.1415, -10.2660, 'Dingle', [ 'HSDSY_XoPa8', 'photo-1560553372-13c74830699a', 5184, 3456, 'Morgan Lane', false ] ],
			[ '18:30', 52.1350, -10.2900, 'Dingle', [ 'ezQ802l40Sg', 'photo-1586810728501-7a1d470d1a9e', 3973, 2594, 'Proinsias Mac an Bheatha', false ] ],
		],
		'2025-06-14' => [
			[ '09:30', 52.1405, -10.2690, 'Dingle' ],
			[ '09:50', 52.1300, -10.3600, 'Ventry' ],
			[ '10:15', 52.1010, -10.4500, 'Slea Head' ],
			[ '10:40', 52.1090, -10.4650, 'Dún Chaoin', [ 'qZ0SRvo0g2s', 'photo-1787177236351-6bb832455401', 3369, 3544, 'Jeremy Brady', true ] ],
			[ '11:00', 52.1080, -10.4690, 'Dún Chaoin', [ '5M-xWJ3uGEY', 'photo-1592662110359-2d3a1c8a59b8', 5181, 3450, 'Mark Lawson', false ] ],
			[ '11:30', 52.1270, -10.4590, 'Dún Chaoin', [ '3JXl1WrFxsQ', 'photo-1608222755569-1df12cf8b24c', 3024, 4032, 'Irina Shishkina', true ] ],
			[ '11:45', 52.1272, -10.4595, 'Dún Chaoin', [ 'ZS2YDwGsGKA', 'photo-1659296197284-199ec641f7f5', 4016, 6016, 'Pascal Bullan', false ] ],
			[ '13:00', 52.1660, -10.4050, 'Ballyferriter' ],
			[ '14:00', 52.1750, -10.4300, 'Ballyferriter', [ 'lQI7lcpHn7s', 'photo-1757962043421-967030b23d31', 3648, 2736, 'Jim Petkiewicz', true ] ],
			[ '14:20', 52.1770, -10.4350, 'Ballyferriter', [ 'pC9N7vhzKoE', 'photo-1757962329476-599fc0fd2a5e', 3648, 2736, 'Jim Petkiewicz', false ] ],
			[ '15:30', 52.1405, -10.2690, 'Dingle' ],
		],
		'2025-06-15' => [
			[ '09:00', 52.1405, -10.2690, 'Dingle' ],
			[ '09:30', 52.1500, -10.0570, 'Annascaul' ],
			[ '09:45', 52.1400, -9.9800, 'Inch' ],
			[ '10:10', 52.1680, -9.7000, 'Castlemaine' ],
			[ '10:30', 52.1060, -9.7850, 'Killorglin' ],
			[ '10:50', 52.0430, -9.6400, 'Gap of Dunloe' ],
			[ '11:20', 52.0280, -9.6370, 'Gap of Dunloe', [ 'kv-F9x_JVTo', 'photo-1588588787326-4a071bbce137', 4912, 3264, 'Peter Mitchell', true ] ],
			[ '11:40', 52.0200, -9.6420, 'Gap of Dunloe', [ '2c3ycx4ERJg', 'photo-1654466592855-d0cdd1bd3a8e', 2592, 3872, 'Michaela Murphy', false ] ],
			[ '12:20', 51.9960, -9.6450, 'Gap of Dunloe' ],
			[ '12:40', 51.9960, -9.6450, 'Gap of Dunloe' ],
			[ '13:00', 51.9900, -9.6000, 'Killarney' ],
			[ '13:20', 52.0050, -9.5650, 'Killarney' ],
			[ '13:40', 52.0440, -9.5290, 'Killarney' ],
			[ '13:50', 52.0440, -9.5290, 'Killarney', [ 'EeUBl2uIkAw', 'photo-1787304455277-460af911f25c', 5980, 3916, 'Evgeni Gitlits', true ] ],
			[ '14:30', 52.0185, -9.5050, 'Killarney', [ '2bWR3h4AOEs', 'photo-1650989536602-cc07e201761c', 6000, 4000, 'Ian Hutchinson', true ] ],
			[ '15:30', 52.0060, -9.5060, 'Killarney', [ 'QAy0csr3A7U', 'photo-1659983007421-5bdc0dd0a051', 4032, 3024, 'Tommy Bond', true ] ],
			[ '16:30', 51.9800, -9.5900, 'Ladies View', [ 'tx1OrUxLS90', 'photo-1787307900316-5d88a2394dba', 5041, 3874, 'Jeremy Brady', true ] ],
			[ '16:45', 51.9805, -9.5905, 'Ladies View', [ '6ez-AIJiCic', 'photo-1697650054945-b2a0600c4e38', 5806, 3870, 'Valerie', false ] ],
			[ '17:30', 52.0590, -9.5070, 'Killarney' ],
		],
	];
}

function unix( string $day, string $time ): int {
	return ( new \DateTimeImmutable( "$day $time", new \DateTimeZone( ZONE ) ) )->getTimestamp();
}

/**
 * An Immich-style id for an Unsplash photo.
 */
function asset_id( string $unsplash_id ): string {
	$h = md5( 'travel-steps-demo:' . $unsplash_id );
	return sprintf( '%s-%s-4%s-8%s-%s', substr( $h, 0, 8 ), substr( $h, 8, 4 ), substr( $h, 13, 3 ), substr( $h, 17, 3 ), substr( $h, 20, 12 ) );
}

/**
 * Immich assets, as /api/search/metadata returns them.
 *
 * @return array<string, array> By asset id, each with its Unsplash image path under 'demo_image'.
 */
function assets(): array {
	$assets = [];
	foreach ( days() as $day => $stops ) {
		foreach ( $stops as $stop ) {
			if ( empty( $stop[4] ) ) {
				continue;
			}
			[ $time, $lat, $lon, $city, $photo ]                  = $stop;
			[ $unsplash, $image, $width, $height, $by, $favourite ] = $photo;
			$at        = new \DateTimeImmutable( "$day $time", new \DateTimeZone( ZONE ) );
			$id        = asset_id( $unsplash );
			$assets[ $id ] = [
				'id'            => $id,
				'type'          => 'IMAGE',
				'isFavorite'    => $favourite,
				'isTrashed'     => false,
				'width'         => $width,
				'height'        => $height,
				'localDateTime' => $at->format( 'Y-m-d\TH:i:s' ) . '.000Z',
				'fileCreatedAt' => $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s' ) . '.000Z',
				'exifInfo'      => [
					'dateTimeOriginal' => $at->format( 'c' ),
					'timeZone'         => ZONE,
					'latitude'         => $lat,
					'longitude'        => $lon,
					'city'             => $city,
					'country'          => 'Ireland',
					'description'      => sprintf( 'Photo: %s on Unsplash', $by ),
					// Sync drops photos with no camera (screenshots), unless they're favourites.
					'make'             => 'Unsplash',
				],
				'demo_image'    => $image,
				'demo_taken'    => $at->getTimestamp(),
			];
		}
	}
	return $assets;
}

/**
 * Dawarich points: a fix every two minutes along each day's stops, at the
 * stops' times.
 */
function points(): array {
	$points = [];
	foreach ( days() as $day => $stops ) {
		for ( $i = 1, $n = count( $stops ); $i < $n; $i++ ) {
			[ $from_time, $from_lat, $from_lon, $from_city ] = $stops[ $i - 1 ];
			[ $to_time, $to_lat, $to_lon ]                   = $stops[ $i ];
			$from = unix( $day, $from_time );
			$to   = unix( $day, $to_time );
			for ( $t = $from; $t < $to; $t += 120 ) {
				$f        = ( $t - $from ) / max( 1, $to - $from );
				$points[] = [
					'latitude'     => round( $from_lat + ( $to_lat - $from_lat ) * $f, 6 ),
					'longitude'    => round( $from_lon + ( $to_lon - $from_lon ) * $f, 6 ),
					'timestamp'    => $t,
					'city'         => $from_city,
					'country_name' => 'Ireland',
				];
			}
		}
		$last     = end( $stops );
		$points[] = [
			'latitude'     => $last[1],
			'longitude'    => $last[2],
			'timestamp'    => unix( $day, $last[0] ),
			'city'         => $last[3],
			'country_name' => 'Ireland',
		];
	}
	return $points;
}

function respond( $body, array $headers = [], int $code = 200 ): array {
	return [
		'headers'  => array_merge( [ 'content-type' => 'application/json' ], $headers ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => [
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Not Found',
		],
		'cookies'  => [],
		'filename' => null,
	];
}

function immich( string $path, array $args ) {
	$route = (string) wp_parse_url( $path, PHP_URL_PATH );
	if ( '/api/albums' === $route ) {
		return respond(
			[
				[
					'id'         => ALBUM,
					'albumName'  => 'West of Ireland',
					'assetCount' => count( assets() ),
				],
			]
		);
	}
	if ( '/api/search/metadata' === $route ) {
		$query = json_decode( (string) ( $args['body'] ?? '' ), true ) ?: [];
		$items = array_values(
			array_filter(
				assets(),
				function ( $asset ) use ( $query ) {
					if ( ! empty( $query['albumIds'] ) ) {
						return in_array( ALBUM, (array) $query['albumIds'], true );
					}
					$after  = isset( $query['takenAfter'] ) ? strtotime( $query['takenAfter'] ) : PHP_INT_MIN;
					$before = isset( $query['takenBefore'] ) ? strtotime( $query['takenBefore'] ) : PHP_INT_MAX;
					return $asset['demo_taken'] >= $after && $asset['demo_taken'] <= $before;
				}
			)
		);
		return respond(
			[
				'assets' => [
					'items'    => $items,
					'nextPage' => null,
				],
			]
		);
	}
	if ( preg_match( '#^/api/assets/([a-f0-9-]{36})/thumbnail$#', $route, $m ) ) {
		$asset = assets()[ $m[1] ] ?? null;
		if ( ! $asset ) {
			return respond( '', [], 404 );
		}
		$params = query( $path );
		$width = 'preview' === ( $params['size'] ?? '' ) ? 1440 : 400;
		return wp_remote_get( 'https://images.unsplash.com/' . $asset['demo_image'] . '?fm=jpg&q=80&w=' . $width, [ 'timeout' => 30 ] );
	}
	return respond( '', [], 404 );
}

/**
 * Query parameters, decoded without turning "+" into a space: Travel Steps 0.2.0
 * and earlier send ISO 8601 times such as 2025-06-09T08:00:00+00:00 unencoded.
 */
function query( string $path ): array {
	$params = [];
	foreach ( explode( '&', (string) wp_parse_url( $path, PHP_URL_QUERY ) ) as $pair ) {
		[ $key, $value ]             = array_pad( explode( '=', $pair, 2 ), 2, '' );
		$params[ rawurldecode( $key ) ] = rawurldecode( $value );
	}
	return $params;
}

function dawarich( string $path ) {
	$params = query( $path );
	$start = isset( $params['start_at'] ) ? strtotime( $params['start_at'] ) : PHP_INT_MIN;
	$end   = isset( $params['end_at'] ) ? strtotime( $params['end_at'] ) : PHP_INT_MAX;
	$rows  = array_values( array_filter( points(), fn( $p ) => $p['timestamp'] >= $start && $p['timestamp'] <= $end ) );
	return respond( $rows, [ 'x-total-pages' => '1' ] );
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( 0 === strpos( $url, IMMICH . '/' ) ) {
			return immich( substr( $url, strlen( IMMICH ) ), $args );
		}
		if ( 0 === strpos( $url, DAWARICH . '/' ) ) {
			return dawarich( substr( $url, strlen( DAWARICH ) ) );
		}
		return $preempt;
	},
	10,
	3
);
