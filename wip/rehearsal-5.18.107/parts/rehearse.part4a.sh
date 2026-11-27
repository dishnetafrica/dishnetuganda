# ════════════════════════════════════════════════════════════════════════════
# D1 — the first deploy, PASSED, and everything judged after it on the same deploy (v3 §B D1: 107-4, 107-6 seen=1)
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-4. D1: $V107 over $BV106 — the first deploy, PASSED: QF-G2's trial and NA-G1, neg092, MG-CA1, NA-CA1, MG-CA2, R7 applied, LK-CA2 one row more, the state lines =="
tidy107 "107-4-pre"
P107R="$PD"   # the seed's code root once 5.18.107 serves: the installed release's own classes (rh107-seed.php never boots the store)
BDOFF107="cd $REPO && bash scripts/sales-followup.sh set ai_burst_merge_enabled 0"; BDDR107="cd $REPO && bash scripts/sales-followup.sh --check burst-drain"   # the script's BD_OFF_CMD and BD_DRAIN_CMD as printed (S:7341-7342)
check "$(sv107 "" QF_WRAPPER)|$(grep -cF 'BD_OFF_CMD="cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0"' "$DEPLOY107")|$(grep -cF 'BD_DRAIN_CMD="cd $REPO && bash $QF_WRAPPER --check burst-drain"' "$DEPLOY107")" "scripts/sales-followup.sh|1|1" \
  "107-4-0 the two §K commands as the script assigns them, over its own wrapper (read from the script, never typed)"
check "$(states107)|$(backups107)|$(keysum107)|$(left107)|$(inst_ver)|$(ledger107)|$(kept107)" "0|0|nokey|0|$V106|mig092=0 wa_no_ai=none|0" \
  "107-4a a first deploy: no state file, rollback record, backup or LK key of $V107's in its run folder; none of the $NA107 files it adds on disk; $V106 installed; no 092 and no wa_no_ai; nothing kept"
fresh107; O4_107="$(run107c --answer DEPLOY)"; T4_107="$(last107)"
check "$(fails "$O4_107")$(has "$O4_107" "  $V107 (deploy): PASSED.")|$(rc107)|$(went107 "$O4_107")|$(live)|$(installed107 "$P107F")|$(ledger107)|$(cat "$R107/evo/run-$T4_107.n" 2>/dev/null)" \
      "0yes|0|yesyes|$P107|0|mig092=1 wa_no_ai=table|0 nocron" \
  "107-4b D1, DEPLOY typed, under the clock: PASSED (exit 0), $P107 serving, every file it changes installed as it has them, 092 applied by the first boot (V1's request to the stand-in) and wa_no_ai made; Evolution not called"
check "$(has "$O4_107" "  ok    MG-G1 the copy's migration ledger has no 092")|$(has "$O4_107" 'extra=092_wa_no_ai.sql:ok:OK:7')|$(has "$O4_107" '  wa_no_ai (NA-G1) p1=none ')|$(has "$O4_107" ' p3=PDOException gate=')|$(has "$O4_107" '  negative control (092) reset=0 status=partial partial=1 check=1 row=0')|$(has "$O4_107" 'applied, ok, its own migration.log OK with its 7 statements')" \
      "yes|yes|yes|yes|yes|yes" \
  "107-4c stage A: MG-G1 absent; QF-G2's trial applies 092 (ok, OK, 7 statements); NA-G1's probes, probe 3 throwing at the read (§T.16 S-1); 092's negative control reset=0 with CHECK named (§T.16 S-2); QF-G2 ok"
check "$(has "$O4_107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(has "$O4_107" '  ok    NA-CA1 wa_no_ai reads the way the worker reads it')|$(has "$O4_107" "  ok    MG-CA2 migration.log holds 092's OK line (1) and no PARTIAL line for it")|$(has "$O4_107" 'moved by exactly 092, applied at a boot')|$(has "$O4_107" 'but one added, exactly 092_wa_no_ai.sql:5/5')|$(has "$O4_107" 'the deploy run makes no poll')" \
      "yes|yes|yes|yes|yes|yes" \
  "107-4d after the copy: MG-CA1, NA-CA1 and MG-CA2 ok; R7 reads the ledger moved by exactly 092, applied at a boot; LK-CA2 every _schema_checks row unchanged but one added, exactly 092's 5/5; ME-CA1 a note in the deploy run (no poll)"
AT4_107="$(kv107 "$(ledgerx107)" at092)"; CU4_107="$(sget107 "$STATE107" QF_COPY_UNIX)"
check "$(sget107 "$STATE107" MG_G1)|$(sget107 "$STATE107" MG_CA2_SEEN)|$(case "$CU4_107" in [0-9]*) echo read ;; *) echo "unread:$CU4_107" ;; esac)|$( [ "$(n103 "$AT4_107")" -ge "$(n103 "$CU4_107")" ] && echo after)|$(has "$O4_107" '092 was applied before this copy began')" \
      "absent|1|read|after|no" \
  "107-4e the state file: MG_G1=absent, MG_CA2_SEEN=1, QF_COPY_UNIX recorded; 092's applied_at ($AT4_107) at or after it — no dated MG-CA1 note"
master106 "107-4f"
aft107 "107-4g" PASSED $L2ON107
check "$(has "$AFT107" 'ME-CA1 PASS —')|$(has "$AFT107" 'errors master caught are not judged (plugin.log:')|$(has "$AFT107" "  ok    MG-CA2 migration.log holds 092's OK line")" "yes|yes|yes" \
  "107-4h the --after-only after master's cycle: ME-CA1 PASS with its 'not judged (plugin.log: …)' clause; MG-CA2's OK line still there"
B4_107="$(bookdig107)"; L4_107="$(logsha107)"; S4_107="$(sha256sum < "$STATE107" | cut -c1-16)"; LX4_107="$(ledgerx107)"

# ════════════════════════════════════════════════════════════════════════════
# 107-6 on D1 (MG_CA2_SEEN=1) — each an --after-only, its plant kept and put back after (the book by its digest, the log and the
# state file by sha256). The branches judged are the same in --after-only and a redeploy (v3 §C item 6).
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-6. on D1 (MG_CA2_SEEN=1): the failures after the copy, MG-CA2's missing line, the straddle note's conditions, the ladder edited after the copy =="
ao107() { idle104; fresh107; AO107="$(run107c --after-only)"; AOT107="$(last107)"; AORC107="$(rc107)"; }   # one --after-only under the clock: AO107, AOT107, AORC107
STK107="$R107/stk"; mkdir -p "$STK107"; chmod 700 "$STK107"
sdrop107() {   # sdrop107 TAG KEY… — the state file kept as TAG, then every KEY= line taken out (each asserted present first)
  local t="$1" k; shift; cp -p "$STATE107" "$STK107/$t"
  for k in "$@"; do grep -q "^$k=" "$STATE107" || echo "sdrop107:$t:$k" >> "$SB/anchor-missing"; sed -i "/^$k=/d" "$STATE107"; done
}
sback107() { cp -p "$STK107/$1" "$STATE107" && rm -f "$STK107/$1"; }   # sback107 TAG — put back as kept
back4_107() {   # back4_107 LABEL — D1's state after 107-4: the book by its digest, migration.log by sha256, the state file's two 092 records, nothing kept
  check "$(bookdig107)|$(logsha107)|$(sget107 "$STATE107" MG_G1)|$(sget107 "$STATE107" MG_CA2_SEEN)|$(kept107)|$(ls -A "$STK107" | grep -c .)|$(live)" "$B4_107|$L4_107|absent|1|0|0|$P107" \
    "$1 put back: the book by its digest and migration.log by sha256 as 107-4 left them, the state file's MG_G1=absent and MG_CA2_SEEN=1, nothing kept"
}
declare -F standin_page >/dev/null || echo "standin_page:missing" >> "$SB/anchor-missing"   # the chain's request to the stand-in (5.18.90's harness)
# 6a — wa_no_ai renamed: NA-CA1 FAIL (probe 1 throws), MG-CA1 FAIL; the lead in capitals: no AI reply; the rollback command stands alone
X1="$(seedk107 "$P107R" ledger092 r6a '"what":"rename"')"
ao107
check "$(sd107 "$X1" fixture)|$AORC107|$(has "$AO107" 'NA-CA1 wa_no_ai does not read the way the worker reads it')|$(has "$AO107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$AO107" '  NO UGANDA CUSTOMER GETS AN AI REPLY: THE ASSISTANT-OFF LIST')|$(has "$AO107" 'a failure after the copy means the rollback')|$(grep -cxF "      $EXP_ROLLBACK107" <<<"$AO107")" \
      "1|1|yes|yes|yes|yes|1" \
  "107-6a wa_no_ai renamed (a FIXTURE, the book kept first): NA-CA1 FAIL (probe 1 throws), MG-CA1 FAIL; the FAILED summary leads with the AI-reply consequence in capitals; the rollback command alone on its line"
check "$(sd107 "$(seedk107 "$P107R" ledger092_undo r6a '"what":"rename"')" fixture)" "1" "107-6a' the table's name back (the book from its keep)"; back4_107 "107-6a*"
# 6b — another md5 over a whole, readable table: MG-CA1 FAIL; NA-CA1 holds; the lead names the checksum and the redeploy consequence, and AI replies go on
X1="$(seedk107 "$P107R" ledger092 r6b '"what":"checksum"')"
ao107
check "$(sd107 "$X1" fixture)|$AORC107|$(has "$AO107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$AO107" '  ok    NA-CA1 wa_no_ai reads the way the worker reads it')|$(has "$AO107" '  092 is not shown whole in the live migration ledger')|$(has "$AO107" 'MG-G1 refuses every redeploy')|$(has "$AO107" '(NA-CA1): AI replies go on.')|$(has "$AO107" 'NO UGANDA CUSTOMER GETS AN AI REPLY')" \
      "1|1|yes|yes|yes|yes|yes|no" \
  "107-6b 092's ledger row with another md5 (a FIXTURE): MG-CA1 FAIL, NA-CA1 holds; the lead names the checksum and that MG-G1 refuses every redeploy, and says AI replies go on — never that they stopped"
check "$(sd107 "$(seedk107 "$P107R" ledger092_undo r6b '"what":"checksum"')" fixture)" "1" "107-6b' the checksum put back"; back4_107 "107-6b*"
# 6c — MG_CA2_SEEN=1, the OK line removed, no trim marker: the note that does not name the halving. Copies: one ignores MG_CA2_SEEN
# (the absent branch: NOT ESTABLISHED naming the missing record), one names the halving there
anc107 vread '    v="$(sed -n '"'"'s/^MG_CA2_SEEN=//p'"'"' "$STATE" 2>/dev/null | tail -n1)"' '    v=""'
anc107 halvalways '(MG_CA2_SEEN=1), and it is gone since: $( [ -n "$mk" ] && echo' '(MG_CA2_SEEN=1), and it is gone since: $( [ -z "" ] && echo'
K1="$(seedk107 "$P107R" log_keep l6c)"; K2="$(seedk107 "$P107R" log_drop l6c '"match":"ok092"')"
ao107
check "$(sd107 "$K2" removed)|$AORC107|$(fails "$AO107")|$(has "$AO107" 'the log'"'"'s first line is no trim marker, so halving is not the cause')|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "1|0|0|yes|no" \
  "107-6c MG_CA2_SEEN=1 and 092's OK line gone with no trim marker (a FIXTURE): MG-CA2's note says the deploy read it and halving is not the cause — never NOT ESTABLISHED, never a FAIL"
mutant107 c6c-vread vread -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD')|$(has "$MUT107" 'halving is not the cause')" "yes|no" \
  "107-6c' a weakened copy that ignores MG_CA2_SEEN takes the absent branch and prints NOT ESTABLISHED naming the missing record — caught"
mutant107 c6c-halv halvalways -- --after-only
check "$(has "$MUT107" "is the runner's trim marker — 071_dpo_payments.sql's checksum WARNING")" "yes" "107-6c\" a weakened copy that names the halving with no marker — caught"
check "$(sd107 "$(seedk107 "$P107R" log_back l6c)" fixture)" "1" "107-6c* migration.log back"; back4_107 "107-6c**"
# 6d — the 071 halving, by the runner itself: 071's ledger checksum other (a FIXTURE: production's state, every boot logs 071's
# WARNING), migration.log filled with fictitious lines naming no migration until it is just over 256 KiB, then ONE logging boot
# through the stand-in (UTC): the marker on line 1, 092's OK line gone, 071's WARNING after. The note names both. Copies: one
# ignores MG_CA2_SEEN and the marker (NOT ESTABLISHED), one treats MG_CA2_SEEN=1 as 0 (NOT ESTABLISHED). The control (no sighting,
# no marker, NOT ESTABLISHED) is D6's.
anc107 mkread 'case "$l1" in "["*"] --- earlier entries trimmed ---") mk=1;' 'case "$l1" in "["*"] --- earlier entries trimmed -X-") mk=1;'
anc107 seen1as0 '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER' '      0|1) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER'
K0="$(seedk107 "$P107R" book_keep b6d)"; K1="$(seedk107 "$P107R" ledger071 c6d)"; K2="$(seedk107 "$P107R" log_keep l6d)"; K3="$(seedk107 "$P107R" log_fill l6d '"over":262144')"
LG6D_107="$(log107)"
check "$(sd107 "$K1" fixture)|$(kv107 "$LG6D_107" ok)|$( [ "$(n103 "$(kv107 "$LG6D_107" okoff)")" -lt 131072 ] && echo under)|$( [ "$(n103 "$(kv107 "$LG6D_107" size)")" -gt 262144 ] && echo over)|$(kv107 "$LG6D_107" owner)|$(kv107 "$LG6D_107" mk)" \
      "1|1|under|over|1000|0" \
  "107-6d0 before the boot: 071's checksum other (a FIXTURE), migration.log filled ($(sd107 "$K3" lines) fictitious lines) to $(kv107 "$LG6D_107" size) bytes, 092's OK line at byte $(kv107 "$LG6D_107" okoff) (in the half the runner drops), the file uid 1000's, no marker"
STP6D_107="$(standin_page customer_login)"; LG6D_107="$(log107)"
check "$STP6D_107|$(kv107 "$LG6D_107" mk)|$(kv107 "$LG6D_107" ok)|$( [ "$(n103 "$(kv107 "$LG6D_107" w071)")" -ge 1 ] && echo w071)|$( [ "$(n103 "$(kv107 "$LG6D_107" mkt)")" -ge "$(n103 "$AT4_107")" ] && echo after)" "200|1|0|w071|after" \
  "107-6d1 ONE request to the stand-in (a boot, UTC): the runner halves the log — its trim marker on line 1, stamped after 092's applied_at; 092's OK line gone; 071's WARNING after (no until loop: a second boot would be a failure of the plant)"
ao107
check "$AORC107|$(fails "$AO107")|$(has "$AO107" "the log's first line is the runner's trim marker — 071_dpo_payments.sql's checksum WARNING is logged on every boot, so the runner halves the log within hours")|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "0|0|yes|no" \
  "107-6d MG_CA2_SEEN=1 and the runner's own halving: MG-CA2's note names 071's per-boot WARNING and the halving — never NOT ESTABLISHED"
mutant107 c6d-both vread mkread -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" "is the runner's trim marker")" "yes|no" "107-6d' a weakened copy that ignores both MG_CA2_SEEN and the marker prints NOT ESTABLISHED — caught"
mutant107 c6d-1as0 seen1as0 -- --after-only
check "$(has "$MUT107" 'STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)')|$(has "$MUT107" "is the runner's trim marker")" "yes|no" "107-6d\" a weakened copy that treats MG_CA2_SEEN=1 as 0 prints NOT ESTABLISHED — caught"
check "$(sd107 "$(seedk107 "$P107R" ledger071_undo c6d)" fixture)|$(sd107 "$(seedk107 "$P107R" log_back l6d)" fixture)|$(kv107 "$(ledgerx107)" cks071)" "1|1|$(kv107 "$LX4_107" cks071)" \
  "107-6d* 071's checksum and migration.log put back (ledger071_undo, asserted)"
check "$(sd107 "$(seedk107 "$P107R" book_back b6d)" fixture)" "1" "107-6d** the book from its keep (whatever the stand-in's boot wrote)"
back4_107 "107-6d***"
# 6e — MG_CA2_SEEN taken out of the state file (three stamps): a marker at or after applied_at reads the note; one before it, and no
# marker, read NOT ESTABLISHED naming the missing record. Copies: one accepts any marker with no stamp comparison (caught by the
# second), one ignores the marker here (caught by the first).
anc107 anymark '        if [ -n "$mk" ] && [ -n "$mkt" ] && [ -n "$au" ] && [ "$mkt" -ge "$au" ]; then' '        if [ -n "$mk" ]; then'
anc107 nomark '        if [ -n "$mk" ] && [ -n "$mkt" ] && [ -n "$au" ] && [ "$mkt" -ge "$au" ]; then' '        if [ -n "" ] && [ -n "$mk" ]; then'
sdrop107 s6e MG_CA2_SEEN
for st107 in after:60 before:-60 none:x; do
  w107="${st107%%:*}"; o107="${st107##*:}"
  K1="$(seedk107 "$P107R" log_keep "l6e-$w107")"
  if [ "$w107" = none ]; then K2="$(seedk107 "$P107R" log_drop "l6e-$w107" '"match":"ok092"')"; else K2="$(seedk107 "$P107R" log_mark "l6e-$w107" "\"offset_s\":$o107")"; fi
  ao107
  case "$w107" in
    after) check "$AORC107|$(has "$AO107" 'the state file holds no MG_CA2_SEEN record; the log'"'"'s first line is a trim marker stamped (read as UTC) at or after 092'"'"'s applied_at')|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "0|yes|no" \
             "107-6e1 no MG_CA2_SEEN record, a trim marker stamped 60 s after 092's applied_at (a FIXTURE, UTC): the note naming the halving"
           mutant107 c6e-nomark nomark -- --after-only
           check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')" "yes" "107-6e1' a weakened copy that ignores the marker here prints NOT ESTABLISHED — caught" ;;
    before) check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD')|$(has "$AO107" "the log's trim marker is stamped before 092's applied_at")" "yes|yes" \
              "107-6e2 a marker stamped 60 s before applied_at: NOT ESTABLISHED, naming the missing record and the marker's stamp"
            mutant107 c6e-anymark anymark -- --after-only
            check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" 'stamped (read as UTC) at or after')" "no|yes" "107-6e2' a weakened copy that accepts any marker with no stamp comparison prints the note — caught" ;;
    none) check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD')|$(has "$AO107" "the log's first line is no trim marker")" "yes|yes" \
            "107-6e3 no marker at all: NOT ESTABLISHED, naming the missing record and no trim marker" ;;
  esac
  check "$(sd107 "$(seedk107 "$P107R" log_back "l6e-$w107")" fixture)" "1" "107-6e-$w107* migration.log back"
done
sback107 s6e; back4_107 "107-6e*"
# 6f — the state file without MG_G1 (an older one) and no OK line: NOT ESTABLISHED naming the missing record, never the redeploy note
sdrop107 s6f MG_G1; K1="$(seedk107 "$P107R" log_keep l6f)"; K2="$(seedk107 "$P107R" log_drop l6f '"match":"ok092"')"
ao107
check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — THE STATE FILE HOLDS NO MG_G1 RECORD (an older state file)')|$(has "$AO107" 'a redeploy: 092 was applied by an earlier deploy')" "yes|no" \
  "107-6f the state file holds no MG_G1 and 092's OK line is gone: NOT ESTABLISHED naming the missing record — never the redeploy note"
check "$(sd107 "$(seedk107 "$P107R" log_back l6f)" fixture)" "1" "107-6f' migration.log back"; sback107 s6f; back4_107 "107-6f*"
# 6g — 092's installed bytes altered after the copy, beside a planted 092 WARNING: R1 FAIL, MG-CA2 names the WARNING as not judged,
# no straddle note. Copy: the note keyed on MG-CA1 alone, without R1 — prints the note, caught.
anc107 stradmg1 '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ -n "$ok1" ]; then'
anc107 stradnona '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ -n "$ok1" ] && [ "$r1" = "ok" ]; then'
MF107="$PD/migrations/092_wa_no_ai.sql"; cp -p "$MF107" "$STK107/mf092"; printf '%s\n' '-- RH107SECRET- a byte the pin does not have' >> "$MF107"
K1="$(seedk107 "$P107R" log_keep l6g)"; K2="$(seedk107 "$P107R" log_add l6g '"line":"warning092"')"
ao107
check "$AORC107|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" "MG-CA2 migration.log holds 092's checksum WARNING (1) — not judged here: R1 reads the installed migrations/092_wa_no_ai.sql other than the pin's")|$(has "$AO107" 'the tar straddle')" "1|yes|yes|no" \
  "107-6g 092's installed bytes altered after the copy (a FIXTURE, one comment line) beside a planted WARNING: R1 FAIL, MG-CA2 names the WARNING as not judged beside R1 — no straddle note"
mutant107 c6g-mg1 stradmg1 -- --after-only
check "$(has "$MUT107" 'the tar straddle')" "yes" "107-6g' a weakened copy that keys the straddle note on MG-CA1 alone, without R1, prints it — caught"
cp -p "$STK107/mf092" "$MF107" && rm -f "$STK107/mf092"
check "$(sd107 "$(seedk107 "$P107R" log_back l6g)" fixture)|$(installed107 "$P107F")" "1|0" "107-6g* 092's installed file and migration.log back"
back4_107 "107-6g**"
# 6h — NA-CA1's first sentinel planted active (a data-only FIXTURE) beside the WARNING: MG-CA1, R1 hold; NA-CA1 FAIL (probe 1 lists it),
# no straddle note, MG-CA2 names the WARNING as not judged; the lead is NA-CA1's (dat). Copy: NA-CA1 dropped from the note's condition.
X1="$(seedk107 "$P107R" ledger092 n6h "\"what\":\"sentinel\",\"phone\":\"$NAS107\"")"; K1="$(seedk107 "$P107R" log_keep l6h)"; K2="$(seedk107 "$P107R" log_add l6h '"line":"warning092"')"
check "$(sd107 "$X1" fixture)|$(kv107 "$(ledgerx107)" sentinel)" "1|1" "107-6h0 NA-CA1's first sentinel planted active (a FIXTURE: the script's own constant, a number nobody can hold)"
ao107
check "$(has "$AO107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" 'NA-CA1 wa_no_ai lists a number nobody can hold')|$(has "$AO107" 'the tar straddle')|$(has "$AO107" "checksum WARNING (1) — not judged here: NA-CA1 failed or was not read; ")|$(has "$AO107" '  NA-CA1 failed: the assistant-off list reads, but its first probe')" \
      "yes|no|yes|no|yes|yes" \
  "107-6h MG-CA1 and R1 hold; NA-CA1 FAIL (probe 1 lists the sentinel), no straddle note, MG-CA2 names the WARNING as not judged; the lead is NA-CA1's: the list reads, its rows are wrong"
mutant107 c6h-nona stradnona -- --after-only
check "$(has "$MUT107" 'the tar straddle')" "yes" "107-6h' a weakened copy that drops NA-CA1 from the note's condition prints it — caught"
# 6i — the same with wa_no_ai renamed as well: NA-CA1 and MG-CA1 FAIL, no straddle note (no copy claimed: MG-CA1's objects term covers it)
X2="$(seedk107 "$P107R" ledger092 r6i '"what":"rename"')"
ao107
check "$(sd107 "$X2" fixture)|$(has "$AO107" 'NA-CA1 wa_no_ai does not read the way the worker reads it')|$(has "$AO107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$AO107" 'the tar straddle')" "1|yes|yes|no" \
  "107-6i the sentinel and the rename together: NA-CA1 and MG-CA1 FAIL, no straddle note"
check "$(sd107 "$(seedk107 "$P107R" ledger092_undo r6i '"what":"rename"')" fixture)|$(sd107 "$(seedk107 "$P107R" ledger092_undo n6h '"what":"sentinel"')" fixture)|$(sd107 "$(seedk107 "$P107R" log_back l6h)" fixture)" "1|1|1" "107-6i' the rename, the sentinel and the WARNING taken off"
back4_107 "107-6i*"
# 6j — the ladder edited after the copy (one byte, said so): R1 FAIL, the ladder read 'neither', the switch-off worded 'may be refused',
# never 'it leaves '0''; both §K commands printed. Copy: 'it leaves '0'' whatever the ladder reads — caught.
anc107 wlzero '*) wl="may be refused" ;; esac' "*) wl=\"runs, and leaves '0'\" ;; esac"
LF107="$PD/lib/SalesFollowupLadder.php"; cp -p "$LF107" "$STK107/lad"; printf '\n// RH107SECRET- a byte the pin does not have\n' >> "$LF107"
ao107
check "$AORC107|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" '  The switch-off — it may be refused:')|$(has "$AO107" "it runs, and leaves '0'")" \
      "1|yes|yes|no" \
  "107-6j the ladder edited after the copy (a FIXTURE): R1 FAIL; the put-back paragraph words the switch-off 'may be refused', never 'it leaves '0''"
check "$( [ "$(grep -cxF "      $BDOFF107" <<<"$AO107")" -ge 1 ] && echo off)|$( [ "$(grep -cxF "      $BDDR107" <<<"$AO107")" -ge 1 ] && echo drain)" "off|drain" \
  "107-6j' both of §K's commands printed, each alone on its line"
mutant107 c6j-zero wlzero -- --after-only
check "$(has "$MUT107" "it runs, and leaves '0'")" "yes" "107-6j\" a weakened copy that says it leaves '0' whatever the ladder reads — caught"
cp -p "$STK107/lad" "$LF107" && rm -f "$STK107/lad"
check "$(installed107 "$P107F")" "0" "107-6j* the ladder back as the pin has it"; back4_107 "107-6j**"
aft107 "107-6z" PASSED $L2ON107
check "$(ancc107)" "0|$(ls "$AN107"/*.a | wc -l | tr -d ' ')|-" "107-6zz every anchor so far the script's own text, once"
