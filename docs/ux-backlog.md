# Onboarding screen — UX backlog

Patterns worth borrowing from the two-factor onboarding screen cited as the
design reference in
[WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813),
adapted to a screen that solves a different problem.

That screen is **Solid Security's**, not LearnDash's. The screenshot in #813 was
taken on learndash.com, which runs the plugin;
[Timothy Jacobs, who built it, said so in the thread](https://github.com/WordPress/two-factor/issues/813).
This file called it the LearnDash screen throughout until that was noticed.

Their screen is **pre-enrollment**: it asks the user to pick a method. Ours is
**post-enrollment**: the user has already passed a real challenge, and the screen
exists to hand over recovery codes. So the affordances transfer; the structure
does not. See "Deliberately not taken" at the end.

**Items 1–5 have shipped.** They are kept below as the reasoning behind what is
on the screen now, not as work to do. Open work is in "Still open" at the end.

---

## 1. Gate Continue behind acknowledging the codes — shipped

**The problem it solved.** The codes are displayed exactly once — the
compare-and-swap consumes the one-time token before rendering — and `Continue`
was live from the moment the page loaded. A user could click past ten recovery
codes without saving any of them.

**What shipped.** An explicit acknowledgement:

> ☐ I've saved these codes somewhere safe

with `Continue` inert until it is checked.

**How the no-JS constraint is honored.** The server renders an ordinary working
`<a href>` and a `hidden` checkbox; the inline script reveals the checkbox and
only then arms the gate. With JavaScript off the checkbox never appears and
`Continue` behaves exactly as it did before. Blocking is `preventDefault()` plus
`aria-disabled` and a class — never the `href` — so a script that fails halfway
leaves a working link rather than a dead one. **This is not decorative:** a user
who cannot leave this screen is locked out by a UX nudge, on the recovery path,
with the marker already `done` and no second chance at the screen.

The gate lives in its own IIFE. The clipboard block returns early when
`navigator.clipboard` is absent, which is every plain-HTTP install; sharing that
function body would have silently disarmed the gate there.

Only rendered alongside real codes — with the codes section omitted there is
nothing to have saved.

**Tests.** Markup and emitted script both, mutation-verified in both directions:
adding `aria-disabled` to the server-rendered link fails the no-JS guard, and
dropping `preventDefault` fails the gate test.

---

## 2. Status badge on the upgrade offer — shipped

The "Want something faster?" block was an undifferentiated paragraph and a link.
It now leads with the method name and a neutral state chip:

> **Authenticator app** · Not set up

"Not set up" is a fact rather than a guess: the screen is only reachable with the
onboarding marker `pending`, which only the gate sets, and only for a user who
had no configured provider when they signed in. Anyone who already had an
authenticator app never enrolls through this plugin.

The chip stays grey — not having taken up an optional upgrade is the normal
state, not a warning.

---

## 3. Method icon — shipped

Inline SVG beside the authenticator offer. Not dashicons: the login screen never
enqueues that stylesheet, so the alternative was loading an entire icon font for
one glyph, and the plugin still ships no asset files.

Decorative, so `aria-hidden` and `focusable="false"` — the text beside it already
reads "Authenticator app". It inherits `currentColor` so it tracks the label.

The codes block was left without one. The method row is a self-contained
component; the codes block is the screen's subject and does not need labelling.

---

## 4. Let sites brand the header space — shipped

`two_factor_enrollment_onboarding_header`, default `''`, rendered above the card.
Documented in the README's Filters section.

The default is empty and link-free deliberately. The WordPress logo was removed
because it linked to wordpress.org from directly above one-time codes that
navigating away destroys, and that reasoning applies to whatever replaces it. A
site supplying markup with a link has made that call for its own users.

Run through `wp_kses_post()` — a plugin registering this filter is trusted to put
a logo on the page, which is not the same as being trusted to inject a script
above recovery codes on a screen the user was forced onto. That also rules out
inline SVG; supply a logo as an `<img>`, `data:` URI included if you want to
avoid the request.

---

## 5. Tighten the copy — shipped

The backup-codes explanation was five lines carrying the regeneration caveat, the
once-only warning and the save instruction in one block, with the only sentence
the user has to act on sitting in the middle of it. Split into a leading
imperative and a quieter caveat.

All five substantive clauses survive and the suite asserts each individually. The
regeneration clause especially — a review added it after finding the copy
overstated the permanence of the codes, and "will not be shown again" reads as
though recovery is now impossible without it.

The caveat is set secondary, not fine print: it carries the one piece of
reassurance on the screen.

---

## 6. Both factors as method rows — shipped

The screen states what the user now has, not only what they could add. The
enrolled factor carries the same row as the upgrade offer — icon, name, state
chip:

> ✉ **Email** · Active
> 📱 **Authenticator app** · Not set up

That symmetry is what earns the icons their place: both label a component rather
than decorating a heading. An icon on the `<h2>` alone would have been the
latter.

Two rows with states is **not** the method list ruled out below. Nothing here is
selectable, both statements are simply true, and the required path that must
never offer a choice is upstream of this screen — the challenge has already been
passed by the time it renders.

The Email row is unconditional; the authenticator row is not (see "Still open",
now closed, and the site-wide availability check in `totp_available()`).

---

## 7. Button labels — shipped

"Download codes" and "Copy codes" became "Download" and "Copy". They sit directly
beneath the boxed list they act on, under a heading already reading "Save your
backup codes" — three repetitions of the word in a few inches.

No `aria-label` restoring the longer name. The copy button swaps its visible text
to "Copied!" and "Couldn't copy", and a fixed accessible name of "Copy codes"
would then not contain the visible label, which is what WCAG 2.5.3 (Label in
Name) tests and what voice-control users speak. A transient mismatch is worse
than the shorter name.

---

## Closed since

**The upgrade offer now checks whether TOTP is available site-wide.** Found while
doing item 2. If `Two_Factor_Totp` is removed via the `two_factor_providers`
filter, the "Want something faster?" section used to render anyway and link to a
profile screen with nothing on it — worse once the chip landed, since the screen
then asserted a state for a method that was not on offer.
`totp_available()` now mirrors the check `maybe_generate_backup_codes()` already
made for Backup Codes.

---

## Deliberately not taken

**The method chooser.** Solid Security presents Mobile App / Email / Backup Codes
as a menu. Adopting that on a forced-enrollment screen would undo this plugin's
central security property: an attacker holding only the password could pick TOTP,
enroll their own authenticator, and never need the victim's mailbox. The required
path offers exactly one factor for exactly that reason. Every option added to a
forced-enrollment chooser is a route around the mailbox requirement.

**A visible "Skip".** Deferral here is a policy decision made in the gate before
any challenge — a deferring user simply logs in normally and never sees this
screen. Rendering a skip would imply an escape the screen does not own.

**The disabled-state list.** Their screen shows every method as `Disabled` because
it runs before enrollment. Ours runs after a challenge has already been passed, so
the equivalent list would be misleading.

---

## Upstream

The chooser argument **has been made** on #813 — "adopt the affordances, not the
chooser, and here is the attack that motivates it" — alongside the earlier
comment on the enrollment race and email-first enrollment. What remains open in
that thread is Timothy Jacobs's suggestion of a login-interstitial framework for
enforcing requirements at login: worth answering with the finding that
email-first enrollment needs no interstitial, because it completes inside core's
existing challenge before any auth cookie is issued.
