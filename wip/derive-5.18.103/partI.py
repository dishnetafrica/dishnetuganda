# Part I — QL-G1's words in 5.18.103's world (root docs/72 §G.13, D6, D7): with the uCRM catalogue off, as the deploy
# leaves it, a kind uCRM offers nothing linkable for stays unpriced (5.18.102's rule, carried); the operator's next step is
# the uCRM catalogue screen and its switch, not a link per item; and QL-LK1, retired, no longer reads --accept-unpriced.


def apply(doc, ctx):
    doc.sub("passes the release's own rules over the catalogue just read (docs/71 §R.3.1 rules 4–7) — so after the deploy every item of that kind stays unpriced, whatever an admin links: a Cash sale of one is refused, and a Credit one registered with no quotation (P1), the agent told. Prepare uCRM as docs/71 §M step 3 lists; then, once this run has printed its last line, link each item on the Plans or Hardware screen (Uganda), as an admin (step 7), and run --after-only (step 8).",
            "passes the release's own rules over the catalogue just read (docs/71 §R.3.1 rules 4–7) — so after the deploy, while the uCRM catalogue is off (as the deploy leaves it), every item of that kind stays unpriced, whatever an admin links: a Cash sale of one is refused, and a Credit one registered with no quotation (P1), the agent told. Once this run has printed its last line, follow the summary's steps: on the uCRM catalogue screen an admin checks what it offers and turns it on — then the forms offer uCRM's own items and no link decides anything (docs/72 §G.1, G-10) — and --after-only follows.",
            why="I QL-G1 accept-unpriced note")
    doc.sub("passes the release's own rules over the catalogue just read (docs/71 §R.3.1 rules 4–7) — so after the deploy every item of that kind would stay unpriced, whatever an admin links: a Cash sale of one refused, a Credit one registered with no quotation (P1).",
            "passes the release's own rules over the catalogue just read (docs/71 §R.3.1 rules 4–7) — so after the deploy, while the uCRM catalogue is off (as the deploy leaves it), every item of that kind would stay unpriced, whatever an admin links: a Cash sale of one refused, a Credit one registered with no quotation (P1); QC-G1's sheet says what the forms would offer once it is on.",
            why="I QL-G1 stop")
    doc.sub("      # 5.18.102 D14 (one check SS-1; docs/71 §R.7.7 item 9): --accept-unpriced typed with a deploy is QL-G1's, and this\n"
            "      # run makes no deploy and runs no QL-G1. Left set, it would decide QL-LK1 here, a choice the operator never made for\n"
            "      # it — so it is cleared, and said, and QL-LK1 judges as in an --after-only typed without it (as V3j and BA2 never\n"
            "      # read a typed --without-bank-balance, only what decided G3). Items left unlinked on purpose: --after-only --accept-unpriced.\n",
            "      # 5.18.102 D14 (one check SS-1; docs/71 §R.7.7 item 9): --accept-unpriced typed with a deploy is QL-G1's, and this\n"
            "      # run makes no deploy and runs no QL-G1 — so it is cleared, and said (as V3j and BA2 never read a typed\n"
            "      # --without-bank-balance, only what decided G3). Since 5.18.103 no check after the copy reads it (QL-LK1 retired,\n"
            "      # docs/72 §G.13), and --after-only refuses it.\n",
            why="I already-serving comment")
    doc.sub('note "--accept-unpriced was typed with a deploy, for QL-G1, and this run makes no deploy: it decides nothing here — QL-LK1 judges as in an --after-only without it; items left unlinked on purpose are accepted only by --after-only --accept-unpriced, a run of its own"',
            'note "--accept-unpriced was typed with a deploy, for QL-G1, and this run makes no deploy: it decides nothing here, and no check after the copy reads it"',
            why="I already-serving note")
    apply2(doc, ctx)
    doc.mustnot("QL-LK1 judges as in")
    doc.mustnot("link each item on the Plans or Hardware screen (Uganda), as an admin (step 7)")


def apply2(doc, ctx):
    # QO_PHP's header: what 5.18.103 adds and what it retired (the PHP's dead QL-CA1/QL-LK1 paths are left: nothing asks)
    doc.sub("# D14 derivation M-1), to its own file in this reading's folder alone — never printed.\nQO_PHP=\"$(cat <<'PHP'\n",
            "# D14 derivation M-1), to its own file in this reading's folder alone — never printed.\n"
            "# 5.18.103 (docs/72 §G.13): QL-CA1 and QL-LK1 are retired — no run hands recs=, appmaxout=, qla=, copyat= or\n"
            "# appmax= any more, and the paths that read them answer \"-\", left in place. \"local\" also reads the uCRM catalogue's\n"
            "# settings on the copy through the code root's UcrmCatalogue (cat=; QC-N1, QC-N2, QC-ON1–QC-ON3), and counts the\n"
            "# entries, the web registrations left unpriced and G-7 (QC-N3, QC-N4, QC-ON4) and the plan types, stock titles and\n"
            "# price-feed exclusions (QC-N5–QC-N7); \"remote\" also asks the code root's UcrmQuoteLines::offered() over those\n"
            "# settings, GET-only (QC-G1 at stage A, QC-CA1 after the copy), each offered item's keyed hash to qch= — never printed.\n"
            "QO_PHP=\"$(cat <<'PHP'\n", why="I QO_PHP header")
    doc.sub("  # — for the decisions alone: none or some (QL-G1's note, QL-CA1's, F).",
            "  # — for the decisions alone: none or some (QL-G1's note, F; QL-CA1's until 5.18.103).", why="I ql_unp comment")
