<?php
/**
 * Proves the hard-dependency guard in two_factor_enrollment_bootstrap().
 *
 * This suite runs under its own bootstrap (tests/bootstrap-no-dependency.php),
 * which never loads the Two Factor plugin. That makes Two_Factor_Core
 * genuinely absent here, so this is the only place the guard's false branch
 * is actually exercised — the main suite (tests/bootstrap.php) always loads
 * Two Factor, so class_exists( 'Two_Factor_Core' ) is always true there.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Bootstrap_Guard extends WP_UnitTestCase {

	/**
	 * Calling two_factor_enrollment_bootstrap() without Two_Factor_Core must
	 * not fatal, and must not load the enrollment class.
	 */
	public function test_bootstrap_no_ops_without_two_factor_core() {
		// Precondition: if this is ever true, the rest of this test proves
		// nothing, so it must fail loudly rather than let the test pass by
		// accident.
		$this->assertFalse(
			class_exists( 'Two_Factor_Core' ),
			'Precondition failed: Two_Factor_Core is loaded in the no-dependency suite. This test suite exists specifically to exercise the case where it is absent; fix the bootstrap before trusting the rest of this test.'
		);

		// The real assertion: calling the bootstrap function directly must
		// not fatal (reaching the next assertion proves that) and must not
		// require the enrollment class file.
		two_factor_enrollment_bootstrap();

		$this->assertFalse(
			class_exists( 'Two_Factor_Enrollment' ),
			'Two_Factor_Enrollment loaded even though Two_Factor_Core is absent — the hard-dependency guard in two_factor_enrollment_bootstrap() did not prevent it.'
		);
	}
}
