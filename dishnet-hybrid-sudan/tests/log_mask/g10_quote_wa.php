<?php
declare(strict_types=1);
/**
 * g10_quote_wa.php — 5.18.105 (root docs/74 §H.1.10): group G10 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-11 … LN-14, four of cron_quote_wa.php's lines in quote_wa_log.json (QWA), through test_quote_cron_log's harness
 * (tests/fixtures/staff_jobs_sandbox.php: the served plugin, the real cron run in the sandbox, the fake uCRM that lists
 * and prints quotes, the fake Evolution), on Uganda, one run:
 *   LN-11, LN-12  Flow A — a deferred KYC quotation (app #…, quote #3411 Q-3411, waiting more than three minutes, its phone
 *                 +256700000111 stored on the application): its TEXT and its PDF;
 *   LN-13         Flow B — a quotation made in uCRM (Q-3413) for client 113, whose contact phone is +256700000113:
 *                 "… UCRM quote #3413 Q-3413 → Test Quote Customer";
 *   LN-14         the webhook's auto-approve PDF queue — one entry older than 30 s and fresher than an hour, carrying
 *                 +256700000114: "… from pending queue: #Q-3414 → Test Pending Customer".
 * Every person and number is fictitious. tests/test_quote_cron_log.php's own lines (Q-3401) are not touched.
 *
 * The queue is written in the code's own form — quote_wa_state.json as one keyed object, as webhook.php (:3294) and the
 * cron itself (:694) save it. On the tree as shipped it is never read back — an older fault, the same on 994d660's own
 * files (measured in this harness; 5.18.105 changes neither lib/SqliteStore.php nor the queue but for LN-14's line):
 * lib/SqliteStore.php:92 $FLAT_TABLES has no 'quote_wa_state', so load() returns a one-element list; cron_quote_wa.php:394
 * (994d660: :391) finds no 'pdf_pending', the queue is never processed, and :694 saves 'pdf_pending' => [] — the
 * queued entry is dropped. So the sandbox's copy takes that one entry in its lib/SqliteStore.php (lm_plant_flat(),
 * anchored and asserted) and nothing else; cron_quote_wa.php is the judged file byte for byte. The control after the
 * judged run puts the store back as shipped, queues an entry the same way, and finds it dropped, unread.
 */

/** One fictitious uCRM quotation, as the fake uCRM keeps it. */
function lm_quote(int $id, int $client): array
{
    return ['id' => $id, 'number' => 'Q-' . $id, 'clientId' => $client, 'status' => 1, 'createdDate' => date('c'),
            'items' => [['label' => 'Starlink Standard Kit', 'quantity' => 1, 'price' => 2500000.0, 'total' => 2500000.0]], 'total' => 2500000.0];
}

/** quote_wa_log.json's entries, each as a judged line (its time left out). */
function lm_qwa_lines(string $data): array
{
    return lm_json_entries($data . '/quote_wa_log.json');
}

function lm_g10(QfCtx $ctx): void
{
    $ctx->run('G10 LN-11 LN-12 LN-13 LN-14 cron_quote_wa.php: flows A and B and the PDF queue, quote_wa_log.json', function () use ($ctx) {
        require_once $ctx->root . '/tests/fixtures/staff_jobs_sandbox.php';
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ], 'lmg10');
        lm_plant_flat($s->plug, 'quote_wa_state');   // the older fault — see the header
        file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
        $s->seedCrm([
            'clients' => ['113' => ['id' => 113, 'firstName' => 'Test Quote', 'lastName' => 'Customer', 'isLead' => false,
                                    'contacts' => [['phone' => lm_num(13), 'email' => 'quoted@example.test', 'isBilling' => true]]]],
            'quotes'  => ['3411' => lm_quote(3411, 111), '3413' => lm_quote(3413, 113), '3414' => lm_quote(3414, 114)],
        ]);
        $store = $s->store();
        $app = $store->appendWithId('kyc_applications.json', ['firstname' => 'Test', 'lastname' => 'Kyc', 'crm_client_id' => 111,
            'quote_id' => 3411, 'quote_ref' => 'Q-3411', 'quote_created' => true, 'wa_quote_pending' => true, 'wa_quote_sent' => false,
            'wa_quote_phone' => lm_num(11), 'wa_quote_deferred_at' => date('Y-m-d H:i:s', time() - 600),
            'created_at' => date('Y-m-d H:i:s', time() - 900), 'status' => 'new']);
        $store->save('quote_wa_state.json', ['sent_ids' => [3414], 'pdf_pending' => [[
            'quote_id' => 3414, 'quote_num' => 'Q-3414', 'phone' => lm_num(14), 'name' => 'Test Pending Customer',
            'amount' => '2500000.00', 'queued_at' => time() - 120]]]);
        [$rc, $out] = $s->run('cron_quote_wa.php');
        $lines = lm_qwa_lines($s->data);
        $media = count((array)($s->http('GET', "{$s->evo}/__test/state")[2]['media_calls'] ?? []));
        // the control: the store as shipped, an entry queued the same way — it is dropped, never read
        copy($ctx->root . '/lib/SqliteStore.php', $s->plug . '/lib/SqliteStore.php');
        $shipped = sha1_file($ctx->root . '/lib/SqliteStore.php') === sha1_file($s->plug . '/lib/SqliteStore.php');
        $queueLines = function (array $ls): int { return count(array_filter($ls, function ($l) { return strpos($l, 'pending queue') !== false; })); };
        $before = $queueLines(lm_qwa_lines($s->data));
        $store->save('quote_wa_state.json', ['sent_ids' => [3414], 'pdf_pending' => [[
            'quote_id' => 3414, 'quote_num' => 'Q-3414', 'phone' => lm_num(14), 'name' => 'Test Pending Customer',
            'amount' => '2500000.00', 'queued_at' => time() - 120]]]);
        $s->run('cron_quote_wa.php');
        $kept = json_decode((string)lm_pdo($s->data)->query('SELECT data FROM quote_wa_state WHERE id = 0')->fetchColumn(), true);
        $after = $queueLines(lm_qwa_lines($s->data));
        $s->stop();
        $ctx->t($shipped && $before >= 1 && $after === $before && is_array($kept) && ($kept['pdf_pending'] ?? null) === [],
            'G10 control: with the store as shipped the queue is not read and its entry is dropped (the older fault, 994d660\'s too)',
            json_encode(['store_as_shipped' => $shipped, 'queue_lines_before' => $before, 'after' => $after, 'pdf_pending_saved' => $kept['pdf_pending'] ?? null]));
        $all = [lm_num(11), lm_num(13), lm_num(14)];
        $appId = (int)($app['id'] ?? 0);
        $ctx->t($appId > 0 && $lines !== [], 'G10 control: the cron ran and wrote its log', substr($out, 0, 400));
        $ctx->eq($media, 3, 'G10 control: three PDFs went (flow A\'s, flow B\'s and the queue\'s)');
        $f11 = lm_ln($ctx, 'LN-11', $lines, $all);
        $ctx->t($f11 !== [] && strpos($f11[0], 'TEXT SENT KYC app #' . $appId . ' quote #3411 Q-3411"') !== false,
            'LN-11 "TEXT SENT KYC app #<id> quote #3411 Q-3411", the line ending there', implode(' | ', $f11));
        $f12 = lm_ln($ctx, 'LN-12', $lines, $all);
        $ctx->t($f12 !== [] && strpos($f12[0], 'PDF SENT KYC app #' . $appId . ' quote #3411 Q-3411"') !== false,
            'LN-12 "PDF SENT KYC app #<id> quote #3411 Q-3411", the line ending there', implode(' | ', $f12));
        $f13 = lm_ln($ctx, 'LN-13', $lines, $all);
        $ctx->t($f13 !== [] && strpos($f13[0], 'TEXT SENT UCRM quote #3413 Q-3413 → Test Quote Customer"') !== false,
            'LN-13 "TEXT SENT UCRM quote #3413 Q-3413 → <name>"', implode(' | ', $f13));
        $f14 = lm_ln($ctx, 'LN-14', $lines, $all);
        $ctx->t($f14 !== [] && strpos($f14[0], 'PDF SENT from pending queue: #Q-3414 → Test Pending Customer"') !== false,
            'LN-14 "PDF SENT from pending queue: #Q-3414 → <name>"', implode(' | ', $f14));
    });
}
