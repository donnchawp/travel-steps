<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Trip photos" block marks where a place's photos go in a step's story. It
 * stores nothing: the trip page puts the photos of the section it's in (see
 * View::render_story()) at its position. In the editor it previews them.
 */
class PhotosBlock {
	const HANDLE = 'travel-steps-photos-block';

	public static function register(): void {
		$version = fn( $file ) => (string) filemtime( TRAVEL_STEPS_PLUGIN_DIR . $file );
		wp_register_script(
			self::HANDLE,
			TRAVEL_STEPS_PLUGIN_URL . 'assets/photos-block.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-data' ],
			$version( 'assets/photos-block.js' ),
			true
		);
		wp_register_style( self::HANDLE, TRAVEL_STEPS_PLUGIN_URL . 'assets/photos-block.css', [], $version( 'assets/photos-block.css' ) );

		register_block_type(
			Days::PHOTOS_BLOCK,
			[
				'api_version'           => 3,
				'title'                 => __( 'Trip photos', 'travel-steps' ),
				'description'           => __( 'Where this place’s photos appear on the trip page.', 'travel-steps' ),
				'category'              => 'media',
				'icon'                  => 'format-gallery',
				// Only for the editor preview: the section the block sits in, as place start times.
				'attributes'            => [
					'start' => [ 'type' => 'integer' ],
					'end'   => [ 'type' => 'integer' ],
				],
				'supports'              => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script_handles' => [ self::HANDLE ],
				'editor_style_handles'  => [ self::HANDLE ],
				'render_callback'       => [ self::class, 'render' ],
			]
		);
	}

	/**
	 * The editor preview: the step's shown photos taken from start until end. Anywhere
	 * else it renders nothing; the trip page places the real photos itself.
	 */
	public static function render( array $attributes ): string {
		$step = get_post();
		if ( ! $step || Trips::STEP !== $step->post_type || ! current_user_can( 'edit_post', $step->ID ) ) {
			return '';
		}
		$trip = get_post( $step->post_parent );
		if ( ! $trip ) {
			return '';
		}
		$groups = Trips::photos_by_step( $trip->ID, Trips::timeline_steps( $trip, true ) )[ $step->ID ] ?? [ 'shown' => [] ];
		$start  = (int) ( $attributes['start'] ?? 0 );
		$end    = (int) ( $attributes['end'] ?? 0 );
		$photos = array_filter( $groups['shown'], fn( $p ) => $p['t'] >= $start && ( ! $end || $p['t'] < $end ) );

		if ( ! $photos ) {
			$empty = Trips::uses_uploads( $trip->ID )
				? __( 'No photos for this place yet. Add photos to the trip.', 'travel-steps' )
				: __( 'No photos for this place yet. Favourite some in Immich and sync.', 'travel-steps' );
			return '<p class="ts-photos-block-empty">' . esc_html( $empty ) . '</p>';
		}
		$html = '';
		foreach ( $photos as $photo ) {
			$html .= sprintf(
				'<img src="%s" alt="%s" data-asset="%s" loading="lazy">',
				esc_url( RestController::photo_url( $trip->ID, $photo['id'], 'thumbnail' ) ),
				esc_attr( $photo['caption'] ?? '' ),
				esc_attr( $photo['id'] )
			);
		}
		$note = sprintf(
			/* translators: %s: number of photos */
			_n( '%s photo. Pin or hide photos on the trip page.', '%s photos. Pin or hide photos on the trip page.', count( $photos ), 'travel-steps' ),
			number_format_i18n( count( $photos ) )
		);
		return '<div class="ts-photos-block-grid">' . $html . '</div><p class="ts-photos-block-note">' . esc_html( $note ) . '</p>';
	}
}
