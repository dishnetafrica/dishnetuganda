<?php
// Tab: ucrm_catalogue — 5.18.103 (Uganda, root docs/72 §G.3): the uCRM catalogue screen.
//
// What the KYC form and Send Quotation offer from uCRM when the switch is on — the plan pairs, the hardware, the
// installation — what they do not and why, and the four settings: the switch, the installation product, the products
// kept off the forms and the standard quotations. Drawn only where the quoting-organization gate is on with an integer id
// (UcrmCatalogue::gateOpen); elsewhere one line says the screen does not apply, and nothing is read. The lists come from
// one read through UcrmQuoteLines::forDirectPage (the page timeouts, the 60-second cache, the last good copy when uCRM
// cannot be read); every save reads uCRM again (includes/post/post_sync.php, the ucrm_cat_* actions). Every uCRM string
// goes through h(); controls are drawn for admins only, and every action is re-checked on the server.
require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';
require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';
$_c3Cfg = is_array($config ?? null) ? $config : [];
$_c3Set = QuoteOrganization::setting($_c3Cfg, isset($dataDir) ? (string)$dataDir : null);
$_c3Cur = htmlspecialchars(trim((string)(($_c3Cfg['currency_symbol'] ?? '') ?: 'UGX'))) . ' ';
$_c3Admin = !empty($isAdmin);
$_c3Money = function ($v) use ($_c3Cur): string {
    $f = (float)$v;
    return $_c3Cur . number_format($f, abs($f - round($f)) < 0.0001 ? 0 : 2);
};
if (!UcrmCatalogue::gateOpen($_c3Set)) {
    echo '<div class="card" style="padding:20px;max-width:760px;"><h3 style="margin-top:0;">uCRM catalogue</h3>'
       . '<p>The uCRM catalogue applies only where quotations are made under the quoting organization (Uganda), and that is not in force here.</p></div>';
    return;
}
$_c3Cat = UcrmCatalogue::fromStore($store, $_c3Set);
$_c3Pg  = ['uql' => null, 'stale' => false];
$_c3Off = ['ok' => false, 'reason' => "uCRM's products could not be read", 'plans' => [], 'hardware' => [],
           'install' => ['ok' => false, 'reason' => "uCRM's products could not be read"], 'not_offered' => []];
$_c3All = [];   // every counted product, for the pickers: id => [name, price]
try {
    $_c3Pg  = UcrmQuoteLines::forDirectPage(isset($crm) ? $crm : null, $_c3Cfg, $_c3Set, (string)$dataDir);
    $_c3Off = $_c3Pg['uql']->offered($_c3Cat->installProductId(), $_c3Cat->hiddenIds());
    foreach ((array)($_c3Pg['uql']->catalogue()['products'] ?? []) as $_c3Row) {
        if (is_array($_c3Row) && (int)($_c3Row['id'] ?? 0) > 0) $_c3All[(int)$_c3Row['id']] = [(string)($_c3Row['name'] ?? ''), $_c3Row['price'] ?? null];
    }
} catch (\Throwable $e) {
    error_log('[ucrm_catalogue] 5.18.103: the uCRM catalogue could not be drawn (' . get_class($e) . ')');
}
$_c3Direct  = $_c3Cat->direct();
$_c3Mode    = $_c3Cat->modeRecord();
$_c3Inst    = $_c3Cat->installRecord();
$_c3Hidden  = $_c3Cat->hiddenRecords();
$_c3Stds    = $_c3Cat->standards();
$_c3Kits    = array_values(array_filter($_c3Off['hardware'], function ($h) { return $h['group'] === 'kit'; }));
$_c3Acc     = array_values(array_filter($_c3Off['hardware'], function ($h) { return $h['group'] !== 'kit'; }));
$_c3NotPlan = array_values(array_filter($_c3Off['not_offered'], function ($n) { return $n['kind'] === 'plan'; }));
$_c3NotProd = array_values(array_filter($_c3Off['not_offered'], function ($n) { return $n['kind'] === 'product' && $n['why'] !== 'kept_off'; }));
$_c3CanOn   = !empty($_c3Off['ok']) && empty($_c3Pg['stale']) && $_c3Off['plans'] !== [] && !empty($_c3Off['install']['ok']);
// The 5.18.102 web applications still waiting with P1 — quoted in uCRM by hand once the switch is on (G-10)
$_c3Waiting = [];
foreach ((array)$store->load('kyc_applications.json') as $_c3A) {
    if (is_array($_c3A) && ($_c3A['quote_sale_entry'] ?? null) === 'web' && ($_c3A['quote_refused'] ?? null) === 'P1'
        && empty($_c3A['quote_created']) && empty($_c3A['quote_found_in_ucrm'])) {
        $_c3Waiting[] = (int)($_c3A['id'] ?? 0);
    }
}
$_c3Who = function (?array $r): string {
    if ($r === null) return '';
    return ' — ' . h((string)($r['by_name'] ?? '')) . ', ' . h((string)($r['at'] ?? ''));
};
$_c3Form = function (string $action, array $fields, string $label, string $style, string $confirm = '') {
    $out = '<form method="POST" style="display:inline;margin:0;"' . ($confirm !== '' ? ' onsubmit="return confirm(this.dataset.q);" data-q="' . h($confirm) . '"' : '') . '>'
         . csrfField() . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v) $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    return $out . '<button type="submit" style="' . h($style) . '">' . h($label) . '</button></form>';
};
$_c3Btn  = 'background:#1565C0;color:#fff;border:none;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;';
$_c3Btn2 = 'background:#fff;color:#334155;border:1px solid #cbd5e1;border-radius:8px;padding:4px 10px;font-size:12px;cursor:pointer;';
$_c3Card = 'background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:14px;';
?>
<div style="max-width:1100px;margin:0 auto;padding:12px;">
  <h2 style="margin:0 0 4px;font-size:20px;">uCRM catalogue</h2>
  <p style="margin:0 0 14px;color:#64748b;font-size:13px;">The plans and hardware the KYC form and Send Quotation offer, straight from uCRM, at uCRM's prices. Prices are changed in uCRM; nothing here is linked.</p>

<?php if (empty($_c3Off['ok'])): ?>
  <div style="<?= $_c3Card ?>border-color:#fecaca;background:#fef2f2;color:#7f1d1d;">uCRM could not be read: <?= h((string)$_c3Off['reason']) ?>. Reload the screen to try again.</div>
<?php elseif (!empty($_c3Pg['stale'])): ?>
  <div style="<?= $_c3Card ?>border-color:#fed7aa;background:#fff7ed;color:#7c2d12;">uCRM could not be read just now — this is the last list read, and it may be out of date. The switch cannot be turned on until uCRM answers.</div>
<?php endif; ?>

  <div style="<?= $_c3Card ?>">
    <h3 style="margin:0 0 8px;font-size:16px;">The switch: <?= $_c3Direct ? '<span style="color:#166534;">ON</span>' : '<span style="color:#9a3412;">OFF</span>' ?></h3>
    <p style="margin:0 0 8px;font-size:13px;"><?= $_c3Direct
        ? 'The KYC form and Send Quotation offer the plans and hardware below. The Plans and Hardware screens are replaced by this one.'
        : 'Agents still see the plugin\'s own plans and hardware, as before. Turn it on once the lists below are right.' ?><?= $_c3Who($_c3Mode) ?></p>
<?php if (!$_c3Direct): ?>
    <ul style="margin:0 0 8px;padding-left:18px;font-size:13px;">
      <li><?= !empty($_c3Off['ok']) && empty($_c3Pg['stale']) ? '✅' : '❌' ?> uCRM can be read now</li>
      <li><?= $_c3Off['plans'] !== [] ? '✅' : '❌' ?> at least one plan is offered (<?= count($_c3Off['plans']) ?>)</li>
      <li><?= !empty($_c3Off['install']['ok']) ? '✅' : '❌' ?> the installation product is set and usable<?= !empty($_c3Off['install']['ok']) ? '' : ': ' . h((string)($_c3Off['install']['reason'] ?? '')) ?></li>
    </ul>
<?php if ($_c3Admin && $_c3CanOn): ?>
    <?= $_c3Form('ucrm_cat_mode', ['on' => '1'], 'Turn on', $_c3Btn, 'Turn the uCRM catalogue on? Agents will see uCRM\'s plans and hardware at once.') ?>
<?php elseif ($_c3Admin): ?>
    <button type="button" disabled style="<?= h($_c3Btn) ?>opacity:.5;cursor:not-allowed;">Turn on</button>
<?php endif; ?>
<?php if ($_c3Waiting !== []): ?>
    <p style="margin:10px 0 0;font-size:12px;color:#7c2d12;"><?= count($_c3Waiting) ?> registration(s) made before the switch are waiting with no quotation (application #<?= h(implode(', #', $_c3Waiting)) ?>). Once the switch is on, make their quotations in uCRM by hand.</p>
<?php endif; ?>
<?php elseif ($_c3Admin): ?>
    <?= $_c3Form('ucrm_cat_mode', ['on' => '0'], 'Turn off', $_c3Btn2, 'Turn the uCRM catalogue off? Agents will see the plugin\'s own plans and hardware again, with no quotation from them.') ?>
<?php endif; ?>
  </div>

  <div style="<?= $_c3Card ?>">
    <h3 style="margin:0 0 8px;font-size:16px;">Plans offered (<?= count($_c3Off['plans']) ?>)</h3>
<?php if ($_c3Off['plans'] === []): ?>
    <p style="font-size:13px;color:#64748b;">None.</p>
<?php else: ?>
    <table style="width:100%;font-size:13px;border-collapse:collapse;"><tr style="text-align:left;color:#64748b;"><th>Plan (uCRM product)</th><th>Speed</th><th>Monthly</th><th>uCRM ids</th></tr>
<?php foreach ($_c3Off['plans'] as $_c3P): ?>
      <tr style="border-top:1px solid #f1f5f9;"><td><?= h($_c3P['label']) ?></td><td><?= h($_c3P['speed']) ?></td><td><?= h($_c3Money($_c3P['price'])) ?></td><td style="color:#94a3b8;">plan #<?= (int)$_c3P['sp'] ?> · product #<?= (int)$_c3P['p'] ?></td></tr>
<?php endforeach; ?>
    </table>
<?php endif; ?>
<?php if ($_c3NotPlan !== []): ?>
    <details style="margin-top:8px;"><summary style="font-size:13px;cursor:pointer;">Service plans not offered (<?= count($_c3NotPlan) ?>) — why</summary>
      <ul style="font-size:12px;"><?php foreach ($_c3NotPlan as $_c3N): ?><li><?= h($_c3N['name'] !== '' ? $_c3N['name'] : ('#' . $_c3N['id'])) ?> (#<?= (int)$_c3N['id'] ?>): <?= h($_c3N['reason']) ?></li><?php endforeach; ?></ul>
    </details>
<?php endif; ?>
  </div>

  <div style="<?= $_c3Card ?>">
    <h3 style="margin:0 0 8px;font-size:16px;">Hardware offered (<?= count($_c3Off['hardware']) ?>)</h3>
<?php foreach (['Kits' => $_c3Kits, 'Accessories' => $_c3Acc] as $_c3G => $_c3List): if ($_c3List === []) continue; ?>
    <h4 style="margin:8px 0 4px;font-size:13px;color:#475569;"><?= h($_c3G) ?></h4>
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
<?php foreach ($_c3List as $_c3H): ?>
      <tr style="border-top:1px solid #f1f5f9;"><td><?= h($_c3H['label']) ?></td><td><?= h($_c3Money($_c3H['price'])) ?></td><td style="color:#94a3b8;">#<?= (int)$_c3H['p'] ?></td>
        <td style="text-align:right;"><?= $_c3Admin ? $_c3Form('ucrm_cat_hidden', ['product_id' => (int)$_c3H['p'], 'hide' => '1'], 'Keep off the forms', $_c3Btn2) : '' ?></td></tr>
<?php endforeach; ?>
    </table>
<?php endforeach; ?>
<?php if ($_c3Hidden !== []): ?>
    <h4 style="margin:12px 0 4px;font-size:13px;color:#475569;">Kept off the forms (<?= count($_c3Hidden) ?>)</h4>
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
<?php foreach ($_c3Hidden as $_c3K): ?>
      <tr style="border-top:1px solid #f1f5f9;"><td><?= h((string)($_c3K['product_name'] ?? '') !== '' ? (string)$_c3K['product_name'] : ('uCRM product #' . (int)$_c3K['product_id'])) ?></td><td style="color:#94a3b8;">#<?= (int)$_c3K['product_id'] ?><?= $_c3Who($_c3K) ?></td>
        <td style="text-align:right;"><?= $_c3Admin ? $_c3Form('ucrm_cat_hidden', ['product_id' => (int)$_c3K['product_id'], 'hide' => '0'], 'Offer again', $_c3Btn2) : '' ?></td></tr>
<?php endforeach; ?>
    </table>
<?php endif; ?>
<?php if ($_c3NotProd !== []): ?>
    <details style="margin-top:8px;"><summary style="font-size:13px;cursor:pointer;">uCRM products not offered (<?= count($_c3NotProd) ?>) — why</summary>
      <ul style="font-size:12px;"><?php foreach ($_c3NotProd as $_c3N): ?><li><?= h($_c3N['name'] !== '' ? $_c3N['name'] : ('#' . $_c3N['id'])) ?> (#<?= (int)$_c3N['id'] ?>): <?= h($_c3N['reason']) ?></li><?php endforeach; ?></ul>
    </details>
<?php endif; ?>
  </div>

  <div style="<?= $_c3Card ?>">
    <h3 style="margin:0 0 8px;font-size:16px;">The installation product</h3>
    <p style="font-size:13px;margin:0 0 8px;"><?php if ($_c3Inst === null): ?>Not set — "DishNet installs" cannot be priced until it is.<?php else: ?>
      <?= h((string)($_c3Inst['product_name'] ?? '')) ?> (uCRM #<?= (int)$_c3Inst['product_id'] ?>)<?= $_c3Who($_c3Inst) ?> —
      <?= !empty($_c3Off['install']['ok']) ? h($_c3Money($_c3Off['install']['line']['price'])) . ', added once when DishNet installs' : '<span style="color:#b91c1c;">not usable now: ' . h((string)($_c3Off['install']['reason'] ?? '')) . '</span>' ?><?php endif; ?></p>
<?php if ($_c3Admin && $_c3All !== []): ?>
    <form method="POST" style="margin:0;"><?= csrfField() ?><input type="hidden" name="action" value="ucrm_cat_install">
      <select name="product_id" style="max-width:420px;">
        <option value="">— pick the uCRM product —</option>
<?php foreach ($_c3All as $_c3Id => $_c3V): ?>
        <option value="<?= (int)$_c3Id ?>"<?= $_c3Inst !== null && (int)$_c3Inst['product_id'] === (int)$_c3Id ? ' selected' : '' ?>><?= h($_c3V[0]) ?> (#<?= (int)$_c3Id ?>)</option>
<?php endforeach; ?>
      </select>
      <button type="submit" style="<?= h($_c3Btn) ?>">Save</button>
    </form>
    <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Checked against uCRM when saved: a price above 0, not taxable, not spelled like a service plan.</p>
<?php endif; ?>
  </div>

  <div style="<?= $_c3Card ?>">
    <h3 style="margin:0 0 8px;font-size:16px;">Standard quotations (<?= count($_c3Stds) ?> of <?= UcrmCatalogue::MAX_STANDARDS ?>)</h3>
    <p style="font-size:13px;margin:0 0 8px;color:#64748b;">One click on the KYC form or Send Quotation fills the plan, the hardware and the installation. Priced from uCRM each time it is used.</p>
<?php foreach ($_c3Stds as $_c3S): $_c3Pr = UcrmQuoteLines::standardFrom($_c3S, $_c3Off); ?>
    <div style="border-top:1px solid #f1f5f9;padding:8px 0;font-size:13px;">
      <strong><?= h((string)$_c3S['title']) ?></strong>
      — <?= !empty($_c3Pr['ok']) ? h($_c3Money($_c3Pr['total'])) : '<span style="color:#b91c1c;">cannot be used now: ' . h((string)$_c3Pr['reason']) . '</span>' ?>
      <span style="color:#94a3b8;"><?= $_c3Who($_c3S) ?></span>
<?php if (!empty($_c3Pr['ok'])): ?>
      <ul style="margin:4px 0 0;font-size:12px;"><?php foreach ($_c3Pr['lines'] as $_c3L): ?><li><?= (int)$_c3L['quantity'] ?> × <?= h((string)$_c3L['label']) ?> — <?= h($_c3Money($_c3L['price'])) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($_c3Admin): ?>
      <div style="margin-top:4px;"><a href="?page=dashboard&amp;tab=ucrm_catalogue&amp;edit_std=<?= (int)$_c3S['id'] ?>#std-form" style="font-size:12px;">Edit</a>
        <?= $_c3Form('ucrm_cat_standard_end', ['standard_id' => (int)$_c3S['id']], 'Stop using', $_c3Btn2, 'Stop using this standard quotation?') ?></div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if ($_c3Admin && !empty($_c3Off['ok'])):
    $_c3Edit = null;
    $_c3EditId = (int)($_GET['edit_std'] ?? 0);
    if ($_c3EditId > 0) $_c3Edit = $_c3Cat->standard($_c3EditId);
    if ($_c3Edit !== null || count($_c3Stds) < UcrmCatalogue::MAX_STANDARDS): ?>
    <form method="POST" id="std-form" style="margin-top:12px;border-top:1px dashed #cbd5e1;padding-top:10px;"><?= csrfField() ?>
      <input type="hidden" name="action" value="ucrm_cat_standard_save">
<?php if ($_c3Edit !== null): ?><input type="hidden" name="standard_id" value="<?= (int)$_c3Edit['id'] ?>"><?php endif; ?>
      <h4 style="margin:0 0 6px;font-size:13px;"><?= $_c3Edit !== null ? 'Edit "' . h((string)$_c3Edit['title']) . '"' : 'Add a standard quotation' ?></h4>
      <div style="margin-bottom:6px;"><label style="font-size:12px;">Title <input type="text" name="title" maxlength="<?= UcrmCatalogue::TITLE_MAX ?>" required value="<?= h((string)($_c3Edit['title'] ?? '')) ?>" style="width:320px;"></label></div>
      <div style="margin-bottom:6px;"><label style="font-size:12px;">Plan <select name="plan" required><option value="">— pick —</option>
<?php foreach ($_c3Off['plans'] as $_c3P): $_c3V = (int)$_c3P['sp'] . ':' . (int)$_c3P['p']; ?>
        <option value="<?= h($_c3V) ?>"<?= $_c3Edit !== null && ((int)$_c3Edit['plan']['sp'] . ':' . (int)$_c3Edit['plan']['p']) === $_c3V ? ' selected' : '' ?>><?= h($_c3P['label']) ?> — <?= h($_c3Money($_c3P['price'])) ?></option>
<?php endforeach; ?>
      </select></label></div>
<?php $_c3Rows = min(UcrmCatalogue::MAX_STANDARD_ITEMS, max(3, count((array)($_c3Edit['hw'] ?? [])) + 2)); for ($_c3I = 0; $_c3I < $_c3Rows; $_c3I++): $_c3Cur0 = $_c3Edit['hw'][$_c3I] ?? null; ?>
      <div style="margin-bottom:4px;font-size:12px;">Hardware <select name="hw_p[]"><option value="">— none —</option>
<?php foreach ($_c3Off['hardware'] as $_c3H): ?>
        <option value="<?= (int)$_c3H['p'] ?>"<?= $_c3Cur0 !== null && (int)$_c3Cur0['p'] === (int)$_c3H['p'] ? ' selected' : '' ?>><?= h($_c3H['label']) ?> — <?= h($_c3Money($_c3H['price'])) ?></option>
<?php endforeach; ?>
      </select> × <input type="number" name="hw_q[]" min="1" max="<?= UcrmCatalogue::MAX_QTY ?>" value="<?= (int)($_c3Cur0['q'] ?? 1) ?>" style="width:60px;"></div>
<?php endfor; ?>
      <div style="margin:6px 0;font-size:12px;"><label><input type="checkbox" name="install" value="1"<?= !empty($_c3Edit['install']) ? ' checked' : '' ?>> includes installation (DishNet installs)</label></div>
      <button type="submit" style="<?= h($_c3Btn) ?>"><?= $_c3Edit !== null ? 'Save the changes' : 'Add' ?></button>
    </form>
<?php endif; endif; ?>
  </div>
</div>
