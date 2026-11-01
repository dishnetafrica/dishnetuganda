# Part G — QL-N3 for a stage A that reads 5.18.102 live (root docs/72 §G.13). Under 5.18.102 its own mark
# ('ucrm-5.18.102', UcrmQuoteLines::SOURCE in both releases) and quote_sale_entry 'web' are written by the live code, so
# neither reads as "an earlier run, rolled back" any more; 5.18.103's own mark ('ucrm-direct-5.18.103', DIRECT_SOURCE) is
# counted apart; and the retry 5.18.103 makes (KycService::retryLines at the pin) prices a 'web-direct' application by its
# uCRM ids, so the D11 count — the ones no link and no uCRM item can price — leaves out 'web' and 'web-direct' alike.


def apply(doc, ctx):
    # the probe (inside QO_PHP: length kept small — the argument limit)
    doc.sub('        // quote_sale_entry is exactly \'web\', which no application saved before the deploy carries), before marked lines\n',
            '        // quote_sale_entry is exactly \'web\', which 5.18.102\'s KYC form writes; \'web-direct\' is retried by its uCRM ids), before marked lines\n',
            why="G probe comment: web")
    doc.sub('        // QL-N3 — Q-N4 extended: the pending and review applications by line shape — 5.18.102\'s mark (quote_lines_source\n'
            '        // \'ucrm-5.18.102\') or not;',
            '        // QL-N3 — Q-N4 extended: the pending and review applications by line shape — 5.18.102\'s mark (quote_lines_source\n'
            '        // \'ucrm-5.18.102\'), 5.18.103\'s (\'ucrm-direct-5.18.103\', "direct") or neither;', why="G probe comment: marks")
    doc.sub('        // are confirmed and before the Fiber check — so every one with no quotation made and quote_sale_entry not exactly\n'
            '        // \'web\', marked or not,',
            '        // are confirmed and before the Fiber check — so every one with no quotation made and quote_sale_entry neither\n'
            '        // \'web\' nor \'web-direct\', marked or not,', why="G probe comment: D11")
    doc.sub('$ql_c = $ql_st . "." . (($r["quote_lines_source"] ?? null) === "ucrm-5.18.102" ? "marked" : "unmarked") . "."',
            '$ql_m = $r["quote_lines_source"] ?? null; $ql_c = $ql_st . "." . ($ql_m === "ucrm-5.18.102" ? "marked" : ($ql_m === "ucrm-direct-5.18.103" ? "direct" : "unmarked")) . "."',
            why="G probe: three marks")
    doc.sub('if (!empty($r["quote_created"]) || ($r["quote_sale_entry"] ?? null) === "web") continue;',
            'if (!empty($r["quote_created"]) || in_array($r["quote_sale_entry"] ?? null, ["web", "web-direct"], true)) continue;',
            why="G probe: D11 leaves out web-direct")
    # ql_n3_say
    doc.sub('printf "%d (marked by 5.18.102 %d, not %d; lines with a productId %d, without one %d, none %d; Cash %d, Credit %d)", t, m["marked"], m["unmarked"],',
            'printf "%d (marked by 5.18.102 %d, by 5.18.103 %d, neither %d; lines with a productId %d, without one %d, none %d; Cash %d, Credit %d)", t, m["marked"], m["direct"], m["unmarked"],',
            why="G ql_n3_say")
    # ql_d11_say
    doc.sub('  # rule, every one with no quotation made whose quote_sale_entry is not exactly web (none saved before the deploy is), by\n',
            '  # rule, every one with no quotation made whose quote_sale_entry is neither web (5.18.102\'s form) nor web-direct, by\n',
            why="G ql_d11_say comment")
    doc.sub("the retry makes no quotation for %s of them (D11, docs/71 §F.4; E-1): every one with no quotation made — pending %s,",
            "the retry makes no quotation for %s of them (D11, docs/71 §F.4; E-1): every one with no quotation made that is neither a web registration nor a direct sale — pending %s,",
            why="G ql_d11_say text")
    # the note
    doc.sub("  # the release's entry rule comes first, so every one with no quotation made and quote_sale_entry not exactly web —\n"
            "  # every one saved before the deploy — the probe's qln3d11, kept in QLN3_D11_A for the summary); with none in force\n",
            "  # the release's entry rule comes first, so every one with no quotation made and quote_sale_entry neither web nor\n"
            "  # web-direct — the probe's qln3d11, kept in QLN3_D11_A); since 5.18.103 (docs/72 §G.13) 5.18.102's mark is the live\n"
            "  # code's and 5.18.103's own is counted apart, an earlier run's, rolled back; with none in force\n",
            why="G note comment")
    old_y = ('''      y="pending $(ql_n3_say "$q" pending); review $(ql_n3_say "$q" review) — $( [ "$x" = "0" ] && echo "none carries $EXPECTED_VERSION's mark (quote_lines_source): the expected reading before the deploy, $BASELINE_VERSION writing none" || echo "$x carry $EXPECTED_VERSION's mark (quote_lines_source): written by an earlier $EXPECTED_VERSION here, rolled back since")"''')
    new_y = ('''      z="$(printf '%s' "$q" | tr ',' '\\n' | awk -F'[.:]' '$2 == "direct" { m += $5 } END { print m + 0 }')"
      y="pending $(ql_n3_say "$q" pending); review $(ql_n3_say "$q" review) — $x carry $BASELINE_VERSION's mark (quote_lines_source 'ucrm-5.18.102': priced at its KYC form through a link; under D6 nothing is linked, docs/72 G-10); $( [ "$z" = "0" ] && echo "none carries $EXPECTED_VERSION's own (ucrm-direct-5.18.103): the expected reading before the deploy" || echo "$z carry $EXPECTED_VERSION's own (ucrm-direct-5.18.103): written by an earlier $EXPECTED_VERSION here, rolled back since")"''')
    doc.sub(old_y, new_y, why="G note: two marks")
    doc.sub("After the deploy their retry takes none of them from uCRM (docs/71 E-1, §R.4.2): the entry rule comes first, and none saved before the deploy is a web registration of $EXPECTED_VERSION's form. $(ql_d11_say \"$QLN3_D11_A\")",
            "After the deploy their retry is $BASELINE_VERSION's for a web registration of its form (quote_sale_entry web: through the links; with the uCRM catalogue's switch on, one unpriced is quoted in uCRM by hand, QC-N4), by its uCRM ids for a direct sale an earlier $EXPECTED_VERSION wrote (web-direct), and from uCRM for none of the others: the entry rule comes first (docs/71 E-1, §R.4.2). $(ql_d11_say \"$QLN3_D11_A\")",
            why="G note: the retry")
    doc.sub("  local c r p t d u x a q why y lk n cp ct\n", "  local c r p t d u x a q why y lk n cp ct z\n", why="G local z")
    doc.must('in_array($r["quote_sale_entry"] ?? null, ["web", "web-direct"], true)', 1)
    doc.mustnot("none saved before the deploy is a web registration")
    doc.mustnot("which no application saved before the deploy carries")
