=== Travel Steps Storyteller ===
Contributors: donncha
Tags: travel, journal, map, immich, dawarich
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A travel journal with photos from Immich or uploaded to the trip, an optional route from Dawarich, and a post for each day's story.

== Description ==

Travel Steps Storyteller shows a trip on your own WordPress site as a map beside a day-by-day timeline. The route comes from your [Dawarich](https://dawarich.app) location history, or from where your photos were taken. Photos come from [Immich](https://immich.app), or you upload them to the trip.

Visitors never reach Immich. WordPress fetches the photos and serves them itself, so Immich and the original files stay private.

Sync creates a draft step for every day with favourite photos. Each place you visited that day gets a heading and a Trip photos block. You write each step in the block editor and publish it. On the trip page you can pin or hide a photo, or make one the cover. On a phone, press Edit photos first.

Only published steps of published trips are public. Your home is kept off every map. Photos taken at home show without a place when you chose them for the trip, as uploads, in an Immich album, or as favourites; a trip of Immich photos picked by its dates leaves out the rest. A step with no position, such as a day at home, shows on the timeline without a marker on the map.

A trip can also have a password. Logged-in users on its viewer lists see it without one.

Photos come from an Immich server your WordPress server can reach, or are uploaded to each trip, which needs PHP's exif extension. Dawarich is optional. Authors and up can make trips, each with their own Immich and Dawarich API keys and home location. A trip always uses its author's keys.

== Installation ==

1. Upload the plugin with its `vendor/` directory (or run `composer install --no-dev` in it) and activate it.
2. Open `/travel-steps/settings`. If you're an administrator, enter the Immich and Dawarich URLs under Site. Both are optional. Everyone who makes trips fills in Personal settings: an Immich API key if they use Immich, and a Dawarich key and home location if they want them.
3. Create a trip at `/travel-steps/new`. For all your Immich photos, set its dates and press Sync. For an Immich album the dates are optional. For uploads, choose "Photos uploaded to this trip", press Save and add photos. Close the media window when they're in, and the trip syncs if anything changed.

== External services ==

Travel Steps connects to three services, and sends nothing anywhere else. Leaflet, the map library, ships with the plugin.

= OpenStreetMap map tiles =

The map on each trip page loads its background images from the OpenStreetMap Foundation's tile servers (`tile.openstreetmap.org`). The visitor's browser requests the tiles directly. So the OpenStreetMap Foundation receives the visitor's IP address, browser user agent, the trip page's address (as the referrer) and which part of the map they view. This happens whenever someone opens a trip page. Map data © OpenStreetMap contributors.

* [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
* [Terms of use](https://osmfoundation.org/wiki/Terms_of_Use)
* [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy)

= Dawarich (your own server, optional) =

If you set up Dawarich, syncing a trip makes your WordPress server ask your Dawarich server for the location points between the trip's dates. It sends the trip author's Dawarich API key and the date range. Nothing is sent to Dawarich at any other time, and visitors never contact it. Dawarich is self-hosted software, so its terms and privacy are those of whoever runs that server. See [dawarich.app](https://dawarich.app).

= Immich (your own server) =

Syncing a trip makes your WordPress server ask your Immich server for the photos taken during the trip, or in the album you chose. It sends the trip author's Immich API key and the date range or album id. The trip edit page also lists the author's Immich albums, and only the author sees them. Your WordPress server fetches the photos on trip pages from Immich and serves them from your site, so visitors never contact Immich. After a sync, or a change to which photos a trip shows, your server also fetches every photo visitors can see on the trip in the background, using the trip author's key, and keeps a copy. That way the trip keeps working without Immich, unless an administrator sets cached photos to be deleted after a number of days. A photo deleted that way is fetched from Immich again when someone next views it. Immich is self-hosted software, so its terms and privacy are those of whoever runs that server. See [immich.app](https://immich.app).

== Credits ==

Place names come from [GeoNames](https://www.geonames.org), licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). The plugin ships towns of 500 people or more, and country names, trimmed to names, countries and positions. Most neighbourhoods and historical places are left out, and Paris's and Marseille's arrondissements are named after their city. Because the names ship with the plugin, naming a place sends nothing anywhere.

== Changelog ==

= 0.5.1 =
* Photos are served from /travel-steps/photo/, outside REST, at the same URL for everyone: their URLs no longer carry a nonce. A photo that isn't there, or that you can't see, gets the same 404, which caches don't keep, and photos only some people may see can't be loaded by other sites. Old photo URLs redirect to the new ones. Like the trip pages, photos need pretty permalinks, and an authentication proxy that lets /travel-steps/* through now lets their photos through too.
* The trip edit page shows an introduction of plain paragraphs as text, not block markup, and an ampersand as typed. An introduction with more than paragraphs, such as an image or a heading, links to the block editor instead, and saving the form leaves it as it was.
* Fixes: a backslash typed in a trip's title is kept when the trip is saved.

= 0.5.0 =
* After an Immich trip syncs, or you pin, hide or show a photo or change the cover, WordPress caches every photo visitors can see on the trip in the background. The trip keeps working when Immich is down or the author's key stops working, at the cost of disk space for the whole trip. A few photos that always fail no longer hold up the rest.
* A daily sweep deletes cached photos that no trip or upload uses any more. The new site setting "Delete cached photos after (days)" also deletes photos cached longer ago than that; 0, the default, keeps them. Deleting the plugin deletes the photo cache.
* Cached photos are named after a keyed hash of their id instead of the id in their proxy URL, so on nginx without the deny rule nobody can fetch a hidden photo from the cache folder by its id. Photos cached by 0.4.0 or earlier are renamed on the first request after the update.
* On a phone, press Edit photos on the trip page to pin, hide or choose a cover. Until then the photo tools are hidden and can't be pressed by accident.
* On touch screens you swipe between photos in the lightbox, which fills the screen; elsewhere it keeps its arrows. It no longer shows the previous photo while the next one loads, and it stops downloading photos you've moved past.
* Editors of a published trip get a warning when some of its steps aren't published, and those steps' badges say "Draft, not public".
* Immich photos without GPS that Dawarich placed get a town and country from the bundled place names, instead of joining the place around them or heading a day "Somewhere new".
* Fixes: photo URLs are never marked for shared caches when the viewer can edit the trip or the URL carries a nonce; a cached photo deleted during a request is fetched again instead of sent empty.

= 0.4.0 =
* A published trip can have a password. Visitors enter it on the trip page; logged-in users on its viewer lists, and anyone who can edit it, see it without one. Core REST follows the same rule.
* In a trip of uploads, photos taken at home stay on their day, without a place: they add no marker, place name or country. The notice after a sync counts them on a line of their own.
* Visitors now see published steps that have no position, such as a day at home, on the timeline without a marker on the map.
* Sync clears a step's position when it can no longer find one, so a step placed before you set or widened your home no longer shows there.
* A country named differently by Immich, Dawarich or the place names counts once in a trip's stats.
* On the settings page, your own section is now "Personal settings", saved with Save, and its radius is "Home radius".
* Fixes: core REST no longer returns or counts (in X-WP-Total) trips and steps a visitor can't see, however the query names them; password-protected trip pages are kept out of page caches.

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
