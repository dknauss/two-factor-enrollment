<?php
/**
 * Multisite tests: the onboarding marker is a network-global fact.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 * @group multisite
 */

/**
 * Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY is ordinary user meta,
 * with no per-site scoping of its own -- needs_onboarding() and
 * filter_login_redirect() read it exactly the same way regardless of
 * get_current_blog_id(). On a network that is exactly the property that
 * matters: a user enrolled and shown the onboarding screen once, on whichever
 * site they first logged into after enrollment, must never be diverted to it
 * again on a *different* site. The failure mode this guards against is the
 * one called out in the plugin's design brief: a user nagged with the
 * one-time onboarding screen repeatedly, once per site they happen to log
 * into.
 *
 * Both states this class checks -- "pending" (owed, not yet shown) and "done"
 * (shown, must never be shown again) -- are read from behind a
 * switch_to_blog() boundary that the write did not happen behind. On single
 * site there is only ever one "current site", so an equivalent assertion
 * there could not distinguish "the marker is global" from "the marker just
 * happens to still be readable because it's the same request" -- it would
 * pass even if the marker were (incorrectly) scoped per site, as long as
 * nothing ever changed which site was current.
 */
class Tests_Two_Factor_Enrollment_Multisite_Onboarding extends WP_UnitTestCase {

	/**
	 * A second site, distinct from the main site where enrollment happens in
	 * these tests.
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
	 * Put a user into the state persist_enrollment() would leave them in on a
	 * real, successful first challenge: enrolled, and the onboarding screen
	 * owed.
	 *
	 * @param int $user_id The user to enroll.
	 * @return string The pending marker's one-time token.
	 */
	private function enroll_and_owe_onboarding( $user_id ) {
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$marker = Two_Factor_Enrollment_Onboarding::new_pending_marker();
		update_user_meta( $user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, $marker );

		return Two_Factor_Enrollment_Onboarding::pending_token( $user_id );
	}

	/**
	 * Enrolled and marked "pending" while the main site is current -- the
	 * ordinary case. Read from a different site's context, the screen must
	 * still read as owed: a user who happens to open a second site's wp-login
	 * before ever seeing the onboarding screen must still get it, not silently
	 * lose it because the "current site" changed.
	 */
	public function test_pending_marker_still_owed_when_read_from_another_site() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->enroll_and_owe_onboarding( $user_id );

		switch_to_blog( self::$other_site_id );

		$user   = get_user_by( 'id', $user_id );
		$needed = Two_Factor_Enrollment_Onboarding::needs_onboarding( $user );

		$redirect = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		restore_current_blog();

		$this->assertTrue( $needed );
		$this->assertNotSame( admin_url(), $redirect, 'A user with an owed onboarding screen must be diverted to it, even when logging into a different site than where they were enrolled.' );
		$this->assertStringContainsString( Two_Factor_Enrollment_Onboarding::ACTION, $redirect );
	}

	/**
	 * The core claim: a marker flipped to 'done' on one site is read as
	 * 'done' from another, so the screen is never shown a second time no
	 * matter which site the user logs into next.
	 */
	public function test_marker_marked_done_on_one_site_is_not_offered_again_from_another_site() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$token = $this->enroll_and_owe_onboarding( $user_id );
		$user  = get_user_by( 'id', $user_id );

		// Simulate the onboarding screen actually having been shown, on the
		// main site, exactly as Two_Factor_Enrollment_Onboarding::render()
		// does via claim_onboarding().
		$claimed = Two_Factor_Enrollment_Onboarding::claim_onboarding( $user, $token );
		$this->assertTrue( $claimed, 'Fixture setup: the claim itself must succeed before this test can mean anything.' );

		switch_to_blog( self::$other_site_id );

		$needed          = Two_Factor_Enrollment_Onboarding::needs_onboarding( $user );
		$redirect_target = admin_url();
		$redirect        = Two_Factor_Enrollment_Onboarding::filter_login_redirect( $redirect_target, $redirect_target, $user );

		restore_current_blog();

		$this->assertFalse( $needed, 'A user already shown the onboarding screen on one site must not be offered it again from another.' );
		$this->assertSame( $redirect_target, $redirect, 'A completed onboarding marker must not divert login on a different site.' );
	}

	/**
	 * A user who configured two-factor before this plugin was ever installed
	 * (or on a site this plugin never enrolled them from) has no marker at
	 * all -- not 'pending', not 'done', simply absent. That must read as
	 * "never show the screen" from every site, not just the one they are
	 * usually thought of as belonging to.
	 */
	public function test_absent_marker_never_triggers_onboarding_from_any_site() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Two-factor configured directly, the way it would be if the user
		// (or an admin) set it up without ever going through this plugin's
		// gate -- no onboarding marker is written for that path.
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$user = get_user_by( 'id', $user_id );
		$this->assertTrue( Two_Factor_Enrollment::is_complete( $user_id ), 'Fixture setup: the user must actually be using two-factor for this test to mean anything.' );

		switch_to_blog( self::$other_site_id );

		$needed = Two_Factor_Enrollment_Onboarding::needs_onboarding( $user );

		restore_current_blog();

		$this->assertFalse( $needed );
	}
}
