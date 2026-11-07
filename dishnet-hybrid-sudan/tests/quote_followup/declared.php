<?php
declare(strict_types=1);
/**
 * The one merged declared list of 5.18.104 (root docs/73 §H.2.3, §H.2.14 N; ruling R10) — every file Parts F, V, O and
 * P add or change, each declared ONCE, with the part that owns it and the parts whose hunks it also carries; and the
 * files each part holds byte for byte. TEST ONLY: read by `quote_followup N` (the one boundary test), `wa_offers M` and
 * `quote_agent_copy J` (reads of it, by slice), TL14 (the one config-key scan, over its new and changed PHP files), and
 * the 5.18.103 boundaries re-pointed in stage B10 (tests/catalogue_direct/I_boundary.php,
 * tests/quote_from_ucrm/I_boundary.php), which judge this tree with exactly these files and hunks taken out
 * (qf_n_revert_tree): 221c9dc, from Git, for the real tree.
 *
 * A path ending in '/' is a directory 5.18.104 adds whole: every file under it is new. Parts: 'F', or 'F,V' for a file
 * one part owns and another's hunks are also in (one file, one entry, both markers; ruling R10).
 *
 * A hunk is 5.18.104's when it carries the marker — "5.18.104 … root docs/73 §H" within one line — in its own added
 * lines, in the 30 lines above it, or is a hunk that only closes a block: 5.18.103's rule (tests/catalogue_direct/
 * I_boundary.php I3). manifest.json carries no comment: its one declared change is the version line. Every person,
 * number and message in these tests is fictitious.
 */

const QF_N_BASE = '221c9dc';
/** What a release does not ship and a weakened copy does not carry (tests/quote_from_ucrm/I_boundary.php QFU_I_SKIP). */
const QF_N_SKIP = ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'scripts'];
/** The marker, as a pattern over one line. */
const QF_N_MARK = '#5\.18\.104[^\n]{0,40}root docs/73 §H#u';

const QF_DECLARED = [
    // ── Part F (§H.2.3): P1–P3 and what every part shares ─────────────────────────────────────────────────────────
    'migrations/089_quote_followups.sql'  => ['F', 'new', '§H.2.4'],
    'lib/WaSendBudget.php'                => ['F', 'new', '§H.2.5'],
    'lib/ReminderDay.php'                 => ['F', 'new', '§H.2.5.3'],
    'lib/HumanTakeover.php'               => ['F', 'new', '§H.2.7.1'],
    'lib/WaSeenMarks.php'                 => ['F', 'new', '§H.2.7.2'],
    'lib/JobBudget.php'                   => ['F', 'new', '§H.2.13.3'],
    'lib/QuoteSendEvidence.php'           => ['F', 'new', '§H.2.9'],
    'lib/QuoteChasePolicy.php'            => ['F', 'new', '§H.2.10'],
    'lib/QuoteChaseText.php'              => ['F', 'new', '§H.2.10.4'],
    'lib/QuoteTracker.php'                => ['F', 'new', '§H.2.9'],
    'lib/QuoteChase.php'                  => ['F', 'new', '§H.2.10'],
    'lib/QuoteDailyList.php'              => ['F', 'new', '§H.2.11'],
    'lib/SalesFollowupLadder.php'         => ['F,V,O,P', 'new', '§H.2.12.2'],
    'lib/SalesFollowupChecks.php'         => ['F', 'new', '§H.2.12.5'],
    'cron/quote_chase.php'                => ['F', 'new', '§H.2.9'],
    'cron/quote_daily_list.php'           => ['F', 'new', '§H.2.11'],
    'tabs/sales/my_quotes.php'            => ['F', 'new', '§H.2.11.5'],
    'tools/sales_followup.php'            => ['F,P', 'new', '§H.2.12.1'],
    'tools/sales_followup_report.php'     => ['F', 'new', '§H.2.8'],
    'cron/master.php'                     => ['F,V,O', 'changed', '§H.2.13'],
    'tools/cron_status.php'               => ['F', 'changed', '§H.2.13.2'],
    'cron/followup_send.php'              => ['F', 'changed', '§H.2.10.6'],
    'cron/followup_scan.php'              => ['F', 'changed', '§H.2.3'],
    'cron/followup_close.php'             => ['F', 'changed', '§H.2.3'],
    'lib/FollowUpService.php'             => ['F', 'changed', '§H.2.3'],
    'lib/NotificationService.php'         => ['F', 'changed', '§H.2.6.1'],
    'lib/AlertService.php'                => ['F', 'changed', '§H.2.6.2'],
    'workers/StarlinkMailWorker.php'      => ['F', 'changed', '§H.2.6.3'],
    'evo_webhook.php'                     => ['F,V', 'changed', '§H.2.7.3, §H.3.4'],
    'public.php'                          => ['F,O', 'changed', '§H.2.11.5, §H.4.4'],
    'includes/navigation.php'             => ['F,O', 'changed', '§H.2.11.5, §H.4.7'],
    'manifest.json'                       => ['F,V,O,P', 'changed', '§H.2.3 (shared)'],
    'SAFETY.md'                           => ['F,V,O,P', 'changed', '§H.2.3 (shared)'],
    // Part F's tests (§H.2.3 Tests, §H.2.14)
    'tests/test_quote_followup.php'       => ['F', 'new', '§H.2.14'],
    'tests/quote_followup/'               => ['F', 'new', '§H.2.14'],
    'tests/test_wa_send_budget.php'       => ['F', 'new', '§H.2.14'],
    'tests/test_sales_followup_tool.php'  => ['F', 'new', '§H.2.14'],
    'tests/sales_followup_tool/'          => ['F', 'new', '§H.2.14'],
    'tests/test_master_104.php'           => ['F', 'new', '§H.2.13.5'],
    'tests/test_sales_followup_report.php' => ['F', 'new', '§H.2.14 K'],
    'tests/fixtures/sales_followup_report_book.php' => ['F', 'new', '§H.2.14 K'],
    'tests/fixtures/r104_revert.php'      => ['F,O', 'new', '§H.2.14 (re-pointed tests)'],
    // Part F's "tests re-pointed on purpose, never weakened" (§H.2.14; coordinator.md items 2, 8, 9, 10)
    'tests/catalogue_direct/I_boundary.php' => ['F', 'changed', '§H.2.14'],
    'tests/quote_from_ucrm/I_boundary.php'  => ['F', 'changed', '§H.2.14'],
    'tests/test_quote_company.php'        => ['F', 'changed', '§H.2.14'],
    'tests/test_bank_store.php'           => ['F', 'changed', '§H.2.14 (coordinator item 2)'],
    'tests/test_bank_boundaries.php'      => ['F,O', 'changed', '§H.2.14 (coordinator items 8, 10a)'],
    'tests/test_cashbook_physical_list.php' => ['F,O', 'changed', '§H.2.14 (coordinator items 8, 10a)'],
    'tests/test_distributor_apply.php'    => ['F', 'changed', '§H.2.14'],
    'tests/test_distributor_link_ucrm.php' => ['F', 'changed', '§H.2.14'],
    'tests/test_distributor_notify.php'   => ['F', 'changed', '§H.2.14'],
    'tests/test_distributor_registry.php' => ['F', 'changed', '§H.2.14'],
    'tests/test_distributor_territory.php' => ['F', 'changed', '§H.2.14'],

    // ── Part V (§H.3.2): P4 and P5 ────────────────────────────────────────────────────────────────────────────────
    'migrations/090_wa_voice.sql'         => ['V', 'new', '§H.3.3'],
    'lib/MediaBlob.php'                   => ['V', 'new', '§H.3.2'],
    'lib/MediaFetcher.php'                => ['V', 'new', '§H.3.2'],
    'lib/MediaPolicy.php'                 => ['V', 'new', '§H.3.5'],
    'lib/InboundMedia.php'                => ['V', 'new', '§H.3.4'],
    'lib/Transcriber.php'                 => ['V', 'new', '§H.3.9'],
    'lib/VoiceTranscription.php'          => ['V', 'new', '§H.3.7'],
    'lib/Handover.php'                    => ['V', 'new', '§H.3.13'],
    'workers/MediaWorker.php'             => ['V', 'new', '§H.3.6'],
    'run_media_worker.php'                => ['V', 'new', '§H.3.6'],
    'lib/OpenAiTranscriber.php'           => ['V', 'new', '§H.3.9'],
    'lib/VoiceDuration.php'               => ['V', 'new', '§H.3.8'],
    'lib/VoiceBudget.php'                 => ['V', 'new', '§H.3.10'],
    'lib/VoiceAck.php'                    => ['V', 'new', '§H.3.15'],
    'lib/VoiceAckLimits.php'              => ['V,F', 'new', '§H.3.15; ruling R4'],
    'lib/VoiceSteps.php'                  => ['V', 'new', '§H.3.5, §H.3.20'],
    'workers/AiReplyWorker.php'           => ['V', 'changed', '§H.3.12, §H.3.15'],
    'lib/EvolutionApiService.php'         => ['V', 'changed', '§H.3.2'],
    'lib/BrainContext.php'                => ['V', 'changed', '§H.3.12'],
    'lib/DishNetAiBrain.php'              => ['V', 'changed', '§H.3.12'],
    'cron/event_processor.php'            => ['V', 'changed', '§H.3.2'],
    'tabs/engage/whatsapp.php'            => ['V', 'changed', '§H.3.18'],
    'includes/api/api_whatsapp.php'       => ['V', 'changed', '§H.3.11; ruling R10'],
    'tabs/engage/wa_inbox.php'            => ['V', 'changed', '§H.3.11'],
    'tools/wa_prune_history.php'          => ['V', 'changed', '§H.3.21'],
    'tests/fixtures/fake_evo_server.php'  => ['V', 'changed', '§H.3.2'],
    'tests/fixtures/fake_openai_server.php' => ['V', 'new', '§H.3.2'],
    'tests/fixtures/ogg_opus.php'         => ['V', 'new', '§H.3.2'],
    'tests/test_media_foundation.php'     => ['V', 'new', '§H.3.24'],
    'tests/test_migration_090.php'        => ['V', 'new', '§H.3.24'],
    'tests/test_openai_transcriber.php'   => ['V', 'new', '§H.3.24'],
    'tests/test_voice_ack.php'            => ['V', 'new', '§H.3.24'],
    'tests/voice_ack/'                    => ['V', 'new', '§H.3.24'],
    'tests/test_voice_duration.php'       => ['V', 'new', '§H.3.24'],
    'tests/test_voice_key_never_printed.php' => ['V', 'new', '§H.3.24'],
    'tests/test_voice_media.php'          => ['V', 'new', '§H.3.24'],
    'tests/voice_media/'                  => ['V', 'new', '§H.3.24'],
    'tests/test_voice_south_sudan.php'    => ['V', 'new', '§H.3.26'],
    'tests/test_voice_switches.php'       => ['V', 'new', '§H.3.24 (VK1–VK4 through the tool; B9-fix1)'],
    'tests/voice_switches/'               => ['V', 'new', '§H.3.24 (VK1–VK4 through the tool; B9-fix1)'],
    // Part V's re-pointed tests (§H.3.24 "Re-pointed on purpose, never weakened")
    'tests/test_event_processor_protected.php' => ['V', 'changed', '§H.3.24'],
    'tests/test_brain_context.php'        => ['V', 'changed', '§H.3.24'],
    'tests/test_staff_jobs_gate.php'      => ['V', 'changed', '§H.3.24'],
    'tests/test_cron_keepalive_order.php' => ['V', 'changed', '§H.3.24; ruling R1'],
    'tests/test_plan_fence.php'           => ['V,O', 'changed', '§H.3.2, §H.4.11'],

    // ── Part O (§H.4.4): P6 ───────────────────────────────────────────────────────────────────────────────────────
    'migrations/091_wa_offers.sql'        => ['O', 'new', '§H.4.5'],
    'lib/OfferSwitch.php'                 => ['O', 'new', '§H.4.6'],
    'lib/OfferText.php'                   => ['O', 'new', '§H.4.4'],
    'lib/OfferImage.php'                  => ['O', 'new', '§H.4.4'],
    'lib/OfferAudience.php'               => ['O', 'new', '§H.4.8'],
    'lib/OfferStore.php'                  => ['O', 'new', '§H.4.4'],
    'lib/OfferGateway.php'                => ['O', 'new', '§H.4.11'],
    'lib/EvolutionOfferGateway.php'       => ['O', 'new', '§H.4.11'],
    'lib/OfferSender.php'                 => ['O', 'new', '§H.4.10'],
    'lib/OfferWindow.php'                 => ['O', 'new', '§H.4.4'],
    'lib/OfferSchema.php'                 => ['O', 'new', '§H.4.4'],
    'cron/offers_send.php'                => ['O', 'new', '§H.4.10'],
    'tabs/engage/wa_offers.php'           => ['O', 'new', '§H.4.7'],
    'includes/post/post_offers.php'       => ['O', 'new', '§H.4.7'],
    'includes/post_handlers.php'          => ['O', 'changed', '§H.4.4'],
    'tests/test_wa_offers.php'            => ['O', 'new', '§H.4.16'],
    'tests/wa_offers/'                    => ['O', 'new', '§H.4.16'],

    // ── Part P (§H.5.6): P7 ───────────────────────────────────────────────────────────────────────────────────────
    'lib/QuoteAgentCopy.php'              => ['P', 'new', '§H.5.7'],
    'tests/test_quote_agent_copy.php'     => ['P', 'new', '§H.5.12'],
    'tests/quote_agent_copy/'             => ['P', 'new', '§H.5.12'],
    'tests/fixtures/fake_smtp_refuse.php' => ['P', 'new', '§H.5.6'],
    'webhook.php'                         => ['P', 'changed', '§H.5.8.2'],
    'lib/QuotationService.php'            => ['P', 'changed', '§H.5.8.1'],
    'lib/CustomerEmailDispatcher.php'     => ['P', 'changed', '§H.5.8.3'],
    'tests/test_quote_summary.php'        => ['P', 'changed', '§H.5.13'],
];

/** Held byte for byte, per part (§H.2.3 "Held byte for byte by quote_followup N", §H.3.2, §H.4.4 "Relied on and never
 *  changed by P6", §H.5.6). None may be in QF_DECLARED. */
const QF_HELD = [
    'F' => [
        // CD_I_KEPT (tests/catalogue_direct/I_boundary.php:42-46), webhook.php excepted (Part P changes it)
        'cron_quote_wa.php', 'api/index.php', 'lib/QuoteOrganization.php', 'lib/PublicPriceFeed.php', 'prices.php', 'shop.php',
        'tools/set_config.php', 'lib/UcrmQuoteLinks.php', 'lib/QuoteTaxLine.php', 'lib/TenantProfile.php',
        'includes/post/post_kyc.php', 'includes/api/api_retailer.php', 'retailer/index.html', 'cron/kyc_crm_sync.php',
        'lib/KycService.php', 'lib/KycCrmSync.php',
        'lib/ConversationService.php', 'lib/AutomationPolicy.php', 'lib/OwnedNumberHold.php', 'lib/FollowUpPolicy.php',
        'lib/ContactOptOut.php', 'lib/PluginConfig.php', 'lib/ConfigVault.php', 'lib/PhoneNumber.php', 'lib/NotifyGate.php',
        'lib/LineOwner.php', 'lib/StaffDirectory.php', 'lib/LeadVisibility.php', 'lib/InboxReplyRoute.php',
        'lib/MigrationRunner.php', 'lib/JobWindow.php',
        'cron/followup_run.php', 'cron/customer_reminders.php',
        'includes/api/api_followups.php', 'tabs/engage/followups.php',
    ],
    'V' => [
        'tools/set_config.php',
        'lib/ConversationService.php', 'lib/ReplyPrivacyGuard.php', 'lib/AutomationPolicy.php', 'lib/FollowUpPolicy.php', 'lib/ContactOptOut.php',
        'workers/WorkerBase.php', 'run_worker.php', 'wa_webhook.php', 'cron_evo_sync.php', 'lib/WaMessageProcessor.php',
        'includes/post/post_admin.php',
    ],
    'O' => [
        'lib/ContactOptOut.php', 'lib/FlyerAsset.php', 'lib/MediaLibrary.php', 'lib/EvoWebhookGuard.php',
        'lib/AutomationPolicy.php', 'lib/InternalNumbers.php', 'lib/ColleagueNumbers.php', 'lib/StaffDirectory.php',
        'lib/PhoneNumber.php', 'lib/DistributorNotifier.php',
        'lib/ConversationService.php', 'lib/FollowUpPolicy.php', 'lib/EmailReplyPolicy.php',
        'includes/api/api_followups.php', 'tools/set_config.php',
    ],
    'P' => [
        'lib/MailService.php', 'lib/EmailRecipients.php', 'lib/CustomerEmails.php', 'lib/EmailTemplate.php', 'lib/QuotePdfSource.php',
        'lib/KycService.php', 'lib/KycCrmSync.php', 'lib/RetailerAuth.php', 'lib/StaffDirectory.php',
        'tools/quote_email_send.php', 'tools/quote_email_doctor.php', 'tools/email_lifecycle_test.php', 'tools/set_customer_emails.php',
        'tabs/admin/email_preview.php', 'tabs/admin/settings.php', 'lib/EmailSettingsWriter.php',
        'api/index.php', 'includes/post/post_leads.php', 'includes/api/api_crm_misc.php', 'cron_quote_wa.php',
        'tests/fixtures/fake_smtp_server.php',
    ],
];

/** Each part's one migration (C2): 089 Part F's, 090 Part V's, 091 Part O's; Part P has none (§H.5.11). */
const QF_N_MIGRATIONS = ['F' => '089_quote_followups.sql', 'V' => '090_wa_voice.sql', 'O' => '091_wa_offers.sql'];

/** The entries of QF_DECLARED whose parts include $part (one letter), or every entry with $part null. */
function qf_declared(?string $part = null): array
{
    $out = [];
    foreach (QF_DECLARED as $path => $row) {
        if ($part === null || in_array($part, explode(',', $row[0]), true)) $out[$path] = $row;
    }
    return $out;
}

/** Is $rel declared — by its own entry, or under a declared new directory? The entry's path, or null. */
function qf_declared_entry(string $rel): ?string
{
    if (isset(QF_DECLARED[$rel])) return $rel;
    foreach (QF_DECLARED as $path => $row) {
        if (substr($path, -1) === '/' && strpos($rel, $path) === 0) return $path;
    }
    return null;
}

/** A plugin tree's files, rel => absolute, the skipped top-level directories left out. */
function qf_n_walk(string $tree): array
{
    $out = [];
    if (!is_dir($tree)) return $out;
    $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        function ($f) use ($tree) { return !in_array(explode('/', substr($f->getPathname(), strlen($tree) + 1))[0], QF_N_SKIP, true); }));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($tree) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
}

/**
 * 221c9dc's plugin tree, from this checkout's Git, into $dir (made once per process): [dir|null, reason]. Never from a
 * working tree. A checkout that cannot supply it is a failure of the test that asks, never a skip.
 */
function qf_n_base(string $dir): array
{
    static $got = null;
    if ($got !== null && is_dir((string)$got[0])) return $got;
    @mkdir($dir, 0700, true);
    $checkout = dirname(__DIR__, 2);
    $top = trim((string)shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse --show-toplevel 2>/dev/null'));
    $o = [];
    exec('git -C ' . escapeshellarg($top !== '' ? $top : $checkout) . ' archive -o ' . escapeshellarg($dir . '.tar') . ' '
        . escapeshellarg(QF_N_BASE . ':dishnet-hybrid-sudan') . ' 2>&1 && tar -xf ' . escapeshellarg($dir . '.tar') . ' -C ' . escapeshellarg($dir) . ' 2>&1', $o, $rc);
    @unlink($dir . '.tar');
    $ver = (string)(json_decode((string)@file_get_contents($dir . '/manifest.json'), true)['information']['version'] ?? '');
    $got = ($rc === 0 && $ver === '5.18.103') ? [$dir, ''] : [null, 'git could not supply ' . QF_N_BASE . ' (5.18.103): rc ' . $rc . ' ' . trim(implode(' ', $o)) . ' version ' . $ver];
    return $got;
}

/**
 * The hunks of $new against $old (diff -U0), each [oldStart, oldLen, newStart, newLen, added lines, removed lines,
 * marked]. marked: the marker in its own added lines, in the 30 lines above it, or a hunk that only closes a block.
 */
function qf_n_hunks(string $oldFile, string $newFile): array
{
    $nl = explode("\n", (string)@file_get_contents($newFile));
    $d = [];
    exec('diff -U0 ' . escapeshellarg(is_file($oldFile) ? $oldFile : '/dev/null') . ' ' . escapeshellarg($newFile), $d);
    $out = []; $i = -1;
    foreach ($d as $l) {
        if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $l, $h)) {
            $out[++$i] = [(int)$h[1], ($h[2] ?? '') === '' ? 1 : (int)$h[2], (int)$h[3], ($h[4] ?? '') === '' ? 1 : (int)$h[4], [], [], false];
            continue;
        }
        if ($i < 0 || strpos($l, '+++ ') === 0 || strpos($l, '--- ') === 0) continue;
        if ($l !== '' && $l[0] === '+') $out[$i][4][] = substr($l, 1);
        if ($l !== '' && $l[0] === '-') $out[$i][5][] = substr($l, 1);
    }
    foreach ($out as $k => [$os, $ol, $ns, $len, $plus]) {
        $at = $len === 0 ? $ns : $ns - 1;
        $span = implode("\n", array_slice($nl, max(0, $at - 30), $at - max(0, $at - 30)));
        $closer = $plus !== [] && array_filter($plus, function ($x) { return !preg_match('/^\s*(?:<\?php\s+(?:endif|else|endforeach);\s*\?>|[})\];,]+)\s*$/', $x); }) === [];
        $out[$k][6] = preg_match(QF_N_MARK, implode("\n", $plus)) === 1 || preg_match(QF_N_MARK, $span) === 1 || $closer;
    }
    return $out;
}

/** $newFile's text with its marked hunks put back to $oldFile's lines; [text, unmarked hunk count]. */
function qf_n_revert_file(string $oldFile, string $newFile): array
{
    $old = explode("\n", (string)@file_get_contents($oldFile));
    $new = explode("\n", (string)@file_get_contents($newFile));
    $unmarked = 0;
    foreach (array_reverse(qf_n_hunks($oldFile, $newFile)) as [$os, $ol, $ns, $nl, , , $marked]) {
        if (!$marked) { $unmarked++; continue; }
        array_splice($new, $nl === 0 ? $ns : $ns - 1, $nl, array_slice($old, $ol === 0 ? $os : $os - 1, $ol));
    }
    return [implode("\n", $new), $unmarked];
}

/**
 * The judged tree with exactly 5.18.104's declared files and hunks taken out, built in $dest (the skipped directories
 * left out): every declared new file removed; every declared changed file's marked hunks put back to $base's lines
 * (an unmarked hunk stays, so the tree then differs from 221c9dc there); manifest.json's version line put back. For
 * the real tree, the result is 221c9dc's, file for file. Returns the count of unmarked hunks left in declared files.
 */
function qf_n_revert_tree(string $tree, string $base, string $dest): int
{
    @mkdir($dest, 0700, true);
    foreach (scandir($tree) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, QF_N_SKIP, true)) continue;
        exec('cp -R ' . escapeshellarg($tree . '/' . $e) . ' ' . escapeshellarg($dest . '/') . ' 2>/dev/null');
    }
    $left = 0;
    foreach (qf_n_walk($dest) as $rel => $abs) {
        $entry = qf_declared_entry($rel);
        if ($entry === null) continue;
        $kind = QF_DECLARED[$entry][1];
        if ($kind === 'new') { if (!is_file($base . '/' . $rel)) @unlink($abs); continue; }
        if (!is_file($base . '/' . $rel)) continue;
        if ($rel === 'manifest.json') {
            $s = (string)file_get_contents($abs);
            if (substr_count($s, '    "version": "5.18.104",') === 1) file_put_contents($abs, str_replace('    "version": "5.18.104",', '    "version": "5.18.103",', $s));
            continue;
        }
        [$text, $n] = qf_n_revert_file($base . '/' . $rel, $abs);
        $left += $n;
        file_put_contents($abs, $text);
    }
    return $left;
}

/** PHP code with comments, inline HTML and string contents taken out (a string becomes ''), line count kept — so a word
 *  in prose or in a message is never read as syntax (tests/catalogue_direct/I_boundary.php I4, quote_agent_copy J). */
function qf_n_code(string $src): string
{
    $o = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) { $o .= str_repeat("\n", substr_count($tk[1], "\n")); continue; }
        if (is_array($tk) && in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $o .= "''" . str_repeat("\n", substr_count($tk[1], "\n")); continue; }
        $o .= is_array($tk) ? $tk[1] : $tk;
    }
    return $o;
}
