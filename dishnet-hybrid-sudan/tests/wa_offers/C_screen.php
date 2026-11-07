<?php
declare(strict_types=1);
/**
 * Section C — the Offers screen and its seven actions (5.18.104, root docs/73 §H.4.7, §H.4.16 C). TEST ONLY.
 *
 * Served by the judged tree itself (tests/fixtures/staff_jobs_sandbox.php: public.php under php -S, the repository's fake
 * Evolution behind it), signed in through the real login page: the card alone and no menu entry while offers-test is off;
 * admins only, the account re-read fresh (a session admin demoted in the store is refused); the CSRF token and the
 * own-page origin; the draft's validation; the image by its detected type, a renamed non-image and a file one byte over
 * 4 MB refused with nothing saved, one of exactly 4 MB accepted (the sandbox serves uploads above 4 MB, B8-fix1); the
 * test to the admin's own phone of record, never a number from the request, and none with the send ledger off; Start refused without every wording tested and recorded on the current content, after an edit,
 * with offers-live off, beside another live offer, and with nobody eligible — each writing nothing; Pause, Resume, Stop
 * (queued rows skipped) and Discard. No customer's number appears on any page. Every person and number is fictitious.
 */
require_once __DIR__ . '/checks_lib.php';

$WO_SECTIONS['C'] = ['title' => 'The Offers screen and its seven actions (§H.4.7)', 'run' => function (QfCtx $ctx) {
    require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
    // B8-fix1 (B8-R02): the served plugin takes uploads above 4 MB, as the server does, so the size rule C06 judges is the
    // plugin's own (OfferImage::accept, FlyerAsset::MAX_FILE_BYTES), never php -S's 2 MB default. An ini file added to the
    // scan path (its leading ':' keeps the compiled-in directory) for the processes the sandbox starts, then put back.
    $ini = $ctx->dir('c-ini');
    file_put_contents($ini . '/zz-wo-uploads.ini', "upload_max_filesize = 8M\npost_max_size = 16M\n");
    $scanWas = getenv('PHP_INI_SCAN_DIR');
    putenv('PHP_INI_SCAN_DIR=' . ($scanWas === false ? '' : $scanWas) . ':' . $ini);
    try {
        $sb = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on',
                                            'offers_test_enabled' => '1', 'offers_live_enabled' => '1', 'offers_live_since' => '2026-11-01 06:00:00'], 'woc');
    } finally {
        if ($scanWas === false) putenv('PHP_INI_SCAN_DIR'); else putenv('PHP_INI_SCAN_DIR=' . $scanWas);
    }
    try {
    $admin = $sb->staff('admin', ['name' => 'Screen Admin', 'email' => 'wo-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '0700990901']);
    $sb->staff('admin2', ['name' => 'Second Admin', 'email' => 'wo-admin2@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '0700990902']);
    $sb->staff('sales', ['name' => 'Screen Seller', 'email' => 'wo-sales@example.test', 'role' => 'sales', 'phone' => '0700990903']);
    foreach (['admin' => 'wo-admin@example.test', 'admin2' => 'wo-admin2@example.test', 'sales' => 'wo-sales@example.test'] as $who => $em) $sb->login($who, $em, 'sj-password-1');
    $pdo = $sb->store()->getPdo();
    $b = ['pdo' => $pdo, 'store' => $sb->store(), 'data' => $sb->data, 'cfg' => $sb->cfg];
    $tab = 'page=dashboard&tab=wa_offers';
    $setCfg = function (array $over) use ($sb): void {
        $cfg = $sb->cfg;
        foreach ($over as $k => $v) { if ($v === null) unset($cfg[$k]); else $cfg[$k] = $v; }
        $sb->store()->save('kyc_config.json', $cfg);
        file_put_contents($sb->data . '/kyc_config.json', json_encode($cfg));
    };
    $token = function () use ($sb): string {
        preg_match('/name="_csrf" value="([^"]+)"/', $sb->http('GET', "{$sb->base}?page=login")[1], $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    };
    // A form as the screen posts it: the day's token, from our own page (Sec-Fetch-Site), unless $o says otherwise.
    $post = function (?string $who, array $fields, array $o = []) use ($sb, $token, $tab): array {
        $fields += ['_csrf' => $token()];
        $h = $o['headers'] ?? ['Sec-Fetch-Site: same-origin'];
        if (isset($o['file'])) {
            [$field, $bytes, $name, $mime] = $o['file'];
            $tmp = $sb->sb . '/up_' . bin2hex(random_bytes(4));
            file_put_contents($tmp, $bytes);
            $fields[$field] = new \CURLFile($tmp, $mime, $name);
            $ch = curl_init("{$sb->base}?{$tab}");
            $loc = '';
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => $fields,
                CURLOPT_HEADERFUNCTION => function ($c, $hd) use (&$loc) { if (stripos($hd, 'Location:') === 0) $loc = trim(substr($hd, 9)); return strlen($hd); }]);
            if ($who !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $sb->jars[$who]); curl_setopt($ch, CURLOPT_COOKIEFILE, $sb->jars[$who]); }
            $r = curl_exec($ch);
            $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            @unlink($tmp);
            return [$c, (string)$r, null, $loc];
        }
        return $sb->http('POST', "{$sb->base}?{$tab}", http_build_query($fields), array_merge(['Content-Type: application/x-www-form-urlencoded'], $h),
                         $who === null ? null : $sb->jars[$who]);
    };
    // The dashboard draws a flash behind a symbol of its own (✅ / ❌); the words after it are the handler's.
    $flash = function (string $who) use ($sb, $tab): string { return (string)preg_replace('/^([a-z]+): [^A-Za-z]*/u', '$1: ', $sb->flash($who, $tab)); };
    $page = function (string $who, string $qs = '') use ($sb, $tab): string { return $sb->page($who, $tab . $qs); };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $w1 = 'A fictitious first wording for the screen test.';
    $w2 = 'A fictitious second wording, unlike the first one.';
    $save = function (array $f = []) use ($post, $w1, $w2): array {
        return $post('admin', $f + ['action' => 'offer_save', 'offer' => '0', 'title' => 'Screen offer (test)', 'wording_1' => $w1, 'wording_2' => $w2,
                                    'wording_3' => '', 'size' => '100']);
    };
    $lastId = function () use ($pdo): int { return (int)$pdo->query('SELECT MAX(id) FROM wa_offers')->fetchColumn(); };

    $ctx->run('C01 control: the admin sees the screen, its compose form and the menu entry', function () use ($ctx, $page) {
        $h = $page('admin');
        $ctx->eq([strpos($h, 'WhatsApp offers</h2>') !== false, strpos($h, 'name="action" value="offer_save"') !== false,
                  strpos($h, 'href="?page=dashboard&tab=wa_offers"') !== false, strpos($h, 'Reply STOP to stop these messages. (added automatically)') !== false],
                 [true, true, true, true], 'C01 the heading, the compose form, the menu entry, the fixed STOP line', substr(strip_tags($h), -600));
    });

    $ctx->run('C02 offers-test off: one card, no form, no menu entry, and every action refuses writing nothing (OM4, OM5)', function () use ($ctx, $page, $post, $flash, $setCfg, $count, $save) {
        $setCfg(['offers_test_enabled' => null]);
        $h = $page('admin');
        $ctx->eq([strpos($h, 'Offers are not switched on here.') !== false, strpos($h, 'offer_save') !== false, strpos($h, 'tab=wa_offers"') !== false],
                 [true, false, false], 'C02 the card alone; no form; no menu entry');
        $n = $count('SELECT COUNT(*) FROM wa_offers');
        foreach (['offer_save', 'offer_discard', 'offer_test', 'offer_start', 'offer_pause', 'offer_resume', 'offer_stop'] as $a) {
            $save(['action' => $a, 'offer' => '1', 'variant' => '1', 'channel' => 'sales']);
            $ctx->eq([$flash('admin'), $count('SELECT COUNT(*) FROM wa_offers')], ['danger: Offers are not switched on here — nothing was changed.', $n], 'C02 ' . $a . ' refused, nothing written');
        }
        $setCfg([]);
        $ctx->eq(strpos($page('admin'), 'offer_save') !== false, true, 'C02 control: switched on again, the form is back');
    });

    $ctx->run('C03 admins only: a salesperson, a visitor not signed in, and a session admin demoted in the store (OM6, OM7)', function () use ($ctx, $sb, $page, $post, $count, $save, $admin) {
        $h = $page('sales');
        $ctx->eq([strpos($h, 'offer_save') !== false, strpos($h, 'tab=wa_offers"') !== false], [false, false], 'C03 a salesperson: no screen, no menu entry');
        $n = $count('SELECT COUNT(*) FROM wa_offers');
        $r = $post('sales', ['action' => 'offer_save', 'offer' => '0', 'title' => 'Not allowed (test)', 'wording_1' => str_repeat('a', 30), 'wording_2' => str_repeat('b', 30), 'size' => '100']);
        $ctx->eq([$r[0], strpos($r[1], 'Access denied') !== false, $count('SELECT COUNT(*) FROM wa_offers')], [403, true, $n], 'C03 a salesperson\'s save: 403, nothing written');
        $r = $post(null, ['action' => 'offer_save', 'offer' => '0', 'title' => 'Not allowed (test)', 'wording_1' => str_repeat('a', 30), 'wording_2' => str_repeat('b', 30), 'size' => '100']);
        $ctx->eq([$r[0], $r[3], $count('SELECT COUNT(*) FROM wa_offers')], [302, '?page=login', $n], 'C03 a visitor not signed in: sent to sign in (requireAdmin), nothing written');
        $sb->update($admin, ['is_admin' => false]);
        $r = $save(['title' => 'Demoted (test)']);
        $sb->update($admin, ['is_admin' => true]);
        $ctx->eq([$r[0], $count('SELECT COUNT(*) FROM wa_offers')], [403, $n], 'C03 an admin demoted in the store, the session still cached as admin: 403, nothing written');
        $save(['title' => 'Promoted again (test)']);
        $ctx->eq($count('SELECT COUNT(*) FROM wa_offers'), $n + 1, 'C03 control: an admin again, the save goes');
    });

    $ctx->run('C04 the CSRF token and the own-page origin: refused with the fixed words, nothing written (OM8)', function () use ($ctx, $post, $flash, $count, $save) {
        $n = $count('SELECT COUNT(*) FROM wa_offers');
        $f = ['action' => 'offer_save', 'offer' => '0', 'title' => 'Cross site (test)', 'wording_1' => str_repeat('c', 30), 'wording_2' => str_repeat('d', 30), 'size' => '100'];
        $post('admin', $f, ['headers' => ['Sec-Fetch-Site: cross-site', 'Origin: https://evil.example']]);
        $ctx->eq([$flash('admin'), $count('SELECT COUNT(*) FROM wa_offers')],
                 ['danger: The security check failed, so nothing was changed. Open the Offers screen again and retry.', $n], 'C04 a cross-site post with a valid token: refused');
        $post('admin', $f, ['headers' => []]);
        $ctx->eq($count('SELECT COUNT(*) FROM wa_offers'), $n, 'C04 a post that shows no origin at all: refused');
        $post('admin', $f + ['_csrf' => 'bad'], []);
        $ctx->eq($count('SELECT COUNT(*) FROM wa_offers'), $n, 'C04 a bad token: refused');
        $post('admin', $f);
        $ctx->eq($count('SELECT COUNT(*) FROM wa_offers'), $n + 1, 'C04 control: from our own page with the token, saved');
    });

    $ctx->run('C05 the draft\'s validation: count, length, distinct, braces, STOP, size — each refused, nothing written', function () use ($ctx, $save, $flash, $count, $w1, $w2, $pdo, $lastId) {
        $n = $count('SELECT COUNT(*) FROM wa_offers');
        foreach ([['wording_2' => ''], ['wording_1' => 'Too short (test)'], ['wording_2' => str_repeat('x', 901)], ['wording_2' => strtoupper($w1)],
                  ['wording_2' => 'A wording with a {placeholder} in it (test).'], ['wording_2' => 'Reply stop to be left alone, says this wording.'],
                  ['title' => ''], ['size' => '300']] as $bad) {
            $save($bad);
            $f = $flash('admin');
            $ctx->eq([strpos($f, 'danger: Nothing was saved: ') === 0, $count('SELECT COUNT(*) FROM wa_offers')], [true, $n], 'C05 refused: ' . json_encode(array_map(function ($v) { return mb_substr((string)$v, 0, 20); }, $bad)), $f);
        }
        $save(['wording_3' => 'A third fictitious wording, different again.']);
        $o = OfferStore::find($pdo, $lastId());
        $ctx->eq([$count('SELECT COUNT(*) FROM wa_offers'), $o['state'], $o['wording_3'] !== null, $o['stop_line']], [$n + 1, 'draft', true, OfferText::STOP_LINE], 'C05 control: three wordings saved as a draft');
    });

    $ctx->run('C06 the image: accepted by its detected type, a renamed non-image and an oversized file refused with nothing saved', function () use ($ctx, $post, $flash, $count, $pdo, $lastId, $sb, $w1, $w2) {
        $img = imagecreatetruecolor(8, 8);
        ob_start(); imagepng($img); $png = (string)ob_get_clean();
        $f = ['action' => 'offer_save', 'offer' => '0', 'title' => 'With an image (test)', 'wording_1' => $w1, 'wording_2' => $w2, 'size' => '100'];
        $post('admin', $f, ['file' => ['image', $png, 'photo.jpg', 'image/jpeg']]);
        $o = OfferStore::find($pdo, $lastId());
        $ctx->eq([$o['image_mime'], $o['image_file'], is_file($sb->data . '/offer_images/offer-' . $o['id'] . '.png'), $o['image_sha256'] === hash('sha256', $png)],
                 ['image/png', 'offer-' . $o['id'] . '.png', true, true], 'C06 a PNG named .jpg: stored as .png, by its detected type, its sha256 recorded');
        $n = $count('SELECT COUNT(*) FROM wa_offers');
        $post('admin', $f, ['file' => ['image', 'not an image at all, only text (test)', 'photo.png', 'image/png']]);
        $ctx->eq([$flash('admin'), $count('SELECT COUNT(*) FROM wa_offers')], ['danger: Nothing was saved: the image was not accepted (not a jpg, png or webp image).', $n],
                 'C06 a renamed text file: refused, no draft made');
        $max = FlyerAsset::MAX_FILE_BYTES;
        $big = $png . str_repeat("\0", $max + 1 - strlen($png));         // a PNG one byte over 4 MB
        $post('admin', $f, ['file' => ['image', $big, 'big.png', 'image/png']]);
        $fl = $flash('admin');
        $ctx->eq([$fl, $count('SELECT COUNT(*) FROM wa_offers')], ['danger: Nothing was saved: the image was not accepted (larger than 4 MB).', $n],
                 'C06 a file one byte over 4 MB arrives (the upload limit above it) and is refused by the plugin\'s size rule: too_large, no draft made', $fl);
        $id = $lastId();
        $post('admin', ['offer' => (string)$id] + $f, ['file' => ['image', 'still not an image (test)', 'x.webp', 'image/webp']]);
        $ctx->eq((string)OfferStore::find($pdo, $id)['image_file'], 'offer-' . $id . '.png', 'C06 an edit with a refused image: the draft keeps its image');
        $edge = $png . str_repeat("\0", $max - strlen($png));            // exactly 4 MB: "at most FlyerAsset::MAX_FILE_BYTES"
        $post('admin', $f, ['file' => ['image', $edge, 'edge.png', 'image/png']]);
        $o = OfferStore::find($pdo, $lastId());
        $ctx->eq([$count('SELECT COUNT(*) FROM wa_offers'), (int)@filesize($sb->data . '/offer_images/offer-' . $o['id'] . '.png')], [$n + 1, $max],
                 'C06 control: a PNG of exactly 4 MB is accepted and stored whole', $flash('admin'));
        // OfferImage::accept itself, through its $move seam: the same two sizes
        $dir = $ctx->dir('c06');
        file_put_contents($dir . '/over', $big);
        file_put_contents($dir . '/edge', $edge);
        $mv = function (string $from, string $to): bool { return copy($from, $to); };
        $a = OfferImage::accept(['error' => UPLOAD_ERR_OK, 'tmp_name' => $dir . '/over', 'name' => 'over.png'], 991, $dir, $mv);
        $e = OfferImage::accept(['error' => UPLOAD_ERR_OK, 'tmp_name' => $dir . '/edge', 'name' => 'edge.png'], 992, $dir, $mv);
        $ctx->eq([$a['ok'], $a['code'], $e['ok'], $e['bytes'] ?? 0, is_file($dir . '/offer_images/offer-991.png')], [false, 'too_large', true, $max, false],
                 'C06 OfferImage::accept: 4 MB + 1 byte too_large and nothing stored; exactly 4 MB accepted');
    });

    // The Start's book: a warm customer the audience can take, the tests planted as sendTest() leaves them.
    $warm = function (string $phone) use ($b): void { wo_warm($b, $phone, time(), 86400); };
    $draft = function (array $f = []) use ($save, $lastId): int { $save($f); return $lastId(); };
    $tested = function (int $id, array $o = []) use ($b): void {
        foreach (array_keys(OfferStore::wordingsOf(OfferStore::find($b['pdo'], $id))) as $v) {
            wo_ptest($b, $id, $v, time() - 120, ($o[$v] ?? []) + ['ledger_row' => true, 'handset' => 'i:sj-sales']);
        }
    };
    $start = function (int $id, ?string $hash = null) use ($post, $pdo): array {
        return $post('admin', ['action' => 'offer_start', 'offer' => (string)$id, 'content_hash' => $hash ?? (string)OfferStore::find($pdo, $id)['content_hash']]);
    };

    $ctx->run('C07 Start refused, writing nothing: untested, an error-only test, an edit, offers-live off, another live offer, nobody eligible (OM53, OM55, OM120)', function () use ($ctx, $sb, $draft, $tested, $start, $flash, $pdo, $count, $setCfg, $warm, $save) {
        $none = function (int $id) use ($pdo, $count): array {
            return [(string)OfferStore::find($pdo, $id)['state'], $count('SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = ' . $id),
                    $count("SELECT COUNT(*) FROM wa_offer_events WHERE action = 'started' AND offer_id = " . $id)];
        };
        $id = $draft();
        $tested($id, [2 => ['state' => 'failed', 'ledger' => 'recorded']]);
        $start($id);
        $ctx->eq([$flash('admin'), $none($id)], ['danger: The offer was not started: every wording must be tested, sent and recorded, on the content as it is now — nothing was started.', ['draft', 0, 0]],
                 'C07 one wording untested (its test failed): refused');
        $id = $draft();
        $tested($id, [2 => ['ledger' => 'error']]);
        $start($id);
        $ctx->eq([$flash('admin'), $none($id)], ['danger: The offer was not started: every wording must be tested, sent and recorded, on the content as it is now — nothing was started.', ['draft', 0, 0]],
                 'C07 a wording whose only test was sent but recorded error: refused, untested');
        $id = $draft();
        $tested($id);
        $old = (string)OfferStore::find($pdo, $id)['content_hash'];
        $save(['offer' => (string)$id, 'wording_2' => 'An edited second wording, after the tests (test).']);
        $start($id);
        $ctx->eq([$flash('admin'), $none($id)], ['danger: The offer was not started: every wording must be tested, sent and recorded, on the content as it is now — nothing was started.', ['draft', 0, 0]],
                 'C07 after an edit: the tests are of the old content, refused, untested');
        $tested($id);
        $setCfg(['offers_live_enabled' => null]);
        $start($id);
        $ctx->eq([$flash('admin'), $none($id)], ['danger: Offers are not switched on here — nothing was changed.', ['draft', 0, 0]], 'C07 offers-live off: refused');
        $setCfg([]);
        new ConversationService($sb->data, $pdo);    // a book the webhook has written to (its columns made), with no warm customer yet
        $start($id);
        $ctx->eq([$flash('admin'), $none($id)], ['danger: The offer was not started: nobody can be sent this offer now — nothing was started.', ['draft', 0, 0]],
                 'C07 nobody eligible: the whole start rolled back');
        $warm('256700990911');
        $warm('256700990912');
        $start($id, $old);
        $ctx->eq([$flash('admin'), $none($id)], ['danger: The offer was not started: the offer is not a draft, or its wording changed since this page was drawn — nothing was started.', ['draft', 0, 0]],
                 'C07 confirmed on the page drawn before the edit (the old hash), customers waiting: refused');
        $start($id);
        $ctx->eq([$flash('admin'), $none($id)], ['success: Offer #' . $id . ' was started: 2 people queued.', ['running', 2, 1]], 'C07 control: tested, two warm customers: started');
        $id2 = $draft();
        $tested($id2);
        $start($id2);
        $ctx->eq([$flash('admin'), $none($id2)], ['danger: The offer was not started: another offer is live — nothing was started.', ['draft', 0, 0]], 'C07 another offer live: refused');
    });

    $ctx->run('C08 Pause, Resume and Stop; Stop skips the queued rows; Discard keeps the draft as history', function () use ($ctx, $post, $flash, $pdo, $count, $draft) {
        $live = OfferStore::live($pdo);
        $id = (int)($live['id'] ?? 0);
        $post('admin', ['action' => 'offer_pause', 'offer' => (string)$id]);
        $ctx->eq([(string)OfferStore::find($pdo, $id)['state'], $flash('admin')], ['paused', 'success: Offer #' . $id . ' was paused.'], 'C08 paused');
        $post('admin', ['action' => 'offer_resume', 'offer' => (string)$id]);
        $ctx->eq((string)OfferStore::find($pdo, $id)['state'], 'running', 'C08 resumed');
        $post('admin', ['action' => 'offer_stop', 'offer' => (string)$id]);
        $ctx->eq([(string)OfferStore::find($pdo, $id)['state'], OfferStore::find($pdo, $id)['live_slot'], $count("SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = {$id} AND state = 'skipped' AND reason = 'offer_stopped'")],
                 ['stopped', null, 2], 'C08 stopped: live_slot cleared, both queued rows skipped(offer_stopped)');
        $ev = $pdo->query("SELECT action FROM wa_offer_events WHERE offer_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq(array_slice($ev, -4), ['started', 'paused', 'resumed', 'stopped'], 'C08 the history');
        $d = $draft();
        $post('admin', ['action' => 'offer_discard', 'offer' => (string)$d]);
        $o = OfferStore::find($pdo, $d);
        $ctx->eq([$o['state'], $o['close_reason']], ['stopped', 'discarded'], 'C08 discard: the draft closed as discarded, never deleted');
    });

    $ctx->run('C09 the test: to the admin\'s own phone of record, never a number from the request; none with the send ledger off (OM52, OM113)', function () use ($ctx, $sb, $post, $flash, $pdo, $count, $draft, $setCfg) {
        $id = $draft();
        $sb->http('GET', "{$sb->evo}/__test/reset");
        $post('admin', ['action' => 'offer_test', 'offer' => (string)$id, 'variant' => '1', 'channel' => 'sales', 'phone' => '0700990999', 'number' => '0700990999']);
        $fl = $flash('admin');
        $tx = (array)($sb->http('GET', "{$sb->evo}/__test/state")[2]['text_calls'] ?? []);
        $t = $pdo->query('SELECT state, ledger, echo FROM wa_offer_tests WHERE offer_id = ' . $id)->fetchAll(\PDO::FETCH_NUM);
        $ctx->eq([count($tx), preg_replace('/\D+/', '', (string)($tx[0]['number'] ?? '')), $t], [1, '256700990901', [['sent', 'recorded', 'claimed']]],
                 'C09 sent to the admin\'s own phone, recorded in the ledger, its echo claimed', $fl);
        $tid = (int)$pdo->query('SELECT id FROM wa_offer_tests WHERE offer_id = ' . $id)->fetchColumn();
        $ctx->eq($pdo->query("SELECT kind, mode, outcome FROM wa_auto_sends WHERE ref = 'offer_test:{$tid}'")->fetchAll(\PDO::FETCH_NUM), [['other', 'record', 'sent']],
                 'C09 one ledger row for the test: kind other, recorded (never claimed), sent');
        $ctx->eq(strpos($fl, '0700990') === false && strpos($fl, '256700990') === false, true, 'C09 the flash names no number');
        $setCfg(['wa_send_ledger' => null]);
        $post('admin', ['action' => 'offer_test', 'offer' => (string)$id, 'variant' => '2', 'channel' => 'sales']);
        $tx = (array)($sb->http('GET', "{$sb->evo}/__test/state")[2]['text_calls'] ?? []);
        $ctx->eq([$flash('admin'), count($tx), $count('SELECT COUNT(*) FROM wa_offer_tests WHERE offer_id = ' . $id)], ['danger: The send ledger is off — nothing was sent.', 1, 1],
                 'C09 the send ledger off: refused, nothing sent, no test row');
        $setCfg([]);
        $h = $sb->page('admin', 'page=dashboard&tab=wa_offers&offer=' . $id);
        $ctx->eq([strpos($h, 'Send to my phone') !== false, (bool)preg_match('/(?:256)?70099090\d/', $h)], [true, false], 'C09 the screen offers the test and prints no number');
    });

    $ctx->run('C10 the preview: each wording as it will go, the audience by number, counts only; no customer\'s number on any page', function () use ($ctx, $sb, $draft, $pdo) {
        $id = $draft();
        $h = $sb->page('admin', 'page=dashboard&tab=wa_offers&offer=' . $id . '&preview=1');
        $ctx->eq([strpos($h, 'Preview</h3>') !== false, substr_count($h, 'Reply STOP to stop these messages.') >= 3, strpos($h, 'Eligible now:') !== false],
                 [true, true, true], 'C10 the preview: the wordings with the STOP line, the audience counts', substr(strip_tags($h), -900));
        $ctx->eq((bool)preg_match('/(?:256)?70099091\d/', $h), false, 'C10 no customer\'s number appears');
    });

    } finally {
        $sb->stop();
    }
}];
