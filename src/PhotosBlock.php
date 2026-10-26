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
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-data', 'wp-components', 'wp-i18n' ],
			$version( 'assets/photos-block.js' ),
			true
		);
		wp_set_script_translations( self::HANDLE, 'travel-steps-storyteller' );
		wp_register_style( self::HANDLE, TRAVEL_STEPS_PLUGIN_URL . 'assets/photos-block.css', [], $version( 'assets/photos-block.css' ) );

		register_block_type(
			Days::PHOTOS_BLOCK,
			[
				'api_version'           => 3,
				'title'                 => __( 'Trip photos', 'travel-steps-storyteller' ),
				'description'           => __( 'Where this place’s photos appear on the trip page.', 'travel-steps-storyteller' ),
				'category'              => 'media',
				'icon'                  => 'format-gallery',
				// Only for the editor preview: the section the block sits in, as place start
				// times, and lead: photos taken before it are shown first, as their own group.
				// pick: the place heading above waits for its first photo to be picked; taken:
				// the times other place headings start at, which it can't pick.
				'attributes'            => [
					'start' => [ 'type' => 'integer' ],
					'end'   => [ 'type' => 'integer' ],
					'lead'  => [ 'type' => 'integer' ],
					'pick'  => [ 'type' => 'boolean' ],
					'taken' => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
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

		// A place added by hand to a step synced before the trip got there. Its heading
		// gets its anchor when its first photo is picked in the block below it.
		register_block_pattern(
			'travel-steps/place',
			[
				'title'       => __( 'Trip place', 'travel-steps-storyteller' ),
				'description' => __( 'A place heading, a paragraph and its photos. Pick the place’s first photo in the photos block.', 'travel-steps-storyteller' ),
				'categories'  => [ 'text' ],
				'postTypes'   => [ Trips::STEP ],
				'content'     => Days::place_pattern( __( 'New place', 'travel-steps-storyteller' ) ),
			]
		);
	}

	/**
	 * The editor preview: the step's shown photos taken from start until end, after any
	 * taken before lead; or with pick, all of them to pick the place's first from, as
	 * buttons, those already starting another place disabled.
	 * Anywhere else it renders nothing; the trip page places the real photos itself.
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
		if ( ! empty( $attributes['pick'] ) && $groups['shown'] ) {
			$taken = array_map( 'intval', (array) ( $attributes['taken'] ?? [] ) );
			return self::grid( $trip->ID, $groups['shown'], __( 'Pick the first photo of this place. Dimmed photos already start a place.', 'travel-steps-storyteller' ), $taken );
		}
		$start  = (int) ( $attributes['start'] ?? 0 );
		$end    = (int) ( $attributes['end'] ?? 0 );
		$lead   = (int) ( $attributes['lead'] ?? 0 );
		$photos = array_filter( $groups['shown'], fn( $p ) => $p['t'] >= $start && ( ! $end || $p['t'] < $end ) );
		$before = $lead ? array_filter( $groups['shown'], fn( $p ) => $p['t'] < $lead ) : [];

		$html = '';
		if ( $before ) {
			$html .= '<div class="ts-photos-block-lead">' . self::grid(
				$trip->ID,
				$before,
				/* translators: %s: number of photos */
				_n( '%s photo taken before the first heading. The trip page shows it above that heading. Add a Trip photos block there to place it.', '%s photos taken before the first heading. The trip page shows them above that heading. Add a Trip photos block there to place them.', count( $before ), 'travel-steps-storyteller' )
			) . '</div>';
		}
		if ( $photos ) {
			/* translators: %s: number of photos */
			$html .= self::grid( $trip->ID, $photos, _n( '%s photo. Pin or hide photos on the trip page.', '%s photos. Pin or hide photos on the trip page.', count( $photos ), 'travel-steps-storyteller' ) );
		} else {
			$empty = Trips::uses_uploads( $trip->ID )
				? __( 'No photos for this place yet. Add photos to the trip.', 'travel-steps-storyteller' )
				: __( 'No photos for this place yet. Favourite some in Immich and sync.', 'travel-steps-storyteller' );
			$html .= '<p class="ts-photos-block-empty">' . esc_html( $empty ) . '</p>';
		}
		return $html;
	}

	/**
	 * Thumbnails of photos, each with its time, then a note, a translated "%s photos…"
	 * given their count. With $taken, a picker: each photo is a button, disabled when
	 * its time is in $taken.
	 *
	 * @param int[]|null $taken
	 */
	private static function grid( int $trip_id, array $photos, string $note, ?array $taken = null ): string {
		$html = '';
		foreach ( $photos as $photo ) {
			$img = sprintf(
				'<img src="%s" alt="%s" data-asset="%s" data-t="%d" loading="lazy">',
				esc_url( RestController::photo_url( $trip_id, $photo['id'], 'thumbnail' ) ),
				esc_attr( $photo['caption'] ?? '' ),
				esc_attr( $photo['id'] ),
				(int) $photo['t']
			);
			if ( null !== $taken ) {
				$img = sprintf(
					'<button type="button" class="ts-photos-block-photo" data-t="%d"%s>%s</button>',
					(int) $photo['t'],
					in_array( (int) $photo['t'], $taken, true ) ? ' disabled' : '',
					$img
				);
			}
			$html .= $img;
		}
		$note  = sprintf( $note, number_format_i18n( count( $photos ) ) );
		$class = null === $taken ? 'ts-photos-block-grid' : 'ts-photos-block-grid ts-photos-block-pick';
		return '<div class="' . $class . '">' . $html . '</div><p class="ts-photos-block-note">' . esc_html( $note ) . '</p>';
	}
}
