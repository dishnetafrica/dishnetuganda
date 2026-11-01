# Part D — what the operator reads: the options, the run's banner, the deploy's opening line, stage V's QC-CA2 and QC-CA3,
# the summary (F) and the header. Root docs/72 §G.13.
import os
HERE = os.path.dirname(os.path.abspath(__file__))

QC_V_FUNCS = r'''# qc_ca2_txt pin|inst — QC-CA2's text read (5.18.103, docs/72 §G.3): includes/post/post_sync.php's one block for the uCRM
# catalogue's five actions, whole-line comments aside (sec_src, sec_block, sec_code — the readers QL-CA3 uses). The block is
# found by its own first line, and every code line of the file that names an action lies inside it. The gate's condition —
# QuoteOrganization::setting(...)['on'] — at the block's first level, every line after it one level in, up to the gate's
# close, the block's last two lines; then $auth->requireAdmin(), the account's fresh re-read ($store->findOne('retailers.json',
# 'id', …)) and the own-page check (csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')), in that
# order, each before the block's first act — a UcrmCatalogue write (setMode, setInstall, setHidden, saveStandard,
# endStandard) or a $store-> call other than that re-read — and each of the five writes present. Prints "ok" or fixed words,
# never a line of the code. The caller reads the pin's file from Git the same way, the control.
qc_ca2_txt() {
  local src code blk act w="" one inb
  src="$(sec_src "$1" includes/post/post_sync.php)"
  [ -n "$src" ] || { echo nofile; return 0; }
  code="$(printf '%s\n' "$src" | sec_code)"
  blk="$(printf '%s\n' "$src" | sec_block "if (\$_SERVER['REQUEST_METHOD'] === 'POST'" | sec_code)"
  for act in ucrm_cat_mode ucrm_cat_install ucrm_cat_hidden ucrm_cat_standard_save ucrm_cat_standard_end; do
    one="$(grep -cF -- "'$act'" <<<"$code" || true)"; inb="$(grep -cF -- "'$act'" <<<"$blk" || true)"
    { [ "${inb:-0}" -ge 1 ] && [ "$one" = "$inb" ]; } || w="$w $act:named-on-${one:-?}-code-lines-${inb:-?}-in-the-block"
  done
  w="$w$(printf '%s\n' "$src" | sec_block "if (\$_SERVER['REQUEST_METHOD'] === 'POST'" | sec_code \
    | QA_GATE="    if (QuoteOrganization::setting(" QA_ON="['on']) {" QA_RA="\$auth->requireAdmin()" QA_FR="\$store->findOne('retailers.json', 'id', " \
      QA_CS="csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST')" awk '
      { L[NR] = $0 }
      END {
        w = ""
        if (NR < 4 || index(L[2], "ucrm_cat_mode") == 0) { printf " ucrm_cat:noblock"; exit }
        g = 0; ra = 0; fr = 0; cs = 0; fa = 0; n = split("setMode(,setInstall(,setHidden(,saveStandard(,endStandard(", W, ",")
        for (i = 3; i <= NR; i++) {
          s = L[i]
          if (!g && index(s, ENVIRON["QA_GATE"]) == 1 && index(s, ENVIRON["QA_ON"]) > 0) g = i
          if (!ra && index(s, ENVIRON["QA_RA"]) > 0) ra = i
          if (!fr && index(s, ENVIRON["QA_FR"]) > 0) fr = i
          if (!cs && index(s, ENVIRON["QA_CS"]) > 0) cs = i
          for (k = 1; k <= n; k++) if (index(s, "UcrmCatalogue::" W[k]) > 0) { H[k]++; if (!fa) fa = i }
          if (!fa && index(s, "$store->") > 0 && index(s, ENVIRON["QA_FR"]) == 0) fa = i
        }
        if (!g) w = w " ucrm_cat:no-gate"
        else {
          if (L[NR - 1] != "    }" || L[NR] != "}") w = w " ucrm_cat:the-gate-does-not-close-the-block"
          for (i = g + 1; i <= NR - 2; i++) if (L[i] !~ /^[ \t]*$/ && L[i] !~ /^        /) { w = w " ucrm_cat:a-line-outside-the-gate"; break }
        }
        if (!ra) w = w " ucrm_cat:no-requireAdmin"
        if (!fr) w = w " ucrm_cat:no-fresh-re-read"
        if (!cs) w = w " ucrm_cat:no-own-page-check"
        for (k = 1; k <= n; k++) if (!H[k]) w = w " ucrm_cat:no-UcrmCatalogue::" substr(W[k], 1, length(W[k]) - 1)
        if (g && ra && fr && cs) {
          if (!(g < ra && ra < fr && fr < cs)) w = w " ucrm_cat:out-of-order"
          if (fa && fa < cs) w = w " ucrm_cat:an-act-before-the-checks"
        }
        printf "%s", w
      }')"
  sec_say "$w"
}
# qc_ca3_txt pin|inst — QC-CA3's text read (5.18.103, docs/72 §G.9, G-15): Send Quotation and the lead quotes re-price each
# picked uCRM line inside QuotationService, from a fresh read — the installed includes/post/post_leads.php calling
# QuotationService::directForm( and QuotationService::pickedLine( (each at least once), and lib/QuotationService.php
# defining directForm, pickedLine and directLines and refusing a changed price (DIRECT_PRICE_CHANGED) — each count, comments
# aside, equal to the pin's, which must read at least one of each (the control). Prints "ok" or fixed words.
qc_ca3_txt() {
  local w="" f nd p i
  while IFS='|' read -r f nd; do
    p="$(sec_src pin "$f" | sec_code | grep -cF -- "$nd" || true)"; i="$(sec_src "$1" "$f" | sec_code | grep -cF -- "$nd" || true)"
    case "$p" in ''|0) w="$w $f:the-control-reads-none-of-$nd" ; continue ;; esac
    [ "$i" = "$p" ] || w="$w $f:$nd×${i:-?}-not-$p"
  done <<'NEEDLES'
includes/post/post_leads.php|QuotationService::directForm(
includes/post/post_leads.php|QuotationService::pickedLine(
lib/QuotationService.php|function directForm(
lib/QuotationService.php|function pickedLine(
lib/QuotationService.php|function directLines(
lib/QuotationService.php|DIRECT_PRICE_CHANGED
NEEDLES
  sec_say "$w"
}
'''

QC_V_CHECKS = r'''      # QC-CA2 — 5.18.103 (docs/72 §G.3, §G.13): the uCRM catalogue screen — where an admin turns direct mode on and sets what
      # every Uganda form offers — after the copy and without a session, with the gate in whatever state is installed: GET
      # ?page=dashboard&tab=ucrm_catalogue must send the visitor to the sign-in page (302, page=login), with no ucrm_cat_
      # action field in the answer and no PHP fatal (CA4's tuple form). GET only, as QL-CA3: the deploy never POSTs to a
      # write action. Then the installed includes/post/post_sync.php as text (qc_ca2_txt): the block of the five actions
      # POST-only, inside the gate, calling $auth->requireAdmin(), the account's fresh re-read and the own-page check, in that
      # order, before any write — beside its control, the pin's file from Git: unless the pin reads as the release must, the
      # check refuses rather than passes. QC-CA3: Send Quotation and the lead quotes re-price each picked uCRM line inside
      # QuotationService (qc_ca3_txt), the installed files as text beside the pin's.
      http GET "$PLUGIN_BASE?page=dashboard&tab=ucrm_catalogue"
      QCCA2_R="$HTTP_CODE|$(grep -qF 'page=login' <<<"$HTTP_LOCATION" && echo login || echo other)|$(count 'value="ucrm_cat_')|$(count 'Fatal error')$(count 'Parse error')"
      if [ "$QCCA2_R" = "302|login|0|00" ]; then ok "QC-CA2 the uCRM catalogue screen (?page=dashboard&tab=ucrm_catalogue) without a session sends the visitor to the sign-in page (302) — none of its actions in the answer, no PHP fatal"
      else bad "QC-CA2 the uCRM catalogue screen does not answer a visitor with no session with the sign-in page as it must: $QCCA2_R (code|where|marker|fatals; expected 302|login|0|00)"; fi
      QCCA2_P="$(qc_ca2_txt pin)"; QCCA2_I="$(qc_ca2_txt inst)"
      if [ "$QCCA2_P|$QCCA2_I" = "ok|ok" ]; then ok "QC-CA2 the installed includes/post/post_sync.php, read as text (comments aside): the uCRM catalogue's five actions — ucrm_cat_mode, ucrm_cat_install, ucrm_cat_hidden, ucrm_cat_standard_save and ucrm_cat_standard_end — are POST-only, inside QuoteOrganization::setting(...)['on'], and call \$auth->requireAdmin(), then the account's fresh re-read, then the own-page check, before any write — beside its control, the pin's from Git, which reads the same"
      elif [ "$QCCA2_P" != "ok" ]; then bad "QC-CA2 the text read of post_sync.php refuses rather than passes: its control, the pin's file from Git ($EXPECTED_PLUGIN_COMMIT), does not read as the release must ($QCCA2_P) — a reader, a path or a needle that cannot see the release proves nothing of the installed file. Send the log file"
      else bad "QC-CA2 the installed includes/post/post_sync.php does not guard the uCRM catalogue's actions as the release does ($QCCA2_I) — read as text beside its control, the pin's from Git, which reads as it must. Send the log file"; fi
      QCCA3_P="$(qc_ca3_txt pin)"; QCCA3_I="$(qc_ca3_txt inst)"
      if [ "$QCCA3_P|$QCCA3_I" = "ok|ok" ]; then ok "QC-CA3 Send Quotation and the lead quotes re-price each picked uCRM line inside QuotationService from a fresh read: the installed post_leads.php calls QuotationService::directForm( and ::pickedLine(, and lib/QuotationService.php defines directForm, pickedLine and directLines and refuses a changed price — read as text, each count the pin's (its control)"
      elif [ "$QCCA3_P" != "ok" ]; then bad "QC-CA3 the text read refuses rather than passes: its control, the pin's files from Git, do not read as the release must ($QCCA3_P). Send the log file"
      else bad "QC-CA3 the installed Send Quotation code does not re-price picked uCRM lines as the release does ($QCCA3_I) — read as text beside the pin's. Send the log file"; fi
'''


def apply(doc, ctx):
    # ── the options ──
    doc.sub('ACCEPT_UNPRICED=""; ROLLBACK_UNPRICED=""   # 5.18.102 (docs/71 §L.3, §Q D-R2, D-R13): the two explicit choices QL-G1 and QL-RB2 accept, each said as such — what decided a check is QLG1_NOTED and QLRB_NOTED, set where it does',
            'ACCEPT_UNPRICED=""   # 5.18.102 (docs/71 §L.3, §Q D-R13): the explicit choice QL-G1 accepts, said as such — what decided it is QLG1_NOTED, set where it does (5.18.103: QL-RB2, and with it --rollback-unpriced-pending, is retired — docs/72 §G.10)')
    doc.sub("    --rollback-unpriced-pending) ROLLBACK_UNPRICED=1 ;;          # 5.18.102 (QL-RB2, D-R2): a rollback over unpriced applications still pending for uCRM, accepted\n", "")
    doc.lines_between("# 5.18.102 D14 (docs/71 §R.7.3, §R.7.7 item 9; D14 derivation M-14): --accept-unpriced has two meanings — with a deploy or",
                      '{ [ -z "$ROLLBACK_UNPRICED" ] || [ "$MODE" = "rollback" ]; } || { echo "--rollback-unpriced-pending goes with --rollback only,',
        "# 5.18.102 D14 (docs/71 §R.7.1): --accept-unpriced is QL-G1's — its refusal of a kind uCRM offers nothing an admin could link\n"
        "# for — so it goes with a deploy or --check only. (Its --after-only meaning went with QL-LK1, retired since 5.18.103.)\n"
        "{ [ -z \"$ACCEPT_UNPRICED\" ] || [ \"$MODE\" = \"deploy\" ]; } || { echo \"--accept-unpriced goes with a deploy or --check, never with --after-only, --rollback, --check-bank or --move-list-only\" >&2; exit 64; }",
        why="options")
    doc.sub('$( [ -n "$ACCEPT_UNPRICED" ] && echo ", --accept-unpriced")$( [ -n "$ROLLBACK_UNPRICED" ] && echo ", --rollback-unpriced-pending"))',
            '$( [ -n "$ACCEPT_UNPRICED" ] && echo ", --accept-unpriced"))')
    doc.mustnot("ROLLBACK_UNPRICED")
    # ── the deploy's opening line for admins ──
    doc.lines_between("# 5.18.102 D14 (docs/71 §R.7.2, §R.6 step 5; SPEC-d14deploy R-11): the deploy's opening line for admins. From stage B the",
                      'if [ "$MODE" = "deploy" ] && [ -z "$CHECK_ONLY" ]; then echo "  admins          make no uCRM link, unlink, or plan or kit save',
        "# 5.18.103 (docs/72 §G.13): the deploy's opening line for admins. From stage B the release is live and its uCRM catalogue\n"
        "# screen is drawn, while QC-CA1 compares what the forms offer after the copy with stage A's reading — so no admin uses that\n"
        "# screen (the switch, the installation product, a product kept off, a standard quotation), makes a uCRM link or saves a\n"
        "# plan or a kit until the run has printed its last line. Said in a deploy only (with --redeploy too); a --check deploys\n"
        "# nothing.\n"
        "if [ \"$MODE\" = \"deploy\" ] && [ -z \"$CHECK_ONLY\" ]; then echo \"  admins          leave the uCRM catalogue screen as it is — its switch, the installation product, the products kept off and the standard quotations — and make no uCRM link, unlink, or plan or kit save, until this run prints its last line (docs/72 §G.13): then turn the uCRM catalogue on, as its summary's steps say\"; fi",
        why="opening line")
    # ── stage V: QC-CA2, QC-CA3 ──
    doc.sub("# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────",
            QC_V_FUNCS + "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────")
    anchor = '      else bad "QL-CA3 the installed admin-only gate (\\$_gateAdminOnly in includes/api/api_products_admin.php, read as text) does not name both Product Mapping actions ($QLCA3_MI), as its control, the pin\'s from Git, does. Send the log file"; fi\n'
    doc.sub(anchor, anchor + QC_V_CHECKS)
    doc.sub("QL-CA3 (ECO Bank, the Cashbook and their exports, Sync All, the KYC, quotation, order and LTE pages, the Plans and Hardware screens' uCRM links and the Orders card's Make the quotation)",
            "QL-CA3, QC-CA2 (ECO Bank, the Cashbook and their exports, Sync All, the KYC, quotation, order and LTE pages, the Plans and Hardware screens' uCRM links, the Orders card's Make the quotation and the uCRM catalogue screen)", 3)

    # ════════════════ F. the summary ════════════════
    doc.lines_between("# 5.18.102: the names of its own checks, said once — QL-G1 (a refusal before the copy), QL-N1–QL-N7 (notes), QL-CA1,",
                      'echo "  uCRM              asked $QO_GETS GET(s) by this run',
        "# 5.18.103: the names of its own checks, said once — QC-G1 (a refusal before the copy), QC-N1–QC-N7 (notes), QC-CA1 (after\n"
        "# the copy), QC-CA2 and QC-CA3 (stage V), QC-ON1–QC-ON4 (an --after-only run once the switch is on), QC-RB1 and QC-RB2 (after\n"
        "# a rollback; QC-RB2's count is stage A of --rollback's note too) — \"quote catalogue\", so that none reads as an earlier\n"
        "# release's check; 5.18.102's (QL-G1, QL-N1–QL-N7, QL-CA2, QL-CA3 — its QL-CA1, QL-LK1 and QL-RB1–QL-RB3 retired, docs/72\n"
        "# §G.13), 5.18.101's, 5.18.100's, 5.18.99's and 5.18.98's, carried, keep theirs. The catalogue's reads, the organization\n"
        "# field's and the notes' are GETs of the quoting checks' own probe, through its GET-only wrapper, so QO_GETS and QO_OTHER\n"
        "# count them with Q-G4's and Q-CA3's; QL-CA3's and QC-CA2's are stage V's, from the host to the plugin's own public\n"
        "# address, as V's other anonymous refusals are.\n"
        "echo \"  names             QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4, QC-RB1 and QC-RB2 are 5.18.103's own checks\"\n"
        "echo \"                    (QC, \\\"quote catalogue\\\"); QL-G1, QL-N1–QL-N7, QL-CA2 and QL-CA3 are 5.18.102's (its QL-CA1, QL-LK1\"\n"
        "echo \"                    and QL-RB1–QL-RB3 retired); QS-G1 and QS-CA1 are 5.18.101's; TN-G1–TN-G3, TN-N1, TN-N2 and\"\n"
        "echo \"                    TN-CA1–TN-CA3 are 5.18.100's; Q-G1–Q-G6, Q-N1–Q-N7 and Q-CA1–Q-CA6 are 5.18.99's\"\n"
        "echo \"                    (SPEC-quote-company §6), and 5.18.98's G2, G3, CA2–CA4, C1–C5 and N1–N4 — each carried with its name\"\n"
        "echo \"  uCRM              asked $QO_GETS GET(s) by this run — the quoting checks', 5.18.102's catalogue, organization field and notes (QL-G1, QL-N1, QL-N2, QL-CA2) and 5.18.103's direct catalogue (QC-G1, QC-CA1) alike — through its GET-only wrappers, and nothing else ($QO_OTHER other)\"",
        why="F names")
    doc.lines_between('    echo "                    items by name, each with its reason, which can carry a price (QL-G1, QL-N7), and, per item,"',
                      '    echo "                    verdict — and the organization numbers uCRM gives its products — for you alone; never paste it."',
        '    echo "                    items by name, each with its reason, which can carry a price (QL-G1, QL-N7), and, per item,"\n'
        '    echo "                    under \\"uCRM items with the same spelling — a hint only\\", the uCRM items spelled like it, by id,"\n'
        '    echo "                    with whether each could be linked, a hint, never a verdict; the organization numbers uCRM gives"\n'
        '    echo "                    its products; what the forms would offer from uCRM in direct mode — each plan and hardware item"\n'
        '    echo "                    with its uCRM ids, price and unit, every other item with its reason (QC-G1) — the uCRM catalogue\'s"\n'
        '    echo "                    settings on the copy, and the waiting web applications\' ids (QC-N4) — for you alone; never paste it."',
        why="F check sheet")
    doc.line('            echo "  what it means     on Uganda, where the quoting organization is in force, the KYC quotations and the Cash amount come again from',
             '            echo "  what it means     on Uganda, where the quoting organization is in force, the uCRM catalogue screen is gone, the Plans and Hardware screens are back with their uCRM link pickers, and the KYC form, Send Quotation and the lead quotes offer the plugin\'s own plans and kits again, priced through the uCRM item linked to each (RB); the web-direct applications $EXPECTED_VERSION made are never quoted by $BASELINE_VERSION — ${QCRB_SAY:-not counted} (QC-RB2): an admin quotes each in uCRM by hand, after checking the customer has none, their ids on this run\'s sheet; nothing is quoted at price 0. The uCRM catalogue\'s settings stay in the store, read by nothing (QC-RB1); a later deploy of $EXPECTED_VERSION finds them as they were. The Cashbook, ECO Bank, every number and the quoting organization stay as they are (RB)" ;;',
             why="F rollback meaning")
    # F_QD9: D9 was 5.18.102's change; 5.18.103 changes nothing webhook.php or post_kyc.php does
    doc.lines_between('    on|stageA) F_QD9=" — though, with the quoting organization in force,',
                      '    unknown) F_QD9=" — though, if the quoting organization is in force,',
        '    on|stageA|unknown) F_QD9="" ;;   # 5.18.103: D9 (no Delivery PDF on Uganda) was 5.18.102\'s change; this release changes nothing webhook.php or post_kyc.php does',
        why="F_QD9")
    doc.lines_between("  # 5.18.102 (docs/71 §A–§K, §P.2; D14 §R): said where the quoting organization is in force — as read after the copy, or",
                      '    else echo "  the flag          --accept-unpriced was typed and decided nothing (QL-LK1 above): nothing it lifts was there to lift"; fi',
        "  # 5.18.103 (docs/72 §G): said where the quoting organization is in force — as read after the copy, or as stage A read it\n"
        "  # where nothing was read after it (above); with ucrm_quote_organization_id 0/off read after the copy the release quotes\n"
        "  # as 5.18.102 does with it off (D7); otherwise NOT ESTABLISHED, and said so. The uCRM catalogue's switch as this run's\n"
        "  # reading after the copy found it (qcsw: on only where an earlier, rolled-back run left it on or, in an --after-only run,\n"
        "  # once an admin turned it on) decides F_SWW; --accept-unpriced is said where it decided QL-G1 (this run's QLG1_NOTED, or\n"
        "  # in an --after-only run the deploy's, from its state file). Counts and fixed words only.\n"
        "  F_QLN=\"${QLG1_NOTED:-}\"\n"
        "  [ -n \"$F_QLN\" ] || [ \"$MODE\" != \"after\" ] || F_QLN=\"$(sed -n 's/^QLG1_NOTED=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n"
        "  F_SW103=\"\"; F_P1=\"\"\n"
        "  case \"${QO_KIND:-}:${QO_R:-}\" in r:*\" gets=\"*\" other=\"*) F_SW103=\"$(kv \"${QO_L:-}\" qcsw)\"; F_P1=\"$(kv \"${QO_L:-}\" qcwebp1)\" ;; esac\n"
        "  case \"$F_SW103\" in on) F_SWW=\"ON\" ;; off|none) F_SWW=\"off\" ;; *) F_SWW=\"not read after the copy (QC-CA1 above)\" ;; esac\n"
        "  case \"$F_P1\" in ''|*[!0-9]*) F_P1=\"not counted\" ;; esac\n"
        "  F_QLNS=\"\"\n"
        "  [ -z \"$F_QLN\" ] || F_QLNS=\" The deploy went ahead on --accept-unpriced — the operator's explicit choice (QL-G1): with the switch off a kind of item uCRM offered nothing linkable for stays unpriced until uCRM is prepared and its items linked, as under $BASELINE_VERSION; with the switch on, links decide nothing.\"\n"
        "  if [ -n \"$F_QORG\" ]; then\n"
        "    echo \"  what changed      on this Uganda server (Q-G2), the quoting organization #$F_QORG in force$F_QIN: a new screen, the uCRM catalogue — in the menu for those who may see Plans or Hardware, its settings an admin's — shows what uCRM's Products and Service Plans offer, each plan with its product, each hardware item and the installation product, and why every other item is not offered. Its switch is $F_SWW. With it off — as a deploy leaves it — $EXPECTED_VERSION quotes exactly as $BASELINE_VERSION: the KYC quotations through the uCRM link an admin records for each plan, kit and the installation. With it on, the KYC form, Send Quotation and the lead quotes offer uCRM's own plans and hardware, by uCRM id, at uCRM's prices; the plugin's Plans and Hardware leave the menu on Uganda; a standard quotation fills the KYC form in one click; and a web registration of $BASELINE_VERSION left unpriced is quoted in uCRM by hand (docs/72 G-10; $F_P1 waiting now, QC-N4). The retailer app and the mobile API keep the plugin's own lists. No migration (R7).$F_QLNS\"\n"
        "  elif [ \"$F_QST\" = \"off\" ]; then\n"
        "    echo \"  what changed      on this server the quoting organization is not in force ($F_QOFF), so $EXPECTED_VERSION quotes as $BASELINE_VERSION does with it off (D7): the uCRM catalogue lives under it, so its screen is not shown and nothing is offered from uCRM; every screen as before. No migration (R7)\"\n"
        "  else\n"
        "    echo \"  what changed      whether the quoting organization is in force through the installed code is NOT ESTABLISHED: it was $F_QNR, and $( [ -n \"$F_QSA\" ] && echo \"stage A read it $F_QSA (Q-G3)\" || echo \"$F_QSAW\") — so this run does not say what $EXPECTED_VERSION changes here. With it in force, the uCRM catalogue screen is shown and, once an admin turns its switch on, the forms offer uCRM's own plans and hardware (docs/72 §G); with it 0/off, $EXPECTED_VERSION quotes as $BASELINE_VERSION does (D7). The --after-only below reads it again. No migration, no entry, no setting, no number and no staff row changed (R7, R11, TN-N1)\"\n"
        "  fi", why="F what changed")
    doc.sub('  fi\n  fi\n  if [ "$MN_BEFORE|$OL_BEFORE|$FH_BEFORE|$HC_BEFORE"', '  fi\n  if [ "$MN_BEFORE|$OL_BEFORE|$FH_BEFORE|$HC_BEFORE"', why="F flag fi")
    doc.sub("($EXPECTED_VERSION's keys on Uganda applications among it: R7, QL-RB2)", "($EXPECTED_VERSION's keys on Uganda applications among it: R7, QC-RB2)", 2)
    doc.sub("no quote, no client, no product and no attribute created, changed or deleted (Q-G4, Q-CA3, QL-G1, QL-CA1);",
            "no quote, no client, no product and no attribute created, changed or deleted (Q-G4, Q-CA3, QL-G1, QC-G1, QC-CA1);")
    doc.lines_between('  echo "                    uCRM link and reason, which can carry a price (QL-G1, QL-CA1, QL-LK1, QL-N7), under \\"uCRM items"',
                      '  echo "                    (QL-LK1), and the lines of the physical count to fill in on paper. For you alone: read it on the"',
        '  echo "                    uCRM link and reason, which can carry a price (QL-G1, QL-N7), under \\"uCRM items with the same"\n'
        '  echo "                    spelling — a hint only\\" the uCRM items spelled like each, by id, with whether each could be"\n'
        '  echo "                    linked, the organization numbers uCRM gives its products, what the forms offer from uCRM in"\n'
        '  echo "                    direct mode — each item with its uCRM ids, price and unit, or its reason (QC-G1, QC-CA1) — the"\n'
        '  echo "                    uCRM catalogue\'s settings, the ids of the waiting web applications (QC-N4) and, after a"\n'
        '  echo "                    rollback, of the web-direct ones to quote by hand (QC-RB2), and the lines of the physical count"\n'
        '  echo "                    to fill in on paper. For you alone: read it on the"', why="F cash sheet lines")
    doc.sub('$( [ -n "$F_QORG" ] && echo "once every item the KYC form offers is linked (your steps, 7, below) — an --after-only before that fails QL-LK1 by design and says what is left (docs/71 §R.6 step 8) — and ")',
            '$( [ -n "$F_QORG" ] && echo "once an admin has turned the uCRM catalogue on (your steps, 3, below) — an --after-only before that fails QC-ON1 by design — and ")')

    # the screens and the operator's steps
    doc.lines_between('    echo "  the screens       on Uganda, signed in: the Plans and Hardware screens carry a \\"uCRM link\\" column, with Link,"',
                      '    echo "                    it is off"',
        '    echo "  the screens       on Uganda, signed in: a new menu entry, \\"uCRM catalogue\\", for those who may see Plans or"\n'
        '    echo "                    Hardware — its settings an admin\'s: the switch, the installation product, the products kept"\n'
        '    echo "                    off the forms, the standard quotations, and what uCRM offers, with why each other item is not"\n'
        '    echo "                    offered. With the switch off — as the deploy leaves it — every other screen is as under"\n'
        '    echo "                    $BASELINE_VERSION. With it on: Plans and Hardware leave the menu (their pages show a card"\n'
        '    echo "                    pointing to the new screen) and their link pickers refuse; the KYC form offers uCRM\'s plans"\n'
        '    echo "                    and hardware by uCRM id and price, with the standard quotations as buttons, and stores each"\n'
        '    echo "                    sale\'s uCRM items; Send Quotation and the lead quotes offer the same and re-price every line"\n'
        '    echo "                    from uCRM at the send; the Orders card of a web registration made under $BASELINE_VERSION and"\n'
        '    echo "                    left unpriced says to quote it in uCRM by hand. The retailer app and the mobile API keep the"\n'
        '    echo "                    plugin\'s own lists (docs/72 §G.11). The Salesperson numbers card, the Cashbook and ECO Bank as"\n'
        '    echo "                    before; no screen of uCRM\'s changes. Do NOT make a test quote for a real customer: the plugin"\n'
        '    echo "                    WhatsApps every new quote"\n'
        '    echo\n'
        '    echo "  your steps        yours alone — this script does none of them (docs/72 §G.3, §G.13):"\n'
        '    echo "                    1. once this run has printed its last line, open the uCRM catalogue screen (Uganda), as an"\n'
        '    echo "                       admin, and check what it offers — the sheet lists each item and why each other is not"\n'
        '    echo "                       offered (QC-G1, QC-CA1); keep off the forms any product that is not to be sold (a fee, a"\n'
        '    echo "                       service); set the installation product; save the standard quotations wanted (one click"\n'
        '    echo "                       each on the KYC form);"\n'
        '    echo "                    2. where QC-N5 counted hardware with no stock category of the same title, title those"\n'
        '    echo "                       categories as uCRM names the products — a completed job deducts stock by that title"\n'
        '    echo "                       (docs/72 §G.7);"\n'
        '    echo "                    3. turn the switch on — the screen refuses while no plan is offered from uCRM$( [ "$F_SWW" = "ON" ] && echo " (it is ON already: QC-N1)");"\n'
        '    echo "                    4. then --after-only (check it, above): QC-ON1–QC-ON4 judge the switch, the installation"\n'
        '    echo "                       product, the standard quotations and the first direct sales — it fails until the switch is"\n'
        '    echo "                       on — and once more the next day after 15:00 UTC;"\n'
        '    echo "                    5. tell the agents: on Uganda the KYC form, Send Quotation and the lead quotes now offer uCRM\'s"\n'
        '    echo "                       plans and hardware at uCRM\'s prices; a web registration made under $BASELINE_VERSION and"\n'
        '    echo "                       left unpriced is quoted in uCRM by hand, after checking the customer has none (QC-N4:"\n'
        '    echo "                       $F_P1 waiting now, their ids on the sheet); the retailer app and the mobile API keep the"\n'
        '    echo "                       plugin\'s own lists (QC-N3 counts their use)."\n'
        '    echo "                    Until step 3, $EXPECTED_VERSION behaves as $BASELINE_VERSION: nothing else is needed for it to"\n'
        '    echo "                    run as it does today."\n'
        '  elif [ "$F_QST" = "off" ]; then\n'
        '    echo "  the screens       none changes: with the quoting organization not in force, the uCRM catalogue is not shown"\n'
        '    echo "                    and every screen is as under $BASELINE_VERSION; the Salesperson numbers card, the Cashbook and"\n'
        '    echo "                    ECO Bank as before; no screen of uCRM\'s changes. Do NOT make a test quote for a real customer:"\n'
        '    echo "                    the plugin WhatsApps every new quote"\n'
        '    echo\n'
        '    echo "  your steps        none now: with the quoting organization not in force, $EXPECTED_VERSION quotes as"\n'
        '    echo "                    $BASELINE_VERSION does, and the uCRM catalogue lives under it. Once ucrm_quote_organization_id"\n'
        '    echo "                    is turned on, an admin finds the uCRM catalogue screen on Uganda and follows docs/72 §G.3; run"\n'
        '    echo "                    --after-only after the switch is on (QC-ON1–QC-ON4)."\n'
        '  else\n'
        '    echo "  the screens       not stated: whether the quoting organization is in force through the installed code is not"\n'
        '    echo "                    established by this run (what changed, above). With it in force, the uCRM catalogue screen is"\n'
        '    echo "                    shown, and once an admin turns its switch on the forms offer uCRM\'s own plans and hardware"\n'
        '    echo "                    (docs/72 §G); with it 0/off, every screen is as under $BASELINE_VERSION. The Salesperson numbers"\n'
        '    echo "                    card, the Cashbook and ECO Bank as before; no screen of uCRM\'s changes. Do NOT make a test quote"\n'
        '    echo "                    for a real customer: the plugin WhatsApps every new quote"\n'
        '    echo\n'
        '    echo "  your steps        not stated until the quoting organization is read again (the --after-only above): with it"\n'
        '    echo "                    in force, the uCRM catalogue screen\'s steps (docs/72 §G.3), then --after-only once the switch"\n'
        '    echo "                    is on (QC-ON1–QC-ON4); with it 0/off, nothing"', why="F screens and steps")
    doc.sub('    [ "$F_QST" != "stageA" ] || echo "  quoting org.      #$F_QORG in force as stage A read it (Q-G3) — $F_QNR: the screens and your steps below are said on that reading"\n',
            '    [ "$F_QST" != "stageA" ] || echo "  quoting org.      #$F_QORG in force as stage A read it (Q-G3) — $F_QNR: the screens and your steps below are said on that reading"\n')
    # the printed rollback, last
    doc.lines_between("# one. Then the rollback, last, in its own block: one step — 5.18.101 put back; ECO Bank and its switch stay as they are.",
                      "# 5.18.102 could not confirm a quotation for may already have one in uCRM; the rollback counts both (QL-RB3).",
        "# one. Then the rollback, last, in its own block: one step — 5.18.102 put back; ECO Bank and its switch stay as they are.\n"
        "# 5.18.103 (docs/72 §G.10): the block says what going back means on Uganda — the uCRM catalogue gone, the plugin's lists\n"
        "# priced by their links again, the web-direct applications never quoted by 5.18.102 (each quoted in uCRM by hand; the\n"
        "# rollback counts them, QC-RB2), nothing quoted at price 0, the catalogue's settings left in the store (QC-RB1). It needs no\n"
        "# explicit choice: going back to 5.18.102 has no refusal of its own. The by-hand put-back counts nothing, and says so.",
        why="F rollback comment")
    doc.lines_between('  echo "  and before that it counts the applications still waiting for uCRM with no plan price recorded — as"',
                      '  echo "  counts nothing: an application still waiting for uCRM with no plan price recorded is then quoted by"',
        '  echo "  On Uganda, with the quoting organization in force, the uCRM catalogue screen then goes, the Plans and Hardware"\n'
        '  echo "  screens come back with their uCRM link pickers, and the KYC form, Send Quotation and the lead quotes offer the"\n'
        '  echo "  plugin\'s own plans and kits again, priced through the uCRM item linked to each — one not linked is unpriced. A"\n'
        '  echo "  web-direct application stays as written and is never quoted by $BASELINE_VERSION (P1, or P2 where cash was"\n'
        '  echo "  collected): an admin quotes each in uCRM by hand, after checking the customer has none — the rollback counts them"\n'
        '  echo "  and puts their ids on its sheet (QC-RB2). Nothing is quoted at price 0. The uCRM catalogue\'s settings stay in the"\n'
        '  echo "  store, read by nothing under $BASELINE_VERSION (QC-RB1); a later deploy of $EXPECTED_VERSION finds them as they"\n'
        '  echo "  were — with the switch left on, direct mode comes back the moment its copy lands. Quotes already made in uCRM"\n'
        '  echo "  are not changed, and the $N_ADDED files $EXPECTED_VERSION adds stay on disk, reached by nothing."\n'
        '  echo "  The summary\'s sign-off, the card\'s owner lists, every number and its owner, the quoting organization, the"\n'
        '  echo "  Cashbook, cash in hand, ECO Bank, its switch, 088\'s tables and every statement kept stay exactly as they are;"\n'
        '  echo "  5.18.95\'s security fixes, 5.18.94\'s documents and every other switch stay as they are. Then run the quarantine\'s"\n'
        '  echo "  own --check."\n'
        '  echo "      $ROLLBACK_CMD"\n'
        '  echo\n'
        '  echo "  Only if that script cannot run — the same put-back by hand, its own command, alone (never with the one above). It"\n'
        '  echo "  counts nothing: the web-direct applications are then left unquoted, as above, and not counted:"',
        why="F rollback block")
    doc.sub('  echo "  $BASELINE_VERSION\'s cron at price 0 and WhatsApped as above:"\n', "")
    # RB's count of the added files on disk, and its last note
    doc.sub("QLRB1_LEFT", "ADDED_LEFT", 6)
    doc.line('note "RB the code is $BASELINE_VERSION again. The rollback restores code only. $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds are on disk',
             'note "RB the code is $BASELINE_VERSION again. The rollback restores code only. $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds are on disk (deploy-hybrid.sh never deletes; counted above) — lib/UcrmCatalogue.php, the uCRM catalogue\'s settings, tabs/sales/ucrm_catalogue.php, its screen, and their tests, which never ran on the server: $(printf \'%s \' $EXPECTED_ADDED); $BASELINE_VERSION reads none of them (QC-RB1), nor the last good copy of the catalogue $EXPECTED_VERSION keeps beside the page cache, which stays in the data folder; the page cache itself is $EXPECTED_VERSION\'s shape, a miss for $BASELINE_VERSION, which draws it again. On Uganda, where the quoting organization is in force, the uCRM catalogue screen is gone, the Plans and Hardware screens are back with their uCRM link pickers, and the KYC form, Send Quotation and the lead quotes offer the plugin\'s own plans and kits again, priced through the uCRM item linked to each. The web-direct applications stay as written and are never quoted by $BASELINE_VERSION — ${QCRB_SAY:-not counted} (QC-RB2): an admin quotes each in uCRM by hand, after checking the customer has none; nothing is quoted at price 0. The uCRM catalogue\'s settings stay in the store (QC-RB1); a later deploy of $EXPECTED_VERSION finds them as they were. Quotes already made in uCRM are not changed. A number given to a technician keeps its owner (RB above). ECO Bank stays as it was: its switch, its screen, its links, 088\'s tables and every statement kept (RB above). 087\'s tables stay, with every row and trail entry the operator\'s card wrote — the rollback writes none — and so does the store\'s wa_evo_numbers, if a verification made it; $(sw_both_on "${MN_AFTER:-}" && echo "the registry is on, as the operator left it, and $BASELINE_VERSION reads them exactly as $EXPECTED_VERSION did (RB above)" || echo "the registry\'s switch reads ${MN_AFTER:-?} (files/store), and with it off $BASELINE_VERSION\'s registry does not read them (RB above)"). Leads, conversations, events, consents and authorisation records stay as they are; so does the quarantine, which this run never touches"',
             why="RB last note")
    doc.mustnot("QLRB"); doc.mustnot("QLV1"); doc.mustnot("QL_UNP"); doc.mustnot("--rollback-unpriced-pending)")
