<?php
// 5.18.102 (Uganda, docs/71 §G): the form draws uCRM's prices — UcrmQuoteLines, the resolver the quotation and the cash
// amount are made from, read for a page (short timeouts; a successful read reused for 60 s; a failure never kept).
// Behind the quoting-organization gate (D7), read through QuoteOrganization::setting(): where it is off (South Sudan;
// Uganda with ucrm_quote_organization_id 0/off) $_q102 stays null, nothing is read and nothing new is emitted below.
// $_q102 = ['ok', 'reason', 'plans' => [id => ['p' => price|null, 'r' => reason]], 'kits' => [id => …], 'install'].
// 5.18.102 (D14, docs/71 §R.4.7, §R.5): each cell also carries 'l', the name its row is drawn with — uCRM's product name
// when the row's link resolves, else the plugin row's own (the installation: its display word) — and, only when it
// resolves, 'k', the link record id it resolved through (the row's data-link; the selection's are posted as
// ucrm_links_seen). The links are read live from the store (UcrmQuoteLinks::fromStore), behind the same gate.
require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
$_q102 = null;
// 5.18.103 (Uganda, root docs/72 §G.5): $_q103 stays null — and everything below is 5.18.102's, or South Sudan's where
// the gate is off — unless the gate is open with an integer id (UcrmCatalogue::gateOpen) AND an admin has turned the uCRM
// catalogue on (UcrmCatalogue::direct()): "direct mode", the plans and hardware straight from uCRM by uCRM id.
$_q103 = null;
$_q102Set = QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
if (!empty($_q102Set['on'])) {
    require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';
    $_q102Unread = "uCRM's products could not be read";
    $_q102Fiber  = 'Fiber is not priced from uCRM on this installation';   // its fee and product 244 are South Sudan's
    $_q102 = ['ok' => false, 'reason' => $_q102Unread, 'plans' => [], 'kits' => [],
              'install' => ['p' => null, 'r' => $_q102Unread, 'l' => UcrmQuoteLines::INSTALL_NAME]];
    // 5.18.102 (D14): $name is what the row is drawn with when its link does not resolve — the plugin row's own name
    $_q102Cell = function (array $r, string $name): array {
        $ok = !empty($r['ok']) && is_array($r['line'] ?? null);
        $cell = ['p' => $ok ? round((float)$r['line']['price'], 2) : null, 'r' => $ok ? '' : (string)($r['reason'] ?? ''),
                 'l' => $ok ? (string)($r['line']['label'] ?? '') : $name];
        if ($ok && is_int($r['link'] ?? null) && $r['link'] > 0) $cell['k'] = $r['link'];   // only a link that resolves
        return $cell;
    };
    $_q102Named = function (array $row, string $key): string {
        return is_scalar($row[$key] ?? null) ? (string)$row[$key] : '';
    };
    // 5.18.103 (root docs/72 §G.5, G-1): direct mode. The switch is read behind the gate (the store's ucrm_catalogue;
    // UcrmCatalogue::fromStore opens nothing unless the gate is open with an integer id). On: the 5.18.102 reading below
    // is skipped — no plugin plan or kit row is priced or drawn, and no link is read — and $_q102 keeps its shape over
    // uCRM's own items: the cell map q102Quote() totals, keyed by the direct ids, a plan by "<servicePlanId>:<productId>"
    // (its ucrm_plan value) and hardware by the uCRM product id. Each cell also carries 't', the item's token
    // (UcrmQuoteLines::token(), computed in PHP only), which the form posts back for its selection as ucrm_items_seen.
    // $_q103 holds what the direct markup draws. A switch that cannot be read is off here: 5.18.102's form, whose post the
    // server reads as 'app' while the switch is on (a Credit sale unpriced, Cash refused).
    $_q103Cat = null;
    try {
        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';
        if (UcrmCatalogue::gateOpen($_q102Set)) {
            $_q103Cat = UcrmCatalogue::fromStore($store, $_q102Set);
            if ($_q103Cat->direct()) {
                $_q103 = ['read' => 'none', 'plans' => [], 'hardware' => [], 'standards' => [], 'not_plans' => 0,
                          'not_products' => 0, 'admin' => false];
            }
        }
    } catch (\Throwable $e) {
        error_log('[kyc_form] 5.18.103: the uCRM catalogue switch could not be read (' . get_class($e) . ')');
        $_q103 = null;
    }
    if ($_q103 !== null) {
        // 5.18.103 (§G.5): the screen that lists what is not offered (?tab=ucrm_catalogue) is linked for those who may see
        // it — Plans or Hardware, admins by default (public.php's $_tabPerms) — and named for everyone else
        try {
            $_q103['admin'] = isset($can) && is_callable($can) && ($can('plans') || $can('hardware'));
        } catch (\Throwable $e) {
            $_q103['admin'] = false;
        }
        $_q103Tok = function ($t): string {
            return (is_string($t) && preg_match('/^[0-9a-f]{16}$/', $t) === 1) ? $t : '';
        };
        try {
            // 5.18.103 (§G.4, §G.5): what the forms offer — the plan pairs (G-4), the hardware (G-5), the installation
            // (G-6) — from the page's read (short timeouts, the 60-second cache) or, when that read fails, from the last
            // good list, marked stale; never priced from at submission, which reads uCRM again
            $_q103Page = UcrmQuoteLines::forDirectPage(isset($crm) ? $crm : null, is_array($config) ? $config : [], $_q102Set, (string)$dataDir);
            $_q103Off  = $_q103Page['uql']->offered($_q103Cat->installProductId(), $_q103Cat->hiddenIds());
            if (!empty($_q103Off['ok'])) {
                $_q103['read'] = !empty($_q103Page['stale']) ? 'stale' : 'fresh';
                foreach ((array)$_q103Off['plans'] as $_q103R) {
                    $_q103V = (int)($_q103R['sp'] ?? 0) . ':' . (int)($_q103R['p'] ?? 0);
                    $_q103T = $_q103Tok($_q103R['token'] ?? null);
                    if (UcrmQuoteLines::parsePlan($_q103V) === null || $_q103T === '') continue;
                    $_q103Pr = round((float)($_q103R['price'] ?? 0), 2);
                    $_q103['plans'][] = ['v' => $_q103V, 'label' => (string)($_q103R['label'] ?? ''), 'price' => $_q103Pr,
                                         'speed' => trim((string)($_q103R['speed'] ?? '')), 'token' => $_q103T];
                    $_q102['plans'][$_q103V] = ['p' => $_q103Pr, 'r' => '', 'l' => (string)($_q103R['label'] ?? ''), 't' => $_q103T];
                }
                foreach ((array)$_q103Off['hardware'] as $_q103R) {
                    $_q103P = (int)($_q103R['p'] ?? 0);
                    $_q103T = $_q103Tok($_q103R['token'] ?? null);
                    if ($_q103P <= 0 || $_q103T === '') continue;
                    $_q103Pr = round((float)($_q103R['price'] ?? 0), 2);
                    $_q103['hardware'][] = ['p' => $_q103P, 'label' => (string)($_q103R['label'] ?? ''), 'price' => $_q103Pr,
                                            'group' => (($_q103R['group'] ?? '') === 'accessory') ? 'accessory' : 'kit', 'token' => $_q103T];
                    $_q102['kits'][(string)$_q103P] = ['p' => $_q103Pr, 'r' => '', 'l' => (string)($_q103R['label'] ?? ''), 't' => $_q103T];
                }
                foreach ((array)$_q103Off['not_offered'] as $_q103R) {
                    if (($_q103R['kind'] ?? '') === 'plan') $_q103['not_plans']++;
                    else $_q103['not_products']++;
                }
                // the installation: offered()'s own judgement of the product in force — its cell, or the reason
                $_q103In = (array)($_q103Off['install'] ?? []);
                $_q103T  = $_q103Tok($_q103In['token'] ?? null);
                $_q102['install'] = (!empty($_q103In['ok']) && is_array($_q103In['line'] ?? null) && $_q103T !== '')
                    ? ['p' => round((float)($_q103In['line']['price'] ?? 0), 2), 'r' => '', 'l' => (string)($_q103In['line']['label'] ?? ''), 't' => $_q103T]
                    : ['p' => null, 'r' => (string)($_q103In['reason'] ?? $_q102Unread), 'l' => UcrmQuoteLines::INSTALL_NAME];
                // the total is uCRM's only on a fresh read: on the last good list every line is unpriced, so there is no
                // total, no ucrm_total_seen, and Cash is disabled (§G.5) — Credit registrations still go in
                $_q102['ok']     = $_q103['read'] === 'fresh';
                $_q102['reason'] = $_q103['read'] === 'fresh' ? '' : 'uCRM could not be read — the list may be out of date';
            }
            // 5.18.103 (G-12): each standard quotation an admin defined, priced live against the same list — usable, or
            // the reason it is not (never a partial bundle); ids and quantities only, never a price from the store
            foreach ($_q103Cat->standards() as $_q103S) {
                $_q103F  = UcrmQuoteLines::standardFrom($_q103S, $_q103Off);
                $_q103Hw = [];
                foreach ((array)($_q103S['hw'] ?? []) as $_q103H) $_q103Hw[] = ['p' => (int)($_q103H['p'] ?? 0), 'q' => (int)($_q103H['q'] ?? 0)];
                $_q103['standards'][] = ['id' => (int)($_q103S['id'] ?? 0), 'title' => (string)($_q103S['title'] ?? ''),
                    'ok' => !empty($_q103F['ok']), 'reason' => (string)($_q103F['reason'] ?? ''),
                    'total' => !empty($_q103F['ok']) ? round((float)$_q103F['total'], 2) : null,
                    'install' => !empty($_q103S['install']), 'hw' => $_q103Hw,
                    'plan' => (int)($_q103S['plan']['sp'] ?? 0) . ':' . (int)($_q103S['plan']['p'] ?? 0)];
            }
        } catch (\Throwable $e) {
            error_log('[kyc_form] 5.18.103: the uCRM catalogue could not be drawn (' . get_class($e) . ')');
            $_q103 = ['read' => 'none', 'plans' => [], 'hardware' => [], 'standards' => [], 'not_plans' => 0,
                      'not_products' => 0, 'admin' => !empty($_q103['admin'])];
            $_q102 = ['ok' => false, 'reason' => $_q102Unread, 'plans' => [], 'kits' => [],
                      'install' => ['p' => null, 'r' => $_q102Unread, 'l' => UcrmQuoteLines::INSTALL_NAME]];
        }
    }
    if ($_q103 === null) {   // 5.18.103 (§G.5): the 5.18.102 reading, unchanged — only while direct mode is off
    try {
        // 5.18.102 (D14, docs/71 §R.4): priced only through the links an admin made, read live — never by a name
        $_q102Res = UcrmQuoteLines::forPage(UcrmQuoteLines::quoteClient(isset($crm) ? $crm : null, is_array($config) ? $config : []),
            $_q102Set, (string)$dataDir, UcrmQuoteLinks::fromStore($store, $_q102Set));
        $_q102Cat = $_q102Res->catalogue();
        if (!empty($_q102Cat['ok'])) {
            $_q102['ok'] = true;
            $_q102['reason'] = '';
            foreach ((array)$store->load('subscription_plans.json') as $_q102Sp) {
                if (!is_array($_q102Sp) || empty($_q102Sp['is_active'])) continue;
                $_q102['plans'][(string)($_q102Sp['id'] ?? '')] = strtolower((string)($_q102Sp['type'] ?? 'starlink')) === 'fiber'
                    ? ['p' => null, 'r' => $_q102Fiber] : $_q102Cell($_q102Res->planLine($_q102Sp), $_q102Named($_q102Sp, 'name'));
            }
            foreach ((array)($devices ?? []) as $_q102D) {
                if (!is_array($_q102D) || empty($_q102D['is_active'])) continue;
                // 5.18.102 (D14, F-5): the kit's own cell or the Fiber cell, chosen by UcrmQuoteLinks::offeredKit — rule 8's
                // "offered", one implementation; this loop compares no type itself
                $_q102['kits'][(string)($_q102D['id'] ?? '')] = UcrmQuoteLinks::offeredKit($_q102D)
                    ? $_q102Cell($_q102Res->kitLine($_q102D, 1), $_q102Named($_q102D, 'title')) : ['p' => null, 'r' => $_q102Fiber];
            }
            // 5.18.102 (D14, §R.2.4): the installation's linked product, by its link; unlinked, its display word only
            $_q102['install'] = $_q102Cell($_q102Res->installLine(), UcrmQuoteLines::INSTALL_NAME);
        }
    } catch (\Throwable $e) {
        error_log('[kyc_form] 5.18.102: the uCRM catalogue could not be drawn (' . get_class($e) . ')');
        $_q102['ok'] = false;
        $_q102['reason'] = $_q102Unread;
        $_q102['plans'] = $_q102['kits'] = [];
        $_q102['install'] = ['p' => null, 'r' => $_q102Unread, 'l' => UcrmQuoteLines::INSTALL_NAME];
    }
    }   // 5.18.103 (§G.5): end of the 5.18.102 reading
    // One row's cell for the PHP-drawn lists: what the resolver said, or the unreadable reason
    $_q102Of = function (string $map, $id) use (&$_q102): array {
        if (empty($_q102['ok'])) return ['p' => null, 'r' => (string)$_q102['reason']];
        return $_q102[$map][(string)$id] ?? ['p' => null, 'r' => 'not in the page\'s price list'];
    };
}
?>

<!-- STEP WIZARD CSS -->
<style>
.wiz-container{max-width:700px;margin:0 auto;}
.wiz-progress{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:28px;padding:0 10px;}
.wiz-step-dot{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;border:2px solid #dee2e6;color:#aaa;background:#fff;transition:.3s;position:relative;z-index:1;flex-shrink:0;}
.wiz-step-dot.active{background:#D41C1C;color:#fff;border-color:#D41C1C;box-shadow:0 3px 12px rgba(212,28,28,.35);}
.wiz-step-dot.done{background:#28a745;color:#fff;border-color:#28a745;}
.wiz-step-line{flex:1;height:3px;background:#dee2e6;margin:0 -2px;transition:.3s;}
.wiz-step-line.done{background:#28a745;}
.wiz-step-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#aaa;margin-top:4px;text-align:center;white-space:nowrap;}
.wiz-step-label.active{color:#D41C1C;}
.wiz-step-label.done{color:#28a745;}
.wiz-step-wrap{display:flex;flex-direction:column;align-items:center;flex-shrink:0;}
/* M-01: On small screens only show the active step label to prevent overflow */
@media(max-width:420px){
  .wiz-step-label{display:none;}
  .wiz-step-label.active{display:block;}
  .wiz-step-wrap{min-width:40px;}
}
.wiz-panel{display:none;animation:wizFade .3s ease;}
.wiz-panel.active{display:block;}
@keyframes wizFade{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}
.wiz-field{margin-bottom:18px;}
.wiz-field label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.wiz-field label .req{color:#dc3545;}
/* C-01: 16px minimum to prevent iOS zoom; visually fine at this size */
.wiz-field input,.wiz-field select,.wiz-field textarea{width:100%;padding:12px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:16px!important;transition:.2s;background:#fafbfc;font-family:inherit;}
.wiz-field input:focus,.wiz-field select:focus,.wiz-field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(33,150,243,.1);outline:none;background:#fff;}
.wiz-field textarea{resize:vertical;min-height:60px;}
.wiz-field .hint{font-size:11px;color:#9ca3af;margin-top:4px;}
.wiz-radio-group{display:flex;flex-wrap:wrap;gap:10px;}
.wiz-radio-card{flex:1;min-width:120px;padding:14px 16px;border:2px solid #e5e7eb;border-radius:12px;cursor:pointer;transition:.2s;text-align:center;background:#fff;}
.wiz-radio-card:hover{border-color:#D41C1C;background:#fff5f5;}
.wiz-radio-card.selected{border-color:#D41C1C;background:linear-gradient(135deg,#fff5f5,#ffecec);box-shadow:0 2px 8px rgba(212,28,28,.15);}
.wiz-radio-card input{display:none;}
.wiz-radio-card .wrc-icon{font-size:22px;margin-bottom:4px;}
.wiz-radio-card .wrc-title{font-size:13px;font-weight:700;color:#1e293b;}
.wiz-radio-card .wrc-sub{font-size:11px;color:#94a3b8;margin-top:2px;}
.wiz-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.wiz-section-title{font-size:15px;font-weight:800;color:#1e293b;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #f1f5f9;display:flex;align-items:center;gap:8px;}
.wiz-section-title i{color:var(--primary);}
.wiz-nav{display:flex;gap:12px;margin-top:24px;}
.wiz-nav .wiz-btn{flex:1;padding:14px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;border:none;transition:.2s;text-align:center;}
.wiz-btn-back{background:#f1f5f9;color:#475569;}
.wiz-btn-back:hover{background:#e2e8f0;}
.wiz-btn-next{background:#D41C1C;color:#fff;box-shadow:0 2px 8px rgba(212,28,28,.3);border-radius:8px;}
/* M-03: Hardware +/- buttons — bumped to 40px for 44px+ effective tap area */
.hw-qty-btn{width:40px!important;height:40px!important;font-size:20px!important;min-width:40px;}
/* N-02: Review table — tighter label column so values have room */
.review-table td:first-child{width:35%;min-width:90px;max-width:130px;}
.review-table{word-break:break-word;}
.wiz-btn-next:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(212,28,28,.4);}
.wiz-btn-next:disabled{opacity:.5;transform:none;cursor:not-allowed;}
.wiz-btn-submit{background:linear-gradient(135deg,#28a745,#20c997);color:#fff;box-shadow:0 4px 12px rgba(40,167,69,.3);}
.wiz-btn-submit:hover{transform:translateY(-1px);}
/* SIM card style */
.sim-card-visual{background:linear-gradient(145deg,#1a1a2e,#2d2d44);border-radius:14px;padding:20px;color:#fff;margin-bottom:14px;position:relative;overflow:hidden;min-height:100px;}
.sim-card-visual::before{content:'';position:absolute;top:15px;left:15px;width:40px;height:30px;border-radius:5px;background:linear-gradient(135deg,#d4a017,#f0c040);box-shadow:inset 0 1px 2px rgba(0,0,0,.2);}
.sim-card-visual .sim-number{font-family:monospace;font-size:13px;letter-spacing:2px;margin-top:40px;color:rgba(255,255,255,.7);}
.sim-card-visual .sim-msisdn{font-size:18px;font-weight:700;margin-top:4px;letter-spacing:1px;}
.sim-card-visual .sim-brand{position:absolute;top:15px;right:15px;font-size:11px;font-weight:800;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:2px;}
/* Review summary */
.review-table{width:100%;}
.review-table tr{border-bottom:1px solid #f1f5f9;}
.review-table td{padding:10px 12px;font-size:13px;}
.review-table td:first-child{font-weight:700;color:#64748b;width:40%;font-size:12px;text-transform:uppercase;letter-spacing:.3px;}
.review-table td:last-child{color:#1e293b;font-weight:600;}
/* File upload */
.wiz-upload{border:2px dashed #d1d5db;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:.2s;background:#fafbfc;}
.wiz-upload:hover,.wiz-upload.dragover{border-color:var(--primary);background:#f0fdfa;}
.wiz-upload i{font-size:28px;color:#9ca3af;display:block;margin-bottom:6px;}
.wiz-upload span{font-size:12px;color:#6b7280;}
.wiz-upload input{display:none;}
.wiz-upload-preview{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;}
.wiz-upload-preview img{width:56px;height:56px;border-radius:8px;object-fit:cover;border:2px solid var(--primary);}
</style>
<script>
// XSS-safe HTML escaping for dynamic content
function esc(s) { var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Multi-contact management (v4.9.20) ─────────────────────────────────
var _kycContactIdx = 1; // next index (0 is primary)
var _kycMaxContacts = 5;

function kycAddContact() {
    var wrap = document.getElementById('kycContactsWrap');
    if (!wrap) return;
    var existing = wrap.querySelectorAll('.kyc-contact-row').length;
    if (existing >= _kycMaxContacts) { alert('Maximum ' + _kycMaxContacts + ' contacts allowed.'); return; }
    var idx = _kycContactIdx++;
    var row = document.createElement('div');
    row.className = 'kyc-contact-row';
    row.setAttribute('data-idx', idx);
    row.style.cssText = 'background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px;margin-bottom:8px;';
    row.innerHTML =
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
            '<span style="font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;">Contact ' + (existing + 1) + '</span>' +
            '<button type="button" onclick="kycRemoveContact(this)" style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">Remove</button>' +
        '</div>' +
        '<div class="wiz-row" style="margin-bottom:0;">' +
            '<div class="wiz-field"><label>Phone</label><input type="tel" name="contacts[' + idx + '][phone]" placeholder="+211 9XX XXX XXX"></div>' +
            '<div class="wiz-field"><label>Email</label><input type="email" name="contacts[' + idx + '][email]" placeholder="email@example.com"></div>' +
        '</div>' +
        '<div class="wiz-row" style="margin-bottom:0;">' +
            '<div class="wiz-field"><label>Contact name <span style="font-weight:400;color:#9ca3af;">(e.g. Office Manager)</span></label><input type="text" name="contacts[' + idx + '][name]" placeholder="Who is this contact?"></div>' +
        '</div>';
    wrap.appendChild(row);
}

function kycRemoveContact(btn) {
    var row = btn.closest('.kyc-contact-row');
    if (row) row.remove();
}

/* ── KYC Duplicate Detection ──────────────────────────────────────────── */
var _kycDupTimer = null;
var _kycPhoneChecked = '';
var _kycNameChecked  = '';
var _kycApiToken = '<?= h($retailer['api_token'] ?? '') ?>';

function kycDupBanner(html, type) {
    // type: 'clear'|'info'|'warning'|'found'|'shared'|'error'
    var el = document.getElementById('kycDupBanner');
    if (!el) return;
    if (!html || type === 'clear') { el.style.display = 'none'; el.innerHTML = ''; return; }
    var styles = {
        info:    'background:#eff6ff;border:1.5px solid #93c5fd;color:#1e40af;',
        warning: 'background:#fffbeb;border:1.5px solid #fbbf24;color:#92400e;',
        found:   'background:#fff7ed;border:1.5px solid #fb923c;color:#c2410c;',
        shared:  'background:#fef3c7;border:1.5px solid #f59e0b;color:#78350f;',
        error:   'background:#fef2f2;border:1.5px solid #fca5a5;color:#991b1b;',
    };
    el.style.cssText = 'display:block;border-radius:12px;padding:12px 14px;margin-bottom:10px;font-size:13px;' + (styles[type] || styles.info);
    el.innerHTML = html;
}

function kycClearDupState() {
    document.getElementById('kycDupConfirmed').value = '0';
    document.getElementById('kycDupCrmId').value     = '';
    document.getElementById('kycDupNote').value      = '';
}

// Called when staff taps "Add service to CRM #X"
window.kycUseExisting = function(crmId, name) {
    document.getElementById('kycCustomerId').value   = crmId;
    document.getElementById('kycDupConfirmed').value = '0';
    document.getElementById('kycDupCrmId').value     = crmId;
    document.getElementById('kycDupNote').value      = 'auto-linked-existing';
    kycDupBanner(
        '<strong>✅ Linked to existing customer: ' + name + ' (CRM #' + crmId + ')</strong><br>' +
        '<span style="font-size:12px;">A new service will be added to their account — no duplicate will be created.</span>',
        'info'
    );
};

// Called when staff ticks "Different customer" confirmation
window.kycConfirmDifferent = function(phone, existingName, crmId) {
    document.getElementById('kycDupConfirmed').value = '1';
    document.getElementById('kycDupCrmId').value     = crmId;
    document.getElementById('kycDupNote').value      = 'confirmed-different-by-staff';
    // Remove the checkbox row, show a logged confirmation banner
    kycDupBanner(
        '⚠ <strong>Logged:</strong> You confirmed this is a different customer sharing phone ' + phone + '.<br>' +
        '<span style="font-size:12px;opacity:.8;">This will be reviewed by admin. Please ensure the customer has a unique contact if possible.</span>',
        'warning'
    );
};

function kycCheckPhone(phone) {
    phone = (phone || '').trim();
    var digits = phone.replace(/[^0-9]/g, '');
    if (digits.length < 7) { kycDupBanner('', 'clear'); return; }
    if (phone === _kycPhoneChecked) return; // already checked this number
    _kycPhoneChecked = phone;
    kycClearDupState();

    // Check if customer_id already filled — if so no need to warn
    var custId = (document.getElementById('kycCustomerId') || {}).value || '';
    if (custId.trim() !== '') return;

    kycDupBanner('<span style="opacity:.7;">🔍 Checking phone number...</span>', 'info');

    fetch('?page=api&action=check_phone_duplicate&phone=' + encodeURIComponent(phone), {
        credentials: 'same-origin',
        headers: { 'Authorization': 'Bearer ' + _kycApiToken }
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (!d || !d.data) { kycDupBanner('', 'clear'); return; }
        var data = d.data;

        if (data.status === 'clear') {
            kycDupBanner('<span style="color:#059669;">✅ Phone number not in system — new customer</span>', 'info');
            setTimeout(function(){ kycDupBanner('', 'clear'); }, 2000);
            return;
        }

        if (data.status === 'failed_retry') {
            kycDupBanner('ℹ Previous registration with this number failed and was refunded. Continuing as new registration.', 'info');
            return;
        }

        var matches = data.matches || [];

        if (data.status === 'found' && matches.length === 1) {
            var m = matches[0];
            var crmId = m.crm_id || 0;
            var name  = m.name || 'Unknown';
            var svc   = m.service ? ' — ' + m.service : '';
            var html  = '⚠ <strong>This number is already registered:</strong> ' + name + (crmId ? ' (CRM #' + crmId + ')' : '') + svc + '<br><br>';
            // v4.21.99: Add-new-service-to-existing-CRM option removed by request.
            // Every registration must create a NEW customer. If phone matches an existing
            // customer, staff get only two paths: register as different (logged for review)
            // or change phone number entirely.
            if (crmId) {
                html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">';
                html += '<button type="button" onclick="kycConfirmDifferent(\'' + phone + '\',\'' + name.replace(/'/g,'') + '\',' + crmId + ')" '
                     + 'style="background:#059669;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">'
                     + '✓ Register as new customer (logged for admin review)</button>';
                html += '<button type="button" onclick="kycPhoneClear()" '
                     + 'style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">'
                     + '❌ Change phone number</button>';
                html += '</div>';
            }
            kycDupBanner(html, 'found');
            return;
        }

        if (data.status === 'shared' && matches.length >= 2) {
            var html2 = '⚠ <strong>This number is used by multiple clients (shared office phone):</strong><br>';
            matches.forEach(function(m) {
                html2 += '&nbsp;&nbsp;• ' + m.name + (m.crm_id ? ' (CRM #' + m.crm_id + ')' : '') + (m.service ? ' — ' + m.service : '') + '<br>';
            });
            html2 += '<br><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;">'
                  + '<input type="checkbox" onchange="if(this.checked){kycConfirmDifferent(\'' + phone + '\',\'' + (matches[0].name||'').replace(/'/g,'') + '\',' + (matches[0].crm_id||0) + ')}else{kycClearDupState();}" style="width:18px;height:18px;">'
                  + 'I confirm this is a genuinely different customer using a shared phone</label>';
            kycDupBanner(html2, 'shared');
        }
    }).catch(function(){ kycDupBanner('', 'clear'); });
}

window.kycPhoneClear = function() {
    var inp = document.getElementById('kycPhoneInput');
    if (inp) { inp.value = ''; inp.focus(); }
    _kycPhoneChecked = '';
    kycClearDupState();
    kycDupBanner('', 'clear');
};

function kycCheckName() {
    var fn = (document.querySelector('[name="firstname"]') || {}).value || '';
    var ln = (document.querySelector('[name="lastname"]')  || {}).value || '';
    var name = (fn + ' ' + ln).trim();
    if (name.length < 3 || name === _kycNameChecked) return;
    _kycNameChecked = name;

    // Don't show name warning if phone already triggered a banner
    var banner = document.getElementById('kycDupBanner');
    if (banner && banner.style.display !== 'none' && banner.innerHTML.indexOf('already registered') !== -1) return;

    fetch('?page=api&action=check_name_duplicate&name=' + encodeURIComponent(name), {
        credentials: 'same-origin',
        headers: { 'Authorization': 'Bearer ' + _kycApiToken }
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (!d || !d.data || d.data.status === 'clear') return;
        var matches = d.data.matches || [];
        if (!matches.length) return;
        var html = '⚠ <strong>Similar name found in system:</strong><br>';
        matches.forEach(function(m) {
            html += '&nbsp;&nbsp;• <strong>' + m.name + '</strong>'
                 + (m.crm_id ? ' (CRM #' + m.crm_id + ')' : '')
                 + (m.service ? ' — ' + m.service : '') + '<br>';
        });
        html += '<br><div style="display:flex;gap:8px;flex-wrap:wrap;">';
        // v4.21.99: same-org add-service button removed; staff just continue as new customer.
        html += '<button type="button" onclick="kycDupBanner(\'\',\'clear\')" '
             + 'style="background:#059669;color:#fff;border:none;border-radius:8px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;">'
             + '✅ Different organisation — continue</button>';
        html += '</div>';
        kycDupBanner(html, 'warning');
    }).catch(function(){});
}

function kycSyncContactMirrors() {
    var ph = document.querySelector('input[name="contacts[0][phone]"]');
    var em = document.querySelector('input[name="contacts[0][email]"]');
    if (ph) document.getElementById('kycMobileMirror').value = ph.value;
    if (em) document.getElementById('kycEmailMirror').value = em.value;
}

// Also sync on form submit
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('kycForm');
    if (form) {
        form.addEventListener('submit', function() { kycSyncContactMirrors(); });
        // Auto-fill contact[0][name] from firstname/lastname
        var fn = form.querySelector('[name="firstname"]');
        var ln = form.querySelector('[name="lastname"]');
        var cn = form.querySelector('[name="contacts[0][name]"]');
        if (fn && cn) {
            var syncName = function() {
                if (!cn.value || cn.value === cn.getAttribute('data-auto')) {
                    var v = ((fn ? fn.value : '') + ' ' + (ln ? ln.value : '')).trim();
                    cn.value = v;
                    cn.setAttribute('data-auto', v);
                }
            };
            fn.addEventListener('input', syncName);
            if (ln) ln.addEventListener('input', syncName);
        }
    }
});
// ── End multi-contact ──────────────────────────────────────────────────

let wizCurrent = 1;
const wizTotal = 5;

function wizGo(step) {
    if (step < 1 || step > wizTotal) return;
    wizCurrent = step;
    document.querySelectorAll('.wiz-panel').forEach(p => p.classList.remove('active'));
    const target = document.querySelector('[data-panel="'+step+'"]');
    if (target) target.classList.add('active');
    document.querySelectorAll('.wiz-step-dot').forEach(d => {
        const s = +d.dataset.step;
        d.classList.remove('active','done');
        if (s < step) { d.classList.add('done'); d.innerHTML = '<i class="bi bi-check"></i>'; }
        else if (s === step) { d.classList.add('active'); d.textContent = s; }
        else { d.textContent = s; }
    });
    document.querySelectorAll('.wiz-step-label').forEach((l, i) => {
        l.classList.remove('active','done');
        if (i+1 < step) l.classList.add('done');
        else if (i+1 === step) l.classList.add('active');
    });
    document.querySelectorAll('.wiz-step-line').forEach((l, i) => {
        l.classList.toggle('done', i+1 < step);
    });
    if (step === wizTotal) wizBuildReview();
    syncOuterFields(); // keep hidden fields current at every step navigation
    if (step === 3 && typeof kitScanVisibility === 'function') kitScanVisibility();
    window.scrollTo({top:0,behavior:'smooth'});
    // U-01: Push history state so iOS swipe-back goes to previous step, not exits
    history.pushState({wizStep: step}, '', location.href);
}

// U-01: Intercept browser back (swipe-back on iOS) to navigate wizard steps
window.addEventListener('popstate', function(e) {
    if (e.state && e.state.wizStep) {
        // Going back via swipe — move to previous step without pushing new state
        var targetStep = e.state.wizStep;
        wizCurrent = targetStep;
        document.querySelectorAll('.wiz-panel').forEach(p => p.classList.remove('active'));
        var target = document.querySelector('[data-panel="'+targetStep+'"]');
        if (target) target.classList.add('active');
        document.querySelectorAll('.wiz-step-dot').forEach(function(d) {
            var s = +d.dataset.step;
            d.classList.remove('active','done');
            if (s < targetStep) { d.classList.add('done'); d.innerHTML = '<i class="bi bi-check"></i>'; }
            else if (s === targetStep) { d.classList.add('active'); d.textContent = s; }
            else { d.textContent = s; }
        });
        document.querySelectorAll('.wiz-step-label').forEach(function(l, i) {
            l.classList.remove('active','done');
            if (i+1 < targetStep) l.classList.add('done');
            else if (i+1 === targetStep) l.classList.add('active');
        });
        window.scrollTo({top:0,behavior:'smooth'});
    }
});
// Push initial state so first back-swipe goes to step 1 not away
history.replaceState({wizStep: 1}, '', location.href);

function wizNext() {
    const panel = document.querySelector('[data-panel="'+wizCurrent+'"]');
    const inputs = panel.querySelectorAll('input[required],select[required],textarea[required]');
    let valid = true;
    inputs.forEach(f => {
        if (!f.value || !f.value.trim()) { f.style.borderColor='#dc3545'; valid=false; }
        else { f.style.borderColor=''; }
    });
<?php if ($_q102 !== null): ?>
    // 5.18.102 (Uganda, docs/71 §E.1): "Who installs?" is checked here explicitly — a radio always has a value, so the
    // check above would pass it, and a required radio in a hidden panel would block Submit with no visible message
    if (panel.querySelector('#q102Install') && !q102InstallCheck()) valid = false;
<?php endif; ?>
    if (!valid) return;
    wizGo(wizCurrent + 1);
}
function wizBack() { wizGo(wizCurrent - 1); }

function wizRadio(el) {
    const group = el.closest('.wiz-radio-group');
    if (!group) return;
    group.querySelectorAll('.wiz-radio-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    const inp = el.querySelector('input[type=radio]');
    if (inp) inp.checked = true;
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    wizTypeChange();
    const st = document.getElementById('wSalesType');
    if (st) wizSalesType(st);
});

// Form submit
document.getElementById('kycForm')?.addEventListener('submit', function(e) {
    const t = document.querySelector('input[name="customer_type"]:checked')?.value || '';
    if(t === 'LTE') {
        // For LTE: register directly in LTE system instead of standard KYC submit
        e.preventDefault();
        const btn = document.getElementById('btnSubmit');
        const name  = document.querySelector('[name="first_name"]')?.value?.trim() + ' ' + (document.querySelector('[name="last_name"]')?.value?.trim()||'');
        const phone = document.querySelector('[name="phone"]')?.value?.trim() || document.querySelector('[name="contact_phone"]')?.value?.trim() || '';
        const email = document.querySelector('[name="email"]')?.value?.trim() || '';
        const addr  = document.querySelector('[name="install_address"]')?.value?.trim() || document.querySelector('[name="address"]')?.value?.trim() || '';
        const imsi  = document.querySelector('[name="lte_imsi"]')?.value?.trim() || '';
        const msisdn= document.querySelector('[name="lte_msisdn"]')?.value?.trim() || '';
        const iccid = document.querySelector('[name="lte_iccid"]')?.value?.trim() || '';
        const pkgId = parseInt(document.querySelector('[name="lte_package_id"]')?.value||'0')||0;
        const amt   = parseFloat(document.querySelector('[name="lte_amount_paid"]')?.value||'0')||0;
        const meth  = document.querySelector('[name="lte_payment_method"]')?.value||'cash';
        if(!name.trim()||!phone){alert('Name and phone are required');return;}
        btn.disabled=true;btn.textContent='Registering LTE Subscriber…';
        const TK = document.querySelector('meta[name="api-token"]')?.content || '';
        fetch('?page=api&action=lte_create_subscriber',{
          credentials:'same-origin',
          method:'POST',
            headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},
            body:JSON.stringify({name:name.trim(),phone,email,address:addr,imsi,msisdn,iccid,
                package_id:pkgId,amount_paid:amt,payment_method:meth})
        }).then(r=>r.json()).then(function(d){
            btn.disabled=false;btn.textContent='Submit';
            if(d.status==='success'){
                alert('✅ LTE Subscriber #'+d.data.id+' registered!'+(d.data._first_renewal?' Plan activated until '+d.data._first_renewal._expires+'.':'')<?php /* 5.18.99 (Uganda): the reason no quotation was made — emitted only where the quoting-organization gate is on */ require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php'; if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']): ?>+(d.data._quote_error?' '+d.data._quote_error:'')<?php endif; ?>);
                window.location.href='?page=dashboard&tab=lte_dashboard';
            } else {
                alert('⚠ '+( d.message||'Registration failed'));
            }
        }).catch(function(){btn.disabled=false;btn.textContent='Submit';alert('Network error');});
        return;
    }
    const sp = document.getElementById('kycSpinner');
    if (sp) sp.style.display = 'flex';
    document.getElementById('btnSubmit').disabled = true;
});

// ═══════════════════════════════════════════════════════════════════════
// wizBuildReview — Step 5 Review Panel
// Reads live JS state (hwCart, selPlan, curType) + DOM field values.
// Renders a clean card-based summary into #wReviewContent.
// ═══════════════════════════════════════════════════════════════════════
function wizBuildReview() {
  var el = document.getElementById('wReviewContent');
  if (!el) return;

  // ── Helpers ──────────────────────────────────────────────────────────
  function val(name) {
    var f = document.querySelector('[name="' + name + '"]');
    if (!f) return '';
    if (f.type === 'radio' || f.type === 'checkbox') {
      var checked = document.querySelector('[name="' + name + '"]:checked');
      return checked ? checked.value : '';
    }
    return (f.value || '').trim();
  }
  function fmt(v) { return v || '<span style="color:#9ca3af;font-style:italic;">—</span>'; }
  function money(n) { return <?= json_encode(dn_cur($config)) ?> + parseFloat(n || 0).toFixed(2); }

  // ── Collect values ────────────────────────────────────────────────────
  // BUG FIX: read directly from checked radio, NOT val('customer_type') which
  // can find the hidden #custTypeOut field first and return stale 'StarLink'.
  var _ctRadio   = document.querySelector('input[name="customer_type"]:checked');
  var svcType    = _ctRadio ? _ctRadio.value : (curType === 'fiber' ? 'Fiber' : curType === 'lte' ? 'LTE' : curType === 'sim' ? 'SIM' : 'StarLink');
  var connType   = val('connectivity_type');
  var firstname  = val('firstname');
  var lastname   = val('lastname');
  var fullName   = (firstname + ' ' + lastname).trim();
  var mobile     = val('mobile');
  var email      = val('email');

  // Collect all contacts from the multi-contact form
  var kycContacts = [];
  var contactRows = document.querySelectorAll('.kyc-contact-row');
  contactRows.forEach(function(row) {
    var idx = row.getAttribute('data-idx');
    var ph  = (row.querySelector('input[name="contacts[' + idx + '][phone]"]') || {}).value || '';
    var em  = (row.querySelector('input[name="contacts[' + idx + '][email]"]') || {}).value || '';
    var nm  = (row.querySelector('input[name="contacts[' + idx + '][name]"]') || {}).value || '';
    if (ph || em) kycContacts.push({name: nm, phone: ph, email: em});
  });
  // Fallback: if no multi-contact rows found, use legacy fields
  if (kycContacts.length === 0 && (mobile || email)) {
    kycContacts.push({name: fullName, phone: mobile, email: email});
  }
  // Sync mirrors
  kycSyncContactMirrors();
  var address1   = val('address_1');
  var address2   = val('address_2');
  var custId     = val('customer_id');
  var salesPerson= val('sales_person');
  var payType    = val('sales_type');
  var ref        = val('ref');
  var kitName    = val('kitName');
  var kitNumber  = val('kitNumber');
  var simIccid   = val('sim_iccid');
  var simMsisdn  = val('sim_msisdn');

  // Cart from live JS globals
  var cart     = (typeof hwCart  !== 'undefined') ? hwCart  : [];
  var plan     = (typeof selPlan !== 'undefined') ? selPlan : null;
  var svcKey   = (typeof curType !== 'undefined') ? curType : 'starlink';

  // ── Service colour map ────────────────────────────────────────────────
  var svcColors = {
    StarLink: { bg: '#E3F2FD', text: '#1565C0', icon: '🛰️' },
    Fiber:    { bg: '#E8F5E9', text: '#2E7D32', icon: '🔗' },
    SIM:      { bg: '#FFF3E0', text: '#E65100', icon: '📶' },
    LTE:      { bg: '#F3E5F5', text: '#6A1B9A', icon: '📡' }
  };
  var sc = svcColors[svcType] || { bg: '#f1f5f9', text: '#374151', icon: '📋' };

  // ── Cart totals ───────────────────────────────────────────────────────
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §G): step 5 reads the same lines and total as step 3 — q102Quote(), uCRM's prices only
  var q = q102Quote();
<?php else: ?>
  var hwTotal = 0;
  cart.forEach(function(i){ hwTotal += parseFloat(i.price || 0) * parseInt(i.qty || 1); });
  var planPrice = plan ? parseFloat(plan.price || 0) : 0;
  var installFee = (svcType === 'Fiber' && typeof _kycFiberInstallFee !== 'undefined') ? _kycFiberInstallFee : 0;
  var todayTotal = hwTotal + planPrice + installFee;
<?php endif; ?>

  // ── Section builder ───────────────────────────────────────────────────
  function section(icon, title, rows) {
    var rowsHtml = rows.filter(function(r){ return r; }).map(function(r) {
      return '<div style="display:flex;gap:8px;padding:7px 0;border-bottom:1px solid #f1f5f9;">' +
        '<span style="min-width:130px;font-size:12px;color:#6b7280;font-weight:600;">' + r[0] + '</span>' +
        '<span style="font-size:13px;color:#1e293b;font-weight:700;flex:1;">' + fmt(r[1]) + '</span>' +
        '</div>';
    }).join('');
    return '<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:10px;overflow:hidden;">' +
      '<div style="background:linear-gradient(135deg,#f8fafc,#f1f5f9);padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;">' +
        '<span style="font-size:16px;">' + icon + '</span>' +
        '<span style="font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;">' + title + '</span>' +
      '</div>' +
      '<div style="padding:4px 14px 10px;">' + rowsHtml + '</div>' +
    '</div>';
  }

  // ── Build HTML ────────────────────────────────────────────────────────
  var html = '';

  // Service banner
  html += '<div style="background:' + sc.bg + ';border:1.5px solid ' + sc.text + '33;border-radius:14px;' +
    'padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px;">' +
    '<span style="font-size:28px;">' + sc.icon + '</span>' +
    '<div>' +
      '<div style="font-size:16px;font-weight:900;color:' + sc.text + ';">' + (svcType || '—') + '</div>' +
      '<div style="font-size:12px;color:' + sc.text + ';opacity:.8;">' + (connType || 'New Connection') + '</div>' +
    '</div>' +
    (custId ? '<div style="margin-left:auto;background:' + sc.text + ';color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;">Existing CRM #' + custId + '</div>' : '') +
  '</div>';

  // Customer details + contacts
  var custRows = [
    ['Full Name',  fullName  || (firstname || lastname ? (firstname + ' ' + lastname).trim() : '')],
    ['Address',    address1 + (address2 ? ', ' + address2 : '')],
  ];
  // Add each contact as a row
  kycContacts.forEach(function(c, i) {
    var label = i === 0 ? 'Phone' : 'Phone ' + (i + 1);
    var cName = (c.name && c.name !== fullName) ? ' <span style="color:#6b7280;font-weight:400;">(' + esc(c.name) + ')</span>' : '';
    custRows.push([label, esc(c.phone) + cName]);
    if (c.email) {
      var eLabel = i === 0 ? 'Email' : 'Email ' + (i + 1);
      custRows.push([eLabel, esc(c.email) + cName]);
    }
  });
  html += section('👤', 'Customer', custRows);

  // Hardware order
  if (svcKey !== 'sim' && svcKey !== 'lte') {
    var hwRows = '';
    if (cart.length > 0) {
<?php if ($_q102 !== null): ?>
      // 5.18.102 (Uganda, docs/71 §G): each line as q102Quote() priced it, or "not priced in uCRM" with its reason
      cart.forEach(function(item, ix) {
        var ql = q.kits[ix] || { price: null, cents: 0, qty: 1, reason: '' };
        hwRows += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9;">' +
          '<span style="flex:1;font-size:13px;font-weight:700;color:#1e293b;">' + escHtml(item.title || 'Hardware') + '</span>' +
          '<span style="font-size:12px;color:#6b7280;background:#f1f5f9;padding:2px 8px;border-radius:6px;">×' + ql.qty + '</span>' +
          (ql.price === null
            ? '<span style="font-size:12px;font-weight:800;color:#b45309;min-width:60px;text-align:right;" title="' + escAttr(ql.reason) + '">not priced in uCRM</span>'
            : '<span style="font-size:13px;font-weight:800;color:#D41C1C;min-width:60px;text-align:right;">' + money(ql.cents * ql.qty / 100) + '</span>') +
        '</div>';
        if (ql.price === null) {
          hwRows += '<div style="font-size:11px;color:#b45309;padding:2px 0 6px;">' + escHtml(ql.reason) + '</div>';
        }
      });
      if (cart.length > 1) {
        hwRows += '<div style="display:flex;justify-content:flex-end;padding:6px 0;font-size:12px;font-weight:700;color:#374151;">Hardware subtotal: ' + (q.hwCents === null ? 'not priced in uCRM' : money(q.hwCents / 100)) + '</div>';
      }
<?php else: ?>
      cart.forEach(function(item) {
        var lineTotal = parseFloat(item.price || 0) * parseInt(item.qty || 1);
        hwRows += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9;">' +
          '<span style="flex:1;font-size:13px;font-weight:700;color:#1e293b;">' + (item.title || 'Hardware') + '</span>' +
          '<span style="font-size:12px;color:#6b7280;background:#f1f5f9;padding:2px 8px;border-radius:6px;">×' + (item.qty || 1) + '</span>' +
          '<span style="font-size:13px;font-weight:800;color:#D41C1C;min-width:60px;text-align:right;">' + money(lineTotal) + '</span>' +
        '</div>';
      });
      if (cart.length > 1) {
        hwRows += '<div style="display:flex;justify-content:flex-end;padding:6px 0;font-size:12px;font-weight:700;color:#374151;">Hardware subtotal: ' + money(hwTotal) + '</div>';
      }
<?php endif; ?>
      if (kitName || kitNumber) {
        hwRows += '<div style="font-size:11px;color:#6b7280;padding:4px 0;">Kit: ' + [kitName, kitNumber].filter(Boolean).join(' · ') + '</div>';
      }
    } else {
      hwRows = '<div style="color:#9ca3af;font-style:italic;padding:8px 0;font-size:13px;">No hardware selected</div>';
    }

    html += '<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:10px;overflow:hidden;">' +
      '<div style="background:linear-gradient(135deg,#f8fafc,#f1f5f9);padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;">' +
        '<span style="font-size:16px;">📦</span>' +
        '<span style="font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;">Hardware</span>' +
      '</div>' +
      '<div style="padding:4px 14px 10px;">' + hwRows + '</div>' +
    '</div>';
  }

  // Plan / subscription
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §G): the plan's 1-month price in uCRM, as q102Quote() priced it
  if (plan && plan.name) {
    html += section('📶', 'Monthly Plan', [
      ['Plan',       escHtml(plan.name)],
      ['Monthly Fee', (q.plan && q.plan.price !== null) ? money(q.plan.cents / 100) + '/mo'
        : '<span style="color:#b45309;">not priced in uCRM — ' + escHtml(q.plan ? q.plan.reason : '') + '</span>'],
    ]);
  } else if (svcKey !== 'lte') {
<?php else: ?>
  if (plan && plan.name) {
    html += section('📶', 'Monthly Plan', [
      ['Plan',       plan.name],
      ['Monthly Fee', money(plan.price) + '/mo'],
    ]);
  } else if (svcKey !== 'lte') {
<?php endif; ?>
    html += section('📶', 'Monthly Plan', [
      ['Plan', '<span style="color:#f59e0b;font-style:italic;">No plan selected</span>'],
    ]);
  }

  // SIM details
  if (svcKey === 'sim' && (simIccid || simMsisdn)) {
    html += section('📱', 'SIM Details', [
      simIccid  ? ['ICCID',  simIccid]  : null,
      simMsisdn ? ['MSISDN', simMsisdn] : null,
    ]);
  }

  // Sales & payment
  html += section('🧾', 'Sales Details', [
    ['Sales Person',  salesPerson],
    ['Payment Type',  payType || 'Cash'],
    ref ? ['Referral Source', ref] : null,
  ]);

  // Order total summary box
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §G): the lines and total of q102Quote() — the same as step 3 and ucrm_total_seen; no
  // total at all when any line is not priced in uCRM (all or nothing, as the quotation is)
  if (q.lines.length) {
    var qRow = function (label, amount) {
      return '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">' +
        '<span style="opacity:.85;">' + label + '</span>' + amount + '</div>';
    };
    var qAmt = function (cents) { return '<span style="font-weight:700;">' + money(cents / 100) + '</span>'; };
    var qNo  = '<span style="font-weight:700;color:#fca5a5;">not priced in uCRM</span>';
    html += '<div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:14px;padding:14px 16px;color:#fff;margin-bottom:10px;">' +
      '<div style="font-size:11px;font-weight:700;opacity:.7;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;">💰 Order Summary · uCRM prices</div>';
    if (q.kits.length) html += qRow('Hardware', q.hwCents === null ? qNo : qAmt(q.hwCents));
    if (q.plan) html += qRow('First Month (' + escHtml(q.plan.label) + ')', q.plan.price === null ? qNo : qAmt(q.plan.cents));
    if (q.install) html += qRow('🔧 ' + escHtml(q.install.label), q.install.price === null ? qNo : qAmt(q.install.cents));
    if (q.installBy === 'customer') html += qRow('🔧 Customer installs (kit only)', '<span style="opacity:.7;">no installation line</span>');
    if (q.status === 'priced') {
      html += '<div style="display:flex;justify-content:space-between;font-size:15px;font-weight:900;border-top:1px solid rgba(255,255,255,.25);padding-top:8px;margin-top:4px;">' +
        '<span>Today Total</span><span>' + money(q.total) + '</span></div>';
      if (q.plan && q.plan.price !== null) {
        html += '<div style="font-size:11px;opacity:.65;margin-top:6px;">↻ Then ' + money(q.plan.cents / 100) + '/month recurring</div>';
      }
    } else if (q.status === 'unpriced') {
      html += '<div style="border-top:1px solid rgba(255,255,255,.25);padding-top:8px;margin-top:4px;font-size:12px;font-weight:700;color:#fecaca;">' +
        '⚠ No total from uCRM: ' + escHtml(q102Why(q.reason)) + '. A Credit registration goes in with no quotation; Cash is not possible.</div>';
      if (payType === 'Cash') {
        html += '<div style="font-size:12px;font-weight:800;color:#fca5a5;margin-top:6px;">Payment Type is Cash — choose Credit in step 4 to submit.</div>';
      }
    }
    html += '</div>';
  }
<?php else: ?>
  if (todayTotal > 0 || planPrice > 0) {
    html += '<div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:14px;padding:14px 16px;color:#fff;margin-bottom:10px;">' +
      '<div style="font-size:11px;font-weight:700;opacity:.7;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;">💰 Order Summary</div>';

    if (hwTotal > 0) {
      html += '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">' +
        '<span style="opacity:.85;">Hardware</span><span style="font-weight:700;">' + money(hwTotal) + '</span></div>';
    }
    if (planPrice > 0) {
      html += '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">' +
        '<span style="opacity:.85;">First Month (' + (plan ? plan.name : 'Plan') + ')</span>' +
        '<span style="font-weight:700;">' + money(planPrice) + '</span></div>';
    }
    if (installFee > 0) {
      html += '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">' +
        '<span style="opacity:.85;">🔧 Installation Fee</span>' +
        '<span style="font-weight:700;">' + money(installFee) + '</span></div>';
    }
    if (todayTotal > 0) {
      html += '<div style="display:flex;justify-content:space-between;font-size:15px;font-weight:900;border-top:1px solid rgba(255,255,255,.25);padding-top:8px;margin-top:4px;">' +
        '<span>Today Total</span><span>' + money(todayTotal) + '</span></div>';
    }
    if (planPrice > 0) {
      html += '<div style="font-size:11px;opacity:.65;margin-top:6px;">↻ Then ' + money(planPrice) + '/month recurring</div>';
    }
    html += '</div>';
  }
<?php endif; ?>

  el.innerHTML = html;
}

// Load LTE packages into wizard selector
(function(){
    const TK = document.querySelector('meta[name="api-token"]')?.content || '';
    if(!TK) return;
    fetch('?page=api&action=lte_packages',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
        .then(r=>r.json()).then(function(d){
            const sel=document.getElementById('wLtePkgSel');
            if(!sel||d.status!=='success')return;
            (d.data||[]).filter(p=>p.active!==false).forEach(function(p){
                var opt=document.createElement('option');
                opt.value=p.id;
                opt.textContent=p.name+' — ' + <?= json_encode(dn_cur($config)) ?> +parseFloat(p.price).toFixed(2)+'/'+p.duration_days+'d';
                sel.appendChild(opt);
            });
        }).catch(function(){});
})();
</script>


<?php $pf = $_SESSION['kyc_prefill'] ?? []; unset($_SESSION['kyc_prefill']); ?>
<?php if (!empty($pf['from_lead_id'])): ?>
<div style="background:#e0f2f1;border:1.5px solid #80cbc4;border-radius:10px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">
    <span style="font-size:20px;">&#9989;</span>
    <div>
        <div style="font-weight:800;color:#00695c;font-size:13px;">Pre-filled from Lead #<?= h((string)$pf['from_lead_id']) ?></div>
        <div style="font-size:12px;color:#374151;margin-top:2px;">Verify the details below, upload ID/photo documents, then submit. The KYC form has been pre-filled from the lead record.</div>
    </div>
</div>
<input type="hidden" name="from_lead_id" value="<?= h((string)$pf['from_lead_id']) ?>">
<?php endif; ?>
<form method="POST" enctype="multipart/form-data" id="kycForm" onsubmit="return kycFormSubmit()">
<?= csrfField() ?>
<input type="hidden" name="action" value="kyc_submit">

<!-- ── Persistent hidden fields (OUTSIDE all wiz-panels so they always submit) ── -->
<?php if ($_q103 === null): /* 5.18.103 (root docs/72 §G.5, G-7): never drawn in direct mode — the server sets package_choice, device_id and hw_cart_json itself */ ?>
<input type="hidden" name="package_choice"      id="pkgChoiceOut"  value="">
<input type="hidden" name="device_id"           id="deviceIdOut"   value="">
<input type="hidden" name="hw_cart_json"        id="hwCartOut"     value="[]">
<?php endif; /* 5.18.103 (G-7) */ ?>
<input type="hidden" name="connectivity_type"   id="connTypeOut"   value="New Connection">
<input type="hidden" name="customer_type"       id="custTypeOut"   value="StarLink">
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, §G.6, G-7, G-9): the direct form's own fields. No package_choice, device_id, hw_cart_json or ucrm_links_seen; the plan posts from its own radio (ucrm_plan) and "Who installs?" from its own (install_by); ucrm_cart_json is [{"p":productId,"q":n}]; ucrm_total_seen and ucrm_items_seen (the drawn tokens of the selection, sorted, comma-joined) are '' while there is no total; the marker is the class's constant */ ?>
<input type="hidden" name="ucrm_cart_json"      id="q103CartOut"   value="[]">
<input type="hidden" name="ucrm_total_seen"     id="q102TotalSeen" value="">
<input type="hidden" name="ucrm_items_seen"     id="q103ItemsSeen" value="">
<input type="hidden" name="ucrm_quote_form" value="<?= h(UcrmQuoteLines::DIRECT_MARKER) ?>">
<?php if ($_q103['read'] === 'stale'): /* 5.18.103 (§G.5): the last good list is drawn, marked so; Cash disabled */ ?>
<div id="q103Banner" class="kyc-alert warning" style="display:flex;">
    <i class="bi bi-exclamation-triangle"></i>
    <div><strong>uCRM could not be read — the list may be out of date; Cash is unavailable; Credit registrations still go in.</strong>
    <br><span style="font-size:12px;">Reload the form in a minute to try again.</span></div>
</div>
<?php elseif ($_q103['read'] === 'none'): /* 5.18.103 (§G.5): never read — no list to draw, so nothing to choose */ ?>
<div id="q103Banner" class="kyc-alert warning" style="display:flex;">
    <i class="bi bi-exclamation-triangle"></i>
    <div><strong>uCRM could not be read, and no earlier list of its plans and hardware is kept — there is nothing to choose from, so no registration can be made here until it can.</strong>
    <br><span style="font-size:12px;">Reload the form in a minute to try again.</span></div>
</div>
<?php endif; /* 5.18.103 (§G.5) */ ?>
<?php else: /* 5.18.103: direct mode off — 5.18.102's own fields, unchanged */ ?>
<?php if ($_q102 !== null): /* 5.18.102 (Uganda, docs/71 §G): the total the form showed, posted for the server's check */ ?>
<input type="hidden" name="ucrm_total_seen"     id="q102TotalSeen" value="">
<?php /* 5.18.102 (D14, docs/71 §R.4.7, §R.4.2): the link records that total was made of, and this form's own fixed marker — the sale is 'web' only with it */ ?>
<input type="hidden" name="ucrm_links_seen"     id="q102LinksSeen" value="">
<input type="hidden" name="ucrm_quote_form" value="5.18.102">
<?php if (empty($_q102['ok'])): ?>
<div id="q102Banner" class="kyc-alert warning" style="display:flex;">
    <i class="bi bi-exclamation-triangle"></i>
    <div><strong>uCRM's prices could not be read — Cash sales are not possible until it can; Credit registrations still go in.</strong>
    <br><span style="font-size:12px;">Reload the form in a minute to try again.</span></div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; /* 5.18.103 (§G.5): end of the direct form's fields / 5.18.102's */ ?>

<!-- Wallet warning -->
<div id="cashWarning" class="kyc-alert warning" style="display:none;">
    <i class="bi bi-wallet2"></i>
    <div><strong>Cash payment.</strong> Wallet: <strong><?= dn_cur($config) ?><?= number_format($myWallet['balance'], 2) ?></strong> will be deducted.
    <?php if ($myWallet['balance'] <= 0): ?><br><span style="color:#dc3545;">Wallet empty — ask admin to top up.</span><?php endif; ?>
    </div>
</div>
<script>var _kycWalletBalance = <?= json_encode(round((float)($myWallet['balance'] ?? 0), 2)) ?>;
<?php if ($_q102 === null): /* 5.18.102 (Uganda, docs/71 §D.4): fiber_install_fee is South Sudan's — not even emitted where the gate is on */ ?>var _kycFiberInstallFee = <?= json_encode((float)($config['fiber_install_fee'] ?? 100)) ?>;<?php endif; ?></script>
<?php if ($_q102 !== null): ?>
<script>
// ── 5.18.102 (Uganda, docs/71 §E.1, §G): uCRM's prices and the ONE total ──────────────────────────────────────────
// _q102 is what the page's resolver read (lib/UcrmQuoteLines.php, forPage): per plan and hardware id, uCRM's price
// ('p', null when the row does not resolve) and the reason ('r'); the installation product; whether uCRM could be read.
// q102Quote() is the only place the form's total is computed — step 3's summary, step 5's review, the Cash wallet
// check and ucrm_total_seen all read it. It never reads the plugin's prices, the browser's, or fiber_install_fee.
// 5.18.102 (D14, docs/71 §R.4.7): a cell whose link resolves also carries 'k', its link record id; q102Quote() collects
// the ids of the very selection it totals, and q102Refresh() posts them as ucrm_links_seen beside ucrm_total_seen.
var _q102 = <?= json_encode($_q102, JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null' ?>;
var _q102Cur = <?= json_encode(dn_cur($config)) ?>;
var _Q102_FIBER = <?= json_encode($_q102Fiber) ?>;
var _Q102_UNANSWERED = <?= json_encode(defined('UcrmQuoteLines::NO_INSTALL_ANSWER') ? (string)constant('UcrmQuoteLines::NO_INSTALL_ANSWER') : 'who installs was not answered', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var _q102InstallKey = null;

function q102Money(cents) { return (cents / 100).toFixed(2); }
// The reason as the agent reads it (the unanswered question names where to answer it)
function q102Why(reason) { return reason === _Q102_UNANSWERED ? 'choose who installs in step 3' : String(reason || ''); }
function q102Types() {
  var t = document.querySelector('input[name="customer_type"]:checked');
  var c = document.querySelector('input[name="connectivity_type"]:checked');
  return { type: t ? String(t.value).trim() : '', conn: c ? String(c.value).trim() : '' };
}
// UcrmQuoteLines::needsInstallAnswer: Starlink, other than Shifting Connection and Ownership Change
function q102NeedsInstall() {
  var s = q102Types();
  return s.type.toLowerCase() === 'starlink' && ['Shifting Connection', 'Ownership Change'].indexOf(s.conn) < 0;
}
function q102InstallBy() {
  var r = document.querySelector('input[name="install_by"]:checked');
  return (r && !r.disabled && (r.value === 'dishnet' || r.value === 'customer')) ? r.value : '';
}
// 5.18.102 (D14, docs/71 §R.4.7): a priced cell's link record id ('k', a positive whole number), or null
function q102LinkOf(cell) {
  var k = cell ? cell.k : null;
  return (typeof k === 'number' && isFinite(k) && k > 0 && Math.floor(k) === k) ? k : null;
}
// 5.18.102 (D14): the link record ids of these lines, once each, sorted — the set the server compares (resolve()'s)
function q102LinkSet(lines) {
  var seen = {}, ids = [];
  lines.forEach(function (l) {
    if (l.link !== null && !seen[l.link]) { seen[l.link] = true; ids.push(l.link); }
  });
  ids.sort(function (a, b) { return a - b; });
  return ids;
}

// The form's lines and total, all or nothing like the quotation: status 'no_plan' | 'unpriced' (with the first
// reason, in the resolver's order: Fiber, uCRM unreadable, plan, cart, installation) | 'priced' (total in UGX, 2 dp).
function q102Quote() {
  var d = (_q102 && typeof _q102 === 'object') ? _q102 : { ok: false, reason: "uCRM's products could not be read", plans: {}, kits: {}, install: null };
  var s = q102Types();
  var fiber = s.type.toLowerCase() === 'fiber';
  // 5.18.102 (D14, §R.4.7): links — the link record ids of the selection, set only when the total is priced
  var out = { status: 'priced', reason: '', lines: [], plan: null, kits: [], install: null, installBy: '', hwCents: 0, total: null, links: [] };
  if (fiber) out.reason = _Q102_FIBER;
  else if (!d.ok) out.reason = String(d.reason || "uCRM's products could not be read");
  function line(kind, label, cell, qty) {
    var l = { kind: kind, label: String(label || ''), qty: Math.max(1, parseInt(qty, 10) || 1), price: null, cents: 0, reason: '', link: null };
    if (fiber) l.reason = _Q102_FIBER;
    else if (!d.ok) l.reason = String(d.reason || '');
    else if (!cell || cell.p === null || cell.p === undefined || !(Number(cell.p) > 0)) l.reason = String((cell && cell.r) || (l.label + ' is not priced in uCRM'));
    else { l.price = Number(cell.p); l.cents = Math.round(l.price * 100); l.link = q102LinkOf(cell); }
    if (l.price === null && out.reason === '') out.reason = l.reason;
    out.lines.push(l);
    return l;
  }
  var cartList = (typeof hwCart !== 'undefined' && hwCart) ? hwCart : [];
  var p = (typeof selPlan !== 'undefined') ? selPlan : null;
  if (p) out.plan = line('plan', p.name, (d.plans || {})[String(p.id)], 1);
  cartList.forEach(function (i) { out.kits.push(line('kit', i.title, (d.kits || {})[String(i.id)], i.qty)); });
  out.kits.forEach(function (k) { out.hwCents = (out.hwCents === null || k.price === null) ? null : out.hwCents + k.cents * k.qty; });
  if (q102NeedsInstall()) {
    out.installBy = q102InstallBy();
    if (out.installBy === 'dishnet') {
      var ins = d.install || null;
      out.install = line('install', (ins && ins.l) || 'Professional Installation', ins, 1);
    } else if (out.installBy !== 'customer' && out.reason === '') {
      out.reason = _Q102_UNANSWERED;
    }
  }
  if (!p) { out.status = 'no_plan'; return out; }
  if (out.reason !== '') { out.status = 'unpriced'; return out; }
  var cents = 0;
  out.lines.forEach(function (l) { cents += l.cents * l.qty; });
  out.total = cents / 100;
  // 5.18.102 (D14, docs/71 §R.4.7): the link records of the same lines this total is made of — the selected plan, each
  // cart item, and the installation when DishNet installs
  out.links = q102LinkSet(out.lines);
  return out;
}

// q102Quote(), then what it decides on the page: ucrm_total_seen (a plain decimal, '' when there is no total) and the
// Cash option of step 4 (disabled, with the reason, while the total is not priced)
function q102Refresh() {
  var q = q102Quote();
  var seen = document.getElementById('q102TotalSeen');
  if (seen) seen.value = q.status === 'priced' ? q.total.toFixed(2) : '';
  // 5.18.102 (D14, docs/71 §R.4.7): the selection's link records, sorted and comma-joined — '' when there is no total
  var linksSeen = document.getElementById('q102LinksSeen');
  if (linksSeen) linksSeen.value = q.status === 'priced' ? q.links.join(',') : '';
  var no = q.status === 'unpriced';
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5): direct mode only */ ?>
  // 5.18.103 (§G.5, §G.6): the drawn tokens of the very selection this total is made of, posted as ucrm_items_seen (''
  // while there is no total); and Cash disabled whenever the list on the page is not uCRM's now (the last good list)
  var itemsSeen = document.getElementById('q103ItemsSeen');
  if (itemsSeen) itemsSeen.value = (typeof q103ItemsSeen === 'function') ? q103ItemsSeen(q) : '';
  if (!_q102 || !_q102.ok) no = true;
<?php endif; /* 5.18.103 */ ?>
  var cash = document.querySelector('#wSalesType option[value="Cash"]');
  if (cash) cash.disabled = no;
  var note = document.getElementById('q102CashNote');
  if (note) {
    note.style.display = no ? 'block' : 'none';
    // 5.18.102 (D14, docs/71 §R.4.1): the reason carries its own remedy ("an admin links it…"), so the tail is short
    note.textContent = no ? 'Cash is not possible — the total is not priced in uCRM: ' + q102Why(q.reason) + '. Register as Credit, or ask an admin.' : '';
  }
  return q;
}

// "Who installs?" shown only for Starlink + New Connection; while hidden its inputs are disabled (so nothing posts)
// and never required; the answer is cleared whenever the service or the connection type changes.
function q102InstallSync() {
  var wrap = document.getElementById('q102Install');
  if (!wrap) return;
  var s = q102Types(), key = s.type + '|' + s.conn;
  var need = q102NeedsInstall();
  var clear = (key !== _q102InstallKey) || !need;
  _q102InstallKey = key;
  wrap.style.display = need ? 'block' : 'none';
  wrap.querySelectorAll('input[name="install_by"]').forEach(function (i) {
    i.disabled = !need;
    i.required = false;
    if (clear) i.checked = false;
  });
  if (clear) {
    wrap.querySelectorAll('.wiz-radio-card').forEach(function (c) { c.classList.remove('selected'); });
    var box = document.getElementById('q102InstallBox'), err = document.getElementById('q102InstallErr');
    if (box) box.style.borderColor = '';
    if (err) err.style.display = 'none';
  }
  renderSummary();
}
function q102InstallPick(el) {
  wizRadio(el);
  q102InstallCheck();
  renderSummary();
}
// wizNext (step 3) and kycFormSubmit: false, with a red border and "Choose who installs", while the shown question
// has no answer
function q102InstallCheck() {
  var box = document.getElementById('q102InstallBox'), err = document.getElementById('q102InstallErr');
  var ok = !q102NeedsInstall() || q102InstallBy() !== '';
  if (box) box.style.borderColor = ok ? '' : '#dc3545';
  if (err) err.style.display = ok ? 'none' : 'block';
  return ok;
}
document.addEventListener('DOMContentLoaded', function () {
  // the connection-type cards only call wizRadio(); a change there must also show, hide or clear the question
  document.querySelectorAll('input[type="radio"][name="connectivity_type"]').forEach(function (i) {
    var card = i.closest('label');
    if (card) card.addEventListener('click', function () { q102InstallSync(); });
  });
});
</script>
<?php endif; ?>
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5): direct mode's own script — emitted only in direct mode */ ?>
<script>
// ── 5.18.103 (Uganda, root docs/72 §G.5): the KYC form in direct mode ──────────────────────────────────────────────
// _q102 (above) holds uCRM's own items here, keyed by the direct ids: a plan by "<servicePlanId>:<productId>" (selPlan.id,
// the checked ucrm_plan radio), hardware by the uCRM product id (each hwCart id). q102Quote() totals it exactly as in
// 5.18.102, all or nothing, in integer cents. Each cell carries 't', the item's token, drawn by PHP
// (UcrmQuoteLines::token) — this script only picks the selection's and joins them, it never computes one.
var _Q103_MAX_QTY  = <?= (int)UcrmQuoteLines::MAX_QTY ?>;
var _Q103_MAX_CART = <?= (int)UcrmQuoteLines::MAX_CART ?>;
<?php
// 5.18.103 (G-12): the usable standard quotations, by id — ids and quantities only; their prices are the cells above
$_q103StdJs = [];
foreach ($_q103['standards'] as $_q103S) {
    if (!$_q103S['ok'] || $_q103S['id'] <= 0) continue;
    $_q103StdJs[(string)$_q103S['id']] = ['title' => $_q103S['title'], 'plan' => $_q103S['plan'], 'hw' => $_q103S['hw'],
                                          'install' => $_q103S['install']];
}
?>
var _q103Std = <?= json_encode((object)$_q103StdJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;

// ucrm_cart_json: the cart as [{"p":productId,"q":n}] — never a price, never a plugin id (G-7)
function q103SyncCart() {
  var el = document.getElementById('q103CartOut');
  if (!el) return;
  var out = [];
  ((typeof hwCart !== 'undefined' && hwCart) ? hwCart : []).forEach(function (i) {
    var p = parseInt(i.id, 10), q = parseInt(i.qty, 10);
    if (p > 0 && q >= 1) out.push({ p: p, q: Math.min(_Q103_MAX_QTY, q) });
  });
  el.value = JSON.stringify(out);
}

// ucrm_items_seen: the drawn tokens of the selected items — the plan, each hardware item, and the installation when
// DishNet installs (q.install, as q102Quote() decided it) — once each, sorted, comma-joined; '' while there is no total,
// or when any selected item has no drawn token (the server then refuses a Cash sale rather than guess)
function q103ItemsSeen(q) {
  if (!q || q.status !== 'priced') return '';
  var d = (_q102 && typeof _q102 === 'object') ? _q102 : {};
  var toks = [], bad = false;
  function add(cell) {
    var t = (cell && typeof cell.t === 'string' && /^[0-9a-f]{16}$/.test(cell.t)) ? cell.t : null;
    if (t === null) { bad = true; return; }
    if (toks.indexOf(t) < 0) toks.push(t);
  }
  if (typeof selPlan !== 'undefined' && selPlan) add((d.plans || {})[String(selPlan.id)]);
  ((typeof hwCart !== 'undefined' && hwCart) ? hwCart : []).forEach(function (i) { add((d.kits || {})[String(i.id)]); });
  if (q.install) add(d.install);
  if (bad) return '';
  toks.sort();
  return toks.join(',');
}

// A standard quotation (G-12): selects its plan, sets the cart to its hardware and quantities, and answers "Who installs?"
// with DishNet when it includes the installation — otherwise the question is left open (unanswered) for the agent. It
// never submits: the agent still checks and submits. Only usable ones have a live button; this re-checks every row.
function q103Standard(id) {
  var s = _q103Std[String(id)];
  if (!s || curType !== 'starlink') return;
  var planRow = null;
  document.querySelectorAll('.pkg-opt').forEach(function (p) { if (p.dataset.id === String(s.plan)) planRow = p; });
  var cart = [], missing = !planRow;
  (s.hw || []).forEach(function (h) {
    var row = null;
    document.querySelectorAll('.hw-row').forEach(function (r) { if (r.dataset.id === String(h.p)) row = r; });
    if (!row) { missing = true; return; }
    cart.push({ id: row.dataset.id, title: row.dataset.title, price: pNum(row.dataset.price),
                qty: Math.min(_Q103_MAX_QTY, Math.max(1, parseInt(h.q, 10) || 1)) });
  });
  if (missing) { alert('This standard quotation cannot be used on this form now — reload the form.'); return; }
  hwCart = cart;
  var wrap = document.getElementById('q102Install');
  var asked = !!wrap && q102NeedsInstall();
  if (asked) {
    wrap.querySelectorAll('input[name="install_by"]').forEach(function (i) { i.checked = false; });
    wrap.querySelectorAll('.wiz-radio-card').forEach(function (c) { c.classList.remove('selected'); });
    var box = document.getElementById('q102InstallBox'), err = document.getElementById('q102InstallErr');
    if (box) box.style.borderColor = '';
    if (err) err.style.display = 'none';
    if (s.install) {
      var r = wrap.querySelector('input[name="install_by"][value="dishnet"]');
      var card = r ? r.closest('label') : null;
      if (card) wizRadio(card);
    }
  }
  pkgPick(planRow);
  hwHighlightAll();
  renderCart();
  syncOuterFields();
  renderSummary();
  var note = document.getElementById('q103StdNote');
  if (note) {
    note.style.display = 'block';
    note.textContent = 'Filled from "' + String(s.title || '') + '" — check the items'
      + (asked && !s.install ? ', choose who installs' : '') + ', then continue. Nothing is submitted until you submit.';
  }
}
</script>
<?php endif; /* 5.18.103 (§G.5): end of direct mode's script */ ?>

<div class="wiz-container">

<!-- Progress Steps -->
<div class="wiz-progress" id="wizProgress">
    <div class="wiz-step-wrap"><div class="wiz-step-dot active" data-step="1">1</div><div class="wiz-step-label active">Service</div></div>
    <div class="wiz-step-line"></div>
    <div class="wiz-step-wrap"><div class="wiz-step-dot" data-step="2">2</div><div class="wiz-step-label">Customer</div></div>
    <div class="wiz-step-line"></div>
    <div class="wiz-step-wrap"><div class="wiz-step-dot" data-step="3">3</div><div class="wiz-step-label">Plan</div></div>
    <div class="wiz-step-line"></div>
    <div class="wiz-step-wrap"><div class="wiz-step-dot" data-step="4">4</div><div class="wiz-step-label">KYC</div></div>
    <div class="wiz-step-line"></div>
    <div class="wiz-step-wrap"><div class="wiz-step-dot" data-step="5">5</div><div class="wiz-step-label">Review</div></div>
</div>

<!-- ═══ STEP 1: Service Type ═══════════════════════════════════════════ -->
<div class="wiz-panel active" data-panel="1">
<div class="kyc-card">
<div class="kyc-card-body">
    <div class="wiz-section-title"><i class="bi bi-broadcast"></i> What service does the customer need?</div>

    <div class="wiz-field">
        <label>Connection Type</label>
        <div class="wiz-radio-group">
            <label class="wiz-radio-card selected" onclick="wizRadio(this)">
                <input type="radio" name="connectivity_type" value="New Connection" checked>
                <div class="wrc-icon">🆕</div><div class="wrc-title">New Connection</div>
            </label>
            <label class="wiz-radio-card" onclick="wizRadio(this)">
                <input type="radio" name="connectivity_type" value="Shifting Connection">
                <div class="wrc-icon">🔄</div><div class="wrc-title">Shifting</div>
            </label>
            <label class="wiz-radio-card" onclick="wizRadio(this)">
                <input type="radio" name="connectivity_type" value="Ownership Change">
                <div class="wrc-icon">👤</div><div class="wrc-title">Transfer</div>
            </label>
        </div>
    </div>

    <div class="wiz-field">
        <label>Service Type</label>
        <div class="wiz-radio-group" id="serviceTypeGroup">
            <label class="wiz-radio-card selected" onclick="wizRadio(this);wizTypeChange()">
                <input type="radio" name="customer_type" value="StarLink" checked>
                <div class="wrc-icon">📡</div><div class="wrc-title">Starlink</div><div class="wrc-sub">Satellite Internet</div>
            </label>
<?php if ($_q103 === null): /* 5.18.103 (root docs/72 §G.5): the Fiber card is not drawn in direct mode — Uganda has no Fiber plan to offer (QL-G1: 0 Fiber plan rows) */ ?>
            <label class="wiz-radio-card" onclick="wizRadio(this);wizTypeChange()">
                <input type="radio" name="customer_type" value="Fiber">
                <div class="wrc-icon">🔌</div><div class="wrc-title">Fiber</div><div class="wrc-sub">FTTH Connection</div>
            </label>
<?php endif; /* 5.18.103 (§G.5): end of the Fiber card */ ?>
            <label class="wiz-radio-card" onclick="wizRadio(this);wizTypeChange()">
                <input type="radio" name="customer_type" value="LTE">
                <div class="wrc-icon">📶</div><div class="wrc-title">DishNet 4G</div><div class="wrc-sub">Magma Network</div>
            </label>
        </div>
    </div>

    <!-- Service summary bar -->
    <div id="serviceSummary" style="background:#E3F2FD;border-radius:10px;padding:12px 16px;margin-top:8px;display:flex;align-items:center;gap:12px;">
        <i class="bi bi-info-circle" style="color:#D41C1C;font-size:16px;"></i>
        <div id="serviceSummaryText" style="font-size:12px;color:#D41C1C;font-weight:600;">Starlink: Hardware + Plan + Address + KYC documents</div>
    </div>

    <div class="wiz-nav">
        <button type="button" class="wiz-btn wiz-btn-next" onclick="wizNext()">Continue <i class="bi bi-arrow-right"></i></button>
    </div>
</div>
</div>
</div>

<!-- ═══ STEP 2: Customer Details + Address ═════════════════════════════ -->
<div class="wiz-panel" data-panel="2">
<div class="kyc-card">
<div class="kyc-card-body">
    <div class="wiz-section-title"><i class="bi bi-person"></i> Customer Information</div>
    <div class="wiz-row">
        <div class="wiz-field"><label>First Name <span class="req">*</span></label><input type="text" name="firstname" placeholder="First name" required value="<?= h($pf['firstname']??'') ?>"></div>
        <div class="wiz-field"><label>Last Name <span class="req">*</span></label><input type="text" name="lastname" placeholder="Last name" required value="<?= h($pf['lastname']??'') ?>" onblur="kycCheckName()"></div>
    </div>
    <!-- ── Multi-contact section (v4.9.20) ──────────────────────────── -->
    <!-- Hidden mirrors for backward compat — post_kyc.php reads $_POST['mobile'] & $_POST['email'] -->
    <input type="hidden" name="mobile" id="kycMobileMirror" value="<?= h($pf['mobile']??'') ?>">
    <input type="hidden" name="email" id="kycEmailMirror" value="<?= h($pf['email']??'') ?>">

    <div class="wiz-section-title" style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;">
        <span><i class="bi bi-telephone"></i> Contacts</span>
        <button type="button" onclick="kycAddContact()" style="background:#10b981;color:#fff;border:none;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;">+ Add Contact</button>
    </div>
    <div id="kycContactsWrap">
        <!-- Contact 0 — primary (required) -->
        <div class="kyc-contact-row" data-idx="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Primary contact</span>
            </div>
            <div class="wiz-row" style="margin-bottom:0;">
                <div class="wiz-field"><label>Phone <span class="req">*</span></label><input type="tel" name="contacts[0][phone]" id="kycPhoneInput" placeholder="+211 9XX XXX XXX" required value="<?= h($pf['mobile']??'') ?>" onchange="kycSyncContactMirrors()" onblur="kycCheckPhone(this.value)"></div>
                <div class="wiz-field"><label>Email</label><input type="email" name="contacts[0][email]" placeholder="customer@email.com" value="<?= h($pf['email']??'') ?>" onchange="kycSyncContactMirrors()"></div>
            </div>
            <div class="wiz-row" style="margin-bottom:0;">
                <div class="wiz-field"><label>Contact name</label><input type="text" name="contacts[0][name]" placeholder="Same as customer" value=""></div>
            </div>
        </div>
    </div>

    <!-- ── Duplicate check banner — shown by JS after phone blur ── -->
    <div id="kycDupBanner" style="display:none;border-radius:12px;padding:12px 14px;margin-bottom:10px;"></div>

    <!-- Hidden fields for duplicate handling -->
    <input type="hidden" name="duplicate_confirmed" id="kycDupConfirmed" value="0">
    <input type="hidden" name="duplicate_crm_id"    id="kycDupCrmId"    value="">
    <input type="hidden" name="duplicate_note"      id="kycDupNote"     value="">

    <div class="wiz-row">
        <div class="wiz-field"><label>Date</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
        <!-- v4.21.99: Existing Customer ID field removed — every registration is a new customer. Hidden input kept so the JS that reads/clears it does not crash. --><input type="hidden" name="customer_id" id="kycCustomerId" value="">
    </div>

    <div class="wiz-section-title" style="margin-top:20px;"><i class="bi bi-geo-alt"></i> Service Address</div>
    <div class="wiz-field"><label>Address <span class="req">*</span></label><input type="text" name="address_1" placeholder="Street address, building, area" required value="<?= h($pf['address_1']??'') ?>"></div>
    <div class="wiz-field"><label>Address Line 2</label><input type="text" name="address_2" placeholder="Apartment, floor, landmark" value="<?= h($pf['address_2']??'') ?>"></div>

    <div class="wiz-field" id="wAreaField" style="display:none;">
        <label>Area <span class="req">*</span> <span style="font-size:10px;font-weight:400;color:#6b7280;">(Fiber only — required for NOC dispatch)</span></label>
        <!-- Searchable area combobox — pin pill style -->
        <div style="position:relative;" id="wAreaComboWrap">
            <div style="position:relative;display:flex;align-items:center;">
                <span style="position:absolute;left:14px;font-size:16px;pointer-events:none;z-index:1;">📍</span>
                <input type="text"
                       id="wAreaSearch"
                       autocomplete="off"
                       placeholder="Search area… Gudele, Kator, Buluk"
                       style="width:100%;padding:11px 14px 11px 38px;border:2px solid #E2E8F0;border-radius:30px;font-size:14px;font-weight:600;box-sizing:border-box;background:#fafafa;transition:border-color .15s,background .15s;"
                       oninput="areaFilter(this.value)"
                       onfocus="areaDropShow();this.style.background='#fff';this.style.borderColor='#D41C1C';"
                       onblur="setTimeout(areaDropHide,200);if(!document.getElementById('wFiberArea').value)this.style.borderColor='#E2E8F0';"
                       value="<?= h($pf['fiber_area'] ?? '') ?>">
            </div>
            <!-- Hidden field that actually submits -->
            <input type="hidden" name="fiber_area" id="wFiberArea" value="<?= h($pf['fiber_area'] ?? '') ?>">
            <!-- Dropdown list -->
            <div id="wAreaDrop" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid #eee;border-radius:14px;max-height:220px;overflow-y:auto;z-index:9999;box-shadow:0 8px 28px rgba(0,0,0,.13);">
                <?php
                $jubaAreas = ['Juba Town','Hai Jerusalem','Hai Mayo','Hai Gonyo','Hai Tarawa',
                    'Hai Darussalam','Hai Referendum','Hai Mauna','St Kizito',
                    'Munuki Libya','Munuki Melissa','New Site','Mangaten','Thongping',
                    'Kololo','Hai Amarat','Hai Jalaba','Hai Cinema','Hai Seminary',
                    'Hai Malakal','Buluk','Hai Thoura','Custom','Nyakuron West',
                    'Nyakuron East','Rock City','Gudele 1','Gudele 2','Jebel Yesua',
                    'Jebel','Gurei','Konyokonyo','Hai Kuwait','Mia Saba','Lologo',
                    'Kor William','Kator','Atlabara','Melikia','Hai Neem',
                    'Gumbo Market','Gumbo Shirkat','Hai Jaborona','Hai Nimra Talata',
                    'Hai Gabat','Jondoru','Kasire','Gbongoroki','Hai Game','Joppa'];
                foreach ($jubaAreas as $a):
                ?>
                <div class="area-opt" data-val="<?= h($a) ?>"
                     style="padding:10px 14px;font-size:14px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
                     onmousedown="areaSelect('<?= h(addslashes($a)) ?>')">
                    <?= h($a) ?>
                </div>
                <?php endforeach; ?>
                <?php
                // Custom areas added by agents
                $customAreas = $store->load('kyc_custom_areas.json') ?? [];
                foreach ($customAreas as $ca):
                    $caName = $ca['name'] ?? '';
                    if (!$caName) continue;
                ?>
                <div class="area-opt" data-val="<?= h($caName) ?>"
                     style="padding:10px 14px;font-size:14px;cursor:pointer;border-bottom:1px solid #f1f5f9;background:#fffbeb;"
                     onmousedown="areaSelect('<?= h(addslashes($caName)) ?>')">
                    <?= h($caName) ?> <span style="font-size:10px;color:#d97706;font-weight:700;">custom</span>
                </div>
                <?php endforeach; ?>
                <!-- "Add new area" row — shown when no match -->
                <div id="wAreaAddNew" style="display:none;padding:10px 14px;font-size:13px;color:#D41C1C;font-weight:700;cursor:pointer;background:#fff5f5;border-radius:0 0 10px 10px;"
                     onmousedown="areaAddNew()">
                    ➕ Add "<span id="wAreaAddLabel"></span>" as new area
                </div>
            </div>
        </div>
        <!-- Confirmed area pill (hidden — input itself acts as pill when selected) -->
        <div style="margin-top:0;">
            <span id="wAreaPill" style="display:none;align-items:center;gap:5px;background:#D41C1C;color:#fff;font-size:12px;font-weight:800;padding:4px 12px;border-radius:20px;letter-spacing:.2px;">
                📍 <span id="wAreaPillText"></span>
                <span onclick="areaSelect('')" style="cursor:pointer;opacity:.7;font-weight:400;margin-left:2px;font-size:14px;line-height:1;">×</span>
            </span>
        </div>
        <div id="wAreaNewBadge" style="display:none;margin-top:6px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:6px 12px;font-size:12px;color:#92400e;">
            ⚠ New area — will be added to the system after submission
        </div>
    </div>

    <div class="wiz-row">
        <div class="wiz-field"><label>Latitude</label><input type="number" name="latitude" step="0.0000001" placeholder="Auto-detect" id="wLat"></div>
        <div class="wiz-field"><label>Longitude</label><input type="number" name="longitude" step="0.0000001" placeholder="Auto-detect" id="wLng"></div>
    </div>
    <div style="text-align:center;margin-bottom:4px;">
        <button type="button" onclick="wizDetectLoc()" style="background:#fff5f5;border:1.5px solid #D41C1C;border-radius:8px;padding:8px 20px;color:#D41C1C;font-weight:700;font-size:12px;cursor:pointer;"><i class="bi bi-crosshair"></i> Detect GPS</button>
    </div>

    <div class="wiz-nav">
        <button type="button" class="wiz-btn wiz-btn-back" onclick="wizBack()"><i class="bi bi-arrow-left"></i> Back</button>
        <button type="button" class="wiz-btn wiz-btn-next" onclick="wizNext()">Continue <i class="bi bi-arrow-right"></i></button>
    </div>
</div>
</div>
</div>

<!-- ═══ STEP 3: Smart Order Builder ════════════════════════════════════ -->
<div class="wiz-panel" data-panel="3">
<div class="kyc-card">
<div class="kyc-card-body" style="padding:0;">

<!-- ══ KIT SCAN BLOCK — Starlink only, always shown at top of step 3 ═══ -->
<div id="wKitScanBlock" style="display:none;padding:14px 14px 0;">
  <div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:14px;padding:16px;border:1px solid #2A2A2A;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
      <span style="font-size:26px;">📡</span>
      <div>
        <div style="font-size:14px;font-weight:800;color:#fff;letter-spacing:-.2px;line-height:1.2;">Scan Starlink Kit Label</div>
        <div style="font-size:11px;color:#888;margin-top:3px;">Point at the barcode on the dish box — serial fills automatically</div>
      </div>
    </div>
    <button type="button" id="btnKitScan" onclick="smartKitScan()" style="display:flex;align-items:center;justify-content:center;gap:10px;background:#D41C1C;color:#fff;border:none;border-radius:12px;padding:15px;font-size:16px;font-weight:800;cursor:pointer;width:100%;box-sizing:border-box;-webkit-tap-highlight-color:transparent;">
      <i class="bi bi-upc-scan" style="font-size:20px;"></i> Scan Kit Label
    </button>
    <input type="file" id="kitPhotoInput" name="kit_image" accept="image/*" capture="environment" style="display:none;" onchange="kitScanPhoto(this)">
    <div id="kitScanStatus" style="font-size:12px;color:#888;text-align:center;margin-top:8px;min-height:18px;"></div>
    <div id="kitScanPreview" style="display:none;margin-top:10px;">
      <img id="kitScanThumb" style="width:100%;max-height:160px;object-fit:contain;border-radius:10px;border:1px solid #333;" src="">
      <div id="kitOcrResult" style="margin-top:8px;background:#0D0D0D;border-radius:8px;padding:10px 12px;font-size:12px;color:#aaa;line-height:1.6;max-height:120px;overflow-y:auto;"></div>
    </div>
    <div style="margin-top:12px;">
      <div style="font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Kit Serial Details</div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <input type="text" id="kitNameDisplay" placeholder="Serial / Kit Name  e.g. STML-00234"
          style="background:#262626;border:1.5px solid #333;border-radius:10px;padding:12px;font-size:14px;color:#fff;outline:none;width:100%;box-sizing:border-box;"
          oninput="document.getElementById('hwKitName').value=this.value"
          onfocus="this.style.borderColor='#D41C1C'" onblur="this.style.borderColor='#333'">
        <input type="text" id="kitNumberDisplay" placeholder="Tracking / Box Number"
          style="background:#262626;border:1.5px solid #333;border-radius:10px;padding:12px;font-size:14px;color:#fff;outline:none;width:100%;box-sizing:border-box;"
          oninput="document.getElementById('hwKitNumber').value=this.value"
          onfocus="this.style.borderColor='#D41C1C'" onblur="this.style.borderColor='#333'">
      </div>
    </div>
  </div>
</div>

<?php if ($_q102 !== null): /* 5.18.102 (Uganda, docs/71 §E.1): "Who installs?" — Starlink + New Connection only; never preselected, never required */ ?>
<!-- ══ WHO INSTALLS — Starlink New Connection (5.18.102) ══════════════ -->
<div id="q102Install" style="display:none;padding:14px 14px 0;">
  <div id="q102InstallBox" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:12px 14px;">
    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
      <i class="bi bi-tools" style="color:#D41C1C;"></i> Who installs? <span style="color:#dc3545;">*</span>
    </div>
    <div class="wiz-radio-group">
      <label class="wiz-radio-card" onclick="q102InstallPick(this)"<?php if ($_q102['install']['p'] === null): ?> title="<?= h((string)$_q102['install']['r']) ?>"<?php endif; ?><?php if (isset($_q102['install']['k'])): /* 5.18.102 (D14, §R.4.7): the installation's link record */ ?> data-link="<?= (int)$_q102['install']['k'] ?>"<?php endif; ?>>
        <input type="radio" name="install_by" value="dishnet" disabled>
        <div class="wrc-title">DishNet installs</div>
<?php if ($_q102['install']['p'] === null): ?>
        <div class="wrc-sub" style="color:#b45309;">Installation not priced in uCRM — no quotation, Cash not possible</div>
        <div class="q102-reason" style="font-size:10px;color:#b45309;margin-top:2px;white-space:normal;"><?= h((string)$_q102['install']['r']) ?></div>
<?php else: ?>
        <div class="wrc-sub"><?= h((string)$_q102['install']['l']) ?> · <?= dn_cur($config) ?><?= number_format((float)$_q102['install']['p'], 2) ?></div>
<?php endif; ?>
      </label>
      <label class="wiz-radio-card" onclick="q102InstallPick(this)">
        <input type="radio" name="install_by" value="customer" disabled>
        <div class="wrc-title">Customer installs (kit only)</div>
        <div class="wrc-sub">No installation line</div>
      </label>
    </div>
    <div id="q102InstallErr" style="display:none;color:#dc3545;font-size:12px;font-weight:700;margin-top:8px;">Choose who installs</div>
  </div>
</div>
<?php endif; ?>
<!-- ══ STICKY ORDER SUMMARY ══════════════════════════════════════════ -->
<div id="osBar" style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:14px 14px 0 0;padding:14px 16px 12px;color:#fff;position:sticky;top:0;z-index:10;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
    <span style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;opacity:.8;">🛒 Order</span>
    <span id="osTotalBadge" style="background:rgba(255,255,255,.18);border-radius:20px;padding:4px 14px;font-size:15px;font-weight:900;letter-spacing:-.3px;"><?= dn_cur($config) ?>0.00</span>
  </div>
  <div id="osLines" style="display:flex;flex-direction:column;gap:4px;">
    <div style="font-size:12px;opacity:.55;font-style:italic;">← select hardware &amp; plan below</div>
  </div>
  <div id="osRecurring" style="display:none;border-top:1px solid rgba(255,255,255,.2);margin-top:8px;padding-top:7px;font-size:11px;opacity:.75;"></div>
</div>
<div style="padding:14px 14px 0;">

<!-- ══ HARDWARE CATALOGUE ════════════════════════════════════════════ -->
<div id="wHardwareSection">
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, G-12): above the lists, one button per standard quotation an admin defined, priced live (UcrmQuoteLines::standardFrom); pressing one fills the plan, the hardware and "Who installs?", and never submits; one that cannot be used now is disabled with its reason. Then how many uCRM items this form does not offer, and where they are listed */ ?>
  <div id="q103Standards" style="margin-bottom:12px;">
<?php if ($_q103['standards'] !== []): ?>
    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
      <i class="bi bi-lightning-charge" style="color:#D41C1C;"></i> Standard quotations
      <span style="font-size:10px;font-weight:600;color:#D41C1C;background:#EFF6FF;padding:1px 8px;border-radius:10px;">one tap fills the order</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;">
<?php foreach ($_q103['standards'] as $q103S): $q103On = $q103S['ok'] && $q103S['total'] !== null; ?>
      <button type="button" class="q103-std" data-std="<?= (int)$q103S['id'] ?>"<?php if (!$q103On): ?> disabled title="<?= h($q103S['reason']) ?>"<?php endif; ?> onclick="q103Standard(<?= (int)$q103S['id'] ?>)"
              style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;width:100%;text-align:left;padding:11px 14px;background:<?= $q103On ? '#fff' : '#f8fafc' ?>;border:1.5px solid <?= $q103On ? '#D41C1C' : '#e2e8f0' ?>;border-radius:12px;cursor:<?= $q103On ? 'pointer' : 'not-allowed' ?>;font-family:inherit;">
        <span style="font-size:13px;font-weight:800;color:<?= $q103On ? '#1e293b' : '#94a3b8' ?>;"><?= h($q103S['title']) ?></span>
<?php if ($q103On): $q103Tot = (float)$q103S['total']; ?>
        <span style="font-size:11px;color:#6b7280;"><?= dn_cur($config) ?><?= number_format($q103Tot, abs($q103Tot - round($q103Tot)) < 0.005 ? 0 : 2) ?><?= $q103S['install'] ? ' · includes installation' : '' ?></span>
<?php else: ?>
        <span class="q103-reason" style="font-size:11px;color:#b45309;white-space:normal;">Cannot be used now: <?= h($q103S['reason']) ?></span>
<?php endif; ?>
      </button>
<?php endforeach; ?>
    </div>
    <div id="q103StdNote" style="display:none;font-size:12px;font-weight:700;color:#166534;margin-top:6px;"></div>
<?php endif; ?>
<?php $q103NotN = (int)$_q103['not_plans'] + (int)$_q103['not_products']; if ($q103NotN > 0): ?>
    <div class="q103-not-offered" style="font-size:11px;color:#64748b;margin-top:8px;line-height:1.4;">
      <?= (int)$_q103['not_plans'] ?> uCRM service plan<?= (int)$_q103['not_plans'] === 1 ? '' : 's' ?> and <?= (int)$_q103['not_products'] ?> uCRM product<?= (int)$_q103['not_products'] === 1 ? '' : 's' ?> are not offered on this form.
<?php if (!empty($_q103['admin'])): ?>
      The <a href="?page=dashboard&amp;tab=ucrm_catalogue" style="color:#D41C1C;font-weight:700;">uCRM catalogue</a> screen lists them, and why.
<?php else: ?>
      An admin sees them, and why, on the uCRM catalogue screen.
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
<?php endif; /* 5.18.103 (§G.5): end of the standard quotations */ ?>
  <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
    <i class="bi bi-hdd-network" style="color:#D41C1C;"></i> Hardware
    <span style="font-size:10px;font-weight:600;color:#D41C1C;background:#EFF6FF;padding:1px 8px;border-radius:10px;">tap + to add</span>
  </div>

  <!-- Catalogue rows rendered by PHP, typed for JS filtering -->
  <div id="wHardwareList" style="display:flex;flex-direction:column;gap:6px;">
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, G-5): uCRM's own hardware — one row per offered product, data-id the uCRM product id, kits before accessories (the website's split, for display only), uCRM's price; the plugin's kit rows are not drawn. The row's token rides beside it (data-token), as in its cell */ ?>
<?php $q103Grp = ''; foreach ($_q103['hardware'] as $q103H): $q103Pr = (float)$q103H['price']; ?>
<?php if ($q103H['group'] !== $q103Grp): $q103Grp = $q103H['group']; ?>
  <div class="q103-group" style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin:4px 2px 0;"><?= $q103Grp === 'accessory' ? 'Accessories' : 'Kits' ?></div>
<?php endif; ?>
  <div class="hw-row" data-id="<?= (int)$q103H['p'] ?>" data-title="<?= h($q103H['label']) ?>"
       data-price="<?= h(number_format($q103Pr, 2, '.', '')) ?>" data-type="starlink" data-group="<?= h($q103H['group']) ?>" data-token="<?= h($q103H['token']) ?>"
       style="display:none;align-items:center;gap:10px;padding:11px 14px;background:#fff;border-radius:12px;border:1.5px solid #e2e8f0;cursor:pointer;transition:border-color .12s,background .12s;"
       onclick="hwAdd(this)">
    <div style="flex:1;min-width:0;">
      <div style="font-weight:700;font-size:13px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h($q103H['label']) ?></div>
      <div style="font-size:11px;color:#94a3b8;margin-top:1px;"><?= $q103H['group'] === 'accessory' ? 'Accessory' : 'Kit' ?> · uCRM</div>
    </div>
    <div style="font-weight:800;color:#D41C1C;font-size:14px;white-space:nowrap;margin-right:2px;"><?= dn_cur($config) ?><?= number_format($q103Pr, abs($q103Pr - round($q103Pr)) < 0.005 ? 0 : 2) ?></div>
    <div style="width:32px;height:32px;background:#D41C1C;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;font-weight:300;pointer-events:none;">+</div>
  </div>
<?php endforeach; ?>
<?php if ($_q103['hardware'] === [] && $_q103['read'] !== 'none'): ?>
  <div style="font-size:12px;color:#9ca3af;font-style:italic;padding:6px 2px;">No hardware is offered from uCRM.</div>
<?php endif; ?>
<?php else: /* 5.18.103: direct mode off — 5.18.102's kit rows, unchanged */ ?>
  <?php
  $activeDevices = array_values(array_filter($devices, fn($d) => !empty($d['is_active'])));
  foreach ($activeDevices as $d):
    $dtype = strtolower($d['type'] ?? 'general');
    $dnum  = (float)preg_replace('/[^0-9.]/','',$d['price']??'0');
    // 5.18.102 (Uganda, docs/71 §G): the row's price is uCRM's (never the plugin's); one that does not resolve is
    // drawn "not priced in uCRM" with its reason, and carries 0 so it adds nothing
    $q102Cell = $_q102 !== null ? $_q102Of('kits', $d['id'] ?? '') : null;
    if ($q102Cell !== null) $dnum = $q102Cell['p'] !== null ? (float)$q102Cell['p'] : 0.0;
    // 5.18.102 (D14, docs/71 §R.5, §R.4.7): a row whose link resolves is drawn with uCRM's product name — its text and
    // data-title, and so the cart, the summary and hw_cart_json — and its link record as data-link; any other row with the
    // plugin's own title and the reason. Where the gate is off: the plugin's title and no data-link, as before.
    $q102Resolves = $q102Cell !== null && $q102Cell['p'] !== null;
    $q102Title    = ($q102Resolves && isset($q102Cell['l'])) ? $q102Cell['l'] : $d['title'];
    $q102LinkAttr = ($q102Resolves && isset($q102Cell['k'])) ? ' data-link="' . (int)$q102Cell['k'] . '"' : '';
    // 5.18.102 D14 review (D14-UI-5): the row's own title, for applyDefaults' keyword — never uCRM's name (gate on only)
    $q102RowAttr  = $_q102 !== null ? ' data-row-title="' . h((string)$d['title']) . '"' : '';
  ?>
  <div class="hw-row" data-id="<?= $d['id'] ?>"<?= $q102LinkAttr ?><?= $q102RowAttr ?> data-title="<?= h($q102Title) ?>"
       data-price="<?= $dnum ?>" data-type="<?= h($dtype) ?>" data-sku="<?= h($d['sku']??'') ?>"
       style="display:none;align-items:center;gap:10px;padding:11px 14px;background:#fff;border-radius:12px;border:1.5px solid #e2e8f0;cursor:pointer;transition:border-color .12s,background .12s;"
       onclick="hwAdd(this)">
    <div style="flex:1;min-width:0;">
      <div style="font-weight:700;font-size:13px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h($q102Title) ?></div>
      <div style="font-size:11px;color:#94a3b8;margin-top:1px;text-transform:capitalize;"><?= h($dtype) ?><?= !empty($d['sku'])?' · '.$d['sku']:'' ?></div>
<?php if ($q102Cell !== null && $q102Cell['p'] === null): ?>
      <div class="q102-reason" style="font-size:10px;color:#b45309;margin-top:2px;white-space:normal;"><?= h((string)$q102Cell['r']) ?></div>
<?php endif; ?>
    </div>
<?php if ($q102Cell !== null && $q102Cell['p'] === null): ?>
    <div class="q102-unpriced" style="font-weight:800;color:#b45309;font-size:12px;white-space:nowrap;margin-right:2px;" title="<?= h((string)$q102Cell['r']) ?>">not priced in uCRM</div>
<?php elseif ($q102Cell !== null): /* uCRM's price as it is: its decimals shown when it has any */ ?>
    <div style="font-weight:800;color:#D41C1C;font-size:14px;white-space:nowrap;margin-right:2px;"><?= dn_cur($config) ?><?= number_format($dnum, abs($dnum - round($dnum)) < 0.005 ? 0 : 2) ?></div>
<?php else: ?>
    <div style="font-weight:800;color:#D41C1C;font-size:14px;white-space:nowrap;margin-right:2px;"><?= dn_cur($config) ?><?= number_format($dnum,0) ?></div>
<?php endif; ?>
    <div style="width:32px;height:32px;background:#D41C1C;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;font-weight:300;pointer-events:none;">+</div>
  </div>
  <?php endforeach; ?>
<?php endif; /* 5.18.103 (§G.5): end of the hardware rows */ ?>
  </div>

  <!-- Cart -->
  <div id="hwCartWrap" style="margin-top:10px;display:none;">
    <div style="font-size:11px;font-weight:800;color:#D41C1C;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;">✅ Selected Items</div>
    <div id="hwCartRows" style="display:flex;flex-direction:column;gap:5px;"></div>
  </div>
</div><!-- /wHardwareSection -->

</div>

<script>
// ── Kit Photo OCR Scanner ─────────────────────────────────────────────
// Uses Tesseract.js (CDN) to extract serial/tracking numbers from kit label photo.
// Image is saved via the form's multipart POST (name="kit_image") and uploaded
// to CRM alongside customer photo and ID proof.

var _tesseractReady = false;
var _tesseractWorker = null;

function kitOcrInit() {
  if (_tesseractReady || typeof Tesseract === 'undefined') return;
  _tesseractReady = true;
  // Worker is lazy-created on first scan
}

function kitScanPhoto(input) {
  var f = input.files && input.files[0];
  if (!f) return;

  var preview = document.getElementById('kitScanPreview');
  var thumb   = document.getElementById('kitScanThumb');
  var status  = document.getElementById('kitScanStatus');
  var result  = document.getElementById('kitOcrResult');

  // Compress first (higher quality/px for OCR readability), then preview + OCR
  compressImage(f, 1600, 0.88).then(function(compressed) {
    // Replace input file list with compressed version
    var dt = new DataTransfer();
    dt.items.add(compressed);
    input.files = dt.files;

    var reader = new FileReader();
    reader.onload = function(e) {
      thumb.src = e.target.result;
      preview.style.display = 'block';
      status.innerHTML = '<span style="color:#f59e0b;">⏳ Reading kit label…</span>';
      result.textContent = '';
      kitRunOcr(e.target.result);
    };
    reader.readAsDataURL(compressed);
  });
}

function kitRunOcr(dataUrl) {
  var status = document.getElementById('kitScanStatus');
  var result = document.getElementById('kitOcrResult');

  // Load Tesseract.js on demand
  if (typeof Tesseract === 'undefined') {
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/4.1.1/tesseract.min.js';
    s.onload = function() { kitRunOcr(dataUrl); };
    s.onerror = function() {
      status.innerHTML = '<span style="color:#ef4444;">❌ OCR library failed to load</span>';
    };
    document.head.appendChild(s);
    return;
  }

  Tesseract.recognize(dataUrl, 'eng', {
    logger: function(m) {
      if (m.status === 'recognizing text') {
        status.innerHTML = '<span style="color:#f59e0b;">⏳ ' + Math.round(m.progress * 100) + '%…</span>';
      }
    }
  }).then(function(r) {
    var raw = r.data.text || '';
    result.innerHTML = '<div style="color:#888;font-size:10px;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Raw OCR text:</div>' +
      '<div style="color:#ccc;word-break:break-all;">' + raw.replace(/\n/g,'<br>') + '</div>';

    // ── Extract Starlink-specific patterns ─────────────────────────────
    // Starlink dish serial format: STML-XXXXXXXX or UTXXXXXXXXXX
    // Router serial: SN: XXXXXXXXXXXXXXXX
    // Kit serial label patterns vary — try several
    var extracted = kitParseSerial(raw);

    if (extracted.serial || extracted.tracking) {
      status.innerHTML = '<span style="color:#22c55e;">✅ Serial detected — fields pre-filled</span>';
      if (extracted.serial) {
        document.getElementById('kitNameDisplay').value = extracted.serial;
        document.getElementById('hwKitName').value = extracted.serial;
      }
      if (extracted.tracking) {
        document.getElementById('kitNumberDisplay').value = extracted.tracking;
        document.getElementById('hwKitNumber').value = extracted.tracking;
      }
      // Highlight the matched text in OCR output
      var highlighted = raw;
      if (extracted.serial)   highlighted = highlighted.replace(new RegExp(extracted.serial.replace(/[-]/g,'[-]'), 'gi'), '<span style="background:#D41C1C;color:#fff;border-radius:3px;padding:0 3px;">$&</span>');
      if (extracted.tracking) highlighted = highlighted.replace(new RegExp(extracted.tracking, 'gi'), '<span style="background:#059669;color:#fff;border-radius:3px;padding:0 3px;">$&</span>');
      result.innerHTML = '<div style="color:#888;font-size:10px;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Detected serials (highlighted):</div>' +
        '<div style="color:#ccc;word-break:break-all;">' + highlighted.replace(/\n/g,'<br>') + '</div>';
    } else {
      status.innerHTML = '<span style="color:#f59e0b;">⚠ No serial found — enter manually</span>';
    }
  }).catch(function(e) {
    status.innerHTML = '<span style="color:#ef4444;">❌ OCR failed: ' + (e.message || e) + '</span>';
    console.error('Tesseract error:', e);
  });
}

function kitParseSerial(text) {
  var serial = null, tracking = null;

  // Normalize — remove excess whitespace, keep alphanumeric + hyphens
  var clean = text.replace(/\s+/g, ' ').toUpperCase();

  // Starlink dish serial: STML-XXXXXXXX  (STML followed by dash and 6-10 alphanumeric)
  var m = clean.match(/\bSTML[-\s]?([A-Z0-9]{5,12})\b/);
  if (m) serial = 'STML-' + m[1];

  // Starlink UT flat dish: UTXXXXXXXXXX (UT + 8-12 digits)
  if (!serial) { m = clean.match(/\bUT([0-9]{8,12})\b/); if (m) serial = 'UT' + m[1]; }

  // Starlink Mini Kit serial: KIT + alphanumeric (e.g. KIT4M03906184RHV)
  if (!serial) { m = clean.match(/\bKIT([A-Z0-9]{8,20})\b/); if (m) serial = 'KIT' + m[1]; }

  // Explicit SN: prefix (common on all Starlink labels)
  if (!serial) { m = clean.match(/SN[:\s]+([A-Z0-9\-]{6,24})/); if (m) serial = m[1].trim(); }

  // Generic S/N or S N prefix (router labels)
  if (!serial) { m = clean.match(/S[\s]?[\/N][\s:]+([A-Z0-9\-]{6,20})/); if (m) serial = m[1].trim(); }

  // PN: part number → use as tracking/reference
  var pm = clean.match(/PN[:\s]+([A-Z0-9\-]{6,20})/);
  if (pm) tracking = 'PN-' + pm[1];

  // Tracking/order number: long numeric (12-20 digits), common on DHL/Starlink box
  if (!tracking) { var tm = clean.match(/\b([0-9]{12,22})\b/); if (tm && tm[1] !== serial) tracking = tm[1]; }

  // Starlink order number format: ORD-XXXXXXXX
  if (!tracking) { m = clean.match(/\bORD[-\s]?([A-Z0-9]{6,12})\b/); if (m) tracking = 'ORD-' + m[1]; }

  return { serial: serial, tracking: tracking };
}

// Show/hide scan block based on service type
function kitScanVisibility() {
  var block = document.getElementById('wKitScanBlock');
  if (!block) return;
  var type = (typeof curType !== 'undefined') ? curType : 'starlink';
  block.style.display = (type === 'starlink') ? 'block' : 'none';
}

// ── Smart Kit Scanner ───────────────────────────────────────────────
// One tap: native barcode if in Android app, otherwise photo OCR.
function smartKitScan() {
  // Try native barcode scanner first (instant, accurate)
  var launched = window.dishnetScan && window.dishnetScan('kit_serial', function(value, format, id) {
    if (!value || format === 'CANCELLED') return;
    // Fill serial field
    var kitName = document.getElementById('kitNameDisplay');
    var hwKit   = document.getElementById('hwKitName');
    if (kitName) { kitName.value = value; kitName.style.borderColor = '#059669'; }
    if (hwKit)   hwKit.value = value;
    // Update status
    var status = document.getElementById('kitScanStatus');
    if (status) status.innerHTML = '<span style="color:#22c55e;">\u2705 Scanned: ' + value + '</span>';
  });
  // If native not available, fall back to photo OCR
  if (!launched) {
    document.getElementById('kitPhotoInput').click();
  }
}

// kitScanVisibility is called directly inside wizTypeChange below (defined after this block)
</script>

<!-- SIM section -->
<div id="wSimSection" style="display:none;margin-top:4px;">
  <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;"><i class="bi bi-sim" style="color:#E65100;margin-right:4px;"></i> SIM Card Details</div>
  <div class="sim-card-visual" id="simVisual">
    <div class="sim-brand">DishNet</div>
    <div class="sim-number" id="simIccidDisplay">ICCID: ____________________</div>
    <div class="sim-msisdn" id="simMsisdnDisplay">+211 ___ ___ ___</div>
  </div>
  <div class="wiz-row">
    <div class="wiz-field"><label>ICCID <span style="font-weight:400;color:#9ca3af;">(SIM No.)</span></label>
      <input type="text" name="sim_iccid" placeholder="89234000123456789" maxlength="22" oninput="wizSimUpdate()"></div>
    <div class="wiz-field"><label>MSISDN <span style="font-weight:400;color:#9ca3af;">(Phone No.)</span></label>
      <input type="tel" name="sim_msisdn" placeholder="+211912345678" oninput="wizSimUpdate()"></div>
  </div>
  <div class="wiz-row">
    <div class="wiz-field"><label>IMSI</label><input type="text" name="sim_imsi" placeholder="412010012345678"></div>
    <div class="wiz-field"><label>PIN / PUK <span style="font-weight:400;color:#9ca3af;">optional</span></label><input type="text" name="sim_pin" placeholder="Optional"></div>
  </div>
</div>

<!-- LTE section -->
<div id="wLteSection" style="display:none;margin-top:4px;">
  <div style="font-size:11px;font-weight:800;color:#5B21B6;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;"><i class="bi bi-reception-4" style="margin-right:4px;"></i> DishNet 4G — SIM & Network</div>
  <div style="background:#F5F3FF;border:1px solid #DDD6FE;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#5B21B6;">
    <i class="bi bi-info-circle-fill" style="margin-right:5px;"></i>Subscriber will be auto-provisioned in LTE if Magma is configured.
  </div>
  <div class="wiz-row">
    <div class="wiz-field"><label>IMSI <span style="font-weight:400;color:#9ca3af;">*Magma</span></label>
      <input type="text" name="lte_imsi" placeholder="IMSI001010000000001" style="font-family:monospace;"></div>
    <div class="wiz-field"><label>MSISDN</label>
      <input type="tel" name="lte_msisdn" placeholder="+211912345678" style="font-family:monospace;"></div>
  </div>
  <div class="wiz-row">
    <div class="wiz-field"><label>ICCID</label>
      <input type="text" name="lte_iccid" placeholder="20-digit SIM serial" style="font-family:monospace;"></div>
    <div class="wiz-field"><label>Data Package</label>
      <select name="lte_package_id" id="wLtePkgSel">
        <option value="">— Select LTE package —</option>
      </select>
    </div>
  </div>
  <div class="wiz-row">
    <div class="wiz-field"><label>Amount Collected ($)</label><input type="number" name="lte_amount_paid" placeholder="0.00" step="0.01"></div>
    <div class="wiz-field"><label>Payment Method</label>
      <select name="lte_payment_method">
        <option value="cash">Cash</option>
      </select>
    </div>
  </div>
  <div class="wiz-row">
    <div class="wiz-field"><label>Auth Key (K) <span style="font-weight:400;color:#9ca3af;">opt</span></label>
      <input type="text" name="lte_auth_key" placeholder="Optional" style="font-family:monospace;"></div>
    <div class="wiz-field"><label>OP/OPC Key <span style="font-weight:400;color:#9ca3af;">opt</span></label>
      <input type="text" name="lte_op_key" placeholder="Optional" style="font-family:monospace;"></div>
  </div>
</div>

<!-- ══ PLAN SELECTOR ══════════════════════════════════════════════ -->
<div id="wPlanSection" style="margin-top:14px;">
  <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
    <i class="bi bi-speedometer2" style="color:#D41C1C;"></i> Monthly Plan
    <span id="wPlanCount" style="font-size:10px;color:#94a3b8;font-weight:600;"></span>
  </div>
  <div id="wPackageList" style="display:flex;flex-direction:column;gap:6px;">
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, G-4): uCRM's own plans — one radio per offered plan pair, name ucrm_plan, value "<servicePlanId>:<productId>", drawn with uCRM's product name, speed and 1-month price; the plugin's plan rows (and their package_choice radios) are not drawn, and nothing is preselected */ ?>
<?php foreach ($_q103['plans'] as $q103P): $q103Pr = (float)$q103P['price']; $q103Sp = (string)$q103P['speed']; if ($q103Sp !== '' && is_numeric($q103Sp)) $q103Sp = rtrim(rtrim(number_format((float)$q103Sp, 2, '.', ''), '0'), '.') . ' Mbps'; ?>
  <div class="pkg-opt pkg-starlink"
       data-id="<?= h($q103P['v']) ?>" data-name="<?= h($q103P['label']) ?>"
       data-price="<?= h(number_format($q103Pr, 2, '.', '')) ?>" data-type="starlink" data-token="<?= h($q103P['token']) ?>"
       style="display:none;align-items:center;gap:10px;padding:12px 14px;background:#fff;border-radius:12px;border:1.5px solid #e2e8f0;cursor:pointer;transition:border-color .12s,background .12s;"
       onclick="pkgPick(this)">
    <input type="radio" name="ucrm_plan" value="<?= h($q103P['v']) ?>" style="width:18px;height:18px;accent-color:#D41C1C;flex-shrink:0;">
    <div style="width:4px;height:36px;border-radius:2px;background:#D41C1C;flex-shrink:0;"></div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= h($q103P['label']) ?></div>
      <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">
        <?php if ($q103Sp !== ''): ?><span style="background:#fff0f0;color:#D41C1C;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:700;"><?= h($q103Sp) ?></span><?php endif; ?>
        <span style="background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:600;">uCRM</span>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0;">
      <div style="font-size:16px;font-weight:900;color:#0D47A1;"><?= dn_cur($config) ?><?= number_format($q103Pr, 2) ?></div>
      <div style="font-size:9px;color:#94a3b8;font-weight:600;">/month</div>
    </div>
  </div>
<?php endforeach; ?>
<?php else: /* 5.18.103: direct mode off — 5.18.102's plan rows, unchanged */ ?>
  <?php
  $subPlans  = $store->load('subscription_plans.json');
  $spFirstOf = [];
  foreach ($subPlans as $sp):
    if (empty($sp['is_active'])) continue;
    $spType  = strtolower($sp['type'] ?? 'starlink');
    $spFirst = !isset($spFirstOf[$spType]);
    if ($spFirst) $spFirstOf[$spType] = true;
    $spPrice = (float)($sp['customer_price'] ?? 0);
    // 5.18.102 (Uganda, docs/71 §G): uCRM's 1-month price (the page's resolver), never customer_price; a plan that does
    // not resolve is drawn "not priced in uCRM" with its reason, and carries 0 so it adds nothing
    $q102Cell = $_q102 !== null ? $_q102Of('plans', $sp['id'] ?? '') : null;
    if ($q102Cell !== null) $spPrice = $q102Cell['p'] !== null ? (float)$q102Cell['p'] : 0.0;
    // 5.18.102 (D14, docs/71 §R.5, §R.4.7): a plan whose link resolves is drawn with uCRM's product name — its text and
    // data-name, and so the summary, step 5 and selected_plan_name — and its link record as data-link; any other plan
    // with the plugin's own name and the reason. Where the gate is off: the plugin's name and no data-link, as before.
    $q102Resolves = $q102Cell !== null && $q102Cell['p'] !== null;
    $q102Name     = ($q102Resolves && isset($q102Cell['l'])) ? $q102Cell['l'] : $sp['name'];
    $q102LinkAttr = ($q102Resolves && isset($q102Cell['k'])) ? ' data-link="' . (int)$q102Cell['k'] . '"' : '';
    // 5.18.102 D14 review (D14-UI-5): the row's own name, for applyDefaults' keyword — never uCRM's name (gate on only)
    $q102RowAttr  = $_q102 !== null ? ' data-row-name="' . h((string)$sp['name']) . '"' : '';
  ?>
  <div class="pkg-opt pkg-<?= h($spType) ?>"
       data-id="<?= $sp['id'] ?>"<?= $q102LinkAttr ?><?= $q102RowAttr ?> data-name="<?= h($q102Name) ?>"
       data-price="<?= $spPrice ?>" data-type="<?= h($spType) ?>"
       style="display:none;align-items:center;gap:10px;padding:12px 14px;background:#fff;border-radius:12px;border:1.5px solid #e2e8f0;cursor:pointer;transition:border-color .12s,background .12s;"
       onclick="pkgPick(this)">
    <input type="radio" name="package_choice" value="<?= $sp['id'] ?>" style="width:18px;height:18px;accent-color:#D41C1C;flex-shrink:0;">
    <div style="width:4px;height:36px;border-radius:2px;background:<?= h($sp['color']??'#D41C1C') ?>;flex-shrink:0;"></div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= h($q102Name) ?></div>
      <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">
        <?php if(!empty($sp['speed'])): ?><span style="background:#fff0f0;color:#D41C1C;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:700;"><?= h($sp['speed']) ?></span><?php endif; ?>
        <?php if(!empty($sp['validity'])): ?><span style="background:#FFF3E0;color:#E65100;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:700;"><?= h($sp['validity']) ?></span><?php endif; ?>
        <?php if(!empty($sp['supplier'])): ?><span style="background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:600;"><?= h($sp['supplier']) ?></span><?php endif; ?>
      </div>
    </div>
<?php if ($q102Cell !== null && $q102Cell['p'] === null): ?>
    <div class="q102-unpriced" style="text-align:right;flex-shrink:1;max-width:45%;" title="<?= h((string)$q102Cell['r']) ?>">
      <div style="font-size:12px;font-weight:800;color:#b45309;white-space:nowrap;">not priced in uCRM</div>
      <div class="q102-reason" style="font-size:9px;color:#b45309;font-weight:600;"><?= h((string)$q102Cell['r']) ?></div>
    </div>
<?php else: ?>
    <div style="text-align:right;flex-shrink:0;">
      <div style="font-size:16px;font-weight:900;color:#0D47A1;"><?= dn_cur($config) ?><?= number_format($spPrice,2) ?></div>
      <div style="font-size:9px;color:#94a3b8;font-weight:600;">/month</div>
    </div>
<?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; /* 5.18.103 (§G.5): end of the plan rows */ ?>
  </div>
  <div id="wNoPlanMsg" style="display:none;text-align:center;padding:20px;color:#9ca3af;font-size:13px;">No plans available for this service.</div>
</div>

</div><!-- /padding -->

<!-- Hidden form fields -->
<?php if ($_q103 === null): /* 5.18.103 (root docs/72 §G.5, G-7): never drawn in direct mode — the direct cart posts as ucrm_cart_json */ ?>
<input type="hidden" name="hw_cart_json"        id="hwCartJson"   value="[]">
<input type="hidden" name="device_id"           id="hwDeviceId"   value="">
<?php endif; /* 5.18.103 (G-7) */ ?>
<input type="hidden" name="kitQty"              id="hwKitQty"     value="1">
<input type="hidden" name="kitUnit"             id="hwKitUnit"    value="Piece">
<input type="hidden" name="kitName"             id="hwKitName"    value="">
<input type="hidden" name="kitNumber"           id="hwKitNumber"  value="">
<input type="hidden" name="selected_plan_price" id="selPlanPrice" value="0">
<input type="hidden" name="selected_plan_name"  id="selPlanName"  value="">

<div style="padding:10px 14px 4px;">
<div class="wiz-nav">
  <button type="button" class="wiz-btn wiz-btn-back" onclick="wizBack()"><i class="bi bi-arrow-left"></i> Back</button>
  <button type="button" class="wiz-btn wiz-btn-next" onclick="wizNext()">Continue <i class="bi bi-arrow-right"></i></button>
</div>
</div>

</div><!-- kyc-card-body -->
</div><!-- kyc-card -->
</div><!-- wiz-panel 3 -->

<script>
// ═══════════════════════════════════════════════════════════════
// SMART ORDER BUILDER v2 — Complete rewrite
// Defaults: Starlink=Mini+Residential | Fiber=ONU+router+FIBER:50 | SIM=first combo
// ═══════════════════════════════════════════════════════════════

var hwCart  = []; // [{id,title,price,qty}]
var selPlan = null; // {id,name,price}
var curType = 'starlink';

// Which device types to SHOW for each service key
var typeShow = {
  starlink: ['starlink', 'general'],
  fiber:    ['fiber', 'general'],
  sim:      [],
  lte:      []
};

// Smart default hardware selection per service type
// Array of matchers: first match wins for primary, rest are "also add"
var typeDefaults = {
  starlink: [
    { match: function(t){ return t.includes('mini'); }, primary: true  },
    { match: function(t){ return t.includes('starlink'); }, primary: true }  // fallback
  ],
  fiber: [
    { match: function(t){ return t.includes('onu') || t.includes('ont') || t.includes('wifi onu'); }, primary: true },
    { match: function(t){ return t.includes('router') || t.includes('wi-fi router'); }, primary: false, autoAdd: true }
  ],
  sim: [],
  lte: []
};

// Plan keyword to auto-select
var planDefault = {
  starlink: 'residential',
  fiber:    '',   // first fiber plan
  sim:      '',   // first sim plan
  lte:      ''
};

// ── Helpers ──────────────────────────────────────────────────────
function pNum(s){ return parseFloat((String(s||'0')).replace(/[^0-9.]/g,''))||0; }

function hwHighlightAll() {
  document.querySelectorAll('.hw-row').forEach(function(r){
    var inCart = hwCart.find(function(i){ return i.id === r.dataset.id; });
    r.style.borderColor = inCart ? '#D41C1C' : '#e2e8f0';
    r.style.background  = inCart ? '#EFF6FF' : '#fff';
  });
}

// ── Show / hide hardware rows for current service ─────────────────
function hwShowForType(typeKey) {
  var allowed = typeShow[typeKey] || [];
  document.querySelectorAll('.hw-row').forEach(function(r){
    var show = allowed.indexOf(r.dataset.type) >= 0;
    r.style.display = show ? 'flex' : 'none';
  });
}

// ── Plan rows: show only matching type ───────────────────────────
function planShowForType(typeKey) {
  var vis = 0, first = null;
  document.querySelectorAll('.pkg-opt').forEach(function(p){
    var match = p.dataset.type === typeKey;
    p.style.display = match ? 'flex' : 'none';
    p.querySelector('input[type=radio]').checked = false;
    if (match) { vis++; if (!first) first = p; }
  });
  var cnt = document.getElementById('wPlanCount');
  if (cnt) cnt.textContent = vis ? '(' + vis + ' available)' : '';
  document.getElementById('wNoPlanMsg').style.display = (vis===0 && typeKey!=='lte') ? 'block' : 'none';
  return { visible: vis, first: first };
}

// ── Apply smart defaults ─────────────────────────────────────────
function applyDefaults(typeKey) {
  curType = typeKey;
  hwCart  = [];
  selPlan = null;
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5): direct mode */ ?>
  // 5.18.103 (§G.5): no keyword defaults in direct mode — nothing is preselected unless a standard quotation is pressed;
  // the cart and the plan are only cleared, and the page redrawn
  hwHighlightAll();
  renderCart();
  renderPlanHighlights();
  renderSummary();
  return;
<?php endif; /* 5.18.103 */ ?>

  // 1. Determine default hw — primary first, then autoAdd secondaries
  var defs = typeDefaults[typeKey] || [];
  if (defs.length) {
    var rows = [];
    document.querySelectorAll('.hw-row').forEach(function(r){
      if ((typeShow[typeKey]||[]).indexOf(r.dataset.type) >= 0) rows.push(r);
    });
    // Pass 1: primary item (first non-autoAdd match)
    var primaryAdded = false;
    for (var di = 0; di < defs.length; di++) {
      if (defs[di].autoAdd) continue;
      for (var ri = 0; ri < rows.length; ri++) {
        var t = (rows[ri].dataset.<?= $_q102 !== null ? 'rowTitle' : 'title' /* 5.18.102 D14 review (D14-UI-5): the row's own title */ ?> || '').toLowerCase();
        if (defs[di].match(t)) {
          hwCart.push({ id: rows[ri].dataset.id, title: rows[ri].dataset.title, price: pNum(rows[ri].dataset.price), qty: 1 });
          primaryAdded = true;
          break;
        }
      }
      if (primaryAdded) break;
    }
    // Absolute fallback: first visible row as primary
    if (!hwCart.length && rows.length) {
      hwCart.push({ id: rows[0].dataset.id, title: rows[0].dataset.title, price: pNum(rows[0].dataset.price), qty: 1 });
    }
    // Pass 2: autoAdd secondary items (e.g. Wi-Fi Router for fiber combo)
    for (var di2 = 0; di2 < defs.length; di2++) {
      if (!defs[di2].autoAdd) continue;
      for (var ri2 = 0; ri2 < rows.length; ri2++) {
        var t2 = (rows[ri2].dataset.<?= $_q102 !== null ? 'rowTitle' : 'title' /* 5.18.102 D14 review (D14-UI-5) */ ?> || '').toLowerCase();
        if (defs[di2].match(t2)) {
          var alreadyIn = hwCart.some(function(c){ return c.id === rows[ri2].dataset.id; });
          if (!alreadyIn) {
            hwCart.push({ id: rows[ri2].dataset.id, title: rows[ri2].dataset.title, price: pNum(rows[ri2].dataset.price), qty: 1 });
          }
          break;
        }
      }
    }
  }

  // 2. Determine default plan
  var keyword = planDefault[typeKey] || '';
  var pkgs = [];
  document.querySelectorAll('.pkg-opt').forEach(function(p){
    if (p.dataset.type === typeKey) pkgs.push(p);
  });
  var picked = null;
  if (keyword) {
    for (var pi = 0; pi < pkgs.length; pi++) {
      if ((pkgs[pi].dataset.<?= $_q102 !== null ? 'rowName' : 'name' /* 5.18.102 D14 review (D14-UI-5): the row's own name */ ?>||'').toLowerCase().indexOf(keyword) >= 0) {
        picked = pkgs[pi]; break;
      }
    }
  }
  if (!picked && pkgs.length) picked = pkgs[0];

  if (picked) {
    picked.querySelector('input[type=radio]').checked = true;
    selPlan = { id: picked.dataset.id, name: picked.dataset.name, price: pNum(picked.dataset.price) };
  }

  // 3. Refresh UI
  hwHighlightAll();
  renderCart();
  renderPlanHighlights();
  renderSummary();
}

// ── Plan: pick ────────────────────────────────────────────────────
function pkgPick(el) {
  document.querySelectorAll('.pkg-opt').forEach(function(p){
    p.style.borderColor = '#e2e8f0';
    p.style.background  = '#fff';
  });
  el.style.borderColor = '#D41C1C';
  el.style.background  = '#EFF6FF';
  el.querySelector('input[type=radio]').checked = true;
  selPlan = { id: el.dataset.id, name: el.dataset.name, price: pNum(el.dataset.price) };
  document.getElementById('selPlanPrice').value = selPlan.price;
  document.getElementById('selPlanName').value  = selPlan.name;
  syncOuterFields();
  renderSummary();
}

function renderPlanHighlights() {
  document.querySelectorAll('.pkg-opt').forEach(function(p){
    var on = selPlan && p.dataset.id == selPlan.id;
    p.style.borderColor = on ? '#D41C1C' : '#e2e8f0';
    p.style.background  = on ? '#EFF6FF' : '#fff';
  });
  if (selPlan) {
    document.getElementById('selPlanPrice').value = selPlan.price;
    document.getElementById('selPlanName').value  = selPlan.name;
  }
}

// ── Cart: add ─────────────────────────────────────────────────────
function hwAdd(row) {
  var id = row.dataset.id;
  var existing = hwCart.find(function(i){ return i.id === id; });
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, §G.6): the server's limits — at most 99 of one item, at most 30 items */ ?>
  if (existing && existing.qty >= _Q103_MAX_QTY) { alert('At most ' + _Q103_MAX_QTY + ' of one item on a sale.'); return; }
  if (!existing && hwCart.length >= _Q103_MAX_CART) { alert('At most ' + _Q103_MAX_CART + ' different items on a sale.'); return; }
<?php endif; /* 5.18.103 */ ?>
  if (existing) { existing.qty++; }
  else { hwCart.push({ id: id, title: row.dataset.title, price: pNum(row.dataset.price), qty: 1 }); }
  hwHighlightAll();
  renderCart();
  renderSummary();
}
function hwQty(idx, d) {
  hwCart[idx].qty = Math.max(1, hwCart[idx].qty + d);
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5): the quantity box stops at the server's limit, 99 */ ?>
  if (hwCart[idx].qty > _Q103_MAX_QTY) hwCart[idx].qty = _Q103_MAX_QTY;
<?php endif; /* 5.18.103 */ ?>
  renderCart(); renderSummary();
}
function hwRemove(idx) {
  var removedId = hwCart[idx].id;
  hwCart.splice(idx, 1);
  document.querySelectorAll('.hw-row').forEach(function(r){
    if (r.dataset.id === removedId && !hwCart.find(function(i){ return i.id === removedId; })) {
      r.style.borderColor = '#e2e8f0'; r.style.background = '#fff';
    }
  });
  renderCart(); renderSummary();
}

// ── Cart render ───────────────────────────────────────────────────
function renderCart() {
  var wrap = document.getElementById('hwCartWrap');
  var rows = document.getElementById('hwCartRows');
  var kit  = document.getElementById('wKitSection');
  var isSL  = (curType === 'starlink');
  var isFB  = (curType === 'fiber');

  if (!hwCart.length) {
    wrap.style.display = 'none';
    if (kit) kit.style.display = 'none';
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, G-7): no hw_cart_json or device_id in direct mode — the direct cart, ucrm_cart_json */ ?>
    q103SyncCart();
<?php else: ?>
    document.getElementById('hwCartJson').value = '[]';
    document.getElementById('hwDeviceId').value = '';
<?php endif; /* 5.18.103 */ ?>
    return;
  }

  wrap.style.display = 'block';
  if (kit) kit.style.display = (isSL || isFB) ? 'block' : 'none';

  var html = '';
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §G): each line at uCRM's price as q102Quote() priced it, or "not priced in uCRM" with
  // its reason (under Fiber, every line: Fiber is not priced from uCRM here)
  var q = q102Quote();
  hwCart.forEach(function(item, idx) {
    var ql = q.kits[idx] || { price: null, cents: 0, qty: item.qty, reason: '' };
    html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fff;border-radius:10px;border:1px solid #DBEAFE;">'
      + '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(item.title) + '</div>'
        + (ql.price === null
            ? '<div class="q102-unpriced" style="font-size:11px;color:#b45309;margin-top:1px;" title="' + escAttr(ql.reason) + '">not priced in uCRM — ' + escHtml(ql.reason) + '</div>'
            : '<div style="font-size:11px;color:#6b7280;margin-top:1px;">' + _q102Cur + q102Money(ql.cents) + ' each</div>')
      + '</div>'
      + '<div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">'
        + '<button type="button" onclick="hwQty(' + idx + ',-1)" class="hw-qty-btn" style="border:1.5px solid #cbd5e1;background:#f8fafc;border-radius:7px;font-size:20px;cursor:pointer;line-height:1;font-weight:600;color:#374151;">−</button>'
        + '<span style="min-width:24px;text-align:center;font-weight:800;font-size:14px;">' + item.qty + '</span>'
        + '<button type="button" onclick="hwQty(' + idx + ',1)" class="hw-qty-btn" style="border:1.5px solid #D41C1C;background:#fff5f5;color:#D41C1C;border-radius:7px;font-size:20px;cursor:pointer;line-height:1;font-weight:700;">+</button>'
      + '</div>'
      + (ql.price === null
          ? '<div style="font-weight:800;color:#b45309;font-size:14px;min-width:54px;text-align:right;">—</div>'
          : '<div style="font-weight:800;color:#0D47A1;font-size:14px;min-width:54px;text-align:right;">' + _q102Cur + q102Money(ql.cents * ql.qty) + '</div>')
      + '<button type="button" onclick="hwRemove(' + idx + ')" style="width:24px;height:24px;background:#FEE2E2;color:#dc2626;border:none;border-radius:6px;font-size:11px;cursor:pointer;flex-shrink:0;">✕</button>'
    + '</div>';
  });
<?php else: ?>
  hwCart.forEach(function(item, idx) {
    var lt = item.price * item.qty;
    html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fff;border-radius:10px;border:1px solid #DBEAFE;">'
      + '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + item.title + '</div>'
        + '<div style="font-size:11px;color:#6b7280;margin-top:1px;">' + <?= json_encode(dn_cur($config)) ?> + item.price.toFixed(2) + ' each</div>'
      + '</div>'
      + '<div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">'
        + '<button type="button" onclick="hwQty(' + idx + ',-1)" class="hw-qty-btn" style="border:1.5px solid #cbd5e1;background:#f8fafc;border-radius:7px;font-size:20px;cursor:pointer;line-height:1;font-weight:600;color:#374151;">−</button>'
        + '<span style="min-width:24px;text-align:center;font-weight:800;font-size:14px;">' + item.qty + '</span>'
        + '<button type="button" onclick="hwQty(' + idx + ',1)" class="hw-qty-btn" style="border:1.5px solid #D41C1C;background:#fff5f5;color:#D41C1C;border-radius:7px;font-size:20px;cursor:pointer;line-height:1;font-weight:700;">+</button>'
      + '</div>'
      + '<div style="font-weight:800;color:#0D47A1;font-size:14px;min-width:54px;text-align:right;">' + <?= json_encode(dn_cur($config)) ?> + lt.toFixed(2) + '</div>'
      + '<button type="button" onclick="hwRemove(' + idx + ')" style="width:24px;height:24px;background:#FEE2E2;color:#dc2626;border:none;border-radius:6px;font-size:11px;cursor:pointer;flex-shrink:0;">✕</button>'
    + '</div>';
  });
<?php endif; ?>
  rows.innerHTML = html;

  // Show fixed installation fee for Fiber
  if (curType === 'fiber' && typeof _kycFiberInstallFee !== 'undefined' && _kycFiberInstallFee > 0) {
    rows.innerHTML += '<div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0;">'
      + '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:13px;font-weight:700;color:#15803d;">🔧 Installation Fee</div>'
        + '<div style="font-size:11px;color:#6b7280;margin-top:1px;">Fixed — included in all Fiber quotes</div>'
      + '</div>'
      + '<div style="font-weight:800;color:#15803d;font-size:14px;min-width:54px;text-align:right;">' + <?= json_encode(dn_cur($config)) ?> + _kycFiberInstallFee.toFixed(2) + '</div>'
    + '</div>';
  }

<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5, G-7): the direct cart, ucrm_cart_json — never hw_cart_json or device_id */ ?>
  q103SyncCart();
  document.getElementById('hwKitQty').value   = hwCart[0].qty;
<?php else: ?>
  var cartJson = JSON.stringify(hwCart);
  document.getElementById('hwCartJson').value = cartJson;
  document.getElementById('hwDeviceId').value = hwCart[0].id;
  document.getElementById('hwKitQty').value   = hwCart[0].qty;
<?php endif; /* 5.18.103 */ ?>
}

// ── Summary bar render ────────────────────────────────────────────
function renderSummary() {
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §G): the one total — q102Quote(), through q102Refresh(), which also sets
  // ucrm_total_seen and the Cash option. uCRM's prices only; no total while any line is not priced in uCRM.
  var q = q102Refresh();
  var linesEl = document.getElementById('osLines');
  var totEl   = document.getElementById('osTotalBadge');
  var recEl   = document.getElementById('osRecurring');
  if (!linesEl || !totEl) return;

  var html = '';
  if (!hwCart.length && !selPlan) {
    html = '<div style="font-size:12px;opacity:.55;font-style:italic;">← select hardware &amp; plan below</div>';
  } else {
    q.lines.forEach(function (l) {
      var icon = l.kind === 'plan' ? '📶 ' : (l.kind === 'install' ? '🔧 ' : '📦 ');
      html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;' + (l.kind === 'kit' ? '' : 'margin-top:2px;') + '">'
        + '<span style="opacity:.88;">' + icon + escHtml(l.label) + (l.kind === 'kit' && l.qty > 1 ? ' ×' + l.qty : '') + '</span>'
        + (l.price === null
            ? '<span style="font-weight:800;color:#fca5a5;" title="' + escAttr(l.reason) + '">not priced in uCRM</span>'
            : '<span style="font-weight:800;">' + _q102Cur + q102Money(l.cents * l.qty) + (l.kind === 'plan' ? '/mo' : '') + '</span>')
      + '</div>';
    });
    if (q.installBy === 'customer') {
      html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-top:2px;">'
        + '<span style="opacity:.7;">🔧 Customer installs (kit only)</span><span style="opacity:.7;">—</span></div>';
    }
    if (q.status === 'priced' && q.lines.length > 1) {
      html += '<div style="display:flex;justify-content:space-between;font-size:13px;font-weight:800;border-top:1px solid rgba(255,255,255,.22);margin-top:7px;padding-top:7px;">'
        + '<span>Today Total</span><span>' + _q102Cur + q.total.toFixed(2) + '</span></div>';
    } else if (q.status === 'unpriced') {
      html += '<div style="font-size:11px;font-weight:700;color:#fecaca;border-top:1px solid rgba(255,255,255,.22);margin-top:7px;padding-top:7px;">'
        + (q.reason === _Q102_UNANSWERED ? 'Choose who installs above to see the total.'
            : '⚠ No total from uCRM: ' + escHtml(q.reason) + ' — no quotation, Cash not possible.')
      + '</div>';
    }
  }

  linesEl.innerHTML = html;
  totEl.textContent = q.status === 'priced' ? _q102Cur + q.total.toFixed(2) : (q.status === 'unpriced' ? 'not priced' : '—');

  var planCents = (q.plan && q.plan.price !== null) ? q.plan.cents : null;
  if (planCents !== null) {
    recEl.style.display = 'block';
    recEl.textContent   = '↻ Then ' + _q102Cur + q102Money(planCents) + '/month recurring';
  } else {
    recEl.style.display = 'none';
  }

  document.getElementById('selPlanPrice').value = planCents !== null ? q102Money(planCents) : 0;
  document.getElementById('selPlanName').value  = selPlan ? selPlan.name : '';
<?php else: ?>
  var hwT = hwCart.reduce(function(s,i){ return s + i.price*i.qty; }, 0);
  var plT = selPlan ? selPlan.price : 0;
  var instFee = (curType === 'fiber' && typeof _kycFiberInstallFee !== 'undefined') ? _kycFiberInstallFee : 0;
  var tot = hwT + plT + instFee;

  var linesEl = document.getElementById('osLines');
  var totEl   = document.getElementById('osTotalBadge');
  var recEl   = document.getElementById('osRecurring');
  if (!linesEl || !totEl) return;

  var html = '';
  if (!hwCart.length && !selPlan) {
    html = '<div style="font-size:12px;opacity:.55;font-style:italic;">← select hardware &amp; plan below</div>';
  } else {
    hwCart.forEach(function(item){
      html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;">'
        + '<span style="opacity:.88;">📦 ' + item.title + (item.qty > 1 ? ' ×' + item.qty : '') + '</span>'
        + '<span style="font-weight:800;">' + <?= json_encode(dn_cur($config)) ?> + (item.price*item.qty).toFixed(2) + '</span>'
      + '</div>';
    });
    if (selPlan) {
      html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-top:2px;">'
        + '<span style="opacity:.88;">📶 ' + selPlan.name + '</span>'
        + '<span style="font-weight:800;">' + <?= json_encode(dn_cur($config)) ?> + plT.toFixed(2) + '/mo</span>'
      + '</div>';
    }
    if (instFee > 0) {
      html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-top:2px;">'
        + '<span style="opacity:.88;">🔧 Installation Fee</span>'
        + '<span style="font-weight:800;">' + <?= json_encode(dn_cur($config)) ?> + instFee.toFixed(2) + '</span>'
      + '</div>';
    }
    if (hwT > 0 && (plT > 0 || instFee > 0)) {
      html += '<div style="display:flex;justify-content:space-between;font-size:13px;font-weight:800;border-top:1px solid rgba(255,255,255,.22);margin-top:7px;padding-top:7px;">'
        + '<span>Today Total</span><span>' + <?= json_encode(dn_cur($config)) ?> + tot.toFixed(2) + '</span></div>';
    }
  }

  linesEl.innerHTML = html;
  totEl.textContent = <?= json_encode(dn_cur($config)) ?> + tot.toFixed(2);

  if (plT > 0) {
    recEl.style.display = 'block';
    recEl.textContent   = '↻ Then ' + <?= json_encode(dn_cur($config)) ?> + plT.toFixed(2) + '/month recurring';
  } else {
    recEl.style.display = 'none';
  }

  document.getElementById('selPlanPrice').value = plT;
  document.getElementById('selPlanName').value  = selPlan ? selPlan.name : '';
<?php endif; ?>
}

// ── Area combobox logic ──────────────────────────────────────────────
var _areaAllOpts = null;
function _getAreaOpts() {
  if (!_areaAllOpts) _areaAllOpts = Array.from(document.querySelectorAll('.area-opt'));
  return _areaAllOpts;
}

function areaFilter(q) {
  var drop = document.getElementById('wAreaDrop');
  var addRow = document.getElementById('wAreaAddNew');
  var addLbl = document.getElementById('wAreaAddLabel');
  var hidden = document.getElementById('wFiberArea');
  if (!drop) return;
  drop.style.display = 'block';
  q = q.trim();
  var lower = q.toLowerCase();
  var matchCount = 0;
  _getAreaOpts().forEach(function(el) {
    var txt = (el.dataset.val || '').toLowerCase();
    var show = !lower || txt.indexOf(lower) !== -1;
    el.style.display = show ? 'block' : 'none';
    if (show) matchCount++;
  });
  // Show "Add new" row if typed something that doesn't exactly match
  var exactMatch = _getAreaOpts().some(function(el) {
    return el.dataset.val.toLowerCase() === lower;
  });
  if (q && !exactMatch) {
    if (addLbl) addLbl.textContent = q;
    if (addRow) addRow.style.display = 'block';
    // Don't set hidden yet — user hasn't confirmed
  } else {
    if (addRow) addRow.style.display = 'none';
    if (exactMatch) {
      // Auto-confirm exact match
      var matched = _getAreaOpts().find(function(el) { return el.dataset.val.toLowerCase() === lower; });
      if (matched && hidden) hidden.value = matched.dataset.val;
    }
  }
}

function areaDropShow() {
  var drop = document.getElementById('wAreaDrop');
  var q = (document.getElementById('wAreaSearch') || {}).value || '';
  if (drop) drop.style.display = 'block';
  areaFilter(q);
}

function areaDropHide() {
  var drop = document.getElementById('wAreaDrop');
  if (drop) drop.style.display = 'none';
  // If text input doesn't match hidden value, clear both (incomplete entry)
  var search = document.getElementById('wAreaSearch');
  var hidden = document.getElementById('wFiberArea');
  if (search && hidden && search.value.trim() && !hidden.value) {
    // user typed something but never confirmed — keep text, leave hidden empty
    // validation will catch it
  }
}

function areaSelect(val) {
  var search  = document.getElementById('wAreaSearch');
  var hidden  = document.getElementById('wFiberArea');
  var badge   = document.getElementById('wAreaNewBadge');
  var pill    = document.getElementById('wAreaPill');
  var pillTxt = document.getElementById('wAreaPillText');
  var drop    = document.getElementById('wAreaDrop');
  if (search) {
    search.value = val;
    if (val) {
      search.style.borderColor   = '#D41C1C';
      search.style.background    = '#D41C1C';
      search.style.color         = '#fff';
      search.style.fontWeight    = '800';
      search.style.paddingLeft   = '38px';
    } else {
      search.style.borderColor   = '#E2E8F0';
      search.style.background    = '#fafafa';
      search.style.color         = '';
      search.style.fontWeight    = '600';
    }
  }
  if (hidden) hidden.value = val;
  if (badge)  badge.style.display = 'none';
  if (drop)   drop.style.display  = 'none';
  if (pill && pillTxt) {
    if (val) {
      pillTxt.textContent = val;
      pill.style.display = 'inline-flex';
    } else {
      pill.style.display = 'none';
    }
  }
}

function areaAddNew() {
  var search = document.getElementById('wAreaSearch');
  var hidden = document.getElementById('wFiberArea');
  var badge  = document.getElementById('wAreaNewBadge');
  var drop   = document.getElementById('wAreaDrop');
  var newVal = (search ? search.value.trim() : '');
  if (!newVal) return;
  // Capitalise first letter of each word
  newVal = newVal.replace(/\w/g, function(c) { return c.toUpperCase(); });
  if (search) {
    search.value = newVal;
    search.style.borderColor = '#D41C1C';
    search.style.borderWidth = '2px';
  }
  if (hidden) hidden.value = newVal;
  if (badge)  badge.style.display = 'block';
  if (drop)   drop.style.display  = 'none';
  var pill    = document.getElementById('wAreaPill');
  var pillTxt = document.getElementById('wAreaPillText');
  if (pill && pillTxt) { pillTxt.textContent = newVal; pill.style.display = 'inline-flex'; }
}

// ── Master type switch (called by wizTypeChange) ──────────────────
function wizTypeChange() {
  var t      = document.querySelector('input[name="customer_type"]:checked')?.value || 'StarLink';
  var isSIM  = t === 'SIM';
  var isFiber= t === 'Fiber';
  var isLTE  = t === 'LTE';
  var typeKey= isSIM ? 'sim' : isFiber ? 'fiber' : isLTE ? 'lte' : 'starlink';

  // Section visibility
  var noHW = isSIM || isLTE;
  document.getElementById('wHardwareSection').style.display = noHW ? 'none' : 'block';
  document.getElementById('wSimSection').style.display      = isSIM  ? 'block' : 'none';
  document.getElementById('wLteSection').style.display      = isLTE  ? 'block' : 'none';
  document.getElementById('wPlanSection').style.display     = 'block';

  // Filter hw catalogue rows
  hwShowForType(typeKey);

  // Filter plan rows
  planShowForType(typeKey);

  // Update step 1 summary box
  var st = document.getElementById('serviceSummaryText');
  var sb = document.getElementById('serviceSummary');
  if (st && sb) {
    var msgs = { lte:'DishNet 4G: IMSI + data plan + Magma auto-provisioning', sim:'SIM: select data combo plan + SIM details', fiber:'Fiber FTTH: ONU + optional router + monthly plan', starlink:'Starlink: kit + optional extras + monthly plan' };
    var bg   = { lte:'#F3E8FF', sim:'#FFF3E0', fiber:'#E8F5E9', starlink:'#E3F2FD' };
    var col  = { lte:'#6D28D9', sim:'#E65100', fiber:'#2E7D32', starlink:'#D41C1C' };
    st.textContent    = msgs[typeKey] || msgs.starlink;
    sb.style.background = bg[typeKey] || bg.starlink;
    st.style.color      = col[typeKey]|| col.starlink;
  }
  var prev = document.getElementById('wCrmPreview');
  if (prev) prev.textContent = { starlink:'STAR######', fiber:'FTTH######', sim:'SIM######', lte:'LTE######' }[typeKey] || 'STAR######';

  // Apply smart defaults (cart + plan)
  applyDefaults(typeKey);

  // Show/hide area field (Fiber only)
  var areaField = document.getElementById('wAreaField');
  var areaSearch = document.getElementById('wAreaSearch');
  if (areaField) areaField.style.display = isFiber ? 'block' : 'none';
  if (areaSearch) areaSearch.required = isFiber;
  if (!isFiber) { areaSelect(''); } // clear when switching away from fiber
  // On load: if area already set (e.g. edit mode), show pill
  if (isFiber) {
    var _preArea = (document.getElementById('wFiberArea') || {}).value || '';
    if (_preArea) areaSelect(_preArea);
  }

  // Show/hide Starlink kit scan block
  kitScanVisibility();
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §E.1): "Who installs?" shown, hidden or cleared with the service type
  q102InstallSync();
<?php endif; ?>
}

// ── Sync outer hidden fields (always submitted regardless of panel visibility) ──
function syncOuterFields() {
  // package_choice
  var pcOut = document.getElementById('pkgChoiceOut');
  if (pcOut && selPlan) pcOut.value = selPlan.id;

  // device_id (primary cart item or first hw item)
  var diOut = document.getElementById('deviceIdOut');
  if (diOut) diOut.value = hwCart.length ? hwCart[0].id : '';

  // hw_cart_json
  var hcOut = document.getElementById('hwCartOut');
  if (hcOut) hcOut.value = JSON.stringify(hwCart);

  // connectivity_type
  var ctOut = document.getElementById('connTypeOut');
  if (ctOut) {
    var checkedConn = document.querySelector('input[name="connectivity_type"]:checked');
    if (checkedConn) ctOut.value = checkedConn.value;
  }

  // customer_type
  var custOut = document.getElementById('custTypeOut');
  if (custOut) {
    var checkedCust = document.querySelector('input[name="customer_type"]:checked');
    if (checkedCust) custOut.value = checkedCust.value;
  }

  // also keep in-panel fields in sync (they may not submit, but keep consistent)
  var hwCartJson = document.getElementById('hwCartJson');
  if (hwCartJson) hwCartJson.value = JSON.stringify(hwCart);
  var selPriceEl = document.getElementById('selPlanPrice');
  if (selPriceEl && selPlan) selPriceEl.value = selPlan.price;
  var selNameEl = document.getElementById('selPlanName');
  if (selNameEl && selPlan) selNameEl.value = selPlan.name;
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5): direct mode */ ?>
  // 5.18.103 (§G.5): the direct cart as ucrm_cart_json; the plan posts from its own radio (ucrm_plan) — the three
  // plugin-id fields above are not drawn in direct mode, so the lines that set them find nothing
  q103SyncCart();
<?php endif; /* 5.18.103 */ ?>
}

// ── Form submit with validation ───────────────────────────────────
function kycFormSubmit() {
  syncOuterFields();
  if (!selPlan) { alert('Please select a service plan before submitting.'); return false; }
<?php if ($_q103 !== null): /* 5.18.103 (root docs/72 §G.5): a plan is selected — its own radio, ucrm_plan, the one the total was made from; there is no package_choice in direct mode */ ?>
  var uplan = document.querySelector('input[name="ucrm_plan"]:checked');
  if (!uplan || !uplan.value || uplan.value !== String(selPlan.id)) { alert('Please select a service plan before submitting.'); return false; }
<?php else: ?>
  var pcOut = document.getElementById('pkgChoiceOut');
  if (!pcOut || !pcOut.value) { alert('Please select a service plan before submitting.'); return false; }
<?php endif; /* 5.18.103 */ ?>

  // ── Client-side wallet balance check for Cash sales ─────────────
  var stEl = document.getElementById('wSalesType');
  var isCash = stEl && stEl.value === 'Cash';
<?php if ($_q102 !== null): ?>
  // 5.18.102 (Uganda, docs/71 §E.1, §G): the installation answer checked again; Cash refused while the total is not
  // priced in uCRM; the wallet checked against the one total (q102Quote) — the same total ucrm_total_seen now posts
  var q = q102Refresh();
  if (!q102InstallCheck()) {
    alert('Choose who installs — step 3 (Plan): "DishNet installs" or "Customer installs (kit only)".');
    return false;
  }
  if (isCash && q.status === 'unpriced') {
    // 5.18.102 (D14, docs/71 §R.4.1): the reason carries its own remedy ("an admin links it…"), so the tail is short
    alert('Cash is not possible: this sale is not priced in uCRM (' + q102Why(q.reason) + ').\n\nNothing was submitted. Register as Credit (step 4), or ask an admin.');
    return false;
  }
  if (isCash && typeof _kycWalletBalance !== 'undefined' && q.status === 'priced') {
    var orderTotal = q.total;
    if (orderTotal > 0 && _kycWalletBalance < orderTotal) {
      alert('Insufficient wallet balance.\n\nRequired: ' + _q102Cur + orderTotal.toFixed(2) + '\nAvailable: ' + _q102Cur + _kycWalletBalance.toFixed(2) + '\n\nPlease recharge your wallet or switch to Credit sale.');
      return false;
    }
  }
<?php else: ?>
  if (isCash && typeof _kycWalletBalance !== 'undefined') {
    var hwTotal = 0;
    if (typeof hwCart !== 'undefined') {
      hwCart.forEach(function(i){ hwTotal += parseFloat(i.price || 0) * parseInt(i.qty || 1); });
    }
    var planPrice = selPlan ? parseFloat(selPlan.price || 0) : 0;
    var instFee = (curType === 'fiber' && typeof _kycFiberInstallFee !== 'undefined') ? _kycFiberInstallFee : 0;
    var orderTotal = hwTotal + planPrice + instFee;
    if (orderTotal > 0 && _kycWalletBalance < orderTotal) {
      alert('Insufficient wallet balance.\n\nRequired: ' + <?= json_encode(dn_cur($config)) ?> + orderTotal.toFixed(2) + '\nAvailable: ' + <?= json_encode(dn_cur($config)) ?> + _kycWalletBalance.toFixed(2) + '\n\nPlease recharge your wallet or switch to Credit sale.');
      return false;
    }
  }
<?php endif; ?>

  return confirm('Submit this KYC application?\nPlan: ' + (selPlan ? selPlan.name : '') + '\nAmount will be debited from your wallet if Cash payment.');
}

// ── Init on DOM ready ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  wizTypeChange(); // Starlink defaults on first load
  syncOuterFields(); // Sync initial defaults to outer hidden fields
  var st = document.getElementById('wSalesType');
  if (st && typeof wizSalesType === 'function') wizSalesType(st);
});

// ── wizSalesType — show/hide wallet deduction warning ────────────
function wizSalesType(sel) {
  var w = document.getElementById('cashWarning');
  if (!w) return;
  var isCash = (sel.value || sel) === 'Cash' || (sel.value === 'Cash');
  // handle both element and string
  if (typeof sel === 'object') isCash = sel.value === 'Cash';
  else isCash = sel === 'Cash';
  w.style.display = isCash ? 'flex' : 'none';
}

/**
 * compressImage(file, maxPx, quality) → Promise<File>
 * Resizes to maxPx on longest side, exports as JPEG. PDFs pass through unchanged.
 */
function compressImage(file, maxPx, quality) {
  maxPx   = maxPx   || 1280;
  quality = quality || 0.82;
  if (file.type === 'application/pdf') return Promise.resolve(file);
  return new Promise(function(resolve, reject) {
    var reader = new FileReader();
    reader.onerror = reject;
    reader.onload = function(e) {
      var img = new Image();
      img.onerror = reject;
      img.onload = function() {
        var w = img.naturalWidth, h = img.naturalHeight;
        if (w > maxPx || h > maxPx) {
          if (w >= h) { h = Math.round(h * maxPx / w); w = maxPx; }
          else        { w = Math.round(w * maxPx / h); h = maxPx; }
        }
        var canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        canvas.toBlob(function(blob) {
          if (!blob) { resolve(file); return; }
          var compressed = new File(
            [blob],
            file.name.replace(/\.[^.]+$/, '.jpg'),
            { type: 'image/jpeg', lastModified: Date.now() }
          );
          resolve(compressed);
        }, 'image/jpeg', quality);
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });
}

// ── wizPreview — compress then preview ───────────────────────────
function wizPreview(input) {
  var isPhoto = (input.name === 'customer_image');
  var previewId = isPhoto ? 'wImgPreview' : 'wDocPreview';
  var metaId    = isPhoto ? 'photoMeta' : 'docMeta';
  var uploadId  = isPhoto ? 'uploadPhoto' : 'uploadDoc';
  var el   = document.getElementById(previewId);
  var meta = document.getElementById(metaId);
  var box  = document.getElementById(uploadId);
  var f    = input.files && input.files[0];
  if (!f) return;
  if (f.type.startsWith('image/')) {
    compressImage(f, 1280, 0.82).then(function(compressed) {
      // Replace input file list with compressed version
      var dt = new DataTransfer();
      dt.items.add(compressed);
      input.files = dt.files;
      // Show large preview
      var r = new FileReader();
      r.onload = function(e) {
        if (el) { el.src = e.target.result; el.style.display = 'block'; }
        if (box) { box.style.display = 'none'; }
        var kb = Math.round(compressed.size / 1024);
        if (meta) { meta.style.display = 'block'; meta.textContent = '✅ ' + (isPhoto ? 'Photo' : 'ID') + ' ready (' + kb + ' KB) · Tap image to change'; }
      };
      r.readAsDataURL(compressed);
    });
  } else {
    // PDF — show filename, keep upload box visible
    if (el) el.style.display = 'none';
    if (meta) { meta.style.display = 'block'; meta.textContent = '📄 ' + f.name + ' · Tap to change'; meta.style.color = '#6b7280'; }
  }
}
// ── wizDetectLoc — GPS auto-detect for customer address ──────────
function wizDetectLoc() {
  if (!navigator.geolocation) { alert('Geolocation not supported by this browser.'); return; }
  var btn = (event && event.currentTarget) || null;
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Detecting...'; }
  navigator.geolocation.getCurrentPosition(function(pos) {
    var lat = pos.coords.latitude.toFixed(6);
    var lng = pos.coords.longitude.toFixed(6);
    var latEl = document.querySelector('input[name="latitude"]');
    var lngEl = document.querySelector('input[name="longitude"]');
    if (latEl) latEl.value = lat;
    if (lngEl) lngEl.value = lng;
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-crosshair"></i> Detect GPS'; }
  }, function(err) {
    alert('GPS error: ' + err.message);
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-crosshair"></i> Detect GPS'; }
  }, { timeout: 10000, enableHighAccuracy: true });
}

// ── wizSimUpdate — sync SIM fields into review panel ─────────────
function wizSimUpdate() {
  var iccid  = (document.querySelector('input[name="sim_iccid"]')  || {}).value || '';
  var msisdn = (document.querySelector('input[name="sim_msisdn"]') || {}).value || '';
  var rIccid  = document.getElementById('reviewSimIccid');
  var rMsisdn = document.getElementById('reviewSimMsisdn');
  if (rIccid)  rIccid.textContent  = iccid  || '—';
  if (rMsisdn) rMsisdn.textContent = msisdn || '—';
}
</script>


<!-- ═══ STEP 4: KYC Documents + Sales Info ═════════════════════════════ -->
<div class="wiz-panel" data-panel="4">
<div class="kyc-card">
<div class="kyc-card-body">
    <div class="wiz-section-title"><i class="bi bi-card-image"></i> Identity Verification</div>
    <div class="wiz-row">
        <div class="wiz-field">
            <label>Customer Photo</label>
            <div class="wiz-upload" onclick="this.querySelector('input').click()" id="uploadPhoto">
                <i class="bi bi-camera" id="photoIcon"></i>
                <span class="wiz-upload-label">Tap to capture or upload</span>
                <input type="file" name="customer_image" accept="image/*" capture="user" onchange="wizPreview(this)">
            </div>
            <img id="wImgPreview" style="display:none;width:100%;max-height:200px;object-fit:cover;border-radius:12px;margin-top:8px;border:2px solid #10b981;cursor:pointer;" onclick="document.querySelector('[name=customer_image]').click()" title="Tap to change photo">
            <div id="photoMeta" style="display:none;font-size:11px;color:#059669;font-weight:600;margin-top:4px;text-align:center;">✅ Photo ready · Tap image to change</div>
        </div>
        <div class="wiz-field">
            <label>ID Document</label>
            <div class="wiz-upload" onclick="this.querySelector('input').click()" id="uploadDoc">
                <i class="bi bi-card-heading" id="docIcon"></i>
                <span class="wiz-upload-label">National ID / Passport</span>
                <input type="file" name="id_document" accept="image/*,.pdf" capture="environment" onchange="wizPreview(this)">
            </div>
            <img id="wDocPreview" style="display:none;width:100%;max-height:200px;object-fit:contain;border-radius:12px;margin-top:8px;border:2px solid #3b82f6;cursor:pointer;" onclick="document.querySelector('[name=id_document]').click()" title="Tap to change document">
            <div id="docMeta" style="display:none;font-size:11px;color:#2563eb;font-weight:600;margin-top:4px;text-align:center;">✅ ID ready · Tap image to change</div>
        </div>
    </div>

    <div class="wiz-section-title" style="margin-top:18px;"><i class="bi bi-receipt"></i> Sales Details</div>
    <div class="wiz-row">
        <div class="wiz-field">
            <label>Sales Person <span style="font-size:10px;color:#2E7D32;font-weight:600;">(auto — your account)</span></label>
            <?php
              // Sales person is ALWAYS the logged-in agent's name — locked, not editable.
              // This ensures UCRM customAttribute[1] always matches the submitting agent.
              $agentName = trim($retailer['name'] ?? '');
            ?>
            <input type="hidden" name="sales_person" value="<?= h($agentName) ?>">
            <div style="padding:10px 14px;background:#E8F5E9;border-radius:10px;border:1.5px solid #A5D6A7;
                        font-size:14px;font-weight:700;color:#1B5E20;display:flex;align-items:center;gap:8px;">
              <i class="bi bi-person-check" style="font-size:16px;"></i>
              <?= h($agentName) ?>
              <span style="font-size:10px;color:#4CAF50;margin-left:auto;">🔒 Locked to your account</span>
            </div>
        </div>
        <div class="wiz-field">
            <label>How did customer hear about us?</label>
            <select name="ref">
                <option value="">— Select —</option>
                <?php foreach ($config['sales_persons'] as $sp): ?><option value="<?= h($sp) ?>"><?= h($sp) ?></option><?php endforeach; ?>
                <option value="Social Media">Social Media</option>
                <option value="Friend">Friend / Referral</option>
                <option value="Walk-in">Walk-in</option>
            </select>
        </div>
    </div>
    <div class="wiz-row">
        <div class="wiz-field">
            <label>Payment Type</label>
            <select name="sales_type" id="wSalesType" onchange="wizSalesType(this)">
                <option value="Cash" <?= ($pf['sales_type']??'Credit')==='Cash'?'selected':'' ?>>Cash (Wallet Debit) &#8594; Regular Customer</option>
                <option value="Credit" <?= ($pf['sales_type']??'Credit')==='Credit'?'selected':'' ?>>Credit &#8594; CRM Lead</option>
            </select>
<?php if ($_q102 !== null): /* 5.18.102 (Uganda, docs/71 §G): why Cash is disabled, written by q102Refresh() */ ?>
            <div id="q102CashNote" style="display:none;font-size:11px;font-weight:700;color:#b91c1c;margin-top:6px;line-height:1.4;"></div>
<?php endif; ?>
        </div>
        <div class="wiz-field">
            <label>Priority</label>
            <select name="priority">
                <option value="Low">Low</option>
                <option value="Medium" selected>Medium</option>
                <option value="High">High</option>
            </select>
        </div>
    </div>

    <div class="wiz-nav">
        <button type="button" class="wiz-btn wiz-btn-back" onclick="wizBack()"><i class="bi bi-arrow-left"></i> Back</button>
        <button type="button" class="wiz-btn wiz-btn-next" onclick="wizNext()">Review Application <i class="bi bi-arrow-right"></i></button>
    </div>
</div>
</div>
</div>

<!-- ═══ STEP 5: Review & Submit ════════════════════════════════════════ -->
<div class="wiz-panel" data-panel="5">
<div class="kyc-card">
<div class="kyc-card-body" style="padding-bottom:8px;">
    <div class="wiz-section-title" style="margin-bottom:14px;">
        <i class="bi bi-clipboard-check"></i> Review & Confirm
        <span style="margin-left:auto;font-size:11px;font-weight:600;color:#6b7280;">Check all details before submitting</span>
    </div>

    <!-- Review content injected by wizBuildReview() -->
    <div id="wReviewContent" style="margin-bottom:14px;"></div>

    <!-- Terms checkbox -->
    <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <input type="checkbox" id="wTerms" required style="width:20px;height:20px;accent-color:#16a34a;flex-shrink:0;cursor:pointer;">
        <label for="wTerms" style="font-size:13px;font-weight:700;color:#15803d;cursor:pointer;line-height:1.4;">
            I confirm all details above are correct and the customer agrees to DishNet Africa Terms &amp; Conditions
        </label>
    </div>

    <!-- Navigation -->
    <div class="wiz-nav">
        <button type="button" class="wiz-btn wiz-btn-back" onclick="wizBack()">
            <i class="bi bi-arrow-left"></i> Back &amp; Edit
        </button>
        <button type="submit" class="wiz-btn wiz-btn-submit" id="btnSubmit"
            style="background:linear-gradient(135deg,#16a34a,#15803d);border:none;padding:12px 28px;font-size:14px;font-weight:800;">
            <i class="bi bi-check-circle-fill"></i> Submit Application
        </button>
    </div>
</div>
</div>
</div>

</form>
