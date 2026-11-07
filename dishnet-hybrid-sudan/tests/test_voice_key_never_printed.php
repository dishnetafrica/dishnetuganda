<?php
declare(strict_types=1);
/**
 * test_voice_key_never_printed.php — 5.18.104 (root docs/73 §H.3.18; C9, R-08): the OpenAI key is never printed.
 *
 * A the settings tab (tabs/engage/whatsapp.php) on Uganda renders the OpenAI key's field empty and says only `set` or
 * `not set` (ACTIVE stays when OpenAI is the provider); South Sudan's tab is 5.18.103's, so there the stored key is still
 * in the field — the control that proves the page read the planted key at all. (Saving the form with the field blank
 * keeps the stored key: includes/post/post_admin.php:731-735, unchanged and held byte for byte.)
 * B a voice note through the media chain at R2 and R3, the provider answering 401 with the key's tail and then a
 * transcript: the planted key is in no row, no event, no log line, no file the run wrote. (The adapter's own guards — its
 * result, the error log, a dump of the object, an exception's stack — are tests/test_openai_transcriber.php's section C.)
 * Each section begins with its positive control; then the weakened copies (V40's two forms, KP-WORKER).
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_voice_key_never_printed.php [--root=DIR] [--sections=A] [--no-mutants]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('kp');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

/** The OpenAI key's input and its badge, from the settings tab's HTML. */
function kp_field(string $html): array
{
    $input = preg_match('#<input type="password" name="openai_api_key"[^>]*value="([^"]*)"#s', $html, $m) ? $m[1] : '<no field>';
    $badge = preg_match('#✨ OpenAI API Key\s*(.*?)</label>#s', $html, $b) ? trim(strip_tags($b[1])) : '<no label>';
    return [$input, $badge];
}

$SECTIONS = [
    'A' => ['title' => 'The settings tab: on Uganda the stored OpenAI key is never in the page', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
        $page = function (array $cfg) use ($ctx): array {
            $s = SjSandbox::start($ctx->root, $cfg + ['timezone' => QF_TZ], 'kpa');
            try {
                $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
                $s->login('admin', 'admin@example.test', 'sj-password-1');
                $html = $s->page('admin', 'page=dashboard&tab=whatsapp&subtab=config');
                return ['html' => $html, 'field' => kp_field($html)];
            } finally {
                $s->stop();
            }
        };
        $ctx->run('A01 control: South Sudan\'s tab is 5.18.103\'s — the stored key is in the field there', function () use ($ctx, $page) {
            $ss = $page(['tenant_profile' => 'south-sudan', 'openai_api_key' => VM_KEY, 'ai_provider' => 'claude']);
            $ctx->eq($ss['field'], [VM_KEY, 'Saved'], 'A01 the page read the planted key: value and "Saved"', substr($ss['html'], 0, 200));
        });
        $ctx->run('A02 Uganda: the field is empty and the badge says set (V40)', function () use ($ctx, $page) {
            $ug = $page(['tenant_profile' => 'uganda', 'openai_api_key' => VM_KEY, 'ai_provider' => 'claude']);
            $ctx->eq($ug['field'], ['', 'set'], 'A02 value="" and "set"');
            $ctx->t(strpos($ug['html'], VM_KEY) === false && strpos($ug['html'], substr(VM_KEY, -8)) === false, 'A02 the key, and its tail, nowhere in the page');
            $act = $page(['tenant_profile' => 'uganda', 'openai_api_key' => VM_KEY, 'ai_provider' => 'openai']);
            $ctx->eq($act['field'], ['', 'ACTIVE'], 'A02 OpenAI the provider: ACTIVE stays, the field still empty');
            $none = $page(['tenant_profile' => 'uganda', 'ai_provider' => 'claude']);
            $ctx->eq($none['field'], ['', 'not set'], 'A02 no key stored: "not set"');
        });
    }],
    'B' => ['title' => 'The media chain: the key in no row, no event, no log, no file', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        vm_voiceack($ctx);
        $evo = qf_fake_evo(); $oa = vm_fake_openai();
        $ctx->t($evo !== '' && $oa !== '', 'B00 the fakes started');
        if ($evo === '' || $oa === '') return;
        $ctx->run('B01 a 401 that quotes the key, then a transcript, at R2 and R3', function () use ($ctx, $evo, $oa) {
            $elog = $ctx->dir('kp-elog') . '/php.log';
            $prev = ini_set('error_log', $elog);
            $logs = '';
            try {
                foreach (['dry_run', 'handover'] as $rung) {
                    qf_http('GET', $evo . '/__test/reset'); vm_fake_openai();
                    $b = vm_book($ctx, vm_cfg($evo, vm_rung($rung)), 'kp-' . $rung);
                    $v1 = vm_voice($b, '256771301101'); $v2 = vm_voice($b, '256771301102');
                    vm_media($evo, $v1['wa'], ogg_opus(['seconds' => 2.0])); vm_media($evo, $v2['wa'], ogg_opus(['seconds' => 2.0]));
                    vm_oa_next($oa, [['echo_key_tail' => true]]);
                    $r = vm_run($b, $oa);
                    $logs .= $r['log'];
                    $r2 = vm_run($b, $oa);
                    $logs .= $r2['log'];
                    $ctx->eq([vm_row($b, $v1['media'])['failure_reason'], vm_row($b, $v2['media'])['status']], ['provider_auth', 'understood'],
                             "B01 {$rung}: the first note met the 401, the second was transcribed");
                    $ctx->eq(count(vm_oa_calls($oa)), 2, "B01 {$rung} control: the adapter was called twice with the key (401, then a transcript)");
                    $dump = '';
                    foreach ($b['pdo']->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                        foreach ($b['pdo']->query('SELECT * FROM "' . $t . '"')->fetchAll(\PDO::FETCH_ASSOC) as $row) $dump .= json_encode($row) . "\n";
                    }
                    $ctx->t($dump !== '' && strpos($dump, VM_KEY) === false && strpos($dump, substr(VM_KEY, -8)) === false, "B01 {$rung}: no row of any table carries the key or its tail");
                    $files = array_values(array_filter(array_merge(vm_files_with($b['data'], substr(VM_KEY, -8)), vm_files_with($b['data'], substr(VM_KEY, 0, 12))),
                        function ($f) { return basename($f) !== 'kyc_config.json'; }));
                    $ctx->eq($files, [], "B01 {$rung}: no file in the data folder but the configuration itself holds it");
                }
            } finally {
                ini_set('error_log', (string)$prev);
            }
            $el = (string)@file_get_contents($elog);
            $ctx->t(strpos($logs, VM_KEY) === false && strpos($logs, substr(VM_KEY, -8)) === false && strpos($el, substr(VM_KEY, -8)) === false
                    && strpos($el, substr(VM_KEY, 0, 12)) === false, 'B01 the worker\'s log and the PHP error log carry neither the key nor its tail', $logs . $el);
            $ctx->eq(array_unique(array_map(function ($c) { return $c['auth']; }, vm_oa_calls($oa))), ['present'], 'B01 control: every call carried a bearer');
        });
    }],
];

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $T = 'tabs/engage/whatsapp.php';
    qf_mutants($ctx, __FILE__, [
        ['label' => 'V40 the Uganda settings tab prints the OpenAI key again', 'file' => $T,
         'old' => "value=\"<?= \$_aiKeyUg ? '' : h(\$config['openai_api_key'] ?? '') /* 5.18.104 (root docs/73 §H.3.18): never printed on Uganda */ ?>\"",
         'new' => "value=\"<?= h(\$config['openai_api_key'] ?? '') ?>\"", 'sections' => 'A', 'cases' => 'A02', 'pattern' => '/A02 /'],
        ['label' => 'V40b the badge says Saved, never set / not set', 'file' => $T,
         'old' => "<?= \$_aiKeyUg ? 'set' : 'Saved' ?>", 'new' => 'Saved', 'sections' => 'A', 'cases' => 'A02', 'pattern' => '/A02 value/'],
        ['label' => 'KP-WORKER the provider\'s answer kept in the worker\'s log', 'file' => 'lib/VoiceTranscription.php',
         'old' => "            \$budget->settle(\$callId, 'failed', \$http, \$ms, \$secs, \$reason);",
         'new' => "            \$budget->settle(\$callId, 'failed', \$http, \$ms, \$secs, \$reason); (\$this->log)('warn', json_encode(\$ans) . ' key ' . (string)(\$fresh['openai_api_key'] ?? ''));",
         'sections' => 'B', 'cases' => 'B01', 'pattern' => '/B01 the worker/'],
    ], $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);
