#!/usr/bin/env bash
#
# Build a clean dist/ directory containing only the files a distributed copy
# of the plugin needs, per .distignore.
#
# This plugin compiles nothing (no JS bundling, no CSS preprocessing), so the
# build is a filtered copy: rsync everything except what .distignore lists.
# Idempotent — dist/ is removed and recreated each run, so a stale file from
# a previous run can never survive into a new build.
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

rm -rf dist
mkdir dist

rsync -a --exclude-from=.distignore ./ dist/

file_count="$(find dist -type f | wc -l | tr -d ' ')"
echo "Built dist/ ($file_count files)."
