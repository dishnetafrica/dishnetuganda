# ════════════════════════════════════════════════════════════════════════════
# 107-7 on D1 — ME-CA1 (W.7) and ME-CA2 against master's own record (root docs/76 §T.11; v3 A10's pollrun107). Each plant
# (plugin.log, master_schedule's row, master_budget_seconds) kept first and put back after; master106 and a PASSED
# --after-only at the end.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-7. on D1: ME-CA1's caught-error half from plugin.log, the poll (a later cycle, no cycle, a re-dispatch), an unreadable schedule, the NOT YET summary; ME-CA2 =="
CU7_107="$(sget107 "$STATE107" COPY_AT)"
TZ7_107="$(cd "$H107/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- "$PHPBIN" -r 'require $argv[1] . "/lib/timezone.php"; echo dn_tz();' "$PD" 2>/dev/null)"
check "$(case "$CU7_107" in [0-9]*) echo read ;; *) echo "unread:$CU7_107" ;; esac)|$(case "$TZ7_107" in */*) echo zone ;; *) echo "unread:$TZ7_107" ;; esac)" "read|zone" \
  "107-7-0 the deploy's COPY_AT from its state file, and the zone master stamps [master] lines in (the installed lib/timezone.php's dn_tz(), called with no config as cron/master.php calls it: $TZ7_107)"
PLOG107="$PD/data/plugin.log"; MS0_107="$(ms107)"
mstamp107() { TZ="$TZ7_107" date -d "@$1" '+%Y-%m-%d %H:%M:%S'; }   # mstamp107 EPOCH — a [master] line's stamp in master's own zone
# 7a — [master] lines planted (a FIXTURE in master's format, said so): a cycle complete before the copy, then the four follow-up jobs
# run after it and a complete cycle — ME-CA1's control holds, 0 ERROR; then an ERROR of followup_send after that cycle: FAIL
cp -p "$PLOG107" "$STK107/plog" 2>/dev/null || : > "$STK107/plog.absent"
{ printf '[%s] [master] RUN followup_close\n' "$(mstamp107 $((CU7_107 - 120)))"; printf '[%s] [master] Cycle complete in 1s\n' "$(mstamp107 $((CU7_107 - 60)))"
  for j107 in followup_close followup_scan followup_run followup_send; do printf '[%s] [master] RUN %s\n' "$(mstamp107 $((CU7_107 + 60)))" "$j107"; done
  printf '[%s] [master] Cycle complete in 2s\n' "$(mstamp107 $((CU7_107 + 62)))"; } >> "$PLOG107"
chown 1000:1000 "$PLOG107"
ao107
check "$AORC107|$(has "$AO107" 'ME-CA1 PASS —')|$(has "$AO107" 'caught errors judged from plugin.log: 0 ERROR after the straddling cycle')|$(has "$AO107" 'errors master caught are not judged')" "0|yes|yes|no" \
  "107-7a [master] lines planted (a FIXTURE in master's own format and zone): ME-CA1's log half holds its control and judges 0 ERROR after the straddling cycle; PASS"
printf '[%s] [master] ERROR followup_send: RH107SECRET- a thrown error\n' "$(mstamp107 $((CU7_107 + 90)))" >> "$PLOG107"
ao107
check "$AORC107|$(has "$AO107" 'ERROR line(s) of the four follow-up jobs in')|$(has "$AO107" 'caught errors judged from plugin.log: 1 ERROR after the straddling cycle — its FAIL above')" "1|yes|yes" \
  "107-7a' a followup_send ERROR after that cycle (planted): ME-CA1's log half FAILs, and the verdict line's clause names it"
if [ -e "$STK107/plog.absent" ]; then rm -f "$PLOG107" "$STK107/plog.absent"; else cp -p "$STK107/plog" "$PLOG107" && rm -f "$STK107/plog"; fi
check "$(ls -A "$STK107" | grep -c .)" "0" "107-7a* plugin.log back as it was"
# 7b — the poll. The plant: followup_send at (L, -1), L the clock's now, after the copy, with no later entry (master_schedule's row
# through the store's save(), the row kept first). The script waits ("ME-CA1 waiting up to"); pollrun107 acts 1 s after the line.
clkfwd107() {   # clkfwd107 SECONDS — the rehearsal's clock moved forward (the offset file, never backward, its mode kept)
  local o; o="$(head -n1 "$CLKF104" 2>/dev/null)"; case "$o" in ''|*[!0-9-]*) echo "clkfwd107:unread" >> "$SB/anchor-missing"; return 1 ;; esac
  printf '%s\n' "$((o + $1))" > "$CLKF104.c107" && cat "$CLKF104.c107" > "$CLKF104" && rm -f "$CLKF104.c107"
}
schedL107() { seedk107 "$P107R" sched "$1" '"set":{"followup_send":{"ago_s":0,"duration_ms":-1}}'; }   # schedL107 TAG — the (L, -1) plant
schedat107() { seed107 "$P107R" "[{\"op\":\"sched\",\"keep\":\"$KEEP107\",\"tag\":\"$1\",\"set\":{\"$2\":{\"ago_s\":$3,\"duration_ms\":$4}}}]"; }   # schedat107 TAG JOB AGO DURATION
schedath107() { local o; o="$(schedat107 "$@")"; printf '%s\n' "$o" > "$R107/logs/schedat-$1.json"; case "$o" in '{'*) return 0 ;; *) return 1 ;; esac; }   # schedat107 as a poll's ACTION: non-zero unless the seed answered JSON (never none(…))
for c107 in cycle nocycle missed; do
  K1="$(schedL107 "s7b-$c107")"
  case "$c107" in
    cycle)   pollrun107 "107-7b-$c107" "$DEPLOY107" -- schedath107 "a7b-$c107" event_processor -30 5 ;;
    missed)  pollrun107 "107-7b-$c107" "$DEPLOY107" -- schedath107 "a7b-$c107" followup_send -30 5 ;;
    nocycle) K0="$(seedk107 "$P107R" budget g7b '"seconds":0')"
             check "$(sd107 "$K0" budget)" "0" "107-7b0 master_budget_seconds 0 in the store's kyc_config row (a small budget, said so: the poll's bound is then 360 s)"
             pollrun107 "107-7b-$c107" "$DEPLOY107" -- clkfwd107 420 ;;
  esac
  T7_107="$(secs107)"; A7_107="$(( $(cat "$R107/rc/run-$(last107).t1" 2>/dev/null || echo 0) - $(stat -c %Y "$R107/logs/act-107-7b-$c107.rc" 2>/dev/null || echo 0) ))"   # the whole run, and from the ACTION's end to the run's
  case "$c107" in
    cycle)   check "$HOLDSAW107|$HOLDRC107|$(rc107)|$(has "$HOLDOUT107" 'ME-CA1 FAIL —')|$(has "$HOLDOUT107" 'never returned to master (duration_ms -1), and a later cycle has begun')" "yes|0|1|yes|yes" \
               "107-7b1 (L, -1) then a later cycle written while the poll waits: ME-CA1 FAIL — followup_send never returned and a later cycle began" ;;
    missed)  check "$HOLDSAW107|$HOLDRC107|$(rc107)|$(has "$HOLDOUT107" 'ran again between two reads of the poll (a newer last_run): the poll missed the window')" "yes|0|3|yes" \
               "107-7b2 (L, -1) then followup_send re-dispatched between two reads: NOT YET, the poll missed the window" ;;
    nocycle) check "$HOLDSAW107|$HOLDRC107|$(rc107)|$(has "$HOLDOUT107" 'master did not start a new cycle within the poll'"'"'s bound: followup_send still read duration_ms -1')|$(has "$HOLDOUT107" "  $V107 (after-only): NOT YET — ")|$(has "$HOLDOUT107" 'run it again after')|$(awk -v t="Then --after-only again, its own command, alone, with its own log:" 'f { print; exit } index($0, t) { f = 1 }' <<<"$HOLDOUT107")" \
                   "yes|0|3|yes|yes|yes|      $EXP_AFTER107" \
               "107-7b3 (L, -1) and no new cycle within the bound (the clock moved past it, said so): NOT YET, master did not start one; the summary NOT YET (exit 3), 'run it again after', the --after-only command alone on the line after the summary's 'Then --after-only again' (as 106-5a' reads it: the 'check it' block prints the same command twice more, S:16091-16095; the sentence, S:16188)"
             check "$(sd107 "$(seedk107 "$P107R" budget_back g7b)" budget)" "$(kv107 "$MS0_107" budget)" "107-7b3' master_budget_seconds back as it was" ;;
  esac
  check "$( [ "$A7_107" -ge 0 ] && [ "$A7_107" -lt 120 ] && echo quick)|$(sd107 "$(seedk107 "$P107R" sched_back "s7b-$c107")" fixture)" "quick|1" \
    "107-7b-$c107* ${A7_107} s from the action to the run's end (${T7_107} s in all): the poll read the change and never waited out its bound (360 s at the least); master_schedule's row back byte for byte"
  [ "$c107" = nocycle ] || rm -f "$KEEP107/a7b-$c107.meta" "$KEEP107/a7b-$c107.orig"   # the action's own keep, overtaken by the row's
done
# 7c — an unreadable schedule (a FIXTURE: the row made text that does not decode): the capitals note, never a PASS, a note
K1="$(seedk107 "$P107R" sched_raw s7c '"raw":"RH107 not json {"')"
ao107
check "$(sd107 "$K1" fixture)|$(has "$AO107" "ME-CA1 NOT ESTABLISHED — MASTER'S OWN RECORD COULD NOT BE READ FROM THE LIVE STORE")|$(has "$AO107" 'ME-CA1 PASS —')" "1|yes|no" \
  "107-7c master_schedule's row unreadable (a FIXTURE): ME-CA1 NOT ESTABLISHED in capitals — never a PASS; exit status $AORC107 (recorded)"
check "$(sd107 "$(seedk107 "$P107R" sched_back s7c)" fixture)" "1" "107-7c* the row back byte for byte"
# 7d — ME-CA2 on one of its seven: (L, -1) then a later cycle — FAIL; (L, -1) with no later entry — the note; and an (L, -1) on an entry
# outside the seven (notify_retry, said so) with a later cycle — no ME-CA2 FAIL line, as the known limit says
for c107 in fail note outside; do
  j107=quote_chase; [ "$c107" = outside ] && j107=notify_retry
  K1="$(seedk107 "$P107R" sched "s7d-$c107" "\"set\":{\"$j107\":{\"ago_s\":$( [ "$c107" = note ] && echo 0 || echo 30),\"duration_ms\":-1}$( [ "$c107" = note ] || echo ',"event_processor":{"ago_s":0,"duration_ms":5}')}")"
  ao107
  case "$c107" in
    fail) check "$AORC107|$(has "$AO107" 'ME-CA2 FAIL — quote_chase started after the copy and never returned to master')" "1|yes" "107-7d1 ME-CA2: quote_chase at (L, -1) and a later cycle begun: FAIL" ;;
    note) check "$(has "$AO107" 'quote_chase running since')|$(has "$AO107" '(no later cycle yet)')|$(has "$AO107" 'ME-CA2 FAIL')" "yes|yes|no" "107-7d2 ME-CA2: quote_chase at (L, -1) with no later entry: the note, running since … (no later cycle yet)" ;;
    outside) check "$(has "$AO107" 'ME-CA2 FAIL')|$(has "$AO107" 'ME-CA2 the seven other jobs whose code')" "no|yes" "107-7d3 an (L, -1) on notify_retry, outside the seven, with a later cycle: no ME-CA2 FAIL line (the known limit), the note still prints" ;;
  esac
  check "$(sd107 "$(seedk107 "$P107R" sched_back "s7d-$c107")" fixture)" "1" "107-7d-$c107* the row back byte for byte"
done
master106 "107-7z0"
aft107 "107-7z" PASSED $L2ON107
check "$(kv107 "$(ms107)" budget)|$(kept107)" "$(kv107 "$MS0_107" budget)|0" "107-7zz the exit: master_budget_seconds as before 107-7, nothing kept, a PASSED --after-only"
