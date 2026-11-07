<?php
//
// THE OFFERS SCREEN'S SEVEN ACTIONS (5.18.104 P6, root docs/73 §H.4.7; rulings R13, R18, R19): offer_save, offer_discard,
// offer_test, offer_start, offer_pause, offer_resume, offer_stop. Uganda only, admins only, while offers_test_enabled is on.
//
// The 5.18.103 catalogue's order (includes/post/post_sync.php), each step before the next and each refusal writing nothing:
//   1. OfferSwitch::screen() on the configuration read now; offer_start and offer_resume also need OfferSwitch::live();
//      offer_test also needs WaSendBudget::on() (off sending leaves the screen up, ruling R19), and OfferSender::sendTest()
//      tests both again on its own read;
//   2. $auth->requireAdmin(), then the account re-read FRESH by id: active and an admin, or 403 — never the session copy;
//   3. csrfCheck() AND not CustomerSession::crossSite() AND BankGate::fromOwnPage('POST');
//   4. the act, inside BEGIN IMMEDIATE where it writes (OfferStore), then a redirect to the tab.
// Every flash is fixed text plus ids and fixed codes (the dashboard prints a flash unescaped); the log carries the action,
// the offer id and a fixed code — never a wording, a number, a name or the staff id (B10-fix1: §H.4.7 step 4 named the staff
// id, against §H.2.14 M's "no staff id", C8 and 5.18.103's rule; who acted is wa_offer_events.by_id, in the book, not the log).
//
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['offer_save', 'offer_discard', 'offer_test', 'offer_start', 'offer_pause', 'offer_resume', 'offer_stop'], true)) {
    require_once dirname(__DIR__, 2) . '/lib/OfferSwitch.php';
    require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
    $_ofRoot = dirname(__DIR__, 2);
    $_ofData = isset($dataDir) ? (string)$dataDir : '';
    $_ofAct  = (string)$_POST['action'];
    $_ofBack = '?page=dashboard&tab=wa_offers';
    $_ofCfg  = PluginConfig::read($_ofRoot, $_ofData);

    // 1. the switches, on the configuration as it reads now
    if (!OfferSwitch::screen($_ofCfg, $_ofData)
        || (in_array($_ofAct, ['offer_start', 'offer_resume'], true) && !OfferSwitch::live($_ofCfg, $_ofData))) {
        flash('Offers are not switched on here — nothing was changed.', 'danger');
        redirect($_ofBack);
    }
    require_once $_ofRoot . '/lib/WaSendBudget.php';
    if ($_ofAct === 'offer_test' && !WaSendBudget::on($_ofCfg, $_ofData)) {
        flash('The send ledger is off — nothing was sent.', 'danger');
        redirect($_ofBack);
    }

    // 2. an admin, judged on the account record re-read fresh by id — never the session copy
    $_ofMe   = $auth->requireAdmin();
    $_ofMeId = (int)($_ofMe['id'] ?? 0);
    try {
        $_ofFresh = $_ofMeId > 0 ? $store->findOne('retailers.json', 'id', $_ofMeId) : null;
    } catch (\Throwable $e) {
        $_ofFresh = null;
    }
    if (!is_array($_ofFresh) || $_ofMeId <= 0 || (int)($_ofFresh['id'] ?? 0) !== $_ofMeId
        || empty($_ofFresh['is_active']) || empty($_ofFresh['is_admin'])) {
        http_response_code(403);
        die('<p style="color:red;padding:20px;">Access denied — admin only.</p>');
    }

    // 3. the own-page check (post_bank.php's)
    require_once $_ofRoot . '/lib/BankGate.php';
    require_once $_ofRoot . '/lib/CustomerSession.php';
    if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {
        flash('The security check failed, so nothing was changed. Open the Offers screen again and retry.', 'danger');
        redirect($_ofBack);
    }

    // 4. the act
    foreach (['UtcClock', 'PluginLog', 'OfferSchema', 'OfferText', 'OfferImage', 'OfferGateway', 'EvolutionOfferGateway', 'OfferAudience',
              'OfferStore', 'OfferSender'] as $_ofC) {
        require_once $_ofRoot . '/lib/' . $_ofC . '.php';
    }
    $_ofPdo  = $store->getPdo();
    $_ofNow  = time();
    $_ofName = (string)($_ofFresh['name'] ?? ('staff #' . $_ofMeId));
    $_ofId   = (is_string($_POST['offer'] ?? null) && ctype_digit($_POST['offer']) && strlen($_POST['offer']) <= 12) ? (int)$_POST['offer'] : 0;
    $_ofPosted = $_ofId;
    $_ofCode = function (string $c): string { return substr((string)preg_replace('/[^a-z0-9_:]/', '', strtolower($c)), 0, 60); };
    $_ofLog  = function (string $code) use ($_ofAct, &$_ofId, $_ofCode): void {
        PluginLog::write('wa_offers', $_ofAct . ' offer=' . (int)$_ofId . ' code=' . ($code === '' ? 'ok' : $_ofCode($code)));
    };
    $_ofTo = function (int $id) use ($_ofBack): string { return $id > 0 ? $_ofBack . '&offer=' . $id : $_ofBack; };
    if (OfferSchema::check($_ofPdo) !== []) {
        $_ofLog('schema');
        flash('This install\'s offer tables are not complete — nothing was changed.', 'danger');
        redirect($_ofBack);
    }

    try {
        if ($_ofAct === 'offer_save') {
            $_ofTitle = is_string($_POST['title'] ?? null) ? (string)$_POST['title'] : '';
            $_ofWords = [];
            foreach ([1, 2, 3] as $_ofV) $_ofWords[] = is_string($_POST['wording_' . $_ofV] ?? null) ? (string)$_POST['wording_' . $_ofV] : '';
            $_ofSize = is_string($_POST['size'] ?? null) ? (string)$_POST['size'] : '';
            $_ofBad = OfferText::validate($_ofTitle, $_ofWords);
            if (!array_key_exists($_ofSize, OfferAudience::SIZES)) $_ofBad[] = 'size';
            if ($_ofBad !== []) {
                $_ofLog('invalid');
                $_ofWhy = [];
                foreach ($_ofBad as $_ofB) {
                    $_ofK = explode(':', $_ofB)[0];
                    $_ofN = strpos($_ofB, ':') !== false ? ' ' . (int)explode(':', $_ofB)[1] : '';
                    $_ofWhy[] = ['title_length' => 'the title must be 1–80 characters', 'wording_count' => 'wordings 1 and 2 are needed, at most 3',
                                 'wording_length' => 'wording' . $_ofN . ' must be 20–900 characters',
                                 'wording_braces' => 'wording' . $_ofN . ' has { or }: no placeholders',
                                 'wording_stop' => 'wording' . $_ofN . ' says STOP: the STOP line is added automatically',
                                 'wording_same' => 'two wordings are the same', 'size' => 'the audience size must be 100, 200 or all'][$_ofK] ?? $_ofCode($_ofB);
                }
                flash('Nothing was saved: ' . implode('; ', array_values(array_unique($_ofWhy))) . '.', 'danger');
                redirect($_ofTo($_ofId));
            }
            $_ofUp = is_array($_FILES['image'] ?? null) ? $_FILES['image'] : ['error' => UPLOAD_ERR_NO_FILE];
            $_ofHasImg = (int)($_ofUp['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $_ofOwn = OfferStore::begin($_ofPdo);
            try {
                if ($_ofId > 0) {
                    $_ofR = OfferStore::edit($_ofPdo, $_ofId, $_ofTitle, $_ofWords, $_ofSize, $_ofMeId, $_ofName, $_ofNow);
                    if (!$_ofR['ok']) throw new \RuntimeException('offer_save:not_draft');
                } else {
                    $_ofR = OfferStore::create($_ofPdo, $_ofTitle, $_ofWords, $_ofSize, $_ofMeId, $_ofName, $_ofNow);
                    if (!$_ofR['ok']) throw new \RuntimeException('offer_save:invalid');
                    $_ofId = (int)$_ofR['id'];
                }
                if ($_ofHasImg) {
                    $_ofImg = OfferImage::accept($_ofUp, $_ofId, $_ofData);
                    if (!$_ofImg['ok']) throw new \RuntimeException('offer_save:image:' . $_ofCode((string)$_ofImg['code']));
                    if (!OfferStore::setImage($_ofPdo, $_ofId, $_ofImg, $_ofMeId, $_ofName, $_ofNow)) throw new \RuntimeException('offer_save:not_draft');
                } elseif (!empty($_POST['remove_image'])) {
                    if (!OfferStore::setImage($_ofPdo, $_ofId, null, $_ofMeId, $_ofName, $_ofNow)) throw new \RuntimeException('offer_save:not_draft');
                }
                if ($_ofOwn) $_ofPdo->exec('COMMIT');
            } catch (\Throwable $e) {
                if ($_ofOwn) { try { $_ofPdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* none open */ } }
                $_ofId = $_ofPosted;                                   // a new draft rolled back with the image: none was made
                $_ofMsg = $e->getMessage();
                if (strpos($_ofMsg, 'offer_save:image:') === 0) {
                    $_ofIc = substr($_ofMsg, strlen('offer_save:image:'));
                    $_ofLog('image_' . $_ofIc);
                    flash('Nothing was saved: the image was not accepted (' . (['too_large' => 'larger than 4 MB', 'not_an_image' => 'not a jpg, png or webp image',
                          'empty' => 'an empty file', 'upload_error' => 'the upload did not arrive', 'store_failed' => 'it could not be stored'][$_ofIc] ?? $_ofIc) . ').', 'danger');
                    redirect($_ofTo($_ofId));
                }
                if ($_ofMsg === 'offer_save:not_draft') {
                    $_ofLog('not_draft');
                    flash('Only a draft can be changed — nothing was saved.', 'danger');
                    redirect($_ofTo($_ofId));
                }
                throw $e;
            }
            $_ofLog('');
            flash('Draft #' . $_ofId . ' was saved. Test each wording again before a start.', 'success');
            redirect($_ofTo($_ofId));
        }

        if ($_ofAct === 'offer_discard') {
            $_ofOk = OfferStore::discard($_ofPdo, $_ofId, $_ofMeId, $_ofName, $_ofNow);
            $_ofLog($_ofOk ? '' : 'not_draft');
            flash($_ofOk ? 'Draft #' . $_ofId . ' was discarded. It is kept in the history.' : 'Only a draft can be discarded — nothing was changed.',
                  $_ofOk ? 'success' : 'danger');
            redirect($_ofBack);
        }

        if ($_ofAct === 'offer_test') {
            $_ofVar = (is_string($_POST['variant'] ?? null) && in_array($_POST['variant'], ['1', '2', '3'], true)) ? (int)$_POST['variant'] : 0;
            $_ofCh  = (is_string($_POST['channel'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/', (string)$_POST['channel'])) ? (string)$_POST['channel'] : '';
            if ($_ofVar === 0 || $_ofCh === '') {
                $_ofLog('bad_request');
                flash('That test could not be sent: choose a wording and a number.', 'danger');
                redirect($_ofTo($_ofId));
            }
            // The phone is the fresh account's own, read by sendTest(); never a number from the request.
            $_ofR = OfferSender::forInstall($_ofRoot, $_ofData, $_ofCfg, ['store' => $store])->sendTest($_ofId, $_ofVar, $_ofCh, $_ofFresh);
            $_ofLog((string)$_ofR['code']);
            if (!empty($_ofR['ok'])) {
                flash('Test #' . (int)$_ofR['test_id'] . ' of wording ' . $_ofVar . ' was sent to your account\'s phone.', 'success');
            } else {
                $_ofSay = ['ledger_off' => 'the send ledger is off', 'offers_off' => 'offers are not switched on', 'not_draft' => 'only a draft is tested',
                           'no_phone_of_record' => 'your account has no phone number that can be used', 'no_instance' => 'that number is not set up',
                           'test_in_progress' => 'another test of this offer is still being sent', 'image_changed' => 'the image changed: save the draft again',
                           'bad_variant' => 'that wording does not exist'];
                $_ofC = (string)$_ofR['code'];
                flash('The test was not sent: ' . ($_ofSay[$_ofC] ?? $_ofCode($_ofC)) . ((int)$_ofR['test_id'] > 0 ? ' (test #' . (int)$_ofR['test_id'] . ')' : '') . '.', 'danger');
            }
            redirect($_ofTo($_ofId));
        }

        if ($_ofAct === 'offer_start') {
            $_ofHash = (is_string($_POST['content_hash'] ?? null) && preg_match('/^[0-9a-f]{64}$/', (string)$_POST['content_hash'])) ? (string)$_POST['content_hash'] : '';
            $_ofR = OfferStore::start($_ofPdo, $_ofCfg, $_ofData, (array)($store->load('retailers.json') ?? []), (array)($store->load('kyc_config.json') ?? []),
                                      $_ofId, $_ofHash, $_ofMeId, $_ofName, $_ofNow);
            $_ofLog((string)$_ofR['code']);
            if (!empty($_ofR['ok'])) {
                flash('Offer #' . $_ofId . ' was started: ' . (int)$_ofR['queued'] . ' people queued.', 'success');
                redirect($_ofBack);
            }
            $_ofC = (string)$_ofR['code'];
            $_ofSay = ['nobody_eligible' => 'nobody can be sent this offer now — nothing was started',
                       'untested' => 'every wording must be tested, sent and recorded, on the content as it is now — nothing was started',
                       'another_live' => 'another offer is live — nothing was started',
                       'not_draft' => 'the offer is not a draft, or its wording changed since this page was drawn — nothing was started',
                       'settings_out_of_range' => 'a setting is out of range — nothing was started'];
            flash('The offer was not started: ' . ($_ofSay[$_ofC] ?? ($_ofCode($_ofC) . ' — nothing was started')) . '.', 'danger');
            redirect($_ofTo($_ofId));
        }

        if (in_array($_ofAct, ['offer_pause', 'offer_resume', 'offer_stop'], true)) {
            if ($_ofAct === 'offer_pause') $_ofOk = OfferStore::pause($_ofPdo, $_ofId, $_ofMeId, $_ofName, $_ofNow);
            elseif ($_ofAct === 'offer_resume') $_ofOk = OfferStore::resume($_ofPdo, $_ofId, $_ofMeId, $_ofName, $_ofNow);
            else $_ofOk = OfferStore::stop($_ofPdo, $_ofId, $_ofMeId, $_ofName, $_ofNow);
            $_ofLog($_ofOk ? '' : 'wrong_state');
            $_ofDone = ['offer_pause' => 'paused', 'offer_resume' => 'resumed', 'offer_stop' => 'stopped'][$_ofAct];
            flash($_ofOk ? 'Offer #' . $_ofId . ' was ' . $_ofDone . '.' : 'Offer #' . $_ofId . ' could not be ' . $_ofDone . ' from the state it is in — nothing was changed.',
                  $_ofOk ? 'success' : 'danger');
            redirect($_ofBack);
        }
    } catch (\Throwable $e) {
        $_ofLog('error');
        flash('Nothing was changed — try again.', 'danger');
        redirect($_ofBack);
    }
}
