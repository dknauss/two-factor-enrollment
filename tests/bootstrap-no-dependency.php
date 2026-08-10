<?php
/**
 * Bootstrap the PHPUnit tests that verify plugin behavior without Two Factor.
 *
 * Unlike tests/bootstrap.php, this bootstrap never requires the Two Factor
 * plugin, so `Two_Factor_Core` genuinely does not exist for this suite. It
 * requires only this plugin's entry file and deliberately does not call
 * two_factor_enrollment_bootstrap() itself — the tests in tests/no-dependency
 * drive that call so they can observe its behavior directly.
 *
 * @package Two_Factor_Enrollment
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require_once dirname( __DIR__ ) . '/two-factor-enrollment.php';
	}
);

require_once $_tests_dir . '/includes/bootstrap.php';
