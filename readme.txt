=== Travel Steps ===
Contributors: donncha
Tags: travel, journal, map, immich, dawarich
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Polarsteps-style travel journal: photos from Immich, an optional route from Dawarich, and each day's story as a WordPress post.

== Description ==

Travel Steps shows a trip on your own WordPress site as a map beside a day-by-day timeline. The route comes from your [Dawarich](https://dawarich.app) location history, or from where your photos were taken. Photos come from [Immich](https://immich.app) and are served through WordPress, so Immich stays private.

Sync creates a draft step for every day with favourite photos, with a heading and a Trip photos block for each place you visited. You write each step up in the block editor and publish it. Pin, hide or choose a cover photo on the trip page. Only published steps of published trips are public, and your home area is kept off every map.

Requires an Immich server reachable from your WordPress server; Dawarich is optional. Creating and syncing trips needs the `manage_options` capability by default.

== Installation ==

1. Upload the plugin with its `vendor/` directory (or run `composer install --no-dev` in it) and activate it.
2. Open `/travel-steps/settings` and enter your Immich URL and API key, and optionally Dawarich's and your home location.
3. Create a trip at `/travel-steps/new`, set its dates and press Sync.

== External services ==

Travel Steps connects to three services. The plugin sends nothing anywhere else: Leaflet, the map library, ships with the plugin.

= OpenStreetMap map tiles =

The map on each trip page loads its background images from the OpenStreetMap Foundation's tile servers (`tile.openstreetmap.org`). The visitor's browser requests the tiles directly, so the OpenStreetMap Foundation receives the visitor's IP address, browser user agent, the trip page's address (as the referrer) and which part of the map they view. This happens whenever someone opens a trip page. Map data © OpenStreetMap contributors.

* [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
* [Terms of use](https://osmfoundation.org/wiki/Terms_of_Use)
* [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy)

= Dawarich (your own server, optional) =

If you set up Dawarich, syncing a trip makes your WordPress server ask your Dawarich server for the location points between the trip's dates. It sends your Dawarich API key and the date range. Nothing is sent to Dawarich at any other time, and visitors never contact it. Dawarich is self-hosted software, so its terms and privacy are those of whoever runs that server; see [dawarich.app](https://dawarich.app).

= Immich (your own server) =

Syncing a trip makes your WordPress server ask your Immich server for the photos taken during the trip, or in the album you chose. It sends your Immich API key and the date range or album id. The trip edit page also lists your Immich albums. Photos on trip pages are fetched from Immich by your WordPress server and served from your site, so visitors never contact Immich. Immich is self-hosted software, so its terms and privacy are those of whoever runs that server; see [immich.app](https://immich.app).

== Changelog ==

= 0.2.1 =
* Dates sent to Dawarich are URL-encoded, so servers read the "+00:00" time zone correctly instead of as a space.

= 0.2.0 =
* Dawarich is optional: without it, trips are mapped from where their photos were taken.
* Distance includes flights, and GPS glitches are dropped from the route, distance and countries.
* Settings: remove a saved API key, and paste a "latitude, longitude" pair for home. Unreadable home coordinates show an error instead of being cleared.
* Warnings when a synced trip has no favourite photos, and when published steps are hidden because their trip isn't public.
* The trips list runs oldest first by start date.

= 0.1.0 =
* First release.
