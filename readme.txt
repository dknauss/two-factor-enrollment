=== Two-Factor Enrollment ===
Contributors: dknauss
Tags: two-factor, 2fa, mfa, authentication, security
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.2
Requires Plugins: two-factor
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enrolls users in two-factor authentication during login, before a session exists. Adds no policy of its own — you decide who it covers.

== Description ==

An add-on for the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin. When a user you have chosen to cover signs in without a second factor, this enrolls them then and there: WordPress emails them a one-time code, withholds the login cookie until they enter it, and records the enrollment once they do.

Entering that code proves they control the mailbox, so proving the factor and setting it up are the same step. There is no separate setup wizard to complete later, and no window in which they are signed in without a second factor.

Immediately afterwards they see a one-time screen with ten backup codes, which are enabled as a working recovery method rather than merely displayed, plus an offer to switch to an authenticator app.

= It has no opinion about who should enroll =

This plugin ships no settings screen and no policy. On its own it does nothing at all. You decide who it covers with a filter — everyone, administrators only, anyone who can publish, whatever suits the site:

`add_filter( 'two_factor_enrollment_required', function ( $required, $user ) {
	return user_can( $user, 'edit_posts' );
}, 10, 2 );`

A second filter, `two_factor_enrollment_deferrable`, lets you stage a rollout: cover everyone from the start while allowing most people to defer until a date you choose. Someone deferring signs in completely normally and notices nothing. The plugin stores no deferral state, so that condition can be a date, a login count, or anything else you like.

= Why it works this way =

Enrollment at first sign-in has a weakness worth understanding. If the user picks their own method, whoever arrives first holding the password owns the second factor — an attacker enrolls their own authenticator app and never needs the real owner's mailbox.

Offering exactly one factor closes that. An emailed code cannot be enrolled by someone who cannot read the email, and because there is no choice of method, there is no way around that requirement. Stronger factors remain available afterwards as an upgrade the real owner chooses.

= Before you switch it on =

The second factor is email, so email has to work. If transactional mail is broken on your site, covered users will not be able to sign in. Confirm delivery to a real mailbox first, and keep a signed-in administrator session open while you roll out.

Do not run this alongside Require Email 2FA. Both enable the Email provider, and that plugin gets there first, so this one stands down and you get none of its behaviour.

== Installation ==

1. Install and activate the Two Factor plugin. This plugin will not activate without it.
2. Install and activate Two-Factor Enrollment.
3. Add a `two_factor_enrollment_required` filter deciding who must enroll. A must-use plugin is the right home for it, since must-use plugins cannot be switched off from the admin.

Until step 3, the plugin is inert and tells you so with an admin notice.

== Frequently Asked Questions ==

= Nothing happens after I activate it. =

That is expected. The plugin covers nobody until you write a policy filter. See Installation, or the admin notice.

= Which users does it affect? =

Only those your filter returns true for, who do not already have a second factor configured, and who are not deferring. Everyone else signs in exactly as before.

= Does it lock people out of the REST API or XML-RPC? =

No. Those are governed by the Two Factor plugin's own API-login setting. Application passwords continue to work.

= What if a user loses access to their email? =

That is what the backup codes are for, which is why they are shown and enabled at enrollment. They can also generate a fresh set from their profile at any time while signed in.

= Does it work on multisite? =

Yes, and the network behaviour is covered by its own test suite: enrollment recorded on one site is recognised on every site, and the one-time screen is shown once per user, not once per site.

= Can I see when someone enrolls? =

Hook `two_factor_enrollment_enrolled`, which fires with the user and the provider once enrollment is recorded.

== Screenshots ==

1. The one-time screen shown immediately after a user's first two-factor sign-in, with their backup codes ready to download or copy.

== Changelog ==

= 0.1.0 =
* First release.
