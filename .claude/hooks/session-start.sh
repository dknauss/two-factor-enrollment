#!/bin/bash
# SessionStart hook for Claude Code on the web.
#
# Installs what `composer lint` and `composer test` need, without Docker (the
# web sandbox has no Docker daemon, so the wp-env wrappers such as `npm test`
# cannot run there):
#
# - Composer packages come from git sources. The sandbox proxy blocks GitHub
#   zipball downloads for repositories outside the session, but allows git
#   clones.
# - The Two Factor plugin must sit next to this one, where tests/bootstrap.php
#   looks for it. If no checkout is there, the hook clones WordPress/two-factor,
#   the same source .wp-env.json uses.
# - PHPUnit runs against MariaDB and a wordpress-develop checkout, configured
#   like the wp-env tests environment (WP_DEBUG on).
#
# Run all three suites with `composer test`, or one with vendor/bin/phpunit.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
	exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1

WP_DEVELOP_DIR="$HOME/.cache/wordpress-develop"
export WP_TESTS_DIR="$WP_DEVELOP_DIR/tests/phpunit"

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
	{
		echo "export COMPOSER_ALLOW_SUPERUSER=1"
		echo "export WP_TESTS_DIR=\"$WP_TESTS_DIR\""
	} >> "$CLAUDE_ENV_FILE"
fi

composer install --prefer-source --no-progress

# Two Factor, as a sibling directory (tests/bootstrap.php: ../two-factor).
TWO_FACTOR_DIR="$(dirname "$CLAUDE_PROJECT_DIR")/two-factor"
if [ ! -f "$TWO_FACTOR_DIR/two-factor.php" ]; then
	if [ -e "$TWO_FACTOR_DIR" ]; then
		echo "session-start: $TWO_FACTOR_DIR exists but has no two-factor.php; tests needing Two Factor will fail." >&2
	else
		git clone -q --depth 1 https://github.com/WordPress/two-factor "$TWO_FACTOR_DIR"
	fi
fi

# PHPUnit: MariaDB plus the WordPress test library.
if ! command -v mariadbd >/dev/null 2>&1; then
	apt-get update -qq
	DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server >/dev/null
fi
if ! mysqladmin ping --silent >/dev/null 2>&1; then
	service mariadb start >/dev/null
fi
mysql -e "CREATE DATABASE IF NOT EXISTS wordpress_test; CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp'; GRANT ALL ON wordpress_test.* TO 'wp'@'localhost';"

if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
	rm -rf "$WP_DEVELOP_DIR"
	git clone -q --depth 1 --filter=blob:none --sparse https://github.com/WordPress/wordpress-develop "$WP_DEVELOP_DIR"
	git -C "$WP_DEVELOP_DIR" sparse-checkout set src tests/phpunit/includes tests/phpunit/data
fi

cat > "$WP_DEVELOP_DIR/wp-tests-config.php" <<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/src/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', 'wp' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP
