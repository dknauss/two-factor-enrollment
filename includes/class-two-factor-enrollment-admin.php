<?php
/**
 * Admin-facing warnings.
 *
 * @package Two_Factor_Enrollment
 */

defined( 'ABSPATH' ) || exit;

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
		add_action( 'network_admin_notices', array( __CLASS__, 'network_notice' ) );
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
	 * Whether no policy callback has ever been registered.
	 *
	 * This is the far more likely misconfiguration than the Email provider
	 * being disabled: installing the plugin and never writing a filter. It
	 * is deliberately keyed off registration, via has_filter(), rather than
	 * the filter's return value for any particular user. A policy that
	 * legitimately covers nobody right now — mid-rollout, or scoped to a
	 * role with no members yet — is a correctly configured install, and
	 * evaluating the filter would warn about it too, training admins to
	 * ignore the notice. Only "nobody ever wired this up" should warn.
	 *
	 * @return bool
	 */
	public static function no_policy_configured() {
		return ! has_filter( 'two_factor_enrollment_required' );
	}

	/**
	 * Print the warning.
	 *
	 * When both conditions hold — Email disabled and no policy registered —
	 * only the Email-unavailable notice is shown. Email being disabled is
	 * the more fundamental blocker: the gate checks it before it ever
	 * consults a policy, so even a freshly written filter would still
	 * enroll no one until Email is re-enabled. Showing the no-policy notice
	 * alongside it would invite an admin to "fix" the wrong problem first,
	 * and a second stacked notice restating "nothing is happening" for a
	 * different reason is noise on top of that.
	 *
	 * @return void
	 */
	public static function notice() {
		/*
		 * Capability first. should_warn() reaches
		 * Two_Factor_Core::get_providers(), which instantiates every
		 * registered provider, and this runs on every admin page load for
		 * every user. Only administrators can act on the notice, so nobody
		 * else should pay for deciding whether to show it.
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::should_warn() ) {
			self::email_unavailable_notice();
			return;
		}

		if ( self::no_policy_configured() ) {
			self::no_policy_notice();
		}
	}

	/**
	 * Print the warning on Network Admin screens.
	 *
	 * This is deliberately not notice(). Of the two warnings, only the
	 * no-policy one means anything on a network screen.
	 *
	 * has_filter() answers a question about the PHP process, so its answer
	 * is the same on every site of the network and a network administrator
	 * is exactly the person who can act on it — the policy filter for a
	 * network-activated install lives in an mu-plugin they control.
	 *
	 * should_warn() is the opposite. It reaches Two_Factor_Core::get_providers()
	 * through the per-site 'two_factor_providers' filter, and on
	 * wp-admin/network/ the current site is the network's main site by
	 * default rather than anything the administrator chose. Printing it here
	 * would describe one site while appearing to describe the network:
	 * silent on a network with Email disabled on every site but the main
	 * one, and warning about the whole network when only the main site has
	 * it off. Answering honestly would mean switch_to_blog() over every site
	 * on the network on every network admin page load, which is not a cost
	 * a notice gets to impose. So the per-site fact stays on the per-site
	 * hook, where it is both accurate and actionable.
	 *
	 * @return void
	 */
	public static function network_notice() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		if ( self::no_policy_configured() ) {
			self::no_policy_notice();
		}
	}

	/**
	 * The Email-provider-disabled notice.
	 *
	 * @return void
	 */
	private static function email_unavailable_notice() {
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

	/**
	 * The no-policy-registered notice.
	 *
	 * @return void
	 */
	private static function no_policy_notice() {
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Two-Factor Enrollment is not enrolling anyone.', 'two-factor-enrollment' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'This plugin is installed and active, but it enrolls no one until something tells it who is covered. By design, nothing is required and no one is enrolled unless a policy answers the two_factor_enrollment_required filter — and no such filter is currently registered on this site.', 'two-factor-enrollment' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: opening link tag to the plugin's README, Usage section, 2: closing link tag. */
					esc_html__( 'See the %1$sUsage%2$s section of the plugin\'s README for how to write one.', 'two-factor-enrollment' ),
					'<a href="' . esc_url( 'https://github.com/dknauss/two-factor-enrollment#usage' ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Building a translatable string's markup wrapper; the href is escaped with esc_url() above and this is not a raw echo of any request or user data.
					'</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing tag, no data.
				);
				?>
			</p>
		</div>
		<?php
	}
}
