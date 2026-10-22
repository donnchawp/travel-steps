<?php
/**
 * Share links: a protected trip opened from a link built from its password, the cover photo
 * a link preview loads through it, the preview tags on trip pages, and the link on the edit
 * page. Required at the end of smoke.php after password-trips.php, in its scope: uses
 * check(), $render, $pw_trip, $pw_set, $pw_enter, $pw_photo and $pw_cover.
 */
use TravelSteps\Trips;

[ $sh_trip ] = $pw_trip( 'Shared Lisbon' );
$pw_set( $sh_trip, 'kerry and clare' );
// The trip page as a visitor without the password sees it, with a share code or none.
$sh_page = function ( $code ) use ( $render, $sh_trip, $pw_enter ) {
	wp_set_current_user( 0 );
	$pw_enter( null );
	if ( null !== $code ) {
		$_GET[ Trips::SHARE_ARG ] = $code;
	}
	$page = $render( 'trip.php', (string) $sh_trip );
	unset( $_GET[ Trips::SHARE_ARG ] );
	return $page;
};
$sh_code = Trips::share_code( get_post( $sh_trip ) );
check( 'share: a protected trip has a 32-character code', 1 === preg_match( '/^[0-9a-f]{32}$/', $sh_code ), $sh_code );
check( '…and its link holds the code, not the password', false !== strpos( Trips::share_url( get_post( $sh_trip ) ), 'share=' . $sh_code ) && false === strpos( Trips::share_url( get_post( $sh_trip ) ), 'kerry' ), Trips::share_url( get_post( $sh_trip ) ) );
$sh_form = $sh_page( null );
$page    = $sh_page( $sh_code );
check( '…opens the trip without the form', false !== strpos( $page, 'Secret story' ) && false === strpos( $page, 'name="post_password"' ) );
check( '…sets core’s cookie to the password, as the form does', ( new PasswordHash( 8, true ) )->CheckPassword( 'kerry and clare', wp_unslash( $_COOKIE[ Trips::password_cookie() ] ?? '' ) ) );
check( '…and gives the page the clean URL to show', false !== strpos( $page, 'data-clean-url="' . esc_url( Trips::url( get_post( $sh_trip ) ) ) . '"' ) );
foreach ( [ 'wrong', '', [ $sh_code ] ] as $sh_bad ) {
	check( 'share: a bad code renders what no code does, byte for byte', $sh_form === $sh_page( $sh_bad ), $sh_bad );
}
[ $sh_other ] = $pw_trip( 'Other Lisbon' );
$pw_set( $sh_other, 'other' );
check( 'share: another trip’s code doesn’t open this one', $sh_form === $sh_page( Trips::share_code( get_post( $sh_other ) ) ) );
$pw_set( $sh_trip, 'a new password' );
check( 'share: after a password change, the old code gets the form', false !== strpos( $sh_page( $sh_code ), 'name="post_password"' ) );
check( '…and the code changes', Trips::share_code( get_post( $sh_trip ) ) !== $sh_code );
foreach ( [ '', '0' ] as $sh_none ) {
	$pw_set( $sh_other, $sh_none );
	check( "share: a trip with password “{$sh_none}” has no link", '' === Trips::share_url( get_post( $sh_other ) ) );
}
$pw_set( $sh_other, 'other' );
wp_update_post( [ 'ID' => $sh_other, 'post_status' => 'draft' ] );
check( 'share: a draft trip has no link', '' === Trips::share_url( get_post( $sh_other ) ) );
// For the later checks: the code from before the password change, the current one, and a second protected trip.
$sh_old_code = $sh_code;
$sh_code     = Trips::share_code( get_post( $sh_trip ) );
[ $sh_other_published ] = $pw_trip( 'Another Lisbon' );
$pw_set( $sh_other_published, 'another' );
