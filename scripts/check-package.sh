#!/usr/bin/env bash
#
# Executable guard for scripts/package.sh: build a throwaway ZIP and assert the
# transformations the packager is responsible for.
#
# This runs the packager instead of linting it on purpose. The
# machine-translation stripper was once silently broken by an unescaped `$`
# (the shell expanded `$string` and aborted under `set -u`), which no static
# check can see -- only running the script reveals it.
#
# Every sed below is single-quoted for the same reason: nothing in this file may
# rely on the shell leaving `$` alone inside double quotes.
#
# Usage: bash scripts/check-package.sh
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

RELEASE="packaging-check"
ZIP="$ROOT/mod_exelearning-$RELEASE.zip"
WORK="$(mktemp -d)"
FAKE_EDITOR=0
cleanup() {
    rm -rf "$WORK" "$ZIP"
    # Restore anything the failure-mode tests moved aside, even on abort.
    [ -f "$WORK_EDITOR_VERSION_BACKUP" ] 2>/dev/null && mv "$WORK_EDITOR_VERSION_BACKUP" .editor-version
    [ "$FAKE_EDITOR" -eq 1 ] && rm -rf dist/static
    return 0
}
trap cleanup EXIT
WORK_EDITOR_VERSION_BACKUP="$WORK/editor-version.backup"

fail=0
report() { echo "FAIL: $1"; fail=1; }

# 0) A package without a valid bundled editor must never be produced (DEC-0065).
#    The guard runs on plain CI checkouts where dist/static/ is absent, which is
#    itself the first failure case; a minimal editor is fabricated afterwards so
#    the success path can be asserted too. A real dist/static/ (local dev) is
#    used as-is and never touched.
if [ ! -d dist/static ]; then
    if bash scripts/package.sh "$RELEASE" > /dev/null 2> "$WORK/no-editor.err"; then
        report "package.sh must fail when dist/static/ is absent"
    fi
    grep -q "dist/static" "$WORK/no-editor.err" \
        || report "the missing-editor error must name dist/static (got: $(cat "$WORK/no-editor.err"))"
    [ -f "$ZIP" ] && report "package.sh must not leave a ZIP behind when the editor is missing"

    FAKE_EDITOR=1
    mkdir -p dist/static/app
    echo '<!doctype html><title>editor</title>' > dist/static/index.html
    echo 'fake' > dist/static/app/app.js
fi

# The editor version must be known: with .editor-version hidden, packaging fails
# and no ZIP is left behind. The file is restored immediately (and by the trap).
if [ -f .editor-version ]; then
    mv .editor-version "$WORK_EDITOR_VERSION_BACKUP"
    if bash scripts/package.sh "$RELEASE" > /dev/null 2> "$WORK/no-version.err"; then
        report "package.sh must fail when .editor-version is missing"
    fi
    grep -q ".editor-version" "$WORK/no-version.err" \
        || report "the missing-version error must name .editor-version (got: $(cat "$WORK/no-version.err"))"
    [ -f "$ZIP" ] && report "package.sh must not leave a ZIP behind when .editor-version is missing"
    mv "$WORK_EDITOR_VERSION_BACKUP" .editor-version
else
    report ".editor-version is missing from the checkout; packaging would fail"
fi

# Snapshot the tree so assertion 6 blames only the packager, not whatever the
# developer already had uncommitted when running this locally.
git status --porcelain > "$WORK/tree-before.txt"

bash scripts/package.sh "$RELEASE" > /dev/null
[ -f "$ZIP" ] || { echo "FAIL: packager produced no $ZIP"; exit 1; }
unzip -q "$ZIP" -d "$WORK"

PKG="$WORK/exelearning"

# 1) Everything must live under the Moodle install folder, whatever the repo or
#    the ZIP is called.
[ -d "$PKG" ] || report "the ZIP must place everything under exelearning/"

# 2) The dev sentinel must be stamped with a real date version and release
#    (DEC-0030); shipping 9999999999 would make every later release a downgrade.
if [ -f "$PKG/version.php" ]; then
    grep -qE '\$plugin->version[[:space:]]*=[[:space:]]*20[0-9]{8};' "$PKG/version.php" \
        || report "version.php was not stamped with a YYYYMMDDXX version"
    grep -qE "\\\$plugin->release[[:space:]]*=[[:space:]]*'$RELEASE'" "$PKG/version.php" \
        || report "version.php was not stamped with release '$RELEASE'"
else
    report "version.php is missing from the ZIP"
fi

# 3) Language packs ship without the leading '~' machine-translation marker,
#    and nothing else in them changes. The source files keep their markers so
#    pending human reviews stay visible in Git.
strip_marker() {
    sed -E 's/^([[:space:]]*\$string\[[^]]+\][[:space:]]*=[[:space:]]*)(['"'"'"])~(.*)$/\1\2\3/' "$1"
}
count_marker() {
    grep -cE '^[[:space:]]*\$string\[[^]]+\][[:space:]]*=[[:space:]]*['"'"'"]~' "$1" || true
}

sourcemarkers=0
for src in lang/*/exelearning.php; do
    packaged="$PKG/$src"
    [ -f "$packaged" ] || { report "$src is missing from the ZIP"; continue; }

    sourcemarkers=$((sourcemarkers + $(count_marker "$src")))

    [ "$(count_marker "$packaged")" -eq 0 ] \
        || report "packaged $src still carries '~' machine-translation markers"

    strip_marker "$src" | diff -q - "$packaged" > /dev/null \
        || report "packaged $src differs from the source beyond stripping '~'"
done

if [ "$sourcemarkers" -eq 0 ]; then
    echo "NOTE: no '~' markers left in lang/; the stripping assertion proved nothing."
fi

# 4) Development-only paths stay out of the distributed plugin.
for excluded in research scripts .github AGENTS.md composer.json codecov.yml tests/js; do
    if [ -e "$PKG/$excluded" ]; then
        report "$excluded must not ship in the release ZIP"
    fi
done

# 5) Runtime files that must ship.
for required in README.md LICENSE thirdpartylibs.xml lib.php view.php lang/en/exelearning.php; do
    [ -e "$PKG/$required" ] || report "$required is missing from the release ZIP"
done

# 5b) The bundled editor ships and is declared (DEC-0065): dist/static/ with its
#     index.html inside the ZIP, and a thirdpartylibs.xml entry carrying the
#     .editor-version version and the AGPL licence.
[ -f "$PKG/dist/static/index.html" ] \
    || report "the release ZIP must bundle the editor at dist/static/"
expected_version="$(tr -d ' \t\r\n' < .editor-version)"
expected_version="${expected_version#v}"
grep -q "<location>dist/static</location>" "$PKG/thirdpartylibs.xml" \
    || report "packaged thirdpartylibs.xml must declare dist/static"
grep -q "<version>$expected_version</version>" "$PKG/thirdpartylibs.xml" \
    || report "packaged thirdpartylibs.xml must carry the editor version $expected_version"
grep -q "<license>AGPL-3.0-or-later</license>" "$PKG/thirdpartylibs.xml" \
    || report "packaged thirdpartylibs.xml must declare the editor licence AGPL-3.0-or-later"

# 6) The packager works on a temporary index and must never touch the tree:
#    the stamped version.php and the stripped language files exist only inside
#    the ZIP.
git status --porcelain > "$WORK/tree-after.txt"
diff -q "$WORK/tree-before.txt" "$WORK/tree-after.txt" > /dev/null \
    || report "package.sh changed the working tree: $(diff "$WORK/tree-before.txt" "$WORK/tree-after.txt" | tr '\n' ' ')"

if [ "$fail" -ne 0 ]; then
    echo "Release packaging guard FAILED."
    exit 1
fi

echo "OK: release packaging requires the bundled editor, stamps version.php and thirdpartylibs.xml, strips '~' markers and keeps dev files out."
