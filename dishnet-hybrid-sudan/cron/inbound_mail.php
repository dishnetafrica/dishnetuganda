<?php
declare(strict_types=1);

/**
 * inbound_mail.php — read the customer mailbox, draft, and file for approval.
 *
 * Scheduled by cron/master.php. Until this existed every draft came from
 * somebody typing a command, so a customer writing at nine in the evening got
 * nothing until a person remembered to look. Now the reply is waiting in
 * Drafts by the time anyone opens webmail.
 *
 * It cannot send. The worker holds no mailer, the draft is filed with the
 * \Draft flag, and a person presses send in their own mail client. That is the
 * approval, and there is no path around it.
 *
 * Off unless configured: no mailbox, no run, no behaviour change anywhere.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/CrmApiClient.php';
require_once $pluginRoot . '/lib/DishNetAiBrain.php';
require_once $pluginRoot . '/lib/EmailTemplate.php';
require_once $pluginRoot . '/lib/CustomerEmailDispatcher.php';
require_once $pluginRoot . '/lib/MailService.php';
require_once $pluginRoot . '/lib/SentCopy.php';
require_once $pluginRoot . '/lib/DraftMailCopy.php';
foreach (['EmailReplyPolicy', 'EmailIntentClassifier', 'EmailCustomerMatcher',
          'InboundMailFilter', 'EmailDraftStore', 'JmapMailbox',
          'InboundMailWorker'] as $c) {
    require_once $pluginRoot . '/lib/' . $c . '.php';
}

$dataDir = getDataDir($pluginRoot);
$config  = CustomerEmailDispatcher::effectiveConfig(PluginConfig::load($pluginRoot, $dataDir));

$mailbox = new JmapMailbox(
    (string)($config['email_ai_jmap_url']   ?? ''),
    (string)($config['email_ai_mailbox']    ?? ''),
    (string)($config['email_ai_mailbox_pw'] ?? '')
);
if (!$mailbox->isConfigured()) exit(0);          // not set up here; nothing to do

$via = trim((string)($config['email_ai_jmap_via'] ?? ''));
if ($via !== '') $mailbox->setVia($via);
$resolve = trim((string)($config['email_ai_jmap_resolve'] ?? ''));
if ($resolve !== '') $mailbox->setResolve(array_map('trim', explode(',', $resolve)));

// One run at a time. Two readers would draft the same message twice — the
// message_id is unique so the second insert is refused, but the second run
// would still have spent a model call to produce the draft it then discards.
$lock = @fopen($dataDir . '/inbound_mail.lock', 'c');
if ($lock === false) exit(0);
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

try {
    $pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store = new EmailDraftStore($pdo);

    $crm    = CrmApiClient::fromUcrm($pluginRoot, $config);
    $brain  = new DishNetAiBrain($config);
    $worker = new InboundMailWorker($mailbox, $store, $crm, $brain, $config);

    // Where the last run got to, so a mailbox that has been read once is not
    // re-read from the beginning every five minutes.
    $cursorFile = $dataDir . '/inbound_mail_cursor.txt';
    $since      = is_file($cursorFile) ? trim((string)@file_get_contents($cursorFile)) : '';

    $run = $worker->run($since, 25);
    if (!empty($run['ok'])) {
        if ((string)$run['newest'] !== '' && (string)$run['newest'] !== $since) {
            @file_put_contents($cursorFile, (string)$run['newest']);
        }
        if ($run['drafted'] > 0 || $run['ignored'] > 0) {
            error_log(sprintf('[inbound_mail] read %d, drafted %d, ignored %d',
                $run['read'], $run['drafted'], $run['ignored']));
        }
    } else {
        error_log('[inbound_mail] ' . (string)$run['error']);
    }

    // File whatever is waiting into the mailbox's Drafts folder, so the
    // approval happens where people already read mail.
    $esFile = $dataDir . '/email_settings.json';
    $es     = is_file($esFile) ? (json_decode((string)@file_get_contents($esFile), true) ?: []) : [];
    if (!empty($es['sent_copy_enabled'])) {
        foreach ($store->unplaced(25) as $d) {
            $r = DraftMailCopy::place($es, $d, $config);
            if (!empty($r['ok'])) {
                $store->markPlaced((int)$d['id'], (string)$r['folder']);
            } else {
                error_log('[inbound_mail] draft ' . $d['id'] . ' not filed: ' . (string)$r['error']);
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[inbound_mail] ' . $e->getMessage());
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit(0);
