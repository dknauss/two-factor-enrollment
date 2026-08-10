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
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_providers' );
		remove_all_filters( 'two_factor_enrollment_notify' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_no_injection_when_not_required() {
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $this->user_id ) );
	}

	public function test_email_injected_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertContains( 'Two_Factor_Email', $enabled );
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
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Totp::get_instance() );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
	}

	public function test_persist_enrollment_does_not_clobber_existing_providers() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Backup_Codes' ) );

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$enabled = get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		$this->assertContains( 'Two_Factor_Backup_Codes', $enabled );
		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	public function test_persist_enrollment_is_idempotent() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );
		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
	}

	public function test_persist_enrollment_sends_notification_mail_on_new_enrollment() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertNotFalse( $sent );
		$this->assertSame( 'editor@example.org', $sent->to[0][0] );
	}

	public function test_persist_enrollment_does_not_resend_notification_mail() {
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
}
