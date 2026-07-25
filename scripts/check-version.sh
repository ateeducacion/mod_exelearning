#!/usr/bin/env bash
#
# Validate the plugin version metadata (DEC-0068: real, monotonic versions).
#
# version.php ships exactly as committed — packaging never rewrites it — so this
# guard is what keeps the value sane:
#
#   scripts/check-version.sh                 # validate the committed state
#   scripts/check-version.sh --release 4.0.3 # official release validation
#
# Without arguments the committed state decides the rules: release = 'dev'
# applies the development rules; anything else applies the release rules with
# the committed release as the expectation (so CI stays green on the short-lived
# release-preparation commit that lands on main before tagging).
#
# Development mode asserts: no sentinel, a plausible 10-digit YYYYMMDDXX value,
# release = 'dev', and $plugin->version STRICTLY greater than every
# upgrade_mod_savepoint()/`$oldversion <` version in db/upgrade.php.
#
# Release mode additionally asserts: $plugin->release equals the expected value
# (never 'dev'), the version is >= the highest savepoint, and — when HEAD is an
# exactly-tagged commit — the tag (without its leading 'v') matches the release.
#
# Overridable for the self-test (scripts/check-version-selftest.sh):
#   CHECK_VERSION_FILE     path to version.php
#   CHECK_UPGRADE_FILE     path to db/upgrade.php
#   CHECK_VERSION_TAG      simulated `git describe --tags --exact-match` output
#                          ('' = simulate "not on a tag"; unset = ask git)
#
# Works under Bash 3.2+ (macOS, Git Bash) and Ubuntu.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
VERSION_FILE="${CHECK_VERSION_FILE:-$ROOT/version.php}"
UPGRADE_FILE="${CHECK_UPGRADE_FILE:-$ROOT/db/upgrade.php}"

fail() { echo "check-version: ERROR: $1" >&2; exit 1; }

EXPECTED_RELEASE=""
if [ "${1:-}" = "--release" ]; then
    EXPECTED_RELEASE="${2:-}"
    [ -n "$EXPECTED_RELEASE" ] || fail "--release requires a value (e.g. --release 4.0.3)."
elif [ -n "${1:-}" ]; then
    fail "unknown argument '$1'. Usage: check-version.sh [--release X.Y.Z]"
fi

[ -f "$VERSION_FILE" ] || fail "version file not found: $VERSION_FILE"
[ -f "$UPGRADE_FILE" ] || fail "upgrade file not found: $UPGRADE_FILE"

VERSION="$(sed -n "s/^\$plugin->version[[:space:]]*=[[:space:]]*\([0-9][0-9]*\);.*/\1/p" "$VERSION_FILE" | head -n1)"
RELEASE="$(sed -n "s/^\$plugin->release[[:space:]]*=[[:space:]]*'\([^']*\)';.*/\1/p" "$VERSION_FILE" | head -n1)"

[ -n "$VERSION" ] || fail "could not read \$plugin->version from $VERSION_FILE"
[ -n "$RELEASE" ] || fail "could not read \$plugin->release from $VERSION_FILE"

# 1) Sentinels are forbidden in either direction: the maximum bricks upgrades
#    (every real release becomes a downgrade) and low values break the upgrade
#    protocol against existing savepoints.
case "$VERSION" in
    9999999999|99999|0|1|000000|000001)
        fail "\$plugin->version = $VERSION is a sentinel; use a real YYYYMMDDXX version (DEC-0068)." ;;
esac

# 2) Exactly ten digits, plausible YYYYMMDDXX (year 20xx, month 01-12, day 01-31).
echo "$VERSION" | grep -qE '^[0-9]{10}$' \
    || fail "\$plugin->version = $VERSION must be exactly ten digits (YYYYMMDDXX)."
echo "$VERSION" | grep -qE '^20[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])[0-9]{2}$' \
    || fail "\$plugin->version = $VERSION does not look like YYYYMMDDXX (invalid date part)."

# 3) Highest version the upgrade path knows about: every savepoint plus every
#    `$oldversion < N` guard (they should agree, but check both).
MAXSAVEPOINT="$(
    {
        sed -n 's/.*upgrade_mod_savepoint(true,[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$UPGRADE_FILE"
        sed -n 's/.*\$oldversion[[:space:]]*<[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$UPGRADE_FILE"
    } | sort -n | tail -n1
)"
MAXSAVEPOINT="${MAXSAVEPOINT:-0}"

# Without --release, validate whatever is committed.
if [ -z "$EXPECTED_RELEASE" ] && [ "$RELEASE" != "dev" ]; then
    EXPECTED_RELEASE="$RELEASE"
fi

if [ -z "$EXPECTED_RELEASE" ] || [ "$EXPECTED_RELEASE" = "dev" ]; then
    # Development validation.
    [ "$RELEASE" = "dev" ] \
        || fail "development validation expects \$plugin->release = 'dev', found '$RELEASE'."
    [ "$VERSION" -gt "$MAXSAVEPOINT" ] \
        || fail "\$plugin->version = $VERSION must be STRICTLY greater than the highest db/upgrade.php savepoint ($MAXSAVEPOINT)."
    # A dev-labelled commit must never be an officially tagged one: tags carry
    # final release metadata, committed before tagging.
    TAG="${CHECK_VERSION_TAG-$(git describe --tags --exact-match 2>/dev/null || true)}"
    [ -z "$TAG" ] \
        || fail "release 'dev' on the tagged commit $TAG; official tags must carry a final release."
    echo "check-version: OK (development): version=$VERSION release=dev savepoint-max=$MAXSAVEPOINT"
    exit 0
fi

# Official release validation.
[ "$RELEASE" != "dev" ] \
    || fail "building an official release but \$plugin->release is still 'dev'; commit the release metadata first."
[ "$RELEASE" = "$EXPECTED_RELEASE" ] \
    || fail "\$plugin->release = '$RELEASE' does not match the expected release '$EXPECTED_RELEASE'."
[ "$VERSION" -ge "$MAXSAVEPOINT" ] \
    || fail "\$plugin->version = $VERSION is lower than the highest db/upgrade.php savepoint ($MAXSAVEPOINT)."

TAG="${CHECK_VERSION_TAG-$(git describe --tags --exact-match 2>/dev/null || true)}"
if [ -n "$TAG" ]; then
    [ "${TAG#v}" = "$RELEASE" ] \
        || fail "tag '$TAG' (without 'v': '${TAG#v}') does not match \$plugin->release = '$RELEASE'."
fi

echo "check-version: OK (release): version=$VERSION release=$RELEASE savepoint-max=$MAXSAVEPOINT tag=${TAG:-none}"
