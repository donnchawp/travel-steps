# Uploaded trip photos, PR 2 (trip page, modal, docs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a trip's author choose uploads as its photo source on the trip page, add and delete photos through the WordPress media modal, sync when the modal closes, and see what sync left out; with wording, settings and docs that make Immich optional.

**Architecture:** One "Photos" select (`photos` = `uploads`, `immich` or an Immich album id) replaces the album select; `View::photo_choices()` decides its options from the author's Immich key and the site's `exif` support. An upload trip's edit page gets a Photos panel whose button opens a `wp.media` frame (`assets/travel-steps-edit.js`) that uploads with `Uploads::REQUEST_FLAG`, records adds and deletes, and on close submits the sync form (author) or reloads with a notice (other editors). `run_sync_and_redirect()` passes PR 1's left-out counts to the edit page's notice.

**Tech Stack:** PHP 8.0+, WordPress 6.7+ on akirk/wp-app, `wp.media` (Backbone), plain JS, WordPress Playground smoke tests (`make smoke`).

**Spec:** `docs/superpowers/specs/2026-10-12-uploaded-trip-photos-design.md`, section "PR 2". PR 1 (#21) is merged; its data layer is used as is.

## Probe result (the spec's first task, done before this plan)

On `make demo`, with `wp_enqueue_media( [ 'post' => $trip_id ] )` in `templates/trip-edit.php`:

- The media scripts, styles and footer templates survive `wp_app_dequeue_theme_assets()`: it dequeues only theme-sourced assets and three block-style handles.
- The modal opened; an upload with `post_id` and `travel_steps_photo` uploader params went through `async-upload.php`, became a trip photo (proxy URL in the details, `status: private`) and its thumbnail loaded through the photo proxy (200). "Delete permanently" (`model.destroy()`) removed it; `close` fired.
- Two problems, fixed in Task 3:
  1. **New uploads don't appear in the grid.** `wp.media.model.Query` only observes `wp.Uploader.queue` when every query arg is in core's allowlist; `travel_steps_photo` isn't. Add each finished upload to the library by hand.
  2. **Invisible text in dark mode.** The page's `body { color: var(--ts-ink) }` (light in dark mode) is inherited by the white modal: its title, "Drop files", labels and the inactive tab are near-white on white.
- The "Done" button is disabled until something is selected; pass `requires: { selection: false }`.

## Global Constraints

- WordPress 6.7 or later (`Requires at least: 6.7`); PHP 8.0 or later.
- Only the trip's author changes its dates or photos (source or album) or syncs it (#17): a non-author request with `start`, `end`, `album`, `photos` or `sync` gets the existing 403.
- Immich trips behave as before; only the form field carrying the album changes (`photos` instead of `album`, except the typed album id when Immich can't list albums).
- Nothing handed to the browser names a trip photo's file (the modal already gets proxy URLs from `Uploads::prepare_for_js()`).
- Copy comes verbatim from the spec where it gives it; British spelling ("favourite", "colour"); curly apostrophes (’) in user-facing strings, as the code base uses.
- Commit subjects: an imperative sentence ending with "(#6)". Every commit message ends with `Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE`.
- Tests: smoke checks via `make smoke` (about 3 minutes; prints `ok`/`FAIL` lines and a total). New checks go in `tests/playground/upload-pages.php`, which runs last. Delete any trip you create that would add steps other checks count.
- Run `make smoke 2>&1 | grep -E 'FAIL|ok, '` to see failures and the total. A run that ends "No results: the smoke script didn't finish" is a PHP crash: look at the output above it.

## Deviations from the spec (decided here; flag them in the PR)

1. **Field name.** The select posts `photos` (`uploads`, `immich`, or an album id), replacing PR 1's `source` field and the album select's `album`. When Immich can't list albums, a text field `album` beside the select still takes a typed album id (as today). A form without `photos` leaves the source and album alone.
2. **"Can use Immich" is "has a key".** `Settings::immich_url()` always returns a URL (it falls back to `http://immich_server:2283`), so "the site has an Immich URL" is always true. The settings notice for "neither Immich nor exif" becomes a notice when the site has no `exif`: that is the case an admin can act on.
3. **Keyless author, Immich trip.** An existing Immich trip whose author has no key keeps its Immich choice in the select (selected), so saving the form doesn't silently switch it to uploads, the only other option.
4. **New trip of uploads.** "Save and sync" is hidden: there is nothing to sync until photos are added.
5. **Non-author notice** after closing the modal: "The trip’s photos changed. %s needs to sync it to update its steps." (the spec's "Photos added." is wrong after a deletion).
6. **No exif, existing upload trip.** Its Photos panel shows the count and the reason, without the Add photos button (the prefilter would refuse every upload).

## Review Focus

1. An Immich trip whose author has no Immich key is saved from the edit page: it must stay on Immich, not flip to "Photos uploaded to this trip". → Task 2 check "an Immich trip whose author has no key keeps Immich chosen", and Task 1 check that `photos=immich` keeps the album.
2. Immich is down when an album trip's author opens the edit page: the select must keep "All Immich photos" selected with the album id typed beside it, so saving keeps the album. → Task 2 check "Immich can’t list albums…".
3. A refused upload (not a photo) in the modal must not count as a change, so closing doesn't sync. → Task 3's JS counts only uploads that finish with an id; Task 8 browser step uploads a non-photo and closes.
4. Reloading the edit page after `?add=1` must not reopen the modal. → Task 3's JS removes `add` from the URL with `history.replaceState`; Task 8 browser step reloads.
5. A non-author editor of an upload trip adds photos: they get the button but no sync form, and closing reloads with the notice naming the author, never a 403. → Task 3 check "another editor can add photos but has no sync form…".

## Files

- Modify `src/Trips.php`: `can_use_immich()`, the `photos` field in `handle_save_trip()`, `?add=1` after creating a trip of uploads, left-out counts in `run_sync_and_redirect()`.
- Modify `src/Uploads.php`: `available()` (exif, filterable), used by the prefilter.
- Modify `src/View.php`: `photo_choices()`.
- Modify `templates/trip-edit.php`: the select, dates hint, Save labels, Photos panel, media enqueue, sync-form class, notices, wording.
- Create `assets/travel-steps-edit.js`: the select's behaviour and the media modal.
- Modify `assets/travel-steps.css`: `[hidden]`, the modal's colour, the dates hint.
- Modify `templates/trip.php`, `src/PhotosBlock.php`, `templates/settings.php`: wording by source.
- Modify `README.md`, `readme.txt`.
- Tests: modify `tests/playground/upload-trips.php` (source switching posts `photos`; `$ut_location` helper), `tests/playground/smoke.php` (three album-select checks; require the new file); create `tests/playground/upload-pages.php`.

---

### Task 1: The `photos` field, and opening the modal after creating a trip of uploads

**Files:**
- Modify: `src/Trips.php:354-411` (`handle_save_trip()`)
- Test: `tests/playground/upload-trips.php:52-65` (the "Only the author changes the source" block)

**Interfaces:**
- Consumes: `Trips::SOURCE_UPLOADS`, `Trips::SOURCE_IMMICH`, `Trips::META_SOURCE`, `Trips::META_ALBUM`, `Trips::uses_uploads()` (PR 1).
- Produces: form field `photos` (`'uploads'` | `'immich'` | album id), optional `album` (typed id, used with `photos=immich`); a new trip of uploads redirects to `…/edit/?saved=1&add=1`. Test helper `$ut_location( callable $handler ): string` (redirect URL, or `'die'`/`'returned'`), defined in `upload-trips.php` and used by Tasks 4 and later.

- [ ] **Step 1: Write the failing checks**

In `tests/playground/upload-trips.php`, replace the block from `// Only the author changes the source.` through the line `wp_set_current_user( 1 );` that follows the `another editor can’t change the source` check with:

```php
// Only the author changes the source, with the "Photos" select: uploads, all Immich photos, or an album.
$ut_save = function ( array $fields ) use ( $ut_rows_trip, $stopped ) {
	$_POST  = $_REQUEST = $fields + [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
	$result = $stopped( [ new Trips(), 'handle_save_trip' ] );
	$_POST  = $_REQUEST = [];
	return $result;
};
$ut_album  = fn() => get_post_meta( $ut_rows_trip, Trips::META_ALBUM, true );
$ut_albumA = '11111111-2222-4333-8444-555555555555';
$ut_albumB = '22222222-3333-4444-8555-666666666666';
wp_set_current_user( $ut_author );
check( 'the author can switch a trip to Immich', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_IMMICH ] ) && ! Trips::uses_uploads( $ut_rows_trip ) && '' === $ut_album() );
check( '…and back to uploads', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_UPLOADS ] ) && Trips::uses_uploads( $ut_rows_trip ) );
check( 'a form without the select leaves the source alone', 'redirect' === $ut_save( [ 'start' => '', 'end' => '' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
check( 'an album chosen in the select: the trip uses Immich, with that album', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => $ut_albumA ] ) && ! Trips::uses_uploads( $ut_rows_trip ) && $ut_albumA === $ut_album() );
check( 'switching to uploads keeps the album, for switching back', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_UPLOADS ] ) && Trips::uses_uploads( $ut_rows_trip ) && $ut_albumA === $ut_album() );
check( 'a form without the select leaves the album alone', 'redirect' === $ut_save( [ 'start' => '', 'end' => '' ] ) && $ut_albumA === $ut_album() );
check( 'all Immich photos with an album id typed beside it: that album', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_IMMICH, 'album' => $ut_albumB ] ) && ! Trips::uses_uploads( $ut_rows_trip ) && $ut_albumB === $ut_album() );
check( 'a typed album id is cleaned', 'redirect' === $ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_IMMICH, 'album' => '<b>' . $ut_albumA ] ) && $ut_albumA === $ut_album() );
$ut_save( [ 'start' => '', 'end' => '', 'photos' => Trips::SOURCE_UPLOADS ] );
wp_set_current_user( $eddie );
check( 'another editor can’t change the source', 'die' === $ut_save( [ 'photos' => Trips::SOURCE_IMMICH ] ) && Trips::uses_uploads( $ut_rows_trip ) );
wp_set_current_user( 1 );

// Where a form handler redirects to; 'die' if it stops instead.
$ut_location = function ( callable $handler ) use ( $stopped ): string {
	$to   = '';
	$keep = function ( $location ) use ( &$to ) {
		$to = $location;
		return $location;
	};
	add_filter( 'wp_redirect', $keep, 1 );
	$result = $stopped( $handler );
	remove_filter( 'wp_redirect', $keep, 1 );
	return 'redirect' === $result ? $to : $result;
};

// A new trip of uploads has none yet: saving it opens its edit page ready to add some.
wp_set_current_user( $ut_author );
$ut_create = function ( string $title, string $photos ) use ( $ut_location ) {
	$_POST = $_REQUEST = [ 'trip_id' => 0, 'title' => $title, 'start' => '', 'end' => '', 'photos' => $photos, 'status' => 'draft', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
	$to    = $ut_location( [ new Trips(), 'handle_save_trip' ] );
	$_POST = $_REQUEST = [];
	$trip  = Trips::find_by_slug( sanitize_title( $title ) );
	if ( $trip ) {
		wp_delete_post( $trip->ID, true );
	}
	return $to;
};
$ut_to = $ut_create( 'Fresh uploads', Trips::SOURCE_UPLOADS );
check( 'a new trip of uploads opens its edit page with ?add=1', false !== strpos( $ut_to, '/edit' ) && false !== strpos( $ut_to, 'add=1' ), $ut_to );
$ut_to = $ut_create( 'Fresh Immich', Trips::SOURCE_IMMICH );
check( '…a new Immich trip doesn’t', false !== strpos( $ut_to, 'saved=1' ) && false === strpos( $ut_to, 'add=1' ), $ut_to );
wp_set_current_user( 1 );
```

- [ ] **Step 2: Run the smoke tests to see them fail**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: FAIL for "the author can switch a trip to Immich" (the handler ignores `photos`), the album checks, and "a new trip of uploads opens its edit page with ?add=1". All other checks ok.

- [ ] **Step 3: Implement**

In `src/Trips.php`, `handle_save_trip()`:

1. Add `'photos'` to the author-only list:

```php
			if ( ! self::can_sync_trip( $trip_id ) && array_intersect_key( $input, array_flip( [ 'start', 'end', 'album', 'photos', 'sync' ] ) ) ) {
```

2. Remember whether the trip is new, right after `$trip_id = absint( $input['trip_id'] ?? 0 );`:

```php
		$created = ! $trip_id;
```

3. Replace these lines:

```php
			update_post_meta( $trip_id, self::META_ALBUM, preg_replace( '/[^a-f0-9-]/i', '', (string) ( $input['album'] ?? '' ) ) );
			// Left alone when the form doesn't offer the choice.
			if ( isset( $input['source'] ) ) {
				update_post_meta( $trip_id, self::META_SOURCE, self::SOURCE_UPLOADS === $input['source'] ? self::SOURCE_UPLOADS : self::SOURCE_IMMICH );
			}
```

with:

```php
			// The "Photos" select: uploads, all Immich photos, or an album id. When Immich
			// can't list albums, an album id can be typed beside it. Left alone when the form
			// doesn't offer the choice; uploads keep the album, for switching back.
			if ( isset( $input['photos'] ) ) {
				$photos = (string) $input['photos'];
				update_post_meta( $trip_id, self::META_SOURCE, self::SOURCE_UPLOADS === $photos ? self::SOURCE_UPLOADS : self::SOURCE_IMMICH );
				if ( self::SOURCE_UPLOADS !== $photos ) {
					$album = self::SOURCE_IMMICH === $photos ? (string) ( $input['album'] ?? '' ) : $photos;
					update_post_meta( $trip_id, self::META_ALBUM, preg_replace( '/[^a-f0-9-]/i', '', $album ) );
				}
			}
```

4. Replace the final redirect:

```php
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url( $trip, 'edit' ) ) );
		exit;
```

with:

```php
		// A new trip of uploads has none yet: its edit page opens the media modal.
		$args = $created && self::uses_uploads( $trip_id ) ? [ 'saved' => '1', 'add' => '1' ] : [ 'saved' => '1' ];
		wp_safe_redirect( add_query_arg( $args, self::url( $trip, 'edit' ) ) );
		exit;
```

5. Fix the docblock of `can_sync_trip()` ("its dates and album") to read "its dates and photos".

- [ ] **Step 4: Run the smoke tests to see them pass**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: no FAIL lines. The album select in `templates/trip-edit.php` still posts `album`; the old smoke checks on it read markup only, so they still pass. Task 2 replaces it.

- [ ] **Step 5: Commit**

```bash
git add src/Trips.php tests/playground/upload-trips.php
git commit -m "Take a trip's photos from one Photos field, and open new upload trips ready to add some (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 2: The "Photos" select

**Files:**
- Modify: `src/Uploads.php:81-98` (`prefilter()`), add `available()`
- Modify: `src/Trips.php` (add `can_use_immich()` after `can_sync_trip()`)
- Modify: `src/View.php` (add `photo_choices()`)
- Modify: `templates/trip-edit.php:7-37` (top), `:110-151` (dates and Photos), `:174-179` (buttons)
- Create: `assets/travel-steps-edit.js`
- Modify: `assets/travel-steps.css`
- Create: `tests/playground/upload-pages.php`
- Modify: `tests/playground/smoke.php:498`, `:841`, `:848`, `:938`

**Interfaces:**
- Consumes: the `photos` field (Task 1).
- Produces:
  - `Uploads::available(): bool`: PHP's exif extension is there, filtered by `travel_steps_can_upload`.
  - `Trips::can_use_immich( int $user_id ): bool`: the user has an Immich key.
  - `View::photo_choices( ?\WP_Post $trip, $albums ): list<array{value: string, label: string, selected: bool, disabled: bool}>`, `$albums` being `array` (albums), `\WP_Error`, or `null` (no key).
  - Template variables later tasks use in `trip-edit.php`: `$albums`, `$choices`, `$picks_uploads`; markup `form.ts-trip-form`, `select[name="photos"]`, `.ts-dates-hint`, `button.ts-save[data-label][data-uploads-label]`.
  - `assets/travel-steps-edit.js`, enqueued as handle `travel-steps-edit`.
  - Smoke file `tests/playground/upload-pages.php` with helpers `$up_choices( string $html ): array` (option value => its attributes) and `$up_new( int $user ): string` (the new-trip page as that user), and user `$up_nokey` (an author without keys).

- [ ] **Step 1: Write the failing checks**

Create `tests/playground/upload-pages.php`:

```php
<?php
/**
 * The trip pages for trips of uploads: the Photos select, the Photos panel, the sync
 * notice and wording by source. Required after upload-trips.php, in smoke.php's scope:
 * uses check(), $render, $stopped, $person, $servers, $status, $trip_by, $alice,
 * $carol, $carols, $alices, $eddie and upload-trips.php's $ut_* helpers.
 */
use TravelSteps\Settings;
use TravelSteps\Trips;
use TravelSteps\Uploads;

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
```

In `tests/playground/smoke.php`:

- line 498: change `'<select name="album"'` to `'<select name="photos"'` (the regex on `<option value="' . $gone . '"[^>]*selected` stays).
- line 841: change `false === strpos( $edit, 'name="album"' )` to `false === strpos( $edit, 'name="photos"' )`.
- line 848: change `false !== strpos( $edit, 'name="album"' )` to `false !== strpos( $edit, 'name="photos"' )`.
- line 938: after `require '/smoke/upload-trips.php';` add `require '/smoke/upload-pages.php';`.

- [ ] **Step 2: Run the smoke tests to see them fail**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: FAIL for the new select checks and the three edited `smoke.php` checks (no `name="photos"` yet).

- [ ] **Step 3: Implement**

`src/Uploads.php`: add after `upload_trip()`:

```php
	/**
	 * Whether photos can be uploaded to trips on this site. Without PHP's exif extension
	 * there's no knowing when a photo was taken, so no day to put it on.
	 */
	public static function available(): bool {
		return (bool) apply_filters( 'travel_steps_can_upload', function_exists( 'exif_read_data' ) );
	}
```

and in `prefilter()` replace

```php
		} elseif ( $trip_id && ! function_exists( 'exif_read_data' ) ) {
			// Without it there's no knowing when a photo was taken, so no day to put it on.
			$file['error'] = …
```

with `} elseif ( $trip_id && ! self::available() ) {` (keep the error message line).

`src/Trips.php`: add after `can_sync_trip()`:

```php
	/**
	 * Whether a user can take a trip's photos from Immich. The site always has an Immich
	 * URL (it falls back to the default container), so it's their key that decides.
	 */
	public static function can_use_immich( int $user_id ): bool {
		return '' !== Settings::account( $user_id )['immich_key'];
	}
```

`src/View.php`: add at the end of the class:

```php
	/**
	 * The trip form's "Photos" choices for the trip's author: the trip's uploads, all
	 * their Immich photos from its dates, or one Immich album. Empty when they can use
	 * neither.
	 *
	 * @param array|\WP_Error|null $albums The author's Immich albums, the error listing
	 *                                     them, or null when they have no Immich key.
	 * @return list<array{value: string, label: string, selected: bool, disabled: bool}>
	 */
	public static function photo_choices( ?\WP_Post $trip, $albums ): array {
		$can_upload = Uploads::available();
		if ( null === $albums && ! $can_upload ) {
			return [];
		}
		$saved_album = $trip ? (string) get_post_meta( $trip->ID, Trips::META_ALBUM, true ) : '';
		if ( ! $trip ) {
			// A new trip: uploads when the author can't use Immich, else all their Immich photos.
			$current = null === $albums ? Trips::SOURCE_UPLOADS : Trips::SOURCE_IMMICH;
		} elseif ( Trips::uses_uploads( $trip->ID ) ) {
			$current = Trips::SOURCE_UPLOADS;
		} elseif ( '' !== $saved_album && ! is_wp_error( $albums ) ) {
			$current = $saved_album;
		} else {
			// When Immich can't list albums, the album id is typed beside the select.
			$current = Trips::SOURCE_IMMICH;
		}
		$choice = fn( string $value, string $label, bool $disabled = false ) => [
			'value'    => $value,
			'label'    => $label,
			'selected' => $value === $current,
			'disabled' => $disabled,
		];

		$choices = [ $choice( Trips::SOURCE_UPLOADS, __( 'Photos uploaded to this trip', 'travel-steps' ), ! $can_upload ) ];
		if ( null === $albums ) {
			// Without a key the author can't choose Immich, but saving the form mustn't
			// switch an Immich trip to uploads.
			if ( Trips::SOURCE_UPLOADS !== $current ) {
				$choices[] = $choice(
					$current,
					Trips::SOURCE_IMMICH === $current
						? __( 'All Immich photos taken during the trip', 'travel-steps' )
						/* translators: %s: Immich album id */
						: sprintf( __( 'Immich album %s', 'travel-steps' ), $current )
				);
			}
			return $choices;
		}
		$choices[] = $choice( Trips::SOURCE_IMMICH, __( 'All Immich photos taken during the trip', 'travel-steps' ) );
		$listed    = is_array( $albums ) ? $albums : [];
		// An album Immich no longer lists (deleted, or not visible to this key) stays
		// selected, so saving the form for something else doesn't drop it.
		if ( $current === $saved_album && '' !== $saved_album && ! in_array( $saved_album, array_column( $listed, 'id' ), true ) ) {
			/* translators: %s: Immich album id */
			$choices[] = $choice( $saved_album, sprintf( __( 'Album not found in Immich (%s)', 'travel-steps' ), $saved_album ) );
		}
		foreach ( $listed as $album ) {
			$choices[] = $choice( $album['id'], sprintf( '%s (%s)', $album['name'], number_format_i18n( $album['count'] ) ) );
		}
		return $choices;
	}
```

`templates/trip-edit.php`:

1. Add `use TravelSteps\Uploads;` to the `use` lines (alphabetical: after `Trips`, before `View`).

2. Replace `$albums   = $is_owner ? ImmichClient::for_user( $author )->albums() : [];` with:

```php
// Null when the author has no Immich key: Immich isn't offered then.
$albums  = $is_owner && Trips::can_use_immich( $author ) ? ImmichClient::for_user( $author )->albums() : null;
$choices = $is_owner ? View::photo_choices( $trip, $albums ) : [];
// Uploads make the dates optional: sync takes them from the photos.
$picks_uploads = in_array( Trips::SOURCE_UPLOADS, array_column( array_filter( $choices, fn( $c ) => $c['selected'] ), 'value' ), true );
```

3. Just before `$page_title = …`, enqueue the script:

```php
wp_app_enqueue_script(
	'travel-steps-edit',
	TRAVEL_STEPS_PLUGIN_URL . 'assets/travel-steps-edit.js',
	[],
	filemtime( TRAVEL_STEPS_PLUGIN_DIR . 'assets/travel-steps-edit.js' ),
	true,
	'travel-steps'
);
```

4. Give the main form a class: `<form class="ts-form ts-trip-form" method="post" …>`.

5. In both date inputs replace `required` with `<?php echo $picks_uploads ? '' : 'required'; ?>`. After the closing `</div>` of `.ts-form-row` add:

```php
		<?php if ( $is_owner ) : ?>
			<small class="ts-muted ts-dates-hint" <?php echo $picks_uploads ? '' : 'hidden'; ?>><?php esc_html_e( 'Leave empty to take them from the photos.', 'travel-steps' ); ?></small>
		<?php endif; ?>
```

6. In the non-owner sentence change "change its dates or album, or sync it" to "change its dates or photos, or sync it".

7. Replace the owner's `<label>…Photos…</label>` block (from `<?php else : ?>` after the non-owner sentence through the `<?php endif; ?>` before the Introduction label) with:

```php
		<?php elseif ( ! $choices ) : ?>
			<p class="ts-notice is-warning">
				<?php esc_html_e( 'To add photos to a trip, add an Immich key in settings, or ask your site’s administrator for PHP’s exif extension, which uploading needs.', 'travel-steps' ); ?>
				<a href="<?php echo esc_url( App::url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'travel-steps' ); ?></a>
			</p>
		<?php else : ?>
		<label>
			<span><?php esc_html_e( 'Photos', 'travel-steps' ); ?></span>
			<select name="photos">
				<?php foreach ( $choices as $choice ) : ?>
					<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( $choice['selected'] ); ?> <?php disabled( $choice['disabled'] ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! Uploads::available() ) : ?>
				<small class="ts-muted"><?php esc_html_e( 'Uploading photos needs PHP’s exif extension, which reads when and where they were taken. This site doesn’t have it.', 'travel-steps' ); ?></small>
			<?php endif; ?>
			<?php if ( null === $albums ) : ?>
				<small class="ts-muted"><a href="<?php echo esc_url( App::url( 'settings' ) ); ?>"><?php esc_html_e( 'Add an Immich key in settings to use Immich photos.', 'travel-steps' ); ?></a></small>
			<?php elseif ( is_wp_error( $albums ) ) : ?>
				<input type="text" name="album" placeholder="<?php esc_attr_e( 'Immich album id', 'travel-steps' ); ?>" value="<?php echo esc_attr( $meta( Trips::META_ALBUM ) ); ?>">
				<small class="ts-muted"><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'Could not list Immich albums: %s', 'travel-steps' ), $albums->get_error_message() ) ); ?></small>
			<?php else : ?>
				<small class="ts-muted"><?php esc_html_e( 'Pick an Immich album to choose exactly which photos appear.', 'travel-steps' ); ?></small>
			<?php endif; ?>
		</label>
		<?php endif; ?>
```

The `if ( ! $is_owner )` that opens this chain stays as it is; the chain is now `if ( ! $is_owner ) … elseif ( ! $choices ) … else … endif`.

8. Replace the two buttons in `.ts-form-actions` with:

```php
			<?php
			$save_label = __( 'Save', 'travel-steps' );
			$add_label  = __( 'Save and add photos', 'travel-steps' );
			// A new trip of uploads has nothing to sync yet: saving it opens the media modal.
			$adds = ! $trip && $picks_uploads;
			?>
			<button type="submit" class="ts-button ts-save" data-label="<?php echo esc_attr( $save_label ); ?>" data-uploads-label="<?php echo esc_attr( $add_label ); ?>"><?php echo esc_html( $adds ? $add_label : $save_label ); ?></button>
			<?php if ( $is_owner ) : ?>
				<button type="submit" class="ts-button ts-button-secondary" name="sync" value="1" <?php echo $adds ? 'hidden' : ''; ?>><?php esc_html_e( 'Save and sync', 'travel-steps' ); ?></button>
			<?php endif; ?>
```

Create `assets/travel-steps-edit.js`:

```js
/**
 * The trip form: what the "Photos" choice changes on the page.
 */
( function () {
	'use strict';

	const form = document.querySelector( '.ts-trip-form' );
	const choice = form && form.querySelector( 'select[name="photos"]' );
	if ( choice ) {
		const isNew = '0' === form.elements.trip_id.value;
		const save = form.querySelector( '.ts-save' );
		const sync = form.querySelector( 'button[name="sync"]' );
		const hint = form.querySelector( '.ts-dates-hint' );
		choice.addEventListener( 'change', () => {
			const uploads = 'uploads' === choice.value;
			// A trip of uploads can take its dates from the photos.
			form.querySelectorAll( 'input[type="date"]' ).forEach( ( input ) => {
				input.required = ! uploads;
			} );
			hint.hidden = ! uploads;
			// A new trip of uploads has nothing to sync yet: saving it opens the media modal.
			if ( isNew ) {
				save.textContent = uploads ? save.dataset.uploadsLabel : save.dataset.label;
				if ( sync ) {
					sync.hidden = uploads;
				}
			}
		} );
	}
}() );
```

`assets/travel-steps.css`: after the `.ts-form-actions` rule add:

```css
/* .ts-button's display would otherwise win over the hidden attribute. */
.ts-body [hidden] { display: none !important; }
.ts-dates-hint { margin-top: -.5rem; }
```

- [ ] **Step 4: Run the smoke tests to see them pass**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: no FAIL lines.

- [ ] **Step 5: Commit**

```bash
git add src/Uploads.php src/Trips.php src/View.php templates/trip-edit.php assets/travel-steps-edit.js assets/travel-steps.css tests/playground/upload-pages.php tests/playground/smoke.php
git commit -m "Choose a trip's photos from uploads, all Immich photos or an album (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 3: The Photos panel and the media modal

**Files:**
- Modify: `templates/trip-edit.php` (top: media enqueue; notices; new Photos panel before the Sync panel; sync form class)
- Modify: `assets/travel-steps-edit.js`
- Modify: `assets/travel-steps.css`
- Test: `tests/playground/upload-pages.php`

**Interfaces:**
- Consumes: `Uploads::available()`, `Uploads::trip_photos( int $trip_id ): int[]`, `Uploads::REQUEST_FLAG` (`'travel_steps_photo'`), `Trips::uses_uploads()`.
- Produces: markup `section.ts-panel.ts-uploads[data-trip][data-open][data-title][data-done]` with `button.ts-add-photos`; `form.ts-sync-form` (author only); query flag `?photos=1` (non-author's reload) and its notice.

- [ ] **Step 1: Write the failing checks**

Append to `tests/playground/upload-pages.php`:

```php
// The Photos panel: how many uploads, and a button to add more, for anyone who can edit the trip.
$up_panel = $ut_trip( 'Panel', $ut_author, '2025-06-01', '2025-06-02' );
$ut_photo( $up_panel, [ 'taken' => '2025:06:01 10:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
wp_set_current_user( $ut_author );
$_GET    = [ 'saved' => '1', 'add' => '1' ];
$up_html = $render( 'trip-edit.php', (string) $up_panel );
$_GET    = [];
check( 'an upload trip’s edit page: its photo count and Add photos', false !== strpos( $up_html, '1 photo uploaded.' ) && false !== strpos( $up_html, 'ts-add-photos' ), substr( $up_html, strpos( $up_html, 'ts-uploads' ) ?: 0, 600 ) );
check( '…?add=1 opens the modal, whose templates are on the page', 1 === preg_match( '/class="ts-panel ts-uploads"[^>]*data-open="1"/', $up_html ) && false !== strpos( $up_html, 'tmpl-media-modal' ) );
check( '…and the author’s page has the sync form that closing it submits', false !== strpos( $up_html, 'ts-sync-form' ) );
wp_set_current_user( $eddie );
$_GET    = [ 'photos' => '1' ];
$up_html = $render( 'trip-edit.php', (string) $up_panel );
$_GET    = [];
check( 'another editor can add photos but has no sync form, and is told who syncs', false !== strpos( $up_html, 'ts-add-photos' ) && false === strpos( $up_html, 'ts-sync-form' ) && false !== strpos( $up_html, 'Wanderer needs to sync it' ), substr( $up_html, 0, 2000 ) );
add_filter( 'travel_steps_can_upload', '__return_false' );
wp_set_current_user( $ut_author );
$up_html = $render( 'trip-edit.php', (string) $up_panel );
remove_filter( 'travel_steps_can_upload', '__return_false' );
check( 'without exif, the panel says why there’s no Add photos', false !== strpos( $up_html, '1 photo uploaded.' ) && false === strpos( $up_html, 'ts-add-photos' ) && false !== strpos( $up_html, 'can’t take uploads' ) );
wp_set_current_user( $alice );
add_filter( 'pre_http_request', $servers, 10, 3 );
$up_html = $render( 'trip-edit.php', (string) $alices );
remove_filter( 'pre_http_request', $servers, 10 );
check( 'an Immich trip’s edit page has no Photos panel', false === strpos( $up_html, 'ts-uploads' ) && false !== strpos( $up_html, 'ts-sync-form' ) );
wp_set_current_user( 1 );
wp_delete_post( $up_panel, true );
```

(`tmpl-media-modal` is printed by every later page render once `wp_enqueue_media()` has run in this PHP process, so it can't show the Immich page lacks the modal; `ts-uploads` can.)

- [ ] **Step 2: Run the smoke tests to see them fail**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: FAIL for the five panel checks; "an Immich trip’s edit page has no Photos panel" fails too (no `ts-sync-form` class yet).

- [ ] **Step 3: Implement**

`templates/trip-edit.php`:

1. After the `$picks_uploads = …` line add:

```php
$uses_uploads = $trip && Trips::uses_uploads( $trip_id );
// Anyone who can edit the trip may add photos to it; only its author syncs.
$can_add = $uses_uploads && Uploads::available();
if ( $can_add ) {
	wp_enqueue_media( [ 'post' => $trip_id ] );
}
```

and change the script's dependencies from `[]` to `$can_add ? [ 'media-views' ] : []`.

2. After the `elseif ( $error )` notice's `<?php endif; ?>` add:

```php
	<?php if ( isset( $_GET['photos'] ) && ! $is_owner ) : ?>
		<p class="ts-notice"><?php echo esc_html( sprintf( /* translators: %s: the trip author's name */ __( 'The trip’s photos changed. %s needs to sync it to update its steps.', 'travel-steps' ), get_the_author_meta( 'display_name', $author ) ) ); ?></p>
	<?php endif; ?>
```

3. Directly after `<?php if ( $trip ) : ?>` that opens the Sync panel, add the Photos panel:

```php
		<?php if ( $uses_uploads ) : ?>
			<section class="ts-panel ts-uploads" data-trip="<?php echo (int) $trip_id; ?>" data-open="<?php echo isset( $_GET['add'] ) ? '1' : ''; ?>" data-title="<?php esc_attr_e( 'Photos for this trip', 'travel-steps' ); ?>" data-done="<?php esc_attr_e( 'Done', 'travel-steps' ); ?>">
				<div class="ts-page-head">
					<h2><?php esc_html_e( 'Photos', 'travel-steps' ); ?></h2>
					<?php if ( $can_add ) : ?>
						<button type="button" class="ts-button ts-button-secondary ts-add-photos"><?php esc_html_e( 'Add photos', 'travel-steps' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="ts-muted">
					<?php
					$uploaded = count( Uploads::trip_photos( $trip_id ) );
					echo esc_html(
						$uploaded
							/* translators: %s: number of photos */
							? sprintf( _n( '%s photo uploaded.', '%s photos uploaded.', $uploaded, 'travel-steps' ), number_format_i18n( $uploaded ) )
							: __( 'No photos uploaded yet.', 'travel-steps' )
					);
					if ( ! $can_add ) {
						echo ' ' . esc_html__( 'This site can’t take uploads: its PHP has no exif extension, which reads when and where photos were taken.', 'travel-steps' );
					}
					?>
				</p>
			</section>
		<?php endif; ?>
```

4. Give the Sync panel's form a class: `<form class="ts-sync-form" method="post" …>` (the one with `action` value `travel_steps_sync`).

`assets/travel-steps-edit.js`: change the header comment to "The trip form: what the "Photos" choice changes on the page, and adding photos to a trip of uploads through the media modal.", add `/* global wp */` as the first line, and before the closing `}() );` add:

```js

	/* ---------- Adding photos ---------- */

	const panel = document.querySelector( '.ts-uploads' );
	const button = panel && panel.querySelector( '.ts-add-photos' );
	if ( ! button || ! window.wp || ! wp.media ) {
		return;
	}
	const trip = parseInt( panel.dataset.trip, 10 );
	let frame = null;
	let changed = false;

	// After an upload or a deletion the trip's steps are out of date: the author's page
	// syncs it; anyone else's reloads to say the author needs to.
	function closed() {
		if ( ! changed ) {
			return;
		}
		const syncForm = document.querySelector( '.ts-sync-form' );
		if ( syncForm ) {
			syncForm.submit();
			return;
		}
		const url = new URL( window.location.href );
		url.searchParams.delete( 'add' );
		url.searchParams.set( 'photos', '1' );
		window.location.assign( url );
	}

	function open() {
		if ( ! frame ) {
			frame = wp.media( {
				title: panel.dataset.title,
				multiple: true,
				library: { uploadedTo: trip, travel_steps_photo: 1, type: 'image' },
				// Closes the modal whether or not a photo is selected.
				button: { text: panel.dataset.done, requires: { selection: false } },
			} );
			const library = frame.state( 'library' ).get( 'library' );
			library.on( 'destroy', () => {
				changed = true;
			} );
			// Core only adds new uploads to a library whose query it fully understands, and
			// travel_steps_photo isn't one of its arguments: add each when it's uploaded. A
			// refused upload never gets an id.
			wp.Uploader.queue.on( 'change:uploading', ( attachment ) => {
				if ( ! attachment.get( 'uploading' ) && attachment.id ) {
					changed = true;
					library.add( attachment );
				}
			} );
			// What makes an upload one of the trip's photos (Uploads::REQUEST_FLAG).
			frame.on( 'open', () => {
				frame.uploader.uploader.param( 'post_id', trip );
				frame.uploader.uploader.param( 'travel_steps_photo', 1 );
			} );
			frame.on( 'close', closed );
		}
		frame.open();
	}

	button.addEventListener( 'click', open );
	// Saving a new trip of uploads comes here with ?add=1; a reload shouldn't reopen it.
	if ( panel.dataset.open ) {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'add' );
		window.history.replaceState( null, '', url );
		open();
	}
```

`assets/travel-steps.css`: after the `[hidden]` rule add:

```css
/* The media modal is light whatever the page's colour scheme: keep the page's ink out of it. */
.ts-body .media-modal { color: #1d2327; color-scheme: light; }
```

- [ ] **Step 4: Run the smoke tests to see them pass**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: no FAIL lines.

- [ ] **Step 5: Check it in the browser**

Run `make demo` in the background and wait for "Ready!". In Chrome (the `chrome-devtools` MCP tools; put any file you upload under `/Users/donncha/dev/personal/travel-steps/.probe/`, which the DevTools workspace allows, and delete that folder afterwards):

1. Emulate a dark colour scheme (`emulate` with `prefers-color-scheme: dark`) or set the OS to dark.
2. Open `http://127.0.0.1:9400/travel-steps/new/`, choose "Photos uploaded to this trip": the dates lose `required`, the hint shows, Save reads "Save and add photos" and "Save and sync" disappears. Choose "All Immich photos…": all of it reverts.
3. Choose uploads again, give a title, press Save and add photos. The edit page opens with the modal open, and the URL has no `add=1`.
4. In the modal, evaluate in the page: `getComputedStyle(document.querySelector('.media-frame-title h1')).color` and the same for `.media-router .media-menu-item` and `.media-toolbar-secondary label`. All must be dark (not `rgb(240, 239, 233)` or `rgb(255, 255, 255)`). If a menu item is still light, add `.ts-body .media-modal .media-menu-item:not(.active) { color: #50575e; }` and look again; take a screenshot to confirm.
5. Upload `.probe/doolin.jpg` (make it with the probe's `magick` + `exiftool` commands: 1200×800, `DateTimeOriginal=2025:06:01 10:00:00`, `OffsetTimeOriginal=+01:00`, GPS 53.0158 N 9.3769 W). The thumbnail appears **in the grid** and loads from `/wp-json/travel-steps/v1/trips/<id>/photo/…` (check `list_network_requests`). "Done" is enabled.
6. Press Done: the sync form submits and the page shows "Synced. 1 new draft step." with a Photos panel saying "1 photo uploaded.".

- [ ] **Step 6: Commit**

```bash
git add templates/trip-edit.php assets/travel-steps-edit.js assets/travel-steps.css tests/playground/upload-pages.php
git commit -m "Add photos to a trip through the media modal, and sync when it closes (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 4: The sync notice for trips of uploads

**Files:**
- Modify: `src/Trips.php:422-441` (`run_sync_and_redirect()`)
- Modify: `templates/trip-edit.php` (the `synced=1` notice)
- Test: `tests/playground/upload-pages.php`

**Interfaces:**
- Consumes: `TripSync::sync()` result keys `left_out` (`['outside' => int, 'untimed' => int, 'home' => int]`), `unlocated` (int), `no_places` (bool), present only for trips of uploads (PR 1); `$ut_location` (Task 1); `$ut_tour` from `upload-trips.php` (left out after its tests: 1 outside, 1 untimed, 1 at home; 1 with no location).
- Produces: query args `outside`, `untimed`, `home`, `unlocated`, `no_places` on the redirect after syncing a trip of uploads.

- [ ] **Step 1: Write the failing checks**

Append to `tests/playground/upload-pages.php`:

```php
// The sync notice: what sync left out of a trip of uploads, and why.
wp_set_current_user( $ut_author );
$_POST = $_REQUEST = [ 'trip_id' => $ut_tour, '_wpnonce' => wp_create_nonce( 'travel_steps_sync' ) ];
$up_to = $ut_location( [ new Trips(), 'handle_sync' ] );
$_POST = $_REQUEST = [];
parse_str( (string) wp_parse_url( $up_to, PHP_URL_QUERY ), $up_args );
check( 'syncing a trip of uploads passes what it left out to the notice', [ '1', '1', '1', '1', '0' ] === [ $up_args['outside'] ?? '', $up_args['untimed'] ?? '', $up_args['home'] ?? '', $up_args['unlocated'] ?? '', $up_args['no_places'] ?? '' ], $up_to );
$_GET    = $up_args;
$up_html = $render( 'trip-edit.php', (string) $ut_tour );
check( 'the notice: what was left out, and why', false !== strpos( $up_html, 'Left out: 1 outside the trip’s dates, 1 with no time taken (often sent through a messaging app, or a screenshot), 1 taken at home.' ), substr( $up_html, strpos( $up_html, 'Synced.' ) ?: 0, 800 ) );
check( '…and the photo with no location', false !== strpos( $up_html, '1 photo has no location, so it isn’t on the map. iPhone browsers drop it unless you turn on Options → Location before uploading; messaging apps strip it.' ) );
$_GET    = [ 'synced' => '1', 'new_steps' => '0', 'untimed' => '2', 'no_places' => '1' ];
$up_html = $render( 'trip-edit.php', (string) $ut_tour );
$_GET    = [];
check( '…only counts above zero, and missing place names', false !== strpos( $up_html, 'Left out: 2 with no time taken (often sent through a messaging app, or a screenshot).' ) && false === strpos( $up_html, 'outside the trip’s dates' ) && false === strpos( $up_html, 'no location' ) && false !== strpos( $up_html, 'Place names are unavailable: the plugin’s geonames data is missing.' ) );
wp_set_current_user( $alice );
add_filter( 'pre_http_request', $servers, 10, 3 );
$_POST = $_REQUEST = [ 'trip_id' => $alices, '_wpnonce' => wp_create_nonce( 'travel_steps_sync' ) ];
$up_to = $ut_location( [ new Trips(), 'handle_sync' ] );
$_POST = $_REQUEST = [];
remove_filter( 'pre_http_request', $servers, 10 );
check( 'an Immich trip’s sync passes no left-out counts', false !== strpos( $up_to, 'synced=1' ) && false === strpos( $up_to, 'untimed' ), $up_to );
wp_set_current_user( 1 );
```

- [ ] **Step 2: Run the smoke tests to see them fail**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: FAIL for the first four new checks; the Immich one passes already.

- [ ] **Step 3: Implement**

`src/Trips.php`, `run_sync_and_redirect()`: replace the success redirect with:

```php
		$args = [
			'synced'       => '1',
			'new_steps'    => (int) $result['new_steps'],
			'skipped_days' => (int) $result['skipped_days'],
		];
		// For a trip of uploads: what sync left out or couldn't place, for the notice.
		if ( isset( $result['left_out'] ) ) {
			$args += array_map( 'intval', $result['left_out'] ) + [
				'unlocated' => (int) $result['unlocated'],
				'no_places' => (int) $result['no_places'],
			];
		}
		wp_safe_redirect( add_query_arg( $args, self::url( $trip, 'edit' ) ) );
		exit;
```

`templates/trip-edit.php`: directly after the closing `</p>` of the `synced=1` notice (before `<?php elseif ( $error ) : ?>`), add:

```php
		<?php
		$counts   = array_map( fn( $key ) => absint( $_GET[ $key ] ?? 0 ), [ 'outside' => 'outside', 'untimed' => 'untimed', 'home' => 'home', 'unlocated' => 'unlocated' ] );
		$left_out = array_filter(
			[
				/* translators: %s: number of photos */
				$counts['outside'] ? sprintf( __( '%s outside the trip’s dates', 'travel-steps' ), number_format_i18n( $counts['outside'] ) ) : '',
				/* translators: %s: number of photos */
				$counts['untimed'] ? sprintf( __( '%s with no time taken (often sent through a messaging app, or a screenshot)', 'travel-steps' ), number_format_i18n( $counts['untimed'] ) ) : '',
				/* translators: %s: number of photos */
				$counts['home'] ? sprintf( __( '%s taken at home', 'travel-steps' ), number_format_i18n( $counts['home'] ) ) : '',
			]
		);
		?>
		<?php if ( $left_out ) : ?>
			<p class="ts-notice is-warning"><?php echo esc_html( sprintf( /* translators: %s: a list of counts, such as "1 outside the trip's dates, 2 taken at home" */ __( 'Left out: %s.', 'travel-steps' ), implode( ', ', $left_out ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( $counts['unlocated'] ) : ?>
			<p class="ts-notice is-warning">
				<?php
				/* translators: %s: number of photos */
				echo esc_html( sprintf( _n( '%s photo has no location, so it isn’t on the map.', '%s photos have no location, so they aren’t on the map.', $counts['unlocated'], 'travel-steps' ), number_format_i18n( $counts['unlocated'] ) ) );
				echo ' ' . esc_html__( 'iPhone browsers drop it unless you turn on Options → Location before uploading; messaging apps strip it.', 'travel-steps' );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['no_places'] ) ) : ?>
			<p class="ts-notice is-warning"><?php esc_html_e( 'Place names are unavailable: the plugin’s geonames data is missing.', 'travel-steps' ); ?></p>
		<?php endif; ?>
```

- [ ] **Step 4: Run the smoke tests to see them pass**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: no FAIL lines.

- [ ] **Step 5: Commit**

```bash
git add src/Trips.php templates/trip-edit.php tests/playground/upload-pages.php
git commit -m "Say which uploads sync left out, and why (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 5: Wording by source

**Files:**
- Modify: `templates/trip.php:26-50` (the heart)
- Modify: `src/PhotosBlock.php:71`
- Modify: `templates/trip-edit.php` (key warning, no-favourites warning, never-synced text, photo limit, empty Steps text)
- Test: `tests/playground/upload-pages.php`

**Interfaces:**
- Consumes: `$uses_uploads` (Task 3) in `trip-edit.php`; `Trips::uses_uploads()`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing checks**

Append to `tests/playground/upload-pages.php`:

```php
// Wording on a trip of uploads, where every upload is a favourite and Immich plays no part.
wp_set_current_user( $ut_author );
$up_html = $render( 'trip.php', (string) $ut_tour );
check( 'no “Favourite in Immich” heart on a trip of uploads', false !== strpos( $up_html, 'ts-photo-tools' ) && false === strpos( $up_html, 'Favourite in Immich' ) );
$up_bare = $ut_trip( 'Bare', $up_nokey );
wp_set_current_user( $up_nokey );
$up_html = $render( 'trip-edit.php', (string) $up_bare );
check( 'a trip of uploads: no Immich key warning and no photo limit', false === strpos( $up_html, 'saved an Immich API key' ) && false === strpos( $up_html, 'ignores the rest' ), substr( $up_html, 0, 2000 ) );
check( '…and its Sync and Steps panels say what syncing uploads does', false !== strpos( $up_html, 'Syncing makes a draft step for each day with photos, named after where they were taken.' ) && false !== strpos( $up_html, 'Add photos and sync: each day with photos gets a step.' ) );
$up_step = wp_insert_post( [ 'post_type' => Trips::STEP, 'post_parent' => $up_bare, 'post_title' => 'Day', 'post_status' => 'draft', 'post_author' => $up_nokey ] );
$GLOBALS['post'] = get_post( $up_step );
check( 'the photos block on a trip of uploads asks for uploads', false !== strpos( TravelSteps\PhotosBlock::render( [] ), 'No photos for this place yet. Add photos to the trip.' ) );
wp_set_current_user( 1 );
wp_delete_post( $up_step, true );
wp_delete_post( $up_bare, true );
```

- [ ] **Step 2: Run the smoke tests to see them fail**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: FAIL for all four new checks (the key-warning one fails on "ignores the rest").

- [ ] **Step 3: Implement**

`templates/trip.php`:

- After `$cover_id     = Trips::cover( $trip->ID );` add `$uploads      = Trips::uses_uploads( $trip->ID ); // Every upload is a favourite: no heart.`
- Add `$uploads` to `$render_photo`'s `use ( … )` list.
- Change `<?php if ( ! empty( $photo['fav'] ) ) : ?>` to `<?php if ( ! empty( $photo['fav'] ) && ! $uploads ) : ?>`.

`src/PhotosBlock.php:71`: replace the empty return with:

```php
		if ( ! $photos ) {
			$empty = Trips::uses_uploads( $trip->ID )
				? __( 'No photos for this place yet. Add photos to the trip.', 'travel-steps' )
				: __( 'No photos for this place yet. Favourite some in Immich and sync.', 'travel-steps' );
			return '<p class="ts-photos-block-empty">' . esc_html( $empty ) . '</p>';
		}
```

`templates/trip-edit.php`:

1. Key warning: `<?php if ( '' === $account['immich_key'] ) : ?>` becomes `<?php if ( $trip && ! $uses_uploads && '' === $account['immich_key'] ) : ?>` (a new trip's select says it instead).
2. No-favourites warning: `<?php if ( $photos && ! array_filter( array_column( $photos, 'fav' ) ) ) : ?>` becomes `<?php if ( ! $uses_uploads && $photos && ! array_filter( array_column( $photos, 'fav' ) ) ) : ?>`.
3. Never-synced text: before `} elseif ( $has_dawarich ) {` in the Sync panel insert:

```php
				} elseif ( $uses_uploads ) {
					esc_html_e( 'Not synced yet. Syncing makes a draft step for each day with photos, named after where they were taken.', 'travel-steps' );
```

4. Photo limit: wrap the whole `<p class="ts-muted">` that prints `$max_photos` in `<?php if ( ! $uses_uploads ) : ?> … <?php endif; ?>`.
5. Empty Steps text: replace the line with:

```php
				<p class="ts-muted">
					<?php
					echo esc_html(
						$uses_uploads
							? __( 'No steps yet. Add photos and sync: each day with photos gets a step.', 'travel-steps' )
							: __( 'No steps yet. Favourite photos in Immich and sync, or add one by hand.', 'travel-steps' )
					);
					?>
				</p>
```

- [ ] **Step 4: Run the smoke tests to see them pass**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: no FAIL lines; the existing "an editor of a trip whose author has no Immich key is told so" (Carol's Immich trip) still passes.

- [ ] **Step 5: Commit**

```bash
git add templates/trip.php src/PhotosBlock.php templates/trip-edit.php tests/playground/upload-pages.php
git commit -m "Word a trip of uploads' pages for uploads, not Immich (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 6: Settings

**Files:**
- Modify: `templates/settings.php`
- Test: `tests/playground/upload-pages.php`

**Interfaces:**
- Consumes: `Uploads::available()`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing checks**

Append to `tests/playground/upload-pages.php`:

```php
// Settings: Immich is optional, and a site without exif says what that means.
wp_set_current_user( 1 );
$up_html = $render( 'settings.php', '' );
check( 'settings: Immich is optional, and a trip of uploads shows every upload', false !== strpos( $up_html, 'Immich (optional)' ) && false !== strpos( $up_html, 'For a trip with uploaded photos, every upload is shown; hide the ones you don’t want.' ) && false === strpos( $up_html, 'no exif extension' ) );
add_filter( 'travel_steps_can_upload', '__return_false' );
$up_html = $render( 'settings.php', '' );
remove_filter( 'travel_steps_can_upload', '__return_false' );
check( 'settings without exif: trips can only use Immich', false !== strpos( $up_html, 'This site’s PHP has no exif extension, so trips can only use Immich photos.' ) );
```

- [ ] **Step 2: Run the smoke tests to see them fail**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: FAIL for both.

- [ ] **Step 3: Implement**

`templates/settings.php`:

1. Add `use TravelSteps\Uploads;` after `use TravelSteps\Settings;`.
2. After the `saved`/`invalid` notices' `<?php endif; ?>` add:

```php
	<?php if ( ! Uploads::available() ) : ?>
		<p class="ts-notice is-warning"><?php esc_html_e( 'This site’s PHP has no exif extension, so trips can only use Immich photos. Uploading needs it to read when and where photos were taken.', 'travel-steps' ); ?></p>
	<?php endif; ?>
```

3. The Immich legend: `esc_html_e( 'Immich', … )` becomes `esc_html_e( 'Immich (optional)', 'travel-steps' )`, and add under the legend:

```php
			<?php if ( Uploads::available() ) : ?>
				<p class="ts-muted"><?php esc_html_e( 'Without a key, upload photos to your trips instead.', 'travel-steps' ); ?></p>
			<?php endif; ?>
```

4. The site Photos paragraph: append, inside the same `esc_html_e()` string after "Star ratings are ignored.", the sentence " For a trip with uploaded photos, every upload is shown; hide the ones you don’t want."

- [ ] **Step 4: Run the smoke tests to see them pass**

Run: `make smoke 2>&1 | grep -E 'FAIL|ok, '`
Expected: no FAIL lines.

- [ ] **Step 5: Commit**

```bash
git add templates/settings.php tests/playground/upload-pages.php
git commit -m "Make Immich optional in settings (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 7: Docs

**Files:**
- Modify: `README.md`, `readme.txt`

**Interfaces:** none.

Write in the README's existing voice: plain, specific, no marketing. The Privacy section already covers private storage, deletion with the trip and `exif`; don't repeat it, link to it.

- [ ] **Step 1: README.md**

1. Intro (line 3): replace "Photos come from [Immich](https://immich.app) and are served through WordPress, so Immich stays private." with "Photos come from [Immich](https://immich.app), or you upload them to the trip; either way they're served through WordPress, so Immich and the original files stay private."
2. "How it works", first sentence: replace "has a date range and, optionally, an Immich album." with "has a date range and takes its photos from Immich (everything taken in those dates, or one album) or from photos uploaded to it."
3. "How it works", after step 2 add a paragraph (not a numbered step):

   "A trip of uploads uses every photo uploaded to it, and needs no dates: left empty, they're taken from its first and last photo. Uploads with no capture time can't go on a day, so sync leaves them out, along with those outside the trip's dates and those taken at home, and the notice after a sync counts each. Places are named offline, from the GeoNames data the plugin ships (see Privacy)."
4. "Which photos are shown": add a first bullet: "On a trip of uploads, every upload is a favourite: each day with photos gets a step that shows them all. Hide the ones you don't want."
5. Pages table, edit row: "dates, album, visibility, sync, step list" becomes "dates, photos (Immich or uploads), visibility, sync, step list".
6. Setup: before `composer install`, add "Needs WordPress 6.7 or later, which converts iPhones' HEIC photos, and PHP's `exif` extension for uploading photos to trips." In the bullet list, change "- Immich:" to "- Immich (optional):", and add a bullet after it:

   "- Uploads, without Immich: on a trip, choose "Photos uploaded to this trip", then **Add photos**. Closing the media window syncs the trip. iPhone browsers strip a photo's location unless you turn on Options → Location in the photo picker, and messaging apps strip it too, so upload from the photo library. See Privacy for where uploads are kept."
7. "`make smoke` covers …": add "trips of uploaded photos and their pages" to the list.

- [ ] **Step 2: readme.txt**

1. Line 19: replace "Requires an Immich server reachable from your WordPress server; Dawarich is optional." with "Photos come from an Immich server reachable from your WordPress server, or are uploaded to each trip, which needs PHP's exif extension. Dawarich is optional."
2. Installation step 2: replace "enter the Immich URL, and optionally Dawarich's, under Site. Everyone who makes trips enters their own Immich API key, and optionally their Dawarich key and home location, under Your accounts." with "optionally enter the Immich and Dawarich URLs under Site. Everyone who makes trips enters their own Immich API key if they use Immich, and optionally their Dawarich key and home location, under Your accounts."
3. Installation step 3: replace with "Create a trip at `/travel-steps/new`. For Immich photos, set its dates and press Sync. For uploads, choose "Photos uploaded to this trip", press Save and add photos, and close the media window when they're in: that syncs the trip."

- [ ] **Step 3: Check the wording**

Read both files' changed sections once. Check: no claim the code doesn't back (every string quoted matches the UI), and "Immich" is called optional everywhere it's introduced.

- [ ] **Step 4: Commit**

```bash
git add README.md readme.txt
git commit -m "Document trips of uploaded photos, with Immich optional (#6)" -m "Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE"
```

---

### Task 8: Verify and open the PR

**Files:** none committed (scripts go in the scratchpad).

- [ ] **Step 1: Full check**

Run: `make check`
Expected: every unit test passes, and smoke prints `N ok, 0 FAIL`.

- [ ] **Step 2: Browser, end to end on `make demo`**

With `make demo` running (port 9400) and a light colour scheme this time, on a new trip of uploads (Task 3 Step 5 covers the first upload):

1. Reload the edit page: the modal doesn't reopen.
2. Add photos, upload a non-photo saved as `.probe/notaphoto.jpg` (`echo hello > .probe/notaphoto.jpg`): the modal shows "Only photos can be added to a trip." Close it with nothing else changed: the page does **not** submit the sync form (no navigation).
3. Add photos, upload a second photo on another day (`DateTimeOriginal=2025:06:02 11:00:00`, GPS 52.1409 N 10.2689 W), press Done: "Synced. 1 new draft step.", two photos.
4. Add photos, select a photo, "Delete permanently" (accept the confirm; `evaluate_script`'s default `dialogAction` is accept), close: the page syncs, and the Photos panel says one photo fewer.
5. On the trip page (`View trip`), the photos show through the proxy, with Pin, Hide and Cover and no heart.

Delete `.probe/` afterwards; stop `make demo`.

- [ ] **Step 3: Docker (`wordpress:php8.3-apache`)**

Write `<scratchpad>/docker-check.sh` and run it from the repo root. It must clean up its containers and network even on failure (`trap … EXIT`):

```sh
#!/bin/sh
set -eu
NET=ts-check
cleanup() { docker rm -f ts-wp ts-db >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create "$NET" >/dev/null
docker run -d --name ts-db --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run -d --name ts-wp --network "$NET" -p 9500:80 \
  -e WORDPRESS_DB_HOST=ts-db -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp \
  -e WORDPRESS_CONFIG_EXTRA="define('WP_ENVIRONMENT_TYPE','local');" \
  -v "$PWD":/var/www/html/wp-content/plugins/travel-steps:ro wordpress:php8.3-apache >/dev/null
WP="docker run --rm --network $NET --volumes-from ts-wp --user 33:33 -e WORDPRESS_DB_HOST=ts-db -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp wordpress:cli-php8.3 wp"
until docker exec ts-wp test -f /var/www/html/wp-config.php && $WP db check >/dev/null 2>&1; do sleep 2; done
$WP core install --url=http://localhost:9500 --title=check --admin_user=admin --admin_password="$(openssl rand -hex 12)" --admin_email=admin@example.test --skip-email >/dev/null
$WP plugin activate travel-steps >/dev/null
docker cp .probe/doolin.jpg ts-wp:/tmp/doolin.jpg
OUT=$($WP --user=admin eval '
	$trip = wp_insert_post( [ "post_type" => "ts_trip", "post_title" => "Check", "post_status" => "publish", "post_author" => 1 ] );
	update_post_meta( $trip, "_ts_source", "uploads" );
	require_once ABSPATH . "wp-admin/includes/file.php";
	require_once ABSPATH . "wp-admin/includes/media.php";
	require_once ABSPATH . "wp-admin/includes/image.php";
	$_REQUEST = [ "post_id" => $trip, "travel_steps_photo" => "1" ];
	$tmp = wp_tempnam( "doolin.jpg" );
	copy( "/tmp/doolin.jpg", $tmp );
	$id = media_handle_sideload( [ "name" => "doolin.jpg", "tmp_name" => $tmp ], $trip );
	echo is_wp_error( $id ) ? "ERR " . $id->get_error_message() : $id . " " . wp_basename( get_attached_file( $id ) ) . " " . $trip;
')
echo "sideload: $OUT"
set -- $OUT
ID=$1 FILE=$2 TRIP=$3
APP=$($WP user application-password create admin check --porcelain)
CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:9500/wp-content/uploads/travel-steps/$TRIP/$FILE")
echo "direct file URL: HTTP $CODE (want 403)"
JSON=$(curl -s -u "admin:$APP" "http://localhost:9500/wp-json/wp/v2/media/$ID?context=edit")
echo "$JSON" | grep -q "$FILE" && echo "REST names the file: FAIL" || echo "REST names no file: ok"
echo "$JSON" | grep -q 'uploads/travel-steps' && echo "REST has the uploads path: FAIL" || echo "REST has no uploads path: ok"
```

`docker cp` needs `.probe/doolin.jpg` (Task 3 Step 5); recreate it if deleted. Pull `wordpress:cli-php8.3` if it's missing (`docker pull wordpress:cli-php8.3`).
Expected: `sideload: <id> <uuid>.jpg <trip>`, `HTTP 403`, and both REST lines `ok`. If the direct URL isn't 403, look at `uploads/travel-steps/.htaccess` in the container before changing anything.

- [ ] **Step 4: Open the PR**

Push the branch and open a PR titled "Trips with uploaded photos: the trip page, the modal and docs (#6)". Body: what it adds (the Photos select, the Photos panel and modal, the sync notice, wording, settings, docs); the six deviations from the spec listed above; the probe's two fixes; test results (`make check` total, the browser run, the Docker output); "Part of #6" (no closing keyword: #6 closes after the user's manual checks on selfhoster, listed on #6). End the body with the line `https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE`.

```bash
git push -u origin uploaded-photos-6-ui
gh pr create --title "Trips with uploaded photos: the trip page, the modal and docs (#6)" --body-file <scratchpad>/pr2-body.md
```
