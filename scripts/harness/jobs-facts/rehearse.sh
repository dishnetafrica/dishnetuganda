#!/usr/bin/env bash
# Rehearse scripts/dnb-jobs-facts.sh (READ-ONLY facts for docs/43) against a fake install.
#
# A fake `docker` on PATH maps the container's paths into a sandbox, so the REAL script and the REAL report
# (scripts/lib/jobs_facts.php) run against a copy of this repository's plugin, a store seeded with CANARY values,
# a fake uCRM and a fake Evolution — all on 127.0.0.1. Asserted: the facts the report must show, that no canary
# (name, e-mail, number, title, address, token, key, instance name, host) reaches the output, that the live store
# is untouched and its copy removed, that uCRM and Evolution only ever see GET, and that each weakened copy of the
# script or the report fails at least one assertion. Refuses to run where the real server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; H="$R/scripts/harness/jobs-facts"
SCRIPT="${SCRIPT:-$R/scripts/dnb-jobs-facts.sh}"
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
map() { local s="$1"; s="${s//\/data\/ucrm\/data\/plugins/$F/plugins}"; s="${s//\/tmp\/dnj-ro-/$F/tmp/dnj-ro-}"; printf '%s' "$s"; }
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
mkdir -p "$SB/tmp"

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
UCRM_PORT="$(serve "$H/fake_ucrm.php" FAKE-UCRM-JOBS-FACTS 12100 FAKE_UCRM_SEED="$SB/ucrm_seed.json" FAKE_UCRM_LOG="$SB/ucrm.log")" || { echo "FAIL fake uCRM did not start"; exit 1; }
EVO_PORT="$(serve "$H/fake_evo.php" FAKE-EVO-JOBS-FACTS 12300 FAKE_EVO_LOG="$SB/evo.log")" || { echo "FAIL fake Evolution did not start"; exit 1; }

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
}
run() { PATH="$SB/bin:$PATH" FAKE="$F" bash "${1:-$SCRIPT}" 2>&1; }
canaries() {  # $1 output — no canary of any kind
  local out="$1" c
  hasnt "$out" "canary" "no canary word (names, e-mails, titles, addresses, previews, keys, hosts, instances)"
  while read -r c; do [ -n "$c" ] && hasnt "$out" "$c" "no staff e-mail taken from the hard-coded map"; done < "$SB/ucrm_seed.json.canaries"
  for c in 771234567 "771 234 567" 772345678 "772 345 678" 912345678 "912 345 678" 773456789 "773 456 789" 774567890 "774 567 890" 775000007 "775 000 007" 771000001 "771 000 001"; do
    hasnt "$out" "$c" "no phone digits ($c)"
  done
  hasnt "$out" "9f9f9f9f" "no token fragment"
}
readonly_proof() {  # the live store untouched, its copy gone, uCRM and Evolution asked with GET only
  check "$(sha256sum "$F/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3" | cut -c1-64)" "$LIVE_SUM" "the live store is byte-identical afterwards"
  check "$(find "$F/tmp" -maxdepth 1 -name 'dnj-ro-*' | wc -l | tr -d ' ')" "0" "the store copy was removed"
  check "$(grep -c -v '^GET ' "$SB/ucrm.log" | tr -d ' ')" "0" "uCRM saw GET only ($(wc -l < "$SB/ucrm.log" | tr -d ' ') requests)"
  check "$(grep -c -v '^GET /instance/connectionState/' "$SB/evo.log" | tr -d ' ')" "0" "Evolution saw connection-state reads only"
  has "$(cat "$F/docker.log")" "RO_DIR=/tmp/dnj-ro-" "the report was pointed at the copy, not the live store"
}

echo; echo "S1  the normal run"
build normal "http://127.0.0.1:$UCRM_PORT"
OUT="$(run)"; RC=$?
[ -n "${DUMP:-}" ] && printf '%s\n' "$OUT" > "$DUMP"   # DUMP=<file> keeps the normal run's output to read
check "$RC" "0" "exit 0"
has "$OUT" "ok    the report ran to its end" "the report completes"
has "$OUT" "ok    timezone Africa/Kampala" "Kampala timezone reported"
has "$OUT" "S1   id 1    role admin" "staff appear as S-labels with role"
has "$OUT" "phone +256 international" "an international number shows its country code only"
has "$OUT" "phone national 0… (goes out with no country code)" "a national-form number is called out"
has "$OUT" "phone +211 international" "a South Sudan number is recognised"
has "$OUT" "hard-coded South Sudan maps force: deploy → 1 · page load → 1 · My Jobs → 1 · Clear Cache → 1 · auto-map → 1" "S1 forced to 1 by all five maps"
has "$OUT" "deploy → 1581" "S5 forced to 1581"
has "$OUT" "uCRM id 1: users/admins/1 → 200 · users/1 → 404 · same e-mail as this row: yes · phone field: absent" "uCRM user 1: real, same e-mail, no phone field; the webhook's endpoint 404"
has "$OUT" "uCRM id 81: users/admins/81 → 200 · users/81 → 404 · same e-mail as this row: yes · phone field: empty" "uCRM user 81: phone field present but empty"
has "$OUT" "uCRM id 1581: users/admins/1581 → 404 · users/1581 → 404 · no such uCRM user" "1581 is no Uganda uCRM user"
has "$OUT" "CRM LINKED 0/5 (FTTH CRM client link)" "the tile, computed as the page does"
has "$OUT" "ids 1 · organisation 7 (where the FTTH CRM link would be created) exists: no" "organisation 7 checked (ids only)"
has "$OUT" "offered in My Jobs → New Job (active support/leader/engineer/admin role + uCRM id): S1, S4, S5" "who the New Job picker offers"
has "$OUT" "app sign-in token yes, issued 100 day(s) ago (expired: 90-day limit)" "an expired app token is called out"
has "$OUT" "must change password (PWD badge) yes" "the PWD badge"
has "$OUT" "commission agent (AGENT badge) yes" "the AGENT badge (is_employee false)"
has "$OUT" "3 user(s) · with a phone field 2 · with a phone 1" "uCRM staff users counted"
has "$OUT" "same e-mail as a staff row: 1=S1, 81=S4 · no staff row: 7" "uCRM users matched to staff rows"
has "$OUT" "4 job(s) returned (limit 500) · by status (0 pending, 1 open, 2 closed) 0:1, 1:2, 2:1" "jobs by status"
has "$OUT" "by assigned uCRM user 1:1=S1, 81:1=S4, 99:1=no staff row, none:1" "jobs by assignee"
has "$OUT" "with a client 3" "jobs with a client"
has "$OUT" "installation 1" "title classes (no title printed)"
has "$OUT" "field names of one job (detail): id, title, description, clientId" "job detail field names"
has "$OUT" "job_assigned                       sent 2 · failed 1" "Message Log job_assigned counts"
has "$OUT" "holds 5 row(s) of every kind" "Message Log positive control"
has "$OUT" "failure queue job_assigned failed 1" "failure queue counted"
has "$OUT" 'job.add lines 3: "notification sent" 1 · "No phone found" 1 · "No user assigned" 1 · job.edit 1' "webhook log counted"
has "$OUT" "ours: active yes · events any · job.add delivered yes · job.edit delivered yes · route reaches webhook.php" "our uCRM endpoint described"
has "$OUT" "2 endpoint(s), 1 of them this plugin's" "endpoints counted"
has "$OUT" "channels with an instance: account, support" "Evolution channels"
has "$OUT" "support open" "support connection state"
has "$OUT" "job_assign scheduled no · staff_jobs scheduled yes" "the dispatch cron is not scheduled"
has "$OUT" "last run of staff_jobs: 2026-09-27 07:00:04, 12 ms" "daily summary last run"
has "$OUT" 'staff_jobs ran 2 time(s), ERROR 2, last 2026-09-27 07:00:04: CrmApiClient::__construct(): Argument #1 ($baseUrl) must be of type string, array given' "the summary's error from the plugin log"
has "$OUT" "staff numbers with a customer conversation: 1 (S2 on support)" "a staff number filed as a customer conversation"
has "$OUT" "AI replies queued for a staff number: 1" "AI queued for a staff number"
has "$OUT" "job completions 1 · invoice queue 1 (pending 1)" "local job records"
canaries "$OUT"
readonly_proof

echo; echo "S2  neither a timezone nor a tenant profile set (the plugin then runs on Africa/Juba, like South Sudan)"
build juba "http://127.0.0.1:$UCRM_PORT"
OUT="$(run)"; RC=$?
check "$RC" "0" "exit 0"
has "$OUT" "note  timezone Africa/Juba — the technician's job message prints the day and hour in this zone, not Kampala's" "Juba is a note, never an ok"
hasnt "$OUT" "ok    timezone" "no ok line for the timezone"
readonly_proof

echo; echo "S3  uCRM unreachable"
build normal "http://127.0.0.1:1"
OUT="$(run)"; RC=$?
check "$RC" "0" "exit 0 — the store facts still come"
has "$OUT" "users/admins → 0 (no list)" "uCRM users: no list, said so"
has "$OUT" "scheduling/jobs → 0 (no list)" "jobs: no list, said so"
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
mutant() {  # $1 name  $2 file to break (script|report)  $3 python transformation  $4 scenario
  local name="$1" what="$2" py="$3" scen="${4:-normal}" copyR copyS before
  copyR="$SB/m/scripts/lib"; copyS="$SB/m/scripts"; rm -rf "$SB/m"; mkdir -p "$copyR"
  cp "$SCRIPT" "$copyS/dnb-jobs-facts.sh"; cp "$R/scripts/lib/jobs_facts.php" "$copyR/jobs_facts.php"
  local target="$copyS/dnb-jobs-facts.sh"; [ "$what" = "report" ] && target="$copyR/jobs_facts.php"
  python3 - "$target" <<PY || { FAILN=$((FAILN+1)); echo "  FAIL mutant $name: anchor not found"; return; }
import sys
p = sys.argv[1]; s = open(p).read()
$py
open(p, 'w').write(s)
PY
  build "$scen" "http://127.0.0.1:$UCRM_PORT"
  before=$FAILN
  local out; out="$(PATH="$SB/bin:$PATH" FAKE="$F" bash "$copyS/dnb-jobs-facts.sh" 2>&1)"
  { canaries "$out"; readonly_proof
    if [ "$scen" = "juba" ]; then has "$out" "note  timezone Africa/Juba" "Juba noted"; fi; } >/dev/null
  if [ "$FAILN" -gt "$before" ]; then FAILN=$before; PASS=$((PASS+1)); echo "  ok   weakened copy caught: $name"
  else FAILN=$((before+1)); echo "  FAIL weakened copy NOT caught: $name"; fi
}
echo; echo "S5  weakened copies"
mutant "the report prints staff names" report "
a = \"    out('        uCRM user id ' . (\$uid ?: 'not set')\"
assert s.count(a) == 1; s = s.replace(a, \"    out('        name ' . (string)(\$r['name'] ?? ''));\n\" + a)"
mutant "the report prints job titles" report "
a = \"        \$t = strtolower((string)(\$j['title'] ?? ''));\"
assert s.count(a) == 1; s = s.replace(a, a + \"\\n        out('  title ' . (string)(\$j['title'] ?? ''));\")"
mutant "the report prints the webhook address" report "
a = \"        \$list = (array)(\$ep['eventTypes'] ?? []);\"
assert s.count(a) == 1; s = s.replace(a, a + \"\\n        out('  url ' . (string)(\$ep['url'] ?? ''));\")"
mutant "the report is pointed at the live store" script "
a = '-e \"RO_DIR=\$RO\"'
assert s.count(a) == 1; s = s.replace(a, '-e \"RO_DIR=\$PDD\"')"
mutant "the report writes to uCRM" report "
a = \"\$jobs = \$crm->isConfigured() ? \$crm->get('scheduling/jobs?limit=500') : null;\"
assert s.count(a) == 1; s = s.replace(a, \"\$crm->post('scheduling/jobs', ['title' => 'x']);\n\" + a)"
mutant "the copy is never removed" script "
a = 'trap cleanup EXIT'
assert s.count(a) == 1; s = s.replace(a, ': no cleanup')"
mutant "any timezone reported as Kampala" script "
a = '  Africa/Kampala*) ok'
assert s.count(a) == 1; s = s.replace(a, '  *) ok')" juba

# The mask is the backstop: with it in place, a number the report wrongly prints is still masked (the control).
rm -rf "$SB/m"; mkdir -p "$SB/m/scripts/lib"; cp "$SCRIPT" "$SB/m/scripts/"; cp "$R/scripts/lib/jobs_facts.php" "$SB/m/scripts/lib/"
python3 - "$SB/m/scripts/lib/jobs_facts.php" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
a = "    out('        uCRM user id ' . ($uid ?: 'not set')"
assert s.count(a) == 1; s = s.replace(a, "    out('        number ' . (string)($r['phone'] ?? '') . ' mail ' . (string)($r['email'] ?? ''));\n" + a)
open(p, 'w').write(s)
PY
build normal "http://127.0.0.1:$UCRM_PORT"
OUT="$(PATH="$SB/bin:$PATH" FAKE="$F" bash "$SB/m/scripts/dnb-jobs-facts.sh" 2>&1)"
echo; echo "S6  control: the mask alone keeps a leaked number and e-mail out"
has "$OUT" "number <number>" "a leaked number is masked"
has "$OUT" "mail <email>" "a leaked e-mail is masked"
canaries "$OUT"
# …and the 'mask removed' weakening above differs from this control only by the mask: prove the mask was the reason.
python3 - "$SB/m/scripts/dnb-jobs-facts.sh" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
a = "| mask)\""
assert s.count(a) == 1; s = s.replace(a, ")\"")
open(p, 'w').write(s)
PY
OUT="$(PATH="$SB/bin:$PATH" FAKE="$F" bash "$SB/m/scripts/dnb-jobs-facts.sh" 2>&1)"
has "$OUT" "number +256 771 000 001" "control on the control: without the mask the same leak shows"

echo; printf '%d passed, %d failed\n' "$PASS" "$FAILN"
[ "$FAILN" -eq 0 ]
