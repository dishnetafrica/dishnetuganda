<?php
/**
 * starlink_accounts.php — the fleet grouped by Starlink account.
 *
 * The companion to starlink_fleet.php, which groups by customer. That screen
 * answers "who is my customer and what are they using". This one answers
 * "what is stopping this fleet from reporting", and they are different
 * questions: a dead cookie, a service line nobody bound, an assignment with
 * no account on it — none of those have a customer row to appear on, so the
 * customer view cannot show them at all.
 *
 * Every figure is regrouped from StarlinkFleet::build(). Nothing is
 * recomputed, so this screen and the Fleet screen cannot disagree.
 *
 * Read-only.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/bootstrap_data.php';
require_once dirname(__DIR__, 2) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__, 2) . '/lib/CrmKitAttribute.php';
require_once dirname(__DIR__, 2) . '/lib/KitUsage.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkFleet.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkAccounts.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkSessionStore.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkLineDiscovery.php';
require_once dirname(__DIR__, 2) . '/lib/KitSlMap.php';
require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__, 2) . '/lib/crm_url.php';

$saRoot    = dirname(__DIR__, 2);
$saConfig  = $store->load('kyc_config.json') ?? [];
$saDataDir = getDataDir($saRoot);
$saEa      = EquipmentAssignment::fromStore($store);

$saCrm = null;
try { $saCrm = function_exists('svc') ? svc('crm')
                                      : CrmApiClient::fromUcrm($saRoot, $saConfig); }
catch (\Throwable $e) {}

$saFleet = (new StarlinkFleet($saEa, new KitUsage($saEa, $saDataDir), $store,
                              $saCrm ? new CrmKitAttribute($saCrm, $saEa) : null))->build();

$saSessions = [];
try { $saSessions = StarlinkAccounts::sessionSnapshot(
        new StarlinkSessionStore($saRoot, $saDataDir)); } catch (\Throwable $e) {}

$sa   = StarlinkAccounts::group($saFleet, $saSessions);
$sum  = $sa['summary'];
$map  = new KitSlMap($saDataDir);
$map->overlay(StarlinkLineDiscovery::load($saDataDir));
$crmWeb = dn_crm_web($saConfig);

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$gb = static function ($v): string {
    if ($v === null) return '—';
    $v = (float)$v;
    return $v >= 1024 ? number_format($v / 1024, 1) . ' TB' : number_format($v, 1) . ' GB';
};
?>
<style>
.sa-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.sa-wrap h2{margin:0;font-size:19px;font-weight:800;color:#0F172A;}
.sa-sub{font-size:12px;color:#64748B;margin:3px 0 16px;}
.sa-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.sa-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 14px;min-width:104px;}
.sa-stat b{display:block;font-size:20px;font-weight:800;color:#0F172A;line-height:1.2;}
.sa-stat span{font-size:10px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
.sa-stat.warn b{color:#B45309;} .sa-stat.bad b{color:#B91C1C;} .sa-stat.good b{color:#047857;}
.sa-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.6;margin-bottom:14px;}
.sa-note.amber{background:#FFFBEB;border:1px solid #FDE68A;color:#78350F;}
.sa-note.grey{background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;}
.sa-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-bottom:12px;}
.sa-card.attn{border-color:#FDE68A;background:#FFFDF5;}
.sa-card.dead{border-color:#FECACA;background:#FFFBFB;}
.sa-chead{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;}
.sa-acct{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;font-weight:700;color:#0F172A;}
.sa-tot{display:flex;gap:18px;flex-wrap:wrap;}
.sa-tot div{text-align:right;} .sa-tot b{display:block;font-size:16px;font-weight:800;color:#0F172A;}
.sa-tot span{font-size:9.5px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
.sa-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
.sa-b{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:700;border:1px solid transparent;}
.sa-b.ok{background:#ECFDF5;color:#047857;border-color:#A7F3D0;}
.sa-b.warn{background:#FFFBEB;color:#B45309;border-color:#FDE68A;}
.sa-b.bad{background:#FEF2F2;color:#B91C1C;border-color:#FECACA;}
.sa-b.grey{background:#F8FAFC;color:#475569;border-color:#E2E8F0;}
.sa-kits{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
.sa-kit{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:7px;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10.5px;border:1px solid #E2E8F0;background:#F8FAFC;color:#475569;}
.sa-kit .d{width:6px;height:6px;border-radius:50%;background:#CBD5E1;flex:0 0 auto;}
.sa-kit.active{background:#ECFDF5;border-color:#A7F3D0;color:#065F46;} .sa-kit.active .d{background:#10B981;}
.sa-kit.paused{background:#FFFBEB;border-color:#FDE68A;color:#92400E;} .sa-kit.paused .d{background:#F59E0B;}
table.sa{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px;}
table.sa th{text-align:left;padding:7px 10px;font-weight:700;color:#475569;text-transform:uppercase;
  letter-spacing:.4px;font-size:9.5px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;white-space:nowrap;}
table.sa td{padding:8px 10px;border-bottom:1px solid #F1F5F9;vertical-align:top;}
table.sa tr:last-child td{border-bottom:none;}
.sa-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#475569;}
.sa-why{font-size:11px;color:#94A3B8;}
</style>

<div class="sa-wrap">
  <h2>Starlink Accounts</h2>
  <div class="sa-sub">The same fleet as the Fleet screen, grouped by Starlink account instead of
    by customer — so the things that stop a fleet reporting have somewhere to appear.</div>

  <div class="sa-stats">
    <div class="sa-stat"><b><?= (int)$sum['accounts'] ?></b><span>Accounts</span></div>
    <div class="sa-stat<?= $sum['sessions_held'] === 0 ? ' bad' : ' good' ?>">
      <b><?= (int)$sum['sessions_held'] ?></b><span>Cookies held</span></div>
    <div class="sa-stat"><b><?= (int)$sum['kits'] ?></b><span>Bound kits</span></div>
    <div class="sa-stat good"><b><?= (int)$sum['active'] ?></b><span>Active</span></div>
    <div class="sa-stat<?= $sum['unbound_lines'] > 0 ? ' warn' : '' ?>">
      <b><?= (int)$sum['unbound_lines'] ?></b><span>Unbound lines</span></div>
    <div class="sa-stat<?= $sum['no_account'] > 0 ? ' bad' : '' ?>">
      <b><?= (int)$sum['no_account'] ?></b><span>No account</span></div>
  </div>

  <?php if ($sum['accounts_no_session'] > 0 || $sum['unbound_lines'] > 0 || $sum['no_account'] > 0): ?>
    <div class="sa-note amber">
      <strong>What is stopping this fleet reporting</strong><br>
      <?php if ($sum['accounts_no_session'] > 0): ?>
        <b><?= (int)$sum['accounts_no_session'] ?></b> account(s) have no cookie — nothing can be
        read from them at all. <a href="?tab=starlink_session">Import one</a>.<br>
      <?php endif; ?>
      <?php if ($sum['unbound_lines'] > 0): ?>
        <b><?= (int)$sum['unbound_lines'] ?></b> service line(s) that no assignment claims. Some
        will be stock; any that are not are a customer whose install was never recorded, and that
        customer is invisible on every other screen.<br>
      <?php endif; ?>
      <?php if ($sum['no_account'] > 0): ?>
        <b><?= (int)$sum['no_account'] ?></b> assignment(s) name no Starlink account. The usage
        endpoint takes the account in its path, so these can never be collected however good the
        cookie is.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($sa['accounts'] === []): ?>
    <div class="sa-note grey">
      Nothing to group yet — no assignment names a Starlink account, no cookie is held, and the
      data plugin's service-line cache has nothing in it. Import a cookie under
      <a href="?tab=starlink_session">Starlink Sessions</a> and this fills in.
    </div>
  <?php endif; ?>

  <?php foreach ($sa['accounts'] as $a):
      $cls = !$a['session_held'] && ($a['total_kits'] || $a['unclaimed']) ? ' dead'
           : ($a['needs'] !== [] ? ' attn' : ''); ?>
    <div class="sa-card<?= $cls ?>">
      <div class="sa-chead">
        <div>
          <div class="sa-acct"><?= $h($a['account']) ?></div>
          <div class="sa-badges">
            <?php if ($a['session_held']): ?>
              <span class="sa-b <?= $a['session_state'] === 'ok' ? 'ok' : 'warn' ?>">
                Cookie held<?= $a['session_state'] !== '' ? ' · ' . $h($a['session_state']) : '' ?></span>
              <?php if ($a['session_last_ok'] !== ''): ?>
                <span class="sa-b grey">Last accepted <?= $h($a['session_last_ok']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="sa-b bad">No cookie</span>
            <?php endif; ?>
            <?php if ($a['total_kits'] === 0 && $a['unclaimed'] !== []): ?>
              <span class="sa-b warn">Nothing bound to a customer</span>
            <?php endif; ?>
            <?php if (in_array('no_usage', $a['needs'], true)): ?>
              <span class="sa-b warn">Active, nothing collected</span>
            <?php endif; ?>
            <?php if ($a['needs'] === [] && $a['total_kits'] > 0): ?>
              <span class="sa-b ok">Reporting</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="sa-tot">
          <div><b><?= (int)$a['total_kits'] ?></b><span>Kits</span></div>
          <div><b><?= (int)$a['active_kits'] ?></b><span>Active</span></div>
          <div><b><?= $a['collected_kits'] > 0 ? $h($gb($a['used_gb'])) : '—' ?></b><span>This cycle</span></div>
        </div>
      </div>

      <?php if ($a['kits'] !== []): ?>
        <div class="sa-kits">
          <?php foreach ($a['kits'] as $k): ?>
            <span class="sa-kit <?= $h($k['status']) ?>" title="<?= $h($k['client_name']) ?>">
              <span class="d"></span><?= $h($k['kit_serial']) ?></span>
          <?php endforeach; ?>
        </div>
        <table class="sa">
          <thead><tr><th>Kit</th><th>Customer</th><th>Service line</th><th>State</th>
                     <th>This cycle</th></tr></thead>
          <tbody>
          <?php foreach ($a['kits'] as $k): ?>
            <tr>
              <td class="sa-mono"><?= $h($k['kit_serial']) ?></td>
              <td><?php if ($k['client_id'] > 0 && $crmWeb !== ''): ?>
                    <a href="<?= $h($crmWeb) ?>/client/<?= (int)$k['client_id'] ?>" target="_blank"
                       rel="noopener"><?= $h($k['client_name'] !== '' ? $k['client_name'] : 'client #' . $k['client_id']) ?></a>
                  <?php else: ?><?= $h($k['client_name']) ?><?php endif; ?></td>
              <td class="sa-mono"><?= $k['service_line'] !== '' ? $h($k['service_line'])
                                     : '<span class="sa-why">none recorded</span>' ?></td>
              <td><?= $k['label'] !== '' ? $h($k['label']) : '<span class="sa-why">unreported</span>' ?></td>
              <td><?php if ($k['used_gb'] !== null): ?>
                    <?= $h($gb($k['used_gb'])) ?>
                    <?php if ($k['cycle'] !== ''): ?><div class="sa-why"><?= $h($k['cycle']) ?></div><?php endif; ?>
                  <?php else: ?>
                    <span class="sa-why"><?= $h($k['why_no_usage'] !== '' ? $k['why_no_usage'] : 'nothing collected') ?></span>
                  <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if ($a['unclaimed'] !== []): ?>
        <table class="sa">
          <thead><tr><th colspan="4">
            <?= count($a['unclaimed']) ?> line(s) Starlink knows about that no assignment claims
          </th></tr>
          <tr><th>Service line</th><th>State</th><th>Plan</th><th>Kit, if Starlink named one</th></tr></thead>
          <tbody>
          <?php foreach ($a['unclaimed'] as $u):
              $kit = $map->kitFor((string)$u['service_line']); ?>
            <tr>
              <td class="sa-mono"><?= $h($u['service_line']) ?></td>
              <td><?= $h($u['label']) ?></td>
              <td><?= $u['plan'] !== '' ? $h($u['plan']) : '<span class="sa-why">—</span>' ?></td>
              <td class="sa-mono"><?php if ($kit !== ''): ?>
                    <?= $h($kit) ?>
                    <div class="sa-why"><?= $map->sourceFor($kit) === 'typed'
                        ? 'from the typed map' : 'from Starlink' ?> — bind it with
                      <code>tools/assign_kit.php</code></div>
                  <?php else: ?>
                    <span class="sa-why">no kit serial known — Starlink has none against this
                      line, and nobody has typed one</span>
                  <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($sa['orphans'] !== []): ?>
    <div class="sa-card dead">
      <div class="sa-acct">No Starlink account recorded</div>
      <div class="sa-badges"><span class="sa-b bad">Cannot be collected</span></div>
      <div class="sa-note grey" style="margin:10px 0 0;">
        The usage endpoint is
        <code>/api/telemetryagg/v1/data-usage/account/{account}/service-line/{line}/annotated</code>
        — it takes the account in the path. An assignment without one is unreadable no matter how
        fresh the cookie is. Add it with <code>tools/assign_kit.php</code>.
      </div>
      <table class="sa">
        <thead><tr><th>Kit</th><th>Customer</th><th>Service line</th></tr></thead>
        <tbody>
        <?php foreach ($sa['orphans'] as $o): ?>
          <tr>
            <td class="sa-mono"><?= $h($o['kit_serial']) ?></td>
            <td><?= $h($o['client_name']) ?></td>
            <td class="sa-mono"><?= $o['service_line'] !== '' ? $h($o['service_line'])
                                   : '<span class="sa-why">none recorded either</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="sa-note grey">
    <strong>Where these figures come from.</strong> Every number is regrouped from the same
    <code>StarlinkFleet::build()</code> the Fleet screen renders — not fetched again. Two screens
    computing "GB this cycle" from two sources is how they end up disagreeing, and then nobody
    believes either one.
  </div>
</div>
