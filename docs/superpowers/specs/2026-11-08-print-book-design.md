# Print book: a trip's chosen photos, laid out for paper

Status: draft for review. Replaces the print layout in the uncommitted print-version work on `master`.

## Intent

After a trip, its author wants a short printed book, or a PDF of one, as a memory of the trip. It holds the trip's best photos, chosen by hand per section. It isn't a copy of the trip page.

The first print version printed everything visitors see. On a real trip (Cómpeta and Nerja: 12 steps, 806 photos, 278 shown) that made 81 pages and a 243 MB PDF. Automatic layout couldn't fix that, because the problem was which photos were printing. Choosing few photos also means each can print large.

## Goals

- The author chooses photos on the trip page, while logged in, after the trip.
- A book of about 2–3 large photos per section, with text on its own page and plenty of white space.
- Sections with no chosen photos are left out.
- What visitors see on the trip page doesn't change.

## Non-goals

- Setting a size per photo. The author decides how big photos print by how many they choose.
- Changing Immich's settings or converting photos to JPEG. PDF size from WebP previews is a separate decision.
- A separate screen for choosing photos, ordering sections, or editing book text.
- Printing for logged-out visitors, including those with a share link.

## Anti-goals

The work has failed, even if it functions, if:

- choosing a print photo changes what visitors see or which photos are pinned or hidden;
- a hidden photo appears in print, other than a chosen cover, which prints as it shows on the trip page;
- an anonymous visitor can reach the print version;
- a full-page portrait prints soft because it was scaled to fill the page.

## Decided by the author

- **Layout:** for each section, a text page, then one lead photo alone on a page, then the other photos together on a page.
- **Text page:** text centred on the page with lots of white space.
- **Choosing:** on the trip page, logged in, on a laptop, after the trip.
- **Button label:** "Print" on each photo, not "Book".
- **Empty sections:** left out, along with their text.
- **Print button:** only logged-in visitors get it.
- **Portraits:** printed smaller, not raised through a bigger Immich preview. About 137×183mm for a 3:4 portrait, centred, which is about 200 dpi from 1080px-wide previews. Originals here are 2048px on the long side.

## Definitions

- **Section:** one place heading's part of a step, as `View::render_story()` and `Days::section_index()` already define it. Photos taken before the first heading, or in a step with no headings, form section 0. A section's photos come from a photo's time, not from where it appears on the page.
- **Chosen photo:** a photo in the trip's print selection.
- **Lead:** the photo that gets its own page first in a section.

## Choosing photos

Each non-hidden photo on the trip page, including those behind "Show more", gets a **Print** toggle beside Pin, Hide and Cover. It appears for those who get the other photo tools (`Trips::can_edit_trip()`), and shows on touch screens under the existing "Edit photos" toggle.

- The first photo chosen in a section is its lead. A **Make lead** button appears on the section's other chosen photos.
- Removing the lead makes the next chosen photo the lead.
- The section shows how many of its photos are chosen. A sixth or later photo is allowed, with a warning that the book works best with five.
- Changes save straight away and update the page without a reload, as Pin and Hide do.

Storage is one ordered list of photo ids in trip meta, `_travel_steps_print`, written through a new REST route that takes the same permission as `photo-state`. The route refuses photos that aren't in the trip. A section's photos print in the order they're in the list, so its lead is whichever comes first.

The selection is independent of pinned and hidden. A photo that's hidden after being chosen stays in the list but doesn't print, and comes back if it's unhidden. Ids no longer in the trip are ignored.

## The print page

`/travel-steps/trip/{slug}/print` stays the address. The order is: a cover, then for each step with chosen photos, its sections in order, each with its pages. A step with nothing chosen is left out, title included.

Access:

- Anonymous visitors get the same not-found page as a trip they can't see, whether or not they have a share code. This check comes before the password form.
- Logged-in users then pass `_trip-gate.php`, as now.
- Users see only steps `Trips::timeline_steps()` gives them, so a non-editor doesn't see photos from unpublished steps.

The trip page's Print button shows for logged-in users who can see the trip. Non-editors only see it if the trip has chosen photos they can see. Editors always see it, and if nothing's chosen the print page gives them a short note saying so, with a link back to the trip.

### Cover

As now: header photo, dates, title, stats and intro.

### Text page

The step's day, date and any badge; the step's title on its first section in the book; the section's place heading; then its text, from the blocks `render_story()` already separates by section, with any Trip photos block dropped.

Text that fits on a page is centred on it horizontally and vertically. Longer text starts at the top and runs on to further pages.

Every text page starts on a left page, an even page number, with a blank page before it if it would land on the right. Browsers ignore `break-before: left`, so the print script counts pages and adds the blanks, measuring text and the cover at print sizes against Letter's 255mm height.

The first section of a step in the book always gets this page, even with only the day, date and title on it, so a step's photos never print before its title. A later section with no text and no heading skips this page.

### Photo pages

Every photo uses the `preview` size. All sizes must fit both A4 and US Letter (printable area at least 186×255mm).

| Chosen photos | Pages after the text page |
|---|---|
| 1 | Lead alone |
| 2 | Lead alone, then the other alone |
| 3 | Lead alone, then the other two together |
| 4 | Lead alone, two together, then the last alone |
| 5 (recommended) | Lead alone, then two pages of two: three pages, so a section with a one-page text page takes four and needs no blank page |
| 6 or more | Lead alone, then the rest two to a page |

- A landscape alone prints across the page, 186mm wide.
- A portrait alone prints about 137×183mm, centred.
- Two landscapes on a page stack, each about 118mm tall, or about 157mm wide at 4:3. Two 4:3 photos can't both run the full 186mm width, as they'd need 279mm of height against the 255mm available on Letter.
- Two portraits on a page sit side by side.
- Captions print beneath photos, as now.

A photo whose size isn't known is treated as landscape.

## Replacing the current print work

Keep `_trip-gate.php`, the print button that waits for images to load, `@page` rules and the print bar.

Remove the `travel_steps_print_size` filter, which has never been released, and the row layouts and medium-size images in `PrintLayout`. `PrintLayout` should instead turn a section's chosen photos into pages, stay free of WordPress, and keep its unit tests.

## Testing

- Unit tests for choosing the lead and splitting photos into pages.
- Smoke tests for: the REST route's permission and refusals; independence from pin and hide; hidden photos not printing; empty sections and steps left out; anonymous and share-link visitors refused; who gets the Print button; a non-editor not seeing unpublished steps.
- A PDF checked by hand in Firefox and Chrome, A4 and Letter, because smoke doesn't run a browser. Centred text and long text running across pages are the likeliest to differ.

## Left to the builder

- REST route shape, CSS approach, and exact markup.
- How the trip page shows a section's count, given that "Show more" photos sit at the end of the step, away from their section.
- Exact sizes for pages of three or more photos.
- How a landscape and a portrait share a page.
- Updating `README.md`, `readme.txt` and the existing print tests.

## Calls made by the designer, not confirmed by the author

- Logged-out visitors can't open the print page at all, beyond not getting the button.
- First chosen photo is the lead; "Make lead" changes it.
- A sixth photo warns and doesn't block.
- Photos behind "Show more" can be chosen; hidden ones can't print.
- A step's title prints on its first section in the book only.
- Long text runs on to further pages.
- A later section with no text and no heading skips the text page; a step's first section in the book never does.
- Two landscapes on a page shrink to fit, and don't split on to two pages.
- A chosen cover prints as it shows on the trip page, even if that photo is hidden or from an unpublished step.
- Non-editors don't get a Print button on a trip with nothing chosen.
