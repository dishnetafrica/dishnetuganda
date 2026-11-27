# ════════════════════════════════════════════════════════════════════════════
# D11 and on — the rollback, the redeploys, and the end (v3 §B: 107-8 part two, 107-9). Entry: A9, a plain first deploy
# PASSED, master106, --after-only PASSED. Corrections to v3 read off the script (the readers' map, §C of this part's notes):
#   R1's settings-text note prints NO restore command (S:9009) — its absence is asserted;
#   V1's automatic rollback exists only in deploy mode (S:13950) — the V1 cases are --redeploy runs, never a --rollback;
#   the three R3 copies reach their branch (pb_para's K/Q lines, S:9121–9131) in a PASSED --after-only's summary, the
#     cheapest mode that reaches it (§B's rule), said so; the put-back paragraph's readings (item 10) likewise.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-8 (part two). D11: the typed rollbacks, the redeploys, H6 with Q and with K, H3, V1 through H5, the put-back paragraph; 107-9 the final redeploy and the owner's steps =="
rbset107() { ls -1 "$OUT107"/rollback-state-*.env 2>/dev/null | wc -l | tr -d ' '; }   # rollback records, any
dredeploy107() {   # dredeploy107 LABEL — a --redeploy after a rollback, DEPLOY typed, under the clock: PASSED, $P107 serving; MG-G1 present,
                   # QF-G2 none pending with 092's negative control reset=1, R7 the ledger the same, LK-CA2 exact, MG-CA1's earlier-deploy
                   # note, NS-G1 GO; DRD107 its output
  fresh107; DRD107="$(run107c --answer DEPLOY --redeploy)"
  check "$(verd107 "$DRD107")|$(rc107)|$(went107 "$DRD107")|$(live)|$(installed107 "$P107F")|$(has "$DRD107" "== $V107 (deploy, --redeploy) — ")" \
        "PASSED|0|yesyes|$P107|0|yes" "$1 the --redeploy, DEPLOY typed, under the clock: PASSED, $P107 serving, every file installed as the pin has it"
  check "$(has "$DRD107" "  ok    MG-G1 the copy's migration ledger holds 092 whole")|$(has "$DRD107" "092 already in the copy's ledger, applied by an earlier deploy (MG-G1), no line logged")|$(has "$DRD107" '  negative control (092) reset=1 status=partial partial=1 check=1 row=0')|$(has "$DRD107" "  ok    R7 the database since this run's stage A: the migration ledger its last row the same (")|$(has "$DRD107" '  ok    LK-CA2 every _schema_checks row unchanged across the copy (')|$(has "$DRD107" '  note  MG-CA1 092 was applied by an earlier deploy, before this copy (')|$(has "$DRD107" '  ok    NS-G1 quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1) read off in every place')" \
        "yes|yes|yes|yes|yes|yes|yes" \
    "$1' MG-G1 present; QF-G2 none pending, 092's negative control reset=1; R7 the ledger's last row the same; LK-CA2 exact; MG-CA1's earlier-deploy note; NS-G1 GO"
}
nsd107() { echo "$(jv107 quoted_no_prices_enabled)|$(jv107 connection_quote_enabled)|$(jv107 ai_burst_merge_enabled)"; }   # K, Q and W.1 in kyc_config.json, as JSON or absent
hasid107() {   # hasid107 OUTPUT PREFIX ID — yes when PREFIX is followed by ID as a whole id (a comma, semicolon, full stop, space or the line's end next), else no
  local p; p="$(printf '%s' "$2" | sed 's/[][\.*^$()+?{}|/]/\\&/g')"; grep -qE -- "${p}$3([,;. ]|\$)" <<<"$1" && echo yes || echo no; }

# ── Entry (v3 D11): A9, a plain first deploy PASSED, master106, --after-only PASSED ──
reset107 "107-8Rb-A9"
dep107
check "$(verd107 "$DEP107")|$DEPRC107|$(live)|$(nsd107)" "PASSED|0|$P107|absent|absent|absent" "107-8Rb the entry: a plain first deploy PASSED, $P107 serving, no K, Q or W.1 key"
master106 "107-8Rb0"
aft107 "107-8Rb1" PASSED $L2ON107

# ── R1 — a typed rollback on the off path (W.1 absent: off). Before it, the reader control of step 6 (v2 A3): a copy whose
# SA_TXT_V9 is the planted text's prefix reads the plant as v9 — a READER CONTROL, never a production reading — answered no. ──
fresh107; mutant107 r1-v9 satxt -- --answer no --rollback
check "$(has "$MUT107" 'RB107 the settings text reads v9 as the workers read it (its sha256 prefix the recorded')|$(has "$MUT107" '  STOP: not confirmed')|$(live)" "yes|yes|$P107" \
  "107-8Rc the reader control (a copy with the planted text's prefix as SA_TXT_V9, said so — never a production reading): step 6 says v9, nothing to restore; answered no, nothing changed"
RS0_107="$(rbset107)"
rbx107 "107-8Rd-RB"
check "$(has "$RBX107_OUT" 'RB107 waiting 125 s, then reading the switch and the drain again')|$(has "$RBX107_OUT" 'RB107 the settings text does not read v9 as the workers read it (')|$(has "$RBX107_OUT" 'This script prints no command that writes bot_custom_instructions')|$(grep -cE '^      .*bot_custom_instructions' <<<"$RBX107_OUT")" \
      "yes|yes|yes|0" \
  "107-8Rd' the off path: two readings 125 s apart, then the question; step 6 says the planted text is not v9 — and prints no restore command (the script prints none, S:9009; v3's 'with the restore command' corrected)"
check "$(has "$RBX107_OUT" 'a queued assistant-off alert is settled done as unknown on both (wa.no_ai_alert=done/0+unknown)')|$(has "$RBX107_OUT" "  ok    RB107 the installed cron/master.php is $BV107's byte for byte")|$(has "$RBX107_OUT" '  ok    LK-RB1 every ladder key, in every place, reads after the rollback exactly as before it')|$(has "$RBX107_OUT" 'RB-X after the rollback: active wa_no_ai rows ')|$(( $(rbset107) - RS0_107 ))" \
      "yes|yes|yes|yes|1" \
  "107-8Rd\" after the put-back: RB's assistant-off alert settled done as unknown on both (NA_RB, EP_NOAI_DONE); RB107 master $BV107's byte for byte; LK-RB1 every ladder key as before; RB-X counted; one rollback record"
RS1_107="$(rbset107)"; fresh107; O8E_107="$(run107c --rollback)"
check "$(rc107)|$(verd107 "$O8E_107")|$(has "$O8E_107" "  note  the container already serves $B107 ($BV107) — nothing to roll back; running the rollback checks")|$(has "$O8E_107" 'LK-RB1 not judged: this run rolled nothing back')|$(has "$O8E_107" 'Type ROLLBACK')|$(rbset107)|$(live)" \
      "0|PASSED|yes|yes|no|$RS1_107|$B107" \
  "107-8Rd* --rollback again with $BV107 serving: RB_NOTHING — nothing to roll back, LK-RB1 not judged, no question asked, no record written, PASSED"

# ── RD1 — a --redeploy with a 092 WARNING planted and 092's OK line removed before it (§T.11:3485–3486): the straddle note and the
# redeploy note, PASSED. Its copy (the WARNING counted as a FAIL) as --after-only. Then master106 and --after-only PASSED. ──
K1="$(seedk107 "$(pinroot107)" log_keep r8e)"; K2="$(seedk107 "$(pinroot107)" log_drop r8e '"match":"ok092"')"; K3="$(seedk107 "$(pinroot107)" log_add r8e '"line":"warning092"')"
check "$(sd107 "$K2" removed)|$(sd107 "$K3" fixture)|$(kv107 "$(log107)" ok)|$(kv107 "$(log107)" warning)" "1|1|0|1" "107-8Re0 the plants (FIXTURES): 092's OK line taken out, a 092 WARNING appended"
dredeploy107 "107-8Re"
check "$(has "$DRD107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$DRD107" '  note  MG-CA2 no OK line for 092 in migration.log — a redeploy: 092 was applied by an earlier deploy')|$(has "$DRD107" '— not judged here:')|$(has "$DRD107" '  FAIL  MG-CA2')" "yes|yes|no|no" \
  "107-8Re\" RD1: the straddle note (MG-CA1, NA-CA1 and R1 hold) and MG-CA2's redeploy note — never a FAIL"
anc107 warnfail '      note "MG-CA2 migration.log holds 092'"'"'s checksum WARNING ($wl): a read met bytes other than those recorded' '      MG_CA2_R=bad; bad "MG-CA2 migration.log holds 092'"'"'s checksum WARNING ($wl): a read met bytes other than those recorded'
idle104; fresh107; mutant107 c8e-warn warnfail -- --after-only
check "$MUTRC107|$(has "$MUT107" "  FAIL  MG-CA2 migration.log holds 092's checksum WARNING")" "1|yes" "107-8Re* a weakened copy that counts the WARNING as a FAIL — caught (as --after-only)"
master106 "107-8Re**"
aft107 "107-8Re***" PASSED $L2ON107
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back r8e)" fixture)|$(kept107)" "1|0" "107-8Re+ migration.log back"

# ── R2 — W.1 on (the pin's wrapper, as printed), and a burst mark written while Type ROLLBACK waits (rbhold107; the seed's burst op,
# as the worker writes it): ROLLBACK typed, it completes; rb_pre107's reading, taken before the mark, does not list it; rb107's
# lists its conversation. Then a --check: NS-G1 NO-GO naming the key and its place, nothing changed. A fixture clears W.1 (A13). ──
OW_107="$(run_line107c "" "$W1ON107")"
check "$(rc107)|$(has "$OW_107" 'SET ai_burst_merge_enabled = 1')|$(jv107 ai_burst_merge_enabled)" '0|yes|"1"' "107-8Rf0 W.1 on through the pin's wrapper, as printed"
plant2_107() { seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 07)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- and the price\",\"instance\":\"sales-001\",\"mark\":\"aside\",\"fail\":1}]" > "$R107/logs/plant2.json"; case "$(head -c1 "$R107/logs/plant2.json")" in '{') return 0 ;; *) return 1 ;; esac; }
RS2_107="$(rbset107)"
rbhold107 107-8Rf ROLLBACK -- plant2_107
P2_107="$(cat "$R107/logs/plant2.json" 2>/dev/null)"; C2_107="$(sd107 "$P2_107" conv)"
PRE2_107="$(sed -n '1,/Type ROLLBACK to put /p' <<<"$HOLDOUT107")"; POST2_107="$(sed -n '/Type ROLLBACK to put /,$p' <<<"$HOLDOUT107")"
check "$HOLDSAW107|$HOLDRC107|$(sd107 "$P2_107" a_marked)|$(rc107)|$(live)|$(has "$HOLDOUT107" "  $V107 (rollback): PASSED.")|$(( $(rbset107) - RS2_107 ))" "yes|0|1|0|$B107|yes|1" \
  "107-8Rf R2 held at Type ROLLBACK, a burst mark written there (the seed's own exit status 0); ROLLBACK typed: PASSED, $B107 serving, one record"
check "$(hasid107 "$PRE2_107" "the conversations " "$C2_107")|$(hasid107 "$POST2_107" "HAND THESE CONVERSATIONS TO STAFF ($BV107 CANNOT DRAIN THEM): " "$C2_107")|$(has "$PRE2_107" 'ai_burst_merge_enabled on; the last drain reading (a) 0,')" "no|yes|yes" \
  "107-8Rf' rb_pre107's readings, taken before the mark, do not list conversation $C2_107; rb107's drain after the put-back hands it to staff in capitals"
qcheck106 "107-8Rf q"   # the quarantine's --check after a rollback that copied (A14, S:13218–13239: every later deploy-mode run needs one), as 5.18.106's rehearsal does after every rollback
D94_8F_107="$(data94)"; fresh107; O8F_107="$(run107c --check)"
check "$(has "$O8F_107" 'NO-GO (NS-G1): ai_burst_merge_enabled in k')|$(has "$O8F_107" 'no value is read out')" "yes|yes" "107-8Rf\" a --check with $BV107 serving: NS-G1 NO-GO naming W.1 and its place, no value"
nogo107 "107-8Rf*" "NO-GO (NS-G1): " "$O8F_107" "$D94_8F_107" "$B107"
CL2_107="$(seed107 "$PD" '[{"op":"clear107","keys":["ai_burst_merge_enabled"]}]')"; BU2_107="$(seed107 "$PD" "[{\"op\":\"burst_undo\",\"ids\":[$(sd107 "$P2_107" a),$(sd107 "$P2_107" b)]}]")"
check "$(sd107 "$CL2_107" fixture)|$(sd107 "$CL2_107" places)|$(jv107 ai_burst_merge_enabled)|$(sd107 "$BU2_107" op)" "1|none|absent|burst_undo" \
  "107-8Rf** A13: a fixture clears W.1 from kyc_config.json and the store row (PluginConfig::saveOverrides, the known-limits route, said so); the two planted events removed"

# ── RD2 — a --redeploy with every 092 line removed before it (§T.11:3499): the redeploy note, PASSED, reset=1. Then Q on through the
# pin's set_config, as printed. ──
K1="$(seedk107 "$(pinroot107)" log_keep r8g)"; K2="$(seedk107 "$(pinroot107)" log_drop r8g '"match":"any092"')"
dredeploy107 "107-8Rg-RD2"
check "$(sd107 "$K2" fixture)|$(has "$DRD107" '  note  MG-CA2 no OK line for 092 in migration.log — a redeploy')|$(has "$DRD107" 'the tar straddle')" "1|yes|no" "107-8Rg' RD2: every 092 line gone — the redeploy note, no straddle note"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back r8g)" fixture)|$(kept107)" "1|0" "107-8Rg\" migration.log back"
OQ_107="$(run_line107c "" "$QON107")"
check "$(rc107)|$(has "$OQ_107" '  connection_quote_enabled = 1')|$(jv107 connection_quote_enabled)" '0|yes|"1"' "107-8Rg* Q on through $V107's set_config, as printed"

# ── R3's three copies, each in a PASSED --after-only with Q on — the cheapest mode reaching pb_para's K/Q lines (S:9121–9131), said
# so: the summary prints the paragraph with LK-AO's reading. The control first: the real --after-only prints Q's line alone. ──
master106 "107-8Rh0"
idle104; fresh107; AO8H_107="$(run107c --after-only)"
check "$(has "$AO8H_107" "connection_quote_enabled reads on (the run's latest reading of the ladder's keys): once $BV107's tools are installed none can write it. Its own command, alone, with its own log — it runs:")|$(has "$AO8H_107" "quoted_no_prices_enabled reads on (the run's latest reading")" "yes|no" \
  "107-8Rh0' the control: with Q on, the summary's put-back paragraph prints Q's line alone, worded from the installed set_config (it runs)"
anc107 kqoff 'q="$(rb_kq "$ref" "$k")"' 'q=off'
anc107 kline 'q="$(rb_kq "$ref" "$k")"' 'q="$(rb_kq "$ref" "$k")"; [ "$k" = quoted_no_prices_enabled ] && q=on'
anc107 oldwhy "(the run'\"'\"'s latest reading of the ladder'\"'\"'s keys): once %s'\"'\"'s tools are installed none can write it. Its own command" "(the run'\"'\"'s latest reading of the ladder'\"'\"'s keys)%.0s: after the put-back no shipped tool can write these keys. Its own command"
idle104; fresh107; mutant107 c8h-kqoff kqoff -- --after-only
check "$(has "$MUT107" "connection_quote_enabled reads on (the run's latest reading")" "no" "107-8Rh a weakened copy that drops the K/Q lines — caught"
idle104; fresh107; mutant107 c8h-kline kline -- --after-only
check "$(has "$MUT107" "quoted_no_prices_enabled reads on (the run's latest reading")" "yes" "107-8Rh' a weakened copy that prints K's line while K reads off — caught"
idle104; fresh107; mutant107 c8h-why oldwhy -- --after-only
check "$(has "$MUT107" 'after the put-back no shipped tool can write these keys')|$(has "$MUT107" "once $BV107's tools are installed none can write it")" "yes|no" "107-8Rh\" a weakened copy with the old reason — caught"

# ── R3 — H6 with Q on: a typed rollback on the off path whose record does not verify (5.18.106's tools installed). The stop's put-back
# paragraph: the tools reading, the switch-off and the drain worded as refused, the known-limits repair, Q's line alone. A fixture
# then clears Q. ──
: > "$SB/rbfail107.fired"; : > "$SB/.rbfail107"
RS3_107="$(rbset107)"; fresh107; O8I_107="$(run107c --answer ROLLBACK --rollback)"
check "$(rc107)|$(grep -c . "$SB/rbfail107.fired")|$( [ -e "$SB/.rbfail107" ] && echo unspent || echo spent)|$(has "$O8I_107" '  STOP: the rollback did not verify (live=')|$(has "$O8I_107" '  Nothing further was done. Send the log file.')|$(live)|$(installed107 "$B107F")|$(( $(rbset107) - RS3_107 ))" \
      "1|1|spent|yes|yes|$B107|0|1" \
  "107-8Ri R3 H6 armed: ROLLBACK typed, $BV107's files copied, the record read wrong once — the rollback did not verify; 'Nothing further was done'; $BV107's files installed; one rollback record"
check "$(has "$O8I_107" "lib/SalesFollowupLadder.php ${B107:0:7}'s, tools/sales_followup.php ${B107:0:7}'s, lib/BurstMerge.php the pin's, tools/set_config.php ${B107:0:7}'s.")|$(has "$O8I_107" '  The switch-off — it is refused (undeclared):')|$(has "$O8I_107" '  The drain reading — it is refused (an unknown step):')|$(has "$O8I_107" "Where the switch-off does not run, the repair is root docs/76 §T.10's known limit")" \
      "yes|yes|yes|yes" \
  "107-8Ri' the stop's put-back paragraph: the tools reading (the ladder, the tool and set_config $BV107's; BurstMerge the pin's); the switch-off refused (undeclared), the drain refused (an unknown step); the known-limits repair named"
check "$(has "$O8I_107" "connection_quote_enabled reads on (the run's latest reading of the ladder's keys): once $BV107's tools are installed none can write it. Its own command, alone, with its own log — it is refused (unmanaged):")|$(has "$O8I_107" "quoted_no_prices_enabled reads on (the run's latest reading")|$(grep -cxF "      $QOFF107" <<<"$O8I_107")" \
      "yes|no|2" \
  "107-8Ri\" Q's line alone, its reason 'once $BV107's tools are installed none can write it', its command worded as refused (unmanaged) — printed in rb_pre107's step 5 and again in the stop; no K line"
qcheck106 "107-8Ri q"   # H6's rollback copied (RB_COPY_AT written before the verify failed, S:13847–13852): the --check A14 asks for
CL3_107="$(seed107 "$PD" '[{"op":"clear107","keys":["connection_quote_enabled"]}]')"
check "$(sd107 "$CL3_107" fixture)|$(jv107 connection_quote_enabled)" "1|absent" "107-8Ri* A13: a fixture clears Q (the known-limits route, said so)"

# ── RD3, then K on as printed; R4 — H6 with K on: K's line alone. A fixture clears K. ──
dredeploy107 "107-8Rj-RD3"
OK4_107="$(run_line107c "" "$KON107")"
check "$(rc107)|$(has "$OK4_107" '  quoted_no_prices_enabled = 1')|$(jv107 quoted_no_prices_enabled)" '0|yes|"1"' "107-8Rj' K on through $V107's set_config, as printed"
: > "$SB/rbfail107.fired"; : > "$SB/.rbfail107"
fresh107; O8K_107="$(run107c --answer ROLLBACK --rollback)"
check "$(rc107)|$(grep -c . "$SB/rbfail107.fired")|$(has "$O8K_107" "quoted_no_prices_enabled reads on (the run's latest reading of the ladder's keys): once $BV107's tools are installed none can write it. Its own command, alone, with its own log — it is refused (unmanaged):")|$(has "$O8K_107" "connection_quote_enabled reads on (the run's latest reading")|$(live)" \
      "1|1|yes|no|$B107" \
  "107-8Rk R4 H6 with K on: the stop's paragraph prints K's line alone (refused, unmanaged), no Q line"
qcheck106 "107-8Rk q"
CL4_107="$(seed107 "$PD" '[{"op":"clear107","keys":["quoted_no_prices_enabled"]}]')"
check "$(sd107 "$CL4_107" fixture)|$(nsd107)" "1|absent|absent|absent" "107-8Rk' A13: a fixture clears K — no K, Q or W.1 key"

# ── RD4; R5 — H3, the clone's index lock: the rollback's checkout refused before anything is copied — 5.18.107's tools remain, every
# command worded as running, 'it leaves '0'' beside the switch-off. The lock removed; nothing changed. ──
dredeploy107 "107-8Rl-RD4"
: > "$REPO/.git/index.lock"; D94_8L_107="$(data94)"
fresh107; O8L_107="$(run107c --answer ROLLBACK --rollback)"
check "$(rc107)|$(has "$O8L_107" "  FAIL  git checkout $B107 failed — nothing was deployed")|$(has "$O8L_107" "  STOP: the rollback did not verify (live=$P107)")|$(has "$O8L_107" "lib/SalesFollowupLadder.php the pin's, tools/sales_followup.php the pin's, lib/BurstMerge.php the pin's, tools/set_config.php the pin's.")" \
      "1|yes|yes|yes" \
  "107-8Rl R5 H3 (the clone's index lock): the checkout refused, nothing deployed, the rollback did not verify — $V107's tools remain (all four the pin's)"
check "$(has "$O8L_107" "  The switch-off — it runs, and leaves '0':")|$(has "$O8L_107" '  The drain reading — it runs:')|$(has "$O8L_107" 'Where the switch-off does not run')|$(has "$O8L_107" ' A refusal changes nothing and saves nothing.')|$(unlock106)|$(live)|$(installed107 "$P107F")|$(data94)" \
      "yes|yes|no|no|absent|$P107|0|$D94_8L_107" \
  "107-8Rl' every command worded as running, 'it leaves '0'' beside the switch-off, no refusal sentence; the lock removed: $P107 serving, its files, the data as before"

# ── The put-back paragraph's reading (v3 item 10), in a PASSED --after-only's summary (the cheapest mode reaching pb_ready and
# pb_para sum): W.1 off — the switch off, (a) 0 and (b) 0 — then on. Then the one-block rule's control. Then W.1 off as printed. ──
master106 "107-8Rm0"
aft107 "107-8Rm" PASSED $L2ON107
check "$(has "$AFT107" ': switch off; the drain (a) 0, counted apart 0, (b) 0, control ')|$(has "$AFT107" 'One reading, not a drain.')" "yes|yes" "107-8Rm' the put-back paragraph, W.1 off: one reading — switch off, (a) 0 and (b) 0"
OW2_107="$(run_line107c "" "$W1ON107")"
idle104; fresh107; AO8M_107="$(run107c --after-only)"
check "$(has "$OW2_107" 'SET ai_burst_merge_enabled = 1')|$(has "$AO8M_107" ': switch on; the drain ')" "yes|yes" "107-8Rm\" W.1 on (as printed): the paragraph's reading says the switch is on"
anc107 blk1 '  echo "      $ROLLBACK_CMD"' "$(printf '  echo "      $ROLLBACK_CMD"\n  echo "      $REDEPLOY_CMD"')"
idle104; fresh107; mutant107 c8m-blk blk1 -- --after-only
check "$(q107 blocks107 "$R107/mutants/run-$MUTT107.txt" | grep -oE 'adjacent=[0-9]+' | cut -d= -f2 | awk '{ print ($1 >= 1) ? "caught" : "missed" }')" "caught" \
  "107-8Rm* the one-block rule's control (v2 A18): a copy that prints the redeploy command beside the rollback command reads adjacent — caught"
anc107 blk2 '  echo "      $ROLLBACK_CMD"' '  echo "      $ROLLBACK_CMD; $REDEPLOY_CMD"'
idle104; fresh107; mutant107 c8m-blk2 blk2 -- --after-only
check "$(q107 blocks107 "$R107/mutants/run-$MUTT107.txt" | grep -oE 'mixed=[0-9]+' | cut -d= -f2 | awk '{ print ($1 >= 1) ? "caught" : "missed" }')" "caught" \
  "107-8Rm*' the other half of the rule's control: a copy that prints the redeploy command on the rollback command's own line reads mixed — caught"
OO2_107="$(run_line107c "" "$BDOFF107")"
check "$(has "$OO2_107" 'SET ai_burst_merge_enabled = 0')|$(jv107 ai_burst_merge_enabled)" 'yes|"0"' "107-8Rm** W.1 off by the switch-off as printed under $V107: it leaves '0'"

# ── V1 through H5 — each a --redeploy whose V1 redirects (V1's automatic rollback, deploy mode only, S:13950). First §K's way out: a
# leftover mark planted, a typed rollback on the off path anyway; then a --redeploy ending in V1: 'this run wrote no mark' with
# stage A's count, the stop handing the conversation to staff. Then V1 with no mark and control 0: INDETERMINATE, and its copy.
# Then --rollback: RB_NOTHING. ──
v1run107() {   # v1run107 LABEL [SCRIPT] — H5 armed, then a --redeploy (or SCRIPT's): V1 FAIL, ROLLING BACK, exit 1, H5 spent, $B107 serving; V1RD107
  local scr="${2:-$DEPLOY107}"
  arm107; fresh107
  if [ -n "${2:-}" ]; then V1RD107="$(R107_MUTANT_OUT=1 run107c --script "$scr" --answer DEPLOY --redeploy)"   # a weakened copy's output kept apart (mutants/), out of 107-9g's sweep
  else V1RD107="$(run107c --script "$scr" --answer DEPLOY --redeploy)"; fi
  check "$(has "$V1RD107" 'V1 the sign-in page on the public address → 200 after 1 redirect(s)')|$(has "$V1RD107" '  ROLLING BACK — the public address must never redirect. Every switch kept; ai_burst_merge_enabled read off since stage A (NS-G1, LK-B1): this run wrote no mark')|$(has "$V1RD107" "  STOP: rolled back (serves $B107); RB107 above reads the put-back")|$(rc107)|$(spent99 .redirect107)|$(live)" \
        "yes|yes|yes|1|spent|$B107" \
    "$1 V1 through H5: the redeploy's sign-in page redirects once — V1 FAIL, ROLLING BACK (this run wrote no mark), the automatic rollback verified, exit 1, H5 spent, $B107 serving"
  qcheck106 "$1 q"   # V1's automatic rollback copied (RB_COPY_AT in the deploy's state file): the --check A14 asks for before any later deploy-mode run
}
M9_107="$(seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 08)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- the price\",\"instance\":\"sales-001\",\"mark\":\"aside\",\"fail\":1}]")"
C9_107="$(sd107 "$M9_107" conv)"
check "$(sd107 "$M9_107" a_marked)|$(sd107 "$M9_107" b_status)" "1|failed" "107-8Rn0 a leftover mark planted under $V107 (the seed's burst op, as the worker writes it): conversation $C9_107"
rbx107 "107-8Rn-RB"
check "$(has "$RBX107_OUT" 'ANSWER ANYTHING BUT ROLLBACK BELOW')|$(hasid107 "$RBX107_OUT" "the conversations " "$C9_107")|$(hasid107 "$RBX107_OUT" "HAND THESE CONVERSATIONS TO STAFF ($BV107 CANNOT DRAIN THEM): " "$C9_107")" "yes|yes|yes" \
  "107-8Rn' §K's way out: the drain does not hold (the capitals, its ids), the rollback typed anyway; after it the drain hands conversation $C9_107 to staff"
v1run107 "107-8Ro"
check "$(has "$V1RD107" "this run wrote no mark; stage A counted leftover marks — (a) 1, ")|$(has "$V1RD107" 'the drain after the rollback lists what still waits')|$(hasid107 "$V1RD107" "HAND THESE CONVERSATIONS TO STAFF ($BV107 CANNOT DRAIN THEM): " "$C9_107")|$(has "$V1RD107" '(V1'"'"'s automatic rollback: against the deploy'"'"'s LK-B1)')" \
      "yes|yes|yes|yes" \
  "107-8Ro' the V1 stop: stage A's count of leftover marks beside 'this run wrote no mark'; the stop hands conversation $C9_107 to staff; LK-RB1 against the deploy's LK-B1"
BU9_107="$(seed107 "$PD" "[{\"op\":\"burst_undo\",\"ids\":[$(sd107 "$M9_107" a),$(sd107 "$M9_107" b)]}]")"
check "$(sd107 "$BU9_107" op)" "burst_undo" "107-8Ro* the planted events removed"
# V1 with no mark and control above 0 (v3 item 9's "V1 with no mark", apart from control 0): an unmarked typed burst — two typed
# ai.reply events, no mark (the seed's burst op, mark none) — so the drain after the rollback reads, and lists no conversation
M9B_107="$(seed107 "$P107R" "[{\"op\":\"burst\",\"phone\":\"$(ph107 09)\",\"name\":\"RH107SECRET- a customer\",\"text\":\"RH107SECRET- hello\",\"text2\":\"RH107SECRET- again\",\"instance\":\"sales-001\",\"mark\":\"none\"}]")"
check "$(sd107 "$M9B_107" a_marked)|$(sd107 "$M9B_107" a_status)|$(sd107 "$M9B_107" b_status)" "0|pending|pending" "107-8Ro2-0 an unmarked typed burst planted (two typed events, no mark)"
v1run107 "107-8Ro2-V1"
check "$(has "$V1RD107" 'stage A counted leftover marks')|$(has "$V1RD107" "the pin's drain after the rollback, on a copy of the book: (a) 0, counted apart 0, (b) 0, control ")|$(has "$V1RD107" '— it lists no conversation')|$(has "$V1RD107" "THE PIN'S DRAIN AFTER THE ROLLBACK IS INDETERMINATE")" "no|yes|yes|no" \
  "107-8Ro2 V1 with no mark and typed messages in the last day: no count beside 'this run wrote no mark'; the stop reads the drain — (a) 0 and (b) 0 with its control — and lists no conversation"
BU9B_107="$(seed107 "$PD" "[{\"op\":\"burst_undo\",\"ids\":[$(sd107 "$M9B_107" a),$(sd107 "$M9B_107" b)]}]")"
check "$(sd107 "$BU9B_107" op)" "burst_undo" "107-8Ro2* the unmarked events removed"
clkfwd107 $((2 * 86400 + 600))   # no typed ai.reply in the last day, whatever the book holds: control 0 (said so)
v1run107 "107-8Rp"
check "$(has "$V1RD107" 'stage A counted leftover marks')|$(has "$V1RD107" "THE PIN'S DRAIN AFTER THE ROLLBACK IS INDETERMINATE — NO TYPED MESSAGE IN THE LAST DAY ((a) 0, counted apart 0, (b) 0, control 0)")" "no|yes" \
  "107-8Rp' V1 with no mark and control 0 (the clock two days on, said so): no count beside 'this run wrote no mark'; the stop says INDETERMINATE — never 'nothing to drain'"
anc107 ctl0 'if [ "$(kv "$1" control)" = "0" ]; then printf "THE PIN' 'if false; then printf "THE PIN'
WK_107="$(weak107 v1-ctl0 ctl0)"; v1run107 "107-8Rq" "$WK_107"; rm -f "$WK_107"
check "$(has "$V1RD107" "THE PIN'S DRAIN AFTER THE ROLLBACK IS INDETERMINATE")|$(has "$V1RD107" "the pin's drain after the rollback, on a copy of the book: (a) 0, counted apart 0, (b) 0, control 0 — it lists no conversation")" "no|yes" \
  "107-8Rq' a weakened copy that reads control 0 as a reading — 'it lists no conversation' — caught (a V1 run of its own)"
RS9_107="$(rbset107)"; fresh107; O9R_107="$(run107c --rollback)"
check "$(rc107)|$(has "$O9R_107" 'nothing to roll back; running the rollback checks')|$(has "$O9R_107" 'LK-RB1 not judged: this run rolled nothing back')|$(rbset107)" "0|yes|yes|$RS9_107" "107-8Rr rb107 and RB_NOTHING after V1's rollback: nothing to roll back, LK-RB1 not judged, no record"

# ════════════════════════════════════════════════════════════════════════════
# 107-9 — the final step: --redeploy (NS-G1 GO with W.1 '0' — the switch-off's own '0', left by 107-8Rm** under 5.18.107 and kept by
# 107-8Rn's rollback), master106, --after-only PASSED with the owner's steps; steps 1 and 3 as printed; the drain through the
# wrapper; the put-back's and step 5's set_config --value 0 lines; K and W.1 off as printed; the one-block rule over every run.
# Exit: 5.18.107 serving, K, Q and W.1 off.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-9. the final redeploy (NS-G1 GO with '0'), the PASSED --after-only and the owner's steps run as printed; the one-block rule =="
check "$(jv107 ai_burst_merge_enabled)|$(live)" '"0"|'"$B107" "107-9-0 W.1 reads '0' in kyc_config.json (the switch-off's own value, kept by the rollbacks since), $BV107 serving"
dredeploy107 "107-9a"
master106 "107-9b0"
aft107 "107-9b" PASSED $L2ON107
K9ON_107="$(printed107 "$AFT107" '--key quoted_no_prices_enabled --value 1')"; Q9ON_107="$(printed107 "$AFT107" '--key connection_quote_enabled --value 1')"; W9ON_107="$(printed107 "$AFT107" 'set ai_burst_merge_enabled 1')"
check "$(has "$AFT107" "  the owner's steps root docs/76 §K — $V107 serves and this --after-only PASSED")|$( [ "$K9ON_107" = "$KON107" ] && echo K)|$( [ "$Q9ON_107" = "$QON107" ] && echo Q)|$( [ "$W9ON_107" = "$W1ON107" ] && echo W)" "yes|K|Q|W" \
  "107-9b' the owner's steps printed, each command alone: K's, Q's and W.1's, as 107-7z's run printed them"
OS1_107="$(run_line107c "" "$K9ON_107")"; OS3_107="$(run_line107c "" "$W9ON_107")"
check "$(has "$OS1_107" '  quoted_no_prices_enabled = 1')|$(has "$OS3_107" 'SET ai_burst_merge_enabled = 1')|$(nsd107)" 'yes|yes|"1"|absent|"1"' "107-9c steps 1 and 3 run as printed, each alone with its own log: K on, W.1 on"
DR9_107="$(printed107 "$AFT107" '--check burst-drain')"; OD9_107="$(run_line107c "" "$DR9_107")"
check "$DR9_107|$(has "$OD9_107" 'burst-drain: (a) 0 mark(s) naming an event that has not run yet')|$(has "$OD9_107" 'burst-drain: control: ')" "$BDDR107|yes|yes" "107-9d the drain through the wrapper, as printed: it reads (a) 0, with its control line"
idle104; fresh107; AO9_107="$(run107c --after-only)"; KOFFP_107="$(printed107 "$AO9_107" '--key quoted_no_prices_enabled --value 0')"
rbno107
KOFFS_107="$(printed107 "$RBNO107" '--key quoted_no_prices_enabled --value 0')"
check "$( [ -n "$KOFFP_107" ] && [ "$KOFFP_107" = "$KOFF107" ] && echo putback)|$( [ "$KOFFS_107" = "$KOFF107" ] && echo step5)|$(has "$RBNO107" "quoted_no_prices_enabled READS ON (NS-G1'S RULE, ON LK-RB0)")" "putback|step5|yes" \
  "107-9e K's off line, printed by the put-back paragraph (an --after-only with K on) and by step 5 (a rollback answered no, W.1 on: no wait) — the same command both times"
rbq107 "107-9e'"
OK9_107="$(run_line107c "" "$KOFFP_107")"; OW9_107="$(run_line107c "" "$BDOFF107")"
check "$(has "$OK9_107" '  quoted_no_prices_enabled = 0')|$(has "$OW9_107" 'SET ai_burst_merge_enabled = 0')|$(nsd107)" 'yes|yes|"0"|absent|"0"' "107-9f K and W.1 off again by their printed off lines"
BL9_107="$(q107 blocks107 "$R107"/run-*.txt)"
check "$(kv107 "$BL9_107" mixed)|$(kv107 "$BL9_107" adjacent)|$( [ "$(n103 "$(kv107 "$BL9_107" cmds)")" -gt 0 ] && echo read)" "0|0|read" \
  "107-9g the one-block rule (v2 A18) over every run's output ($(kv107 "$BL9_107" files) files, $(kv107 "$BL9_107" cmds) command lines): no rollback command beside another, no two of the five without a prose line between"
master106 "107-9z0"
aft107 "107-9z" PASSED $L2ON107
check "$(live)|$(nsd107)|$(kept107)|$(LEFT107)" "$P107|\"0\"|absent|\"0\"|0|none" "107-9zz the exit: $P107 serving, K '0', Q absent, W.1 '0' — all three off; nothing kept, no trigger armed"
