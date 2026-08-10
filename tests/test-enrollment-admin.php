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

	public function test_notice_silent_when_admin_and_email_available() {
		wp_set_current_user( $this->admin_id );

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
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
}
