<?php
/**
 * Tests for the admin notice.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Admin extends WP_UnitTestCase {

	private $admin_id;
	private $subscriber_id;

	public function set_up() {
		parent::set_up();
		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_providers' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_no_warning_when_email_available() {
		$this->assertFalse( Two_Factor_Enrollment_Admin::should_warn() );
	}

	public function test_warns_when_email_disabled() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$this->assertTrue( Two_Factor_Enrollment_Admin::should_warn() );
	}

	public function test_notice_output_mentions_email() {
		wp_set_current_user( $this->admin_id );

		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Email', $html );
	}

	public function test_notice_silent_when_email_available() {
		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * A fully configured install — Email available and a policy registered
	 * — must produce no notice at all. This is the state the two warnings
	 * exist to steer admins toward.
	 */
	public function test_notice_silent_when_fully_configured() {
		wp_set_current_user( $this->admin_id );

		add_filter( 'two_factor_enrollment_required', '__return_false' );

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );

		remove_filter( 'two_factor_enrollment_required', '__return_false' );
	}

	public function test_notice_silent_when_email_disabled_but_non_admin() {
		wp_set_current_user( $this->subscriber_id );

		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * The detection must key off whether a policy callback is *registered*,
	 * via has_filter(), never off what it returns for any given user. A
	 * policy that legitimately covers nobody right now — mid-rollout, or
	 * scoped to a role with no current members — is correctly configured,
	 * and warning about it would be noise that trains admins to ignore the
	 * notice. This is the case that must not warn.
	 */
	public function test_no_policy_configured_false_when_filter_registered_even_if_it_returns_false_for_everyone() {
		add_filter( 'two_factor_enrollment_required', '__return_false' );

		$this->assertFalse( Two_Factor_Enrollment_Admin::no_policy_configured() );

		remove_filter( 'two_factor_enrollment_required', '__return_false' );
	}

	public function test_no_policy_configured_true_when_no_filter_registered() {
		$this->assertTrue( Two_Factor_Enrollment_Admin::no_policy_configured() );
	}

	public function test_notice_silent_when_policy_registered() {
		wp_set_current_user( $this->admin_id );

		add_filter( 'two_factor_enrollment_required', '__return_false' );

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );

		remove_filter( 'two_factor_enrollment_required', '__return_false' );
	}

	public function test_notice_shown_when_no_policy_registered() {
		wp_set_current_user( $this->admin_id );

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'not enrolling anyone', $html );
	}

	public function test_no_policy_notice_silent_for_non_admin() {
		wp_set_current_user( $this->subscriber_id );

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * Two-notice interaction: when both conditions hold, only the
	 * Email-unavailable notice is shown. Email is the more fundamental
	 * blocker — the gate checks it before ever consulting a policy, so a
	 * freshly written filter would still enroll no one until Email is
	 * re-enabled — and a second stacked notice restating "nothing is
	 * happening" for a different reason would only be noise on top of that.
	 */
	public function test_notice_shows_only_email_unavailable_when_both_conditions_hold() {
		wp_set_current_user( $this->admin_id );

		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$this->assertTrue( Two_Factor_Enrollment_Admin::should_warn() );
		$this->assertTrue( Two_Factor_Enrollment_Admin::no_policy_configured() );

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'not enforcing anything', $html );
		$this->assertStringNotContainsString( 'not enrolling anyone', $html );
	}
}
