#!/usr/bin/env bash
#
# Static guard (DEC-78-01): the Release workflow must build the embedded editor
# from a fixed editor TAG matching the plugin release tag -- never from the
# moving `main` branch -- so two builds of the same plugin release always bundle
# the same editor (and the editor reports the final version, not a nightly/alpha
# build). This mirrors the convention in exelearning/mod_exeweb and
# exelearning/mod_exescorm. The manual `workflow_dispatch` branch is exempt: it
# legitimately keeps the `main`/`auto` defaults for local/manual testing.
#
# Usage: bash scripts/check-release-workflow.sh
#
set -euo pipefail

WF=".github/workflows/release.yml"
[ -f "$WF" ] || { echo "ERROR: $WF not found"; exit 1; }

# Isolate only the release-event branch of the env-setup conditional:
#   if [ "...event_name" = "release" ]; then  <BLOCK>  else ...
block="$(awk '/= "release" \]; then/{f=1; next} f && /^[[:space:]]*else/{f=0} f' "$WF")"

if [ -z "$block" ]; then
    echo "ERROR: could not locate the release-event branch in $WF"; exit 1
fi

fail=0

echo "$block" | grep -q 'EXELEARNING_EDITOR_REF_TYPE=tag' \
    || { echo "FAIL: release event must set EXELEARNING_EDITOR_REF_TYPE=tag"; fail=1; }

if echo "$block" | grep -qE 'EXELEARNING_EDITOR_REF=(main|master)'; then
    echo "FAIL: release event must NOT build the editor from a branch (main/master)"; fail=1
fi

if echo "$block" | grep -q 'EXELEARNING_EDITOR_REF_TYPE=branch'; then
    echo "FAIL: release event must NOT use EXELEARNING_EDITOR_REF_TYPE=branch"; fail=1
fi

echo "$block" | grep -q 'EXELEARNING_EDITOR_REF=v${VERSION_TAG}' \
    || { echo 'FAIL: release event must pin EXELEARNING_EDITOR_REF=v${VERSION_TAG}'; fail=1; }

if [ "$fail" -ne 0 ]; then
    echo "Release workflow editor-pinning guard FAILED."; exit 1
fi

echo "OK: release workflow pins the editor to the matching tag (REF=v\${VERSION_TAG}, TYPE=tag)."
