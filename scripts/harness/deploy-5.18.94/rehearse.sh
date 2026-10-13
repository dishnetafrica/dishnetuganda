#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.94.sh — the customer portal's Terms and Privacy without the UCC claim, the Installation
# Terms v1.1, the UCC defaults removed (docs/07 12 Oct; root docs/67 §K) — before the operator runs it.
#
# The sandbox is the quarantine's rehearsal's, built by its own code: scripts/harness/quarantine-stray-store/rehearse.sh,
# read up to its first stage-A section (the cut asserted: its anchor exactly once) and run here as it is — 5.18.92 as
# production ran it since 10 Oct, the salesperson pilot as the operator left it, the stray store inside the plugin folder,
# 5.18.92's record, the REAL deploy of 5.18.93 and its day-later --after-only, the plugin's files the server's owner's
# (1000:1000). On it, in the runbook's order: the REAL quarantine (QUARANTINE typed at its prompt), its --check, and then
# this release's script — through a pseudo-terminal, its word typed, as the operator runs it.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.94, cut on 69c87a6 (5.18.93); the release's files are
#     exactly the reviewed set — sixteen taken whole from the development branch's change, one added, the manifest and
#     five version pins 5.18.93's with the version alone changed; master.php, main.php, the retry job and its gate
#     5.18.93's byte for byte; nothing outside the plugin; the header's deploy command stands alone (0);
#   · A14: nothing is deployed before the quarantine has moved the file and a --check that began 20 minutes or more after
#     the move PASSED — with no record, before its --check, over a --check begun too soon or not conclusive, while a run
#     holds the quarantine's lock, over a failure, a put-back, a stage not done, a database or side file at the old name,
#     a store's first-start file, another data folder, a kept file the record does not describe; each refusal changes
#     nothing, and a copy blind to each clause reaches the question (caught); a state file recording an R19 failure after
#     the move is said, not refused (1);
#   · A17–A21: the documents and records as the pin's and the live code read them on this server's PHP — a pin whose
#     Uganda documents say UCC, carry a wrong date or lose an override refuses; a record that would not match its terms
#     under the pin's code refuses; a pending v1.0 request is said; the operator's three settings saying UCC in either
#     copy, the badge shown included, refuse, and never print a value; the recordings' retention outside 1–365 refuses;
#     the website without the 12 Oct Terms refuses, unreachable is a note; a date before today in Kampala refuses, today
#     is ok, later a note; each refusal changes nothing, and a copy blind to each reaches the question (caught) (2);
#   · the deploy PASSES and installs exactly the pin; V13 reads the public Terms and Privacy at 1.2 with no "UCC", R22 the
#     installed documents and records; the quarantine untouched — the kept file, the old name, the record; R19 not yet
#     conclusive; the rollback command printed once, after the verdict (3);
#   · --after-only: R19 conclusive once master has run the retry job after the copy; the quarantine's own --check PASSES
#     with 5.18.94 live; a database made at the old name FAILS R19, is recorded and fails every later run, and a copy that
#     forgets it passes (caught) (4);
#   · the rollback PASSES: 5.18.93's documents back (1.1, 26 September), a v1.1 record said with its remedy, the
#     quarantine still holding; deployed again over it, a v1.1 record the live code cannot read is said, not refused (5);
#   · nothing reaches Evolution; the clone, /tmp and the checkout are left as found (6).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious.
#
#   bash scripts/harness/deploy-5.18.94/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H94_DIR="$(cd "$(dirname "$0")" && pwd)"
Q_HARNESS="$H94_DIR/../quarantine-stray-store/rehearse.sh"
Q_ANCHOR='echo; echo "== Q1. stage A refuses, each clause on its own, before anything changes =="'
H94_PREFIX="$(mktemp)"
python3 - "$Q_HARNESS" "$H94_PREFIX" "$Q_ANCHOR" <<'PY' || { echo "refusing: the quarantine rehearsal could not be cut at its stage-A section"; rm -f "$H94_PREFIX"; exit 2; }
import sys
src, dst, anchor = sys.argv[1], sys.argv[2], sys.argv[3]
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 800, at[0]                  # the sandbox, the 5.18.93 deploy and the quarantine's pinned copy come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# The clone in one pack, as a fresh clone of the remote is: Git's short form follows the object count (8 characters from
# about 16,000), and a local clone keeps this checkout's duplicate packs and loose objects (12 Oct: 21,920 counted, 13,064
# once repacked). The server's checkout recorded 69c87a6 on 11 Oct — the 7-character form.
clone = 'git clone -q --no-hardlinks "$R" "$SB/repo" || { echo "cannot clone $R"; exit 1; }\n'
assert pre.count(clone) == 1, pre.count(clone)
pre = pre.replace(clone, clone + 'git -C "$SB/repo" repack -a -d -q && git -C "$SB/repo" prune-packed || { echo "cannot repack the clone"; exit 1; }\n')
for need in ('own_server\n', 'QP="$(qpin qpin)"', 'qrun() {', 'set_sched() {', 'echo "== Q0. the real 5.18.93 deploy'):
    assert pre.count(need) >= 1, need
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H94_PREFIX"
rm -f "$H94_PREFIX"

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.94's own rehearsal, on the sandbox built above: 5.18.93 (69c87a6) live, the stray store still in the plugin folder.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
B93="$PIN"; F94=scripts/deploy-5.18.94.sh; REL94=release/5.18.94; DEPLOY94="$REPO/$F94"
FIX94=85143cbf                                                      # the change on the development branch (the commit holding it)
TV10=9308072840e784e49a0656987a3edfae33c6f3d081558cbf63625c23b071d2e1  # INSTALLATION-TERMS-v1.0, as every record made under it names it
TV11=eee8644e8bb7ea509fa1977279f518d9f0f96ec0b5028431160a57fd833b1db0  # INSTALLATION-TERMS-v1.1, the reviewed text
SS_GOLD=b2f4ff3b34bb1fcabc11bc87beb9f66cbafee22605c3a9e69d42ebcb1ead4637 # South Sudan's Terms and Privacy (test_portal_tenant's golden)
LEGAL_SIG="80 passed, 0 failed rc=0"
ADDED94="tests/test_legal_overrides.php"
TAKEN94="lib/LegalContent.php lib/InstallationTerms.php lib/EmailTemplate.php profiles/uganda.json tabs/customer_app/login_web.php tabs/engage/wa_ai_setup.php tools/set_email_brand.php tools/pdf_template_doctor.php ucrm_pdf_templates/quotation_uganda/template.html.twig ucrm_pdf_templates/invoice_uganda/template.html.twig tests/test_portal_tenant.php tests/test_consent_identity.php tests/test_install_authorisation.php tests/test_email_no_sudan.php tests/test_job_notifications_day.php tests/test_notify_staff_side.php"
PINS94="tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
N94=$(( $(wc -w <<<"$ADDED94 $TAKEN94 $PINS94") + 1 ))           # …and the manifest
if git -C "$R" ls-files --error-unmatch "$F94" >/dev/null 2>&1; then
  cmp -s "$R/$F94" "$DEPLOY94" || { echo "refusing: $F94 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH94="the committed script"
else cp "$R/$F94" "$DEPLOY94"; WHICH94="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL94:refs/remotes/origin/$REL94" 2>/dev/null || true
P94="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY94")"
OUT94="$SB/out94"; mkdir -p "$OUT94"; ln -s "$OUT94" "$RECS/dnb-5.18.94"
STATE94="$OUT94/state-5.18.94.env"
data94() { data_dump | grep -v '^master_schedule:' | sha256sum | cut -c1-16; }   # the data as data_digest reads it, but master's record of the
                                                                                 # retry job, which the rehearsal itself moves (set_sched) — never this script
left_tmp94() { ls -d /tmp/dnb-5.18.94-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP94="$(left_tmp94)"
host_code94() { ls -d "$OUT94"/code-* 2>/dev/null | wc -l | tr -d ' '; }
fresh94() { find "$OUT94" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.94.env*' ! -name 'rollback-state-*' -exec rm -rf {} +; base_log; }
backups94() { ls -d "$OUT94"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
installed94() {   # installed94 COMMIT — the number of files 5.18.93 → 5.18.94 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$B93" "$P94"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}

echo; echo "== 94-0. what is rehearsed: 5.18.94 =="
echo "  script    $WHICH94, sha256 $(sha256sum "$DEPLOY94" | cut -c1-16)"
case "$P94" in __*|'') echo "  FAIL the script still carries the placeholder pin ($P94)"; exit 2;; esac
case "$FIX94" in __*) echo "  FAIL the rehearsal does not name the development branch's commit of the change ($FIX94)"; exit 2;; esac
check "$P94" "$(git -C "$REPO" rev-parse --short "origin/$REL94" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL94 ($P94)"
check "$(git -C "$REPO" rev-parse --short "$P94^" 2>/dev/null)$(ver "$P94")$(ver "$B93")" "${B93}5.18.945.18.93" "the release commit is cut on $B93 (5.18.93, the live version) and is 5.18.94"
check "$(dl D "$B93" "$P94" | grep -c .)$(dl A "$B93" "$P94" | grep -c .)$(dl AM "$B93" "$P94" | grep -c .)" "01$N94" "5.18.93 → 5.18.94: nothing removed, one file added, $N94 in all"
check "$(dl A "$B93" "$P94")" "$P/$ADDED94" "…the one file added is the test of the overrides"
W94=""; for f in $ADDED94 $TAKEN94 $PINS94 manifest.json; do grep -qx "$P/$f" <(dl AM "$B93" "$P94") || W94="$W94 $f"; done
check "${W94:-none}" "none" "…and every file of the reviewed set is in the delta"
check "$(git -C "$REPO" diff --name-only "$B93" "$P94" | grep -vc "^$P/")$(dl AM "$B93" "$P94" | grep -c "^$P/migrations/")" "00" "nothing outside the plugin, and no migration"
T94=""; for f in $ADDED94 $TAKEN94; do [ "$(git -C "$REPO" rev-parse "$P94:$P/$f")" = "$(git -C "$REPO" rev-parse "$FIX94:$P/$f" 2>/dev/null)" ] || T94="$T94 $f"; done
check "${T94:-same}" "same" "the release's seventeen files of the change are the development branch's ($FIX94) byte for byte"
pin_only() {   # pin_only FILE — the release's file is 5.18.93's with every 5.18.93 → 5.18.94 and nothing else
  python3 - <(git -C "$REPO" show "$B93:$P/$1") <(git -C "$REPO" show "$P94:$P/$1") <<'PY'
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
print('pin' if a.count('5.18.93') >= 1 and a.replace('5.18.93', '5.18.94') == b and a != b else 'other')
PY
}
V94=""; for f in manifest.json $PINS94; do [ "$(pin_only "$f")" = "pin" ] || V94="$V94 $f"; done
check "${V94:-pins}" "pins" "the manifest and the five version pins are 5.18.93's with the version alone changed"
check "$(pin_only lib/LegalContent.php)" "other" "control: a file of the change is not a version pin (the comparison can tell them apart)"
S94=""; for f in main.php cron/master.php cron_starlink_block_retry.php lib/StarlinkRetryScope.php tests/test_master_lock_release.php tests/test_main_auto_pull_retired.php migrations/087_wa_channels.sql; do
  [ "$(git -C "$REPO" rev-parse "$P94:$P/$f")" = "$(git -C "$REPO" rev-parse "$B93:$P/$f")" ] || S94="$S94 $f"; done
check "${S94:-same}" "same" "main.php, master.php, the retry job, its gate, both tests of them and 087 are 5.18.93's byte for byte"
check "$(git -C "$REPO" rev-parse "$B93:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$B93:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$P94:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P94:$P/docs")" "Domain B's tree and the plugin's docs are 5.18.93's"
HDR94="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY94")"
check "$(grep -c 'deploy-5.18.94.sh 2>&1 | tee' <<<"$HDR94")$(grep -cE -- '--rollback|--put-back|set_config|git checkout [0-9a-f]{7}' <<<"$HDR94")$(grep -c 'deploy-5.18.94.sh --after-only 2>&1 | tee' <<<"$HDR94")" "101" \
  "the header's deploy command stands alone — no rollback, put-back, setting or checkout in its block (docs/44 §16.9) — and the later --after-only is its own"
check "$(grep -cE -- '--key +(ai_identity_line|wa_flyer_caption|email_badge_line|recording_retention_days)|--set +email_badge_line [^<]' "$DEPLOY94")$(grep -cE 'quarantine\.env[^"]*>>|>> *"\$Q_RECORD"|rm [^|;]*\$Q_KEPT|mv [^|;]*\$Q_KEPT' "$DEPLOY94")" "00" \
  "the script sets none of the three settings or the retention, and never writes the quarantine's record or moves its kept file"
check "$(bash -n "$DEPLOY94" && echo parses)" "parses" "the script parses"
check "$(git -C "$REPO" log -1 --format=%h "$P94" -- $P)|$(git -C "$REPO" log -1 --format=%h "$B93" -- $P)" "$P94|$B93" \
  "control: the clone, in one pack as a fresh clone is, writes the 7-character short form scripts/deploy-hybrid.sh records — as the server's checkout recorded 69c87a6 on 11 Oct"

# ── The fake docker's exec runs with a container's own umask, 022 — a real `docker exec` never carries the calling
# script's (deploy-5.18.94.sh sets 077): trial 1 measured R21's copy folder left 700, out of the database owner's reach,
# where the server ran the same code on 11 Oct and passed. The edit asserted, as every edit here. ──────────────────────
python3 - "$SB/bin/docker" <<'PYD' || echo "fakedocker:umask" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = 'detach=""; user=""\n'
assert s.count(old) == 1
s = s.replace(old, 'umask 022   # a container\'s own, never the calling script\'s\n' + old, 1)
open(p, 'w').write(s)
PYD
check "$(grep -c "^umask 022" "$SB/bin/docker")$(env PATH="$SB/bin:$PATH" bash -c 'umask 077; docker exec ucrm sh -c umask')" "10022" \
  "control: the fake docker's exec runs with the container's umask (022), whatever the calling script set"

# ── The stand-in public.php serves the two documents and the version endpoint, as public.php and api_customer_app.php do ─
python3 - "$SB/web/public.php" <<'PY' || echo "standin:legal" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "if ($page === 'api') {"
assert s.count(old) == 1
new = """if ($page === 'terms' || $page === 'privacy') {   // public.php: the INSTALLED legal page, $config from the store row
    $config = $store->load('kyc_config.json');
    require $root . '/tabs/customer_app/legal_page.php';
    exit;
}
if ($page === 'api' && ($_GET['action'] ?? '') === 'app_legal_version') {   // api_customer_app.php's public action, through the INSTALLED code
    require_once $root . '/lib/TenantProfile.php'; require_once $root . '/lib/LegalContent.php';
    $v = dnLegalVersion(TenantProfile::current((array)($store->load('kyc_config.json') ?? []), $dataDir));
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'data' => ['tos_version' => $v['tos'], 'privacy_version' => $v['privacy'], 'dated' => $v['dated'],
        'terms_url' => 'public.php?page=terms', 'privacy_url' => 'public.php?page=privacy']]);
    exit;
}
""" + old
s = s.replace(old, new, 1); assert s.count("$page === 'terms'") == 1
open(p, 'w').write(s)
PY
pubget() { curl -s --noproxy '*' "$PLUGIN_BASE?$1"; }
for i in $(seq 1 60); do pubget 'page=api&action=app_legal_version' | grep -q '"tos_version"' && break; sleep 0.1; done   # PHP's cache re-reads the edited file within 2 s
check "$(has "$(pubget page=terms)" 'v1.1')$(has "$(pubget page=terms)" 'UCC')$(pubget 'page=api&action=app_legal_version' | grep -o '"dated":"[^"]*"')" "yesyes\"dated\":\"26 September 2026\"" \
  "control: the stand-in serves 5.18.93's Terms (v1.1, the UCC claim in its first sentence) and its version endpoint, through the installed code"

# ── The website, as dishnet-web-uganda/site has it at the clone's commit (the 12 Oct pages), and three variants ─────────
WSRC="$REPO/dishnet-web-uganda/site"; WROOT="$SB/website"; mkdir -p "$WROOT"
for v in ok old nopriv nopol ucc; do cp -a "$WSRC" "$WROOT/$v"; done
python3 - "$WROOT" <<'PY' || echo "website:variants" >> "$SB/anchor-missing"
import sys, os
r = sys.argv[1]
def edit(path, old, new):
    s = open(path).read(); assert s.count(old) == 1, (path, old); open(path, 'w').write(s.replace(old, new))
edit(os.path.join(r, 'old', 'terms.html'), 'independent Ugandan company: an IT solutions provider and Starlink installer',
     'independent Ugandan company: an IT solutions provider and UCC-authorised Starlink installer')
edit(os.path.join(r, 'nopriv', 'privacy.html'), 'Call recordings, where a call is recorded', 'Recordings of calls')
open(os.path.join(r, 'nopol', 'policies.html'), 'w').write('<!DOCTYPE html><html><body><h1>Policies</h1><p>Coming soon.</p></body></html>')   # a hub without the two links
edit(os.path.join(r, 'ucc', 'terms.html'), '</body>', '<p>DishNet Africa is a UCC-authorised Starlink installer.</p></body>')   # the identity sentence kept, a claim beside it
PY
WPORT94="$(free_port)"; "$PHPBIN" -S "127.0.0.1:$WPORT94" -t "$WROOT" >"$SB/website.log" 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' -o /dev/null "http://127.0.0.1:$WPORT94/ok/policies.html" && break; sleep 0.1; done
WEB_OK="http://127.0.0.1:$WPORT94/ok"; WEB_DOWN="http://127.0.0.1:$(free_port)"
check "$(curl -s --noproxy '*' "$WEB_OK/terms.html" | grep -c 'independent Ugandan company: an IT solutions provider and Starlink installer')$(curl -s --noproxy '*' "http://127.0.0.1:$WPORT94/nopol/policies.html" | grep -c 'href="terms.html"')$(curl -s --noproxy '*' "$WEB_OK/policies.html" | grep -c 'href="terms.html"')" "102" \
  "control: the stand-in website serves the 12 Oct Terms and its policies hub (both links), and the variant's hub carries no link"
check "$(curl -s --noproxy '*' "http://127.0.0.1:$WPORT94/ucc/terms.html" | grep -c 'independent Ugandan company: an IT solutions provider and Starlink installer')$(curl -s --noproxy '*' "http://127.0.0.1:$WPORT94/ucc/terms.html" | grep -ciE 'UCC[- ]authori[sz]ed')" "11" \
  "control: the UCC variant keeps the identity sentence and adds one UCC claim"

# ── Kampala's date, as the script reads it (TZ=Africa/Kampala date +%Y%m%d) — moved only where a scenario says so ─────
mkdir -p "$SB/datebin"
cat > "$SB/datebin/date" <<SH
#!/usr/bin/env bash
if [ -n "\${FAKE_TODAY:-}" ] && [ "\${TZ:-}" = "Africa/Kampala" ] && [ "\$#" = 1 ] && [ "\$1" = "+%Y%m%d" ]; then echo "\$FAKE_TODAY"; exit 0; fi
exec $(command -v date) "\$@"
SH
chmod +x "$SB/datebin/date"
check "$(PATH="$SB/datebin:$PATH" FAKE_TODAY=20991231 TZ=Africa/Kampala date +%Y%m%d)$(PATH="$SB/datebin:$PATH" TZ=Africa/Kampala date +%Y%m%d)" "20991231$(TZ=Africa/Kampala date +%Y%m%d)" \
  "control: the stand-in date answers the planted day only when asked for one"
# Every run's today in Kampala is the day before the documents' date, unless a scenario says otherwise — so A20 reads the
# same whatever day the rehearsal runs (the review of 13 Oct: on the real day, every run would have refused at A20).
DATED94="$(git -C "$REPO" show "$P94:$P/profiles/uganda.json" | python3 -c 'import sys, json; print(json.load(sys.stdin)["legal"]["dated"])')"
DATED94_YMD="$(date -d "$DATED94" +%Y%m%d)"; TODAY94="$(date -d "$DATED94 -1 day" +%Y%m%d)"; AFTER94="$(date -d "$DATED94 +1 day" +%Y%m%d)"
check "$DATED94|$DATED94_YMD|$TODAY94|$AFTER94" "16 October 2026|20261016|20261015|20261017" "control: the pin's documents are dated 16 October 2026; every run's today in Kampala is the day before"

run94() {   # [--answer WORD] [--script FILE] [--env K=V]… [--website URL] [--today YYYYMMDD], then the script's options — as run does
  local answer="" script="$DEPLOY94" extra=() web="$WEB_OK" today="$TODAY94" n o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;;
    --website) web="$2"; shift 2 ;; --today) today="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$today" DNB_OUT="$OUT94" DNB_RECORDS="$RECS" DNB_WEBSITE="$web" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  case "$o" in *"unknown option:"*) echo "run94-$n" >> "$SB/bad-runs" ;; esac
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run94-$(printf '%03d' "$n")-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
went94() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question (a word other than DEPLOY then stops it)
QREC_KEEP="$SB/qrec.kept"
qrec_keep() { cp -p "$QREC" "$QREC_KEEP"; }
qrec_back() { cat "$QREC_KEEP" > "$QREC"; touch -r "$QREC_KEEP" "$QREC"; }   # in place: its inode, bytes and time
kept_state() { echo "$(stat -c '%d:%i:%h:%u:%g:%a:%Y:%Z' "$KEPT" 2>/dev/null)/$(sha256sum "$KEPT" 2>/dev/null | cut -c1-16)/$(ls -A "$QD" | grep -v '\.own\.log$\|^run-[0-9]*\.log$' | sort | paste -sd, -)"; }
oldname() { ls -A "$PD/data" | grep -cE '^plugin\.sqlite3'; }
quiet94() {   # nothing deployed, backed up or changed: 5.18.93 live, no backup, the data as seeded, the quarantine as it was
  echo "$(live)|$(backups94)|$(data94)|$(kept_state)|$(oldname)"
}

# ── Two installation-authorisation records, as 5.18.93's request and acceptance leave them: one v1.0 request accepted,
# one pending — so A17 reads records of both states, and the pending v1.0 request is said ───────────────────────────
sqx "INSERT INTO install_auth(job_id, crm_client_id, seq, acceptance_reference, status, terms_version, terms_hash, price_snapshot, scope_snapshot, customer_name, requested_by, requested_by_name, requested_at, token_hash, token_expires_at, accepted_at, accepted_method, accepted_by_name)
     VALUES (31, 5, 1, 'ACC-20261008-000001', 'accepted', 'INSTALLATION-TERMS-v1.0', '$TV10', '{}', '{}', 'Rehearsal Customer', 1, 'Rehearsal Tech', '2026-10-08 09:00:00', 'rh-token-hash-31', '2026-10-20 00:00:00', '2026-10-08 10:00:00', 'web_link', 'Rehearsal Customer')" >/dev/null
sqx "INSERT INTO install_auth(job_id, crm_client_id, seq, acceptance_reference, status, terms_version, terms_hash, price_snapshot, scope_snapshot, customer_name, requested_by, requested_by_name, requested_at, token_hash, token_expires_at)
     VALUES (32, 6, 2, 'ACC-20261011-000002', 'pending', 'INSTALLATION-TERMS-v1.0', '$TV10', '{}', '{}', 'Rehearsal Customer Two', 1, 'Rehearsal Tech', '2026-10-11 09:00:00', 'rh-token-hash-32', '2026-10-25 00:00:00')" >/dev/null
check "$(sq "SELECT group_concat(job_id || ':' || status || ':' || substr(terms_hash, 1, 8), ',') FROM install_auth")" "31:accepted:${TV10:0:8},32:pending:${TV10:0:8}" \
  "control: two v1.0 records — one accepted, one pending — hashed as 5.18.93's InstallationTerms has v1.0"
D94="$(data94)"

echo; echo "== 94-1. A14: nothing before the quarantine has moved the file and its --check has passed =="
# A0's short-form guard: a checkout writing 8-character commits (as a clone with duplicate packs did, 12 Oct) is refused
# with its remedy, before anything changes; a copy without the guard is refused only by the parent check, naming the
# longer form — the message the guard replaces.
git -C "$REPO" config core.abbrev 8; fresh94; OUT="$(run94 --answer NO)"
M="$(mutant abbrev "$DEPLOY94" '[ "$ABBREV_PIN|$ABBREV_BASE" = "$EXPECTED_PLUGIN_COMMIT|$BASELINE_COMMIT" ] \' 'true \')"; fresh94; OUTM="$(run94 --answer NO --script "$M")"; rm -f "$M"
git -C "$REPO" config --unset core.abbrev
check "$(has "$OUT" "STOP: this checkout writes commits in a short form of 8 characters (${P94}")$(has "$OUT" "Run: git -C $REPO config core.abbrev 7")$(went94 "$OUT")|$(live)|$(backups94)|$(data94)" "yesyesnono|$B93|0|$D94" \
  "1z a checkout writing 8-character commits is refused before anything changes, with the remedy (git config core.abbrev 7)"
check "$(has "$OUTM" "is not cut on $B93 (5.18.93): its parent is ${B93}")$(went94 "$OUTM")" "yesnono" \
  "1z' a copy without the guard is refused only by the parent check, naming the longer form (the message the guard replaces)"
check "$(git -C "$REPO" config core.abbrev || echo unset)$(git -C "$REPO" log -1 --format=%h "$P94" -- $P)" "unset$P94" "…the setting taken out again: the clone writes the 7-character form"
fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'NO-GO (A14): the stray store'"'"'s quarantine is not done, or does not hold — no record at')$(went94 "$OUT")|$(live)|$(backups94)|$(data94)" "yesnono|$B93|0|$D94" \
  "1a no quarantine yet (no record): NO-GO at A14, nothing deployed, backed up or changed"
check "$(has "$OUT" 'ok    A0 the release delta is exactly 5.18.94')$(okline "$OUT" A7 'Domain B untouched')$(has "$OUT" 'ok    A6 ')$(has "$OUT" 'ok    A8 ')$(has "$OUT" 'ok    A11 ')$(has "$OUT" 'ok    A12 ')" "yesyesyesyesyesno" \
  "…every check before it passed (A0, A7, A6, A8, A11) and none after it ran (A12): the refusal is A14's alone"
# The quarantine, as the operator runs it after 5.18.93's day-later check: QUARANTINE typed.
OUT="$(qrun --answer QUARANTINE)"
check "$(has "$OUT" 'stray-store quarantine (quarantine): PASSED.')$(rec_last Q_STAGE)$(oldname)$( [ -f "$KEPT" ] && echo kept)" "yesdone0kept" \
  "the REAL quarantine moves the stray store into its folder: PASSED, its record done, nothing at the old name, the kept file there"
KEPT0="$(kept_state)"; QREC0="$(sha256sum "$QREC" | cut -c1-16)"; QAT0="$(rec_last Q_AT)"
fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'NO-GO (A14): no --check of the quarantine that began at least 20 minutes after the move PASSED')$(went94 "$OUT")|$(quiet94)" "yesnono|$B93|0|$D94|$KEPT0|0" \
  "1b moved, no --check yet: NO-GO, nothing changed — the kept file and the old name as the quarantine left them"
OUT="$(qrun -- --check)"
check "$(has "$OUT" '(check): NOT CONCLUSIVE')" "yes" "…its --check straight after the move: not conclusive (master has not run the retry job since)"
fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'NO-GO (A14): no --check of the quarantine that began at least 20 minutes after the move PASSED with "the quarantine holds" (there is none')$(went94 "$OUT")" "yesnono" \
  "1c a --check begun less than 20 minutes after the move does not count: NO-GO"
qat_back94() { sed -i "s/^Q_AT=.*/Q_AT=$1/" "$QREC"; }   # the move N seconds ago, as a later --check and this deploy find it (every Q_AT line)
NOW94="$(date -u +%s)"; qat_back94 $(( NOW94 - 1800 )); QAT94=$(( NOW94 - 1800 ))
set_sched $(( QAT94 - 60 ))   # master's last run of the job: before the move
OUT="$(qrun -- --check)"
check "$(has "$OUT" '(check): NOT CONCLUSIVE')" "yes" "…the move 30 minutes back, master's record still from before it: the --check is not conclusive"
fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'NO-GO (A14): no --check of the quarantine that began at least 20 minutes after the move PASSED with "the quarantine holds" (the newest, check-')$(has "$OUT" ', is inconclusive)')$(went94 "$OUT")" "yesyesnono" \
  "1d the newest --check begun 20 minutes or more after the move is NOT CONCLUSIVE: NO-GO, and it names the log"
T94="$(date -u +%s)"; set_sched $(( T94 - 300 ))   # master ran the job five minutes ago: after either move time below
qrec_keep; qat_back94 $(( T94 - 600 )); OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…a --check that PASSES ten minutes after a move:"
fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'NO-GO (A14): no --check of the quarantine that began at least 20 minutes after the move PASSED')$(went94 "$OUT")" "yesnono" \
  "1e …does not count either: NO-GO (begun too soon after the move, PASSED or not)"
qrec_back; rm -f "$(ls -t "$QD"/check-*.own.log | head -1)"
OUT="$(qrun -- --check)"; QCHK94="$(ls -t "$QD"/check-*.own.log | head -1)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')$(has "$(cat "$QCHK94")" ': the quarantine holds')$(grep -cE '^  checks +[0-9]+ ok, 0 failed, [0-9]+ notes$' "$QCHK94")" "yesyes1" \
  "the --check the runbook asks for — 30 minutes after the move, master's run of the job since: PASSED, the quarantine holds"
KEPT0="$(kept_state)"; QREC0="$(sha256sum "$QREC" | cut -c1-16)"
fresh94; OUT="$(run94 --answer NO)"
check "$(okline "$OUT" A14 'the stray store is quarantined and the quarantine holds' 'no failure, no put-back')$(went94 "$OUT")$(has "$OUT" 'STOP: not confirmed')|$(quiet94)" "yesyesyesyes|$B93|1|$D94|$KEPT0|0" \
  "1f now A14 passes, the run reaches the question, and a word other than DEPLOY stops it with nothing changed but the backup GO takes before it asks"
check "$(sha256sum "$QREC" | cut -c1-16)" "$QREC0" "…the quarantine's record is exactly as before: the script reads it, never writes it"
# Each clause on its own: planted in the record (the last line of a key wins), or beside the files; put back after each.
a14() {   # a14 LABEL EXPECTED-REASON — run, then: refused at A14 with that reason, never the question, nothing changed
  fresh94; local o; o="$(run94 --answer NO)"
  check "$(has "$o" 'NO-GO (A14)')$(has "$o" "$2")$(went94 "$o")|$(live)|$(backups94)|$(data94)" "yesyesnono|$B93|0|$D94" "$1"
}
qrec_keep
printf 'Q_CHECK_FAILED=%s\n' "$(date -u +%s)" >> "$QREC"; a14 "1g the record holds a failure: NO-GO" "the record holds a failure (Q_CHECK_FAILED at"; qrec_back
printf 'PUTBACK_STAGE=linked\n' >> "$QREC"; a14 "1h a put-back is recorded: NO-GO" "a put-back is recorded (linked"; qrec_back
printf 'Q_STAGE=moving\n' >> "$QREC"; a14 "1i the record's stage is not done: NO-GO" "the record's stage is moving, not done"; qrec_back
printf 'Q_AT=soon\n' >> "$QREC"; a14 "1j the record's move time is not a time: NO-GO" "the record holds no usable move time"; qrec_back
printf 'Q_DATA_KEY=1:2:-\n' >> "$QREC"; a14 "1k <plugin>/data is another folder than the move's: NO-GO" "<plugin>/data is another folder than at the move"; qrec_back
printf 'Q_SHA256=%064d\n' 0 >> "$QREC"; a14 "1l the kept file is not the one the record describes: NO-GO" "the kept file is missing or changed"; qrec_back
printf 'Q_KEY_BEFORE=1:2\n' >> "$QREC"; a14 "1m the kept file is another inode than the record's: NO-GO" "the kept file is missing or changed"; qrec_back
: > "$SRC"; chown 1000:1000 "$SRC"; a14 "1n a database at the old name (an empty one, as a first open leaves it): NO-GO" "a database or its side files at the old name"; rm -f "$SRC"
: > "$SRC-wal"; a14 "1o a side file at the old name: NO-GO" "a database or its side files at the old name"; rm -f "$SRC-wal"
SML_T="$(stat -c %y "$PD/data/sqlite_migration.log" 2>/dev/null)"; cp -p "$PD/data/sqlite_migration.log" "$SB/sml94.kept" 2>/dev/null
printf 'planted\n' >> "$PD/data/sqlite_migration.log"; a14 "1p sqlite_migration.log changed since the move (what a new store's first start writes): NO-GO" "sqlite_migration.log changed since the move"
cat "$SB/sml94.kept" > "$PD/data/sqlite_migration.log"; touch -d "$SML_T" "$PD/data/sqlite_migration.log"; rm -f "$SB/sml94.kept"
: > "$PD/data/rh.json.migrated"; a14 "1q a *.json.migrated made since the move: NO-GO" "*.json.migrated changed since the move"; rm -f "$PD/data/rh.json.migrated"
mv "$QCHK94" "$SB/check.kept"; a14 "1r the passing --check's log gone: NO-GO" "no --check of the quarantine that began at least 20 minutes after the move PASSED"; mv "$SB/check.kept" "$QCHK94"
sed -i 's/: the quarantine holds/: the quarantine reads/' "$QCHK94"; a14 "1s a --check log that does not say \"the quarantine holds\": NO-GO" "is inconclusive"
sed -i 's/: the quarantine reads/: the quarantine holds/' "$QCHK94"
flock "$QD" -c "touch '$SB/qlock.ready'; while [ ! -f '$SB/qlock.go' ]; do sleep 0.1; done" & QL=$!; PIDS+=("$QL")
for i in $(seq 1 50); do [ -f "$SB/qlock.ready" ] && break; sleep 0.1; done
a14 "1t a run of the quarantine script holds its folder's lock: NO-GO" "a run of scripts/quarantine-stray-store.sh is going on this server"
touch "$SB/qlock.go"; wait "$QL" 2>/dev/null; rm -f "$SB/qlock.ready" "$SB/qlock.go"
check "$(kept_state)|$(oldname)|$(sha256sum "$QREC" | cut -c1-16)|$(data94)" "$KEPT0|0|$QREC0|$D94" "…every clause put back: the quarantine's folder, record and kept file, the old name and the data exactly as before"
# The weakened copies: each clause taken out reaches the question over the state it guards (caught).
a14m() {   # a14m LABEL ANCHOR NEW — a copy without one clause, over the planted state the caller set up
  local m o; m="$(mutant "a14-$RANDOM" "$DEPLOY94" "$2" "$3")"; fresh94; o="$(run94 --answer NO --script "$m")"; rm -f "$m"
  check "$(went94 "$o")" "yesyes" "$1"
}
mv "$QCHK94" "$SB/check.kept"
a14m "1r' a copy that does not ask for a passing --check reaches the question with none (caught)" '[ "$QCL_R" = "passed" ] || stop' 'true || stop'
mv "$SB/check.kept" "$QCHK94"
printf 'Q_CHECK_FAILED=%s\n' "$(date -u +%s)" >> "$QREC"
a14m "1g' a copy that does not ask whether the quarantine holds reaches the question over a recorded failure (caught)" '[ "$Q_HOLDS" = "yes" ] || stop' 'true || stop'
qrec_back
flock "$QD" -c "touch '$SB/qlock.ready'; while [ ! -f '$SB/qlock.go' ]; do sleep 0.1; done" & QL=$!; PIDS+=("$QL")
for i in $(seq 1 50); do [ -f "$SB/qlock.ready" ] && break; sleep 0.1; done
a14m "1t' a copy that does not try the lock reaches the question while a run holds it (caught)" '[ "$Q_LOCK" = "free" ] || stop' 'true || stop'
touch "$SB/qlock.go"; wait "$QL" 2>/dev/null; rm -f "$SB/qlock.ready" "$SB/qlock.go"
: > "$SRC"; chown 1000:1000 "$SRC"
a14m "1n' a copy that does not look at the old name reaches the question over a database there (caught)" \
  '{ absent "$DEST/data/plugin.sqlite3" && [ "$(side_of "$DEST/data/plugin.sqlite3")" = "-" ]; } || why=' '{ true; } || why='
rm -f "$SRC"
printf 'STRAY_R19_FAILED=%s\n' "$(date -u +%s)" >> "$SB/out/state-5.18.93.env"
fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'note  A14 a state file records an R19 failure after the move (dnb-5.18.93/state-5.18.93.env)')$(went94 "$OUT")" "yesyesyes" \
  "1u 5.18.93's state file records an R19 failure after the move (a run the runbook forbids): said, never a reason to stop"
sed -i '/^STRAY_R19_FAILED=/d' "$SB/out/state-5.18.93.env"
check "$(kept_state)|$(oldname)|$(sha256sum "$QREC" | cut -c1-16)|$(data94)|$(live)" "$KEPT0|0|$QREC0|$D94|$B93" "…after section 1: the quarantine, the data and the live release exactly as before it"

echo; echo "== 94-2. A17–A21: the documents, the records, the operator's settings, the website, the date =="
nogo94() {   # nogo94 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] — refused with that text, never the question, nothing deployed or changed
  check "$(has "$3" "$2")$(went94 "$3")|$(live)|$(backups94)|$(data94)|$(kept_state)" "yesnono|$B93|0|${4:-$D94}|$KEPT0" "$1"
}
release_variant94() {   # release_variant94 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.93
  local idx="$SB/variant94.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P94"
  blob="$(git -C "$REPO" show "$P94:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant94:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$B93")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
v94() {   # v94 LABEL EXPECTED-TEXT PATH OLD NEW — the pin with one file edited: refused (the variant cut on 5.18.93, the script pinned to it)
  local v s o; v="$(release_variant94 "$3" "$4" "$5")"; s="$(pinned_copy "v94-$RANDOM" "$v" "$DEPLOY94")"; fresh94; o="$(run94 --answer NO --script "$s")"; rm -f "$s"
  nogo94 "$1" "$2" "$o"
}
v94 "2a a pin whose Uganda identity says UCC refuses (A17)" "pin:uganda-says-UCC" profiles/uganda.json \
  '"identity": "an independent IT solutions provider' '"identity": "a UCC-authorised, independent IT solutions provider'
v94 "2b a pin whose Uganda documents carry another date refuses (A17)" "pin:uganda-dated(" profiles/uganda.json '"dated": "16 October 2026"' '"dated": "17 October 2026"'
v94 "2c a pin whose Uganda Terms lost an override refuses (A17): the shared text would come back" "pin:overrides(9/6)" profiles/uganda.json '"billing": "' '"billing_old": "'
v94 "2d a pin whose v1.1 text changed refuses (A17): it is not the reviewed text" "pin:v1.1-not-the-reviewed-text" lib/InstallationTerms.php \
  'These Terms are version INSTALLATION-TERMS-v1.1. Your acceptance' 'These Terms are version INSTALLATION-TERMS-v1.1. Your agreement'
# A record that would no longer match its terms under the pin's code: a v1.1 request (as made only after a deploy) with
# another hash — 5.18.93's code cannot read v1.1 at all (said, not refused), the pin's would show it "cannot be shown".
db_keep
sqx "INSERT INTO install_auth(job_id, crm_client_id, seq, acceptance_reference, status, terms_version, terms_hash, price_snapshot, scope_snapshot, requested_by, requested_at, token_hash, token_expires_at)
     VALUES (39, 7, 9, 'ACC-20261012-000009', 'pending', 'INSTALLATION-TERMS-v1.1', '$(printf '%064d' 1)', '{}', '{}', 1, '2026-10-12 09:00:00', 'rh-token-hash-39', '2026-10-25 00:00:00')" >/dev/null
DX="$(data94)"; fresh94; OUT="$(run94 --answer NO)"
nogo94 "2e a v1.1 record whose hash is not v1.1's refuses (A17): under the pin's code it could never be shown or accepted" "pin:records-unmatched(rows=3" "$OUT" "$DX"
check "$(has "$OUT" 'unknown=1 unknown_pending=1 unmatched=0')$(has "$OUT" 'live:records-unmatched')" "yesno" "…5.18.93's code counts it as a version it does not hold — not a refusal of its own"
M="$(mutant a17rec "$DEPLOY94" 'case "$IA_PIN" in "rows="*" unknown=0 unknown_pending=0 unmatched=0 unmatched_pending=0") ;;' 'case "$IA_PIN" in *) ;;')"
fresh94; OUT="$(run94 --answer NO --script "$M")"; rm -f "$M"
check "$(went94 "$OUT")" "yesyes" "2e' a copy that does not read the records through the pin's code reaches the question over it (caught)"
db_back
check "$(data94)" "$D94" "…the record taken away again (the database as kept): the data as seeded"
# The operator's three settings, in either copy; and the badge the pin would show over each.
a18() {   # a18 LABEL KEY VALUE files|store|both EXPECTED — the setting planted, refused, never its value in the log; put back
  cfg_keep; vault_keep
  case "$4" in files) file_set "$2" "$3" ;; store) row_set "$2" "$3" ;; both) file_set "$2" "$3"; row_set "$2" "$3" ;; esac
  local dx o; dx="$(data94)"; fresh94; o="$(run94 --answer NO)"
  check "$(has "$o" 'NO-GO (A18): these still say UCC —')$(has "$o" "$5")$(went94 "$o")$(has "$o" "$3")|$(live)|$(backups94)|$(data94)" "yesyesnonono|$B93|0|$dx" "$1"
  cfg_back; vault_back
}
a18 "2f ai_identity_line saying UCC in the store row refuses (A18), and the log never prints it" ai_identity_line "Rehearsal Seller, a UCC Authorised installer" store "ai_identity_line=unset:ok/set:UCC"
a18 "2g wa_flyer_caption saying UCC in the configuration file refuses (A18)" wa_flyer_caption "Rehearsal flyer — UCC approved" files "wa_flyer_caption=set:UCC/unset:ok"
a18 "2h email_badge_line saying UCC in both copies refuses (A18) — the badge the pin would show says it too" email_badge_line "UCC Authorised Starlink Installer" both "email_badge_line=set:UCC/set:UCC"
check "$(data94)" "$D94" "…each put back: the data as seeded"
cfg_keep; vault_keep; row_set email_badge_line "UCC Authorised Starlink Installer"
M="$(mutant a18 "$DEPLOY94" '[ -z "$UCC_HIT" ] || stop' 'true || stop')"; fresh94; OUT="$(run94 --answer NO --script "$M")"; rm -f "$M"
check "$(went94 "$OUT")$(has "$OUT" 'UCC Authorised Starlink Installer')" "yesyesno" "2h' a copy that does not refuse on UCC reaches the question with the badge saying it (caught) — the value still unprinted"
cfg_back; vault_back
cfg_keep; vault_keep; row_set email_badge_line "Starlink Installation"; file_set ai_identity_line "Rehearsal Seller, a Starlink installer"
DX="$(data94)"; fresh94; OUT="$(run94 --answer NO)"
check "$(okline "$OUT" A18 'none of ai_identity_line, wa_flyer_caption or email_badge_line says UCC' 'email_badge_line=unset:ok/set:ok' 'ai_identity_line=set:ok/unset:ok' 'badge-shown=ok/ok')$(went94 "$OUT")" "yesyesyes" \
  "2i the settings set without UCC: A18 ok, set or not set and ok per copy, the badge shown ok in both"
cfg_back; vault_back
# The recordings' retention, read from the store row as the maintenance job reads it.
a21() {   # a21 LABEL VALUE EXPECTED
  cfg_keep; vault_keep; row_set recording_retention_days "$2"; local dx o; dx="$(data94)"; fresh94; o="$(run94 --answer NO)"
  check "$(has "$o" 'NO-GO (A21)')$(has "$o" "$3")$(went94 "$o")|$(live)|$(backups94)|$(data94)" "yesyesnono|$B93|0|$dx" "$1"; cfg_back; vault_back
}
a21 "2j retention 0 (keep for ever) refuses (A21)" 0 "recording_retention_days is 0 in the store row"
a21 "2k retention 400 days refuses (A21): longer than the 12 months the policy states" 400 "recording_retention_days is 400 in the store row"
a21 "2l retention not a number refuses (A21)" "a year" "recording_retention_days in the store row is blank or not a whole number (?)"
a21 "2l' retention blank refuses (A21): the maintenance job reads a blank as 0, which keeps every recording for ever" "" "recording_retention_days in the store row is blank or not a whole number (?)"
cfg_keep; vault_keep; row_set recording_retention_days ""
M="$(mutant a21blank "$DEPLOY94" 'if ($v === null) return "365";' 'if ($v === null || $v === "") return "365";')"; fresh94; OUT="$(run94 --answer NO --script "$M")"; rm -f "$M"
check "$(went94 "$OUT")$(has "$OUT" 'ok    A21 call recordings are deleted after 365 day(s)')" "yesyesyes" "2l'' a copy that reads a blank as the default reaches the question over it (caught)"
cfg_back; vault_back
cfg_keep; vault_keep; row_set recording_retention_days 400
M="$(mutant a21 "$DEPLOY94" '{ [ "$RET_S" -ge 1 ] && [ "$RET_S" -le 365 ]; } || stop' 'true || stop')"; fresh94; OUT="$(run94 --answer NO --script "$M")"; rm -f "$M"
check "$(went94 "$OUT")" "yesyes" "2k' a copy without the bound reaches the question over 400 days (caught)"
cfg_back; vault_back
cfg_keep; vault_keep; row_set recording_retention_days 180; DX="$(data94)"; fresh94; OUT="$(run94 --answer NO)"
check "$(okline "$OUT" A21 'call recordings are deleted after 180 day(s)' 'the files say 365')$(has "$OUT" 'note  A21 the configuration files say 365 for recording_retention_days, the store row 180')$(went94 "$OUT")" "yesyesyesyes" \
  "2m 180 days in the store row, none in the file: A21 ok on the row the maintenance job reads, the difference said"
cfg_back; vault_back
OUT94A="$OUT"
# The website the portal's new Terms point to.
fresh94; OUT="$(run94 --answer NO --website "http://127.0.0.1:$WPORT94/old")"
nogo94 "2n the website's Terms still claiming UCC refuses (A19)" "NO-GO (A19): the website does not show the 12 Oct policies" "$OUT"
check "$(has "$OUT" 'terms.html 200 (identity ×0, a UCC claim ×')" "yes" "…it says what it found: the identity sentence missing, the claim counted"
fresh94; OUT="$(run94 --answer NO --website "http://127.0.0.1:$WPORT94/nopol")"
nogo94 "2o the website's policies hub without its links refuses (A19)" "policies.html 200 (links no)" "$OUT"
fresh94; OUT="$(run94 --answer NO --website "http://127.0.0.1:$WPORT94/ucc")"
nogo94 "2o' the website's Terms keeping the identity sentence but claiming UCC beside it refuses (A19)" "terms.html 200 (identity ×1, a UCC claim ×1)" "$OUT"
M="$(mutant a19ucc "$DEPLOY94" ' && [ "${W_TOS_UCC:-1}" = "0" ]; }' '; }')"; fresh94; OUT="$(run94 --answer NO --script "$M" --website "http://127.0.0.1:$WPORT94/ucc")"; rm -f "$M"
check "$(went94 "$OUT")" "yesyes" "2o'' a copy that does not count the website's UCC claim reaches the question over it (caught)"
M="$(mutant a19 "$DEPLOY94" '{ [ "$W_POL" = "200" ] && [ "$W_POL_OK" = "yes" ] && [ "$W_TOS" = "200" ] && [ "$W_TOS_ID" != "0" ] && [ "${W_TOS_UCC:-1}" = "0" ]; }' 'true')"
fresh94; OUT="$(run94 --answer NO --script "$M" --website "http://127.0.0.1:$WPORT94/old")"; rm -f "$M"
check "$(went94 "$OUT")" "yesyes" "2n' a copy that does not read the website's Terms reaches the question over the old ones (caught)"
fresh94; OUT="$(run94 --answer NO --website "http://127.0.0.1:$WPORT94/nopriv")"
check "$(okline "$OUT" A19 'the website shows the policies hub')$(has "$OUT" "note  A19 the website's Privacy Policy or Starlink Installation page is not yet the 12 Oct evening's (recordings ×0")$(went94 "$OUT")" "yesyesyesyes" \
  "2p the Privacy Policy without the 12 Oct evening's recordings line: said, not refused"
fresh94; OUT="$(run94 --answer NO --website "$WEB_DOWN")"
check "$(has "$OUT" "note  A19 the website ($WEB_DOWN) could not be reached from this server (curl 000)")$(went94 "$OUT")" "yesyesyes" \
  "2q the website unreachable from the server: said (open it in a browser), neither a pass nor a refusal"
check "$(okline "$OUT94A" A19 'the website shows the policies hub and the Terms of Service' 'no UCC authorisation claim')$(has "$OUT94A" 'note  A19')" "yesno" \
  "2r the 12 Oct website: A19 ok, no note"
# The documents' date against today in Kampala.
fresh94; OUT="$(run94 --answer NO --today "$AFTER94")"
nogo94 "2s the day after the documents' date in Kampala refuses (A20): never deployed backdated" "NO-GO (A20): the Uganda documents are dated 16 October 2026, before today in Kampala ($AFTER94)" "$OUT"
M="$(mutant a20 "$DEPLOY94" '[ "$DATED_YMD" -ge "$TODAY_KLA" ] || stop' 'true || stop')"; fresh94; OUT="$(run94 --answer NO --script "$M" --today "$AFTER94")"; rm -f "$M"
check "$(went94 "$OUT")" "yesyes" "2s' a copy without the date's bound reaches the question a day late (caught)"
fresh94; OUT="$(run94 --answer NO --today "$DATED94_YMD")"
check "$(okline "$OUT" A20 'the Uganda documents are dated 16 October 2026' 'today in Kampala')$(went94 "$OUT")" "yesyesyes" "2t on the day itself: A20 ok"
check "$(has "$OUT94A" "note  A20 the Uganda documents are dated 16 October 2026, after today in Kampala ($TODAY94)")" "yes" \
  "2u the day before (every run's today): said — a customer signing in before then accepts them with that date shown as Effective"
check "$(kept_state)|$(oldname)|$(sha256sum "$QREC" | cut -c1-16)|$(data94)|$(live)|$(host_code94)|$(left_tmp94)" "$KEPT0|0|$QREC0|$D94|$B93|0|$TMP94" \
  "…after section 2: nothing changed, no copy of the code left on the host or in /tmp"

echo; echo "== 94-3. the deploy, as the operator runs it =="
fresh94; OUT="$(run94 --answer DEPLOY)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (deploy): PASSED')$(live)" "0yes$P94" "the deploy PASSES: 0 failed, the container serves $P94"
for l in "ok    A0 the release delta is exactly 5.18.94's $N94 files — 1 added, $(( N94 - 1 )) changed, no migration" \
         "ok    A7 Domain B untouched" \
         "ok    A14 the stray store is quarantined and the quarantine holds" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" \
         "ok    A13 the pin's retry job" \
         "ok    A15 under this server's own PHP" "ok    A16 under this server's own PHP" \
         "documents test  pin  5.18.94's $ADDED94 under PHP" \
         "ok    A17 the pin's documents, under this server's own PHP: South Sudan's Terms and Privacy byte for byte the live code's and the golden (sha256 ${SS_GOLD:0:16}…); Uganda's version 1.2, dated 16 October 2026, every override in place (terms/privacy 10/6)" \
         "the pin's test of the overrides passes ($LEGAL_SIG)" \
         "note  A17 pending request(s) made under INSTALLATION-TERMS-v1.0 (v1.0:1) keep INSTALLATION-TERMS-v1.0" \
         "ok    A18 none of ai_identity_line, wa_flyer_caption or email_badge_line says UCC" \
         "ok    A21 call recordings are deleted after 365 day(s)" \
         "ok    A19 the website shows the policies hub" \
         "GO — evidence recorded" \
         "checking out $P94 (5.18.94, the release commit) for the documented deploy" \
         "ok    container serves $P94" \
         "ok    V3f " "ok    V3h " "ok    V3i " \
         "ok    V13 the public Terms and Privacy are 5.18.94's: version 1.2, the identity sentence, no \"UCC\"; app_legal_version answers 1.2 / 1.2, dated 16 October 2026" \
         "ok    R1 all $N94 files 5.18.94 changes are installed exactly as $P94 has them (1 new)" \
         "ok    R1 the installed manifest says 5.18.94" \
         "ok    R5 all" \
         "ok    R11 " "ok    R12 " "ok    R16 " "ok    R18 the installed retry job" \
         "note  R19 the quarantine holds — nothing at the old name, nothing a new store makes, the kept file unchanged — but master has not completed a run of the retry job since the copy" \
         "ok    R20 under this server's own PHP the installed test of master's lock passes" \
         "ok    R21 under this server's own PHP the installed test of the retired auto-pull passes" \
         "ok    R22 the installed documents are 5.18.94's" \
         "what changed      the customer portal's Terms of Service and Privacy Policy, Uganda only"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:110}"
done
check "$(notes "$OUT")$(has "$OUT" 'ok    V4 no fatal or parse error of')" "3yes" "three notes — A17's pending v1.0 request, A20's date still ahead, R19's not yet conclusive — and V4 ok"
check "$(okline "$OUT" A5 'mn=on/on')$(okline "$OUT" A9 "$SHAPE_ON")$(okline "$OUT" A11 'policy=on/on')$(okline "$OUT" R3 'migration 087' 'unchanged')" "yesyesyesyes" \
  "the pilot as the operator left it: A5, A9, A11 read it on, R3 finds 087's rows unchanged"
check "$(has "$OUT" 'UCC Authorised')$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "no0" "the log names no setting's value, no Evolution address, key or instance, and no number"
check "$(installed94 "$P94")$(inst_ver)$(sha256sum "$PD/lib/InstallationTerms.php" | cut -c1-64)" "05.18.94$(git -C "$REPO" show "$P94:$P/lib/InstallationTerms.php" | sha256sum | cut -c1-64)" \
  "every file 5.18.93 → 5.18.94 changes is installed exactly as the pin has it; manifest 5.18.94"
check "$(data94)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)" "$D94${KEPT0}0$QREC0" \
  "the deploy wrote no record or setting, and left the quarantine as it was: the kept file, the old name, the record"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/LegalContent.php")\$" "$STATE94")$(grep -c '^Q_HOLDS_BEFORE=yes$' "$STATE94")$(grep -c '^STRAY_Q_FAILED=' "$STATE94")" "110" \
  "the state file records the copy's time (as stamp_copy gave lib/LegalContent.php) and that the quarantine held before; no failure"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.94.sh --rollback" <<<"$OUT")$(awk '/\(deploy\): PASSED/ {p=1} p && /deploy-5\.18\.94\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "1after" \
  "the rollback command is printed once, on its own line, after the verdict"
check "$(host_code94)$(left_tmp94)$(evo_calls)" "0${TMP94}0" "the run left no copy of the code on the host or in /tmp, and never called Evolution"
check "$(has "$(pubget page=terms)" 'v1.2')$(has "$(pubget page=terms)" 'UCC')$(has "$(pubget page=privacy)" 'UCC')$(pubget 'page=api&action=app_legal_version' | grep -o '"dated":"[^"]*"')" "yesnono\"dated\":\"16 October 2026\"" \
  "control: the stand-in now serves 5.18.94's Terms (v1.2, no UCC), its Privacy (no UCC) and its date"

echo; echo "== 94-4. --after-only: R19 conclusive once master has run the job; the quarantine's own --check =="
copy_back94() {   # the copy N minutes ago: the marker file's time, the recorded copy time, and the deploy's start a minute before it
  touch -d "-$1 minutes" "$PD/lib/LegalContent.php"; local t; t="$(stat -c %Y "$PD/lib/LegalContent.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE94"; }
copy_back94 30; COPY94="$(sed -n 's/^COPY_AT=//p' "$STATE94" | tail -1)"
set_sched $(( COPY94 - 60 )); base_log; OUT="$(run94 --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'note  R19 the quarantine holds')$(has "$OUT" '5.18.94 (after): PASSED')" "0yesyes" "4a 30 minutes after the copy, master's last run of the job before it: PASSED, R19 not yet conclusive"
set_sched $(( COPY94 + 120 )); base_log; OUT="$(run94 --after-only)"
check "$(has "$OUT" 'note  R19 the quarantine holds')" "yes" "4b a run of the job that began only 120 s after the copy: still not conclusive (the margin is strict)"
set_sched $(( COPY94 + 300 )); base_log; OUT="$(run94 --after-only)"
check "$(fails "$OUT")$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY94"))")$(has "$OUT" '5.18.94 (after): PASSED')$(okline "$OUT" R22 "the installed documents are 5.18.94's")" "0yesyesyes" \
  "4c master's run of the job since the copy: R19 conclusive — the quarantine holds — and R22 again: PASSED"
check "$(data94)$(kept_state)$(oldname)$(host_code94)$(left_tmp94)" "$D94${KEPT0}00$TMP94" "…the --after-only runs wrote nothing and left nothing behind"
# V4: 5.18.93 has no known fatal, so any fatal of the plugin since the deploy fails — 5.18.92's old line at main.php:484
# too, named by its time and file:line, never by more of its text.
base_log; chown 1000:1000 "$SB/container.log"; touch "$SB/web/.plant_old"; OUT="$(run94 --after-only)"   # the stand-in writes as the server's owner
check "$(failline "$OUT" V4 '1 fatal line(s) of')$(grep -A7 -E '^  FAIL  V4' <<<"$OUT" | grep -cE '^ +20[0-9-]+T[0-9:]+Z main\.php:484$')$(grep -A7 -E '^  FAIL  V4' <<<"$OUT" | grep -cE 'not callable|UNCAUGHT')$(ls -A "$SB/web" | grep -c '^\.plant_old$')" "yes100" \
  "4c' a fatal of the plugin since the deploy — 5.18.92's old line at main.php:484, planted during the run — FAILS V4, named by its time and file:line only"
base_log
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')$(has "$OUT" 'note  K2 the container serves')" "yesyes" \
  "4d the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.94 live (the commit said, the gate's files unchanged)"
KEPT1="$(kept_state)"; QREC1="$(sha256sum "$QREC" | cut -c1-16)"
# A database made at the old name after the copy (what a gate that let the job through would leave): R19 FAILS, records it,
# and every later run fails too — what it saw may be gone by then.
: > "$SRC"; chown 1000:1000 "$SRC"; base_log; OUT="$(run94 --after-only)"
check "$(failline "$OUT" R19 'the quarantine no longer holds' 'a database or its side files at the old name')$(has "$OUT" 'R19 FAILED — recorded in')$(grep -c '^STRAY_Q_FAILED=' "$STATE94")" "yesyes1" \
  "4e a database at the old name: R19 FAILS, and the failure is recorded in the state file"
rm -f "$SRC"; base_log; OUT="$(run94 --after-only)"
check "$(failline "$OUT" R19 'an earlier run found the quarantine broken')$(grep -c '^STRAY_Q_FAILED=' "$STATE94")" "yes1" "4f the database gone again: R19 still FAILS on the recorded failure (recorded once)"
M="$(mutant r19sticky "$DEPLOY94" 'elif [ -n "$R19_FAILED_LINE" ]; then' 'elif false; then')"; base_log; OUT="$(run94 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" R19 'the quarantine holds')" "yes" "4f' a copy that forgets the recorded failure passes R19 (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"
check "$(kept_state)|$(oldname)|$(sha256sum "$QREC" | cut -c1-16)" "$KEPT1|0|$QREC1" "…the planted database removed, the recorded failure taken out of the state file by hand: the quarantine as it was"

echo; echo "== 94-5. the rollback: --rollback, typed ROLLBACK — and deployed again =="
# A request made under v1.1 since the deploy, still pending: 5.18.93's code cannot read its terms.
sqx "INSERT INTO install_auth(job_id, crm_client_id, seq, acceptance_reference, status, terms_version, terms_hash, price_snapshot, scope_snapshot, requested_by, requested_at, token_hash, token_expires_at)
     VALUES (33, 8, 3, 'ACC-20261012-000003', 'pending', 'INSTALLATION-TERMS-v1.1', '$TV11', '{}', '{}', 1, '2026-10-12 12:00:00', 'rh-token-hash-33', '2026-10-26 00:00:00')" >/dev/null
D95="$(data94)"
fresh94; OUT="$(run94 --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (rollback): PASSED')$(live)" "0yes$B93" "the rollback PASSES: 5.18.93 serving again"
for l in "ok    container serves $B93 (5.18.93)" \
         "ok    RB the installed manifest says 5.18.93" \
         "ok    RB the documents are 5.18.93's again: South Sudan's byte for byte the golden; Uganda's version 1.1, dated 26 September 2026" \
         "note  RB 1 authorisation record(s) were made under INSTALLATION-TERMS-v1.1, which 5.18.93's code does not hold" \
         "1 of them still pending — withdraw each on its job page and request again" \
         "ok    RB the quarantine still holds, as its record says" \
         "note  RB run the quarantine's --check next" \
         "ok    V13 the public Terms and Privacy are 5.18.93's again: version 1.1, dated 26 September 2026" \
         "note  RB the code is 5.18.93 again. The rollback restores code only. The file 5.18.94 added stays on disk, reached by nothing (deploy-hybrid.sh never deletes): $ADDED94"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:110}"
done
check "$(installed94 "$B93")$(inst_ver)$( [ -f "$PD/$ADDED94" ] && echo kept)" "05.18.93kept" "every changed file as 5.18.93 has it, manifest 5.18.93; the added test stays on disk"
check "$(data94)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code94)$(left_tmp94)" "$D95${KEPT1}0${QREC1}0$TMP94" "the rollback changed no data and not the quarantine, and left no copy of the code"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
# Deployed again over the rollback: the v1.1 record the live code cannot read is said, not refused — the pin reads it.
fresh94; OUT="$(run94 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (deploy): PASSED')$(live)" "0yes$P94" "deployed again over the rollback: PASSED"
check "$(has "$OUT" 'note  A17 1 authorisation record(s) name a version the live 5.18.93 code does not hold (v1.0:2,v1.1:1; 1 pending)')$(okline "$OUT" A14 'the quarantine holds')$(okline "$OUT" R22 "the installed documents are 5.18.94's")" "yesyesyes" \
  "…A17 says the v1.1 record 5.18.93's code could not read; A14 holds on the same --check; R22 reads every record through the installed code"
M="$(mutant a17live "$DEPLOY94" 'case "$IA_BASE" in "rows="*" unknown="*" unmatched=0 unmatched_pending=0") ;;' 'case "$IA_BASE" in "rows="*" unknown=0 unknown_pending=0 unmatched=0 unmatched_pending=0") ;;')"
fresh94; OUT="$(run94 --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(live)" "0$B93" "…rolled back once more (PASSED), 5.18.93 serving, the v1.1 record still there"
fresh94; OUT="$(run94 --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'live:records-unmatched')$(went94 "$OUT")$(live)" "yesnono$B93" \
  "5' a copy that counts a version the live code does not hold as a record that does not match refuses a redeploy after a rollback (the defect this rehearsal found, caught)"
fresh94; OUT="$(run94 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (deploy): PASSED')$(live)" "0yes$P94" "…rolled back and deployed again with the script as it is: PASSED, $P94 serving"

echo; echo "== 94-5b. a recorded failure is not forgotten: into a rollback, and a deploy waits for a --check after it =="
# The review of 13 Oct: a deploy run again wrote a new state file and only noted the old failure. R19's failure (a database
# at the old name after the copy), then the database gone again: the rollback still FAILS on it (RB — a rollback runs RB,
# not R19), and a deploy refuses (A14) until a --check of the quarantine that began after it has PASSED.
: > "$SRC"; chown 1000:1000 "$SRC"; base_log; OUT="$(run94 --after-only)"
check "$(failline "$OUT" R19 'the quarantine no longer holds')$(grep -c '^STRAY_Q_FAILED=' "$STATE94")" "yes1" "5b-a a database at the old name after the redeploy: R19 FAILS and records it"
rm -f "$SRC"; fresh94; OUT="$(run94 --answer ROLLBACK --rollback)"
check "$(live)|$(failline "$OUT" RB 'the quarantine holds as its record says now, but a run of this script found it broken')$(has "$OUT" '5.18.94 (rollback): PASSED')$(grep -c '^STRAY_Q_FAILED=' "$STATE94")$(grep -cE '^  (ok|FAIL|note)  R19 ' <<<"$OUT")" "$B93|yesno10" \
  "5b-b the database gone again, then --rollback: 5.18.93 back, but RB FAILS on the recorded failure (recorded once; a rollback runs no R19)"
D96="$(data94)"; fresh94; OUT="$(run94 --answer DEPLOY)"
check "$(has "$OUT" 'NO-GO (A14): a run of this script found the quarantine broken at')$(has "$OUT" 'and the newest passing --check of the quarantine began before that')$(went94 "$OUT")|$(live)|$(backups94)|$(data94)" "yesyesnono|$B93|0|$D96" \
  "5b-c a deploy after it refuses (A14) — the newest passing --check began before the failure — nothing deployed or changed"
M="$(mutant a14sticky "$DEPLOY94" '[ "$QCL_T" -gt "$Q_FAIL_LAST" ] || stop' 'true || stop')"; fresh94; OUT="$(run94 --answer NO --script "$M")"; rm -f "$M"
check "$(went94 "$OUT")$(live)" "yesyes$B93" "5b-c' a copy that forgets the recorded failure reaches the question over it (caught)"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5b-d the quarantine's own --check, after the failure: PASSED"
fresh94; OUT="$(run94 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (deploy): PASSED')$(live)|$(has "$OUT" 'note  A14 a run of this script found the quarantine broken at')$(has "$OUT" 'after it, PASSED — so this deploy goes on')$(grep -c '^STRAY_Q_FAILED=' "$STATE94")$(grep -l '^STRAY_Q_FAILED=' "$STATE94".prev-* | wc -l | tr -d ' ')" "0yes$P94|yesyes01" \
  "5b-e deployed after that --check: PASSED, the earlier failure said (A14's note), the new state file clean, the old one kept"
# RB's own failure is recorded (a rollback runs no R19, so RB is the only check that can): a later deploy then waits too.
: > "$SRC"; chown 1000:1000 "$SRC"; fresh94; OUT="$(run94 --answer ROLLBACK --rollback)"
check "$(live)|$(failline "$OUT" RB 'the quarantine does not hold after the rollback')$(has "$OUT" 'RB FAILED — recorded in')$(grep -c '^STRAY_Q_FAILED=' "$STATE94")" "$B93|yesyes1" \
  "5b-f a rollback with a database at the old name: RB FAILS and records it in the deploy's state file"
rm -f "$SRC"; D97="$(data94)"; fresh94; OUT="$(run94 --answer NO)"
check "$(has "$OUT" 'NO-GO (A14): a run of this script found the quarantine broken at')$(went94 "$OUT")|$(live)|$(data94)" "yesnono|$B93|$D97" "5b-g …and a deploy after it refuses (A14) on RB's record"
OUT="$(qrun -- --check)"; check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5b-h the quarantine's own --check again: PASSED"
fresh94; OUT="$(run94 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (deploy): PASSED')$(live)$(has "$OUT" 'note  A14 a run of this script found the quarantine broken at')" "0yes${P94}yes" "5b-i …deployed after it: PASSED, $P94 serving"
check "$(kept_state | cut -d/ -f1-2)|$(oldname)" "$(printf '%s' "$KEPT1" | cut -d/ -f1-2)|0" "…the kept file as it was, nothing at the old name"

echo; echo "== 94-6. what the rehearsal left behind, and what it never reached =="
check "$(evo_calls)" "0" "Evolution never called — not by the quarantine, the deploy, its checks or the installed code they ran"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside, as its own rehearsal counts it"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy and every planted edit found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe94 "$DEPLOY94" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe94 none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run94 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(left_tmp94)$(host_code94)" "${TMP94}0" "no copy of the code, throwaway layout or test folder is left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy scripts, $(cat "$SB/qruns" 2>/dev/null || echo 0) of the quarantine)"
[ "$FAILN" = "0" ]
