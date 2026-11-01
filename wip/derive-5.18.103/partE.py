# Part E — the rollback (root docs/72 §G.10, §G.13). 5.18.102's QL-RB1–QL-RB3 and the pending-unpriced refusal guarded a
# rollback to 5.18.101 (its plan line at offer_price ?? 0); going back to 5.18.102 has no such hazard — with its gate on
# it reads a direct record as 'app' (P1, or P2 where cash was collected) and never quotes it. They are retired, and with
# them --rollback-unpriced-pending. QC-RB1: nothing in 5.18.102 names UcrmCatalogue or ucrm_catalogue (the screen, the
# class, the table). QC-RB2: the web-direct applications with no quotation, counted, their ids on the sheet — a note.
import subprocess
from d103lib import git

NEEDLES = ("UcrmCatalogue", "ucrm_catalogue")


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    # ── QC-RB1's marks, read off Git with the reader's own rule (lines carrying the needle) ──
    marks = []
    for f in sorted(ctx["changed"]):
        for nd in NEEDLES:
            n = git(repo, "show", f"{pin}:{plugin}/{f}").decode("utf-8", "replace").split("\n")
            c = sum(1 for ln in n if nd in ln)
            if c:
                b = sum(1 for ln in git(repo, "show", f"{base}:{plugin}/{f}").decode("utf-8", "replace").split("\n") if nd in ln)
                assert b == 0, f"5.18.102's {f} names {nd}"
                marks.append(f"{f}|{nd}|{c}")
    hits = subprocess.run(["git", "-C", repo, "grep", "-l", "-e", NEEDLES[0], "-e", NEEDLES[1], base, "--", plugin], capture_output=True)
    assert hits.stdout.strip() == b"", "5.18.102 names a QC-RB1 needle somewhere"
    ctl = git(repo, "show", f"{pin}:{plugin}/lib/UcrmCatalogue.php").decode().split("\n")
    assert all(any(nd in ln for ln in ctl) for nd in NEEDLES), "the control names both"
    ctx["qcrb1_marks"] = marks

    # ── RBQ_PHP: QC-RB2 and the catalogue's table in place of 5.18.102's own counts ──
    doc.between('    $ql = " qlunp=? ql102=?";\n', '    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();\n',
        "    // 5.18.103 (docs/72 §G.10, §G.13; QC-RB2): the web-direct applications with no quotation made, by sync status —\n"
        "    // 5.18.102 reads each as 'app' (P1, or P2 where cash was collected) and never quotes it, so an admin quotes each in\n"
        "    // uCRM by hand. Their ids go to the ids file (the second argument, under /tmp) for the sheet, never to stdout.\n"
        "    $ql = \" qcrb2=?\";\n"
        "    if ($has(\"kyc_applications\")) {\n"
        "        $dw = \"json_extract(data, '$.quote_sale_entry') = 'web-direct' AND COALESCE(json_extract(data, '$.quote_created'), 0) IN (0, '', '0')\";\n"
        "        $dn = $n(\"SELECT COUNT(*) FROM kyc_applications WHERE json_extract(data, '$.quote_sale_entry') = 'web-direct'\");\n"
        "        $dq = $n(\"SELECT COUNT(*) FROM kyc_applications WHERE \" . $dw);\n"
        "        $by = []; foreach ([\"pending\", \"review\", \"failed\", \"synced\"] as $st) $by[$st] = $n(\"SELECT COUNT(*) FROM kyc_applications WHERE \" . $dw . \" AND json_extract(data, '$.crm_sync_status') = '\" . $st . \"'\");\n"
        "        $ql = \" qcrb2=direct:\" . $dn . \",unquoted:\" . $dq . \",pending:\" . $by[\"pending\"] . \",review:\" . $by[\"review\"] . \",failed:\" . $by[\"failed\"] . \",synced:\" . $by[\"synced\"];\n"
        "        $idf = (string)($argv[2] ?? \"\");\n"
        "        if ($idf !== \"\" && strpos($idf, \"/tmp/\") === 0) {\n"
        "            $ids = array_map(\"intval\", $p->query(\"SELECT json_extract(data, '$.id') FROM kyc_applications WHERE \" . $dw . \" ORDER BY json_extract(data, '$.id')\")->fetchAll(PDO::FETCH_COLUMN));\n"
        "            if (@file_put_contents($idf, ($ids ? \"#\" . implode(\", #\", $ids) : \"none\") . \"\\n\") === false) $ql .= \"/?ids\"; else @chmod($idf, 0600);\n"
        "        }\n"
        "    }\n", keep_ends=False, why="RBQ qlunp")
    # (between() with keep_ends=False dropped the end anchor: put it back after the block)
    doc.sub("            if (@file_put_contents($idf, ($ids ? \"#\" . implode(\", #\", $ids) : \"none\") . \"\\n\") === false) $ql .= \"/?ids\"; else @chmod($idf, 0600);\n"
            "        }\n"
            "    }\n",
            "            if (@file_put_contents($idf, ($ids ? \"#\" . implode(\", #\", $ids) : \"none\") . \"\\n\") === false) $ql .= \"/?ids\"; else @chmod($idf, 0600);\n"
            "        }\n"
            "    }\n"
            '    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();\n')
    doc.between("    // QL-RB3 (D14 §R.7.4) and §R.17.3's count — each its own \"?\" when it cannot be read, never the whole line's\n",
                '    echo "refused=", $ref < 0 ? "?" : $ref, " orgmissing=", $mis < 0 ? "?" : $mis, " key=", $u($w), "/", $u($v), $ql, $q3;\n',
        "    // QC-RB1 (5.18.103): the uCRM catalogue's table a rollback leaves — absent, or its records — read through this\n"
        "    // read-only connection, never load(), which would create it; its own \"?\" when it cannot be read\n"
        "    try {\n"
        "        $ct = $p->query(\"SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ucrm_catalogue'\")->fetchColumn() !== false;\n"
        "        $q3 = \" qcrb1t=\" . (!$ct ? \"absent\" : ($has(\"ucrm_catalogue\") ? \"records:\" . $n(\"SELECT COUNT(*) FROM ucrm_catalogue\") : \"?\"));\n"
        "    } catch (Throwable $e) { $q3 = \" qcrb1t=?\"; }\n", keep_ends=False, why="RBQ qlrb3")
    doc.sub('        $q3 = " qcrb1t=" . (!$ct ? "absent" : ($has("ucrm_catalogue") ? "records:" . $n("SELECT COUNT(*) FROM ucrm_catalogue") : "?"));\n'
            '    } catch (Throwable $e) { $q3 = " qcrb1t=?"; }\n',
            '        $q3 = " qcrb1t=" . (!$ct ? "absent" : ($has("ucrm_catalogue") ? "records:" . $n("SELECT COUNT(*) FROM ucrm_catalogue") : "?"));\n'
            '    } catch (Throwable $e) { $q3 = " qcrb1t=?"; }\n'
            '    echo "refused=", $ref < 0 ? "?" : $ref, " orgmissing=", $mis < 0 ? "?" : $mis, " key=", $u($w), "/", $u($v), $ql, $q3;\n')
    doc.must('$u($v), $ql, $q3;', 1); doc.must('else @chmod($idf, 0600);\n        }\n    }\n    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();\n    $s = is_string($row)', 1)
    doc.lines_between("# RBQ_PHP — after a rollback (RB) and, since 5.18.102, in stage A of one (QL-RB2): 5.18.99's quoting records (5.18.100",
                      "# folder: the live one, opened read-only (RB), or a folder holding a consistent copy (qlrb_count).",
        "# RBQ_PHP — after a rollback (RB) and in stage A of one: 5.18.99's quoting records (5.18.100 and 5.18.101 write none of\n"
        "# their own), READ-ONLY as the database's owner — applications with quote_refused, leads flagged quote_org_missing, and\n"
        "# whether ucrm_quote_organization_id is set in the configuration file and in the store row — and, since 5.18.103 (docs/72\n"
        "# §G.10, §G.13), \"qcrb2\", the web-direct applications with no quotation made, by sync status, which 5.18.102 leaves\n"
        "# unquoted (their ids to the ids file, its optional second argument under /tmp, for the sheet), and \"qcrb1t\", the uCRM\n"
        "# catalogue's table a rollback leaves — absent, or its records — read through this read-only connection, never load(),\n"
        "# which would create it. Counts and set/unset only on stdout. Its first argument is the data folder: the live one, opened\n"
        "# read-only (RB), or a folder holding a consistent copy (qcrb_count). (5.18.102's own counts — qlunp, ql102, qlrb3,\n"
        "# qlunconf — guarded a rollback to 5.18.101 and are retired with it.)", why="RBQ comment")
    doc.sub('rbq_state() { [ -n "$DB_OWNER" ] || { echo "?noowner"; return; }; docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$RBQ_PHP" "${1:-$PDD_IN}" 2>/dev/null </dev/null || echo "?"; }',
            'rbq_state() { [ -n "$DB_OWNER" ] || { echo "?noowner"; return; }; docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$RBQ_PHP" "${1:-$PDD_IN}" "${2:-}" 2>/dev/null </dev/null || echo "?"; }')

    # ── qlrb_count / qlrb_judge → qcrb_count (a note, never a refusal) ──
    doc.lines_between("# qlrb_count — QL-RB2, 5.18.102 (docs/71 §Q D-R2): the applications with no lines and no plan price recorded — as",
                      "# Every request this run made of uCRM, by method, summed over its probes (each probe counts its own, through its GET-only",
        "# qcrb_count [WHEN] — QC-RB2, 5.18.103 (docs/72 §G.10, §G.13): the web-direct applications with no quotation made, which\n"
        "# 5.18.102 leaves unquoted after a rollback (with its gate on it reads each as 'app': P1, or P2 where cash was collected,\n"
        "# and never quotes one) — READ-ONLY on a consistent copy of the book — VACUUM INTO as the database's owner (CIHV_PHP, the\n"
        "# backup's own way: its settings row cut to the book's keys), in a folder of mode 700 of this run's own in the container's\n"
        "# /tmp, removed at once (and by the trap, chk_cleanup) — through RBQ_PHP. Sets QC_RB (\"direct:<n>,unquoted:<n>,pending:<n>,\n"
        "# review:<n>,failed:<n>,synced:<n>\") and puts their ids on the run's sheet under WHEN, or sets QC_RB_ERR. A note, never a\n"
        "# refusal: going back to 5.18.102 quotes nothing at price 0 (§G.10). Never an id, a name or an amount in the log.\n"
        "QC_RB=\"\"; QC_RB_ERR=\"\"\n"
        "qcrb_count() {\n"
        "  local d size free o when=\"${1:-}\"\n"
        "  QC_RB=\"\"; QC_RB_ERR=\"\"\n"
        "  [ -n \"$DB_OWNER\" ] || { QC_RB_ERR=\"no owner of plugin.sqlite3 was found\"; return 1; }\n"
        "  size=\"$(db_state | sed -nE 's/^size=([0-9]+) .*/\\1/p')\"; free=\"$(dxo php -r 'echo (int)@disk_free_space($argv[1]);' /tmp)\"\n"
        "  case \"$size\" in ''|*[!0-9]*) QC_RB_ERR=\"the book's size could not be read\"; return 1 ;; esac\n"
        "  case \"$free\" in ''|*[!0-9]*) QC_RB_ERR=\"the space free in the container's /tmp could not be read\"; return 1 ;; esac\n"
        "  [ \"$free\" -ge $(( size + 67108864 )) ] || { QC_RB_ERR=\"the container's /tmp has $free bytes free; a copy of the book and its margin need $(( size + 67108864 ))\"; return 1; }\n"
        "  d=\"/tmp/dnb-$EXPECTED_VERSION-qcrb-$TS-$$-$RANDOM\"\n"
        "  dxo mkdir -m 700 \"$d\" || { QC_RB_ERR=\"a folder of its own could not be made in the container's /tmp\"; return 1; }\n"
        "  o=\"$(dxo php -d display_errors=stderr -r \"$CIHV_PHP\" \"$PDD_IN/plugin.sqlite3\" \"$d/plugin.sqlite3\" \"$BOOK_KEYS_CSV\")\"\n"
        "  case \"$o\" in \"copy=ok \"*\" side=no\") ;; *) cih_drop \"$d\"; QC_RB_ERR=\"the consistent copy could not be made (${o:-?})\"; return 1 ;; esac\n"
        "  o=\"$(rbq_state \"$d\" \"$d/qcrb-ids.txt\")\"\n"
        "  if [ -n \"$SHEET\" ]; then { printf '\\n== QC-RB2 the web-direct applications with no quotation — %s (application ids: never paste) ==\\n  ' \"${when:-counted on a copy}\"; dxo cat \"$d/qcrb-ids.txt\" || echo \"(not read)\"; } >> \"$SHEET\" 2>/dev/null || true; fi\n"
        "  cih_drop \"$d\"\n"
        "  QC_RB=\"$(kv \"$o\" qcrb2)\"\n"
        "  case \"$QC_RB\" in direct:[0-9]*,unquoted:[0-9]*,pending:[0-9]*,review:[0-9]*,failed:[0-9]*,synced:[0-9]*) return 0 ;; esac\n"
        "  QC_RB_ERR=\"the copy could not be counted (${o:-?})\"; QC_RB=\"\"\n"
        "  return 1\n"
        "}\n"
        "qcrb_say() { printf '%s web-direct application(s), %s with no quotation made (pending for uCRM %s, in review %s, failed %s, in uCRM with no quotation %s)' \"$(subkv \"$1\" direct)\" \"$(subkv \"$1\" unquoted)\" \"$(subkv \"$1\" pending)\" \"$(subkv \"$1\" review)\" \"$(subkv \"$1\" failed)\" \"$(subkv \"$1\" synced)\"; }\n"
        "# Every request this run made of uCRM, by method, summed over its probes (each probe counts its own, through its GET-only", why="qcrb_count")

    # ── the rollback's own words, before anything changes (stage A of --rollback) ──
    WHAT = ("after the rollback the code is $BASELINE_VERSION's again. On Uganda the uCRM catalogue screen goes, the Plans and "
            "Hardware screens come back with their uCRM link pickers, and the KYC form, Send Quotation and the lead quotes offer "
            "the plugin's own plans and kits again, priced through the uCRM item an admin linked to each — one not linked is "
            "unpriced, as under $BASELINE_VERSION before (docs/71 §R). A web-direct application $EXPECTED_VERSION made stays as "
            "written; $BASELINE_VERSION reads it as one from the app — P1, or P2 where cash was collected — so it is never quoted "
            "by the cron and its Orders card offers no Make the quotation: an admin quotes it in uCRM by hand, after checking the "
            "customer has none (QC-RB2 counts them; their ids are on the sheet). Nothing is quoted at price 0 — $BASELINE_VERSION "
            "keeps its gate on (docs/72 §G.10). A direct KYC form still open in a browser posts no plan of the plugin's, so "
            "$BASELINE_VERSION refuses it and writes nothing. The uCRM catalogue's settings — the switch, the installation "
            "product, the products kept off, the standard quotations — stay in the store (the table ucrm_catalogue), read by "
            "nothing under $BASELINE_VERSION (QC-RB1); a later deploy of $EXPECTED_VERSION finds them as they were, so with the "
            "switch left on agents see direct mode again the moment its copy lands (its QC-N1 says so). The uCRM links stay as "
            "they are: $EXPECTED_VERSION never writes them while the switch is on. Quotes already made in uCRM are not changed. "
            "The quotation summary's sign-off, the card's owner lists and every number, the quoting organization, the Cashbook, "
            "cash in hand and ECO Bank stay exactly as they are: $EXPECTED_VERSION changes none of them. A later deploy of "
            "$EXPECTED_VERSION goes with --redeploy. 5.18.95's security fixes stay; 5.18.94's documents too; master keeps 5.18.92's "
            "lock, main.php 5.18.93's change and the retry job 5.18.91's gate")
    doc.lines_between("  # QL-RB2 — 5.18.102 (docs/71 §Q D-R2), before anything changes: on Uganda 5.18.102 saves an application it cannot price",
                      '  note "after the rollback the code is $BASELINE_VERSION\'s again.',
        "  # QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), before anything changes: the web-direct applications with no quotation\n"
        "  # made, which $BASELINE_VERSION will leave unquoted, counted read-only on a consistent copy of the book (qcrb_count) —\n"
        "  # a note, never a refusal: going back quotes nothing at price 0. Their ids go to the sheet, to quote by hand.\n"
        "  if qcrb_count \"stage A, before the rollback\"; then note \"QC-RB2 before the rollback, on a copy of the book: $(qcrb_say \"$QC_RB\") — after it each with no quotation is quoted in uCRM by hand; ids on the sheet\"\n"
        "  else note \"QC-RB2 the web-direct applications with no quotation could not be counted on a copy before the rollback ($QC_RB_ERR) — said, never a reason to stop; RB counts again after it\"; fi\n"
        f"  note \"{WHAT}\"", why="rollback stage A")
    # ── as ROLLBACK is typed: no count to make again ──
    doc.lines_between("    # 5.18.102 (docs/71 §Q D-R2; review 1, S1): QL-RB2 counted again, here, before TOUCHED and the copy. Stage A's",
                      '    qlrb_judge "counted again as ROLLBACK was typed, right before the copy: "', "", why="ROLLBACK typed")
    # ── V1: the deploy's own automatic rollback ──
    doc.lines_between("      # 5.18.102 (docs/71 §Q D-R2; review 1, C1 and S2): the deploy's own automatic rollback never waits on QL-RB2 — the",
                      '        stop "the automatic rollback did not complete — it did not verify (live=$(live_commit)):',
        "      # 5.18.103 (docs/72 §G.10): the deploy's own automatic rollback — the public address must never redirect, and putting\n"
        "      # $BASELINE_VERSION back is what ends it — then QC-RB2 counted after it on a consistent copy of the book, read-only\n"
        "      # (qcrb_count; counts in the log, ids on the sheet): going back to $BASELINE_VERSION quotes nothing at price 0, so\n"
        "      # nothing here refuses. do_rollback's own answer decides the words: verified, no command is printed; not verified\n"
        "      # — the checkout, deploy-hybrid.sh, or a record that did not turn — $EXPECTED_VERSION may still serve, and the stop\n"
        "      # says the rollback did not complete and prints the by-hand put-back, alone on its own line.\n"
        "      if [ \"$MODE\" = \"deploy\" ] && [ \"${HTTP_REDIRECTS:-0}\" != \"0\" ]; then echo; echo \"  ROLLING BACK — the public address must never redirect\"; if do_rollback; then QCV1_RB=ok; else QCV1_RB=failed; fi\n"
        "        if qcrb_count \"after V1's automatic rollback$( [ \"$QCV1_RB\" = \"ok\" ] || echo \" attempt\")\"; then QCV1_SAY=\"QC-RB2 counted after it, on a copy of the book: $(qcrb_say \"$QC_RB\") — each with no quotation is quoted in uCRM by hand (ids on the sheet)\"\n"
        "        else QCV1_SAY=\"QC-RB2 could not count the web-direct applications with no quotation after it ($QC_RB_ERR)\"; fi\n"
        "        note \"$QCV1_SAY\"\n"
        "        if [ \"$QCV1_RB\" = \"ok\" ]; then stop \"rolled back (serves $(live_commit)); $QCV1_SAY; the uCRM catalogue's settings stay in the store, read by nothing under $BASELINE_VERSION; a later deploy of $EXPECTED_VERSION finds them as they were (docs/72 §G.10); send the log file\"; fi\n"
        "        stop \"the automatic rollback did not complete — it did not verify (live=$(live_commit)): $EXPECTED_VERSION may still serve, or some installed files may already be $BASELINE_VERSION's, and the public address may still redirect. $QCV1_SAY. $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand, with its own command — then send this log file and that command's log:$PUTBACK_SHOW\"; fi",
        why="V1 rollback")
    doc.mustnot("QLV1_")
    doc.lines_between("# 5.18.102 (docs/71 §Q D-R2; review 1, C1): the put-back counts nothing — no QL-RB2, no refusal — and every message",
                      'PUTBACK_HAZARD="The put-back below counts nothing before it copies:',
        "# 5.18.103 (docs/72 §G.10): the put-back counts nothing, and every message that prints it says what that leaves, first, in\n"
        "# one sentence; the command still stands alone on its own line after it.\n"
        "PUTBACK_HAZARD=\"The put-back below counts nothing before it copies: the web-direct applications $EXPECTED_VERSION made are then left unquoted under $BASELINE_VERSION (P1, or P2 where cash was collected) — an admin quotes each in uCRM by hand; nothing is quoted at price 0.\"",
        why="putback hazard")

    # ── RB: QC-RB1 in place of QL-RB1 and QL-RB3 ──
    marks = "\n".join(ctx["qcrb1_marks"]); nm = len(ctx["qcrb1_marks"])
    QCRB1 = (
        "# QC-RB1 — 5.18.103 (docs/72 §G.10, §G.13): nothing in 5.18.102 reaches what 5.18.103 adds and leaves: no installed file\n"
        "# names UcrmCatalogue (lib/UcrmCatalogue.php, the class) or ucrm_catalogue (the screen, ?tab=ucrm_catalogue, and the\n"
        "# table its settings live in). The files 5.18.103 adds stay on disk (deploy-hybrid.sh never deletes; counted above), and\n"
        "# so does the table (counted below), each reached by nothing. One reader for every file, the pin's and the installed\n"
        "# alike (qc_rb1_n: whole lines, as text, from its input — never from a variable, which would drop a binary file's NUL\n"
        "# bytes): (1) each file 5.18.103 changes that names one of them in the release, by the count pinned here — the pin's file\n"
        "# from Git must read exactly that count, the control: a reader, a path or a needle that could not see the release would\n"
        "# pass any installed file — and the installed one 0; (2) every file 5.18.102 tracks under the plugin (git ls-tree\n"
        "# $BASELINE_COMMIT, data/ aside), as installed, 0 for both, beside its control, the pin's lib/UcrmCatalogue.php, which\n"
        "# names both. A tracked file missing on disk fails, so the sweep never passes over a file it did not read. The counts are\n"
        "# the release commit's, read with this reader by the derivation (derive103.py), which also asserts 5.18.102 names\n"
        "# neither anywhere. Fixed words and counts only.\n"
        "QC_RB1_MARKS=\"$(cat <<'MARKS'\n" + marks + "\nMARKS\n)\"\n"
        "qc_rb1_n() { grep -cF -- \"$1\" 2>/dev/null || true; }   # qc_rb1_n NEEDLE <INPUT — the lines of INPUT carrying it, or nothing when INPUT could not be read: one reader, pin and installed\n"
        "QCRB1_BAD=\"\"; QCRB1_N=0; QCRB1_SW_N=0; QCRB1_SW_HIT=0; QCRB1_SW_MISS=0\n"
        "while IFS='|' read -r qr1_rel qr1_mark qr1_pin; do\n"
        "  [ -n \"$qr1_rel\" ] || continue\n"
        "  QCRB1_N=$((QCRB1_N + 1))\n"
        "  qr1_pn=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$qr1_rel\" 2>/dev/null | qc_rb1_n \"$qr1_mark\")\"\n"
        "  [ \"$qr1_pn\" = \"$qr1_pin\" ] || { QCRB1_BAD=\"$QCRB1_BAD $qr1_rel(the-control:the-pin-reads-$qr1_mark×${qr1_pn:-?}-not-$qr1_pin)\"; continue; }\n"
        "  [ -f \"$DEST/$qr1_rel\" ] || { QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:missing\"; continue; }\n"
        "  qr1_in=\"$(qc_rb1_n \"$qr1_mark\" <\"$DEST/$qr1_rel\")\"\n"
        "  [ \"$qr1_in\" = \"0\" ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:names-$qr1_mark×${qr1_in:-?}\"\n"
        "done <<< \"$QC_RB1_MARKS\"\n"
        f"[ \"$QCRB1_N\" = \"{nm}\" ] || QCRB1_BAD=\"$QCRB1_BAD count:$QCRB1_N/{nm}\"\n"
        "qr1_ctl=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/lib/UcrmCatalogue.php\" 2>/dev/null | qc_rb1_n UcrmCatalogue):$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/lib/UcrmCatalogue.php\" 2>/dev/null | qc_rb1_n ucrm_catalogue)\"\n"
        "case \"$qr1_ctl\" in\n"
        "  [1-9]*:[1-9]*)\n"
        "    while IFS= read -r -d '' qr1_rel; do\n"
        "      qr1_rel=\"${qr1_rel#\"$PLUGIN\"/}\"\n"
        "      case \"$qr1_rel\" in data/*) continue ;; esac\n"
        "      QCRB1_SW_N=$((QCRB1_SW_N + 1))\n"
        "      if [ ! -f \"$DEST/$qr1_rel\" ]; then QCRB1_SW_MISS=$((QCRB1_SW_MISS + 1)); [ \"$QCRB1_SW_MISS\" -gt 5 ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:missing\"; continue; fi\n"
        "      for qr1_mark in UcrmCatalogue ucrm_catalogue; do\n"
        "        qr1_in=\"$(qc_rb1_n \"$qr1_mark\" <\"$DEST/$qr1_rel\")\"\n"
        "        [ \"$qr1_in\" = \"0\" ] || { QCRB1_SW_HIT=$((QCRB1_SW_HIT + 1)); [ \"$QCRB1_SW_HIT\" -gt 5 ] || QCRB1_BAD=\"$QCRB1_BAD $qr1_rel:names-$qr1_mark×${qr1_in:-?}\"; }\n"
        "      done\n"
        "    done < <(git -C \"$REPO\" ls-tree -r -z --name-only \"$BASELINE_COMMIT\" -- \"$PLUGIN\" 2>/dev/null)\n"
        "    [ \"$QCRB1_SW_N\" -gt 0 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:no-file-of-$BASELINE_COMMIT-listed\"\n"
        "    [ \"$QCRB1_SW_HIT\" -le 5 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:$QCRB1_SW_HIT-readings-name-one-in-all\"\n"
        "    [ \"$QCRB1_SW_MISS\" -le 5 ] || QCRB1_BAD=\"$QCRB1_BAD sweep:$QCRB1_SW_MISS-files-missing-in-all\" ;;\n"
        "  *) QCRB1_BAD=\"$QCRB1_BAD sweep(the-control:the-pin-lib/UcrmCatalogue.php-reads-${qr1_ctl})\" ;;\n"
        "esac\n"
        "if [ -z \"$QCRB1_BAD\" ]; then ok \"QC-RB1 nothing in $BASELINE_VERSION reaches the uCRM catalogue $EXPECTED_VERSION leaves: none of the $QCRB1_N readings of the installed files $EXPECTED_VERSION changes that name UcrmCatalogue or ucrm_catalogue in it finds one now, and none of the $QCRB1_SW_N files $BASELINE_COMMIT tracks, as installed, names either — each read as text beside its control, the pin's files from Git, which name them exactly as pinned; $QLRB1_LEFT of the $N_ADDED files $EXPECTED_VERSION adds are on disk, reached by nothing\"\n"
        "else bad \"QC-RB1 $BASELINE_VERSION's installed code names the uCRM catalogue $EXPECTED_VERSION leaves, or its readers could not see the release:$QCRB1_BAD — the rollback leaves lib/UcrmCatalogue.php, the screen's file and the table in place, so nothing installed may name them. Send the log file\"; fi")
    doc.lines_between("# QL-RB1 — 5.18.102 (docs/71 §Q D-R1): the 31 files 5.18.102 adds stay on disk after the rollback (above), and nothing",
                      'else bad "QL-RB3 $BASELINE_VERSION\'s installed code names the uCRM link store $EXPECTED_VERSION leaves,',
                      QCRB1, why="QC-RB1")
    # ── RB: QC-RB2 in place of QL-RB3's counts and QL-RB2 ──
    QCRB2 = (
        "# QC-RB1's table and QC-RB2 — 5.18.103 (docs/72 §G.10, §G.13), with $BASELINE_VERSION in place: the uCRM catalogue's\n"
        "# table, which the rollback leaves as written (RBQ_S's qcrb1t: absent, or its records), and the web-direct applications\n"
        "# with no quotation made, counted again on a fresh consistent copy of the book (qcrb_count; counts in the log, ids on\n"
        "# the sheet). Notes, never a failure: the rollback rewrites no application, and $BASELINE_VERSION quotes none of them —\n"
        "# each is quoted in uCRM by hand. QCRB_SAY carries the reading to the summary.\n"
        "case \"$(kv \"$RBQ_S\" qcrb1t)\" in\n"
        "  absent) note \"QC-RB1 the uCRM catalogue's table is not on the book — no setting was ever saved on the uCRM catalogue screen\" ;;\n"
        "  records:*) note \"QC-RB1 the uCRM catalogue's table stays on the book, as the rollback leaves it: $(subkv \"$(kv \"$RBQ_S\" qcrb1t)\" records) record(s) — read by nothing under $BASELINE_VERSION; a later deploy of $EXPECTED_VERSION finds the switch, the installation product, the products kept off and the standard quotations as they were\" ;;\n"
        "  *) note \"QC-RB1 the uCRM catalogue's table could not be counted ($(kv \"$RBQ_S\" qcrb1t)) — said, never a failure\" ;;\n"
        "esac\n"
        "QCRB_SAY=\"\"\n"
        "if qcrb_count \"RB, after the rollback\"; then QCRB_SAY=\"$(qcrb_say \"$QC_RB\")\"\n"
        "  if [ \"$(subkv \"$QC_RB\" unquoted)\" = \"0\" ]; then note \"QC-RB2 after the rollback, on a copy of the book: $QCRB_SAY — none to quote by hand\"\n"
        "  else note \"QC-RB2 after the rollback, on a copy of the book: $QCRB_SAY — $BASELINE_VERSION reads each as one from the app (P1, or P2 where cash was collected) and never quotes it: an admin quotes each in uCRM by hand, after checking the customer has none (ids on the sheet)\"; fi\n"
        "else QCRB_SAY=\"not counted ($QC_RB_ERR)\"; note \"QC-RB2 the web-direct applications with no quotation could not be counted after the rollback ($QC_RB_ERR) — said, never a failure\"; fi")
    doc.lines_between("# QL-RB3's counts — 5.18.102 D14 (docs/71 §R.7.4, §R.17.3), in the same reading (RBQ_S: read-only, as the database's",
                      '  note "$QLRB2_TXT"', QCRB2, why="QC-RB2")
    doc.sub("\n" + QCRB2 + "\nfi\n# 5.18.95's fixes stay with the rollback", "\n" + QCRB2 + "\n# 5.18.95's fixes stay with the rollback", why="QC-RB2 tail")
    doc.mustnot("QL_RB1_MARKS"); doc.mustnot("QL_RB3_MARKS"); doc.mustnot("qlrb_judge"); doc.mustnot("qlrb_count")
    # the added files' count, its comment
    doc.lines_between("# 5.18.102's files are 5.18.101's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each",
                      "# N_ADDED is Git's count of them, never the disk's; RB below, QL-RB1, RB's last note and the summary say this count.",
        f"# 5.18.103's files are 5.18.102's again, each byte for byte — by Git's sha256 and by the one THIS SCRIPT pins for each\n"
        f"# (PREV_CHANGED_SHA256S, Q-G1's set read again: {ctx['n_changed']}) — and the {ctx['n_added']} files it added stay on disk\n"
        "# (deploy-hybrid.sh never deletes, nor can the by-hand put-back). Git's loop below passes over them, since 5.18.102 has\n"
        "# none of them; QC-RB1 (below) proves nothing in 5.18.102 reaches them. The files 5.18.103 adds, as they are on disk now —\n"
        "# counted here, before any line says how many: N_ADDED is Git's count of them, never the disk's; RB below, QC-RB1, RB's\n"
        "# last note and the summary say this count.", why="RB added comment")
