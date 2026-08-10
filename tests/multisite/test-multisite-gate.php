<?php
/**
 * Multisite tests for the enrollment gate.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 * @group multisite
 */

/**
 * The gate itself (Two_Factor_Enrollment_Gate) never looks at blog IDs -- it
 * only reads and writes user meta, which is global across a network. That is
 * exactly the property this file exists to pin down: a policy filter that
 * *does* reason about site membership (the realistic shape of a policy on a
 * network -- "cover editors on the intranet subsite", say) must still result
 * in the gate injecting Email correctly, for a user whose only membership is
 * a subsite, evaluated while that subsite -- not the main site -- is the
 * current blog.
 *
 * None of this is expressible on single-site: there is no second site to
 * belong instead of, no switch_to_blog() boundary to cross, and
 * is_user_member_of_blog() itself only exists under multisite. A test that
 * merely re-ran test-enrollment-gate.php's assertions unchanged would prove
 * nothing about the network dimension; every assertion here is keyed to a
 * user's site membership or a switch_to_blog() boundary.
 */
class Tests_Two_Factor_Enrollment_Multisite_Gate extends WP_UnitTestCase {

	/**
	 * A second site, distinct from the main site every install starts with.
	 *
	 * @var int
	 */
	private static $subsite_id;

	/**
	 * A user who belongs only to $subsite_id -- never added to the main
	 * site.
	 *
	 * @var int
	 */
	private static $subsite_only_user_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$subsite_id = $factory->blog->create();

		switch_to_blog( self::$subsite_id );
		self::$subsite_only_user_id = $factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'subsite-editor@example.org',
			)
		);
		restore_current_blog();
	}

	public static function wpTearDownAfterClass() {
		wp_delete_site( self::$subsite_id );
	}

	public function set_up() {
		parent::set_up();
		Two_Factor_Enrollment_Gate::reset_injections();
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_enrollment_deferrable' );
		Two_Factor_Enrollment_Gate::reset_injections();
		parent::tear_down();
	}

	/**
	 * A policy filter shaped like a real one: it covers a user only on the
	 * site(s) they actually belong to, not globally.
	 *
	 * @return void
	 */
	private function add_membership_scoped_policy() {
		add_filter(
			'two_factor_enrollment_required',
			function ( $required, $user ) {
				return is_user_member_of_blog( $user->ID, self::$subsite_id );
			},
			10,
			2
		);
	}

	/**
	 * Self-check: this user really does not belong to the main site. If this
	 * ever failed, every other assertion in this class would be exercising
	 * the wrong scenario without saying so.
	 */
	public function test_fixture_user_does_not_belong_to_main_site() {
		$this->assertFalse( is_user_member_of_blog( self::$subsite_only_user_id, get_current_blog_id() ) );
		$this->assertTrue( is_user_member_of_blog( self::$subsite_only_user_id, self::$subsite_id ) );
	}

	/**
	 * The core claim: evaluated from the subsite the user actually belongs
	 * to, a membership-scoped policy causes the gate to inject Email. This is
	 * the "the gate works away from the main site" requirement -- the whole
	 * request, including get_current_blog_id(), is running in the subsite's
	 * context, not the network's site 1.
	 */
	public function test_email_injected_for_subsite_only_user_on_their_own_site() {
		$this->add_membership_scoped_policy();

		switch_to_blog( self::$subsite_id );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( self::$subsite_only_user_id );

		restore_current_blog();

		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	/**
	 * The same user, the same policy filter, evaluated from the main site
	 * instead. is_user_member_of_blog( $user, $subsite_id ) takes an explicit
	 * blog ID, not "the current blog", so the policy's answer for this user
	 * does not depend on which site is currently active -- confirming the
	 * gate has no hidden main-site assumption of its own layered on top of
	 * whatever the policy decides.
	 */
	public function test_email_still_injected_for_subsite_only_user_when_evaluated_from_main_site() {
		$this->add_membership_scoped_policy();

		// Deliberately no switch_to_blog(): current blog is the main site,
		// which this user does not belong to at all.
		$enabled = Two_Factor_Core::get_enabled_providers_for_user( self::$subsite_only_user_id );

		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	/**
	 * A user who belongs only to the main site is exactly the case a
	 * membership-scoped subsite policy must *not* cover. Without this
	 * negative case, a gate bug that injected Email unconditionally
	 * regardless of the policy's answer would pass every assertion above.
	 */
	public function test_email_not_injected_for_main_site_user_under_subsite_scoped_policy() {
		$main_site_user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'main-site-editor@example.org',
			)
		);

		$this->add_membership_scoped_policy();

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $main_site_user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	/**
	 * is_user_using_two_factor() is the boolean the login flow actually
	 * gates on. Confirms the positive case end-to-end, not just the enabled-
	 * providers list.
	 */
	public function test_subsite_only_user_is_using_two_factor_when_covered() {
		$this->add_membership_scoped_policy();

		switch_to_blog( self::$subsite_id );

		$is_using = Two_Factor_Core::is_user_using_two_factor( self::$subsite_only_user_id );

		restore_current_blog();

		$this->assertTrue( $is_using );
	}
}
