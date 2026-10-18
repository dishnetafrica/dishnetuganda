<?php
declare(strict_types=1);
/**
 * BankStatementStore — check, then keep, Ecobank statements for one book account (5.18.97, the Bank screen v1; Uganda
 * only; SPEC-bank §a.7, §a.8, §a.10, §b.2–§b.4). It writes the five bank_* tables of migration 088 and the files under
 * <dataDir>/bank_statements/, and nothing else: it never constructs CashbookService, never reads or writes cb_ledger,
 * and its only read of an existing table is one SELECT of cb_accounts, made after sqlite_master says the table exists.
 *
 *   check($account, $bytes, $actor)   C1 (the account is offered) before the file is read; then the reader
 *                                      (lib/EcobankDetailsCsv.php, every rule of §a.2–§a.6), C2–C5 and the overlap rule
 *                                      (§a.8), all before any write. A refusal or a note is one closed bank_checks row and
 *                                      its event; a passed file is held, 0600, in bank_statements/.pending/ and its check
 *                                      waits. Nothing is kept.
 *   keep($check, $account, $link, …)  housekeeping; the check must wait, for this account, inside the TTL; the held file is
 *                                      read through realpath containment and must still have the check's sha256; every
 *                                      check runs again; the held file is COPIED to this attempt's own final name (a
 *                                      random token in it, so two keeps of one check never share a file); BEGIN
 *                                      IMMEDIATE, the profile and the kept lines are re-read and must give the check's
 *                                      counts; the
 *                                      profile (first file), the statement, its lines and the events are written; COMMIT;
 *                                      only then is the held file deleted, and with it any other attempt's copy of this
 *                                      check. A failing attempt deletes only its own copy. A process that dies at any point
 *                                      leaves nothing that cannot be retried: at worst a copy of its own that no row names
 *                                      (orphanFiles()), which the next keep of that check to commit removes.
 *   discard($check, $account, …)      a waiting check is discarded and its held file deleted.
 *   housekeeping()                    waiting checks past PENDING_TTL expire (a system event) and held files whose check
 *                                      no longer waits are deleted. Every POST act runs it first; a GET never writes.
 *   latestLine() lines() lineCount()  the reads of the screen (tabs/accounts/bank.php) and of its export (the routes
 *   keptRange() monthStrip()           hunk): SELECTs of the bank_* tables only, in the bank's own order (Cheque
 *   checksFor() statement()            Number), each balance the bank's own. They write nothing and recompute no
 *   waitingCount()                     balance; the export and the screen read the same lines through lines().
 *
 * Joins are proven by a SHARED bank line (EcobankDetailsCsv::fit()), never by a balance. The store reads for that only
 * two columns of the kept lines inside the file's range (cheque_number, compare_digest) and five fields of the account's
 * first and last kept lines: the kept lines are never held whole.
 *
 * Statement text lives in two places only: the bank_statement_lines rows and the kept (or held) file. No check row, event,
 * exception message or log line carries a cell, a name, an amount or the full account number: a check row holds codes,
 * counts, dates, a sha256, a currency and the last four digits. The original file name is never read. This class calls
 * no error_log().
 *
 * Files: <dataDir>/bank_statements/<cb_accounts.id>/<check id>-<sha256 first 16>-<16 hex random>.csv for a kept statement
 * (the random part is the keep attempt's: BK97-R1) and
 * bank_statements/.pending/<check id>-<16 hex random>.csv while a check waits. Folders 0700, files 0600, set with chmod
 * after writing; written under a temporary name in the same folder and renamed into place. Not under uploads/, so in no
 * in-app backup. No route serves them in v1.
 *
 * PHP 7.4. The clock and a step probe can be given in $opt for tests: 'clock' => callable(): int, 'probe' =>
 * callable(string $step) called at 'after_copy' and 'before_commit'. Production gives neither.
 */
final class BankStatementStore
{
    /** bank_account_profiles.bank and .profile carry no CHECK (088 rule M4): the lists live here, asserted by the tests. */
    public const BANKS    = ['ecobank'];
    public const PROFILES = ['ecobank_account_details_csv'];
    /** bank_line_events.event (no CHECK, M4). */
    public const EVENTS = ['statement_checked', 'account_linked', 'statement_kept', 'keep_refused', 'check_discarded',
        'check_expired', 'lines_exported'];
    /** bank_statement_lines.status: v1 writes 'imported' and never updates it (P3c gives it a lifecycle). */
    public const LINE_STATUSES = ['imported'];
    public const TABLES = ['bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events'];
    /** The codes this class returns; the reader's and fit()'s are EcobankDetailsCsv::READER_CODES and FIT_CODES. */
    public const STORE_CODES = ['not_ready', 'not_received', 'account_not_offered', 'currency_mismatch', 'account_other',
        'account_linked_elsewhere', 'already_kept', 'link_required', 'changed_since_check', 'check_expired'];

    /** A waiting check, and its held file, live this long (seconds). */
    public const PENDING_TTL = 7200;
    public const DIR       = 'bank_statements';
    public const PENDING   = '.pending';
    public const DIR_MODE  = 0700;
    public const FILE_MODE = 0600;

    private const HELD_NAME  = '/^\d{1,12}-[0-9a-f]{16}\.csv$/';
    private const KEPT_NAME  = '/^\d{1,12}-[0-9a-f]{16}-[0-9a-f]{16}\.csv$/D';
    private const TEMP_NAME  = '/^\.tmp-[0-9a-f]{32}$/';
    private const LINE_COLUMNS = ['statement_id', 'cb_account_id', 'cheque_number', 'seq_nmbr', 'sr_no', 'file_row', 'posting_date',
        'value_date', 'posting_raw', 'value_raw', 'type_code', 'type_desc', 'txn_code', 'credit_text', 'debit_text', 'credit_units',
        'debit_units', 'running_text', 'running_units', 'currency', 'cust_ref', 'bank_ref', 'remarks', 'loan_outstanding',
        'total_amount', 'reference', 'customer_reference', 'transaction_reference', 'fingerprint', 'compare_digest', 'warnings',
        'status', 'created_at'];

    /** @var \PDO */
    private $pdo;
    /** @var string */
    private $dataDir;
    /** @var string[] the book's currencies, in the book's order (the base first) */
    private $currencies;
    /** @var callable */
    private $clock;
    /** @var callable|null */
    private $probe;
    /** @var bool|null */
    private $ready = null;
    /** @var bool */
    private $inTxn = false;

    /**
     * @param \PDO     $pdo            the plugin store's connection ($store->getPdo())
     * @param string   $dataDir        getDataDir()'s directory, never a path under the code
     * @param string[] $bookCurrencies dn_book_currencies() of the book's configuration
     */
    public function __construct(\PDO $pdo, string $dataDir, array $bookCurrencies, array $opt = [])
    {
        $dataDir = rtrim($dataDir, '/');
        if ($dataDir === '') throw new \InvalidArgumentException('BankStatementStore: a data directory is required');
        if (!class_exists('EcobankDetailsCsv')) require_once __DIR__ . '/EcobankDetailsCsv.php';
        $this->pdo = $pdo;
        $this->dataDir = $dataDir;
        $list = [];
        foreach ($bookCurrencies as $c) {
            $c = strtoupper(trim((string)$c));
            if (preg_match('/^[A-Z]{3}$/', $c) && !in_array($c, $list, true)) $list[] = $c;
        }
        $this->currencies = $list;
        $this->clock = (isset($opt['clock']) && is_callable($opt['clock'])) ? $opt['clock'] : function (): int { return time(); };
        $this->probe = (isset($opt['probe']) && is_callable($opt['probe'])) ? $opt['probe'] : null;
    }

    // ── What exists ─────────────────────────────────────────────────────────────

    /** Migration 088 is applied: the five tables exist. Without them the Bank screen fails closed. */
    public function ready(): bool
    {
        if ($this->ready === null) {
            $st = $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('"
                . implode("', '", self::TABLES) . "')");
            $this->ready = (int)$st->fetchColumn() === count(self::TABLES);
            $st->closeCursor();
        }
        return $this->ready;
    }

    /**
     * C1: the book accounts a statement may be checked against — active cb_accounts rows of kind 'bank' whose currency is
     * one of the book's, in the book's currency order, then by id. [] when cb_accounts does not exist (it is created by
     * CashbookService at runtime, not by a migration). The one SELECT of an existing table this class makes.
     *
     * @return array<int,array{id:int,name:string,currency:string}>
     */
    public function offeredAccounts(): array
    {
        if (!$this->tableExists('cb_accounts')) return [];
        $order = array_flip($this->currencies);
        $out = [];
        $st = $this->pdo->query("SELECT id, name, currency, kind, active FROM cb_accounts WHERE kind = 'bank' AND active = 1");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $cur = strtoupper(trim((string)$r['currency']));
            if ((string)$r['kind'] !== 'bank' || (int)$r['active'] !== 1 || !isset($order[$cur])) continue;
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'currency' => $cur];
        }
        usort($out, function (array $a, array $b) use ($order): int {
            return [$order[$a['currency']], $a['id']] <=> [$order[$b['currency']], $b['id']];
        });
        return $out;
    }

    /** One offered account, or null (C1). */
    public function account(int $id): ?array
    {
        foreach ($this->offeredAccounts() as $a) if ($a['id'] === $id) return $a;
        return null;
    }

    /**
     * Whether the book's name for an account says Ecobank: the cards' "ECO BANK <CUR>" heading, and 5.18.98's R3. "Eco"
     * must start a word, so a name holding "eco bank" only inside a longer word ("Deco-Bank") is not Ecobank's.
     */
    public static function ecobankNamed(string $name): bool
    {
        return preg_match('/\beco[\s-]*bank/i', $name) === 1;
    }

    /** The account's confirmed Ecobank link, or null before its first kept file. */
    public function profile(int $accountId): ?array
    {
        if (!$this->ready()) return null;
        $st = $this->pdo->prepare('SELECT * FROM bank_account_profiles WHERE cb_account_id = ?');
        $st->execute([$accountId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r ?: null;
    }

    public function checkRow(int $checkId): ?array
    {
        if ($checkId <= 0 || !$this->ready()) return null;
        $st = $this->pdo->prepare('SELECT * FROM bank_checks WHERE id = ?');
        $st->execute([$checkId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r ?: null;
    }

    /** A waiting check older than the TTL. The screen shows it as expired without writing; the next POST expires it. */
    public function isExpired(array $check): bool
    {
        return (string)($check['status'] ?? '') === 'waiting' && (string)($check['checked_at'] ?? '') <= $this->cutoff();
    }

    // ── The upload ──────────────────────────────────────────────────────────────

    /**
     * One $_FILES entry as bytes, or a refusal that writes nothing (no row: nothing arrived to record). The upload errors
     * are mapped as lib/JobPhotos.php maps them; the file must be an uploaded file; its NAME is never read.
     *
     * @return array{ok:bool, bytes?:string, code?:string, param?:string}
     */
    public static function uploaded(array $file): array
    {
        if (!class_exists('EcobankDetailsCsv')) require_once __DIR__ . '/EcobankDetailsCsv.php';
        $cap = EcobankDetailsCsv::effectiveCap();
        $mb  = EcobankDetailsCsv::capMb($cap);
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return self::no('too_large', $mb);
        if ($err !== UPLOAD_ERR_OK) return self::no('not_received', $mb);
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return self::no('not_received', $mb);
        $size = @filesize($tmp);
        if (!is_int($size)) return self::no('not_received', $mb);
        if ($size > $cap) return self::no('too_large', $mb);
        $bytes = @file_get_contents($tmp);
        if (!is_string($bytes) || strlen($bytes) !== $size) return self::no('not_received', $mb);
        return ['ok' => true, 'bytes' => $bytes];
    }

    // ── Check ───────────────────────────────────────────────────────────────────

    /**
     * Check one file against one account. Nothing is kept. Returns
     *   ['ok' => true, 'check_id', 'outcome' => 'passed', 'lines_new', 'lines_known', 'needs_link']   (the check waits)
     *   ['ok' => false, 'code', 'param', 'check_id', 'outcome', 'note']                                  (a closed check)
     *   ['ok' => false, 'code', 'param', 'check_id' => 0]   not_ready / account_not_offered: nothing written
     *
     * @param array $actor the FRESH staff record (BankGate::allows); its id is the actor of every row written
     */
    public function check(int $accountId, string $bytes, array $actor): array
    {
        $by = self::actorId($actor);
        if (!$this->ready()) return self::no('not_ready');
        $acct = $this->account($accountId);
        if ($acct === null) return self::no('account_not_offered');               // C1, before the file is read
        $this->housekeeping();
        $sha = hash('sha256', $bytes);
        $ev = $this->evaluate($acct, $bytes, $sha);
        $row = $this->checkFields($acct, $sha, strlen($bytes), $ev, $by);
        if ($ev['outcome'] !== 'passed') {
            $id = $this->recordClosed($row, $ev);
            return ['ok' => false, 'code' => $ev['code'], 'param' => $ev['param'], 'check_id' => $id, 'outcome' => $ev['outcome'],
                'note' => $ev['outcome'] !== 'refused'];
        }
        $id = $this->recordWaiting($row, $bytes, $ev);
        return ['ok' => true, 'code' => '', 'param' => '', 'check_id' => $id, 'outcome' => 'passed',
            'lines_new' => count($ev['new']), 'lines_known' => (int)$ev['known'], 'needs_link' => $ev['profile'] === null];
    }

    /**
     * Everything that decides a file, in SPEC-bank's order, with no write: the reader (§a.2–§a.6), C2 the currency, C3 the
     * profile's last four, C4 not linked to another account, C5 not already kept, C6 the overlap (§a.8).
     */
    private function evaluate(array $acct, string $bytes, string $sha): array
    {
        $ev = ['outcome' => 'refused', 'code' => '', 'param' => '', 'facts' => [], 'warnings' => [], 'lines' => [], 'new' => [],
            'known' => 0, 'profile' => null, 'statement_id' => null];
        $r = EcobankDetailsCsv::read($bytes);
        if (!$r['ok']) { $ev['code'] = (string)$r['code']; $ev['param'] = (string)$r['param']; return $ev; }
        $f = $r['facts'];
        $ev['facts'] = $f;
        $ev['warnings'] = $r['warnings'];
        $profile = $this->profile($acct['id']);
        $ev['profile'] = $profile;
        if ($f['currency'] !== $acct['currency']) return self::refusedAs($ev, 'currency_mismatch', $f['currency'] . '|' . $acct['currency']);
        if ($profile !== null) {
            if ((string)$profile['currency'] !== $f['currency']) return self::refusedAs($ev, 'currency_mismatch', $f['currency'] . '|' . (string)$profile['currency']);
            if ((string)$profile['acct_last4'] !== $f['last4']) return self::refusedAs($ev, 'account_other', $f['last4']);
        } elseif ($this->linkedElsewhere($acct['id'], $f['currency'], $f['last4'])) {
            return self::refusedAs($ev, 'account_linked_elsewhere', $f['last4']);
        }
        $kept = $this->keptStatement($acct['id'], $sha);
        if ($kept !== null) {
            $ev['outcome'] = 'already_kept'; $ev['code'] = 'already_kept';
            $ev['param'] = substr((string)$kept['kept_at'], 0, 10); $ev['statement_id'] = (int)$kept['id'];
            $ev['known'] = (int)$f['line_count'];
            return $ev;
        }
        [$known, $first, $last] = $this->view($acct['id'], (string)$f['cheque_first'], (string)$f['cheque_last']);
        $fit = EcobankDetailsCsv::fit($r['lines'], $known, $first, $last);
        unset($known);
        if (!$fit['ok']) {
            $ev['outcome'] = $fit['code'] === 'nothing_new' ? 'nothing_new' : 'refused';
            $ev['code'] = (string)$fit['code']; $ev['param'] = (string)$fit['param'];
            if ($fit['code'] === 'nothing_new') $ev['known'] = (int)$f['line_count'];
            return $ev;
        }
        $ev['outcome'] = 'passed';
        $ev['lines'] = $r['lines'];
        $ev['new'] = $fit['new'];
        $ev['known'] = (int)$fit['known'];
        return $ev;
    }

    private static function refusedAs(array $ev, string $code, string $param): array
    {
        $ev['outcome'] = 'refused'; $ev['code'] = $code; $ev['param'] = $param;
        return $ev;
    }

    /**
     * What fit() needs, and nothing more: cheque_number => compare_digest of the account's kept lines between the file's
     * first and last bank line, and five fields of the account's first and last kept lines (null when nothing is kept).
     */
    private function view(int $accountId, string $fileFirst, string $fileLast): array
    {
        $ends = $this->pdo->prepare('SELECT cheque_number, posting_date, running_units, credit_units, debit_units FROM bank_statement_lines'
            . ' WHERE cb_account_id = ? ORDER BY cheque_number ASC LIMIT 1');
        $ends->execute([$accountId]);
        $first = $ends->fetch(\PDO::FETCH_ASSOC);
        $ends->closeCursor();
        if (!$first) return [[], null, null];
        $ends = $this->pdo->prepare('SELECT cheque_number, posting_date, running_units, credit_units, debit_units FROM bank_statement_lines'
            . ' WHERE cb_account_id = ? ORDER BY cheque_number DESC LIMIT 1');
        $ends->execute([$accountId]);
        $last = $ends->fetch(\PDO::FETCH_ASSOC);
        $ends->closeCursor();
        $st = $this->pdo->prepare('SELECT cheque_number, compare_digest FROM bank_statement_lines'
            . ' WHERE cb_account_id = ? AND cheque_number BETWEEN ? AND ?');
        $st->execute([$accountId, $fileFirst, $fileLast]);
        $known = [];
        while (($k = $st->fetch(\PDO::FETCH_NUM)) !== false) $known[(string)$k[0]] = (string)$k[1];
        $st->closeCursor();
        return [$known, self::edge($first), self::edge($last)];
    }

    private static function edge(array $r): array
    {
        return ['cheque_number' => (string)$r['cheque_number'], 'posting_date' => (string)$r['posting_date'],
            'running_units' => (int)$r['running_units'], 'credit_units' => (int)$r['credit_units'], 'debit_units' => (int)$r['debit_units']];
    }

    private function linkedElsewhere(int $accountId, string $currency, string $last4): bool
    {
        $st = $this->pdo->prepare('SELECT cb_account_id FROM bank_account_profiles WHERE bank = ? AND currency = ? AND acct_last4 = ?'
            . ' AND cb_account_id <> ? LIMIT 1');
        $st->execute([self::BANKS[0], $currency, $last4, $accountId]);
        $r = $st->fetchColumn();
        $st->closeCursor();
        return $r !== false;
    }

    private function keptStatement(int $accountId, string $sha): ?array
    {
        $st = $this->pdo->prepare('SELECT id, kept_at FROM bank_statements WHERE cb_account_id = ? AND sha256 = ?');
        $st->execute([$accountId, $sha]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r ?: null;
    }

    /** The bank_checks columns that describe what a check found (fixed by trigger once written). */
    private function checkFields(array $acct, string $sha, int $size, array $ev, int $by): array
    {
        $f = $ev['facts'];
        return [
            'cb_account_id' => $acct['id'], 'sha256' => $sha, 'bytes' => $size,
            'profile' => EcobankDetailsCsv::PROFILE, 'parser_version' => EcobankDetailsCsv::VERSION,
            'outcome' => $ev['outcome'], 'code' => $ev['code'], 'param' => $ev['param'],
            'currency' => (string)($f['currency'] ?? ''), 'acct_last4' => (string)($f['last4'] ?? ''),
            'line_count' => (int)($f['line_count'] ?? 0), 'lines_new' => count($ev['new']), 'lines_known' => (int)$ev['known'],
            'period_from' => (string)($f['period_from'] ?? ''), 'period_to' => (string)($f['period_to'] ?? ''),
            'opening_stated' => $f['opening_stated'] ?? null, 'closing_stated' => $f['closing_stated'] ?? null,
            'opening_derived' => $f['opening_derived'] ?? null,
            'credit_count' => (int)($f['credits'][0] ?? 0), 'credit_units' => (int)($f['credits'][1] ?? 0),
            'debit_count' => (int)($f['debits'][0] ?? 0), 'debit_units' => (int)($f['debits'][1] ?? 0),
            'warnings' => implode(',', $ev['warnings']),
            'checked_by_id' => $by, 'checked_at' => $this->stamp(),
        ];
    }

    /** What statement_checked records: codes and counts, never a cell. */
    private static function checkDetail(array $row, array $ev): array
    {
        return ['outcome' => $row['outcome'], 'code' => $row['code'], 'param' => $row['param'], 'sha256' => $row['sha256'],
            'bytes' => $row['bytes'], 'lines' => $row['line_count'], 'new' => $row['lines_new'], 'known' => $row['lines_known'],
            'warnings' => array_values($ev['warnings'])];
    }

    /** A refusal or a note: one closed check row and its event, in one transaction. */
    private function recordClosed(array $row, array $ev): int
    {
        $row += ['pending_rel' => '', 'status' => 'closed', 'closed_by_id' => $row['checked_by_id'], 'closed_at' => $row['checked_at'],
            'statement_id' => $ev['statement_id']];
        try {
            $this->begin();
            $id = $this->insert('bank_checks', $row);
            $this->event('statement_checked', $row['cb_account_id'], $id, $ev['statement_id'], $row['checked_by_id'], self::checkDetail($row, $ev));
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $this->failure('check', $e);
        }
        return $id;
    }

    /**
     * A passed check: the bytes are held in .pending/ under a name the server makes from the check id, and the check row
     * that names them waits. The row's id is taken inside BEGIN IMMEDIATE, so the name can be part of the row (pending_rel
     * is fixed once written) and no other writer can take the id meanwhile.
     */
    private function recordWaiting(array $row, string $bytes, array $ev): int
    {
        $dir = $this->folder(self::PENDING);
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(16));
        $this->writeFile($tmp, $bytes);
        $placed = '';
        try {
            $this->begin();
            $id = $this->nextCheckId();
            $name = $id . '-' . bin2hex(random_bytes(8)) . '.csv';
            if (!@rename($tmp, $dir . '/' . $name)) throw new \RuntimeException('BankStatementStore: the held file could not be put in place');
            $placed = $dir . '/' . $name;
            $row = ['id' => $id] + $row + ['pending_rel' => self::DIR . '/' . self::PENDING . '/' . $name, 'status' => 'waiting',
                'closed_by_id' => null, 'closed_at' => null, 'statement_id' => null];
            if ($this->insert('bank_checks', $row) !== $id) throw new \RuntimeException('BankStatementStore: the check id was not the one reserved');
            $this->event('statement_checked', $row['cb_account_id'], $id, null, $row['checked_by_id'], self::checkDetail($row, $ev));
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            @unlink($tmp);
            if ($placed !== '') @unlink($placed);
            throw $this->failure('check', $e);
        }
        return $id;
    }

    private function nextCheckId(): int
    {
        $st = $this->pdo->query("SELECT MAX(COALESCE((SELECT seq FROM sqlite_sequence WHERE name = 'bank_checks'), 0),"
            . ' COALESCE((SELECT MAX(id) FROM bank_checks), 0)) + 1');
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    // ── Keep ────────────────────────────────────────────────────────────────────

    /**
     * Keep a waiting check's file (SPEC-bank §a.10, steps 1–9). $link must be true on the account's first file: it is the
     * admin's confirmation of the link the check showed (last four and currency, from the bank's own file). Returns
     *   ['ok' => true, 'statement_id', 'lines_added', 'lines_known', 'linked']
     *   ['ok' => false, 'code', 'param', 'note']   already_kept is a note (a double click, a retry, a second session)
     */
    public function keep(int $checkId, int $accountId, bool $link, array $actor): array
    {
        $by = self::actorId($actor);
        if (!$this->ready()) return self::no('not_ready');
        $acct = $this->account($accountId);
        if ($acct === null) return self::no('account_not_offered');
        $this->housekeeping();                                                        // 1
        $chk = $this->checkRow($checkId);                                             // 2
        if ($chk === null || (int)$chk['cb_account_id'] !== $acct['id']) return self::no('changed_since_check');
        if ((string)$chk['status'] !== 'waiting') return $this->refuseKeep($chk, $by, $this->stateCode($chk), false);
        if ($this->isExpired($chk)) return $this->refuseKeep($chk, $by, 'check_expired', true);
        if (!$link && $this->profile($acct['id']) === null) return $this->refuseKeep($chk, $by, 'link_required', false);

        $held = $this->heldPath($chk);                                                // 3
        $bytes = $held === null ? false : @file_get_contents($held);
        if (!is_string($bytes) || strlen($bytes) !== (int)$chk['bytes'] || !hash_equals((string)$chk['sha256'], hash('sha256', $bytes))) {
            return $this->refuseKeep($chk, $by, 'changed_since_check', true);
        }
        $ev = $this->evaluate($acct, $bytes, (string)$chk['sha256']);                 // 4
        if ($ev['outcome'] === 'already_kept') return $this->refuseKeep($chk, $by, 'already_kept', true);
        if ($ev['outcome'] !== 'passed' || count($ev['new']) !== (int)$chk['lines_new'] || (int)$ev['known'] !== (int)$chk['lines_known']) {
            return $this->refuseKeep($chk, $by, 'changed_since_check', true);
        }
        // 5: a copy, under a name of this attempt's own; the held file stays. Two keeps of one check (a double click, two
        // sessions) each write their own, so one that fails deletes only what it wrote, never the other's (BK97-R1).
        $finalRel = self::DIR . '/' . $acct['id'] . '/' . (int)$chk['id'] . '-' . substr((string)$chk['sha256'], 0, 16) . '-' . bin2hex(random_bytes(8)) . '.csv';
        $this->place($bytes, (string)$acct['id'], $finalRel);
        unset($bytes);
        $this->step('after_copy');

        $f = $ev['facts'];
        try {
            $this->begin();                                                           // 6
            $now = $this->checkRow((int)$chk['id']);
            if ($now === null || (string)$now['status'] !== 'waiting') {
                $this->rollback();
                $this->dropCopy($finalRel);                                           // this attempt's own copy, whatever happened
                return $this->refuseKeep($now ?? $chk, $by, $now === null ? 'changed_since_check' : $this->stateCode($now), false);
            }
            $profile = $this->profile($acct['id']);
            $code = '';
            if ($this->keptStatement($acct['id'], (string)$chk['sha256']) !== null) {
                $code = 'already_kept';
            } elseif ($profile === null && !$link) {
                $code = 'link_required';
            } elseif ($profile !== null && ((string)$profile['acct_last4'] !== $f['last4'] || (string)$profile['currency'] !== $f['currency'])) {
                $code = 'changed_since_check';
            } elseif ($profile === null && $this->linkedElsewhere($acct['id'], $f['currency'], $f['last4'])) {
                $code = 'changed_since_check';
            } else {
                [$known, $first, $last] = $this->view($acct['id'], (string)$f['cheque_first'], (string)$f['cheque_last']);
                $fit = EcobankDetailsCsv::fit($ev['lines'], $known, $first, $last);
                unset($known);
                if (!$fit['ok'] || count($fit['new']) !== (int)$chk['lines_new'] || (int)$fit['known'] !== (int)$chk['lines_known']) {
                    $code = 'changed_since_check';
                }
            }
            if ($code === 'link_required') {
                $this->rollback();
                $this->dropCopy($finalRel);
                return $this->refuseKeep($chk, $by, 'link_required', false);
            }
            if ($code !== '') {
                $this->closeCheck((int)$chk['id'], 'expired', $by, null);
                $this->event('keep_refused', $acct['id'], (int)$chk['id'], null, $by, ['code' => $code]);
                $this->commit();
                $this->dropCopy($finalRel);
                $this->removeHeld($chk);
                return self::no($code, $code === 'already_kept' ? $this->keptDate((int)$acct['id'], (string)$chk['sha256']) : '');
            }
            $new = $fit['new'];
            $linked = false;                                                          // 7
            if ($profile === null) {
                $this->insert('bank_account_profiles', ['cb_account_id' => $acct['id'], 'bank' => self::BANKS[0],
                    'profile' => EcobankDetailsCsv::PROFILE, 'currency' => $acct['currency'], 'acct_last4' => $f['last4'],
                    'confirmed_by_id' => $by, 'confirmed_at' => $this->stamp()]);
                $this->event('account_linked', $acct['id'], (int)$chk['id'], null, $by, ['bank' => self::BANKS[0],
                    'profile' => EcobankDetailsCsv::PROFILE, 'currency' => $acct['currency'], 'last4' => $f['last4']]);
                $linked = true;
            }
            $sid = $this->insert('bank_statements', ['cb_account_id' => $acct['id'], 'check_id' => (int)$chk['id'],
                'sha256' => (string)$chk['sha256'], 'bytes' => (int)$chk['bytes'], 'file_rel' => $finalRel,
                'profile' => EcobankDetailsCsv::PROFILE, 'parser_version' => EcobankDetailsCsv::VERSION, 'currency' => $f['currency'],
                'acct_last4' => $f['last4'], 'period_from' => $f['period_from'], 'period_to' => $f['period_to'],
                'line_count' => $f['line_count'], 'lines_added' => count($new), 'lines_known' => (int)$fit['known'],
                'opening_stated' => $f['opening_stated'], 'closing_stated' => $f['closing_stated'],
                'opening_derived' => $f['opening_derived'], 'closing_derived' => $f['closing_derived'],
                'cheque_first' => $f['cheque_first'], 'cheque_last' => $f['cheque_last'],
                'file_order_differs' => $f['file_order_differs'] ? 1 : 0, 'warnings' => implode(',', $ev['warnings']),
                'kept_by_id' => $by, 'kept_at' => $this->stamp()]);
            $this->insertLines($sid, (int)$acct['id'], (string)$f['currency'], $new);
            $this->closeCheck((int)$chk['id'], 'kept', $by, $sid);
            $this->event('statement_kept', $acct['id'], (int)$chk['id'], $sid, $by, ['statement_id' => $sid,
                'sha8' => substr((string)$chk['sha256'], 0, 8), 'lines_added' => count($new), 'lines_known' => (int)$fit['known'],
                'period_from' => $f['period_from'], 'period_to' => $f['period_to']]);
            $this->step('before_commit');
            $this->commit();                                                          // 8
        } catch (\Throwable $e) {
            $this->rollback();
            $this->dropCopy($finalRel);
            throw $this->failure('keep', $e);
        }
        $this->removeHeld($chk);                                                      // 9: only now
        $this->dropOtherCopies((int)$acct['id'], (int)$chk['id'], $finalRel);
        return ['ok' => true, 'code' => '', 'param' => '', 'statement_id' => $sid, 'lines_added' => count($new),
            'lines_known' => (int)$fit['known'], 'linked' => $linked];
    }

    /** One prepared INSERT for every new line, in SEQUENCE_NMBR order. */
    private function insertLines(int $statementId, int $accountId, string $currency, array $lines): void
    {
        $st = $this->pdo->prepare('INSERT INTO bank_statement_lines (' . implode(', ', self::LINE_COLUMNS) . ') VALUES ('
            . implode(', ', array_fill(0, count(self::LINE_COLUMNS), '?')) . ')');
        $at = $this->stamp();
        foreach ($lines as $l) {
            $st->execute([$statementId, $accountId, $l['cheque_number'], $l['seq'], $l['sr_no'], $l['file_row'], $l['posting_date'],
                $l['value_date'], $l['posting_raw'], $l['value_raw'], $l['type_code'], $l['type_desc'], $l['txn_code'], $l['credit_text'],
                $l['debit_text'], $l['credit_units'], $l['debit_units'], $l['running_text'], $l['running_units'], $currency,
                $l['cust_ref'], $l['bank_ref'], $l['remarks'], $l['loan_outstanding'], $l['total_amount'], $l['reference'],
                $l['customer_reference'], $l['transaction_reference'], self::fingerprint($l), $l['compare_digest'], '',
                self::LINE_STATUSES[0], $at]);
        }
    }

    /** sha256 of (posting date, direction, units, running units, remarks): the identity a profile with no line id would use. */
    public static function fingerprint(array $l): string
    {
        $units = (string)$l['side'] === 'C' ? $l['credit_units'] : $l['debit_units'];
        $j = json_encode([(string)$l['posting_date'], (string)$l['side'], (string)$units, (string)$l['running_units'], (string)$l['remarks']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false) throw new \RuntimeException('BankStatementStore: a line could not be fingerprinted');
        return hash('sha256', $j);
    }

    /** A keep that does not keep: one keep_refused event; with $expire, the waiting check expires and its held file goes. */
    private function refuseKeep(array $chk, int $by, string $code, bool $expire): array
    {
        $param = $code === 'already_kept' ? $this->keptDate((int)$chk['cb_account_id'], (string)$chk['sha256']) : '';
        try {
            $this->begin();
            $now = $this->checkRow((int)$chk['id']);
            $closed = false;
            if ($expire && $now !== null && (string)$now['status'] === 'waiting') {
                $this->closeCheck((int)$chk['id'], 'expired', $by, null);
                $closed = true;
            }
            $this->event('keep_refused', (int)$chk['cb_account_id'], (int)$chk['id'], null, $by, ['code' => $code]);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $this->failure('keep', $e);
        }
        if ($closed) $this->removeHeld($chk);
        return self::no($code, $param);
    }

    /** What a keep of a check that no longer waits answers. */
    private function stateCode(array $chk): string
    {
        $s = (string)$chk['status'];
        if ($s === 'kept') return 'already_kept';
        if ($s === 'expired') return 'check_expired';
        return 'changed_since_check';
    }

    /** The date a file was kept for an account, ISO (a bank_checks-shaped parameter), or '' when it is not kept. */
    private function keptDate(int $accountId, string $sha): string
    {
        $k = $this->keptStatement($accountId, $sha);
        return $k === null ? '' : substr((string)$k['kept_at'], 0, 10);
    }

    /** waiting → kept / discarded / expired, once (088's trigger allows nothing else). Inside the caller's transaction. */
    private function closeCheck(int $checkId, string $status, ?int $by, ?int $statementId): void
    {
        $st = $this->pdo->prepare("UPDATE bank_checks SET status = ?, closed_by_id = ?, closed_at = ?, statement_id = ?"
            . " WHERE id = ? AND status = 'waiting'");
        $st->execute([$status, $by, $this->stamp(), $statementId, $checkId]);
        if ($st->rowCount() !== 1) throw new \RuntimeException('BankStatementStore: the check no longer waits');
    }

    // ── Discard, export, housekeeping ───────────────────────────────────────────

    /** A waiting check is discarded and its held file deleted. A check that no longer waits is answered, never changed. */
    public function discard(int $checkId, int $accountId, array $actor): array
    {
        $by = self::actorId($actor);
        if (!$this->ready()) return self::no('not_ready');
        $acct = $this->account($accountId);
        if ($acct === null) return self::no('account_not_offered');
        $this->housekeeping();
        $chk = $this->checkRow($checkId);
        if ($chk === null || (int)$chk['cb_account_id'] !== $acct['id']) return self::no('changed_since_check');
        if ((string)$chk['status'] !== 'waiting') {
            $code = $this->stateCode($chk);
            return self::no($code, $code === 'already_kept' ? $this->keptDate($acct['id'], (string)$chk['sha256']) : '');
        }
        try {
            $this->begin();
            $this->closeCheck((int)$chk['id'], 'discarded', $by, null);
            $this->event('check_discarded', $acct['id'], (int)$chk['id'], null, $by, []);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $this->failure('discard', $e);
        }
        $this->removeHeld($chk);
        return ['ok' => true, 'code' => '', 'param' => ''];
    }

    /** The export's event (SPEC-bank §c.5): the count and the month, or 'all'. Nothing else. */
    public function recordExport(int $accountId, int $count, string $month, array $actor): void
    {
        $by = self::actorId($actor);
        if (!$this->ready()) throw new \RuntimeException('BankStatementStore: the Bank tables are not there');
        if ($this->account($accountId) === null) throw new \InvalidArgumentException('BankStatementStore: that account is not offered');
        if ($month !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new \InvalidArgumentException('BankStatementStore: a month is YYYY-MM or all');
        $this->event('lines_exported', $accountId, null, null, $by, ['count' => max(0, $count), 'month' => $month]);
    }

    /**
     * Waiting checks past PENDING_TTL expire (a system event each), and every held file whose check no longer waits — or
     * that no check names — is deleted: that also finishes step 9 of a keep that died after its COMMIT. Decided while
     * holding the write lock, so a check being written by another request is never mistaken for an orphan; deleted after
     * the COMMIT. Temporary files are deleted only once older than the TTL. Kept statement files are never touched.
     *
     * @return array{expired:int, removed:int}
     */
    public function housekeeping(): array
    {
        if (!$this->ready()) return ['expired' => 0, 'removed' => 0];
        $cut = $this->cutoff();
        $root = $this->dataDir . '/' . self::DIR;
        $pend = $root . '/' . self::PENDING;
        $remove = []; $expired = 0;
        try {
            $this->begin();
            $st = $this->pdo->prepare("SELECT id, cb_account_id FROM bank_checks WHERE status = 'waiting' AND checked_at <= ? ORDER BY id");
            $st->execute([$cut]);
            $old = $st->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($old as $o) {
                $this->closeCheck((int)$o['id'], 'expired', null, null);
                $this->event('check_expired', (int)$o['cb_account_id'], (int)$o['id'], null, null, []);
                $expired++;
            }
            if (is_dir($pend) && !is_link($pend) && !is_link($root)) {
                $waiting = [];
                foreach ($this->pdo->query("SELECT pending_rel FROM bank_checks WHERE status = 'waiting'")->fetchAll(\PDO::FETCH_COLUMN) as $rel) {
                    $waiting[(string)$rel] = true;
                }
                foreach (scandir($pend) ?: [] as $name) {
                    $p = $pend . '/' . $name;
                    if (is_link($p) || !is_file($p)) continue;
                    if (preg_match(self::HELD_NAME, $name)) {
                        if (!isset($waiting[self::DIR . '/' . self::PENDING . '/' . $name])) $remove[] = $p;
                    } elseif (preg_match(self::TEMP_NAME, $name) && (int)@filemtime($p) <= $this->now() - self::PENDING_TTL) {
                        $remove[] = $p;
                    }
                }
            }
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $this->failure('housekeeping', $e);
        }
        foreach ($this->accountFolders() as $d) {
            foreach (scandir($d) ?: [] as $name) {
                $p = $d . '/' . $name;
                if (preg_match(self::TEMP_NAME, $name) && !is_link($p) && is_file($p) && (int)@filemtime($p) <= $this->now() - self::PENDING_TTL) $remove[] = $p;
            }
        }
        $n = 0;
        foreach ($remove as $p) if (@unlink($p)) $n++;
        return ['expired' => $expired, 'removed' => $n];
    }

    /**
     * Statement files at a final name that no bank_statements row names: what a keep that died between its copy and its
     * COMMIT leaves. Counted by the deploy's --after-only as a note; never deleted here (a keep may be between the two).
     *
     * @return string[] paths relative to the data directory, server-made names only
     */
    public function orphanFiles(): array
    {
        $named = [];
        if ($this->ready()) {
            foreach ($this->pdo->query('SELECT file_rel FROM bank_statements')->fetchAll(\PDO::FETCH_COLUMN) as $rel) $named[(string)$rel] = true;
        }
        $out = [];
        foreach ($this->accountFolders() as $sub => $d) {
            foreach (scandir($d) ?: [] as $name) {
                if (!preg_match(self::KEPT_NAME, $name) || is_link($d . '/' . $name)) continue;
                $rel = self::DIR . '/' . $sub . '/' . $name;
                if (!isset($named[$rel])) $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    // ── Reads for the screen and its export (SELECTs of the bank_* tables; nothing is written) ─────────────────

    /** What the screen and the export show of a kept line: no Account, no Account Name (they are not stored). */
    private const VIEW_COLUMNS = 'l.id, l.statement_id, l.cheque_number, l.posting_date, l.value_date, l.type_code, l.type_desc,'
        . ' l.txn_code, l.credit_text, l.debit_text, l.credit_units, l.debit_units, l.running_units, l.currency, l.remarks,'
        . ' l.reference, l.status, s.sha256 AS statement_sha256';

    /**
     * The account's kept line with the highest bank line number — the card's figure is its running balance, the bank's
     * own — with the statement that holds it (kept_at, kept_by_id). Null when nothing is kept.
     */
    public function latestLine(int $accountId): ?array
    {
        if (!$this->ready()) return null;
        $st = $this->pdo->prepare('SELECT l.cheque_number, l.posting_date, l.running_units, l.currency, l.statement_id, s.kept_at,'
            . ' s.kept_by_id FROM bank_statement_lines l JOIN bank_statements s ON s.id = l.statement_id'
            . ' WHERE l.cb_account_id = ? ORDER BY l.cheque_number DESC LIMIT 1');
        $st->execute([$accountId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r ?: null;
    }

    /**
     * Kept lines of one account in the bank's order, by Cheque Number: 'desc' (the screen, newest first) or 'asc' (the
     * export, oldest first). $month is YYYY-MM (on the posting date) or '' for every month. $limit 0 means all.
     *
     * @return array<int,array<string,mixed>>
     */
    public function lines(int $accountId, string $month, string $order, int $limit = 0, int $offset = 0): array
    {
        if (!$this->ready()) return [];
        [$where, $p] = self::monthWhere($accountId, $month);
        $sql = 'SELECT ' . self::VIEW_COLUMNS . ' FROM bank_statement_lines l JOIN bank_statements s ON s.id = l.statement_id'
            . ' WHERE ' . $where . ' ORDER BY l.cheque_number ' . ($order === 'asc' ? 'ASC' : 'DESC');
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . max(0, (int)$offset);
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $out = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** How many kept lines lines() would return with no limit. */
    public function lineCount(int $accountId, string $month): int
    {
        if (!$this->ready()) return 0;
        [$where, $p] = self::monthWhere($accountId, $month);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM bank_statement_lines l WHERE ' . $where);
        $st->execute($p);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /** The first and last posting dates of the account's kept lines, and how many: null when nothing is kept. */
    public function keptRange(int $accountId): ?array
    {
        if (!$this->ready()) return null;
        $st = $this->pdo->prepare('SELECT MIN(posting_date), MAX(posting_date), COUNT(*) FROM bank_statement_lines WHERE cb_account_id = ?');
        $st->execute([$accountId]);
        $r = $st->fetch(\PDO::FETCH_NUM);
        $st->closeCursor();
        if (!$r || (int)$r[2] === 0) return null;
        return ['first' => (string)$r[0], 'last' => (string)$r[1], 'count' => (int)$r[2]];
    }

    /**
     * One month of kept lines, from the bank's own balances: the opening (the month's first line by Cheque Number,
     * running − credit + debit), the credits, the debits and the closing (its last line's running). 'consecutive' is false
     * when a kept line of another month sits between the month's first and last bank line (posting dates out of the
     * bank's order); then no sum is claimed. Null when the month holds no kept line.
     */
    public function monthStrip(int $accountId, string $month): ?array
    {
        if (!$this->ready() || $month === '') return null;
        [$where, $p] = self::monthWhere($accountId, $month);
        $st = $this->pdo->prepare('SELECT COUNT(*), MIN(l.cheque_number), MAX(l.cheque_number), COALESCE(SUM(l.credit_units), 0),'
            . ' COALESCE(SUM(l.debit_units), 0) FROM bank_statement_lines l WHERE ' . $where);
        $st->execute($p);
        $r = $st->fetch(\PDO::FETCH_NUM);
        $st->closeCursor();
        if (!$r || (int)$r[0] === 0) return null;
        $one = $this->pdo->prepare('SELECT running_units, credit_units, debit_units FROM bank_statement_lines WHERE cb_account_id = ? AND cheque_number = ?');
        $one->execute([$accountId, (string)$r[1]]);
        $first = $one->fetch(\PDO::FETCH_ASSOC);
        $one->closeCursor();
        $one->execute([$accountId, (string)$r[2]]);
        $last = $one->fetch(\PDO::FETCH_ASSOC);
        $one->closeCursor();
        $span = $this->pdo->prepare('SELECT COUNT(*) FROM bank_statement_lines WHERE cb_account_id = ? AND cheque_number BETWEEN ? AND ?');
        $span->execute([$accountId, (string)$r[1], (string)$r[2]]);
        $between = (int)$span->fetchColumn();
        $span->closeCursor();
        $opening = (int)$first['running_units'] - (int)$first['credit_units'] + (int)$first['debit_units'];
        $in = (int)$r[3]; $out = (int)$r[4]; $closing = (int)$last['running_units'];
        return ['count' => (int)$r[0], 'opening' => $opening, 'in' => $in, 'out' => $out, 'closing' => $closing,
            'consecutive' => $between === (int)$r[0], 'adds_up' => $opening + $in - $out === $closing];
    }

    /**
     * Every check of one account, newest first, with the statement a kept check made (lines added, kept by, kept at,
     * the file's server name): the Statements tab. Codes, counts, dates, the currency and the last four; never a cell.
     */
    public function checksFor(int $accountId, int $limit = 200): array
    {
        if (!$this->ready()) return [];
        $st = $this->pdo->prepare('SELECT c.id, c.cb_account_id, c.outcome, c.code, c.param, c.currency, c.acct_last4, c.line_count,'
            . ' c.lines_new, c.lines_known, c.period_from, c.period_to, c.opening_stated, c.closing_stated, c.sha256, c.status,'
            . ' c.checked_by_id, c.checked_at, c.closed_at, c.statement_id, s.lines_added AS st_lines_added,'
            . ' s.lines_known AS st_lines_known, s.kept_by_id AS st_kept_by_id, s.kept_at AS st_kept_at, s.file_rel AS st_file_rel'
            . " FROM bank_checks c LEFT JOIN bank_statements s ON s.id = c.statement_id AND c.status = 'kept'"
            . ' WHERE c.cb_account_id = ? ORDER BY c.id DESC LIMIT ' . max(1, (int)$limit));
        $st->execute([$accountId]);
        $out = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** One kept statement's row (counts, who and when, the file's server name), or null. */
    public function statement(int $statementId): ?array
    {
        if ($statementId <= 0 || !$this->ready()) return null;
        $st = $this->pdo->prepare('SELECT id, cb_account_id, check_id, sha256, file_rel, currency, acct_last4, period_from, period_to,'
            . ' line_count, lines_added, lines_known, kept_by_id, kept_at FROM bank_statements WHERE id = ?');
        $st->execute([$statementId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $r ?: null;
    }

    /** Checks of this account that wait to be kept and are still inside the TTL (the Statements tab's badge). */
    public function waitingCount(int $accountId): int
    {
        if (!$this->ready()) return 0;
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM bank_checks WHERE cb_account_id = ? AND status = 'waiting' AND checked_at > ?");
        $st->execute([$accountId, $this->cutoff()]);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /** The WHERE of a month filter over bank_statement_lines l: the account, and YYYY-MM on the posting date. */
    private static function monthWhere(int $accountId, string $month): array
    {
        if ($month === '') return ['l.cb_account_id = ?', [$accountId]];
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new \InvalidArgumentException('BankStatementStore: a month is YYYY-MM');
        return ['l.cb_account_id = ? AND l.posting_date BETWEEN ? AND ?', [$accountId, $month . '-01', $month . '-31']];
    }

    // ── Files ───────────────────────────────────────────────────────────────────

    /** A check's held file, only while it lies under bank_statements/.pending and exists (JobPhotos::path()'s rule). */
    public function heldPath(array $check): ?string
    {
        return $this->contained((string)($check['pending_rel'] ?? ''), self::PENDING);
    }

    /** A kept statement's file, only while it lies under bank_statements/<its account id> and exists. */
    public function statementPath(array $statement): ?string
    {
        return $this->contained((string)($statement['file_rel'] ?? ''), (string)(int)($statement['cb_account_id'] ?? 0));
    }

    private function contained(string $rel, string $sub): ?string
    {
        if ($rel === '' || strpos($rel, "\0") !== false) return null;
        $prefix = self::DIR . '/' . $sub . '/';
        if (strncmp($rel, $prefix, strlen($prefix)) !== 0) return null;
        $base = realpath($this->dataDir . '/' . self::DIR . '/' . $sub);
        if ($base === false) return null;
        $full = realpath($this->dataDir . '/' . $rel);
        if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) return null;
        return $full;
    }

    /** <dataDir>/bank_statements/<sub>, created 0700 when absent; a folder that is a link is refused. */
    private function folder(string $sub): string
    {
        if (!is_dir($this->dataDir) || is_link($this->dataDir . '/' . self::DIR)) {
            throw new \RuntimeException('BankStatementStore: the statement folder cannot be used; nothing was written');
        }
        $root = $this->dataDir . '/' . self::DIR;
        foreach ([$root, $root . '/' . $sub] as $d) {
            if (is_link($d)) throw new \RuntimeException('BankStatementStore: the statement folder cannot be used; nothing was written');
            if (!is_dir($d) && !@mkdir($d, self::DIR_MODE) && !is_dir($d)) {
                throw new \RuntimeException('BankStatementStore: the statement folder could not be created; nothing was written');
            }
            @chmod($d, self::DIR_MODE);
        }
        return $root . '/' . $sub;
    }

    /** @return array<string,string> account id => its folder, for each bank_statements/<digits>/ that is a real folder */
    private function accountFolders(): array
    {
        $root = $this->dataDir . '/' . self::DIR;
        if (!is_dir($root) || is_link($root)) return [];
        $out = [];
        foreach (scandir($root) ?: [] as $sub) {
            if (!preg_match('/^\d{1,12}$/', $sub)) continue;
            $d = $root . '/' . $sub;
            if (is_dir($d) && !is_link($d)) $out[$sub] = $d;
        }
        return $out;
    }

    /** Written whole under a temporary name, 0600, never visible to another user while being written. */
    private function writeFile(string $path, string $bytes): void
    {
        $old = umask(0077);
        try {
            $n = @file_put_contents($path, $bytes, LOCK_EX);
        } finally {
            umask($old);
        }
        if ($n !== strlen($bytes)) {
            @unlink($path);
            throw new \RuntimeException('BankStatementStore: a statement file could not be written; nothing was kept');
        }
        @chmod($path, self::FILE_MODE);
    }

    /** The kept copy: written beside its final name (this attempt's own), then renamed into place. */
    private function place(string $bytes, string $sub, string $finalRel): void
    {
        $dir = $this->folder($sub);
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(16));
        $this->writeFile($tmp, $bytes);
        if (!@rename($tmp, $this->dataDir . '/' . $finalRel)) {
            @unlink($tmp);
            throw new \RuntimeException('BankStatementStore: the statement file could not be put in place; nothing was kept');
        }
    }

    /** The copy at the final name goes, unless a committed statement names it (another request kept this check). */
    private function dropCopy(string $finalRel): void
    {
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM bank_statements WHERE file_rel = ?');
            $st->execute([$finalRel]);
            $named = (int)$st->fetchColumn() > 0;
            $st->closeCursor();
        } catch (\Throwable $e) {
            return;                                                                   // unsure: leave it, orphanFiles() counts it
        }
        $p = $this->dataDir . '/' . $finalRel;
        if (!$named && is_file($p) && !is_link($p)) @unlink($p);
    }

    /**
     * After a COMMIT that kept this check: every other copy of it in the account's folder goes — one a dead attempt left,
     * or one an attempt still running made (it can no longer commit: it re-reads the check inside its own transaction and
     * finds it kept). dropCopy() still leaves any file a committed statement names.
     */
    private function dropOtherCopies(int $accountId, int $checkId, string $keptRel): void
    {
        $d = $this->dataDir . '/' . self::DIR . '/' . $accountId;
        if (!is_dir($d) || is_link($d)) return;
        $prefix = $checkId . '-';
        foreach (scandir($d) ?: [] as $name) {
            $rel = self::DIR . '/' . $accountId . '/' . $name;
            if ($rel === $keptRel || strncmp($name, $prefix, strlen($prefix)) !== 0 || !preg_match(self::KEPT_NAME, $name)) continue;
            $this->dropCopy($rel);
        }
    }

    private function removeHeld(array $check): void
    {
        $p = $this->heldPath($check);
        if ($p !== null) @unlink($p);
    }

    // ── The trail and the plumbing ──────────────────────────────────────────────

    private function event(string $event, ?int $accountId, ?int $checkId, ?int $statementId, ?int $by, array $detail): void
    {
        if (!in_array($event, self::EVENTS, true)) throw new \LogicException('BankStatementStore: an event that is not on the list');
        $this->insert('bank_line_events', ['event' => $event, 'cb_account_id' => $accountId, 'check_id' => $checkId,
            'statement_id' => $statementId, 'line_id' => null, 'actor_kind' => $by === null ? 'system' : 'staff', 'actor_id' => $by,
            'detail' => self::json($detail), 'created_at' => $this->stamp()]);
    }

    /** One INSERT into one of the five tables (the names are this class's constants, never input). */
    private function insert(string $table, array $cols): int
    {
        if (!in_array($table, self::TABLES, true)) throw new \LogicException('BankStatementStore: not a bank table');
        $st = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($cols)) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($cols));
        return (int)$this->pdo->lastInsertId();
    }

    private function step(string $at): void
    {
        if ($this->probe !== null) ($this->probe)($at);
    }

    private function begin(): void
    {
        if ($this->inTxn || $this->pdo->inTransaction()) throw new \LogicException('BankStatementStore: a transaction is already open on this connection');
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->inTxn = true;
    }

    private function commit(): void
    {
        $this->pdo->exec('COMMIT');
        $this->inTxn = false;
    }

    private function rollback(): void
    {
        if (!$this->inTxn) return;
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (\Throwable $e) {
            // the connection had already ended the transaction
        }
        $this->inTxn = false;
    }

    /** What a caller may show or log about a failure: fixed text, the class and the SQLSTATE. Never a value. */
    private function failure(string $act, \Throwable $e): \RuntimeException
    {
        if ($e instanceof \RuntimeException && strncmp($e->getMessage(), 'BankStatementStore: ', 20) === 0) return $e;
        $why = get_class($e) . ($e instanceof \PDOException ? ' ' . (string)$e->getCode() : '');
        return new \RuntimeException('BankStatementStore: the ' . $act . ' did not complete; nothing was kept (' . $why . ')');
    }

    private function tableExists(string $name): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n > 0;
    }

    private function now(): int
    {
        return (int)($this->clock)();
    }

    private function stamp(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now());
    }

    private function cutoff(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now() - self::PENDING_TTL);
    }

    private static function actorId(array $actor): int
    {
        $id = (int)($actor['id'] ?? 0);
        if ($id <= 0) throw new \InvalidArgumentException('BankStatementStore: an act needs the re-read staff record');
        return $id;
    }

    private static function json(array $detail): string
    {
        if ($detail === []) return '{}';
        $j = json_encode($detail, JSON_UNESCAPED_SLASHES);
        if ($j === false) throw new \RuntimeException('BankStatementStore: an event could not be recorded');
        return $j;
    }

    private static function no(string $code, string $param = ''): array
    {
        return ['ok' => false, 'code' => $code, 'param' => $param, 'check_id' => 0, 'note' => in_array($code, ['already_kept', 'nothing_new'], true)];
    }
}
