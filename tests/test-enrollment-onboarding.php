<?php
/**
 * Tests for the onboarding screen routing.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Onboarding extends WP_UnitTestCase {

	private $user_id;

	/**
	 * Where abort_on_redirect() last saw the request being sent.
	 *
	 * @var string
	 */
	private $redirected_to = '';

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_providers' );
		remove_all_filters( 'wp_redirect' );
		unset( $_REQUEST[ Two_Factor_Enrollment_Onboarding::TOKEN_ARG ], $_REQUEST['redirect_to'] );
		parent::tear_down();
	}

	/**
	 * wp_redirect() filter callback that aborts the request by throwing, so a
	 * test can observe where render() sends the user without the `exit` that
	 * follows taking the PHPUnit process down with it.
	 *
	 * The destination is recorded on $this->redirected_to rather than carried
	 * in the exception message, which would put an unescaped value there.
	 *
	 * @param string $location The redirect destination.
	 * @return string
	 * @throws RuntimeException Always.
	 */
	public function abort_on_redirect( $location ) { // phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement -- Throwing rather than returning is how this fixture stops render() short of its exit.
		$this->redirected_to = $location;

		throw new RuntimeException( 'aborted at redirect' );
	}

	/**
	 * Put the user in the state a completed enrollment leaves them in: Email
	 * enrolled, and the onboarding screen owed with a live one-time token.
	 *
	 * @return string The token.
	 */
	private function seed_pending_enrollment() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );
		update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, Two_Factor_Enrollment_Onboarding::new_pending_marker() );

		return Two_Factor_Enrollment_Onboarding::pending_token( $this->user_id );
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
		$this->seed_pending_enrollment();

		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	/**
	 * The marker is 'pending:TOKEN', so the state test has to be a prefix
	 * match. A plain equality check against 'pending' would read every real
	 * enrollment as "not owed" and the screen would never appear.
	 */
	public function test_needs_onboarding_matches_the_pending_prefix_not_the_bare_word() {
		$this->seed_pending_enrollment();

		$marker = get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true );

		$this->assertNotSame( 'pending', $marker );
		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	/**
	 * 'done' shares no prefix with 'pending', but a sloppy prefix check
	 * (strpos !== false rather than === 0) would match a value that merely
	 * contains it, so pin the near-misses down.
	 */
	public function test_needs_onboarding_false_for_states_that_only_look_pending() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		foreach ( array( 'done', 'done:pending', 'suspending' ) as $state ) {
			update_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, $state );

			$this->assertFalse(
				Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ),
				"State '{$state}' must not read as owed."
			);
		}
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
		$this->seed_pending_enrollment();

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_mark_onboarded_moves_pending_to_done() {
		$this->seed_pending_enrollment();

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertSame( 'done', get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
	}

	/**
	 * Completing the screen destroys the token, which is what makes it single
	 * use — there is no separate "already used" bookkeeping to get wrong.
	 */
	public function test_mark_onboarded_destroys_the_token() {
		$token = $this->seed_pending_enrollment();

		$this->assertNotSame( '', $token );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertSame( '', Two_Factor_Enrollment_Onboarding::pending_token( $this->user_id ) );
	}

	public function test_url_contains_the_custom_action() {
		$url = Two_Factor_Enrollment_Onboarding::url( admin_url(), $this->user_id );

		$this->assertStringContainsString( 'action=two_factor_enrollment_onboarding', $url );
		$this->assertStringContainsString( 'wp-login.php', $url );
	}

	/**
	 * Two_Factor_Core::login_url() encodes with urlencode_deep(), i.e.
	 * urlencode() — not rawurlencode(). The two agree on this particular
	 * string, so asserting the wrong one passed by coincidence.
	 */
	public function test_url_preserves_redirect_to() {
		$url = Two_Factor_Enrollment_Onboarding::url( 'https://example.org/wp-admin/edit.php', $this->user_id );

		$this->assertStringContainsString( urlencode( 'https://example.org/wp-admin/edit.php' ), $url ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Mirrors urlencode_deep() inside Two_Factor_Core::login_url(), which is what is being asserted.
	}

	public function test_url_carries_the_one_time_token() {
		$token = $this->seed_pending_enrollment();

		$url = Two_Factor_Enrollment_Onboarding::url( admin_url(), $this->user_id );

		$this->assertStringContainsString( Two_Factor_Enrollment_Onboarding::TOKEN_ARG . '=' . $token, $url );
	}

	public function test_url_omits_the_token_when_there_is_none() {
		$url = Two_Factor_Enrollment_Onboarding::url( admin_url(), $this->user_id );

		$this->assertStringNotContainsString( Two_Factor_Enrollment_Onboarding::TOKEN_ARG, $url );
	}

	public function test_login_redirect_diverts_when_onboarding_needed() {
		$token = $this->seed_pending_enrollment();

		$user = get_user_by( 'id', $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$this->assertStringContainsString( 'action=two_factor_enrollment_onboarding', $result );

		// The screen has to stay reachable in the normal flow: the redirect
		// the user actually follows must carry the token render() demands.
		$this->assertStringContainsString( Two_Factor_Enrollment_Onboarding::TOKEN_ARG . '=' . $token, $result );
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

	/**
	 * Without the login_redirect filter nothing ever routes a freshly enrolled
	 * user to the onboarding screen, and deleting the line would otherwise
	 * leave the suite green.
	 */
	public function test_login_redirect_filter_is_registered() {
		$this->assertNotFalse(
			has_filter( 'login_redirect', array( 'Two_Factor_Enrollment_Onboarding', 'filter_login_redirect' ) )
		);
	}

	/**
	 * The other side of the token: it must not make the screen unreachable in
	 * the normal flow. Take the URL the user is actually sent to, pull the
	 * token back out of the query string, and check it against the value
	 * render() will compare — so URL encoding cannot quietly break the only
	 * path to the recovery screen.
	 */
	public function test_the_redirect_token_round_trips_to_what_render_compares() {
		$this->seed_pending_enrollment();

		$user = get_user_by( 'id', $this->user_id );
		$url  = Two_Factor_Enrollment_Onboarding::filter_login_redirect( admin_url(), admin_url(), $user );

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertArrayHasKey( Two_Factor_Enrollment_Onboarding::TOKEN_ARG, $query );
		$this->assertTrue(
			hash_equals(
				Two_Factor_Enrollment_Onboarding::pending_token( $this->user_id ),
				$query[ Two_Factor_Enrollment_Onboarding::TOKEN_ARG ]
			)
		);
	}

	/**
	 * The finding this token exists for.
	 *
	 * render() is a state-changing GET: it generates the user's only backup
	 * codes, enables the provider, and consumes the one-time screen. A single
	 * cross-site click by a logged-in user in the pending state would burn all
	 * of that with the codes never displayed. Without a token on the request
	 * the user must be passed through to their destination, and nothing may be
	 * generated or consumed.
	 */
	public function test_render_refuses_an_untokened_request_without_consuming_the_screen() {
		$this->seed_pending_enrollment();

		wp_set_current_user( $this->user_id );

		$_REQUEST['redirect_to'] = admin_url();
		unset( $_REQUEST[ Two_Factor_Enrollment_Onboarding::TOKEN_ARG ] );

		add_filter( 'wp_redirect', array( $this, 'abort_on_redirect' ) );

		try {
			Two_Factor_Enrollment_Onboarding::render();
			$this->fail( 'render() must redirect an untokened request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'aborted at redirect', $e->getMessage() );
		}

		$this->assertSame( admin_url(), $this->redirected_to );

		// Nothing consumed: the marker still owes the screen, the token still
		// works, and no codes were generated behind the user's back.
		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
		$this->assertNotSame( '', Two_Factor_Enrollment_Onboarding::pending_token( $this->user_id ) );
		$this->assertSame( '', get_user_meta( $this->user_id, '_two_factor_backup_codes', true ) );
	}

	public function test_render_refuses_a_wrong_token_without_consuming_the_screen() {
		$token = $this->seed_pending_enrollment();

		wp_set_current_user( $this->user_id );

		$_REQUEST['redirect_to']                                 = admin_url();
		$_REQUEST[ Two_Factor_Enrollment_Onboarding::TOKEN_ARG ] = strrev( $token );

		add_filter( 'wp_redirect', array( $this, 'abort_on_redirect' ) );

		try {
			Two_Factor_Enrollment_Onboarding::render();
			$this->fail( 'render() must redirect a mismatched token.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'aborted at redirect', $e->getMessage() );
		}

		$this->assertSame( admin_url(), $this->redirected_to );

		$this->assertSame( $token, Two_Factor_Enrollment_Onboarding::pending_token( $this->user_id ) );
		$this->assertSame( '', get_user_meta( $this->user_id, '_two_factor_backup_codes', true ) );
	}

	/**
	 * This screen is the lockout-recovery path. Codes the user cannot save are
	 * codes the user will not have, so the download link is load-bearing — and
	 * it has to be the JS-free data: URI, because this plugin ships no scripts
	 * and phpcs.xml.dist is written on that assumption.
	 */
	public function test_render_body_offers_a_download_link_for_the_codes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111', 'bbbb2222' ), admin_url() );

		$this->assertStringContainsString( 'href="data:application/text;charset=utf-8,', $html );
		$this->assertStringContainsString( 'download="two-factor-backup-codes.txt"', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_codes_download_link_contains_every_code() {
		$link = Two_Factor_Enrollment_Onboarding::codes_download_link( array( 'aaaa1111', 'bbbb2222' ) );

		$this->assertStringContainsString( rawurlencode( '1. aaaa1111' ), $link );
		$this->assertStringContainsString( rawurlencode( '2. bbbb2222' ), $link );
	}

	public function test_render_body_omits_the_download_link_when_there_are_no_codes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array(), admin_url() );

		$this->assertStringNotContainsString( 'data:application/text', $html );
	}

	/**
	 * "They will not be shown again" is true of these particular codes but
	 * reads as though recovery is now impossible. A signed-in user can
	 * generate a fresh set from their profile at any time, and the copy has to
	 * say both.
	 */
	public function test_render_body_does_not_overstate_the_permanence_of_the_codes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111' ), admin_url() );

		$this->assertStringContainsString( 'generate a new set from your profile', $html );
	}

	public function test_render_body_lists_backup_codes() {
		$codes = array( 'aaaa1111', 'bbbb2222' );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $codes, admin_url() );

		$this->assertStringContainsString( 'aaaa1111', $html );
		$this->assertStringContainsString( 'bbbb2222', $html );
	}

	public function test_render_body_offers_totp_upgrade() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array(), admin_url() );

		$this->assertStringContainsString( 'profile.php', $html );
	}

	public function test_render_body_includes_continue_link_to_redirect_target() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array(), 'https://example.org/wp-admin/edit.php' );

		$this->assertStringContainsString( 'https://example.org/wp-admin/edit.php', $html );
	}

	public function test_render_body_escapes_codes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( '<script>x</script>' ), admin_url() );

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

		$html = Two_Factor_Enrollment_Onboarding::render_body( $codes, admin_url() );

		$this->assertStringNotContainsString( 'Save your backup codes', $html );
	}
}
