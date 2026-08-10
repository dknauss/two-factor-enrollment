<?php
/**
 * Multisite tests for uninstall cleanup.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 * @group multisite
 */

/**
 * Automates the one network check this plugin had previously only been given
 * by hand: uninstalling from site 1 removed the onboarding marker for a user
 * who belongs solely to site 2, while leaving Two Factor's own data (enabled
 * providers, primary provider, backup codes) untouched.
 *
 * uninstall.php explains why get_users() would be the wrong tool here --
 * "get_users() scopes to the current site, which would leave [users on other
 * sites] behind" -- and reaches for delete_metadata( 'user', 0, ..., true )
 * instead, which ignores site scope entirely and matches on the meta key
 * alone. This class exists to prove that claim rather than trust the
 * docblock: it runs the real uninstall routine, from the main site, against
 * users on two different sites, one of whom (self::$subsite_only_user_id)
 * the main site's own get_users() would never see at all.
 *
 * A single-site version of this test could confirm the marker is removed and
 * Two Factor's data survives, but it could not distinguish
 * delete_metadata( ..., $delete_all = true ) from the get_users()-scoped
 * approach the docblock explicitly warns against, because on single site the
 * two approaches behave identically. Only a user who belongs to a site other
 * than the one uninstall runs from can tell them apart.
 */
class Tests_Two_Factor_Enrollment_Multisite_Uninstall extends WP_UnitTestCase {

	/**
	 * A second site, distinct from the main site uninstall runs from below.
	 *
	 * @var int
	 */
	private static $subsite_id;

	/**
	 * A user who belongs only to $subsite_id -- the case get_users() run from
	 * the main site would never find.
	 *
	 * @var int
	 */
	private static $subsite_only_user_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$subsite_id = $factory->blog->create();

		switch_to_blog( self::$subsite_id );
		self::$subsite_only_user_id = $factory->user->create( array( 'role' => 'editor' ) );
		restore_current_blog();
	}

	public static function wpTearDownAfterClass() {
		wp_delete_site( self::$subsite_id );
	}

	/**
	 * Fully enroll a user the way this plugin would, complete with backup
	 * codes and a finished onboarding marker, on whichever site is current.
	 *
	 * @param int $user_id The user to enroll.
	 * @return void
	 */
	private function fully_enroll( $user_id ) {
		update_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ) );
		update_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$user = get_user_by( 'id', $user_id );
		Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $user_id );
	}

	private function run_uninstall() {
		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';

		two_factor_enrollment_uninstall();
	}

	/**
	 * The claim from the plugin's design brief, automated: uninstalling from
	 * the main site removes the onboarding marker for a user who belongs
	 * solely to a different site, while every piece of Two Factor's own data
	 * for that user -- enabled providers, primary provider, backup codes, and
	 * the ability to actually log in with them -- survives untouched.
	 */
	public function test_uninstall_from_main_site_cleans_up_subsite_only_user_without_touching_two_factor_data() {
		switch_to_blog( self::$subsite_id );
		$this->fully_enroll( self::$subsite_only_user_id );
		$codes_before = get_user_meta( self::$subsite_only_user_id, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY, true );
		restore_current_blog();

		$this->assertNotEmpty( $codes_before, 'Fixture setup: the user must actually have backup codes for this test to mean anything.' );

		// Current blog is the main site again -- uninstall runs from here,
		// exactly as it was when this was previously checked by hand.
		$this->assertNotSame( self::$subsite_id, get_current_blog_id() );

		$this->run_uninstall();

		$this->assertSame(
			'',
			get_user_meta( self::$subsite_only_user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ),
			'The onboarding marker for a subsite-only user must be removed by an uninstall run from the main site.'
		);

		switch_to_blog( self::$subsite_id );

		$enabled_providers = get_user_meta( self::$subsite_only_user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );
		$primary_provider  = get_user_meta( self::$subsite_only_user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true );
		$codes_after       = get_user_meta( self::$subsite_only_user_id, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY, true );
		$still_using_2fa   = Two_Factor_Core::is_user_using_two_factor( self::$subsite_only_user_id );

		restore_current_blog();

		$this->assertSame( array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ), $enabled_providers );
		$this->assertSame( 'Two_Factor_Email', $primary_provider );
		$this->assertSame( $codes_before, $codes_after );
		$this->assertTrue( $still_using_2fa, "A subsite-only user's working second factor must survive uninstall." );
	}

	/**
	 * The same uninstall run, in the same request, must also clean up a user
	 * who belongs to the main site itself -- confirming
	 * delete_metadata( ..., $delete_all = true ) covers every site at once
	 * rather than only reaching the site the subsite-only fixture happens to
	 * be on.
	 */
	public function test_uninstall_also_cleans_up_main_site_user_in_the_same_run() {
		$main_site_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->fully_enroll( $main_site_user_id );

		$this->run_uninstall();

		$this->assertSame( '', get_user_meta( $main_site_user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $main_site_user_id ) );
	}
}
