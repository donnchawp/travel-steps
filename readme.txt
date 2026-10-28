=== Travel Steps Storyteller ===
Contributors: donncha
Tags: travel, journal, map, immich, dawarich
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A travel journal with photos from Immich or uploaded to the trip, an optional route from Dawarich, and a post for each day's story.

== Description ==

Travel Steps Storyteller shows a trip on your own WordPress site as a map beside a day-by-day timeline. The route comes from your [Dawarich](https://dawarich.app) location history, or from where your photos were taken. Photos come from [Immich](https://immich.app), or you upload them to the trip.

Visitors never reach Immich. WordPress fetches the photos and serves them itself, so Immich and the original files stay private.

Sync creates a draft step for every day with favourite photos. Each place you visited that day gets a heading and a Trip photos block. If you sync a day before you reach its next place, add that place yourself: insert the Trip place pattern from the block inserter, then click the place's first photo in its Trip photos block. You write each step in the block editor and publish it. On the trip page you can pin or hide a photo, or make one the cover. On a phone, press Edit photos first.

Favourites in Immich decide which photos a step shows. Every photo uploaded to a trip counts as a favourite. A step without favourites, such as one you add with Add step, gets an automatic pick spread across its day. The rest of a day's photos wait behind "Show N more photos". Sync skips screenshots unless they're favourites, and ignores star ratings.

Only published steps of published trips are public. Your home is kept off every map. Photos taken at home show without a place when you chose them for the trip, as uploads, in an Immich album, or as favourites; a trip of Immich photos picked by its dates leaves out the rest. A step with no position, such as a day at home, shows on the timeline without a marker on the map.

A trip can also have a password. Logged-in users on its viewer lists see it without one. Its edit page gives a share link that opens it without the password, until the password changes.

Visitors can subscribe to a trip. Once a day, if new days or photos were published, they get one plain-text email listing them, with a link to unsubscribe.

Photos come from an Immich server your WordPress server can reach, or are uploaded to each trip, which needs PHP's exif extension. Dawarich is optional. Authors and up can make trips, each with their own Immich and Dawarich API keys and home location. A trip always uses its author's keys.

== Installation ==

1. Upload the plugin with its `vendor/` directory (or run `composer install --no-dev` in it) and activate it.
2. Open `/travel-steps/settings`. If you're an administrator, enter the Immich and Dawarich URLs under Site. Both are optional. Everyone who makes trips fills in Personal settings: an Immich API key if they use Immich, and a Dawarich key and home location if they want them.
3. Create a trip at `/travel-steps/new`. For all your Immich photos, set its dates and press Sync. For an Immich album the dates are optional. For uploads, choose "Photos uploaded to this trip", press Save and add photos. Close the media window when they're in, and the trip syncs if anything changed.

== Frequently Asked Questions ==

= Does syncing again overwrite what I wrote? =

No. Sync writes a step once, the first time it sees that day. After that it only updates the step's position on the map. It never changes a step's title, story, date, status or tags. A step you move to the bin stays gone. To get that day back, delete the step permanently from the bin and sync again.

= What does my Immich API key need? =

The `asset.read`, `asset.view` and `album.read` permissions. Everyone who makes trips adds their own key on the settings page, and a trip always uses its author's.

= Which address do I enter for Immich or Dawarich? =

The one your WordPress server reaches it at, which isn't always the one you use in your browser. If they share a Docker network, that's something like `http://dawarich_app:3000`.

= The trip pages aren't found =

Travel Steps needs pretty permalinks. Pick anything but Plain under Settings > Permalinks.

= Is there anything to set up on nginx? =

Yes. Photos uploaded to trips and cached copies of Immich photos are kept in two folders under `uploads`. On Apache, an `.htaccess` file blocks them. nginx ignores that, so add these rules (on multisite, use each site's `uploads/sites/<id>/` path):

    location ^~ /wp-content/uploads/travel-steps/ { deny all; }
    location ^~ /wp-content/uploads/travel-steps-cache/ { deny all; }

Without them the files are only hidden by their names, which never appear anywhere visitors can see.

= Can I use a CDN or page cache? =

Yes, but:

* Don't make a CDN cache `/travel-steps/photo/*` whatever cache headers the site sends, such as with Cloudflare's "Cache Everything". Public photos are already marked to be cached for five minutes, and private ones must never be.
* Don't cache `/travel-steps/*` pages for logged-in users, or for visitors with a cookie whose name starts with `wp-postpass_`. They see drafts, or a protected trip.
* Let POST requests to `/travel-steps/trip/*` and `/travel-steps/subscription` through to WordPress. The password, subscribe and unsubscribe forms use them.

= Photos uploaded from my phone have no location =

iPhone browsers remove a photo's location unless you turn on Options > Location in the photo picker. Messaging apps remove it too, so upload from your photo library.

= Do uploaded photos keep their location? =

The original is stored as you uploaded it, location included, but it's never sent to anyone. Visitors get copies the site makes, which have no location or other metadata.

= What happens when I delete the plugin? =

Deleting it on the Plugins screen deletes the cached photos and every subscription. Trips, steps, photos uploaded to trips and settings stay. Deactivating it deletes nothing.

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

= 0.6.1 =
* If a step's sync missed a place, add it with the Trip place pattern from the block inserter, then click the place's first photo in its Trip photos block. That photo and the ones after it, up to the next place, show under the new heading.
* Fixes: links on trip pages are the accent colour again, instead of the blue of the app framework's colour scheme; back closes an open photo instead of leaving the trip, on browsers such as Android Firefox that didn't already; on a trip with hundreds of photos, tapping one late in the trip opens it, not the one before, in Chrome and other Chromium browsers on phones.

= 0.6.0 =
* Travel Steps is now Travel Steps Storyteller, the name it has on WordPress.org. It lives in the folder travel-steps-storyteller, so WordPress sees it as a new plugin: deactivate the old one, remove its travel-steps folder by hand rather than with Delete on the Plugins screen (which deletes subscriptions and cached photos), then add and activate the new one. Trips, steps, settings and links are unchanged. An authentication proxy that lets /wp-content/plugins/travel-steps/* through needs the new folder instead.
* Visitors can subscribe to a published trip from the end of its page. Once a day, at the hour set on the settings page, each trip with newly published days or photos emails its subscribers one plain-text roundup, with links to the new days and a link to unsubscribe. An email address is confirmed before anything is sent, and logged-in users subscribe with a button. The trip's edit page lists its subscribers and says when the last roundup went, to how many, and how many emails the site's mail refused. Subscriptions are included in WordPress's personal data export and erasure.
* Opening a link from a subscription email only shows a page with a button, Confirm subscription or Unsubscribe, so mail scanners that open every link change nothing. A mail client's one-click unsubscribe still works in one step.
* A password-protected trip's edit page has a share link that opens the trip without the password. Changing the password stops old links working. Roundups for a protected trip carry the link, and a link to a day lands on that day.
* Trip pages have link-preview tags, so a shared trip shows its title, introduction and cover in chats and social media.
* As you read a trip, the map zooms to the route under the place heading you're reading, and the other days' routes fade. A long move zooms out, across and back in.
* On a phone, one finger scrolls the page over the trip map instead of moving the map. Pinching still zooms it.
* The trips page lists trips newest first. Drafts without dates stay at the top.
* Photos taken at home are kept, without a place, when they're in the trip's Immich album, or favourites on any trip.
* A trip's edit page names the days that have photos but no step, because none of their photos is a favourite.
* The block editor shows a step's photos taken before its first place heading in the first Trip photos block, as the trip page does.
* Fixes: the lightbox's close button stays on screen while a phone's address bar shows, and its arrows are hidden when there is only one photo; a < in a trip's introduction that doesn't start a tag is kept; a draft trip gets its own URL when saved, so it can't share a published trip's; a password set on a step is dropped, since a step's trip password covers it; two requests can no longer both take a sync's lock.
* Hardening: the app's pages can only be framed by the site itself; trip photos can't be set as featured images; a share link sets a cookie for its own trip only; subscription forms check where a post came from.

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
