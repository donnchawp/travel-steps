=== Travel Steps ===
Contributors: donncha
Tags: travel, journal, map, immich, dawarich
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Polarsteps-style travel journal: photos from Immich, an optional route from Dawarich, and each day's story as a WordPress post.

== Description ==

Travel Steps turns a trip into a map, a timeline and a story on your own WordPress site.

* **Route** from your [Dawarich](https://dawarich.app) location history, or from where your photos were taken.
* **Photos** from [Immich](https://immich.app), served through WordPress so Immich stays private.
* **Story** in the block editor: each day with favourite photos becomes a draft step you write up and publish.

Sync creates a draft step for every day with favourites, with a heading and a Trip photos block for each place you visited. Pin, hide or choose a cover photo on the trip page. Only published steps of published trips are public, and your home area is kept off every map.

Requires an Immich server reachable from your WordPress server; Dawarich is optional. Creating and syncing trips needs the `manage_options` capability by default.

== Installation ==

1. Upload the plugin with its `vendor/` directory (or run `composer install --no-dev` in it) and activate it.
2. Open `/travel-steps/settings` and enter your Immich URL and API key, and optionally Dawarich's and your home location.
3. Create a trip at `/travel-steps/new`, set its dates and press Sync.

== External services ==

Travel Steps connects to three services. The plugin sends nothing anywhere else: Leaflet, the map library, ships with the plugin.

**OpenStreetMap map tiles.** The map on each trip page loads its background images from the OpenStreetMap Foundation's tile servers (`tile.openstreetmap.org`). The visitor's browser requests the tiles directly, so the OpenStreetMap Foundation receives the visitor's IP address, browser user agent, the trip page's address (as the referrer) and which part of the map they view. This happens whenever someone opens a trip page. Map data © OpenStreetMap contributors.

* [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
* [Terms of use](https://osmfoundation.org/wiki/Terms_of_Use)
* [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy)

**Dawarich** (your own server, optional). If you configure it, when you sync a trip your WordPress server asks the Dawarich server you configure for the location points between the trip's dates, sending your Dawarich API key and the date range. Nothing is sent to Dawarich at any other time, and visitors never contact it. Dawarich is self-hosted software, so its terms and privacy are those of whoever runs that server; see [dawarich.app](https://dawarich.app).

**Immich** (your own server). When you sync a trip, your WordPress server asks the Immich server you configure for the photos taken during the trip (or in the album you chose), sending your Immich API key and the date range or album id. The trip edit page lists your Immich albums, and photos shown on trip pages are fetched from Immich by your WordPress server and served from your site, so visitors never contact Immich. Immich is self-hosted software, so its terms and privacy are those of whoever runs that server; see [immich.app](https://immich.app).

== Changelog ==

= 0.1.0 =
* First release.
