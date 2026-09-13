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
require_once dirname(__DIR__, 2) . '/lib/StoreInterface.php';
require_once dirname(__DIR__, 2) . '/lib/JsonStore.php';
require_once dirname(__DIR__, 2) . '/lib/SqliteStore.php';
require_once dirname(__DIR__, 2) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__, 2) . '/lib/StarlinkUsage.php';
require_once dirname(__DIR__, 2) . '/lib/KitSlMap.php';

/**
 * Collect usage NOW, for whichever account is selected.
 *
 * ── WHY THIS STILL RUNS AT IMPORT ───────────────────────────────────────
 *
 * This note used to say a Starlink token "lasts minutes, and using it does not
 * extend it", from one reading here: imported 07:58:08, last accepted
 * 08:05:03, expired. That reading was wrong twice over. The span is import to
 * last SUCCESSFUL call, not a token lifetime; and the collector was fetching
 * through raw(), which threw away the rotated cookie every response carries —
 * so it was discarding the very thing that keeps a session alive and then
 * reporting that the session had died.
 *
 * The working installation settles it. dishnet-data-report, South Sudan, one
 * pasted cookie: "Cookie fresh (0.4h old) — 51 accounts · auto-refreshed
 * 3058×", syncing every two hours with nobody re-pasting. A session survives
 * indefinitely as long as every call persists the rotation it is handed.
 *
 * Collecting at import stays, but for a smaller reason: it is the one moment
 * the session is certainly alive, so a paste gives an immediate answer instead
 * of a wait for the next tick.
 */
function ssCollectNow(StarlinkSessionStore $store, array $config, string $dataDir): array
{
    try {
        $ea   = EquipmentAssignment::fromStore(SqliteStore::create($dataDir));
        $live = $ea->liveAssignments();
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'could not read assignments: ' . $e->getMessage()];
    }
    if ($live === []) return ['ok' => false, 'msg' => 'nothing is bound to a customer yet'];

    // Same gap-fill the cron applies, so this button and the schedule collect
    // the same set rather than two sets that merely look alike.
    $map = new KitSlMap($dataDir);
    if ($map->count() > 0) $live = $map->apply($live, new StarlinkServiceState())['assignments'];

    $u   = new StarlinkUsage($store, $config);
    $res = $u->collect($live);
    if ($res['rows'] === []) {
        $why = [];
        foreach ($res['report'] as $r) $why[] = $r['kit'] . ': ' . $r['why'];
        return ['ok' => false, 'msg' => 'no usage collected — ' . implode('; ', array_slice($why, 0, 3))];
    }
    $w = $u->save($dataDir, $res['rows']);
    return !empty($w['ok'])
        ? ['ok' => true, 'msg' => $w['written'] . ' usage row(s) collected and saved. '
                                . 'The Fleet screen reads them now.']
        : ['ok' => false, 'msg' => (string)$w['why']];
}

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
        if (empty($v['ok'])) {
            $ssFlash = ['warn', 'Imported ' . count($r['names']) . ' cookie(s) for ' . $r['account']
                     . ', but Starlink did NOT accept it: ' . (string)($v['error'] ?? 'unknown')
                     . '. Sign in again on starlink.com and copy the header fresh.'];
        } else {
            // Collect immediately, while the session is certainly alive. It has
            // minutes, not hours, and this is the only moment we can be sure of.
            $c = ssCollectNow($ssStore, $ssConfig, $ssDataDir);
            $ssFlash = [$c['ok'] ? 'good' : 'warn',
                'Imported ' . count($r['names']) . ' cookie(s) for ' . $r['account']
                . ' — Starlink accepted it. ' . $r['accounts_held'] . ' account(s) held. '
                . $c['msg']];
        }
    }
    $_POST['ss_cookie'] = '';   // never round-trips into the form
}

// ── Switch / forget ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ss_action'] ?? '') === 'collect' && csrfCheck()) {
    $c = ssCollectNow($ssStore, $ssConfig, $ssDataDir);
    $ssFlash = [$c['ok'] ? 'good' : 'warn', $c['msg']];
}

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

// ── KIT → service line map ──────────────────────────────────────────────
$ssMap = new KitSlMap($ssDataDir);
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ss_action'] ?? '') === 'map' && csrfCheck()) {
    $r = $ssMap->replace((string)($_POST['ss_map'] ?? ''));
    $bits = [];
    if (!empty($r['ok'])) $bits[] = $r['stored'] . ' pair(s) saved';
    else                  $bits[] = $r['why'];
    if ($r['errors'] !== []) {
        $bits[] = count($r['errors']) . ' line(s) not stored: '
                . implode(' · ', array_slice($r['errors'], 0, 4))
                . (count($r['errors']) > 4 ? ' …' : '');
    }
    $ssFlash = [!empty($r['ok']) ? ($r['errors'] === [] ? 'good' : 'warn') : 'bad',
                implode('. ', $bits)];
    $ssMap = new KitSlMap($ssDataDir);
}

// What the map would actually do to the bindings we hold, shown rather than
// promised — a map is only worth what it fills in.
$ssGap = ['filled_lines' => 0, 'filled_accounts' => 0, 'disagreements' => [], 'unused' => []];
$ssLiveCount = null;
try {
    $ssLive = EquipmentAssignment::fromStore(SqliteStore::create($ssDataDir))->liveAssignments();
    $ssLiveCount = count($ssLive);
    if ($ssMap->count() > 0) $ssGap = $ssMap->apply($ssLive, new StarlinkServiceState());
} catch (\Throwable $e) { /* the map card simply shows no preview */ }

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
        <button class="ss-btn" type="submit">Import &amp; collect usage</button>
        <span class="ss-id">Stored encrypted. Never displayed again, here or anywhere.</span>
      </div>
    </form>
    <div class="ss-note ss-amber" style="margin:14px 0 0;">
      <strong>One paste should be enough.</strong> A session stays alive by being used and
      having the rotated cookie written back each time — South Sudan's data plugin holds one
      pasted cookie across 51 accounts and reports it <em>auto-refreshed 3,058&times;</em>,
      syncing every two hours with nobody re-pasting. Our keep-alive now does the same every
      two minutes, and retries an account it had written off once an hour.
      <br><br>
      Importing still collects immediately, because that is the one moment the session is
      certainly warm. If <b>last accepted</b> below stops moving, the session really has
      gone and wants a fresh paste.
    </div>
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
      <form method="post" style="margin-top:12px;">
        <?= csrfField() ?><input type="hidden" name="ss_action" value="collect">
        <button class="ss-btn" type="submit">Collect usage now</button>
        <span class="ss-id" style="margin-left:10px;">Only works while a session is still
          warm — within a few minutes of a paste.</span>
      </form>
    <?php endif; ?>
  </div>

  <div class="ss-card">
    <h3>KIT &rarr; service line map</h3>
    <div class="ss-sub" style="margin-bottom:12px;">
      Starlink's own listing does not reliably carry a kit serial — every service line in
      our cache came back without one, and a Mini never has one. This is where that pairing
      is typed in. It is not a workaround: in South Sudan <b>295 of 367</b> service lines are
      reachable only through the typed map.
      <br><br>
      One per line, <code>KIT…=SL…</code>. Blank lines and <code>#</code> comments are fine.
      Saving <b>replaces</b> the whole map with what is in the box, so removing a line here
      removes it for good.
    </div>
    <form method="post" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="ss_action" value="map">
      <textarea class="ss-paste" name="ss_map" spellcheck="false"
                placeholder="KIT404246364BX6=SL-DF-16046613-35504-0"><?= $h($ssMap->asText()) ?></textarea>
      <div style="margin-top:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <button class="ss-btn" type="submit">Save map</button>
        <span class="ss-id">
          <?php if ($ssMap->count() === 0): ?>
            Empty. Export what we already hold with <code>php tools/dr_kit_map.php --paste</code>.
          <?php else: ?>
            <?= (int)$ssMap->count() ?> pair(s)<?= $ssMap->savedAt() !== '' ? ', saved ' . $h($ssMap->savedAt()) . ' UTC' : '' ?>.
          <?php endif; ?>
        </span>
      </div>
    </form>

    <?php if ($ssMap->count() > 0 && $ssLiveCount !== null): ?>
      <div class="ss-note <?= $ssGap['disagreements'] !== [] ? 'ss-amber' : 'ss-grey2' ?>"
           style="margin:14px 0 0;">
        <strong>Against the <?= (int)$ssLiveCount ?> live binding(s) we hold:</strong>
        fills in <?= (int)$ssGap['filled_lines'] ?> service line(s)
        and <?= (int)$ssGap['filled_accounts'] ?> account(s).
        <?php foreach ($ssGap['disagreements'] as $d): ?>
          <br><br><strong>⚠ <?= $h($d['kit']) ?></strong> — the map says
          <code><?= $h($d['map']) ?></code>, the install record says
          <code><?= $h($d['assignment']) ?></code>. The install record is used. One of the
          two is wrong and it is worth knowing which, because the usage follows it.
        <?php endforeach; ?>
        <?php if ($ssGap['unused'] !== []): ?>
          <br><br><?= count($ssGap['unused']) ?> mapped kit(s) no live binding claims:
          <code><?= $h(implode(', ', array_slice($ssGap['unused'], 0, 8))) ?></code><?=
            count($ssGap['unused']) > 8 ? ' …' : '' ?>.
          Normal for stock or released kits — and exactly what a mistyped serial looks like.
        <?php endif; ?>
      </div>
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
