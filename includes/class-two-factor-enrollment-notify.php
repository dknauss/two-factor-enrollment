<?php
/**
 * Notifies users when a two-factor method is enrolled on their account.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Enrollment notification mail.
 *
 * Enrolling a second factor is a security-relevant account change that the
 * Two Factor plugin does not currently announce, while first-time enrollment
 * simultaneously destroys the account's other sessions. This converts a silent
 * change into a detectable one.
 *
 * This is a pure service class: it has no hooks of its own. Callers decide
 * exactly when a notification-worthy enrollment has occurred and call
 * notify() directly at that point.
 */
class Two_Factor_Enrollment_Notify {

	/**
	 * Mail the account address about a newly enrolled method.
	 *
	 * @param WP_User $user           The user to notify.
	 * @param string  $provider_label Human-readable provider name.
	 * @return bool Whether the mail was sent.
	 */
	public static function notify( $user, $provider_label ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		/**
		 * Whether to send enrollment notification mail.
		 *
		 * @param bool    $send Default true.
		 * @param WP_User $user The user being notified.
		 */
		if ( ! apply_filters( 'two_factor_enrollment_notify', true, $user ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Two-factor authentication was enabled on your %s account', 'two-factor-enrollment' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$message = sprintf(
			/* translators: 1: user login, 2: provider label, 3: site URL. */
			__(
				'Hi %1$s,

Two-factor authentication was enabled on your account using the "%2$s" method.

This also signed you out of any other active sessions.

If you did this, no action is needed. If you did not, your password may be compromised — reset it immediately and contact the site administrator.

Site: %3$s',
				'two-factor-enrollment'
			),
			$user->user_login,
			$provider_label,
			home_url( '/' )
		);

		return wp_mail( $user->user_email, $subject, $message ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Single transactional security email to the affected user.
	}
}
