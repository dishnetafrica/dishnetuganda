<?php
declare(strict_types=1);
/**
 * Starlink Orders — customer email identities + the locked order card.
 *
 * The card shows exactly what staff type into starlink.com's checkout:
 * the customer's DishNet email identity and their real phone. It stays
 * LOCKED until the mailbox is provisioned, so an order can never be placed
 * with an address that does not exist yet.
 *
 * Password reset shows the new password ONCE and stores nothing — relay it
 * to the customer over WhatsApp.
 */
if (!isset($store, $config)) { echo '<p>Context missing.</p>'; return; }

require_once dirname(__DIR__, 2) . '/lib/MailProviderInterface.php';
require_once dirname(__DIR__, 2) . '/lib/StalwartProvider.php';
require_once dirname(__DIR__, 2) . '/lib/CustomerIdentityService.php';

$pdo   = $store->getPdo();
$idSvc = new CustomerIdentityService($pdo, $config, new StalwartProvider($config));

$flash = null; $oneTimePassword = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('csrfCheck') && csrfCheck()) {
    $cid = (int)($_POST['client_id'] ?? 0);
    if (($_POST['id_action'] ?? '') === 'reset_password' && $cid) {
        $r = $idSvc->resetPasswordForClient($cid);
        if ($r['ok']) { $oneTimePassword = $r['data']; $flash = ['ok', 'Password reset — shown once below. Send it to the customer on WhatsApp; it is not stored anywhere.']; }
        else $flash = ['err', 'Reset failed: ' . htmlspecialchars($r['error'])];
    }
    if (($_POST['id_action'] ?? '') === 'retry' && $cid) {
        $pdo->prepare("UPDATE customer_identities SET attempts=0, last_error=NULL,
                              updated_at=datetime('now','-1 hour')
                        WHERE client_id=? AND pending_action IS NOT NULL")->execute([$cid]);
        $flash = ['ok', "Retry queued for client #{$cid} — the identity worker picks it up within a minute."];
    }
    if (($_POST['id_action'] ?? '') === 'reactivate' && $cid) {
        $r = $idSvc->requestReactivate($cid);
        $flash = $r['ok'] ? ['ok', 'Reactivation queued.'] : ['err', htmlspecialchars($r['error'])];
    }
}

$identities = $idSvc->listRecent(200);
$clientsIdx = [];
foreach (($store->load('ucrm_clients_cache.json') ?? []) as $c) {
    $clientsIdx[(int)($c['id'] ?? 0)] = $c;
}
$hasEvents = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='starlink_events'")->fetchColumn();
$events = $hasEvents
    ? $pdo->query("SELECT * FROM starlink_events ORDER BY created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$chip = function (string $s): string {
    $c = ['provisioned' => '#177245', 'pending' => '#a05a00', 'failed' => '#b3261e',
          'suspended' => '#5d6570', 'disabled' => '#5d6570'][$s] ?? '#5d6570';
    return "<span style=\"background:{$c};color:#fff;border-radius:10px;padding:2px 9px;font-size:11px\">" . htmlspecialchars($s) . '</span>';
};
?>
<div style="max-width:1100px">
  <h2 style="margin:6px 0 2px">Starlink Orders — DishNet identities</h2>
  <p style="color:#666;margin:0 0 14px">
    Every new uCRM client is queued for a permanent <b>@<?= htmlspecialchars($idSvc->domain()) ?></b> mailbox.
    Place Starlink orders with the values on the card — never with a personal email.
    <?php if (empty($config['identity_enabled'])): ?>
      <br><b style="color:#b3261e">identity_enabled is OFF</b> — reservations are not being made. Enable it in kyc_config.json once the mail server is live.
    <?php endif; ?>
  </p>

  <?php if ($flash): ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;color:#fff;background:<?= $flash[0] === 'ok' ? '#177245' : '#b3261e' ?>">
      <?= $flash[1] ?>
      <?php if ($oneTimePassword): ?>
        <div style="margin-top:6px;font-size:18px;font-family:monospace;background:#00000033;padding:6px 10px;border-radius:6px;display:inline-block">
          <?= htmlspecialchars($oneTimePassword) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <tr style="text-align:left;border-bottom:2px solid #ddd">
      <th style="padding:6px">Client</th><th>DishNet email</th><th>Status</th>
      <th>Queue</th><th>Order card</th><th>Actions</th>
    </tr>
    <?php foreach ($identities as $row):
        $cid    = (int)$row['client_id'];
        $client = $clientsIdx[$cid] ?? [];
        $name   = htmlspecialchars((string)($client['name'] ?? ('Client #' . $cid)));
        $phone  = htmlspecialchars((string)($client['phone'] ?? ''));
        $addr   = htmlspecialchars(trim((string)($client['street1'] ?? '') . ' ' . (string)($client['city'] ?? '')));
        $ready  = $row['status'] === 'provisioned';
    ?>
    <tr style="border-bottom:1px solid #eee;vertical-align:top">
      <td style="padding:6px"><b><?= $name ?></b><br><span style="color:#888">#<?= $cid ?></span></td>
      <td style="font-family:monospace"><?= htmlspecialchars($row['email']) ?></td>
      <td><?= $chip((string)$row['status']) ?></td>
      <td style="color:#888">
        <?= $row['pending_action'] ? htmlspecialchars($row['pending_action']) . ' ×' . (int)$row['attempts'] : '—' ?>
        <?php if (!empty($row['last_error'])): ?><br><span style="color:#b3261e;font-size:11px"><?= htmlspecialchars(mb_substr($row['last_error'], 0, 80)) ?></span><?php endif; ?>
      </td>
      <td>
        <?php if ($ready): ?>
          <div style="border:1px solid #cde;border-radius:8px;padding:8px 10px;background:#f6faff">
            <div><b>Email:</b> <span style="font-family:monospace"><?= htmlspecialchars($row['email']) ?></span></div>
            <div><b>Phone:</b> <?= $phone !== '' ? $phone : '<i style="color:#b3261e">missing in uCRM</i>' ?></div>
            <?php if ($addr !== ''): ?><div><b>Address:</b> <?= $addr ?></div><?php endif; ?>
            <button type="button" style="margin-top:5px"
              onclick="navigator.clipboard.writeText('<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>')">Copy email</button>
          </div>
        <?php else: ?>
          <div style="border:1px dashed #ccc;border-radius:8px;padding:8px 10px;color:#888">
            🔒 Locked — do not order on Starlink until the mailbox is provisioned.
          </div>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if ($ready): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Reset the webmail password for <?= htmlspecialchars($row['email'], ENT_QUOTES) ?>?')">
          <?= function_exists('csrfField') ? csrfField() : '' ?>
          <input type="hidden" name="client_id" value="<?= $cid ?>">
          <input type="hidden" name="id_action" value="reset_password">
          <button>Reset password</button>
        </form>
        <?php elseif ($row['status'] === 'suspended'): ?>
        <form method="post" style="display:inline">
          <?= function_exists('csrfField') ? csrfField() : '' ?>
          <input type="hidden" name="client_id" value="<?= $cid ?>">
          <input type="hidden" name="id_action" value="reactivate">
          <button>Reactivate</button>
        </form>
        <?php elseif ($row['pending_action']): ?>
        <form method="post" style="display:inline">
          <?= function_exists('csrfField') ? csrfField() : '' ?>
          <input type="hidden" name="client_id" value="<?= $cid ?>">
          <input type="hidden" name="id_action" value="retry">
          <button>Retry now</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$identities): ?>
      <tr><td colspan="6" style="padding:14px;color:#888">No identities yet — they appear automatically when clients are created (identity_enabled must be ON).</td></tr>
    <?php endif; ?>
  </table>

  <h3 style="margin:26px 0 6px">Recent Starlink emails</h3>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <tr style="text-align:left;border-bottom:2px solid #ddd">
      <th style="padding:6px">When</th><th>Client</th><th>Type</th><th>Conf.</th><th>Subject</th><th>Outcome</th>
    </tr>
    <?php foreach ($events as $e): ?>
    <tr style="border-bottom:1px solid #eee">
      <td style="padding:6px;color:#888"><?= htmlspecialchars((string)$e['created_at']) ?></td>
      <td><?= $e['client_id'] ? '#' . (int)$e['client_id'] : '<b style="color:#b3261e">unmatched</b>' ?></td>
      <td><?= htmlspecialchars((string)$e['type']) ?><?= $e['action_required'] ? ' ⚠' : '' ?></td>
      <td><?= number_format((float)$e['confidence'], 2) ?></td>
      <td><?= htmlspecialchars(mb_substr((string)$e['subject'], 0, 60)) ?></td>
      <td><?= htmlspecialchars((string)$e['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$events): ?>
      <tr><td colspan="6" style="padding:14px;color:#888">Nothing processed yet<?= $hasEvents ? '' : ' (migration 063 has not run)' ?>.</td></tr>
    <?php endif; ?>
  </table>
</div>
