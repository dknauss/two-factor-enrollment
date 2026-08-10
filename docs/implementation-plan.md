> **Historical record.** This is the build plan as written before implementation; several of its code samples contain defects that were corrected while building. See [README.md](README.md) in this directory
> for where this was superseded, and the repository root `README.md` for what
> the plugin does today.

# Two-Factor Enrollment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone WordPress add-on plugin that enrolls policy-covered users in two-factor authentication before an auth cookie is ever issued, by pre-enabling the Email provider so the Two Factor plugin's existing login challenge performs the enrollment.

**Architecture:** A pure flow layer owning no enforcement policy. Two contract filters (`two_factor_enrollment_required`, `two_factor_enrollment_deferrable`) decide whether anything happens; both default to `false`, so the plugin is inert on install. Enrollment happens by injecting `Two_Factor_Email` into `two_factor_enabled_providers_for_user`, which makes `Two_Factor_Core::filter_authenticate()` suppress the auth cookie and run its normal challenge. A one-time post-login onboarding screen — reached only on an already-two-factored session — offers backup codes and the TOTP upgrade.

**Tech Stack:** PHP 7.2+, WordPress 6.5+, `@wordpress/env` (Docker), PHPUnit 9 with `yoast/phpunit-polyfills`, PHPCS with WPCS + VIPCS, PHPStan level 2. No JavaScript build step; no runtime JS dependencies.

## Global Constraints

- **New repository**, developed at `~/Code/two-factor-enrollment`. Not a directory inside the two-factor fork.
- **PHP floor: 7.2.24.** No typed properties, no arrow functions (`fn()`), no null-coalescing assignment (`??=`), no spread in array literals. Short array syntax and anonymous functions are fine.
- **WordPress floor: 6.5** (required for the `Requires Plugins` header).
- **Plugin slug:** `two-factor-enrollment`. **Text domain:** `two-factor-enrollment`. **Class prefix:** `Two_Factor_Enrollment`. **Hook prefix:** `two_factor_enrollment_`.
- **License:** GPL-2.0-or-later.
- **Coding standards:** WordPress-Extra plus WordPress-VIP-Go, matching the two-factor plugin. PHPCS must pass. File naming is WPCS convention: `class-two-factor-enrollment.php` for class `Two_Factor_Enrollment`.
- **Hard dependency on the Two Factor plugin.** Every entry point must no-op when `Two_Factor_Core` does not exist. Never fatal.
- **The plugin persists exactly one piece of its own state:** the user meta key `_two_factor_enrollment_onboarded`. No options, no settings, no policy state.
- **All line references to two-factor source are anchored to `upstream/master` at `6245be6`.** Re-derive before citing them anywhere public.

## Scope of this plan

This plan implements the **required path** from the spec (§6.1–6.3), plus safe degradation when the Email provider is unavailable (§6.4's admin notice, but not its wizard).

The **fallback enrollment wizard and its session lockdown** (spec §6.4 steps 1–4 and all of §7) are deliberately **out of scope here** and belong in a second plan. Rationale: the required path is complete, shippable software on its own, and the fallback is an independent subsystem with a materially different security model. Task 8 makes the plugin fail safe — do nothing, warn the admin — when Email is disabled site-wide, so shipping this plan alone is correct rather than half-finished.

---

## File Structure

| File | Responsibility |
|---|---|
| `two-factor-enrollment.php` | Plugin header, constants, guarded bootstrap, `add_hooks()` wiring |
| `includes/class-two-factor-enrollment.php` | Pure predicates. No output, no side effects |
| `includes/class-two-factor-enrollment-gate.php` | Email injection and post-auth persistence |
| `includes/class-two-factor-enrollment-onboarding.php` | One-time post-login screen: routing and rendering |
| `includes/class-two-factor-enrollment-notify.php` | Enrollment notification mail |
| `includes/class-two-factor-enrollment-admin.php` | Admin notice when Email is unavailable |
| `uninstall.php` | Removes `_two_factor_enrollment_onboarded` from all users |
| `tests/bootstrap.php` | Loads two-factor first, then this plugin |
| `tests/*.php` | One test file per class |

---

### Task 1: Repository scaffold and test harness

**Files:**
- Create: `~/Code/two-factor-enrollment/two-factor-enrollment.php`
- Create: `~/Code/two-factor-enrollment/composer.json`
- Create: `~/Code/two-factor-enrollment/package.json`
- Create: `~/Code/two-factor-enrollment/.wp-env.json`
- Create: `~/Code/two-factor-enrollment/phpunit.xml.dist`
- Create: `~/Code/two-factor-enrollment/phpcs.xml.dist`
- Create: `~/Code/two-factor-enrollment/.gitignore`
- Test: `~/Code/two-factor-enrollment/tests/bootstrap.php`
- Test: `~/Code/two-factor-enrollment/tests/test-bootstrap.php`

**Interfaces:**
- Consumes: nothing.
- Produces: constants `TWO_FACTOR_ENROLLMENT_DIR` (string, trailing slash) and `TWO_FACTOR_ENROLLMENT_VERSION` (string). A working `npm test`.

- [ ] **Step 1: Create the repository and directories**

```bash
mkdir -p ~/Code/two-factor-enrollment/includes ~/Code/two-factor-enrollment/tests
cd ~/Code/two-factor-enrollment
git init
```

- [ ] **Step 2: Write `composer.json`**

```json
{
	"name": "dknauss/two-factor-enrollment",
	"type": "wordpress-plugin",
	"description": "Two-factor enrollment onboarding flow for the Two Factor plugin.",
	"license": "GPL-2.0-or-later",
	"config": {
		"sort-packages": true,
		"platform": {
			"php": "7.2.24"
		},
		"allow-plugins": {
			"dealerdirect/phpcodesniffer-composer-installer": true
		}
	},
	"minimum-stability": "dev",
	"prefer-stable": true,
	"require": {
		"php": ">=7.2.24|^8"
	},
	"require-dev": {
		"automattic/vipwpcs": "^3.0",
		"dealerdirect/phpcodesniffer-composer-installer": "^1.0",
		"phpcompatibility/phpcompatibility-wp": "3.0.0-alpha2",
		"phpunit/phpunit": "^8.5|^9.6",
		"wp-coding-standards/wpcs": "^3.3",
		"yoast/phpunit-polyfills": "^4.0"
	},
	"scripts": {
		"lint": "phpcs",
		"test": "phpunit",
		"format": "phpcbf"
	}
}
```

- [ ] **Step 3: Write `package.json`**

The `composer` script path segment must match the plugin directory name inside the container.

```json
{
	"name": "two-factor-enrollment",
	"version": "0.1.0",
	"private": true,
	"scripts": {
		"env": "wp-env",
		"composer": "wp-env run tests-cli --env-cwd=wp-content/plugins/two-factor-enrollment composer --",
		"test": "npm run composer test",
		"lint": "npm run composer lint"
	},
	"devDependencies": {
		"@wordpress/env": "^10.0.0"
	}
}
```

- [ ] **Step 4: Write `.wp-env.json`**

`"WordPress/two-factor"` tells wp-env to pull that plugin from GitHub, so the dependency is present in the test container.

```json
{
	"phpVersion": "7.4",
	"plugins": [ ".", "WordPress/two-factor" ],
	"env": {
		"tests": {
			"config": {
				"WP_DEBUG": true,
				"WP_TESTS_EMAIL": "admin@example.org",
				"WP_TESTS_DOMAIN": "example.org",
				"WP_SITEURL": "https://example.org",
				"WP_HOME": "https://example.org"
			}
		}
	}
}
```

- [ ] **Step 5: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/bootstrap.php"
	backupGlobals="false"
	colors="true"
	cacheResultFile="./tests/.phpunit.result.cache"
	>
	<testsuites>
		<testsuite name="All Tests">
			<directory suffix=".php">tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 6: Write `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="Two Factor Enrollment">
	<description>Coding standards for the Two Factor Enrollment plugin.</description>

	<file>.</file>
	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/node_modules/*</exclude-pattern>

	<arg name="extensions" value="php"/>
	<arg name="colors"/>
	<arg value="sp"/>

	<rule ref="WordPress-Extra"/>
	<rule ref="WordPress-VIP-Go"/>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array" value="two-factor-enrollment"/>
		</properties>
	</rule>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array" value="two_factor_enrollment,Two_Factor_Enrollment,TWO_FACTOR_ENROLLMENT"/>
		</properties>
	</rule>

	<config name="testVersion" value="7.2-"/>
	<rule ref="PHPCompatibilityWP"/>
</ruleset>
```

- [ ] **Step 7: Write `.gitignore`**

```
/vendor/
/node_modules/
/tests/.phpunit.result.cache
```

- [ ] **Step 8: Write the plugin bootstrap `two-factor-enrollment.php`**

Note the `Requires Plugins` header and the hard guard: the plugin must never fatal when Two Factor is absent.

```php
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
```

- [ ] **Step 9: Write `tests/bootstrap.php`**

Two Factor must load before this plugin, since `two_factor_enrollment_bootstrap()` checks for `Two_Factor_Core`.

```php
<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package Two_Factor_Enrollment
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		$two_factor = dirname( dirname( dirname( __DIR__ ) ) ) . '/two-factor/two-factor.php';

		if ( ! file_exists( $two_factor ) ) {
			echo "Two Factor plugin not found at {$two_factor}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit( 1 );
		}

		require_once $two_factor;
		require_once dirname( __DIR__ ) . '/two-factor-enrollment.php';

		two_factor_enrollment_bootstrap();
	}
);

require_once $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 10: Write the failing smoke test `tests/test-bootstrap.php`**

```php
<?php
/**
 * Smoke tests for plugin loading.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Bootstrap extends WP_UnitTestCase {

	public function test_two_factor_core_is_available() {
		$this->assertTrue( class_exists( 'Two_Factor_Core' ) );
	}

	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'TWO_FACTOR_ENROLLMENT_DIR' ) );
		$this->assertTrue( defined( 'TWO_FACTOR_ENROLLMENT_VERSION' ) );
	}

	public function test_enrollment_class_is_loaded() {
		$this->assertTrue( class_exists( 'Two_Factor_Enrollment' ) );
	}
}
```

- [ ] **Step 11: Run the test to verify it fails**

```bash
cd ~/Code/two-factor-enrollment && npm install && npm run env start && npm test
```

Expected: FAIL on `test_enrollment_class_is_loaded` — `includes/class-two-factor-enrollment.php` does not exist yet, so `two_factor_enrollment_bootstrap()` fatals on the `require_once`. That fatal is the expected failure signal for this step; Task 2 creates the file.

- [ ] **Step 12: Create a stub so the harness boots**

```php
<?php
/**
 * Enrollment predicates.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Decides whether a user must enroll in two-factor authentication.
 */
class Two_Factor_Enrollment {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
	}
}
```

- [ ] **Step 13: Run the tests to verify they pass**

```bash
npm test
```

Expected: 3 passing tests.

- [ ] **Step 14: Commit**

```bash
git add -A
git commit -m "Scaffold plugin, test harness, and coding standards"
```

---

### Task 2: Enrollment predicates

**Files:**
- Modify: `includes/class-two-factor-enrollment.php`
- Test: `tests/test-enrollment.php`

**Interfaces:**
- Consumes: `TWO_FACTOR_ENROLLMENT_DIR`.
- Produces:
  - `Two_Factor_Enrollment::is_required( $user )` → `bool`. `$user` is `WP_User|int|null`.
  - `Two_Factor_Enrollment::is_deferrable( $user )` → `bool`.
  - `Two_Factor_Enrollment::is_complete( $user )` → `bool`.
  - `Two_Factor_Enrollment::email_provider_available()` → `bool`.
  - `Two_Factor_Enrollment::EMAIL_PROVIDER` → `'Two_Factor_Email'`.
  - `Two_Factor_Enrollment::fetch_user( $user )` → `WP_User|false`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for enrollment predicates.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_enrollment_deferrable' );
		remove_all_filters( 'two_factor_providers' );
		parent::tear_down();
	}

	public function test_is_required_defaults_to_false() {
		$this->assertFalse( Two_Factor_Enrollment::is_required( $this->user_id ) );
	}

	public function test_is_required_honors_filter() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		$this->assertTrue( Two_Factor_Enrollment::is_required( $this->user_id ) );
	}

	public function test_is_required_filter_receives_user_object() {
		$seen = null;
		add_filter(
			'two_factor_enrollment_required',
			function ( $required, $user ) use ( &$seen ) {
				$seen = $user;
				return $required;
			},
			10,
			2
		);

		Two_Factor_Enrollment::is_required( $this->user_id );

		$this->assertInstanceOf( 'WP_User', $seen );
		$this->assertSame( $this->user_id, $seen->ID );
	}

	public function test_is_required_false_for_invalid_user() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		$this->assertFalse( Two_Factor_Enrollment::is_required( 999999 ) );
	}

	public function test_is_deferrable_defaults_to_false() {
		$this->assertFalse( Two_Factor_Enrollment::is_deferrable( $this->user_id ) );
	}

	public function test_is_deferrable_honors_filter() {
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );
		$this->assertTrue( Two_Factor_Enrollment::is_deferrable( $this->user_id ) );
	}

	public function test_is_complete_false_without_provider() {
		$this->assertFalse( Two_Factor_Enrollment::is_complete( $this->user_id ) );
	}

	public function test_is_complete_true_with_configured_provider() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$this->assertTrue( Two_Factor_Enrollment::is_complete( $this->user_id ) );
	}

	public function test_email_provider_available_by_default() {
		$this->assertTrue( Two_Factor_Enrollment::email_provider_available() );
	}

	public function test_email_provider_unavailable_when_filtered_out() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$this->assertFalse( Two_Factor_Enrollment::email_provider_available() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment
```

Expected: FAIL with "Call to undefined method Two_Factor_Enrollment::is_required()".

- [ ] **Step 3: Implement the predicates**

Replace the body of `includes/class-two-factor-enrollment.php`:

```php
<?php
/**
 * Enrollment predicates.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Decides whether a user must enroll in two-factor authentication.
 *
 * This class is intentionally free of side effects and output so that the
 * security-relevant decisions are reviewable in one place.
 */
class Two_Factor_Enrollment {

	/**
	 * Provider key used as the enrollment floor.
	 *
	 * @var string
	 */
	const EMAIL_PROVIDER = 'Two_Factor_Email';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
	}

	/**
	 * Resolve a user reference to a WP_User.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return WP_User|false
	 */
	public static function fetch_user( $user = null ) {
		if ( $user instanceof WP_User ) {
			return $user->exists() ? $user : false;
		}

		if ( null === $user ) {
			$user = get_current_user_id();
		}

		if ( ! is_numeric( $user ) || (int) $user < 1 ) {
			return false;
		}

		$user = get_user_by( 'id', (int) $user );

		return ( $user instanceof WP_User && $user->exists() ) ? $user : false;
	}

	/**
	 * Whether this user must enroll in two-factor authentication.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_required( $user = null ) {
		$user = self::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		/**
		 * Whether this user must enroll in two-factor authentication.
		 *
		 * @param bool    $required Default false.
		 * @param WP_User $user     The user being evaluated.
		 */
		return (bool) apply_filters( 'two_factor_enrollment_required', false, $user );
	}

	/**
	 * Whether this user may defer enrollment.
	 *
	 * The policy layer owns all counting, deadlines, and state. This plugin
	 * stores nothing and only honors the answer.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_deferrable( $user = null ) {
		$user = self::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		/**
		 * Whether this user may defer enrollment.
		 *
		 * @param bool    $allowed Default false.
		 * @param WP_User $user    The user being evaluated.
		 */
		return (bool) apply_filters( 'two_factor_enrollment_deferrable', false, $user );
	}

	/**
	 * Whether this user has already completed enrollment.
	 *
	 * Delegates to Two Factor rather than tracking parallel state.
	 *
	 * @param WP_User|int|null $user User object, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_complete( $user = null ) {
		$user = self::fetch_user( $user );

		if ( ! $user ) {
			return false;
		}

		return (bool) Two_Factor_Core::is_user_using_two_factor( $user );
	}

	/**
	 * Whether the Email provider is registered site-wide.
	 *
	 * Administrators can disable providers, in which case there is no
	 * mailbox-bound factor to enroll into and this plugin stands down.
	 *
	 * @return bool
	 */
	public static function email_provider_available() {
		$providers = Two_Factor_Core::get_providers();

		return isset( $providers[ self::EMAIL_PROVIDER ] );
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment
```

Expected: 10 passing tests.

- [ ] **Step 5: Run the linter**

```bash
npm run lint
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add enrollment predicates with contract filters"
```

---

### Task 3: The gate — inject Email during authentication

**Files:**
- Create: `includes/class-two-factor-enrollment-gate.php`
- Modify: `includes/class-two-factor-enrollment.php` (wire `add_hooks()`)
- Test: `tests/test-enrollment-gate.php`

**Interfaces:**
- Consumes: `Two_Factor_Enrollment::is_required()`, `is_complete()`, `email_provider_available()`, `EMAIL_PROVIDER`.
- Produces:
  - `Two_Factor_Enrollment_Gate::add_hooks()` → `void`.
  - `Two_Factor_Enrollment_Gate::filter_enabled_providers( array $providers, $user_id )` → `array`.

**Why one filter is enough:** `Two_Factor_Core::get_primary_provider_for_user()` forces the primary when exactly one provider is available, and `Two_Factor_Email::is_available_for_user()` returns `true` unconditionally. A user who has not completed enrollment has no available providers, so injecting Email yields exactly one — which becomes primary without a second filter.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for the enrollment gate.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Gate extends WP_UnitTestCase {

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

	public function test_no_injection_when_not_required() {
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $this->user_id ) );
	}

	public function test_email_injected_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	public function test_email_becomes_primary_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$provider = Two_Factor_Core::get_primary_provider_for_user( $this->user_id );

		$this->assertInstanceOf( 'Two_Factor_Email', $provider );
	}

	public function test_user_is_considered_using_two_factor_when_required() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $this->user_id ) );
	}

	public function test_no_injection_when_already_complete() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
		update_user_meta( $this->user_id, '_two_factor_totp_key', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' );

		add_filter( 'two_factor_enrollment_required', '__return_true' );

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	public function test_no_injection_when_email_provider_disabled() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertNotContains( 'Two_Factor_Email', $enabled );
	}

	public function test_injection_does_not_write_user_meta() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );

		Two_Factor_Core::get_enabled_providers_for_user( $this->user_id );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Gate
```

Expected: FAIL on `test_email_injected_when_required` — the returned array does not contain `Two_Factor_Email`.

- [ ] **Step 3: Implement the gate**

Create `includes/class-two-factor-enrollment-gate.php`:

```php
<?php
/**
 * Injects the Email provider so core performs enrollment during login.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Pre-cookie enrollment gate.
 *
 * Two_Factor_Core::filter_authenticate() suppresses the auth cookie for any
 * user who is using two-factor. By making Email the resolved provider for a
 * user who must enroll, enrollment happens inside Two Factor's existing challenge,
 * before any cookie is issued.
 */
class Two_Factor_Enrollment_Gate {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_filter( 'two_factor_enabled_providers_for_user', array( __CLASS__, 'filter_enabled_providers' ), 10, 2 );
	}

	/**
	 * Add the Email provider for users who must enroll.
	 *
	 * The injection is runtime-only. Nothing is written to user meta until the
	 * user has actually passed the challenge, so deactivating this plugin
	 * before a covered user has ever logged in leaves no trace.
	 *
	 * @param array $providers Enabled provider keys.
	 * @param int   $user_id   The user ID.
	 * @return array
	 */
	public static function filter_enabled_providers( $providers, $user_id ) {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}

		if ( in_array( Two_Factor_Enrollment::EMAIL_PROVIDER, $providers, true ) ) {
			return $providers;
		}

		if ( ! Two_Factor_Enrollment::email_provider_available() ) {
			return $providers;
		}

		if ( ! Two_Factor_Enrollment::is_required( $user_id ) ) {
			return $providers;
		}

		if ( self::has_configured_provider( $user_id, $providers ) ) {
			return $providers;
		}

		$providers[] = Two_Factor_Enrollment::EMAIL_PROVIDER;

		return $providers;
	}

	/**
	 * Whether the user already has a usable, configured provider.
	 *
	 * Two_Factor_Enrollment::is_complete() cannot be used here: it calls back
	 * into get_enabled_providers_for_user(), which would recurse through this
	 * same filter. This checks the supplied list directly instead.
	 *
	 * @param int   $user_id   The user ID.
	 * @param array $providers Enabled provider keys already resolved for the user.
	 * @return bool
	 */
	private static function has_configured_provider( $user_id, $providers ) {
		if ( empty( $providers ) ) {
			return false;
		}

		$user = Two_Factor_Enrollment::fetch_user( $user_id );

		if ( ! $user ) {
			return false;
		}

		$supported = Two_Factor_Core::get_providers();

		foreach ( $providers as $key ) {
			if ( isset( $supported[ $key ] ) && $supported[ $key ]->is_available_for_user( $user ) ) {
				return true;
			}
		}

		return false;
	}
}
```

- [ ] **Step 4: Wire it into `Two_Factor_Enrollment::add_hooks()`**

Replace the empty `add_hooks()` method in `includes/class-two-factor-enrollment.php`:

```php
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-gate.php';

		Two_Factor_Enrollment_Gate::add_hooks();
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Gate
```

Expected: 7 passing tests.

- [ ] **Step 6: Run the full suite to check for regressions**

```bash
npm test
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add pre-cookie enrollment gate injecting the Email provider"
```

---

### Task 4: Persist enrollment after a successful challenge

**Files:**
- Modify: `includes/class-two-factor-enrollment-gate.php`
- Test: `tests/test-enrollment-gate.php` (append)

**Interfaces:**
- Consumes: the `two_factor_user_authenticated` action, fired by `Two_Factor_Core::validate_login_form_2fa()` after the auth cookie is set.
- Produces: `Two_Factor_Enrollment_Gate::persist_enrollment( WP_User $user, $provider )` → `void`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/test-enrollment-gate.php`, inside the class:

```php
	public function test_persist_enrollment_writes_user_meta() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
		$this->assertSame(
			'Two_Factor_Email',
			get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true )
		);
	}

	public function test_persist_enrollment_ignores_other_providers() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Totp::get_instance() );

		$this->assertSame( '', get_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
	}

	public function test_persist_enrollment_does_not_clobber_existing_providers() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Backup_Codes' ) );

		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$enabled = get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		$this->assertContains( 'Two_Factor_Backup_Codes', $enabled );
		$this->assertContains( 'Two_Factor_Email', $enabled );
	}

	public function test_persist_enrollment_is_idempotent() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );
		Two_Factor_Enrollment_Gate::persist_enrollment( $user, Two_Factor_Email::get_instance() );

		$this->assertSame(
			array( 'Two_Factor_Email' ),
			get_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true )
		);
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Gate
```

Expected: FAIL with "Call to undefined method Two_Factor_Enrollment_Gate::persist_enrollment()".

- [ ] **Step 3: Implement persistence**

Add the hook registration inside `Two_Factor_Enrollment_Gate::add_hooks()`:

```php
		add_action( 'two_factor_user_authenticated', array( __CLASS__, 'persist_enrollment' ), 10, 2 );
```

And add the method to the class:

```php
	/**
	 * Write the enrollment to user meta after a successful challenge.
	 *
	 * Until this runs, the Email provider is only injected at runtime. Once the
	 * user has actually received and entered an emailed code, mailbox control
	 * is proven and the enrollment is made real and visible in their profile.
	 *
	 * @param WP_User             $user     The authenticated user.
	 * @param Two_Factor_Provider $provider The provider used to authenticate.
	 * @return void
	 */
	public static function persist_enrollment( $user, $provider ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( ! is_object( $provider ) || Two_Factor_Enrollment::EMAIL_PROVIDER !== $provider->get_key() ) {
			return;
		}

		$enabled = get_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true );

		if ( ! is_array( $enabled ) ) {
			$enabled = array();
		}

		if ( ! in_array( Two_Factor_Enrollment::EMAIL_PROVIDER, $enabled, true ) ) {
			$enabled[] = Two_Factor_Enrollment::EMAIL_PROVIDER;
			update_user_meta( $user->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array_values( $enabled ) );
		}

		$primary = get_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, true );

		if ( empty( $primary ) ) {
			update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, Two_Factor_Enrollment::EMAIL_PROVIDER );
		}
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Gate
```

Expected: 11 passing tests.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Persist Email enrollment after a successful challenge"
```

---

### Task 5: Enrollment notification mail

**Files:**
- Create: `includes/class-two-factor-enrollment-notify.php`
- Modify: `includes/class-two-factor-enrollment.php` (wire `add_hooks()`)
- Test: `tests/test-enrollment-notify.php`

**Interfaces:**
- Consumes: `Two_Factor_Enrollment::fetch_user()`.
- Produces:
  - `Two_Factor_Enrollment_Notify::add_hooks()` → `void`.
  - `Two_Factor_Enrollment_Notify::notify( WP_User $user, $provider_label )` → `bool` (the `wp_mail()` result).

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for enrollment notification mail.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Notify extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor@example.org',
			)
		);
		reset_phpmailer_instance();
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_notify' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_notify_sends_mail_to_account_address() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Notify::notify( $user, 'Email' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertNotFalse( $sent );
		$this->assertSame( 'editor@example.org', $sent->to[0][0] );
	}

	public function test_notify_mentions_the_provider_label() {
		$user = get_user_by( 'id', $this->user_id );

		Two_Factor_Enrollment_Notify::notify( $user, 'Email' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertStringContainsString( 'Email', $sent->body );
	}

	public function test_notify_suppressed_by_filter() {
		add_filter( 'two_factor_enrollment_notify', '__return_false' );

		$user = get_user_by( 'id', $this->user_id );

		$this->assertFalse( Two_Factor_Enrollment_Notify::notify( $user, 'Email' ) );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertFalse( $mailer->get_sent() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Notify
```

Expected: FAIL with "Class 'Two_Factor_Enrollment_Notify' not found".

- [ ] **Step 3: Implement the notifier**

Create `includes/class-two-factor-enrollment-notify.php`:

```php
<?php
/**
 * Notifies users when a two-factor method is enrolled on their account.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Enrollment notification mail.
 *
 * Enrolling a second factor is a security-relevant account change that the
 * Two Factor plugin does not currently announce, while first-time enrollment
 * simultaneously destroys the account's other sessions. This converts a silent
 * change into a detectable one.
 */
class Two_Factor_Enrollment_Notify {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_action( 'two_factor_user_authenticated', array( __CLASS__, 'maybe_notify' ), 20, 2 );
	}

	/**
	 * Send a notification when this plugin has just enrolled the user.
	 *
	 * Runs at priority 20, after Two_Factor_Enrollment_Gate::persist_enrollment()
	 * at priority 10, so it only fires for a genuinely new enrollment.
	 *
	 * @param WP_User             $user     The authenticated user.
	 * @param Two_Factor_Provider $provider The provider used to authenticate.
	 * @return void
	 */
	public static function maybe_notify( $user, $provider ) {
		if ( ! $user instanceof WP_User || ! is_object( $provider ) ) {
			return;
		}

		if ( Two_Factor_Enrollment::EMAIL_PROVIDER !== $provider->get_key() ) {
			return;
		}

		if ( get_user_meta( $user->ID, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) ) {
			return;
		}

		self::notify( $user, $provider->get_label() );
	}

	/**
	 * Mail the account address about a newly enrolled method.
	 *
	 * @param WP_User $user           The user to notify.
	 * @param string  $provider_label Human-readable provider name.
	 * @return bool Whether the mail was sent.
	 */
	public static function notify( $user, $provider_label ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		/**
		 * Whether to send enrollment notification mail.
		 *
		 * @param bool    $send Default true.
		 * @param WP_User $user The user being notified.
		 */
		if ( ! apply_filters( 'two_factor_enrollment_notify', true, $user ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Two-factor authentication was enabled on your %s account', 'two-factor-enrollment' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$message = sprintf(
			/* translators: 1: user login, 2: provider label, 3: site URL. */
			__(
				'Hi %1$s,

Two-factor authentication was enabled on your account using the "%2$s" method.

This also signed you out of any other active sessions.

If you did this, no action is needed. If you did not, your password may be compromised — reset it immediately and contact the site administrator.

Site: %3$s',
				'two-factor-enrollment'
			),
			$user->user_login,
			$provider_label,
			home_url( '/' )
		);

		return wp_mail( $user->user_email, $subject, $message ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Single transactional security email to the affected user.
	}
}
```

- [ ] **Step 4: Wire it into `Two_Factor_Enrollment::add_hooks()`**

Replace `add_hooks()` in `includes/class-two-factor-enrollment.php`:

```php
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-gate.php';
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-onboarding.php';
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-notify.php';

		Two_Factor_Enrollment_Gate::add_hooks();
		Two_Factor_Enrollment_Onboarding::add_hooks();
		Two_Factor_Enrollment_Notify::add_hooks();
	}
```

`Two_Factor_Enrollment_Notify::maybe_notify()` references `Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY`, which Task 6 creates. Implement Task 6 before running the full suite; run only the notify filter in step 5.

- [ ] **Step 5: Run the notify tests to verify they pass**

The three tests above call `notify()` directly and do not touch `maybe_notify()`, so they pass before Task 6 exists.

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Notify
```

Expected: 3 passing tests.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add enrollment notification mail"
```

---

### Task 6: Onboarding screen — routing and the one-time flag

**Files:**
- Create: `includes/class-two-factor-enrollment-onboarding.php`
- Test: `tests/test-enrollment-onboarding.php`

**Interfaces:**
- Consumes: `Two_Factor_Enrollment::is_complete()`.
- Produces:
  - `Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY` → `'_two_factor_enrollment_onboarded'`.
  - `Two_Factor_Enrollment_Onboarding::add_hooks()` → `void`.
  - `Two_Factor_Enrollment_Onboarding::needs_onboarding( $user )` → `bool`.
  - `Two_Factor_Enrollment_Onboarding::mark_onboarded( $user_id )` → `void`.
  - `Two_Factor_Enrollment_Onboarding::filter_login_redirect( $redirect_to, $requested, $user )` → `string`.
  - `Two_Factor_Enrollment_Onboarding::url( $redirect_to )` → `string`.

**Mechanism:** `wp-login.php` accepts a custom `action` when `has_filter( 'login_form_' . $action )` is registered (`wp-login.php:509`), and `do_action( "login_form_{$action}" )` fires at `wp-login.php:571`, before the routing switch at line 585.

- [ ] **Step 1: Write the failing tests**

```php
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
		parent::tear_down();
	}

	public function test_needs_onboarding_false_without_two_factor() {
		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_needs_onboarding_true_when_enrolled_and_unflagged() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$this->assertTrue( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
	}

	public function test_needs_onboarding_false_once_flagged() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $this->user_id );

		$this->assertFalse( Two_Factor_Enrollment_Onboarding::needs_onboarding( $this->user_id ) );
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
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Onboarding
```

Expected: FAIL with "Class 'Two_Factor_Enrollment_Onboarding' not found".

- [ ] **Step 3: Implement routing**

Create `includes/class-two-factor-enrollment-onboarding.php`:

```php
<?php
/**
 * One-time post-login onboarding screen.
 *
 * @package Two_Factor_Enrollment
 */

/**
 * Offers backup codes and the TOTP upgrade after a user's first two-factored login.
 *
 * This screen is convenience, not a security boundary: any session reaching it
 * has already passed a real two-factor challenge.
 */
class Two_Factor_Enrollment_Onboarding {

	/**
	 * User meta flag recording that the screen has been shown.
	 *
	 * This is the only state this plugin persists.
	 *
	 * @var string
	 */
	const ONBOARDED_META_KEY = '_two_factor_enrollment_onboarded';

	/**
	 * The wp-login.php action slug for this screen.
	 *
	 * @var string
	 */
	const ACTION = 'two_factor_enrollment_onboarding';

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
	 * Whether this user should see the onboarding screen.
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

		return ! get_user_meta( $user->ID, self::ONBOARDED_META_KEY, true );
	}

	/**
	 * Record that the screen has been shown.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 */
	public static function mark_onboarded( $user_id ) {
		update_user_meta( (int) $user_id, self::ONBOARDED_META_KEY, 1 );
	}

	/**
	 * Build the onboarding screen URL.
	 *
	 * @param string $redirect_to Where to send the user afterwards.
	 * @return string
	 */
	public static function url( $redirect_to ) {
		return Two_Factor_Core::login_url(
			array(
				'action'      => self::ACTION,
				'redirect_to' => rawurlencode( $redirect_to ),
			)
		);
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

		return self::url( $redirect_to );
	}

	/**
	 * Render the onboarding screen.
	 *
	 * Implemented in Task 7.
	 *
	 * @return void
	 */
	public static function render() {
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Onboarding
```

Expected: 9 passing tests.

- [ ] **Step 5: Run the full suite**

```bash
npm test
```

Expected: all tests pass, including the notify tests that reference `ONBOARDED_META_KEY`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add onboarding screen routing and one-time flag"
```

---

### Task 7: Onboarding screen — backup codes and TOTP offer

**Files:**
- Modify: `includes/class-two-factor-enrollment-onboarding.php`
- Test: `tests/test-enrollment-onboarding.php` (append)

**Interfaces:**
- Consumes: `Two_Factor_Backup_Codes::get_instance()->generate_codes( WP_User $user, array $args )` → `array` of code strings.
- Produces:
  - `Two_Factor_Enrollment_Onboarding::render()` → `void` (exits).
  - `Two_Factor_Enrollment_Onboarding::render_body( WP_User $user, array $codes, $redirect_to )` → `string` HTML.

- [ ] **Step 1: Write the failing tests**

Append to `tests/test-enrollment-onboarding.php`, inside the class:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Onboarding
```

Expected: FAIL with "Call to undefined method Two_Factor_Enrollment_Onboarding::render_body()".

- [ ] **Step 3: Implement rendering**

Replace the stub `render()` in `includes/class-two-factor-enrollment-onboarding.php` with these two methods:

```php
	/**
	 * Render the onboarding screen and exit.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only destination, validated by wp_safe_redirect below.
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();

		if ( ! self::needs_onboarding( $user ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$codes = array();

		if ( class_exists( 'Two_Factor_Backup_Codes' ) ) {
			$codes = Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
		}

		self::mark_onboarded( $user->ID );

		login_header( __( 'Two-Factor Authentication', 'two-factor-enrollment' ) );

		echo self::render_body( $user, $codes, $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped within render_body().

		login_footer();

		exit;
	}

	/**
	 * Build the onboarding screen markup.
	 *
	 * @param WP_User $user        The enrolled user.
	 * @param array   $codes       Freshly generated backup codes.
	 * @param string  $redirect_to Where the Continue link points.
	 * @return string
	 */
	public static function render_body( $user, $codes, $redirect_to ) {
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
					<?php esc_html_e( 'If you ever lose access to your email, these codes are the only way back into your account. Each one works once. Save them somewhere safe now — they will not be shown again.', 'two-factor-enrollment' ); ?>
				</p>

				<ul class="two-factor-enrollment-codes">
					<?php foreach ( $codes as $code ) : ?>
						<li><code><?php echo esc_html( $code ); ?></code></li>
					<?php endforeach; ?>
				</ul>
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
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Onboarding
```

Expected: 13 passing tests.

- [ ] **Step 5: Run the linter**

```bash
npm run lint
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Render onboarding screen with backup codes and TOTP offer"
```

---

### Task 8: Safe degradation when the Email provider is unavailable

**Files:**
- Create: `includes/class-two-factor-enrollment-admin.php`
- Modify: `includes/class-two-factor-enrollment.php` (wire `add_hooks()`)
- Test: `tests/test-enrollment-admin.php`

**Interfaces:**
- Consumes: `Two_Factor_Enrollment::email_provider_available()`.
- Produces:
  - `Two_Factor_Enrollment_Admin::add_hooks()` → `void`.
  - `Two_Factor_Enrollment_Admin::should_warn()` → `bool`.
  - `Two_Factor_Enrollment_Admin::notice()` → `void` (echoes).

**Why this is required to ship:** Task 3 already declines to inject when Email is disabled, so the plugin fails safe. But it fails *silently* — an administrator who disabled Email would believe enrollment is being enforced when it is not. This task makes that visible. The fallback wizard that would actually enroll users in this configuration is a separate plan.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for the admin notice.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Admin extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'two_factor_providers' );
		parent::tear_down();
	}

	public function test_no_warning_when_email_available() {
		$this->assertFalse( Two_Factor_Enrollment_Admin::should_warn() );
	}

	public function test_warns_when_email_disabled() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$this->assertTrue( Two_Factor_Enrollment_Admin::should_warn() );
	}

	public function test_notice_output_mentions_email() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Email', $html );
	}

	public function test_notice_silent_when_email_available() {
		ob_start();
		Two_Factor_Enrollment_Admin::notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Admin
```

Expected: FAIL with "Class 'Two_Factor_Enrollment_Admin' not found".

- [ ] **Step 3: Implement the notice**

Create `includes/class-two-factor-enrollment-admin.php`:

```php
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
```

- [ ] **Step 4: Wire it into `Two_Factor_Enrollment::add_hooks()`**

Replace `add_hooks()` in `includes/class-two-factor-enrollment.php`:

```php
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function add_hooks() {
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-gate.php';
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-onboarding.php';
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-notify.php';
		require_once TWO_FACTOR_ENROLLMENT_DIR . 'includes/class-two-factor-enrollment-admin.php';

		Two_Factor_Enrollment_Gate::add_hooks();
		Two_Factor_Enrollment_Onboarding::add_hooks();
		Two_Factor_Enrollment_Notify::add_hooks();
		Two_Factor_Enrollment_Admin::add_hooks();
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Admin
```

Expected: 4 passing tests.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Warn administrators when the Email provider is unavailable"
```

---

### Task 9: Uninstall cleanup and documentation

**Files:**
- Create: `uninstall.php`
- Create: `README.md`
- Test: `tests/test-uninstall.php`

**Interfaces:**
- Consumes: `Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY`.
- Produces: `two_factor_enrollment_uninstall()` → `void`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for uninstall cleanup.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_removes_onboarding_flag_from_all_users() {
		$one = self::factory()->user->create();
		$two = self::factory()->user->create();

		Two_Factor_Enrollment_Onboarding::mark_onboarded( $one );
		Two_Factor_Enrollment_Onboarding::mark_onboarded( $two );

		require_once dirname( __DIR__ ) . '/uninstall.php';

		two_factor_enrollment_uninstall();

		$this->assertSame( '', get_user_meta( $one, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $two, Two_Factor_Enrollment_Onboarding::ONBOARDED_META_KEY, true ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Uninstall
```

Expected: FAIL — `uninstall.php` does not exist.

- [ ] **Step 3: Write `uninstall.php`**

The guard means the file is safe to `require_once` from tests, where `WP_UNINSTALL_PLUGIN` is not defined.

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm run composer test -- --filter Tests_Two_Factor_Enrollment_Uninstall
```

Expected: 1 passing test.

- [ ] **Step 5: Write `README.md`**

````markdown
# Two-Factor Enrollment

An add-on for the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin
that enrolls policy-covered users in two-factor authentication **before an auth
cookie is issued**.

Implements the onboarding flow described in
[WordPress/two-factor#813](https://github.com/WordPress/two-factor/issues/813).

## What it does

When a covered user logs in without a second factor configured, this plugin adds
the Email provider to their account at runtime. Two Factor's existing login
challenge then emails them a one-time code and withholds the auth cookie until
they enter it. Confirming the code proves mailbox control and completes
enrollment in a single step.

Because enrollment finishes before any cookie exists, there is no password-only
session to defend, and no admin lockdown is needed.

It also closes the **enrollment race**: an attacker holding only the password
cannot receive the emailed code, and — because the required path offers no
method chooser — cannot steer enrollment toward a factor that avoids the mailbox.

## What it does not do

It owns **no enforcement policy**. It has no settings screen and no role
targeting. Both contract filters default to `false`, so installing it alone
changes nothing. Something else decides who must enroll — for example
[Require-Email-2FA](https://github.com/dknauss/Require-Email-2FA), or a few lines
in a theme or mu-plugin.

## Usage

```php
// Require enrollment for everyone who can publish.
add_filter(
	'two_factor_enrollment_required',
	function ( $required, $user ) {
		return user_can( $user, 'edit_posts' );
	},
	10,
	2
);
```

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `two_factor_enrollment_required` | `false` | Does this user have to enroll |
| `two_factor_enrollment_deferrable` | `false` | May this user defer |
| `two_factor_enrollment_notify` | `true` | Send enrollment notification mail |

## Requirements

WordPress 6.5+, PHP 7.2+, and the Two Factor plugin.

## Limitation

If an administrator disables the Email provider site-wide, this plugin stands
down and shows an admin notice. Enrolling users into a non-Email provider
requires configuration after login, which needs a session — see
[WordPress/two-factor#934](https://github.com/WordPress/two-factor/issues/934).

## License

GPL-2.0-or-later.
````

- [ ] **Step 6: Run the full suite and linter**

```bash
npm test && npm run lint
```

Expected: all tests pass, no lint errors.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add uninstall cleanup and README"
```

---

## Self-Review

**Spec coverage.** §4 contract → Task 2. §5 architecture → Tasks 2, 3, 5, 6, 8 (the `class-enrollment-fallback.php` unit is deferred with the fallback subsystem). §6.1 required path → Tasks 3 and 4. §6.2 onboarding → Tasks 6 and 7. §6.3 front end not intercepted → satisfied structurally: nothing in this plan hooks `parse_request` or `template_redirect`, so there is no interception to disable. §6.4 fallback → Task 8 covers the safe-degradation half; the wizard half is explicitly deferred to a second plan and stated in Scope. §7 session security → not applicable, since no password-only session is created on the required path. §8 enrollment race → closed by Task 3's design; notification is Task 5. §9 lockout recovery → backup codes surfaced in Task 7. §10 filter reference → the three required-path filters are implemented; `two_factor_enrollment_intercept_frontend`, `_idle_ttl`, and `_absolute_ttl` belong to the deferred fallback and are intentionally absent. §11 testing → each task carries its tests. §13 licensing → README and headers.

**Gap found and closed.** The spec's §12 lists upstream contributions; those are comment drafts, not code, and correctly have no task.

**Placeholder scan.** No TBDs. Every code step contains complete, runnable code. Task 6's `render()` stub is explicitly labelled as implemented in Task 7 rather than left vague.

**Type consistency.** `ONBOARDED_META_KEY` is defined in Task 6 and consumed in Tasks 5, 7, and 9 under the same name. `EMAIL_PROVIDER` is defined in Task 2 and consumed in Tasks 3, 4, and 5. `fetch_user()` is defined in Task 2 and consumed in Tasks 3 and 6. `Two_Factor_Enrollment_Notify::maybe_notify()` forward-references `ONBOARDED_META_KEY`; the ordering hazard is called out in Task 5 Step 4 with instructions.

**One deliberate deviation from the spec.** The spec's §6.1 named two filters for provider injection; the plan uses one. `get_primary_provider_for_user()` forces the primary when exactly one provider is available and `Two_Factor_Email::is_available_for_user()` always returns `true`, so the second filter is redundant. The spec has been amended to match.
