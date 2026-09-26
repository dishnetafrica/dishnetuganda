#!/usr/bin/env bash
#
# phase2-verify.sh — the final Phase 2 verifications on the live Uganda install. READ-MOSTLY:
# it deploys nothing, changes no configuration, and touches no customer record. The only
# writes are the rows the plugin itself writes when a sign-in is attempted (audit, one
# pending code, one session that is logged out again).
#
# Run as root on the server, one mode at a time, and send back THE LOG FILE:
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-verify \
#     && bash scripts/phase2-verify.sh <mode> 2>&1 | tee /root/dnb-verify/verify-<mode>-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Modes (exactly one):
#   --data-report          READ-ONLY. Prints the installed dishnet-data-report plugin's token-handling
#                          SOURCE, masked: what reads the token, the function that verifies it and
#                          with which key, the region around its "JWT is valid" check, every line
#                          reading clientId, the actions it dispatches, its gates — plus whether
#                          crm_auth_token / webhook_secret / crm_app_key are SET or EMPTY on this
#                          install (presence only; no value, key, hash or e-mail is ever printed).
#                          Computed in PHP inside the container: this container's grep has no
#                          --include, which silently emptied the first version's mechanical lines.
#   --email <address>      One controlled e-mail sign-in with an address on a customer record YOU
#                          control. One e-mail is sent to it; the code is typed here and never
#                          printed; the session is logged out at the end.
#   --lead <+2567…>        One controlled eligibility refusal. The number MUST belong to a LEAD:
#                          the script checks that first and stops if it is not — so no customer is
#                          ever contacted. A lead gets no message: the gate refuses before sending.
#   --website              Reports whether the live site already links the portal (report only).
#
# What it never does: print a token, code, key, secret or password; send anything to a customer;
# change a record; deploy; roll back.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
SIBLING="dishnet-data-report"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBSITE="${WEBSITE:-https://dishnetuganda.com/}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SNIP="$(mktemp -d)"; trap 'rm -rf "$SNIP"' EXIT

MODE=""; ARG=""
case "${1:-}" in
  --data-report|--website) MODE="${1#--}" ;;
  --email|--lead) MODE="${1#--}"; ARG="${2:-}"; [ -n "$ARG" ] || { echo "usage: $0 $1 <value>" >&2; exit 64; } ;;
  *) echo "usage: $0 --data-report | --email <address> | --lead <+2567…> | --website" >&2; exit 64 ;;
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

hdr "Phase 2 verification — $MODE — $TS — $(hostname)"

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
if [ "$MODE" = "data-report" ]; then
# --- DR BEGIN ---
hdr "D. The data-report hand-off token — read-only SOURCE inspection of the installed sibling plugin"
SIB_IN="$PLUGINS_IN/$SIBLING"
if docker exec "$CONTAINER" test -d "$SIB_IN"; then
  ok "D1 $SIBLING is installed at $SIB_IN"
  echo "  version         $(docker exec "$CONTAINER" php -r '$m=@json_decode((string)@file_get_contents($argv[1]),true); echo (string)($m["information"]["version"]??"?");' "$SIB_IN/manifest.json" 2>/dev/null)"
  echo "  The first run's D2–D7 lines and its occurrence counts were INVALID: this container's grep has no --include, so every"
  echo "  such command printed nothing and the counts covered every file, data included. Everything below is computed in PHP,"
  echo "  over PHP files only, and masked before it is printed (the shared constant → a placeholder; a credential-shaped value,"
  echo "  any long letters+digits run, any e-mail, any long number → <redacted>). No data file is opened."
  cat > "$SNIP/drsrc.php" <<'PHP'
<?php
// READ-ONLY source inspection of the installed dishnet-data-report plugin. Nothing here writes.
// Every excerpt is masked before it is printed: the shared constant becomes a placeholder, a
// credential-shaped assignment loses its value, any long letters+digits run (a key, a token, a
// hash), any e-mail address and any long number are replaced. Data files are never opened.
$sib = rtrim((string)getenv('SIB'), '/'); $hyb = rtrim((string)getenv('HYB'), '/');
$CONST = 'DishNet-Hybrid-JWT-v2-2026';   // our own JwtAuth::legacySecret constant — public in our repository
$LINES_OUT = 0; $CAP = 2000;
function out(string $s): void { global $LINES_OUT, $CAP; if ($LINES_OUT === $CAP) { echo "  … output capped at {$CAP} lines\n"; } if ($LINES_OUT >= $CAP) { $LINES_OUT++; return; } $LINES_OUT++; echo $s, "\n"; }
function m(string $s): string {
    global $CONST;
    $s = str_replace($CONST, '<the shared constant>', $s);
    $s = preg_replace('/((?:password|passwd|secret|api_?key|app_?key|token|bearer|pepper|private_?key)\s*(?:=>|=|:)\s*[\'"])([^\'"]{6,})([\'"])/i', '$1<redacted>$3', $s);
    $s = preg_replace_callback('/[A-Za-z0-9+\/=_-]{24,}/', function ($mm) {
        $r = $mm[0]; if (strlen($r) >= 40) return '<redacted>';
        return (preg_match('/\d/', $r) && preg_match('/[A-Za-z]/', $r)) ? '<redacted>' : $r;   // a name has no digits; a key or hash has both
    }, $s);
    $s = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '<email>', $s);
    $s = preg_replace('/(?<![\w.])\d{7,}(?![\w.])/', '<digits>', $s);
    return rtrim($s);
}
function rel(string $p): string { global $sib; return ltrim(substr($p, strlen($sib)), '/'); }
function phpFiles(string $dir): array {
    $out = []; $skip = ['data', 'vendor', 'node_modules', '.git', 'backups', 'backup', 'logs', 'cache', 'tmp', 'sessions'];
    $walk = function (string $d, int $depth) use (&$walk, &$out, $skip) {
        $h = @scandir($d); if ($h === false) return;
        foreach ($h as $n) {
            if ($n === '.' || $n === '..') continue; $p = "$d/$n";
            if (is_dir($p)) { if ($depth < 3 && !in_array($n, $skip, true)) $walk($p, $depth + 1); }
            elseif (substr($n, -4) === '.php') $out[] = $p;
        }
    };
    $walk($dir, 0); sort($out); return $out;
}
$P = [
    'token param'      => '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"]token[\'"]\s*\]/',
    'clientId param'   => '/\$_(GET|POST|REQUEST)\s*\[\s*[\'"](clientId|client_id)[\'"]\s*\]/',
    'clientId'         => '/clientId/',
    'hash_hmac'        => '/hash_hmac\s*\(/',
    'hash_equals'      => '/hash_equals\s*\(/',
    'JwtAuth'          => '/JwtAuth|legacySecret|fromConfig\s*\(/',
    'the constant'     => '/DishNet-Hybrid-JWT/',
    'webhook_secret'   => '/webhook_secret/',
    'crm_auth_token'   => '/crm_auth_token/',
    'crm_app_key'      => '/crm_app_key/',
    'kyc_config'       => '/kyc_config/',
    'sqlite'           => '/sqlite|SqliteStore|new\s+PDO\s*\(/i',
    'hybrid path'      => '/dishnet-hybrid/',
    "'sub'"            => '/[\'"]sub[\'"]/',
    "'exp'"            => '/[\'"]exp[\'"]/',
    "'aud'"            => '/[\'"]aud[\'"]/',
    "'kid'"            => '/[\'"]kid[\'"]/',
    'alg / HS256'      => '/[\'"]alg[\'"]|HS256/',
    "'accounts'"       => '/[\'"]accounts[\'"]/',
    'internal auth'    => '/Internal-Auth|INTERNAL_AUTH|internal_auth/',
    'ucrm session'     => '/PHPSESSID|nms-session|nms-crm|current-user|currentUser/i',
    'X-Auth-App-Key'   => '/X-Auth-App-Key|pluginAppKey/i',
];
$files = phpFiles($sib);
if ($files === []) { out("@@BAD D1 no PHP file is readable under {$sib}"); exit; }
$SRC = []; $unreadable = [];
foreach ($files as $f) { if (!is_readable($f)) { $unreadable[] = rel($f); continue; } $SRC[$f] = file($f, FILE_IGNORE_NEW_LINES) ?: []; }
$pub = "$sib/public.php";
if (!isset($SRC[$pub])) { out("@@BAD D1 public.php is not readable — nothing below can be trusted"); }

// ── 1. Inventory: PHP files only, this time (the earlier counts covered every file, data included) ──
out(""); out("── D-I inventory of PHP files (bytes · lines · sha256[0:12]) and where the load-bearing words occur ──");
$tot = [];
foreach ($SRC as $f => $L) {
    $c = []; foreach ($P as $k => $re) { $n = 0; foreach ($L as $ln) if (preg_match($re, $ln)) $n++; if ($n) { $c[] = $k . "×" . $n; $tot[$k] = ($tot[$k] ?? 0) + $n; } }
    out(sprintf("  %-34s %7d B %5d lines  %s  %s", rel($f), filesize($f), count($L), substr(hash_file('sha256', $f), 0, 12), $c ? implode(', ', $c) : '—'));
}
foreach ($unreadable as $u) out("  $u  UNREADABLE by this user");
out("  totals over PHP files: " . ($tot ? implode(', ', array_map(function ($k, $v) { return $k . "×" . $v; }, array_keys($tot), $tot)) : 'none of the words occurs'));

// helpers over a file's lines
function grepL(array $L, string $re, int $cap = 120): array { $o = []; foreach ($L as $i => $ln) { if (preg_match($re, $ln)) { $o[] = [$i + 1, $ln]; if (count($o) >= $cap) break; } } return $o; }
function printLines(array $hits, int $cap = 120): void { $n = 0; foreach ($hits as [$no, $ln]) { out(sprintf("  %5d: %s", $no, m($ln))); if (++$n >= $cap) { out("  … (more, capped)"); break; } } if ($hits === []) out("  (none)"); }
function region(array $L, int $from, int $to): void { $from = max(1, $from); $to = min(count($L), $to); for ($i = $from; $i <= $to; $i++) out(sprintf("  %5d| %s", $i, m($L[$i - 1]))); }
function funcAround(array $L, int $at): array {   // [start,end] of the function that contains line $at (1-based), or a window
    $start = null; for ($i = $at; $i >= max(1, $at - 120); $i--) { if (preg_match('/^\s*(?:(?:public|private|protected|static|final)\s+)*function\s+\w+/', $L[$i - 1])) { $start = $i; break; } }
    if ($start === null) return [max(1, $at - 30), min(count($L), $at + 40)];
    $depth = 0; $seen = false; $end = min(count($L), $start + 160);
    for ($i = $start; $i <= min(count($L), $start + 160); $i++) { $ln = preg_replace('/\/\/.*$|#.*$/', '', $L[$i - 1]); $o = substr_count($ln, '{'); $c = substr_count($ln, '}'); if ($o) $seen = true; $depth += $o - $c; if ($seen && $depth <= 0) { $end = $i; break; } }
    return [$start, $end];
}
function actions(array $L): array {
    $names = [];
    foreach ($L as $ln) {
        if (preg_match_all('/(?:\$\w*(?:action|act|page|view|mode)\w*|\$_(?:GET|POST|REQUEST)\s*\[\s*[\'"](?:action|page)[\'"]\s*\])\s*[!=]==?\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $ln, $mm)) foreach ($mm[1] as $x) $names[$x] = true;
        if (preg_match_all('/^\s*case\s+[\'"]([A-Za-z0-9_\-]+)[\'"]\s*:/', $ln, $mm)) foreach ($mm[1] as $x) $names[$x] = true;
        if (preg_match_all('/[\'"]([A-Za-z0-9_\-]+)[\'"]\s*[!=]==?\s*\$\w*(?:action|act)\w*/', $ln, $mm)) foreach ($mm[1] as $x) $names[$x] = true;
        if (preg_match('/in_array\s*\(\s*\$\w*(?:action|act)\w*\s*,\s*\[([^\]]*)\]/', $ln, $mm)) { if (preg_match_all('/[\'"]([A-Za-z0-9_\-]+)[\'"]/', $mm[1], $m2)) foreach ($m2[1] as $x) $names[$x] = true; }
    }
    $names = array_keys($names); sort($names); return $names;
}

if (isset($SRC[$pub])) {
    $L = $SRC[$pub]; $N = count($L);
    out(""); out("── D-II public.php ({$N} lines): what it includes ──");
    printLines(grepL($L, '/^\s*(require|include)(_once)?\b/', 60), 60);

    out(""); out("── D-III public.php: every request parameter it reads (GET/POST/REQUEST/COOKIE, and identity headers) ──");
    printLines(grepL($L, '/\$_(GET|POST|REQUEST|COOKIE)\s*\[|\$_SERVER\s*\[\s*[\'"]HTTP_(X_|AUTHORIZATION|COOKIE)/', 90), 90);

    $acts = actions($L);
    out(""); out("── D-IV public.php: action / page names it dispatches on (" . count($acts) . ") ──");
    if ($acts) { $row = ''; foreach ($acts as $a) { if (strlen($row) + strlen($a) > 150) { out("  $row"); $row = ''; } $row .= ($row === '' ? '' : ' ') . $a; } if ($row !== '') out("  $row"); } else out("  (no literal dispatch found)");
    out("  lines naming dr_raw_services:"); printLines(grepL($L, '/dr_raw_services/', 10), 10);

    out(""); out("── D-V public.php: every function that computes an HMAC (the token verifier), in full ──");
    $hm = grepL($L, '/hash_hmac\s*\(/', 6); $printed = [];
    if ($hm === []) out("  (no hash_hmac in public.php — it verifies no signature itself; see D-I for other files)");
    foreach ($hm as [$no, $ln]) { [$a, $b] = funcAround($L, $no); $key = "$a-$b"; if (isset($printed[$key])) continue; $printed[$key] = true; out("  · lines {$a}–{$b}:"); region($L, $a, $b); }

    $ja = grepL($L, '/JwtAuth|legacySecret|fromConfig\s*\(|->verify\s*\(/', 4);
    if ($ja) { out(""); out("── D-V-b public.php: ±15 lines around every JwtAuth / verify( mention (the verifier may be our own class, included from the hybrid plugin) ──"); $last = 0; foreach ($ja as [$no, $ln]) { if ($no <= $last) continue; out("  · around line {$no}:"); region($L, $no - 15, $no + 15); $last = $no + 15; } }
    $tp = grepL($L, '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"]token[\'"]\s*\]/', 3);
    if ($tp) { out(""); out("── D-V-c public.php: ±12 lines around every read of the token parameter ──"); $last = 0; foreach ($tp as [$no, $ln]) { if ($no <= $last) continue; out("  · around line {$no}:"); region($L, $no - 12, $no + 12); $last = $no + 12; } }
    out(""); out("── D-VI public.php: the region around 'JWT is valid' (±70 lines) — the customer page's authorisation ──");
    $jv = grepL($L, '/JWT is valid|jwt.{0,20}valid|valid.{0,20}jwt/i', 1);
    if ($jv === []) { out("  (no such comment; printing where the token parameter is read instead)"); $tp = grepL($L, '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"]token[\'"]\s*\]/', 1); if ($tp) region($L, $tp[0][0] - 40, $tp[0][0] + 80); else out("  (the token parameter is never read in public.php)"); }
    else region($L, $jv[0][0] - 70, $jv[0][0] + 130);

    // ── the OTHER access paths: the uCRM-session gate, the "view as client" read, and what an anonymous request reaches ──
    out(""); out("── D-VI-b public.php: the session/internal-auth gate (±45 lines around the first PHPSESSID test) and every later clientId read (±60) ──");
    $gate = grepL($L, '/isset\s*\(\s*\$_COOKIE\s*\[\s*[\'"](PHPSESSID|nms-session)[\'"]\s*\]\s*\)\s*\|\|/', 1);
    $ranges = [];
    if ($gate) $ranges[] = [$gate[0][0] - 45, $gate[0][0] + 45];
    $after = $jv ? $jv[0][0] + 130 : 0;
    foreach (grepL($L, '/\$_(GET|POST|REQUEST)\s*\[\s*[\'"](clientId|client_id)[\'"]\s*\]/', 40) as [$no, $ln]) { if ($no > $after && $no > 300) $ranges[] = [$no - 60, $no + 60]; }
    usort($ranges, function ($a, $b) { return $a[0] - $b[0]; });
    $merged = [];
    foreach ($ranges as $r) { if ($merged && $r[0] <= $merged[count($merged) - 1][1] + 1) { $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $r[1]); } else $merged[] = $r; }
    if ($merged === []) out("  (no session gate and no later clientId read found)");
    foreach ($merged as $k => [$a, $b]) { if ($k >= 4) { out("  … (more regions, capped)"); break; } out("  · lines " . max(1, $a) . "–" . min($N, $b) . ":"); region($L, $a, $b); }
    out(""); out("── D-VI-c public.php: its last 40 lines (what a request that matched nothing above reaches) ──");
    region($L, $N - 39, $N);
    out(""); out("── D-VI-d public.php: ±12 lines around every include of dr_wifi_change.php (where its actions are gated, if anywhere) ──");
    $inc = grepL($L, '/(require|include)(_once)?\s*[\(\s].*dr_wifi_change\.php/', 4);
    if ($inc === []) out("  (not included from public.php)");
    foreach ($inc as [$no, $ln]) { out("  · around line {$no}:"); region($L, $no - 12, $no + 12); }

    out(""); out("── D-VII public.php: every line mentioning clientId ──");
    printLines(grepL($L, '/clientId/', 140), 140);

    out(""); out("── D-VIII public.php: where the key inputs come from (webhook_secret / crm_auth_token / crm_app_key / kyc_config / the constant / the hybrid plugin's path / sqlite), ±2 lines ──");
    $ki = grepL($L, '/webhook_secret|crm_auth_token|crm_app_key|kyc_config|DishNet-Hybrid-JWT|dishnet-hybrid|SqliteStore|sqlite3|plugin\.sqlite/', 40);
    if ($ki === []) out("  (none — the key source is not any of these)");
    $want = []; foreach ($ki as [$no, $ln]) for ($i = $no - 2; $i <= $no + 2; $i++) if ($i >= 1 && $i <= $N) $want[$i] = true;
    $prev = 0; $n = 0; foreach (array_keys($want) as $i) { if ($prev && $i > $prev + 1) out("       …"); out(sprintf("  %5d| %s", $i, m($L[$i - 1]))); $prev = $i; if (++$n >= 140) { out("  … (capped)"); break; } }

    out(""); out("── D-IX public.php: claim keys it reads (sub/exp/aud/iss/kid/kind/accounts/alg/jti) ──");
    printLines(grepL($L, '/[\'"](sub|exp|aud|iss|kid|kind|accounts|alg|jti)[\'"]/', 60), 60);

    out(""); out("── D-X public.php: its gates (uCRM session, internal-auth header, hash_equals, 401/403 answers) ──");
    printLines(grepL($L, '/session_start|PHPSESSID|nms-session|nms-crm|current-user|currentUser|Internal-Auth|INTERNAL_AUTH|internal_auth|hash_equals|isAdmin|is_admin|requireAuth|require_auth|Unauthori[sz]ed|Forbidden|http_response_code\s*\(\s*(401|403)|\b40[13]\b/', 80), 80);
}

// ── other PHP files: parameters, gates, and any HMAC function ──
foreach ($SRC as $f => $L) {
    if ($f === $pub) continue;
    $params = grepL($L, '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"](token|clientId|client_id|action|kit|router_id)[\'"]\s*\]/', 30);
    $gates  = grepL($L, '/PHPSESSID|nms-session|nms-crm|current-user|currentUser|Internal-Auth|INTERNAL_AUTH|internal_auth|hash_equals|hash_hmac|JwtAuth|DishNet-Hybrid-JWT|webhook_secret|crm_auth_token|kyc_config|Unauthori[sz]ed|Forbidden|http_response_code\s*\(\s*(401|403)/', 40);
    if ($params === [] && $gates === []) continue;
    out(""); out("── D-XI " . rel($f) . " (" . count($L) . " lines): parameters it reads / its gates ──");
    if ($params) { out("  parameters:"); printLines($params, 30); }
    if ($gates)  { out("  gates and key words:"); printLines($gates, 40); }
    $hm = grepL($L, '/hash_hmac\s*\(/', 3); $printed = [];
    foreach ($hm as [$no, $ln]) { [$a, $b] = funcAround($L, $no); $key = "$a-$b"; if (isset($printed[$key])) continue; $printed[$key] = true; out("  · HMAC function, lines {$a}–{$b}:"); region($L, $a, $b); }
    $disp = grepL($L, '/switch\s*\(\s*\$\w*action|\$\w*action\w*\s*===?\s*[\'"]dr_|case\s+[\'"]dr_|in_array\s*\(\s*\$\w*action/', 1);
    if ($disp) { out("  · the action dispatch, ±30 lines around line {$disp[0][0]} (is anything checked before an action runs?):"); region($L, $disp[0][0] - 30, $disp[0][0] + 30); }
}

// ── the sibling's own credential and data directory: presence and names only ──
out(""); out("── D-XII the sibling's own uCRM credential and data directory (presence and names only; no file is opened) ──");
$uj = @json_decode((string)@file_get_contents("$sib/ucrm.json"), true) ?: [];
out("  ucrm.json pluginAppKey: " . (trim((string)($uj['pluginAppKey'] ?? '')) === '' ? 'EMPTY/absent' : 'set') . " — the sibling's own uCRM API credential; with it the sibling can read ANY client from uCRM, so which client it shows is its own authorisation decision");
foreach (["$sib/data" => '<plugin dir>/data', dirname($sib) . '/.' . basename($sib) . '-data' => '<plugins dir>/.' . basename($sib) . '-data'] as $dd => $label) {
    if (!is_dir($dd)) { out("  {$label}: absent"); continue; }
    $names = array_values(array_diff(@scandir($dd) ?: [], ['.', '..'])); $ext = [];
    foreach ($names as $n) { $e = is_dir("$dd/$n") ? '<dir>' : (pathinfo($n, PATHINFO_EXTENSION) ?: '(none)'); $ext[$e] = ($ext[$e] ?? 0) + 1; }
    out("  {$label}: " . count($names) . " entries — " . implode(', ', array_map(function ($k, $v) { return $k . "×" . $v; }, array_keys($ext), $ext)));
    $sus = array_values(array_filter($names, function ($n) { return preg_match('/secret|key|token|auth|jwt|session|cred|pass/i', $n); }));
    out("  names suggesting a credential store: " . ($sus ? m(implode(', ', $sus)) : 'none'));
}

// ── which hybrid plugin directories exist on THIS host (names only) — the verifier hard-codes one of them ──
out(""); out("── D-XII-b the hybrid plugin directories on this host, as the sibling's verifier would find them (presence only) ──");
$pd = dirname($sib);
foreach (['dishnet-hybrid-telecom', '.dishnet-hybrid-telecom-data', 'dishnet-hybrid-sudan', '.dishnet-hybrid-sudan-data'] as $d) {
    $dir = "$pd/$d";
    if (!is_dir($dir)) { out("  <plugins dir>/{$d}: ABSENT"); continue; }
    $cands = ["{$d}/data/plugin.sqlite3" => "$dir/data/plugin.sqlite3", "{$d}/plugin.sqlite3" => "$dir/plugin.sqlite3"];
    $found = [];
    foreach ($cands as $lab => $f) { if (is_file($f)) { $st = @stat($f); $found[] = $lab . ' (owner ' . ($st ? $st['uid'] . ':' . $st['gid'] : '?') . ', mode ' . ($st ? substr(sprintf('%o', $st['mode']), -4) : '?') . ')'; } }
    out("  <plugins dir>/{$d}: present — " . ($found ? implode('; ', $found) : 'no plugin.sqlite3 in it'));
}

// ── machine summary, computed here (no shell grep): the answers to items 1–7 as far as text can give them ──
out(""); out("── D-XIII mechanical reading (PHP-computed over PHP files only) ──");
$all = function (string $re, int $cap = 12) use ($SRC) { $o = []; foreach ($SRC as $f => $L) foreach ($L as $i => $ln) { if (preg_match($re, $ln)) { $o[] = rel($f) . ':' . ($i + 1); if (count($o) >= $cap) return $o; } } return $o; };
$fmt = function (array $a): string { return $a ? implode(' ', $a) : 'none'; };
$tokRead = $all('/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"]token[\'"]\s*\]/');
out("@@NOTE D2 the token parameter is read at: " . $fmt($tokRead) . ($tokRead ? '' : ' — the hand-off token is DECORATIVE; the exposure is the sibling\'s own access control'));
$sig = $all('/hash_hmac\s*\(/'); $heq = $all('/hash_equals\s*\(/'); $alg = $all('/HS256|[\'"]alg[\'"]/');
out("@@NOTE D3 signature computed at: " . $fmt($sig) . "; constant-time compare at: " . $fmt($heq) . "; algorithm pinned/read at: " . $fmt($alg) . ($sig ? '' : ' — NO signature verification in any PHP file'));
$cst = $all('/DishNet-Hybrid-JWT/'); $inp = $all('/webhook_secret|crm_auth_token|crm_app_key/'); $kc = $all('/kyc_config/');
out("@@NOTE D4 the shared constant at: " . $fmt($cst) . "; the hybrid plugin's key inputs at: " . $fmt($inp) . "; kyc_config at: " . $fmt($kc) . (($cst || $inp) ? ' — it re-derives the SAME legacy key from the hybrid plugin\'s settings' : ' — its key source, if any, is its own'));
$cmp = $all('/(urlClientId|clientId|client_id)[^;]*(\[[\'"](sub|accounts)[\'"]\]|->sub|\$sub\b|\$own\b|\$allowed\b)|(\[[\'"](sub|accounts)[\'"]\]|\$sub\b|\$own\b|\$allowed\b)[^;]*(urlClientId|clientId|client_id)|\$\w*[cC]lient[iI]d\w*\s*[!=]==?\s*\$\w*(?:jwt|sub|claim|token)\w*|\$\w*(?:jwt|sub|claim|token)\w*\s*[!=]==?\s*\$\w*[cC]lient[iI]d\w*/');
out("@@NOTE D5 lines comparing clientId with the token's identity (sub/accounts): " . $fmt($cmp) . ($cmp ? '' : ' — NO line binds clientId to the token'));
$exp = $all('/[\'"]exp[\'"][^;]*time\s*\(|time\s*\(\s*\)[^;]*[\'"]exp[\'"]/'); $aud = $all('/[\'"]aud[\'"]/'); $kid = $all('/[\'"]kid[\'"]/'); $kind = $all('/[\'"]kind[\'"]/');
out("@@NOTE D6 expiry checked against the clock at: " . $fmt($exp) . "; 'aud' read at: " . $fmt($aud) . "; 'kid' read at: " . $fmt($kid) . "; 'kind' read at: " . $fmt($kind));
$noTok = $all('/\$token\s*(===?|!==?)\s*[\'"][\'"]|empty\s*\(\s*\$token\s*\)|!\s*\$token\b|\$token\s*\?\s*:/');
out("@@NOTE D7 what happens with NO token — the branch lines: " . $fmt($noTok) . " (read them in D-VI: a fallback to a uCRM staff session is one thing; serving the page on clientId alone is another)");
$raw = $all('/dr_raw_services/');
out("@@NOTE D8 dr_raw_services (raw uCRM dump for a clientId) at: " . $fmt($raw));
$guard = $all('/(webhook_secret|crm_auth_token|crm_app_key|secretParts\[\d\])[^;]*===?\s*[\'"][\'"]/');
$hpath = $all('/dishnet-hybrid-(telecom|sudan)[^\'"]*plugin\.sqlite3|dishnet-hybrid-(telecom|sudan)[^\'"]*kyc_config/');
out("@@NOTE D9 empty-key-input guard at: " . $fmt($guard) . "; the hybrid store/settings path it hard-codes at: " . $fmt($hpath) . " (compare with D-XII-b: does that path exist on this host?)");
PHP
  # Source files only, never the store, so this runs as the container's default user: a source file the store's owner
  # cannot read must be printed as unreadable, not silently skipped. Output lines tagged @@OK/@@NOTE/@@BAD become checks.
  while IFS= read -r line; do
    case "$line" in
      "@@OK "*)   ok   "${line#@@OK }";;
      "@@NOTE "*) note "${line#@@NOTE }";;
      "@@BAD "*)  bad  "${line#@@BAD }";;
      *)          printf '%s\n' "$line";;
    esac
  done < <(docker exec -i -w "$IN_CONTAINER" -e "SIB=$SIB_IN" -e "HYB=$IN_CONTAINER" "$CONTAINER" php < "$SNIP/drsrc.php" 2>&1)
else
  bad "D1 $SIBLING is not installed at $SIB_IN — nothing verifies the hand-off token, and nothing consumes it"
fi
hdr "K. The hand-off key's inputs on THIS install — presence only, never a value"
ro_copy key || stop "could not copy the store"
cat > "$SNIP/keys.php" <<'PHP'
<?php
// Presence only, never a value. (1) The store copy public.php loads as $config — exactly what app_data_report_token
// hashes. (2) PluginConfig::read(): the three settings files + the vault, changing nothing on disk. (3) Whether a
// PLAIN kyc_config.json (or its .migrated backup) still exists where the sibling could read it: the sibling may
// derive its key from such a file rather than from our store, and then the two keys agree only if the values agree.
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cfg = json_decode((string)$db->query("SELECT data FROM kyc_config LIMIT 1")->fetchColumn(), true) ?: [];
$p = function (array $c, string $k): string { $v = trim((string)($c[$k] ?? '')); return $v === '' ? 'EMPTY' : 'set (' . strlen($v) . ' chars)'; };
$three = function (array $c) use ($p): string { return 'webhook_secret=' . $p($c, 'webhook_secret') . '  crm_auth_token=' . $p($c, 'crm_auth_token') . '  crm_app_key=' . $p($c, 'crm_app_key'); };
echo "store copy (what the hand-off hashes):  ", $three($cfg), "\n";
try { require_once 'lib/PluginConfig.php'; $merged = PluginConfig::read(getcwd(), (string)getenv('PDD_IN')); echo "PluginConfig::read (files + vault):     ", $three($merged), "\n"; }
catch (Throwable $e) { echo "PluginConfig::read unavailable: ", get_class($e), ' — ', $e->getMessage(), "\n"; }
$pdd = (string)getenv('PDD_IN');
foreach (['<data dir>/kyc_config.json' => $pdd . '/kyc_config.json', '<data dir>/kyc_config.json.migrated' => $pdd . '/kyc_config.json.migrated',
          '<plugin dir>/data/kyc_config.json' => getcwd() . '/data/kyc_config.json', '<plugin dir>/data/kyc_config.json.migrated' => getcwd() . '/data/kyc_config.json.migrated'] as $label => $f) {
    if (!is_file($f)) { echo "plain file {$label}: absent\n"; continue; }
    if (!is_readable($f)) { echo "plain file {$label}: present, NOT readable by this user\n"; continue; }
    $c = json_decode((string)file_get_contents($f), true);
    echo "plain file {$label}: present — ", is_array($c) ? $three($c) : 'not JSON', "\n";
}
$ws = trim((string)($cfg['webhook_secret'] ?? '')); $ct = trim((string)($cfg['crm_app_key'] ?? $cfg['crm_auth_token'] ?? ''));
echo "verdict: ", ($ws === '' && $ct === '') ? "BOTH EMPTY in the store copy → the legacy key is sha256('||constant'), a constant anyone can read in the source: a hand-off token is FORGEABLE for any sub" : "at least one input is set → the legacy key has entropy the public does not have (the derivation itself is still shared with whoever verifies it)", "\n";
PHP
cphp keys.php -e "RO_DB=$RO/key.sqlite3" -e "PDD_IN=$PDD_IN" | mask | sed 's/^/  /'
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
echo; echo "  Our side (repository, read here): app_data_report_token mints a 600 s token with the LEGACY derivation, claims sub/kind/phone/name/accounts/aud=data-report,"
echo "  only for a signed-in customer and only with that customer's own sub. Whether it can be forged depends on K; whether a forgery matters depends on D."
# --- DR END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "email" ]; then
# --- EMAIL BEGIN ---
hdr "E. One controlled e-mail sign-in (the address must be on a customer record you control)"
EMAIL="$ARG"; EMAIL_MASKED="$(printf '%s' "$EMAIL" | sed -E 's/^(.).*(@.*)$/\1***\2/')"
admin_token && ok "E0 an administrator token is on file (not printed)" || stop "no administrator token on file — the lookup control needs one"
A="Authorization: Bearer $ADMIN_TOKEN"
http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&email=$(printf '%s' "$EMAIL" | sed 's/@/%40/')" '' "$A"
N_ACC="$(printf '%s' "$HTTP_BODY" | grep -o '"id":[0-9]*' | wc -l | tr -d ' ')"
ELIG="$(printf '%s' "$HTTP_BODY" | grep -o '"eligible":[a-z]*' | head -1 | sed 's/"eligible"://')"
TRANSPORT="$(jfield "$HTTP_BODY" in_use)"
[ "$HTTP_CODE" = "200" ] || stop "staff_login_lookup → $HTTP_CODE"
[ "${N_ACC:-0}" -ge 1 ] || stop "E1 the address $EMAIL_MASKED matches no customer record — nothing was sent"
ok "E1 $EMAIL_MASKED matches $N_ACC customer account(s); eligible=${ELIG:-?}; WhatsApp transport in use: ${TRANSPORT:-?} (e-mail goes by the mailer)"
[ "$ELIG" = "true" ] || stop "E1 the account is not eligible to sign in — nothing was sent"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"email\":\"$EMAIL\"}"
[ "$HTTP_CODE" = "200" ] && [ "$(jfield "$HTTP_BODY" message)" = "Code sent via Email." ] && ok "E2 app_send_otp: 200 'Code sent via Email.'" || stop "E2 app_send_otp → $HTTP_CODE $(jfield "$HTTP_BODY" message)"
CODE="$(ask_code)"
[ -n "$CODE" ] && ok "E3 a code arrived by e-mail" || stop "E3 no code arrived — the mailer is the thing to diagnose (Settings → Email); nothing else was changed"
http POST "$PLUGIN_BASE?page=api&action=app_verify_otp" "{\"email\":\"$EMAIL\",\"code\":\"$CODE\"}"
if [ "$HTTP_CODE" = "200" ]; then
  ok "E4 app_verify_otp: 200 (a session was issued; nothing printed)"
  SC="$(printf '%s' "$HTTP_HEADERS" | grep -i '^set-cookie: dn_customer_session=' | head -1 | tr -d '\r')"
  CVAL="$(printf '%s' "$SC" | sed -E 's/^[Ss]et-[Cc]ookie: dn_customer_session=([^;]*).*/\1/')"
  [ -n "$CVAL" ] && ok "E4 the server set the session cookie" || bad "E4 no session cookie"
  printf '%s' "$SC" | grep -qi 'httponly' && ok "E4 …HttpOnly" || bad "E4 cookie not HttpOnly"
  printf '%s' "$SC" | grep -qi 'samesite=lax' && ok "E4 …SameSite=Lax" || bad "E4 cookie without SameSite=Lax"
  case "$PLUGIN_BASE" in https://*) printf '%s' "$SC" | grep -qi 'secure' && ok "E4 …Secure" || bad "E4 cookie not Secure over HTTPS";; esac
  printf '%s' "$HTTP_BODY" | grep -q '"token":"' && bad "E4 the JSON body carries a token to a browser client" || ok "E4 the JSON body carries no token (web flow)"
  http GET "$PLUGIN_BASE?page=api&action=app_me" '' "Cookie: dn_customer_session=$CVAL"
  [ "$HTTP_CODE" = "200" ] && ok "E5 app_me on the cookie: 200 — the customer's own account (login_mode e-mail)" || bad "E5 app_me → $HTTP_CODE"
  http GET "$PLUGIN_BASE?page=customer_portal&view=home" '' "Cookie: dn_customer_session=$CVAL"
  # A record that has never accepted the terms is sent to the consent step first (302); that is the
  # session being recognised, not refused. Nothing here records consent on the customer's behalf.
  LOC="$(printf '%s' "$HTTP_HEADERS" | grep -i '^location:' | head -1 | tr -d '\r')"
  if [ "$HTTP_CODE" = "200" ]; then ok "E5 the portal page opens on the cookie: 200"
  elif [ "$HTTP_CODE" = "302" ] && printf '%s' "$LOC" | grep -q 'step=consent'; then ok "E5 the portal recognises the session and asks for consent first (302 to the consent step; nothing was accepted on the customer's behalf)"
  else bad "E5 portal → $HTTP_CODE ${LOC:+($LOC)}"; fi
  http POST "$PLUGIN_BASE?page=api&action=app_logout" '{}' "Cookie: dn_customer_session=$CVAL" "X-Requested-With: DishNet" "Origin: ${PLUGIN_BASE%%/crm/*}"
  [ "$HTTP_CODE" = "200" ] && ok "E6 app_logout: 200" || bad "E6 app_logout → $HTTP_CODE $(jfield "$HTTP_BODY" message)"
  http GET "$PLUGIN_BASE?page=api&action=app_me" '' "Cookie: dn_customer_session=$CVAL"
  [ "$HTTP_CODE" = "401" ] && ok "E6 the cookie after logout: 401 (revoked)" || bad "E6 the cookie still works after logout → $HTTP_CODE"
  unset SC CVAL
else stop "E4 app_verify_otp → $HTTP_CODE $(jfield "$HTTP_BODY" message) — a mistyped code is the usual cause; run again"; fi
# E7 the code appears in no log, no store row
NCL="$(docker logs "$CONTAINER" --since "$STARTED" 2>&1 | grep -c -F -- "$CODE" || true)"
[ "${NCL:-0}" = "0" ] && ok "E7 the code appears nowhere in the container log since $STARTED" || bad "E7 the code's digits appear in $NCL container-log line(s)"
ro_copy e7 || true
cat > "$SNIP/e7.php" <<'PHP'
<?php
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$code = (string)getenv('OTP_CODE'); $ident = strtolower((string)getenv('OTP_IDENT')); $since = (int)getenv('SINCE');
$hits = 0;
foreach ([["notification_audit_log", "preview LIKE ? OR error LIKE ?", 2], ["app_audit_log", "details LIKE ?", 1], ["notification_queue", "message LIKE ?", 1], ["wa_messages", "body LIKE ?", 1]] as [$t, $w, $n]) {
    try { $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE $w"); $st->execute(array_fill(0, $n, "%{$code}%")); $hits += (int)$st->fetchColumn(); } catch (Throwable $e) {}
}
try { $st = $db->prepare("SELECT COUNT(*) FROM app_otp_pending WHERE code = ?"); $st->execute([$code]); $hits += (int)$st->fetchColumn(); } catch (Throwable $e) {}
$audit = [];
try { $st = $db->prepare("SELECT action, COUNT(*) c FROM app_audit_log WHERE phone = ? AND at >= ? GROUP BY action"); $st->execute([$ident, $since]); foreach ($st as $r) $audit[] = $r['action'] . '×' . $r['c']; } catch (Throwable $e) {}
echo "hits={$hits} | audit=", implode(' ', $audit) ?: '(none)', "\n";
PHP
E7="$(cphp e7.php -e "RO_DB=$RO/e7.sqlite3" -e "OTP_CODE=$CODE" -e "OTP_IDENT=$EMAIL" -e "SINCE=$(date -u -d "$STARTED" +%s)" | tail -n1)"
HITS="${E7#hits=}"; HITS="${HITS%% |*}"
[ "${HITS:-?}" = "0" ] && ok "E7 the code appears in no notification, queue, conversation, pending or audit row" || bad "E7 the code appears in ${HITS} stored row(s)"
echo "  audit for this address since the start: ${E7#*audit=}"
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
unset CODE
# --- EMAIL END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "lead" ]; then
# --- LEAD BEGIN ---
hdr "L. One controlled eligibility refusal (the number must belong to a LEAD; nothing is sent to anyone)"
PHONE="$ARG"; PHONE_MASKED="$(printf '%s' "$PHONE" | sed -E 's/^(.*)([0-9]{3})$/…\2/')"   # POSIX ERE only: sed has no look-ahead
admin_token && ok "L0 an administrator token is on file (not printed)" || stop "no administrator token on file"
A="Authorization: Bearer $ADMIN_TOKEN"
http GET "$PLUGIN_BASE?page=api&action=staff_login_lookup&phone=$(printf '%s' "$PHONE" | sed 's/+/%2B/')" '' "$A"
[ "$HTTP_CODE" = "200" ] || stop "staff_login_lookup → $HTTP_CODE"
IDENT="$(jfield "$HTTP_BODY" identifier)"
N_ACC="$(printf '%s' "$HTTP_BODY" | grep -o '"refused_because"' | wc -l | tr -d ' ')"
ELIG="$(printf '%s' "$HTTP_BODY" | grep -o '"eligible":[a-z]*' | head -1 | sed 's/"eligible"://')"
WHY="$(jfield "$HTTP_BODY" refused_because)"
IS_LEAD="$(printf '%s' "$HTTP_BODY" | grep -o '"is_lead":[^,}]*' | head -1 | sed 's/"is_lead"://')"
echo "  identifier      $(printf '%s' "$IDENT" | sed -E 's/^(.*)([0-9]{3})$/…\2/')   accounts $N_ACC   eligible ${ELIG:-?}   refused_because ${WHY:-—}   is_lead ${IS_LEAD:-?}"
[ "${N_ACC:-0}" -ge 1 ] || stop "L1 the number matches no record — nothing was sent"
if [ "$ELIG" = "false" ] && [ "$WHY" = "lead" ]; then ok "L1 the record is a LEAD and the gate says it would be refused — safe to try the door"
else stop "L1 this number is NOT a refused lead (eligible=${ELIG:-?}, reason=${WHY:-none}) — a code would reach a real customer; nothing was sent"; fi
DEFAULTS="$(printf '%s' "$HTTP_BODY" | grep -o '"eligibility_defaults":{[^}]*}' | head -1)"
echo "  gates           ${DEFAULTS:-?}"
http POST "$PLUGIN_BASE?page=api&action=app_send_otp" "{\"phone\":\"$PHONE\"}"
[ "$HTTP_CODE" = "200" ] && [ "$(jfield "$HTTP_BODY" message)" = "Code sent via WhatsApp." ] && ok "L2 app_send_otp answered the UNIFORM 200 'Code sent via WhatsApp.' (a lead learns nothing)" || bad "L2 app_send_otp → $HTTP_CODE '$(jfield "$HTTP_BODY" message)' — not the uniform answer"
sleep 2
ro_copy l3 || stop "could not copy the store"
cat > "$SNIP/l3.php" <<'PHP'
<?php
$db = new PDO('sqlite:' . getenv('RO_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ident = (string)getenv('IDENT'); $since = (int)getenv('SINCE'); $digits = preg_replace('/\D/', '', $ident); $last9 = substr($digits, -9);
// a table that has never been created (no notification ever sent) counts as empty, not as an error
$c = function (string $sql, array $p) use ($db): int { try { $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); } catch (Throwable $e) { return stripos($e->getMessage(), 'no such table') !== false ? 0 : -1; } };
echo "otp_ineligible=", $c("SELECT COUNT(*) FROM app_audit_log WHERE action='otp_ineligible' AND phone=? AND at>=?", [$ident, $since]),
     " otp_sent=", $c("SELECT COUNT(*) FROM app_audit_log WHERE action='otp_sent' AND phone=? AND at>=?", [$ident, $since]),
     " pending=", $c("SELECT COUNT(*) FROM app_otp_pending WHERE phone=?", [$ident]),
     " notifications=", $c("SELECT COUNT(*) FROM notification_audit_log WHERE event='app_otp' AND (phone=? OR phone LIKE ?) AND sent_at>=?", [$digits, '%' . $last9, $since]),
     " queued=", $c("SELECT COUNT(*) FROM notification_queue WHERE event='app_otp' AND (phone=? OR phone LIKE ?)", [$digits, '%' . $last9]), "\n";
PHP
L3="$(cphp l3.php -e "RO_DB=$RO/l3.sqlite3" -e "IDENT=$IDENT" -e "SINCE=$(date -u -d "$STARTED" +%s)" | tail -n1)"
echo "  store           $L3"
case "$L3" in *"otp_ineligible=1"*) ok "L3 one otp_ineligible audit row for the lead — the gate fired";; *) bad "L3 no otp_ineligible row: $L3";; esac
case "$L3" in *"otp_sent=0"*) ok "L3 no otp_sent row";; *) bad "L3 an otp_sent row exists";; esac
case "$L3" in *"pending=0"*) ok "L3 no pending code was created";; *) bad "L3 a pending code exists — the gate did not stop the send";; esac
case "$L3" in *"notifications=0"*) ok "L3 no app_otp notification was recorded for this number since the start";; *) bad "L3 a notification row exists: $L3";; esac
case "$L3" in *"queued=0"*) ok "L3 nothing queued for this number";; *) bad "L3 a queued message exists";; esac
docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null || true
# --- LEAD END ---
fi

# ═════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "website" ]; then
# --- WEB BEGIN ---
hdr "W. The website — is the committed repoint live? (report only)"
WEB_COMMIT="$(git -C "$REPO" log -1 --format='%h %ad' --date=short -- dishnet-web-uganda 2>/dev/null || echo ?)"
echo "  repository      last website commit: $WEB_COMMIT"
http GET "$WEBSITE" ''
N_PORTAL="$(printf '%s' "$HTTP_BODY" | grep -o 'href="https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login"' | wc -l | tr -d ' ')"
N_UCRM="$(printf '%s' "$HTTP_BODY" | grep -o 'href="https://crm.dishnetuganda.com/crm/login"' | wc -l | tr -d ' ')"
if [ "$HTTP_CODE" != "200" ]; then bad "W the website answered $HTTP_CODE"
elif [ "${N_PORTAL:-0}" != "0" ] && [ "${N_UCRM:-0}" = "0" ]; then ok "W LIVE: the home page links the DishNet portal sign-in ($N_PORTAL links) and uCRM's login nowhere — the redeploy has happened"
elif [ "${N_UCRM:-0}" != "0" ]; then note "W NOT REDEPLOYED: the home page still links uCRM's login ($N_UCRM links; portal links $N_PORTAL) — redeploy in EasyPanel (project web, app web-uganda)"
else note "W the home page carries neither login link — read it yourself"; fi
# --- WEB END ---
fi

hdr "Summary"
echo "  mode     $MODE"
echo "  checks   $PASS ok, $FAIL failed, $NOTE notes"
[ "$FAIL" = "0" ] && echo "  Send this LOG FILE back (not a copy of the terminal)." || echo "  $FAIL FAILED — send the log file; nothing was deployed or changed."
[ "$FAIL" = "0" ]
