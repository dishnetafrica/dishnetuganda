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
 *   php tools/inbound_mail_run.php --forget 7      drop it so it is drafted again
 *   php tools/inbound_mail_run.php --to-drafts     file pending drafts in the mailbox
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
require_once $root . '/lib/MailService.php';
require_once $root . '/lib/SentCopy.php';
require_once $root . '/lib/DraftMailCopy.php';
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

if ($has('--to-drafts')) {
    // The approval inbox that already exists on every desk is the mail client.
    $esFile = $dataDir . '/email_settings.json';
    $es     = is_file($esFile)
        ? (json_decode((string)@file_get_contents($esFile), true) ?: [])
        : [];

    if (empty($es['sent_copy_enabled'])) {
        echo "\n  The mailbox copy is not configured, so there is nowhere to file a\n";
        echo "  draft. It uses the same IMAP settings as the Sent-folder archive:\n\n";
        echo "    php tools/set_sent_copy.php --show\n\n";
        exit(1);
    }

    $pending = $store->unplaced(25);
    if ($pending === []) {
        echo "\n  Nothing to file. Every pending draft with a body is already in the\n";
        echo "  mailbox, or is empty and must be written by hand.\n\n";
        exit(0);
    }

    echo "\n  Filing " . count($pending) . " draft(s) into the mailbox\n\n";
    $filed = 0;
    foreach ($pending as $d) {
        $r = DraftMailCopy::place($es, $d, $config);
        if (!empty($r['ok'])) {
            $store->markPlaced((int)$d['id'], (string)$r['folder']);
            $filed++;
            printf("  FILED   %-4d %-26s → %s\n", $d['id'],
                substr((string)$d['from_addr'], 0, 26), (string)$r['folder']);
        } else {
            printf("  FAILED  %-4d %-26s %s\n", $d['id'],
                substr((string)$d['from_addr'], 0, 26), (string)$r['error']);
        }
    }
    echo "\n  {$filed} draft(s) now waiting in the Drafts folder of "
       . (string)($config['email_ai_mailbox'] ?? 'the mailbox') . ".\n";
    echo "  Open webmail, edit if needed, and send — that is the approval.\n\n";
    echo "  Note: a draft sent from webmail is sent somewhere this plugin cannot\n";
    echo "  see, so its row here will still read \"pending\". The reply is real;\n";
    echo "  our record of it is not.\n\n";
    exit($filed > 0 ? 0 : 1);
}

if ($has('--forget')) {
    $id = (int)$value('--forget', '0');
    $r  = $store->forget($id);
    if (empty($r['ok'])) { echo "\n  " . ($r['error'] ?: 'nothing to forget') . "\n\n"; exit(1); }
    echo "\n  Draft {$id} forgotten. The next run will read that message again.\n\n";
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

// Whatever the probe found has to apply here too. A URL that only works with
// a pinned address is not configured until the address is configured with it.
$resolve = trim((string)($config['email_ai_jmap_resolve'] ?? ''));
if ($resolve !== '') $mailbox->setResolve(array_map('trim', explode(',', $resolve)));

$via = trim((string)($config['email_ai_jmap_via'] ?? ''));
if ($via !== '') $mailbox->setVia($via);

if (!$mailbox->isConfigured()) {
    // Say which key is missing, not which keys exist. The first version of
    // this listed all three whenever any one was absent, so a run that was
    // one password away from working read exactly like a run that had never
    // been configured at all.
    $need = [
        'email_ai_jmap_url'   => 'the mail server URL, e.g. https://mail.dishnetuganda.com',
        'email_ai_mailbox'    => 'the address to read, e.g. accounts@dishnetuganda.com',
        'email_ai_mailbox_pw' => 'its password',
    ];
    echo "\n  THE MAILBOX IS NOT READY\n\n";
    $missing = [];
    foreach ($need as $k => $what) {
        $set = trim((string)($config[$k] ?? '')) !== '';
        if (!$set) $missing[] = $k;
        printf("    %-21s %s\n", $k, $set
            ? ($k === 'email_ai_mailbox_pw' ? 'set' : (string)$config[$k])
            : 'MISSING — ' . $what);
    }
    echo "\n";
    if ($missing === ['email_ai_mailbox_pw']) {
        echo "  Only the password is missing. Two ways to set it:\n\n";
        echo "    1. uCRM → System → Plugins → DishNet → Configuration\n";
        echo "       field: \"Inbound mail: mailbox password\"\n";
        echo "       (if that field is not there, uCRM is still holding the old\n";
        echo "        manifest — use 2 instead)\n\n";
        echo "    2. php tools/set_mailbox_password.php\n";
        echo "       Typed in, never echoed, never in your shell history.\n\n";
    } else {
        echo "  php tools/set_inbound_mail.php --url <url> --mailbox <address>\n";
        echo "  php tools/set_mailbox_password.php\n\n";
    }
    exit(1);
}

// fromUcrm, not the constructor: it reads ucrm.json for the base URL and app
// key. Handing the constructor a plugin root and a config array type-errors on
// the second argument, which is a fatal at exactly the point where the mailbox
// has finally been configured.
$crm   = CrmApiClient::fromUcrm($root, $config);
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

        // A dry run exists to be read. Printing only the verdict hides the one
        // thing worth judging, and sends you looking for a draft id that a dry
        // run never created.
        if ($has('--dry')) {
            $body = trim((string)($i['draft_body'] ?? ''));
            echo "\n          ── draft ──────────────────────────────────────────\n";
            // "See the reason above" printed nothing above. Say it here.
            $why = trim((string)($i['escalation'] ?? ''));
            $empty = '(empty — must be written by hand'
                   . ($why !== '' ? ': ' . $why : '') . ')';
            foreach (explode("\n", $body !== '' ? $body : $empty) as $line) {
                echo '          ' . $line . "\n";
            }
            echo "          ───────────────────────────────────────────────────\n\n";
        }
    }
}
echo "\n  Nothing was sent. " . ($has('--dry')
    ? "Nothing was stored either.\n\n"
    : "php tools/inbound_mail_run.php --list   to review\n\n");
exit(0);
