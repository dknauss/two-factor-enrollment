<?php
/**
 * Uninstall cleanup.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Remove all data this plugin created.
 *
 * The only persisted state is the onboarding flag.
 *
 * @return void
 */
function two_factor_enrollment_uninstall() {
	$meta_key = '_two_factor_enrollment_onboarded';

	/*
	 * User meta is global across a multisite network and Two Factor is
	 * network-enabled, so this key can exist on users who belong only to other
	 * sites. get_users() scopes to the current site, which would leave those
	 * rows behind. delete_metadata()'s $delete_all removes every row for the
	 * key — one statement, correct on single site and multisite alike, and it
	 * invalidates the object cache as it goes.
	 *
	 * Nothing belonging to the Two Factor plugin is touched. Enabled
	 * providers, primary provider, and backup codes are the dependency's data
	 * and may still be in active use; a user enrolled by this plugin keeps
	 * their working second factor after it is removed.
	 */
	delete_metadata( 'user', 0, $meta_key, '', true );
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	two_factor_enrollment_uninstall();
}
