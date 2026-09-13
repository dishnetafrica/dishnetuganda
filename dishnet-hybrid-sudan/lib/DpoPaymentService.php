<?php
declare(strict_types=1);

require_once __DIR__ . '/DpoClient.php';
require_once __DIR__ . '/DpoPaymentStore.php';

/**
 * DpoPaymentService — the only thing that can settle a DPO payment.
 *
 * ── ONE DOOR ────────────────────────────────────────────────────────────
 *
 * The browser return, DPO's server push and the reconcile cron all call
 * verifyAndSettle(). It takes a REFERENCE, never a result — so nothing that
 * arrives over the wire can assert that a payment succeeded. It goes and asks
 * DPO. DPO's push carries no signature, and that endpoint is public: a forged
 * <Result>000</Result> would otherwise clear an invoice for free.
 *
 * ── WHAT SETTLING MEANS HERE ────────────────────────────────────────────
 *
 * It means creating a PAYMENT in uCRM and letting uCRM apply it. We never
 * write an invoice status. That is what keeps one opinion about what a
 * customer owes.
 *
 * ── THE FOUR REFUSALS ───────────────────────────────────────────────────
 *
 * Money can arrive and still not be settled. Each of these quarantines
 * instead, because guessing costs more than a person looking:
 *
 *   · DPO paid a different amount than we asked for  (no partial payments)
 *   · DPO paid in a different currency               (never convert)
 *   · DPO's CompanyRef is not our reference          (wrong transaction)
 *   · DPO did not tell us the amount at all          (unconfirmed ≠ agreed)
 *
 * And one refusal that is a configuration fault, not a payment fault: with no
 * uCRM payment method configured we do NOT fall back to Cash. Cash feeds agent
 * cash reconciliation, so that fallback would invent money an agent must hand
 * over.
 */
final class DpoPaymentService
{
    /** uCRM invoice status the plugin already treats as paid (portal_data.php). */
    const UCRM_STATUS_PAID = 4;

    private DpoPaymentStore $store;
    private DpoClient       $dpo;
    /** @var object a CrmApiClient, or anything with get()/createPaymentSafe()/getLastError() */
    private $crm;
    private array $cfg;
    /** @var callable|null fn(string $event, string $detail): void */
    private $log;

    public function __construct(DpoPaymentStore $store, DpoClient $dpo, $crm,
                                array $cfg, ?callable $log = null)
    {
        $this->store = $store;
        $this->dpo   = $dpo;
        $this->crm   = $crm;
        $this->cfg   = $cfg;
        $this->log   = $log;
    }

    /** The kill switch. Off blocks NEW attempts only — settlement keeps working. */
    public function isEnabled(): bool
    {
        $v = $this->cfg['dpo_enabled'] ?? false;
        return $v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'on';
    }

    public function environment(): string
    {
        return ((string)($this->cfg['dpo_environment'] ?? 'test')) === 'live' ? 'live' : 'test';
    }

    // ── Initiating ──────────────────────────────────────────────────────

    /**
     * @param int   $clientId from the JWT claim — NEVER from a request body
     * @param int   $invoiceId
     * @param array $customer first, last, email, phone, dial_code, country
     * @return array{ok:bool, code:string, error:string, reference:string,
     *                checkout_url:string, amount:float, currency:string, reused:bool}
     */
    public function initiate(int $clientId, int $invoiceId, array $customer = []): array
    {
        if (!$this->isEnabled()) {
            return self::no('DISABLED', 'Online payment is temporarily unavailable.');
        }
        if ($clientId <= 0 || $invoiceId <= 0) {
            return self::no('BADREQUEST', 'Invoice not specified.');
        }

        // LIVE, not the portal cache. The cache is right for a list and can be
        // hours stale; in that window the customer may have paid an agent.
        $inv = $this->crm->get('invoices/' . $invoiceId);
        if (!is_array($inv) || empty($inv['id'])) {
            return self::no('NOTFOUND', 'That invoice could not be found.');
        }

        // Ownership from the claim, not from anything the caller sent.
        if ((int)($inv['clientId'] ?? 0) !== $clientId) {
            return self::no('FORBIDDEN', 'That invoice is not on your account.');
        }

        $status = (int)($inv['status'] ?? 0);
        $blocked = array_map('intval', (array)($this->cfg['dpo_unpayable_statuses'] ?? []));
        if (in_array($status, $blocked, true)) {
            return self::no('NOTPAYABLE', 'This invoice cannot be paid online.');
        }

        $total   = (float)($inv['total'] ?? 0);
        $paid    = (float)($inv['amountPaid'] ?? 0);
        $payable = round($total - $paid, 2);
        if ($status === self::UCRM_STATUS_PAID || $payable <= 0) {
            return self::no('SETTLED', 'This invoice is already paid.');
        }

        $currency = (string)($inv['currencyCode'] ?? '');
        if ($currency === '') {
            return self::no('NOCURRENCY', 'This invoice has no currency recorded.');
        }
        // Never convert. A currency DPO will not take is a refusal, not a
        // conversion — one currency per row, decided long before DPO existed.
        $allowed = array_filter(array_map('strtoupper',
            array_map('strval', (array)($this->cfg['dpo_currencies'] ?? []))));
        if ($allowed !== [] && !in_array(strtoupper($currency), $allowed, true)) {
            return self::no('CURRENCY', 'Online payment is not available for ' . $currency . ' invoices.');
        }

        // One live attempt per invoice. A second Pay Now reuses the first.
        $open = $this->store->openForInvoice($invoiceId);
        if ($open !== null) {
            $expired = !empty($open['attempt_expires_at'])
                    && strtotime((string)$open['attempt_expires_at']) < time();
            if (!$expired && (string)$open['checkout_url'] !== ''
                && round((float)$open['amount'], 2) === $payable) {
                $this->store->event((int)$open['id'], 'initiated',
                    'reused the open attempt rather than opening a rival one', 'portal');
                return ['ok' => true, 'code' => 'REUSED', 'error' => '',
                        'reference' => (string)$open['reference'],
                        'checkout_url' => (string)$open['checkout_url'],
                        'amount' => (float)$open['amount'], 'currency' => (string)$open['currency'],
                        'reused' => true];
            }
            // Stale, or the outstanding amount has moved since. Close it so the
            // partial index lets a correct attempt through.
            $this->store->update((string)$open['reference'], [
                'status' => 'EXPIRED',
                'failure_reason' => $expired ? 'attempt expired' : 'outstanding amount changed',
            ]);
            $this->store->event((int)$open['id'], 'expired',
                $expired ? 'the attempt passed its time limit' : 'the invoice outstanding changed');
        }

        $ptl       = max(0, (int)($this->cfg['dpo_ptl'] ?? 30));
        $reference = self::reference($invoiceId, $clientId);
        $id = $this->store->create([
            'reference' => $reference, 'crm_client_id' => $clientId,
            'crm_invoice_id' => $invoiceId,
            'invoice_number' => (string)($inv['number'] ?? ('INV-' . $invoiceId)),
            'amount' => $payable, 'currency' => $currency,
            'environment' => $this->environment(),
            'attempt_expires_at' => $ptl > 0
                ? gmdate('Y-m-d H:i:s', time() + $ptl * 60) : null,
        ]);
        $this->store->event($id, 'initiated',
            sprintf('%s %s on invoice %s', number_format($payable, 2), $currency,
                    (string)($inv['number'] ?? $invoiceId)), 'portal');

        $r = $this->dpo->createToken([
            'reference'   => $reference,
            'amount'      => $payable,
            'currency'    => $currency,
            'description' => self::describe($inv),
            'customer_first'     => (string)($customer['first'] ?? ''),
            'customer_last'      => (string)($customer['last'] ?? ''),
            'customer_email'     => (string)($customer['email'] ?? ''),
            'customer_phone'     => (string)($customer['phone'] ?? ''),
            'customer_dial_code' => (string)($customer['dial_code'] ?? ''),
            'customer_country'   => (string)($customer['country'] ?? ''),
            'redirect_url'       => (string)($this->cfg['dpo_return_url'] ?? ''),
            'back_url'           => (string)($this->cfg['dpo_back_url'] ?? ''),
        ]);

        if (!$r['ok']) {
            // 940 means DPO already holds this reference AND it is paid. Never
            // charge again for it — reconcile instead.
            if ($r['code'] === '940') {
                $this->store->update($reference, [
                    'status' => 'PENDING', 'dpo_result' => '940',
                    'dpo_result_text' => $r['message'],
                    'failure_reason' => 'DPO reports this reference already paid — reconciling',
                ]);
                $this->store->event($id, 'verify_requested',
                    'DPO answered 940 at create: the reference is already paid, so it is '
                  . 'reconciled rather than re-charged');
                return self::no('ALREADYPAID',
                    'This payment has already been made. It will be confirmed shortly.');
            }
            $this->store->update($reference, [
                'status' => 'FAILED', 'dpo_result' => $r['code'],
                'dpo_result_text' => $r['message'], 'failure_reason' => $r['message'],
            ]);
            $this->store->event($id, 'verify_failed', 'createToken ' . $r['code'] . ': ' . $r['message']);
            $this->note('dpo_create_failed', $reference . ' ' . $r['code'] . ' ' . $r['message']);
            return self::no('CREATE_' . $r['code'], 'Payment could not be started. Please try again.');
        }

        $url = $this->dpo->checkoutUrl($r['trans_token']);
        $this->store->update($reference, [
            'status' => 'REDIRECTED', 'dpo_trans_token' => $r['trans_token'],
            'dpo_trans_ref' => $r['trans_ref'], 'dpo_result' => $r['code'],
            'dpo_result_text' => $r['message'], 'checkout_url' => $url,
        ]);
        $this->store->event($id, 'token_created', 'DPO ref ' . $r['trans_ref']);
        $this->store->event($id, 'redirected', 'customer sent to DPO hosted checkout');

        return ['ok' => true, 'code' => '000', 'error' => '', 'reference' => $reference,
                'checkout_url' => $url, 'amount' => $payable, 'currency' => $currency,
                'reused' => false];
    }

    // ── Settling ────────────────────────────────────────────────────────

    /**
     * Ask DPO what happened, and act on the answer. The ONE door.
     *
     * @return array{ok:bool, status:string, code:string, message:string,
     *                reference:string, crm_payment_id:int, changed:bool}
     */
    public function verifyAndSettle(string $reference): array
    {
        $row = $this->store->byReference($reference);
        if ($row === null) {
            // A push naming a reference we never issued. Log it and say nothing
            // useful back — it may be a probe.
            $this->note('dpo_unknown_reference', $reference);
            return self::settleOut(false, 'UNKNOWN', 'UNKNOWN', 'No such payment', $reference);
        }
        $id = (int)$row['id'];

        if ((string)$row['status'] === 'SUCCESS') {
            return self::settleOut(true, 'SUCCESS', '000', 'Already settled', $reference,
                                   (int)$row['crm_payment_id'], false);
        }
        if ((string)$row['status'] === 'REFUNDED') {
            return self::settleOut(true, 'REFUNDED', '', 'Refunded', $reference,
                                   (int)$row['crm_payment_id'], false);
        }
        if ((string)$row['dpo_trans_token'] === '') {
            // Never reached DPO. There is nothing to verify and no money.
            $this->store->update($reference, ['status' => 'FAILED',
                'failure_reason' => 'no transaction token — the payment never reached DPO']);
            $this->store->event($id, 'verify_failed', 'no transaction token to verify');
            return self::settleOut(false, 'FAILED', 'NOTOKEN', 'Payment never started', $reference);
        }

        // Claim first: the return, the push and the cron can all be here at
        // once, and two of them each posting to uCRM would be a double payment
        // that only the second row update would catch.
        if (!$this->store->claimForSettle($reference)) {
            return self::settleOut(true, (string)$row['status'], '', 'Settlement already in progress',
                                   $reference, (int)$row['crm_payment_id'], false);
        }

        try {
            $this->store->event($id, 'verify_requested', 'asking DPO about ' . $row['dpo_trans_ref']);
            $v = $this->dpo->verifyToken((string)$row['dpo_trans_token']);

            if (!$v['reachable']) {
                // Our problem, not the customer's failed payment. Change nothing.
                $this->store->event($id, 'verify_failed', 'DPO unreachable: ' . $v['message']);
                $this->store->update($reference, ['verified_at' => gmdate('Y-m-d H:i:s')]);
                return self::settleOut(false, (string)$row['status'], 'UNREACHABLE',
                                       'Could not reach DPO', $reference);
            }

            $this->store->update($reference, [
                'verified_at' => gmdate('Y-m-d H:i:s'),
                'dpo_result' => $v['code'], 'dpo_result_text' => $v['message'],
                'payment_method' => $v['method'] !== '' ? $v['method'] : null,
            ]);
            $this->store->event($id, 'verify_succeeded', 'DPO ' . $v['code'] . ': ' . $v['message']);

            if (!$v['paid']) return $this->notPaid($row, $v);
            return $this->settle($row, $v);
        } finally {
            $cur = $this->store->byReference($reference);
            if ($cur !== null && (string)$cur['status'] !== 'SUCCESS') {
                $this->store->releaseClaim($reference);
            }
        }
    }

    /** A verified answer that is not "paid". Each code means one thing. */
    private function notPaid(array $row, array $v): array
    {
        $ref = (string)$row['reference'];
        $id  = (int)$row['id'];
        $map = ['001' => 'PENDING', '900' => 'PENDING', '901' => 'FAILED',
                '903' => 'EXPIRED', '904' => 'CANCELLED', '002' => 'QUARANTINED',
                '902' => 'FAILED'];
        $status = $map[$v['code']] ?? 'PENDING';

        // 002 is over- or underpayment: money moved, and it is not a clean
        // settlement. Decision: no partial payments, so a person decides.
        $reason = $v['code'] === '002'
            ? 'DPO reports the transaction over- or underpaid: '
              . ($v['amount'] === null ? 'amount not stated' : number_format($v['amount'], 2))
              . ' against ' . number_format((float)$row['amount'], 2) . ' ' . $row['currency']
            : $v['message'];

        $this->store->update($ref, ['status' => $status, 'failure_reason' => $reason]);
        $this->store->event($id, $status === 'QUARANTINED' ? 'quarantined'
                                : ($status === 'EXPIRED' ? 'expired'
                                : ($status === 'CANCELLED' ? 'cancelled' : 'verify_succeeded')),
                            $reason);
        if ($status === 'QUARANTINED') $this->note('dpo_quarantined', $ref . ' — ' . $reason);
        return self::settleOut(true, $status, $v['code'], $v['message'], $ref);
    }

    /** Result 000. Check everything before a shilling is recorded. */
    private function settle(array $row, array $v): array
    {
        $ref = (string)$row['reference'];
        $id  = (int)$row['id'];

        // The reference DPO echoes must be ours. A mismatch means we are
        // looking at somebody else's transaction.
        if ($v['reference'] !== '' && $v['reference'] !== $ref) {
            return $this->quarantine($row, 'DPO returned a different reference: '
                . $v['reference'] . ' — nothing was settled');
        }
        // "DPO did not say" is not "it matched". Settling a payment we could
        // not check is how money goes missing quietly.
        if ($v['amount'] === null || $v['currency'] === null) {
            return $this->quarantine($row,
                'DPO reported the payment without stating the amount or currency, '
              . 'so it could not be checked against the invoice');
        }
        if (strtoupper($v['currency']) !== strtoupper((string)$row['currency'])) {
            return $this->quarantine($row, 'DPO settled in ' . $v['currency']
                . ' but the invoice is in ' . $row['currency'] . ' — never converted');
        }
        // Compared in minor units so a float never decides whether an invoice
        // is paid. Decision: full outstanding only.
        if ((int)round($v['amount'] * 100) !== (int)round((float)$row['amount'] * 100)) {
            return $this->quarantine($row, 'DPO settled ' . number_format($v['amount'], 2)
                . ' against ' . number_format((float)$row['amount'], 2) . ' ' . $row['currency']
                . ' outstanding — partial payments are not accepted');
        }

        $method = (string)($this->cfg['dpo_payment_method_uuid'] ?? '');
        if ($method === '') {
            // Never Cash. Cash feeds agent cash reconciliation, so the fallback
            // would invent money somebody has to hand over.
            return $this->quarantine($row,
                'no uCRM payment method is configured for DPO — refusing to book this '
              . 'as Cash. Run tools/dpo_payment_method.php, then settle this row.');
        }

        $payload = [
            'clientId'     => (int)$row['crm_client_id'],
            'methodId'     => $method,
            'amount'       => (float)$row['amount'],
            'currencyCode' => (string)$row['currency'],
            'note'         => 'DPO Pay | Ref: ' . $ref
                            . ' | DPO TransRef: ' . (string)$row['dpo_trans_ref']
                            . ' | Invoice: ' . (string)$row['invoice_number']
                            . ' | Env: ' . (string)$row['environment'],
            'applyToInvoicesAutomatically' => true,
        ];
        $res = $this->crm->createPaymentSafe($payload, $ref);

        if (empty($res['success']) || empty($res['id'])) {
            // The money is real; uCRM is the thing that failed. Stay open so
            // the cron tries again — never drop a payment we have taken.
            $err = (string)($res['error'] ?? 'uCRM did not accept the payment');
            $this->store->update($ref, ['status' => 'PENDING', 'failure_reason' => $err]);
            $this->store->event($id, 'verify_failed', 'uCRM refused the payment: ' . $err);
            $this->note('dpo_crm_payment_failed', $ref . ' — ' . $err);
            return self::settleOut(false, 'PENDING', '000',
                'Payment confirmed but not yet recorded', $ref);
        }

        $crmId = (int)$res['id'];
        if (!$this->store->markSettled($ref, $crmId, (string)$v['method'])) {
            // Another settlement won the race, or this uCRM payment is already
            // claimed by another row. Either way: do not record it twice.
            $now = $this->store->byReference($ref);
            $this->store->event($id, 'verify_succeeded',
                'settlement already recorded by another channel — nothing written');
            return self::settleOut(true, (string)($now['status'] ?? 'SUCCESS'), '000',
                'Already settled', $ref, (int)($now['crm_payment_id'] ?? $crmId), false);
        }

        $this->store->event($id, 'crm_payment_created', 'uCRM payment #' . $crmId);
        $this->store->event($id, 'marked_success',
            number_format((float)$row['amount'], 2) . ' ' . $row['currency']
          . ' settled against ' . (string)$row['invoice_number']);
        $this->note('dpo_payment_settled',
            $ref . ' → uCRM payment #' . $crmId . ' (' . $row['environment'] . ')');

        return self::settleOut(true, 'SUCCESS', '000', 'Payment recorded', $ref, $crmId, true);
    }

    private function quarantine(array $row, string $why): array
    {
        $ref = (string)$row['reference'];
        $this->store->update($ref, ['status' => 'QUARANTINED', 'failure_reason' => $why]);
        $this->store->event((int)$row['id'], 'quarantined', $why);
        $this->note('dpo_quarantined', $ref . ' — ' . $why);
        return self::settleOut(false, 'QUARANTINED', '000', $why, $ref);
    }

    // ── Reconciling ─────────────────────────────────────────────────────

    /**
     * The third leg: the customer who paid and closed the browser.
     *
     * Runs whether or not the feature flag is on — disabling new payments must
     * never strand money already taken.
     */
    public function reconcile(int $graceSeconds = 120, int $limit = 50): array
    {
        $out = ['checked' => 0, 'settled' => 0, 'expired' => 0, 'failed' => 0,
                'quarantined' => 0, 'unreachable' => 0, 'skipped' => 0];

        foreach ($this->store->openOlderThan($graceSeconds, $limit) as $row) {
            // Don't hammer DPO for a token something else just asked about.
            if (!empty($row['verified_at'])
                && strtotime((string)$row['verified_at']) > time() - 60) { $out['skipped']++; continue; }

            $out['checked']++;
            $r = $this->verifyAndSettle((string)$row['reference']);

            // Expiry needs BOTH our clock and a verify that says unpaid. A
            // payment completing at minute 29 must not be expired at minute 30.
            if ($r['status'] === 'PENDING' && !empty($row['attempt_expires_at'])
                && strtotime((string)$row['attempt_expires_at']) < time()
                && $r['code'] !== 'UNREACHABLE') {
                $this->store->update((string)$row['reference'], [
                    'status' => 'EXPIRED',
                    'failure_reason' => 'the payment attempt passed its time limit unpaid']);
                $this->store->event((int)$row['id'], 'expired',
                    'attempt expired after DPO confirmed it was still unpaid');
                $out['expired']++;
                continue;
            }

            switch ($r['status']) {
                case 'SUCCESS':     $out['settled']++;     break;
                case 'EXPIRED':     $out['expired']++;     break;
                case 'FAILED':      $out['failed']++;      break;
                case 'QUARANTINED': $out['quarantined']++; break;
            }
            if ($r['code'] === 'UNREACHABLE') $out['unreachable']++;
        }
        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Unique, unguessable, and readable enough to trace by eye. */
    public static function reference(int $invoiceId, int $clientId): string
    {
        return sprintf('DPO-%d-%d-%s', $invoiceId, $clientId,
                       strtoupper(bin2hex(random_bytes(4))));
    }

    private static function describe(array $inv): string
    {
        $label  = (string)($inv['items'][0]['label'] ?? '');
        $number = (string)($inv['number'] ?? ('INV-' . (int)($inv['id'] ?? 0)));
        return trim(($label !== '' ? $label : 'Internet Service') . ' (' . $number . ')');
    }

    private function note(string $event, string $detail): void
    {
        if ($this->log !== null) ($this->log)($event, $detail);
    }

    private static function no(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'reference' => '',
                'checkout_url' => '', 'amount' => 0.0, 'currency' => '', 'reused' => false];
    }

    private static function settleOut(bool $ok, string $status, string $code, string $message,
                                      string $ref, int $crmId = 0, bool $changed = true): array
    {
        return ['ok' => $ok, 'status' => $status, 'code' => $code, 'message' => $message,
                'reference' => $ref, 'crm_payment_id' => $crmId, 'changed' => $changed];
    }
}
