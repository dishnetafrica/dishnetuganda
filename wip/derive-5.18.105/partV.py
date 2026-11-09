# Part V — 5.18.104's Part V checks (VN, VA) made live-aware (root docs/74 §H.4.4):
#   VN-G1 reads LK-G1 (Part V's keys set are a note, never a refusal); VN-G2 and VN-G6 keep only their live form (090
#   applied as the pin makes it; the events are history); VN-G7 keeps "none pending"; VN-G4 compares the pin's whole
#   fromMe branch with 994d660's, byte for byte, with W1's count checked on both; VN-CA1 must equal the same probe through
#   the pin's code on stage A's copy (one new reading in stage A, VN_CA1_A); VN-CA2's u0 must equal ai_media_enabled on the
#   copy; VN-CA4's keys half is LK-CA1's, its rows and late counts a note.
# The derivation asserts from Git that 5.18.105 touches none of what VN-G4 reads: no changed line of a runtime file names
# a gate asker, 8c's lines or W1–W3, and the fromMe branch is the same text at both commits.
import re
from d105lib import git


def fromme(text):
    out, on = [], False
    for ln in text.split("\n"):
        if ln == "    if ($fromMe) {":
            on = True
        if on:
            out.append(ln)
            if ln == "    }":
                break
    return "\n".join(out)


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── from Git: VN-G4's subjects are the same at both commits ──
    fb, fp = fromme(show(base, "evo_webhook.php")), fromme(show(pin, "evo_webhook.php"))
    if not fb or fb != fp:
        raise SystemExit("PART V: evo_webhook.php's fromMe branch differs between 994d660 and the pin")
    askers = re.compile(r"(MediaPolicy::(enabled|voiceEnabled|voiceHandoverEnabled|voiceMode|voiceReplyEnabled)|VoiceAck::enabled)[(]")
    gated = re.compile(r"(MediaPolicy|VoiceAck|InboundMedia|StaffJobsGate|WaSeenMarks|WaSendBudget)::|\$bus->emit\(|require_once")
    for f in ctx["runtime_changed"]:
        d = git(repo, "diff", "-U0", "--no-renames", base, pin, "--", f"{plugin}/{f}").decode()
        for ln in d.split("\n"):
            if (ln.startswith("+") or ln.startswith("-")) and not ln.startswith("+++") and not ln.startswith("---"):
                if askers.search(ln):
                    raise SystemExit(f"PART V: a changed line of {f} asks a voice gate: {ln[:160]!r}")
                if f == "evo_webhook.php" and gated.search(ln):
                    raise SystemExit(f"PART V: a changed line of evo_webhook.php names 8c's or W1–W3's text: {ln[:160]!r}")
    m = re.search(r'VN_CALLS_N="(\d+)"; VN_8C_IN="(\d+)"; VN_W1_N="(\d+)"', doc.t)
    if not m:
        raise SystemExit("PART V: VN_CALLS_N / VN_8C_IN / VN_W1_N not found")

    # ── VN-G1 reads LK-G1 ──
    doc.lines_between("  # VN-G1 — a read of QF-G4's result (ruling R7): Part V's switch and record keys among those QF-G4 found set; its settings",
                      '  else ok "VN-G1 none of Part V\'s $(qf_kv "$o" vswitch) switch and record keys is set',
        "  # VN-G1 — a read of LK-G1 (root docs/74 §H.4.4): Part V's switch and record keys among those LK-G1 found set — kept as\n"
        "  # found, never a refusal; its settings\n"
        '  o="$(vn_php g1 "$sub/code" "$sub")"\n'
        '  case "$o" in vswitch=[1-9]*" vsettings="*) ;; *) cih_drop "$sub"; stop "NO-GO (VN-G1): Part V\'s keys could not be read through the pin\'s ladder on the copy (${o:-not read}). Nothing was changed. Send the log file" ;; esac\n'
        '  VN_SWITCH="$(qf_kv "$o" vswitchnames)"\n'
        '  vs=""; for k in $(printf \'%s\' "$VN_SWITCH" | tr \',\' \' \'); do case ",${LK_SET_NAMES:-}," in *",$k,"*) vs="$vs $k" ;; esac; done\n'
        '  if [ -n "$vs" ]; then note "VN-G1 Part V\'s keys set:$vs — LK-G1\'s reading: kept exactly as found by this run (root docs/74 §H.4.3); Part V clears nothing"\n'
        '  else note "VN-G1 none of Part V\'s $(qf_kv "$o" vswitch) switch and record keys is set — LK-G1\'s reading; kept as found; Part V clears nothing"; fi',
        why="VN-G1")
    # VN-G2: the live form only
    doc.sub('  case "$QF_G1" in\n'
            '    first)\n'
            '      case "$o" in "tables=0/3 objects=0/$VN_OBJ_N exact=0/$VN_OBJ_N extra=0 ledger=absent sc=absent control=yes "*)\n',
            '  case "$QF_G1" in\n'
            '    _first_retired_)\n'
            '      case "$o" in "tables=0/3 objects=0/$VN_OBJ_N exact=0/$VN_OBJ_N extra=0 ledger=absent sc=absent control=yes "*)\n',
            why="VN-G2 first (anchor)")
    doc.lines_between("    _first_retired_)", "    redeploy)", "    live)", why="VN-G2 first deleted")
    doc.sub('        ok "VN-G2 a redeploy: exactly 090\'s $VN_OBJ_N objects', '        ok "VN-G2 $BASELINE_VERSION live: exactly 090\'s $VN_OBJ_N objects', why="VN-G2 words")
    # VN-G6: the live form only
    i = doc.t.index('  if [ "$QF_G1" = "first" ]; then\n    [ "$(qf_kv "$o" media)|$(qf_kv "$o" ack)" = "0|0" ]')
    j = doc.t.index("\n  fi\n", i) + len("\n  fi\n")
    block = doc.t[i:j]
    if block.count('note "VN-G6 a redeploy: an earlier deployment\'s events are history, never a fault') != 1:
        raise SystemExit("PART V: VN-G6's block is not as expected")
    note_line = [ln for ln in block.split("\n") if ln.startswith('    note "VN-G6 a redeploy:')][0]
    new_note = note_line.replace('    note "VN-G6 a redeploy: an earlier deployment\'s events are history, never a fault',
                                 '  note "VN-G6 the voice events on this live $BASELINE_VERSION book are its own history, never a fault', 1) \
                        .replace("; after its rollback $BASELINE_VERSION's Uganda event_processor acknowledged them as unknown, and a done event goes 30 days after it was processed (Q17-V02). VN-CA4's baseline is taken at QF-G1's one moment, after DEPLOY is typed\"",
                                 "; a done event goes 30 days after it was processed (Q17-V02). VN-CA4's baseline is taken at the copy's start, after DEPLOY and LK-B1\"", 1)
    if new_note == note_line or "after DEPLOY and LK-B1" not in new_note:
        raise SystemExit("PART V: VN-G6's note could not be reworded")
    doc.sub(block, new_note + "\n", why="VN-G6 live")
    # VN-G7: none pending only
    doc.sub('     && { [ "$QF_G1" != "first" ] || printf \'%s\\n\' "$l" | grep -qx \'090_wa_voice.sql=OK\'; } \\\n', '', why="VN-G7 first")
    doc.sub('$( [ "$QF_G1" = "first" ] && echo "090\'s migration.log line OK and" || echo "on this redeploy 090 already applied (no log line of the trial\'s), and" )',
            "090 already applied on this live $BASELINE_VERSION book (none pending; no log line of the trial's), and", why="VN-G7 words")
    doc.sub("  # 090's log line is the trial's only where the trial applied 090 — a first deploy; on a redeploy MigrationRunner passes\n"
            "  # over a file the copy's ledger already holds and logs nothing, and QF-G2 takes the verdict from the row (DS1-fix1, R01)\n",
            "  # 5.18.105: 5.18.104 is live, so 090 is in the copy's ledger already — MigrationRunner passes over it and logs nothing,\n"
            "  # and QF-G2 takes the verdict from the row (root docs/74 §H.4.4)\n", why="VN-G7 comment")
    # the baseline: always taken now
    doc.sub('  qf_sheet "VN-G6 VN-CA4\'s redeploy baseline: the largest ids', '  qf_sheet "VN-G6 VN-CA4\'s baseline: the largest ids', why="VN baseline words")
    doc.sub('  note "VN-G6 VN-CA4\'s redeploy baseline, read-only from the live book in QF-G1\'s one call, just before the copy:',
            '  note "VN-G6 VN-CA4\'s baseline, read-only from the live book in QF-G1\'s one call, just before the copy:', why="VN baseline note")
    doc.sub("*) stop \"NO-GO (VN-G6): VN-CA4's redeploy baseline could not be read", "*) stop \"NO-GO (VN-G6): VN-CA4's baseline could not be read", why="VN baseline stop")

    # ── VN-G4: the fromMe branch, the pin's against 994d660's, whole ──
    doc.sub('  b="$(vn_base_text evo_webhook.php | vn_fromme)"; p="$(vn_pin evo_webhook.php | vn_fromme)"\n'
            '  w="$(printf \'%s\\n\' "$p" | vn_fromme_w1 count)"\n'
            '  if [ -z "$b" ] || [ "$w" != "w1=$VN_W1_N out_marks=1" ] || [ "$(printf \'%s\\n\' "$p" | vn_fromme_w1 text | sha256sum)" != "$(printf \'%s\\n\' "$b" | sha256sum)" ]; then\n'
            '    VN_G4_BAD="$VN_G4_BAD fromMe:not-W1-alone($w)"\n'
            '  fi\n',
            '  # 5.18.105 (root docs/74 §H.4.4): the pin\'s whole fromMe branch is $BASELINE_VERSION\'s byte for byte, W1 in both\n'
            '  b="$(vn_base_text evo_webhook.php | vn_fromme)"; p="$(vn_pin evo_webhook.php | vn_fromme)"\n'
            '  w="$(printf \'%s\\n\' "$p" | vn_fromme_w1 count)|$(printf \'%s\\n\' "$b" | vn_fromme_w1 count)"\n'
            '  if [ -z "$b" ] || [ "$w" != "w1=$VN_W1_N out_marks=1|w1=$VN_W1_N out_marks=1" ] || [ "$(printf \'%s\\n\' "$p" | sha256sum)" != "$(printf \'%s\\n\' "$b" | sha256sum)" ]; then\n'
            '    VN_G4_BAD="$VN_G4_BAD fromMe:not-$BASELINE_VERSION\'s($w)"\n'
            '  fi\n', why="VN-G4 fromMe")
    doc.sub("the fromMe branch is $BASELINE_VERSION's but for Part F's W1 ($VN_W1_N lines, one out-mark);",
            "the fromMe branch is $BASELINE_VERSION's byte for byte, W1 in both ($VN_W1_N lines, one out-mark);", why="VN-G4 ok")

    # ── VN-CA1: stage A's reading through the pin's code, and equality after the copy ──
    doc.sub('  cih_drop "$sub"\n}\n\n# vn_g7 — VN-G7,',
            '  # VN-CA1\'s stage-A reading (root docs/74 §H.4.4): the same probe through the PIN\'s code on this copy — after the copy\n'
            '  # the installed code\'s must read the same\n'
            '  VN_CA1_A="$(vn_php ca1 "$sub/code" "$sub")"\n'
            '  case "$VN_CA1_A" in channels=[1-9]*) note "VN-CA1 stage A, through the pin\'s code on a copy: voiceMode $(qf_kv "$VN_CA1_A" ug) over $(qf_kv "$VN_CA1_A" channels) channels, the acknowledgement $(qf_kv "$VN_CA1_A" ug_ack), the media switch $(qf_kv "$VN_CA1_A" ug_media), the transcriber factory $(qf_kv "$VN_CA1_A" factory) (after the copy the installed code must read the same)" ;;\n'
            '    *) note "VN-CA1 stage A\'s reading through the pin\'s code could not be made (${VN_CA1_A:-not read}) — VN-CA1 will have nothing to compare with" ;; esac\n'
            '  cih_drop "$sub"\n}\n\n# vn_g7 — VN-G7,', why="VN-CA1 stage A")
    doc.sub('VN_BASE=""; VN_BASE_F=""; VN_SWITCH=""; ', 'VN_BASE=""; VN_BASE_F=""; VN_SWITCH=""; VN_CA1_A=""; ', why="VN vars")
    old_ca1 = doc.t[doc.t.index('        elif [ "$MODE" = "deploy" ]; then\n          [ "$(qf_kv "$o" ug)|$(qf_kv "$o" ugai)|'):]
    old_ca1 = old_ca1[:old_ca1.index("\n        else note \"VN-CA1 on Uganda, as the copy reads now") + 1]
    doc.sub(old_ca1,
            '        elif [ "$MODE" = "deploy" ]; then\n'
            '          if [ -z "$VN_CA1_A" ]; then bad "VN-CA1 stage A\'s reading through the pin\'s code is not there — the installed gates on Uganda ($(qf_kv "$o" ug) over $n channels) have nothing to be compared with"\n'
            '          elif [ "$o" = "$VN_CA1_A" ]; then ok "VN-CA1 on Uganda, as the copy reads after the copy (tenant uganda through the installed gate, the copy\'s own vault read): the installed gates read exactly as the pin\'s did on stage A\'s copy — voiceMode $(qf_kv "$o" ug) over $n channels ($(qf_kv "$o" ugai) with the assistant forced on), the acknowledgement $(qf_kv "$o" ug_ack), the media switch $(qf_kv "$o" ug_media), the transcriber factory $(qf_kv "$o" factory) with a sentinel key (provider $(qf_kv "$o" provider); its control built one): the steps that are on, kept as found"\n'
            '          else bad "VN-CA1 the installed gates on Uganda do not read as the pin\'s did on stage A\'s copy (now: $o; stage A: $VN_CA1_A) — the voice steps are not as the deploy found them"; fi\n',
            why="VN-CA1 equal to stage A")
    # ── VN-CA2: u0 as ai_media_enabled reads on the copy ──
    doc.sub('    u0="$(vn_cs)"\n',
            '    u0="$(vn_cs)"; u0k="$(qf_kv "$(qf_php flags "$sub/plugin.sqlite3" ai_media_enabled)" flags)"   # 5.18.105: what the copy\'s flag says, read before the scratch runs change it\n',
            why="VN-CA2 flag")
    doc.sub('    elif [ "$u1|$s1|$s0" = "listed|absent|absent" ] && { [ "$u0" = "off" ] || { [ "$MODE" = "after" ] && [ "$u0" = "listed" ]; }; }; then\n',
            '    elif [ "$u1|$s1|$s0" = "listed|absent|absent" ] && { [ "$u0k|$u0" = "ai_media_enabled:0|off" ] || [ "$u0k|$u0" = "ai_media_enabled:1|listed" ]; }; then\n',
            why="VN-CA2 rule")
    doc.sub('on Uganda ai_media is listed $( [ "$u0" = "off" ] && echo "OFF (switch) while ai_media_enabled is off" || echo "as a job (its switch is on: an --after-only run)"), and',
            'on Uganda ai_media is listed $( [ "$u0" = "off" ] && echo "OFF (switch), as ai_media_enabled is off on the copy" || echo "as a job, as ai_media_enabled is on on the copy (step 5)"), and',
            why="VN-CA2 ok")
    doc.sub('(Uganda as copied: $u0; flag on: $u1;', '(Uganda as copied: $u0, its flag $u0k; flag on: $u1;', why="VN-CA2 bad")
    doc.sub("  local inst=\"$1\" sub o o4 v n i k st u1 u0 s1 s0\n", "  local inst=\"$1\" sub o o4 v n i k st u1 u0 u0k s1 s0\n", why="vn_after locals")
    # ── VN-CA4: its keys half is LK-CA1's; the rows and late counts a note ──
    i = doc.t.index("  # VN-CA4 — no switch changed by the run, and nothing of Part V written by the dark build during the deploy (ruling R18)\n")
    j = doc.t.index("    cih_drop \"$sub\"\n  fi\n", i) + len("    cih_drop \"$sub\"\n  fi\n")
    doc.sub(doc.t[i:j],
            "  # VN-CA4 — 5.18.105 (root docs/74 §H.4.4): its keys half is LK-CA1's (the ladder's keys after the copy as LK-B1 read\n"
            "  # them); Part V's rows and its late counts against VN-G6's baseline are a note — the voice steps that are on write\n"
            "  if [ -n \"$sub\" ]; then\n"
            "    case \"$o4\" in\n"
            "      \"rows=\"*) note \"VN-CA4 Part V after the copy, against VN-G6's baseline at the copy's start (${QF_COPY_AT:-not taken} UTC) — a note, the steps that are on write: rows $(qf_kv \"$o4\" rows) (wa_media, wa_voice_calls, wa_voice_acks), events $(qf_kv \"$o4\" events) (ai.media, ai.voice_ack); added since the baseline — rows $(qf_kv \"$o4\" added), events $(qf_kv \"$o4\" ev_added); in a later second than the copy's start: voice_ack claims $(qf_kv \"$o4\" ack_claims_late), sent or uncertain decisions $(qf_kv \"$o4\" acks_late), OpenAI calls $(qf_kv \"$o4\" calls_late)\" ;;\n"
            "      *) note \"VN-CA4 Part V's rows could not be read on the copy (${o4:-not read})\" ;;\n"
            "    esac\n"
            "    cih_drop \"$sub\"\n"
            "  fi\n", why="VN-CA4")
    doc.mustnot("VN-CA4 a first deploy")
    doc.mustnot('[ "$QF_G1" = "first" ]; then\n    [ "$(qf_kv "$o" media)')
