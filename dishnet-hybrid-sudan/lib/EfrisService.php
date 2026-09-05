<?php
declare(strict_types=1);

require_once __DIR__ . '/EfrisStore.php';
require_once __DIR__ . '/EfrisInvoiceMapper.php';
require_once __DIR__ . '/EfrisClient.php';
require_once __DIR__ . '/CrmApiClient.php';

/**
 * EfrisService — the one place a uCRM invoice becomes an EFRIS submission.
 *
 * Status machine (stored in efris_transactions.status; absence of a row is
 * the implicit NOT_SUBMITTED):
 *
 *   PENDING     claimed, being prepared/sent
 *   SUBMITTED   sent, awaiting a definitive answer (async/pending response)
 *   FISCALISED  EFRIS confirmed — fdn/verification/qr are EFRIS's own values
 *   REJECTED    EFRIS said no (business rejection); retry only after data fix
 *   ERROR       validation failed locally, or transport failed (retryable)
 *   NEEDS_ADJUSTMENT  a fiscalised invoice was edited in uCRM afterwards
 *   CANCELLED / CREDITED / DEBITED  reserved for the Phase-2 adjustment flows
 *
 * Hard rules enforced here, not in the UI:
 *   - environment disabled/production → nothing is ever sent (Phase 1);
 *   - a FISCALISED row is terminal for KIND_INVOICE — resubmission returns
 *     the stored fiscal record (idempotency by DB constraint);
 *   - fiscal values are copied VERBATIM from the EFRIS response; this class
 *     cannot invent an FDN because it never composes one.
 */
class EfrisService
{
    public const ST_PENDING    = 'PENDING';
    public const ST_SUBMITTED  = 'SUBMITTED';
    public const ST_FISCALISED = 'FISCALISED';
    public const ST_REJECTED   = 'REJECTED';
    public const ST_ERROR      = 'ERROR';
    public const ST_NEEDS_ADJUSTMENT = 'NEEDS_ADJUSTMENT';

    private $store;              // SqliteStore (for JSON maps + pdo)
    private array $config;
    private EfrisStore $tx;
    private CrmApiClient $crm;
    private EfrisClient $client;
    private string $dataDir;

    public function __construct($store, array $config, string $dataDir,
                                ?CrmApiClient $crm = null, ?EfrisClient $client = null)
    {
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = $dataDir;
        $this->tx      = new EfrisStore($store->getPdo());
        $this->crm     = $crm ?: CrmApiClient::fromUcrm(dirname(__DIR__), $config);
        $this->client  = $client ?: new EfrisClient($config);
    }

    public function transactions(): EfrisStore { return $this->tx; }
    public function environment(): string      { return $this->client->environment(); }

    public function autoSubmitEnabled(): bool
    {
        return PluginConfig::toBool($this->config['efris_auto_submit'] ?? false)
            && $this->client->environment() === EfrisClient::ENV_TEST;
    }

    /** Operator-maintained maps (edited in the EFRIS admin tab). */
    public function commodityMap(): array
    {
        $rows = $this->store->load('efris_commodity_map.json');
        $map = [];
        foreach ((array)$rows as $r) {
            if (!empty($r['item']) && isset($r['code'])) $map[strtolower((string)$r['item'])] = (string)$r['code'];
        }
        return $map;
    }

    public function taxMap(): array
    {
        $rows = $this->store->load('efris_tax_map.json');
        $map = [];
        foreach ((array)$rows as $r) {
            if (!empty($r['tax']) && !empty($r['category'])) $map[strtolower((string)$r['tax'])] = (string)$r['category'];
        }
        return $map;
    }

    /**
     * uCRM's tax registry (GET /taxes): id => name + rate. Probe-confirmed:
     * invoice items reference taxes by tax1Id only, so rates come from here —
     * cached for an hour, and a fetch failure degrades to the stale copy.
     */
    public function taxRegistry(): array
    {
        $cached = $this->store->load('efris_tax_registry.json');
        $fresh  = is_array($cached)
               && (int)($cached['fetched_at'] ?? 0) > time() - 3600
               && is_array($cached['taxes'] ?? null);
        if (!$fresh && $this->crm->isConfigured()) {
            $list = $this->crm->get('taxes');
            if (is_array($list)) {
                $cached = ['fetched_at' => time(), 'taxes' => []];
                foreach ($list as $t) {
                    if (!is_array($t) || empty($t['id'])) continue;
                    $cached['taxes'][(string)(int)$t['id']] = [
                        'name' => (string)($t['name'] ?? ''),
                        'rate' => isset($t['rate']) ? (float)$t['rate'] : null,
                    ];
                }
                $this->store->save('efris_tax_registry.json', $cached);
            }
        }
        $out = [];
        foreach ((array)($cached['taxes'] ?? []) as $id => $t) $out[(int)$id] = $t;
        return $out;
    }

    /** Map without sending — the admin "preview / validate" path and tests. */
    public function preview(int $ucrmInvoiceId): array
    {
        [$invoice, $client, $err] = $this->fetch($ucrmInvoiceId);
        if ($err !== '') return ['ok' => false, 'errors' => [$err], 'warnings' => [], 'model' => []];
        $mapper = new EfrisInvoiceMapper($this->config, $this->commodityMap(), $this->taxMap(), $this->taxRegistry());
        return $mapper->map($invoice, $client);
    }

    /**
     * Submit one invoice. $source: manual|webhook|scan (audit trail).
     * @return array{ok:bool, status:string, message:string, tx:?array, duplicate?:bool}
     */
    public function submitInvoice(int $ucrmInvoiceId, string $source = 'manual', bool $retry = false): array
    {
        $env = $this->client->environment();
        if ($env === EfrisClient::ENV_DISABLED) {
            return ['ok' => false, 'status' => 'DISABLED',
                    'message' => 'EFRIS is disabled — set efris_environment=test to use the test flow.', 'tx' => null];
        }
        if ($env === EfrisClient::ENV_PRODUCTION) {
            return ['ok' => false, 'status' => 'REFUSED',
                    'message' => $this->client->refusalReason(), 'tx' => null];
        }

        [$invoice, $client, $err] = $this->fetch($ucrmInvoiceId);
        if ($err !== '') {
            return ['ok' => false, 'status' => self::ST_ERROR, 'message' => $err, 'tx' => null];
        }

        $number = trim((string)($invoice['number'] ?? ''));
        $extra = [
            'client_id'   => (int)($invoice['clientId'] ?? 0),
            'client_name' => trim(((string)($client['firstName'] ?? '')) . ' ' . ((string)($client['lastName'] ?? '')))
                             ?: (string)($client['companyName'] ?? ''),
            'amount'      => (float)($invoice['total'] ?? 0),
            'currency'    => (string)($invoice['currencyCode'] ?? ''),
        ];

        [$row, $createdNow] = $this->tx->beginSubmission($ucrmInvoiceId, $number, $env, $extra);
        if ($row === null) {
            return ['ok' => false, 'status' => self::ST_ERROR, 'message' => 'Could not claim the transaction row', 'tx' => null];
        }

        // ── Idempotency: the DB row decides, not the caller ──
        if (!$createdNow) {
            if ($row['status'] === self::ST_FISCALISED) {
                $this->log("invoice {$number}: already FISCALISED (fdn={$row['fdn']}) — duplicate submit ignored ({$source})");
                return ['ok' => true, 'status' => self::ST_FISCALISED, 'duplicate' => true,
                        'message' => 'Already fiscalised — returning the stored fiscal record.', 'tx' => $row];
            }
            if (in_array($row['status'], [self::ST_PENDING, self::ST_SUBMITTED], true) && !$retry) {
                $ageOk = (strtotime((string)$row['updated_at']) ?: 0) > time() - 300;
                if ($ageOk) {
                    return ['ok' => false, 'status' => $row['status'], 'duplicate' => true,
                            'message' => 'A submission for this invoice is already in flight.', 'tx' => $row];
                }
            }
            if ($row['status'] === self::ST_REJECTED && !$retry) {
                return ['ok' => false, 'status' => self::ST_REJECTED,
                        'message' => 'EFRIS rejected this invoice: ' . (string)$row['response_message']
                                   . ' — fix the data, then use Retry.', 'tx' => $row];
            }
            $this->tx->update((int)$row['id'], ['status' => self::ST_PENDING, 'environment' => $env]);
            $this->tx->bumpRetry((int)$row['id']);
        }
        $txId = (int)$row['id'];

        // ── Validate + map ──
        $mapper = new EfrisInvoiceMapper($this->config, $this->commodityMap(), $this->taxMap(), $this->taxRegistry());
        $m = $mapper->map($invoice, $client);
        if (!$m['ok']) {
            $msg = 'Validation failed: ' . implode('; ', $m['errors']);
            $this->tx->update($txId, ['status' => self::ST_ERROR, 'response_message' => $msg]);
            $this->log("invoice {$number}: {$msg}");
            return ['ok' => false, 'status' => self::ST_ERROR, 'message' => $msg, 'tx' => $this->tx->get($txId)];
        }

        // ── Send ──
        $this->tx->update($txId, [
            'status'          => self::ST_SUBMITTED,
            'submitted_at'    => gmdate('Y-m-d H:i:s'),
            'request_payload' => json_encode($m['model']),
        ]);
        $r = $this->client->submitInvoice($m['model']);
        $this->tx->update($txId, [
            'request_id'       => $r['request_id'],
            'response_payload' => $r['raw'] !== '' ? $r['raw'] : json_encode($r['envelope']),
        ]);

        // ── Interpret — fiscal values come ONLY from the response ──
        if ($r['ok'] && is_array($r['content'])) {
            $c = $r['content'];
            $fdn = trim((string)($c['fdn'] ?? $c['invoiceNo'] ?? ''));
            if ($fdn === '') {
                $msg = 'EFRIS said success but returned no fiscal document number — NOT marked fiscalised';
                $this->tx->update($txId, ['status' => self::ST_ERROR, 'response_message' => $msg]);
                $this->log("invoice {$number}: {$msg}");
                return ['ok' => false, 'status' => self::ST_ERROR, 'message' => $msg, 'tx' => $this->tx->get($txId)];
            }
            $this->tx->update($txId, [
                'status'            => self::ST_FISCALISED,
                'fdn'               => $fdn,
                'verification_code' => trim((string)($c['verificationCode'] ?? '')),
                'qr_data'           => trim((string)($c['qrCode'] ?? $c['qrData'] ?? '')),
                'efris_reference'   => trim((string)($c['referenceNo'] ?? $c['efrisReference'] ?? '')),
                'fiscalised_at'     => trim((string)($c['fiscalisedAt'] ?? $c['issuedDate'] ?? gmdate('Y-m-d H:i:s'))),
                'response_code'     => '00',
                'response_message'  => 'OK',
            ]);
            $this->log("invoice {$number}: FISCALISED fdn={$fdn} env={$env} source={$source}");
            return ['ok' => true, 'status' => self::ST_FISCALISED, 'message' => 'Fiscalised.',
                    'tx' => $this->tx->get($txId)];
        }

        // Pending/processing answers stay SUBMITTED for the scanner to re-check.
        $rc = (string)($r['envelope']['returnStateInfo']['returnCode'] ?? '');
        if ($rc === '01') {
            $this->tx->update($txId, ['status' => self::ST_SUBMITTED,
                'response_code' => $rc, 'response_message' => $r['error'] ?: 'Processing']);
            return ['ok' => false, 'status' => self::ST_SUBMITTED,
                    'message' => 'EFRIS is still processing — will re-check.', 'tx' => $this->tx->get($txId)];
        }

        $isRejection = $r['http'] > 0 && $r['http'] < 500 && $r['envelope'] !== null && $rc !== '';
        $status = $isRejection ? self::ST_REJECTED : self::ST_ERROR;
        $this->tx->update($txId, ['status' => $status,
            'response_code' => $rc !== '' ? $rc : (string)$r['http'],
            'response_message' => $r['error']]);
        $this->log("invoice {$number}: {$status} — {$r['error']}");
        return ['ok' => false, 'status' => $status, 'message' => $r['error'], 'tx' => $this->tx->get($txId)];
    }

    /** invoice.edit on a fiscalised invoice: flag, never auto-resubmit. */
    public function flagAdjustmentNeeded(int $ucrmInvoiceId, string $reason): bool
    {
        $row = $this->tx->find($ucrmInvoiceId);
        if ($row === null || $row['status'] !== self::ST_FISCALISED) return false;
        $this->tx->update((int)$row['id'], [
            'status'           => self::ST_NEEDS_ADJUSTMENT,
            'response_message' => 'Invoice edited in uCRM after fiscalisation: ' . $reason
                                . ' — issue a credit/debit note (Phase 2 flow), do not edit fiscal history.',
        ]);
        $this->log("invoice {$row['invoice_number']}: NEEDS_ADJUSTMENT — {$reason}");
        return true;
    }

    /** Invoices eligible for automatic submission (approved, has number). */
    public function eligible(array $invoice): bool
    {
        $status = (int)($invoice['status'] ?? -1);
        return in_array($status, [1, 2, 3], true)
            && trim((string)($invoice['number'] ?? '')) !== '';
    }

    private function fetch(int $ucrmInvoiceId): array
    {
        if ($ucrmInvoiceId <= 0) return [[], [], 'No invoice id'];
        if (!$this->crm->isConfigured()) return [[], [], 'uCRM API is not configured'];
        $invoice = $this->crm->get("invoices/{$ucrmInvoiceId}")
                ?? $this->crm->get("billing/invoices/{$ucrmInvoiceId}");
        if (!is_array($invoice) || empty($invoice['id'])) {
            return [[], [], "Invoice {$ucrmInvoiceId} not found in uCRM"];
        }
        $clientId = (int)($invoice['clientId'] ?? 0);
        $client = $clientId > 0 ? ($this->crm->get("clients/{$clientId}") ?? []) : [];
        return [$invoice, is_array($client) ? $client : [], ''];
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->dataDir . '/efris.log',
            '[' . gmdate('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
