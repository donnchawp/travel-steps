# Copying Media Library images into a trip

A trip's photos are the ones uploaded to it. An image already in the Media
Library can't be picked for a trip, or copied into one.

## How trip photos are kept apart

A photo becomes a trip photo only when it's uploaded from the trip's photo
modal, which sends the trip as `post_id` and the `travel_steps_photo` flag.
`Uploads::prefilter()`, `attachment_data()` and `add_attachment()` then:

- give it a UUID as its photo id and another as its file name;
- store it in `uploads/travel-steps/<trip id>/`, behind a deny rule;
- make it a private attachment of the trip;
- read its time, offset and GPS from the original with `Exif::read()`.

`Uploads::modal_query()` limits the trip's modal to that trip's photos, and
`Uploads::exclude()` keeps trip photos out of every other media listing. The
two never mix.

## Why this is out of scope

Downloading the original from the Media Library and uploading it to the trip
already does this. The result is the same as a copy would be, because the
upload goes through the same steps.

Building it in would add:

- a second source in the trip's modal, ordinary media, where picking means
  "copy" rather than "select";
- a copy endpoint that fakes the upload request (the flag and `post_id` are
  read from `$_REQUEST`) so the copy gets the same treatment;
- care to copy from `wp_get_original_image_path()`, not the `-scaled` file:
  core strips EXIF from the files it makes, and without EXIF there's no time
  taken;
- double storage for every copied image;
- a warning that the public original stays where it was, GPS included, which
  the plugin can't fix.

That's a lot of new surface to save one download and re-upload.

A site with many travel photos already in old posts would save real effort
from a bulk copy. That's a one-off migration, though, not something a trip
needs to support.

## Prior requests

- #8 — "Copy existing Media Library images into a trip"
