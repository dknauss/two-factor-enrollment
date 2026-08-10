> **Historical record.** This is the approved design, not current documentation. See [README.md](README.md) in this directory
> for where this was superseded, and the repository root `README.md` for what
> the plugin does today.

# Two-Factor Enrollment — Design

**Date:** 2026-08-09
**Status:** Approved design, not yet implemented
**Upstream target:** [WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813) — "Setup Onboarding Login-Flow (for Enforce 2FA)"

---

## 1. Summary

A standalone WordPress add-on plugin that provides the 2FA enrollment experience
described in upstream issue #813, without owning any enforcement policy of its own.

The central design decision: **the second factor is proven before any auth cookie
is issued, by pre-enabling the Email provider so that WordPress core's existing
two-factor login challenge performs the enrollment.** Confirming the emailed code
proves mailbox control and enrolls the user in the same step. No password-only
session is ever created on the required path.

Stated precisely, because the ordering matters and is easy to describe loosely:
`Two_Factor_Core` validates the emailed code, *then* calls `wp_set_auth_cookie()`,
*then* fires `two_factor_user_authenticated`, at which point this plugin writes the
enrollment to user meta. So the cookie is issued before the enrollment is
*persisted* — but only ever after the second factor has been *validated*, which is
the property that matters. The permanent record is bookkeeping that follows a
challenge already passed; it plays no part in gating the cookie.

A post-login onboarding screen — shown once, on a session that has already passed
a real challenge — offers backup codes and the TOTP upgrade. That screen is
convenience, not a security boundary.

## 2. Context and prior art

Enforcement of 2FA is the most-requested missing feature in the Two Factor plugin.
The upstream work is decomposed across several open issues:

| Issue / PR | Scope | Status as of 2026-08-09 |
|---|---|---|
| [#846](https://github.com/WordPress/two-factor/issues/846) | Role-based enforcement settings and logic | Open, **assigned to masteradhoc** |
| [#813](https://github.com/WordPress/two-factor/issues/813) | Login-flow onboarding wizard | Open, "Help Wanted", unbuilt — **this spec** |
| [#934](https://github.com/WordPress/two-factor/issues/934) | Server-owned TOTP enrollment over REST | Open, contributor offered a PR, no maintainer response |
| [#335](https://github.com/WordPress/two-factor/issues/335) | Make settings portable off `profile.php` | Open |
| [#778](https://github.com/WordPress/two-factor/issues/778) | Require verification before activating Email | Open |
| [PR #239](https://github.com/WordPress/two-factor/pull/239) | Human Made's "Optionally Force 2fa" | Open since 2018, prior art for #846 |

Existing implementations that inform this design:

- **[Require-Email-2FA](https://github.com/dknauss/Require-Email-2FA)** — enables the
  Email provider for every user so core's login challenge applies to all accounts.
  Targets by *exclusion* (everyone, minus excluded roles). No admin UI. This spec
  deliberately reuses its core insight.
- **WordPress VIP** — `wpcom_vip_is_two_factor_forced`.
- **PR #239** — targets by *inclusion* (only these roles). Redirects logged-in users
  from `parse_request`, which bounces users off the front end; not repeated here.

### 2.1 WordPress core has no relevant primitive

Verified against a local WordPress 7.0.2 install (7.0.3 is the current release and is
a security release, per wordpress.org/news):

- `grep -rn "two_factor" wp-includes/` returns zero hits.
- `determine_current_user` carries only `wp_validate_auth_cookie`,
  `wp_validate_logged_in_cookie`, and `wp_validate_application_password`
  (`wp-includes/default-filters.php:507-509`). There is no partial-authentication
  or nonce-scoped capability primitive.
- The WordPress 7.1 Field Guide (2026-08-05, RC phase) does not mention 2FA.

No core change is available or expected. This design requires none.

## 3. Scope

### In scope

- Enrollment gated on the emailed-code challenge, via Email provider pre-enablement,
  so no cookie is issued until the second factor is proven.
- A one-time post-login onboarding screen offering backup codes and TOTP.
- A fallback multi-step enrollment flow for sites where Email is disabled site-wide.
- Session hardening for the fallback path only.
- Enrollment notification mail.

### Non-goals

- **Any enforcement policy.** No settings page, no role targeting, no capability
  targeting. That is #846's scope and it is assigned. This plugin is inert until
  something else tells it a user must enroll.
- **Any persisted policy state.** No options, no settings, no role or capability
  targeting. The plugin's only stored state is a single boolean user meta flag
  recording that the onboarding screen has been shown (§6.2); everything else is
  derived from the contract filters plus `Two_Factor_Core`'s existing state.
- **Front-end interception.** Off by default; see §6.3.
- **Replacing `profile.php` settings.** Ongoing 2FA management stays where it is.

## 4. The contract

Behavior is driven entirely by two filters. The four additional filters in §10 tune
an already-decided behavior; these two decide whether anything happens at all.

```php
/**
 * Whether this user must enroll in two-factor authentication.
 *
 * @param bool    $required Default false.
 * @param WP_User $user     The user being evaluated.
 */
apply_filters( 'two_factor_enrollment_required', false, $user );

/**
 * Whether this user may defer enrollment.
 *
 * The policy layer owns all counting, deadlines, and state. This plugin
 * stores nothing and only honors the answer.
 *
 * @param bool    $allowed Default false.
 * @param WP_User $user    The user being evaluated.
 */
apply_filters( 'two_factor_enrollment_deferrable', false, $user );
```

Completion is **not** a third filter. It is
`Two_Factor_Core::is_user_using_two_factor( $user )`, which resolves true exactly
when a primary provider is configured. One source
of truth; no parallel state to drift.

Both defaults are `false`, so installing this plugin alone changes nothing.
Require-Email-2FA, #846, and VIP's `wpcom_vip_is_two_factor_forced` can each drive
it without knowing the others exist.

## 5. Architecture

Five small classes. The predicate class has no WordPress side effects and no output,
so the security-relevant decisions are reviewable in one short file.

| File | Responsibility | Depends on |
|---|---|---|
| `includes/class-enrollment.php` | Pure predicates: `is_required()`, `is_deferrable()`, `is_complete()`, `email_provider_available()` | Contract filters, `Two_Factor_Core` |
| `includes/class-enrollment-gate.php` | Pre-cookie path: injects Email as the resolved provider before `filter_authenticate` | `Enrollment` |
| `includes/class-enrollment-onboarding.php` | Post-login one-time screen: backup codes, TOTP upgrade | `Enrollment` |
| `includes/class-enrollment-fallback.php` | Fallback multi-step flow when Email is unavailable, plus its session lockdown | `Enrollment` |
| `includes/class-enrollment-notify.php` | Enrollment notification mail | `Two_Factor_Core` |

Bootstrap `two-factor-enrollment.php` carries `Requires Plugins: two-factor`
(WP 6.5+). The dependency is hard: the plugin no-ops entirely if
`Two_Factor_Core` is absent.

## 6. Flow

### 6.1 Required path (Email available) — the normal case

1. User submits username and password.
2. On `two_factor_enabled_providers_for_user`, the gate injects `Two_Factor_Email`
   for any user where `is_required()` is true and `is_complete()` is false. One
   filter suffices: `get_primary_provider_for_user()` forces the primary when
   exactly one provider is available, and `Two_Factor_Email::is_available_for_user()`
   always returns true, so Email becomes primary without a second filter.
3. `Two_Factor_Core::filter_authenticate()` runs at priority 31, sees
   `is_user_using_two_factor() === true`, and adds
   `send_auth_cookies => __return_false`.
   **No auth cookie is issued.**
4. `Two_Factor_Core::wp_login()` renders core's existing Email challenge and exits.
5. The user enters the emailed code. Core validates it, issues the auth cookie, and
   stamps `two-factor-login` and `two-factor-provider` on the session.
6. The gate persists the enrollment: Email is written to
   `ENABLED_PROVIDERS_USER_META_KEY` and `PROVIDER_USER_META_KEY` on
   `two_factor_user_authenticated`, so the enrollment is real, visible in the user's
   profile, and no longer dependent on the runtime filter.
7. `is_user_using_two_factor()` is now true. Enrollment is complete.

Steps 3 through 5 are entirely core's existing, already-audited code path. This
plugin contributes the injection in step 2 and the persistence in step 6.

Note the ordering of steps 5 and 6: the cookie is issued *before* the enrollment is
written to user meta. That is correct and intentional — step 6 records a challenge
that step 5 already validated. What the design guarantees is that no cookie exists
until the second factor has been proven, not that the database write precedes it.

Injection is a runtime filter until step 6 so that deactivating the plugin before a
user has ever logged in leaves no residue.

### 6.2 Onboarding screen

After the first successful two-factored login of a user enrolled by this plugin,
one redirect to `wp-login.php?action=two_factor_onboarding` presents:

- **Backup codes** — generated and displayed once, with download. Email-only plus a
  broken mailbox is lockout with no way back, so the recovery path is put in front of
  the user rather than buried in `profile.php`. Generating codes nobody ever saw
  would be worthless, so display is the entire value.
- **TOTP upgrade** — an explanatory offer with a link, not a step.
- **Continue** — proceeds to the original `redirect_to`.

This screen is skippable and shown once. It is not a security boundary: the session
reaching it has already passed a real challenge. Tracked by a single user meta flag
`_two_factor_enrollment_onboarded`, the plugin's only stored state, cleaned up on
uninstall.

`wp-login.php` accepts a custom `action` when `has_filter( 'login_form_' . $action )`
is registered (`wp-login.php:509`), and `do_action( "login_form_{$action}" )` fires at
`wp-login.php:571`, before the routing switch at 585. Core's `login_header()` and
`login_footer()` are therefore in scope; two-factor's `includes/` shims are not needed.

### 6.3 Front end is not intercepted

Default `false`, exposed as `two_factor_enrollment_intercept_frontend`.

PR #239 hooks `parse_request` and redirects logged-in users to wp-admin, so a
subscriber reading a post is thrown into the admin. On the required path the question
is moot — the user never gets a session until enrolled. The filter exists for the
fallback path only.

### 6.4 Fallback path (Email disabled site-wide)

`#764` shipped a settings screen that filters providers through `two_factor_providers`
(`two-factor.php:147`), so an administrator can disable Email entirely. There is then
no mailbox-bound factor to gate on, and pre-cookie enrollment is impossible: every
remaining provider requires configuration, and configuration requires a session.

In that configuration only:

1. The user logs in normally and receives a session flagged pending (§7).
2. `admin_init` redirects to `wp-login.php?action=two_factor_setup`.
3. Method chooser → configure (delegating to
   `do_action( 'two_factor_user_options_' . $provider_key, $user )`) → **verify** →
   backup codes → done.
4. Verification before activation is mandatory here. It is the same concern as #778.

An admin notice states that enrollment cannot be mailbox-verified in this
configuration and that the enrollment race (§8) is consequently open. Naming the
weakness is better than failing closed on a site that deliberately chose TOTP-only.

## 7. Session security — fallback path only

These layers apply **only** to §6.4. On the required path no password-only session
exists, so none of this runs.

**Layer 1 · Capability neutering.** A `user_has_cap` filter at `PHP_INT_MAX` replaces
`allcaps` with `array( 'read' => true )` for a pending session. Every
`current_user_can()` that maps to a real capability then fails regardless of entry
point — which matters because `admin-ajax.php` fires `admin_init`
(`wp-admin/admin-ajax.php:45`) but must not be redirected, and `admin-post.php`,
`async-upload.php`, REST, and front-end actions all bottom out in capability checks
rather than routing.

Verified caveat: `map_meta_cap` short-circuits `edit_user` on **self** at
`wp-includes/capabilities.php:70` — it `break`s with an empty `$caps` array, and
`WP_User::has_cap()` returns true for an empty requirement. So
`current_user_can( 'edit_user', $own_id )` survives a total `allcaps` wipe. That is
necessary (the enrollment REST endpoints depend on it) but it also leaves
`profile.php` reachable. Changing the account email from a password-only session is
an account-takeover primitive, so a `user_profile_update_errors` handler adds a
blocking error for pending sessions, preventing the save outright rather than relying
on the redirect.

**Layer 2 · REST namespace fence.** `rest_pre_dispatch` rejects any route outside
`two-factor/1.0` with 403 when the request is cookie-authenticated on a pending
session. Keyed off the session flag via `wp_get_session_token()`, so Application
Password and other non-cookie auth — which carry no session token — are unaffected.
Belt-and-braces over layer 1, since some REST routes ship permissive permission
callbacks.

**Layer 3 · Session tagging and idle expiry.** `attach_session_information` stamps
`two-factor-enrollment-pending => time()`. Two consequences:

- Lockdown scopes to *that session*, not the user globally, so an already-two-factored
  session elsewhere is not neutered.
- It supports a bound: **15 minutes idle, refreshed on each wizard step, with a
  60 minute absolute cap**, both filterable. Fixed wall-clock fails the realistic
  case — install an authenticator app, find your phone — and expiring mid-setup is
  the confusion this whole design is trying to avoid.

`Two_Factor_Core::filter_session_information()` copies any `two-factor-` prefixed key
forward across session regeneration, so the flag is
sticky by construction and cannot be shed accidentally.
`update_current_user_session()` supports explicit null-clearing.

**Layer 4 · Completion.** On successful verification: clear the pending flag
explicitly, rotate the session token so the password-only token cannot be replayed,
and stamp `two-factor-login` / `two-factor-provider` on the new session so core's
revalidation grace computes correctly.

**Residual risk.** On the fallback path a password-only cookie exists between login
and verification. It is capability-stripped, REST-fenced, blocked from profile
mutation, and time-bounded — but it exists. Eliminating it requires server-side
provider enrollment without a session, which is #934. If #934 lands, layers 1–3
become deletable rather than needing rework.

## 8. The enrollment race

Any forced-enrollment-at-login design has a structural weakness: an attacker holding
the password can log in first and enroll **their own** authenticator, becoming the
legitimate second factor. This affects #813 as written, PR #239, and commercial
equivalents.

Two upstream behaviors make it worse than it first appears:

- `Two_Factor_Core::user_two_factor_options_update()` calls
  `wp_destroy_other_sessions()` on first-time enrollment
  (`Two_Factor_Core::user_two_factor_options_update()`), so the attacker who enrolls first also logs the
  legitimate user out everywhere.
- The plugin sends **no mail on enrollment**. `wp_mail` appears only in the
  compromised-password path (`Two_Factor_Core::notify_user_password_reset()`). Enrolling a second
  factor is silent.

**Mitigation on the required path: the race is closed.** Enrollment requires entering
a code delivered to the account's mailbox. A password-only attacker cannot complete it,
and there is no provider choice to steer toward a factor that avoids the mailbox.
This is the security argument for the Email-first design, and it is why the required
path offers no method chooser.

**Mitigation on the fallback path: detection only.** The race is open by construction
there. See §6.4's admin notice.

**Notification.** `class-enrollment-notify.php` mails the account address on every
provider enrollment, converting a silent takeover into a detectable one. Filterable
via `two_factor_enrollment_notify`, default true.

## 9. Lockout recovery

The required path's failure mode is a user whose mailbox does not receive. That user
cannot log in at all — the same property Require-Email-2FA already has, and the reason
§6.2 puts backup codes in front of every newly enrolled user.

Beyond that: logout is always reachable from any enrollment screen, and recovery is a
documented WP-CLI command plus a constant. Both are deliberately out-of-band, since
anything reachable in-browser is reachable by whoever holds the password.

## 10. Filter reference

| Filter | Default | Purpose |
|---|---|---|
| `two_factor_enrollment_required` | `false` | Does this user have to enroll |
| `two_factor_enrollment_deferrable` | `false` | May this user defer |
| `two_factor_enrollment_intercept_frontend` | `false` | Fallback path only |
| `two_factor_enrollment_idle_ttl` | `900` | Fallback path idle bound, seconds |
| `two_factor_enrollment_absolute_ttl` | `3600` | Fallback path absolute bound, seconds |
| `two_factor_enrollment_notify` | `true` | Send enrollment notification mail |

## 11. Testing

PHPUnit, mirroring the existing suite's conventions, `@group enrollment`.

**Predicates** — pure, tested directly: required/deferrable/complete across
filter combinations; `email_provider_available()` when Email is filtered out.

**Required path** — no auth cookie is issued before the challenge; Email resolves as
primary for a covered user; enrollment persists to user meta after
`two_factor_user_authenticated`; a non-covered user's login is untouched; deactivating
before first login leaves no user meta.

**Onboarding** — shown once; the flag survives; skipping does not re-trigger;
uninstall removes the flag.

**Fallback security** — each claim in §7 gets a named test rather than prose: a
capability-stripped session cannot publish; cannot save `profile.php`; REST outside
`two-factor/1.0` returns 403; Application Password requests are unaffected; idle TTL
expiry destroys the session; absolute TTL is enforced independently; completion rotates
the token and clears the flag.

**Regression** — the front end is not intercepted by default (PR #239's behavior).

**Notification** — mail sent on enrollment; suppressed when filtered off.

## 12. Upstream contributions

Findings from this design that stand on their own, independent of whether this plugin
ships:

1. **Enrollment is silent** (§8) — no mail on provider enrollment, while first-time
   enrollment destroys the user's other sessions. Worth its own issue.
2. **The enrollment race** (§8) — worth documenting on #813 and #846, since both
   inherit it and neither mentions it.
3. **The REST permission gate** — `rest_api_can_edit_user_and_update_two_factor_options`
   hard-returns on `current_user_can( 'edit_user' )` before any filter
  , which is the concrete reason pre-cookie
   provider configuration is impossible. This corroborates #934 independently.
4. **Email-first enrollment closes the race** — the design argument in §8, relevant
   to #813's wizard scope.
5. **PR #239 bug inventory** — the `super-admin` checkbox is offered by
   `global_force_2fa_by_role_field()` but discarded by `validate_forced_roles()`, which
   whitelists against `get_editable_roles()`; `readonly` on a checkbox is a no-op; the
   `parse_request` redirect bounces users off the front end. Relevant to #846, which
   names #239 as prior art.

## 13. Licensing

GPL-2.0-or-later, matching Two Factor. No code is copied from PR #239 — the flow
described here differs structurally — but #239 and Require-Email-2FA are credited as
prior art.
