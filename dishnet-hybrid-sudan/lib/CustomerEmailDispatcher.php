<?php
/**
 * CustomerEmailDispatcher — the one way a lifecycle email reaches a customer.
 *
 * The nine templates in CustomerEmails rendered correctly and were wired to
 * nothing, so only a quotation could ever be triggered. This connects the rest
 * to their real events, behind switches that all start OFF.
 *
 *   customer_emails_enabled = 1        the master switch
 *   customer_email_<key>    = 1        one switch per event
 *
 * BOTH must be on. Absence means off, so deploying this changes nothing until
 * somebody decides otherwise — for the Sudan install as much as the Uganda one.
 * Events can then be turned on one at a time and watched, and turned off again
 * in a second if the copy is wrong.
 *
 * Two rules hold everywhere in here:
 *
 *   It never throws. This is called from webhooks and crons beside a WhatsApp
 *   send that already worked. An email failure must not roll back a payment or
 *   abort a cron mid-batch, so every path returns a result array.
 *
 *   It never sends the same thing twice. Webhooks retry. Every send is logged
 *   with a dedupe key first, and a key already present is skipped.
 *
 * The quotation email is NOT dispatched here — it keeps its own path in
 * QuotationService, which fetches and attaches the uCRM PDF.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/CustomerEmails.php';
require_once __DIR__ . '/EmailTemplate.php';
require_once __DIR__ . '/MailService.php';

class CustomerEmailDispatcher
{
    /** @var string */ private $dataDir;
    /** @var array */  private $config;
    /** @var object|null */ private $crm;
    /** @var PDO|null */    private $pdo;

    public function __construct(string $dataDir, array $config, $crm = null, $pdo = null)
    {
        $this->dataDir = $dataDir;
        $this->config  = $config;
        $this->crm     = $crm;
        $this->pdo     = $pdo;
    }

    /**
     * Claim an event exactly once, across code paths that do not know about
     * each other.
     *
     * The quotation email has two entry points: QuotationService, when a quote
     * is created through the DishNet app, and the quote.add webhook, which
     * fires for every quote including that one. Without a shared claim the
     * customer gets the same quotation twice.
     *
     * Uses notification_dedup — the same table and the same INSERT OR IGNORE
     * that webhook.php already relies on for invoices — so the two mechanisms
     * cannot disagree about what has been sent.
     *
     * @return bool true if the caller now owns this event
     */
    public static function claimOnce(PDO $pdo, string $key): bool
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS notification_dedup (
                dedup_key TEXT PRIMARY KEY, sent_at TEXT NOT NULL)");
            $st = $pdo->prepare('INSERT OR IGNORE INTO notification_dedup (dedup_key, sent_at) VALUES (?, ?)');
            $st->execute([$key, date('Y-m-d H:i:s')]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            // A broken claim must not silence the email; a duplicate is the
            // lesser failure than a customer hearing nothing.
            return true;
        }
    }

    /**
     * Give a claim back, so a failed attempt can be retried.
     *
     * claimOnce() has to run BEFORE the work, or two paths race and the
     * customer gets two emails. But a claim held after a failure is worse than
     * the race it prevents: the quotation would then never be sent by anyone,
     * and nothing would say why. Every failure path releases.
     */
    public static function releaseClaim(PDO $pdo, string $key): void
    {
        try {
            $st = $pdo->prepare('DELETE FROM notification_dedup WHERE dedup_key = ?');
            $st->execute([$key]);
        } catch (\Throwable $e) {}
    }

    /**
     * The switches as they are ON DISK, merged over whatever the caller holds.
     *
     * Two backends answer to the name kyc_config.json. tools/set_customer_emails
     * writes the FILE through PluginConfig::saveOverrides; webhook.php hydrates
     * $config from the SqliteStore copy, which never learns new keys. So an
     * operator could turn quotation email on, see it ON in the tool, and have
     * the webhook read it as off — which is exactly what happened, and the send
     * returned silently because a switched-off event is not an error.
     *
     * lib/currency.php solved this once for the ledger currency and
     * OverdueDunningHelpers again for the dunning gate. Same fix here: read the
     * files, cache per request, let the caller's array win only where the files
     * say nothing.
     */
    public static function effectiveConfig(array $config = []): array
    {
        static $fromDisk = null;
        if ($fromDisk === null) {
            $fromDisk = [];
            $root    = dirname(__DIR__);
            $dataDir = $GLOBALS['dataDir'] ?? ($root . '/data');
            foreach ([$root . '/data/config.json', $dataDir . '/config.json',
                      $dataDir . '/kyc_config.json'] as $p) {
                if (!is_file($p)) continue;
                $d = json_decode((string)@file_get_contents($p), true);
                if (is_array($d)) $fromDisk = array_merge($fromDisk, $d);
            }
        }
        return array_merge($config, $fromDisk);
    }

    /** The master switch. Absent means off. */
    public static function masterEnabled(array $config): bool
    {
        $c = self::effectiveConfig($config);
        return !empty($c['customer_emails_enabled']);
    }

    /** Whether one event may send. Both switches must be on. */
    public static function enabled(string $key, array $config): bool
    {
        if (!self::masterEnabled($config)) return false;
        $c = self::effectiveConfig($config);
        return !empty($c['customer_email_' . $key]);
    }

    /** Every event and its current state, for settings screens and doctors. */
    public static function states(array $config): array
    {
        $out = [];
        foreach (array_keys(CustomerEmails::CATALOGUE) as $k) {
            // Two templates are not dispatched here and have no switch:
            // the quotation is sent by QuotationService with its PDF, and the
            // login code is sent by the portal the moment a customer asks for
            // one — gating that would lock people out of their own account.
            // The login code is sent by the portal the moment a customer asks
            // for one; gating that would lock people out of their account.
            //
            // The quotation IS switchable, because there are two paths to it:
            // QuotationService covers quotes created through the app, under
            // quote_email_via_plugin, and the quote.add webhook covers quotes
            // typed into uCRM's own screen, under this switch. Without it that
            // second path could not be turned on at all.
            if ($k === 'login_code') continue;
            $out[$k] = [
                'label'   => CustomerEmails::CATALOGUE[$k][0],
                'trigger' => CustomerEmails::CATALOGUE[$k][1],
                'on'      => self::enabled($k, $config),
            ];
        }
        return $out;
    }

    /**
     * Send one lifecycle email.
     *
     * $to may be an address, or ['client_id' => int] to look the address up in
     * uCRM the way the quotation path does — billing contact first, then any
     * contact. $dedupe should identify the real-world event (an invoice
     * number, a payment id) so a webhook retry is a no-op.
     *
     * @return array{sent:bool, reason:string, to:string}
     */
    public function send(string $key, $to, string $name, array $data, string $dedupe = '', array $attachments = []): array
    {
        try {
            if (!self::enabled($key, $this->config)) {
                return $this->result(false, 'switched off', '');
            }
            if (!isset(CustomerEmails::CATALOGUE[$key])) {
                return $this->result(false, "no such template: {$key}", '');
            }

            $email = trim((string)(is_array($to) ? '' : $to));
            if (is_array($to)) {
                [$email, $found] = $this->lookupContact((int)($to['client_id'] ?? 0));
                // Some call sites know the client id but not the name.
                if (trim($name) === '') $name = $found;
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->result(false, 'no usable email address', $email);
            }

            $mark = $dedupe !== '' ? "{$key}:{$dedupe}" : '';
            if ($mark !== '' && $this->alreadySent($mark)) {
                return $this->result(false, 'already sent', $email);
            }

            // effectiveConfig, not $this->config. The webhook hydrates its
            // config from the SqliteStore copy, which never learned the
            // email_* branding keys — so a webhook-sent quotation carried the
            // Sudan defaults: "DishNet Africa Ltd." instead of the registered
            // "DishNet Africa Limited". The switches were fixed for this
            // reason already; the rendering needed the same treatment.
            $built = CustomerEmails::render($key, self::effectiveConfig($this->config),
                                            $data + ['name' => $name]);
            $mail  = new MailService($this->dataDir);
            if (!$mail->getConfig()) {
                return $this->result(false, 'plugin mail is not configured', $email);
            }

            // Claim the dedupe key BEFORE sending. A crash between the send and
            // the log would otherwise let a retry send it again, and a customer
            // would rather miss an email than get it twice.
            if ($mark !== '') $this->claim($mark, $key, $email);

            $res = $mail->send($email, $name, (string)$built['subject'],
                               (string)$built['html'], (string)$built['text'],
                               ['Reply-To' => EmailTemplate::replyTo($this->config)],
                               $attachments);

            $ok = !empty($res['ok']);
            if ($mark !== '') $this->settle($mark, $ok, (string)($res['error'] ?? ''));
            return $this->result($ok, $ok ? 'sent' : (string)($res['error'] ?? 'send failed'), $email);
        } catch (\Throwable $e) {
            // Deliberately swallowed: see the class comment. The caller has
            // already done the thing that matters.
            error_log('[CustomerEmailDispatcher] ' . $key . ': ' . $e->getMessage());
            return $this->result(false, 'error: ' . $e->getMessage(), '');
        }
    }

    private function result(bool $sent, string $reason, string $to): array
    {
        return ['sent' => $sent, 'reason' => $reason, 'to' => $to];
    }

    /**
     * The customer's address and display name from uCRM.
     *
     * Billing contact first, then any contact — the same rule the quotation
     * path uses, so both emails reach the same person.
     *
     * @return array{0:string,1:string}  [email, name]
     */
    private function lookupContact(int $clientId): array
    {
        if ($clientId <= 0 || !$this->crm) return ['', ''];
        $client = $this->crm->get("clients/{$clientId}") ?: [];

        $name = trim((string)(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')));
        if ($name === '') $name = trim((string)($client['companyName'] ?? ''));

        $contacts = is_array($client['contacts'] ?? null) ? $client['contacts'] : [];
        foreach ($contacts as $c) {
            if (!empty($c['isBilling']) && !empty($c['email'])) return [(string)$c['email'], $name];
        }
        foreach ($contacts as $c) {
            if (!empty($c['email'])) return [(string)$c['email'], $name];
        }
        return ['', $name];
    }

    private function table(): bool
    {
        if (!$this->pdo) return false;
        static $made = false;
        if ($made) return true;
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS customer_email_log (
                    dedupe_key TEXT PRIMARY KEY,
                    template   TEXT NOT NULL,
                    recipient  TEXT NOT NULL,
                    status     TEXT NOT NULL DEFAULT 'claimed',
                    error      TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                 )");
            $made = true;
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Whether this exact event has already been handled.
     *
     * A delivered email blocks forever. A failure does NOT — a transient SMTP
     * problem must be retryable, or one bad minute silently costs a customer
     * their receipt. A claim that never settled blocks only briefly, long
     * enough to cover a webhook retrying while the first send is still in
     * flight, after which it is treated as a crash and allowed through.
     */
    private function alreadySent(string $mark): bool
    {
        if (!$this->table()) return false;
        try {
            $st = $this->pdo->prepare(
                'SELECT status, created_at FROM customer_email_log WHERE dedupe_key = ?');
            $st->execute([$mark]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            if ($row['status'] === 'sent')   return true;
            if ($row['status'] === 'failed') return false;
            // 'claimed': in flight, or abandoned by a crash.
            $age = time() - (int)strtotime((string)$row['created_at'] . ' UTC');
            return $age >= 0 && $age < 600;
        } catch (\Throwable $e) { return false; }
    }

    private function claim(string $mark, string $key, string $email): void
    {
        if (!$this->table()) return;
        try {
            // REPLACE, not IGNORE: a retry after a failure must be able to
            // take the row back and reset it to claimed.
            $st = $this->pdo->prepare(
                'INSERT OR REPLACE INTO customer_email_log (dedupe_key, template, recipient, status)
                 VALUES (?,?,?,\'claimed\')');
            $st->execute([$mark, $key, $email]);
        } catch (\Throwable $e) {}
    }

    private function settle(string $mark, bool $ok, string $error): void
    {
        if (!$this->table()) return;
        try {
            $st = $this->pdo->prepare('UPDATE customer_email_log SET status = ?, error = ? WHERE dedupe_key = ?');
            $st->execute([$ok ? 'sent' : 'failed', substr($error, 0, 500), $mark]);
        } catch (\Throwable $e) {}
    }
}
