<?php
/**
 * Tests for uninstall cleanup.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_removes_onboarding_flag_from_all_users() {
		$one = self::factory()->user->create();
		$two = self::factory()->user->create();

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $one );
		Two_Factor_Enrollment_Onboarding::mark_onboarded( $two );

		require_once dirname( __DIR__ ) . '/uninstall.php';

		two_factor_enrollment_uninstall();

		$this->assertSame( '', get_user_meta( $one, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $two, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
	}
}
