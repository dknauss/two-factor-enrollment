<?php
/**
 * Plugin Name:       Two-Factor Enrollment
 * Plugin URI:        https://github.com/dknauss/two-factor-enrollment
 * Description:       Enrolls policy-covered users in two-factor authentication during login, before an auth cookie is issued.
 * Author:            Dan Knauss
 * Version:           0.1.0
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       two-factor-enrollment
 * Requires at least: 6.5
 * Requires PHP:      7.2
 * Requires Plugins:  two-factor
 *
 * @package Two_Factor_Enrollment
 */

define( 'TWO_FACTOR_ENROLLMENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWO_FACTOR_ENROLLMENT_VERSION', '0.1.0' );

/**
 * Load the plugin once all plugins are available.
 *
 * The Two Factor plugin is a hard dependency. If it is not active this plugin
 * does nothing at all rather than fataling.
 *
 * @return void
 */
function two_factor_enrollment_bootstrap() {
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}

	require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment.php';

	Two_Factor_Enrollment::add_hooks();
}
add_action( 'plugins_loaded', 'two_factor_enrollment_bootstrap' );
