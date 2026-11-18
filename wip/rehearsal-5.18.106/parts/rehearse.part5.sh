# ══════════════════════════════════════════════════════════════════════════════════════
# 5.18.106's rehearsal, part 5 — 106-6 (the typed rollback's cases) and 106-7 (redeploys, V1's automatic rollback with its
# put-back made to fail, and the offer to turn followup_auto_send back on), as the rehearsal's design (18 Nov, v2) §C and §D
# set them. It takes the state 106-5 leaves (106-5z): 5.18.106 (bf29cbd) serving from D6's --redeploy, followup_auto_send
# on, no claim switch, followup_enabled on, followup_max_age_hours 336, J3's pair waiting, J1's two fresh refused drafts
# approved, the latest rollback record D6's typed one (AS_RB1=written).
#   Every typed rollback that is to read PASSED is rbx106 (its quarantine --check inside). Every other rollback — a weakened
# copy's, one that FAILs by design, V1's own, --rollback with nothing to roll back — is followed by qcheck106, and so is every
# 5.18.106 deploy whose copy no rollback followed (V1 with its put-back made to fail): A14 of the next deploy needs a --check
# begun after every copy of this script, now that COPY_AT and RB_COPY_AT are on the clock (A1; Part 1's open point 1).
# Master runs only through master106 and, once, sender106 (106-6s): 5.18.106's sender in the window, after 6a's rollback
# has read J3's pair and its redeploy has copied 5.18.106 again.
#   The production writers this part runs, each as the book's owner through the stand-in docker, under the clock: set_config
# (setcfg106; setclr106 for --clear), set_evolution (setevo106 — --clear-key and --key -, a key the store row holds and the
# file lacks, 6a; --url, a key the file holds and the row lacks, 6b, 7d, 7f), the switch tool (on chase, 6f; CS-G1's off
# command as printed, 7a). The FIXTURES — states no production writer makes — each said so where it is planted, and undone
# with its undo asserted: '3' against 3 (6c); an undecodable kyc_config.json and followup_auto_send in <data>/config.json
# (6d); the store-only offers keys (6f); and, where a production writer's side effect has to be taken back, kyc_config.json
# and the vault put back byte for byte from a copy kept first (keep106/back106).
#   This part's number of 106's range: +256700106020 (ph106 20), the planted quote_company_phone (6b) — in NAMES104 and
# FORMS106 since 106-infra f, so every sweep reads it; 21–59 are this part's and unused. Its one planted text, the
# quote_company_email RH106SECRET-quote@example.test, carries NM106F's marker. Neither may reach a log a sweep reads: 6b
# asserts it of the owner's command's own log; set_config's and set_evolution's outputs go to $R106/setcfg.txt and
# $R106/setevo.txt, which no sweep reads.
#   Order: 106-6 runs 6a, the sender (6s), 6b, 6e (6b's last step — the rollback run again from a redeploy — IS 6e's case),
# 6c, 6d, 6f; 106-7 runs 7a–7d, 7h, then 7e–7g and 7i — 7h's PASSED deploy with the key on is the redeploy 7e begins from,
# which saves a rollback and a redeploy. The design's "typed rollback → --redeploy → --value 1 → V1" (7e, 7f) is run as
# typed rollback → the owner's --value 1 → the V1 deploy: a deploy while 5.18.106 serves is an --after-only (S:12365), so V1
# can fire only on a deploy over 7835906, and only a key on before it lets AS-RB1 write. 6c and 6d answer the rollback's
# question no: the write they show cannot happen is AS-RB0's to say before ROLLBACK, and a declined rollback changes nothing,
# so no redeploy follows either.
# ══════════════════════════════════════════════════════════════════════════════════════
echo; echo "== 106-6. the typed rollback's cases: a key only the store row holds (AS-RB1 writes), one only the file holds (it cannot: the owner's command; AC3 caught), already off (RBN caught), '3' against 3, an undecodable file, step 6 on with the offers keys — and between them 5.18.106's sender in the window =="
# ── This part's helpers (none is defined in Parts 1–4; none redefines one of theirs). ──
pls106() { sed -e 's/^k:/kyc_config.json /' -e 's/,s:/, its store row /' -e 's#,p:#, <plugin>/data/config.json #' -e 's#,d:#, <data>/config.json #' <<<"$(kv106 "$1" asplaces)"; }   # pls106 KYCPLACES-LINE — asplaces= in as_places_say's own words (deploy-5.18.106.sh:8679)
ascmd106() {   # ascmd106 0|1 COMMAND — form when COMMAND (a printed line, its six spaces off) is the owner's own command for
               # followup_auto_send as as_owner_cmd builds it (deploy-5.18.106.sh:12333-12337; LOGW :1563): set_config as the book's
               # owner on the data folder (its container path not assumed, p4-open 11), --value V, the output cut where the
               # settings listing begins, its own log; else other
  case "$2" in "docker exec -u $(owner104) -e DN_DATA_DIR="*" ucrm php $IN_CONTAINER/tools/set_config.php --key followup_auto_send --value $1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW106 "autosend-$1")") echo form ;; *) echo other ;; esac
}
half106() {   # half106 OUTPUT before|after PREFIX — the lines before the first one beginning PREFIX, or from it on (bytes)
  python3 -I -c 'import os, sys
w, p = sys.argv[1], os.fsencode(sys.argv[2]); ls = sys.stdin.buffer.read().split(b"\n")
i = next((k for k, l in enumerate(ls) if l.startswith(p)), len(ls))
sys.stdout.buffer.write(b"\n".join(ls[:i] if w == "before" else ls[i:]))' "$2" "$3" <<<"$1"
}
rd106() {   # rd106 close ID… | drafts FU | sends FU — read-only, the book's WAL folded in first: close → ID:<close_reason|open|nofu>,…;
            # drafts → the follow-up's drafts by status, sorted, comma-joined; sends → its followup_sends rows
  dwal106
  python3 -I - "$DATA/plugin.sqlite3" "$@" <<'PY'
import sqlite3, sys
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    m, a = sys.argv[2], sys.argv[3:]
    if m == 'close':
        o = []
        for x in a:
            r = c.execute('SELECT closed_at, close_reason FROM followups WHERE id = ?', (int(x),)).fetchone()
            o.append('%s:%s' % (x, 'nofu' if r is None else ('open' if r[0] is None else (r[1] or '-'))))
        print(','.join(o) or '-')
    elif m == 'drafts':
        print(','.join(str(x[0]) for x in c.execute('SELECT status FROM followup_drafts WHERE followup_id = ? ORDER BY status', (int(a[0]),)).fetchall()) or '-')
    elif m == 'sends':
        print(c.execute('SELECT COUNT(*) FROM followup_sends WHERE followup_id = ?', (int(a[0]),)).fetchone()[0])
    else:
        print('unread:mode')
except (sqlite3.Error, ValueError, IndexError) as x:
    print('unread:%s' % type(x).__name__)
finally:
    c.close()
PY
}
kyceq106() {   # kyceq106 KEPT — <data>/kyc_config.json against a copy kept of it, key by key and by type, followup_auto_send aside
               # (AS-RB1's one write): same, or differs:<key names> — never a value
  python3 -I - "$DATA/kyc_config.json" "$1" <<'PY'
import json, sys
try:
    a = json.load(open(sys.argv[1], encoding='utf-8')); b = json.load(open(sys.argv[2], encoding='utf-8'))
    d = sorted(k for k in set(a) | set(b) if k != 'followup_auto_send' and (k not in a or k not in b or type(a[k]) is not type(b[k]) or a[k] != b[k]))
    print('same' if not d else 'differs:' + ','.join(d)[:200])
except (OSError, ValueError, TypeError, AttributeError) as x:
    print('unread:%s' % type(x).__name__)
PY
}
setevo106() {   # setevo106 [--key-from KEPT] ARG… — the INSTALLED tools/set_evolution.php as the book's owner on the live data folder,
                # under the clock, no proxy (its status asks the stand-in Evolution what instances it has: loopback only) — the
                # form of setcfg106; with --key-from, the evo_api_key a kept copy of kyc_config.json holds piped to its stdin (--key -:
                # never an argument, never printed, never in a variable). Its output kept in $R106/setevo.txt (no sweep reads it),
                # its exit status in $R106/setevo.rc (sevrc106)
  local kf="" o
  if [ "${1:-}" = --key-from ]; then kf="$2"; shift 2; fi
  if [ -n "$kf" ]; then
    o="$(python3 -I -c 'import json, sys; sys.stdout.write(str(json.load(open(sys.argv[1], encoding="utf-8")).get("evo_api_key", "")))' "$kf" \
          | env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' \
              bash "$SB/bin/docker" exec -i -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php "$IN_CONTAINER/tools/set_evolution.php" "$@" 2>&1; exit "${PIPESTATUS[1]}")"
  else
    o="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' \
          bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php "$IN_CONTAINER/tools/set_evolution.php" "$@" 2>&1 </dev/null)"
  fi
  echo "$?" >> "$R106/setevo.rc"; printf '%s\n' "$o" >> "$R106/setevo.txt"; printf '%s' "$o"
}
sevrc106() { tail -n1 "$R106/setevo.rc" 2>/dev/null || echo none; }
setclr106() {   # setclr106 KEY — set_config --key KEY --clear (the key out of the file and the store row: saveOverrides with '',
                # tools/set_config.php:375, :414), as setcfg106 runs --value; its output and exit status kept beside setcfg106's
  local o
  o="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
        php "$IN_CONTAINER/tools/set_config.php" --key "$1" --clear 2>&1 </dev/null | sed '/^  AI SETTINGS$/,$d'; exit "${PIPESTATUS[0]}")"
  echo "$?" >> "$R106/setcfg.rc"; printf '%s\n' "$o" >> "$R106/setcfg.txt"; printf '%s' "$o"
}
rsl106() { ls -1 "$OUT106"/rollback-state-*.env 2>/dev/null; }   # rsl106 — the rollback records in the run folder, one a line
newrec106() { local r; r="$(rsl106 | grep -vxF -f <(printf '%s\n' "$1") | tail -1)"; echo "${r:-none}"; }   # newrec106 LIST — the record a run wrote beside LIST (rsl106 before it), or none
v1run106() {   # v1run106 LABEL [lock] — V1's automatic rollback (design-v2 A7): .redirect106 armed (with "lock", .redirect106lock beside
               # it: spent by the same request, it creates the clone's .git/index.lock, after the deploy's own copy and checkout
               # back), then 5.18.106 deployed over 7835906, DEPLOY typed, under the clock (--redeploy: its added files are on disk).
               # One check: V1's FAIL on the one redirect (deploy-5.18.106.sh:13911), ROLLING BACK (:13923), exit status 1 (:1688),
               # .redirect106 spent, .redirect106lock spent exactly when armed, the index lock there exactly when armed; armed, the
               # lock then removed (unlock106; cleanup106 also removes it at exit) and asserted gone. Output in V1OUT106 — call it
               # directly. The quarantine's --check after it is the caller's, once the case's own readings are taken
  local lab="$1" lk="${2:-}" o
  arm106 $lk
  fresh106; o="$(run106c --answer DEPLOY --redeploy)"; V1OUT106="$o"; V1RC106="$(rc106)"
  check "$(failline "$o" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$o" '  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept; followup_auto_send set off first, AS-RB1)')|$V1RC106|$(spent99 .redirect106)|$( [ -n "$lk" ] && spent99 .redirect106lock || echo -)|$(lock106)" \
        "yesyes|1|spent|$( [ -n "$lk" ] && echo spent || echo -)|$( [ -n "$lk" ] && echo present || echo absent)" \
    "$lab a deploy whose public sign-in page redirects once (the stand-in's $( [ -n "$lk" ] && echo '.redirect106 with .redirect106lock' || echo '.redirect106'), planted, spent once): V1 FAILs on the one redirect (S:13911) and the deploy rolls itself back, AS-RB1 first (S:13923); exit status 1$( [ -n "$lk" ] && echo "; the same request made the clone's index lock (A7), so the put-back's own checkout refuses")"
  if [ -n "$lk" ]; then check "$(unlock106)" "absent" "$lab' the clone's index lock removed (unlock106; cleanup106 removes it at exit too) — gone"; fi
  return 0
}
AS0OFF106="  note  AS-RB0 followup_auto_send already reads off: the rollback writes nothing (AS-RB1 reads it again, and writes only a key that reads on), whatever §L.7's five conditions say"   # deploy-5.18.106.sh:8925
AS0OK106="  note  AS-RB0 followup_auto_send reads on and §L.7's five conditions hold: after ROLLBACK is typed, before the put-back, the rollback sets it off — '0' in <data>/kyc_config.json through the plugin's own writer, the five conditions recomputed in the same process just before; every other key of the file and of the store row kept (AS-RB1)"   # :8927
F500_106="  note  AS-RB0 no open enquiry follow-up holds two or more approved drafts (F5's count)"   # :8885
TRB106="  Type ROLLBACK to put $BV106 ($B106) back over live $P106 — "   # :13881, the prompt; what AS-RB0 says it will write follows
OFFER106="  followup_auto_send reads off: the latest rollback set it off (AS_RB1=written in its record; its copy at "   # :8839, as_back_on's offer
EVS106=0   # the stand-in Evolution's requests made by set_evolution's own status in this part (fetchInstances), for 106-10
EVF106="${EVO_URL%/*}/rh106-file-only"   # the file-only key's value: a base URL on the stand-in Evolution itself, never another host

# ── 106-6pre: what 106-5z hands over, asserted again before anything here moves it ──
dpre106 "106-6pre0" "$P106" on - "on:$LIM106" "written:offer-if-off"
J1A6_106="$(cut -d' ' -f1 <<<"$J1FU106")"; J1B6_106="$(cut -d' ' -f2 <<<"$J1FU106")"
dwal106
check "$(apd106)|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)|$(rd106 close "$J1A6_106" "$J1B6_106" "$J3FU106")|$(grep -c . "$SB/evo/rh106-refuse.list" 2>/dev/null)|$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(lock106)|$(ls -A "$SB/web" | grep -c '^\.redirect106')|$(sget106 "$RBX106_REC" AS_RB1)|$(case "$EVF106" in http://127.0.0.1:*/rh106-file-only) [ "$EVF106" != "$EVO_URL" ] && echo loopback ;; *) echo other ;; esac)" \
      "n=4 fu=$J1A6_106,$J1B6_106,$J3FU106,$J3FU106|1/2|$J1A6_106:open,$J1B6_106:open,$J3FU106:open|3|none|absent|0|written|loopback" \
  "106-6pre1 the state 106-5 hands over (106-5z): the approved drafts in the sender's order are J1's two fresh refused ones, then J3's pair — nothing sent since the jam (F5's count 1/2); J1's two and J3 open; the stand-in's refusal list still J1's three; no uj trigger, no index lock, no V1 trigger; D6's typed rollback the latest record (AS_RB1=written); the file-only key's URL this part plants is the stand-in Evolution's own host"
# The weakened copies of this part, each anchored on the script's own text (106-infra's anc106/weak106; ancc106 the control of the
# controls): AC3 — AS_PHP's condition 3 taken out (deploy-5.18.106.sh:8608, the line read from the script), at 6b; RBN — the RB
# note's AS-RB1 case made "written" whatever AS-RB1 recorded (:15803), at 6e; BOV — as_back_on's V1 guard removed (:8829, the line
# read from the script), at 7e and 7f.
AC3L106="$(grep -m1 -F "if (!array_key_exists(\$k, \$row) || \$row[\$k] !== \$v) \$r['differ'][] = \$asName(\$k);" "$DEPLOY106")"
anc106 ac3 "$AC3L106" "            // rh106 AC3: condition 3 removed — the file's keys never compared with the store row's"
anc106 rbn '$( case "${AS_RB1:-}" in written) echo "nothing else moved but followup_auto_send:' '$( case "written" in written) echo "nothing else moved but followup_auto_send:'
BOVL106="$(grep -m1 -F "# this deploy's V1 wrote it, may have, or read it on (review 2)" "$DEPLOY106")"
anc106 bov "$BOVL106" "  :   # rh106 BOV: as_back_on's V1 guard removed"
check "$(ancc106)|$(grep -c . <<<"$AC3L106")$(grep -c . <<<"$BOVL106")" "0|$(ls "$AN106"/*.a | wc -l | tr -d ' ')|-|11" \
  "106-6anc the anchors of this part's weakened copies (AC3, RBN, BOV), with every earlier one: each the script's own text, found in it exactly once, each replacement different; AC3's and BOV's lines read from the script"

echo; echo "== 106-6a. a key the store row holds and kyc_config.json lacks (set_evolution --clear-key's state): J3's pair in F5's capitals; AS-RB1 writes all the same =="
dpre106 "106-6a0" "$P106" on - "on:$LIM106" "written:offer-if-off"
# The writer: tools/set_evolution.php --clear-key, the INSTALLED tool (bf29cbd tools/set_evolution.php:144-169) —
# PluginConfig::clearEvolutionKey (lib/PluginConfig.php:257-266) takes evo_api_key out of <data>/kyc_config.json alone,
# through writeKycFile, and copies nothing into the store row: saveOverrides' mirrorToStore (:308-358) is the one writer that
# copies the file into the row, and the tools that set a key in the file alone (saveEvolutionCredentials, saveAiSettings) leave
# the row lacking one (6b's case), never holding one. So the key the row holds stays there alone — root docs/75:727's
# "production's normal state". Chosen over the other production writer of that state, a settings screen's own
# $store->save('kyc_config.json', …) (includes/post/post_admin.php:749): that needs a signed-in staff POST to the INSTALLED
# public.php, which no server serves in 106 (A2: master's piggyback rides on it). Its side effect: ConfigVault::store with
# an empty value forgets the vault's copy too (tools/set_evolution.php:148-160). The undo, after the rollback (so AS-RB1's
# write stays): the key put back through the same tool's own writer (--key -, the value from the copy kept first on its stdin,
# never an argument and never printed; --force-short, the chain's key being shorter than the tool's 16), and the vault byte for
# byte from its copy — both asserted. Its status at the end asks the stand-in Evolution for its instances (none come back:
# exit status 1, set_evolution.php:209 or :219), counted in EVS106.
K6A0_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key)"; CF6A0_106="$(q106 cfgsha "$PD" "$DATA")"
KK6A106="$(keep106 p5-k6a "$DATA/kyc_config.json")"; KV6A106="$(keep106 p5-v6a "$VAULT")"
E6A0_106="$(evo_calls)"; O6A_106="$(setevo106 --clear-key)"; RC6A_106="$(sevrc106)"; E6A1_106=$(( $(evo_calls) - E6A0_106 )); EVS106=$(( EVS106 + E6A1_106 ))
K6A1_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key,followup_auto_send)"
# tools/set_evolution.php:165 (removed, the vault's copy with it), :156 (removed, the vault's copy could not be forgotten)
check "$(kv106 "$K6A0_106" keys)|$(kv106 "$K6A0_106" pre13)|$( [ "$KK6A106" != absent ] && echo kept)|$(( $(grep -cxF "  Key override removed, and the vault's copy with it." <<<"$O6A_106") + $(grep -cxF "  The override was removed but the vault still holds a copy:" <<<"$O6A_106") ))|$RC6A_106|$(kv106 "$K6A1_106" keys)|$(kv106 "$K6A1_106" pre13)|$(kv106 "$K6A1_106" asplaces)|$( [ "$(kv106 "$(q106 cfgsha "$PD" "$DATA")" s)" = "$(kv106 "$CF6A0_106" s)" ] && echo row-same)|$( [ "$E6A1_106" -le 1 ] && echo le1)" \
      "evo_api_key@ks,ss|ok|kept|1|1|evo_api_key@ss;followup_auto_send@ks,ss|ok|k:on,s:on,p:-,d:-|row-same|le1" \
  "106-6a1 the state planted through the installed set_evolution.php --clear-key as the book's owner (the file and the vault kept first, $KK6A106/$KV6A106): before, evo_api_key in the file and its store row, agreeing; after, in the store row alone — the store row's bytes as they were (no mirror), §L.7's condition 3 still holding (it compares the file's keys with the row, never the row's with the file), followup_auto_send on in both; exit status 1 (the stand-in Evolution lists no instance)"
F56A106="$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)"
rbx106 "106-6a2"
O="$RBX106_OUT"; K6A2_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key,followup_auto_send)"
# deploy-5.18.106.sh:8886 (F5's capitals), :8927 (on, the five conditions holding), :13881 (the prompt), :9032 (AS-RB1),
# :9048 (the row reads '0'), :9137 (AS-RB2), :7892 (LM-RB1), :15803 (RB's note, its "written" case)
check "$F56A106|$(grep -cxF "  note  AS-RB0 ${F56A106%/*} OPEN ENQUIRY FOLLOW-UP(S) HOLD TWO OR MORE APPROVED DRAFTS (${F56A106#*/} IN ALL): $BV106'S SENDER WILL SEND EACH OF THOSE FOLLOW-UPS' APPROVED DRAFTS IN ONE PASS — $V106 SENDS ONE AND EXPIRES THE REST (F5). WITH followup_auto_send OFF NO NEW DRAFT IS APPROVED, BUT THESE ARE APPROVED ALREADY" <<<"$O")|$(grep -cxF "$AS0OK106" <<<"$O")|$(has "$O" "${TRB106}followup_auto_send set off first (AS-RB1), every other switch of the sales follow-up kept as it is — anything else to stop: ")|$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (typed)' 'every other key of the file and of the settings row as it was')$(noteline "$O" AS-RB1 "the settings row reads '0' too")$(okline "$O" AS-RB2 "followup_auto_send reads off through $BV106's code ($B106, installed)" '; AS-RB1 set it before the put-back')|$(okline "$O" LM-RB1 "5.18.105's masked lines still in place under $BV106, counted as blocks")|$(noteline "$O" RB "the code is $BV106's again where $V106 changed it — the four follow-up jobs and lib/FollowUpService.php — and nothing else moved but followup_auto_send: the rollback ran no switch tool and no off-hook, applied or removed no migration, and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: set off first, AS-RB1; root docs/75 §L.7)")|$(sget106 "$RBX106_REC" AS_RB1)|$(kv106 "$K6A2_106" keys)|$(kv106 "$K6A2_106" asplaces)" \
      "1/2|1|1|yes|yesyesyes|yes|yes|written|evo_api_key@ss;followup_auto_send@ks,ss|k:off,s:off,p:-,d:-" \
  "106-6a2'' the typed rollback over it: J3's pair named in AS-RB0's capitals with F5's count (1 follow-up, 2 drafts, S:8886); AS-RB0 reads on and the five conditions holding (S:8927), and the prompt says so; AS-RB1 wrote followup_auto_send '0' (S:9032) — the key the row alone holds did not stop it — the row reading '0' too (S:9048); AS-RB2 off through $BV106's code (S:9137); LM-RB1 masked (S:7892); RB: nothing else moved but followup_auto_send (S:15803); its record AS_RB1=written; evo_api_key still in the row alone"
# The undo (A9): the key back in the file through the tool's own writer, the vault byte for byte — 7835906 serving, its tool
E6A2_106="$(evo_calls)"; O6AU_106="$(setevo106 --key-from "$R106/kept/p5-k6a/file" --key - --force-short)"; RC6AU_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E6A2_106 ))
B6AV_106="$(back106 p5-v6a)"; K6AU_106="$(q106 kycplaces "$PD" "$DATA" evo_api_key,followup_auto_send)"
# tools/set_evolution.php:139 (the key stored, its length only), :188 (SAVED)
check "$(grep -cxF '  SAVED' <<<"$O6AU_106")$(grep -c '^    API key  stored, [0-9][0-9]* characters (not shown)$' <<<"$O6AU_106")|$RC6AU_106|$B6AV_106|$(kv106 "$K6AU_106" keys)|$(kv106 "$K6AU_106" pre13)|$(kyceq106 "$R106/kept/p5-k6a/file")|$(kv106 "$K6AU_106" asplaces)|$(live)" \
      "11|1|same|evo_api_key@ks,ss;followup_auto_send@ks,ss|ok|same|k:off,s:off,p:-,d:-|$B106" \
  "106-6a3 the state undone (A9): evo_api_key back in <data>/kyc_config.json through set_evolution.php --key - (its value from the copy kept first, on stdin, never shown), every key of the file as it was before the plant but followup_auto_send (AS-RB1's '0', kept), the file and the row agreeing again; the vault put back byte for byte"
dredeploy106 "106-6a4"
dpre106 "106-6a5" "$P106" off - "on:$LIM106" "written:offer-if-off"

echo; echo "== 106-6s. between the cases, once: 5.18.106's sender in the window — J3's first draft sent, its second expired (F5); J1's refused drafts closed send_failed (F1), never sent again =="
AP6S_106="$(apd106)"; RF6S_106="$(refused106)"; E6S_106="$(evo_calls)"
sender106 "106-6s0 5.18.106's sender, once, in the next sending window" --window
FS6S_106="$(fsl106 "$SS106_OUT")"
# bf29cbd cron/followup_send.php:131 (F5: the follow-up's oldest draft only), :303-309 (F1 closes, nothing sent), :385-389 (the
# send, then expireOtherApproved), :398-406 (its two lines); lib/FollowUpService.php:331-344 (expireOtherApproved)
check "$AP6S_106|$FS6S_106|$(grep -cE '\[followup_send\] enquiry_second_draft=1 enquiry_tries_exhausted=2$' "$SS106_OUT")|$(( $(evo_calls) - E6S_106 ))|$(( $(refused106) - RF6S_106 ))|$(rd106 close "$J1A6_106" "$J1B6_106" "$J3FU106")|$(rd106 drafts "$J3FU106")|$(rd106 sends "$J3FU106")|$(apd106)|$(kv106 "$(q106 u2u3 "$DATA/plugin.sqlite3" "$PD" "$(fnow104)")" f5_multi)" \
      "n=4 fu=$J1A6_106,$J1B6_106,$J3FU106,$J3FU106|1:3:0|1|1|0|$J1A6_106:send_failed,$J1B6_106:send_failed,$J3FU106:open|expired,sent|1|n=0 fu=-|0/0" \
  "106-6s1 the pass over the four approved drafts: sent=1 held=3 failed=0, the second draft held (enquiry_second_draft=1) and J1's two closed by F1 (enquiry_tries_exhausted=2) — J1's two closed send_failed with no send (the stand-in refused nothing more though their numbers are still listed: F1, not a refusal, closed them); one sendText for J3, its follow-up open, one draft sent and the other expired, one send recorded; no approved draft left, F5's count 0/0"
check "$(refuse106)|$( [ -e "$SB/evo/rh106-refuse.list" ] && echo present || echo gone)" "0|gone" \
  "106-6s2 the stand-in's refusal list (J1's three numbers, a stand-in setting the jam needed) removed now its last use is past (A9) — gone"
master106 "106-6s3"
aft106 "106-6s4" PASSED $L1ON106
# deploy-5.18.106.sh:8036 (UJ-CA since the copy)
check "$(noteline "$AFT106" UJ-CA "F2 closed 0 follow-up(s) whose customer was quiet past the limit (actor sender), F5 expired 0 draft(s) whose attempt was taken, F1 closed 2 refused draft(s), the after-send sibling expiries 1/1 (events/drafts), 1 enquiry follow-up(s) sent")" "yes" \
  "106-6s4' UJ-CA reads the pass since this copy (S:8036): F1 closed 2 refused drafts, the after-send sibling expiry 1/1 (J3's second), 1 enquiry follow-up sent"
doffer106 "106-6s5"

echo; echo "== 106-6b. a key kyc_config.json holds and the store row lacks (set_evolution --url's state): AC3 caught; no write — the capitals notice and the owner's command before ROLLBACK, again after the verdict; run as printed =="
dpre106 "106-6b0" "$P106" on - "on:$LIM106" "written:offer-if-off"
# The company's quotation phone and reply address, planted through set_config (both places) so the owner's command's own log
# can be shown to hold neither (it is cut where the settings listing begins, which prints both)
PH6B106="$(ph106 20)"; EM6B106='RH106SECRET-quote@example.test'
K6B0_106="$(q106 kycplaces "$PD" "$DATA" quote_company_phone,quote_company_email)"
O6B1_106="$(setcfg106 quote_company_phone "$PH6B106")"; R6B1_106="$(setrc106)"; O6B2_106="$(setcfg106 quote_company_email "$EM6B106")"; R6B2_106="$(setrc106)"
K6B1_106="$(q106 kycplaces "$PD" "$DATA" quote_company_phone,quote_company_email)"
# tools/set_config.php:185, :187 (the two keys), :670 ("  <key> = <value>")
check "$(kv106 "$K6B0_106" keys)|$R6B1_106$R6B2_106|$(grep -cxF "  quote_company_phone = $PH6B106" <<<"$O6B1_106")$(grep -cxF "  quote_company_email = $EM6B106" <<<"$O6B2_106")|$(kv106 "$K6B1_106" keys)|$(kv106 "$K6B1_106" pre13)" \
      "quote_company_phone@-;quote_company_email@-|00|11|quote_company_phone@ks,ss;quote_company_email@ks,ss|ok" \
  "106-6b1 quote_company_phone (a number of 106's range) and quote_company_email (a marked address), in neither place before, set through the installed set_config.php as the book's owner: in the file and its store row, agreeing"
# The writer: tools/set_evolution.php --url (bf29cbd :78-99) — PluginConfig::saveEvolutionCredentials (lib/PluginConfig.php:215-237)
# writes evo_api_url into <data>/kyc_config.json alone (writeKycFile, no mirror): the file then holds a value the row lacks.
# Its URL the stand-in's own host on a path of this rehearsal's (http, so --allow-insecure, :88). The file and the vault kept
# first; undone byte for byte from them where nothing writes either between the plant and the undo.
KK6B106="$(keep106 p5-k6b "$DATA/kyc_config.json")"; KV6B106="$(keep106 p5-v6b "$VAULT")"; CF6B0_106="$(q106 cfgsha "$PD" "$DATA")"
E6B3_106="$(evo_calls)"; O6B3_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R6B3_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E6B3_106 ))
K6B3_106="$(q106 kycplaces "$PD" "$DATA" evo_api_url,followup_auto_send)"
# tools/set_evolution.php:98 ("API URL  <url>"), :188 (SAVED), :219 (no instance came back)
check "$(grep -cxF '  SAVED' <<<"$O6B3_106")$(grep -cxF "    API URL  $EVF106" <<<"$O6B3_106")|$R6B3_106|$(kv106 "$K6B3_106" keys)|$(kv106 "$K6B3_106" pre13):$(kv106 "$K6B3_106" differ)|$( [ "$(kv106 "$(q106 cfgsha "$PD" "$DATA")" s)" = "$(kv106 "$CF6B0_106" s)" ] && echo row-same)|$(kv106 "$K6B3_106" asplaces)" \
      "11|1|evo_api_url@ks,ss;followup_auto_send@ks,ss|differ:evo_api_url|row-same|k:on,s:on,p:-,d:-" \
  "106-6b2 the state planted through the installed set_evolution.php --url as the book's owner (the file and the vault kept first, $KK6B106/$KV6B106): evo_api_url in the file now differs from the store row's, which is as it was — §L.7's condition 3 fails on evo_api_url; followup_auto_send on in both"
dpre106 "106-6b3" "$P106" on - "on:$LIM106" "written:offer-if-off"
# AC3 — the weakened copy without condition 3, ROLLBACK typed, on this state: the rollback it runs is real (it puts 7835906
# back), and its AS-RB1 writes where the script's must not — mirroring the whole file into the store row, so the row's
# evo_api_url becomes the file's: its own read-back refuses that (row-others). Caught by 106-6b9 (the capitals notice, no write)
RR6B4_106="$(rsl106)"; MAC3_106="$(weak106 ac3 ac3)"
fresh106; OAC3_106="$(R106_MUTANT_OUT=1 run106c --answer ROLLBACK --script "$MAC3_106" --rollback)"; RCAC3_106="$(rc106)"; rm -f "$MAC3_106"
RAC3_106="$(newrec106 "$RR6B4_106")"; KAC3_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,evo_api_url)"
# deploy-5.18.106.sh:8927 (the five conditions read holding), :9034 (AS-RB1's read-back FAIL), :1683
check "$(has "$OAC3_106" 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3')|$(grep -cxF "$AS0OK106" <<<"$OAC3_106")|$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND WAS WRITTEN, BUT ITS READ-BACK DID NOT HOLD (row-others) — the rollback goes on (it is never blocked by this); AS-RB2 reads it again under $BV106" <<<"$OAC3_106")|$(fails "$OAC3_106")|$RCAC3_106|$(sget106 "$RAC3_106" AS_RB1)|$(kv106 "$KAC3_106" asplaces)|$(kv106 "$KAC3_106" pre13)|$(live)|$( [ -e "$MAC3_106" ] && echo left || echo removed)" \
      "no|1|1|1|1|written-check:row-others|k:off,s:off,p:-,d:-|ok|$B106|removed" \
  "106-6b4 AC3, the copy without §L.7's condition 3 (S:8608), ROLLBACK typed over a key the file holds and the row lacks: no capitals notice, AS-RB0 reads the five conditions holding (S:8927), and its AS-RB1 writes followup_auto_send '0' — the writer mirroring the whole file into the store row, so the row's evo_api_url is now the file's (they agree: the row was changed) — its own read-back FAILs, row-others (S:9034), record written-check:row-others: what condition 3 is there to prevent (caught by 106-6b9); $B106 serving; the copy removed"
qcheck106 "106-6b4'"
# The write undone (A9): kyc_config.json put back from the copy kept before the URL's plant (AC3's '0' and the URL both taken
# back), then followup_auto_send 1 through set_config as the book's owner — the file as it is, mirrored whole into the store row
# (lib/PluginConfig.php:356), which takes back what AC3's write copied there (the planted URL, '0'); the vault byte for byte
B6B5_106="$(back106 p5-k6b)"; O6B5_106="$(setcfg106 followup_auto_send 1)"; R6B5_106="$(setrc106)"; B6B5V_106="$(back106 p5-v6b)"
K6B5_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,evo_api_url,quote_company_phone,quote_company_email)"
check "$B6B5_106|$(grep -cxF '  followup_auto_send = 1' <<<"$O6B5_106")|$R6B5_106|$B6B5V_106|$(kv106 "$K6B5_106" pre13)|$(kv106 "$K6B5_106" asplaces)|$(kyceq106 "$R106/kept/p5-k6b/file")" \
      "same|1|0|same|ok|k:on,s:on,p:-,d:-|same" \
  "106-6b5 AC3's write undone (A9): the file put back from its copy, followup_auto_send set to 1 through set_config as the book's owner (the file mirrored into the row: the row's URL the file's again), the vault byte for byte — every key of the file as before the plant, followup_auto_send on in both"
dredeploy106 "106-6b6"
# the file-only key planted again, on this deploy (the same writer; the file and the vault kept again first)
KQ6B_106="$(kyceq106 "$R106/kept/p5-k6b/file")"
KK6B2_106="$(keep106 p5-k6b2 "$DATA/kyc_config.json")"; KV6B2_106="$(keep106 p5-v6b2 "$VAULT")"
E6B7_106="$(evo_calls)"; O6B7_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R6B7_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E6B7_106 ))
K6B7_106="$(q106 kycplaces "$PD" "$DATA" evo_api_url,followup_auto_send)"
check "$KQ6B_106|$(grep -cxF "    API URL  $EVF106" <<<"$O6B7_106")|$R6B7_106|$(kv106 "$K6B7_106" pre13):$(kv106 "$K6B7_106" differ)|$(kv106 "$K6B7_106" asplaces)" \
      "same|1|1|differ:evo_api_url|k:on,s:on,p:-,d:-" \
  "106-6b7 the file-only key planted again after the redeploy (the file kept first, $KK6B2_106 — every key as the copy 106-6b2 kept, so nothing else moved it): condition 3 fails on evo_api_url again"
dpre106 "106-6b8" "$P106" on - "on:$LIM106" "written-check:row-others:nothing:not-written"
PL6B_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"; KD6B_106="$(digest105)"; RR6B9_106="$(rsl106)"
fresh106; O6B_106="$(run106c --answer ROLLBACK --rollback)"; RC6B_106="$(rc106)"
R6B_106="$(newrec106 "$RR6B9_106")"; C6B_106="$(printed "$O6B_106" "docker exec -u ")"
B6BH_106="$(half106 "$O6B_106" before "  $V106 (rollback): ")"; A6BH_106="$(half106 "$O6B_106" after "  $V106 (rollback): ")"
CA6B_106="condition 3: the file and the store row disagree on evo_api_url"
# deploy-5.18.106.sh:8882 (AS-RB0's reading), :8885 (F5 0/0 after the sender's pass), :8934 (the capitals notice, a command
# allowed), :8724 and :8735 (the owner's text: differ, the command), :13881 (the prompt, AS_RB0_SAY :8930), :9038 (AS-RB1's
# FAIL), :9144 (AS-RB2's note), :16089 (the verdict), :8795 (as_after_verdict), :15803 (RB, its last case)
check "$(order106 "$B6BH_106" "  note  AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): on — per place: " "$F500_106" "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (" "  It reads on (per place: " "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; " "$TRB106" "  FAIL  AS-RB1 " "  note  AS-RB2 ")|$(grep -cxF "  note  AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): on — per place: $PL6B_106; - absent, ? unread; §L.7's five conditions do not hold: $CA6B_106" <<<"$O6B_106")|$(grep -cxF "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF ($CA6B_106): $BV106 WILL THEN APPROVE AND SEND AUTOMATICALLY. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN YOUR OWN COMMAND BELOW, ALONE, THEN RUN THE ROLLBACK AGAIN:" <<<"$O6B_106")|$(grep -cxF "  It reads on (per place: $PL6B_106; - absent, ? unread). The command below also copies <data>/kyc_config.json, whole, into the settings row, so evo_api_url take the file's values there (missing or different in the row now), and it refreshes the vault (a later run's Z1 reads that) — your decision. Your own command, alone, with its own log:" <<<"$O6B_106")|$(has "$O6B_106" "${TRB106}followup_auto_send NOT set off — the rollback cannot write it (above), every other switch of the sales follow-up kept as it is — anything else to stop: ")|$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($CA6B_106) — $BV106 WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed after the verdict" <<<"$O6B_106")|$(grep -cxF "  note  AS-RB2 followup_auto_send reads on under $BV106 — AS-RB1 did not write it (not written: $CA6B_106; its FAIL above): your own command, where its cause allows one, is printed after the verdict" <<<"$O6B_106")" \
      "ordered|1|1|2|yes|1|1" \
  "106-6b9 the rollback over a key the file holds and the row lacks, ROLLBACK typed once — before the question (S:8882-8936): AS-RB0 reads it on and condition 3 failing on evo_api_url, F5's count 0/0 now (the sender's pass sent one of J3's pair and expired the other), then the capitals notice that the rollback cannot set it off, with the owner's own text and command (S:8724, S:8735), then the prompt saying it will not be set off (S:13881); then AS-RB1's FAIL (S:9038) and AS-RB2's note (S:9144)"
check "$(order106 "$A6BH_106" "  $V106 (rollback): 1 FAILED — " "  AS-RB1 DID NOT SET followup_auto_send OFF (not written: $CA6B_106) — UNDER $BV106 IT APPROVES AND SENDS AUTOMATICALLY WHILE IT READS ON:" "  It reads on (per place: " "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; ")|$(grep -cxF "      $C6B_106" <<<"$O6B_106")|$(ascmd106 0 "$C6B_106")|$(fails "$O6B_106")|$RC6B_106|$(live)|$(installed106 "$B106F")|$(sget106 "$R6B_106" AS_RB1)|$(grep -c '^RB_COPY_AT=[0-9]' "$R6B_106" 2>/dev/null)|$( [ "$(digest105)" = "$KD6B_106" ] && echo kept)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)|$(noteline "$O6B_106" RB "and wrote no key (AS-RB1: not written: $CA6B_106; root docs/75 §L.7)")" \
      "ordered|2|form|1|1|$B106|0|not-written:differ|1|kept|k:on,s:on,p:-,d:-|yes" \
  "106-6b9' after the verdict — 1 FAILED, AS-RB1's (S:16089) — as_after_verdict's headline and the same command again, alone (S:8795; RH106-15); that command exactly as as_owner_cmd builds it (S:12333-12337); exit status 1, $B106 serving, every file $V106 changes $BV106's, the record AS_RB1=not-written:differ with the rollback's copy time, every ladder key kept, followup_auto_send still on in both places; RB says no key was written (S:15803)"
qcheck106 "106-6b9''"
# With a wrong container name in place of the printed one, docker's own error reaches the command's log (its 2>&1 before sed)
W6B_106="${C6B_106/ ucrm php / rh106-nosuch php }"
fresh106; O6BW_106="$(run_line106c "" "$W6B_106")"; RC6BW_106="$(rc106)"; LG6BW_106="$(ls -1t "$OUT106"/autosend-0-*.log 2>/dev/null | head -1)"
# the stand-in docker's own answer (scripts/harness/quarantine-stray-store/rehearse.sh:468)
check "$(grep -o ' ucrm php ' <<<"$C6B_106" | grep -c .)|$(grep -cxF 'Error: No such container: rh106-nosuch' "${LG6BW_106:-/nonexistent}" 2>/dev/null)|$(grep -c '^  followup_auto_send = ' "${LG6BW_106:-/nonexistent}" 2>/dev/null)|$RC6BW_106|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|1|0|0|k:on,s:on,p:-,d:-" \
  "106-6b10 the printed command with a wrong container name (the one container name in it replaced): docker's error reaches the command's own log through its 2>&1 ($(basename "${LG6BW_106:-none}")), no saved line, the pipeline's exit status tee's; followup_auto_send still on"
# The file-only key undone (A9) before the owner's command: nothing wrote the file or the vault since the plant (AS-RB1 wrote nothing)
check "$(back106 p5-k6b2)|$(back106 p5-v6b2)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13)" "same|same|ok" \
  "106-6b11 the file-only key undone (A9): kyc_config.json and the vault put back byte for byte from their copies — condition 3 holds again"
# The control on the control: set_config's own output, UNCUT, on a throwaway copy of the data folder (kyc_config.json alone, no
# book, so nothing is mirrored; a vault of its own) shows the planted phone and address — the cut is what keeps them out of the
# owner's log. Held in memory only, never written; the throwaway folder removed
TW6B106="$H106/v/tw6b"; rm -rf "$TW6B106"; mkdir -p "$TW6B106"; cp -p "$DATA/kyc_config.json" "$TW6B106/kyc_config.json"; chown -R 1000:1000 "$TW6B106"; chmod 700 "$TW6B106"
OTW6B106="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$TW6B106" -e "DN_VAULT_FILE=$TW6B106/vault.json" ucrm \
             php "$IN_CONTAINER/tools/set_config.php" --key followup_auto_send --value 0 2>&1 </dev/null)"
rm -rf "$TW6B106"
# tools/set_config.php:670 (the saved line), :267 (the listing's heading), :333 (a text value shown in quotes)
check "$(grep -cxF '  followup_auto_send = 0' <<<"$OTW6B106")|$(grep -cxF '  AI SETTINGS' <<<"$OTW6B106")|$( [ "$(grep -cF -- "\"$PH6B106\"" <<<"$OTW6B106")" -ge 1 ] && echo phone)|$( [ "$(grep -cF -- "\"$EM6B106\"" <<<"$OTW6B106")" -ge 1 ] && echo address)|$( [ -e "$TW6B106" ] && echo left || echo gone)|$(cmp -s "$DATA/kyc_config.json" "$R106/kept/p5-k6b2/file" && echo live-same)" \
      "1|1|phone|address|gone|live-same" \
  "106-6b12 the control on the control: set_config's output uncut, on a throwaway copy of kyc_config.json, prints the settings listing with the planted phone and address in it — so the owner's cut is what keeps them out of its log; the throwaway removed, the live file untouched"
fresh106; O6BR_106="$(run_line106c "" "$C6B_106")"; RC6BR_106="$(rc106)"; LG6BR_106="$(ls -1t "$OUT106"/autosend-0-*.log 2>/dev/null | head -1)"
K6BR_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
check "$(grep -cxF '  followup_auto_send = 0' <<<"$O6BR_106")|$(grep -c 'AI SETTINGS' <<<"$O6BR_106")|$RC6BR_106|$(grep -cxF '  followup_auto_send = 0' "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(grep -c 'AI SETTINGS' "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(grep -cF -e "${PH6B106: -9}" -e "$EM6B106" -e 'RH106SECRET-' "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(grep -cF -e "${PH6B106: -9}" -e "$EM6B106" <<<"$O6BR_106")|$(stat -c %a "${LG6BR_106:-/nonexistent}" 2>/dev/null)|$(kv106 "$K6BR_106" asplaces)" \
      "1|0|0|1|0|0|0|600|k:off,s:off,p:-,d:-" \
  "106-6b13 the owner's command run exactly as printed (run_line106c, under the clock): \"  followup_auto_send = 0\" and nothing from \"  AI SETTINGS\" on, exit status 0; its own log ($(basename "${LG6BR_106:-none}"), mode 0600) the same, holding neither the planted phone nor the address nor any marked text; followup_auto_send off in the file and its store row"
O6BC1_106="$(setclr106 quote_company_phone)"; R6BC1_106="$(setrc106)"; O6BC2_106="$(setclr106 quote_company_email)"; R6BC2_106="$(setrc106)"
# tools/set_config.php:668 ("<key> cleared — back to the default")
check "$R6BC1_106$R6BC2_106|$(grep -cxF '  quote_company_phone cleared — back to the default.' <<<"$O6BC1_106")$(grep -cxF '  quote_company_email cleared — back to the default.' <<<"$O6BC2_106")|$(kv106 "$(q106 kycplaces "$PD" "$DATA" quote_company_phone,quote_company_email)" keys)" \
      "00|11|quote_company_phone@-;quote_company_email@-" \
  "106-6b14 the company's phone and address cleared through set_config --clear as the book's owner (A9): in neither place again"

echo; echo "== 106-6e. the rollback run again from a redeploy (6b's last step): followup_auto_send already off — the plain note, AS_RB1=off; RBN caught on the same state =="
dpre106 "106-6e0" "$B106" off - "on:$LIM106" "not-written:differ:nothing:not-before"
dredeploy106 "106-6e1"
dpre106 "106-6e2" "$P106" off - "on:$LIM106" "not-written:differ:nothing:not-written"
rbx106 "106-6e3"
O="$RBX106_OUT"
# deploy-5.18.106.sh:8925 (already off), :8924's AS_RB0_SAY in the prompt (:13881), :9036 (AS-RB1), :9137 (AS-RB2), :15803 (RB)
check "$(grep -cxF "$AS0OFF106" <<<"$O")|$(has "$O" "${TRB106}followup_auto_send already off, nothing written, every other switch of the sales follow-up kept as it is — anything else to stop: ")|$(noteline "$O" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(okline "$O" AS-RB2 "followup_auto_send reads off through $BV106's code ($B106, installed)")$(has "$O" '; AS-RB1 set it before the put-back')|$(has "$O" 'THE ROLLBACK CANNOT SET')|$(noteline "$O" RB "and wrote no key (AS-RB1: already off, AS-RB1 wrote nothing; root docs/75 §L.7)")|$(has "$O" 'and wrote one key before the put-back')|$(sget106 "$RBX106_REC" AS_RB1)" \
      "1|yes|yes|yesno|no|yes|no|off" \
  "106-6e3'' already off: AS-RB0's plain note, nothing will be written (S:8925), and the prompt says so; AS-RB1 wrote nothing (S:9036); AS-RB2 off, without \"AS-RB1 set it\" (S:9137); no capitals; RB: wrote no key (S:15803); its record AS_RB1=off"
dredeploy106 "106-6e4"
dpre106 "106-6e5" "$P106" off - "on:$LIM106" "off:nothing:not-written"
# RBN — the weakened copy whose RB note says "wrote one key" whatever AS-RB1 recorded, ROLLBACK typed on this already-off state: a
# real rollback (it puts 7835906 back), whose note claims a write its own record denies — caught by 106-6e3's "wrote no key"
RR6E6_106="$(rsl106)"; MRBN106="$(weak106 rbn rbn)"
fresh106; ORBN106="$(R106_MUTANT_OUT=1 run106c --answer ROLLBACK --script "$MRBN106" --rollback)"; RCRBN106="$(rc106)"; rm -f "$MRBN106"
RRBN106="$(newrec106 "$RR6E6_106")"
check "$(noteline "$ORBN106" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(sget106 "$RRBN106" AS_RB1)|$(noteline "$ORBN106" RB "and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: already off, AS-RB1 wrote nothing; root docs/75 §L.7)")|$RCRBN106|$(live)|$( [ -e "$MRBN106" ] && echo left || echo removed)" \
      "yes|off|yes|0|$B106|removed" \
  "106-6e6 RBN, the copy whose RB note is the written case's whatever AS-RB1 recorded (S:15803), ROLLBACK typed with the key already off: its AS-RB1 wrote nothing and its record says off, yet its note says it wrote one key — caught by 106-6e3''; $B106 serving; the copy removed"
qcheck106 "106-6e6'"
dredeploy106 "106-6e7"
O6E8_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O6E8_106")|$(setrc106)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" "1|0|k:on,s:on,p:-,d:-" \
  "106-6e8 followup_auto_send on again through set_config as the book's owner — the owner's own command, where no --after-only offers it (the latest rollback wrote nothing)"
dpre106 "106-6e9" "$P106" on - "on:$LIM106" "off:nothing:not-written"

echo; echo "== 106-6c. '3' against 3 (a FIXTURE): condition 3 on a number the store row holds as a number — the rollback cannot write; answered no, nothing changed =="
dpre106 "106-6c0" "$P106" on - "on:$LIM106" "off:nothing:not-written"
# A FIXTURE: no production writer leaves a number typed differently in the file and the row (set_config writes strings to both);
# rh106-seed.php fx_type3 makes the row's followup_daily_cap the number the file holds as a string, through the store's own load()
# and save(), and fx_type3_undo makes it the string again
X6C_106="$(plant106 '[{"op":"fx_type3","key":"followup_daily_cap"}]')"; K6C_106="$(q106 kycplaces "$PD" "$DATA" followup_daily_cap)"
check "$(jf105 "$X6C_106" ops)|$(sd106 "$X6C_106" 0 fixture)|$(sd106 "$X6C_106" 0 row)|$(sd106 "$X6C_106" 0 file)|$(kv106 "$K6C_106" keys)|$(kv106 "$K6C_106" pre13):$(kv106 "$K6C_106" differ)" \
      "1|true|number|unchanged|followup_daily_cap@ks,si|differ:followup_daily_cap" \
  "106-6c1 the fixture: followup_daily_cap a string in kyc_config.json and a number in its store row (this rehearsal's reading, ===: §L.7's condition 3 fails on it)"
D6C_106="$(data94)"; CF6C_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"; RB6C_106="$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)"
fresh106; O6C_106="$(run106c --answer no --rollback)"; RC6C_106="$(rc106)"
# deploy-5.18.106.sh:8934 (the notice), :8735 (the command), :13881 (the prompt), :13883 and :1689 (not confirmed, nothing changed)
check "$(grep -cxF "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3: the file and the store row disagree on followup_daily_cap): $BV106 WILL THEN APPROVE AND SEND AUTOMATICALLY. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN YOUR OWN COMMAND BELOW, ALONE, THEN RUN THE ROLLBACK AGAIN:" <<<"$O6C_106")|$(order106 "$O6C_106" "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (" "      docker exec -u " "${TRB106}followup_auto_send NOT set off — the rollback cannot write it (above), every other switch" "  STOP: not confirmed" "  Nothing was changed. Send the log file.")|$(ascmd106 0 "$(printed "$O6C_106" "docker exec -u ")")|$(grep -cE '^  (ok    |FAIL  |note  )AS-RB1 ' <<<"$O6C_106")|$RC6C_106|$(live)|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)" \
      "1|ordered|form|0|1|$P106|$D6C_106|$CF6C_106|$RB6C_106" \
  "106-6c2 the rollback over '3' against 3, answered no: the capitals notice naming condition 3 on followup_daily_cap (S:8934), the owner's command alone, the prompt saying it will not be set off, then \"not confirmed\" and \"Nothing was changed\" — no AS-RB1 at all; $P106 serving, the book and the four settings places byte for byte as before, no rollback record with a copy time"
X6CU_106="$(plant106 '[{"op":"fx_type3_undo","key":"followup_daily_cap"}]')"; K6CU_106="$(q106 kycplaces "$PD" "$DATA" followup_daily_cap)"
check "$(sd106 "$X6CU_106" 0 row)|$(kv106 "$K6CU_106" keys)|$(kv106 "$K6CU_106" pre13)" "string|followup_daily_cap@ks,ss|ok" \
  "106-6c3 the fixture undone (A9) through the store's own load() and save(): followup_daily_cap a string in both places, condition 3 holding"

echo; echo "== 106-6d. an undecodable kyc_config.json (a FIXTURE): AS-RB0's plain note naming condition 1, no command; with followup_auto_send on from <data>/config.json (a FIXTURE): DOES NOT DECODE … NO COMMAND IS PRINTED; each answered no; undone by sha256 =="
dpre106 "106-6d0" "$P106" on - "on:$LIM106" "off:nothing:not-written"
# FIXTURES, both by rh106-seed.php as root (each file's owner and mode kept, its original kept in $R106/kept/p5-6d): kyc_config.json
# replaced by marked text that does not decode (writeKycFile always writes JSON), then followup_auto_send '1' in <data>/config.json
# (uCRM's file, which no production writer of the plugin writes); both put back by fx_file_back, by sha256
mkdir -p "$R106/kept/p5-6d"; chmod 700 "$R106/kept/p5-6d"
CF6D0_106="$(q106 cfgsha "$PD" "$DATA")"; D6D_106="$(data94)"; RB6D_106="$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)"
WD6D_106="$( [ -e "$DATA/config.json" ] && echo present || echo absent)"
X6D1_106="$(seed106r '[{"op":"fx_file","place":"k","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-k","raw":"{\"RH106SECRET-undecodable\":"}]')"
K6D1_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; PL6D1_106="$(pls106 "$K6D1_106")"; CF6D1_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"
check "$(jf105 "$X6D1_106" ops)|$(sd106 "$X6D1_106" 0 fixture)|$(sd106 "$X6D1_106" 0 what)|$(kv106 "$K6D1_106" file)|$(kv106 "$K6D1_106" eff)|$(kv106 "$K6D1_106" asplaces)|$(kv106 "$K6D1_106" pre13)" \
      "1|true|a file that does not decode|undecodable|followup_auto_send:off|k:?,s:on,p:-,d:-|file" \
  "106-6d1 the fixture: kyc_config.json replaced by marked text that does not decode — the jobs ignore it, so followup_auto_send reads off as they read it, though its store row says on; §L.7's condition 1 fails"
fresh106; O6D2_106="$(run106c --answer no --rollback)"; RC6D2_106="$(rc106)"
# deploy-5.18.106.sh:8882 (AS-RB0, condition 1 named), :8925 (already off), :13881 (the prompt), :1689
check "$(grep -cxF "  note  AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): off — per place: $PL6D1_106; - absent, ? unread; §L.7's five conditions do not hold: condition 1: <data>/kyc_config.json is undecodable" <<<"$O6D2_106")|$(grep -cxF "$AS0OFF106" <<<"$O6D2_106")|$(has "$O6D2_106" 'THE ROLLBACK CANNOT SET')|$(grep -c '^      docker exec ' <<<"$O6D2_106")|$(has "$O6D2_106" "${TRB106}followup_auto_send already off, nothing written, every other switch")|$(has "$O6D2_106" '  STOP: not confirmed')|$RC6D2_106|$(live)|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)" \
      "1|1|no|0|yes|yes|1|$P106|$D6D_106|$CF6D1_106|$RB6D_106" \
  "106-6d2 the rollback over it, answered no: AS-RB0's plain note naming condition 1, the file undecodable (S:8882), then \"already reads off\" (S:8925) — no capitals and no command; the prompt says nothing will be written; not confirmed, exit status 1; the file and the book unchanged"
X6D3_106="$(seed106r '[{"op":"fx_file","place":"d","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-d","set":{"followup_auto_send":"1"}}]')"
K6D3_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"; PL6D3_106="$(pls106 "$K6D3_106")"; CF6D3_106="$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)"
check "$(jf105 "$X6D3_106" ops)|$(sd106 "$X6D3_106" 0 fixture)|$(sd106 "$X6D3_106" 0 keys)|$(kv106 "$K6D3_106" eff)|$(kv106 "$K6D3_106" asplaces)|$(kv106 "$K6D3_106" pd)" \
      "1|true|followup_auto_send|followup_auto_send:on|k:?,s:on,p:-,d:on|followup_auto_send@d" \
  "106-6d3 the sub-case's fixture: followup_auto_send '1' in <data>/config.json, beside the undecodable file — it reads on as the jobs read it"
fresh106; O6D4_106="$(run106c --answer no --rollback)"; RC6D4_106="$(rc106)"
# deploy-5.18.106.sh:8936 (the capitals, no command allowed), :8722 (DOES NOT DECODE … NO COMMAND IS PRINTED), :13881, :1689
check "$(grep -cxF "  note  THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 1: <data>/kyc_config.json is undecodable): $BV106 WILL THEN APPROVE AND SEND AUTOMATICALLY WHILE IT READS ON. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — AND ACT ON WHAT FOLLOWS (NO COMMAND CAN BE PRINTED FOR THIS CAUSE), THEN RUN THE ROLLBACK AGAIN:" <<<"$O6D4_106")|$(grep -cxF "  It reads on (per place: $PL6D3_106; - absent, ? unread). <data>/KYC_CONFIG.JSON DOES NOT DECODE (IT IS EMPTY OR NOT JSON): THE PLUGIN IGNORES THAT FILE TODAY, SO ITS VALUES ARE NOT IN EFFECT, AND set_config — OR ANY SETTINGS SAVE — WOULD REPLACE IT WITH A ONE-KEY FILE. NO COMMAND IS PRINTED: REPAIR IT BY HAND FIRST, THEN run the rollback again." <<<"$O6D4_106")|$(grep -c '^      docker exec ' <<<"$O6D4_106")|$(has "$O6D4_106" "${TRB106}followup_auto_send NOT set off — the rollback cannot write it (above), every other switch")|$(has "$O6D4_106" '  STOP: not confirmed')|$RC6D4_106|$(live)|$(data94)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)" \
      "1|1|0|yes|yes|1|$P106|$D6D_106|$CF6D3_106" \
  "106-6d4 the same rollback with followup_auto_send on from <data>/config.json, answered no: the capitals notice that the rollback cannot set it off, no command allowed for this cause (S:8936), and the owner's text: DOES NOT DECODE … NO COMMAND IS PRINTED (S:8722); no command line; not confirmed; every file and the book unchanged"
X6D5_106="$(seed106r '[{"op":"fx_file_back","place":"d","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-d"},{"op":"fx_file_back","place":"k","keep":"'"$R106/kept/p5-6d"'","tag":"p5-6d-k"}]')"
K6D5_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
check "$(jf105 "$X6D5_106" ops)|$(sd106 "$X6D5_106" 0 was)$(sd106 "$X6D5_106" 1 was)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$K6D5_106" file):$(kv106 "$K6D5_106" pre13):$(kv106 "$K6D5_106" asplaces)|$(ls -A "$R106/kept/p5-6d" | grep -c .)" \
      "2|${WD6D_106}present|$(kv106 "$CF6D0_106" all)|decoded:ok:k:on,s:on,p:-,d:-|0" \
  "106-6d5 both fixtures undone (A9) before the next redeploy: <data>/config.json put back as it was ($WD6D_106 before), kyc_config.json put back — the four settings places byte for byte as before 106-6d1 (sha256), the file decoding, the key on; nothing left kept"

echo; echo "== 106-6f. step 6 on at the rollback, with the offers keys in the store row alone (a FIXTURE): STEP 12, 11 and 6, highest first, each its command alone; the key on, so it writes (for 7a) =="
dpre106 "106-6f0" "$P106" on - "on:$LIM106" "off:nothing:not-written"
O6F1_106="$(sf104c --tty yes on chase)"
# tools/sales_followup.php:224 (the question), :261 ("ON <step>: <keys>") — 7835906's and bf29cbd's alike (106-infra b)
check "$(sfrc104)|$(has "$O6F1_106" 'Type yes to switch the quote chase on:')|$(grep -c '^ON chase: ' <<<"$O6F1_106")" "0|yes|1" \
  "106-6f1 on chase through the installed tool while $V106 serves, yes typed at the terminal — ON, exit 0"
DG6F_106="$(digest105)"
# A FIXTURE: no production writer leaves an offers switch in the store row alone; rh106-seed.php fx_store_keys puts
# offers_live_enabled and offers_test_enabled there through the store's own load() and save(), kyc_config.json untouched
X6F2_106="$(plant106 '[{"op":"fx_store_keys","keys":{"offers_live_enabled":"1","offers_test_enabled":"1"}}]')"
check "$(jf105 "$X6F2_106" ops)|$(sd106 "$X6F2_106" 0 fixture)|$(sd106 "$X6F2_106" 0 file)" "1|true|unchanged" \
  "106-6f2 the fixture: offers_live_enabled and offers_test_enabled in the store row alone, kyc_config.json untouched"
dpre106 "106-6f3" "$P106" on "offers_live_enabled@s+offers_test_enabled@s+quote_chase_enabled@k,s" "on:$LIM106" "off:nothing:not-written"
rbx106 "106-6f4"
O="$RBX106_OUT"
# deploy-5.18.106.sh:8897/:8899 (step 12), :8903/:8905 (step 11), :8909/:8911 (step 6), :13881 (the prompt), :9032 (AS-RB1)
check "$(order106 "$O" "  note  STEP 12 (OFFERS-LIVE) IS ON: offers_live_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BV106 THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS." "$OFFW106 offers-live" "  note  STEP 11 (OFFERS-TEST) IS ON: offers_test_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BV106 THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS." "$OFFW106 offers-test" "  note  STEP 6 (CHASE) IS ON: quote_chase_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BV106 THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS." "$OFFW106 chase" "$TRB106")|$(for w106 in offers-live offers-test chase; do grep -cxF "$OFFW106 $w106" <<<"$O"; done | paste -sd, -)|$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (typed)')|$(sget106 "$RBX106_REC" AS_RB1)|$( [ "$(digest105)" = "$DG6F_106" ] && echo kept)" \
      "ordered|1,1,1|yes|written|kept" \
  "106-6f4'' step 6 on and the offers keys in the store row at the rollback: AS-RB0's three notices in capitals, highest step first — STEP 12, STEP 11, STEP 6 — each followed by its off command alone on its line (S:8897-8911), before the question; never run; the key on and the five conditions holding, AS-RB1 wrote (its record AS_RB1=written); every ladder key, the offers keys and chase among them, as before"
X6F5_106="$(plant106 '[{"op":"fx_store_keys_undo","keys":["offers_live_enabled","offers_test_enabled"]}]')"
check "$(jf105 "$X6F5_106" ops)|$(sd106 "$X6F5_106" 0 file)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled)" cs)|$( [ "$(digest105)" = "$DG6F_106" ] && echo before-fixture)" \
      "1|unchanged|quote_chase_enabled@k,s|before-fixture" \
  "106-6f5 the fixture undone (A9) through the store's own load() and save(): no offers key in either place, step 6 still on (for 7a), every ladder key as after on chase"

echo; echo "== 106-7. redeploys and the offer to turn followup_auto_send back on; V1's automatic rollback, its put-back made to fail (A7) =="
echo; echo "== 106-7a. after 6f, step 6 on: --redeploy refused by CS-G1; its off command as printed; --redeploy PASSED; its --after-only offers 6f's command, run =="
dpre106 "106-7a0" "$B106" off "quote_chase_enabled@k,s" "on:$LIM106" "written:nothing:not-before"
D7A_106="$(data94)"; fresh106; O7A_106="$(run106c --answer DEPLOY --redeploy)"
# deploy-5.18.106.sh:7434 (CS-G1's refusal), :1689
nogo106 "106-7a1 CS-G1 on the --redeploy with step 6 on: NO-GO — quote_chase_enabled present (k,s), exit 1, never the question, nothing changed" \
        "NO-GO (CS-G1): a claim switch is present (quote_chase_enabled@k,s) — until $V106 serves none may be, with any value" "$O7A_106" "$D7A_106" "$B106"
OFF7A_106="$(printed "$O7A_106" "cd $REPO && bash scripts/sales-followup.sh off chase")"; O7A2_106="$(run_line106c "" "$OFF7A_106")"
check "$( [ "      $OFF7A_106" = "$OFFW106 chase" ] && echo printed)|$(rc106)|$(grep -cx 'OFF chase' <<<"$O7A2_106")|$(kv105 "$(lk105)" on | tr ',' '\n' | grep -cx chase)" "printed|0|1|0" \
  "106-7a2 CS-G1's off command run exactly as printed (S:7428), under the clock: OFF chase, exit 0 — step 6 off by this rehearsal's ladder reading"
dredeploy106 "106-7a3"
dpre106 "106-7a4" "$P106" off - "on:$LIM106" "written:offer-if-off"
master106 "106-7a5"
aft106 "106-7a6" PASSED $L1ON106
doffer106 "106-7a7"

echo; echo "== 106-7b. a typed rollback that writes, then a plain deploy (no flag): BR1's note, PASSED; its --after-only offers the rollback's command, run =="
dpre106 "106-7b0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7b1"
check "$(sget106 "$RBX106_REC" AS_RB1)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" file)" "written|$(basename "$RBX106_REC")" \
  "106-7b1'' the rollback wrote followup_auto_send off (AS_RB1=written); its record the latest by this rehearsal's own reading (rblast)"
dpre106 "106-7b2" "$B106" off - "on:$LIM106" "written:nothing:not-before"
K7B_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send)"
check "$(live)|$(kv106 "$K7B_106" cs)|$(kv106 "$(dnow106)" en)|$(kv106 "$K7B_106" file):$(kv106 "$K7B_106" row)|$(left106)" "$B106|-|on|decoded:decoded|$NA106" \
  "106-7b3 the gates before the plain deploy hold, by this rehearsal's own reading: $B106 serving, no claim switch, followup_enabled on, both places decodable; all $NA106 of $V106's own files still on disk (the rollback deletes none)"
P7B_106="$(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .)"
fresh106; O7B_106="$(run106c --answer DEPLOY)"; T7B_106="$(last106)"
# deploy-5.18.106.sh:12451 (BR1's note), :16084 (PASSED), :7409 (CS-G1), :7494 (LK-CA1), :13737 (the earlier state file kept)
check "$(fails "$O7B_106")$(has "$O7B_106" "  $V106 (deploy): PASSED.")|$(rc106)|$(went106 "$O7B_106")|$(noteline "$O7B_106" BR1 "$NA106 of $V106's own files are on disk although --redeploy was not asked for: an earlier $V106 was deployed here and rolled back" "offering your own command to turn followup_auto_send back on where that rollback set it off")|$(live)|$(installed106 "$P106F")|$(okline "$O7B_106" CS-G1 'none of the three claim switches')$(okline "$O7B_106" LK-CA1 'after the copy every ladder key')|$(( $(ls -1 "$STATE106".prev-* 2>/dev/null | grep -c .) - P7B_106 ))|$(copyok106)|$(ctl106)|$(cat "$R106/evo/run-$T7B_106.n" 2>/dev/null)" \
      "0yes|0|yesyes|yes|$P106|0|yesyes|1|near|before|0 nocron" \
  "106-7b4 the plain deploy after the rollback, no --redeploy, DEPLOY typed: BR1's note — $V106's own files on disk, a deploy after that rollback all the same, its --after-only to offer the turn-back-on (S:12451) — PASSED, $P106 serving and installed, CS-G1 and LK-CA1 ok, the earlier state file kept beside the new one (S:13737), COPY_AT on the clock (A1), followup_close not run since the copy (A2), Evolution not called"
dpre106 "106-7b5" "$P106" off - "on:$LIM106" "written:offer-if-off"
master106 "106-7b6"
aft106 "106-7b7" PASSED $L1ON106
doffer106 "106-7b8"

echo; echo "== 106-7c. V1 with its precondition holding: it writes, the put-back succeeds; --redeploy; its --after-only offers V1's command (its record kept beside the state file), run =="
dpre106 "106-7c0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7c1"
O7C2_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7C2_106")|$(setrc106)" "1|0" "106-7c2 the owner's --value 1 through set_config as the book's owner, $BV106 serving: \"  followup_auto_send = 1\", exit 0"
dpre106 "106-7c3" "$B106" on - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7c4"
O="$V1OUT106"
# deploy-5.18.106.sh:9032 (AS-RB1, v1), :13828 (the put-back verified), :13928 (the stop; as_para v1:written prints nothing, :8752)
check "$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (v1)')|$(has "$O" "  the container now serves $B106; the checkout is back on ")|$(has "$O" "  STOP: rolled back (serves $B106); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (set off first, AS-RB1), 5.18.105's masked lines still masked under $BV106 (LM-RB1); ")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(installed106 "$B106F")|$(sget106 "$STATE106" AS_RB1)|$(grep -c '^RB_COPY_AT=[0-9]' "$STATE106")|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "yes|yes|yes|0|$B106|0|written|1|k:off,s:off,p:-,d:-" \
  "106-7c4' V1's AS-RB1 set followup_auto_send off (S:9032) and the put-back verified (S:13828): the stop says rolled back, every switch kept but followup_auto_send (S:13928), with no command (V1 wrote it, :8752); $B106 serving, every file $V106 changes $BV106's; this deploy's state file records AS_RB1=written and the put-back's copy time"
qcheck106 "106-7c5"
dpre106 "106-7c6" "$B106" off - "on:$LIM106" "written:nothing:v1guard"
dredeploy106 "106-7c7"; DRD7C_106="$DRD106_OUT"; PL7C_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"
R7C_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7C_106" last):$(case "$(kv106 "$R7C_106" file)" in "state-$V106.env.prev-"*) echo prev ;; *) echo other ;; esac):$(kv106 "$R7C_106" before):$(kv106 "$R7C_106" v1guard):$(kv106 "$R7C_106" would)" "written:prev:yes:pass:offer-if-off" \
  "106-7c8 this rehearsal's own reading after the --redeploy (rblast, as_rb_last's mirror): the latest rollback record is V1's, in the state file the --redeploy kept beside its own (S:13737), written, its copy before this deploy's — as_back_on would offer were the key off"
master106 "106-7c9"
aft106 "106-7c10" PASSED $L1ON106
doffer106 "106-7c11"

echo; echo "== 106-7d. V1 with its precondition failing (a key the file holds and the row lacks): its stop carries the owner's command; then --rollback with nothing to roll back: AS-RB2's capitals and the command =="
dpre106 "106-7d0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7d1"
O7D2_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7D2_106")|$(setrc106)" "1|0" "106-7d2 the owner's --value 1 through set_config as the book's owner, $BV106 serving"
# the file-only key, as in 106-6b: set_evolution.php --url, the file and the vault kept first, undone byte for byte at 106-7d10
KK7D106="$(keep106 p5-k7d "$DATA/kyc_config.json")"; KV7D106="$(keep106 p5-v7d "$VAULT")"; CF7D0_106="$(q106 cfgsha "$PD" "$DATA")"
E7D3_106="$(evo_calls)"; O7D3_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R7D3_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E7D3_106 ))
check "$(grep -cxF "    API URL  $EVF106" <<<"$O7D3_106")|$R7D3_106|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13):$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" differ)" "1|1|differ:evo_api_url" \
  "106-7d3 the file-only key planted through set_evolution.php --url as the book's owner (the file and the vault kept first, $KK7D106/$KV7D106): condition 3 fails on evo_api_url"
dpre106 "106-7d4" "$B106" on - "on:$LIM106" "written:nothing:not-before"
PL7D_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"; CA7D_106="condition 3: the file and the store row disagree on evo_api_url"
v1run106 "106-7d5"
O="$V1OUT106"; C7D_106="$(printed "$O" "docker exec -u ")"
# deploy-5.18.106.sh:9038 (AS-RB1's FAIL, v1), :9144 (AS-RB2), :13928 (the stop), :8755 (as_para v1, not written), :8724, :8735
check "$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($CA7D_106) — $BV106 WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed in the stop message below" <<<"$O")|$(grep -cxF "  note  AS-RB2 followup_auto_send reads on under $BV106 — AS-RB1 did not write it (not written: $CA7D_106; its FAIL above): your own command, where its cause allows one, is printed in the stop message below" <<<"$O")|$(order106 "$O" "  STOP: rolled back (serves $B106); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (not written: $CA7D_106), " "  AS-RB1 DID NOT SET followup_auto_send OFF (not written: $CA7D_106). It reads on (per place: $PL7D_106; - absent, ? unread). The command below also copies <data>/kyc_config.json, whole, into the settings row" "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; " "  Nothing further was done. Send the log file.")|$(grep -cxF "      $C7D_106" <<<"$O")|$(ascmd106 0 "$C7D_106")|$(live)|$(sget106 "$STATE106" AS_RB1)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|1|ordered|1|form|$B106|not-written:differ|k:on,s:on,p:-,d:-" \
  "106-7d5' V1 with condition 3 failing: AS-RB1's FAIL in capitals, its command promised in the stop message (S:9038), AS-RB2's note (S:9144); the put-back verified and the stop says rolled back (S:13928), then as_para v1 — AS-RB1 did not set it off, the owner's text and command, alone (S:8755, S:8735); $B106 serving, the record AS_RB1=not-written:differ, followup_auto_send still on"
qcheck106 "106-7d6"
dpre106 "106-7d7" "$B106" on - "on:$LIM106" "written:nothing:v1guard"
RB7D8_106="$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)"
fresh106; O7DN_106="$(run106c --rollback)"; RC7DN_106="$(rc106)"; C7DN_106="$(printed "$O7DN_106" "docker exec -u ")"
# deploy-5.18.106.sh:12381 (nothing to roll back), :9094 (LK-RB1 not judged), :9140 (AS-RB2's capitals under RB_NOTHING), :8735, :16084
check "$(noteline "$O7DN_106" the "container already serves $B106 ($BV106) — nothing to roll back; running the rollback checks")|$(noteline "$O7DN_106" LK-RB1 'not judged: this run rolled nothing back')|$(order106 "$O7DN_106" "  note  AS-RB2 FOLLOWUP_AUTO_SEND READS ON UNDER $BV106, WHICH APPROVES AND SENDS AUTOMATICALLY WHILE IT DOES (per place: $PL7D_106; - absent, ? unread) — this run rolled nothing back, so it wrote nothing (AS-RB1 runs only in a rollback that puts $BV106 back); your own command, as the one reader allows it:" "  It reads on (per place: " "      docker exec -u ")|$(ascmd106 0 "$C7DN_106")|$(has "$O7DN_106" '  Type ROLLBACK to put ')|$(fails "$O7DN_106")$(has "$O7DN_106" "  $V106 (rollback): PASSED.")|$RC7DN_106|$(live)|$(kv106 "$(q106 rblast "$OUT106" "$STATE106")" records)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "yes|yes|ordered|form|no|0yes|0|$B106|$RB7D8_106|k:on,s:on,p:-,d:-" \
  "106-7d8 --rollback with $BV106 already serving and the key on (RH106-15): nothing to roll back (S:12381), LK-RB1 not judged (S:9094), no question; AS-RB2's capitals and the owner's command (S:9140) — nothing written; PASSED, no rollback record with a copy time"
qcheck106 "106-7d9"
check "$(back106 p5-k7d)|$(back106 p5-v7d)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13)" "same|same|$(kv106 "$CF7D0_106" all)|ok" \
  "106-7d10 the file-only key undone (A9): kyc_config.json and the vault put back byte for byte (nothing wrote either since the plant) — the four settings places as before 106-7d3"

echo; echo "== 106-7h. a PASSED deploy with followup_auto_send on: the by-hand paragraph prints the owner's whole command (with it off, 7c's redeploy, nothing to run) =="
dpre106 "106-7h0" "$B106" on - "on:$LIM106" "written:nothing:v1guard"
PL7H_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"
dredeploy106 "106-7h1"
O="$DRD106_OUT"; C7H_106="$(printed "$O" "docker exec -u ")"
# deploy-5.18.106.sh:16191 (as_para sum after the put-back by hand), :8752 (its sentence), :8735 (the command), :8715 (already off)
check "$(order106 "$O" "      ( cd $REPO && git checkout $B106 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) " "  followup_auto_send before a put-back by hand, which writes no setting (the rollback above sets it off first, AS-RB1): It reads on (per place: $PL7H_106; - absent, ? unread). Your own command, alone, with its own log:" "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; without that line take nothing as saved (run the rollback again: it reads the setting first).")|$(ascmd106 0 "$C7H_106")|$(grep -cxF "  followup_auto_send before a put-back by hand, which writes no setting (the rollback above sets it off first, AS-RB1): It already reads off (per place: $PL7C_106; - absent, ? unread): nothing to run." <<<"$DRD7C_106")|$(grep -c '^      docker exec .* --key followup_auto_send ' <<<"$DRD7C_106")" \
      "ordered|form|1|0" \
  "106-7h1'' the PASSED --redeploy with followup_auto_send on: after the put-back by hand, the by-hand paragraph (S:16191) — it reads on, and the owner's whole command to set it off, alone, with the line it prints (S:8752, S:8735); its control, 106-7c7's PASSED --redeploy with the key off: \"It already reads off … nothing to run.\" (S:8715), no command"

echo; echo "== 106-7e. a typed rollback that writes → the owner's --value 1 → V1 written, its put-back made to fail (.redirect106lock): $V106 serves; its --after-only offers nothing; BOV caught =="
dpre106 "106-7e0" "$P106" on - "on:$LIM106" "not-written:differ:nothing:not-written"
rbx106 "106-7e1"; RB7E_106="$RBX106_REC"
O7E2_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7E2_106")|$(setrc106)" "1|0" "106-7e2 the owner's --value 1 through set_config as the book's owner, $BV106 serving"
dpre106 "106-7e3" "$B106" on - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7e4" lock
O="$V1OUT106"
# deploy-5.18.106.sh:13863 (the deploy's checkout back), :13923, :9032 (AS-RB1, v1), :13818 (the put-back's checkout refused),
# :13929 (the stop), :8748 (as_para rb:written)
check "$(okline "$O" AS-RB1 'followup_auto_send set off before the put-back (v1)')|$(order106 "$O" "  the checkout is back on " "  ROLLING BACK — the public address must never redirect" "  ok    AS-RB1 followup_auto_send set off before the put-back (v1)" "  FAIL  git checkout $B106 failed — nothing was deployed" "  STOP: the automatic rollback did not complete — it did not verify (live=$P106): ")|$(has "$O" " followup_auto_send was set off before the put-back was tried (AS-RB1): $V106 keeps serving with automatic approval off, and a put-back by hand leaves it off — a person approves each draft.")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(installed106 "$P106F")|$(sget106 "$STATE106" AS_RB1)|$(grep -c '^RB_COPY_AT=' "$STATE106")|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "yes|ordered|yes|0|$P106|0|written|0|k:off,s:off,p:-,d:-" \
  "106-7e4'' V1 written, its put-back made to fail: the deploy's own checkout back first (S:13863), then ROLLING BACK, AS-RB1's write (S:9032), then \"git checkout $B106 failed — nothing was deployed\" (S:13818) and the stop: the automatic rollback did not complete (S:13929), followup_auto_send set off before the put-back was tried (S:8748), no command; $P106 still serving and installed; this deploy's state file AS_RB1=written, no put-back copy time"
qcheck106 "106-7e5"
R7E_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7E_106" last):$(kv106 "$R7E_106" file):$(kv106 "$R7E_106" before):$(kv106 "$R7E_106" v1):$(kv106 "$R7E_106" v1guard):$(kv106 "$R7E_106" would)|$( [ "$(kv106 "$R7E_106" t)" -lt "$(kv106 "$R7E_106" copy_at)" ] 2>/dev/null && echo earlier)" \
      "written:$(basename "$RB7E_106"):yes:written:stop:nothing:v1guard|earlier" \
  "106-7e6 this rehearsal's own reading before the --after-only (rblast): the latest rollback record 106-7e1's typed one, written, its copy time before this deploy's COPY_AT; this deploy's own AS_RB1=written stops as_back_on (the V1 guard, S:8829) — without it, the key reading off, it would offer"
dpre106 "106-7e7" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
master106 "106-7e8"
aft106 "106-7e9" PASSED $L1ON106
check "$(dnooffer106)" "none" "106-7e9' its PASSED --after-only offers nothing (S:8829): V1 of this deploy wrote followup_auto_send, and its own stop said what it left"
MB7E_106="$(weak106 bov bov)"; fresh106; OB7E_106="$(R106_MUTANT_OUT=1 run106c --script "$MB7E_106" --after-only)"; rm -f "$MB7E_106"
check "$(has "$OB7E_106" "$OFFER106")|$(grep -cE '^      docker exec .* --key followup_auto_send --value 1 ' <<<"$OB7E_106")|$(has "$OB7E_106" "  $V106 (after): PASSED.")|$( [ -e "$MB7E_106" ] && echo left || echo removed)" "yes|1|yes|removed" \
  "106-7e10 BOV, the copy without as_back_on's V1 guard, on this same state: it offers the turn-back-on command — caught by 106-7e9'; the copy removed"

echo; echo "== 106-7f. the same, V1 reading the key on with condition 3 failing (not-written:differ), its put-back made to fail: its printed --value 0 run; then the --after-only offers nothing; BOV again caught =="
dpre106 "106-7f0" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
O7F1_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7F1_106")|$(setrc106)" "1|0" "106-7f1 followup_auto_send on through set_config as the book's owner, $V106 serving — the owner's own, where no --after-only offers it"
rbx106 "106-7f2"; RB7F_106="$RBX106_REC"
O7F3_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7F3_106")|$(setrc106)" "1|0" "106-7f3 the owner's --value 1 through set_config as the book's owner, $BV106 serving"
KK7F106="$(keep106 p5-k7f "$DATA/kyc_config.json")"; KV7F106="$(keep106 p5-v7f "$VAULT")"; CF7F0_106="$(q106 cfgsha "$PD" "$DATA")"
E7F4_106="$(evo_calls)"; O7F4_106="$(setevo106 --url "$EVF106" --allow-insecure)"; R7F4_106="$(sevrc106)"; EVS106=$(( EVS106 + $(evo_calls) - E7F4_106 ))
check "$(grep -cxF "    API URL  $EVF106" <<<"$O7F4_106")|$R7F4_106|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13):$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" differ)" "1|1|differ:evo_api_url" \
  "106-7f4 the file-only key planted through set_evolution.php --url as the book's owner (the file and the vault kept first, $KK7F106/$KV7F106): condition 3 fails on evo_api_url"
dpre106 "106-7f5" "$B106" on - "on:$LIM106" "written:nothing:not-before"
PL7F_106="$(pls106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)")"; CA7F_106="condition 3: the file and the store row disagree on evo_api_url"
v1run106 "106-7f6" lock
O="$V1OUT106"; C7F_106="$(printed "$O" "docker exec -u ")"
# deploy-5.18.106.sh:9038 (AS-RB1's FAIL, v1), :13818, :13929, :8755 (as_para rb, not written), :8724, :8735
check "$(grep -cxF "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($CA7F_106) — $BV106 WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed in the stop message below" <<<"$O")|$(order106 "$O" "  the checkout is back on " "  FAIL  AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: " "  FAIL  git checkout $B106 failed — nothing was deployed" "  STOP: the automatic rollback did not complete — it did not verify (live=$P106): " "  AS-RB1 DID NOT SET followup_auto_send OFF (not written: $CA7F_106), and the put-back by hand does not either. It reads on (per place: $PL7F_106; - absent, ? unread). The command below also copies <data>/kyc_config.json, whole, into the settings row" "      docker exec -u " "  It prints the line \"  followup_auto_send = 0\" once it has saved; ")|$(grep -cxF "      $C7F_106" <<<"$O")|$(ascmd106 0 "$C7F_106")|$(live)|$(sget106 "$STATE106" AS_RB1)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|ordered|1|form|$P106|not-written:differ|k:on,s:on,p:-,d:-" \
  "106-7f6'' V1 reading the key on with condition 3 failing, its put-back made to fail: AS-RB1's FAIL (S:9038), \"git checkout $B106 failed\" (S:13818), the stop (S:13929) and as_para rb — AS-RB1 did not set it off, nor does a put-back by hand — with the owner's text and command, alone (S:8755, S:8735); $P106 still serving; this deploy's AS_RB1=not-written:differ; the key still on"
qcheck106 "106-7f7"
check "$(back106 p5-k7f)|$(back106 p5-v7f)|$(kv106 "$(q106 cfgsha "$PD" "$DATA")" all)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" evo_api_url)" pre13)" "same|same|$(kv106 "$CF7F0_106" all)|ok" \
  "106-7f8 the file-only key undone (A9) before the owner's command: kyc_config.json and the vault byte for byte (nothing wrote either since the plant) — the four settings places as before 106-7f4"
fresh106; O7F9_106="$(run_line106c "" "$C7F_106")"; RC7F9_106="$(rc106)"; LG7F9_106="$(ls -1t "$OUT106"/autosend-0-*.log 2>/dev/null | head -1)"
check "$(grep -cxF '  followup_auto_send = 0' <<<"$O7F9_106")|$(grep -c 'AI SETTINGS' <<<"$O7F9_106")|$RC7F9_106|$(grep -cxF '  followup_auto_send = 0' "${LG7F9_106:-/nonexistent}" 2>/dev/null)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" \
      "1|0|0|1|k:off,s:off,p:-,d:-" \
  "106-7f9 the stop's printed --value 0 run exactly as printed (run_line106c, under the clock): \"  followup_auto_send = 0\", nothing of the listing, exit 0, its own log holding the line; the key off in the file and its store row"
R7F_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7F_106" last):$(kv106 "$R7F_106" file):$(kv106 "$R7F_106" before):$(kv106 "$R7F_106" v1):$(kv106 "$R7F_106" v1guard):$(kv106 "$R7F_106" would)" \
      "written:$(basename "$RB7F_106"):yes:not-written:differ:stop:nothing:v1guard" \
  "106-7f10 this rehearsal's own reading before the --after-only (rblast): the latest record 106-7f2's typed one, written, before this deploy's copy; the key off; this deploy's own AS_RB1=not-written:differ stops as_back_on (S:8829)"
dpre106 "106-7f11" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
master106 "106-7f12"
aft106 "106-7f13" PASSED $L1ON106
check "$(dnooffer106)" "none" "106-7f13' its PASSED --after-only offers nothing (S:8829): V1 of this deploy read the key on, and its own stop carried the owner's command"
MB7F_106="$(weak106 bov bov)"; fresh106; OB7F_106="$(R106_MUTANT_OUT=1 run106c --script "$MB7F_106" --after-only)"; rm -f "$MB7F_106"
check "$(has "$OB7F_106" "$OFFER106")|$(grep -cE '^      docker exec .* --key followup_auto_send --value 1 ' <<<"$OB7F_106")|$(has "$OB7F_106" "  $V106 (after): PASSED.")|$( [ -e "$MB7F_106" ] && echo left || echo removed)" "yes|1|yes|removed" \
  "106-7f14 BOV again, on this state: it offers — caught by 106-7f13'; the copy removed"

echo; echo "== 106-7g. a typed rollback that writes → V1 finding the key already off (no --value 1), its put-back made to fail: the --after-only offers the typed rollback's command, run =="
dpre106 "106-7g0" "$P106" off - "on:$LIM106" "written:nothing:v1guard"
O7G1_106="$(setcfg106 followup_auto_send 1)"
check "$(grep -cxF '  followup_auto_send = 1' <<<"$O7G1_106")|$(setrc106)" "1|0" "106-7g1 followup_auto_send on through set_config as the book's owner, $V106 serving, so the typed rollback below writes"
rbx106 "106-7g2"; RB7G_106="$RBX106_REC"
dpre106 "106-7g3" "$B106" off - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7g4" lock
O="$V1OUT106"
# deploy-5.18.106.sh:9036 (AS-RB1: already off), :13818, :13929, :8749 (as_para rb:off)
check "$(noteline "$O" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(order106 "$O" "  ROLLING BACK — the public address must never redirect" "  note  AS-RB1 followup_auto_send already reads off — nothing was written" "  FAIL  git checkout $B106 failed — nothing was deployed" "  STOP: the automatic rollback did not complete — it did not verify (live=$P106): ")|$(has "$O" " followup_auto_send read off before the put-back was tried (AS-RB1 wrote nothing), and a put-back by hand leaves it so.")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(sget106 "$STATE106" AS_RB1)" \
      "yes|ordered|yes|0|$P106|off" \
  "106-7g4'' V1 finding followup_auto_send already off: AS-RB1 wrote nothing (S:9036), the put-back's checkout refused (S:13818), the stop (S:13929): it read off before the put-back was tried (S:8749), no command; $P106 still serving; this deploy's AS_RB1=off"
qcheck106 "106-7g5"
R7G_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7G_106" last):$(kv106 "$R7G_106" file):$(kv106 "$R7G_106" before):$(kv106 "$R7G_106" v1):$(kv106 "$R7G_106" v1guard):$(kv106 "$R7G_106" would)" \
      "written:$(basename "$RB7G_106"):yes:off:pass:offer-if-off" \
  "106-7g6 this rehearsal's own reading before the --after-only (rblast): the latest record 106-7g2's typed one, written, before this deploy's copy; this deploy's AS_RB1=off passes the V1 guard (S:8829) — the key off, as_back_on would offer"
dpre106 "106-7g7" "$P106" off - "on:$LIM106" "written:offer-if-off"
master106 "106-7g8"
aft106 "106-7g9" PASSED $L1ON106
doffer106 "106-7g10"

echo; echo "== 106-7i. the known limit (design-v2 A10; root docs/75 §L.8's base rule — THE OWNER'S DECISION): V1 finding the key off after a typed rollback that wrote, its put-back succeeding, then --redeploy — its --after-only offers nothing =="
dpre106 "106-7i0" "$P106" on - "on:$LIM106" "written:offer-if-off"
rbx106 "106-7i1"; RB7I_106="$RBX106_REC"
dpre106 "106-7i2" "$B106" off - "on:$LIM106" "written:nothing:not-before"
v1run106 "106-7i3"
O="$V1OUT106"
# deploy-5.18.106.sh:9036, :13828, :13928 (the stop; as_para v1:off prints nothing, :8752)
check "$(noteline "$O" AS-RB1 'followup_auto_send already reads off — nothing was written')|$(has "$O" "  the container now serves $B106; the checkout is back on ")|$(has "$O" "  STOP: rolled back (serves $B106); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (already off, AS-RB1 wrote nothing), ")|$(grep -cE -- '--key followup_auto_send --value [01] ' <<<"$O")|$(live)|$(sget106 "$STATE106" AS_RB1)|$(grep -c '^RB_COPY_AT=[0-9]' "$STATE106")" \
      "yes|yes|yes|0|$B106|off|1" \
  "106-7i3' V1 finding followup_auto_send off, its put-back verified: AS-RB1 wrote nothing (S:9036), the stop says rolled back (S:13928), no command; $B106 serving; this deploy's state file AS_RB1=off with the put-back's copy time"
qcheck106 "106-7i4"
dpre106 "106-7i5" "$B106" off - "on:$LIM106" "written:offer-if-off"
dredeploy106 "106-7i6"
R7I_106="$(q106 rblast "$OUT106" "$STATE106")"
check "$(kv106 "$R7I_106" last):$(case "$(kv106 "$R7I_106" file)" in "state-$V106.env.prev-"*) echo prev ;; *) echo other ;; esac):$(kv106 "$R7I_106" would)|$(sget106 "$RB7I_106" AS_RB1)|$( [ "$(sget106 "$RB7I_106" RB_COPY_AT)" -lt "$(kv106 "$R7I_106" t)" ] 2>/dev/null && echo earlier)" \
      "off:prev:nothing:not-written|written|earlier" \
  "106-7i7 this rehearsal's own reading after the --redeploy (rblast): the latest record is V1's (off), kept beside the state file — the typed rollback's (written) is older, and as_rb_last reads only the newest (S:8805-8818)"
dpre106 "106-7i8" "$P106" off - "on:$LIM106" "off:nothing:not-written"
master106 "106-7i9"
aft106 "106-7i10" PASSED $L1ON106
check "$(dnooffer106)|$(kv106 "$(q106 kycplaces "$PD" "$DATA" followup_auto_send)" asplaces)" "none|k:off,s:off,p:-,d:-" \
  "106-7i11 THE KNOWN LIMIT, PINNED — THE OWNER'S DECISION (design-v2 A10, S6; root docs/75 §L.8's base rule): followup_auto_send reads off, set off by 106-7i1's typed rollback (AS_RB1=written in its record), yet this PASSED --after-only offers nothing, because V1 of the deploy before read it off, put back successfully and became the latest record (S:8805-8818, S:8835). Pinned so that a change of this behaviour is seen here"
PL7Z_106="$(q106 kycplaces "$PD" "$DATA" followup_auto_send,evo_api_key,evo_api_url,quote_company_phone,quote_company_email,followup_daily_cap)"
check "$(live)|$(kv106 "$PL7Z_106" cs)|$(kv106 "$(dnow106)" en):$(kv106 "$(dnow106)" hours)|$(kv106 "$PL7Z_106" keys)|$(kv106 "$PL7Z_106" pre13)|$(kv106 "$PL7Z_106" asplaces)|$(lock106)|$(ls -A "$SB/web" | grep -c '^\.redirect106')|$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo none)|$( [ -e "$SB/.ujfail106" ] && echo armed || echo none)|$(ls "$REPO"/scripts/.ac3.sh "$REPO"/scripts/.rbn.sh "$REPO"/scripts/.bov.sh 2>/dev/null | grep -c .)" \
      "$P106|-|on:$LIM106|followup_auto_send@ks,ss;evo_api_key@ks,ss;evo_api_url@ks,ss;quote_company_phone@-;quote_company_email@-;followup_daily_cap@ks,ss|ok|k:off,s:off,p:-,d:-|absent|0|none|none|0" \
  "106-7z what 106-10 begins from: $P106 serving (106-7i6's --redeploy), no claim switch, followup_enabled on at $LIM106 h, followup_auto_send off; the Evolution keys in the file and its store row again, agreeing, the company's phone and address in neither, followup_daily_cap a string in both; no index lock, no V1 trigger, no refusal list, no uj trigger, no weakened copy left"
CLK7Z_106="$(fnow104)"
echo "  note  RH-NOTE 106-6 and 106-7 hand 106-10: $P106 ($V106) serving from 106-7i6's --redeploy; followup_auto_send off; the latest rollback record V1's (off) kept beside the state file; set_evolution's status asked the stand-in Evolution $EVS106 time(s), the sender once (J3); the clock $(kday104 "$CLK7Z_106") $(TZ=Africa/Kampala date -d "@$CLK7Z_106" +%H:%M) Kampala"
