# Part B — the carried 5.18.103 checks that 5.18.104's delta re-points (root docs/73 §H.2.15: "It keeps every 5.18.103
# check"). Each re-point keeps the check's force against what 5.18.104 really is; none is weakened, and each says why:
#   B1 A0 and its migrations: 5.18.104 adds exactly 089, 090 and 091 (docs/73 §C C2), each the reviewed file by its
#      sha256 and by the md5 the runner records; 088 and 087 unchanged. 5.18.103's "no migration" refusal would refuse it.
#   B2 cron/master.php: 5.18.104 adds ruling R1's four job lines and their dispatch (§H.2.13), so the pin's master is no
#      longer 5.18.92's byte for byte — MASTER_PIN_SHA256 is the pin's, from Git; the live one and the rollback's stay
#      5.18.92's (MASTER_BASE_SHA256); the lock code itself is unchanged (asserted here on the two texts).
#   B3 the event processor (A6, R13, RB): on Uganda ai.media and ai.voice_ack become their workers' (§H.3.2), so the
#      pin's Uganda signature leaves ai.media pending — UG_PIN_SIGNATURE, predicted from the one-line change and
#      asserted against the pin's text here; South Sudan's list and signature unchanged (§H.2.17).
#   B4 the copy's time mark: lib/KycService.php is not 5.18.104's; lib/NotificationService.php is.
#   B5 R4's undeployed work: the AI media layer is this release's (Part V), pinned file by file in PIN_SHA256S — its three
#      "not in this release" readings retire, and the files 5.18.104 adds leave the undeployed list.
#   B6 R7's migration ledger: after the first boot the ledger holds 089, 090 and 091 beyond stage A's 088 (QF-CA1
#      judges each); anything else still fails, and across a rollback nothing may move (RB, QF-RB2).
import re
from d104lib import git, sha_at, md5_at


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed, migs = ctx["added"], ctx["changed"], ctx["migrations"]

    # ── B1 — the three migrations, by sha256 and md5, from Git ──
    rows = []
    for m in migs:
        rows.append(f"{sha_at(repo, pin, plugin + '/' + m)} {md5_at(repo, pin, plugin + '/' + m)}  {m}")
    last = migs[-1].split("/", 1)[1]
    last_md5 = md5_at(repo, pin, plugin + "/" + migs[-1])
    m88_md5 = md5_at(repo, pin, plugin + "/migrations/088_bank_statements.sql")
    assert m88_md5 == md5_at(repo, base, plugin + "/migrations/088_bank_statements.sql")
    doc.must('MIG88_MD5="42036d6d967521b4999be7d254706d5e"\n', 1)
    assert m88_md5 == "42036d6d967521b4999be7d254706d5e", m88_md5
    ctx.update(mig104_rows=rows, mig104_last=last, mig104_last_md5=last_md5)
    doc.insert_after("BK_TABLES=\"bank_account_profiles bank_checks bank_statements bank_statement_lines bank_line_events\"\nBK_CHECKS=16\n",
        "# 5.18.104's own migrations (A0, QF-G2, QF-CA1, R7; root docs/73 §C C2): 089 the quote follow-ups and the one send\n"
        "# ledger, 090 voice media, 091 offers — added, none changed, each verifying itself (its _schema_checks row is the\n"
        "# verdict, §H.2.4). The reviewed files byte for byte (sha256) and their checksums as the runner records them in\n"
        "# _migrations (md5 of the file, lib/MigrationRunner.php:189), from Git. After the first boot the ledger reads\n"
        "# MIG104_LAST with three rows more than before it (R7); a rollback moves it never (RB; QF-RB2: the tables stay).\n"
        'MIG104_SUMS="$(cat <<\'SUMS\'\n' + "\n".join(rows) + "\nSUMS\n)\"\n"
        f'MIG104_N={len(migs)}\n'
        f'MIG104_LAST="{last}"; MIG104_LAST_MD5="{last_md5}"\n', why="MIG104 constants")

    doc.lines_between('N_MIG="$(printf \'%s\\n\' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"',
                      '[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s)',
        'N_MIG="$(printf \'%s\\n\' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"\n'
        '# 5.18.104 (root docs/73 §C C2): exactly its three migrations, each added (none changed) and each the reviewed file by\n'
        '# its sha256 and the md5 the runner records; 088 (5.18.97\'s) and 087 (5.18.88\'s) stay as they are (below). 5.18.103\'s\n'
        '# rule — "adds none" — is re-pointed, never dropped: any other migration in the pin still stops here.\n'
        'MIG_BAD=""; MIG_SEEN=0\n'
        'while read -r mg_sha mg_md5 mg_rel; do\n'
        '  [ -n "$mg_rel" ] || continue\n'
        '  MIG_SEEN=$((MIG_SEEN + 1))\n'
        '  grep -qxF -- "$PLUGIN/$mg_rel" <<<"$ADDED" || MIG_BAD="$MIG_BAD ${mg_rel#migrations/}(not-added)"\n'
        '  [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$mg_rel" 2>/dev/null | sha256sum | cut -c1-64)" = "$mg_sha" ] || MIG_BAD="$MIG_BAD ${mg_rel#migrations/}(sha256)"\n'
        '  [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$mg_rel" 2>/dev/null | md5sum | cut -c1-32)" = "$mg_md5" ] || MIG_BAD="$MIG_BAD ${mg_rel#migrations/}(md5)"\n'
        'done <<< "$MIG104_SUMS"\n'
        'for mg_f in $(printf \'%s\\n\' "$CHANGED" | grep "^$PLUGIN/migrations/"); do\n'
        '  grep -qF -- "  ${mg_f#"$PLUGIN"/}" <<<"$MIG104_SUMS" || MIG_BAD="$MIG_BAD ${mg_f#"$PLUGIN"/migrations/}(not-$EXPECTED_VERSION\'s)"\n'
        'done\n'
        '[ "$MIG_SEEN|$N_MIG" = "$MIG104_N|$MIG104_N" ] || MIG_BAD="$MIG_BAD count:$N_MIG-in-the-delta/$MIG104_N-expected"\n'
        'echo "  migrations      $N_MIG added since $BASELINE_VERSION — $EXPECTED_VERSION\'s own: $(printf \'%s\\n\' "$MIG104_SUMS" | awk \'{ sub(/^migrations\\//, "", $3); printf "%s%s", (n++ ? ", " : ""), $3 }\'); 088 (5.18.97\'s) and 087 (5.18.88\'s) stay as they are"\n'
        '[ -z "$MIG_BAD" ] || stop "the pin\'s migrations are not $EXPECTED_VERSION\'s reviewed three ($MIG_BAD) — this script is for another build. Nothing was changed"',
        why="A0 migrations")
    doc.mustnot('$EXPECTED_VERSION adds none — this script is for another build"')
    doc.must('done <<< "$MIG104_SUMS"\n', 1)
    doc.sub("— and no migration: ${MIG88#migrations/} the reviewed one in both release commits (sha256 ${MIG88_SHA256:0:16}…), 087 the reviewed one (sha256 ${MIG87_SHA256:0:16}…), both unchanged;",
            "— and exactly its $MIG104_N migrations, each added and the reviewed file (sha256 and md5, MIG104_SUMS); ${MIG88#migrations/} the reviewed one in both release commits (sha256 ${MIG88_SHA256:0:16}…), 087 the reviewed one (sha256 ${MIG87_SHA256:0:16}…), both unchanged;",
            why="A0 ok migrations")
    doc.sub("the owner rule and the quoting files $EXPECTED_VERSION keeps — webhook.php, QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them — unchanged:",
            "the owner rule and the quoting files $EXPECTED_VERSION keeps — QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed and cron_quote_wa.php among them; webhook.php is $EXPECTED_VERSION's own since Part P's hunk (docs/73 §H.5.8.2) — unchanged:",
            why="A0 ok kept")
    doc.sub("$MASTER $BASELINE_VERSION's byte for byte (sha256 ${MASTER_PIN_SHA256:0:16}…), with no AI media job; no partner-portal, CSRF or AI media-layer file;",
            "$MASTER the reviewed one (sha256 ${MASTER_PIN_SHA256:0:16}…: 5.18.92's lock and ruling R1's four jobs last, docs/73 §H.2.13); no partner-portal or CSRF file;",
            why="A0 ok master")

    # ── B2 — master.php ──
    m_base = git(repo, "show", f"{base}:{plugin}/cron/master.php").decode()
    m_pin = git(repo, "show", f"{pin}:{plugin}/cron/master.php").decode()
    assert sha_at(repo, base, plugin + "/cron/master.php") == "5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1"
    # the lock code is unchanged: every line 5.18.92's master holds that names the lock is in the pin's, in the same order
    lk = lambda t: [ln for ln in t.split("\n") if "_m_lock" in ln]
    assert lk(m_base) and lk(m_base) == lk(m_pin), "master's lock lines differ in 5.18.104"
    r1 = [ln.strip() for ln in m_pin.split("\n") if re.match(r"\s*'(quote_chase|quote_daily_list|ai_media|offers_send)'\s*=>", ln)]
    assert [l.split("'")[1] for l in r1] == ["quote_chase", "quote_daily_list", "ai_media", "offers_send"], r1
    m_pin_sha = sha_at(repo, pin, plugin + "/cron/master.php")
    doc.line('MASTER_PIN_SHA256="5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1"',
             f'MASTER_PIN_SHA256="{m_pin_sha}"   # 5.18.104\'s master.php: 5.18.92\'s lock and its lines unchanged, ruling R1\'s four jobs last with their dispatch (docs/73 §H.2.13) — the pin\'s byte for byte (A0, Q-CA1, R6)')
    doc.line('MASTER_BASE_SHA256="5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1"',
             'MASTER_BASE_SHA256="5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1"  # 5.18.92\'s master.php: the live one\'s, and the rollback\'s (A15, RB)')
    doc.sub('[ "$M_PIN_SHA" = "$MASTER_PIN_SHA256" ] || stop "the pin\'s $MASTER is not $BASELINE_VERSION\'s (sha256',
            '[ "$M_PIN_SHA" = "$MASTER_PIN_SHA256" ] || stop "the pin\'s $MASTER is not the reviewed one — 5.18.92\'s lock with ruling R1\'s four jobs (sha256')
    doc.sub("# cron/master.php — the pin's is 5.18.92's byte for byte (A0) — so this is the regression: master's lock code and",
            "# cron/master.php's lock — 5.18.104 adds ruling R1's four job lines and their dispatch, its lock lines unchanged (A0) —\n"
            "# so this is the regression: master's lock code and")
    doc.sub("passes ($LT_A): 5.18.92's lock, unchanged by $EXPECTED_VERSION, released at master's normal end",
            "passes ($LT_A): 5.18.92's lock, its code unchanged by $EXPECTED_VERSION (which adds ruling R1's four jobs, QF-CA3), released at master's normal end")
    doc.sub("|| R6_BAD=\"$R6_BAD master:not-5.18.92's\"", "|| R6_BAD=\"$R6_BAD master:not-the-pin's\"")

    # ── B3 — the event processor ──
    ep = git(repo, "show", f"{pin}:{plugin}/cron/event_processor.php").decode()
    ep_line = "$_epWorkerOwned = $_epUg ? ['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];"
    assert ep.split("\n").count(ep_line) == 1, "the pin's worker-owned list"
    ep_ai_lines = sum(1 for ln in ep.split("\n") if "ai.media" in ln)
    assert ep_ai_lines == 2, ep_ai_lines   # the comment above the list, and the list
    ug103 = "crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
    ug104 = ug103.replace("ai.media=done/0+unknown", "ai.media=pending/0")
    assert ug104 != ug103 and ug104.count("ai.media=pending/0") == 1
    doc.must(f'UG_SIGNATURE="{ug103}"\n', 1)
    doc.insert_after(f'UG_SIGNATURE="{ug103}"\n',
        ("# 5.18.104 (root docs/73 §H.3.2): on Uganda the pin's processor leaves ai.media (and ai.voice_ack) to their workers too,\n"
         "# so its Uganda run leaves the ai.media event pending, never logged unknown — UG_SIGNATURE with that one field changed,\n"
         "# predicted from the pin's one-line change (its worker-owned list) and re-measured on the release commit by the\n"
         "# rehearsal before pinning (A6, R13). South Sudan's list is unchanged, so SS_SIGNATURE is the pin's too (§H.2.17).\n"
         f'UG_PIN_SIGNATURE="{ug104}"\n'
         f'EP_PIN_LIST="{ep_line}"\n').replace('$', '\\$'), why="UG_PIN_SIGNATURE")
    doc.must('EP_PIN_LIST="\\$_epWorkerOwned = \\$_epUg ? [\'ai.reply\', \'ai.media\', \'ai.voice_ack\', \'crm.lead.sync\'] : [\'ai.reply\'];"\n', 1)
    doc.subs([
        ("# 5.18.103 changes none of the files the event processor loads (EventBus, the Splynx classes, NotificationService):\n"
         "# both signatures are 5.18.102's values, carried — re-measured on the release commit by the rehearsal (A6, R13) before\n"
         "# pinning (docs/72 §G.13).",
         "# 5.18.104 changes cron/event_processor.php (its Uganda worker-owned list, docs/73 §H.3.2) and lib/NotificationService.php,\n"
         "# which it loads (three record-only hooks behind the ledger key, §H.2.6): both signatures are 5.18.103's values, carried —\n"
         "# what the live code and the rollback must read — and the pin's Uganda one is UG_PIN_SIGNATURE below; each re-measured\n"
         "# on the release commit by the rehearsal (A6, R13) before pinning (docs/73 §H.2.15.4)."),
        ('  [ "$(grep -cF "\\$_epWorkerOwned = \\$_epUg ? [\'ai.reply\', \'crm.lead.sync\'] : [\'ai.reply\'];" <<<"$PIN_EP")" = "1" ] || A6_BAD="$A6_BAD lists"\n'
         "  grep -qF 'ai.media' <<<\"$PIN_EP\" && A6_BAD=\"$A6_BAD ai.media-named\"\n",
         '  # 5.18.104 (root docs/73 §H.3.2, §H.2.17): the pin\'s list is EP_PIN_LIST — ai.media and ai.voice_ack on Uganda\'s side\n'
         '  # only, South Sudan\'s [\'ai.reply\'] unchanged — and ai.media is named nowhere else but the comment above it\n'
         '  [ "$(grep -cxF -- "$EP_PIN_LIST" <<<"$PIN_EP")" = "1" ] || A6_BAD="$A6_BAD lists"\n'
         f'  [ "$(grep -cF \'ai.media\' <<<"$PIN_EP")" = "{ep_ai_lines}" ] || A6_BAD="$A6_BAD ai.media-named-beyond-the-uganda-list"\n'),
        ('  [ "$UG_PIN" = "$UG_LIVE" ] || A6_BAD="$A6_BAD uganda-differs"\n',
         '  [ "$UG_PIN" = "$UG_PIN_SIGNATURE" ] || A6_BAD="$A6_BAD uganda-not-$EXPECTED_VERSION\'s"   # 5.18.104: ai.media left to MediaWorker on Uganda (UG_PIN_SIGNATURE)\n'),
        ("leaves five events as the live one does, as a South Sudan install (its list ['ai.reply'], no ai.media) and as a Uganda one (the workers' events left to them);",
         "leaves five events as the live one does as a South Sudan install (its list ['ai.reply'], no ai.media); as a Uganda one it leaves the workers' events to them, ai.media now among them ($UG_PIN, UG_PIN_SIGNATURE);"),
        ('[ "$UG_NOW" = "$UG_SIGNATURE" ] || R13_BAD="$R13_BAD uganda:$UG_NOW"\n',
         '[ "$UG_NOW" = "$UG_PIN_SIGNATURE" ] || R13_BAD="$R13_BAD uganda:$UG_NOW"   # 5.18.104: ai.media left to MediaWorker on Uganda\n'),
        ('grep -qF "\\$_epWorkerOwned = \\$_epUg ? [\'ai.reply\', \'crm.lead.sync\'] : [\'ai.reply\'];" "$DEST/cron/event_processor.php" 2>/dev/null || R13_BAD="$R13_BAD lists"\n',
         'grep -qxF -- "$EP_PIN_LIST" "$DEST/cron/event_processor.php" 2>/dev/null || R13_BAD="$R13_BAD lists"\n'),
        ("on Uganda it leaves ai.reply and crm.lead.sync to their workers and settles wa.escalation as known ($UG_NOW);",
         "on Uganda it leaves ai.reply, ai.media and crm.lead.sync to their workers and settles wa.escalation as known ($UG_NOW);"),
        ("# lib/NotificationService.php, which 5.18.103 does not change, so it must read 5.18.102's signature again:\n"
         "# UG_SIGNATURE and SS_SIGNATURE are 5.18.102's values, carried (docs/72 §G.13).",
         "# lib/NotificationService.php, both of which 5.18.104 changes and the rollback puts back, so it must read 5.18.103's\n"
         "# signatures again: UG_SIGNATURE and SS_SIGNATURE are 5.18.103's values, carried (docs/73 §H.2.15.4)."),
    ], why="event processor")
    doc.mustnot("'ai.reply', 'crm.lead.sync'] : ['ai.reply'];\" <<<")

    # ── B4 — the copy's time mark ──
    assert "lib/NotificationService.php" in changed and "lib/KycService.php" not in changed
    doc.subs([
        ("# The copy's time is the time stamp_copy gives a file this release changes: lib/KycService.php, as 5.18.102 read it —\n"
         "# 5.18.103 changes it too.\nCOPY_MARK=\"lib/KycService.php\"",
         "# The copy's time is the time stamp_copy gives a file this release changes: lib/NotificationService.php since 5.18.104\n"
         "# (lib/KycService.php, 5.18.102's and 5.18.103's mark, is a file 5.18.104 keeps byte for byte, so stamp_copy never\n"
         "# touches it).\nCOPY_MARK=\"lib/NotificationService.php\""),
        ("  # 5.18.97 (its review): the rollback's own copy time, as stamp_copy gave lib/KycService.php (COPY_MARK, as 5.18.102 read it; 5.18.101 off",
         "  # 5.18.97 (its review): the rollback's own copy time, as stamp_copy gave lib/NotificationService.php (COPY_MARK since 5.18.104; 5.18.103 and 5.18.102 off lib/KycService.php, 5.18.101 off"),
        ("  # The copy's own time, as stamp_copy gave lib/KycService.php (COPY_MARK, a file 5.18.103 changes, as 5.18.102 read it):",
         "  # The copy's own time, as stamp_copy gave lib/NotificationService.php (COPY_MARK, a file 5.18.104 changes):"),
    ], why="copy mark")

    # ── B5 — R4's undeployed work ──
    r4_old = ("for f in partner_api.php lib/StaffApiCsrf.php lib/Totp.php lib/DistributorPortalData.php migrations/081_distributor_portal_accounts.sql "
              "migrations/085_wa_media.sql lib/MediaPolicy.php lib/InboundMedia.php lib/MediaWorker.php workers/MediaWorker.php run_media_worker.php lib/Handover.php; do\n")
    listed = r4_old[len("for f in "):-len("; do\n")].split()
    now_ours = [f for f in listed if f in added]
    still = [f for f in listed if f not in added and f not in changed]
    assert now_ours == ["lib/MediaPolicy.php", "lib/InboundMedia.php", "workers/MediaWorker.php", "run_media_worker.php", "lib/Handover.php"], now_ours
    for f in now_ours + ["cron/event_processor.php", "evo_webhook.php", "workers/AiReplyWorker.php"]:
        assert f in added or f in changed, f
    doc.sub(r4_old,
            "# 5.18.104 (root docs/73 §H.3.2): the files of the undeployed work that 5.18.104 adds as its own — "
            + ", ".join(now_ours) + " — left this list: each is\n"
            "# pinned by its sha256 in PIN_SHA256S (A0 in Git, R1 and Q-CA1 installed), the stronger statement. The rest is still\n"
            "# not this release's.\n"
            "for f in " + " ".join(still) + "; do\n", why="R4 list")
    doc.sub("# The AI media layer is code this release does not carry: the event processor names no ai.media, the webhook has no media\n"
            "# branch, the AI worker no media path.\n"
            "grep -qF 'ai.media' \"$DEST/cron/event_processor.php\" 2>/dev/null && R4_BAD=\"$R4_BAD event_processor:ai.media(not-in-this-release)\"\n"
            "grep -qF 'MediaPolicy' \"$DEST/evo_webhook.php\" 2>/dev/null && R4_BAD=\"$R4_BAD evo_webhook:media-branch(not-in-this-release)\"\n"
            "grep -qF 'VoiceTranscription' \"$DEST/workers/AiReplyWorker.php\" 2>/dev/null && R4_BAD=\"$R4_BAD AiReplyWorker:media-layer(not-in-this-release)\"\n",
            "# (Until 5.18.103: \"the AI media layer is code this release does not carry\" — the event processor naming no ai.media,\n"
            "# the webhook no MediaPolicy branch, the AI worker no VoiceTranscription. RETIRED in 5.18.104, whose Part V IS that\n"
            "# layer, behind switches that ship off (root docs/73 §H.3.2, §H.3.5): cron/event_processor.php, evo_webhook.php and\n"
            "# workers/AiReplyWorker.php are each pinned by their sha256 in PIN_SHA256S — A0 in Git, R1 and Q-CA1 installed — and\n"
            "# the event processor's behaviour by UG_PIN_SIGNATURE and SS_SIGNATURE (A6, R13).)\n", why="R4 media readings")
    doc.mustnot("(not-in-this-release)\"\n" + "grep")

    # ── B6 — R7's migration ledger ──
    doc.sub("db_judge() {   # db_judge ID BEFORE NOW WHEN strict|loose — the ledger's last row the same (5.18.98 to 5.18.103 add no migration); the\n",
            "# db_mig104 BEFORE-LEDGER NOW-LEDGER — \"applied\" when NOW is BEFORE with exactly 5.18.104's three migrations added at\n"
            "# the first boot: BEFORE's last row 088 (5.18.97's file, MIG88_MD5) and NOW's MIG104_LAST, MIG104_N rows more, its\n"
            "# checksum the reviewed file's (MIG104_LAST_MD5; each of the three is QF-CA1's to judge by its _schema_checks row);\n"
            "# \"other\" otherwise. The forms are DBS_PHP's: \"<rows>:<last migration>:<first 16 hex of its checksum>\".\n"
            "db_mig104() {\n"
            "  local br bf bc nr nf nc\n"
            "  IFS=: read -r br bf bc <<<\"$1\"; IFS=: read -r nr nf nc <<<\"$2\"\n"
            "  case \"$br$nr\" in ''|*[!0-9]*) echo other; return ;; esac\n"
            "  if [ \"$bf|$bc\" = \"${MIG88#migrations/}|${MIG88_MD5:0:16}\" ] && [ \"$nf|$nc\" = \"$MIG104_LAST|${MIG104_LAST_MD5:0:16}\" ] && [ \"$nr\" = \"$((br + MIG104_N))\" ]; then echo applied; else echo other; fi\n"
            "}\n"
            "db_judge() {   # db_judge ID BEFORE NOW WHEN strict|loose — the ledger's last row the same, or (R7 only) BEFORE with 5.18.104's three migrations applied (db_mig104); the\n",
            why="db_mig104")
    doc.sub('  if [ "$bl" != "$nl" ]; then bad "$1 the migration ledger CHANGED $4 ($bl → $nl; rows:last migration:checksum) — $EXPECTED_VERSION adds no migration, and this run applies none. Send the log file"\n',
            '  local mg="same"; [ "$bl" = "$nl" ] || mg="$( [ "$1" = "R7" ] && db_mig104 "$bl" "$nl" || echo other)"\n'
            '  if [ "$mg" = "other" ]; then bad "$1 the migration ledger CHANGED $4 ($bl → $nl; rows:last migration:checksum) — $( [ "$1" = "R7" ] && echo "$EXPECTED_VERSION adds exactly its $MIG104_N migrations (089, 090, 091), applied at the first boot, and this run applies no other" || echo "a rollback applies no migration and removes none (QF-RB2: 5.18.104\'s tables stay)"). Send the log file"\n',
            why="db_judge ledger")
    doc.sub('  elif [ "$ns" -ge "$bs" ]; then ok "$1 the database $4: the migration ledger\'s last row the same ($nl; rows:last migration:checksum), the file',
            '  elif [ "$ns" -ge "$bs" ]; then ok "$1 the database $4: the migration ledger $( [ "$mg" = "applied" ] && echo "moved by exactly $EXPECTED_VERSION\'s $MIG104_N migrations, applied at the first boot ($bl → $nl; rows:last migration:checksum — QF-CA1 judges each by its _schema_checks row)" || echo "its last row the same ($nl; rows:last migration:checksum)"), the file',
            why="db_judge ok")
    doc.sub("— $EXPECTED_VERSION adds no migration; what a live plugin writes at use is the plugin's own ($EXPECTED_VERSION's keys on Uganda applications among it: R7, QC-RB2), and nothing in this run writes it\"",
            "— $EXPECTED_VERSION adds no migration beyond its three; what a live plugin writes at use is the plugin's own, and nothing in this run writes it but the switch tool's clear where --clear-switches was given (QF-G4)\"",
            why="db_judge ok tail")
    doc.sub("# ── 5.18.95: the database, before and after (R7, RB) — this release adds no migration; this run writes nothing ───────",
            "# ── 5.18.95: the database, before and after (R7, RB) — 5.18.104 adds 089–091 (db_mig104); this run writes nothing else ─")
    doc.sub("# R7 — the database (5.18.95 to 5.18.103): 5.18.103 adds no migration, and its Cashbook reads write nothing to\n",
            "# R7 — the database (5.18.95 to 5.18.104): 5.18.104 adds its three migrations, 089, 090 and 091, applied by the plugin's\n"
            "# own runner at its first boot after the copy (db_mig104: exactly those three rows, the last one 091's reviewed checksum;\n"
            "# QF-CA1 judges each by its _schema_checks row); 5.18.103 added none, and its Cashbook reads write nothing to\n")
    doc.must('db_mig104 "$bl" "$nl"', 1)
