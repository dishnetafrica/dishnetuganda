<?php
/**
 * N10 backfill/data-state census. Read-only with respect to design: it counts
 * states and classifies them. Runs against whatever DNB_DSN points at, so the
 * SAME script can later be run by an operator against production — which is the
 * point, because a disposable database cannot speak for production.
 *
 *   DNB_DSN=... php tools/audit/n10_backfill_census.php
 */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';
$i = \Dn\Db\Database::inspector();   // BYPASSRLS: a census blinded by RLS is not a census
printf("  database=%s  user=%s  bypassrls=%s\n\n",
    $i->one('SELECT current_database() d')['d'], $i->one('SELECT current_user u')['u'],
    $i->one("SELECT rolbypassrls::text b FROM pg_roles WHERE rolname=current_user")['b']);

echo "  ---- 1. mt_devices state census ----\n";
$c = $i->one("SELECT
   count(*)                                                        AS total,
   count(*) FILTER (WHERE customer_id IS NULL AND site_id IS NULL)  AS unassigned,
   count(*) FILTER (WHERE customer_id IS NOT NULL AND site_id IS NULL) AS claimed_unsited,
   count(*) FILTER (WHERE customer_id IS NULL AND site_id IS NOT NULL) AS partial_null,
   count(*) FILTER (WHERE customer_id IS NOT NULL AND site_id IS NOT NULL) AS fully_assigned,
   count(*) FILTER (WHERE tunnel_ip IS NULL)                        AS no_tunnel_ip
   FROM mt_devices");
foreach ($c as $k => $v) printf("    %-22s %s\n", $k, $v);

echo "\n  ---- 2. N10 violations ----\n";
$v = $i->one("SELECT count(*) n FROM mt_devices d JOIN mt_sites s ON s.id = d.site_id
               WHERE d.customer_id IS DISTINCT FROM s.customer_id")['n'];
printf("    devices whose site belongs to another customer   %s\n", $v);
$orph = $i->one("SELECT count(*) n FROM mt_devices d
                  WHERE d.site_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM mt_sites s WHERE s.id = d.site_id)")['n'];
printf("    devices whose site_id points at no site          %s   (FK should make this 0)\n", $orph);

echo "\n  ---- 3. partial-null detail (the CHECK's target) ----\n";
printf("    site_id set + customer_id NULL                   %s\n", $c['partial_null']);
printf("    of those, would the site's owner be knowable?    %s\n",
    $i->one("SELECT count(*) n FROM mt_devices d JOIN mt_sites s ON s.id=d.site_id
              WHERE d.customer_id IS NULL")['n']);

echo "\n  ---- 4. ambiguous / projection-relevant states ----\n";
$rows = [
 'fully assigned but NO tunnel_ip (cannot be projected)' =>
   "SELECT count(*) n FROM mt_devices WHERE site_id IS NOT NULL AND tunnel_ip IS NULL",
 'decommissioned yet still holding a site' =>
   "SELECT count(*) n FROM mt_devices WHERE state='decommissioned' AND site_id IS NOT NULL",
 'sited but not yet active (state <> active)' =>
   "SELECT count(*) n FROM mt_devices WHERE site_id IS NOT NULL AND state <> 'active'",
 'two devices sharing a tunnel_ip' =>
   "SELECT count(*) n FROM (SELECT tunnel_ip FROM mt_devices WHERE tunnel_ip IS NOT NULL
      GROUP BY tunnel_ip HAVING count(*)>1) x",
 'sites with more than one device (Q2-adjacent, informational)' =>
   "SELECT count(*) n FROM (SELECT site_id FROM mt_devices WHERE site_id IS NOT NULL
      GROUP BY site_id HAVING count(*)>1) x",
];
foreach ($rows as $label => $sql) printf("    %-54s %s\n", $label, $i->one($sql)['n']);

echo "\n  ---- 5. the SAME defect class on other tables ----\n";
echo "    tables carrying BOTH customer_id and site_id, and whether anything ties them:\n";
$tabs = $i->query(
  "SELECT c.relname t FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE n.nspname='public' AND c.relkind='r' AND c.relname LIKE 'mt\\_%'
      AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid=c.oid AND a.attname='customer_id' AND NOT a.attisdropped)
      AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid=c.oid AND a.attname='site_id'     AND NOT a.attisdropped)
    ORDER BY 1");
foreach ($tabs as $t) {
    $tn = $t['t'];
    $tied = $i->one("SELECT count(*) n FROM pg_constraint
        WHERE conrelid = ?::regclass AND contype='f' AND array_length(conkey,1) > 1", [$tn])['n'];
    $bad = $i->one("SELECT count(*) n FROM {$tn} x JOIN mt_sites s ON s.id = x.site_id
                     WHERE x.customer_id IS DISTINCT FROM s.customer_id")['n'];
    printf("    %-20s composite FK: %-3s   rows violating the same rule: %s\n",
        $tn, $tied ? 'yes' : 'NO', $bad);
}
echo "\n";
