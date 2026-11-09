# partT — what the rehearsal's development trials found in the derived script and the derivation never re-pointed
# (root docs/73 §H.2.15; coordinator items 30–31). Each defect was SEEN in a trial run of
# scripts/harness/deploy-5.18.104/rehearse.sh (T-D1, T-D2: trial 2, 7 Nov; T-D3: trial 4, 8 Nov) and is fixed here by
# an asserted edit; the texts it re-points are read from Git (the live release BASE and the pin), never typed.
#
#   T-D1  R6 — "every earlier surface is installed as before" — read two of 5.18.103's literals that the pin changes on
#         purpose, so every 5.18.104 deploy and --after-only printed "FAIL R6 incomplete: event-processor:lists
#         ai-worker:no-owner-handover" and ended "1 FAILED":
#           · cron/event_processor.php's worker-owned list, which 5.18.104's Part V widens (ai.media, ai.voice_ack);
#           · 5.18.89's owner hand-over (`$alerts->handover(…, $owner)`), which 5.18.104 moves out of
#             workers/AiReplyWorker.php into lib/Handover.php (root docs/73 §H.3.2 (a)), the worker passing its owner in.
#         R6 now reads the pin's list line and, for the hand-over, both halves where they now live. The rollback's own
#         reading of 5.18.88's Batch 1 (RB_REF batch1:event-processor) reads 5.18.103's installed file after the put-back
#         and keeps 5.18.103's line: it is not touched.
#   T-D2  QF-N2's approved drafts by age bound their two limits as TEXT (PDO's execute([...])), and the expression
#         `julianday('now') - julianday(created_at)` has no affinity, so it compares below any TEXT value: every bucket
#         read 0 (the trial's approved draft, 0.03 days old, read '<1d:0'). The limits are cast to REAL in the SQL, so
#         the comparison is numeric whatever the binding.
#   T-D3  Twenty-eight of 5.18.104's own readings of a COPY named no vault. Trial 4's 98 runs, judged by the harness's
#         qfaudit (rh104q.py; the rehearsal's check 10 wants bare=0 in every run), counted them "bare" — QF_PHP's g1, n5,
#         trial, neg, hk7, ca1, ca2, on5; VN_PHP's objects, events, ca4, store, rb, rb5; OF_PHP's pre, post, m1, ca2, ca3,
#         prep4, ca5, rb, rb2; AC_PHP's n2, ca2, onlog, onfiles, rb2: no -e and no putenv of DN_VAULT_FILE, against the
#         script's own ON COPIES rule (5.18.103's: every PHP process run on a copy has its own DN_VAULT_FILE). No live
#         vault was touched (the rehearsal read it unchanged around every run) and none of the 28 loads the plugin's
#         configuration — the book, the pin's migrations through MigrationRunner, OfferSchema's checks, constants, files
#         read as text or copied by path — so none read wrongly; but each fell back to ConfigVault::path()'s default (a
#         file beside the code root that does not exist, or a DN_VAULT_FILE the container itself sets), where a later
#         edit loading the configuration would read a vault-held tenant as absent. Each now names a vault that is never
#         made, in the reading's own folder — 5.18.103's shape for a reading that loads no configuration (notes-, bank-,
#         c-vault-none.json) — by a line in its own source, of the form the four sources already set their copy modes'
#         vaults with (qfaudit's "put"): every call of these modes goes through qf_php, vn_php, of_php or ac_php(_ca2),
#         whose dxo passes no -e, and a line in the source binds every later call too. Asserted: each source's modes are
#         its copy modes, these and its live-by-design modes (the rehearsal's own reading of the calls), disjoint; each
#         folder argument is the one its mode's signature names; no such mode's branch names the configuration's classes.
import re
from d104lib import git

R6_EP_OLD = ('grep -qF "\\$_epWorkerOwned = \\$_epUg ? [\'ai.reply\', \'crm.lead.sync\'] : [\'ai.reply\'];" '
             '"$DEST/cron/event_processor.php" 2>/dev/null || R6_BAD="$R6_BAD event-processor:lists"')
R6_HO_OLD = ("grep -qF '            $sent  = $alerts->handover($convId, $phone, $channel, $reason, $owner);' "
             '"$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:no-owner-handover"')
QN2_OLD = "julianday('now') - julianday(created_at) >= ? AND julianday('now') - julianday(created_at) < ?"
QN2_NEW = "julianday('now') - julianday(created_at) >= CAST(? AS REAL) AND julianday('now') - julianday(created_at) < CAST(? AS REAL)"

# T-D3. Each source's copy modes that already name the copy's own vault (left as they are), and the modes the script calls
# on live files by design — the rehearsal's QFLIVE104, read from the calls by the same pattern (rehearse.sh): the pin's or
# the installed code root, the live plugin folder, the live book, the source root of the live vault's copy, the PHP log.
TD3_COPY = {"qf": ["notes", "ca4", "ca6", "ca8", "on3", "lon"], "vn": ["g1", "g5", "ca1"],
            "of": ["n1", "n3", "ca1", "ca4", "job"], "ac": ["n1", "ca1"]}
TD3_LIVE = ("ac:admins,ac:conf,ac:rb1,of:ledger,of:n2,of:okeys,qf:base,qf:conf,qf:g4,qf:keys,qf:n6,qf:rb3,qf:vault,"
            "vn:g3,vn:k1,vn:ledger,vn:vkeys")
TD3_LIVE_RE = re.compile(r'\b(qf|vn|of|ac)_php ([a-z0-9]+) "(?:\$P3B_PIN|\$\{P3B_PIN:-\$IN_CONTAINER\}|\$IN_CONTAINER|'
                         r'\$PDD_IN/plugin\.sqlite3|\$src|\$inst|\$\(dxo php -r)')
# The 28 modes trial 4 counted bare, by source, grouped by the argument that gives the reading's folder: the DIR, SUB or SRC
# argument where the mode takes one ("rtrim", its index), else the folder holding the first (DB, ROOT, CODE or TREE:
# "dirname"). The vault each names: <that folder>/<source>-<mode>-vault-none.json, a file nothing makes.
TD3_NONE = {
    "qf": [("dirname", 0, ["g1", "ca1", "ca2", "hk7"]), ("rtrim", 0, ["n5", "on5"]), ("rtrim", 1, ["trial", "neg"])],
    "vn": [("dirname", 0, ["objects", "events", "ca4", "rb", "rb5"]), ("rtrim", 0, ["store"])],
    "of": [("dirname", 0, ["pre", "m1", "ca2", "ca3", "ca5", "rb", "rb2"]), ("rtrim", 0, ["prep4"]), ("rtrim", 1, ["post"])],
    "ac": [("dirname", 0, ["n2", "rb2"]), ("rtrim", 2, ["ca2", "onlog"]), ("rtrim", 3, ["onfiles"])],
}
TD3_SIG = {"dirname": {"DB", "ROOT", "CODE", "TREE"}, "rtrim": {"DIR", "SUB", "SRC"}}
# qfaudit's own reading of a source (rh104q.py): the modes whose source sets their own vault first
TD3_QFA = re.compile(r"in_array\(\$mode, \[([^\]]*)\], true\)\)\s*(?:\{\s*\$vd = [^;]*;\s*)?putenv\('DN_VAULT_FILE=")
# where each source sets its copy modes' vault: the line or block the new lines follow
TD3_AFTER = {
    "qf": "if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon'], true)) putenv('DN_VAULT_FILE=' . rtrim((string)($a[1] ?? ''), '/') . '/vault.json');\n",
    "vn": "if (in_array($mode, ['g1', 'g5', 'ca1'], true)) {\n    $vd = rtrim((string)($a[1] ?? ''), '/');\n"
          "    putenv('DN_VAULT_FILE=' . $vd . '/vault.json'); putenv('DN_DATA_DIR=' . $vd);\n}\n",
    "of": "if (in_array($mode, ['n1', 'n3', 'ca1', 'ca4', 'job'], true)) {\n    $vd = rtrim((string)($a[1] ?? ''), '/');\n"
          "    putenv('DN_VAULT_FILE=' . $vd . '/vault.json'); putenv('DN_DATA_DIR=' . $vd);\n}\n",
    "ac": "if (in_array($mode, ['n1', 'ca1'], true)) {\n    $vd = rtrim((string)($a[1] ?? ''), '/');\n"
          "    putenv('DN_VAULT_FILE=' . $vd . '/vault.json'); putenv('DN_DATA_DIR=' . $vd);\n}\n",
}
TD3_WHY = {
    "qf": ("// Every other reading of a COPY loads none of the plugin's configuration — the book alone, the pin's migrations\n"
           "// through MigrationRunner (\"trial\", \"neg\"), the live master log or the pin's tree read as text (\"n5\", \"hk7\") — and\n"
           "// names a vault that is never made, in the reading's own folder, as 5.18.103's readings that load no configuration do\n"
           "// (notes-, bank-, c-vault-none.json; T-D3): nothing it runs can reach ConfigVault::path()'s default, nor a DN_VAULT_FILE\n"
           "// the container itself sets. The folder: the DIR argument where the mode takes one, else the folder holding the\n"
           "// first argument (DB, TREE).\n"),
    "vn": ("// The other modes that read a COPY load none of the plugin's configuration — the book alone (\"events\", \"ca4\", \"rb\",\n"
           "// \"rb5\"; \"objects\" with a code root's 090 through MigrationRunner::splitStatements) or the copy's settings written\n"
           "// (\"store\") — and name a vault that is never made, in the reading's own folder, as 5.18.103's readings that load no\n"
           "// configuration do (T-D3): nothing they run can reach ConfigVault::path()'s default, nor a DN_VAULT_FILE the container\n"
           "// itself sets. The folder: the DIR argument where the mode takes one, else the folder holding the first (DB).\n"),
    "of": ("// The other modes that read a COPY load none of the plugin's configuration — the book alone (\"pre\", \"ca3\", \"rb\",\n"
           "// \"rb2\"), OfferSchema's checks on it (\"post\", \"m1\"), OfferSwitch's and the ladder's constants and master.php as text\n"
           "// (\"ca2\", \"ca5\") or settings files copied by path (\"prep4\") — and name a vault that is never made, in the reading's\n"
           "// own folder, as 5.18.103's readings that load no configuration do (T-D3): nothing they run can reach\n"
           "// ConfigVault::path()'s default, nor a DN_VAULT_FILE the container itself sets. The folder: the DIR or SRC argument\n"
           "// where the mode takes one, else the folder holding the first (DB, ROOT).\n"),
    "ac": ("// The other modes that read a COPY load none of the plugin's configuration — the book alone (\"rb2\"), the book and the\n"
           "// live quote_mail.log read as text (\"n2\"), QuoteAgentCopy's file readers (\"onlog\", \"onfiles\": quote_mail.log and\n"
           "// email_settings.json) or MailService alone (\"ca2\": every drive it spawns is given its own sandbox's vault) — and name\n"
           "// a vault that is never made, in the reading's own folder, as 5.18.103's readings that load no configuration do (T-D3):\n"
           "// nothing they run can reach ConfigVault::path()'s default, nor a DN_VAULT_FILE the container itself sets. The folder:\n"
           "// the DIR or SUB argument where the mode takes one, else the folder holding the first (DB).\n"),
}
# QF_PHP's own comment on its copy modes, and Part F's header, said every reading of a copy reads the copy's vault
TD3_QF_COMMENT_OLD = (
    "// A reading of a COPY reads the copy's own vault — DN_VAULT_FILE, the reading's folder's vault.json (qf_copy's: the live\n"
    "// vault's book keys and the keys the readings keep, never a secret) — and never ConfigVault::path()'s default beside the\n"
    "// code root, which holds nothing: without it a copy whose tenant or instances live only in the vault would read as South\n"
    "// Sudan, or as having no instance (5.18.103 sets DN_VAULT_FILE for every in-process reading). Each of these modes takes\n"
    "// [ROOT, DIR], DIR the reading's folder.\n")
TD3_QF_COMMENT_NEW = (
    "// A reading of a COPY that loads the plugin's configuration reads the copy's own vault — DN_VAULT_FILE, the reading's\n"
    "// folder's vault.json (qf_copy's: the live vault's book keys and the keys the readings keep, never a secret) — and never\n"
    "// ConfigVault::path()'s default beside the code root, which holds nothing: without it a copy whose tenant or instances\n"
    "// live only in the vault would read as South Sudan, or as having no instance (5.18.103 sets DN_VAULT_FILE for every\n"
    "// in-process reading). Each of these modes takes [ROOT, DIR], DIR the reading's folder.\n")
TD3_QF_HDR_OLD = (
    "# the readings keep, never a secret), named by DN_VAULT_FILE in every reading of a copy; and a code root of its own, a\n"
    "# copy of the PIN's code from Git (stage A) or of the INSTALLED code (ICODE_R, after the copy), in the container's /tmp —\n")
TD3_QF_HDR_NEW = (
    "# the readings keep, never a secret), named by DN_VAULT_FILE in every reading of a copy that loads the plugin's\n"
    "# configuration (every other reading of a copy names a vault that is never made, in its own folder: T-D3); and a code\n"
    "# root of its own, a copy of the PIN's code from Git (stage A) or of the INSTALLED code (ICODE_R, after the copy), in\n"
    "# the container's /tmp —\n")
# the pin's libraries the 28 load whole that hold no configuration reader at all (the others' called methods: notes)
TD3_PURE_LIBS = ["lib/StoreInterface.php", "lib/MigrationRunner.php", "lib/OfferSchema.php", "lib/UtcClock.php",
                 "lib/VoiceAckLimits.php"]


def _td3_line(tag, how, i, modes):
    q = ", ".join("'" + m + "'" for m in modes)
    folder = f"dirname((string)($a[{i}] ?? ''))" if how == "dirname" else f"rtrim((string)($a[{i}] ?? ''), '/')"
    return f"if (in_array($mode, [{q}], true)) putenv('DN_VAULT_FILE=' . {folder} . '/{tag}-' . $mode . '-vault-none.json');\n"


def _td3_source(text, tag):
    head = tag.upper() + "_PHP=\"$(cat <<'PHP'\n"
    if text.count(head) != 1:
        raise SystemExit(f"partT T-D3: {tag.upper()}_PHP's heredoc found {text.count(head)} times")
    a = text.index(head) + len(head)
    return text[a:text.index("\nPHP\n", a)]


def _td3_cases(src, tag):
    """mode -> (its signature's argument words, its branch with comments removed)"""
    cs = [(m.start(), m.group(1)) for m in re.finditer(r"^    case '([a-z0-9]+)':", src, re.M)]
    out = {}
    for k, (at, mode) in enumerate(cs):
        body = src[at:cs[k + 1][0] if k + 1 < len(cs) else len(src)]
        first = body.split("\n", 1)[0]
        sig = re.search(r"// " + re.escape(mode) + r" ([^—]*) —", first)
        if mode in out:
            raise SystemExit(f"partT T-D3: {tag}:{mode} has two case lines")
        code = "\n".join(l for l in body.split("\n") if not l.strip().startswith("//"))
        out[mode] = (sig.group(1).split() if sig else None, re.sub(r"//[^\n']*$", "", code, flags=re.M))
    return out


def _one_line(text, start, where):
    hits = [l for l in text.split("\n") if l.startswith(start)]
    if len(hits) != 1:
        raise SystemExit(f"partT: expected one line starting {start!r} in {where}, found {len(hits)}")
    return hits[0]


def _one_exact(text, line, where):
    c = text.split("\n").count(line)
    if c != 1:
        raise SystemExit(f"partT: expected the line {line!r} exactly once in {where}, found {c}")


def apply(doc, ctx):
    rel, p, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    show = lambda c, f: git(rel, "show", f"{c}:{p}/{f}").decode()

    # ── T-D1, the event processor's list: 5.18.103's line gone at the pin, the pin's own line read from it ─────────────
    ep_b, ep_p = show(base, "cron/event_processor.php"), show(pin, "cron/event_processor.php")
    old_list = "$_epWorkerOwned = $_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];"
    _one_exact(ep_b, old_list, "5.18.103's cron/event_processor.php")
    if old_list in ep_p.split("\n"):
        raise SystemExit("partT: the pin still holds 5.18.103's worker-owned list — R6 needs no change there")
    new_list = _one_line(ep_p, "$_epWorkerOwned = ", "the pin's cron/event_processor.php")
    if "'ai.media'" not in new_list or "'ai.voice_ack'" not in new_list or '"' in new_list:
        raise SystemExit(f"partT: the pin's worker-owned list is not the one Part V widens: {new_list!r}")
    ep_new = ('grep -qF "' + new_list.replace("\\", "\\\\").replace("$", "\\$") + '" '
              '"$DEST/cron/event_processor.php" 2>/dev/null || R6_BAD="$R6_BAD event-processor:lists"')
    doc.sub(R6_EP_OLD, ep_new, 1, why="T-D1 R6: the pin's worker-owned list (Part V adds ai.media, ai.voice_ack)")

    # ── T-D1, the owner hand-over: moved to lib/Handover.php, the worker passing the line's owner ─────────────────────
    wk_b, wk_p, ho_p = show(base, "workers/AiReplyWorker.php"), show(pin, "workers/AiReplyWorker.php"), show(pin, "lib/Handover.php")
    old_ho = "            $sent  = $alerts->handover($convId, $phone, $channel, $reason, $owner);"
    _one_exact(wk_b, old_ho, "5.18.103's workers/AiReplyWorker.php")
    if "$alerts->handover(" in wk_p:
        raise SystemExit("partT: the pin's AiReplyWorker still calls AlertService::handover itself")
    new_ho = _one_line(ho_p, "            $sent = $alerts->handover(", "the pin's lib/Handover.php")
    if not new_ho.endswith("$reason, $owner);"):
        raise SystemExit(f"partT: the pin's hand-over does not pass the owner: {new_ho!r}")
    pass_owner = "            $this->handoverOwner($channel));"
    _one_exact(wk_p, pass_owner, "the pin's workers/AiReplyWorker.php")
    for s in (new_ho, pass_owner):
        if "'" in s:
            raise SystemExit(f"partT: a quote in a line R6 reads in single quotes: {s!r}")
    ho_new = ("grep -qF '" + new_ho + "' \"$DEST/lib/Handover.php\" 2>/dev/null && grep -qF '" + pass_owner + "' "
              '"$DEST/workers/AiReplyWorker.php" 2>/dev/null || R6_BAD="$R6_BAD ai-worker:no-owner-handover"   '
              "# 5.18.104 moved 5.18.89's owner hand-over into lib/Handover.php (root docs/73 §H.3.2 (a))")
    doc.sub(R6_HO_OLD, ho_new, 1, why="T-D1 R6: the owner hand-over where 5.18.104 put it")
    doc.must(old_list.replace("$", "\\$"), 1, why="T-D1: the rollback's Batch 1 reading of 5.18.103's list kept")
    doc.must('RB_REF="$RB_REF batch1:event-processor"', 1, why="T-D1: the rollback's Batch 1 reading kept")

    # ── T-D2, QF-N2's limits compared as numbers ─────────────────────────────────────────────────────────────────────
    doc.sub(QN2_OLD, QN2_NEW, 1, why="T-D2 QF-N2: the age limits cast to REAL (a no-affinity expression against TEXT binds)")
    doc.mustnot(">= ? AND julianday('now')", why="T-D2: no text-bound age limit left")

    # ── T-D3, every reading of a COPY names a vault of its own: one that is never made where it loads no configuration ──
    _td3(doc, show, pin)


def _td3(doc, show, pin):
    for f in TD3_PURE_LIBS:   # read from Git: what "trial", "neg", "objects", "post" and "m1" load whole reads no configuration
        hits = [w for w in ("ConfigVault", "PluginConfig", "TenantProfile", "getenv(") if w in show(pin, f)]
        if hits:
            raise SystemExit(f"partT T-D3: the pin's {f} names {hits}: a mode loading it could read the configuration")
    doc.sub(TD3_QF_HDR_OLD, TD3_QF_HDR_NEW, 1, why="T-D3: Part F's header — the copy's vault, where the configuration is loaded")
    doc.sub(TD3_QF_COMMENT_OLD, TD3_QF_COMMENT_NEW, 1, why="T-D3: QF_PHP's comment on the modes that read the copy's vault")
    for tag, groups in TD3_NONE.items():
        lines = "".join(_td3_line(tag, how, i, modes) for how, i, modes in groups)
        doc.insert_after(TD3_AFTER[tag], TD3_WHY[tag] + lines, 1,
                         why=f"T-D3: {tag.upper()}_PHP's copy modes that load no configuration name a vault never made")
        doc.must(TD3_AFTER[tag] + TD3_WHY[tag] + lines, 1, why=f"T-D3: {tag.upper()}_PHP's lines right after its copy modes' vault")
        for how, i, modes in groups:
            doc.must(_td3_line(tag, how, i, modes), 1, why=f"T-D3: {tag} {modes}")
    doc.must("-vault-none.json');\n", sum(len(g) for g in TD3_NONE.values()), why="T-D3: one line per group, nowhere else")
    # The result, read as the rehearsal reads it: the live-by-design modes from the calls (QFLIVE104's pattern), and in each
    # source qfaudit's "own" modes — exactly its copy modes and these, each in one list, none live — and every mode is one
    # of the three. Each folder argument is the one the mode's own signature names; no such mode's branch names the
    # configuration's classes.
    live = sorted(set(f"{t}:{m}" for t, m in TD3_LIVE_RE.findall(doc.t)))
    if live != sorted(TD3_LIVE.split(",")):
        raise SystemExit(f"partT T-D3: the live-by-design modes read from the calls are {live}, not {TD3_LIVE}")
    for tag, groups in TD3_NONE.items():
        src = _td3_source(doc.t, tag)
        cases = _td3_cases(src, tag)
        lists = [set(re.findall(r"'([a-z0-9_]+)'", mm.group(1))) for mm in TD3_QFA.finditer(src)]
        own = set().union(*lists)
        none = {m for _, _, ms in groups for m in ms}
        lv = {x.split(":")[1] for x in live if x.startswith(tag + ":")}
        if len(lists) != 1 + len(groups) or sum(len(l) for l in lists) != len(own) or own != set(TD3_COPY[tag]) | none:
            raise SystemExit(f"partT T-D3: {tag.upper()}_PHP's own vault lists read {lists}, not its copy modes and {sorted(none)}")
        if own & lv or set(cases) != own | lv:
            raise SystemExit(f"partT T-D3: {tag.upper()}_PHP's modes {sorted(cases)} are not its own ({sorted(own)}) and its "
                             f"live ones ({sorted(lv)}), disjoint")
        for how, i, modes in groups:
            for m in modes:
                sig, code = cases[m]
                folders = [k for k, w in enumerate(sig or []) if w in TD3_SIG["rtrim"]]
                want = (folders[:1] == [i]) if how == "rtrim" else (not folders and i == 0 and sig[0] in TD3_SIG["dirname"])
                if not sig or not want:
                    raise SystemExit(f"partT T-D3: {tag}:{m}'s signature {sig} does not give its folder as {how}($a[{i}])")
                bad = [w for w in ("PluginConfig", "ConfigVault", "TenantProfile", "StaffJobsGate") if w in code]
                if bad:
                    raise SystemExit(f"partT T-D3: {tag}:{m}'s branch names {bad} — it would read an absent vault")
    for l in doc.t.split("\n"):
        if "vault-none.json" in l and not l.lstrip().startswith(("#", "//")) and "DN_VAULT_FILE" not in l:
            raise SystemExit(f"partT T-D3: a line names a vault-none file other than as a DN_VAULT_FILE: {l[:120]!r}")
