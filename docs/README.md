# Design documents

These are the working documents this plugin was built from. They are kept
because the reasoning behind several non-obvious decisions lives here and
nowhere else — in particular why enrollment happens inside the existing login
challenge rather than after it, and why the required path deliberately offers no
choice of provider.

They are **historical records, not current documentation.** Where they disagree
with the code, the code is right. `README.md` in the repository root describes
what the plugin actually does today.

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

## Related upstream work

This plugin implements [WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813).
Policy and enforcement are deliberately left to
[#846](https://github.com/WordPress/two-factor/issues/846). Issues opened as a
result of building this:
[#953](https://github.com/WordPress/two-factor/issues/953),
[#956](https://github.com/WordPress/two-factor/issues/956),
[#957](https://github.com/WordPress/two-factor/issues/957).
