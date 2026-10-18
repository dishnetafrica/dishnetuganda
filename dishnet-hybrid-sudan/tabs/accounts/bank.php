<?php
/**
 * tabs/accounts/bank.php — the Bank screen v1 (5.18.97; SPEC-bank §c): Ecobank statements, shown the way the Cashbook
 * shows cash, posted nowhere. Uganda only; reached from the menu item ECO Bank, directly below Cashbook, and from the CEO
 * page's Quick Links, each shown by BankGate::showLink() alone (the operator, 18 Oct: it supersedes O9's "no menu link"),
 * or by its address (?page=dashboard&tab=bank).
 *
 *   one account at a time   a chip per active Ecobank account of the book (lib/BankStatementStore::offeredAccounts()),
 *                           never an "All": UGX and USD are never added together
 *   the cards               every offered account's card at once, UGX first (5.18.97, the operator, 18 Oct: "two hero
 *                           section like ECO Bank UGX and ECO BANK USD"): each the bank's OWN balance for that account —
 *                           the running balance of its kept line with the highest bank line number — "as stated by
 *                           Ecobank", slate grey so it can never read as cash in hand. One card per account, never per
 *                           currency; never added together, and no total. Nothing from the Cashbook is shown beside
 *                           them (O6), and cb_ledger is never read
 *   the lines               newest first by Cheque Number, 50 a page, each with the bank's own running balance; a
 *                           month filter with its opening / in / out / closing strip; the export (includes/routes.php)
 *   Statements              every check of the account: refused, waiting, kept, discarded, expired
 *   the check panel         drawn from the STORED check (bk_check=<id>), never from the flash
 *
 * Gated first, before anything is drawn: BankGate::open() (Uganda, no SSP, the switch) and BankGate::allows() on the
 * FRESH staff record (admins only in v1). public.php's $_tabPerms['bank'] = '*admin' is the dispatcher's own check.
 *
 * Every value from a statement goes through h() on every render. The page writes nothing (a GET never writes: an expired
 * check is shown as expired, and the next POST expires it). No GET parameter carries statement text: bk_acct, bk_view,
 * bk_month, bk_page and bk_check are an id, a word, a month, a number and an id. The tab's script writes no HTML: it opens
 * and closes the upload sheet (choosing the account of the button that opened it, its data-bk-for), checks the file's
 * size, and echoes the chosen file's name with textContent.
 *
 * In scope from public.php: $store, $dataDir, $retailer, csrfField(), h(). Every variable here starts with $_bk, so the
 * page's own ($page, $tab, $config, …) are never touched.
 */
require_once dirname(__DIR__, 2) . '/lib/BankGate.php';
if (!BankGate::open($dataDir ?? null)) {
    echo '<div style="padding:24px;color:#6b7280;">ECO Bank is not available on this installation.</div>';
    return;
}
$_bkMe = BankGate::allows('bank.view', $store ?? null, is_array($retailer ?? null) ? $retailer : []);
if ($_bkMe === null) {
    echo '<div style="color:#b91c1c;padding:24px;font-weight:600;">Access denied.</div>';
    return;
}
$_bkImport = BankGate::allows('bank.import', $store, is_array($retailer ?? null) ? $retailer : []) !== null;

require_once dirname(__DIR__, 2) . '/lib/currency.php';
require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
require_once dirname(__DIR__, 2) . '/lib/EcobankDetailsCsv.php';
require_once dirname(__DIR__, 2) . '/lib/BankStatementStore.php';

$_bkCurrs = dn_book_currencies(PluginConfig::read(dirname(__DIR__, 2), (string)$dataDir));
$_bkStore = new BankStatementStore($store->getPdo(), (string)$dataDir, $_bkCurrs);
$_bkReady = $_bkStore->ready();
$_bkAccts = $_bkReady ? $_bkStore->offeredAccounts() : [];

// ── The address: every parameter validated; none carries statement text ───────────────────────────────────────────
$_bkGet = function (string $k): string { $v = $_GET[$k] ?? ''; return is_string($v) ? $v : ''; };
$_bkAcct = null;
$_bkWant = ctype_digit($_bkGet('bk_acct')) ? (int)$_bkGet('bk_acct') : 0;
foreach ($_bkAccts as $_bkA) if ($_bkA['id'] === $_bkWant) $_bkAcct = $_bkA;
if ($_bkAcct === null && $_bkAccts) $_bkAcct = $_bkAccts[0];          // the first in the book's currency order (UGX first)
$_bkView  = $_bkGet('bk_view') === 'statements' ? 'statements' : 'lines';
$_bkMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_bkGet('bk_month')) ? $_bkGet('bk_month') : '';
$_bkPage  = ctype_digit($_bkGet('bk_page')) ? max(1, (int)$_bkGet('bk_page')) : 1;
$_bkCheck = ctype_digit($_bkGet('bk_check')) ? (int)$_bkGet('bk_check') : 0;
$_bkPer   = 50;
/** An address of this screen. Only the five keys of §c.2, plus the export's own. */
$_bkUrl = function (array $q): string {
    $keep = [];
    foreach (['bk_acct', 'bk_view', 'bk_month', 'bk_page', 'bk_check', 'bk_export'] as $k) {
        if (isset($q[$k]) && (string)$q[$k] !== '') $keep[$k] = (string)$q[$k];
    }
    return '?page=dashboard&tab=bank' . ($keep ? '&' . http_build_query($keep) : '');
};

// ── Small helpers: exact money from minor units, dates without strtotime(), a name never showing a full number ──────
$_bkMoney = function (string $cur, int $u): string {
    $a = $u < 0 ? -$u : $u;
    return $cur . ' ' . ($u < 0 ? '-' : '') . number_format(intdiv($a, 100)) . '.' . sprintf('%02d', $a % 100);
};
$_bkSigned = function (string $cur, int $u, bool $in) use ($_bkMoney): string { return ($in ? '+' : '−') . $_bkMoney($cur, $u); };
$_bkDate = function (string $iso): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) return '';
    return $m[3] . ' ' . ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][(int)$m[2] - 1] . ' ' . $m[1];
};
$_bkMonthName = function (string $ym) use ($_bkDate): string { return $ym === '' ? '' : substr($_bkDate($ym . '-01'), 3); };
$_bkStamp = function (?string $utc): string {                       // a stored UTC stamp, in the server's own zone
    if ($utc === null || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utc)) return '';
    try {
        $d = new \DateTime($utc, new \DateTimeZone('UTC'));
        $d->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $d->format('d M Y H:i');
    } catch (\Throwable $e) {
        return '';
    }
};
$_bkMask = function (string $name): string {                        // a run of 5+ digits shows as •••• and its last four
    return (string)preg_replace_callback('/\d{5,}/', function (array $m): string { return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr($m[0], -4); }, $name);
};
$_bkUpper = function (string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s); };
$_bkCut = function (string $t, int $n): string { return preg_match('/^.{' . $n . '}(?=.)/su', $t, $m) ? $m[0] . '…' : $t; };
$_bkFlag = function (string $cur): string {
    $map = ['USD' => "\u{1F4B5}", 'SSP' => "\u{1F1F8}\u{1F1F8}", 'UGX' => "\u{1F1FA}\u{1F1EC}"];   // the Cashbook's $_cbFlagMap
    return $map[$cur] ?? "\u{1F4B1}";
};
$_bkWhoCache = [];
$_bkWho = function (?int $id) use ($store, &$_bkWhoCache): string {  // a staff name, read by id; never a statement field
    $id = (int)$id;
    if ($id <= 0) return '';
    if (!array_key_exists($id, $_bkWhoCache)) {
        try { $r = $store->findOne('retailers.json', 'id', $id); } catch (\Throwable $e) { $r = null; }
        $_bkWhoCache[$id] = is_array($r) ? trim((string)($r['name'] ?? '')) : '';
    }
    return $_bkWhoCache[$id];
};
$_bkSay = function (string $code, string $param = ''): string { return EcobankDetailsCsv::sentence($code, $param); };
$_bkStatusText = function (string $s): string { return $s === 'imported' ? 'Not compared' : 'Unknown status'; };
$_bkCap = EcobankDetailsCsv::effectiveCap();
$_bkCapMb = EcobankDetailsCsv::capMb($_bkCap);
?>
<style>
/* The Bank screen v1 (5.18.97). The Cashbook's shell, copied under its own class names (tabs/accounts/cashbook.php is not
   included and not touched); the screen's own rules carry a bk- prefix. Its colours live on .bk-wrap, not :root. */
.bk-wrap{--red:#D41C1C;--dark:#0f0f0f;--green:#059669;--amber:#d97706;--mute:#94a3b8;--bg:#f5f5f2;--border:#e8e8e3;
  font-family:'Inter',-apple-system,'Segoe UI',sans-serif;}
.bk-wrap *{box-sizing:border-box;}
.cb3-wrap{display:flex;flex-direction:column;min-height:calc(100dvh - 56px);background:var(--bg);padding-bottom:80px;}
.cb3-bar{background:var(--dark);padding:0 14px;display:flex;align-items:center;gap:10px;height:52px;position:sticky;top:0;z-index:200;}
.cb3-back{display:flex;align-items:center;justify-content:center;width:34px;height:34px;background:rgba(255,255,255,.08);border-radius:9px;text-decoration:none;color:#fff;font-size:17px;flex-shrink:0;border:none;cursor:pointer;}
.cb3-title{font-size:17px;font-weight:800;color:#fff;letter-spacing:.3px;flex:1;}
.cb3-fab{background:var(--red);color:#fff;border:none;border-radius:9px;padding:7px 13px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.cb3-tabs{background:#fff;border-bottom:2px solid var(--border);display:flex;overflow-x:auto;scrollbar-width:none;}
.cb3-tabs::-webkit-scrollbar{display:none;}
.cb3-tab{padding:11px 14px;font-size:12px;font-weight:600;color:var(--mute);white-space:nowrap;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:4px;}
.cb3-tab.on{color:var(--red);border-bottom-color:var(--red);}
.cb3-badge{background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;}
.cb3-cards{padding:8px 12px;display:flex;flex-direction:column;gap:7px;}
.cb3-card-amt{font-size:14px;font-weight:700;white-space:nowrap;flex-shrink:0;}
.cb3-card-amt.in{color:var(--green);}
.cb3-card-amt.out{color:#dc2626;}
.cb3-card-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:11px;color:var(--mute);font-weight:500;}
.cb3-tbl-wrap{display:none;overflow-x:auto;background:#fff;}
.cb3-pager{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#fff;border-top:1px solid var(--border);gap:8px;flex-wrap:wrap;}
.cb3-pg-info{font-size:12px;color:var(--mute);font-weight:500;}
.cb3-pg-btns{display:flex;gap:4px;flex-wrap:wrap;}
.cb3-pg{padding:6px 11px;border:1px solid var(--border);border-radius:7px;background:#fff;font-size:12px;font-weight:500;color:#374151;text-decoration:none;}
.cb3-pg.on{background:var(--dark);color:#fff;border-color:var(--dark);}
.cb3-empty{padding:28px 16px;text-align:center;color:var(--mute);font-size:13px;}
.cb3-filter-btn{padding:8px 12px;background:#f8f8f5;border:1.5px solid var(--border);border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;color:#374151;text-decoration:none;font-family:inherit;}
.cbv2-fi{padding:7px 10px;border:1.5px solid #e5e5e0;border-radius:7px;font-size:12px;font-family:inherit;background:#fff;color:#0a0a0a;outline:none;}
.cbv2-fi:focus{border-color:#D41C1C;}
.cbv2-noseed{background:#fffbeb;border:2px solid #fde68a;border-radius:14px;padding:28px 24px;margin:20px;text-align:center;color:#78350f;font-weight:600;}
.cbv2-bsave{background:#D41C1C;color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
.cbv2-bcancel{background:#f8fafc;color:#374151;border:1px solid #e5e5e0;border-radius:9px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;}
.cb4-mo{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9000;align-items:flex-end;justify-content:center;}
.cb4-mo.open{display:flex;}
.cb4-mb{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:560px;max-height:92dvh;display:flex;flex-direction:column;margin-bottom:calc(60px + env(safe-area-inset-bottom,0px));}
@media(min-width:600px){.cb4-mo{align-items:center;}.cb4-mb{border-radius:18px;margin:auto;max-height:88vh;}}
.cb4-mh{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;border-radius:18px 18px 0 0;}
.cb4-mh.neutral{background:#0f0f0f;}
.cb4-mt{font-size:15px;font-weight:900;color:#fff;letter-spacing:.3px;}
.cb4-msub{font-size:11px;color:rgba(255,255,255,.5);margin-top:1px;}
.cb4-mclose{width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:none;cursor:pointer;font-size:14px;color:rgba(255,255,255,.7);}
.cb4-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;}
.cb4-mbody{padding:18px 20px;}
/* the Bank screen's own */
.bk-head{padding:12px 14px 4px;font-size:11px;color:#64748b;font-weight:600;}
.bk-chips{display:flex;gap:6px;flex-wrap:wrap;padding:4px 14px 10px;}
.bk-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;text-decoration:none;color:#0f0f0f;background:#fff;border:1.5px solid var(--border);}
.bk-chip.on{background:#0f0f0f;color:#fff;border-color:#0f0f0f;}
.bk-chip-nl{font-size:10px;font-weight:600;color:#94a3b8;}
.bk-card{position:relative;overflow:hidden;margin:0 14px 10px;border-radius:16px;padding:18px 20px;color:#fff;background:linear-gradient(135deg,#0f172a,#1e293b);}
.bk-card-o{position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.06);border-radius:50%;}
.bk-card-l{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.55);margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.bk-ccy{font-size:9px;font-weight:800;letter-spacing:.5px;padding:2px 7px;border-radius:10px;color:#fff;background:#475569;}
.bk-ccy.UGX{background:#1a6b3a;}
.bk-ccy.USD{background:#1a3a7a;}
.bk-card-v{font-size:clamp(26px,9vw,42px);font-weight:900;letter-spacing:-1.5px;line-height:1.05;word-break:break-word;}   /* 42 px as the Cashbook's card, smaller only where a phone needs it */
.bk-card-s{font-size:11px;color:rgba(255,255,255,.5);margin-top:6px;}
.bk-pill{display:inline-block;margin-top:10px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;background:rgba(255,255,255,.15);color:#fff;}
/* 5.18.97: every offered account's card at once, stacked as the Cashbook stacks its two; the account in view ringed */
.bk-hero{display:flex;flex-direction:column;}
.bk-card.on{box-shadow:0 0 0 2px #fff,0 0 0 4px #334155;}
.bk-card-n{font-size:11px;font-weight:600;color:rgba(255,255,255,.7);margin:-2px 0 6px;}
.bk-card-e{font-size:22px;letter-spacing:-.5px;}
.bk-card-f{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;}
.bk-card-f .bk-pill{margin-top:0;}
.bk-card-a{font-size:11px;font-weight:700;color:#cbd5e1;}
.bk-card.none{background:#e2e8f0;color:#475569;}
.bk-card.none .bk-card-l,.bk-card.none .bk-card-s{color:#475569;}
.bk-panel{margin:12px 14px 4px;border-radius:12px;padding:14px 16px;border:1.5px solid;}
.bk-panel.g{background:#f0fdf4;border-color:#bbf7d0;color:#14532d;}
.bk-panel.b{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a;}
.bk-panel.r{background:#fef2f2;border-color:#fecaca;color:#7f1d1d;}
.bk-panel.n{background:#f8fafc;border-color:#e2e8f0;color:#334155;}
.bk-panel-h{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;font-size:14px;font-weight:800;}
.bk-panel-x{text-decoration:none;color:inherit;font-size:14px;opacity:.6;}
.bk-panel-t{font-size:13px;margin-top:6px;line-height:1.45;}
.bk-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;}
@media(min-width:700px){.bk-grid{grid-template-columns:repeat(4,1fr);}}
.bk-gi{background:rgba(255,255,255,.7);border-radius:8px;padding:8px 10px;}
.bk-gl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;opacity:.65;}
.bk-gv{font-size:13px;font-weight:700;margin-top:2px;word-break:break-word;}
.bk-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.bk-keep{background:#059669;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
.bk-link{margin-top:10px;font-size:13px;font-weight:700;}
.bk-filter{background:#fff;border-bottom:1px solid var(--border);padding:10px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;position:sticky;top:52px;z-index:100;}
.bk-csv{background:#0f0f0f;color:#fff;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;}
.bk-up{background:var(--red);color:#fff;border:none;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.bk-strip{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);border-bottom:1px solid var(--border);}
@media(min-width:700px){.bk-strip{grid-template-columns:repeat(4,1fr);}}
.bk-sc{background:#fff;padding:12px 14px;}
.bk-sl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);}
.bk-sv{font-size:16px;font-weight:900;margin-top:3px;word-break:break-word;}
.bk-strip-note{background:#fff;padding:8px 14px;font-size:11px;font-weight:700;border-bottom:1px solid var(--border);}
.bk-ok{color:#059669;}
.bk-bad{color:#dc2626;}
.bk-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.bk-tbl th{background:#f8fafc;padding:8px 10px;text-align:left;font-size:9px;font-weight:800;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);white-space:nowrap;}
.bk-tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
.bk-tbl td.bk-n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;}
.bk-chq{font-family:monospace;font-size:11px;color:#475569;white-space:nowrap;}
.bk-sub{font-size:10px;color:var(--mute);}
.bk-st{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:800;white-space:nowrap;background:#f1f5f9;color:#475569;}
.bk-st.u{background:#f8fafc;color:#94a3b8;}
.bk-line{background:#fff;border-radius:12px;border:1px solid var(--border);padding:10px 12px;}
.bk-line-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px;}
.bk-line-desc{font-size:13px;font-weight:600;color:#0f0f0f;line-height:1.35;flex:1;min-width:0;white-space:normal;word-break:break-word;}
.bk-line-bal{font-size:11px;font-weight:700;color:#334155;margin-top:4px;text-align:right;}
.bk-cover{margin:10px 14px;font-size:12px;color:#475569;line-height:1.45;}
.bk-note{font-size:10px;color:#92400e;}
.bk-form .bk-fl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);display:block;margin:0 0 5px;}
.bk-form .bk-fg{margin-bottom:14px;}
.bk-form select,.bk-form input[type=file]{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:16px;background:#fff;}
.bk-fname{font-size:12px;color:#334155;margin-top:6px;word-break:break-all;}
.bk-help{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;font-size:12px;color:#14532d;margin-bottom:10px;line-height:1.45;}
.bk-warn{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:12px;color:#78350f;margin-bottom:10px;line-height:1.45;}
.bk-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-size:12px;color:#7f1d1d;margin-bottom:10px;}
.bk-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;background:#fff;}
@media(min-width:700px){
  .cb3-cards{display:none;}
  .cb3-tbl-wrap{display:block!important;}
  .cb3-bar{position:static;}
  .bk-filter{top:0;position:static;}
}
</style>
<div class="cb3-wrap bk-wrap">
  <div class="cb3-bar">
    <a class="cb3-back" href="?page=dashboard" aria-label="Back">&#8592;</a>
    <div class="cb3-title">ECO Bank</div>
    <?php if ($_bkImport && $_bkReady && $_bkAcct !== null): ?><button type="button" class="cb3-fab" data-bk-open="1" data-bk-for="<?= (int)$_bkAcct['id'] ?>">&#65291; Statement</button><?php endif; ?>
  </div>
<?php
if (!$_bkReady) {
    echo '<div class="cbv2-noseed">ECO Bank is not ready on this installation.</div></div>';
    return;
}
if ($_bkAcct === null) {
    echo '<div class="cbv2-noseed">The book has no active bank account in ' . h(implode(' or ', $_bkCurrs)) . '.</div></div>';
    return;
}
$_bkId      = (int)$_bkAcct['id'];
$_bkCur     = (string)$_bkAcct['currency'];
$_bkName    = $_bkMask((string)$_bkAcct['name']);
$_bkProfile = $_bkStore->profile($_bkId);

// ── The check panel: drawn from the stored check, never from the flash ─────────────────────────────────────────────
$_bkChk = $_bkCheck > 0 ? $_bkStore->checkRow($_bkCheck) : null;
if ($_bkChk !== null && (int)$_bkChk['cb_account_id'] !== $_bkId) $_bkChk = null;
if ($_bkChk !== null):
    $_bkCs = (string)$_bkChk['status'];
    $_bkCo = (string)$_bkChk['outcome'];
    $_bkCx = $_bkUrl(['bk_acct' => $_bkId, 'bk_view' => $_bkView === 'lines' ? '' : $_bkView]);
    $_bkWaiting = $_bkCs === 'waiting' && !$_bkStore->isExpired($_bkChk);
    if ($_bkWaiting)                                            { $_bkTone = 'g'; $_bkHead = "\u{2713} This file passed every check."; $_bkText = 'Nothing is kept yet.'; }
    elseif ($_bkCs === 'waiting' || $_bkCs === 'expired')       { $_bkTone = 'n'; $_bkHead = 'Nothing was kept from this check.'; $_bkText = $_bkCs === 'waiting' ? $_bkSay('check_expired') : 'This check was closed without keeping anything. Check the file again.'; }
    elseif ($_bkCs === 'kept')                                  { $_bkTone = 'g'; $_bkHead = "\u{2713} This statement is kept."; $_bkText = ''; }
    elseif ($_bkCs === 'discarded')                             { $_bkTone = 'n'; $_bkHead = 'Discarded.'; $_bkText = 'Nothing was kept from this file.'; }
    elseif ($_bkCo === 'already_kept' || $_bkCo === 'nothing_new') { $_bkTone = 'b'; $_bkHead = 'Nothing was added.'; $_bkText = $_bkSay((string)$_bkChk['code'], (string)$_bkChk['param']); }
    else                                                        { $_bkTone = 'r'; $_bkHead = "\u{2717} This file was not kept."; $_bkText = $_bkSay((string)$_bkChk['code'], (string)$_bkChk['param']); }
?>
  <div class="bk-panel <?= $_bkTone ?>" id="bkPanel" data-bk-check="<?= (int)$_bkChk['id'] ?>" data-bk-state="<?= h($_bkCs . '/' . $_bkCo) ?>">
    <div class="bk-panel-h"><span><?= h($_bkHead) ?></span><a class="bk-panel-x" href="<?= h($_bkCx) ?>" aria-label="Close">&#10005;</a></div>
    <?php if ($_bkText !== ''): ?><div class="bk-panel-t" id="bkPanelText"><?= h($_bkText) ?></div><?php endif; ?>
    <?php if ($_bkWaiting || $_bkCs === 'kept'):
        $_bkSt = $_bkCs === 'kept' ? $_bkStore->statement((int)$_bkChk['statement_id']) : null; ?>
    <div class="bk-grid">
      <div class="bk-gi"><div class="bk-gl">Account</div><div class="bk-gv">ending <?= "\u{2022}\u{2022}\u{2022}\u{2022}" . h((string)$_bkChk['acct_last4']) ?> · <?= h((string)$_bkChk['currency']) ?></div>
        <?php if ($_bkProfile !== null): ?><div class="bk-sub">matches «<?= h($_bkName) ?>»</div><?php endif; ?></div>
      <div class="bk-gi"><div class="bk-gl">Period</div><div class="bk-gv"><?= h($_bkDate((string)$_bkChk['period_from'])) ?> – <?= h($_bkDate((string)$_bkChk['period_to'])) ?></div></div>
      <div class="bk-gi"><div class="bk-gl">Lines</div><div class="bk-gv" id="bkPanelLines"><?= (int)$_bkChk['line_count'] ?> in the file · <?= (int)$_bkChk['lines_new'] ?> new · <?= (int)$_bkChk['lines_known'] ?> already kept</div></div>
      <div class="bk-gi"><div class="bk-gl">Opening · closing (stated)</div><div class="bk-gv"><?= h($_bkMoney((string)$_bkChk['currency'], (int)$_bkChk['opening_stated'])) ?> · <?= h($_bkMoney((string)$_bkChk['currency'], (int)$_bkChk['closing_stated'])) ?></div></div>
      <div class="bk-gi"><div class="bk-gl">File id</div><div class="bk-gv"><?= h(substr((string)$_bkChk['sha256'], 0, 8)) ?></div></div>
      <?php if ($_bkSt !== null): ?><div class="bk-gi"><div class="bk-gl">Kept</div><div class="bk-gv"><?= h($_bkStamp((string)$_bkSt['kept_at'])) ?><?php $_bkK = $_bkWho((int)$_bkSt['kept_by_id']); if ($_bkK !== ''): ?> by <?= h($_bkK) ?><?php endif; ?></div></div><?php endif; ?>
    </div>
    <?php if ($_bkWaiting): ?>
    <div class="bk-panel-t">Keeping adds <?= (int)$_bkChk['lines_new'] ?> line<?= (int)$_bkChk['lines_new'] === 1 ? '' : 's' ?> to this screen. Nothing is posted to the Cashbook.</div>
    <?php if ($_bkProfile === null): ?>
    <div class="bk-link" id="bkLinkQ">This file is for an account ending <?= "\u{2022}\u{2022}\u{2022}\u{2022}" . h((string)$_bkChk['acct_last4']) ?>, in <?= h((string)$_bkChk['currency']) ?>. Link it to «<?= h($_bkName) ?>»?</div>
    <?php endif; ?>
    <?php if ($_bkImport): ?>
    <div class="bk-acts">
      <form method="post" action="?page=dashboard&amp;tab=bank"><?= csrfField() ?>
        <input type="hidden" name="bk_action" value="keep"><input type="hidden" name="bk_acct" value="<?= $_bkId ?>"><input type="hidden" name="bk_check" value="<?= (int)$_bkChk['id'] ?>">
        <?php if ($_bkProfile === null): ?><input type="hidden" name="bk_link" value="1"><button type="submit" class="bk-keep">Link and keep</button>
        <?php else: ?><button type="submit" class="bk-keep">Keep this statement</button><?php endif; ?>
      </form>
      <form method="post" action="?page=dashboard&amp;tab=bank"><?= csrfField() ?>
        <input type="hidden" name="bk_action" value="discard"><input type="hidden" name="bk_acct" value="<?= $_bkId ?>"><input type="hidden" name="bk_check" value="<?= (int)$_bkChk['id'] ?>">
        <button type="submit" class="cbv2-bcancel">Discard</button>
      </form>
    </div>
    <?php endif; ?>
    <?php if ((string)$_bkChk['warnings'] !== ''): ?><div class="bk-panel-t bk-sub">Notes from the check (they do not refuse the file): <?= h(str_replace(',', ', ', (string)$_bkChk['warnings'])) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php
// 5.18.97 (the operator, 18 Oct: "two hero section like ECO Bank UGX and ECO BANK USD"). One card per OFFERED account,
// never per currency: each card is that account's own latestLine() and profile(). Nothing is added to anything, and no
// total exists. "ECO BANK <CUR>" heads a card only when the book's name says Ecobank (ecobankNamed); any other bank
// account keeps its own masked name, as v1's card did.
$_bkHero = []; $_bkHeads = [];
foreach ($_bkAccts as $_bkA) {
    $_bkHid = (int)$_bkA['id'];
    $_bkEco = BankStatementStore::ecobankNamed((string)$_bkA['name']);
    $_bkHt  = $_bkEco ? 'ECO BANK ' . (string)$_bkA['currency'] : $_bkUpper($_bkMask((string)$_bkA['name']));
    $_bkHero[$_bkHid] = ['acct' => $_bkA, 'eco' => $_bkEco, 'head' => $_bkHt, 'last' => $_bkStore->latestLine($_bkHid), 'profile' => $_bkStore->profile($_bkHid)];
    $_bkHeads[$_bkHt] = ($_bkHeads[$_bkHt] ?? 0) + 1;
}
?>
  <div class="bk-head">&#127974; ECO Bank · Ecobank's own balances, each account on its own</div>
  <!-- bk-hero:start --><div class="bk-hero">
<?php foreach ($_bkCurrs as $_bkNc):                                // the book's currency order (UGX first); each currency's accounts in id order
    $_bkOfNc = array_filter($_bkHero, function (array $x) use ($_bkNc): bool { return $x['acct']['currency'] === $_bkNc; });
    if (!$_bkOfNc): ?>
  <div class="bk-card none" data-bk-card-none="<?= h($_bkNc) ?>">
    <div class="bk-card-l">&#127974; NO ACTIVE BANK ACCOUNT IN <?= h($_bkNc) ?></div>
    <div class="bk-card-s">The Cashbook's chart of accounts has no active bank account in <?= h($_bkNc) ?>, so no Ecobank statement can be kept or shown for <?= h($_bkNc) ?>.</div>
  </div>
<?php else: foreach ($_bkOfNc as $_bkHid => $_bkHc):
        $_bkHcur = (string)$_bkHc['acct']['currency']; $_bkHon = (int)$_bkHid === $_bkId; ?>
  <div class="bk-card<?= $_bkHon ? ' on' : '' ?>" data-bk-card="<?= (int)$_bkHid ?>">
    <div class="bk-card-o"></div>
    <div class="bk-card-l">&#127974; <?= h($_bkHc['head']) ?> · AS STATED BY ECOBANK <span class="bk-ccy <?= h($_bkHcur) ?>"><?= h($_bkHcur) ?></span></div>
<?php if ($_bkHc['eco'] && $_bkHeads[$_bkHc['head']] > 1): ?>
    <div class="bk-card-n"><?= h($_bkMask((string)$_bkHc['acct']['name'])) ?></div>
<?php endif; ?>
<?php if ($_bkHc['last'] === null): ?>
    <div class="bk-card-v bk-card-e">No Ecobank statement kept yet</div>
    <div class="bk-card-s">Upload this account's Account Details CSV to see Ecobank's own lines and balance here.</div>
<?php else: ?>
    <div class="bk-card-v" id="bkCardFig-<?= (int)$_bkHid ?>"><?= h($_bkMoney($_bkHcur, (int)$_bkHc['last']['running_units'])) ?></div>
    <div class="bk-card-s">available balance after the last kept line, dated <?= h($_bkDate((string)$_bkHc['last']['posting_date'])) ?><?php if ($_bkHc['profile'] !== null): ?> · account ending <?= "\u{2022}\u{2022}\u{2022}\u{2022}" . h((string)$_bkHc['profile']['acct_last4']) ?><?php endif; ?></div>
    <div class="bk-card-s">from the statement kept on <?= h($_bkStamp((string)$_bkHc['last']['kept_at'])) ?></div>
<?php endif; ?>
    <div class="bk-card-f">
      <span class="bk-pill">Not compared with the Cashbook yet</span>
      <a class="bk-card-a" href="<?= h($_bkUrl(['bk_acct' => (int)$_bkHid])) ?>">Show its lines</a>
      <?php if ($_bkImport): ?><button type="button" class="bk-up" data-bk-open="1" data-bk-for="<?= (int)$_bkHid ?>">&#65291; <?= h($_bkHc['last'] === null ? 'Upload the Account Details CSV' : 'Upload a newer statement') ?></button><?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; endforeach; ?>
  </div><!-- bk-hero:end -->
  <div class="bk-head">Statement lines · one account at a time</div>
  <div class="bk-chips">
<?php foreach ($_bkAccts as $_bkA):
    $_bkLinked = $_bkHero[(int)$_bkA['id']]['profile'] !== null; ?>
    <a class="bk-chip<?= (int)$_bkA['id'] === $_bkId ? ' on' : '' ?>" data-bk-acct="<?= (int)$_bkA['id'] ?>" href="<?= h($_bkUrl(['bk_acct' => (int)$_bkA['id'], 'bk_view' => $_bkView === 'lines' ? '' : $_bkView])) ?>"><?= h($_bkFlag((string)$_bkA['currency'])) ?> <?= h($_bkMask((string)$_bkA['name'])) ?><?php if (!$_bkLinked): ?> <span class="bk-chip-nl">not linked</span><?php endif; ?></a>
<?php endforeach; ?>
  </div>
<?php
$_bkWait = $_bkStore->waitingCount($_bkId);
?>
  <div class="cb3-tabs">
    <a class="cb3-tab<?= $_bkView === 'lines' ? ' on' : '' ?>" href="<?= h($_bkUrl(['bk_acct' => $_bkId])) ?>">&#128203; Lines</a>
    <a class="cb3-tab<?= $_bkView === 'statements' ? ' on' : '' ?>" href="<?= h($_bkUrl(['bk_acct' => $_bkId, 'bk_view' => 'statements'])) ?>">&#128196; Statements<?php if ($_bkWait > 0): ?> <span class="cb3-badge"><?= $_bkWait ?></span><?php endif; ?></a>
  </div>
<?php if ($_bkView === 'lines'):
    $_bkRange = $_bkStore->keptRange($_bkId);
    $_bkTotal = $_bkStore->lineCount($_bkId, $_bkMonth);
    $_bkPages = max(1, (int)ceil($_bkTotal / $_bkPer));
    if ($_bkPage > $_bkPages) $_bkPage = $_bkPages;
    $_bkRows  = $_bkStore->lines($_bkId, $_bkMonth, 'desc', $_bkPer, ($_bkPage - 1) * $_bkPer);
?>
  <form method="get" class="bk-filter" action="">
    <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="bank"><input type="hidden" name="bk_acct" value="<?= $_bkId ?>">
    <input type="month" name="bk_month" class="cbv2-fi" value="<?= h($_bkMonth) ?>"<?php if ($_bkRange !== null): ?> min="<?= h(substr($_bkRange['first'], 0, 7)) ?>" max="<?= h(substr($_bkRange['last'], 0, 7)) ?>"<?php endif; ?> data-bk-submit="1" aria-label="Month">
    <button type="submit" class="cb3-filter-btn">Show</button>
    <?php if ($_bkMonth !== ''): ?><a class="cb3-filter-btn" href="<?= h($_bkUrl(['bk_acct' => $_bkId])) ?>" aria-label="All months">&#10005;</a><?php endif; ?>
    <?php if ($_bkRange !== null): ?><a class="bk-csv" id="bkCsv" href="<?= h($_bkUrl(['bk_acct' => $_bkId, 'bk_month' => $_bkMonth, 'bk_export' => 'csv'])) ?>">&#8595; <?= $_bkMonth === '' ? 'CSV' : h($_bkMonthName($_bkMonth)) ?></a><?php endif; ?>
    <?php if ($_bkImport): ?><button type="button" class="bk-up" data-bk-open="1" data-bk-for="<?= $_bkId ?>">&#65291; Upload</button><?php endif; ?>
  </form>
<?php if ($_bkMonth !== '' && ($_bkStrip = $_bkStore->monthStrip($_bkId, $_bkMonth)) !== null): ?>
  <?php if ($_bkStrip['consecutive']): ?>
  <div class="bk-strip" id="bkStrip">
    <div class="bk-sc"><div class="bk-sl"><?= h($_bkMonthName($_bkMonth)) ?> · Opening</div><div class="bk-sv"><?= h($_bkMoney($_bkCur, $_bkStrip['opening'])) ?></div></div>
    <div class="bk-sc"><div class="bk-sl">In</div><div class="bk-sv bk-ok"><?= h($_bkMoney($_bkCur, $_bkStrip['in'])) ?></div></div>
    <div class="bk-sc"><div class="bk-sl">Out</div><div class="bk-sv bk-bad"><?= h($_bkMoney($_bkCur, $_bkStrip['out'])) ?></div></div>
    <div class="bk-sc"><div class="bk-sl">Closing</div><div class="bk-sv"><?= h($_bkMoney($_bkCur, $_bkStrip['closing'])) ?></div></div>
  </div>
  <div class="bk-strip-note" id="bkStripNote"><?php if ($_bkStrip['adds_up']): ?><span class="bk-ok">&#10003; adds up</span> — opening + in − out = closing, to the cent<?php else: ?><span class="bk-bad">&#10007; does not add up</span> — report it<?php endif; ?></div>
  <?php else: ?>
  <div class="bk-strip-note" id="bkStripNote">This month's lines are not consecutive in the bank's order (their posting dates are out of order), so no opening, closing or sum is shown. In: <?= h($_bkMoney($_bkCur, $_bkStrip['in'])) ?> · Out: <?= h($_bkMoney($_bkCur, $_bkStrip['out'])) ?></div>
  <?php endif; ?>
<?php endif; ?>
<?php if ($_bkRange === null): ?>
  <div class="cb3-empty">No statement is kept for this account yet.</div>
<?php elseif (!$_bkRows): ?>
  <div class="cb3-empty">No line in this month.</div>
<?php else: ?>
  <div class="cb3-tbl-wrap">
    <table class="bk-tbl" id="bkTable">
      <thead><tr><th>Line no.</th><th>Date</th><th>Particulars</th><th>Type</th><th class="bk-n">In &#8595;</th><th class="bk-n">Out &#8593;</th><th class="bk-n">Balance</th><th>Ref</th><th>Status</th></tr></thead>
      <tbody>
<?php foreach ($_bkRows as $_bkL):
    $_bkIn = (string)$_bkL['credit_text'] !== '';
    $_bkAmt = $_bkIn ? (int)$_bkL['credit_units'] : (int)$_bkL['debit_units'];
    $_bkStC = (string)$_bkL['status'] === 'imported' ? '' : ' u'; ?>
        <tr data-bk-line="<?= h((string)$_bkL['cheque_number']) ?>">
          <td class="bk-chq"><?= h((string)$_bkL['cheque_number']) ?></td>
          <td style="white-space:nowrap;"><?= h((string)$_bkL['posting_date']) ?><?php if ((string)$_bkL['value_date'] !== (string)$_bkL['posting_date']): ?><div class="bk-sub">val <?= h((string)$_bkL['value_date']) ?></div><?php endif; ?></td>
          <td title="<?= h((string)$_bkL['remarks']) ?>"><?= h($_bkCut((string)$_bkL['remarks'], 65)) ?></td>
          <td><?= h((string)$_bkL['type_code']) ?><div class="bk-sub"><?= h((string)$_bkL['type_desc']) ?></div></td>
          <td class="bk-n bk-ok"><?= $_bkIn ? h($_bkMoney($_bkCur, $_bkAmt)) : '' ?></td>
          <td class="bk-n bk-bad"><?= $_bkIn ? '' : h($_bkMoney($_bkCur, $_bkAmt)) ?></td>
          <td class="bk-n bk-bal"><?= h($_bkMoney($_bkCur, (int)$_bkL['running_units'])) ?></td>
          <td><?= h((string)$_bkL['reference']) ?></td>
          <td><span class="bk-st<?= $_bkStC ?>"><?= h($_bkStatusText((string)$_bkL['status'])) ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="cb3-cards" id="bkCards">
<?php foreach ($_bkRows as $_bkL):
    $_bkIn = (string)$_bkL['credit_text'] !== '';
    $_bkAmt = $_bkIn ? (int)$_bkL['credit_units'] : (int)$_bkL['debit_units'];
    $_bkStC = (string)$_bkL['status'] === 'imported' ? '' : ' u'; ?>
    <div class="bk-line" data-bk-line="<?= h((string)$_bkL['cheque_number']) ?>">
      <div class="bk-line-top"><div class="bk-line-desc"><?= h((string)$_bkL['remarks']) ?></div><div class="cb3-card-amt <?= $_bkIn ? 'in' : 'out' ?>"><?= h($_bkSigned($_bkCur, $_bkAmt, $_bkIn)) ?></div></div>
      <div class="cb3-card-meta"><span><?= h((string)$_bkL['posting_date']) ?></span><?php if ((string)$_bkL['value_date'] !== (string)$_bkL['posting_date']): ?><span>val <?= h((string)$_bkL['value_date']) ?></span><?php endif; ?><span><?= h((string)$_bkL['type_code']) ?> <?= h((string)$_bkL['type_desc']) ?></span><span>ref <?= h((string)$_bkL['reference']) ?></span><span class="bk-chq"><?= h((string)$_bkL['cheque_number']) ?></span><span class="bk-st<?= $_bkStC ?>"><?= h($_bkStatusText((string)$_bkL['status'])) ?></span></div>
      <div class="bk-line-bal">Bal <?= h($_bkMoney($_bkCur, (int)$_bkL['running_units'])) ?></div>
    </div>
<?php endforeach; ?>
  </div>
  <div class="cb3-pager">
    <div class="cb3-pg-info"><?= number_format(($_bkPage - 1) * $_bkPer + 1) ?>–<?= number_format(min($_bkTotal, $_bkPage * $_bkPer)) ?> of <?= number_format($_bkTotal) ?></div>
    <?php if ($_bkPages > 1): ?><div class="cb3-pg-btns">
      <?php if ($_bkPage > 1): ?><a class="cb3-pg" href="<?= h($_bkUrl(['bk_acct' => $_bkId, 'bk_month' => $_bkMonth, 'bk_page' => $_bkPage - 1])) ?>">&#8249;</a><?php endif; ?>
      <?php for ($_bkP = max(1, $_bkPage - 2); $_bkP <= min($_bkPages, $_bkPage + 2); $_bkP++): ?><a class="cb3-pg<?= $_bkP === $_bkPage ? ' on' : '' ?>" href="<?= h($_bkUrl(['bk_acct' => $_bkId, 'bk_month' => $_bkMonth, 'bk_page' => $_bkP])) ?>"><?= $_bkP ?></a><?php endfor; ?>
      <?php if ($_bkPage < $_bkPages): ?><a class="cb3-pg" href="<?= h($_bkUrl(['bk_acct' => $_bkId, 'bk_month' => $_bkMonth, 'bk_page' => $_bkPage + 1])) ?>">&#8250;</a><?php endif; ?>
    </div><?php endif; ?>
  </div>
<?php endif; ?>
<?php else:   // ── Statements ────────────────────────────────────────────────────────────────────────────────────
    $_bkRange  = $_bkStore->keptRange($_bkId);
    $_bkChecks = $_bkStore->checksFor($_bkId);
?>
  <?php if ($_bkRange !== null): ?>
  <div class="bk-cover" id="bkCover">Kept lines run from <?= h($_bkDate($_bkRange['first'])) ?> to <?= h($_bkDate($_bkRange['last'])) ?>. Every file was complete by its own line numbers, and each one after the first overlapped the lines already kept.</div>
  <?php endif; ?>
  <?php if (!$_bkChecks): ?>
  <div class="cb3-empty">No file has been checked for this account yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;background:#fff;">
    <table class="bk-tbl" id="bkChecks">
      <thead><tr><th>Checked</th><th>Period</th><th class="bk-n">Lines</th><th class="bk-n">Added</th><th class="bk-n">Already kept</th><th class="bk-n">Opening · closing</th><th>Kept by</th><th>File</th><th>Status</th></tr></thead>
      <tbody>
<?php foreach ($_bkChecks as $_bkC):
    $_bkS = (string)$_bkC['status']; $_bkO = (string)$_bkC['outcome'];
    $_bkNote = '';
    if ($_bkS === 'kept')                                   { $_bkLbl = 'Kept'; }
    elseif ($_bkS === 'waiting')                            { $_bkLbl = $_bkStore->isExpired($_bkC) ? 'Expired' : 'Waiting to be kept'; }
    elseif ($_bkS === 'discarded')                          { $_bkLbl = 'Discarded'; }
    elseif ($_bkS === 'expired')                            { $_bkLbl = 'Expired'; }
    elseif ($_bkO === 'already_kept')                       { $_bkLbl = 'Already kept'; }
    elseif ($_bkO === 'nothing_new')                        { $_bkLbl = 'Nothing new'; }
    else                                                    { $_bkLbl = 'Refused'; $_bkNote = $_bkSay((string)$_bkC['code'], (string)$_bkC['param']); }
    $_bkHasFile = $_bkS === 'kept' && $_bkStore->statementPath(['file_rel' => (string)$_bkC['st_file_rel'], 'cb_account_id' => $_bkId]) !== null;
    // Every cell made here, as text, and escaped where it is echoed.
    $_bkPeriod = (string)$_bkC['period_from'] !== '' ? $_bkDate((string)$_bkC['period_from']) . ' – ' . $_bkDate((string)$_bkC['period_to']) : '—';
    $_bkAdded  = $_bkS === 'kept' ? (string)(int)$_bkC['st_lines_added'] : '—';
    $_bkKnown  = $_bkS === 'kept' ? (string)(int)$_bkC['st_lines_known'] : ((int)$_bkC['lines_known'] > 0 ? (string)(int)$_bkC['lines_known'] : '—');
    $_bkOC     = ($_bkC['opening_stated'] !== null && (string)$_bkC['currency'] !== '')
        ? $_bkMoney((string)$_bkC['currency'], (int)$_bkC['opening_stated']) . ' · ' . $_bkMoney((string)$_bkC['currency'], (int)$_bkC['closing_stated']) : '—'; ?>
        <tr data-bk-check="<?= (int)$_bkC['id'] ?>">
          <td style="white-space:nowrap;"><?= h($_bkStamp((string)$_bkC['checked_at'])) ?></td>
          <td style="white-space:nowrap;"><?= h($_bkPeriod) ?></td>
          <td class="bk-n"><?= (int)$_bkC['line_count'] ?></td>
          <td class="bk-n"><?= h($_bkAdded) ?></td>
          <td class="bk-n"><?= h($_bkKnown) ?></td>
          <td class="bk-n"><?= h($_bkOC) ?></td>
          <td><?php if ($_bkS === 'kept'): ?><?= h($_bkWho((int)$_bkC['st_kept_by_id'])) ?><div class="bk-sub"><?= h($_bkStamp((string)$_bkC['st_kept_at'])) ?></div><?php if (!$_bkHasFile): ?><div class="bk-note">the original file is not on this server; its lines are kept</div><?php endif; ?><?php else: ?>—<?php endif; ?></td>
          <td class="bk-chq"><?= h(substr((string)$_bkC['sha256'], 0, 8)) ?></td>
          <td><span class="bk-st"><?= h($_bkLbl) ?></span><?php if ($_bkNote !== ''): ?><div class="bk-sub"><?= h($_bkNote) ?></div><?php endif; ?><?php if ($_bkLbl === 'Waiting to be kept'): ?> <a class="bk-sub" href="<?= h($_bkUrl(['bk_acct' => $_bkId, 'bk_view' => 'statements', 'bk_check' => (int)$_bkC['id']])) ?>">open</a><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php if ($_bkImport): ?>
  <div class="cb4-mo" id="bkSheet" aria-hidden="true">
    <div class="cb4-mb">
      <div class="cb4-mh neutral"><div><div class="cb4-mt">&#65291; Statement</div><div class="cb4-msub">Ecobank Account Details, as CSV</div></div><button type="button" class="cb4-mclose" data-bk-close="1" aria-label="Close">&#10005;</button></div>
      <form method="post" enctype="multipart/form-data" action="?page=dashboard&amp;tab=bank" class="bk-form cb4-scroll" id="bkUpForm" data-bk-cap="<?= (int)$_bkCap ?>">
        <?= csrfField() ?>
        <input type="hidden" name="bk_action" value="check">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$_bkCap ?>">
        <div class="cb4-mbody">
          <div class="bk-fg"><label class="bk-fl" for="bkAcctSel">1. The account</label>
            <select name="bk_acct" id="bkAcctSel">
<?php foreach ($_bkAccts as $_bkA): ?>
              <option value="<?= (int)$_bkA['id'] ?>"<?= (int)$_bkA['id'] === $_bkId ? ' selected' : '' ?>><?= h($_bkMask((string)$_bkA['name'])) ?> (<?= h((string)$_bkA['currency']) ?>)</option>
<?php endforeach; ?>
            </select></div>
          <div class="bk-fg"><label class="bk-fl" for="bkFile">2. The file</label>
            <input type="file" name="bk_file" id="bkFile" accept=".csv,text/csv" required>
            <div class="bk-fname" id="bkFileName"></div></div>
          <div class="bk-help">In Ecobank online banking, open the account and download <b>Account Details</b> as <b>CSV</b> — one account per file. <b>An account's first file:</b> any period that ends today, for example from the 1st of this month. <b>After that:</b> from the date on the account's card, or earlier. Nothing is kept until you press Keep.</div>
          <div class="bk-warn">Not the account summary, and not the PDF. Do not open the file in Excel and save it before uploading: Excel rewrites the dates. The largest file this installation can receive is <?= h($_bkCapMb) ?> MB.</div>
          <div class="bk-err" id="bkSizeMsg" hidden></div>
        </div>
        <div class="bk-foot"><button type="button" class="cbv2-bcancel" data-bk-close="1">Cancel</button><button type="submit" class="cbv2-bsave">Check this file</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>
<script>
(function () {
  var sheet = document.getElementById('bkSheet');
  var each = function (sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); };
  each('[data-bk-open]', function (b) { b.addEventListener('click', function () {
    var sel = document.getElementById('bkAcctSel'), f = b.getAttribute('data-bk-for'); if (sel && f) sel.value = f;   // 5.18.97: the button's own account
    if (sheet) { sheet.classList.add('open'); sheet.setAttribute('aria-hidden', 'false'); }
  }); });
  each('[data-bk-close]', function (b) { b.addEventListener('click', function () { if (sheet) { sheet.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true'); } }); });
  each('[data-bk-submit]', function (i) { i.addEventListener('change', function () { if (i.form) i.form.submit(); }); });
  var form = document.getElementById('bkUpForm'), file = document.getElementById('bkFile');
  var name = document.getElementById('bkFileName'), msg = document.getElementById('bkSizeMsg');
  if (!form || !file) return;
  file.addEventListener('change', function () {
    name.textContent = file.files && file.files[0] ? file.files[0].name : '';
    msg.hidden = true; msg.textContent = '';
  });
  form.addEventListener('submit', function (e) {
    var cap = parseInt(form.getAttribute('data-bk-cap'), 10) || 0;
    if (file.files && file.files[0] && cap > 0 && file.files[0].size > cap) {
      e.preventDefault();
      msg.textContent = 'This file is larger than ' + Math.floor(cap / 1048576) + ' MB; nothing was sent. Download a shorter period.';
      msg.hidden = false;
    }
  });
})();
</script>
