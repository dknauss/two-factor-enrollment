<?php
/**
 * Self-check for the multisite test bootstrap.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 * @group multisite
 */

/**
 * phpunit-multisite.xml.dist defines the WP_TESTS_MULTISITE constant so the
 * shared WordPress test library installs and boots as a network. Every other
 * test in tests/multisite/ depends on that having actually happened -- a
 * network table missing, a stray `MULTISITE` misconfiguration, or someone
 * copy-pasting phpunit.xml.dist over phpunit-multisite.xml.dist by mistake
 * would otherwise make this whole suite quietly re-run the single-site tests
 * a second time instead of exercising the network dimension at all.
 *
 * This class asserts the precondition directly, so that failure mode is loud
 * (every test here fails) rather than silent (every test here "passes" for
 * the wrong reason).
 */
class Tests_Two_Factor_Enrollment_Multisite_Bootstrap extends WP_UnitTestCase {

	/**
	 * The one assertion this whole suite's credibility rests on.
	 */
	public function test_is_multisite() {
		$this->assertTrue( is_multisite(), 'WP_TESTS_MULTISITE did not put the install into multisite mode; see phpunit-multisite.xml.dist.' );
	}

	/**
	 * Confirms switch_to_blog() has something real to switch to, i.e. this
	 * install has more than the one site every install starts with.
	 */
	public function test_can_create_and_switch_to_a_second_site() {
		$site_id = self::factory()->blog->create();

		$this->assertIsInt( $site_id );
		$this->assertNotSame( get_current_blog_id(), $site_id );

		switch_to_blog( $site_id );

		$this->assertSame( $site_id, get_current_blog_id() );

		restore_current_blog();

		wp_delete_site( $site_id );
	}

	public function test_two_factor_core_is_available() {
		$this->assertTrue( class_exists( 'Two_Factor_Core' ) );
	}

	public function test_enrollment_class_is_loaded() {
		$this->assertTrue( class_exists( 'Two_Factor_Enrollment' ) );
	}
}
