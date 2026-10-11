<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WpApp\WpApp;
use WpApp\BaseApp;

class App extends BaseApp {
	const URL_PATH = 'travel-steps';

	public function __construct() {
		$this->app = new WpApp(
			$this->get_template_dir(),
			self::URL_PATH,
			[
				// Published trips are public; editing routes carry their own capability.
				'require_login'       => false,
				'app_name'            => 'Travel Steps',
				'app_name_textdomain' => 'travel-steps',
				'app_icon'            => 'dashicons-location-alt',
				'app_icon_background' => 'linear-gradient(135deg, #11998e, #38ef7d)',
				'app_icon_color'      => '#fff',
			]
		);
	}

	protected function get_template_dir(): string {
		return dirname( __DIR__ ) . '/templates';
	}

	public static function url( string $path = '' ): string {
		return home_url( '/' . self::URL_PATH . '/' . ltrim( $path, '/' ) );
	}

	public function init() {
		// We're already inside init (priority 10), so register directly rather than
		// via nested add_action( 'init', … ) which won't fire reliably.
		Trips::register_post_types();
		PhotosBlock::register();

		$trips = new Trips();
		add_action( 'admin_post_travel_steps_save_trip', [ $trips, 'handle_save_trip' ] );
		add_action( 'admin_post_travel_steps_sync', [ $trips, 'handle_sync' ] );
		add_action( 'admin_post_travel_steps_add_step', [ $trips, 'handle_add_step' ] );
		add_action( 'admin_post_travel_steps_settings', [ Settings::class, 'handle_save' ] );
		add_action( 'rest_api_init', [ new RestController(), 'register_routes' ] );
		add_filter( 'wp_unique_post_slug', [ Trips::class, 'unique_slug' ], 10, 5 );

		parent::init();

		// See cook-app: BaseApp defers setup_menu() to an init callback at the
		// priority we're currently inside, so it would never run.
		$this->setup_menu();
	}

	protected function setup_database(): void {
		// Native WP storage: trip and step post types + post meta.
	}

	protected function setup_routes(): void {
		$this->app->route( 'new', 'trip-edit.php', [], Trips::capability() );
		$this->app->route( 'settings', 'settings.php', [], 'manage_options' );
		$this->app->route( 'trip/{slug}/edit', 'trip-edit.php', [], Trips::capability() );
		$this->app->route( 'trip/{slug}', 'trip.php' );
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( 'trips', __( 'Trips', 'travel-steps' ), self::url() );
		if ( current_user_can( Trips::capability() ) ) {
			$this->app->add_menu_item( 'new', __( 'New trip', 'travel-steps' ), self::url( 'new' ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$this->app->add_menu_item( 'settings', __( 'Settings', 'travel-steps' ), self::url( 'settings' ) );
		}
	}
}
