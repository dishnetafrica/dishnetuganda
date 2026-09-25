<?php
declare(strict_types=1);

require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/PaymentUuids.php';
require_once __DIR__ . '/UcrmClientTarget.php';
require_once __DIR__ . '/KycService.php';

/**
 * KycCrmSync — put a KYC customer who is saved in the plugin but not in uCRM
 * into uCRM.
 *
 * KycService creates the uCRM client in the same request as the KYC form. When
 * that fails, the application is saved with crm_sync_status 'pending' and this
 * class finishes the job: every five minutes from cron/kyc_crm_sync.php, or at
 * once when an admin presses Retry on the Orders screen.
 *
 * Until 5.18.28 the retry job could never run. It looked for a database file
 * called data.db that nothing creates, then called SqliteStore's private
 * constructor, then read four settings no screen writes — and returned
 * silently at the first of those, every five minutes, while the scheduler
 * logged a normal run. It would also have re-sent organization 2. On the
 * Uganda install three customers waited 1–13 days for it (measured
 * 25 September 2026).
 *
 * What it does now, per application, in order:
 *
 *   1. claims the application with one conditional UPDATE, so a cron run and
 *      an admin's Retry can never both create the same customer;
 *   2. fits the create to the connected uCRM (UcrmClientTarget) — the stored
 *      request may carry the South Sudan organization and custom fields;
 *   3. looks for a uCRM client with the same phone number, the way the KYC form
 *      does, and if there is one STOPS and asks a person (status 'review'):
 *      after days of waiting someone may have typed the customer in by hand,
 *      and a duplicate client is worse than a customer who waited;
 *   4. creates the client, writes its id back, and finishes what the form
 *      would have done: the cash payment, the quote, and — only on the South
 *      Sudan layout, whose numbers they use — the work order and tag.
 *
 * Applications older than 30 days are not created automatically: a person
 * checks them and uses Retry.
 */
final class KycCrmSync
{
    const MAX_ATTEMPTS = 10;
    const MAX_AGE_DAYS = 30;
    /** Statuses that mean "saved here, not in uCRM". */
    const WAITING = ['pending', 'review', 'failed'];

    private $store;
    private $crm;
    private array $config;
    private ?UcrmClientTarget $target = null;

    public function __construct($store, $crm, array $config = [])
    {
        $this->store  = $store;
        $this->crm    = $crm;
        $this->config = $config;
    }

    /**
     * The cron pass: every 'pending' application that is due. 'review' and
     * 'failed' wait for a person.
     *
     * @return array{due:int,synced:int,failed:int,review:int,gave_up:int,busy:int}
     */
    public function runDue(): array
    {
        $out = ['due' => 0, 'synced' => 0, 'failed' => 0, 'review' => 0, 'gave_up' => 0, 'busy' => 0];
        $now = time();
        foreach (($this->store->load('kyc_applications.json') ?? []) as $app) {
            if (!empty($app['crm_client_id'])) continue;
            if (($app['crm_sync_status'] ?? '') !== 'pending') continue;
            $id = (int)($app['id'] ?? 0);
            if ($id <= 0) continue;

            $attempts = (int)($app['crm_sync_attempts'] ?? 0);
            $last     = (string)($app['crm_sync_last_attempt'] ?? '');
            if ($attempts > 0 && $last !== '') {
                // 5 min, 15 min, 45 min, 2 h 15, 6 h 45, then 12 h
                $backoff = (int)min(43200, 300 * (3 ** ($attempts - 1)));
                if ($now < (int)strtotime($last) + $backoff) continue;
            }
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->store->updateOne('kyc_applications.json', 'id', $id, [
                    'crm_sync_status' => 'failed',
                    'crm_sync_error'  => 'Gave up after ' . self::MAX_ATTEMPTS . ' attempts. Last: '
                                         . (string)($app['crm_sync_error'] ?? 'no reason recorded'),
                ]);
                error_log("[kyc_crm_sync] App #{$id} GAVE UP after " . self::MAX_ATTEMPTS . ' attempts');
                $out['gave_up']++;
                continue;
            }
            $submitted = (int)strtotime((string)($app['submitted_at'] ?? ''));
            if ($submitted > 0 && $now - $submitted > self::MAX_AGE_DAYS * 86400) {
                $this->store->updateOne('kyc_applications.json', 'id', $id, [
                    'crm_sync_status' => 'review',
                    'crm_sync_error'  => 'Waited more than ' . self::MAX_AGE_DAYS . ' days, so it is not created'
                                         . ' automatically. Check uCRM does not already have this customer, then retry.',
                ]);
                $out['review']++;
                continue;
            }

            $out['due']++;
            $r = $this->attempt($app, false, 'cron');
            if     ($r['status'] === 'synced') $out['synced']++;
            elseif ($r['status'] === 'review') $out['review']++;
            elseif ($r['status'] === 'busy')   $out['busy']++;
            else                               $out['failed']++;
            usleep(200000); // do not hammer uCRM
        }
        return $out;
    }

    /**
     * One application, now. Used by an admin's Retry. Backoff and the attempt
     * limit do not apply; $force also skips the phone check, for when a person
     * has looked at the uCRM client it found and decided this is someone else.
     *
     * @return array{ok:bool,status:string,crm_client_id:?string,message:string}
     */
    public function syncOne(int $appId, bool $force = false, string $by = 'admin'): array
    {
        $app = $this->store->findOne('kyc_applications.json', 'id', $appId);
        if ($app === null) return $this->result(false, 'missing', null, "Application #{$appId} does not exist.");
        if (!empty($app['crm_client_id'])) {
            return $this->result(true, 'synced', (string)$app['crm_client_id'],
                "Application #{$appId} is already in the CRM as client #{$app['crm_client_id']}.");
        }
        return $this->attempt($app, $force, $by);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function attempt(array $app, bool $force, string $by): array
    {
        $id = (int)$app['id'];
        if (!$this->claim($id)) {
            return $this->result(false, 'busy', null,
                "Application #{$id} is being sent to the CRM right now; look again in a minute.");
        }
        try {
            return $this->run($app, $force, $by);
        } finally {
            $this->store->updateOne('kyc_applications.json', 'id', $id, ['crm_sync_claim' => null]);
        }
    }

    private function run(array $app, bool $force, string $by): array
    {
        $id  = (int)$app['id'];
        $now = date('Y-m-d H:i:s');

        // 2. Fit the request to the connected uCRM.
        $placed = $this->target()->apply($this->payloadFor($app));
        if ($placed['error'] !== '') {
            return $this->failed($app, $placed['error'], $by);
        }
        $payload = $placed['payload'];

        // 3. A uCRM client with the same phone number stops the automatic path.
        if (!$force) {
            $same = $this->crmClientsWithPhone((string)($app['mobile'] ?? ''));
            if ($same !== []) {
                $msg = 'uCRM already has a client with this phone number (#' . implode(', #', $same) . ').'
                     . ' Check it is not this customer before retrying.';
                $this->store->updateOne('kyc_applications.json', 'id', $id, [
                    'crm_sync_status'       => 'review',
                    'crm_sync_error'        => $msg,
                    'crm_review_client_ids' => $same,
                    'crm_sync_last_attempt' => $now,
                    'crm_sync_by'           => $by,
                ]);
                return $this->result(false, 'review', null, "Application #{$id}: {$msg}");
            }
        }

        // 4. Create. A taken username is the one refusal worth another try.
        $username = (string)($payload['username'] ?? '');
        $res = null;
        for ($try = 1; $try <= 5; $try++) {
            $res = $this->crm->post('clients', $payload);
            if (is_array($res) && !empty($res['id'])) break;
            $err = $this->crm->getLastError();
            $taken = (int)($err['http_code'] ?? 0) === 422
                && (isset($err['response']['errors']['username'])
                    || strpos((string)json_encode($err), 'already taken') !== false);
            if (!$taken || $username === '') { $res = null; break; }
            $username = KycService::generateNextUsername($username);
            $payload['username'] = $username;
            $res = null;
        }
        if (!is_array($res) || empty($res['id'])) {
            return $this->failed($app, UcrmClientTarget::describe($this->crm->getLastError()), $by);
        }

        $crmId = (string)$res['id'];
        $this->store->updateOne('kyc_applications.json', 'id', $id, [
            'crm_client_id'         => $crmId,
            'crm_sync_status'       => 'synced',
            'crm_sync_payload'      => null,
            'crm_sync_error'        => null,
            'crm_review_client_ids' => null,
            'crm_synced_at'         => $now,
            'crm_sync_last_attempt' => $now,
            'crm_sync_by'           => $by,
            'username'              => $username !== '' ? $username : ($app['username'] ?? ''),
        ]);
        error_log("[kyc_crm_sync] App #{$id} created in uCRM as client #{$crmId} ({$by})");

        $this->afterCreate($app, $crmId);

        return $this->result(true, 'synced', $crmId,
            "Application #{$id} is now in the CRM as client #{$crmId}"
            . (!empty($app['is_lead']) ? ' (a lead: find it under Leads in uCRM).' : '.'));
    }

    /** What KycService would have done after a successful create. */
    private function afterCreate(array $app, string $crmId): void
    {
        $id = (int)$app['id'];

        // Work order and tag use South Sudan template and tag numbers.
        if ($this->target()->legacyLayout()) {
            $connectivity = (string)($app['connectivity_type'] ?? 'New Connection');
            $tpl = $connectivity === 'New Connection' ? KycService::TPL_WORK_ORDER_NEW : KycService::TPL_WORK_ORDER_OTHER;
            $this->crm->post('documents', ['clientId' => (int)$crmId, 'name' => 'Work Order', 'templateId' => $tpl]);
            $tagMap = ['Ownership Change' => KycService::TAG_OWNERSHIP_CHANGE, 'Shifting Connection' => KycService::TAG_SHIFTING];
            $this->crm->patch("clients/{$crmId}/add-tag/" . ($tagMap[$connectivity] ?? KycService::TAG_NEW_CONNECTION));
        }

        // The cash the agent collected, once — under the same switch the form obeys.
        $isCash  = ($app['sales_type'] ?? '') === 'Cash';
        $amount  = (float)($app['amount_charged'] ?? 0);
        $autoPay = ($this->config['kyc_auto_payment_enabled'] ?? true) !== false;
        if ($autoPay && $isCash && $amount > 0 && empty($app['payment_id'])) {
            $pay = $this->crm->createPaymentSafe([
                'clientId'     => (int)$crmId,
                'amount'       => $amount,
                'currencyCode' => dn_payload_currency('', $this->config),
                'methodId'     => PaymentUuids::resolve('Cash'),
                'note'         => 'Cash collected at registration (CRM retry) | Agent: '
                                  . ($app['retailer_name'] ?? '') . ' | Ref: KYC-' . $crmId,
            ], 'KYC-' . $crmId);
            if (!empty($pay['success']) && !empty($pay['id'])) {
                $this->store->updateOne('kyc_applications.json', 'id', $id, [
                    'payment_id' => $pay['id'], 'payment_created' => true,
                ]);
            }
        }

        // The quote, where this install has the admin token quotes need.
        $token = trim((string)($this->config['crm_auth_token'] ?? ''));
        $auto  = ($this->config['kyc_auto_quote_enabled'] ?? true) !== false;
        if ($auto && $token !== '' && empty($app['quote_created'])) {
            $price = (float)($app['offer_price'] ?? 0);
            if ($price > 0) {
                $quoteCrm = new CrmApiClient(rtrim($this->crm->getBaseUrl(), '/'), $token, 'x-auth-token');
                $q = $quoteCrm->post("clients/{$crmId}/quotes", [
                    'notes'      => 'Auto-generated on KYC registration (CRM retry). Sales: ' . ($app['retailer_name'] ?? ''),
                    'adminNotes' => 'CRM retry for App #' . $id,
                    'items'      => [['label' => (string)($app['offer_name'] ?? 'Service Plan'), 'price' => $price,
                                      'quantity' => 1, 'unit' => 'Service']],
                ]);
                if (!empty($q['id'])) {
                    $this->store->updateOne('kyc_applications.json', 'id', $id, [
                        'quote_id' => $q['id'], 'quote_created' => true, 'quote_ref' => $q['number'] ?? '',
                        'wa_quote_pending' => true, 'wa_quote_phone' => $app['mobile'] ?? '',
                        'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
                    ]);
                    $quoteCrm->patch("billing/quotes/{$q['id']}/send");
                }
            }
        }

        // The collection the agent recorded now has a customer.
        foreach (($this->store->load('payment_collections.json') ?? []) as $col) {
            if ((int)($col['kyc_app_id'] ?? 0) === $id && empty($col['crm_customer_id']) && !empty($col['id'])) {
                $this->store->updateOne('payment_collections.json', 'id', $col['id'], ['crm_customer_id' => $crmId]);
            }
        }
    }

    private function failed(array $app, string $reason, string $by): array
    {
        $id       = (int)$app['id'];
        $attempts = (int)($app['crm_sync_attempts'] ?? 0) + 1;
        $this->store->updateOne('kyc_applications.json', 'id', $id, [
            'crm_sync_status'       => 'pending',
            'crm_sync_error'        => $reason,
            'crm_sync_attempts'     => $attempts,
            'crm_sync_last_attempt' => date('Y-m-d H:i:s'),
            'crm_sync_by'           => $by,
        ]);
        error_log("[kyc_crm_sync] App #{$id} attempt #{$attempts} failed: {$reason}");
        return $this->result(false, 'failed', null, "Application #{$id} is still not in the CRM: {$reason}");
    }

    /**
     * The create request: the one saved when the form's own attempt failed, or,
     * for an application saved without one, rebuilt from its fields the way
     * KycService builds it. UcrmClientTarget fits either to this uCRM.
     */
    private function payloadFor(array $app): array
    {
        $saved = json_decode((string)($app['crm_sync_payload'] ?? ''), true);
        if (is_array($saved) && $saved !== []) return $saved;

        $contacts = is_array($app['contacts'] ?? null) && $app['contacts'] !== [] ? $app['contacts'] : [[
            'name' => (string)($app['firstname'] ?? ''), 'email' => (string)($app['email'] ?? ''),
            'phone' => (string)($app['mobile'] ?? ''),
        ]];
        return [
            'clientType'     => 1,
            'isLead'         => !empty($app['is_lead']),
            'firstName'      => (string)($app['firstname'] ?? ''),
            'lastName'       => (string)($app['lastname'] ?? ''),
            'street1'        => (string)($app['address_1'] ?? ''),
            'street2'        => (string)($app['address_2'] ?? ''),
            'city'           => ($app['customer_type'] ?? '') === 'Fiber' ? (string)($app['fiber_area'] ?? '') : '',
            'countryId'      => null,
            'organizationId' => UcrmClientTarget::LEGACY_ORGANIZATION,
            'stateId'        => null,
            'note'           => ($app['device_title'] ?? '') . ' / ' . ($app['offer_name'] ?? ''),
            'zipCode'        => '',
            'username'       => (string)($app['username'] ?? ('AUTO' . (int)$app['id'])),
            'contacts'       => $contacts,
            'attributes'     => [
                ['value' => (string)($app['priority'] ?? ''),       'customAttributeId' => KycService::ATTR_PRIORITY],
                ['value' => (string)($app['sales_person'] ?? ''),   'customAttributeId' => KycService::ATTR_SALES_PERSON],
                ['value' => (string)($app['ref'] ?? ''),            'customAttributeId' => KycService::ATTR_REF],
                ['value' => (string)($app['device_id'] ?? ''),      'customAttributeId' => KycService::ATTR_DEVICE_ID],
                ['value' => (string)($app['package_choice'] ?? ''), 'customAttributeId' => KycService::ATTR_PACKAGE],
                ['value' => (string)($app['kitNumber'] ?? ''),      'customAttributeId' => KycService::ATTR_KIT_NUMBER],
                ['value' => (string)($app['kitQty'] ?? ''),         'customAttributeId' => KycService::ATTR_KIT_QTY],
                ['value' => (string)($app['kitUnit'] ?? ''),        'customAttributeId' => KycService::ATTR_KIT_UNIT],
                ['value' => (string)($app['kitName'] ?? ''),        'customAttributeId' => KycService::ATTR_KIT_NAME],
            ],
        ];
    }

    /**
     * uCRM clients on the same number, by its last nine digits: the plugin's
     * index of uCRM clients, then uCRM's own search. These are the lookups the
     * KYC form makes before it saves anything.
     *
     * @return int[]
     */
    private function crmClientsWithPhone(string $mobile): array
    {
        $digits = preg_replace('/[^0-9]/', '', $mobile) ?? '';
        if (strlen($digits) < 9) return [];
        $last9 = substr($digits, -9);
        $tail  = static function (string $p): string {
            $d = preg_replace('/[^0-9]/', '', $p) ?? '';
            return strlen($d) >= 9 ? substr($d, -9) : '';
        };
        $ids = [];

        try {
            if (method_exists($this->store, 'getPdo')) {
                $st = $this->store->getPdo()->prepare('SELECT id FROM client_search_index WHERE phone_norm = ?');
                $st->execute([$last9]);
                foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $cid) $ids[] = (int)$cid;
            }
        } catch (\Throwable $e) { /* that index table is optional */ }

        foreach (($this->store->load('client_search_index.json') ?? []) as $cl) {
            if ($tail((string)($cl['phone'] ?? '')) === $last9) $ids[] = (int)($cl['id'] ?? 0);
        }

        $rows = $this->crm->get('clients?search=' . urlencode($mobile) . '&limit=5');
        if (is_array($rows) && !isset($rows['raw'])) {
            foreach ($rows as $cl) {
                if (!is_array($cl)) continue;
                foreach ((array)($cl['contacts'] ?? []) as $c) {
                    if (is_array($c) && $tail((string)($c['phone'] ?? '')) === $last9) $ids[] = (int)($cl['id'] ?? 0);
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    /**
     * One conditional UPDATE: it succeeds for exactly one caller while the
     * application has no uCRM id and no live claim. A claim older than five
     * minutes belonged to a run that died.
     */
    private function claim(int $id): bool
    {
        if (!method_exists($this->store, 'getPdo')) return true;
        $st = $this->store->getPdo()->prepare(
            "UPDATE kyc_applications
                SET data = json_set(data, '$.crm_sync_claim', :token, '$.crm_sync_claimed_at', :now)
              WHERE id = :id
                AND (json_extract(data, '$.crm_client_id') IS NULL OR json_extract(data, '$.crm_client_id') = '')
                AND (json_extract(data, '$.crm_sync_claim') IS NULL
                     OR json_extract(data, '$.crm_sync_claimed_at') < :stale)"
        );
        $st->execute([
            ':token' => bin2hex(random_bytes(8)),
            ':now'   => date('Y-m-d H:i:s'),
            ':stale' => date('Y-m-d H:i:s', time() - 300),
            ':id'    => $id,
        ]);
        return $st->rowCount() === 1;
    }

    private function target(): UcrmClientTarget
    {
        return $this->target ??= new UcrmClientTarget($this->crm);
    }

    private function result(bool $ok, string $status, ?string $crmId, string $message): array
    {
        return ['ok' => $ok, 'status' => $status, 'crm_client_id' => $crmId, 'message' => $message];
    }
}
