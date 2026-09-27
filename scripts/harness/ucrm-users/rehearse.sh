#!/usr/bin/env bash
# Rehearse scripts/dnb-ucrm-users-facts.sh (READ-ONLY: Uganda's uCRM staff users, docs/44) against a fake install.
#
# A fake `docker` on PATH maps the container's paths into a sandbox, so the REAL script and the REAL report
# (scripts/lib/ucrm_users_facts.php) run against a copy of this repository's plugin, a store seeded with CANARY values,
# a fake uCRM and a fake Evolution — all on 127.0.0.1. Asserted: the facts the report must show, that no canary
# (name, username, e-mail, number, amount, host, token, instance name) reaches the output, that the live store is
# untouched and its copy removed, that uCRM and Evolution only ever see GET, and that each weakened copy of the
# script or the report fails at least one assertion. Refuses to run where the real server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/ucrm-users"
SCRIPT="${SCRIPT:-$R/scripts/dnb-ucrm-users-facts.sh}"
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx ucrm; then echo "refusing: a real ucrm container runs here"; exit 2; fi
for v in http_proxy https_proxy HTTP_PROXY HTTPS_PROXY ALL_PROXY all_proxy DN_VAULT_FILE DN_DATA_DIR; do unset "$v"; done
SB="$(mktemp -d)"
# serve() runs inside $( ), a subshell, so the servers' pids go to a file the trap reads.
trap '[ -f "$SB/pids" ] && xargs -r kill < "$SB/pids" 2>/dev/null; rm -rf "$SB"' EXIT
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { if printf '%s' "$1" | grep -qF -- "$2"; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (missing '$2')"; fi; }
hasnt() { if printf '%s' "$1" | grep -qiF -- "$2"; then FAILN=$((FAILN+1)); echo "  FAIL $3 (found '$2')"; else PASS=$((PASS+1)); echo "  ok   $3"; fi; }

# ── the fake docker ────────────────────────────────────────────────────────
mkdir -p "$SB/bin"
cat > "$SB/bin/docker" <<'STUB'
#!/usr/bin/env bash
F="$FAKE"
map() { local s="$1"; s="${s//\/data\/ucrm\/data\/plugins/$F/plugins}"; s="${s//\/tmp\/dnu-ro-/$F/tmp/dnu-ro-}"; printf '%s' "$s"; }
[ "$1" = "exec" ] || exit 0
shift; W=""; ENVS=()
echo "exec $*" >> "$F/docker.log"
while [ $# -gt 0 ]; do case "$1" in
  -i) shift;; -u) shift 2;; -w) W="$(map "$2")"; shift 2;; -e) ENVS+=("$(map "$2")"); shift 2;; *) break;; esac; done
shift   # the container
args=(); for a in "$@"; do args+=("$(map "$a")"); done
[ -n "$W" ] && cd "$W"
for e in "${ENVS[@]}"; do export "$e"; done
exec "${args[@]}"
STUB
chmod +x "$SB/bin/docker"

serve() {  # $1 router  $2 marker  $3 base port  … env pairs
  local router="$1" marker="$2" base="$3"; shift 3; local p i
  for i in 0 1 2 3 4 5 6 7 8 9; do
    p=$(( base + ( $$ + i * 7 ) % 90 ))
    env "$@" php -S "127.0.0.1:$p" "$router" >/dev/null 2>&1 & local pid=$!
    for _ in $(seq 1 40); do sleep 0.1; curl -s --max-time 1 "http://127.0.0.1:$p/__ping" 2>/dev/null | grep -q "$marker" && { echo "$pid" >> "$SB/pids"; echo "$p"; return 0; }; done
    kill "$pid" 2>/dev/null
  done
  return 1
}
UCRM_PORT="$(serve "$H/fake_ucrm.php" FAKE-UCRM-USERS-FACTS 12500 FAKE_UCRM_SEED="$SB/ucrm_seed.json" FAKE_UCRM_LOG="$SB/ucrm.log")" || { echo "FAIL fake uCRM did not start"; exit 1; }
EVO_PORT="$(serve "$H/fake_evo.php" FAKE-EVO-USERS-FACTS 12700 FAKE_EVO_LOG="$SB/evo.log")" || { echo "FAIL fake Evolution did not start"; exit 1; }

# ── a fresh fake install per scenario ─────────────────────────────────────
F="$SB/fake"
build() {  # $1 scenario  $2 uCRM url
  rm -rf "$F"; mkdir -p "$F/plugins" "$F/tmp"
  cp -R "$R/dishnet-hybrid-sudan" "$F/plugins/dishnet-hybrid-sudan"
  rm -rf "$F/plugins/dishnet-hybrid-sudan/data" "$F/plugins/dishnet-hybrid-sudan/tests"
  php "$H/seed.php" "$F/plugins/dishnet-hybrid-sudan" "$F/plugins/.dishnet-hybrid-sudan-data" "$2" "http://127.0.0.1:$EVO_PORT" "$SB/ucrm_seed.json" "$1" >/dev/null \
    || { echo "FAIL seeding ($1)"; exit 1; }
  : > "$SB/ucrm.log"; : > "$SB/evo.log"; : > "$F/docker.log"
  LIVE_SUM="$(sha256sum "$F/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3" | cut -c1-64)"
  CFG_SUM="$(sha256sum "$F/plugins/.dishnet-hybrid-sudan-data/kyc_config.json" | cut -c1-64)"
}
run() { PATH="$SB/bin:$PATH" FAKE="$F" bash "${1:-$SCRIPT}" 2>&1; }
canaries() {  # $1 output — no canary of any kind
  local out="$1" c
  hasnt "$out" "canary" "no canary word (names, usernames, e-mails, hosts, instances, tokens)"
  for c in 912345678 "912 345 678" 771234567 "771 234 567" 772345678 "772 345 678" 773456789 "773 456 789" 775000007 "775 000 007"; do
    hasnt "$out" "$c" "no phone digits ($c)"
  done
  hasnt "$out" "98765" "no amount"
  hasnt "$out" "2026-09-12" "no date read from uCRM (client)"
  hasnt "$out" "2026-09-20" "no date read from uCRM (invoice)"
  hasnt "$out" "#e53935" "no avatar colour"
}
readonly_proof() {  # the live store and settings untouched, the copy gone, uCRM and Evolution asked with GET only
  check "$(sha256sum "$F/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3" | cut -c1-64)" "$LIVE_SUM" "the live store is byte-identical afterwards"
  check "$(sha256sum "$F/plugins/.dishnet-hybrid-sudan-data/kyc_config.json" | cut -c1-64)" "$CFG_SUM" "the settings file is byte-identical afterwards"
  check "$(find "$F/tmp" -maxdepth 1 -name 'dnu-ro-*' | wc -l | tr -d ' ')" "0" "the store copy was removed"
  check "$(grep -c -v '^GET ' "$SB/ucrm.log" | tr -d ' ')" "0" "uCRM saw GET only ($(wc -l < "$SB/ucrm.log" | tr -d ' ') requests)"
  check "$(grep -c -v '^GET /webhook/find/' "$SB/evo.log" | tr -d ' ')" "0" "Evolution saw webhook-settings reads only"
  has "$(cat "$F/docker.log")" "RO_DIR=/tmp/dnu-ro-" "the report was pointed at the copy, not the live store"
}
keyfacts() {  # $1 output — the facts a weakened copy would lose (normal scenario)
  has "$1" "phone-like fields (list + detail): contacts.*.phone (+256 international / empty)" "a phone held only in the DETAIL record is found, nested"
  has "$1" "3 user(s) · active 2 · with a phone-like field 1 · with a number in it 1" "users counted"
  has "$1" "same e-mail in uCRM: U1 (id 1000)" "the admin account matches uCRM user 1000, whatever the case of the e-mail"
  has "$1" "stored uCRM ids that are real uCRM users: 0 of 4" "no stored id is real"
}

echo; echo "S1  three uCRM users: a match, a phone in the detail record only, an inactive match"
build normal "http://127.0.0.1:$UCRM_PORT"
OUT="$(run)"; RC=$?
[ -n "${DUMP:-}" ] && printf '%s\n' "$OUT" > "$DUMP"   # DUMP=<file> keeps the normal run's output to read
check "$RC" "0" "exit 0"
has "$OUT" "ok    the report ran to its end" "the report completes"
has "$OUT" "tenant profile uganda (selected by the currency UGX; no tenant_profile setting)" "the profile and why"
has "$OUT" "plugin timezone Africa/Kampala (UTC+03:00, from the uganda profile)" "the zone and where it came from"
has "$OUT" "U1   id 1000   active yes UISP-linked yes same e-mail as a staff account: S1" "U1: id, active, UISP link, the staff match"
has "$OUT" "list record fields: id, unmsId, email, firstName, lastName, username, avatarColor, isActive" "field NAMES of the list record"
has "$OUT" "detail GET users/admins/1000 → 200 · fields beyond the list record: permissions" "the detail record's extra field names"
has "$OUT" "GET users/1000 (the address the job.add handler reads) → 404" "users/{id} for a REAL id: 404"
has "$OUT" "GET users/1007 (the address the job.add handler reads) → 200" "users/{id} reported as answered when it answers"
has "$OUT" "U3   id 1009   active no  UISP-linked no  same e-mail as a staff account: S4" "an inactive uCRM user, matched"
has "$OUT" "same e-mail in uCRM: U3 (id 1009, INACTIVE)" "an inactive match is called out"
has "$OUT" "same e-mail in uCRM: none — no uCRM user has this e-mail" "a staff account with no uCRM user"
has "$OUT" "stored uCRM id 1 (no such uCRM user)" "the admin account's stored id is not a uCRM user"
has "$OUT" "stored uCRM id 1581 (no such uCRM user)" "1581 is not a uCRM user"
has "$OUT" "e-mails shared by two staff accounts: 0 · by two uCRM users: 0" "duplicates counted"
has "$OUT" "setting bidal_ucrm_user_id (second-site KYC jobs): 1007 — a real uCRM user" "a real id in a setting"
has "$OUT" "setting accountant_ucrm_user_id (fibre-install accountant): 55 — no such uCRM user" "a false id in a setting"
has "$OUT" "a client's registration date: +0300" "uCRM's offset from a client (offset only)"
has "$OUT" "an invoice's created date: +0300" "uCRM's offset from an invoice (offset only)"
has "$OUT" "a payment's created date: no timestamp to read" "no payment: said so"
has "$OUT" "followup_enabled: off" "follow-ups switch"
has "$OUT" "support: enabled yes · points at this plugin yes · events CONNECTION_UPDATE, MESSAGES_UPDATE, MESSAGES_UPSERT · delivery receipts (MESSAGES_UPDATE) subscribed" "receipts subscribed on support"
has "$OUT" "account: enabled yes · points at this plugin no · events MESSAGES_UPSERT · delivery receipts (MESSAGES_UPDATE) NOT subscribed" "the nested shape read; receipts not subscribed"
has "$OUT" "sales: webhook settings → 404 (not read)" "an instance Evolution does not know"
has "$OUT" "ok    tenant profile uganda" "summary: profile"
has "$OUT" "note  uCRM has 3 staff user(s), 2 active" "summary: users"
has "$OUT" "note  1 uCRM user record(s) hold a phone number" "summary: a phone in uCRM, and it is still not used"
has "$OUT" "note  2 of 5 staff account(s) share an e-mail with a uCRM user" "summary: matches"
has "$OUT" "note  0 of 4 stored uCRM user id(s) belong to a real uCRM user" "summary: stored ids"
has "$OUT" "ok    uCRM writes its times in UTC+03:00, Kampala's offset" "summary: uCRM's clock"
has "$OUT" "note  delivery receipts (MESSAGES_UPDATE) are subscribed on 1 of 3 WhatsApp number(s)" "summary: receipts"
keyfacts "$OUT"
canaries "$OUT"
readonly_proof

echo; echo "S2  production's shape (27 Sep 15:54 UTC): one uCRM user, no phone anywhere"
build prod "http://127.0.0.1:$UCRM_PORT"
OUT="$(run)"; RC=$?
check "$RC" "0" "exit 0"
has "$OUT" "1 user(s) · active 1 · with a phone-like field 0 · with a number in it 0" "one user, no phone field"
has "$OUT" "note  no uCRM user record holds a phone number (0 carry a phone-like field)" "summary: the number must come from the staff account"
has "$OUT" "note  1 of 5 staff account(s) share an e-mail with a uCRM user" "summary: only the admin account matches"
has "$OUT" "setting bidal_ucrm_user_id (second-site KYC jobs): not set" "an unset setting"
canaries "$OUT"
readonly_proof

echo; echo "S3  uCRM unreachable"
build normal "http://127.0.0.1:1"
OUT="$(run)"; RC=$?
check "$RC" "0" "exit 0 — the store facts still come"
has "$OUT" "users/admins → 0 (no list; nothing below about uCRM users can be read)" "no list, said so"
has "$OUT" "same e-mail in uCRM: not checked: no uCRM list" "no match claimed without a list"
has "$OUT" "stored uCRM id 81 (not checked: no uCRM list)" "no verdict on a stored id without a list"
has "$OUT" "ok    the report ran to its end" "still completes"
canaries "$OUT"

echo; echo "S4  no store"
build normal "http://127.0.0.1:$UCRM_PORT"
rm -f "$F/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3"*
OUT="$(run)"; RC=$?
check "$RC" "1" "exit 1"
has "$OUT" "STOP: no store at" "stops before anything else"
check "$(grep -c 'RO_DIR' "$F/docker.log" | tr -d ' ')" "0" "the report was never started"

# ── weakened copies: each must fail at least one assertion ──────────────────
mutant() {  # $1 name  $2 file to break (script|report)  $3 python transformation
  local name="$1" what="$2" py="$3" copyR copyS before
  copyR="$SB/m/scripts/lib"; copyS="$SB/m/scripts"; rm -rf "$SB/m"; mkdir -p "$copyR"
  cp "$SCRIPT" "$copyS/dnb-ucrm-users-facts.sh"; cp "$R/scripts/lib/ucrm_users_facts.php" "$copyR/ucrm_users_facts.php"
  local target="$copyS/dnb-ucrm-users-facts.sh"; [ "$what" = "report" ] && target="$copyR/ucrm_users_facts.php"
  python3 - "$target" <<PY || { FAILN=$((FAILN+1)); echo "  FAIL mutant $name: anchor not found"; return; }
import sys
p = sys.argv[1]; s = open(p).read()
$py
open(p, 'w').write(s)
PY
  build normal "http://127.0.0.1:$UCRM_PORT"
  before=$FAILN
  local out; out="$(PATH="$SB/bin:$PATH" FAKE="$F" bash "$copyS/dnb-ucrm-users-facts.sh" 2>&1)"
  { canaries "$out"; readonly_proof; keyfacts "$out"; } >/dev/null
  if [ "$FAILN" -gt "$before" ]; then FAILN=$before; PASS=$((PASS+1)); echo "  ok   weakened copy caught: $name"
  else FAILN=$((before+1)); echo "  FAIL weakened copy NOT caught: $name"; fi
}
echo; echo "S5  weakened copies"
mutant "the report prints uCRM users' names" report "
a = \"        out('        list record fields: '\"
assert s.count(a) == 1; s = s.replace(a, \"        out('        name ' . (string)(\$u['firstName'] ?? '') . ' ' . (string)(\$u['lastName'] ?? ''));\n\" + a)"
mutant "the report prints uCRM users' e-mails" report "
a = \"        out('        list record fields: '\"
assert s.count(a) == 1; s = s.replace(a, \"        out('        user ' . str_replace('@', ' at ', (string)(\$u['email'] ?? '')));\n\" + a)"
mutant "the report prints a phone number instead of its form" report "
a = \"        \$forms = array_values(array_unique(array_map('phoneForm', \$vals)));\"
assert s.count(a) == 1; s = s.replace(a, \"        \$forms = array_values(array_unique(array_map('strval', \$vals)));\")"
mutant "the report prints the WhatsApp webhook address" report "
a = \"        \$sub = in_array('MESSAGES_UPDATE'\"
assert s.count(a) == 1; s = s.replace(a, \"        out('  address ' . str_replace(['https://', '?'], ['', ' '], \$url));\n\" + a)"
mutant "the report prints the dates it reads" report "
a = \"    if (\$o !== '') \$offsets[] = \$o;\"
assert s.count(a) == 1; s = s.replace(a, a + \"\\n    if (is_array(\$rec)) out('  read ' . (string)(\$rec[\$field] ?? ''));\")"
mutant "the report skips the detail record" report "
a = \"        \$det = \$crm->get(\\\"users/admins/{\$id}\\\");\"
assert s.count(a) == 1; s = s.replace(a, \"        \$det = null;\")"
mutant "the report is pointed at the live store" script "
a = '-e \"RO_DIR=\$RO\"'
assert s.count(a) == 1; s = s.replace(a, '-e \"RO_DIR=\$PDD\"')"
mutant "the report writes to uCRM" report "
a = \"\$admins = \$crm->isConfigured() ? \$crm->get('users/admins') : null;\"
assert s.count(a) == 1; s = s.replace(a, \"\$crm->post('users/admins', ['email' => 'x']);\n\" + a)"
mutant "the report changes a WhatsApp webhook" report "
a = \"        \$res = \$evo->findWebhook(\$inst);\"
assert s.count(a) == 1; s = s.replace(a, \"        \$evo->setWebhook(\$inst, 'https://example.invalid/x');\n\" + a)"
mutant "the report matches e-mails case-sensitively" report "
a = \"        \$em = strtolower(trim((string)(\$u['email'] ?? '')));\"
assert s.count(a) == 1; s = s.replace(a, \"        \$em = trim((string)(\$u['email'] ?? ''));\")"
mutant "the copy is never removed" script "
a = 'trap cleanup EXIT'
assert s.count(a) == 1; s = s.replace(a, ': no cleanup')"

# The mask is the backstop: with it in place, a number, an e-mail and an address the report wrongly prints are still
# masked (the control), and without the mask the same leak shows (the control on the control).
rm -rf "$SB/m"; mkdir -p "$SB/m/scripts/lib"; cp "$SCRIPT" "$SB/m/scripts/"; cp "$R/scripts/lib/ucrm_users_facts.php" "$SB/m/scripts/lib/"
python3 - "$SB/m/scripts/lib/ucrm_users_facts.php" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
a = "    out('        same e-mail in uCRM: ' . $prop);"
assert s.count(a) == 1
s = s.replace(a, a + "\n    out('        leak number ' . (string)($r['phone'] ?? '') . ' mail ' . (string)($r['email'] ?? '') . ' site https://leak.example/x');")
open(p, 'w').write(s)
PY
build normal "http://127.0.0.1:$UCRM_PORT"
OUT="$(PATH="$SB/bin:$PATH" FAKE="$F" bash "$SB/m/scripts/dnb-ucrm-users-facts.sh" 2>&1)"
echo; echo "S6  control: the mask alone keeps a leaked number, e-mail and address out"
has "$OUT" "leak number <number> mail <email> site <url>" "a leaked number, e-mail and address are masked"
canaries "$OUT"
python3 - "$SB/m/scripts/dnb-ucrm-users-facts.sh" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
a = "| mask)\""
assert s.count(a) == 1; s = s.replace(a, ")\"")
open(p, 'w').write(s)
PY
OUT="$(PATH="$SB/bin:$PATH" FAKE="$F" bash "$SB/m/scripts/dnb-ucrm-users-facts.sh" 2>&1)"
has "$OUT" "leak number +256 772 345 678" "control on the control: without the mask the same leak shows"

echo; printf '%d passed, %d failed\n' "$PASS" "$FAILN"
[ "$FAILN" -eq 0 ]
