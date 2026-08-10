<?php
/**
 * Multisite tests for the admin misconfiguration notice.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 * @group multisite
 */

/**
 * What "behaving sensibly on a network" means here, and why:
 *
 * Two_Factor_Enrollment_Admin only hooks 'admin_notices', a per-site hook
 * that fires once for every site's own wp-admin page load. It does not hook
 * 'network_admin_notices', so a network-activated install with the Email
 * provider disabled everywhere, or no policy filter registered anywhere,
 * produces no warning at all on wp-admin/network/ -- only on each site's own
 * dashboard, and only for that site's own administrators. That is a real
 * property of the current code, not a bug this task is scoped to fix (per
 * the constraint against changing plugin behavior), so it is called out here
 * rather than silently assumed away: an admin who only ever visits the
 * network dashboard will never see this notice, network-activated or not.
 *
 * Given that starting point, "sensible" for the per-site notice that does
 * exist is the narrowest useful guarantee: it must reflect the site that is
 * actually current when admin_notices fires, and it must not leak a stale
 * answer from whatever site happened to be current earlier in the same PHP
 * process. Both should_warn() (-> Two_Factor_Core::get_providers(), via the
 * 'two_factor_providers' filter) and no_policy_configured() (-> has_filter())
 * are plain function calls with no caching of their own, so nothing in this
 * plugin should be reading a memoized answer across a switch_to_blog()
 * boundary. That is what the tests below pin down, using a policy filter
 * shaped like a real one -- disabling Email only for one specific site on the
 * network, which is a legitimate thing for a network administrator to do
 * (e.g. an intranet subsite that mandates a hardware key instead) -- rather
 * than a contrived global on/off switch.
 *
 * None of this is expressible on single site: there is only ever one
 * "current site" to be stale relative to, so an equivalent single-site test
 * could not tell a correctly-recomputed answer apart from an accidentally
 * cached one.
 */
class Tests_Two_Factor_Enrollment_Multisite_Admin_Notice extends WP_UnitTestCase {

	/**
	 * A second site, distinct from the main site.
	 *
	 * @var int
	 */
	private static $restricted_site_id;

	/**
	 * An administrator on both the main site and $restricted_site_id, so
	 * current_user_can( 'manage_options' ) holds on either without that
	 * capability check being the thing under test.
	 *
	 * @var int
	 */
	private static $admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$restricted_site_id = $factory->blog->create();
		self::$admin_id           = $factory->user->create( array( 'role' => 'administrator' ) );

		add_user_to_blog( self::$restricted_site_id, self::$admin_id, 'administrator' );
	}

	public static function wpTearDownAfterClass() {
		wp_delete_site( self::$restricted_site_id );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_providers' );
		remove_all_filters( 'two_factor_enrollment_required' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * has_filter( 'two_factor_enrollment_required' ) is a plain PHP filter
	 * registry check: it is true or false for the whole request, the same on
	 * every site, regardless of which blog is current when it runs. A real
	 * network install would normally have a policy filter registered from
	 * plugins_loaded, so every test in this class registers one too --
	 * otherwise no_policy_configured() would be true everywhere and its
	 * "not enrolling anyone" notice would fire on both sites, masking the
	 * per-site Email-availability signal these tests actually exist to
	 * isolate.
	 *
	 * @return void
	 */
	private function register_policy_so_only_email_availability_varies() {
		add_filter( 'two_factor_enrollment_required', '__return_false' );
	}

	/**
	 * A policy shaped like a real network configuration: Email is disabled
	 * on one specific site, not globally.
	 *
	 * @return void
	 */
	private function disable_email_on_restricted_site_only() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				if ( get_current_blog_id() === self::$restricted_site_id ) {
					unset( $providers['Two_Factor_Email'] );
				}

				return $providers;
			}
		);
	}

	/**
	 * On the main site, where the filter above leaves Email untouched,
	 * should_warn() must be false.
	 */
	public function test_should_warn_false_on_main_site_when_only_restricted_site_disables_email() {
		$this->disable_email_on_restricted_site_only();

		$this->assertFalse( Two_Factor_Enrollment_Admin::should_warn() );
	}

	/**
	 * The same filter, evaluated with the restricted site current, must
	 * report the warning -- proving get_providers() (and therefore
	 * should_warn()) is genuinely re-evaluated per site, not fixed at
	 * whatever the main site's answer was.
	 */
	public function test_should_warn_true_on_restricted_site() {
		$this->disable_email_on_restricted_site_only();

		switch_to_blog( self::$restricted_site_id );
		$warns = Two_Factor_Enrollment_Admin::should_warn();
		restore_current_blog();

		$this->assertTrue( $warns );
	}

	/**
	 * The leak check: after visiting the restricted site and switching back,
	 * the main site's answer must return to false rather than staying stuck
	 * on the restricted site's "true". This is what would fail if
	 * get_providers() or should_warn() ever grew a static cache keyed only by
	 * provider class name and not by site.
	 */
	public function test_should_warn_reverts_to_false_after_restoring_main_site() {
		$this->disable_email_on_restricted_site_only();

		switch_to_blog( self::$restricted_site_id );
		Two_Factor_Enrollment_Admin::should_warn(); // Prime any would-be cache before checking the real site.
		restore_current_blog();

		$this->assertFalse( Two_Factor_Enrollment_Admin::should_warn() );
	}

	/**
	 * End-to-end through notice() itself, not just the boolean helper: an
	 * administrator on the restricted site sees the warning markup: on the
	 * main site they do not, in the same test, without a process restart
	 * between the two checks.
	 */
	public function test_notice_output_differs_between_sites_for_the_same_administrator() {
		wp_set_current_user( self::$admin_id );

		$this->register_policy_so_only_email_availability_varies();
		$this->disable_email_on_restricted_site_only();

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$main_site_html = ob_get_clean();

		switch_to_blog( self::$restricted_site_id );
		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$restricted_site_html = ob_get_clean();
		restore_current_blog();

		$this->assertSame( '', $main_site_html );
		$this->assertStringContainsString( 'notice-warning', $restricted_site_html );
		$this->assertStringContainsString( 'not enforcing anything', $restricted_site_html );
	}
}
