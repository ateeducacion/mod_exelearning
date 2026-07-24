#!/usr/bin/env bash
#
# Build a distributable ZIP for the mod_exelearning Moodle plugin using ONLY git.
#
# `git archive --format=zip` writes ZIPs natively, so this needs no zip, rsync,
# python or php -- only git, which is guaranteed wherever the repo is cloned
# (including Git Bash on Windows). The built editor under dist/static/ is
# .gitignore'd (untracked), so it is staged into a throwaway index first.
#
# Usage: bash scripts/package.sh <RELEASE> [<PLUGIN_NAME>]
#
# version.php is stamped inside the temporary index (the working tree is never
# modified) and the produced ZIP places everything under the Moodle install
# folder "exelearning/" (the component is mod_exelearning).

set -euo pipefail

RELEASE="${1:-}"
PLUGIN_NAME="${2:-mod_exelearning}"
INSTALL_DIR="exelearning"

if [ -z "$RELEASE" ]; then
    echo "Error: RELEASE not specified. Usage: bash scripts/package.sh <RELEASE> [<PLUGIN_NAME>]" >&2
    exit 1
fi

# Defensively drop surrounding whitespace/CR and a stray trailing dot (see issue #22).
RELEASE="$(printf '%s' "$RELEASE" | tr -d ' \t\r\n')"
RELEASE="${RELEASE%.}"

command -v git >/dev/null 2>&1 || { echo "Error: git is required to build the package." >&2; exit 1; }

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

OUTPUT="$ROOT/$PLUGIN_NAME-$RELEASE.zip"
DATE_VERSION="$(date +%Y%m%d)00"

# Read .distignore patterns (skip blanks/comments, strip trailing slash and CR).
PATTERNS=()
while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in ''|\#*) continue ;; esac
    PATTERNS+=("${line%/}")
done < .distignore

# A path is excluded if its top component OR its full relative path matches any
# pattern (same semantics as the sibling plugins' package.py).
is_excluded() {
    local rel="$1" top="${1%%/*}" p
    for p in "${PATTERNS[@]}"; do
        case "$top" in $p) return 0 ;; esac
        case "$rel" in $p) return 0 ;; esac
    done
    return 1
}

# Stage everything into a temporary index whose objects live in a temporary
# object store, so the real repository is never touched or polluted.
TMPOBJ="$(mktemp -d)"
TMPIDX="$(mktemp)"; rm -f "$TMPIDX"
export GIT_INDEX_FILE="$TMPIDX"
export GIT_OBJECT_DIRECTORY="$TMPOBJ"
export GIT_ALTERNATE_OBJECT_DIRECTORIES="$ROOT/.git/objects"
cleanup() { rm -rf "$TMPOBJ" "$TMPIDX"; }
trap cleanup EXIT

# Tracked + untracked files (including the gitignored dist/static/), minus
# .distignore matches, hashed into the temp index.
while IFS= read -r -d '' f; do
    is_excluded "$f" && continue
    printf '%s\0' "$f"
done < <(git ls-files -z -c -o) | git update-index -z --add --stdin

# Stamp version.php in the index only (working tree stays at the dev sentinels).
stamped_sha="$(
    sed -e "s/\(plugin->version[[:space:]]*=[[:space:]]*\)[0-9]*/\1$DATE_VERSION/" \
        -e "s/\(plugin->release[[:space:]]*=[[:space:]]*'\)[^']*/\1$RELEASE/" version.php \
    | git hash-object -w --stdin
)"
git update-index --add --cacheinfo "100644,$stamped_sha,version.php"

# Stamp thirdpartylibs.xml in the index only: the committed file must not list
# dist/static (the path is absent in a plain checkout and would break
# moodle-plugin-ci install), but the release ZIP bundles the editor and must
# declare it (see the header comment in thirdpartylibs.xml). The editor version
# is read from .editor-version, which is .distignore'd, so it is taken from the
# working tree here.
if [ -d dist/static ] && [ -n "$(ls -A dist/static 2>/dev/null)" ]; then
    EDITOR_VERSION="$(tr -d ' \t\r\n' < .editor-version 2>/dev/null || true)"
    EDITOR_VERSION="${EDITOR_VERSION#v}"
    [ -n "$EDITOR_VERSION" ] || EDITOR_VERSION="unknown"
    tpl_sha="$(
        sed "s#^</libraries>#  <library>\\
    <location>dist/static</location>\\
    <name>eXeLearning (static editor build)</name>\\
    <description>Embedded eXeLearning v4 editor, built from https://github.com/exelearning/exelearning and bundled into the release ZIP.</description>\\
    <version>$EDITOR_VERSION</version>\\
    <license>AGPL-3.0-or-later</license>\\
  </library>\\
</libraries>#" thirdpartylibs.xml \
        | git hash-object -w --stdin
    )"
    git update-index --add --cacheinfo "100644,$tpl_sha,thirdpartylibs.xml"
fi

# Remove one leading machine-translation marker from packaged language strings.
# Source files remain unchanged so pending human reviews stay visible in Git.
# The $ of $string is escaped as \\\$ so the shell passes a literal \$ to sed
# instead of expanding an (unset) "string" variable, which aborts under `set -u`.
while IFS= read -r -d '' langfile; do
    cleaned_sha="$(
        sed -E "s/^([[:space:]]*\\\$string\\[[^]]+\\][[:space:]]*=[[:space:]]*)(['\"])~(.*)$/\\1\\2\\3/" "$langfile" \
        | git hash-object -w --stdin
    )"
    git update-index --add --cacheinfo "100644,$cleaned_sha,$langfile"
done < <(git ls-files -z -- 'lang/*/exelearning.php')

echo "Packaging release $RELEASE (version $DATE_VERSION) -> $PLUGIN_NAME-$RELEASE.zip"
TREE="$(git write-tree)"
rm -f "$OUTPUT"
git archive --format=zip --prefix="$INSTALL_DIR/" -o "$OUTPUT" "$TREE"
echo "Package created: $PLUGIN_NAME-$RELEASE.zip"
