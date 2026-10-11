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
}
