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
#   S3 the installed plugin is not 5.18.106          S4 an installed file the reader names is not 5.18.106's bytes
#   S4b a lib file the reader does not name differs   S4c a lib file the book's owner cannot read (not compared)
#   S4d the container cannot hash (containerd's NotFound): nothing compared, said so
#   S4e a 5.18.106 lib file missing from the install: said so, not called unreadable
#   S5 the reader is not the reviewed one            S6 a root-owned book
#   S7 uCRM does not answer: the run ends, says what it could not read, changes nothing
#   S8 the worker's quoting organization off, the row's on: the switch judged under the staff screens' gate
#   S9 flyer_available in a settings file with no flyer: the assistant is offered <<FLYER>>, said so
#   S10 a plugin process closes the book between the reader's check and its first read: said so, exit 3, through the
#       reader and through the wrapper (never "nothing was changed"); and its control, the same with the detection
#       removed, shows the race does re-create the files (the holder gone, the -wal there) and nothing says so
#   S11 alert_whatsapp absent from the files, set in the row: the watchdog's number is the row's
#   S12 the race, then the reading stops: exit 1, both causes on the last line, never "nothing was changed"
#   S13 the reading stops, through the wrapper   S14 docker fails the reader's exec: the wrapper does not claim it ran
#   S4f a folder the book's owner cannot enter: its files "could not be read", never "not installed"
# then weakened copies — each must be caught: of the reader (R1-R14, run directly) and of the wrapper (X1-X11).
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
cleanup() { [ -n "$HPID" ] && touch "$SB/ctmp/stop" 2>/dev/null; [ -n "$UPID" ] && kill "$UPID" 2>/dev/null; wait 2>/dev/null; rm -rf "$SB"; }
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
hold() { rm -f "$SB/ctmp/stop"; setpriv --reuid=1000 --regid=1000 --clear-groups php -r '$p = new PDO("sqlite:" . $argv[1]); $p->query("SELECT COUNT(*) FROM events")->fetchColumn(); $n = 0; while (!is_file($argv[2]) && $n++ < 9000) usleep(100000); $p = null;' "$D/plugin.sqlite3" "$SB/ctmp/stop" & HPID=$!
         for _ in $(seq 50); do [ -f "$D/plugin.sqlite3-shm" ] && break; sleep 0.1; done; }
unhold() { [ -n "$HPID" ] && { touch "$SB/ctmp/stop"; wait "$HPID" 2>/dev/null; }; HPID=""; }
hold

cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
# S4d: the container goes away as the files are hashed — containerd prints this on stdout (deploy-hybrid.sh:41-50)
if [ -f "$SB/fail-hash" ] && grep -q 'dnb-values-107' <<< "\$*"; then echo "NotFound: task 1a2b3c not found"; exit 1; fi
# S14: the reader's exec meets a container that is restarting
if [ -f "$SB/fail-reader" ] && grep -q 'opcache.enable_cli=0' <<< "\$*"; then echo "Error response from daemon: Container 1a2b3c is restarting, wait until the container is running"; exit 1; fi
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
PINNED="$(grep -E '^[0-9a-f]{64}  [^ ]+$' "$WRAP")"
WANT="$(git -C "$SRCREPO" ls-tree -r --name-only "$COMMIT" "$PLUGIN/lib" "$PLUGIN/profiles" "$PLUGIN/assets/shop/catalogue.json" | sed "s#^$PLUGIN/##" \
        | while read -r f; do printf '%s  %s\n' "$(git -C "$SRCREPO" show "$COMMIT:$PLUGIN/$f" | sha256sum | cut -c1-64)" "$f"; done)"
check "$PINNED" "$WANT" "the wrapper pins every file of lib/, profiles/ and the shop catalogue at bf29cbd, each with bf29cbd's sum"
check "$(grep -c . <<< "$PINNED")" 299 "299 files pinned"
check "$(sed -n 's/^CONTROL_SHA256="\([0-9a-f]\{64\}\)".*$/\1/p' "$WRAP")" "$(printf 'dnb-values-107' | sha256sum | cut -c1-64)" "the hash control's sum is right"
B="$(snap)"; : > "$SB/ucrm.log"
run_wrap "$WRAP"
[ -n "${V107_KEEP:-}" ] && printf '%s\n' "$OUT" > "$V107_KEEP"
check "$RC" 0 "exit 0"
check "$LOGT" "$OUT" "the log holds exactly what the terminal showed"
check "$(stat -c %a "$LOGF")" 600 "the log is mode 600"
has "$OUT" "Send back the log:  cat $LOGF" "it ends with the cat line naming its log"
has "$OUT" "installed       5.18.106 (commit bf29cbd); all 299 files of lib/, profiles/ and the shop catalogue are 5.18.106's bytes; the book owned by 1000:1000" "the installed code checked"
has "$OUT" "the book: opened read-only (query_only, one read transaction)" "the book opened read-only"
has "$OUT" 'ai_enabled                   worker true · row absent → the worker reads it as ON (the website chat does not read it)' "ai_enabled: the WhatsApp worker's only"
has "$OUT" 'web_chat_enabled             worker absent · row "1" → the website chat answers: yes' "web_chat_enabled, filled from the row"
has "$OUT" 'ai_hardware_expert           worker "1" · row "1" → the worker reads it as ON; the website chat as ON' "ai_hardware_expert, both sources, both readers"
has "$OUT" 'bot_instructions_mode        worker absent · row "override" → the worker reads it as append; the website chat as override' "the mode: the website chat fills it from the row"
hasre "$OUT" 'bot_custom_instructions      worker set, 239 bytes / 239 chars, sha256 [0-9a-f]{12}, digit runs of 5\+: 0, phone-shaped: 0 · row set, 26 bytes / 26 chars, no hash \(under 200 bytes\), digit runs of 5\+: 0, phone-shaped: 0' "the settings text: a hash only when long"
has "$OUT" "override in effect (mode \"override\" AND custom text not empty; the knowledge base and business facts then leave the prompt): on WhatsApp no · on the website chat yes" "override, on each surface"
has "$OUT" 'flyer_available              worker absent · row absent' "flyer_available read"
has "$OUT" "flyer: file" "the flyer"; has "$OUT" "wa_flyer.jpg: 10 KB, usable" "the flyer file"; has "$OUT" "wa_flyer_url: set, scheme https" "the flyer URL as a scheme"
has "$OUT" 'ai_fact_kit_taxes      worker "omit"' "a fact set to omit"
has "$OUT" 'ai_fact_payment        worker set, 35 bytes / 35 chars, digit runs of 5+: 1, phone-shaped: 1 · row not set' "a fact with an account number: counted, never shown, no hash"
has "$OUT" 'quote_company_phone    worker set, 13 bytes, phone-shaped runs: 1 (with +, starting 256, 12 digits) · row not set' "the company phone: each number's form only"
check "$(grep -c 'sha256 [0-9a-f]' <<< "$OUT")" 2 "a hash prefix only on the reader's own line and the settings text"
hasre "$OUT" 'ai_fact_office         worker set, [0-9]+ bytes .*phone-shaped: 1' "a fact with a spaced phone number: counted"
has "$OUT" "ai_fact_prices         worker empty" "an empty fact"
has "$OUT" "the kit tax note the worker appends: none (hardware module off, or \"omit\")" "the kit tax note"
has "$OUT" "the business-plan note: the code's default sentence" "the business note's default"
has "$OUT" 'rows by kind and status: "fact"/"approved" 1, "fact"/"disabled" 1, "rule"/"approved" 1' "the knowledge base by kind and status"
hasre "$OUT" 'OFFICE_LOCATION: kind "fact", status "approved", approved \(rendered\): yes, updated [0-9]{4}-[0-9]{2}-[0-9]{2}T' "OFFICE_LOCATION's status"
has "$OUT" 'wa_answer: set, 33 bytes / 33 chars, digit runs of 5+: 0, phone-shaped: 1, over the 300-char chat cut: no' "OFFICE_LOCATION's chat answer"
has "$OUT" "rows spelled like OFFICE_LOCATION but not exactly it: 1" "a near-duplicate key counted"
has "$OUT" "plugin data/config.json:     tenant_profile null · currency_code \"\" · cashbook_base_currency selects south-sudan" "the plugin's own file, by class"
has "$OUT" "without the vault: south-sudan (decided by currency_code)" "without the vault"
has "$OUT" "with the vault, as TenantProfile::current() reads it: uganda (decided by currency_code)" "with the vault"
has "$OUT" "the gate the workers ask (StaffJobsGate::applies on the worker view): yes" "the gate"
has "$OUT" "the vault: the data folder's config_vault.json (the plugins folder is not writable by this user); present yes, readable yes, valid yes; tenant_profile absent · currency_code selects uganda · cashbook_base_currency absent" "the vault, as classes"
has "$OUT" "profiles/uganda.json: present yes, readable yes, valid yes" "the profile readable"
has "$OUT" "alert_whatsapp               worker with +, starting 256, 12 digits · row no +, starting 0 (local), 10 digits · watchdog with +, starting 256, 12 digits" "the alert number as a form, each reader"
has "$OUT" 'alert_patience_minutes       worker "" · row "15" → the watchdog waits 15 minutes' "a blank patience setting: the watchdog fills it from the row"
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
has "$OUT" "the uCRM catalogue switch: mode records in force 1 · the record in force says on yes · direct mode on for the staff screens (row gate) yes · under the worker gate yes" "the switch, under each gate"
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
echo "-- S4b a lib file the reader does not name"
cp "$P/lib/PublicPriceFeed.php" "$SB/ppf"; echo "// x" >> "$P/lib/PublicPriceFeed.php"; run_wrap "$WRAP"; refused S4b "these installed files are not 5.18.106's bytes: lib/PublicPriceFeed.php"
cp "$SB/ppf" "$P/lib/PublicPriceFeed.php"
echo "-- S4c a lib file the book's owner cannot read"
chmod 600 "$P/lib/PublicPriceFeed.php"; run_wrap "$WRAP"; refused S4c "these installed files could not be read by the book's owner, so they were not compared: lib/PublicPriceFeed.php"
hasnt "$OUT" "are not 5.18.106's bytes" "S4c: an unreadable file is never called changed"
chmod 664 "$P/lib/PublicPriceFeed.php"
echo "-- S4d the container cannot hash"
touch "$SB/fail-hash"; run_wrap "$WRAP"; refused S4d "the installed files could not be checked: php in the 'ucrm' container did not hash a known string"
hasnt "$OUT" "are not 5.18.106's bytes" "S4d: nothing is called changed"
rm -f "$SB/fail-hash"
echo "-- S4e a 5.18.106 lib file missing from the install"
mv "$P/lib/PublicPriceFeed.php" "$SB/ppf.moved"; run_wrap "$WRAP"; refused S4e "these 5.18.106 files are not installed: lib/PublicPriceFeed.php"
hasnt "$OUT" "could not be read by the book's owner" "S4e: a missing file is never called unreadable"
mv "$SB/ppf.moved" "$P/lib/PublicPriceFeed.php"
echo "-- S4f a folder the book's owner cannot enter"
PM="$(stat -c %a "$P/profiles")"; chmod 700 "$P/profiles"; run_wrap "$WRAP"
refused S4f "these installed files could not be read by the book's owner, so they were not compared: profiles/south-sudan.json profiles/uganda.json"
hasnt "$OUT" "not installed" "S4f: a file in a closed folder is never called not installed"
chmod "$PM" "$P/profiles"
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

# ── S8 the two gates differ ──────────────────────────────────────────────────────────────────────────────────────────
jset() {  # $1 = file, $2 = key, $3 = JSON value or DEL — keeps the file's owner
  php -r '$f = $argv[1]; $a = json_decode((string)file_get_contents($f), true) ?: []; if ($argv[3] === "DEL") unset($a[$argv[2]]); else $a[$argv[2]] = json_decode($argv[3], true);
          file_put_contents($f, json_encode($a, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));' "$1" "$2" "$3"; }
s8_on()  { cp -p "$D/config.json" "$SB/cfg.bak"; jset "$D/config.json" ucrm_quote_organization_id '"0"'; }
s8_off() { cp -p "$SB/cfg.bak" "$D/config.json"; }
echo "-- S8 the worker's quoting organization off, the row's on"
s8_on; run_wrap "$WRAP"; check "$RC" 0 "exit 0"
has "$OUT" "the quoting organization: worker off, source explicit-off, id present no · row on, source setting, id present yes" "the two gates"
has "$OUT" "the record in force says on yes · direct mode on for the staff screens (row gate) yes · under the worker gate no" "the switch judged under the staff screens' gate"
s8_off

# ── S9 a stray flyer_available ───────────────────────────────────────────────────────────────────────────────────────
s9_on()  { cp -p "$D/config.json" "$SB/cfg.bak"; cp -p "$D/kyc_config.json" "$SB/kyc.bak"; mv "$D/wa_flyer.jpg" "$SB/flyer.bak"
           jset "$D/config.json" wa_flyer_url DEL; jset "$D/kyc_config.json" flyer_available '"1"'; }
s9_off() { cp -p "$SB/cfg.bak" "$D/config.json"; cp -p "$SB/kyc.bak" "$D/kyc_config.json"; mv "$SB/flyer.bak" "$D/wa_flyer.jpg"; }
echo "-- S9 flyer_available in a settings file, no flyer"
s9_on; run_wrap "$WRAP"; check "$RC" 0 "exit 0"
has "$OUT" 'flyer_available              worker "1" · row absent' "the key read"
has "$OUT" "flyer: none, BUT flyer_available is set in the settings: the assistant IS offered <<FLYER>> and nothing sends it" "the stray key said so"
s9_off

# ── S10 the race at open ─────────────────────────────────────────────────────────────────────────────────────────────
echo "-- S10 a plugin process closes the book between the check and the first read"
# stage(): a copy of a reader that, before opening the book, tells the holder to close it and waits until the -wal is
# gone (so the order is certain, not timed), and records whether it was; optionally without the detection, or throwing
# right after the first read. raced(): the -wal was gone before the open, the holder has gone, and a -wal is back.
stage() {  # $1 = source reader, $2 = out, $3 = 1 to stage the race, $4 = 1 to remove the detection, $5 = 1 to throw
  rm -f "$SB/ctmp/raced"
  python3 -I -c '
import sys
src, out, stop, mark, race, nodetect, throw = sys.argv[1:8]
s = open(src).read()
a = "        $db = new PDO(\x27sqlite:\x27 . $book,"
assert s.count(a) == 1
if race == "1":
    inj = ("        touch(%r); for ($i = 0; $i < 200 && (clearstatcache() || true) && is_file($book . \x27-wal\x27); $i++) usleep(50000);\n"
           "        clearstatcache(); file_put_contents(%r, is_file($book . \x27-wal\x27) ? \x27present\x27 : \x27absent\x27);\n") % (stop, mark)
    s = s.replace(a, inj + a)
if nodetect == "1":
    d = "$recreated = is_array($st) && (int)$st[\x27nlink\x27] === 0;"
    assert s.count(d) == 1; s = s.replace(d, "$recreated = false;")
if throw == "1":
    t = "    // ── 2. Operator facts"
    assert s.count(t) == 1; s = s.replace(t, "    throw new RuntimeException(\x27injected\x27);\n" + t)
open(out, "w").write(s)' "$1" "$2" "$SB/ctmp/stop" "$SB/ctmp/raced" "${3:-0}" "${4:-0}" "${5:-0}"; }
repin() { sed "s/^READER_SHA256=\"[0-9a-f]\{64\}\"$/READER_SHA256=\"$(sha256sum "$2" | cut -c1-64)\"/" "$1" > "$3"; }   # wrapper, reader, out
after_race() { wait "$HPID" 2>/dev/null; HPID=""; hold; unhold; hold; }
raced() { [ "$(cat "$SB/ctmp/raced" 2>/dev/null)" = absent ] && ! kill -0 "$HPID" 2>/dev/null && [ -f "$D/plugin.sqlite3-wal" ]; }
stage "$READ" "$SB/race.php" 1; run_read "$SB/race.php"
if raced; then ok "S10: the race happened (the -wal gone before the open, back after)"; else bad "S10: the race was not staged"; fi
check "$RC" 3 "S10: exit 3"
has "$OUT" "NOTE: a plugin process closed the book at the moment it was opened, so this read re-created SQLite's own -wal and -shm files" "S10: the re-created files said so"
has "$OUT" "READ-ONLY — SQLite's own -wal/-shm were re-created as the book was opened (see the NOTE)" "S10: the last line names the cause"
check "$(grep -c 'nothing was changed' <<< "$OUT")" 0 "S10: never 'nothing was changed'"
after_race
echo "-- S10 through the wrapper"
mkdir -p "$SB/s10/scripts"; stage "$READ" "$SB/s10/scripts/values-5.18.107.php" 1
repin "$WRAP" "$SB/s10/scripts/values-5.18.107.php" "$SB/s10/scripts/values-5.18.107.sh"
run_wrap "$SB/s10/scripts/values-5.18.107.sh"
if raced; then ok "S10w: the race happened"; else bad "S10w: the race was not staged"; fi
check "$RC" 3 "S10w: exit 3"
check "$(grep -c 'nothing was changed' <<< "$OUT")" 0 "S10w: neither the reader nor the wrapper says 'nothing was changed'"
has "$OUT" "  the reader's last line above says what happened; send the log as it is" "S10w: the wrapper points to the reader's last line"
after_race
stage "$READ" "$SB/race0.php" 1 1; run_read "$SB/race0.php"
if raced && ! grep -qF "NOTE: a plugin process" <<< "$OUT"; then ok "S10 control: without the detection the race re-creates the files and nothing says so"; else bad "S10 control: the race did not happen as staged, or was reported"; fi
after_race

# ── S12 the race, then a stop ────────────────────────────────────────────────────────────────────────────────────────
echo "-- S12 the race, then the reading stops"
stage "$READ" "$SB/race12.php" 1 0 1; run_read "$SB/race12.php"
if raced; then ok "S12: the race happened"; else bad "S12: the race was not staged"; fi
check "$RC" 1 "S12: exit 1 (stopped comes first)"
has "$OUT" "STOPPED at " "S12: the stop said so"
has "$OUT" "READ-ONLY — the reading stopped part-way (send the log as it is); SQLite's own -wal/-shm were re-created as the book was opened (see the NOTE)" "S12: the last line names both causes"
check "$(grep -c 'nothing was changed' <<< "$OUT")" 0 "S12: never 'nothing was changed'"
after_race

# ── S13 a stop, through the wrapper ──────────────────────────────────────────────────────────────────────────────────
echo "-- S13 the reading stops, through the wrapper"
mkdir -p "$SB/s13/scripts"; stage "$READ" "$SB/s13/scripts/values-5.18.107.php" 0 0 1
repin "$WRAP" "$SB/s13/scripts/values-5.18.107.php" "$SB/s13/scripts/values-5.18.107.sh"
run_wrap "$SB/s13/scripts/values-5.18.107.sh"
check "$RC" 1 "S13: exit 1"
has "$OUT" "READ-ONLY — the reading stopped part-way (send the log as it is); nothing was changed" "S13: the reader's last line"
has "$OUT" "  the reader's last line above says what happened; send the log as it is" "S13: the wrapper points to it"

# ── S14 docker fails the reader's exec ───────────────────────────────────────────────────────────────────────────────
echo "-- S14 the container restarts as the reader starts"
touch "$SB/fail-reader"; run_wrap "$WRAP"; rm -f "$SB/fail-reader"
check "$RC" 1 "S14: exit 1 (docker's)"
has "$OUT" "the reader ended without its last line (exit 1): it may not have run at all" "S14: the wrapper does not claim the reader ran"
hasnt "$OUT" "the reader's last line above says what happened" "S14: no pointer to a reader line that does not exist"

# ── S11 the watchdog's alert number ──────────────────────────────────────────────────────────────────────────────────
s11_on()  { cp -p "$D/config.json" "$SB/cfg.bak"; jset "$D/config.json" alert_whatsapp DEL; jset "$D/config.json" quote_company_phone '"+256 000 111 222  0000 999 888"'; }
s11_off() { cp -p "$SB/cfg.bak" "$D/config.json"; }
echo "-- S11 alert_whatsapp only in the row"
s11_on; run_wrap "$WRAP"; check "$RC" 0 "exit 0"
has "$OUT" "alert_whatsapp               worker not set (alerts off) · row no +, starting 0 (local), 10 digits · watchdog no +, starting 0 (local), 10 digits" "the watchdog pages the row's number"
has "$OUT" "quote_company_phone    worker set, 30 bytes, phone-shaped runs: 2 (with +, starting 256, 12 digits; no +, starting 0 (local), 10 digits) · row not set" "two company numbers, each on its own"
privacy "$OUT" "S11"
s11_off

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
    absent:*) run_read "$f"; if grep -qF -- "${3#absent:}" <<< "$OUT"; then bad "$id NOT caught"; else ok "$id caught (no: ${3#absent:})"; fi ;;
    s12rc) stage "$f" "$f.s12" 1 0 1; run_read "$f.s12"; local st=no; raced && st=yes; after_race
           if [ "$st" != yes ]; then bad "$id not staged"; elif [ "$RC" != 1 ]; then ok "$id caught (S12 exit $RC, not 1)"; else bad "$id NOT caught"; fi ;;
    s12line) stage "$f" "$f.s12" 1 0 1; run_read "$f.s12"; local st=no; raced && st=yes; after_race
           if [ "$st" != yes ]; then bad "$id not staged"; elif grep -qF "the reading stopped part-way" <<< "$OUT"; then bad "$id NOT caught"; else ok "$id caught (S12's last line lacks the stop)"; fi ;;
    s11absent:*) s11_on; run_read "$f"; s11_off; if grep -qF -- "${3#s11absent:}" <<< "$OUT"; then bad "$id NOT caught"; else ok "$id caught under S11 (no: ${3#s11absent:})"; fi ;;
    s8absent:*) s8_on; run_read "$f"; s8_off; if grep -qF -- "${3#s8absent:}" <<< "$OUT"; then bad "$id NOT caught"; else ok "$id caught under S8 (no: ${3#s8absent:})"; fi ;;
    present:*) run_read "$f"; if grep -qE -- "${3#present:}" <<< "$OUT"; then ok "$id caught (found: ${3#present:})"; else bad "$id NOT caught"; fi ;;
  esac
}
weak_read R1 'return '"'"'<9+ digits withheld>'"'"';|||return $m_[0] ?? '"'"''"'"';
@@
function () use (&$n9) {|||function ($m_) use (&$n9) {' privacy
weak_read R2 "    return 'set, ' . strlen(\$t) . ' bytes / '|||    return \$t . ' set, ' . strlen(\$t) . ' bytes / '" privacy
weak_read R3 "elseif (!is_file(\$book . '-wal') || !is_file(\$book . '-shm')) \$bookWhy|||elseif (false) \$bookWhy
@@
    elseif ((\$walH = @fopen(\$book . '-wal', 'rb')) === false) \$bookWhy|||    elseif (false) \$bookWhy" idle
weak_read R4 "\$ai['catalogue_cache_seconds'] = 0;|||\$ai['catalogue_cache_seconds'] = 60;" cache
weak_read R5 "\$say('wa_webhook_secret set: worker ' . \$yn(trim((string)(\$cfg['wa_webhook_secret'] ?? '')) !== '')|||\$say('wa_webhook_secret set: worker ' . (string)(\$cfg['wa_webhook_secret'] ?? '')" privacy
weak_read R6 "        if (!array_key_exists(\$k, \$filled) || \$filled[\$k] === '' || \$filled[\$k] === null) \$filled[\$k] = \$v;|||        // gap-fill removed" "absent:on the website chat yes"
weak_read R7 "(\$withSha ? (strlen(\$t) >= 200 ? ', sha256 ' . \$sha(\$t) : ', no hash (under 200 bytes)') : '')|||', sha256 ' . \$sha(\$t)" "present:ai_fact_payment +worker set, [0-9]+ bytes / [0-9]+ chars, sha256"
weak_read R8 "' · direct mode on for the staff screens (row gate) ' . \$yn(UcrmCatalogue::fromRecords(\$cr, UcrmCatalogue::gateOpen(\$qoRow))->direct())|||' · direct mode on for the staff screens (row gate) ' . \$yn(UcrmCatalogue::fromRecords(\$cr, UcrmCatalogue::gateOpen(\$qo))->direct())" "s8absent:direct mode on for the staff screens (row gate) yes"
weak_read R10 "\$form(\$filled['alert_whatsapp'] ?? null|||\$form(\$cfg['alert_whatsapp'] ?? null" "s11absent:watchdog no +, starting 0 (local), 10 digits"
weak_read R11 "(strlen(\$t) >= 200 ? ', sha256 ' . \$sha(\$t) : ', no hash (under 200 bytes)')|||', sha256 ' . \$sha(\$t)" "present:row set, 26 bytes / 26 chars, sha256"
weak_read R12 "\$rc = \$stopped ? 1 : (\$changed ? 4 : (\$recreated ? 3 : 0));|||\$rc = \$recreated ? 3 : (\$stopped ? 1 : (\$changed ? 4 : 0));" s12rc
weak_read R13 "if (\$stopped) \$causes[] = 'the reading stopped part-way (send the log as it is)';|||" s12line
weak_read R14 "preg_split('#\\s*(?:[/,;|\\n]|\\s{2,}|\\bor\\b)\\s*#u', \$t)|||[\$t]" "s11absent:phone-shaped runs: 2 (with +, starting 256, 12 digits; no +"
weak_read R9 "\$say(sprintf('%-28s %s', 'flyer_available', \$both('flyer_available')));|||" "absent:flyer_available              worker"

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
    S4c) chmod 600 "$P/lib/PublicPriceFeed.php"; run_wrap "$d/values-5.18.107.sh"; chmod 664 "$P/lib/PublicPriceFeed.php"
         grep -qF "could not be read by the book's owner, so they were not compared: lib/PublicPriceFeed.php" <<< "$OUT" && bad "$id NOT caught" || ok "$id caught (S4c: the unreadable file not reported as such)" ;;
    S4e) mv "$P/lib/PublicPriceFeed.php" "$SB/ppf.moved"; run_wrap "$d/values-5.18.107.sh"; mv "$SB/ppf.moved" "$P/lib/PublicPriceFeed.php"
         grep -qF "these 5.18.106 files are not installed: lib/PublicPriceFeed.php" <<< "$OUT" && bad "$id NOT caught" || ok "$id caught (S4e: the missing file not reported as such)" ;;
    S10w) stage "$READ" "$d/values-5.18.107.php" 1; repin "$d/values-5.18.107.sh" "$d/values-5.18.107.php" "$d/w.sh"; mv "$d/w.sh" "$d/values-5.18.107.sh"
          run_wrap "$d/values-5.18.107.sh"; local st=no; raced && [ "$RC" = 3 ] && st=yes; after_race
          if [ "$st" != yes ]; then bad "$id not staged (raced/exit 3)"; elif [ "$(grep -c 'nothing was changed' <<< "$OUT")" = 0 ]; then bad "$id NOT caught"; else ok "$id caught (S10w: the wrapper said 'nothing was changed')"; fi ;;
    S14) touch "$SB/fail-reader"; run_wrap "$d/values-5.18.107.sh"; rm -f "$SB/fail-reader"
         grep -qF "the reader ended without its last line (exit 1)" <<< "$OUT" && bad "$id NOT caught" || ok "$id caught (S14: claimed the reader ran)" ;;
    S4f) local pm; pm="$(stat -c %a "$P/profiles")"; chmod 700 "$P/profiles"; run_wrap "$d/values-5.18.107.sh"; chmod "$pm" "$P/profiles"
         grep -qF "could not be read by the book's owner, so they were not compared: profiles/south-sudan.json" <<< "$OUT" && bad "$id NOT caught" || ok "$id caught (S4f: a closed folder called not installed)" ;;
    S4d) touch "$SB/fail-hash"; run_wrap "$d/values-5.18.107.sh"; rm -f "$SB/fail-hash"
         grep -qF "did not hash a known string" <<< "$OUT" && bad "$id NOT caught" || ok "$id caught (S4d: the failed hashing not reported as such)" ;;
  esac
}
weak_wrap X1 'chmod 600 "$LOG"' 'chmod 644 "$LOG"' S1log
weak_wrap X2 '[ "${#REC}" -ge 7 ] && [ "${LIVE_COMMIT_FULL#"$REC"}" != "$LIVE_COMMIT_FULL" ] || stop' 'true || stop' S3
weak_wrap X3 '[ -z "$BAD" ] || stop "these installed files' 'true || stop "these installed files' S4
weak_wrap X4 '[ "$(sha256sum "$READER" 2>/dev/null | cut -c1-64)" = "$READER_SHA256" ] || stop' 'true || stop' S5
weak_wrap X5 '0:*) stop "the book is root'"'"'s' '0:*) : "the book is root'"'"'s' S6
weak_wrap X6 '[ "$(sed -n '"'"'s/^CONTROL \([0-9a-f]\{64\}\)$/\1/p'"'"' <<< "$GOT")" = "$CONTROL_SHA256" ] && grep -qx "END $N" <<< "$GOT"' 'true' S4d
weak_wrap X8 '? "MISSING" : "UNREAD"' '? "UNREAD" : "UNREAD"' S4e
weak_wrap X9 '  yes:1|yes:3|yes:4) echo "  the reader'"'"'s last line above says what happened; send the log as it is" ;;' '  yes:1|yes:3|yes:4) echo "  READ-ONLY — nothing was changed" ;;' S10w
weak_wrap X10 'FINISHED=no; grep -q '"'"'^READ-ONLY — '"'"' "$ROUT" && FINISHED=yes' 'FINISHED=yes' S14
weak_wrap X11 'echo (is_readable($d) && is_executable($d)) ? "MISSING" : "UNREAD", "  ", $f, "\n"; continue; }' 'echo "MISSING  ", $f, "\n"; continue; }' S4f
weak_wrap X7 'if (g !~ /^[0-9a-f]+$/ || length(g) != 64) print "UNREAD " f; else if' 'if' S4c

echo; echo "passed $PASS, failed $FAIL"
[ "$FAIL" = 0 ] && echo "ALL PASSED" || echo "FAILURES — see above"
[ "$FAIL" = 0 ]
