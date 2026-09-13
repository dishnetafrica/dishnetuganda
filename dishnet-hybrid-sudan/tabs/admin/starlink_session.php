<?php
/**
 * starlink_session.php — import and watch the Starlink sessions, from a browser.
 *
 * The CLI tool refuses anything but a terminal, for a good reason: a cookie
 * passed as a shell argument lives on in history and is visible to anyone
 * running ps. That reason does not apply to a form field, and pasting 5,239
 * characters through `docker exec -it` has already produced one truncated
 * paste and a "that does not look like a cookie string".
 *
 * So this does the same import, with the same validation and the same store,
 * somewhere a paste actually works.
 *
 * What it does NOT do, deliberately:
 *   · accept the cookie on the URL — POST only, so it cannot reach a web
 *     server access log, a proxy log, or the browser's own history
 *   · print the cookie back, ever, in any state — only its names and lengths
 *   · work for anyone but an administrator
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/bootstrap_data.php';
require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkSessionStore.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkPortalConnector.php';

$ssRoot    = dirname(__DIR__, 2);
$ssDataDir = getDataDir($ssRoot);
$ssConfig  = PluginConfig::load($ssRoot, $ssDataDir);
$ssStore   = new StarlinkSessionStore($ssRoot, $ssDataDir);

$ssFlash = null;

// ── Import ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ss_action'] ?? '') === 'import' && csrfCheck()) {

    $raw = (string)($_POST['ss_cookie'] ?? '');
    // Browsers wrap a long paste. Newlines are not part of a cookie header and
    // a jar that kept them would be rejected by Starlink with no clue why.
    $raw = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $raw) ?? '');

    $r = $ssStore->importCookie($raw, 'browser: ' . (string)($retailer['name'] ?? 'admin'));
    if (empty($r['ok'])) {
        $ssFlash = ['bad', (string)$r['error']];
    } else {
        // Prove it before calling it good — an accepted paste that Starlink
        // rejects is worse than a rejected paste, because nothing looks wrong.
        $v = (new StarlinkPortalConnector($ssStore, $ssConfig))->verify();
        $ssFlash = !empty($v['ok'])
            ? ['good', 'Imported ' . count($r['names']) . ' cookie(s) for '
                     . $r['account'] . ' — and Starlink accepted it. '
                     . $r['accounts_held'] . ' account(s) held.']
            : ['warn', 'Imported ' . count($r['names']) . ' cookie(s) for ' . $r['account']
                     . ', but Starlink did NOT accept it: ' . (string)($v['error'] ?? 'unknown')
                     . '. Sign in again on starlink.com and copy the header fresh.'];
    }
    $_POST['ss_cookie'] = '';   // never round-trips into the form
}

// ── Switch / forget ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ss_action'] ?? '') === 'use' && csrfCheck()) {
    $a = (string)($_POST['ss_account'] ?? '');
    $ssFlash = $ssStore->useAccount($a)
        ? ['good', 'Now using ' . strtoupper($a) . '.']
        : ['bad', 'No session held for ' . htmlspecialchars(strtoupper($a)) . '.'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ss_action'] ?? '') === 'forget' && csrfCheck()) {
    $a = (string)($_POST['ss_account'] ?? '');
    $ssFlash = $ssStore->forgetAccount($a)
        ? ['good', 'Dropped ' . strtoupper($a) . '. Sign out of starlink.com too if that '
                 . 'session should be revoked.']
        : ['bad', 'No session held for ' . htmlspecialchars(strtoupper($a)) . '.'];
}

$h      = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$active = $ssStore->active();
$held   = $ssStore->accounts();

$ago = static function (string $ts): string {
    if ($ts === '') return '—';
    $s = time() - (int)strtotime($ts . ' UTC');
    if ($s < 0)    return $ts;
    if ($s < 90)   return $s . 's ago';
    if ($s < 5400) return round($s / 60) . 'm ago';
    if ($s < 172800) return round($s / 3600, 1) . 'h ago';
    return round($s / 86400) . 'd ago';
};
$stateClass = ['active' => 'ss-ok', 'stale' => 'ss-warn', 'expired' => 'ss-warn',
               'dead' => 'ss-bad', 'absent' => 'ss-grey'];
?>
<style>
.ss-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:960px;}
.ss-wrap h2{margin:0 0 3px;font-size:19px;font-weight:800;color:#0F172A;}
.ss-sub{font-size:12px;color:#64748B;margin-bottom:16px;}
.ss-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-bottom:16px;}
.ss-card h3{margin:0 0 10px;font-size:13px;font-weight:800;color:#0F172A;text-transform:uppercase;letter-spacing:.4px;}
table.ss{width:100%;border-collapse:collapse;font-size:12.5px;}
table.ss th{text-align:left;padding:7px 10px;font-size:10px;font-weight:700;color:#475569;
  text-transform:uppercase;letter-spacing:.4px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;}
table.ss td{padding:9px 10px;border-bottom:1px solid #F1F5F9;vertical-align:middle;}
.ss-acct{font-family:'Courier New',monospace;font-weight:700;color:#1E293B;}
.ss-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;
  text-transform:uppercase;letter-spacing:.3px;}
.ss-ok{background:#DCFCE7;color:#166534;} .ss-warn{background:#FEF3C7;color:#92400E;}
.ss-bad{background:#FEE2E2;color:#991B1B;} .ss-grey{background:#F1F5F9;color:#64748B;}
.ss-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.55;margin-bottom:14px;}
.ss-good{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;}
.ss-amber{background:#FFFBEB;border:1px solid #FDE68A;color:#78350F;}
.ss-red{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
.ss-grey2{background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;}
textarea.ss-paste{width:100%;min-height:110px;font-family:'Courier New',monospace;font-size:11px;
  border:1px solid #CBD5E1;border-radius:8px;padding:10px;resize:vertical;}
.ss-btn{background:#2563EB;color:#fff;border:0;border-radius:8px;padding:8px 16px;
  font-size:12.5px;font-weight:700;cursor:pointer;}
.ss-btn.small{background:#E2E8F0;color:#0F172A;padding:4px 10px;font-size:11px;font-weight:700;}
.ss-btn.danger{background:#FEE2E2;color:#991B1B;}
.ss-id{font-size:10.5px;color:#94A3B8;}
</style>

<div class="ss-wrap">
  <h2>Starlink Sessions</h2>
  <div class="ss-sub">One session per Starlink account. Importing adds an account — it does not
    replace the last one.</div>

  <?php if ($ssFlash): ?>
    <div class="ss-note <?= $ssFlash[0] === 'good' ? 'ss-good' : ($ssFlash[0] === 'warn' ? 'ss-amber' : 'ss-red') ?>">
      <?= $h($ssFlash[1]) ?>
    </div>
  <?php endif; ?>

  <div class="ss-card">
    <h3>Import a cookie</h3>
    <div class="ss-sub" style="margin-bottom:12px;">
      On <b>starlink.com</b>, signed in as the account you want: developer tools →
      <b>Network</b> → click any request → under <b>Request Headers</b>, copy the whole
      value of the <code>cookie:</code> header, and paste it here.
      The cookie names its own account, so there is nothing to choose.
    </div>
    <form method="post" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="ss_action" value="import">
      <textarea class="ss-paste" name="ss_cookie" spellcheck="false"
                placeholder="cookie: _ga=…; Starlink.Com.Sso=…; starlink.com.account_number=ACC-DF-…"></textarea>
      <div style="margin-top:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <button class="ss-btn" type="submit">Import</button>
        <span class="ss-id">Stored encrypted. Never displayed again, here or anywhere.</span>
      </div>
    </form>
  </div>

  <div class="ss-card">
    <h3>Accounts held</h3>
    <?php if ($held === []): ?>
      <div class="ss-note ss-grey2" style="margin:0;">
        No session imported yet. Usage cannot be collected for any account until one is.
      </div>
    <?php else: ?>
      <table class="ss">
        <thead><tr><th></th><th>Account</th><th>State</th><th>Last accepted</th><th>Imported</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($held as $acct):
          if (!$ssStore->useAccount($acct)) continue;
          $r = $ssStore->load();
          $st = (string)$r['state'];
        ?>
          <tr>
            <td style="width:18px;"><?= $acct === $active ? '▸' : '' ?></td>
            <td>
              <div class="ss-acct"><?= $h($acct) ?></div>
              <?php if (($r['account_email'] ?? '') !== ''): ?>
                <div class="ss-id"><?= $h($r['account_email']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="ss-pill <?= $stateClass[$st] ?? 'ss-grey' ?>"><?= $h($st) ?></span>
              <?php if (($r['last_error'] ?? '') !== ''): ?>
                <div class="ss-id"><?= $h(mb_substr((string)$r['last_error'], 0, 90)) ?></div>
              <?php endif; ?>
            </td>
            <td><?= $h($ago((string)$r['last_ok_at'])) ?></td>
            <td><?= $h($ago((string)$r['imported_at'])) ?>
              <?php if (($r['imported_by'] ?? '') !== ''): ?>
                <div class="ss-id">by <?= $h($r['imported_by']) ?></div>
              <?php endif; ?>
            </td>
            <td style="text-align:right;white-space:nowrap;">
              <?php if ($acct !== $active): ?>
                <form method="post" style="display:inline;">
                  <?= csrfField() ?><input type="hidden" name="ss_action" value="use">
                  <input type="hidden" name="ss_account" value="<?= $h($acct) ?>">
                  <button class="ss-btn small" type="submit">Use</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Drop the stored session for <?= $h($acct) ?>?');">
                <?= csrfField() ?><input type="hidden" name="ss_action" value="forget">
                <input type="hidden" name="ss_account" value="<?= $h($acct) ?>">
                <button class="ss-btn small danger" type="submit">Forget</button>
              </form>
            </td>
          </tr>
        <?php endforeach; $ssStore->useAccount($active); ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="ss-note ss-grey2">
    <strong>Why one per account.</strong> A single cookie can <em>list</em> every account the
    login reaches, but Starlink authorises usage separately: with a session on one account,
    the identical usage call for another answered 404 seconds later. So each account needs its
    own cookie here, and <code>cron/starlink_usage.php</code> collects for the accounts it has one for.
    <br><br>
    <strong>Signing out revokes these.</strong> An imported cookie is not a copy of the browser
    session — it is that session. Closing the tab is fine; signing out of starlink.com kills it
    here too. If you are replacing an old cookie, sign out first, sign back in, then import.
  </div>
</div>
