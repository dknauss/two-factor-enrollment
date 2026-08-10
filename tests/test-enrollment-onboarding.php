<?php
/**
 * Tests for the onboarding screen routing.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Onboarding extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		parent::tear_down();
	}

	public function test_needs_onboarding_false_without_two_factor() {
		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_needs_onboarding_true_when_enrolled_and_pending() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	/**
	 * The regression this override exists to prevent: a user with two-factor
	 * fully configured but no onboarding marker at all (e.g. TOTP set up
	 * before this plugin was ever installed) must not be routed to the
	 * onboarding screen. Only users this plugin itself enrolled — marked
	 * 'pending' by persist_enrollment() — are shown the screen.
	 */
	public function test_needs_onboarding_false_when_configured_but_never_marked_pending() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
		update_user_meta( $this->user_id, '_two_factor_totp_key', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );

		$user   = get_user_by( 'id', $this->user_id );
		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertSame( admin_url(), $result );
	}

	public function test_needs_onboarding_false_once_flagged_done() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_mark_onboarded_moves_pending_to_done() {
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertSame( 'done', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
	}

	public function test_url_contains_the_custom_action() {
		$url = Two_Factor_Enrollment_Onboarding::url( admin_url() );

		$this->assertStringContainsString( 'action=two_factor_enrollment_onboarding', $url );
		$this->assertStringContainsString( 'wp-login.php', $url );
	}

	public function test_url_preserves_redirect_to() {
		$url = Two_Factor_Enrollment_Onboarding::url( 'https://example.org/wp-admin/edit.php' );

		$this->assertStringContainsString( rawurlencode( 'https://example.org/wp-admin/edit.php' ), $url );
	}

	public function test_login_redirect_diverts_when_onboarding_needed() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		$user = get_user_by( 'id', $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertStringContainsString( 'action=two_factor_enrollment_onboarding', $result );
	}

	public function test_login_redirect_untouched_when_not_needed() {
		$user = get_user_by( 'id', $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertSame( admin_url(), $result );
	}

	public function test_login_redirect_untouched_on_login_error() {
		$error = new WP_Error( 'invalid_username', 'nope' );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $error );

		$this->assertSame( admin_url(), $result );
	}

	public function test_login_form_action_is_registered() {
		$this->assertNotFalse( has_action( 'login_form_two_factor_enrollment_onboarding' ) );
	}
}
