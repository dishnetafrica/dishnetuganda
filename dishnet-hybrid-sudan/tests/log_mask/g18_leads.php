<?php
declare(strict_types=1);
/**
 * g18_leads.php — 5.18.105 (root docs/74 §H.1.15): group G18 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-28 and LN-29, cron_leads.php's stale-lead lines (master's stdout, plugin.log), as tests/test_sales_numbers.php runs
 * the job: two active sales agents; one open lead assigned 80 hours ago (reassigned: 72 h) and one assigned 50 hours ago
 * (flagged: 48 h), each an AI lead with no name — its customer_name its number, as AiLeadService::newLead() writes it
 * (+256700000128, +256700000129; fictitious). A3's decimal guard is exercised here: {$hoursIdle} prints as a raw float
 * (80⅓ and 50⅓ hours back, so it always has its long fraction).
 */

/**
 * An AI-qualified lead with no name, as lib/AiLeadService.php's newLead() writes it, assigned $hoursAgo hours back. Its
 * stamps are wall times in QF_TZ, the zone the job reads them in: cron_leads.php and cron_lead_alerts.php call
 * dn_tz_apply() (the box's kyc_config.json names QF_TZ) and strtotime() the stamp there, while this process runs on UTC.
 * Written as a UTC wall time, every lead would read older by the zone's offset (5.18.105 C2 review, G18 and G19).
 */
function lm_ai_lead(string $digits, int $agent, string $agentName, float $hoursAgo, array $extra = []): array
{
    $at = (new \DateTimeImmutable('@' . (int)(time() - $hoursAgo * 3600)))->setTimezone(new \DateTimeZone(QF_TZ))->format('Y-m-d H:i:s');
    return $extra + ['phone' => $digits, 'customer_name' => $digits, 'service_type' => 'starlink', 'source' => 'whatsapp_ai',
                     'source_detail' => 'Qualified by the assistant in conversation c1', 'priority' => 'medium', 'status' => 'open',
                     'ai_qualified' => true, 'retailer_id' => 0, 'assigned_to' => $agent, 'assigned_name' => $agentName,
                     'assigned_at' => $at, 'notes' => '', 'created_at' => $at, 'updated_at' => $at];
}

function lm_g18(QfCtx $ctx): void
{
    $ctx->run('G18 LN-28 LN-29 cron_leads.php: a lead reassigned and a lead flagged, stdout', function () use ($ctx) {
        $evo = qf_fake_evo();
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_support' => 'lm-support', 'evo_instance_account' => 'lm-account',
                                           'evo_instance_sales' => 'lm-sales'], 'g18');
        $store = \SqliteStore::create($data);
        $a = (int)($store->appendWithId('retailers.json', ['name' => 'Test Agent One', 'role' => 'sales', 'is_active' => true,
                                                            'phone' => lm_num(88), 'email' => 'agent1@example.test'])['id'] ?? 0);
        $store->appendWithId('retailers.json', ['name' => 'Test Agent Two', 'role' => 'sales', 'is_active' => true,
                                                'phone' => lm_num(89), 'email' => 'agent2@example.test']);
        $l28 = $store->appendWithId('leads.json', lm_ai_lead(lm_forms(lm_num(28))[1], $a, 'Test Agent One', 80 + 1 / 3));
        $l29 = $store->appendWithId('leads.json', lm_ai_lead(lm_forms(lm_num(29))[1], $a, 'Test Agent One', 50 + 1 / 3));
        $err = $ctx->dir('g18-log') . '/php.log';
        touch($err);
        [, $out] = lm_cli_run($plug, $data, 'cron_leads.php', $err);
        $lines = lm_lines($out);
        $nums = [lm_num(28), lm_num(29)];
        $f28 = lm_ln($ctx, 'LN-28', $lines, $nums);
        $ctx->t($f28 !== [] && preg_match('/^  ⟲ Lead #' . (int)($l28['id'] ?? 0) . ' reassigned from Test Agent One → Test Agent Two \(80\.\d+h idle\)$/u', $f28[0]) === 1,
            'LN-28 "⟲ Lead #<id> reassigned from … (<hours>h idle)", the hours a raw float', implode(' | ', $f28) . ' ' . substr($out, -400));
        $f29 = lm_ln($ctx, 'LN-29', $lines, $nums);
        $ctx->t($f29 !== [] && preg_match('/^  ⚠ Lead #' . (int)($l29['id'] ?? 0) . ' flagged stale \(50\.\d+h idle\)$/u', $f29[0]) === 1,
            'LN-29 "⚠ Lead #<id> flagged stale (<hours>h idle)"', implode(' | ', $f29));
    });
}
