<?php
/**
 * WinBack — "We miss you", 7 to 10 days after a service ended (5.18.54, docs/46 rows 6 and 20; Uganda only).
 *
 * The maintenance job's win-back task, moved to the daytime reminder run, with two changes of substance:
 *   - it is a promotional message, so it goes as CLASS_PROACTIVE and a customer who wrote STOP no longer receives it
 *     (C9). Before, it went as a transactional message, which a proactive opt-out does not block;
 *   - it is sent once per ended service. The old task's guard was a keyed file, winback_log.json, which the store does
 *     not read back (docs/46, N-6): every run found nothing, so the message went on each of the four days of its
 *     window. The guard is now WB<service id> in notification_dedup, the atomic table the other senders use, claimed
 *     after every check, just before the send. The old file is still read — flattened, whatever its nesting — so a
 *     service it names is not written to again.
 */
final class WinBack
{
    /** @var object CrmApiClient */ private $crm;
    /** @var object NotificationService */ private $notify;
    /** @var object SqliteStore */ private $store;
    /** @var callable(string):void */ private $log;

    public function __construct($crm, $notify, $store, callable $log)
    {
        $this->crm = $crm; $this->notify = $notify; $this->store = $store; $this->log = $log;
    }

    /** @return array{sent:int,skipped:int} */
    public function run(\DateTimeImmutable $today): array
    {
        require_once __DIR__ . '/ContactOptOut.php';
        $out = ['sent' => 0, 'skipped' => 0];
        $old = self::oldKeys((array)($this->store->load('winback_log.json') ?: []));
        $ended = $this->crm->get('clients/services?statuses[]=3&limit=500') ?? [];
        if (empty($ended)) $ended = $this->crm->get('clients/services?statuses[]=5&limit=500') ?? [];
        $from = $today->modify('-10 days')->format('Y-m-d');
        $to   = $today->modify('-7 days')->format('Y-m-d');

        foreach ((array)$ended as $svc) {
            $svcId    = (int)($svc['id'] ?? 0);
            $clientId = (int)($svc['clientId'] ?? 0);
            $svcName  = (string)($svc['name'] ?? $svc['servicePlanName'] ?? 'Internet Service');
            $activeTo = substr((string)($svc['activeTo'] ?? ''), 0, 10);
            if (!$svcId || !$clientId || $activeTo === '' || $activeTo < $from || $activeTo > $to) continue;
            $key = "WB{$svcId}";
            if (isset($old[$key]) || $this->notify->dedupCheck($key)) continue;     // sent before, by this run or the old task

            $hasActive = false;
            foreach ((array)($this->crm->get("clients/{$clientId}/services") ?? []) as $os) {
                if ((int)($os['id'] ?? 0) !== $svcId && (int)($os['status'] ?? 0) === 1) { $hasActive = true; break; }
            }
            if ($hasActive) { $out['skipped']++; continue; }                          // still a customer: nothing to win back

            $client = $this->crm->get("clients/{$clientId}");
            if (!is_array($client)) continue;
            $phone = '';
            foreach ((array)($client['contacts'] ?? []) as $c) { if (!empty($c['phone'])) { $phone = (string)$c['phone']; break; } }
            if ($phone === '') continue;
            $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')) ?: ((string)($client['companyName'] ?? '') ?: 'Customer');

            if (!$this->notify->dedupMark($key)) continue;                          // claimed just before the send
            $this->notify->winBackFollowup($phone, $name, $svcName, $activeTo, \ContactOptOut::CLASS_PROACTIVE);
            ($this->log)("  Win-back sent: client #{$clientId} — service ended {$activeTo}");
            $out['sent']++;
            usleep(500000);
        }
        return $out;
    }

    /**
     * The WB<id> keys of the old task's log, whatever shape the store has given it: a keyed file comes back as a list
     * holding the object, and each save by the old task nested the previous one a level deeper.
     */
    public static function oldKeys(array $log): array
    {
        $out = [];
        foreach ($log as $k => $v) {
            if (is_array($v)) { $out += self::oldKeys($v); continue; }
            if (is_string($k) && strncmp($k, 'WB', 2) === 0) $out[$k] = (string)$v;
        }
        return $out;
    }
}
