<?php
/**
 * Enrollment predicates.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Decides whether a user must enroll in two-factor authentication.
 *
 * This class is intentionally free of side effects and output so that the
 * security-relevant decisions are reviewable in one place.
 */
class Two_Factor_Enrollment {

	/**
	 * Provider key used as the enrollment floor.
	 *
	 * @var string
	 */
	const EMAIL_PROVIDER = 'Two_Factor_Email';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-gate.php';

		Two_Factor_Enrollment_Gate::add_hooks();
	}

	/**
	 * Resolve a user reference to a WP_User.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return WP_User|false
	 */
	public static function fetch_user( $user = null ) {
		return Two_Factor_Core::fetch_user( $user );
	}

	/**
	 * Whether this user must enroll in two-factor authentication.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_required( $user = null ) {
		$user = self::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		/**
		 * Whether this user must enroll in two-factor authentication.
		 *
		 * @param bool    $required Default false.
		 * @param WP_User $user     The user being evaluated.
		 */
		return (bool) apply_filters( 'two_factor_enrollment_required', false, $user );
	}

	/**
	 * Whether this user may defer enrollment.
	 *
	 * The policy layer owns all counting, deadlines, and state. This plugin
	 * stores nothing and only honors the answer.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_deferrable( $user = null ) {
		$user = self::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		/**
		 * Whether this user may defer enrollment.
		 *
		 * @param bool    $allowed Default false.
		 * @param WP_User $user    The user being evaluated.
		 */
		return (bool) apply_filters( 'two_factor_enrollment_deferrable', false, $user );
	}

	/**
	 * Whether this user has already completed enrollment.
	 *
	 * Delegates to Two Factor rather than tracking parallel state.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_complete( $user = null ) {
		$user = self::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		return (bool) Two_Factor_Core::is_user_using_two_factor( $user );
	}

	/**
	 * Whether the Email provider is registered site-wide.
	 *
	 * Administrators can disable providers, in which case there is no
	 * mailbox-bound factor to enroll into and this plugin stands down.
	 *
	 * @return bool
	 */
	public static function email_provider_available() {
		$providers = Two_Factor_Core::get_providers();

		return isset( $providers[ self::EMAIL_PROVIDER ] );
	}
}
