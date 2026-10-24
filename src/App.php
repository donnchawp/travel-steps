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
		Settings::migrate();
		// Activation hooks don't run on update, so the first request after one moves the cache.
		RestController::migrate_cache();
		PhotosBlock::register();
		Uploads::register();
		Subscriptions::register();

		$trips = new Trips();
		add_action( 'admin_post_travel_steps_save_trip', [ $trips, 'handle_save_trip' ] );
		add_action( 'admin_post_travel_steps_sync', [ $trips, 'handle_sync' ] );
		add_action( 'admin_post_travel_steps_add_step', [ $trips, 'handle_add_step' ] );
		add_action( 'admin_post_travel_steps_account', [ Settings::class, 'handle_save_account' ] );
		add_action( 'admin_post_travel_steps_settings', [ Settings::class, 'handle_save_site' ] );
		add_action( 'rest_api_init', [ new RestController(), 'register_routes' ] );
		// Under the app's path, so whatever lets visitors reach trips lets them reach their photos.
		add_action( 'parse_request', [ RestController::class, 'maybe_serve_photo' ] );
		add_action( CacheFill::HOOK, [ CacheFill::class, 'run' ] );
		add_action( CacheSweep::HOOK, [ CacheSweep::class, 'run' ] );
		CacheSweep::schedule();
		add_action( Roundup::HOOK, [ Roundup::class, 'run' ] );
		Roundup::schedule();
		// A new timezone moves the hour the roundup goes at.
		add_action( 'update_option_timezone_string', [ Roundup::class, 'reschedule' ], 10, 0 );
		add_action( 'update_option_gmt_offset', [ Roundup::class, 'reschedule' ], 10, 0 );
		add_action( 'deleted_user', [ Subscriptions::class, 'delete_for_user' ] );
		add_action( 'admin_post_travel_steps_remove_subscriber', [ Subscriptions::class, 'handle_remove' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ Subscriptions::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ Subscriptions::class, 'register_eraser' ] );
		add_filter( 'wp_unique_post_slug', [ Trips::class, 'unique_slug' ], 10, 5 );
		add_filter( 'wp_insert_post_data', [ Trips::class, 'step_parent' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ Trips::class, 'draft_slug' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ Trips::class, 'step_password' ] );
		add_filter( 'protected_title_format', [ Trips::class, 'protected_title_format' ], 10, 2 );
		add_filter( 'post_password_required', [ Trips::class, 'unlocked' ], 10, 2 );
		add_action( 'admin_init', [ self::class, 'suggest_privacy_policy' ] );
		add_action( 'post_updated', [ Subscriptions::class, 'password_changed' ], 10, 3 );
		add_action( 'before_delete_post', [ Subscriptions::class, 'delete_for_trip' ] );
		add_action( 'wp_app_before_render', [ self::class, 'frame_options' ], 10, 2 );

		parent::init();

		// See cook-app: BaseApp defers setup_menu() to an init callback at the
		// priority we're currently inside, so it would never run.
		$this->setup_menu();
	}

	/**
	 * The app's pages have one-click controls (viewers, pin, hide, cover), so another
	 * site mustn't frame them; the site itself may, as OpenStation's windows do.
	 * wp_app_before_render runs for every wp-app app, so only this one's routes.
	 */
	public static function frame_options( $template, $route = [] ): void {
		if ( self::URL_PATH === ( $route['app_path'] ?? '' ) ) {
			send_frame_options_header();
		}
	}

	/**
	 * Text for Settings → Privacy, which site owners can copy into their privacy policy.
	 */
	public static function suggest_privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Trip pages show a map whose background images come from the OpenStreetMap Foundation’s tile servers. When you view a trip, your browser requests those images directly, so the OpenStreetMap Foundation receives your IP address, browser details, the address of the trip page and the part of the map you look at.', 'travel-steps' ) . '</p>';
		$text .= '<p>' . sprintf(
			/* translators: %s: link to the OpenStreetMap Foundation privacy policy */
			esc_html__( 'See the OpenStreetMap Foundation’s privacy policy: %s', 'travel-steps' ),
			'<a href="https://osmfoundation.org/wiki/Privacy_Policy">https://osmfoundation.org/wiki/Privacy_Policy</a>'
		) . '</p>';
		$text .= '<p>' . esc_html__( 'Photos on trip pages are served by this site. Published trips show the route travelled, with the area around the author’s home left out.', 'travel-steps' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Visitors can subscribe to a trip to get an email on days when something is added to it. This site keeps their email address, or for a logged-in visitor their account, until they unsubscribe or the trip’s author removes them, and sends the emails through this site’s own mail setup. A subscription that isn’t confirmed from the emailed link is deleted after 7 days, and changing a trip’s password deletes the subscriptions made with an email address.', 'travel-steps' ) . '</p>';
		$text .= '<p>' . esc_html__( 'People who make trips store their own Immich and Dawarich API keys and home location on this site. They are used only to fetch that person’s photos and location history for their trips, and nobody else can see them.', 'travel-steps' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Travel Steps', 'travel-steps' ), wp_kses_post( $text ) );
	}

	protected function setup_database(): void {
		// Native WP storage: trip and step post types + post meta.
	}

	protected function setup_routes(): void {
		$this->app->route( 'new', 'trip-edit.php', [], Trips::capability() );
		$this->app->route( 'settings', 'settings.php', [], Trips::capability() );
		$this->app->route( 'trip/{slug}/edit', 'trip-edit.php', [], Trips::capability() );
		$this->app->route( 'trip/{slug}', 'trip.php' );
		// Confirm and unsubscribe links in emails: they work whatever the trip's status.
		$this->app->route( 'subscription', 'subscription.php' );
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( 'trips', __( 'Trips', 'travel-steps' ), self::url() );
		if ( current_user_can( Trips::capability() ) ) {
			$this->app->add_menu_item( 'new', __( 'New trip', 'travel-steps' ), self::url( 'new' ) );
			$this->app->add_menu_item( 'settings', __( 'Settings', 'travel-steps' ), self::url( 'settings' ) );
		}
	}
}
