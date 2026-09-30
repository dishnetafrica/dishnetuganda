<?php
/**
 * InvoiceReminders — Uganda's payment reminders, from one path (5.18.54, docs/46 rows 5-8; NotifyGate::REMINDERS).
 *
 * Until 5.18.53 two paths sent them with separate guards: uCRM's invoice.near_due / invoice.overdue events through
 * the webhook (guarded once per invoice PER DAY, so an event raised daily meant a "Final Notice" daily), and the
 * 02:00 maintenance job (once per invoice and tier). On Uganda the webhook now records those events and sends
 * nothing, and this class is the maintenance job's tasks 4a and 4 moved to a daytime run:
 *
 *   before the due date  d7, d3, d1   (exactly 7, 3 and 1 days before)
 *   after it             d1, d3, d5, d7 (exactly 1, 3, 5 and 7 days after)
 *
 * The texts and the guard keys are the 02:00 job's own ("<number>-pre-d7", "<number>-d3"), so an invoice reminded
 * before the upgrade is not reminded again after it. What changed:
 *   - the tier is claimed just before the send, after the checks: a check that fails no longer uses it up (N-3);
 *   - the phone is the first contact that has one, as every other sender reads it (the old job read contact 0 only);
 *   - on a prepaid install (billing_model = prepaid) nothing is sent after the due date. Those four texts speak of
 *     suspension, which is false for a prepaid customer — the reason the e-mail ladder was stopped on 15 September.
 *     The service-paused message tells a prepaid customer what happened. Each suppression is logged (C1);
 *   - a list that comes back at uCRM's page limit is reported: reminders past it would be missed.
 */
final class InvoiceReminders
{
    public const PRE_TIERS     = [7 => 'd7', 3 => 'd3', 1 => 'd1'];         // days before the due date
    public const OVERDUE_TIERS = [1 => 'd1', 3 => 'd3', 5 => 'd5', 7 => 'd7']; // days after it
    public const PAGE_LIMIT    = 500;

    /**
     * Quiet hours for scheduled customer notices, local time: from notify_quiet_from_hour (default 21) to
     * notify_quiet_until_hour (default 8). Equal values switch them off. A scan that looks back 24 hours loses
     * nothing by waiting for the morning.
     */
    public static function quiet(array $config, int $hour): bool
    {
        $from  = (int)($config['notify_quiet_from_hour'] ?? 21);
        $until = (int)($config['notify_quiet_until_hour'] ?? 8);
        if ($from === $until || $from < 0 || $from > 23 || $until < 0 || $until > 23) return false;
        return $from > $until ? ($hour >= $from || $hour < $until) : ($hour >= $from && $hour < $until);
    }

    public static function preKey(string $num, string $tier): string     { return "{$num}-pre-{$tier}"; }
    public static function overdueKey(string $num, string $tier): string { return "{$num}-{$tier}"; }

    /** billing_model, read the way the e-mail ladder reads it (explicit key first, then the config files). */
    public static function prepaid(array $config): bool
    {
        if (!function_exists('_dunningBillingModel')) require_once __DIR__ . '/OverdueDunningHelpers.php';
        return _dunningBillingModel($config) === 'prepaid';
    }

    /** @var object CrmApiClient */ private $crm;
    /** @var object NotificationService */ private $notify;
    private array $config;
    /** @var callable(string):void */ private $log;

    public function __construct($crm, $notify, array $config, callable $log)
    {
        $this->crm = $crm; $this->notify = $notify; $this->config = $config; $this->log = $log;
    }

    private function log(string $m): void { ($this->log)($m); }

    /** The unpaid invoices: uCRM's documented filter first, the 02:00 job's own query if that answers nothing. */
    public function unpaidInvoices(): array
    {
        $rows = $this->crm->get('invoices?statuses[]=1&statuses[]=2&limit=' . self::PAGE_LIMIT);
        if (!is_array($rows) || $rows === []) $rows = $this->crm->get('invoices?status[]=1&status[]=2&limit=' . self::PAGE_LIMIT);
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        if (count($rows) >= self::PAGE_LIMIT) {
            $this->log("  WARNING: uCRM returned " . count($rows) . " invoices, its page limit — reminders for invoices past it are not sent");
            if (!class_exists('PluginLog') && is_file(__DIR__ . '/PluginLog.php')) require_once __DIR__ . '/PluginLog.php';
            if (class_exists('PluginLog')) \PluginLog::write('reminders', 'the unpaid-invoice list reached uCRM\'s page limit (' . self::PAGE_LIMIT . '); reminders past it are not sent');
        }
        return $rows;
    }

    /**
     * One pass. $today is the local date the tiers are counted from.
     * @return array{pre:array<string,int>,overdue:array<string,int>,skipped:int,errors:int,suppressed_prepaid:int,fetched:int}
     */
    public function run(\DateTimeImmutable $today): array
    {
        $out = ['pre' => ['d7' => 0, 'd3' => 0, 'd1' => 0], 'overdue' => ['d1' => 0, 'd3' => 0, 'd5' => 0, 'd7' => 0],
                'skipped' => 0, 'errors' => 0, 'suppressed_prepaid' => 0, 'fetched' => 0];
        $prepaid  = self::prepaid($this->config);
        $invoices = $this->unpaidInvoices();
        $out['fetched'] = count($invoices);
        $this->log("  Fetched {$out['fetched']} unpaid invoices from uCRM" . ($prepaid ? ' (billing model: prepaid)' : ''));
        $midnight = $today->setTime(0, 0, 0);

        foreach ($invoices as $inv) {
            $num      = (string)($inv['number'] ?? ($inv['id'] ?? '?'));
            $invId    = (int)($inv['id'] ?? 0);
            $clientId = (int)($inv['clientId'] ?? 0);
            $dueRaw   = (string)($inv['dueDate'] ?? $inv['maturityDate'] ?? '');
            $status   = (int)($inv['status'] ?? -1);
            if ($invId <= 0 || $clientId <= 0 || $dueRaw === '' || in_array($status, [0, 3, 4], true)) { $out['skipped']++; continue; }

            $due = self::parseDate($dueRaw, $today->getTimezone());
            if ($due === null) { $out['skipped']++; continue; }
            $days = (int)$midnight->diff($due)->format('%r%a');        // + before the due date, - after it

            $kind = null; $tier = null;
            if ($days > 0 && isset(self::PRE_TIERS[$days]))       { $kind = 'pre';     $tier = self::PRE_TIERS[$days]; }
            if ($days < 0 && isset(self::OVERDUE_TIERS[-$days]))  { $kind = 'overdue'; $tier = self::OVERDUE_TIERS[-$days]; }
            if ($kind === null) { $out['skipped']++; continue; }

            $key = $kind === 'pre' ? self::preKey($num, $tier) : self::overdueKey($num, $tier);
            if ($this->notify->dedupCheck($key)) { $out['skipped']++; continue; }        // sent before (any run, any version)

            if ($kind === 'overdue' && $prepaid) {
                $this->log("  SUPPRESSED #{$num} overdue-{$tier}: prepaid — no suspension wording to a prepaid customer");
                $out['suppressed_prepaid']++;
                continue;
            }

            // The customer may have paid since the list was read.
            $fresh = $this->crm->get("invoices/{$invId}");
            if (is_array($fresh)) {
                $fs = (int)($fresh['status'] ?? -1);
                $fa = (float)($fresh['amountToPay'] ?? $fresh['total'] ?? 0);
                if (in_array($fs, [3, 4], true) || $fa <= 0) { $this->log("  SKIP #{$num} {$kind}-{$tier} — paid (fresh check)"); $out['skipped']++; continue; }
                $total = $fa;
            } else {
                $total = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
            }
            if ($total <= 0) { $out['skipped']++; continue; }

            $client = $this->crm->get("clients/{$clientId}");
            if (!is_array($client)) { $out['errors']++; continue; }
            $phone = '';
            foreach ((array)($client['contacts'] ?? []) as $c) {
                if (!empty($c['phone'])) { $phone = (string)$c['phone']; break; }
                if (!empty($c['phones'][0]['number'])) { $phone = (string)$c['phones'][0]['number']; break; }
            }
            if ($phone === '') { $this->log("  SKIP #{$num} {$kind}-{$tier} — client #{$clientId} has no phone"); $out['skipped']++; continue; }
            $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')) ?: ((string)($client['companyName'] ?? '') ?: 'Customer');

            $svcName = $kind === 'overdue' ? 'DishNet Service' : '';
            $svcs = $this->crm->get("clients/services?clientId={$clientId}&status[]=1");
            if (is_array($svcs) && !empty($svcs[0]['name'])) $svcName = (string)$svcs[0]['name'];
            $currency = (string)($inv['currencyCode'] ?? '') ?: (function_exists('dn_code') ? dn_code($this->config) : '');

            // Claimed here, after every check, just before the send (N-3).
            if (!$this->notify->dedupMark($key)) { $out['skipped']++; continue; }
            $dueTxt = $due->format('M j, Y');
            if ($kind === 'pre') {
                if ($tier === 'd7')     $this->notify->invoiceDue7Days($phone, $name, $num, $total, $currency, $dueTxt, $svcName);
                elseif ($tier === 'd3') $this->notify->invoiceDue3Days($phone, $name, $num, $total, $currency, $dueTxt, $svcName);
                else                    $this->notify->invoiceDueTomorrow($phone, $name, $num, $total, $currency, $dueTxt, $svcName);
            } else {
                if ($tier === 'd1')     $this->notify->overdueDay1($phone, $name, $num, $total, $currency, $svcName);
                elseif ($tier === 'd3') $this->notify->overdueDay3($phone, $name, $num, $total, $currency, $svcName);
                elseif ($tier === 'd5') $this->notify->overdueDay5($phone, $name, $num, $total, $currency, $svcName);
                else                    $this->notify->lowBalanceWarning($phone, $name, $total, $dueTxt, $num, $svcName);
            }
            $out[$kind][$tier]++;
            $this->log("  SENT #{$num} {$kind}-{$tier} → client #{$clientId}");
            usleep(300000);
        }
        return $out;
    }

    /**
     * The due date as a calendar date, at local midnight. uCRM sends a date as midnight in its own zone
     * ("2026-10-07T00:00:00+0300"), so the date is the first ten characters. Reading it as an instant is wrong
     * wherever the plugin's zone differs from uCRM's: the 02:00 job parses it with its offset and counts from the
     * plugin's midnight, so under Africa/Juba (UTC+2) a date sent at +0300 is 6 days 23 hours away and "7 days" never
     * matches (docs/46, N-5); converting it first would move it to the day before instead.
     */
    public static function parseDate(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($raw), $m)) return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $tz);
        return ($d instanceof \DateTimeImmutable && $d->format('Y-m-d') === $m[1]) ? $d : null;
    }
}
