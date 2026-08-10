# Design documents

These are the working documents this plugin was built from. They are kept
because the reasoning behind several non-obvious decisions lives here and
nowhere else — in particular why enrollment happens inside the existing login
challenge rather than after it, and why the required path deliberately offers no
choice of provider.

The two documents in the table below are **historical records, not current
documentation.** Where they disagree with the code, the code is right. This file
is the exception: the sections after the table are maintained, and describe what
was built rather than what was planned. `README.md` in the repository root
describes what the plugin does today from a user's point of view.

| Document | Status |
|---|---|
| [design.md](design.md) | The approved design. Accurate on architecture and rationale; superseded in the specifics listed below. |
| [implementation-plan.md](implementation-plan.md) | The task-by-task build plan. Fully historical — its task numbering does not map to the final file structure, and several of its code samples contain defects that were corrected during implementation. |

## Where the design was superseded

Every item below was found during implementation, review, or live testing, and
the shipped behavior differs from the design deliberately.

**The notification is sent from the enrollment write, not from a separate hook.**
The design had a `maybe_notify()` on `two_factor_user_authenticated` that
deduplicated by reading the onboarding flag. That forward-referenced a class
introduced by a later task, and it would have re-notified anyone who never
finished the onboarding screen — on every subsequent login.

**The onboarding marker is tri-state, not boolean.** `_two_factor_enrollment_onboarded`
is absent, `pending:<token>`, or `done`. The design's boolean would have diverted
*every* user with two-factor configured to the onboarding screen on activation,
including people who set up TOTP long before this plugin existed.

**The onboarding screen enables backup codes rather than only displaying them.**
`Two_Factor_Backup_Codes::generate_codes()` writes the code hashes but does not
add the provider to the user's enabled list, and Two Factor only offers providers
that are both enabled and configured. The design would have shown users
real-looking codes that silently did nothing at login.

**The onboarding screen is authorized by a one-time token.** Not in the design at
all. Without it, a single cross-site click on a logged-in user in the pending
state consumed the one-time recovery screen — generating and enabling codes the
user never saw.

**That token is claimed by compare-and-swap before rendering.** Two concurrent
requests carrying the same valid token could otherwise both generate a set of
codes, with the last write winning and one browser displaying codes that were not
the ones stored.

**Enrollment persistence is gated on this plugin having caused the enrollment.**
The design's handler fired for any user authenticating via Email, so on a site
with no policy configured the plugin still wrote user meta, sent mail, and
diverted users — violating its own core promise that installing it alone changes
nothing.

**The deferral filter is honored.** `two_factor_enrollment_deferrable` was
defined and tested by the design but consumed by nothing, making it a documented
filter that did not work.

**A `two_factor_enrollment_enrolled` action was added.** The design assigns all
deferral counting and deadline state to the policy layer but gave it no signal
that enrollment had completed.

**Provider injection needs one filter, not two.** `get_primary_provider_for_user()`
forces the primary when exactly one provider is available, and the Email
provider is always available, so the second filter the design specified was
redundant.

**The plugin ships scoped CSS and a small amount of JavaScript.** The design
committed to neither. Both are delivered inline, conditionally, and only on the
onboarding screen.

**Sections 6.4 and 7 were never built.** The fallback enrollment wizard for sites
that have disabled the Email provider, and the session-lockdown layers that path
would require, are out of scope. The plugin stands down and shows an
administrator notice instead. Building them would depend on
[WordPress/two-factor#934](https://github.com/WordPress/two-factor/issues/934).

## The onboarding screen as shipped

Design §6.2 describes this screen in three bullets: backup codes, a TOTP offer,
and Continue. What shipped is considerably more, and the reasoning is recorded
here because most of it is not recoverable from the markup.

The reference screen cited in
[#813](https://github.com/WordPress/two-factor/issues/813) is **Solid Security's**,
not LearnDash's — the screenshot was taken on learndash.com, which runs the
plugin, and Timothy Jacobs, who built the UI, said so in that thread. Its
affordances transfer; its structure does not, because it runs *before*
enrollment where the user must choose, and this one runs after.

**Continue is gated behind acknowledging the codes.** The codes are displayed
exactly once — the compare-and-swap consumes the one-time token before anything
renders — and Continue was live from page load, so ten recovery codes were one
click from being lost. The server renders an ordinary working `<a href>` and a
`hidden` checkbox; the inline script reveals the checkbox and only then arms the
gate. With JavaScript off the checkbox never appears and Continue behaves as it
did before. Blocking is `preventDefault()` plus `aria-disabled` and a class,
never the `href`, so a script that fails halfway leaves a working link rather
than a dead one. **This constraint is not decorative:** a user who cannot leave
this screen is locked out by a UX nudge, on the recovery path, with the marker
already `done` and no second chance at the screen. The gate lives in its own
IIFE — the clipboard block returns early wherever `navigator.clipboard` is
absent, which is every plain-HTTP install, and sharing that function body would
have silently disarmed the gate there.

**Both factors are shown as method rows** — icon, name, state chip — so the
screen says what the user has before what they could add: Email · Active, and
Authenticator app · Not set up. That symmetry is what earns the icons their
place; an icon on the heading alone would be decoration. Icons are inline SVG,
because the login screen never enqueues dashicons and the plugin ships no asset
files.

**The authenticator offer is conditional; the Email row is not.** Any provider
can be removed site-wide through `two_factor_providers`, and offering one that
has been removed sends the user to a profile screen with nothing on it — worse
with a chip, since the screen then asserts a state for a method that is not on
offer. `totp_available()` mirrors the check `maybe_generate_backup_codes()`
already made for Backup Codes. Email needs no such guard: it is the only factor
this plugin enrolls anyone in, and the user passed an emailed challenge moments
earlier in the same request chain.

**The codes copy leads with the instruction.** It was one five-line paragraph
with the only actionable sentence buried mid-way; it is now an imperative
followed by a quieter caveat. Every substantive clause survived and the suite
asserts each individually — the regeneration clause especially, which a review
added after finding the copy overstated the permanence of the codes.

**The buttons are "Download" and "Copy", not "Download codes" and "Copy codes".**
They sit directly beneath the list they act on, under a heading already reading
"Save your backup codes". No `aria-label` restores the longer name: the copy
button swaps its visible text to "Copied!" and "Couldn't copy", and a fixed
accessible name would then not contain the visible label, which is what WCAG
2.5.3 tests and what voice-control users speak.

**Sites can brand the space where the WordPress logo was.**
`two_factor_enrollment_onboarding_header`, default `''`, run through
`wp_kses_post()`. The logo was removed because it linked off-site from directly
above one-time codes that navigating away destroys, so the default stays empty
and link-free; supplying markup with a link is a decision a site makes for its
own users.

### Deliberately not taken

**A method chooser.** Solid Security presents Mobile App / Email / Backup Codes
as a menu. On a *forced* enrollment screen that would undo this plugin's central
security property: an attacker holding only the password could pick TOTP, enroll
their own authenticator, and never need the victim's mailbox. The required path
offers exactly one factor for that reason, and every option added to a
forced-enrollment chooser is a route around the mailbox requirement. Two method
rows with states is not this: nothing on the screen is selectable, both
statements are true, and the required path that must offer no choice is upstream
of this screen.

**A visible "Skip".** Deferral is a policy decision made in the gate before any
challenge — a deferring user logs in normally and never sees this screen.
Rendering a skip would imply an escape the screen does not own.

**A disabled-state method list.** The reference screen shows every method as
`Disabled` because it runs before enrollment. Ours runs after a challenge has
been passed, so the equivalent list would be misleading.

## Related upstream work

This plugin implements [WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813).
Policy and enforcement are deliberately left to
[#846](https://github.com/WordPress/two-factor/issues/846). Issues opened as a
result of building this:
[#953](https://github.com/WordPress/two-factor/issues/953),
[#956](https://github.com/WordPress/two-factor/issues/956),
[#957](https://github.com/WordPress/two-factor/issues/957).
