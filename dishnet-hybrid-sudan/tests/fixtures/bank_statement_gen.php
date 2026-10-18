<?php
declare(strict_types=1);
/**
 * bank_statement_gen.php — synthetic Ecobank "Account Details" CSV files in the measured export format, for the Bank
 * screen's tests (5.18.97; lib/EcobankDetailsCsv.php). A real statement is NEVER a fixture.
 *
 * EVERY value here is invented: the account is 0000000001, the holder "RH-Account Holder One", the counterparties
 * "RH-CLIENT n", the references RH000001…, the bank line numbers (Cheque Number) 0000000101… rising by 1 to 3, and the
 * amounts small made-up decimals from a seeded generator. The same seed gives the same history on every run.
 *
 * The format, as measured on the export (masked): one header row of 23 names, every field double-quoted, UTF-8, LF line
 * ends, the running balance left-padded with spaces, SEQUENCE_NMBR renumbered from 1 in each file, Opening and Closing
 * Available ("UGX 1234.50") repeated on every line, and plain decimals that drop a leading zero (".9").
 *
 *   g_history($n, $seed, …)   one account's lines, SEQUENCE 1..n, each an array keyed by column name, plus three
 *                             private keys the file writer drops: _cents (the amount), _credit (its side), _run (the
 *                             balance after it, in cents)
 *   g_rechain($lines, $from)  recomputes _run and the Running Balance text from line $from on, after a test changed an
 *                             amount or a side (the export's chain always holds; a test that breaks it does so on purpose)
 *   g_file($lines, $ccy, $o)  the CSV text of a slice of a history. Options:
 *                               shuffle  the rows in another order (SEQUENCE_NMBR still names their bank order)
 *                               mutate   a callback over the column arrays, after renumbering and the stated balances
 *                               header   the header row to write (and which columns, in which order)
 *                               pad      the Running Balance's padding width in this file (the bank's padding varies)
 *                               nolead   balances written without a leading zero ("UGX .00", "   .50")
 *                               marker   a string put into every text cell (names, codes, descriptions, references)
 *                               bom      a UTF-8 byte-order mark first
 *                               crlf     CRLF line ends
 *   g_summary($header, $ccy)  Ecobank's ten-column ACCOUNT SUMMARY export (balances, no lines), with or without its
 *                             header row — the file the reader must refuse without repeating a cell
 *
 * PHP 7.4. No database, no file: strings in, strings out.
 */

const BK_GEN_MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
const BK_GEN_ACCOUNT = '0000000001';
const BK_GEN_HOLDER  = 'RH-Account Holder One';
/** The columns g_file()'s marker option writes into: every cell the reader keeps as text and does not interpret. */
const BK_GEN_TEXT_COLUMNS = ['Account Name', 'Sr. No.', 'Type Code', 'Type Description', 'Cust Ref', 'Bank Ref', 'Remarks',
    'Transaction Code', 'Loan Outstanding Amount', 'Total Amount', 'Reference', 'Customer Reference', 'Transaction Reference'];

function g_date(int $y, int $m, int $d): string { return sprintf('%02d-%s-%02d', $d, BK_GEN_MONTHS[$m - 1], $y % 100); }

/** Cents as a signed plain decimal with two places: 123450 → "1234.50", -50 → "-0.50". */
function g_money(int $cents): string
{
    $s = $cents < 0 ? '-' : '';
    $c = abs($cents);
    return $s . intdiv($c, 100) . '.' . sprintf('%02d', $c % 100);
}

/** A plain decimal as the export writes an amount: no separator, trailing zeros and a leading zero dropped (".9"). */
function g_plain(int $cents): string
{
    $t = rtrim(rtrim(g_money($cents), '0'), '.');
    if (strpos($t, '0.') === 0) $t = substr($t, 1);
    return $t === '' ? '0' : $t;
}

/**
 * One account's history: SEQUENCE 1..n from an opening balance, Cheque Numbers from $chq0. Deterministic for a seed.
 * A debit never takes the balance below zero; a value date sometimes falls a day after its posting date.
 */
function g_history(int $n, int $seed = 1, int $opening = 0, int $chq0 = 101, array $start = [2026, 8, 1]): array
{
    mt_srand($seed);
    $run = $opening; $out = []; $chq = $chq0; [$y, $m, $d] = $start; $ref = 1;
    for ($i = 1; $i <= $n; $i++) {
        $isCredit = ($i === 1) || mt_rand(0, 2) > 0;
        $amt = mt_rand(1, 500000) * ($isCredit ? 10 : 1);
        if (!$isCredit && $amt > $run) $isCredit = true;
        if ($isCredit) $run += $amt; else $run -= $amt;
        if (mt_rand(0, 3) === 0) { $d++; if ($d > 28) { $d = 1; $m++; if ($m > 12) { $m = 1; $y++; } } }
        $vd = mt_rand(0, 5) === 0 ? min(28, $d + 1) : $d;
        $rf = 'RH' . sprintf('%06d', $ref);
        if (mt_rand(0, 2)) $ref++;
        $rem = $isCredit ? 'TRF FROM RH-CLIENT ' . mt_rand(1, 9) . ' INV-' . sprintf('%04d', $i) : 'CHARGES ON TRF ' . $rf;
        $out[] = ['Account' => BK_GEN_ACCOUNT, 'Account Name' => BK_GEN_HOLDER, 'Opening Available' => '', 'Closing Available' => '',
            'SEQUENCE_NMBR' => (string)$i, 'Sr. No.' => (string)$i, 'Posting Date' => g_date($y, $m, $d),
            'Type Code' => $isCredit ? 'NTRF' : 'NCHG', 'Type Description' => $isCredit ? 'RH TRANSFER' : 'RH CHARGE',
            'Credit' => $isCredit ? g_plain($amt) : '', 'Debit' => $isCredit ? '' : g_plain($amt),
            'Running Balance' => str_pad(g_money($run), 18, ' ', STR_PAD_LEFT), 'Value Date' => g_date($y, $m, $vd),
            'Cust Ref' => '', 'Bank Ref' => '', 'Cheque Number' => sprintf('%010d', $chq), 'Remarks' => $rem,
            'Transaction Code' => $isCredit ? 'RH01' : 'RH02', 'Loan Outstanding Amount' => '0', 'Total Amount' => '0',
            'Reference' => $rf, 'Customer Reference' => $rf, 'Transaction Reference' => $rem,
            '_cents' => $amt, '_credit' => $isCredit, '_run' => $run];
        $chq += mt_rand(1, 3);
    }
    return $out;
}

/**
 * The chain recomputed from line $from on: each line's _run, Credit/Debit text and Running Balance follow its _cents and
 * _credit, starting from the balance before line $from. Lines before $from are left exactly as they were.
 */
function g_rechain(array $lines, int $from = 0): array
{
    $run = $from > 0 ? $lines[$from - 1]['_run'] : $lines[0]['_run'] - ($lines[0]['_credit'] ? $lines[0]['_cents'] : -$lines[0]['_cents']);
    for ($i = $from, $n = count($lines); $i < $n; $i++) {
        $l = &$lines[$i];
        $run += $l['_credit'] ? $l['_cents'] : -$l['_cents'];
        $l['_run'] = $run;
        $l['Credit'] = $l['_credit'] ? g_plain($l['_cents']) : '';
        $l['Debit']  = $l['_credit'] ? '' : g_plain($l['_cents']);
        $l['Running Balance'] = str_pad(g_money($run), 18, ' ', STR_PAD_LEFT);
        unset($l);
    }
    return $lines;
}

/** A file from a slice of history: SEQUENCE renumbered 1..k, the stated balances set, then the options (see the top). */
function g_file(array $lines, string $ccy = 'UGX', array $opt = []): string
{
    $k = 0; $first = $lines[0]; $last = $lines[count($lines) - 1];
    $open = $first['_run'] - ($first['_credit'] ? $first['_cents'] : -$first['_cents']);
    foreach ($lines as &$l) {
        $k++;
        $l['SEQUENCE_NMBR'] = (string)$k; $l['Sr. No.'] = (string)$k;
        $l['Opening Available'] = $ccy . ' ' . g_money($open); $l['Closing Available'] = $ccy . ' ' . g_money($last['_run']);
    }
    unset($l);
    if (isset($opt['marker'])) {
        foreach ($lines as &$l) foreach (BK_GEN_TEXT_COLUMNS as $c) $l[$c] = $opt['marker'] . ' ' . $l[$c];
        unset($l);
    }
    if (isset($opt['mutate'])) $lines = ($opt['mutate'])($lines);
    $nl = function (string $m): string { return (string)preg_replace('/^(-?)0\./', '$1.', $m); };
    if (!empty($opt['nolead'])) {
        foreach ($lines as &$l) {
            $l['Opening Available'] = $ccy . ' ' . $nl(g_money($open)); $l['Closing Available'] = $ccy . ' ' . $nl(g_money($last['_run']));
            $l['Running Balance'] = str_pad($nl(trim($l['Running Balance'])), 18, ' ', STR_PAD_LEFT);
        }
        unset($l);
    }
    if (isset($opt['pad'])) {
        foreach ($lines as &$l) $l['Running Balance'] = str_pad(trim($l['Running Balance']), (int)$opt['pad'], ' ', STR_PAD_LEFT);
        unset($l);
    }
    if (!empty($opt['shuffle'])) { mt_srand(7); shuffle($lines); }
    $hdr = $opt['header'] ?? array_keys(array_filter($first, function ($k) { return $k[0] !== '_'; }, ARRAY_FILTER_USE_KEY));
    $q = function ($v): string { return '"' . str_replace('"', '""', (string)$v) . '"'; };
    $eol = !empty($opt['crlf']) ? "\r\n" : "\n";
    $out = (!empty($opt['bom']) ? "\xEF\xBB\xBF" : '') . implode(',', array_map($q, $hdr)) . $eol;
    foreach ($lines as $l) {
        $cells = [];
        foreach ($hdr as $h) $cells[] = $l[$h] ?? '';
        $out .= implode(',', array_map($q, $cells)) . $eol;
    }
    return $out;
}

/** Ecobank's ACCOUNT SUMMARY export: ten columns, one row per account, balances only. Invented values. */
function g_summary(bool $withHeader = true, string $ccy = 'UGX', string $marker = ''): string
{
    $q = function (array $a): string { return implode(',', array_map(function ($v) { return '"' . $v . '"'; }, $a)) . "\n"; };
    $m = $marker === '' ? '' : $marker . ' ';
    $row = ['1', BK_GEN_ACCOUNT, $m . BK_GEN_HOLDER, $m . 'CURRENT', $ccy . ' 95.10', $ccy . ' 4.00', $ccy . ' 0.00', $ccy . ' 0.00',
        $ccy . ' 95.10', $ccy . ' 99.10'];
    $hdr = ['Sr. No.', 'Account Number', 'Account Name', 'Account Type', 'Closing Ledger', 'Total Debits', 'Opening Ledger',
        'Opening Available', 'Closing Available', 'Total Credits'];
    return ($withHeader ? $q($hdr) : '') . $q($row);
}
