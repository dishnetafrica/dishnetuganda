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
require_once __DIR__ . '/EmailRecipients.php';

class CustomerEmailDispatcher
{
    /** @var string */ private $dataDir;
    /** @var array */  private $config;
    /** @var object|null */ private $crm;
    /** @var PDO|null */    private $pdo;

    public function __construct(string $dataDir, array $config, $crm = null, $pdo = null)
    {
        $this->dataDir = $dataDir;
        // Resolved ONCE, here, rather than at each use.
        //
        // The webhook hydrates its array from the SqliteStore copy, which
        // never learns keys written to the config FILE. That has now caused
        // three separate faults in three layers: the switches read as off, the
        // body rendered with the Sudan company name, and the Reply-To header
        // pointed at info@dishnetafrica.com on a Ugandan quotation. Each was
        // fixed where it was found, which simply moved the bug one layer down.
        //
        // Holding the effective config means every use is correct by
        // construction, and there is no fourth layer to find.
        $this->config  = self::effectiveConfig($config);
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
        // Cached per request, keyed by the files' size and modification time,
        // so a write earlier in the same process is seen by the next call.
        // A one-shot static here made tools/set_customer_emails.php --all-off
        // report every switch still ON after it had just cleared them all:
        // the "Before" display filled the cache, the "After" display re-read
        // it. The stop worked; the read-back lied.
        static $fromDisk = null, $stamp = null;
        $root    = dirname(__DIR__);
        $dataDir = $GLOBALS['dataDir'] ?? ($root . '/data');
        $files   = [$root . '/data/config.json', $dataDir . '/config.json', $dataDir . '/kyc_config.json'];
        clearstatcache();
        $now = '';
        foreach ($files as $p) $now .= is_file($p) ? (filemtime($p) . ':' . filesize($p) . ';') : '-;';
        if ($fromDisk === null || $stamp !== $now) {
            $fromDisk = [];
            foreach ($files as $p) {
                if (!is_file($p)) continue;
                $d = json_decode((string)@file_get_contents($p), true);
                if (is_array($d)) $fromDisk = array_merge($fromDisk, $d);
            }
            $stamp = $now;
        }
        // Disk wins, EXCEPT where disk is empty and the caller has a value.
        //
        // uCRM re-materialises its config.json from the manifest, writing every
        // declared field it holds no value for as "". A plain array_merge lets
        // that empty string beat a real one — and the real one is often the
        // ConfigVault's copy, restored moments earlier precisely so that a
        // re-install would not lose it. Declaring the mailbox password in the
        // manifest was enough to blank a working password: the field appeared,
        // unfilled, and erased the value the vault was holding.
        //
        // An unfilled field is not an instruction to erase. Clearing a value
        // has its own explicit routes — saveOverrides unsets a key given '',
        // and set_mailbox_password.php has --clear — so nothing is lost by
        // refusing to read emptiness as intent.
        foreach ($fromDisk as $k => $v) {
            if (is_string($v) && trim($v) === ''
                && isset($config[$k]) && trim((string)$config[$k]) !== '') {
                continue;
            }
            $config[$k] = $v;
        }
        return $config;
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

    /**
     * Whether a customer email should CC the client's other contacts.
     *
     * Absent means off, so the code ships inert: existing emails keep going to
     * a single recipient until an operator turns email_cc_contacts on. When on,
     * the To is unchanged and every OTHER address on the client is CC'd.
     */
    public static function ccEnabled(array $config): bool
    {
        return !empty(self::effectiveConfig($config)['email_cc_contacts']);
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

            $cc = [];
            $email = trim((string)(is_array($to) ? '' : $to));
            if (is_array($to)) {
                [$email, $found, $cc] = $this->lookupRecipients((int)($to['client_id'] ?? 0));
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

            $built = CustomerEmails::render($key, $this->config, $data + ['name' => $name]);
            $mail  = new MailService($this->dataDir);
            if (!$mail->getConfig()) {
                return $this->result(false, 'plugin mail is not configured', $email);
            }

            // Who the header will say it is from. The dispatcher passes no
            // From override, so the configured sender IS the one used, and it
            // belongs on the audit row rather than only in the relay's log.
            $sender = MailService::bareAddress((string)($mail->getConfig()['from'] ?? ''));

            // Claim the dedupe key BEFORE sending. A crash between the send and
            // the log would otherwise let a retry send it again, and a customer
            // would rather miss an email than get it twice.
            if ($mark !== '') $this->claim($mark, $key, $email, $sender);

            $headers = ['Reply-To' => EmailTemplate::replyTo($this->config)];
            if ($cc) $headers['Cc'] = implode(', ', $cc);
            $res = $mail->send($email, $name, (string)$built['subject'],
                               (string)$built['html'], (string)$built['text'],
                               $headers, $attachments);

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

    /**
     * Send one payment-reminder email — the e-mail arm of the Uganda reminders
     * cron (InvoiceReminders). Separate from send() because the reminder is not
     * a catalogue event: it has its own switch (reminder_email_enabled) and is
     * kept out of the catalogue so the preview screen and the South Sudan golden
     * are untouched. It still shares the billing-first To, the CC list (when
     * email_cc_contacts is on), the dedupe log and the mailer.
     *
     * Gated by BOTH the master switch and reminder_email_enabled, so --all-off
     * stops reminders too. Dedupe key names the invoice+tier so a retry is a
     * no-op and, because it is a distinct key from the WhatsApp dedupe, the two
     * channels never block each other.
     *
     * @return array{sent:bool, reason:string, to:string}
     */
    public function sendReminderDue(int $clientId, string $name, array $data, string $dedupe = ''): array
    {
        try {
            // $this->config is already the effective (disk-merged) config —
            // resolved once in the constructor — so read the switch from it
            // directly rather than re-resolving it.
            if (!self::masterEnabled($this->config)
                || empty($this->config['reminder_email_enabled'])) {
                return $this->result(false, 'switched off', '');
            }
            [$email, $found, $cc] = $this->lookupRecipients($clientId);
            if (trim($name) === '') $name = $found;
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->result(false, 'no usable email address', $email);
            }

            $mark = $dedupe !== '' ? "reminder_due:{$dedupe}" : '';
            if ($mark !== '' && $this->alreadySent($mark)) {
                return $this->result(false, 'already sent', $email);
            }

            $built = CustomerEmails::reminderDue($this->config, $data + ['name' => $name]);
            $mail  = new MailService($this->dataDir);
            if (!$mail->getConfig()) {
                return $this->result(false, 'plugin mail is not configured', $email);
            }
            $sender = MailService::bareAddress((string)($mail->getConfig()['from'] ?? ''));
            if ($mark !== '') $this->claim($mark, 'reminder_due', $email, $sender);

            $headers = ['Reply-To' => EmailTemplate::replyTo($this->config)];
            if ($cc) $headers['Cc'] = implode(', ', $cc);
            $res = $mail->send($email, $name, (string)$built['subject'],
                               (string)$built['html'], (string)$built['text'], $headers);

            $ok = !empty($res['ok']);
            if ($mark !== '') $this->settle($mark, $ok, (string)($res['error'] ?? ''));
            return $this->result($ok, $ok ? 'sent' : (string)($res['error'] ?? 'send failed'), $email);
        } catch (\Throwable $e) {
            error_log('[CustomerEmailDispatcher] reminder_due: ' . $e->getMessage());
            return $this->result(false, 'error: ' . $e->getMessage(), '');
        }
    }

    // ── Customer Installation Authorisation (5.18.82, docs/61 §3, docs/63) ──────
    //
    // Two e-mails off the catalogue, on the reminderDue pattern: the catalogue's
    // tests iterate CATALOGUE, so a feature with its own switch sits beside it.
    // The master switch still rules. Each has its own key —
    // customer_email_install_auth_request / _confirmed.
    //
    // 5.18.83 (docs/64 §E): ABSENT MEANS OFF, like the catalogue keys. 5.18.82 had
    // absent = on, so on an install whose customer e-mails were already switched
    // on for receipts and invoices, turning the feature on sent these e-mails too
    // without anyone having chosen to. Each channel is now a deliberate choice;
    // a request no switched-on channel can carry is refused before any record is
    // made (InstallAuth::noChannelReason), so "silently absent" cannot happen.
    // 1 / on / true / yes turn one on.

    /** Whether one of the two authorisation e-mails may send: the master switch, then its own key (absent = off). */
    public static function installAuthEnabled(string $kind, array $config): bool
    {
        if (!self::masterEnabled($config)) return false;
        $c = self::effectiveConfig($config);
        $v = $c['customer_email_install_auth_' . $kind] ?? null;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * Send the authorisation request ('request') or the confirmation ('confirmed') to one address — the customer's
     * uCRM contacts[0] e-mail (D10), already chosen by the caller. $dedupe identifies the send: the job and the token
     * for a request (a resent link is a new send), the job and the acceptance reference for a confirmation.
     *
     * @return array{sent:bool, reason:string, to:string}
     */
    public function sendInstallAuth(string $kind, string $email, string $name, array $data, string $dedupe = ''): array
    {
        try {
            if (!in_array($kind, ['request', 'confirmed'], true)) return $this->result(false, 'unknown kind', '');
            if (!self::installAuthEnabled($kind, $this->config)) return $this->result(false, 'switched off', '');
            $email = trim($email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->result(false, 'no usable email address', $email);
            }
            $mark = $dedupe !== '' ? "install_auth_{$kind}:{$dedupe}" : '';
            if ($mark !== '' && $this->alreadySent($mark)) return $this->result(false, 'already sent', $email);

            require_once __DIR__ . '/InstallAuthEmails.php';
            $built = $kind === 'request'
                ? InstallAuthEmails::request($this->config, $data)
                : InstallAuthEmails::confirmed($this->config, $data);
            $mail = new MailService($this->dataDir);
            if (!$mail->getConfig()) return $this->result(false, 'plugin mail is not configured', $email);
            $sender = MailService::bareAddress((string)($mail->getConfig()['from'] ?? ''));
            if ($mark !== '') $this->claim($mark, 'install_auth_' . $kind, $email, $sender);

            $headers = ['Reply-To' => EmailTemplate::replyTo($this->config)];
            $res = $mail->send($email, $name, (string)$built['subject'], (string)$built['html'], (string)$built['text'], $headers);
            $ok = !empty($res['ok']);
            if ($mark !== '') $this->settle($mark, $ok, (string)($res['error'] ?? ''));
            return $this->result($ok, $ok ? 'sent' : (string)($res['error'] ?? 'send failed'), $email);
        } catch (\Throwable $e) {
            error_log('[CustomerEmailDispatcher] install_auth_' . $kind . ': ' . $e->getMessage());
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
        [$email, $name] = $this->lookupRecipients($clientId);
        return [$email, $name];
    }

    /**
     * To, display name, and the CC list for a client.
     *
     * The To is the long-standing billing-first rule — turning CC on never
     * moves it. The CC list is every OTHER address on the client, and only
     * when email_cc_contacts is on, so with the switch off this returns an
     * empty CC and behaves exactly as it always did.
     *
     * @return array{0:string,1:string,2:array<int,string>}  [email, name, cc]
     */
    private function lookupRecipients(int $clientId): array
    {
        if ($clientId <= 0 || !$this->crm) return ['', '', []];
        $client = $this->crm->get("clients/{$clientId}") ?: [];
        [$email, $name] = self::contactFor($client);
        $cc = ($email !== '' && self::ccEnabled($this->config))
            ? EmailRecipients::ccFor($client, $email) : [];
        return [$email, $name, $cc];
    }

    /**
     * The billing-first To rule, on an already-fetched client. Billing contact
     * with an email first, then any contact with one.
     *
     * @return array{0:string,1:string}  [email, name]
     */
    private static function contactFor(array $client): array
    {
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
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    sender     TEXT NOT NULL DEFAULT ''
                 )");
            // Additive, for a table created before the column existed. An
            // audit row that cannot say who sent it answers half a question.
            $cols = [];
            foreach ($this->pdo->query('PRAGMA table_info(customer_email_log)') as $c) {
                $cols[] = (string)($c['name'] ?? '');
            }
            if (!in_array('sender', $cols, true)) {
                $this->pdo->exec("ALTER TABLE customer_email_log ADD COLUMN sender TEXT NOT NULL DEFAULT ''");
            }
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

    private function claim(string $mark, string $key, string $email, string $sender = ''): void
    {
        if (!$this->table()) return;
        try {
            // REPLACE, not IGNORE: a retry after a failure must be able to
            // take the row back and reset it to claimed.
            $st = $this->pdo->prepare(
                'INSERT OR REPLACE INTO customer_email_log (dedupe_key, template, recipient, status, sender)
                 VALUES (?,?,?,\'claimed\',?)');
            $st->execute([$mark, $key, $email, $sender]);
        } catch (\Throwable $e) {}
    }

    /**
     * Forget a settled send, so the same event may be sent again on purpose.
     *
     * A row marked 'sent' blocks forever by design. tools/quote_email_send.php
     * --clear-claim exists to make a quotation resendable, and it used to
     * clear only the QEMAIL claim — which was enough while this table held no
     * quotation rows. Now that the webhook records its sends here, that row
     * would still say "sent" and the webhook would still refuse. Both records
     * have to go for "the webhook may send again" to be true.
     *
     * @return bool whether a row existed to forget
     */
    public static function forget(PDO $pdo, string $key, string $dedupe): bool
    {
        try {
            $st = $pdo->prepare('DELETE FROM customer_email_log WHERE dedupe_key = ?');
            $st->execute(["{$key}:{$dedupe}"]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) { return false; }
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
