# Mixing Immich photos and uploads in one trip

A trip takes its photos from one source: Immich (everything taken during its
dates, or one album) or the photos uploaded to it. It can't use both.

## How a trip's source works

`Trips::source()` returns one `PhotoSource` for the whole trip: `MediaSource`
when the trip uses uploads, otherwise `ImmichSource` with the author's key.
Sync asks it for the trip's photos, and the photo proxy asks it for each image.
Nothing records which source a single photo came from, because the trip already
says.

## Why this is out of scope

The reasons to mix are a friend's photos and a camera that isn't backed up to
Immich. Immich already handles both:

- a friend can share an album, or their library through partner sharing;
- photos from any camera can be uploaded to Immich in the browser or the app,
  then added to the trip's album.

Mixing in the plugin would mean solving three problems that one source per
trip avoids:

- **Duplicates.** The same photo can be in Immich and uploaded too. Matching
  them by capture time and size is guesswork, and a miss shows it twice.
- **Favourites.** Every upload counts as a favourite, because choosing what to
  upload is the choosing. An Immich photo is a favourite only if it's starred.
  In one list the two mean different things, and steps are built from
  favourites.
- **Serving.** The proxy would need a stored map from each photo id to its
  source to know where to fetch it, and it would have to stay right through
  every sync.

All that to save adding the photos to Immich, which the author is already using.

## Prior requests

- #7 — "Mix Immich photos and uploads in one trip"
