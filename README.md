# Travel Steps

A Polarsteps-style travel journal for your own WordPress, built with [WpApp](https://github.com/akirk/wp-app):

- **Route** from [Dawarich](https://dawarich.app) location history
- **Photos** from [Immich](https://immich.app), served through WordPress, so Immich stays private
- **Story** in WordPress: every step is a post you write in the block editor

## How it works

A **trip** (`ts_trip` post) has a date range and, optionally, an Immich album. A **step** (`ts_step` post, child of the trip) is a day. **Sync**:

1. Fetches your Dawarich points for those dates and drops anything near home.
2. Fetches the album's photos, or all photos taken during those dates if there's no album. Photos without GPS get the position you were at when you took them. A camera that records no time zone gets the offset of the nearest phone photo that does (or the site's), so abroad its photos aren't hours off. Photos are kept by the local date they were taken, so the first morning and last evening of a trip in another time zone aren't cut off.
3. Creates a **draft step for each day with favourites**. The day is the local date the photos were taken (Immich's `localDateTime`).
4. Groups each new day's favourites into **places**. A new place starts when a favourite is more than 2 km (configurable) from the current one and in a different town. A day with several places gets a heading per place in its story and a **place tag** (`ts_place`) for each. Each place also gets an empty paragraph to write in and a **Trip photos** block. The title lists them: "Lisbon, Sintra".
5. Simplifies the route, splits it at big jumps (flights, ferries) and caches it on the trip.

Headings and tags are seeded once. After that a step is yours. Re-syncing never changes its title, story, date, status or tags, and doesn't recreate days whose step you've moved to the bin; the notice after a sync says how many days that skipped, with a link to the trash. To recreate a day, empty its step from the trash and sync again. It only refreshes each step's map position.

Publish the days you want shown. Draft steps and their photos stay private, but a published trip's map route, distance and country count cover all its dates, unpublished days included. To keep a day's whereabouts private, leave it outside the trip's dates.

## Which photos are shown

A step shows photos from its date. Favourites in Immich decide which:

- **A step with favourites** shows exactly those.
- **A step without favourites** (one you added by hand) gets an automatic pick (8 by default), spread across the day. Bursts of shots taken seconds apart count as one.
- **Pin** a photo on the trip page to always show it, or **Hide** it to never show it. Press the button again to undo.
- **Cover** makes a photo the trip's header image; press it again to go back to the automatic choice (the first landscape favourite on a published step). A chosen cover is public with the trip, even if its step isn't.
- **Everything else** stays behind a collapsed "Show all N photos".
- **Days without a step** show no photos at all.
- **Screenshots** and other images with no camera make/model in EXIF are skipped at sync, unless favourited.
- **Star ratings are ignored**, since they're often a Lightroom workflow rather than a quality score.

Shown photos sit under the place heading they belong to. Each seeded heading carries an anchor with its start time (`id="ts-place-<unix>"`) and owns the photos taken from then until the next heading. Rename the headings, write between them, or add your own. Delete one and its photos join the section above. Favourites added later appear in the right section without sync touching the story.

The **Trip photos** block marks where a section's photos go and previews them in the block editor. Move it to put text after the photos, or insert one under a heading of a step made before the block existed. A section without one gets its photos after its text. Only the first block in a section shows photos.

Grids load a 640 px (short side) copy of Immich's preview, made once and cached; a photo shown on its own, and the lightbox, use the full preview.

The map gets a marker per place heading (3a, 3b…), or one per step without headings, numbered by trip day.

Selection happens when the page loads, so pin and hide take effect immediately. New favourites need a re-sync to be picked up, and only create a step for a day that doesn't have one yet.

To add a step by hand, click **Add step**, set its date in the block editor and write it up. It shows that day's photos, and the next sync places it on the map.

## Privacy

Creating, editing and syncing trips uses the site's Immich and Dawarich credentials, which reach your whole photo library and location history, so it needs the `manage_options` capability (administrators) rather than just being able to write posts. To let other roles manage trips, filter it:

```php
add_filter( 'travel_steps_capability', fn() => 'edit_others_posts' );
```

Add the filter before `init`, e.g. in a plugin or mu-plugin, since routes are registered then.

Steps and places are in core REST for the block editor, but a step is only readable when its trip is (published, or you can edit it), and place terms only by editors. The photo proxy serves the public only photos shown on published steps of published trips. Shared caches (CDNs, proxies) keep a public photo for 5 minutes, so unpublishing a step or hiding a photo takes effect within that; browsers revalidate after an hour with an ETag, which re-runs the access check without resending the image.

Leaflet (1.9.4, BSD-2-Clause) ships with the plugin in `assets/vendor/leaflet/`, so no third-party script runs on trip pages. Map tiles still load from OpenStreetMap's tile servers, which see visitors' IP addresses.

## Pages

| URL | Who |
|---|---|
| `/travel-steps/` | Everyone: published trips (editors also see drafts) |
| `/travel-steps/trip/{slug}` | Map, route and step timeline with photos and lightbox. Editors can pin and hide photos. |
| `/travel-steps/new`, `/travel-steps/trip/{slug}/edit` | Trip managers (see Privacy): dates, album, visibility, sync, step list |
| `/travel-steps/settings` | Admins: Dawarich/Immich URLs and keys, home privacy zone, place radius |

## Setup

```bash
composer install --no-dev
```

Then activate the plugin and open `/travel-steps/settings`:

- **Dawarich**: URL as reachable *from the WordPress server* (e.g. `http://dawarich_app:3000` on a shared Docker network) plus the API key from Dawarich → Settings → Account.
- **Immich**: falls back to the URL and key from *Media Picker for Immich* when that's configured. The key needs `asset.read`, `asset.view` and `album.read`.
- **Home**: set coordinates and a radius to keep your home off every map.

## Development

```bash
make test    # standalone: geometry, local days and places, photo selection
make smoke   # end-to-end in WordPress Playground with fake Dawarich/Immich clients
```

`make smoke` covers sync and re-sync (edits kept, binned days stay binned), the sync lock, the REST gate for steps and places, photo visibility, story sections and the trip pages.

## Deploying

Copy the plugin to a server with rsync over SSH. Put the destination in `Makefile.local`, which git ignores:

```make
DEPLOY_DEST = host:/path/to/wp-content/plugins/travel-steps/
```

```bash
make deploy-dry   # what would be copied and deleted
make deploy       # composer install --no-dev, then rsync --delete
```

- It copies your **working tree**, not the last commit, so uncommitted changes go too.
- `tests/`, `.git`, the Makefiles and `.gitignore` stay behind. `vendor/` goes, with runtime dependencies only.
- `--delete` removes files on the server that aren't in your copy, so keep nothing else in the plugin folder there.
- `vendor/composer/installed.php` is copied every time: Composer writes the current commit into it.
- If `vendor/` ever goes missing on the server, the plugin stands down and shows admins a notice instead of breaking the site.

## Proxies and caches

If an authentication proxy guards the whole site, trips aren't public until it lets these paths through:

- `/travel-steps/*`: the trip pages. The new, edit and settings routes still need a WordPress login with the right capability (`edit_posts`, `manage_options`).
- `/wp-json/travel-steps/v1/*`: map data and photos, each checked against the trip's visibility
- `/wp-content/plugins/travel-steps/*`: scripts, styles and Leaflet

Photos come from `/wp-json/travel-steps/v1/trips/{id}/photo/{asset}`. Public ones are sent with `s-maxage=300`, so a CDN that honours origin headers keeps them 5 minutes; drafts and editor URLs are `private`. Don't let a CDN or page cache store `/travel-steps/*` pages for logged-in users: editors see drafts and editing tools.
