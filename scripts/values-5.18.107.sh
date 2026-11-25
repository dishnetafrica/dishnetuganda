#!/usr/bin/env bash
#
# values-5.18.107.sh — READ-ONLY: the production values 5.18.107's build needs (root docs/76 §N), 25 Nov 2026.
#
# It changes nothing — no table, no row, no setting, no file in the plugin or its data folder, nothing in uCRM or
# Evolution. It prints COUNTS, NAMES OF PRODUCTS AND PRICES, AND SHAPES ONLY: no phone number, no person's name, no
# message text, no key or token; a text setting is "set, its size, a hash prefix, digit counts"; an invoice number only
# its shape (<6d>). As root on the host:
#
#   1. it finds the uCRM container, the installed plugin, its data folder and the book's owner (the report wrappers'
#      discovery), refuses a root-owned book, and checks that the installed plugin is 5.18.106 (bf29cbd) and that the
#      twenty files the reader loads are 5.18.106's bytes;
#   2. it checks scripts/values-5.18.107.php beside it against the sha256 this file pins, and feeds it on stdin to php
#      inside the container, as the book's owner — nothing is copied into the container;
#   3. the reader (see its header) opens the book read-only (only when its -wal and -shm already exist), asks uCRM with
#      GET only, and prints the values with every run of nine or more digits withheld; this file masks again;
#   4. it prints the container's PATH (W.8: the harness fix needs it), then where the log is.
#
# The one command (it writes its own log, mode 600, under /root/dnb-sales):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/values-5.18.107.sh
#
# Then send back the LOG FILE with the `cat` line it prints at the end — never a copy of the terminal.
# There is no rollback, because nothing changes.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
PLUGINS_IN="$(dirname "$IN_CONTAINER")"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
READER="$HERE/values-5.18.107.php"
READER_SHA256="14192dcad935acd63d309a2a31ae99e06cdf127a6e8820e3737d2e2d1f7b6d9c"
LIVE_COMMIT_FULL="bf29cbd7171639f93e674ab174a6d09948b68932"   # 5.18.106 (release), live since 22 Nov 05:48Z
LIVE_SUMS="$(cat <<'SUMS'
500d5643bec2b143b190b9d9c49ddd374a35554a652a9827870fe6991630a635  lib/PluginConfig.php
bf7e1b1f1f5e132e0ad3af96ad20f5fcb011307cb83d14ee504abcc4ee581da7  lib/ConfigVault.php
153aebea2bff4d9ed13eafa99d444f79e1f32a0a7d7e726cb564d11ae755350a  lib/CrmApiClient.php
d8deb95ac3a6e09bf807a8b36dad065d3db1ad400c32b445b54f3e200a7384cd  lib/DishNetTools.php
6e37597c1e62fc7520c21feebc522ce0d07522dc41a81387fcff54203d185053  lib/TenantProfile.php
ac2fdc5608769292f515a1b43cd3b17c049c3f764309c27f077ccd954f6b0f1b  lib/StaffJobsGate.php
db2d2672b07ae4619555ea5de2a4ceeba97461d4f0e0d41a194d5885f26d6cdc  lib/ShopCatalogue.php
c7f6387757704c3f4690e88225b3460d02d6a188e7e290b9e8c088ed8d6873c7  lib/QuoteOrganization.php
011a16a20a04c1a896a3d6109433397a717cb81f964567750fcd952bd383fde0  lib/UcrmCatalogue.php
da0aa750bb2c60ab950ab6c7ed8c8c23e8a80f139a698ebc932b3d0064403e1c  lib/UcrmQuoteLinks.php
b36af2de417ed7a574ea3260f8d1101f4cca93ab738b4c9eb133977e0712376a  lib/UcrmQuoteLines.php
ee4bd76c14d3060e9113c8ef332b0c096233af98cb4e27f52b8b211e35ac7c39  lib/MediaPolicy.php
840df3dbdfba3e8c02009ce87c38a5eec028a2d42a91534b6cbc25a8682bb00c  lib/FlyerAsset.php
74fe9cd4fe028d6e4a56a7e567fb1a080af28abe657dbff5e03894a4e6af44fc  lib/KitTaxNote.php
7ba4d5235f6ce43e54aee4d0beafd019e5fa9ee41c34215a5275fadce5c2ca51  lib/PlanFenceGuard.php
b1a043e1667b9a21f51a9e0e7a034c801b1f838ceeebff9f22aef25f16948ab4  lib/KnowledgeBase.php
8f7c1dcdaa25e97cd0f49ba3a852e1cce8e86e6bac84513803b6274020849dea  lib/WaSendBudget.php
662e92542687d5a9bbe2579283e0c46ee895b6126a744011d5f727f5a085b5d5  lib/QuoteTracker.php
1a1c2bb5c9794324ead81064403468cd5fab1c4219582c88962bd68291cca3dd  lib/QuoteChase.php
05d48740e95280fbf95e7b96b0ddfc34f884d071d21b0be47d6720c0e0e8b0ed  lib/timezone.php
SUMS
)"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
LOGDIR="${DNB_LOG_DIR:-/root/dnb-sales}"
LOG="$LOGDIR/values-5.18.107-$TS.log"

mask() { sed -E 's/[0-9]{9,}/<digits>/g'; }
mkdir -p "$LOGDIR" && chmod 700 "$LOGDIR" && : > "$LOG" && chmod 600 "$LOG" || { echo "STOP: the log $LOG could not be made; nothing was read"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
stop() { printf '\n  STOP: %s\n  READ-ONLY — nothing was changed.\n  Send back the log:  cat %s\n' "$*" "$LOG"; exit 2; }

printf '== 5.18.107 production values — read-only — %s — %s ==\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(hostname)"
command -v docker >/dev/null 2>&1 || stop "docker is not available here"
[ "$(sha256sum "$READER" 2>/dev/null | cut -c1-64)" = "$READER_SHA256" ] || stop "scripts/values-5.18.107.php is not the reviewed one (sha256) — run: cd $(cd "$HERE/.." && pwd) && git pull origin claude/study-this-jhe2eg, then this again"
docker exec "$CONTAINER" test -f "$IN_CONTAINER/manifest.json" </dev/null 2>/dev/null || stop "the plugin is not installed at $IN_CONTAINER in the '$CONTAINER' container"
PDD="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null </dev/null || true)"
if [ -z "$PDD" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3" </dev/null 2>/dev/null; then PDD="$PLUGINS_IN/.$PLUGIN-data"; else PDD=""; fi
fi
[ -n "$PDD" ] && [ "${PDD%/}" != "$IN_CONTAINER/data" ] || stop "the plugin's data folder could not be found beside the plugin"
DB="$PDD/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB" </dev/null 2>/dev/null || stop "no book (plugin.sqlite3) in the data folder"
OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB" 2>/dev/null </dev/null || true)"
case "$OWNER" in ''|*[!0-9:]*) stop "the book's owner could not be read" ;; 0:*) stop "the book is root's — the plugin's own user must own it" ;; esac
REC="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null </dev/null | tail -n1 | tr -cd '0-9a-f')"
[ "${#REC}" -ge 7 ] && [ "${LIVE_COMMIT_FULL#"$REC"}" != "$LIVE_COMMIT_FULL" ] || stop "the installed plugin records commit '${REC:-none}', not 5.18.106 (${LIVE_COMMIT_FULL:0:7}): these readings were written for 5.18.106"
BAD=""; N=0
while read -r s f; do
  [ -n "$f" ] || continue; N=$((N + 1))
  got="$(docker exec "$CONTAINER" sha256sum "$IN_CONTAINER/$f" 2>/dev/null </dev/null | cut -c1-64)"
  [ "$got" = "$s" ] || BAD="$BAD $f"
done <<< "$LIVE_SUMS"
[ -z "$BAD" ] || stop "these installed files are not 5.18.106's bytes:$BAD"
echo "  installed       5.18.106 (commit $REC); the $N files the reader loads are 5.18.106's bytes; the book owned by $OWNER"
echo "  reader          scripts/values-5.18.107.php (sha256 ${READER_SHA256:0:12}…), fed on stdin; nothing is copied into the container"

docker exec -i -u "$OWNER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$IN_CONTAINER" "$PDD" < "$READER" 2>&1 | mask
RC="${PIPESTATUS[0]}"

echo
echo "── W.8: the container's PATH (for the rehearsal's fake docker) ───────────────────────────────────────────"
echo "  as the container's default user: $(docker exec "$CONTAINER" printenv PATH 2>/dev/null </dev/null | mask)"
echo "  as the book's owner:             $(docker exec -u "$OWNER" "$CONTAINER" printenv PATH 2>/dev/null </dev/null | mask)"
echo
echo "  exit $RC (0 done; 1 the reading stopped part-way; 2 refused before reading)"
echo "  READ-ONLY — nothing was changed"
echo "  Send back the log:  cat $LOG"
exit "$RC"
