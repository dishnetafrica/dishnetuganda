# ════════════════════════════════════════════════════════════════════════════
# 107-2 — MG-G1, QF-G2 and NA-G1 at stage A (root docs/76 §T.11; v2 §B 107-2): the pairing, a partial 092, 092 with other
# bytes. Each a --check refused at stage A, nothing changed; each plant a fixture of rh107-seed.php (said so: no writer
# makes these states), the book kept first and put back after by its digest, migration.log by its sha256.
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-2. MG-G1, QF-G2 and NA-G1 refusing at stage A: the pairing, a partial 092, 092 with other bytes (root docs/76 §T.11, §T.16) =="
B2_107="$(bookdig107)"; L2_107="$(logsha107)"
check "$(ledger107)|$(kept107)" "mig092=0 wa_no_ai=none|0" "107-2-0 the entry: 107-pre's book — no 092 in the ledger, no wa_no_ai — and nothing kept"
# The pairing's two weakened copies: one accepts any pairing, one drops the pairing check (§T.11). Each must read GO where
# the script refuses.
anc107 pairany '    "absent|reset=0 status=partial partial=1 check=1 row=0"|"present|reset=1 status=partial partial=1 check=1 row=0") ;;' \
               '    "absent|reset="?" status=partial partial=1 check=1 row=0"|"present|reset="?" status=partial partial=1 check=1 row=0") ;;'
anc107 pairdrop 'bad="$bad 092-negative-control-pairing(MG-G1:${MG_G1:-not-read};${o%% status=*})" ;;' ': ;;'
# 107-2a — the pairing: 092's five objects with no ledger row. MG-G1 reads 092 absent, neg092 reset=1: NO-GO naming the pairing.
PA107="$(seedk107 "$(pinroot107)" ledger092 pair107 '"what":"unledgered"')"
check "$(sd107 "$PA107" fixture)|$(sd107 "$PA107" objects)|$(ledger107)|$(logsha107)" "1|5|mig092=0 wa_no_ai=table|$L2_107" \
  "107-2a0 the pairing's ground, a FIXTURE (no writer makes it): 092's five objects made by 092's own statements on the live book, with no ledger row and no _schema_checks row; migration.log untouched (no runner ran)"
D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
check "$(has "$O107" "  ok    MG-G1 the copy's migration ledger has no 092")|$(has "$O107" '  negative control (092) reset=1 status=partial partial=1 check=1 row=0')|$(has "$O107" '092-negative-control-pairing(MG-G1:absent;reset=1)')|$(has "$O107" '  wa_no_ai (NA-G1) p1=none ')|$(has "$O107" ' p3=PDOException gate=')" \
      "yes|yes|yes|yes|yes" \
  "107-2a the pairing: MG-G1 reads 092 absent (no ledger row), 092's negative control reads reset=1 (it found wa_no_ai and took it off its own copy), and QF-G2 names the pairing; NA-G1's probe 3 throws at the read, not the rename (§T.16 S-1)"
nogo107 "107-2a'" "NO-GO (QF-G2): the one migration trial" "$O107" "$D94_107" "$P106"
mutant107 pair-any pairany -- --check
check "$(has "$MUT107" 'NO-GO (QF-G2)')|$(has "$MUT107" "  $V107 (check): GO — stage A holds.")|$MUTRC107" "no|yes|0" \
  "107-2a1 a weakened copy that accepts any pairing reads GO over the same book — caught: the script refuses it"
mutant107 pair-drop pairdrop -- --check
check "$(has "$MUT107" 'NO-GO (QF-G2)')|$(has "$MUT107" "  $V107 (check): GO — stage A holds.")|$MUTRC107" "no|yes|0" \
  "107-2a2 a weakened copy that drops the pairing check reads GO over the same book — caught"
PB107="$(seedk107 "$(pinroot107)" ledger092_undo pair107 '"what":"unledgered"')"
check "$(sd107 "$PB107" fixture)|$(ledger107)|$(bookdig107)|$(logsha107)|$(kept107)" "1|mig092=0 wa_no_ai=none|$B2_107|$L2_107|0" \
  "107-2a3 the pairing's ground taken off: the book put back from its keep (SQLite backup), its digest as before 107-2, migration.log unchanged, nothing kept"
# 107-2b and 107-2c — 092 applied by the pin's runner over the live book (the fixture mig092: said so, the book and the log
# kept first), then its _schema_checks row taken out (a partial 092), or its ledger checksum rewritten (other bytes).
for c107 in partial:b checksum:c; do
  w107="${c107%%:*}"; l107="107-2${c107##*:}"
  K1="$(seedk107 "$(pinroot107)" book_keep "mg-$w107")"; K2="$(seedk107 "$(pinroot107)" log_keep "lg-$w107")"
  M1="$(seed107 "$(pinroot107)" '[{"op":"mig092"}]')"
  K3="$(seedk107 "$(pinroot107)" ledger092 "f-$w107" "\"what\":\"$w107\"")"
  X1="$(ledgerx107)"
  if [ "$w107" = partial ]; then v107="$(kv107 "$X1" sc092)"; elif [ "$(kv107 "$X1" cks092)" != "$(sd107 "$M1" cks)" ]; then v107=other; else v107=same; fi
  check "$(sd107 "$K1" fixture)|$(sd107 "$K2" fixture)|$(sd107 "$M1" rows)|$(sd107 "$M1" ok_lines)|$(sd107 "$K3" fixture)|$(kv107 "$X1" mig092)|$v107" \
        "1|1|1|1|1|1|$( [ "$w107" = partial ] && echo missing || echo other)" \
    "${l107}0 a $( [ "$w107" = partial ] && echo 'partial 092' || echo '092 with other bytes'), a FIXTURE: the book and migration.log kept, 092 applied by the pin's MigrationRunner (one ledger row, one OK line), then $( [ "$w107" = partial ] && echo 'its _schema_checks row taken out' || echo 'its ledger checksum rewritten to another md5')"
  D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
  check "$(has "$O107" 'NO-GO (MG-G1): the book holds a 092 that is not whole')|$(has "$O107" "$( [ "$w107" = partial ] && echo 'sc=missing' || echo "mig092=1 cks=$(kv107 "$X1" cks092)")")" "yes|yes" \
    "$l107 MG-G1 refuses $( [ "$w107" = partial ] && echo 'a 092 recorded without its _schema_checks row' || echo 'a 092 recorded with other bytes'), naming what it read"
  nogo107 "$l107'" "NO-GO (MG-G1): the book holds a 092 that is not whole" "$O107" "$D94_107" "$P106"
  U1="$(seedk107 "$(pinroot107)" ledger092_undo "f-$w107" "\"what\":\"$w107\"")"; U2="$(seedk107 "$(pinroot107)" book_back "mg-$w107")"; U3="$(seedk107 "$(pinroot107)" log_back "lg-$w107")"
  check "$(sd107 "$U1" fixture)$(sd107 "$U2" fixture)$(sd107 "$U3" fixture)|$(ledger107)|$(bookdig107)|$(logsha107)|$(kept107)" "111|mig092=0 wa_no_ai=none|$B2_107|$L2_107|0" \
    "${l107}1 put back: the plant undone, the book from its keep (its digest as before 107-2), migration.log byte for byte, nothing kept"
done
check "$(ledger107)|$(bookdig107)|$(logsha107)|$(snapeq107)|$(live)" "mig092=0 wa_no_ai=none|$B2_107|$L2_107|same|$P106" \
  "107-2z the exit: 107-pre's state — no 092, the book and migration.log as they were, the snapshot's files, $P106 serving"

# ════════════════════════════════════════════════════════════════════════════
# 107-3 — NS-G1, NS-B1 and stage A's notes (root docs/76 §T.11; v2 §B 107-3, A13). The places: p <plugin>/data/config.json,
# d <data>/config.json, k <data>/kyc_config.json — planted by rh106-seed.php's fx_file as root (said so: no release writes
# p or d, and 5.18.106's tools refuse these keys) and put back by fx_file_back; s the store row, by rh107-seed.php's
# store_keys107. W.1's switch is one of the ladder's settings (settingKeys(), 107-infra b), never one of allKeys(), and LK-G1
# refuses only an allKeys() key held by p or d (S:7511): so NS-G1 judges W.1 in every place, and 107-3w shows it in p (trial 1,
# 28 Nov, found the first build expecting LK-G1's refusal there).
# ════════════════════════════════════════════════════════════════════════════
echo; echo "== 107-3. NS-G1, NS-B1 and stage A's notes: the switches in each place, '0', the store row alone, the texts, NS-B1 mid-run, the settings text, StaffJobsGate =="
B3_107="$(bookdig107)"; NSK107="$R107/nskept"; mkdir -p "$NSK107"; chmod 700 "$NSK107"
fxs107() {   # fxs107 PLACE TAG JSON-SET — a key or keys put into PLACE's file (fx_file, as root); its line
  seed106r "[{\"op\":\"fx_file\",\"place\":\"$1\",\"keep\":\"$NSK107\",\"tag\":\"$2\",\"set\":$3,\"plugin\":\"$PD\"}]"; }
fxb107() { seed106r "[{\"op\":\"fx_file_back\",\"place\":\"$1\",\"keep\":\"$NSK107\",\"tag\":\"$2\",\"plugin\":\"$PD\"}]"; }   # fxb107 PLACE TAG
nskept107() { find "$NSK107" -mindepth 1 2>/dev/null | wc -l | tr -d ' '; }
check "$(sd106 "$(fxs107 k probe '{"rh107_probe":"1"}')" 0 fixture)|$(sd106 "$(fxb107 k probe)" 0 fixture)|$(nskept107)|$(snapeq107)" "true|true|0|same" \
  "107-3-0 the place fixture answers (a probe key into k and out again, the file byte for byte, nothing kept) — the control for every plant below"
# The per-place weakened copies: NS-G1 with one place left out of its judged set (S:6488). Each must read GO where the script refuses.
anc107 nsp "\$ns = \$nsOn(\$doc, ['p', 'd', 'k']);" "\$ns = \$nsOn(\$doc, ['d', 'k']);"
anc107 nsd "\$ns = \$nsOn(\$doc, ['p', 'd', 'k']);" "\$ns = \$nsOn(\$doc, ['p', 'k']);"
anc107 nsk "\$ns = \$nsOn(\$doc, ['p', 'd', 'k']);" "\$ns = \$nsOn(\$doc, ['p', 'd']);"
for pl107 in p d k; do
  set107='{"quoted_no_prices_enabled":"1","connection_quote_enabled":true'"$( [ "$pl107" = k ] && echo ',"ai_burst_merge_enabled":1')"'}'
  want107="quoted_no_prices_enabled in $pl107; connection_quote_enabled in $pl107$( [ "$pl107" = k ] && echo "; ai_burst_merge_enabled in $pl107") reads on"
  X1="$(fxs107 "$pl107" "on-$pl107" "$set107")"
  D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
  check "$(sd106 "$X1" 0 fixture)|$(has "$O107" "NO-GO (NS-G1): $want107")|$(has "$O107" 'no value is read out')" "true|yes|yes" \
    "107-3$pl107 NS-G1 refuses $( [ "$pl107" = k ] && echo "K, Q and W.1" || echo "K and Q") on in $pl107 (a FIXTURE plant: '1', true$( [ "$pl107" = k ] && echo ', 1')), naming keys and the place, never a value"
  nogo107 "107-3$pl107'" "NO-GO (NS-G1): " "$O107" "$D94_107" "$P106"
  mutant107 "ns-no-$pl107" "ns$pl107" -- --check
  check "$(has "$MUT107" 'NO-GO (NS-G1)')|$(has "$MUT107" "  $V107 (check): GO — stage A holds.")|$MUTRC107" "no|yes|0" \
    "107-3$pl107\" a weakened copy that leaves $pl107 out of NS-G1's judged places reads GO over the same plant — caught"
  check "$(sd106 "$(fxb107 "$pl107" "on-$pl107")" 0 fixture)|$(nskept107)|$(snapeq107)" "true|0|same" "107-3$pl107* the plant put back, the file byte for byte"
done
# W.1's switch in p: a ladder setting, not an allKeys() key — LK-G1 counts it set and holds nothing, and NS-G1 refuses it
X1="$(fxs107 p w1-p '{"ai_burst_merge_enabled":"1"}')"
D94_107="$(data94)"; fresh107; O107="$(run107c --check)"
check "$(sd106 "$X1" 0 fixture)|$(has "$O107" 'NO-GO (NS-G1): ai_burst_merge_enabled in p reads on')|$(has "$O107" 'no value is read out')|$(has "$O107" 'NO-GO (LK-G1)')" "true|yes|yes|no" \
  "107-3w W.1's switch on in p (a FIXTURE plant, '1'): NS-G1 refuses it, naming the key and the place, never a value — LK-G1 holds nothing, since W.1's switch is a ladder setting and LK-G1 judges allKeys() alone (S:7511)"
nogo107 "107-3w'" "NO-GO (NS-G1): " "$O107" "$D94_107" "$P106"
check "$(sd106 "$(fxb107 p w1-p)" 0 fixture)|$(nskept107)|$(snapeq107)" "true|0|same" "107-3w* put back"
# 107-3o — off and set, never judged on: K 0, Q 'off' and W.1 '0' in k (§K's own pre-rollback step leaves '0'); K and W.1 on in
# the store row alone (no reader reads it); the texts set: two in k, W.1's window in the store row. GO, with each note.
X1="$(fxs107 k off-k '{"quoted_no_prices_enabled":0,"connection_quote_enabled":"off","ai_burst_merge_enabled":"0","quoted_reply_text":"RH107SECRET- a quoted reply","no_ai_alert_cooldown_hours":"6"}')"
X2="$(seed107 "$PD" '[{"op":"store_keys107","keys":{"quoted_no_prices_enabled":"1","ai_burst_merge_enabled":"1","ai_burst_merge_window_minutes":5}}]')"
fresh107; O107="$(run107c --check)"
check "$(sd106 "$X1" 0 fixture)|$(sd107 "$X2" fixture)|$(checkgo107 "$O107")|$(has "$O107" '  ok    NS-G1 quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1) read off in every place their readers read')" \
      "true|1|yesnono0|0|0|samekey|0|yes" \
  "107-3o '0', 0 and 'off' present in k read off: NS-G1 ok, the --check GO with nothing changed (FIXTURE plants in k and in the store row)"
check "$(has "$O107" 'NS-G1 the store row holds quoted_no_prices_enabled in s; ai_burst_merge_enabled in s reading on — reported, never judged')|$(has "$O107" 'NS-G1 the texts and W.1'"'"'s window, set per place, by key name')|$(has "$O107" 'quoted_reply_text in k; no_ai_alert_cooldown_hours in k; ai_burst_merge_window_minutes in s')" \
      "yes|yes|yes" \
  "107-3o' the store row alone: reported, never judged; the texts and W.1's window noted per place by key name, no_ai_alert_cooldown_hours among them — never a value"
X3="$(seed107 "$PD" '[{"op":"store_keys107_undo","keys":["quoted_no_prices_enabled","ai_burst_merge_enabled","ai_burst_merge_window_minutes"]}]')"
check "$(sd106 "$(fxb107 k off-k)" 0 fixture)|$(sd107 "$X3" fixture)|$(nskept107)|$(snapeq107)|$(bookdig107)" "true|1|0|same|$B3_107" "107-3o* put back: the file byte for byte, the store row's keys taken out (the book's digest as before 107-3)"
# 107-3c — the rule's positive control: a weakened copy whose off values include '1' — the in-memory '1' then reads off, and NS-G1
# refuses NOT ESTABLISHED, nothing planted
anc107 nsctl "\$nsOff = function (\$v): bool { return \$v === null ||" "\$nsOff = function (\$v): bool { return \$v === '1' || \$v === null ||"
mutant107 ns-ctl nsctl -- --check
check "$(has "$MUT107" "NO-GO (NS-G1): NOT ESTABLISHED — THE SWITCH RULE'S POSITIVE CONTROL DID NOT HOLD (nsctl=bad)")|$MUTRC107" "yes|1" \
  "107-3c a weakened copy that counts '1' as off: the in-memory control fails and NS-G1 refuses NOT ESTABLISHED — the control has teeth"
# 107-3b — NS-B1: Q turned on in k while the script holds at Type DEPLOY (a FIXTURE writer, said so: 5.18.106's tools refuse the key).
D94_107="$(data94)"
fxsh107() { local o; o="$(fxs107 "$@")"; printf '%s\n' "$o" > "$R107/logs/fxsh-$2.json"; case "$o" in '{'*) return 0 ;; *) return 1 ;; esac; }   # fxs107 as a hold's ACTION: non-zero unless the seed answered JSON (never none(…))
csb1run107 107-3b "$DEPLOY107" -- fxsh107 k nsb1 '{"connection_quote_enabled":"1"}'
# deploy-5.18.107.sh: ns_check NS-B1 in qf_keep_and_baseline, before LK-B1's comparison and the copy
check "$HOLDSAW107|$HOLDRC107|$(has "$HOLDOUT107" 'NO-GO (NS-B1): connection_quote_enabled in k reads on')|$(has "$HOLDOUT107" 'it was turned on during this run, after stage A. Nothing was copied.')|$(has "$HOLDOUT107" 'NO-GO (LK-B1)')|$(has "$HOLDOUT107" '  copy            at ')|$(rc107)|$(live)|$(installed107 "$B107F")" \
      "yes|0|yes|yes|no|no|1|$P106|0" \
  "107-3b NS-B1: Q on in k after stage A (a FIXTURE plant at Type DEPLOY), refused before LK-B1's comparison and before the copy — 'Nothing was copied', exit 1, $P106 serving, every file $V107 changes still $BV107's"
tidy107 107-3b
check "$(sd106 "$(fxb107 k nsb1)" 0 fixture)|$(nskept107)|$(data94)|$(states107)|$(backups107)" "true|0|$D94_107|0|0" \
  "107-3b* put back: the data folder's digest as before the run; the refused run's state file and LK key moved into its case folder, so the next deploy is a first deploy"
# 107-3t — stage A's settings-text note (A3): the planted RH107SECRET- text reads NOT v9 (107-pre6's --check); the v9 branch shown
# once by a READER CONTROL — a copy whose recorded prefix is the planted text's — never a production reading
TX107="$(sd107 "$SETTXT107" sha)"
anc107 satxt 'SA_TXT_V9="fca936af7760"' "SA_TXT_V9=\"${TX107:0:12}\""
check "$(has "$OA2_107" 'NOT v9 as the workers read it')|${#TX107}" "yes|16" \
  "107-3t the settings text (planted, RH107SECRET-) reads NOT v9 as the workers read it, in capitals (107-pre6's --check) — never the text"
mutant107 sa-v9 satxt -- --check
check "$(has "$MUT107" "v9 as the workers read it — its sha256 prefix is the recorded ${TX107:0:12}")|$(has "$MUT107" 'NOT v9 as the workers read it')|$MUTRC107" "yes|no|0" \
  "107-3t' a READER CONTROL (a copy pinning the planted text's prefix, one line differing): the same reading says v9 — the branch exists and the comparison is live"
# 107-3g — StaffJobsGate::applies false. Not reachable on a live book: a profile other than Uganda in the settings files (k is
# merged last, and the sandbox's kyc_config.json says uganda) makes A23.7 refuse first — the pin's cashOnlyRuleInForce() reads
# the same gate over a copy of the same three files (S:2853–2858, S:13442) — and A23 runs before qf_stage, SA's only caller
# (S:8566). So, three readings: the real one says Uganda (107-pre6's --check); a READER CONTROL — a copy whose SA reading
# returns ug=0, nothing planted, as 107-3t' does for the text — shows the capitals note with the --check still GO; and the live
# plant in k (a FIXTURE: the file kept whole by keep106, one key rewritten in place, owner and mode kept, put back byte for byte
# by back106) shows the refusal at A23.7, nothing changed, SA never reached.
anc107 sjg0 "\$o[] = 'ug=' . (StaffJobsGate::applies(\$c, \$dir) ? 1 : 0);" "\$o[] = 'ug=0';"
check "$(has "$OA2_107" 'SA StaffJobsGate::applies reads Uganda as the workers read it (PluginConfig::read)')|$(has "$OA2_107" 'SA STAFFJOBSGATE::APPLIES IS FALSE')" "yes|no" \
  "107-3g the real reading (107-pre6's --check): SA reads StaffJobsGate::applies as Uganda — never the capitals"
mutant107 sa-sjg0 sjg0 -- --check
check "$(has "$MUT107" "SA STAFFJOBSGATE::APPLIES IS FALSE ON THIS BOOK'S SETTINGS, AS THE WORKERS READ THEM")|$(has "$MUT107" 'SA StaffJobsGate::applies reads Uganda')|$(checkgo107 "$MUT107")" "yes|no|yesnono0|0|0|samekey|0" \
  "107-3g' a READER CONTROL (a copy whose SA reading returns ug=0, nothing planted): SA's note in capitals, and the --check still GO — a note, never a refusal"
SJG0_107="$(keep106 t107-sjg "$DATA/kyc_config.json")"
SJG1_107="$(python3 -I -c 'import json, sys
p = sys.argv[1]; d = json.load(open(p))
assert isinstance(d, dict) and d.get("tenant_profile") == "uganda"
d["tenant_profile"] = "south-sudan"; open(p, "w").write(json.dumps(d)); print("planted")' "$DATA/kyc_config.json" 2>/dev/null || echo refused)"
D3G_107="$(data94)"; fresh107; O107="$(run107c --check)"
check "$( [ "$SJG0_107" != absent ] && echo kept)|$SJG1_107|$(has "$O107" 'SA STAFFJOBSGATE')|$(has "$O107" 'SA StaffJobsGate')" "kept|planted|no|no" \
  "107-3g\" South Sudan's profile in k, the file read last (a FIXTURE; it said uganda): SA is never reached"
nogo107 "107-3g\"'" "NO-GO (A23.7): rule A is NOT in force on this server's own configuration, as the pin's code reads it" "$O107" "$D3G_107" "$P106"
check "$(back106 t107-sjg)|$(nskept107)|$(snapeq107)" "same|0|same" "107-3g* put back: kyc_config.json byte for byte"
check "$(ancc107)|$(snapeq107)|$(bookdig107)|$(ledger107)|$(live)" "0|$(ls "$AN107"/*.a | wc -l | tr -d ' ')|-|same|$B3_107|mig092=0 wa_no_ai=none|$P106" \
  "107-3z the exit: 107-pre's state — every key absent again (the files byte for byte, the book's digest), no 092, $P106 serving; every anchor so far the script's own text, once"
