<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos uploaded to the trip. Choosing what to upload is the choosing, so every
 * upload is a favourite. Uploads are the trip's own photos, like an album, so the
 * range is ignored.
 */
class MediaSource implements PhotoSource {
	public function photos( int $trip_id, ?array $range ) {
		$ids = Uploads::trip_photos( $trip_id );
		update_meta_cache( 'post', $ids ); // One query for all of them, not one each.
		return array_map( [ self::class, 'row' ], $ids );
	}

	/**
	 * An upload as a photo row, from what was read from its original (Exif::parse()),
	 * or the time its author gave it if that has none, and its size as displayed (the
	 * attachment metadata).
	 */
	private static function row( int $attachment_id ): array {
		$exif   = ( get_post_meta( $attachment_id, Uploads::META_EXIF, true ) ?: [] ) + Exif::parse( [] );
		// A time the author gave it is as a camera would have written it, with no offset.
		$taken  = $exif['taken'] ?: (string) get_post_meta( $attachment_id, Uploads::META_TAKEN, true );
		$meta   = wp_get_attachment_metadata( $attachment_id ) ?: [];
		$local  = '' === $taken ? null : ( new \DateTimeImmutable( $taken, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$offset = self::offset_s( (string) $exif['offset'] );
		$t      = null === $local ? null : $local - (int) $offset;
		return [
			'id'      => Uploads::photo_id( $attachment_id ),
			'local'   => $local,
			'zoned'   => null !== $local && null !== $offset,
			't'       => $t,
			'day'     => null === $t ? '' : Days::local_day( $taken, $t, wp_timezone() ),
			'lat'     => null === $exif['lat'] ? null : (float) $exif['lat'],
			'lon'     => null === $exif['lon'] ? null : (float) $exif['lon'],
			'w'       => (int) ( $meta['width'] ?? 0 ),
			'h'       => (int) ( $meta['height'] ?? 0 ),
			'city'    => '',
			'country' => '',
			'caption' => '',
			'fav'     => true,
			'camera'  => (string) $exif['camera'],
		];
	}

	/**
	 * "+01:00" as seconds east of UTC, or null if there's no offset.
	 */
	private static function offset_s( string $offset ): ?int {
		if ( ! preg_match( '/^([+-])(\d{2}):(\d{2})$/', $offset, $m ) ) {
			return null;
		}
		return ( '-' === $m[1] ? -1 : 1 ) * ( (int) $m[2] * HOUR_IN_SECONDS + (int) $m[3] * MINUTE_IN_SECONDS );
	}
}
