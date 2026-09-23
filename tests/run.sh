#!/usr/bin/env bash
# Run the SDK scenarios against a WordPress install.
#
#   WP_PATH=/path/to/wordpress tests/run.sh [scenario-name-filter]
#
# The fixture plugins are symlinked into wp-content/plugins but only loaded
# for the test processes (never activated on the site). The license server is
# faked, so nothing leaves the machine. Needs wp-cli.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_PATH="${WP_PATH:?Set WP_PATH to a WordPress install}"
WP="${WP_CLI:-wp} --path=$WP_PATH --skip-themes"
PLUGINS="$($WP eval 'echo WP_PLUGIN_DIR;')"

for kind in pro free; do
  ln -sfn "$ROOT/tests/fixtures/se-sdk-fixture-$kind" "$PLUGINS/se-sdk-fixture-$kind"
done

fail=0
for scenario in "$ROOT"/tests/scenarios/*${1:-}*.php; do
  out="$($WP --require="$ROOT/tests/lib/load-fixtures.php" eval-file "$ROOT/tests/lib/bootstrap.php" "$scenario" 2>&1)"
  code=$?
  printf '%s\n' "$out" | grep -v -E 'called <strong>incorrectly|_load_textdomain_just_in_time|wp_update_plugins\(\): An unexpected error|Attempt to read property "id" on null|^$' || true
  [ $code -eq 0 ] || fail=1
done

for kind in pro free; do rm -f "$PLUGINS/se-sdk-fixture-$kind"; done

if [ $fail -ne 0 ]; then echo "SOME SCENARIOS FAILED"; exit 1; fi
echo "ALL SCENARIOS PASSED"
