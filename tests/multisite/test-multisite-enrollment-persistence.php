<?php
/**
 * Multisite tests: enrollment state is visible network-wide.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 * @group multisite
 */

/**
 * The plugin's README claim, and the reason this repository exists on a
 * multisite install at all, is that enrollment is a network-wide fact: it
 * lives entirely in user meta (ENABLED_PROVIDERS_USER_META_KEY and
 * PROVIDER_USER_META_KEY, both read by Two_Factor_Core, plus this plugin's
 * own onboarding marker), and user meta has no per-site table prefix on
 * WordPress multisite. A user enrolled while authenticating against one site
 * must read back as enrolled from every other site on the network, including
 * one they have never logged into.
 *
 * Every assertion below is taken from behind a switch_to_blog() boundary the
 * enrollment did not happen behind, which is the one thing that makes this
 * suite different from tests/test-enrollment.php: on single site there is no
 * second site to read the state back from, so the identical assertions there
 * only prove the meta was written, never that it survives a change of "which
 * site is this".
 */
class Tests_Two_Factor_Enrollment_Multisite_Persistence extends WP_UnitTestCase {

	/**
	 * A second site, distinct from the main site.
	 *
	 * @var int
	 */
	private static $other_site_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$other_site_id = $factory->blog->create();
	}

	public static function wpTearDownAfterClass() {
		wp_delete_site( self::$other_site_id );
	}

	/**
	 * Enroll a user the way persist_enrollment() would, on whichever site is
	 * current when this is called.
	 *
	 * @param int $user_id The user to enroll.
	 * @return void
	 */
	private function enroll( $user_id ) {
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
	}

	/**
	 * Enrolled on the main site (no switch_to_blog() at all -- the ordinary
	 * case every other test file already covers), read back as enrolled from
	 * a second site the user was never even added to.
	 */
	public function test_enrollment_on_main_site_visible_from_other_site() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->enroll( $user_id );

		switch_to_blog( self::$other_site_id );

		$is_using_two_factor = Two_Factor_Core::is_user_using_two_factor( $user_id );
		$is_complete         = Two_Factor_Enrollment::is_complete( $user_id );

		restore_current_blog();

		$this->assertTrue( $is_using_two_factor );
		$this->assertTrue( $is_complete );
	}

	/**
	 * The reverse direction, and the more realistic one for a subsite-only
	 * user: enrolled while the subsite is current (as would happen during an
	 * actual login to that subsite), read back from the main site's context.
	 */
	public function test_enrollment_on_subsite_visible_from_main_site() {
		switch_to_blog( self::$other_site_id );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->enroll( $user_id );
		restore_current_blog();

		// Current blog is the main site again; deliberately no further switch.
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
		$this->assertTrue( Two_Factor_Enrollment::is_complete( $user_id ) );
	}

	/**
	 * A user who is not enrolled must read back as not enrolled from another
	 * site too. Without this, a bug that made is_user_using_two_factor()
	 * always return true after any switch_to_blog() would pass every
	 * assertion above.
	 */
	public function test_unenrolled_user_reads_as_unenrolled_from_other_site() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		switch_to_blog( self::$other_site_id );

		$is_using_two_factor = Two_Factor_Core::is_user_using_two_factor( $user_id );
		$is_complete         = Two_Factor_Enrollment::is_complete( $user_id );

		restore_current_blog();

		$this->assertFalse( $is_using_two_factor );
		$this->assertFalse( $is_complete );
	}
}
