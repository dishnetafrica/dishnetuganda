# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.106's rehearsal, part 4 — 106-4 (the deploy, production's: D1) and 106-5 (--after-only on D1, then the copies of
# their own, D2–D6), as the rehearsal's design (18 Nov, v2) §C and §D set them. It takes the state 106-1 leaves: 5.18.105
# (7835906) serving, steps 1–5 on, step 6 (chase) off, followup_enabled on, followup_max_age_hours 336, followup_auto_send
# on, followup_daily_cap 3, followup_run_limit 2 (A11), the jam planted (J1–J3). It leaves 5.18.106 (bf29cbd) serving from
# D6's --redeploy — its stage A read with the age limit 0, so UJ-ON will not judge F3 for that deploy — followup_auto_send
# on, chase off, followup_enabled on, the limit 336, J3's pair still waiting, and D6's typed rollback the latest rollback
# record (AS_RB1=written). Every run is a 106 runner's, every rollback rbx106; no run here moves a copy back or writes
# master's schedule; master runs only through master106 (A2), and never the sender: J3 sends nothing until 106-6.
#   The numbers of 106's range this part plants — fictitious, +2567001060NN, proved free in 106-infra e and in the pattern
# files — each a conversation of its own, never used before: a conversation's last_customer_at never moves back, so every
# re-plant needs fresh numbers; a follow-up is never deleted, only closed (here by F3 itself, D2, D3 and D5's passes).
#     60, 61 (D2)   62, 63 (D3)   64, 65 (D5)   66–69 this part's, unused   — 98 and 99 are 106-infra's (A6's control)
# ══════════════════════════════════════════════════════════════════════════════════════
# rh106-seed.php refuses unless RH106_RANGE names 106's own ten digits: 106-infra's seed106 gives it to each run, read from
# PR106 — nothing here exports it (106-10f asserts the rehearsal's own environment never carries it).
LIM106=336                                                                   # followup_max_age_hours at the deploy (A11; 106-1 step 5)
PLOG106="$PD/data/plugin.log"; PLOGW106="$IN_CONTAINER/data/plugin.log"     # ME-CA1's log on the host, and as the script names it (S:8188)
dwal106() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; }        # the book's write-ahead log folded in before a read-only reading (as lk105 does)
dclose106() { dwal106; q106 closer "$DATA/plugin.sqlite3" "$(sget106 "$STATE106" COPY_AT)"; }   # the closer since this deploy's copy, as UJ-ON counts it (S:7139-7171)
dsto106() { dwal106; q106 staleorder "$DATA/plugin.sqlite3" "$@"; }        # dsto106 HOURS EPOCH [LO HI] — the stale and gone order at EPOCH (rh106q.py staleorder)
dnow106() { dwal106; q106 u1mirror "$DATA/plugin.sqlite3" "$PD" "$(fnow104)"; }   # the gate's fields as the jobs read them now (en=, hours=)
dnooffer106() {   # dnooffer106 — none when the --after-only in AFT106 offers no turn-back-on (as_back_on, S:8839): neither its line nor a --value 1 command
  [ "$(has "$AFT106" 'followup_auto_send reads off: the latest rollback set it off')$(grep -c -- '--key followup_auto_send --value 1 ' <<<"$AFT106")" = "no0" ] && echo none || echo offered
}
dpre106() {   # dpre106 LABEL SERVES KEY CLAIMS EN:HOURS LAST:WOULD — design-v2 §D, before a case, one check: the commit serving;
              # followup_auto_send as the jobs read it (rh106q.py kycplaces eff=); the claim switches present in
              # <data>/kyc_config.json or its store row by cs_found's own rule ('-' none: step 6 off); followup_enabled and the age
              # limit as the jobs read them now (rh106q.py u1mirror en= hours=); and the latest rollback record as as_rb_last's
              # mirror reads it, with what as_back_on would do were the key off (rh106q.py rblast last= would=)
  local k m r
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; m="$(dnow106)"; r="$(q106 rblast "$OUT106" "$STATE106")"
  check "$(live)|$(kv106 "$k" eff)|$(kv106 "$k" cs)|$(kv106 "$m" en):$(kv106 "$m" hours)|$(kv106 "$r" last):$(kv106 "$r" would)" \
        "$2|followup_auto_send:$3|$4|$5|$6" \
    "$1 the case's preconditions (design-v2 §D): $2 serving; followup_auto_send $3 as the jobs read it; the claim switches present: $4; followup_enabled:limit $5; the latest rollback record $6 (rh106q.py rblast — its AS_RB1, and what as_back_on would do with the key off)"
}
dredeploy106() {   # dredeploy106 LABEL — a --redeploy of 5.18.106 over 7835906 after its own typed rollback, DEPLOY typed, under the
                   # clock (design-v2 §C: the gates hold at each redeploy). The gates first, by this rehearsal's own readings: no claim
                   # switch (CS-G1's), followup_enabled on (FE-G1's), every settings place decodable. Then PASSED (exit 0), bf29cbd
                   # serving and installed, CS-G1 and LK-CA1 ok, no FE-G1 refusal, the earlier state file kept beside the new one
                   # (S:13737), COPY_AT on the clock within 60 s of QF_COPY_AT (A1), followup_close not run since the copy (A2's
                   # positive control), Evolution not called. Its output in DRD106_OUT — call it directly
  local k p0 o
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
  check "$(live)|$(kv106 "$k" cs)|$(kv106 "$(dnow106)" en)|$(kv106 "$k" file):$(kv106 "$k" row)|$(case "$(kv106 "$k" pcfg):$(kv106 "$k" dcfg)" in absent:absent|absent:decoded|decoded:absent|decoded:decoded) echo decodes ;; *) echo "$(kv106 "$k" pcfg):$(kv106 "$k" dcfg)" ;; esac)" \
        "$B106|-|on|decoded:decoded|decodes" \
    "$1 the gates before the --redeploy hold, by this rehearsal's own reading: $B106 serving after the typed rollback, no claim switch in <data>/kyc_config.json or its store row, followup_enabled on, every settings place decodable"
  p0="$(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .)"
  fresh106; o="$(run106c --answer DEPLOY --redeploy)"; DRD106_OUT="$o"
  check "$(fails "$o")$(has "$o" "  $V106 (deploy): PASSED.")|$(rc106)|$(went106 "$o")|$(live)|$(installed106 "$P106F")|$(okline "$o" CS-G1 'none of the three claim switches')$(okline "$o" LK-CA1 'after the copy every ladder key')$(has "$o" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(( $(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .) - p0 ))|$(copyok106)|$(ctl106)|$(cat "$R106/evo/run-$(last106).n" 2>/dev/null)" \
        "0yes|0|yesyes|$P106|0|yesyesno|1|near|before|0 nocron" \
    "$1' the --redeploy, DEPLOY typed, under the clock: PASSED (exit 0), $P106 serving and every file it changes installed as it has them, CS-G1 none (S:7409) and LK-CA1 ok (S:7494), no FE-G1 refusal; the earlier state file kept beside the new one; COPY_AT on the clock within 60 s of QF_COPY_AT (A1); followup_close not run since the copy (A2's positive control); Evolution not called"
}
doffer106() {   # doffer106 LABEL — the PASSED --after-only in AFT106 offers the owner's own command to turn followup_auto_send back on
                # (as_back_on, S:8826-8841; as_owner_text 1, S:8735): the latest rollback wrote it off (AS_RB1=written, its copy
                # before this deploy's) and it reads off — by this rehearsal's own reading too (rblast). The command, alone on its
                # line, is then run exactly as printed (run_line106c, under the clock, nothing typed): it prints
                # "  followup_auto_send = 1" and nothing from the settings listing on, its own log holds that line, exit status 0;
                # the key reads on again in the file and its store row, and every ladder key is as it was
  local r c o lg k k0
  r="$(q106 rblast "$OUT106" "$STATE106")"
  c="$(grep -m1 -E '^      docker exec .* --key followup_auto_send --value 1 2>&1 \| sed ' <<<"$AFT106" | cut -c7-)"
  check "$(kv106 "$r" last):$(kv106 "$r" before):$(kv106 "$r" would)|$(has "$AFT106" "  followup_auto_send reads off: the latest rollback set it off (AS_RB1=written in its record; its copy at ")$(has "$AFT106" "and it stays off until you turn it on. Turning it back on — automatic approval, as before that rollback — is your choice:")|$(grep -cE '^      docker exec .* --key followup_auto_send --value 1 ' <<<"$AFT106")|$(case "$c" in "docker exec -u $(owner104) -e DN_DATA_DIR="*" ucrm php $IN_CONTAINER/tools/set_config.php --key followup_auto_send --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 autosend-1)") echo form ;; *) echo other ;; esac)|$(has "$AFT106" '  It prints the line "  followup_auto_send = 1" once it has saved;')" \
        "written:yes:offer-if-off|yesyes|1|form|yes" \
    "$1 as_back_on offers the owner's own command to turn followup_auto_send back on (S:8839, S:8735): the latest rollback wrote it off, its copy before this deploy's, and it reads off — this rehearsal's own reading agrees (rblast: offer-if-off); the command alone on its line, in the owner's form (set_config as the book's owner, its output cut where the settings listing begins, its own log)"
  k0="$(digest105)"
  o="$(run_line106c "" "$c")"
  lg="$(ls -1t "$OUT106"/autosend-1-*.log 2>/dev/null | head -1)"
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
  check "$(grep -cx '  followup_auto_send = 1' <<<"$o")|$(grep -c '^  AI SETTINGS' <<<"$o")|$(rc106)|$(grep -cx '  followup_auto_send = 1' "${lg:-/nonexistent}" 2>/dev/null)|$(kv106 "$k" eff)|$(kv106 "$k" asplaces)|$( [ "$(digest105)" = "$k0" ] && echo kept)" \
        "1|0|0|1|followup_auto_send:on|k:on,s:on,p:-,d:-|kept" \
    "$1' the offered command run exactly as printed (run_line106c, under the clock): it prints \"  followup_auto_send = 1\" and nothing from \"  AI SETTINGS\" on, exit status 0, its own log ($(basename "${lg:-none}")) holds the line; followup_auto_send reads on again in <data>/kyc_config.json and its store row; every ladder key as before"
}
dstale106() {   # dstale106 LABEL NN… — present stale enquiry follow-ups (design-v2 §C: D2, D3 and D5's re-plants), one for each number
                # NN of 106's range, through the pin's own classes (rh106-seed.php's enquiry op, upto open: the customer's message
                # stored with last_customer_at 420 h before the clock's now, then FollowUpService::open — never raw SQL), each a
                # conversation of its own. Asserted: every op done, each aged 420 h by the clock; by this rehearsal's own reading
                # (staleorder, now) exactly these are stale with a present conversation under 336 h and none under 504 h — aged
                # between the two limits — and fewer than 200 gone rows are open. Their ids in DST106; the installed public.php's
                # piggyback record then in PIGP106 (A2: it must not move before the case's last run)
  local lab="$1" ops o r s3 s5 n; shift; n=$#
  ops="$(python3 -I -c 'import json, sys
print(json.dumps([{"op": "enquiry", "phone": p, "name": "RH106SECRET-quiet-" + p[-2:], "text": "RH106SECRET-an enquiry gone quiet " + p[-2:], "lca_ago_s": 1512000, "upto": "open"} for p in sys.argv[1:]]))' $(for x in "$@"; do ph106 "$x"; done))"
  o="$(plant106 "$ops")"
  r="$(python3 -I -c 'import json, sys
try:
    d = json.loads(sys.argv[1]); dn = d["done"]
    ok = d["ops"] == len(dn) == int(sys.argv[2]) and all(x["op"] == "enquiry" and x["approved"] == 0 and x["drafts"] == [] and 1512000 <= x["age_s"] <= 1512005 for x in dn)
    print(("planted" if ok else "bad") + " " + (",".join(str(x["id"]) for x in dn) or "-"))
except (ValueError, KeyError, TypeError) as e:
    print("unread:" + type(e).__name__ + " -")' "$o" "$n")"
  DST106="${r#* }"; PIGP106="$(pig106)"
  s3="$(dsto106 "$LIM106" "$(fnow104)")"; s5="$(dsto106 504 "$(fnow104)")"
  check "${r%% *}|$(kv106 "$s3" stale_present)|$(kv106 "$s5" stale_present)|$( [ "$(n103 "$(kv106 "$s3" open_gone)")" -lt 200 ] && echo lt200 || echo "gone:$(kv106 "$s3" open_gone)")" \
        "planted|$n|0|lt200" \
    "$lab $n present stale enquiry follow-up(s) planted through the pin's own classes (rh106-seed.php enquiry, upto open — numbers $*, ids $DST106), each aged 420 h by the clock: by this rehearsal's own reading exactly these are stale with a present conversation under $LIM106 h and none under 504 h; fewer than 200 gone rows open ($(kv106 "$s3" open_gone))"
}
dstdrop106() {   # dstdrop106 KEY — this deploy's state file with its one line beginning KEY= taken out, every other byte as it was: an
                 # asserted edit in place (inode, owner and mode kept); prints removed, or miss (recorded in anchor-missing). A FIXTURE:
                 # no writer of the script's ever takes a line out (design-v2 A9) — keep106 before it, back106 after, both asserted
  python3 -I - "$STATE106" "$1" <<'PY' || { echo "rh106:stdrop:$1" >> "$SB/anchor-missing"; echo miss; }
import sys
p, k = sys.argv[1], sys.argv[2].encode() + b'='
b = open(p, 'rb').read()
ls = b.splitlines(keepends=True)
drop = [l for l in ls if l.startswith(k)]
assert len(drop) == 1, len(drop)
nb = b''.join(l for l in ls if not l.startswith(k))
assert len(nb) == len(b) - len(drop[0]) and not any(l.startswith(k) for l in nb.splitlines(keepends=True))
with open(p, 'r+b') as f:
    f.write(nb)
    f.truncate()
print('removed')
PY
}
dmets106() {   # dmets106 COPYUNIX OFF… — the plugin's zone as ME_PHP reads it (S:8129-8130): the INSTALLED lib/timezone.php's dn_tz(),
               # called with no config, in the container as the book's owner, with the data folder the script's runs carry (run106's
               # DN_DATA_DIR) — never assumed Kampala. Prints "tz=<zone> off=<its UTC offset at COPYUNIX, s> at=<stamp>,…", each stamp
               # COPYUNIX+OFF in that zone as master_log writes it (date('Y-m-d H:i:s'), its space as _); OFF is p<s> or m<s>, never a
               # leading dash (php would read one as its own option)
  local cu="$1"; shift
  env DN_DATA_DIR="$DATA" PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u "$(owner104)" ucrm php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r '
$r = (string)$argv[1]; $cu = (int)$argv[2];
require_once $r . "/lib/timezone.php";
$tz = dn_tz(); $z = new DateTimeZone($tz);
$d = new DateTime("@" . $cu); $d->setTimezone($z);
$s = [];
for ($i = 3; $i < count($argv); $i++) {
    $a = (string)$argv[$i]; $n = (int)substr($a, 1); if ($a[0] === "m") $n = -$n;
    $e = new DateTime("@" . ($cu + $n)); $e->setTimezone($z); $s[] = $e->format("Y-m-d_H:i:s");
}
echo "tz=", $tz, " off=", $d->getOffset(), " at=", implode(",", $s);' "$IN_CONTAINER" "$cu" "$@" 2>/dev/null </dev/null
}
dmecase106() {   # dmecase106 LABEL VERDICT LINE… — ME-CA1 on planted lines (design-v2 §C 106-5b; RH106-14, F9). A FIXTURE: in the
                 # sandbox master's output goes to master-*.out (master104), never to plugin.log, so every [master] line there is this
                 # rehearsal's. plugin.log marked (mark106: its size and checksum); each LINE, "OFF|TEXT", appended as master_log writes
                 # it ("[Y-m-d H:i:s] [master] TEXT", bf29cbd cron/master.php:440-443), stamped COPY_AT+OFF in the installed dn_tz()
                 # (dmets106); the --after-only (aft106 LABEL VERDICT, steps 1–5 judged); then plugin.log cut back to its mark (cut106)
                 # — the undo, asserted (A9). The run's output stays in AFT106, the zone's reading in MET106
  local lab="$1" want="$2" cu m l offs="" i=0 c0 c1 cb at; shift 2
  cu="$(sget106 "$STATE106" COPY_AT)"
  for l in "$@"; do offs="$offs ${l%%|*}"; done
  MET106="$(dmets106 "$cu" $offs)"; at="$(kv106 "$MET106" at)"
  m="$(mark106 "$PLOG106")"; c0="$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)"
  for l in "$@"; do i=$((i+1)); printf '[%s] [master] %s\n' "$(cut -d, -f"$i" <<<"$at" | tr '_' ' ')" "${l#*|}" >> "$PLOG106"; done
  c1="$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)"
  aft106 "$lab" "$want" $L1ON106
  cb="$(cut106 "$PLOG106" "$m")"
  check "$(n103 "$c0")|$(( $(n103 "$c1") - $(n103 "$c0") ))|$(tr ',' '\n' <<<"$at" | grep -c '^[0-9]\{4\}-[0-9][0-9]-[0-9][0-9]_[0-9][0-9]:[0-9][0-9]:[0-9][0-9]$')|$cb|$(mark106 "$PLOG106")" \
        "0|$#|$#|same|$m" \
    "$lab' the fixture undone (A9): plugin.log held no [master] line before; the $# planted lines, each stamped in the installed dn_tz() ($(kv106 "$MET106" tz)), were appended; afterwards it is cut back to its recorded size and checksum ($m)"
}
dround106() {   # dround106 D RBWANT [LIMIT HOURS] — a copy of its own (design-v2 §C, 106-5 D2–D6): its preconditions (bf29cbd
                # serving, the key on, no claim switch, followup_enabled on at 336 h, the latest rollback record RBWANT); the typed
                # rollback (rbx106), which writes followup_auto_send off (AS-RB1: the key read on and §L.7's five conditions hold), its
                # record then the latest by this rehearsal's own reading; with LIMIT, followup_max_age_hours set to it through the real
                # set_config before the --redeploy (D6: HOURS as the jobs will read it); the --redeploy with the gates holding
                # (dredeploy106); then the new deploy's preconditions — the key off, the latest record written and before its copy,
                # so that its first PASSED --after-only offers the turn-back-on
  local d="$1" want="$2" k r o
  dpre106 "106-5${d}a" "$P106" on - "on:$LIM106" "$want"
  rbx106 "106-5${d}b"
  dwal106; k="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; r="$(q106 rblast "$OUT106" "$STATE106")"
  check "$(sget106 "$RBX106_REC" AS_RB1)|$(okline "$RBX106_OUT" AS-RB1 "followup_auto_send set off before the put-back (typed)")|$(kv106 "$k" eff)|$(kv106 "$k" asplaces)|$(kv106 "$r" last):$(kv106 "$r" file)|$(kv106 "$r" before):$(kv106 "$r" would)" \
        "written|yes|followup_auto_send:off|k:off,s:off,p:-,d:-|written:$(basename "$RBX106_REC")|no:nothing:not-before" \
    "106-5${d}c the typed rollback wrote followup_auto_send off before the put-back (AS-RB1, S:9031; AS_RB1=written in its record), read off now in the file and its store row; its record the latest by this rehearsal's own reading (rblast), its RB_COPY_AT — on the clock (A1) — after the rolled-back deploy's COPY_AT"
  if [ -n "${3:-}" ]; then
    o="$(setcfg106 followup_max_age_hours "$3")"
    check "$(grep -cx "  followup_max_age_hours = $3" <<<"$o")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$4" \
      "106-5${d}c2 followup_max_age_hours set to $3 through the installed set_config as the book's owner, under the clock (it prints \"  followup_max_age_hours = $3\"); this rehearsal reads it as the jobs do: $4"
  fi
  dredeploy106 "106-5${d}d"
  dpre106 "106-5${d}e" "$P106" off - "on:${4:-$LIM106}" "written:offer-if-off"
}
dfailchk106() {   # dfailchk106 LABEL N — UJ-ON's deterministic FAIL in AFT106 (S:8096), against this rehearsal's own readings since the
                  # copy: the control held (followup_close started after the copy and ended), nothing closed expired, its start T the
                  # close job's last run, the cancels at or after T, and expireStale's SELECT at T under the limit stage A read — N
                  # stale rows with a present conversation (staleorder); no UJ-ON PASS or NOT YET line
  local c s f
  c="$(dclose106)"; s="$(dsto106 "$LIM106" "$(kv106 "$c" fc_last)")"
  f="$(sed -nE 's/^  FAIL  UJ-ON FAIL — F3 did not expire: followup_close started at ([^,]+), after the copy, and ended; none closed expired by the closer since the copy, ([0-9]+) closed cancelled at or after that start \(fewer than 200, so a pass.s cap does not explain it\), and expireStale.s SELECT at [^ ]+ still selects ([0-9]+) stale enquiry follow-up\(s\) with a present conversation; .*/\1 \2 \3/p' <<<"$AFT106")"
  check "$(grep -c '^  FAIL  UJ-ON FAIL — F3 did not expire: ' <<<"$AFT106")|${f:-none}|$(kv106 "$c" control):$(kv106 "$c" expired)|$(kv106 "$s" stale_present)|$(grep -cE '^  (ok    UJ-ON PASS|wait  UJ-ON NOT YET)' <<<"$AFT106")|$(ctl106)" \
        "1|$(kv106 "$c" t) $(kv106 "$c" on_cancelled_t) $2|held:0|$2|0|after" \
    "$1 UJ-ON FAIL — F3 did not expire (S:8096): its start and its cancels as this rehearsal reads them (followup_close's last run $(kv106 "$c" t), $(kv106 "$c" on_cancelled_t) cancelled at or after it), nothing closed expired since the copy, and expireStale's SELECT at that start still selecting $2 stale row(s) with a present conversation by this rehearsal's own reading; no UJ-ON PASS or NOT YET line"
}
dpasschk106() {   # dpasschk106 LABEL N — UJ-ON's PASS in AFT106 (S:8085): N closed expired by the closer since the copy, as this
                  # rehearsal's own reading counts them (rh106q.py closer on_expired, F3's own detail), the control held
  local c n; c="$(dclose106)"
  n="$(sed -nE 's/^  ok    UJ-ON PASS — F3 works: ([0-9]+) enquiry follow-up\(s\) closed expired by the closer since the copy, each with F3.s own detail .*/\1/p' <<<"$AFT106")"
  check "${n:-none}|$(kv106 "$c" on_expired)|$(kv106 "$c" control)|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "$2|$2|held|0" \
    "$1 UJ-ON PASS — F3 works (S:8085): $2 enquiry follow-up(s) closed expired by the closer since the copy, with F3's own detail — as many as this rehearsal's own reading counts (rh106q.py closer), the control held"
}
WILLNOT106="but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: its gate did not hold at stage A (followup_max_age_hours was not a positive number at stage A), and stage A's reading is fixed in the deploy's state file, so F3 stays unproven and the steps that wait for it (6, 11, 12) would follow without that proof; a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate."   # check it's arm when stage A's gate did not hold (S:16019)
NOWARM106="UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not) and a close pass has run after that (followup_close runs every 10 minutes) — run it only then: a run before that pass judges F3 by passes made while the gate did not hold, and can read FAIL; while it does not hold it gives that note, no verdict, and the run reads PASSED without that proof"   # check it's 'now' arm (S:16017)
NOWEN106="  note  UJ-ON not judged — its gate did not hold: followup_enabled is off now. F3 then has nothing to do, or another limit to do it by. Once it holds again, run --after-only only after a close pass has run under it (followup_close runs every 10 minutes): a run before that judges F3 by passes made while it did not hold, and can read FAIL; a note, which holds back nothing"   # UJ-ON's 'now' note, followup_enabled off now (S:8073)

echo; echo "== 106-4. the deploy, production's (D1): $V106 over $BV106 with the jam planted — PASSED, U1–U5 as this rehearsal reads them =="
# What 106-1's deploys that typed DEPLOY and then stopped (CS-B1), or were answered otherwise, may have left in the run folder — a
# backup, the state file, the LK key — moved into this rehearsal's own folder first, so that D1 is a first deploy, as production's is.
tidy106 "106-4-pre"
dpre106 "106-4a" "$B106" on - "on:$LIM106" "-:nothing:no-copy-at"
check "$(states106)|$(backups106)|$(keyf106)|$(left106)|$(inst_ver)" "0|0|nokey|0|$BV106" \
  "106-4a' a first deploy: no state file or rollback record of $V106's in its run folder, no backup, no LK key; none of the $NA106 files $V106 adds on disk; $BV106's manifest installed"
PIG4_106="$(pig106)"
fresh106; O4_106="$(run106c --answer DEPLOY)"; T4_106="$(last106)"
check "$(fails "$O4_106")$(has "$O4_106" "  $V106 (deploy): PASSED.")|$(rc106)|$(went106 "$O4_106")|$(live)|$(installed106 "$P106F")|$(copyok106)|$(ctl106)|$( [ "$(pig106)" = "$PIG4_106" ] && [ "$PIG4_106" = "${PIG0_106:-unset}" ] && echo kept)|$(cat "$R106/evo/run-$T4_106.n" 2>/dev/null)" \
      "0yes|0|yesyes|$P106|0|near|before|kept|0 nocron" \
  "106-4b D1, DEPLOY typed, under the clock, no flag (none of $V106's files on disk): PASSED (exit 0), $P106 serving and every file it changes installed as it has them; COPY_AT on the clock within 60 s of QF_COPY_AT (A1); followup_close not run since the copy (A2's positive control); the installed public.php's piggyback record unchanged from J2's plant (106-pre5's PIG0_106) through the run — A2's plant-to-copy span; Evolution not called, no cron stand-in"
check "$(okline "$O4_106" CS-G1 'none of the three claim switches')$(okline "$O4_106" LK-CA1 'after the copy every ladder key')$(okline "$O4_106" R1 all "files $V106 changes are installed exactly as $P106 has them")$(okline "$O4_106" R1 "the installed manifest says $V106")$(okline "$O4_106" Q-CA1 "installed = $V106's release")|$(has "$O4_106" 'U4 UJ-ON WILL NOT JUDGE F3')$(has "$O4_106" 'U4 STAFFJOBSGATE::APPLIES IS FALSE')$(has "$O4_106" 'U4 followup_enabled is off')$(has "$O4_106" 'FE-G1 FOLLOWUP_ENABLED READS OFF')|$(has "$O4_106" 'UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again')" \
      "yesyesyesyesyes|nononono|yes" \
  "106-4c …CS-G1 none (S:7409), LK-CA1 (S:7494), R1 (S:14400, S:14402) and Q-CA1 (S:14432) ok; no U4 capitals note, no StaffJobsGate note, no followup_enabled note, no FE-G1 refusal (S:7983, S:7982, S:7984, S:8006); check it says UJ-ON judges F3 (S:16017's last arm: stage A's gate held)"
# U1–U5 against this rehearsal's own readings, read at once (nothing writes the book in between), at stage A's own moment (UJ_A's
# at=, the deploy's raw stage-A line, S:13776), so the 24 h and 30-day windows are the deploy's
UJA4_106="$(sget106 "$STATE106" UJ_A)"; AT4_106="$(kv106 "$UJA4_106" at)"
case "$(kv106 "$UJA4_106" held)" in none) HS4_106=- ;; *) HS4_106="${HELD106:-unstated}" ;; esac   # the registry's held channels: none, or as 106-pre states them (HELD106)
dwal106
M14_106="$(q106 u1mirror "$DATA/plugin.sqlite3" "$PD" "$AT4_106" "$HS4_106" 1)"
M24_106="$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$AT4_106" 1)"
SO4_106="$(q106 staleorder "$DATA/plugin.sqlite3" "$LIM106" "$AT4_106" 9000001 9000400)"
K4_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
CF4_106="$(kv106 "$UJA4_106" classes | awk -F'[:,]' 'NF == 12 { printf "held (a second draft this pass) %s, F5 expires (attempt taken) %s, closes (gone, colleague, owned number, opted out, taken over or replied) %s, F2 expires (stale) %s, F1 closes %s, waits %s", $2, $4, $6, $8, $10, $12 }')"   # uj_places' own sentence (S:7960)
PL4_106="$(kv106 "$K4_106" asplaces | sed -e 's/^k:/kyc_config.json /' -e 's/,s:/, its store row /' -e 's#,p:#, <plugin>/data/config.json #' -e 's#,d:#, <data>/config.json #')"   # as_places_say's own words (S:8679)
check "$(case "$UJA4_106" in "uj=read at="[0-9]*) echo whole ;; *) echo "${UJA4_106:0:24}" ;; esac)|$(q105 same "$UJA4_106" "$M14_106" out,held,places,place,classes,en,hours,cutoff,ledger,ug)|$( [ "$(n103 "$(sed -n 's/.*,f1:\([0-9]*\),.*/\1/p' <<<"$(kv106 "$UJA4_106" classes)")")" -ge 1 ] && echo f1)|$( [ "$(n103 "$(sed -n 's/^held:\([0-9]*\),.*/\1/p' <<<"$(kv106 "$UJA4_106" classes)")")" -ge 1 ] && echo held)|$( [ -n "$CF4_106" ] && noteline "$O4_106" U1 "the sender's ten places" "$CF4_106")" \
      "whole|same=yes|f1|held|yes" \
  "106-4d U1 as this rehearsal reads it (rh106q.py u1mirror at stage A's own moment, $AT4_106; the registry's held channels $HS4_106): each channel's deliveries, the sender's ten places in its order and each place's class equal the deploy's stage-A reading (UJ_A in its state file); J1's refused drafts class F1 closes and J3's second draft held ($(kv106 "$UJA4_106" classes)); U1's note carries those counts (S:7968)"
check "$(q105 same "$UJA4_106" "$M24_106" hours,cutoff,ug,last_send,sends7,approved,approved_stale,approved_fresh,approved_gone,failed7,failed7_f1,open,open_age,due105,due106,xs_all,xs_passes,xs_first_expire,xs_first_cancel,f5_multi)|$(kv106 "$UJA4_106" xs_first_cancel):$(kv106 "$UJA4_106" xs_first_expire):$( [ "$(n103 "$(kv106 "$UJA4_106" xs_all)")" -ge 401 ] && echo ge401)|$(kv106 "$UJA4_106" f5_multi)|$(kv106 "$SO4_106" first400_gone):$(kv106 "$SO4_106" gone_first):$(kv106 "$SO4_106" gone_older):$(kv106 "$SO4_106" planted_open):$(kv106 "$SO4_106" planted_conv)|$(noteline "$O4_106" U3 "its first pass (LIMIT 200): 0 close expired, 200 cancelled (a gone conversation); F5: 1/2 (follow-ups/drafts) hold two or more approved drafts")" \
      "same=yes|200:0:ge401|1/2|400/400:yes:yes:400:0|yes" \
  "106-4e U2 and U3 as this rehearsal reads them (rh106q.py u2u3 at stage A's moment) equal the deploy's — the sends, the approved drafts by stale, fresh and gone, the 400s, the open rows by age, due() under each release, expireStale's forecast; A3 by this rehearsal's own order (staleorder): the 400 oldest stale rows all gone conversations, every gone row older than every present one, the 400 on conversation ids 9000001–9000400 open with no conversation there — so U3's first pass cancels 200 and expires none ($(kv106 "$UJA4_106" xs_all) stale in all, S:7972); J3 the one follow-up holding two approved drafts (F5 1/2)"
check "$(kv106 "$UJA4_106" en):$(kv106 "$UJA4_106" auto):$(kv106 "$UJA4_106" auto_places):$(kv106 "$UJA4_106" cap):$(kv106 "$UJA4_106" run):$(kv106 "$UJA4_106" hours):$(kv106 "$UJA4_106" ug)|$(noteline "$O4_106" U4 'the settings as the jobs read them' 'followup_enabled on, followup_auto_send on (present in: k,s' 'followup_daily_cap 3, followup_run_limit 2' "followup_max_age_hours $LIM106 — F2 and F3 active" 'StaffJobsGate::applies Uganda')|$(kv106 "$K4_106" eff):$(kv106 "$K4_106" pre13)|$(noteline "$O4_106" U5 'whether a rollback could write followup_auto_send' "it reads on as the jobs read it (per place: $PL4_106; - absent, ? unread)" "§L.7's five conditions hold — a rollback would set it off itself before the put-back (AS-RB1), recomputing them just before")" \
      "on:on:k,s:3:2:$LIM106:1|yes|followup_auto_send:on:ok|yes" \
  "106-4f U4 on the copy reads production's settings (A11): followup_enabled on, followup_auto_send on (the file and its store row), followup_daily_cap 3, followup_run_limit 2, the limit $LIM106 h, StaffJobsGate Uganda — its note says so (S:7981); U5 reads on … hold (S:8773), its places as this rehearsal reads them (kycplaces: on, §L.7's conditions 1–3 holding)"

echo; echo "== 106-5. --after-only on D1: NOT YET before any pass, ME-CA1's cases, stage A's reading and the copy's time out of the record, the reading failing; pass 1 NOT YET, the limit and 'now' notes, pass 2 PASS =="
# 5a — before any close pass (A2's positive control: followup_close's last run at or before this deploy's copy)
dpre106 "106-5a0" "$P106" on - "on:$LIM106" "-:nothing:no-record"
CL5A106="$(dclose106)"
check "$(ctl106)|$(kv106 "$CL5A106" control):$(kv106 "$CL5A106" expired):$(kv106 "$CL5A106" cancelled)" "before|not:0:0" \
  "106-5a1 A2's positive control for NOT YET: followup_close's last run (master_schedule.json) is at or before this deploy's COPY_AT — no close pass since the copy; nothing closed expired or cancelled since it (rh106q.py closer)"
aft106 "106-5a" NOTYET $L1ON106
AFT5A106="$AFT106"; NYT106="The owner's steps (root docs/75 §F) wait for a PASSED --after-only. Then --after-only again, its own command, alone, with its own log:"
check "$(grep -c '^  wait  UJ-ON NOT YET — followup_close has not started and ended since the copy (its last start ' <<<"$AFT106")|$(grep -cE '^  checks +[0-9]+ ok, 0 failed, [0-9]+ notes, 1 not yet$' <<<"$AFT106")|$(awk -v t="$NYT106" 'f { print; exit } index($0, t) { f = 1 }' <<<"$AFT106")|$(has "$AFT106" "  the owner's steps root docs/75 §F")$(has "$AFT106" "  ECO Bank's own check, read-only")|$(dnooffer106)" \
      "1|1|      $EXP_AFTER106|nono|none" \
  "106-5a' UJ-ON NOT YET — followup_close has not started and ended since the copy (S:8099-8100); the count line ends \", 1 not yet\" (S:16081); the NOT YET block's one next command, the --after-only alone (S:16086-16088); the owner's steps (S:16103) and ECO Bank's check (S:16162) absent; as_back_on silent"
# 5b — ME-CA1 (S:8186-8206), each case on plugin.log lines this rehearsal plants and cuts away again (RH106-14, F9); UJ-ON reads
# NOT YET throughout (no close pass yet), so the run's exit status is 3, or 1 where ME-CA1 FAILs
dpre106 "106-5b" "$P106" on - "on:$LIM106" "-:nothing:no-record"
CU5B106="$(sget106 "$STATE106" COPY_AT)"; MZ5B106="$(dmets106 "$CU5B106" p0)"; MZ106="$(kv106 "$MZ5B106" tz)"
check "$( [ -f "$PLOG106" ] && echo present)|$(n103 "$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)")|$( [ ! -s "$PLOG106" ] || [ "$(tail -c1 "$PLOG106" | od -An -tx1 | tr -d ' \n')" = "0a" ] && echo whole-lines)|$( [ -n "$MZ106" ] && echo zone)|$(ctl106)|$(grep -cxF "  note  ME-CA1 NOT ESTABLISHED — THE POSITIVE CONTROL DID NOT HOLD IN $PLOGW106, WHERE MASTER'S OUTPUT WAS LOOKED FOR: it needs a [master] line stamped at or before the copy (0 read) and a [master] RUN followup_ line stamped after it (0 read), the stamps read in the plugin's zone ($MZ106); master's errors after the copy are not judged here — run --after-only again once master has run a follow-up job since the copy; a note, which holds back nothing" <<<"$AFT5A106")" \
      "present|0|whole-lines|zone|before|1" \
  "106-5b0 ME-CA1 with no [master] line in plugin.log (106-5a's run, S:8200): NOT ESTABLISHED, a note — in the sandbox master's output goes to master-*.out, so plugin.log holds none (measured: 0; the file ends a line, so a planted line starts its own); the plugin's zone read through the installed lib/timezone.php's dn_tz() as ME_PHP calls it ($MZ106), never assumed; still no close pass since the copy"
dmecase106 "106-5b1" NOTYET "m60|RUN followup_scan" "p30|ERROR followup_send: RH106SECRET-me fixture inside the straddle" "p40|Cycle complete in 100s. Jobs run: followup_scan, followup_send" "p700|RUN followup_close"
check "$(grep -cxF "  note  ME-CA1 1 ERROR line(s) of followup_close, followup_scan, followup_run or followup_send stamped after the copy inside the cycle it straddled (3 [master] line(s), up to its own Cycle complete) — a job of that cycle met the old class beside the new; a note" <<<"$AFT106")|$(grep -cxF "  ok    ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $PLOGW106 after the cycle the copy straddled (4 [master] lines read, stamped in the plugin's zone $MZ106; the control: 1 at or before the copy, 1 RUN followup_ after it)" <<<"$AFT106")" "1|1" \
  "106-5b1'' an ERROR of followup_send stamped after the copy inside the cycle the copy straddled (a RUN before the copy, then the error, then that cycle's Cycle complete): a note naming it, 3 lines up to its own Cycle complete (S:8203); none after the straddle (S:8204)"
dmecase106 "106-5b2" FAILED "m60|RUN followup_scan" "p30|Cycle complete in 90s. Jobs run: followup_scan" "p700|RUN followup_close" "p710|ERROR followup_close: RH106SECRET-me fixture after the straddle"
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -cxF "  FAIL  ME-CA1 1 ERROR line(s) of the four follow-up jobs in $PLOGW106 after the cycle the copy straddled (by job: close 1 scan 0 run 0 send 0) — in the 24 h before the copy, for comparison: 0; counts only, the lines are in that file" <<<"$AFT106")|$(grep -c '^  note  ME-CA1 [0-9]* ERROR line' <<<"$AFT106")|$(grep -c '^  wait  UJ-ON NOT YET — ' <<<"$AFT106")" "1|1|0|1" \
  "106-5b2'' an ERROR of followup_close stamped after the cycle the copy straddled (that cycle ended by its own Cycle complete before it): ME-CA1's FAIL by job (S:8205), its only FAIL; no note of one inside the straddle; UJ-ON still NOT YET — ME-CA1 decides the exit status, 1"
OFF5B106="$(n103 "$(kv106 "$MZ5B106" off)")"; DM5B106=$(( OFF5B106 / 2 ))   # the mirror stamp: half the zone's offset before the copy
check "$( [ "$OFF5B106" -ge 2 ] && echo east || echo "off:$OFF5B106")" "east" \
  "106-5b3a the mirror stamp can be built: the plugin's zone ($MZ106) is $OFF5B106 s east of UTC at the copy — a stamp $DM5B106 s before the copy in that zone reads, taken as UTC, after it (F9)"
dmecase106 "106-5b3" NOTYET "m60000|RUN followup_scan" "m59990|Cycle complete in 10s. Jobs run: followup_scan" "m$DM5B106|ERROR followup_send: RH106SECRET-me mirror fixture" "p700|RUN followup_close"
ST5B3_106="$(cut -d, -f3 <<<"$(kv106 "$MET106" at)" | tr '_' ' ')"
check "$(grep -cxF "  ok    ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $PLOGW106 after the cycle the copy straddled (4 [master] lines read, stamped in the plugin's zone $MZ106; the control: 3 at or before the copy, 1 RUN followup_ after it)" <<<"$AFT106")|$(grep -cE '^  (FAIL|note)  ME-CA1 [0-9]+ ERROR line' <<<"$AFT106")|$( [ "$(n103 "$(TZ=UTC date -d "$ST5B3_106" +%s 2>/dev/null)")" -gt "$CU5B106" ] && echo utc-after)" "1|0|utc-after" \
  "106-5b3'' the mirror (F9): an ERROR stamped $DM5B106 s before the copy in the plugin's zone ($ST5B3_106), which read as UTC would fall after the copy, is not counted — 3 [master] lines at or before the copy, no ERROR note or FAIL (S:8204)"
dmecase106 "106-5b4" NOTYET "m60|RUN followup_scan" "p20|ERROR followup_scan: RH106SECRET-me fixture before the next cycle" "p600|RUN followup_scan" "p610|RUN followup_close"
check "$(grep -cxF "  note  ME-CA1 1 ERROR line(s) of followup_close, followup_scan, followup_run or followup_send stamped after the copy inside the cycle it straddled (2 [master] line(s), up to the next cycle's first RUN of a job it had run) — a job of that cycle met the old class beside the new; a note" <<<"$AFT106")|$(grep -cxF "  ok    ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $PLOGW106 after the cycle the copy straddled (4 [master] lines read, stamped in the plugin's zone $MZ106; the control: 1 at or before the copy, 2 RUN followup_ after it)" <<<"$AFT106")" "1|1" \
  "106-5b4'' a straddle with no Cycle complete: it ends at the next RUN of a job it had already run (S:8158-8167) — the ERROR before that inside it, a note (S:8203); none after"
dmecase106 "106-5b5" FAILED "m120|RUN followup_scan" "m1|Cycle complete in 119s. Jobs run: followup_scan" "p20|ERROR followup_send: RH106SECRET-me fixture with no straddle" "p700|RUN followup_close"
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -cxF "  FAIL  ME-CA1 1 ERROR line(s) of the four follow-up jobs in $PLOGW106 after the cycle the copy straddled (by job: close 0 scan 0 run 0 send 1) — in the 24 h before the copy, for comparison: 0; counts only, the lines are in that file" <<<"$AFT106")|$(grep -c '^  note  ME-CA1 [0-9]* ERROR line' <<<"$AFT106")|$(grep -c '^  wait  UJ-ON NOT YET — ' <<<"$AFT106")" "1|1|0|1" \
  "106-5b5'' a copy no cycle straddled (a Cycle complete stamped a second before it): an ERROR of followup_send 20 s after the copy is after the straddle — ME-CA1's FAIL (S:8205), its only FAIL; UJ-ON still NOT YET"
KP5B7_106="$(keep106 p4-plog5b7 "$PLOG106")"; rm -f "$PLOG106"   # a FIXTURE (A9): plugin.log taken away for this one run, put back byte for byte after it
aft106 "106-5b7" NOTYET $L1ON106
check "$(grep -cxF "  note  ME-CA1 NOT ESTABLISHED — MASTER'S OUTPUT WAS LOOKED FOR IN $PLOGW106 AND COULD NOT BE READ THERE (log=absent tz=$MZ106): a host whose master writes elsewhere is not judged here; a note, which holds back nothing" <<<"$AFT106")|$(back106 p4-plog5b7)|$(sha256sum < "$PLOG106" 2>/dev/null | cut -c1-16)" "1|same|$KP5B7_106" \
  "106-5b7' the log absent: ME-CA1 NOT ESTABLISHED — COULD NOT BE READ THERE (log=absent, S:8132, S:8194), a note; plugin.log then put back byte for byte, with its owner, mode and time (A9)"
# 5c — stage A's reading out of the record: a FIXTURE (A9), the state file's UJ_A line taken out, put back byte for byte after
dpre106 "106-5c" "$P106" on - "on:$LIM106" "-:nothing:no-record"
UJ5C106="$(sget106 "$STATE106" UJ_A)"; KP5C106="$(keep106 p4-st5c "$STATE106")"
check "$( [ -n "$UJ5C106" ] && echo read)|$(dstdrop106 UJ_A)|$(grep -c '^UJ_A=' "$STATE106")" "read|removed|0" \
  "106-5c0 a FIXTURE (A9): this deploy's state file with its one UJ_A line — stage A's reading, S:13776 — taken out, every other byte as it was; the file kept aside first ($KP5C106)"
aft106 "106-5c1" NOTYET $L1ON106
check "$(grep -c "; judged on this run's values alone — stage A's reading is not on record (UJ_A in $STATE106); run it again after " <<<"$AFT106")|$(noteline "$AFT106" UJ-CA "stage A's reading is not on record (UJ_A in $STATE106) — U1–U3 stand alone")|$(back106 p4-st5c)|$( [ "$(sget106 "$STATE106" UJ_A)" = "$UJ5C106" ] && echo back)" "1|yes|same|back" \
  "106-5c' UJ_A out of the record: UJ-ON's NOT YET says it judged on this run's values alone (S:8063), UJ-CA says stage A's reading is not on record (S:8033); the state file then put back byte for byte, its UJ_A line as before"
# 5d — the copy's time out of the record: a FIXTURE (A9), the state file's COPY_AT line taken out (QF_COPY_AT kept), put back after
dpre106 "106-5d" "$P106" on - "on:$LIM106" "-:nothing:no-record"
CA5D106="$(sget106 "$STATE106" COPY_AT)"; KP5D106="$(keep106 p4-st5d "$STATE106")"
check "$( [ -n "$CA5D106" ] && echo read)|$(dstdrop106 COPY_AT)|$(grep -c '^COPY_AT=' "$STATE106")|$(grep -c '^QF_COPY_AT=' "$STATE106")" "read|removed|0|1" \
  "106-5d0 a FIXTURE (A9): this deploy's state file with its one COPY_AT line (S:13869) taken out — QF_COPY_AT kept — every other byte as it was; the file kept aside first ($KP5D106)"
aft106 "106-5d1" FAILED $L1ON106
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -cxF "  FAIL  UJ-ON FAIL — the copy's time is not on record (COPY_AT in $STATE106), so nothing since the copy can be counted" <<<"$AFT106")|$(noteline "$AFT106" UJ-CA "the copy's time is not known — the closes, expiries and sends since the copy are not counted")|$(noteline "$AFT106" ME-CA1 "NOT ESTABLISHED — THE COPY'S TIME IS NOT KNOWN (unset)")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(back106 p4-st5d)|$( [ "$(sget106 "$STATE106" COPY_AT)" = "$CA5D106" ] && echo back)|$(copyok106)" \
      "1|1|yes|yes|0|same|back|near" \
  "106-5d' COPY_AT out of the record: UJ-ON's one FAIL, the copy's time is not on record (S:8080); UJ-CA's note (S:8034) and ME-CA1's (S:8190); no NOT YET; the state file then put back byte for byte, COPY_AT again within 60 s of QF_COPY_AT"
# 5e — this run's reading fails: the uj hook (A8), a FIXTURE armed just before the run and removed just after (it is not one-shot)
dpre106 "106-5e" "$P106" on - "on:$LIM106" "-:nothing:no-record"
F5E0_106="$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)"
check "$( [ -e "$SB/.ujfail106" ] && echo armed || echo unarmed)" "unarmed" "106-5e0 the uj hook's trigger is absent before it is armed"
: > "$SB/.ujfail106"
aft106 "106-5e1" FAILED $L1ON106
rm -f "$SB/.ujfail106"
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$(grep -c "^  FAIL  UJ-ON FAIL — this run's reading could not be made (unread(.*)): whether F3 works is not shown$" <<<"$AFT106")|$(noteline "$AFT106" UJ-CA "U1–U3 could not be read after the copy (unread(" "— NOT ESTABLISHED")|$(( $(n103 "$(grep -c '^uj$' "$SB/ujfail106.fired" 2>/dev/null)") - $(n103 "$F5E0_106") ))|$( [ -e "$SB/.ujfail106" ] && echo armed || echo removed)" \
      "1|1|yes|1|removed" \
  "106-5e' this run's reading fails (the uj hook refused QF_PHP's uj call once): UJ-ON FAIL — this run's reading could not be made (S:8062), its one FAIL, exit 1; UJ-CA NOT ESTABLISHED (S:8037); the trigger removed and gone (A9)"
# 5f — pass 1 (A3): the main loop's 200 places and expireStale's 200 all go to gone conversations, nothing expires: NOT YET
dpre106 "106-5f" "$P106" on - "on:$LIM106" "-:nothing:no-record"
SO5F106="$(dsto106 "$LIM106" $(( $(fnow104) + 601 )) 9000001 9000400)"   # the first close pass as this rehearsal forecasts it (p1_*), at the moment it starts
master106 "106-5f0"
CL5F106="$(dclose106)"
check "$( [ "$(n103 "$(kv106 "$SO5F106" p1_cn)")" -ge 200 ] && echo ge200):$(kv106 "$SO5F106" p1_ex)|$(kv106 "$CL5F106" control):$(kv106 "$CL5F106" expired)|$( [ "$(kv106 "$CL5F106" cancelled)" = "$(kv106 "$SO5F106" p1_cn)" ] && echo forecast)|$(ctl106)" \
      "ge200:0|held:0|forecast|after" \
  "106-5f1 pass 1 as this rehearsal forecast it (staleorder: $(kv106 "$SO5F106" p1_main), then $(kv106 "$SO5F106" p1_xs)): it cancelled $(kv106 "$CL5F106" cancelled) — 200 or more — and expired none (rh106q.py closer); followup_close ran after the copy and ended — the control holds"
aft106 "106-5f2" NOTYET $L1ON106
NY5F106="$(sed -nE 's/^  wait  UJ-ON NOT YET — the close passes. places went to gone conversations — ([0-9]+) closed cancelled by the closer since followup_close started at ([^,]+), none expired, ([0-9]+) stale follow-up\(s\) with a present conversation left at .*/\1 \2 \3/p' <<<"$AFT106")"
ST5F106="$(dsto106 "$LIM106" "$(kv106 "$CL5F106" fc_last)")"
check "${NY5F106:-none}|$( [ "$(n103 "${NY5F106%% *}")" -ge 200 ] && echo ge200)" "$(kv106 "$CL5F106" on_cancelled_t) $(kv106 "$CL5F106" t) $(kv106 "$ST5F106" stale_present)|ge200" \
  "106-5f' UJ-ON NOT YET — the close passes' places went to gone conversations (S:8094-8095): its cancels at or after the pass's start (200 or more, ex 0), that start, and the stale rows with a present conversation left then — each as this rehearsal reads them (closer, staleorder)"
# 5g — the limit-only note (S:8079) and check it's limit arm (S:16017): another limit now than stage A's — PASSED, and the owner's
# steps printed (RH106-15); then the limit set back
dpre106 "106-5g" "$P106" on - "on:$LIM106" "-:nothing:no-record"
O5G106="$(setcfg106 followup_max_age_hours 504)"
check "$(grep -cx '  followup_max_age_hours = 504' <<<"$O5G106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|504" \
  "106-5g0 followup_max_age_hours set to 504 through the installed set_config as the book's owner, under the clock (it prints \"  followup_max_age_hours = 504\"); this rehearsal reads 504"
aft106 "106-5g1" PASSED $L1ON106
check "$(grep -cxF "  note  UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is 504 h now and was $LIM106 h at stage A, and UJ-ON judges F3 only by the limit stage A read: set it back to $LIM106 h and, once a close pass has run after that (followup_close runs every 10 minutes), run --after-only again to have F3 judged — or, to keep the new limit, a rollback and then a --redeploy, whose stage A reads it. Until then F3 is unproven, and the steps that wait for it (6, 11, 12) would follow without that proof; a note, which holds back nothing" <<<"$AFT106")|$(has "$AFT106" "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A's $LIM106 h and a close pass has run after that (UJ-ON above) — run it only then: a run before that pass judges F3 by passes made under the other limit, and can read FAIL; while the limit differs it gives that note, no verdict, and the run reads PASSED without that proof")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(dnooffer106)" \
      "1|yes|0|none" \
  "106-5g1' the limit-only note (S:8079): stage A's gate held, the limit 504 h now against $LIM106 h then — a note, no verdict; check it's limit arm (S:16017); the run PASSED; as_back_on silent (no rollback on record)"
check "$(has "$AFT106" "  the owner's steps root docs/75 §F — $V106 serves and this --after-only PASSED, so the steps that")|$(grep -F -- "--key followup_daily_cap --value '<CAP>' 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 daily-cap)" <<<"$AFT106" | grep -c "^      docker exec -u $(owner104) -e DN_DATA_DIR=")|$(grep -cxF "      cd $REPO && bash scripts/sales-followup.sh on chase" <<<"$AFT106")|$(grep -cxF "      cd $REPO && bash scripts/sales-followup.sh --check chase" <<<"$AFT106")|$(grep -F -- "--key followup_run_limit --value '<LIMIT>' 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 run-limit)" <<<"$AFT106" | grep -c "^      docker exec -u $(owner104) -e DN_DATA_DIR=")" \
      "yes|1|1|1|1" \
  "106-5g2 a PASSED --after-only prints the owner's steps (S:16103-16143, RH106-15): followup_daily_cap's command with <CAP> (S:16109); step 6 — chase is off, so on chase (S:16134), then its check (S:16137); followup_run_limit's with <LIMIT> (S:16142) — each alone on its line"
O5G106="$(setcfg106 followup_max_age_hours "$LIM106")"
check "$(grep -cx "  followup_max_age_hours = $LIM106" <<<"$O5G106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$LIM106" \
  "106-5g3 the limit set back to $LIM106 through the installed set_config (it prints \"  followup_max_age_hours = $LIM106\")"
# 5h — the 'now' note (S:8073) with its wait clause, and check it's 'now' arm (S:16017): followup_enabled off now; then on again
dpre106 "106-5h" "$P106" on - "on:$LIM106" "-:nothing:no-record"
O5H106="$(setcfg106 followup_enabled 0)"
check "$(grep -cx '  followup_enabled = 0' <<<"$O5H106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|off" \
  "106-5h0 followup_enabled set to 0 through the installed set_config (it prints \"  followup_enabled = 0\"); read off as the jobs read it"
aft106 "106-5h1" PASSED $L1ON106
check "$(grep -cxF "$NOWEN106" <<<"$AFT106")|$(has "$AFT106" "$NOWARM106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(dnooffer106)" "1|yes|0|none" \
  "106-5h1' the 'now' note (S:8073): followup_enabled is off now — with its wait clause (run --after-only only after a close pass under the gate; a run before that can read FAIL); check it's 'now' arm (S:16017); the run PASSED; as_back_on silent"
O5H106="$(setcfg106 followup_enabled 1)"
check "$(grep -cx '  followup_enabled = 1' <<<"$O5H106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|on" \
  "106-5h2 followup_enabled set back to 1 through the installed set_config (it prints \"  followup_enabled = 1\")"
# 5i — pass 2: the gone rows spent, F3 expires the present stale rows — PASS, F3 works; as_back_on offers nothing: no rollback yet
dpre106 "106-5i" "$P106" on - "on:$LIM106" "-:nothing:no-record"
SO5I106="$(dsto106 "$LIM106" $(( $(fnow104) + 601 )))"
master106 "106-5i0"
aft106 "106-5i1" PASSED $L1ON106
dpasschk106 "106-5i2" "$(kv106 "$SO5I106" p1_ex)"
check "$( [ "$(n103 "$(kv106 "$SO5I106" p1_ex)")" -ge 1 ] && echo some)|$(dnooffer106)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)" "some|none|0" \
  "106-5i3 pass 2 expired what this rehearsal forecast ($(kv106 "$SO5I106" p1_xs), staleorder before the pass) — at least one; no rollback of $V106 is on record, so as_back_on offers nothing"

echo; echo "== 106-5D2. a copy of its own (F7): the limit note, a pass under the larger limit, the deterministic FAIL before a pass, then PASS; the turn-back-on offer first seen =="
dround106 D2 "-:nothing:no-record"
dstale106 "106-5D2f" 60 61
O5D2_106="$(setcfg106 followup_max_age_hours 504)"
check "$(grep -cx '  followup_max_age_hours = 504' <<<"$O5D2_106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|504" "106-5D2g the limit set to 504 through the installed set_config"
aft106 "106-5D2h" PASSED $L1ON106
check "$(noteline "$AFT106" UJ-ON "not judged on this run — stage A's gate held, but followup_max_age_hours is 504 h now and was $LIM106 h at stage A")|$(has "$AFT106" "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A's $LIM106 h")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "yes|yes|0" \
  "106-5D2h' the limit note on a fresh copy (S:8079) and check it's limit arm (S:16017), before any pass: PASSED"
doffer106 "106-5D2i"
master106 "106-5D2j"
CL5D2_106="$(dclose106)"; T5D2_106="$(kv106 "$CL5D2_106" fc_last)"
check "$(kv106 "$CL5D2_106" control):$(kv106 "$CL5D2_106" expired)|$(kv106 "$(dsto106 504 "$T5D2_106")" stale_present)|$(kv106 "$(dsto106 "$LIM106" "$T5D2_106")" stale_present)" "held:0|0|2" \
  "106-5D2k a close pass under 504 h expired nothing: the control holds, nothing closed expired since the copy; at its start nothing is stale with a present conversation under 504 h, the two planted are under $LIM106 h (staleorder)"
O5D2_106="$(setcfg106 followup_max_age_hours "$LIM106")"
check "$(grep -cx "  followup_max_age_hours = $LIM106" <<<"$O5D2_106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$LIM106" "106-5D2l the limit set back to $LIM106 — no close pass after it"
aft106 "106-5D2m" FAILED $L1ON106
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$( [ "$(pig106)" = "$PIGP106" ] && echo kept)" "1|kept" \
  "106-5D2m' the --after-only before any pass under $LIM106 h: one FAIL, exit 1 (above), deterministic — the piggyback record unchanged since the plant (A2)"
dfailchk106 "106-5D2m''" 2
master106 "106-5D2n"
aft106 "106-5D2o" PASSED $L1ON106
dpasschk106 "106-5D2o'" 2
check "$(dnooffer106)" "none" "106-5D2o'' the key on again (106-5D2i'): as_back_on offers nothing"

echo; echo "== 106-5D3. a copy of its own (RH106-8): followup_enabled off, a pass, the 'now' note; on again, the FAIL before a pass, then PASS =="
dround106 D3 "written:offer-if-off"
dstale106 "106-5D3f" 62 63
O5D3_106="$(setcfg106 followup_enabled 0)"
check "$(grep -cx '  followup_enabled = 0' <<<"$O5D3_106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|off" "106-5D3g followup_enabled set to 0 through the installed set_config"
master106 "106-5D3h"
CL5D3_106="$(dclose106)"
check "$(kv106 "$CL5D3_106" control):$(kv106 "$CL5D3_106" expired):$(kv106 "$CL5D3_106" cancelled)|$(ctl106)" "held:0:0|after" \
  "106-5D3i a close pass with followup_enabled off: followup_close started after the copy and ended (its last run moved — the control holds) and closed nothing: the job returns at once while followup_enabled is empty (bf29cbd cron/followup_close.php:33)"
aft106 "106-5D3j" PASSED $L1ON106
check "$(grep -cxF "$NOWEN106" <<<"$AFT106")|$(has "$AFT106" "$NOWARM106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "1|yes|0" \
  "106-5D3j' the 'now' note with its wait (S:8073) and check it's 'now' arm (S:16017), after a pass made while the gate did not hold: PASSED"
doffer106 "106-5D3k"
O5D3_106="$(setcfg106 followup_enabled 1)"
check "$(grep -cx '  followup_enabled = 1' <<<"$O5D3_106")|$(setrc106)|$(kv106 "$(dnow106)" en)" "1|0|on" "106-5D3l followup_enabled set back to 1 — no close pass after it"
aft106 "106-5D3m" FAILED $L1ON106
check "$(grep -c '^  FAIL  ' <<<"$AFT106")|$( [ "$(pig106)" = "$PIGP106" ] && echo kept)" "1|kept" \
  "106-5D3m' the --after-only after followup_enabled went back on, before any pass under it: one FAIL, exit 1 — the 'now' note's warning made true; the piggyback record unchanged since the plant (A2)"
dfailchk106 "106-5D3m''" 2
master106 "106-5D3n"
aft106 "106-5D3o" PASSED $L1ON106
dpasschk106 "106-5D3o'" 2
check "$(dnooffer106)" "none" "106-5D3o'' the key on again (106-5D3k'): as_back_on offers nothing"

echo; echo "== 106-5D4. a copy of its own: nothing stale for F3 to close — one pass, then PASS =="
dround106 D4 "written:offer-if-off"
SO5D4_106="$(dsto106 "$LIM106" "$(fnow104)")"
check "$(kv106 "$SO5D4_106" stale_present)|$( [ "$(n103 "$(kv106 "$SO5D4_106" open_gone)")" -lt 200 ] && echo lt200)" "0|lt200" \
  "106-5D4f nothing stale with a present conversation under $LIM106 h by this rehearsal's own reading, and fewer than 200 gone rows open ($(kv106 "$SO5D4_106" open_gone))"
master106 "106-5D4g"
aft106 "106-5D4h" PASSED $L1ON106
CL5D4_106="$(dclose106)"; ST5D4_106="$(dsto106 "$LIM106" "$(kv106 "$CL5D4_106" fc_last)")"
check "$(sed -nE 's/^  ok    UJ-ON PASS — nothing stale for F3 to close: followup_close started at ([^,]+), after the copy, and ended; .*/\1/p' <<<"$AFT106")|$(kv106 "$CL5D4_106" control):$(kv106 "$CL5D4_106" on_expired)|$(kv106 "$ST5D4_106" stale_present)" "$(kv106 "$CL5D4_106" t)|held:0|0" \
  "106-5D4h' UJ-ON PASS — nothing stale for F3 to close (S:8091): its start the close job's last run, nothing closed expired since the copy, expireStale's SELECT at that start selecting nothing with a present conversation — each by this rehearsal's own reading"
doffer106 "106-5D4i"

echo; echo "== 106-5D5. a copy of its own (S2, RH106-3): F3 taken out of the installed close job — UJ-ON's FAIL and R1's; put back, PASS =="
dround106 D5 "written:offer-if-off"
dstale106 "106-5D5f" 64 65
FC106="$PD/cron/followup_close.php"; FCG106="$(git -C "$REPO" show "$P106F:$P/cron/followup_close.php" | sha256sum | cut -c1-64)"
FCS5_106="$(stat -c '%u:%g:%a' "$FC106" 2>/dev/null)"; FCK5_106="$(keep106 p4-fc5 "$FC106")"
# A FIXTURE (A9), a weakened copy of the release: bf29cbd's cron/followup_close.php lines 84–93 — F3's comment and its block, the
# StaffJobsGate require and the expireStale() call — replaced by one line keeping $fcExpired 0, so line 95's echo still reads it
FCX5_106="$(python3 -I - "$FC106" "$FCG106" <<'PY' || { echo "rh106:f3cut" >> "$SB/anchor-missing"; echo miss; }
import sys, hashlib
p, want = sys.argv[1], sys.argv[2]
b = open(p, 'rb').read()
assert hashlib.sha256(b).hexdigest() == want, 'the installed file is not the pin\'s'
s = b.decode('utf-8')
block = '\n'.join([
    "// 5.18.106 (root docs/75 \u00a7C F3; G1, G4, G5): on Uganda, after the cases above (a reply closes 'replied' first), up to 200",
    "// open enquiry follow-ups whose customer \u2014 by the row and by the conversation \u2014 has been quiet longer than",
    "// followup_max_age_hours close 'expired', oldest first, with no model call; their waiting drafts leave every list with",
    "// them. Its own clock: this file sets no $now. Elsewhere nothing here runs.",
    "require_once $pluginRoot . '/lib/StaffJobsGate.php';",
    "$fcExpired = 0;",
    "if (StaffJobsGate::applies($config, $dataDir)) {",
    "    $fcStale = FollowUpService::staleBefore($config, time());",
    "    if ($fcStale !== null) $fcExpired = $svc->expireStale($fcStale, 200, FollowUpService::staleHours($config), 'closer');",
    "}",
]) + '\n'
assert s.count(block) == 1, s.count(block)
assert s[:s.index(block)].count('\n') == 83, s[:s.index(block)].count('\n')   # it begins on line 84
repl = "$fcExpired = 0;   // 5.18.106's rehearsal (D5): F3's block, lines 84-93, taken out of the installed copy - put back byte for byte after\n"
s2 = s.replace(block, repl, 1)
assert s.count('expireStale(') == 1 and s2.count('expireStale(') == 0 and s2.count('$fcExpired = 0;') == 1 and len(s2) == len(s) - len(block) + len(repl)
assert s2.count("if ($closed || $fcExpired) echo") == 1
with open(p, 'r+b') as f:
    f.write(s2.encode('utf-8'))
    f.truncate()
print('cut')
PY
)"
check "$FCX5_106|$(sha256sum < "$FC106" | cut -c1-64 | grep -cx "$FCG106")|$(grep -c 'expireStale(' "$FC106")|$("$PHPBIN" -l "$FC106" 2>&1 | grep -c '^No syntax errors detected')|$(stat -c '%u:%g:%a' "$FC106")" "cut|0|0|1|$FCS5_106" \
  "106-5D5g a weakened copy of the RELEASE, installed (S2): F3's block — bf29cbd's cron/followup_close.php lines 84–93 — taken out of the installed close job by an asserted edit (the pin's bytes first; the block once, at line 84; \$fcExpired kept 0), still PHP (php -l), its owner and mode kept; the original kept aside ($FCK5_106)"
master106 "106-5D5h"
CL5D5_106="$(dclose106)"
check "$(kv106 "$CL5D5_106" control):$(kv106 "$CL5D5_106" expired)|$(kv106 "$(dsto106 "$LIM106" "$(kv106 "$CL5D5_106" fc_last)")" stale_present)" "held:0|2" \
  "106-5D5i a close pass with F3 taken out: it ran after the copy and ended, and expired nothing — the two planted still stale with a present conversation at its start (staleorder)"
aft106 "106-5D5j" FAILED $L1ON106
check "$(grep -cxF '  FAIL  R1 installed files that differ: cron/followup_close.php' <<<"$AFT106")|$(grep '^  FAIL  ' <<<"$AFT106" | grep -v '^  FAIL  UJ-ON FAIL — F3 did not expire: ' | grep -cvF 'cron/followup_close.php')|$( [ "$(grep -c '^  FAIL  ' <<<"$AFT106")" -ge 2 ] && echo some)|$( [ "$(pig106)" = "$PIGP106" ] && echo kept)" "1|0|some|kept" \
  "106-5D5j' R1 FAILs naming cron/followup_close.php (S:14400); every other FAIL is UJ-ON's or names that one file — nothing else of the release is touched; exit 1; the piggyback record unchanged since the plant (A2)"
dfailchk106 "106-5D5j''" 2
check "$(back106 p4-fc5)|$(sha256sum < "$FC106" | cut -c1-64 | grep -cx "$FCG106")|$(stat -c '%u:%g:%a' "$FC106")|$(grep -c 'expireStale(' "$FC106")" "same|1|$FCS5_106|1" \
  "106-5D5k the close job put back (A9): byte-equal to bf29cbd's (sha256 against Git), its owner and mode as they were, F3's expireStale() call there again"
master106 "106-5D5l"
aft106 "106-5D5m" PASSED $L1ON106
dpasschk106 "106-5D5m'" 2
check "$(okline "$AFT106" R1 all "files $V106 changes are installed exactly as $P106 has them")" "yes" "106-5D5m'' R1 ok again (S:14400): every file $V106 changes installed as $P106 has it"
doffer106 "106-5D5n"

echo; echo "== 106-5D6. a copy of its own (RH106-7): the --redeploy taken on a limit of 0 — U4's capitals note before DEPLOY; the 'now' note with stage A's; set back, a pass, UJ-ON will not judge; weakened copy UJG =="
dround106 D6 "written:offer-if-off" 0 misread
U4N6_106="  note  U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: followup_max_age_hours was not a positive number at stage A. STAGE A'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY, SO F3 WOULD STAY UNPROVEN AND THE STEPS THAT WAIT FOR IT (6, 11, 12) WOULD FOLLOW WITHOUT THAT PROOF; AFTERWARDS A JUDGED UJ-ON NEEDS A ROLLBACK AND THEN A --redeploy. TO HAVE IT JUDGED, DO NOT DEPLOY ON THIS READING: PUT IT RIGHT, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN"
LU4_106="$(grep -nxF "$U4N6_106" <<<"$DRD106_OUT" | head -1 | cut -d: -f1)"; LTD_106="$(grep -n '^  Type DEPLOY to deploy ' <<<"$DRD106_OUT" | head -1 | cut -d: -f1)"
check "$(grep -cxF "$U4N6_106" <<<"$DRD106_OUT")|$( [ "$(n103 "$LU4_106")" -gt 0 ] && [ "$(n103 "$LU4_106")" -lt "$(n103 "$LTD_106")" ] && echo before)|$(noteline "$DRD106_OUT" U4 'the settings as the jobs read them' 'followup_max_age_hours not a positive number — F2 and F3 inactive: nothing is expired on a misread')|$(kv106 "$(sget106 "$STATE106" UJ_A)" hours)|$(has "$DRD106_OUT" "$WILLNOT106")" \
      "1|before|yes|misread|yes" \
  "106-5D6f U4's capitals note with its DEPLOY clause (S:7983) printed before \"  Type DEPLOY to deploy\" (S:13838), U4 reading the limit not a positive number (S:7981); DEPLOY typed all the same — stage A's reading kept (hours misread); the deploy's check it says UJ-ON will not judge F3 for it (S:16019)"
aft106 "106-5D6g" PASSED $L1ON106
check "$(grep -cxF "  note  UJ-ON not judged — its gate did not hold: followup_max_age_hours is not a positive number now; followup_max_age_hours was not a positive number at stage A. F3 then has nothing to do, or another limit to do it by. Stage A's gate did not hold either, so UJ-ON will not judge F3 for this deploy whatever is put right now (a judged UJ-ON needs a rollback and then a --redeploy); a note, which holds back nothing" <<<"$AFT106")|$(has "$AFT106" "$WILLNOT106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")" "1|yes|0" \
  "106-5D6g' before the limit is set back: the 'now' note naming both, with \"Stage A's gate did not hold either\" (S:8073); check it's will-not-judge arm (S:16019); PASSED"
doffer106 "106-5D6h"
O5D6_106="$(setcfg106 followup_max_age_hours "$LIM106")"
check "$(grep -cx "  followup_max_age_hours = $LIM106" <<<"$O5D6_106")|$(setrc106)|$(kv106 "$(dnow106)" hours)" "1|0|$LIM106" "106-5D6i the limit set back to $LIM106 through the installed set_config"
master106 "106-5D6j"
aft106 "106-5D6k" PASSED $L1ON106
check "$(grep -cxF "  note  UJ-ON not judged for this deploy — its gate did not hold at stage A: followup_max_age_hours was not a positive number at stage A. F3 may be running now, but this deploy's stage A reading is fixed in $STATE106 (a deploy run while $V106 serves is an --after-only of this one), so UJ-ON cannot judge F3 for this deploy: F3 stays unproven, and the steps that wait for it (6, 11, 12) would follow without that proof. A judged UJ-ON needs a rollback and then a --redeploy, its stage A taken with the gate holding; a note, which holds back nothing" <<<"$AFT106")|$(has "$AFT106" "$WILLNOT106")|$(grep -c '^  wait  UJ-ON ' <<<"$AFT106")|$(dnooffer106)" "1|yes|0|none" \
  "106-5D6k' the limit set back and a close pass run: UJ-ON not judged for this deploy — its gate did not hold at stage A (S:8076); check it's will-not-judge arm (S:16019); PASSED; the key on again, as_back_on offers nothing"
# Weakened copy UJG — UJ-ON's stage-A-only branch merged into the 'now' branch (S:8073's condition widened to stage A's gate), on
# this same state: the stage-A note above must be gone, the 'now' note in its place — caught
anc106 UJG '  if [ -n "$g" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:' \
           '  if [ -n "$g$ga" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:'
AMU6_106="$(grep -c '^weak106:UJG' "$SB/anchor-missing" 2>/dev/null)"
MUJG106="$(weak106 UJG UJG)"; fresh106; OUJG106="$(R106_MUTANT_OUT=1 run106c --script "$MUJG106" --after-only)"; rm -f "$MUJG106"
check "$(grep -cF -- "$(cat "$AN106/UJG.a")" "$DEPLOY106")|$(( $(n103 "$(grep -c '^weak106:UJG' "$SB/anchor-missing" 2>/dev/null)") - $(n103 "$AMU6_106") ))|$(has "$OUJG106" "UJ-ON not judged for this deploy — its gate did not hold at stage A")|$(noteline "$OUJG106" UJ-ON "not judged — its gate did not hold: followup_max_age_hours was not a positive number at stage A." "Stage A's gate did not hold either")|$( [ -e "$MUJG106" ] && echo left || echo removed)" \
      "1|0|no|yes|removed" \
  "106-5D6l weakened copy UJG — UJ-ON's stage-A-only branch merged into the 'now' branch (its condition \$g made \$g\$ga, the anchor once in the script) on this same state: the stage-A note 106-5D6k' read is gone and the 'now' note stands in its place — caught; the copy removed"
# What 106-6 begins from
dpre106 "106-5z0" "$P106" on - "on:$LIM106" "written:offer-if-off"
dwal106; UZ5_106="$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")"; CLK5Z106="$(fnow104)"
check "$(kv106 "$UZ5_106" f5_multi)|$(kv106 "$(sget106 "$STATE106" UJ_A)" hours)|$(sget106 "$RBX106_REC" AS_RB1)|$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(lock106)|$( [ "$(sha256sum < "$FC106" | cut -c1-64)" = "$FCG106" ] && echo pin)|$(n103 "$(grep -c '\] \[master\] ' "$PLOG106" 2>/dev/null)")|$( [ -e "$REPO/scripts/.UJG.sh" ] && echo left || echo none)" \
      "1/2|misread|written|none|absent|pin|0|none" \
  "106-5z the state 106-6 begins from: $P106 serving (D6's --redeploy, its stage A read on a limit of 0), followup_auto_send on, no claim switch, followup_enabled on, the limit $LIM106 h; J3's pair still waiting (F5 1/2, nothing sent); the latest rollback record D6's typed one, AS_RB1=written ($(basename "$RBX106_REC")); no uj trigger, no index lock, the close job the pin's, plugin.log without a [master] line, no weakened copy left; the clock at $(kday104 "$CLK5Z106") $(TZ=Africa/Kampala date -d "@$CLK5Z106" +%H:%M) Kampala"
