# Onboarding screen — UX backlog

Patterns worth borrowing from the LearnDash two-factor onboarding screen cited as
the design reference in
[WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813),
adapted to a screen that solves a different problem.

Their screen is **pre-enrollment**: it asks the user to pick a method. Ours is
**post-enrollment**: the user has already passed a real challenge, and the screen
exists to hand over recovery codes. So the affordances transfer; the structure
does not. See "Deliberately not taken" at the end.

Ordered by value. Each item is independently shippable.

---

## 1. Gate Continue behind acknowledging the codes

**The problem it solves.** This is the screen's one remaining serious UX risk. The
codes are displayed exactly once — the compare-and-swap consumes the one-time
token before rendering — and `Continue` is live from the moment the page loads.
A user can click past ten recovery codes without saving any of them. The copy
mentions they can regenerate from their profile, but nothing slows the click down.

**The pattern.** LearnDash greys out `Continue` until at least one method is
enabled, so the screen cannot be left in an unprotected state. The direct analogue
here is an explicit acknowledgement:

> ☐ I've saved these codes somewhere safe

with `Continue` inert until it is checked.

**Shape.** Progressive enhancement, and this constraint is not optional: `Continue`
is an `<a>`, and the screen must stay usable with JavaScript off. Render the
checkbox and a working `Continue`; the inline script adds the gating. Without
JavaScript the checkbox is decorative and `Continue` behaves exactly as it does
today. **Never ship a version where no-JS users cannot leave the screen** — that
converts a UX nudge into a trap on the recovery path.

**Cost.** Small. One checkbox, a few lines in the existing inline script, one CSS
rule. No new state, no new request.

**Risk.** Mild friction on a screen users want to leave. Acceptable: the friction
is exactly the point, and it is one click.

**Tests.** Assert the checkbox renders; assert `Continue` carries no disabling
attribute in the server-rendered HTML (proving no-JS users are not trapped);
assert the emitted script wires the gate. That last one matters — an earlier
review found the suite asserting markup while the behaviour lived in the script.

---

## 2. Status badges on the upgrade offer

**The pattern.** Each LearnDash method shows a state chip — `Disabled` / `Enable`.
State is legible without reading prose.

**Here.** The "Want something faster?" block is currently an undifferentiated
paragraph and a link. It could read:

> **Authenticator app** · Not set up
> Generates codes instantly, with no waiting for email.

Only the optional-upgrade block. The required path shows no method list at all,
by design.

**Cost.** Trivial — markup and one CSS rule. No JavaScript.

**Value.** Moderate. Mostly it makes the upgrade feel like a thing with a state
rather than a footnote.

---

## 3. Method icons

**The pattern.** LearnDash anchors each method with a small icon.

**Here.** One icon beside the authenticator-app offer, and possibly one on the
codes block.

**Cost and caveat.** The login screen does not enqueue `dashicons` by default, so
this means either enqueueing it — weight for one glyph — or inlining SVG. Inline
SVG is the better trade and keeps the no-asset-file property.

**Value.** Low. Cosmetic. Do it only if items 1 and 2 land and the screen still
reads flat.

---

## 4. Let sites brand the header space

**The pattern.** LearnDash replaces the WordPress logo with their own mark.

**Here.** We removed the logo entirely — it linked off-site to wordpress.org,
directly above codes that navigating away destroys — which leaves the space empty.
An agency deploying this for a client has an obvious use for it.

**Shape.** A filter rendering optional markup above the card, defaulting to
nothing. Do **not** restore a linked logo: the reason the original was removed is
that any outbound link on this screen costs the user their codes. If markup with a
link is supplied, that is the site's decision, but the default must stay
link-free.

**Cost.** Small. One filter, one optional block.

---

## 5. Tighten the copy

**The pattern.** LearnDash uses a bold method name and one terse line. Ours uses
full paragraphs.

**Here.** The backup-codes explanation is five lines and carries the regeneration
caveat, the once-only warning, and the save instruction in one block. It could
split into a short imperative line plus a smaller caveat.

**Cost.** Copy editing only. **Care required:** every clause in that paragraph
was added deliberately — the "you can generate a new set from your profile"
sentence exists because a review found the original copy overstated the
permanence of the codes. Shorten without dropping the substance.

---

## Deliberately not taken

**The method chooser.** LearnDash presents Mobile App / Email / Backup Codes as a
menu. Adopting that on a forced-enrollment screen would undo this plugin's central
security property: an attacker holding only the password could pick TOTP, enroll
their own authenticator, and never need the victim's mailbox. The required path
offers exactly one factor for exactly that reason. Every option added to a
forced-enrollment chooser is a route around the mailbox requirement.

**A visible "Skip".** Deferral here is a policy decision made in the gate before
any challenge — a deferring user simply logs in normally and never sees this
screen. Rendering a skip would imply an escape the screen does not own.

**The disabled-state list.** Their screen shows every method as `Disabled` because
it runs before enrollment. Ours runs after a challenge has already been passed, so
the equivalent list would be misleading.

---

## Upstream

The chooser argument is worth making on #813 itself, where this screenshot is
cited as the design target. "Adopt the affordances, not the chooser, and here is
the attack that motivates it" is a substantive contribution to that thread, and
one nobody there has made yet.
