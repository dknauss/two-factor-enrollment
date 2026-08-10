<?php
/**
 * Injects the Email provider so core performs enrollment during login.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Pre-cookie enrollment gate.
 *
 * Two_Factor_Core::filter_authenticate() suppresses the auth cookie for any
 * user who is using two-factor. By making Email the resolved provider for a
 * user who must enroll, enrollment happens inside core's existing challenge,
 * before any cookie is issued.
 */
class Two_Factor_Enrollment_Gate {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_filter( 'two_factor_enabled_providers_for_user', array( __CLASS__, 'filter_enabled_providers' ), 10, 2 );
	}

	/**
	 * Add the Email provider for users who must enroll.
	 *
	 * The injection is runtime-only. Nothing is written to user meta until the
	 * user has actually passed the challenge, so deactivating this plugin
	 * before a covered user has ever logged in leaves no trace.
	 *
	 * @param array $providers Enabled provider keys.
	 * @param int   $user_id   The user ID.
	 * @return array
	 */
	public static function filter_enabled_providers( $providers, $user_id ) {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}

		if ( in_array( Two_Factor_Enrollment::EMAIL_PROVIDER, $providers, true ) ) {
			return $providers;
		}

		if ( ! Two_Factor_Enrollment::email_provider_available() ) {
			return $providers;
		}

		if ( ! Two_Factor_Enrollment::is_required( $user_id ) ) {
			return $providers;
		}

		if ( self::has_configured_provider( $user_id, $providers ) ) {
			return $providers;
		}

		$providers[] = Two_Factor_Enrollment::EMAIL_PROVIDER;

		return $providers;
	}

	/**
	 * Whether the user already has a usable, configured provider.
	 *
	 * Two_Factor_Enrollment::is_complete() cannot be used here: it calls back
	 * into get_enabled_providers_for_user(), which would recurse through this
	 * same filter. This checks the supplied list directly instead.
	 *
	 * @param int   $user_id   The user ID.
	 * @param array $providers Enabled provider keys already resolved for the user.
	 * @return bool
	 */
	private static function has_configured_provider( $user_id, $providers ) {
		if ( empty( $providers ) ) {
			return false;
		}

		$user = Two_Factor_Enrollment::fetch_user( $user_id );

		if ( ! $user ) {
			return false;
		}

		$supported = Two_Factor_Core::get_providers();

		foreach ( $providers as $key ) {
			if ( isset( $supported[ $key ] ) && $supported[ $key ]->is_available_for_user( $user ) ) {
				return true;
			}
		}

		return false;
	}
}
