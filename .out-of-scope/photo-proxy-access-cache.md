# Caching the photo proxy's access check

The photo proxy works out who may see a photo from scratch on every request. It
keeps no cached set of a trip's viewable photo IDs.

## What the check costs

`RestController::access()` runs first in `serve_photo()`, before the ETag
comparison, so 304 revalidations pay for it too. For a published trip it calls
`Trips::shown_photo_ids()`, which:

- decodes the trip's `_travel_steps_photos` JSON;
- queries the trip's published steps;
- groups the photos by step, running `Selection::for_step()` for each step.

An editor's request can also decode the list again, through `Trips::photos()`,
and query the attachment through `Uploads::attachment()`.

The check order is deliberate. Unpublishing a step, hiding a photo or changing
the cover has to take effect at the next revalidation, not when some cache
expires.

## Why this is out of scope

The check is cheap next to the request it sits in. It was measured in
WordPress Playground, which runs PHP as WASM and is slower than native PHP:

| Trip                   | Per photo request | Whole trip page |
| ---------------------- | ----------------- | --------------- |
| 100 photos, 10 steps   | 0.52 ms           | 52 ms           |
| 500 photos             | 1.23 ms           | 614 ms          |

Every photo is its own REST request, and loading WordPress for one typically
costs 20–50 ms. The access check is a few percent of that. A cache would remove
the smaller cost and leave the larger one.

A cache would also add a privacy risk. The cached set would have to be cleared
on every change that can shrink it:

- a sync;
- a photo being hidden;
- the cover changing;
- a step being published, unpublished, binned, deleted or moved to another trip;
- the trip's status changing.

If any of those is missed, now or later, a photo that has since become private
is still served. Without a persistent object cache, a transient also costs a
database read per request anyway.

If photo requests ever get slow, look first at what each request loads before
the check runs. Caching the access answer is a poor trade.

## Prior requests

- #15 — "Photo proxy decodes the trip's whole photo list on every request"
