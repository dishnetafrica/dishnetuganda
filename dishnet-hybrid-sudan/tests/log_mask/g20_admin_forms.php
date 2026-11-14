<?php
declare(strict_types=1);
/**
 * g20_admin_forms.php — 5.18.105 (root docs/74 §H.1.17, §H.1.18): group G20 of tests/test_log_mask.php section A.
 * TEST ONLY.
 *
 * LN-33 and LN-34, two logActivity() lines in activity_log.json (ACT), each through the served plugin
 * (tests/fixtures/staff_jobs_sandbox.php) as an admin posts the panel's form, with the day's CSRF token taken from a page:
 *   LN-33  Notify → Installation Confirmed (action notify_install_confirmed) for "Test Installed", +256700000133:
 *          the detail reads "Date:<date>", the number gone;
 *   LN-34  the KYC admin edit (action kyc_admin_edit): mobile changed from +256700000184 to +256700000134, and a note
 *          that holds that number spaced ("0700 000 134"): the detail reads "mobile: changed, note: changed"; the
 *          control, a firstname change in the same post, keeps its before and after.
 * Every person and number is fictitious.
 */
function lm_g20(QfCtx $ctx): void
{
    $ctx->run('G20 LN-33 LN-34 includes/notify_actions.php and includes/post/post_kyc.php: two admin forms, activity_log.json', function () use ($ctx) {
        require_once $ctx->root . '/tests/fixtures/staff_jobs_sandbox.php';
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ], 'lmg20');
        $s->staff('admin', ['name' => 'Test Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $app = $s->store()->appendWithId('kyc_applications.json', ['firstname' => 'Test Kyc', 'lastname' => 'Applicant', 'mobile' => lm_num(84),
            'email' => 'applicant@example.test', 'note' => '', 'status' => 'new', 'created_at' => date('Y-m-d H:i:s')]);
        $s->login('admin', 'admin@example.test', 'sj-password-1');
        $r33 = $s->form('admin', ['action' => 'notify_install_confirmed', 'customer_phone' => lm_num(33), 'customer_name' => 'Test Installed',
                                  'service_type' => 'Starlink', 'install_date' => '2026-11-10', 'install_time' => '10:00', 'tech_name' => 'Test Technician']);
        $r34 = $s->form('admin', ['action' => 'kyc_admin_edit', 'app_id' => (string)(int)($app['id'] ?? 0), 'firstname' => 'Test Kyc Edited',
                                  'mobile' => lm_num(34), 'note' => 'Call back on ' . lm_forms(lm_num(34))[3] . ' (test)'],
                         'page=dashboard&tab=applications');
        $lines = lm_json_entries($s->data . '/activity_log.json');
        $texts = $s->texts();
        $s->stop();
        $ctx->eq([$r33[0], $r34[0]], [302, 302], 'G20 control: both forms were accepted (302)');
        $ctx->eq(count(array_filter($texts, function ($t) { return strpos((string)$t['number'], substr(lm_forms(lm_num(33))[1], -9)) !== false; })), 1,
            'LN-33 control: the installation confirmation went (the fake Evolution took it)');
        $f33 = lm_ln($ctx, 'LN-33', $lines, [lm_num(33)]);
        $ctx->t($f33 !== [] && strpos($f33[0], '"title":"Install confirmation sent to Test Installed","detail":"Date:2026-11-10"') !== false,
            'LN-33 the name and the date stay', implode(' | ', $f33));
        $f34 = lm_ln($ctx, 'LN-34', $lines, [lm_num(34), lm_num(84)]);
        $ctx->t($f34 !== [] && strpos($f34[0], "firstname: 'Test Kyc' → 'Test Kyc Edited'") !== false,
            'LN-34 control: a firstname change in the same post keeps its before and after', implode(' | ', $f34));
    });
}
