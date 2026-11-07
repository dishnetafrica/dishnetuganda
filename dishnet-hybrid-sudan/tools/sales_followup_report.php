<?php
declare(strict_types=1);

/**
 * sales_followup_report.php — 5.18.104 (Uganda, root docs/73 §H.2.8, §H.2.21; P1): the read-only sales follow-up report.
 *
 *   php tools/sales_followup_report.php --db=<copy.sqlite3> --data=<the folder of copies> [--at='YYYY-MM-DD HH:MM:SS']
 *        [--json] [--sheet=<file>]
 *
 * Per number and per owner, over the last 30 and 90 days: conversations, inbound customers and new ones; quotations
 * sent, and those from a registration; answered, chased (by the engine and by a person) and NEITHER — the headline;
 * registered, paid, opted out. Run by scripts/sales-followup-report.sh (repository root) on a VACUUM INTO copy, in a
 * mode-700 folder that holds the copies of data/config.json, the data folder's config.json and kyc_config.json and a
 * vault with only the tenant keys (DN_VAULT_FILE); --data is that folder (R104-18). Anything but Uganda prints
 * "not this install — nothing read".
 *
 * How it cannot write (F17, §H.2.8.3):
 *   - it never calls SqliteStore::create (which runs the migrations), nor anything that would;
 *   - one connection to the copy: SQLITE_OPEN_READONLY, immutable=1, then PRAGMA query_only=ON and temp_store=MEMORY,
 *     both checked; every statement of its own is a single SELECT or WITH (q()), all inside one BEGIN … ROLLBACK; the
 *     run fails (exit 3) unless total_changes() is 0 at the end;
 *   - its includes are pure or read-only: UtcClock, PhoneNumber, TenantProfile, PluginConfig::read (with ConfigVault's
 *     fill, which writes nothing), ContactOptOut::detect, QuoteSendEvidence, HumanTakeover (SELECTs only); and, on a
 *     book that has 089's ledger, WaSendBudget::onConnection() on the same connection, asked only for counts().
 *
 * What it prints: counts only. Business numbers as their last two digits; owners as `owner 1…n`, the mapping to staff
 * ids written only to --sheet (mode 0600), never to the output; no name, phone, amount or text. A missing source prints
 * UNKNOWN, never 0 (tools/report.php's rule). The windows are UTC, [now − D·24 h, now); dates print in Kampala.
 *
 * "Chased by a person" is HumanTakeover's row and mark rules, the tracker's own code, so the two agree by construction;
 * on a book without 089's tables (an 088-shaped copy) it reads them through HumanTakeover's absent-table rule (A11-02).
 *
 * Exit: 0 done (or not this install); 2 refused before reading; 3 a check failed. PHP 7.4.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$sfrRoot = dirname(__DIR__);
require_once $sfrRoot . '/lib/UtcClock.php';
require_once $sfrRoot . '/lib/PhoneNumber.php';
require_once $sfrRoot . '/lib/TenantProfile.php';
require_once $sfrRoot . '/lib/PluginConfig.php';
require_once $sfrRoot . '/lib/ContactOptOut.php';
require_once $sfrRoot . '/lib/QuoteSendEvidence.php';
require_once $sfrRoot . '/lib/HumanTakeover.php';

final class SalesFollowupReport
{
    /** The zone dates print in (profiles/uganda.json:15). Windows are UTC. */
    public const TZ = 'Africa/Kampala';
    /** FollowUpPolicy::TRUSTED_LINKS (lib/FollowUpPolicy.php:41), the client of a conversation the report trusts; a test asserts they are equal. */
    const TRUSTED_LINKS = ['verified', 'manual', 'ai_identified'];
    /** The payment messages (QuoteTracker::PAYMENT_EVENTS). */
    const PAYMENT_EVENTS = ['ops_payment_received', 'ops_invoice_auto_paid'];
    const WINDOWS = [30, 90];
    const ANSWER_S = 7 * 86400;
    const FOLLOW_S = 30 * 86400;
    const NEVER_WROTE = "(quote's own number — customer never wrote)";
    const WEB = 'web chat';
    const COLS = ['conv', 'cust', 'new', 'quotes', 'kyc', 'answ', 'chased', 'eng', 'per', 'neither', 'reg', 'paid', 'optout'];

    /** @var \PDO */ public $pdo;
    /** @var int */  public $now;
    /** @var array */ public $config;
    /** @var string */ public $data;
    /** @var \TenantProfile */ public $tenant;
    /** @var array */ private $memo = [];
    /** @var array<string,int> */ public $diag = ['phone_unparseable' => 0, 'owner_ambiguous' => 0];

    // ── reading, and only reading ───────────────────────────────────────────────────────────────────────────────

    /** One statement of the report's own: a single SELECT or WITH. Anything else is refused before it reaches SQLite. */
    public function q(string $sql, array $args = []): array
    {
        $s = ltrim($sql);
        if (!preg_match('/^(SELECT|WITH)\b/i', $s) || strpos(rtrim($s, "; \n\t"), ';') !== false) {
            throw new \LogicException('the report runs single SELECT statements only');
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function one(string $sql, array $args = [])
    {
        $r = $this->q($sql, $args);
        return $r === [] ? null : array_values($r[0])[0];
    }

    public function hasTable(string $t): bool
    {
        if (!isset($this->memo['t:' . $t])) {
            $this->memo['t:' . $t] = (int)$this->one("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) > 0;
        }
        return $this->memo['t:' . $t];
    }

    public function hasColumns(string $t, array $cols): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $t) || !$this->hasTable($t)) return false;
        $have = [];
        foreach ($this->q("SELECT name FROM pragma_table_info(?)", [$t]) as $c) $have[(string)$c['name']] = true;
        foreach ($cols as $c) if (!isset($have[$c])) return false;
        return true;
    }

    /** Every row of a JSON collection, with its id. */
    public function blobAll(string $t): array
    {
        if (isset($this->memo['b:' . $t])) return $this->memo['b:' . $t];
        $out = [];
        if ($this->hasColumns($t, ['data'])) {
            foreach ($this->q("SELECT id, data FROM [{$t}] ORDER BY id") as $r) {
                $d = json_decode((string)$r['data'], true);
                if (!is_array($d)) continue;
                $d['id'] = (int)$r['id'];
                $out[] = $d;
            }
        }
        return $this->memo['b:' . $t] = $out;
    }

    /** The rows of a collection whose field equals $value (as text, trimmed). */
    public function blobWhere(string $t, string $field, string $value): array
    {
        if (trim($value) === '') return [];
        $out = [];
        foreach ($this->blobAll($t) as $d) {
            if (isset($d[$field]) && !is_array($d[$field]) && trim((string)$d[$field]) === trim($value)) $out[] = $d;
        }
        return $out;
    }

    // ── phones, times ───────────────────────────────────────────────────────────────────────────────────────────

    /** International digits, no '+', by the tenant's rule; the raw digits (and a diagnostic) when it does not normalise. */
    public function canon(string $raw, bool $count = false): string
    {
        $d = (string)preg_replace('/\D+/', '', $raw);
        if ($d === '') return '';
        $i = \PhoneNumber::international($raw, $this->tenant);
        if ($i === null) { if ($count) $this->diag['phone_unparseable']++; return $d; }
        return ltrim($i, '+');
    }

    public static function stamp(int $t): string { return UtcClock::stamp($t); }

    public static function local(int $t): string
    {
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i:s');
    }

    public static function day(int $t): string { return substr(self::local($t), 0, 10); }

    // ── the book's people and numbers ───────────────────────────────────────────────────────────────────────────

    /** DishNet's own numbers: every wa_channels business number, and the phones of active retailers rows. */
    public function ownNumbers(): array
    {
        if (isset($this->memo['own'])) return $this->memo['own'];
        $own = [];
        if ($this->hasColumns('wa_channels', ['business_number'])) {
            foreach ($this->q('SELECT business_number FROM wa_channels WHERE business_number IS NOT NULL') as $r) {
                $p = $this->canon((string)$r['business_number']);
                if ($p !== '') $own[$p] = true;
            }
        }
        foreach ($this->blobAll('retailers') as $r) {
            if (!QuoteSendEvidence::isActive($r)) continue;
            $p = $this->canon((string)($r['phone'] ?? ''));
            if ($p !== '') $own[$p] = true;
        }
        return $this->memo['own'] = $own;
    }

    public function channelRow(string $ch): ?array
    {
        if ($ch === '' || !$this->hasTable('wa_channels')) return null;
        if (!array_key_exists('c:' . $ch, $this->memo)) {
            $r = $this->q('SELECT * FROM wa_channels WHERE channel_id = ?', [$ch]);
            $this->memo['c:' . $ch] = $r[0] ?? null;
        }
        return $this->memo['c:' . $ch];
    }

    /** A number's row label: its channel id, `accounts` folded into `account`. */
    public static function number(string $channel): string
    {
        return $channel === 'accounts' ? 'account' : $channel;
    }

    public function conversation(int $id): ?array
    {
        if (!array_key_exists('v:' . $id, $this->memo)) {
            $r = $this->q('SELECT * FROM wa_conversations WHERE id = ?', [$id]);
            $this->memo['v:' . $id] = $r[0] ?? null;
        }
        return $this->memo['v:' . $id];
    }

    /** Every conversation of p (phone exactly p, never the last nine digits) except category 'staff', and the quote's own. */
    public function convsOf(string $p, int $own = 0): array
    {
        $ids = [];
        foreach ($this->q("SELECT id FROM wa_conversations WHERE phone = ? AND COALESCE(category, '') <> 'staff'", [$p]) as $r) $ids[(int)$r['id']] = (int)$r['id'];
        if ($own > 0) {
            $c = $this->conversation($own);
            if ($c !== null && strtolower((string)($c['category'] ?? '')) !== 'staff') $ids[$own] = $own;
        }
        ksort($ids);
        return array_values($ids);
    }

    // ── one quotation (§H.2.21 9.2) ─────────────────────────────────────────────────────────────────────────────

    /** The uCRM quote id: wa_sent_quotes, then quotes_log, then kyc_applications ('PENDING' never). */
    public function quoteIdOf(string $number): ?int
    {
        if ($this->hasColumns('wa_sent_quotes', ['quote_id', 'quote_ref'])) {
            $v = $this->one('SELECT quote_id FROM wa_sent_quotes WHERE TRIM(quote_ref) = ? ORDER BY sent_at, quote_id LIMIT 1', [$number]);
            if ($v !== null && (int)$v > 0) return (int)$v;
        }
        foreach ($this->blobWhere('quotes_log', 'quote_ref', $number) as $r) if ((int)($r['crm_quote_id'] ?? 0) > 0) return (int)$r['crm_quote_id'];
        foreach ($this->blobWhere('kyc_applications', 'quote_ref', $number) as $a) {
            $q = trim((string)($a['quote_id'] ?? ''));
            if ($q !== '' && strtoupper($q) !== 'PENDING' && ctype_digit($q) && (int)$q > 0) return (int)$q;
        }
        return null;
    }

    /** The KYC application this quotation is: by its uCRM id, else by its number; 'PENDING' is never a number. */
    public function kycByQuote(?int $quoteId, string $number): ?array
    {
        if ($quoteId !== null) foreach ($this->blobWhere('kyc_applications', 'quote_id', (string)$quoteId) as $a) return $a;
        if (strtoupper(trim($number)) === 'PENDING') return null;
        foreach ($this->blobWhere('kyc_applications', 'quote_ref', $number) as $a) return $a;
        return null;
    }

    public function logOf(?int $quoteId, string $number): ?array
    {
        if ($quoteId !== null) foreach ($this->blobWhere('quotes_log', 'crm_quote_id', (string)$quoteId) as $r) return $r;
        foreach ($this->blobWhere('quotes_log', 'quote_ref', $number) as $r) return $r;
        return null;
    }

    public function leadLogOf(?int $quoteId, string $number): ?array
    {
        $rows = $quoteId !== null ? $this->blobWhere('quotes_log', 'crm_quote_id', (string)$quoteId) : [];
        foreach (array_merge($rows, $this->blobWhere('quotes_log', 'quote_ref', $number)) as $r) {
            if ((string)($r['type'] ?? '') === 'lead') return $r;
        }
        return null;
    }

    public function leadOf(?int $quoteId, string $number): ?array
    {
        if ($quoteId !== null) foreach ($this->blobWhere('leads', 'crm_quote_id', (string)$quoteId) as $l) return $l;
        foreach ($this->blobWhere('leads', 'quote_ref', $number) as $l) return $l;
        return null;
    }

    /** The client: from the KYC application or the quote log, else the conversation's only when its link is trusted. */
    public function clientOf(?array $kycApp, ?array $log, array $conv): ?int
    {
        foreach ([$kycApp, $log] as $r) if ($r !== null && (int)($r['crm_client_id'] ?? 0) > 0) return (int)$r['crm_client_id'];
        if ((int)($conv['crm_client_id'] ?? 0) > 0 && in_array((string)($conv['crm_link_method'] ?? ''), self::TRUSTED_LINKS, true)) {
            return (int)$conv['crm_client_id'];
        }
        return null;
    }

    /** Spoke-to (§H.2.9.5): the conversations with phone exactly p, each with its latest inbound row at or before t. */
    public function spokeOf(string $p, string $sentAt): ?array
    {
        $cands = [];
        foreach ($this->q("SELECT c.*, (SELECT MAX(m.sent_at) FROM wa_messages m WHERE m.conversation_id = c.id
                                          AND m.direction = 'in' AND m.sent_at <= ?) AS last_in
                             FROM wa_conversations c WHERE c.phone = ?", [$sentAt, $p]) as $c) {
            $ch = $this->channelRow((string)$c['channel']);
            $c['role'] = $ch !== null ? (string)($ch['role'] ?? '') : self::number((string)$c['channel']);
            $cands[] = $c;
        }
        return QuoteSendEvidence::spokeTo($cands, $sentAt);
    }

    /** The latest KYC of the client submitted before the send (rule 6); submitted_at is date(), Kampala. */
    public function kycOfClientBefore(int $client, string $sentAt): ?array
    {
        $local = self::local(UtcClock::parse($sentAt));
        $best = null;
        foreach ($this->blobWhere('kyc_applications', 'crm_client_id', (string)$client) as $a) {
            $s = (string)($a['submitted_at'] ?? '');
            if ($s === '' || $s >= $local) continue;
            if ($best === null || [$s, (int)($a['id'] ?? 0)] > [(string)$best['submitted_at'], (int)($best['id'] ?? 0)]) $best = $a;
        }
        return $best;
    }

    /** Rules 1–7 (F4) over the book's facts, with QuoteSendEvidence::owner — the tracker's own code; no `pending`. */
    public function ownerOf(?int $quoteId, string $number, ?array $kycApp, ?array $log, ?int $client, ?array $spoke, string $sentAt): array
    {
        $leadLog = $log !== null && (string)($log['type'] ?? '') === 'lead' ? $log : $this->leadLogOf($quoteId, $number);
        $convLead = null;
        if ($spoke !== null && (int)($spoke['lead_id'] ?? 0) > 0) {
            foreach ($this->blobAll('leads') as $l) if ((int)$l['id'] === (int)$spoke['lead_id']) { $convLead = $l; break; }
        }
        $facts = [
            'kyc_app'    => $kycApp,
            'lead_log'   => $leadLog !== null,
            'lead'       => $leadLog !== null ? $this->leadOf($quoteId, $number) : null,
            'log'        => $log,
            'spoke_chan' => $spoke !== null ? $this->channelRow((string)$spoke['channel']) : null,
            'conv_lead'  => $convLead,
            'client_app' => $client !== null ? $this->kycOfClientBefore($client, $sentAt) : null,
        ];
        $ret = $this->blobAll('retailers');
        foreach ([$kycApp['sales_person'] ?? '', $log['sent_by'] ?? ''] as $name) {
            if ($this->namedTwice($ret, (string)$name)) { $this->diag['owner_ambiguous']++; break; }
        }
        return QuoteSendEvidence::owner($facts, $ret);
    }

    /** Two or more active accounts with this name (the rule then yields none). */
    private function namedTwice(array $ret, string $name): bool
    {
        $want = strtolower(trim($name));
        if ($want === '') return false;
        $n = 0;
        foreach ($ret as $r) if (QuoteSendEvidence::isActive($r) && strtolower(trim((string)($r['name'] ?? ''))) === $want) $n++;
        return $n > 1;
    }

    /**
     * Every quotation the book confirms (QuoteSendEvidence over the stored quote texts): the earliest row per quote_key.
     *
     * @return array<string,array{key:string, number:string, t:int, sent_at:string, conv:int, msg:int}>
     */
    public function quotations(): array
    {
        $ev = QuoteSendEvidence::EVENTS;
        $in = implode(',', array_fill(0, count($ev), '?'));
        $out = [];
        foreach ($this->q("SELECT id, conversation_id, direction, event_key, body, sent_at FROM wa_messages
                            WHERE direction = 'out' AND event_key IN ({$in}) ORDER BY id", $ev) as $m) {
            $e = QuoteSendEvidence::read($m);
            if ($e === null) continue;
            $t = UtcClock::parse((string)$m['sent_at']);
            if ($t === 0) continue;
            $key = QuoteSendEvidence::key($e[1]);
            if (isset($out[$key]) && $out[$key]['t'] <= $t) continue;
            $out[$key] = ['key' => $key, 'number' => $e[1], 'marker' => $e[0], 't' => $t, 'sent_at' => self::stamp($t),
                          'conv' => (int)$m['conversation_id'], 'msg' => (int)$m['id']];
        }
        return $out;
    }

    /**
     * One quotation's facts at $this->now. Null when it is out of the report (a staff-filed conversation, a web session,
     * one of DishNet's own numbers, or its conversation gone).
     */
    public function judge(array $q): ?array
    {
        $conv = $this->conversation($q['conv']);
        if ($conv === null) return null;
        $raw = (string)$conv['phone'];
        if (strtolower((string)($conv['category'] ?? '')) === 'staff' || strpos($raw, 'web:') === 0) return null;
        $p = $this->canon($raw, true);
        if ($p === '' || isset($this->ownNumbers()[$p])) return null;
        $t = $q['t']; $sent = $q['sent_at'];
        $quoteId = $this->quoteIdOf($q['number']);
        $kycApp = $this->kycByQuote($quoteId, $q['number']);
        $log = $this->logOf($quoteId, $q['number']);
        $client = $this->clientOf($kycApp, $log, $conv);
        $spoke = $this->spokeOf($p, $sent);
        $owner = $this->ownerOf($quoteId, $q['number'], $kycApp, $log, $client, $spoke, $sent);
        $convs = $this->convsOf($p, $q['conv']);
        $end7 = $t + self::ANSWER_S;

        // answered and opted out, in (t, t + 7 d]: an inbound on any channel; a STOP-only one is an opt-out, not an answer
        $firstAnswer = null; $optout = false;
        if ($convs !== []) {
            foreach ($this->q('SELECT body, sent_at FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                                AND direction = 'in' AND sent_at > ? AND sent_at <= ? ORDER BY sent_at, id", [$sent, self::stamp($end7)]) as $m) {
                if (\ContactOptOut::detect((string)$m['body'])['stop']) { $optout = true; continue; }
                $firstAnswer = $firstAnswer ?? UtcClock::parse((string)$m['sent_at']);
            }
        }
        if ($this->hasTable('wa_seen_marks')) {
            $mk = $this->one("SELECT MIN(seen_at) FROM wa_seen_marks WHERE direction = 'in' AND phone = ? AND seen_at > ? AND seen_at <= ?",
                             [$p, $sent, self::stamp($end7)]);
            if ($mk !== null) { $mt = UtcClock::parse((string)$mk); $firstAnswer = $firstAnswer === null ? $mt : min($firstAnswer, $mt); }
        }
        if (!$optout && $this->hasColumns('contact_optouts', ['phone', 'created_at'])) {
            $ph = $this->convPhones($convs, $p);
            $optout = (int)$this->one('SELECT COUNT(*) FROM contact_optouts WHERE active = 1 AND phone IN (' . implode(',', array_fill(0, count($ph), '?'))
                                      . ') AND created_at > ? AND created_at <= ?', array_merge($ph, [$sent, self::stamp($end7)])) > 0;
        }

        // chased before the first answer, or t + 7 d: by the engine (a followup_sends row) or by a person (HumanTakeover)
        $end = $firstAnswer !== null ? min($firstAnswer, $end7) : $end7;
        $eng = false;
        if ($convs !== [] && $this->hasTable('followup_sends')) {
            $eng = (int)$this->one('SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id
                                     WHERE f.conversation_id IN (' . implode(',', $convs) . ') AND s.sent_at > ? AND s.sent_at <= ?',
                                   [$sent, self::stamp($end)]) > 0;
        }
        $person = \HumanTakeover::phoneSince($this->pdo, $p, $sent, self::stamp($end));
        $per = $person !== null;

        // registered (non-registration quotations) and paid, in (t, t + 30 d]
        $fromReg = $kycApp !== null;
        $lo = self::local($t); $hi = self::local($t + self::FOLLOW_S);
        $reg = false;
        if (!$fromReg) {
            foreach ($this->blobAll('kyc_applications') as $a) {
                $s = (string)($a['submitted_at'] ?? '');
                if ($s <= $lo || $s > $hi) continue;
                if (($client !== null && (int)($a['crm_client_id'] ?? 0) === $client)
                    || $this->canon((string)($a['mobile'] ?? '')) === $p || $this->canon((string)($a['wa_quote_phone'] ?? '')) === $p) { $reg = true; break; }
            }
        }
        $paid = $this->paid($convs, $client, $quoteId, $sent, self::stamp($t + self::FOLLOW_S), $lo, $hi);

        $sentFrom = QuoteSendEvidence::sentChannel((string)$conv['channel'], QuoteSendEvidence::forceAccounts($this->config));
        return [
            'key' => $q['key'], 't' => $t, 'p' => $p, 'number' => $spoke !== null ? self::number((string)$spoke['channel']) : self::NEVER_WROTE,
            'sent_from' => $sentFrom, 'owner' => $owner['id'], 'kyc' => $fromReg, 'answ' => $firstAnswer !== null, 'optout' => $optout,
            'eng' => $eng, 'per' => $per, 'chased' => $eng || $per, 'reg' => $reg, 'paid' => $paid,
            'neither' => $t <= $this->now - self::ANSWER_S && $firstAnswer === null && !$eng && !$per,
        ];
    }

    /** The raw phones of p's conversations, with p itself: opt-outs keep the digits they were typed with. */
    private function convPhones(array $convs, string $p): array
    {
        $out = [$p => $p];
        foreach ($convs as $id) {
            $c = $this->conversation((int)$id);
            $d = $c === null ? '' : (string)preg_replace('/\D+/', '', (string)$c['phone']);
            if ($d !== '') $out[$d] = $d;
        }
        return array_values($out);
    }

    /** §H.2.9.7's local paid signals, presence only: no amount is read into the output. */
    private function paid(array $convs, ?int $client, ?int $quoteId, string $from, string $to, string $lo, string $hi): bool
    {
        if ($convs !== []) {
            $ev = implode(',', array_fill(0, count(self::PAYMENT_EVENTS), '?'));
            if ((int)$this->one('SELECT COUNT(*) FROM wa_messages WHERE conversation_id IN (' . implode(',', $convs) . ")
                                  AND direction = 'out' AND event_key IN ({$ev}) AND sent_at > ? AND sent_at <= ?",
                                array_merge(self::PAYMENT_EVENTS, [$from, $to])) > 0) return true;
        }
        if ($client === null && $quoteId === null) return false;
        if ($client !== null && $this->hasColumns('cb_ledger', ['direction', 'crm_payment_id', 'crm_client_id', 'created_at'])) {
            if ((int)$this->one("SELECT COUNT(*) FROM cb_ledger WHERE direction = 'in' AND crm_payment_id > 0 AND crm_client_id = ?
                                  AND created_at > ? AND created_at <= ?", [$client, $lo, $hi]) > 0) return true;
        }
        if ($client !== null) {
            foreach ($this->blobAll('payment_collections') as $c) {
                $at = (string)($c['created_at'] ?? '');
                if ((string)($c['crm_customer_id'] ?? '') === (string)$client && $at > $lo && $at <= $hi) return true;
            }
        }
        foreach ($this->blobAll('kyc_applications') as $a) {
            $mine = ($client !== null && (int)($a['crm_client_id'] ?? 0) === $client)
                 || ($quoteId !== null && trim((string)($a['quote_id'] ?? '')) === (string)$quoteId);
            if (!$mine) continue;
            $s = (string)($a['submitted_at'] ?? '');
            if (strtolower(trim((string)($a['sales_type'] ?? ''))) === 'cash' && (float)($a['amount_charged'] ?? 0) > 0 && $s > $lo && $s <= $hi) return true;
        }
        return false;
    }

    // ── conversations, per number ───────────────────────────────────────────────────────────────────────────────

    /**
     * Per number and window: sessions (an inbound in W from a phone with no inbound on that number in the 24 h before),
     * the distinct customers, and the new ones (their first-ever inbound on that number in W). A web: session is the web
     * chat's row; staff-filed conversations and DishNet's own numbers are left out.
     *
     * @return array<int, array<string, array{conv:int, cust:array, new:array}>>  window => number => counts (cust/new: p => true)
     */
    public function sessions(): array
    {
        $by = [];
        foreach ($this->q("SELECT m.sent_at, c.phone, c.channel FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                            WHERE m.direction = 'in' AND COALESCE(c.category, '') <> 'staff' AND m.sent_at < ?
                            ORDER BY m.sent_at, m.id", [self::stamp($this->now)]) as $m) {
            $raw = (string)$m['phone'];
            $web = strpos($raw, 'web:') === 0;
            $p = $web ? $raw : $this->canon($raw);
            if ($p === '' || (!$web && isset($this->ownNumbers()[$p]))) continue;
            $n = $web ? self::WEB : self::number((string)$m['channel']);
            $by[$n][$p][] = UtcClock::parse((string)$m['sent_at']);
        }
        $out = [];
        foreach (self::WINDOWS as $d) {
            $from = $this->now - $d * 86400;
            foreach ($by as $n => $phones) {
                $row = ['conv' => 0, 'cust' => [], 'new' => []];
                foreach ($phones as $p => $times) {
                    $prev = null; $first = $times[0];
                    foreach ($times as $t) {
                        if ($t >= $from && $t < $this->now) {
                            if ($prev === null || $t - $prev >= 86400) $row['conv']++;
                            $row['cust'][$p] = true;
                            if ($first >= $from) $row['new'][$p] = true;
                        }
                        $prev = $t;
                    }
                }
                $out[$d][$n] = $row;
            }
        }
        return $out;
    }

    // ── after 5.18.104 (§H.2.8.2) ───────────────────────────────────────────────────────────────────────────────

    /** The tracker's parity, as Q1-ON states it (§H.2.12.5; Q17-F04): counts by key, at the tracker's reach. */
    public function parity(array $quotes): array
    {
        $since = UtcClock::parse($this->config['quote_tracking_since'] ?? '');
        $cursor = (int)($this->one("SELECT v FROM quote_followup_state WHERE k = 'tracker_cursor'") ?? 0);
        $rows = [];
        foreach ($this->q('SELECT quote_key, evidence_msg_id, backfilled, sent_at FROM quote_tracker') as $r) $rows[(string)$r['quote_key']] = $r;
        $conflict = [];
        foreach ($this->q("SELECT k FROM quote_followup_state WHERE k LIKE 'ref_conflict:%'") as $r) $conflict[substr((string)$r['k'], 13)] = true;
        $n = ['keys' => 0, 'tracked' => 0, 'missing' => 0, 'ref_conflict' => count($conflict), 'awaiting_run' => 0, 'evidence_gone' => 0, 'wrong_evidence' => 0];
        $ev = QuoteSendEvidence::EVENTS;
        $in = implode(',', array_fill(0, count($ev), '?'));
        $keysAt = []; $seen = [];
        foreach ($this->q("SELECT id, direction, event_key, body, sent_at FROM wa_messages WHERE direction = 'out' AND event_key IN ({$in}) ORDER BY id", $ev) as $m) {
            $e = QuoteSendEvidence::read($m);
            if ($e === null) continue;
            $k = QuoteSendEvidence::key($e[1]);
            $keysAt[(int)$m['id']] = $k;
            if ($since <= 0 || UtcClock::parse((string)$m['sent_at']) < $since) continue;
            if ((int)$m['id'] > $cursor) { $n['awaiting_run']++; continue; }
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $n['keys']++;
            if (isset($rows[$k])) $n['tracked']++;
            elseif (!isset($conflict[$k])) $n['missing']++;
        }
        foreach ($rows as $k => $r) {
            if ((int)$r['backfilled'] === 1 || $since <= 0 || UtcClock::parse((string)$r['sent_at']) < $since) continue;
            $mid = (int)$r['evidence_msg_id'];
            if (!array_key_exists($mid, $keysAt)) {
                if ((int)$this->one('SELECT COUNT(*) FROM wa_messages WHERE id = ?', [$mid]) === 0) $n['evidence_gone']++;
                else $n['wrong_evidence']++;
            } elseif ($keysAt[$mid] !== $k) {
                $n['wrong_evidence']++;
            }
        }
        return $n;
    }
}

// ── the command ───────────────────────────────────────────────────────────────────────────────────────────────

$sfrOut = [];
$sfrSay = function (string $l) use (&$sfrOut): void { $sfrOut[] = $l; };
$sfrRefuse = function (int $code, string $why): void { fwrite(STDERR, "sales_followup_report: refused — {$why}\n"); exit($code); };

$sfrOpt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--(db|data|at|sheet)=(.+)$/', $a, $m) && $a !== '--json') $sfrRefuse(2, 'unknown argument (known: --db= --data= --at= --sheet= --json)');
    if ($a === '--json') $sfrOpt['json'] = true; else $sfrOpt[$m[1]] = $m[2];
}
$sfrData = rtrim((string)($sfrOpt['data'] ?? ''), '/');
$sfrDb = (string)($sfrOpt['db'] ?? '');
if ($sfrData === '' || !is_dir($sfrData)) $sfrRefuse(2, '--data must be the folder that holds the copies');
if ($sfrDb === '' || !is_file($sfrDb)) $sfrRefuse(2, '--db must be the copy of the book');
if (is_file($sfrDb . '-wal') || is_file($sfrDb . '-shm')) $sfrRefuse(2, 'a -wal or -shm file sits beside the book: it is open, not a copy');
$sfrNow = time();
if (isset($sfrOpt['at'])) {
    $sfrNow = UtcClock::parse($sfrOpt['at']);
    if ($sfrNow === 0 || $sfrNow > time() + 86400) $sfrRefuse(2, '--at must be a readable UTC moment, not in the future');
}

// The tenant, from the copies beside the copy of the book (R104-18): which file decided it is printed.
$sfrConfig = PluginConfig::read($sfrRoot, $sfrData);
$sfrDecided = 'the default';
$sfrVault = (string)getenv('DN_VAULT_FILE');
if ($sfrVault !== '' && is_file($sfrVault)) {
    $sfrV = json_decode((string)file_get_contents($sfrVault), true);
    $sfrVc = is_array($sfrV) ? (array)($sfrV['config'] ?? $sfrV) : [];
    foreach (TenantProfile::SELECTOR_SOURCES as $sfrK) if (isset($sfrVc[$sfrK]) && $sfrVc[$sfrK] !== '') { $sfrDecided = 'the vault'; break; }
}
foreach ([[$sfrRoot . '/data/config.json', 'data/config.json'], [$sfrData . '/config.json', 'config.json'], [$sfrData . '/kyc_config.json', 'kyc_config.json']] as [$sfrF, $sfrL]) {
    $sfrJ = is_file($sfrF) ? json_decode((string)file_get_contents($sfrF), true) : null;
    if (is_array($sfrJ)) foreach (TenantProfile::SELECTOR_SOURCES as $sfrK) if (isset($sfrJ[$sfrK]) && $sfrJ[$sfrK] !== '') $sfrDecided = $sfrL;
}
$sfrTenant = TenantProfile::current($sfrConfig, $sfrData);
$sfrSay('== sales follow-up report (READ-ONLY) — ' . gmdate('Y-m-d\TH:i:s\Z', $sfrNow) . ' ==');
$sfrSay('  tenant     ' . $sfrTenant->id() . ' (decided by ' . $sfrDecided . ')');
if ($sfrTenant->id() !== 'uganda') {
    $sfrSay('  not this install — nothing read');
    echo implode("\n", $sfrOut) . "\n";
    exit(0);
}

// One connection to the copy: read-only, immutable, query_only (F17).
try {
    $sfrPdo = new PDO('sqlite:file:' . $sfrDb . '?immutable=1', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $sfrPdo->exec('PRAGMA query_only = ON');
    $sfrPdo->exec('PRAGMA temp_store = MEMORY');
} catch (\Throwable $e) {
    $sfrRefuse(2, 'the copy could not be opened read-only and immutable (' . get_class($e) . ')');
}
$R = new SalesFollowupReport();
$R->pdo = $sfrPdo; $R->now = $sfrNow; $R->config = $sfrConfig; $R->data = $sfrData; $R->tenant = $sfrTenant;
if ((int)$R->one('SELECT query_only FROM pragma_query_only') !== 1) { fwrite(STDERR, "sales_followup_report: query_only is not on\n"); exit(3); }
$sfrPdo->exec('BEGIN');

$sfrJson = ['tenant' => 'uganda', 'at' => gmdate('Y-m-d\TH:i:s\Z', $sfrNow)];
$sfrUnknown = function (string $t) use ($R): bool { return !$R->hasTable($t); };

// coverage
$sfrFirst = function (string $sql) use ($R): string { $v = $R->one($sql); return $v === null || $v === '' ? 'UNKNOWN' : SalesFollowupReport::day(UtcClock::parse((string)$v)); };
$sfrCovW = $R->hasTable('wa_messages') ? $sfrFirst('SELECT MIN(sent_at) FROM wa_messages') : 'UNKNOWN';
$sfrLog = $R->blobAll('quotes_log');
$sfrLogFrom = 'UNKNOWN';
foreach ($sfrLog as $sfrL) { $sfrA = (string)($sfrL['created_at'] ?? ''); if ($sfrA !== '' && ($sfrLogFrom === 'UNKNOWN' || $sfrA < $sfrLogFrom)) $sfrLogFrom = substr($sfrA, 0, 10); }
$sfrKyc = $R->blobAll('kyc_applications');
$sfrKycFrom = 'UNKNOWN';
foreach ($sfrKyc as $sfrA) { $sfrS = (string)($sfrA['submitted_at'] ?? ''); if ($sfrS !== '' && ($sfrKycFrom === 'UNKNOWN' || $sfrS < $sfrKycFrom)) $sfrKycFrom = substr($sfrS, 0, 10); }
$sfrIncomplete = [];
foreach (SalesFollowupReport::WINDOWS as $sfrD) {
    $sfrStart = SalesFollowupReport::day($sfrNow - $sfrD * 86400);
    foreach ([$sfrCovW, $sfrLogFrom] as $sfrF) if ($sfrF === 'UNKNOWN' || $sfrF > $sfrStart) { $sfrIncomplete[$sfrD] = true; }
}
$sfrSay('  copy       ' . basename($sfrDb) . ' · opened read-only, immutable, query_only=1');
$sfrSay('  windows    30 d: ' . SalesFollowupReport::day($sfrNow - 30 * 86400) . ' → ' . SalesFollowupReport::day($sfrNow)
      . ' · 90 d: ' . SalesFollowupReport::day($sfrNow - 90 * 86400) . ' → ' . SalesFollowupReport::day($sfrNow) . ' (Africa/Kampala dates; UTC windows)');
$sfrSay('  coverage   wa_messages from ' . $sfrCovW . ' · quotes_log from ' . $sfrLogFrom . ', ' . count($sfrLog) . ' rows; cut at 2000'
      . ' · kyc_applications from ' . $sfrKycFrom
      . ($sfrIncomplete ? ' — INCOMPLETE: ' . implode(', ', array_map(function ($d) { return $d . ' d'; }, array_keys($sfrIncomplete))) : ''));
$sfrJson['coverage'] = ['wa_messages_from' => $sfrCovW, 'quotes_log_from' => $sfrLogFrom, 'quotes_log_rows' => count($sfrLog),
                        'kyc_applications_from' => $sfrKycFrom, 'incomplete' => array_keys($sfrIncomplete)];

// the quotations, judged once
$sfrQuotes = $R->hasTable('wa_messages') ? $R->quotations() : [];
$sfrJudged = [];
foreach ($sfrQuotes as $sfrQ) {
    if ($sfrQ['t'] < $sfrNow - 90 * 86400 || $sfrQ['t'] >= $sfrNow) continue;
    $sfrJ = $R->judge($sfrQ);
    if ($sfrJ !== null) $sfrJudged[] = $sfrJ;
}
// owners as `owner n`, numbered by staff id; the mapping only to the sheet
$sfrOwnerIds = [];
foreach ($sfrJudged as $sfrJ) if ($sfrJ['owner'] !== null) $sfrOwnerIds[(int)$sfrJ['owner']] = true;
foreach ($R->hasTable('wa_channels') ? $R->q("SELECT owner_staff_id FROM wa_channels WHERE owner_type = 'staff' AND owner_staff_id IS NOT NULL") : [] as $sfrC) {
    $sfrOwnerIds[(int)$sfrC['owner_staff_id']] = true;
}
ksort($sfrOwnerIds);
$sfrOwnerN = []; $sfrI = 0;
foreach (array_keys($sfrOwnerIds) as $sfrId) $sfrOwnerN[$sfrId] = ++$sfrI;
$sfrOwnerLabel = function (?int $id) use ($sfrOwnerN): string { return $id === null ? 'unattributed' : 'owner ' . $sfrOwnerN[$id]; };

$sfrBlank = function (): array { return array_fill_keys(SalesFollowupReport::COLS, 0); };
$sfrAdd = function (array &$row, array $j): void {
    $row['quotes']++;
    foreach (['kyc', 'answ', 'chased', 'eng', 'per', 'neither', 'reg', 'paid', 'optout'] as $k) if ($j[$k]) $row[$k]++;
};
$sfrSess = $R->hasTable('wa_messages') ? $R->sessions() : [];
foreach (SalesFollowupReport::WINDOWS as $sfrD) {
    $sfrFrom = $sfrNow - $sfrD * 86400;
    $sfrRows = []; $sfrAll = $sfrBlank(); $sfrAllCust = []; $sfrAllNew = []; $sfrSentFrom = ['support' => 0, 'account' => 0];
    foreach ($sfrSess[$sfrD] ?? [] as $sfrN => $sfrS) {
        $sfrRows[$sfrN] = $sfrRows[$sfrN] ?? $sfrBlank();
        $sfrRows[$sfrN]['conv'] = $sfrS['conv']; $sfrRows[$sfrN]['cust'] = count($sfrS['cust']); $sfrRows[$sfrN]['new'] = count($sfrS['new']);
        $sfrAll['conv'] += $sfrS['conv'];
        $sfrAllCust += $sfrS['cust']; $sfrAllNew += $sfrS['new'];
    }
    foreach ($sfrJudged as $sfrJ) {
        if ($sfrJ['t'] < $sfrFrom) continue;
        $sfrN = $sfrJ['number'];
        $sfrRows[$sfrN] = $sfrRows[$sfrN] ?? $sfrBlank();
        $sfrAdd($sfrRows[$sfrN], $sfrJ);
        $sfrAdd($sfrAll, $sfrJ);
        $sfrSentFrom[$sfrJ['sent_from']] = ($sfrSentFrom[$sfrJ['sent_from']] ?? 0) + 1;
    }
    $sfrAll['cust'] = count($sfrAllCust); $sfrAll['new'] = count($sfrAllNew);
    $sfrOrder = array_keys($sfrRows);
    usort($sfrOrder, function ($a, $b) {
        $rank = function ($n) { return $n === SalesFollowupReport::NEVER_WROTE ? 2 : ($n === SalesFollowupReport::WEB ? 3 : 1); };
        return [$rank($a), $a] <=> [$rank($b), $b];
    });
    $sfrSay('');
    $sfrSay("  BY NUMBER ({$sfrD} d) — the number the customer spoke to");
    $sfrSay('  ' . str_pad('number', 46) . str_pad('role', 9) . str_pad('owner', 13) . str_pad('line', 7)
          . 'conv cust new quotes kyc answ chased(eng/per) neither reg paid optout');
    $sfrJson['by_number'][$sfrD] = [];
    foreach ($sfrOrder as $sfrN) {
        $sfrR = $sfrRows[$sfrN];
        $sfrCh = ($sfrN === SalesFollowupReport::NEVER_WROTE || $sfrN === SalesFollowupReport::WEB) ? null : $R->channelRow($sfrN);
        $sfrRole = $sfrCh !== null ? (string)$sfrCh['role'] : ($sfrN === SalesFollowupReport::NEVER_WROTE || $sfrN === SalesFollowupReport::WEB ? '—' : $sfrN);
        $sfrOt = $sfrCh !== null ? (string)$sfrCh['owner_type'] : '—';
        $sfrOwn = $sfrOt === 'staff' ? $sfrOwnerLabel((int)$sfrCh['owner_staff_id']) : ($sfrOt === 'partner' ? 'partner' : ($sfrOt === 'department' ? 'dept' : '—'));
        $sfrLine = '—';
        if ($sfrCh !== null && trim((string)($sfrCh['business_number'] ?? '')) !== '') $sfrLine = '••••' . substr((string)preg_replace('/\D+/', '', (string)$sfrCh['business_number']), -2);   // ChannelRegistry::mask's form
        $sfrSay('  ' . str_pad($sfrN, 46) . str_pad($sfrRole, 9) . str_pad($sfrOwn, 13) . str_pad($sfrLine, 7)
              . sprintf('%4d %4d %3d %6d %3d %4d %6d (%d/%d) %7d %3d %4d %6d', $sfrR['conv'], $sfrR['cust'], $sfrR['new'], $sfrR['quotes'],
                        $sfrR['kyc'], $sfrR['answ'], $sfrR['chased'], $sfrR['eng'], $sfrR['per'], $sfrR['neither'], $sfrR['reg'], $sfrR['paid'], $sfrR['optout']));
        $sfrJson['by_number'][$sfrD][$sfrN] = $sfrR + ['role' => $sfrRole, 'owner' => $sfrOwn, 'line' => $sfrLine];
    }
    $sfrSay('  ' . str_pad('ALL', 75) . sprintf('%4d %4d %3d %6d %3d %4d %6d (%d/%d) %7d %3d %4d %6d', $sfrAll['conv'], $sfrAll['cust'], $sfrAll['new'],
              $sfrAll['quotes'], $sfrAll['kyc'], $sfrAll['answ'], $sfrAll['chased'], $sfrAll['eng'], $sfrAll['per'], $sfrAll['neither'], $sfrAll['reg'],
              $sfrAll['paid'], $sfrAll['optout']) . '   sent from: support ' . (int)$sfrSentFrom['support'] . ' · account ' . (int)$sfrSentFrom['account']);
    $sfrJson['by_number'][$sfrD]['ALL'] = $sfrAll + ['sent_from' => $sfrSentFrom];
}

// never wrote (F5), by owner, the headline, still open
$sfrNw = ['support' => 0, 'account' => 0]; $sfrNwN = 0;
foreach ($sfrJudged as $sfrJ) if ($sfrJ['t'] >= $sfrNow - 30 * 86400 && $sfrJ['number'] === SalesFollowupReport::NEVER_WROTE) { $sfrNwN++; $sfrNw[$sfrJ['sent_from']]++; }
$sfrAsRec = function (string $ch) use ($R): string {
    $c = $R->channelRow($ch);
    if ($c === null) return 'not recorded';
    return 'status ' . (preg_match('/^[a-z]{1,12}$/', (string)$c['status']) ? (string)$c['status'] : 'other') . ', assistant ' . ((int)$c['ai_enabled'] === 1 ? 'on' : 'off');
};
$sfrSay('');
$sfrSay("  NEVER WROTE (F5, 30 d): {$sfrNwN} — left from support {$sfrNw['support']} (" . $sfrAsRec('support') . ") · account {$sfrNw['account']} ("
      . $sfrAsRec('account') . ') · chasable/refused UNKNOWN (offline)');
$sfrJson['never_wrote_30d'] = ['n' => $sfrNwN] + $sfrNw;
$sfrSay('  BY OWNER (30 d | 90 d): quotes kyc answ chased neither reg paid');
$sfrOwners = [];
foreach ($sfrJudged as $sfrJ) {
    $sfrL = $sfrOwnerLabel($sfrJ['owner']);
    foreach (SalesFollowupReport::WINDOWS as $sfrD) {
        $sfrOwners[$sfrL][$sfrD] = $sfrOwners[$sfrL][$sfrD] ?? $sfrBlank();
        if ($sfrJ['t'] >= $sfrNow - $sfrD * 86400) $sfrAdd($sfrOwners[$sfrL][$sfrD], $sfrJ);
    }
}
uksort($sfrOwners, function ($a, $b) {
    $na = $a === 'unattributed' ? PHP_INT_MAX : (int)substr($a, 6); $nb = $b === 'unattributed' ? PHP_INT_MAX : (int)substr($b, 6);
    return $na <=> $nb;
});
foreach ($sfrOwners as $sfrL => $sfrW) {
    $sfrF = function (array $r): string { return sprintf('%d %d %d %d %d %d %d', $r['quotes'], $r['kyc'], $r['answ'], $r['chased'], $r['neither'], $r['reg'], $r['paid']); };
    $sfrSay('    ' . str_pad($sfrL, 14) . $sfrF($sfrW[30]) . ' | ' . $sfrF($sfrW[90]));
    $sfrJson['by_owner'][$sfrL] = $sfrW;
}
$sfrHead = [];
foreach (SalesFollowupReport::WINDOWS as $sfrD) {
    $sfrDen = 0; $sfrNum = 0;
    foreach ($sfrJudged as $sfrJ) {
        if ($sfrJ['t'] < $sfrNow - $sfrD * 86400 || $sfrJ['t'] > $sfrNow - SalesFollowupReport::ANSWER_S) continue;
        $sfrDen++; if ($sfrJ['neither']) $sfrNum++;
    }
    $sfrHead[$sfrD] = [$sfrNum, $sfrDen];
}
$sfrOpen7 = 0; $sfrOpen30 = 0;
foreach ($sfrJudged as $sfrJ) {
    if ($sfrJ['t'] > $sfrNow - SalesFollowupReport::ANSWER_S) $sfrOpen7++;
    if ($sfrJ['t'] > $sfrNow - SalesFollowupReport::FOLLOW_S) $sfrOpen30++;
}
$sfrSay("  HEADLINE   quotes neither answered nor chased within 7 days: {$sfrHead[30][0]} of {$sfrHead[30][1]} (30 d) · {$sfrHead[90][0]} of {$sfrHead[90][1]} (90 d)");
$sfrSay("  STILL OPEN sent less than 7 days ago: {$sfrOpen7} · less than 30 days ago (reg/paid not final): {$sfrOpen30}");
$sfrJson['headline'] = ['30' => $sfrHead[30], '90' => $sfrHead[90]];
$sfrJson['still_open'] = ['7d' => $sfrOpen7, '30d' => $sfrOpen30];

// diagnostics
$sfrKeys = array_flip(array_keys($sfrQuotes));
$sfrClaimed = 'UNKNOWN';
if ($R->hasColumns('wa_sent_quotes', ['quote_ref', 'sent_at'])) {
    $sfrClaimed = 0;
    foreach ($R->q('SELECT quote_ref, sent_at FROM wa_sent_quotes WHERE sent_at >= ? AND sent_at < ?', [SalesFollowupReport::stamp($sfrNow - 90 * 86400), SalesFollowupReport::stamp($sfrNow)]) as $sfrC) {
        $sfrRef = QuoteSendEvidence::clean((string)$sfrC['quote_ref']);
        if ($sfrRef !== '' && !isset($sfrKeys[QuoteSendEvidence::key($sfrRef)])) $sfrClaimed++;
    }
}
$sfrLogged = $R->hasTable('quotes_log') ? 0 : 'UNKNOWN';
$sfrLocal90 = SalesFollowupReport::local($sfrNow - 90 * 86400);
foreach ($sfrLog as $sfrL) {
    if ((string)($sfrL['created_at'] ?? '') < $sfrLocal90) continue;   // date(), Kampala
    $sfrRef = QuoteSendEvidence::clean((string)($sfrL['quote_ref'] ?? ''));
    if ($sfrRef !== '' && !isset($sfrKeys[QuoteSendEvidence::key($sfrRef)])) $sfrLogged++;
}
$sfrRefused = [];
foreach ($sfrKyc as $sfrA) {
    $sfrW = trim((string)($sfrA['quote_refused'] ?? ''));
    if ($sfrW === '') continue;
    $sfrW = preg_match('/^[A-Za-z][A-Za-z0-9_]{0,23}$/', $sfrW) ? $sfrW : 'other';
    $sfrRefused[$sfrW] = ($sfrRefused[$sfrW] ?? 0) + 1;
}
ksort($sfrRefused);
$sfrRefTxt = array_sum($sfrRefused) . ($sfrRefused ? ' (' . implode(', ', array_map(function ($k, $v) { return $k . ' ' . $v; }, array_keys($sfrRefused), $sfrRefused)) . ')' : '');
$sfrSay("  DIAGNOSTICS claimed, no message {$sfrClaimed} · logged, no message {$sfrLogged} · owner ambiguous {$R->diag['owner_ambiguous']} · phone unparseable {$R->diag['phone_unparseable']}");
$sfrSay("              · registrations with no automatic quote by reason {$sfrRefTxt} · standard quotations used UNKNOWN (not recorded)");
$sfrJson['diagnostics'] = ['claimed_no_message' => $sfrClaimed, 'logged_no_message' => $sfrLogged, 'owner_ambiguous' => $R->diag['owner_ambiguous'],
                           'phone_unparseable' => $R->diag['phone_unparseable'], 'registrations_no_quote' => $sfrRefused, 'standard_quotations' => 'UNKNOWN'];

// after 5.18.104 (§H.2.8.2): UNKNOWN on a book 089 has not reached
if ($sfrUnknown('quote_tracker')) {
    $sfrSay('  AFTER 5.18.104 not deployed — tracked UNKNOWN · parity UNKNOWN · chases UNKNOWN · held UNKNOWN · stops UNKNOWN · ledger UNKNOWN · marks UNKNOWN');
    $sfrJson['after'] = 'UNKNOWN';
} else {
    $sfrA = ['tracked' => (int)$R->one('SELECT COUNT(*) FROM quote_tracker')];
    $sfrA['parity'] = $R->parity($sfrQuotes);
    $sfrA['chases'] = [];
    if ($R->hasColumns('followups', ['kind'])) {
        foreach ($R->q("SELECT COALESCE(d.model, '') AS tpl, COUNT(*) AS n FROM followup_sends s JOIN followups f ON f.id = s.followup_id
                         LEFT JOIN followup_drafts d ON d.id = s.draft_id WHERE f.kind = 'quote' GROUP BY tpl ORDER BY tpl") as $sfrC) {
            $sfrT = (string)$sfrC['tpl'];
            $sfrA['chases'][preg_match('/^template:quote_chase_v1:[1-7][AB]p?$/', $sfrT) ? substr($sfrT, 9) : 'other'] = (int)$sfrC['n'];
        }
    }
    $sfrCode = function (string $c): string { return preg_match('/^[a-z][a-z0-9_:]{0,40}$/', $c) ? $c : 'other'; };
    $sfrA['held'] = []; $sfrA['stops'] = [];
    foreach ($R->q("SELECT chase_hold, COUNT(*) AS n FROM quote_tracker WHERE chase_hold IS NOT NULL GROUP BY chase_hold ORDER BY chase_hold") as $sfrC) $sfrA['held'][$sfrCode((string)$sfrC['chase_hold'])] = (int)$sfrC['n'];
    foreach ($R->q("SELECT status, COALESCE(status_reason, '') AS why, COUNT(*) AS n FROM quote_tracker WHERE status <> 'open' GROUP BY status, why ORDER BY status, why") as $sfrC) {
        $sfrA['stops'][$sfrCode((string)$sfrC['status']) . ($sfrC['why'] !== '' ? '/' . $sfrCode((string)$sfrC['why']) : '')] = (int)$sfrC['n'];
    }
    $sfrA['ledger'] = 'UNKNOWN';
    if ($R->hasTable('wa_auto_sends')) {
        require_once $sfrRoot . '/lib/WaSendBudget.php';
        $sfrB = WaSendBudget::onConnection($sfrPdo, $sfrConfig, $sfrData);
        $sfrRoles = [];
        foreach (['sales', 'support', 'account'] as $sfrRole) {
            $sfrH = WaSendBudget::handset((string)($sfrConfig['evo_instance_' . $sfrRole] ?? ''));
            if ($sfrH !== '' && !isset($sfrRoles[$sfrH])) $sfrRoles[$sfrH] = $sfrRole;
        }
        $sfrA['ledger'] = []; $sfrIdx = [];
        foreach ($R->q('SELECT handset, COUNT(*) AS n FROM wa_auto_sends WHERE claimed_at >= ? GROUP BY handset ORDER BY handset', [SalesFollowupReport::stamp($sfrNow - 7 * 86400)]) as $sfrC) {
            $sfrRole = $sfrRoles[(string)$sfrC['handset']] ?? 'other';
            $sfrIdx[$sfrRole] = ($sfrIdx[$sfrRole] ?? 0) + 1;
            $sfrCt = $sfrB->counts((string)$sfrC['handset']);
            $sfrA['ledger'][$sfrRole . ' #' . $sfrIdx[$sfrRole]] = ['today' => (int)$sfrCt['total_today'], 'proactive_today' => (int)$sfrCt['proactive_today'],
                                                                   '7d' => (int)$sfrC['n'], 'in_flight' => (int)$sfrCt['in_flight']];
        }
        $sfrA['ledger_total_7d'] = (int)$R->one('SELECT COUNT(*) FROM wa_auto_sends WHERE claimed_at >= ?', [SalesFollowupReport::stamp($sfrNow - 7 * 86400)]);
    }
    $sfrA['marks'] = 'UNKNOWN';
    if ($R->hasTable('wa_seen_marks')) {
        $sfrA['marks'] = [];
        foreach ($R->q('SELECT seen_at, direction, kind FROM wa_seen_marks WHERE seen_at >= ? ORDER BY seen_at', [SalesFollowupReport::stamp($sfrNow - 7 * 86400)]) as $sfrC) {
            $sfrDay = SalesFollowupReport::day(UtcClock::parse((string)$sfrC['seen_at']));
            $sfrA['marks'][$sfrDay] = $sfrA['marks'][$sfrDay] ?? ['in' => 0, 'out' => 0, 'lid_no_number' => 0];
            $sfrA['marks'][$sfrDay][(string)$sfrC['direction'] === 'in' ? 'in' : 'out']++;
            if ((string)$sfrC['kind'] === 'lid_no_number') $sfrA['marks'][$sfrDay]['lid_no_number']++;
        }
    }
    $sfrKv = function (array $a): string { return $a === [] ? 'none' : implode(', ', array_map(function ($k, $v) { return $k . ' ' . (is_array($v) ? json_encode($v) : $v); }, array_keys($a), $a)); };
    $sfrP = $sfrA['parity'];
    $sfrSay("  AFTER 5.18.104 tracked {$sfrA['tracked']} · parity: keys {$sfrP['keys']} · tracked {$sfrP['tracked']} · missing {$sfrP['missing']}"
          . " · ref_conflict {$sfrP['ref_conflict']} · awaiting_run {$sfrP['awaiting_run']} · evidence_gone {$sfrP['evidence_gone']} · wrong_evidence {$sfrP['wrong_evidence']}");
    $sfrSay('              · chases by step and template: ' . $sfrKv($sfrA['chases']));
    $sfrSay('              · held by reason: ' . $sfrKv($sfrA['held']) . ' · stops by reason: ' . $sfrKv($sfrA['stops']));
    $sfrSay('              · automated sends per number, today / 7 d: ' . (is_array($sfrA['ledger']) ? $sfrKv(array_map(function ($v) { return $v['today'] . ' / ' . $v['7d']; }, $sfrA['ledger']))
          . ' · total 7 d ' . $sfrA['ledger_total_7d'] : 'UNKNOWN'));
    $sfrSay('              · marks per day: ' . (is_array($sfrA['marks']) ? $sfrKv($sfrA['marks']) : 'UNKNOWN'));
    $sfrJson['after'] = $sfrA;
}

// the owners' sheet: owner n = staff #id, never in the output
if (isset($sfrOpt['sheet'])) {
    $sfrOld = umask(077);
    $sfrFh = @fopen($sfrOpt['sheet'], 'x');
    umask($sfrOld);
    if ($sfrFh === false) {
        $sfrSay('  owners     ' . count($sfrOwnerN) . ' — the sheet was NOT written (it exists already, or the folder is not writable)');
    } else {
        foreach ($sfrOwnerN as $sfrId => $sfrN) fwrite($sfrFh, "owner {$sfrN} = staff #{$sfrId}\n");
        fclose($sfrFh);
        @chmod($sfrOpt['sheet'], 0600);
        $sfrSay('  owners     ' . count($sfrOwnerN) . ' — mapped to staff ids in the sheet (mode 0600), not here');
    }
} else {
    $sfrSay('  owners     ' . count($sfrOwnerN) . ' — no sheet asked for (--sheet=<file>)');
}
$sfrJson['owners'] = count($sfrOwnerN);

$sfrPdo->exec('ROLLBACK');
$sfrChanged = (int)$R->one('SELECT total_changes()');
if ($sfrChanged !== 0) { fwrite(STDERR, "sales_followup_report: the connection changed {$sfrChanged} row(s)\n"); exit(3); }
$sfrSay('  READ-ONLY — nothing was changed.');
if (!empty($sfrOpt['json'])) {
    echo json_encode($sfrJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo implode("\n", $sfrOut) . "\n";
}
exit(0);
