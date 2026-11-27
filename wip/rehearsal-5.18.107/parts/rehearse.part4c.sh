# ════════════════════════════════════════════════════════════════════════════
# 107-8 part one, on D1 — every rollback answered anything but ROLLBACK (v3 §B D1.5): nothing changed by any of them. The
# switches are turned on and off by the commands the script itself prints, run as printed, each alone.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-8 (part one). on D1: the rollback answered anything but ROLLBACK — W.1 on, NOT READ, TD7 (K, then Q), the counts, W.1 off as printed, §M with the real worker, control 0; then K and Q off as printed =="
rbno107() {   # rbno107 [ANSWER] [--env K=V] — a --rollback under the clock answered ANSWER (no by default): not confirmed, nothing changed;
              # RBNO107 its output; asserted by the caller with rbq107
  local a=no; [ "${1:-}" = "" ] || { case "$1" in --*) ;; *) a="$1"; shift ;; esac; }
  D94R_107="$(data94)"; RBC0_107="$(rbcopy107)"; RKS0_107="$(keysum107)"; RST0_107="$(sha256sum < "$STATE107" 2>/dev/null | cut -c1-16)"
  fresh107; RBNO107="$(run107c --answer "$a" "$@" --rollback)"
}
rbcopy107() { grep -l '^RB_COPY_AT=[0-9]' "$OUT107"/rollback-state-*.env 2>/dev/null | wc -l | tr -d ' '; }   # rollback records holding a copy time (a declined rollback writes its record before the question, S:13757, with none)
rbq107() {   # rbq107 LABEL — the latest rbno107 not confirmed, nothing changed: $P107 serving, the data folder's digest as before, no record with a copy time
  check "$(has "$RBNO107" '  STOP: not confirmed')$(has "$RBNO107" 'Nothing was changed. Send the log file.')|$(rc107)|$(live)|$(data94)|$(rbcopy107)|$(keysum107)|$(sha256sum < "$STATE107" 2>/dev/null | cut -c1-16)" \
        "yesyes|1|$P107|$D94R_107|$RBC0_107|$RKS0_107|$RST0_107" "$1 not confirmed: 'Nothing was changed', exit 1, $P107 serving, the data folder's digest as before, the LK key file and the deploy's state file unchanged, no new rollback record with a copy time (the record a declined rollback writes before its question carries none — S:13757, as 5.18.106's rehearsal reads it)"
}
printed107() { grep -F -- "$2" <<<"$1" | grep -E '^      ' | head -1 | sed -E 's/^ {6}//'; }   # printed107 OUTPUT FRAGMENT — the first command line the script printed (six spaces) holding FRAGMENT
jv107() { python3 -I -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    print("unread"); sys.exit(0)
print(json.dumps(d[sys.argv[2]]) if isinstance(d, dict) and sys.argv[2] in d else "absent")' "$DATA/kyc_config.json" "$1"; }   # jv107 KEY — kyc_config.json's value for KEY, as JSON, or absent
W1ON107="$(printed107 "$AFT107" 'set ai_burst_merge_enabled 1')"; KON107="$(printed107 "$AFT107" '--key quoted_no_prices_enabled --value 1')"; QON107="$(printed107 "$AFT107" '--key connection_quote_enabled --value 1')"
check "$W1ON107|$( [ -n "$KON107" ] && echo k)|$( [ -n "$QON107" ] && echo q)" "cd $REPO && bash scripts/sales-followup.sh set ai_burst_merge_enabled 1|k|q" \
  "107-8-0 the PASSED --after-only (107-7z) printed the owner's steps, each alone: W.1's switch-on through the pin's wrapper, and K's and Q's through 5.18.107's set_config (read from its output, never typed)"
# 8a — W.1 on, as printed; then the rollback answered no: the capitals, both §K commands each alone, the redeploy sentence, the
# prompt saying on — no wait (the switch is on); then any other answer ("Rollback") changes nothing either
OW1_107="$(run_line107c "" "$W1ON107")"
check "$(rc107)|$(has "$OW1_107" 'SET ai_burst_merge_enabled = 1')|$(jv107 ai_burst_merge_enabled)" '0|yes|"1"' "107-8a0 W.1 on through the pin's wrapper, run as printed: 'SET ai_burst_merge_enabled = 1', kyc_config.json holds '1'"
rbno107
check "$(has "$RBNO107" 'ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED')|$(has "$RBNO107" "A REDEPLOY'S NS-G1 REFUSES WHILE IT READS ON")|$(grep -cxF "      $BDOFF107" <<<"$RBNO107")|$(grep -cxF "      $BDDR107" <<<"$RBNO107")|$(has "$RBNO107" 'RB107 waiting 125 s')|$(has "$RBNO107" '— ai_burst_merge_enabled on; the last drain reading')" \
      "yes|yes|1|1|no|yes" \
  "107-8a the rollback with W.1 on, answered no: ANSWER ANYTHING BUT ROLLBACK in capitals, the redeploy sentence, the switch-off and the drain reading each alone on its line, no 125 s wait, the prompt saying on"
rbq107 "107-8a'"
NAI0_107="$(sed -n 's/.*RB-X before the rollback: active wa_no_ai rows \([0-9][0-9]*\) —.*/\1/p' <<<"$RBNO107" | head -1)"; NAL0_107="$(sed -n 's/.*pending wa\.no_ai_alert events \([0-9][0-9]*\) —.*/\1/p' <<<"$RBNO107" | head -1)"
check "$( [ -n "$NAI0_107" ] && [ -n "$NAL0_107" ] && echo read)" "read" "107-8a* RB-X's counts as found, read from the script's own line before anything is planted: $NAI0_107 listed, $NAL0_107 alert(s)"
rbno107 Rollback; rbq107 "107-8a\" any other answer (Rollback, not ROLLBACK)"
# 8b — the switch NOT READ (FAKE_CP_FAIL: the pin's code cannot be copied, root:456): SWITCH NOT READ, both commands, the prompt saying
# NOT READ; step 5's capitals naming K and Q as not read, each set_config --value 0 alone. Copies: the set command dropped; step 5
# firing only when K or Q reads on.
anc107 dropset '    [ "$s2" = "off" ] || echo "      $BD_OFF_CMD"   # §K'"'"'s switch-off: printed, never run (RB107)' '    :   # rh107: the switch-off line dropped'
anc107 kqon "$(printf '    q="$(rb_kq "${LK_RB0:-}" "$k")"\n    [ "$q" = "off" ] && continue\n')" "$(printf '    q="$(rb_kq "${LK_RB0:-}" "$k")"\n    [ "$q" = "on" ] || continue\n')"
rbno107 no --env FAKE_CP_FAIL=1
KOFF107="$(printed107 "$RBNO107" '--key quoted_no_prices_enabled --value 0')"; QOFF107="$(printed107 "$RBNO107" '--key connection_quote_enabled --value 0')"
check "$(has "$RBNO107" 'SWITCH NOT READ — ai_burst_merge_enabled: NOT READ (the pin is not staged: the release code could not be copied above)')|$(grep -cxF "      $BDOFF107" <<<"$RBNO107")|$(grep -cxF "      $BDDR107" <<<"$RBNO107")|$(has "$RBNO107" '— ai_burst_merge_enabled NOT READ')|$(has "$RBNO107" "quoted_no_prices_enabled COULD NOT BE READ (NS-G1'S RULE, ON LK-RB0)")|$(has "$RBNO107" "connection_quote_enabled COULD NOT BE READ (NS-G1'S RULE, ON LK-RB0)")|$(grep -cxF "      $KOFF107" <<<"$RBNO107")|$(grep -cxF "      $QOFF107" <<<"$RBNO107")" \
      "yes|1|1|yes|yes|yes|1|1" \
  "107-8b the switch NOT READ (the pin's code made uncopiable, said so): SWITCH NOT READ with its reason (P3B_WHY, S:12540), both §K commands each alone, the prompt saying NOT READ; step 5 names K and Q as not read, each set_config --value 0 alone with its own log"
rbq107 "107-8b'"
fresh107; mutant107 c8b-dropset dropset -- --env FAKE_CP_FAIL=1 --answer no --rollback
check "$(grep -cxF "      $BDOFF107" <<<"$MUT107")|$(grep -cxF "      $BDDR107" <<<"$MUT107")|$(has "$MUT107" 'SWITCH NOT READ — ai_burst_merge_enabled: NOT READ (the pin is not staged: the release code could not be copied above)')" "0|1|yes" "107-8b\" a weakened copy that drops the switch-off line — caught (the drain line and the reason still printed: the copy reached the same place)"
fresh107; mutant107 c8b-kqon kqon -- --env FAKE_CP_FAIL=1 --answer no --rollback
check "$(has "$MUT107" 'COULD NOT BE READ (NS-G1')|$(grep -cF -- '--value 0' <<<"$MUT107")|$(has "$MUT107" 'SWITCH NOT READ — ai_burst_merge_enabled: NOT READ (the pin is not staged: the release code could not be copied above)')|$(has "$MUT107" '  STOP: not confirmed')" "no|0|yes|yes" "107-8b* a weakened copy whose step 5 fires only on 'on': no note and no line when K and Q cannot be read — caught"
# 8c — TD7: K on through 5.18.107's set_config, as printed; the rollback answered no names K, its off command alone; then Q on: both
for k107 in quoted_no_prices_enabled connection_quote_enabled; do
  OK8_107="$(run_line107c "" "$( [ "$k107" = quoted_no_prices_enabled ] && echo "$KON107" || echo "$QON107")")"
  check "$(rc107)|$(has "$OK8_107" "  $k107 = 1")" "0|yes" "107-8c0 $k107 on through 5.18.107's set_config, run as printed: it prints the line '  $k107 = 1'"
  rbno107
  check "$(has "$RBNO107" "quoted_no_prices_enabled READS ON (NS-G1'S RULE, ON LK-RB0)")|$(has "$RBNO107" "connection_quote_enabled READS ON (NS-G1'S RULE, ON LK-RB0)")|$(grep -cxF "      $KOFF107" <<<"$RBNO107")|$(grep -cxF "      $QOFF107" <<<"$RBNO107")|$(has "$RBNO107" "it prints the line \"  $k107 = 0\" once it has saved")" \
        "yes|$( [ "$k107" = connection_quote_enabled ] && echo yes || echo no)|1|$( [ "$k107" = connection_quote_enabled ] && echo 1 || echo 0)|yes" \
    "107-8c TD7 with $( [ "$k107" = quoted_no_prices_enabled ] && echo 'K on' || echo 'K and Q on'): each named READS ON in capitals, its set_config --value 0 alone, and 'without that line take nothing as saved'"
  rbq107 "107-8c'"
done
# 8d — a listed number (through NoAiList::add, as the staff screens list it) and a queued wa.no_ai_alert (as evo_webhook queues it):
# RB-X's counts; the book kept first and put back after (a wa_no_ai row is never deleted)
K1="$(seedk107 "$P107R" book_keep b8d)"; N8D_107="$(seed107 "$P107R" "[{\"op\":\"noai\",\"phone\":\"$(ph107 05)\",\"name\":\"RH107SECRET- staff\",\"reason\":\"RH107SECRET- a listed number\",\"alert\":1}]")"
rbno107
check "$( [ "$(n103 "$(sd107 "$N8D_107" id)")" -ge 1 ] && echo listed)|$( [ "$(n103 "$(sd107 "$N8D_107" event)")" -ge 1 ] && echo queued)|$(has "$RBNO107" "RB-X before the rollback: active wa_no_ai rows $(( $(n103 "$NAI0_107") + 1 )) — no $BV107 file reads the list")|$(has "$RBNO107" "pending wa.no_ai_alert events $(( $(n103 "$NAL0_107") + 1 )) — $BV107's event processor acks a queued alert as unknown")" "listed|queued|yes|yes" \
  "107-8d a listed number and a queued alert: RB-X counts one more of each than 107-8a found, each with what $BV107 does with it"
rbq107 "107-8d'"
check "$(sd107 "$(seedk107 "$P107R" book_back b8d)" fixture)" "1" "107-8d* the book back from its keep (the listed row and the alert gone with it)"
# 8e — W.1 off by the switch-off as printed (BD_OFF_CMD under 5.18.107: it leaves '0')
OOFF_107="$(run_line107c "" "$BDOFF107")"
check "$(rc107)|$(has "$OOFF_107" 'SET ai_burst_merge_enabled = 0')|$(jv107 ai_burst_merge_enabled)" '0|yes|"0"' "107-8e W.1 off, the switch-off run as printed under $V107: it leaves '0'"
# 8f — §M: a mark whose named event is retrying (two typed ai.reply events on one conversation, the first set aside for the second, the
# second failed once — EventBus and BurstMerge, as the worker writes them), the switch off: the drain lists the ids, after the 125 s
# wait. The real worker (run_worker.php, as the server's cron runs it) then answers the event, and the drain lists it no longer.
K1="$(seedk107 "$P107R" book_keep b8f)"
M8F_107="$(seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 06)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- the price please\",\"instance\":\"sales-001\",\"mark\":\"aside\",\"fail\":1}]")"
A8F_107="$(sd107 "$M8F_107" a)"; B8F_107="$(sd107 "$M8F_107" b)"; C8F_107="$(sd107 "$M8F_107" conv)"
check "$(sd107 "$M8F_107" a_marked)|$(sd107 "$M8F_107" b_status)|$(sd107 "$M8F_107" b_attempts | cut -d/ -f1)|$(sd107 "$M8F_107" origin)" "1|failed|1|typed" "107-8f0 the plant: event #$A8F_107 set aside for #$B8F_107, which failed once and is retrying"
rbno107
EV8F_107="$(printf '%s\n' "$A8F_107" "$B8F_107" | sort -un | paste -sd, -)"
check "$(has "$RBNO107" 'RB107 waiting 125 s, then reading the switch and the drain again')|$(has "$RBNO107" "; the conversations $C8F_107, the events $EV8F_107")|$(has "$RBNO107" '(a) 1, counted apart 0')|$(has "$RBNO107" "RB107 root docs/76 §K's drain holds")" "yes|yes|yes|no" \
  "107-8f §M: the switch off, two readings 125 s apart — the drain lists the mark ((a) 1): conversation $C8F_107, events $EV8F_107 (#$A8F_107 waiting for #$B8F_107); it does not hold"
rbq107 "107-8f'"
clkfwd107 15
worker107() { env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
  php -d log_errors=0 "$IN_CONTAINER/run_worker.php" </dev/null >> "$R107/logs/worker107.out" 2>&1; echo $?; }   # worker107 — one run of the installed worker, as the cron runs it
# This sandbox reaches no model provider (5.18.104's control: no provider key is set), so the worker cannot answer #B with a
# reply — it would fail it again and the mark would stay listed. Its own way to run #B here: #B's number listed through
# NoAiList::add, which the installed worker settles done with no model call (root docs/76 §X.3) — said so.
N8F_107="$(seed107 "$P107R" "[{\"op\":\"noai\",\"phone\":\"$(ph107 06)\",\"name\":\"RH107SECRET- a customer\",\"reason\":\"RH107SECRET- the named event run by the worker's own hold\"}]")"
check "$( [ "$(n103 "$(sd107 "$N8F_107" id)")" -ge 1 ] && echo listed)|$(sd107 "$N8F_107" event)" "listed|0" "107-8f\"0 #$B8F_107's number listed (no alert): the sandbox reaches no model provider, so this is how the installed worker runs #$B8F_107 here — settled done with no model call, said so"
WR8F_107="$(worker107)"; ES8F_107="$(sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 evstat "$DATA/plugin.sqlite3" "$A8F_107" "$B8F_107")"
check "$WR8F_107|$(cut -d' ' -f1 <<<"$ES8F_107" | cut -d: -f1-2)|$(cut -d' ' -f2 <<<"$ES8F_107" | cut -d: -f1-2)" "0|$A8F_107:done|$B8F_107:done" \
  "107-8f\" the installed worker (run_worker.php, as the cron runs it, as the book's owner, under the clock) runs both: #$A8F_107 carried by its mark, #$B8F_107 held (the list) — each done ($ES8F_107)"
rbno107
check "$(has "$RBNO107" "; the conversations $C8F_107,")|$(has "$RBNO107" '(a) 0, counted apart')" "no|yes" "107-8f* the drain after the worker: the mark no longer listed, (a) 0 (§T.11:3528–3529)"
rbq107 "107-8f**"
check "$(sd107 "$(seedk107 "$P107R" book_back b8f)" fixture)" "1" "107-8f*** the book back from its keep (the conversation and the events gone with it)"
# 8g — control 0: the clock two days on, so no typed ai.reply is in the last day: the drain's control 0 never counts as zero — the
# capitals and the drain command stand. Copy: a reading with control 0 counted as zero ("the drain holds").
anc107 zeroctl '[ "$(kv "$1" b)" = "0" ] && [ "$(kv "$1" control)" != "0" ] ;; *) return 1 ;; esac; }   # rb_bd_zero LINE' '[ "$(kv "$1" b)" = "0" ] ;; *) return 1 ;; esac; }   # rb_bd_zero LINE'
clkfwd107 $((2 * 86400 + 600))
rbno107
check "$(has "$RBNO107" ', control 0')|$(has "$RBNO107" 'ANSWER ANYTHING BUT ROLLBACK BELOW')|$(grep -cxF "      $BDDR107" <<<"$RBNO107")|$(has "$RBNO107" "RB107 root docs/76 §K's drain holds")" "yes|yes|1|no" \
  "107-8g no typed ai.reply in the last day (the clock two days on, said so): the drain's control 0 — the capitals and the drain command stand, never 'the drain holds'"
rbq107 "107-8g'"
fresh107; mutant107 c8g-zero zeroctl -- --answer no --rollback
check "$(has "$MUT107" "RB107 root docs/76 §K's drain holds")" "yes" "107-8g\" a weakened copy that counts a control-0 reading as zero says the drain holds — caught"
# 8h — K and Q off by their printed set_config --value 0 lines (8b's), each alone
for k107 in quoted_no_prices_enabled connection_quote_enabled; do
  OK8_107="$(run_line107c "" "$( [ "$k107" = quoted_no_prices_enabled ] && echo "$KOFF107" || echo "$QOFF107")")"
  check "$(rc107)|$(has "$OK8_107" "  $k107 = 0")" "0|yes" "107-8h $k107 off through 5.18.107's set_config, run as printed: '  $k107 = 0'"
done
master106 "107-8z0"
aft107 "107-8z" PASSED $L2ON107
check "$(live)|$(jv107 ai_burst_merge_enabled)|$(jv107 quoted_no_prices_enabled)|$(jv107 connection_quote_enabled)|$(kept107)" "$P107|\"0\"|\"0\"|\"0\"|0" \
  "107-8zz the exit: $P107 serving, a PASSED --after-only, W.1 '0', K and Q 0, nothing kept"
