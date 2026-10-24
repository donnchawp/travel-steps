# Email roundups for trip subscribers

## Why

Friends and family who follow a trip have no way to know when there's something new, short of checking the trip page. A daily email listing the newly published days, and the days that got new photos, brings them back when there's something to see, without the author having to message anyone.

Most of the author's trips will be password-protected, shared with a share link.

## Goals

- **Visitors subscribe to one trip** from the trip page, with their email address, and confirm by clicking a link sent to it.
- **Logged-in visitors subscribe with a button**, with no confirmation email: their account's address is used.
- **Subscribers see an Unsubscribe button** on the trip page, and every email has an unsubscribe link that works in one click.
- **One plain-text roundup per trip per day**, at a fixed hour, only when something new happened.
- **The author sees and manages subscribers** on the trip's edit page.
- **Changing a trip's password drops its email-only subscribers**, so revoking access by changing the password (see the share links design) also stops the emails.

## Non-goals

- HTML email, photos in the email, or excerpts of a step's text.
- An email for each change as it happens.
- The author adding subscribers. Everyone signs up themselves.
- Telling the author when someone subscribes.
- Publishing or adding photos without notifying subscribers.
- A site-wide subscription to every trip.

## Anti-goals

These would count as failures even if the emails went out:

- **An email names or links to anything the recipient can't see:** a draft or scheduled step, a hidden photo, a private trip.
- **Anyone can make the site send email to an address that didn't ask for it**, beyond one confirmation email per address per trip per day.
- **An email-only subscriber keeps getting roundups after the trip's password changes.**
- **A roundup goes out for a change that's only a text edit.**
- **Turning the feature on emails subscribers about everything already on the trip.**
- **One recipient's address is visible to another** (no shared To or BCC).

## Decided

### Who can subscribe (the user's decisions)

- Subscriptions are per trip.
- A visitor who isn't logged in enters an email address. The subscription is pending until they click the confirmation link emailed to them.
- A logged-in visitor gets a Subscribe button. It subscribes their account at once, with no confirmation. The roundup goes to their account's current email address.
- Someone subscribed sees an Unsubscribe button in place of the form. A logged-in subscriber is recognised by their account. One who isn't logged in is recognised by a cookie set when they confirm, so on another device they see the form again. That's accepted (my call).
- On a protected trip, the form only appears to people who can see the trip: editors, viewers, and visitors with the password cookie.
- A trip that's private or a draft has no form.

### What triggers a roundup (the user's decisions)

- **A step is published** (including a scheduled step going live). That always counts.
- **New photos appear on a published step:** photos visible to visitors that weren't visible at the last roundup. Photos on draft steps, and hidden photos, don't count.
- **Text edits to a published step** are listed only in a roundup that one of the two triggers above has already caused. On their own, they send nothing.
- Not counted: the cover photo, pinning or hiding photos, the trip's title, dates, intro or route.

### When it's sent (the user's decisions)

- Once a day, at a fixed hour on the site settings page, defaulting to 08:00 in the site's timezone.
- A trip with no trigger since its last roundup sends nothing that day.
- Changes made after the day's send wait for the next day.
- A private or draft trip sends nothing. Its subscriptions are kept, in case it's published again.

### What the email says (the user's decisions: plain text, share code)

Plain text, the same layout for public and protected trips:

- Subject: the trip's title and what's new (for example "Portugal and Spain 2026: 2 new days").
- The newly published steps, each with its date, title and link.
- "New photos on:" the steps that got new photos, with links.
- "Also updated:" published steps whose text changed, with links.
- A footer: why they're getting it ("You subscribed to this trip on {site}") and a one-click unsubscribe link.

For a protected trip, every link in the roundup carries the trip's current share code (`?share=…`), so it opens the trip even after the subscriber's password cookie has expired. The share link already removes the code from the address bar.

### Revoking (the user's decisions)

- When a trip's password changes, from the plugin's edit page or anywhere else (block editor, wp-admin, REST), every email-only subscription to it is deleted, with no email.
- Logged-in subscribers are checked when each roundup is sent: one who can no longer see the trip is skipped for that roundup, and is kept.

### The author's controls (the user's decisions)

- The trip's edit page has a Subscribers panel listing each subscriber: the email address, or the user's display name for a logged-in subscription. It marks pending ones. Each has a Remove button.
- It shows the count, and when the last roundup was sent and what it listed.

### Sending (my calls)

- Mail goes through `wp_mail()`, so the site's own mail setup (an SMTP plugin, for example) applies.
- One email per recipient, with that recipient's own unsubscribe link.
- WP-cron runs the send at the hour set. A site that only runs cron on page loads may send late. That's accepted.
- The From name and address are the site's defaults.
- Unsubscribe links work in one click (GET), and the email also carries `List-Unsubscribe` and `List-Unsubscribe-Post` headers, so mail clients can show their own unsubscribe button.

### Abuse (my calls)

- The signup form has a hidden honeypot field. A filled-in honeypot gets the same "check your email" page and sends nothing.
- An address gets at most one confirmation email per trip per day. Signing up again within the day shows the same page and sends nothing.
- The form's response is the same whether the address is new, pending or already subscribed, so it can't be used to find out who follows a trip.
- An unconfirmed subscription expires after 7 days.
- Confirmation and unsubscribe tokens are random, unguessable values, compared with `hash_equals()`.

### Data (my calls)

- Each subscription is a post of a hidden custom post type, with the trip as its parent, as steps are. Its meta holds the email address or user ID, the status (pending or confirmed), the token, and when the last confirmation email was sent.
- What was last sent for each trip (the visible photo IDs per published step, and each step's last modified time) is kept as trip meta, so the next roundup can tell what's new.
- When the feature first runs, every trip's current state is recorded as already sent, so nothing that existed before triggers a roundup.
- The privacy policy text mentions subscriptions. Subscriptions are covered by WordPress's personal data exporter and eraser, looked up by email address.
- Deleting the plugin deletes every subscription. Deleting a trip deletes its subscriptions.

## Constraints

- What counts as visible is what visitors are shown now: `Trips::visible_photo_ids()` and the step statuses the trip page already uses. Don't build a second idea of visibility.
- No address, token or share code is logged.
- No public text (readme, changelog, email wording) names other trip apps.
- Works on Playground's SQLite as well as MySQL. The smoke tests use Playground.

## Left to the builder

- Where the form sits on the trip page, and its wording.
- How the edit page's panel looks, following the existing panels.
- How the send finds the trips with subscribers, and how it splits the work if a run would take too long.
- The token format, and the subject line's exact wording.
