<?php
/**
 * starlink_fleet.php — every Starlink customer, their kit, plan and usage.
 *
 * The Uganda equivalent of the South Sudan data-report Clients tab, with one
 * difference that matters: ownership is read from equipment_assignments, the
 * authoritative binding, and never from a kit file a sync happened to write.
 *
 * Read-only. Nothing on this page changes an assignment — that is what
 * tools/assign_kit.php is for, and it wants a reason.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__, 2) . '/lib/CrmKitAttribute.php';
require_once dirname(__DIR__, 2) . '/lib/KitUsage.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkFleet.php';
require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__, 2) . '/lib/crm_url.php';

$config = $store->load('kyc_config.json') ?? [];
$ea     = EquipmentAssignment::fromStore($store);
// Our own data directory: when cron/starlink_usage.php has collected from our
// Starlink session, those figures are preferred over the sibling plugin's.
require_once dirname(__DIR__, 2) . '/lib/bootstrap_data.php';
$dnDataDir = getDataDir(dirname(__DIR__, 2));

// uCRM gives the plan and the label state. Without it the fleet still lists —
// with the allowance and label columns saying so, rather than guessing.
$crm = null;
try {
    // svc() is the request's shared container — using it means this screen
    // does not open a second uCRM client alongside whatever else the page
    // already built. Outside a request (a render harness) fall back.
    $crm = function_exists('svc') ? svc('crm') : CrmApiClient::fromUcrm(dirname(__DIR__, 2), $config);
} catch (\Throwable $e) {}
$kitAttr = $crm ? new CrmKitAttribute($crm, $ea) : null;

$fleet = (new StarlinkFleet($ea, new KitUsage($ea, $dnDataDir), $store, $kitAttr))->build();
$rows  = $fleet['rows'];
$sum   = $fleet['summary'];
$tel   = $fleet['telemetry'];
$slk   = $fleet['starlink'];
$orph  = $fleet['unclaimed'];
$crmWeb = dn_crm_web($config);

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<style>
.sf-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.sf-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.sf-head h2{margin:0;font-size:19px;font-weight:800;color:#0F172A;}
.sf-sub{font-size:12px;color:#64748B;margin-top:3px;}
.sf-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.sf-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 14px;min-width:96px;}
.sf-stat b{display:block;font-size:20px;font-weight:800;color:#0F172A;line-height:1.2;}
.sf-stat span{font-size:10px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
.sf-stat.warn b{color:#B45309;} .sf-stat.bad b{color:#B91C1C;}
.sf-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.55;margin-bottom:14px;}
.sf-note.amber{background:#FFFBEB;border:1px solid #FDE68A;color:#78350F;}
.sf-note.grey{background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;}
.sf-tablewrap{overflow-x:auto;background:#fff;border:1px solid #E2E8F0;border-radius:12px;}
table.sf{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px;}
table.sf th{text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;
  letter-spacing:.4px;font-size:10px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;white-space:nowrap;}
table.sf td{padding:10px 12px;border-bottom:1px solid #F1F5F9;vertical-align:top;}
table.sf tr:last-child td{border-bottom:none;}
.sf-cust{font-weight:700;color:#0F172A;}
.sf-cust a{color:#0F172A;text-decoration:none;} .sf-cust a:hover{text-decoration:underline;}
.sf-id{font-size:10.5px;color:#94A3B8;}
.sf-kit{font-family:'Courier New',monospace;font-weight:800;color:#1E293B;font-size:13px;}
.sf-ids{font-family:'Courier New',monospace;font-size:10.5px;color:#64748B;line-height:1.6;}
.sf-unknown{color:#94A3B8;font-style:italic;}
.sf-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;
  text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.sf-ok{background:#DCFCE7;color:#166534;} .sf-warnp{background:#FEF3C7;color:#92400E;}
.sf-badp{background:#FEE2E2;color:#991B1B;} .sf-greyp{background:#F1F5F9;color:#64748B;}
.sf-bar{height:5px;border-radius:3px;background:#E2E8F0;margin-top:5px;overflow:hidden;max-width:150px;}
.sf-bar i{display:block;height:100%;background:#2563EB;}
.sf-bar i.hot{background:#DC2626;}
.sf-empty{padding:40px 20px;text-align:center;color:#64748B;font-size:13px;}
</style>

<div class="sf-wrap">
  <div class="sf-head">
    <div>
      <h2>Starlink Fleet</h2>
      <div class="sf-sub">Every kit in the field, and the customer it is bound to.
        Ownership comes from <code>equipment_assignments</code> — the authoritative binding.</div>
    </div>
  </div>

  <div class="sf-stats">
    <div class="sf-stat"><b><?= (int)$sum['kits'] ?></b><span>Kits live</span></div>
    <div class="sf-stat"><b><?= (int)$sum['customers'] ?></b><span>Customers</span></div>
    <div class="sf-stat"><b><?= (int)$sum['live_active'] ?></b><span>Active on Starlink</span></div>
    <?php if ($sum['live_pending']): ?>
      <div class="sf-stat warn"><b><?= (int)$sum['live_pending'] ?></b><span>Pending activation</span></div>
    <?php endif; ?>
    <?php if ($sum['live_suspended'] + $sum['live_paused'] + $sum['live_standby'] + $sum['live_inactive']): ?>
      <div class="sf-stat bad"><b><?= (int)($sum['live_suspended'] + $sum['live_paused'] + $sum['live_standby'] + $sum['live_inactive']) ?></b><span>Not serving</span></div>
    <?php endif; ?>
    <div class="sf-stat<?= $sum['usage_silent'] ? ' warn' : '' ?>"><b><?= (int)$sum['usage_silent'] ?></b><span>No reading</span></div>
    <div class="sf-stat<?= $sum['allowance_unknown'] ? ' warn' : '' ?>"><b><?= (int)$sum['allowance_unknown'] ?></b><span>Allowance unknown</span></div>
    <div class="sf-stat<?= $sum['over_cap'] ? ' bad' : '' ?>"><b><?= (int)$sum['over_cap'] ?></b><span>Over cap</span></div>
    <?php if ($sum['unserviced']): ?>
      <div class="sf-stat bad"><b><?= (int)$sum['unserviced'] ?></b><span>No service</span></div>
    <?php endif; ?>
    <?php if ($sum['label_differs']): ?>
      <div class="sf-stat bad"><b><?= (int)$sum['label_differs'] ?></b><span>Label differs</span></div>
    <?php endif; ?>
  </div>

  <?php if (!$slk['available']): ?>
    <div class="sf-note amber"><strong>No Starlink state.</strong> <?= $h($slk['reason']) ?></div>
  <?php elseif ($sum['live_silent'] > 0): ?>
    <div class="sf-note grey">
      <strong><?= (int)$sum['live_silent'] ?> of <?= (int)$sum['kits'] ?> kits have no Starlink state.</strong>
      The data plugin holds <?= (int)$slk['lines'] ?> service line<?= $slk['lines'] === 1 ? '' : 's' ?>,
      and these kits are not among them
      <?php if ($sum['no_service_line']): ?>— <?= (int)$sum['no_service_line'] ?> because no service line was
      recorded at installation, which <code>tools/binding_doctor.php --learn</code> can fill in<?php endif; ?>.
      A kit with no state is not a kit that is switched off.
    </div>
  <?php endif; ?>

  <?php if (!$tel['available']): ?>
    <div class="sf-note amber"><strong>No telemetry source.</strong> <?= $h($tel['reason']) ?></div>
  <?php elseif ($sum['usage_silent'] > 0): ?>
    <div class="sf-note amber">
      <strong><?= (int)$sum['usage_silent'] ?> of <?= (int)$sum['kits'] ?> kits have no reading this cycle.</strong>
      A silent kit is usually a dead Starlink session rather than a dead dish — the data plugin reads
      404 from the usage endpoint as a service line that no longer exists, and tombstones it. Re-import
      the cookie for that account, clear the entry under Sync&nbsp;Status&nbsp;→&nbsp;Dead&nbsp;SLs, and re-run the sync.
      This is separate from the Starlink column: a line can be active and still have no reading.
    </div>
  <?php endif; ?>

  <?php if ($crm === null): ?>
    <div class="sf-note grey">uCRM is not reachable from here, so the plan and label columns cannot be
      filled. The fleet and its identifiers below are read from this plugin's own database and are complete.</div>
  <?php endif; ?>

  <div class="sf-tablewrap">
    <?php if (!$rows): ?>
      <div class="sf-empty">
        No kit is assigned to a customer yet.<br>
        Receive one into stock, then bind it with
        <code>tools/assign_kit.php --unit N --client N --service N --commit</code>.
      </div>
    <?php else: ?>
    <table class="sf">
      <thead><tr>
        <th>Customer</th><th>Kit</th><th>Plan</th><th>Starlink says</th>
        <th>Used this cycle</th><th>Identifiers</th><th>Label</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $u = $r['usage']; $p = $r['plan'];
        $name = $r['client_name'] !== '' ? $r['client_name'] : ('Client #' . $r['client_id']);
      ?>
        <tr>
          <td>
            <div class="sf-cust"><?php if ($crmWeb !== ''): ?>
              <a href="<?= $h($crmWeb) ?>/crm/client/<?= (int)$r['client_id'] ?>" target="_blank" rel="noopener"><?= $h($name) ?></a>
            <?php else: ?><?= $h($name) ?><?php endif; ?></div>
            <div class="sf-id">client #<?= (int)$r['client_id'] ?>
              <?php if ($r['service_id'] > 0): ?>· service #<?= (int)$r['service_id'] ?>
              <?php else: ?>· <span style="color:#B91C1C;font-weight:700;">no service</span><?php endif; ?>
            </div>
          </td>
          <td>
            <div class="sf-kit"><?= $h($r['kit_serial']) ?></div>
            <div class="sf-id">assignment #<?= (int)$r['assignment_id'] ?></div>
          </td>
          <td>
            <?php if ($p === null): ?>
              <span class="sf-unknown">service not read</span>
            <?php else: ?>
              <div><?= $h($p['display']) ?></div>
              <div class="sf-id">
                <?php if (!empty($p['unlimited'])): ?>unlimited
                <?php elseif (!empty($p['cap_gb'])): ?><?= $h(number_format((float)$p['cap_gb'], 0)) ?> GB
                <?php else: ?><span class="sf-unknown">allowance not stated</span><?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $L = $r['live'];
              $pill = ['active'    => 'sf-ok',    'pending'  => 'sf-warnp',
                       'suspended' => 'sf-badp',  'inactive' => 'sf-badp',
                       'paused'    => 'sf-greyp', 'standby'  => 'sf-greyp'][$L['status']] ?? 'sf-greyp';
            ?>
            <?php if (!$L['known']): ?>
              <span class="sf-unknown"><?= $h($L['reason']) ?></span>
            <?php else: ?>
              <span class="sf-pill <?= $pill ?>"><?= $h($L['label']) ?></span>
              <?php if ($L['plan'] !== ''): ?><div class="sf-id"><?= $h($L['plan']) ?></div><?php endif; ?>
              <?php if ($L['stale']): ?>
                <div class="sf-id" style="color:#B45309;font-weight:700;">last synced
                  <?= $h(number_format((float)$L['age_hours'], 0)) ?>h ago</div>
              <?php elseif ($L['age_hours'] !== null): ?>
                <div class="sf-id"><?= $h(number_format((float)$L['age_hours'], 1)) ?>h ago</div>
              <?php endif; ?>
              <?php if ($L['telemetry'] === false): ?>
                <div class="sf-id">Starlink reports no telemetry on this line</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($u['used_gb'] === null): ?>
              <span class="sf-unknown"><?= $h($u['reason']) ?></span>
            <?php else: ?>
              <div><strong><?= $h(number_format((float)$u['used_gb'], 1)) ?> GB</strong>
                <?php if ($u['cycle'] !== ''): ?><span class="sf-id"><?= $h($u['cycle']) ?></span><?php endif; ?></div>
              <?php if ($u['pct'] !== null): ?>
                <div class="sf-id"><?= $h($u['pct']) ?>% of <?= $h(number_format((float)$u['cap_gb'], 0)) ?> GB ·
                  <?= $h(number_format((float)$u['remaining_gb'], 1)) ?> GB left</div>
                <div class="sf-bar"><i class="<?= $u['pct'] >= 90 ? 'hot' : '' ?>" style="width:<?= (float)$u['pct'] ?>%"></i></div>
              <?php elseif (!empty($u['unlimited'])): ?>
                <div class="sf-id">unlimited plan</div>
              <?php else: ?>
                <div class="sf-id sf-unknown">no allowance to measure against</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="sf-ids">
            <?php if ($r['service_line'] !== ''): ?><div><?= $h($r['service_line']) ?></div><?php endif; ?>
            <?php if ($r['account'] !== ''): ?><div><?= $h($r['account']) ?></div><?php endif; ?>
            <?php if ($r['router_id'] !== ''): ?><div>rtr <?= $h($r['router_id']) ?></div><?php endif; ?>
            <?php if ($r['terminal_id'] !== ''): ?><div>ut <?= $h($r['terminal_id']) ?></div><?php endif; ?>
            <?php if ($r['service_line'] === '' && $r['account'] === '' && $r['router_id'] === '' && $r['terminal_id'] === ''): ?>
              <span class="sf-unknown">none recorded — run binding_doctor --learn</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $lbl = ['match'      => ['sf-ok',    'matches'],
                      'missing'    => ['sf-warnp', 'not written'],
                      'differs'    => ['sf-badp',  'differs'],
                      'no_service' => ['sf-greyp', 'no service'],
                      'unknown'    => ['sf-greyp', 'not read']][$r['label']] ?? ['sf-greyp', 'not read'];
            ?>
            <span class="sf-pill <?= $lbl[0] ?>"><?= $h($lbl[1]) ?></span>
            <?php if ($r['label'] === 'differs' && $r['label_theirs']): ?>
              <div class="sf-id">uCRM says <?= $h(implode(', ', $r['label_theirs'])) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($orph): ?>
    <div class="sf-tablewrap" style="margin-top:18px;">
      <table class="sf" style="min-width:640px;">
        <thead><tr><th colspan="4" style="background:#FFFBEB;color:#78350F;font-size:11px;">
          <?= count($orph) ?> Starlink service line<?= count($orph) === 1 ? '' : 's' ?> no kit is bound to
        </th></tr>
        <tr><th>Service line</th><th>Starlink says</th><th>Plan</th><th>Account</th></tr></thead>
        <tbody>
        <?php foreach ($orph as $ol):
          $opill = ['active'    => 'sf-ok',    'pending'  => 'sf-warnp',
                    'suspended' => 'sf-badp',  'inactive' => 'sf-badp',
                    'paused'    => 'sf-greyp', 'standby'  => 'sf-greyp'][$ol['status']] ?? 'sf-greyp';
        ?>
          <tr>
            <td class="sf-ids"><?= $h($ol['service_line']) ?></td>
            <td><span class="sf-pill <?= $opill ?>"><?= $h($ol['label']) ?></span></td>
            <td><?= $ol['plan'] !== '' ? $h($ol['plan']) : '<span class="sf-unknown">not stated</span>' ?></td>
            <td class="sf-ids"><?= $h($ol['account']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="sf-note amber" style="margin-top:10px;">
      <strong>Starlink is billing these lines and no customer is bound to them.</strong>
      Each is either an installation nobody recorded — bind it with
      <code>tools/assign_kit.php</code> so it appears above — or a subscription still running for a
      customer who has gone, which is a cost with no revenue against it. This list is not a fault
      in the binding: it is what the binding is for.
    </div>
  <?php endif; ?>

  <div class="sf-note grey" style="margin-top:14px;">
    <strong>What this screen will not do.</strong> It never infers ownership. A kit appears here only
    because an <code>equipment_assignments</code> row binds it to a uCRM client id — not because a name,
    a service title or a kit file suggested it. Where a figure is unknown it says so: an unknown
    allowance is never shown as unlimited, and a kit with no reading is never shown as zero.
  </div>
</div>
