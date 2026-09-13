<?php
declare(strict_types=1);

require_once __DIR__ . '/StarlinkSessionStore.php';
require_once __DIR__ . '/StarlinkPortalConnector.php';
require_once __DIR__ . '/EquipmentAssignment.php';
require_once __DIR__ . '/StarlinkServiceState.php';
require_once __DIR__ . '/SecureFile.php';

/**
 * StarlinkUsage — collect our own usage, from our own session.
 *
 * ── WHY WE COLLECT IT OURSELVES ─────────────────────────────────────────
 *
 * dishnet-data-report has always been able to do this, and for South Sudan it
 * does: Phase 0 discovers accounts, Phase 2 fetches usage for 320 service
 * lines. For Uganda it produced an empty sl_usage.json on every run, because
 * it fetches only for KITs typed into its Manual KIT → Service Line Map and
 * nobody ever typed Uganda's in. It also keeps its data in the directory uCRM
 * deletes, and lost two files the day this was written.
 *
 * We already hold the pairing that map exists to supply — equipment_assignments
 * captures the kit serial and the service line together at installation — so
 * the fetch is ours to make.
 *
 * ── THE ENDPOINT, AND HOW IT WAS ESTABLISHED ────────────────────────────
 *
 *   GET /api/telemetryagg/v1/data-usage/account/{acc}/service-line/{sl}/annotated
 *
 * Not guessed. It is the path dishnet-data-report's cron.php:1484 calls, and
 * it answered 200 with 2,155 bytes for a live Uganda line on 2026-09-13. Six
 * invented alternatives 404'd, as did /annotated dropped, v2, the mini family
 * and the no-account form. The shape is exact.
 *
 * ── ONE COOKIE PER ACCOUNT ──────────────────────────────────────────────
 *
 * One cookie REACHES several accounts — /api/accounts/v3/accounts/contact
 * listed four for Uganda — but reaching is not the same as reading. Swapping
 * the starlink.com.account_number segment does NOT carry this endpoint across
 * accounts: with a session on one account, the control answered 200 and the
 * identical call for a known-good pair on another account answered 404,
 * seconds apart. So each account's usage needs that account's own session, and
 * the store holds one per account.
 *
 * ── WHAT IT WRITES ──────────────────────────────────────────────────────
 *
 * Rows in exactly the shape KitUsage already reads, into OUR data directory.
 * That was deliberate: the Fleet screen, the portal and every "three kinds of
 * not knowing" distinction keep working untouched, and a row we collected and
 * a row the data plugin collected are interchangeable.
 */
final class StarlinkUsage
{
    /** Our own copy, in our own data directory, which survives an upgrade. */
    public const FILE = 'sl_usage.json';

    /** Proven 2026-09-13 against a live Uganda service line. */
    public const PATH = '/api/telemetryagg/v1/data-usage/account/%s/service-line/%s/annotated';

    private StarlinkSessionStore $store;
    private array $config;
    /** @var callable|null test seam, handed straight to the connector */
    private $http;

    public function __construct(StarlinkSessionStore $store, array $config, ?callable $http = null)
    {
        $this->store  = $store;
        $this->config = $config;
        $this->http   = $http;
    }

    /**
     * Turn one annotated payload into rows KitUsage can read.
     *
     * A cycle with no reading is NOT written as zero. Starlink returns every
     * cycle since the line existed, including ones before it was activated —
     * the first of the seven on our own line runs Mar–Apr with totalAmountGB 0
     * against a service that started in September. Writing those as zero would
     * put "0 GB used" on a customer who did not have the service yet, and that
     * figure is what a person reads to decide whether a dish is faulty.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rowsFrom(array $payload, string $kitSerial, string $serviceLine): array
    {
        $content = $payload['content'] ?? [];
        if (!is_array($content)) return [];

        $plan      = is_array($content['servicePlan'] ?? null) ? $content['servicePlan'] : [];
        $activeRaw = (string)($plan['subscriptionActiveFrom'] ?? $plan['activeFrom'] ?? '');
        $activeTs  = $activeRaw !== '' ? strtotime($activeRaw) : false;

        $rows = [];
        foreach ((array)($content['billingCyclesAnnotated'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $start = (string)($c['startDate'] ?? '');
            $end   = (string)($c['endDate'] ?? '');
            if ($start === '') continue;

            $endTs = $end !== '' ? strtotime($end) : false;
            // A cycle that closed before the subscription began is not this
            // customer's cycle at all.
            if ($activeTs !== false && $endTs !== false && $endTs <= $activeTs) continue;

            $rows[] = [
                'kit_number'   => EquipmentAssignment::clean($kitSerial),
                'service_line' => StarlinkServiceState::normalise($serviceLine),
                'cycle_key'    => substr($start, 0, 10),
                'cycle_label'  => self::label($start, $end),
                'total_gb'     => round((float)($c['totalAmountGB'] ?? 0), 3),
                'cycle_start'  => $start,
                'cycle_end'    => $end,
                // The allowance as Starlink states it. Never rendered as
                // "unlimited": 100000 GB is a number, and a number we were
                // told is worth more than a word we chose.
                'limit_gb'     => isset($plan['usageLimitGB']) ? (float)$plan['usageLimitGB'] : null,
                'product_id'   => (string)($plan['productId'] ?? ''),
                'currency'     => (string)($plan['isoCurrencyCode'] ?? ''),
                'collected_at' => gmdate('Y-m-d H:i:s'),
                'source'       => 'dishnet-hybrid-sudan',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['cycle_key'], $b['cycle_key']));
        return $rows;
    }

    /** "11 Mar – 11 Apr 2026", for a person rather than a sort. */
    private static function label(string $start, string $end): string
    {
        $s = strtotime($start);
        $e = $end !== '' ? strtotime($end) : false;
        if ($s === false) return substr($start, 0, 10);
        return $e === false
            ? gmdate('j M Y', $s)
            : gmdate('j M', $s) . ' – ' . gmdate('j M Y', $e);
    }

    /**
     * Collect every live assignment that has what the call needs.
     *
     * Walks the accounts we hold a session for, selecting each in turn, and
     * restores the operator's selection afterwards.
     *
     * @param array<int,array<string,mixed>> $assignments from EquipmentAssignment::liveAssignments()
     * @return array{rows:array, report:array<int,array<string,string>>, accounts:array<string,string>}
     */
    public function collect(array $assignments): array
    {
        $restore = $this->store->active();
        $held    = $this->store->accounts();
        $rows    = [];
        $report  = [];
        $accountOutcome = [];

        // Group the work by account, so each session is selected once.
        $byAccount = [];
        foreach ($assignments as $a) {
            $kit  = EquipmentAssignment::clean((string)($a['kit_serial'] ?? ''));
            $line = StarlinkServiceState::normalise((string)($a['starlink_service_line'] ?? ''));
            $acct = StarlinkServiceState::normalise((string)($a['starlink_account'] ?? ''));
            if ($kit === '' || $line === '') {
                $report[] = ['kit' => $kit !== '' ? $kit : '(no serial)', 'line' => $line,
                             'status' => 'skipped',
                             'why' => $line === '' ? 'no service line recorded on the assignment'
                                                   : 'no kit serial on the assignment'];
                continue;
            }
            if ($acct === '') {
                $report[] = ['kit' => $kit, 'line' => $line, 'status' => 'skipped',
                             'why' => 'no Starlink account recorded — the endpoint needs one'];
                continue;
            }
            $byAccount[$acct][] = ['kit' => $kit, 'line' => $line];
        }

        foreach ($byAccount as $acct => $jobs) {
            if (!in_array($acct, $held, true) || !$this->store->useAccount($acct)) {
                $accountOutcome[$acct] = 'no session held';
                foreach ($jobs as $j) {
                    $report[] = ['kit' => $j['kit'], 'line' => $j['line'], 'status' => 'no_session',
                                 'why' => 'no cookie imported for ' . $acct
                                        . ' — switch to it on starlink.com and import'];
                }
                continue;
            }

            $conn = new StarlinkPortalConnector($this->store, $this->config, $this->http);
            $ok = 0;
            foreach ($jobs as $j) {
                $path = sprintf(self::PATH, rawurlencode($acct), rawurlencode($j['line']));

                // get(), not raw(). This is a data fetch, not a diagnostic, and
                // request() does three things raw() deliberately does not:
                // refresh once on a 401 and retry, MERGE the rotated cookie the
                // response carries and store it, and record the outcome against
                // the session.
                //
                // Collecting through raw() threw all three away. Every hourly
                // run discarded the token rotation that keeps a session alive
                // and then reported token_expired — the collector was causing
                // the condition it was complaining about.
                $r = $conn->get($path);

                if (empty($r['ok'])) {
                    $why = (string)$r['error'];
                    $report[] = ['kit' => $j['kit'], 'line' => $j['line'], 'status' => 'failed',
                                 'why' => $why !== '' ? $why : 'HTTP ' . (int)$r['code']];
                    continue;
                }

                $data  = (array)($r['data'] ?? []);
                $these = self::rowsFrom($data, $j['kit'], $j['line']);
                if ($these === []) {
                    $report[] = ['kit' => $j['kit'], 'line' => $j['line'], 'status' => 'no_cycles',
                                 'why' => 'Starlink returned no billing cycle since this '
                                        . 'subscription began — real, and not the same as 0 GB'];
                    continue;
                }
                foreach ($these as $row) $rows[] = $row;
                $ok++;
                $last = end($these);
                $report[] = ['kit' => $j['kit'], 'line' => $j['line'], 'status' => 'collected',
                             'why' => count($these) . ' cycle(s), newest '
                                    . $last['cycle_label'] . ' = ' . $last['total_gb'] . ' GB'];
            }
            $accountOutcome[$acct] = $ok . ' of ' . count($jobs) . ' collected';
        }

        if ($restore !== '') $this->store->useAccount($restore);
        return ['rows' => $rows, 'report' => $report, 'accounts' => $accountOutcome];
    }

    /**
     * Write the rows where KitUsage will find them.
     *
     * Only ever called with rows actually collected. Writing an empty file
     * would turn "we could not read anything" into "the fleet used nothing",
     * which is the exact confusion this plugin keeps having to undo.
     */
    public function save(string $dataDir, array $rows): array
    {
        if ($rows === []) {
            return ['ok' => false, 'written' => 0,
                    'why' => 'nothing collected — refusing to write an empty usage file '
                           . 'over whatever is there, because empty reads as zero'];
        }
        $path = rtrim($dataDir, '/') . '/' . self::FILE;
        $r = SecureFile::write($path,
            (string)json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return !empty($r['ok'])
            ? ['ok' => true, 'written' => count($rows), 'why' => '', 'path' => $path]
            : ['ok' => false, 'written' => 0, 'why' => 'could not write ' . $path];
    }
}
