<?php
/**
 * dpo_payments.php — online payments taken through DPO Pay, and the settings
 * that govern them.
 *
 * One screen owns DPO: its configuration sits beside the transactions it
 * produced, rather than in the shared settings page, so that turning the
 * gateway off and seeing what is in flight are the same glance.
 *
 * ── WHAT IT NEVER SHOWS ─────────────────────────────────────────────────
 *
 * The company token. It is a bearer secret carried inside the request body —
 * DPO uses no header and no signature — so this page reports whether one is
 * configured and never what it is. The same for anything else in the vault.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/bootstrap_data.php';
require_once dirname(__DIR__, 2) . '/lib/ConfigVault.php';
require_once dirname(__DIR__, 2) . '/lib/DpoBootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/crm_url.php';

$dpRoot    = dirname(__DIR__, 2);
$dpDataDir = getDataDir($dpRoot);
// Through the vault: public.php builds $config from kyc_config.json alone, so
// a credential that lives only in the vault would read here as "not set"
// while working perfectly everywhere else.
$dpConfig  = DpoBootstrap::vaulted($store->load('kyc_config.json') ?? []);
$dpNotice  = null;
$dpError   = null;

// ── Saving settings ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['dp_action'] ?? '') === 'save') {
    if (function_exists('csrfCheck')) csrfCheck();

    $dpPairs = [
        'dpo_enabled'            => !empty($_POST['dpo_enabled']) ? '1' : '0',
        'dpo_environment'        => ($_POST['dpo_environment'] ?? 'test') === 'live' ? 'live' : 'test',
        'dpo_service_type'       => trim((string)($_POST['dpo_service_type'] ?? '')),
        'dpo_company_acc_ref'    => trim((string)($_POST['dpo_company_acc_ref'] ?? '')),
        'dpo_payment_method_uuid'=> trim((string)($_POST['dpo_payment_method_uuid'] ?? '')),
        'dpo_currencies'         => trim((string)($_POST['dpo_currencies'] ?? '')),
        'dpo_unpayable_statuses' => trim((string)($_POST['dpo_unpayable_statuses'] ?? '')),
        'dpo_ptl'                => (string)max(0, (int)($_POST['dpo_ptl'] ?? 30)),
        'dpo_test_clients'       => implode(', ', DpoBootstrap::testClients(
                                        ['dpo_test_clients' => (string)($_POST['dpo_test_clients'] ?? '')])),
    ];
    // A blank token field LEAVES the stored one alone. Clearing a live
    // credential must be deliberate, not the result of saving the form
    // without retyping it.
    $dpTok = trim((string)($_POST['dpo_company_token'] ?? ''));
    if ($dpTok !== '') $dpPairs['dpo_company_token'] = $dpTok;

    $dpConfig = array_merge($dpConfig, $dpPairs);
    $store->save('kyc_config.json', $dpConfig);
    // Mirror into the vault so a re-install does not lose the gateway —
    // exactly how the EFRIS credentials are held.
    $dpV = ConfigVault::store($dpRoot, $dpDataDir, $dpPairs);
    $dpNotice = empty($dpV['ok'])
        ? 'Saved, but NOT vaulted: ' . ($dpV['error'] ?? 'unknown') . ' — it will be lost on re-install.'
        : 'Saved.';
    if (function_exists('logActivity')) {
        logActivity($dpDataDir, 'dpo_settings_saved', 'DPO Pay settings changed',
            'environment=' . $dpPairs['dpo_environment'] . ' enabled=' . $dpPairs['dpo_enabled']);
    }
}

// ── The test link for DPO's reviewer ────────────────────────────────────
// A new key replaces the old one, so a link that went further than it
// should can be closed from here.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['dp_action'] ?? '') === 'new_test_link') {
    if (function_exists('csrfCheck')) csrfCheck();
    $dpPairs  = ['dpo_test_link_key' => bin2hex(random_bytes(16))];
    $dpConfig = array_merge($dpConfig, $dpPairs);
    $store->save('kyc_config.json', $dpConfig);
    $dpV = ConfigVault::store($dpRoot, $dpDataDir, $dpPairs);
    $dpNotice = empty($dpV['ok'])
        ? 'New test link made, but NOT vaulted: ' . ($dpV['error'] ?? 'unknown') . '.'
        : 'New test link made. Any earlier link no longer opens.';
    if (function_exists('logActivity')) {
        logActivity($dpDataDir, 'dpo_test_link_made', 'DPO Pay test link replaced', '');
    }
}

$dpReady = DpoBootstrap::readiness($dpConfig);
$dpStore = null; $dpRows = []; $dpSum = ['by_status' => [], 'test_rows' => 0, 'settled_value' => []];
$dpOne   = null; $dpEvents = [];
try {
    require_once $dpRoot . '/lib/MigrationRunner.php';
    (new MigrationRunner($store->getPdo(), $dpRoot . '/migrations'))->run();
    $dpStore = new DpoPaymentStore($store->getPdo());
    $dpFilter = [
        'status'      => trim((string)($_GET['dp_status'] ?? '')),
        'environment' => trim((string)($_GET['dp_env'] ?? '')),
        'client'      => (int)($_GET['dp_client'] ?? 0),
        'invoice'     => (int)($_GET['dp_invoice'] ?? 0),
        'reference'   => trim((string)($_GET['dp_ref'] ?? '')),
        'from'        => trim((string)($_GET['dp_from'] ?? '')),
        'to'          => trim((string)($_GET['dp_to'] ?? '')),
    ];
    $dpRows = $dpStore->search($dpFilter, 300);
    $dpSum  = $dpStore->summary();
    $dpOpen = trim((string)($_GET['dp_open'] ?? ''));
    if ($dpOpen !== '') {
        $dpOne = $dpStore->byReference($dpOpen);
        if ($dpOne !== null) $dpEvents = $dpStore->events((int)$dpOne['id']);
    }
} catch (\Throwable $e) { $dpError = $e->getMessage(); }

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($n, $c): string => $h($c) . ' ' . number_format((float)$n, 0);
$crmWeb = dn_crm_web($dpConfig);
$dpPill = static function (string $s): string {
    $map = ['SUCCESS' => 'ok', 'REFUNDED' => 'grey', 'PENDING' => 'warn', 'CREATED' => 'warn',
            'REDIRECTED' => 'warn', 'QUARANTINED' => 'bad', 'FAILED' => 'grey',
            'CANCELLED' => 'grey', 'EXPIRED' => 'grey'];
    return $map[$s] ?? 'grey';
};
?>
<style>
.dp-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.dp-wrap h2{margin:0;font-size:19px;font-weight:800;color:#0F172A;}
.dp-sub{font-size:12px;color:#64748B;margin:3px 0 16px;}
.dp-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-bottom:14px;}
.dp-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.dp-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 14px;min-width:110px;}
.dp-stat b{display:block;font-size:20px;font-weight:800;color:#0F172A;line-height:1.2;}
.dp-stat span{font-size:10px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
.dp-stat.bad b{color:#B91C1C;} .dp-stat.good b{color:#047857;} .dp-stat.warn b{color:#B45309;}
.dp-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.6;margin-bottom:14px;}
.dp-note.amber{background:#FFFBEB;border:1px solid #FDE68A;color:#78350F;}
.dp-note.red{background:#FEF2F2;border:1px solid #FECACA;color:#7F1D1D;}
.dp-note.green{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.dp-note.grey{background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;}
.dp-pill{display:inline-flex;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;border:1px solid;}
.dp-pill.ok{background:#ECFDF5;color:#047857;border-color:#A7F3D0;}
.dp-pill.warn{background:#FFFBEB;color:#B45309;border-color:#FDE68A;}
.dp-pill.bad{background:#FEF2F2;color:#B91C1C;border-color:#FECACA;}
.dp-pill.grey{background:#F8FAFC;color:#475569;border-color:#E2E8F0;}
.dp-tablewrap{overflow-x:auto;background:#fff;border:1px solid #E2E8F0;border-radius:12px;}
table.dp{width:100%;border-collapse:collapse;font-size:12.5px;min-width:860px;}
table.dp th{text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;
 letter-spacing:.4px;font-size:10px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;white-space:nowrap;}
table.dp td{padding:10px 12px;border-bottom:1px solid #F1F5F9;vertical-align:top;}
table.dp tr:last-child td{border-bottom:none;}
.dp-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:#475569;}
.dp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
.dp-f label{display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;
 letter-spacing:.4px;margin-bottom:4px;}
.dp-f input,.dp-f select{width:100%;padding:8px 10px;border:1px solid #E2E8F0;border-radius:8px;
 font-size:13px;color:#0F172A;background:#fff;}
.dp-f .hint{font-size:11px;color:#94A3B8;margin-top:4px;line-height:1.45;}
.dp-btn{padding:9px 18px;border-radius:9px;border:none;background:#1565C0;color:#fff;
 font-size:13px;font-weight:700;cursor:pointer;}
.dp-url{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:#0F172A;
 background:#F8FAFC;border:1px solid #E2E8F0;border-radius:7px;padding:7px 9px;
 word-break:break-all;margin-top:4px;}
.dp-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;}
.dp-filters input,.dp-filters select{padding:7px 9px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;}
.dp-ev{border-left:2px solid #E2E8F0;padding-left:14px;margin-left:4px;}
.dp-ev .e{padding:7px 0;border-bottom:1px solid #F8FAFC;}
.dp-ev .e b{font-size:12.5px;color:#0F172A;font-weight:700;}
.dp-ev .e span{font-size:11.5px;color:#64748B;display:block;margin-top:2px;line-height:1.5;}
.dp-ev .e i{font-size:10.5px;color:#94A3B8;font-style:normal;}
</style>

<div class="dp-wrap">
  <h2>DPO Pay</h2>
  <div class="dp-sub">Invoice payments taken online by card or mobile money, and the
    settings that govern them.</div>

  <?php if ($dpNotice): ?><div class="dp-note green"><?= $h($dpNotice) ?></div><?php endif; ?>
  <?php if ($dpError):  ?><div class="dp-note red">Could not read payments: <?= $h($dpError) ?></div><?php endif; ?>

  <?php if (!$dpReady['ready']): ?>
    <div class="dp-note amber">
      <strong>Not ready for production.</strong> Missing:
      <strong><?= $h(implode(', ', $dpReady['missing'])) ?></strong>.
      Until these are set, initiating a payment is refused — and a payment that
      somehow arrived would be <em>quarantined</em> rather than booked as Cash,
      because Cash feeds agent cash reconciliation.
    </div>
  <?php endif; ?>

  <?php if ($dpReady['environment'] === 'test'): ?>
    <div class="dp-note grey">
      <strong>Test environment.</strong> DPO has no separate test host — test and live
      use the same URLs and differ only by which company token is sent. Every row taken
      now is stamped <code>test</code> and is excluded from settled-value totals.
    </div>
  <?php else: ?>
    <div class="dp-note red">
      <strong>LIVE environment.</strong> Payments taken here are real money.
    </div>
  <?php endif; ?>

  <div class="dp-stats">
    <div class="dp-stat<?= $dpReady['enabled'] ? ' good' : '' ?>">
      <b><?= $dpReady['enabled'] ? 'On' : 'Off' ?></b><span>Pay Now</span></div>
    <div class="dp-stat good"><b><?= (int)($dpSum['by_status']['SUCCESS'] ?? 0) ?></b><span>Settled</span></div>
    <div class="dp-stat warn"><b><?= (int)(($dpSum['by_status']['PENDING'] ?? 0)
        + ($dpSum['by_status']['REDIRECTED'] ?? 0) + ($dpSum['by_status']['CREATED'] ?? 0)) ?></b><span>In flight</span></div>
    <div class="dp-stat<?= !empty($dpSum['by_status']['QUARANTINED']) ? ' bad' : '' ?>">
      <b><?= (int)($dpSum['by_status']['QUARANTINED'] ?? 0) ?></b><span>Quarantined</span></div>
    <div class="dp-stat"><b><?= (int)$dpSum['test_rows'] ?></b><span>Test rows</span></div>
    <?php foreach ($dpSum['settled_value'] as $cur => $val): ?>
      <div class="dp-stat good"><b><?= $money($val, $cur) ?></b><span>Settled (live)</span></div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($dpSum['by_status']['QUARANTINED'])): ?>
    <div class="dp-note red">
      <strong><?= (int)$dpSum['by_status']['QUARANTINED'] ?> payment(s) quarantined.</strong>
      Money moved at DPO but it was not a clean settlement — a different amount, a
      different currency, or figures DPO would not confirm. Nothing has been posted to
      uCRM for these. Open each one and decide.
    </div>
  <?php endif; ?>

  <!-- ── Settings ───────────────────────────────────────────────────── -->
  <div class="dp-card">
    <form method="post">
      <?php if (function_exists('csrfField')) echo csrfField(); ?>
      <input type="hidden" name="dp_action" value="save">
      <div class="dp-form">
        <div class="dp-f">
          <label>Pay Now</label>
          <select name="dpo_enabled">
            <option value="1" <?= $dpReady['enabled'] ? 'selected' : '' ?>>Enabled</option>
            <option value="0" <?= $dpReady['enabled'] ? '' : 'selected' ?>>Disabled</option>
          </select>
          <div class="hint">Off hides Pay Now and refuses new attempts. Payments already
            at DPO still settle, and reconciliation keeps running.</div>
        </div>
        <div class="dp-f">
          <label>Environment</label>
          <select name="dpo_environment">
            <option value="test" <?= $dpReady['environment'] === 'test' ? 'selected' : '' ?>>Test</option>
            <option value="live" <?= $dpReady['environment'] === 'live' ? 'selected' : '' ?>>Live</option>
          </select>
          <div class="hint">This is the only thing separating a rehearsal from revenue.</div>
        </div>
        <div class="dp-f">
          <label>Company token</label>
          <input type="password" name="dpo_company_token" autocomplete="new-password"
                 placeholder="<?= $h((string)($dpConfig['dpo_company_token'] ?? '') !== ''
                                  ? 'configured — leave blank to keep' : 'not set') ?>">
          <div class="hint">Never displayed. Leave blank to keep the stored one.</div>
        </div>
        <div class="dp-f">
          <label>Service type</label>
          <input type="text" name="dpo_service_type"
                 value="<?= $h($dpConfig['dpo_service_type'] ?? '') ?>">
          <div class="hint">Issued by DPO for this merchant account.</div>
        </div>
        <div class="dp-f">
          <label>Company account ref</label>
          <input type="text" name="dpo_company_acc_ref"
                 value="<?= $h($dpConfig['dpo_company_acc_ref'] ?? '') ?>">
        </div>
        <div class="dp-f">
          <label>uCRM payment method</label>
          <input type="text" name="dpo_payment_method_uuid"
                 value="<?= $h($dpConfig['dpo_payment_method_uuid'] ?? '') ?>">
          <div class="hint">Get it with <code>tools/dpo_payment_method.php --store</code>.
            Unset means payments quarantine rather than book as Cash.</div>
        </div>
        <div class="dp-f">
          <label>Currencies accepted</label>
          <input type="text" name="dpo_currencies"
                 value="<?= $h($dpConfig['dpo_currencies'] ?? '') ?>" placeholder="UGX">
          <div class="hint">Comma separated. An invoice in any other currency is refused,
            never converted.</div>
        </div>
        <div class="dp-f">
          <label>Attempt time limit (minutes)</label>
          <input type="number" name="dpo_ptl" min="0" max="1440"
                 value="<?= (int)($dpConfig['dpo_ptl'] ?? 30) ?>">
          <div class="hint">Expires the attempt, never the invoice.</div>
        </div>
        <div class="dp-f">
          <label>Test customers (uCRM client ids)</label>
          <input type="text" name="dpo_test_clients"
                 value="<?= $h($dpConfig['dpo_test_clients'] ?? '') ?>" placeholder="e.g. 1234">
          <div class="hint">Test environment only: nobody else sees Pay Now or can pay,
            because DPO's test cards are public. Ignored when live.</div>
        </div>
        <div class="dp-f">
          <label>uCRM statuses that cannot be paid</label>
          <input type="text" name="dpo_unpayable_statuses"
                 value="<?= $h($dpConfig['dpo_unpayable_statuses'] ?? '') ?>" placeholder="e.g. 0, 5">
          <div class="hint">Comma separated. This plugin knows 4 = paid; the code for a
            VOID invoice is not established, so put it here once you can read it off a
            void invoice in uCRM.</div>
        </div>
      </div>
      <div style="margin-top:14px"><button class="dp-btn" type="submit">Save settings</button></div>
    </form>
  </div>

  <!-- ── URLs for DPO's portal ──────────────────────────────────────── -->
  <div class="dp-card">
    <div style="font-size:13px;font-weight:700;color:#0F172A;margin-bottom:4px">
      Give these to DPO</div>
    <div style="font-size:12px;color:#64748B;line-height:1.55">
      The return and back URLs are sent with every transaction. The push URL is
      <strong>not</strong> part of the API request — DPO must register it on the merchant
      account, so it has to be given to them.
    </div>
    <div style="margin-top:10px">
      <label style="font-size:11px;font-weight:700;color:#475569">Return URL</label>
      <div class="dp-url"><?= $h(DpoBootstrap::returnUrl($dpConfig)) ?></div>
      <label style="font-size:11px;font-weight:700;color:#475569;margin-top:9px;display:block">Back URL (cancel)</label>
      <div class="dp-url"><?= $h(DpoBootstrap::backUrl($dpConfig)) ?></div>
      <label style="font-size:11px;font-weight:700;color:#475569;margin-top:9px;display:block">Push / notify URL</label>
      <div class="dp-url"><?= $h(DpoBootstrap::pushUrl($dpConfig)) ?></div>
    </div>
  </div>

  <!-- ── The test link for DPO's reviewer ───────────────────────────── -->
  <div class="dp-card">
    <div style="font-size:13px;font-weight:700;color:#0F172A;margin-bottom:4px">
      Test link for DPO's review</div>
    <div style="font-size:12px;color:#64748B;line-height:1.55">
      DPO issues live credentials after their team pays through a test link. This one
      opens a page listing the test customers' unpaid invoices, each with a Pay button —
      no sign-in, because DPO cannot receive a customer's one-time code. It works only in
      the Test environment.
    </div>
    <?php $dpTestLink = DpoBootstrap::testLinkUrl($dpConfig); ?>
    <?php if ($dpReady['environment'] !== 'test'): ?>
      <div class="dp-note grey" style="margin-top:10px">The environment is Live, so the test
        link does not open.</div>
    <?php else: ?>
      <?php if ($dpReady['test_clients'] === []): ?>
        <div class="dp-note amber" style="margin-top:10px">No test customer yet. In the Test
          environment Pay Now is hidden from everyone until one is named in the settings
          above: a uCRM client made for testing, with an unpaid invoice.</div>
      <?php endif; ?>
      <?php if ($dpTestLink !== ''): ?>
        <label style="font-size:11px;font-weight:700;color:#475569;margin-top:10px;display:block">Send DPO this link</label>
        <div class="dp-url"><?= $h($dpTestLink) ?></div>
      <?php endif; ?>
      <form method="post" style="margin-top:10px">
        <?php if (function_exists('csrfField')) echo csrfField(); ?>
        <input type="hidden" name="dp_action" value="new_test_link">
        <button class="dp-btn" type="submit"><?= $dpTestLink === '' ? 'Make the test link' : 'Replace the test link' ?></button>
      </form>
    <?php endif; ?>
  </div>

  <!-- ── One payment in full ────────────────────────────────────────── -->
  <?php if ($dpOne !== null): ?>
  <div class="dp-card">
    <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
      <div>
        <div class="dp-mono" style="font-size:13px;font-weight:700;color:#0F172A"><?= $h($dpOne['reference']) ?></div>
        <div style="margin-top:6px">
          <span class="dp-pill <?= $dpPill((string)$dpOne['status']) ?>"><?= $h($dpOne['status']) ?></span>
          <span class="dp-pill grey"><?= $h($dpOne['environment']) ?></span>
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-size:20px;font-weight:800;color:#0F172A"><?= $money($dpOne['amount'], $dpOne['currency']) ?></div>
        <div style="font-size:11.5px;color:#64748B"><?= $h($dpOne['invoice_number']) ?></div>
      </div>
    </div>
    <?php if ((string)$dpOne['failure_reason'] !== ''): ?>
      <div class="dp-note <?= $h((string)$dpOne['status'] === 'QUARANTINED' ? 'red' : 'amber') ?>" style="margin-top:12px">
        <?= $h($dpOne['failure_reason']) ?>
      </div>
    <?php endif; ?>
    <div class="dp-tablewrap" style="margin-top:12px">
      <table class="dp" style="min-width:0">
        <tbody>
          <tr><th>Customer</th><td><?php if ($crmWeb !== ''): ?>
              <a href="<?= $h($crmWeb) ?>/client/<?= (int)$dpOne['crm_client_id'] ?>" target="_blank" rel="noopener">
                client #<?= (int)$dpOne['crm_client_id'] ?></a>
              <?php else: ?>client #<?= (int)$dpOne['crm_client_id'] ?><?php endif; ?></td></tr>
          <tr><th>DPO reference</th><td class="dp-mono"><?= $h($dpOne['dpo_trans_ref'] ?: '—') ?></td></tr>
          <tr><th>DPO result</th><td><?= $h(($dpOne['dpo_result'] ?: '—') . ' ' . ($dpOne['dpo_result_text'] ?: '')) ?></td></tr>
          <tr><th>Paid with</th><td><?= $h($dpOne['payment_method'] ?: '—') ?></td></tr>
          <tr><th>uCRM payment</th><td><?= $h($dpOne['crm_payment_id'] ? '#' . (int)$dpOne['crm_payment_id'] : '—') ?></td></tr>
          <tr><th>Created</th><td><?= $h($dpOne['created_at']) ?></td></tr>
          <tr><th>Last verified</th><td><?= $h($dpOne['verified_at'] ?: '—') ?></td></tr>
          <tr><th>Settled</th><td><?= $h($dpOne['settled_at'] ?: '—') ?></td></tr>
        </tbody>
      </table>
    </div>
    <div style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;
                letter-spacing:.4px;margin:16px 0 8px">What happened</div>
    <div class="dp-ev">
      <?php foreach ($dpEvents as $ev): ?>
        <div class="e"><b><?= $h($ev['event']) ?></b>
          <?php if ((string)$ev['detail'] !== ''): ?><span><?= $h($ev['detail']) ?></span><?php endif; ?>
          <i><?= $h($ev['created_at']) ?> · <?= $h($ev['actor']) ?></i></div>
      <?php endforeach; ?>
      <?php if ($dpEvents === []): ?><div class="e"><span>No events recorded.</span></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── The list ───────────────────────────────────────────────────── -->
  <form method="get" class="dp-filters">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab"  value="dpo_payments">
    <select name="dp_status">
      <option value="">Any status</option>
      <?php foreach (['SUCCESS','PENDING','REDIRECTED','CREATED','QUARANTINED','FAILED',
                      'CANCELLED','EXPIRED','REFUNDED'] as $s): ?>
        <option value="<?= $s ?>" <?= ($_GET['dp_status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <select name="dp_env">
      <option value="">Any environment</option>
      <option value="live" <?= ($_GET['dp_env'] ?? '') === 'live' ? 'selected' : '' ?>>Live</option>
      <option value="test" <?= ($_GET['dp_env'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
    </select>
    <input type="text"   name="dp_ref"     placeholder="Reference" value="<?= $h($_GET['dp_ref'] ?? '') ?>">
    <input type="number" name="dp_client"  placeholder="Client id" value="<?= $h($_GET['dp_client'] ?? '') ?>">
    <input type="number" name="dp_invoice" placeholder="Invoice id" value="<?= $h($_GET['dp_invoice'] ?? '') ?>">
    <input type="date"   name="dp_from"    value="<?= $h($_GET['dp_from'] ?? '') ?>">
    <input type="date"   name="dp_to"      value="<?= $h($_GET['dp_to'] ?? '') ?>">
    <button class="dp-btn" type="submit">Filter</button>
  </form>

  <div class="dp-tablewrap">
    <table class="dp">
      <thead><tr><th>Reference</th><th>Status</th><th>Invoice</th><th>Client</th>
        <th>Amount</th><th>Env</th><th>Paid with</th><th>uCRM</th><th>Created</th></tr></thead>
      <tbody>
      <?php foreach ($dpRows as $r): ?>
        <tr>
          <td class="dp-mono"><a href="?page=dashboard&tab=dpo_payments&dp_open=<?= urlencode((string)$r['reference']) ?>"><?= $h($r['reference']) ?></a></td>
          <td><span class="dp-pill <?= $dpPill((string)$r['status']) ?>"><?= $h($r['status']) ?></span></td>
          <td><?= $h($r['invoice_number']) ?></td>
          <td><?= (int)$r['crm_client_id'] ?></td>
          <td><?= $money($r['amount'], $r['currency']) ?></td>
          <td><?= $h($r['environment']) ?></td>
          <td><?= $h($r['payment_method'] ?: '—') ?></td>
          <td><?= $h($r['crm_payment_id'] ? '#' . (int)$r['crm_payment_id'] : '—') ?></td>
          <td class="dp-mono"><?= $h($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($dpRows === []): ?>
        <tr><td colspan="9" style="padding:22px;text-align:center;color:#94A3B8">
          No DPO payments yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="dp-note grey" style="margin-top:14px">
    <strong>Reconciling against DPO.</strong> Every row here is one attempt to collect.
    The customer pays the invoice amount exactly — DishNet absorbs DPO's fee, so DPO's
    settlement to the bank will be lower than the sum of these rows. Match on the
    <em>reference</em>, which is sent to DPO as the CompanyRef and written into the uCRM
    payment note.
  </div>
</div>
