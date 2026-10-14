<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core serves every published post of a show_in_rest type to anyone, ignoring
 * `public => false`. A step is only as visible as its trip, so a published step of a
 * draft or private trip (and its coordinates) stays private.
 */
class StepsRestController extends \WP_REST_Posts_Controller {
	public function check_read_permission( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$trip = $post->post_parent ? get_post( $post->post_parent ) : null;
			if ( ! $trip || ! Trips::can_view( $trip ) ) {
				return false;
			}
		}
		return parent::check_read_permission( $post );
	}

	/**
	 * Core counts a collection (X-WP-Total) before check_read_permission() drops a step
	 * from it, so a search would tell of words in steps the user can't see. Steps of
	 * trips they can't see, or of no trip, are left out of the query instead.
	 */
	protected function prepare_items_query( $prepared_args = [], $request = null ) {
		$hidden = array_merge( Trips::hidden_trip_ids(), Trips::stray_step_parents() );
		return Trips::exclude_from_query( parent::prepare_items_query( $prepared_args, $request ), 'post_parent', $hidden );
	}
}
