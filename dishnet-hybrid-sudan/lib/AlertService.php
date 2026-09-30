<?php
declare(strict_types=1);

require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/UtcClock.php';

/**
 * AlertService — tell a human, on WhatsApp, that something needs them.
 *
 * Ported from the South Sudan n8n bot, collapsed to this operation's reality:
 * there are no separate sales/support/accounts staff, so every alert goes to
 * ONE number the operator sets. Blank means alerts are off, and the preflight
 * warns about it -- an escalation nobody hears is the failure this exists to
 * end. Eleven customer messages once sat in the queue for hours and the only
 * way anyone found out was by running SQL.
 *
 * Cooldowns are per alert key, so a customer who triggers the same condition
 * repeatedly produces one alert per window, not a buzzing pocket. That is the
 * South Sudan lesson kept intact: alerts people learn to ignore are worse
 * than none.
 */
class AlertService
{
    const LOCK_FILE = 'alert_locks.json';

    /** @var mixed JsonStore|SqliteStore */
    private $store;
    private array $config;
    private $evo;   // EvolutionApiService|null — injectable for tests

    public function __construct($store, array $config, $evo = null)
    {
        $this->store  = $store;
        $this->config = $config;
        $this->evo    = $evo;
    }

    /** The number alerts go to. Empty string means alerts are disabled. */
    public function target(): string
    {
        return preg_replace('/[^0-9+]/', '', (string)($this->config['alert_whatsapp'] ?? ''));
    }

    /**
     * Send one alert, unless the same key fired inside its cooldown.
     *
     * @param string $key      what this alert is about, e.g. "escalate:conv:7"
     * @param string $text     the message a person reads on their phone
     * @param int    $cooldownMin  silence window for this key
     * @return array{sent:bool,reason:string}
     */
    public function notify(string $key, string $text, int $cooldownMin = 240): array
    {
        $to = $this->target();
        if ($to === '') { $this->record($key, '', $text, null, 'no_alert_number'); return ['sent' => false, 'reason' => 'no_alert_number']; }

        $now = time();
        if ($cooldownMin > 0 && $this->lastSent($key) > $now - $cooldownMin * 60) {
            return ['sent' => false, 'reason' => 'cooldown'];
        }

        // The lock is taken BEFORE the send. If the send fails the lock is
        // released, so a transient Evolution error does not silence the alert
        // for the whole window -- but two workers racing cannot double-send.
        $this->recordSent($key, $now);

        try {
            $evo = $this->evo;
            if ($evo === null) {
                require_once __DIR__ . '/EvolutionApiService.php';
                $evo = new EvolutionApiService($this->config);
            }
            $r = $evo->sendText('sales', $to, $text, ContactOptOut::CLASS_STAFF);
            if (empty($r['ok'])) {
                $this->recordSent($key, 0);          // release: let the next run retry
                $this->record($key, $to, $text, false, (string)($r['error'] ?? '?'), isset($r['http']) ? (int)$r['http'] : null);
                return ['sent' => false, 'reason' => 'send_failed: ' . (string)($r['error'] ?? '?')];
            }
            $this->record($key, $to, $text, true, null, isset($r['http']) ? (int)$r['http'] : null);
            return ['sent' => true, 'reason' => 'sent'];
        } catch (\Throwable $e) {
            $this->recordSent($key, 0);
            $this->record($key, $to, $text, false, $e->getMessage());
            return ['sent' => false, 'reason' => 'send_failed: ' . $e->getMessage()];
        }
    }

    /**
     * 5.18.54 (docs/46 row 26, S-4), Uganda only: every alert leaves a trace. One that went, or failed, is a Message Log
     * row like any other send; one with no number to go to is a line in uCRM's log for the plugin, once per alert and
     * day. Before, an alert through here left nothing, and a missing alert looked like a quiet day. A record that
     * cannot be written never stops the alert.
     *
     * @param bool|null $ok  null: not attempted, for $why
     */
    private function record(string $key, string $to, string $text, ?bool $ok, ?string $why, ?int $http = null): void
    {
        try {
            require_once __DIR__ . '/NotifyGate.php';
            $dir = method_exists($this->store, 'getDataDir') ? $this->store->getDataDir() : null;
            if (!NotifyGate::applies(NotifyGate::STAFF_SIDE, $this->config, $dir)) return;
            require_once __DIR__ . '/NotificationService.php';
            $ns = new NotificationService($this->store, $this->config);
            $event = 'ops_alert_' . (string)preg_replace('/[^a-z0-9_]/', '_', strtolower(strtok($key, ':') ?: 'alert'));
            if ($ok === null) {
                if (!$ns->dedupMark('NOALERT:' . $key . ':' . date('Y-m-d'))) return;
                require_once __DIR__ . '/PluginLog.php';
                PluginLog::write('alerts', "an alert ({$event}) was not sent: no alert number is set (alert_whatsapp)");
                return;
            }
            $ns->logSend('sales', $event, $to, $text, $ok, $http, $why);
        } catch (\Throwable $e) { /* the alert matters more than its record */ }
    }

    private function lastSent(string $key): int
    {
        try {
            foreach ($this->store->load(self::LOCK_FILE) as $r) {
                if (($r['key'] ?? '') === $key) return (int)($r['ts'] ?? 0);
            }
        } catch (\Throwable $e) { /* none yet */ }
        return 0;
    }

    private function recordSent(string $key, int $ts): void
    {
        try {
            $this->store->withLock(self::LOCK_FILE, function (array $rows) use ($key, $ts) {
                $found = false;
                foreach ($rows as &$r) {
                    if (($r['key'] ?? '') === $key) { $r['ts'] = $ts; $found = true; break; }
                }
                unset($r);
                if (!$found) $rows[] = ['key' => $key, 'ts' => $ts];
                // Old locks are noise; a week covers every cooldown in use.
                $cut = time() - 7 * 86400;
                $kept = array_values(array_filter($rows, function ($r) use ($cut) {
                    return (int)($r['ts'] ?? 0) > $cut;
                }));

                // withLock expects ['records' => ..., 'result' => ...]. This
                // returned the bare array, so SqliteStore read a missing
                // 'records' key, iterated null and never wrote anything: every
                // handoff printed three warnings into the AI trace and the
                // cooldown record was silently lost, which is what the
                // cooldown exists to prevent.
                return ['records' => $kept, 'result' => true];
            });
        } catch (\Throwable $e) { /* an unrecorded lock only risks one extra alert */ }
    }

    /**
     * Which conversations have a customer waiting with no reply.
     *
     * Pure: rows in, decisions out, so the watchdog is testable without a
     * database. A row alerts when the last word was the customer's, it is
     * older than the patience window, and no alert has gone out for that
     * particular message yet.
     *
     * @param array $rows     wa_conversations rows
     * @param array $alerted  conversation_id => last_customer_at already alerted for
     * @param int   $now      unix time
     * @param int   $patienceMin  how long a customer may wait before a human hears
     */
    public static function findUnanswered(array $rows, array $alerted, int $now, int $patienceMin = 10): array
    {
        $out = [];
        foreach ($rows as $r) {
            // The stamps are UTC and are read as UTC. strtotime() applied the
            // process zone, and this runs from cron/master.php under Africa/
            // Kampala: a customer who had waited two minutes read as three
            // hours, and staff were paged for a question the AI was answering.
            $cust  = UtcClock::parse($r['last_customer_at'] ?? '');
            $agent = UtcClock::parse($r['last_agent_at'] ?? '');
            if ($cust === 0) continue;                       // never spoke
            if ($agent >= $cust) continue;                   // answered
            if ($cust > $now - $patienceMin * 60) continue;  // still inside patience
            if ($cust <= $now - 24 * 3600) continue;         // stale history, not a live wait
            $id = (int)($r['id'] ?? 0);
            if (($alerted[$id] ?? '') === (string)$r['last_customer_at']) continue; // already told
            $out[] = $r;
        }
        return $out;
    }
}
