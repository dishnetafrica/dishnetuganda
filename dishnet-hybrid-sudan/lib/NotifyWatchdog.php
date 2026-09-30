<?php
/**
 * NotifyWatchdog — 5.18.54 (docs/46 row 32), Uganda only (NotifyGate WATCHDOG): an administrator hears when a notification
 * job stops, or failed messages pile up. Until now nobody did: a stopped job, or a failure queue that grew all day, was
 * found by someone who happened to look.
 *
 * Four conditions, read from master.php's own schedule record, the failure queue and the two copies of the settings:
 *  - overdue: a notification job (JOBS) whose last run is older than its limit. Master still runs, but not that job: it
 *    is being starved by the time budget, or failing before its record is written;
 *  - unfinished: a job whose record still reads duration_ms = -1. master.php writes -1 before a job and its time after;
 *    the watchdog runs inside master, under its lock, so a -1 on any other job is one whose process died in it (an exit,
 *    the time limit, memory), taking every job after it in that cycle with it;
 *  - piling up: PILE or more messages waiting for a person (failed or exhausted) queued in the last 24 hours;
 *  - no transport for the scheduled jobs (docs/45 §2.3): most of them read only the database copy of the settings, and
 *    when that copy has no WhatsApp connection while the settings files have one, they send nothing, and say nothing.
 *
 * Each condition is alerted at most once in COOLDOWN_SEC: one WhatsApp to the administrator (sendAdmin) and one line in
 * uCRM's log for the plugin (PluginLog). The log line is there because the WhatsApp is the thing most likely to be
 * failing when the queue piles up.
 *
 * It cannot report master itself stopping: it runs inside master. uCRM's tick (main.php) and an administrator's visit to
 * the dashboard both start master.
 */
final class NotifyWatchdog
{
    /** The notification jobs watched, and how long each may go without a run before it is overdue (seconds). */
    public const JOBS = [
        'event_processor'    => 1800,        // every cycle (30 s interval)
        'ai_reply'           => 1800,        // every cycle (60 s)
        'quote_wa'           => 3600,        // 300 s
        'followup_send'      => 3600,        // 300 s
        'notify_retry'       => 3600,        // every cycle (240 s)
        'inv_notify'         => 3 * 3600,    // 900 s
        'wa_watchdog'        => 3 * 3600,    // 900 s
        'staff_jobs'         => 26 * 3600,   // daily, 07:00
        'customer_reminders' => 26 * 3600,   // daily, between 09:00 and 17:00
        'maintenance'        => 26 * 3600,   // daily, 02:00
        'overdue_email'      => 8 * 86400,   // weekly, Monday 09:00
    ];
    /** This job's own name in master.php: its record reads -1 while it runs. */
    public const SELF = 'notify_watchdog';
    public const PILE            = 10;
    public const PILE_WINDOW_SEC = 86400;
    public const COOLDOWN_SEC    = 6 * 3600;

    /**
     * The conditions that hold now, each with the key its cooldown is kept under and the alert's text.
     *
     * @param array $schedule   master.php's record: job => {last_run, last_run_at, duration_ms}
     * @param array $dbConfig   the settings as most scheduled jobs read them: the database copy alone
     * @param array $fileConfig the settings as the webhook reads them (PluginConfig::load)
     * @return list<array{key:string, text:string}>
     */
    public static function check(array $schedule, \PDO $pdo, int $now, array $dbConfig = [], array $fileConfig = []): array
    {
        $out = [];

        $late = [];
        foreach (self::JOBS as $job => $limit) {
            $last = (int)($schedule[$job]['last_run'] ?? 0);
            if ($last <= 1) continue;                       // never run: master seeds 1, or the job is not on this install
            if ($now - $last > $limit) $late[] = $job . ' (last ran ' . self::ago($now - $last) . ' ago)';
        }
        if ($late) {
            $out[] = ['key' => 'overdue', 'text' => 'notification jobs have stopped running: ' . implode(', ', $late)
                . '. Customers may not be getting their messages.'];
        }

        $dead = [];
        foreach ($schedule as $job => $rec) {
            if ($job === self::SELF || !is_array($rec)) continue;
            if ((int)($rec['duration_ms'] ?? 0) === -1) {
                $dead[] = $job . ' (started ' . self::ago($now - (int)($rec['last_run'] ?? $now)) . ' ago)';
            }
        }
        if ($dead) {
            $out[] = ['key' => 'unfinished', 'text' => 'a scheduled job started and never finished: ' . implode(', ', $dead)
                . '. The jobs after it in that run did not run.'];
        }

        try {
            $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'notification_queue'")->fetchColumn();
            if ($has) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM notification_queue WHERE status IN ('failed', 'exhausted') AND created_at >= ?");
                $st->execute([gmdate('Y-m-d H:i:s', $now - self::PILE_WINDOW_SEC)]);
                $n = (int)$st->fetchColumn();
                if ($n >= self::PILE) {
                    $out[] = ['key' => 'pile', 'text' => "{$n} WhatsApp messages failed in the last 24 hours and are waiting in the "
                        . 'Failed Queue for a person.'];
                }
            }
        } catch (\Throwable $e) {
            // the queue cannot be read: the other conditions still stand
        }

        if (self::hasTransport($fileConfig) && !self::hasTransport($dbConfig)) {
            $out[] = ['key' => 'transport', 'text' => 'scheduled jobs cannot send WhatsApp: the copy of the settings they read '
                . '(in the database) has no WhatsApp connection, although the settings files have one. They send nothing and '
                . 'say nothing (docs/45 §2.3).'];
        }
        return $out;
    }

    /** Whether settings name a WhatsApp connection the notifier can use: Evolution with an instance, or WASender. */
    public static function hasTransport(array $cfg): bool
    {
        require_once __DIR__ . '/EvolutionApiService.php';
        try {
            if ((new \EvolutionApiService($cfg))->isConfigured()) return true;
        } catch (\Throwable $e) {
            // not configured
        }
        return trim((string)($cfg['wa_plugin_url'] ?? '')) !== '' && trim((string)($cfg['wa_app_key'] ?? '')) !== ''
            && trim((string)($cfg['wa_auth_key'] ?? '')) !== '';
    }

    /**
     * One pass: every condition that holds and is not in its cooldown is alerted, and marked.
     *
     * @return array{held:list<string>, alerted:list<string>, cooling:list<string>}
     */
    public static function run(\NotificationService $ns, \PDO $pdo, array $schedule, int $now, ?callable $log = null,
                               array $dbConfig = [], array $fileConfig = []): array
    {
        $say = $log ?? static function (string $m): void {};
        $out = ['held' => [], 'alerted' => [], 'cooling' => []];
        foreach (self::check($schedule, $pdo, $now, $dbConfig, $fileConfig) as $c) {
            $out['held'][] = $c['key'];
            if (!$ns->dedupMark('WATCHDOG:' . $c['key'] . ':' . intdiv($now, self::COOLDOWN_SEC))) {
                $out['cooling'][] = $c['key'];
                continue;
            }
            $text = '⚠️ DishNet plugin watchdog: ' . $c['text'];
            require_once __DIR__ . '/PluginLog.php';
            \PluginLog::write('watchdog', $text);
            $ns->sendAdmin($text, 'ops_watchdog_' . $c['key']);
            $out['alerted'][] = $c['key'];
            $say("{$c['key']}: alerted — {$c['text']}");
        }
        return $out;
    }

    private static function ago(int $sec): string
    {
        if ($sec < 3600)  return max(0, intdiv($sec, 60)) . ' min';
        if ($sec < 86400) return intdiv($sec, 3600) . ' h';
        return intdiv($sec, 86400) . ' days';
    }
}
