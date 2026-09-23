#!/usr/bin/env bash
# Fails when SDK code changed but the version wasn't bumped.
#
#   tests/check-version.sh <base-ref>     e.g. origin/master
#
# The SDK loader elects the highest *registered version string*, and the first
# copy to claim a version wins. New code shipped under an existing version is
# therefore silently ignored on any site where another plugin bundles an older
# copy with the same number.
set -euo pipefail
BASE="${1:?base ref, e.g. origin/master}"

version_of() { grep -o "register( '[0-9.]*'" | grep -o "[0-9][0-9.]*[0-9]"; }

head_v="$(version_of < init.php)"
base_v="$(git show "$BASE:init.php" | version_of)"
token="$(echo "$head_v" | sed 's/\./_dot_/g')"
errors=0

# Every versioned token in init.php must agree with register().
if grep -o "[0-9]*_dot_[0-9]*_dot_[0-9]*" init.php | grep -v -x "$token" | grep -q .; then
  echo "::error file=init.php::init.php mixes versions: function tokens don't all match $head_v ($token)"; errors=1
fi
if [ "$(grep -c "SE_License_SDK::init( __FILE__, '$head_v' )" init.php)" -ne 1 ]; then
  echo "::error file=init.php::SE_License_SDK::init() is not called with '$head_v'"; errors=1
fi

changed="$(git diff --name-only "$BASE"...HEAD -- classes functions.php init.php static views languages)"
if [ -n "$changed" ]; then
  if [ "$head_v" = "$base_v" ]; then
    echo "::error file=init.php::SDK code changed but the version is still $head_v. Bump every 1_dot_x_dot_y token and version string in init.php, or the new code is ignored on sites where another plugin bundles $head_v."
    echo "$changed" | sed 's/^/  changed: /'
    errors=1
  fi
  if ! grep -q "^= $head_v " changelog.txt; then
    echo "::error file=changelog.txt::No changelog entry '= $head_v - dd-mm-yyyy ='"; errors=1
  fi
fi

[ $errors -eq 0 ] && echo "Version check OK: $base_v -> $head_v"
exit $errors
