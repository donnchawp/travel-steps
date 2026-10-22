# Map Follows Places Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** As a reader scrolls a trip, the map moves and zooms to the stretch of route under the place heading they're reading, and back out to the whole trip above the first step.

**Architecture:**
- **At sync,** `TripSync` keeps a summary of the full track (home already cut out): one box per 10 minutes per piece of track, made by a pure `Geo` helper and stored in a new trip meta.
- **When the trip JSON is built,** `View::areas()` works out each heading's time span from the step's current anchors and day, and unions the boxes into one area per heading (or per step without headings). `RestController::get_trip()` sends the areas keyed by the element id the page already has. The boxes and their times never leave the server.
- **On the page,** the script replaces the step observer with a scroll handler. It finds the heading at the reading line and flies the map to its area.

**Tech Stack:** WordPress 6.7+, PHP 8.0+, wp-app, Leaflet 1.9.4, plain JS. Unit tests are plain PHP (`make test`) and smoke tests run in WordPress Playground (`make smoke`). `make check` runs both.

**Spec:** `docs/superpowers/specs/2026-10-22-map-follows-places-design.md`

## Global Constraints

- **Home stays hidden.** Boxes are made only from the track after `Geo::exclude_near()` (or from the photo-position track, which has no home photos in it).
- **No track times reach the browser.** The trip JSON carries areas as `[[south, west], [north, east]]` and nothing else new.
- **The stored route (`_ts_route`) is unchanged,** as is its simplification.
- **A trip with no boxes** (synced before this change) gets an empty `areas` and behaves as now.
- **Max zoom when following is 16.**
- **Public text:** British spelling, no em dashes, and no other trip app named.
- **Every commit** ends with `Claude-Session: https://claude.ai/code/session_01673F61MoBbqkEkbKPRYHgQ`.
- **Never run `make deploy`,** and never read `Makefile.local`.

## Review Focus

1. **A flight inside a heading's span.** The minutes after landing, before the next heading's photo, must not pull the earlier heading's area across the sea. (Task 1 piece filter, Task 3 worked example)
2. **Ten-minute buckets at a span boundary.** A box overlapping two spans counts in both, so the overlap rule must be `start < to && start + 600 > from`, exactly. (Task 1)
3. **A step whose headings were edited away, or whose anchor falls outside its day.** It gets one area or a widened span, never an empty or inverted one. (Task 3)
4. **A hard-simplified long trip.** A walk keeps its own small area, because boxes come from the raw track. (Task 3)
5. **Scrolling within one heading, or between two close ones.** The map doesn't refit. (Task 4, checked by hand)

---

### Task 1: Track boxes and span areas in `Geo`

**Files:**
- Modify: `src/Geo.php`. Add two public static functions after `split_segments()`.
- Test: `tests/GeoTest.php`.

**Interfaces:**
- Produces:
  - `Geo::BOX_SECONDS = 600`.
  - `Geo::time_boxes( array $points ): array`. It takes points `[lat, lon, t, …]` oldest first, splits them with `self::split_segments()`, and returns `[start, south, west, north, east, piece]` rows.
    - **`start`** is `intdiv( t, 600 ) * 600`.
    - **`piece`** is the segment's index.
    - **Rows** go in piece order, then start order, with coordinates rounded to 5 places. One row per (piece, start) that has points.
  - `Geo::span_area( array $boxes, int $from, int $to, array $near, float $near_m ): ?array` returns `[[south, west], [north, east]]` or null.
    - **Which boxes:** those with `start < $to && start + BOX_SECONDS > $from`, grouped by piece.
    - **Which pieces:** when `$near` (a list of `[lat, lon]`) is non-empty, only pieces whose union box, grown by `$near_m` on each side, contains at least one `$near` point. Grow it by `$near_m / 111320` degrees of latitude, and by that divided by `cos( lat )` for longitude. If no piece qualifies, or `$near` is empty, keep every piece.
    - **Returns:** the union of the kept boxes, or null when no box overlaps the span.

- [ ] **Step 1: Write the failing tests** in `tests/GeoTest.php`, after the `split_segments` checks.

```php
$boxes = Geo::time_boxes( [ [ 37.0, -4.0, 0 ], [ 37.001, -3.999, 300 ], [ 37.002, -4.0, 700 ] ] );
check( 'time boxes: one per 10 minutes, with its bounds and piece', [ [ 0, 37.0, -4.0, 37.001, -3.999, 0 ], [ 600, 37.002, -4.0, 37.002, -4.0, 0 ] ] === $boxes );
$boxes = Geo::time_boxes( [ [ 53.42, -6.27, 0 ], [ 40.47, -3.56, 400 ] ] );
check( 'time boxes: a jump in the same 10 minutes starts a new piece', [ 0, 1 ] === array_column( $boxes, 5 ) && [ 0, 0 ] === array_column( $boxes, 0 ) );
$row = [ [ 0, 1.0, 1.0, 1.0, 1.0, 0 ], [ 600, 2.0, 2.0, 2.0, 2.0, 0 ], [ 1200, 3.0, 3.0, 3.0, 3.0, 0 ] ];
check( 'span area: only the boxes that overlap the span', [ [ 2.0, 2.0 ], [ 2.0, 2.0 ] ] === Geo::span_area( $row, 650, 1200, [], 2000 ) );
check( 'span area: none overlap', null === Geo::span_area( $row, 1800, 2400, [], 2000 ) );
$two = [ [ 0, 51.84, -8.50, 51.93, -8.40, 0 ], [ 600, 36.67, -4.50, 36.68, -4.49, 1 ] ];
check( 'span area: only the piece near the heading’s photos', [ [ 51.84, -8.50 ], [ 51.93, -8.40 ] ] === Geo::span_area( $two, 0, 1200, [ [ 51.8413, -8.4911 ] ], 2000 ) );
check( 'span area: a photo just outside a piece still picks it', [ [ 36.67, -4.50 ], [ 36.68, -4.49 ] ] === Geo::span_area( $two, 0, 1200, [ [ 36.69, -4.49 ] ], 2000 ) );
check( 'span area: no photo near any piece keeps them all', [ [ 36.67, -8.50 ], [ 51.93, -4.49 ] ] === Geo::span_area( $two, 0, 1200, [ [ 0.0, 0.0 ] ], 2000 ) );
check( 'span area: no photos keeps them all', [ [ 36.67, -8.50 ], [ 51.93, -4.49 ] ] === Geo::span_area( $two, 0, 1200, [], 2000 ) );
```

- [ ] **Step 2: Run it to see it fail**

Run: `make test`
Expected: a fatal error, `Call to undefined method TravelSteps\Geo::time_boxes()`.

- [ ] **Step 3: Implement `Geo::time_boxes()` and `Geo::span_area()`** as the interfaces say. Comment each with the why: the boxes keep a walk's shape after the route is simplified, and the piece filter keeps a flight's far end out of a heading's area.

- [ ] **Step 4: Run the tests to see them pass**

Run: `make test`
Expected: every line `ok`, with no `FAIL`.

- [ ] **Step 5: Commit**

```bash
git add src/Geo.php tests/GeoTest.php
git commit -m "Summarise a track as a box per 10 minutes, and find a span's area from them"
```

---

### Task 2: Sync keeps the track's boxes

**Files:**
- Modify: `src/Trips.php`. Add the meta constant after `META_ROUTE`, and the reader next to `Trips::photos()`.
- Modify: `src/TripSync.php`, next to the `META_ROUTE` update (line 145).
- Create: `tests/playground/map-areas.php`.
- Modify: `tests/playground/smoke.php`. Add `require '/smoke/map-areas.php';` after `trip-intro.php`.

**Interfaces:**
- Consumes: `Geo::time_boxes()` (Task 1).
- Produces:
  - `Trips::META_BOXES = '_ts_boxes'`. It's JSON: the `Geo::time_boxes()` rows of the track the route is made from.
  - `Trips::boxes( int $trip_id ): array` gives the decoded rows, or `[]` when the meta is missing or isn't valid JSON.
- `TripSync::run()` stores `wp_slash( wp_json_encode( Geo::time_boxes( $track ) ) )` under `META_BOXES`, from the same `$track` it simplifies.

- [ ] **Step 1: Write the failing tests** in `tests/playground/map-areas.php`.

  The header comment follows `trip-intro.php`'s. It's required at the end of `smoke.php`, in its scope, and uses `check()`, `row()`, `TrackDawarich`, `FakeSource` and `$get`. The trip is the spec's worked example on 2025-06-01 (UTC):
  - **Cork:** points every 2 minutes, 06:00–07:00, from (51.93, -8.40) to Cork Airport (51.8413, -8.4911). Then points at the airport every 10 minutes until 08:30.
  - **The flight:** no points, 08:30–12:00.
  - **Malaga:** points at Malaga Airport (36.6749, -4.4991) every 5 minutes, 12:00–12:20. Then every 2 minutes, 12:20–13:40, in a straight line to Competa (36.8345, -3.9686).
  - **Competa:** a walk every 2 minutes from 14:00 to 16:58, within 0.003° of Competa.
  - **Favourites:** Cork Airport at 07:30, Malaga Airport at 12:20 and Competa at 14:00, made with `row()`. Use cities 'Cork', 'Málaga' and 'Cómpeta', and `country` 'Ireland' for the first.

  The trip runs 2025-06-01 to 2025-06-02. A filler track of 9,000 points, every 9 seconds from 2025-06-02 00:00, zigzagging in 0.005° steps around (37.2, -3.6) (small enough steps that `Geo::drop_glitches()` keeps them), forces the route's simplification loose. Keep the trip's ID, its step and the sync result in variables for Task 3.

```php
check( 'map areas: sync keeps a box per 10 minutes of the track', count( $ma_boxes ) > 0 && 6 === count( $ma_boxes[0] ), count( $ma_boxes ) );
check( '…from before the route is simplified, so the walk keeps its shape', count( array_filter( $ma_boxes, fn( $b ) => $b[0] >= strtotime( '2025-06-01 14:00 UTC' ) && $b[0] < strtotime( '2025-06-01 17:00 UTC' ) ) ) === 18 );
check( '…on separate pieces either side of the flight', count( array_unique( array_column( $ma_boxes, 5 ) ) ) >= 2 );
check( '…and with home cut out', [] === array_filter( $ma_boxes, fn( $b ) => Geo::distance( $b[1], $b[2], $home_lat, $home_lon ) < $home_radius_m ) );
```

  Set the author's home (`Settings` account `home_lat`, `home_lon`, `home_radius_km`) to a point 3 km north of the first Cork point with a 2 km radius, and add two track points inside it at 05:50 and 05:55. Look at how `HomeDawarich`'s trip sets home earlier in `smoke.php` and do the same. Clear it after.

- [ ] **Step 2: Run it to see it fail**

Run: `make smoke`
Expected: `FAIL map areas: sync keeps a box per 10 minutes of the track`. (`Trips::META_BOXES` is undefined, so it may fatal instead. The smoke harness reports an exception as a `FAIL` line.)

- [ ] **Step 3: Implement** `Trips::META_BOXES`, `Trips::boxes()` and the store in `TripSync::run()`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `make check`
Expected: no `FAIL` lines.

- [ ] **Step 5: Commit**

```bash
git add src/Trips.php src/TripSync.php tests/playground/map-areas.php tests/playground/smoke.php
git commit -m "Keep a box per 10 minutes of a trip's track at sync, home left out"
```

---

### Task 3: Each heading's area in the trip JSON

**Files:**
- Modify: `src/View.php`. Add `areas()` after `markers()`.
- Modify: `src/RestController.php`, `get_trip()` (lines 136–163).
- Modify: `tests/playground/map-areas.php`.

**Interfaces:**
- Consumes: `Geo::span_area()` (Task 1), and `Trips::boxes()` (Task 2).
- Produces:
  - `View::AREA_NEAR_M = 2000`.
  - `View::areas( \WP_Post $step, array $photos, array $boxes ): array<string, array>`. `$photos` is the step's visible photos, as `get_trip()` already builds them for `markers()`. Returns element id ⇒ `[[s, w], [n, e]]`, leaving out spans with no area.
    - **The day:** `$day_from` is midnight of `Trips::day( $step )` in `wp_timezone()`, and `$day_to` is a day later. Widen them so `$day_from <= ` the first anchor and `$day_to > ` the step's last photo's `t`.
    - **With anchors** (`Days::anchor_times()`, sorted), span `i` runs from `i ? starts[i] : $day_from` to `starts[i+1] ?? $day_to`. Its key is `Days::ANCHOR_PREFIX . starts[i]`.
    - **Its photos** are those with `Days::section_index( $starts, t ) === i + 1`, plus section 0's for `i === 0`. Their `[lat, lon]` go to `$near`, skipping null positions.
    - **Without anchors,** there's one span, `$day_from` to `$day_to`, keyed `'step-' . $step->ID`, with all the step's located photos as `$near`.
- `get_trip()` adds `'areas' => (object) $areas`, merged over all steps in the same loop as the markers. `Trips::boxes()` is read once, before the loop.

- [ ] **Step 1: Write the failing tests** in `tests/playground/map-areas.php`, after Task 2's. Publish the trip and its step, then read `/travel-steps/v1/trips/{id}` as the admin.

```php
$ma_areas = (array) ( $ma_data['areas'] ?? [] );
[ $cork, $malaga, $competa ] = array_values( $ma_areas ) + [ null, null, null ];
check( 'map areas: one per place heading, keyed by its anchor', array_map( fn( $t ) => Days::ANCHOR_PREFIX . $t, $ma_starts ) === array_keys( $ma_areas ), array_keys( $ma_areas ) );
check( '…Cork Airport’s covers the drive from home to the airport, and nothing in Spain', $cork && $cork[0][0] > 51.8 && $cork[1][0] >= 51.92 && $cork[0][1] <= -8.49 );
check( '…Málaga’s covers the drive to Cómpeta', $malaga && $malaga[0][1] <= -4.49 && $malaga[1][1] >= -3.97 && $malaga[1][0] < 37 );
check( '…and Cómpeta’s is just the walk', $competa && $competa[1][0] - $competa[0][0] < 0.01 && $competa[1][1] - $competa[0][1] < 0.01 );
check( '…though the route was simplified to fit', array_sum( array_map( 'count', $ma_data['route'] ) ) <= 8000 );
check( '…and no track times or boxes are sent', ! isset( $ma_data['boxes'] ) && false === strpos( wp_json_encode( $ma_data ), (string) strtotime( '2025-06-01 12:00 UTC' ) ) );
```

  Then:
  - **Headings edited away.** Replace the step's content with one paragraph and no headings, and read the JSON again: `areas` has the single key `'step-' . $step_id`, reaching from below 37° to above 51.9°.
  - **An anchor outside the day.** Give the step one heading anchored at 23:30 the day before. Its area exists and includes Cork.
  - **A trip synced before this change.** Delete `Trips::META_BOXES`: the response is 200, and `areas` encodes as `{}`.
  - **Clean up.** Delete the trip and its steps at the end.

- [ ] **Step 2: Run it to see it fail**

Run: `make smoke`
Expected: `FAIL map areas: one per place heading, keyed by its anchor`.

- [ ] **Step 3: Implement `View::areas()` and the `get_trip()` change.**

- [ ] **Step 4: Run the tests to see them pass**

Run: `make check`
Expected: no `FAIL` lines.

- [ ] **Step 5: Commit**

```bash
git add src/View.php src/RestController.php tests/playground/map-areas.php
git commit -m "Send each place heading's area of the route with a trip's map data"
```

---

### Task 4: The map follows the reader

**Files:**
- Modify: `assets/travel-steps-trip.js`, the Map section (lines 24–135).
- Modify: `README.md:70`, and the matching description paragraph in `readme.txt` if it describes the map's markers.

**Interfaces:**
- Consumes: `trip.areas` (Task 3): element id ⇒ `[[s, w], [n, e]]`. It may be missing or `{}`.
- Behaviour (no automated test, as the plugin has no JS harness; checked by hand below):
  - **Replace `watchSteps()`'s `IntersectionObserver`** with a `scroll` and `resize` handler throttled by `requestAnimationFrame`. It's set up in `drawTrip()` and run once there.
  - **The reading line:**
    - **On narrow screens,** where `matchMedia( '(max-width: 860px)' )` matches the CSS breakpoint, the map is stacked at the top, so the line is `mapWrap.getBoundingClientRect().bottom + 0.3 * ( innerHeight - that bottom )`.
    - **Otherwise,** it's `0.3 * innerHeight`.
  - **The active step** is the last `.ts-step` whose top is at or above the line. If there's none, the whole trip is active.
  - **The active key:**
    - **In the active step,** it's the id of the last element matching `[id^="ts-place-"]` whose top is at or above the line, or else the first such element.
    - **Without one,** it's `'step-' + id`.
  - **When the key changes:**
    - **Highlighting:**
      - Toggle `.ts-step.is-active` as now.
      - Highlight the marker whose `m.anchor` is the key, or the step's markers if none has it.
      - Record markers by anchor as well as by step in `drawTrip()`.
    - **Moving the map:**
      - **An area for the key:** fly to it. Skip the move when the map's bounds already contain it and `Math.min( 16, map.getBoundsZoom( area, false, L.point( 30, 30 ) ) ) - map.getZoom() < 1`. Otherwise call `map.flyToBounds( area, { padding: [ 30, 30 ], maxZoom: 16, duration: 0.8, animate: ! reduced } )`, where `reduced` is `matchMedia( '(prefers-reduced-motion: reduce)' ).matches`.
      - **No area, but a marker for the key** (or else the step's first): `panTo` it, as now.
      - **Neither:** leave the map.
      - **The whole trip:** fit the trip bounds `drawTrip()` already computes, with the same padding, or `setView( [ 20, 0 ], 2 )` when they aren't valid.
  - **On load at the top of the page,** the key is the whole trip, which is the view `drawTrip()` has just set. Don't fly.
- README: after the marker sentence at line 70, add: "As you scroll, the map zooms to the part of the route under the heading you're reading, and back out to the whole trip above the first step. A trip zooms like this after its first sync with this version."

- [ ] **Step 1: Implement the behaviour above** in `assets/travel-steps-trip.js`, keeping the file's comment style (short why-comments).

- [ ] **Step 2: Check the syntax and run the suite**

Run: `node --check assets/travel-steps-trip.js && make check`
Expected: no output from `node`, and no `FAIL` lines.

- [ ] **Step 3: Check by hand in the Playground demo** (`make demo`, http://127.0.0.1:9400). Sync the demo trip if it has no boxes. With the browser at desktop and at phone width:
  - Scroll from the intro into the first step: the map flies in.
  - Scroll on through the headings and steps: it moves to each.
  - Scroll back to the top: it shows the whole trip.
  - Scroll within one heading: it doesn't move.
  - Click a marker: the page scrolls to its heading, and the map fits it.

  Report what was seen. The demo may not have a Dawarich track, in which case areas come from photo positions.

- [ ] **Step 4: Commit**

```bash
git add assets/travel-steps-trip.js README.md readme.txt
git commit -m "As the reader scrolls, zoom the trip map to the route under the heading they're reading"
```
