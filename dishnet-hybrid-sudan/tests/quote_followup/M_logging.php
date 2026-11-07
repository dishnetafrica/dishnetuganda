<?php
declare(strict_types=1);
/**
 * Section M of tests/test_quote_followup.php — logging (5.18.104, root docs/73 §H.2.14 M, §C C8; the task's privacy
 * rule: new logs carry counts and fixed codes only). TEST ONLY.
 *
 *   M01 every log statement 5.18.104 writes — error_log, PluginLog, echo, print, printf, fwrite, master_log and the tool's
 *       sf_out, in its new runtime files whole and in the added lines of its changed ones (the one merged declared list) —
 *       names no variable that holds a person's data (a phone, a name, a body, an e-mail, an amount, a staff member: by
 *       the names on QM_DENY and, B10-fix1, by the words of any name — a phone, a staff member, an id of a person such as
 *       `$_ofMeId`), labels no value as a person's id (a literal ending `staff=`, `owner_id:`, `staff #`, …), and a new
 *       file logs an exception by its class, never by its message. A write to a private sheet — a handle the same file
 *       opens with fopen(…, 'x') and chmods 0600 — is not a log (D 1454: the owners' mapping goes to such a sheet, never
 *       the log); exactly one such write exists, tools/sales_followup_report.php's owners' sheet;
 *   M02 the jobs' stdout and the log lines they add (data/plugin.log), on Uganda books holding fictitious customers, a
 *       quotation with an amount and a salesperson (staff #41): quote_chase (tracking and the chase), followup_send's quote
 *       pass, quote_daily_list (its line required, the list built for staff #41 and sent) and, B10-fix1, the Offers
 *       screen's actions (offer_save and offer_discard posted by admin #41 through the served plugin) — no 9+ digit run,
 *       no fixture name, no amount, no staff id, each its own assertion;
 *   M03 `erase` prints no number: tests/test_sales_followup_tool.php TL10, run on the judged tree (one test per rule).
 */
require_once __DIR__ . '/declared.php';

/** The variables a log line may never name: a person's data. */
const QM_DENY = ['$phone', '$to', '$msisdn', '$number', '$customerName', '$staffName', '$fullName', '$displayName', '$display_name', '$pushName',
                 '$body', '$text', '$caption', '$email', '$amount', '$price', '$customer', '$staff', '$retailer', '$owner', '$remoteJid', '$jid',
                 '$digits', '$recipient'];
const QM_LOGGERS = ['error_log', 'master_log', 'printf', 'fwrite', 'sf_out'];
/** B10-fix1 (B10-R01): a word of a variable's name that names a person's data wherever it stands in the name. */
const QM_DENY_WORDS = ['phone', 'msisdn', 'jid', 'staff', 'retailer', 'actor', 'email', 'amount', 'price', 'caption', 'recipient', 'digits'];
/** … and the words that, before `id`, make it a person's id (`$_ofMeId`, `$ownerId`, `$user_id`). */
const QM_ID_OWNERS = ['me', 'my', 'user', 'owner', 'agent', 'seller', 'staff', 'admin', 'actor', 'by', 'retailer', 'salesperson', 'person', 'customer', 'client'];
/** A literal that ends by labelling the value concatenated after it as a person's id. */
const QM_ID_LABEL = '/(?:^|[^a-z])(?:staff|owner|retailer|agent|seller|actor|admin|user|salesperson|customer|client|by)(?:[ _-]?id)?\s*(?:=|:|#)\s*$/i';

/** The words of a variable's name: `$_ofMeId` → of, me, id. */
function qm_words(string $var): array
{
    $n = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', ltrim($var, '$'));
    return array_values(array_filter(preg_split('/[\s_]+/', strtolower($n)) ?: [], 'strlen'));
}

/** Why a variable's name says it holds a person's data, or ''. */
function qm_person_var(string $var): string
{
    if (in_array($var, QM_DENY, true)) return 'names ' . $var;
    $w = qm_words($var);
    foreach ($w as $i => $x) {
        if (in_array($x, QM_DENY_WORDS, true)) return 'names ' . $var . ' (the word ' . $x . ')';
        if ($x === 'id' && $i > 0 && in_array($w[$i - 1], QM_ID_OWNERS, true)) return 'names ' . $var . ' (a person\'s id)';
        if ($x === 'name' && count($w) > 1) return 'names ' . $var . ' (a person\'s name)';
    }
    return '';
}

/**
 * The log statements of $src (a PHP file), each [line, variables named, names an exception message, the id label a
 * literal ends with ('' for none), the handle an fwrite writes to (a variable's name, '' otherwise)]; only those whose
 * first line is in $lines when $lines is given (a changed file: its added lines).
 */
function qm_log_statements(string $src, ?array $lines = null): array
{
    $toks = token_get_all($src); $n = count($toks); $out = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t)) continue;
        $k = $i - 1;
        while ($k >= 0 && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) $k--;
        $is = ($t[0] === T_STRING && in_array(strtolower($t[1]), QM_LOGGERS, true) && !($k >= 0 && is_array($toks[$k]) && $toks[$k][0] === T_FUNCTION))
           || in_array($t[0], [T_ECHO, T_PRINT], true)
           || ($t[0] === T_STRING && $t[1] === 'PluginLog');
        if (!$is || ($lines !== null && empty($lines[$t[2]]))) continue;
        $depth = 0; $vars = []; $msg = false; $codeOnly = false; $label = ''; $handle = ''; $first = true;
        $isFwrite = $t[0] === T_STRING && strtolower($t[1]) === 'fwrite';
        for ($j = $i + 1; $j < $n; $j++) {
            $u = $toks[$j];
            if (is_array($u) && $u[0] === T_WHITESPACE) continue;
            // fwrite's first argument: the handle (a variable), or STDOUT / STDERR (a constant, which is the output)
            if ($isFwrite && $first && $u !== '(') { $first = false; if (is_array($u) && $u[0] === T_VARIABLE) $handle = $u[1]; }
            if ($u === '(') $depth++;
            elseif ($u === ')') { if (--$depth < 0) break; }
            elseif ($u === ';' && $depth === 0) break;
            elseif (is_array($u) && $u[0] === T_VARIABLE) $vars[] = $u[1];
            elseif (is_array($u) && $u[0] === T_STRING && $u[1] === 'getMessage') $msg = true;
            // a message reduced to a fixed code first (lower-case letters, digits, '_' and ':' only) is a code, not a message
            elseif (is_array($u) && $u[0] === T_CONSTANT_ENCAPSED_STRING && $u[1] === "'/[^a-z0-9_:]/'") $codeOnly = true;
            // B10-fix1 (B10-R01): a literal (or a part of an interpolated string) that labels what follows as a person's id
            if (is_array($u) && in_array($u[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && $label === '') {
                $lit = $u[0] === T_CONSTANT_ENCAPSED_STRING ? substr($u[1], 1, -1) : $u[1];
                if (preg_match(QM_ID_LABEL, $lit, $lm)) $label = trim($lm[0], " \t\n\r(");
            }
        }
        $out[] = [$t[2], array_values(array_unique($vars)), $msg && !$codeOnly, $label, $handle];
    }
    return $out;
}

$QF_SECTIONS['M'] = ['title' => 'Logging: counts and fixed codes only (§H.2.14 M; C8)', 'run' => function (QfCtx $ctx) {
    $ctx->run('M01 every 5.18.104 log statement names no person\'s data, and a new file logs an exception by its class', function () use ($ctx) {
        [$B, $why] = qf_n_base($ctx->tmp . '/base221');
        $ctx->t($B !== null, 'M01 control: Git supplies ' . QF_N_BASE, $why);
        if ($B === null) return;
        $probe = qm_log_statements("<?php\nerror_log('x ' . \$phone);\nPluginLog::write('y', 'n=' . \$n);\nfunction sf_out(string \$l) {}\nsf_out('z ' . \$e->getMessage());\n"
            . "sf_out('c ' . preg_replace('/[^a-z0-9_:]/', '', \$e->getMessage()));\nPluginLog::write('o', 'save offer=' . \$o . ' staff=' . \$id);\n"
            . "echo 'owner ' . \$n . \"\\n\";\nfwrite(\$fh, \"owner {\$n} = staff #{\$sid}\\n\");\nfwrite(STDERR, 'by ' . \$x);\n");
        $ctx->eq($probe, [[2, ['$phone'], false, '', ''], [3, ['$n'], false, '', ''], [5, ['$e'], true, '', ''], [6, ['$e'], false, '', ''],
                          [7, ['$o', '$id'], false, 'staff=', ''], [8, ['$n'], false, '', ''], [9, ['$fh', '$n', '$sid'], false, 'staff #', '$fh'],
                          [10, ['$x'], false, '', '']],
                 'M01 control: the reader finds each log statement, its variables, an exception message (a message reduced to a code is a code), '
                 . 'a literal labelling a staff id (not the index form `owner n`) and the handle an fwrite writes to (none for STDERR)');
        $ctx->eq([qm_person_var('$_ofMeId'), qm_person_var('$ownerId'), qm_person_var('$user_id'), qm_person_var('$_ofName'), qm_person_var('$custPhone'),
                  qm_person_var('$staffJobs'), qm_person_var('$name'), qm_person_var('$sfrId'), qm_person_var('$lineKind'), qm_person_var('$ofId')],
                 ['names $_ofMeId (a person\'s id)', 'names $ownerId (a person\'s id)', 'names $user_id (a person\'s id)', 'names $_ofName (a person\'s name)',
                  'names $custPhone (the word phone)', 'names $staffJobs (the word staff)', '', '', '', ''],
                 'M01 control: a person\'s data by the words of a name (an id of a person, a compound name, a phone), not an offer\'s or a sheet row\'s id');
        $bad = []; $files = 0; $stmts = 0; $sheets = [];
        foreach (qf_n_walk($ctx->root) as $rel => $abs) {
            if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0 || qf_declared_entry($rel) === null) continue;
            $files++;
            $new = !is_file($B . '/' . $rel);
            $lines = null;
            if (!$new) {
                $lines = [];
                foreach (qf_n_hunks($B . '/' . $rel, $abs) as $h) for ($k = 0; $k < max(1, $h[3]); $k++) $lines[$h[2] + $k] = true;
            }
            $src = (string)file_get_contents($abs);
            foreach (qm_log_statements($src, $lines) as [$line, $vars, $msg, $label, $handle]) {
                // a private sheet — a handle this file opens exclusively (fopen …, 'x') and makes mode 0600 — is not a log (D 1454)
                if ($handle !== '' && preg_match('/' . preg_quote($handle, '/') . '\s*=\s*@?fopen\([^;]*,\s*\'x\'\s*\)/', $src) && preg_match('/@?chmod\([^;]*,\s*0600\s*\)/', $src)) {
                    $sheets[] = "{$rel}:{$line}";
                    continue;
                }
                $stmts++;
                foreach ($vars as $v) { $why = qm_person_var($v); if ($why !== '') $bad[] = "{$rel}:{$line} {$why}"; }
                if ($label !== '') $bad[] = "{$rel}:{$line} labels a person's id ({$label})";
                if ($new && $msg) $bad[] = "{$rel}:{$line} logs an exception's message";
            }
        }
        $ctx->eq($bad, [], 'M01 the ' . $stmts . ' log statements of the ' . $files . ' declared runtime PHP files');
        $ctx->t($stmts > 30, 'M01 control: the statements were read (' . $stmts . ')');
        $ctx->eq(array_map(function ($w) { return preg_replace('/:\d+$/', '', $w); }, $sheets), ['tools/sales_followup_report.php'],
                 'M01 the one private sheet a 5.18.104 file writes, mode 0600, is the report\'s owners\' sheet (D 1454) — no other write is excused');
    });

    $ctx->run('M02 the jobs\' stdout and their log lines: no 9+ digit run, no fixture name, no amount, no staff id', function () use ($ctx) {
        $tree = $ctx->tree();
        foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChase', 'QuoteChaseText', 'FollowUpService'] as $l) require_once $tree . '/lib/' . $l . '.php';
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'M02 control: the fake Evolution answers');
        if ($evo === '') return;
        $log0 = is_file($tree . '/data/plugin.log') ? (string)file_get_contents($tree . '/data/plugin.log') : '';
        $names = ['Rukundo Kabafixture', 'Akello Fixturename', 'Nsubuga Sellerfixture', 'Odongo Fixtureadmin'];   // fictitious; each part distinctive
        $phones = ['256772509101', '256772509102', '256700509103', '256700509141'];
        $out = '';
        // followup_send's quote pass: two chases, from a customer the fixture names
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c1 = qf_chase($b, $phones[0], 'support', 'Q-9901');
        $c2 = qf_chase($b, $phones[1], 'sales', 'Q-9902');
        $p->prepare('UPDATE wa_conversations SET display_name = ? WHERE id = ?')->execute([$names[0], $c1['conv']]);
        $p->prepare('UPDATE wa_conversations SET display_name = ? WHERE id = ?')->execute([$names[1], $c2['conv']]);
        [, $o1] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()]);
        $out .= $o1;
        $ctx->t(count(qf_texts($evo)) >= 1 && strpos($o1, '[followup_send]') !== false, 'M02 control: the quote pass sent (' . count(qf_texts($evo)) . ') and wrote its line', $o1);
        // quote_chase: tracking and the chase, on a book holding a quotation with an amount, sent to a named customer, and a
        // salesperson, staff #41, with a phone (the daily list's owner below)
        $b2 = $ctx->book(qf_ccfg(['evo_api_url' => $evo, 'evo_api_key' => 'fake-key-qf', 'alert_whatsapp' => '256700509199']), 'm');
        $p2 = $b2['pdo'];
        qf_staff($p2, 41, $names[2], true, ['phone' => $phones[3]]);
        $conv = qf_conv($p2, $phones[2], 'support');
        $p2->prepare('UPDATE wa_conversations SET display_name = ? WHERE id = ?')->execute([$names[0], $conv]);
        qf_in($p2, $conv, 'Hello, I need a kit (fixture)', '2026-10-30 08:00:00');
        qf_qmsg($p2, $conv, 'quote_manual', qf_ref_text('Q-9903') . "\n💰 *Total:* UGX 1,250,000\n", '2026-10-30 09:00:00');
        [, $o2] = qf_cron($ctx, $b2, 'cron/quote_chase.php', ['_qf_test_now' => qf_gnow()]);
        $out .= $o2;
        $ctx->t(strpos($o2, 'quote_chase: tracking') !== false && strpos($o2, 'inserted=1') !== false, 'M02 control: quote_chase ran, tracked the quotation and wrote its tracking line', $o2);
        // quote_daily_list on the same book, the list on, the quotation staff #41's: its line, the list built and sent to #41
        $p2->exec("UPDATE quote_tracker SET owner_staff_id = 41, owner_source = 'quotes_log'");
        qf_http('GET', $evo . '/__test/reset');
        [, $o3] = qf_cron($ctx, $b2, 'cron/quote_daily_list.php', ['_qf_test_now' => qf_t('2026-11-02 08:30:00')],
                          $b2['cfg'] + ['quote_daily_list_enabled' => '1', 'quote_daily_list_since' => '2026-11-01 00:00:00']);
        $out .= $o3;
        $toOwner = array_values(array_filter(qf_texts($evo), function ($c) use ($phones) { return (string)($c['number'] ?? '') === $phones[3]; }));
        $ctx->eq([strpos($o3, 'quote_daily_list: ') === 0, (bool)preg_match('/ built=1 /', $o3), (bool)preg_match('/ sent=1 /', $o3), count($toOwner)],
                 [true, true, true, 1], 'M02 control: quote_daily_list ran — its line, the list built for staff #41 and sent to that account\'s phone', $o3);
        $log = (string)@file_get_contents($tree . '/data/plugin.log');
        $added = substr($log, strlen($log0));
        // B10-fix1 (B10-R01): the Offers screen's actions, posted by admin #41 through the served plugin; what they add to its log
        $offers = ''; $admin = 0; $oid = 0; $byIds = [];
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $sb = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on', 'offers_test_enabled' => '1'], 'qmo');
        try {
            qf_blob($sb->store()->getPdo(), 'retailers', ['id' => 40, 'name' => 'Filler (test)', 'is_active' => false]);
            $admin = $sb->staff('admin', ['name' => $names[3], 'email' => 'qm-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '0700509141']);
            $sb->login('admin', 'qm-admin@example.test', 'sj-password-1');
            $slog0 = (string)@file_get_contents($sb->data . '/plugin.log');
            $post = function (array $f) use ($sb): array {
                preg_match('/name="_csrf" value="([^"]+)"/', $sb->http('GET', "{$sb->base}?page=login")[1], $m);
                return $sb->http('POST', "{$sb->base}?page=dashboard&tab=wa_offers", http_build_query($f + ['_csrf' => html_entity_decode($m[1] ?? '', ENT_QUOTES)]),
                                 ['Content-Type: application/x-www-form-urlencoded', 'Sec-Fetch-Site: same-origin'], $sb->jars['admin']);
            };
            $post(['action' => 'offer_save', 'offer' => '0', 'title' => 'Logging offer (test)', 'wording_1' => 'A fictitious first wording for the logging test.',
                   'wording_2' => 'A fictitious second wording, unlike the first one.', 'wording_3' => '', 'size' => '100']);
            $oid = (int)$sb->store()->getPdo()->query('SELECT MAX(id) FROM wa_offers')->fetchColumn();
            $post(['action' => 'offer_discard', 'offer' => (string)$oid]);
            $offers = substr((string)@file_get_contents($sb->data . '/plugin.log'), strlen($slog0));
            $byIds = array_map('intval', $sb->store()->getPdo()->query('SELECT DISTINCT by_id FROM wa_offer_events WHERE by_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $sb->stop();
        }
        $ol = array_values(array_filter(explode("\n", $offers), function ($l) { return strpos($l, '[wa_offers] ') !== false; }));
        $ctx->eq([$admin, $oid > 0, $byIds, count($ol), (bool)preg_match('/\[wa_offers\] offer_save offer=' . $oid . ' /', $ol[0] ?? ''),
                  (bool)preg_match('/\[wa_offers\] offer_discard offer=' . $oid . ' /', $ol[1] ?? '')],
                 [41, true, [41], 2, true, true], 'M02 control: admin #41 saved and discarded an offer through the served plugin — the book records #41 as the actor, '
                 . 'the log has the two actions\' lines', $offers);
        $all = $out . "\n" . $added . "\n" . $offers;
        // each clause its own assertion, so a copy that breaks one is caught by that one
        $ctx->eq(preg_match_all('/\d{9,}/', $all, $m) ? array_values(array_unique($m[0])) : [], [], 'M02 no 9+ digit run in stdout (' . strlen($out) . ' bytes) or the log lines added ('
                 . (substr_count($added, "\n") + count($ol)) . ')');
        $ctx->eq(array_values(array_filter($names, function ($nm) use ($all) {
                     foreach (explode(' ', $nm) as $part) if (stripos($all, $part) !== false) return true;
                     return false;
                 })), [], 'M02 no fixture name (a customer\'s, the salesperson\'s or the admin\'s, whole or by either part)');
        $ctx->eq(preg_match_all('/\bUGX\b|\b\d{1,3}(?:,\d{3})+\b|\b1250000\b/', $all, $am) ? $am[0] : [], [], 'M02 no amount (the quotation\'s UGX 1,250,000 in any form)');
        $ctx->eq(preg_match_all('/\b(?:staff|owner|retailer|agent|seller|admin|actor|user|by)(?:[ _-]?id)?\s*(?:[=:#]\s*|\s+#?)41\b|#41\b/i', $all, $sm) ? $sm[0] : [], [],
                 'M02 no staff id (staff #41 by any label: staff=41, staff:41, staff #41, owner_id 41, #41)');
    });

    $ctx->run('M03 erase prints no number: tests/test_sales_followup_tool.php TL10, on the judged tree', function () use ($ctx) {
        $o = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/test_sales_followup_tool.php') . ' --no-mutants --root=' . escapeshellarg($ctx->root)
            . " --sections=TL --cases='TL10 TL18' 2>&1", $o, $rc);
        $tl10 = array_values(array_filter($o, function ($l) { return strpos($l, '  ok   TL10 erase prints no number') === 0; }));
        $ctx->eq([$rc, count($tl10), array_values(array_filter($o, function ($l) { return strpos($l, '  FAIL ') === 0; }))], [0, 1, []],
                 'M03 TL10 passes on the judged tree: erase prints no number', implode("\n", array_slice($o, -10)));
    });
}];
