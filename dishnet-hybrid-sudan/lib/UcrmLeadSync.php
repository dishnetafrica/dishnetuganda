<?php
declare(strict_types=1);

/**
 * UcrmLeadSync — put an AI-qualified lead into uCRM, once.
 *
 * Phase 2 of three. The assistant has been writing qualified leads to
 * leads.json since 5.18.3 and nothing has ever carried them into uCRM: the
 * sales team's own system never learned that a conversation had become an
 * opportunity. Everything that reads a lead — assignment, the dashboards, the
 * quotation path — reads the plugin's copy, and uCRM reads none of it.
 *
 * ── What this is NOT ────────────────────────────────────────────────────
 *
 * Not another API client. `CrmApiClient` already talks to uCRM with the
 * credentials uCRM injects, and the client payload here is the one
 * `cron/kyc_crm_sync.php` has been creating real customers with for months.
 * This is the same call from a different trigger.
 *
 * Not a quotation. A lead reaching uCRM does not create, price or send
 * anything to a customer. That is Phase 3 and it has its own approval gate.
 *
 * ── Idempotence, because a queue retries ────────────────────────────────
 *
 * Every path through this is safe to run twice, because EventBus will run it
 * twice whenever uCRM times out after doing the work. In order:
 *
 *   1. the lead already carries a crm_client_id  → PATCH it, never POST
 *   2. uCRM already has exactly one client on that phone → LINK to it and
 *      write the id back, never POST
 *   3. uCRM has MORE THAN ONE on that phone → stop, flag, create nothing
 *   4. nobody has it → POST once, under a lock
 *
 * Case 3 is the one that matters. `DishNetTools::identifyCustomerByPhone`
 * refuses to guess between two customers who share a number, and this inherits
 * that refusal rather than re-deciding it: attaching a conversation to the
 * wrong customer is worse than attaching it to nobody, and a duplicate client
 * is worse than a lead that waited.
 *
 * ── organizationId and countryId ────────────────────────────────────────
 *
 * Read off the clients this install already has, not copied from the KYC
 * payload where they are the literals 2 and null. `tools/org_probe.php` was
 * written because those two numbers are genuinely ambiguous here — the code
 * documents organizations 2 and 7 while the admin screen shows id 1 — and its
 * conclusion was that the clients' own organizationId is the answer that needs
 * no guess. This asks the same question the same way, at run time.
 *
 * When the answer cannot be established, this refuses and says so. It does not
 * fall back to a literal: a lead filed under the wrong company is a lead
 * somebody has to find and move.
 */
final class UcrmLeadSync
{
    /** How many existing clients to read when establishing the defaults. */
    const SAMPLE = 50;

    private $store;
    private array $config;
    private $crm;
    private ?\PDO $pdo;
    /** @var array{org:?int,country:?int,error:string}|null */
    private ?array $defaults = null;

    public function __construct($store, array $config, $crm, ?\PDO $pdo = null)
    {
        $this->store  = $store;
        $this->config = $config;
        $this->crm    = $crm;
        $this->pdo    = $pdo;
    }

    /** Shipping is not enabling. Absent means off, as every AI feature here. */
    public function enabled(): bool
    {
        return filter_var($this->config['ai_crm_lead_sync'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Push one lead into uCRM.
     *
     * @return array{ok:bool,action:string,crm_client_id:?int,reason:string}
     */
    public function syncLead(int $leadId): array
    {
        if (!$this->enabled())          return $this->no('disabled', 'ai_crm_lead_sync is off');
        if (!$this->crm->isConfigured()) return $this->no('skipped', 'uCRM is not configured');

        $lead = $this->lead($leadId);
        if ($lead === null) return $this->no('skipped', 'lead #' . $leadId . ' does not exist');

        $phone = trim((string)($lead['phone'] ?? ''));
        if (LeadMatcher::key($phone) === '') {
            return $this->no('skipped', 'lead #' . $leadId . ' has no usable phone number');
        }

        // 1. Already ours.
        $existingId = (int)($lead['crm_client_id'] ?? 0);
        if ($existingId > 0) {
            $patched = $this->patchClient($existingId, $lead);
            return $patched['ok']
                ? $this->yes('updated', $existingId, $leadId)
                : $this->no('failed', 'uCRM #' . $existingId . ' update failed: ' . $patched['reason']);
        }

        // 2/3. Does uCRM already know this number?
        $match = $this->findByPhone($phone);
        if ($match['status'] === 'ambiguous') {
            // Never guess between two customers sharing a number. The lead
            // stays in the plugin, flagged, for a person to resolve.
            $this->flag($leadId, 'ambiguous_phone', $match['count'] . ' uCRM clients share this number');
            return $this->no('skipped', 'ambiguous: ' . $match['count'] . ' uCRM clients share that number');
        }
        if ($match['status'] === 'error') {
            // Flagged as well as retried. The queue knows this is pending, but
            // the lead is what a person opens, and an unsynced lead with no
            // visible reason is the shape of problem this phase exists to end.
            // Cleared again the moment a sync succeeds.
            $this->flag($leadId, 'crm_unreachable', $match['reason']);
            return $this->no('failed', 'uCRM lookup failed: ' . $match['reason']);
        }
        if ($match['status'] === 'found') {
            $this->writeBack($leadId, $match['id']);
            $patched = $this->patchClient($match['id'], $lead);
            return $patched['ok']
                ? $this->yes('linked', $match['id'], $leadId)
                : $this->no('failed', 'linked to uCRM #' . $match['id'] . ' but update failed: ' . $patched['reason']);
        }

        // 4. Create, once.
        $defaults = $this->defaults();
        if ($defaults['error'] !== '') {
            // Could not ASK. Retryable, and it must be: reporting "cannot
            // establish organizationId" for a uCRM that is merely restarting
            // would drop the lead out of the queue for good.
            $this->flag($leadId, 'crm_unreachable', $defaults['error']);
            return $this->no('failed', 'uCRM unreachable while resolving defaults: ' . $defaults['error']);
        }
        if ($defaults['org'] === null) {
            return $this->no('skipped',
                'cannot establish organizationId from existing clients — set '
                . 'ucrm_lead_organization_id, or run tools/org_probe.php to see why');
        }

        $created = null;
        // The lock is the second half of idempotence: two workers that both
        // read "no crm_client_id" must not both POST.
        $this->store->withLock('leads.json', function (array $leads) use (
            $leadId, $lead, $defaults, &$created
        ): array {
            foreach ($leads as $i => $l) {
                if ((int)($l['id'] ?? 0) !== $leadId) continue;
                // Re-read under the lock: another worker may have created it
                // between our check above and this line.
                if ((int)($l['crm_client_id'] ?? 0) > 0) {
                    $created = ['ok' => true, 'id' => (int)$l['crm_client_id'], 'raced' => true];
                    return ['records' => $leads, 'result' => true];
                }
                $res = $this->postClient($lead, $defaults);
                if (!$res['ok']) { $created = $res; return ['records' => $leads, 'result' => false]; }
                $leads[$i]['crm_client_id'] = $res['id'];
                $leads[$i]['crm_synced_at'] = gmdate('Y-m-d H:i:s');
                unset($leads[$i]['crm_sync_error'], $leads[$i]['crm_sync_flag']);
                $created = $res;
                return ['records' => $leads, 'result' => true];
            }
            $created = ['ok' => false, 'id' => null, 'reason' => 'lead vanished under the lock'];
            return ['records' => $leads, 'result' => false];
        });

        if ($created === null || empty($created['ok'])) {
            $reason = (string)($created['reason'] ?? 'unknown');
            $this->flag($leadId, 'create_failed', $reason);
            return $this->no('failed', 'uCRM create failed: ' . $reason);
        }
        if (!empty($created['raced'])) {
            return $this->yes('updated', (int)$created['id'], $leadId);
        }

        // GPS is a second call: uCRM takes it on the client, and the create
        // payload is the one KYC proved. Same mechanism the support app has
        // used since it started capturing coordinates on site visits.
        $this->patchClient((int)$created['id'], $lead);
        return $this->yes('created', (int)$created['id'], $leadId);
    }

    // ── uCRM calls ──────────────────────────────────────────────────────

    /**
     * @return array{status:string,id:int,count:int,reason:string}
     */
    private function findByPhone(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 9) return ['status' => 'none', 'id' => 0, 'count' => 0, 'reason' => ''];
        $needle = substr($digits, -9);

        // CrmApiClient::get() returns null for a transport or HTTP failure and
        // [] for a genuine empty result. Treating those alike is how an outage
        // becomes "nobody has this number" — and then a duplicate client the
        // moment uCRM comes back. They are kept apart deliberately.
        try {
            $rows = $this->crm->get('clients?phone=' . rawurlencode($needle) . '&limit=10');
            if ($rows === null) {
                $rows = $this->crm->get('clients?search=' . rawurlencode($needle) . '&limit=10');
                if ($rows === null) {
                    return ['status' => 'error', 'id' => 0, 'count' => 0,
                            'reason' => $this->lastErrorText()];
                }
            } elseif ($rows === []) {
                $alt = $this->crm->get('clients?search=' . rawurlencode($needle) . '&limit=10');
                if (is_array($alt) && $alt !== []) $rows = $alt;
            }
        } catch (\Throwable $e) {
            return ['status' => 'error', 'id' => 0, 'count' => 0, 'reason' => $e->getMessage()];
        }
        if (!is_array($rows)) return ['status' => 'none', 'id' => 0, 'count' => 0, 'reason' => ''];

        // uCRM's search is fuzzy. Confirm the digits actually match a contact
        // rather than trusting the endpoint to have meant it.
        $hits = [];
        foreach ($rows as $r) {
            foreach ((array)($r['contacts'] ?? []) as $c) {
                $stored = preg_replace('/\D+/', '', (string)($c['phone'] ?? '')) ?? '';
                if ($stored !== '' && substr($stored, -9) === $needle) {
                    $hits[(int)($r['id'] ?? 0)] = true;
                    break;
                }
            }
        }
        unset($hits[0]);
        if (count($hits) > 1) {
            return ['status' => 'ambiguous', 'id' => 0, 'count' => count($hits), 'reason' => ''];
        }
        if (count($hits) === 1) {
            return ['status' => 'found', 'id' => (int)array_key_first($hits), 'count' => 1, 'reason' => ''];
        }
        return ['status' => 'none', 'id' => 0, 'count' => 0, 'reason' => ''];
    }

    /** @return array{ok:bool,id:?int,reason:string} */
    private function postClient(array $lead, array $defaults): array
    {
        try {
            $res = $this->crm->post('clients', $this->payload($lead, $defaults));
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'reason' => $e->getMessage()];
        }
        if (is_array($res) && !empty($res['id'])) {
            return ['ok' => true, 'id' => (int)$res['id'], 'reason' => ''];
        }
        $err = method_exists($this->crm, 'getLastError') ? $this->crm->getLastError() : [];
        return ['ok' => false, 'id' => null, 'reason' => json_encode($err) ?: 'no id returned'];
    }

    /**
     * Coordinates and the running note, onto an existing client.
     *
     * Deliberately narrow: this never rewrites a name, an address or a
     * contact. A salesperson who corrected a record in uCRM must not have it
     * undone by the next message the customer sends — the same rule the
     * plugin's own merge() has always followed.
     *
     * @return array{ok:bool,reason:string}
     */
    private function patchClient(int $clientId, array $lead): array
    {
        $patch = [];
        $lat = $lead['location_lat'] ?? null;
        $lng = $lead['location_lng'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            if (!class_exists('WaLocation')) require_once __DIR__ . '/WaLocation.php';
            if (\WaLocation::isSane((float)$lat, (float)$lng)) {
                $patch['gpsLat'] = (float)$lat;
                $patch['gpsLon'] = (float)$lng;
            }
        }
        if ($patch === []) return ['ok' => true, 'reason' => 'nothing to update'];

        try {
            $res = $this->crm->patch('clients/' . $clientId, $patch);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
        if ($res === null) {
            $err = method_exists($this->crm, 'getLastError') ? $this->crm->getLastError() : [];
            return ['ok' => false, 'reason' => json_encode($err) ?: 'patch returned nothing'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * The client record, shaped exactly as cron/kyc_crm_sync.php shapes it.
     *
     * isLead is true: this is somebody who asked, not somebody who bought.
     * uCRM converts a lead to a client on its own when one is invoiced, and
     * CrmApiClient::post() already does that conversion before a payment.
     */
    private function payload(array $lead, array $defaults): array
    {
        [$first, $last] = $this->splitName((string)($lead['customer_name'] ?? ''), (string)($lead['phone'] ?? ''));

        $payload = [
            'clientType'     => 1,
            'isLead'         => true,
            'firstName'      => $first,
            'lastName'       => $last,
            'organizationId' => $defaults['org'],
            'note'           => $this->note($lead),
            'contacts'       => [[
                'name'  => trim($first . ' ' . $last),
                'phone' => (string)($lead['phone'] ?? ''),
            ]],
        ];
        if (!empty($lead['company'])) $payload['companyName'] = (string)$lead['company'];
        if (!empty($lead['email'])) {
            $payload['contacts'][0]['email'] = (string)$lead['email'];
        }
        // A typed place goes in street1 — it is what the customer said, and
        // uCRM has nowhere better for "Mukono off Kayunga road".
        if (!empty($lead['location'])) $payload['street1'] = (string)$lead['location'];
        if ($defaults['country'] !== null) $payload['countryId'] = $defaults['country'];

        return $payload;
    }

    /** What a salesperson opening the record needs to know, and nothing else. */
    private function note(array $lead): string
    {
        $bits = ['Created by the WhatsApp assistant.'];
        foreach ([
            'requirement'          => 'Wants',
            'customer_type'        => 'Type',
            'location'             => 'Stated location',
            'recommended_plan'     => 'Discussed plan',
            'recommended_hardware' => 'Discussed hardware',
            'existing_internet'    => 'Currently using',
            'users_devices'        => 'Users/devices',
        ] as $k => $label) {
            $v = trim((string)($lead[$k] ?? ''));
            if ($v !== '') $bits[] = $label . ': ' . $v;
        }
        if (is_numeric($lead['location_lat'] ?? null) && is_numeric($lead['location_lng'] ?? null)) {
            $bits[] = 'Location pin received — coordinates are on the client record.';
        }
        if (!empty($lead['quote_requested'])) $bits[] = 'ASKED FOR A QUOTATION.';
        $summary = trim((string)($lead['ai_summary'] ?? ''));
        if ($summary !== '') $bits[] = "\n" . $summary;
        return implode("\n", $bits);
    }

    /**
     * uCRM wants two names. A customer who gave one gets it as the first, and
     * one who gave none is filed under their number — never under an invented
     * surname, which is what a salesperson would then greet them by.
     *
     * @return array{0:string,1:string}
     */
    private function splitName(string $name, string $phone): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || $name === $phone) return [$phone !== '' ? $phone : 'WhatsApp lead', ''];
        $parts = explode(' ', $name);
        if (count($parts) === 1) return [$parts[0], ''];
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    /**
     * organizationId and countryId, from the clients this install has.
     *
     * The commonest value among real clients, because that is what "which
     * company is this" actually means here. Config overrides it; nothing
     * guesses it.
     *
     * @return array{org:?int,country:?int,error:string}
     */
    private function defaults(): array
    {
        if ($this->defaults !== null) return $this->defaults;

        $org     = (int)($this->config['ucrm_lead_organization_id'] ?? 0) ?: null;
        $country = (int)($this->config['ucrm_lead_country_id'] ?? 0) ?: null;

        $error = '';
        if ($org === null || $country === null) {
            $rows = null;
            try {
                $rows = $this->crm->get('clients?limit=' . self::SAMPLE);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            // null is uCRM being unreachable, not uCRM having no clients. The
            // difference decides whether the caller retries or gives up, so it
            // is carried rather than flattened.
            if ($rows === null && $error === '') $error = $this->lastErrorText();

            $orgs = []; $countries = [];
            foreach ((array)($rows ?: []) as $c) {
                $o = (int)($c['organizationId'] ?? 0);
                if ($o > 0) $orgs[$o] = ($orgs[$o] ?? 0) + 1;
                $n = (int)($c['countryId'] ?? 0);
                if ($n > 0) $countries[$n] = ($countries[$n] ?? 0) + 1;
            }
            if ($org === null && $orgs !== []) {
                arsort($orgs);
                $org = (int)array_key_first($orgs);
            }
            if ($country === null && $countries !== []) {
                arsort($countries);
                $country = (int)array_key_first($countries);
            }
        }

        $resolved = ['org' => $org, 'country' => $country, 'error' => $error];
        // A failed read is never cached: the next attempt must ask again.
        if ($error === '') $this->defaults = $resolved;
        return $resolved;
    }

    private function lastErrorText(): string
    {
        if (!method_exists($this->crm, 'getLastError')) return 'unreachable';
        $e = $this->crm->getLastError();
        return $e ? (string)json_encode($e) : 'unreachable';
    }

    // ── the plugin's own record ─────────────────────────────────────────

    private function lead(int $leadId): ?array
    {
        foreach ((array)($this->store->load('leads.json') ?? []) as $l) {
            if ((int)($l['id'] ?? 0) === $leadId) return $l;
        }
        return null;
    }

    private function writeBack(int $leadId, int $crmId): void
    {
        $this->store->withLock('leads.json', function (array $leads) use ($leadId, $crmId): array {
            foreach ($leads as $i => $l) {
                if ((int)($l['id'] ?? 0) !== $leadId) continue;
                $leads[$i]['crm_client_id'] = $crmId;
                $leads[$i]['crm_synced_at'] = gmdate('Y-m-d H:i:s');
                unset($leads[$i]['crm_sync_error'], $leads[$i]['crm_sync_flag']);
                break;
            }
            return ['records' => $leads, 'result' => true];
        });
    }

    /** A failure a person has to look at, written where they will find it. */
    private function flag(int $leadId, string $flag, string $why): void
    {
        $this->store->withLock('leads.json', function (array $leads) use ($leadId, $flag, $why): array {
            foreach ($leads as $i => $l) {
                if ((int)($l['id'] ?? 0) !== $leadId) continue;
                $leads[$i]['crm_sync_flag']  = $flag;
                $leads[$i]['crm_sync_error'] = $why;
                $leads[$i]['crm_sync_at']    = gmdate('Y-m-d H:i:s');
                break;
            }
            return ['records' => $leads, 'result' => true];
        });
    }

    private function audit(string $action, int $leadId, ?int $crmId, string $reason): void
    {
        try {
            $this->store->withLock('ai_crm_actions.json', function (array $rows) use (
                $action, $leadId, $crmId, $reason
            ): array {
                $rows[] = [
                    'at'            => gmdate('Y-m-d H:i:s'),
                    'action'        => 'crm_sync_' . $action,
                    'lead_id'       => $leadId,
                    'crm_client_id' => $crmId,
                    'reason'        => $reason,
                ];
                if (count($rows) > 2000) $rows = array_slice($rows, -2000);
                return ['records' => $rows, 'result' => true];
            });
        } catch (\Throwable $e) { /* an audit line must never cost the sync */ }
    }

    private function yes(string $action, int $crmId, int $leadId): array
    {
        $this->audit($action, $leadId, $crmId, '');
        return ['ok' => true, 'action' => $action, 'crm_client_id' => $crmId, 'reason' => ''];
    }

    private function no(string $action, string $reason): array
    {
        return ['ok' => false, 'action' => $action, 'crm_client_id' => null, 'reason' => $reason];
    }
}
