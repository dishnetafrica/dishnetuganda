<?php
declare(strict_types=1);
/**
 * test_quote_cron_log.php — 5.18.54, docs/46 row 46 (N-19): the quote cron's log says what the notifier did.
 *
 * cron_quote_wa.php wrote "TEXT SENT" and "PDF SENT" after every send, whatever happened: the notifier's sendVia and
 * sendDocument return nothing. Measured before the fix: WhatsApp refused a quotation's text, the Failed Queue held it,
 * and the cron's own log (quote_wa_log.json) said TEXT SENT.
 *
 *    1. Uganda: a refused quotation text reads "TEXT NOT SENT", with the notifier's reason; the failure is in the Failed
 *       Queue; the PDF, which went, reads "PDF SENT"
 *    2. Uganda: a quotation that went reads "TEXT SENT" and "PDF SENT", as before; with PDFs switched off
 *       (wa_send_pdf = false) the PDF reads "PDF NOT SENT" — the notifier returns before sending, and its result was
 *       the text's until a document send started from no result (row 46, in the notifier)
 *    3. South Sudan: the 5.18.53 words, whatever happened
 *    4. weakened copies, each caught
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php), the real cron_quote_wa.php, a fake uCRM
 * that lists and prints quotes, and the fake Evolution told to refuse text sends. Nothing leaves the machine; every
 * person, number and document is fictitious. `--no-mutants` skips 4.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

/**
 * One run of the quote cron in a sandbox holding quote Q-3401, made in uCRM for SjScenario's customer 15. With $refuse,
 * the fake Evolution refuses every text send (the PDF is a media send and still goes).
 */
function qc_run(string $tree, string $tenant, bool $refuse, array $extra = []): array
{
    $base = ($tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba']) + $extra;
    $s = SjSandbox::start($tree, $base, 'qc' . substr($tenant, 0, 2));
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $crm['quotes'] = ['3401' => ['id' => 3401, 'number' => 'Q-3401', 'clientId' => 15, 'status' => 1, 'createdDate' => date('c'),
        'items' => [['label' => 'Starlink Standard Kit', 'quantity' => 1, 'price' => 2500000.0, 'total' => 2500000.0]],
        'total' => 2500000.0]];
    $s->seedCrm($crm);
    SjScenario::staff($s);
    if ($refuse) $s->http('GET', "{$s->evo}/__test/fail_next?n=20");
    [$rc, $out] = $s->run('cron_quote_wa.php');
    $log = json_decode((string)@file_get_contents($s->data . '/quote_wa_log.json'), true);
    $lines = array_values(array_filter(array_map(fn($e) => (string)($e['msg'] ?? ''), is_array($log) ? $log : []),
        fn($m) => strpos($m, 'Q-3401') !== false && preg_match('/^(TEXT|PDF) (NOT )?SENT/', $m)));
    $queued = [];
    try { $queued = $s->q("SELECT event, status FROM notification_queue WHERE event = 'ops_quote_wa'"); } catch (\Throwable $e) {}
    $media = (array)($s->http('GET', "{$s->evo}/__test/state")[2]['media_calls'] ?? []);
    $s->stop();
    return ['rc' => $rc, 'lines' => $lines, 'queued' => $queued, 'media' => count($media), 'out' => substr($out, 0, 300)];
}
function qc_show(array $r): string { return substr((string)json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 700); }
function qc_line(array $r, string $what): string
{
    foreach ($r['lines'] as $l) if (strpos($l, $what . ' ') === 0) return $l;
    return '';
}

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
$caseRefused = function (string $tree): array {
    $r = qc_run($tree, 'uganda', true);
    $text = qc_line($r, 'TEXT');
    return [
        [strpos($text, 'TEXT NOT SENT (') === 0 && strpos($text, 'Q-3401') !== false,
         'the refused text reads TEXT NOT SENT, with the reason', qc_show($r)],
        [count($r['queued']) === 1, 'and the failure waits in the Failed Queue (ops_quote_wa)', qc_show($r)],
        [strpos(qc_line($r, 'PDF'), 'PDF SENT ') === 0 && $r['media'] === 1, 'the PDF went, and reads PDF SENT', qc_show($r)],
    ];
};
$caseSent = function (string $tree): array {
    $r = qc_run($tree, 'uganda', false);
    return [[strpos(qc_line($r, 'TEXT'), 'TEXT SENT ') === 0 && strpos(qc_line($r, 'PDF'), 'PDF SENT ') === 0 && $r['queued'] === [],
             'a quotation that went reads TEXT SENT and PDF SENT, as before', qc_show($r)]];
};
$casePdfOff = function (string $tree): array {
    $r = qc_run($tree, 'uganda', false, ['wa_send_pdf' => false]);
    return [[strpos(qc_line($r, 'TEXT'), 'TEXT SENT ') === 0 && strpos(qc_line($r, 'PDF'), 'PDF NOT SENT (no attempt was made)') === 0
             && $r['media'] === 0, 'PDFs switched off: the text reads TEXT SENT, the PDF PDF NOT SENT', qc_show($r)]];
};
$caseSouthSudan = function (string $tree): array {
    $r = qc_run($tree, 'south-sudan', true);
    return [[strpos(qc_line($r, 'TEXT'), 'TEXT SENT ') === 0 && count($r['queued']) === 1,
             'a refused text still reads TEXT SENT there, as in 5.18.53 (docs/46 §E)', qc_show($r)]];
};

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — WhatsApp refuses the quotation text\n";
foreach ($caseRefused($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n2. Uganda — the quotation goes\n";
foreach ($caseSent($root) as [$ok, $m, $d]) is_($ok, $m, $d);
foreach ($casePdfOff($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n3. South Sudan — the 5.18.53 words\n";
foreach ($caseSouthSudan($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n4. Weakened copies, each caught\n";
$failedWhere = function (array $triples, string $what): bool {
    foreach ($triples as [$ok, $m]) if (!$ok && strpos($m, $what) !== false) return true;
    return false;
};
$mutants = [
    'the result not read' => ['cron_quote_wa.php',
        "    \$r = \$n->lastSendResult();\n    if (!empty(\$r['success'])) return \"{\$what} SENT\";\n",
        "    return \"{\$what} SENT\";\n",
        fn(string $t) => $failedWhere($caseRefused($t), 'reads TEXT NOT SENT'), 'the refused text read TEXT SENT'],
    'every send reported not sent' => ['cron_quote_wa.php',
        "    if (!empty(\$r['success'])) return \"{\$what} SENT\";\n", '',
        fn(string $t) => $failedWhere($caseSent($t), 'reads TEXT SENT and PDF SENT'), 'a quotation that went read NOT SENT'],
    'a document send that keeps the previous result' => ['lib/NotificationService.php',
        "        if (\$this->quoteLogUg()) { \$this->_lastSendSuccess = false; \$this->_lastHttpCode = null; \$this->_lastError = null; }\n", '',
        fn(string $t) => $failedWhere($casePdfOff($t), 'PDFs switched off'), 'the skipped PDF read PDF SENT'],
    'not gated' => ['cron_quote_wa.php',
        "    if (!\$ug) return \"{\$what} SENT\";\n", '',
        fn(string $t) => $failedWhere($caseSouthSudan($t), 'as in 5.18.53'), 'South Sudan\'s log changed'],
];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $old, $new, $caught, $why]) {
    [$t, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) {
        is_(false, "caught: {$name}", "the anchor was found {$n} times in {$rel}");
    } else {
        $ok = (bool)$caught($t);
        is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    }
    exec('rm -rf ' . escapeshellarg($t));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
