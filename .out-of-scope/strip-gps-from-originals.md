# Removing GPS from uploaded originals

The original of a photo uploaded to a trip is kept exactly as it was, GPS
included. The plugin reads its time and position from it and never changes it.

## How originals are protected

Uploaded originals live in `uploads/travel-steps/<trip id>/`, and are only meant
to be reached through the photo proxy:

- The proxy never sends an original, only copies WordPress made, with no
  metadata but the colour profile. It refuses to send a copy with GPS in it.
- Apache obeys the folder's `.htaccess` deny rule.
- Every folder has an empty `index.html`, so none can be listed.
- Each original's file name is a random UUID that's never shown anywhere:
  `Uploads::prepare_for_js()`, `rest_item()` and `xmlrpc_item()` strip it, and
  it isn't the photo id in the proxy URL.

nginx ignores `.htaccess`, so there the file names are all that's left. The
README gives the nginx rule that blocks both folders:

```nginx
location ^~ /wp-content/uploads/travel-steps/ { deny all; }
location ^~ /wp-content/uploads/travel-steps-cache/ { deny all; }
```

## Why this is out of scope

The nginx rule closes the gap completely, in the server's config, without
touching anyone's photos. Removing GPS from originals would only matter on an
nginx site that leaves the rule out and also leaks a file name somehow.

Doing it properly is fiddly and changes the user's own files:

- Only the GPS IFD can go. Removing the whole EXIF segment takes the
  orientation tag with it, and the original then displays sideways.
- Lightroom exports often carry GPS again in XMP, in a separate APP1 segment.
- HEIC needs its own handling.
- It would have to be a setting, off by default, with the UI and readme saying
  what it does to the files.

A data-changing feature that guards against a gap a config line already closes
isn't worth it.

## Prior requests

- #10 — "Remove GPS from uploaded originals (extra protection on nginx)"
