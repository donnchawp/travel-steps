# Travel Steps

A Polarsteps-style travel journal for your own WordPress, built with [WpApp](https://github.com/akirk/wp-app):

- **Route** from [Dawarich](https://dawarich.app) location history
- **Photos** from [Immich](https://immich.app), served through WordPress, so Immich stays private
- **Story** in WordPress: every step is a post you write in the block editor

## How it works

A **trip** (`ts_trip` post) has a date range and, optionally, an Immich album. **Sync** then:

1. Fetches your Dawarich points for those dates and drops anything near home.
2. Fetches the album's photos, or all photos taken during those dates if there's no album. Photos without GPS get the position you were at when you took them.
3. Finds places where you stayed (by default within 300 m for 90+ minutes) and creates a **step** (`ts_step` post, child of the trip) for each new one. Steps are named after the city in the photos' EXIF or the Dawarich point data.
4. Attaches each photo to the step you were at when you took it.
5. Simplifies the route, splits it at big jumps (flights, ferries) and caches it on the trip.

Re-syncing is safe. It never changes a step's title or story, it doesn't recreate steps you've moved to the bin, and it only adjusts the time of steps it created itself.

## Which photos are shown

Favourites in Immich decide what each step shows:

- **A step with favourites** shows exactly those.
- **A step without favourites** gets an automatic pick (8 by default), spread across your time there. Bursts of shots taken seconds apart count as one.
- **Pin** a photo on the trip page to always show it, or **Hide** it to never show it. Press the button again to undo.
- **Everything else** stays behind a collapsed "Show all N photos".
- **Screenshots** and other images with no camera make/model in EXIF are skipped at sync, unless favourited.
- **Star ratings are ignored**, since they're often a Lightroom workflow rather than a quality score.

Selection happens when the page loads, so pin and hide take effect immediately. New favourites need a re-sync.

A step's **arrival time is its post date**. To add a step by hand, click **Add step**, set the date in the block editor and write it up. The next sync places it on the map from where Dawarich says you were then.

## Pages

| URL | Who |
|---|---|
| `/travel-steps/` | Everyone: published trips (editors also see drafts) |
| `/travel-steps/trip/{slug}` | Map, route and step timeline with photos and lightbox. Editors can pin and hide photos. |
| `/travel-steps/new`, `/travel-steps/trip/{slug}/edit` | Editors: dates, album, visibility, sync, step list |
| `/travel-steps/settings` | Admins: Dawarich/Immich URLs and keys, home privacy zone, step detection |

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
php tests/GeoTest.php        # geometry: distance, simplification, stay detection
php tests/SelectionTest.php  # photo selection
```
