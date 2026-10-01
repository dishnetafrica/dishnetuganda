<?php
/**
 * mail_log_doctor.php — READ-ONLY. Why did (or didn't) a lifecycle email go out?
 *
 * mail_doctor.php proves the transport is configured (DNS, SMTP relay, sender).
 * This answers the next question: given a working pipe, is the plugin actually
 * ATTEMPTING the lifecycle emails, and what happens when it does?
 *
 * It reads two records the plugin keeps and nothing else:
 *
 *   customer_email_log   every lifecycle-email attempt: template, recipient,
 *                        status (claimed | sent | failed) and the SMTP error.
 *                        Written by CustomerEmailDispatcher. "sent" means the
 *                        relay accepted it; delivery is then the relay's to do.
 *
 *   webhook_log.json     every uCRM event this plugin received, newest first.
 *                        If the events that should trigger an email are not
 *                        here, uCRM is not calling the plugin — and no switch,
 *                        however correct, can fire on an event that never
 *                        arrived.
 *
 * It opens the SQLite database read-only, SELECTs, and reads a JSON file. It
 * sends nothing, writes nothing, and changes no setting. Safe to run any time.
 *
 *   docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/mail_log_doctor.php
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';

$dataDir = function_exists('cliDataDir') ? cliDataDir($root) : getDataDir($root);

echo "\n  MAIL LOG DOCTOR — " . gmdate('Y-m-d H:i') . " UTC\n";
echo "  read-only: it reads the plugin's own records and sends nothing\n\n";
echo "  data dir   {$dataDir}\n";

// ── 1. The plugin's own record of every lifecycle email it tried to send ──────
$db = rtrim($dataDir, '/') . '/plugin.sqlite3';
echo "  database   {$db}  (" . (is_file($db) ? 'present' : 'MISSING') . ")\n\n";

echo "  ── customer_email_log — what the plugin tried to send ──────────────\n\n";
try {
    $pdo = new PDO('sqlite:' . $db, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    // Read-only intent: we only ever SELECT.
    $hasTable = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='customer_email_log'"
    )->fetchColumn();

    if (!$hasTable) {
        echo "    The table does not exist.\n";
        echo "    => The plugin has NEVER attempted a lifecycle email on this install.\n";
        echo "       The cause is upstream of sending: either no qualifying event has\n";
        echo "       occurred, or uCRM is not delivering events (see the webhook log below).\n\n";
    } else {
        $by = [];
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM customer_email_log GROUP BY status") as $r) {
            $by[(string)$r['status']] = (int)$r['n'];
        }
        if (!$by) {
            echo "    The table exists but holds no rows — nothing attempted yet.\n\n";
        } else {
            echo "    attempts by status:\n";
            foreach ($by as $s => $n) {
                $note = $s === 'sent'    ? 'accepted by the relay (delivery is then the relay\'s)'
                      : ($s === 'failed' ? 'the relay refused it — see the error below'
                      : ($s === 'claimed' ? 'in flight, or abandoned by a crash before settling' : ''));
                printf("      %-8s %4d   %s\n", $s, $n, $note);
            }
            echo "\n    last 20 attempts (newest first):\n";
            echo "      created_at           template           status   recipient / error\n";
            echo "      ------------------------------------------------------------------------\n";
            $q = $pdo->query(
                "SELECT created_at, template, status, recipient, error
                   FROM customer_email_log ORDER BY created_at DESC LIMIT 20"
            );
            foreach ($q as $r) {
                $tail = (string)$r['recipient'];
                if ((string)$r['status'] === 'failed' && trim((string)$r['error']) !== '') {
                    $tail .= '  « ' . substr((string)$r['error'], 0, 90);
                }
                printf("      %-20s %-18s %-8s %s\n",
                    (string)$r['created_at'], substr((string)$r['template'], 0, 18),
                    (string)$r['status'], $tail);
            }
            echo "\n";
        }
    }
} catch (\Throwable $e) {
    echo "    Could not read the database: " . $e->getMessage() . "\n\n";
}

// ── 2. Did the uCRM events that trigger those emails actually arrive? ─────────
echo "  ── webhook_log.json — which uCRM events reached the plugin ─────────\n\n";
$wl = rtrim($dataDir, '/') . '/webhook_log.json';
if (!is_file($wl)) {
    echo "    No webhook log at {$wl}.\n";
    echo "    => The plugin has received no uCRM event, OR the log was never written.\n";
    echo "       Check in uCRM: System → Webhooks, that an endpoint points at this\n";
    echo "       plugin and is active. No endpoint = no event = no lifecycle email.\n\n";
} else {
    $rows = json_decode((string)@file_get_contents($wl), true);
    $rows = is_array($rows) ? $rows : [];
    $n = count($rows);
    $oldest = $n ? (string)($rows[$n - 1]['received_at'] ?? '?') : '-';
    $newest = $n ? (string)($rows[0]['received_at'] ?? '?') : '-';
    echo "    {$n} entries held (the log keeps the most recent 300)\n";
    echo "    span: {$oldest}  →  {$newest}\n\n";

    $counts = [];
    foreach ($rows as $r) {
        $e = (string)($r['event'] ?? '?');
        $counts[$e] = ($counts[$e] ?? 0) + 1;
    }
    arsort($counts);
    echo "    event counts in the log:\n";
    foreach ($counts as $e => $c) {
        printf("      %-28s %4d\n", $e, $c);
    }

    // The events each lifecycle email waits for. If one never appears, that
    // email can never have fired — regardless of its switch.
    $triggers = [
        'welcome / service_resumed' => ['service.add', 'service.edit', 'service.activate'],
        'invoice'                   => ['invoice.add'],
        'payment_received'          => ['payment.add'],
        'service_paused'            => ['service.suspend', 'service.end'],
    ];
    echo "\n    trigger events present?\n";
    foreach ($triggers as $label => $evs) {
        $seen = [];
        foreach ($evs as $ev) if (isset($counts[$ev])) $seen[] = "{$ev}×{$counts[$ev]}";
        printf("      %-26s %s\n", $label, $seen ? implode(', ', $seen) : '— none seen —');
    }

    echo "\n    last 25 events (newest first):\n";
    foreach (array_slice($rows, 0, 25) as $r) {
        printf("      %-20s %-22s %s\n",
            (string)($r['received_at'] ?? '?'),
            substr((string)($r['event'] ?? '?'), 0, 22),
            substr((string)($r['message'] ?? ''), 0, 70));
    }
    echo "\n";
}

echo "  ── how to read this ────────────────────────────────────────────────\n";
echo "    • rows with status 'sent'  → the plugin IS emailing; if a customer\n";
echo "      never got it, the message was accepted then dropped/spam-filed —\n";
echo "      look in the Brevo dashboard, not here.\n";
echo "    • rows with status 'failed' → the error column is the exact cause.\n";
echo "    • no rows / no table / missing trigger event → nothing was ever\n";
echo "      attempted, because the triggering uCRM event did not arrive.\n\n";
