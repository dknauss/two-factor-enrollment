<?php
/**
 * Tests for the enrollment gate.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Gate extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor@example.org',
			)
		);
		reset_phpmailer_instance();
		Two_Factor_Enrollment_Gate::reset_injections();
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_enrollment_deferrable' );
		remove_all_filters( 'two_factor_providers' );
		remove_all_filters( 'two_factor_enrollment_notify' );
		remove_all_actions( 'two_factor_enrollment_enrolled' );
		remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		reset_phpmailer_instance();
		Two_Factor_Enrollment_Gate::reset_injections();
		parent::tear_down();
	}

	/**
	 * Put the user into the state persist_enrollment() requires: covered by
	 * policy, and actually injected by this plugin's gate in this request.
	 *
	 * persist_enrollment() writes nothing for a user this plugin did not
	 * itself challenge, so every test of its write behaviour has to establish
	 * that precondition the same way a real login does — by running the gate.
	 *
	 * @return void
	 */
	private function inject_via_gate() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		// Self-check: if the gate stopped injecting, the tests below would
		// pass for the wrong reason.
		$this->assertContains( 'Two_Factor_Email', Two_Factor_Core::get_enabled_providers_for_user( $this->user_id ) );
	}

	public function test_no_injection_when_not_required() {
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $this->user_id ) );
	}

	public function test_email_injected_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	public function test_no_injection_when_required_and_deferrable() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	public function test_user_not_using_two_factor_when_required_and_deferrable() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );

		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $this->user_id ) );
	}

	public function test_email_injected_when_required_and_not_deferrable() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		add_filter( 'two_factor_enrollment_deferrable', '__return_false' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	public function test_deferrable_alone_has_no_effect_when_not_required() {
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	public function test_persist_enrollment_unaffected_by_deferrable_filter() {
		$this->inject_via_gate();

		// A policy that flips to deferrable after the challenge was already
		// issued must not strand the user half-enrolled.
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
	}

	/**
	 * Whether Two_Factor_Core::filter_authenticate() has suppressed the auth
	 * cookie.
	 *
	 * has_filter() is no use here: the WordPress test suite registers its own
	 * `__return_false` on send_auth_cookies at priority 10 and has_filter()
	 * reports whichever it finds first. Core's suppression is specifically at
	 * PHP_INT_MAX, so look there.
	 *
	 * @return bool
	 */
	private function auth_cookies_suppressed() {
		global $wp_filter;

		if ( ! isset( $wp_filter['send_auth_cookies'][ PHP_INT_MAX ] ) ) {
			return false;
		}

		foreach ( $wp_filter['send_auth_cookies'][ PHP_INT_MAX ] as $callback ) {
			if ( '__return_false' === $callback['function'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The whole design rests on this: for a user this plugin injects Email
	 * for, Two_Factor_Core::filter_authenticate() must suppress the auth
	 * cookie, so no password-only session is ever created. Everything else in
	 * this file tests that property by proxy; this tests it directly.
	 */
	public function test_auth_cookie_is_withheld_for_an_injected_user() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$user = get_user_by( 'id', $this->user_id );

		// The premise: cookies are not already suppressed for some other
		// reason, so the assertion below is about this login.
		$this->assertFalse( $this->auth_cookies_suppressed() );

		$result = Two_Factor_Core::filter_authenticate( $user );

		$this->assertInstanceOf( 'WP_User', $result );
		$this->assertTrue( $this->auth_cookies_suppressed() );
	}

	/**
	 * The other half of the same property: with no policy covering the user,
	 * this plugin must not interfere with an ordinary password login.
	 */
	public function test_auth_cookie_is_not_withheld_for_an_uncovered_user() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Core::filter_authenticate( $user );

		$this->assertFalse( $this->auth_cookies_suppressed() );
	}

	/**
	 * The no-chooser property. If a covered user could pick a method other
	 * than Email, an attacker holding only the password could steer enrollment
	 * toward a factor that does not depend on the mailbox — which is the race
	 * this plugin exists to close.
	 */
	public function test_required_path_offers_no_provider_choice() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$user = get_user_by( 'id', $this->user_id );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			array_keys( Two_Factor_Core::get_available_providers_for_user( $user ) )
		);
	}

	/**
	 * Same property for a user carrying an unconfigured provider in their
	 * enabled-providers meta — someone who started setting up an authenticator
	 * app and never finished. The half-configured entry must not become a
	 * second option on the challenge screen.
	 */
	public function test_required_path_offers_no_choice_despite_an_unconfigured_totp_entry() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );

		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$user = get_user_by( 'id', $this->user_id );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			array_keys( Two_Factor_Core::get_available_providers_for_user( $user ) )
		);
	}

	public function test_email_becomes_primary_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$provider = Two_Factor_Core::get_primary_provider_for_user( $this->user_id );

		$this->assertInstanceOf( 'Two_Factor_Email', $provider );
	}

	public function test_user_is_considered_using_two_factor_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $this->user_id ) );
	}

	public function test_no_injection_when_already_complete() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
		update_user_meta( $this->user_id, '_two_factor_totp_key', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' );

		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	public function test_no_injection_when_email_provider_disabled() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	public function test_injection_does_not_write_user_meta() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	public function test_persist_enrollment_writes_user_meta() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
		$this->assertSame(
			'Two_Factor_Email',
			get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true )
		);
	}

	public function test_persist_enrollment_ignores_other_providers() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Totp::get_instance() );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
	}

	public function test_persist_enrollment_does_not_clobber_existing_providers() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Backup_Codes' ) );

		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$enabled = get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		$this->assertContains( 'Two_Factor_Backup_Codes', $enabled );
		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	public function test_persist_enrollment_is_idempotent() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );
		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
	}

	public function test_persist_enrollment_sends_notification_mail_on_new_enrollment() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertNotFalse( $sent );
		$this->assertSame( 'editor@example.org', $sent->to[0][0] );
	}

	public function test_persist_enrollment_marks_onboarding_pending_on_new_enrollment() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$marker = get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true );

		$this->assertStringStartsWith( 'pending:', $marker );
		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	/**
	 * The onboarding screen is a state-changing GET, so the pending marker has
	 * to carry the one-time token that authorizes it — and a fresh one per
	 * enrollment, never a constant.
	 */
	public function test_persist_enrollment_mints_a_one_time_onboarding_token() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$first = Two_Factor_Enrollment_Onboarding::pending_token( $this->user_id );

		$this->assertSame( 32, strlen( $first ) );

		$other_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		add_filter( 'two_factor_enrollment_required', '__return_true' );
		Two_Factor_Core::get_enabled_providers_for_user( $other_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( get_user_by( 'id', $other_id ), Two_Factor_Email::get_instance() );

		$this->assertNotSame( $first, Two_Factor_Enrollment_Onboarding::pending_token( $other_id ) );
	}

	public function test_persist_enrollment_does_not_reset_onboarding_after_it_is_done() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		// A second call must not re-append Email or resurrect the 'pending' marker.
		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			'done',
			get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true )
		);
	}

	public function test_persist_enrollment_does_not_resend_notification_mail() {
		$this->inject_via_gate();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		// Confirm the first call actually sent mail, so the second assertion is discriminating.
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotFalse( $mailer->get_sent() );

		reset_phpmailer_instance();

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertFalse( $mailer->get_sent() );
	}

	/**
	 * The regression guard for the whole "installing this plugin alone changes
	 * nothing" contract.
	 *
	 * `two_factor_user_authenticated` fires for every user who authenticates
	 * with the Email provider, on every site, whether or not this plugin was
	 * involved. With no policy filter registered, persist_enrollment() must be
	 * inert: no provider written, no primary provider set, no onboarding
	 * marker, no mail.
	 */
	public function test_persist_enrollment_writes_nothing_without_policy() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertFalse( $mailer->get_sent() );
	}

	/**
	 * The concrete case behind the guard above.
	 *
	 * Two_Factor_Core::get_available_providers_for_user() force-enables Email
	 * at runtime for a user whose enabled-providers meta holds only providers
	 * that no longer exist — every account that had FIDO U2F before Two Factor
	 * 0.9.0. That user authenticates with Email through no doing of this
	 * plugin, and must not be enrolled, mailed, or diverted to onboarding.
	 */
	public function test_persist_enrollment_ignores_cores_forced_email_fallback() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_FIDO_U2F' ) );

		$user = get_user_by( 'id', $this->user_id );

		// Confirm the premise: core really does force Email on for this user.
		$available = Two_Factor_Core::get_available_providers_for_user( $user );

		$this->assertArrayHasKey( 'Two_Factor_Email', $available );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_FIDO_U2F' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertFalse( $mailer->get_sent() );
	}

	/**
	 * The most natural way to write "require two-factor for users who don't
	 * have it" is to ask Two Factor. That re-enters this plugin's own
	 * two_factor_enabled_providers_for_user filter, and without a reentrancy
	 * guard it recurses until the login request exhausts memory.
	 *
	 * The call count is the discriminating half: reaching the assertions at
	 * all proves it terminated, and a count of exactly one proves the guard is
	 * what stopped it rather than some incidental short-circuit.
	 */
	public function test_policy_filter_may_consult_two_factor_state_without_recursing() {
		$calls = 0;

		add_filter(
			'two_factor_enrollment_required',
			function ( $required, $user ) use ( &$calls ) {
				++$calls;

				return ! Two_Factor_Core::is_user_using_two_factor( $user->ID );
			},
			10,
			2
		);

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertSame( 1, $calls );
		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	/**
	 * A policy callback that throws must not leave the guard latched, or every
	 * later login on that request would silently skip enrollment.
	 */
	public function test_reentrancy_guard_is_released_when_a_policy_filter_throws() {
		add_filter(
			'two_factor_enrollment_required',
			// phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement -- Throwing instead of returning is the whole point of this fixture.
			function () {
				throw new RuntimeException( 'policy exploded' );
			}
		);

		try {
			Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );
			$this->fail( 'Expected the policy filter to throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'policy exploded', $e->getMessage() );
		}

		remove_all_filters( 'two_factor_enrollment_required' );
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$this->assertContains( 'Two_Factor_Email', Two_Factor_Core::get_enabled_providers_for_user( $this->user_id ) );
	}

	/**
	 * The hook registration itself. Without this action wired up nothing ever
	 * makes an enrollment permanent, and deleting the add_action() line would
	 * otherwise leave the entire suite green.
	 */
	public function test_gate_hooks_are_registered() {
		$this->assertNotFalse(
			has_filter( 'two_factor_enabled_providers_for_user', array( 'Two_Factor_Enrollment_Gate', 'filter_enabled_providers' ) )
		);
		$this->assertNotFalse(
			has_action( 'two_factor_user_authenticated', array( 'Two_Factor_Enrollment_Gate', 'persist_enrollment' ) )
		);
	}
}
