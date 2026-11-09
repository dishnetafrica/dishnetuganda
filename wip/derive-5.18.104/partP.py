# Part P — 5.18.104's agent-copy checks, Part P's codes AC (root docs/73 §H.5.14, §H.5.15; §H.5.10, §H.5.16; rulings R11,
# R13, R18; P7S-02, P7S-07, P7S-08). Stage DS4. Registered after partO, it fills the slots partF and partR left (AC stage A,
# AC-CA, AC-ON1, AC-RB1, AC-RB2), inserts its own probe (ac_probe.php.txt) and functions (ac_funcs.sh.txt) after partO's at
# the same anchor, and makes one asserted edit to DS1's text that its codes need and that changes no QF, VN or OF verdict:
#   - QF-RB1 reads quote_agent_cc_admins (a setting) just before the installed tool's off all --rollback (ac_rb_pre), so
#     that AC-RB1 can say it is as it was (§H.5.14 AC-RB1);
#   - qf_on copies the live quote_mail.log into the run's folder just before it copies the book (ac_on_pre; DS4-fix1,
#     DS4-R01): AC-ON reads that file and email_settings.json beside the book in the tool's data folder, which is qf_on's
#     copy, and the app path records P7's decision before it writes the log line, so a log taken before the book holds no
#     line whose decision the copy could lack; ac_on puts both files into the copy before the tool runs, with controls;
# and the header, the options, the names and the steps' line name the AC codes.
# Every value the fragments carry from the release is read off Git here and asserted: QuoteAgentCopy's keys, collections,
# signatures and precondition lines; the keys P7's lines read (CustomerEmailDispatcher's switches, EmailTemplate's
# Reply-To, mailSetting's email_settings.json keys), none a secret; row 1b of the ladder and the one sequence's end;
# the tool's lines AC-ON1 and AC-RB1 read; AC-L1's four files (the delta: the one P7 adds, the three it changes, each
# holding its marked hunks) and their sha256; the pin's drive.php (its recording fake uCRM, its real-MailService branch,
# no network of its own) and fake_smtp_server.php (byte for byte 5.18.103's), by sha256; qac_mask()'s four lines; the
# retailers SQL of OfferAudience::storedRows(); and the 5.18.103 seams drive.php needs. No e-mail is ever sent to a real
# address by the deploy script, and nothing writes to uCRM.
import os, re, hashlib
from d104lib import git, fill, sha_at, delta

HERE = os.path.dirname(os.path.abspath(__file__))
TN_ANCHOR = "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────\n"
# AC-CA2's drives run with every network and process function off but fsockopen, MailService's one connection (to the
# loopback relay its sandbox names, read back before anything runs); every other AC reading with fsockopen off too
DRIVE_NONET = ("curl_init,curl_exec,curl_multi_init,curl_multi_exec,pfsockopen,stream_socket_client,socket_create,"
               "socket_connect,proc_open,popen,exec,shell_exec,system,passthru")
AC_CODES = ["AC-L1", "AC-N1", "AC-N2", "AC-CA1", "AC-CA2", "AC-ON1", "AC-RB1", "AC-RB2"]


def put(text, key, value, n=1):
    if text.count(key) != n:
        raise SystemExit(f"AC PLACEHOLDER COUNT: {key} found {text.count(key)}, expected {n}")
    out = text.replace(key, value)
    if key in out:
        raise SystemExit(f"AC PLACEHOLDER RESIDUE: {key}")
    return out


def once(src, s, what):
    if src.count(s) != 1:
        raise SystemExit(f"AC: {what} has {s[:110]!r} {src.count(s)} times, expected 1")


def apply(doc, ctx):
    repo, plugin, pin, base = ctx["repo"], ctx["plugin"], ctx["pin_full"], ctx["base_full"]
    show = lambda p, c=pin: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── QuoteAgentCopy, as built (B4, B4-fix1, B9-fix1 R08) ──
    qac = show("lib/QuoteAgentCopy.php")
    for s in ["public const KEY    = 'quote_agent_cc_enabled';", "public const SINCE  = 'quote_agent_cc_since';",
              "public const ADMINS = 'quote_agent_cc_admins';", "public const MAKERS    = 'quote_agent_makers.json';",
              "public const DECISIONS = 'quote_agent_copies.json';",
              "public static function state(string $pluginRoot, string $dataDir): array",
              "public static function on(string $pluginRoot, string $dataDir): bool",
              "public static function mailSetting(string $dataDir): array",
              "public static function preconditions(string $dataDir): array",
              "public static function accountCounts(array $retailers, array $company): array",
              "public static function check(\\PDO $ro, string $pluginRoot, string $dataDir, int $now): array",
              "'plugin'         => 'plugin mail: plugin SMTP',", "'ucrm_not_asked' => \"plugin mail: uCRM's mailer, not asked\",",
              "'none'           => 'plugin mail is not configured (Settings → System → Email)',",
              "'unreadable'     => 'plugin mail is not configured (email_settings.json cannot be read)',",
              "$out = ['active_usable' => 0, 'active_unusable' => 0, 'administrators' => 0, 'company_mailbox' => 0];",
              "if (!StaffJobsGate::applies($cfg, $dataDir) || (string)($cfg[self::KEY] ?? '') !== '1') return $off;"]:
        once(qac, s, "lib/QuoteAgentCopy.php")
    lists = {k: re.findall(r"'([a-z_]*)'", re.search(r"public const " + k + r"\s*= \[(.*?)\];", qac, re.S).group(1))
             for k in ["CODES", "SOURCES", "RULES", "RCPTS", "PATHS"]}
    for k, need in [("CODES", ["added", "same_as_customer", "no_agent"]), ("SOURCES", ["actor", "kyc", "none"]),
                    ("RULES", ["kyc_submitter"]), ("RCPTS", ["accepted", "none"]), ("PATHS", ["plugin", "webhook"])]:
        if not set(need) <= set(lists[k]):
            raise SystemExit(f"AC-CA2: QuoteAgentCopy::{k} lacks {sorted(set(need) - set(lists[k]))}")
    # the four lines ac_mail_say prints are preconditions()'s, for mailSetting()'s four vias
    funcs_raw = open(os.path.join(HERE, "ac_funcs.sh.txt"), encoding="utf-8").read()
    for via, line in [("plugin", "plugin mail: plugin SMTP"), ("ucrm_not_asked", "plugin mail: uCRM's mailer, not asked"),
                      ("none", "plugin mail is not configured (Settings → System → Email)"),
                      ("*", "plugin mail is not configured (email_settings.json cannot be read)")]:
        once(funcs_raw, f'    {via}) echo "{line}" ;;\n', "ac_funcs ac_mail_say")
    # the bash side's guards, each asserted, so that a weakened copy of ac_funcs fails here
    for s in ['       && [ "$(sha256sum "/tmp/dnb_ac_lint.$$" | cut -c1-64)" = "$want" ] \\\n',
              '       && docker exec -i "$CONTAINER" php -l < "/tmp/dnb_ac_lint.$$" 2>&1 | drained grep -q \'^No syntax errors detected\'; then n=$((n + 1))\n',
              '    || stop "NO-GO (AC-L1): ',
              '      live=*" ug=1 set=1 ss=0 "*)\n',
              '  want="c6a=same c6b=same c6_loaded=0 c6_tables=same c5a=same c5b=same c5_rows=0 c1=ok c2=ok c3=ok c4=ok"\n',
              '  case "$o" in *"refused="*) bad "AC-CA2 REFUSED, nothing driven: ',
              '  if [ "$st" = "OFF — not judged" ]; then note "AC-ON1 OFF — not judged (agent-cc is off)"; return; fi\n',
              '    "on=0 set=0 held=- admins="*) ;;\n',
              '    "on="*) qf_byhand; stop "AC-RB1 FAILED: ',
              '    *) qf_byhand; stop "AC-RB1 FAILED: ',
              '  [ "$last" = "OFF the record keys and agent-cc" ] || bad="$bad not-last(${last:-none})"\n',
              '  [ "$prev" = "OFF sending" ] || bad="$bad ledger-not-just-before(${prev:-none})"\n',
              '        if [ "$MODE" = "deploy" ] && [ "$QF_G1" = "first" ]; then\n']:
        once(funcs_raw, s, "ac_funcs.sh.txt")
    # AC-ON1 (DS4-R01): the two files put into the copy before the tool runs, and AC-ON judged only over them
    for s in ['  AC_ON_ML="$(ac_php onlog "$1" "$PDD_IN" "$CIH_BASE/ac-on-log")"\n',
              '  f="$(ac_php onfiles "$1/code" "$PDD_IN" "$CIH_BASE/ac-on-log" "$1")"   # the two files AC-ON reads, into the tool\'s data folder, before it runs\n',
              '        *[!0-9:]*|:*|*:|*::*) why="the copy of quote_mail.log was not counted (${AC_ON_ML:-not taken})" ;;\n',
              '        *) { [ "$n0" -le "$nc" ] && [ "$nc" -le "$n1" ]; } || why="the copy of quote_mail.log holds $nc dated sent line(s),',
              '  if [ -z "$why" ] && { [ "$(qf_kv "$f" ml)" != "$ml" ] || [ "$(qf_kv "$f" ml_lines)" != "$nc" ]; }; then\n',
              '      "present|1"|"absent|1") ;;\n',
              '    unreadable) why="the live quote_mail.log cannot be read',
              '  if [ -n "$why" ]; then bad "AC-ON1 agent-cc is on and NOT judged: $why']:
        once(funcs_raw, s, "ac_funcs.sh.txt (AC-ON1's two files)")
    on = funcs_raw[funcs_raw.index("\nac_on() {"):funcs_raw.index("\n}\n", funcs_raw.index("\nac_on() {"))]
    seq_on = [on.index(x) for x in ['  f="$(ac_php onfiles ', '  o="$(qf_tool "$1" --check agent-cc)"; rc=$?\n',
                                    '  if [ "$st" = "OFF — not judged" ]; then note "AC-ON1 OFF', '  case "$ml" in\n',
                                    '  if [ -n "$why" ]; then bad "AC-ON1 agent-cc is on and NOT judged:', '  ps="$(printf ', '    PASS) ok "AC-ON1 agent-cc: PASS']]
    if seq_on != sorted(seq_on) or on.count("qf_tool ") != 1 or on.count("ac_php ") != 1 or on.count("ok \"") != 1 or on.count("note \"") != 2:
        raise SystemExit("AC-ON1: the files are not put before the tool, or a status is reported before the controls")
    st = funcs_raw[funcs_raw.index("\nac_stage() {"):funcs_raw.index("\n}\n", funcs_raw.index("\nac_stage() {"))]
    i_stop = st.index('    || stop "NO-GO (AC-L1): ')
    if [m.start() for m in re.finditer(r"qf_copy|cih_|vn_book_copy|docker cp|ac_php ", st) if m.start() < i_stop]:
        raise SystemExit("AC-L1: something is copied or read before the lint's NO-GO")
    if st.count("qf_copy ") != 1:
        raise SystemExit("AC-N1: ac_stage makes other than one copy")
    # each code's main reporting line, as built (a copy that drops one and keeps only its fallback fails here)
    for s in ['          note "AC-N1 $m; quote_email_via_plugin (the app\'s quotations, path 1) $(qf_kv "$o" path1);',
              '    p1=*) note "AC-N2 what the copy would have covered over 30 days (UTC) — path 1 (quote_mail.log, the app\'s, $(qf_kv "$o" p1)):',
              '        if [ "$(qf_kv "$o" live)" = "0" ]; then ok "AC-CA1 through the installed QuoteAgentCopy on a copy,',
              '  if [ -z "$miss" ]; then ok "AC-CA2 on a copy of the book,',
              '    PASS) ok "AC-ON1 agent-cc: PASS',
              '  if [ -z "$bad" ]; then ok "AC-RB1 the one sequence cleared Part P\'s two keys last,',
              '    makers=*) note "AC-RB2 P7\'s two collections stay',
              '  ok "AC-L1 PHP ${PHPV:-?} in the container accepts the pin\'s ']:
        once(funcs_raw, s, "ac_funcs.sh.txt (a code's main line)")
    # AC-ON1 (DS4-R01): AC-ON reads quote_mail.log (rule 4, mailLines) and email_settings.json (rule 2, preconditions →
    # mailSetting) from the data folder it is given, and the tool gives it its own — DN_DATA_DIR, which qf_tool sets to the
    # copy. Neither file is in a qf_copy copy, so ac_on puts both there; its controls call the installed mailLines itself.
    for s in ["private static function mailLines(string $dataDir): array", "$f = rtrim($dataDir, '/') . '/quote_mail.log';",
              "$lines = self::mailLines($dataDir);                                    // [[t, number], …]",
              "$notes[] = self::preconditions($dataDir)['lines'][0];", "$f = rtrim($dataDir, '/') . '/email_settings.json';",
              "foreach ($p1Lines as $n => $k) {", "if ($k > ($p1Ok[$n] ?? 0)) return ['status' => 'FAIL decision_missing', 'counts' => $counts, 'notes' => $notes];"]:
        once(qac, s, "lib/QuoteAgentCopy.php (AC-ON's two files)")
    once(show("lib/SalesFollowupLadder.php"), "return \\QuoteAgentCopy::check($ro, $pluginRoot, $dataDir, $now);", "the ladder (AC-ON's data folder)")
    once(show("tools/sales_followup.php"), "$SF_DATA = cliDataDir($root);", "tools/sales_followup.php (its data folder)")
    bsd = show("lib/bootstrap_data.php")
    once(bsd, "$explicit = (string)getenv('DN_DATA_DIR');\n    if ($explicit !== '') return rtrim($explicit, '/');", "lib/bootstrap_data.php (cliDataDir)")
    # ac_on_pre's premise: on the app path P7's decision is recorded before the log line is written, so a log copied before
    # the book holds no line whose decision the book's copy could lack
    qsv = show("lib/QuotationService.php")
    i_rec = qsv.index("                    QuoteAgentCopy::record($this->store, 'plugin', $quoteId, $number, $p7, !empty($send['ok']),")
    once(qsv, "            @file_put_contents($this->dataDir . '/quote_mail.log',\n                '[' . gmdate('Y-m-d H:i:s') . \"] {$number} -> {$email} sent\\n\", FILE_APPEND | LOCK_EX);",
         "lib/QuotationService.php (path 1's log line)")
    if qsv.count("/quote_mail.log'") != 1 or not i_rec < qsv.index("            @file_put_contents($this->dataDir . '/quote_mail.log',"):
        raise SystemExit("AC-ON1: the app path does not record P7's decision before it writes its one log line")
    ml_files = sorted(set(l.split(":", 1)[1] for l in git(repo, "grep", "-l", "quote_mail.log", pin, "--", f"{plugin}/*.php",
                                                          f":(exclude){plugin}/tests").decode().split()))
    if ml_files != [f"{plugin}/lib/QuotationService.php", f"{plugin}/lib/QuoteAgentCopy.php"]:
        raise SystemExit(f"AC-ON1: quote_mail.log is named by {ml_files}, not by its one writer and its one reader alone")
    ms = re.search(r"public static function mailSetting\(string \$dataDir\): array\n    \{(.*?)\n    \}\n", qac, re.S).group(1)
    ms_keys = sorted(set(re.findall(r"\$ec\['([a-z_]+)'\]", ms)))
    if ms_keys != ["smtp_from", "smtp_host", "smtp_user", "use_ucrm_email"]:
        raise SystemExit(f"AC-N1: mailSetting() reads {ms_keys}")

    # ── the keys P7's lines read (AC-N1, §H.5.10.3): the tool's, CustomerEmailDispatcher's, EmailTemplate's ──
    tool = show("tools/sales_followup.php")
    for s in ["$path1 = is_array($es) && !empty($es['quote_email_via_plugin']);", "$master = CustomerEmailDispatcher::masterEnabled($config);",
              "$path3 = CustomerEmailDispatcher::enabled('quotation', $config);", "CustomerEmailDispatcher::ccEnabled($config)",
              "$via = array_key_exists('kyc_quote_send_via_crm', $config) ? $config['kyc_quote_send_via_crm'] : null;",
              "$c = QuoteAgentCopy::accountCounts($rows['retailers'], ['reply_to' => EmailTemplate::replyTo($config), 'sender' => (string)($m['sender'] ?? 'not read')]);",
              # AC-RB1: the lines the one sequence prints for Part P (ruling R11)
              "sf_out('OFF the record keys and agent-cc');",
              "if (!empty(QuoteAgentCopy::state($root, $dataDir)['on'])) { sf_out('FAIL key_outside_overrides — the agent copy still reads on'); $rc = 3; }",
              "else sf_out('quotation e-mails are as 5.18.103 from the next one');",
              "sf_out('OFF sending — every ledger built from now on answers ledger_off; the chase, the list, the acknowledgement and offers go inert');",
              "sf_out('FAIL key_outside_overrides ' . $k . ' — held by ' . $file);",
              "sf_out('  hook ' . $step . ': ' . implode(' ', $p));",
              # AC-ON1: what --check <step> prints (ac_on reads it as qf_on_one and vn_on_one do)
              "if (!SalesFollowupLadder::isOn($config, $step)) { sf_out($step . ': OFF — not judged'); return 0; }",
              "sf_out('  ' . $id . ': ' . SalesFollowupLadder::status((string)($r['status'] ?? ''))['status']);",
              "if ($counts !== []) sf_out('    counts: ' . implode(' ', $counts));",
              "foreach ((array)($r['notes'] ?? []) as $n) sf_out('    note: ' . (string)$n);"]:
        once(tool, s, "tools/sales_followup.php")
    once(tool, "$rows = OfferAudience::storedRows($ro);\n        $m = QuoteAgentCopy::mailSetting($dataDir);", "tools/sales_followup.php (sf_agent_cc_lines)")
    ced = show("lib/CustomerEmailDispatcher.php")
    for s in ["return !empty($c['customer_emails_enabled']);", "return !empty($c['customer_email_' . $key]);",
              "return !empty(self::effectiveConfig($config)['email_cc_contacts']);",
              "$files   = [$root . '/data/config.json', $dataDir . '/config.json', $dataDir . '/kyc_config.json'];",
              "$dataDir = $GLOBALS['dataDir'] ?? ($root . '/data');"]:
        once(ced, s, "lib/CustomerEmailDispatcher.php")
    et = show("lib/EmailTemplate.php")
    once(et, "'email_reply_to'      => 'info@dishnetafrica.com',", "lib/EmailTemplate.php")
    once(et, "return self::brand($config)['reply_to'];", "lib/EmailTemplate.php")
    CFG = ["customer_emails_enabled", "customer_email_quotation", "email_cc_contacts", "email_reply_to"]
    ES = sorted(set(ms_keys) | {"quote_email_via_plugin", "sent_copy_enabled"})
    lad = show("lib/SalesFollowupLadder.php")
    pre = re.search(r"const PREEXISTING = \[(.*?)\];", lad, re.S).group(1)
    pre_keys = set(re.findall(r"'([a-z_]+)'", pre))
    if not set(ES) <= pre_keys or not {"kyc_quote_send_via_crm", "kyc_auto_quote_enabled"} <= pre_keys:
        raise SystemExit(f"AC-N1: the ladder's PREEXISTING lacks {sorted(set(ES) - pre_keys)}")
    sk = set(re.findall(r"'([a-z_0-9]+)'", re.search(r"const SECRET_KEYS = \[(.*?)\];", show("lib/PluginConfig.php"), re.S).group(1)))
    if (set(CFG) | set(ES)) & sk or any("pass" in k or "secret" in k or "key" in k for k in CFG + ES):
        raise SystemExit("AC-N1: a key the copy would carry is a secret")
    oa = show("lib/OfferAudience.php")
    once(oa, "foreach (['retailers' => 'SELECT data FROM [retailers] ORDER BY id', 'kyc_config' => 'SELECT data FROM [kyc_config] WHERE id = 0'] as $t => $sql) {",
         "lib/OfferAudience.php")

    # ── row 1b and the one sequence's end (ruling R11) ──
    once(lad, "'agent-cc' => ['n' => '1b', 'part' => 'P', 'optional' => true, 'voice' => false, 'switch' => ['quote_agent_cc_enabled' => '1'],", "the ladder")
    once(lad, "'stamp' => 'quote_agent_cc_since', 'record' => [], 'ledger' => false, 'options' => [],\n"
              "            'needs' => [], 'pre' => ['P'], 'check' => ['AC-ON'], 'clears' => [], 'hook' => null, 'locks' => [], 'noskip' => true],", "the ladder")
    once(lad, "return array_values(array_unique(array_merge($out, self::rowKeys('agent-cc'))));", "the ladder (lastKeys)")
    once(lad, "            $plan[] = ['ledger', 'sending'];\n            $plan[] = ['last', 'records'];\n", "the ladder (offPlan all)")
    seq = re.findall(r"'([a-z-]+)'", re.search(r"const SEQUENCE = \[(.*?)\];", lad, re.S).group(1))
    if "agent-cc" in seq or seq[:2] != ["offers-live", "offers-test"] or seq[-1] != "ack":
        raise SystemExit(f"AC-RB1: the one sequence is not ruling R7/R11's ({seq})")
    ctx["seq"] = seq

    # ── AC-L1's four files: the one P7 adds and the three it changes, each with its marked hunks (§H.5.8) ──
    added, changed = delta(repo, base, pin, plugin)
    l1 = ["lib/QuoteAgentCopy.php", "lib/QuotationService.php", "webhook.php", "lib/CustomerEmailDispatcher.php"]
    if l1[0] not in added or any(f not in changed for f in l1[1:]):
        raise SystemExit("AC-L1: the delta does not add lib/QuoteAgentCopy.php and change the three P7 files")
    marked = sorted(set(re.sub(r"^[^:]+:" + re.escape(plugin) + "/", "", l)
                        for l in git(repo, "grep", "-l", "root docs/73 §H.5.8", pin, "--", plugin, f":(exclude){plugin}/tests").decode().split()))
    if marked != sorted(l1[1:]):
        raise SystemExit(f"AC-L1: the files with P7's marked hunks are {marked}")
    for f in l1[1:]:
        if "P7" not in show(f) or "require_once __DIR__ . '/lib/QuoteAgentCopy.php';" not in show(f) and "require_once __DIR__ . '/QuoteAgentCopy.php';" not in show(f) and f != "lib/CustomerEmailDispatcher.php":
            raise SystemExit(f"AC-L1: {f} does not load P7's file behind its hunk")
    sums = " ".join(f"{f}:{sha_at(repo, pin, plugin + '/' + f)}" for f in l1)

    # ── AC-CA2: the pin's drive.php and fake relay, by sha256; what the harness relies on in them ──
    drv = show("tests/quote_agent_copy/drive.php")
    for s in ["$mailer = new MailService($DATA);", "$out['qac_loaded'] = class_exists('QuoteAgentCopy', false);", "case 'app':", "case 'webhook':",
              "class QacCrm extends CrmApiClient", "public function get(string $path): ?array", "public function post(string $path, array $p = []): ?array",
              "public function patch(string $path, array $p = []): ?array", "public function delete(string $path): ?array",
              "public function getRawContent(string $path): ?string", "if (($spec['mailer'] ?? 'rec') === 'rec') {",
              "$p = new ReflectionProperty(QuotationService::class, 'crm');", "$store = SqliteStore::create($DATA);",
              "file_put_contents((string)$spec['out'], json_encode($out,"]:
        once(drv, s, "tests/quote_agent_copy/drive.php")
    if re.search(r"parent::(get|post|patch|delete|getRawContent|request)\(|curl_|fsockopen|stream_socket", drv):
        raise SystemExit("AC-CA2: drive.php opens a connection of its own")
    drive_sha = sha_at(repo, pin, f"{plugin}/tests/quote_agent_copy/drive.php")
    relay_sha = sha_at(repo, pin, f"{plugin}/tests/fixtures/fake_smtp_server.php")
    if relay_sha != sha_at(repo, base, f"{plugin}/tests/fixtures/fake_smtp_server.php"):
        raise SystemExit("AC-CA2: fake_smtp_server.php differs between 5.18.103 and the pin")
    once(show("tests/fixtures/fake_smtp_server.php"), '$srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);', "fake_smtp_server.php")
    for f in ["lib/MailService.php", "lib/CrmApiClient.php", "lib/SqliteStore.php", "lib/EmailRecipients.php", "lib/QuotePdfSource.php", "lib/StoreInterface.php",
              "lib/CustomerEmails.php", "lib/EmailTemplate.php"]:   # D §H.5.16: byte for byte — so cases 5 and 6 compare P7's hunks alone
        if sha_at(repo, pin, f"{plugin}/{f}") != sha_at(repo, base, f"{plugin}/{f}"):
            raise SystemExit(f"AC-CA2: {f} differs between 5.18.103 and the pin — the two drives would not run the same mailer and store")
    mail = show("lib/MailService.php")
    once(mail, "$fp = @fsockopen($transport, $cfg['port'], $errno, $errstr, 15);", "lib/MailService.php")
    once(mail, "$ucrmFile = dirname($this->dataDir) . '/ucrm.json';", "lib/MailService.php")
    once(mail, "if (!file_exists($ucrmFile)) $ucrmFile = dirname(dirname($this->dataDir)) . '/ucrm.json';", "lib/MailService.php")
    once(mail, "'enc'  => trim($ec['smtp_enc']  ?? 'tls'),", "lib/MailService.php")
    if re.search(r"stream_socket_client|pfsockopen|socket_connect", mail):
        raise SystemExit("AC-CA2: MailService opens a connection other than by fsockopen")
    b_qs = show("lib/QuotationService.php", base)
    once(b_qs, "    private CrmApiClient $crm;", "5.18.103's lib/QuotationService.php")
    once(b_qs, "    protected function newMailService(): MailService", "5.18.103's lib/QuotationService.php")
    if "QuoteAgentCopy" in b_qs or "QuoteAgentCopy" in show("webhook.php", base):
        raise SystemExit("AC-CA2: 5.18.103's tree names QuoteAgentCopy")
    qse = show("lib/QuoteSendEvidence.php")
    once(qse, "$sub = self::activeId($retailerRows, $application['retailer_id'] ?? 0);", "lib/QuoteSendEvidence.php")
    once(qse, "if ($sub !== null) return ['id' => $sub, 'source' => 'kyc_submitter'];", "lib/QuoteSendEvidence.php")
    once(show("lib/SqliteStore.php"), "            CREATE TABLE IF NOT EXISTS [{$table}] (\n                id   INTEGER PRIMARY KEY,\n                data TEXT    NOT NULL\n            )", "lib/SqliteStore.php")
    lib = show("tests/quote_agent_copy/_lib.php")
    mk = re.search(r"function qac_mask\(\$v\)\n\{\n    if \(is_array\(\$v\)\) \{ foreach \(\$v as \$k => \$x\) \$v\[\$k\] = qac_mask\(\$x\); return \$v; \}\n"
                   r"    if \(!is_string\(\$v\)\) return \$v;\n((?:    \$v = preg_replace\(.*\);\n){4})    return \$v;\n\}\n", lib)
    if not mk:
        raise SystemExit("AC-CA2: the pin's qac_mask() is not the reviewed four masks")
    mask = "\n".join("            " + l.strip() for l in mk.group(1).rstrip("\n").split("\n"))
    # the OF_NONET list less fsockopen: the drives' — so the list AC_NONET adds fsockopen back to is the one reviewed in DS3
    import partO
    if partO.OF_NONET.replace("fsockopen,pfsockopen", "pfsockopen") != DRIVE_NONET or "fsockopen" not in partO.OF_NONET.split(","):
        raise SystemExit("AC: the drives' network-off list is not Part O's less fsockopen")

    # ── the probe and the functions, every placeholder from Git ──
    probe = open(os.path.join(HERE, "ac_probe.php.txt"), encoding="utf-8").read()
    for k, v in [("@@AC_CFG_KEYS@@", ",".join(CFG)), ("@@AC_ES_KEYS@@", ",".join(ES)), ("@@AC_KEYS@@", "quote_agent_cc_enabled,quote_agent_cc_since"),
                 ("@@AC_DRIVE_SHA@@", drive_sha), ("@@AC_RELAY_SHA@@", relay_sha), ("@@AC_DRIVE_NONET@@", DRIVE_NONET), ("@@AC_MASK@@", mask),
                 ("@@AC_RETAILERS_SQL@@", "SELECT data FROM [retailers] ORDER BY id"),
                 ("@@AC_MS_KEYS@@", ",".join(ms_keys))]:
        probe = put(probe, k, v)
    if "@@" in probe or re.search(r"^PHP$", probe, re.M) or "<?php" in probe:
        raise SystemExit("AC: the probe cannot travel in its heredoc")
    # the probe's guards (each asserted, so that a weakened copy of it fails here)
    for s in ["if (!is_file($drive) || hash_file('sha256', $drive) !== '" + drive_sha + "') $fin('refused=drive_sha');",
              "if (!is_file($relay) || hash_file('sha256', $relay) !== '" + relay_sha + "') $fin('refused=relay_sha');",
              "if (($mc['_source'] ?? '') !== 'plugin' || ($mc['host'] ?? '') !== '127.0.0.1') return 'mailservice';",
              "if (!empty($e['use_ucrm_email']) || !empty($e['sent_copy_enabled'])) return 'email_settings_on';",
              "foreach ([dirname($d), dirname(dirname($d))] as $up) { @unlink($up . '/ucrm.json'); clearstatcache(); if (file_exists($up . '/ucrm.json')) return 'ucrm_json'; }",
              "if ($busy > 0) $fin('refused=fixture_ids_in_use');", "$o[] = 'refusal=passed';",
              "if (($why = $refusal($T, $root)) !== '') return ['bad' => 'refused_' . $why];",
              "$h = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $NONET, '-d', 'allow_url_fopen=0',",
              "require_once $code . '/lib/MailService.php';",
              # AC-ON1 (DS4-R01): the installed reader counts; the log taken whole, counted before, in the copy and after;
              # email_settings.json by conf's cut to mailSetting()'s keys; plugin mail read the same on the copy as live
              "    $m = new ReflectionMethod('QuoteAgentCopy', 'mailLines');",
              "        $n0 = $mlCount($pdd);\n        if (!is_file($live)) { echo 'ml=absent live=', $n0, ' copy=0 after=', $mlCount($pdd); break; }",
              "        echo 'ml=copied live=', $n0, ' copy=', $mlCount($dir), ' after=', $mlCount($pdd);",
              "        if (file_exists($sub . '/quote_mail.log') || file_exists($sub . '/email_settings.json')) { echo '?dest'; exit(3); }",
              "        [$es] = $cutEs(rtrim($pdd, '/') . '/email_settings.json', $sub . '/email_settings.json', $MS);",
              "        [$es, $ne] = $cutEs($pdd . '/email_settings.json', $dir . '/email_settings.json', $ES);",
              "        echo 'ml=', $ml, ' ml_lines=', $mlCount($sub), ' es=', $es,\n"
              "             ' es_same=', QuoteAgentCopy::preconditions($sub)['lines'] === QuoteAgentCopy::preconditions($pdd)['lines'] ? 1 : 0;",
              # P7S-02 item 1: the fresh email_settings.json, exactly — plugin SMTP on the loopback relay, uCRM's mailer and the sent copy off
              "$ES7 = ['use_ucrm_email' => false, 'sent_copy_enabled' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_enc' => '',\n"
              "                'smtp_from' => 'quotes@company.example.test', 'quote_email_via_plugin' => true];"]:
        once(probe, s, "ac_probe.php.txt")
    # every drive is called after the refusal passed on both sandboxes, and the refusal is re-asked inside each drive
    i_ref = probe.index("$o[] = 'refusal=passed';")
    calls = [m.start() for m in re.finditer(r"= \$drive1\(", probe)]
    if len(calls) != 5 or min(calls) < i_ref or probe.index("$fin('refused=' . $why . '_' . $k);") > i_ref:
        raise SystemExit("AC-CA2: a drive could run before the refusal")
    if "smtp_pass" in probe or re.search(r"echo[^;]*\$(s|sess|d|data|ds)\[", probe):
        raise SystemExit("AC-CA2: the drives, a password or a transcript in the probe's output")
    for addr in re.findall(r"[a-z0-9._-]+@[a-z0-9.-]+", probe):
        if not addr.endswith(".example.test"):
            raise SystemExit(f"AC-CA2: an address outside the reserved domain in the probe: {addr}")
    funcs = funcs_raw
    for k, v in [("@@AC_PHP@@", probe.rstrip("\n")), ("@@AC_NONET@@", partO.OF_NONET), ("@@AC_L1_FILES@@", " ".join(l1)), ("@@AC_L1_SUMS@@", sums)]:
        funcs = put(funcs, k, v)
    if "@@" in funcs:
        raise SystemExit("AC: a placeholder is left in ac_funcs")
    ctx.update(ac_probe=probe, ac_funcs=funcs)
    doc.must("\nof_rb_after() {", 1, why="partO ran first")
    doc.insert_before(TN_ANCHOR, funcs + "\n", 1, why="AC functions and probe")
    fns = ["ac_php", "ac_php_ca2", "ac_mail_say", "ac_stage", "ac_after", "ac_on_pre", "ac_on_notes", "ac_on", "ac_rb_pre", "ac_rb1", "ac_rb_after"]
    for fn in fns:
        doc.must(f"\n{fn}() {{", 1, why=f"{fn} defined once")
    if doc.t.index("\nof_rb_after() {") > doc.t.index("\nac_php() {") or doc.t.index("\nac_rb_after() {") > doc.t.index(TN_ANCHOR):
        raise SystemExit("AC: the functions are not between partO's and the TN section")
    for fn in ["qf_copy", "qf_tool", "qf_show_tool", "qf_kv", "qf_byhand", "vn_book_copy", "drained", "cih_drop"]:
        doc.must(f"\n{fn}() {{", 1, why=f"AC calls {fn}, defined once")
    # the tool's data folder is the copy (qf_tool), so AC-ON reads the two files ac_on puts there
    doc.must('  docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$sub" -e DN_VAULT_FILE="$sub/vault.json" -e TMPDIR="$sub" "$CONTAINER" \\\n', 1,
             why="qf_tool: the tool's data folder is the reading's copy")

    # ── DS1's qf_on: AC-ON1's copy of quote_mail.log taken just before the book is copied (DS4-R01) ──
    on_copy = '  sub="$(qf_copy on "$inst" log)" || { bad "QF-ON0 a copy of the book could not be made'
    doc.insert_before(on_copy, '  ac_on_pre "$inst"   # 5.18.104 Part P: AC-ON1\'s copy of quote_mail.log, taken before the book (ac_on puts it where the tool reads it)\n',
                      1, why="qf_on: AC-ON1's log copy before the book's")

    # ── DS1's text: QF-RB1 reads Part P's setting just before the tool's off all (AC-RB1's "as it was") ──
    rb_call = '  o="$(UCRM_CONTAINER="$CONTAINER" bash "$REPO/$QF_WRAPPER" off all --rollback 2>&1 </dev/null | qf_safe)"; rc=${PIPESTATUS[0]}\n'
    doc.insert_before(rb_call, "  ac_rb_pre   # 5.18.104 Part P: quote_agent_cc_admins as it reads now, for AC-RB1's \"as it was\" (root docs/73 §H.5.14)\n",
                      1, why="QF-RB1: AC-RB1's before-reading")

    # ── the slots ──
    fill(doc, "AC stage A", "  ac_stage   # AC-L1 (a NO-GO before anything is copied), AC-N1, AC-N2 — Part P's stage A (root docs/73 §H.5.14)\n")
    fill(doc, "AC-CA", '  ac_after "$inst"   # AC-CA1, AC-CA2 — after the copy, through the installed code; no e-mail leaves the container\n')
    fill(doc, "AC-ON1", '  ac_on "$sub"   # AC-ON1 — AC-ON through the installed tool\'s --check agent-cc, while agent-cc is on\n')
    fill(doc, "AC-RB1", "  ac_rb1   # AC-RB1 — Part P's keys last, with the record keys, and read back (rulings R11; P7S-08), before the put-back\n")
    fill(doc, "AC-RB2", "  ac_rb_after   # AC-RB2 — a note after the put-back: P7's two collections stay, read by nothing\n")
    order = [doc.t.index(x) for x in ["\nqf_stage() {", "  vn_stage   #", "  of_stage   #", "  ac_stage   #", "\nqf_clear_and_baseline() {",
                                      "\nqf_after() {", '  vn_after "$inst"   #', '  of_after "$inst"   #', '  ac_after "$inst"   #',
                                      "\nqf_on() {", '  ac_on_pre "$inst"   #', on_copy, '  vn_on "$sub"   #', '  of_on "$sub"   #', '  ac_on "$sub"   #', '  cih_drop "$sub"\n}\n\n# ── 5.18.104: the one rollback sequence',
                                      "\nqf_rb1() {", "  ac_rb_pre   #", rb_call, "  of_rb1   #", "  vn_rb1   #", "  ac_rb1   #", '  note "QF-RB2 ',
                                      "\nqf_rb3() {", '  vn_rb_after "$QF_RB3_VC"   #', "  of_rb_after   #", "  ac_rb_after   #"]]
    if order != sorted(order):
        raise SystemExit("AC: a call is not inside the function its slot belongs to, or not in its order")
    # in qf_on: the run's folder made (cih_main), then AC-ON1's log copy, then the book's copy, then AC-ON1 last but the drop
    qo = doc.t[doc.t.index("\nqf_on() {"):doc.t.index("\n}\n", doc.t.index("\nqf_on() {"))]
    qs = [qo.find(x) for x in ["\n  cih_main || true   #", '\n  ac_on_pre "$inst"   #', "\n" + on_copy, '\n  ac_on "$sub"   #', '\n  cih_drop "$sub"']]
    if -1 in qs or qs != sorted(qs) or qo.count("ac_on_pre ") != 1 or qo.count("qf_copy ") != 1:
        raise SystemExit("AC-ON1: qf_on does not copy quote_mail.log after making the run's folder and before the book's copy")

    # ── what the operator reads: the header, the rollback paragraph, the after-only line, the option, the names, the steps ──
    doc.insert_after("#            to queued, ruling R12; no offer running, no recipient claimed); after it, OF-RB2 (a note).\n",
        "#\n"
        "#   5.18.104'S OWN CHECKS, Part P's (AC, \"agent copy\"; root docs/73 §H.5.14): counts and fixed codes in the log; never\n"
        "#            an address, a quote number or an account's id. NOTHING ASKS uCRM — plugin mail is judged from\n"
        "#            email_settings.json — and NO E-MAIL LEAVES THE CONTAINER. Refusal before the copy: AC-L1 the server's own\n"
        "#            PHP lints the pin's lib/QuoteAgentCopy.php and the three files P7 changes (P7S-07). P7's keys are QF-G4's\n"
        "#            (ruling R7): P7 has no stage-A rule of its own. Notes, on a copy through the PIN's code: AC-N1 how plugin\n"
        "#            mail is configured, the two quotation paths, email_cc_contacts, kyc_quote_send_via_crm, automatic quoting\n"
        "#            and the accounts by kind (accountCounts(), counts only); AC-N2 what the copy would have covered over 30\n"
        "#            days, by path, an upper bound. After the copy, through the INSTALLED code: AC-CA1 QuoteAgentCopy::on()\n"
        "#            off on the live configuration's copy, on with the key set (the control), off with it set as South Sudan,\n"
        "#            and P7's two collections absent on a first deploy (counted, never read, otherwise); AC-CA2 the positive and\n"
        "#            negative controls on a copy of the book, made unable to reach a live system first and read back so\n"
        "#            through the installed MailService (P7S-02: a fresh email_settings.json on the pin's loopback relay, no\n"
        "#            ucrm.json in reach, no WhatsApp sender, fixture accounts under example.test only), uCRM the pin's recording\n"
        "#            fake — the app and the webhook paths copied, the customer's own address and a quote with no record not,\n"
        "#            and with the key unset, or set as South Sudan, every e-mail equal to 5.18.103's. In an --after-only run,\n"
        "#            AC-ON1 through the installed tool's --check agent-cc, while agent-cc is on. In the rollback, AC-RB1 reads\n"
        "#            QF-RB1's result before the put-back (Part P's two keys cleared last, with the record keys, no hook, read\n"
        "#            back off, quote_agent_cc_admins as it was; ruling R11); after it, AC-RB2 (a note: both collections stay).\n",
        1, why="the header's Part P paragraph")
    doc.sub("# offers paused, the claimed recipients settled or returned — and OF-RB2 says what stays). 089's, 090's and 091's\n",
            "# offers paused, the claimed recipients settled or returned — and OF-RB2 says what stays; Part P's AC-RB1 reads its\n"
            "# two keys cleared last, with the record keys, and read back off, and AC-RB2 says its collections stay). 089's, 090's and 091's\n",
            1, why="the rollback paragraph names AC-RB")
    doc.sub("# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8, VN-M1–VN-K2, OF-M1–OF-CA5; QF-ON0–QF-ON5,\n"
            "# VA-ON1, VN-ON1–VN-ON4, OF-ON1 and OF-ON2 for each step that is on); then",
            "# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8, VN-M1–VN-K2, OF-M1–OF-CA5, AC-CA1,\n"
            "# AC-CA2; QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 for each step that is on); then",
            1, why="the after-only line names AC")
    doc.sub("#                        VN-K1, VN-K2, OF-M1, OF-M2, OF-CA1–OF-CA5, and QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4,\n"
            "#                        OF-ON1 and OF-ON2 (each step\n",
            "#                        VN-K1, VN-K2, OF-M1, OF-M2, OF-CA1–OF-CA5, AC-CA1, AC-CA2, and QF-ON0–QF-ON5, VA-ON1,\n"
            "#                        VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 (each step\n", 1, why="the --after-only option names AC")
    doc.sub('echo "                    OF-M2, OF-CA1–OF-CA5, OF-ON1, OF-ON2, OF-RB1 and OF-RB2 are Part O\'s (OF; §H.4.18); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n',
            'echo "                    OF-M2, OF-CA1–OF-CA5, OF-ON1, OF-ON2, OF-RB1 and OF-RB2 are Part O\'s (OF; §H.4.18); AC-L1, AC-N1, AC-N2, AC-CA1,"\n'
            'echo "                    AC-CA2, AC-ON1, AC-RB1 and AC-RB2 are Part P\'s (AC; §H.5.14); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n',
            1, why="the names line names AC")
    doc.sub('  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1"\n'
            '  echo "                    and OF-ON2 judge each step that is on."\n',
            '  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1,"\n'
            '  echo "                    OF-ON2 and AC-ON1 judge each step that is on."\n', 1, why="the steps' line names AC")
    doc.sub("# Q2-ON, QF-ON3 the ledger invariants, QF-ON4 Q3-ON and Q5-ON, QF-ON5 the marks and the R-05 race count; and AC-ON1's slot.\n",
            "# Q2-ON, QF-ON3 the ledger invariants, QF-ON4 Q3-ON and Q5-ON, QF-ON5 the marks and the R-05 race count; then each part's own\n"
            "# (VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2, AC-ON1).\n", 1, why="qf_on's comment: AC-ON1's slot filled")

    # ── the result, as a whole: every code reported by a line of its own, and nothing sent ──
    for code in AC_CODES:
        n = len(re.findall(r'(?:ok|bad|note|stop) "(?:NO-GO \()?' + re.escape(code) + r'[ )]', doc.t))
        if n < 1:
            raise SystemExit(f"AC: no ok/bad/note/stop line reports {code}")
    doc.must('ac_php() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -d allow_url_fopen=0 -d "disable_functions=$AC_NONET" -r "$AC_PHP" "$@"; }', 1)
    doc.must('ac_php_ca2() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -d allow_url_fopen=0 -r "$AC_PHP" ca2 "$@"; }', 1)
    if len(re.findall(r"\bac_php_ca2 ", doc.t)) != 1 or re.search(r'php [^\n]*-r "\$AC_PHP"', doc.t.replace(
            'ac_php() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -d allow_url_fopen=0 -d "disable_functions=$AC_NONET" -r "$AC_PHP" "$@"; }', "").replace(
            'ac_php_ca2() { dxo php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -d allow_url_fopen=0 -r "$AC_PHP" ca2 "$@"; }', "")):
        raise SystemExit("AC: AC_PHP is run other than through ac_php and ac_php_ca2 (ca2 once)")
    doc.mustnot("@@AC_")
    # nothing in Part P's functions reaches uCRM or a relay of its own: no curl, no HTTP client, no mailer but AC-CA2's
    # drives (through the pin's drive.php, whose uCRM is its in-process fake)
    code = "\n".join(l for l in funcs.replace(probe, "").split("\n") if not l.lstrip().startswith("#"))
    if re.search(r"\bcurl\b|CrmApiClient|new MailService|sendmail|smtp_pass", code):
        raise SystemExit("AC: the AC functions reach uCRM or a mailer of their own")
    print(f"  AC: four files linted by AC-L1 ({', '.join(l1)}); drive.php {drive_sha[:12]}…, relay {relay_sha[:12]}…; "
          f"AC-N1 copies {len(CFG)} configuration and {len(ES)} email_settings.json keys")
