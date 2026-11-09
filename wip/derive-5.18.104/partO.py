# Part O — 5.18.104's offers checks, Part O's codes OF (root docs/73 §H.4.18, §H.4.19; §H.4.6, §H.4.15, §H.4.20-§H.4.22;
# rulings R6, R7, R12, R13, R17, R18). Stage DS3. Registered after partV, it fills the slots partF and partR left (OF-G stage
# A, baseline, OF-CA, OF-ON, OF-RB1, OF-RB2), inserts its own probe (of_probe.php.txt) and functions (of_funcs.sh.txt)
# after partV's at the same anchor, and makes four small asserted edits to DS1's text that its codes need and that change
# no QF or VN verdict:
#   - QF-G2 takes OF-G1's and OF-G2's census of its trial copy just before its trial (of_g2_pre) and, once the trial and
#     the pin's doctor have run there, the census again with OfferSchema::check(), OF-N1's and OF-N3's readings on the same
#     copy (of_g2_post): the one stage-A trial for every part (ruling R10) is the only copy on which 091 is applied, so the
#     pin's OfferAudience::build(), which reads 091's tables, can run at all;
#   - QF-G1's baseline call writes, in the same PHP process and before the copy's start is stamped, OF-CA3's baseline to a
#     file of the run's folder (D §H.4.18 OF-G1; §H.2.15.1 "Part O's OF-G1 and Part V's VN-G6 take their baselines at this
#     one moment"), as Part V's VN-CA4's already is — QF_BASE's line is unchanged, so no largest id reaches the log;
#   - the call names the file (of_base_prep before it);
#   - the header, the options, the names and the steps' line name the OF codes.
# Every value the fragments carry from the release is read off Git here and asserted (OfferSwitch's keys, ruling R1's four
# lines in OfferSwitch::masterLines() and at the end of master's $_m_jobs, OfferSchema's tables and count against 091's
# _schema_checks INSERT, 091's md5, the tab's two fixed answers, the menu block and its sha256 by the same awk the script
# runs, the job's order of live() before the sender, the ladder's two rows, the hook's counts, the audience's list codes
# and which of them read 089–091 objects, the echo, test and ledger literals OF-CA3 reads, the R-01 log line, and the tool's
# lines OF-RB1 reads); and Part O's four by-hand statements (ruling R17), printed by DS1's qf_byhand, are asserted equal to
# D §H.4.19's and executed here, in Python's sqlite3, against 091's own tables, CHECKs and triggers, beside two weakened
# statements that 091 must refuse (the control on the control).
# DS3-fix1 (fix()): (R01) the run's folder in the container's /tmp is made in the MAIN shell by every part's entry —
# qf_stage, qf_after, qf_on, qf_rb1, qf_rb3 call cih_main first — and never inside $( … ): qf_copy and vn_book_copy, which
# always run in a command substitution, refuse when it is not there instead of calling cih_init (a folder made in a
# subshell left CIH_BASE empty out here, and every later cih_init was refused by its own mkdir — in a --rollback, where
# nothing before QF-RB1 made the folder, that broke VN-RB3/VN-RB4, OF-RB2, TN's RB and RB's cash in hand); the
# derivation's folder check (d104lib.folder_check, run by derive104.py on the whole result) proves it on every path, with
# its control on the control. (R02) OF-RB1's test clause is bounded by QF-RB1's start (QF_RB1_START, taken before the
# tool runs): a test still sending that started before it less OfferSwitch::STALE_S (from Git) is one the hooks had to
# settle; never "now" less 15 minutes, which would fail over a test the hook rightly left (ruling R18).
import os, re, hashlib, subprocess, sqlite3
from d104lib import git, md5_at, fill

HERE = os.path.dirname(os.path.abspath(__file__))
TN_ANCHOR = "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────\n"
D_NAME = "docs/73-uganda-sales-follow-up-5-18-104-design-2026-11-04.md"
# Ruling R1's four lines (root docs/73 §R1, D 183-186), each as OfferSwitch::masterLines() holds it (trimmed)
R1_LINES = [
    "'quote_chase'      => ['interval' => 540, 'uganda' => true, 'flag' => 'quote_tracking_enabled',   'script' => __DIR__ . '/quote_chase.php'],",
    "'quote_daily_list' => ['interval' => 240, 'uganda' => true, 'flag' => 'quote_daily_list_enabled', 'script' => __DIR__ . '/quote_daily_list.php'],",
    "'ai_media'         => ['interval' => 60,  'uganda' => true, 'flag' => 'ai_media_enabled',         'script' => dirname(__DIR__) . '/run_media_worker.php'],",
    "'offers_send'      => ['interval' => 240, 'uganda' => true, 'flag' => 'offers_live_enabled',      'script' => __DIR__ . '/offers_send.php'],",
]
SETTINGS6 = ["offers_warm_days", "offers_recent_days", "offers_daily_max_per_handset", "offers_on_owned_numbers",
             "offers_stop_pause_pct", "offers_max_days"]
P6 = ["wa_offers", "wa_offer_recipients", "wa_offer_tests", "wa_offer_events"]
# The functions every OF reading runs without (of_php): curl, sockets and processes — so no reading can reach Evolution
OF_NONET = ("curl_init,curl_exec,curl_multi_init,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,"
            "socket_connect,proc_open,popen,exec,shell_exec,system,passthru")
LIST_CODES = ["opted_out", "offer_stop", "stop_words", "recent_voice_note", "unread_inbound", "colleague", "dishnet_number",
              "partner_contact", "quote_hold", "followup_open", "followup_recent", "recent_offer", "person_handling",
              "escalation_words", "ambiguous_identity"]
# OF-N1 (D §H.4.18): "Exclusions that need 089 or 090 objects read as `not measured before 089`" — the reviewed reading of
# the pin's OfferAudience::lists(), asserted below against its text: every source new in 089–091 (WHOLE), or one such
# source beside pre-existing ones (PART: counted on those alone, and said so)
WHOLE = ["offer_stop", "unread_inbound", "recent_offer"]
PART = ["followup_recent", "quote_hold", "person_handling"]


def put(text, key, value, n=1):
    if text.count(key) != n:
        raise SystemExit(f"OF PLACEHOLDER COUNT: {key} found {text.count(key)}, expected {n}")
    out = text.replace(key, value)
    if key in out:
        raise SystemExit(f"OF PLACEHOLDER RESIDUE: {key}")
    return out


def once(src, s, what):
    if src.count(s) != 1:
        raise SystemExit(f"OF: {what} has {s[:100]!r} {src.count(s)} times, expected 1")


def tables_of(sql):
    return set(re.findall(r"\b(?:FROM|JOIN)\s+\[?([a-z_][a-z0-9_]*)\]?", sql))


def awk(prog, text):
    r = subprocess.run(["awk", prog], input=text.encode(), capture_output=True)
    if r.returncode != 0:
        raise SystemExit(f"OF: awk failed: {r.stderr.decode()[:200]}")
    return r.stdout.decode()


def shell_awk(funcs, name):
    """The awk program of the shell function NAME in of_funcs.sh.txt (its one awk '…' word), as the script runs it."""
    m = re.search(r"^" + re.escape(name) + r"\(\) \{\n  awk '(.*?)'\n\}\n", funcs, re.S | re.M)
    if not m:
        raise SystemExit(f"OF: the awk of {name}() not found in of_funcs.sh.txt")
    return m.group(1)   # passed to awk as the shell passes its single-quoted word: \047 stays for awk to read


def apply(doc, ctx):
    repo, plugin, pin, base = ctx["repo"], ctx["plugin"], ctx["pin_full"], ctx["base_full"]
    show = lambda p, c=pin: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── OfferSwitch, as built (B7, B8): its keys, its four callables' shape, ruling R1's lines, the hook's counts ──
    osw = show("lib/OfferSwitch.php")
    for s in ["const SWITCHES = ['offers_test_enabled', 'offers_live_enabled'];", "const LIVE_SINCE = 'offers_live_since';",
              "return array_merge(self::SWITCHES, [self::LIVE_SINCE], array_keys(self::SETTINGS));",
              "public static function screen(array $config, ?string $dataDir): bool",
              "public static function live(array $config, ?string $dataDir): bool",
              "public static function masterCheck(string $pluginRoot): string",
              "public static function offHook(\\PDO $rw, array $config, string $dataDir, string $step, string $why): array",
              "$out = ['paused' => 0, 'settled_uncertain' => 0, 'already_settled' => 0, 'requeued' => 0, 'tests_uncertain' => 0];",
              "$act = $why === 'rollback' ? 'paused_by_rollback' : 'paused_by_switch';",
              "$rw->prepare(\"UPDATE wa_offers SET state = 'paused', updated_at = ? WHERE id = ? AND state = 'running'\");",
              "$ledger->settle($h, 'failed', ['maybe_sent' => true]);",
              "$st = $rw->prepare(\"UPDATE wa_offer_recipients SET state = 'queued', not_before = ? WHERE id = ? AND state = 'claimed'\");"]:
        once(osw, s, "lib/OfferSwitch.php")
    # OF-RB1 (DS3-fix1 R02): the hook makes uncertain every test sending since before its own moment less STALE_S
    stale = re.findall(r"^    const STALE_S = (\d+);", osw, re.M)
    if stale != ["900"]:
        raise SystemExit(f"OF-RB1: OfferSwitch::STALE_S reads {stale}; the script's words say 15 minutes (900)")
    for s in ["            $now = time();\n            $at = UtcClock::stamp($now);\n",
              "$st = $rw->prepare(\"UPDATE wa_offer_tests SET state = 'uncertain', settled_at = ? WHERE state = 'sending' AND started_at < ?\");\n"
              "                $st->execute([$at, UtcClock::stamp($now - self::STALE_S)]);"]:
        once(osw, s, "lib/OfferSwitch.php")
    blk = re.search(r"const SETTINGS = \[(.*?)\];", osw, re.S)
    if not blk or re.findall(r"'([a-z_]+)'\s*=>", blk.group(1)) != SETTINGS6:
        raise SystemExit("OF: OfferSwitch::SETTINGS is not the six reviewed settings")
    ml = re.search(r"public static function masterLines\(\): array\n    \{\n        return \[\n(.*?)\n        \];", osw, re.S)
    got = re.findall(r'^\s*"(.*)",$', ml.group(1), re.M) if ml else []
    if got != R1_LINES:
        raise SystemExit(f"OF-CA2: OfferSwitch::masterLines() is not ruling R1's four lines ({got})")
    import partV
    if partV.R1_AI_MEDIA.strip() != R1_LINES[2]:
        raise SystemExit("OF-CA2: Part V's ruling R1 ai_media line and Part O's are not the same text")
    # master at the pin: the last four job lines of $_m_jobs are R1's, 'offers_send' once — by Python and by the script's awk
    funcs_raw = open(os.path.join(HERE, "of_funcs.sh.txt"), encoding="utf-8").read()
    master = show("cron/master.php")
    if master.count("'offers_send'") != 1:
        raise SystemExit("OF-CA2: master.php names 'offers_send' other than once")
    a = master.index("\n$_m_jobs = [") + 1
    e = master.index("\n];", a)
    lines = []
    for i, l in enumerate(master[a:e].split("\n")):
        t = l.strip()
        if i == 0 or t == "" or t.startswith(("//", "#", "/*", "*")):
            continue
        lines.append(t)
    if lines[-4:] != R1_LINES:
        raise SystemExit("OF-CA2: master.php's $_m_jobs does not end with ruling R1's four lines")
    mo = awk(shell_awk(funcs_raw, "of_master"), master)
    if mo.split("\n")[:4] != R1_LINES or "offers_send_count=1" not in mo.split("\n"):
        raise SystemExit(f"OF-CA2: the script's of_master reads the pin's master as {mo[:300]!r}")

    # ── 091, OfferSchema, and QF's _schema_checks count ──
    m91 = show("migrations/091_wa_offers.sql")
    sch = show("lib/OfferSchema.php")
    once(sch, "const EXPECTED = 25;", "lib/OfferSchema.php")
    once(sch, "const TABLES = ['wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events'];", "lib/OfferSchema.php")
    once(sch, "public static function check(\\PDO $pdo): array", "lib/OfferSchema.php")
    sc91 = [s.split(":")[1] for s in ctx["qf_sc"].split() if s.startswith("091_wa_offers.sql:")]
    if sc91 != ["25"]:
        raise SystemExit(f"OF: 091's _schema_checks count is {sc91}, OfferSchema::EXPECTED 25")
    if re.findall(r"^CREATE TABLE IF NOT EXISTS (wa_offer[a-z_]*) \(", m91, re.M) != P6:
        raise SystemExit("OF: 091 does not create exactly the four P6 tables, in OfferSchema's order")
    md5 = md5_at(repo, pin, f"{plugin}/migrations/091_wa_offers.sql")

    # ── the tab's two fixed answers (OF-CA1), and the menu block (its sha256 by the script's own awk) ──
    tab = show("tabs/engage/wa_offers.php")
    cards = re.findall(r"^    echo '(<div class=\"wo-card\"[^']*>Offers are not switched on here\.</div>)';$", tab, re.M)
    admins = re.findall(r"^    echo '(<div class=\"alert alert-danger\">Admin access required\.</div>)';$", tab, re.M)
    if len(cards) != 1 or len(admins) != 2:
        raise SystemExit(f"OF-CA1: the tab's dark card ({len(cards)}) or its admin alert ({len(admins)}, two expected) is not as reviewed")
    i_admin, i_screen, i_card, i_reread = (tab.index("if (!($retailer['is_admin'] ?? false)) {"), tab.index("if (!OfferSwitch::screen($_woCfg, $_woData)) {"),
                                            tab.index(cards[0]), tab.index("$_woMe = (int)($retailer['id'] ?? 0) > 0 ? $store->findOne("))
    if not (i_admin < i_screen < i_card < i_reread) or "$store" in tab[:i_reread]:
        raise SystemExit("OF-CA1: the tab does not refuse a non-admin, then answer its card on screen() off, before it touches $store")
    if re.search(r"^\s*(function|final class|class)\s", tab, re.M):
        raise SystemExit("OF-CA1: the tab declares a function or class (it is included three times in one process)")
    nav = show("includes/navigation.php")
    nb = awk(shell_awk(funcs_raw, "of_nav_block"), nav)
    nc = awk(shell_awk(funcs_raw, "of_nav_counts"), nav).strip()
    if nc != "blocks=1 tabs=1/1 screen=1/1" or not nb.endswith("<?php endif; ?>\n"):
        raise SystemExit(f"OF-CA1: the pin's menu block reads {nc!r}")
    nbl = nb.split("\n")
    try:
        k_if = nbl.index("if (!empty($config['offers_test_enabled'])) {")
        k_as = next(i for i, l in enumerate(nbl) if l.startswith("    $_wo104Nav = OfferSwitch::screen("))
        k_dr = nbl.index("if ($_wo104Nav): ?>")
        k_ln = next(i for i, l in enumerate(nbl) if "tab=wa_offers" in l)
    except (ValueError, StopIteration):
        raise SystemExit("OF-CA1: the pin's menu block is not the reviewed shape")
    if not (k_if < k_as < k_dr < k_ln) or nbl[k_as + 1] != "}":
        raise SystemExit("OF-CA1: the pin's offers link is not drawn inside OfferSwitch::screen(")
    nav_sha = hashlib.sha256(nb.encode()).hexdigest()

    # ── the job (OF-CA4): live() decides before the sender is loaded or any store opened; its data folder from ucrm.json ──
    job = show("cron/offers_send.php")
    for s in ["if (PHP_SAPI !== 'cli') return;", "$__offersDeadline = isset($_m_deadline) ? (int)$_m_deadline : null;",
              "    $dataDir = getDataDir($root);", "    $config  = PluginConfig::load($root, $dataDir);",
              "    if (!OfferSwitch::live($config, $dataDir)) return;            // before any store is opened",
              "    require_once $root . '/lib/OfferSender.php';"]:
        once(job, s, "cron/offers_send.php")
    if job.index("if (!OfferSwitch::live(") > job.index("require_once $root . '/lib/OfferSender.php';") or "SqliteStore::create" in job:
        raise SystemExit("OF-CA4: the job loads the sender before live(), or opens a store itself")
    bd = show("lib/bootstrap_data.php")
    once(bd, "    foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $ucrmJsonPath) {", "lib/bootstrap_data.php")

    # ── the ladder's two rows and the tool's lines OF-RB1 and OF-ON read ──
    lad = show("lib/SalesFollowupLadder.php")
    for s in ["'pre' => ['O'], 'check' => ['offers-test'], 'clears' => ['offers-live'], 'hook' => ['O', 'before', 'always'], 'locks' => [], 'noskip' => false],",
              "'pre' => ['O'], 'check' => ['offers-live'], 'clears' => [], 'hook' => ['O', 'before', 'always'], 'locks' => [], 'noskip' => false],",
              "    const SEQUENCE = ['offers-live', 'offers-test', 'cold', 'chase',",
              "            case 'O': return \\OfferSwitch::offHook($rw, $config, $dataDir, $step, $why);"]:
        once(lad, s, "lib/SalesFollowupLadder.php")
    if sorted(re.findall(r"^        '(offers_[a-z_]+)'\s+=> \['type' => 'int', [^\n]*'part' => 'O'", lad, re.M)) != sorted(SETTINGS6):
        raise SystemExit("OF: the ladder's Part O settings are not the six")
    tool = show("tools/sales_followup.php")
    for s in ["if ($rollback && $hook[0] === 'O' && !sf_assert_offers($ro)) { $rc = 3; $stop = true; break; }",
              "sf_out('FAIL rollback_assert offers — running ' . $run . ', claimed ' . $cl);",
              "if ($cmd === 'show') { sf_out('not this install'); return 0; }",
              "sf_out('  hook ' . $step . ': ' . implode(' ', $p));", "sf_out('OFF ' . $name);",
              "foreach (array_values(array_unique($wasOn)) as $k) sf_out('was on: ' . $k);"]:
        once(tool, s, "tools/sales_followup.php")
    if tool.index("if ($hook !== null && $hook[1] === 'before') {") > tool.index("sf_out('OFF ' . $name);"):
        raise SystemExit("OF-RB1: the tool does not run a 'before' hook before it prints a step's OFF line")

    # ── the audience (OF-N1): its list codes, and which of them read 089–091 objects, from the pin's text ──
    aud = show("lib/OfferAudience.php")
    lc = re.search(r"const LIST_CODES = \[(.*?)\];", aud, re.S)
    if not lc or re.findall(r"'([a-z_]+)'", lc.group(1)) != LIST_CODES:
        raise SystemExit("OF-N1: OfferAudience::LIST_CODES is not the reviewed fifteen")
    once(aud, "public static function build(\\PDO $pdo, array $config, ?string $dataDir, array $retailerRows, array $storedConfig, string $size,", "lib/OfferAudience.php")
    once(aud, "public static function storedRows(\\PDO $pdo): array", "lib/OfferAudience.php")
    once(aud, "return ['eligible' => $cut, 'queued' => count($cut), 'all_eligible' => count($eligible), 'left_out' => $left,", "lib/OfferAudience.php")
    new_tables = set()
    for m in ctx["migrations"]:
        new_tables |= set(re.findall(r"^CREATE TABLE IF NOT EXISTS ([a-z_]+) \(", show(m), re.M))
    if not {"quote_tracker", "wa_auto_sends", "wa_seen_marks", "wa_offer_recipients"} <= new_tables:
        raise SystemExit(f"OF-N1: 089–091's tables read {sorted(new_tables)}")
    # each $col('<code>', '<sql>' …) read of lists(): the tables it names
    cols = {}
    for code, sql in re.findall(r"\$col\('([a-z_]+)', (\"[^\"]*\"|'[^']*')", aud):
        cols.setdefault(code, set()).update(tables_of(sql))
    want_cols = {"opted_out": {"contact_optouts"}, "offer_stop": {"wa_offer_recipients"}, "unread_inbound": {"wa_seen_marks"},
                 "colleague": {"wa_conversations"}, "partner_contact": {"dist_contacts"}, "followup_open": {"followups"},
                 "followup_recent": {"followup_sends", "wa_auto_sends"}, "ambiguous_identity": {"wa_conversations"}}
    if cols != want_cols:
        raise SystemExit(f"OF-N1: lists()'s reads are {cols}, reviewed {want_cols}")
    li = aud.index("public static function lists(")
    lbody = aud[li:aud.index("\n    }\n", li)]
    ro = re.search(r'\$st = \$pdo->prepare\("SELECT id, phone FROM (wa_offer_recipients) WHERE phone IS NOT NULL', lbody)
    sv = re.search(r'\$st = \$pdo->prepare\("SELECT c\.phone, m\.body, m\.media_type FROM (wa_messages) m JOIN (wa_conversations) c', lbody)
    if not ro or not sv or "QuoteChase::offerHolds($pdo, $at)" not in lbody or "HumanTakeover::phonesSince($pdo," not in lbody:
        raise SystemExit("OF-N1: lists()'s recent-offer, inbound, hold or person read is not as reviewed")
    qc, ht = show("lib/QuoteChase.php"), show("lib/HumanTakeover.php")
    hs = qc[qc.index("function holdSets("):]
    hs = hs[:hs.index("\n    }\n")]
    pso = ht[ht.index("public static function phonesSince("):]
    pso = pso[:pso.index("\n    }\n")]
    for code, srcs in [("quote_hold", tables_of(hs)), ("person_handling", tables_of(pso))]:
        if not (srcs & new_tables) or not (srcs - new_tables):
            raise SystemExit(f"OF-N1: {code}'s sources {sorted(srcs)} are not part new, part pre-existing")
    whole = sorted(c for c, t in cols.items() if t and t <= new_tables) + ["recent_offer"]
    part = sorted([c for c, t in cols.items() if t & new_tables and t - new_tables] + ["quote_hold", "person_handling"])
    if sorted(whole) != sorted(WHOLE) or part != sorted(PART) or (set(WHOLE) | set(PART)) - set(LIST_CODES):
        raise SystemExit(f"OF-N1: the not-measured codes read {whole} / {part}, reviewed {WHOLE} / {PART}")
    if {sv.group(1), sv.group(2)} & new_tables:
        raise SystemExit("OF-N1: the inbound read names an 089–091 table")
    sfc = show("lib/SalesFollowupChecks.php")
    once(sfc, "public static function handsetLabels(array $config): array", "lib/SalesFollowupChecks.php")

    # ── what OF-CA3 reads: the echo, test and ledger literals and their stamps (ruling R18) ──
    gw, snd, ewg = show("lib/EvolutionOfferGateway.php"), show("lib/OfferSender.php"), show("lib/EvoWebhookGuard.php")
    once(ewg, "\"INSERT INTO evo_webhook_seen (message_id, instance, event, received_at)\n                 VALUES (?, ?, ?, datetime('now'))\"", "lib/EvoWebhookGuard.php")
    once(gw, "$echo = $this->guard()->claim($id, $this->evo->instanceFor($m->channel), $m->echoEvent) ? 'claimed' : 'late';", "lib/EvolutionOfferGateway.php")
    for s in ["'offer.send', ContactOptOut::CLASS_PROACTIVE));", "$image, 'offer.test', ContactOptOut::CLASS_STAFF));",
              "$ledger = $budget->record($handset, 'other', 'offer_test:' . $tid, $sent ? 'sent' : 'failed',",
              "->execute([$offerId, (string)$o['content_hash'], $variant, (int)($adminRow['id'] ?? 0), $channel, $handset, UtcClock::stamp($t0)]);"]:
        once(snd, s, "lib/OfferSender.php")
    if snd.count("$ref = 'offer:' . $offerId . ':' . $id;") != 1 or snd.count("->claim((string)$row['handset'], 'offer', $ref,") != 1:
        raise SystemExit("OF-CA3: the sender's offer claim (kind offer, ref offer:<offer>:<recipient>) is not as reviewed")
    once(show("lib/UtcClock.php"), "return gmdate('Y-m-d H:i:s', $time ?? time());", "lib/UtcClock.php")

    # ── OF-N2: the R-01 line the live webhook (5.18.103) and the pin's write; OF-N3: the keys the copy keeps ──
    r01 = "error_log(sprintf('[evo_webhook] no text to answer — %s message stored, AI not queued (%s)',"
    once(show("evo_webhook.php", base), r01, "5.18.103's evo_webhook.php")
    once(show("evo_webhook.php"), r01, "the pin's evo_webhook.php")
    pre = re.search(r"const PREEXISTING = \[(.*?)\];", lad, re.S)
    prek = set(re.findall(r"'([a-z_]+)'", pre.group(1))) if pre else set()
    qp = ctx["qf_probe"]
    n1k = set(re.findall(r"'([a-z_]+)'", re.search(r"\$n1 = \[(.*?)\];", qp, re.S).group(1)))
    for k in ["multi_number_channels_enabled", "wa_human_cooldown_minutes", "reminder_hour", "reminder_until_hour", "timezone"]:
        if k not in prek | n1k:
            raise SystemExit(f"OF-N3: {k} is not among the keys a reading's copy keeps")
    once(show("lib/StaffDirectory.php"), "public static function phoneOf(array $row, TenantProfile $tenant): ?string", "lib/StaffDirectory.php")
    once(show("lib/StaffDirectory.php"), "public static function isActive(array $row): bool", "lib/StaffDirectory.php")

    # ── the fragments, filled ──
    probe = open(os.path.join(HERE, "of_probe.php.txt"), encoding="utf-8").read()
    for k, v in [("@@OF_NEW_WHOLE@@", ",".join(WHOLE)), ("@@OF_NEW_PART@@", ",".join(PART)), ("@@OF_CARD@@", cards[0]),
                 ("@@OF_ADMIN@@", admins[0]), ("@@OF_STALE_S@@", stale[0])]:
        if "'" in v or "\\" in v:
            raise SystemExit(f"OF: {k}'s value cannot travel in a single-quoted PHP string")
        probe = put(probe, k, v)
    if "\nPHP\n" in "\n" + probe + "\n" or "@@" in probe:
        raise SystemExit("OF: the probe holds a line PHP (which would end its heredoc) or a placeholder")
    # OF-RB1's test clause (DS3-fix1 R02): bounded by QF-RB1's start less STALE_S, never by the copy's "now"
    rbm = probe[probe.index("    case 'rb':"):probe.index("    case 'rb2':")]
    if ("datetime('now'" in rbm or rbm.count("started_at < ?\", [$cut]);") != 1 or rbm.count("started_at >= ?\", [$cut]);") != 1
            or rbm.count("$cut = gmdate('Y-m-d H:i:s', (int)$st0 - " + stale[0] + ");") != 1 or "echo '?start'; exit(2);" not in rbm):
        raise SystemExit("OF-RB1: the rb reading's test clause is not bounded by QF-RB1's start less OfferSwitch::STALE_S")
    # every mode that reads a COPY through PluginConfig::read is in the vault list, and every listed mode reads one
    vline = "if (in_array($mode, ['n1', 'n3', 'ca1', 'ca4', 'job'], true)) {\n"
    once(probe, vline, "the OF probe")
    listed = re.findall(r"'(\w+)'", vline)
    for blk in probe.split("\n    case '")[1:]:
        mode = blk.split("'", 1)[0]
        reads = "PluginConfig::read($root, $dir)" in blk
        if "PluginConfig::read(" in blk and mode not in listed:
            raise SystemExit(f"OF: mode {mode} reads a copy through PluginConfig::read without its own vault")
        if mode in listed and not reads:
            raise SystemExit(f"OF: mode {mode} is listed for the copy's vault but reads no copy")
    # nothing in the probe sends, claims, settles or records, and no mode but job includes a job
    for bad in ["->send(", "sendText(", "sendMedia(", "->claim(", "->settle(", "->record(", "runOnce(", "sendTest(", "SqliteStore::create", "offHook("]:
        if bad in probe:
            raise SystemExit(f"OF: the probe holds {bad!r}")
    if probe.count("include $root . '/cron/offers_send.php';") != 1 or probe.count("include $root . '/tabs/engage/wa_offers.php';") != 1:
        raise SystemExit("OF: the probe includes the job or the tab other than once")
    j = probe[probe.index("    case 'job':"):probe.index("    case 'ca5':")]
    pos = [j.find(s) for s in ["refused=datadir", "refused=live_true", "refused=network_on", "include $root . '/cron/offers_send.php';"]]
    if -1 in pos or pos != sorted(pos):
        raise SystemExit(f"OF-CA4: the job is not refused before its include on a data folder, live() or the network ({pos})")
    funcs = funcs_raw
    for k, v in [("@@OF_PHP@@", probe.rstrip("\n")), ("@@OF_NONET@@", OF_NONET), ("@@OF_091_MD5@@", md5), ("@@OF_091_SC@@", sc91[0]),
                 ("@@OF_NAV_SHA@@", nav_sha), ("@@OF_R1_LINES@@", "\n".join(R1_LINES))]:
        if "\nTXT\n" in "\n" + v + "\n":
            raise SystemExit(f"OF: {k}'s value cannot travel in its heredoc")
        funcs = put(funcs, k, v)
    if "@@" in funcs:
        raise SystemExit("OF: a placeholder is left in of_funcs")
    ctx.update(of_probe=probe, of_funcs=funcs, of_md5=md5, of_nav_sha=nav_sha)
    doc.must("\nvn_rb_after() {", 1, why="partV ran first")
    doc.insert_before(TN_ANCHOR, funcs + "\n", 1, why="OF functions and probe")
    fns = ["of_php", "of_nav_block", "of_nav_counts", "of_master", "of_g2_pre", "of_g2_post", "of_stage", "of_base_prep",
           "of_baseline", "of_after", "of_on", "of_rb1", "of_rb_after"]
    for fn in fns:
        doc.must(f"\n{fn}() {{", 1, why=f"{fn} defined once")
    if doc.t.index("\nvn_rb_after() {") > doc.t.index("\nof_php() {") or doc.t.index("\nof_rb_after() {") > doc.t.index(TN_ANCHOR):
        raise SystemExit("OF: the functions are not between partV's and the TN section")
    for fn in ["qf_copy", "qf_root", "qf_tool", "qf_on_one", "qf_sheet", "qf_kv", "vn_book_copy"]:
        doc.must(f"\n{fn}() {{", 1, why=f"OF calls {fn}, defined once")

    # ── DS1's text the codes need (asserted edits; no QF or VN verdict changes) ──
    doc.insert_before('  o="$(qf_php trial "$sub/code" "$sub")"\n',
                      '  of_g2_pre "$sub"   # 5.18.104 Part O: OF-G1\'s and OF-G2\'s census of this copy, just before its trial (ruling R10)\n',
                      1, why="QF-G2's copy: Part O's census before the trial")
    doc.insert_after("  printf '%s\\n' \"$o\" | grep -qE '^089 names: 38 of 38 present$' || bad=\"$bad doctor:089-names\"\n",
                     '  of_g2_post "$sub"   # 5.18.104 Part O: OF-G2 again on this copy after its trial, and OF-N1 and OF-N3 on it (091 applied here alone)\n',
                     1, why="QF-G2's copy: Part O's reads after the trial")
    if not (doc.t.index('  of_g2_pre "$sub"   #') < doc.t.index('  o="$(qf_php trial "$sub/code" "$sub")"\n') < doc.t.index('  of_g2_post "$sub"   #')
            < doc.t.index('  sub="$(qf_copy g2n "$P3B_PIN")"')):
        raise SystemExit("OF: the census does not bracket QF-G2's trial on its own copy")
    base_old = ('            if (@file_put_contents((string)$a[1], $vl . "\\n") !== false) @chmod((string)$a[1], 0600);\n'
                '        }\n'
                '        $t = time();\n')
    base_new = ('            if (@file_put_contents((string)$a[1], $vl . "\\n") !== false) @chmod((string)$a[1], 0600);\n'
                '        }\n'
                '        if (isset($a[2]) && (string)$a[2] !== \'\') {   // 5.18.104 Part O (root docs/73 §H.4.18 OF-G1; Q18-O03): OF-CA3\'s baseline,\n'
                '            // in this one call and before the copy\'s start is stamped — each P6 table\'s rows and largest id, the ledger\'s\n'
                '            // largest id with its rows of kind offer and its refs offer_test:*, evo_webhook_seen\'s largest id — to the file\n'
                '            // named (the run\'s folder; the sheet), never this line\n'
                '            try {\n'
                '                $or = []; $om = [];\n'
                '                foreach ([\'wa_offers\', \'wa_offer_recipients\', \'wa_offer_tests\', \'wa_offer_events\'] as $x) {\n'
                '                    $or[] = $has($p, $x) ? (int)$one($p, \'SELECT COUNT(*) FROM \' . $x) : -1;\n'
                '                    $om[] = $has($p, $x) ? (int)$one($p, \'SELECT COALESCE(MAX(id), 0) FROM \' . $x) : 0;\n'
                '                }\n'
                '                $lw = $has($p, \'wa_auto_sends\');\n'
                '                $ol = \'rows=\' . implode(\',\', $or) . \' max=\' . implode(\',\', $om)\n'
                '                    . \' ledger_max=\' . ($lw ? (int)$one($p, \'SELECT COALESCE(MAX(id), 0) FROM wa_auto_sends\') : 0)\n'
                '                    . \' ledger_offer=\' . ($lw ? (int)$one($p, "SELECT COUNT(*) FROM wa_auto_sends WHERE kind = \'offer\'") : 0)\n'
                '                    . \' ledger_test=\' . ($lw ? (int)$one($p, "SELECT COUNT(*) FROM wa_auto_sends WHERE ref GLOB \'offer_test:*\'") : 0)\n'
                '                    . \' seen_max=\' . ($has($p, \'evo_webhook_seen\') ? (int)$one($p, \'SELECT COALESCE(MAX(id), 0) FROM evo_webhook_seen\') : 0);\n'
                '            } catch (Throwable $ox) { $ol = \'?\' . get_class($ox); }   // never $e: this mode\'s entries\n'
                '            if (@file_put_contents((string)$a[2], $ol . "\\n") !== false) @chmod((string)$a[2], 0600);\n'
                '        }\n'
                '        $t = time();\n')
    doc.sub(base_old, base_new, 1, why="QF-G1's baseline call takes OF-CA3's in the same moment")
    doc.must("        foreach ($JOBS as $j) if (is_array($ms) && isset($ms[$j]) && is_array($ms[$j])) $e[] = $j . ':' . (int)($ms[$j]['last_run'] ?? 0);\n"
             "        if (isset($a[1]) && (string)$a[1] !== '') {", 1, why="Part V's baseline still first in the call")
    doc.sub('  QF_BASE="$(qf_php base "$PDD_IN/plugin.sqlite3" "${VN_BASE_F:-}")"\n',
            '  of_base_prep   # 5.18.104 Part O: the file OF-CA3\'s baseline is written to, in the same call (root docs/73 §H.4.18 OF-G1)\n'
            '  QF_BASE="$(qf_php base "$PDD_IN/plugin.sqlite3" "${VN_BASE_F:-}" "${OF_BASE_F:-}")"\n', 1, why="QF-G1's baseline call names OF's file")
    doc.must('  vn_base_prep   # 5.18.104 Part V: the file VN-CA4\'s baseline is written to, in the same call (root docs/73 §H.3.25 VN-G6)\n'
             '  of_base_prep   # 5.18.104 Part O:', 1, why="both files named before the one call")

    # ── the slots ──
    fill(doc, "OF-G stage A", "  of_stage   # OF-G1, OF-G2, OF-N1–OF-N3 — Part O's stage A (root docs/73 §H.4.18): a refusal stops here, nothing changed\n")
    fill(doc, "baseline", "  of_baseline   # OF-G1 — OF-CA3's redeploy baseline, read in QF-G1's own call above (root docs/73 §H.4.18; Q18-O03)\n")
    fill(doc, "OF-CA", '  of_after "$inst"   # OF-M1, OF-M2, OF-CA1–OF-CA5 — after the copy, through the installed code; nothing is sent\n')
    fill(doc, "OF-ON", '  of_on "$sub"   # OF-ON1, OF-ON2 — offers-test and offers-live, each while it is on, through the installed tool\'s --check\n')
    fill(doc, "OF-RB1", "  of_rb1   # OF-RB1 — Part O's read of QF-RB1's result, before the put-back (root docs/73 §H.4.19; rulings R7, R12)\n")
    fill(doc, "OF-RB2", "  of_rb_after   # OF-RB2 — a note after the put-back: the four tables stay, a paused offer resumes only by Resume\n")
    calls = ["  of_stage   #", "  of_baseline   #", '  of_after "$inst"   #', '  of_on "$sub"   #', "  of_rb1   #", "  of_rb_after   #"]
    for c in calls:
        doc.must(c, 1, why="slot filled once")
    order = [doc.t.index(x) for x in ["\nqf_stage() {", "  vn_stage   #", "  of_stage   #", "\nqf_clear_and_baseline() {", "  of_base_prep   #",
                                      "  vn_baseline   #", "  of_baseline   #", "\nqf_after() {", '  vn_after "$inst"   #', '  of_after "$inst"   #',
                                      "\nqf_on() {", '  vn_on "$sub"   #', '  of_on "$sub"   #', "\nqf_rb1() {", "  of_rb1   #", "  vn_rb1   #",
                                      "\nqf_rb3() {", '  vn_rb_after "$QF_RB3_VC"   #', "  of_rb_after   #"]]
    if order != sorted(order):
        raise SystemExit("OF: a call is not inside the function its slot belongs to, or not in its order")
    doc.must('  of_after "$inst"   # OF-M1, OF-M2, OF-CA1–OF-CA5 — after the copy, through the installed code; nothing is sent\n'
             "  # ── SLOT OF-CA ──", 1, why="of_after where its slot is")

    # ── Part O's by-hand put-back (ruling R17): DS1's four statements are D §H.4.19's, and 091 accepts them ──
    byhand(doc, ctx)

    # ── DS3-fix1: the run's folder made in the main shell by each part's entry; OF-RB1's bound taken before the tool ──
    fix(doc, ctx)

    # ── what the operator reads: the header, the options, the names and the steps' line ──
    doc.insert_after("#            whenever OpenAI was called).\n",
        "#\n"
        "#   5.18.104'S OWN CHECKS, Part O's (OF, \"offers\"; root docs/73 §H.4.18): counts, fixed codes, key and schema names and\n"
        "#            handset labels in the log; offer ids, the baseline's largest ids and the copy's start on the sheet. NOTHING IS\n"
        "#            EVER SENT and no request reaches Evolution: every OF reading runs with PHP's network functions off. Refusals\n"
        "#            before the copy, on QF-G2's trial copy through the PIN's code: OF-G1 a read of QF-G4's one rule for Part O's\n"
        "#            keys (ruling R7; it clears nothing itself), no wa_offer* table on a first deploy, and on a redeploy said in\n"
        "#            capitals with the offers not closed counted by state (their ids on the sheet); OF-G2 Part O's share of\n"
        "#            QF-G2's one trial (ruling R10) — 091 ok, never partial, its _schema_checks row 25 of 25, OfferSchema::check()\n"
        "#            empty, and every existing table's rows unchanged, by count and by a hash of every row. Notes: OF-N1 the\n"
        "#            baseline audience through the pin's store-free OfferAudience::build() on that copy (on a first deploy the\n"
        "#            reasons that need 089–091 objects read \"not measured before 089\"); OF-N2 R-01's lines in the PHP log over 30\n"
        "#            days; OF-N3 the settings offers read, Part O's keys as stored and the staff without a phone. On a redeploy\n"
        "#            OF-CA3's baseline is taken in QF-G1's own call, at its one moment. After the copy: OF-M1 091 on the book (its\n"
        "#            md5, its row, OfferSchema); OF-M2 its migration.log line; OF-CA1 screen() and live() off, the menu entry\n"
        "#            once inside OfferSwitch::screen(, the tab's \"not switched on\" card as an admin, beside two controls; OF-CA2\n"
        "#            ruling R1's four lines in master, offers_send once and last; OF-CA3 nothing of Part O written by the dark build\n"
        "#            (judged in the deploy run; on a redeploy against its baseline, each act by what its maker could know, ruling\n"
        "#            R18); OF-CA4 South Sudan with both offer keys on: live() off, the job — included on a scratch folder with no\n"
        "#            book — writes nothing and loads no sender, the tool \"not this install\"; OF-CA5 OfferSwitch::declaredKeys()\n"
        "#            inside the ladder's keys. In an --after-only run, OF-ON1 and OF-ON2 through the installed tool's --check\n"
        "#            offers-test and offers-live, each step that is on. In the rollback, OF-RB1 reads QF-RB1's result before the\n"
        "#            put-back (Part O's hook before its keys, the offers paused, claimed recipients settled uncertain or returned\n"
        "#            to queued, ruling R12; no offer running, no recipient claimed); after it, OF-RB2 (a note).\n",
        1, why="the header's Part O paragraph")
    doc.sub("# follow-up (and Part V's VN-RB5 and VN-RB6, its instruction to rotate the OpenAI key whenever OpenAI was called;\n"
            "# VN-RB1–VN-RB4 read QF-RB1's result before the put-back). 089's, 090's and 091's\n",
            "# follow-up (and Part V's VN-RB5 and VN-RB6, its instruction to rotate the OpenAI key whenever OpenAI was called;\n"
            "# VN-RB1–VN-RB4 read QF-RB1's result before the put-back, as Part O's OF-RB1 does — its hook before its keys, the\n"
            "# offers paused, the claimed recipients settled or returned — and OF-RB2 says what stays). 089's, 090's and 091's\n",
            1, why="the rollback paragraph names OF-RB")
    doc.sub("# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8, VN-M1–VN-K2; QF-ON0–QF-ON5, VA-ON1 and\n"
            "# VN-ON1–VN-ON4 for each step that is on); then",
            "# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8, VN-M1–VN-K2, OF-M1–OF-CA5; QF-ON0–QF-ON5,\n"
            "# VA-ON1, VN-ON1–VN-ON4, OF-ON1 and OF-ON2 for each step that is on); then", 1, why="the after-only line names OF")
    doc.sub("#                        VN-K1, VN-K2, and QF-ON0–QF-ON5, VA-ON1 and VN-ON1–VN-ON4 (each step\n",
            "#                        VN-K1, VN-K2, OF-M1, OF-M2, OF-CA1–OF-CA5, and QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4,\n"
            "#                        OF-ON1 and OF-ON2 (each step\n", 1, why="the --after-only option names OF")
    doc.sub('echo "                    VN-ON1–VN-ON4, VN-RB1–VN-RB6 and VA-ON1 are Part V\'s (VN, VA; §H.3.25); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n',
            'echo "                    VN-ON1–VN-ON4, VN-RB1–VN-RB6 and VA-ON1 are Part V\'s (VN, VA; §H.3.25); OF-G1, OF-G2, OF-N1–OF-N3, OF-M1,"\n'
            'echo "                    OF-M2, OF-CA1–OF-CA5, OF-ON1, OF-ON2, OF-RB1 and OF-RB2 are Part O\'s (OF; §H.4.18); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n',
            1, why="the names line names OF")
    doc.sub('  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5, VA-ON1 and VN-ON1–VN-ON4 judge"\n'
            '  echo "                    each step that is on."\n',
            '  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1"\n'
            '  echo "                    and OF-ON2 judge each step that is on."\n', 1, why="the steps' line names OF")

    # ── the result, as a whole: every code reported by a line of its own ──
    for code in ["OF-G1", "OF-G2", "OF-N1", "OF-N2", "OF-N3", "OF-M1", "OF-M2", "OF-CA1", "OF-CA2", "OF-CA3", "OF-CA4", "OF-CA5", "OF-RB1", "OF-RB2"]:
        n = len(re.findall(r'(?:ok|bad|note|stop) "(?:NO-GO \()?' + re.escape(code) + r'[ )]', doc.t))
        if n < 1:
            raise SystemExit(f"OF: no ok/bad/note/stop line reports {code}")
    doc.must('  qf_on_one "$1" OF-ON1 offers-test offers-test\n  qf_on_one "$1" OF-ON2 offers-live offers-live\n', 1)
    # OF-CA4 never includes the job but on a scratch with no book, after prep4 said so; the network is off in every OF reading
    doc.must('case "$v" in "prep=ok book=none "*) oj="$(of_php job "$d/code" "$d")"; ot="$(qf_tool "$d" show)" ;;', 1)
    doc.must('of_php() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -d allow_url_fopen=0 -d "disable_functions=$OF_NONET" -r "$OF_PHP" "$@"; }', 1)
    if len(re.findall(r"\bof_php ", doc.t)) < 15 or re.search(r'php [^\n]*-r "\$OF_PHP"', doc.t.replace(
            'of_php() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -d allow_url_fopen=0 -d "disable_functions=$OF_NONET" -r "$OF_PHP" "$@"; }', "")):
        raise SystemExit("OF: OF_PHP is run other than through of_php")
    doc.mustnot("@@OF_")


# Ruling R17: Part O's by-hand put-back. DS1's qf_byhand prints four statements; here they are asserted equal to D §H.4.19's
# SQL block (whitespace aside) and run in Python's sqlite3 against 091's own tables, CHECKs and triggers on rows a stopped
# rollback could leave (a running offer, a claimed recipient with a ledger claim and one without, a test left sending), with
# the outcome each must have; then two weakened statements that 091 must refuse — the control that 091's CHECKs are in force.
def byhand(doc, ctx):
    t = doc.t
    a = t.index("  echo \"  Part O's four (§H.4.19), in the hook's order:\"\n")
    b = t.index("  echo \"  Then every part's switch and record keys", a)
    stm = [re.match(r'^  echo "      (.*)"$', l).group(1) for l in t[a:b].split("\n")[1:] if l.strip()]
    dtext = open(os.path.join(ctx["dev"], D_NAME), encoding="utf-8").read()
    sec = dtext[dtext.index("#### H.4.19 Rollback (C4)"):dtext.index("#### H.4.20 ")]
    blocks = re.findall(r"```sql\n(.*?)```", sec, re.S)
    if len(blocks) != 1:
        raise SystemExit(f"OF (R17): D §H.4.19 holds {len(blocks)} SQL blocks")
    norm = lambda s: re.sub(r"\s+", " ", s).strip()
    dst = [norm(x) + ";" for x in blocks[0].split(";") if norm(x)]
    if [norm(s) for s in stm] != dst or len(stm) != 4:
        raise SystemExit(f"OF (R17): qf_byhand's Part O statements are not D §H.4.19's:\n{stm}\n{dst}")
    m91 = git(ctx["repo"], "show", f"{ctx['pin_full']}:{ctx['plugin']}/migrations/091_wa_offers.sql").decode()
    con = sqlite3.connect(":memory:")
    con.executescript(m91)
    if con.execute("SELECT found, expected FROM _schema_checks WHERE migration = '091_wa_offers.sql'").fetchone() != (25, 25):
        raise SystemExit("OF (R17): 091 does not verify itself in sqlite3")
    w = "x" * 25
    con.execute("INSERT INTO wa_offers (title, wording_1, wording_2, stop_line, content_hash, state, live_slot, started_at, created_by_id, created_by) "
                "VALUES ('t', ?, ?, 'STOP', 'h', 'running', 1, '2026-01-01 10:00:00', 1, 'a')", (w, w + "y"))
    rc = ("INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, attempt, claim_token, "
          "claimed_at, judged_at, day_max, ledger_claim) VALUES (1, ?, ?, 1, 'sales', 'i:x', '2026-01-01 09:00:00', 'claimed', 1, 'k', "
          "'2026-01-01 10:00:00', ?, ?, ?)")
    con.execute(rc, ("256700000001", 1, "2026-01-01 10:00:00", 20, 7))
    con.execute(rc, ("256700000002", 2, None, None, None))
    con.execute("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, started_at) "
                "VALUES (1, 'h', 1, 1, 'sales', 'i:x', 'sending', '2026-01-01 10:00:00')")
    con.commit()
    for s in stm:
        con.execute(s)
    con.commit()
    got = (con.execute("SELECT state, live_slot FROM wa_offers").fetchone(),
           con.execute("SELECT state, reason, sent_at FROM wa_offer_recipients WHERE id = 1").fetchone(),
           con.execute("SELECT state, not_before IS NOT NULL FROM wa_offer_recipients WHERE id = 2").fetchone(),
           con.execute("SELECT state, settled_at IS NOT NULL FROM wa_offer_tests").fetchone())
    if got != (("paused", 1), ("uncertain", "stale_claim", "2026-01-01 10:00:00"), ("queued", 1), ("uncertain", 1)):
        raise SystemExit(f"OF (R17): the four statements left {got}")
    con.execute(rc, ("256700000003", 3, "2026-01-01 10:00:00", 20, 8))
    for weak in ["UPDATE wa_offer_recipients SET state = 'uncertain', reason = 'stale_claim', settled_at = datetime('now') WHERE id = 3",
                 "UPDATE wa_offers SET state = 'paused', live_slot = NULL WHERE id = 1"]:
        try:
            con.execute(weak)
        except sqlite3.IntegrityError:
            continue
        raise SystemExit(f"OF (R17): 091 accepted a weakened statement ({weak}) — the CHECKs are not in force here")
    con.close()
    print("  OF (R17): Part O's four by-hand statements are D §H.4.19's, and run under 091's CHECKs and triggers; two weakened ones refused")


# DS3-fix1. R01 — the run's folder in the container's /tmp (cih_init: CIH_BASE, its vault copy) is made in the MAIN shell.
# qf_copy and vn_book_copy always run inside $( … ) (each prints the folder it made); when either was the first to call
# cih_init, the folder was made in that subshell and CIH_BASE stayed empty out here, so every later cih_init was refused by
# its own `mkdir -m 700` of the same name (/tmp/dnb-<ver>-cih-$TS-$$: $$ is the parent's inside $( … )). In a --rollback
# nothing before QF-RB1 made it (stage A's cash in hand runs in a deploy only), so OF-RB1's copy made it and VN-RB3, VN-RB4,
# OF-RB2, TN's RB and RB's cash in hand each read "could not be read". Now each part's entry makes it first, by cih_main
# (cih_init in this shell; on a failure no half-made folder is left, so a later reading in the main shell — TN's, cash in
# hand — tries again exactly as 5.18.103's did), and qf_copy and vn_book_copy refuse when it is not there: they no longer
# call cih_init at all. derive104.py's folder check proves it over every call path. R02 — QF_RB1_START, before the tool.
ENTRIES = {
    "qf_stage": 'qf_stage() {\n  local sub o g1 last n bad="" v ap st\n',
    "qf_after": 'qf_after() {\n  local inst="$1" sub o n v bad j\n',
    "qf_on": 'qf_on() {\n  local inst="$1" sub o\n',
    "qf_rb3": 'qf_rb3() {\n  local o bad="" f\n',
}
CIH_MAIN_LINE = "  cih_main || true   # the run's folder, made in this shell before the readings on copies below, which run in $( … ) (DS3-fix1)\n"


def fix(doc, ctx):
    doc.insert_after('cih_done() { [ -n "$CIH_BASE" ] && cih_drop "$CIH_BASE"; CIH_BASE=""; }\n',
        "# cih_main — 5.18.104 (root docs/73 §H.2.15; DS3-fix1): the run's folder made HERE, in the main shell, by every part's\n"
        "# entry (qf_stage, qf_after, qf_on, qf_rb1, qf_rb3) before its readings on copies. Those readings run inside $( … )\n"
        "# (qf_copy and vn_book_copy print the folder they made), where a folder cih_init made would be unknown to this shell:\n"
        "# CIH_BASE empty again out here, and every later cih_init refused by its own mkdir of the same name. So neither of them\n"
        "# calls cih_init: each refuses when the folder is not there. A failure leaves no half-made folder (its vault copy not\n"
        "# read) behind, so a later reading in the main shell — the owner list, cash in hand — tries again as 5.18.103's did.\n"
        "cih_main() { cih_init && return 0; [ -z \"$CIH_BASE\" ] || cih_done; return 1; }\n",
        1, why="cih_main beside cih_init")
    doc.sub('  cih_init || { QF_ROOT_ERR="the run\'s folder in the container\'s /tmp could not be made (${CIH_VAULT_SAYS:-?})"; return 1; }\n',
            '  # never cih_init here: qf_copy runs in $( … ), and a folder made in a subshell is lost to the main shell (cih_main)\n'
            '  [ -n "$CIH_BASE" ] || { QF_ROOT_ERR="the run\'s folder in the container\'s /tmp is not there (${CIH_VAULT_SAYS:-not made before this reading})"; return 1; }\n',
            1, why="qf_copy refuses without the run's folder")
    doc.sub('  cih_init || return 1\n  size="$(db_state | sed -nE \'s/^size=([0-9]+) .*/\\1/p\')"; free="$(dxo php -r \'echo (int)@disk_free_space($argv[1]);\' /tmp)"\n'
            '  case "$size$free" in \'\'|*[!0-9]*) return 1 ;; esac\n  [ "$free" -ge $(( size + 67108864 )) ] || return 1\n',
            '  [ -n "$CIH_BASE" ] || return 1   # never cih_init here: vn_book_copy runs in $( … ) (cih_main)\n'
            '  size="$(db_state | sed -nE \'s/^size=([0-9]+) .*/\\1/p\')"; free="$(dxo php -r \'echo (int)@disk_free_space($argv[1]);\' /tmp)"\n'
            '  case "$size$free" in \'\'|*[!0-9]*) return 1 ;; esac\n  [ "$free" -ge $(( size + 67108864 )) ] || return 1\n',
            1, why="vn_book_copy refuses without the run's folder")
    for name, head in ENTRIES.items():
        doc.insert_after(head, CIH_MAIN_LINE, 1, why=f"{name} makes the run's folder first")
    doc.insert_after('  hdr "B. QF-RB1 — every $EXPECTED_VERSION switch off, in the one sequence, under the installed code (before the put-back)"\n',
        "  # 5.18.104 (DS3-fix1): in a --rollback nothing before this made the run's folder (stage A's cash in hand runs in a\n"
        "  # deploy only), and OF-RB1, VN-RB1–VN-RB4 and, after the put-back, TN's RB, RB's cash in hand, VN-RB5, OF-RB2 and\n"
        "  # AC-RB2 read copies under it; in V1's automatic rollback stage A made it, and this does nothing\n"
        "  cih_main || note \"QF-RB1 the run's folder in the container's /tmp could not be made (${CIH_VAULT_SAYS:-?}) — the readings on copies of the book below each say they could not be read; QF-RB1's own tool and read-backs read none\"\n",
        1, why="qf_rb1 makes the run's folder first")
    rb_call = '  o="$(UCRM_CONTAINER="$CONTAINER" bash "$REPO/$QF_WRAPPER" off all --rollback 2>&1 </dev/null | qf_safe)"; rc=${PIPESTATUS[0]}\n'
    doc.insert_before(rb_call,
        "  QF_RB1_START=\"$(date -u +%s)\"   # 5.18.104 Part O (DS3-fix1): before any hook runs — OF-RB1's test clause is bounded by it\n",
        1, why="QF-RB1's start, for OF-RB1")
    # the result: neither copy function makes the folder; every entry makes it first; the start before the tool, read by OF-RB1
    for fn in ["qf_copy", "vn_book_copy"]:
        a = doc.t.index(f"\n{fn}() {{\n")
        body = doc.t[a:doc.t.index("\n}\n", a)]
        if "cih_init" in body.replace("never cih_init here", "") or '[ -n "$CIH_BASE" ] ||' not in body:
            raise SystemExit(f"OF (DS3-fix1): {fn} still makes the run's folder, or does not refuse without it")
    for name in list(ENTRIES) + ["qf_rb1"]:
        a = doc.t.index(f"\n{name}() {{\n")
        body = doc.t[a:doc.t.index("\n}\n", a)]
        calls = [m.start() for m in re.finditer(r"\$\((?:qf_copy|vn_book_copy) |\n  (?:of_rb1|vn_rb1|vn_rb_after|of_rb_after|vn_stage|vn_after|of_after|of_on|vn_on)\b", body)]
        if body.count("\n  cih_main ||") != 1 or (calls and body.index("\n  cih_main ||") > min(calls)):
            raise SystemExit(f"OF (DS3-fix1): {name} does not make the run's folder before its readings on copies")
    a = doc.t.index("\nqf_rb1() {\n")
    rb1 = doc.t[a:doc.t.index("\n}\n", a)]
    if not (rb1.index("  QF_RB1_START=\"$(date -u +%s)\"") < rb1.index(rb_call) < rb1.index("  of_rb1   #")):
        raise SystemExit("OF (DS3-fix1): QF-RB1's start is not taken before the tool, or OF-RB1 is not after both")
    doc.must('r="$(of_php rb "$d/plugin.sqlite3" "$d/of-ids.txt" "${QF_RB1_START:-}")"', 1, why="OF-RB1 passes QF-RB1's start")
    doc.must('cih_main() { cih_init && return 0; [ -z "$CIH_BASE" ] || cih_done; return 1; }\n', 1,
             why="cih_main: on a failure no half-made folder is left (its vault copy not read), as 5.18.103's readings expect")
    doc.must("\n  cih_main ||", 5, why="the five entries")
