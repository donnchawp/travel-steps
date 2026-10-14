<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core serves every published post of a show_in_rest type to anyone, and a password
 * only blanks the content: the title, slug and date still show. A trip is only readable
 * here by those Trips::can_view() lets see it, as on the trip page.
 */
class TripsRestController extends \WP_REST_Posts_Controller {
	public function check_read_permission( $post ) {
		if ( ! Trips::can_view( $post ) ) {
			return false;
		}
		return parent::check_read_permission( $post );
	}
}
