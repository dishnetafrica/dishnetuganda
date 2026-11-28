# ════════════════════════════════════════════════════════════════════════════
# D2 to D10 — first-deploy variants (v3 §B: 107-5, 107-6 seen=0 and nowait), each from A9 (reset107): the plant, the deploy,
# then the step's own --after-only runs. Each plant is said where it is made; each weakened copy runs in the cheapest mode
# that reaches its branch, said so. Every keep is undone before the next A9.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-5. D2–D10: first deploys with a plant — before stamp_copy (H2), at the DEPLOY hold, at deploy-hybrid's read (H4), a WARNING beside the OK line, the log unwritable (seen=0), WARNING only, other bytes at stamp_copy, no boot (nowait, H1), a PARTIAL line =="
verd107() {   # verd107 OUTPUT — the run's verdict line as read (a deploy, a redeploy or a rollback): PASSED, FAILED:<n>, NOTYET, or none
  if grep -qxE "  ${V107//./\\.} \((deploy|rollback)\): PASSED\. Send this LOG FILE back \(not a copy of the terminal, and never the cash sheet\)\." <<<"$1"; then echo PASSED
  elif grep -qE "^  ${V107//./\\.} \((deploy|rollback)\): [0-9]+ FAILED" <<<"$1"; then echo "FAILED:$(grep -oE "^  ${V107//./\\.} \((deploy|rollback)\): [0-9]+ FAILED" <<<"$1" | grep -oE '[0-9]+ FAILED' | cut -d' ' -f1)"
  elif grep -qF "  $V107 (after-only): NOT YET — " <<<"$1"; then echo NOTYET
  else echo none; fi
}
dep107() { fresh107; DEP107="$(run107c --answer DEPLOY)"; DEPT107="$(last107)"; DEPRC107="$(rc107)"; }   # dep107 — a plain deploy under the clock, DEPLOY typed
dfail107() { [ "$(n103 "$(fails "$1")")" -gt 0 ] && echo some || echo none; }   # dfail107 OUTPUT — some FAIL line, or none
pin092_107() { echo "$(pinroot107)/migrations/092_wa_no_ai.sql"; }            # the pin's own 092, from Git's tree
PINMD5_107="$(md5sum < "$(pin092_107)" | cut -c1-16)"
check "$( [ "${#PINMD5_107}" = 16 ] && echo read)|$(grep -c "MIG107_LAST_MD5=\"$PINMD5_107" "$DEPLOY107")" "read|1" \
  "107-5-0 the pin's 092 read from Git's tree here: its md5 is the script's MIG107_LAST_MD5 (the checksum the runner records), so a ledger checksum can be told the pin's or another's"

# ── D2 — A6/H2: 092 applied before stamp_copy, after the tar (v2 A6; v3). PASSED; QF_COPY_UNIX ≤ applied_at < COPY_AT; no dated
# note; master106, --after-only PASSED. The copy (an applied_at < COPY_AT bound in MG-CA1) runs as --after-only and FAILS there;
# its deploy-run half is not repeated (the branch is the same in both modes, v3 §C item 6). ──
reset107 "107-5a-A9"
: > "$SB/plant092touch107.fired"; : > "$R107/boot/h2.out"; : > "$SB/.plant092touch107"
dep107
AT5A_107="$(kv107 "$(ledgerx107)" at092)"; CU5A_107="$(sget107 "$STATE107" QF_COPY_UNIX)"; CA5A_107="$(sget107 "$STATE107" COPY_AT)"
check "$(verd107 "$DEP107")|$DEPRC107|$(live)|$(grep -c . "$SB/plant092touch107.fired")|$( [ -e "$SB/.plant092touch107" ] && echo unspent || echo spent)|$(grep -c '^booted$' "$R107/boot/h2.out")" \
      "PASSED|0|$P107|1|spent|1" \
  "107-5a D2 the trigger set before the run: H2 fires once, at stamp_copy's touch (after the tar, before V1), boots the installed store and is spent; the deploy PASSED, $P107 serving"
check "$(case "$AT5A_107|$CU5A_107|$CA5A_107" in *[!0-9\|]*|*'||'*|'|'*|*'|') echo unread ;; *) [ "$CU5A_107" -le "$AT5A_107" ] && [ "$AT5A_107" -lt "$CA5A_107" ] && echo between || echo outside ;; esac)|$(has "$DEP107" '092 was applied before this copy began')|$(has "$DEP107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(sget107 "$STATE107" MG_CA2_SEEN)" \
      "between|no|yes|1" \
  "107-5a' QF_COPY_UNIX ($CU5A_107) ≤ 092's applied_at ($AT5A_107) < COPY_AT ($CA5A_107): MG-CA1 ok with no dated note — the note's bound is QF_COPY_UNIX, never COPY_AT; MG_CA2_SEEN=1"
master106 "107-5a0"
aft107 "107-5a\"" PASSED $L2ON107
check "$(has "$AFT107" '092 was applied before this copy began')|$(has "$AFT107" '  ok    MG-CA1 092 on a fresh copy after the copy')" "no|yes" "107-5a* the --after-only: MG-CA1 ok, no dated note"
anc107 cabound '    at="$(qf_kv "$o" at)"; au=""; case "$at" in [0-9][0-9][0-9][0-9]-*) au="$(date -u -d "${at/T/ }" +%s 2>/dev/null)" ;; esac' \
  '    at="$(qf_kv "$o" at)"; au=""; case "$at" in [0-9][0-9][0-9][0-9]-*) au="$(date -u -d "${at/T/ }" +%s 2>/dev/null)" ;; esac; cab="$(sed -n '"'"'s/^COPY_AT=//p'"'"' "$STATE" 2>/dev/null | tail -n1)"; [ -n "$au" ] && [ -n "$cab" ] && [ "$au" -lt "$cab" ] && { MG_CA1_R=bad; bad "MG-CA1 rh107 copy: 092 applied before COPY_AT"; }'
idle104; fresh107; mutant107 c5a-cabound cabound -- --after-only
check "$MUTRC107|$(has "$MUT107" 'MG-CA1 rh107 copy: 092 applied before COPY_AT')" "1|yes" \
  "107-5a** a weakened copy that bounds MG-CA1 by COPY_AT FAILS on D2's honest 092 — caught (run as --after-only; the deploy-run half is the same branch)"

# ── D3 — A7: 092 applied while the run waits at Type DEPLOY, before LK-B1 and QF_BASE (csb1run107; the action rh107-seed.php
# mig092, the pin's MigrationRunner over the live book as uid 1000 under the clock, said so). applied_at < QF_COPY_UNIX; the
# dated note; R7 applied (its baseline is stage A's); LK-CA2 exact against a baseline that already holds 092's row. ──
reset107 "107-5b-A9"
mig3_107() { seed107 "$(pinroot107)" '[{"op":"mig092"}]' > "$R107/logs/mig3.json"; case "$(head -c1 "$R107/logs/mig3.json")" in '{') return 0 ;; *) return 1 ;; esac; }
csb1run107 107-5b "$DEPLOY107" -- mig3_107
M3_107="$(cat "$R107/logs/mig3.json" 2>/dev/null)"; AT5B_107="$(kv107 "$(ledgerx107)" at092)"; CU5B_107="$(sget107 "$STATE107" QF_COPY_UNIX)"
check "$HOLDSAW107|$HOLDRC107|$(sd107 "$M3_107" rows)|$(sd107 "$M3_107" ok_lines)|$(sd107 "$M3_107" cks)|$(verd107 "$HOLDOUT107")|$(rc107)|$(live)" \
      "yes|0|1|1|$PINMD5_107|PASSED|0|$P107" \
  "107-5b D3 held at Type DEPLOY, the action (the seed's own exit status 0): the pin's runner applies 092 over the live book — one ledger row, one OK line, the pin's checksum; the deploy PASSED"
check "$(case "$AT5B_107|$CU5B_107" in *[!0-9\|]*|'|'*|*'|') echo unread ;; *) [ "$AT5B_107" -lt "$CU5B_107" ] && echo before || echo after ;; esac)|$(sget107 "$STATE107" MG_G1)|$(has "$HOLDOUT107" 'MG-CA1 092 was applied before this copy began (')|$(has "$HOLDOUT107" 'moved by exactly 092, applied at a boot')|$(has "$HOLDOUT107" 'LK-CA2 every _schema_checks row unchanged across the copy (')|$(has "$HOLDOUT107" 'but one added, exactly 092_wa_no_ai.sql:5/5')" \
      "before|absent|yes|yes|yes|no" \
  "107-5b' applied_at ($AT5B_107) < QF_COPY_UNIX ($CU5B_107), MG_G1=absent at stage A: MG-CA1's dated note; R7 the ledger moved by exactly 092 since stage A; LK-CA2 exact — every row unchanged across the copy (its baseline, taken after the hold, holds 092's row already)"

# ── D4 — A8/H4: 092 left on disk (the pin's file copied into the live 5.18.106 plugin, a FIXTURE, said so), a WARNING planted
# beside it; .boot107 armed by csb1run107's action at Type DEPLOY — never before the run (v3 TC2): 5.18.106's runner applies the
# 092 on disk at deploy-hybrid's read of the record, after QF_BASE, before the tar. First its control: armed before a --check,
# the counter stays 0 (the parent is not deploy-hybrid). Then: the straddle note, no dated note, PASSED. The copy (the note
# keyed on applied_at < QF_COPY_UNIX) runs as --after-only. ──
reset107 "107-5c-A9"
cp "$(pin092_107)" "$PD/migrations/092_wa_no_ai.sql"; chown 1000:1000 "$PD/migrations/092_wa_no_ai.sql"; chmod 644 "$PD/migrations/092_wa_no_ai.sql"
K1="$(seedk107 "$(pinroot107)" log_keep l5c)"; K2="$(seedk107 "$(pinroot107)" log_add l5c '"line":"warning092"')"
check "$(sd107 "$K2" fixture)|$(ledger107)|$(md5sum < "$PD/migrations/092_wa_no_ai.sql" | cut -c1-16)|$(live)" "1|mig092=0 wa_no_ai=none|$PINMD5_107|$P106" \
  "107-5c0 the plants: the pin's 092 on disk in the live $V106 plugin (a FIXTURE), a 092 WARNING appended to migration.log (a FIXTURE, UTC); the ledger holds no 092"
: > "$SB/boot107.fired"; : > "$SB/boot107.passed"; : > "$R107/boot/h4.out"; : > "$SB/.boot107"
D94_5C_107="$(data94)"; fresh107; O5C_107="$(run107c --check)"
check "$(grep -c . "$SB/boot107.fired")|$( [ "$(grep -c '^notdh$' "$SB/boot107.passed")" -ge 1 ] && echo passed)|$( [ "$(grep -c '^dhcheck$' "$SB/boot107.passed")" -ge 1 ] && echo dhcheck)|$( [ -e "$SB/.boot107" ] && echo armed || echo spent)|$(ledger107)|$(data94)" "0|passed|dhcheck|armed|mig092=0 wa_no_ai=none|$D94_5C_107" \
  "107-5c1 H4's control: armed before a --check, the record read by the script itself and by stage A's deploy-hybrid.sh --check (S:12315) passes through — neither is the deploy's read — 0 boots, still armed, no 092 applied, the data as before"
rm -f "$SB/.boot107"; : > "$SB/boot107.passed"
arm4_107() { grep -c . "$SB/boot107.fired" > "$R107/logs/arm4.n"; : > "$SB/.boot107"; }   # at the hold: the counter read, then H4 armed
csb1run107 107-5c "$DEPLOY107" -- arm4_107
AT5C_107="$(kv107 "$(ledgerx107)" at092)"; CU5C_107="$(sget107 "$STATE107" QF_COPY_UNIX)"
check "$HOLDSAW107|$HOLDRC107|$(cat "$R107/logs/arm4.n" 2>/dev/null)|$(grep -c . "$SB/boot107.fired")|$( [ -e "$SB/.boot107" ] && echo armed || echo spent)|$(grep -c '^booted$' "$R107/boot/h4.out")|$(verd107 "$HOLDOUT107")|$(live)" \
      "yes|0|0|1|spent|1|PASSED|$P107" \
  "107-5c D4 H4 armed at the hold (the counter 0 there): it fires once, at deploy-hybrid's read of the record while it names $P106 — $V106's runner applies the 092 on disk — and the deploy PASSED"
check "$(case "$AT5C_107|$CU5C_107" in *[!0-9\|]*|'|'*|*'|') echo unread ;; *) [ "$AT5C_107" -ge "$CU5C_107" ] && echo after || echo before ;; esac)|$(has "$HOLDOUT107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$HOLDOUT107" '092 was applied before this copy began')|$(has "$HOLDOUT107" 'but one added, exactly 092_wa_no_ai.sql:5/5')" \
      "after|yes|no|yes" \
  "107-5c' applied_at ($AT5C_107) at or after QF_COPY_UNIX ($CU5C_107): the straddle note (the WARNING transient: MG-CA1, NA-CA1 and R1 hold), no dated note; LK-CA2 one row added, 092's"
anc107 stradcu '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ -n "$au" ] && [ -n "$cu" ] && [ "$au" -lt "$cu" ]; then'
idle104; fresh107; mutant107 c5c-stradcu stradcu -- --after-only
check "$(has "$MUT107" 'the tar straddle')|$(has "$MUT107" "MG-CA2 migration.log holds 092's checksum WARNING (1) — not judged here")" "no|yes" \
  "107-5c* a weakened copy that keys the straddle note on applied_at < QF_COPY_UNIX reads no note on D4 — caught (as --after-only)"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5c)" fixture)|$(kept107)" "1|0" "107-5c** migration.log back from its keep"

# ── D5 — a WARNING beside the OK line on a plain first deploy: the straddle note, PASSED. Its copy (the note keyed on MG-G1 alone)
# runs as --after-only. Then 092's installed bytes altered (a FIXTURE) and an --after-only: R1 FAIL, MG-CA2 names the WARNING as
# not judged, no straddle note. ──
reset107 "107-5d-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5d)"; K2="$(seedk107 "$(pinroot107)" log_add l5d '"line":"warning092"')"
dep107
check "$(sd107 "$K2" fixture)|$(verd107 "$DEP107")|$DEPRC107|$(has "$DEP107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$DEP107" "  ok    MG-CA2 migration.log holds 092's OK line (1)")|$(sget107 "$STATE107" MG_CA2_SEEN)" \
      "1|PASSED|0|yes|yes|1" \
  "107-5d D5 a 092 WARNING planted before a plain first deploy (a FIXTURE): MG-CA2's OK line and the straddle note beside it — PASSED"
anc107 stradmg '    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then' '    if [ "$mg" = "present" ]; then'
idle104; fresh107; mutant107 c5d-stradmg stradmg -- --after-only
check "$(has "$MUT107" 'the tar straddle')|$(has "$MUT107" "checksum WARNING (1) — not judged here")" "no|yes" \
  "107-5d' a weakened copy that keys the straddle note on MG-G1 alone (a redeploy) reads no note on a first deploy — caught (as --after-only)"
MF107="$PD/migrations/092_wa_no_ai.sql"; mkdir -p "$STK107"; cp -p "$MF107" "$STK107/mf5d"; printf '%s\n' '-- RH107SECRET- a byte the pin does not have' >> "$MF107"
ao107
check "$AORC107|$( [ "$(n103 "$(kv107 "$(log107)" warning)")" -ge 2 ] && echo real)|$(has "$AO107" 'R1 installed files that differ:')|$(has "$AO107" "MG-CA2 migration.log holds 092's checksum WARNING (")|$(has "$AO107" 'not judged here: R1 reads the installed migrations/092_wa_no_ai.sql other than the pin'"'"'s')|$(has "$AO107" 'the tar straddle')" \
      "1|real|yes|yes|yes|no" \
  "107-5d\" 092's installed bytes altered after the copy (a FIXTURE): the --after-only's own V1 boot logs the real WARNING beside the planted one — R1 FAIL, MG-CA2 names the WARNING as not judged beside R1, no straddle note"
cp -p "$STK107/mf5d" "$MF107" && rm -f "$STK107/mf5d"
check "$(installed107 "$P107F")|$(sd107 "$(seedk107 "$(pinroot107)" log_back l5d)" fixture)|$(kept107)" "0|1|0" "107-5d* 092's installed file and migration.log back"

# ── D6 — seen=0: migration.log made unwritable before the run (0444: the boot's @file_put_contents fails silently). Stage R's
# NOT ESTABLISHED in capitals, MG_CA2_SEEN=0. Then --after-only: NOT ESTABLISHED (seen=0, no marker) with two copies (a note
# instead; MG-G1 read from the run's own stage A); a marker stamped after applied_at: NOT ESTABLISHED still, with two copies (the
# marker taken as the halving; 0 read as 1). The log writable again at the end. ──
reset107 "107-5e-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5e)"; LM5E_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"; LS5E_107="$(logsha107)"
dep107
check "$(sd107 "$K1" fixture)|$(verd107 "$DEP107")|$DEPRC107|$(sget107 "$STATE107" MG_CA2_SEEN)|$(has "$DEP107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT')|$(kv107 "$(ledger107)" mig092)|$(logsha107)" \
      "1|PASSED|0|0|yes|1|$LS5E_107" \
  "107-5e D6 migration.log unwritable (0444, a FIXTURE): V1's boot applies 092 and logs nothing — stage R's NOT ESTABLISHED in capitals, MG_CA2_SEEN=0, the log unchanged; a note, PASSED"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5e)" fixture)|$(stat -c %a "$DATA/migration.log")" "1|$LM5E_107" "107-5e' migration.log writable again (put back from its keep, its mode $LM5E_107 as before)"
ao107
check "$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)')|$(dfail107 "$AO107")" "yes|none" \
  "107-5e\" --after-only, MG_CA2_SEEN=0 and no marker: NOT ESTABLISHED naming stage R's own reading — a note, no FAIL"
anc107 seen0note '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)' '      0) note "MG-CA2 no OK line for 092, and stage R of the deploy read none either (MG_CA2_SEEN=0)'
anc107 mgown '  if [ "$MODE" = "after" ]; then mg="$(sed -n '"'"'s/^MG_G1=//p'"'"' "$STATE" 2>/dev/null | tail -n1)";' '  if [ "$MODE" = "after" ]; then mg="${MG_G1:-}";'
idle104; fresh107; mutant107 c5e-note seen0note -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" 'MG-CA2 no OK line for 092, and stage R of the deploy read none either (MG_CA2_SEEN=0)')" "no|yes" "107-5e* a weakened copy that says the --after-only's seen=0 reading as a plain note — caught (its note printed: the copy reached the branch)"
idle104; fresh107; mutant107 c5e-mgown mgown -- --after-only
check "$(has "$MUT107" 'STAGE R OF THE DEPLOY READ NONE EITHER')|$( { [ "$(has "$MUT107" 'THE STATE FILE HOLDS NO MG_G1 RECORD')" = yes ] || [ "$(has "$MUT107" 'a redeploy: 092 was applied by an earlier deploy')" = yes ]; } && echo misread)" "no|misread" \
  "107-5e** a weakened copy that reads MG-G1 from its own run (an --after-only runs no stage A) loses the deploy's record — caught"
K1="$(seedk107 "$P107R" log_keep l5em)"; K2="$(seedk107 "$P107R" log_mark l5em '"offset_s":60')"
ao107
check "$(sd107 "$K2" fixture)|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)')|$(has "$AO107" "the runner's trim marker")" "1|yes|no" \
  "107-5e*** a trim marker stamped 60 s after applied_at (a FIXTURE, UTC): still NOT ESTABLISHED — stage R read none minutes after the first boot, which no halving explains"
anc107 seen0mk '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)' '      0) [ -n "$mk" ] && note "MG-CA2 no OK line for 092 now — rh107 copy: the runner'"'"'s trim marker" || note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)'
anc107 seen0x '      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)' '      0x) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)'
anc107 seen0as1 '      1) note "MG-CA2 no OK line for 092 now — stage R of the deploy read it after the first boot (MG_CA2_SEEN=1)' '      0|1) note "MG-CA2 no OK line for 092 now — stage R of the deploy read it after the first boot (MG_CA2_SEEN=1)'
idle104; fresh107; mutant107 c5e-mk seen0mk -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" "rh107 copy: the runner's trim marker")" "no|yes" "107-5e+ a weakened copy that takes the marker as the halving when seen=0 — caught"
idle104; fresh107; mutant107 c5e-0as1 seen0x seen0as1 -- --after-only
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED')|$(has "$MUT107" "is the runner's trim marker")" "no|yes" "107-5e++ a weakened copy that reads 0 as 1 names the halving — caught"
check "$(sd107 "$(seedk107 "$P107R" log_back l5em)" fixture)|$(kept107)" "1|0" "107-5e+++ migration.log back"
# Stage R's own capitals (S:8485, deploy mode only — the --after-only copies above reach S:8489, not this line): their copy, a
# deploy of its own from A9 with the log unwritable again (said so: v3 §C item 6 does not hold for this branch).
anc107 seenR '    else note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT' '    else note "MG-CA2 no OK line for 092 in migration.log, though the ledger holds it'
reset107 "107-5e-A9b"
K1="$(seedk107 "$(pinroot107)" log_keep l5eR)"; LM5ER_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"
fresh107; mutant107 c5e-R seenR -- --answer DEPLOY
check "$(has "$MUT107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log')|$(has "$MUT107" 'MG-CA2 no OK line for 092 in migration.log, though the ledger holds it')|$(sget107 "$STATE107" MG_CA2_SEEN)" "no|yes|0" \
  "107-5e# a weakened copy that says stage R's reading (seen=0) as a plain note in the deploy run — caught (a deploy of its own from A9, the log unwritable)"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5eR)" fixture)|$(stat -c %a "$DATA/migration.log")|$(kept107)" "1|$LM5ER_107|0" "107-5e## migration.log back, writable"

# ── D7 — WARNING only, first deploy: a WARNING planted, then the log made unwritable. The straddle note beside stage R's
# NOT ESTABLISHED; PASSED. ──
reset107 "107-5f-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5f)"; K2="$(seedk107 "$(pinroot107)" log_add l5f '"line":"warning092"')"; LM5F_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"
dep107
check "$(sd107 "$K2" fixture)|$(verd107 "$DEP107")|$DEPRC107|$(has "$DEP107" 'the tar straddle (root docs/76 §T.6, TD12)')|$(has "$DEP107" 'MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT')|$(sget107 "$STATE107" MG_CA2_SEEN)" \
      "1|PASSED|0|yes|yes|0" \
  "107-5f D7 a 092 WARNING and no OK line (the WARNING planted, then the log made unwritable — FIXTURES): the straddle note beside stage R's NOT ESTABLISHED, MG_CA2_SEEN=0 — PASSED"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5f)" fixture)|$(stat -c %a "$DATA/migration.log")|$(kept107)" "1|$LM5F_107|0" "107-5f' migration.log back, writable"

# ── D8 — §T.11:3487: D7's plants, and H2's second trigger alters the INSTALLED 092 at stamp_copy, before V1 — V1's boot then applies
# other bytes, so the ledger records another md5. MG-CA1 FAIL and R1 FAIL (said so: R1 reads the same altered file). ──
reset107 "107-5g-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5g)"; K2="$(seedk107 "$(pinroot107)" log_add l5g '"line":"warning092"')"; LM5G_107="$(stat -c %a "$DATA/migration.log")"; chmod 0444 "$DATA/migration.log"
: > "$SB/alter092touch107.fired"; : > "$SB/.alter092touch107"
dep107
CK5G_107="$(kv107 "$(ledgerx107)" cks092)"; IM5G_107="$(md5sum < "$PD/migrations/092_wa_no_ai.sql" | cut -c1-16)"
check "$(cat "$SB/alter092touch107.fired")|$( [ -e "$SB/.alter092touch107" ] && echo unspent || echo spent)|$( [ "$CK5G_107" != "$PINMD5_107" ] && [ "$CK5G_107" = "$IM5G_107" ] && echo altered)|$(verd107 "$DEP107" | cut -d: -f1)|$DEPRC107" \
      "fired|spent|altered|FAILED|1" \
  "107-5g D8 H2's second trigger at stamp_copy appends to the installed 092: V1's boot applies those bytes — the ledger's checksum is the altered file's, not the pin's — and the deploy FAILED"
check "$(has "$DEP107" 'MG-CA1 092 on a fresh copy after the copy is not whole (')|$(has "$DEP107" 'R1 installed files that differ:')|$(has "$DEP107" "checksum WARNING (1) — not judged here: R1 reads the installed migrations/092_wa_no_ai.sql other than the pin's; MG-CA1 failed; ")|$(has "$DEP107" 'the tar straddle')|$(has "$DEP107" '  092 is not shown whole in the live migration ledger')" \
      "yes|yes|yes|no|yes" \
  "107-5g' MG-CA1 FAIL (another checksum) and R1 FAIL (the installed file is not the pin's); MG-CA2 names the WARNING as not judged beside both; no straddle note; the lead is MG-CA1's"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5g)" fixture)|$(stat -c %a "$DATA/migration.log")|$(kept107)" "1|$LM5G_107|0" "107-5g* migration.log back, writable (the altered 092 goes with A9's install)"

# ── D9 — nowait (H1: the stand-in answers 503 and boots nothing). FAILED: MG-CA1's timed-out FAIL, MG_CA2_SEEN=nowait, NO BOOT HAD
# APPLIED 092, R7 DID NOT MOVE, the lead; V1's 503 recorded, not judged. Then H1 removed and the ledger asserted without 092; one
# stand-in boot; a halving marker after applied_at: the --after-only note naming the halving. The control: the line removed and no
# marker reads NOT ESTABLISHED naming the wait. Last, the copy writing MG_CA2_SEEN=0 on a timeout — a deploy, from A9. ──
reset107 "107-5h-A9"
: > "$SB/web/.noboot107"; chmod 644 "$SB/web/.noboot107"
dep107
check "$(verd107 "$DEP107" | cut -d: -f1)|$DEPRC107|$(sget107 "$STATE107" MG_CA2_SEEN)|$(has "$DEP107" 'MG-CA1 092 is not in the live migration ledger after a wait of 60 s (its last reading: mig092=0)')|$(has "$DEP107" 'MG-CA2 NOT ESTABLISHED — NO BOOT HAD APPLIED 092 WHEN THE LOG WAS READ')|$(has "$DEP107" 'R7 the migration ledger DID NOT MOVE')|$(has "$DEP107" '  NO UGANDA CUSTOMER GETS AN AI REPLY: THE ASSISTANT-OFF LIST')" \
      "FAILED|1|nowait|yes|yes|yes|yes" \
  "107-5h D9 no boot at all (H1, a FIXTURE): MG-CA1 FAIL after its 60 s wait, MG_CA2_SEEN=nowait, MG-CA2's NOT ESTABLISHED naming no boot, R7 the ledger DID NOT MOVE, the lead in capitals: no AI reply"
echo "  note 107-5h V1 under H1, recorded and not judged: $(grep -c 'V1 the sign-in page on the public address → 503' <<<"$DEP107") line(s) 'V1 the sign-in page on the public address → 503'"
rm -f "$SB/web/.noboot107"
check "$(ledger107)|$(standin_page customer_login)|$(kv107 "$(ledger107)" mig092)|$(kv107 "$(log107)" ok)" "mig092=0 wa_no_ai=none|200|1|1" \
  "107-5h' H1 removed: the ledger still holds no 092; then ONE request to the stand-in boots the store — 092 applied, its OK line logged"
K1="$(seedk107 "$P107R" log_keep l5hm)"; K2="$(seedk107 "$P107R" log_mark l5hm '"offset_s":60')"
ao107
check "$(sd107 "$K2" fixture)|$(has "$AO107" "stage R's wait timed out (MG_CA2_SEEN=nowait), so its reading says nothing about the line; the log's first line is a trim marker stamped (read as UTC) at or after 092's applied_at")|$(has "$AO107" 'MG-CA2 NOT ESTABLISHED')" "1|yes|no" \
  "107-5h\" a trim marker stamped 60 s after applied_at (a FIXTURE, UTC): the --after-only's note names the wait and the halving — never NOT ESTABLISHED"
check "$(sd107 "$(seedk107 "$P107R" log_back l5hm)" fixture)" "1" "107-5h* migration.log back"
K1="$(seedk107 "$P107R" log_keep l5hc)"; K2="$(seedk107 "$P107R" log_drop l5hc '"match":"ok092"')"
ao107
check "$(sd107 "$K2" removed)|$(has "$AO107" "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R'S WAIT TIMED OUT (MG_CA2_SEEN=nowait), SO ITS READING SAYS NOTHING ABOUT THE LINE")|$(has "$AO107" "the log's first line is no trim marker")" "1|yes|yes" \
  "107-5h** the control: the OK line removed and no marker — NOT ESTABLISHED, naming the wait and no trim marker"
check "$(sd107 "$(seedk107 "$P107R" log_back l5hc)" fixture)|$(kept107)" "1|0" "107-5h*** migration.log back"
anc107 nowait0 "    if [ -z \"\$seen\" ]; then printf 'MG_CA2_SEEN=nowait\\n' >> \"\$STATE\";" "    if [ -z \"\$seen\" ]; then printf 'MG_CA2_SEEN=0\\n' >> \"\$STATE\";"
reset107 "107-5h+A9"
: > "$SB/web/.noboot107"; chmod 644 "$SB/web/.noboot107"
fresh107; mutant107 c5h-nowait0 nowait0 -- --answer DEPLOY
check "$(sget107 "$STATE107" MG_CA2_SEEN)|$(has "$MUT107" 'MG-CA1 092 is not in the live migration ledger after a wait of 60 s')" "0|yes" \
  "107-5h+ a weakened copy that records a timed-out wait as MG_CA2_SEEN=0 — a later --after-only would claim stage R read none after seeing 092 — caught (a deploy under H1, from A9)"
rm -f "$SB/web/.noboot107"

# ── D10 — a PARTIAL line planted before the run: MG-CA2 FAIL in the deploy, then in --after-only; the lead is MG-CA2's alone — 092
# needs no repair and MG-G1 would accept a redeploy. The copy printing MG-G1's redeploy-refusal consequence, as --after-only. ──
reset107 "107-5i-A9"
K1="$(seedk107 "$(pinroot107)" log_keep l5i)"; K2="$(seedk107 "$(pinroot107)" log_add l5i '"line":"partial092"')"
dep107
check "$(sd107 "$K2" fixture)|$(verd107 "$DEP107" | cut -d: -f1)|$DEPRC107|$(has "$DEP107" 'MG-CA2 migration.log holds a PARTIAL line for 092 (1)')|$(has "$DEP107" '  ok    MG-CA1 092 on a fresh copy after the copy')|$(has "$DEP107" '  migration.log holds a PARTIAL line for 092 (MG-CA2 above), but the ledger is whole')|$(has "$DEP107" 'MG-G1 refuses every redeploy')" \
      "1|FAILED|1|yes|yes|yes|no" \
  "107-5i D10 a PARTIAL line for 092 planted before the run (a FIXTURE): MG-CA2 FAIL in the deploy, MG-CA1 ok; the lead is MG-CA2's alone — the ledger whole, no redeploy refusal"
ao107
check "$AORC107|$(has "$AO107" 'MG-CA2 migration.log holds a PARTIAL line for 092 (1)')|$(has "$AO107" 'MG-G1 would accept a redeploy')" "1|yes|yes" "107-5i' the --after-only: MG-CA2 FAIL again, the same lead"
anc107 logasmg '  elif [ "$MG_CA2_R" = "bad" ]; then MG_SAY=log' '  elif [ "$MG_CA2_R" = "bad" ]; then MG_SAY=mg'
idle104; fresh107; mutant107 c5i-logmg logasmg -- --after-only
check "$(has "$MUT107" 'MG-G1 refuses every redeploy')|$(has "$MUT107" 'MG-G1 would accept a redeploy')" "yes|no" \
  "107-5i\" a weakened copy that leads a PARTIAL line with MG-CA1's consequence (MG-G1 refuses every redeploy) — caught (as --after-only)"
check "$(sd107 "$(seedk107 "$(pinroot107)" log_back l5i)" fixture)|$(kept107)|$(LEFT107)" "1|0|none" "107-5z the exit: migration.log back, nothing kept, no trigger armed"
