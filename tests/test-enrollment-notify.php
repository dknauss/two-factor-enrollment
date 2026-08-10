<?php
/**
 * Tests for enrollment notification mail.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Notify extends WP_UnitTestCase {

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
		remove_all_filters( 'two_factor_enrollment_notify' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_notify_sends_mail_to_account_address() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Notify::notify( $user, 'Email' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertNotFalse( $sent );
		$this->assertSame( 'editor@example.org', $sent->to[0][0] );
	}

	public function test_notify_mentions_the_provider_label() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Notify::notify( $user, 'Email' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertStringContainsString( 'Email', $sent->body );
	}

	public function test_notify_suppressed_by_filter() {
		add_filter( 'two_factor_enrollment_notify', '__return_false' );

		$user = get_user_by( 'id', $this->user_id );

		$this->assertFalse( Two_Factor_Enrollment_Notify::notify( $user, 'Email' ) );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertFalse( $mailer->get_sent() );
	}
}
