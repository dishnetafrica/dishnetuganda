# ════════════════════════════════════════════════════════════════════════════
# 107-pre — production's state before 5.18.107 (v2 A15, v3), through the real writers
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-pre. production's state before the deploy: $V106 serving, the chase on in claim mode, its --after-only PASSED, the quarantine's --check PASSED; the settings text planted; A6's values read again =="
check "$(live)|$(inst_ver)|$(ledger107)" "$P106|$V106|mig092=0 wa_no_ai=none" \
  "107-pre0 what 106-7z handed over: $P106 ($V106) serving, and the book holds neither 092 nor wa_no_ai (this rehearsal's own reading of the ledger and the tables)"
OPRE107="$(sf104c --tty yes on chase)"
# bf29cbd tools/sales_followup.php (the question, and "ON <step>: <keys>")
check "$(sfrc104)|$(has "$OPRE107" 'Type yes to switch the quote chase on:')|$(grep -c '^ON chase: ' <<<"$OPRE107")|$(srtcsv106 "$(kv105 "$(lk105)" on)")|$(kv105 "$(lk105)" held)" \
      "0|yes|1|ack,chase,list,report,tracking,voice-measure|0" \
  "107-pre1 on chase through the installed tool, yes typed at the terminal (§F step 2, as the owner took it on 22 Nov): ON, exit 0; steps 1–6 on by this rehearsal's ladder reading, nothing held — sends in claim mode"
master106 "107-pre2"
aft106 "107-pre3" PASSED $L2ON107
qcheck106 "107-pre4"
SETTXT107="$(seed107 "$PD" '[{"op":"settext","text":"RH107SECRET- the rehearsal'"'"'s settings text: stay on the plugin'"'"'s own subjects."}]')"
check "$(sd107 "$SETTXT107" op)|$(sd107 "$SETTXT107" fixture)|$(sd107 "$SETTXT107" places)" "settext|1|k,s" \
  "107-pre5 the settings text: an RH107SECRET- text written by a fixture through the release's own PluginConfig::saveOverrides (said so: v9's bytes are production's and are never planted, v2 A3) — in kyc_config.json and the store row"
# A2 — A6's gated values, read again in the sandbox: one --check, GO, its four lines parsed and set against the script's literals
# (M0 measured them first, outside the rehearsal; the derivation records M0's).
fresh107; OA2_107="$(run107c --check)"
A2UG107="$(sed -n 's/^  Uganda          live [0-9.]*: //p' <<<"$OA2_107" | head -1)|$(sed -n '/^  Uganda          live /{n;s/^                  pin  [0-9.]*: //p;}' <<<"$OA2_107" | head -1)"
A2NA107="$(sed -n 's/^  no-AI alert     live [0-9.]*: \(.*\) (Uganda), \(.*\) (South Sudan)$/\1|\2/p' <<<"$OA2_107" | head -1)|$(sed -n '/^  no-AI alert     live /{n;s/^                  pin  [0-9.]*: \(.*\) (Uganda), \(.*\) (South Sudan)$/\1|\2/p;}' <<<"$OA2_107" | head -1)"
check "$(checkgo107 "$OA2_107")|$A2UG107|$A2NA107|$(has "$OA2_107" '  ok    A6 South Sudan stays exactly as it is')" \
      "yesnono0|0|0|samekey|0|$(sv107 "" UG_SIGNATURE)|$(sv107 "" UG_PIN_SIGNATURE)|$(sv107 "" EP_NOAI_DONE)|$(sv107 "" EP_NOAI_DONE)|$(sv107 "" EP_NOAI_PIN_UG)|$(sv107 "" EP_NOAI_DONE)|yes" \
  "107-pre6 A2: a --check under the clock, GO with nothing changed; A6's lines read the pin's five-event Uganda run as UG_PIN_SIGNATURE, the live one as UG_SIGNATURE, the assistant-off alert done as unknown on 5.18.106 (both profiles) and on the pin as South Sudan, and left pending by the pin as Uganda — the values M0 measured (root docs/76 §T.15)"
echo "  note  RH-NOTE A2 (for the derivation's record): in this sandbox A6 read UG_PIN=$(cut -d'|' -f2 <<<"$A2UG107"); no-AI alert live $(cut -d'|' -f1-2 <<<"$A2NA107" | tr '|' ' '), pin $(cut -d'|' -f3-4 <<<"$A2NA107" | tr '|' ' ')"
snap107
check "$(snapeq107)|$(grep -c . "$SNAP107/paths")|$( [ -d "$R104/dsnap/pre107/data" ] && echo snap)" "same|4|snap" \
  "107-pre7 the snapshot every first-deploy reset restores (v3 A9): the book and the data folder by 104's dsnap104, migration.log and the three settings files by sha256"

# ════════════════════════════════════════════════════════════════════════════
# 107-1 — a plain 5.18.106 tree, and the mixed trees, at BASE_DIFFERS (S:12399): the tools reading and the put-back paragraph
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-1. BASE_DIFFERS and the put-back paragraph's tools reading, each tree planted and put back (root docs/76 §T.11; v2 A11 S5, S6) =="
# Each case is a --check (MODE deploy, CHECK_ONLY): BASE_DIFFERS stops it in stage A before A6, before the pin is staged and
# before any question, nothing changed. Every tree is planted from Git (the pin's file, or bf29cbd's) or by a one-byte edit,
# said so, kept first and put back after, checked put back by sha256.
BW107="${B107:0:7}'s"
TOOLS107="lib/SalesFollowupLadder.php tools/sales_followup.php lib/BurstMerge.php tools/set_config.php"
pinf107() { git -C "$REPO" show "$P107F:$P/$1" > "$PD/$1" && chown 1000:1000 "$PD/$1"; }   # pinf107 REL — the pin's file installed over 5.18.106's
edit107() { printf '\n// RH107SECRET- an edit by hand\n' >> "$PD/$1"; }                         # edit107 REL — one line appended: neither the pin's nor bf29cbd's
keepall107() { local f; for f in "$@"; do keep106 "t107-$(tag106 "$f")" "$PD/$f" >/dev/null; done; }
backall107() { local f out=""; for f in "$@"; do out="$out$(back106 "t107-$(tag106 "$f")")"; done; echo "$out"; }   # sames, one per file
bd107() {   # bd107 LABEL OUTPUT — the BASE_DIFFERS stop: its text, Nothing was changed, exit 1, never the question, nothing changed
  check "$(has "$2" "  STOP: NO-GO: the container's record says $B107 ($BV107), but these installed files are not $BV107's:")$(has "$2" 'Nothing was changed.')$(went107 "$2")|$(rc107)|$(quiet107)" \
        "yesyesnono|1|$P106|0|0|samekey|$D0_107" "$1 the --check stopped at BASE_DIFFERS (S:12399): NO-GO naming the files, Nothing was changed, exit 1, never the question; nothing changed"
}
pbt107() { echo "lib/SalesFollowupLadder.php $1, tools/sales_followup.php $2, lib/BurstMerge.php $3, tools/set_config.php $4."; }   # the tools reading as pb_para says it
D0_107="$(data94)"
# 1a — the plain 5.18.106 tree: one changed file outside the tools edited (lib/DishNetAiBrain.php, in $V107's delta and bf29cbd's tree)
check "$(grep -cw 'dishnet-hybrid-sudan/lib/DishNetAiBrain.php' <<<"$CHANGED107")|$(git -C "$REPO" cat-file -e "$B107F:$P/lib/DishNetAiBrain.php" && echo base)" "1|base" "107-1a0 lib/DishNetAiBrain.php is one of the files $V107 changes, and bf29cbd has it"
keepall107 lib/DishNetAiBrain.php; edit107 lib/DishNetAiBrain.php
fresh107; O1A107="$(run107c --check)"; bd107 "107-1a1" "$O1A107"
check "$(has "$O1A107" "$(pbt107 "$BW107" "$BW107" absent "$BW107")")|$(has "$O1A107" '  The switch-off — it is refused (undeclared):')|$(has "$O1A107" '  The drain reading — it is refused (an unknown step):')|$(has "$O1A107" ' A refusal changes nothing and saves nothing.')|$(grep -cF "it leaves '0'" <<<"$O1A107")|$(grep -c '^  quoted_no_prices_enabled cannot be read (the run.s latest reading of the ladder.s keys): .* it is refused (unmanaged):$' <<<"$O1A107")|$(grep -c '^  connection_quote_enabled cannot be read (the run.s latest reading of the ladder.s keys): .* it is refused (unmanaged):$' <<<"$O1A107")|$(has "$O1A107" 'switch NOT READ (the pin is not yet staged)')|$(has "$O1A107" '(Stage A refused before the pin was staged:')" \
      "yes|yes|yes|yes|0|1|1|yes|yes" \
  "107-1a2 the plain $BV107 tree: the tools reading names the ladder, the tool and set_config $BW107 and BurstMerge absent; the switch-off refused (undeclared), the drain refused (an unknown step), a refusal changes nothing and saves nothing, it leaves '0' never said; K and Q cannot be read, each line refused (unmanaged); NOT READ, the pin not yet staged"
check "$(backall107 lib/DishNetAiBrain.php)|$(installed107 "$B107F")" "same|0" "107-1a3 the edited file put back, by sha256: $BV107's tree whole again"
# 1b — only lib/SalesFollowupLadder.php the pin's
keepall107 lib/SalesFollowupLadder.php; pinf107 lib/SalesFollowupLadder.php
fresh107; O1B107="$(run107c --check)"; bd107 "107-1b1" "$O1B107"
check "$(has "$O1B107" "$(pbt107 "the pin's" "$BW107" absent "$BW107")")|$(has "$O1B107" "  The switch-off — it runs, and leaves '0':")|$(has "$O1B107" 'Where the switch-off does not run')|$(has "$O1B107" '  The drain reading — it is refused (an unknown step):')|$(has "$O1B107" ' A refusal changes nothing and saves nothing.')|$(grep -c ' it is refused (unmanaged):$' <<<"$O1B107")" \
      "yes|yes|no|yes|yes|2" \
  "107-1b2 only the ladder the pin's: the switch-off worded as running, leaving '0', with no repair sentence beside it; the drain refused; the refusal sentence on §K's rule line because the drain is refused; K and Q refused (unmanaged)"
check "$(backall107 lib/SalesFollowupLadder.php)|$(installed107 "$B107F")" "same|0" "107-1b3 put back"
# 1c — tools/set_config.php the pin's, the ladder and the tool bf29cbd's; and its two weakened copies
keepall107 tools/set_config.php; pinf107 tools/set_config.php
fresh107; O1C107="$(run107c --check)"; bd107 "107-1c1" "$O1C107"
check "$(has "$O1C107" "$(pbt107 "$BW107" "$BW107" absent "the pin's")")|$(grep -c ' it runs:$' <<<"$O1C107")|$(grep -c 'take nothing as saved\. A refusal changes nothing' <<<"$O1C107")|$(has "$O1C107" '  The switch-off — it is refused (undeclared):')|$(has "$O1C107" '  The drain reading — it is refused (an unknown step):')" \
      "yes|2|0|yes|yes" \
  "107-1c2 set_config the pin's, the ladder and the tool $BW107: the K and Q lines worded runs, with no refusal sentence after them; the switch-off and the drain refused"
anc107 pb2way 'case "$s" in pin) ws="runs"' 'case "$l$s" in pinpin) ws="runs"'                       # a two-way mixed wording: set_config runs only with the ladder
anc107 pbkqlad '  case "$s" in pin) ws="runs" ;; base) ws="is refused (unmanaged)" ;; *) ws="may be refused" ;; esac' '  case "$l" in pin) ws="runs" ;; base) ws="is refused (unmanaged)" ;; *) ws="may be refused" ;; esac'   # K/Q worded from the ladder
mutant107 w107-1c-2way pb2way -- --check; C1C2W107="$(grep -c ' it runs:$' <<<"$MUT107")"
mutant107 w107-1c-kqlad pbkqlad -- --check; C1CKL107="$(grep -c ' it runs:$' <<<"$MUT107")|$(grep -c ' it is refused (unmanaged):$' <<<"$MUT107")"
check "$C1C2W107|$C1CKL107" "0|0|2" "107-1c3 its weakened copies caught: one keeping a two-way mixed wording (set_config runs only beside the pin's ladder) words no K/Q line runs; one wording the K/Q lines from the ladder's reading words both refused (unmanaged)"
check "$(backall107 tools/set_config.php)|$(installed107 "$B107F")" "same|0" "107-1c4 put back"
# 1d — tools/set_config.php edited by hand (neither): may be refused, never runs and never is refused; two copies
keepall107 tools/set_config.php; edit107 tools/set_config.php
fresh107; O1D107="$(run107c --check)"; bd107 "107-1d1" "$O1D107"
check "$(has "$O1D107" "$(pbt107 "$BW107" "$BW107" absent 'neither (an edit by hand)')")|$(grep -c '(the run.s latest reading of the ladder.s keys): .* it may be refused:$' <<<"$O1D107")|$(grep -c '(the run.s latest reading of the ladder.s keys): .* it runs:$' <<<"$O1D107")|$(grep -c '(the run.s latest reading of the ladder.s keys): .* it is refused (unmanaged):$' <<<"$O1D107")" \
      "yes|2|0|0" "107-1d2 set_config edited by hand: it reads neither, and the K and Q lines are worded may be refused — never runs, never is refused"
anc107 pbnbase 'base) ws="is refused (unmanaged)"' 'base|neither) ws="is refused (unmanaged)"'
anc107 pbnpin 'case "$s" in pin) ws="runs"' 'case "$s" in pin|neither) ws="runs"'
mutant107 w107-1d-nbase pbnbase -- --check; C1DNB107="$(grep -c ' it is refused (unmanaged):$' <<<"$MUT107")"
mutant107 w107-1d-npin pbnpin -- --check; C1DNP107="$(grep -c ' it runs:$' <<<"$MUT107")"
check "$C1DNB107|$C1DNP107" "2|2" "107-1d3 its weakened copies caught: one folding neither into $BW107 words both lines refused (unmanaged); one folding it into the pin's words both runs"
check "$(backall107 tools/set_config.php)|$(installed107 "$B107F")" "same|0" "107-1d4 put back"
# 1e — the three files present at bf29cbd removed: the tools reading not made (v2 A11 S6: three not read, BurstMerge absent)
keepall107 lib/SalesFollowupLadder.php tools/sales_followup.php tools/set_config.php; rm -f "$PD/lib/SalesFollowupLadder.php" "$PD/tools/sales_followup.php" "$PD/tools/set_config.php"
fresh107; O1E107="$(run107c --check)"; bd107 "107-1e1" "$O1E107"
check "$(has "$O1E107" "$(pbt107 'not read' 'not read' absent 'not read')")|$(has "$O1E107" '  The switch-off — it may be refused:')|$(has "$O1E107" '  The drain reading — it may be refused:')|$(grep -cF "it leaves '0'" <<<"$O1E107")|$(grep -c ' it may be refused:$' <<<"$O1E107")|$(grep -c "^      cd .* && bash scripts/sales-followup.sh " <<<"$O1E107")" \
      "yes|yes|yes|0|4|2" \
  "107-1e2 the three files bf29cbd has removed: the ladder, the tool and set_config not read, BurstMerge absent; every command, the K and Q lines included, worded may be refused; it leaves '0' never said; both §K commands still printed, each alone"
check "$(backall107 lib/SalesFollowupLadder.php tools/sales_followup.php tools/set_config.php)|$(installed107 "$B107F")" "samesamesame|0" "107-1e3 put back"
# 1f — the ladder the pin's and tools/sales_followup.php removed: the switch-off may be refused, never it leaves '0'; the copy keying it on the ladder alone
keepall107 lib/SalesFollowupLadder.php tools/sales_followup.php; pinf107 lib/SalesFollowupLadder.php; rm -f "$PD/tools/sales_followup.php"
fresh107; O1F107="$(run107c --check)"; bd107 "107-1f1" "$O1F107"
check "$(has "$O1F107" "$(pbt107 "the pin's" 'not read' absent "$BW107")")|$(has "$O1F107" '  The switch-off — it may be refused:')|$(grep -cF "it leaves '0'" <<<"$O1F107")|$(has "$O1F107" ' A refusal changes nothing and saves nothing.')|$(has "$O1F107" " Where the switch-off does not run, the repair is root docs/76 §T.10's known limit")|$(grep -c "^      cd .* && bash scripts/sales-followup.sh " <<<"$O1F107")" \
      "yes|yes|0|yes|yes|2" \
  "107-1f2 the ladder the pin's and the tool removed (not read): the switch-off may be refused, never it leaves '0'; the refusal sentence and the known-limits repair printed; both §K commands, each alone"
anc107 pbladonly 'case "$l:$t" in pin:pin|pin:base)' 'case "$l:$t" in pin:*)'
mutant107 w107-1f-lad pbladonly -- --check
check "$(has "$MUT107" "  The switch-off — it runs, and leaves '0':")" "yes" "107-1f3 its weakened copy caught: keying the switch-off on the ladder alone says it runs and leaves '0'"
check "$(backall107 lib/SalesFollowupLadder.php tools/sales_followup.php)|$(installed107 "$B107F")" "samesame|0" "107-1f4 put back"
# 1g — the pin's tool with lib/BurstMerge.php absent: burst-drain fails without reading anything, never runs
keepall107 tools/sales_followup.php; pinf107 tools/sales_followup.php
fresh107; O1G107="$(run107c --check)"; bd107 "107-1g1" "$O1G107"
check "$(has "$O1G107" "$(pbt107 "$BW107" "the pin's" absent "$BW107")")|$(has "$O1G107" '  The drain reading — it fails without reading anything:')|$(grep -c '  The drain reading — it runs:' <<<"$O1G107")" "yes|yes|0" \
  "107-1g2 the pin's tool with BurstMerge absent: the drain worded as failing without reading anything, never as running"
# 1h — and BurstMerge edited by hand (one byte): neither; may fail, never runs, never fails without reading anything; two copies
keep106 t107-burst "$PD/lib/BurstMerge.php" >/dev/null; git -C "$REPO" show "$P107F:$P/lib/BurstMerge.php" > "$PD/lib/BurstMerge.php"; chown 1000:1000 "$PD/lib/BurstMerge.php"; edit107 lib/BurstMerge.php
fresh107; O1H107="$(run107c --check)"; bd107 "107-1h1" "$O1H107"
check "$(has "$O1H107" "$(pbt107 "$BW107" "the pin's" 'neither (an edit by hand)' "$BW107")")|$(has "$O1H107" "  The drain reading — it may fail (it may also run on bytes that are not the pin's):")|$(grep -c '  The drain reading — it runs:' <<<"$O1H107")|$(grep -c '  The drain reading — it fails without reading anything:' <<<"$O1H107")" "yes|yes|0|0" \
  "107-1h2 the pin's tool with BurstMerge edited by hand: it reads neither, and the drain is worded may fail — never runs, never fails without reading anything"
anc107 pbbabs 'pin:absent) wd="fails without reading anything"' 'pin:absent|pin:neither) wd="fails without reading anything"'
anc107 pbbpin 'case "$t:$b" in pin:pin) wd="runs"' 'case "$t:$b" in pin:pin|pin:neither) wd="runs"'
mutant107 w107-1h-abs pbbabs -- --check; C1HA107="$(has "$MUT107" '  The drain reading — it fails without reading anything:')"
mutant107 w107-1h-pin pbbpin -- --check; C1HP107="$(has "$MUT107" '  The drain reading — it runs:')"
check "$C1HA107|$C1HP107" "yes|yes" "107-1h3 its weakened copies caught: keying fails-without-reading on not-the-pin's, and treating neither as the pin's"
check "$(back106 t107-burst)|$(backall107 tools/sales_followup.php)|$(installed107 "$B107F")|$( [ -e "$PD/lib/BurstMerge.php" ] && echo present || echo absent)" "same|same|0|absent" \
  "107-1h4 put back: the tool $BW107's, BurstMerge absent again (as $BV107's tree has it)"
# 1i — a stage-A mixed-tree refusal: the print-time reading NOT READ (the pin is not yet staged), K and Q cannot be read with their
# lines, the wording from the tools reading, no unbound-variable error under set -u; three copies
keepall107 lib/DishNetAiBrain.php; edit107 lib/DishNetAiBrain.php
fresh107; O1I107="$(run107c --check)"; bd107 "107-1i1" "$O1I107"
check "$(has "$O1I107" 'Read at ')$(has "$O1I107" ': switch NOT READ (the pin is not yet staged); the drain not read (the pin is not yet staged). One reading, not a drain.')|$(grep -c 'unbound variable' <<<"$O1I107")|$(grep -c '^  quoted_no_prices_enabled cannot be read\|^  connection_quote_enabled cannot be read' <<<"$O1I107")" "yesyes|0|2" \
  "107-1i2 the stage-A mixed-tree refusal: the reading NOT READ (the pin is not yet staged), one reading and not a drain; K and Q cannot be read, their lines printed; no unbound-variable error"
anc107 p3bunset 'P3B_PIN=""; P3B_WHY="the pin is not yet staged"   #' ': "the pin is not yet staged"   #'
anc107 pbnotread '  if [ -z "${P3B_PIN:-}" ]; then sw="NOT READ (the pin is not yet staged)"; bd="bd=unread(the pin is not yet staged)"; at="$(date -u +%s)"' '  if [ -z "${P3B_PIN:-}" ]; then sw="off"; bd="bd=unread(the pin is not yet staged)"; at="$(date -u +%s)"'
anc107 pbnokq '    [ "$q" = "off" ] && continue
    kq=' '    continue
    kq='
mutant107 w107-1i-unset p3bunset -- --check; C1IU107="$(grep -c 'unbound variable' <<<"$MUT107")"
mutant107 w107-1i-read pbnotread -- --check; C1IR107="$(has "$MUT107" ': switch NOT READ (the pin is not yet staged);')"
mutant107 w107-1i-nokq pbnokq -- --check; C1IK107="$(grep -c '^  quoted_no_prices_enabled cannot be read\|^  connection_quote_enabled cannot be read' <<<"$MUT107")"
check "$( [ "$(n103 "$C1IU107")" -ge 1 ] && echo unbound)|$C1IR107|$C1IK107" "unbound|no|0" \
  "107-1i3 its weakened copies caught: P3B_PIN and P3B_WHY left undefined before stage A's sites (the unbound-variable error under set -u); a reading other than NOT READ; the K/Q lines dropped"
check "$(backall107 lib/DishNetAiBrain.php)|$(installed107 "$B107F")|$(ancc107)" "same|0|0|$(ls "$AN107"/*.a | wc -l | tr -d ' ')|-" \
  "107-1z put back; $BV107's tree whole; every anchor of 107-1's weakened copies the script's own text, found once, its replacement different (the control of the controls)"
