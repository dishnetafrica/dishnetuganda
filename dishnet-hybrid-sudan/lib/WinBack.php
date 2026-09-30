<?php
/**
 * WinBack — "We miss you", 7 to 10 days after a service ended (5.18.54, docs/46 rows 6 and 20; Uganda only).
 *
 * The maintenance job's win-back task, moved to the daytime reminder run, with one change of substance: it is a
 * promotional message, so it goes as CLASS_PROACTIVE and a customer who wrote STOP no longer receives it (C9). Before,
 * it went as a transactional message, which a proactive opt-out does not block. The log file and its keys are the
 * old task's own (winback_log.json, WB<service id>), so nobody is written to twice across the upgrade.
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
        $wlog = $this->store->load('winback_log.json') ?: [];
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
            if (isset($wlog[$key])) continue;

            $hasActive = false;
            foreach ((array)($this->crm->get("clients/{$clientId}/services") ?? []) as $os) {
                if ((int)($os['id'] ?? 0) !== $svcId && (int)($os['status'] ?? 0) === 1) { $hasActive = true; break; }
            }
            if ($hasActive) { $wlog[$key] = 'skip_active'; $out['skipped']++; continue; }

            $client = $this->crm->get("clients/{$clientId}");
            if (!is_array($client)) continue;
            $phone = '';
            foreach ((array)($client['contacts'] ?? []) as $c) { if (!empty($c['phone'])) { $phone = (string)$c['phone']; break; } }
            if ($phone === '') continue;
            $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')) ?: ((string)($client['companyName'] ?? '') ?: 'Customer');

            $this->notify->winBackFollowup($phone, $name, $svcName, $activeTo, \ContactOptOut::CLASS_PROACTIVE);
            ($this->log)("  Win-back sent: client #{$clientId} — service ended {$activeTo}");
            $wlog[$key] = date('Y-m-d H:i:s');
            $out['sent']++;
            usleep(500000);
        }
        $cut = $today->modify('-120 days')->format('Y-m-d');
        foreach ($wlog as $k => $v) {
            if (($k[0] ?? '') === 'W' && is_string($v) && strlen($v) >= 10 && substr($v, 0, 10) < $cut) unset($wlog[$k]);
        }
        $this->store->save('winback_log.json', $wlog);
        return $out;
    }
}
