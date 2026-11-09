# Part O — 5.18.104's Part O checks (OF) made live-aware (root docs/74 §H.4.4):
#   OF-G1 reads LK-G1 for Part O's keys (a note, kept as found) and says "the offers as 5.18.104 left them" (the live form
#   only; the first branch deleted); OF-G2 keeps "none pending"; OF-CA1 must equal stage A (one new reading on QF-G2's trial
#   copy through the pin's code, OF_CA1_A); OF-CA3's first branch is deleted — "no table fell" stays a FAIL, the late
#   claims, echoes and tests become a note.
from d105lib import git


def apply(doc, ctx):
    # ── OF-G1: Part O's keys, a read of LK-G1 ──
    doc.lines_between("  # OF-G1 — a read of QF-G4's result (ruling R7): Part O's switch and record keys among those QF-G4 found set",
                      '  else ok "OF-G1 none of Part O\'s $(qf_kv "$o" oswitch) switch and record keys',
        "  # OF-G1 — a read of LK-G1 (root docs/74 §H.4.4): Part O's switch and record keys among those LK-G1 found set — kept as\n"
        "  # found, never a refusal\n"
        '  o="$(of_php okeys "$P3B_PIN")"\n'
        '  case "$o" in oswitch=[1-9]*" osettings="*) ;; *) stop "NO-GO (OF-G1): Part O\'s keys could not be read through the pin\'s ladder (${o:-not read}). Nothing was changed. Send the log file" ;; esac\n'
        '  os=""; for k in $(qf_kv "$o" oswitchnames | tr \',\' \' \'); do case ",${LK_SET_NAMES:-}," in *",$k,"*) os="$os $k" ;; esac; done\n'
        '  if [ -n "$os" ]; then note "OF-G1 Part O\'s keys set:$os — LK-G1\'s reading: kept exactly as found by this run (root docs/74 §H.4.3); Part O clears nothing, and its $(qf_kv "$o" osettings) settings are printed by OF-N3"\n'
        '  else note "OF-G1 none of Part O\'s $(qf_kv "$o" oswitch) switch and record keys (offers_test_enabled, offers_live_enabled, offers_live_since and their skip and override keys) is set — LK-G1\'s reading; kept as found; its $(qf_kv "$o" osettings) settings are printed by OF-N3, never NO-GO (ruling R5)"; fi',
        why="OF-G1 keys")
    doc.mustnot("${QF_G4_NAMES:-}")
    doc.mustnot('[ -n "$QF_CLEAR" ]')
    # OF-G1: the live form only — the offers as 5.18.104 left them
    i = doc.t.index('  case "$QF_G1" in\n    first)\n      [ "$(qf_kv "$g1" of_any)" = "0" ]')
    j = doc.t.index('    *) stop "NO-GO (OF-G1): QF-G1 did not say what this book is', i)
    doc.sub(doc.t[i:j],
        '  case "$QF_G1" in\n'
        '    live)\n'
        '      qf_sheet "OF-G1 the offers not closed, as $BASELINE_VERSION left them, by state (offer ids; never paste)" "${OF_G1_IDS:-not read}"\n'
        '      note "OF-G1 the offers as $BASELINE_VERSION left them: its offer tables ($(qf_kv "$g1" of_tables)); offers by state $(qf_kv "$g1" offers) (every one not closed, by id, on the sheet); recipients by state $(qf_kv "$g1" recipients), tests $(qf_kv "$g1" tests), events $(qf_kv "$g1" events) — the steps that are on run them, and this run changes none" ;;\n',
        why="OF-G1 live")
    doc.mustnot("A REDEPLOY: ITS OFFER TABLES")
    # OF-G2: none pending only
    doc.sub('  if [ "$QF_G1" = "first" ]; then\n'
            '    case ",$ap," in *",091_wa_offers.sql:ok,"*) ;; *) bad="$bad 091:not-ok" ;; esac\n'
            '    case ",$lg," in *",091_wa_offers.sql=OK,"*) ;; *) bad="$bad 091:log-not-OK" ;; esac\n'
            '  else\n'
            '    case ",$ap," in *",091_wa_offers.sql:"*) bad="$bad 091:applied-on-a-redeploy" ;; esac\n'
            '  fi\n',
            '  case ",$ap," in *",091_wa_offers.sql:"*) bad="$bad 091:applied-on-a-live-$BASELINE_VERSION-book" ;; esac   # 5.18.105: none pending (root docs/74 §H.4.4)\n',
            why="OF-G2 none pending")
    doc.sub('$( [ "$QF_G1" = "first" ] && echo "091 applied ok, never partial, its migration.log line OK, and" || echo "on this redeploy 091 already applied (none pending, none with other bytes), and" )',
            "091 already applied on this live $BASELINE_VERSION book (none pending, none with other bytes), and", why="OF-G2 ok")
    # OF-CA1's stage-A reading, on QF-G2's trial copy through the pin's code
    doc.sub('  OF_N3="$(of_php n3 "$1/code" "$1")"\n}\n',
            '  OF_N3="$(of_php n3 "$1/code" "$1")"\n'
            '  OF_CA1_A="$(of_php ca1 "$1/code" "$1")"   # 5.18.105 (root docs/74 §H.4.4): OF-CA1\'s stage-A reading through the pin\'s code\n'
            '}\n', why="OF-CA1 stage A")
    doc.sub('  OF_N1="$(of_php n1 "$1/code" "$1" "${QF_G1:-}")"\n', '  OF_N1="$(of_php n1 "$1/code" "$1" "${QF_G1:-}")"   # QF_G1=live: no reason reads "not measured before 089"\n', why="OF-N1 arg")
    # the baseline: always taken now
    doc.sub("# of_base_prep / of_baseline — OF-CA3's redeploy baseline (Q18-O03), read-only from the live book in Part F's own baseline\n"
            "# call (qf_php base), after QF-G4 returned and the pause, at QF-G1's one moment, before the copy's start is stamped: each\n",
            "# of_base_prep / of_baseline — OF-CA3's baseline (Q18-O03), read-only from the live book in Part F's own baseline call\n"
            "# (qf_php base), after LK-B1 and the pause (5.18.105: always), before the copy's start is stamped: each\n", why="OF baseline comment")
    doc.sub("# (QF_COPY_AT, QF_COPY_UNIX) to the sheet. Stage A's copy is not this baseline: on the --clear-switches path it was taken\n"
            "# before the clear, and Part O's own hook then writes its paused_by_rollback events. Unreadable: stops before the checkout.\n",
            "# (QF_COPY_AT, QF_COPY_UNIX) to the sheet. Stage A's copy is not this baseline: the steps that are on write between the\n"
            "# two. Unreadable: stops before the checkout.\n", why="OF baseline comment 2")
    doc.sub("*) stop \"NO-GO (OF-G1): OF-CA3's redeploy baseline could not be read", "*) stop \"NO-GO (OF-G1): OF-CA3's baseline could not be read", why="OF baseline stop")
    doc.sub('  qf_sheet "OF-G1 OF-CA3\'s redeploy baseline: the largest ids', '  qf_sheet "OF-G1 OF-CA3\'s baseline: the largest ids', why="OF baseline sheet")
    doc.sub('  note "OF-G1 OF-CA3\'s redeploy baseline, read-only from the live book in QF-G1\'s one call, just before the copy and after QF-G4\'s clear and the pause:',
            '  note "OF-G1 OF-CA3\'s baseline, read-only from the live book in QF-G1\'s one call, just before the copy, after LK-B1 and the pause:', why="OF baseline note")
    # ── OF-CA1: equal to stage A ──
    i = doc.t.index('        elif [ "$MODE" = "deploy" ]; then\n          [ "$(qf_kv "$o" screen)|$(qf_kv "$o" live)|$(qf_kv "$o" tab)" = "0|0|card" ]')
    j = doc.t.index('        else note "OF-CA1 through the installed code on a copy, as it reads now (an --after-only run)', i)
    doc.sub(doc.t[i:j],
            '        elif [ "$MODE" = "deploy" ]; then\n'
            '          if [ "$(qf_kv "${OF_CA1_A:-}" gate)" != "1" ]; then bad "OF-CA1 stage A\'s reading through the pin\'s code is not there or did not read as Uganda (${OF_CA1_A:-not read}) — the installed screen(), live() and tab have nothing to be compared with"\n'
            '          elif [ "$(qf_kv "$o" screen)|$(qf_kv "$o" live)|$(qf_kv "$o" tab)" = "$(qf_kv "$OF_CA1_A" screen)|$(qf_kv "$OF_CA1_A" live)|$(qf_kv "$OF_CA1_A" tab)" ]; then ok "OF-CA1 through the installed code on a copy (tenant uganda, its own vault read): OfferSwitch::screen() $(qf_kv "$o" screen), live() $(qf_kv "$o" live), the tab as an admin with no store: $(qf_kv "$o" tab) — exactly as the pin\'s code read them on stage A\'s copy (root docs/74 §H.4.4); the controls held"\n'
            '          else bad "OF-CA1 the installed offers switch and tab do not read as the pin\'s did on stage A\'s copy (screen|live|tab now $(qf_kv "$o" screen)|$(qf_kv "$o" live)|$(qf_kv "$o" tab), at stage A $(qf_kv "$OF_CA1_A" screen)|$(qf_kv "$OF_CA1_A" live)|$(qf_kv "$OF_CA1_A" tab)) — the offers steps are not as the deploy found them"; fi\n',
            why="OF-CA1 equal to stage A")
    # ── OF-CA3: no table fell (FAIL); the late counts a note ──
    doc.sub('    o="$(of_php ca3 "$sub/plugin.sqlite3" "$( [ "${QF_G1:-}" = "redeploy" ] && printf \'%s\' "${OF_BASE:-}")" "${QF_COPY_UNIX:-0}")"\n',
            '    o="$(of_php ca3 "$sub/plugin.sqlite3" "${OF_BASE:-}" "${QF_COPY_UNIX:-0}")"\n', why="OF-CA3 baseline arg")
    i = doc.t.index('    elif [ "$MODE" = "deploy" ] && [ "$QF_G1" = "first" ]; then\n      [ "$(qf_kv "$o" rows)|$(qf_kv "$o" ledger_offer)|$(qf_kv "$o" seen_offer)" = "0,0,0,0|0|0" ]')
    j = doc.t.index('    else note "OF-CA3 judges the dark build in the deploy run; here, now:', i)
    doc.sub(doc.t[i:j],
            '    elif [ "$MODE" = "deploy" ]; then\n'
            '      # 5.18.105 (root docs/74 §H.4.4): no table fell — a FAIL (091\'s never-deleted triggers forbid it); the offers steps\n'
            '      # that are on claim, echo and test, so the late counts are a note\n'
            '      b="$(qf_kv "$OF_BASE" rows)"; n=""\n'
            '      for i in 1 2 3 4; do [ "$(printf \'%s\' "$(qf_kv "$o" rows)" | cut -d, -f$i)" -ge "$(printf \'%s\' "$b" | cut -d, -f$i)" ] 2>/dev/null || n="$n table$i"; done\n'
            '      if [ -z "$n" ]; then ok "OF-CA3 against OF-CA3\'s baseline taken just before the copy: no offer table\'s rows fell (rows then $b, now $(qf_kv "$o" rows))"\n'
            '      else bad "OF-CA3 an offer table\'s rows fell below the baseline taken just before the copy (${n# }; rows then $b, now $(qf_kv "$o" rows)) — 091\'s never-deleted triggers forbid it"; fi\n'
            '      note "OF-CA3 after the copy\'s start (on the sheet), a note — the offers steps that are on write: offer claims $(qf_kv "$o" late_claims), offer.send echoes $(qf_kv "$o" late_echoes), tests $(qf_kv "$o" late_tests) in a later second, offer_test:* ledger rows naming no test or a test so started $(qf_kv "$o" bad_test_rows); added at or before it: claims $(qf_kv "$o" added_claims), tests $(qf_kv "$o" added_tests); the tables hold, by state: offers $(qf_kv "$o" offers), recipients $(qf_kv "$o" recipients), tests $(qf_kv "$o" tests)"\n',
            why="OF-CA3 live")
    doc.mustnot('[ "$QF_G1" = "redeploy" ]')
    doc.mustnot('"${QF_G1:-}" = "redeploy"')
