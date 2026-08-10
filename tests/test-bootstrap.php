<?php
/**
 * Smoke tests for plugin loading.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Bootstrap extends WP_UnitTestCase {

	public function test_two_factor_core_is_available() {
		$this->assertTrue( class_exists( 'Two_Factor_Core' ) );
	}

	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'TWO_FACTOR_ENROLLMENT_DIR' ) );
		$this->assertTrue( defined( 'TWO_FACTOR_ENROLLMENT_VERSION' ) );
	}

	public function test_enrollment_class_is_loaded() {
		$this->assertTrue( class_exists( 'Two_Factor_Enrollment' ) );
	}
}
