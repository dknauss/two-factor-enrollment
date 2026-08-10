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
 * Two_Factor_Enrollment_Admin hooks two notice actions, and they deliberately
 * do not carry the same payload.
 *
 * 'admin_notices' is per-site: it fires on each site's own wp-admin pages, for
 * that site's own administrators, and both warnings are meaningful there.
 *
 * 'network_admin_notices' fires on wp-admin/network/, where there is no
 * meaningful "current site" -- get_current_blog_id() reports the network's
 * main site whatever the network administrator is actually there to manage.
 * Only the no-policy warning survives that: has_filter() answers a question
 * about the PHP process, so it is equally true on every site of the network.
 * should_warn() does not -- it reaches Two_Factor_Core::get_providers()
 * through the per-site 'two_factor_providers' filter, so on the network
 * dashboard it would report the main site's Email availability while
 * appearing to describe the network. A network of fifty sites with Email
 * disabled on forty-nine of them would show nothing; disable it on the main
 * site alone and every network screen would warn. Both readings are wrong,
 * and answering the question honestly would mean iterating every site on the
 * network on every network admin page load. So the network notice reports
 * only what it can actually know, and the per-site notice keeps the per-site
 * fact. The tests below pin down both halves of that split.
 *
 * For the per-site notice, "sensible" is the narrowest useful guarantee: it
 * must reflect the site that is
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

	/**
	 * A network administrator, i.e. the only kind of user who can reach the
	 * screens 'network_admin_notices' renders on.
	 *
	 * @var int
	 */
	private static $super_admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$restricted_site_id = $factory->blog->create();
		self::$admin_id           = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$super_admin_id     = $factory->user->create( array( 'role' => 'administrator' ) );

		add_user_to_blog( self::$restricted_site_id, self::$admin_id, 'administrator' );
		grant_super_admin( self::$super_admin_id );
	}

	public static function wpTearDownAfterClass() {
		revoke_super_admin( self::$super_admin_id );
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
	 * Email disabled on every site of the network, which is what the
	 * network notice must still decline to report -- not because the fact is
	 * false, but because the network screen cannot establish it.
	 *
	 * @return void
	 */
	private function disable_email_everywhere() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );

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

	/*
	 * ---------------------------------------------------------------------
	 * The network admin notice.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Without this registration the whole feature is unreachable: a network
	 * administrator who never leaves wp-admin/network/ sees no warning at
	 * all, which is precisely the person most likely to network-activate the
	 * plugin and then never register a policy filter.
	 */
	public function test_add_hooks_registers_the_network_admin_notice() {
		Two_Factor_Enrollment_Admin::add_hooks();

		$this->assertNotFalse(
			has_action( 'network_admin_notices', array( 'Two_Factor_Enrollment_Admin', 'network_notice' ) )
		);
	}

	/**
	 * The case the notice exists for: plugin network-activated, nobody ever
	 * wired up a policy, so it enrolls no one and says why.
	 */
	public function test_network_notice_warns_when_no_policy_registered() {
		wp_set_current_user( self::$super_admin_id );

		ob_start();
		Two_Factor_Enrollment_Admin::network_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'not enrolling anyone', $html );
	}

	/**
	 * A correctly configured network is silent.
	 */
	public function test_network_notice_silent_when_policy_registered() {
		wp_set_current_user( self::$super_admin_id );

		$this->register_policy_so_only_email_availability_varies();

		ob_start();
		Two_Factor_Enrollment_Admin::network_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * The discriminating test for the split described in this file's header:
	 * Email availability is a per-site fact and must not be reported from a
	 * network screen, where the "current site" is an artifact of how network
	 * admin loads rather than anything the administrator chose.
	 *
	 * Both conditions hold here -- Email disabled on the current site and no
	 * policy registered -- which on the per-site notice suppresses the
	 * no-policy warning in favour of the Email one. The network notice must
	 * do the opposite: report the network-wide fact it can actually know,
	 * and stay silent about the per-site one it cannot.
	 *
	 * Reusing notice() for this hook, the obvious one-line fix, fails here.
	 */
	public function test_network_notice_reports_no_policy_and_not_per_site_email_availability() {
		wp_set_current_user( self::$super_admin_id );

		$this->disable_email_everywhere();

		$this->assertTrue( Two_Factor_Enrollment_Admin::should_warn(), 'Precondition: the per-site Email warning would fire here.' );

		ob_start();
		Two_Factor_Enrollment_Admin::network_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'not enrolling anyone', $html );
		$this->assertStringNotContainsString( 'not enforcing anything', $html );
	}

	/**
	 * A policy registered and Email disabled: still silent, because the only
	 * thing this notice speaks to is configured.
	 */
	public function test_network_notice_silent_when_policy_registered_even_with_email_disabled() {
		wp_set_current_user( self::$super_admin_id );

		$this->register_policy_so_only_email_availability_varies();
		$this->disable_email_everywhere();

		ob_start();
		Two_Factor_Enrollment_Admin::network_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * Capability gate. A site administrator is not a network administrator:
	 * they cannot reach wp-admin/network/ at all, and the notice tells them
	 * to edit a filter they have no business editing. This also proves the
	 * gate is consulted rather than the notice being unconditionally
	 * printed by whatever renders it.
	 */
	public function test_network_notice_silent_for_a_site_administrator() {
		wp_set_current_user( self::$admin_id );

		$this->assertFalse( is_super_admin( self::$admin_id ), 'Precondition: this user must not be a super admin.' );

		ob_start();
		Two_Factor_Enrollment_Admin::network_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
