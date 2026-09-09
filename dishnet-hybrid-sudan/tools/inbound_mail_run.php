<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * inbound_mail_run.php — read the mailbox and draft replies. Sends nothing.
 *
 *   php tools/inbound_mail_run.php --dry           read and report, store nothing
 *   php tools/inbound_mail_run.php                 read, draft, file as pending
 *   php tools/inbound_mail_run.php --list          show what is waiting for a person
 *   php tools/inbound_mail_run.php --show 7        read one draft in full
 *   php tools/inbound_mail_run.php --since 2026-09-09T00:00:00Z
 *
 * Nothing this tool can do reaches a customer. Drafts wait in the inbox until
 * somebody approves them, and approving is not something this tool offers.
 *
 * Credentials come from the config the plugin already holds:
 *   email_ai_jmap_url    e.g. https://mail.dishnetuganda.com
 *   email_ai_mailbox     the address to read, e.g. accounts@dishnetuganda.com
 *   email_ai_mailbox_pw  its password
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';
foreach (['EmailReplyPolicy', 'EmailIntentClassifier', 'EmailCustomerMatcher',
          'InboundMailFilter', 'EmailDraftStore', 'JmapMailbox',
          'InboundMailWorker'] as $c) {
    require_once $root . '/lib/' . $c . '.php';
}

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = CustomerEmailDispatcher::effectiveConfig(PluginConfig::load($root, $dataDir));

$args  = array_slice($argv, 1);
$has   = function (string $f) use ($args) { return in_array($f, $args, true); };
$value = function (string $f, string $d = '') use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : $d;
};

$pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new EmailDraftStore($pdo);

// ── Reading what is already there ────────────────────────────────────────
if ($has('--list')) {
    $counts = $store->counts();
    echo "\n  DRAFT INBOX\n";
    foreach ($counts as $k => $v) printf("    %-10s %d\n", $k, $v);
    echo "\n";
    printf("  %-4s %-11s %-17s %-30s %s\n", 'ID', 'CATEGORY', 'FROM', 'SUBJECT', 'CLIENT');
    foreach ($store->listByStatus(EmailDraftStore::PENDING, 50) as $d) {
        printf("  %-4d %-11s %-17s %-30s %s\n",
            $d['id'], substr((string)$d['category'], 0, 11),
            substr((string)$d['from_addr'], 0, 17),
            substr((string)$d['subject'], 0, 30),
            ((int)$d['crm_client_id'] ?: '—'));
    }
    echo "\n  php tools/inbound_mail_run.php --show <id>   to read one\n\n";
    exit(0);
}

if ($has('--show')) {
    $d = $store->get((int)$value('--show', '0'));
    if ($d === null) { echo "No draft with that id.\n"; exit(1); }
    echo "\n  ── THEIR MESSAGE ────────────────────────────────────────────\n";
    echo "  From:     {$d['from_name']} <{$d['from_addr']}>\n";
    echo "  Subject:  {$d['subject']}\n";
    echo "  Received: {$d['received_at']}\n";
    echo "  Category: {$d['category']}  (confidence {$d['confidence']})\n";
    echo "  Client:   " . (((int)$d['crm_client_id']) ?: 'not identified') . "\n";
    echo "  Note:     {$d['customer_note']}\n";
    if ((string)$d['escalation'] !== '') echo "  Hold:     {$d['escalation']}\n";
    echo "\n" . rtrim((string)$d['body_excerpt']) . "\n";
    echo "\n  ── OUR DRAFT (not sent) ─────────────────────────────────────\n";
    echo "  Subject:  {$d['draft_subject']}\n\n";
    echo (trim((string)$d['draft_body']) !== ''
        ? rtrim((string)$d['draft_body'])
        : '  (no draft — no AI provider was configured when this was read)') . "\n\n";
    exit(0);
}

// ── Reading the mailbox ──────────────────────────────────────────────────
$mailbox = new JmapMailbox(
    (string)($config['email_ai_jmap_url']   ?? ''),
    (string)($config['email_ai_mailbox']    ?? ''),
    (string)($config['email_ai_mailbox_pw'] ?? '')
);

if (!$mailbox->isConfigured()) {
    echo "\n  The mailbox is not configured. Three keys are needed:\n\n";
    echo "    email_ai_jmap_url    e.g. https://mail.dishnetuganda.com\n";
    echo "    email_ai_mailbox     e.g. accounts@dishnetuganda.com\n";
    echo "    email_ai_mailbox_pw  its password\n\n";
    echo "  Put them in the plugin's config, never on a command line.\n\n";
    exit(1);
}

$crm   = new CrmApiClient($root, $config);
$brain = new DishNetAiBrain($config);

echo "\n  Reading " . (string)($config['email_ai_mailbox'] ?? '') . "\n";
echo "  AI provider: " . ($brain->isConfigured() ? 'configured' : 'NOT configured — drafts will be empty') . "\n";
if ($has('--dry')) echo "  DRY RUN — nothing will be stored\n";

// A dry run swaps the store for one backed by memory: the same code path,
// with nowhere permanent for the result to land.
if ($has('--dry')) {
    $mem = new PDO('sqlite::memory:');
    $mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store = new EmailDraftStore($mem);
}

$worker = new InboundMailWorker($mailbox, $store, $crm, $brain, $config);
$run    = $worker->run((string)$value('--since', ''), (int)$value('--limit', '25'));

if (!$run['ok']) { echo "\n  Could not read the mailbox: {$run['error']}\n\n"; exit(1); }

echo "\n  read {$run['read']} · drafted {$run['drafted']} · ignored {$run['ignored']}\n\n";
foreach ($run['items'] as $i) {
    $tag = $i['action'] === 'drafted' ? 'DRAFT' : strtoupper((string)$i['action']);
    printf("  %-7s %-26s %s\n", $tag, substr((string)$i['from'], 0, 26),
        substr((string)$i['subject'], 0, 46));
    printf("          %s\n", (string)$i['why']);
    if (($i['action'] ?? '') === 'drafted') {
        printf("          category %s · client %s · match %s%s\n",
            (string)$i['category'], ((int)$i['client_id'] ?: '—'), (string)$i['match'],
            !empty($i['requires_human']) ? ' · HUMAN APPROVAL REQUIRED' : '');
    }
}
echo "\n  Nothing was sent. " . ($has('--dry')
    ? "Nothing was stored either.\n\n"
    : "php tools/inbound_mail_run.php --list   to review\n\n");
exit(0);
