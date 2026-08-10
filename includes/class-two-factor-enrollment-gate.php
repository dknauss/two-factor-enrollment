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
	 * User IDs this plugin injected the Email provider for during this request.
	 *
	 * Keys are user IDs, values are always true. This is the policy gate for
	 * persist_enrollment(): the `two_factor_user_authenticated` action fires
	 * for *every* user who authenticates with the Email provider, including
	 * users this plugin had nothing to do with. The most consequential case is
	 * Two_Factor_Core::get_available_providers_for_user(), which force-enables
	 * Email at runtime for any user whose enabled-providers meta contains only
	 * providers that no longer exist (anyone who had FIDO U2F before Two Factor
	 * 0.9.0). Without this record, such a user would be permanently enrolled,
	 * emailed, and diverted to onboarding on a site where no policy filter is
	 * registered at all — violating the rule that installing this plugin alone
	 * changes nothing.
	 *
	 * Recording the injection is exact where re-evaluating the policy filters
	 * would only be approximate: it is true precisely for the enrollments this
	 * plugin caused. filter_enabled_providers() always runs before the action
	 * within the same request, because Two_Factor_Core::validate_login_form_2fa()
	 * resolves the provider (via get_enabled_providers_for_user()) before it
	 * fires `two_factor_user_authenticated`.
	 *
	 * @var array
	 */
	private static $injected = array();

	/**
	 * Whether filter_enabled_providers() is already on the call stack.
	 *
	 * The policy filters this gate consults are third-party code, and the most
	 * natural way to write "require two-factor for users who don't have it" is
	 * to call Two_Factor_Core::is_user_using_two_factor() or
	 * get_enabled_providers_for_user() from the callback. Both re-enter
	 * `two_factor_enabled_providers_for_user`, and therefore this method,
	 * without bound — exhausting memory on a login request.
	 *
	 * A policy callback is entitled to ask Two Factor what it knows. Guarding
	 * here means the inner call sees the user's stored providers, unmodified
	 * by any injection this plugin might be about to make, which is the honest
	 * answer to "does this user already have a second factor?"
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_filter( 'two_factor_enabled_providers_for_user', array( __CLASS__, 'filter_enabled_providers' ), 10, 2 );
		add_action( 'two_factor_user_authenticated', array( __CLASS__, 'persist_enrollment' ), 10, 2 );
	}

	/**
	 * Add the Email provider for users who must enroll.
	 *
	 * The injection is runtime-only. Nothing is written to user meta until the
	 * user has actually passed the challenge, so deactivating this plugin
	 * before a covered user has ever logged in leaves no trace.
	 *
	 * A user who is required but also deferrable is treated as out of scope
	 * for this login: Email is not injected, and they log in normally. The
	 * policy layer owns all counting, deadlines, and deferral state; this
	 * plugin persists nothing about it and only honors the answer.
	 *
	 * Reentrant calls — a policy callback that consults Two Factor's own state
	 * — return the providers untouched. See self::$running.
	 *
	 * @param array $providers Enabled provider keys.
	 * @param int   $user_id   The user ID.
	 * @return array
	 */
	public static function filter_enabled_providers( $providers, $user_id ) {
		if ( self::$running ) {
			return $providers;
		}

		self::$running = true;

		try {
			return self::resolve_enabled_providers( $providers, $user_id );
		} finally {
			// finally, not a trailing assignment: a policy callback that
			// throws must not leave the gate permanently disarmed.
			self::$running = false;
		}
	}

	/**
	 * The body of filter_enabled_providers(), run with the reentrancy guard
	 * held.
	 *
	 * @param array $providers Enabled provider keys.
	 * @param int   $user_id   The user ID.
	 * @return array
	 */
	private static function resolve_enabled_providers( $providers, $user_id ) {
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

		if ( Two_Factor_Enrollment::is_deferrable( $user_id ) ) {
			return $providers;
		}

		if ( self::has_configured_provider( $user_id, $providers ) ) {
			return $providers;
		}

		$providers[] = Two_Factor_Enrollment::EMAIL_PROVIDER;

		self::$injected[ (int) $user_id ] = true;

		return $providers;
	}

	/**
	 * Forget which users the Email provider was injected for.
	 *
	 * The record is per-request state and needs no clearing in production,
	 * where the process ends with the request. It is exposed because the test
	 * suite simulates many requests inside a single PHP process.
	 *
	 * @return void
	 */
	public static function reset_injections() {
		self::$injected = array();
	}

	/**
	 * Write the enrollment to user meta after a successful challenge.
	 *
	 * Until this runs, the Email provider is only injected at runtime. Once the
	 * user has actually received and entered an emailed code, mailbox control
	 * is proven and the enrollment is made real and visible in their profile.
	 *
	 * Nothing happens at all unless this plugin is the reason the user was
	 * challenged with Email — see self::$injected. Every write below, the
	 * primary-provider write included, is behind that gate.
	 *
	 * A notification is sent, and the onboarding screen is marked "owed"
	 * (Two_Factor_Enrollment_Onboarding::STATE_PENDING), only on the branch
	 * that actually appends Email to the enabled providers list, i.e. only
	 * for a genuinely new enrollment. This runs once per account and is not
	 * repeated on subsequent logins, and is not sent merely because a
	 * primary provider was set.
	 *
	 * @param WP_User             $user     The authenticated user.
	 * @param Two_Factor_Provider $provider The provider used to authenticate.
	 * @return void
	 */
	public static function persist_enrollment( $user, $provider ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( ! is_object( $provider ) || Two_Factor_Enrollment::EMAIL_PROVIDER !== $provider->get_key() ) {
			return;
		}

		if ( empty( self::$injected[ $user->ID ] ) ) {
			return;
		}

		/*
		 * The enabled-providers list is read from and written to user meta by
		 * hand here, deliberately. Two_Factor_Core::enable_provider_for_user()
		 * reads the current list via get_enabled_providers_for_user(), which
		 * fires this plugin's own two_factor_enabled_providers_for_user
		 * filter — so using the core API would persist the runtime-injected
		 * Email entry and destroy the invariant that the injection leaves no
		 * trace until the challenge is actually passed. Do not "simplify"
		 * this to the core API.
		 */
		$enabled = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		if ( ! is_array( $enabled ) ) {
			$enabled = array();
		}

		if ( ! in_array( Two_Factor_Enrollment::EMAIL_PROVIDER, $enabled, true ) ) {
			$enabled[] = Two_Factor_Enrollment::EMAIL_PROVIDER;
			update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array_values( $enabled ) );

			/*
			 * The marker carries a one-time token. The onboarding screen is a
			 * state-changing GET that generates the user's only backup codes,
			 * and this token is what authorizes it. See
			 * Two_Factor_Enrollment_Onboarding::render().
			 */
			update_user_meta( $user->ID, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, Two_Factor_Enrollment_Onboarding::new_pending_marker() );

			/*
			 * Best effort: the return value is deliberately discarded. A
			 * bounced or misconfigured mailer must not fail an enrollment the
			 * user has already completed.
			 *
			 * The mail does not claim to have ended the account's other
			 * sessions, because nothing here does. Ending them at this point
			 * is not possible either: $_COOKIE has not been updated with the
			 * token wp_set_auth_cookie() just issued, so
			 * wp_destroy_other_sessions() would no-op and destroy_all() would
			 * kill the session this very request created.
			 */
			Two_Factor_Enrollment_Notify::notify( $user, $provider->get_label() );
		}

		$primary = get_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, true );

		if ( empty( $primary ) ) {
			update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, Two_Factor_Enrollment::EMAIL_PROVIDER );
		}
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
