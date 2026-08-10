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
		unset( $_REQUEST[ Two_Factor_Enrollment_Onboarding::TOKEN_ARG ], $_REQUEST['redirect_to'], $_REQUEST['action'] );

		// login_enqueue_scripts() registers and enqueues this handle purely to
		// carry an inline script; leaving it registered would let one test's
		// enqueue call read as "already enqueued" in the next.
		wp_dequeue_script( 'two-factor-enrollment-onboarding' );
		wp_deregister_script( 'two-factor-enrollment-onboarding' );

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
	 * The direct simulation of two concurrent requests carrying the same
	 * valid token: a double-click, or the same link open in two tabs. Both
	 * would pass render()'s hash_equals() check before either has written
	 * anything, so the claim itself is what has to arbitrate between them.
	 * Calling it twice in sequence with the same token *is* that race,
	 * collapsed onto one thread — the first call is the winning request, the
	 * second is the one that arrives just after and must lose.
	 */
	public function test_claim_onboarding_succeeds_once_and_fails_on_replay() {
		$token = $this->seed_pending_enrollment();

		$user = get_user_by( 'id', $this->user_id );

		$first  = Two_Factor_Enrollment_Onboarding::claim_onboarding( $user, $token );
		$second = Two_Factor_Enrollment_Onboarding::claim_onboarding( $user, $token );

		$this->assertTrue( $first, 'The first request to claim the screen must win.' );
		$this->assertFalse( $second, 'A second claim with the same token must lose once the first has landed.' );
	}

	public function test_claim_onboarding_leaves_the_marker_exactly_done() {
		$token = $this->seed_pending_enrollment();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Onboarding::claim_onboarding( $user, $token );

		$this->assertSame(
			'done',
			get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true )
		);
	}

	/**
	 * A claim with the wrong token must not match the compare-and-swap's
	 * expected previous value, so it fails and the marker is left exactly as
	 * it was — still pending, still carrying the original token, ready for a
	 * request that actually has it.
	 */
	public function test_claim_onboarding_fails_with_wrong_token_and_leaves_marker_untouched() {
		$token = $this->seed_pending_enrollment();

		$user = get_user_by( 'id', $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::claim_onboarding( $user, strrev( $token ) );

		$this->assertFalse( $result );
		$this->assertSame(
			'pending:' . $token,
			get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true )
		);
	}

	/**
	 * A claim against a marker that has already moved to 'done' — the state
	 * a losing concurrent request, or a stale link clicked after the screen
	 * was already shown, would find — must fail rather than silently
	 * succeeding a second time.
	 */
	public function test_claim_onboarding_fails_when_marker_already_done() {
		$token = $this->seed_pending_enrollment();

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$result = Two_Factor_Enrollment_Onboarding::claim_onboarding( $user, $token );

		$this->assertFalse( $result );
		$this->assertSame(
			'done',
			get_user_meta( $this->user_id, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true )
		);
	}

	/**
	 * This screen is the lockout-recovery path. Codes the user cannot save are
	 * codes the user will not have, so the download link is load-bearing — and
	 * it has to be the JS-free data: URI, because it must keep working with
	 * JavaScript disabled or unavailable. This plugin does ship one small
	 * inline script for click-to-copy (see login_enqueue_scripts()), but
	 * render_body() itself — the HTML this test inspects — never emits a
	 * `<script>` tag; the script is injected separately, from a conditional
	 * hook, only for this screen's own action. It is also styled as the
	 * primary button, not a bare inline link.
	 */
	public function test_render_body_offers_a_download_link_for_the_codes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111', 'bbbb2222' ), admin_url() );

		$this->assertStringContainsString( 'href="data:application/text;charset=utf-8,', $html );
		$this->assertStringContainsString( 'download="two-factor-backup-codes.txt"', $html );
		$this->assertStringContainsString( 'class="button button-primary two-factor-enrollment-download"', $html );
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
	 * Regression test for a dependency bug: Two_Factor_Backup_Codes::generate_codes()
	 * called with 'method' => 'append' does
	 * `(array) get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true )`,
	 * and get_user_meta() with $single = true returns '' when the meta key is
	 * absent. `(array) ''` is `array( '' )`, not `array()`, so the ten real
	 * codes land on top of a phantom empty-string entry — eleven stored
	 * hashes for ten generated codes. That entry can never validate and is
	 * never removed, so codes_remaining_for_user() permanently overcounts by
	 * one. This method must not pass 'method' => 'append' for exactly this
	 * reason; see maybe_generate_backup_codes()'s docblock.
	 */
	public function test_maybe_generate_backup_codes_does_not_inflate_remaining_count() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$this->assertSame(
			Two_Factor_Backup_Codes::NUMBER_OF_CODES,
			Two_Factor_Backup_Codes::codes_remaining_for_user( $user )
		);
	}

	/**
	 * Companion to the count regression above: proves the corruption
	 * directly against stored meta rather than through the derived count,
	 * and confirms the number of stored hashes matches the number of codes
	 * handed back to the caller for display.
	 */
	public function test_maybe_generate_backup_codes_stores_no_empty_hash() {
		$user = get_user_by( 'id', $this->user_id );

		$codes = Two_Factor_Enrollment_Onboarding::maybe_generate_backup_codes( $user );

		$stored = get_user_meta( $this->user_id, '_two_factor_backup_codes', true );

		$this->assertIsArray( $stored );
		$this->assertNotContains( '', $stored, 'A stored backup-code hash was an empty string.' );
		$this->assertSame( count( $codes ), count( $stored ) );
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

	/**
	 * The codes stay a <ul> for its semantics as a list of ten discrete
	 * values, but must not read as prose bullets. The class it carries is
	 * exactly what login_enqueue_scripts()'s CSS targets to strip the
	 * markers — see test_login_enqueue_scripts_removes_bullets_from_the_codes_list().
	 */
	public function test_render_body_codes_are_a_list_carrying_the_no_bullet_style_hook() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111', 'bbbb2222' ), admin_url() );

		$this->assertStringContainsString( '<ul class="two-factor-enrollment-codes">', $html );
	}

	/**
	 * The copy button is an addition, never a replacement: it only appears
	 * alongside real codes, ships hidden by default (see
	 * test_login_enqueue_scripts_ships_the_copy_button_hidden_by_default()),
	 * and is a real <button>, not a click handler bolted onto a <div>. It
	 * carries no aria-live of its own — see
	 * test_render_body_includes_a_status_span_for_copy_feedback() for where
	 * that lives instead, and why.
	 */
	public function test_render_body_includes_a_copy_button_when_codes_are_present() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111', 'bbbb2222' ), admin_url() );

		$this->assertStringContainsString( '<button type="button" class="button two-factor-enrollment-copy" hidden', $html );
		$this->assertStringContainsString( 'data-default-label="Copy codes"', $html );
		$this->assertStringContainsString( 'data-copied-label="Copied!"', $html );
		$this->assertStringNotContainsString( 'aria-live', $html );
	}

	/**
	 * The empty .catch() finding: a rejected clipboard write (a permissions
	 * policy denial, a cross-origin frame, an unfocused document) must not
	 * leave the user with silent nothing. The button needs a distinct
	 * failure label and status text pointing at the always-working download
	 * button, both provided as data attributes for the inline script to
	 * read.
	 */
	public function test_render_body_copy_button_carries_failure_feedback_data_attributes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111', 'bbbb2222' ), admin_url() );

		$this->assertStringContainsString( 'data-failed-label="Couldn&#039;t copy"', $html );
		$this->assertStringContainsString( 'download button instead', $html );
	}

	/**
	 * Requirement 7's other half: setting button.disabled while the button
	 * holds focus would forcibly move keyboard focus to <body>, so
	 * copy/failure feedback is announced via a sibling status element
	 * instead of an aria-live region on the button itself. role="status" is
	 * implicitly aria-live="polite" without needing the attribute spelled
	 * out, and screen-reader-text keeps it inaudible-but-present rather than
	 * a second visible message competing with the button's own label swap.
	 */
	public function test_render_body_includes_a_status_span_for_copy_feedback() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111', 'bbbb2222' ), admin_url() );

		$this->assertStringContainsString( '<span class="two-factor-enrollment-copy-status screen-reader-text" role="status">', $html );
	}

	/**
	 * No codes, nothing to copy: the whole codes section — download link
	 * included — is already omitted when there are no codes to show, and the
	 * copy button and its status span must not be exceptions.
	 */
	public function test_render_body_omits_the_copy_button_when_there_are_no_codes() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array(), admin_url() );

		$this->assertStringNotContainsString( 'two-factor-enrollment-copy', $html );
	}

	/**
	 * The defect this test exists to catch: the clipboard copy must join the
	 * codes with a real separator (one per line), not run them together into
	 * a single indistinguishable string. A test that only checks for the
	 * copy button's existence — as test_render_body_includes_a_copy_button_
	 * when_codes_are_present() above does — would not catch a regression
	 * here; this test inspects the actual payload value.
	 *
	 * The separator is "\r\n", matching codes_download_link()'s file output,
	 * not a bare "\n" — see clipboard_payload()'s docblock for why an
	 * LF-only payload pasted into a CRLF-only target is functionally the
	 * same failure this test guards against.
	 */
	public function test_clipboard_payload_joins_codes_with_newlines_only() {
		$codes = array( 'aaaa1111', 'bbbb2222', 'cccc3333' );

		$payload = Two_Factor_Enrollment_Onboarding::clipboard_payload( $codes );

		$this->assertSame( "aaaa1111\r\nbbbb2222\r\ncccc3333", $payload );
	}

	/**
	 * The direct negative case: this is exactly what the reported defect
	 * produced — all ten codes concatenated with no separator at all. Naming
	 * the wrong shape explicitly, rather than only asserting the right one,
	 * is what makes this test discriminate against a regression back to it.
	 */
	public function test_clipboard_payload_is_not_the_naive_concatenation() {
		$codes = array( 'aaaa1111', 'bbbb2222', 'cccc3333' );

		$payload = Two_Factor_Enrollment_Onboarding::clipboard_payload( $codes );

		$this->assertNotSame( implode( '', $codes ), $payload );
		$this->assertStringNotContainsString( 'aaaa1111bbbb2222', $payload );
	}

	/**
	 * "Nothing else" means nothing else: no numbering (a corrupting no-op if
	 * pasted into a single code field) and no site name or URL (deliberately
	 * less identifying context than the downloaded file carries — see
	 * clipboard_payload()'s docblock). codes_download_link() is the file's
	 * equivalent and is the one place this context belongs.
	 */
	public function test_clipboard_payload_omits_numbering_and_title() {
		$codes = array( 'aaaa1111', 'bbbb2222' );

		$payload = Two_Factor_Enrollment_Onboarding::clipboard_payload( $codes );

		$this->assertStringNotContainsString( '1. aaaa1111', $payload );
		$this->assertStringNotContainsString( 'Two-Factor Recovery Codes', $payload );
		$this->assertStringNotContainsString( home_url( '/' ), $payload );
	}

	/**
	 * The round-trip that actually matters: what a browser will do with the
	 * markup render_body() emits. This decodes the data-codes-payload
	 * attribute exactly the way the inline script does — HTML-attribute
	 * decoding (what esc_attr() produced), then JSON.parse() (what
	 * wp_json_encode() produced) — and confirms the result is the real,
	 * newline-joined clipboard_payload() value, not the ten codes run
	 * together. Unlike a substring check against the raw HTML, this proves
	 * the value that actually reaches navigator.clipboard.writeText().
	 */
	public function test_render_body_embeds_the_clipboard_payload_as_escaped_json() {
		$codes = array( 'aaaa1111', 'bbbb2222', 'cccc3333' );

		$html = Two_Factor_Enrollment_Onboarding::render_body( $codes, admin_url() );

		$found = preg_match( '/data-codes-payload="([^"]*)"/', $html, $matches );
		$this->assertSame( 1, $found, 'The copy button must carry a data-codes-payload attribute.' );

		$decoded_json = html_entity_decode( $matches[1], ENT_QUOTES );
		$payload      = json_decode( $decoded_json );

		$this->assertSame( Two_Factor_Enrollment_Onboarding::clipboard_payload( $codes ), $payload );
		$this->assertSame( "aaaa1111\r\nbbbb2222\r\ncccc3333", $payload );
		$this->assertNotSame( implode( '', $codes ), $payload );
	}

	/**
	 * The download link is the fallback the copy button is not: it must be
	 * a plain, always-present <a href> with no hidden attribute and no
	 * dependency on the inline script ever running, so it keeps working with
	 * JavaScript disabled or navigator.clipboard unavailable.
	 */
	public function test_render_body_download_link_has_no_hidden_attribute_and_works_without_js() {
		$html = Two_Factor_Enrollment_Onboarding::render_body( array( 'aaaa1111' ), admin_url() );

		$start = strpos( $html, '<a class="button button-primary two-factor-enrollment-download"' );

		// (int) strpos( ... ) silently reads a not-found (false) result as 0
		// and would slice the *start* of the markup instead of failing, so a
		// reordered or renamed download link would make this assertion
		// inspect the wrong text and still pass. Fail loudly instead.
		$this->assertNotFalse( $start, 'The download link markup was not found in render_body() output.' );

		$download_tag = substr( $html, $start );

		$end = strpos( $download_tag, '>' );
		$this->assertNotFalse( $end, 'The download link tag was not terminated.' );

		$download_tag = substr( $download_tag, 0, $end + 1 );

		$this->assertStringNotContainsString( 'hidden', $download_tag );
	}

	/**
	 * login_enqueue_scripts() must be wired up, or none of the CSS/JS below
	 * this point ever reaches the browser.
	 */
	public function test_login_enqueue_scripts_is_registered_on_the_hook() {
		$this->assertNotFalse(
			has_action( 'login_enqueue_scripts', array( 'Two_Factor_Enrollment_Onboarding', 'login_enqueue_scripts' ) )
		);
	}

	/**
	 * The whole point of gating on the current action: this hook fires on
	 * every wp-login.php request, for every action, so a request for the
	 * ordinary login screen (or any other screen) must leave no trace behind
	 * — no registered script handle, nothing enqueued, and no inline CSS
	 * attached to the shared 'login' style handle either.
	 *
	 * The wp_script_is() assertion alone does not exercise the
	 * wp_add_inline_style( 'login', ... ) call: that call attaches to a
	 * handle the ordinary login screen also uses, rather than registering
	 * anything of its own, so a version of login_enqueue_scripts() that
	 * called it unconditionally — above, rather than below, the
	 * is_this_screen() guard — would still pass a script-only assertion.
	 * Registering and enqueuing a stub 'login' handle first, the way the
	 * real login_header() would have by the time this hook fires, makes
	 * wp_styles()->get_data( 'login', 'after' ) the thing that actually
	 * proves the CSS is scoped to this screen's own action.
	 */
	public function test_login_enqueue_scripts_does_nothing_off_its_own_screen() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		$_REQUEST['action'] = 'login';

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$this->assertFalse( wp_script_is( 'two-factor-enrollment-onboarding', 'registered' ) );
		$this->assertEmpty( wp_styles()->get_data( 'login', 'after' ) );
	}

	/**
	 * Same guard, the other near-miss: no action at all (the default the
	 * ordinary login screen is reached with) must also leave nothing
	 * enqueued and no inline CSS attached to 'login'. See the docblock above
	 * for why the style assertion, not just the script assertion, is what
	 * actually discriminates here.
	 */
	public function test_login_enqueue_scripts_does_nothing_without_an_action() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		unset( $_REQUEST['action'] );

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$this->assertFalse( wp_script_is( 'two-factor-enrollment-onboarding', 'registered' ) );
		$this->assertEmpty( wp_styles()->get_data( 'login', 'after' ) );
	}

	/**
	 * On this screen's own action, the inline script must actually be
	 * enqueued — registering it and never enqueuing would silently ship
	 * nothing to the browser.
	 */
	public function test_login_enqueue_scripts_enqueues_the_inline_script_for_this_screen() {
		$_REQUEST['action'] = Two_Factor_Enrollment_Onboarding::ACTION;

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$this->assertTrue( wp_script_is( 'two-factor-enrollment-onboarding', 'enqueued' ) );
	}

	/**
	 * The inline script must feature-detect navigator.clipboard rather than
	 * assuming it exists (it is undefined outside a secure context, which
	 * covers plenty of real WordPress installs on plain HTTP), and must
	 * reveal the copy button only once that check passes — it ships hidden
	 * in the markup for exactly this reason.
	 *
	 * That is only half the guarantee. The `hidden` attribute on its own
	 * does not hide the button: wp-includes/css/buttons.css sets
	 * `.wp-core-ui .button { display: inline-block }`, an author-origin
	 * declaration that beats the `[hidden]` UA-stylesheet default, and
	 * login_header() puts `wp-core-ui` on `<body>`. Asserting only the JS
	 * *source text* (as this test previously did in full) would stay green
	 * even if the CSS half of the fix were missing or reverted — the exact
	 * shape of the Critical finding this pair of assertions exists to catch.
	 * So this also inspects the emitted CSS itself and confirms it actually
	 * computes to `display: none` for a hidden button, not just that the
	 * script intends to unhide one.
	 */
	public function test_login_enqueue_scripts_ships_the_copy_button_hidden_by_default() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		$_REQUEST['action'] = Two_Factor_Enrollment_Onboarding::ACTION;

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$script = implode( "\n", (array) wp_scripts()->get_data( 'two-factor-enrollment-onboarding', 'after' ) );

		$this->assertStringContainsString( 'navigator.clipboard', $script );
		$this->assertStringContainsString( 'button.hidden = false', $script );

		$style = implode( "\n", (array) wp_styles()->get_data( 'login', 'after' ) );

		$this->assertMatchesRegularExpression(
			'/\.two-factor-enrollment-copy\[hidden\]\s*\{[^}]*display:\s*none/',
			$style,
			'The emitted CSS must actually hide the [hidden] copy button, not just carry a script that intends to reveal it.'
		);
	}

	/**
	 * Requirement 2: no bullets on the codes. The list-style reset has to be
	 * scoped to this screen's own class, or it leaks onto the ordinary login
	 * and two-factor challenge screens.
	 */
	public function test_login_enqueue_scripts_removes_bullets_from_the_codes_list() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		$_REQUEST['action'] = Two_Factor_Enrollment_Onboarding::ACTION;

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$style = implode( "\n", (array) wp_styles()->get_data( 'login', 'after' ) );

		$this->assertStringContainsString( 'login-action-two_factor_enrollment_onboarding .two-factor-enrollment-codes', $style );
		$this->assertStringContainsString( 'list-style: none', $style );
	}

	/**
	 * Requirement 1: the WordPress.org logo sits directly above one-time
	 * recovery codes and links off-site; following it loses them for good.
	 * Hiding it must be scoped to this screen's body class alone.
	 *
	 * This targets `#login h1 a`, not `#login h1` itself, and checks for the
	 * clip/absolute-position technique rather than `display: none`. On
	 * WordPress 6.5-6.8 (the README's stated floor) that `<h1>` is the only
	 * heading on the page; hiding the whole element with `display: none`
	 * would strip it from the accessibility tree, not just the screen. See
	 * inline_style()'s docblock for the full reasoning.
	 */
	public function test_login_enqueue_scripts_hides_the_wordpress_logo_for_this_screen_only() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		$_REQUEST['action'] = Two_Factor_Enrollment_Onboarding::ACTION;

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$style = implode( "\n", (array) wp_styles()->get_data( 'login', 'after' ) );

		$this->assertMatchesRegularExpression(
			'/login-action-two_factor_enrollment_onboarding #login h1 a\s*\{[^}]*clip:/',
			$style
		);

		// The <h1> element itself must not be hidden outright -- only the
		// off-site link inside it -- or a 6.5-6.8 install loses its only
		// heading.
		$this->assertDoesNotMatchRegularExpression(
			'/login-action-two_factor_enrollment_onboarding #login h1\s*\{[^}]*display:\s*none/',
			$style
		);
	}

	/**
	 * Requirement 7: the 320px default login box cramps ten codes and three
	 * paragraphs into a scroll. The widened container must stay well short
	 * of the 1000px upstream used for a screen with far more content.
	 */
	public function test_login_enqueue_scripts_widens_the_login_container_modestly() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		$_REQUEST['action'] = Two_Factor_Enrollment_Onboarding::ACTION;

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$style = implode( "\n", (array) wp_styles()->get_data( 'login', 'after' ) );

		$this->assertStringContainsString( 'login-action-two_factor_enrollment_onboarding #login {', $style );
		$this->assertMatchesRegularExpression( '/max-width:\s*\d+px/', $style );
		$this->assertStringNotContainsString( 'max-width: 1000px', $style );
	}

	/**
	 * wp-admin/css/login.css floats every .button-primary on login screens
	 * (`.login .button-primary { float: right; }`). The Continue button is
	 * `<a class="button button-primary">`, the last child of the card
	 * (.two-factor-enrollment-onboarding), inside a plain <p> that neither
	 * clears nor otherwise contains that float -- so without an override
	 * the button renders outside the card's bottom border instead of
	 * contributing to its height.
	 *
	 * This asserts the emitted CSS actually neutralises the float on the
	 * Continue button specifically (`.two-factor-enrollment-continue
	 * .button-primary { float: none; ... }`), the same fix core applies to
	 * its own equivalent case at login.css:248-250
	 * (`.admin-email__actions .button-primary`). Asserting only that
	 * *some* `float: none` appears in the stylesheet would stay green even
	 * if the rule were scoped to the wrong selector, or removed and
	 * replaced with an unrelated `float: none` elsewhere -- the regex
	 * anchors on the `.two-factor-enrollment-continue .button-primary`
	 * selector to rule that out.
	 */
	public function test_login_enqueue_scripts_neutralises_the_float_on_the_continue_button() {
		wp_register_style( 'login', false, array(), '1.0' );
		wp_enqueue_style( 'login' );

		$_REQUEST['action'] = Two_Factor_Enrollment_Onboarding::ACTION;

		Two_Factor_Enrollment_Onboarding::login_enqueue_scripts();

		$style = implode( "\n", (array) wp_styles()->get_data( 'login', 'after' ) );

		$this->assertMatchesRegularExpression(
			'/login-action-two_factor_enrollment_onboarding \.two-factor-enrollment-continue \.button-primary\s*\{[^}]*float:\s*none/',
			$style,
			'The Continue button must have its core-imposed float neutralised, or it renders outside the card.'
		);

		// The neutralised float leaves the button with no other source of
		// alignment, so the containing <p> must right-align it itself --
		// otherwise the button silently reverts to the left edge instead
		// of just failing to escape the card.
		$this->assertMatchesRegularExpression(
			'/login-action-two_factor_enrollment_onboarding \.two-factor-enrollment-continue\s*\{[^}]*text-align:\s*right/',
			$style,
			'The Continue button\'s container must right-align it now that float is no longer doing so.'
		);
	}
}
