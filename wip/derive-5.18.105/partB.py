# Part B — the carried 5.18.104 checks that 5.18.105's delta re-points (root docs/74 §H.4.2, §H.4.6). Each re-point keeps
# the check's force against what 5.18.105 really is; none is weakened, and each says why:
#   B1 A0 and its migrations: 5.18.105 adds none (`[ "$N_MIG" = "0" ]`). MIG104_* stay, describing what the live book must
#      already carry; the three files are still read at the pin by their sha256 and md5 (they are kept files too).
#   B2 cron/master.php: 5.18.105 keeps 5.18.104's master byte for byte, so MASTER_PIN_SHA256 and MASTER_BASE_SHA256 are both
#      994d660's (from Git, asserted equal at the pin). A15's and RB's words become "5.18.104's master.php: 5.18.92's lock,
#      ruling R1's four jobs last". Left as it was, A15 would be NO-GO over live 5.18.104.
#   B3 the event processor: UG_SIGNATURE becomes 994d660's value — 5.18.104's UG_PIN_SIGNATURE string (ai.media=pending/0);
#      UG_PIN_SIGNATURE, SS_SIGNATURE and EP_PIN_LIST stay, only their comments reworded. 5.18.105 holds
#      cron/event_processor.php and lib/NotificationService.php (asserted from Git), so nothing needs re-measuring.
#   B4 the copy's time mark: COPY_MARK becomes evo_webhook.php, a file 5.18.105 changes (stamp_copy touches only the delta).
#   B5 R7's migration ledger: "the same" only — 091 last before and after (db_mig104 retired: 5.18.105 applies none).
import re
from d105lib import git, sha_at, md5_at


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed = ctx["added"], ctx["changed"]

    # ── B1 — A0: no migration; 5.18.104's three still the reviewed files at the pin ──
    for ln in doc.t[doc.t.index("MIG104_SUMS=\"$(cat <<'SUMS'\n"):].split("\nSUMS\n", 1)[0].split("\n")[1:]:
        s, m5, f = ln.split()
        if sha_at(repo, pin, plugin + "/" + f) != s or md5_at(repo, pin, plugin + "/" + f) != m5:
            raise SystemExit(f"PART B: {f} at the pin is not MIG104_SUMS' file")
        if sha_at(repo, base, plugin + "/" + f) != s:
            raise SystemExit(f"PART B: {f} at {base[:7]} is not MIG104_SUMS' file")
    doc.lines_between('N_MIG="$(printf \'%s\\n\' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"',
                      '[ -z "$MIG_BAD" ] || stop "the pin\'s migrations are not $EXPECTED_VERSION\'s reviewed three ($MIG_BAD)',
        'N_MIG="$(printf \'%s\\n\' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"\n'
        '# 5.18.105 (root docs/74 §H.4.6): the pin adds no migration and changes none. 5.18.104\'s three, 089–091, are what the\n'
        '# live book must already carry (QF-G1 reads them applied); each is still the reviewed file at the pin, by its sha256 and\n'
        '# the md5 the runner records (MIG104_SUMS) — PREV_KEPT holds them by sha256 too.\n'
        '[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s) ($(printf \'%s\\n\' "$CHANGED" | grep "^$PLUGIN/migrations/" | sed "s#^$PLUGIN/migrations/##" | tr \'\\n\' \' \')) — $EXPECTED_VERSION adds none (root docs/74 §H.3.1): this script is for another build. Nothing was changed"\n'
        'MIG_BAD=""; MIG_SEEN=0\n'
        'while read -r mg_sha mg_md5 mg_rel; do\n'
        '  [ -n "$mg_rel" ] || continue\n'
        '  MIG_SEEN=$((MIG_SEEN + 1))\n'
        '  [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$mg_rel" 2>/dev/null | sha256sum | cut -c1-64)" = "$mg_sha" ] || MIG_BAD="$MIG_BAD ${mg_rel#migrations/}(sha256)"\n'
        '  [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$mg_rel" 2>/dev/null | md5sum | cut -c1-32)" = "$mg_md5" ] || MIG_BAD="$MIG_BAD ${mg_rel#migrations/}(md5)"\n'
        'done <<< "$MIG104_SUMS"\n'
        '[ "$MIG_SEEN" = "$MIG104_N" ] || MIG_BAD="$MIG_BAD count:$MIG_SEEN-read/$MIG104_N-expected"\n'
        'echo "  migrations      none added since $BASELINE_VERSION; $BASELINE_VERSION\'s own, which the live book carries: $(printf \'%s\\n\' "$MIG104_SUMS" | awk \'{ sub(/^migrations\\//, "", $3); printf "%s%s", (n++ ? ", " : ""), $3 }\'); 088 (5.18.97\'s) and 087 (5.18.88\'s) stay as they are"\n'
        '[ -z "$MIG_BAD" ] || stop "the pin\'s copies of $BASELINE_VERSION\'s three migrations are not the reviewed files ($MIG_BAD) — this script is for another build. Nothing was changed"',
        why="A0 migrations")
    doc.must('[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s)', 1)
    doc.mustnot("grep -qxF -- \"$PLUGIN/$mg_rel\" <<<\"$ADDED\"")
    doc.sub("— and exactly its $MIG104_N migrations, each added and the reviewed file (sha256 and md5, MIG104_SUMS);",
            "— and no migration ($BASELINE_VERSION's $MIG104_N, 089–091, the reviewed files at the pin as at $BASELINE_COMMIT: sha256 and md5, MIG104_SUMS);",
            why="A0 ok migrations")
    doc.sub("the owner rule and the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them — unchanged: the same pinned sha256 in both release commits; webhook.php is not among them: 5.18.104 changes it by Part P's two hunks in whQuotationEmail (root docs/73 §H.5.8.2), its new form pinned with the delta, its sign-off line still 5.18.101's (the QS checks);",
            "the owner rule, the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, QuoteTaxLine, TenantProfile and PublicPriceFeed among them — and $BASELINE_VERSION's own sales follow-up (the switch tool, its ladder and checks, master, the event processor, NotificationService, 089–091) unchanged: the same pinned sha256 in both release commits; webhook.php, evo_webhook.php, cron_quote_wa.php and the other files whose log lines $EXPECTED_VERSION masks are not among them (root docs/74 §H.1), each new form pinned with the delta, webhook.php's sign-off line still 5.18.101's (the QS checks);",
            why="A0 ok kept")
    doc.sub("$MASTER the reviewed one (sha256 ${MASTER_PIN_SHA256:0:16}…: 5.18.92's lock and ruling R1's four jobs last, docs/73 §H.2.13);",
            "$MASTER $BASELINE_VERSION's byte for byte (sha256 ${MASTER_PIN_SHA256:0:16}…: 5.18.92's lock, ruling R1's four jobs last);",
            why="A0 ok master")

    # ── B2 — master.php: 994d660's in both places ──
    m_base_sha = sha_at(repo, base, plugin + "/cron/master.php")
    if m_base_sha != sha_at(repo, pin, plugin + "/cron/master.php"):
        raise SystemExit("PART B: the pin changes cron/master.php — 5.18.105 must hold it (R105_HELD)")
    if "cron/master.php" in added + changed:
        raise SystemExit("PART B: cron/master.php is in the delta")
    doc.must(f'MASTER_PIN_SHA256="{m_base_sha}"', 1, why="5.18.104's pinned master is 994d660's")
    doc.line('MASTER_PIN_SHA256="',
             f'MASTER_PIN_SHA256="{m_base_sha}"   # 5.18.104\'s master.php, which 5.18.105 keeps byte for byte: 5.18.92\'s lock, ruling R1\'s four jobs last with their dispatch — the pin\'s (A0, Q-CA1, R6)')
    doc.line('MASTER_BASE_SHA256="5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1"',
             f'MASTER_BASE_SHA256="{m_base_sha}"  # 5.18.104\'s master.php: 5.18.92\'s lock, ruling R1\'s four jobs last — the live one\'s, and the rollback\'s (A15, RB)')
    doc.must(f'MASTER_BASE_SHA256="{m_base_sha}"', 1)
    doc.mustnot("5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1")
    doc.sub("# cron/master.php's lock — 5.18.104 adds ruling R1's four job lines and their dispatch, its lock lines unchanged (A0) —\n"
            "# so this is the regression: master's lock code and",
            "# cron/master.php — 5.18.105 keeps 5.18.104's byte for byte: 5.18.92's lock, ruling R1's four jobs last (A0) —\n"
            "# so this is the regression: master's lock code and")
    doc.sub("# is deployed; if one does not, the log shows what the test printed. And the live master.php is 5.18.92's byte for byte.",
            "# is deployed; if one does not, the log shows what the test printed. And the live master.php is 5.18.104's byte for byte\n"
            "# (5.18.92's lock, ruling R1's four jobs last).")
    doc.sub("passes ($LT_A): 5.18.92's lock, its code unchanged by $EXPECTED_VERSION (which adds ruling R1's four jobs, QF-CA3), released at master's normal end",
            "passes ($LT_A): 5.18.92's lock, its code unchanged by $EXPECTED_VERSION (5.18.104's master, ruling R1's four jobs last, QF-CA3), released at master's normal end")
    doc.sub("# its auto-pull retired; cron/master.php is 5.18.92's again (5.18.104's four jobs gone with the put-back's copy),\n",
            "# its auto-pull retired; cron/master.php is 5.18.104's (5.18.92's lock, ruling R1's four jobs last — 5.18.105 never\n"
            "# changed it),\n")
    doc.sub('|| RB_REF="$RB_REF master:not-5.18.92\'s"', '|| RB_REF="$RB_REF master:not-5.18.104\'s"')

    # ── B3 — the event processor: 994d660's Uganda signature is 5.18.104's pin signature ──
    for f in ("cron/event_processor.php", "lib/NotificationService.php"):
        if f in added + changed or sha_at(repo, base, plugin + "/" + f) != sha_at(repo, pin, plugin + "/" + f):
            raise SystemExit(f"PART B: 5.18.105 changes {f} — the event processor's signatures would need re-measuring")
    ug_pin = "crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=pending/0 wa.escalation=done/0 install.ready=done/0+unknown"
    doc.must(f'UG_PIN_SIGNATURE="{ug_pin}"\n', 1)
    doc.lines_between("# 5.18.104 changes cron/event_processor.php (its Uganda worker-owned list, docs/73 §H.3.2) and lib/NotificationService.php,",
                      'EP_PIN_LIST="',
        "# 5.18.105 keeps cron/event_processor.php and lib/NotificationService.php, which it loads, byte for byte (R105_HELD):\n"
        "# the live code, the pin and the rollback all read 5.18.104's signatures — UG_SIGNATURE is 5.18.104's Uganda one\n"
        "# (its pin signature, ai.media left to MediaWorker), SS_SIGNATURE its South Sudan one; nothing is re-measured (root\n"
        "# docs/74 §H.4.2). UG_PIN_SIGNATURE and EP_PIN_LIST are the same values, kept so A6 and R13 read as 5.18.104's did.\n"
        "SS_SIGNATURE=\"crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown\"\n"
        f'UG_SIGNATURE="{ug_pin}"\n'
        "# 5.18.104 (root docs/73 §H.3.2), kept by 5.18.105: on Uganda the processor leaves ai.media (and ai.voice_ack) to their\n"
        "# workers too, so its Uganda run leaves the ai.media event pending, never logged unknown (A6, R13).\n"
        f'UG_PIN_SIGNATURE="{ug_pin}"\n'
        "EP_PIN_LIST=\"\\$_epWorkerOwned = \\$_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];\"",
        why="event processor signatures")
    doc.must(f'UG_SIGNATURE="{ug_pin}"\n', 1)
    doc.must("EP_PIN_LIST=\"\\$_epWorkerOwned = \\$_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];\"\n", 1)
    ep = git(repo, "show", f"{pin}:{plugin}/cron/event_processor.php").decode()
    if ep.split("\n").count("$_epWorkerOwned = $_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];") != 1:
        raise SystemExit("PART B: the pin's worker-owned list is not EP_PIN_LIST")
    # T1-b (trial 1, 13 Nov: every rollback FAILed "RB … batch1:event-processor"): 5.18.104's script expected 5.18.103's
    # list after its rollback. A rollback of 5.18.105 leaves 5.18.104's file, which is the pin's — EP_PIN_LIST, read
    # here in the baseline from Git as well.
    epb = git(repo, "show", f"{base}:{plugin}/cron/event_processor.php").decode()
    if epb.split("\n").count("$_epWorkerOwned = $_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];") != 1:
        raise SystemExit("PART B: the baseline's worker-owned list is not EP_PIN_LIST")
    doc.sub("grep -qF \"\\$_epWorkerOwned = \\$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];\" \"$DEST/cron/event_processor.php\" 2>/dev/null || RB_REF=\"$RB_REF batch1:event-processor\"\n",
            "grep -qxF -- \"$EP_PIN_LIST\" \"$DEST/cron/event_processor.php\" 2>/dev/null || RB_REF=\"$RB_REF batch1:event-processor\"   # 5.18.105: the rollback leaves 5.18.104's list, which is the pin's (EP_PIN_LIST)\n",
            why="RB_REF event processor")
    doc.mustnot("? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];")
    doc.sub("# lib/NotificationService.php, both of which 5.18.104 changes and the rollback puts back, so it must read 5.18.103's\n"
            "# signatures again: UG_SIGNATURE and SS_SIGNATURE are 5.18.103's values, carried (docs/73 §H.2.15.4).",
            "# lib/NotificationService.php, both of which 5.18.105 keeps byte for byte, so after the rollback it reads 5.18.104's\n"
            "# signatures, as before the deploy: UG_SIGNATURE and SS_SIGNATURE (root docs/74 §H.4.2).")

    # ── B4 — the copy's time mark ──
    if "evo_webhook.php" not in changed:
        raise SystemExit("PART B: evo_webhook.php is not a file 5.18.105 changes")
    doc.subs([
        ("# The copy's time is the time stamp_copy gives a file this release changes: lib/NotificationService.php since 5.18.104\n"
         "# (lib/KycService.php, 5.18.102's and 5.18.103's mark, is a file 5.18.104 keeps byte for byte, so stamp_copy never\n"
         "# touches it).\nCOPY_MARK=\"lib/NotificationService.php\"",
         "# The copy's time is the time stamp_copy gives a file this release changes: evo_webhook.php since 5.18.105 (root docs/74\n"
         "# §H.4.2; lib/NotificationService.php, 5.18.104's mark, is a file 5.18.105 keeps byte for byte, so stamp_copy never\n"
         "# touches it) — COPY_AT, R19, V4 and A14 read the right moment.\nCOPY_MARK=\"evo_webhook.php\""),
        ("  # 5.18.97 (its review): the rollback's own copy time, as stamp_copy gave lib/NotificationService.php (COPY_MARK since 5.18.104; 5.18.103 and 5.18.102 off lib/KycService.php, 5.18.101 off",
         "  # 5.18.97 (its review): the rollback's own copy time, as stamp_copy gave evo_webhook.php (COPY_MARK since 5.18.105; 5.18.104 off lib/NotificationService.php, 5.18.103 and 5.18.102 off lib/KycService.php, 5.18.101 off"),
        ("  # The copy's own time, as stamp_copy gave lib/NotificationService.php (COPY_MARK, a file 5.18.104 changes):",
         "  # The copy's own time, as stamp_copy gave evo_webhook.php (COPY_MARK, a file 5.18.105 changes):"),
    ], why="copy mark")

    # ── B5 — R7's migration ledger: the same, before and after ──
    doc.lines_between("# db_mig104 BEFORE-LEDGER NOW-LEDGER — \"applied\" when NOW is BEFORE with exactly 5.18.104's three migrations added at",
                      "db_judge() {   # db_judge ID BEFORE NOW WHEN strict|loose — the ledger's last row the same, or (R7 only) BEFORE with 5.18.104's three migrations applied (db_mig104); the",
        "db_judge() {   # db_judge ID BEFORE NOW WHEN strict|loose — the ledger's last row the same (5.18.105 adds no migration: 091 last before and after, root docs/74 §H.4.6); the",
        why="db_mig104 retired")
    doc.sub('  local mg="same"; [ "$bl" = "$nl" ] || mg="$( [ "$1" = "R7" ] && db_mig104 "$bl" "$nl" || echo other)"\n',
            '  local mg="same"; [ "$bl" = "$nl" ] || mg="other"\n', why="db_judge same only")
    doc.sub('$( [ "$1" = "R7" ] && echo "$EXPECTED_VERSION adds exactly its $MIG104_N migrations (089, 090, 091), applied at the first boot, and this run applies no other" || echo "a rollback applies no migration and removes none (QF-RB2: 5.18.104\'s tables stay)")',
            '$( [ "$1" = "R7" ] && echo "$EXPECTED_VERSION adds no migration, and this run applies none" || echo "a rollback applies no migration and removes none ($BASELINE_VERSION\'s 089–091 stay, as they are)")',
            why="db_judge bad")
    doc.sub('$( [ "$mg" = "applied" ] && echo "moved by exactly $EXPECTED_VERSION\'s $MIG104_N migrations, applied at the first boot ($bl → $nl; rows:last migration:checksum — QF-CA1 judges each by its _schema_checks row)" || echo "its last row the same ($nl; rows:last migration:checksum)")',
            'its last row the same ($nl; rows:last migration:checksum)', why="db_judge ok")
    doc.sub("— $EXPECTED_VERSION adds no migration beyond its three; what a live plugin writes at use is the plugin's own, and $( if [ \"$1\" != \"RB\" ]; then echo \"nothing in this run writes it but the switch tool's clear where --clear-switches was given (QF-G4)\"; elif [ -n \"${QF_RB1_START:-}\" ]; then echo \"this run wrote it only through QF-RB1, before the put-back: the installed tool's off all --rollback cleared $EXPECTED_VERSION's keys in the store row, and its hooks closed or paused rows; it applied no migration and removed none (QF-RB2)\"; else echo \"nothing in this run wrote it: the container already served $BASELINE_VERSION, so QF-RB1 did not run\"; fi )\"",
            "— $EXPECTED_VERSION adds no migration; what a live plugin writes at use is the plugin's own — the steps of the sales follow-up that are on among it, which this run never turns on or off — and nothing in this run writes it$( [ \"$1\" = \"RB\" ] && echo \": the rollback runs no switch tool and no off-hook, and applies or removes no migration (root docs/74 §H.5.2)\")\"",
            why="db_judge ok tail")
    doc.mustnot("db_mig104()")
    doc.mustnot('db_mig104 "$bl" "$nl"')
    doc.sub("# ── 5.18.95: the database, before and after (R7, RB) — 5.18.104 adds 089–091 (db_mig104); this run writes nothing else ─",
            "# ── 5.18.95: the database, before and after (R7, RB) — 5.18.105 adds no migration; this run writes nothing ─────────────")
    doc.lines_between("# R7 — the database (5.18.95 to 5.18.104): 5.18.104 adds its three migrations, 089, 090 and 091, applied by the plugin's",
                      "# QF-CA1 judges each by its _schema_checks row); 5.18.103 added none, and its Cashbook reads write nothing to",
        "# R7 — the database (5.18.95 to 5.18.105): 5.18.105 adds no migration — the ledger reads 091 last before and after (root\n"
        "# docs/74 §H.4.6); 5.18.104 added 089–091, and its Cashbook reads write nothing to", why="R7 comment")
