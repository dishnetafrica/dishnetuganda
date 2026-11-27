# Part R — the rollback (root docs/76 §T.7): no write, and §K's drain before it.
#   AS-RB1 retires, and with it AS-RB0, AS-RB2, U5 (as_u5), as_back_on, as_after_verdict, as_para, as_owner_text,
#   as_owner_cmd, OWNER_AUTOSEND_CMD, AS_SUMS, AS_MIGS and AS_PHP: the target is 5.18.106, which carries F5, and 5.18.107
#   changes neither approval nor the run (the four files asserted bf29cbd's below). The script writes nothing again.
#   rb_pre107 replaces rb_pre106 (§T.7 items 1–7): LK-RB0 as before; the switch through the pin's BurstMerge::on over the
#   live PluginConfig::read (rb_switch); the pin's drain on a copy (rb_drain, partSA's sa_bd), again 125 s later when the
#   switch reads off; the capitals and the operator's own commands, each alone, when §K's rule does not hold; K's and Q's
#   off commands with 5.18.107's set_config when LK-RB0 reads either on or cannot read it; the settings text against v9;
#   RB-X's counts (the rbn mode, repurposed). RB_PRE_SAY goes into the ROLLBACK prompt.
#   rb107 replaces rb106: as rb106, without AS-RB2, with RB-X's counts in place of the open quote follow-ups and running
#   offers, and the pin's drain once more (the conversation ids for staff; RB_DRAIN for V1's stop and the summary).
#   V1: no write before do_rollback; its headline says no mark was written (NS-G1, LK-B1) and adds stage A's leftover
#   count (BD_A); after do_rollback, rb107, and the stop worded from rb107's drain reading.
#   The put-back by hand: pb_para (the tools reading, each command worded from the files that decide it, K and Q, the
#   dated switch and drain reading, the site's sentence) at the four mixed-tree refusals, the deploy that did not verify,
#   the rollback that did not verify, V1's rollback that did not complete, and the summary; pb_ready makes the print-time
#   readings in the main shell first (the drain reads on a copy, so never inside the message's $( … )).
#   RB_NOTHING carried, without AS-RB2's branch.
import re
from d107lib import git, sha_at

RB_HEAD = "# ── 5.18.106: the rollback, and its one write (RB106; AS-RB0, AS-RB1, AS-RB2; root docs/75 §L.7) ──"
VN_HEAD = "# ── 5.18.104: Part V's checks — the voice notes (VN) and the acknowledgement (VA)"
RB_FUNCS = {"as_read", "as_places_say", "as_show", "as_causes_say", "as_cmd_ok", "as_owner_text", "as_para", "as_u5",
            "as_after_verdict", "as_rb_last", "as_back_on", "rb_pre106", "as_rb1", "rb106", "as_rb2"}
UNCHANGED_RUN = ["cron/followup_run.php", "lib/FollowUpPolicy.php", "lib/FollowUpService.php", "cron/followup_close.php"]
TOOLS4 = ["lib/SalesFollowupLadder.php", "tools/sales_followup.php", "lib/BurstMerge.php", "tools/set_config.php"]

NEW_HEAD = r"""# ── 5.18.107: the rollback writes nothing; §K's drain before it (RB107; root docs/76 §T.7) ───────────────────────────
# The rollback — --rollback, and V1's automatic one — puts bf29cbd (5.18.106) back through do_rollback (git checkout, the
# documented deploy, stamp_copy on COPY_MARK, RB_COPY_AT) and writes nothing. 5.18.106's AS-RB1 (followup_auto_send '0'
# before the put-back) retired with its hazard, 5.18.105's sender: the target now carries F5, and 5.18.107 changes neither
# approval nor the run — cron/followup_run.php, lib/FollowUpPolicy.php, lib/FollowUpService.php and cron/followup_close.php
# are bf29cbd's byte for byte (derive107.py asserts it). The rollback never runs the switch tool or set_config, at either
# call site, and nothing blocks it: a switch or a drain reading is said, never a refusal (TD6). What 5.18.107 leaves that
# 5.18.106 cannot undo: the burst marks, written only while ai_burst_merge_enabled reads on, which 5.18.106's tool cannot
# drain (it refuses burst-drain as an unknown step) — so root docs/76 §K's drain comes before the rollback, by the
# operator's own commands, each alone; and the K, Q and W keys, which 5.18.106's tools cannot write — so one left on is
# turned off before it, with 5.18.107's tools.
#   rb_pre107 — before ROLLBACK is typed, read-only, never a refusal (below).
#   rb107 — after the put-back, read-only (below); the RB checks it sits among are 5.18.106's, reworded to bf29cbd.
#   pb_para — before PUTBACK_CMD, wherever a message prints the put-back by hand (below).
RB_PRE_SAY=""; RB_DRAIN=""; PB_SW=""; PB_BD=""; PB_AT=""
# RBSW_PHP — the switch as the worker reads it: PINROOT LIVEROOT DATADIR — the pin's lib/PluginConfig.php and
# lib/BurstMerge.php (P3B_PIN's copy, from Git), PluginConfig::read over the LIVE plugin root and data folder (read()
# writes nothing; the vault where the plugin itself finds it), then BurstMerge::on: "sw=on", "sw=off", or
# "sw=unread(<why>)" — a read that returned no key at all is not a reading. One word, never a value. PHP 7.4 syntax.
RBSW_PHP="$(cat <<'PHP'
try {
    [$pin, $live, $data] = [(string)($argv[1] ?? ''), (string)($argv[2] ?? ''), (string)($argv[3] ?? '')];
    @ini_set('error_log', '/dev/null');
    require_once $pin . '/lib/PluginConfig.php';
    require_once $pin . '/lib/BurstMerge.php';
    $c = PluginConfig::read($live, $data);
    if (!is_array($c) || $c === []) { echo 'sw=unread(no-keys)'; exit(0); }
    echo 'sw=', BurstMerge::on($c) ? 'on' : 'off';
} catch (Throwable $e) { echo 'sw=unread(', get_class($e), ')'; }
PHP
)"
# rb_switch — ai_burst_merge_enabled as the worker reads it (root docs/76 §T.7 item 1): "on" or "off" only through a
# working reading — the pin's code present (P3B_PIN), the read returned keys, BurstMerge::on gave a value — else
# "NOT READ (<why>)". As the book's owner; read-only.
rb_switch() {
  local o
  [ -n "${P3B_PIN:-}" ] || { printf 'NOT READ (the pin is not staged: %s)' "${P3B_WHY:-?}"; return 0; }
  [ -n "${DB_OWNER:-}" ] || { printf 'NOT READ (the owner of plugin.sqlite3 is not known)'; return 0; }
  o="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r "$RBSW_PHP" "$P3B_PIN" "$IN_CONTAINER" "$PDD_IN" 2>/dev/null </dev/null)"
  case "$o" in
    sw=on) printf 'on' ;;
    sw=off) printf 'off' ;;
    *) printf 'NOT READ (%s)' "$(printf '%s' "${o:-no answer}" | tr -c 'A-Za-z0-9_=:.,()?-' '_' | cut -c1-60)" ;;
  esac
}
# rb_drain [ids] — the pin's --check burst-drain on a fresh copy of the book, through the pin's code (qf_copy, sa_bd):
# sa_bd's line, "bd=read a=… apart=… b=… control=…" (with "ids", " convs=…" too) or "bd=unread(<why>)". Runs in $( … ):
# its caller makes the run's folder in the main shell first (cih_main).
rb_drain() {
  local sub rc
  [ -n "${P3B_PIN:-}" ] || { printf 'bd=unread(the pin is not staged)'; return 1; }
  sub="$(qf_copy rbd "$P3B_PIN")" || { printf 'bd=unread(no copy: %s)' "$(printf '%s' "${sub:-?}" | cut -c1-60)"; return 1; }
  sa_bd "$sub" "${1:-}"; rc=$?
  cih_drop "$sub"
  return "$rc"
}
rb_bd_say() { case "$1" in "bd=read "*) printf '(a) %s, (b) %s, control %s' "$(kv "$1" a)" "$(kv "$1" b)" "$(kv "$1" control)" ;; *) printf 'not read (%s)' "${1#bd=}" ;; esac; }   # rb_bd_say LINE — a drain reading's counts
rb_bd_zero() { case "$1" in "bd=read "*) [ "$(kv "$1" a)" = "0" ] && [ "$(kv "$1" b)" = "0" ] && [ "$(kv "$1" control)" != "0" ] ;; *) return 1 ;; esac; }   # rb_bd_zero LINE — (a) 0 and (b) 0 with a control above 0: a reading §K counts
# rb_bd_staff LINE — a drain reading after the rollback, for staff: the conversation ids in capitals, since 5.18.106
# cannot drain; a control of 0 is INDETERMINATE; never "nothing to drain"
rb_bd_staff() {
  local cv
  case "$1" in "bd=read "*) ;; *) printf "THE PIN'S DRAIN AFTER THE ROLLBACK COULD NOT BE READ (%s): WHETHER MARKS WAIT IS NOT ESTABLISHED" "${1#bd=}"; return 0 ;; esac
  cv="$(kv "$1" convs)"
  if [ "$(kv "$1" control)" = "0" ]; then printf "THE PIN'S DRAIN AFTER THE ROLLBACK IS INDETERMINATE — NO TYPED MESSAGE IN THE LAST DAY (%s)%s" "$(rb_bd_say "$1")" "$( [ "${cv:--}" = "-" ] || echo "; CONVERSATIONS IT LISTS, FOR STAFF ($BASELINE_VERSION CANNOT DRAIN): $cv")"
  elif [ "${cv:--}" = "-" ]; then printf "the pin's drain after the rollback, on a copy of the book: %s — it lists no conversation" "$(rb_bd_say "$1")"
  else printf "THE PIN'S DRAIN AFTER THE ROLLBACK, ON A COPY OF THE BOOK: %s — HAND THESE CONVERSATIONS TO STAFF ($BASELINE_VERSION CANNOT DRAIN THEM): %s" "$(rb_bd_say "$1")" "$cv"; fi
}
# rb_kq LKLINE KEY — "on", "off" or "unread": the switch KEY as NS-G1 judges it, on an LK reading (its ns= places reading
# on; its positive control nsctl=ok). A reading that is not whole, or a control that did not hold, is "unread".
rb_kq() {
  lk_ok "$1" && [ "$(kv "$1" nsctl)" = "ok" ] || { printf 'unread'; return 0; }
  case ",$(kv "$1" ns)," in *",$2:"*) printf 'on' ;; *) printf 'off' ;; esac
}
# owner_set_cmd KEY — the owner's own command turning KEY off with the installed tools/set_config.php (--value 0), as the
# book's owner on the live data folder, its output cut where the settings listing begins, with its own log (root docs/76
# §T.9's owner-command form). Printed alone on its line; never run by this script.
owner_set_cmd() { printf "docker exec -u %s -e DN_DATA_DIR=%s %s php %s/tools/set_config.php --key %s --value 0 2>&1 | sed '/^  AI SETTINGS\$/,\$d' %s" "$DB_OWNER" "$PDD_IN" "$CONTAINER" "$IN_CONTAINER" "$1" "$(LOGW "setoff-$1")"; }
# rb_x_say LINE — RB-X's counts (the rbn reading) with what 5.18.106 does with each (root docs/76 §T.7 item 7)
rb_x_say() {
  local n a
  n="$(kv "$1" nai)"; a="$(kv "$1" nalert)"
  printf 'active wa_no_ai rows %s — no %s file reads the list, so every number on it gets AI replies, follow-ups, chases, offers and the win-back again; pending wa.no_ai_alert events %s — %s'"'"'s event processor acks a queued alert as unknown. The closes Part X and Part K made stay: closes are permanent' "$( [ "$n" = "absent" ] && echo "none (no table)" || echo "$n")" "$BASELINE_VERSION" "${a:-?}" "$BASELINE_VERSION"
}
"""

PRE107 = r"""# rb_pre107 — before ROLLBACK is typed (root docs/76 §T.7; read-only, never a refusal), in order: LK-RB0 as before — the
# ladder's keys in the four places under the kept LK key (one of this run's own when the deploy's key file is not there),
# the rows rb107 judges "never fell" against, and the tool's show and the settings text on a copy (the pin's code); then
# 1. the switch as the worker reads it (rb_switch); 2. the pin's drain on a copy (rb_drain); 3. when the switch reads off,
# 125 s, then both again — two readings of (a) 0 and (b) 0, each with a control above 0 and the switch off at both,
# satisfy §K by themselves; 4. otherwise, in capitals before the question, what to do — SWITCH NOT READ and its reason
# first when it is — and each command alone on its line: §K's switch-off (unless the switch reads off through a working
# reading) and --check burst-drain, with §K's rule and way out, said now because 5.18.106's tool cannot drain; 5. K's
# and Q's off commands with 5.18.107's set_config, each alone, when LK-RB0 reads either on or cannot read it (TD7);
# 6. the settings text against v9 — if it is v10, the v9-restore handed over with its save comes first (§K step 2); this
# script prints no command that writes it; 7. RB-X's counts. The operator is never told to change anything while this
# script waits at the ROLLBACK prompt: every alternative is to answer anything but ROLLBACK, run the command alone, then
# run the rollback again — each command printed alone on its own line, never run by this script, never in one block
# with the rollback command (root docs/44 §16.9). RB_PRE_SAY carries the switch and the last drain reading into the
# ROLLBACK prompt.
rb_pre107() {
  local o sub="" s1 b1 s2="" b2="" held="" k q t
  hdr "B. Before ROLLBACK: the ladder, the burst marks and the switches as they stand (LK-RB0, RB107; read-only — the rollback writes nothing and keeps every switch; root docs/76 §T.7)"
__LK__
  # 1, 2 — the switch, as the worker reads it, and the pin's drain on a copy
  s1="$(rb_switch)"; b1="$(rb_drain)"
  note "RB107 ai_burst_merge_enabled as the worker reads it (the pin's BurstMerge::on over PluginConfig::read of the live files): $s1; the pin's --check burst-drain on a copy of the book: $(rb_bd_say "$b1") — read at $(utc_of "$(date -u +%s)")"
  # 3 — off: 125 s, then both again
  if [ "$s1" = "off" ]; then
    echo "  RB107 waiting 125 s, then reading the switch and the drain again (root docs/76 §K: the second reading at least 2 minutes after the switch-off)"
    sleep 125
    s2="$(rb_switch)"; b2="$(rb_drain)"
    note "RB107 again, 125 s later: ai_burst_merge_enabled $s2; the drain $(rb_bd_say "$b2") — read at $(utc_of "$(date -u +%s)")"
    [ "$s2" = "off" ] && rb_bd_zero "$b1" && rb_bd_zero "$b2" && held=1
  fi
  [ -n "$s2" ] || s2="$s1"; [ -n "$b2" ] || b2="$b1"
  RB_PRE_SAY="ai_burst_merge_enabled $s2; the last drain reading $(rb_bd_say "$b2")"
  if [ -n "$held" ]; then
    note "RB107 root docs/76 §K's drain holds: two readings 125 s apart, each (a) 0 and (b) 0 with a control above 0, ai_burst_merge_enabled off at both — nothing waits on a burst mark"
  else
    # 4 — the capitals, the switch first when it is not read; then the operator's own commands, each alone
    case "$s2" in "NOT READ"*) note "SWITCH NOT READ — ai_burst_merge_enabled: $s2" ;; esac
    note "ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN YOUR OWN COMMANDS, EACH ALONE, THEN RUN THE ROLLBACK AGAIN: root docs/76 §K's drain does not hold yet (ai_burst_merge_enabled $s2; the drain $(rb_bd_say "$b2"))$( [ "$s2" = "off" ] || echo ". $BASELINE_VERSION'S TOOLS CANNOT TURN ai_burst_merge_enabled OFF AFTERWARDS, AND A REDEPLOY'S NS-G1 REFUSES WHILE IT READS ON, UNTIL THE KEY IS REPAIRED AS root docs/76 §T.10's KNOWN LIMITS SAY; AND MARKS WRITTEN UP TO THE COPY ARE GATHERED BY NOBODY, SO RB107 LISTS THEIR CONVERSATION IDS FOR STAFF")"
    echo
    [ "$s2" = "off" ] || echo "      cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0"   # §K's switch-off: printed, never run (RB107)
    echo "      cd $REPO && bash $QF_WRAPPER --check burst-drain"   # §K's drain reading: printed, never run (RB107)
    echo
    note "RB107 each command logs itself in /root/dnb-sales$( [ "$s2" = "off" ] || echo "; the switch-off, run on a switch already off, leaves '0', which NS-G1 accepts"). §K's rule: until (a) and (b) both read 0 twice, the second reading at least 2 minutes after the switch-off; a reading whose control is 0 does not count. §K's way out: if the rollback cannot wait, roll back anyway and hand the conversation ids the reading lists to staff. Said now because after the rollback $BASELINE_VERSION's tool cannot drain: it refuses burst-drain as an unknown step. The rollback is never refused for any of this"
  fi
  # 5 — K and Q, as NS-G1 judges them on LK-RB0
  for k in quoted_no_prices_enabled connection_quote_enabled; do
    q="$(rb_kq "${LK_RB0:-}" "$k")"
    [ "$q" = "off" ] && continue
    if [ -n "$DB_OWNER" ]; then
      note "$k $( [ "$q" = "on" ] && echo "READS ON" || echo "COULD NOT BE READ") (NS-G1'S RULE, ON LK-RB0): $BASELINE_VERSION'S TOOLS CANNOT TURN IT OFF AFTERWARDS, AND A REDEPLOY REFUSES WHILE IT READS ON. ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — TURN IT OFF NOW WITH $EXPECTED_VERSION'S set_config, ITS OWN COMMAND, ALONE, WITH ITS OWN LOG, THEN RUN THE ROLLBACK AGAIN:"
      echo
      echo "      $(owner_set_cmd "$k")"   # printed, never run (RB107)
      echo
      note "RB107 it prints the line \"  $k = 0\" once it has saved; without that line take nothing as saved"
    else note "$k $( [ "$q" = "on" ] && echo "READS ON" || echo "COULD NOT BE READ") (NS-G1'S RULE, ON LK-RB0): $BASELINE_VERSION'S TOOLS CANNOT TURN IT OFF AFTERWARDS — NO COMMAND CAN BE PRINTED: THE OWNER OF plugin.sqlite3 IS NOT KNOWN"; fi
  done
  # 6 — the settings text, as LK-RB0's copy read it
  case "${RB_PRE_TXT:-}" in
    v9) note "RB107 the settings text reads v9 as the workers read it (its sha256 prefix the recorded $SA_TXT_V9): nothing to restore before the rollback" ;;
    *) note "RB107 the settings text does not read v9 as the workers read it (${RB_PRE_TXT:-not read}): if it is v10, run the v9-restore command handed over with v10's save (root docs/76 §K step 2) before the rollback — under $BASELINE_VERSION, v10's first sentence speaks of a marker the prompt never offers. This script prints no command that writes bot_custom_instructions; a note, never a refusal" ;;
  esac
  # 7 — RB-X's counts, read-only on the live book
  o="$(qf_php rbn "$PDD_IN")"
  case "$o" in nai=*) note "RB-X before the rollback: $(rb_x_say "$o")" ;; *) note "RB-X the list's rows and the pending alerts could not be counted before the rollback (${o:-not read})" ;; esac
  return 0
}
"""

PB = r"""# pb_tool REL — the installed REL, by sha256 on the host, against the pin's (PIN_SHA256S) and bf29cbd's
# (PREV_CHANGED_SHA256S, Q-G1's constants): "pin", "base", "absent" (lib/BurstMerge.php alone, which bf29cbd does not
# have: its absence is a reading of a 5.18.106 tree), "neither" (an edit by hand) or "unread" ($DEST missing, or one of
# the files bf29cbd has that cannot be hashed). No pin and no docker exec.
pb_tool() {
  local h p b
  [ -d "$DEST" ] || { printf 'unread'; return 0; }
  if [ ! -e "$DEST/$1" ]; then if [ "$1" = "lib/BurstMerge.php" ]; then printf 'absent'; else printf 'unread'; fi; return 0; fi
  h="$(sha256sum "$DEST/$1" 2>/dev/null | cut -c1-64)"
  [ "${#h}" = "64" ] || { printf 'unread'; return 0; }
  p="$(printf '%s\n' "$PIN_SHA256S" | awk -v f="$1" '$2 == f { print $1 }')"
  b="$(printf '%s\n' "$PREV_CHANGED_SHA256S" | awk -v f="$1" '$2 == f { print $1 }')"
  if [ -n "$p" ] && [ "$h" = "$p" ]; then printf 'pin'; elif [ -n "$b" ] && [ "$h" = "$b" ]; then printf 'base'; else printf 'neither'; fi
}
pb_word() { case "$1" in pin) printf "the pin's" ;; base) printf "%s's" "${BASELINE_COMMIT:0:7}" ;; absent) printf 'absent' ;; neither) printf 'neither (an edit by hand)' ;; *) printf 'not read' ;; esac; }   # pb_word READING — a tools reading as said
# pb_ready — the put-back paragraph's print-time readings, in the MAIN shell, just before a message that prints it (the
# drain reads on a copy, so it never runs inside that message's $( … )): PB_SW the switch (rb_switch), PB_BD one drain
# reading (rb_drain), PB_AT when. Read-only; never stops. Stage A's mixed-tree refusals need none: they come before the
# pin is staged, and pb_para says so itself.
pb_ready() {
  PB_AT="$(date -u +%s)"
  if [ -z "${P3B_PIN:-}" ]; then PB_SW="NOT READ (the pin is not yet staged)"; PB_BD="bd=unread(the pin is not yet staged)"; return 0; fi
  cih_main || true
  PB_SW="$(rb_switch)"; PB_BD="$(rb_drain)"
  return 0
}
# pb_para pre|deploy|rb|v1|sum — the put-back paragraph (root docs/76 §T.7), before PUTBACK_CMD wherever a message
# prints it: the tools reading made now; §K's switch-off and drain, each alone on its line, each worded from the files
# that decide it — never assumed from the site — with §K's rule and way out ("it leaves '0'" only beside a switch-off
# worded as running; beside any command not worded as running, that a refusal changes nothing and saves nothing; for a
# switch-off not worded as running, §T.10's known-limit repair); K's and Q's off commands where the run's latest LK
# reading reads either on or cannot read it, each worded from tools/set_config.php; the dated switch and drain reading
# (pb_ready's; "the pin is not yet staged" before it is) — one reading, never "nothing to drain"; then the site's own
# sentence. Text only, starting with a blank line: a stop message carries it, the summary prints it. It reads nothing
# under the run's folder: it runs in $( … ).
pb_para() {
  local l t b s wl wd ws ref sw bd at k q kq any=""
  l="$(pb_tool lib/SalesFollowupLadder.php)"; t="$(pb_tool tools/sales_followup.php)"; b="$(pb_tool lib/BurstMerge.php)"; s="$(pb_tool tools/set_config.php)"
  case "$l:$t" in pin:pin|pin:base) wl="runs, and leaves '0'" ;; base:pin|base:base) wl="is refused (undeclared)" ;; *) wl="may be refused" ;; esac
  case "$t:$b" in pin:pin) wd="runs" ;; base:*) wd="is refused (an unknown step)" ;; pin:absent) wd="fails without reading anything" ;; pin:neither) wd="may fail (it may also run on bytes that are not the pin's)" ;; *) wd="may be refused" ;; esac
  case "$s" in pin) ws="runs" ;; base) ws="is refused (unmanaged)" ;; *) ws="may be refused" ;; esac
  printf '\n\n  BEFORE ANY PUT-BACK BY HAND (IT WRITES NOTHING): root docs/76 §K'"'"'s two commands, each alone, each logging itself in /root/dnb-sales. The tools installed now, by sha256 on the host against the pin'"'"'s and %s'"'"'s: lib/SalesFollowupLadder.php %s, tools/sales_followup.php %s, lib/BurstMerge.php %s, tools/set_config.php %s.' "${BASELINE_COMMIT:0:7}" "$(pb_word "$l")" "$(pb_word "$t")" "$(pb_word "$b")" "$(pb_word "$s")"
  printf '\n  The switch-off — it %s:\n      cd %s && bash %s set ai_burst_merge_enabled 0' "$wl" "$REPO" "$QF_WRAPPER"
  printf '\n  The drain reading — it %s:\n      cd %s && bash %s --check burst-drain' "$wd" "$REPO" "$QF_WRAPPER"
  printf '\n  §K'"'"'s rule: until (a) and (b) both read 0 twice, the second reading at least 2 minutes after the switch-off; a reading whose control is 0 does not count. §K'"'"'s way out: if the rollback cannot wait, roll back anyway and hand the conversation ids the reading lists to staff.'
  case "$wl" in runs*) ;; *) any=1 ;; esac
  case "$wd" in runs) ;; *) any=1 ;; esac
  [ -z "$any" ] || printf ' A refusal changes nothing and saves nothing.'
  case "$wl" in runs*) ;; *) printf ' Where the switch-off does not run, the repair is root docs/76 §T.10'"'"'s known limit: the owner'"'"'s authorised edit of kyc_config.json — never this script.' ;; esac
  ref="${LK_AO_LINE:-${LK_RB0:-${LK_B1:-${LK_G1:-}}}}"
  for k in quoted_no_prices_enabled connection_quote_enabled; do
    q="$(rb_kq "$ref" "$k")"
    [ "$q" = "off" ] && continue
    kq="$( [ "$q" = "on" ] && echo "reads on" || echo "cannot be read")"
    if [ -n "${DB_OWNER:-}" ]; then
      printf '\n  %s %s (the run'"'"'s latest reading of the ladder'"'"'s keys): once %s'"'"'s tools are installed none can write it. Its own command, alone, with its own log — it %s:\n      %s\n  It prints the line "  %s = 0" once it has saved; without that line take nothing as saved.' "$k" "$kq" "$BASELINE_VERSION" "$ws" "$(owner_set_cmd "$k")" "$k"
      case "$ws" in runs) ;; *) printf ' A refusal changes nothing and saves nothing; the repair is root docs/76 §T.10'"'"'s known limit: the owner'"'"'s authorised edit of kyc_config.json — never this script.' ;; esac
    else printf '\n  %s %s: once %s'"'"'s tools are installed none can write it — no command can be printed: the owner of plugin.sqlite3 is not known.' "$k" "$kq" "$BASELINE_VERSION"; fi
  done
  if [ -z "${P3B_PIN:-}" ]; then sw="NOT READ (the pin is not yet staged)"; bd="bd=unread(the pin is not yet staged)"; at="$(date -u +%s)"
  elif [ -n "${PB_AT:-}" ]; then sw="$PB_SW"; bd="$PB_BD"; at="$PB_AT"
  else sw="NOT READ (no reading was made for this message)"; bd="bd=unread(no reading was made for this message)"; at="$(date -u +%s)"; fi
  printf '\n  Read at %s: switch %s; the drain %s. One reading, not a drain.' "$(utc_of "$at")" "$sw" "$(rb_bd_say "$bd")"
  case "$1" in
    pre) printf ' (Stage A refused before the pin was staged: the ladder'"'"'s keys were not read, so K'"'"'s and Q'"'"'s lines print.)' ;;
    deploy|v1) printf ' NS-G1 and NS-B1 read all three switches off before the copy, so nothing here needs repair unless a switch was changed during the run, which the header forbids.' ;;
    rb) printf ' rb_pre107 printed the off commands before ROLLBACK; if a switch was left on and %s'"'"'s tools are now installed, root docs/76 §T.10'"'"'s known-limits repair is the route that remains.' "$BASELINE_VERSION" ;;
  esac
}
"""


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed = ctx["added"], ctx["changed"]
    import partA
    libs = [f[4:-4] for f in partA.LIB_ADDED]
    for f in UNCHANGED_RUN:
        if f in changed or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"partR: {f} is not bf29cbd's byte for byte at the pin — AS-RB1's retirement rests on it (§T.7)")
    for f in TOOLS4:
        if f not in changed and f not in added:
            raise SystemExit(f"partR: {f} is not in the delta — the tools reading compares it with the pin's")
    if "lib/BurstMerge.php" not in added:
        raise SystemExit("partR: lib/BurstMerge.php is not added by the pin — pb_tool's 'absent' rests on it")
    tool = git(repo, "show", f"{pin}:{plugin}/tools/sales_followup.php").decode()
    if "require_once $root . '/lib/BurstMerge.php';" not in tool:
        raise SystemExit("partR: the pin's tool does not require lib/BurstMerge.php in burst-drain — pb_para's wording rests on it")
    btool = git(repo, "show", f"{base}:{plugin}/tools/sales_followup.php").decode()
    if "burst-drain" in btool:
        raise SystemExit("partR: bf29cbd's tool knows burst-drain — 'is refused (an unknown step)' would be wrong")
    for f in ("lib/SalesFollowupLadder.php", "tools/set_config.php"):
        if "ai_burst_merge_enabled" in git(repo, "show", f"{base}:{plugin}/{f}").decode():
            raise SystemExit(f"partR: bf29cbd's {f} names ai_burst_merge_enabled")
    if "quoted_no_prices_enabled" in git(repo, "show", f"{base}:{plugin}/tools/set_config.php").decode():
        raise SystemExit("partR: bf29cbd's set_config manages K — 'is refused (unmanaged)' would be wrong")
    for k in ("quoted_no_prices_enabled", "connection_quote_enabled"):
        if f"'{k}'" not in git(repo, "show", f"{pin}:{plugin}/tools/set_config.php").decode():
            raise SystemExit(f"partR: the pin's set_config does not manage {k}")

    # ── the RB block: AS-RB0/1/2, U5, the as_ family, rb_pre106 and rb106 out; rb_pre107, rb107, pb_* in ──
    i, j = doc.t.index(RB_HEAD), doc.t.index(VN_HEAD)
    if doc.t.count(RB_HEAD) != 1 or doc.t.count(VN_HEAD) != 1 or not i < j:
        raise SystemExit("partR: the RB block's headers are not each once, in order")
    blk = doc.t[i:j]
    funcs = set(re.findall(r"^([a-z_0-9]+)\(\) \{", blk, re.M))
    if funcs != RB_FUNCS:
        raise SystemExit(f"partR: the RB block defines {sorted(funcs)}, not the fifteen expected")
    for s in ('AS_SUMS="$(cat <<\'SUMS\'', "AS_MIGS=", 'AS_PHP="$(cat <<\'PHP\''):
        if blk.count(s) != 1:
            raise SystemExit(f"partR: the RB block does not hold {s!r} once")
    # LK-RB0's lines, carried from rb_pre106, reworded
    a = blk.index("  cih_main || note \"LK-RB0 the run's folder")
    b = blk.index("  # AS-RB0 — followup_auto_send, per place and as the jobs read it")
    lk = blk[a:b]
    for old, new in [
        ("— the rollback keeps every one (its one write, followup_auto_send, is not a ladder key: AS-RB1)\"",
         "— the rollback keeps every one (it writes nothing)\""),
        ("(RB106 judges \\\"never fell\\\" against them)", "(RB107 judges \\\"never fell\\\" against them)"),
        ("  LK_RB0_SHOW=\"\"\n  if [ -n \"${P3B_PIN:-}\" ] && sub=\"$(qf_copy rb0 \"$P3B_PIN\")\"; then LK_RB0_SHOW=\"$(lk_show_lines \"$(qf_tool \"$sub\" show)\")\"; cih_drop \"$sub\"; fi\n",
         "  LK_RB0_SHOW=\"\"; RB_PRE_TXT=\"\"\n  if [ -n \"${P3B_PIN:-}\" ] && sub=\"$(qf_copy rb0 \"$P3B_PIN\")\"; then LK_RB0_SHOW=\"$(lk_show_lines \"$(qf_tool \"$sub\" show)\")\"; RB_PRE_TXT=\"$(kv \"$(qf_php sa \"$sub/code\" \"$sub\" \"$SA_TXT_V9\")\" txt)\"; cih_drop \"$sub\"; fi\n"),
    ]:
        if lk.count(old) != 1:
            raise SystemExit(f"partR: LK-RB0's lines do not hold, once: {old[:90]!r}")
        lk = lk.replace(old, new)
    pre = PRE107.replace("__LK__\n", lk)
    # rb107, from rb106: the same readings, without AS-RB2; RB-X in place of the open quotes and offers; the drain
    a = blk.index("RB_NOTHING=\"\"\nrb106() {\n")
    b = blk.index("\n}\n", a) + 3
    rb = blk[a:b]
    reps = [
        ("RB_NOTHING=\"\"\nrb106() {\n", "RB_NOTHING=\"\"\nrb107() {\n", 1),
        ("ok \"RB106 the installed $MASTER", "ok \"RB107 the installed $MASTER", 1),
        ("bad \"RB106 the installed $MASTER", "bad \"RB107 the installed $MASTER", 1),
        (" — the rollback kept every ladder switch (its one write, followup_auto_send, is not a ladder key: AS-RB1): ", " — the rollback kept every ladder switch (it writes nothing): ", 1),
        (" — the rollback writes none of them (its one write, followup_auto_send, is not a ladder key); was ", " — the rollback writes none of them (it writes nothing); was ", 1),
        ("  o=\"$(qf_php rbn \"$PDD_IN\")\"\n  case \"$o\" in\n    open_quote=*) note \"RB106 open quote follow-ups $(qf_kv \"$o\" open_quote), offers running $(qf_kv \"$o\" running_offers) — $BASELINE_VERSION's own, which carry on under it as their switches say (root docs/75 §L.7)\" ;;\n    *) note \"RB106 the open quote follow-ups and the running offers could not be counted (${o:-not read})\" ;;\n  esac\n",
         "  o=\"$(qf_php rbn \"$PDD_IN\")\"\n  case \"$o\" in nai=*) note \"RB-X after the rollback: $(rb_x_say \"$o\")\" ;; *) note \"RB-X the list's rows and the pending alerts could not be counted after the rollback (${o:-not read})\" ;; esac\n", 1),
        ("  lm_rb1        # LM-RB1 — 5.18.105's masked lines still in place under 7835906, counted as blocks (root docs/75 §L.6)\n  as_rb2        # AS-RB2 — followup_auto_send off through 7835906's code (root docs/75 §L.7)\n",
         "  lm_rb1        # LM-RB1 — 5.18.105's masked lines still in place under bf29cbd, counted as blocks (root docs/76 §T.2)\n"
         "  RB_DRAIN=\"$(rb_drain ids)\"   # the pin's drain once more, on a copy (root docs/76 §T.7): the ids for staff, a note\n"
         "  note \"RB107 $(rb_bd_staff \"$RB_DRAIN\")\"\n", 1),
        ("  vn_rb_after   # VN-RB5 — a note: 090's tables stay and continue under 7835906\n", "  vn_rb_after   # VN-RB5 — a note: 090's tables stay and continue under bf29cbd\n", 1),
        ("  of_rb_after   # OF-RB2 — a note: the four offer tables stay and continue under 7835906 as its switches say\n", "  of_rb_after   # OF-RB2 — a note: the four offer tables stay and continue under bf29cbd as its switches say\n", 1),
        ("  ac_rb_after   # AC-RB2 — a note: P7's two collections stay and continue under 7835906 as its switch says\n", "  ac_rb_after   # AC-RB2 — a note: P7's two collections stay and continue under bf29cbd as its switch says\n", 1),
    ]
    for old, new, n in reps:
        if rb.count(old) != n:
            raise SystemExit(f"partR: rb106's body does not hold {n}x: {old[:100]!r}")
        rb = rb.replace(old, new)
    for s in ("as_rb2", "AS-RB", "RB106", "followup_auto_send", "7835906"):
        if s in rb:
            raise SystemExit(f"partR: rb107 still holds {s!r}")
    rbc = ("# rb107 — RB107 (root docs/76 §T.7), after the put-back, read-only: master as bf29cbd has it; LK-RB1 — the ladder's keys\n"
           "# through the pin-only reader, the installed tool's show and the rows as before the rollback (against LK-RB0, or in\n"
           "# V1's automatic rollback, which takes no LK-RB0, the deploy's LK-B1); RB-X's counts, each with what 5.18.106 does with\n"
           "# it; LM-RB1, 5.18.105's masked lines still in place; the pin's drain once more on a copy, a note with the conversation\n"
           "# ids to hand to staff, since 5.18.106 cannot drain (RB_DRAIN keeps it for V1's stop); then Part V's, Part O's and Part\n"
           "# P's notes. Reports; never stops.\n"
           "# RB_NOTHING — set only by --rollback when the container already served bf29cbd as the run began (nothing to roll back:\n"
           "# a completed rollback checked again, or a by-hand put-back checked). That run types no ROLLBACK and puts nothing back,\n"
           "# so it takes no LK-RB0 and LK-RB1 — judged across the put-back, against LK-RB0 — has no before to compare with. A\n"
           "# reading taken by this run itself, moments before the \"after\" one, would bracket no rollback, and an ok over it would\n"
           "# claim a kept switch nobody observed. So rb107 says plainly that the three comparisons (the keys, the tool's show, the\n"
           "# rows) are not judged, with the ladder as it reads now for the record; master, RB-X, LM-RB1 and the drain are absolute\n"
           "# and run as on every path. No rollback writes anything (root docs/76 §T.7).\n")
    new_blk = NEW_HEAD + pre + rbc + rb + PB + "\n"
    doc.between(RB_HEAD, VN_HEAD, new_blk + VN_HEAD, keep_ends=False, why="the RB block (§T.7)")

    # ── stage A: U5 goes (the one reader); its phrase in the stage-A comment too ──
    doc.sub("  as_u5   # U5 — 5.18.106 (root docs/75 §L.4): whether a rollback could write followup_auto_send, §L.7's one reader on the live files; a note\n",
            "", why="U5 retires (§T.7)")
    doc.sub("  # through the PIN's code from Git (U5 on the live settings file and store row, read-only). A refusal stops here:\n",
            "  # through the PIN's code from Git. A refusal stops here:\n", why="stage A's comment: U5 gone")

    # ── the owner's followup_auto_send command goes; PUTBACK_HAZARD's comment names pb_para ──
    a = "# 5.18.106 (root docs/75 §L.7): the owner's own command for followup_auto_send — built here, at print time, by every\n"
    b = "  OWNER_AUTOSEND_CMD=\"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php --key followup_auto_send --value $v 2>&1 | sed '/^  AI SETTINGS\\$/,\\$d' $(LOGW \"autosend-$v\")\"\n}\n"
    doc.between(a, b, "", keep_ends=False, why="as_owner_cmd retires")
    doc.sub("# it. Literal releases: the sentence is the same wherever it is printed. 5.18.106 (root docs/75 §L.7): the put-back\n"
            "# writes no setting, so it does not switch followup_auto_send off as the scripted rollback does first (AS-RB1); every\n"
            "# site that prints it says so (as_para) with the owner's own command in its own paragraph, built at print time — after\n"
            "# DB_OWNER is read, below — and, after a rollback that did not verify, only when AS-RB1 did not write, once.\n",
            "# it. Literal releases: the sentence is the same wherever it is printed. 5.18.107 (root docs/76 §T.7): every site that\n"
            "# prints the put-back prints, just before it, the put-back paragraph (pb_para): the tools reading made at print time,\n"
            "# §K's switch-off and drain worded from the files that decide them, K's and Q's off commands where either reads on or\n"
            "# cannot be read, and a dated switch and drain reading; the sites after the pin is staged make that reading first, in\n"
            "# the main shell (pb_ready).\n", why="PUTBACK_HAZARD's comment")

    # ── P3B_PIN and P3B_WHY defined before stage A's first mixed-tree refusal (set -u) ──
    doc.sub("&& stop \"--check is stage A before a deploy, and the container already serves $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION)",
            "&& stop \"--check is stage A before a deploy, and the container already serves $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION)",
            why="the anchor before stage A's mode case, once")
    i = doc.t.index("&& stop \"--check is stage A before a deploy, and the container already serves $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION)")
    j = doc.t.index("\ncase \"$MODE\" in\n  deploy)\n", i)
    if doc.t[i:j].count("\n") != 0:
        raise SystemExit("partR: stage A's mode case does not follow the --check line directly")
    doc.insert_after(doc.t[i:j] + "\n",
                     "P3B_PIN=\"\"; P3B_WHY=\"the pin is not yet staged\"   # 5.18.107 (root docs/76 §T.7): pb_para reads them at the mixed-tree refusals below, before the pin is staged (set again where it is)\n",
                     why="P3B_PIN before the mixed-tree refusals")

    # ── the put-back sites ──
    doc.sub("$PUTBACK_HAZARD$(as_para pb) Put $BASELINE_VERSION back by hand first, with its own command, below — then run this script again, and send both logs:$PUTBACK_SHOW\"",
            "$PUTBACK_HAZARD$(pb_para pre) Put $BASELINE_VERSION back by hand first, with its own command, below — then run this script again, and send both logs:$PUTBACK_SHOW\"",
            n=2, why="mixed-tree refusals (deploy): the paragraph")
    doc.sub("$PUTBACK_HAZARD$(as_para pb) Put $BASELINE_VERSION back by hand first, with its own command, below — then run this again, and send both logs:$PUTBACK_SHOW\"",
            "$PUTBACK_HAZARD$(pb_para pre) Put $BASELINE_VERSION back by hand first, with its own command, below — then run this again, and send both logs:$PUTBACK_SHOW\"",
            why="mixed-tree refusal (--move-list-only): the paragraph")
    doc.sub("$PUTBACK_HAZARD$(as_para pb) Put $BASELINE_VERSION back by hand, with its own command, below — then run this command again, and send both logs:$PUTBACK_SHOW\"",
            "$PUTBACK_HAZARD$(pb_para pre) Put $BASELINE_VERSION back by hand, with its own command, below — then run this command again, and send both logs:$PUTBACK_SHOW\"",
            why="mixed-tree refusal (the rollback modes): the paragraph")
    doc.sub("  elif [ \"$LIVE_AFTER\" = \"$BASELINE_COMMIT\" ]; then\n    stop \"deploy did not verify (rc=$RC): the container's record still names $BASELINE_COMMIT",
            "  elif [ \"$LIVE_AFTER\" = \"$BASELINE_COMMIT\" ]; then\n    pb_ready   # 5.18.107 (root docs/76 §T.7): the put-back paragraph's readings, in this shell\n    stop \"deploy did not verify (rc=$RC): the container's record still names $BASELINE_COMMIT",
            why="deploy that did not verify: pb_ready")
    doc.sub("and the --rollback command, which rolls back only a deploy the record shows, refuses over them. $PUTBACK_HAZARD$(as_para pb) Put",
            "and the --rollback command, which rolls back only a deploy the record shows, refuses over them. $PUTBACK_HAZARD$(pb_para deploy) Put",
            why="deploy that did not verify: the paragraph")
    doc.mustnot("$(as_para pb)")

    # ── the typed rollback ──
    doc.sub("    rb_pre106   # 5.18.106 (root docs/75 §L.7): LK-RB0, followup_auto_send and §L.7's five conditions (AS-RB0), the claim switches — before ROLLBACK is typed, never a refusal\n",
            "    rb_pre107   # 5.18.107 (root docs/76 §T.7): LK-RB0, the switch and §K's drain, K and Q, the settings text, RB-X — before ROLLBACK is typed, never a refusal\n",
            why="rb_pre107's call")
    doc.line("    printf '  Type ROLLBACK to put %s (%s) back over live %s — %s, every other switch of the sales follow-up kept as it is — anything else to stop: '",
             "    printf '  Type ROLLBACK to put %s (%s) back over live %s — %s; nothing written, every switch of the sales follow-up kept as it is — anything else to stop: ' \"$BASELINE_VERSION\" \"$BASELINE_COMMIT\" \"$LIVE_BEFORE\" \"${RB_PRE_SAY:-ai_burst_merge_enabled not read; no drain reading}\"",
             why="the ROLLBACK prompt")
    doc.sub("    # 5.18.106 (root docs/75 §L.7, DL4–DL6): AS-RB1 first — followup_auto_send '0', the one write in this script, when\n"
            "    # it reads on and §L.7's five conditions hold, recomputed in its own process; never a reason to stop — then\n"
            "    # do_rollback: no switch tool, no other key, no by-hand put-back of rows or keys.\n"
            "    as_rb1 typed\n",
            "    # 5.18.107 (root docs/76 §T.7): no write before the put-back (AS-RB1 retired) — do_rollback: no switch tool, no\n"
            "    # set_config, no key, no by-hand put-back of rows or keys.\n", why="the typed rollback: no write")
    doc.sub("else stop \"the rollback did not verify (live=$(live_commit)). $PUTBACK_HAZARD$(as_para rb) Put $BASELINE_VERSION back by hand, with its own command:$PUTBACK_SHOW\"",
            "else pb_ready; stop \"the rollback did not verify (live=$(live_commit)). $PUTBACK_HAZARD$(pb_para rb) Put $BASELINE_VERSION back by hand, with its own command:$PUTBACK_SHOW\"",
            why="a rollback that did not verify")
    doc.sub("fi   # nothing to roll back: rb106 judges no LK-RB1, and nothing is written — AS-RB2 says so (root docs/75 §L.7)",
            "fi   # nothing to roll back: rb107 judges no LK-RB1, and nothing is written (root docs/76 §T.7)", why="RB_NOTHING's line")

    # ── V1 ──
    doc.sub("      # 5.18.103 (docs/72 §G.10), carried; 5.18.106 (root docs/75 §L.7, DL4): the deploy's own automatic rollback — the\n"
            "      # public address must never redirect, and putting $BASELINE_VERSION back is what ends it — AS-RB1 first\n"
            "      # (followup_auto_send '0', the one write, when it reads on and §L.7's five conditions hold; never a reason to\n"
            "      # stop), then do_rollback, no switch tool, every other switch kept; then QC-RB2 counted after it on a consistent\n"
            "      # copy of the book, read-only (qcrb_count; counts in the log, ids on the sheet), a record only. It always\n"
            "      # proceeds, whatever step is on. do_rollback's own answer decides the words: verified, RB106 reads the put-back\n"
            "      # (against the deploy's LK-B1) and no put-back command is printed; not verified — the checkout, deploy-hybrid.sh,\n"
            "      # or a record that did not turn — $EXPECTED_VERSION may still serve, and the stop says the rollback did not\n"
            "      # complete and prints the by-hand put-back of the code, alone on its own line. Where AS-RB1 did not write while\n"
            "      # followup_auto_send reads on, either stop carries the owner's own command in its own paragraph (as_para), before\n"
            "      # the put-back's.\n",
            "      # 5.18.103 (docs/72 §G.10), carried; 5.18.107 (root docs/76 §T.7): the deploy's own automatic rollback — the\n"
            "      # public address must never redirect, and putting $BASELINE_VERSION back is what ends it — writes nothing: its\n"
            "      # headline says that ai_burst_merge_enabled read off from stage A until the copy (NS-G1, LK-B1), so this run wrote\n"
            "      # no mark, and adds stage A's leftover count (BD_A) when it was above 0 or not read; no drain runs before\n"
            "      # do_rollback (K3). Then do_rollback, no switch tool, every switch kept; then QC-RB2 counted after it on a\n"
            "      # consistent copy of the book, read-only (qcrb_count; counts in the log, ids on the sheet), a record only. It\n"
            "      # always proceeds, whatever step is on. do_rollback's own answer decides the words: verified, RB107 reads the\n"
            "      # put-back (against the deploy's LK-B1) and the stop is worded from its drain reading — the conversation ids in\n"
            "      # capitals, for staff, since 5.18.106 cannot drain; a control of 0 INDETERMINATE; never \"nothing to drain\"; not\n"
            "      # verified — the checkout, deploy-hybrid.sh, or a record that did not turn — $EXPECTED_VERSION may still serve,\n"
            "      # and the stop says the rollback did not complete and prints the put-back paragraph and the by-hand put-back of\n"
            "      # the code, alone on its own line.\n", why="V1's comment")
    doc.sub("echo \"  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept; followup_auto_send set off first, AS-RB1)\"; as_rb1 v1; if do_rollback;",
            "echo \"  ROLLING BACK — the public address must never redirect. Every switch kept; ai_burst_merge_enabled read off since stage A (NS-G1, LK-B1): this run wrote no mark$( case \"${BD_A:-}\" in \"bd=read a=0 apart=\"*\" b=0 \"*) ;; *) echo \"; stage A counted leftover marks — $(rb_bd_say \"${BD_A:-bd=unread(no stage-A reading)}\") — the drain after the rollback lists what still waits\" ;; esac )\"; if do_rollback;",
            why="V1's headline, no write")
    doc.sub("        if [ \"$QCV1_RB\" = \"ok\" ]; then rb106   # RB106 — the put-back read, as RB reads it after a --rollback (root docs/75 §L.7)\n",
            "        if [ \"$QCV1_RB\" = \"ok\" ]; then rb107   # RB107 — the put-back read, as RB reads it after a --rollback (root docs/76 §T.7)\n",
            why="V1: rb107")
    a = "          stop \"rolled back (serves $(live_commit)); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (${AS_RB1_SAY:-?}), "
    b = "send the log file$(as_para v1)\"; fi\n"
    i = doc.t.index(a); j = doc.t.index(b, i)
    if doc.t.count(a) != 1 or "\n" in doc.t[i:j]:
        raise SystemExit("partR: V1's verified stop is not one line, once")
    mid = doc.t[i + len(a):j]
    if "5.18.105's masked lines still masked under $BASELINE_VERSION (LM-RB1); $QCV1_SAY; " not in mid:
        raise SystemExit("partR: V1's verified stop's middle is not as expected")
    doc.between(a, b, "          stop \"rolled back (serves $(live_commit)); RB107 above reads the put-back — every switch of the sales follow-up kept as it was, nothing written, 5.18.105's masked lines still masked under $BASELINE_VERSION (LM-RB1); $QCV1_SAY; $(rb_bd_staff \"${RB_DRAIN:-bd=unread(rb107 made no reading)}\"); send the log file\"; fi\n",
                keep_ends=False, why="V1's verified stop, worded from rb107's drain")
    doc.sub("        stop \"the automatic rollback did not complete — it did not verify (live=$(live_commit)): ",
            "        pb_ready; stop \"the automatic rollback did not complete — it did not verify (live=$(live_commit)): ",
            why="V1 incomplete: pb_ready")
    doc.sub(" $QCV1_SAY. $PUTBACK_HAZARD$(as_para rb) Put $BASELINE_VERSION back by hand, with its own command — then send this log file and that command's log:$PUTBACK_SHOW\"; fi",
            " $QCV1_SAY. $PUTBACK_HAZARD$(pb_para v1) Put $BASELINE_VERSION back by hand, with its own command — then send this log file and that command's log:$PUTBACK_SHOW\"; fi",
            why="V1 incomplete: the paragraph")

    # ── rollback mode's stage-A note: what stays, what comes back ──
    doc.line("  note \"after the rollback the code is 5.18.105's again where 5.18.106 changed it",
             "  note \"after the rollback the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it, and 5.18.105's masked lines stay masked under it (LM-RB1). The rollback writes nothing and keeps every switch of the sales follow-up as it is — no switch tool runs, the steps that are on keep running (LK-RB0 below, LK-RB1 after). What stays: the assistant-off list and its rows, which no $BASELINE_VERSION file reads; 092 and the nine classes on disk; the K, Q and W keys — inert while off, and one left on blocks every redeploy (NS-G1) until it is repaired (root docs/76 §T.10); Part X's and Part K's closes (nothing reopens one); v10 of the settings text, if saved. What comes back is $BASELINE_VERSION: AI replies to listed numbers; \\\"Nothing is added on top.\\\" on Uganda quotations; rule 8 out of the absolute rules (the settings text's own STAY ON DISHNET TOPICS paragraph still holds it); no office line; the AI Knowledge Base screen without its menu link; no two-prices warning on the uCRM catalogue screen; F-1's, F-7's, F-8's and F-11's defects. The uCRM catalogue's settings and switch stay as an admin left them. A web-direct application is handled by $BASELINE_VERSION exactly as $EXPECTED_VERSION handles it, so the rollback adds none to quote in uCRM by hand (QC-RB2 counts them, for the record). Quotes already made in uCRM are not changed. The quotation summary's sign-off, the card's owner lists and every number, the quoting organization, the Cashbook, cash in hand and ECO Bank stay exactly as they are. A later deploy of $EXPECTED_VERSION goes with --redeploy. 5.18.95's security fixes stay; 5.18.94's documents too; master keeps 5.18.92's lock, main.php 5.18.93's change and the retry job 5.18.91's gate\"",
             why="rollback mode: what stays, what comes back (§T.7)")

    # ── the RB section: what is gone, and the files ──
    n_c, n_a = len(changed), len(added)
    doc.sub("# 5.18.106's change is gone (below: 7835906's files by sha256 where 5.18.106 changed them (20) and the 455 it keeps\n"
            "# by theirs; the 3 files it adds left on disk, named by nothing 7835906 tracks, QC-RB1; every ladder key as before,\n"
            "# RB106, and followup_auto_send off, AS-RB2;\n",
            f"# 5.18.107's change is gone (below: bf29cbd's files by sha256 where 5.18.107 changed them ({n_c}) and the {ctx['n_kept']} it keeps\n"
            f"# by theirs; the {n_a} files it adds left on disk — nine classes nothing in bf29cbd requires, 092 and its tests — named by\n"
            "# nothing bf29cbd tracks, QC-RB1; every ladder key as before, RB107, and nothing written; RB-X's counts and the pin's\n"
            "# drain once more, notes;\n", why="the RB section's comment")
    doc.sub("# 5.18.106's files are 5.18.105's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
            "# (PREV_CHANGED_SHA256S, Q-G1's set read again: 20) — and the 3 files it added (its tests) stay on disk\n"
            "# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 7835906 has\n"
            "# none of them; QC-RB1 (below) shows that no file 7835906 tracks names them. The files 5.18.106 adds, as they are on\n"
            "# disk now —\n",
            "# 5.18.107's files are bf29cbd's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
            f"# (PREV_CHANGED_SHA256S, Q-G1's set read again: {n_c}) — and the {n_a} files it added stay on disk (deploy-hybrid.sh\n"
            "# never deletes, nor can the by-hand put-back): nine classes nothing in bf29cbd requires, 092 and its tests. Git's loop\n"
            "# below passes over them, since bf29cbd has none of them; QC-RB1 (below) shows that no file bf29cbd tracks names them.\n"
            "# The files 5.18.107 adds, as they are on disk now —\n", why="the RB files' comment")
    doc.sub("$ADDED_LEFT of the $N_ADDED files it adds are on disk (deploy-hybrid.sh never deletes), named by nothing $BASELINE_COMMIT tracks (QC-RB1, below)\"",
            f"$ADDED_LEFT of the $N_ADDED files it adds are on disk (deploy-hybrid.sh never deletes) — nine classes nothing in $BASELINE_COMMIT requires ({', '.join(libs)}), $MIG107_LAST and its tests — named by nothing $BASELINE_COMMIT tracks (QC-RB1, below)\"",
            why="the RB files' line names what stays")
    doc.sub("which changes no setting but followup_auto_send (V3j; AS-RB1)\" ;;", "which changes no setting (V3j; root docs/76 §T.7)\" ;;", why="RB ECO Bank (copy)")
    doc.sub("# rollback restores code and writes no table — 5.18.106's rollback writes one settings key, followup_auto_send\n# (AS-RB1) — so 088 stays\n",
            "# rollback restores code and writes no table — and since 5.18.107 no setting either (root docs/76 §T.7) — so 088 stays\n",
            why="RB 088's comment")
    doc.sub("— the rollback changes no setting but followup_auto_send (AS-RB1)", "— the rollback changes no setting (root docs/76 §T.7)", why="RB ECO Bank (switch)")
    doc.sub("# RB106 — 5.18.106 (root docs/75 §L.7): master as 7835906 has it, the ladder's keys and the tool's show as before the\n"
            "# rollback (LK-RB1), no table's rows fell, the open quote follow-ups and running offers counted, 5.18.105's masked\n"
            "# lines still in place (LM-RB1), followup_auto_send off through 7835906's code (AS-RB2). Read-only. When this run\n"
            "# rolled nothing back (RB_NOTHING), LK-RB1 is a note — not judged, nothing to compare — and so is AS-RB2: nothing\n"
            "# was written.\nrb106\n",
            "# RB107 — 5.18.107 (root docs/76 §T.7): master as bf29cbd has it, the ladder's keys and the tool's show as before the\n"
            "# rollback (LK-RB1), no table's rows fell, RB-X's counts, 5.18.105's masked lines still in place (LM-RB1), the pin's\n"
            "# drain once more (the conversation ids for staff). Read-only. When this run rolled nothing back (RB_NOTHING), LK-RB1\n"
            "# is a note — not judged, nothing to compare. Nothing was written.\nrb107\n", why="RB107's call")
    doc.line("note \"RB the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it — the four follow-up jobs and lib/FollowUpService.php — and $( case \"${AS_RB1:-}\" in",
             "note \"RB the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it, and nothing else moved: the rollback ran no switch tool, no set_config and no off-hook, applied or removed no migration, and wrote no key (root docs/76 §T.7) — every step of the sales follow-up keeps running as its switch says, and 5.18.105's masked log lines stay masked (LM-RB1). Every close $EXPECTED_VERSION made stays (nothing reopens one). $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds are on disk (deploy-hybrid.sh never deletes; counted above) — the nine classes and $MIG107_LAST among them — named by nothing $BASELINE_COMMIT tracks (QC-RB1); the assistant-off list stays, and no $BASELINE_VERSION file reads it (RB-X above). ECO Bank, the uCRM catalogue's settings, the channel registry and every number's owner stay as they were (RB above). A14 of any later deploy needs the quarantine's --check begun after this rollback's copy (RB_COPY_AT), and a later deploy of $EXPECTED_VERSION goes with --redeploy\"",
             why="RB's last note")

    # ── the summary: what it means, the rollback paragraph, the put-back paragraph; as_after_verdict and as_back_on go ──
    line = [l for l in doc.t.split("\n") if l.startswith("            echo \"  what it means     the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it — the four follow-up jobs and lib/FollowUpService.php (RB); followup_auto_send")]
    if len(line) != 1:
        raise SystemExit("partR: the summary's rollback 'what it means' line is not there once")
    a = "(RB); followup_auto_send ${AS_RB1_SAY:-not written by this run}"
    b = "every close $EXPECTED_VERSION made stays (nothing reopens one), and so do the drafts it set expired."
    l0 = line[0]
    if l0.count(a) != 1 or l0.count(b) != 1:
        raise SystemExit("partR: the 'what it means' line's anchors are not each once")
    l1 = (l0[:l0.index(a)] + "(RB); the rollback wrote nothing, and every switch of the sales follow-up is as it was (LK-RB1): the steps that were on keep running; 5.18.105's masked lines stay masked (LM-RB1); every close $EXPECTED_VERSION made stays (nothing reopens one); the assistant-off list and the nine classes stay, used by no $BASELINE_VERSION file, 092 stays, passed over by the migration runner once applied, and listed numbers get AI replies again (RB-X above); $(rb_bd_staff \"${RB_DRAIN:-bd=unread(rb107 made no reading)}\")." + l0[l0.index(b) + len(b):])
    l1 = l1.replace("echo \"  what it means     the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it — the four follow-up jobs and lib/FollowUpService.php (RB);",
                    "echo \"  what it means     the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it (RB);", 1)
    doc.sub(l0, l1, why="the summary's 'what it means'")
    doc.sub("as_after_verdict   # 5.18.106 (root docs/75 §L.7, DL6): after a rollback whose AS-RB1 did not set followup_auto_send off, the owner's own command, alone\n",
            "", why="as_after_verdict retires")
    doc.sub("  as_back_on   # 5.18.106 (root docs/75 §L.8): followup_auto_send off since the latest rollback wrote it — the owner's own command to turn it back on, or nothing\n",
            "", why="as_back_on retires (§T.9)")
    doc.sub("# of F3's, loses nothing by waiting for that run's PASSED. Then the rollback, last, in its own block (root docs/75\n"
            "# §L.7): followup_auto_send '0' first when it reads on and the five conditions hold (AS-RB1), then 5.18.105 put back\n"
            "# by the documented deploy, every other switch kept — no switch tool, no off-hook — then RB106; ECO Bank and its switch\n"
            "# stay as they are. It needs no explicit choice: going back to 5.18.105 has no refusal of its own, and the ladder never\n"
            "# blocks it. The by-hand path is the code's put-back alone (PUTBACK_CMD); it counts nothing and writes no setting, and\n"
            "# says so.\n",
            "# of F3's, loses nothing by waiting for that run's PASSED. Then the rollback, last, in its own block (root docs/76\n"
            "# §T.7, §T.9): §K's two commands first, each alone — the switch-off and the drain reading — then the rollback command:\n"
            "# bf29cbd put back by the documented deploy, nothing written, every switch kept — no switch tool, no off-hook — then\n"
            "# RB107; ECO Bank and its switch stay as they are. It needs no explicit choice: going back to bf29cbd has no refusal of\n"
            "# its own, and neither the ladder nor a drain reading ever blocks it. The by-hand path is the code's put-back alone\n"
            "# (PUTBACK_CMD), after the put-back paragraph (pb_para, its readings made just before by pb_ready).\n",
            why="the summary's rollback comment")
    a = "  echo \"  Only if it is ever needed — the rollback, never pasted together with any other command (root docs/44 §16.9).\"\n"
    b = "  as_show \"$(as_para sum)\"   # 5.18.106 (root docs/75 §L.7): followup_auto_send before a put-back by hand — the one reader, run now; the owner's own command in its own paragraph\n"
    doc.between(a, b,
        "  pb_ready   # 5.18.107 (root docs/76 §T.7): the put-back paragraph's readings, in this shell\n"
        "  echo \"  Only if it is ever needed — the rollback, never pasted together with any other command (root docs/44 §16.9).\"\n"
        "  echo \"  First, root docs/76 §K's two commands, each alone, each logging itself in /root/dnb-sales: the switch-off, then the\"\n"
        "  echo \"  drain reading — again, until (a) and (b) both read 0 twice, the second at least 2 minutes after the switch-off\"\n"
        "  echo \"  (a reading whose control is 0 does not count; if the rollback cannot wait, roll back anyway and hand the\"\n"
        "  echo \"  conversation ids the reading lists to staff):\"\n"
        "  echo \"      cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0\"\n"
        "  echo\n"
        "  echo \"      cd $REPO && bash $QF_WRAPPER --check burst-drain\"\n"
        "  echo\n"
        "  echo \"  Then $BASELINE_VERSION ($BASELINE_COMMIT) back — it asks you to type ROLLBACK before it changes anything. Before you\"\n"
        "  echo \"  type it, it reads ai_burst_merge_enabled and the drain again, K's and Q's switches and the settings text (rb_pre107),\"\n"
        "  echo \"  and says in capitals what to run first where root docs/76 §K's rule does not hold; it is never refused for it. It\"\n"
        "  echo \"  writes nothing and keeps every switch of the sales follow-up as it is — no switch tool, no off-hook: every step that\"\n"
        "  echo \"  is on keeps running. Then $BASELINE_VERSION is put back and checked (RB107: LK-RB1, LM-RB1 — 5.18.105's masked lines\"\n"
        "  echo \"  still in place — RB-X's counts, and the pin's drain once more, its conversation ids for staff). Every close\"\n"
        "  echo \"  $EXPECTED_VERSION made stays (nothing reopens one). The assistant-off list, 092 and the nine classes stay, read by\"\n"
        "  echo \"  nothing; listed numbers get AI replies again. The uCRM catalogue's settings stay as they are. Quotes already made\"\n"
        "  echo \"  in uCRM are not changed, and the $N_ADDED files $EXPECTED_VERSION adds stay on disk, named by nothing\"\n"
        "  echo \"  $BASELINE_COMMIT tracks. The summary's sign-off, the card's owner lists, every number and its owner, the quoting\"\n"
        "  echo \"  organization, the Cashbook, cash in hand, ECO Bank, its switch, 088's tables and every statement kept stay exactly as\"\n"
        "  echo \"  they are; 5.18.95's security fixes and 5.18.94's documents too. Then run the quarantine's own --check (A14 of any\"\n"
        "  echo \"  later deploy needs it).\"\n"
        "  echo \"      $ROLLBACK_CMD\"\n"
        "  echo\n"
        "  echo \"  Only if that script cannot run — the same put-back of the code by hand, its own command, alone (never with the\"\n"
        "  echo \"  one above; it counts nothing, runs no switch tool and writes no setting):\"\n"
        "  printf '%s\\n' \"$(pb_para sum)\"\n"
        "  echo \"      $PUTBACK_CMD\"\n",
        keep_ends=False, why="the summary's rollback and put-back paragraphs (§T.7, §T.9)")

    # ── the rbn mode: RB-X's counts ──
    doc.sub("    case 'rbn':    // rbn PDD — RB106's counts after the put-back (root docs/74 §H.5.4), read-only on the live book: the open quote\n"
            "                   // follow-ups and the running offers — 7835906's own, which carry on — and wa_voice_calls' rows (a note)\n"
            "        $p = $ro($a[0] . '/plugin.sqlite3');\n"
            "        echo 'open_quote=', $col($p, 'followups', 'kind') ? (int)$one($p, \"SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL\") : 0,\n"
            "             ' running_offers=', $has($p, 'wa_offers') ? (int)$one($p, \"SELECT COUNT(*) FROM wa_offers WHERE state = 'running'\") : 0,\n"
            "             ' voice_calls=', $has($p, 'wa_voice_calls') ? (int)$one($p, 'SELECT COUNT(*) FROM wa_voice_calls') : 0;\n"
            "        break;\n",
            "    case 'rbn':    // rbn PDD — RB-X's counts (root docs/76 §T.7 item 7), read-only on the live book, before and after a rollback:\n"
            "                   // wa_no_ai's active rows (nai=absent without the table) and the pending wa.no_ai_alert events\n"
            "        $p = $ro($a[0] . '/plugin.sqlite3');\n"
            "        echo 'nai=', $has($p, 'wa_no_ai') ? (int)$one($p, 'SELECT COUNT(*) FROM wa_no_ai WHERE active = 1') : 'absent',\n"
            "             ' nalert=', $has($p, 'events') ? (int)$one($p, \"SELECT COUNT(*) FROM events WHERE event_type = 'wa.no_ai_alert' AND status = 'pending'\") : 0;\n"
            "        break;\n", why="rbn: RB-X's counts")

    # ── lk_ao keeps its reading for the put-back paragraph ──
    doc.sub("  LK_AO_ON=\"$(qf_kv \"$o\" on)\"   # 5.18.106 (review L8-2): the steps on now, for the summary (LK_AO_ON above)\n",
            "  LK_AO_ON=\"$(qf_kv \"$o\" on)\"   # 5.18.106 (review L8-2): the steps on now, for the summary (LK_AO_ON above)\n"
            "  LK_AO_LINE=\"$o\"   # 5.18.107 (root docs/76 §T.7): the reading itself, for the put-back paragraph's K and Q (pb_para)\n",
            why="lk_ao keeps its line")
    doc.sub("LK_AO_ON=\"\"\n", "LK_AO_ON=\"\"; LK_AO_LINE=\"\"\n", why="LK_AO_LINE declared")

    # ── the other texts that named AS-RB1, RB106 or rb_pre106 ──
    doc.sub("its one write is a rollback's followup_auto_send '0' (AS-RB1, root docs/75 §L.7).",
            "it writes no setting, nor does its rollback (root docs/76 §T.7).", why="--clear-switches")
    doc.sub("# entry (qf_stage, qf_after, qf_on, rb_pre106, rb106 and LK's) before its readings on copies.",
            "# entry (qf_stage, qf_after, qf_on, rb_pre107, rb107, pb_ready and LK's) before its readings on copies.", why="cih_main's comment")
    doc.sub("# the rows (LK-CA2, LK-RB1). Nothing here writes the live book: this script never runs the switch tool (root docs/74 §H.4.3), and its one write — a\n"
            "# rollback's followup_auto_send '0', AS-RB1 — is not QF's (root docs/75 §L.7),\n",
            "# the rows (LK-CA2, LK-RB1). Nothing here writes the live book: this script never runs the switch tool (root docs/74 §H.4.3)\n"
            "# and writes nothing (root docs/76 §T.7),\n", why="QF's comment")
    doc.sub("# on run and claim). LK-CA2 across the copy; LK-RB1's rows across a rollback (RB106).",
            "# on run and claim). LK-CA2 across the copy; LK-RB1's rows across a rollback (RB107).", why="lkrows' comment")
    doc.sub("# lm_rb1 — LM-RB1, after the put-back (RB106):", "# lm_rb1 — LM-RB1, after the put-back (RB107):", why="lm_rb1's comment")
    doc.sub("# ── 5.18.95: the database, before and after (R7, RB) — 5.18.106 adds no migration; a rollback writes one key (AS-RB1) ─",
            "# ── 5.18.95: the database, before and after (R7, RB) — 5.18.107 adds 092; a rollback writes nothing (root docs/76 §T.7) ─",
            why="R7's header")
    doc.sub("and this run writes nothing in it$( [ \"${AS_RB1:-}\" = \"written\" ] && echo \" but followup_auto_send's '0' in the settings row, before the put-back (AS-RB1)\")$( [ \"$1\" = \"RB\" ] && echo \": the rollback runs no switch tool and no off-hook, writes no key but followup_auto_send (AS-RB1), and applies or removes no migration (root docs/75 §L.7)\")\"",
            "and this run writes nothing in it$( [ \"$1\" = \"RB\" ] && echo \": the rollback runs no switch tool, no set_config and no off-hook, writes no key, and applies or removes no migration (root docs/76 §T.7)\")\"",
            why="R7's ok line: the rollback writes no key")
    doc.sub("# docs/75 §L.2; a rollback writes one settings key, AS-RB1, and R7 is read right after it); 5.18.104 added 089–091",
            "# docs/75 §L.2; since 5.18.107 a rollback writes nothing, root docs/76 §T.7); 5.18.104 added 089–091", why="R7's comment")

    doc.sub("    --clear-switches)   # refused since 5.18.105; 5.18.106: its one write is a rollback's followup_auto_send '0' (root docs/75 §L.7)",
            "    --clear-switches)   # refused since 5.18.105; 5.18.107: this script writes no setting, nor does its rollback (root docs/76 §T.7)",
            why="--clear-switches' comment")

    # ── results: below the header (partH rewrites the header) ──
    lines = doc.t.split("\n")
    head_end = next(k for k, l in enumerate(lines) if k > 0 and l and not l.startswith("#"))
    body = "\n".join(lines[head_end:])
    gone = [f + s for f in sorted(RB_FUNCS - {"rb106", "rb_pre106"}) + ["as_owner_cmd"] for s in ("() {", " ", "\n")]
    gone += ["$(" + f for f in sorted(RB_FUNCS) + ["as_owner_cmd"]]
    gone += ["AS_SUMS", "AS_MIGS", "AS_PHP", "AS_RB0", "AS_RB1", "AS_RB2", "OWNER_AUTOSEND_CMD", "rb_pre106", "rb106", "as_para"]
    for s in gone:
        if s in body:
            raise SystemExit(f"partR: {s!r} is left below the header")
    if body.count("followup_auto_send '0'") != 1 or "5.18.106's AS-RB1 (followup_auto_send '0'" not in body:
        raise SystemExit("partR: followup_auto_send '0' is named below the header other than in the RB block's history line")
    for f in ("rb_pre107", "rb107", "rb_switch", "rb_drain", "rb_bd_say", "rb_bd_zero", "rb_bd_staff", "rb_kq", "owner_set_cmd",
              "rb_x_say", "pb_tool", "pb_word", "pb_ready", "pb_para"):
        doc.must(f + "() {", 1, why=f"{f} defined once")
    doc.must("$(pb_para pre)", 4); doc.must("$(pb_para deploy)", 1); doc.must("$(pb_para rb)", 1); doc.must("$(pb_para v1)", 1)
    doc.must("$(pb_para sum)", 1)
    doc.must("\n  pb_ready   # 5.18.107 (root docs/76 §T.7): the put-back paragraph's readings, in this shell\n", 1)
    doc.must("\n    pb_ready   # 5.18.107 (root docs/76 §T.7): the put-back paragraph's readings, in this shell\n", 1)
    doc.must("else pb_ready; stop \"the rollback did not verify", 1); doc.must("        pb_ready; stop \"the automatic rollback did not complete", 1)
    calls = [l for l in doc.t.split("\n") if re.search(r"(^\s*|[;&|{] *|then |else )pb_ready(;| |$)", l) and not l.lstrip().startswith("#")]
    if len(calls) != 4:
        raise SystemExit(f"partR: pb_ready is called {len(calls)} times, not 4 (two stops, the deploy that did not verify, the summary)")
    doc.must("    rb_pre107   #", 1); doc.must("rb107\n", 1); doc.must("then rb107   #", 1)
    doc.must("RB_DRAIN=\"$(rb_drain ids)\"", 1)
    left = sorted(set(re.findall(r"AS-RB[0-9]|RB106", doc.t)))
    stray = [f"{k + 1}: {l[:80]}" for k, l in enumerate(lines) if k >= head_end and re.search(r"AS-RB[0-9]|RB106", l)
             and not l.startswith("echo \"  names             ") and "5.18.106's AS-RB1 (" not in l and "(AS-RB1 retired)" not in l]
    if stray:
        raise SystemExit(f"partR: AS-RB or RB106 left outside the header and the summary's names line: {stray[:6]}")
    ctx.update(rb_left=left)
