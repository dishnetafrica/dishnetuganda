<?php
// ── 5.18.98 (SPEC-separate-v2 §2.6): the export of the Cashbook's "Bank & other accounts" tab ───────────────────────
// Required by includes/routes.php inside the Cashbook export, only under the page's own gate ($_csvRule2: the Uganda
// tenant AND a book without SSP), and never for one of the page's field agents or field accountants, who get the cash
// export instead. Like the cash export, it does not ask the Cashbook tab's own permission (SPEC-separate-v2 §2.12, the
// 5.18.98 review's SEC-1). The signed-in user, the service and the book's currencies are routes.php's. The project is
// read here by the page's own rule (cashbook.php: absent or unknown is 'dishnet'), never routes.php's $proj2, which is
// NULL when the request carries no cb_proj (the 5.18.98 review, SQL-3: chips() takes a string).
//
// Exactly the rows of one button of the tab (CashbookNonCashView::selected(), the tab's own rule), within the dates
// and the search, every page. No Balance column and no total: the book holds only part of Ecobank's history. A text
// cell a spreadsheet would read as a formula (= + - @, a tab, a CR) gets a ' in front; the amounts stay numbers. The
// file name carries the project and the account's id, never its name or number. Nothing is written.
require_once dirname(__DIR__) . '/lib/CashbookNonCashView.php';

$_ncxQ      = function (string $k): string { $v = $_GET[$k] ?? ''; return is_string($v) ? $v : ''; };   // as the page reads them
$_ncxSearch = trim($_ncxQ('cb_q'));
$_ncxFrom   = $_ncxQ('cb_from');
$_ncxTo     = $_ncxQ('cb_to');
$_ncxProj   = in_array($_GET['cb_proj'] ?? 'dishnet', ['dishnet','4g','bluecard']) ? ($_GET['cb_proj'] ?? 'dishnet') : 'dishnet';
$_ncxChips  = CashbookNonCashView::chips($cb2csv, $_ncxProj, $_ncxSearch, $_ncxFrom, $_ncxTo);
$_ncxSel    = CashbookNonCashView::selected($_ncxChips, $_GET, $_ncxSearch !== '');
$_ncxF = array_filter(['project' => $_ncxProj, 'date_from' => $_ncxFrom, 'date_to' => $_ncxTo, 'search' => $_ncxSearch]);
$_ncxF['book']         = 'noncash';
$_ncxF['book_account'] = $_ncxSel;
$_ncxF['limit']        = 9999;
$_ncxF['offset']       = 0;
$_ncxRows   = $cb2csv->getEntries($_ncxF);
$_ncxLabels = $cb2csv->accountLabels();

$_ncxText = function ($v): string {
    $s = (string)$v;
    return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
};
$_ncxKind = function ($accountId) use ($_ncxLabels): string {
    $raw = is_scalar($accountId) ? (string)$accountId : '';
    if ((is_int($accountId) || ctype_digit($raw)) && isset($_ncxLabels[$raw]) && is_array($_ncxLabels[$raw])) {
        return (string)($_ncxLabels[$raw]['kind'] ?? '');
    }
    return '';
};

$_ncxName = 'cashbook-other-accounts-' . strtoupper($_ncxProj) . '-'
    . (is_int($_ncxSel) ? 'ACCOUNT' . $_ncxSel : 'OTHER') . '-' . date('Y-m-d') . '.csv';
if (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $_ncxName . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
$_ncxOut = fopen('php://output', 'w');
fputcsv($_ncxOut, ['SR No.', 'Date', 'Account', 'Account kind', 'Type', 'Particulars', 'Category', 'Person', 'Currency',
    'Received', 'Payment', 'Ref', 'Entry state', 'Source'], ',', '"', '');
foreach ($_ncxRows as $_ncxE) {
    $_ncxIn  = ($_ncxE['direction'] ?? '') === 'in';
    $_ncxAmt = (float)($_ncxE['amount'] ?? 0);
    $_ncxCur = strtoupper(trim((string)($_ncxE['currency'] ?? ''))) ?: $_csvBase2;
    fputcsv($_ncxOut, [
        $_ncxText($_ncxE['sr'] ?? ''),
        $_ncxText($_ncxE['date'] ?? ''),
        $_ncxText(htmlspecialchars_decode(CashbookNonCashView::label($_ncxE['account_id'] ?? null, $_ncxLabels), ENT_QUOTES)),
        $_ncxText($_ncxKind($_ncxE['account_id'] ?? null)),
        $_ncxText($_ncxE['txn_type'] ?? ''),
        $_ncxText($_ncxE['description'] ?? ''),
        $_ncxText($_ncxE['category'] ?? ''),
        $_ncxText($_ncxE['person'] ?? ''),
        $_ncxText($_ncxCur),
        $_ncxIn ? $_ncxAmt : '',
        $_ncxIn ? '' : $_ncxAmt,
        $_ncxText($_ncxE['validation_ref'] ?? ''),
        $_ncxText(CashbookNonCashView::state((string)($_ncxE['status'] ?? ''))),
        $_ncxText($_ncxE['source'] ?? 'manual'),
    ], ',', '"', '');
}
fclose($_ncxOut);
