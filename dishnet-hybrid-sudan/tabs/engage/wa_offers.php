<?php
/**
 * wa_offers.php — WhatsApp offers on the Evolution numbers (5.18.104 P6, root docs/73 §H.4.7; contract C1; rulings R13,
 * R18, R19). Admins only, Uganda only, and only while offers_test_enabled is on (OfferSwitch::screen()); otherwise one
 * card and nothing read.
 *
 *   status    test or live; the live offer and its state; settings out of range; what the sender is waiting on
 *   compose   a draft: a title, two or three wordings (20–900 characters), the fixed STOP line beneath, an optional
 *             image, the audience size. Saving changes content_hash, so tests of the old content no longer count
 *   preview   (&offer=<id>&preview=1) each wording as it will go; the audience by handset; who is left out, by reason
 *   test      per wording, to the admin's own phone of record (never a typed number), with its ledger record
 *   start, pause, resume, stop, discard; the counts of every offer; today's offers per handset; the history
 *
 * Every string goes through h(); no phone number, customer name or customer's message appears anywhere. The forms post
 * to includes/post/post_offers.php, which checks the switches, the admin (re-read fresh) and the own-page origin itself.
 * PHP 7.4.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}
require_once dirname(__DIR__, 2) . '/lib/OfferSwitch.php';
require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
$_woRoot = dirname(__DIR__, 2);
$_woData = isset($dataDir) ? (string)$dataDir : '';
$_woCfg  = PluginConfig::read($_woRoot, $_woData);
if (!OfferSwitch::screen($_woCfg, $_woData)) {
    echo '<div class="wo-card" style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:18px;">Offers are not switched on here.</div>';
    return;
}
// The account, re-read fresh by id: the session copy is never trusted.
$_woMe = null;
try {
    $_woMe = (int)($retailer['id'] ?? 0) > 0 ? $store->findOne('retailers.json', 'id', (int)$retailer['id']) : null;
} catch (\Throwable $e) {
    $_woMe = null;
}
if (!is_array($_woMe) || empty($_woMe['is_active']) || empty($_woMe['is_admin'])) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

foreach (['UtcClock', 'OfferSchema', 'OfferText', 'OfferImage', 'OfferWindow', 'OfferGateway', 'EvolutionOfferGateway', 'OfferAudience',
          'OfferStore', 'OfferSender', 'WaSendBudget', 'ReminderDay'] as $_woC) {
    require_once $_woRoot . '/lib/' . $_woC . '.php';
}
$_woPdo  = $store->getPdo();
$_woNow  = time();
$_woH    = function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$_woSet  = OfferSwitch::settings($_woCfg);
$_woBad  = OfferSwitch::invalidSettings($_woCfg);
$_woLive = OfferSwitch::live($_woCfg, $_woData);
$_woLedgerOn = WaSendBudget::on($_woCfg, $_woData);
$_woSchema = OfferSchema::check($_woPdo);
$_woTz = new \DateTimeZone(OfferSwitch::TZ);
$_woAt = function (?string $utc) use ($_woTz): string {
    if ($utc === null || $utc === '') return '—';
    $t = UtcClock::parse($utc);
    return $t > 0 ? (new \DateTime('@' . $t))->setTimezone($_woTz)->format('D j M, H:i') : '—';
};
// The words a fixed code is shown in (§H.4.7); a code not named is shown as itself.
$_woSay = function (string $code): string {
    $w = ['reminders_pending' => 'waiting for today\'s payment reminders to go first', 'partf_missing' => 'a part this needs is missing',
          'schema_missing' => 'this install\'s offer tables are missing', 'zone' => 'the install\'s time zone is not Kampala\'s',
          'ledger_off' => 'the send ledger is off', 'offers_off' => 'offers-live is off'];
    return $w[$code] ?? $code;
};
$_woLabel = function (string $channel) use ($_woPdo): string {
    try {
        $q = $_woPdo->prepare("SELECT display_name FROM wa_channels WHERE channel_id = ?");
        $q->execute([$channel]);
        $n = $q->fetchColumn();
        $q->closeCursor();
        if (is_string($n) && trim($n) !== '') return trim($n);
    } catch (\Throwable $e) { /* no registry table: the department's name */ }
    return ucfirst($channel);
};

if ($_woSchema !== []) {
    echo '<div class="alert alert-danger">This install\'s offer tables are not complete (' . $_woH(implode(', ', $_woSchema)) . '). Nothing can be done here until migration 091 is applied.</div>';
    return;
}

$_woLiveOffer = OfferStore::live($_woPdo);
$_woSel = (int)($_GET['offer'] ?? 0);
$_woOffer = $_woSel > 0 ? OfferStore::find($_woPdo, $_woSel) : null;
$_woDraft = is_array($_woOffer) && (string)$_woOffer['state'] === 'draft' ? $_woOffer : null;
$_woPreview = $_woDraft !== null && !empty($_GET['preview']);
$_woStored = ['retailers' => (array)($store->load('retailers.json') ?? []), 'kyc' => (array)($store->load('kyc_config.json') ?? [])];

// What the sender is waiting on, as a fixed code (§H.4.7 status).
$_woWaiting = '';
if (!OfferSender::partF($_woPdo)) $_woWaiting = 'partf_missing';
elseif ($_woLive && $_woLiveOffer !== null && (string)$_woLiveOffer['state'] === 'running') {
    if (!$_woLedgerOn) $_woWaiting = 'ledger_off';
    elseif (OfferWindow::open($_woCfg, $_woNow) && !ReminderDay::done((array)($store->load('master_schedule.json') ?? []), $_woCfg, $_woData, $_woNow)) {
        $_woWaiting = 'reminders_pending';
    }
}

// The audience of the selected draft: the preview and the test's number list read it (store-free, §H.4.8).
$_woAud = null;
$_woAudErr = '';
if ($_woDraft !== null) {
    try {
        $_woAud = OfferAudience::build($_woPdo, $_woCfg, $_woData, $_woStored['retailers'], $_woStored['kyc'], (string)$_woDraft['audience_size'], $_woNow);
    } catch (\Throwable $e) {
        $_woAudErr = strpos($e->getMessage(), 'audience_unreadable:') === 0 ? $e->getMessage() : 'audience_unreadable';
    }
}
// The test's numbers: the handsets the audience uses, sales first; each a channel the admin's phone is sent from.
$_woTestChannels = [];
try {
    $_woGw = new EvolutionOfferGateway($_woPdo, $_woCfg, $_woData, OfferAudience::reader($_woPdo, $_woStored['retailers'], $_woStored['kyc']));
    $_woUsed = $_woAud !== null ? array_keys($_woAud['by_handset']) : [];
    foreach (['sales', 'support', 'account'] as $_woCh) {
        $_woHs = $_woGw->handsetFor($_woCh);
        if ($_woHs === '' || isset($_woTestChannels[$_woHs])) continue;
        if ($_woUsed !== [] && !in_array($_woHs, $_woUsed, true)) continue;
        $_woTestChannels[$_woHs] = $_woCh;
    }
} catch (\Throwable $e) { /* no Evolution configured: no number to test from */ }
?>
<style>
.wo{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:1000px;}
.wo *{box-sizing:border-box;}
.wo-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-bottom:14px;}
.wo-card h3{margin:0 0 10px;font-size:15px;font-weight:800;color:#0F172A;}
.wo-sub{font-size:12.5px;color:#64748B;line-height:1.5;}
.wo table{width:100%;border-collapse:collapse;font-size:13px;}
.wo th,.wo td{text-align:left;padding:6px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;}
.wo textarea,.wo input[type=text]{width:100%;padding:8px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;}
.wo .wo-stop{font-size:12.5px;color:#475569;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:8px;padding:6px 10px;}
.wo .wo-btn{display:inline-block;padding:7px 14px;border-radius:8px;border:1px solid #7C3AED;background:#7C3AED;color:#fff;font-weight:700;cursor:pointer;}
.wo .wo-btn.ghost{background:#fff;color:#7C3AED;}
.wo .wo-btn.danger{background:#fff;color:#B91C1C;border-color:#B91C1C;}
.wo .wo-msg{white-space:pre-wrap;background:#ECFDF5;border-radius:10px;padding:10px 12px;font-size:13.5px;margin:6px 0;}
.wo .wo-warn{color:#9A3412;font-weight:700;}
</style>
<div class="wo">
<h2 style="margin:0 0 6px;font-size:19px;font-weight:800;">WhatsApp offers</h2>
<p class="wo-sub" style="margin:0 0 14px;">One offer at a time, to customers who wrote to a DishNet number in the last <?= (int)($_woSet['offers_warm_days'] ?? OfferSwitch::SETTINGS['offers_warm_days'][0]) ?> days, from the number they wrote to. Quote reminders and payment reminders always go first. Nobody's number is shown here.</p>

<div class="wo-card" id="wo-status">
  <h3>Status</h3>
  <table>
    <tr><th>Mode</th><td><?= $_woLive ? 'Live (offers-live): a started offer is sent to customers' : 'Test only (offers-test): tests go to your own phone; nothing reaches a customer' ?></td></tr>
    <tr><th>Live offer</th><td><?= $_woLiveOffer === null ? 'none' : ('#' . (int)$_woLiveOffer['id'] . ' ' . $_woH($_woLiveOffer['title']) . ' — ' . $_woH($_woLiveOffer['state'])) ?></td></tr>
    <?php if ($_woBad !== []): ?>
    <tr><th>Settings</th><td class="wo-warn">Out of range, so nothing is sent: <?= $_woH(implode(', ', $_woBad)) ?></td></tr>
    <?php endif; ?>
    <?php if (!$_woLedgerOn): ?>
    <tr><th>Send ledger</th><td class="wo-warn">Off — no test and no offer can be sent.</td></tr>
    <?php endif; ?>
    <?php if ($_woWaiting !== ''): ?>
    <tr><th>The sender</th><td class="wo-warn"><?= $_woH($_woSay($_woWaiting)) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<?php
// ── the live offer: pause, resume, stop ─────────────────────────────────────────────────────────────────────────
if ($_woLiveOffer !== null):
    $_woLc = OfferStore::counts($_woPdo, (int)$_woLiveOffer['id']);
?>
<div class="wo-card" id="wo-live">
  <h3>Offer #<?= (int)$_woLiveOffer['id'] ?> — <?= $_woH($_woLiveOffer['title']) ?> (<?= $_woH($_woLiveOffer['state']) ?>)</h3>
  <p class="wo-sub">Started <?= $_woH($_woAt((string)$_woLiveOffer['started_at'])) ?> by <?= $_woH($_woLiveOffer['started_by']) ?>.
     Queued <?= (int)$_woLc['queued'] ?>, sent <?= (int)$_woLc['sent'] ?>, may have been sent <?= (int)$_woLc['maybe_sent'] ?>, failed <?= (int)$_woLc['failed'] ?>,
     skipped <?= (int)$_woLc['skipped'] ?>, replied within 72 h <?= (int)$_woLc['replied_72h'] ?>, STOP <?= (int)$_woLc['stop_72h'] ?>.</p>
  <?php if ((string)$_woLiveOffer['state'] === 'running'): ?>
  <form method="post" action="?page=dashboard&tab=wa_offers" style="display:inline;"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_pause"><input type="hidden" name="offer" value="<?= (int)$_woLiveOffer['id'] ?>">
    <button class="wo-btn ghost" type="submit">Pause</button></form>
  <?php elseif ($_woLive): ?>
  <form method="post" action="?page=dashboard&tab=wa_offers" style="display:inline;"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_resume"><input type="hidden" name="offer" value="<?= (int)$_woLiveOffer['id'] ?>">
    <button class="wo-btn" type="submit">Resume</button></form>
  <?php else: ?>
  <span class="wo-sub">Resume needs offers-live switched on.</span>
  <?php endif; ?>
  <form method="post" action="?page=dashboard&tab=wa_offers" style="display:inline;" onsubmit="return confirm('Stop this offer for good? The <?= (int)$_woLc['queued'] ?> still queued will not be sent.');"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_stop"><input type="hidden" name="offer" value="<?= (int)$_woLiveOffer['id'] ?>">
    <button class="wo-btn danger" type="submit">Stop</button></form>
</div>
<?php endif; ?>

<?php
// ── compose: a new draft, or the selected one ──────────────────────────────────────────────────────────────────
$_woF = $_woDraft ?? ['id' => 0, 'title' => '', 'wording_1' => '', 'wording_2' => '', 'wording_3' => '', 'audience_size' => '100', 'image_file' => null];
?>
<div class="wo-card" id="wo-compose">
  <h3><?= $_woDraft !== null ? 'Draft #' . (int)$_woDraft['id'] : 'A new offer' ?></h3>
  <form method="post" action="?page=dashboard&tab=wa_offers" enctype="multipart/form-data"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_save"><input type="hidden" name="offer" value="<?= (int)$_woF['id'] ?>">
    <label class="wo-sub">Title (for staff only, never sent)</label>
    <input type="text" name="title" maxlength="80" value="<?= $_woH($_woF['title']) ?>">
    <?php foreach ([1, 2, 3] as $_woV): ?>
    <label class="wo-sub" style="display:block;margin-top:10px;">Wording <?= $_woV ?><?= $_woV === 3 ? ' (optional)' : '' ?> — <span class="wo-count" data-for="wo-w<?= $_woV ?>">0</span> of 20–900 characters</label>
    <textarea id="wo-w<?= $_woV ?>" name="wording_<?= $_woV ?>" rows="3" maxlength="900"><?= $_woH($_woF['wording_' . $_woV] ?? '') ?></textarea>
    <?php endforeach; ?>
    <div class="wo-stop">Reply STOP to stop these messages. (added automatically)</div>
    <label class="wo-sub" style="display:block;margin-top:10px;">Image (optional: jpg, png or webp, at most 4 MB)<?= (string)($_woF['image_file'] ?? '') !== '' ? ' — one is attached' : '' ?></label>
    <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
    <?php if ((string)($_woF['image_file'] ?? '') !== ''): ?><label class="wo-sub"><input type="checkbox" name="remove_image" value="1"> remove the image</label><?php endif; ?>
    <label class="wo-sub" style="display:block;margin-top:10px;">Audience size</label>
    <select name="size"><?php foreach (array_keys(OfferAudience::SIZES) as $_woSz): ?><option value="<?= $_woH($_woSz) ?>"<?= (string)$_woF['audience_size'] === (string)$_woSz ? ' selected' : '' ?>><?= $_woH($_woSz) ?></option><?php endforeach; ?></select>
    <div style="margin-top:12px;"><button class="wo-btn" type="submit">Save the draft</button>
      <?php if ($_woDraft !== null): ?><span class="wo-sub">Saving changes the wording's fingerprint: earlier tests then no longer count.</span><?php endif; ?></div>
  </form>
  <?php if ($_woDraft !== null): ?>
  <form method="post" action="?page=dashboard&tab=wa_offers" style="margin-top:8px;" onsubmit="return confirm('Discard this draft? It is kept in the history.');"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_discard"><input type="hidden" name="offer" value="<?= (int)$_woDraft['id'] ?>">
    <button class="wo-btn danger" type="submit">Discard</button></form>
  <?php endif; ?>
</div>

<?php if ($_woDraft !== null):
    $_woWords = OfferStore::wordingsOf($_woDraft);
    $_woThumb = OfferImage::thumbnailDataUri($_woData, $_woDraft);
    $_woTests = [];
    $_woQ = $_woPdo->prepare('SELECT id, variant, content_hash, state, reason, echo, ledger, started_at FROM wa_offer_tests WHERE offer_id = ? ORDER BY id DESC LIMIT 30');
    $_woQ->execute([(int)$_woDraft['id']]);
    $_woTests = $_woQ->fetchAll(\PDO::FETCH_ASSOC);
    $_woTested = OfferStore::testedVariants($_woPdo, $_woDraft, $_woNow);
?>
<div class="wo-card" id="wo-test">
  <h3>Test each wording on your own phone</h3>
  <p class="wo-sub">A test goes to your account's phone, from the number you choose, never to anyone else. A wording counts as tested when its test was sent and recorded in the send ledger, on the content as it is now, within 7 days. Tested now: <?= (int)$_woTested ?> of <?= count($_woWords) ?>.</p>
  <?php if ($_woTestChannels === []): ?>
  <p class="wo-warn">No WhatsApp number is set up to send a test from.</p>
  <?php else: foreach ($_woWords as $_woV => $_woText): ?>
  <form method="post" action="?page=dashboard&tab=wa_offers&offer=<?= (int)$_woDraft['id'] ?>" style="margin:6px 0;"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_test"><input type="hidden" name="offer" value="<?= (int)$_woDraft['id'] ?>"><input type="hidden" name="variant" value="<?= (int)$_woV ?>">
    Wording <?= (int)$_woV ?>: from <select name="channel"><?php foreach ($_woTestChannels as $_woHs => $_woCh): ?><option value="<?= $_woH($_woCh) ?>"><?= $_woH($_woLabel($_woCh)) ?></option><?php endforeach; ?></select>
    <button class="wo-btn ghost" type="submit">Send to my phone</button>
  </form>
  <?php endforeach; endif; ?>
  <?php if ($_woTests !== []): ?>
  <table><tr><th>Test</th><th>Wording</th><th>For this content</th><th>State</th><th>Echo</th><th>Ledger</th><th>When</th></tr>
  <?php foreach ($_woTests as $_woT): ?>
    <tr><td>#<?= (int)$_woT['id'] ?></td><td><?= (int)$_woT['variant'] ?></td><td><?= (string)$_woT['content_hash'] === (string)$_woDraft['content_hash'] ? 'yes' : 'no (older content)' ?></td>
        <td><?= $_woH($_woT['state']) ?><?= (string)($_woT['reason'] ?? '') !== '' ? ' (' . $_woH($_woT['reason']) . ')' : '' ?></td>
        <td><?= $_woH($_woT['echo'] ?? '—') ?></td>
        <td><?= $_woT['ledger'] === null ? '—' : $_woH($_woT['ledger']) ?><?= (string)$_woT['state'] === 'sent' && (string)$_woT['ledger'] !== 'recorded' ? ' — <span class="wo-warn">not recorded: test this wording again</span>' : '' ?></td>
        <td><?= $_woH($_woAt((string)$_woT['started_at'])) ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
  <p><a href="?page=dashboard&tab=wa_offers&offer=<?= (int)$_woDraft['id'] ?>&preview=1">Preview the messages and the audience</a></p>
</div>

<?php if ($_woPreview): ?>
<div class="wo-card" id="wo-preview">
  <h3>Preview</h3>
  <?php if ($_woThumb !== ''): ?><img src="<?= $_woH($_woThumb) ?>" alt="the offer's image" style="max-width:220px;border-radius:10px;"><?php endif; ?>
  <?php foreach ($_woWords as $_woV => $_woText): ?>
  <div class="wo-sub">Wording <?= (int)$_woV ?>, exactly as it will go:</div>
  <div class="wo-msg"><?= $_woH(OfferText::render($_woText, (string)$_woDraft['stop_line'])) ?></div>
  <?php endforeach; ?>
  <?php if ($_woAudErr !== ''): ?>
  <p class="wo-warn">The audience could not be read (<?= $_woH($_woAudErr) ?>), so nobody would be sent this offer now.</p>
  <?php elseif ($_woAud !== null):
      $_woCeil = (int)($_woSet['offers_daily_max_per_handset'] ?? OfferSwitch::SETTINGS['offers_daily_max_per_handset'][0]);
      $_woHandChannels = [];
      foreach ($_woTestChannels as $_woHs => $_woCh) $_woHandChannels[$_woHs] = [];
      foreach (['sales', 'support', 'account'] as $_woCh) { $_woHs = isset($_woGw) ? $_woGw->handsetFor($_woCh) : ''; if ($_woHs !== '') $_woHandChannels[$_woHs][] = $_woLabel($_woCh); }
  ?>
  <table><tr><th>Number</th><th>Would be queued</th></tr>
  <?php foreach ($_woAud['by_handset'] as $_woHs => $_woN): ?>
    <tr><td><?= $_woH(implode(' / ', array_values(array_unique($_woHandChannels[$_woHs] ?? ['another number'])))) ?></td><td><?= (int)$_woN ?></td></tr>
  <?php endforeach; ?></table>
  <p>Eligible now: <?= (int)$_woAud['all_eligible'] ?>; queued at a start: <?= (int)$_woAud['queued'] ?> (size <?= $_woH($_woDraft['audience_size']) ?>).
     At least <?= $_woCeil > 0 ? (int)ceil(max(0, (int)max($_woAud['by_handset'] ?: [0])) / $_woCeil) : 0 ?> sending days per number. Quote reminders come first and offers start each day after the payment reminders, so it can take longer. Sundays do not count.</p>
  <?php if ($_woAud['left_out'] !== []): ?>
  <table><tr><th>Left out, by reason</th><th>People</th></tr>
  <?php foreach ($_woAud['left_out'] as $_woWhy => $_woN): ?><tr><td><?= $_woH($_woWhy) ?></td><td><?= (int)$_woN ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="wo-card" id="wo-start">
  <h3>Start</h3>
  <?php if (!$_woLive): ?>
  <p class="wo-sub">Start needs offers-live switched on.</p>
  <?php elseif ($_woLiveOffer !== null): ?>
  <p class="wo-sub">Another offer is live: stop it before starting this one.</p>
  <?php elseif ($_woTested !== count($_woWords)): ?>
  <p class="wo-sub">Test every wording first (<?= (int)$_woTested ?> of <?= count($_woWords) ?> tested on the content as it is now).</p>
  <?php else: ?>
  <form method="post" action="?page=dashboard&tab=wa_offers" onsubmit="return confirm('Start this offer now? <?= $_woAud !== null ? (int)$_woAud['queued'] : 0 ?> people would be queued as it stands.');"><?= csrfField() ?>
    <input type="hidden" name="action" value="offer_start"><input type="hidden" name="offer" value="<?= (int)$_woDraft['id'] ?>">
    <input type="hidden" name="content_hash" value="<?= $_woH($_woDraft['content_hash']) ?>">
    <button class="wo-btn" type="submit">Start the offer</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ── every offer, its counts; today's offers per handset; the history ─────────────────────────────────────────
$_woAll = $_woPdo->query('SELECT id, title, state, close_reason, created_at, started_at FROM wa_offers ORDER BY id DESC LIMIT 50')->fetchAll(\PDO::FETCH_ASSOC);
$_woToday = OfferStore::todayPerHandset($_woPdo, OfferWindow::dayStart($_woNow));
?>
<div class="wo-card" id="wo-offers">
  <h3>Offers</h3>
  <?php if ($_woAll === []): ?><p class="wo-sub">None yet.</p><?php else: ?>
  <table><tr><th>Offer</th><th>State</th><th>Queued</th><th>Sent</th><th>May have been sent</th><th>Failed</th><th>Skipped</th><th>Replied (72 h)</th><th>STOP (72 h)</th></tr>
  <?php foreach ($_woAll as $_woO): $_woK = OfferStore::counts($_woPdo, (int)$_woO['id']); ?>
    <tr><td><a href="?page=dashboard&tab=wa_offers&offer=<?= (int)$_woO['id'] ?>">#<?= (int)$_woO['id'] ?> <?= $_woH($_woO['title']) ?></a></td>
        <td><?= $_woH($_woO['state']) ?><?= (string)($_woO['close_reason'] ?? '') !== '' ? ' (' . $_woH($_woO['close_reason']) . ')' : '' ?></td>
        <td><?= (int)$_woK['queued'] ?></td><td><?= (int)$_woK['sent'] ?></td><td><?= (int)$_woK['maybe_sent'] ?></td><td><?= (int)$_woK['failed'] ?></td>
        <td><?= (int)$_woK['skipped'] ?></td><td><?= (int)$_woK['replied_72h'] ?></td><td><?= (int)$_woK['stop_72h'] ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
  <p class="wo-sub">Today's offers per number, against the ceiling of <?= $_woSet['offers_daily_max_per_handset'] === null ? 'an out-of-range setting' : (int)$_woSet['offers_daily_max_per_handset'] ?>:
  <?php if ($_woToday === []): ?>none.<?php else: foreach ($_woToday as $_woHs => $_woN): ?> <?= $_woH(substr((string)$_woHs, 2)) ?> <?= (int)$_woN ?>;<?php endforeach; endif; ?></p>
</div>

<?php if ($_woOffer !== null):
    $_woQ = $_woPdo->prepare('SELECT action, by_name, detail, at FROM wa_offer_events WHERE offer_id = ? ORDER BY id DESC LIMIT 100');
    $_woQ->execute([(int)$_woOffer['id']]);
    $_woEv = $_woQ->fetchAll(\PDO::FETCH_ASSOC);
?>
<div class="wo-card" id="wo-history">
  <h3>History of offer #<?= (int)$_woOffer['id'] ?></h3>
  <table><tr><th>When</th><th>What</th><th>Who</th><th>Detail</th></tr>
  <?php foreach ($_woEv as $_woE): ?><tr><td><?= $_woH($_woAt((string)$_woE['at'])) ?></td><td><?= $_woH($_woE['action']) ?></td><td><?= $_woH($_woE['by_name']) ?></td><td><?= $_woH($_woE['detail'] ?? '') ?></td></tr><?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
</div>
<script>
(function () {
  var c = document.querySelectorAll('.wo-count');
  for (var i = 0; i < c.length; i++) (function (el) {
    var t = document.getElementById(el.getAttribute('data-for'));
    if (!t) return;
    var f = function () { el.textContent = String(t.value.trim().length); };
    t.addEventListener('input', f); f();
  })(c[i]);
})();
</script>
