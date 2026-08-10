<?php
/**
 * Tests for uninstall cleanup.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Uninstall extends WP_UnitTestCase {

	/**
	 * Load and run the uninstall routine.
	 *
	 * @return void
	 */
	private function run_uninstall() {
		require_once dirname( __DIR__ ) . '/uninstall.php';

		two_factor_enrollment_uninstall();
	}

	/**
	 * The claim the README makes and nothing previously checked: uninstalling
	 * removes this plugin's one meta key and nothing else. A user this plugin
	 * enrolled must still have a working second factor and usable recovery
	 * codes afterwards — that data belongs to Two Factor, not to this plugin,
	 * and destroying it would lock people out.
	 */
	public function test_uninstall_leaves_two_factor_data_intact() {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ) );
		update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$user = get_user_by( 'id', $user_id );

		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );

		$codes_before = get_user_meta( $user_id, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY, true );

		$this->assertNotEmpty( $codes_before );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $user_id );

		$this->run_uninstall();

		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );

		$this->assertSame(
			array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ),
			get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
		$this->assertSame( 'Two_Factor_Email', get_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
		$this->assertSame( $codes_before, get_user_meta( $user_id, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY, true ) );

		// Still a usable second factor, not just surviving rows.
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

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
