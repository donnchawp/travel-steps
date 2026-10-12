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
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $trip_id,
				'post_status'    => [ 'inherit', 'private' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => Uploads::META_PHOTO_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		return array_map( [ self::class, 'row' ], array_map( 'intval', $ids ) );
	}

	/**
	 * An upload as a photo row, from what was read from its original (Exif::parse())
	 * and its size as displayed (the attachment metadata).
	 */
	private static function row( int $attachment_id ): array {
		$exif   = ( get_post_meta( $attachment_id, Uploads::META_EXIF, true ) ?: [] ) + [
			'taken'  => '',
			'offset' => '',
			'lat'    => null,
			'lon'    => null,
			'camera' => '',
		];
		$meta   = wp_get_attachment_metadata( $attachment_id ) ?: [];
		$local  = '' === $exif['taken'] ? null : ( new \DateTimeImmutable( $exif['taken'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$offset = self::offset_s( (string) $exif['offset'] );
		return [
			'id'      => Uploads::photo_id( $attachment_id ),
			'local'   => $local,
			'zoned'   => null !== $local && null !== $offset,
			't'       => null === $local ? null : $local - (int) $offset,
			'day'     => null === $local ? '' : substr( $exif['taken'], 0, 10 ),
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

	/**
	 * The photo proxy serves uploads itself (Uploads::image()), before asking a source.
	 */
	public function image( string $photo_id, string $size ) {
		return new \WP_Error( 'travel_steps_not_here', 'Uploads are served by the photo proxy.' );
	}
}
