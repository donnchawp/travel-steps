<?php
/**
 * The print book: choosing photos on a trip, and printing only those. Required at the end
 * of smoke.php after share-links.php, in its scope: uses check(), row(), $render,
 * $rest_post, $alice, $refused, $pw_set and $pw_enter.
 */
use TravelSteps\RestController;
use TravelSteps\Trips;
use TravelSteps\View;

// A published trip of Alice's with one step in two places, Porto and Braga, three photos in each.
$pb_rows   = [
	row( 61, '2025-05-01', '09:00', 41.1, -8.6, 'Porto', true ),
	row( 62, '2025-05-01', '09:30', 41.1, -8.6, 'Porto', true ),
	row( 63, '2025-05-01', '10:00', 41.1, -8.6, 'Porto', true ),
	row( 64, '2025-05-01', '14:00', 41.5, -8.4, 'Braga', true ),
	row( 65, '2025-05-01', '14:30', 41.5, -8.4, 'Braga', true ),
	row( 66, '2025-05-01', '15:00', 41.5, -8.4, 'Braga', true ),
];
$pb_id     = fn( int $n ) => $pb_rows[ $n - 61 ]['id'];
$pb_place  = fn( string $name, int $t ) => '<!-- wp:heading --><h2 class="wp-block-heading" id="ts-place-' . $t . '">' . $name . '</h2><!-- /wp:heading -->';
$pb_text   = fn( string $text ) => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$pb_story  = $pb_place( 'Porto', $pb_rows[0]['t'] ) . $pb_text( 'Porto story' ) . $pb_place( 'Braga', $pb_rows[3]['t'] ) . $pb_text( 'Braga story' );
$pb_trip   = function ( string $title, ?string $story = null ) use ( $alice, $pb_rows, $pb_story ) {
	wp_set_current_user( 1 );
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $alice ] );
	update_post_meta( $id, Trips::META_START, '2025-05-01' );
	update_post_meta( $id, Trips::META_END, '2025-05-01' );
	update_post_meta( $id, Trips::META_PHOTOS, wp_slash( wp_json_encode( $pb_rows ) ) );
	$step = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $id, 'post_title' => 'North of Portugal', 'post_status' => 'publish', 'post_author' => $alice, 'post_date' => '2025-05-01 12:00:00', 'post_content' => wp_slash( $story ?? $pb_story ) ] );
	return [ $id, $step ];
};
$pb_friend = wp_insert_user( [ 'user_login' => 'pb_friend', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
// Changes the selection as the trip page does, as an admin unless told otherwise.
$pb_do = function ( int $trip, string $asset, string $action, int $user = 1 ) use ( $rest_post ) {
	wp_set_current_user( $user );
	return $rest_post( "/travel-steps/v1/trips/$trip/print-photo", [ 'asset' => $asset, 'action' => $action ] );
};

[ $pb_a, $pb_a_step ] = $pb_trip( 'Printed Porto' );

// Choosing: who may, and what.
$pb_res = $pb_do( $pb_a, $pb_id( 61 ), 'add' );
check( 'print: an editor chooses a photo', 200 === $pb_res->get_status() && [ $pb_id( 61 ) ] === Trips::print_ids( $pb_a ), [ $pb_res->get_status(), $pb_res->get_data() ] );
check( '…a subscriber may not', $refused( $pb_do( $pb_a, $pb_id( 62 ), 'add', $pb_friend )->get_status() ) && [ $pb_id( 61 ) ] === Trips::print_ids( $pb_a ) );
check( '…nor a logged-out visitor', $refused( $pb_do( $pb_a, $pb_id( 62 ), 'add', 0 )->get_status() ) && [ $pb_id( 61 ) ] === Trips::print_ids( $pb_a ) );
$pb_res = $pb_do( $pb_a, '99999999-9999-4999-8999-999999999999', 'add' );
check( '…only a photo of the trip', 400 === $pb_res->get_status() && 'travel_steps_not_in_trip' === $pb_res->as_error()->get_error_code() && [ $pb_id( 61 ) ] === Trips::print_ids( $pb_a ), $pb_res->get_data() );
$pb_do( $pb_a, $pb_id( 61 ), 'add' );
check( '…and only once', [ $pb_id( 61 ) ] === Trips::print_ids( $pb_a ), Trips::print_ids( $pb_a ) );
$pb_do( $pb_a, $pb_id( 62 ), 'add' );
$pb_do( $pb_a, $pb_id( 62 ), 'lead' );
check( 'print: make lead moves a photo to the front', [ $pb_id( 62 ), $pb_id( 61 ) ] === Trips::print_ids( $pb_a ), Trips::print_ids( $pb_a ) );

// Choosing changes nothing visitors see.
check( 'print: choosing doesn’t pin or hide', [] === Trips::pinned( $pb_a ) && [] === Trips::hidden( $pb_a ), [ Trips::pinned( $pb_a ), Trips::hidden( $pb_a ) ] );
wp_set_current_user( 0 );
$pb_seen = $render( 'trip.php', (string) $pb_a );
[ $pb_b ] = $pb_trip( 'Unprinted Porto' );
wp_set_current_user( 0 );
$pb_plain = $render( 'trip.php', (string) $pb_b );
$pb_assets = fn( string $page ) => array_values( array_filter( array_column( $pb_rows, 'id' ), fn( $n ) => false !== strpos( $page, $n ) ) );
check( '…and a visitor’s trip page has the same photos as a trip with none chosen', $pb_assets( $pb_seen ) === $pb_assets( $pb_plain ) && 6 === count( $pb_assets( $pb_seen ) ), $pb_assets( $pb_seen ) );

// Removing: chosen out of time order, the next chosen leads.
[ $pb_c ] = $pb_trip( 'Reordered Porto' );
foreach ( [ 63, 62, 61, 64 ] as $pb_n ) {
	$pb_do( $pb_c, $pb_id( $pb_n ), 'add' );
}
$pb_do( $pb_c, $pb_id( 63 ), 'remove' );
check( 'print: removing a lead makes the next chosen the lead', [ $pb_id( 62 ), $pb_id( 61 ), $pb_id( 64 ) ] === Trips::print_ids( $pb_c ), Trips::print_ids( $pb_c ) );
wp_set_current_user( 0 );

// The print page. Everything after the cover, so its photo doesn't count.
$pb_print = function ( int $trip, int $user = 1 ) use ( $render ) {
	wp_set_current_user( $user );
	return $render( 'trip-print.php', (string) $trip );
};
$pb_body = function ( string $page ) {
	$cover = strpos( $page, 'ts-print-cover' );
	return false === $cover ? $page : substr( $page, (int) strpos( $page, '</header>', $cover ) );
};
$pb_choose = function ( int $trip, array $numbers ) use ( $pb_do, $pb_id ) {
	foreach ( $numbers as $n ) {
		$pb_do( $trip, $pb_id( $n ), 'add' );
	}
};
$pb_has = fn( string $page, int $n ) => false !== strpos( $page, $pb_id( $n ) );

// Nothing chosen.
[ $pb_e ] = $pb_trip( 'Empty Porto' );
$pb_page  = $pb_print( $pb_e );
check( 'book: nothing chosen, an editor gets the cover and a note', false !== strpos( $pb_page, 'Empty Porto' ) && false !== strpos( $pb_page, 'No photos are chosen for printing yet.' ) && false === strpos( $pb_page, 'ts-book-photos' ) && false !== strpos( $pb_page, 'href="' . esc_url( Trips::url( get_post( $pb_e ) ) ) . '"' ) );
check( '…a logged-in visitor gets no note', false === strpos( $pb_print( $pb_e, $pb_friend ), 'No photos are chosen' ) );

// One place chosen: the other is left out, text and all.
[ $pb_f ] = $pb_trip( 'Half Porto' );
$pb_choose( $pb_f, [ 64, 65, 66 ] );
$pb_page = $pb_body( $pb_print( $pb_f ) );
check( 'book: a place with photos chosen prints', false !== strpos( $pb_page, 'Braga story' ) && $pb_has( $pb_page, 64 ) );
check( '…a place with none is left out, text and all', false === strpos( $pb_page, 'Porto story' ) && ! $pb_has( $pb_page, 61 ) );
check( '…its photos are previews', false !== strpos( $pb_page, esc_url( RestController::photo_url( $pb_f, $pb_id( 64 ), 'preview' ) ) ) && false === strpos( $pb_page, esc_url( RestController::photo_url( $pb_f, $pb_id( 64 ), 'medium' ) ) ) );
check( '…three photos: the lead alone, then two stacked', 1 === preg_match( '#ts-book-photos is-landscape.*ts-book-photos is-stack#s', $pb_page ), $pb_page );
[ $pb_q ] = $pb_trip( 'Ordered Porto' );
$pb_choose( $pb_q, [ 66, 65, 64 ] );
$pb_order = $pb_body( $pb_print( $pb_q ) );
check( '…printing in the order they were chosen, not by time', strpos( $pb_order, $pb_id( 66 ) ) < strpos( $pb_order, $pb_id( 65 ) ) && strpos( $pb_order, $pb_id( 65 ) ) < strpos( $pb_order, $pb_id( 64 ) ) );
check( '…no map or photo tools', false === strpos( $pb_page, 'ts-map' ) && false === strpos( $pb_page, 'ts-photo-tools' ) );
$pb_page = $pb_print( $pb_f, $pb_friend );
check( '…a logged-in visitor gets the same book', $pb_has( $pb_body( $pb_page ), 64 ) && false === strpos( $pb_page, 'No photos are chosen' ) );

// A step with nothing chosen is left out altogether, title included.
wp_set_current_user( 1 );
[ $pb_g ] = $pb_trip( 'Titled Porto' );
$pb_choose( $pb_g, [ 61, 64 ] );
check( 'book: a step’s title prints once, though two of its places do', 1 === substr_count( $pb_body( $pb_print( $pb_g ) ), 'North of Portugal' ) );
$pb_do( $pb_g, $pb_id( 61 ), 'remove' );
$pb_do( $pb_g, $pb_id( 64 ), 'remove' );
check( '…and not at all if none do', 0 === substr_count( $pb_body( $pb_print( $pb_g ) ), 'North of Portugal' ) );

// Photos from before the first place heading: the title still comes first.
[ $pb_h ] = $pb_trip( 'Early Porto', $pb_place( 'Braga', $pb_rows[3]['t'] ) . $pb_text( 'Braga story' ) );
$pb_choose( $pb_h, [ 61 ] );
$pb_page = $pb_body( $pb_print( $pb_h ) );
check( 'book: photos before the first heading print after the step’s title', false !== strpos( $pb_page, 'North of Portugal' ) && strpos( $pb_page, 'North of Portugal' ) < strpos( $pb_page, $pb_id( 61 ) ) && false === strpos( $pb_page, 'Braga story' ) );

// A later section with no heading of its own or text has no text page.
[ $pb_i ] = $pb_trip( 'Nested Porto', $pb_place( 'Porto', $pb_rows[0]['t'] ) . $pb_text( 'Porto story' ) . '<!-- wp:group --><div class="wp-block-group">' . $pb_place( 'Braga', $pb_rows[3]['t'] ) . '</div><!-- /wp:group -->' );
$pb_choose( $pb_i, [ 61, 64 ] );
$pb_page = $pb_body( $pb_print( $pb_i ) );
check( 'book: a later section with no text skips its text page', 1 === substr_count( $pb_page, 'class="ts-book-text' ) && $pb_has( $pb_page, 61 ) && $pb_has( $pb_page, 64 ), substr_count( $pb_page, 'class="ts-book-text' ) );

// Who may open it.
$pb_page = $pb_print( $pb_f, 0 );
check( 'book: a logged-out visitor gets not found', false !== strpos( $pb_page, 'Trip not found' ) && ! $pb_has( $pb_page, 64 ) );
[ $pb_j ] = $pb_trip( 'Locked Porto' );
$pb_choose( $pb_j, [ 61 ] );
$pw_set( $pb_j, 'douro' );
$pw_enter( null );
$pb_name = Trips::share_cookie( get_post( $pb_j ) );
$pb_code = Trips::share_code( get_post( $pb_j ) );
$pb_page = $pb_print( $pb_j, 0 );
check( '…on a protected trip too, before the password form', false !== strpos( $pb_page, 'Trip not found' ) && false === strpos( $pb_page, 'name="post_password"' ) );
$_GET[ Trips::SHARE_ARG ] = $pb_code;
$pb_page = $pb_print( $pb_j, 0 );
unset( $_GET[ Trips::SHARE_ARG ] );
check( '…or with a share code, which sets no cookie', false !== strpos( $pb_page, 'Trip not found' ) && ! $pb_has( $pb_page, 61 ) && ! isset( $_COOKIE[ $pb_name ] ) );
$pb_page = $pb_print( $pb_j, $pb_friend );
check( 'book: someone logged in who may not skip the password gets the form', false !== strpos( $pb_page, 'name="post_password"' ) && ! $pb_has( $pb_page, 61 ) && false !== strpos( $pb_page, 'action="' . esc_url( Trips::url( get_post( $pb_j ), 'print' ) ) . '"' ) );
wp_set_current_user( $pb_friend );
[ $pb_to, $pb_cookie ] = $pw_post( $pb_j, 'douro', [], 'trip-print.php' );
$pb_print_url = Trips::url( get_post( $pb_j ), 'print' );
check( '…which comes back to the print version, flagged, with core’s cookie', add_query_arg( 'tried', '1', $pb_print_url ) === $pb_to && null !== $pb_cookie, [ $pb_to, $pb_cookie ] );
$pw_keep( $pb_cookie );
$_GET  = [ 'tried' => '1' ];
$pb_to = $pw_redirect( $pb_j, $pb_ignored, 'trip-print.php' );
$_GET  = [];
check( '…whose flagged URL drops the flag and stays there', $pb_print_url === $pb_to, $pb_to );
check( '…to show the book', $pb_has( $pb_body( $pb_print( $pb_j, $pb_friend ) ), 61 ) );
$pw_enter( null );
wp_set_current_user( $pb_friend );
$_GET[ Trips::SHARE_ARG ] = $pb_code;
$pb_page = $render( 'trip-print.php', (string) $pb_j );
unset( $_GET[ Trips::SHARE_ARG ], $_COOKIE[ $pb_name ] );
header_remove( 'Set-Cookie' );
check( 'book: logged in with a share code, it takes the code out of its address', $pb_has( $pb_body( $pb_page ), 61 ) && false !== strpos( $pb_page, 'data-clean-url="' . esc_url( $pb_print_url ) . '"' ) );

// Hidden after being chosen: stops printing, and comes back in its old place.
[ $pb_k ] = $pb_trip( 'Hidden Porto' );
$pb_choose( $pb_k, [ 62, 61 ] );
$rest_post( "/travel-steps/v1/trips/$pb_k/photo-state", [ 'asset' => $pb_id( 62 ), 'state' => 'hidden' ] );
$pb_page = $pb_body( $pb_print( $pb_k ) );
check( 'book: a hidden photo doesn’t print', ! $pb_has( $pb_page, 62 ) && $pb_has( $pb_page, 61 ) );
$rest_post( "/travel-steps/v1/trips/$pb_k/photo-state", [ 'asset' => $pb_id( 62 ), 'state' => 'default' ] );
$pb_page = $pb_body( $pb_print( $pb_k ) );
check( '…and unhidden, it’s back as the lead', $pb_has( $pb_page, 62 ) && strpos( $pb_page, $pb_id( 62 ) ) < strpos( $pb_page, $pb_id( 61 ) ) );

// A photo that left the trip is ignored.
[ $pb_l ] = $pb_trip( 'Synced Porto' );
$pb_choose( $pb_l, [ 62, 61 ] );
update_post_meta( $pb_l, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_values( array_filter( $pb_rows, fn( $r ) => $r['id'] !== $pb_id( 62 ) ) ) ) ) );
$pb_page = $pb_body( $pb_print( $pb_l ) );
check( 'book: a chosen photo no longer in the trip is ignored', ! $pb_has( $pb_page, 62 ) && $pb_has( $pb_page, 61 ) );

// A place heading added after choosing: photos move to their new sections.
[ $pb_m, $pb_m_step ] = $pb_trip( 'Regrouped Porto', $pb_place( 'Porto', $pb_rows[0]['t'] ) . $pb_text( 'Porto story' ) );
$pb_choose( $pb_m, [ 64, 61 ] );
wp_update_post( [ 'ID' => $pb_m_step, 'post_content' => wp_slash( $pb_story ) ] );
$pb_page = $pb_body( $pb_print( $pb_m ) );
check( 'book: a heading added later moves photos into their new place', strpos( $pb_page, 'Porto story' ) < strpos( $pb_page, $pb_id( 61 ) ) && strpos( $pb_page, $pb_id( 61 ) ) < strpos( $pb_page, 'Braga story' ) && strpos( $pb_page, 'Braga story' ) < strpos( $pb_page, $pb_id( 64 ) ), $pb_page );

// An unpublished step's chosen photos.
[ $pb_n ] = $pb_trip( 'Draft Porto' );
$pb_late  = row( 67, '2025-05-02', '09:00', 41.1, -8.6, 'Porto', true );
update_post_meta( $pb_n, Trips::META_END, '2025-05-02' );
update_post_meta( $pb_n, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_merge( $pb_rows, [ $pb_late ] ) ) ) );
wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $pb_n, 'post_title' => 'Draft day', 'post_status' => 'draft', 'post_author' => $alice, 'post_date' => '2025-05-02 12:00:00', 'post_content' => wp_slash( $pb_text( 'Draft story' ) ) ] );
$pb_do( $pb_n, $pb_late['id'], 'add' );
check( 'book: an editor sees an unpublished step’s chosen photo', false !== strpos( $pb_body( $pb_print( $pb_n ) ), $pb_late['id'] ) );
$pb_page = $pb_body( $pb_print( $pb_n, $pb_friend ) );
check( '…a logged-in visitor doesn’t', false === strpos( $pb_page, $pb_late['id'] ) && false === strpos( $pb_page, 'Draft story' ) );
wp_set_current_user( 0 );

// The trip page: choosing photos, and who gets its Print button.
[ $pb_o ] = $pb_trip( 'Chosen Porto' );
$pb_spare = row( 68, '2025-05-01', '16:00', 41.5, -8.4, 'Braga', false );
update_post_meta( $pb_o, Trips::META_PHOTOS, wp_slash( wp_json_encode( array_merge( $pb_rows, [ $pb_spare ] ) ) ) );
wp_set_current_user( 1 );
$rest_post( "/travel-steps/v1/trips/$pb_o/photo-state", [ 'asset' => $pb_id( 66 ), 'state' => 'hidden' ] );
$pb_do( $pb_o, $pb_id( 62 ), 'add' );
$pb_do( $pb_o, $pb_id( 61 ), 'add' );
wp_set_current_user( 1 );
$pb_page   = $render( 'trip.php', (string) $pb_o );
$pb_figure = function ( string $page, string $asset ) {
	return preg_match( '#<figure[^>]*data-asset="' . preg_quote( $asset, '#' ) . '".*?</figure>#s', $page, $m ) ? $m[0] : '';
};
check( 'trip page: an editor gets a Print toggle on a shown photo', false !== strpos( $pb_figure( $pb_page, $pb_id( 61 ) ), 'ts-photo-print' ) );
check( '…and on one behind “Show more”', false !== strpos( $pb_figure( $pb_page, $pb_spare['id'] ), 'ts-photo-print' ) );
check( '…not on a hidden one', '' !== $pb_figure( $pb_page, $pb_id( 66 ) ) && false === strpos( $pb_figure( $pb_page, $pb_id( 66 ) ), 'ts-photo-print' ) );
check( '…labelled Print, with Make lead beside it', false !== strpos( $pb_figure( $pb_page, $pb_id( 61 ) ), '>Print<' ) && false !== strpos( $pb_figure( $pb_page, $pb_id( 61 ) ), '>Make lead<' ) );
$pb_section = fn( string $figure ) => preg_match( '#data-print-section="([^"]+)"#', $figure, $m ) ? $m[1] : '';
check( '…photos of one place share a section', '' !== $pb_section( $pb_figure( $pb_page, $pb_id( 61 ) ) ) && $pb_section( $pb_figure( $pb_page, $pb_id( 61 ) ) ) === $pb_section( $pb_figure( $pb_page, $pb_id( 62 ) ) ) );
check( '…another place’s don’t', $pb_section( $pb_figure( $pb_page, $pb_id( 61 ) ) ) !== $pb_section( $pb_figure( $pb_page, $pb_id( 64 ) ) ) && $pb_section( $pb_figure( $pb_page, $pb_id( 64 ) ) ) === $pb_section( $pb_figure( $pb_page, $pb_spare['id'] ) ) );
check( '…and the page says how many work best', false !== strpos( $render( 'trip.php', (string) $pb_o ), 'data-print-best="' . \TravelSteps\PrintLayout::BEST . '"' ) && false !== strpos( $render( 'trip.php', (string) $pb_o ), '%s, best with 5' ) );
check( '…and the page holds the chosen list, in order', false !== strpos( $pb_page, 'data-print-ids="' . esc_attr( wp_json_encode( [ $pb_id( 62 ), $pb_id( 61 ) ] ) ) . '"' ) );
wp_set_current_user( 0 );
$pb_page = $render( 'trip.php', (string) $pb_o );
check( '…a visitor gets none of that', false === strpos( $pb_page, 'ts-photo-print' ) && false === strpos( $pb_page, 'data-print-ids' ) && false === strpos( $pb_page, 'data-print-section' ) );
wp_set_current_user( $pb_friend );
$pb_page = $render( 'trip.php', (string) $pb_o );
check( '…nor does someone logged in who can’t edit', false === strpos( $pb_page, 'ts-photo-print' ) && false === strpos( $pb_page, 'data-print-ids' ) );

$pb_button = function ( int $trip, int $user ) use ( $render ) {
	wp_set_current_user( $user );
	return false !== strpos( $render( 'trip.php', (string) $trip ), 'href="' . esc_url( Trips::url( get_post( $trip ), 'print' ) ) . '"' );
};
check( 'Print button: a logged-out visitor gets none', ! $pb_button( $pb_o, 0 ) );
check( '…a logged-in visitor gets it once photos are chosen', $pb_button( $pb_o, $pb_friend ) );
check( '…but not before', ! $pb_button( $pb_e, $pb_friend ) );
check( '…an editor always does', $pb_button( $pb_e, 1 ) );
check( '…nor if only an unpublished step has them', ! $pb_button( $pb_n, $pb_friend ) && $pb_button( $pb_n, 1 ) );
[ $pb_h ] = $pb_trip( 'Hidden Porto' );
$pb_do( $pb_h, $pb_id( 61 ), 'add' );
$rest_post( "/travel-steps/v1/trips/$pb_h/photo-state", [ 'asset' => $pb_id( 61 ), 'state' => 'hidden' ] );
check( '…nor if the only photo chosen is hidden', ! $pb_button( $pb_h, $pb_friend ) && $pb_button( $pb_h, 1 ) );
wp_set_current_user( 0 );
$_GET[ Trips::SHARE_ARG ] = $pb_code;
$pb_page = $render( 'trip.php', (string) $pb_j );
unset( $_GET[ Trips::SHARE_ARG ], $_COOKIE[ $pb_name ] );
header_remove( 'Set-Cookie' );
check( '…nor does a logged-out visitor with a share link', ( false !== strpos( $pb_page, 'Secret' ) || false !== strpos( $pb_page, 'Porto story' ) ) && false === strpos( $pb_page, 'href="' . esc_url( $pb_print_url ) . '"' ) );
wp_set_current_user( 0 );
