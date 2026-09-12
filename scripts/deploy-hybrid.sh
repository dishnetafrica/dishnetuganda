#!/usr/bin/env bash
#
# deploy-hybrid.sh — put this checkout into the directory uCRM actually runs.
#
#   bash scripts/deploy-hybrid.sh            deploy, then prove it landed
#   bash scripts/deploy-hybrid.sh --check    say what is live, change nothing
#
# ─── Why this exists ─────────────────────────────────────────────────────────
# A git pull in this checkout deploys nothing. The checkout is not what uCRM
# serves: the container mounts its own data directory, and the plugin lives
# under that mount. For one evening the pulls all reported success, every file
# updated on disk, and the container went on running code from hours earlier —
# including a tool invoked with a flag it did not have, which silently ignored
# the flag and ran its default path instead. That looked exactly like a working
# deploy. It cost a live Starlink session and most of a night.
#
# So this script does not guess the destination. It asks Docker where the
# container's /data comes from and derives the path from the answer.
#
# It also records the deployed commit inside the served tree and reads it back
# THROUGH the container, so "deployed" means the container can see it, not that
# a copy command exited zero.
#
# ─── What it will not do ─────────────────────────────────────────────────────
# It never deletes. It never touches data/ — plugin runtime state lives there
# and in the sibling .dishnet-hybrid-sudan-data directory, and neither belongs
# to this repository.

set -euo pipefail

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"

die() { echo "  ✗ $*" >&2; exit 1; }

# Read .deployed-commit from inside the container, waiting out a restart.
#
# uCRM recycles its own container when plugin files change, so the read
# immediately after a copy can land while the container is between tasks.
# containerd then answers "NotFound: task <id> not found" — ON STDOUT, which
# went straight into the variable and got compared against a commit. The
# deploy had worked; the script said it had not, and told the operator not to
# trust it.
#
# So: retry for a bounded window, and accept only something shaped like a
# short commit. Anything else is "could not read", which is a different
# outcome from "read, and it is the wrong commit" — one means wait, the other
# means the deploy failed.
#
# Echoes the commit, or nothing. Never echoes an error message.
read_live() {
    local tries="${1:-1}" out
    while [ "$tries" -gt 0 ]; do
        out="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n 1 | tr -d '[:space:]')" || true
        case "$out" in
            *[!0-9a-f]*|'') ;;              # error text, or empty
            *) echo "$out"; return 0 ;;     # hex only — a commit
        esac
        tries=$((tries - 1))
        # An `if`, not `&&` — under `set -e` a trailing `&&` that short-circuits
        # leaves the loop body with status 1 and can kill the script.
        if [ "$tries" -gt 0 ]; then sleep 2; fi
    done
    return 1
}

[ -f "$SRC/manifest.json" ] || die "no plugin at $SRC (manifest.json missing)"
command -v docker >/dev/null 2>&1 || die "docker not on PATH"

# Ask the container where its /data actually comes from, rather than assuming.
MOUNT="$(docker inspect "$CONTAINER" \
    --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || die "container '$CONTAINER' has no /data mount (set UCRM_CONTAINER?)"

DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"

# plugins_staging is where uCRM unpacks an uploaded zip. It is not what runs,
# and deploying into it would look like it worked.
case "$DEST" in *plugins_staging*) die "refusing to deploy into staging: $DEST";; esac
[ -f "$DEST/manifest.json" ] || die "no installed plugin at $DEST — install it through uCRM once first"

LIVE="$(read_live 3 || echo 'unknown')"
HEAD="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo 'unknown')"

echo
echo "  repo      $REPO  ($HEAD)"
echo "  serves    $DEST"
echo "  live      $LIVE"

if [ "${1:-}" = "--check" ]; then
    echo
    [ "$LIVE" = "$HEAD" ] && echo "  Up to date." || echo "  NOT up to date — run without --check to deploy."
    echo
    exit 0
fi

# Whoever owns the destination owns it afterwards. Extracting as root applied
# the archive's own ./ entry to $DEST and changed it from unms:unms 775 to
# root:root 755, so uCRM's user could no longer write into its own plugin
# directory. It stopped calling the plugin at that exact minute, and nothing
# said why: heartbeat.log simply stopped having new lines.
OWNER="$(stat -c '%u:%g' "$DEST")"
MODE="$(stat -c '%a' "$DEST")"

# tar rather than rsync: rsync is not always installed, and tar's exclude is
# unambiguous about which data directory it means. --no-overwrite-dir keeps
# the metadata of directories that already exist.
tar -C "$SRC" --exclude=./data --exclude=./.git -cf - . \
  | tar -C "$DEST" --no-overwrite-dir -xf -

echo "$HEAD" > "$DEST/.deployed-commit"

# Belt and braces: --no-overwrite-dir protects $DEST itself, this covers files
# and subdirectories created by the extraction.
chown -R "$OWNER" "$DEST" 2>/dev/null || echo "  ! could not chown to $OWNER — check the plugin still runs"
chmod "$MODE" "$DEST" 2>/dev/null || true

# The only verification that counts: read it back from inside the container.
# Up to ~30s, because the copy itself is what makes uCRM recycle.
SEEN="$(read_live 15 || true)"
echo
if [ "$SEEN" = "$HEAD" ]; then
    echo "  ✓ container now serves $SEEN"
    echo
elif [ -z "$SEEN" ]; then
    echo "  ? container did not answer within 30s — it is most likely still"
    echo "    restarting, which uCRM does by itself when plugin files change."
    echo "    The files are copied and in place. Confirm once it is up:"
    echo
    echo "      bash scripts/deploy-hybrid.sh --check"
    echo
    exit 2
else
    echo "  ✗ container still reports '$SEEN', expected '$HEAD'"
    echo "    The copy succeeded but the container cannot see it. Do not treat"
    echo "    this as deployed."
    echo
    exit 1
fi
