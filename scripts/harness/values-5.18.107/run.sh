#!/usr/bin/env bash
#
# run.sh — the harness for scripts/values-5.18.107.{sh,php}, as root, from any folder. It leaves nothing behind.
#
# A sandbox install: the 5.18.106 plugin tree (git archive bf29cbd), its data folder and book built by build.php
# (the plugin's own migrations, fixture rows, planted PLANT_* strings), a fake uCRM (ucrm.php under php -S) that logs
# every request, a process of uid 1000 holding the book open (as the workers do, so its -wal/-shm exist), and a fake
# `docker` that runs `exec -u UID:GID` through setpriv with the container's PATH. Scenarios:
#   S1 the run: every section's expected lines; privacy (no PLANT_* string, no phone digits, no key); read-only (every
#      file of the data folder and the plugin unchanged, no file added, uCRM asked GET only on its four paths, no
#      catalogue cache written); the log is mode 600 and names itself
#   S2 the book idle (no process holds it): not opened, and no -wal/-shm left behind
#   S3 the installed plugin is not 5.18.106          S4 an installed file the reader loads is not 5.18.106's bytes
#   S5 the reader is not the reviewed one            S6 a root-owned book
#   S7 uCRM does not answer: the run ends, says what it could not read, changes nothing
# then weakened copies — each must be caught: of the reader (R1-R5, run directly) and of the wrapper (X1-X5).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRCREPO="$(cd "$HERE/../../.." && pwd)"
COMMIT=bf29cbd7171639f93e674ab174a6d09948b68932
PLUGIN=dishnet-hybrid-sudan
WRAP="$SRCREPO/scripts/values-5.18.107.sh"; READ="$SRCREPO/scripts/values-5.18.107.php"
[ "$(id -u)" = 0 ] || { echo "run as root (it drops to uid 1000 for the container's user)"; exit 2; }
command -v setpriv >/dev/null || { echo "setpriv is needed"; exit 2; }
git -C "$SRCREPO" cat-file -e "$COMMIT^{commit}" 2>/dev/null || { echo "this checkout lacks $COMMIT"; exit 2; }

SB="$(mktemp -d /tmp/v107-XXXXXX)"; chmod 755 "$SB"
UPID=""; HPID=""
cleanup() { [ -n "$HPID" ] && touch "$SB/stop" 2>/dev/null; [ -n "$UPID" ] && kill "$UPID" 2>/dev/null; wait 2>/dev/null; rm -rf "$SB"; }
trap cleanup EXIT
PASS=0; FAIL=0
ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
bad()   { FAIL=$((FAIL + 1)); echo "  FAIL $1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | sed 's/^/       /' | head -30; return 0; }
check() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3" "got [$1], wanted [$2]"; fi; }
has()   { if grep -qF -- "$2" <<< "$1"; then ok "$3"; else bad "$3" "not found: $2"; fi; }
hasnt() { if grep -qF -- "$2" <<< "$1"; then bad "$3" "found: $2"; else ok "$3"; fi; }
hasre() { if grep -qE -- "$2" <<< "$1"; then ok "$3"; else bad "$3" "no match: $2"; fi; }

# ── the sandbox ──────────────────────────────────────────────────────────────────────────────────────────────────────
mkdir -p "$SB/plugins" "$SB/bin" "$SB/logs" "$SB/ctmp" && chmod 1777 "$SB/ctmp"
git -C "$SRCREPO" archive "$COMMIT" "$PLUGIN" | tar -x -C "$SB/plugins" || { echo "archive failed"; exit 2; }
P="$SB/plugins/$PLUGIN"; D="$SB/plugins/.$PLUGIN-data"
PORT=$(( 20000 + RANDOM % 20000 ))
: > "$SB/ucrm.log"; chmod 666 "$SB/ucrm.log"
start_ucrm() { UCRM_LOG="$SB/ucrm.log" php -S "127.0.0.1:$PORT" "$HERE/ucrm.php" >/dev/null 2>&1 & UPID=$!; for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/api/v2.1/organizations" && break; sleep 0.1; done; : > "$SB/ucrm.log"; }
start_ucrm
php -d log_errors=0 "$HERE/build.php" "$P" "$D" "http://127.0.0.1:$PORT" >/dev/null || { echo "build.php failed"; exit 2; }
chown -R 1000:1000 "$D"; chmod 755 "$SB/plugins"
# a process of uid 1000 holding the book open, as the workers do; it closes it cleanly (so its -wal/-shm go) when told
hold() { rm -f "$SB/stop"; setpriv --reuid=1000 --regid=1000 --clear-groups php -r '$p = new PDO("sqlite:" . $argv[1]); $p->query("SELECT COUNT(*) FROM events")->fetchColumn(); $n = 0; while (!is_file($argv[2]) && $n++ < 9000) usleep(100000); $p = null;' "$D/plugin.sqlite3" "$SB/stop" & HPID=$!
         for _ in $(seq 50); do [ -f "$D/plugin.sqlite3-shm" ] && break; sleep 0.1; done; }
unhold() { [ -n "$HPID" ] && { touch "$SB/stop"; wait "$HPID" 2>/dev/null; }; HPID=""; }
hold

cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
[ "\$1" = exec ] || { echo "fake docker: only exec" >&2; exit 1; }; shift
user=""; while :; do case "\$1" in -u) user="\$2"; shift 2 ;; -i) shift ;; *) break ;; esac; done
[ "\$1" = ucrm ] || { echo "Error: No such container: \$1" >&2; exit 1; }; shift
E=(env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/tmp TMPDIR="$SB/ctmp")
if [ -n "\$user" ]; then exec setpriv --reuid="\${user%%:*}" --regid="\${user##*:}" --clear-groups "\${E[@]}" "\$@"; else exec "\${E[@]}" "\$@"; fi
SH
chmod 755 "$SB/bin/docker"

# every file of the data folder and the plugin, but the -shm index the reader may touch as SQLite's own bookkeeping
snap() { (cd "$SB/plugins" && find . -type f ! -name 'plugin.sqlite3-shm' -print0 | sort -z | xargs -0 sha256sum); }
run_wrap() {  # $1 = the wrapper to run (it reads the reader beside it)
  local w="$1"; rm -f "$SB/logs/"*
  OUT="$(PATH="$SB/bin:$PATH" IN_CONTAINER="$P" DNB_LOG_DIR="$SB/logs" bash "$w" 2>&1 </dev/null)"; RC=$?
  sleep 0.3; LOGF="$(ls "$SB/logs/"values-5.18.107-*.log 2>/dev/null | head -1)"; LOGT="$( [ -n "$LOGF" ] && cat "$LOGF")"
}
run_read() {  # $1 = a reader, run directly as the container's user (the weakened copies of the reader)
  OUT="$(PATH="$SB/bin:$PATH" docker exec -i -u 1000:1000 ucrm php -d display_errors=stderr -d log_errors=0 -- "$P" "$D" < "$1" 2>&1)"; RC=$?
}
leaks() {  # $1 = text: every planted string, phone digit run or key it holds
  local t="$1" leaked=""
  for s in $(grep -ohE 'PLANT_[A-Za-z0-9_]+' "$HERE/build.php" "$HERE/ucrm.php" | sort -u); do grep -qF "$s" <<< "$t" && leaked="$leaked $s"; done
  for s in 256000 0000999888 0000123456 1234567890 9988776655 'sk-' 'PLANT@'; do grep -qF -- "$s" <<< "$t" && leaked="$leaked $s"; done
  printf '%s' "${leaked:- none}"
}
privacy() { check "$(leaks "$1")" " none" "$2: nothing planted, no phone digits, no key"; }

# ── S1 ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- S1 the run"
check "$(sed -n 's/^READER_SHA256="\([0-9a-f]\{64\}\)"$/\1/p' "$WRAP")" "$(sha256sum "$READ" | cut -c1-64)" "the wrapper pins the reader's sha256"
for f in $(sed -n 's/^[0-9a-f]\{64\}  \(lib\/[A-Za-z]*\.php\)$/\1/p' "$WRAP"); do
  check "$(sed -n "s#^\([0-9a-f]\{64\}\)  $f\$#\1#p" "$WRAP")" "$(git -C "$SRCREPO" show "$COMMIT:$PLUGIN/$f" | sha256sum | cut -c1-64)" "the wrapper's sum for $f is bf29cbd's"
done
B="$(snap)"; : > "$SB/ucrm.log"
run_wrap "$WRAP"
[ -n "${V107_KEEP:-}" ] && printf '%s\n' "$OUT" > "$V107_KEEP"
check "$RC" 0 "exit 0"
check "$LOGT" "$OUT" "the log holds exactly what the terminal showed"
check "$(stat -c %a "$LOGF")" 600 "the log is mode 600"
has "$OUT" "Send back the log:  cat $LOGF" "it ends with the cat line naming its log"
has "$OUT" "installed       5.18.106 (commit bf29cbd); the 20 files the reader loads are 5.18.106's bytes; the book owned by 1000:1000" "the installed code checked"
has "$OUT" "the book: opened read-only (query_only, one read transaction)" "the book opened read-only"
has "$OUT" 'ai_hardware_expert           worker "1" · row "1" → the worker reads it as ON' "ai_hardware_expert, both sources"
has "$OUT" 'bot_instructions_mode        worker absent · row "override" → the worker reads it as append' "the mode, where it is stored"
hasre "$OUT" 'bot_custom_instructions      worker set, 28 bytes / 28 chars, sha256 [0-9a-f]{12}, digit runs of 5\+: 0, phone-shaped: 0 · row set, 26 bytes' "the custom text as a size and hash, both sources"
has "$OUT" "override in effect on the worker (mode \"override\" AND custom text not empty; the knowledge base and business facts then leave the prompt): no" "override on the worker"
has "$OUT" "flyer: file" "the flyer"; has "$OUT" "wa_flyer.jpg: 10 KB, usable" "the flyer file"; has "$OUT" "wa_flyer_url: set, scheme https" "the flyer URL as a scheme"
has "$OUT" 'ai_fact_kit_taxes      worker "omit"' "a fact set to omit"
hasre "$OUT" 'ai_fact_payment        worker set, 35 bytes / 35 chars, sha256 [0-9a-f]{12}, digit runs of 5\+: 1, phone-shaped: 1 · row not set' "a fact with an account number: counted, never shown"
hasre "$OUT" 'ai_fact_office         worker set, [0-9]+ bytes .*phone-shaped: 1' "a fact with a spaced phone number: counted"
has "$OUT" "ai_fact_prices         worker empty" "an empty fact"
has "$OUT" "the kit tax note the worker appends: none (hardware module off, or \"omit\")" "the kit tax note"
has "$OUT" "the business-plan note: the code's default sentence" "the business note's default"
has "$OUT" 'rows by kind and status: "fact"/"approved" 1, "fact"/"disabled" 1, "rule"/"approved" 1' "the knowledge base by kind and status"
hasre "$OUT" 'OFFICE_LOCATION: kind "fact", status "approved", approved \(rendered\): yes, updated [0-9]{4}-[0-9]{2}-[0-9]{2}T' "OFFICE_LOCATION's status"
hasre "$OUT" 'wa_answer: set, 33 bytes / 33 chars, sha256 [0-9a-f]{12}, digit runs of 5\+: 0, phone-shaped: 1, over the 300-char chat cut: no' "OFFICE_LOCATION's chat answer"
has "$OUT" "rows spelled like OFFICE_LOCATION but not exactly it: 1" "a near-duplicate key counted"
has "$OUT" "plugin data/config.json:     tenant_profile null · currency_code \"\" · cashbook_base_currency selects south-sudan" "the plugin's own file, by class"
has "$OUT" "without the vault: south-sudan (decided by currency_code)" "without the vault"
has "$OUT" "with the vault, as TenantProfile::current() reads it: uganda (decided by currency_code)" "with the vault"
has "$OUT" "the gate the workers ask (StaffJobsGate::applies on the worker view): yes" "the gate"
has "$OUT" "the vault: the data folder's config_vault.json (the plugins folder is not writable by this user); present yes, readable yes, valid yes; tenant_profile absent · currency_code selects uganda · cashbook_base_currency absent" "the vault, as classes"
has "$OUT" "profiles/uganda.json: present yes, readable yes, valid yes" "the profile readable"
has "$OUT" "alert_whatsapp               worker international with +, 12 digits · row local, starting with 0, 10 digits" "the alert number as a form"
has "$OUT" 'alert_patience_minutes       worker "" · row absent → 0 minutes' "a blank patience setting reads 0"
has "$OUT" 'wa_handover_copy_central     worker absent · row absent → ON (unset)' "the central copy"
has "$OUT" 'wa_human_cooldown_minutes    worker "60" · row absent → 60 minutes' "the cooldown"
has "$OUT" "the voice rung on each number: sales dry_run · support dry_run · account dry_run · sales-001 dry_run" "the voice rung per number"
has "$OUT" 'the numbers registered: "account" "account"/"active" · "sales" "sales"/"active" · "sales-001" "sales"/"active" · "support" "support"/"active"' "the numbers, ids only"
has "$OUT" "quote tracking on (the worker view): yes · quote chase on: no · the zone in use: Africa/Kampala" "the quotation switches"
has "$OUT" 'timezone                 worker "Africa/Kampala" · row absent' "a zone name printed as a token"
has "$OUT" "the 2 latest invoices (newest first honoured: yes):" "the invoices read"
hasre "$OUT" "number shape <6d> · invoiceNumber shape <6d> · equal yes · proforma key no · created 2026-11-24T10:00:00" "an invoice number as its shape"
has "$OUT" 'the quoting organization: yes · selected yes · phone set yes · numbering: invoicePrefix "", invoiceNumberLength 6, invoiceInitNumber 1 digits, proformaInvoicePrefix shape PRO' "the numbering keys"
has "$OUT" "the old path answers (row: !empty(wa_bot_enabled) || !empty(wa_auto_reply_enabled)): no · the sync job runs (row: (bool)(wa_sync_enabled ?? true)): yes" "the old path's switches"
has "$OUT" "wa_webhook_secret set: worker yes · row no" "the webhook secret, set or not"
has "$OUT" "last 30 days: 1 (message_received 1)" "the webhook log, counted"
has "$OUT" "inbound stored by the old path (wa_messages, source webhook/cron), last 30 days: webhook 1" "inbound by source"
has "$OUT" "the old path's replies (wa_messages), last 30 days: Auto-Reply (support) 1" "the old path's replies"
has "$OUT" "conversations the old path answered (bot_replied_at), last 30 days: 1" "bot_replied_at"
has "$OUT" "support tickets from the old bot, last 30 days: 1" "the tickets"
has "$OUT" "the old path's message-log events, last 30 days: no notification_audit_log table" "a table never created"
has "$OUT" 'sync starts 1, "sync disabled" 1, "Fetched N new messages" 1, ERROR pkId (WaInbound load order) 1' "plugin.log counted, unstamped lines carried"
has "$OUT" "last batch 3, stored 2, errors 1, total 40" "the sync state"
has "$OUT" "uCRM gave 3 service plan(s) and 8 product(s); the assistant keeps 2 active plan(s), 5 one-time item(s), 2 accessory(ies), and drops 1 plan mirror(s)" "the catalogue's counts"
has "$OUT" '   1. "Residential (up to 350 Mbps)" — UGX 350,000 every 1 month(s); 1-month period UGX 350,000; id 1' "a plan, its price and order"
has "$OUT" '"Router Mini <9+ digits withheld>"' "a digit run in a product name withheld"
has "$OUT" '  the Standard kit (its match list): 2 in the one-time items' "two Standard kit rows found"
has "$OUT" '  the Mini kit (its match list): 0 in the one-time items' "the live Mini name not in the match list"
has "$OUT" 'one-time items naming "mini" or "standard": #1 "Starlink Standard Kit"; #2 "Starlink Mini Kit + Mini Router"' "the candidates"
has "$OUT" '  the Standard Pivot Mount: 1 in the accessories — #1 "Pivot Mount | Standard 4 and 4 X, Enterprise" UGX 150,000 (id 14)' "a mount in the accessories"
has "$OUT" '   4. "Router Mini <9+ digits withheld>" — UGX 300,000; id 18; taxable no, org the quoting one' "a one-time item, its price, id and organization"
has "$OUT" 'duplicates among the raw products (the shop'"'"'s exact-name key): "Starlink Standard Kit" = "Starlink Standard Kit "' "a duplicate named"
hasre "$OUT" "the installation link \(worker's organization\): ok · active records 1 · product id 13 · its organization matches yes · linked [0-9]{4}-" "the installation link judged"
has "$OUT" 'the installation at use (UcrmQuoteLines::installLine, with the plugin'"'"'s own key): ok · "Installation" UGX 200,000 · product id 13' "the installation at use"
has "$OUT" "the quoting organization: worker on, source setting, id present yes · row on, source setting, id present yes" "the quoting organization, both views"
has "$OUT" "the uCRM catalogue switch: no ucrm_catalogue table (never switched) → off" "the switch"
has "$OUT" "last 7 days: 5 typed events in 3 conversations; 1 had a newer typed one within 15 minutes, in 1 conversations" "the bursts"
has "$OUT" '  by number: "sales" 1/5' "the bursts by number"
has "$OUT" '  the older event'"'"'s status (with a newer one): "done" 1' "the bursts by status"
has "$OUT" 'transcripts, last 7 days, by model and rung: "whisper-1"/"dry_run" 1' "the transcripts"
has "$OUT" 'provider calls, last 7 days, by model, outcome and reason: "whisper-1"/"ok" 1' "the provider calls"
has "$OUT" '→ the model in use: whisper-1' "the model in use"
has "$OUT" "the book: changes made 0 (none)" "no change to the book"
hasre "$OUT" "uCRM asked \(GET only\): invoices ok, organizations ok, service-plans ok, products ok, products ok, service-plans ok; anything else refused: 0" "uCRM asked"
has "$OUT" "READ-ONLY — nothing was changed" "it says read-only"
has "$OUT" "as the book's owner:             /usr/local/sbin:/usr/local/bin" "the container's PATH"
privacy "$OUT" "S1"
check "$(snap)" "$B" "S1: every file of the data folder and the plugin unchanged, none added"
check "$(grep -cv '^GET /api/v2.1/\(service-plans\|products\|invoices\|organizations\)\([?][^ ]*\)\? key=' "$SB/ucrm.log")" 0 "S1: uCRM was asked GET only, on the four paths"
check "$(ls "$SB/ctmp" | grep -c dishnet_catalogue)" 0 "S1: no catalogue cache written"

# ── S2 the book idle ─────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- S2 the book idle"
unhold
check "$(ls "$D" | grep -c 'plugin.sqlite3-\(wal\|shm\)')" 0 "the book idle has no -wal/-shm"
run_wrap "$WRAP"
check "$RC" 0 "exit 0"
has "$OUT" "the book: NOT READ — its -wal/-shm files are absent" "it says the book was not opened"
check "$(ls "$D" | grep -c 'plugin.sqlite3-\(wal\|shm\)')" 0 "S2: no -wal/-shm left behind"
privacy "$OUT" "S2"
hold

# ── S3–S6 refusals ───────────────────────────────────────────────────────────────────────────────────────────────────
refused() {  # $1 = label, $2 = expected text
  check "$RC" 2 "$1: exit 2"; has "$OUT" "$2" "$1: says why"; has "$OUT" "READ-ONLY — nothing was changed." "$1: says nothing was changed"
  hasnt "$OUT" "reading as uid" "$1: the reader never ran"
}
echo "-- S3 not 5.18.106"
echo abc1234 > "$P/.deployed-commit"; run_wrap "$WRAP"; refused S3 "the installed plugin records commit 'abc1234', not 5.18.106 (bf29cbd)"
echo bf29cbd > "$P/.deployed-commit"
echo "-- S4 a changed installed file"
cp "$P/lib/KitTaxNote.php" "$SB/ktn"; echo "// x" >> "$P/lib/KitTaxNote.php"; run_wrap "$WRAP"; refused S4 "these installed files are not 5.18.106's bytes: lib/KitTaxNote.php"
cp "$SB/ktn" "$P/lib/KitTaxNote.php"
echo "-- S5 a reader that is not the reviewed one"
mkdir -p "$SB/s5/scripts"; cp "$WRAP" "$SB/s5/scripts/"; { cat "$READ"; echo "// x"; } > "$SB/s5/scripts/values-5.18.107.php"
run_wrap "$SB/s5/scripts/values-5.18.107.sh"; refused S5 "scripts/values-5.18.107.php is not the reviewed one (sha256)"
echo "-- S6 a root-owned book"
chown 0:0 "$D/plugin.sqlite3"; run_wrap "$WRAP"; refused S6 "the book is root's"
chown 1000:1000 "$D/plugin.sqlite3"

# ── S7 uCRM down ─────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- S7 uCRM does not answer"
kill "$UPID" 2>/dev/null; wait "$UPID" 2>/dev/null; UPID=""
B="$(snap)"; run_wrap "$WRAP"
check "$RC" 0 "exit 0"
has "$OUT" "latest invoices: not read (no connection)" "the invoices not read, said so"
has "$OUT" "the catalogue could NOT be read from uCRM (service-plans: no connection, products: no connection)" "the catalogue not read, said so"
hasnt "$OUT" "uCRM gave 0 service plan" "an unanswered uCRM is never read as an empty catalogue"
has "$OUT" "READ-ONLY — nothing was changed" "it says read-only"
check "$(snap)" "$B" "S7: nothing changed"
privacy "$OUT" "S7"
start_ucrm

# ── weakened copies ──────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- weakened copies of the reader (run directly), each must be caught"
weak_read() {  # $1 = id, $2 = python replacement list (old|||new), $3 = how it is caught (privacy|idle|cache)
  local id="$1" f="$SB/w-$1.php"
  python3 -I -c '
import sys
s = open(sys.argv[1]).read()
for pair in sys.argv[3].split("\n@@\n"):
    old, new = pair.split("|||")
    assert s.count(old) == 1, ("anchor", old[:50], s.count(old))
    s = s.replace(old, new)
open(sys.argv[2], "w").write(s)' "$READ" "$f" "$2" || { bad "$id: the copy could not be made"; return; }
  case "$3" in
    privacy) run_read "$f"; local l; l="$(leaks "$OUT")"; if [ "$l" != " none" ]; then ok "$id caught (leaks:$l)"; else bad "$id NOT caught"; fi ;;
    idle) unhold; run_read "$f"; if ls "$D" | grep -q 'plugin.sqlite3-\(wal\|shm\)'; then ok "$id caught (the idle book got -wal/-shm)"; else bad "$id NOT caught"; fi
          rm -f "$D/plugin.sqlite3-wal" "$D/plugin.sqlite3-shm"; hold ;;
    cache) rm -f "$SB/ctmp/"dishnet_catalogue_*; run_read "$f"; if ls "$SB/ctmp" | grep -q dishnet_catalogue; then ok "$id caught (a catalogue cache was written)"; else bad "$id NOT caught"; fi; rm -f "$SB/ctmp/"dishnet_catalogue_* ;;
  esac
}
weak_read R1 'return '"'"'<9+ digits withheld>'"'"';|||return $m_[0] ?? '"'"''"'"';
@@
function () use (&$n9) {|||function ($m_) use (&$n9) {' privacy
weak_read R2 "    return 'set, ' . strlen(\$t) . ' bytes / '|||    return \$t . ' set, ' . strlen(\$t) . ' bytes / '" privacy
weak_read R3 "elseif (!is_file(\$book . '-wal') || !is_file(\$book . '-shm')) \$bookWhy|||elseif (false) \$bookWhy" idle
weak_read R4 "\$ai['catalogue_cache_seconds'] = 0;|||\$ai['catalogue_cache_seconds'] = 60;" cache
weak_read R5 "\$say('wa_webhook_secret set: worker ' . \$yn(trim((string)(\$cfg['wa_webhook_secret'] ?? '')) !== '')|||\$say('wa_webhook_secret set: worker ' . (string)(\$cfg['wa_webhook_secret'] ?? '')" privacy

echo "-- weakened copies of the wrapper, each must be caught"
weak_wrap() {  # $1 = id, $2 = old, $3 = new, $4 = scenario to run (S1log|S3|S4|S5|S6)
  local id="$1" d="$SB/x-$1/scripts"; mkdir -p "$d"; cp "$READ" "$d/"
  python3 -I -c '
import sys
s = open(sys.argv[1]).read(); old, new = sys.argv[3], sys.argv[4]
assert s.count(old) == 1, ("anchor", old[:50], s.count(old))
open(sys.argv[2], "w").write(s.replace(old, new))' "$WRAP" "$d/values-5.18.107.sh" "$2" "$3" || { bad "$id: the copy could not be made"; return; }
  case "$4" in
    S1log) run_wrap "$d/values-5.18.107.sh"; [ "$(stat -c %a "$LOGF" 2>/dev/null)" = 600 ] && bad "$id NOT caught" || ok "$id caught (the log is not mode 600)" ;;
    S3) echo abc1234 > "$P/.deployed-commit"; run_wrap "$d/values-5.18.107.sh"; [ "$RC" = 2 ] && bad "$id NOT caught" || ok "$id caught (S3 ran the reader)"; echo bf29cbd > "$P/.deployed-commit" ;;
    S4) echo "// x" >> "$P/lib/KitTaxNote.php"; run_wrap "$d/values-5.18.107.sh"; [ "$RC" = 2 ] && bad "$id NOT caught" || ok "$id caught (S4 ran the reader)"; cp "$SB/ktn" "$P/lib/KitTaxNote.php" ;;
    S5) echo "// x" >> "$d/values-5.18.107.php"; run_wrap "$d/values-5.18.107.sh"; [ "$RC" = 2 ] && bad "$id NOT caught" || ok "$id caught (S5 ran the reader)" ;;
    S6) chown 0:0 "$D/plugin.sqlite3"; run_wrap "$d/values-5.18.107.sh"; grep -qF "the book is root's" <<< "$OUT" && bad "$id NOT caught" || ok "$id caught (S6 not refused as root's)"; chown 1000:1000 "$D/plugin.sqlite3" ;;
  esac
}
weak_wrap X1 'chmod 600 "$LOG"' 'chmod 644 "$LOG"' S1log
weak_wrap X2 '[ "${#REC}" -ge 7 ] && [ "${LIVE_COMMIT_FULL#"$REC"}" != "$LIVE_COMMIT_FULL" ] || stop' 'true || stop' S3
weak_wrap X3 '[ -z "$BAD" ] || stop "these installed files' 'true || stop "these installed files' S4
weak_wrap X4 '[ "$(sha256sum "$READER" 2>/dev/null | cut -c1-64)" = "$READER_SHA256" ] || stop' 'true || stop' S5
weak_wrap X5 '0:*) stop "the book is root'"'"'s' '0:*) : "the book is root'"'"'s' S6

echo; echo "passed $PASS, failed $FAIL"
[ "$FAIL" = 0 ] && echo "ALL PASSED" || echo "FAILURES — see above"
[ "$FAIL" = 0 ]
