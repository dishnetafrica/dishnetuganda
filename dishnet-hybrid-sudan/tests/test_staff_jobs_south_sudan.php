<?php
declare(strict_types=1);
/**
 * test_staff_jobs_south_sudan.php — 5.18.50 (docs/44 D2, release A): South Sudan behaves as 5.18.49, byte for byte.
 *
 * Release A changes Uganda only: every Uganda line is a separate branch, or a condition that is false wherever
 * StaffJobsGate does not read Uganda. This test runs the same South Sudan day twice — once on 5.18.49, taken from Git
 * (commit e076632), once on this tree — and requires every output to be identical once the values that differ between
 * ANY two runs are normalised: each sandbox's own ports and tokens, the day's form tokens, clock readings, "Ns ago",
 * and the in-request cron tick that prints after </html> — and each tree's own version label, "v" and the version
 * its manifest.json names (5.18.49 / 5.18.50), which every page prints three times. Only that label: a bare version
 * number anywhere else is compared.
 *
 * The day is the job traffic of fixtures/staff_jobs_scenario.php, then:
 *   pages    the Staff page; ＋ New Job and My Jobs, as the admin and as a technician; Bulk Dispatch; WA Events; the
 *            Message Log; the dashboard — the whole HTML of each
 *   API      My Jobs from the cache and live; job detail; both engineer lists; get_ucrm_users; Clear Cache; auto-map;
 *            set_ucrm_user_id; reading a survey
 *   forms    creating a staff account and editing one: the message shown, and the rows stored afterwards
 *   records  every uCRM request, every WhatsApp text, the Message Log, the webhook log, the customer's e-mail
 *
 * A difference is reported by position and length only: these pages carry the South Sudan staff lists, and names and
 * addresses are never printed.
 *
 *   php test_staff_jobs_south_sudan.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

/** Where two strings first differ — never what they say. */
function where_(string $a, string $b): string
{
    $n = min(strlen($a), strlen($b)); $i = 0;
    while ($i < $n && $a[$i] === $b[$i]) $i++;
    $line = substr_count(substr($a, 0, $i), "\n") + 1;
    return sprintf('first difference at byte %d (line %d); lengths %d / %d; md5 %s / %s', $i, $line, strlen($a), strlen($b), substr(md5($a), 0, 8), substr(md5($b), 0, 8));
}

/** The values that differ between any two runs of the same day, on any tree. */
function norm_(string $x, SjSandbox $s, bool $page = false): string
{
    if ($page && ($p = strpos($x, '</html>')) !== false) $x = substr($x, 0, $p + 7);   // the cron tick prints after it
    static $ver = [];
    if (!isset($ver[$s->root])) {
        $m = json_decode((string)@file_get_contents($s->root . '/manifest.json'), true);
        $ver[$s->root] = (string)($m['information']['version'] ?? '');
    }
    if ($ver[$s->root] !== '') $x = (string)preg_replace('/\bv' . preg_quote($ver[$s->root], '/') . '\b/', 'v<version>', $x);
    foreach ($s->tok as $k => $t) $x = str_replace($t, "<tok:{$k}>", $x);
    // The sandbox's own directories, which a page may print (the AI setup page names the flyer's path): per run.
    $x = str_replace([$s->data, $s->plug], ['<data>', '<plug>'], $x);
    foreach (['crm' => $s->crm, 'evo' => $s->evo, 'plugin' => (string)preg_replace('#/public\.php$#', '', $s->base)] as $k => $a) {
        $x = str_replace([$a, str_replace('/', '\/', $a)], "<{$k}>", $x);
    }
    $x = (string)preg_replace('/\b\d{8}:[0-9a-f]{40}\b/', '<day-token>', $x);
    $x = (string)preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value="<csrf>"', $x);
    $x = (string)preg_replace('/\b\d+[smhd] ago\b/', '<ago>', $x);
    $x = (string)preg_replace('/"cache_age_sec":\d+/', '"cache_age_sec":<n>', $x);
    // The sandbox's own mail relay listens on a port picked per run, and the e-mail settings card prints it: that
    // number, in that field, and nothing else.
    $mail = json_decode((string)@file_get_contents($s->data . '/email_settings.json'), true);
    if (is_array($mail) && (int)($mail['smtp_port'] ?? 0) > 0) {
        $x = (string)preg_replace('/(name="smtp_port"[^>]*\bvalue=")' . (int)$mail['smtp_port'] . '"/', '${1}<relay-port>"', $x);
    }
    return (string)preg_replace('/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\b/', '<clock>', $x);
}

$day = function (SjSandbox $s): array {
    $o = [];
    $s->staff('acct2', ['name' => 'Sandbox Second Accountant', 'email' => 'acct2@example.test', 'role' => 'accountant', 'phone' => '+211900000114']);
    foreach ([['tech', 'GET', 'scheduling_jobs', null, ''], ['tech', 'GET', 'scheduling_jobs', null, '&refresh=1'],
              ['admin', 'GET', 'scheduling_jobs', null, '&refresh=1'], ['tech', 'GET', 'scheduling_job_detail', null, '&job_id=901'],
              ['admin', 'GET', 'get_support_staff', null, ''], ['tech', 'GET', 'support_engineers', null, ''],
              ['admin', 'GET', 'get_ucrm_users', null, ''], ['tech', 'GET', 'get_survey', null, '&job_id=901'],
              ['acct', 'POST', 'scheduling_clear_cache', [], ''], ['admin', 'POST', 'auto_map_ucrm_users', [], ''],
              ['admin', 'POST', 'set_ucrm_user_id', ['retailer_id' => $s->ids['acct2'], 'ucrm_user_id' => 1400], '']] as $i => [$who, $m, $act, $body, $qs]) {
        $r = $s->api($who, $m, $act, $body, $qs);
        $o[sprintf('api %02d %s as %s', $i, $act, $who)] = norm_($r[0] . ' ' . $r[1], $s);
    }
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    $s->form('admin', ['action' => 'create_retailer', 'name' => 'Sandbox New', 'email' => 'new@example.test', 'phone' => '0922 000 222',
                       'role' => 'support', 'password' => 'sj-password-2', 'is_employee' => '1']);
    $o['form create_retailer: its message'] = norm_($s->flash('admin'), $s);
    $t = $s->row($s->ids['tech']);
    $s->form('admin', ['action' => 'edit_retailer', 'retailer_id' => $s->ids['tech'], 'name' => 'Sandbox Tech Renamed', 'email' => $t['email'] ?? '',
                       'phone' => '0922 000 111', 'role' => 'support', 'is_active' => '1', 'ucrm_user_id' => '1099']);
    $o['form edit_retailer: its message'] = norm_($s->flash('admin'), $s);
    $rows = [];
    foreach ($s->q('SELECT * FROM retailers ORDER BY id') as $r) {
        $d = isset($r['data']) ? (json_decode((string)$r['data'], true) ?: []) : $r;
        foreach (array_keys($d) as $k) if (preg_match('/password|token|_at$|last_login|session/', (string)$k)) unset($d[$k]);
        ksort($d);
        $rows[] = $d;
    }
    $o['the staff table afterwards'] = norm_(json_encode($rows, JSON_UNESCAPED_UNICODE), $s);
    $s->login('tech', 'tech@example.test', 'sj-password-1');
    foreach (['admin' => ['the Staff page' => 'tab=retailers', '＋ New Job / My Jobs' => 'tab=scheduling', 'Bulk Dispatch' => 'tab=bulk_dispatch',
                          'WA Events' => 'tab=engage_failed_queue&fqsub=crm_events', 'the Message Log' => 'tab=whatsapp&subtab=log', 'the dashboard' => 'tab=dashboard',
                          // 5.18.54 (docs/46 rows 9, 27, 29): pages the notification fixes touched on Uganda only
                          'the Event Map' => 'tab=whatsapp&subtab=events', 'the AI setup' => 'tab=wa_ai_setup', 'the help page' => 'tab=faq',
                          // 5.18.54 (docs/46 rows 22, 39): the e-mail settings card and the ladder template screen
                          'the e-mail settings' => 'tab=settings&stab=system', 'the ladder templates' => 'tab=overdue_email_tpl'],
              'tech'  => ['My Jobs' => 'tab=scheduling']] as $who => $pages) {
        foreach ($pages as $label => $qs) $o["page {$label}, as {$who}"] = norm_($s->page($who, 'page=dashboard&' . $qs), $s, true);
    }
    return $o;
};

[$base, $why] = sj_baseline_tree();
if ($base === null) {
    $skip++;
    echo "  skip the whole comparison: {$why}\n";
    printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
    exit(0);
}
is_(preg_match('/"version":\s*"5\.18\.49"/', (string)file_get_contents($base . '/manifest.json')) === 1, 'the baseline is 5.18.49, from Git (' . SJ_BASELINE . ')');

$ss  = ['timezone' => 'Africa/Juba'];   // South Sudan's own zone, so a Kampala clock leaking into this path shows
$old = SjScenario::run($base, $ss, 'sjss-old', $day);
$new = SjScenario::run($root, $ss, 'sjss-new', $day);

echo "\n1. The job traffic\n";
foreach (['answers' => 'every answer of the staff app and the webhook', 'texts' => 'every WhatsApp text, to the same number, in the same order',
          'log' => 'the Message Log', 'whlog' => 'the webhook log', 'mail' => 'the customer\'s e-mail',
          'crm' => 'every uCRM request — reads, writes, bodies and dates — in the same order'] as $k => $label) {
    $a = json_encode($old[$k], JSON_UNESCAPED_UNICODE); $b = json_encode($new[$k], JSON_UNESCAPED_UNICODE);
    is_($a === $b, "{$label} (" . count($new[$k]) . ')', $a === $b ? '' : where_($a, $b));
}
is_(count($new['texts']) === 11, 'control: the day sent eleven WhatsApp messages, the four job-assignment ones among them', (string)count($new['texts']));

echo "\n2. Pages, API answers and forms\n";
foreach ($old['extra'] as $k => $a) {
    $b = $new['extra'][$k] ?? '';
    is_($a === $b, $k . ' (' . strlen($b) . ' bytes)', $a === $b ? '' : where_($a, $b));
}
is_(array_keys($old['extra']) === array_keys($new['extra']), 'and nothing on one side is missing from the other');
$staffPage = $new['extra']['page the Staff page, as admin'] ?? '';
is_(strlen($staffPage) > 50000 && strpos($staffPage, 'Sandbox Tech Renamed') !== false, 'control: the Staff page is the real page, showing the edit just made');
$oldStaff = $old['extra']['page the Staff page, as admin'] ?? '';
is_(substr_count($staffPage, 'v<version>') === 3 && substr_count($oldStaff, 'v<version>') === 3,
    'control: the version label — normalised, and nothing else of the release — is found three times on each tree\'s Staff page',
    substr_count($oldStaff, 'v<version>') . ' / ' . substr_count($staffPage, 'v<version>'));

// ── 3. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n3. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['lib/StaffJobsGate.php', "self::\$memo[\$key] = TenantProfile::current(\$config, \$dir)->id() === 'uganda';", "self::\$memo[\$key] = true;",
         'the Uganda rules applied everywhere'],
        ['tabs/support/scheduling.php', "<?php if (\$_sjUganda): ?>\n    <input type=\"checkbox\" id=\"njNotifyWa\" style=\"display:none;\" disabled>",
         "    <?php if (\$_sjUganda): ?>\n    <input type=\"checkbox\" id=\"njNotifyWa\" style=\"display:none;\" disabled>", 'four spaces leaked into the South Sudan page'],
        ['tabs/admin/retailers.php', "<?php if (!\$_rtUganda): ?>", "<?php if (false): ?>", 'a South Sudan branch of the Staff page lost'],
        ['includes/post/post_sync.php', "    if (StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)) {",
         "    if (true) {", 'the Uganda edit rules applied on South Sudan'],
        ['includes/api/api_support.php', "        if (StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)) {",
         "        if (true) {", 'the Uganda engineer list served on South Sudan'],
        // 5.18.54 (docs/46 rows 9, 27, 29): each page the notification fixes touched, one indented control tag each
        ['tabs/engage/whatsapp.php', "<?php if (!\$_emUnused): ?>\n        <form method=\"POST\" id=\"waSaveForm\"",
         "        <?php if (!\$_emUnused): ?>\n        <form method=\"POST\" id=\"waSaveForm\"", 'eight spaces leaked into the South Sudan Message Log'],
        ['tabs/engage/wa_ai_setup.php', "<?php\n        // 5.18.54 (docs/46 row 27, S-5)", "      <?php\n        // 5.18.54 (docs/46 row 27, S-5)",
         'six spaces leaked into the South Sudan AI setup page'],
        ['tabs/help/faq.php', "<?php endif; ?>\n\n    <div class=\"faq-c\">&#128273;", "    <?php endif; ?>\n\n    <div class=\"faq-c\">&#128273;",
         'four spaces leaked into the South Sudan help page'],
        ['tabs/admin/settings.php', "<?php endif; ?>\n        </label>\n        <div style=\"font-size:12px;color:#666;margin:4px 0 10px;\">\n            Reads SMTP",
         "        <?php endif; ?>\n        </label>\n        <div style=\"font-size:12px;color:#666;margin:4px 0 10px;\">\n            Reads SMTP",
         'eight spaces leaked into the South Sudan e-mail settings'],
    ];
    foreach ($MUTANTS as [$rel, $o_, $n_, $label]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $o_, $n_);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);
