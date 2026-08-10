<?php
/**
 * One-time post-login onboarding screen.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Offers backup codes and the TOTP upgrade after a user's first two-factored login.
 *
 * This screen is convenience, not a security boundary: any session reaching it
 * has already passed a real two-factor challenge.
 */
class Two_Factor_Enrollment_Onboarding {

	/**
	 * User meta key tracking onboarding-screen state.
	 *
	 * This is a tri-state flag, not a boolean, and is the only state this
	 * plugin persists:
	 *
	 * - absent    This plugin never enrolled the user. Never show the screen.
	 *             This covers users who configured two-factor (e.g. TOTP)
	 *             before this plugin was ever installed; they must not be
	 *             diverted to a screen they never asked for.
	 * - 'pending' This plugin enrolled the user via the gate. The screen is
	 *             owed and has not yet been shown.
	 * - 'done'    The screen has been shown.
	 *
	 * Comparisons against this meta value must use strict string identity
	 * ('pending' === $value), not truthiness — both the absent ('') and
	 * 'done' states must read as "do not show the screen".
	 *
	 * @var string
	 */
	const ONBOARDED_META_KEY = '_two_factor_enrollment_onboarded';

	/**
	 * Meta value recorded once the plugin has enrolled a user and the screen
	 * is owed.
	 *
	 * @var string
	 */
	const STATE_PENDING = 'pending';

	/**
	 * Meta value recorded once the screen has been shown.
	 *
	 * @var string
	 */
	const STATE_DONE = 'done';

	/**
	 * The wp-login.php action slug for this screen.
	 *
	 * @var string
	 */
	const ACTION = 'two_factor_enrollment_onboarding';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 20, 3 );
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'render' ) );
	}

	/**
	 * Whether this user should see the onboarding screen.
	 *
	 * True only when this plugin is the one that enrolled the user, i.e. the
	 * onboarding marker is exactly 'pending'. A user whose two-factor setup
	 * predates this plugin (marker absent) or who has already seen the
	 * screen (marker 'done') is never diverted here.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function needs_onboarding( $user = null ) {
		$user = Two_Factor_Enrollment::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		if ( ! Two_Factor_Enrollment::is_complete( $user ) ) {
			return false;
		}

		$state = get_user_meta( $user->ID, self::ONBOARDED_META_KEY, true );

		return self::STATE_PENDING === $state;
	}

	/**
	 * Record that the screen has been shown.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 */
	public static function mark_onboarded( $user_id ) {
		update_user_meta( (int) $user_id, self::ONBOARDED_META_KEY, self::STATE_DONE );
	}

	/**
	 * Build the onboarding screen URL.
	 *
	 * Two_Factor_Core::login_url() already runs urlencode_deep() over every
	 * param value before building the query string, so $redirect_to is
	 * passed through raw here — encoding it first would double-encode it.
	 *
	 * @param string $redirect_to Where to send the user afterwards.
	 * @return string
	 */
	public static function url( $redirect_to ) {
		return Two_Factor_Core::login_url(
			array(
				'action'      => self::ACTION,
				'redirect_to' => $redirect_to,
			)
		);
	}

	/**
	 * Divert a fresh login to the onboarding screen.
	 *
	 * @param string           $redirect_to           The redirect destination.
	 * @param string           $requested_redirect_to The requested destination.
	 * @param WP_User|WP_Error $user                  User on success, WP_Error on failure.
	 * @return string
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( ! self::needs_onboarding( $user ) ) {
			return $redirect_to;
		}

		return self::url( $redirect_to );
	}

	/**
	 * Render the onboarding screen.
	 *
	 * Implemented in Task 7.
	 *
	 * @return void
	 */
	public static function render() {
	}
}
