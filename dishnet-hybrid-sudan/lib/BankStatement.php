<?php
declare(strict_types=1);

/**
 * BankStatement — read a bank statement without trusting the reader.
 *
 * A statement is the only record of money that actually moved. Everything
 * else in this system is what somebody INTENDED: an order placed, a bill
 * booked, a payment marked as made. The bank says what left the account.
 *
 * ── THE RUNNING BALANCE IS THE PROOF ────────────────────────────────────
 *
 * Every row carries the balance after it. So a statement can check itself:
 * previous balance, minus the debit, plus the credit, must equal this row's
 * balance — for every row, with no exceptions. A mistyped digit, a dropped
 * row, a column read as the wrong one: all of them break the chain, and a
 * statement whose chain is broken is refused rather than imported. There is
 * no version of "close enough" here. Money either reconciles or it does not.
 *
 * ── IT DOES NOT DECIDE WHAT THINGS MEAN ─────────────────────────────────
 *
 * Classification stops where certainty stops. A bank charge is a bank
 * charge. A card purchase at a named supplier is a payment to that supplier.
 * But money arriving is not obviously share capital, a director's loan, or a
 * customer paying an invoice — those are three different things on three
 * different statements, and the bank cannot tell them apart. They come back
 * as 'deposit' for a person to say.
 */
final class BankStatement
{
    /** What a row is, as far as the statement alone can prove. */
    public const KINDS = ['deposit', 'supplier', 'bank_charge', 'withdrawal', 'unclassified'];

    /**
     * Column names seen in the wild, lower-cased. Ecobank's BTR export, and
     * enough near-misses that a statement from another bank has a chance.
     */
    private const COLUMNS = [
        'date'        => ['posting date', 'value date', 'date', 'transaction date', 'txn date'],
        'description' => ['description', 'remarks', 'narration', 'details', 'particulars'],
        'debit'       => ['debit', 'withdrawal', 'dr', 'money out', 'paid out'],
        'credit'      => ['credit', 'deposit', 'cr', 'money in', 'paid in'],
        'balance'     => ['running balance', 'balance', 'closing balance'],
        'ref'         => ['transaction reference', 'customer reference', 'reference', 'ref', 'transaction id'],
    ];

    /**
     * Parse CSV text into rows.
     *
     * @return array{ok:bool, error?:string, headers?:array, rows?:array}
     */
    public static function parse(string $csv): array
    {
        $csv = preg_replace("/^\xEF\xBB\xBF/", '', $csv) ?? $csv;
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
        if (count($lines) < 2) return ['ok' => false, 'error' => 'The file has no data rows.'];

        $head = str_getcsv(array_shift($lines));
        $map  = [];
        foreach ($head as $i => $h) {
            $h = strtolower(trim((string)$h));
            foreach (self::COLUMNS as $field => $names) {
                if (isset($map[$field])) continue;
                if (in_array($h, $names, true)) { $map[$field] = $i; break; }
            }
        }
        foreach (['date', 'description', 'balance'] as $need) {
            if (!isset($map[$need])) {
                // Naming the headers it DID see turns "unsupported format" into
                // something the person can act on in ten seconds.
                return ['ok' => false, 'error' => "No '{$need}' column. Columns found: "
                                                . implode(', ', array_map('trim', $head))];
            }
        }
        if (!isset($map['debit']) && !isset($map['credit'])) {
            return ['ok' => false, 'error' => 'No debit or credit column. Columns found: '
                                            . implode(', ', array_map('trim', $head))];
        }

        $rows = [];
        foreach ($lines as $n => $line) {
            $c = str_getcsv($line);
            $get = static function (?int $i) use ($c): string {
                return $i === null ? '' : trim((string)($c[$i] ?? ''));
            };
            $date = self::date($get($map['date'] ?? null));
            if ($date === '') continue;              // a totals or footer line
            $desc = $get($map['description'] ?? null);
            $rows[] = [
                'line'        => $n + 2,             // as numbered in the file
                'date'        => $date,
                'description' => $desc,
                'debit'       => self::money($get($map['debit'] ?? null)),
                'credit'      => self::money($get($map['credit'] ?? null)),
                'balance'     => self::money($get($map['balance'] ?? null)),
                'ref'         => $get($map['ref'] ?? null),
                'kind'        => self::classify($desc, self::money($get($map['credit'] ?? null)) > 0),
            ];
        }
        if ($rows === []) return ['ok' => false, 'error' => 'No rows with a readable date.'];
        return ['ok' => true, 'headers' => $head, 'rows' => $rows];
    }

    /**
     * Check every row against the bank's own running balance.
     *
     * The opening balance is derived from the first row rather than asked
     * for: whatever it was, the first row's own balance fixes it.
     *
     * @return array{ok:bool, opening:float, closing:float, breaks:array}
     */
    public static function verifyChain(array $rows): array
    {
        if ($rows === []) return ['ok' => true, 'opening' => 0.0, 'closing' => 0.0, 'breaks' => []];

        $first   = $rows[0];
        $opening = round($first['balance'] + $first['debit'] - $first['credit'], 2);

        $bal = $opening; $breaks = [];
        foreach ($rows as $r) {
            $bal = round($bal - $r['debit'] + $r['credit'], 2);
            if (abs($bal - $r['balance']) > 0.005) {
                $breaks[] = [
                    'line'        => $r['line'],
                    'date'        => $r['date'],
                    'description' => $r['description'],
                    'expected'    => $bal,
                    'stated'      => $r['balance'],
                    'out_by'      => round($r['balance'] - $bal, 2),
                ];
                // Carry on from what the bank says, so one bad row reports as
                // one bad row instead of poisoning every row after it.
                $bal = $r['balance'];
            }
        }
        return ['ok' => $breaks === [], 'opening' => $opening,
                'closing' => round($bal, 2), 'breaks' => $breaks];
    }

    /** Totals per kind, for the summary. */
    public static function summarise(array $rows): array
    {
        $out = ['in' => 0.0, 'out' => 0.0, 'count' => count($rows), 'by_kind' => []];
        foreach ($rows as $r) {
            $out['in']  = round($out['in']  + $r['credit'], 2);
            $out['out'] = round($out['out'] + $r['debit'], 2);
            $k = $r['kind'];
            $out['by_kind'][$k] = $out['by_kind'][$k] ?? ['count' => 0, 'in' => 0.0, 'out' => 0.0];
            $out['by_kind'][$k]['count']++;
            $out['by_kind'][$k]['in']  = round($out['by_kind'][$k]['in']  + $r['credit'], 2);
            $out['by_kind'][$k]['out'] = round($out['by_kind'][$k]['out'] + $r['debit'], 2);
        }
        return $out;
    }

    /**
     * What the statement alone can prove a row is.
     *
     * Deliberately narrow. 'unclassified' is a useful answer; a confident
     * wrong one is not.
     */
    public static function classify(string $desc, bool $isCredit): string
    {
        $d = strtolower($desc);
        if ($isCredit) return 'deposit';
        // 'VALUE ADDED TAX' on its own line, next to the fee it is charged on,
        // is the bank's VAT — not a payment to the revenue authority, which a
        // statement names (URA, PAYE, WHT).
        if (preg_match('/\b(fee|charge|vat|vat_fee|value added tax|maintenance|cheque book|card issuance)\b/', $d)
            && !preg_match('/\b(ura|paye|wht|withholding|revenue authority)\b/', $d)) {
            return 'bank_charge';
        }
        if (preg_match('/\b(cash w\/d|withdrawal|atm)\b/', $d)) return 'withdrawal';
        if (preg_match('/\b(pos purchase|card purchase|starlink)\b/', $d)) return 'supplier';
        return 'unclassified';
    }

    /**
     * The supplier a card purchase names.
     *
     * Bank narrations are shouty and full of terminal ids: "STARLINK GLOBAL
     * INTERNE256781468254 UG - POS PURCHASE (ON-US)". The name at the front
     * is the part that matters.
     */
    public static function supplierName(string $desc): string
    {
        if (stripos($desc, 'starlink') !== false) return 'Starlink';
        $first = trim((string)preg_split('/\d{6,}|\s+-\s+POS\b/i', trim($desc))[0]);
        return $first !== '' ? ucwords(strtolower($first)) : '';
    }

    // ── Reading the two awkward column types ────────────────────────────────

    /** A money cell: '$ 1,859,520.00', 'UGX 1,859,520.00', '(500.00)', ''. */
    public static function money(string $v): float
    {
        $v = trim($v);
        if ($v === '' || $v === '-') return 0.0;
        $neg = (strpos($v, '(') !== false && strpos($v, ')') !== false) || strpos($v, '-') === 0;
        $v = preg_replace('/[^0-9.]/', '', $v) ?? '';
        if ($v === '' || !is_numeric($v)) return 0.0;
        return round((float)$v * ($neg ? -1 : 1), 2);
    }

    /**
     * A date cell, as YYYY-MM-DD.
     *
     * Ecobank writes 09/11/2026 and means 11 September — US order. A bank
     * that writes 11/09/2026 means the same day the other way round, and
     * guessing between them silently moves money between months. So: an
     * unambiguous day (>12) settles it, and anything still ambiguous is read
     * US-first, which is what this statement uses.
     */
    public static function date(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})#', $v, $m)) {
            [$a, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            [$mo, $d] = $a > 12 ? [$b, $a] : [$a, $b];
            if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return '';
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : '';
    }
}
