# Part B — retire 5.18.102's QL-CA1 (the after-copy item comparison) and QL-LK1 (an --after-only's judgement of the
# links), root docs/72 §G.13: with the uCRM catalogue switched on links decide nothing, and under the owner's D6 every item
# stays unlinked, so QL-LK1 would fail every run. QL-G1, QL-N1–QL-N7, QL-CA2 and QL-CA3 are carried. The QL key and its
# file stay: QC-CA1 compares under it (Part C). The probe's QL-CA1/QL-LK1 code paths answer "-" now that no reading asks
# for them (no qla=, appmax=, copyat=, recs=, appmaxout=; no "lk"; after the copy no "catalogue").


def apply(doc, ctx):
    # ── qo_run: the local reading's arguments; the remote reading's words ──
    doc.line('    a) la+=("links=$pin/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "recs=$sub/recs.txt" "appmaxout=$sub/appmax.txt") ;;',
             '    a) la+=("links=$pin/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "cat=$pin/lib/UcrmCatalogue.php") ;;')
    doc.line('    r) la+=("links=$sub/l/$PLUGIN/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "recs=$sub/recs.txt" "qla=$sub/qla.txt" "appmax=${QL_IN_APPMAX:--}" "copyat=${QL_IN_COPYAT:--}") ;;',
             '    r) la+=("salt=$QL_KEY" "cat=$sub/l/$PLUGIN/lib/UcrmCatalogue.php") ;;')
    doc.sub('  case "$kind" in a) what="orgs,token,clients,quotes,catalogue,attr,q314"; code="$pin"; cin=0; cout=0 ;; m) what="orgs,clients"; code="$pin"; cin=0; cout=0 ;; *) what="orgs,token,check,quotes,catalogue,attr"; code="$inst"; cin="${QO_A_IN:-0}"; cout="${QO_A_OUT:-0}" ;; esac\n'
            '  [ "$kind" = "r" ] && [ "$MODE" = "after" ] && what="$what,lk"   # 5.18.102 D14 (§R.7.3): an --after-only\'s sheet section is QL-LK1\'s\n',
            '  case "$kind" in a) what="orgs,token,clients,quotes,catalogue,attr,q314,qc"; code="$pin"; cin=0; cout=0 ;; m) what="orgs,clients"; code="$pin"; cin=0; cout=0 ;; *) what="orgs,token,check,quotes,attr,qc"; code="$inst"; cin="${QO_A_IN:-0}"; cout="${QO_A_OUT:-0}" ;; esac\n'
            '  # 5.18.103 (docs/72 §G.13): after the copy the catalogue is QC-CA1\'s, through offered(); QL-CA1 and QL-LK1 are retired,\n'
            '  # so the remote reading after the copy reads no link catalogue ("catalogue" and "lk" are no longer asked for)\n')
    doc.sub('"q314=$sub/q314.txt" "qlh=$sub/qlh.txt" "salt=$QL_KEY" "qla=$( [ "$kind" = "r" ] && printf \'%s\' "$sub/qla.txt" )" "out=$sub/remote.txt"',
            '"q314=$sub/q314.txt" "qch=$sub/qch.txt" "salt=$QL_KEY" "out=$sub/remote.txt"')
    # the hash lines after the reading: QC's items replace QL's items, records and highest id
    doc.lines_between("  # 5.18.102 D14 (§R.7.2, derivation M-1): the items' and the records' keyed hashes and stage A's highest application id,",
                      '  QO_APPMAX="$(dxo sh -c \'if [ -f "$1" ]; then head -n1 "$1"; fi\' _ "$sub/appmax.txt")"; case "$QO_APPMAX" in \'\'|*[!0-9]*) QO_APPMAX="" ;; esac',
        "  # 5.18.103 (docs/72 §G.13): the direct catalogue's items' keyed hashes (QC-G1 at stage A, QC-CA1 after the copy),\n"
        "  # from their 0600 file in this reading's folder — in memory only, never printed; the file goes with the folder below\n"
        '  QO_QCI="$(dxo sh -c \'if [ -f "$1" ]; then cat "$1"; fi\' _ "$sub/qch.txt" | qo_hlines i items)"', why="qo_run hash reads")
    doc.sub("  # 5.18.102 D14 (§R.7.2, §R.7.3; R-12): after the copy, stage A's item and record hashes for both readings, through stdin\n"
            "  # into this reading's own folder (0600), never on a command line; what is not well formed is \"unknown\" (qo_qla_lines)\n"
            "  if [ \"$kind\" = \"r\" ]; then\n"
            "    { qo_qla_lines i items \"$QL_IN_I\"; qo_qla_lines r recs \"$QL_IN_R\"; } | docker exec -i -u \"$DB_OWNER\" \"$CONTAINER\" sh -c 'umask 077; cat > \"$1\"' _ \"$sub/qla.txt\" >/dev/null 2>&1 \\\n"
            "      || { QO_ERR=\"stage A's comparison lines could not be put in this reading's folder\"; cih_drop \"$sub\"; return 1; }\n"
            "  fi\n", "")
    doc.lines_between("  # 5.18.102 D14 (§R.7.1, §R.7.7 item 2; derivation M-1, M-6): the local reading's link arguments. a: the PIN's",
                      "  # stage A's lines (qla.txt), QLG1_APP_MAX and COPY_AT (\"-\" when not known); m: no link reading at all.",
        "  # 5.18.102 D14 (§R.7.1, §R.7.7 item 2; derivation M-1, M-6): the local reading's link arguments. a: the PIN's\n"
        "  # lib/UcrmQuoteLinks.php from Git, by its own path (never ICODE_A's root, copied above), and the QL key (QL-G1); r and\n"
        "  # m: no link reading at all — since 5.18.103 QL-CA1 and QL-LK1 are retired (docs/72 §G.13). 5.18.103: the RELEASE's\n"
        "  # lib/UcrmCatalogue.php by its own path (cat=) — a: the pin's from Git; r: the installed root's own copy — for the\n"
        "  # catalogue settings on the copy (QC).", why="la comment")
    doc.sub('QO_QLI=""; QO_QLREC=""; QO_APPMAX=""; QO_KIND=""   # 5.18.102 D14: the items\' and the records\' keyed hashes and stage A\'s highest application id (never printed), and which reading QO_R is',
            'QO_QCI=""; QO_KIND=""   # 5.18.103: the direct catalogue\'s items\' keyed hashes (never printed), and which reading QO_R is (5.18.102\'s QO_QLI, QO_QLREC and QO_APPMAX went with QL-CA1 and QL-LK1)')
    doc.sub('QL_IN_I=""; QL_IN_R=""; QL_IN_APPMAX=""; QL_IN_COPYAT=""   # 5.18.102 D14 (R-12): stage A\'s lines for qo_run r — set by its caller before qo_after, never printed\n', "")
    doc.sub('  QO_QLI=""; QO_QLREC=""; QO_APPMAX=""; QO_KIND="$kind"\n', '  QO_QCI=""; QO_KIND="$kind"\n')
    # qo_qla_lines: no caller any more
    doc.lines_between("# qo_qla_lines TAG HEAD VALUE — stage A's comma-joined value (QL_IN_I or QL_IN_R) as qla.txt's lines for the readings after",
                      "      print h \":known\"; for (i = 1; i <= n; i++) { k = index(e[i], \":\"); print t \" \" substr(e[i], 1, k - 1) \" \" substr(e[i], k + 1) } }'",
        "", why="qo_qla_lines")
    doc.sub("\n}\n}\n# qo_run a|m|r INST_ROOT [PIN_ROOT]", "\n}\n# qo_run a|m|r INST_ROOT [PIN_ROOT]", why="qo_qla_lines close")

    # ── ql_stage: QL-G1 carried; its hashes, made for QL-CA1, are no longer judged or kept ──
    doc.sub("# QL-N7's. With no quoting organization in force QL-G1 is a note. A refusal stops: nothing has changed. Sets QLG1_A\n"
            "# (QL-G1's counts by client and by class), QLG1_I_A (each item's keyed hash with its verdict's), QLG1_R_A (each link\n"
            "# record's keyed id hash with its state, a or e) and QLG1_APP_MAX (the highest kyc_applications id on the copy) — for this\n"
            "# run's QL-CA1 and, through the state file, a later --after-only's QL-CA1 and QL-LK1: hashes under the QL key, which is\n"
            "# kept in a file of mode 0600 beside the state file, never printed (D14 derivation M-1; D-R3's \"never stored\" amended to\n"
            "# that file's mode) — QLATTR_A, and QLG1_NOTED only when --accept-unpriced decided QL-G1.\n"
            "QLG1_A=\"\"; QLG1_I_A=\"\"; QLG1_R_A=\"\"; QLG1_APP_MAX=\"\"; QLATTR_A=\"\"; QLG1_NOTED=\"\"; QL_QN1=\"\"\n"
            "QLLK1_NOTED=\"\"; QL_KEY_A=\"\"   # 5.18.102 D14: QLLK1_NOTED 1 only when --accept-unpriced decided QL-LK1 (an --after-only run; F reads it); QL_KEY_A the QL key the readings after the copy compare under, set in stage R (never printed)\n",
            "# QL-N7's. With no quoting organization in force QL-G1 is a note. A refusal stops: nothing has changed. Sets QLG1_A\n"
            "# (QL-G1's counts by client and by class), QLATTR_A, and QLG1_NOTED only when --accept-unpriced decided QL-G1. Since\n"
            "# 5.18.103 (docs/72 §G.13) QL-CA1 and QL-LK1 are retired, so the items' and the link records' keyed hashes and the\n"
            "# highest application id they compared are neither judged nor kept.\n"
            "QLG1_A=\"\"; QLATTR_A=\"\"; QLG1_NOTED=\"\"; QL_QN1=\"\"\n"
            "QL_KEY_A=\"\"   # the QL key the readings after the copy compare under (QC-CA1 since 5.18.103), set in stage R (never printed)\n")
    doc.sub('  QLG1_APP_MAX="$QO_APPMAX"   # digits, or nothing: the state file then says "-", and QL-LK1 cannot tell which applications are the window\'s\n', "")
    doc.lines_between("      # The items' and the link records' keyed hashes (qo_run a's 0600 files, in memory only, never printed): QL-CA1",
                      '      QLG1_A="$c/$r"; QLG1_I_A="$QO_QLI"; QLG1_R_A="$QO_QLREC"',
                      '      QLG1_A="$c/$r"', why="ql_stage hashes")
    doc.sub('      [ -n "$QLG1_APP_MAX" ] || note "QL-G1 the highest kyc_applications id on the copy could not be read — the state file says \\"-\\", so an --after-only\'s QL-LK1 cannot tell which applications were made since the copy (its Make-the-quotation count)"\n', "")

    # ── ql_after: QL-CA2 only; QL-CA1 retired ──
    doc.lines_between("# ql_after INST_ROOT — QL-CA1 and QL-CA2 after the copy (stage R; --after-only too), on qo_after's reading (qo_run r: the",
                      "  # release sets no \"Prepared by\": a note, never an ok (E2-1, review 2). c is read whenever a is: both need the reading.",
        "# ql_after INST_ROOT — QL-CA2 after the copy (stage R; --after-only too), on qo_after's reading (qo_run r: the INSTALLED\n"
        "# code, a copy of it, ICODE_R, and uCRM with GETs only). Reports; never stops. 5.18.102's QL-CA1 — the installed\n"
        "# resolver's verdicts against stage A's, item by item, and the items still to link — is retired since 5.18.103 (docs/72\n"
        "# §G.13): with the uCRM catalogue on, links decide nothing, and QC-CA1 compares what the forms offer instead.\n"
        "ql_after() {\n"
        "  local inst=\"$1\" a rel ok_r=\"\"\n"
        "  case \"$QO_KIND:$QO_R\" in r:*\" gets=\"*\" other=\"*) ok_r=1 ;; esac\n"
        "  # QL-CA2 — the salesPerson attribute again, through the installed code's reading (a note when it differs). With no\n"
        "  # quoting organization in force through the installed code (the probe's qlattrrel \"off\"), the installed release sets\n"
        "  # no \"Prepared by\": a note, never an ok (E2-1, review 2).", why="ql_after head")
    doc.sub('  a="$( [ -n "$ok_r" ] && [ -n "$inst" ] && kv "$QO_R" qlattr)"; rel="$( [ -n "$ok_r" ] && [ -n "$inst" ] && kv "$QO_R" qlattrrel)"\n',
            '  a="$( [ -n "$ok_r" ] && [ -n "$inst" ] && kv "$QO_R" qlattr)"; rel="$( [ -n "$ok_r" ] && [ -n "$inst" ] && kv "$QO_R" qlattrrel)"\n')
    doc.sub('  elif [ "$c" = "off" ] || [ "$rel" = "off" ]; then note "QL-CA2 the client attribute keyed salesPerson, through the installed code\'s reading:',
            '  elif [ "$rel" = "off" ]; then note "QL-CA2 the client attribute keyed salesPerson, through the installed code\'s reading:')
    doc.sub('      one:*) if [ "$c" != "off" ] && [ "$rel" != "off" ] && [ "$rel" = "${a#one:}" ]; then ok "QL-CA2',
            '      one:*) if [ "$rel" != "off" ] && [ "$rel" = "${a#one:}" ]; then ok "QL-CA2')
    doc.mustnot('"$c" = "off" ] || [ "$rel" = "off" ]')
    # ql_lk1: gone
    doc.lines_between("# ql_lk1 — QL-LK1, an --after-only's judgement of the links (docs/71 §R.7.3; D14 derivation M-10, M-14, M-15), on the",
                      "# ql_ca3_txt pin|inst — QL-CA3's text read (docs/71 §R.7.6 item 2): includes/post/post_sync.php's three blocks D14 adds,",
        "# (5.18.102's ql_lk1 — QL-LK1, an --after-only's judgement of the links — is retired since 5.18.103, docs/72 §G.13: with the\n"
        "# uCRM catalogue on links decide nothing, and under D6 every item stays unlinked, so it would fail every run.)\n"
        "# ql_ca3_txt pin|inst — QL-CA3's text read (docs/71 §R.7.6 item 2): includes/post/post_sync.php's three blocks D14 adds,", why="ql_lk1")
    doc.mustnot("ql_lk1()")

    # ── qo_run's header and ql_hl_ok's comment, for what the readings now set ──
    doc.lines_between("# returns; the run's folder again by the trap. 5.18.102: local also writes the offer and q314 files (the rows the KYC",
                      "# --after-only's remote reading also asks for lk, so its sheet section is QL-LK1's.",
        "# returns; the run's folder again by the trap. 5.18.102: local also writes the offer and q314 files (the rows the KYC\n"
        "# form offers; quotation 000314's quote id), which remote reads — at stage A resolving the rows through the PIN's code\n"
        "# (QL-G1, QL-N7), reading the salesPerson attribute (QL-N1; after the copy QL-CA2) and quotation 000314 (QL-N2) — the\n"
        "# names to the sheet with the rest. 5.18.102 D14 (§R.7.1): stage A's local reading loads the RELEASE's\n"
        "# lib/UcrmQuoteLinks.php by its own path — the pin's from Git (PIN_ROOT), never the root it copies — and reads the link\n"
        "# store on the copy; after the copy no link is read (QL-CA1 and QL-LK1 are retired since 5.18.103, docs/72 §G.13).\n"
        "# 5.18.103: local also reads the uCRM catalogue's settings on the copy through the RELEASE's lib/UcrmCatalogue.php by\n"
        "# its own path (a: the pin's from Git; r: the installed root's own copy), the applications by entry, the stock\n"
        "# categories' titles and public_price_exclude (to the offer file); remote reads what the forms offer through that\n"
        "# code's offered() (QC-G1 at stage A, QC-CA1 after the copy). The readings key their hashes with the QL key (QL_KEY,\n"
        "# salt=), never MAP_SALT. Sets QO_QCI (each direct item's keyed hash with its verdict's, \"<H>:<H>,…\", or none), read\n"
        "# from its 0600 file in this reading's folder before the folder goes, in memory only, never printed (qo_hlines;\n"
        "# \"?<why>\" when the file is not as written) — and QO_KIND, the kind of reading QO_L and QO_R now hold.", why="qo_run header")
    doc.sub('ql_hl_ok() {   # ql_hl_ok i|r VALUE — VALUE (QO_QLI, QLG1_I_A: "<H>:<H>,…"; QO_QLREC, QLG1_R_A: "<H>:a|e,…" or none) as written',
            'ql_hl_ok() {   # ql_hl_ok i|r VALUE — VALUE (since 5.18.103 QO_QCI, QC_I_A: "<H>:<H>,…"; an r value "<H>:a|e,…" or none) as written')
