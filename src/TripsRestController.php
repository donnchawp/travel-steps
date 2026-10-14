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
		return Trips::can_view( $post ) && parent::check_read_permission( $post );
	}

	/**
	 * Core counts a collection (X-WP-Total) before check_read_permission() drops a trip
	 * from it, so a count filtered by slug, date or a word would tell of trips the user
	 * can't see. They're left out of the query instead.
	 */
	protected function prepare_items_query( $prepared_args = [], $request = null ) {
		$args = parent::prepare_items_query( $prepared_args, $request );
		return Trips::exclude_from_query( $args, 'post', Trips::hidden_trip_ids( (array) ( $args['post_status'] ?? [] ) ) );
	}
}
