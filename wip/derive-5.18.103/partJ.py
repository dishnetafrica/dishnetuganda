# Part J — the derived script's adversarial review (two reviewers, 1 Nov): six confirmed defects, each fixed here.
#  J1 (major) QC-RB2's application ids reached no sheet in a --rollback (no sheet open yet), while three places said they
#     were on it: qcrb_count opens the sheet itself and says where the ids went (QC_RB_IDS).
#  J2 QC-ON4 read G-7 without quote_sale_cart (5.18.102's saleFromApp reads it first) and took an undecodable cart as fine.
#  J3 with no quoting organization in force, QC-ON1–QC-ON3 failed every --after-only and QC-N1 said "could not be read".
#  J4 QC-CA2's text read and QC-CA3 need no HTTP, yet sat inside the reachable public address's branch, unnamed when skipped.
#  J5 QC-N5 matched stock titles by its own rule: the deduction looks up QuoteOrganization::plain(name) by LOWER(title).
#  J6 QC-N6 compared an offered plan's product name; the website hides a plan by its service plan's name.


def apply(doc, ctx):
    # ── J1 ──
    doc.sub("# review:<n>,failed:<n>,synced:<n>\") and puts their ids on the run's sheet under WHEN, or sets QC_RB_ERR. A note, never a\n",
            "# review:<n>,failed:<n>,synced:<n>\") and puts their ids on the run's sheet under WHEN — opening it when none is open yet,\n"
            "# as in a --rollback's stage A — saying where they went in QC_RB_IDS; or sets QC_RB_ERR. A note, never a\n", why="J1 comment")
    doc.sub('QC_RB=""; QC_RB_ERR=""\nqcrb_count() {\n', 'QC_RB=""; QC_RB_ERR=""; QC_RB_IDS=""\nqcrb_count() {\n', why="J1 var")
    doc.sub("  QC_RB=\"\"; QC_RB_ERR=\"\"\n  [ -n \"$DB_OWNER\" ] || { QC_RB_ERR=\"no owner of plugin.sqlite3 was found\"; return 1; }\n",
            "  QC_RB=\"\"; QC_RB_ERR=\"\"; QC_RB_IDS=\"ids not kept: the sheet could not be made\"\n  [ -n \"$DB_OWNER\" ] || { QC_RB_ERR=\"no owner of plugin.sqlite3 was found\"; return 1; }\n",
            why="J1 init")
    doc.sub("  if [ -n \"$SHEET\" ]; then { printf '\\n== QC-RB2 the web-direct applications with no quotation — %s (application ids: never paste) ==\\n  ' \"${when:-counted on a copy}\"; dxo cat \"$d/qcrb-ids.txt\" || echo \"(not read)\"; } >> \"$SHEET\" 2>/dev/null || true; fi\n",
            "  if sheet_open; then { printf '\\n== QC-RB2 the web-direct applications with no quotation — %s (application ids: never paste) ==\\n  ' \"${when:-counted on a copy}\"; dxo cat \"$d/qcrb-ids.txt\" || echo \"(not read)\"; } >> \"$SHEET\" 2>/dev/null && QC_RB_IDS=\"ids on the sheet\"; fi\n",
            why="J1 sheet")
    doc.sub("after it each with no quotation is quoted in uCRM by hand; ids on the sheet\"",
            "after it each with no quotation is quoted in uCRM by hand; $QC_RB_IDS\"", why="J1 stage A note")
    doc.sub("an admin quotes each in uCRM by hand, after checking the customer has none (ids on the sheet)\"; fi",
            "an admin quotes each in uCRM by hand, after checking the customer has none ($QC_RB_IDS)\"; fi", why="J1 RB note")
    doc.sub("— each with no quotation is quoted in uCRM by hand (ids on the sheet)\"\n",
            "— each with no quotation is quoted in uCRM by hand ($QC_RB_IDS)\"\n", why="J1 V1 note")
    # ── J2 ──
    doc.sub('                $qcD++; $cart = $r["hw_cart_json"] ?? null; if (is_string($cart)) $cart = json_decode($cart, true);\n'
            '                $cartOk = $cart === null || (is_array($cart) && array_reduce($cart, function ($ok, $c) { return $ok && is_array($c) && ($c["id"] ?? null) === 0; }, true));\n',
            '                $qcD++; $cartOk = true;   // G-7: hw_cart_json and quote_sale_cart (5.18.102 reads it first) — absent, or id 0 rows only\n'
            '                foreach (["hw_cart_json", "quote_sale_cart"] as $f) { $cart = $r[$f] ?? null; if (is_string($cart)) { $cart = json_decode($cart, true); if (!is_array($cart)) $cartOk = false; }\n'
            '                    if ($cart !== null && !(is_array($cart) && array_reduce($cart, function ($ok, $c) { return $ok && is_array($c) && ($c["id"] ?? null) === 0; }, true))) $cartOk = false; }\n',
            why="J2 probe")
    doc.sub('web-direct applications that break G-7 (package_choice, device_id or a cart id that is not 0): #"',
            'web-direct applications that break G-7 (package_choice, device_id, or in hw_cart_json or quote_sale_cart an id that is not 0 or a cart that does not read): #"',
            why="J2 sheet")
    doc.sub("keeps G-7: package_choice and device_id empty, and only id 0 in its cart — nothing 5.18.102 would match",
            "keeps G-7: package_choice and device_id empty, and only id 0 in hw_cart_json and in quote_sale_cart, if any — nothing 5.18.102 would match",
            why="J2 ok")
    doc.sub("break G-7 (package_choice or device_id set, or a cart id that is not 0) — ids on the sheet.",
            "break G-7 (package_choice or device_id set, or in hw_cart_json or quote_sale_cart an id that is not 0 or a cart that does not read) — ids on the sheet.",
            why="J2 bad")
    doc.sub("# web-direct records — package_choice '', device_id '' and only id 0 in the cart. Reports; never stops.",
            "# web-direct records — package_choice '', device_id '' and only id 0 in hw_cart_json and quote_sale_cart; with no quoting\n"
            "# organization in force QC-ON1–QC-ON3 are one note (direct mode lives under it). Reports; never stops.", why="J2/J3 comment")
    # ── J3 ──
    doc.sub("  # QC-ON1–QC-ON4 — an --after-only run, once an admin has turned the switch on (docs/72 §G.13).\n"
            "  x=\"$( [ -n \"$ok_r\" ] && kv \"$QO_L\" qcsw)\"\n",
            "  # QC-ON1–QC-ON4 — an --after-only run, once an admin has turned the switch on (docs/72 §G.13). With no quoting\n"
            "  # organization in force the switch, the installation and the standard quotations are not asked (D7): one note.\n"
            "  if [ -n \"$ok_r\" ] && [ \"$(kv \"$QO_R\" qcc)\" = \"off\" ]; then\n"
            "    note \"QC-ON1–QC-ON3 not asked: no quoting organization is in force (Q-CA2) — the uCRM catalogue lives under it, so $EXPECTED_VERSION quotes as $BASELINE_VERSION does with it off (D7), and its screen is not shown\"\n"
            "  else\n"
            "  x=\"$( [ -n \"$ok_r\" ] && kv \"$QO_L\" qcsw)\"\n", why="J3 after open")
    doc.sub("  esac\n  g=\"$( [ -n \"$ok_r\" ] && kv \"$QO_L\" qcg7)\"\n",
            "  esac\n  fi\n  g=\"$( [ -n \"$ok_r\" ] && kv \"$QO_L\" qcg7)\"\n", why="J3 after close")
    doc.sub("  # then agents see direct mode the moment the copy lands — said in capitals.\n  case \"$QCSW_A\" in\n",
            "  # then agents see direct mode the moment the copy lands — said in capitals. With no quoting organization in force\n"
            "  # the switch is not read (it lives under it).\n"
            "  if [ \"$c\" = \"off\" ]; then note \"QC-N1 the uCRM catalogue's switch not read: no quoting organization is in force (Q-G3) — the switch lives under it\"\n"
            "  else\n  case \"$QCSW_A\" in\n", why="J3 stage open")
    doc.sub("    *) note \"QC-N1 the uCRM catalogue's switch could not be read on the copy (${QCSW_A:-not read}) — said, never a reason to stop\" ;;\n  esac\n",
            "    *) note \"QC-N1 the uCRM catalogue's switch could not be read on the copy (${QCSW_A:-not read}) — said, never a reason to stop\" ;;\n  esac\n  fi\n",
            why="J3 stage close")
    # ── J4 ──
    t = doc.t
    s = t.index('      QCCA2_P="$(qc_ca2_txt pin)"; QCCA2_I="$(qc_ca2_txt inst)"\n')
    e = t.index('read as text beside the pin\'s. Send the log file"; fi\n', s) + len('read as text beside the pin\'s. Send the log file"; fi\n')
    block = t[s:e]
    assert t.count(block) == 1 and block.count("\n") == 8, "J4 block"
    doc.sub(block, "", why="J4 out of the HTTP branch")
    moved = "".join(l[4:] + "\n" for l in block.rstrip("\n").split("\n"))
    assert all(l.startswith("  ") for l in moved.rstrip("\n").split("\n")), "J4 indent"
    anchor = "skipped — the plugin's public URL could not be derived (re-run with --plugin-base); the R checks below read the install directly\"; fi\n"
    doc.sub(anchor, anchor +
            "# QC-CA2's text read and QC-CA3 need no HTTP: they run whether or not the public address answered (5.18.103's review).\n"
            "if [ \"$MODE\" != \"rollback\" ]; then\n" + moved + "fi\n", why="J4 after V")
    doc.sub("      # write action. Then the installed includes/post/post_sync.php as text (qc_ca2_txt): the block of the five actions\n",
            "      # write action. Its text read follows V, below, needing no HTTP: the installed includes/post/post_sync.php (qc_ca2_txt), the block of the five actions\n",
            why="J4 comment")
    doc.sub("      # check refuses rather than passes. QC-CA3: Send Quotation and the lead quotes re-price each picked uCRM line inside\n"
            "      # QuotationService (qc_ca3_txt), the installed files as text beside the pin's.\n",
            "      # check refuses rather than passes. QC-CA3, also after V: Send Quotation and the lead quotes re-price each picked uCRM\n"
            "      # line inside QuotationService (qc_ca3_txt), the installed files as text beside the pin's.\n", why="J4 comment 2")
    # ── J5 ──
    doc.sub('foreach ($pdo->query("SELECT title FROM stock_categories WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $t) $offer["stock"][] = strtolower(trim((string)$t));',
            'foreach ($pdo->query("SELECT title FROM stock_categories WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $t) $offer["stock"][] = strtolower((string)$t);',
            why="J5 local")
    doc.sub("        // active stock categories' titles and public_price_exclude's names (to the offer file, lowercased and trimmed:\n"
            "        // QC-N5 and QC-N6 compare them in the remote reading).",
            "        // active stock categories' titles (lowercased, as StockService's LOWER(title)) and public_price_exclude's names\n"
            "        // (lowercased and trimmed, as PublicPriceFeed) to the offer file: QC-N5 and QC-N6 compare them in the remote reading.",
            why="J5 comment")
    doc.sub('                            $lab = strtolower(trim((string)$r["label"]));\n',
            '                            $lab = strtolower(trim((string)$r["label"])); $stk = strtolower(QuoteOrganization::plain((string)$r["label"]));   // the cart title a deduction looks up\n',
            why="J5 key")
    doc.sub('. (isset($qcStock[$lab]) ? "" : " — no active stock category of this title: a completed job deducts nothing for it (docs/72 §G.7)");\n'
            '                            if (!isset($qcStock[$lab])) $nStock++;\n',
            '. (isset($qcStock[$stk]) ? "" : " — no active stock category of this title: a completed job deducts nothing for it (docs/72 §G.7)");\n'
            '                            if (!isset($qcStock[$stk])) $nStock++;\n', why="J5 compare")
    # ── J6 ──
    doc.sub('foreach ($off["plans"] as $r) if (isset($qcPpx[strtolower(trim((string)$r["label"]))])) { $nPpx++; $S[] = "    public_price_exclude names the offered plan " . (string)$r["label"]; }',
            'foreach ($off["plans"] as $r) if (isset($qcPpx[strtolower(trim((string)($r["name"] ?? "")))])) { $nPpx++; $S[] = "    public_price_exclude names the offered plan\'s service plan " . (string)($r["name"] ?? ""); }',
            why="J6 plans")
    # ── J7 — QC-G1's settings refusals reachable (review): the local reading's ?nocat/?created/?<throw> leave the
    # catalogue null, so the remote reading answers nooffer and the plug:* branch never sees them; name them in *) instead.
    doc.sub('      case "$lt" in\n        absent|records:*) ;;\n        \'?nocat\') stop "NO-GO (QC-G1): the quoting organization is in force, and the RELEASE\'s lib/UcrmCatalogue.php was not where the local reading loads it, the pin\'s own file by its path (?nocat) — the catalogue\'s settings on the copy cannot be read. No flag lifts it. Nothing was changed. Send the log file" ;;\n        \'?created\') stop "NO-GO (QC-G1): reading the uCRM catalogue\'s settings on the copy created their table (?created) — the release\'s reader never does: the pin\'s code is not the one reviewed. No flag lifts it. Nothing was changed. Send the log file" ;;\n        *) stop "NO-GO (QC-G1): the uCRM catalogue\'s settings on the copy could not be read through the pin\'s UcrmCatalogue (${lt:-not read}). No flag lifts it. Nothing was changed. Send the log file" ;;\n      esac\n',
            "      # The settings' own refusals (?nocat, ?created, a reading that threw) are in the *) branch below: a local reading other\n      # than absent or records leaves the remote reading without them (qcc=nooffer), so they never reach this branch.\n", why="J7 dead branches out")
    doc.sub('    *) stop "NO-GO (QC-G1): what the forms would offer could not be read on the copy (${c:-not read}$(case "$c" in nolib) echo ": the pin\'s code has no lib/UcrmQuoteLines.php or no lib/UcrmCatalogue.php" ;; nooffer) echo ": the copy\'s catalogue settings, stock categories or public_price_exclude were not read" ;; \'?\'*) echo ": the reading threw" ;; esac)). No flag lifts it. Nothing was changed. Send the log file" ;;\n',
            '    *) if [ "$c" = "nooffer" ]; then case "$lt" in\n           \'?nocat\') stop "NO-GO (QC-G1): the quoting organization is in force, and the RELEASE\'s lib/UcrmCatalogue.php was not where the local reading loads it, the pin\'s own file by its path (?nocat) — the catalogue\'s settings on the copy cannot be read. No flag lifts it. Nothing was changed. Send the log file" ;;\n           \'?created\') stop "NO-GO (QC-G1): reading the uCRM catalogue\'s settings on the copy created their table (?created) — the release\'s reader never does: the pin\'s code is not the one reviewed. No flag lifts it. Nothing was changed. Send the log file" ;;\n           \'?\'*) stop "NO-GO (QC-G1): the uCRM catalogue\'s settings on the copy could not be read through the pin\'s UcrmCatalogue (${lt:-not read}). No flag lifts it. Nothing was changed. Send the log file" ;;\n         esac; fi\n       stop "NO-GO (QC-G1): what the forms would offer could not be read on the copy (${c:-not read}$(case "$c" in nolib) echo ": the pin\'s code has no lib/UcrmQuoteLines.php or no lib/UcrmCatalogue.php" ;; nooffer) echo ": the copy\'s catalogue settings, stock categories or public_price_exclude were not read" ;; \'?\'*) echo ": the reading threw" ;; esac)). No flag lifts it. Nothing was changed. Send the log file" ;;\n', why="J7 named in *)")
    doc.must("'?nocat') stop \"NO-GO (QC-G1): the quoting organization is in force", 1)
    doc.must('    *) if [ "$c" = "nooffer" ]; then case "$lt" in\n', 1)
    doc.must("QC_RB_IDS", 7)
    doc.mustnot("(ids on the sheet)\"")
    doc.must('if [ "$MODE" != "rollback" ]; then\n  QCCA2_P="$(qc_ca2_txt pin)"', 1)
