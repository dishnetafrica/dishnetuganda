<?php
declare(strict_types=1);

/**
 * DistributorEvents — the ONE safe entry point that turns a business event into a
 * distributor DRAFT alert (WS-A P3, docs/49 §4.2 Flow A/B). Every event source
 * (our own attribution handler, and the live uCRM webhook hooks) calls
 * maybeNotify(); nothing else constructs the notifier for an event.
 *
 * It is designed to be dropped into a LIVE path without risk:
 *   - STRICT NO-OP unless Uganda AND distributors_enabled (the canonical gate,
 *     docs/49; matches includes/post/post_distributors.php verbatim). With the
 *     pilot off — every South Sudan install, and Uganda by default — it does
 *     nothing observable and touches no data.
 *   - EVERYTHING is wrapped so a throw can never escape into the caller. A uCRM
 *     webhook handler that calls this must never 500 because of it.
 *   - It resolves the owning distributor from dist_customer_links (079); a
 *     customer/lead with no distributor owner produces NOTHING. Most customers
 *     have no owner, so most events are silent.
 *   - It builds only a DRAFT (DistributorNotifier, NullWhatsAppChannel). It
 *     sends no message and writes no uCRM record. The actor is the system, from
 *     the identity boundary, never a request field.
 */
class DistributorEvents
{
    /** The canonical pilot gate — Uganda AND the off-by-default flag. */
    public static function enabled(?array $config, ?string $dataDir): bool
    {
        try {
            require_once __DIR__ . '/StaffJobsGate.php';
            $cfg = is_array($config) ? $config : [];
            return StaffJobsGate::applies($cfg, $dataDir) && !empty($cfg['distributors_enabled']);
        } catch (\Throwable $e) {
            return false; // fail closed: behave exactly as South Sudan / pilot-off
        }
    }

    /** event => [owner scope, owner id key, dedup id key] in the $ids array. */
    private const MAP = [
        'lead_attributed'    => ['lead',        'lead_id',   'lead_id'],
        'payment_received'   => ['ucrm_client', 'client_id', 'payment_id'],
        'customer_activated' => ['ucrm_client', 'client_id', 'client_id'],
    ];

    /**
     * Produce a draft distributor alert for an event, if the pilot is on and the
     * customer/lead is owned by a distributor. Safe to call from anywhere.
     *
     * @param mixed       $store   the SqliteStore (getPdo())
     * @param array|null  $config  plugin config
     * @param string|null $dataDir plugin data dir (for the Uganda gate)
     * @param string      $event   lead_attributed | payment_received | customer_activated
     * @param array       $ids     client_id?, lead_id?, payment_id? (strings/ints)
     * @param array       $ctx     customer_name?, amount_raw?, amount_display?
     * @return array{enabled:bool, owned?:bool, created?:bool, status?:string, reason?:string}
     */
    public static function maybeNotify($store, ?array $config, ?string $dataDir, string $event, array $ids, array $ctx = [], string $actor = 'system'): array
    {
        try {
            if (!self::enabled($config, $dataDir)) return ['enabled' => false];
            if (!isset(self::MAP[$event]) || !$store) return ['enabled' => true, 'owned' => false, 'reason' => 'unknown event'];
            [$ownerScope, $ownerKey, $dedupKey] = self::MAP[$event];
            $ownerId = trim((string)($ids[$ownerKey] ?? ''));
            $dedupId = trim((string)($ids[$dedupKey] ?? ''));
            if ($ownerId === '' || $dedupId === '') return ['enabled' => true, 'owned' => false, 'reason' => 'missing ids'];

            require_once __DIR__ . '/DistributorAttribution.php';
            require_once __DIR__ . '/DistributorNotifier.php';
            $att  = DistributorAttribution::fromStore($store);
            $link = $att->activeLink($ownerScope, $ownerId);
            if (!$link) return ['enabled' => true, 'owned' => false]; // no distributor owns this customer/lead

            $ctx2 = $ctx;
            $ctx2['client_id'] = (string)($ids['client_id'] ?? '');
            $notifier = DistributorNotifier::fromStore($store); // NullWhatsAppChannel — draft only, no send
            $res = $notifier->notify((int)$link['partner_id'], $event, $dedupId, $ctx2, $actor);
            return ['enabled' => true, 'owned' => true] + $res;
        } catch (\Throwable $e) {
            // Never let a distributor-pilot hook disturb the caller (a live webhook).
            return ['enabled' => true, 'error' => $e->getMessage()];
        }
    }
}
