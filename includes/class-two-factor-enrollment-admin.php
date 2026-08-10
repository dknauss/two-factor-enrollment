<?php
/**
 * Admin-facing warnings.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Warns administrators when enrollment cannot run.
 */
class Two_Factor_Enrollment_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Whether the warning applies.
	 *
	 * @return bool
	 */
	public static function should_warn() {
		return ! Two_Factor_Enrollment::email_provider_available();
	}

	/**
	 * Print the warning.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( ! self::should_warn() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Two-Factor Enrollment is not enforcing anything.', 'two-factor-enrollment' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'This plugin enrolls users by sending a one-time code to their email address, which proves they control the mailbox before a second factor is set up. The Email provider is currently disabled for this site, so there is nothing to enroll users into and no user will be prompted.', 'two-factor-enrollment' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Re-enable the Email provider in the Two Factor settings, or remove this plugin if you intend to manage enrollment another way.', 'two-factor-enrollment' ); ?>
			</p>
		</div>
		<?php
	}
}
