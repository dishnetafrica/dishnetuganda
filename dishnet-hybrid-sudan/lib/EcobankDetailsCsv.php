<?php
declare(strict_types=1);
/**
 * EcobankDetailsCsv — the strict reader for Ecobank's "Account Details" CSV export (5.18.97, the Bank screen v1; Uganda
 * only). One profile, ecobank_account_details_csv, version 1.
 *
 *   pure      read() takes the file's bytes and returns either
 *               ['ok' => true, 'facts' => …, 'lines' => …, 'warnings' => […]]  or  ['ok' => false, 'code' => …, 'param' => …]
 *             It opens no database, writes no file and logs nothing. php://temp is the only stream it opens.
 *   whole     every rule runs over the whole file before anything is kept; the first failure stops the run, and a refusal
 *             keeps nothing (the store, lib/BankStatementStore.php, decides that; this class only answers).
 *   silent    a refusal NEVER repeats anything from the file: no name, no amount, no description, no reference. `param`
 *             is at most a line number, a row number, a bank line number, a date, a currency code, the last four
 *             digits or a cap — always a string already in migration 088's shape for bank_checks.param
 *             (^[0-9A-Z|-]{0,24}$, PARAM_SHAPE). sentence() renders the fixed sentence for a code and refuses to print
 *             a parameter outside that shape.
 *   private   the full account number never leaves this class: facts carry `last4` only, and the lines carry neither
 *             the account nor the holder's name (both stay inside the kept file, as the bank issued it).
 *   exact     money is integer minor units (cents) from the first digit to the last; there is no float anywhere, and a
 *             non-zero third decimal is refused (amount_scale), never rounded. Dates are DD-MON-YY with upper-case
 *             English months, century 20YY, checked with checkdate(); strtotime() is never used (it reads 32-AUG-26 as
 *             a date in 2032).
 *   ordered   the lines are taken in SEQUENCE_NMBR order, never file order; the chain, the closing and the bank line
 *             numbers are proved in that order.
 *
 * fit() is the overlap and continuity rule against the lines an account already holds. A join between files is proven
 * by a SHARED bank line, never by a balance: a debit and its reversal net to zero, so a balance cannot show they are
 * missing. An overlapping line is compared by VALUE (compare_digest over COMPARED): the bank's padding of the running
 * balance and its spelling of a number (".9" against "0.9") are display only.
 *
 * lib/BankStatement.php is a different reader (file-order chain, strtotime, float) and stays as it is; this one never
 * calls it and it never calls this one.
 *
 * PHP 7.4. The escape character of every fgetcsv() here is '' (RFC 4180): PHP's default backslash merges two fields when
 * a quoted field ends in a backslash. Every pattern that must match a WHOLE value ends in $/D: a bare $ also matches just
 * before a final line feed, so a quoted cell "0000000101<LF>" would pass /^\d{10}$/ while 088's length() and GLOB CHECKs
 * refuse it (a file shown as passed that can never be kept, or a refusal whose check row cannot be written).
 */
final class EcobankDetailsCsv
{
    public const PROFILE = 'ecobank_account_details_csv';
    public const VERSION = 1;
    public const BYTES_MAX = 4194304;   // 4 MiB; the effective cap is also bounded by upload_max_filesize and post_max_size
    public const LINES_MAX = 10000;     // data rows
    public const FIELD_MAX = 1000;      // characters in one field; never truncated
    /** A record the scan counts wider than this is never handed to fgetcsv(): it is a row of the wrong width (BK-SEC-3). */
    public const SCAN_FIELDS = 1000;
    public const HEADER = ['Account','Account Name','Opening Available','Closing Available','SEQUENCE_NMBR','Sr. No.','Posting Date',
        'Type Code','Type Description','Credit','Debit','Running Balance','Value Date','Cust Ref','Bank Ref','Cheque Number',
        'Remarks','Transaction Code','Loan Outstanding Amount','Total Amount','Reference','Customer Reference','Transaction Reference'];
    public const MONTHS = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];

    /** What bank_checks.param may hold (migration 088's CHECK): nothing a statement could carry as text. */
    public const PARAM_SHAPE = '/^[0-9A-Z|-]{0,24}$/D';

    /** Warnings never refuse: every column is kept anyway. Listed on the check panel as codes and stored on the statement. */
    public const WARNINGS = ['dates_not_in_order', 'loan_total_not_zero', 'opening_differs', 'reference_differs', 'refs_not_empty',
        'remarks_differs', 'zero_amount'];

    /** The codes read() and fit() return; the store returns the others in SENTENCES (they need the chosen account). */
    public const READER_CODES = ['empty', 'too_large', 'not_csv', 'summary_export', 'header', 'columns', 'too_many_lines',
        'field_too_long', 'account_mixed', 'currency_form', 'sequence', 'date', 'amount_both_neither', 'amount_form',
        'amount_scale', 'balance_form', 'chain', 'closing', 'lineid_form', 'lineid_duplicate', 'lineid_order'];
    public const FIT_CODES = ['overlap_differs', 'overlap_interleave', 'gap_after', 'gap_before', 'nothing_new'];

    /** not_csv's parameters, upper case so they fit bank_checks.param. */
    public const NOT_CSV = ['PDF' => 'This is a PDF.', 'EXCEL' => 'This is an Excel file.', 'BINARY' => 'This is not a CSV file.',
        'ENCODING' => 'This is not a CSV file.', 'LINE-ENDS' => 'This is not a CSV file.'];

    /**
     * Every outcome's fixed sentence and what to do (SPEC-bank §a.9). {p} is the parameter, rendered by its kind:
     *   mb, n, row, seq   digits          chq    a ten-digit bank line number   last4   four digits
     *   date              an ISO date, shown "31 Aug 2026"                       pair    "USD|UGX": file, then account
     *   kind              not_csv's kind (the sentence is NOT_CSV's)             ''      no parameter
     * already_kept's parameter is optional: the date the file was kept, when the caller has it.
     */
    public const SENTENCES = [
        'not_received'             => ['mb', 'The file did not arrive. It may be larger than {p} MB, or the upload was interrupted.', 'Try again; the limit is shown on the form.'],
        'too_large'                => ['mb', 'This file is larger than {p} MB; nothing was kept.', 'Download a shorter period, if Ecobank allows it.'],
        'empty'                    => ['', 'The file has no transaction lines.', 'Download Account Details again.'],
        'not_csv'                  => ['kind', '', 'In Ecobank online banking, download Account Details as CSV.'],
        'summary_export'           => ['', "This is Ecobank's account summary. It has balances but no transaction lines.", 'Download Account Details for this account instead.'],
        'header'                   => ['', "The columns are not those of Ecobank's Account Details export.", 'Download it again, without opening and saving it in Excel.'],
        'columns'                  => ['row', 'Row {p} of the file does not have 23 columns.', 'Download it again, without opening and saving it in Excel.'],
        'too_many_lines'           => ['n', 'This file has more than {p} lines.', 'Download a shorter period.'],
        'field_too_long'           => ['row', 'Row {p} holds a field longer than the Bank screen keeps.', 'Report it.'],
        'account_mixed'            => ['', 'The file holds more than one account, or an account number that is not 10 digits.', 'Download one account per file.'],
        'currency_form'            => ['', 'The opening and closing balances are not in one currency on every line, or cannot be read.', 'Download it again.'],
        'currency_mismatch'        => ['pair', 'This file is in {a}; the chosen account books {b}.', 'Choose the other account.'],
        'account_other'            => ['last4', "This file is for a different account, ending \u{2022}\u{2022}\u{2022}\u{2022}{p}.", "Choose the right account, or download that account's statement."],
        'account_linked_elsewhere' => ['last4', "The account ending \u{2022}\u{2022}\u{2022}\u{2022}{p} is already linked to another book account.", 'Choose that account.'],
        'account_not_offered'      => ['', 'Choose an active Ecobank account of the book.', ''],
        'sequence'                 => ['', 'Line numbers are missing or repeated: the file is incomplete.', 'Download the whole period again.'],
        'date'                     => ['seq', 'A posting or value date cannot be read, at line {p}.', 'Download it again, without opening it in Excel.'],
        'amount_both_neither'      => ['seq', 'Line {p} has both, or neither, a credit and a debit.', 'Report it.'],
        'amount_form'              => ['seq', 'Line {p} has an amount that cannot be read.', 'Download it again.'],
        'amount_scale'             => ['seq', 'Line {p} has an amount with more than two decimals.', 'Report it; nothing is rounded.'],
        'balance_form'             => ['seq', 'Line {p} has a running balance that cannot be read.', 'Download it again.'],
        'chain'                    => ['seq', 'The running balance does not add up at line {p}: the file is not complete or not intact.', 'Download the whole period again.'],
        'closing'                  => ['', "The last running balance is not the file's Closing Available: the file stops early.", 'Download the whole period again.'],
        'lineid_form'              => ['seq', "The bank's line number is missing or malformed, at line {p}.", 'Report it.'],
        'lineid_duplicate'         => ['seq', "The bank's line number appears twice, at line {p}.", 'Report it.'],
        'lineid_order'             => ['seq', "The bank's line number is out of order, at line {p}.", 'Report it.'],
        'overlap_differs'          => ['chq', 'This file disagrees with a statement already kept, at bank line {p}.', 'Nothing was kept. Report it; do not edit the file.'],
        'overlap_interleave'       => ['chq', 'This file and the kept statements do not hold the same lines around bank line {p}.', 'Nothing was kept. Report it; do not edit the file.'],
        'gap_after'                => ['date', 'This file does not reach back to the last kept line, dated {p}.', 'Download Account Details from {p} or earlier.'],
        'gap_before'               => ['date', 'This file ends before reaching the first kept line, dated {p}.', 'Download a statement that runs to {p} or later.'],
        'already_kept'             => ['date?', 'This exact file was kept on {p}. Nothing changed.', ''],
        'nothing_new'              => ['', 'Every line in this file is already kept. Nothing was added.', ''],
        'link_required'            => ['', 'Confirm which book account this file belongs to before keeping it.', ''],
        'changed_since_check'      => ['', 'The kept statements changed since this check, or the held file changed. Check the file again.', ''],
        'check_expired'            => ['', 'This check is older than two hours. Check the file again.', ''],
    ];
    /** What sentence() says for a code it does not know, or a parameter that does not have its code's shape. */
    public const SENTENCE_FALLBACK = 'This file could not be checked. Nothing was kept. Report it.';

    private static function no(string $code, $param = null): array { return ['ok'=>false, 'code'=>$code, 'param'=>$param === null ? '' : (string)$param]; }

    public static function date(string $s): ?string
    {
        if (!preg_match('/^(\d{2})-(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)-(\d{2})$/D', $s, $m)) return null;
        $d = (int)$m[1]; $mo = self::MONTHS[$m[2]]; $y = 2000 + (int)$m[3];
        if (!checkdate($mo, $d, $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** Credit/Debit: unsigned plain decimal; ".9" allowed; a non-zero third decimal is 'scale'. Integer cents or null. */
    public static function amount(string $s)
    {
        if (!preg_match('/^(\d*)(?:\.(\d+))?$/D', $s, $m) || ($m[1] === '' && !isset($m[2])) || ($m[1] === '' && ($m[2] ?? '') === '')) return null;
        $int = $m[1] === '' ? '0' : $m[1]; $frac = $m[2] ?? '';
        if (strlen($frac) > 2 && trim(substr($frac, 2), '0') !== '') return 'scale';
        $frac = str_pad(substr($frac, 0, 2), 2, '0');
        if (strlen(ltrim($int, '0')) > 15) return null;
        return (int)$int * 100 + (int)$frac;
    }

    /** Running Balance: signed, exactly two decimals, the leading digit optional ("   .50", "-.50"), after trimming spaces. */
    public static function balance(string $s): ?int
    {
        if (!preg_match('/^(-?)(\d{0,15})\.(\d{2})$/D', trim($s, ' '), $m)) return null;
        $v = (int)($m[2] === '' ? '0' : $m[2]) * 100 + (int)$m[3];
        return $m[1] === '-' ? -$v : $v;
    }

    /** "CCY amount": signed, 0–2 decimals, at least one digit overall ("UGX 0", "UGX .00", "UGX 12.5", "UGX -.50"). */
    public static function ccyAmount(string $s): ?array
    {
        $s = trim($s, ' ');
        if (!preg_match('/^([A-Z]{3}) (-?)(\d{0,15})(?:\.(\d{1,2}))?$/D', $s, $m)) return null;
        if ($m[3] === '' && !isset($m[4])) return null;                       // "UGX " or "UGX -" alone
        $v = (int)($m[3] === '' ? '0' : $m[3]) * 100 + (int)str_pad($m[4] ?? '', 2, '0');
        return [$m[1], $m[2] === '-' ? -$v : $v];
    }

    /** The fields an overlapping export must repeat. Numbers by VALUE (units and side), text exactly. */
    public const COMPARED = ['posting_raw','value_raw','type_code','type_desc','side','credit_units','debit_units','running_units',
        'cust_ref','bank_ref','remarks','txn_code','loan_outstanding','total_amount','reference','customer_reference','transaction_reference'];

    /**
     * sha256 over the compared fields in COMPARED order. Recomputable from a stored line row: its side is 'C' when
     * credit_text is not empty, else 'D'; the units read back as integers or digit strings and compare as text.
     */
    public static function compareDigest(array $l): string
    {
        $v = [];
        foreach (self::COMPARED as $f) $v[] = (string)$l[$f];
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false) throw new \RuntimeException('compare digest: a compared value is not valid UTF-8');
        return hash('sha256', $j);
    }

    /** A php.ini quantity in bytes ("2M", "512K", "1G", "8388608"); 0 when it sets no limit or cannot be read. */
    public static function iniBytes(string $v): int
    {
        if (!preg_match('/^\s*(\d{1,12})\s*([KMG]?)\s*$/i', $v, $m)) return 0;
        $n = (int)$m[1];
        $mul = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
        return $n * $mul;
    }

    /** The largest file this installation can receive and read: the smallest of BYTES_MAX and PHP's two upload limits. */
    public static function effectiveCap(): int
    {
        $cap = self::BYTES_MAX;
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $b = self::iniBytes((string)ini_get($k));
            if ($b > 0 && $b < $cap) $cap = $b;
        }
        return $cap;
    }

    /** A cap in whole megabytes, rounded down, as too_large and not_received carry it ("4"). */
    public static function capMb(int $cap): string { return (string)intdiv(max(0, $cap), 1048576); }

    /**
     * The fields read()'s fgetcsv() call returns for the record that starts at byte $p, and the offset of the next
     * record, counted WITHOUT building the record (BK-SEC-3: one cap-sized row of commas is millions of fields, and
     * fgetcsv() would build them all before any rule could refuse it). It follows php_fgetcsv(): at the start of a field,
     * white space before a '"' is skipped and the field is quoted; a quoted field runs to a '"' that is not doubled, a
     * line end inside it continuing the record on the next line; after it, as in an unquoted field, the field runs to the
     * next ',' or the line end. A ',' starts another field; a line end outside quotes ends the record; a blank line is one
     * field. read() has refused a NUL and a bare CR before it calls this. tests/test_bank_reader.php holds the count and
     * the offset equal to fgetcsv()'s own over generated inputs.
     *
     * @return array{0:int,1:int} [fields, offset of the next record]
     */
    private static function width(string $b, int $p, int $n): array
    {
        $f = 1;
        while (true) {
            $q = $p + strspn($b, " \t\x0B\x0C\r", $p);                         // white space before a quote is skipped
            if ($q < $n && $b[$q] === '"') {
                $p = $q + 1;
                while (true) {
                    $e = strpos($b, '"', $p);
                    if ($e === false) return [$f, $n];                           // a quote open to the end of the file
                    if ($e + 1 < $n && $b[$e + 1] === '"') { $p = $e + 2; continue; }  // "" is one quote inside the field
                    $p = $e + 1;
                    break;
                }
            }
            $e = $p + strcspn($b, ",\n", $p);                                   // to the next ',' or the line end
            if ($e >= $n) return [$f, $n];
            if ($b[$e] === "\n") return [$f, $e + 1];
            $run = strspn($b, ',', $e + 1);                                     // a run of ',': one empty field each
            $f += 1 + $run;
            $p = $e + 1 + $run;
        }
    }

    /** Characters in a valid UTF-8 string (read() has proved the encoding before it counts). */
    private static function chars(string $s): int { return (int)preg_match_all('/./su', $s); }

    /**
     * The whole file, every rule of SPEC-bank §a.2–§a.6, first failure first. $cap may only NARROW the effective cap
     * (a test or a caller that already knows a smaller limit); it can never raise it above BYTES_MAX.
     */
    public static function read(string $bytes, ?int $cap = null): array
    {
        $cap = $cap === null ? self::effectiveCap() : min($cap, self::BYTES_MAX);
        $n = strlen($bytes);
        if ($n === 0) return self::no('empty');
        if ($n > $cap) return self::no('too_large', self::capMb($cap));
        if (strncmp($bytes, '%PDF-', 5) === 0) return self::no('not_csv', 'PDF');
        if (strncmp($bytes, "PK\x03\x04", 4) === 0 || strncmp($bytes, "\xD0\xCF\x11\xE0", 4) === 0) return self::no('not_csv', 'EXCEL');
        if (strpos($bytes, "\0") !== false) return self::no('not_csv', 'BINARY');
        if (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) $bytes = substr($bytes, 3);
        if (!preg_match('//u', $bytes)) return self::no('not_csv', 'ENCODING');
        if (preg_match("/\r(?!\n)/", $bytes)) return self::no('not_csv', 'LINE-ENDS');

        $fh = fopen('php://temp', 'w+'); fwrite($fh, $bytes); rewind($fh);
        $n = strlen($bytes);
        // One record at a time (BK-SEC-3). The outcome is the one of SPEC-bank §a.2–§a.3, in its order: too many lines
        // first (so the whole file is counted), then the header, then the first data row that is blank, of the wrong width
        // or holds a field too long. A record is KEPT only while every rule so far holds; once the outcome is a refusal,
        // the rest of the file is only counted. A blank line is a counter, never an entry. A record the scan counts wider
        // than SCAN_FIELDS is never parsed: it stands as [] (a width that is neither the header's 23 nor the summary's 10),
        // refused exactly where the parsed record would have been.
        $head = null; $rows = []; $real = 0; $k = -1; $last = 0; $blank = null; $bad = null; $keep = true;
        while (($pos = (int)ftell($fh)) < $n) {
            [$w, $end] = self::width($bytes, $pos, $n);
            if ($w > self::SCAN_FIELDS) {
                $r = [];
                fseek($fh, $end);
            } elseif (($r = fgetcsv($fh, 0, ',', '"', '')) === false) {
                break;
            }
            $k++;
            if ($r === [null]) { if ($blank === null) $blank = $k; continue; }     // a blank line: refused only if a record follows
            if (++$real > self::LINES_MAX + 1) { fclose($fh); return self::no('too_many_lines', self::LINES_MAX); }
            if ($blank !== null) {                                              // the blank lines before this record are not at the end
                if ($blank === 0) $keep = false;                                 // before the header: refused as header, below
                elseif ($bad === null) $bad = ['columns', $blank + 1];
                $blank = null;
            }
            if ($k === 0) {
                $head = array_map(function ($v) { return trim((string)$v); }, $r);
                if ($head !== self::HEADER) $keep = false;                       // summary_export or header, below
                continue;
            }
            $last = $k;
            if (!$keep || $bad !== null) continue;
            if (count($r) !== 23) { $bad = ['columns', $k + 1]; continue; }
            foreach ($r as $f) if (strlen($f) > self::FIELD_MAX && self::chars($f) > self::FIELD_MAX) { $bad = ['field_too_long', $k + 1]; continue 2; }
            $rows[] = $r;
        }
        fclose($fh); unset($bytes);
        if ($real === 0) return self::no('empty');
        if ($head === null) return self::no('header');                         // a blank line before the header
        if (count($head) === 10) return self::no('summary_export');
        if ($head !== self::HEADER) return self::no('header');
        if ($last === 0) return self::no('empty');
        if ($last > self::LINES_MAX) return self::no('too_many_lines', self::LINES_MAX);   // data rows, blank lines between them counted
        if ($bad !== null) return self::no($bad[0], $bad[1]);
        $data = $rows; unset($rows);
        $acct = $data[0][0]; $name = $data[0][1]; $oa = $data[0][2]; $ca = $data[0][3];
        foreach ($data as $r) {
            if ($r[0] !== $acct || $r[1] !== $name) return self::no('account_mixed');
            if ($r[2] !== $oa || $r[3] !== $ca) return self::no('currency_form');
        }
        if (!preg_match('/^\d{10}$/D', $acct) || trim($name) === '') return self::no('account_mixed');
        $o = self::ccyAmount($oa); $c = self::ccyAmount($ca);
        if ($o === null || $c === null || $o[0] !== $c[0]) return self::no('currency_form');

        $bySeq = [];
        foreach ($data as $i => $r) {
            if (!preg_match('/^[1-9]\d{0,6}$/D', $r[4])) return self::no('sequence');
            $s = (int)$r[4];
            if (isset($bySeq[$s]) || $s > count($data)) return self::no('sequence');
            $bySeq[$s] = $i;
        }
        ksort($bySeq);
        $orderDiffers = array_values($bySeq) !== array_keys($data);

        $lines = []; $warn = []; $prevRun = null; $prevChq = null; $seenChq = []; $opening = null;
        $cr = [0, 0]; $dr = [0, 0];
        foreach ($bySeq as $s => $i) {
            $r = $data[$i];
            if (!preg_match('/^\d{10}$/D', $r[15])) return self::no('lineid_form', $s);
            if (isset($seenChq[$r[15]])) return self::no('lineid_duplicate', $s);
            if ($prevChq !== null && strcmp($r[15], $prevChq) <= 0) return self::no('lineid_order', $s);
            $seenChq[$r[15]] = true; $prevChq = $r[15];
            $pd = self::date($r[6]); $vd = self::date($r[12]);
            if ($pd === null || $vd === null) return self::no('date', $s);
            $ct = trim($r[9], ' '); $dt = trim($r[10], ' ');                       // ONE rule: the trimmed text, everywhere
            if (($ct === '') === ($dt === '')) return self::no('amount_both_neither', $s);
            $amt = self::amount($ct !== '' ? $ct : $dt);
            if ($amt === 'scale') return self::no('amount_scale', $s);
            if ($amt === null) return self::no('amount_form', $s);
            $run = self::balance($r[11]);
            if ($run === null) return self::no('balance_form', $s);
            $cu = $ct !== '' ? $amt : 0; $du = $dt !== '' ? $amt : 0;
            if ($prevRun !== null && $run !== $prevRun + $cu - $du) return self::no('chain', $s);
            if ($prevRun === null) $opening = $run - $cu + $du;
            $prevRun = $run;
            if ($cu) { $cr[0]++; $cr[1] += $cu; } elseif ($du) { $dr[0]++; $dr[1] += $du; } else { $warn['zero_amount'] = true; }
            if ($r[13] !== '' || $r[14] !== '') $warn['refs_not_empty'] = true;
            if ($r[18] !== '0' || $r[19] !== '0') $warn['loan_total_not_zero'] = true;
            if ($r[20] !== $r[21]) $warn['reference_differs'] = true;
            if ($r[16] !== $r[22]) $warn['remarks_differs'] = true;
            $l = ['seq'=>$s, 'file_row'=>$i + 2, 'sr_no'=>$r[5], 'posting_date'=>$pd, 'posting_raw'=>$r[6],
                'value_date'=>$vd, 'value_raw'=>$r[12], 'type_code'=>$r[7], 'type_desc'=>$r[8],
                'side'=>$ct !== '' ? 'C' : 'D', 'credit_text'=>$ct, 'debit_text'=>$dt, 'credit_units'=>$cu, 'debit_units'=>$du,
                'running_text'=>$r[11], 'running_units'=>$run, 'cust_ref'=>$r[13], 'bank_ref'=>$r[14],
                'cheque_number'=>$r[15], 'remarks'=>$r[16], 'txn_code'=>$r[17], 'loan_outstanding'=>$r[18],
                'total_amount'=>$r[19], 'reference'=>$r[20], 'customer_reference'=>$r[21], 'transaction_reference'=>$r[22]];
            $l['compare_digest'] = self::compareDigest($l);
            $lines[] = $l;
        }
        unset($data);
        if ($prevRun !== $c[1]) return self::no('closing');
        if ($opening !== $o[1]) $warn['opening_differs'] = true;
        $dates = array_column($lines, 'posting_date');
        $sorted = $dates; sort($sorted);
        if ($sorted !== $dates) $warn['dates_not_in_order'] = true;
        $warnings = array_keys($warn); sort($warnings);
        return ['ok'=>true, 'lines'=>$lines, 'warnings'=>$warnings, 'facts'=>[
            'last4'=>substr($acct, -4), 'currency'=>$o[0], 'opening_stated'=>$o[1], 'closing_stated'=>$c[1],
            'opening_derived'=>$opening, 'closing_derived'=>$prevRun, 'line_count'=>count($lines),
            'period_from'=>min($dates), 'period_to'=>max($dates), 'file_order_differs'=>$orderDiffers,
            'credits'=>$cr, 'debits'=>$dr, 'cheque_first'=>$lines[0]['cheque_number'], 'cheque_last'=>$prevChq]];
    }

    /**
     * Overlap and continuity. $known: cheque_number => compare_digest for the account's kept lines between the file's
     * first and last line, and no others (the store reads only those two columns); $first/$last: the account's first and
     * last kept lines (cheque_number, posting_date, running_units, credit_units, debit_units), or null when nothing is
     * kept. $fileLines are read()'s lines, in bank order.
     * A join is proven by a SHARED bank line, never by a balance alone: a file that adds lines after the kept ones
     * must contain the last kept line; one that adds lines before them must contain the first kept line.
     * Returns ['ok' => true, 'new' => the lines to add, 'known' => how many were already kept] or a refusal.
     */
    public static function fit(array $fileLines, array $known, ?array $first, ?array $last): array
    {
        if ($first === null || $last === null) return ['ok'=>true, 'new'=>$fileLines, 'known'=>0];
        $kMin = $first['cheque_number']; $kMax = $last['cheque_number'];
        $new = []; $nKnown = 0; $inFile = [];
        foreach ($fileLines as $l) {
            $q = $l['cheque_number']; $inFile[$q] = true;
            if (isset($known[$q])) {
                if ($known[$q] !== $l['compare_digest']) return self::no('overlap_differs', $q);
                $nKnown++;
            } elseif (strcmp($q, $kMin) >= 0 && strcmp($q, $kMax) <= 0) {
                return self::no('overlap_interleave', $q);
            } else {
                $new[] = $l;
            }
        }
        foreach ($known as $q => $_) if (!isset($inFile[$q])) return self::no('overlap_interleave', $q);
        if (!$new) return self::no('nothing_new');
        $after  = strcmp($new[count($new) - 1]['cheque_number'], $kMax) > 0;
        $before = strcmp($new[0]['cheque_number'], $kMin) < 0;
        if ($after && !isset($inFile[$kMax])) return self::no('gap_after', $last['posting_date']);
        if ($before && !isset($inFile[$kMin])) return self::no('gap_before', $first['posting_date']);
        // With the shared line present and the file's own chain proven, the balances join by construction; assert it.
        foreach ($fileLines as $l) {
            if ($l['cheque_number'] === $kMax && $l['running_units'] !== $last['running_units']) return self::no('overlap_differs', $kMax);
            if ($l['cheque_number'] === $kMin && $l['running_units'] !== $first['running_units']) return self::no('overlap_differs', $kMin);
        }
        return ['ok'=>true, 'new'=>$new, 'known'=>$nKnown];
    }

    /** True when $p may be stored as bank_checks.param (and so may be shown). */
    public static function paramFits(string $p): bool { return preg_match(self::PARAM_SHAPE, $p) === 1; }

    /**
     * The fixed sentence for an outcome, then what to do. Only the parameter's own shape is ever printed: anything else
     * gives SENTENCE_FALLBACK, which carries nothing at all. Plain text: the screen escapes it.
     */
    public static function sentence(string $code, string $param = ''): string
    {
        if (!isset(self::SENTENCES[$code])) return self::SENTENCE_FALLBACK;
        if (!self::paramFits($param)) return self::SENTENCE_FALLBACK;
        [$kind, $text, $todo] = self::SENTENCES[$code];
        $p = null; $a = null; $b = null;
        if ($kind === 'kind') {
            if (!isset(self::NOT_CSV[$param])) return self::SENTENCE_FALLBACK;
            $text = self::NOT_CSV[$param];
        } elseif ($kind === 'mb' || $kind === 'n' || $kind === 'row' || $kind === 'seq') {
            if (!preg_match('/^\d{1,9}$/D', $param)) return self::SENTENCE_FALLBACK;
            $p = $param;
        } elseif ($kind === 'chq') {
            if (!preg_match('/^\d{10}$/D', $param)) return self::SENTENCE_FALLBACK;
            $p = $param;
        } elseif ($kind === 'last4') {
            if (!preg_match('/^\d{4}$/D', $param)) return self::SENTENCE_FALLBACK;
            $p = $param;
        } elseif ($kind === 'pair') {
            if (!preg_match('/^([A-Z]{3})\|([A-Z]{3})$/D', $param, $m)) return self::SENTENCE_FALLBACK;
            $a = $m[1]; $b = $m[2];
        } elseif ($kind === 'date' || $kind === 'date?') {
            if ($kind === 'date?' && $param === '') {
                $text = str_replace(' on {p}', '', $text);                        // "This exact file was kept. Nothing changed."
            } else {
                $p = self::showDate($param);
                if ($p === null) return self::SENTENCE_FALLBACK;
            }
        } elseif ($param !== '') {
            return self::SENTENCE_FALLBACK;                                       // a code that carries nothing was given something
        }
        $out = trim($text . ' ' . $todo);
        if ($p !== null) $out = str_replace('{p}', $p, $out);
        if ($a !== null) $out = str_replace(['{a}', '{b}'], [$a, $b], $out);
        return $out;
    }

    /** "2026-08-31" → "31 Aug 2026"; null for anything that is not a real ISO date. No timezone is involved. */
    private static function showDate(string $iso): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $iso, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        $mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][(int)$m[2] - 1];
        return $m[3] . ' ' . $mon . ' ' . $m[1];
    }
}
