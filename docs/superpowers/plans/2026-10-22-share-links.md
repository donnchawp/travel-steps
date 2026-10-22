# Share Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each password-protected trip gets one share link that opens it without the form. Changing the password stops the link working. Trip pages get link-preview tags.

**Architecture:**
- **The code** is `wp_hash()` of the trip ID and its current password, so nothing is stored, and a new password gives a new code.
- **The trip page** (`templates/trip.php`) takes a valid `?share=` code, sets core's password cookie as the password form does, and renders the trip in the same request. The page script then tidies the address bar.
- **The photo route** accepts the same code for the trip's cover photo only, so a preview fetcher can load the `og:image`.
- **Preview tags** are built in `View` and printed by `templates/_header.php`.

**Tech Stack:** WordPress 6.7+, PHP 8.0+, wp-app, plain JS. Smoke tests run in WordPress Playground (`make smoke`) and unit tests as plain PHP (`make test`). `make check` runs both.

**Spec:** `docs/superpowers/specs/2026-10-22-share-links-design.md`

## Global Constraints

- **The code is equivalent to the password.** The plugin never logs it, and never puts it in a cacheable response.
- **A missing, wrong or malformed code** (including `share[]=x`) renders exactly what no code renders, byte for byte, with no PHP notices.
- **Photo-route refusals** stay a uniform `404 no-store`. Non-public answers keep `Cross-Origin-Resource-Policy: same-site`, and an answer through a code is cached `private`, never `public`.
- **The share response sends `Referrer-Policy: strict-origin`.** Not `no-referrer`, which gets OpenStreetMap tiles blocked.
- **Public text:** British spelling, no em dashes, and no other trip app named.
- **Every commit** ends with `Claude-Session: https://claude.ai/code/session_01673F61MoBbqkEkbKPRYHgQ`.
- **Never run `make deploy`,** and never read `Makefile.local`.

## Review Focus

1. **A password change.** The old code then gets the password form on the page, and a 404 on the cover photo. (Task 1, Task 2)
2. **A code from trip A on trip B.** It's refused on both page and photo. (Task 1, Task 2)
3. **A code on a non-cover photo** of the right trip gets a 404. (Task 2)
4. **The password `"0"`, no password, or a draft or private trip:** there's no share link, and a stray `?share=` changes nothing. (Task 1, Task 4)
5. **An intro with entities and tags** (`B&amp;B <b>x</b>`) gives an `og:description` of `B&amp;B x`, escaped exactly once. (Task 3)

---

### Task 1: Share code, and opening the trip with it

**Files:**
- Modify: `src/Trips.php`, near `password_cookie()` and `handle_password_post()` (lines 357–390).
- Modify: `templates/trip.php:11-31`.
- Modify: `assets/travel-steps-trip.js`, near the top, where `root` is read.
- Create: `tests/playground/share-links.php`.
- Modify: `tests/playground/smoke.php:1155`. Add `require '/smoke/share-links.php';` after `password-trips.php`, since it reuses that file's helpers.

**Interfaces:**
- Produces:
  - `Trips::SHARE_ARG = 'share'`: the query argument.
  - `Trips::share_code( \WP_Post $trip ): string` gives `wp_hash( 'share|' . $trip->ID . '|' . $trip->post_password )`, 32 hex characters. It's `''` unless `'publish' === $trip->post_status && Trips::is_protected( $trip )`.
  - `Trips::share_url( \WP_Post $trip ): string` gives `add_query_arg( SHARE_ARG, share_code(), Trips::url( $trip ) )`, or `''` when there's no code.
  - `Trips::share_matches( \WP_Post $trip, $code ): bool` takes any value from the request. It's false for a non-string, an empty expected code, or no `hash_equals` match.
  - `Trips::handle_share_link( \WP_Post $trip ): bool` reads `$_GET[ SHARE_ARG ]`. When it matches:
    - it sets core's cookie to a phpass hash of `$trip->post_password`, and puts the same value in `$_COOKIE[ Trips::password_cookie() ]`, so `needs_password()` passes in this request;
    - it sends `Referrer-Policy: strict-origin`;
    - it returns true.
    Otherwise it returns false and does nothing.
- Refactor: `handle_password_post()` and `handle_share_link()` share `private static function set_password_cookie( string $password, string $url ): void`. It holds the phpass hash, the `post_password_expires` filter, `COOKIEPATH`, `COOKIE_DOMAIN` and the secure flag from `$url`'s scheme.
- `trip.php`:
  - Inside the existing `is_protected` block, after `handle_password_post()`, set `$shared = Trips::handle_share_link( $trip );`. Default it to `false` above the block.
  - When `$shared`, the `<article class="ts-trip">` gets `data-clean-url="<?php echo esc_url( Trips::url( $trip ) ); ?>"`.
- `travel-steps-trip.js`: if `root.dataset.cleanUrl` is set, call `history.replaceState( null, '', root.dataset.cleanUrl )`.

- [ ] **Step 1: Write the failing tests** in `tests/playground/share-links.php`.

  The header comment follows `password-trips.php`'s: required at the end of `smoke.php`, in its scope, and the helpers it uses.

  ```php
  [ $sh_trip ] = $pw_trip( 'Shared Lisbon' );
  $pw_set( $sh_trip, 'kerry and clare' );
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
  check( '…and its link holds the code, not the password', false !== strpos( Trips::share_url( get_post( $sh_trip ) ), 'share=' . $sh_code ) && false === strpos( Trips::share_url( get_post( $sh_trip ) ), 'kerry' ) );
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
  // For the later tasks: the code from before the password change, the current one, and a second protected trip.
  $sh_old_code = $sh_code;
  $sh_code     = Trips::share_code( get_post( $sh_trip ) );
  [ $sh_other_published ] = $pw_trip( 'Another Lisbon' );
  $pw_set( $sh_other_published, 'another' );
  ```

- [ ] **Step 2: Run `make smoke`, saving the output to a log in the scratchpad once.** Grep the log for `FAIL`. Expect it to fail: `Trips::SHARE_ARG` is undefined.

- [ ] **Step 3: Implement** the Interfaces above in `src/Trips.php`, `templates/trip.php` and `assets/travel-steps-trip.js`.

- [ ] **Step 4: Run `make check`, saved to a log.** Expect exit 0 and no `FAIL` lines.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Trips.php templates/trip.php assets/travel-steps-trip.js tests/playground/share-links.php tests/playground/smoke.php
  git commit -m "Open a password-protected trip from a share link built from its password"
  ```

### Task 2: The cover photo through a share code

**Files:**
- Modify: `src/View.php:141-155` (`cover_url`).
- Modify: `src/RestController.php`: `maybe_serve_photo()` (103), `access()` (221), `serve_photo()` (247), `photo_answer()` (281).
- Test: `tests/playground/share-links.php`.

**Interfaces:**
- Consumes: `Trips::share_matches()` and `Trips::SHARE_ARG` from Task 1.
- Produces:
  - `View::cover_id( int $trip_id ): string`: the asset `cover_url()` uses, chosen or automatic, or `''`. `cover_url()` becomes `RestController::photo_url( $trip_id, cover_id(), $size )`, or `''`.
  - `RestController::access( int $trip_id, string $asset, $share = '' ): string`. When the existing checks give `''`, it returns `'private'` if `Trips::share_matches( $trip, $share ) && $asset === View::cover_id( $trip_id )`.
  - `RestController::photo_answer( int $trip_id, string $asset, string $size, string $if_none_match, $share = '' ): array` passes `$share` to `access()`.
  - `RestController::serve_photo( int $trip_id, string $asset, string $size, $share = '' ): void` passes it on.
  - `maybe_serve_photo()` passes `wp_unslash( $_GET[ Trips::SHARE_ARG ] ?? '' )`. `share_matches()` rejects a non-string, and it isn't sanitised because a code is only ever compared.

- [ ] **Step 1: Write the failing tests**, appended to `share-links.php`. `$pw_photo` and `$pw_cover` come from `password-trips.php`.

  ```php
  wp_set_current_user( 0 );
  $pw_enter( null );
  check( 'share photo: the code lets the cover through, privately', 'private' === RestController::access( $sh_trip, $pw_cover, $sh_code ) );
  check( '…but no other photo', '' === RestController::access( $sh_trip, $pw_photo, $sh_code ) );
  check( '…and an old code nothing', '' === RestController::access( $sh_trip, $pw_cover, $sh_old_code ) );
  check( '…nor another trip’s', '' === RestController::access( $sh_trip, $pw_cover, Trips::share_code( get_post( $sh_other_published ) ) ) );
  check( '…nor a non-string', '' === RestController::access( $sh_trip, $pw_cover, [ $sh_code ] ) );
  $sh_refused = RestController::photo_answer( $sh_trip, $pw_photo, 'medium', '', $sh_code );
  check( 'share photo: a refusal is the usual 404 no-store', [ 404, 'no-store' ] === [ $sh_refused['status'], $sh_refused['cache'] ], $sh_refused );
  check( 'cover: cover_url still picks the chosen cover', false !== strpos( View::cover_url( $sh_trip ), $pw_cover ) );
  ```

- [ ] **Step 2: Run `make smoke` to a log.** Expect a failure: `access()` ignores the third argument, so the first check fails.

- [ ] **Step 3: Implement** the Interfaces above.

- [ ] **Step 4: Run `make check` to a log.** Expect exit 0 and no `FAIL` lines.

- [ ] **Step 5: Commit**

  ```bash
  git add src/View.php src/RestController.php tests/playground/share-links.php
  git commit -m "Let a share link's code load the trip's cover photo, so a link preview can show it"
  ```

### Task 3: Link-preview tags on trip pages

**Files:**
- Modify: `src/View.php` (new methods).
- Modify: `templates/trip.php`, before `include __DIR__ . '/_header.php';` (about line 96).
- Modify: `templates/_header.php:19-23`.
- Test: `tests/playground/share-links.php`.

**Interfaces:**
- Consumes: `Trips::share_url()` and `Trips::share_code()` (Task 1), and `View::cover_id()` (Task 2).
- Produces:
  - `View::preview_description( \WP_Post $trip ): string`:
    - the intro's text, from `wp_strip_all_tags()` on `post_content` with entities decoded, and whitespace collapsed;
    - trimmed at a word boundary to at most 200 characters, with `…` added when it's cut;
    - `View::date_range( $trip->ID )` when there's no intro.
    It returns plain text. The header escapes it.
  - `View::preview_meta( \WP_Post $trip, bool $shared ): array<string,string>` maps a property to its content, in this order:
    - `og:type` gives `website`;
    - `og:title` gives `get_the_title( $trip )`;
    - `og:url` gives `Trips::share_url( $trip )` when `$shared`, else `Trips::url( $trip )`;
    - `og:description` is left out when it's empty;
    - `og:image` is the cover at `medium`, with `add_query_arg( Trips::SHARE_ARG, Trips::share_code( $trip ), … )` added when `$shared`, and left out with no cover;
    - `twitter:card` gives `summary_large_image` with an image, else `summary`.
  - `trip.php` sets `$preview_meta = View::preview_meta( $trip, $shared );`. The password form (`_password.php`) sets none, so a protected trip opened without the code gets no preview tags.
  - `_header.php` prints `$preview_meta` after `<title>`, as `<meta property="…" content="…">`. A key starting with `twitter:` uses `name=` instead. Both attributes go through `esc_attr()`.

- [ ] **Step 1: Write the failing tests**, appended to `share-links.php`.

  ```php
  $sh_meta = fn( string $page, string $prop ) => preg_match( '/<meta (?:property|name)="' . preg_quote( $prop, '/' ) . '" content="([^"]*)"/', $page, $m ) ? $m[1] : null;
  $page = $sh_page( $sh_code );
  check( 'preview: a share link’s og:url is the share link', esc_attr( Trips::share_url( get_post( $sh_trip ) ) ) === $sh_meta( $page, 'og:url' ), $sh_meta( $page, 'og:url' ) );
  check( '…and its image is the cover at medium, with the code', false !== strpos( (string) $sh_meta( $page, 'og:image' ), $pw_cover . '/medium' ) && false !== strpos( (string) $sh_meta( $page, 'og:image' ), 'share=' . $sh_code ) );
  check( '…and a large card', 'summary_large_image' === $sh_meta( $page, 'twitter:card' ) );
  check( 'preview: the password form has no preview tags', false === strpos( $sh_page( null ), 'og:' ) );
  wp_update_post( [ 'ID' => $sh_trip, 'post_content' => '<!-- wp:paragraph --><p>B&amp;B <b>x</b></p><!-- /wp:paragraph -->' ] );
  check( 'preview: the description is the intro as text, escaped once', 'B&amp;B x' === $sh_meta( $sh_page( $sh_code ), 'og:description' ), $sh_meta( $sh_page( $sh_code ), 'og:description' ) );
  wp_update_post( [ 'ID' => $sh_trip, 'post_content' => str_repeat( 'Lisbon ', 60 ) ] );
  $sh_long = View::preview_description( get_post( $sh_trip ) );
  check( '…trimmed at a word to 200 characters', mb_strlen( $sh_long ) <= 200 && str_ends_with( $sh_long, 'Lisbon…' ), $sh_long );
  $pw_set( $sh_other_published, '' );
  $page = $render( 'trip.php', (string) $sh_other_published );
  check( 'preview: a public trip’s og:url is its own URL, its image without a code', esc_attr( Trips::url( get_post( $sh_other_published ) ) ) === $sh_meta( $page, 'og:url' ) && false === strpos( (string) $sh_meta( $page, 'og:image' ), 'share=' ) );
  ```

- [ ] **Step 2: Run `make smoke` to a log.** Expect a failure: there's no `og:url`.

- [ ] **Step 3: Implement** the Interfaces above.

- [ ] **Step 4: Run `make check` to a log.** Expect exit 0 and no `FAIL` lines.

- [ ] **Step 5: Commit**

  ```bash
  git add src/View.php templates/trip.php templates/_header.php tests/playground/share-links.php
  git commit -m "Give trip pages link-preview tags: title, summary and cover photo"
  ```

### Task 4: The share link on the edit page

**Files:**
- Modify: `templates/trip-edit.php:263-268`, inside the password `<fieldset>`, after the password `<label>`.
- Modify: `assets/travel-steps-edit.js`.
- Modify: `assets/travel-steps.css`, only if the field needs layout.
- Test: `tests/playground/share-links.php`.

**Interfaces:**
- Consumes: `Trips::share_url()` (Task 1).
- Produces markup, shown only when `$trip && '' !== Trips::share_url( $trip )`:

  ```html
  <div class="ts-share" data-password="{saved password, esc_attr}" data-status="publish">
    <span>Share link</span>
    <input type="text" readonly value="{share URL, esc_url}" class="ts-share-url">
    <button type="button" class="ts-share-copy">Copy</button>
    <small class="ts-muted">Anyone with this link sees the trip without the password. Changing the password gives a new link, and the old one stops working.</small>
    <small class="ts-muted ts-share-stale" hidden>Save to get the link for the new password. The link above stops working once you save.</small>
  </div>
  ```

  All copy goes through `__()` with the `travel-steps` text domain.
- JS:
  - when the `password` input's value differs from `data-password`, or the `status` select's value isn't `publish`, the script greys out the URL and button (`disabled`) and shows `.ts-share-stale`. It reverses that when they match again;
  - Copy calls `navigator.clipboard.writeText()`. If that's missing or fails, it falls back to `input.select()`. On success the button reads "Copied" for two seconds.

- [ ] **Step 1: Write the failing tests**, appended to `share-links.php`. Render the edit page as `trip-intro.php` renders `$edit`, as user 1.

  ```php
  check( 'edit page: a protected trip shows its share link', false !== strpos( $sh_edit( $sh_trip ), 'value="' . esc_url( Trips::share_url( get_post( $sh_trip ) ) ) . '"' ) );
  check( 'edit page: a public trip has none', false === strpos( $sh_edit( $sh_other_published ), 'ts-share-url' ) );
  ```

  `$sh_edit` is a small closure around the same render `trip-intro.php` uses.

- [ ] **Step 2: Run `make smoke` to a log.** Expect a failure on the first check.

- [ ] **Step 3: Implement** the markup and the JS.

- [ ] **Step 4: Run `make check` to a log.** Expect exit 0 and no `FAIL` lines.

  Then check the JS in a browser. Run `make demo`, log in, open a protected trip's edit page, and check:
  - Copy puts the link on the clipboard;
  - editing the password greys the link out and shows the note;
  - putting the password back restores it.

  Open the copied link in a private window. The trip shows, and the address bar holds the clean URL.

- [ ] **Step 5: Commit**

  ```bash
  git add templates/trip-edit.php assets/travel-steps-edit.js assets/travel-steps.css tests/playground/share-links.php
  git commit -m "Show a protected trip's share link on its edit page, with a Copy button"
  ```

### After the tasks

- Open a PR that says "Part of #N", for an issue filed for this feature with the AI note. The user tests on selfhoster:
  - a WhatsApp preview for a public trip and a share link;
  - whether Cloudflare lets the preview fetcher through.
- The next release's changelog gets the share links and the preview tags.
