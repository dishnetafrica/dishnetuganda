
# ══════════════════════════════════════════════════════════════════════════════════════
# 106-10 — what the rehearsal left behind (design-v2 §C 106-10; A9)
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-10. what the rehearsal left behind, over 104's, 105's and 106's records alike: the sweeps, the fixtures' leftovers, the chain's failure files =="
# 5.18.105's 105-10 is cut with its rehearsal (106-cut), so what it judged is judged here again, over 105's records and 106's
# alike, by this file's own lines: none of R5's past the cut is read here (106-infra d read sweep105's two lines; 104-10's
# three one-liners are read from 5.18.104's rehearsal, as R5's 105-10c' read them). Evolution and uCRM asked only as each may
# be; no planted name (NM106F: the chain's names and the RH106SECRET- marker), no fixture number (FORMS106: every number of the
# chain's, 105's and 106's ranges in its four written forms and its last nine digits) and no LK key in any terminal, log,
# sheet, state file, master pass or request record of 106's runs, 105's (106-pre's among them) or 104's; 106's own numbers
# and marker nowhere in the plugin's data folders or the records but the book; every fixture's leftover gone (A9);
# anchor-missing, wait104.fail and bad-runs against their baselines — 105's span (R5's 105-10i, cut with 105-10) and 106's
# own. Not swept, by design: $R106/setcfg.txt (set_config's own output, 106-infra: a planted setting's value may reach it),
# the case folders' backup-* (raw copies of the book) and the stand-in docker's exec records. Every file this section plants is
# a CONTROL of a reading (zz106ctl, zz-control, key-control, .zz106ctl.sh), said so where it is planted, removed at once, and
# asserted gone in 106-10f. No number of 106's range is planted or newly used here.
# The case's preconditions — the state 106-7i leaves by design-v2 (§C 106-7i, A10's known limit): 5.18.106 serving after the
# last --redeploy; followup_auto_send off (V1 found it off and wrote nothing: AS_RB1=off); no claim switch; followup_enabled
# on, the limit 336 h; the latest rollback record V1's, so as_back_on would offer nothing (rblast: off, nothing:not-written).
dpre106 "106-10a0" "$P106" off - "on:${LIM106:-unset}" "off:nothing:not-written"
# 106-10a — Evolution (R5's 105-10a): no run of 5.18.106's script or 5.18.105's called it, every run summed — 106's own and
# 105's, 106-pre's redeploy and --after-only among them; the cron stand-in ran its worker during none of them (a run is
# tagged cron only when cron104.runs grew during it); every request since 105 began on the stand-in's own base path; outside
# every run, since 106-pre (EVO0_106) no fewer than the sends the stand-in refused for J1's numbers (A6: 15 in J1's five
# passes, more if 106-6's sender pass met them), and in 105's sections and 106-infra no fewer than 105's drives' own counted
EVP106="${EVO_URL:-}"; EVP106="/${EVP106#http://*/}"
EVQ106="${EVF106:-}"; EVQ106="/${EVQ106#http://*/}"   # Part 5's file-only base path (106-6b2, 6b7, 7d3, 7f4: set_evolution's status asks it) (review r2 F4)
EVA106=$(( $(evo_calls) - EVO0_106 )); EVA5_106=$(( EVO0_106 - EVO0_105 )); RFJ106=$(( $(refused106) - $(n103 "${RF4_106:-}") ))
EVN106="$(cat "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | awk '{s += $1} $2 == "cron" {c++} END {printf "%d|%d\n", s, c}')"
check "$EVN106|$(tail -n +"$(( EVO0_105 + 1 ))" "$SB/evo/requests.log" 2>/dev/null | grep -cvE "^(GET|POST) ($EVP106|$EVQ106)/")|$( [ -n "${RF4_106:-}" ] && [ "$RFJ106" -ge 15 ] && [ "$EVA106" -ge "$RFJ106" ] && echo ge)|$( [ -n "${EVODRV105:-}" ] && [ "$EVA5_106" -ge "$(n103 "${EVODRV105:-}")" ] && echo ge)" "0|0|0|ge|ge" \
  "106-10a Evolution: no run of $V106's script or $V105's called it ($(ls "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | wc -l | tr -d ' ') runs read, every one summed — 106-pre's among $V105's); the cron stand-in ran its worker during none of them; the stand-in asked only on its own base path ($EVP106), or on the file-only path this rehearsal planted ($EVQ106), since $V105's sections began; every request outside every run — $EVA106 since 106-pre, no fewer than the $RFJ106 sends it refused for J1's numbers (15 or more), and $EVA5_106 in $V105's sections and 106-infra, no fewer than $V105's drives' ${EVODRV105:-?}"
# 106-10b — uCRM (R5's 105-10b): every run's request record names GETs only (uo106: u99other prints the requests that are not
# a GET, or -), no token record finds a copy left at the next call (tl106: tok99), over 106's runs, 105's and 104's alike;
# nothing of any of the three left in /tmp. Each reading's CONTROL a planted record in this section's own folder, removed
SCN106="$R106/scan106"; mkdir -p "$SCN106"; chmod 700 "$SCN106"
UO106="$(uo106 "$R106"/ucrm/run-*.req "$R105"/ucrm/run-*.req "$R104"/ucrm/run-*.req)"; TK106="$(tl106 "$R106"/tok/run-*.tok "$R105"/tok/run-*.tok "$R104"/tok/run-*.tok)"
printf 'GET /organizations app-key\nPOST /clients app-key\n' > "$SCN106/zz-control.req"; printf '0 x\n1 remote\n1 y\n' > "$SCN106/zz-control.tok"
check "$UO106|$TK106|$(ls -d /tmp/dnb-$V106-* /tmp/dnb-$V105-* /tmp/dnb-$V104-* /tmp/dnb-report-* 2>/dev/null | wc -l | tr -d ' ')|$(for d106 in "$R106" "$R105" "$R104"; do ls "$d106"/ucrm/run-*.req 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "r" : "-"}'; ls "$d106"/tok/run-*.tok 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "t" : "-"}'; done)|$(uo106 "$SCN106/zz-control.req")$(tl106 "$SCN106/zz-control.tok")" "0|0|0|rtrtrt|11" \
  "106-10b uCRM asked GETs only in every run of $V106, $V105 and $V104 (106's sections', 105's — 106-pre's among them — and 104's: R5's 105-10b, cut, judged again with 106's); no token copy left at the next call by any of them; nothing of any of them left in /tmp; controls: a planted POST and a planted token copy left, each found"
rm -f "$SCN106/zz-control.req" "$SCN106/zz-control.tok"
# 106-10c — 106's own records (R5's 105-10c, over sweep106, 106-infra's list): every terminal, mutant terminal, sheet, log,
# stream, state and rollback record, row digest and case file (backup-* aside) of 106's runs; and besides them what else
# 106's runs wrote (sweepx106): its master passes' output (master106, sender106: master-106m-*, master-106s-*), the tool's
# output while a deploy waited (csb1run106) and its uCRM request records. Scanned with both pattern files; the list read (run
# terminals and master passes among it); a CONTROL file holding one of each found by both scans, then planted through the
# list as a run terminal and as a master pass, found again, and removed
sweepx106() { ls -1 "$R104"/logs/master-106m-*.out "$R104"/logs/master-106s-*.out "$R106"/logs/act-*.txt "$R106"/ucrm/run-*.req 2>/dev/null; }   # what else 106's runs wrote
sweepall106() { { sweep106; sweepx106; } | sort -u; }
printf 'RH106SECRET-control %s\n' "$(grep -m1 -F '7001060' "$FORMS106")" > "$SCN106/control.txt"; chmod 600 "$SCN106/control.txt"
NHS106="$(sweepall106 | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')"; FHS106="$(sweepall106 | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
SWN106="$(sweepall106 | grep -c .)"; SWR106="$(ls "$R106"/run-*.txt 2>/dev/null | grep -c .)"; SWM106="$(sweepx106 | grep -c '/master-106[ms]-')"
cp "$SCN106/control.txt" "$R106/run-zz106ctl.txt"; cp "$SCN106/control.txt" "$R104/logs/master-106m-zz106ctl.out"
SWC106="$(for p106 in run-zz106ctl.txt master-106m-zz106ctl.out; do printf '%s%s ' "$(sweepall106 | grep -F "/$p106" | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')" "$(sweepall106 | grep -F "/$p106" | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R106/run-zz106ctl.txt" "$R104/logs/master-106m-zz106ctl.out"
check "$NHS106|$FHS106|$(grep -lF -f "$NM106F" "$SCN106/control.txt" | wc -l | tr -d ' ')$(grep -lF -f "$FORMS106" "$SCN106/control.txt" | wc -l | tr -d ' ')|$( [ "$SWR106" -gt 0 ] && [ "$SWM106" -gt 0 ] && echo listed)|$SWC106" "0|0|11|listed|11 11" \
  "106-10c no planted name (the chain's names, the RH106SECRET- marker) and no fixture number (the chain's, $V105's and $V106's, each in its four written forms and its last nine digits) in any of $V106's terminals, logs, sheets, streams, state and rollback records, row digests, case files (their backups aside), master passes, the tool's output beside a held deploy or uCRM request records ($SWN106 files: $SWR106 run terminals, $SWM106 master passes); the control file holding one of each found by both scans, and again through the list as a run terminal and as a master pass, then removed"
# 106-10c' — 5.18.105's records by 105-10's own list (sweep105, read in 106-infra d): 105's sections' terminals, logs, sheets,
# streams and state files, and 106-pre's (5.18.105 deployed again, its --after-only); the CONTROL planted as a run terminal of
# 105's, found by both scans through that list, then removed
NH5_106="$(sweep105 | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')"; FH5_106="$(sweep105 | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
SWN5_106="$(sweep105 | grep -c .)"
cp "$SCN106/control.txt" "$R105/run-zz106ctl.txt"
SWC5_106="$(sweep105 | grep -F '/run-zz106ctl.txt' | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')$(sweep105 | grep -F '/run-zz106ctl.txt' | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
rm -f "$R105/run-zz106ctl.txt"
check "$NH5_106|$FH5_106|$( [ "$SWN5_106" -gt 0 ] && echo listed)|$SWC5_106" "0|0|listed|11" \
  "106-10c' …nor in any of $V105's records by 105-10's own list (sweep105): its terminals, logs, sheets, streams and state files — 105's sections' and 106-pre's ($SWN5_106 files); the control file planted as one of its run terminals found by both scans through that list, then removed"
# 106-10c'' — 5.18.104's records by 104-10's own lists, which 106's runs write too (sf104c's sf.txt, the wrappers' logs of the
# commands run as printed, 104's and the quarantine's terminals): the three one-line definitions read from 5.18.104's
# rehearsal — each the start of exactly one line, after 104-10's heading, ending its body — and sourced unchanged (as R5's
# 105-10c' read them; a miss recorded in anchor-missing, and the lists then read nothing, which fails here); each list read;
# a CONTROL file planted where each looks, found by both scans through it, then removed
L104F_106="$(mktemp)"
python3 -I - "$H104_HARNESS105" "$L104F_106" <<'PY' || echo "rh106:lists104" >> "$SB/anchor-missing"
import sys
src, dst = sys.argv[1], sys.argv[2]
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == 'echo; echo "== 104-10. what the rehearsal left behind, and what it never reached =="']
assert len(at) == 1, len(at)
out = []
for name in ('sweep_files104', 'sheets104', 'reqs104'):
    k = [i for i, l in enumerate(lines) if l.startswith(name + '() { ')]
    assert len(k) == 1 and k[0] > at[0], (name, k)
    l = lines[k[0]]
    assert l.endswith('; }') and l.count('() {') == 1 and '$R104' in l, name
    out.append(l)
open(dst, 'w', encoding='utf-8').write('\n'.join(out) + '\n')
PY
# shellcheck disable=SC1090
. "$L104F_106"; rm -f "$L104F_106"
sweep104_106() { { sweep_files104; sheets104; ls -1 "$OUT104"/cash-*.txt 2>/dev/null; reqs104; } | sort -u; }
NH4_106="$(sweep104_106 | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')"; FH4_106="$(sweep104_106 | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"
NL4_106="$(for l106 in sweep_files104 sheets104 reqs104; do "$l106" 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "y" : "n"}'; done)"
mkdir -p "$R104/sheets" "$R104/ucrm"
for f106 in "$R104/run-zz106ctl.txt" "$R104/sheets/cash-zz106ctl.txt" "$R104/ucrm/run-zz106ctl.req"; do cp "$SCN106/control.txt" "$f106"; done
CL4_106="$(for l106 in sweep_files104 sheets104 reqs104; do printf '%s%s ' "$("$l106" 2>/dev/null | grep -F 'zz106ctl' | xargs -r grep -lF -f "$NM106F" 2>/dev/null | wc -l | tr -d ' ')" "$("$l106" 2>/dev/null | grep -F 'zz106ctl' | xargs -r grep -lF -f "$FORMS106" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R104/run-zz106ctl.txt" "$R104/sheets/cash-zz106ctl.txt" "$R104/ucrm/run-zz106ctl.req" "$SCN106/control.txt"
check "$NH4_106|$FH4_106|$NL4_106|$CL4_106" "0|0|yyy|11 11 11" \
  "106-10c'' …nor in any of $V104's records, which 106's runs write too: its runs' terminals and logs, sf.txt (every tool run of 106's), its state, rollback-state and row files, the wrappers' logs (every command run as printed), its sheets and its request records ($(sweep104_106 | grep -c .) files, by 104-10's own lists, each list read); the control file planted where each list looks found by both scans through it, then removed"
# 106-10d — the LK key files 5.18.106 wrote (R5's 105-10d): its run folder's and those tidy106 moved into the case folders,
# each mode 0600 and read; no key's value in any file the three sweeps above read; the CONTROL a copy of the keys, found
KL106="$( { [ -e "$LKF106" ] && echo "$LKF106"; find "$R106/case" -type f -name "lk-key-$V106" 2>/dev/null; } | sort -u)"
: > "$SCN106/key.pat"; chmod 600 "$SCN106/key.pat"; SK106=""
for f106 in $KL106; do SK106="$SK106 $(q105 secret "$f106" "$SCN106/key1.pat" | tr ' ' '/')"; [ -f "$SCN106/key1.pat" ] && cat "$SCN106/key1.pat" >> "$SCN106/key.pat"; rm -f "$SCN106/key1.pat"; done
SK106="${SK106# }"
KH106="$( { sweepall106; sweep105; sweep104_106; } 2>/dev/null | sort -u | xargs -r grep -lF -f "$SCN106/key.pat" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN106/key.pat" "$SCN106/key-control.txt"
check "$( [ "$(grep -c . <<<"$KL106")" -ge 1 ] && echo some)|$(tr ' ' '\n' <<<"$SK106" | grep -vcx 'mode=600/key=read')|$KH106|$(grep -lF -f "$SCN106/key.pat" "$SCN106/key-control.txt" 2>/dev/null | wc -l | tr -d ' ')" "some|0|0|1" \
  "106-10d every LK key file $V106 wrote — its run folder's and the case folders' ($(grep -c . <<<"$KL106") file(s), $(sort -u "$SCN106/key.pat" | grep -c .) key value(s)) — mode 0600 and read; no key's value in any terminal, log, sheet, state file, master pass or request record of 106's, 105's or 104's; the control (a copy of the keys) found"
rm -f "$SCN106/key.pat" "$SCN106/key-control.txt"
# 106-10e — 106's own numbers and marker in the plugin's data folders and the records: the reading of 106-infra e (the range
# in any written form, not inside a longer run of digits, or its spaced national form), which found none there before
# 106-pre, and the RH106SECRET- marker — in no file but the book (plugin.sqlite3 and its -wal and -shm), where J1–J3 and the
# re-plants put both: a fixture left in a settings file (A9), or a log line carrying either, is found here. The control: the
# book, its write-ahead log folded in, holds each
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
ND1_106="$( { grep -rlaE --exclude='plugin.sqlite3*' "$RX106" "$DATA" "$PD/data" "$RECS"; grep -rlaF --exclude='plugin.sqlite3*' 'RH106SECRET-' "$DATA" "$PD/data" "$RECS"; } 2>/dev/null | sort -u)"
check "$(grep -c . <<<"$ND1_106")|$( [ "$(n103 "$(grep -caE "$RX106" "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo numbers)|$( [ "$(n103 "$(grep -caF 'RH106SECRET-' "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo marker)" "0|numbers|marker" \
  "106-10e $V106's own numbers ($PR106<NN>, in any written form: 106-infra e's reading) and its RH106SECRET- marker in no file of the plugin's data folders or the records — the book aside ($(grep -c . <<<"$ND1_106") file(s) found$( [ -n "$ND1_106" ] && printf ': %s' "$(xargs -r -n1 basename <<<"$ND1_106" | paste -sd, -)")); the control: the book holds both, where J1–J3 and the re-plants put them"
# 106-10f — the fixtures' leftovers (A9), each asserted gone: the uj hook's trigger (A8); the clone's index lock and the two V1
# triggers (A7); the stand-in's refusal list (A6, kept for 106-6's sender pass: p3-open 9) — removed here, the stand-in then
# answering a send for J1's first number with a key.id and refusing nothing more; the weakened copies (every $REPO/scripts/
# .NAME.sh newer than this release's script in the clone; the CONTROL one planted, counted and removed); the keep folders
# (keep106's and the settings-file fixtures': no fixture's kept copy left in one — fx_file_back takes its own — then removed);
# no [master] line left in plugin.log (106-5b's plants cut back); this section's own controls gone; neither RH106_TOUCH nor
# RH106_RANGE in the rehearsal's own environment (each given to its child alone: A1's shim stays 5.18.106's runs' own, the
# range seed106's)
KD106="$R106/kept"
KN106="$(find "$KD106" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | grep -c .)"; KP106="$(find "$KD106" -mindepth 2 -maxdepth 2 -type f -name path 2>/dev/null | grep -c .)"
KF106="$(for d106 in "$KD106"/*/; do [ -d "$d106" ] && [ ! -f "$d106/path" ] && find "$d106" -type f; done 2>/dev/null | grep -c .)"
rm -rf "${KD106:?}"
WC0_106="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)"
cp "$DEPLOY106" "$REPO/scripts/.zz106ctl.sh"; WC1_106="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)"; rm -f "$REPO/scripts/.zz106ctl.sh"
RLB106="$(if [ -e "$SB/evo/rh106-refuse.list" ]; then grep -c . "$SB/evo/rh106-refuse.list"; else echo absent; fi)"
RLA106="$(refuse106)"; RFB106="$(refused106)"; RQ106="$(evopost106 sendText "${J1A106:-}")"
check "$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(lock106)|$(ls -A "$SB/web" 2>/dev/null | grep -c '^\.redirect106')|$RLA106:$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo unlisted):$RQ106:$(( $(refused106) - RFB106 ))|$WC0_106:$WC1_106:$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)|$( [ "$KN106" -ge 1 ] && echo kept):$KF106:$( [ -e "$KD106" ] && echo left || echo gone)|$(n103 "$(grep -c '\] \[master\] ' "${PLOG106:-$PD/data/plugin.log}" 2>/dev/null)")|$(find "$R104" "$R105" "$R106" "$REPO/scripts" -name '*zz106ctl*' 2>/dev/null | grep -c .):$(ls -A "$SCN106" 2>/dev/null | grep -c .)|$(printenv RH106_TOUCH >/dev/null && echo touch)$(printenv RH106_RANGE >/dev/null && echo range)" \
      "none|absent|0|0:unlisted:id:0|0:1:0|kept:0:gone|0|0:0|" \
  "106-10f the fixtures' leftovers gone (A9): no uj trigger; no index lock in the clone and neither V1 trigger in the stand-in's folder; the refusal list (it listed $RLB106) removed — a send for J1's first number answered with a key.id, nothing more refused; no weakened copy of the script left in the clone (the control: one planted, counted, removed); the keep folders ($KN106, $KP106 of them keep106's) holding no settings-file fixture's kept copy, then removed; plugin.log without a [master] line; none of this section's controls left; the rehearsal's own environment carrying neither RH106_TOUCH nor RH106_RANGE"
# 106-10g — 5.18.105's sections' failure files (R5's 105-10i, cut with 105-10): anchor-missing and wait104.fail from 105's
# baselines (AM0_105, WF0_105, R5's) to 106's (AM0_106, WF0_106, 106-infra's), and no run of 105's runner — 105's sections'
# and 106-pre's — given an unknown option
AMS5_106=$(( AM0_106 - $(n103 "${AM0_105:-}") )); WFS5_106=$(( WF0_106 - $(n103 "${WF0_105:-}") ))
check "$( [ -n "${AM0_105:-}" ] && [ -n "${WF0_105:-}" ] && echo set || echo unset)|$AMS5_106|$(n103 "$(grep -c '^run105-' "$SB/bad-runs" 2>/dev/null)")|$WFS5_106|$( [ "$AMS5_106" -gt 0 ] && tail -n +$(( $(n103 "${AM0_105:-}") + 1 )) "$SB/anchor-missing" 2>/dev/null | head -n "$AMS5_106" | paste -sd, -)" "set|0|0|0|" \
  "106-10g $V105's sections (R5's 105-10i, cut with 105-10, judged here): every asserted edit and anchor of theirs found, no run of $V105's runner given an unknown option (106-pre's among them), no bounded wait timed out"
# 106-10h — 106's own: anchor-missing, wait104.fail and bad-runs against 106-infra's baselines; and the control of the controls
# over every anchor 106's weakened copies set (ancc106: each the script's own text, once, its replacement different)
AM1_106="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM1_106="${AM1_106:-0}"; WF1_106="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF1_106="${WF1_106:-0}"
BR1_106="$(grep -c '^run106-' "$SB/bad-runs" 2>/dev/null)"; AC106="$(ancc106)"
check "$(( AM1_106 - AM0_106 ))|$(( $(n103 "$BR1_106") - BR0_106 ))|$(( WF1_106 - WF0_106 ))|$(tail -n +$(( AM0_106 + 1 )) "$SB/anchor-missing" 2>/dev/null | paste -sd, -)|$(cut -d'|' -f1,3 <<<"$AC106")" "0|0|0||0|-" \
  "106-10h $V106's own sections: every asserted edit, anchor and plant of theirs found, no run of 106's runners given an unknown option, no bounded wait timed out; every anchor its weakened copies set ($(cut -d'|' -f2 <<<"$AC106")) still the script's own text, found in it once, its replacement different"
# 106-10i — the end state (R5's 105-10j): what 106-7 left serving, every file 5.18.106 changes from 5.18.105 as that commit has
# it (installed106: a file the commit lacks is not asked); this checkout's status exactly as the rehearsal found it
LV106="$(live)"; LVF106="$(git -C "$REPO" rev-parse -q --verify "$LV106^{commit}" 2>/dev/null)"
check "$(case "$LV106" in "$P106"|"$B106") echo known ;; *) echo "live:$LV106" ;; esac)|$( [ -n "$LVF106" ] && installed106 "$LVF106" || echo unread)|$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)" "known|0|$ST0_106" \
  "106-10i the end state: $LV106 ($(inst_ver)) serving after 106-7, every file $V106 changes from $BV106 as that commit has it; this checkout's status exactly as the rehearsal found it"

echo
echo "RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE106 before $V106's own sections (the earlier rehearsals' chain)"
[ "$FAILN" = 0 ]
