# ════════════════════════════════════════════════════════════════════════════
# 107-10 — what the rehearsal left behind, over 104's, 105's, 106's and 107's records alike (v2 §B 107-10, with v3's additions:
# the boots' output files, the stand-in log's fatal check, the leftover reading over 106's and 107's triggers, the ledger071
# undo). 5.18.106's 106-10 is cut with its rehearsal (107-cut), so what it judged is judged here again, over 106's records and
# 107's alike, by this file's own lines. Every file this section plants is a CONTROL of a reading (zz107ctl, zz-control,
# key-control, .zz107ctl.sh), said so where it is planted, removed at once and asserted gone in 107-10f. The control file holds
# one of 107's own numbers (from FORMS107, never newly made), in this section's own folder only, and is removed with it.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-10. what the rehearsal left behind, over 104's, 105's, 106's and 107's records alike: the sweeps, the fixtures' leftovers, the chain's failure files =="
# 107-10a — Evolution (106-10a's reading, carried): no run of 5.18.107's, 5.18.106's or 5.18.105's script called it, every run
# summed; the cron stand-in ran its worker during none of them; every request since 105 began on the stand-in's own base paths;
# outside every run, since 106-pre, no fewer calls than the sends the stand-in refused for J1's numbers (106-6), and since 105
# began no fewer than 105's drives counted
EVP107="${EVO_URL:-}"; EVP107="/${EVP107#http://*/}"
EVQ107="${EVF106:-}"; EVQ107="/${EVQ107#http://*/}"
EVA107=$(( $(evo_calls) - EVO0_106 )); EVA5_107=$(( EVO0_106 - EVO0_105 )); RFJ107=$(( $(refused106) - $(n103 "${RF4_106:-}") ))
EVN107="$(cat "$R107"/evo/run-*.n "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | awk '{s += $1} $2 == "cron" {c++} END {printf "%d|%d\n", s, c}')"
check "$EVN107|$(tail -n +"$(( EVO0_105 + 1 ))" "$SB/evo/requests.log" 2>/dev/null | grep -cvE "^(GET|POST) ($EVP107|$EVQ107)/")|$( [ -n "${RF4_106:-}" ] && [ "$RFJ107" -ge 15 ] && [ "$EVA107" -ge "$RFJ107" ] && echo ge)|$( [ -n "${EVODRV105:-}" ] && [ "$EVA5_107" -ge "$(n103 "${EVODRV105:-}")" ] && echo ge)" "0|0|0|ge|ge" \
  "107-10a Evolution: no run of $V107's script, $V106's or $V105's called it ($(ls "$R107"/evo/run-*.n "$R106"/evo/run-*.n "$R105"/evo/run-*.n 2>/dev/null | wc -l | tr -d ' ') runs read, every one summed); the cron stand-in ran its worker during none of them; every request on the stand-in's own base paths; outside every run, calls at least the sends refused for J1's numbers ($RFJ107) and 105's drives' own; $(( $(evo_calls) - EVO0_107 )) since 107-pre, master's cycles and 107-8f's worker run among them (the worker's asserted 0 there)"
# 107-10b — uCRM (106-10b's reading, carried): every run's request record GETs only, no token copy left at the next call — 107's,
# 106's, 105's and 104's runs; nothing of any of them left in /tmp. The CONTROL a planted POST and a planted token copy, removed
SCN107="$R107/scan107"; mkdir -p "$SCN107"; chmod 700 "$SCN107"
UO107="$(uo106 "$R107"/ucrm/run-*.req "$R106"/ucrm/run-*.req "$R105"/ucrm/run-*.req "$R104"/ucrm/run-*.req)"; TK107="$(tl106 "$R107"/tok/run-*.tok "$R106"/tok/run-*.tok "$R105"/tok/run-*.tok "$R104"/tok/run-*.tok)"
printf 'GET /organizations app-key\nPOST /clients app-key\n' > "$SCN107/zz-control.req"; printf '0 x\n1 remote\n1 y\n' > "$SCN107/zz-control.tok"
check "$UO107|$TK107|$(ls -d /tmp/dnb-$V107-* /tmp/dnb-$V106-* /tmp/dnb-$V105-* /tmp/dnb-$V104-* /tmp/dnb-report-* 2>/dev/null | wc -l | tr -d ' ')|$(for d107 in "$R107" "$R106" "$R105" "$R104"; do ls "$d107"/ucrm/run-*.req 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "r" : "-"}'; done)|$(uo106 "$SCN107/zz-control.req")$(tl106 "$SCN107/zz-control.tok")" \
      "0|0|0|rrrr|11" \
  "107-10b uCRM asked GETs only in every run of $V107, $V106, $V105 and $V104; no token copy left; nothing of any of them in /tmp; controls: a planted POST and a planted token copy left, each found"
rm -f "$SCN107/zz-control.req" "$SCN107/zz-control.tok"
# 107-10c — 107's own records (every terminal, mutant terminal, sheet, log, stream, state and rollback record, row digest and case
# file, backups aside), and what else 107's runs and plants wrote: the master passes (master106's files, which 107's calls write
# too), the held actions' output, the uCRM request records, the boots' output files (v3 FM7: H2's, H4's and boot107's) and the
# installed worker's output (107-8f). Scanned with both of 107's pattern files (the chain's names and RH107SECRET-; every number of
# the chain's ranges and 107's in its four forms and last nine digits). A CONTROL file holding one of each, planted through the
# list as a run terminal and as a boot output, found by both scans, removed.
sweep107() { { ls "$R107"/run-*.txt "$R107"/mutants/*.txt "$R107"/sheets/* "$R107"/logs/*.log "$R107"/logs/stream-*.txt "$OUT107"/state-* "$OUT107"/rollback-state-* "$OUT107"/cih-rows-* "$OUT107"/*.log "$OUT107"/cash-*.txt; \
               find "$R107/case" -type f ! -path '*/backup-*/*' 2>/dev/null; } 2>/dev/null | grep -v '/lk-key' | sort -u; }
sweepx107() { ls -1 "$R104"/logs/master-106m-*.out "$R104"/logs/master-106s-*.out "$R107"/logs/act-*.txt "$R107"/logs/*.json "$R107"/ucrm/run-*.req "$R107"/boot/*.out "$R107"/logs/worker107.out 2>/dev/null; }
sweepall107() { { sweep107; sweepx107; } | sort -u; }
printf 'RH107SECRET-control %s\n' "$(grep -m1 -F '7001070' "$FORMS107")" > "$SCN107/control.txt"; chmod 600 "$SCN107/control.txt"
NHS107="$(sweepall107 | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FHS107="$(sweepall107 | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
SWR107="$(ls "$R107"/run-*.txt 2>/dev/null | grep -c .)"; SWB107="$(sweepx107 | grep -c '/boot/')"
cp "$SCN107/control.txt" "$R107/run-zz107ctl.txt"; cp "$SCN107/control.txt" "$R107/boot/zz107ctl.out"
SWC107="$(for p107 in run-zz107ctl.txt boot/zz107ctl.out; do printf '%s%s ' "$(sweepall107 | grep -F "/$p107" | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')" "$(sweepall107 | grep -F "/$p107" | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R107/run-zz107ctl.txt" "$R107/boot/zz107ctl.out"
check "$NHS107|$FHS107|$(grep -lF -f "$NM107F" "$SCN107/control.txt" | wc -l | tr -d ' ')$(grep -lF -f "$FORMS107" "$SCN107/control.txt" | wc -l | tr -d ' ')|$( [ "$SWR107" -gt 0 ] && [ "$SWB107" -gt 0 ] && echo listed)|$SWC107" "0|0|11|listed|11 11" \
  "107-10c no planted name (the chain's names, RH106SECRET-, RH107SECRET-) and no fixture number (the chain's ranges and $V107's, each in its four forms and its last nine digits) in any of $V107's terminals, logs, sheets, streams, state and rollback records, row digests, case files (their backups aside), master passes, held actions, request records, boot outputs or the worker's output ($(sweepall107 | grep -c .) files); the control found through the list as a run terminal and as a boot output"
# 107-10c' — 106's records by 106's own list (sweep106) and its extra writers (106-10c's sweepx106: master passes, held actions,
# request records), 105's by 105-10's (sweep105) — not swept by a 106-10 (cut with the chain); the CONTROL planted as one of each's
# run terminals, found by both scans through its list, removed
sweepx106_107() { ls -1 "$R106"/logs/act-*.txt "$R106"/ucrm/run-*.req 2>/dev/null; }
NH6_107="$( { sweep106; sweepx106_107; } | sort -u | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FH6_107="$( { sweep106; sweepx106_107; } | sort -u | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
NH5_107="$(sweep105 | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FH5_107="$(sweep105 | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN107/control.txt" "$R106/run-zz107ctl.txt"; cp "$SCN107/control.txt" "$R105/run-zz107ctl.txt"
SWC65_107="$(sweep106 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')$(sweep106 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ') $(sweep105 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')$(sweep105 | grep -F '/run-zz107ctl.txt' | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
rm -f "$R106/run-zz107ctl.txt" "$R105/run-zz107ctl.txt"
check "$NH6_107|$FH6_107|$NH5_107|$FH5_107|$( [ "$(sweep106 | grep -c .)" -gt 0 ] && [ "$(sweep105 | grep -c .)" -gt 0 ] && echo listed)|$SWC65_107" "0|0|0|0|listed|11 11" \
  "107-10c' …nor in any of $V106's records (sweep106 and its held actions and request records: $( { sweep106; sweepx106_107; } | sort -u | grep -c .) files) or $V105's (sweep105: $(sweep105 | grep -c .) files); the control found through each list"
# 107-10c'' — 104's records by 104-10's own lists (106-10c'''s reading, carried: the three one-line definitions read from 5.18.104's
# rehearsal, each the start of exactly one line after 104-10's heading, sourced unchanged); a CONTROL where each looks
L104F_107="$(mktemp)"
python3 -I - "$H104_HARNESS105" "$L104F_107" <<'PY' || echo "rh107:lists104" >> "$SB/anchor-missing"
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
. "$L104F_107"; rm -f "$L104F_107"
sweep104_107() { { sweep_files104; sheets104; ls -1 "$OUT104"/cash-*.txt 2>/dev/null; reqs104; } | sort -u; }
NH4_107="$(sweep104_107 | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')"; FH4_107="$(sweep104_107 | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"
NL4_107="$(for l107 in sweep_files104 sheets104 reqs104; do "$l107" 2>/dev/null | grep -c . | awk '{printf "%s", ($1 > 0) ? "y" : "n"}'; done)"   # each list read something before the controls (106-10c'')
mkdir -p "$R104/sheets" "$R104/ucrm"
for f107 in "$R104/run-zz107ctl.txt" "$R104/sheets/cash-zz107ctl.txt" "$R104/ucrm/run-zz107ctl.req"; do cp "$SCN107/control.txt" "$f107"; done
CL4_107="$(for l107 in sweep_files104 sheets104 reqs104; do printf '%s%s ' "$("$l107" 2>/dev/null | grep -F 'zz107ctl' | xargs -r grep -lF -f "$NM107F" 2>/dev/null | wc -l | tr -d ' ')" "$("$l107" 2>/dev/null | grep -F 'zz107ctl' | xargs -r grep -lF -f "$FORMS107" 2>/dev/null | wc -l | tr -d ' ')"; done | sed 's/ $//')"
rm -f "$R104/run-zz107ctl.txt" "$R104/sheets/cash-zz107ctl.txt" "$R104/ucrm/run-zz107ctl.req" "$SCN107/control.txt"
check "$NH4_107|$FH4_107|$NL4_107|$CL4_107" "0|0|yyy|11 11 11" \
  "107-10c'' …nor in any of $V104's records, which 107's runs write too (sf.txt, the wrappers' logs of every command run as printed: $(sweep104_107 | grep -c .) files, by 104-10's own lists, each list reading some); the control found through each"
# 107-10d — the LK key files 5.18.107 wrote (its run folder's and the case folders'), each mode 0600 and read; no key's value in
# any file the sweeps above read; the CONTROL a copy of the keys, found
KL107="$( { [ -e "$LKF107" ] && echo "$LKF107"; find "$R107/case" -type f -name "lk-key-$V107" 2>/dev/null; } | sort -u)"
: > "$SCN107/key.pat"; chmod 600 "$SCN107/key.pat"; SK107=""
for f107 in $KL107; do SK107="$SK107 $(q105 secret "$f107" "$SCN107/key1.pat" | tr ' ' '/')"; [ -f "$SCN107/key1.pat" ] && cat "$SCN107/key1.pat" >> "$SCN107/key.pat"; rm -f "$SCN107/key1.pat"; done
SK107="${SK107# }"
KH107="$( { sweepall107; sweep106; sweep105; sweep104_107; } 2>/dev/null | sort -u | xargs -r grep -lF -f "$SCN107/key.pat" 2>/dev/null | wc -l | tr -d ' ')"
cp "$SCN107/key.pat" "$SCN107/key-control.txt"
check "$( [ "$(grep -c . <<<"$KL107")" -ge 1 ] && echo some)|$(tr ' ' '\n' <<<"$SK107" | grep -vcx 'mode=600/key=read')|$KH107|$(grep -lF -f "$SCN107/key.pat" "$SCN107/key-control.txt" 2>/dev/null | wc -l | tr -d ' ')" "some|0|0|1" \
  "107-10d every LK key file $V107 wrote ($(grep -c . <<<"$KL107") file(s), $(sort -u "$SCN107/key.pat" | grep -c .) key value(s)) mode 0600 and read; no key's value in any record of 107's, 106's, 105's or 104's; the control found"
rm -f "$SCN107/key.pat" "$SCN107/key-control.txt"
# 107-10e — 107's own numbers and marker in the plugin's data folders and the records: in no file but the book — and, for the
# marker alone, kyc_config.json, which holds 107-pre's planted settings text (the one fixture that stays by design, v2 A3, said
# so: its numbers are none). The control: the book holds both
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
ND1_107="$( { grep -rlaE --exclude='plugin.sqlite3*' "$RX107" "$DATA" "$PD/data" "$RECS"; grep -rlaF --exclude='plugin.sqlite3*' 'RH107SECRET-' "$DATA" "$PD/data" "$RECS" | grep -vxF "$DATA/kyc_config.json"; } 2>/dev/null | sort -u)"   # the one exemption by its path, never a name at every depth
check "$(grep -c . <<<"$ND1_107")|$(grep -caF 'RH107SECRET-' "$DATA/kyc_config.json" 2>/dev/null)|$(grep -caE "$RX107" "$DATA/kyc_config.json" 2>/dev/null)|$( [ "$(n103 "$(grep -caE "$RX107" "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo numbers)|$( [ "$(n103 "$(grep -caF 'RH107SECRET-' "$DATA/plugin.sqlite3" 2>/dev/null)")" -ge 1 ] && echo marker)" \
      "0|1|0|numbers|marker" \
  "107-10e $V107's own numbers ($PR107<NN>) and its RH107SECRET- marker in no file of the plugin's data folders or the records but the book — the marker once in kyc_config.json, the planted settings text (107-pre5, kept by design), with none of 107's numbers ($(grep -c . <<<"$ND1_107") other file(s)$( [ -n "$ND1_107" ] && printf ': %s' "$(xargs -r -n1 basename <<<"$ND1_107" | paste -sd, -)")); the control: the book holds both"
# 107-10f — the fixtures' leftovers, each asserted gone: every trigger of 106's and 107's (LEFT107, v3); the clone's index lock;
# the weakened copies (every $REPO/scripts/.NAME.sh newer than this release's script; the CONTROL one planted, counted, removed);
# the keep folders empty (107's, its state-file keep and 107-3's NS keep), kept in place; every control this section planted gone; NA-CA1's sentinel row gone; 071's ledger checksum
# as 107-4 read it (the ledger071 undo, v3); migration.log writable as found; H2's refusal record empty; no [master] line of
# 107-7's plants left; neither RH107_RANGE nor RH106_TOUCH in the rehearsal's own environment
WC0_107="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY107" 2>/dev/null | grep -c .)"
cp "$DEPLOY107" "$REPO/scripts/.zz107ctl.sh"; touch "$REPO/scripts/.zz107ctl.sh"; WC1_107="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY107" 2>/dev/null | grep -c .)"; rm -f "$REPO/scripts/.zz107ctl.sh"
KN107="$(kept107)|$(ls -A "$STK107" 2>/dev/null | grep -c .)|$(nskept107)|$(ls "$R104"/run-zz107ctl.txt "$R104"/sheets/cash-zz107ctl.txt "$R104"/ucrm/run-zz107ctl.req "$R105"/run-zz107ctl.txt "$R106"/run-zz107ctl.txt "$R107"/run-zz107ctl.txt "$R107"/boot/zz107ctl.out 2>/dev/null | grep -c .)"
check "$(LEFT107)|$(lock106)|$WC0_107:$WC1_107:$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY107" 2>/dev/null | grep -c .)|$KN107|$(kv107 "$(ledgerx107)" sentinel)|$(kv107 "$(ledgerx107)" cks071)|$(stat -c %a "$DATA/migration.log")|$(grep -c . "$SB/plant092touch107.refused" 2>/dev/null)|$(n103 "$(grep -c 'RH107SECRET- a thrown error' "$PD/data/plugin.log" 2>/dev/null)")|$(env | grep -cE '^(RH107_RANGE|RH106_TOUCH)=')" \
      "none|absent|0:1:0|0|0|0|0|0|$(kv107 "$LX4_107" cks071)|$(stat -c %a "$SNAP107/$(sha256sum <<<"$DATA/migration.log" | cut -c1-16)" 2>/dev/null)|0|0|0" \
  "107-10f the fixtures' leftovers gone: no trigger of 106's or 107's armed, no index lock, no weakened copy left (the control planted, counted, removed), nothing kept, NA-CA1's sentinel row gone, 071's checksum as 107-4 read it (ledger071 undone), migration.log's mode as the snapshot's, H2 never refused a backward clock, no planted [master] ERROR left, neither range nor touch flag in the rehearsal's own environment"
rm -rf "${SCN107:?}"
# 107-10g — the stand-in web server's log (v3 FM6): no new Fatal or Parse error since 107-infra f's relaunch; every boot output of
# 107's says booted
WF1X_107="$(grep -cE 'Fatal error|Parse error' "$SB/web.log" 2>/dev/null)"
BO107="$(for f107 in "$R107"/boot/*.out; do [ -s "$f107" ] || continue; grep -qvxE 'booted|' "$f107" && echo "${f107##*/}"; done | paste -sd, -)"   # any line but booted (h2.out and h4.out gather every firing)
check "$(( $(n103 "$WF1X_107") - $(n103 "$WF0X_107") ))|$( [ "$(grep -c . "$SB/web.log")" -gt "$(n103 "$WL0_107")" ] && echo grew)|${BO107:-all-booted}" "0|grew|all-booted" \
  "107-10g the stand-in's log: no new Fatal or Parse error since its relaunch on the clock (it grew: every request since was logged); every boot output of 107's (H2's, H4's, boot107's) says booted"
# 107-10h — 106's sections' failure files (106-10g/h, cut with 106-10): from 106's baselines (AM0_106, WF0_106, BR0_106) to 107's
# (AM0_107, WF0_107, BR0_107), and the control of the controls over every anchor 106's copies set (ancc106)
AMS6_107=$(( AM0_107 - $(n103 "${AM0_106:-}") )); WFS6_107=$(( WF0_107 - $(n103 "${WF0_106:-}") )); AC6_107="$(ancc106)"
check "$( [ -n "${AM0_106:-}" ] && [ -n "${WF0_106:-}" ] && echo set || echo unset)|$AMS6_107|$(( $(n103 "$(grep -c '^run106-' "$SB/bad-runs" 2>/dev/null)") - $(n103 "${BR0_106:-}") ))|$WFS6_107|$( [ "$AMS6_107" -gt 0 ] && tail -n +$(( $(n103 "${AM0_106:-}") + 1 )) "$SB/anchor-missing" 2>/dev/null | head -n "$AMS6_107" | paste -sd, -)|$(cut -d'|' -f1,3 <<<"$AC6_107")" \
      "set|0|0|0||0|-" \
  "107-10h $V106's sections (106-10g and 106-10h, cut with 106-10, judged here): every asserted edit and anchor found, no run of 106's runners given an unknown option, no bounded wait timed out; every anchor 106's copies set ($(cut -d'|' -f2 <<<"$AC6_107")) still its script's own text, once"
# 107-10i — 107's own: anchor-missing, wait104.fail and bad-runs against 107-infra's baselines; ancc107 over every anchor 107's copies set
AM1_107="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM1_107="${AM1_107:-0}"; WF1_107="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF1_107="${WF1_107:-0}"
BR1_107="$(grep -c '^run107-' "$SB/bad-runs" 2>/dev/null)"; AC107="$(ancc107)"
check "$(( AM1_107 - AM0_107 ))|$(( $(n103 "$BR1_107") - BR0_107 ))|$(( WF1_107 - WF0_107 ))|$(tail -n +$(( AM0_107 + 1 )) "$SB/anchor-missing" 2>/dev/null | paste -sd, -)|$(cut -d'|' -f1,3 <<<"$AC107")" "0|0|0||0|-" \
  "107-10i $V107's own sections: every asserted edit, anchor and plant found, no run of 107's runners given an unknown option, no bounded wait timed out; every anchor its weakened copies set ($(cut -d'|' -f2 <<<"$AC107")) still the script's own text, found in it once, its replacement different"
# 107-10j — the end state: 107-9 left 5.18.107 serving, every file 5.18.107 changes as the pin has it, K, Q and W.1 off; this
# checkout's status exactly as the rehearsal found it
check "$(live)|$(installed107 "$P107F")|$(nsd107)|$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)" "$P107|0|\"0\"|absent|\"0\"|$ST0_107" \
  "107-10j the end state: $P107 ($(inst_ver)) serving, every file $V107 changes as the pin has it, K '0', Q absent, W.1 '0'; this checkout's status exactly as the rehearsal found it"

echo
echo "RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE107 before $V107's own sections (the earlier rehearsals' chain)"
[ "$FAILN" = 0 ]
