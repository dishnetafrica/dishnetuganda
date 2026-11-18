
# ══════════════════════════════════════════════════════════════════════════════════════
# 106-pre — production's state before the deploy, made through the real writers (design-v2 §C 106-pre; A3–A6, A11)
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-pre. production's state before the deploy: $BV106 deployed again, step 6 off, followup_* as production has them, the jam (J1–J3), the owner's interim of 18 Nov =="
# This part's numbers of 106's range (ph106 NN; 106-infra keeps 98 and 99 for A6's control), each in NAMES104 and FORMS106
# since 106-infra f, so every sweep reads them: 00 and 01 J1's fresh refused customers; 02 J1's stale refused one (the set
# F2 expires); 03 the number the deliveries between J1's refusals are stored on; 04 and 05 J2's present stale customers; 07
# the one number of J2's 400 gone rows; 08 J3's customer. 06 and 09 are this part's and unused. 10–97 are left to the later
# sections, a fresh number for every re-plant: a conversation's last_customer_at never moves back, and rh106-seed.php refuses
# a plant older than the customer's last message.
# ── This part's helpers, beside 106-infra's (none of them is defined there; none redefines one of 106-infra's). ──
plant106() {   # plant106 OPS-JSON — rh106-seed.php's book ops (the jam: enquiry, pair, delivery, gone; and the store-only claim-key
               # fixture) through the PIN's own classes ($H106/ref: bf29cbd's lib/ from Git), as the book's owner, under the clock, in
               # Kampala's zone (106-infra's seed106: a vault copy of its own, removed after; RH106_RANGE, this rehearsal's ten
               # digits, which the helper requires, given to that one run by seed106 itself, never exported). Its JSON line, or
               # none(<why>)
  seed106 "$H106/ref/$P" "$DATA" "$1" Africa/Kampala
}
seed106r() {   # seed106r OPS-JSON — the same helper as root, for the settings-file fixtures alone (fx_file, fx_file_back: a file root
               # or another user may own; rh106-seed.php keeps its owner and mode and puts both back) — a book op refuses any user but
               # the book's owner, so none runs this way. Under the clock. Its JSON line, or none(<why>)
  local o
  o="$(cd "$H106/v" && env DN_VAULT_FILE="$H106/v/seedr-vault.none" RH106_RANGE="${PR106#+}" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
        http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$H106/rh106-seed.php" "$H106/ref/$P" "$DATA" "$1" Africa/Kampala 2>"$H106/v/seedr.err")"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H106/v/seedr.err"))" ;; esac
}
sd106() {   # sd106 SEED-LINE N FIELD — FIELD of the Nth entry (from 0) of a seed line's "done" (a list joined by commas), or unread
  python3 -I -c 'import json, sys
try:
    v = json.loads(sys.argv[1])["done"][int(sys.argv[2])][sys.argv[3]]
    print(",".join(str(x) for x in v) if isinstance(v, list) else (("true" if v else "false") if isinstance(v, bool) else v))
except Exception:
    print("unread")' "$1" "$2" "$3"
}
ag106() {   # ag106 SEED-LINE N SECONDS — the Nth entry's age_s (time() less its lca, the helper's own) against the plant's lca_ago_s:
            # SECONDS when within 5 s of it (a second may tick inside the op), else off:<age>
  local a; a="$(sd106 "$1" "$2" age_s)"
  case "$a" in ''|*[!0-9]*) echo unread ;; *) if [ "$a" -ge "$3" ] && [ "$a" -le $(( $3 + 5 )) ]; then echo "$3"; else echo "off:$a"; fi ;; esac
}
rwdk106() { tr ' ;=' '\n\n\n' <<<"$1" | awk -F@ -v i="$2" 'NF == 5 && $1 == i { printf "%s/%s/%s/%s", $2, $3, $4, $5; f = 1 } END { if (!f) printf "none" }'; }   # rwdk106 RWD-LINE ID — n3/nall/refusals/deliveries of one follow-up, from rh106q.py rwd
fsl106() { grep -oE '\[followup_send\] sent=[0-9]+ held=[0-9]+ failed=[0-9]+' "$1" 2>/dev/null | tail -n1 | sed -E 's/.*sent=([0-9]+) held=([0-9]+) failed=([0-9]+)$/\1:\2:\3/'; }   # fsl106 MASTER-OUT — the sender's own summary in a pass (7835906 cron/followup_send.php:362): sent:held:failed
f5d106() { echo "+$(( $(n103 "${2%%/*}") - $(n103 "${1%%/*}") ))/+$(( $(n103 "${2#*/}") - $(n103 "${1#*/}") ))"; }   # f5d106 BEFORE AFTER — U3's F5 count (follow-ups/drafts) moved by: +f/+d
apd106() {   # apd106 — the approved enquiry drafts on open follow-ups in the sender's order (approvedDrafts' decided_at, then the
             # draft's id): n=<count> fu=<each draft's follow-up id, comma-separated, or -> — read-only
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  python3 -I - "$DATA/plugin.sqlite3" <<'PY'
import sqlite3, sys
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    k = [r[1] for r in c.execute('PRAGMA table_info(followups)')]
    e = " AND COALESCE(f.kind,'enquiry')='enquiry'" if 'kind' in k else ''
    r = c.execute("SELECT d.followup_id FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                  " WHERE d.status = 'approved' AND f.closed_at IS NULL" + e + " ORDER BY d.decided_at, d.id").fetchall()
    print('n=%d fu=%s' % (len(r), ','.join(str(x[0]) for x in r) or '-'))
except sqlite3.Error as x:
    print('unread:%s' % type(x).__name__)
finally:
    c.close()
PY
}
outsum106() { ( cd "$OUT106" 2>/dev/null && find . -type f ! -name '*.log' ! -name 'cash-*.txt' ! -name 'cih-rows-*' -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum ) | sha256sum | cut -c1-16; }   # outsum106 — the run folder's records (state, rollback-state, key files, backups) as bytes, a run's own readings aside (its log, its cash sheet, its row digests)
still106() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; echo "$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(outsum106)"; }   # still106 — the book's digest, the four settings places by sha256 (rh106q.py cfgsha), the run folder's records: nothing changed when it reads the same
order106() {   # order106 OUTPUT PREFIX… — ordered when each PREFIX begins exactly one line of OUTPUT and those lines come in the order
               # given; else the first PREFIX found other than once (count:N:…) or the order read (order:…). OUTPUT on stdin (a run's
               # terminal can pass the size an argument may have), compared as bytes
  python3 -I -c 'import os, sys
lines = sys.stdin.buffer.read().split(b"\n"); at = []
for a in sys.argv[1:]:
    p = os.fsencode(a)
    h = [i for i, l in enumerate(lines) if l.startswith(p)]
    if len(h) != 1:
        print("count:%d:%s" % (len(h), a.strip()[:48].encode("ascii", "replace").decode().replace(" ", "_"))); sys.exit(0)
    at.append(h[0])
print("ordered" if at == sorted(at) else "order:" + ",".join(str(x) for x in at))' "${@:2}" <<<"$1"
}
cond106() {   # cond106 LABEL SERVES KEY CHASE EN [RECORD] — a case's preconditions, asserted first (design-v2 §D), one check: SERVES
              # the live commit; KEY followup_auto_send and EN followup_enabled, on or off as the jobs read them (rh106q.py kycplaces'
              # eff: PluginConfig::read's three files, FILTER_VALIDATE_BOOLEAN); CHASE step 6 on or off by this rehearsal's ladder
              # reading (lk105's steps on); RECORD the latest rollback record as as_rb_last's mirror reads it over $OUT106 (rh106q.py
              # rblast, "last records"; default "- 0": no rollback of 5.18.106 on record)
  local k r c
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,followup_enabled)"; r="$(q106 rblast "$OUT106" "$STATE106")"
  c="$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)"
  check "$(live)|$(kv106 "$k" eff)|$( [ "$(n103 "$c")" -ge 1 ] && echo on || echo off)|$(kv106 "$r" last) $(kv106 "$r" records)" \
        "$2|followup_auto_send:$3;followup_enabled:$5|$4|${6:-- 0}" \
    "$1 the case's preconditions (design-v2 §D): $2 serving; followup_auto_send $3 and followup_enabled $5 as the jobs read them; step 6 (chase) $4 by this rehearsal's ladder reading; the latest rollback record '${6:-- 0}' (last, records) by as_rb_last's mirror"
}
srtcsv106() { tr ',' '\n' <<<"$1" | grep -v '^-$' | grep . | LC_ALL=C sort | paste -sd, -; }   # srtcsv106 CSV — sorted, '-' and blanks dropped
dlv106() {   # dlv106 TAG — one delivery on J1's channel (sales), stored now through the pin's ConversationService::storeMessage as the
             # sender stores what it delivered: outbound, WhatsApp's id, sent_at and created_at the clock's (design-v2 §A5) —
             # planted, not a fixture: storeMessage is the sender's own writer. Prints the message's id, or unread
  sd106 "$(plant106 "[{\"op\":\"delivery\",\"phone\":\"$J1D106\",\"channel\":\"sales\",\"name\":\"RH106SECRET-J1D\",\"text\":\"RH106SECRET-J1 delivery $1\"}]")" 0 message
}
csb1run106() {   # csb1run106 LABEL SCRIPT -- ACTION… — 5.18.105's lkb1run105 on this release's runner: a deploy whose DEPLOY is typed
                 # only once ACTION has run while the question waited (run106c --hold, the terminal streamed to a file of its own,
                 # swept with the others); CSB1OUT106 the run's output, CSB1RC106 the tool's exit code for ACTION — call it directly
  local lab="$1" scr="$2" st hf op
  shift 2; [ "${1:-}" = -- ] && shift
  st="$R106/logs/stream-$lab.txt"; hf="$R106/logs/hold-$lab"; rm -f "$hf" "$R106/logs/act-$lab.rc"; : > "$st"
  ( i=0; while ! grep -q 'Type DEPLOY' "$st" 2>/dev/null && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done
    "$@" > "$R106/logs/act-$lab.txt" 2>&1; sfrc104 > "$R106/logs/act-$lab.rc"; : > "$hf" ) & op=$!
  fresh106; CSB1OUT106="$(R106_STREAM="$st" run106c --answer DEPLOY --hold "$hf" --script "$scr")"
  wait "$op" 2>/dev/null
  CSB1RC106="$(cat "$R106/logs/act-$lab.rc" 2>/dev/null)"
}

# ── 106-pre1: 5.18.105 deployed again, through the real deploy-5.18.105.sh, as production's is when 5.18.106 comes (105-7e
# ended with a rollback: 994d660 serving, the ladder at L2, 5.18.105's files on disk). Run as 5.18.105's rehearsal ran it —
# run105c, no RH106_TOUCH (A1's shim is 5.18.106's runs' alone), so its COPY_AT is the kernel's, as there. ──
cond106 "106-pre1 cond" "$P104" off on off
fresh105; OUT="$(run105c --answer DEPLOY --redeploy)"
# deploy-5.18.105.sh:14708 the verdict ("  $EXPECTED_VERSION ($MODE): PASSED."), :7099 LK-CA1's ok line
check "$(fails "$OUT")$(has "$OUT" "  $V105 (deploy): PASSED.")|$(live)|$(okline "$OUT" LK-CA1 "after the copy every ladder key")|$(keyf105)" "0yes|$P105|yes|key" \
  "106-pre1a $BV106 deployed again through the real deploy-$BV106.sh, DEPLOY typed, --redeploy (its files on disk after 105-7e's rollback): PASSED, $P105 serving, LK-CA1 ok (every ladder key as found), its LK key kept"
# Its --after-only, the copy where the deploy recorded it — never moved back: A1 rewrites no state line in 106, so neither
# copy_back105 nor set_sched runs (aft105 calls both). R19 is then conclusive or a note, and PASSED either way
# (deploy-5.18.105.sh:13761-13764); every other "since the copy" reading of it only counts fewer lines with the later copy time.
idle104; EA106="$(evo_calls)"; fresh105; OUT="$(run105c --after-only)"
# deploy-5.18.105.sh:14708
check "$(fails "$OUT")$(has "$OUT" "  $V105 (after): PASSED.")|$(q105 stcmp "$(onall105 "$OUT")" "$(expect105 $L2ON105)")|$(( $(evo_calls) - EA106 ))" "0yes|same|0" \
  "106-pre1b $BV106's --after-only under the clock, the copy as its deploy recorded it: PASSED — steps 1–6 judged, the rest OFF — not judged; Evolution not called"
qcheck106 "106-pre1c"
# 5.18.106's PREV_STATE and A14 read the baseline's records at <records>/dnb-5.18.105 (deploy-5.18.106.sh:1538): 5.18.105's
# run folder, as 5.18.105's rehearsal linked 5.18.104's (its pre-c).
if [ -e "$RECS/dnb-$BV106" ] && [ ! -L "$RECS/dnb-$BV106" ]; then echo "rh106:records-dnb-$BV106-not-a-link" >> "$SB/anchor-missing"; else ln -sfn "$OUT105" "$RECS/dnb-$BV106"; fi
check "$(readlink "$RECS/dnb-$BV106")|$(grep -c '^COPY_AT=[0-9]' "$RECS/dnb-$BV106/state-$BV106.env" 2>/dev/null | awk '{print ($1 > 0) ? "copyat" : "none"}')" "$OUT105|copyat" \
  "106-pre1d $RECS/dnb-$BV106 is 5.18.105's run folder, its state file carrying the copy's COPY_AT — where $V106's PREV_STATE and A14 read their baseline's records"

# ── 106-pre2: step 6 off through the installed tool — production's ladder before the deploy is steps 1–5 on, step 6 off (A11). ──
cond106 "106-pre2 cond" "$B106" off on off
OUT="$(sf104c off chase)"; LK2_106="$(lk105)"
# 7835906 tools/sales_followup.php:484 ("OFF <step>")
check "$(sfrc104)|$(grep -cx 'OFF chase' <<<"$OUT")|$(srtcsv106 "$(kv105 "$LK2_106" on)")|$(kv105 "$LK2_106" held)" "0|1|ack,list,report,tracking,voice-measure|0" \
  "106-pre2a off chase through the installed tool, as production's step 6 is off: OFF chase, exit 0; steps 1–5 on by this rehearsal's ladder reading (report, ack, tracking, list, voice-measure), nothing held"

# ── 106-pre3: production's followup_* settings (A11), through the installed set_config.php as the book's owner, under the
# clock: followup_enabled 1, followup_auto_send 1, followup_daily_cap 3, followup_run_limit 2 — each by its own line and then
# by U4's reading on a copy (a 5.18.106 --check, read-only: GO, stage A holding with step 6 off and followup_enabled on). ──
cond106 "106-pre3 cond" "$B106" off off off
for kv3_106 in followup_enabled=1 followup_auto_send=1 followup_daily_cap=3 followup_run_limit=2; do
  O3_106="$(setcfg106 "${kv3_106%%=*}" "${kv3_106#*=}")"
  # tools/set_config.php:670 (bf29cbd's, 7835906's alike): "  <key> = <value>" once it has saved
  check "$(setrc106)|$(grep -cxF "  ${kv3_106%%=*} = ${kv3_106#*=}" <<<"$O3_106")" "0|1" \
    "106-pre3a ${kv3_106%%=*} set to ${kv3_106#*=} (production's, A11) through the installed set_config.php as the book's owner, under the clock: its line '  ${kv3_106%%=*} = ${kv3_106#*=}', exit 0"
done
HRS106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" hours)"   # followup_max_age_hours as the jobs read it (unset: the release's default)
S3_106="$(still106)"; fresh106; OUT="$(run106c --check)"
# deploy-5.18.106.sh:15907 GO; :7409 CS-G1's ok; :7981 U4's note; :8006 FE-G1's headline
check "$(checkgo106 "$OUT")|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(noteline "$OUT" U4 'followup_enabled on, followup_auto_send on (present in: k,s — ' 'followup_daily_cap 3, followup_run_limit 2, followup_scan_limit ' "followup_max_age_hours $HRS106 — F2 and F3 active" 'the ledger record, StaffJobsGate::applies Uganda')|$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(still106)" \
      "yesnono0|0|0|samekey|0|yes|yes|no|$S3_106" \
  "106-pre3b U4's reading on a copy (a $V106 --check, read-only): GO — followup_enabled on, followup_auto_send on in the file and its store row (k,s), followup_daily_cap 3, followup_run_limit 2, followup_max_age_hours $HRS106 (unset: the default) — F2 and F3 active, the ledger recording (step 6 off); CS-G1 ok, no FE-G1; the book, the four settings places and the run folder unchanged"
cond106 "106-pre3c" "$B106" on off on

# ── 106-pre4: J1 — approved drafts Evolution refuses (HTTP 400 to sendText) while the number delivers others (A4, A5, A6). Two
# for fresh customers (U1 classes them "F1 closes" at stage A) and one for a stale one (F2's), each opened, drafted and
# approved through the pin's FollowUpService, their numbers in the stand-in's refusal list; five passes of 5.18.105's own
# sender (master, followup_send DUE) in one sending window (A4), a delivery stored before each (A5: the clock +60 s, the
# delivery, the clock +241 s, the pass), so that every refusal falls after a delivery and F1 counts it. The chain's own
# approved draft of 9 Nov (decided first) heads pass 1 and is counted. ──
cond106 "106-pre4 cond" "$B106" on off on
J1A106="$(ph106 00)"; J1B106="$(ph106 01)"; J1S106="$(ph106 02)"; J1D106="$(ph106 03)"
J1OPS106="$(python3 -I - "$J1A106" "$J1B106" "$J1S106" <<'PY'
import json, sys
def enq(ph, t, ago):
    return {"op": "enquiry", "phone": ph, "channel": "sales", "name": "RH106SECRET-J1" + t, "text": "RH106SECRET-J1" + t + " asks what the dish costs",
            "lca_ago_s": ago, "upto": "approve", "body": "RH106SECRET-J1" + t + " follow-up", "by": "RH106SECRET-approver"}
print(json.dumps([enq(sys.argv[1], "A", 3600), enq(sys.argv[2], "B", 3600), enq(sys.argv[3], "S", 420 * 3600)]))
PY
)"
AP0_106="$(apd106)"; C106="$(n103 "$(kv106 "$AP0_106" n)")"   # the chain's approved enquiry drafts, before J1's
J1P106="$(plant106 "$J1OPS106")"
J1FU106="$(for i106 in 0 1 2; do sd106 "$J1P106" "$i106" id; done | paste -sd' ' -)"
AP1_106="$(apd106)"
check "$(jf105 "$J1P106" ops)|$(jf105 "$J1P106" uid)|$(sd106 "$J1P106" 0 approved)$(sd106 "$J1P106" 1 approved)$(sd106 "$J1P106" 2 approved)|$(ag106 "$J1P106" 0 3600),$(ag106 "$J1P106" 1 3600),$(ag106 "$J1P106" 2 1512000)|$(tr ' ' '\n' <<<"$J1FU106" | grep -cE '^[0-9]+$')" \
      "3|1000|111|3600,3600,1512000|3" \
  "106-pre4a J1's three follow-ups planted through the pin's FollowUpService as the book's owner (the customer's message stored, opened, one SEND draft each, approved): two fresh (the customer last wrote 1 h before), one stale (420 h, past the limit: F2's) — planted, not fixtures"
RL106="$(refuse106 "$J1A106" "$J1B106" "$J1S106")"   # the stand-in's refusal list (A6): J1's three numbers; kept for 106-6's 5.18.106 sender pass (F1 closes there), removed by cleanup106
check "$( [ "$C106" -ge 1 ] && echo chain || echo "chain:$C106")|$(kv106 "$AP1_106" n)|$(kv106 "$AP1_106" fu | tr ',' '\n' | tail -n 3 | paste -sd' ' -)|$(kv106 "$AP1_106" fu | cut -d, -f1 | grep -cxF -f <(tr ' ' '\n' <<<"$J1FU106"))|$RL106|$(stat -c %a "$SB/evo/rh106-refuse.list" 2>/dev/null)" \
      "chain|$(( C106 + 3 ))|$J1FU106|0|3|644" \
  "106-pre4b the sender's order before pass 1 (approvedDrafts by decided_at, this rehearsal's reading): the chain's own approved draft(s) first ($C106: the 9 Nov one among them), then J1's three; the stand-in Evolution lists J1's three numbers for refusal"
RF4_106="$(refused106)"
for k106 in 1 2 3 4 5; do
  clk_to104 $(( $(fnow104) + 60 )) || true   # 60 s after the plant, or after the pass before (A5)
  DMK106="$(dlv106 "$k106")"
  RFK106="$(refused106)"
  if [ "$k106" = 1 ]; then sender106 "106-pre4c-p1 J1's pass 1 of 5, in the next sending window" --window; KWD106="$(kday104 "$SS106_AT")"
  else sender106 "106-pre4c-p$k106 J1's pass $k106 of 5" --step 241; fi
  RWK106="$(q106 rwd "$DATA/plugin.sqlite3" $J1FU106)"; FSK106="$(fsl106 "$SS106_OUT")"
  G106=""; W106=""; m106=$(( k106 < 3 ? k106 : 3 ))
  for f106 in $J1FU106; do
    r106="$(rwdk106 "$RWK106" "$f106")"
    G106="$G106 $(cut -d/ -f1-3 <<<"$r106")/$( [ "$(n103 "$(cut -d/ -f4 <<<"$r106")")" -ge "$k106" ] && echo dl || echo "dl:$(cut -d/ -f4 <<<"$r106")")"
    W106="$W106 $m106/$k106/$k106/dl"
  done
  if [ "$k106" = 1 ]; then
    FSG106="$(( $(n103 "$(cut -d: -f1 <<<"$FSK106")") + $(n103 "$(cut -d: -f2 <<<"$FSK106")") )):$(cut -d: -f3 <<<"$FSK106")|$(apd106)"; FSW106="$C106:3|n=3 fu=$(tr ' ' ',' <<<"$J1FU106")"
  else FSG106="$FSK106"; FSW106="0:0:3"; fi
  check "$(case "$DMK106" in ''|*[!0-9]*) echo "unread:$DMK106" ;; *) echo stored ;; esac)|${G106# }|$(( $(refused106) - RFK106 ))|$FSG106" \
        "stored|${W106# }|3|$FSW106" \
    "106-pre4d-p$k106 J1's pass $k106 of 5: $( [ "$k106" = 1 ] && echo 'a delivery stored 60 s after the plant' || echo 'a delivery stored 60 s after the pass before'), then each of J1's three refused once more (HTTP 400, Evolution's JSON) — by this rehearsal's mirror of refusedWhileDelivering n=$m106 (to 3), $k106 refusals each counted after a delivery, $k106 failed events whose detail matches F1's LIKE (A6's pass-level control: 7835906's request() records 'Bad Request [HTTP 400 on POST /message/sendText/…]', lib/EvolutionApiService.php:898-904), a delivery in each interval; the sender's own summary $( [ "$k106" = 1 ] && echo "sent or closed the chain's $C106 and failed J1's 3, leaving J1's three alone approved" || echo "sent=0 held=0 failed=3")"
done
RW5_106="$RWK106"; SCH5_106="$(sched104 followup_send)|$(sched104 followup_close)"   # J1's last pass: no master runs after it until the deploy
check "$(kv106 "$RW5_106" min)|$(kv106 "$RW5_106" ge3)|$(for f106 in $J1FU106; do rwdk106 "$RW5_106" "$f106" | cut -d/ -f3; done | paste -sd, -)|$(( $(refused106) - RF4_106 ))|$(grep -c . "$SB/evo/rh106-refuse.list" 2>/dev/null)" \
      "3|3/3|5,5,5|15|3" \
  "106-pre4e J1's positive control after the jam: each refused draft has 5 failed 400 events (4 or more), and this rehearsal's mirror of refusedWhileDelivering reads 3 or more for each of the three (F1's own count, counted to 3); the stand-in refused 15 sends, its list still J1's three"

# ── 106-pre5: J2 (A3) — present stale rows and the 400 gone rows, the order asserted. The gone rows: FollowUpService::open on
# conversation ids 9 000 001–9 000 400, which no conversation holds (asserted before and after) — the state a prune leaves;
# wa_prune_history.php is never run (F14). One number for all of them; their last_customer_at older than every open enquiry
# follow-up's in the book — the chain's 9 Nov row, J1's stale one and J2's present ones included — so the close job's first
# pass spends both of its 200-row lists on gone rows and expires nothing (UJ-ON's NOT YET, 106-5f). Follow-ups can never be
# deleted (fu_never_deleted): these are undone by 5.18.106's own close job, F3, in 106-5 — never by this rehearsal. Planted
# with master idle: nothing runs master between here and 106-4's copy (A2, pig106). ──
cond106 "106-pre5 cond" "$B106" on off on
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
GL106="$(python3 -I - "$DATA/plugin.sqlite3" "$(fnow104)" <<'PY'
import calendar, sqlite3, sys, time
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    k = [r[1] for r in c.execute('PRAGMA table_info(followups)')]
    e = " AND COALESCE(kind,'enquiry')='enquiry'" if 'kind' in k else ''
    m = c.execute("SELECT MIN(last_customer_at) FROM followups WHERE closed_at IS NULL AND COALESCE(last_customer_at,'') <> ''" + e).fetchone()[0]
    t = int(sys.argv[2]) - 180 * 86400
    if m:
        t = min(t, calendar.timegm(time.strptime(str(m)[:19], '%Y-%m-%d %H:%M:%S')) - 86400)
    print(time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(t)))
except (sqlite3.Error, ValueError) as x:
    print('unread:%s' % type(x).__name__)
finally:
    c.close()
PY
)"   # the gone rows' last_customer_at: a day before the oldest open enquiry follow-up's, and at least 180 days before the clock
SO0_106="$(q106 staleorder "$DATA/plugin.sqlite3" "$HRS106" "$(fnow104)" 9000001 9000400)"
J2A106="$(ph106 04)"; J2B106="$(ph106 05)"; J2G106="$(ph106 07)"
J2OPS106="$(python3 -I - "$J2A106" "$J2B106" "$J2G106" "$GL106" <<'PY'
import json, sys
a, b, g, gl = sys.argv[1:5]
def old(ph, t, ago):
    return {"op": "enquiry", "phone": ph, "channel": "sales", "name": "RH106SECRET-J2" + t, "text": "RH106SECRET-J2" + t + " asked once",
            "lca_ago_s": ago, "upto": "open"}
print(json.dumps([old(a, "A", 380 * 3600), old(b, "B", 400 * 3600),
                  {"op": "gone", "from": 9000001, "count": 400, "phone": g, "channel": "sales", "lca": gl}]))
PY
)"
J2P106="$(plant106 "$J2OPS106")"
J2FU106="$(for i106 in 0 1; do sd106 "$J2P106" "$i106" id; done | paste -sd' ' -)"
SO1_106="$(q106 staleorder "$DATA/plugin.sqlite3" "$HRS106" "$(fnow104)" 9000001 9000400)"
check "$(grep -cE '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' <<<"$GL106")|$(kv106 "$SO0_106" planted_open)/$(kv106 "$SO0_106" planted_conv)|$(jf105 "$J2P106" ops)|$(ag106 "$J2P106" 0 1368000),$(ag106 "$J2P106" 1 1440000)|$(sd106 "$J2P106" 2 opened)|$(sd106 "$J2P106" 2 absent_after)" \
      "1|0/0|3|1368000,1440000|400|400" \
  "106-pre5a J2 planted as the book's owner through the pin's classes: before it, no follow-up and no conversation on ids 9 000 001–9 000 400; two present stale follow-ups (customers quiet 380 h and 400 h: past the limit, younger than every gone row), then 400 follow-ups opened in one transaction on ids no conversation holds, older than every open enquiry follow-up — 400 ids still holding no conversation after"
check "$(kv106 "$SO1_106" first400_gone)|$(kv106 "$SO1_106" gone_first)|$(kv106 "$SO1_106" gone_older)|$(kv106 "$SO1_106" planted_open)|$(kv106 "$SO1_106" planted_conv)|$(kv106 "$SO1_106" xs_first_cancel)|$(kv106 "$SO1_106" xs_first_expire)|$( [ "$(n103 "$(kv106 "$SO1_106" xs_all)")" -ge 401 ] && echo 401+ || echo "$(kv106 "$SO1_106" xs_all)")|$( [ "$(n103 "$(kv106 "$SO1_106" stale_present)")" -ge 3 ] && echo 3+ || echo "$(kv106 "$SO1_106" stale_present)")|$( [ "$(n103 "$(kv106 "$SO1_106" p1_cn)")" -ge 200 ] && echo 200+ || echo "$(kv106 "$SO1_106" p1_cn)")|$(kv106 "$SO1_106" p1_ex)" \
      "400/400|yes|yes|400|0|200|0|401+|3+|200+|0" \
  "106-pre5b the stale and gone order (A3), by this rehearsal's own reading at followup_max_age_hours $HRS106: the 400 oldest stale rows all gone, every stale gone row before every stale present row, every open gone row older than every open present one; the 400 planted rows open, no conversation on their ids; U3's forecast (expireStale alone): its first pass cancels 200, expires 0, $(kv106 "$SO1_106" xs_all) stale in all (401 or more); the close job's first pass, its main loop then expireStale: 200 or more cancelled, none expired (106-5f's NOT YET)"
PIG0_106="$(pig106)"   # the installed public.php's piggyback record at the start of the plant-to-copy span (A2): unchanged at 106-4's copy

# ── 106-pre6: J3 (RH106-12) — one fresh follow-up holding two approved drafts of the same attempt (FollowUpService::draft and
# ::approve twice), planted with master idle and kept fresh: nothing sends until 106-6's sender pass, so stage A's U1 classes
# its second draft "held" and U3 counts it in F5, and the rollback's AS-RB0 finds the pair (F5's capitals line). ──
cond106 "106-pre6 cond" "$B106" on off on
F5B106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)"
J3PH106="$(ph106 08)"
J3P106="$(plant106 "[{\"op\":\"pair\",\"phone\":\"$J3PH106\",\"channel\":\"sales\",\"name\":\"RH106SECRET-J3\",\"text\":\"RH106SECRET-J3 asks about the install\",\"lca_ago_s\":3600,\"body\":\"RH106SECRET-J3 first follow-up\",\"body2\":\"RH106SECRET-J3 second follow-up\",\"by\":\"RH106SECRET-approver\"}]")"
J3FU106="$(sd106 "$J3P106" 0 id)"; J3DR106="$(sd106 "$J3P106" 0 drafts)"
F5A106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)"
check "$(jf105 "$J3P106" ops)|$(sd106 "$J3P106" 0 approved)|$(tr ',' '\n' <<<"$J3DR106" | grep -cE '^[0-9]+$')|$(ag106 "$J3P106" 0 3600)|$(f5d106 "$F5B106" "$F5A106")|$(sched104 followup_send)|$(sched104 followup_close)|$(pig106)" \
      "1|2|2|3600|+1/+2|$SCH5_106|$PIG0_106" \
  "106-pre6a J3: one fresh follow-up (the customer last wrote 1 h before) with two SEND drafts of the same attempt, both approved, through the pin's FollowUpService — U3's F5 count up by one follow-up and two drafts ($F5B106 → $F5A106); master idle since J1's last pass (followup_send's and followup_close's entries as that pass left them) and the piggyback record unchanged"

# ── 106-pre7: the interim of 18 Nov, as the owner ran it: followup_enabled 0 (set_config, as the book's owner), then on chase
# (yes typed at the terminal), then --check chase. ──
cond106 "106-pre7 cond" "$B106" on off on
O7_106="$(setcfg106 followup_enabled 0)"
# tools/set_config.php:670
check "$(setrc106)|$(grep -cxF '  followup_enabled = 0' <<<"$O7_106")" "0|1" \
  "106-pre7a the interim, first: followup_enabled 0 through the installed set_config.php as the book's owner — its line '  followup_enabled = 0', exit 0"
O7_106="$(sf104c --tty yes on chase)"
# 7835906 tools/sales_followup.php:224 (the question), :261 ("ON <step>: <keys>")
check "$(sfrc104)|$(has "$O7_106" 'Type yes to switch the quote chase on:')|$(grep -c '^ON chase: ' <<<"$O7_106")" "0|yes|1" \
  "106-pre7b the interim, then: on chase through the installed tool, yes typed at the terminal — ON, exit 0"
O7_106="$(sf104c --check chase)"
# 7835906 tools/sales_followup.php:171 ("<step>: <status>"), :180 (exit 3 only on FAIL)
check "$(sfrc104)|$(grep -cE '^ *chase: (PASS|NOT_YET)' <<<"$O7_106")" "0|1" \
  "106-pre7c the interim, last: --check chase — judged (PASS or NOT_YET), exit 0"
cond106 "106-pre7d" "$B106" on on off
check "$(srtcsv106 "$(kv105 "$(lk105)" on)")|$(kv105 "$(lk105)" held)" "ack,chase,list,report,tracking,voice-measure|0" \
  "106-pre7e the interim's state: steps 1–6 on by this rehearsal's ladder reading (chase among them), nothing held — what the owner's order before the deploy starts from"

# ══════════════════════════════════════════════════════════════════════════════════════
# 106-1 — the owner's order before the deploy, through the script's own refusals (design-v2 §C 106-1)
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-1. the owner's order before the deploy, through $V106's own refusals — CS-G1, FE-G1, U4's capitals note, CS-B1 — each with its weakened copy caught =="
# The weakened copies, each anchored on the script's own text (106-infra's anc106/weak106; ancc106 the control of the
# controls): CS1 cs_check returning at once (deploy-5.18.106.sh:7405-7406); FE1 uj_fe_g1 returning at once (:8003-8004); U41
# U4's capitals note's line removed (:7983, read from the script); CSB the CS-B1 call removed (:7465, read from the script).
anc106 cs1 'cs_check() {
  local c="$1" f
' 'cs_check() {
  return 0   # rh106 CS1: cs_check returns at once
  local c="$1" f
'
anc106 fe1 'uj_fe_g1() {
  uj_ok "$UJ_A" && [ "$(kv "$UJ_A" en)" = "off" ] || return 0
' 'uj_fe_g1() {
  return 0   # rh106 FE1: uj_fe_g1 returns at once
  uj_ok "$UJ_A" && [ "$(kv "$UJ_A" en)" = "off" ] || return 0
'
U41L106="$(grep -m1 -F '  [ "$(kv "$o" en)" = "off" ] || [ -z "$(uj_gate_a "$o")" ] || note "U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:' "$DEPLOY106")"
anc106 u41 "$U41L106" "  :   # rh106 U41: U4's capitals note removed"
CSBL106="$(grep -m1 -F '  cs_check CS-B1 "$o"   # CS-B1' "$DEPLOY106")"
anc106 csb "$CSBL106" "  :   # rh106 CSB: CS-B1's call removed (LK-B1 judges alone)"
check "$(ancc106)|$(grep -c . <<<"$U41L106")$(grep -c . <<<"$CSBL106")" "0|$(ls "$AN106"/*.a | wc -l | tr -d ' ')|-|11" \
  "106-1 anchors: each anchor of the weakened copies so far (CS1, FE1, U41, CSB among them) is the script's own text, found in it exactly once, each replacement different; U41's and CSB's lines read from the script"
# U4's capitals note (deploy-5.18.106.sh:7983), its clause ", ANSWER ANYTHING BUT DEPLOY BELOW" in a deploy only; uj_gate_a (:7935-7941) names the age limit
U4C106="U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: followup_max_age_hours was not a positive number at stage A. STAGE A'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY, SO F3 WOULD STAY UNPROVEN AND THE STEPS THAT WAIT FOR IT (6, 11, 12) WOULD FOLLOW WITHOUT THAT PROOF; AFTERWARDS A JUDGED UJ-ON NEEDS A ROLLBACK AND THEN A --redeploy. TO HAVE IT JUDGED, DO NOT DEPLOY ON THIS READING: PUT IT RIGHT"
# CS-G1's and CS-B1's printed commands: the off command (deploy-5.18.106.sh:7418, :7423, :7428, $QF_WRAPPER at :7205) and the
# --check again (:7432 through cs_check_cmd, :7399, and LOGW, :1563)
OFFW106="      cd $REPO && bash scripts/sales-followup.sh off"; CHKL106="      cd $REPO && bash $F106 --check $(LOGW106 check)"

# ── 106-1a: --check over the interim: CS-G1 NO-GO, quote_chase_enabled present in the file and its store row (k,s); the off
# command alone; nothing changed (the book, the four settings places and the run folder by sha256). ──
cond106 "106-1a cond" "$B106" on on off
D1A_106="$(data94)"; S1A_106="$(still106)"; fresh106; OUT1A_106="$(run106c --check)"; OUT="$OUT1A_106"
# deploy-5.18.106.sh:7434 (the refusal), :1689-1690 (stop's lines)
nogo106 "106-1a CS-G1 --check over the interim: NO-GO — a claim switch is present (quote_chase_enabled@k,s), exit 1, nothing asked, nothing changed" \
        "NO-GO (CS-G1): a claim switch is present (quote_chase_enabled@k,s) — until $V106 serves none may be, with any value" "$OUT" "$D1A_106" "$B106"
# deploy-5.18.106.sh:7413, :7414, :7427-7428, :7431-7432, :7434, :1690
check "$(order106 "$OUT" "  CS-G1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s (key@place — k <data>/kyc_config.json, s its store row; no value is read out). " "  Turn each one off yourself, highest step first, each its own command, alone, " "  step 6 (chase) — off chase also turns step 10 (cold) off:" "$OFFW106 chase" "  Then run --check again, its own command, alone:" "      cd $REPO && bash $F106 --check " "  STOP: NO-GO (CS-G1): " "  Nothing was changed. Send the log file.")|$(grep -cxF "$OFFW106 chase" <<<"$OUT")|$(grep -cxF "$CHKL106" <<<"$OUT")|$(grep -cE '^      cd .* && bash scripts/sales-followup\.sh off offers-(live|test)$' <<<"$OUT")|$(noteline "$OUT" LK-G1 "the ladder's keys as found")|$(has "$OUT" 'NO-GO (FE-G1)')|$(still106)" \
      "ordered|1|1|0|yes|no|$S1A_106" \
  "106-1a' CS-G1's refusal in order: the headline naming quote_chase_enabled@k,s, step 6's off command alone on its line, then --check again with its own log, alone — no step 11 or 12 command; LK-G1 read first (a note); FE-G1 not reached; the book, the four settings places and the run folder byte for byte as before"
MW106="$(weak106 cs1 cs1)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
check "$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')$(has "$OUT" 'NO-GO (CS-G1)')|$(has "$OUT" 'NO-GO (FE-G1): followup_enabled reads off')|$(went106 "$OUT")|$(live)|$(still106)" \
      "nono|yes|nono|$B106|$S1A_106" \
  "106-1a'' CS1, the copy whose cs_check returns at once, on the k,s state: no claim switch found and no CS-G1 refusal — the run goes on past it to the next gate, FE-G1's refusal (followup_enabled is off in the interim) (caught by 106-1a's CS-G1 text); nothing changed"

# ── 106-1b: CS-G1's off command run as printed (the owner's order: step 6 off first). It runs before the variants (design-v2
# 106-1 steps 2–3): a store-only quote_chase_enabled (chase@s) cannot be planted while the store row holds the key. ──
cond106 "106-1b cond" "$B106" on on off
OFFL106="$(printed "$OUT1A_106" "cd $REPO && bash scripts/sales-followup.sh off chase")"
O1B_106="$(run_line106c "" "$OFFL106")"
check "$( [ "      $OFFL106" = "$OFFW106 chase" ] && echo printed)|$(rc106)|$(grep -cx 'OFF chase' <<<"$O1B_106")|$(srtcsv106 "$(kv105 "$(lk105)" on)")" \
      "printed|0|1|ack,list,report,tracking,voice-measure" \
  "106-1b CS-G1's off command run exactly as printed, under the clock: OFF chase, exit 0 — steps 1–5 on by this rehearsal's ladder reading"
cond106 "106-1b1" "$B106" on off off

# ── 106-1c: the store-only claim keys (a FIXTURE: no production writer leaves a claim key in the store row alone) —
# offers_live_enabled, offers_test_enabled and quote_chase_enabled put into the store row alone through the store's own
# load() and save() (rh106-seed.php fx_store_keys); kyc_config.json untouched. CS-G1 reads s too: three commands, highest
# step first, each alone. CS1 on this s-only state. Then undone (fx_store_keys_undo), and LK and CS-G1 read clean. ──
cond106 "106-1c cond" "$B106" on off off
DG1C_106="$(digest105)"; CF1C_106="$(q106 cfgsha "$PD" "$DATA")"
X1C_106="$(plant106 '[{"op":"fx_store_keys","keys":{"offers_live_enabled":"1","offers_test_enabled":"1","quote_chase_enabled":"1"}}]')"
KP1C_106="$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled,quote_chase_enabled)"
F1C_106="$(tr '+' ' ' <<<"$(kv106 "$KP1C_106" cs)")"
check "$(jf105 "$X1C_106" ops)|$(sd106 "$X1C_106" 0 fixture)|$(sd106 "$X1C_106" 0 file)|$(kv106 "$KP1C_106" cs)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" k)" \
      "1|true|unchanged|offers_live_enabled@s+offers_test_enabled@s+quote_chase_enabled@s|$(kv106 "$CF1C_106" k)" \
  "106-1c the fixture: the three claim switches in the store row alone (this rehearsal's reading of cs_found: each @s), kyc_config.json byte for byte as before"
D1C_106="$(data94)"; S1C_106="$(still106)"; fresh106; OUT="$(run106c --check)"
# deploy-5.18.106.sh:7434
nogo106 "106-1c1 CS-G1 --check over the store-only keys: NO-GO — the three present in the store row (s), exit 1, nothing changed" \
        "NO-GO (CS-G1): a claim switch is present ($F1C_106) — until $V106 serves none may be, with any value" "$OUT" "$D1C_106" "$B106"
# deploy-5.18.106.sh:7413, :7417-7418, :7422-7423, :7427-7428, :7431-7432, :7434
check "$(order106 "$OUT" "  CS-G1 A CLAIM SWITCH IS PRESENT: $F1C_106 (key@place — " "  step 12 (offers-live) — off offers-live also pauses a running offer:" "$OFFW106 offers-live" "  step 11 (offers-test) — off offers-test also turns offers-live off and pauses a running offer:" "$OFFW106 offers-test" "  step 6 (chase) — off chase also turns step 10 (cold) off:" "$OFFW106 chase" "  Then run --check again, its own command, alone:" "      cd $REPO && bash $F106 --check " "  STOP: NO-GO (CS-G1): ")|$(for w106 in offers-live offers-test chase; do grep -cxF "$OFFW106 $w106" <<<"$OUT"; done | paste -sd, -)|$(grep -cxF "$CHKL106" <<<"$OUT")|$(still106)" \
      "ordered|1,1,1|1|$S1C_106" \
  "106-1c2 CS-G1 names what this rehearsal reads ($F1C_106) and prints three commands, highest step first — offers-live, offers-test, chase — each alone on its line with what its off also does, then --check again; the book, the four places and the run folder unchanged"
MW106="$(weak106 cs1 cs1)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
check "$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')$(has "$OUT" 'NO-GO (CS-G1)')|$(has "$OUT" 'NO-GO (FE-G1): followup_enabled reads off')|$(went106 "$OUT")|$(live)|$(still106)" \
      "nono|yes|nono|$B106|$S1C_106" \
  "106-1c3 CS1 on this s-only state: no claim switch found, no CS-G1 refusal — the run goes on to FE-G1's refusal (caught by 106-1c1's text); nothing changed"
X1CU_106="$(plant106 '[{"op":"fx_store_keys_undo","keys":["offers_live_enabled","offers_test_enabled","quote_chase_enabled"]}]')"
check "$(jf105 "$X1CU_106" ops)|$(sd106 "$X1CU_106" 0 file)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled,quote_chase_enabled)" cs)|$(digest105)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" k)" \
      "1|unchanged|-|$DG1C_106|$(kv106 "$CF1C_106" k)" \
  "106-1c4 the fixture undone (A9) through the store's own load() and save(): no claim switch in k or s by this rehearsal's reading, every ladder key as before the fixture (LK's digest), kyc_config.json byte for byte — CS-G1 reads clean again in 106-1f"

# ── 106-1d, 106-1e: LK-G1 refuses first — quote_chase_enabled in <plugin>/data/config.json, then in <data>/config.json (each a
# FIXTURE: no production writer writes either file, LK-G1's own remedy text says so), put there by rh106-seed.php fx_file as
# root (the file's owner and mode kept), the original kept in $R106/kept/lkg1; each undone by fx_file_back, by sha256. ──
mkdir -p "$R106/kept/lkg1"; chmod 700 "$R106/kept/lkg1"
for pl106 in p d; do
  c106="$( [ "$pl106" = p ] && echo 106-1d || echo 106-1e )"; w106="$( [ "$pl106" = p ] && echo '<plugin>/data/config.json' || echo '<data>/config.json' )"
  cond106 "$c106 cond" "$B106" on off off
  CF0_106="$(q106 cfgsha "$PD" "$DATA")"
  X1D_106="$(seed106r "[{\"op\":\"fx_file\",\"place\":\"$pl106\",\"keep\":\"$R106/kept/lkg1\",\"tag\":\"lkg1-$pl106\",\"set\":{\"quote_chase_enabled\":\"1\"},\"plugin\":\"$PD\"}]")"
  check "$(jf105 "$X1D_106" ops)|$(sd106 "$X1D_106" 0 fixture)|$(sd106 "$X1D_106" 0 keys)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" quote_chase_enabled)" pd)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" quote_chase_enabled)" cs)" \
        "1|true|quote_chase_enabled|quote_chase_enabled@$pl106|-" \
    "$c106 the fixture: quote_chase_enabled in $w106 alone (this rehearsal's reading: held there, in neither k nor s)"
  D1D_106="$(data94)"; S1D_106="$(still106)"; fresh106; OUT="$(run106c --check)"
  # deploy-5.18.106.sh:7368
  nogo106 "$c106 LK-G1 refuses first: one of allKeys() held by $w106 (quote_chase_enabled), exit 1, nothing changed" \
          "NO-GO (LK-G1): 1 of the ladder's switch and record keys (SalesFollowupLadder::allKeys()) are held by <plugin>/data/config.json or <data>/config.json (quote_chase_enabled)" "$OUT" "$D1D_106" "$B106"
  check "$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(has "$OUT" 'NO-GO (FE-G1)')|$(still106)" "no|no|no|$S1D_106" \
    "${c106}1 …LK-G1's held rule refuses before CS-G1 is reached (neither its refusal nor its ok line) and before FE-G1; the book, the four places and the run folder unchanged"
  X1DB_106="$(seed106r "[{\"op\":\"fx_file_back\",\"place\":\"$pl106\",\"keep\":\"$R106/kept/lkg1\",\"tag\":\"lkg1-$pl106\",\"plugin\":\"$PD\"}]")"
  check "$(jf105 "$X1DB_106" ops)|$(sd106 "$X1DB_106" 0 fixture)|$(q106 cfgsha "$PD" "$DATA")|$(kv105 "$(lk105)" held)|$(ls -A "$R106/kept/lkg1" | grep -c "^lkg1-$pl106\.")" \
        "1|true|$CF0_106|0|0" \
    "${c106}2 the fixture undone (A9): $w106 put back by fx_file_back — the four places byte for byte as before (sha256), nothing held, nothing left kept"
done

# ── 106-1f: the clean --check (step 6 off, followup_enabled still off): CS-G1 ok, FE-G1 NO-GO — its command alone, the owner's
# own (set_config as the book's owner, --value 1, its output cut, its own log), nothing changed. FE1 caught. ──
cond106 "106-1f cond" "$B106" on off off
FEX106="docker exec -u $(owner104) -e DN_DATA_DIR=$PDD104_IN ucrm php $IN_CONTAINER/tools/set_config.php --key followup_enabled --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 followup-enabled-1)"   # deploy-5.18.106.sh:8008, as the script prints it
D1F_106="$(data94)"; S1F_106="$(still106)"; fresh106; OUTFE106="$(run106c --check)"; OUT="$OUTFE106"
# deploy-5.18.106.sh:8013
nogo106 "106-1f FE-G1 --check with step 6 off and followup_enabled off: NO-GO (FE-G1), exit 1, nothing changed" \
        "NO-GO (FE-G1): followup_enabled reads off — the enquiry follow-ups are paused (the 18 Nov interim)." "$OUT" "$D1F_106" "$B106"
# deploy-5.18.106.sh:7409, :7370, :7981, :8006, :8008, :8009, :8013, :1690
check "$(okline "$OUT" CS-G1 'none of the three claim switches')|$(noteline "$OUT" LK-G1 "the ladder's keys as found")|$(noteline "$OUT" U4 'followup_enabled off, followup_auto_send on')|$(order106 "$OUT" "  FE-G1 FOLLOWUP_ENABLED READS OFF AS THE JOBS READ IT (U4 above): " "      docker exec -u " "  It prints the line \"  followup_enabled = 1\" once it has saved; " "  STOP: NO-GO (FE-G1): " "  Nothing was changed. Send the log file.")|$( [ "$(printed "$OUT" "docker exec -u ")" = "$FEX106" ] && echo printed)|$(grep -cxF "      $FEX106" <<<"$OUT")|$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')|$(still106)" \
      "yes|yes|yes|ordered|printed|1|no|$S1F_106" \
  "106-1f1 CS-G1 reads clean after the fixtures (its ok line; LK-G1's note), U4 reads followup_enabled off; FE-G1's headline, then its command alone — set_config as the book's owner on the live data folder, --value 1, its output cut where the settings listing begins, its own log — then the line it prints once it has saved, then the stop; the book, the four places and the run folder unchanged"
MW106="$(weak106 fe1 fe1)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
# deploy-5.18.106.sh:15907 GO; :7984 U4's off note
check "$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')$(has "$OUT" 'NO-GO (FE-G1)')|$(has "$OUT" "  $V106 (check): GO — stage A holds.")|$(noteline "$OUT" U4 'followup_enabled is off — the four follow-up jobs do not run')|$(live)|$(still106)" \
      "nono|yes|yes|$B106|$S1F_106" \
  "106-1f2 FE1, the copy whose uj_fe_g1 returns at once: U4 still reads followup_enabled off, yet no FE-G1 and GO (caught by 106-1f's refusal); nothing changed"

# ── 106-1g: U4 unreadable (A8's uj hook, FAULT INJECTION: QF_PHP's uj reading refused while $SB/.ujfail106 exists), followup_enabled
# still off, so "no refusal" discriminates: no FE-G1, GO, U1–U4's NOT ESTABLISHED note. The trigger armed for this one run
# and removed after it, asserted gone. ──
cond106 "106-1g cond" "$B106" on off off
UJF0_106="$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)"; S1G_106="$(still106)"
: > "$SB/.ujfail106"
fresh106; OUT="$(run106c --check)"
rm -f "$SB/.ujfail106"
# deploy-5.18.106.sh:7991 (uj_stage's note; uj_read :7914-7921 makes "unread(no_answer)" of an empty answer), :8004, :15907
check "$(checkgo106 "$OUT")|$(noteline "$OUT" 'U1–U4' 'could not be read on the copy (unread(no_answer))' 'NOT ESTABLISHED')|$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')$(has "$OUT" 'NO-GO (FE-G1)')|$(grep -c '^  note  U4 ' <<<"$OUT")|$(( $(n103 "$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)") - $(n103 "$UJF0_106") ))|$( [ -e "$SB/.ujfail106" ] && echo armed || echo removed)|$(still106)" \
      "yesnono0|0|0|samekey|0|yes|nono|0|1|removed|$S1G_106" \
  "106-1g U4 unreadable (the uj hook, armed for this run alone): GO — U1–U4's NOT ESTABLISHED note, no U4 line, no FE-G1 refusal although followup_enabled is off (DL2: not read, no refusal); the hook refused the one uj reading and is removed; nothing changed"

# ── 106-1h: FE-G1's command run exactly as printed (the owner's: followup_enabled back on); its own log holds the saved line and
# nothing of the settings listing. Then --check: GO. ──
cond106 "106-1h cond" "$B106" on off off
FEL106="$(printed "$OUTFE106" "docker exec -u ")"
fresh106   # the run folder's logs moved aside first, so that the command's own log is the one there
O1H_106="$(run_line106c "" "$FEL106")"; RC1H_106="$(rc106)"
FEG106="$(ls -1 "$OUT106"/followup-enabled-1-*.log 2>/dev/null)"
# tools/set_config.php:670; deploy-5.18.106.sh:8009 (the line it prints once it has saved), :1563 (LOGW: umask 077)
check "$( [ "$FEL106" = "$FEX106" ] && echo printed)|$RC1H_106|$(grep -c . <<<"$FEG106")|$(grep -cxF '  followup_enabled = 1' "$FEG106" 2>/dev/null)|$(grep -c 'AI SETTINGS' "$FEG106" 2>/dev/null)|$(stat -c %a "$FEG106" 2>/dev/null)|$(grep -cxF '  followup_enabled = 1' <<<"$O1H_106")" \
      "printed|0|1|1|0|600|1" \
  "106-1h FE-G1's command run exactly as printed, under the clock: exit 0, its own log ($(basename "${FEG106:-none}"), mode 0600) holds '  followup_enabled = 1' and nothing from '  AI SETTINGS' on"
cond106 "106-1h1" "$B106" on off on
S1H_106="$(still106)"; fresh106; OUT="$(run106c --check)"
check "$(checkgo106 "$OUT")|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(noteline "$OUT" U4 'followup_enabled on, followup_auto_send on (present in: k,s — ')|$(has "$OUT" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(grep -cF 'U4 UJ-ON WILL NOT JUDGE F3' <<<"$OUT")|$(still106)" \
      "yesnono0|0|0|samekey|0|yes|yes|no|0|$S1H_106" \
  "106-1h2 --check after the owner's two commands: GO — CS-G1 ok, U4 reads followup_enabled on (followup_auto_send on, k,s), no FE-G1, no capitals note; nothing changed"

# ── 106-1i: U4's capitals note — followup_max_age_hours 0 (not a positive number: F2 and F3 inactive), through set_config as
# the book's owner. --check: the note without its DEPLOY clause, then GO; a deploy answered no: the note with the clause,
# before the question, then "not confirmed", nothing changed. U41 caught. Then the limit back at 336. ──
cond106 "106-1i cond" "$B106" on off on
O1I_106="$(setcfg106 followup_max_age_hours 0)"
check "$(setrc106)|$(grep -cxF '  followup_max_age_hours = 0' <<<"$O1I_106")|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" hours)" "0|1|misread" \
  "106-1i1 followup_max_age_hours 0 through the installed set_config.php as the book's owner — its line, exit 0; this rehearsal's reading: not a positive number (staleBefore null)"
S1I_106="$(still106)"; fresh106; OUT="$(run106c --check)"
# deploy-5.18.106.sh:7983 (the note, CHECK_ONLY: no DEPLOY clause), :7981, :15907
check "$(checkgo106 "$OUT")|$(grep -cxF "  note  $U4C106, THEN RUN THIS AGAIN" <<<"$OUT")|$(grep -c 'ANSWER ANYTHING BUT DEPLOY BELOW' <<<"$OUT")|$(noteline "$OUT" U4 'followup_max_age_hours not a positive number — F2 and F3 inactive: nothing is expired on a misread')|$(still106)" \
      "yesnono0|0|0|samekey|0|1|0|yes|$S1I_106" \
  "106-1i2 --check with the limit 0: U4's capitals note — UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY, the age limit named — without its DEPLOY clause, a note: GO; nothing changed"
D1I_106="$(data94)"; C1I_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"; fresh106; OUT="$(run106c --answer no)"
# deploy-5.18.106.sh:7983 (with ", ANSWER ANYTHING BUT DEPLOY BELOW"), :13806, :13838, :13840, :1690
check "$(grep -cxF "  note  $U4C106, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN" <<<"$OUT")|$(order106 "$OUT" "  note  $U4C106, ANSWER ANYTHING BUT DEPLOY BELOW" "  GO — evidence recorded, backup in " "  Type DEPLOY to deploy $V106 ($P106) over live " "  STOP: not confirmed" "  Nothing was changed. Send the log file.")|$(fails "$OUT")|$(rc106)|$(live)|$(installed106 "$B106F")|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)" \
      "1|ordered|0|1|$B106|0|$D1I_106|$C1I_106" \
  "106-1i3 a deploy with the limit 0, answered no: U4's capitals note with its DEPLOY clause before the question, then 'not confirmed', exit 1 — nothing changed: $B106 serving, every file $V106 changes still $BV106's, the book and the four settings places as before (the backup, the state file and the LK key it wrote are its own records)"
tidy106 "106-1i-no"
check "$(backups106)|$(states106)|$(keyf106)" "0|0|nokey" "106-1i4 …the declined deploy's backup, state file and LK key moved into the case's folder: the next deploy is a first deploy again"
MW106="$(weak106 u41 u41)"; fresh106; OUT="$(R106_MUTANT_OUT=1 run106c --script "$MW106" --check)"; rm -f "$MW106"
check "$(grep -cF 'U4 UJ-ON WILL NOT JUDGE F3' <<<"$OUT")|$(noteline "$OUT" U4 'followup_max_age_hours not a positive number')|$(has "$OUT" "  $V106 (check): GO — stage A holds.")" "0|yes|yes" \
  "106-1i5 U41, the copy without the capitals note's line: U4 still reads the limit not a positive number, GO, and no capitals note (caught by 106-1i2's note)"
O1I_106="$(setcfg106 followup_max_age_hours 336)"
check "$(setrc106)|$(grep -cxF '  followup_max_age_hours = 336' <<<"$O1I_106")|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" hours)" "0|1|336" \
  "106-1i6 followup_max_age_hours set to 336 through the installed set_config.php as the book's owner — its line, exit 0; this rehearsal's reading 336"

# ── 106-1j: CS-B1 (RH106-6) — a deploy held at "Type DEPLOY"; on chase (yes typed) run while it waits; then DEPLOY typed. CS-B1
# refuses on LK-B1's reading before LK-B1's comparison: its own text, the off and --check commands each alone, nothing copied.
# CSB caught by LK-B1's generic refusal. Step 6 off again after each. ──
cond106 "106-1j cond" "$B106" on off on
WF0J_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"
csb1run106 csb1 "$DEPLOY106" -- sf104c --tty yes on chase
OUT="$CSB1OUT106"
# deploy-5.18.106.sh:7409 (CS-G1 at stage A), :13806, :13838
check "$CSB1RC106|$(grep -c '^ON chase: ' "$R106/logs/act-csb1.txt" 2>/dev/null)|$(okline "$OUT" CS-G1 'none of the three claim switches')|$(grep -cF 'U4 UJ-ON WILL NOT JUDGE F3' <<<"$OUT")|$(went106 "$OUT")|$(( $(n103 "$(grep -c . "$R104/wait104.fail" 2>/dev/null)") - $(n103 "$WF0J_106") ))" \
      "0|1|yes|0|yesyes|0" \
  "106-1j1 the deploy held at its question: stage A held (CS-G1 ok, no capitals note), its evidence recorded, the question asked; on chase run through the installed tool while it waited (yes typed: ON, exit 0); DEPLOY typed once it had"
# deploy-5.18.106.sh:13838, :7413, :7427-7428, :7431-7432, :7433, :1691; :7469 (LK-B1, not reached); :13868-13870 (the copy, not reached)
check "$(grep -cF '  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s (key@place — k <data>/kyc_config.json, s its store row; no value is read out). ' <<<"$OUT")|$(order106 "$OUT" "  Type DEPLOY to deploy $V106 ($P106) over live " "  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s " "  step 6 (chase) — off chase also turns step 10 (cold) off:" "$OFFW106 chase" "  Then run --check again, its own command, alone:" "      cd $REPO && bash $F106 --check " "  STOP: NO-GO (CS-B1): a claim switch was turned on during this run, after stage A (quote_chase_enabled@k,s) — nothing was copied." "  Nothing further was done. Send the log file.")|$(grep -cxF "$OFFW106 chase" <<<"$OUT")|$(grep -cxF "$CHKL106" <<<"$OUT")|$(has "$OUT" 'NO-GO (LK-B1)')|$(rc106)|$(live)|$(installed106 "$B106F")|$(has "$OUT" '  copy            at ')" \
      "1|ordered|1|1|no|1|$B106|0|no" \
  "106-1j2 CS-B1 after DEPLOY: '  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s', the off command and --check again each alone, then 'NO-GO (CS-B1): a claim switch was turned on during this run, after stage A' — no LK-B1 refusal; exit 1; nothing copied: $B106 serving, every file $V106 changes still $BV106's"
tidy106 "106-1j-csb1"
O1J_106="$(sf104c off chase)"
check "$(sfrc104)|$(grep -cx 'OFF chase' <<<"$O1J_106")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)|$(backups106)|$(states106)|$(keyf106)" "0|1|0|0|0|nokey" \
  "106-1j3 …step 6 off again through the installed tool, and the held deploy's backup, state file and LK key moved into the case's folder"
cond106 "106-1j4 cond" "$B106" on off on
WF0K_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"
MW106="$(weak106 csb csb)"; R106_MUTANT_OUT=1 csb1run106 csb "$MW106" -- sf104c --tty yes on chase; rm -f "$MW106"
OUT="$CSB1OUT106"
# deploy-5.18.106.sh:7469 (LK-B1's refusal)
check "$CSB1RC106|$(went106 "$OUT")|$(has "$OUT" 'A CLAIM SWITCH IS PRESENT')|$(has "$OUT" 'NO-GO (LK-B1): a ladder key changed during this run')$(has "$OUT" 'nothing was copied')|$(rc106)|$(live)|$(installed106 "$B106F")|$(( $(n103 "$(grep -c . "$R104/wait104.fail" 2>/dev/null)") - $(n103 "$WF0K_106") ))" \
      "0|yesyes|no|yesyes|1|$B106|0|0" \
  "106-1j5 CSB, the copy without the CS-B1 call, the same held deploy with on chase while it waited: no CS-B1 text — LK-B1's generic refusal instead (caught by 106-1j2's text); nothing copied"
tidy106 "106-1j-csb"
O1J_106="$(sf104c off chase)"
check "$(sfrc104)|$(grep -cx 'OFF chase' <<<"$O1J_106")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)|$(backups106)|$(states106)|$(keyf106)" "0|1|0|0|0|nokey" \
  "106-1j6 …step 6 off again, the copy's backup, state file and LK key moved aside"

# ── 106-1k: the state handed to 106-4 — production's before the deploy: 5.18.105 serving, steps 1–5 on, step 6 off,
# followup_enabled on, followup_auto_send on, followup_max_age_hours 336; J1–J3 in the book as planted; master idle since J1's
# last pass; no weakened copy left; this part's fixtures undone (the refusal list stays for 106-6: a stand-in setting). ──
cond106 "106-1k cond" "$B106" on off on
U1M106="$(q106 u1mirror "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")"
check "$(kv106 "$U1M106" en)|$(kv106 "$U1M106" hours)|$(kv106 "$U1M106" ledger)|$(kv106 "$U1M106" places)|$(kv106 "$U1M106" classes)" \
      "on|336|record|5|held:1,f5:0,closes:0,f2:1,f1:2,waits:1" \
  "106-1k1 U1's ten places by this rehearsal's own mirror now (what stage A will read): followup_enabled on, the limit 336, the ledger recording — five approved drafts: J1's two fresh F1 closes, J1's stale one F2 expires, J3's first waits and its second is held"
SOK106="$(q106 staleorder "$DATA/plugin.sqlite3" 336 "$(fnow104)" 9000001 9000400)"
check "$(srtcsv106 "$(kv105 "$(lk105)" on)")|$(apd106)|$(kv106 "$SOK106" planted_open)|$(kv106 "$SOK106" first400_gone)|$(q106 rwd "$DATA/plugin.sqlite3" $J1FU106)|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)|$(sched104 followup_send)|$(sched104 followup_close)|$(pig106)|$(grep -c . "$SB/evo/rh106-refuse.list" 2>/dev/null)|$(ls "$REPO"/scripts/.cs1.sh "$REPO"/scripts/.fe1.sh "$REPO"/scripts/.u41.sh "$REPO"/scripts/.csb.sh 2>/dev/null | grep -c .)|$(backups106)|$(states106)|$(keyf106)" \
      "ack,list,report,tracking,voice-measure|n=5 fu=$(tr ' ' ',' <<<"$J1FU106"),$J3FU106,$J3FU106|400|400/400|$RW5_106|$F5A106|$SCH5_106|$PIG0_106|3|0|0|0|nokey" \
  "106-1k2 handed to 106-4: steps 1–5 on; the approved drafts J1's three then J3's two, nothing else; the 400 gone rows open and still the 400 oldest stale; J1's refusals as the jam left them; F5's count as J3 left it; master idle since J1's last pass and the piggyback record unchanged (A2); the refusal list J1's three; no weakened copy left; no backup, state file or LK key in $V106's run folder"
CLK3_106="$(fnow104)"
HELD106=-   # the registry's held channels (AutomationPolicy::sqlHeld) as this rehearsal states them to 106-4d's mirror: none — 106-1k1's mirror read the five places with none held; a registry holding a channel shows there as a held= mismatch, visibly
echo "  note  RH-NOTE 106-pre and 106-1 hand 106-4: $B106 ($BV106) serving; steps 1–5 on, step 6 off; followup_enabled on, followup_auto_send on, followup_daily_cap 3, followup_run_limit 2, followup_max_age_hours 336; J1 (follow-ups $J1FU106, its passes on Kampala $KWD106 from 09:00), J2 (follow-ups $J2FU106 and the 400 gone rows on ids 9 000 001–9 000 400), J3 (follow-up $J3FU106) in the book; no rollback of $V106 on record; the clock $(kday104 "$CLK3_106") $(TZ=Africa/Kampala date -d "@$CLK3_106" +%H:%M) Kampala"
