#!/usr/bin/env bash
#
# Self-test for scripts/check-version.sh: exercises the validator against
# fixture version.php / upgrade.php files so the version policy (DEC-111-01) is
# enforced by CI, not by convention. Packaging invariants (working tree
# untouched, packaged version.php identical to the committed one) live in
# scripts/check-package.sh.
#
# Usage: bash scripts/check-version-selftest.sh
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
CHECK="$ROOT/scripts/check-version.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail=0

# Build a fixture version.php + upgrade.php (highest savepoint 2026072400).
make_fixture() { # $1 version, $2 release
    cat > "$WORK/version.php" <<EOF
<?php
\$plugin->version   = $1;
\$plugin->release   = '$2';
EOF
    cat > "$WORK/upgrade.php" <<'EOF'
<?php
if ($oldversion < 2026061800) {
    upgrade_mod_savepoint(true, 2026061800, 'exelearning');
}
if ($oldversion < 2026072400) {
    upgrade_mod_savepoint(true, 2026072400, 'exelearning');
}
EOF
}

# run <expected: ok|fail> <description> [args...]
run() {
    local expected="$1" desc="$2"; shift 2
    local out rc=0
    out="$(CHECK_VERSION_FILE="$WORK/version.php" CHECK_UPGRADE_FILE="$WORK/upgrade.php" \
        CHECK_VERSION_TAG="${SIMTAG-}" bash "$CHECK" "$@" 2>&1)" || rc=$?
    if [ "$expected" = "ok" ] && [ "$rc" -ne 0 ]; then
        echo "FAIL: '$desc' should pass but failed: $out"; fail=1
    elif [ "$expected" = "fail" ] && [ "$rc" -eq 0 ]; then
        echo "FAIL: '$desc' should fail but passed: $out"; fail=1
    else
        echo "ok: $desc"
    fi
}

SIMTAG=""   # Simulate "not on a tagged commit" unless a case overrides it.

# 1) Valid development state.
make_fixture 2026072401 dev;        run ok   "dev 2026072401 passes"
# 2-4) Sentinels.
make_fixture 9999999999 dev;        run fail "sentinel 9999999999 rejected"
make_fixture 99999 dev;             run fail "sentinel 99999 rejected"
make_fixture 0 dev;                 run fail "sentinel 0 rejected"
make_fixture 1 dev;                 run fail "sentinel 1 rejected"
# 5) Malformed (ten digits but impossible date).
make_fixture 2026134599 dev;        run fail "malformed date 2026134599 rejected"
make_fixture 202607240 dev;         run fail "nine digits rejected"
# 6) Equal to the highest savepoint fails in dev mode.
make_fixture 2026072400 dev;        run fail "dev version equal to highest savepoint rejected"
# 7) Lower than the highest savepoint fails.
make_fixture 2026061801 dev;        run fail "dev version below highest savepoint rejected"
# 8) Greater than the highest savepoint succeeds.
make_fixture 2026072402 dev;        run ok   "dev version above highest savepoint passes"
# 9) Official release: tag matches release.
make_fixture 2026072500 4.0.3;      SIMTAG="v4.0.3" run ok   "release 4.0.3 with tag v4.0.3 passes" --release 4.0.3
# 10) Official release: tag differs from release.
make_fixture 2026072500 4.0.3;      SIMTAG="v4.0.4" run fail "release 4.0.3 with tag v4.0.4 rejected" --release 4.0.3
# 11) Official release still carrying release='dev'.
make_fixture 2026072500 dev;        SIMTAG="v4.0.3" run fail "official release with release='dev' rejected" --release 4.0.3
# Release-preparation commit on main (no args, no tag): committed rules apply.
make_fixture 2026072500 4.0.3;      SIMTAG="" run ok   "release-prep commit validates without args"
# Extra guards: mismatched --release argument; dev metadata on a tagged commit.
make_fixture 2026072500 4.0.3;      SIMTAG="" run fail "release 4.0.2 expected but version.php says 4.0.3" --release 4.0.2
make_fixture 2026072401 dev;        SIMTAG="v4.0.3" run fail "dev metadata on a tagged commit rejected (no args)"
make_fixture 2026072401 dev;        SIMTAG="v4.0.3" run fail "dev build on a tagged commit rejected (--release dev)" --release dev

if [ "$fail" -ne 0 ]; then
    echo "check-version self-test FAILED." >&2
    exit 1
fi
echo "OK: check-version.sh enforces the DEC-111-01 version policy."
