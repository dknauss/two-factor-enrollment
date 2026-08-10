# Two-Factor Enrollment

An add-on for the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin
that enrolls policy-covered users in two-factor authentication **before an auth
cookie is issued**.

Implements the onboarding flow described in
[WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813).

## What it does

When a covered user logs in without a second factor configured, this plugin adds
the Email provider to their account at runtime. Two Factor's existing login
challenge then emails them a one-time code and withholds the auth cookie until
they enter it. Confirming the code proves mailbox control and completes
enrollment in a single step.

Enrollment is only made permanent — written to the user's enabled providers —
once they actually pass the emailed challenge. If a covered user never logs in
after this plugin is installed, or if it's deactivated first, nothing is written
and no trace is left. Once enrollment does complete, an account-change
notification is emailed (see `two_factor_enrollment_notify` below), and —
still within that same login, right after they enter the emailed code and
before they ever reach the dashboard — they're redirected to a one-time
onboarding screen, where backup codes are generated and enabled for them and
they're offered a link to set up an authenticator app.

A user who is both required and marked deferrable is skipped entirely on that
login — Email is not injected, and they proceed normally. This plugin stores
no counters or deadlines for deferral; it only asks the policy layer's filter
and honors the answer each time.

### Onboarding is scoped to users this plugin enrolled

The onboarding screen only ever appears for a user this plugin itself enrolled.
Internally this is tracked with a tri-state user meta value
(`_two_factor_enrollment_onboarded`: absent, `pending`, or `done`) rather than a
boolean, specifically so that "never enrolled by this plugin" and "already saw
the screen" are both distinguishable from "owed the screen." A user who
configured TOTP (or any other provider) before this plugin was ever installed
is never diverted to a screen they never asked for.

### The onboarding screen enables backup codes, not just displays them

If the user has no backup codes yet, the onboarding screen generates a set and
appends `Two_Factor_Backup_Codes` to their enabled providers, so the codes it
shows are immediately usable as a real login method — not a preview. They can
be downloaded as a text file from the screen. Their primary provider stays
Email. If an administrator has disabled the Backup Codes provider site-wide,
this section is skipped.

That particular set of codes is shown once and not again, but this is not the
user's last chance at recovery codes: anyone who is signed in can generate a
fresh set from `profile.php` whenever they like.

### The onboarding screen is authorized by a one-time token

Rendering the screen changes state — it generates backup codes, enables the
provider, and consumes itself — so the link carries a one-time token, minted
with the pending marker and destroyed when the screen completes. A request
without the matching token is passed straight through to the user's
destination, generating nothing and consuming nothing. (A WordPress nonce
cannot do this job: `wp_create_nonce()` folds the session token into its hash,
and `wp_set_auth_cookie()` does not populate `$_COOKIE` within the request that
mints the link, so such a nonce would fail verification every time.)

## Security

The property this design rests on is where the auth cookie sits in the login
sequence: `Two_Factor_Core` validates the emailed code first and only calls
`wp_set_auth_cookie()` after that succeeds — never on the strength of the
password alone. (The permanent record of enrollment — appending Email to the
user's enabled providers — is written a moment later in that same request,
once the code has already been proven; it plays no part in gating the
cookie.)

- No password-only session is ever created for a covered user. There is
  nothing to lock down between "password verified" and "second factor set up,"
  because that window doesn't exist — the auth cookie is never issued until
  the emailed code is entered and validated.
- This closes the **enrollment race**: an attacker who has obtained only a
  user's password cannot pass that validation, because they cannot receive the
  emailed one-time code. Passwords alone stop being sufficient to take over an
  account, including one that had no second factor configured yet.
- The required path deliberately offers **no provider chooser**. If a user
  could pick a method other than Email, an attacker could try to steer
  enrollment toward a factor that doesn't depend on the mailbox. Removing the
  choice is what makes the mailbox requirement actually load-bearing.

## What it does not do

It owns **no enforcement policy**. It has no settings screen and no role
targeting. Both contract filters (`two_factor_enrollment_required` and
`two_factor_enrollment_deferrable`) default to `false`, so installing it alone
changes nothing. Something else decides who must enroll — for example a
companion policy plugin, or a few lines in a theme or mu-plugin.

## Usage

```php
// Require enrollment for everyone who can publish.
add_filter(
	'two_factor_enrollment_required',
	function ( $required, $user ) {
		return user_can( $user, 'edit_posts' );
	},
	10,
	2
);
```

```php
// Let users who are within their first 14 days defer enrollment.
add_filter(
	'two_factor_enrollment_deferrable',
	function ( $deferrable, $user ) {
		return ( time() - strtotime( $user->user_registered ) ) < 14 * DAY_IN_SECONDS;
	},
	10,
	2
);
```

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `two_factor_enrollment_required` | `false` | Does this user have to enroll |
| `two_factor_enrollment_deferrable` | `false` | May this user skip enrollment on this login |
| `two_factor_enrollment_notify` | `true` | Send the enrollment notification email |

### Your policy callback may call back into Two Factor

`two_factor_enrollment_required` and `two_factor_enrollment_deferrable` both run
from inside this plugin's `two_factor_enabled_providers_for_user` filter, so the
obvious way to write "require two-factor for users who don't have it" —
`! Two_Factor_Core::is_user_using_two_factor( $user )` — re-enters that same
filter. That is supported: the injection is guarded against reentrancy, and the
inner call returns the user's **stored** providers, unmodified by any injection
this plugin is about to make. In other words your callback sees the honest
answer to "does this user already have a second factor?", not a view of the
world this plugin has already edited.


## Requirements

WordPress 6.5+, PHP 7.2+, and the Two Factor plugin.

## Limitation

If an administrator disables the Email provider site-wide, this plugin stands
down and shows an admin notice. Enrolling users into a non-Email provider
requires configuration after login, which needs a session — see
[WordPress/two-factor#934](https://github.com/WordPress/two-factor/issues/934).

## Uninstalling

The only state this plugin persists of its own is the single
`_two_factor_enrollment_onboarded` user meta key. Uninstalling removes that key
from every user who has it. It deliberately does **not** touch the Two Factor
plugin's own data — enabled providers, backup codes, and so on — because that
data belongs to the dependency and may still be in active use.

## License

GPL-2.0-or-later.
