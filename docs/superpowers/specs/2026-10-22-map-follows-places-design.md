# The map follows the place being read

## Why

The trip map fits the whole trip when the page loads, and as the reader scrolls it only pans to each step's marker, at the zoom of the whole trip. For a trip around Spain, that's a map of Spain the whole way down. A walk around Competa on the third day is a squiggle the reader has to zoom in on by hand, on a phone, and do the same again for the next day.

## Goals

- **The map zooms to the part of the route that belongs to what's being read.** As the reader scrolls through a step, the map moves and zooms to fit each place heading's stretch of the route in turn, out for a long drive, in for a walk.
- **Worked example, from the user.** One day: a drive from home to Cork Airport, a flight to Malaga, a drive to Competa, a walk around Competa. The step has three headings, Cork Airport, Malaga and Competa, each with a favourite photo. Reading "Cork Airport", the map shows the drive from the edge of the home area to the airport. Scrolling to "Malaga", it moves to Spain and shows the drive to Competa. Scrolling to "Competa", it zooms in on the walk around the town.
- **Above the first step** (the intro and stats), the map shows the whole trip, as it does on load. Scrolling back up there zooms back out to it.

## Non-goals

- Showing only the active stretch, or hiding or fading the rest of the route. The whole route stays drawn; only the view changes.
- New controls, or a way to turn the following off.
- Changing how sync makes steps or headings, or what counts as a place.

## Anti-goals

These would count as failures even if the zooming worked:

- **The area around the author's home becomes visible**, or can be worked out, by zooming. Home is already cut out of the track, and nothing in this design may add it back.
- **The track's timestamps reach the browser.** The trip JSON is public for a public trip. It may carry each place's area, but not the times of the track behind it.
- **The map jumps around while the reader is scrolling within one place,** or zooms to a single building because a place has one point.
- **Editing a step's story breaks the map** (renamed, moved or deleted headings make the map zoom to the wrong area, or throw an error).

## Decided

### Where a place's stretch of route comes from (the user's model, the details my call)

- Sync already gives each place heading an anchor holding the place's start time (`id="ts-place-<unix>"`, `Days::ANCHOR_PREFIX`). A heading owns the photos from its time until the next heading's (`Days::anchor_times()`, `Days::section_index()`). This design gives it the same span of the route.
- **A place's span** runs from its anchor time to the next anchor's time in the same step. The first heading's span starts at the start of the step's day, so the stretch before the first photo is included (home to Cork Airport). The last heading's span runs to the end of the step's day.
- **The step's day** is its date (`Trips::day()`), from midnight to midnight in the site's time zone, widened where needed to take in the step's first anchor and its last photo. So a span is never empty or back to front. (My call. Photo days come from Immich's local time, which sync doesn't keep an offset for. On a trip far from the site's zone, the stretch before a day's first photo may be counted with the day before. For trips around Europe on a UTC site, the difference is an hour or two in the small hours.)
- **A step with no anchored headings** (written by hand, or whose headings were removed) has one span: its whole day.
- **Two steps on the same day** each use their own anchors. A step without anchors on a day that another step shares still takes the whole day. (My call. It's rare and harmless.)
- **The area** of a span is the bounding box of the track inside it. A span with no track has no area. A gap in the track (a flight with the phone off) has no points, so it adds nothing, which is why the Cork view stays on Cork.

### Boxes of the track, kept at sync (my call)

- The stored route can't be used for this. It has no times, and on a long trip sync loosens its simplification (up to about 5 km) to fit 8,000 points, which can shrink a walk around a town to two points.
- Instead, sync keeps a summary of the full track before it's simplified: **one bounding box per 10 minutes that has points, per piece of the track,** as `[start, south, west, north, east, piece]`, in a new trip meta. The route itself is unchanged.
  - **Size:** that's at most 144 boxes a day, and fewer in practice, so a two-week trip is a couple of thousand small rows.
  - **Source:** the track is the same one the route comes from today, after `Geo::exclude_near()` has cut out home. For a trip without location history, it's the photos' own positions, as now.
- **Each box records which piece of the track it's on.** The track is split into pieces wherever the route itself breaks, at a jump of more than 20 km (`Geo::split_segments()`).
- **A span's area** is the union of the boxes whose 10 minutes overlap it, keeping only the pieces that pass within 2 km of one of the heading's located photos. When the heading has no located photos, or no piece passes near one, every piece is kept. (My call. Without it, the minutes in Malaga between landing and the airport photo would belong to "Cork Airport", and stretch its view to Spain.)
- **The heading's photos** are the ones it shows, worked out as `View::render_story()` does (`Days::section_index()`). The first heading also takes the photos before its anchor. It's worked out when the trip JSON is built, not stored per heading, so a story edited after sync gets areas for its current headings.
- **Ten minutes is the resolution.** A heading's area can take in up to ten minutes of the stretch either side of its anchor. Next to a flight, that's nothing.
- **The trip JSON** sends areas, never the boxes or their times. Each marker that has an area gets it, as `[[south, west], [north, east]]`. A step-level entry needs an area too. That's the "no anchors" case, which already gets a marker from the step's own position, or none at all. How it's carried is the builder's choice, as long as the browser can find the area for a step and for each heading.
- **Existing trips** have no boxes until their next sync. For them, no areas are sent, and the map pans to the step as it does now. Nothing else changes, and no migration is needed.

### What a heading's area tells a visitor (my call)

- Heading anchors already put each place's start time in the public page and in the trip JSON. The route is drawn in full, and each marker sits on a photo taken under its heading.
- So a heading's area adds a box around a stretch of route that's already on the map, between times the page already shows. That's accepted.
- The anti-goal about timestamps covers the track's own times: the boxes stay on the server.

### What the map does (the user's decisions, the details my call)

- **What's being read** is decided the way the active step is now (an `IntersectionObserver` with a band across the upper part of the viewport). It extends to the place headings inside each step. Within a step, the active place is the last heading that has scrolled into or above the band. Before the step's first heading (its title and date), the first place is active.
- **When the active place changes**, the map flies to fit its area, with padding, in one smooth move. When it has no area, the map pans to the place's marker as it does now. When it has no marker either, the map stays where it is.
- **A maximum zoom** (around street level, about 16) stops a span with one point, or a tiny one, from zooming to a building. (My call.)
- **Above the first step**, the map fits the whole trip again.
- **The active marker** is highlighted as now, and for a step with several places it's the active place's marker that's highlighted. (My call.)
- **Clicking a marker** still scrolls to its heading. The scroll then makes that place active, and the map fits it.
- **Small moves don't refit.** If the new area is already well inside the view and would zoom by less than a level, the map is left alone, so going between two nearby places doesn't wobble. (My call. The builder picks the exact rule.)

## Left to the builder

- The exact JSON shape for areas, and whether the area is worked out in `View` or `RestController`.
- Animation length and padding.
- How the observer tracks headings alongside steps.

## Testing

- Smoke tests for sync storing the 10-minute boxes, from the track with home cut out, and for the trip JSON carrying areas per heading and per step, with no boxes or times in it.
- A long trip whose route is simplified hard still gives a walk its own small area.
- **The worked example as a smoke test:**
  - **The day:** a track from the edge of home to the airport, a gap, a drive and a walk, under three anchored headings.
  - **The check:** each heading's area covers its stretch and nothing from the others. The first heading's area includes the points before its anchor time.
- A trip synced before this change, with no boxes, gives no areas and no error.
- A step whose headings are edited away gets one area for its day.
- The map's behaviour is checked by hand on selfhoster after a sync. Playground can't show a visitor's view, but the user is logged in on selfhoster.
