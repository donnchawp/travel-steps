# Print Book Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the trip's print version with a short book of photos the author chooses per section on the trip page.

**Architecture:** One ordered list of photo ids in trip meta holds the selection, changed through a new REST route. Pure functions in `PrintLayout` choose each section's lead and split photos into pages. `View` joins them with the story's sections, and `templates/trip-print.php` renders the result.

**Tech Stack:** PHP on WordPress with `wp-app`, plain JS and CSS. Standalone PHP tests and WordPress Playground smoke tests.

**Spec:** `docs/superpowers/specs/2026-11-08-print-book-design.md`

## Global Constraints

- Choosing a print photo never changes pinned, hidden, or what visitors see.
- Hidden photos never print, apart from a chosen cover.
- Anonymous visitors get the not-found page at `/print`, share code or not, before the password form.
- Every photo uses the `preview` size.
- Sizes fit A4 and US Letter: printable area at least 186×255mm.
- Portrait alone: about 137×183mm, centred. Landscape alone: 186mm wide. Two landscapes: each about 118mm tall.
- UI labels: **Print**, **Make lead**.
- Use `make test` and `make smoke`. Stage files by name: the repo root holds private PDFs and notes that must not be committed.
- End each commit message with `Claude-Session: https://claude.ai/code/session_011XCmpcQ7K4jLwwddv7pDK2`.

## Review Focus

1. A chosen photo is hidden, then unhidden: it stops printing, then prints again in its old place.
2. A chosen photo leaves the trip after a sync: it's ignored, and the rest of its section still prints.
3. The lead is removed after photos were chosen out of time order: the next by time becomes lead, not the next chosen.
4. A place heading is added after photos were chosen: photos move to their new sections with no error, and each section still has one lead.
5. A non-editor views a trip whose only chosen photos are on an unpublished step: no Print button, and no photo from that step.

---

### Task 1: Save the print work as a baseline

**Files:** the uncommitted print work: `Makefile`, `README.md`, `readme.txt`, `assets/travel-steps-print.css`, `assets/travel-steps-print.js`, `src/App.php`, `src/PrintLayout.php`, `src/Trips.php`, `templates/_password.php`, `templates/_trip-gate.php`, `templates/trip-print.php`, `templates/trip.php`, `tests/PrintLayoutTest.php`, `tests/playground/password-trips.php`, `tests/playground/share-links.php`, plus this plan and its spec.

**Interfaces:** Produces a branch with a clean starting point.

- [ ] **Step 1: Create the branch:** `git switch -c print-book`
- [ ] **Step 2: Run the tests:** `make test && make smoke`. Expected: no `FAIL` lines.
- [ ] **Step 3: Commit those files by name**, message `Add a print version of trips`, describing the current behaviour rather than the patch's old message. Check `git status` shows no PDF staged.

### Task 2: Choosing leads and splitting pages

**Files:**
- Modify: `src/PrintLayout.php` (replace `size()`, `rows()`, `image_size()`, `side_by_side()` and their constants)
- Test: `tests/PrintLayoutTest.php` (replace the existing checks)

**Interfaces:**
- Produces:
  - `PrintLayout::lead_first( array $photos, array $print_ids ): array` takes a section's photos by time and returns the chosen ones, lead first, then the others by time. The lead is the one earliest in `$print_ids`.
  - `PrintLayout::pages( array $chosen ): array` returns `list<array{layout: string, photos: array}>`, with layouts `PrintLayout::LANDSCAPE`, `PORTRAIT`, `STACK`, `PAIR`, `THREE`.
  - `PrintLayout::after_removal( array $print_ids, string $asset, array $section_photos ): array` returns the list without `$asset`. If `$asset` was the section's lead, the section's earliest remaining chosen photo by time moves to the front of the list.

- [ ] **Step 1: Write the failing checks**, using the file's `check()` and `photo()` helpers:

```php
check( 'lead is first chosen, others by time', [ 3, 1, 2 ] === $nums( PrintLayout::lead_first( [ photo( 1 ), photo( 2 ), photo( 3 ) ], [ $id( 3 ), $id( 2 ), $id( 1 ) ] ) ) );
check( 'unchosen photos drop out', [ 2 ] === $nums( PrintLayout::lead_first( [ photo( 1 ), photo( 2 ) ], [ $id( 2 ) ] ) ) );
check( 'ids not in the section are ignored', [ 1 ] === $nums( PrintLayout::lead_first( [ photo( 1 ) ], [ $id( 9 ), $id( 1 ) ] ) ) );
check( 'one photo: alone', [ 'landscape' ] === $layouts( PrintLayout::pages( [ photo( 1 ) ] ) ) );
check( 'portrait alone', [ 'portrait' ] === $layouts( PrintLayout::pages( [ photo( 1, 2000, 3000 ) ] ) ) );
check( 'size unknown: landscape', [ 'landscape' ] === $layouts( PrintLayout::pages( [ [ 'id' => 'x' ] ] ) ) );
check( 'two photos: each alone', [ 'landscape', 'portrait' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2, 2000, 3000 ) ] ) ) );
check( 'three: lead, then two landscapes stacked', [ 'landscape', 'stack' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2 ), photo( 3 ) ] ) ) );
check( 'three: two portraits side by side', [ 'landscape', 'pair' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2, 2000, 3000 ), photo( 3, 2000, 3000 ) ] ) ) );
check( 'three: a landscape and a portrait side by side', [ 'landscape', 'pair' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2 ), photo( 3, 2000, 3000 ) ] ) ) );
check( 'four: lead, then three together', [ 'landscape', 'three' ] === $layouts( PrintLayout::pages( [ photo( 1 ), photo( 2 ), photo( 3 ), photo( 4 ) ] ) ) );
check( 'five: lead, three, then one alone', [ 'landscape', 'three', 'landscape' ] === $layouts( PrintLayout::pages( array_map( 'photo', [ 1, 2, 3, 4, 5 ] ) ) ) );
check( 'no photos: no pages', [] === PrintLayout::pages( [] ) );
// Chosen out of time order: 3, then 1, then 2. Removing lead 3 makes 1 the lead.
check( 'removing the lead: next by time leads', [ $id( 1 ), $id( 2 ) ] === PrintLayout::after_removal( [ $id( 3 ), $id( 2 ), $id( 1 ) ], $id( 3 ), [ photo( 1 ), photo( 2 ), photo( 3 ) ] ) );
check( 'removing another keeps the lead', [ $id( 3 ), $id( 1 ) ] === PrintLayout::after_removal( [ $id( 3 ), $id( 2 ), $id( 1 ) ], $id( 2 ), [ photo( 1 ), photo( 2 ), photo( 3 ) ] ) );
```

`$id( $n )` is `photo( $n )['id']`, and `$nums` maps photos to their numbers, as the file's existing `$ids` does.

- [ ] **Step 2: Run:** `php tests/PrintLayoutTest.php`. Expected: fatal error, as the methods don't exist.
- [ ] **Step 3: Implement the three methods** and rewrite the class docblock. A photo is portrait when `h > w`. `array_chunk( $rest, 3 )` splits photos after the lead, except that exactly one remaining photo after a lead, or a remainder of one, is a page alone.
- [ ] **Step 4: Run:** `php tests/PrintLayoutTest.php`. Expected: every line `ok`.

The print template breaks until Task 4, so commit Tasks 2–4 together at the end of Task 4.

### Task 3: Storing the selection

**Files:**
- Modify: `src/Trips.php`, `src/RestController.php`, `src/View.php`
- Create: `tests/playground/print-book.php`
- Modify: `tests/playground/smoke.php` (require it after `share-links.php`)

**Interfaces:**
- Consumes: `PrintLayout::after_removal()`.
- Produces:
  - `Trips::META_PRINT = '_travel_steps_print'`, `Trips::print_ids( int $trip_id ): array`.
  - `POST /travel-steps/v1/trips/{id}/print-photo`, with `asset` and `action` (`add`, `remove`, `lead`), and permission `Trips::can_edit_trip()`. Returns `{ asset, action, ids }`. A photo not in the trip returns 400 with code `travel_steps_not_in_trip`.
  - `View::story_sections( \WP_Post $step, array $photos ): array` returns `list<array{index: int, before: array, after: array, photos: array}>` in story order, with sections whose headings are nested inside other blocks last.
  - `View::section_photos( int $trip_id, string $asset ): array` returns the non-hidden photos of that photo's section.

- [ ] **Step 1: Write failing smoke checks** in `print-book.php`, using `$pw_trip`, `$pw_rows`, `$rest_post`, `$render`, on a new trip:
  - An editor's `add` returns 200 and `Trips::print_ids()` holds the id.
  - A contributor and a logged-out visitor get a refused status.
  - A photo not in the trip gets 400.
  - `add` twice stores the id once.
  - `lead` moves it to the front of the list.
  - After `add`, `Trips::pinned()` and `Trips::hidden()` are unchanged and the trip page's photos are unchanged.
  - Review Focus 3, through REST: choose 3, 1, 2 in one section, remove 3, and the list starts with 1.
- [ ] **Step 2: Run:** `make smoke`. Expected: those checks fail.
- [ ] **Step 3: Move `render_story()`'s splitting into `story_sections()`**, with `render_story()` calling it. A heading repeated with the same time gets no photos of its own, as now. Existing trip page checks guard this refactor.
- [ ] **Step 4: Implement `print_ids()`, `section_photos()` and the route**, registered and written as `photo-state` is. `lead` adds the photo if it's absent, and moves it to the front. `section_photos()` looks through `Trips::steps( $trip_id, true )`. It does not call `CacheFill::schedule()`, because every chosen photo is already visible and so already filled.
- [ ] **Step 5: Run:** `make smoke`. Expected: only checks of the print template fail, if any.

### Task 4: The print page

**Files:**
- Modify: `templates/trip-print.php`, `assets/travel-steps-print.css`, `assets/travel-steps-print.js`, `src/View.php`
- Test: `tests/playground/print-book.php`, `tests/playground/password-trips.php`, `tests/playground/share-links.php`

**Interfaces:**
- Consumes: `lead_first()`, `pages()`, `story_sections()`, `print_ids()`.
- Produces: `View::print_book( \WP_Post $trip, array $steps ): array` returns `list<array{step: \WP_Post, sections: list<array{before: array, after: array, chosen: array, text_page: bool, first: bool}>}>`. Empty sections and steps are left out. `text_page` is true for a step's first kept section, and for a later one with non-empty blocks. Photos are `shown` plus `rest` from `Trips::photos_by_step()`, so hidden ones are excluded.

- [ ] **Step 1: Write failing smoke checks**, on a trip with two place headings and three photos under each:
  - Nothing chosen, as editor: cover and the note `No photos are chosen for printing yet.`, with no `ts-book-photos`.
  - One section chosen: its text prints; the other section's text doesn't.
  - Each photo's URL is the `preview` size.
  - The step title appears once, though two sections are chosen.
  - Photos chosen before the first heading: the title page comes before them.
  - A later section with no text and no heading has no text page.
  - Logged out, with and without a share code: not found, status 404, no `name="post_password"`, no photo ids.
  - Logged-in non-viewer on a protected trip: the password form.
  - Review Focus 1: hide a chosen photo and it's gone; unhide and it's back in the same place.
  - Review Focus 2: remove a chosen photo from `META_PHOTOS`; the section's other photo prints and nothing errors.
  - Review Focus 4: add a heading to the story after choosing; the photos sit under their new sections.
  - Review Focus 5: a chosen photo on a draft step doesn't appear for a non-editor.
- [ ] **Step 2: Update `password-trips.php` and `share-links.php`.** Their `print` checks render anonymously, which now returns not found. Render as a logged-in subscriber for the password checks, assert not found for the anonymous share-link check, and remove the checks for `is-low` and medium images.
- [ ] **Step 3: Run:** `make smoke`. Expected: the new checks fail.
- [ ] **Step 4: Implement `print_book()` and rewrite the template.** At the top: `nocache_headers()`, then `if ( ! is_user_logged_in() ) { include __DIR__ . '/_notfound.php'; return; }`, then `_trip-gate.php`. Remove `travel_steps_print_size`. Editors with an empty book get the note and a link back to the trip. The text page is `.ts-book-text`. Each photo page is `.ts-book-photos.is-{layout}`.
- [ ] **Step 5: Write the CSS.** Each page has `break-before: page` and `height: 255mm`, with photos centred. Maximum image sizes:

| Layout | Maximum size |
|---|---|
| `landscape` | 186mm wide, 200mm tall |
| `portrait` | 137mm wide, 183mm tall |
| `stack` | 118mm tall each |
| `pair` | two equal columns, 200mm tall |
| `three` | 74mm tall each, stacked |

- [ ] **Step 6: Centre short text in the print script.** Long text must flow across pages, and flex layouts fragment unreliably across browsers, so `.ts-book-text` is a plain block by default. The script adds `is-short` when its height is under 230mm, measured in pixels using a `1mm` test element. `.ts-book-text.is-short` then centres its text on a 255mm-tall page. Without JS, text stays at the top of the page.
- [ ] **Step 7: Run:** `make test && make smoke`. Expected: no `FAIL` lines.
- [ ] **Step 8: Commit** `src/PrintLayout.php`, `src/Trips.php`, `src/RestController.php`, `src/View.php`, `templates/trip-print.php`, `assets/travel-steps-print.css`, `assets/travel-steps-print.js`, and the test files, with the message `Print a book of chosen photos instead of every shown photo`.

### Task 5: Choosing photos on the trip page

**Files:**
- Modify: `templates/trip.php`, `assets/travel-steps-trip.js`, `assets/travel-steps.css`, `src/View.php`
- Test: `tests/playground/print-book.php`

**Interfaces:**
- Consumes: the `print-photo` route, `View::print_book()`.
- Produces: `View::has_print_book( \WP_Post $trip, array $steps ): bool`. Each editor's figure carries `data-print-section="{step id}-{section index}"` and, if chosen, `data-print="lead"` or `data-print="chosen"`.

- [ ] **Step 1: Write failing smoke checks:**
  - Editors get `ts-photo-print` on shown photos and those behind "Show more", but not hidden ones.
  - Visitors get no `ts-photo-print`.
  - The figure's `data-print` values match the selection.
  - Print button: absent for anonymous and for a share-link visitor; present for an editor on an empty trip; absent for a logged-in non-editor on an empty trip; present for them once a published step has a chosen photo; absent if only a draft step does.
- [ ] **Step 2: Run:** `make smoke`. Expected: those checks fail.
- [ ] **Step 3: Add the markup.** A **Print** toggle with class `ts-photo-print` and a **Make lead** button with class `ts-photo-lead` sit in the existing `.ts-photo-tools`. The section index comes from `Days::section_index()` over the step's sorted `Days::anchor_times()`. Show the Print button when `is_user_logged_in()` and either `$can_edit` or `View::has_print_book()`.
- [ ] **Step 4: Add the JS.** On load and after each response, for each section: mark its lead and chosen photos, show **Make lead** only on chosen photos that aren't the lead, and set a tag on each chosen photo: `Lead` or `2 of 3`. Above three photos, the tag adds `, best with 3`. The tag sits on the photo so it works for photos behind "Show more", away from their section's grid. Keep tools visible on chosen photos as they are on pinned ones.
- [ ] **Step 5: Run:** `make smoke`. Expected: no `FAIL` lines.
- [ ] **Step 6: Commit** the changed files, with the message `Choose photos to print on the trip page`.

### Task 6: Docs and checking the result by hand

**Files:** Modify `README.md`, `readme.txt`.

- [ ] **Step 1: Rewrite README's Printing a trip section** and its route table row: choosing photos, the page layout, sections left out, logged-in only. Remove the filter. Update the Unreleased line in `readme.txt`.
- [ ] **Step 2: Run:** `make check`. Expected: no `FAIL` lines.
- [ ] **Step 3: Check on `make demo` in a browser:**
  - Choose, remove and lead photos without reloading, including one behind "Show more".
  - Save PDFs from Firefox and Chrome on A4 and Letter.
  - On each, confirm that short text is centred, long text runs on, no photo crosses a page, and no page is blank.
- [ ] **Step 4: Commit** `README.md` and `readme.txt`, with the message `Document the print book`.
- [ ] **Step 5: Report the results.** Leave deploying and merging to the author, who checks on selfhoster first.
