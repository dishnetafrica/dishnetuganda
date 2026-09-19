#!/usr/bin/env bash
#
# build-zip.sh — package this plugin for upload to UISP/uCRM.
#
# uCRM looks for manifest.json at the ROOT of the archive. Zipping the
# containing folder instead of its contents produces
# "Plugin manifest could not be found in the ZIP archive." That is the only
# thing this script exists to get right.
#
set -euo pipefail
cd "$(dirname "$0")"

OUT="${1:-../dishnet-hybrid-sudan.zip}"

# Never ship runtime state: the database, logs, or a config file holding secrets.
find data -mindepth 1 -delete 2>/dev/null || true
touch data/.gitkeep

# Stamp the build. A ZIP is a file that can be lost or renamed; the plugin
# directory on the server outlives it. build.json says which commit the
# installed files came from, so "what is running on uCRM?" is answered by
# `cat build.json` in the plugin directory, and the same ZIP is rebuilt with
# `git checkout <commit> && bash build-zip.sh`. Written for the archive only:
# it is removed from the tree afterwards and never committed.
VERSION="$(php -r '$m = json_decode((string)file_get_contents("manifest.json"), true); echo (string)($m["information"]["version"] ?? "");' 2>/dev/null || true)"
COMMIT="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
DIRTY="false"
if [ -n "$(git status --porcelain -- . 2>/dev/null | grep -v ' data/.gitkeep$' || true)" ]; then DIRTY="true"; fi
trap 'rm -f build.json' EXIT
printf '{\n  "version": "%s",\n  "commit": "%s",\n  "commit_short": "%s",\n  "branch": "%s",\n  "built_at": "%s",\n  "tree_dirty": %s\n}\n' \
  "$VERSION" "$COMMIT" "${COMMIT:0:7}" "$BRANCH" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$DIRTY" > build.json

rm -f "$OUT"
zip -qr "$OUT" . -x '*.DS_Store' 'build-zip.sh' '.gitkeep'

# Verify rather than assume.
#
# Read the listing into a variable first. Piping into `grep -q` under
# `set -o pipefail` reports failure even on success: grep exits at the first
# match, unzip takes SIGPIPE, and pipefail surfaces unzip's status.
listing="$(unzip -l "$OUT")"
if ! grep -qE ' manifest\.json$' <<< "$listing"; then
  echo "FAIL: manifest.json is not at the archive root" >&2
  exit 1
fi

echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
echo "$listing" | tail -1
echo "build.json: version=$VERSION commit=${COMMIT:0:7} branch=$BRANCH tree_dirty=$DIRTY"
if [ "$DIRTY" = "true" ]; then
  echo "WARNING: the tree has uncommitted changes — this ZIP is not reproducible from commit ${COMMIT:0:7}" >&2
fi
