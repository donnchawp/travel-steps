<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core lists every term of a show_in_rest taxonomy to anyone. Place names from draft
 * trips shouldn't be public, so only people who can edit posts may read them.
 */
class PlacesRestController extends \WP_REST_Terms_Controller {
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'edit_posts' ) ? parent::get_items_permissions_check( $request ) : $this->forbidden();
	}

	public function get_item_permissions_check( $request ) {
		return current_user_can( 'edit_posts' ) ? parent::get_item_permissions_check( $request ) : $this->forbidden();
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'travel-steps-storyteller' ), [ 'status' => rest_authorization_required_code() ] );
	}
}
