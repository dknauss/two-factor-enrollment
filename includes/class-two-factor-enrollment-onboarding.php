<?php
/**
 * One-time post-login onboarding screen.
 *
 * @package Two_Factor_Enrollment
 */

defined( 'ABSPATH' ) || exit;

/**
 * Offers backup codes and the TOTP upgrade after a user's first two-factored login.
 *
 * This screen is convenience, not a security boundary: any session reaching it
 * has already passed a real two-factor challenge.
 */
class Two_Factor_Enrollment_Onboarding {

	/**
	 * User meta key tracking onboarding-screen state.
	 *
	 * This is a tri-state flag, not a boolean, and is the only state this
	 * plugin persists:
	 *
	 * - absent           This plugin never enrolled the user. Never show the
	 *                    screen. This covers users who configured two-factor
	 *                    (e.g. TOTP) before this plugin was ever installed;
	 *                    they must not be diverted to a screen they never
	 *                    asked for.
	 * - 'pending:TOKEN'  This plugin enrolled the user via the gate. The
	 *                    screen is owed and has not yet been shown, and TOKEN
	 *                    is the one-time secret that authorizes showing it.
	 * - 'done'           The screen has been shown.
	 *
	 * Comparisons against this meta value must never use truthiness — both the
	 * absent ('') and 'done' states must read as "do not show the screen". Use
	 * needs_onboarding(), which knows about the 'pending' prefix.
	 *
	 * Flipping the marker to 'done' destroys the token, which is what makes
	 * the screen single-use by construction.
	 *
	 * @var string
	 */
	const ONBOARDED_META_KEY = '_two_factor_enrollment_onboarded';

	/**
	 * Meta value prefix recorded once the plugin has enrolled a user and the
	 * screen is owed. The token follows, separated by a colon.
	 *
	 * @var string
	 */
	const STATE_PENDING = 'pending';

	/**
	 * Meta value recorded once the screen has been shown.
	 *
	 * @var string
	 */
	const STATE_DONE = 'done';

	/**
	 * The wp-login.php action slug for this screen.
	 *
	 * @var string
	 */
	const ACTION = 'two_factor_enrollment_onboarding';

	/**
	 * Query argument carrying the one-time token.
	 *
	 * @var string
	 */
	const TOKEN_ARG = 'two_factor_enrollment_token';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 20, 3 );
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'render' ) );
	}

	/**
	 * Mint a fresh 'pending' marker value, token and all.
	 *
	 * The format of the marker is this class's business, so the gate asks for
	 * a value rather than assembling one.
	 *
	 * @return string
	 */
	public static function new_pending_marker() {
		return self::STATE_PENDING . ':' . wp_generate_password( 32, false, false );
	}

	/**
	 * The one-time token stored in the user's 'pending' marker.
	 *
	 * @param int $user_id The user ID.
	 * @return string The token, or an empty string when the user is not in the
	 *                pending state or their marker predates tokens.
	 */
	public static function pending_token( $user_id ) {
		$state = get_user_meta( (int) $user_id, self::ONBOARDED_META_KEY, true );

		if ( ! is_string( $state ) ) {
			return '';
		}

		$prefix = self::STATE_PENDING . ':';

		if ( 0 !== strpos( $state, $prefix ) ) {
			return '';
		}

		return substr( $state, strlen( $prefix ) );
	}

	/**
	 * Whether this user should see the onboarding screen.
	 *
	 * True only when this plugin is the one that enrolled the user, i.e. the
	 * onboarding marker is in the 'pending' state. A user whose two-factor
	 * setup predates this plugin (marker absent) or who has already seen the
	 * screen (marker 'done') is never diverted here.
	 *
	 * A bare 'pending' with no token still reads as owed, so the state stays
	 * honest, but render() will refuse to show the screen without a token to
	 * check and will pass the user straight through to their destination.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function needs_onboarding( $user = null ) {
		$user = Two_Factor_Enrollment::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		if ( ! Two_Factor_Enrollment::is_complete( $user ) ) {
			return false;
		}

		$state = get_user_meta( $user->ID, self::ONBOARDED_META_KEY, true );

		if ( ! is_string( $state ) ) {
			return false;
		}

		if ( self::STATE_PENDING === $state ) {
			return true;
		}

		return 0 === strpos( $state, self::STATE_PENDING . ':' );
	}

	/**
	 * Record that the screen has been shown.
	 *
	 * This destroys the one-time token along with the pending state.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 */
	public static function mark_onboarded( $user_id ) {
		update_user_meta( (int) $user_id, self::ONBOARDED_META_KEY, self::STATE_DONE );
	}

	/**
	 * Atomically claim the onboarding screen for this request.
	 *
	 * Two concurrent requests can carry the same valid token — a double-click,
	 * or the same link open in two tabs — and both would pass the caller's
	 * hash_equals() check before either write lands. Without a compare-and-swap
	 * here, both requests would generate a fresh set of ten backup codes and
	 * both would enable the provider; the second write wins, and the *other*
	 * tab displays codes that were never stored. For a recovery path, that is
	 * the worst available outcome: the user saves codes that silently do not
	 * work, and only finds out while locked out.
	 *
	 * update_user_meta()'s optional $prev_value argument turns the write into
	 * a real compare-and-swap at the database level: the row is only updated
	 * if its current value still matches $prev_value. The first request to
	 * call this swaps 'pending:TOKEN' for 'done' and wins the claim; a
	 * concurrent second request finds the row no longer matches (the first
	 * request already changed it) and gets false back.
	 *
	 * Caller contract: this must run after the token has already been
	 * verified with hash_equals() against pending_token(), and before any
	 * codes are generated or anything is rendered. A false return means
	 * another request already claimed the screen; the caller must redirect
	 * to the validated destination without generating codes or rendering.
	 *
	 * That ordering — claim before render — has a real cost: if the request
	 * dies between the claim landing and the browser receiving the page, the
	 * user never sees their codes, and the screen does not come back (the
	 * marker is already 'done'). That trade is intentional. The codes were
	 * still generated and Two_Factor_Backup_Codes is still enabled regardless
	 * of whether the page renders, the user holds a valid session at this
	 * point, and render_body()'s own copy already tells them they can
	 * generate a fresh set from their profile at any time. So this failure
	 * mode is an inconvenience — reload the profile page instead of this
	 * one-time screen — whereas the race it replaces could hand someone codes
	 * that do not work, discovered only once they are locked out. Do not
	 * "fix" this back to claiming after render; that reintroduces the race
	 * this method exists to close.
	 *
	 * Note on update_user_meta()'s return value: it returns false both when
	 * no row matched $prev_value and when the stored value already equals
	 * $meta_value. Those two cases are indistinguishable from the return
	 * value alone. They cannot collide for this specific call, because the
	 * swap always moves 'pending:TOKEN' to 'done' — two different strings —
	 * so a false here always means "no matching row", never "already equal".
	 * A future caller reusing this pattern for a same-to-same swap would not
	 * get that guarantee and must not rely on this method's return value
	 * meaning the same thing.
	 *
	 * @param WP_User $user  The user attempting to claim the screen.
	 * @param string  $token The token already verified against pending_token().
	 * @return bool True if this request won the claim, false if another
	 *              request already claimed it first.
	 */
	public static function claim_onboarding( $user, $token ) {
		return (bool) update_user_meta(
			$user->ID,
			self::ONBOARDED_META_KEY,
			self::STATE_DONE,
			self::STATE_PENDING . ':' . $token
		);
	}

	/**
	 * Build the onboarding screen URL, carrying the user's one-time token.
	 *
	 * Two_Factor_Core::login_url() already runs urlencode_deep() over every
	 * param value before building the query string, so $redirect_to is
	 * passed through raw here — encoding it first would double-encode it.
	 *
	 * @param string           $redirect_to Where to send the user afterwards.
	 * @param WP_User|int|null $user        The user the token belongs to.
	 *                                      Defaults to the current user.
	 * @return string
	 */
	public static function url( $redirect_to, $user = null ) {
		$args = array(
			'action'      => self::ACTION,
			'redirect_to' => $redirect_to,
		);

		$user = Two_Factor_Enrollment::fetch_user( $user );

		if ( $user ) {
			$token = self::pending_token( $user->ID );

			if ( '' !== $token ) {
				$args[ self::TOKEN_ARG ] = $token;
			}
		}

		return Two_Factor_Core::login_url( $args );
	}

	/**
	 * Divert a fresh login to the onboarding screen.
	 *
	 * @param string           $redirect_to           The redirect destination.
	 * @param string           $requested_redirect_to The requested destination.
	 * @param WP_User|WP_Error $user                  User on success, WP_Error on failure.
	 * @return string
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( ! self::needs_onboarding( $user ) ) {
			return $redirect_to;
		}

		return self::url( $redirect_to, $user );
	}

	/**
	 * Render the onboarding screen and exit.
	 *
	 * This is a state-changing GET: it generates backup codes, enables the
	 * Backup Codes provider, and consumes the one-time screen. It is therefore
	 * authorized by the token minted with the pending marker, without which a
	 * single cross-site click could burn a user's recovery screen — generating
	 * codes and flipping the marker to 'done' while the user never saw them.
	 *
	 * wp_create_nonce() cannot stand in for that token. It folds
	 * wp_get_session_token() into its hash, wp_get_session_token() reads
	 * $_COOKIE[ LOGGED_IN_COOKIE ], and wp_set_auth_cookie() never populates
	 * $_COOKIE within the same request. A nonce minted at `login_redirect`
	 * time is therefore built from an empty session token and verified against
	 * the real one on the next request: it fails every time.
	 *
	 * The token check alone is not enough to make this single-request-safe:
	 * two requests carrying the same valid token (double-click, two tabs) can
	 * both pass hash_equals() before either has written anything. The claim
	 * below closes that race; see claim_onboarding()'s docblock for the full
	 * reasoning and the trade-off it accepts.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only destination, validated below and by wp_safe_redirect().
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();
		$redirect_to = wp_validate_redirect( $redirect_to, admin_url() );

		if ( ! self::needs_onboarding( $user ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$stored = self::pending_token( $user->ID );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This token is the request's authentication; see the docblock for why a nonce cannot be used.
		$supplied = isset( $_REQUEST[ self::TOKEN_ARG ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::TOKEN_ARG ] ) ) : '';

		if ( '' === $stored || '' === $supplied || ! hash_equals( $stored, $supplied ) ) {
			// Send them on their way without consuming the screen: the marker
			// stays 'pending', no codes are generated, and the real link still
			// works.
			wp_safe_redirect( $redirect_to );
			exit;
		}

		/*
		 * Claim the screen before doing anything else. Two requests carrying
		 * the same valid token (double-click, the same link open in two tabs)
		 * can both reach this point before either has written anything; the
		 * claim is a compare-and-swap against the database, so only one of
		 * them can win it. A losing request has already been out-raced by a
		 * winner that will generate and display the real codes, so it is sent
		 * on to the destination with nothing generated here. See
		 * claim_onboarding()'s docblock for why claiming here, before
		 * generating codes or rendering, is the correct trade-off.
		 */
		if ( ! self::claim_onboarding( $user, $supplied ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$codes = self::maybe_generate_backup_codes( $user );

		login_header( __( 'Two-Factor Authentication', 'two-factor-enrollment' ) );

		echo self::render_body( $codes, $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped within render_body().

		login_footer();

		exit;
	}

	/**
	 * Generate backup codes for a user who has none, and make them a usable
	 * login method.
	 *
	 * Two_Factor_Backup_Codes::generate_codes() by itself is not sufficient
	 * for this screen's purpose, for two reasons:
	 *
	 * 1. It only writes the code hashes to BACKUP_CODES_META_KEY. It never
	 *    adds Two_Factor_Backup_Codes to ENABLED_PROVIDERS_USER_META_KEY, and
	 *    Two_Factor_Core::get_available_providers_for_user() only offers
	 *    providers that are both enabled and configured. Skipping the enable
	 *    step would show the user real-looking codes that silently do
	 *    nothing at login — the worst outcome for a recovery path.
	 * 2. Called without 'method' => 'append', it *replaces* any codes the
	 *    user already has, invalidating them. That is why the zero-check
	 *    below exists: this method only ever calls generate_codes() after
	 *    Two_Factor_Backup_Codes::codes_remaining_for_user() has confirmed
	 *    the count is exactly zero, at which point replace and append are
	 *    semantically identical — there is nothing left to preserve.
	 *
	 * 'method' => 'append' is deliberately *not* passed, and must not be
	 * restored as a "second line of defense". On the append path,
	 * generate_codes() does
	 * `(array) get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true )`.
	 * get_user_meta() with $single = true returns '' when the key is
	 * absent, and `(array) ''` evaluates to `array( '' )`, not `array()` —
	 * a phantom empty-string hash is prepended ahead of the ten real ones.
	 * It can never validate (wp_check_password() against '' never matches)
	 * and nothing ever removes it, so codes_remaining_for_user() overcounts
	 * by one for the life of the account: a user who has spent all ten real
	 * codes is told one remains, on the exact screen this plugin exists to
	 * provide as a lockout-recovery path. The zero-check is the only guard
	 * this call needs; the append flag adds nothing on top of it and only
	 * corrupts the result.
	 *
	 * Enabling never disturbs the rest of the enabled-providers list or the
	 * primary provider, which must stay Email.
	 *
	 * @param WP_User $user The user to generate codes for.
	 * @return string[] Freshly generated (unhashed) codes, or an empty array
	 *                   if Backup Codes is unavailable or the user already
	 *                   has codes.
	 */
	public static function maybe_generate_backup_codes( $user ) {
		if ( ! class_exists( 'Two_Factor_Backup_Codes' ) ) {
			return array();
		}

		$providers = Two_Factor_Core::get_providers();

		if ( ! isset( $providers['Two_Factor_Backup_Codes'] ) ) {
			return array();
		}

		if ( 0 !== Two_Factor_Backup_Codes::codes_remaining_for_user( $user ) ) {
			return array();
		}

		$codes = Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );

		/*
		 * The enabled-providers list is read from and written to user meta by
		 * hand here, deliberately. Two_Factor_Core::enable_provider_for_user()
		 * reads the current list via get_enabled_providers_for_user(), which
		 * fires this plugin's own two_factor_enabled_providers_for_user
		 * filter — so using the core API would persist whatever the gate
		 * injected at runtime, and the injection-is-runtime-only invariant is
		 * what lets this plugin be removed without a trace. Do not "simplify"
		 * this to the core API.
		 */
		$enabled = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		if ( ! is_array( $enabled ) ) {
			$enabled = array();
		}

		if ( ! in_array( 'Two_Factor_Backup_Codes', $enabled, true ) ) {
			$enabled[] = 'Two_Factor_Backup_Codes';
			update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array_values( $enabled ) );
		}

		return $codes;
	}

	/**
	 * Build a `data:` URI that downloads the codes as a text file.
	 *
	 * Two Factor core builds its own download link the same way (see
	 * Two_Factor_Backup_Codes::rest_generate_codes()). Reusing the pattern
	 * keeps this screen JS-free and asset-free, which phpcs.xml.dist relies on
	 * — this plugin ships no scripts and no styles by design.
	 *
	 * @param string[] $codes The freshly generated codes.
	 * @return string
	 */
	public static function codes_download_link( $codes ) {
		$title = sprintf(
			/* translators: %s: the site's domain */
			__( 'Two-Factor Recovery Codes for %s', 'two-factor-enrollment' ),
			home_url( '/' )
		);

		$link  = 'data:application/text;charset=utf-8,';
		$link .= rawurlencode( "{$title}\r\n\r\n" );

		$i = 1;

		foreach ( $codes as $code ) {
			$link .= rawurlencode( "{$i}. {$code}\r\n" );
			++$i;
		}

		return $link;
	}

	/**
	 * Build the onboarding screen markup.
	 *
	 * @param array  $codes       Freshly generated backup codes, or an empty
	 *                            array to omit that section (Backup Codes
	 *                            disabled site-wide, or the user already
	 *                            has codes).
	 * @param string $redirect_to Where the Continue link points.
	 * @return string
	 */
	public static function render_body( $codes, $redirect_to ) {
		$profile_url = admin_url( 'profile.php#two-factor-options' );

		ob_start();
		?>
		<div class="two-factor-enrollment-onboarding">
			<h2><?php esc_html_e( 'Two-factor authentication is on', 'two-factor-enrollment' ); ?></h2>

			<p>
				<?php esc_html_e( 'From now on, signing in will send a one-time code to your email address.', 'two-factor-enrollment' ); ?>
			</p>

			<?php if ( ! empty( $codes ) ) : ?>
				<h3><?php esc_html_e( 'Save your backup codes', 'two-factor-enrollment' ); ?></h3>

				<p>
					<?php esc_html_e( 'If you ever lose access to your email, these codes are the only way back into your account. Each one works once. Save them somewhere safe now — these codes will not be shown again, though you can generate a new set from your profile any time you are signed in.', 'two-factor-enrollment' ); ?>
				</p>

				<ul class="two-factor-enrollment-codes">
					<?php foreach ( $codes as $code ) : ?>
						<li><code><?php echo esc_html( $code ); ?></code></li>
					<?php endforeach; ?>
				</ul>

				<p class="two-factor-enrollment-download">
					<a href="<?php echo esc_url( self::codes_download_link( $codes ), array( 'data' ) ); ?>" download="two-factor-backup-codes.txt">
						<?php esc_html_e( 'Download codes', 'two-factor-enrollment' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Want something faster?', 'two-factor-enrollment' ); ?></h3>

			<p>
				<?php esc_html_e( 'An authenticator app generates codes instantly, with no waiting for email. You can set one up any time from your profile.', 'two-factor-enrollment' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( $profile_url ); ?>">
					<?php esc_html_e( 'Set up an authenticator app', 'two-factor-enrollment' ); ?>
				</a>
			</p>

			<p class="two-factor-enrollment-continue">
				<a class="button button-primary" href="<?php echo esc_url( $redirect_to ); ?>">
					<?php esc_html_e( 'Continue', 'two-factor-enrollment' ); ?>
				</a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
