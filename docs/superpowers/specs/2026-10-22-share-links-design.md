# Share links for password-protected trips

## Why

Sharing a password-protected trip today means sending the trip URL plus the password in a second message, and the friend then copies the password into the form. On WhatsApp that's awkward, and some people can't copy text easily at all. A share link is a single link to paste that opens the trip without the form.

Links in WhatsApp also show no preview at the moment, for any trip. The plugin outputs no preview tags.

## Goals

- **One share link per password-protected trip.** It's copied from the trip's edit page, next to the password field.
- **Opening the link shows the trip at once**, with no password form. The visitor's browser then remembers the trip just as it does after the password is typed, so the link only has to work once per browser.
- **Changing the trip's password stops old share links working.** This is the only way to revoke a link.
- **Link previews** (WhatsApp, iMessage, Slack and the like) show the trip's title, cover photo and summary. This covers public trips and protected trips opened through their share link.

## Non-goals

- Links for individual people, links that expire, or a count of how often a link is used.
- Revoking a link without changing the password.
- Share links for private or draft trips. Only a published trip can have a password.

## Anti-goals

These would count as failures even if the link worked:

- **The password appears in the link**, or can be worked back from it.
- **A link keeps working after the password changes.**
- **The share code stays in the address bar** after the page loads, so a visitor who copies the URL from there passes the code on without meaning to.
- **A wrong code behaves differently** from no code, beyond still showing the password form.
- **Preview tags on a protected trip opened without the code** show more than the title already shown on the password form.

## Decided

### The code (the user's decision that it follows the password, the details my call)

- The code is a keyed hash of the trip ID and its current `post_password`, made with `wp_hash()` (the site's salts) and truncated to at least 128 bits, URL-safe. It's checked with `hash_equals()`.
- Because the password is part of the hash, a new password means a new code. Nothing is stored.
- One link per trip, the same for everyone.

### Opening a share link (my call)

- The link is the trip's normal URL with the code added (for example `…/trip/{slug}/?share={code}`). The exact form is the builder's choice.
- With a valid code, the response sets a share cookie for that trip only (`Trips::share_cookie()`), holding the code, with core's password-cookie expiry filter, path and secure flag, plus HttpOnly and SameSite=Lax. It then renders the trip page itself with a 200. (Changed after review: it first set core's post password cookie, whose phpass hash of the password opens every post with the same password and can be cracked offline.) It doesn't redirect, because a preview fetcher doesn't carry the cookie across a redirect and would land on the password form.
- On load, the page replaces the address-bar URL with the clean trip URL (`history.replaceState`).
- The response keeps the `nocache_headers()` that protected trips already get.
- With a missing or wrong code, the trip behaves exactly as it does now.
- Each trip has its own share cookie, so opening trip B's share link leaves trip A's in place.

### Preview tags (the user wants them for public trips too; the tags themselves my call)

- **The tags:** `og:title`, `og:description`, `og:image`, `og:url`, `og:type` (`website`) and `twitter:card` (`summary_large_image`). They're output in the head of the trip page.
- **Title:** the trip title.
- **Description:** the trip's intro as plain text, trimmed to about 200 characters at a word boundary. A trip with no intro falls back to its dates, or is left without a description.
- **Image:** the cover photo, as `View::cover_url()` picks it, at the `medium` size (640px on the short side). That's big enough for a large card, and small enough that WhatsApp doesn't drop it, which it can do for an image of a few hundred KB. A trip with no cover gets no image tag. (My call. No new size is added.)
- **`og:url`:** the clean trip URL for a public trip. For a share-link response it's the share URL, because some apps fetch `og:url` again, which would otherwise end at the password form.
- **A protected trip without the code,** or one the visitor can't see, gets no description or image tags.

### The cover photo in a share-link preview (the user wants it shown; the mechanism my call)

- A preview fetcher has no cookie, so the share-link response's `og:image` URL is the photo route URL with the same code added.
- The photo route accepts a valid code for that trip's **cover photo only**, at the sizes it already serves. Any other photo with a code is refused in the normal way, with the uniform 404.
- These answers are cached `private`, as other non-public photo answers are.
- The page itself keeps loading photos with the cookie, as it does now, with no code in their URLs.

### The edit page (the user's decision on placement)

- A read-only field with the share link and a Copy button sits under the password field.
- It shows only when the saved trip is published and has a password. The edit page is already limited to people who can edit the trip, and they can see the password.
- If the password field is edited so it differs from the saved password, the link is hidden or greyed out, with a note that saving gives a new link and stops the old one working.
- Copy uses `navigator.clipboard`, and falls back to selecting the text.

## Constraints

- Follow the existing photo-route rules: a refusal is a uniform `404 no-store`, and a non-public answer has `Cross-Origin-Resource-Policy: same-site`.
- The code is equivalent to the password, so treat it that way. The plugin never logs it, and never puts it in a cacheable response.
- The share response sends `Referrer-Policy: strict-origin`, so no request the page makes passes the code on in the Referer header. The policy lasts for the page's whole life, `replaceState` or not. Not `no-referrer`: OpenStreetMap's tile servers block requests with no Referer, which left the map blank. (My call, changed after the selfhoster test.)
- Because the code is in a query string, it ends up in the web server's and Cloudflare's access logs, and in the preview caches of WhatsApp and similar apps. That's accepted: whoever reads those logs already runs the site, and the preview apps were sent the link on purpose. A URL fragment would keep the code out of the logs, but preview fetchers don't send fragments. (My call.)
- `wp_hash()` depends on the site's salts. Rotating them changes every code, so old share links stop working. That's accepted as a second, rare way to revoke links. (My call.)
- Any public text (the changelog, readme) uses plain wording and doesn't name other trip apps.

## Testing on selfhoster

Check that Cloudflare's bot protection lets WhatsApp's and Slack's preview fetchers reach `/travel-steps/`. If it doesn't, previews stay empty whatever tags the page has.

## Left to the builder

- The exact URL form and the query argument's name.
- Where the code helper lives (`Trips` seems natural).
- The exact description fallback and the trim length.
- Test layout, following `tests/playground/` and the unit tests.
