# A race-proof sync lock

`Lock::run()` keeps its lock in an option, taken with `add_option()`. It isn't race-proof, and that's accepted.

## The race

Core's `add_option()` checks `get_option()` first, then runs `INSERT … ON DUPLICATE KEY UPDATE`, and only returns false when the query affects no rows. Two requests can both take the lock if:

- both run the `get_option()` check before either one inserts;
- and their `time()` values differ, so the second insert changes the row instead of leaving it as it was.

The 10-minute takeover of a stale lock (`update_option()`) has no compare-and-swap either, so two requests can both take over a dead lock.

## Why this is out of scope

The window is a few milliseconds, and it has to fall across a second boundary. A double click on Sync usually lands in the same second, and then the second request is refused correctly. A request that checks after the first one has inserted is also refused.

The locks that use it:

- **Trip sync** (`TripSync::sync()`). Two syncs of one trip at the same moment could create two steps for the same day. It hasn't been seen happening. The likeliest case is the media window closing (which syncs a trip of uploads) at the same moment as a Sync click.
- **Moving the photo cache to hashed names** (`RestController::migrate_cache()`). A second run finds nothing left to move.

The background cache fill (#18) doesn't take this lock.

A fix needs direct SQL: an `INSERT IGNORE` to take the lock, and `UPDATE … WHERE option_value = <old>` for the takeover. It also has to clear the option caches, and work on Playground's SQLite as well as MySQL. That's more code and more risk than a race this unlikely is worth.

If a duplicate step does turn up, reopen this. The fix above is the place to start.

## Prior requests

- #67: "Lock::run() lets two requests take the lock when they start in different seconds"
