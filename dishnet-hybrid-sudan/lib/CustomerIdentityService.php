<?php
declare(strict_types=1);

require_once __DIR__ . '/MailProviderInterface.php';

/**
 * CustomerIdentityService — one permanent DishNet email identity per customer.
 *
 * john.doe@dishnetuganda.com is minted once, from the uCRM client record, and
 * never changes afterwards: not for a package change, not for a new kit, not
 * for billing changes. Termination suspends the mailbox (retention hold);
 * nothing here deletes. Deleting a mailbox is a manual, policy-gated admin
 * act on the mail server, recorded here as status='disabled'.
 *
 * Queueing: customer_identities is its own work queue. The webhook does the
 * fast, local part (reserve a unique address, set pending_action) and the
 * identity worker does the slow, remote part (mail server + uCRM write-back)
 * on its own schedule with attempt-squared backoff. The shared events queue
 * is deliberately not used for the work itself — event_processor.php claims
 * unfiltered batches there and acks types it does not know, which can swallow
 * a dedicated worker's job. A row cannot be swallowed.
 *
 * Idempotency is structural: UNIQUE(client_id) and UNIQUE(email). Replaying
 * client.add or retrying a provision converges instead of duplicating.
 *
 * Audit: every state change is ALSO emitted on the EventBus (entity
 * 'identity') purely as history — event_processor acks unknown informational
 * types, which files them as done rows queryable via getEntityHistory().
 */
class CustomerIdentityService
{
    public const DOMAIN_KEY   = 'identity_domain';        // e.g. dishnetuganda.com
    public const MAX_ATTEMPTS = 8;

    private \PDO $pdo;
    private array $config;
    private MailProviderInterface $provider;
    private $crm;        // CrmApiClient|null
    private $events;     // EventBus|null

    public function __construct(\PDO $pdo, array $config, MailProviderInterface $provider, $crm = null, $events = null)
    {
        $this->pdo      = $pdo;
        $this->config   = $config;
        $this->provider = $provider;
        $this->crm      = $crm;
        $this->events   = $events;
    }

    public function domain(): string
    {
        return strtolower(trim((string)($this->config[self::DOMAIN_KEY] ?? 'dishnetuganda.com')));
    }

    // ── webhook side: fast, local, no network ────────────────────────────

    /**
     * Reserve the identity and queue provisioning. Called from client.add.
     * Pure SQLite work — safe inside a webhook response.
     */
    public function reserveForClient(int $clientId, string $displayName, string $fallback = ''): array
    {
        if ($clientId <= 0) return $this->err('invalid client id');

        $row = $this->getByClient($clientId);
        if ($row) {
            // Permanence: an existing identity is never re-derived, even if
            // the client was renamed since. Just make sure work is queued if
            // it never completed.
            if ($row['status'] === 'pending' && $row['pending_action'] === null) {
                $this->setPending($clientId, 'provision');
            }
            return $this->ok($row['email']);
        }

        $email = $this->reserve($clientId, $displayName, $fallback);
        if ($email === null) return $this->err('could not reserve a unique address');
        return $this->ok($email);
    }

    /** Queue a retention-hold suspension (service.end / client.delete). */
    public function requestSuspend(int $clientId, string $reason = ''): array
    {
        $row = $this->getByClient($clientId);
        if (!$row) return $this->err('no identity for client');
        if (in_array($row['status'], ['suspended', 'disabled'], true)) return $this->ok('already ' . $row['status']);
        $this->setPending($clientId, 'suspend');
        $this->audit('identity.suspend_requested', $clientId, ['email' => $row['email'], 'reason' => $reason]);
        return $this->ok('queued');
    }

    /** Queue reactivation (customer returns). */
    public function requestReactivate(int $clientId): array
    {
        $row = $this->getByClient($clientId);
        if (!$row) return $this->err('no identity for client');
        $this->setPending($clientId, 'reactivate');
        return $this->ok('queued');
    }

    // ── worker side: slow, remote, retried ───────────────────────────────

    /**
     * Drain due queue rows. Backoff is attempts² minutes; a row that exhausts
     * MAX_ATTEMPTS keeps its pending_action (visible on the admin screen as
     * stuck) but stops being retried automatically.
     * Returns ['processed' => n, 'failed' => n].
     */
    public function processPending(int $limit = 10): array
    {
        $st = $this->pdo->prepare("
            SELECT * FROM customer_identities
             WHERE pending_action IS NOT NULL
               AND attempts < ?
               AND datetime(updated_at) <= datetime('now', '-' || (attempts*attempts) || ' minutes')
             ORDER BY updated_at ASC
             LIMIT ?");
        $st->execute([self::MAX_ATTEMPTS, $limit]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $done = 0; $failed = 0;
        foreach ($rows as $row) {
            $r = match ($row['pending_action']) {
                'provision'  => $this->provisionRow($row),
                'suspend'    => $this->suspendRow($row),
                'reactivate' => $this->reactivateRow($row),
                default      => $this->err('unknown action ' . (string)$row['pending_action']),
            };
            if ($r['ok']) {
                $done++;
            } else {
                $failed++;
                $this->pdo->prepare("UPDATE customer_identities
                                        SET attempts = attempts + 1,
                                            last_error = ?,
                                            updated_at = strftime('%Y-%m-%dT%H:%M:%SZ','now')
                                      WHERE client_id = ?")
                          ->execute([substr($r['error'], 0, 300), (int)$row['client_id']]);
            }
        }
        return ['processed' => $done, 'failed' => $failed];
    }

    /** Password handed out exactly once, over WhatsApp, by staff request. */
    public function resetPasswordForClient(int $clientId): array
    {
        $row = $this->getByClient($clientId);
        if (!$row || $row['status'] !== 'provisioned') return $this->err('identity not active');
        $r = $this->provider->resetPassword($row['email']);
        if (!$r['ok']) return $this->err($r['error']);
        $this->audit('identity.password_reset', $clientId, ['email' => $row['email']]);
        return $this->ok(['email' => $row['email'], 'password' => $r['data']]);
    }

    // ── lookups ──────────────────────────────────────────────────────────

    public function getByClient(int $clientId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM customer_identities WHERE client_id = ?');
        $st->execute([$clientId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findClientByEmail(string $email): ?int
    {
        $st = $this->pdo->prepare('SELECT client_id FROM customer_identities WHERE email = ?');
        $st->execute([strtolower(trim($email))]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function listRecent(int $limit = 100): array
    {
        $st = $this->pdo->prepare('SELECT * FROM customer_identities ORDER BY created_at DESC LIMIT ?');
        $st->execute([$limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── local-part generation ────────────────────────────────────────────

    /**
     * "John  DOE-Okello" → john.doe-okello ; collisions get .2, .3 …
     * Companies fall back to their trimmed company name. Result is lowercase
     * ASCII: dot-separated words, digits and hyphens only, always starting
     * with a letter.
     */
    public static function makeLocalPart(string $name, string $fallback = 'customer'): string
    {
        $s = self::asciiFold(trim($name) !== '' ? $name : $fallback);
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9\- ]+/', '', $s) ?? '';
        $s = preg_replace('/\s+/', '.', trim($s)) ?? '';
        $s = trim(preg_replace('/\.{2,}/', '.', $s) ?? '', '.-');
        if ($s === '' || $s[0] < 'a' || $s[0] > 'z') $s = 'c.' . ($s !== '' ? $s : bin2hex(random_bytes(3)));
        return substr($s, 0, 40);
    }

    private static function asciiFold(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t !== false && $t !== '' ? $t : (preg_replace('/[^\x20-\x7E]/', '', $s) ?? '');
    }

    // ── internals ────────────────────────────────────────────────────────

    private function reserve(int $clientId, string $name, string $fallback): ?string
    {
        $base   = self::makeLocalPart($name, $fallback !== '' ? $fallback : 'customer');
        $domain = $this->domain();

        for ($i = 0; $i < 100; $i++) {
            $local = $i === 0 ? $base : $base . '.' . ($i + 1);
            $email = $local . '@' . $domain;
            try {
                $this->pdo->prepare(
                    "INSERT INTO customer_identities (client_id, email, local_part, pending_action)
                     VALUES (?,?,?, 'provision')"
                )->execute([$clientId, $email, $local]);
                $this->audit('identity.reserved', $clientId, ['email' => $email]);
                return $email;
            } catch (\PDOException $e) {
                $existing = $this->getByClient($clientId);
                if ($existing) return $existing['email'];   // lost a race to ourselves — converged
                // email taken by another client → try the next suffix
            }
        }
        return null;
    }

    private function provisionRow(array $row): array
    {
        $clientId = (int)$row['client_id'];
        $display  = $this->displayNameFromCrm($clientId) ?? $row['local_part'];

        $r = $this->provider->ensureMailbox($row['email'], $display);
        if (!$r['ok']) return $this->err('provision failed: ' . $r['error']);

        $this->pdo->prepare("UPDATE customer_identities
                                SET status='provisioned', pending_action=NULL, provider_ref=?,
                                    last_error=NULL,
                                    provisioned_at=strftime('%Y-%m-%dT%H:%M:%SZ','now'),
                                    updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')
                              WHERE client_id=?")
                  ->execute([(string)$r['data'], $clientId]);

        $this->writeEmailToCrm($clientId, $row['email']);
        $this->audit('identity.provisioned', $clientId, ['email' => $row['email']]);
        return $this->ok(true);
    }

    private function suspendRow(array $row): array
    {
        $clientId = (int)$row['client_id'];
        $r = $this->provider->suspendMailbox($row['email']);
        if (!$r['ok']) return $this->err($r['error']);
        $this->pdo->prepare("UPDATE customer_identities
                                SET status='suspended', pending_action=NULL,
                                    suspended_at=strftime('%Y-%m-%dT%H:%M:%SZ','now'),
                                    updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')
                              WHERE client_id=?")->execute([$clientId]);
        $this->audit('identity.suspended', $clientId, ['email' => $row['email']]);
        return $this->ok(true);
    }

    private function reactivateRow(array $row): array
    {
        $clientId = (int)$row['client_id'];
        $r = $this->provider->unsuspendMailbox($row['email']);
        if (!$r['ok']) return $this->err($r['error']);
        $this->pdo->prepare("UPDATE customer_identities
                                SET status='provisioned', pending_action=NULL, suspended_at=NULL,
                                    updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')
                              WHERE client_id=?")->execute([$clientId]);
        $this->audit('identity.reactivated', $clientId, ['email' => $row['email']]);
        return $this->ok(true);
    }

    private function setPending(int $clientId, string $action): void
    {
        $this->pdo->prepare("UPDATE customer_identities
                                SET pending_action=?, attempts=0,
                                    updated_at=datetime('now','-1 hour')  -- due immediately
                              WHERE client_id=?")->execute([$action, $clientId]);
    }

    private function displayNameFromCrm(int $clientId): ?string
    {
        if (!$this->crm || !method_exists($this->crm, 'get')) return null;
        try {
            $c = $this->crm->get("clients/{$clientId}") ?? [];
            $n = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')) ?: (string)($c['companyName'] ?? '');
            return $n !== '' ? $n : null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * uCRM stays the source of truth: put the identity on the client's primary
     * contact. Contacts are updated by id; a client with no contact gets one.
     */
    private function writeEmailToCrm(int $clientId, string $email): void
    {
        if (!$this->crm || !method_exists($this->crm, 'patch')) return;
        try {
            $client   = $this->crm->get("clients/{$clientId}") ?? [];
            $contacts = $client['contacts'] ?? [];
            if ($contacts) {
                $patch = [];
                foreach (array_values($contacts) as $i => $c) {
                    $entry = ['id' => $c['id'] ?? null];
                    if ($i === 0) $entry['email'] = $email;
                    $patch[] = $entry;
                }
                $this->crm->patch("clients/{$clientId}", ['contacts' => $patch]);
            } else {
                $this->crm->patch("clients/{$clientId}", ['contacts' => [['email' => $email, 'isBilling' => true]]]);
            }
        } catch (\Throwable $e) {
            // Never let a CRM hiccup fail the provision — the identity row
            // already holds the truth and the screen shows the address.
            error_log('[CustomerIdentity] CRM email write failed for #' . $clientId . ': ' . $e->getMessage());
        }
    }

    private function audit(string $type, int $clientId, array $payload): void
    {
        if ($this->events && method_exists($this->events, 'emit')) {
            try { $this->events->emit($type, 'identity', $clientId, $payload, 9, 'identity-service'); }
            catch (\Throwable $e) { /* audit must never break the operation */ }
        }
    }

    private function ok($data): array      { return ['ok' => true,  'data' => $data, 'error' => '']; }
    private function err(string $m): array { return ['ok' => false, 'data' => null,  'error' => $m]; }
}
