=== Travel Steps ===
Contributors: donncha
Tags: travel, journal, map, immich, dawarich
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Polarsteps-style travel journal: your route from Dawarich, photos from Immich, and each day's story as a WordPress post.

== Description ==

Travel Steps turns a trip into a map, a timeline and a story on your own WordPress site.

* **Route** from your [Dawarich](https://dawarich.app) location history.
* **Photos** from [Immich](https://immich.app), served through WordPress so Immich stays private.
* **Story** in the block editor: each day with favourite photos becomes a draft step you write up and publish.

Sync creates a draft step for every day with favourites, with a heading and a Trip photos block for each place you visited. Pin, hide or choose a cover photo on the trip page. Only published steps of published trips are public, and your home area is kept off every map.

Requires a Dawarich and an Immich server reachable from your WordPress server. Creating and syncing trips needs the `manage_options` capability by default.

== Installation ==

1. Upload the plugin with its `vendor/` directory (or run `composer install --no-dev` in it) and activate it.
2. Open `/travel-steps/settings` and enter your Dawarich and Immich URLs and API keys, and optionally your home location.
3. Create a trip at `/travel-steps/new`, set its dates and press Sync.

== Changelog ==

= 0.1.0 =
* First release.
