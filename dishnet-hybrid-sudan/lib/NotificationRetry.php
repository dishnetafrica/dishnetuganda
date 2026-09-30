<?php
/**
 * NotificationRetry — 5.18.54 (docs/46 row 30, M4), Uganda only (NotifyGate RETRIES): failed customer WhatsApps are
 * retried automatically, a bounded number of times, when two things are certain.
 *
 * - The message cannot have reached the customer: WhatsApp answered and refused it (an HTTP 4xx), or the request never
 *   left (row 31's "Not sent — "). A send that may have gone (row 31's "May have been sent — ", a gateway's 502 or 504)
 *   and a failure this class cannot read are never retried automatically: a person decides, after looking at the chat.
 * - The message is still true when it goes: a receipt, a welcome, a quotation (EVENTS). An invoice notice, a reminder
 *   or a balance can be overtaken by a payment in the meantime, and a service or installation message by events; they
 *   wait for a person, as before. So do documents, staff messages and every event EVENTS does not name.
 *
 * At most three automatic tries, 10, 30 and 120 minutes after the previous attempt, and none later than six hours after
 * the message was first queued. A failed automatic try that leaves no further try sets the row to `exhausted`: it stays
 * in the Failed Queue, where a person can still retry or dismiss it, and nothing is tried automatically again.
 *
 * `attempts` counts every send of the row, a person's retries included, and bounds the tries. A message that a person
 * sent again by hand outside the Failed Queue is not seen here: its row should be dismissed.
 *
 * Times: `created_at` is SQLite's datetime('now'), UTC; `last_attempt_at` is written with date(), in this install's
 * zone, and read back in it.
 */
final class NotificationRetry
{
    /** Customer texts that stay true for the retry window. */
    public const EVENTS = [
        // receipts
        'ops_payment_received', 'ops_invoice_auto_paid',
        // welcomes
        'event_client_add', 'ops_kyc_customer_welcome',
        // quotations: quote.add, the quote cron, the WhatsApp tab, the quotation screens
        'ops_quote_created', 'ops_quote_wa', 'ops_quote_text', 'quote_kyc', 'quote_lead', 'quote_cash', 'quote_manual',
    ];

    /** The first send and three automatic retries. */
    public const MAX_ATTEMPTS = 4;
    /** Minutes from the previous attempt to the next automatic try, by the attempts made so far. */
    public const GAP_MIN = [1 => 10, 2 => 30, 3 => 120];
    /** No automatic try later than this after the message was first queued. */
    public const WINDOW_MIN = 360;
    /** A row still `retrying` after this long: its try never finished. */
    public const STALE_MIN = 15;
    /** At most this many tries in one run, and none started after RUN_BUDGET_SEC: master.php gives a job 60 s. */
    public const PER_RUN = 5;
    public const RUN_BUDGET_SEC = 30;

    public const NOT_SENT   = 'not_sent';
    public const REFUSED    = 'refused';
    public const MAYBE_SENT = 'may_have_been_sent';
    public const NO_ATTEMPT = 'no_attempt';
    public const UNKNOWN    = 'unknown';

    /** A retry that sent nothing at all: the number opted out or cannot be used, or WhatsApp is not set up. */
    public const NO_ATTEMPT_TEXT = 'Not sent — no attempt was made: the number has opted out or cannot be used, or WhatsApp is not set up';
    /** A try that never finished: the process stopped during the send. */
    public const UNFINISHED_TEXT = 'May have been sent — its last try did not finish (the process stopped during the send); check the chat before retrying';

    /** What a failure says about whether the message can have reached the customer. */
    public static function classify($error, $http = null): string
    {
        require_once __DIR__ . '/EvolutionApiService.php';
        $e = (string)$error;
        if (\EvolutionApiService::mayHaveBeenSent($e)) return self::MAYBE_SENT;
        if (strpos($e, self::NO_ATTEMPT_TEXT) === 0) return self::NO_ATTEMPT;
        if (strpos($e, \EvolutionApiService::NOT_SENT) === 0) return self::NOT_SENT;
        if (preg_match('/\[HTTP 4\d\d on POST /', $e)) return self::REFUSED;   // Evolution answered, and refused it
        $h = (int)$http;
        if ($h >= 400 && $h < 500) return self::REFUSED;                       // WASender answered, and refused it
        return self::UNKNOWN;
    }

    /** Whether the automatic retry may ever send this row: the right message, and a failure that is certain. */
    public static function retryable(array $row): bool
    {
        if (!in_array((string)($row['event'] ?? ''), self::EVENTS, true)) return false;
        $vars = json_decode((string)($row['vars'] ?? ''), true);
        if (is_array($vars) && ($vars['_type'] ?? '') === 'document') return false;
        $class = self::classify($row['error'] ?? null, $row['http_code'] ?? null);
        return $class === self::NOT_SENT || $class === self::REFUSED;
    }

    /** When the next automatic try of this row is due, as a Unix time; null when there is none. */
    public static function nextTryAt(array $row): ?int
    {
        if ((string)($row['status'] ?? '') !== 'failed' || !self::retryable($row)) return null;
        $n = max(1, (int)($row['attempts'] ?? 1));
        if ($n >= self::MAX_ATTEMPTS || !isset(self::GAP_MIN[$n])) return null;
        $created = trim((string)($row['created_at'] ?? ''));
        $last    = strtotime((string)($row['last_attempt_at'] ?? ''));
        $first   = $created !== '' ? strtotime($created . ' UTC') : false;
        if ($last === false || $first === false) return null;
        $at = $last + self::GAP_MIN[$n] * 60;
        return $at <= $first + self::WINDOW_MIN * 60 ? $at : null;
    }

    /**
     * One pass. A try that never finished is recorded as one that may have been sent; then the rows that are due are
     * retried, at most PER_RUN of them. Returns what it did, as counts.
     *
     * @param callable|null $log receives one line per row touched
     */
    public static function run(\NotificationService $ns, \PDO $pdo, int $now, ?callable $log = null): array
    {
        $say = $log ?? static function (string $m): void {};
        $out = ['unfinished' => 0, 'due' => 0, 'tried' => 0, 'sent' => 0, 'failed' => 0, 'exhausted' => 0,
                'busy' => 0, 'deferred' => 0];

        foreach ($pdo->query("SELECT id, last_attempt_at FROM notification_queue WHERE status = 'retrying'")
                     ->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $t = strtotime((string)$r['last_attempt_at']);
            if ($t === false || $t > $now - self::STALE_MIN * 60) continue;
            $u = $pdo->prepare("UPDATE notification_queue SET status = 'failed', error = ? WHERE id = ? AND status = 'retrying'");
            $u->execute([self::UNFINISHED_TEXT, (int)$r['id']]);
            if ($u->rowCount() === 1) {
                $out['unfinished']++;
                $say("#{$r['id']}: its last try did not finish — it may have been sent, so it waits for a person");
            }
        }

        $in  = implode(',', array_fill(0, count(self::EVENTS), '?'));
        $sel = $pdo->prepare("SELECT * FROM notification_queue
                               WHERE status = 'failed' AND attempts < ? AND event IN ({$in}) AND created_at >= ?
                               ORDER BY created_at, id LIMIT 200");
        $sel->execute(array_merge([self::MAX_ATTEMPTS], self::EVENTS,
                                  [gmdate('Y-m-d H:i:s', $now - self::WINDOW_MIN * 60)]));
        $due = [];
        foreach ($sel->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $at = self::nextTryAt($r);
            if ($at !== null && $at <= $now) $due[] = $r;
        }
        $out['due'] = count($due);

        $started = microtime(true);
        foreach ($due as $r) {
            $id = (int)$r['id'];
            if ($out['tried'] >= self::PER_RUN || microtime(true) - $started > self::RUN_BUDGET_SEC) {
                $out['deferred']++;
                continue;
            }
            $res = $ns->retryOne($id, 'automatic retry');
            if (empty($res['claimed']) && empty($res['success'])) {   // a person's retry, or another run, has it
                $out['busy']++;
                continue;
            }
            $out['tried']++;
            if (!empty($res['success'])) {
                $out['sent']++;
                $say("#{$id} ({$r['event']}): sent on automatic try " . ((int)$r['attempts']) . ' of ' . (self::MAX_ATTEMPTS - 1));
                continue;
            }
            $st = $pdo->prepare("SELECT * FROM notification_queue WHERE id = ?");
            $st->execute([$id]);
            $now2 = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            if (($now2['status'] ?? '') === 'failed' && self::nextTryAt($now2) === null) {
                $pdo->prepare("UPDATE notification_queue SET status = 'exhausted' WHERE id = ? AND status = 'failed'")->execute([$id]);
                $out['exhausted']++;
                $say("#{$id} ({$r['event']}): failed again, no automatic try left — exhausted: " . mb_substr((string)($res['error'] ?? ''), 0, 120));
            } else {
                $out['failed']++;
                $say("#{$id} ({$r['event']}): failed again, tried again later: " . mb_substr((string)($res['error'] ?? ''), 0, 120));
            }
        }
        return $out;
    }
}
