<?php
//
// THE BANK SCREEN v1 (5.18.97; SPEC-bank §d.2) — check, keep (link and keep on an account's first file) and discard an
// Ecobank "Account Details" statement. Uganda only, admins only, posts nothing to the Cashbook.
//
// Order, each step before the next and each refusal writing nothing:
//   1. a POST whose bk_action is one of check / keep / discard;
//   2. the sign-in (so a visitor who is not signed in learns nothing about the switch), then BankGate::open() (Uganda,
//      no SSP, the switch), then BankGate::allows('bank.import') on the FRESH record of the signed-in account (never
//      the session copy);
//   3. csrfCheck() AND not CustomerSession::crossSite() AND BankGate::fromOwnPage('POST'): the token is the same for
//      every user and day (the login page prints it), so it proves nothing about where a request came from. The
//      request must SHOW it came from our own page (Sec-Fetch-Site same-origin, or an Origin, or else a Referer, naming
//      this host); a request that shows nothing is refused. The global gate in post_handlers.php also checks the token
//      (bk_action is not on its bypass list); this handler never relies on it;
//   4. bk_acct must be an account the store offers (C1), before the file is read; bk_check a number;
//   5. the act, through lib/BankStatementStore.php, with the fresh record as its actor — never a form field;
//   6. a flash of FIXED text, then a redirect whose address carries only numbers. The dashboard prints the flash
//      unescaped, so nothing from the file, its name or a cell ever goes into one: a refusal is the code's fixed sentence
//      (EcobankDetailsCsv::sentence(), whose parameter is at most a number, a date, a currency or four digits), escaped.
//      The check panel draws the result from the stored check.
// No error_log(), no exception message shown: a failure is one fixed sentence.
//

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bk_action'])) {
    $_bkpBack = '?page=dashboard&tab=bank';
    $_bkpAct = is_string($_POST['bk_action']) ? $_POST['bk_action'] : '';
    if (!in_array($_bkpAct, ['check', 'keep', 'discard'], true)) {
        flash('That is not an action of ECO Bank. Nothing was changed.', 'danger');
        redirect($_bkpBack);
    }

    require_once dirname(__DIR__, 2) . '/lib/BankGate.php';
    $_bkpUser = $auth->requireLogin();
    if (!BankGate::open($dataDir ?? null)) {
        flash('ECO Bank is not available on this installation.', 'danger');
        redirect('?page=dashboard');
    }
    $_bkpMe = BankGate::allows('bank.import', $store, is_array($_bkpUser) ? $_bkpUser : []);
    if ($_bkpMe === null) {
        flash('Access denied.', 'danger');
        redirect('?page=dashboard');
    }

    require_once dirname(__DIR__, 2) . '/lib/CustomerSession.php';
    if (!csrfCheck() || CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {
        flash('The security check failed, so nothing was changed. Open ECO Bank again and retry.', 'danger');
        redirect($_bkpBack);
    }

    require_once dirname(__DIR__, 2) . '/lib/currency.php';
    require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
    require_once dirname(__DIR__, 2) . '/lib/EcobankDetailsCsv.php';
    require_once dirname(__DIR__, 2) . '/lib/BankStatementStore.php';
    $_bkpSay = function (string $code, string $param = ''): string { return h(EcobankDetailsCsv::sentence($code, $param)); };
    $_bkpNum = function (string $k): int { $v = $_POST[$k] ?? ''; return (is_string($v) && ctype_digit($v) && strlen($v) <= 12) ? (int)$v : 0; };

    try {
        $_bkpStore = new BankStatementStore($store->getPdo(), (string)$dataDir,
            dn_book_currencies(PluginConfig::read(dirname(__DIR__, 2), (string)$dataDir)));
        if (!$_bkpStore->ready()) {
            flash('ECO Bank is not ready on this installation. Nothing was kept.', 'danger');
            redirect($_bkpBack);
        }
        $_bkpAcct = $_bkpNum('bk_acct');
        if ($_bkpStore->account($_bkpAcct) === null) {                                // C1, before the file is read
            flash($_bkpSay('account_not_offered'), 'danger');
            redirect($_bkpBack);
        }
        $_bkpTo = $_bkpBack . '&bk_acct=' . $_bkpAcct;

        if ($_bkpAct === 'check') {
            $_bkpUp = BankStatementStore::uploaded(is_array($_FILES['bk_file'] ?? null) ? $_FILES['bk_file'] : []);
            if (!$_bkpUp['ok']) {
                flash($_bkpSay((string)$_bkpUp['code'], (string)($_bkpUp['param'] ?? '')), 'danger');
                redirect($_bkpTo);
            }
            $_bkpR = $_bkpStore->check($_bkpAcct, (string)$_bkpUp['bytes'], $_bkpMe);
            unset($_bkpUp);
            $_bkpId = (int)($_bkpR['check_id'] ?? 0);
            if ($_bkpId <= 0) {
                flash($_bkpSay((string)$_bkpR['code'], (string)($_bkpR['param'] ?? '')), 'danger');
                redirect($_bkpTo);
            }
            if (!empty($_bkpR['ok'])) {
                flash('The file passed every check. Nothing is kept until you press Keep.', 'success');
            } elseif (!empty($_bkpR['note'])) {
                flash('The file was checked. Nothing was added.', 'info');
            } else {
                flash('The file was not kept. The reason is shown below.', 'danger');
            }
            redirect($_bkpTo . '&bk_check=' . $_bkpId);
        }

        $_bkpChk = $_bkpNum('bk_check');
        if ($_bkpChk <= 0) {
            flash('Choose the check to keep or discard. Nothing was changed.', 'danger');
            redirect($_bkpTo);
        }
        if ($_bkpAct === 'keep') {
            $_bkpR = $_bkpStore->keep($_bkpChk, $_bkpAcct, ($_POST['bk_link'] ?? '') === '1', $_bkpMe);
            if (!empty($_bkpR['ok'])) {
                $_bkpAdded = (int)$_bkpR['lines_added'];
                flash('Kept: ' . $_bkpAdded . ' new line' . ($_bkpAdded === 1 ? '' : 's') . ' added, ' . (int)$_bkpR['lines_known']
                    . ' already kept. Nothing was posted to the Cashbook.'
                    . (!empty($_bkpR['linked']) ? ' This book account is now linked to its Ecobank account.' : ''), 'success');
            } else {
                flash($_bkpSay((string)$_bkpR['code'], (string)($_bkpR['param'] ?? '')), !empty($_bkpR['note']) ? 'info' : 'danger');
            }
            redirect($_bkpTo . '&bk_check=' . $_bkpChk);
        }
        $_bkpR = $_bkpStore->discard($_bkpChk, $_bkpAcct, $_bkpMe);
        if (!empty($_bkpR['ok'])) {
            flash('Discarded. Nothing was kept from this file.', 'success');
        } else {
            flash($_bkpSay((string)$_bkpR['code'], (string)($_bkpR['param'] ?? '')), !empty($_bkpR['note']) ? 'info' : 'danger');
        }
        redirect($_bkpTo . '&bk_check=' . $_bkpChk);
    } catch (\Throwable $_bkpE) {
        flash('ECO Bank could not finish this. Nothing was kept. Report it.', 'danger');
        redirect($_bkpBack);
    }
}
