#!/usr/bin/env bash
#
# install_shadow.sh — put this branch's code onto a live plugin install.
#
# Run it from a checkout of the branch; it copies into the INSTALLED plugin,
# which is somewhere else:
#
#     bash tools/install_shadow.sh                     find the plugin, install
#     bash tools/install_shadow.sh --target /path/...  when it cannot, or must not, guess
#     bash tools/install_shadow.sh --rollback          put back exactly what was there
#     bash tools/install_shadow.sh --dry-run           say what it would do, change nothing
#
# ── WHY IT SYNCS EVERYTHING RATHER THAN A FILE LIST ─────────────────────
#
# The first version of this copied the nine files B3.3 and B3.4 touched. That
# was wrong twice over. The security work actually spans seventeen production
# files back to B1 — AiSecurityPolicy, BrainContext, ConversationService,
# DishNetAiBrain, DishNetTools, ShopBotPayload, web_chat and InboundMailWorker
# among them — and installing the last two phases onto a base without the
# first five produces a plugin that boots, answers customers, and is subtly
# wrong. Running the suite inside a simulated install caught it: eighteen
# failures across eight files.
#
# And seventeen is only right if the server sits exactly on the commit I
# diffed against, which is not something this script can know. So it does what
# a ZIP upload does: replace the code, keep the data.
#
# ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────
#
# It does not enable anything. ai_shadow_compare is untouched, so the
# customer-facing path is unchanged until somebody runs --begin deliberately.
#
# It never touches data/. That directory holds the database, the logs and
# possibly ucrm.json — the plugin's uCRM key and data-directory pointer.
# (build-zip.sh DELETES its contents. That is why build-zip.sh is a
# build-machine script and this is the one that goes near a server.)
#
# It copies over, and never deletes. ucrm.json is written by uCRM and is not
# in the repository, so a sync that deleted unknown files would take the
# plugin's credentials with it.
#
# It does not choose between two candidate installs. If the search finds more
# than one it lists them and stops, for the same reason cliDataDir() refuses
# to pick a database: a tool that writes must be aimed by a person.
#
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SELF="$SRC/tools/$(basename "${BASH_SOURCE[0]}")"
PLUGIN='dishnet-hybrid-sudan'
BACKUP_ROOT="/var/tmp/${PLUGIN}-backup"
EXCLUDES=(--exclude=./data --exclude=./.git --exclude=./build-zip.sh --exclude='*.zip')

say()   { printf '  %s\n' "$*"; }
head_() { printf '\n  %s\n  ------------------------------------------------------------------\n' "$*"; }
die()   { printf '\n  STOPPED: %s\n\n' "$*" >&2; exit 1; }

TARGET=''; ROLLBACK=0; DRY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --target)   TARGET="${2:-}"; shift 2 ;;
    --rollback) ROLLBACK=1; shift ;;
    --dry-run)  DRY=1; shift ;;
    -h|--help)  sed -n '2,46p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)          die "unknown argument: $1" ;;
  esac
done

# ── Find the installed plugin ───────────────────────────────────────────
if [ -z "$TARGET" ]; then
  head_ "Looking for the installed plugin"
  FOUND=()
  for base in /home/unms/data/ucrm/ucrm/data/plugins /data/ucrm/data/plugins \
              /usr/src/ucrm/data/plugins /var/www/ucrm/data/plugins; do
    [ -f "$base/$PLUGIN/manifest.json" ] && FOUND+=("$base/$PLUGIN")
  done
  if [ "${#FOUND[@]}" -eq 0 ]; then
    say "not in the usual places; searching (up to 45s)"
    while IFS= read -r d; do FOUND+=("$d"); done < <(
      timeout 45 find / -maxdepth 8 -name manifest.json -path "*/$PLUGIN/*" \
           -not -path "$SRC/*" 2>/dev/null | xargs -r -n1 dirname | sort -u)
  fi
  case "${#FOUND[@]}" in
    0) die "no installed $PLUGIN found. Name it: --target /path/to/plugins/$PLUGIN" ;;
    1) TARGET="${FOUND[0]}"; say "found  $TARGET" ;;
    *) printf '\n  MORE THAN ONE INSTALL FOUND\n\n'; printf '    %s\n' "${FOUND[@]}"
       die "name the one you mean: --target <path>" ;;
  esac
fi

[ -d "$TARGET" ]               || die "$TARGET is not a directory"
[ -f "$TARGET/manifest.json" ] || die "$TARGET has no manifest.json — that is not a plugin"
grep -q "\"name\": *\"$PLUGIN\"" "$TARGET/manifest.json" || die "$TARGET is not $PLUGIN"
TARGET="$(cd "$TARGET" && pwd)"
[ "$TARGET" != "$SRC" ] || die "the target is this checkout — nothing to do"

# ── Rollback ────────────────────────────────────────────────────────────
if [ "$ROLLBACK" -eq 1 ]; then
  LAST="$(ls -1 "$BACKUP_ROOT"/*.tar.gz 2>/dev/null | sort | tail -1 || true)"
  [ -n "$LAST" ] || die "no backup under $BACKUP_ROOT"
  head_ "Restoring $TARGET from $LAST"
  tar -xzf "$LAST" -C "$TARGET"
  say "restored $(tar -tzf "$LAST" | grep -vc '/$') file(s), byte for byte"
  say ""
  say "Code only — data/ was never in the backup because it was never touched."
  say ""
  say "Files the install ADDED are still on disk: a backup of what was there"
  say "cannot contain files that did not exist. They are inert — with the"
  say "worker restored, nothing requires ShadowCompare, ShadowObservation,"
  say "BrainContext or ShopBotPayload. Remove them by hand if you want the"
  say "tree bit-identical to what it was."
  say "If the observation was running, close it too:"
  say "    php $TARGET/tools/shadow_observe.php --end"
  printf '\n'
  exit 0
fi

# ── Check the source before touching anything ───────────────────────────
head_ "Checking the source"
command -v php >/dev/null || die "php is not on PATH; this needs php-cli"
command -v tar >/dev/null || die "tar is not on PATH"
for f in manifest.json lib/ShadowCompare.php lib/ShadowObservation.php \
         tools/shadow_observe.php workers/AiReplyWorker.php; do
  [ -f "$SRC/$f" ] || die "missing from this checkout: $f  (wrong branch?)"
done
BAD=0
while IFS= read -r f; do
  php -l "$f" >/dev/null 2>&1 || { say "does not parse: ${f#$SRC/}"; BAD=$((BAD+1)); }
done < <(find "$SRC" -name '*.php' -not -path "$SRC/data/*" -not -path "$SRC/.git/*")
[ "$BAD" -eq 0 ] || die "$BAD source file(s) do not parse — refusing to copy them"
say "every PHP file in the source parses"
say "from    $SRC"
say "commit  $(git -C "$SRC" rev-parse --short HEAD 2>/dev/null || echo 'not a git checkout')"
say "into    $TARGET"

if [ "$DRY" -eq 1 ]; then
  head_ "Dry run — nothing will change"
  say "would back up the target's code to $BACKUP_ROOT/<stamp>.tar.gz"
  say "would copy $(cd "$SRC" && tar -cf - "${EXCLUDES[@]}" . | tar -tf - | grep -vc '/$') file(s)"
  say "would leave data/ untouched and delete nothing"
  printf '\n'
  exit 0
fi

# ── Back up the target's code, exactly as it stands ─────────────────────
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$BACKUP_ROOT/$STAMP.tar.gz"
head_ "Backing up"
mkdir -p "$BACKUP_ROOT"
( cd "$TARGET" && tar -czf "$BACKUP" "${EXCLUDES[@]}" . )
say "$(tar -tzf "$BACKUP" | grep -vc '/$') file(s) -> $BACKUP"

# ── Copy ────────────────────────────────────────────────────────────────
head_ "Installing"
( cd "$SRC" && tar -cf - "${EXCLUDES[@]}" . ) | ( cd "$TARGET" && tar -xf - )
say "$(cd "$SRC" && tar -cf - "${EXCLUDES[@]}" . | tar -tf - | grep -vc '/$') file(s) copied"
say "data/ untouched, nothing deleted"

# ── Verify, rather than assume ──────────────────────────────────────────
head_ "Verifying"
fails=0
n=$(grep -c 'shadowCompare' "$TARGET/workers/AiReplyWorker.php" || true)
[ "$n" = "3" ] && say "ok    the worker carries the shadow path" \
               || { say "FAIL  worker has $n shadowCompare references, expected 3"; fails=$((fails+1)); }
php -r 'require $argv[1]."/lib/ShadowCompare.php";
        exit(count(ShadowCompare::FACTS) === 5 ? 0 : 1);' "$TARGET" \
  && say "ok    ShadowCompare loads and declares its five facts" \
  || { say "FAIL  ShadowCompare did not load"; fails=$((fails+1)); }
php -r 'require $argv[1]."/lib/ShadowObservation.php";
        exit(ShadowObservation::MIN_CUSTOMERS === 3 ? 0 : 1);' "$TARGET" \
  && say "ok    ShadowObservation loads" \
  || { say "FAIL  ShadowObservation did not load"; fails=$((fails+1)); }
[ -f "$TARGET/data/ucrm.json" ] || [ -f "$TARGET/ucrm.json" ] \
  && say "ok    ucrm.json is still in place" \
  || say "note  no ucrm.json found — normal on a test install, not on a live one"

# The gate is the security suite, not the whole suite.
#
# A dozen tests in tests/ spawn fake HTTP servers on ports derived from the
# pid — fine on a developer's machine, fragile on a uCRM host where those
# ports may be taken or blocked. Gating a deploy on them means an operator
# rolls back a CORRECT install because a port was busy. (Measured: the full
# suite reported ten failures inside a simulated install while every one of
# the tests below passed.)
#
# These twelve touch no network and are exactly what is being deployed.
GATE=(test_shadow_compare test_shadow_observation test_brain_customer_tools
      test_customer_data_tools test_brain_context test_ai_security_policy
      test_history_identity test_shopbot_payload test_tools_phone_identity
      test_reply_privacy_guard test_guard_blocks_send test_customer_identity)
if [ -d "$TARGET/tests" ]; then
  say ""
  say "running the security suite in the installed copy"
  tot=0; bad=0
  for t in "${GATE[@]}"; do
    [ -f "$TARGET/tests/$t.php" ] || { say "FAIL  missing  $t"; bad=$((bad+1)); continue; }
    line="$( cd "$TARGET" && php "tests/$t.php" 2>&1 | tail -1 )"
    n="$(printf '%s' "$line" | grep -oE '^[0-9]+' || echo 0)"
    f="$(printf '%s' "$line" | grep -oE '[0-9]+ failed' | grep -oE '^[0-9]+' || echo 1)"
    tot=$((tot+n))
    [ "$f" = "0" ] || { say "FAIL  $t -> $line"; bad=$((bad+1)); }
  done
  [ "$bad" -eq 0 ] && say "ok    ${#GATE[@]} security suites, $tot assertions, 0 failed" \
                   || fails=$((fails+bad))
fi

if [ "$fails" -gt 0 ]; then
  printf '\n  %d CHECK(S) FAILED — roll back before doing anything else:\n' "$fails"
  printf '      bash %s --target %s --rollback\n\n' "$SELF" "$TARGET"
  exit 1
fi

head_ "Current state"
( cd "$TARGET" && php tools/shadow_observe.php --status ) || true

cat <<EOF
  ------------------------------------------------------------------
  Installed. NOTHING IS ENABLED — the customer-facing path is
  unchanged, and ai_shadow_compare stays off until you say otherwise.

  Start the observation when you are ready:
      cd $TARGET && php tools/shadow_observe.php --begin

  Check on it any time (read-only, safe to repeat):
      php tools/shadow_observe.php --report

  Close it and restore the exact previous config:
      php tools/shadow_observe.php --end

  Undo this install:
      bash $SELF --target $TARGET --rollback

EOF
