# Trips with uploaded photos: sync and data (PR 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a trip take its photos from uploads to that trip, so that sync turns them into steps. This PR covers the sync, data and hardening; the trip page, modal and docs follow in PR 2.

**Architecture:** Sources return raw photo rows (wall-clock `local`, `zoned`). `TripSync` resolves times, takes missing dates from the photos, names places and counts what it left out, for every source. Immich trips still do what they do today. A new `MediaSource` reads the trip's uploads and the `_ts_exif` that #5 stores. Trip meta `_ts_source` picks the source.

**Tech Stack:** PHP 8.0+, WordPress 6.7+, akirk/wp-app, WordPress Playground smoke tests (`make smoke`), standalone PHP unit tests (`make test`).

**Spec:** `docs/superpowers/specs/2026-10-12-uploaded-trip-photos-design.md` (the "PR 1" half).

## Global Constraints

- Immich trips behave exactly as before. Existing smoke checks keep their assertions. Test doubles (`FakeSource`, `row()`, the `AlbumImmich` call) change only to follow the new `photos()` signature and row fields.
- Only the trip's author changes what a trip pulls in (`Trips::can_sync_trip()`, from #17).
- Trip photo originals are never served or modified. Nothing hands out a trip photo's file URL or file name.
- Requires WordPress 6.7 (HEIC conversion) and the PHP `exif` extension for trip uploads.
- Commit subjects are plain imperative sentences ending in `(#6)`. Every commit message ends with `Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE`.
- Run tests with `make test` (unit, seconds) and `make smoke` (Playground, about 3 minutes; it prints `ok`/`FAIL` lines). `make check` runs both.
- Smoke-script gotchas: `use` statements can't go inside the `try` block; give new variables names that are not already used in `smoke.php` (it's one shared scope); `(array) get_post_meta( …, true )` on an empty value gives `['']`, so use `?: []`.
- Never contact a real Immich or Dawarich server. Never run `make deploy`. Never read Makefile.local.

## Deviations from the spec (agreed while planning)

- **Sync-notice query string moved to PR 2.** The spec has `run_sync_and_redirect()` passing the left-out counts to the edit page in PR 1. That moves to PR 2, beside the notice that shows them, so it's tested where it's seen.
- **`_ts_version` is a counter.** It's incremented rather than set to the time, so two edits in the same second still change the ETag. Task 7 updates the spec.

## Review Focus

1. **Switching a synced Immich trip to uploads.** The stored photos must be replaced by the uploads, with no Immich ids left behind. Task 3 adds the test.
2. **Syncing an upload trip again.** It must create no duplicate steps. Task 3 adds the test.
3. **An upload deleted from the modal.** It must disappear from the trip on the next sync. Task 3 adds the test.
4. **A trip with only one date filled in.** Sync must fill only the empty date and keep the one the author typed. Task 3 adds the test.
5. **An upload trip with no uploads and no dates.** It must give a clear error, not "set the dates" or an empty sync. Task 3 adds the test.

---

## File structure

| File | Change | Responsibility |
|---|---|---|
| `src/PhotoSource.php` | modify | Interface: `photos( int $trip_id, ?array $range )`, raw rows |
| `src/ImmichSource.php` | modify | New signature; date mode without a range is an error |
| `src/ImmichClient.php` | modify | Stop resolving times and sorting |
| `src/MediaSource.php` | create | Rows from a trip's uploads |
| `src/Trips.php` | modify | `META_SOURCE`, `uses_uploads()`, `source()`, save handler |
| `src/TripSync.php` | modify | Resolve times, dates from photos, counts, place names |
| `src/Geocoder.php` | modify | `available()` |
| `src/Uploads.php` | modify | Images only, REST item filter, `META_VERSION` |
| `src/RestController.php` | modify | `etag()` with the upload's version |
| `travel-steps.php`, `readme.txt` | modify | Requires at least 6.7 |
| `tests/playground/smoke.php` | modify | Test doubles; zone check; require the new file |
| `tests/playground/upload-trips.php` | create | Smoke checks for upload trips |
| `tests/GeocoderTest.php` | modify | `available()` |

---

### Task 1: Sources return raw rows; sync resolves times

**Files:**
- Modify: `src/PhotoSource.php`, `src/ImmichSource.php`, `src/ImmichClient.php:116-158`, `src/TripSync.php:59-86`
- Test: `tests/playground/smoke.php` (lines 66-81 test doubles, ~150-159 album check, new zone check after it)

**Interfaces:**
- Produces: `PhotoSource::photos( int $trip_id, ?array $range )`, where `$range` is `[int $start, int $end]` or null. Each row has, besides today's fields, `local` (?int) and `zoned` (bool). `TripSync` drops rows whose `local` is null, then runs `Days::resolve_times()` and sorts by `t`.

- [ ] **Step 1: Update the test doubles to the new contract, and add the failing checks**

In `tests/playground/smoke.php`, replace `FakeSource::photos()` and `row()`:

```php
class FakeSource implements PhotoSource {
	public array $rows = [];
	public ?array $asked = [];
	public function photos( int $trip_id, ?array $range ) {
		$this->asked = $range;
		return $this->rows;
	}
	public function image( string $photo_id, string $size ) {
		return new WP_Error( 'fake', 'No images here' );
	}
}
function row( $n, $day, $hm, $lat, $lon, $city, $fav ) {
	$t = strtotime( "$day $hm UTC" );
	return [ 'id' => sprintf( '00000000-0000-4000-8000-%012d', $n ), 't' => $t, 'day' => $day, 'local' => $t, 'zoned' => true, 'lat' => $lat, 'lon' => $lon,
		'w' => 400, 'h' => 300, 'city' => $city, 'country' => 'Portugal', 'caption' => '', 'fav' => $fav, 'camera' => 'Fuji X100' ];
}
```

Change the album call (currently `( new ImmichSource( $album_client ) )->photos( $trip_id, 0, 1 );`) to:

```php
( new ImmichSource( $album_client ) )->photos( $trip_id, [ 0, 1 ] );
```

After the line `check( 'Immich is asked for the trip’s album', … );`, add:

```php
$no_dates = ( new ImmichSource( new AlbumImmich( 'http://x', 'k' ) ) )->photos( $trip_id, null );
check( 'Immich without an album needs the trip’s dates', is_wp_error( $no_dates ) && 'travel_steps_no_dates' === $no_dates->get_error_code() );

// Sources hand over wall-clock times; sync works out each photo's zone, and the order.
$zone_trip = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Zones', 'post_status' => 'draft' ] );
update_post_meta( $zone_trip, Trips::META_START, '2025-05-01' );
update_post_meta( $zone_trip, Trips::META_END, '2025-05-01' );
$zone_known          = row( 21, '2025-05-01', '12:00', 38.7139, -9.1394, 'Lisbon', true );
$zone_known['local'] = $zone_known['t'] + HOUR_IN_SECONDS; // 13:00 where it was taken: UTC+1.
$zone_bare           = row( 22, '2025-05-01', '10:00', 38.7139, -9.1394, 'Lisbon', true );
$zone_bare['zoned']  = false; // 10:00 local, no offset: it borrows +1 from the photo beside it.
$zone_source         = new FakeSource();
$zone_source->rows   = [ $zone_known, $zone_bare ];
( new TripSync( new DawarichClient( 'http://x', '' ), $zone_source ) )->sync( $zone_trip );
$zone_times = array_column( Trips::photos( $zone_trip ), 't', 'id' );
check( 'sync resolves a time without an offset from its neighbours, and sorts', [ $zone_bare['id'], $zone_known['id'] ] === array_keys( $zone_times ) && strtotime( '2025-05-01 09:00 UTC' ) === $zone_times[ $zone_bare['id'] ], $zone_times );
```

- [ ] **Step 2: Run smoke and watch it fail**

Run: `make smoke`
Expected: a `FAIL exception: …must be of type int, array given…` line from the `ImmichSource` call (the old signature). The run stops there.

- [ ] **Step 3: Change the interface**

`src/PhotoSource.php`: replace the `photos()` docblock and signature:

```php
	/**
	 * The trip's photos: those taken in $range (unix times, already widened for time
	 * zones), or all of the trip's own photos (an album, its uploads) whenever they
	 * were taken. Sync keeps the ones on the trip's days. $range is null when the trip
	 * has no dates; a source that needs them returns travel_steps_no_dates.
	 *
	 * Each row is:
	 * - id:      string, a lowercase 36-character UUID, as the photo route accepts (the
	 *            thumbnail cache is keyed by it alone);
	 * - local:   ?int, the wall-clock time it was taken, as a unix time read as UTC; null
	 *            if the photo doesn't say (sync leaves it out);
	 * - zoned:   bool, whether t is exact because the photo recorded its UTC offset;
	 * - t:       ?int, unix time it was taken when zoned, else local: sync works it out
	 *            with Days::resolve_times();
	 * - day:     string, Y-m-d where it was taken, empty if local is null;
	 * - lat/lon: float|null;
	 * - w/h:     int, as displayed (after rotation);
	 * - city, country, caption: string, may be empty;
	 * - fav:     bool;
	 * - camera:  string, make and model, empty for screenshots and downloads.
	 *
	 * @param array{0: int, 1: int}|null $range
	 * @return array<int, array>|\WP_Error Photo rows, in any order.
	 */
	public function photos( int $trip_id, ?array $range );
```

- [ ] **Step 4: Update `ImmichSource`**

`src/ImmichSource.php`, replace `photos()`:

```php
	public function photos( int $trip_id, ?array $range ) {
		$album = (string) get_post_meta( $trip_id, Trips::META_ALBUM, true );
		if ( '' === $album && ! $range ) {
			return new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps' ) );
		}
		[ $start, $end ] = $range ?? [ 0, 0 ];
		return $this->client->photos( $start, $end, $album );
	}
```

- [ ] **Step 5: Stop `ImmichClient` resolving times**

`src/ImmichClient.php`: in `photos()`, delete these two lines before `return $photos;`:

```php
		$photos = Days::resolve_times( $photos, wp_timezone() );
		usort( $photos, fn( $a, $b ) => $a['t'] <=> $b['t'] );
```

and change its docblock's return line to:

```php
	 * @return array<int, array>|\WP_Error Photo rows as PhotoSource::photos() describes them.
```

- [ ] **Step 6: Resolve times in `TripSync`**

`src/TripSync.php`, in `run()`, replace:

```php
		$photos = $source->photos( $trip_id, $start - self::ZONE_MARGIN_S, $end + self::ZONE_MARGIN_S );
		if ( is_wp_error( $photos ) ) {
			return $photos;
		}
```

with:

```php
		$photos = $source->photos( $trip_id, [ $start - self::ZONE_MARGIN_S, $end + self::ZONE_MARGIN_S ] );
		if ( is_wp_error( $photos ) ) {
			return $photos;
		}
		$photos = $this->resolve_times( $photos );
```

and add this method below `run()`:

```php
	/**
	 * Every source's rows, with exact times in time order. Photos that don't say when
	 * they were taken can't go on a day, so they're left out.
	 */
	private function resolve_times( array $photos ): array {
		$photos = Days::resolve_times( array_values( array_filter( $photos, fn( $p ) => null !== $p['local'] ) ), wp_timezone() );
		usort( $photos, fn( $a, $b ) => $a['t'] <=> $b['t'] );
		return $photos;
	}
```

- [ ] **Step 7: Run smoke and watch it pass**

Run: `make check`
Expected: no `FAIL` lines; the two new checks print `ok`.

- [ ] **Step 8: Commit**

```bash
git add src/PhotoSource.php src/ImmichSource.php src/ImmichClient.php src/TripSync.php tests/playground/smoke.php
git commit -F - <<'EOF'
Let sync work out photo times for every source (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 2: A trip's source, and `MediaSource`

**Files:**
- Create: `src/MediaSource.php`, `tests/playground/upload-trips.php`
- Modify: `src/Trips.php` (constants near line 21; `source()` ~246; `handle_save_trip()` ~337-380)
- Modify: `tests/playground/smoke.php` (require the new file as the very last statement inside the `try` block, after the privacy-policy checks)

**Interfaces:**
- Consumes: `PhotoSource::photos( int, ?array )` from Task 1; `Uploads::META_PHOTO_ID`, `Uploads::META_EXIF` (array with `taken` "Y-m-d H:i:s" or '', `offset` "+hh:mm" or '', `lat`, `lon` ?float, `camera`), `Uploads::photo_id( int ): string`.
- Produces:
  - `Trips::META_SOURCE = '_ts_source'`, `Trips::SOURCE_UPLOADS = 'uploads'`, `Trips::SOURCE_IMMICH = 'immich'`;
  - `Trips::uses_uploads( int $trip_id ): bool`;
  - `Trips::source()` returning a `MediaSource` for upload trips;
  - `class MediaSource implements PhotoSource`;
  - the smoke helpers `$ut_photo( int $trip_id, array $tags ): int|WP_Error` and `$ut_trip( string $title, int $author, string $start = '', string $end = '' ): int`, defined in `upload-trips.php` and used by later tasks.

- [ ] **Step 1: Create the smoke file with failing checks**

Create `tests/playground/upload-trips.php`:

```php
<?php
/**
 * Trips whose photos are uploaded to WordPress. Required at the end of smoke.php, in
 * its scope: uses check(), $upload, $rest_post, $get, $stopped, $person and $eddie.
 */
use TravelSteps\MediaSource;
use TravelSteps\Settings;
use TravelSteps\Trips;
use TravelSteps\TripSync;
use TravelSteps\Uploads;

wp_set_current_user( 1 );
$ut_author = $person( 'wanderer', 'author' );
// Home in Dublin: photos taken there are left out.
Settings::update_account( $ut_author, [ 'home_lat' => '53.3498', 'home_lon' => '-6.2603', 'home_radius_km' => 1 ] + Settings::ACCOUNT_DEFAULTS );
$ut_trip = function ( string $title, int $author, string $start = '', string $end = '' ) {
	$id = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $author ] );
	update_post_meta( $id, Trips::META_SOURCE, Trips::SOURCE_UPLOADS );
	update_post_meta( $id, Trips::META_START, $start );
	update_post_meta( $id, Trips::META_END, $end );
	return $id;
};
$ut_photo = fn( int $trip_id, array $tags ) => $upload( [ 'post_id' => $trip_id, Uploads::REQUEST_FLAG => '1' ], $trip_id, 600, 400, $tags );
$ut_iphone = [ 'make' => 'Apple', 'model' => 'iPhone 15' ];
$ut_doolin = [ 'lat' => 53.0158, 'lon' => -9.3769 ];
$ut_dingle = [ 'lat' => 52.1409, 'lon' => -10.2689 ];
$ut_paris  = [ 'lat' => 48.8566, 'lon' => 2.3522 ];

// Which source a trip uses.
$ut_rows_trip = $ut_trip( 'Rows', $ut_author );
check( 'an upload trip uses its uploads', Trips::uses_uploads( $ut_rows_trip ) && Trips::source( $ut_rows_trip ) instanceof MediaSource );
$ut_zoned = $ut_photo( $ut_rows_trip, [ 'taken' => '2025:06:01 10:00:00', 'offset' => '+01:00' ] + $ut_doolin + $ut_iphone );
$ut_bare  = $ut_photo( $ut_rows_trip, [ 'taken' => '2025:06:01 11:00:00' ] );
$ut_none  = $ut_photo( $ut_rows_trip, [] );
$ut_rows  = array_column( ( new MediaSource() )->photos( $ut_rows_trip, null ), null, 'id' );
$ut_row   = $ut_rows[ Uploads::photo_id( $ut_zoned ) ] ?? [];
check(
	'an upload’s row: its time, zone, day, place, size and camera, and a favourite',
	3 === count( $ut_rows )
		&& strtotime( '2025-06-01 10:00:00 UTC' ) === $ut_row['local'] && true === $ut_row['zoned'] && strtotime( '2025-06-01 09:00:00 UTC' ) === $ut_row['t']
		&& '2025-06-01' === $ut_row['day'] && 53.0158 === round( $ut_row['lat'], 4 ) && -9.3769 === round( $ut_row['lon'], 4 )
		&& 600 === $ut_row['w'] && 400 === $ut_row['h'] && 'Apple iPhone 15' === $ut_row['camera'] && true === $ut_row['fav']
		&& '' === $ut_row['city'] && '' === $ut_row['caption'],
	$ut_row
);
$ut_row = $ut_rows[ Uploads::photo_id( $ut_bare ) ] ?? [];
check( 'an upload without an offset: wall-clock time, not zoned', false === $ut_row['zoned'] && $ut_row['local'] === $ut_row['t'] && null === $ut_row['lat'], $ut_row );
$ut_row = $ut_rows[ Uploads::photo_id( $ut_none ) ] ?? [];
check( 'an upload without a time: no local time and no day', null === $ut_row['local'] && '' === $ut_row['day'], $ut_row );

// Only the author changes the source.
wp_set_current_user( $ut_author );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'start' => '', 'end' => '', 'status' => 'publish', 'source' => Trips::SOURCE_IMMICH, '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( 'the author can switch a trip to Immich', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && ! Trips::uses_uploads( $ut_rows_trip ) );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'start' => '', 'end' => '', 'status' => 'publish', 'source' => Trips::SOURCE_UPLOADS, '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( '…and back to uploads', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'start' => '', 'end' => '', 'status' => 'publish', '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( 'a form without the source leaves it alone', 'redirect' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
wp_set_current_user( $eddie );
$_POST = $_REQUEST = [ 'trip_id' => $ut_rows_trip, 'title' => 'Rows', 'status' => 'publish', 'source' => Trips::SOURCE_IMMICH, '_wpnonce' => wp_create_nonce( 'travel_steps_save_trip' ) ];
check( 'another editor can’t change the source', 'die' === $stopped( [ new Trips(), 'handle_save_trip' ] ) && Trips::uses_uploads( $ut_rows_trip ) );
$_POST = $_REQUEST = [];
wp_set_current_user( 1 );
```

In `tests/playground/smoke.php`, add as the last line inside the `try` block (after the `privacy policy suggestion names OpenStreetMap` check):

```php
require '/smoke/upload-trips.php';
```

- [ ] **Step 2: Run smoke and watch it fail**

Run: `make smoke`
Expected: `FAIL exception: Undefined constant TravelSteps\Trips::META_SOURCE` (from `upload-trips.php`).

- [ ] **Step 3: Add the source meta and choice to `Trips`**

`src/Trips.php`: after `const META_ALBUM …`, add:

```php
	const META_SOURCE = '_ts_source';      // SOURCE_UPLOADS or SOURCE_IMMICH; empty means Immich.
	const SOURCE_UPLOADS = 'uploads';
	const SOURCE_IMMICH  = 'immich';
```

Replace `source()` and its docblock:

```php
	/**
	 * Whether the trip's photos are the ones uploaded to it, rather than Immich's.
	 */
	public static function uses_uploads( int $trip_id ): bool {
		return self::SOURCE_UPLOADS === get_post_meta( $trip_id, self::META_SOURCE, true );
	}

	/**
	 * Where the trip's photos come from: its uploads, or Immich with the trip author's
	 * key, whoever is asking.
	 */
	public static function source( int $trip_id ): PhotoSource {
		if ( self::uses_uploads( $trip_id ) ) {
			return new MediaSource();
		}
		return new ImmichSource( ImmichClient::for_user( (int) get_post_field( 'post_author', $trip_id ) ) );
	}
```

In `handle_save_trip()`, change the author-only check to include `source` and say "photos":

```php
			if ( ! self::can_sync_trip( $trip_id ) && array_intersect_key( $input, array_flip( [ 'start', 'end', 'album', 'source', 'sync' ] ) ) ) {
				wp_die( esc_html__( 'Only the trip’s author can change its dates or photos, or sync it.', 'travel-steps' ), 403 );
			}
```

and inside `if ( self::can_sync_trip( $trip_id ) ) {`, after the `META_ALBUM` line, add:

```php
			// Left alone when the form doesn't offer the choice.
			if ( isset( $input['source'] ) ) {
				update_post_meta( $trip_id, self::META_SOURCE, self::SOURCE_UPLOADS === $input['source'] ? self::SOURCE_UPLOADS : self::SOURCE_IMMICH );
			}
```

- [ ] **Step 4: Create `MediaSource`**

Create `src/MediaSource.php`:

```php
<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos uploaded to the trip. Choosing what to upload is the choosing, so every
 * upload is a favourite. Uploads are the trip's own photos, like an album, so the
 * range is ignored.
 */
class MediaSource implements PhotoSource {
	public function photos( int $trip_id, ?array $range ) {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $trip_id,
				'post_status'    => [ 'inherit', 'private' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => Uploads::META_PHOTO_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		return array_map( [ self::class, 'row' ], array_map( 'intval', $ids ) );
	}

	/**
	 * An upload as a photo row, from what was read from its original (Exif::parse())
	 * and its size as displayed (the attachment metadata).
	 */
	private static function row( int $attachment_id ): array {
		$exif   = ( get_post_meta( $attachment_id, Uploads::META_EXIF, true ) ?: [] ) + [
			'taken'  => '',
			'offset' => '',
			'lat'    => null,
			'lon'    => null,
			'camera' => '',
		];
		$meta   = wp_get_attachment_metadata( $attachment_id ) ?: [];
		$local  = '' === $exif['taken'] ? null : ( new \DateTimeImmutable( $exif['taken'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$offset = self::offset_s( (string) $exif['offset'] );
		return [
			'id'      => Uploads::photo_id( $attachment_id ),
			'local'   => $local,
			'zoned'   => null !== $local && null !== $offset,
			't'       => null === $local ? null : $local - (int) $offset,
			'day'     => null === $local ? '' : substr( $exif['taken'], 0, 10 ),
			'lat'     => null === $exif['lat'] ? null : (float) $exif['lat'],
			'lon'     => null === $exif['lon'] ? null : (float) $exif['lon'],
			'w'       => (int) ( $meta['width'] ?? 0 ),
			'h'       => (int) ( $meta['height'] ?? 0 ),
			'city'    => '',
			'country' => '',
			'caption' => '',
			'fav'     => true,
			'camera'  => (string) $exif['camera'],
		];
	}

	/**
	 * "+01:00" as seconds east of UTC, or null if there's no offset.
	 */
	private static function offset_s( string $offset ): ?int {
		if ( ! preg_match( '/^([+-])(\d{2}):(\d{2})$/', $offset, $m ) ) {
			return null;
		}
		return ( '-' === $m[1] ? -1 : 1 ) * ( (int) $m[2] * HOUR_IN_SECONDS + (int) $m[3] * MINUTE_IN_SECONDS );
	}

	/**
	 * The photo proxy serves uploads itself (Uploads::image()), before asking a source.
	 */
	public function image( string $photo_id, string $size ) {
		return new \WP_Error( 'travel_steps_not_here', 'Uploads are served by the photo proxy.' );
	}
}
```

Composer autoloads `TravelSteps\` from `src/` by PSR-4, so the new file needs no registration.

- [ ] **Step 5: Run smoke and watch it pass**

Run: `make check`
Expected: no `FAIL` lines; the eight new checks print `ok`.

- [ ] **Step 6: Commit**

```bash
git add src/MediaSource.php src/Trips.php tests/playground/upload-trips.php tests/playground/smoke.php
git commit -F - <<'EOF'
Let a trip take its photos from its uploads (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 3: Sync an upload trip: dates from its photos, and what it left out

**Files:**
- Modify: `src/TripSync.php` (`run()`, `locate_photos()`, new `dates_from()`)
- Test: `tests/playground/upload-trips.php` (append)

**Interfaces:**
- Consumes: `Trips::uses_uploads()`, `MediaSource`, `$ut_trip`, `$ut_photo` (Task 2); `TripSync::resolve_times()` (Task 1).
- Produces: for upload trips, `TripSync::sync()` also returns `left_out => [ 'outside' => int, 'untimed' => int, 'home' => int ]` and `unlocated => int`. New error code `travel_steps_no_photos`. `locate_photos()` returns `[ array $photos, int $at_home ]`.

- [ ] **Step 1: Append the failing checks**

Append to `tests/playground/upload-trips.php`:

```php
// Three days of uploads become three steps; what sync can't use is counted.
$ut_tour = $ut_trip( 'Tour', $ut_author, '2025-06-01', '2025-06-03' );
$ut_ids  = [];
foreach (
	[
		'doolin_am' => [ 'taken' => '2025:06:01 10:00:00', 'offset' => '+01:00' ] + $ut_doolin + $ut_iphone,
		'doolin_pm' => [ 'taken' => '2025:06:01 15:00:00', 'offset' => '+01:00' ] + $ut_doolin + $ut_iphone,
		'dingle'    => [ 'taken' => '2025:06:02 11:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone,
		'paris'     => [ 'taken' => '2025:06:03 12:00:00', 'offset' => '+02:00' ] + $ut_paris + $ut_iphone,
		'no_gps'    => [ 'taken' => '2025:06:03 09:00:00', 'offset' => '+02:00' ], // No camera either: still kept.
		'home'      => [ 'taken' => '2025:06:02 18:00:00', 'offset' => '+01:00', 'lat' => 53.3498, 'lon' => -6.2603 ] + $ut_iphone,
		'later'     => [ 'taken' => '2025:06:10 12:00:00', 'offset' => '+01:00' ] + $ut_paris + $ut_iphone,
		'untimed'   => $ut_doolin + $ut_iphone,
	] as $ut_name => $ut_tags
) {
	$ut_ids[ $ut_name ] = Uploads::photo_id( $ut_photo( $ut_tour, $ut_tags ) );
}
// Left over from when it used Immich: replaced by the uploads.
update_post_meta( $ut_tour, Trips::META_PHOTOS, wp_slash( wp_json_encode( [ row( 99, '2025-06-01', '10:00', 53.0, -9.3, 'Doolin', true ) ] ) ) );
$ut_result = ( new TripSync() )->sync( $ut_tour );
check( 'an upload trip over three days syncs into three steps', ! is_wp_error( $ut_result ) && 3 === $ut_result['new_steps'], is_wp_error( $ut_result ) ? $ut_result->get_error_message() : $ut_result );
$ut_kept = array_column( Trips::photos( $ut_tour ), 'id' );
check( '…keeping every upload on its days, without a camera too, and nothing from Immich', [] === array_diff( [ $ut_ids['doolin_am'], $ut_ids['doolin_pm'], $ut_ids['dingle'], $ut_ids['paris'], $ut_ids['no_gps'] ], $ut_kept ) && 5 === count( $ut_kept ), $ut_kept );
check( '…and counting those left out: one outside the dates, one untimed, one at home', [ 'outside' => 1, 'untimed' => 1, 'home' => 1 ] === ( $ut_result['left_out'] ?? null ) && 1 === ( $ut_result['unlocated'] ?? null ), $ut_result );
check( 'syncing it again adds no steps', 0 === ( ( new TripSync() )->sync( $ut_tour )['new_steps'] ?? -1 ) && 3 === count( Trips::steps( $ut_tour, true ) ) );
wp_delete_attachment( (int) Uploads::attachment( $ut_tour, $ut_ids['doolin_pm'] ), true );
( new TripSync() )->sync( $ut_tour );
check( 'a deleted upload leaves the trip at the next sync', ! in_array( $ut_ids['doolin_pm'], array_column( Trips::photos( $ut_tour ), 'id' ), true ) );

// A trip without dates takes them from its uploads.
$ut_undated = $ut_trip( 'Undated', $ut_author );
$ut_photo( $ut_undated, [ 'taken' => '2025:07:04 09:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
$ut_photo( $ut_undated, [ 'taken' => '2025:07:06 20:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
$ut_result = ( new TripSync() )->sync( $ut_undated );
check( 'a trip without dates gets them from its uploads', ! is_wp_error( $ut_result ) && [ '2025-07-04', '2025-07-06' ] === [ get_post_meta( $ut_undated, Trips::META_START, true ), get_post_meta( $ut_undated, Trips::META_END, true ) ] && 2 === $ut_result['new_steps'], is_wp_error( $ut_result ) ? $ut_result->get_error_message() : $ut_result );
$ut_half = $ut_trip( 'Half', $ut_author, '2025-07-01' );
$ut_photo( $ut_half, [ 'taken' => '2025:07:04 09:00:00', 'offset' => '+01:00' ] + $ut_dingle + $ut_iphone );
( new TripSync() )->sync( $ut_half );
check( 'with only a first day, sync fills in the last and keeps the first', [ '2025-07-01', '2025-07-04' ] === [ get_post_meta( $ut_half, Trips::META_START, true ), get_post_meta( $ut_half, Trips::META_END, true ) ] );
$ut_untimed_only = $ut_trip( 'Untimed', $ut_author );
$ut_photo( $ut_untimed_only, $ut_doolin );
$ut_result = ( new TripSync() )->sync( $ut_untimed_only );
check( 'a trip whose uploads have no times asks for dates', is_wp_error( $ut_result ) && 'travel_steps_no_dates' === $ut_result->get_error_code(), $ut_result );
$ut_result = ( new TripSync() )->sync( $ut_trip( 'Empty', $ut_author ) );
check( 'an upload trip with no uploads and no dates says to add photos', is_wp_error( $ut_result ) && 'travel_steps_no_photos' === $ut_result->get_error_code(), $ut_result );
$ut_immich = wp_insert_post( [ 'post_type' => Trips::TRIP, 'post_title' => 'Immich, undated', 'post_status' => 'draft', 'post_author' => $ut_author ] );
$ut_result = ( new TripSync() )->sync( $ut_immich );
check( 'an Immich trip without dates still fails as before', is_wp_error( $ut_result ) && 'travel_steps_no_dates' === $ut_result->get_error_code() );
```

- [ ] **Step 2: Run smoke and watch it fail**

Run: `make smoke`
Expected, among others:
- `FAIL …and counting those left out: one outside the dates, one untimed, one at home`: the trip has dates, so it syncs, but there's no `left_out` yet;
- `FAIL a trip without dates gets them from its uploads -- Set the trip start and end dates first.`

- [ ] **Step 3: Rework `TripSync::run()`**

`src/TripSync.php`: replace `run()` from its start through the line `$photos = $this->locate_photos( $photos, $points, $home );` with:

```php
	private function run( int $trip_id ) {
		$uploads = Trips::uses_uploads( $trip_id );
		$range   = Trips::range( $trip_id );
		if ( ! $range && ! $uploads ) {
			return new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps' ) );
		}

		// Trip dates are local to wherever you were, so ask for more and keep photos by local day.
		$source = $this->source ?? Trips::source( $trip_id );
		$photos = $source->photos( $trip_id, $range ? [ $range[0] - self::ZONE_MARGIN_S, $range[1] + self::ZONE_MARGIN_S ] : null );
		if ( is_wp_error( $photos ) ) {
			return $photos;
		}
		$all     = count( $photos );
		$photos  = $this->resolve_times( $photos );
		$untimed = $all - count( $photos );
		if ( ! $range ) {
			$range = $this->dates_from( $trip_id, $photos, $untimed );
			if ( is_wp_error( $range ) ) {
				return $range;
			}
		}
		[ $start, $end ] = $range;
		// The trip's author's: their location history, and their home to leave out.
		$author = (int) get_post_field( 'post_author', $trip_id );
		$home   = Settings::home( $author );

		$points = ( $this->dawarich ?? DawarichClient::for_user( $author ) )->points( $start, $end );
		if ( is_wp_error( $points ) ) {
			return $points;
		}
		// Glitches first: the fixes at home are the witnesses that give away a bad one
		// the morning you leave.
		$points = Geo::exclude_near( Geo::drop_glitches( $points ), $home );

		$first   = (string) get_post_meta( $trip_id, Trips::META_START, true );
		$last    = (string) get_post_meta( $trip_id, Trips::META_END, true );
		$taken   = count( $photos );
		$photos  = array_values( array_filter( $photos, fn( $p ) => $p['day'] >= $first && $p['day'] <= $last ) );
		$outside = $taken - count( $photos );
		[ $photos, $at_home ] = $this->locate_photos( $photos, $points, $home );
```

This asks Immich for photos before Dawarich for points. Only the order of the two requests changes for Immich trips (which error shows if both fail).

Then replace the final `return [ … ];` of `run()` with:

```php
		$result = [
			'new_steps'    => $new_steps,
			'skipped_days' => $skipped_days,
			'points'       => count( $points ),
			'photos'       => count( $photos ),
		];
		// Uploads are all meant to be shown, so say which weren't, and why.
		if ( $uploads ) {
			$result['left_out']  = [
				'outside' => $outside,
				'untimed' => $untimed,
				'home'    => $at_home,
			];
			$result['unlocated'] = count( array_filter( $photos, fn( $p ) => null === $p['lat'] ) );
		}
		return $result;
	}
```

Update the docblocks of `sync()` and `run()` to:

```php
	/**
	 * @return array{new_steps: int, skipped_days: int, points: int, photos: int, left_out?: array{outside: int, untimed: int, home: int}, unlocated?: int}|\WP_Error
	 *         left_out and unlocated only for a trip of uploads.
	 */
```

- [ ] **Step 4: Add `dates_from()` and count home in `locate_photos()`**

Add below `resolve_times()`:

```php
	/**
	 * A trip of uploads without dates takes them from its photos: the first and last
	 * local day. A date already set is kept.
	 *
	 * @return array{0: int, 1: int}|\WP_Error
	 */
	private function dates_from( int $trip_id, array $photos, int $untimed ) {
		if ( ! $photos ) {
			return $untimed
				? new \WP_Error( 'travel_steps_no_dates', __( 'None of the trip’s photos say when they were taken, so set its dates.', 'travel-steps' ) )
				: new \WP_Error( 'travel_steps_no_photos', __( 'Add photos to the trip, or set its dates.', 'travel-steps' ) );
		}
		$days = array_column( $photos, 'day' );
		foreach ( [ Trips::META_START => min( $days ), Trips::META_END => max( $days ) ] as $key => $day ) {
			if ( '' === (string) get_post_meta( $trip_id, $key, true ) ) {
				update_post_meta( $trip_id, $key, $day );
			}
		}
		return Trips::range( $trip_id ) ?? new \WP_Error( 'travel_steps_no_dates', __( 'Set the trip start and end dates first.', 'travel-steps' ) );
	}
```

Change `locate_photos()` to return the photos and how many it left out at home. Its docblock gains `@return array{0: array, 1: int} The photos kept, and how many were taken at home.`; its body becomes:

```php
	private function locate_photos( array $photos, array $points, ?array $home ): array {
		$out     = [];
		$at_home = 0;
		foreach ( $photos as $photo ) {
			if ( '' === $photo['camera'] && ! $photo['fav'] ) {
				continue;
			}
			if ( null === $photo['lat'] ) {
				$index = Geo::nearest_in_time( $points, $photo['t'], self::PHOTO_MAX_GAP_S );
				if ( null !== $index ) {
					$photo['lat'] = $points[ $index ][0];
					$photo['lon'] = $points[ $index ][1];
				}
			}
			if ( $home && null !== $photo['lat'] && Geo::distance( $photo['lat'], $photo['lon'], $home['lat'], $home['lon'] ) <= $home['radius_m'] ) {
				++$at_home;
				continue;
			}
			$out[] = $photo;
		}
		return [ $out, $at_home ];
	}
```

- [ ] **Step 5: Run smoke and watch it pass**

Run: `make check`
Expected: no `FAIL` lines. Every Immich sync check (`first sync creates a step per day with favourites`, `photos asked for 14h either side of the trip`, …) still prints `ok`.

- [ ] **Step 6: Commit**

```bash
git add src/TripSync.php tests/playground/upload-trips.php
git commit -F - <<'EOF'
Sync a trip of uploads, taking its dates from them and counting what it left out (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 4: Name the places of an upload trip

**Files:**
- Modify: `src/Geocoder.php` (new `available()`), `src/TripSync.php` (constructor, `run()`)
- Test: `tests/GeocoderTest.php`, `tests/playground/upload-trips.php` (append)

**Interfaces:**
- Consumes: Task 3's `run()`.
- Produces: `Geocoder::available(): bool`; `TripSync::__construct( ?DawarichClient $dawarich = null, ?PhotoSource $source = null, ?Geocoder $geocoder = null )`; result key `no_places => bool` for upload trips.

- [ ] **Step 1: Write the failing unit check**

Append to `tests/GeocoderTest.php`, before its final summary or exit lines:

```php
check( 'the bundled data is available', ( new Geocoder() )->available() );
check( 'missing data is reported, not mistaken for open sea', ! ( new Geocoder( __DIR__ . '/no-such-dir' ) )->available() );
```

- [ ] **Step 2: Run it and watch it fail**

Run: `make test`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined method TravelSteps\Geocoder::available()`.

- [ ] **Step 3: Add `available()`**

`src/Geocoder.php`, after the constructor:

```php
	/**
	 * Whether the data files are there. Without them every point is unnamed, like open
	 * sea, so a broken deploy would otherwise go unnoticed.
	 */
	public function available(): bool {
		foreach ( [ 'index.dat', 'places.tsv', 'countries.tsv' ] as $file ) {
			if ( ! is_readable( $this->dir . '/' . $file ) ) {
				return false;
			}
		}
		return true;
	}
```

Run: `make test`. Expected: both new checks `ok`.

- [ ] **Step 4: Append the failing smoke checks**

Append to `tests/playground/upload-trips.php`:

```php
// Place names for uploads come from the offline geocoder.
$ut_places = [];
foreach ( Trips::steps( $ut_tour, true ) as $ut_step ) {
	$ut_places[ Trips::day( $ut_step ) ] = wp_get_object_terms( $ut_step->ID, Trips::PLACE, [ 'fields' => 'names' ] );
}
ksort( $ut_places );
check( 'an upload trip’s steps are named by the geocoder', [ '2025-06-01' => [ 'Lisdoonvarna' ], '2025-06-02' => [ 'Dingle' ], '2025-06-03' => [ 'Paris' ] ] === $ut_places, $ut_places );
check( '…and so are its photos', [ 'Lisdoonvarna', 'Ireland' ] === [ Trips::photos( $ut_tour )[0]['city'] ?? '', Trips::photos( $ut_tour )[0]['country'] ?? '' ], Trips::photos( $ut_tour )[0] ?? [] );
check( '…with no warning about place names', false === ( ( new TripSync() )->sync( $ut_tour )['no_places'] ?? null ) );
$ut_result = ( new TripSync( null, null, new TravelSteps\Geocoder( '/nowhere' ) ) )->sync( $ut_tour );
check( 'missing geocoder data is reported', true === ( $ut_result['no_places'] ?? null ), $ut_result );
```

- [ ] **Step 5: Run smoke and watch it fail**

Run: `make smoke`
Expected: `FAIL an upload trip’s steps are named by the geocoder`, showing an empty array for each day.

The steps are tagged when Task 3's first sync of `$ut_tour` creates them, so the check passes only once that sync names places.

- [ ] **Step 6: Geocode in `TripSync`**

`src/TripSync.php`: add a property and constructor parameter:

```php
	private ?Geocoder $geocoder;

	/**
	 * By default, each trip uses its author's own Dawarich and photo source.
	 *
	 * @param DawarichClient|null $dawarich Location history for every trip.
	 * @param PhotoSource|null    $source   Photos for every trip.
	 * @param Geocoder|null       $geocoder Place names for uploads.
	 */
	public function __construct( ?DawarichClient $dawarich = null, ?PhotoSource $source = null, ?Geocoder $geocoder = null ) {
		$this->dawarich = $dawarich;
		$this->source   = $source;
		$this->geocoder = $geocoder;
	}
```

In `run()`, directly after `[ $photos, $at_home ] = $this->locate_photos( $photos, $points, $home );`, add:

```php
		// Uploads carry no place names, and some have a position only from Dawarich:
		// name them all here, with one geocoder for the sync. Immich names its own.
		$geocoder = $uploads ? ( $this->geocoder ?? new Geocoder() ) : null;
		if ( $geocoder ) {
			foreach ( $photos as &$photo ) {
				if ( null !== $photo['lat'] && '' === $photo['city'] ) {
					$photo = array_merge( $photo, $geocoder->nearest( $photo['lat'], $photo['lon'] ) );
				}
			}
			unset( $photo );
		}
```

and in the `if ( $uploads ) {` block that builds `$result`, add:

```php
			$result['no_places'] = ! $geocoder->available();
```

Add `no_places?: bool` to the `@return` docblocks of `sync()` and `run()`.

- [ ] **Step 7: Run everything and watch it pass**

Run: `make check`
Expected: no `FAIL` lines.

- [ ] **Step 8: Commit**

```bash
git add src/Geocoder.php src/TripSync.php tests/GeocoderTest.php tests/playground/upload-trips.php
git commit -F - <<'EOF'
Name the places of a trip of uploads with the offline geocoder (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 5: Pin, hide and cover on an upload trip

This needs no production code if Tasks 2–4 are right. It pins the spec's acceptance criterion: "all photos shown, and pin/hide/cover working".

**Files:**
- Test: `tests/playground/upload-trips.php` (append)

**Interfaces:**
- Consumes: `$ut_tour`, `$ut_ids` (Task 3); `$rest_post` (smoke.php); `Trips::photos_by_step()`, `Trips::cover()`.

- [ ] **Step 1: Append the checks**

```php
// Every upload is shown; pin, hide and cover work on them as on Immich photos.
$ut_groups = Trips::photos_by_step( $ut_tour, Trips::steps( $ut_tour, true ) );
$ut_shown  = array_merge( ...array_map( fn( $g ) => array_column( $g['shown'], 'id' ), array_values( $ut_groups ) ) );
check( 'every upload on a step is shown, none behind “Show more”', 4 === count( $ut_shown ) && [] === array_merge( ...array_map( fn( $g ) => $g['rest'], array_values( $ut_groups ) ) ), $ut_groups );
$rest_post( "/travel-steps/v1/trips/$ut_tour/photo-state", [ 'asset' => $ut_ids['dingle'], 'state' => 'hidden' ] );
$rest_post( "/travel-steps/v1/trips/$ut_tour/photo-state", [ 'asset' => $ut_ids['paris'], 'state' => 'pinned' ] );
$ut_cover = $rest_post( "/travel-steps/v1/trips/$ut_tour/cover", [ 'asset' => $ut_ids['paris'] ] );
$ut_groups = Trips::photos_by_step( $ut_tour, Trips::steps( $ut_tour, true ) );
$ut_hidden = array_merge( ...array_map( fn( $g ) => array_column( $g['hidden'], 'id' ), array_values( $ut_groups ) ) );
check( 'an upload can be hidden, pinned and made the cover', [ $ut_ids['dingle'] ] === $ut_hidden && in_array( $ut_ids['paris'], Trips::pinned( $ut_tour ), true ) && 200 === $ut_cover->get_status() && $ut_ids['paris'] === Trips::cover( $ut_tour ), [ $ut_hidden, $ut_cover->get_data() ] );
```

- [ ] **Step 2: Run smoke**

Run: `make smoke`
Expected: both checks `ok`. If one fails, it's a real bug: find it with superpowers:systematic-debugging before changing anything.

- [ ] **Step 3: Commit**

```bash
git add tests/playground/upload-trips.php
git commit -F - <<'EOF'
Check that pin, hide and cover work on uploaded photos (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 6: Only images, on WordPress 6.7

**Files:**
- Modify: `src/Uploads.php:77-91` (`prefilter()`), `travel-steps.php:6`, `readme.txt:4`
- Test: `tests/playground/upload-trips.php` (append)

**Interfaces:**
- Consumes: `Uploads::upload_trip()`.
- Produces: a flagged trip upload whose content isn't an image fails with "Only photos can be added to a trip."

`wp_get_image_mime()` reads the file's content, so a PDF named `.jpg` is caught too. On WordPress 7.1.3 (checked in `wordpress:php8.3-apache` while planning) it recognises HEIC and HEIF by their `ftyp` brand.

- [ ] **Step 1: Append the failing checks**

```php
// Trip photos must be images: Imagick would otherwise open a PDF or a video to read it.
$ut_sideload = function ( string $name, string $bytes ) use ( $ut_tour ) {
	$_REQUEST = [ 'post_id' => $ut_tour, Uploads::REQUEST_FLAG => '1' ];
	$tmp      = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	$id       = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $ut_tour );
	$_REQUEST = [];
	return $id;
};
$ut_pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
check( 'a PDF can’t be a trip photo', is_wp_error( $ut_sideload( 'notes.pdf', $ut_pdf ) ) );
check( '…even named like a photo', is_wp_error( $ut_sideload( 'IMG_0001.jpg', $ut_pdf ) ) );
```

- [ ] **Step 2: Run smoke and watch it fail**

Run: `make smoke`
Expected: both checks `FAIL`, or a `FAIL exception` from Imagick reading the PDF. The administrator running smoke has `unfiltered_upload`, so core lets both files through.

- [ ] **Step 3: Refuse non-images in the prefilter**

`src/Uploads.php`, in `prefilter()`, after the `exif_read_data` branch and before `} elseif ( $trip_id ) {`, add:

```php
		} elseif ( $trip_id && ! wp_get_image_mime( (string) $file['tmp_name'] ) ) {
			// Read from its content, not its name: Imagick opens PDFs and videos too.
			$file['error'] = __( 'Only photos can be added to a trip.', 'travel-steps' );
```

- [ ] **Step 4: Require WordPress 6.7**

`travel-steps.php`: ` * Requires at least: 6.5` → ` * Requires at least: 6.7`.
`readme.txt`: `Requires at least: 6.5` → `Requires at least: 6.7`.

- [ ] **Step 5: Run everything**

Run: `make check`
Expected: no `FAIL` lines. The earlier upload checks (JPEG fixtures) still pass.

- [ ] **Step 6: Commit**

```bash
git add src/Uploads.php travel-steps.php readme.txt tests/playground/upload-trips.php
git commit -F - <<'EOF'
Take only images as trip photos, and require WordPress 6.7 for HEIC (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 7: Keep trip photo files out of core REST

**Files:**
- Modify: `src/Uploads.php` (`register()`, new `rest_item()`)
- Test: `tests/playground/upload-trips.php` (append)

**Interfaces:**
- Produces: `Uploads::rest_item( \WP_REST_Response $response, \WP_Post $attachment ): \WP_REST_Response`, hooked to `rest_prepare_attachment`.

- [ ] **Step 1: Append the failing check**

```php
// Core REST gives editors an attachment's file URL and names (source_url, media_details,
// guid, the description's <img>); on nginx those lead to the untouched original.
$ut_rest_id = $ut_photo( $ut_tour, $ut_doolin + $ut_iphone );
$ut_file    = pathinfo( (string) get_post_meta( $ut_rest_id, '_wp_attached_file', true ), PATHINFO_FILENAME );
$ut_file    = preg_replace( '/-scaled$/', '', $ut_file );
$ut_json    = wp_json_encode( $get( "/wp/v2/media/$ut_rest_id", [ 'context' => 'edit' ] )->get_data(), JSON_UNESCAPED_SLASHES );
check( 'core REST names no file for a trip photo, even for editors', false === strpos( $ut_json, $ut_file ) && false === strpos( $ut_json, '/uploads/travel-steps/' ), substr( $ut_json, 0, 800 ) );
```

- [ ] **Step 2: Run smoke and watch it fail**

Run: `make smoke`
Expected: `FAIL core REST names no file for a trip photo, even for editors`, with the JSON showing `source_url`. That confirms the leak the spec asked us to check.

- [ ] **Step 3: Filter the REST item**

`src/Uploads.php`, in `register()` after the `xmlrpc_prepare_media_item` line:

```php
		add_filter( 'rest_prepare_attachment', [ self::class, 'rest_item' ], 10, 2 );
```

and after `xmlrpc_item()`:

```php
	/**
	 * Core REST gives anyone who can edit a trip photo its file URL and names. Leave
	 * those out, as for the modal: on nginx they'd lead to the untouched original.
	 */
	public static function rest_item( \WP_REST_Response $response, \WP_Post $attachment ): \WP_REST_Response {
		if ( '' === self::photo_id( $attachment->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		unset( $data['source_url'], $data['media_details'], $data['missing_image_sizes'], $data['guid'] );
		$data['link'] = '';
		if ( isset( $data['description']['rendered'] ) ) {
			$data['description']['rendered'] = '';
		}
		$response->set_data( $data );
		return $response;
	}
```

- [ ] **Step 4: Run smoke and watch it pass**

Run: `make check`
Expected: no `FAIL` lines. If the check still fails, the printed JSON shows which other field names the file. Remove that field too, and say so in the PR.

- [ ] **Step 5: Commit**

```bash
git add src/Uploads.php tests/playground/upload-trips.php
git commit -F - <<'EOF'
Keep trip photos' file names out of core REST for editors (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 8: A new ETag when an upload's copies change

**Files:**
- Modify: `src/Uploads.php` (constant, `new_copies()`), `src/RestController.php:204-235` (`serve_photo()`, new `etag()`)
- Modify: `docs/superpowers/specs/2026-10-12-uploaded-trip-photos-design.md` (the ETag bullet: counter, not time)
- Test: `tests/playground/upload-trips.php` (append)

**Interfaces:**
- Produces: `Uploads::META_VERSION = '_ts_version'`; `RestController::etag( string $asset, string $size, int $upload = 0 ): string`. When `$upload` is 0 or has no version, it's the same ETag as today: `'"' . md5( "$asset/$size" ) . '"'`.

- [ ] **Step 1: Append the failing check**

```php
// An edited upload gets a new ETag, so browsers that revalidate fetch the new image.
$ut_asset   = Uploads::photo_id( $ut_rest_id );
$ut_before  = TravelSteps\RestController::etag( $ut_asset, 'thumbnail', $ut_rest_id );
wp_update_attachment_metadata( $ut_rest_id, wp_get_attachment_metadata( $ut_rest_id ) );
check( 'an upload’s ETag changes with its copies', $ut_before !== TravelSteps\RestController::etag( $ut_asset, 'thumbnail', $ut_rest_id ) );
check( 'an Immich photo’s ETag is as before', '"' . md5( '00000000-0000-4000-8000-000000000001/thumbnail' ) . '"' === TravelSteps\RestController::etag( '00000000-0000-4000-8000-000000000001', 'thumbnail' ) );
```

- [ ] **Step 2: Run smoke and watch it fail**

Run: `make smoke`
Expected: `FAIL exception: Call to undefined method TravelSteps\RestController::etag()`.

- [ ] **Step 3: Version the copies**

`src/Uploads.php`: after `const META_CHECKED …` add:

```php
	const META_VERSION  = '_ts_version'; // Counts new sets of copies, for the proxy's ETag.
```

In `new_copies()`, inside the `if`, add:

```php
			update_post_meta( (int) $attachment_id, self::META_VERSION, 1 + (int) get_post_meta( (int) $attachment_id, self::META_VERSION, true ) );
```

- [ ] **Step 4: Use it in the ETag**

`src/RestController.php`: add:

```php
	/**
	 * A photo's ETag at a size. An Immich thumbnail doesn't change; an upload's copies
	 * do when it's edited, so its version is part of the tag.
	 */
	public static function etag( string $asset, string $size, int $upload = 0 ): string {
		$version = $upload ? (string) get_post_meta( $upload, Uploads::META_VERSION, true ) : '';
		return '"' . md5( $asset . '/' . $size . ( '' !== $version ? '/' . $version : '' ) ) . '"';
	}
```

In `serve_photo()`, replace:

```php
		// An asset's thumbnail doesn't change, so its tag needn't depend on the cache file.
		$etag = '"' . md5( $asset . '/' . $size ) . '"';
```

with:

```php
		$upload = Uploads::attachment( $trip_id, $asset );
		$etag   = self::etag( $asset, $size, $upload );
```

and further down replace `$upload = Uploads::attachment( $trip_id, $asset );` followed by `if ( $upload ) {` with just `if ( $upload ) {`.

- [ ] **Step 5: Correct the spec**

In the spec's ETag bullet, replace "which `Uploads::new_copies()` sets to the current time" with "a counter that `Uploads::new_copies()` increments".

- [ ] **Step 6: Run everything**

Run: `make check`
Expected: no `FAIL` lines.

- [ ] **Step 7: Commit**

```bash
git add src/Uploads.php src/RestController.php docs/superpowers/specs/2026-10-12-uploaded-trip-photos-design.md tests/playground/upload-trips.php
git commit -F - <<'EOF'
Give an upload a new ETag when its copies change (#6)

Claude-Session: https://claude.ai/code/session_018fdi1GVMiwa11XSR8dhQzE
EOF
```

---

### Task 9: Open PR 1

- [ ] **Step 1: Full run**

Run: `make check`
Expected: no `FAIL` lines; unit and smoke counts both higher than before (312 before this branch).

- [ ] **Step 2: Push and open the PR**

The PR body must not contain "close", "closes", "fix" or "fixes" next to `#6`: this PR doesn't finish the issue, and GitHub reads those words as closing keywords even inside a negative sentence. Write "Part of #6".

```bash
git push -u origin uploaded-photos-6
gh pr create --base master --title "Sync trips from their uploaded photos (#6, part 1)" --body-file <path to a drafted body>
```

The body:
- opens with "> *This was generated by AI while implementing #6.*";
- says "Part of #6";
- lists what changed by task;
- lists the two deviations from the spec;
- notes that the Immich request order changed (photos before Dawarich);
- says what PR 2 brings;
- ends with the session URL on its own line.
