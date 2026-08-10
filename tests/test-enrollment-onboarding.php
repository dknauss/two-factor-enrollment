<?php
/**
 * Tests for the onboarding screen routing.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Onboarding extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_providers' );
		parent::tear_down();
	}

	/**
	 * two_factor_providers filter callback that removes Backup Codes,
	 * simulating an administrator disabling the provider site-wide.
	 *
	 * @param array $providers Registered providers, keyed by class name.
	 * @return array
	 */
	public function remove_backup_codes_provider( $providers ) {
		unset( $providers['Two_Factor_Backup_Codes'] );
		return $providers;
	}

	public function test_needs_onboarding_false_without_two_factor() {
		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_needs_onboarding_true_when_enrolled_and_pending() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	/**
	 * The regression this override exists to prevent: a user with two-factor
	 * fully configured but no onboarding marker at all (e.g. TOTP set up
	 * before this plugin was ever installed) must not be routed to the
	 * onboarding screen. Only users this plugin itself enrolled — marked
	 * 'pending' by persist_enrollment() — are shown the screen.
	 */
	public function test_needs_onboarding_false_when_configured_but_never_marked_pending() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
		update_user_meta( $this->user_id, '_two_factor_totp_key', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );

		$user   = get_user_by( 'id', $this->user_id );
		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertSame( admin_url(), $result );
	}

	public function test_needs_onboarding_false_once_flagged_done() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_mark_onboarded_moves_pending_to_done() {
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertSame( 'done', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
	}

	public function test_url_contains_the_custom_action() {
		$url = Two_Factor_Enrollment_Onboarding::url( admin_url() );

		$this->assertStringContainsString( 'action=two_factor_enrollment_onboarding', $url );
		$this->assertStringContainsString( 'wp-login.php', $url );
	}

	public function test_url_preserves_redirect_to() {
		$url = Two_Factor_Enrollment_Onboarding::url( 'https://example.org/wp-admin/edit.php' );

		$this->assertStringContainsString( rawurlencode( 'https://example.org/wp-admin/edit.php' ), $url );
	}

	public function test_login_redirect_diverts_when_onboarding_needed() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, 'pending' );

		$user = get_user_by( 'id', $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertStringContainsString( 'action=two_factor_enrollment_onboarding', $result );
	}

	public function test_login_redirect_untouched_when_not_needed() {
		$user = get_user_by( 'id', $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertSame( admin_url(), $result );
	}

	public function test_login_redirect_untouched_on_login_error() {
		$error = new WP_Error( 'invalid_username', 'nope' );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $error );

		$this->assertSame( admin_url(), $result );
	}

	public function test_login_form_action_is_registered() {
		$this->assertNotFalse( has_action( 'login_form_two_factor_enrollment_onboarding' ) );
	}

	public function test_render_body_lists_backup_codes() {
		$user  = get_user_by( 'id', $this->user_id );
		$codes = array( 'aaaa1111', 'bbbb2222' );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $user, $codes, admin_url() );

		$this->assertStringContainsString( 'aaaa1111', $html );
		$this->assertStringContainsString( 'bbbb2222', $html );
	}

	public function test_render_body_offers_totp_upgrade() {
		$user = get_user_by( 'id', $this->user_id );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $user, array(), admin_url() );

		$this->assertStringContainsString( 'profile.php', $html );
	}

	public function test_render_body_includes_continue_link_to_redirect_target() {
		$user = get_user_by( 'id', $this->user_id );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $user, array(), 'https://example.org/wp-admin/edit.php' );

		$this->assertStringContainsString( 'https://example.org/wp-admin/edit.php', $html );
	}

	public function test_render_body_escapes_codes() {
		$user = get_user_by( 'id', $this->user_id );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $user, array( '<script>x</script>' ), admin_url() );

		$this->assertStringNotContainsString( '<script>x</script>', $html );
	}

	/**
	 * This is the assertion that proves the recovery path actually works:
	 * codes generated on the onboarding screen must make Backup Codes an
	 * available login method, not just a screen full of text. The brief's
	 * approach (calling generate_codes() alone) writes only the code hashes
	 * and would fail this — Two_Factor_Core::get_available_providers_for_user()
	 * intersects enabled providers with configured ones, and a provider that
	 * is never enabled is never offered at login.
	 */
	public function test_maybe_generate_backup_codes_makes_provider_available_at_login() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$user = get_user_by( 'id', $this->user_id );

		$codes = Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$this->assertNotEmpty( $codes );

		$available = Two_Factor_Core::get_available_providers_for_user( $user );

		$this->assertArrayHasKey( 'Two_Factor_Backup_Codes', $available );
	}

	/**
	 * A user who already has backup codes must keep them: generating new
	 * ones on every visit to this screen would silently invalidate codes
	 * the user may have already saved.
	 */
	public function test_maybe_generate_backup_codes_does_not_regenerate_existing_codes() {
		$existing = array( 'existing-hash-one', 'existing-hash-two' );
		update_user_meta( $this->user_id, '_two_factor_backup_codes', $existing );

		$user = get_user_by( 'id', $this->user_id );

		$codes = Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$this->assertSame( array(), $codes );
		$this->assertSame( $existing, get_user_meta( $this->user_id, '_two_factor_backup_codes', true ) );
	}

	/**
	 * Enabling Backup Codes must append to the existing enabled-providers
	 * list and must never touch the primary provider, which stays Email.
	 */
	public function test_maybe_generate_backup_codes_appends_without_disturbing_existing_state() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$enabled = get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		$this->assertSame( array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ), $enabled );
		$this->assertSame( 'Two_Factor_Email', get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
	}

	/**
	 * If an administrator has disabled Backup Codes site-wide, the screen
	 * must not offer codes that cannot exist.
	 */
	public function test_maybe_generate_backup_codes_skipped_when_provider_disabled() {
		add_filter( 'two_factor_providers', array( $this, 'remove_backup_codes_provider' ) );

		$user = get_user_by( 'id', $this->user_id );

		$codes = Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$this->assertSame( array(), $codes );
		$this->assertSame( '', get_user_meta( $this->user_id, '_two_factor_backup_codes', true ) );
	}

	public function test_render_body_omits_backup_codes_section_when_provider_disabled() {
		add_filter( 'two_factor_providers', array( $this, 'remove_backup_codes_provider' ) );

		$user  = get_user_by( 'id', $this->user_id );
		$codes = Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $user, $codes, admin_url() );

		$this->assertStringNotContainsString( 'Save your backup codes', $html );
	}
}
