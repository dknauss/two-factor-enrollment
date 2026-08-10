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
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_enqueue_scripts' ) );
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
	 * keeps the download working with JavaScript off or unavailable: this
	 * plugin does ship a small inline script and stylesheet for this screen
	 * (see login_enqueue_scripts()), but they are progressive enhancement —
	 * click-to-copy and presentation only. The download link itself, and the
	 * codes it contains, never depend on either one running.
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
	 * Build the clipboard payload for the click-to-copy button: the codes,
	 * newline-separated, and nothing else.
	 *
	 * This format is a deliberate choice, not a leftover default:
	 *
	 * - One code per line is what a human pastes into a password manager
	 *   note, a text file, or a printed sheet without editing first. Comma-
	 *   or tab-separated would need cleaning up before it is usable.
	 * - The line ending is "\r\n", matching codes_download_link()'s file
	 *   output below, not a bare "\n". A paste target that only recognizes
	 *   CRLF (a Windows-flavored text field being the common case) would
	 *   otherwise render an LF-only payload as one run-together line —
	 *   indistinguishable from the very concatenation bug this method exists
	 *   to avoid, just introduced one layer down. Keeping both methods on
	 *   the same line ending means there is exactly one behavior to reason
	 *   about, not two that quietly disagree.
	 * - No numbering. The "1." "2." prefixes in codes_download_link()'s file
	 *   and in the on-screen list are visual aids for reading a list; in
	 *   stored text they are noise, and they corrupt the value if someone
	 *   pastes a single line into a code-entry field.
	 * - No site name or URL header, deliberately, even though
	 *   codes_download_link()'s downloaded *file* carries one. The clipboard
	 *   is a more exposed surface than a file the user chose to save:
	 *   clipboard managers persist history, and any application the user
	 *   focuses next can read it. The codes alone are meaningless to anyone
	 *   who does not already know where they came from, so the clipboard
	 *   copy should carry less identifying context than the file, not the
	 *   same amount.
	 *
	 * @param string[] $codes The freshly generated codes.
	 * @return string
	 */
	public static function clipboard_payload( $codes ) {
		return implode( "\r\n", $codes );
	}

	/**
	 * Whether the current wp-login.php request is this screen's own action.
	 *
	 * login_enqueue_scripts fires from inside login_header(), before this
	 * class's render() has run far enough to offer any other hook to key
	 * off of, so this reads $_REQUEST['action'] directly -- the same source
	 * wp-login.php itself resolves the $action global from. This is a
	 * read-only comparison against a public query argument with no
	 * state-changing effect of its own; it authorizes nothing the way the
	 * one-time token in render() does, it only decides whether this
	 * screen's CSS and JS are enqueued.
	 *
	 * @return bool
	 */
	private static function is_this_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison against the current login action; nothing here changes state.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		return self::ACTION === $action;
	}

	/**
	 * Enqueue this screen's presentation assets.
	 *
	 * Scoped to this screen's own wp-login.php action via is_this_screen(),
	 * so none of this reaches the ordinary login screen or the two-factor
	 * challenge screen itself -- both share the same 'login' style handle
	 * this hooks into.
	 *
	 * The 'login' style handle is already registered and enqueued by the
	 * time login_enqueue_scripts fires (wp_enqueue_style( 'login' ) runs
	 * earlier in login_header()), so wp_add_inline_style() can attach to it
	 * directly without registering anything of its own.
	 *
	 * There is no equivalent shared script handle on the login page, so a
	 * placeholder handle (no src) is registered here purely to carry the
	 * inline script via wp_add_inline_script(); this does not add a script
	 * asset file, and the plugin still ships none.
	 *
	 * @return void
	 */
	public static function login_enqueue_scripts() {
		if ( ! self::is_this_screen() ) {
			return;
		}

		wp_add_inline_style( 'login', self::inline_style() );

		wp_register_script( 'two-factor-enrollment-onboarding', false, array(), TWO_FACTOR_ENROLLMENT_VERSION, true );
		wp_enqueue_script( 'two-factor-enrollment-onboarding' );
		wp_add_inline_script( 'two-factor-enrollment-onboarding', self::inline_script() );
	}

	/**
	 * CSS for this screen, scoped entirely under its own body class so
	 * nothing here can leak onto the ordinary login screen or the
	 * two-factor challenge screen.
	 *
	 * This is a static string with no interpolated data of any kind, so
	 * there is nothing here for esc_attr()/esc_html() to protect against --
	 * the appropriate "escaping" for trusted, developer-authored CSS handed
	 * to wp_add_inline_style() is exactly that: a constant string, never
	 * built from request or user data.
	 *
	 * @return string
	 */
	private static function inline_style() {
		return '
			body.login-action-two_factor_enrollment_onboarding #login {
				width: 100%;
				max-width: 480px;
				padding-top: 60px;
			}

			/* The WordPress.org logo above the login form is an off-site
			 * link sitting directly above one-time recovery codes; following
			 * it would abandon this screen and lose the codes for good.
			 *
			 * This hides the link visually rather than removing the <h1>
			 * itself. WordPress 7.0 gives the page a second, separate
			 * <h1 class="screen-reader-text"> outside #login, but on 6.5-6.8
			 * (the stated minimum WordPress version) #login h1 is the only
			 * heading on the page; `display: none` on the whole element
			 * would strip it from the accessibility tree entirely. The clip
			 * technique below mirrors the .screen-reader-text rule
			 * WordPress core itself uses: the link (and the logo image it
			 * carries) disappears from the screen and cannot be clicked,
			 * but the heading and its text remain for assistive
			 * technology. */
			body.login-action-two_factor_enrollment_onboarding #login h1 a {
				border: 0;
				clip: rect(1px, 1px, 1px, 1px);
				clip-path: inset(50%);
				height: 1px;
				margin: -1px;
				overflow: hidden;
				padding: 0;
				position: absolute;
				width: 1px;
				word-wrap: normal;
			}

			/* wp-includes/css/buttons.css sets `.wp-core-ui .button` to
			 * `display: inline-block`, and login_header() puts wp-core-ui on
			 * <body>. That author-origin declaration beats the [hidden]
			 * attribute UA-stylesheet default of display:none, so the copy
			 * button below -- which starts hidden until inline_script()
			 * confirms navigator.clipboard is available -- would otherwise
			 * render as a full, non-functional button on any page where the
			 * script never runs (plain HTTP, JS disabled, a strict CSP). The
			 * specificity of this rule, (0,3,1), beats the (0,2,0) of
			 * .wp-core-ui .button, so it wins regardless of stylesheet
			 * order. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-copy[hidden] {
				display: none;
			}

			/* Same mechanism as the copy button above, same reason: the
			 * acknowledgement ships `hidden` and is revealed by the inline
			 * script, so a browser that never runs it is never shown a gate
			 * that cannot be enforced. The UA stylesheet default for
			 * [hidden] is display:none, but this screen inherits plenty of
			 * author-origin display rules from wp-core-ui and login.css,
			 * and one landing on this selector would render a live-looking
			 * checkbox that gates nothing. Stated explicitly so it cannot. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-acknowledge[hidden] {
				display: none;
			}

			/* Qualified `p.` and scoped inside the card, at (0,3,2), so it
			 * outranks the generic `… .two-factor-enrollment-onboarding p`
			 * rule at (0,2,2). Written as a bare class selector this is
			 * (0,2,1) and loses: the declarations parse, apply to nothing,
			 * and the block silently renders at the body size. The same
			 * applies to every <p> on this screen that wants its own size or
			 * spacing; see the sibling rules below. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding p.two-factor-enrollment-acknowledge {
				margin: 0 0 16px;
				font-size: 13px;
				line-height: 1.5;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-acknowledge label {
				display: flex;
				align-items: flex-start;
				gap: 8px;
				cursor: pointer;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-acknowledge input {
				margin: 2px 0 0;
				flex: 0 0 auto;
			}

			/* The blocked state is styling only -- preventDefault() in the
			 * inline script is what actually stops the navigation. This
			 * exists so the button does not look clickable while it is not,
			 * and it is keyed off the aria-disabled attribute the script
			 * sets, so the visual state and the announced state cannot drift
			 * apart. Deliberately not the core `.button.disabled` class:
			 * buttons.css styles it with !important throughout, which would
			 * make the enabled state unrecoverable once applied. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-continue-button[aria-disabled="true"] {
				opacity: 0.5;
				cursor: not-allowed;
			}

			/* Only ever rendered when the two_factor_enrollment_onboarding_header
			 * filter returns markup, so these rules describe a block that
			 * does not exist on a default install. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-header {
				margin: 0 0 20px;
				text-align: center;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-header img {
				max-width: 100%;
				height: auto;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding {
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
				padding: 32px 32px 24px;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-section {
				margin: 0 0 28px;
				padding: 0 0 28px;
				border-bottom: 1px solid #dcdcde;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-section:last-child {
				margin-bottom: 0;
				padding-bottom: 0;
				border-bottom: none;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding h2,
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding h3 {
				margin: 0 0 12px;
				font-size: 20px;
				line-height: 1.3;
				font-weight: 600;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding h3 {
				font-size: 16px;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding p {
				margin: 0 0 16px;
				font-size: 14px;
				line-height: 1.6;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding p:last-child {
				margin-bottom: 0;
			}

			/* Secondary, not hidden: the caveat carries the one piece of
			 * reassurance on this screen -- that a lost set can be
			 * regenerated -- so it is set quieter than the instruction it
			 * follows without dropping to a size that reads as fine print
			 * nobody is expected to have seen. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding p.two-factor-enrollment-caveat {
				font-size: 13px;
				color: #50575e;
			}

			/* Qualified for specificity; see the acknowledgement rule above. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-onboarding p.two-factor-enrollment-method {
				display: flex;
				align-items: center;
				gap: 10px;
				margin: 0 0 8px;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-method-name {
				font-size: 14px;
				color: #1d2327;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-method-icon {
				flex: 0 0 auto;
				color: #50575e;
			}

			/* A state chip, not a warning: this is the normal state of an
			 * offer the user has not taken up yet, so it stays neutral grey
			 * rather than borrowing any of the notice colours. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-badge {
				display: inline-block;
				background: #f0f0f1;
				border: 1px solid #dcdcde;
				border-radius: 9px;
				padding: 1px 10px;
				font-size: 12px;
				line-height: 1.6;
				color: #50575e;
				white-space: nowrap;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-credential-block {
				background: #f6f7f7;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 16px 20px 8px;
				margin: 0 0 20px;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-codes {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 8px 20px;
				margin: 0;
				padding: 0;
				list-style: none;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-codes li {
				margin: 0 0 8px;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-codes code {
				display: block;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 3px;
				padding: 6px 10px;
				font-family: Consolas, Monaco, monospace;
				font-size: 15px;
				letter-spacing: 0.03em;
				color: #1d2327;
				text-align: center;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-actions {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 8px;
				margin: 0;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-actions .button-primary {
				padding: 4px 20px;
				height: auto;
				line-height: 2;
			}

			/* wp-admin/css/login.css floats every .button-primary on login
			 * screens (`.login .button-primary { float: right; }`,
			 * login.css:208-210). .two-factor-enrollment-actions is
			 * `display: flex` above, and flex items never float -- the
			 * float computes to its used value of `none` per spec -- so
			 * the Download codes button inside it is unaffected and needs
			 * no override here.
			 *
			 * The Continue button below has no such protection: its
			 * container, .two-factor-enrollment-continue, is a plain <p>
			 * and the last child of the card
			 * (.two-factor-enrollment-onboarding). A floated child does
			 * not contribute to the height of its container, so without
			 * this rule the button renders outside the bottom border of
			 * the card. Core hits the identical problem and fixes it the
			 * identical way at login.css:248-250
			 * (`.login .admin-email__actions .button-primary { float:
			 * none; ... }`): neutralise the float and control alignment
			 * from the containing element instead of clearing it. */
			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-continue {
				text-align: right;
			}

			body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-continue .button-primary {
				float: none;
			}

			@media (max-width: 380px) {
				body.login-action-two_factor_enrollment_onboarding .two-factor-enrollment-codes {
					grid-template-columns: 1fr;
				}
			}
		';
	}

	/**
	 * JS for this screen's two progressive enhancements: the acknowledgement
	 * gate on Continue, and click-to-copy.
	 *
	 * They are two separate IIFEs, and that separation is load-bearing. The
	 * clipboard block returns early when navigator.clipboard is missing —
	 * which is every plain-HTTP install — so anything sharing its function
	 * body would silently never run there. The gate is the more important of
	 * the two and must not inherit an unrelated feature-detect's early exit.
	 *
	 * The gate: Continue is a plain working <a href> in the markup, and the
	 * acknowledgement checkbox ships `hidden`. This block reveals the
	 * checkbox and only then arms the gate, so the enhancement appears
	 * exactly when it can be enforced. It never touches the href — blocking
	 * is preventDefault() on the click, with aria-disabled and a class for
	 * the visual and assistive-technology state — so a script that fails
	 * halfway leaves a working link rather than a dead one. It is armed only
	 * when all three elements are found; on a render with no codes there is
	 * no checkbox and Continue is left entirely alone.
	 *
	 * Blocking moves focus to the checkbox rather than disabling anything.
	 * The same reasoning as the copy button's status span applies: setting
	 * .disabled on a focused control throws focus to <body>, and a link is
	 * not disable-able anyway.
	 *
	 * navigator.clipboard is undefined outside a secure context (plain HTTP,
	 * broadly), so the copy button ships `hidden` in the markup and this
	 * only reveals it once the API is confirmed present -- a page with no
	 * JavaScript, or JavaScript that finds no clipboard API, simply leaves
	 * the button hidden rather than showing one that would silently do
	 * nothing. The download link needs none of this and keeps working
	 * either way.
	 *
	 * Passing feature detection only proves navigator.clipboard.writeText
	 * exists, not that a call to it will succeed: a clipboard-write
	 * permissions policy, a cross-origin iframe, an unfocused document, or
	 * the user simply denying the prompt can all still reject the promise.
	 * The .catch() below is not a no-op for that reason -- it swaps the
	 * button to a distinct failure label and updates the status span so the
	 * user is told the copy did not happen and is pointed at the download
	 * button, which does not depend on any of this.
	 *
	 * The status span is a separate element from the button, not an
	 * aria-live region on the button itself, because setting
	 * button.disabled while the button holds focus would forcibly move
	 * keyboard focus to <body>. The label swap on the button is feedback
	 * enough for sighted users; the sibling status span (role="status", so
	 * implicitly aria-live="polite") is what a screen reader announces,
	 * without ever touching focus or disabling the control.
	 *
	 * This is a static string with no interpolated data of any kind -- the
	 * translated labels and the codes themselves live in the markup's data
	 * attributes, already escaped there with esc_attr() (and, for the codes
	 * payload, wp_json_encode() underneath that -- see render_body() and
	 * clipboard_payload()), not built into this string. Reading the payload
	 * from a data attribute rather than re-deriving it by scraping and
	 * joining the on-screen code nodes here keeps the newline-separated,
	 * no-numbering, no-title format defined in exactly one place
	 * (clipboard_payload()) instead of two that could drift apart.
	 *
	 * @return string
	 */
	private static function inline_script() {
		return '
			( function () {
				var acknowledge = document.querySelector( ".two-factor-enrollment-acknowledge" );
				var acknowledgeInput = document.querySelector( ".two-factor-enrollment-acknowledge-input" );
				var continueButton = document.querySelector( ".two-factor-enrollment-continue-button" );

				if ( acknowledge && acknowledgeInput && continueButton ) {
					var syncGate = function () {
						if ( acknowledgeInput.checked ) {
							continueButton.removeAttribute( "aria-disabled" );
							continueButton.classList.remove( "two-factor-enrollment-blocked" );
						} else {
							continueButton.setAttribute( "aria-disabled", "true" );
							continueButton.classList.add( "two-factor-enrollment-blocked" );
						}
					};

					acknowledgeInput.addEventListener( "change", syncGate );

					continueButton.addEventListener( "click", function ( event ) {
						if ( acknowledgeInput.checked ) {
							return;
						}

						// Stop the navigation and put the user on the thing
						// that is blocking them, rather than leaving them to
						// work out why a button did nothing.
						event.preventDefault();
						acknowledgeInput.focus();
					} );

					acknowledge.hidden = false;
					syncGate();
				}
			} )();

			( function () {
				if ( ! window.navigator || ! navigator.clipboard || ! navigator.clipboard.writeText ) {
					return;
				}

				var button = document.querySelector( ".two-factor-enrollment-copy" );

				if ( ! button ) {
					return;
				}

				var status = document.querySelector( ".two-factor-enrollment-copy-status" );
				var resetTimer = null;

				var announce = function ( label, statusText ) {
					var originalLabel = button.getAttribute( "data-default-label" );

					window.clearTimeout( resetTimer );

					button.textContent = label;

					if ( status ) {
						status.textContent = statusText;
					}

					resetTimer = window.setTimeout( function () {
						button.textContent = originalLabel;

						if ( status ) {
							status.textContent = "";
						}
					}, 2000 );
				};

				button.hidden = false;

				button.addEventListener( "click", function () {
					var payload = JSON.parse( button.getAttribute( "data-codes-payload" ) );

					navigator.clipboard.writeText( payload ).then( function () {
						announce( button.getAttribute( "data-copied-label" ), button.getAttribute( "data-copied-status" ) );
					} ).catch( function () {
						// The feature-detect above only confirms the API exists, not that
						// a write will succeed (a permissions policy, a cross-origin
						// frame, an unfocused document, or a user denial can still
						// reject it). Tell the user nothing was copied and point them at
						// the download button, which always works.
						announce( button.getAttribute( "data-failed-label" ), button.getAttribute( "data-failed-status" ) );
					} );
				} );
			} )();
		';
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

		/**
		 * Optional markup rendered above the onboarding card.
		 *
		 * This screen has no WordPress logo: the default one linked to
		 * wordpress.org from directly above one-time recovery codes, and
		 * following any outbound link here costs the user those codes for
		 * good — the screen is consumed before it renders. Removing it left
		 * a space a site deploying this for its own users has an obvious use
		 * for, and this filter is that space.
		 *
		 * The default is an empty string and must stay one. Shipping a
		 * linked mark by default would reintroduce exactly the problem the
		 * logo removal solved. A site that supplies markup containing a link
		 * has made that decision for itself, knowing its own users; this
		 * plugin cannot make it for them.
		 *
		 * The value is run through wp_kses_post() before output.
		 *
		 * @param string $header Markup to render above the card. Default ''.
		 */
		$header = apply_filters( 'two_factor_enrollment_onboarding_header', '' );
		$header = wp_kses_post( (string) $header );

		ob_start();
		?>
		<?php if ( '' !== trim( $header ) ) : ?>
			<div class="two-factor-enrollment-header">
				<?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Site-supplied markup, sanitized with wp_kses_post() above; escaping it again would render the tags as text and defeat the filter's purpose. ?>
			</div>
		<?php endif; ?>

		<div class="two-factor-enrollment-onboarding">
			<section class="two-factor-enrollment-section">
				<h2><?php esc_html_e( 'Two-factor authentication is on', 'two-factor-enrollment' ); ?></h2>

				<p>
					<?php esc_html_e( 'From now on, signing in will send a one-time code to your email address.', 'two-factor-enrollment' ); ?>
				</p>
			</section>

			<?php if ( ! empty( $codes ) ) : ?>
				<section class="two-factor-enrollment-section">
					<h3><?php esc_html_e( 'Save your backup codes', 'two-factor-enrollment' ); ?></h3>

					<?php
					/*
					 * Instruction first, caveat second and quieter. As one
					 * paragraph the only sentence the user has to act on —
					 * save these now — sat in the middle, behind a
					 * conditional about losing email access and ahead of a
					 * clause about regenerating them later.
					 *
					 * Every clause here earned its place and none may be
					 * dropped in the name of brevity. The regeneration
					 * clause in particular was added after a review found
					 * the copy overstated the permanence of the codes:
					 * "will not be shown again" is true of these codes but
					 * reads as though recovery is now impossible, which
					 * would frighten a user who has already lost them into
					 * thinking there is nothing left to do. The suite
					 * asserts all of them survive.
					 */
					?>
					<p>
						<?php esc_html_e( 'Save these somewhere safe now. If you ever lose access to your email, they are the only way back into your account, and each code works once.', 'two-factor-enrollment' ); ?>
					</p>

					<p class="two-factor-enrollment-caveat">
						<?php esc_html_e( 'They will not be shown again, though you can generate a new set from your profile any time you are signed in.', 'two-factor-enrollment' ); ?>
					</p>

					<div class="two-factor-enrollment-credential-block">
						<ul class="two-factor-enrollment-codes">
							<?php foreach ( $codes as $code ) : ?>
								<li><code><?php echo esc_html( $code ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					</div>

					<p class="two-factor-enrollment-actions">
						<a class="button button-primary two-factor-enrollment-download" href="<?php echo esc_url( self::codes_download_link( $codes ), array( 'data' ) ); ?>" download="two-factor-backup-codes.txt">
							<?php esc_html_e( 'Download codes', 'two-factor-enrollment' ); ?>
						</a>
						<?php
						/*
						 * The payload is built once, in PHP, by clipboard_payload() --
						 * codes separated by "\r\n" and nothing else -- then carried to
						 * the click handler as JSON (wp_json_encode()) inside an
						 * HTML attribute (esc_attr()). Double-escaped for its two
						 * nested contexts: JSON for the JS string the click handler
						 * will JSON.parse(), then HTML-attribute escaping around
						 * that. This is the "properly escaped for its context" rule
						 * the brief calls out, and it is why this is wp_json_encode()
						 * rather than hand-built string concatenation.
						 *
						 * wp_json_encode() can return false (e.g. a payload containing
						 * invalid UTF-8). Practically unreachable here -- the payload is
						 * this plugin's own freshly generated backup codes, never
						 * arbitrary input -- but esc_attr( false ) is '', and
						 * JSON.parse( '' ) throws uncaught in the click handler.
						 * The fallback below (a valid JSON string) costs nothing and
						 * keeps that failure mode from ever reaching the browser. It
						 * is written as an explicit if(), not the `?:` short-ternary
						 * form, only because the project coding standard forbids
						 * short ternaries; the behavior is the same either way, and
						 * on the PHP 7.2 floor both forms are available (`??=` is
						 * not, and is not used here).
						 */
						$codes_payload_json = wp_json_encode( self::clipboard_payload( $codes ) );

						if ( false === $codes_payload_json ) {
							$codes_payload_json = '""';
						}
						?>
						<button type="button" class="button two-factor-enrollment-copy" hidden
							data-default-label="<?php echo esc_attr__( 'Copy codes', 'two-factor-enrollment' ); ?>"
							data-copied-label="<?php echo esc_attr__( 'Copied!', 'two-factor-enrollment' ); ?>"
							data-copied-status="<?php echo esc_attr__( 'Codes copied to the clipboard.', 'two-factor-enrollment' ); ?>"
							data-failed-label="<?php echo esc_attr__( "Couldn't copy", 'two-factor-enrollment' ); ?>"
							data-failed-status="<?php echo esc_attr__( "Couldn't copy the codes automatically. Use the download button instead.", 'two-factor-enrollment' ); ?>"
							data-codes-payload="<?php echo esc_attr( $codes_payload_json ); ?>"
						>
							<?php esc_html_e( 'Copy codes', 'two-factor-enrollment' ); ?>
						</button>
						<span class="two-factor-enrollment-copy-status screen-reader-text" role="status"></span>
					</p>
				</section>
			<?php endif; ?>

			<section class="two-factor-enrollment-section">
				<h3><?php esc_html_e( 'Want something faster?', 'two-factor-enrollment' ); ?></h3>

				<?php
				/*
				 * "Not set up" is a statement of fact, not a guess. This
				 * screen is only reachable with the onboarding marker in the
				 * 'pending' state, which only the gate sets, and only for a
				 * user who had no configured provider at all when they
				 * signed in. A user who already had an authenticator app
				 * never enrolls through this plugin and never arrives here.
				 */
				?>
				<p class="two-factor-enrollment-method">
					<?php
					/*
					 * Inline SVG rather than dashicons: the login screen
					 * never enqueues that stylesheet, so the alternative is
					 * loading an entire icon font for one glyph. It is
					 * decorative — the text beside it already says
					 * "Authenticator app" — so it is hidden from assistive
					 * technology and taken out of the tab order, and it
					 * inherits currentColor so it tracks the label rather
					 * than needing its own colour rule.
					 */
					?>
					<svg class="two-factor-enrollment-method-icon" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
						<rect x="5.75" y="2.75" width="8.5" height="14.5" rx="1.75" fill="none" stroke="currentColor" stroke-width="1.5"/>
						<path d="M8.75 14.75h2.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
					</svg>
					<strong class="two-factor-enrollment-method-name"><?php esc_html_e( 'Authenticator app', 'two-factor-enrollment' ); ?></strong>
					<span class="two-factor-enrollment-badge"><?php esc_html_e( 'Not set up', 'two-factor-enrollment' ); ?></span>
				</p>

				<p>
					<?php esc_html_e( 'An authenticator app generates codes instantly, with no waiting for email. You can set one up any time from your profile.', 'two-factor-enrollment' ); ?>
				</p>

				<p>
					<a href="<?php echo esc_url( $profile_url ); ?>">
						<?php esc_html_e( 'Set up an authenticator app', 'two-factor-enrollment' ); ?>
					</a>
				</p>
			</section>

			<?php if ( ! empty( $codes ) ) : ?>
				<?php
				/*
				 * The acknowledgement gate. It ships `hidden` and the inline
				 * script reveals it, which is the whole contract: with
				 * JavaScript off, or blocked by a CSP, the checkbox never
				 * appears and Continue behaves exactly as it did before this
				 * feature existed.
				 *
				 * Rendering it visible-but-inert instead would be worse than
				 * omitting it. It would tell the user something is being
				 * enforced while nothing is, and any attempt to make it
				 * enforce server-side — a form post, a required attribute —
				 * risks the one outcome this screen must never produce: a
				 * user who cannot leave. The marker is already 'done' by the
				 * time this renders, so a user stuck here has no second
				 * chance at the screen, and the screen is the lockout
				 * recovery path.
				 *
				 * Only rendered alongside real codes. With the codes section
				 * omitted there is nothing to have saved, and the question
				 * would be unanswerable.
				 */
				?>
				<p class="two-factor-enrollment-acknowledge" hidden>
					<label>
						<input type="checkbox" class="two-factor-enrollment-acknowledge-input">
						<span><?php esc_html_e( 'I’ve saved these codes somewhere safe', 'two-factor-enrollment' ); ?></span>
					</label>
				</p>
			<?php endif; ?>

			<p class="two-factor-enrollment-continue">
				<a class="button button-primary two-factor-enrollment-continue-button" href="<?php echo esc_url( $redirect_to ); ?>">
					<?php esc_html_e( 'Continue', 'two-factor-enrollment' ); ?>
				</a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
