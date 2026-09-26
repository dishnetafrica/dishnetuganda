#!/usr/bin/env bash
#
# journey-audit.sh — the customer-journey and three-plugin synchronisation audit, on the live Uganda
# install. READ-ONLY: it deploys nothing, changes no configuration, touches no customer record, syncs
# nothing, unsticks nothing. The only writes are the rows the plugin itself writes when the operator's
# OWN customer record signs in (one pending code, one session that is logged out, audit rows), and a
# small fingerprint file under /root/dnb-verify.
#
# Run as root on the server, one mode at a time, and send back THE LOG FILE:
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify \
#     && bash scripts/journey-audit.sh <mode> 2>&1 | tee /root/dnb-verify/journey-<mode>-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Modes (exactly one):
#   --siblings              dishnet-data-report and dishnet-starlink-finance: PHP inventory, data-file
#                           METADATA (names, sizes, ages, record counts, field names), the auto-sync
#                           lock (main.lock) and what its code does with it, the unstick handler, the
#                           "Needs Sync" rule and which kits it flags, uCRM calls, writes towards uCRM,
#                           Starlink hosts, cross-plugin references, kit↔customer fields, JSON writers.
#   --identity <clientId>   one customer across the four systems: the hybrid store (a copy), uCRM live
#                           (GET only, through the plugin's own client), Finance's files, Data Report's
#                           files; then a field-by-field comparison with the source of truth per field.
#                           Personal identifiers are masked; CRM ids and amounts are the operator's own.
#   --login-phone <+2567…>  one controlled sign-in by WhatsApp/phone (the code is typed here, never
#   --login-email <address> printed), then every customer screen through the API and the portal pages,
#                           classified WORKING / PARTIAL / BROKEN / NOT IMPLEMENTED / N-A with the data
#                           source each reads; branding; an identity fingerprint saved for --compare;
#                           logout and revocation; the code and cookie searched for in the container log.
#   --compare               phone login = e-mail login = the same customer? (from the two fingerprints)
#
# What it never does: print a token, code, key, secret, password or cookie; send anything to anyone
# but the record named; accept terms on a customer's behalf; sync, unstick, delete, rebuild, deploy.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
SIBLING="dishnet-data-report"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JOURNEY_OUT="${JOURNEY_OUT:-/root/dnb-verify}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SNIP="$(mktemp -d)"; trap 'rm -rf "$SNIP"' EXIT

MODE=""; ARG=""
case "${1:-}" in
  --siblings|--compare) MODE="${1#--}" ;;
  --identity|--login-phone|--login-email) MODE="${1#--}"; ARG="${2:-}"; [ -n "$ARG" ] || { echo "usage: $0 $1 <value>" >&2; exit 64; } ;;
  *) echo "usage: $0 --siblings | --identity <clientId> | --login-phone <+2567…> | --login-email <address> | --compare" >&2; exit 64 ;;
esac

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }
# Anything that looks like a key, token or hash is masked before it can reach the log.
mask() { sed -E 's/[A-Za-z0-9+\/=_-]{24,}/<redacted>/g'; }
# The operator types the one-time code here. Never echoed. (A rehearsal replaces this function.)
ask_code() { local c; printf '  Type the 6-digit code you received (not shown), or press Enter if none arrived: ' >&2; read -rs c </dev/tty || c=""; echo >&2; printf '%s' "$c" | tr -cd '0-9'; }

HTTP_CODE=""; HTTP_BODY=""; HTTP_HEADERS=""
http() {
  local m="$1" u="$2" b="$3"; shift 3
  local hdrs=(); local h; for h in "$@"; do hdrs+=(-H "$h"); done
  local args=(-sS -o "/tmp/dnv_body.$$" -D "/tmp/dnv_hdr.$$" -w '%{http_code}' --max-time 30 -X "$m" -H 'Content-Type: application/json')
  [ -n "$b" ] && args+=(--data "$b")
  local r; r="$(curl "${args[@]}" ${hdrs[@]+"${hdrs[@]}"} "$u" 2>/dev/null)" || r="000"
  HTTP_CODE="$r"; HTTP_BODY="$(head -c 200000 "/tmp/dnv_body.$$" 2>/dev/null | tr -d '\000' || true)"
  HTTP_HEADERS="$(head -c 8000 "/tmp/dnv_hdr.$$" 2>/dev/null || true)"
  rm -f "/tmp/dnv_body.$$" "/tmp/dnv_hdr.$$"
}
jfield() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed -E "s/^\"$2\":\"//; s/\"$//"; }
jnum()   { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed -E "s/^\"$2\"://"; }

hdr "Customer journey audit — $MODE — $TS — $(hostname)"

# ── The container, the plugin, its data directory and public address (the deploy scripts' rule) ──
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
PLUGINS_IN="/data/ucrm/data/plugins"
LIVE="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
echo "  live plugin     ${LIVE:-unknown}   repo HEAD $(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo ?)   last plugin commit $(git -C "$REPO" log -1 --format=%h -- "$PLUGIN" 2>/dev/null || echo ?)"
docker exec "$CONTAINER" php -v >/dev/null 2>&1 || stop "no php inside the container"
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="$PLUGINS_IN/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
fi
DB_IN="$PDD_IN/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB_IN" || stop "no database at $DB_IN"
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB_IN")"
# --- TOOLS BEGIN ---
RO="/tmp/dnv-ro-$$"
ro_copy() {  # $1 name → a read-only copy of the store inside the container, as its owner
  docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB_IN' '$RO/$1.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/$1.sqlite3-wal' || true )" 2>/dev/null
}
cphp() {  # run a PHP snippet from $SNIP inside the container as the store's owner, cwd the plugin; extra -e pairs follow the file
  local f="$1"; shift; docker exec -i -u "$DB_OWNER" -w "$IN_CONTAINER" "$@" "$CONTAINER" php < "$SNIP/$f" 2>/dev/null
}
# --- TOOLS END ---
PLUGIN_BASE="${PLUGIN_BASE:-}"
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){ $over=preg_replace("#/crm$#","",$v); break; } }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
[ -n "$PLUGIN_BASE" ] || stop "could not derive the plugin's public URL — set PLUGIN_BASE=https://<host>/crm/_plugins/$PLUGIN/public.php"
echo "  plugin URL      $PLUGIN_BASE"
echo "  data dir        $PDD_IN (container)"

# --- ADMIN BEGIN ---
# The staff tokens, read from a COPY of the store and never printed.
admin_token() {
  ro_copy tok || return 1
  cat > "$SNIP/tokens.php" <<'PHP'
<?php
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin = ''; $now = time();
foreach ($db->query("SELECT data FROM retailers") as $row) {
    $r = json_decode((string)$row['data'], true); if (!is_array($r)) continue;
    if (empty($r['is_active']) || empty($r['api_token']) || empty($r['is_admin'])) continue;
    $issued = (int)($r['token_issued_at'] ?? 0); if ($issued > 0 && ($now - $issued) > 90 * 86400) continue;
    $admin = (string)$r['api_token']; break;
}
echo $admin === '' ? '-' : $admin, "\n";
PHP
  ADMIN_TOKEN="$(cphp tokens.php -e "RO_DB=$RO/tok.sqlite3" | tail -n1 | tr -d '\r\n')"
  [ "${ADMIN_TOKEN:-}" = "-" ] && ADMIN_TOKEN=""
  [ -n "$ADMIN_TOKEN" ]
}
# --- ADMIN END ---
# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "siblings" ]; then
# --- SIBLINGS BEGIN ---
hdr "J. The two sibling plugins — code and DATA METADATA, the auto-sync lock, the Needs-Sync rule (read-only)"
echo "  Nothing below is a value from a data file: counts, field names, ages, sizes, CRM client ids and masked identifiers only."
cat > "$SNIP/siblings.php" <<'PHP'
<?php
// READ-ONLY. Inventory of the two sibling plugins' code and DATA METADATA, the lock that blocks auto-sync,
// the "Needs Sync" rule, cross-plugin references, uCRM calls and writers. No value from a data file is
// printed except counts, field NAMES, ages, sizes, CRM client ids and masked identifiers.
$PL = rtrim((string)getenv('PLUGINS'), '/'); $NOW = time();
function m(string $s): string {
    $s = preg_replace('/((?:password|passwd|secret|api_?key|app_?key|token|bearer|pepper|cookie|private_?key)\s*(?:=>|=|:)\s*[\'"])([^\'"]{6,})([\'"])/i', '$1<redacted>$3', $s);
    $s = preg_replace_callback('/[A-Za-z0-9+\/=_-]{24,}/', function ($mm) { $r = $mm[0]; if (strlen($r) >= 40) return '<redacted>'; return (preg_match('/\d/', $r) && preg_match('/[A-Za-z]/', $r)) ? '<redacted>' : $r; }, $s);
    $s = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '<email>', $s);
    $s = preg_replace('/(?<![\w.])\d{7,}(?![\w.])/', '<digits>', $s);
    return rtrim($s);
}
function idm(string $v): string { $v = trim($v); if ($v === '') return '—'; if (strlen($v) <= 6) return str_repeat('*', strlen($v)); return substr($v, 0, 3) . '…' . substr($v, -3); }
function age(int $t): string { global $NOW; $d = max(0, $NOW - $t); if ($d < 3600) return floor($d / 60) . ' min'; if ($d < 86400) return round($d / 3600, 1) . ' h'; return round($d / 86400, 1) . ' d'; }
function phpFiles(string $dir): array { $out = []; $skip = ['data', 'vendor', 'node_modules', '.git', 'backups', 'backup', 'logs', 'cache', 'tmp', 'sessions'];
    $walk = function (string $d, int $depth) use (&$walk, &$out, $skip) { $h = @scandir($d); if ($h === false) return; foreach ($h as $n) { if ($n === '.' || $n === '..') continue; $p = "$d/$n"; if (is_dir($p)) { if ($depth < 3 && !in_array($n, $skip, true)) $walk($p, $depth + 1); } elseif (substr($n, -4) === '.php') $out[] = $p; } };
    $walk($dir, 0); sort($out); return $out; }
function grepL(array $L, string $re, int $cap = 40): array { $o = []; foreach ($L as $i => $ln) { if (preg_match($re, $ln)) { $o[] = [$i + 1, $ln]; if (count($o) >= $cap) break; } } return $o; }
function region(array $L, int $a, int $b, string $pfx = '     '): void { $a = max(1, $a); $b = min(count($L), $b); for ($i = $a; $i <= $b; $i++) echo $pfx, sprintf('%5d| ', $i), m($L[$i - 1]), "\n"; }
function jsonMeta(string $f): array { $raw = @file_get_contents($f); $d = json_decode((string)$raw, true);
    if (!is_array($d)) return ['kind' => 'not-json-or-scalar', 'count' => 0, 'keys' => []];
    $isList = array_keys($d) === range(0, count($d) - 1);
    $first = $isList ? ($d[0] ?? null) : ($d[array_key_first($d)] ?? null);
    $keys = is_array($first) ? array_slice(array_keys($first), 0, 10) : ($isList ? [] : array_slice(array_keys($d), 0, 10));
    return ['kind' => $isList ? 'list' : 'object', 'count' => count($d), 'keys' => $keys]; }
function crmIdOf(array $k): string { foreach (['crm_client_id', 'assigned_client_id', 'crm_id', 'client_id', 'clientId', 'customer_id', 'ucrm_client_id'] as $f) { if (isset($k[$f]) && (string)$k[$f] !== '') return (string)$k[$f]; } return ''; }

foreach (['dishnet-data-report', 'dishnet-starlink-finance'] as $S) {
    $dir = "$PL/$S";
    echo "\n══ $S ══\n";
    if (!is_dir($dir)) { echo "@@BAD $S is NOT installed at $dir\n"; continue; }
    $man = @json_decode((string)@file_get_contents("$dir/manifest.json"), true) ?: [];
    echo "  manifest: ", m((string)($man['information']['name'] ?? '?')), ' ', m((string)($man['information']['version'] ?? '?')), " · top-level keys: ", implode(', ', array_keys($man)), "\n";
    foreach (['cron', 'crons', 'schedule', 'execution', 'menu', 'configuration'] as $k) if (isset($man[$k])) echo "  manifest[$k]: ", is_array($man[$k]) ? (count($man[$k]) . ' entries — ' . m(substr(json_encode(array_map(function ($e) { return is_array($e) ? (($e['key'] ?? $e['name'] ?? $e['label'] ?? json_encode(array_keys($e)))) : $e; }, $man[$k])), 0, 300))) : m((string)$man[$k]), "\n";
    $files = phpFiles($dir); $SRC = []; foreach ($files as $f) $SRC[$f] = file($f, FILE_IGNORE_NEW_LINES) ?: [];
    echo "  PHP files: ", count($files), "\n";
    foreach ($SRC as $f => $L) printf("    %-36s %5d lines  %s  modified %s ago\n", ltrim(substr($f, strlen($dir)), '/'), count($L), substr(hash_file('sha256', $f), 0, 12), age((int)filemtime($f)));

    // ── data directories: metadata only ──
    foreach (["$dir/data" => '<plugin>/data', "$PL/.$S-data" => "<plugins>/.$S-data"] as $dd => $label) {
        echo "  ── $label ──\n";
        if (!is_dir($dd)) { echo "    absent\n"; continue; }
        $names = array_values(array_diff(@scandir($dd) ?: [], ['.', '..'])); sort($names);
        foreach ($names as $n) {
            $p = "$dd/$n"; if (is_dir($p)) { echo "    ", m($n), "/ (dir, ", count(array_diff(@scandir($p) ?: [], ['.', '..'])), " entries)\n"; continue; }
            $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION)); $sz = filesize($p); $ag = age((int)filemtime($p));
            if ($ext === 'json') { $jm = jsonMeta($p); printf("    %-36s %8d B  %8s ago  %s×%d  fields: %s\n", m($n), $sz, $ag, $jm['kind'], $jm['count'], $jm['keys'] ? m(implode(',', $jm['keys'])) : '—'); }
            elseif ($ext === 'lock') { $c = (string)@file_get_contents($p); printf("    %-36s %8d B  %8s ago  LOCK — content: %s\n", m($n), $sz, $ag, $sz === 0 ? 'empty' : ($sz <= 80 ? (ctype_digit(trim($c)) ? 'a number (' . strlen(trim($c)) . ' digits' . (strlen(trim($c)) === 10 ? ', a unix time ' . age((int)trim($c)) . ' ago' : '') . ')' : m(trim($c))) : "$sz bytes")); }
            elseif ($ext === 'log') { printf("    %-36s %8d B  %8s ago  LOG\n", m($n), $sz, $ag); }
            else printf("    %-36s %8d B  %8s ago  .%s\n", m($n), $sz, $ag, $ext ?: '(none)');
        }
        // logs: the last lines about locks, crashes, dispatch
        foreach ($names as $n) { $p = "$dd/$n"; if (is_dir($p) || strtolower(pathinfo($n, PATHINFO_EXTENSION)) !== 'log') continue;
            $tail = array_slice(file($p, FILE_IGNORE_NEW_LINES) ?: [], -400); $hits = [];
            foreach ($tail as $ln) if (preg_match('/lock|crash|fatal|error|dispatch|stuck|exception|timeout/i', $ln)) $hits[] = $ln;
            $hits = array_slice($hits, -12);
            if ($hits) { echo "    · last lines of ", m($n), " about lock/crash/dispatch/error (masked, ≤12):\n"; foreach ($hits as $h) echo "        ", m(mb_substr($h, 0, 220)), "\n"; }
        }
    }

    // ── lock handling in code ──
    echo "  ── lock handling in code (lines with .lock / flock / stuck / crashed) ──\n";
    $any = false; $first = null;
    foreach ($SRC as $f => $L) foreach (grepL($L, '/\.lock|flock\s*\(|LOCK_EX|stuck|crashed|dispatch/i', 25) as [$no, $ln]) { $any = true; printf("    %-28s %5d: %s\n", ltrim(substr($f, strlen($dir)), '/'), $no, m($ln)); if ($first === null && preg_match('/main\.lock/', $ln)) $first = [$f, $no]; }
    if (!$any) echo "    (none)\n";
    if ($first) { [$f, $no] = $first; echo "    · ±18 lines around the first main.lock mention (", ltrim(substr($f, strlen($dir)), '/'), ":$no):\n"; region($SRC[$f], $no - 18, $no + 18, '        '); }
    // main.php: the dispatcher (which crons it runs)
    if (isset($SRC["$dir/main.php"])) { $L = $SRC["$dir/main.php"]; echo "  ── main.php: what the tick runs (require/include/exec/shell lines) ──\n"; foreach (grepL($L, '/require|include|exec\s*\(|shell_exec|proc_open|popen|php\s+/i', 30) as [$no, $ln]) printf("    %5d: %s\n", $no, m($ln)); }
    // unstick handler
    foreach ($SRC as $f => $L) { $u = grepL($L, '/[\'"]dr_cron_unstick[\'"]|unstick/i', 3); if ($u) { echo "  ── unstick / reset handling (", ltrim(substr($f, strlen($dir)), '/'), ") — what it deletes or resets ──\n"; $done = []; foreach ($u as [$no, $ln]) { $k = intdiv($no, 40); if (isset($done[$k])) continue; $done[$k] = 1; region($L, $no - 6, $no + 26, '        '); } } }
    // Needs Sync rule
    echo "  ── the 'Needs Sync' / 'no data' rule in templates and code ──\n";
    $shown = 0; foreach ($SRC as $f => $L) { foreach (grepL($L, '/needs?[_ ]?sync|no data|NEEDS SYNC|needsSync/i', 4) as [$no, $ln]) { if ($shown++ >= 4) break 2; echo "    · ", ltrim(substr($f, strlen($dir)), '/'), ":$no\n"; region($L, $no - 8, $no + 8, '        '); } }
    if (!$shown) echo "    (no such words)\n";
    // uCRM calls, writes, Starlink, cross-plugin, kit/customer fields, writers
    echo "  ── uCRM API calls (lines) ──\n"; $c = 0; foreach ($SRC as $f => $L) foreach (grepL($L, '/\/api\/v[0-9.]+\/|X-Auth-App-Key|unms-api|\/crm\/current-user|current-user/', 15) as [$no, $ln]) { if ($c++ >= 30) break 2; printf("    %-28s %5d: %s\n", ltrim(substr($f, strlen($dir)), '/'), $no, m(mb_substr($ln, 0, 200))); } if (!$c) echo "    (none)\n";
    echo "  ── WRITES towards uCRM (a POST/PATCH/PUT/DELETE on an api path) ──\n"; $c = 0; foreach ($SRC as $f => $L) foreach (grepL($L, '/(CUSTOMREQUEST|CURLOPT_POST\b|->post\(|->patch\(|->put\(|->delete\(|[\'"](POST|PATCH|PUT|DELETE)[\'"])/', 40) as [$no, $ln]) { if (!preg_match('/api\/v|clients|services|invoices|payments|custom|attribute/i', implode(' ', array_slice($L, max(0, $no - 4), 8)))) continue; if ($c++ >= 20) break 2; printf("    %-28s %5d: %s\n", ltrim(substr($f, strlen($dir)), '/'), $no, m(mb_substr($ln, 0, 200))); } if (!$c) echo "    (none found — reads only, as far as text can tell)\n";
    echo "  ── Starlink API hosts ──\n"; $hosts = []; foreach ($SRC as $f => $L) foreach ($L as $ln) if (preg_match_all('/https?:\/\/([a-z0-9.-]*starlink\.com)[^\'" ]*/i', $ln, $mm)) foreach ($mm[1] as $h) $hosts[$h] = ($hosts[$h] ?? 0) + 1; echo "    ", $hosts ? implode(', ', array_map(function ($k, $v) { return "$k×$v"; }, array_keys($hosts), $hosts)) : '(none)', "\n";
    echo "  ── other DishNet plugin directories named ──\n"; $c = 0; foreach ($SRC as $f => $L) foreach (grepL($L, '/dishnet-hybrid-|dishnet-starlink-finance|dishnet-data-report|_dishnet_shared/', 20) as [$no, $ln]) { if (preg_match('/dishnet-' . preg_quote($S === 'dishnet-data-report' ? 'data-report' : 'starlink-finance', '/') . '/', $ln) && !preg_match('/dishnet-hybrid|_dishnet_shared|dishnet-' . ($S === 'dishnet-data-report' ? 'starlink-finance' : 'data-report') . '/', $ln)) continue; if ($c++ >= 25) break 2; printf("    %-28s %5d: %s\n", ltrim(substr($f, strlen($dir)), '/'), $no, m(mb_substr($ln, 0, 200))); } if (!$c) echo "    (none)\n";
    echo "  ── kit ↔ customer identity fields used in code (count per file) ──\n";
    foreach ($SRC as $f => $L) { $cnt = []; foreach (['crm_client_id', 'assigned_client_id', 'crm_id', 'clientId', 'customer_name', 'assigned_client_name', 'kit_number', 'router_id_full', 'service_line', 'terminal_id', 'starlink_account', 'account_number'] as $w) { $n = 0; foreach ($L as $ln) $n += substr_count($ln, $w); if ($n) $cnt[] = $w . "×" . $n; } if ($cnt) printf("    %-28s %s\n", ltrim(substr($f, strlen($dir)), '/'), implode(', ', $cnt)); }
    echo "  ── JSON writers (saveJSON / file_put_contents / *Save*) with a literal file name ──\n"; $c = 0; foreach ($SRC as $f => $L) foreach (grepL($L, '/(saveJSON|file_put_contents|drSave\w*|drWifiSave\w*|smSave\w*|writeJson\w*)\s*\(/', 40) as [$no, $ln]) { if ($c++ >= 40) break 2; printf("    %-28s %5d: %s\n", ltrim(substr($f, strlen($dir)), '/'), $no, m(mb_substr($ln, 0, 180))); } if (!$c) echo "    (none)\n";
    if ($S === 'dishnet-starlink-finance') { foreach ($SRC as $f => $L) { $a = grepL($L, '/mode\s*=\s*apply|[\'"]apply[\'"]\s*(===|==)|=== *[\'"]apply[\'"]/', 1); if ($a) { echo "  ── the kit register's apply-mode writer region (", ltrim(substr($f, strlen($dir)), '/'), ":{$a[0][0]}) ──\n"; region($L, $a[0][0] - 10, $a[0][0] + 30, '        '); break; } } }

    // ── DATA: kits, registry, usage, routers, services — ids and masked identifiers only ──
    $dd = is_dir("$PL/.$S-data") && is_file("$PL/.$S-data/sl_kits.json") ? "$PL/.$S-data" : "$dir/data";
    $J = function (string $n) use ($dd) { $d = @json_decode((string)@file_get_contents("$dd/$n"), true); return is_array($d) ? $d : null; };
    if ($S === 'dishnet-starlink-finance') {
        echo "  ── sl_kits.json — THE KIT REGISTER (crm id · kit masked · status · router masked · service line masked · account masked · name initials) ──\n";
        $kits = $J('sl_kits.json'); if ($kits === null) echo "    absent/unreadable\n"; else foreach ($kits as $k) { if (!is_array($k)) continue; $nm = trim((string)($k['assigned_client_name'] ?? $k['customer_name'] ?? $k['customer'] ?? '')); $ini = $nm === '' ? '—' : implode('', array_map(function ($w) { return mb_substr($w, 0, 1); }, preg_split('/\s+/', $nm))) . '.'; printf("    crm %-5s kit %-12s status %-10s router %-12s sl %-12s acc %-12s name %s\n", crmIdOf($k) ?: '—', idm((string)($k['kit_number'] ?? $k['kit'] ?? '')), (string)($k['starlink_account_status'] ?? $k['status'] ?? '—'), idm((string)($k['router_id_full'] ?? $k['router_id'] ?? '')), idm((string)($k['service_line'] ?? '')), idm((string)($k['account_number'] ?? $k['starlink_account'] ?? '')), $ini); }
        foreach (['sl_accounts.json', 'crm_clients_cache.json', 'crm_services_cache.json', 'crm_invoice_export.json', 'sl_usage.json', 'sl_hardware.json'] as $n) { $d = $J($n); if ($d === null) { echo "  $n: absent\n"; continue; } $ids = []; foreach ($d as $r) if (is_array($r)) { $cid = (string)($r['id'] ?? $r['clientId'] ?? crmIdOf($r)); if ($cid !== '') $ids[$cid] = true; } echo "  $n: ", count($d), " records", $n === 'crm_clients_cache.json' ? " (Finance's OWN copy of uCRM clients; distinct ids " . count($ids) . ")" : ($n === 'crm_services_cache.json' ? " (distinct clientIds " . count($ids) . ")" : ''), "\n"; }
    } else {
        echo "  ── dr_kit_registry.json (generated snapshot) — kits: crm id · kit masked · account masked · has usage rows ──\n";
        $reg = $J('dr_kit_registry.json'); $usage = $J('sl_usage.json') ?: []; $usedKits = []; foreach ($usage as $u) if (is_array($u)) $usedKits[(string)($u['kit_number'] ?? '')] = ($usedKits[(string)($u['kit_number'] ?? '')] ?? 0) + 1;
        if ($reg === null) echo "    absent\n"; else { $kits = $reg['kits'] ?? $reg; foreach ((array)$kits as $key => $k) { if (!is_array($k)) continue; $kn = (string)($k['kit_number'] ?? $key); printf("    crm %-5s kit %-12s acc %-12s sl %-12s usage rows %d\n", crmIdOf($k) ?: '—', idm($kn), idm((string)($k['account_number'] ?? $k['account'] ?? '')), idm((string)($k['service_line'] ?? '')), $usedKits[$kn] ?? 0); } echo "    envelope: generated_at ", m((string)($reg['generated_at'] ?? '—')), ", generator ", m((string)($reg['generator'] ?? '—')), "\n"; }
        echo "  ── sl_usage.json: ", count($usage), " rows; kits with rows: ", count($usedKits), "\n";
        $svc = $J('sl_svc_cache.json'); if ($svc === null) echo "  sl_svc_cache.json: absent\n"; else { $emptyKit = 0; $act = ['true' => 0, 'false' => 0, 'null' => 0]; $crm = 0; foreach ($svc as $r) { if (!is_array($r)) continue; if (trim((string)($r['kit_number'] ?? '')) === '') $emptyKit++; $a = $r['subscription_active'] ?? null; $act[$a === null ? 'null' : ($a ? 'true' : 'false')]++; if (crmIdOf($r) !== '') $crm++; } echo "  sl_svc_cache.json: ", count($svc), " service lines; kit_number empty: $emptyKit; subscription_active true/false/null: {$act['true']}/{$act['false']}/{$act['null']}; with a CRM id: $crm\n"; }
        $rm = $J('wifi_router_map.json'); if ($rm === null) echo "  wifi_router_map.json: absent\n"; else { echo "  wifi_router_map.json: ", count($rm), " routers"; $withCust = 0; foreach ($rm as $r) if (is_array($r) && crmIdOf($r) !== '') $withCust++; echo "; with a customer/crm id: $withCust\n"; foreach ($rm as $rid => $r) if (is_array($r)) printf("    router %-12s crm %-5s kit %-12s sl %-12s\n", idm((string)$rid), crmIdOf($r) ?: '—', idm((string)($r['kit'] ?? $r['kit_number'] ?? '')), idm((string)($r['sl'] ?? $r['service_line'] ?? ''))); }
        $acc = $J('dr_accounts.json'); echo "  dr_accounts.json: ", $acc === null ? 'absent' : count($acc) . " accounts (holds session material — never opened beyond the count)", "\n";
        foreach (['dr_plan_cache.json', 'dr_orders.json', 'wifi_test_block_state.json', 'sl_sync_settings.json'] as $n) { $d = $J($n); echo "  $n: ", $d === null ? 'absent' : count($d) . ' entries', "\n"; }
        // needs-sync heuristic: registry kits with zero usage rows
        if ($reg !== null) { $ns = []; foreach ((array)($reg['kits'] ?? $reg) as $key => $k) { if (!is_array($k)) continue; $kn = (string)($k['kit_number'] ?? $key); if (($usedKits[$kn] ?? 0) === 0) $ns[] = idm($kn) . ' (crm ' . (crmIdOf($k) ?: '—') . ')'; } echo "@@NOTE J2 kits in the registry with NO usage rows (the likely 'Needs Sync · Active · no data' set): ", count($ns), $ns ? ' — ' . implode(', ', $ns) : '', "\n"; }
        $lock = null; foreach (["$dir/data/main.lock", "$dir/main.lock", "$PL/.$S-data/main.lock"] as $lp) if (is_file($lp)) { $lock = $lp; break; }
        if ($lock) { $c = trim((string)@file_get_contents($lock)); echo "@@NOTE J1 main.lock EXISTS at ", m(str_replace($PL, '<plugins>', $lock)), " — modified ", age((int)filemtime($lock)), " ago, ", filesize($lock), " B", ctype_digit($c) && strlen($c) === 10 ? ", content a unix time " . age((int)$c) . " ago" : (ctype_digit($c) ? ", content a number (" . strlen($c) . " digits)" : ''), "\n"; }
        else echo "@@NOTE J1 no main.lock file found under <plugin>/data, <plugin>/ or the persistent dir — the banner may test a lock elsewhere (see the code lines above)\n";
    }
}
PHP
while IFS= read -r line; do
  case "$line" in
    "@@OK "*)   ok   "${line#@@OK }";;
    "@@NOTE "*) note "${line#@@NOTE }";;
    "@@BAD "*)  bad  "${line#@@BAD }";;
    *)          printf '%s\n' "$line";;
  esac
done < <(docker exec -i -w "$IN_CONTAINER" -e "PLUGINS=$PLUGINS_IN" "$CONTAINER" php < "$SNIP/siblings.php" 2>&1)
# --- SIBLINGS END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "identity" ]; then
# --- IDENTITY BEGIN ---
CID="$ARG"; case "$CID" in ''|*[!0-9]*) stop "the client id must be a number";; esac
hdr "I. One customer across four systems — CRM #$CID (read-only; personal identifiers masked)"
# The store is read from a COPY in its own directory (SqliteStore wants a directory holding plugin.sqlite3).
docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO/id' && cp '$DB_IN' '$RO/id/plugin.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/id/plugin.sqlite3-wal' || true )" 2>/dev/null || stop "could not copy the store"
cat > "$SNIP/identity.php" <<'PHP'
<?php
// READ-ONLY. One customer across four systems: the hybrid store (a COPY), uCRM live (GET only, through the
// plugin's own client), Finance's files and Data Report's files. Personal identifiers are masked; the
// customer is the operator's own controlled record. Nothing is written anywhere but the copy's own WAL.
$cid = (int)getenv('CLIENT_ID'); $PL = rtrim((string)getenv('PLUGINS'), '/'); $RO = rtrim((string)getenv('RO_DIR'), '/'); $root = getcwd();
function mp(string $v): string { $d = preg_replace('/\D/', '', $v); return $d === '' ? '—' : '…' . substr($d, -3) . ' (' . strlen($d) . ' digits)'; }
function me(string $v): string { $v = trim($v); if ($v === '' || strpos($v, '@') === false) return $v === '' ? '—' : 'not an address'; [$l, $d] = explode('@', $v, 2); return substr($l, 0, 1) . '***@' . $d; }
function idm(string $v): string { $v = trim($v); if ($v === '') return '—'; if (strlen($v) <= 6) return str_repeat('*', strlen($v)); return substr($v, 0, 3) . '…' . substr($v, -3); }
function crmIdOf(array $k): string { foreach (['crm_client_id', 'assigned_client_id', 'crm_id', 'client_id', 'clientId', 'customer_id', 'ucrm_client_id'] as $f) { if (isset($k[$f]) && (string)$k[$f] !== '') return (string)$k[$f]; } return ''; }
function nm(array $c): string { $n = trim((string)($c['companyName'] ?? '')); if ($n === '') $n = trim(((string)($c['firstName'] ?? '')) . ' ' . ((string)($c['lastName'] ?? ''))); if ($n === '') $n = trim((string)($c['name'] ?? '')); return $n === '' ? '—' : $n; }
$T = [];  // comparison table: field => [system => value]
$C = [];  // comparison keys per field/system (normalised), separate from the display text
$put = function (string $field, string $sys, $val, ?string $cmp = null) use (&$T, &$C) { $d = is_scalar($val) || $val === null ? (string)($val ?? '—') : json_encode($val); $T[$field][$sys] = $d;
    $C[$field][$sys] = $cmp ?? strtolower(trim(preg_replace('/\s*\(.*\)$/', '', $d))); };

// ── 1. the hybrid store (copy) ──
echo "── I-1 dishnet-hybrid-sudan (store copy) ──\n";
require_once 'lib/StoreInterface.php'; require_once 'lib/SqliteStore.php';
$store = SqliteStore::create($RO); $pdo = $store->getPdo();
$row = null; try { $st = $pdo->prepare('SELECT * FROM client_search_index WHERE id = ?'); $st->execute([$cid]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { echo "  client_search_index: ", $e->getMessage(), "\n"; }
if ($row) { echo "  index row: id {$row['id']} · name ", $row['name'] ?? '—', " · phone ", mp((string)($row['phone'] ?? '')), " · email ", me((string)($row['email'] ?? '')), " · service '", (string)($row['service'] ?? ''), "' · is_lead ", var_export($row['is_lead'] ?? null, true), " · is_archived ", var_export($row['is_archived'] ?? null, true), " · has_service ", var_export($row['has_service'] ?? null, true), " · has_invoice ", var_export($row['has_invoice'] ?? null, true), " · updated ", $row['updated_at'] ?? '—', "\n";
    $put('Customer ID', 'Hybrid', $row['id']); $put('Customer name', 'Hybrid', $row['name'] ?? ''); $put('Phone', 'Hybrid', mp((string)($row['phone'] ?? ''))); $put('Email', 'Hybrid', me((string)($row['email'] ?? ''))); }
else { echo "  index row: NONE for id $cid\n"; $put('Customer ID', 'Hybrid', 'absent'); }
$cc = null; foreach ($store->load('ucrm_clients_cache.json') ?? [] as $c) if ((int)($c['id'] ?? 0) === $cid) { $cc = $c; break; }
echo "  ucrm_clients_cache: ", $cc ? "present · name " . nm($cc) . " · isLead " . var_export($cc['isLead'] ?? null, true) . " · isArchived " . var_export($cc['isArchived'] ?? null, true) . " · contacts " . count((array)($cc['contacts'] ?? [])) : 'absent', "\n";
$svcs = []; foreach ($store->load('ucrm_services_cache.json') ?? [] as $s) if ((int)($s['clientId'] ?? $s['_clientId'] ?? 0) === $cid) $svcs[] = $s;
echo "  ucrm_services_cache: ", count($svcs), " service(s)"; foreach ($svcs as $s) echo " · [id ", $s['id'] ?? '?', " '", (string)($s['name'] ?? $s['servicePlanName'] ?? ''), "' price ", $s['price'] ?? '—', " status ", $s['status'] ?? '—', " plan ", $s['servicePlanId'] ?? '—', "]"; echo "\n";
if ($svcs) { $put('Service', 'Hybrid', 'id ' . ($svcs[0]['id'] ?? '?')); $put('Service plan', 'Hybrid', (string)($svcs[0]['name'] ?? $svcs[0]['servicePlanName'] ?? '')); $put('Monthly price', 'Hybrid', (string)($svcs[0]['price'] ?? '')); $put('Active/suspended state', 'Hybrid', 'service status ' . (string)($svcs[0]['status'] ?? '')); } else { $put('Service', 'Hybrid', 'none cached'); }
$inv = []; foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $i) if ((int)($i['clientId'] ?? 0) === $cid) $inv[] = $i;
$byS = []; $due = 0.0; foreach ($inv as $i) { $byS[(string)($i['status'] ?? '?')] = ($byS[(string)($i['status'] ?? '?')] ?? 0) + 1; $due += (float)($i['total'] ?? 0) - (float)($i['amountPaid'] ?? 0); }
echo "  ucrm_invoices_cache: ", count($inv), " invoice(s); by status ", json_encode($byS), "; outstanding ", number_format($due, 0), "\n"; $put('Invoice', 'Hybrid', count($inv) . ' cached, due ' . number_format($due, 0), count($inv) . '|' . round($due)); $put('Payment status', 'Hybrid', $due > 0 ? 'outstanding ' . number_format($due, 0) : 'nothing due (cache)', (string)round($due));
$pays = 0; foreach ($store->load('ucrm_invoice_payments_cache.json') ?? [] as $k => $p) { if (is_array($p)) foreach ($p as $pp) if ((int)($pp['clientId'] ?? 0) === $cid) $pays++; } echo "  ucrm_invoice_payments_cache: $pays payment(s) for this client\n";
foreach (['equipment_assignments' => 'crm_client_id', 'stock_units' => 'crm_client_id', 'customer_identities' => 'crm_client_id', 'kyc_applications' => 'crm_client_id', 'customer_sessions' => 'client_id'] as $tb => $col) {
    try { $st = $pdo->prepare("SELECT * FROM $tb WHERE $col = ?"); $st->execute([$cid]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); echo "  $tb: ", count($rows), " row(s)";
        if ($tb === 'equipment_assignments') foreach ($rows as $r) echo " · [kit ", idm((string)($r['kit_serial'] ?? '')), " sl ", idm((string)($r['starlink_service_line'] ?? '')), " acc ", idm((string)($r['starlink_account'] ?? '')), " router ", idm((string)($r['router_id'] ?? '')), " service ", $r['crm_service_id'] ?? '—', "]";
        if ($tb === 'customer_sessions') { $live = 0; foreach ($rows as $r) if (empty($r['revoked_at']) && strtotime((string)($r['expires_at'] ?? '1970')) > time()) $live++; echo " ($live live)"; }
        echo "\n"; if ($tb === 'equipment_assignments') { $put('Kit', 'Hybrid', count($rows) ? count($rows) . ' assignment(s)' : 'no kit assigned', (string)count($rows)); $put('Kit serial', 'Hybrid', $rows ? idm((string)($rows[0]['kit_serial'] ?? '')) : '—'); $put('Starlink account', 'Hybrid', $rows ? idm((string)($rows[0]['starlink_account'] ?? '')) : '—'); } }
    catch (Throwable $e) { echo "  $tb: ", stripos($e->getMessage(), 'no such') !== false ? 'table absent' : $e->getMessage(), "\n"; }
}
try { $st = $pdo->prepare("SELECT action, COUNT(*) c FROM app_audit_log WHERE phone IN (SELECT phone FROM client_search_index WHERE id = ?) OR phone IN (SELECT LOWER(email) FROM client_search_index WHERE id = ?) GROUP BY action"); $st->execute([$cid, $cid]); $a = []; foreach ($st as $r) $a[] = $r['action'] . '×' . $r['c']; echo "  app_audit_log for this customer's identifiers: ", $a ? implode(' ', $a) : 'none', "\n"; } catch (Throwable $e) {}
$put('Usage', 'Hybrid', 'API app_usage → unavailable (hard-coded); portal joins KitUsage on equipment_assignments', 'n/a');

// ── 2. uCRM live (read-only GET) ──
echo "── I-2 uCRM live (GET through the plugin's CrmApiClient; the plugin's own app key; nothing written) ──\n";
require_once 'lib/CrmApiClient.php'; $cfg = $store->load('kyc_config.json') ?: [];
$crm = CrmApiClient::fromUcrm($root, $cfg);
$cl = $crm->get("clients/$cid");
if (!is_array($cl)) { echo "  clients/$cid: NOT READABLE — ", json_encode($crm->lastError ?? 'no detail'), "\n"; $put('Customer ID', 'uCRM', 'unreachable'); }
else { $ph = ''; $em = ''; foreach ((array)($cl['contacts'] ?? []) as $ct) { if ($ph === '' && !empty($ct['phone'])) $ph = (string)$ct['phone']; if ($em === '' && !empty($ct['email'])) $em = (string)$ct['email']; }
    echo "  client: id ", $cl['id'] ?? '?', " · name ", nm($cl), " · isLead ", var_export($cl['isLead'] ?? null, true), " · isActive ", var_export($cl['isActive'] ?? null, true), " · isArchived ", var_export($cl['isArchived'] ?? null, true), " · clientType ", $cl['clientType'] ?? '—', " · contacts ", count((array)($cl['contacts'] ?? [])), " · phone ", mp($ph), " · email ", me($em), " · balance ", $cl['accountBalance'] ?? '—', " · outstanding ", $cl['accountOutstanding'] ?? '—', "\n";
    $put('Customer ID', 'uCRM', $cl['id'] ?? ''); $put('Customer name', 'uCRM', nm($cl)); $put('Phone', 'uCRM', mp($ph)); $put('Email', 'uCRM', me($em)); $put('Payment status', 'uCRM', 'outstanding ' . ($cl['accountOutstanding'] ?? '—') . ', balance ' . ($cl['accountBalance'] ?? '—'), (string)round((float)($cl['accountOutstanding'] ?? 0)));
    $attrs = []; foreach ((array)($cl['attributes'] ?? []) as $at) $attrs[] = (string)($at['name'] ?? $at['key'] ?? '?'); echo "  client custom attributes (names): ", $attrs ? implode(', ', $attrs) : 'none', "\n"; }
$ls = $crm->get("clients/services?clientId=$cid");
if (!is_array($ls)) echo "  clients/services: NOT READABLE\n"; else { echo "  services: ", count($ls), "\n"; foreach ($ls as $s) { $an = []; foreach ((array)($s['attributes'] ?? []) as $at) $an[] = (string)($at['name'] ?? $at['key'] ?? '?') . '=' . (preg_match('/kit|serial|router|line|account/i', (string)($at['name'] ?? '')) ? idm((string)($at['value'] ?? '')) : '<value>'); echo "    · id ", $s['id'] ?? '?', " '", (string)($s['name'] ?? ''), "' plan ", $s['servicePlanId'] ?? '—', " price ", $s['price'] ?? '—', " ", $s['currencyCode'] ?? '', " status ", $s['status'] ?? '—', " active from ", substr((string)($s['activeFrom'] ?? ''), 0, 10), " period ", $s['servicePlanPeriodId'] ?? '—', $an ? " attributes: " . implode(', ', $an) : '', "\n"; }
    if ($ls) { $put('Service', 'uCRM', 'id ' . ($ls[0]['id'] ?? '?')); $put('Service plan', 'uCRM', (string)($ls[0]['name'] ?? '')); $put('Monthly price', 'uCRM', (string)($ls[0]['price'] ?? '')); $put('Active/suspended state', 'uCRM', 'service status ' . (string)($ls[0]['status'] ?? '')); } else $put('Service', 'uCRM', 'none'); }
$li = $crm->get("invoices?clientId=$cid&limit=200");
if (!is_array($li)) echo "  invoices: NOT READABLE\n"; else { $byS = []; $due = 0.0; foreach ($li as $i) { $byS[(string)($i['status'] ?? '?')] = ($byS[(string)($i['status'] ?? '?')] ?? 0) + 1; $due += (float)($i['amountToPay'] ?? ((float)($i['total'] ?? 0) - (float)($i['amountPaid'] ?? 0))); } echo "  invoices: ", count($li), "; by status ", json_encode($byS), "; to pay ", number_format($due, 0), "\n"; $put('Invoice', 'uCRM', count($li) . ' live, to pay ' . number_format($due, 0), count($li) . '|' . round($due)); }
$lp = $crm->get("payments?clientId=$cid&limit=200"); echo "  payments: ", is_array($lp) ? count($lp) : 'NOT READABLE', "\n";

// ── 3. Finance files ──
echo "── I-3 dishnet-starlink-finance (files) ──\n";
$fd = is_dir("$PL/.dishnet-starlink-finance-data") ? "$PL/.dishnet-starlink-finance-data" : "$PL/dishnet-starlink-finance/data";
$J = function (string $dir, string $n) { $d = @json_decode((string)@file_get_contents("$dir/$n"), true); return is_array($d) ? $d : null; };
$kits = $J($fd, 'sl_kits.json'); $mine = []; if ($kits !== null) foreach ($kits as $k) if (is_array($k) && crmIdOf($k) === (string)$cid) $mine[] = $k;
echo "  sl_kits.json: ", $kits === null ? 'absent' : count($kits) . ' kits in the register; ' . count($mine) . ' for crm ' . $cid, "\n"; foreach ($mine as $k) echo "    · kit ", idm((string)($k['kit_number'] ?? '')), " status ", $k['starlink_account_status'] ?? '—', " sl ", idm((string)($k['service_line'] ?? '')), " acc ", idm((string)($k['account_number'] ?? '')), "\n";
$put('Kit', 'Finance', $kits === null ? 'file absent' : (count($mine) ? count($mine) . ' kit(s)' : 'not in the register'), $kits === null ? null : (string)count($mine)); $put('Kit serial', 'Finance', $mine ? idm((string)($mine[0]['kit_number'] ?? '')) : '—'); $put('Starlink account', 'Finance', $mine ? idm((string)($mine[0]['account_number'] ?? '')) : '—');
$fc = $J($fd, 'crm_clients_cache.json'); $fcm = null; if ($fc !== null) foreach ($fc as $c) if ((int)($c['id'] ?? 0) === $cid) { $fcm = $c; break; }
echo "  crm_clients_cache.json (Finance's own uCRM copy): ", $fc === null ? 'absent' : count($fc) . ' clients; id ' . $cid . ' ' . ($fcm ? 'PRESENT (name ' . nm($fcm) . ')' : 'ABSENT'), "\n"; $put('Customer ID', 'Finance', $fc === null ? 'no client copy' : ($fcm ? $cid . ' (own cache)' : 'absent from its cache'), $fcm ? (string)$cid : null); if ($fcm) $put('Customer name', 'Finance', nm($fcm));
$fs = $J($fd, 'crm_services_cache.json'); $fsm = []; if ($fs !== null) foreach ($fs as $s) if ((int)($s['clientId'] ?? 0) === $cid) $fsm[] = $s; echo "  crm_services_cache.json: ", $fs === null ? 'absent' : count($fs) . ' services; ' . count($fsm) . ' for this client', "\n"; if ($fsm) { $put('Service plan', 'Finance', (string)($fsm[0]['name'] ?? $fsm[0]['servicePlanName'] ?? '')); $put('Monthly price', 'Finance', (string)($fsm[0]['price'] ?? '')); }
$fu = $J($fd, 'sl_usage.json'); echo "  sl_usage.json: ", $fu === null ? 'absent' : count($fu) . ' rows', "\n";

// ── 4. Data Report files ──
echo "── I-4 dishnet-data-report (files) ──\n";
$dd = is_dir("$PL/.dishnet-data-report-data") ? "$PL/.dishnet-data-report-data" : "$PL/dishnet-data-report/data";
$reg = $J($dd, 'dr_kit_registry.json'); $rk = []; if ($reg !== null) foreach ((array)($reg['kits'] ?? $reg) as $key => $k) if (is_array($k) && crmIdOf($k) === (string)$cid) $rk[] = (string)($k['kit_number'] ?? $key);
echo "  dr_kit_registry.json: ", $reg === null ? 'absent' : count((array)($reg['kits'] ?? $reg)) . ' kits; ' . count($rk) . ' for crm ' . $cid, "\n"; $put('Kit', 'Data Report', $reg === null ? 'no registry' : (count($rk) ? count($rk) . ' kit(s)' : 'not a client here'), $reg === null ? null : (string)count($rk));
$svc = $J($dd, 'sl_svc_cache.json'); $sm = 0; if ($svc !== null) foreach ($svc as $r) if (is_array($r) && crmIdOf($r) === (string)$cid) $sm++; echo "  sl_svc_cache.json: ", $svc === null ? 'absent' : count($svc) . ' lines; ' . $sm . ' carrying crm ' . $cid, "\n";
$rm = $J($dd, 'wifi_router_map.json'); $rmm = 0; if ($rm !== null) foreach ($rm as $r) if (is_array($r) && crmIdOf($r) === (string)$cid) $rmm++; echo "  wifi_router_map.json: ", $rm === null ? 'absent' : count($rm) . ' routers; ' . $rmm . ' for crm ' . $cid, "\n";
$du = $J($dd, 'sl_usage.json'); $dum = 0; if ($du !== null && $rk) foreach ($du as $u) if (is_array($u) && in_array((string)($u['kit_number'] ?? ''), $rk, true)) $dum++; echo "  sl_usage.json: ", $du === null ? 'absent' : count($du) . ' rows; ' . $dum . ' for this client\'s kits', "\n"; $put('Usage', 'Data Report', $du === null ? 'no usage file' : ($dum . ' rows for this client'), 'n/a');
$put('Customer ID', 'Data Report', ($rk || $sm || $rmm) ? "$cid (derived from kit/line records)" : 'not present in any file', ($rk || $sm || $rmm) ? (string)$cid : null);

// ── 5. the comparison table ──
echo "── I-5 comparison (Same? = every system that HAS the field agrees; source of truth per the code) ──\n";
$truth = ['Customer ID' => 'uCRM client id (Hybrid: copy via webhook + 60 s delta; Finance/Data Report: typed/derived)', 'Customer name' => 'uCRM (Hybrid caches copy it; Finance types it into the kit register)', 'Email' => 'uCRM contact (Hybrid index copies it — the sign-in key)', 'Phone' => 'uCRM contact (Hybrid index copies it — the sign-in key)', 'Service' => 'uCRM service (Hybrid cache; Finance cache)', 'Service plan' => 'uCRM service plan', 'Monthly price' => 'uCRM service price', 'Invoice' => 'uCRM invoices (Hybrid cache refreshed by webhook/on-demand)', 'Kit' => 'DISPUTED: Hybrid equipment_assignments vs Finance sl_kits.json (two registers)', 'Kit serial' => 'as Kit', 'Starlink account' => 'Data Report dr_accounts (session) / Finance sl_kits (typed) / Hybrid assignment', 'Usage' => 'Starlink API via Data Report cron (empty here) or Hybrid own collection', 'Payment status' => 'uCRM (invoices/payments)', 'Active/suspended state' => 'uCRM service status (Starlink pause state lives in Data Report)'];
foreach ($truth as $field => $src) { $vals = $T[$field] ?? []; $keys = array_filter($C[$field] ?? [], function ($v) { return $v !== null && $v !== '' && $v !== '—' && $v !== 'n/a' && !preg_match('/absent|none|unreachable|not present|not in|not a client|no kit|no registry|no usage|no client copy|nothing due|file absent/i', $v); });
    $same = in_array('n/a', $C[$field] ?? [], true) && count($keys) <= 1 ? 'n/a (not comparable)' : (count($keys) <= 1 ? 'n/a (one or none)' : (count(array_unique($keys)) === 1 ? 'yes' : 'NO')); printf("  %-24s uCRM: %-28s Hybrid: %-30s Finance: %-22s DataReport: %-22s same? %-16s truth: %s\n", $field, substr($vals['uCRM'] ?? '—', 0, 28), substr($vals['Hybrid'] ?? '—', 0, 30), substr($vals['Finance'] ?? '—', 0, 22), substr($vals['Data Report'] ?? '—', 0, 22), $same, $src); }
PHP
while IFS= read -r line; do
  case "$line" in
    "@@OK "*)   ok   "${line#@@OK }";;
    "@@NOTE "*) note "${line#@@NOTE }";;
    "@@BAD "*)  bad  "${line#@@BAD }";;
    *)          printf '%s\n' "$line";;
  esac
done < <(docker exec -i -u "$DB_OWNER" -w "$IN_CONTAINER" -e "CLIENT_ID=$CID" -e "PLUGINS=$PLUGINS_IN" -e "RO_DIR=$RO/id" "$CONTAINER" php < "$SNIP/identity.php" 2>&1 | mask)
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
ok "I6 read-only run complete (the store copy was removed)"
# --- IDENTITY END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "login-phone" ] || [ "$MODE" = "login-email" ]; then
# --- LOGIN BEGIN ---
KIND="${MODE#login-}"; IDENT="$ARG"
if [ "$KIND" = "phone" ]; then IDENT_MASKED="$(printf '%s' "$IDENT" | sed -E 's/^(.*)([0-9]{3})$/…\2/')"; Q="phone=$(printf '%s' "$IDENT" | sed 's/+/%2B/')"; else IDENT_MASKED="$(printf '%s' "$IDENT" | sed -E 's/^(.).*(@.*)$/\1***\2/')"; Q="email=$(printf '%s' "$IDENT" | sed 's/@/%40/')"; fi
hdr "L. Controlled ${KIND} sign-in and screen walk — the operator's own customer record ($IDENT_MASKED); nothing is modified"
mkdir -p "$JOURNEY_OUT"
cat > "$SNIP/judge.php" <<'PHP'
$act = (string)($argv[1] ?? ''); $code = (int)($argv[2] ?? 0); $raw = (string)stream_get_contents(STDIN); $j = json_decode($raw, true); $d = is_array($j) ? ($j['data'] ?? $j) : null; $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
$out = function (string $st, string $sum, array $kv = []) { $p = []; foreach ($kv as $k => $v) $p[] = $k . '=' . str_replace(["\t", "\n", ';'], ' ', (string)$v); echo $st, "\t", str_replace(["\t", "\n"], ' ', $sum), "\t", implode(';', $p), "\n"; exit; };
$mp = function ($v) { $s = preg_replace('/\D/', '', (string)$v); return $s === '' ? '—' : '…' . substr($s, -3); }; $me = function ($v) { $v = (string)$v; return strpos($v, '@') === false ? ($v === '' ? '—' : 'set') : substr($v, 0, 1) . '***@' . substr($v, strpos($v, '@') + 1); };
if ($code === 401) $out('BROKEN', "401 " . $msg);
switch ($act) {
    case 'app_me':
        if ($code !== 200 || !is_array($d) || empty($d['id'])) $out('BROKEN', "$code $msg");
        $acc = array_values(array_filter(array_map(function ($a) { return (int)($a['id'] ?? 0); }, (array)($d['accounts'] ?? [])))); sort($acc);
        $fp = hash('sha256', (int)$d['id'] . '|' . implode(',', $acc));
        $out('WORKING', "id {$d['id']} · name set · phone {$mp($d['phone'] ?? '')} · email {$me($d['email'] ?? '')} · service_type '" . ($d['service_type'] ?? '') . "' · services " . count((array)($d['services'] ?? [])) . " · accounts " . count($acc) . " · paused " . var_export((bool)($d['is_paused'] ?? false), true) . " · unpaid_total " . ($d['unpaid_total'] ?? '—') . " · invoices " . ($d['unpaid_count'] ?? $d['invoice_count'] ?? '—'), ['id' => (int)$d['id'], 'accounts' => implode(',', $acc), 'fp' => $fp, 'service_type' => (string)($d['service_type'] ?? ''), 'phone_digits' => strlen(preg_replace('/\D/', '', (string)($d['phone'] ?? '')))]);
    case 'app_account':
        if ($code !== 200 || !is_array($d)) $out($code === 404 ? 'PARTIAL' : 'BROKEN', "$code $msg");
        $eq = (array)($d['equipment'] ?? []); $sv = (array)($d['services'] ?? []); $b = (array)($d['billing'] ?? []);
        $kit = ''; foreach ($eq as $e) { if (!empty($e['kit_serial'] ?? $e['serial'] ?? '')) { $kit = (string)($e['kit_serial'] ?? $e['serial']); break; } }
        $out('WORKING', "services " . count($sv) . " · equipment " . count($eq) . " · billing keys " . implode(',', array_keys($b)) . " · invoices " . count((array)($d['invoices'] ?? [])) . " · payments " . count((array)($d['payments'] ?? [])) . (isset($d['gaps']) ? " · gaps " . count((array)$d['gaps']) : ''), ['kit' => $kit, 'equipment' => count($eq)]);
    case 'app_plan':
        if ($code === 404) $out('PARTIAL', "404 " . $msg . " — no service in ucrm_services_cache for this client"); if ($code !== 200 || !is_array($d)) $out('BROKEN', "$code $msg");
        $out('WORKING', "plan '" . ($d['name'] ?? $d['plan_name'] ?? '') . "' price " . ($d['price'] ?? '—') . " " . ($d['currency'] ?? '') . " status " . ($d['status'] ?? '—'));
    case 'app_invoices':
        if ($code !== 200 || !is_array($d)) $out('BROKEN', "$code $msg"); $list = $d['invoices'] ?? (isset($d[0]) ? $d : []); $byS = []; $first = 0; foreach ((array)$list as $i) { $byS[(string)($i['status'] ?? $i['state'] ?? '?')] = ($byS[(string)($i['status'] ?? $i['state'] ?? '?')] ?? 0) + 1; if (!$first) $first = (int)($i['ucrm_id'] ?? preg_replace('/\D/', '', (string)($i['id'] ?? '0'))); }
        $out('WORKING', count((array)$list) . " invoice(s); by status " . json_encode($byS) . " · unpaid_total " . ($d['unpaid_total'] ?? '—'), ['first_inv' => $first]);
    case 'app_invoice': if ($code !== 200) $out($code === 404 ? 'PARTIAL' : 'BROKEN', "$code $msg"); $out('WORKING', "invoice detail: number " . (isset($d['number']) ? 'set' : '—') . " · total " . ($d['total'] ?? '—') . " · status " . ($d['status'] ?? '—') . " · items " . count((array)($d['items'] ?? [])));
    case 'app_invoice_receipts_list': if ($code !== 200) $out($code >= 500 ? 'BROKEN' : 'PARTIAL', "$code $msg"); $out('WORKING', count((array)($d['receipts'] ?? $d['payments'] ?? $d)) . " receipt row(s) (uCRM live)");
    case 'app_payments': if ($code !== 200 || !is_array($d)) $out('BROKEN', "$code $msg"); $out('WORKING', count((array)($d['payments'] ?? [])) . " payment(s) · billing keys " . implode(',', array_keys((array)($d['billing'] ?? []))));
    case 'app_equipment': if ($code !== 200 || !is_array($d)) $out('BROKEN', "$code $msg"); $n = count((array)($d['equipment'] ?? [])); $out($n ? 'WORKING' : 'PARTIAL', $n ? "$n item(s) from equipment_assignments/stock_units" : "0 items — no kit is assigned to this customer in the hybrid's equipment register");
    case 'app_usage': if ($code !== 200 || !is_array($d)) $out('BROKEN', "$code $msg"); $out(!empty($d['unavailable']) ? 'NOT IMPLEMENTED' : 'WORKING', !empty($d['unavailable']) ? "answers unavailable:true by design (api_customer_app.php: 'TODO: query dishnet-data-report … For now return unavailable')" : "used " . ($d['used_gb'] ?? '—') . " GB");
    case 'app_legal_version': if ($code !== 200) $out('BROKEN', "$code $msg"); $out('WORKING', "tos " . ($d['tos_version'] ?? '—') . " · privacy " . ($d['privacy_version'] ?? '—') . " · accepted " . var_export($d['accepted'] ?? $d['consented'] ?? null, true));
    case 'app_wifi_get': if ($code === 200 && is_array($d) && (string)($d['ssid'] ?? '') === '') $out('PARTIAL', "200 but no router resolved for this customer (empty ssid; a _diag payload explains)"); if ($code === 400) $out('N/A', "400 " . $msg . " — needs a router"); if ($code !== 200) $out('BROKEN', "$code $msg"); $out('WORKING', "200 · ssid set");
    case 'app_site_diagnostics': if ($code === 400) $out('N/A', "400 " . $msg . " — needs a kit/router; this customer has none bound"); if ($code === 503) $out('BROKEN', "503 " . $msg); if ($code === 404) $out('PARTIAL', "404 " . $msg); if ($code !== 200) $out('BROKEN', "$code $msg"); $out('WORKING', "200 · keys " . implode(',', array_slice(array_keys((array)$d), 0, 8)));
    case 'app_data_report_token': if ($code !== 200 || empty($d['token'])) $out('BROKEN', "$code $msg"); $out('WORKING', "hand-off token minted (600 s; not printed)", ['token' => (string)$d['token']]);
    case 'app_logout': $out($code === 200 ? 'WORKING' : 'BROKEN', "$code $msg");
    default: $out($code === 200 ? 'WORKING' : ($code >= 500 ? 'BROKEN' : 'PARTIAL'), "$code $msg");
}
PHP
JUDGE="$(cat "$SNIP/judge.php")"
admin_token && ok "L0 an administrator token is on file (not printed)" || stop "no administrator token on file"
A="Authorization: Bearer $ADMIN_TOKEN"
http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&$Q" '' "$A"
[ "$HTTP_CODE" = "200" ] || stop "staff_login_lookup → $HTTP_CODE"
N_ACC="$(printf '%s' "$HTTP_BODY" | grep -o '"id":[0-9]*' | wc -l | tr -d ' ')"
ELIG="$(printf '%s' "$HTTP_BODY" | grep -o '"eligible":[a-z]*' | head -1 | sed 's/"eligible"://')"
[ "${N_ACC:-0}" -ge 1 ] || stop "L1 $IDENT_MASKED matches no customer record — nothing was sent"
[ "$ELIG" = "true" ] || stop "L1 the record is not eligible to sign in (refused_because=$(jfield "$HTTP_BODY" refused_because)) — nothing was sent"
ok "L1 $IDENT_MASKED matches $N_ACC account(s) in the sign-in index; eligible (transport in use: $(jfield "$HTTP_BODY" in_use))"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"$KIND\":\"$IDENT\"}"
MSG="$(jfield "$HTTP_BODY" message)"
[ "$HTTP_CODE" = "200" ] && ok "L2 app_send_otp: 200 '$MSG'" || stop "L2 app_send_otp → $HTTP_CODE '$MSG'"
CODE="$(ask_code)"
[ -n "$CODE" ] || stop "L3 no code arrived by $KIND — the transport is the thing to diagnose; nothing else was changed"
http POST "$PLUGIN_BASE?page=api&action=app_verify_otp" "{\"$KIND\":\"$IDENT\",\"code\":\"$CODE\"}"
[ "$HTTP_CODE" = "200" ] || stop "L3 app_verify_otp → $HTTP_CODE $(jfield "$HTTP_BODY" message) — a mistyped code is the usual cause; run again"
SC="$(printf '%s' "$HTTP_HEADERS" | grep -i '^set-cookie: dn_customer_session=' | head -1 | tr -d '\r')"
CVAL="$(printf '%s' "$SC" | sed -E 's/^[Ss]et-[Cc]ookie: dn_customer_session=([^;]*).*/\1/')"
[ -n "$CVAL" ] && ok "L3 verified; the server set the session cookie ($(printf '%s' "$SC" | grep -qi httponly && printf HttpOnly) $(printf '%s' "$SC" | grep -qi 'samesite=lax' && printf SameSite=Lax) $(printf '%s' "$SC" | grep -qi secure && printf Secure))" || stop "L3 no session cookie in the answer"
printf '%s' "$HTTP_BODY" | grep -q '"token":"' && bad "L3 the JSON body carries a token" || ok "L3 the JSON body carries no token"
C="Cookie: dn_customer_session=$CVAL"
WALK_KV=""
walk() {  # $1 screen  $2 action[&params]  $3 data source (from the code)
  local act="${2%%&*}" j st sum kv
  http GET "$PLUGIN_BASE?page=api&action=$2" '' "$C"
  j="$(printf '%s' "$HTTP_BODY" | docker exec -i "$CONTAINER" php -d display_errors=0 -r "$JUDGE" -- "$act" "$HTTP_CODE" 2>/dev/null | head -1)"
  IFS=$'\t' read -r st sum kv <<<"$j"; [ -n "$st" ] || { st="BROKEN"; sum="judge produced nothing (HTTP $HTTP_CODE)"; kv=""; }
  WALK_KV="$kv"
  printf '  %-15s %-12s %s\n' "$st" "$1" "$sum"; printf '  %-15s %-12s   ← %s\n' '' '' "$3"
  case "$st" in WORKING) PASS=$((PASS+1));; BROKEN) FAIL=$((FAIL+1));; *) NOTE=$((NOTE+1));; esac
}
kv() { printf '%s' "$WALK_KV" | tr ';' '\n' | sed -n "s/^$1=//p" | head -1; }
echo; echo "  ── the screens, through the customer API (WORKING / PARTIAL / BROKEN / NOT IMPLEMENTED / N-A) ──"
walk "Account"     "app_me"                    "client_search_index + ucrm_clients_cache (name/phone/email) · ucrm_services_cache (services) · Finance sl_kits.json (kits) · Data Report wifi_router_map + wifi_test_block_state (paused) · ucrm_invoices_cache (unpaid)"
ID="$(kv id)"; ACCS="$(kv accounts)"; FP="$(kv fp)"; STYPE="$(kv service_type)"
[ -n "$ID" ] || stop "app_me returned no id — nothing further can be walked"
walk "Account"     "app_account"               "CustomerAccountService: uCRM LIVE clients/{id} + clients/services (refresh) · invoices/payments caches · stock_units + equipment_assignments · customer_identities · kyc_applications"
KIT="$(kv kit)"; EQN="$(kv equipment)"
walk "Services"    "app_plan"                  "ucrm_services_cache (active service) + ucrm_plans_cache (plan name)"
walk "Invoices"    "app_invoices"              "ucrm_invoices_cache, refreshed from uCRM by webhook (invoice/payment) and on demand when stale"
INV="$(kv first_inv)"
if [ -n "$INV" ] && [ "$INV" != "0" ]; then walk "Invoice" "app_invoice&id=$INV" "ucrm_invoices_cache (one invoice)"; walk "Receipts" "app_invoice_receipts_list&inv_id=$INV" "uCRM LIVE payments for the invoice (+ ucrm_invoice_payments_cache)"; else note "Invoice/Receipts: no invoice to open (the list was empty)"; fi
walk "Payments"    "app_payments"              "CustomerAccountService: ucrm_invoice_payments_cache after a live refresh"
walk "Equipment"   "app_equipment"             "CustomerAccountService: stock_units + equipment_assignments (the hybrid's OWN kit register, not Finance's)"
walk "Usage"       "app_usage"                 "hard-coded unavailable in api_customer_app.php (the portal's usage view instead joins KitUsage: our own collection, else Data Report sl_usage.json, on equipment_assignments)"
walk "Legal"       "app_legal_version"         "customer_consents (terms/privacy versions)"
if [ -n "$KIT" ]; then walk "Starlink" "app_site_diagnostics&kit=$(printf '%s' "$KIT" | sed 's/[^A-Za-z0-9-]//g')" "Finance sl_kits.json (kit) + Data Report wifi_router_map + sl_svc_cache · dr_wifi_* over HTTP"; else walk "Starlink" "app_site_diagnostics" "Finance sl_kits.json + Data Report files — needs a kit bound to the customer"; fi
walk "WiFi"        "app_wifi_get"              "Data Report wifi_router_map.json + app_wifi_cache · dr_wifi_get_config over HTTP — needs a router"
walk "Data Report" "app_data_report_token"     "the hand-off token (docs/36): minted here, verified by dishnet-data-report"
TOK="$(kv token)"
if [ -n "$TOK" ]; then
  DRURL="$(printf '%s' "$PLUGIN_BASE" | sed 's#/dishnet-hybrid-sudan/public.php#/dishnet-data-report/public.php#')"
  if [ "$DRURL" != "$PLUGIN_BASE" ]; then
    http GET "$DRURL?clientId=$ID&token=$TOK" ''
    if [ "$HTTP_CODE" = "404" ] && printf '%s' "$HTTP_BODY" | grep -q 'Report not found'; then note "Data Report link opened with the token → 404 'Report not found' (the sibling cannot verify the token on this host — docs/36 §0)"; else note "Data Report link opened with the token → HTTP $HTTP_CODE ($(printf '%s' "$HTTP_BODY" | tr -d '\n' | sed -E 's/<[^>]+>//g' | cut -c1-80 | mask))"; fi
  else note "Data Report link: the plugin base is not under /dishnet-hybrid-sudan/ here — link not followed"; fi
fi
unset TOK
echo; echo "  ── the portal pages, with the cookie (200 = rendered; 302 = sent to the sign-in or consent step) ──"
BRAND_HTML=""
for V in home account plans invoices usage sites support devices; do
  http GET "$PLUGIN_BASE?page=customer_portal&view=$V" '' "$C"
  LOC="$(printf '%s' "$HTTP_HEADERS" | grep -i '^location:' | head -1 | tr -d '\r' | sed -E 's/^[Ll]ocation: *//')"
  if [ "$HTTP_CODE" = "200" ]; then printf '  %-15s %-12s 200 rendered (%s bytes)\n' "PAGE OK" "view=$V" "$(printf '%s' "$HTTP_BODY" | wc -c | tr -d ' ')"; [ -z "$BRAND_HTML" ] && BRAND_HTML="$HTTP_BODY"; PASS=$((PASS+1))
  elif [ "$HTTP_CODE" = "302" ]; then case "$LOC" in *step=consent*) printf '  %-15s %-12s 302 → the consent step (terms not yet accepted on the web; NOT accepted by this audit)\n' "CONSENT FIRST" "view=$V";; *customer_login*) printf '  %-15s %-12s 302 → sign-in (the page did not recognise the session)\n' "BROKEN" "view=$V"; FAIL=$((FAIL+1));; *) printf '  %-15s %-12s 302 → %s\n' "REDIRECT" "view=$V" "$(printf '%s' "$LOC" | mask | cut -c1-80)";; esac; NOTE=$((NOTE+1))
  else printf '  %-15s %-12s HTTP %s\n' "BROKEN" "view=$V" "$HTTP_CODE"; FAIL=$((FAIL+1)); fi
done
if [ -z "$BRAND_HTML" ]; then http GET "$PLUGIN_BASE?page=customer_login" '' "$C"; [ "$HTTP_CODE" = "200" ] && { BRAND_HTML="$HTTP_BODY"; echo "  (branding read from the sign-in/consent page, since every portal page redirected)"; }; fi
echo; echo "  ── branding on the rendered page (counts) ──"
b() { printf '%s' "$BRAND_HTML" | grep -o -F -- "$1" | wc -l | tr -d ' '; }
echo "  UGX $(b 'UGX') · SSP $(b 'SSP') · 'DishNet Africa' $(b 'DishNet Africa') · Kampala $(b 'Kampala') · Uganda $(b 'Uganda') · '+256 705 993 348' $(b '+256 705 993 348') · 256705993348 $(b '256705993348') · Juba $(b 'Juba') · 'South Sudan' $(b 'South Sudan') · '+211' $(b '+211')"
[ "$(b 'SSP')" = "0" ] && [ "$(b 'Juba')" = "0" ] && [ "$(b 'South Sudan')" = "0" ] && ok "L5 no South Sudan branding, currency or city on the rendered page" || bad "L5 South Sudan wording or currency on the rendered page"
[ "$(b 'UGX')" != "0" ] || [ "$(b 'DishNet Africa')" != "0" ] && ok "L5 Uganda tenant wording present (UGX/DishNet Africa)" || note "L5 neither UGX nor the trading name appears on the rendered page"
echo; echo "  ── identity fingerprint (sha256 of the customer id + account ids; no identity in the log) ──"
printf 'kind=%s\nid=%s\naccounts=%s\nfp=%s\nat=%s\n' "$KIND" "$ID" "$(printf '%s' "$ACCS" | tr ',' '\n' | grep -c . )" "$FP" "$TS" > "$JOURNEY_OUT/journey-fp-$KIND.txt"
ok "L6 $KIND sign-in resolved to CRM #$ID with $(printf '%s' "$ACCS" | tr ',' '\n' | grep -c .) account(s); fingerprint saved to $JOURNEY_OUT/journey-fp-$KIND.txt (run --compare after both logins)"
http POST "$PLUGIN_BASE?page=api&action=app_logout" '{}' "$C" "X-Requested-With: DishNet" "Origin: ${PLUGIN_BASE%%/crm/*}"
[ "$HTTP_CODE" = "200" ] && ok "L7 app_logout: 200" || bad "L7 app_logout → $HTTP_CODE"
http GET "$PLUGIN_BASE?page=api&action=app_me" '' "$C"
[ "$HTTP_CODE" = "401" ] && ok "L7 the cookie after logout: 401 (revoked)" || bad "L7 the cookie still works after logout → $HTTP_CODE"
NCL="$(docker logs "$CONTAINER" --since "$STARTED" 2>&1 | grep -c -F -- "$CODE" || true)"; NCK="$(docker logs "$CONTAINER" --since "$STARTED" 2>&1 | grep -c -F -- "$CVAL" || true)"
[ "${NCL:-0}" = "0" ] && [ "${NCK:-0}" = "0" ] && ok "L8 neither the code nor the session cookie appears in the container log since $STARTED" || bad "L8 the code appears $NCL time(s) and the cookie $NCK time(s) in the container log"
unset CODE CVAL C
# --- LOGIN END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "compare" ]; then
# --- COMPARE BEGIN ---
hdr "C. Phone login = e-mail login = the same customer?"
FP_P="$JOURNEY_OUT/journey-fp-phone.txt"; FP_E="$JOURNEY_OUT/journey-fp-email.txt"
[ -f "$FP_P" ] || stop "no phone fingerprint yet — run --login-phone first"
[ -f "$FP_E" ] || stop "no e-mail fingerprint yet — run --login-email first"
rd() { sed -n "s/^$2=//p" "$1" | head -1; }
echo "  phone  → CRM #$(rd "$FP_P" id) · $(rd "$FP_P" accounts) account(s) · at $(rd "$FP_P" at)"
echo "  e-mail → CRM #$(rd "$FP_E" id) · $(rd "$FP_E" accounts) account(s) · at $(rd "$FP_E" at)"
if [ "$(rd "$FP_P" fp)" = "$(rd "$FP_E" fp)" ] && [ -n "$(rd "$FP_P" fp)" ]; then ok "C1 SAME customer identity and the same account scope by both routes (fingerprints equal)"; else bad "C1 the two routes resolved DIFFERENTLY (fingerprints differ) — see the ids above"; fi
# --- COMPARE END ---
fi

hdr "Summary"
echo "  mode     $MODE${ARG:+ $(printf '%s' "$ARG" | sed -E 's/^(.).*(@.*)$/\1***\2/; s/^(\+?[0-9]*)([0-9]{3})$/…\2/')}"
echo "  checks   $PASS ok, $FAIL failed, $NOTE notes"
[ "$FAIL" = "0" ] && echo "  Send this LOG FILE back (not a copy of the terminal)." || echo "  $FAIL FAILED — send the log file; nothing was deployed or changed."
[ "$FAIL" = "0" ]
