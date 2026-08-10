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

	$user_ids = get_users(
		array(
			'fields'       => 'ID',
			'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time uninstall routine.
			'meta_compare' => 'EXISTS',
			'number'       => -1,
		)
	);

	foreach ( $user_ids as $user_id ) {
		delete_user_meta( $user_id, $meta_key );
	}
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	two_factor_enrollment_uninstall();
}
