<?php
declare(strict_types=1);

require_once __DIR__ . '/CashbookService.php';
require_once __DIR__ . '/BankStatement.php';
require_once __DIR__ . '/FinAudit.php';

/**
 * BankImport — put a verified statement into the cashbook, and tell the truth
 * about the parts it cannot explain.
 *
 * ── BUYING STOCK IS NOT AN EXPENSE ──────────────────────────────────────
 *
 * 7,438,080 paid to Starlink did not make the business 7,438,080 poorer: it
 * turned cash into equipment it still owns. Booked as an expense it would
 * show as a trading loss for kits sitting in a box. So a supplier payment
 * for equipment moves BETWEEN accounts — out of the bank, into inventory —
 * typed TRANSFER, which the P&L excludes by construction. The cost reaches
 * the P&L later, when the kit is installed and invoiced, against the revenue
 * it earns. cb_accounts already has an 'inventory' kind and MONEY_KINDS
 * already excludes it from cash positions; this is what they are for.
 *
 * ── WHAT IT REFUSES TO GUESS ────────────────────────────────────────────
 *
 * Money ARRIVING is share capital, a director's loan, or a customer paying —
 * three different things with three different consequences, and a bank
 * narration that says "DEP BY BHAVIN MADLANI" tells you none of them. So
 * deposits are imported only when a person says which. Cash withdrawals are
 * the same: the bank knows the money left, not where it went.
 *
 * ── THE SUSPENSE ACCOUNT ────────────────────────────────────────────────
 *
 * The bank shows far more Starlink payments than there are booked orders,
 * because most of them belong to Starlink accounts whose orders have not
 * been imported. Those payments are real — the money is gone — but claiming
 * inventory for them would invent stock. They go to a suspense account
 * instead: the bank balance reconciles, nothing false is claimed, and the
 * suspense balance IS the size of the remaining job. It shrinks to zero as
 * the missing orders arrive.
 */
final class BankImport
{
    /**
     * How long after a payment its bank posting may appear.
     *
     * DIRECTIONAL, and that is the whole point. A bank cannot post a payment
     * before it was made. A symmetric window let a debit posted on the 3rd
     * claim a payment made on the 8th — five days in the future — and because
     * twelve payments here are the same amount, it looked like a clean match.
     *
     * Starlink stamps UTC; Ecobank posts in EAT (UTC+3), so a local posting
     * date is never earlier than the UTC payment date. Same day or after,
     * never before.
     *
     * Written into the SQL rather than bound, deliberately. PDO binds an int
     * as a string unless told otherwise, and SQLite sorts any text above any
     * number — so `BETWEEN 0 AND ?` with a bound 3 has no upper bound at all
     * and silently matches every payment ever made. It is a compile-time int
     * constant, so there is nothing to inject.
     */
    private const SETTLE_LAG_DAYS = 3;

    private CashbookService $cb;
    private \PDO $db;

    public function __construct(CashbookService $cb, ?\PDO $db = null)
    {
        $this->cb = $cb;
        $this->db = $db ?? $cb->getPdo();
    }

    /**
     * @param array $rows   from BankStatement::parse()
     * @param int   $acctId the bank account these rows belong to
     * @param array $opts   commit, deposits ('' | 'director' | 'capital' | 'receipt'),
     *                      actor (string), liability_account (int, for 'director')
     *
     * @return array{ok:bool, posted:int, skipped:int, already:int,
     *               matched:int, suspense:int, ambiguous:int, needs_decision:array,
     *               notes:string[], errors:string[], lines:array}
     */
    public function import(array $rows, int $acctId, array $opts = []): array
    {
        $commit = !empty($opts['commit']);
        $actor  = trim((string)($opts['actor'] ?? 'bank import')) ?: 'bank import';

        $acct = $this->cb->account($acctId);
        if (!$acct) return ['ok' => false, 'posted' => 0, 'skipped' => 0, 'already' => 0,
                            'matched' => 0, 'suspense' => 0, 'ambiguous' => 0,
                            'needs_decision' => [], 'notes' => [],
                            'errors' => ["There is no cashbook account #{$acctId}."], 'lines' => []];
        $cur = strtoupper((string)$acct['currency']);

        $posted = 0; $skipped = 0; $already = 0; $matched = 0; $suspense = 0; $ambiguous = 0;
        $needs = []; $notes = []; $errors = []; $lines = [];

        $invId = $susId = 0;
        // Twelve payments of the same amount days apart are twelve DIFFERENT
        // acts. Without this, a statement with two identical card debits ties
        // both to the one booked order and reports two payments covered when
        // only one is — and it does so in the dry run, where nothing has been
        // written yet to stop it.
        $claimed = [];
        $dupWarned = false;
        foreach ($rows as $r) {
            $ref  = self::reference($r);
            $seen = $this->refExists($ref);
            $line = [
                'date' => $r['date'], 'description' => $r['description'], 'kind' => $r['kind'],
                'amount' => $r['debit'] > 0 ? -$r['debit'] : $r['credit'],
                'ref' => $ref, 'action' => '', 'detail' => '',
            ];

            if ($seen) { $already++; $line['action'] = 'already in the book'; $lines[] = $line; continue; }

            switch ($r['kind']) {
                case 'bank_charge':
                    $line['action'] = 'bank charge';
                    if ($commit) {
                        $this->cb->addEntryRaw([
                            'project' => 'dishnet', 'date' => $r['date'], 'direction' => 'out',
                            'amount' => $r['debit'], 'currency' => $cur,
                            'category' => 'Bank Charges', 'category_raw' => 'Bank Charges',
                            'description' => $r['description'],
                            'validation_ref' => $ref, 'validation_status' => 'online',
                            'status' => 'approved', 'approved_by' => $actor,
                            'source' => 'bank_import', 'account_id' => $acctId,
                            'txn_type' => 'EXPENSE',
                        ]);
                    }
                    $posted++;
                    break;

                case 'supplier':
                    $pay = $this->matchPayment($r, $cur, $claimed);
                    if ($pay) {
                        $claimed[] = (int)$pay['id'];
                        $ambiguous += (int)$pay['candidates'] > 1 ? 1 : 0;
                        if ($invId === 0) { $invId = $this->ensureAccount('Inventory – Equipment (' . $cur . ')', $cur, 'inventory'); }
                        $to = $invId; $matched++;
                        $line['action'] = 'to inventory';
                        $line['detail'] = 'matches ' . (string)$pay['invoice_number'] . ' — ' . (string)$pay['supplier_ref'];
                    } else {
                        if ($susId === 0) { $susId = $this->ensureAccount('Supplier payments – unmatched (' . $cur . ')', $cur, 'asset'); }
                        $to = $susId; $suspense++;
                        $line['action'] = 'to suspense';
                        $line['detail'] = 'no booked order for this payment';
                    }
                    if ($commit) {
                        $res = $this->cb->recordAccountTransfer(
                            $acctId, $to, $r['debit'], $r['debit'], $r['date'],
                            $r['description'], '', $actor, $ref, 'bank_import');
                        if (empty($res['ok'])) { $errors[] = $r['date'] . ' ' . $r['description'] . ': ' . (string)$res['error']; break; }
                        if ($pay) $this->linkPayment((int)$pay['id'], $ref, $actor);
                    }
                    $posted++;
                    break;

                case 'deposit':
                    // Named by a person or not at all.
                    $how = (string)($opts['deposits'] ?? '');
                    if ($how === '') {
                        $skipped++; $needs[] = $r;
                        $line['action'] = 'NEEDS A DECISION';
                        $line['detail'] = 'capital, a director loan, or a customer paying?';
                        break;
                    }
                    $cat = ['capital' => 'Share Capital', 'director' => 'Loan Received',
                            'receipt' => 'Receipt'][$how] ?? '';
                    if ($cat === '') { $errors[] = "Unknown deposit treatment '{$how}'."; break; }
                    // The same money often reaches the book twice: once typed in
                    // when it arrived, and again off the statement that records
                    // it. Capital counted twice makes a company look twice as
                    // funded as it is, and nothing downstream would catch it.
                    if (!$dupWarned && ($existing = $this->existingCapital($cur)) > 0) {
                        $notes[] = 'This book ALREADY records ' . number_format($existing, 0) . ' '
                                 . $cur . ' of capital or funding that did not come from a bank '
                                 . 'statement. Check these deposits are not that same money '
                                 . 'arriving a second time.';
                        $dupWarned = true;
                    }
                    $line['action'] = 'in as ' . $cat;
                    if ($commit) {
                        $this->cb->addEntryRaw([
                            'project' => 'dishnet', 'date' => $r['date'], 'direction' => 'in',
                            'amount' => $r['credit'], 'currency' => $cur,
                            'category' => $cat, 'category_raw' => $cat,
                            'description' => $r['description'],
                            'validation_ref' => $ref, 'validation_status' => 'online',
                            'status' => 'approved', 'approved_by' => $actor,
                            'source' => 'bank_import', 'account_id' => $acctId,
                            'txn_type' => $how === 'receipt' ? 'PAYMENT' : 'DIRECTOR_FUNDING',
                        ]);
                    }
                    $posted++;
                    break;

                default:
                    // A withdrawal, or a narration nothing recognised. The bank
                    // proves the money moved; it does not say what for.
                    $skipped++; $needs[] = $r;
                    $line['action'] = 'NEEDS A DECISION';
                    $line['detail'] = $r['kind'] === 'withdrawal'
                        ? 'cash left the bank — where did it go?'
                        : 'nothing in the narration says what this is';
            }
            $lines[] = $line;
        }

        if ($ambiguous > 0) {
            // Twelve payments of 1,859,520 to one supplier in nine days cannot be
            // told apart by amount, and neither the bank reference nor the order
            // number appears on the other side. The nearest date is the best key
            // there is; where more than one order fitted, which bank row paid
            // which order is a reasonable guess. No total depends on it — only
            // the line-to-line trace does — and pretending otherwise would be
            // the dishonest part.
            $notes[] = $ambiguous . ' payment(s) were matched on date alone because more than one '
                     . 'order of the same amount was in range. The totals are unaffected; which '
                     . 'bank row paid which order is a best fit, not a certainty.';
        }
        if ($suspense > 0) {
            $notes[] = $suspense . ' supplier payment(s) have no booked order and went to the '
                     . 'suspense account. Import those orders and re-run: each one moves itself '
                     . 'out of suspense and into inventory.';
        }
        if ($needs !== []) {
            $notes[] = count($needs) . ' row(s) need a decision before they can be booked — '
                     . 'until then this account will not agree with the bank.';
        }

        return ['ok' => $errors === [], 'posted' => $posted, 'skipped' => $skipped,
                'already' => $already, 'matched' => $matched, 'suspense' => $suspense, 'ambiguous' => $ambiguous,
                'needs_decision' => $needs, 'notes' => $notes, 'errors' => $errors, 'lines' => $lines];
    }

    /**
     * Does the book now agree with the bank?
     *
     * The only question that matters after an import, and the one a ledger
     * full of correct rows still cannot answer on its own. The statement ends
     * at a figure the bank will defend; the account holds whatever was
     * bookable. Where they differ, the difference has to be NAMED — and every
     * shilling of it should be a row this import refused to guess at.
     *
     * An account reading minus twenty-four million is alarming until you can
     * say "that is the three deposits nobody has classified yet, to the
     * shilling". If the difference is anything other than those rows, that is
     * a real problem and deserves to be the loudest thing on the screen.
     *
     * @return array{bank:float, book:float, difference:float, pending:float,
     *               unexplained:float, agrees:bool, rows:array}
     */
    public function reconcile(int $acctId, float $statementClosing, array $pendingRows): array
    {
        $book = round($this->cb->accountBalance($acctId), 2);
        $diff = round($statementClosing - $book, 2);

        // What the unbooked rows would move this account by, if they were
        // booked: money in raises it, money out lowers it.
        $pending = 0.0;
        foreach ($pendingRows as $r) {
            $pending = round($pending + (float)$r['credit'] - (float)$r['debit'], 2);
        }

        return [
            'bank'        => round($statementClosing, 2),
            'book'        => $book,
            'difference'  => $diff,
            'pending'     => $pending,
            'unexplained' => round($diff - $pending, 2),
            'agrees'      => abs($diff) < 0.005,
            'rows'        => $pendingRows,
        ];
    }

    /**
     * The bank's own transaction id, which is what makes a second import of
     * the same statement do nothing. Statements without one fall back to the
     * row itself, which is stable for the same row and different for another.
     */
    public static function reference(array $r): string
    {
        $id = trim((string)($r['ref'] ?? ''));
        if ($id !== '') return 'BANK-' . $id;
        return 'BANK-' . substr(sha1($r['date'] . '|' . $r['description'] . '|'
                                   . $r['debit'] . '|' . $r['credit'] . '|' . $r['balance']), 0, 12);
    }

    /**
     * Capital and funding already in the book that did NOT come from a
     * statement — the rows a second recording of the same money would
     * duplicate.
     */
    private function existingCapital(string $cur): float
    {
        try {
            $st = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM cb_ledger
                 WHERE direction = 'in' AND status = 'approved'
                   AND UPPER(currency) = ?
                   AND (source IS NULL OR source != 'bank_import')
                   AND (txn_type = 'DIRECTOR_FUNDING'
                        OR category IN ('Share Capital', 'Loan Received', 'Opening Balance'))");
            $st->execute([strtoupper($cur)]);
            return round((float)$st->fetchColumn(), 2);
        } catch (\Throwable $e) { return 0.0; }
    }

    private function refExists(string $ref): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
        $st->execute([$ref]);
        return $st->fetchColumn() !== false;
    }

    /** An account by name, created if it is not there yet. */
    private function ensureAccount(string $name, string $currency, string $kind): int
    {
        foreach ($this->cb->accounts() as $a) {
            if (strcasecmp((string)$a['name'], $name) === 0) return (int)$a['id'];
        }
        $r = $this->cb->addAccount($name, $currency, $kind);
        return !empty($r['ok']) ? (int)$r['id'] : 0;
    }

    /**
     * Find the recorded supplier payment this bank debit IS.
     *
     * Same amount, same currency, near the same day, not already tied to a
     * bank row. Amount alone would tie the wrong one of twelve identical
     * payments; the date window is what separates them, and a payment already
     * linked is never taken twice.
     */
    private function matchPayment(array $r, string $cur, array $claimed = []): ?array
    {
        try {
            // Every payment this debit COULD be, claimed or not. The count has
            // to include the ones already taken in this run: two identical
            // payments make both matches a guess, and excluding the first one
            // before counting would make the second look certain when it is
            // only what was left over.
            $st = $this->db->prepare(
                "SELECT p.id, p.purchase_id, p.paid_on, s.invoice_number, s.supplier, s.supplier_ref
                 FROM stock_purchase_payments p
                 JOIN stock_purchases s ON s.id = p.purchase_id
                 WHERE ROUND(p.amount, 2) = ROUND(?, 2)
                   AND UPPER(p.currency) = ?
                   AND (p.cb_ledger_id IS NULL OR p.cb_ledger_id = 0)
                   AND julianday(?) - julianday(p.paid_on) BETWEEN 0 AND " . (int)self::SETTLE_LAG_DAYS . "
                 ORDER BY julianday(?) - julianday(p.paid_on)");
            $st->execute([$r['debit'], $cur, $r['date'], $r['date']]);
            $all = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($all === []) return null;

            // How many payments this debit could have been, counted WITHOUT
            // regard to what has already been taken. Once the first of two
            // identical payments is linked, the database filter hides it and
            // the second looks uniquely determined — when in truth it is only
            // what was left over, and no more certain than the first.
            $cnt = $this->db->prepare(
                "SELECT COUNT(*) FROM stock_purchase_payments
                 WHERE ROUND(amount, 2) = ROUND(?, 2) AND UPPER(currency) = ?
                   AND julianday(?) - julianday(paid_on) BETWEEN 0 AND " . (int)self::SETTLE_LAG_DAYS);
            $cnt->execute([$r['debit'], $cur, $r['date']]);
            $candidates = max(1, (int)$cnt->fetchColumn());

            foreach ($all as $row) {
                if (in_array((int)$row['id'], $claimed, true)) continue;
                $row['candidates'] = $candidates;
                return $row;
            }
            return null;   // every candidate already taken by an earlier row
        } catch (\Throwable $e) { return null; }
    }

    /** Tie the recorded payment to the bank row that proves it happened. */
    private function linkPayment(int $paymentId, string $ref, string $actor): void
    {
        try {
            $st = $this->db->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? AND direction = 'out' LIMIT 1");
            $st->execute([$ref]);
            $ledgerId = $st->fetchColumn();
            if ($ledgerId === false) return;
            $this->db->prepare("UPDATE stock_purchase_payments SET cb_ledger_id = ? WHERE id = ?")
                     ->execute([(int)$ledgerId, $paymentId]);
            FinAudit::record($this->db, 'stock_purchase_payment', $paymentId, 'update',
                             ['id' => 0, 'name' => $actor], null,
                             ['cb_ledger_id' => (int)$ledgerId],
                             'matched to bank row ' . $ref);
        } catch (\Throwable $e) { /* the money is booked; the link is a convenience */ }
    }
}
