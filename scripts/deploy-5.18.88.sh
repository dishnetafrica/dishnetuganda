#!/usr/bin/env bash
#
# deploy-5.18.88.sh — the rest of multi-number Batch 1, dark (plugin 5.18.88, docs/65) over 5.18.87, or roll it back to
#            5.18.87. The remaining Batch 1 release: production's 5.18.86 took only Batch 1's Inbox fix.
#
#   5.18.88  On Uganda the 30-second event processor stops taking the workers' events: it no longer claims ai.reply or
#            crm.lead.sync — until now a lead's uCRM sync could be acknowledged as an "unknown event type" before the uCRM
#            worker saw it (docs/65 §Z.5) — and it settles wa.escalation as a known type. Migration 087 adds the WhatsApp
#            channel registry: two tables, three indexes, three triggers, and the three department numbers' rows with NO
#            Evolution instance stored. The webhook, the AI worker, the follow-ups and set_config.php carry Batch 1's
#            routing by channel behind multi_number_channels_enabled, which ships OFF and is set nowhere: with it off every
#            number routes exactly as at 5.18.87. South Sudan keeps 5.18.87's event loop exactly — its list ['ai.reply'].
#            Record: docs/65, docs/07 07 Oct.
#
#   THE REGISTRY STAYS DARK. multi_number_channels_enabled must read OFF in both copies before anything changes (A5); this
#            script never sets it, creates or pairs no Evolution instance, and sends nothing. 087's rows name no instance:
#            each number's instance stays where the uCRM Configuration screen keeps it.
#
#   Live on this server, kept exactly as they are: Customer Installation Authorisation (5.18.83) and the booking WhatsApp
#            (5.18.84), each switch's two copies agreeing (V3b, V3c, R2); migration 086, complete before and after (A, R3);
#            the Inbox's own-number replies (5.18.86) and its settings row (A3, V3d, R9); the AI's leads (5.18.87), with the
#            four lead switches unchanged (A4, V3e, R10) — capture ON and the uCRM write OFF, as the operator decided on 07
#            Oct. The rollback puts 5.18.87 back; 087's tables stay, unread while the switch is off.
#
#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP — as 5.18.66 through 5.18.87 were. The pin below, on
#            release/5.18.88, is Batch 1's remainder on 9cc81af, the 5.18.87 release commit production runs: six files byte
#            for byte from Batch 1 (b1865ea), four merged onto live's without the AI media layer, three tests, the manifest
#            and five version pins. The branch tip also carries undeployed work: the distributor partner portal (migrations
#            081–083), the PD-8 CSRF guard and the AI media layer (migration 085, the media worker, voice, image and document
#            paths). Stage A refuses a pin whose parent is not 5.18.87, whose delta is not exactly this release's files, or
#            whose migration is not the reviewed 087.
#
#   Scope:   ONE additive migration, 087 (CREATE … IF NOT EXISTS throughout, INSERT OR IGNORE and NOT EXISTS seeds; no ALTER,
#            UPDATE, DELETE or DROP), applied by the plugin's own runner on its next request, as 086 was in 5.18.83. Five new
#            files (087, the read-only tools/channels.php, three tests) and thirteen changed. No configuration value set, no
#            switch changed, no message sent, no Evolution or uCRM call.
#
#   South Sudan and Domain B. Before anything changes the PIN's event processor is run once as a South Sudan install, beside
#            the live 5.18.87 one, each against a throwaway database in the container's /tmp: they must leave five events
#            exactly alike, or nothing is deployed (A6). The whole repository's delta must be the plugin's own files, the
#            Domain B tree and the plugin's docs the same trees as the live release's (A7). After the deploy R13 runs the
#            INSTALLED processor the same way, and R14 checks the installed Domain B files byte for byte.
#
#   Regression: because the whole plugin tree is copied, stage R doubles as a full check that Release A (5.18.49) through
#            5.18.87 is installed byte-for-byte as the pin has it (R5), every earlier surface is present (R6), and the pilot
#            still binds the Null channel with none of the undeployed work installed (R4). R7 runs 5.18.71's read-only
#            cash-in-hand tool; R8 the quotation reader; R9 the Inbox route; R10 the lead path; R11 the registry, dark; R12
#            the three numbers' routing against 5.18.87's code; R13 the event processor and the queue; R14 Domain B — reads,
#            or work in a throwaway directory: nothing read from uCRM, nothing sent, nothing written to the plugin's data.
#
# 5.18.87 must be the live plugin (it is, since 7 Oct 06:35 UTC): this script refuses any other live commit or version.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.88 \
#     && mkdir -p /root/dnb-5.18.88 \
#     && bash scripts/deploy-5.18.88.sh 2>&1 | tee /root/dnb-5.18.88/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again
#   --rollback           put 5.18.87 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: switch multi_number_channels_enabled, ai_lead_capture, ai_crm_lead_sync, ai_qualification,
# ai_sales_on_all_numbers, customer_wa_install_scheduled, install_auth_enabled or any other configuration value on or off;
# create, pair or call an Evolution instance, or add a channel; touch a customer, a staff account, a job, a quotation, a
# lead, a conversation, a message, an event, a photo, a cash record, the webhook key, Traefik, UISP, Evolution or the
# website; send anything; sign anyone in; run a cron or a worker on the plugin's data; call uCRM. The databases are read as
# their owner, READ-ONLY. The two releases' code is copied into the container's /tmp for the comparisons (A6, A9, R12, R13,
# RB), where the event processor runs only against throwaway databases with no address configured; all of it is removed
# when the run ends. The writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.88/ (the event
# queue's snapshot included), a record of where the deploy started, the time of the copy on the files this release
# changes — and, by the plugin itself on its next request, migration 087.
#
# Stages:  A before-evidence: the release delta (one migration, the reviewed 087) and Domain B (A7); the live commit and
#            version; both live switches, migration 086 complete; the registry's switch OFF (A5); 087 absent (A8); the
#            Inbox's settings row (A3); the lead switches (A4, evidence); the three numbers' routing and the AI settings,
#            kept to compare (A9: the pin's code must route them as the live code does); uCRM's job.add reaching the plugin;
#            the syntax check under the server's own PHP; South Sudan (A6); the backup and the event queue's snapshot →
#            GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.87); the copy's time on each file
#          V the public pages; the staff actions refusing an anonymous caller; the pilot switch, install_auth_enabled,
#            customer_wa_install_scheduled, the Inbox's settings, the lead switches and the registry's switch UNCHANGED by
#            the run (V3, V3b–V3f), the AI settings too (V3g); no fatal since the deploy started, read in the form the log
#            writes its times (V4)
#          R 5.18.88 installed: R1 the changed files byte-for-byte and the version; R2 the switches through the installed
#            code; R3 086 complete, 084 in place, 087 applied and complete — every object, its three rows with no instance,
#            its trail; R4 the pilot Null-bound and none of the undeployed work; R5 Release A→5.18.87 intact; R6 every
#            earlier marker and 5.18.88's pieces; R7 cash in hand, read-only, and the photos — growth is fine, a loss is not;
#            R8 the quotation reader; R9 the Inbox route; R10 the lead path; R11 the registry dark, never consulted; R12 the
#            three numbers route as on 5.18.87; R13 the event processor, Uganda and South Sudan, and the queue, nothing lost;
#            R14 Domain B byte for byte
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="6464204"   # 5.18.88 (release) — the rest of multi-number Batch 1, dark (b1865ea's, without the AI media layer); cut on 5.18.87; branch release/5.18.88
EXPECTED_VERSION="5.18.88"
BASELINE_COMMIT="9cc81af"          # 5.18.87 (release) — what the server runs first, and the rollback
BASELINE_VERSION="5.18.87"
RELEASE_A_BASE="e076632"           # 5.18.49 — the regression base: R5 checks every file from Release A through 5.18.87 is installed as pinned
RELEASE_BRANCH="release/5.18.88"   # where the release commit lives; fetched when the checkout does not hold it
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.88}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
TOOL="tools/set_distributors.php"   # reads the live pilot switch (read-only with --show); a regression check here
LAYOUT_MARK='flex-shrink:0;">📷 '    # the one-line button of 5.18.67's card (R6 regression)
BACKFILL_TOOL="tools/backfill_staff_cash_ins.php"   # 5.18.68 (R6 regression: installed)
RECORDS_TOOL="tools/staff_records_currency.php"      # 5.18.70 (R6 regression: installed, and still 5.18.70's)
CIH_TOOL="tools/cash_in_hand.php"                     # 5.18.71 (R6 regression: installed); run in R7 — READ-ONLY, nothing written
MIG="migrations/086_install_authorisation.sql"       # 5.18.83's migration — live, with the operator's records: a regression check here
IA_TABLES="install_auth install_auth_events install_auth_activations install_auth_exempt install_auth_rate"
IA_TRIGGERS="install_auth_accepted_is_final install_auth_never_deleted install_auth_status_moves install_auth_request_is_fixed install_auth_events_no_update install_auth_events_no_delete install_auth_activations_no_update install_auth_activations_no_delete install_auth_exempt_no_update install_auth_exempt_no_delete"
IA_INDEXES="idx_install_auth_client idx_install_auth_status idx_install_auth_events_job idx_install_auth_rate"
WA_LIB="lib/InstallScheduledWhatsApp.php"          # 5.18.84's class
WA_KEY="customer_wa_install_scheduled"             # 5.18.84's switch — LIVE: switched on by the operator on 06 Oct
QP_LIB="lib/QuotationPrefill.php"                  # 5.18.85's quotation reader — LIVE
IR_LIB="lib/InboxReplyRoute.php"                   # 5.18.86's Inbox route
MN_FLAG="multi_number_channels_enabled"           # the channel registry's switch — must read OFF in both copies (A5); this script never sets it
LC_KEY="ai_lead_capture"                          # the operator's decision, 07 Oct: ON (reported, A4; unchanged, V3e)
LS_KEY="ai_crm_lead_sync"                         # the operator's decision, 07 Oct: OFF, "uCRM later" (reported, A4; unchanged, V3e)
QU_KEY="ai_qualification"                         # the rules that ask the assistant for a LEAD line (reported, never changed)
SA_KEY="ai_sales_on_all_numbers"                  # the support/account number sells, and records leads, too (reported)
MIG87="migrations/087_wa_channels.sql"            # 5.18.88's one migration: the channel registry — additive, dark
MIG87_SHA256="3feda1b44ca79c1ad0057f029c032b741a581f4383e7378480129db1f1385aaa"   # the reviewed 087: Batch 1's (b1865ea), byte for byte
MIG87_STMTS=14                                    # its statements as the runner splits them: 2 tables, 3 indexes, 3 triggers, 3 rows, 3 trail rows
WA_TABLES="wa_channels wa_channel_log"
WA_INDEXES="idx_wa_channels_instance idx_wa_channels_number idx_wa_channel_log_channel"
WA_TRIGGERS="wa_channel_log_append_only_update wa_channel_log_append_only_delete wa_channels_never_deleted"
DOMAIN_B="dishnet-mikrotik-control-plane docs"    # Domain B and the plugin's documents, under the plugin directory: never touched (A7, R14)
# The event processor run once on a throwaway database with five events (A6, R13, RB): type=status/attempts, and +unknown
# when it was logged as an unknown type. 5.18.87's loop — everywhere until now, and South Sudan's still — and 5.18.88's on
# Uganda, which leaves ai.reply and crm.lead.sync untouched for their workers and settles wa.escalation as known. ai.media
# is no type of either: production has no AI media layer.
SS_SIGNATURE="crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
UG_SIGNATURE="crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
# The release's own files, and nothing else: 5 added, 13 changed. Anything outside this list in the pin is another build.
EXPECTED_ADDED="migrations/087_wa_channels.sql tests/test_channel_registry.php tests/test_event_processor_protected.php tests/test_multi_number_routing.php tools/channels.php"
EXPECTED_CHANGED="cron/event_processor.php cron/followup_send.php evo_webhook.php lib/AiLeadService.php lib/EventBus.php manifest.json tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tools/set_config.php workers/AiReplyWorker.php"

MODE="deploy"; PLUGIN_BASE="${PLUGIN_BASE:-}"
while [ $# -gt 0 ]; do
  case "$1" in
    --after-only) MODE="after" ;;
    --rollback) MODE="rollback" ;;
    --plugin-base) PLUGIN_BASE="${2:-}"; shift ;;
    *) echo "unknown option: $1" >&2; exit 64 ;;
  esac; shift
done

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }
case "$EXPECTED_PLUGIN_COMMIT" in __*) stop "this copy of the script is not pinned to a reviewed commit — pull the branch again";; esac

# ── HTTP helper ──────────────────────────────────────────────────────────────
HTTP_CODE=""; HTTP_BODY=""; HTTP_HEADERS=""; HTTP_REDIRECTS=""; HTTP_LOCATION=""
http() {   # http METHOD URL [curl args...]
  local m="$1" u="$2"; shift 2
  local args=(-sS -o "/tmp/dnb_body.$$" -D "/tmp/dnb_hdr.$$" -w '%{http_code} %{num_redirects} %{redirect_url}' --max-time 30 -X "$m" --max-redirs 5)
  local a; for a in "$@"; do args+=("$a"); done
  local r; r="$(curl "${args[@]}" "$u" 2>/dev/null)" || r="000 0 "
  HTTP_CODE="${r%% *}"; r="${r#* }"; HTTP_REDIRECTS="${r%% *}"; HTTP_LOCATION="${r#* }"
  HTTP_BODY="$(head -c 400000 "/tmp/dnb_body.$$" 2>/dev/null | tr -d '\000' || true)"
  HTTP_HEADERS="$(head -c 8000 "/tmp/dnb_hdr.$$" 2>/dev/null || true)"
  rm -f "/tmp/dnb_body.$$" "/tmp/dnb_hdr.$$"
}
count() { printf '%s' "$HTTP_BODY" | grep -o -F -- "$1" | wc -l | tr -d ' '; }
live_commit() { docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f'; }
# The container log since an ISO-8601 UTC time ($1), written to a file ($2) and kept from that time on — compared in the
# form the log writes its own times. Until 5.18.88 an --after-only run handed this the compact stamp of the deploy's state
# file (20261007T063502Z) and kept no line at all: every log time begins "2026-" and sorts before it, so V4 read nothing
# and passed. A compact stamp is turned into the ISO form first, and docker's own failure is returned, never hidden.
iso_time() {
  case "$1" in
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) printf '%s-%s-%sT%s:%s:%sZ' "${1:0:4}" "${1:4:2}" "${1:6:2}" "${1:9:2}" "${1:11:2}" "${1:13:2}" ;;
    *) printf '%s' "$1" ;;
  esac
}
log_since() {   # log_since TIME FILE — returns docker's exit status
  local t; t="$(iso_time "$1")"
  docker logs "$CONTAINER" --timestamps --since "$t" > "$2" 2>&1 || return $?
  awk -v t="${t:0:19}" 'substr($1, 1, 19) >= t' "$2" > "$2.in" && mv "$2.in" "$2"
}
in_list() { case " $2 " in *" $1 "*) return 0;; esac; return 1; }   # in_list WORD "LIST"

# ── 5.18.88: read-only helpers. Each runs under the server's own PHP, as the database's owner, and prints one line. ──
# Migration 087, from the installed plugin.sqlite3, READ-ONLY: the ledger row, every object 087 creates, its two signature
# CHECKs, the three department rows exactly as 087 seeds them (no instance, no number stored), any other channel, the trail.
#   mig=<absent|applied:<md5>> tables=<n>/2 indexes=<n>/3 triggers=<n>/3 checks=<n>/2 rows=<channels>:<trail>
#   seed=<ok|-|differs:…> other=<n> trail=<ok|-|differs> missing=<names|->        or nodb | ?
WA87_PHP="$(cat <<'PHP'
$db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
if (!is_file($db)) { echo "nodb"; exit; }
$tables = explode(" ", $argv[2]); $indexes = explode(" ", $argv[3]); $triggers = explode(" ", $argv[4]);
try {
    $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $have = [];
    foreach ($p->query("SELECT type, name, sql FROM sqlite_master")->fetchAll(PDO::FETCH_ASSOC) as $o) $have[$o["type"] . ":" . $o["name"]] = (string)$o["sql"];
    $mig = "absent";
    if (isset($have["table:_migrations"])) {
        $q = $p->prepare("SELECT checksum FROM _migrations WHERE filename = ?"); $q->execute(["087_wa_channels.sql"]);
        $c = $q->fetchColumn(); if ($c !== false) $mig = "applied:" . (string)$c;
    }
    $miss = []; $nt = 0; $ni = 0; $ng = 0; $nc = 0;
    foreach ($tables as $t)   { if (isset($have["table:$t"])) $nt++; else $miss[] = $t; }
    foreach ($indexes as $t)  { if (isset($have["index:$t"])) $ni++; else $miss[] = $t; }
    foreach ($triggers as $t) { if (isset($have["trigger:$t"])) $ng++; else $miss[] = $t; }
    $ch = $have["table:wa_channels"] ?? "";
    if ($ch !== "") {
        if (strpos($ch, "CHECK (role IN ('sales', 'support', 'account'))") !== false) $nc++; else $miss[] = "wa_channels:role-check";
        if (strpos($ch, "CHECK ((evo_instance IS NULL AND channel_id IN ('sales', 'support', 'account'))") !== false) $nc++; else $miss[] = "wa_channels:instance-check";
    }
    $rows = "-:-"; $seed = "-"; $other = 0; $trail = "-";
    if (isset($have["table:wa_channels"]) && isset($have["table:wa_channel_log"])) {
        $want = [
            ["account", null, null, "Accounts", "account", "department", null, null, null, "all", "1", "department", "active"],
            ["sales",   null, null, "Sales",    "sales",   "department", null, null, null, "all", "1", "department", "active"],
            ["support", null, null, "Support",  "support", "department", null, null, null, "all", "1", "department", "active"],
        ];
        $all = $p->query("SELECT channel_id, evo_instance, business_number, display_name, role, owner_type, owner_staff_id, owner_partner_id, territory_region_id, portfolio_scope, ai_enabled, handover_to, status FROM wa_channels ORDER BY channel_id")->fetchAll(PDO::FETCH_NUM);
        $dept = [];
        foreach ($all as $r) { if (in_array($r[0], ["account", "sales", "support"], true)) $dept[] = array_map(function ($v) { return $v === null ? null : (string)$v; }, $r); else $other++; }
        $bad = [];
        foreach ($want as $i => $w) if (($dept[$i] ?? null) !== $w) $bad[] = $w[0];
        $seed = $bad ? "differs:" . implode(",", $bad) : "ok";
        $tl = $p->query("SELECT channel_id, action, actor FROM wa_channel_log ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        $trail = $tl === [["sales", "seeded", "migration 087"], ["support", "seeded", "migration 087"], ["account", "seeded", "migration 087"]] ? "ok" : "differs";
        $rows = count($all) . ":" . count($tl);
    }
    echo "mig=$mig tables=$nt/" . count($tables) . " indexes=$ni/" . count($indexes) . " triggers=$ng/" . count($triggers) . " checks=$nc/2 rows=$rows seed=$seed other=$other trail=$trail missing=" . ($miss ? implode(",", $miss) : "-");
} catch (Throwable $e) { echo "?"; }
PHP
)"
wa87_state() {
  if [ -z "$DB_OWNER" ]; then   # never open the database as root: SQLite could leave a root-owned -wal/-shm beside it
    if docker exec "$CONTAINER" test -f "$PDD_IN/plugin.sqlite3" 2>/dev/null; then echo "?"; else echo "nodb"; fi
    return
  fi
  docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$WA87_PHP" "$PDD_IN" "$WA_TABLES" "$WA_INDEXES" "$WA_TRIGGERS" 2>/dev/null </dev/null || echo "?"
}
# How a release's code routes the three department numbers on THIS server's configuration, through forStore() — the path
# the webhook, the AI worker, the follow-ups and the Inbox take: "files" is what the webhook and the workers read (the
# configuration files as the installed plugin reads them, the vault filling only what is missing), "store" the row the
# Inbox reads. Prints the shape — the channel each number's inbound lands on, which numbers share an instance, whether
# Evolution is configured, whether the registry is in effect — then "|" and an HMAC of the instance names under this run's
# key, kept to compare. The names themselves are never printed.
MAP_PHP="$(cat <<'PHP'
[$code, $inst, $pdd, $salt, $which] = array_slice($argv, 1, 5);
try {
    require_once $code . "/lib/ConfigVault.php";
    putenv("DN_VAULT_FILE=" . ConfigVault::path($inst, $pdd));   // the vault the installed plugin reads, read-only (fill)
    require_once $code . "/lib/PluginConfig.php"; require_once $code . "/lib/EvolutionApiService.php";
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    if ($which === "files") $c = PluginConfig::read($inst, $pdd);
    else { $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn(); $c = is_string($row) ? (json_decode($row, true) ?: []) : []; }
    $e = EvolutionApiService::forStore($c, $p, $pdd);
    $out = []; $in = []; $grp = [];
    foreach (["sales", "support", "account"] as $ch) {
        $i = $e->instanceFor($ch); $out[$ch] = $i; $in[$ch] = $i === "" ? "-" : $e->channelFor($i);
        if ($i !== "") $grp[strtolower($i)][] = $ch;
    }
    $sh = []; foreach ($grp as $g) if (count($g) > 1) $sh[] = implode("+", $g);
    echo "sales:in=", $in["sales"], " support:in=", $in["support"], " account:in=", $in["account"], " shared=", $sh ? implode(",", $sh) : "-",
         " evo=", $e->canReachApi() ? "yes" : "no", " registry=", $e->registryOn() ? "on" : "off",
         "|", substr(hash_hmac("sha256", json_encode([$out, $in]), $salt), 0, 32);
} catch (Throwable $e) { echo "?", get_class($e); }
PHP
)"
map_state() {   # map_state CODE_ROOT files|store — the shape, "|", the HMAC (compared, never printed)
  [ -n "$DB_OWNER" ] || { echo "?noowner"; return; }
  docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$MAP_PHP" "$1" "$IN_CONTAINER" "$PDD_IN" "$MAP_SALT" "$2" 2>/dev/null </dev/null || echo "?"
}
# The AI and WhatsApp settings — every ai_, evo_, openai_ and claude_ key and the registry's switch — in the files the
# workers read and in the row the panel reads: each value as an HMAC under this run's key, compared, never printed.
# Prints "files=<n> store=<m>|<json of copy.key → hmac>". Read-only.
AIS_PHP="$(cat <<'PHP'
[$r, $pdd, $salt, $flag] = array_slice($argv, 1, 4);
try {
    require_once $r . "/lib/PluginConfig.php";
    $f = PluginConfig::read($r, $pdd);
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
    $s = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $o = []; $n = ["files" => 0, "store" => 0];
    foreach (["files" => $f, "store" => $s] as $w => $c) foreach ($c as $k => $v) {
        if (!preg_match("/^(ai_|evo_|openai_|claude_)/", (string)$k) && $k !== $flag) continue;
        $o[$w . "." . $k] = substr(hash_hmac("sha256", json_encode($v), $salt), 0, 16); $n[$w]++;
    }
    ksort($o);
    echo "files=", $n["files"], " store=", $n["store"], "|", json_encode($o);
} catch (Throwable $e) { echo "?"; }
PHP
)"
ai_state() {
  [ -n "$DB_OWNER" ] || { echo "?"; return; }
  docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$AIS_PHP" "$IN_CONTAINER" "$PDD_IN" "$MAP_SALT" "$MN_FLAG" 2>/dev/null </dev/null || echo "?"
}
ai_changed() {  # ai_changed BEFORE AFTER — the names (copy.key) whose value differs, or that were added or removed
  local x="/tmp/dnb_ais.$$"
  printf '%s' "${1#*|}" | tr -d '{}"' | tr ',' '\n' | grep . | sort > "$x.a"
  printf '%s' "${2#*|}" | tr -d '{}"' | tr ',' '\n' | grep . | sort > "$x.b"
  comm -3 "$x.a" "$x.b" | tr -d '\t' | cut -d: -f1 | sort -u | tr '\n' ' '; rm -f "$x.a" "$x.b"
}
# The registry is never consulted with its switch off (R11, RB): ChannelRegistry::enabled() over both copies, forStore()
# over both, and the Inbox's route for every kind of chat, each handed a database handle that records every statement it
# is asked to run. Prints "enabled=<files>/<store> forStore=<files>/<store> statements=<n> registry-reads=<n>". Read-only.
REG_PHP="$(cat <<'PHP'
[$r, $pdd] = array_slice($argv, 1, 2);
try {
    require_once $r . "/lib/PluginConfig.php"; require_once $r . "/lib/EvolutionApiService.php";
    require_once $r . "/lib/ChannelRegistry.php"; require_once $r . "/lib/InboxReplyRoute.php";
    class DnbRecordingPdo extends PDO {
        public array $seen = [];
        public function prepare(string $query, array $options = []): PDOStatement|false { $this->seen[] = $query; return parent::prepare($query, $options); }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $this->seen[] = $query; return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs); }
        public function exec(string $statement): int|false { $this->seen[] = $statement; return parent::exec($statement); }
    }
    $db = rtrim($pdd, "/") . "/plugin.sqlite3";
    $ro = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
    $p = new PDO("sqlite:" . $db, null, null, $ro);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn(); $p = null;
    $s = is_string($row) ? (json_decode($row, true) ?: []) : []; $f = PluginConfig::read($r, $pdd);
    $rec = new DnbRecordingPdo("sqlite:" . $db, null, null, $ro);
    $en = (ChannelRegistry::enabled($f, $pdd) ? "on" : "off") . "/" . (ChannelRegistry::enabled($s, $pdd) ? "on" : "off");
    $fs = [];
    foreach ([$f, $s] as $c) $fs[] = EvolutionApiService::forStore($c, $rec, $pdd)->registryOn() ? "on" : "off";
    foreach (["sales", "support", "account", "accounts", "web"] as $ch) InboxReplyRoute::decide($ch, $s, $pdd, $rec);
    $reads = count(array_filter($rec->seen, function ($q) { return stripos($q, "wa_channel") !== false; }));
    echo "enabled=", $en, " forStore=", implode("/", $fs), " statements=", count($rec->seen), " registry-reads=", $reads;
} catch (Throwable $e) { echo "?", get_class($e); }
PHP
)"
reg_state() {   # reg_state CODE_ROOT
  [ -n "$DB_OWNER" ] || { echo "?noowner"; return; }
  docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$REG_PHP" "$1" "$PDD_IN" 2>/dev/null </dev/null || echo "?"
}
# A release's event processor, once, as cron/master.php includes it, against a NEW store in a throwaway directory of the
# container's /tmp: five events seeded — a lead's sync, an AI turn, an ai.media event, a hand-over's escalation, an
# unknown type — and nothing else. No address of any kind is configured, so nothing can be sent; the data directory and
# the vault are the throwaway one's. Prints the signature (SS_SIGNATURE / UG_SIGNATURE above).
EP_PHP="$(cat <<'PHP'
[$root, $tmp, $profile] = array_slice($argv, 1, 3);
if (!is_dir($tmp) || count(scandir($tmp)) !== 2) { echo "?not-empty"; exit(2); }
putenv("DN_DATA_DIR=" . $tmp); putenv("DN_VAULT_FILE=" . $tmp . "/vault.json");
try {
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "EventBus"] as $l) require_once $root . "/lib/$l.php";
    $store = SqliteStore::create($tmp);
    $pdo = $store->getPdo(); $bus = new EventBus($pdo); $ids = [];
    foreach ([["crm.lead.sync", 5], ["ai.reply", 3], ["ai.media", 3], ["wa.escalation", 2], ["install.ready", 5]] as [$t, $prio]) {
        $ids[$t] = $bus->emit($t, "deploy_check", 1, ["deploy_check" => true], $prio, "deploy_check");
    }
    $log = $tmp . "/php_error.log"; ini_set("error_log", $log);
    $config = ["tenant_profile" => $profile]; $dataDir = $tmp;
    (function () use ($root, $store, $config, $dataDir) { ob_start(); try { include $root . "/cron/event_processor.php"; } finally { ob_end_clean(); } })();
    $lg = (string)@file_get_contents($log); $out = [];
    foreach ($ids as $t => $id) {
        $r = $pdo->query("SELECT status, attempts FROM events WHERE id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
        $out[] = $t . "=" . ($r["status"] ?? "?") . "/" . ($r["attempts"] ?? "?") . (strpos($lg, "unknown event type '$t'") !== false ? "+unknown" : "");
    }
    echo implode(" ", $out);
} catch (Throwable $e) { echo "?", get_class($e); exit(3); }
PHP
)"
ep_run() {   # ep_run CODE_ROOT uganda|south-sudan — the signature; the throwaway directory is removed again
  [ -n "$DB_OWNER" ] || { echo "?noowner"; return; }
  local d="/tmp/dnb-$EXPECTED_VERSION-ep-$TS-$$-$RANDOM" o
  docker exec -u "$DB_OWNER" "$CONTAINER" mkdir -m 700 "$d" 2>/dev/null </dev/null || { echo "?mkdir"; return; }
  o="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$EP_PHP" "$1" "$d" "$2" 2>/dev/null </dev/null)"
  docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$d" >/dev/null 2>&1 </dev/null || true
  printf '%s' "${o:-?}"
}
# The plugin's event queue, READ-ONLY. "summary": counts by status, and the events not yet done of the three types this
# release concerns. "tsv": every event not done — id, type, status, attempts, created, next retry; never a payload (they
# carry phone numbers and messages). "check": which of the ids on stdin are no longer there. Only done events older than
# 30 days are ever pruned (EventBus::prune), so an event not done at the snapshot must still be there.
EVQ_PHP="$(cat <<'PHP'
[$pdd, $mode] = array_slice($argv, 1, 2);
$db = rtrim($pdd, "/") . "/plugin.sqlite3";
if (!is_file($db)) { echo "nodb"; exit; }
try {
    $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    if ($p->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'events'")->fetchColumn() === false) { echo "noevents"; exit; }
    if ($mode === "tsv") {
        foreach ($p->query("SELECT id, event_type, status, attempts, created_at, next_retry_at FROM events WHERE status <> 'done' ORDER BY id")->fetchAll(PDO::FETCH_NUM) as $r) {
            echo implode("\t", array_map(function ($v) { return $v === null ? "" : (string)$v; }, $r)), "\n";
        }
        exit;
    }
    if ($mode === "check") {
        $ids = array_values(array_filter(array_map("intval", preg_split("/\s+/", (string)stream_get_contents(STDIN)))));
        $q = $p->prepare("SELECT 1 FROM events WHERE id = ?"); $miss = [];
        foreach ($ids as $id) { $q->execute([$id]); if ($q->fetchColumn() === false) $miss[] = $id; }
        echo "kept=", count($ids) - count($miss), "/", count($ids), " missing=", $miss ? implode(",", array_slice($miss, 0, 20)) : "-";
        exit;
    }
    $st = $p->query("SELECT status, COUNT(*) FROM events GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $own = $p->query("SELECT event_type, COUNT(*) FROM events WHERE status <> 'done' AND event_type IN ('ai.reply', 'crm.lead.sync', 'wa.escalation') GROUP BY event_type")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "total=", array_sum($st), " done=", (int)($st["done"] ?? 0), " pending=", (int)($st["pending"] ?? 0), " processing=", (int)($st["processing"] ?? 0),
         " failed=", (int)($st["failed"] ?? 0), " dead=", (int)($st["dead"] ?? 0), "; not done: ai.reply=", (int)($own["ai.reply"] ?? 0),
         " crm.lead.sync=", (int)($own["crm.lead.sync"] ?? 0), " wa.escalation=", (int)($own["wa.escalation"] ?? 0);
} catch (Throwable $e) { echo "?"; }
PHP
)"
evq() {         # evq summary|tsv
  [ -n "$DB_OWNER" ] || { echo "?"; return; }
  docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$EVQ_PHP" "$PDD_IN" "$1" 2>/dev/null </dev/null || echo "?"
}
evq_check() {   # the ids on stdin
  [ -n "$DB_OWNER" ] || { echo "?"; return; }
  docker exec -i -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$EVQ_PHP" "$PDD_IN" check 2>/dev/null || echo "?"
}
# The photo tables and files, before and after: a technician may take photos during the run, so they may grow; only a loss
# fails (until 5.18.88 R7 required them equal, and three photos taken during 5.18.87's deploy read as a change).
photo_growth() {   # photo_growth TB_BEFORE TB_AFTER PF_BEFORE PF_AFTER → same | grew | lost | unreadable
  local p1=0 g1=0 p2=0 g2=0 f1=0 f2=0 lost=""
  [ "$1" = "$2" ] && [ "$3" = "$4" ] && case "$1$3" in *"?"*|*nodb*) ;; *) echo same; return ;; esac
  case "$1" in present:*:*) p1="$(printf '%s' "$1" | cut -d: -f2)"; g1="$(printf '%s' "$1" | cut -d: -f3)" ;; lazy) ;; *) echo unreadable; return ;; esac
  case "$2" in present:*:*) p2="$(printf '%s' "$2" | cut -d: -f2)"; g2="$(printf '%s' "$2" | cut -d: -f3)" ;; lazy) [ "$1" = lazy ] || lost=1 ;; *) echo unreadable; return ;; esac
  case "$3" in files:*) f1="${3#files:}" ;; absent) ;; *) echo unreadable; return ;; esac
  case "$4" in files:*) f2="${4#files:}" ;; absent) [ "$3" = absent ] || lost=1 ;; *) echo unreadable; return ;; esac
  case "$p1$g1$p2$g2$f1$f2" in *[!0-9]*) echo unreadable; return ;; esac
  if [ -n "$lost" ] || [ "$p2" -lt "$p1" ] || [ "$g2" -lt "$g1" ] || [ "$f2" -lt "$f1" ]; then echo lost; else echo grew; fi
}

hdr "$EXPECTED_VERSION ($MODE) — $TS — $(hostname)"

# ════════════════════════════════════════════════════════
hdr "A. Before-evidence (read-only)"
# ════════════════════════════════════════════════════════
HEAD_REPO="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)"
HEAD_PLUGIN="$(git -C "$REPO" log -1 --format=%h -- "$PLUGIN" 2>/dev/null || echo unknown)"
DIRTY="$(git -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null | wc -l | tr -d ' ')"
echo "  checkout        $REPO"
echo "  repo HEAD       $HEAD_REPO"
echo "  branch tip      $HEAD_PLUGIN   (the plugin commit on $BRANCH — NOT what this script installs)"
echo "  release commit  $EXPECTED_PLUGIN_COMMIT   (on $RELEASE_BRANCH, cut on $BASELINE_COMMIT)"
# The release commit is not on the branch: fetch it when the checkout does not hold it yet, then prove it is cut on the
# live version. A pin whose parent is anything else is another build, and stops here.
if ! git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT^{commit}" 2>/dev/null; then
  echo "  fetching        origin $RELEASE_BRANCH — the release commit is not in this checkout yet"
  git -C "$REPO" fetch -q origin "$RELEASE_BRANCH" 2>&1 | sed 's/^/     /' || true
fi
git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT^{commit}" 2>/dev/null \
  || stop "the checkout does not hold $EXPECTED_PLUGIN_COMMIT — run: cd $REPO && git fetch origin $RELEASE_BRANCH, then this script again"
PIN_PARENT="$(git -C "$REPO" rev-parse --short "$EXPECTED_PLUGIN_COMMIT^" 2>/dev/null || echo '?')"
[ "$PIN_PARENT" = "$BASELINE_COMMIT" ] || stop "$EXPECTED_PLUGIN_COMMIT is not cut on $BASELINE_COMMIT ($BASELINE_VERSION): its parent is $PIN_PARENT — this script is for another build"
PIN_CHECKOUT=1
echo "  installs        $EXPECTED_PLUGIN_COMMIT by its hash: $EXPECTED_VERSION's own changes on the live $BASELINE_VERSION, without the undeployed work the branch tip ($HEAD_PLUGIN) also carries"
VERSION_SRC="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/manifest.json" 2>/dev/null | grep -o '"version": *"5[^"]*"' | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  plugin version  ${VERSION_SRC:-?}   (expected $EXPECTED_VERSION)"
echo "  tracked edits   $DIRTY"
[ "$VERSION_SRC" = "$EXPECTED_VERSION" ]        || stop "manifest.json in $EXPECTED_PLUGIN_COMMIT says ${VERSION_SRC:-?}, expected $EXPECTED_VERSION"
[ "$DIRTY" = "0" ]                              || stop "the checkout has $DIRTY locally edited tracked files — deploying would ship edits nobody reviewed"
git -C "$REPO" cat-file -e "$BASELINE_COMMIT^{commit}" 2>/dev/null || stop "the checkout does not hold $BASELINE_COMMIT ($BASELINE_VERSION) — the rollback commit must be present before anything changes"
git -C "$REPO" cat-file -e "$RELEASE_A_BASE^{commit}" 2>/dev/null || stop "the checkout does not hold $RELEASE_A_BASE (Release A's baseline) — pull the branch again"

CHANGED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
ADDED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=A "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
DELETED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=D "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
OTHER="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
N_CHANGED="$(printf '%s\n' "$CHANGED" | grep -c . || true)"; N_ADDED="$(printf '%s\n' "$ADDED" | grep -c . || true)"
echo "  $EXPECTED_VERSION         $N_CHANGED files differ from $BASELINE_COMMIT: $((N_CHANGED - N_ADDED)) changed, $N_ADDED added, $(printf '%s\n' "$DELETED" | grep -c . || true) removed"
[ "$N_CHANGED" -gt 0 ] || stop "Git reports no file between $BASELINE_COMMIT and $EXPECTED_PLUGIN_COMMIT"
[ -z "$DELETED$OTHER" ] || stop "the pin removes or retypes files ($(printf '%s ' $DELETED $OTHER | sed "s#$PLUGIN/##g")); $EXPECTED_VERSION removes none — this script is for another build"
# A7 — Domain B (refusal 7): the MikroTik control plane and the documents under the plugin directory. This release must
# not touch either: the whole repository's delta between the live release and the pin must be the plugin's own files, none
# under Domain B, and both trees must be the live release's, by hash. Checked before the allow-list, so that a pin touching
# Domain B is refused as such.
WHOLE="$(git -C "$REPO" diff --no-renames --name-only "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT")"
DB_BAD=""; DB_TREES=""
for f in $WHOLE; do
  case "$f" in "$PLUGIN"/*) ;; *) DB_BAD="$DB_BAD $f(outside-the-plugin)"; continue ;; esac
  for d in $DOMAIN_B; do case "$f" in "$PLUGIN/$d"/*) DB_BAD="$DB_BAD ${f#"$PLUGIN"/}" ;; esac; done
done
for d in $DOMAIN_B; do
  a="$(git -C "$REPO" rev-parse -q --verify "$BASELINE_COMMIT:$PLUGIN/$d" 2>/dev/null || echo none)"
  b="$(git -C "$REPO" rev-parse -q --verify "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$d" 2>/dev/null || echo none)"
  { [ "$a" != "none" ] && [ "$a" = "$b" ]; } || DB_BAD="$DB_BAD $d/:tree-differs"
  DB_TREES="$DB_TREES $d/ ${b:0:7}"
done
[ -z "$DB_BAD" ] || stop "NO-GO (Domain B): the pin changes Domain B, the plugin's documents or files outside the plugin:$DB_BAD — $EXPECTED_VERSION must never touch them. Nothing was changed"
ok "A7 Domain B untouched: the whole repository differs between $BASELINE_COMMIT and the pin by the plugin's $N_CHANGED files alone, none under Domain B, and its trees are the live release's —$DB_TREES"
# The delta must be exactly this release's files: every added file expected as added, every changed file expected as
# changed, and every expected file present. This is what keeps the undeployed portal, CSRF and AI media-layer work out.
STRAY=""; MISSING=""
for f in $CHANGED; do
  rel="${f#"$PLUGIN"/}"
  if printf '%s\n' "$ADDED" | grep -qxF -- "$f"; then in_list "$rel" "$EXPECTED_ADDED" || STRAY="$STRAY $rel(added)"
  else in_list "$rel" "$EXPECTED_CHANGED" || STRAY="$STRAY $rel"; fi
done
for rel in $EXPECTED_ADDED; do printf '%s\n' "$ADDED" | grep -qxF -- "$PLUGIN/$rel" || MISSING="$MISSING $rel(added)"; done
for rel in $EXPECTED_CHANGED; do
  printf '%s\n' "$CHANGED" | grep -qxF -- "$PLUGIN/$rel" && ! printf '%s\n' "$ADDED" | grep -qxF -- "$PLUGIN/$rel" || MISSING="$MISSING $rel"
done
[ -z "$STRAY" ] || stop "the pin carries files that are not $EXPECTED_VERSION's, which this release must not ship:$STRAY"
[ -z "$MISSING" ] || stop "the pin lacks files $EXPECTED_VERSION is made of:$MISSING — this script is for another build"
N_MIG="$(printf '%s\n' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"
echo "  migrations      $N_MIG added since $BASELINE_VERSION: $(printf '%s\n' "$CHANGED" | grep "^$PLUGIN/migrations/" | sed "s#^$PLUGIN/migrations/##" | tr '\n' ' ')(additive; applied by the plugin's own runner on its next request)"
{ [ "$N_MIG" = "1" ] && printf '%s\n' "$ADDED" | grep -qxF -- "$PLUGIN/$MIG87"; } || stop "the pin carries $N_MIG migration(s), not exactly $MIG87 — this script is for another build"
M87_SHA="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$MIG87" 2>/dev/null | sha256sum | cut -c1-64)"
[ "$M87_SHA" = "$MIG87_SHA256" ] || stop "the pin's $MIG87 is not the reviewed one (sha256 ${M87_SHA:0:16}…, reviewed ${MIG87_SHA256:0:16}…) — this script is for another build"
ok "A0 the release delta is exactly $EXPECTED_VERSION's $N_CHANGED files — $N_ADDED added, $((N_CHANGED - N_ADDED)) changed, one migration: the reviewed 087 (sha256 ${MIG87_SHA256:0:16}…); no partner-portal, CSRF or AI media-layer file"

MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
LIVE_BEFORE="$(live_commit)"
LIVE_VERSION="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  serves          $DEST"
echo "  live commit     ${LIVE_BEFORE:-unknown}"
echo "  live version    ${LIVE_VERSION:-?}"
echo "  --check says:"; bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | sed 's/^/     /'
echo "     (--check compares the container with the branch tip, $HEAD_PLUGIN; this script installs $EXPECTED_PLUGIN_COMMIT, so it reads \"NOT up to date\" before and after — expected)"

# The in-container plugin data directory (holds plugin.sqlite3), derived read-only — for the switch tool and the R3 table check.
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
fi
# Who owns the plugin's database: the reads of the new tables run as that user, so SQLite can never leave a file owned by root beside it.
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$PDD_IN/plugin.sqlite3" 2>/dev/null || true)"

case "$MODE" in
  deploy)
    if [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then note "the container already serves $EXPECTED_PLUGIN_COMMIT — skipping the deploy, running the checks"; MODE="after"
    elif [ "$LIVE_BEFORE" != "$BASELINE_COMMIT" ]; then
      stop "NO-GO: the container serves ${LIVE_BEFORE:-an unknown commit}; $EXPECTED_VERSION was built and tested against $BASELINE_COMMIT ($BASELINE_VERSION) — deploy $BASELINE_VERSION first (scripts/deploy-$BASELINE_VERSION.sh) and send its log"
    elif [ "$LIVE_VERSION" != "$BASELINE_VERSION" ]; then
      stop "NO-GO: the container's record says $BASELINE_COMMIT, but the installed manifest says ${LIVE_VERSION:-?}, not $BASELINE_VERSION — the installed plugin is not the release this script was built against. Nothing was changed. Send the log file"
    fi ;;
  after)
    [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ] || stop "the container serves ${LIVE_BEFORE:-?}, not $EXPECTED_PLUGIN_COMMIT" ;;
  rollback)
    if [ "$LIVE_BEFORE" = "$BASELINE_COMMIT" ]; then note "the container already serves $BASELINE_COMMIT ($BASELINE_VERSION) — nothing to roll back; running the rollback checks"
    elif [ "$LIVE_BEFORE" != "$EXPECTED_PLUGIN_COMMIT" ]; then
      stop "the container serves ${LIVE_BEFORE:-an unknown commit}, neither $EXPECTED_PLUGIN_COMMIT nor $BASELINE_COMMIT — roll back by hand, from the log of the deploy that put it there"
    fi ;;
esac

PHPV="$(docker exec "$CONTAINER" php -r 'echo PHP_VERSION;' 2>/dev/null | tr -cd '0-9.')"
[ -n "$PHPV" ] || stop "no php inside the container"
echo "  container PHP   $PHPV"
case "$PHPV" in 5.*|7.*) stop "the container's PHP is $PHPV; $EXPECTED_VERSION needs 8.0 or later (as $BASELINE_VERSION does)";; esac

# The two releases' code, for the checks that compare them on this server's own PHP and configuration (A6, A9, R12, R13,
# RB): lib/, cron/, migrations/ and profiles/ of the pin and of the live release, copied into the container's /tmp and
# removed when this run ends, however it ends. The plugin never reaches them, and nothing there is run on its data.
MAP_SALT="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"   # this run's key for comparing instance names and settings; never printed
CHK="/tmp/dnb-$EXPECTED_VERSION-code-$TS-$$"; CHK_HOST="$OUT/code-$TS-$$"; CHK_OK=""
PIN_ROOT="$CHK/pin/$PLUGIN"; BASE_ROOT="$CHK/base/$PLUGIN"
chk_cleanup() { docker exec "$CONTAINER" rm -rf "$CHK" >/dev/null 2>&1 </dev/null || true; rm -rf "$CHK_HOST"; }
trap chk_cleanup EXIT
CHK_PARTS="$PLUGIN/lib $PLUGIN/cron $PLUGIN/migrations $PLUGIN/profiles"
if mkdir -p "$CHK_HOST/pin" "$CHK_HOST/base" \
   && git -C "$REPO" archive "$EXPECTED_PLUGIN_COMMIT" $CHK_PARTS | tar --no-same-owner -xf - -C "$CHK_HOST/pin" \
   && git -C "$REPO" archive "$BASELINE_COMMIT" $CHK_PARTS | tar --no-same-owner -xf - -C "$CHK_HOST/base" \
   && chmod -R a+rX "$CHK_HOST" \
   && docker cp "$CHK_HOST" "$CONTAINER:$CHK" >/dev/null 2>&1 \
   && docker exec "$CONTAINER" test -f "$PIN_ROOT/cron/event_processor.php" 2>/dev/null </dev/null \
   && docker exec "$CONTAINER" test -f "$BASE_ROOT/cron/event_processor.php" 2>/dev/null </dev/null; then
  CHK_OK=1
  echo "  release code    $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION) and $BASELINE_COMMIT ($BASELINE_VERSION), copied into the container's /tmp for the comparisons below — removed when this run ends"
else
  echo "  release code    could NOT be copied into the container's /tmp — every comparison that needs it says so below"
fi

# The live pilot switch, read with the plugin's own tool (--show is read-only). A regression check: this deploy never touches it.
switch_state() {  # prints "pilot=<on|off>"
  docker exec "$CONTAINER" php "$IN_CONTAINER/$TOOL" --show 2>/dev/null \
    | awk '/distributors_enabled/ { print "pilot=" (toupper($2)=="ON"?"on":"off"); exit }'
}
SW_BEFORE="$(switch_state)"
echo "  switch          ${SW_BEFORE:-pilot=?}   (the operator's setting; this deploy never changes it)"

# A switch, in BOTH places the plugin keeps it, judged as the plugin's own flagOn() judges it:
#   files — the configuration files, through the installed plugin's own read-only path (PluginConfig::read reads them in
#           the plugin's order and changes nothing on disk); what the plugin's tools read, and webhook.php beneath the store;
#   store — the kyc_config row in plugin.sqlite3, read-only; what public.php reads, and webhook.php first.
# set_config.php writes both. The vault never holds these keys. Read as the database's owner.
cfg_switch() {  # cfg_switch KEY PREFIX — prints PREFIX=<files>/<store>, each absent|off|on|?, the store also nodb; or PREFIX=?
  local v=""
  [ -n "$DB_OWNER" ] && v="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
    $r = $argv[1]; $pdd = $argv[2]; $key = $argv[3];
    $judge = function ($c) use ($key) {
      if (!is_array($c)) return "?";
      $v = $c[$key] ?? null;
      if ($v === null || $v === "") return "absent";
      if (is_bool($v)) return $v ? "on" : "off";
      return in_array(strtolower(trim((string)$v)), ["1", "true", "on", "yes"], true) ? "on" : "off";
    };
    $files = "?";
    try {
      if (is_file($r . "/lib/PluginConfig.php")) { require_once $r . "/lib/PluginConfig.php"; if (method_exists("PluginConfig", "read")) $files = $judge(PluginConfig::read($r, $pdd)); }
    } catch (Throwable $e) { $files = "?"; }
    $store = "?"; $db = rtrim($pdd, "/") . "/plugin.sqlite3";
    if (!is_file($db)) $store = "nodb";
    else try {
      $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
      if ($p->query("SELECT 1 FROM sqlite_master WHERE type = \x27table\x27 AND name = \x27kyc_config\x27")->fetchColumn() === false) $store = "absent";
      else { $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn(); $store = $row === false ? "absent" : $judge(json_decode((string)$row, true)); }
    } catch (Throwable $e) { $store = "?"; }
    echo "$files/$store";
  ' "$IN_CONTAINER" "$PDD_IN" "$1" 2>/dev/null)"
  case "$v" in */*) echo "$2=$v" ;; *) echo "$2=?" ;; esac
}
sw_off()      { case "${1#*=}" in absent/absent|absent/off|off/absent|off/off) return 0;; esac; return 1; }   # both read, both off
sw_on()       { case "${1#*=}" in on/*|*/on) return 0;; esac; return 1; }                                      # either on
sw_both_on()  { [ "${1#*=}" = "on/on" ]; }
ia_store_on() { case "$1" in ia=*/on) return 0;; esac; return 1; }                                             # what the authorisation page reads
ia_switch_state() { cfg_switch install_auth_enabled ia; }   # 5.18.83's — LIVE: switched on by the operator on 06 Oct
wa_switch_state() { cfg_switch "$WA_KEY" wa; }              # 5.18.84's — LIVE: switched on by the operator on 06 Oct
IA_BEFORE="$(ia_switch_state)"
echo "  authorisation   ${IA_BEFORE}   (install_auth_enabled in the configuration files / the store row; the operator's setting — this run never changes it)"
WA_BEFORE="$(wa_switch_state)"
echo "  booking WA      ${WA_BEFORE}   ($WA_KEY in the configuration files / the store row; the operator's setting — this run never changes it)"
# The lead switches, in both places, read as the other switches are. The workers (run_worker.php) load the configuration
# files — PluginConfig::load, the vault filling only what is missing — which is the files column here.
LC_BEFORE="$(cfg_switch "$LC_KEY" lc)"; LS_BEFORE="$(cfg_switch "$LS_KEY" ls)"; QU_BEFORE="$(cfg_switch "$QU_KEY" qu)"; SA_BEFORE="$(cfg_switch "$SA_KEY" sa)"
echo "  lead capture    ${LC_BEFORE}   ($LC_KEY in the configuration files / the store row; the operator's decision, 07 Oct: ON)"
echo "  uCRM lead write ${LS_BEFORE}   ($LS_KEY; the operator's decision, 07 Oct: OFF, until it is switched on as its own step)"
echo "  qualification   ${QU_BEFORE}   ($QU_KEY — the rules that ask the assistant to record a lead; reported, never changed)"
echo "  sales on all    ${SA_BEFORE}   ($SA_KEY — the support and account number sell, and record leads, too; reported)"

# The settings row the WhatsApp Inbox reads — public.php's $config is $store->load('kyc_config.json'): the kyc_config row
# of plugin.sqlite3 alone, no configuration file and no vault (docs/65 §Z.6) — judged by the INSTALLED EvolutionApiService
# exactly as 5.18.86's channel route will judge it (5.18.86 builds the same service from the same row: forStore() with the
# registry switch unset is the constructor): can it reach Evolution, and which numbers does it name. Read-only, as the
# database's owner. Prints yes/no only — never an address, a key or an instance name.
inbox_evo_state() {  # prints evo=<yes|no> sales=<yes|no> support=<yes|no> account=<yes|no> registry=<absent|off|on> | nodb | norow | ?
  if [ -z "$DB_OWNER" ]; then echo "?"; return; fi
  docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
    $r = $argv[1]; $db = rtrim((string)$argv[2], "/") . "/plugin.sqlite3"; $flag = $argv[3];
    if (!is_file($db)) { echo "nodb"; exit; }
    try {
      $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
      if ($p->query("SELECT 1 FROM sqlite_master WHERE type = \x27table\x27 AND name = \x27kyc_config\x27")->fetchColumn() === false) { echo "norow"; exit; }
      $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
      $c = is_string($row) ? json_decode($row, true) : null;
      if (!is_array($c)) { echo "norow"; exit; }
      require_once $r . "/lib/EvolutionApiService.php";
      $e = new EvolutionApiService($c);
      $y = function ($b) { return $b ? "yes" : "no"; };
      $v = $c[$flag] ?? null;
      $f = ($v === null || $v === "") ? "absent" : ((is_bool($v) ? $v : in_array(strtolower(trim((string)$v)), ["1", "true", "on", "yes"], true)) ? "on" : "off");
      echo "evo=", $y($e->canReachApi()), " sales=", $y($e->instanceFor("sales") !== ""), " support=", $y($e->instanceFor("support") !== ""),
           " account=", $y($e->instanceFor("account") !== ""), " registry=", $f;
    } catch (Throwable $e) { echo "?"; }
  ' "$IN_CONTAINER" "$PDD_IN" "$MN_FLAG" 2>/dev/null || echo "?"
}
IX_BEFORE="$(inbox_evo_state)"
echo "  Inbox settings  ${IX_BEFORE}   (the kyc_config row the Inbox reads, judged by the installed EvolutionApiService; yes/no only)"

# Migration 086, read-only, from the installed plugin.sqlite3, as the database's owner: the ledger row, every object 086
# creates and the rows in its five tables. Prints one line:
#   mig=<absent|applied:<md5>> tables=<n>/5 triggers=<n>/10 indexes=<n>/4 checks=<n>/2 rows=<t1>:<t2>:<t3>:<t4>:<t5> missing=<names|->
# or nodb | ?
ia_tables_state() {
  if [ -z "$DB_OWNER" ]; then   # never open the database as root: SQLite could leave a root-owned -wal/-shm beside it
    if docker exec "$CONTAINER" test -f "$PDD_IN/plugin.sqlite3" 2>/dev/null; then echo "?"; else echo "nodb"; fi
    return
  fi
  docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
    $db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
    if (!is_file($db)) { echo "nodb"; exit; }
    $tables = explode(" ", $argv[2]); $triggers = explode(" ", $argv[3]); $indexes = explode(" ", $argv[4]);
    try {
      $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
      $have = []; foreach ($p->query("SELECT type, name, sql FROM sqlite_master")->fetchAll(PDO::FETCH_ASSOC) as $o) $have[$o["type"] . ":" . $o["name"]] = (string)$o["sql"];
      $mig = "absent";
      if (isset($have["table:_migrations"])) {
        $q = $p->prepare("SELECT checksum FROM _migrations WHERE filename = ?"); $q->execute(["086_install_authorisation.sql"]);
        $c = $q->fetchColumn(); if ($c !== false) $mig = "applied:" . (string)$c;
      }
      $miss = []; $nt = 0; $ng = 0; $ni = 0; $rows = [];
      foreach ($tables as $t)   { if (isset($have["table:$t"])) { $nt++; $rows[] = (int)$p->query("SELECT COUNT(*) FROM [$t]")->fetchColumn(); } else { $miss[] = $t; $rows[] = "-"; } }
      foreach ($triggers as $t) { if (isset($have["trigger:$t"])) $ng++; else $miss[] = $t; }
      foreach ($indexes as $t)  { if (isset($have["index:$t"]))   $ni++; else $miss[] = $t; }
      $nc = 0;
      if (strpos($have["table:install_auth"] ?? "", "accepted_at IS NOT NULL AND accepted_method IS NOT NULL") !== false) $nc++; else $miss[] = "install_auth:accepted-check";
      if (strpos($have["table:install_auth_events"] ?? "", "INSTALLATION_EXEMPTED") !== false) $nc++; else $miss[] = "install_auth_events:event-check";
      echo "mig=$mig tables=$nt/" . count($tables) . " triggers=$ng/" . count($triggers) . " indexes=$ni/" . count($indexes) . " checks=$nc/2 rows=" . implode(":", $rows) . " missing=" . ($miss ? implode(",", $miss) : "-");
    } catch (Throwable $e) { echo "?"; }
  ' "$PDD_IN" "$IA_TABLES" "$IA_TRIGGERS" "$IA_INDEXES" 2>/dev/null || echo "?"
}
IA_TB_BEFORE="$(ia_tables_state)"
echo "  086 state       $IA_TB_BEFORE"
MN_BEFORE="$(cfg_switch "$MN_FLAG" mn)"
echo "  registry switch ${MN_BEFORE}   ($MN_FLAG in the configuration files / the store row; must read OFF — this release ships the registry dark)"
WA87_BEFORE="$(wa87_state)"
echo "  087 state       $WA87_BEFORE"
if [ "$MODE" = "deploy" ]; then
  if ! sw_off "$IA_BEFORE" && ! sw_both_on "$IA_BEFORE"; then
    stop "NO-GO: install_auth_enabled reads '$IA_BEFORE' (files/store) — this script must find it readable, its two copies agreeing (on in both, as the operator left it, or off in both), before it deploys. Send the log file"
  fi
  if ! sw_off "$WA_BEFORE" && ! sw_both_on "$WA_BEFORE"; then
    stop "NO-GO: $WA_KEY reads '$WA_BEFORE' (files/store) — this script must find it readable, its two copies agreeing (on in both, as the operator left it, or off in both), before it deploys: the tools and the webhook would act differently. Send the log file"
  fi
  case "$IA_TB_BEFORE" in
    "mig=applied:"*" tables=5/5 triggers=10/10 indexes=4/4 checks=2/2 "*) ;;
    "mig=applied:"*) stop "NO-GO: migration 086 (5.18.83's) is recorded but not complete on this server ($IA_TB_BEFORE) — Customer Installation Authorisation depends on it. Send the log file" ;;
    "mig=absent "*) stop "NO-GO: migration 086 is not applied on this server ($IA_TB_BEFORE), although $BASELINE_VERSION is live. Send the log file" ;;
    nodb) stop "NO-GO: no plugin.sqlite3 at $PDD_IN — the live plugin has a database; the derived data directory is wrong" ;;
    *) stop "NO-GO: the plugin database could not be read read-only as its owner (${DB_OWNER:-unknown}): '$IA_TB_BEFORE' — without that read, R3 could not verify migration 086 either. Send the log file" ;;
  esac
  # A3 — 5.18.86's Inbox route (live, unchanged by this release) sends a reply in a sales or account chat on that number or
  # not at all, from the Inbox's own settings row. The row is read as evidence: the reading must work (V3d compares it
  # after the run), but what it says belongs to 5.18.86, not to this release — reported, never a reason to stop.
  case "$IX_BEFORE" in
    "evo=yes sales=yes support="*" account=yes registry="*) ok "A3 the settings row the Inbox reads still reaches Evolution and names a sales and an account number — 5.18.86's Inbox replies keep a number to leave on ($IX_BEFORE)" ;;
    nodb|norow|"?"|"") stop "NO-GO: the settings row the Inbox reads could not be read read-only as its owner (${DB_OWNER:-unknown}): '$IX_BEFORE'. Send the log file" ;;
    *) note "the settings row the Inbox reads does not name every number ($IX_BEFORE): 5.18.86's Inbox refuses a reply in a chat whose number it lacks. $EXPECTED_VERSION does not change the Inbox" ;;
  esac
  # A4 — the lead switches, evidence: the operator decided on 07 Oct "Leads on, uCRM later" (capture ON in both copies, the
  # uCRM write OFF). 5.18.88 changes what becomes of a lead's sync on Uganda — the event processor no longer takes it — so
  # the uCRM write is said. Neither is a reason to stop, and the script switches neither.
  if sw_both_on "$LC_BEFORE" && sw_off "$LS_BEFORE"; then
    ok "A4 the lead switches are as the operator decided on 07 Oct: $LC_KEY ON in both copies, $LS_KEY OFF ($LC_BEFORE, $LS_BEFORE; files/store)"
  else
    note "the lead switches read $LC_BEFORE $LS_BEFORE (files/store), not the 07 Oct decision (capture ON in both copies, the uCRM write OFF). $EXPECTED_VERSION switches neither; with the uCRM write on, its event processor leaves every lead's sync to the uCRM worker, which then writes it to uCRM"
  fi
  case "${QU_BEFORE#*=}" in
    on/*) ;;
    *) note "$QU_KEY reads '$QU_BEFORE' (files/store): the assistant is asked to record a lead only under the qualification rules, so no lead will be recorded while it is off in the files the workers read. $EXPECTED_VERSION does not switch it" ;;
  esac
  # A5 — the channel registry's switch (refusal 2). 5.18.88 ships the registry dark: with its switch on, Uganda's WhatsApp
  # would route through wa_channels the moment 087 is applied. It must read off (or absent) in both copies — the files the
  # webhook and the workers read, and the row the Inbox reads; the vault never holds it. This script never switches it.
  if ! sw_off "$MN_BEFORE"; then
    stop "NO-GO: $MN_FLAG reads '$MN_BEFORE' (files/store). $EXPECTED_VERSION ships the channel registry dark, and with the switch on it would route Uganda's WhatsApp through it as soon as 087 is applied. It must read off in both copies before anything changes; this script never switches it. Nothing was changed. Send the log file"
  fi
  ok "A5 the channel registry's switch reads OFF in both copies ($MN_BEFORE; files/store) — $EXPECTED_VERSION ships the registry dark"
  # A8 — migration 087 (refusals 3 and 4). The plugin's runner skips a file it has recorded, so a recorded 087 would never
  # be applied as this release's; and objects of 087 present without its ledger row were made by hand or by an incomplete
  # run, which this release's 087 would meet half-built. Either stops the deploy before anything changes.
  case "$WA87_BEFORE" in
    "mig=absent tables=0/2 indexes=0/3 triggers=0/3 "*) ok "A8 migration 087 is not recorded on this server and none of its objects exists — the plugin's runner will apply it, whole, on its first request after the copy" ;;
    "mig=applied:"*) stop "NO-GO: a migration 087 is already recorded on this server ($WA87_BEFORE). The plugin's runner would skip $EXPECTED_VERSION's 087 as already applied. Nothing was changed. Send the log file" ;;
    "mig=absent "*)  stop "NO-GO: 087 is not recorded, yet some of its objects exist on this server ($WA87_BEFORE) — made by hand or by an incomplete run, which $EXPECTED_VERSION's 087 would meet half-built. Nothing was changed. Send the log file" ;;
    nodb) stop "NO-GO: no plugin.sqlite3 at $PDD_IN — the live plugin has a database; the derived data directory is wrong" ;;
    *)    stop "NO-GO: the plugin database could not be read read-only as its owner (${DB_OWNER:-unknown}): '$WA87_BEFORE' — without that read, R3 could not verify migration 087 either. Send the log file" ;;
  esac
fi
if [ "$MODE" = "rollback" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then
  note "after the rollback the event processor is $BASELINE_VERSION's again: on Uganda it claims every type, so a lead's uCRM sync can be acknowledged as an unknown type before the uCRM worker sees it (docs/65 §Z.5). 087's tables stay, unread while the switch is off; the AI's leads, the Inbox, the authorisation, the booking WhatsApp and the request form are $BASELINE_VERSION's, unchanged by this release"
fi

# A9 — the three department numbers, as the code installed now routes them on this server's own configuration, through
# forStore(): the files the webhook and the workers read, and the row the Inbox reads. Kept to compare after the run (R12,
# RB); instance names are compared under this run's key, never printed. On a deploy, the pin's code must route them exactly
# so before anything changes. The AI and WhatsApp settings, both copies, are kept the same way to compare after (V3g).
MAP_A_F="$(map_state "$IN_CONTAINER" files)"; MAP_A_S="$(map_state "$IN_CONTAINER" store)"
echo "  routing         ${MAP_A_F%%|*}   (the files the webhook and the workers read)"
echo "                  ${MAP_A_S%%|*}   (the row the Inbox reads)"
AIS_A="$(ai_state)"
echo "  AI settings     ${AIS_A%%|*} key(s) — every ai_, evo_, openai_ and claude_ key and $MN_FLAG (files/store); compared after the run, never printed"
if [ "$MODE" = "deploy" ]; then
  case "$MAP_A_F $MAP_A_S" in "?"*|*" ?"*) stop "NO-GO: the three numbers' routing could not be read on this server ($MAP_A_F / $MAP_A_S) — R12 could not compare it after the deploy. Send the log file" ;; esac
  case "$AIS_A" in files=*) ;; *) stop "NO-GO: the AI settings could not be read on this server ($AIS_A) — V3g could not compare them after the deploy. Send the log file" ;; esac
  [ -n "$CHK_OK" ] || stop "NO-GO: the two releases' code could not be copied into the container's /tmp — A9 and A6 compare it under the server's own PHP, and both are refusals. Nothing was changed. Send the log file"
  MAP_P_F="$(map_state "$PIN_ROOT" files)"; MAP_P_S="$(map_state "$PIN_ROOT" store)"
  if [ "$MAP_P_F" != "$MAP_A_F" ] || [ "$MAP_P_S" != "$MAP_A_S" ]; then
    stop "NO-GO: with the registry off the pin's code would not route the three numbers exactly as the live code does (files: ${MAP_P_F%%|*}; the Inbox's row: ${MAP_P_S%%|*}). Nothing was changed. Send the log file"
  fi
  ok "A9 the pin's code routes the three numbers exactly as the live code does, on this server's own configuration — the files the webhook and the workers read, and the Inbox's row: ${MAP_A_F%%|*} (instance names compared, never printed)"
fi

# uCRM's job.add as the plugin's own webhook log records it (read-only; the log keeps its last 300 entries): the event the
# booking WhatsApp hangs on. Evidence only, never a NO-GO — a quiet week has no new jobs.
JOBADD="$(docker exec "$CONTAINER" php -r '
  $l = @json_decode((string)@file_get_contents(rtrim($argv[1], "/") . "/webhook_log.json"), true);
  if (!is_array($l)) { echo "nolog"; exit; }
  $n = 0; $last = "";
  foreach ($l as $e) { if (($e["message"] ?? "") === "Received UCRM webhook: job.add") { $n++; $t = (string)($e["received_at"] ?? ""); if ($t > $last) $last = $t; } }
  echo $n, "|", $last;' "$PDD_IN" 2>/dev/null || true)"
case "$JOBADD" in
  ""|nolog)   echo "  job.add         the plugin's webhook log could not be read — evidence only" ;;
  "0|"*)      echo "  job.add         none among the webhook log's last 300 entries — evidence only (docs/07, 28 Sep: uCRM delivers job.add to the plugin)" ;;
  *)          echo "  job.add         ${JOBADD%%|*} received among the webhook log's last 300 entries, the last at ${JOBADD#*|} (the plugin's clock) — the event the booking WhatsApp hangs on" ;;
esac

# The two tables of migration 084 (from 5.18.66), read-only: present with their row counts, or lazy.
photo_tables_state() {  # prints present:<photos>:<gps> | lazy | nodb | ?
  docker exec "$CONTAINER" php -r '
    $db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
    if (!is_file($db)) { echo "nodb"; exit; }
    try { $p = new PDO("sqlite:" . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    catch (Throwable $e) { echo "nodb"; exit; }
    try { $have = $p->query("SELECT name FROM sqlite_master WHERE type=\x27table\x27")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { echo "?"; exit; }
    if (!in_array("job_photos", $have, true) || !in_array("job_completion_gps", $have, true)) { echo "lazy"; exit; }
    try { echo "present:", (int)$p->query("SELECT COUNT(*) FROM job_photos")->fetchColumn(), ":", (int)$p->query("SELECT COUNT(*) FROM job_completion_gps")->fetchColumn(); }
    catch (Throwable $e) { echo "?"; }
  ' "$PDD_IN" 2>/dev/null || echo "?"
}
# The pilot's tables, as 5.18.65's own check read them — a regression (R4).
dist_tables_state() {  # prints present | lazy | nodb | ?
  docker exec "$CONTAINER" php -r '
    $db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
    if (!is_file($db)) { echo "nodb"; exit; }
    try { $p = new PDO("sqlite:" . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    catch (Throwable $e) { echo "nodb"; exit; }
    $want = ["dist_partners","dist_appointment","dist_regions","dist_territory_map","dist_customer_links","dist_contacts","dist_notify_consent","dist_notify_log"];
    try { $have = $p->query("SELECT name FROM sqlite_master WHERE type=\x27table\x27")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { echo "?"; exit; }
    foreach ($want as $t) if (!in_array($t, $have, true)) { echo "lazy"; exit; }
    echo "present";
  ' "$PDD_IN" 2>/dev/null || echo "?"
}
# The photo folder in the data directory, read-only: absent, or how many files it holds.
photo_files_state() {  # prints absent | files:<n>
  docker exec "$CONTAINER" sh -c 'd="$1/uploads/job_photos"; if [ -d "$d" ]; then echo "files:$(find "$d" -type f | wc -l | tr -d " ")"; else echo absent; fi' _ "$PDD_IN" 2>/dev/null || echo "?"
}
TB_BEFORE="$(photo_tables_state)"; PF_BEFORE="$(photo_files_state)"
echo "  photo tables    $TB_BEFORE   (from 5.18.66; this deploy touches neither)"
echo "  photo files     $PF_BEFORE"

# The public address, by the plugin's own rule.
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){$over=$v; break;} }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
echo "  plugin URL      ${PLUGIN_BASE:-<unknown>}"

# A2 — every changed PHP file, as the pinned commit has it, checked by the server's own PHP before a byte is copied.
if [ "$MODE" = "deploy" ]; then
  L_OK=0; L_TOK=0; L_BAD=""; L_TBAD=""
  for f in $(printf '%s\n' "$CHANGED" | grep '\.php$'); do
    if git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" > "/tmp/dnb_lint.$$" 2>/dev/null \
       && docker exec -i "$CONTAINER" php -l < "/tmp/dnb_lint.$$" 2>&1 | grep -q '^No syntax errors detected'; then
      case "$f" in "$PLUGIN"/tests/*) L_TOK=$((L_TOK+1));; *) L_OK=$((L_OK+1));; esac
    else case "$f" in "$PLUGIN"/tests/*) L_TBAD="$L_TBAD ${f#"$PLUGIN"/}";; *) L_BAD="$L_BAD ${f#"$PLUGIN"/}";; esac; fi
  done
  rm -f "/tmp/dnb_lint.$$"
  [ -z "$L_BAD" ] || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"
  ok "A2 PHP $PHPV in the container accepts all $L_OK changed PHP files that run on the server (and $L_TOK test files)"
  [ -z "$L_TBAD" ] || note "PHP $PHPV rejects test files (they are copied, never run on the server):$L_TBAD"
fi

# A6 — South Sudan (refusal 6). The pin's event processor must keep South Sudan's loop exactly: its list ['ai.reply'], no
# ai.media anywhere, the early return on an empty claim, the Uganda path behind the country gate alone. Then, under the
# server's own PHP, the live 5.18.87 processor and the pin's are each run once as a South Sudan install, against a throwaway
# database in the container's /tmp — five events, no address configured, nothing else — and must leave them exactly alike;
# as a Uganda install the pin's must leave the workers' events to them. The live run must read 5.18.87's known signature
# first: a check that measured nothing would agree with anything.
if [ "$MODE" = "deploy" ]; then
  PIN_EP="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/cron/event_processor.php" 2>/dev/null)"
  A6_BAD=""
  [ "$(grep -cF "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];" <<<"$PIN_EP")" = "1" ] || A6_BAD="$A6_BAD lists"
  grep -qF 'ai.media' <<<"$PIN_EP" && A6_BAD="$A6_BAD ai.media-named"
  [ "$(grep -cF '    $events = $bus->consume(20);' <<<"$PIN_EP")$(grep -cF '    if (empty($events)) return; // nothing to process' <<<"$PIN_EP")" = "11" ] || A6_BAD="$A6_BAD early-return"
  [ "$(grep -cF '    $_epUg = StaffJobsGate::applies(' <<<"$PIN_EP")" = "1" ] || A6_BAD="$A6_BAD country-gate"
  if [ -n "$CHK_OK" ]; then SS_LIVE="$(ep_run "$IN_CONTAINER" south-sudan)"; SS_PIN="$(ep_run "$PIN_ROOT" south-sudan)"; UG_PIN="$(ep_run "$PIN_ROOT" uganda)"
  else SS_LIVE="?nocode"; SS_PIN="?nocode"; UG_PIN="?nocode"; fi
  echo "  South Sudan     live $BASELINE_VERSION: $SS_LIVE"
  echo "                  pin  $EXPECTED_VERSION: $SS_PIN"
  echo "  Uganda          pin  $EXPECTED_VERSION: $UG_PIN"
  [ "$SS_LIVE" = "$SS_SIGNATURE" ] || A6_BAD="$A6_BAD live-loop-not-measured"
  [ "$SS_PIN" = "$SS_LIVE" ] || A6_BAD="$A6_BAD south-sudan-differs"
  [ "$UG_PIN" = "$UG_SIGNATURE" ] || A6_BAD="$A6_BAD uganda-not-protected"
  [ -z "$A6_BAD" ] || stop "NO-GO (South Sudan): the pin does not keep South Sudan's event loop exactly, or the check could not be made:$A6_BAD — nothing was changed. Send the log file"
  ok "A6 South Sudan keeps $BASELINE_VERSION's event loop exactly: run as a South Sudan install, the pin's processor leaves five events as the live one does (its list ['ai.reply'], no ai.media); as a Uganda install it leaves ai.reply and crm.lead.sync to their workers and settles wa.escalation as known — on throwaway databases, nothing sent, nothing of the plugin's touched"
fi

# ── Backup (code + data), on deploy or on a rollback from the new version ────
BK=""
if [ "$MODE" = "deploy" ] || { [ "$MODE" = "rollback" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; }; then
  hdr "A. Backup"
  BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"
  DATA_HOST="$MOUNT${PDD_IN#/data}"; [ -d "$DATA_HOST" ] || DATA_HOST="$PDD_IN"
  SNAP_DBS="plugin.sqlite3 dishnet.sqlite"
  SNAP_TMP="${DNB_SNAPSHOT_TMP:-/tmp}"
  SNAPSHOT_PHP="$(cat <<'PHP'
$src = $argv[1]; $tmp = $argv[2];
if (file_exists($tmp)) { fwrite(STDERR, "a file is already at the temporary path\n"); exit(4); }
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
try {
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30];
    $db = new PDO("sqlite:" . $src, null, null, $o);
    $v = (string)$db->query("SELECT sqlite_version()")->fetchColumn();
    $db->exec("VACUUM INTO " . $db->quote($tmp));
    $db = null;
    $c = new PDO("sqlite:" . $tmp, null, null, $o);
    $ic = $c->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN);
    $t = (int)$c->query("SELECT count(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
    $c = null;
    if ($ic !== ["ok"]) { fwrite(STDERR, "integrity_check of the copy: " . implode("; ", array_slice($ic, 0, 3)) . "\n"); exit(3); }
    clearstatcache();
    $n = filesize($tmp); $h = hash_file("sha256", $tmp);
    $f = fopen($tmp, "rb"); $w = fopen("php://stdout", "wb");
    $sent = stream_copy_to_stream($f, $w); fclose($f); fclose($w);
    if ($sent !== $n) { fwrite(STDERR, "sent $sent of $n bytes\n"); exit(5); }
    fwrite(STDERR, "SNAPSHOT $n $h $t $v\n");
} catch (Throwable $e) { fwrite(STDERR, get_class($e) . ": " . $e->getMessage() . "\n"); exit(2); }
PHP
)"
  for db in $SNAP_DBS; do
    src="$PDD_IN/$db"
    if ! docker exec "$CONTAINER" test -f "$src" 2>/dev/null; then echo "  …     $db is not in the data directory — nothing to copy"; continue; fi
    owner="$(docker exec "$CONTAINER" stat -c '%u:%g' "$src" 2>/dev/null || true)"
    if [ -z "$owner" ]; then bad "backup of $db failed: could not read who owns it"; continue; fi
    docker exec -u "$owner" "$CONTAINER" php -d display_errors=stderr -r "$SNAPSHOT_PHP" "$src" "$SNAP_TMP/dnb-backup-$TS-$db" > "$BK/$db" 2> "$BK/$db.err"; rc=$?
    docker exec -u "$owner" "$CONTAINER" rm -f "$SNAP_TMP/dnb-backup-$TS-$db" 2>/dev/null || true
    set -- $(grep '^SNAPSHOT ' "$BK/$db.err" | tail -1)
    got_n="$(stat -c %s "$BK/$db" 2>/dev/null || echo 0)"; got_h="$(sha256sum "$BK/$db" 2>/dev/null | cut -c1-64)"
    if [ "$rc" = "0" ] && [ "${1:-}" = "SNAPSHOT" ] && [ "$got_n" = "${2:-}" ] && [ "$got_h" = "${3:-}" ]; then
      ok "backed up $db → $BK/$db ($(du -h "$BK/$db" | cut -f1)) — one consistent copy, integrity ok, ${4:-?} tables, SQLite ${5:-?}"
    elif [ "$rc" = "0" ] && [ "${1:-}" = "SNAPSHOT" ]; then bad "backup of $db failed: the copy that arrived is not the copy checked"
    else bad "backup of $db failed (exit $rc): $(grep -v '^SNAPSHOT ' "$BK/$db.err" | grep -v '^$' | head -2 | tr '\n' ' ' | cut -c1-200)"; fi
    set --
  done
  if [ -d "$DATA_HOST" ]; then
    ex=(); for db in $SNAP_DBS; do for s in "" -wal -shm -journal; do ex+=("--exclude=$(basename "$DATA_HOST")/$db$s"); done; done
    tar -C "$(dirname "$DATA_HOST")" ${ex[@]+"${ex[@]}"} -czf "$BK/data.tar.gz" "$(basename "$DATA_HOST")" 2> "$BK/data.tar.err"; rc=$?
    if [ "$rc" -le 1 ] && tar -tzf "$BK/data.tar.gz" >/dev/null 2>&1; then ok "backed up the data dir → $BK/data.tar.gz ($(du -h "$BK/data.tar.gz" | cut -f1)) — without the live databases, copied above"
    else bad "backup of the data dir failed (tar exit $rc)"; fi
  fi
  CODE_TAR="$BK/plugin-installed-${LIVE_VERSION:-unknown}.tar.gz"
  tar -C "$(dirname "$DEST")" --exclude="$PLUGIN/data" -czf "$CODE_TAR" "$PLUGIN" 2> "$BK/plugin-installed.tar.err"; rc=$?
  if [ "$rc" = "0" ] && tar -tzf "$CODE_TAR" >/dev/null 2>&1; then ok "backed up the installed plugin (${LIVE_VERSION:-?}, ${LIVE_BEFORE:-?}) → $CODE_TAR ($(du -h "$CODE_TAR" | cut -f1))"
  else bad "backup of the installed plugin failed (tar exit $rc)"; fi
  VAULT="$MOUNT/ucrm/data/plugins/.dishnet-sudan.vault.json"
  if [ -f "$VAULT" ]; then cp -p "$VAULT" "$BK/config-vault.json" && cmp -s "$VAULT" "$BK/config-vault.json" && ok "backed up the configuration vault → $BK/config-vault.json" || bad "backup of the configuration vault failed"
  else note "no configuration vault at the plugins directory — nothing to copy"; fi
  # The event queue (refusal 8 if it fails): every event not done yet, beside the database's own consistent copy above.
  EQ_BEFORE="$(evq summary)"
  if [ "${EQ_BEFORE#total=}" != "$EQ_BEFORE" ] && evq tsv > "$BK/event-queue.tsv" && ! grep -qvE $'^[0-9]+\t' "$BK/event-queue.tsv"; then
    ok "snapshot of the event queue → $BK/event-queue.tsv — $(grep -c . "$BK/event-queue.tsv" || true) event(s) not done yet: id, type, status, attempts and times, never a payload ($EQ_BEFORE)"
  else bad "snapshot of the event queue failed ($EQ_BEFORE)"; fi
  cp "$DEST/.deployed-commit" "$BK/deployed-commit.before" 2>/dev/null || true
  # DEPLOYED_AT in ISO-8601, the form the container log writes its times in (V4 of an --after-only run reads it).
  printf 'DEPLOYED_AT=%s\nFROM_COMMIT=%s\nSW_BEFORE=%s\nIA_BEFORE=%s\nWA_BEFORE=%s\nIX_BEFORE=%s\nLC_BEFORE=%s\nLS_BEFORE=%s\nQU_BEFORE=%s\nSA_BEFORE=%s\nTB_BEFORE=%s\nPF_BEFORE=%s\nMN_BEFORE=%s\nWA87_BEFORE=%s\nEQ_FILE=%s\nEQ_BEFORE=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$LIVE_BEFORE" "$SW_BEFORE" "$IA_BEFORE" "$WA_BEFORE" "$IX_BEFORE" "$LC_BEFORE" "$LS_BEFORE" "$QU_BEFORE" "$SA_BEFORE" "$TB_BEFORE" "$PF_BEFORE" "$MN_BEFORE" "$WA87_BEFORE" "$BK/event-queue.tsv" "$EQ_BEFORE" > "$STATE"
  chmod -R go-rwx "$BK"
  [ "$FAIL" = "0" ] || stop "NO-GO: the backup or a before-check did not complete"
  echo; echo "  GO — evidence recorded, backup in $BK"
  [ "$MODE" = "deploy" ] && echo "  The rollback command is printed at the end of this log, on its own."
fi

# §16.23: each changed file gets the time of this copy so PHP-FPM recompiles it.
stamp_copy() { local f rel n=0; for f in $CHANGED; do rel="${f#"$PLUGIN"/}"; [ -f "$DEST/$rel" ] && touch -c "$DEST/$rel" && n=$((n+1)); done; echo "  gave the $n installed file(s) this release changes the time of this copy, $(date -u +%H:%M:%S) UTC"; }

do_rollback() {
  local ref; ref="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"
  echo "  the checkout is on $ref; checking out $BASELINE_COMMIT ($BASELINE_VERSION) for the documented deploy"
  git -C "$REPO" checkout -q "$BASELINE_COMMIT" || { bad "git checkout $BASELINE_COMMIT failed — nothing was deployed"; return 1; }
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; local rc=${PIPESTATUS[0]}
  if [ "$rc" = "2" ]; then note "the container was still restarting; waiting for it"; local i; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do sleep 10; if [ "$(live_commit)" = "$BASELINE_COMMIT" ]; then rc=0; break; fi; done; fi
  git -C "$REPO" checkout -q "$ref" || bad "could not return the checkout to $ref — run: cd $REPO && git checkout $ref"
  stamp_copy
  local live; live="$(live_commit)"; echo "  the container now serves ${live:-?}; the checkout is back on $ref"
  [ "$rc" = "0" ] && [ "$live" = "$BASELINE_COMMIT" ]
}

DEPLOY_STARTED=""
# ════════════════════════════════════════════════════════
if [ "$MODE" = "deploy" ]; then
  hdr "B. The documented deploy (scripts/deploy-hybrid.sh)"
  printf '  Type DEPLOY to deploy %s (%s) over live %s, anything else to stop: ' "$EXPECTED_VERSION" "$EXPECTED_PLUGIN_COMMIT" "${LIVE_BEFORE:-?}"
  read -r ANSWER </dev/tty || ANSWER=""; echo
  [ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"
  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  DEPLOY_REF=""
  if [ -n "$PIN_CHECKOUT" ]; then
    DEPLOY_REF="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"
    echo "  the checkout is on $DEPLOY_REF ($HEAD_PLUGIN); checking out $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION, the release commit) for the documented deploy"
    git -C "$REPO" checkout -q "$EXPECTED_PLUGIN_COMMIT" || stop "git checkout $EXPECTED_PLUGIN_COMMIT failed — nothing was deployed"
  fi
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then note "the container was still restarting; waiting for it"; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do sleep 10; if [ "$(live_commit)" = "$EXPECTED_PLUGIN_COMMIT" ]; then RC=0; break; fi; done; fi
  if [ -n "$DEPLOY_REF" ]; then git -C "$REPO" checkout -q "$DEPLOY_REF" && echo "  the checkout is back on $DEPLOY_REF" || bad "could not return the checkout to $DEPLOY_REF — run: cd $REPO && git checkout $DEPLOY_REF"; fi
  stamp_copy
  LIVE_AFTER="$(live_commit)"
  if [ "$RC" = "0" ] && [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ]; then ok "container serves $LIVE_AFTER"
  else stop "deploy did not verify (rc=$RC, live=${LIVE_AFTER:-?}). Roll back with the separate rollback command: cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback"; fi
elif [ "$MODE" = "rollback" ]; then
  hdr "B. The rollback: the documented deploy of $BASELINE_COMMIT ($BASELINE_VERSION)"
  if [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then
    printf '  Type ROLLBACK to put %s (%s) back over live %s, anything else to stop: ' "$BASELINE_VERSION" "$BASELINE_COMMIT" "$LIVE_BEFORE"
    read -r ANSWER </dev/tty || ANSWER=""; echo
    [ "$ANSWER" = "ROLLBACK" ] || stop "not confirmed"
    DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if do_rollback; then ok "container serves $BASELINE_COMMIT ($BASELINE_VERSION)"; else stop "the rollback did not verify (live=$(live_commit)) — by hand: cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"; fi
  else DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"; fi
  LIVE_AFTER="$(live_commit)"
else
  LIVE_AFTER="$LIVE_BEFORE"; ok "live commit is $LIVE_AFTER"
  DEPLOY_STARTED="$(sed -n 's/^DEPLOYED_AT=//p' "$STATE" 2>/dev/null | head -1)"; [ -n "$DEPLOY_STARTED" ] || DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

# ════════════════════════════════════════════════════════
hdr "V. Verification over the public address (nothing signed in; nothing written)"
# ════════════════════════════════════════════════════════
if [ -n "$PLUGIN_BASE" ]; then
  http GET "$PLUGIN_BASE?page=customer_login" -L
  if [ "$HTTP_CODE" = "000" ]; then
    case "$PLUGIN_BASE" in
      *:8443*) note "V1/V2/V5/V7 could not probe the public address: the derived address is $PLUGIN_BASE and the server cannot reach its own :8443 public port from inside the host (curl 000 — hairpin). This says nothing about whether the pages work; the code is verified by R1–R6 below and by V4 (no fatal). To confirm the pages themselves, open $PLUGIN_BASE?page=customer_login in a browser, or re-run: bash scripts/deploy-$EXPECTED_VERSION.sh --after-only --plugin-base <an address the server can reach>" ;;
      *) note "V1/V2/V5/V7 could not probe the public address: $PLUGIN_BASE is not reachable from the server (curl 000). This says nothing about the pages; R1–R6 and V4 verify the code. Open the sign-in page in a browser, or re-run with --plugin-base <a reachable address>" ;;
    esac
  else
    if [ "$HTTP_CODE" = "200" ] && [ "$HTTP_REDIRECTS" = "0" ]; then ok "V1 the sign-in page on the public address answers 200 with zero redirects (no loop)"
    else
      bad "V1 the sign-in page on the public address → $HTTP_CODE after $HTTP_REDIRECTS redirect(s) ${HTTP_LOCATION:+(last $HTTP_LOCATION)}"
      if [ "$MODE" = "deploy" ] && [ "${HTTP_REDIRECTS:-0}" != "0" ]; then echo; echo "  ROLLING BACK — the public address must never redirect"; do_rollback || true; stop "rolled back (serves $(live_commit)); send the log file"; fi
    fi
    http GET "$PLUGIN_BASE?page=customer_portal&view=home"
    case "$HTTP_CODE" in 302|401) ok "V1 the portal without a session still refuses ($HTTP_CODE)";; *) bad "V1 the portal without a session → $HTTP_CODE";; esac
    http GET "$PLUGIN_BASE?page=customer_login"
    if [ "$HTTP_CODE" = "200" ] && [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ]; then ok "V2 the sign-in page answers 200 and carries no South Sudan contact"
    else bad "V2 sign-in page → $HTTP_CODE; '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"; fi
    # V6 — 5.18.73/5.18.74: the sign-in page allows pinch-zoom; 5.18.88 keeps it (and so does the rollback target, 5.18.87).
    if [ "$HTTP_CODE" = "200" ] && [ "$(count 'user-scalable=no')" = "0" ] && [ "$(count 'maximum-scale=')" = "0" ]; then ok "V6 the sign-in page allows pinch-zoom (no user-scalable=no, no maximum-scale) — as on 5.18.74"
    else bad "V6 the sign-in page blocks zoom: user-scalable=no ×$(count 'user-scalable=no') · maximum-scale= ×$(count 'maximum-scale=') (HTTP $HTTP_CODE)"; fi
    # V5 — the photo surfaces of 5.18.66 still refuse an anonymous caller. Both reads change nothing; neither ever answers 200 to nobody.
    if [ "$MODE" != "rollback" ]; then
      http GET "$PLUGIN_BASE?page=job_photo&id=1"
      case "$HTTP_CODE" in 302|401|403) ok "V5 the photo viewer without a session refuses ($HTTP_CODE) — a photo is never served to nobody";; *) bad "V5 the photo viewer without a session → $HTTP_CODE (expected 302/401/403)";; esac
      http POST "$PLUGIN_BASE?page=api&action=job_photo_upload"
      case "$HTTP_CODE" in 401|403) ok "V5 job_photo_upload without a login answers $HTTP_CODE — the staff guard, before any handler";; *) bad "V5 job_photo_upload without a login → $HTTP_CODE (expected 401)";; esac
    fi
    # V7 — 5.18.83's authorisation page answers as its switch says, unchanged by this release: with install_auth_enabled on
    # in the store row (the operator's setting since 06 Oct), 404 "This link is not valid" to a request without a link —
    # malformed links are refused before the database is read; with it off, 404 "This page is not available.".
    # V8 — the staff side refuses an anonymous caller before any handler, as every staff action does.
    if [ "$MODE" != "rollback" ]; then
      http GET "$PLUGIN_BASE?page=install_auth"
      NA="$(count 'This page is not available.')"; NV="$(count 'This link is not valid or has expired.')"
      if ia_store_on "$IA_BEFORE"; then
        if [ "$HTTP_CODE" = "404" ] && [ "$NV" = "1" ] && [ "$NA" = "0" ]; then ok "V7 the customer authorisation page answers 404 \"This link is not valid\" — the switch is on, and a request without a link is refused"
        else bad "V7 the customer authorisation page → $HTTP_CODE; 'not available' ×$NA · 'link is not valid' ×$NV (with the switch on it must answer 404 'This link is not valid')"; fi
      elif [ "$HTTP_CODE" = "404" ] && [ "$NA" = "1" ] && [ "$NV" = "0" ]; then
        ok "V7 the customer authorisation page answers 404 \"This page is not available.\" — the switch is off, so it behaves as a page that does not exist"
      else bad "V7 the customer authorisation page → $HTTP_CODE; 'not available' ×$NA · 'link is not valid' ×$NV (with the switch off it must answer 404 'This page is not available.')"; fi
      http POST "$PLUGIN_BASE?page=api&action=install_auth_request"
      case "$HTTP_CODE" in 401|403) ok "V8 install_auth_request without a login answers $HTTP_CODE — the staff guard, before any handler";; *) bad "V8 install_auth_request without a login → $HTTP_CODE (expected 401)";; esac
      # V9 — 5.18.85: the form's data, which now reads the customer's quotations, refuses an anonymous caller before any of it.
      http GET "$PLUGIN_BASE?page=api&action=install_auth_prefill&job_id=1"
      case "$HTTP_CODE" in 401|403) ok "V9 install_auth_prefill without a login answers $HTTP_CODE — the staff guard, before a quotation is read";; *) bad "V9 install_auth_prefill without a login → $HTTP_CODE (expected 401)";; esac
      # V10 — 5.18.86: the Inbox's reply action, which now chooses the number from the conversation, refuses an anonymous
      # caller before a conversation is read or a number chosen. Nothing is sent: the guard answers first.
      http POST "$PLUGIN_BASE?page=api&action=wa_send_reply"
      case "$HTTP_CODE" in 401|403) ok "V10 wa_send_reply without a login answers $HTTP_CODE — the staff guard, before any conversation is read or anything sent";; *) bad "V10 wa_send_reply without a login → $HTTP_CODE (expected 401)";; esac
    fi
  fi
else note "V1/V2/V5/V7 skipped — the plugin's public URL could not be derived (re-run with --plugin-base); the R checks below read the install directly"; fi

# V3 — the pilot switch is UNCHANGED by the deploy: whatever state the operator left it in, it stays.
SW_AFTER="$(switch_state)"
if [ -z "$SW_AFTER" ] || ! printf '%s' "$SW_AFTER" | grep -q '='; then bad "V3 could not read the pilot switch ($SW_AFTER)"
elif [ "$SW_AFTER" = "${SW_BEFORE:-}" ]; then ok "V3 the pilot switch is unchanged by the deploy (before=$SW_BEFORE, after=$SW_AFTER) — $EXPECTED_VERSION changes no configuration value"
else bad "V3 the pilot switch CHANGED across the deploy (before=${SW_BEFORE:-?}, after=$SW_AFTER) — $EXPECTED_VERSION must never touch it"; fi
printf '  …     the pilot reads %s (set by the operator; manage it with set_distributors.php --show/--on/--off)\n' "${SW_AFTER#pilot=}"
# V3b — install_auth_enabled (5.18.83's, live) is UNCHANGED in both places, its two copies agreeing: this run never touches it.
IA_AFTER="$(ia_switch_state)"
if ! sw_off "$IA_AFTER" && ! sw_on "$IA_AFTER"; then bad "V3b could not read install_auth_enabled in both places ($IA_AFTER, files/store)"
elif [ "$IA_AFTER" != "$IA_BEFORE" ]; then bad "V3b install_auth_enabled CHANGED across this run (before=$IA_BEFORE, after=$IA_AFTER, files/store) — this run must never touch it"
elif sw_on "$IA_AFTER" && ! sw_both_on "$IA_AFTER"; then bad "V3b the configuration files and the store row disagree about install_auth_enabled ($IA_AFTER, files/store): the tools and the pages would act differently. Send the log file"
else ok "V3b install_auth_enabled is unchanged by this run (before=$IA_BEFORE, after=$IA_AFTER; files/store)$(sw_both_on "$IA_AFTER" && echo ' — ON in both: Customer Installation Authorisation stays live, as the operator left it' || echo ' — OFF in both')"; fi
# V3c — customer_wa_install_scheduled (5.18.84's, live) is UNCHANGED in both places, its two copies agreeing: this run never touches it.
WA_AFTER="$(wa_switch_state)"
if sw_both_on "$WA_AFTER"; then WA_SAYS=" — ON in both: the booking WhatsApp stays live, as the operator left it$([ "$MODE" = "rollback" ] && echo "; $BASELINE_VERSION sends it too, naming the technician")"
else WA_SAYS=" — OFF in both: no customer is sent the booking WhatsApp, as the operator left it"; fi
if ! sw_off "$WA_AFTER" && ! sw_on "$WA_AFTER"; then bad "V3c could not read $WA_KEY in both places ($WA_AFTER, files/store)"
elif [ "$WA_AFTER" != "$WA_BEFORE" ]; then bad "V3c $WA_KEY CHANGED across this run (before=$WA_BEFORE, after=$WA_AFTER, files/store) — this run must never touch it"
elif sw_on "$WA_AFTER" && ! sw_both_on "$WA_AFTER"; then bad "V3c the configuration files and the store row disagree about $WA_KEY ($WA_AFTER, files/store): the tools and the webhook would act differently. Send the log file"
else ok "V3c $WA_KEY is unchanged by this run (before=$WA_BEFORE, after=$WA_AFTER; files/store)$WA_SAYS"; fi

# V3d — the settings row the Inbox reads is UNCHANGED by this run, read as before (A3): this run never writes it.
IX_AFTER="$(inbox_evo_state)"
case "$IX_AFTER" in nodb|norow|"?"|"") bad "V3d could not read the Inbox's settings row ($IX_AFTER)" ;;
  *) if [ "$IX_AFTER" = "$IX_BEFORE" ]; then ok "V3d the settings row the Inbox reads is unchanged by this run (before=$IX_BEFORE, after=$IX_AFTER; yes/no only)"
     else bad "V3d the settings row the Inbox reads CHANGED across this run (before=$IX_BEFORE, after=$IX_AFTER) — this run must never touch it"; fi ;;
esac
# V3e — the four lead switches are UNCHANGED by this run, read as before (A4): this run never writes them.
LC_AFTER="$(cfg_switch "$LC_KEY" lc)"; LS_AFTER="$(cfg_switch "$LS_KEY" ls)"; QU_AFTER="$(cfg_switch "$QU_KEY" qu)"; SA_AFTER="$(cfg_switch "$SA_KEY" sa)"
V3E_BAD=""
for pair in "$LC_BEFORE|$LC_AFTER" "$LS_BEFORE|$LS_AFTER" "$QU_BEFORE|$QU_AFTER" "$SA_BEFORE|$SA_AFTER"; do
  b="${pair%%|*}"; a="${pair#*|}"
  case "$a" in *=*/*) ;; *) V3E_BAD="$V3E_BAD unreadable:$a" ;; esac
  [ "$a" = "$b" ] || V3E_BAD="$V3E_BAD ${a%%=*}:${b#*=}→${a#*=}"
done
# …and the two the operator decided, each's two copies agreeing: the workers read the files, the panel the store row.
for sw in "$LC_AFTER" "$LS_AFTER"; do
  case "$sw" in *=*/*) sw_off "$sw" || sw_both_on "$sw" || V3E_BAD="$V3E_BAD ${sw%%=*}:copies-disagree(${sw#*=})" ;; esac
done
if [ -z "$V3E_BAD" ]; then ok "V3e the lead switches are unchanged by this run ($LC_AFTER $LS_AFTER $QU_AFTER $SA_AFTER; files/store) — the script never switches them"
else bad "V3e the lead switches CHANGED across this run, could not be read, or their two copies disagree (files/store):$V3E_BAD — this run must never touch them, and the tools and the workers must read the same"; fi
# V3f — the channel registry's switch is UNCHANGED by this run and OFF in both copies, read as before (A5).
MN_AFTER="$(cfg_switch "$MN_FLAG" mn)"
if ! sw_off "$MN_AFTER" && ! sw_on "$MN_AFTER"; then bad "V3f could not read $MN_FLAG in both places ($MN_AFTER, files/store)"
elif [ "$MN_AFTER" != "$MN_BEFORE" ]; then bad "V3f $MN_FLAG CHANGED across this run (before=$MN_BEFORE, after=$MN_AFTER, files/store) — this run must never touch it"
elif ! sw_off "$MN_AFTER"; then bad "V3f $MN_FLAG reads ON ($MN_AFTER, files/store): the registry is not dark — Uganda's WhatsApp routes through wa_channels. Send the log file"
else ok "V3f the channel registry's switch is unchanged by this run and OFF in both copies ($MN_AFTER; files/store) — the registry stays dark"; fi
# V3g — S6: the AI and WhatsApp settings are UNCHANGED by this run — every ai_, evo_, openai_ and claude_ key and the
# registry's switch, both copies, compared under this run's key as read at A; never printed.
AIS_AFTER="$(ai_state)"
case "$AIS_A|$AIS_AFTER" in
  files=*"|files="*)
    if [ "$AIS_AFTER" = "$AIS_A" ]; then ok "V3g S6 the AI and WhatsApp settings are unchanged by this run (${AIS_A%%|*}; files/store — values never printed)"
    else bad "V3g the AI or WhatsApp settings CHANGED across this run: $(ai_changed "$AIS_A" "$AIS_AFTER")— this run must never touch them"; fi ;;
  *) bad "V3g could not read the AI settings (${AIS_A%%|*} / ${AIS_AFTER%%|*})" ;;
esac

# V4 — no fatal of the plugin in the container log since the deploy started, read in the form the log writes its times;
# docker failing to read the log is a FAIL, never a silent pass.
if [ "$MODE" != "after" ] && [ -n "$BK" ]; then printf '  …     waiting %ss for the first requests on the code now installed\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"; fi
V4_SINCE="$(iso_time "$DEPLOY_STARTED")"; V4_FILE="/tmp/dnb_v4.$$"
if log_since "$V4_SINCE" "$V4_FILE"; then
  V4_READ="$(grep -c . "$V4_FILE" || true)"
  V4_LINES="$(grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' "$V4_FILE" | grep -F -- "$PLUGIN" || true)"
  N_FATAL="$(printf '%s\n' "$V4_LINES" | grep -c . || true)"
  if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $V4_SINCE (${V4_READ:-0} line(s) read)"
  else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $V4_SINCE:"; printf '%s\n' "$V4_LINES" | cut -c1-200 | head -5 | sed 's/^/     /'; fi
else bad "V4 could not read the container log since $V4_SINCE: $(head -c 200 "$V4_FILE" 2>/dev/null | tr '\n' ' ')"; fi
rm -f "$V4_FILE" "$V4_FILE.in"

# Migration 086 judged as installed now — R3 after a deploy, RB after a rollback (defined here, before both).
check_086() {   # $1 the label
  local tb md5 mlog rows r3bad=""
  md5="$(md5sum "$DEST/$MIG" 2>/dev/null | cut -c1-32)"
  tb="$(ia_tables_state)"
  mlog="$(docker exec "$CONTAINER" sh -c 'grep -F "086_install_authorisation.sql" "$1/migration.log" 2>/dev/null | tail -3' _ "$PDD_IN" 2>/dev/null || true)"
  case "$tb" in
    "mig=applied:"*)
      [ "$(printf '%s' "$tb" | sed -E 's/^mig=applied:([0-9a-f]*) .*/\1/')" = "$md5" ] || r3bad="$r3bad ledger-checksum≠installed-file"
      printf '%s' "$tb" | grep -q ' tables=5/5 triggers=10/10 indexes=4/4 checks=2/2 ' || r3bad="$r3bad incomplete:$(printf '%s' "$tb" | sed -E 's/.* missing=//')"
      printf '%s\n' "$mlog" | grep -q 'PARTIAL' && r3bad="$r3bad migration.log:PARTIAL"
      rows="$(printf '%s' "$tb" | sed -E 's/.* rows=([^ ]*) .*/\1/')"
      if [ -n "$r3bad" ]; then bad "$1 migration 086 is recorded but not complete:$r3bad ($tb)"
      else ok "$1 migration 086 (5.18.83's) is still applied and complete — 5 tables, 10 triggers, 4 indexes, both CHECKs, the ledger row matching the installed file; its tables hold install_auth:events:activations:exempt:rate = $rows (before this run: $(printf '%s' "$IA_TB_BEFORE" | sed -E 's/.* rows=([^ ]*) .*/\1/'))"; fi ;;
    "mig=absent "*) bad "$1 migration 086 is not applied on this server ($tb)" ;;
    nodb) bad "$1 no plugin.sqlite3 at $PDD_IN" ;;
    *)    bad "$1 could not read migration 086's state ($tb)" ;;
  esac
}

# Migration 087 judged as installed now — R3 after a deploy (returns 1 when no request has applied it yet). What is read from
# the store is the verification: each of 087's 14 statements leaves something wa87_state reads. The runner's log is a second,
# independent trace, and it is read strictly: the runner skips a statement whose error it calls safe ("already exists",
# "no such column" in a CREATE, …) without counting or reporting it and still logs OK — so OK must count all 14.
check_087() {   # $1 the label
  local st md5 mlog nl nok cnt bad87=""
  md5="$(md5sum "$DEST/$MIG87" 2>/dev/null | cut -c1-32)"
  st="$(wa87_state)"
  mlog="$(docker exec "$CONTAINER" sh -c 'grep -F "087_wa_channels.sql" "$1/migration.log" 2>/dev/null | tail -3' _ "$PDD_IN" 2>/dev/null </dev/null || true)"
  nl="$(printf '%s\n' "$mlog" | grep -c . || true)"
  nok="$(printf '%s\n' "$mlog" | grep -cF "OK: 087_wa_channels.sql ($MIG87_STMTS stmts," || true)"
  case "$st" in
    "mig=applied:"*)
      [ "$(printf '%s' "$st" | sed -E 's/^mig=applied:([0-9a-f]*) .*/\1/')" = "$md5" ] || bad87="$bad87 ledger-checksum≠installed-file"
      printf '%s' "$st" | grep -q ' tables=2/2 indexes=3/3 triggers=3/3 checks=2/2 ' || bad87="$bad87 incomplete:$(printf '%s' "$st" | sed -E 's/.* missing=//')"
      printf '%s' "$st" | grep -q ' rows=3:3 seed=ok other=0 trail=ok ' || bad87="$bad87 $(printf '%s' "$st" | sed -E 's/.* (rows=[^ ]* seed=[^ ]* other=[^ ]* trail=[^ ]*) .*/\1/')"
      if printf '%s\n' "$mlog" | grep -qE 'PARTIAL|ERROR|WARNING'; then bad87="$bad87 migration.log:$(printf '%s\n' "$mlog" | grep -oE 'PARTIAL|ERROR|WARNING' | head -1)"
      elif [ "${nl:-0}" -gt 0 ] && [ "${nok:-0}" != "$nl" ]; then
        cnt="$(printf '%s\n' "$mlog" | grep -oE 'OK: 087_wa_channels\.sql \([0-9]+ stmts' | sed -E 's/.*\(([0-9]+) stmts/\1/' | grep -vx "$MIG87_STMTS" | head -1)"
        bad87="$bad87 migration.log:OK-counts-${cnt:-?}-of-$MIG87_STMTS-statements"
      fi
      if [ -n "$bad87" ]; then bad "$1 migration 087 is recorded but not as it must be:$bad87 ($st)"
      else ok "$1 migration 087 is applied and complete — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — holding exactly the three department rows as 087 seeds them, NO instance stored, no other channel, and the trail of three"; fi
      if [ "${nl:-0}" -gt 0 ]; then printf '%s\n' "$mlog" | cut -c1-160 | sed 's/^/  …     migration.log: /'
      else note "$1 migration.log holds no line for 087 (not written, or rotated away) — every statement's effect was read from the store above, which is the verification"; fi
      return 0 ;;
    "mig=absent "*) return 1 ;;
    nodb) note "$1 $MIG87 is installed; no plugin.sqlite3 yet at $PDD_IN"; return 0 ;;
    *)    bad "$1 could not read migration 087's state ($st)"; return 0 ;;
  esac
}
# Domain B as installed (R14, RB): deploy-hybrid.sh copies the whole plugin directory, so every file of the Domain B tree
# and of the plugin's documents is checked byte for byte against a commit's (the pin's and the live release's are the same
# trees, A7).
check_domainb() {   # $1 the commit, $2 the label
  local x="$OUT/domainb-$TS-$$" n=0 miss="?"
  if mkdir -p "$x" && git -C "$REPO" archive "$1" $(for d in $DOMAIN_B; do printf '%s ' "$PLUGIN/$d"; done) | tar --no-same-owner -xf - -C "$x"; then
    ( cd "$x/$PLUGIN" && find . -type f -print0 | sort -z | xargs -0 sha256sum ) > "$x.sums" 2>/dev/null
    n="$(grep -c . "$x.sums" || true)"
    miss="$( cd "$DEST" && sha256sum -c "$x.sums" 2>/dev/null | grep -v ': OK$' | cut -d: -f1 | head -5 | tr '\n' ' ')"
  fi
  rm -rf "$x" "$x.sums"
  if [ -z "$miss" ] && [ "${n:-0}" -gt 0 ]; then ok "$2 Domain B is installed exactly as $1 has it: all $n files of dishnet-mikrotik-control-plane/ and the plugin's docs/, byte for byte — this release touched neither"
  else bad "$2 Domain B files on the server differ from $1: ${miss:-none could be read}"; fi
}

if [ "$MODE" != "rollback" ]; then
# ════════════════════════════════════════════════════════
hdr "R. $EXPECTED_VERSION installed (read-only)"
# ════════════════════════════════════════════════════════
# R1 — every changed file is installed byte for byte, and the version.
R_OK=0; R_BAD=""
for f in $CHANGED; do
  rel="${f#"$PLUGIN"/}"
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi
done
[ -z "$R_BAD" ] && ok "R1 all $R_OK files $EXPECTED_VERSION changes are installed exactly as $EXPECTED_PLUGIN_COMMIT has them ($N_ADDED new)" || bad "R1 installed files that differ:$R_BAD"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$EXPECTED_VERSION" ] && ok "R1 the installed manifest says $IV" || bad "R1 the installed manifest says ${IV:-?}"

# R2 — the installed tool re-reads the live switch through the installed code; the deploy left it exactly as it was. And
# the installed authorisation code reads its own switch as the plugin will: InstallAuth::enabled() over the configuration.
if printf '%s' "$SW_AFTER" | grep -q '='; then ok "R2 the installed set_distributors --show reports $SW_AFTER (the operator's setting, unchanged by this deploy)"
else bad "R2 could not read the installed switch ($SW_AFTER)"; fi
IA_CODE="?"
[ -n "$DB_OWNER" ] && IA_CODE="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
  $r = $argv[1]; $pdd = $argv[2];
  try {
    require_once $r . "/lib/PluginConfig.php"; require_once $r . "/lib/InstallAuth.php";
    $f = PluginConfig::read($r, $pdd);
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
    $s = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $one = function (array $c) use ($pdd) { return (InstallAuth::flagOn($c) ? "on" : "off") . "/" . (InstallAuth::enabled($c, $pdd) ? "yes" : "no"); };
    echo "files=", $one($f), " store=", $one($s);
  } catch (Throwable $e) { echo "?"; }
' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || echo "?")"
case "$IA_CODE" in
  "files=off/no store=off/no") ok "R2 the installed InstallAuth reads install_auth_enabled OFF in the configuration files and in the store row the pages read — as before this run" ;;
  "files=on/yes store=on/yes") ok "R2 the installed InstallAuth reads install_auth_enabled ON in both places and enabled() is true — Customer Installation Authorisation stays live, as the operator left it" ;;
  *) bad "R2 the installed InstallAuth could not read its switch, or its two copies disagree ($IA_CODE)" ;;
esac
# …and the installed booking WhatsApp reads its own switch as the webhook will: InstallScheduledWhatsApp::enabled().
WA_CODE="?"
[ -n "$DB_OWNER" ] && [ -f "$DEST/$WA_LIB" ] && WA_CODE="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
  $r = $argv[1]; $pdd = $argv[2];
  try {
    require_once $r . "/lib/PluginConfig.php"; require_once $r . "/lib/InstallScheduledWhatsApp.php";
    $f = PluginConfig::read($r, $pdd);
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
    $s = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $one = function (array $c) use ($pdd) { return (InstallScheduledWhatsApp::flagOn($c) ? "on" : "off") . "/" . (InstallScheduledWhatsApp::enabled($c, $pdd) ? "yes" : "no"); };
    echo "files=", $one($f), " store=", $one($s);
  } catch (Throwable $e) { echo "?"; }
' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || echo "?")"
case "$WA_CODE" in
  "files=off/no store=off/no") if sw_off "${WA_AFTER:-}"; then ok "R2 the installed InstallScheduledWhatsApp reads $WA_KEY OFF in the configuration files and in the store row, and enabled() is false in both — as the operator left it"; else bad "R2 the installed InstallScheduledWhatsApp reads $WA_KEY OFF, but the configuration says ${WA_AFTER:-?} (files/store)"; fi ;;
  "files=on/yes store=on/yes") if sw_both_on "${WA_AFTER:-}"; then ok "R2 the installed InstallScheduledWhatsApp reads $WA_KEY ON in both places and enabled() is true — the booking WhatsApp stays live, as the operator left it"; else bad "R2 the installed InstallScheduledWhatsApp reads $WA_KEY ON, but the configuration says ${WA_AFTER:-?} (files/store)"; fi ;;
  *) bad "R2 the installed InstallScheduledWhatsApp could not read its switch, or its two copies disagree ($WA_CODE)" ;;
esac

# R3 — migration 086 (5.18.83's) is still installed and complete: the runner applies a file statement by statement and
# records it even when one fails ("PARTIAL"), so the ledger row alone proves nothing. Its tables hold the operator's
# records since the switch-on (activations, exemptions, requests, events); this run only reads them. Regression: 084 (from
# 5.18.66) still installed, its two tables untouched.
if [ ! -f "$DEST/$MIG" ]; then bad "R3 $MIG is not installed"; else check_086 "R3"; fi
if [ ! -f "$DEST/migrations/084_job_photos.sql" ]; then bad "R3 migrations/084_job_photos.sql from 5.18.66 is missing on the server"
else
  TB="$(photo_tables_state)"
  case "$TB" in
    present:*) ok "R3 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos ${TB#present:} rows (photos:gps), untouched by this deploy" ;;
    lazy)      note "R3 084 is installed; the two tables are not in plugin.sqlite3 yet — created on the plugin's next request" ;;
    nodb)      note "R3 084 is installed; no plugin.sqlite3 yet at $PDD_IN" ;;
    *)         note "R3 084 is installed; could not read the table state ($TB)" ;;
  esac
  PF="$(photo_files_state)"
  case "$PF" in
    absent)  echo "  …     uploads/job_photos does not exist in the data directory yet: no photo has been taken" ;;
    files:*) echo "  …     uploads/job_photos holds ${PF#files:} file(s) — photos technicians have taken (never touched by this script)" ;;
    *)       echo "  …     could not read uploads/job_photos ($PF)" ;;
  esac
fi
# R3 — migration 087 (5.18.88's one migration): the plugin's runner applies it on its next request. The runner records a
# file even when a statement fails ("PARTIAL"), and skips without a word a statement whose error it calls safe, so the
# ledger row alone proves nothing: every object, both CHECKs, the three department rows exactly as 087 seeds them — no
# instance stored — no other channel, and the trail of three; then its own log line, which must count all 14 statements.
MIG087_PENDING=""
if [ ! -f "$DEST/$MIG87" ]; then bad "R3 $MIG87 is not installed"
elif ! check_087 "R3"; then
  MIG087_PENDING=1
  echo "  …     R3 $MIG87 is installed; no request has reached the plugin since the copy, so its runner has not applied it yet — looked at again after R7"
fi

# R4 — regression: the distributor pilot is installed as before, its two webhook hooks flag-gated, and the bound channel is
# the Null one (the Evolution adapter is defined but constructed nowhere): nothing can be sent. And none of the undeployed
# work on the branch — the partner portal, the CSRF guard, the AI media layer (migration 085, the media worker, voice,
# image and document paths) — is installed.
R4_BAD=""
for lib in lib/DistributorRegistry.php lib/DistributorAttribution.php lib/DistributorNotifier.php lib/DistributorEvents.php lib/WhatsAppChannel.php; do
  [ -f "$DEST/$lib" ] || R4_BAD="$R4_BAD $lib:missing"
done
[ -f "$DEST/tabs/admin/distributors.php" ] || R4_BAD="$R4_BAD tabs/admin/distributors.php:missing"
grep -q 'class NullWhatsAppChannel' "$DEST/lib/WhatsAppChannel.php" 2>/dev/null || R4_BAD="$R4_BAD WhatsAppChannel:NullWhatsAppChannel"
grep -q 'new NullWhatsAppChannel' "$DEST/lib/DistributorNotifier.php" 2>/dev/null || R4_BAD="$R4_BAD notifier:Null-bound"
HOOKS="$(grep -c 'DistributorEvents::maybeNotify' "$DEST/webhook.php" 2>/dev/null || echo 0)"
[ "$HOOKS" = "2" ] || R4_BAD="$R4_BAD webhook:maybeNotify×$HOOKS(want 2)"
if grep -q 'new EvolutionWhatsAppChannel' "$DEST/webhook.php" 2>/dev/null \
   || grep -q 'new EvolutionWhatsAppChannel' "$DEST/includes/post/post_distributors.php" 2>/dev/null; then
  R4_BAD="$R4_BAD live-channel-bound"
fi
for f in partner_api.php lib/StaffApiCsrf.php lib/Totp.php lib/DistributorPortalData.php migrations/081_distributor_portal_accounts.sql migrations/085_wa_media.sql lib/MediaPolicy.php lib/InboundMedia.php lib/MediaWorker.php workers/MediaWorker.php run_media_worker.php; do
  [ -f "$DEST/$f" ] && R4_BAD="$R4_BAD $f:present(not-in-this-release)"
done
# The AI media layer is code this release does not carry: the event processor names no ai.media, the webhook has no media
# branch, the AI worker no media path.
grep -qF 'ai.media' "$DEST/cron/event_processor.php" 2>/dev/null && R4_BAD="$R4_BAD event_processor:ai.media(not-in-this-release)"
grep -qF 'MediaPolicy' "$DEST/evo_webhook.php" 2>/dev/null && R4_BAD="$R4_BAD evo_webhook:media-branch(not-in-this-release)"
grep -qF 'VoiceTranscription' "$DEST/workers/AiReplyWorker.php" 2>/dev/null && R4_BAD="$R4_BAD AiReplyWorker:media-layer(not-in-this-release)"
DT="$(dist_tables_state)"
[ -z "$R4_BAD" ] && ok "R4 the pilot libs + the Distributors tab are installed as before; webhook.php draws the two flag-gated hooks; the bound channel is NullWhatsAppChannel and the Evolution adapter is constructed nowhere — nothing can be sent; the pilot tables read $DT; no partner-portal, CSRF or AI media-layer file is installed, and neither the event processor, the webhook nor the AI worker carries any of the media layer" || bad "R4 the installed pilot is incomplete, binds a live channel, or carries files not in this release:$R4_BAD"

# R5 — Release A (5.18.49) → 5.18.83 installed as pinned (regression that nothing — the photo surface and 5.18.83's authorisation included — was lost).
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R5 all $RA_OK files from Release A through $BASELINE_VERSION are installed as $EXPECTED_PLUGIN_COMMIT has them — $EXPECTED_VERSION lost none of the prior releases" || bad "R5 files from Release A→$BASELINE_VERSION differ on the server:$RA_BAD"

# R6 — every earlier release's surface is still installed (regression), and 5.18.88's pieces are in place. R1 proves each
# file byte-for-byte; this is the readable confirmation.
R6_BAD=""
for f in lib/JobPhotos.php includes/api/api_job_photos.php migrations/084_job_photos.sql "$BACKFILL_TOOL" "$RECORDS_TOOL" "$CIH_TOOL"; do [ -f "$DEST/$f" ] || R6_BAD="$R6_BAD $f:missing"; done
grep -qF "require __DIR__ . '/api/api_job_photos.php'" "$DEST/includes/api_handlers.php" 2>/dev/null || R6_BAD="$R6_BAD api_handlers:no-require"
grep -qF 'var UG_PHOTOS = <?= $_sjUganda' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:no-uganda-gate"
grep -qF -- "$LAYOUT_MARK" "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:old-photo-layout"
N_AMT="$(grep -cF "!== 'SSP' ? \$cbAmount : 0" "$DEST/includes/post/post_cashbook.php" 2>/dev/null || echo 0)"
[ "$N_AMT" = "2" ] || R6_BAD="$R6_BAD auto-link:amount-rule×$N_AMT(want 2)"
grep -qF "dn_book_base(null)" "$DEST/lib/StaffCashPositionService.php" 2>/dev/null || R6_BAD="$R6_BAD position-service:literal-usd"
grep -qF "if (\$currency === '' || \$currency === 'SSP') \$currency = 'USD';" "$DEST/lib/StaffLedgerWriter.php" 2>/dev/null || R6_BAD="$R6_BAD ledger-writer:literal-usd"
grep -qF "public function cashInHand(string \$currency, string \$project = ''): float" "$DEST/lib/CashbookService.php" 2>/dev/null || R6_BAD="$R6_BAD service:no-cashInHand"
grep -qF 'CASH IN HAND</div>' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null || R6_BAD="$R6_BAD cashbook-page:no-cash-in-hand-card"
grep -qF '$cb->currencyPositions()' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null && R6_BAD="$R6_BAD cashbook-page:position-cards-still-drawn"
grep -qF '_navTenantUganda' "$DEST/includes/navigation.php" 2>/dev/null || R6_BAD="$R6_BAD strip:no-uganda-gate"
grep -qF "\$uL=array_values(array_filter(\$sc_ledger,fn(\$r)=>\$r['cur']===\$scBaseCode));" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:literal-usd"
grep -qF 'CASH IN HAND: ' "$DEST/cron/cashbook_summary.php" 2>/dev/null || R6_BAD="$R6_BAD evening-summary:position-model"
# 5.18.69: the Manual Entry stamp, the base-bag label, the export's tab→currency rule
grep -qF "'currency'        => \$manCur," "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD manual-entry:stamps-literal-usd"
grep -qF "\$scBaseCode.' Received'" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:usd-received-label"
grep -qF "=== 'ssp' ? 'SSP' : dn_book_base(\$config ?? null);" "$DEST/includes/routes.php" 2>/dev/null || R6_BAD="$R6_BAD export:literal-usd-tab"
grep -qF "'cat'=>\$_mcBase.' Received'," "$DEST/tabs/sales/my_account.php" 2>/dev/null || R6_BAD="$R6_BAD my-cash:usd-received-label"
# 5.18.70: the Field Register tells its forms the base and submits it; its export reads the base; the records tool is 5.18.70's
grep -qF 'var _fr3Base = <?= json_encode(dn_book_base($config)) ?>;' "$DEST/tabs/sales/wallet.php" 2>/dev/null || R6_BAD="$R6_BAD field-register:no-base-token"
[ "$(grep -cF "fr3fInCurrency').value  = _fr3Base;" "$DEST/tabs/sales/wallet.php" 2>/dev/null)" = "2" ] || R6_BAD="$R6_BAD field-register:literal-usd-submit"
grep -qF "fr3fInCurrency').value  = 'USD';" "$DEST/tabs/sales/wallet.php" 2>/dev/null && R6_BAD="$R6_BAD field-register:literal-usd-submit-present"
grep -qF '$frBase3 = dn_book_base($config ?? null);' "$DEST/includes/routes.php" 2>/dev/null || R6_BAD="$R6_BAD export:literal-usd-collection"
grep -qF '== staff records by currency (5.18.70)' "$DEST/$RECORDS_TOOL" 2>/dev/null || R6_BAD="$R6_BAD records-tool:not-5.18.70"
# 5.18.71: the dashboard's hero is cash in hand per currency, the account position is not drawn, money held by staff is listed, the tool is installed
grep -qF 'Cash in hand — per currency' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null || R6_BAD="$R6_BAD dashboard:no-cash-in-hand-hero"
grep -qF '$cbDash->currencyPositions()' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null && R6_BAD="$R6_BAD dashboard:account-position-still-drawn"
grep -qF '$_held = $_adJsonSvc->getUSDBalance($_hid);' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null || R6_BAD="$R6_BAD dashboard:no-held-by-staff"
grep -qF '== cash in hand (5.18.71)' "$DEST/$CIH_TOOL" 2>/dev/null || R6_BAD="$R6_BAD cash-in-hand-tool:missing-or-wrong"
# 5.18.72: on a book without SSP the Staff Cashbooks tiles read "received · Advances & collections" and "Still to account for"; South Sudan's "collected" tile is still in the file
grep -qF '<div class="cb3-stat-lbl"><?=$scBaseCode?> received</div>' "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:no-received-tile"
grep -qF "(\$scSSP?'Needs handover':'Still to account for')" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:no-account-for-line"
grep -qF '<div class="cb3-stat-sub">Advances &amp; collections</div>' "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:no-advances-subline"
grep -qF "<div class=\"cb3-stat-lbl\"><?=\$curTab==='ssp'?'SSP':\$scBaseCode?> collected</div>" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:south-sudan-tile-lost"
# 5.18.74: the Data Report hand-off is a tenant setting, ON in both profiles, the portal gates on it (twice), no tokenless fallback, the mint refuses empty inputs, the secret generator, "How to pay", zoom, no invented uptime, no hard-coded version
grep -qF '"data_report_handoff": true' "$DEST/profiles/uganda.json" 2>/dev/null || R6_BAD="$R6_BAD profile-uganda:no-handoff-on"
grep -qF '"data_report_handoff": false' "$DEST/profiles/uganda.json" 2>/dev/null && R6_BAD="$R6_BAD profile-uganda:5.18.73-off-value-present"
grep -qF "'webhook_secret' => ['secret'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-secret-generator"
grep -qF '" generated and stored (" . strlen($new) . " characters). It is not shown here;' "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:generator-message-changed"
grep -qF '$portalPayText = trim((string)((is_array($config ?? null) ? $config : [])[' "$DEST/tabs/customer_app/portal_data.php" 2>/dev/null || R6_BAD="$R6_BAD portal-data:no-pay-text"
grep -qF '>How to pay</div>' "$DEST/tabs/customer_app/portal.php" 2>/dev/null || R6_BAD="$R6_BAD portal:no-how-to-pay"
grep -qF '"data_report_handoff": true' "$DEST/profiles/south-sudan.json" 2>/dev/null || R6_BAD="$R6_BAD profile-south-sudan:no-handoff-on"
grep -qF 'public function dataReportHandoff(): bool' "$DEST/lib/TenantProfile.php" 2>/dev/null || R6_BAD="$R6_BAD tenant-profile:no-handoff-method"
grep -qF '$portalDataReportHandoff = $portalTenant->dataReportHandoff();' "$DEST/tabs/customer_app/portal_data.php" 2>/dev/null || R6_BAD="$R6_BAD portal-data:no-handoff-flag"
N_GATE="$(grep -cF '<?php if ($portalDataReportHandoff):' "$DEST/tabs/customer_app/portal.php" 2>/dev/null || echo 0)"
[ "$N_GATE" = "2" ] || R6_BAD="$R6_BAD portal:handoff-gates×$N_GATE(want 2)"
grep -qF ".catch(function () { location.href = url; });" "$DEST/tabs/customer_app/portal.php" 2>/dev/null && R6_BAD="$R6_BAD portal:tokenless-fallback-present"
grep -qF "'data_report_handoff_unconfigured'" "$DEST/includes/api/api_customer_app.php" 2>/dev/null || R6_BAD="$R6_BAD api:mint-does-not-refuse-empty-inputs"
grep -qF 'user-scalable=no' "$DEST/tabs/customer_app/login_web.php" 2>/dev/null && R6_BAD="$R6_BAD login:zoom-still-blocked"
grep -qF '24h uptime' "$DEST/tabs/customer_app/portal.php" 2>/dev/null && R6_BAD="$R6_BAD portal:invented-uptime-still-drawn"
grep -qF 'v4.12.20<br>' "$DEST/tabs/customer_app/portal.php" 2>/dev/null && R6_BAD="$R6_BAD portal:hard-coded-version-still-drawn"
# 5.18.83: the record and its one rule, the activation step, the uCRM-side check, the page behind its gate, the staff
# actions, the four guards (accept, check-in, check-out, complete), the link kept out of every store, the channels off by default
for f in lib/InstallAuth.php lib/InstallAuthNotifier.php lib/InstallAuthEmails.php lib/InstallationTerms.php includes/api/api_install_auth.php tabs/customer_app/install_auth_page.php "$MIG"; do [ -f "$DEST/$f" ] || R6_BAD="$R6_BAD $f:missing"; done
grep -qF 'public static function decision(\PDO $pdo, array $config, array $job): array' "$DEST/lib/InstallAuth.php" 2>/dev/null || R6_BAD="$R6_BAD install-auth:no-one-rule"
grep -qF 'public static function activate(\PDO $pdo, $crm, array $config, string $by, ?int $now = null): array' "$DEST/lib/InstallAuth.php" 2>/dev/null || R6_BAD="$R6_BAD install-auth:no-activation"
grep -qF 'public static function observeUcrm(' "$DEST/lib/InstallAuth.php" 2>/dev/null || R6_BAD="$R6_BAD install-auth:no-ucrm-check"
grep -qF "public const VERSION = 'INSTALLATION-TERMS-v1.0';" "$DEST/lib/InstallationTerms.php" 2>/dev/null || R6_BAD="$R6_BAD terms:not-v1.0"
grep -qF "require __DIR__ . '/api/api_install_auth.php';" "$DEST/includes/api_handlers.php" 2>/dev/null || R6_BAD="$R6_BAD api_handlers:no-install-auth-require"
grep -qF "if (\$page === 'install_auth') {" "$DEST/public.php" 2>/dev/null || R6_BAD="$R6_BAD public:no-install-auth-route"
grep -qF 'if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {' "$DEST/tabs/customer_app/install_auth_page.php" 2>/dev/null || R6_BAD="$R6_BAD page:no-gate"
grep -qF "\$job, \$statusInt, 'accept', (int)(\$_sjMe['id'] ?? 0));" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD guard:accept"
grep -qF "\$job, 2, 'complete', \$rid);" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD guard:complete"
grep -qF "\$_iaJob, 1, 'checkin', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || R6_BAD="$R6_BAD guard:checkin"
grep -qF "\$_iaJob, 2, 'checkout', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || R6_BAD="$R6_BAD guard:checkout"
grep -qF "public const NEVER_QUEUED = ['app_otp', 'ops_install_auth_request'];" "$DEST/lib/NotificationService.php" 2>/dev/null || R6_BAD="$R6_BAD notify:link-may-be-queued"
grep -qF "'body'       => self::storable(\$message)," "$DEST/lib/NotificationService.php" 2>/dev/null || R6_BAD="$R6_BAD notify:link-kept-in-inbox"
grep -qF 'private function installAuthObserve(int $jobId, ?array $was, array $job): ?array' "$DEST/lib/JobNotifier.php" 2>/dev/null || R6_BAD="$R6_BAD job-notifier:no-ucrm-check"
grep -qF "\$_iaGone  = !is_array(\$_iaStill) && (int)(\$crm->getLastError()['http_code'] ?? 0) === 404;" "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:delete-not-confirmed"
grep -qF "'install_auth_enabled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-switch"
grep -qF '$iaActivation = InstallAuth::activate($iaPdo, CrmApiClient::fromUcrm($root, $iaCur),' "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-activation-step"
grep -qF "return in_array(strtolower(trim((string)\$v)), ['1', 'on', 'true', 'yes'], true);" "$DEST/lib/CustomerEmailDispatcher.php" 2>/dev/null || R6_BAD="$R6_BAD emails:not-off-by-default"
grep -qF '🔴 CUSTOMER ACCEPTANCE REQUIRED' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:no-panel"
# 5.18.84: the booking WhatsApp — the class, its switch and Uganda gate, the claim before the send, the call in job.add
# beside the e-mail, the helper that never throws, the setting
[ -f "$DEST/$WA_LIB" ] || R6_BAD="$R6_BAD $WA_LIB:missing"
grep -qF "public const FLAG  = 'customer_wa_install_scheduled';" "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-switch"
grep -qF '        return StaffJobsGate::applies($config, $dataDir);' "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-uganda-gate"
grep -qF '        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {' "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-claim"
grep -qF '            whInstallScheduledWhatsApp($jobId, is_array($job) ? $job : [], is_array($client ?? null) ? $client : [],' "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-booking-call"
grep -qF 'function whInstallScheduledWhatsApp(int $jobId, array $job, array $client, string $technician, array $config, string $dataDir,' "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-booking-helper"
grep -qF "'customer_wa_install_scheduled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-booking-switch"
# 5.18.85: the quotation reader (the latest, this client's only), the prefill's one read of uCRM's quotations, the form's
# note and its transport guard, the technician's name from the staff account or uCRM's users/admins in job.add
[ -f "$DEST/$QP_LIB" ] || R6_BAD="$R6_BAD $QP_LIB:missing"
grep -qF 'public static function pick($quotes, int $clientId): ?array' "$DEST/$QP_LIB" 2>/dev/null || R6_BAD="$R6_BAD quotation:no-pick"
grep -qF "            if ((int)(\$q['clientId'] ?? 0) !== \$clientId) continue;" "$DEST/$QP_LIB" 2>/dev/null || R6_BAD="$R6_BAD quotation:other-clients"
grep -qF "\$iaQList = \$iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => \$cid]));" "$DEST/includes/api/api_install_auth.php" 2>/dev/null || R6_BAD="$R6_BAD prefill:no-quotation-read"
grep -qF 'Filled from quotation <b>' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD form:no-quotation-note"
grep -qF "if(window._iaTransportAsk && v('iaTransport')===''){" "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD form:no-transport-guard"
grep -qF 'public static function technicianName(int $ucrmUserId, $store, $crm): string' "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-name-lookup"
grep -qF "\$_whTech = \$_whJobNotifier ? whInstallTechName(\$assignedUserId, \$store, \$crm) : '';" "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-technician-name"
# 5.18.86: the Inbox's route and its South Sudan rule, the four Inbox sends through it, the channel send that never
# falls back to another number, forStore() and the constructor's own rule, the registry's switch
for f in "$IR_LIB" lib/ChannelRegistry.php lib/ChannelContext.php; do [ -f "$DEST/$f" ] || R6_BAD="$R6_BAD $f:missing"; done
grep -qF 'public static function decide(string $conversationChannel, array $config, ?string $dataDir, ?\PDO $pdo = null): array' "$DEST/$IR_LIB" 2>/dev/null || R6_BAD="$R6_BAD inbox-route:no-decide"
grep -qF "            return ['mode' => 'sender', 'sender' => \$ch === 'accounts' ? 'accounts' : 'support', 'channel' => \$ch, 'reason' => ''];" "$DEST/$IR_LIB" 2>/dev/null || R6_BAD="$R6_BAD inbox-route:no-south-sudan-rule"
N_IR="$(grep -cF "InboxReplyRoute::decide((string)(\$conv['channel'] ?? ''), (array)\$config, \$dataDir, \$store->getPdo());" "$DEST/includes/api/api_whatsapp.php" 2>/dev/null || true)"; N_IR="${N_IR:-0}"   # grep -c prints 0 itself when nothing matches
[ "$N_IR" = "4" ] || R6_BAD="$R6_BAD inbox:route×$N_IR(want 4)"
grep -qF "=== 'accounts' ? 'accounts' : 'support';" "$DEST/includes/api/api_whatsapp.php" 2>/dev/null && R6_BAD="$R6_BAD inbox:5.18.85-sender-line-still-present"
grep -qF 'public function sendOnChannel(string $channel, string $toPhone, string $text, string $event,' "$DEST/lib/NotificationService.php" 2>/dev/null || R6_BAD="$R6_BAD notify:no-channel-send"
grep -qF "            \$out['error'] = \"no WhatsApp number is connected for the '{\$channel}' channel\";" "$DEST/lib/NotificationService.php" 2>/dev/null || R6_BAD="$R6_BAD notify:channel-send-may-fall-back"
grep -qF 'public static function forStore(array $config, ?\PDO $pdo, ?string $dataDir, int $timeout = 20): self' "$DEST/lib/EvolutionApiService.php" 2>/dev/null || R6_BAD="$R6_BAD evolution:no-forStore"
grep -qF 'public static function configInstanceMap(array $config): array' "$DEST/lib/EvolutionApiService.php" 2>/dev/null || R6_BAD="$R6_BAD evolution:no-shared-rule"
grep -qF "    const FLAG = '$MN_FLAG';" "$DEST/lib/ChannelRegistry.php" 2>/dev/null || R6_BAD="$R6_BAD registry:no-switch"
# 5.18.87 — Batch 0: the capture's pin read from the context that exists, the uCRM worker reading the payload WorkerBase
# decodes (in handle() and onDead()), the sales context's pin, the guard's audit ids from the worker's own turn
grep -qF '$this->latestPin($convId, $context));' "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:capture-pin"
grep -qF '$this->latestPin($convId, $ctx));' "$DEST/workers/AiReplyWorker.php" 2>/dev/null && R6_BAD="$R6_BAD ai-worker:5.18.86-defect-still-present"
grep -qF 'private static function payloadOf(array $event): array' "$DEST/workers/UcrmLeadWorker.php" 2>/dev/null || R6_BAD="$R6_BAD lead-worker:no-payloadOf"
N_PO="$(grep -cF 'self::payloadOf($event);' "$DEST/workers/UcrmLeadWorker.php" 2>/dev/null || true)"; N_PO="${N_PO:-0}"   # grep -c prints 0 itself when nothing matches
[ "$N_PO" = "2" ] || R6_BAD="$R6_BAD lead-worker:payloadOf×$N_PO(want 2)"
grep -qF "'location'       => ['lat', 'lng', 'name', 'in_bounds']," "$DEST/lib/BrainContext.php" 2>/dev/null || R6_BAD="$R6_BAD brain-context:no-location"
grep -qF "'location'  => \$ctx['location'] ?? null," "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:sales-context-no-pin"
grep -qF "return (int)(\$this->turnIds['conversation_id'] ?? (\$ctx['conversation_id'] ?? 0));" "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:guard-ids"
# 5.18.88 — the rest of Batch 1: the webhook, the AI worker and the follow-ups through forStore(); a switched-off channel
# refused; the assistant's switch per number; the reply route behind the registry; the event processor's two lists and
# EventBus's exclusion; the lead's origin; the registry's switch in set_config.php; the read-only CLI and 087.
{ [ -f "$DEST/tools/channels.php" ] && [ -f "$DEST/$MIG87" ]; } || R6_BAD="$R6_BAD 5.18.88:channels-cli-or-087-missing"
grep -qF '$evo     = EvolutionApiService::forStore($config, $pdo, $dataDir);' "$DEST/evo_webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-forStore"
grep -qF "evoRespond(200, 'channel_disabled');" "$DEST/evo_webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-channel-refusal"
grep -qF '$aiOnChannel = $evo->channelAllowsAi($channel);' "$DEST/evo_webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-ai-switch"
grep -qF '$this->evo   = EvolutionApiService::forStore($config, $this->pdo, $dataDir);' "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:no-forStore"
grep -qF '        if (!$this->evo->registryOn()) return $channel;' "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:reply-route-not-behind-registry"
grep -qF '$evo     = EvolutionApiService::forStore($config, $pdo, $dataDir);' "$DEST/cron/followup_send.php" 2>/dev/null || R6_BAD="$R6_BAD followups:no-forStore"
grep -qF "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];" "$DEST/cron/event_processor.php" 2>/dev/null || R6_BAD="$R6_BAD event-processor:lists"
grep -qF "public function consume(int \$limit = 20, string \$workerId = '', array \$types = [], array \$excludeTypes = []): array" "$DEST/lib/EventBus.php" 2>/dev/null || R6_BAD="$R6_BAD eventbus:no-exclusion"
grep -qF 'public function withOrigin(?array $origin): self' "$DEST/lib/AiLeadService.php" 2>/dev/null || R6_BAD="$R6_BAD lead-service:no-origin"
grep -qF "'$MN_FLAG' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-registry-switch"
[ -z "$R6_BAD" ] && ok "R6 every earlier surface is installed as before (the photo surface, the 5.18.67 card, the 5.18.68 chain, the 5.18.69–5.18.72 fixes, 5.18.74's hand-off setting and \"How to pay\", 5.18.83's authorisation — the record and its one rule, the activation step, the uCRM-side check, the page behind its gate, the staff actions, the four guards, the link kept out of every store, the e-mails off by default, the job-page panel), 5.18.84's booking WhatsApp (the class with its switch, Uganda gate and once-per-job claim, its call in job.add beside the e-mail, the setting), 5.18.85's quotation form (the reader, the prefill's read of uCRM's quotations, the form's note and transport guard, the technician's name), 5.18.86's Inbox (the route and its South Sudan rule, all four Inbox sends through it, the channel send that never falls back, forStore() and the constructor's rule, the registry's switch), 5.18.87's Batch 0 (the capture's pin from the context, the uCRM worker reading its payload, the sales context's pin, the guard's audit ids), and 5.18.88's Batch 1 is in place: the webhook, the AI worker and the follow-ups through forStore(), a switched-off channel refused, the assistant's switch per number, the reply route behind the registry, the event processor's two lists and EventBus's exclusion, the lead's origin, the registry's switch in set_config.php, the read-only CLI and 087" || bad "R6 incomplete:$R6_BAD"

# R7 — cash in hand on the live book, READ-ONLY: tools/cash_in_hand.php (5.18.71's, unchanged by this release) prints the
# ledger's running balance per currency and the base per project — the figures the landing hero shows. Nothing is written.
R7_OUT="$(docker exec "$CONTAINER" php "$IN_CONTAINER/$CIH_TOOL" 2>&1)"; R7_RC=$?
if [ "$R7_RC" = "0" ] && printf '%s' "$R7_OUT" | grep -qF 'READ-ONLY — nothing was changed'; then
  ok "R7 cash in hand on the live book, read-only — what the landing hero shows: $(printf '%s' "$R7_OUT" | grep -E 'CASH IN HAND' | tr -s ' ' | sed 's/^ //' | paste -sd '|' - | sed 's/|/ · /g')"
  printf '%s\n' "$R7_OUT" | grep -E 'CASH IN HAND|Fiber & Starlink|DishNet 4G|BlueCARD' | sed 's/^/     /'
else
  bad "R7 the cash-in-hand tool failed (exit $R7_RC): $(printf '%s' "$R7_OUT" | tail -3 | tr '\n' ' ' | cut -c1-300)"
fi
# The photo tables and files across the run: photos taken meanwhile make them grow, which is fine; a loss is a FAIL.
TB_AFTER="$(photo_tables_state)"; PF_AFTER="$(photo_files_state)"
case "$(photo_growth "$TB_BEFORE" "$TB_AFTER" "$PF_BEFORE" "$PF_AFTER")" in
  same) ok "R7 the photo tables and files read exactly as before the run ($TB_AFTER, $PF_AFTER) — the tool touched no data" ;;
  grew) ok "R7 the photo tables and files only grew during the run ($TB_BEFORE → $TB_AFTER, $PF_BEFORE → $PF_AFTER): photos taken meanwhile — nothing lost, and nothing in this run writes either" ;;
  lost) bad "R7 the photo tables or files LOST something during the run ($TB_BEFORE → $TB_AFTER, $PF_BEFORE → $PF_AFTER)" ;;
  *)    bad "R7 could not compare the photo tables and files ($TB_BEFORE → $TB_AFTER, $PF_BEFORE → $PF_AFTER)" ;;
esac
# R3, again — when no request had reached the plugin by R3 (the public address unreachable from the server, for one), the
# first thing in this run to open the plugin's store is R7's tool, and the plugin's own runner applies 087 then.
if [ -n "$MIG087_PENDING" ] && ! check_087 "R3 (after R7)"; then
  note "R3 (after R7) $MIG87 is still not applied — the plugin's runner applies it on the plugin's next request (open the plugin in uCRM once), then re-run: cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"
fi


# R8 — the installed quotation reader, under the server's own PHP, on a quotation shaped like DishNet Uganda's 000181: a
# kit, a monthly plan, "Professional Installation", and transport "borne by the customer" at 0. A pure function: nothing
# is read from uCRM and nothing is written.
R8_OUT="$(docker exec "$CONTAINER" php -r '
  require_once $argv[1] . "/lib/QuotationPrefill.php";
  $q = ["id" => 1, "clientId" => 1, "number" => "R8", "status" => 1, "createdDate" => "2026-10-06T10:15:00+0300", "items" => [
    ["label" => "Starlink Mini Kit + Mini Router", "price" => 2249000, "quantity" => 1, "total" => 2249000, "unit" => "Pc"],
    ["label" => "Residential Lite (up to 100 Mbps)", "price" => 249000, "quantity" => 1, "total" => 249000, "unit" => "Monthly"],
    ["label" => "Professional Installation", "price" => 150000, "quantity" => 1, "total" => 150000, "unit" => "Time"],
    ["label" => "Transportation charges to and from the site shall be borne by the customer.", "price" => 0, "quantity" => 1, "total" => 0, "unit" => "Time"]]];
  $p = QuotationPrefill::pick([$q], 1); $x = $p ? QuotationPrefill::suggest($p) : [];
  echo ($x["equipment"] ?? "?"), "|", ($x["service"] ?? "?"), "|", var_export($x["installation"] ?? null, true), "|", var_export($x["transport"] ?? null, true), "|", empty($x["transport_unpriced"]) ? "fill" : "ask";
' "$IN_CONTAINER" 2>&1)"
if [ "$R8_OUT" = "Starlink Mini Kit + Mini Router|Residential Lite (up to 100 Mbps)|'150000'|NULL|ask" ]; then
  ok "R8 the installed quotation reader reads a quotation shaped like 000181 as the form will: the kit, the plan, installation 150000, transport asked for — nothing read from uCRM, nothing written"
else bad "R8 the installed quotation reader read a 000181-shaped quotation as: $(printf '%s' "$R8_OUT" | head -c 300)"; fi

# R9 — 5.18.86, under the server's own PHP: the installed InboxReplyRoute decides, with the server's own Inbox settings row
# and data directory — what the Inbox passes it — which number each kind of chat is answered from; and the installed
# EvolutionApiService, built from that row by forStore(), names a number for sales and account with the registry dark.
# Pure: no database handle is passed to either (the registry is never consulted), nothing is sent, nothing written; the
# row is read READ-ONLY, as its owner. Prints routes and yes/no only — never an address, a key or an instance name.
R9_OUT="?"
[ -n "$DB_OWNER" ] && R9_OUT="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
  $r = $argv[1]; $pdd = $argv[2];
  try {
    require_once $r . "/lib/InboxReplyRoute.php"; require_once $r . "/lib/EvolutionApiService.php";
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn(); $p = null;
    $c = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $o = [];
    foreach (["sales", "support", "account", "accounts", "web"] as $ch) {
      $d = InboxReplyRoute::decide($ch, $c, $pdd, null);
      $o[] = $ch . ":" . $d["mode"] . ($d["mode"] === "sender" ? "/" . $d["sender"] : "");
    }
    $e = EvolutionApiService::forStore($c, null, $pdd);
    echo implode(" ", $o), " | registry=", $e->registryOn() ? "on" : "off", " sales=", $e->instanceFor("sales") !== "" ? "yes" : "no",
         " account=", $e->instanceFor("account") !== "" ? "yes" : "no";
  } catch (Throwable $e) { echo "?", get_class($e); }
' "$IN_CONTAINER" "$PDD_IN" 2>&1)"
if [ "$R9_OUT" = "sales:channel support:sender/support account:channel accounts:sender/accounts web:sender/support | registry=off sales=yes account=yes" ]; then
  ok "R9 the installed Inbox route, on the server's own Inbox settings: a sales chat → the sales number, an account chat → the account number, support / accounts / web → sendVia as before; the registry dark — nothing sent, nothing written"
else bad "R9 the installed Inbox route answered: $(printf '%s' "$R9_OUT" | head -c 300) (expected sales → its number, account → its number, the rest as before)"; fi

# R10 — 5.18.87's lead path, with 5.18.88's event processor: what it will do with the switches the workers load. Both services judge their switch
# with filter_var(…, FILTER_VALIDATE_BOOLEAN) over the configuration run_worker.php loads — the files column A4 and V3e
# read; a switched-off sync is a decision the uCRM worker settles, never a failure it retries. Read-only, nothing written.
R10_BAD=""
grep -qF "return filter_var(\$this->config['$LC_KEY'] ?? false, FILTER_VALIDATE_BOOLEAN);" "$DEST/lib/AiLeadService.php" 2>/dev/null || R10_BAD="$R10_BAD AiLeadService:switch-rule"
grep -qF "return filter_var(\$this->config['$LS_KEY'] ?? false, FILTER_VALIDATE_BOOLEAN);" "$DEST/lib/UcrmLeadSync.php" 2>/dev/null || R10_BAD="$R10_BAD UcrmLeadSync:switch-rule"
grep -qF "if (in_array(\$r['action'], ['disabled', 'skipped'], true)) {" "$DEST/workers/UcrmLeadWorker.php" 2>/dev/null || R10_BAD="$R10_BAD UcrmLeadWorker:switched-off-retried"
# S7: with the registry off a lead is 5.18.87's, field for field — the worker hands no origin, AiLeadService stores none,
# and the sync event names no channel.
grep -qF '        if (!$this->evo->registryOn()) return null;' "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R10_BAD="$R10_BAD AiReplyWorker:origin-with-the-registry-off"
grep -qF "if (\$origin === null || trim((string)(\$origin['channel_id'] ?? '')) === '') return [];" "$DEST/lib/AiLeadService.php" 2>/dev/null || R10_BAD="$R10_BAD AiLeadService:origin-rule"
grep -qF "if (\$this->evo->registryOn()) \$syncPayload['channel_id'] = \$channel;" "$DEST/workers/AiReplyWorker.php" 2>/dev/null || R10_BAD="$R10_BAD AiReplyWorker:sync-payload"
case "${LC_AFTER#*=}" in on/*) R10_LC="records a qualified enquiry as a lead in Sales -> Leads" ;; *) R10_LC="records NO lead ($LC_KEY is off in the files the workers read)" ;; esac
case "${LS_AFTER#*=}" in
  on/*) R10_LS="creates or links a lead client in uCRM for each lead's sync it receives — and on Uganda the event processor now leaves every sync to it (docs/65 §Z.5)" ;;
  *) R10_LS="settles a lead's sync as \"not synced — switched off\": nothing reaches uCRM" ;;
esac
case "${QU_AFTER#*=}" in
  on/*) case "${SA_AFTER#*=}" in on/*) R10_WHERE="on the sales number and on the support/account number" ;; *) R10_WHERE="on the sales number" ;; esac ;;
  *) R10_WHERE="nowhere while $QU_KEY is off" ;;
esac
if [ -z "$R10_BAD" ]; then ok "R10 the installed lead path, as the workers will act on this server's switches: the assistant is asked to record leads $R10_WHERE; AiLeadService $R10_LC; UcrmLeadWorker $R10_LS. S7: with the registry off a lead carries no channel fields and its sync names no channel — 5.18.87's lead, field for field — read-only, nothing written"
else bad "R10 the installed lead path is not as $EXPECTED_VERSION has it:$R10_BAD"; fi

# R11 — S4/S5: the registry is dark. Its switch reads OFF in both copies (V3f); the installed ChannelRegistry::enabled()
# says off for both; forStore() — the webhook's, the workers' and the Inbox's way to Evolution — says off for both; and the
# Inbox's route for every kind of chat runs too: all of it handed a database handle that records every statement, of which
# none may name the registry's tables. 087 holds the three department rows alone with no instance (R3): no Evolution
# instance was added anywhere. The shipped read-only CLI says the same; only its three status lines are kept — the rest
# names the instances and is never printed.
REG_NOW="$(reg_state "$IN_CONTAINER")"
CLI_OUT="?"; [ -n "$DB_OWNER" ] && CLI_OUT="$(docker exec -u "$DB_OWNER" -e "DN_DATA_DIR=$PDD_IN" "$CONTAINER" php "$IN_CONTAINER/tools/channels.php" 2>&1 </dev/null)"
CLI_SAYS="$(printf '%s\n' "$CLI_OUT" | grep -cE "^ +$MN_FLAG +OFF")$(printf '%s\n' "$CLI_OUT" | grep -cE '^ +registry in effect +no')$(printf '%s\n' "$CLI_OUT" | grep -cE '^ +registry installed \(migration 087\) +yes')"
CLI_OUT=""
WA87_R="$(wa87_state)"
R11_BAD=""
[ "$REG_NOW" = "enabled=off/off forStore=off/off statements=0 registry-reads=0" ] || R11_BAD="$R11_BAD $REG_NOW"
sw_off "$MN_AFTER" || R11_BAD="$R11_BAD switch:$MN_AFTER"
case "$WA87_R" in
  *" rows=3:3 seed=ok other=0 "*) [ "$CLI_SAYS" = "111" ] || R11_BAD="$R11_BAD channels.php:$CLI_SAYS" ;;
  "mig=absent "*) [ "$CLI_SAYS" = "110" ] || R11_BAD="$R11_BAD channels.php:$CLI_SAYS" ;;
  *) R11_BAD="$R11_BAD wa_channels:$(printf '%s' "$WA87_R" | sed -E 's/.* (rows=[^ ]* seed=[^ ]* other=[^ ]*) .*/\1/')" ;;
esac
if [ -z "$R11_BAD" ]; then ok "R11 S4/S5 the registry is dark: its switch OFF in both copies; ChannelRegistry::enabled() and forStore() off for both; the Inbox's route for every kind of chat — 0 statements, none on the registry's tables; 087 holds the three department rows alone, no instance stored, so no Evolution instance was added; tools/channels.php says OFF, not in effect, installed"
else bad "R11 the registry is not dark, or not as 087 seeds it:$R11_BAD"; fi

# R12 — S1–S3: the three department numbers, as the installed code routes them now, against 5.18.87's code on the same
# configuration at the same moment — and, after a deploy, against stage A's reading by the live code: the instance each
# number sends from and the channel each number's inbound lands on, identical; the registry off.
MAP_R_F="$(map_state "$IN_CONTAINER" files)"; MAP_R_S="$(map_state "$IN_CONTAINER" store)"
if [ -n "$CHK_OK" ]; then MAP_B_F="$(map_state "$BASE_ROOT" files)"; MAP_B_S="$(map_state "$BASE_ROOT" store)"; else MAP_B_F="?nocode"; MAP_B_S="?nocode"; fi
R12_BAD=""
case "$MAP_R_F $MAP_R_S" in "?"*|*" ?"*) R12_BAD="$R12_BAD unreadable:$MAP_R_F/$MAP_R_S" ;; esac
{ [ "$MAP_R_F" = "$MAP_B_F" ] && [ "$MAP_R_S" = "$MAP_B_S" ]; } || R12_BAD="$R12_BAD differs-from-$BASELINE_VERSION's-code"
if [ -n "$BK" ]; then { [ "$MAP_R_F" = "$MAP_A_F" ] && [ "$MAP_R_S" = "$MAP_A_S" ]; } || R12_BAD="$R12_BAD changed-since-stage-A"; fi
case "$MAP_R_F$MAP_R_S" in *" registry=on"*) R12_BAD="$R12_BAD registry-on" ;; esac
if [ -z "$R12_BAD" ]; then ok "R12 S1–S3 the three numbers route exactly as on $BASELINE_VERSION — the installed code and $BASELINE_VERSION's, on this server's own configuration: ${MAP_R_F%%|*} (the files the webhook and the workers read); the Inbox's row: ${MAP_R_S%%|*} — instance names compared, never printed"
else bad "R12 the three numbers do not route as on $BASELINE_VERSION:$R12_BAD (installed: ${MAP_R_F%%|*} / ${MAP_R_S%%|*}; $BASELINE_VERSION's code: ${MAP_B_F%%|*} / ${MAP_B_S%%|*})"; fi

# R13 — S8: the event processor as installed, run once as a Uganda and once as a South Sudan install on throwaway databases
# in the container's /tmp, as in A6: on Uganda the workers' events are left to them; South Sudan's run must be exactly
# 5.18.87's (and 5.18.87's own code, run beside it, must read so — the control). Then the plugin's own queue, read-only:
# every event not done at the backup's snapshot is still there.
UG_NOW="$(ep_run "$IN_CONTAINER" uganda)"; SS_NOW="$(ep_run "$IN_CONTAINER" south-sudan)"
if [ -n "$CHK_OK" ]; then SS_BASE="$(ep_run "$BASE_ROOT" south-sudan)"; else SS_BASE="?nocode"; fi
R13_BAD=""
[ "$UG_NOW" = "$UG_SIGNATURE" ] || R13_BAD="$R13_BAD uganda:$UG_NOW"
[ "$SS_NOW" = "$SS_SIGNATURE" ] || R13_BAD="$R13_BAD south-sudan:$SS_NOW"
[ "$SS_BASE" = "$SS_SIGNATURE" ] || R13_BAD="$R13_BAD control($BASELINE_VERSION):$SS_BASE"
grep -qF "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];" "$DEST/cron/event_processor.php" 2>/dev/null || R13_BAD="$R13_BAD lists"
if [ -z "$R13_BAD" ]; then ok "R13 S8 the installed event processor, on throwaway databases: on Uganda it leaves ai.reply and crm.lead.sync to their workers and settles wa.escalation as known ($UG_NOW); as a South Sudan install it does exactly what $BASELINE_VERSION's does ($SS_NOW) — nothing sent, nothing of the plugin's touched"
else bad "R13 the installed event processor does not behave as $EXPECTED_VERSION's must:$R13_BAD"; fi
EQ_TSV=""; if [ -n "$BK" ]; then EQ_TSV="$BK/event-queue.tsv"; else EQ_TSV="$(sed -n 's/^EQ_FILE=//p' "$STATE" 2>/dev/null | head -1)"; fi
if [ -n "$EQ_TSV" ] && [ -f "$EQ_TSV" ]; then
  EQ_KEPT="$(cut -f1 "$EQ_TSV" | evq_check)"
  case "$EQ_KEPT" in
    "kept="*" missing=-") ok "R13 the plugin's own event queue lost nothing: $EQ_KEPT of the events not done at the snapshot are still there (now: $(evq summary); read-only)" ;;
    *) bad "R13 events that were not done at the snapshot are GONE from the queue ($EQ_KEPT) — only done events are ever pruned" ;;
  esac
else note "R13 no event-queue snapshot to compare with (this run took no backup and the state file names none) — the queue now: $(evq summary)"; fi

# R14 — Domain B, installed, byte for byte.
check_domainb "$EXPECTED_PLUGIN_COMMIT" "R14"
elif [ "$MODE" = "rollback" ]; then
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB the installed manifest says $IV" || bad "RB the installed manifest says ${IV:-?}, expected $BASELINE_VERSION"
RB_REF=""
grep -qF '_epWorkerOwned' "$DEST/cron/event_processor.php" 2>/dev/null && RB_REF="$RB_REF event_processor.php"
grep -qF 'EvolutionApiService::forStore(' "$DEST/evo_webhook.php" 2>/dev/null && RB_REF="$RB_REF evo_webhook.php"
grep -qF 'replyRoleOrRefuse' "$DEST/workers/AiReplyWorker.php" 2>/dev/null && RB_REF="$RB_REF AiReplyWorker.php"
grep -qF 'array $excludeTypes = []' "$DEST/lib/EventBus.php" 2>/dev/null && RB_REF="$RB_REF EventBus.php"
grep -qF 'ORIGIN_FIELDS' "$DEST/lib/AiLeadService.php" 2>/dev/null && RB_REF="$RB_REF AiLeadService.php"
grep -qF 'EvolutionApiService::forStore(' "$DEST/cron/followup_send.php" 2>/dev/null && RB_REF="$RB_REF followup_send.php"
grep -qF "'$MN_FLAG' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null && RB_REF="$RB_REF set_config.php"
# …and 5.18.87's Batch 0, which the rollback keeps: the capture's pin from the context, the uCRM worker's payloadOf().
[ "$(grep -cF '$this->latestPin($convId, $context));' "$DEST/workers/AiReplyWorker.php" 2>/dev/null || true)" = "1" ] || RB_REF="$RB_REF batch0:capture-pin"
grep -qF 'private static function payloadOf(array $event): array' "$DEST/workers/UcrmLeadWorker.php" 2>/dev/null || RB_REF="$RB_REF batch0:payloadOf"
[ -z "$RB_REF" ] && ok "RB the installed plugin is $BASELINE_VERSION's again: Batch 1's routing and the event processor's change are gone, and 5.18.87's lead fixes are there — a qualified enquiry is still recorded as a lead" || bad "RB the installed plugin is not $BASELINE_VERSION's:$RB_REF"
# 087 stays — the rollback restores code only — and 5.18.87 does not read it with the switch off (V3f: still off).
WA87_RB="$(wa87_state)"; REG_RB="$(reg_state "$IN_CONTAINER")"
case "$WA87_RB" in
  "mig=applied:"*) RB87="087's tables stay in plugin.sqlite3, as the plugin applied them" ;;
  "mig=absent "*)  RB87="087 was not applied before the rollback (no request reached $EXPECTED_VERSION); its file stays on disk, so $BASELINE_VERSION's runner — the same code — applies it on the plugin's next request, additive" ;;
  *)               RB87="087's state could not be read ($WA87_RB)" ;;
esac
if [ "$REG_RB" = "enabled=off/off forStore=off/off statements=0 registry-reads=0" ] && sw_off "${MN_AFTER:-}"; then
  ok "RB $RB87; $BASELINE_VERSION does not read them with the switch off — ChannelRegistry::enabled() and forStore() off for both copies, the Inbox's route for every kind of chat: 0 statements on them; the switch stays OFF (${MN_AFTER:-?})"
else bad "RB $BASELINE_VERSION reads the registry, or its switch is not off: $REG_RB; switch ${MN_AFTER:-?}"; fi
# The event processor is 5.18.87's again, on Uganda and South Sudan alike.
EP_RB_UG="$(ep_run "$IN_CONTAINER" uganda)"; EP_RB_SS="$(ep_run "$IN_CONTAINER" south-sudan)"
{ [ "$EP_RB_UG" = "$SS_SIGNATURE" ] && [ "$EP_RB_SS" = "$SS_SIGNATURE" ]; } && ok "RB the event processor is $BASELINE_VERSION's again, on Uganda and South Sudan alike ($EP_RB_SS)" || bad "RB the installed event processor is not $BASELINE_VERSION's: Uganda $EP_RB_UG; South Sudan $EP_RB_SS"
# The three numbers route exactly as before the rollback.
MAP_RB_F="$(map_state "$IN_CONTAINER" files)"; MAP_RB_S="$(map_state "$IN_CONTAINER" store)"
{ [ "$MAP_RB_F" = "$MAP_A_F" ] && [ "$MAP_RB_S" = "$MAP_A_S" ] && [ "${MAP_RB_F#\?}" = "$MAP_RB_F" ]; } && ok "RB the three numbers route exactly as before the rollback: ${MAP_RB_F%%|*}; the Inbox's row: ${MAP_RB_S%%|*} (instance names compared, never printed)" || bad "RB the three numbers' routing changed across the rollback: ${MAP_A_F%%|*} → ${MAP_RB_F%%|*}; the row ${MAP_A_S%%|*} → ${MAP_RB_S%%|*}"
# The event queue lost nothing across the rollback.
if [ -n "$BK" ] && [ -f "$BK/event-queue.tsv" ]; then
  EQ_KEPT="$(cut -f1 "$BK/event-queue.tsv" | evq_check)"
  case "$EQ_KEPT" in "kept="*" missing=-") ok "RB the event queue lost nothing across the rollback ($EQ_KEPT)" ;; *) bad "RB events are GONE from the queue across the rollback ($EQ_KEPT)" ;; esac
fi
check_domainb "$BASELINE_COMMIT" "RB"
# 5.18.86's Inbox route is live on this server: the rollback must leave it whole.
RBI=""
for f in "$IR_LIB" lib/ChannelRegistry.php lib/ChannelContext.php; do [ -f "$DEST/$f" ] || RBI="$RBI $f:missing"; done
N_IR="$(grep -cF "InboxReplyRoute::decide((string)(\$conv['channel'] ?? ''), (array)\$config, \$dataDir, \$store->getPdo());" "$DEST/includes/api/api_whatsapp.php" 2>/dev/null || true)"; N_IR="${N_IR:-0}"
[ "$N_IR" = "4" ] || RBI="$RBI inbox:route×$N_IR(want 4)"
grep -qF 'public function sendOnChannel(string $channel, string $toPhone, string $text, string $event,' "$DEST/lib/NotificationService.php" 2>/dev/null || RBI="$RBI notify:no-channel-send"
grep -qF 'public static function forStore(array $config, ?\PDO $pdo, ?string $dataDir, int $timeout = 20): self' "$DEST/lib/EvolutionApiService.php" 2>/dev/null || RBI="$RBI evolution:no-forStore"
[ -z "$RBI" ] && ok "RB 5.18.86's Inbox route is whole: all four Inbox sends through it, the channel send, forStore()" || bad "RB 5.18.86's Inbox route is incomplete after the rollback:$RBI"
# 5.18.85's request form from the quotation is live on this server: the rollback must leave it whole.
RBQ=""
[ -f "$DEST/$QP_LIB" ] || RBQ="$RBQ reader"
grep -qF "\$iaQList = \$iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => \$cid]));" "$DEST/includes/api/api_install_auth.php" 2>/dev/null || RBQ="$RBQ prefill"
grep -qF 'Filled from quotation <b>' "$DEST/tabs/support/scheduling.php" 2>/dev/null || RBQ="$RBQ form-note"
grep -qF "\$_whTech = \$_whJobNotifier ? whInstallTechName(\$assignedUserId, \$store, \$crm) : '';" "$DEST/webhook.php" 2>/dev/null || RBQ="$RBQ technician-name"
[ -z "$RBQ" ] && ok "RB 5.18.85's request form from the quotation and the technician's name are whole" || bad "RB 5.18.85's quotation form is incomplete after the rollback:$RBQ"
# 5.18.84's booking WhatsApp is live on this server: the rollback must leave it whole.
RBW=""
[ -f "$DEST/$WA_LIB" ] || RBW="$RBW class"
grep -qF "public const FLAG  = 'customer_wa_install_scheduled';" "$DEST/$WA_LIB" 2>/dev/null || RBW="$RBW switch"
grep -qF '        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {' "$DEST/$WA_LIB" 2>/dev/null || RBW="$RBW claim"
grep -qF '            whInstallScheduledWhatsApp($jobId, is_array($job) ? $job : [], is_array($client ?? null) ? $client : [],' "$DEST/webhook.php" 2>/dev/null || RBW="$RBW call"
grep -qF "'customer_wa_install_scheduled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || RBW="$RBW setting"
[ -z "$RBW" ] && ok "RB 5.18.84's booking WhatsApp is whole: the class with its switch and once-per-job claim, its call in job.add, its setting" || bad "RB 5.18.84's booking WhatsApp is incomplete after the rollback:$RBW"
# 5.18.83's authorisation is live on this server: the rollback must leave it whole.
RBA=""
grep -qF 'public static function decision(\PDO $pdo, array $config, array $job): array' "$DEST/lib/InstallAuth.php" 2>/dev/null || RBA="$RBA one-rule"
grep -qF "if (\$page === 'install_auth') {" "$DEST/public.php" 2>/dev/null || RBA="$RBA route"
grep -qF 'if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {' "$DEST/tabs/customer_app/install_auth_page.php" 2>/dev/null || RBA="$RBA page-gate"
grep -qF "\$job, \$statusInt, 'accept', (int)(\$_sjMe['id'] ?? 0));" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || RBA="$RBA guard:accept"
grep -qF "\$job, 2, 'complete', \$rid);" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || RBA="$RBA guard:complete"
grep -qF "\$_iaJob, 1, 'checkin', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || RBA="$RBA guard:checkin"
grep -qF "\$_iaJob, 2, 'checkout', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || RBA="$RBA guard:checkout"
grep -qF "public const NEVER_QUEUED = ['app_otp', 'ops_install_auth_request'];" "$DEST/lib/NotificationService.php" 2>/dev/null || RBA="$RBA never-queued"
grep -qF "'install_auth_enabled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || RBA="$RBA set-config:switch"
[ -z "$RBA" ] && ok "RB 5.18.83's Customer Installation Authorisation is whole: the one rule, the page behind its gate, the four guards, the link never queued, its switch in the tool" || bad "RB 5.18.83's authorisation is incomplete after the rollback:$RBA"
check_086 "RB"
grep -qF 'public function dataReportHandoff(): bool' "$DEST/lib/TenantProfile.php" 2>/dev/null && grep -qF '>How to pay</div>' "$DEST/tabs/customer_app/portal.php" 2>/dev/null && ok "RB the 5.18.74 hand-off setting and \"How to pay\" are still there" || bad "RB the installed plugin lost 5.18.74 code"
grep -qF "'Still to account for'" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null && ok "RB the 5.18.72 Staff Cashbooks wording is still there" || bad "RB the installed plugin lost 5.18.72 code"
grep -qF 'Cash in hand — per currency' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null && grep -qF '== cash in hand (5.18.71)' "$DEST/$CIH_TOOL" 2>/dev/null && ok "RB the 5.18.71 landing hero and cash-in-hand tool are still there" || bad "RB the installed plugin lost 5.18.71 code"
grep -qF 'var _fr3Base' "$DEST/tabs/sales/wallet.php" 2>/dev/null && grep -qF '(5.18.70)' "$DEST/$RECORDS_TOOL" 2>/dev/null && grep -qF "'currency'        => \$manCur," "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null && ok "RB the 5.18.70 Field Register fix and records tool and the 5.18.69 Manual Entry stamp are still there" || bad "RB the installed plugin lost 5.18.69/5.18.70 code"
grep -qF 'CASH IN HAND</div>' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null && grep -qF "dn_book_base(null)" "$DEST/lib/StaffCashPositionService.php" 2>/dev/null && ok "RB the 5.18.68 staff-cash chain and card are still there" || bad "RB the installed plugin lost 5.18.68 code"
grep -qF 'var UG_PHOTOS' "$DEST/tabs/support/scheduling.php" 2>/dev/null && grep -qF -- "$LAYOUT_MARK" "$DEST/tabs/support/scheduling.php" 2>/dev/null && ok "RB the 5.18.66 photo surface and the 5.18.67 card are still there" || bad "RB the installed job page lost 5.18.66/5.18.67 code"
note "RB the code is $BASELINE_VERSION again. The rollback restores code only. Files $EXPECTED_VERSION added stay on disk, reached by nothing (deploy-hybrid.sh never deletes): $(printf '%s ' $EXPECTED_ADDED) — 087's file among them, already recorded wherever it was applied. Its tables stay too, read by nothing while the switch is off. Leads, conversations and events stay as they are"
fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
LIVE_NOW="$(live_commit)"
case "$MODE" in
  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"; echo "  deploy again      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh" ;;
  *)        echo "  deployed commit   ${LIVE_NOW:-?}  (plugin $EXPECTED_VERSION, the release commit on $RELEASE_BRANCH)" ;;
esac
[ -n "$BK" ] && echo "  backup            $BK"
if [ "$MODE" != "rollback" ]; then
  echo "  what changed      Uganda's event processor leaves ai.reply and crm.lead.sync to their workers and settles wa.escalation as a known type — a lead's uCRM sync can no longer be acknowledged as an unknown type before the uCRM worker sees it (docs/65 §Z.5); migration 087's channel registry, dark — the three department numbers' rows, no instance stored; Batch 1's routing by channel, shipped OFF"
  echo "  the switches      $MN_FLAG ${MN_AFTER#mn=} (files/store) — OFF: the registry is dark (A5, V3f, R11); $LC_KEY ${LC_AFTER#lc=}, $LS_KEY ${LS_AFTER#ls=}, $QU_KEY ${QU_AFTER#qu=}, $SA_KEY ${SA_AFTER#sa=}; install_auth_enabled ${IA_AFTER#ia=}, $WA_KEY ${WA_AFTER#wa=} — as the operator left them (V3b–V3e, R2)"
  echo "  try it            nothing to switch on: every number routes as before (R12), and a lead's sync is now left to the uCRM worker, which settles it \"not synced — switched off\" while the uCRM write is off (R10, R13)"
  echo "  what did not      South Sudan (its event loop exactly $BASELINE_VERSION's: A6, R13); Domain B (A7, R14); the three numbers' routing (A9, R12); the Inbox (5.18.86's route, R9); the AI's leads (5.18.87's path, R10); every configuration value and AI setting (V3–V3g — this run switches nothing); no Evolution instance created, paired or called, no message sent; the authorisation, the booking WhatsApp and the quotation form (R6, R3, R8); every cash record (R7 only reads); the photos (R7: they may only grow); the event queue (R13: nothing lost); the undeployed work on the branch — the partner portal, the CSRF guard, the AI media layer (not in this release, A0/R4)"
  echo "  migrations        one, 087 — two tables, three indexes, three triggers, the three department rows with no instance stored, applied by the plugin's own runner (R3); 086 still complete"
  echo "  later             cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"
fi
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file; do not roll back on your own unless staff or customers are affected."; fi
if [ "$MODE" != "rollback" ]; then
  echo
  echo "  Only if it is ever needed — its own command, never pasted together with the deploy (root docs/44 §16.9) —"
  echo "  the rollback to $BASELINE_VERSION ($BASELINE_COMMIT) asks you to type ROLLBACK before it changes anything. It puts back"
  echo "  5.18.87, whose event processor can take a lead's sync again; 087's tables stay, unread; every switch stays as it is:"
  echo "      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback"
  echo "  or by hand, if this script cannot run:"
  echo "      cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"
fi
[ "$FAIL" = "0" ]
}

main "$@"; exit $?
