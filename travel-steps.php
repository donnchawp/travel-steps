<?php
/**
 * Plugin Name: Travel Steps Storyteller
 * Description: A travel journal with photos from Immich or uploaded to the trip, a route from Dawarich or the photos themselves, and a post for each day's story.
 * Version: 0.5.1
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Author: Donncha
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: travel-steps-storyteller
 */

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRAVEL_STEPS_VERSION', '0.5.1' );
define( 'TRAVEL_STEPS_PLUGIN_FILE', __FILE__ );
define( 'TRAVEL_STEPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRAVEL_STEPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Without Composer's dependencies, stand down instead of taking the whole site with us.
if ( ! is_readable( TRAVEL_STEPS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Travel Steps Storyteller is missing its dependencies. Run composer install --no-dev in its folder.', 'travel-steps-storyteller' ) . '</p></div>';
			}
		}
	);
	return;
}
require_once TRAVEL_STEPS_PLUGIN_DIR . 'vendor/autoload.php';

add_action(
	'init',
	function () {
		$app = new App();
		$app->init();
	}
);

register_activation_hook(
	TRAVEL_STEPS_PLUGIN_FILE,
	function () {
		Trips::register_post_types();
		flush_rewrite_rules();
		CacheSweep::schedule();
	}
);

register_deactivation_hook(
	TRAVEL_STEPS_PLUGIN_FILE,
	function () {
		flush_rewrite_rules();
		wp_unschedule_hook( CacheFill::HOOK );
		wp_unschedule_hook( CacheSweep::HOOK );
	}
);
