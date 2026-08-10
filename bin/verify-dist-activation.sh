#!/usr/bin/env bash
#
# Prove the built dist/ tree genuinely works as a plugin, not merely that it
# contains the right filenames: install it into a running wp-env environment
# under its own plugin slug, and activate it alongside Two Factor.
#
# two-factor-enrollment.php declares `Requires Plugins: two-factor`, so
# activation order matters. This script deliberately checks both orders:
#   1. Activating the dist copy before Two Factor must be REFUSED by
#      WordPress core's plugin-dependency check.
#   2. Activating Two Factor first, then the dist copy, must SUCCEED with
#      the plugin's classes actually loaded.
# .wp-env.json already lists the plugins in the working order; this is the
# regression that a wrong order silently reintroduces.
#
# Requires a running wp-env environment (`npm run env start`) and the tests-cli
# service, which mounts this repository read-write at
# wp-content/plugins/two-factor-enrollment. dist/ must already be built
# (`npm run build`) — it is visible inside the container via that same mount.
#
# The source plugin is deactivated for the duration. .wp-env.json lists "." as
# a plugin, so a freshly started environment has the source copy active at the
# same time this script activates the dist copy — and both files declare
# two_factor_enrollment_bootstrap() at global scope, so the second one to load
# is a PHP fatal:
#
#   Cannot redeclare two_factor_enrollment_bootstrap() (previously declared in
#   .../two-factor-enrollment/two-factor-enrollment.php:32)
#
# That is a collision between two copies of the same plugin, not a defect in
# either, and it is what this script is here to rule out for the dist copy
# specifically. Note that it only reproduces on a *fresh* environment: a
# developer whose container has the source plugin already deactivated (this
# script's own cleanup used to leave it that way) sees a clean pass locally
# while CI fails on every run.
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

if [ ! -d dist ]; then
	echo "dist/ does not exist. Run \`npm run build\` first." >&2
	exit 1
fi

container="tests-cli"
wp_path="/var/www/html"
source_slug="two-factor-enrollment"
plugin_dir="/var/www/html/wp-content/plugins/${source_slug}"
dist_slug="two-factor-enrollment-dist"
dist_dir="/var/www/html/wp-content/plugins/${dist_slug}"

wp_run() {
	npx wp-env run "$container" -- wp --path="$wp_path" "$@"
}

# Put the environment back the way .wp-env.json leaves it after `wp-env start`:
# Two Factor and the source plugin both active, no dist copy on disk. Order
# matters on the way back up, because the source plugin declares
# `Requires Plugins: two-factor` and WordPress refuses to activate it while
# that dependency is inactive.
cleanup() {
	local status=$?
	wp_run plugin deactivate "$dist_slug" >/dev/null 2>&1 || true
	npx wp-env run "$container" -- bash -c "rm -rf '$dist_dir'" >/dev/null 2>&1 || true
	wp_run plugin activate two-factor >/dev/null 2>&1 || true
	wp_run plugin activate "$source_slug" >/dev/null 2>&1 || true
	exit "$status"
}
trap cleanup EXIT

echo "Installing dist/ into the tests-cli container as '${dist_slug}'..."
npx wp-env run "$container" -- bash -c "rm -rf '$dist_dir' && cp -r '$plugin_dir/dist' '$dist_dir'"

# Get the source copy out of the way before anything activates the dist copy.
# Two copies of this plugin active at once is a redeclare fatal, and it would
# be reported against the dist copy -- the thing under test -- while being
# entirely an artifact of the harness. See the header for the full note.
echo
echo "Deactivating the source plugin so only the dist copy is loaded..."
wp_run plugin deactivate "$source_slug" >/dev/null 2>&1 || true
if wp_run plugin is-active "$source_slug" >/dev/null 2>&1; then
	echo "ERROR: could not deactivate ${source_slug}. Two active copies would fatal on a redeclare and blame the dist copy for it." >&2
	exit 1
fi

echo
echo "Checking that activation is refused before Two Factor is active..."
wp_run plugin deactivate two-factor >/dev/null 2>&1 || true
if wp_run plugin is-active "$dist_slug" >/dev/null 2>&1; then
	echo "ERROR: ${dist_slug} was already active before its dependency. Cannot test the ordering guard." >&2
	exit 1
fi
activate_output="$(wp_run plugin activate "$dist_slug" 2>&1 || true)"
echo "$activate_output"
if wp_run plugin is-active "$dist_slug" >/dev/null 2>&1; then
	echo "ERROR: ${dist_slug} activated even though Two Factor is inactive. The Requires Plugins header may be missing from dist/two-factor-enrollment.php." >&2
	exit 1
fi
echo "Confirmed: activation was refused while Two Factor is inactive."

echo
echo "Activating Two Factor, then the dist copy, in the correct order..."
wp_run plugin activate two-factor
wp_run plugin activate "$dist_slug"

if ! wp_run plugin is-active "$dist_slug" >/dev/null 2>&1; then
	echo "ERROR: ${dist_slug} did not activate cleanly with Two Factor active." >&2
	exit 1
fi

echo
echo "Confirming the plugin's classes actually loaded (not just that activation reported success)..."
loaded="$(wp_run eval 'echo class_exists( "Two_Factor_Enrollment" ) && class_exists( "Two_Factor_Enrollment_Gate" ) ? "CLASSES_LOADED" : "CLASSES_MISSING";')"
echo "$loaded"
if [ "$loaded" != "CLASSES_LOADED" ]; then
	echo "ERROR: dist/ activated but its classes did not load." >&2
	exit 1
fi

echo
echo "dist/ installs and activates cleanly alongside Two Factor, in the correct order only."
