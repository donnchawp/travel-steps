=== Travel Steps ===
Contributors: donncha
Tags: travel, journal, map, immich, dawarich
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Polarsteps-style travel journal: photos from Immich or uploaded to the trip, an optional route from Dawarich, and each day's story as a post.

== Description ==

Travel Steps shows a trip on your own WordPress site as a map beside a day-by-day timeline. The route comes from your [Dawarich](https://dawarich.app) location history, or from where your photos were taken. Photos come from [Immich](https://immich.app), or you upload them to the trip; either way they're served through WordPress, so Immich and the original files stay private.

Sync creates a draft step for every day with favourite photos, with a heading and a Trip photos block for each place you visited. You write each step up in the block editor and publish it. Pin, hide or choose a cover photo on the trip page. Only published steps of published trips are public, and your home area is kept off every map. A trip can also have a password, with lists of logged-in users who see it without one.

Photos come from an Immich server reachable from your WordPress server, or are uploaded to each trip, which needs PHP's exif extension. Dawarich is optional. Authors and up can make trips, each with their own Immich and Dawarich API keys and home location; a trip always uses its author's.

== Installation ==

1. Upload the plugin with its `vendor/` directory (or run `composer install --no-dev` in it) and activate it.
2. Open `/travel-steps/settings`. As an administrator, optionally enter the Immich and Dawarich URLs under Site. Everyone who makes trips enters their own Immich API key if they use Immich, and optionally their Dawarich key and home location, under Your accounts.
3. Create a trip at `/travel-steps/new`. For all your Immich photos, set its dates and press Sync; for an Immich album the dates are optional. For uploads, choose "Photos uploaded to this trip", press Save and add photos, and close the media window when they're in: that syncs the trip if anything changed.

== External services ==

Travel Steps connects to three services. The plugin sends nothing anywhere else: Leaflet, the map library, ships with the plugin.

= OpenStreetMap map tiles =

The map on each trip page loads its background images from the OpenStreetMap Foundation's tile servers (`tile.openstreetmap.org`). The visitor's browser requests the tiles directly, so the OpenStreetMap Foundation receives the visitor's IP address, browser user agent, the trip page's address (as the referrer) and which part of the map they view. This happens whenever someone opens a trip page. Map data © OpenStreetMap contributors.

* [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
* [Terms of use](https://osmfoundation.org/wiki/Terms_of_Use)
* [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy)

= Dawarich (your own server, optional) =

If you set up Dawarich, syncing a trip makes your WordPress server ask your Dawarich server for the location points between the trip's dates. It sends the trip author's Dawarich API key and the date range. Nothing is sent to Dawarich at any other time, and visitors never contact it. Dawarich is self-hosted software, so its terms and privacy are those of whoever runs that server; see [dawarich.app](https://dawarich.app).

= Immich (your own server) =

Syncing a trip makes your WordPress server ask your Immich server for the photos taken during the trip, or in the album you chose. It sends the trip author's Immich API key and the date range or album id. The trip edit page also lists the author's Immich albums, for the author only. Photos on trip pages are fetched from Immich by your WordPress server and served from your site, so visitors never contact Immich. Immich is self-hosted software, so its terms and privacy are those of whoever runs that server; see [immich.app](https://immich.app).

== Credits ==

Place names come from [GeoNames](https://www.geonames.org) (towns of 500 people or more, and country names), licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/), trimmed to names, countries and positions and filtered (most neighbourhoods and historical places left out, and Paris's and Marseille's arrondissements named after their city). They ship with the plugin, so naming a place sends nothing anywhere.

== Changelog ==

= 0.3.0 =
* Trips can use photos uploaded to the trip instead of Immich, which is now optional. Uploads are stored privately, served without their GPS data, and their file names are kept out of every listing. HEIC needs WordPress 6.7.
* Places are named offline from GeoNames, so a trip of uploads gets place names without Dawarich.
* A trip of uploads takes its dates from its photos, and follows them as you add more.
* Authors and up can make trips, each with their own Immich and Dawarich API keys and home location.
* An upload with no time taken can be given one; the date and time are suggested from the file name where it has them.
* An Immich album trip takes any dates you leave out from the album.
* Fixes: other authors' steps no longer appear in a trip; pin, hide and cover need permission to edit trips; a date that doesn't exist is refused instead of crashing sync.

= 0.2.2 =
* The button under a step's photos says how many more it shows ("Show 2 more photos"), instead of counting the photos already on screen too.

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
