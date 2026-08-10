#!/usr/bin/env bash
#
# Assert dist/ contains exactly the files a distributed copy of the plugin
# needs — no more, no less. A full list comparison, not a spot check, so a
# file quietly added to the source tree (or a .distignore rule that stops
# matching) is caught here instead of shipping.
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

if [ ! -d dist ]; then
	echo "dist/ does not exist. Run \`npm run build\` first." >&2
	exit 1
fi

expected="$(mktemp)"
actual="$(mktemp)"
trap 'rm -f "$expected" "$actual"' EXIT

cat > "$expected" <<'EOF'
LICENSE
includes/class-two-factor-enrollment-admin.php
includes/class-two-factor-enrollment-gate.php
includes/class-two-factor-enrollment-notify.php
includes/class-two-factor-enrollment-onboarding.php
includes/class-two-factor-enrollment.php
readme.txt
two-factor-enrollment.php
uninstall.php
EOF

( cd dist && find . -type f | sed 's#^\./##' ) | LC_ALL=C sort > "$actual"
LC_ALL=C sort "$expected" -o "$expected"

if ! diff -u "$expected" "$actual"; then
	echo "dist/ file list does not match the expected manifest (see diff above)." >&2
	exit 1
fi

echo "dist/ file list matches the expected manifest exactly ($(wc -l < "$actual" | tr -d ' ') files)."
