<?php
/**
 * The trip pages for trips of uploads: the Photos select, the Photos panel, the sync
 * notice and wording by source. Required after upload-trips.php, in smoke.php's scope:
 * uses check(), $render, $stopped, $person, $servers, $status, $trip_by, $alice,
 * $carol, $carols, $alices, $eddie and upload-trips.php's $ut_* helpers.
 */
use TravelSteps\Settings;
use TravelSteps\Trips;

// The "Photos" select's options: value => the rest of the option tag.
$up_choices = function ( string $html ): array {
	if ( ! preg_match( '/<select name="photos">(.*?)<\/select>/s', $html, $select ) ) {
		return [];
	}
	preg_match_all( '/<option value="([^"]*)"([^>]*)>/', $select[1], $options, PREG_SET_ORDER );
	return array_column( array_map( fn( $o ) => [ $o[1], $o[2] ], $options ), 1, 0 );
};
$up_new = function ( int $user ) use ( $render, $servers ): string {
	wp_set_current_user( $user );
	add_filter( 'pre_http_request', $servers, 10, 3 );
	$html = $render( 'trip-edit.php', '' );
	remove_filter( 'pre_http_request', $servers, 10 );
	return $html;
};
$up_required = fn( string $html ) => preg_match_all( '/<input type="date"[^>]*required/', $html );
$up_nokey    = $person( 'nokey', 'author' );

// The "Photos" select, by what the author can use.
$up_html = $up_new( $alice );
$up_opts = $up_choices( $up_html );
check( 'an author with an Immich key: uploads, then all Immich photos, chosen', [ 'uploads', 'immich' ] === array_keys( $up_opts ) && false !== strpos( $up_opts['immich'], 'selected' ), $up_opts );
check( '…the dates are required, with the hint hidden', 2 === $up_required( $up_html ) && 1 === preg_match( '/<small[^>]*ts-dates-hint[^>]*hidden/', $up_html ) );
check( '…and Save says Save', 1 === preg_match( '/class="ts-button ts-save"[^>]*>Save</', $up_html ) );
$up_html = $up_new( $up_nokey );
$up_opts = $up_choices( $up_html );
check( 'an author without a key: only uploads, chosen, and a line about the key', [ 'uploads' ] === array_keys( $up_opts ) && false !== strpos( $up_opts['uploads'], 'selected' ) && false !== strpos( $up_html, 'Add an Immich key in settings' ), $up_opts );
check( '…the dates are optional, with the hint shown', 0 === $up_required( $up_html ) && 1 === preg_match( '/<small[^>]*ts-dates-hint[^>]*>/', $up_html, $up_hint ) && false === strpos( $up_hint[0], 'hidden' ) );
check( '…Save says it adds photos, and there’s no Save and sync', 1 === preg_match( '/class="ts-button ts-save"[^>]*>Save and add photos</', $up_html ) && 1 === preg_match( '/<button[^>]*name="sync"[^>]*hidden/', $up_html ) );
add_filter( 'travel_steps_can_upload', '__return_false' );
$up_html = $up_new( $alice );
$up_opts = $up_choices( $up_html );
check( 'without exif: the uploads option is disabled, with the reason', false !== strpos( $up_opts['uploads'] ?? '', 'disabled' ) && false !== strpos( $up_opts['immich'] ?? '', 'selected' ) && false !== strpos( $up_html, 'Uploading photos needs PHP’s exif extension' ), $up_opts );
$up_html = $up_new( $up_nokey );
check( 'with neither: no select, and a line saying what’s needed', [] === $up_choices( $up_html ) && false !== strpos( $up_html, 'ask your site’s administrator for PHP’s exif extension' ) );
remove_filter( 'travel_steps_can_upload', '__return_false' );

// An Immich trip whose author has no key keeps Immich, so saving it doesn't switch it to uploads.
wp_set_current_user( $carol );
$up_opts = $up_choices( $render( 'trip-edit.php', (string) $carols ) );
check( 'an Immich trip whose author has no key keeps Immich chosen', [ 'uploads', 'immich' ] === array_keys( $up_opts ) && false !== strpos( $up_opts['immich'], 'selected' ), $up_opts );

// Immich can't list albums: the album id stays typed beside "All Immich photos".
$up_offline = $person( 'offline', 'author' );
Settings::update_account( $up_offline, [ 'immich_key' => 'offline-immich' ] + Settings::ACCOUNT_DEFAULTS );
$up_offline_trip = $trip_by( $up_offline );
update_post_meta( $up_offline_trip, Trips::META_ALBUM, '11111111-2222-4333-8444-555555555555' );
wp_set_current_user( $up_offline );
$status = 500;
add_filter( 'pre_http_request', $servers, 10, 3 );
$up_html = $render( 'trip-edit.php', (string) $up_offline_trip );
remove_filter( 'pre_http_request', $servers, 10 );
$status  = 200;
$up_opts = $up_choices( $up_html );
check( 'Immich can’t list albums: all Immich photos chosen, the album id typed beside it', false !== strpos( $up_opts['immich'] ?? '', 'selected' ) && 1 === preg_match( '/name="album"[^>]*value="11111111-2222-4333-8444-555555555555"/', $up_html ) && false !== strpos( $up_html, 'Could not list Immich albums' ), $up_opts );
wp_set_current_user( 1 );
