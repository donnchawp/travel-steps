<?php
/**
 * The trip edit form's Introduction field: plain text for an intro of paragraphs, a link to the
 * block editor for anything else. Also that the form keeps backslashes typed in it. Required at
 * the end of smoke.php, in its scope: uses check(), $render and $stopped.
 */
use TravelSteps\Trips;

wp_set_current_user( 1 );

$intro_blocks = "<!-- wp:paragraph -->\n<p>A week <a href=\"https://example.com/\">round the west</a>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Then home.</p>\n<!-- /wp:paragraph -->";
$intro_plain  = "A week <a href=\"https://example.com/\">round the west</a>.\n\nThen home.";
$text         = Trips::intro_text( $intro_blocks );
check( 'intro: paragraph blocks show as plain paragraphs, links kept', $intro_plain === $text, $text );
$text = Trips::intro_text( "<p>One.</p>\n<p>Two.</p>" );
check( '…as does classic HTML', "One.\n\nTwo." === $text, $text );
$text = Trips::intro_text( "One.\n\nTwo." );
check( '…and plain text', "One.\n\nTwo." === $text, $text );
check( '…and a line break inside a paragraph is a single newline', "One\ntwo." === Trips::intro_text( "<!-- wp:paragraph -->\n<p>One<br>two.</p>\n<!-- /wp:paragraph -->" ) );
check( '…and an empty intro is empty', '' === Trips::intro_text( '' ) );
$text = Trips::intro_text( "<p>Line<br />\r\nNext</p>\r\n<p>Two</p>" );
check( '…and classic HTML saved with CRLF keeps its line break inside the paragraph', "Line\nNext\n\nTwo" === $text, $text );
$text = Trips::intro_text( "<!-- wp:paragraph -->\n<p>B&amp;B in Dingle &amp;amp; &lt;b&gt;</p>\n<!-- /wp:paragraph -->" );
check( '…and an ampersand shows as typed, other entities as stored', 'B&B in Dingle &amp;amp; &lt;b&gt;' === $text, $text );
$intro_image = $intro_blocks . "\n\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/a.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->";
check( 'intro: an image block means it isn’t plain text', null === Trips::intro_text( $intro_image ) );
check( '…as does a heading', null === Trips::intro_text( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">West</h2>\n<!-- /wp:heading -->" ) );
check( '…or a classic list', null === Trips::intro_text( "<p>One.</p>\n<ul><li>Two</li></ul>" ) );
check( '…or a paragraph with a blank line made of line breaks, which the field would split in two', null === Trips::intro_text( "<!-- wp:paragraph -->\n<p>One<br><br>Two</p>\n<!-- /wp:paragraph -->" ) );
check( '…or a paragraph with settings of its own', null === Trips::intro_text( "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">One.</p>\n<!-- /wp:paragraph -->" ) );

$saved = Trips::intro_content( "A week <a href=\"https://example.com/\">round the west</a>.\r\n\r\nThen\nhome." );
check( 'intro: two paragraphs of text save as two paragraph blocks', [ 'core/paragraph', 'core/paragraph' ] === array_values( array_filter( array_column( parse_blocks( $saved ), 'blockName' ) ) ), $saved );
$text = Trips::intro_text( $saved );
check( '…which read back as the same text', "A week <a href=\"https://example.com/\">round the west</a>.\n\nThen\nhome." === $text, $text );
check( '…without a script', false === strpos( Trips::intro_content( 'Hi <script>alert(1)</script>' ), '<script' ) );
$saved = Trips::intro_content( '<h2>West</h2> of <strong>Ireland</strong>' );
check( '…or a tag the field can’t show again, so a heading typed in stays editable as text', 'West of <strong>Ireland</strong>' === Trips::intro_text( $saved ), $saved );
check( '…and empty text saves an empty intro', '' === Trips::intro_content( " \n\n " ) );
$saved = Trips::intro_content( "<strong>Bold\n\nstill</strong>" );
check( '…and a tag left open is closed within its paragraph', "<strong>Bold</strong>\n\nstill" === Trips::intro_text( $saved ), $saved );
$text = Trips::intro_text( Trips::intro_content( 'B&B' ) );
check( '…and an ampersand typed reads back as typed', 'B&B' === $text, $text );
// A < or > that isn't part of a tag is text: kses would cut "<3 Kerry…" off as a broken tag.
foreach ( [ 'I <3 Kerry and Clare', 'a < b > c', 'x > y, 1 <= 2 << 3' ] as $intro_typed ) {
	$saved = Trips::intro_content( $intro_typed );
	$text  = Trips::intro_text( $saved );
	check( "intro: “{$intro_typed}” is kept, and reads back as typed", $intro_typed === $text, [ $saved, $text ] );
	check( '…and saving it again stores it byte for byte', Trips::intro_content( (string) $text ) === $saved, $saved );
}
check( '…shown on the trip page as typed', false !== strpos( Trips::intro_content( 'I <3 Kerry' ), 'I &lt;3 Kerry' ) );
$saved = "<!-- wp:paragraph -->\n<p>Type &lt;b&gt; for bold</p>\n<!-- /wp:paragraph -->";
check( '…and a tag written out as text in the block editor stays text through the form', 'Type &lt;b&gt; for bold' === Trips::intro_text( $saved ) && $saved === Trips::intro_content( (string) Trips::intro_text( $saved ) ), Trips::intro_text( $saved ) );
foreach ( [ 'more &gt; less', 'x &lt; y' ] as $intro_title ) {
	$saved = "<!-- wp:paragraph -->\n<p>A <abbr title=\"{$intro_title}\">tag</abbr> here</p>\n<!-- /wp:paragraph -->";
	$text  = Trips::intro_text( $saved );
	check( "…and an attribute holding “{$intro_title}” is left as stored, so the tag survives a save", $saved === Trips::intro_content( (string) $text ), [ $text, Trips::intro_content( (string) $text ) ] );
}

// The form and its handler.
$intro_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Intro', 'post_status' => 'draft', 'post_content' => $intro_blocks ] );
$edit       = $render( 'trip-edit.php', (string) $intro_trip );
check( 'edit page: an intro of paragraph blocks shows as text, without block markup', 1 === preg_match( '/<textarea name="intro"[^>]*>([^<]*)</', $edit, $m ) && false === strpos( $m[1], 'wp:paragraph' ) && false !== strpos( $m[1], 'Then home.' ), $m[1] ?? '' );
$save_intro = function ( array $fields ) use ( $stopped, $intro_trip ) {
	$_POST = $_REQUEST = $fields + [ 'trip_id' => $intro_trip, 'title' => 'Intro', 'status' => 'draft', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
	$result = $stopped( [ new Trips(), 'handle_save_trip' ] );
	$_POST  = $_REQUEST = [];
	return $result;
};
check( '…and saving it as shown works', 'redirect' === $save_intro( [ 'intro' => $intro_plain ] ) );
$content = get_post_field( 'post_content', $intro_trip );
check( '…and keeps it as paragraph blocks of the same text', $intro_plain === Trips::intro_text( $content ) && false !== strpos( $content, '<!-- wp:paragraph -->' ), $content );
check( '…and a form without the field leaves the intro alone', 'redirect' === $save_intro( [] ) && $content === get_post_field( 'post_content', $intro_trip ) );
$save_intro( [ 'intro' => wp_slash( 'C:\\Users\\me' ) ] ); // Requests arrive slashed.
$text = Trips::intro_text( get_post_field( 'post_content', $intro_trip ) );
check( '…and a backslash typed in it is kept', 'C:\\Users\\me' === $text, $text );
$save_intro( [ 'intro' => wp_slash( 'I <3 Kerry and Clare' ) ] );
$text = Trips::intro_text( get_post_field( 'post_content', $intro_trip ) );
check( '…as is a < that starts no tag', 'I <3 Kerry and Clare' === $text, $text );
$saved = $save_intro( [ 'title' => wp_slash( 'C:\\Trips' ) ] );
$title = get_post_field( 'post_title', $intro_trip );
check( '…as is one typed in the trip’s title', 'redirect' === $saved && 'C:\\Trips' === $title, $title );
$_POST = $_REQUEST = [ 'trip_id' => 0, 'title' => wp_slash( 'New\\intro' ), 'status' => 'draft', 'intro' => "First.\n\nSecond.", '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
$stopped( [ new Trips(), 'handle_save_trip' ] );
$_POST = $_REQUEST = [];
// WP_Query unslashes its title, so this finds the trip only if the backslash was kept.
$new_trip = get_posts( [ 'post_type' => Trips::TRIP, 'post_status' => 'any', 'title' => wp_slash( 'New\\intro' ), 'fields' => 'ids' ] );
$text     = $new_trip ? Trips::intro_text( get_post_field( 'post_content', $new_trip[0] ) ) : null;
check( 'a new trip saves its introduction as paragraphs', "First.\n\nSecond." === $text, $text );
check( '…and keeps a backslash in its title, with its slug from the title as typed', $new_trip && 'newintro' === get_post_field( 'post_name', $new_trip[0] ), $new_trip ? get_post_field( 'post_name', $new_trip[0] ) : 'no trip titled New\\intro' );
if ( $new_trip ) {
	wp_delete_post( $new_trip[0], true );
}

wp_update_post( [ 'ID' => $intro_trip, 'post_content' => wp_slash( $intro_image ) ] );
$before = get_post_field( 'post_content', $intro_trip );
$edit   = $render( 'trip-edit.php', (string) $intro_trip );
check( 'edit page: an intro with an image has no textarea, and links to the block editor', false === strpos( $edit, 'name="intro"' ) && false !== strpos( $edit, esc_url( get_edit_post_link( $intro_trip, 'raw' ) ) ), substr( $edit, strpos( $edit, 'introduction' ) ?: 0, 600 ) );
check( '…and saving the form, even with an intro sent, leaves it byte for byte', 'redirect' === $save_intro( [ 'intro' => 'Replaced' ] ) && $before === get_post_field( 'post_content', $intro_trip ) );
wp_delete_post( $intro_trip, true );
