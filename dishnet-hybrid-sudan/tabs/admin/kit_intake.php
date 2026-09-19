<?php
/**
 * kit_intake.php — the Kit Number typed on a service, reviewed before it binds.
 *
 * A REVIEW screen. It reads the uCRM service attribute, compares it with the
 * authoritative binding, and shows what agrees, what could be bound and what
 * cannot. Nothing on this page runs on a schedule and nothing binds by being
 * looked at: a person reads a row and presses a button, once, per kit.
 *
 * ── THE BOUNDARY IT MUST NOT CROSS ──────────────────────────────────────
 *
 *   starlinkDetails       where a human enters the kit        (input)
 *   equipment_assignments who actually owns it                (authority)
 *   dishnet-data-report   Starlink operational data           (integration)
 *
 * So: editing or deleting the attribute can never release or move a kit. The
 * screen offers exactly one verb — bind a kit that is currently bound to
 * nobody — and every write goes through KitAttributeIntake::apply(), which
 * goes through EquipmentAssignment::assign(), which is the same door
 * tools/assign_kit.php uses. There is no second assignment mechanism here.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/bootstrap_data.php';
require_once dirname(__DIR__, 2) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__, 2) . '/lib/KitAttributeIntake.php';
require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__, 2) . '/lib/crm_url.php';

$kiRoot   = dirname(__DIR__, 2);
$kiConfig = $store->load('kyc_config.json') ?? [];
$kiNotice = null;
$kiError  = null;

$kiCrm = null;
try { $kiCrm = function_exists('svc') ? svc('crm')
                                      : CrmApiClient::fromUcrm($kiRoot, $kiConfig); }
catch (\Throwable $e) {}

$kiEa     = EquipmentAssignment::fromStore($store);
$kiIntake = new KitAttributeIntake($store->getPdo(), $kiEa, $kiCrm);

// ── Binding one kit, deliberately ───────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['ki_action'] ?? '') === 'bind') {
    if (function_exists('csrfCheck')) csrfCheck();

    $wantKit     = strtoupper(trim((string)($_POST['ki_kit'] ?? '')));
    $wantClient  = (int)($_POST['ki_client'] ?? 0);
    $wantService = (int)($_POST['ki_service'] ?? 0);

    // The posted row is a REQUEST, not an instruction. Re-scan and act only if
    // that exact kit/client/service is still a valid proposal — a page left
    // open while somebody else assigned the kit must not bind it anyway.
    $fresh = $kiIntake->scan();
    $match = null;
    foreach ($fresh['proposals'] as $p) {
        if ($p['kit'] === $wantKit && (int)$p['client'] === $wantClient
            && (int)$p['service'] === $wantService) { $match = $p; break; }
    }

    if ($match === null) {
        $kiError = 'That kit is no longer ready to bind — the page was out of date. '
                 . 'Nothing was written; the refreshed result is below.';
    } else {
        $actor = ['id'  => (int)($retailer['id'] ?? 0),
                  'name' => (string)($retailer['name'] ?? $retailer['username'] ?? 'admin')];
        $res = $kiIntake->apply([$match], $actor);
        if ($res['created'] !== []) {
            $kiNotice = $wantKit . ' is now bound to client #' . $wantClient
                      . ' on service #' . $wantService
                      . ' — assignment #' . (int)$res['created'][0]['assignment'] . '.';
            if (function_exists('logActivity')) {
                logActivity(getDataDir($kiRoot), 'kit_bound_from_attribute',
                    'Kit bound from the uCRM service field',
                    $wantKit . ' → client #' . $wantClient . ' service #' . $wantService);
            }
        } else {
            $kiError = 'Refused at the last moment: '
                     . (string)($res['failed'][0]['detail'] ?? 'the assignment was not created.');
        }
    }
}

$ki = ['proposals' => [], 'settled' => [], 'refusals' => [],
       'services' => 0, 'scanned' => 0, 'reachable' => false];
if ($kiCrm === null) {
    $kiError = $kiError ?? 'uCRM is not reachable, so no service field can be read.';
} else {
    try { $ki = $kiIntake->scan(); }
    catch (\Throwable $e) { $kiError = $kiError ?? ('Could not read uCRM: ' . $e->getMessage()); }
}

/** uCRM client names, once each. */
$kiNameOf = function (int $id) use ($kiCrm): string {
    static $seen = [];
    if (isset($seen[$id])) return $seen[$id];
    if ($kiCrm === null) return $seen[$id] = '';
    $c = $kiCrm->get('clients/' . $id);
    if (!is_array($c)) return $seen[$id] = '(uCRM did not answer)';
    $n = trim((string)($c['companyName'] ?? ''));
    if ($n === '') $n = trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? ''));
    return $seen[$id] = ($n !== '' ? $n : '(unnamed)');
};

/** Every refusal in words an operator can act on. */
$kiWhy = static function (string $reason): array {
    switch ($reason) {
        case 'held_elsewhere': return [
            'This kit is already assigned to a different customer.',
            'Editing the service field must never move a dish between customers — that '
          . 'would destroy the history of who had it. If it genuinely moved, release it '
          . 'on the Stock screen (Return), or record a replacement.'];
        case 'not_in_stock': return [
            'No stock unit has this serial, so it is a typo rather than equipment.',
            'Assigning it would invent inventory that does not exist. Receive the kit on '
          . 'the Stock screen with its supplier and cost, or import the Starlink order it '
          . 'came on — check the serial against the label first.'];
        case 'service_taken': return [
            'That service already runs a different kit.',
            'One service running two dishes makes every later suspension ambiguous. If the '
          . 'dish was replaced, record it as a replacement so both are kept in the history.'];
        case 'service_missing': return [
            'The assignment exists but has no uCRM service recorded against it.',
            'A text field may not fill that in — a service is what per-service blocking '
          . 'acts on. Add it with tools/assign_kit.php, where the service is checked to '
          . 'belong to that customer.'];
        case 'service_differs': return [
            'The assignment is on a different uCRM service than the field is typed on.',
            'Somebody may have moved the customer to a new service without moving the '
          . 'binding, or typed the kit onto the wrong service. Worth looking at before '
          . 'anything is changed.'];
    }
    return ['Refused by validation.', 'See the detail below.'];
};

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$crmWeb = dn_crm_web($kiConfig);
?>
<style>
.ki-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.ki-wrap h2{margin:0;font-size:19px;font-weight:800;color:#0F172A;}
.ki-sub{font-size:12px;color:#64748B;margin:3px 0 16px;max-width:70ch;line-height:1.55;}
.ki-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.6;margin-bottom:14px;}
.ki-note.green{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.ki-note.red{background:#FEF2F2;border:1px solid #FECACA;color:#7F1D1D;}
.ki-note.grey{background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;}
.ki-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.ki-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 14px;min-width:118px;}
.ki-stat b{display:block;font-size:20px;font-weight:800;color:#0F172A;line-height:1.2;}
.ki-stat span{font-size:10px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
.ki-stat.ok b{color:#047857;} .ki-stat.go b{color:#1565C0;} .ki-stat.bad b{color:#B91C1C;}
.ki-sec{margin-bottom:26px;}
.ki-sec h3{font-size:13px;font-weight:800;color:#0F172A;text-transform:uppercase;
  letter-spacing:.6px;margin:0 0 4px;display:flex;align-items:center;gap:9px;}
.ki-sec .lede{font-size:12.5px;color:#64748B;margin:0 0 12px;max-width:70ch;line-height:1.55;}
.ki-n{display:inline-flex;min-width:22px;height:22px;padding:0 7px;border-radius:11px;
  align-items:center;justify-content:center;font-size:11px;font-weight:800;}
.ki-n.ok{background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;}
.ki-n.go{background:#EFF6FF;color:#1565C0;border:1px solid #BFDBFE;}
.ki-n.bad{background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;}
.ki-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:15px 17px;margin-bottom:10px;}
.ki-card.ok{border-left:3px solid #047857;}
.ki-card.go{border-left:3px solid #1565C0;}
.ki-card.bad{border-left:3px solid #B91C1C;}
.ki-top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;}
.ki-kit{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;
  font-weight:700;color:#0F172A;}
.ki-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px 18px;margin-top:11px;}
.ki-f label{display:block;font-size:9.5px;font-weight:700;color:#94A3B8;text-transform:uppercase;
  letter-spacing:.5px;margin-bottom:2px;}
.ki-f div{font-size:13px;color:#0F172A;}
.ki-act{margin-top:12px;padding-top:11px;border-top:1px solid #F1F5F9;font-size:12.5px;line-height:1.6;}
.ki-act b{color:#0F172A;}
.ki-btn{padding:8px 16px;border-radius:8px;border:none;background:#1565C0;color:#fff;
  font-size:12.5px;font-weight:700;cursor:pointer;}
.ki-why{font-size:12.5px;color:#475569;line-height:1.6;margin-top:3px;}
.ki-detail{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#64748B;
  background:#F8FAFC;border:1px solid #F1F5F9;border-radius:7px;padding:7px 9px;margin-top:8px;}
.ki-tag{display:inline-flex;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;
  border:1px solid;font-family:ui-monospace,Menlo,monospace;}
.ki-tag.bad{background:#FEF2F2;color:#B91C1C;border-color:#FECACA;}
.ki-empty{background:#F8FAFC;border:1px dashed #E2E8F0;border-radius:10px;padding:16px;
  font-size:12.5px;color:#64748B;text-align:center;}
</style>

<div class="ki-wrap">
  <h2>Kit Number reconciliation</h2>
  <div class="ki-sub">The Kit Number an operator types into a uCRM service is an
    <strong>input</strong>. <code>equipment_assignments</code> stays the authority. This screen
    shows where the two agree, what could be bound, and what cannot — and binds only what
    somebody presses a button for.</div>

  <?php if ($kiNotice): ?><div class="ki-note green"><?= $h($kiNotice) ?></div><?php endif; ?>
  <?php if ($kiError):  ?><div class="ki-note red"><?= $h($kiError) ?></div><?php endif; ?>
  <?php if (!$ki['reachable']): ?>
    <!-- "uCRM did not answer" and "there are no services" must never look the
         same. Three empty sections would say everything is fine. -->
    <div class="ki-note red"><strong>uCRM did not answer.</strong> Nothing below was read —
      the empty sections mean <em>unknown</em>, not <em>nothing to do</em>. Check the CRM
      connection and reload.</div>
  <?php endif; ?>

  <div class="ki-stats">
    <div class="ki-stat"><b><?= (int)$ki['services'] ?></b><span>Live services</span></div>
    <div class="ki-stat"><b><?= (int)$ki['scanned'] ?></b><span>Kit numbers typed</span></div>
    <div class="ki-stat ok"><b><?= count($ki['settled']) ?></b><span>Agree</span></div>
    <div class="ki-stat go"><b><?= count($ki['proposals']) ?></b><span>Ready to bind</span></div>
    <div class="ki-stat<?= $h($ki['refusals'] !== [] ? ' bad' : '') ?>">
      <b><?= count($ki['refusals']) ?></b><span>Refused</span></div>
  </div>

  <!-- ── 1 · AGREEMENT ──────────────────────────────────────────────── -->
  <div class="ki-sec">
    <h3><span class="ki-n ok"><?= count($ki['settled']) ?></span> Already bound · agreement</h3>
    <p class="lede">The service field and the authoritative binding say the same thing.
      Nothing to do.</p>
    <?php if ($ki['settled'] === []): ?>
      <div class="ki-empty">No typed kit currently matches an assignment.</div>
    <?php endif; ?>
    <?php foreach ($ki['settled'] as $r): ?>
      <div class="ki-card ok">
        <div class="ki-top">
          <div class="ki-kit"><?= $h($r['kit']) ?></div>
          <div style="font-size:12px;font-weight:700;color:#047857">✓ agrees</div>
        </div>
        <div class="ki-grid">
          <div class="ki-f"><label>Customer</label><div>
            <?php $nm = $kiNameOf((int)$r['client']); if ($crmWeb !== ''): ?>
              <a href="<?= $h($crmWeb) ?>/client/<?= (int)$r['client'] ?>" target="_blank" rel="noopener"><?= $h($nm) ?></a>
            <?php else: ?><?= $h($nm) ?><?php endif; ?></div></div>
          <div class="ki-f"><label>Customer ID</label><div>#<?= (int)$r['client'] ?></div></div>
          <div class="ki-f"><label>Service</label><div><?= $h($r['service_name'] !== '' ? $r['service_name'] : '(unnamed)') ?></div></div>
          <div class="ki-f"><label>Service ID</label><div>#<?= (int)$r['service'] ?></div></div>
          <div class="ki-f"><label>Assignment ID</label><div>#<?= (int)($r['assignment'] ?? 0) ?></div></div>
        </div>
        <div class="ki-act"><b>Action:</b> none. The typed field and
          <code>equipment_assignments</code> already agree.</div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ── 2 · READY TO BIND ──────────────────────────────────────────── -->
  <div class="ki-sec">
    <h3><span class="ki-n go"><?= count($ki['proposals']) ?></span> Ready to bind</h3>
    <p class="lede">Valid, in stock, bound to nobody, and the service is free. Pressing Bind
      creates the assignment through the same validation
      <code>tools/assign_kit.php</code> uses — nothing is written until you do.</p>
    <?php if ($ki['proposals'] === []): ?>
      <div class="ki-empty">Nothing is waiting to be bound.</div>
    <?php endif; ?>
    <?php foreach ($ki['proposals'] as $r): ?>
      <div class="ki-card go">
        <div class="ki-top">
          <div class="ki-kit"><?= $h($r['kit']) ?></div>
          <form method="post" style="margin:0" onsubmit="return confirm('Bind <?= $h($r['kit']) ?> to client #<?= (int)$r['client'] ?> on service #<?= (int)$r['service'] ?>?');">
            <?php if (function_exists('csrfField')) echo csrfField(); ?>
            <input type="hidden" name="ki_action"  value="bind">
            <input type="hidden" name="ki_kit"     value="<?= $h($r['kit']) ?>">
            <input type="hidden" name="ki_client"  value="<?= (int)$r['client'] ?>">
            <input type="hidden" name="ki_service" value="<?= (int)$r['service'] ?>">
            <button class="ki-btn" type="submit">Bind this kit</button>
          </form>
        </div>
        <div class="ki-grid">
          <div class="ki-f"><label>Customer</label><div><?= $h($kiNameOf((int)$r['client'])) ?></div></div>
          <div class="ki-f"><label>Customer ID</label><div>#<?= (int)$r['client'] ?></div></div>
          <div class="ki-f"><label>Service</label><div><?= $h($r['service_name'] !== '' ? $r['service_name'] : '(unnamed)') ?></div></div>
          <div class="ki-f"><label>Service ID</label><div>#<?= (int)$r['service'] ?></div></div>
          <div class="ki-f"><label>Current assignment</label><div>none — bound to nobody</div></div>
        </div>
        <div class="ki-act"><b>Would create:</b> <?= $h($r['kit']) ?> →
          client #<?= (int)$r['client'] ?>, service #<?= (int)$r['service'] ?>,
          through <code>EquipmentAssignment::assign()</code>.</div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ── 3 · REFUSED ────────────────────────────────────────────────── -->
  <div class="ki-sec">
    <h3><span class="ki-n bad"><?= count($ki['refusals']) ?></span> Refused</h3>
    <p class="lede">Each of these needs a person. None of them will ever bind by itself, and
      none can be forced from this screen — the fix is in the place the problem actually is.</p>
    <?php if ($ki['refusals'] === []): ?>
      <div class="ki-empty">Nothing was refused.</div>
    <?php endif; ?>
    <?php foreach ($ki['refusals'] as $r): [$what, $fix] = $kiWhy((string)$r['reason']); ?>
      <div class="ki-card bad">
        <div class="ki-top">
          <div class="ki-kit"><?= $h($r['kit']) ?></div>
          <span class="ki-tag bad"><?= $h($r['reason']) ?></span>
        </div>
        <div class="ki-grid">
          <div class="ki-f"><label>Customer</label><div><?= $h($kiNameOf((int)$r['client'])) ?></div></div>
          <div class="ki-f"><label>Customer ID</label><div>#<?= (int)$r['client'] ?></div></div>
          <div class="ki-f"><label>Service</label><div><?= $h($r['service_name'] !== '' ? $r['service_name'] : '(unnamed)') ?></div></div>
          <div class="ki-f"><label>Service ID</label><div>#<?= (int)$r['service'] ?></div></div>
        </div>
        <div class="ki-act">
          <b>What it means:</b> <?= $h($what) ?>
          <div class="ki-why"><b>What to do:</b> <?= $h($fix) ?></div>
          <?php if ((string)($r['detail'] ?? '') !== ''): ?>
            <div class="ki-detail"><?= $h($r['detail']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ki-note grey">
    <strong>Why this screen does not run on its own.</strong> Nothing here is scheduled.
    Editing or deleting the <code>starlinkDetails</code> field cannot release a kit or move it
    to another customer — the field is where a human enters the number, and
    <code>equipment_assignments</code> is who actually owns it. Starlink usage is a separate
    integration and is untouched by anything on this page.
  </div>
</div>
