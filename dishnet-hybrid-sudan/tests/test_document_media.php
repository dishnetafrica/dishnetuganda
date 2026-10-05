<?php
declare(strict_types=1);
/**
 * test_document_media.php — Batch 4 of the AI communication layer, Slice 4a (docs/55 §9, docs/58): DOCUMENTS, dark.
 *
 * A customer's document, fetched by the Batch 1 media worker, is read INSIDE this process — no library, no provider, no byte
 * leaving the server — classified with deterministic rules that FAIL CLOSED, and then EITHER becomes the customer's turn
 * through the EXISTING assistant (general text, a spreadsheet — seen whole, no sensitive signal) OR becomes a record and a
 * person's job (payment evidence, a statement, an invoice, a contract, a quotation, an identity document, a credential) OR a
 * reason and a person's job (a refusal, or a classification that could not be made safely). Only while ai_media_enabled AND
 * ai_media_document are on, and they are on nowhere. A PDF's text layer is read in this process since Slice 4b (D-1 = P-1;
 * tests/test_document_pdf.php proves the reader); a scanned document has no OCR (D-2). The two D-11 wrapper fixes are
 * flag-independent and proved here too.
 *
 * Two scenarios return FACTS (no asserting inside): `core`, in-process against the fake Evolution and the fake uCRM with the
 * deterministic FakeDocumentOcr injected and a fake brain (the real marker parser) on the reply worker; `cli`, the real plugin
 * under php -S (SjSandbox) with run_media_worker.php over the CLI. Every document is built here, byte for byte, from nothing
 * real. Driver mode: php tests/test_document_media.php --driver=core|cli (env DN_T_EVO_PORT, DN_T_UCRM_PORT).
 */
$root = dirname(__DIR__);
date_default_timezone_set('UTC');
$driver = null;
foreach ($argv ?? [] as $a) { if (strpos($a, '--driver') === 0) $driver = substr($a, 9) !== false && substr($a, 9) !== '' ? substr($a, 9) : 'core'; }

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/UtcClock.php';
require_once $root . '/lib/AlertService.php';
require_once $root . '/lib/EvoWebhookGuard.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/WaLocation.php';
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/AiLeadService.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/BrainContext.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/MediaPolicy.php';
require_once $root . '/lib/MediaBlob.php';
require_once $root . '/lib/InboundMedia.php';
require_once $root . '/lib/MediaFetcher.php';
require_once $root . '/lib/PaymentEvidence.php';
require_once $root . '/lib/DocumentOcr.php';
require_once $root . '/lib/DocumentExtraction.php';
require_once $root . '/lib/Handover.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/MediaWorker.php';
require_once $root . '/workers/AiReplyWorker.php';
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // SjSandbox, sj_weakened_copy()
require_once $root . '/tests/fixtures/document_fixtures.php';    // vd_*()

/** A brain that never leaves the process, but parses its canned answer with the REAL marker parser (Batch 0's). */
class VdFakeBrain extends DishNetAiBrain
{
    public string $canned = '';
    public ?array $lastContext = null;
    public int $calls = 0;
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->calls++;
        $this->lastContext = $context;
        $m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
        $m->setAccessible(true);
        $out = $m->invoke($this, $this->canned);
        return is_array($out) ? $out : ['reply' => $this->canned];
    }
    public function getLastUsage(): array { return []; }
}

// ── The documents, named once ─────────────────────────────────────────────────────────────────────
const VD_MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const VD_MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const VD_GENERAL   = ['We are a school in Gulu with three buildings.', 'Can one dish connect all of them?', 'The buildings are 40 metres apart and the main block has an iron roof.'];
const VD_RECEIPT   = ['MTN Mobile Money', 'You have sent UGX 150,000 to DishNet Africa.', 'Transaction ID 7G4K2Q9', 'Status: successful', 'Balance UGX 23,400'];
const VD_INVOICE   = ['TAX INVOICE No. INV-2026-0042', 'Bill to: Example Trading Ltd', 'Amount due: UGX 1,690,000', 'Due date: 30 October 2026', 'Account 0012345678'];
const VD_CONTRACT  = ['SERVICE AGREEMENT', 'This agreement is made between DishNet Africa and the Customer (the parties).', 'The parties hereby agree to the following terms.', 'Signature: ______'];
const VD_QUOTATION = ['QUOTATION No. Q-2026-19', 'Standard kit, qty 1, unit price 1,690,000', 'Valid until 30 October 2026'];
const VD_IDENTITY  = ['REPUBLIC OF UGANDA', 'NATIONAL IDENTIFICATION CARD', 'Surname: TESTSURNAME', 'Given names: TEST PERSON', 'Date of birth: 01.01.1990', 'NIN: CF00000000TEST'];
const VD_UNCERTAIN = ['Hello DishNet, we run a small shop in Mbale and need internet for two laptops.', 'Our old provider\'s invoice was far too high for what we got.'];
const VD_STATEMENT = "Account statement for September\nOpening balance 50,000\n01/09 Deposit 20,000\n15/09 Withdrawal 10,000\nClosing balance 60,000\nAccount no 0012345678\n";
const VD_CREDENTIAL = "Router admin login\nusername: admin\npassword: hunter2xyz99\naws key AKIAABCDEFGHIJKLMNOP\n";
const VD_TXT       = "Hello,\nwe would like internet for our clinic in Arua.\nWe have two rooms and a generator.\n";
const VD_CSV       = "Site;Town;Buildings\nMain;Gulu;3\nAnnex;Lira;1\n";

// ── Scenario 1: core, in-process ─────────────────────────────────────────────────────────────────
function vd_core(string $root, int $evoPort, int $ucrmPort): array
{
    $tmp = sys_get_temp_dir() . '/dn_vd_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    putenv('DN_DATA_DIR=' . $tmp);
    vd_hit($evoPort, '/__test/reset');
    vd_hit($ucrmPort, '/__test/reset');
    vd_hit($ucrmPort, '/__test/scenario?name=fresh_install');
    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    $HOLD  = 'Let me get a colleague to help you with that.';
    $cfg = ['evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'TESTKEY', 'evo_instance_sales' => 'dishnet_ug',
            'evo_instance_support' => 'dishnet_ug', 'ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_document' => '1',
            // Batch 5 (docs/60): the two rungs above it, so this suite keeps proving the REPLY mode that Slices 4a and 4b built
            'ai_media_document_handover' => '1', 'ai_media_document_reply' => '1',
            'ai_media_max_bytes' => 131072, 'ai_media_document_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'ai_media_document_timeout_s' => 5,
            'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
            'crm_base_url' => "http://127.0.0.1:{$ucrmPort}", 'crm_auth_token' => 'test-key',
            'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD, 'wa_human_cooldown_minutes' => 30, 'data_dir' => $tmp];
    $f = ['tmp' => $tmp, 'hold' => $HOLD];
    $logAll = '';
    $texts   = function () use ($evoPort): array { return vd_state($evoPort)['text_calls'] ?? []; };
    $fetches = function () use ($evoPort): int { return count(vd_state($evoPort)['media_fetch_calls'] ?? []); };
    $serve = function (string $waId, string $bytes, string $mime = VD_MIME_DOCX, string $fileName = 'document.docx') use ($evoPort): string {
        vd_hit($evoPort, '/__test/media', ['for_id' => $waId, 'base64' => base64_encode($bytes), 'mimetype' => $mime, 'fileName' => $fileName]);
        return vd_sha($bytes);
    };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $escs    = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'"); };
    $lastReply = function () use ($pdo): array {
        $r = $pdo->query("SELECT id, payload, created_by, status FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['p'] = json_decode((string)($r['payload'] ?? ''), true) ?: [];
        return $r;
    };
    $lastEsc = function () use ($pdo): array {
        $r = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'wa.escalation' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['by' => $r['created_by'] ?? null, 'reason' => (string)((json_decode((string)($r['payload'] ?? ''), true) ?: [])['reason'] ?? '')];
    };
    $conv  = function (string $phone) use ($svc): int { return (int)$svc->ensureConversation($phone, 'sales', 'Document Tester', 'test')['id']; };
    $state = function (int $cid) use ($svc): string { return (string)($svc->getConversation($cid)['state'] ?? ''); };
    $body  = function (int $msgId) use ($pdo): string { return (string)$pdo->query("SELECT body FROM wa_messages WHERE id = {$msgId}")->fetchColumn(); };
    $meta  = function (int $msgId) use ($pdo): ?array { return (json_decode((string)$pdo->query("SELECT metadata FROM wa_messages WHERE id = {$msgId}")->fetchColumn(), true) ?: [])['document'] ?? null; };
    /** A document as the webhook records it: the stored message (caption or placeholder), the wa_media row, the ai.media event. */
    $docm = function (string $waId, string $phone, string $caption = '', array $o = []) use ($svc, $pdo, $bus, $conv): array {
        $cid = $conv($phone);
        $env = vd_envelope('document', $waId, $phone, ['caption' => $caption] + $o);
        $msgId = $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => $caption !== '' ? $caption : '[DOCUMENT]', 'media_type' => 'document', 'wa_message_id' => $waId]);
        $mediaId = InboundMedia::record($pdo, $cid, InboundMedia::fromEvoMessage($env['data']), 'dishnet_ug', 'sales', $waId);
        $evt = $bus->emit('ai.media', 'conversation', $cid, ['media_id' => $mediaId, 'conversation_id' => $cid, 'channel' => 'sales',
            'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => $waId, 'kind' => 'document', 'has_caption' => $caption !== '', 'received_at' => gmdate('c')], 3, 'test');
        return ['cid' => $cid, 'media_id' => (int)$mediaId, 'msg_id' => (int)$msgId, 'event' => $evt];
    };
    $fake = new FakeDocumentOcr([]);
    $script = function (string $sha, array $entry) use (&$fake): void { $rp = new ReflectionProperty(FakeDocumentOcr::class, 'script'); $rp->setAccessible(true); $s = $rp->getValue($fake); $s[$sha] = $entry; $rp->setValue($fake, $s); };
    $mkMedia = function (array $ov = [], $ocr = null, ?DocumentDeadline $dl = null) use ($store, $cfg, &$fake): MediaWorker {
        $w = new MediaWorker($store, array_merge($cfg, $ov), 30, 10);
        $w->useDocumentOcr($ocr === 'fake' ? $fake : $ocr);
        if ($dl !== null) $w->useDocumentDeadline($dl);
        return $w;
    };
    $run = function ($worker) use (&$logAll) { ob_start(); try { $r = $worker->run(); } finally { $out = (string)ob_get_clean(); $logAll .= $out; } return ['r' => $r, 'log' => $out]; };
    $mkReply = function (string $canned) use ($store, $cfg): array {
        $w = new AiReplyWorker($store, $cfg, 30, 10);
        $b = new VdFakeBrain($cfg); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        return [$w, $b];
    };
    $holdingTo = function (string $phone) use ($texts, $HOLD): int { return count(array_filter($texts(), function ($c) use ($phone, $HOLD) { return ($c['number'] ?? '') === $phone && trim((string)$c['text']) === $HOLD; })); };
    $alerts = function () use ($texts): array { return array_values(array_map(function ($c) { return (string)$c['text']; }, array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256700000999'; }))); };
    /** One document through the worker: the facts every refusal and every class shares. */
    $probe = function (string $waId, string $phone, string $bytes, array $o = []) use ($docm, $serve, $run, $mkMedia, $replies, $escs, $state, $holdingTo, $alerts, $lastEsc, $body, $meta, $pdo, &$fake): array {
        $n = $docm($waId, $phone, (string)($o['caption'] ?? ''), (array)($o['env'] ?? []));
        $serve($waId, $bytes, (string)($o['mime'] ?? VD_MIME_DOCX), (string)($o['fileName'] ?? 'document.docx'));
        $rc = $replies(); $ec = $escs(); $a0 = count($alerts()); $oc = count($fake->calls);
        $r = $run($mkMedia((array)($o['cfg'] ?? []), $o['ocr'] ?? null, $o['deadline'] ?? null));
        $row = vd_row($pdo, $waId);
        return ['n' => $n, 'run' => $r['r'], 'log' => $r['log'], 'row' => vd_brief($row), 'replies_added' => $replies() - $rc, 'escalations_added' => $escs() - $ec,
                'ocr_calls_added' => count($fake->calls) - $oc, 'state' => $state($n['cid']), 'holding' => $holdingTo($phone), 'alerts' => array_slice($alerts(), $a0),
                'esc' => $lastEsc(), 'msg_body' => $body($n['msg_id']), 'meta' => $meta($n['msg_id'])];
    };
    $L = DocumentExtraction::LABEL; $LC = DocumentExtraction::LABEL_CAPTION;

    // ── 1–3. A general Word document: the row, the message, the one event, the brain ─────────────
    // The conversation opens with a typed greeting (a conversation's first message is never replayed).
    $cid1 = $conv('256772000811');
    $svc->storeMessage($cid1, ['direction' => 'in', 'role' => 'customer', 'body' => 'Hello', 'wa_message_id' => 'VD-0-TEXT']);
    $bus->emit('ai.reply', 'conversation', $cid1, ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000811',
        'message' => 'Hello', 'push_name' => 'Document Tester', 'wa_message_id' => 'VD-0-TEXT', 'remote_jid' => '256772000811@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    [$rw0] = $mkReply('Hello! How can I help you today?');
    $run($rw0);
    $texts0 = count($texts()); $rc0 = $replies();
    $docx1 = vd_docx(VD_GENERAL, ['deleted' => 'this deleted sentence must never be read', 'extra' => ['word/embeddings/oleObject1.bin' => str_repeat('X', 100), 'payload.zip' => vd_zip(['a.txt' => 'nested'])]]);
    $t1 = $probe('VD-1', '256772000811', $docx1, ['fileName' => 'school-site-plan.docx', 'env' => ['fileName' => 'school-site-plan.docx']]);
    $ev1 = $lastReply();
    $f['t1'] = ['run' => $t1['run'], 'row' => $t1['row'], 'msg_body' => $t1['msg_body'], 'meta' => $t1['meta'], 'replies_added' => $t1['replies_added'], 'fetches' => $fetches(),
                'event' => ['created_by' => $ev1['created_by'] ?? null, 'status' => $ev1['status'] ?? null, 'message' => $ev1['p']['message'] ?? null, 'origin' => $ev1['p']['origin'] ?? null,
                            'document' => $ev1['p']['document'] ?? null, 'phone' => $ev1['p']['customer_phone'] ?? null, 'channel' => $ev1['p']['channel'] ?? null,
                            'wa_message_id' => $ev1['p']['wa_message_id'] ?? null, 'received_at_ok' => UtcClock::parse((string)($ev1['p']['received_at'] ?? '')) > 0,
                            'has_image_or_voice' => array_key_exists('voice', $ev1['p']) || array_key_exists('image', $ev1['p'])],
                'media_event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$t1['n']['event']}")->fetchColumn(),
                'log_read' => preg_match('/media #\d+ \(conversation \d+\): document read \(docx, general\) — \d+ characters, ai\.reply #\d+ queued/', $t1['log']) === 1,
                'message_has_filename' => strpos((string)($ev1['p']['message'] ?? ''), 'school-site-plan') !== false,
                'message_has_deleted' => strpos((string)($ev1['p']['message'] ?? ''), 'deleted sentence') !== false,
                'label_prefix' => $L . ' — Word document, 4 paragraphs]', 'ocr_calls' => count($fake->calls)];   // the deleted paragraph is a paragraph; its text is not read
    $n1 = $t1['n'];

    // ── 4. A duplicate event, and the understood guard called directly ────────────────────────────
    $bus->emit('ai.media', 'conversation', $n1['cid'], ['media_id' => $n1['media_id'], 'conversation_id' => $n1['cid'], 'channel' => 'sales',
        'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => 'VD-1', 'kind' => 'document', 'has_caption' => false, 'received_at' => gmdate('c')], 3, 'test');
    $r4 = $run($mkMedia());
    $direct = (new DocumentExtraction($pdo, $store, $cfg, null, function () {}))->complete(vd_row($pdo, 'VD-1'), ['route' => 'brain', 'class' => 'general', 'kind' => 'docx', 'text' => 'a second extract', 'facts' => []]);
    $f['t4'] = ['run' => $r4['r'], 'fetches' => $fetches(), 'replies_added' => $replies() - $rc0, 'log_dup' => strpos($r4['log'], 'duplicate event, nothing fetched') !== false,
                'direct' => $direct['outcome'] ?? null, 'row_text_still_first' => strpos((string)(vd_row($pdo, 'VD-1')['understanding'] ?? ''), 'school in Gulu') !== false];

    // ── 3. The brain: labelled message, document key, prompt block; later history ────────────────
    [$rw, $brain] = $mkReply('Thank you for the plan. One dish can serve three buildings that close together with a good router. Which town is the school in?');
    $r3 = $run($rw);
    $lc = $brain->lastContext ?? [];
    $prompt = (new DishNetAiBrain($cfg))->promptPreview($lc);
    $t3texts = $texts();
    $f['t3'] = ['run' => $r3['r'], 'brain_calls' => $brain->calls, 'ctx_document' => $lc['document'] ?? 'absent', 'ctx_image' => $lc['image'] ?? 'absent', 'ctx_voice' => $lc['voice'] ?? 'absent',
                'ctx_message_labelled' => strpos((string)($lc['message'] ?? ''), $L . ' — ') === 0, 'ctx_is_contract' => BrainContext::isContract($lc),
                'ctx_json_has_filename' => strpos(json_encode($lc), 'school-site-plan') !== false, 'ctx_json_has_sha' => strpos(json_encode($lc), vd_sha($docx1)) !== false,
                'ctx_json_has_phone' => strpos(json_encode($lc), '256772000811') !== false,
                'prompt_block' => strpos($prompt, 'DOCUMENT JUST RECEIVED (docx, classified as general)') !== false,
                'prompt_rules' => strpos($prompt, 'AUTOMATIC EXTRACTION') !== false && strpos($prompt, 'UNCONFIRMED') !== false && strpos($prompt, 'NEVER accept, approve, confirm or agree') !== false && strpos($prompt, 'rule 7') !== false,
                'prompt_without_document_has_block' => strpos((new DishNetAiBrain($cfg))->promptPreview(array_diff_key($lc, ['document' => 1])), 'DOCUMENT JUST RECEIVED') !== false,
                'texts_added' => count($t3texts) - $texts0, 'reply_to' => (string)(end($t3texts)['number'] ?? ''), 'reply_sent' => (string)(end($t3texts)['text'] ?? ''),
                'stored_out' => $count("SELECT COUNT(*) FROM wa_messages WHERE conversation_id = {$n1['cid']} AND direction = 'out'")];
    $bus->emit('ai.reply', 'conversation', $n1['cid'], ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000811',
        'message' => 'It is in Gulu town', 'push_name' => 'Document Tester', 'wa_message_id' => 'VD-1-TEXT', 'remote_jid' => '256772000811@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    $svc->storeMessage($n1['cid'], ['direction' => 'in', 'role' => 'customer', 'body' => 'It is in Gulu town', 'wa_message_id' => 'VD-1-TEXT']);
    [$rw2, $brain2] = $mkReply('Thank you. A colleague will confirm coverage for Gulu.');
    $run($rw2);
    $hist = (array)($brain2->lastContext['history'] ?? []);
    $f['t3_history'] = ['turns' => count($hist), 'has_labelled_extract' => count(array_filter($hist, function ($h) use ($L) {
                            return strpos((string)($h['text'] ?? ''), $L) !== false && strpos((string)($h['text'] ?? ''), 'school in Gulu') !== false; })) === 1,
                        'typed_turn_has_document' => array_key_exists('document', $brain2->lastContext ?? [])];

    // ── A captioned document: the turn carries the extract AND the customer's words, labelled apart ──
    $CAP = 'Here is our site plan, can you install here?';
    $tc = $probe('VD-CAP', '256772000822', vd_docx(VD_GENERAL), ['caption' => $CAP]);
    $evc = $lastReply();
    $f['t_caption'] = ['message' => $evc['p']['message'] ?? null, 'ends_with_caption' => substr((string)($evc['p']['message'] ?? ''), -strlen("\n" . $LC . ' ' . $CAP)) === "\n" . $LC . ' ' . $CAP,
                       'has_caption' => $evc['p']['document']['has_caption'] ?? null, 'msg_body_same' => $tc['msg_body'] === ($evc['p']['message'] ?? null), 'replies_added' => $tc['replies_added']];
    [$rwc] = $mkReply('Yes, that site looks suitable. We can arrange a survey.');
    $run($rwc);

    // ── A spreadsheet, a CSV and a text file go to the assistant too ─────────────────────────────
    // ("Sum" as a row label would be a money word, and the fail-closed rule would send the sheet to a person — as it should.)
    $xlsx = vd_xlsx(['Sites' => [['Site', 'Town', 'Buildings'], ['Main', 'Gulu', 3], ['Annex', 'Lira', 1], ['All', 'inline:x', ['f' => 'SUM(C2:C3)', 'v' => 4]]], 'Notes' => [['Note'], ['Iron roof on the main block']]]);
    $tx = $probe('VD-XLSX', '256772000833', $xlsx, ['mime' => VD_MIME_XLSX, 'fileName' => 'sites.xlsx']);
    $evx = $lastReply();
    $f['t_xlsx'] = ['row' => $tx['row'], 'replies_added' => $tx['replies_added'], 'class' => $evx['p']['document']['classification'] ?? null, 'kind' => $evx['p']['document']['kind'] ?? null,
                    'rows' => $evx['p']['document']['rows'] ?? null, 'message' => $evx['p']['message'] ?? null,
                    'has_sheet_header' => strpos((string)($evx['p']['message'] ?? ''), 'Sheet "Sites" (4 rows, 3 columns)') !== false,
                    'has_cached_value' => strpos((string)($evx['p']['message'] ?? ''), 'All | x | 4') !== false, 'has_formula' => strpos((string)($evx['p']['message'] ?? ''), 'SUM(') !== false,
                    'label_ok' => strpos((string)($evx['p']['message'] ?? ''), $L . ' — Excel workbook, 2 sheets, 6 rows]') === 0];
    [$rwx] = $mkReply('Thank you for the list of sites.');
    $run($rwx);
    $tcsv = $probe('VD-CSV', '256772000834', VD_CSV, ['mime' => 'text/csv', 'fileName' => 'sites.csv']);
    $f['t_csv'] = ['row' => $tcsv['row'], 'replies_added' => $tcsv['replies_added'], 'class' => $lastReply()['p']['document']['classification'] ?? null, 'kind' => $lastReply()['p']['document']['kind'] ?? null,
                   'message_has_rows' => strpos((string)($lastReply()['p']['message'] ?? ''), 'Main | Gulu | 3') !== false];
    [$rwcsv] = $mkReply('Thank you.'); $run($rwcsv);
    $ttxt = $probe('VD-TXT', '256772000835', VD_TXT, ['mime' => 'text/plain', 'fileName' => 'request.txt']);
    $f['t_txt'] = ['row' => $ttxt['row'], 'replies_added' => $ttxt['replies_added'], 'class' => $lastReply()['p']['document']['classification'] ?? null, 'kind' => $lastReply()['p']['document']['kind'] ?? null,
                   'label_ok' => strpos((string)($lastReply()['p']['message'] ?? ''), $L . ' — text file, 3 lines]') === 0];
    [$rwt] = $mkReply('Thank you.'); $run($rwt);

    // ── 12 / 13. Payment evidence, a statement, an invoice, a contract, a quotation: a person, never the brain ──
    $moneyBefore = vd_money($pdo); $kycBefore = vd_kyc($pdo);
    $payBefore = (int)((json_decode(vd_hit($ucrmPort, '/__test/payments'), true) ?: [])['count'] ?? -1);
    $tp = $probe('VD-PAY', '256772000812', vd_docx(VD_RECEIPT), ['caption' => 'I have paid, see attached', 'fileName' => 'momo.docx']);
    $f['t12'] = $tp + ['expected_reason' => PaymentEvidence::handoverReason(),
                       'expected_body_prefix' => sprintf(DocumentExtraction::LABEL_EVIDENCE, 'payment evidence'),
                       'alert_has_amount' => count(array_filter($tp['alerts'], function ($t) { return strpos($t, '150,000') !== false || strpos($t, '7G4K2Q9') !== false; })) > 0,
                       'alert_says_nothing_recorded' => count(array_filter($tp['alerts'], function ($t) { return strpos($t, 'nothing was recorded or marked paid') !== false; })) === 1,
                       'log_has_amount' => strpos($tp['log'], '150,000') !== false || strpos($tp['log'], '7G4K2Q9') !== false,
                       'log_says' => strpos($tp['log'], 'document is payment evidence (payment_proof, docx) — no AI turn; a person handles it') !== false];
    $tpw = $probe('VD-PAYW', '256772000813', "Receipt\nTotal 85,000 paid in cash to the agent.\nThank you.\n", ['mime' => 'text/plain', 'fileName' => 'note.txt']);
    $f['t12_words'] = ['row' => $tpw['row'], 'replies_added' => $tpw['replies_added'], 'state' => $tpw['state'], 'esc_reason' => $tpw['esc']['reason']];
    $ti = $probe('VD-INV', '256772000814', vd_docx(VD_INVOICE), ['fileName' => 'bill.docx']);
    $f['t_invoice'] = ['row' => $ti['row'], 'replies_added' => $ti['replies_added'], 'state' => $ti['state'], 'esc_reason' => $ti['esc']['reason'], 'holding' => $ti['holding'], 'msg_body' => $ti['msg_body'], 'meta' => $ti['meta'], 'alerts' => $ti['alerts'],
                       'expected_reason' => DocumentExtraction::HANDOVER['invoice'], 'account_in_record' => strpos((string)$ti['row']['text'], '0012345678') !== false || strpos($ti['msg_body'], '0012345678') !== false,
                       'alert_has_account' => count(array_filter($ti['alerts'], function ($t) { return strpos($t, '0012345678') !== false || strpos($t, '1,690,000') !== false; })) > 0];
    $ts = $probe('VD-STMT', '256772000815', VD_STATEMENT, ['mime' => 'text/plain', 'fileName' => 'statement.txt']);
    $f['t_statement'] = ['row' => $ts['row'], 'replies_added' => $ts['replies_added'], 'state' => $ts['state'], 'esc_reason' => $ts['esc']['reason'], 'expected_reason' => DocumentExtraction::HANDOVER['statement'],
                         'account_in_record' => strpos((string)$ts['row']['text'], '0012345678') !== false];
    $tco = $probe('VD-CON', '256772000816', vd_docx(VD_CONTRACT), ['fileName' => 'agreement.docx']);
    $f['t_contract'] = ['row' => $tco['row'], 'replies_added' => $tco['replies_added'], 'state' => $tco['state'], 'esc_reason' => $tco['esc']['reason'], 'expected_reason' => DocumentExtraction::HANDOVER['contract']];
    $tq = $probe('VD-QUO', '256772000817', vd_docx(VD_QUOTATION), ['fileName' => 'quote.docx']);
    $f['t_quotation'] = ['row' => $tq['row'], 'replies_added' => $tq['replies_added'], 'state' => $tq['state'], 'esc_reason' => $tq['esc']['reason'], 'expected_reason' => DocumentExtraction::HANDOVER['quotation']];
    [$rwpay] = $mkReply('never called'); $run($rwpay);   // nothing is queued for any of these: the worker must find nothing

    // ── An identity document and a credential: a person, and NOTHING of the content kept ─────────
    $tid = $probe('VD-ID', '256772000818', vd_docx(VD_IDENTITY), ['fileName' => 'id.docx']);
    $f['t_identity'] = ['row' => $tid['row'], 'replies_added' => $tid['replies_added'], 'state' => $tid['state'], 'esc_reason' => $tid['esc']['reason'], 'msg_body' => $tid['msg_body'], 'meta' => $tid['meta'], 'alerts' => $tid['alerts'],
                        'expected_reason' => DocumentExtraction::HANDOVER['identity_document'], 'expected_body' => sprintf(DocumentExtraction::LABEL_EVIDENCE, 'identity document'),
                        'alerts_have_content' => count(array_filter($tid['alerts'], function ($t) { return strpos($t, 'TESTSURNAME') !== false || strpos($t, 'CF00000000TEST') !== false; })) > 0];
    $tcr = $probe('VD-CRED', '256772000819', VD_CREDENTIAL, ['mime' => 'text/plain', 'fileName' => 'router.txt']);
    $f['t_credential'] = ['row' => $tcr['row'], 'replies_added' => $tcr['replies_added'], 'state' => $tcr['state'], 'esc_reason' => $tcr['esc']['reason'], 'msg_body' => $tcr['msg_body'],
                          'expected_reason' => DocumentExtraction::HANDOVER['credential'], 'expected_body' => sprintf(DocumentExtraction::LABEL_EVIDENCE, 'document containing a credential')];
    $f['t13'] = ['money_before' => $moneyBefore, 'money_after' => vd_money($pdo), 'kyc_before' => $kycBefore, 'kyc_after' => vd_kyc($pdo), 'ucrm_payments_before' => $payBefore,
                 'ucrm_payments_after' => (int)((json_decode(vd_hit($ucrmPort, '/__test/payments'), true) ?: [])['count'] ?? -1)];

    // ── Uncertain, and incomplete: a person, never a guess ────────────────────────────────────────
    $tu = $probe('VD-UNC', '256772000820', vd_docx(VD_UNCERTAIN), ['fileName' => 'shop.docx']);
    $f['t_uncertain'] = ['row' => $tu['row'], 'replies_added' => $tu['replies_added'], 'state' => $tu['state'], 'holding' => $tu['holding'], 'esc_reason' => $tu['esc']['reason'],
                         'expected_reason' => DocumentExtraction::handoverReason('failed', 'classification_uncertain'), 'log' => strpos($tu['log'], 'classification_uncertain') !== false];
    $bigRows = []; for ($i = 0; $i < 2100; $i++) $bigRows[] = ['Site ' . $i, 'Town', $i % 7];
    $tbig = $probe('VD-BIGSHEET', '256772000821', vd_xlsx(['Sites' => $bigRows]), ['mime' => VD_MIME_XLSX, 'fileName' => 'sites.xlsx']);
    $f['t_incomplete'] = ['row' => $tbig['row'], 'replies_added' => $tbig['replies_added'], 'state' => $tbig['state'], 'esc_reason' => $tbig['esc']['reason']];
    $f['t_classifier'] = ['uncertain_by_name' => DocumentClassifier::classify('Here is the site plan for the school.', 'docx', ['receipt', 'pdf'], true),
                          'general' => DocumentClassifier::classify(implode(' ', VD_GENERAL), 'docx', ['plan', 'docx'], true),
                          'incomplete' => DocumentClassifier::classify(implode(' ', VD_GENERAL), 'docx', [], false),
                          'empty' => DocumentClassifier::classify('   ', 'txt', [], true),
                          'statement_over_payment' => DocumentClassifier::classify(VD_STATEMENT, 'txt', [], true)['class'],
                          'invoice_over_payment' => DocumentClassifier::classify(implode(' ', VD_INVOICE) . ' pay by bank transfer', 'docx', [], true)['class']];

    // ── PDFs: the text layer is read here since Slice 4b (D-1 = P-1); a scan has no OCR (D-2) ─────
    $pdfText = vd_pdf('text', 'Hello from a text PDF');
    $tpt = $probe('VD-PDFT', '256772000840', $pdfText, ['mime' => 'application/pdf', 'fileName' => 'letter.pdf']);
    $lrp = $lastReply();
    $f['t_pdf_text'] = ['row' => $tpt['row'], 'replies_added' => $tpt['replies_added'], 'state' => $tpt['state'], 'holding' => $tpt['holding'], 'ocr_calls_added' => $tpt['ocr_calls_added'],
                        'label_ok' => strpos((string)($lrp['p']['message'] ?? ''), $L . ' — PDF, 1 page read of 1]') === 0, 'has_text' => strpos((string)($lrp['p']['message'] ?? ''), 'Hello from a text PDF') !== false,
                        'document' => $lrp['p']['document'] ?? null, 'origin' => $lrp['p']['origin'] ?? null,
                        'payload_has_pdf_bytes' => strpos((string)($lrp['payload'] ?? ''), '%PDF') !== false || strpos((string)($lrp['payload'] ?? ''), base64_encode(substr($pdfText, 0, 48))) !== false];
    [$rwp] = $mkReply('Thank you for the letter.'); $run($rwp);
    // a PDF with a font and no text at all: a person, told so — never a guess, never the assistant
    $tpn = $probe('VD-PDFN', '256772000846', vd_pdfx(['pages' => [['lines' => []]]]), ['mime' => 'application/pdf', 'fileName' => 'blank.pdf']);
    $f['t_pdf_no_text'] = ['row' => $tpn['row'], 'replies_added' => $tpn['replies_added'], 'state' => $tpn['state'], 'holding' => $tpn['holding'], 'esc_reason' => $tpn['esc']['reason'],
                           'expected_reason' => DocumentExtraction::handoverReason('failed', 'pdf_no_text', '1 page, a text layer that yielded no text'), 'ocr_calls_added' => $tpn['ocr_calls_added']];
    $scanned = vd_pdf('scanned');
    $tps = $probe('VD-PDFS', '256772000841', $scanned, ['mime' => 'application/pdf', 'fileName' => 'scan.pdf']);
    $f['t_pdf_scanned'] = ['row' => $tps['row'], 'replies_added' => $tps['replies_added'], 'state' => $tps['state'], 'holding' => $tps['holding'], 'esc_reason' => $tps['esc']['reason'],
                           'expected_reason' => DocumentExtraction::handoverReason('failed', 'provider_missing', 'scanned PDF, 1 page; ai_document_provider is none'), 'ocr_calls_added' => $tps['ocr_calls_added']];
    putenv('DN_FAKE_DOCUMENT_OCR_FILE');
    $f['t_ocr_factory'] = DocumentOcrFactory::fromConfig([]) === null && DocumentOcrFactory::fromConfig(['ai_document_provider' => 'none']) === null
                        && DocumentOcrFactory::fromConfig(['ai_document_provider' => 'tesseract-not-integrated']) === null && DocumentOcrFactory::fromConfig(['ai_document_provider' => 'fake']) === null;
    // the empty boundary, exercised with the deterministic fake: a scanned receipt is evidence; a scanned letter is a turn; a timeout retries
    $script(vd_sha($scanned), ['text' => implode("\n", VD_RECEIPT), 'pages' => 1]);
    $tpo = $probe('VD-PDFO', '256772000842', $scanned, ['mime' => 'application/pdf', 'fileName' => 'scan.pdf', 'ocr' => 'fake']);
    $f['t_pdf_ocr_receipt'] = ['row' => $tpo['row'], 'replies_added' => $tpo['replies_added'], 'state' => $tpo['state'], 'esc_reason' => $tpo['esc']['reason'], 'ocr_calls_added' => $tpo['ocr_calls_added'], 'ocr_call' => end($fake->calls)];
    $scanned2 = vd_pdf('scanned') . "\n% second scan\n";
    $script(vd_sha($scanned2), ['text' => implode("\n", VD_GENERAL), 'pages' => 1]);
    $tpg = $probe('VD-PDFG', '256772000843', $scanned2, ['mime' => 'application/pdf', 'fileName' => 'scan2.pdf', 'ocr' => 'fake']);
    $f['t_pdf_ocr_general'] = ['row' => $tpg['row'], 'replies_added' => $tpg['replies_added'], 'label_ok' => strpos((string)($lastReply()['p']['message'] ?? ''), $L . ' — PDF, 1 page read of 1]') === 0,
                               'document' => $lastReply()['p']['document'] ?? null];
    [$rwg] = $mkReply('Thank you for the letter.'); $run($rwg);
    $scanned3 = vd_pdf('scanned') . "\n% third scan\n";
    $script(vd_sha($scanned3), ['fail' => 'timeout']);
    $tpto = $probe('VD-PDFTO', '256772000844', $scanned3, ['mime' => 'application/pdf', 'fileName' => 'scan3.pdf', 'ocr' => 'fake']);
    $ev8 = $pdo->query("SELECT status, attempts, error FROM events WHERE id = {$tpto['n']['event']}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $f['t_ocr_timeout'] = ['row' => $tpto['row'], 'event' => $ev8, 'state' => $tpto['state'], 'replies_added' => $tpto['replies_added'], 'error_has_jid' => strpos((string)($ev8['error'] ?? ''), '@s.whatsapp.net') !== false];
    $pdo->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE id = {$tpto['n']['event']}");
    $script(vd_sha($scanned3), ['text' => implode("\n", VD_GENERAL), 'pages' => 1]);
    $ff = $fetches(); $rc = $replies();
    $r8b = $run($mkMedia([], 'fake'));
    $f['t_ocr_retry'] = ['run' => $r8b['r'], 'row' => vd_brief(vd_row($pdo, 'VD-PDFTO')), 'event' => $pdo->query("SELECT status, attempts FROM events WHERE id = {$tpto['n']['event']}")->fetch(PDO::FETCH_ASSOC),
                         'fetches_added' => $fetches() - $ff, 'replies_added' => $replies() - $rc];
    [$rwr] = $mkReply('Thank you.'); $run($rwr);
    $tpe = $probe('VD-PDFE', '256772000845', vd_pdf('encrypted'), ['mime' => 'application/pdf', 'fileName' => 'locked.pdf', 'ocr' => 'fake']);
    $f['t_pdf_encrypted'] = ['row' => $tpe['row'], 'replies_added' => $tpe['replies_added'], 'state' => $tpe['state'], 'ocr_calls_added' => $tpe['ocr_calls_added'], 'esc_reason' => $tpe['esc']['reason']];

    // ── Refusals: encrypted, legacy, macro, malformed, the wrong type, too large, too slow, unavailable ──
    $ref = [];
    $ref['zip_encrypted'] = $probe('VD-ZENC', '256772000850', vd_docx(['x'], ['ov' => ['word/document.xml' => ['flags' => 1]]]))['row'];
    $ref['ole2'] = $probe('VD-OLE', '256772000851', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504), ['mime' => 'application/msword', 'fileName' => 'old.doc'])['row'];
    $ref['ole2_encrypted'] = $probe('VD-OLEE', '256772000852', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64) . "E\0n\0c\0r\0y\0p\0t\0e\0d\0P\0a\0c\0k\0a\0g\0e\0" . str_repeat("\0", 64))['row'];
    $ref['docm'] = $probe('VD-DOCM', '256772000853', vd_docx(['x'], ['ct' => 'application/vnd.ms-word.document.macroEnabled.main+xml']))['row'];
    $ref['docx_with_vba'] = $probe('VD-VBA', '256772000866', vd_docx(VD_GENERAL, ['vba' => true]))['row'];   // a Word main part beside a vbaProject part
    $ref['pdf_junk'] = $probe('VD-PDFJ', '256772000854', "%PDF-1.4\n" . str_repeat('DN-MEDIA-TEST-PAYLOAD/', 40), ['mime' => 'application/pdf'])['row'];
    $ref['junk'] = $probe('VD-JUNK', '256772000855', random_bytes(400) . "\0", ['mime' => 'application/pdf'])['row'];
    $ref['png_behind_pdf'] = $probe('VD-PNG', '256772000856', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64), ['mime' => 'application/pdf'])['row'];
    $ref['doctype'] = $probe('VD-DTD', '256772000857', vd_docx(['x'], ['doctype' => '<!DOCTYPE w [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>']))['row'];
    $ref['pptx'] = $probe('VD-PPT', '256772000858', vd_zip(['[Content_Types].xml' => '<Types><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>', 'ppt/presentation.xml' => '<p/>']))['row'];
    $ref['bomb_declared'] = $probe('VD-BOMB1', '256772000859', vd_docx(['x'], ['ov' => ['word/document.xml' => ['usize' => 1024 * 1024 * 1024]]]))['row'];
    $ref['bomb_lying'] = $probe('VD-BOMB2', '256772000860', vd_docx(['x'], ['pad' => str_repeat(' ', 2 * 1024 * 1024), 'ov' => ['word/document.xml' => ['usize' => 1024]]]))['row'];
    $bomb3 = vd_docx(['after the bomb'], ['pad' => str_repeat(' ', 20 * 1024 * 1024)]);   // 20 MiB of nothing, declared truthfully, a few KB on the wire
    $ref['bomb_true'] = $probe('VD-BOMB3', '256772000861', $bomb3)['row'];
    $ref['too_large_document'] = $probe('VD-BIG', '256772000862', str_repeat("a line of text\n", 5000), ['mime' => 'text/plain', 'env' => ['bytes' => 75000]])['row'];
    $ref['too_large_fetch'] = $probe('VD-HUGE', '256772000863', 'x', ['mime' => 'text/plain', 'env' => ['bytes' => 20000000]])['row'];
    $ref['too_slow'] = $probe('VD-SLOW', '256772000864', vd_docx(VD_GENERAL), ['deadline' => new DocumentDeadline(0.0)])['row'];
    DocumentExtraction::$capabilities = ['gzinflate' => false];
    $tun = $probe('VD-NOZ', '256772000865', vd_docx(VD_GENERAL));
    DocumentExtraction::$capabilities = null;
    $ref['extractor_unavailable'] = $tun['row'];
    $f['t_refusals'] = $ref;
    $f['t_refusal_handover'] = ['slow_state' => $state($conv('256772000864')), 'unavailable_state' => $tun['state'], 'unavailable_reason' => $tun['esc']['reason'],
                                'expected_unavailable' => DocumentExtraction::handoverReason('failed', 'extractor_unavailable'), 'bomb3_size' => strlen($bomb3)];
    $zipN = OoxmlArchive::open($docx1, new DocumentDeadline(5.0));
    $zipN->read('word/document.xml');
    try { $zipN->read('payload.zip'); $pl = 'read'; } catch (DocumentRefused $e) { $pl = $e->reason . ':' . $e->detail; }
    try { $zipN->read('word/embeddings/oleObject1.bin'); $em = 'read'; } catch (DocumentRefused $e) { $em = $e->reason . ':' . $e->detail; }
    $f['t_nested'] = ['opened' => $zipN->opened, 'payload' => $pl, 'embedding' => $em, 'names_has_payload' => in_array('payload.zip', $zipN->names(), true)];

    // ── The guard: a committing reply on a document turn is refused; the same words on a typed turn are not its business ──
    $tg = $probe('VD-GUARD', '256772000870', vd_docx(['Dear DishNet, we would like to connect our clinic in Soroti. Please advise the next steps.']), ['fileName' => 'letter.docx']);
    $audit0 = count($store->load('ai_security_events.json') ?? []);
    $COMMIT = 'Thank you — we have received your payment and the invoice has been settled, so installation goes ahead.';
    [$rwg1] = $mkReply($COMMIT);
    $run($rwg1);
    $audit = $store->load('ai_security_events.json') ?? [];
    $last  = is_array($audit) && $audit ? end($audit) : [];
    $tgt = array_values(array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256772000870'; }));
    $f['t_guard'] = ['row' => $tg['row'], 'audit_added' => count($audit) - $audit0, 'audit_modality' => $last['modality'] ?? 'absent', 'audit_categories' => $last['categories'] ?? [],
                     'audit_conv' => (int)($last['conversation_id'] ?? -1), 'expected_conv' => $tg['n']['cid'],
                     'leaked' => count(array_filter($tgt, function ($c) { return stripos((string)$c['text'], 'received your payment') !== false; })),
                     'fallback' => count(array_filter($tgt, function ($c) { return trim((string)$c['text']) === trim(\ReplyPrivacyGuard::SAFE_FALLBACK); })), 'state' => $state($tg['n']['cid'])];
    $cidT = $conv('256772000871');
    $svc->storeMessage($cidT, ['direction' => 'in', 'role' => 'customer', 'body' => 'Hello', 'wa_message_id' => 'VD-T0']);
    $bus->emit('ai.reply', 'conversation', $cidT, ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000871', 'message' => 'Did my payment go through?',
        'push_name' => 'Document Tester', 'wa_message_id' => 'VD-T1', 'remote_jid' => '256772000871@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    [$rwg2] = $mkReply('Once your payment is received, a colleague will confirm it and schedule the installation.');
    $run($rwg2);
    $f['t_guard_typed'] = ['audit_added' => count($store->load('ai_security_events.json') ?? []) - count($audit),
                           'sent' => count(array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256772000871' && strpos((string)$c['text'], 'Once your payment') !== false; }))];
    $f['t_guard_direct'] = ['doc_turn' => ReplyPrivacyGuard::check($COMMIT, ['values' => [], 'commitments' => true])['categories'],
                            'typed_turn' => ReplyPrivacyGuard::check($COMMIT, ['values' => []])['categories'],
                            'conditional' => ReplyPrivacyGuard::check('Once your payment is received we will schedule the installation.', ['values' => [], 'commitments' => true])['categories'],
                            'contract' => ReplyPrivacyGuard::check('We accept the terms of your agreement.', ['values' => [], 'commitments' => true])['categories'],
                            'identity' => ReplyPrivacyGuard::check('Your ID has been verified, welcome aboard.', ['values' => [], 'commitments' => true])['categories']];

    // ── 10. STOP is the webhook's — an extract reading "stop" is not an opt-out ───────────────────
    $oo0 = $count('SELECT COUNT(*) FROM contact_optouts');
    $tst = $probe('VD-STOPDOC', '256772000872', "stop\n", ['mime' => 'text/plain', 'fileName' => 'note.txt']);
    $f['t10'] = ['optouts_added' => $count('SELECT COUNT(*) FROM contact_optouts') - $oo0, 'row' => $tst['row']['status']];
    [$rw10] = $mkReply('Thank you for the note.'); $run($rw10);

    // ── D-11: the wrapped shapes, through the real conversation store and InboundMedia ────────────
    $w1 = vd_envelope('document', 'VD-W1', '256772000880', ['caption' => 'here is the receipt', 'wrap' => ['documentWithCaptionMessage']]);
    $w2 = vd_envelope('document', 'VD-W2', '256772000880', ['caption' => 'and again', 'wrap' => ['ephemeralMessage', 'documentWithCaptionMessage']]);
    $w3 = vd_envelope('document', 'VD-W3', '256772000880', ['wrap' => ['viewOnceMessage']]);
    $w4 = vd_envelope('document', 'VD-W4', '256772000880', ['wrap' => ['viewOnceMessageV2']]);
    $w5 = vd_envelope('document', 'VD-W5', '256772000880', ['caption' => 'four deep', 'wrap' => ['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage']]);
    $w6 = vd_envelope('text', 'VD-W6', '256772000880', ['text' => 'a disappearing hello', 'wrap' => ['ephemeralMessage']]);
    $plain = vd_envelope('document', 'VD-W0', '256772000880', ['caption' => 'plain']);
    $im = function (array $env) { $m = InboundMedia::fromEvoMessage($env['data']); return $m === null ? null : ['kind' => $m['kind'], 'caption' => $m['caption'], 'file_name' => $m['file_name']]; };
    $stored = function (array $env) use ($svc, $pdo): ?array {
        $cid = $svc->importEvoMessage($env['data'], 'sales');
        if ($cid === null) return null;
        $r = $pdo->query("SELECT body, media_type FROM wa_messages WHERE wa_message_id = '" . $env['data']['key']['id'] . "'")->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['cid' => (int)$cid, 'body' => $r['body'] ?? null, 'media_type' => $r['media_type'] ?? null];
    };
    $f['t_d11'] = ['inbound' => ['plain' => $im($plain), 'w1' => $im($w1), 'w2' => $im($w2), 'w3' => $im($w3), 'w4' => $im($w4), 'w5' => $im($w5)],
                   'stored' => ['plain' => $stored($plain), 'w1' => $stored($w1), 'w2' => $stored($w2), 'w3' => $stored($w3), 'w4' => $stored($w4), 'w5' => $stored($w5), 'w6' => $stored($w6)],
                   'unwrap_identity' => InboundMedia::unwrap(['conversation' => 'x']) === ['conversation' => 'x']];

    // ── 16 / 17. The flags ───────────────────────────────────────────────────────────────────────
    $toff = $probe('VD-OFF', '256772000890', vd_docx(VD_GENERAL), ['caption' => 'look at this', 'cfg' => ['ai_media_document' => '0']]);
    $f['t16'] = ['run' => $toff['run'], 'row' => $toff['row'], 'replies_added' => $toff['replies_added'], 'escalations_added' => $toff['escalations_added'], 'state' => $toff['state'], 'msg_body' => $toff['msg_body']];
    $f['t17_policy'] = ['document_alone' => MediaPolicy::documentEnabled(['ai_media_document' => '1']), 'both' => MediaPolicy::documentEnabled(['ai_media_enabled' => '1', 'ai_media_document' => '1']),
                        'media_alone' => MediaPolicy::documentEnabled(['ai_media_enabled' => '1']), 'none' => MediaPolicy::documentEnabled([]),
                        'max_bytes_capped_by_fetch' => MediaPolicy::documentMaxBytes(['ai_media_max_bytes' => 131072, 'ai_media_document_max_bytes' => 10485760]),
                        'max_bytes_default' => MediaPolicy::documentMaxBytes([]), 'pages_default' => MediaPolicy::documentMaxPages([]), 'timeout_default' => MediaPolicy::documentTimeoutSeconds([])];
    $ff = $fetches();
    $tmoff = $probe('VD-MOFF', '256772000891', vd_docx(VD_GENERAL), ['cfg' => ['ai_media_enabled' => '0', 'ai_media_document' => '1']]);
    $f['t17'] = ['run' => $tmoff['run'], 'row' => $tmoff['row'], 'replies_added' => $tmoff['replies_added'], 'fetches_added' => $fetches() - $ff];

    // ── 14 / 15. Nothing on disk, nothing in any log, nothing of the sensitive classes anywhere ───
    $needles = ['TESTSURNAME', 'CF00000000TEST', 'hunter2xyz99', 'AKIAABCDEFGHIJKLMNOP', base64_encode($docx1), vd_sha($docx1)];
    $f['t14'] = ['disk' => vd_scan($tmp, $needles), 'docx_bytes_on_disk' => vd_scan($tmp, [substr($docx1, 0, 64)]),
                 'db_sensitive' => vd_dbscan($pdo, ['TESTSURNAME', 'CF00000000TEST', 'hunter2xyz99', 'AKIAABCDEFGHIJKLMNOP']),
                 'db_general_extract_where' => vd_dbscan($pdo, ['school in Gulu'])];
    $f['t15'] = ['log_has_b64' => strpos($logAll, base64_encode($docx1)) !== false, 'log_has_extract' => strpos($logAll, 'school in Gulu') !== false || strpos($logAll, 'iron roof') !== false,
                 'log_has_payment_details' => strpos($logAll, '150,000') !== false || strpos($logAll, '7G4K2Q9') !== false || strpos($logAll, '0012345678') !== false,
                 'log_has_identity' => strpos($logAll, 'TESTSURNAME') !== false || strpos($logAll, 'CF00000000TEST') !== false,
                 'log_has_credential' => strpos($logAll, 'hunter2xyz99') !== false || strpos($logAll, 'AKIAABCDEFGHIJKLMNOP') !== false,
                 'log_has_jid' => strpos($logAll, '@s.whatsapp.net') !== false,
                 'fake_saw_content' => count(array_filter($fake->calls, function ($c) { return isset($c['text']) || strlen(json_encode($c)) > 200; }))];
    $f['texts_total'] = count($texts());
    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Scenario 2: the CLI runner in the real plugin tree ───────────────────────────────────────────
function vd_cli(string $root): array
{
    $HOLD = 'Let me get a colleague to help you with that.';
    $s = SjSandbox::start($root, ['ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_document' => '1', 'ai_document_provider' => 'fake',
        'ai_media_document_handover' => '1', 'ai_media_document_reply' => '1',   // Batch 5 (docs/60): the reply rung, as before this batch
        'tenant_profile' => 'uganda', 'ai_currency' => 'UGX', 'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
        'ai_media_max_bytes' => 131072, 'ai_media_document_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'ai_media_document_timeout_s' => 5,
        'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD], 'vd');
    $pdo = $s->store()->getPdo();
    $f = [];
    $L = DocumentExtraction::LABEL; $LC = DocumentExtraction::LABEL_CAPTION;
    $post  = function (array $env) use ($s): array { return $s->http('POST', "{$s->base}?page=evo_webhook", $env, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]); };
    $flag  = function (array $ov) use ($s): void { $cfg = array_merge($s->cfg, $ov); $s->store()->save('kyc_config.json', $cfg); file_put_contents($s->data . '/kyc_config.json', json_encode($cfg)); };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $scriptFile = $s->sb . '/fake_ocr.json'; $callLog = $s->sb . '/fake_ocr.log';
    $scanned = vd_pdf('scanned');
    file_put_contents($scriptFile, json_encode([vd_sha($scanned) => ['text' => implode("\n", VD_GENERAL), 'pages' => 1]]));
    $runner = function (bool $withEnv) use ($s, $scriptFile, $callLog): string {
        $env = $withEnv ? 'DN_FAKE_DOCUMENT_OCR_FILE=' . escapeshellarg($scriptFile) . ' DN_FAKE_DOCUMENT_OCR_LOG=' . escapeshellarg($callLog) . ' ' : '';
        return (string)shell_exec('cd ' . escapeshellarg($s->plug) . ' && DN_DATA_DIR=' . escapeshellarg($s->data) . ' DN_VAULT_FILE=' . escapeshellarg($s->vault) . ' ' . $env . 'php run_media_worker.php 2>/dev/null');
    };
    $serve = function (string $waId, string $bytes, string $mime = VD_MIME_DOCX, string $fileName = 'document.docx') use ($s): void {
        $s->http('POST', "{$s->evo}/__test/media", ['for_id' => $waId, 'base64' => base64_encode($bytes), 'mimetype' => $mime, 'fileName' => $fileName]);
    };
    $logLines = function () use ($callLog): int { return count(array_filter(explode("\n", (string)@file_get_contents($callLog)))); };
    $reqs = function () use ($s): array { return array_values(array_filter($s->crmDump()['requests'] ?? [], function ($r) { return ($r['method'] ?? 'GET') !== 'GET' && preg_match('#/(payments|invoices)#', (string)($r['path'] ?? '')); })); };
    $lastReply = function () use ($pdo): array {
        $r = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['p'] = json_decode((string)($r['payload'] ?? ''), true) ?: [];
        return $r;
    };
    $msg = function (string $waId) use ($pdo): array { return $pdo->query("SELECT body, media_type FROM wa_messages WHERE wa_message_id = '{$waId}'")->fetch(PDO::FETCH_ASSOC) ?: []; };
    $stateOf = function (string $phone) use ($pdo): string { return (string)$pdo->query("SELECT state FROM wa_conversations WHERE phone = '{$phone}'")->fetchColumn(); };
    $holdingTo = function (string $phone) use ($s, $HOLD): int { return count(array_filter($s->texts(), function ($c) use ($phone, $HOLD) { return ($c['number'] ?? '') === $phone && trim((string)$c['text']) === $HOLD; })); };
    $alertsWith = function (string $needle) use ($s): int { return count(array_filter($s->texts(), function ($c) use ($needle) { return ($c['number'] ?? '') === '256700000999' && strpos((string)$c['text'], $needle) !== false; })); };
    $generalDocx = vd_docx(VD_GENERAL);

    // C1 — both flags on, a CAPTIONED general document: the webhook queues NO text turn; the runner's document turn carries both, once
    $r = $post(vd_envelope('document', 'VD-W-1', '256772000911', ['caption' => 'Here is our site plan', 'fileName' => 'plan.docx']));
    $serve('VD-W-1', $generalDocx, VD_MIME_DOCX, 'plan.docx');
    $out1 = $runner(true);
    $ev = $lastReply(); $log = (string)@file_get_contents($s->data . '/ai_platform.log');
    $f['c1'] = ['webhook' => ['http' => $r[0], 'queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null], 'out' => trim($out1), 'row' => vd_brief(vd_row($pdo, 'VD-W-1')),
                'replies' => $replies(), 'event_by' => $ev['created_by'] ?? null, 'origin' => $ev['p']['origin'] ?? null, 'class' => $ev['p']['document']['classification'] ?? null,
                'has_caption' => $ev['p']['document']['has_caption'] ?? null, 'phone' => $ev['p']['customer_phone'] ?? null,
                'message_labelled' => strpos((string)($ev['p']['message'] ?? ''), $L . ' — Word document') === 0, 'message_has_caption' => strpos((string)($ev['p']['message'] ?? ''), $LC . ' Here is our site plan') !== false,
                'message_has_filename' => strpos((string)($ev['p']['message'] ?? ''), 'plan.docx') !== false, 'msg' => $msg('VD-W-1'), 'msg_is_message' => ($msg('VD-W-1')['body'] ?? null) === ($ev['p']['message'] ?? null),
                'ocr_log_lines' => $logLines(), 'log_has_extract' => strpos($log, 'school in Gulu') !== false, 'log_has_b64' => strpos($log, base64_encode($generalDocx)) !== false,
                'log_read' => strpos($log, 'document read (docx, general)') !== false, 'texts' => count($s->texts())];
    // C2 — a WRAPPED captioned document (documentWithCaptionMessage) with caption "stop": stored (D-11), the opt-out recorded as for typed text, recorded for the worker, the turn carries the caption
    $r = $post(vd_envelope('document', 'VD-W-STOP', '256772000912', ['caption' => 'stop', 'wrap' => ['documentWithCaptionMessage']]));
    $msgBefore = $msg('VD-W-STOP');   // as the webhook stored it, before the runner rewrites it under the label
    $serve('VD-W-STOP', vd_docx(['Please remove our number from your list.']));
    $runner(true);
    $oo = $pdo->query("SELECT phone, source, evidence FROM contact_optouts ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $f['c2'] = ['webhook' => ['queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null], 'msg' => $msgBefore, 'msg_after' => $msg('VD-W-STOP'), 'optouts' => $count('SELECT COUNT(*) FROM contact_optouts'), 'optout' => $oo,
                'row' => vd_brief(vd_row($pdo, 'VD-W-STOP')), 'replies' => $replies(), 'last_message_has_caption' => strpos((string)($lastReply()['p']['message'] ?? ''), $LC . ' stop') !== false];
    // C3 — a receipt through the real tree: evidence, hand-over, no event, no uCRM write
    $r = $post(vd_envelope('document', 'VD-W-PAY', '256772000913', ['caption' => 'paid', 'fileName' => 'momo.docx']));
    $serve('VD-W-PAY', vd_docx(VD_RECEIPT), VD_MIME_DOCX, 'momo.docx');
    $runner(true);
    $f['c3'] = ['row' => vd_brief(vd_row($pdo, 'VD-W-PAY')), 'replies' => $replies(), 'state' => $stateOf('256772000913'), 'holding' => $holdingTo('256772000913'),
                'alert' => $alertsWith('nothing was recorded or marked paid'), 'alert_has_amount' => $alertsWith('150,000') + $alertsWith('7G4K2Q9'),
                'msg_body_prefix' => strpos((string)($msg('VD-W-PAY')['body'] ?? ''), sprintf(DocumentExtraction::LABEL_EVIDENCE, 'payment evidence')) === 0,
                'ucrm_money_writes' => count($reqs()), 'ai_log_has_amount' => strpos((string)@file_get_contents($s->data . '/ai_platform.log'), '150,000') !== false];
    // C4 — document OFF: the caption is answered as text (as always) and the file is fetched only
    $flag(['ai_media_document' => '0']);
    $r = $post(vd_envelope('document', 'VD-W-2', '256772000914', ['caption' => 'and this one?']));
    $serve('VD-W-2', $generalDocx);
    $runner(true);
    $f['c4'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null, 'row' => vd_brief(vd_row($pdo, 'VD-W-2')), 'replies' => $replies(),
                'last_by' => (string)$pdo->query("SELECT created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'ocr_log_lines' => $logLines()];
    // C5 — media OFF: a WRAPPED captioned document is still stored (D-11 is flag-independent) and its caption answered as text; nothing is recorded for the worker
    $flag(['ai_media_enabled' => '0', 'ai_media_document' => '1']);
    $r = $post(vd_envelope('document', 'VD-W-3', '256772000915', ['caption' => 'wrapped, media off', 'wrap' => ['documentWithCaptionMessage']]));
    $runner(true);
    $f['c5'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null, 'row' => vd_row($pdo, 'VD-W-3'), 'msg' => $msg('VD-W-3'), 'replies' => $replies(),
                'last_by' => (string)$pdo->query("SELECT created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetchColumn(),
                'last_message' => (string)($lastReply()['p']['message'] ?? '')];
    // C6 — both on again: a scanned PDF with provider "fake" WITHOUT its environment fails closed; WITH it, the fake reads it and the one turn is queued
    $flag(['ai_media_enabled' => '1', 'ai_media_document' => '1']);
    $r = $post(vd_envelope('document', 'VD-W-4', '256772000916', ['mimetype' => 'application/pdf', 'fileName' => 'scan.pdf']));
    $serve('VD-W-4', $scanned, 'application/pdf', 'scan.pdf');
    $runner(false);
    $f['c6'] = ['row' => vd_brief(vd_row($pdo, 'VD-W-4')), 'state' => $stateOf('256772000916'), 'holding' => $holdingTo('256772000916'), 'alert' => $alertsWith('cannot be read automatically'),
                'ocr_log_lines' => $logLines(), 'replies' => $replies()];
    $r = $post(vd_envelope('document', 'VD-W-5', '256772000917', ['mimetype' => 'application/pdf', 'fileName' => 'scan.pdf']));
    $serve('VD-W-5', $scanned, 'application/pdf', 'scan.pdf');
    $runner(true);
    $f['c6_ocr'] = ['row' => vd_brief(vd_row($pdo, 'VD-W-5')), 'ocr_log_lines' => $logLines(), 'ocr_log' => trim((string)@file_get_contents($callLog)), 'replies' => $replies(),
                    'class' => $lastReply()['p']['document']['classification'] ?? null, 'kind' => $lastReply()['p']['document']['kind'] ?? null, 'event_by' => $lastReply()['created_by'] ?? null];
    // C7 — nested: ephemeral → documentWithCaption → document: stored and recorded (the runner has not run yet, so the row is pending)
    $r = $post(vd_envelope('document', 'VD-W-6', '256772000918', ['caption' => 'nested', 'wrap' => ['ephemeralMessage', 'documentWithCaptionMessage']]));
    $f['c7'] = ['webhook' => ['queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null], 'msg' => $msg('VD-W-6'), 'row' => vd_brief(vd_row($pdo, 'VD-W-6'))];
    $f['disk'] = array_merge(vd_scan($s->data, [base64_encode($generalDocx), '7G4K2Q9']), vd_scan($s->plug, [base64_encode($generalDocx), '7G4K2Q9']));
    $f['cs_jobs'] = (function () use ($s): array { [$rc, $out] = $s->run('tools/cron_status.php', ['--all']); return ['rc' => $rc, 'ai_media' => strpos($out, 'ai_media') !== false]; })();
    // C8 — the runner's memory floor, through the REAL runner under a 64M CLI limit (docs/58 §4, §7; the Slice 4b evidence gate): a
    // 12 MiB text file needs usage + 48 MiB + 16 MiB under DocumentExtraction's budget rule, which 64M cannot hold. With the raise
    // (run_media_worker.php lifts a CLI limit below 256M to 256M) the budget passes and the file is READ — to the 1 MiB text cap,
    // so classification_incomplete, a person; WITHOUT the raise the budget refuses it as too_large_document before a byte is read.
    // Either way a person; the REASON tells the two apart. The fake builds the payload on its side, so no large body ships here.
    $flag(['ai_media_enabled' => '1', 'ai_media_document' => '1', 'ai_media_max_bytes' => 16 * 1024 * 1024, 'ai_media_document_max_bytes' => 14 * 1024 * 1024]);
    $r = $post(vd_envelope('document', 'VD-W-7', '256772000919', ['mimetype' => 'text/plain', 'fileName' => 'big.txt', 'bytes' => 12 * 1024 * 1024]));
    $s->http('POST', "{$s->evo}/__test/media", ['for_id' => 'VD-W-7', 'bytes' => 12 * 1024 * 1024, 'mimetype' => 'text/plain', 'fileName' => 'big.txt']);
    $out8 = (string)shell_exec('cd ' . escapeshellarg($s->plug) . ' && DN_DATA_DIR=' . escapeshellarg($s->data) . ' DN_VAULT_FILE=' . escapeshellarg($s->vault)
                               . ' php -d memory_limit=64M run_media_worker.php 2>&1');
    $f['c8'] = ['webhook' => ['queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null], 'row' => vd_brief(vd_row($pdo, 'VD-W-7')),
                'fatal' => stripos($out8, 'Allowed memory size') !== false || stripos($out8, 'Fatal') !== false, 'out_len' => strlen($out8),
                'state' => $stateOf('256772000919'), 'alert_incomplete' => $alertsWith('classification_incomplete'), 'alert_budget' => $alertsWith('would not fit the memory budget')];
    $s->http('GET', "{$s->evo}/__test/reset");
    $s->stop();
    return $f;
}

// ── Driver mode: one JSON line ───────────────────────────────────────────────────────────────────
if ($driver !== null) {
    if ($driver === 'cli') {
        $facts = vd_cli($root);
    } else {
        $evo = (int)getenv('DN_T_EVO_PORT'); $ucrm = (int)getenv('DN_T_UCRM_PORT');
        $srvE = $srvU = null;
        if ($evo <= 0)  { [$srvE, $evo]  = vd_boot($root . '/tests/fixtures/fake_evo_server.php', 9700, 'FAKE-EVO-TEST'); }
        if ($ucrm <= 0) { [$srvU, $ucrm] = vd_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9770, 'FAKE-UCRM-TEST'); }
        $facts = vd_core($root, $evo, $ucrm);
        foreach ([$srvE, $srvU] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    echo json_encode($facts), "\n";
    exit(0);
}

// ── Main ─────────────────────────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 1200) : '') . "\n"; } }
function j($v): string { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
[$evoSrv, $evoPort]   = vd_boot($root . '/tests/fixtures/fake_evo_server.php', 9705, 'FAKE-EVO-TEST');
[$ucrmSrv, $ucrmPort] = vd_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9775, 'FAKE-UCRM-TEST');
if (!$evoPort || !$ucrmPort) { echo "FAIL could not start the fake servers\n0 passed, 1 failed\n"; exit(1); }

$c = vd_core($root, $evoPort, $ucrmPort);
$L = DocumentExtraction::LABEL; $LE = DocumentExtraction::LABEL_EVIDENCE; $LC = DocumentExtraction::LABEL_CAPTION;

echo "1. A valid document → row → fetch → extraction\n";
$t1 = $c['t1'];
is_(($t1['run']['processed'] ?? 0) === 1 && ($t1['run']['failed'] ?? -1) === 0 && $t1['media_event'] === 'done', 'the media event is processed and done', j($t1['run']));
is_($t1['row']['status'] === 'understood' && $t1['row']['kind'] === 'extraction' && strpos((string)$t1['row']['text'], 'school in Gulu') !== false && $t1['row']['attempts'] === 1,
    'the row: understood, kind extraction, the extract, one attempt', j($t1['row']));
is_(strpos($t1['msg_body'], $t1['label_prefix']) === 0 && ($t1['meta']['kind'] ?? '') === 'docx' && ($t1['meta']['classification'] ?? '') === 'general' && ($t1['meta']['evidence'] ?? null) === false && ($t1['meta']['record'] ?? '') === 'text',
    'the stored [DOCUMENT] message carries the labelled extract and the document metadata', j([$t1['msg_body'], $t1['meta']]));
is_($t1['fetches'] === 1 && $t1['ocr_calls'] === 0 && $t1['log_read'], 'one fetch, no OCR call, and the log says a document was read, by kind and class, with the event number', j([$t1['fetches'], $t1['ocr_calls']]));
is_($t1['message_has_filename'] === false && $t1['message_has_deleted'] === false, 'the turn carries neither the file name nor the deleted text (word/embeddings and payload.zip were never opened)', j($t1['event']['message']));

echo "\n2. The extract enters the existing brain exactly once\n";
$ev = $t1['event'];
is_($t1['replies_added'] === 1 && $ev['created_by'] === 'media_worker' && $ev['status'] === 'pending', 'exactly one ai.reply event for the document, queued by the media worker', j([$t1['replies_added'], $ev['created_by'], $ev['status']]));
is_($ev['phone'] === '256772000811' && $ev['channel'] === 'sales' && $ev['wa_message_id'] === 'VD-1' && $ev['received_at_ok'] && $ev['has_image_or_voice'] === false, 'the event has the shape the webhook gives a typed message, and no image or voice key', j($ev));
is_(($c['t3']['brain_calls'] ?? 0) === 1 && ($c['t3']['run']['processed'] ?? 0) === 1, 'the reply worker processed it with one brain call', j([$c['t3']['brain_calls'], $c['t3']['run']]));

echo "\n3. The document modality is preserved — event, context, prompt, audit\n";
is_($ev['origin'] === 'document' && ($ev['document']['classification'] ?? null) === 'general' && ($ev['document']['kind'] ?? null) === 'docx' && ($ev['document']['truncated'] ?? null) === false
    && ($ev['document']['has_caption'] ?? null) === false && (int)($ev['document']['media_id'] ?? 0) > 0 && (int)($ev['document']['chars'] ?? 0) > 50,
    'origin document, with classification, kind, truncation flag, caption flag, row id and length', j([$ev['origin'], $ev['document']]));
$t3 = $c['t3'];
is_($t3['ctx_is_contract'] && $t3['ctx_document'] === ['classification' => 'general', 'kind' => 'docx', 'truncated' => false] && $t3['ctx_image'] === 'absent' && $t3['ctx_voice'] === 'absent' && $t3['ctx_message_labelled'],
    'the sales contract carries document = {classification, kind, truncated} and the labelled message, and no image or voice key', j([$t3['ctx_document'], $t3['ctx_image'], $t3['ctx_voice']]));
is_($t3['ctx_json_has_filename'] === false && $t3['ctx_json_has_sha'] === false && $t3['ctx_json_has_phone'] === false, 'the context carries no file name, no hash and no phone number', j([$t3['ctx_json_has_filename'], $t3['ctx_json_has_sha'], $t3['ctx_json_has_phone']]));
is_($t3['prompt_block'] && $t3['prompt_rules'] && $t3['prompt_without_document_has_block'] === false, 'the prompt carries the DOCUMENT block with the never-accept rule and rule 7, and not for a typed turn', j([$t3['prompt_block'], $t3['prompt_rules'], $t3['prompt_without_document_has_block']]));
is_($t3['texts_added'] === 1 && $t3['reply_to'] === '256772000811' && strpos($t3['reply_sent'], 'Which town') !== false && $t3['stored_out'] === 2, 'the brain\'s reply went to the customer once and was stored', j([$t3['texts_added'], $t3['reply_to'], $t3['reply_sent'], $t3['stored_out']]));
is_($c['t3_history']['has_labelled_extract'] && $c['t3_history']['typed_turn_has_document'] === false, 'a later typed turn sees the labelled extract in history, and carries no document key', j($c['t3_history']));
$tc = $c['t_caption'];
is_($tc['ends_with_caption'] && $tc['has_caption'] === true && $tc['msg_body_same'] && $tc['replies_added'] === 1, 'a captioned document: extract and caption in one turn, each under its own label; the inbox shows the same', j($tc));
is_($c['t_guard']['audit_modality'] === 'document', 'a blocked reply\'s audit event says modality document', j($c['t_guard']));

echo "\n4. A duplicate event is idempotent\n";
$t4 = $c['t4'];
is_(($t4['run']['processed'] ?? 0) === 1 && $t4['fetches'] === 1 && $t4['replies_added'] === 1 && $t4['log_dup'], 'the duplicate is acknowledged: no second fetch, no second extraction, no second event', j($t4));
is_($t4['direct'] === 'already_understood' && $t4['row_text_still_first'], 'the understood guard holds even when completion is called directly', j($t4));

echo "\n5. A spreadsheet, a CSV and a text file are the assistant's — within the caps, formulas never evaluated\n";
$tx = $c['t_xlsx'];
is_($tx['row']['status'] === 'understood' && $tx['replies_added'] === 1 && $tx['class'] === 'spreadsheet' && $tx['kind'] === 'xlsx' && $tx['rows'] === 6 && $tx['label_ok'],
    'an Excel workbook: understood, class spreadsheet, kind xlsx, 2 sheets / 6 rows in the label', j([$tx['row'], $tx['class'], $tx['kind'], $tx['rows'], $tx['label_ok']]));
is_($tx['has_sheet_header'] && $tx['has_cached_value'] && $tx['has_formula'] === false, 'the turn shows the sheet header and the formula\'s cached value, never the formula', j($tx['message']));
is_($c['t_csv']['row']['status'] === 'understood' && $c['t_csv']['class'] === 'spreadsheet' && $c['t_csv']['kind'] === 'csv' && $c['t_csv']['message_has_rows'] && $c['t_csv']['replies_added'] === 1, 'a CSV: class spreadsheet, kind csv, its rows in the turn', j($c['t_csv']));
is_($c['t_txt']['row']['status'] === 'understood' && $c['t_txt']['class'] === 'general' && $c['t_txt']['kind'] === 'txt' && $c['t_txt']['label_ok'] && $c['t_txt']['replies_added'] === 1, 'a text file: class general, kind txt, 3 lines in the label', j($c['t_txt']));

echo "\n6. Payment evidence in a document: evidence and a person only — the Batch 3 rule unchanged\n";
$t12 = $c['t12'];
is_($t12['row']['status'] === 'understood' && $t12['row']['kind'] === 'document_evidence' && strpos((string)$t12['row']['text'], 'MTN Mobile Money') === 0 && mb_strlen((string)$t12['row']['text']) <= 301,
    'the row: understood, kind document_evidence, a masked excerpt as the record', j($t12['row']));
is_($t12['replies_added'] === 0 && ($t12['run']['processed'] ?? 0) === 1 && $t12['ocr_calls_added'] === 0, 'NO ai.reply event — the brain never sees it', j([$t12['replies_added'], $t12['run']]));
is_($t12['state'] === 'needs_human' && $t12['escalations_added'] === 1 && $t12['esc']['by'] === 'media_worker' && $t12['esc']['reason'] === $t12['expected_reason'],
    'handed to a person: needs_human, one wa.escalation by the media worker with the payment rule\'s own words', j([$t12['state'], $t12['escalations_added'], $t12['esc']]));
is_($t12['alert_says_nothing_recorded'] && $t12['alert_has_amount'] === false && $t12['holding'] === 1, 'the staff alert says nothing was recorded — without the amount or the reference; the customer gets the holding line once', j([$t12['alerts'], $t12['holding']]));
is_(strpos($t12['msg_body'], $t12['expected_body_prefix']) === 0 && strpos($t12['msg_body'], $LC . ' I have paid, see attached') !== false && ($t12['meta']['evidence'] ?? null) === true && ($t12['meta']['record'] ?? '') === 'excerpt' && ($t12['meta']['classification'] ?? '') === 'payment_proof',
    'the inbox shows the evidence under the payment label, with the caption, flagged as evidence', j([$t12['msg_body'], $t12['meta']]));
is_($t12['log_says'] && $t12['log_has_amount'] === false, 'the log says payment evidence, a person handles it — and never the amount or the reference', j([$t12['log_says'], $t12['log_has_amount']]));
is_($c['t12_words']['row']['kind'] === 'document_evidence' && $c['t12_words']['replies_added'] === 0 && $c['t12_words']['state'] === 'needs_human' && $c['t12_words']['esc_reason'] === PaymentEvidence::handoverReason(),
    'a text file saying "Total 85,000 paid in cash": payment evidence by the words alone', j($c['t12_words']));

echo "\n7. A statement, an invoice, a contract and a quotation: a person, told which it is\n";
foreach (['t_statement' => 'statement', 't_invoice' => 'invoice', 't_contract' => 'contract', 't_quotation' => 'quotation'] as $key => $class) {
    $t = $c[$key];
    is_($t['row']['status'] === 'understood' && $t['row']['kind'] === 'document_evidence' && $t['replies_added'] === 0 && $t['state'] === 'needs_human' && $t['esc_reason'] === $t['expected_reason'],
        "a {$class}: recorded as evidence, no event, a person — with the {$class} hand-over wording exactly", j($t));
}
is_($c['t_invoice']['account_in_record'] === false && $c['t_invoice']['alert_has_account'] === false && $c['t_statement']['account_in_record'] === false && ($c['t_invoice']['meta']['record'] ?? '') === 'excerpt' && $c['t_invoice']['holding'] === 1,
    'the account number is masked in the record and absent from the alert; the record is an excerpt; the holding line once', j([$c['t_invoice']['row'], $c['t_invoice']['msg_body'], $c['t_invoice']['alerts']]));

echo "\n8. An identity document and a credential: a person, and NOTHING of the content kept anywhere\n";
$tid = $c['t_identity'];
is_($tid['row']['status'] === 'understood' && $tid['row']['kind'] === 'document_evidence' && (string)$tid['row']['text'] === '' && $tid['replies_added'] === 0 && $tid['state'] === 'needs_human' && $tid['esc_reason'] === $tid['expected_reason'],
    'an identity document: recorded as evidence with an EMPTY record, no event, the KYC hand-over wording exactly', j($tid));
is_($tid['msg_body'] === $tid['expected_body'] && ($tid['meta']['record'] ?? '') === 'none' && $tid['alerts_have_content'] === false, 'the inbox shows the identity label alone; the alert carries no name or number', j([$tid['msg_body'], $tid['meta'], $tid['alerts']]));
$tcr = $c['t_credential'];
is_($tcr['row']['kind'] === 'document_evidence' && (string)$tcr['row']['text'] === '' && $tcr['replies_added'] === 0 && $tcr['state'] === 'needs_human' && $tcr['esc_reason'] === $tcr['expected_reason'] && $tcr['msg_body'] === $tcr['expected_body'],
    'a document with a password and a key: evidence with an EMPTY record, no event, the credential hand-over wording exactly, the label alone in the inbox', j($tcr));
is_($c['t14']['db_sensitive'] === [], 'no table anywhere holds the identity name or number, the password or the key', j($c['t14']['db_sensitive']));

echo "\n9. No financial or KYC write from document understanding\n";
$t13 = $c['t13'];
is_($t13['money_before'] === $t13['money_after'] && count($t13['money_before']) >= 8, 'every money table has exactly the rows it had (' . count($t13['money_before']) . ' tables compared)', j($t13));
is_($t13['kyc_before'] === $t13['kyc_after'] && count($t13['kyc_before']) >= 1, 'every identity or KYC table has exactly the rows it had (' . count($t13['kyc_before']) . ' tables compared)', j([$t13['kyc_before'], $t13['kyc_after']]));
is_($t13['ucrm_payments_before'] === 0 && $t13['ucrm_payments_after'] === 0, 'the fake uCRM received no payment', j([$t13['ucrm_payments_before'], $t13['ucrm_payments_after']]));
$writers = ['CrmApiClient', 'createPayment', 'DpoPaymentService', 'CashbookService', 'markPaid', 'mark_paid', 'cb_ledger', 'dpo_payments', 'wallet_transactions', 'staff_ledger', 'KycService', 'KycCrmSync', 'CustomerIdentityService', 'kyc_applications'];
$newLibs = ['lib/DocumentExtraction.php', 'lib/DocumentClassifier.php', 'lib/DocumentSniffer.php', 'lib/OoxmlArchive.php', 'lib/DocxReader.php', 'lib/SheetReader.php', 'lib/TextReader.php', 'lib/PdfReader.php', 'lib/DocumentOcr.php', 'lib/DocumentDeadline.php'];
$noWriter = true; $where = [];
foreach (array_merge($newLibs, ['workers/MediaWorker.php', 'lib/Handover.php', 'lib/PaymentEvidence.php']) as $rel) {
    $code = vd_codeOf($root . '/' . $rel);
    foreach ($writers as $w) if (strpos($code, $w) !== false) { $noWriter = false; $where[] = "$rel has $w"; }
}
is_($noWriter, 'neither the document path, the worker nor the hand-over names any payment, ledger, cashbook, KYC or CRM writer', j($where));

echo "\n10. Classification FAILS CLOSED: uncertain, incomplete, empty — a person, never the assistant\n";
$tu = $c['t_uncertain'];
is_($tu['row']['status'] === 'failed' && $tu['row']['reason'] === 'classification_uncertain' && $tu['replies_added'] === 0 && $tu['state'] === 'needs_human' && $tu['holding'] === 1 && $tu['esc_reason'] === $tu['expected_reason'] && $tu['log'],
    'one sensitive word ("invoice") in an otherwise harmless letter: classification_uncertain, no event, a person decides', j($tu));
is_($c['t_incomplete']['row']['status'] === 'failed' && $c['t_incomplete']['row']['reason'] === 'classification_incomplete' && $c['t_incomplete']['replies_added'] === 0 && $c['t_incomplete']['state'] === 'needs_human',
    'a sheet over the scanning cap: the classifier did not see all of it, so nothing about it is harmless — a person', j($c['t_incomplete']));
$tcl = $c['t_classifier'];
is_(($tcl['uncertain_by_name']['route'] ?? '') === 'human' && ($tcl['uncertain_by_name']['reason'] ?? '') === 'classification_uncertain' && ($tcl['general']['route'] ?? '') === 'brain' && ($tcl['general']['class'] ?? '') === 'general'
    && ($tcl['incomplete']['reason'] ?? '') === 'classification_incomplete' && ($tcl['empty']['reason'] ?? '') === 'empty_extraction' && $tcl['statement_over_payment'] === 'statement' && $tcl['invoice_over_payment'] === 'invoice',
    'the classifier: a file named "receipt" is a signal; a harmless plan is general; unseen text is incomplete; nothing is empty; a decisive statement or invoice phrase outranks the payment words', j($tcl));

echo "\n11. PDFs: the text layer is read (D-1 = P-1, Slice 4b), a layer with no text is a person, a scan has no OCR (D-2), the empty boundary works\n";
$tpt = $c['t_pdf_text'];
is_($tpt['row']['status'] === 'understood' && $tpt['row']['kind'] === 'extraction' && $tpt['replies_added'] === 1 && $tpt['state'] !== 'needs_human' && $tpt['holding'] === 0 && $tpt['ocr_calls_added'] === 0
    && $tpt['label_ok'] && $tpt['has_text'] && ($tpt['document']['kind'] ?? '') === 'pdf' && ($tpt['document']['classification'] ?? '') === 'general' && ($tpt['document']['pages_read'] ?? 0) === 1 && $tpt['origin'] === 'document',
    'a PDF with a text layer is READ in this process: one turn labelled "PDF, 1 page read of 1" carrying its text, class general, origin document, no OCR call', j($tpt));
is_($tpt['payload_has_pdf_bytes'] === false, 'the event carries the extracted text and never the PDF\'s bytes or their base64', j($tpt['payload_has_pdf_bytes']));
$tpn = $c['t_pdf_no_text'];
is_($tpn['row']['status'] === 'failed' && $tpn['row']['reason'] === 'pdf_no_text' && $tpn['replies_added'] === 0 && $tpn['state'] === 'needs_human' && $tpn['holding'] === 1 && $tpn['esc_reason'] === $tpn['expected_reason'] && $tpn['ocr_calls_added'] === 0,
    'a PDF whose text layer yields nothing: pdf_no_text, a person told so word for word, the holding line once, no OCR call, no event', j($tpn));
$tps = $c['t_pdf_scanned'];
is_($tps['row']['status'] === 'failed' && $tps['row']['reason'] === 'provider_missing' && $tps['replies_added'] === 0 && $tps['state'] === 'needs_human' && $tps['esc_reason'] === $tps['expected_reason'] && $tps['ocr_calls_added'] === 0,
    'a scanned PDF with no provider: provider_missing, a person told it is scanned, no event', j($tps));
is_($c['t_ocr_factory'] === true, 'the OCR factory yields no provider for none, unset, an unknown name, or "fake" without its test environment');
$tpo = $c['t_pdf_ocr_receipt'];
is_($tpo['row']['kind'] === 'document_evidence' && $tpo['replies_added'] === 0 && $tpo['state'] === 'needs_human' && $tpo['esc_reason'] === PaymentEvidence::handoverReason() && $tpo['ocr_calls_added'] === 1
    && !isset($tpo['ocr_call']['text']) && strlen((string)($tpo['ocr_call']['sha'] ?? '')) === 12 && ($tpo['ocr_call']['max_pages'] ?? 0) === 20,
    'through the fake OCR, a scanned receipt is payment evidence; the provider recorded a hash prefix and the page cap, never text', j($tpo));
$tpg = $c['t_pdf_ocr_general'];
is_($tpg['row']['status'] === 'understood' && $tpg['row']['kind'] === 'extraction' && $tpg['replies_added'] === 1 && $tpg['label_ok'] && ($tpg['document']['pages_read'] ?? 0) === 1 && ($tpg['document']['kind'] ?? '') === 'pdf',
    'through the fake OCR, a scanned letter is the assistant\'s: one turn, labelled "PDF, 1 page read of 1"', j($tpg));
$tto = $c['t_ocr_timeout'];
is_($tto['row']['status'] === 'failed' && $tto['row']['reason'] === 'timeout' && ($tto['event']['status'] ?? '') === 'failed' && (int)($tto['event']['attempts'] ?? 0) === 1 && $tto['state'] !== 'needs_human' && $tto['replies_added'] === 0 && $tto['error_has_jid'] === false,
    'an OCR timeout is retryable: row failed/timeout, the event failed with one attempt, not handed over yet, no JID in the error', j($tto));
$ttr = $c['t_ocr_retry'];
is_($ttr['row']['status'] === 'understood' && $ttr['row']['attempts'] === 2 && ($ttr['event']['status'] ?? '') === 'done' && $ttr['fetches_added'] === 1 && $ttr['replies_added'] === 1, 'the due retry fetches again, reads, and queues the one event', j($ttr));
is_($c['t_pdf_encrypted']['row']['reason'] === 'password_protected' && $c['t_pdf_encrypted']['ocr_calls_added'] === 0 && $c['t_pdf_encrypted']['replies_added'] === 0 && $c['t_pdf_encrypted']['state'] === 'needs_human',
    'an encrypted PDF: password_protected before any provider, a person, no password asked', j($c['t_pdf_encrypted']));

echo "\n12. Refusals: encrypted, legacy, macro-enabled, malformed, the wrong type, archive bombs, too large, too slow, unavailable\n";
$ref = $c['t_refusals'];
$expect = ['zip_encrypted' => 'password_protected', 'ole2' => 'unsupported_document', 'ole2_encrypted' => 'password_protected', 'docm' => 'unsupported_document', 'pdf_junk' => 'malformed_document',
           'docx_with_vba' => 'unsupported_document',
           'junk' => 'malformed_document', 'png_behind_pdf' => 'unsupported_mime', 'doctype' => 'malformed_document', 'pptx' => 'unsupported_document', 'bomb_declared' => 'too_large_document',
           'bomb_lying' => 'malformed_document', 'bomb_true' => 'too_large_document', 'too_large_document' => 'too_large_document', 'too_large_fetch' => 'too_large', 'too_slow' => 'too_slow', 'extractor_unavailable' => 'extractor_unavailable'];
foreach ($expect as $k => $reason) {
    is_(($ref[$k]['reason'] ?? null) === $reason && in_array($ref[$k]['status'] ?? '', ['failed', 'unsupported'], true) && ($ref[$k]['kind'] ?? null) === null,
        "{$k}: {$reason}, nothing understood", j($ref[$k] ?? null));
}
$trh = $c['t_refusal_handover'];
is_($trh['slow_state'] === 'needs_human' && $trh['unavailable_state'] === 'needs_human' && $trh['unavailable_reason'] === $trh['expected_unavailable'] && $trh['bomb3_size'] < 65536,
    'too slow and extractor unavailable are handed over; the 20 MiB bomb was a few KB on the wire', j($trh));
$tn = $c['t_nested'];
is_($tn['opened'] === ['word/document.xml'] && strpos($tn['payload'], 'malformed_document:member not on the allow-list') === 0 && strpos($tn['embedding'], 'malformed_document:member not on the allow-list') === 0 && $tn['names_has_payload'],
    'the archive reader opens only allow-listed members: a nested zip and an embedded object are refused by name', j($tn));

echo "\n13. The guard: a committing reply on a document turn is refused; conditional sentences and typed turns are not its business\n";
$tg = $c['t_guard'];
is_($tg['row']['status'] === 'understood' && $tg['audit_added'] === 1 && in_array('commitment:payment', (array)$tg['audit_categories'], true) && $tg['audit_conv'] === $tg['expected_conv'] && $tg['leaked'] === 0 && $tg['fallback'] === 1 && $tg['state'] === 'needs_human',
    '"we have received your payment and the invoice has been settled" on a document turn: blocked, the fallback sent, audited, a person takes over', j($tg));
is_($c['t_guard_typed']['audit_added'] === 0 && $c['t_guard_typed']['sent'] === 1, 'the conditional "once your payment is received" on a typed turn is sent as written', j($c['t_guard_typed']));
$tgd = $c['t_guard_direct'];
is_(in_array('commitment:payment', $tgd['doc_turn'], true) && !in_array('commitment:payment', $tgd['typed_turn'], true) && $tgd['conditional'] === [] && in_array('commitment:contract', $tgd['contract'], true) && in_array('commitment:identity', $tgd['identity'], true),
    'the commitment phrases: payment, contract and identity commitments are refused only where the caller asks; the conditional form passes', j($tgd));

echo "\n14. STOP is the webhook's — an extract reading \"stop\" is not an opt-out\n";
is_($c['t10']['optouts_added'] === 0 && $c['t10']['row'] === 'understood', 'a text file reading "stop" is not an opt-out — the caption path is the webhook\'s, proved under C', j($c['t10']));

echo "\n15. D-11: the wrapped shapes are stored and recorded\n";
$d = $c['t_d11'];
is_(($d['inbound']['plain']['caption'] ?? null) === 'plain' && ($d['inbound']['w1']['caption'] ?? null) === 'here is the receipt' && ($d['inbound']['w2']['caption'] ?? null) === 'and again'
    && ($d['inbound']['w3']['kind'] ?? null) === 'document' && ($d['inbound']['w4']['kind'] ?? null) === 'document' && $d['inbound']['w5'] === null,
    'InboundMedia reads a document through documentWithCaptionMessage, ephemeral→documentWithCaption, viewOnce and viewOnceV2; four wrappers deep is refused', j($d['inbound']));
is_(($d['stored']['plain']['body'] ?? null) === 'plain' && ($d['stored']['w1']['body'] ?? null) === 'here is the receipt' && ($d['stored']['w1']['media_type'] ?? null) === 'document'
    && ($d['stored']['w2']['body'] ?? null) === 'and again' && ($d['stored']['w2']['media_type'] ?? null) === 'document' && ($d['stored']['w3']['body'] ?? null) === '[DOCUMENT]' && ($d['stored']['w4']['body'] ?? null) === '[DOCUMENT]' && $d['stored']['w5'] === null,
    'the conversation store keeps a wrapped captioned document with its caption and type — the message that was dropped before', j($d['stored']));
is_(($d['stored']['w6']['body'] ?? null) === 'a disappearing hello' && is_array($d['stored']['w6']) && array_key_exists('media_type', $d['stored']['w6']) && $d['stored']['w6']['media_type'] === null && $d['unwrap_identity'],
    'a disappearing text message is stored too (a consequence of D-11, recorded); an unwrapped message is untouched', j([$d['stored']['w6'], $d['unwrap_identity']]));

echo "\n16. ai_media_document OFF: fetched, nothing more\n";
$t16 = $c['t16'];
is_($t16['row']['status'] === 'fetched' && $t16['replies_added'] === 0 && $t16['escalations_added'] === 0 && $t16['state'] !== 'needs_human' && $t16['msg_body'] === 'look at this',
    'with the document flag off a document is fetched (Batch 1) and nothing else happens; the caption stays the stored text', j($t16));

echo "\n17. ai_media_enabled OFF overrides\n";
$tp17 = $c['t17_policy'];
is_($tp17['document_alone'] === false && $tp17['both'] === true && $tp17['media_alone'] === false && $tp17['none'] === false, 'documentEnabled() needs both flags', j($tp17));
is_($tp17['max_bytes_capped_by_fetch'] === 131072 && $tp17['max_bytes_default'] === 10485760 && $tp17['pages_default'] === 20 && $tp17['timeout_default'] === 20, 'the document cap never exceeds the fetch cap; the defaults are 10 MiB, 20 pages, 20 s', j($tp17));
is_($c['t17']['row']['status'] === 'skipped' && $c['t17']['row']['reason'] === 'media_disabled' && $c['t17']['replies_added'] === 0 && $c['t17']['fetches_added'] === 0, 'media off, document on: skipped, nothing fetched, nothing read, nothing queued', j($c['t17']));

echo "\n18. Nothing on disk, nothing in any log; the extract lives only where a typed message would\n";
is_($c['t14']['disk'] === [] && $c['t14']['docx_bytes_on_disk'] === [], 'no file under the data directory holds a document, its base64, its hash, an identity or a credential', j($c['t14']));
$where = array_values(array_unique(array_map(function ($h) { return explode(' has ', $h)[0]; }, $c['t14']['db_general_extract_where'])));
is_(array_diff($where, ['wa_media', 'wa_messages', 'events']) === [] && in_array('wa_media', $where, true) && in_array('events', $where, true), 'the general extract is in wa_media, the stored message and the ai.reply payload — and nowhere else', j($where));
$t15 = $c['t15'];
is_($t15['log_has_b64'] === false && $t15['log_has_extract'] === false && $t15['log_has_payment_details'] === false && $t15['log_has_identity'] === false && $t15['log_has_credential'] === false && $t15['log_has_jid'] === false && $t15['fake_saw_content'] === 0,
    'the worker logs carry no base64, no extract, no amount, account or reference, no identity, no credential, no JID; the fake OCR recorded hash prefixes and sizes only', j($t15));

echo "\nC. The CLI runner in the real plugin tree\n";
$w = vd_cli($root);
$c1 = $w['c1'];
is_(($c1['webhook']['queued'] ?? -1) === 0 && ($c1['webhook']['media_queued'] ?? 0) === 1 && $c1['out'] === '' && $c1['row']['status'] === 'understood' && $c1['row']['kind'] === 'extraction',
    'a captioned document with the flag on: the webhook queues NO text turn, records the document; the runner reads it', j([$c1['webhook'], $c1['out'], $c1['row']]));
is_($c1['replies'] === 1 && $c1['event_by'] === 'media_worker' && $c1['origin'] === 'document' && $c1['class'] === 'general' && $c1['has_caption'] === true && $c1['phone'] === '256772000911' && $c1['message_labelled'] && $c1['message_has_caption'] && $c1['message_has_filename'] === false && $c1['msg_is_message'],
    'ONE event carries the extract and the caption under their labels, never the file name — the customer is answered once', j($c1));
is_($c1['ocr_log_lines'] === 0 && $c1['log_has_extract'] === false && $c1['log_has_b64'] === false && $c1['log_read'] && $c1['texts'] === 0, 'no OCR call for a Word file; ai_platform.log holds no extract and no base64; nothing was sent', j([$c1['ocr_log_lines'], $c1['log_read'], $c1['texts']]));
$c2 = $w['c2'];
is_(($c2['webhook']['queued'] ?? -1) === 0 && ($c2['webhook']['media_queued'] ?? 0) === 1 && ($c2['msg']['body'] ?? null) === 'stop' && ($c2['msg']['media_type'] ?? null) === 'document' && $c2['optouts'] === 1 && ($c2['optout']['phone'] ?? '') === '256772000912' && ($c2['optout']['source'] ?? '') === 'keyword' && ($c2['optout']['evidence'] ?? '') === 'stop'
    && $c2['row']['status'] === 'understood' && $c2['replies'] === 2 && $c2['last_message_has_caption'],
    'a WRAPPED captioned document saying "stop": stored with its caption (D-11), the opt-out recorded exactly as for typed text, the document still makes its turn with the caption', j($c2));
$c3 = $w['c3'];
is_($c3['row']['kind'] === 'document_evidence' && $c3['replies'] === 2 && $c3['state'] === 'needs_human' && $c3['holding'] === 1 && $c3['alert'] === 1 && $c3['alert_has_amount'] === 0 && $c3['msg_body_prefix'] && $c3['ucrm_money_writes'] === 0 && $c3['ai_log_has_amount'] === false,
    'a receipt through the real tree: evidence, hand-over, no event, no uCRM payment or invoice write, no amount in the alert or the log', j($c3));
$c4 = $w['c4'];
is_($c4['webhook_queued'] === 1 && $c4['media_queued'] === 1 && $c4['row']['status'] === 'fetched' && $c4['replies'] === 3 && $c4['last_by'] === 'evo_webhook' && $c4['ocr_log_lines'] === 0,
    'document OFF: the caption is answered as text by the webhook as always; the file is fetched only', j($c4));
$c5 = $w['c5'];
is_($c5['webhook_queued'] === 1 && $c5['media_queued'] === 0 && $c5['row'] === [] && ($c5['msg']['body'] ?? null) === 'wrapped, media off' && ($c5['msg']['media_type'] ?? null) === 'document' && $c5['replies'] === 4 && $c5['last_by'] === 'evo_webhook' && $c5['last_message'] === 'wrapped, media off',
    'media OFF: a wrapped captioned document is still STORED and its caption answered as text (D-11 is flag-independent); nothing recorded for the worker', j($c5));
$c6 = $w['c6'];
is_($c6['row']['status'] === 'failed' && $c6['row']['reason'] === 'provider_missing' && $c6['state'] === 'needs_human' && $c6['holding'] === 1 && $c6['alert'] === 1 && $c6['ocr_log_lines'] === 0 && $c6['replies'] === 4,
    'a scanned PDF with provider "fake" and no test environment fails closed: provider_missing, a person, the holding line, no event', j($c6));
$c6o = $w['c6_ocr'];
is_($c6o['row']['status'] === 'understood' && $c6o['row']['kind'] === 'extraction' && $c6o['ocr_log_lines'] === 1 && strpos($c6o['ocr_log'], '"sha":"') !== false && strpos($c6o['ocr_log'], 'school') === false && $c6o['replies'] === 5 && $c6o['class'] === 'general' && $c6o['kind'] === 'pdf' && $c6o['event_by'] === 'media_worker',
    'with the test environment the fake reads the scan: one hash-prefix line in its log, one turn, class general, kind pdf', j($c6o));
$c7 = $w['c7'];
is_(($c7['webhook']['queued'] ?? -1) === 0 && ($c7['webhook']['media_queued'] ?? 0) === 1 && ($c7['msg']['body'] ?? null) === 'nested' && ($c7['msg']['media_type'] ?? null) === 'document' && $c7['row']['status'] === 'pending',
    'ephemeral → documentWithCaption → document: stored with its caption and recorded for the worker', j($c7));
is_($w['disk'] === [], 'no file under the sandbox holds a document, its base64 or a transaction reference', j($w['disk']));
is_(($w['cs_jobs']['rc'] ?? 1) === 0 && $w['cs_jobs']['ai_media'] === true, 'cron_status lists the ai_media job while the media flag is on (unchanged since Batch 1)', j($w['cs_jobs']));
$c8 = $w['c8'];
is_(($c8['webhook']['media_queued'] ?? 0) === 1 && $c8['fatal'] === false && $c8['row']['status'] === 'failed' && $c8['row']['reason'] === 'classification_incomplete' && $c8['state'] === 'needs_human'
    && $c8['alert_incomplete'] === 1 && $c8['alert_budget'] === 0,
    'the runner started under a 64M CLI limit raises it to 256M: a 12 MiB text file passes the memory budget and is READ to the text cap (classification_incomplete, a person) — never refused for memory, never a fatal', j($c8));

echo "\nD. Wiring\n";
$mwCode = vd_codeOf($root . '/workers/MediaWorker.php'); $deCode = vd_codeOf($root . '/lib/DocumentExtraction.php'); $dcCode = vd_codeOf($root . '/lib/DocumentClassifier.php');
is_(strpos($mwCode, 'DishNetAiBrain') === false && strpos($mwCode, 'ReplyPrivacyGuard') === false && strpos($deCode, 'DishNetAiBrain') === false && strpos($deCode, 'ReplyPrivacyGuard') === false,
    'neither the media worker nor the extraction knows the brain or the guard: the extract joins the ai.reply queue and nothing else');
is_(strpos($dcCode, 'ReplyPrivacyGuard::secretShapesIn(') !== false && strpos($dcCode, 'ReplyPrivacyGuard::check(') === false, 'the classifier borrows the guard\'s secret SHAPES and nothing else of it');
is_(strpos($mwCode, 'ai_media_document') === false && strpos($mwCode, 'MediaPolicy::documentEnabled($this->config)') !== false, 'the worker reads the document flag through the policy only');
is_(strpos($deCode, 'DocumentClassifier::classify(') !== false && strpos($deCode, "if (\$cls['route'] === 'human' && \$class === null) {") !== false && strpos($deCode, "if (\$cls['route'] === 'human') {") !== false,
    'the classification is this plugin\'s, and both human routes are decided before any event is queued');
// Batch 5 (docs/60 §2): the caption's text turn is skipped only on the REPLY rung — in the dry-run and hand-over modes there is no
// document turn, so the caption must be answered as text. tests/test_document_activation.php proves the three lower modes answer it.
is_(strpos(vd_codeOf($root . '/evo_webhook.php'), "if (\$mediaEvent && (string)(\$media['kind'] ?? '') === 'document' && MediaPolicy::documentReplyEnabled(\$config)) {") !== false, 'the webhook skips the caption\'s text turn only for a recorded document with the document flag on');
$forbidden = ['tempnam(', 'tmpfile(', 'file_put_contents(', 'fopen(', 'exec(', 'shell_exec(', 'proc_open(', 'system(', 'passthru(', 'eval(', 'unserialize(', 'ZipArchive', 'LIBXML_NOENT', 'curl_'];
$readers = ['lib/DocumentExtraction.php', 'lib/DocumentClassifier.php', 'lib/DocumentSniffer.php', 'lib/OoxmlArchive.php', 'lib/DocxReader.php', 'lib/SheetReader.php', 'lib/TextReader.php', 'lib/PdfReader.php', 'lib/DocumentDeadline.php'];
$bad = [];
foreach ($readers as $rel) { $code = vd_codeOf($root . '/' . $rel); foreach ($forbidden as $fn) if (strpos($code, $fn) !== false) $bad[] = "$rel has $fn"; }
is_($bad === [], 'the readers write no file, run no process, evaluate nothing, open no socket, use no ZipArchive and never substitute entities', j($bad));
$dx = vd_codeOf($root . '/lib/DocxReader.php');
is_(strpos($dx, 'LIBXML_NONET') !== false && strpos($dx, 'XMLReader::SUBST_ENTITIES, false') !== false && strpos($dx, 'XMLReader::LOADDTD, false') !== false && strpos($dx, "'/<!DOCTYPE|<!ENTITY/i'") !== false,
    'XMLReader runs with LIBXML_NONET, no entity substitution, no DTD loading, and a DOCTYPE is refused before the parser sees it');
is_(is_file(dirname($root) . '/docs/58-document-processing-boundary-review-2026-10-05.md'), 'the document boundary, the fail-closed rule and the human-only classes are documented (docs/58) before any code');
is_(count(glob($root . '/migrations/08[6-9]_*.sql') ?: []) === 0, 'no migration: wa_media already carries understanding and understanding_kind');
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'ai_media_document' => ['bool',") !== false && strpos($sc, "'ai_media_document_max_bytes' => ['number',") !== false && strpos($sc, "'ai_media_document_max_pages' => ['number',") !== false && strpos($sc, "'ai_media_document_timeout_s' => ['number',") !== false && strpos($sc, "'ai_document_provider' => ['text',") !== false,
    'set_config.php manages the five document settings');
$scOut = shell_exec('php ' . escapeshellarg($root . '/tools/set_config.php') . ' --key ai_document_provider --value fake 2>&1; echo "rc=$?"');
is_(strpos((string)$scOut, 'none is the only value today (docs/58)') !== false && strpos((string)$scOut, 'rc=1') !== false, 'the tool refuses a document OCR provider that does not exist, the fake included', (string)$scOut);
$scOut2 = shell_exec('php ' . escapeshellarg($root . '/tools/set_config.php') . ' --key ai_media_document_max_pages --value 500 2>&1; echo "rc=$?"');
is_(strpos((string)$scOut2, 'between 1 and 200') !== false && strpos((string)$scOut2, 'rc=1') !== false, 'the tool refuses a page cap outside its range rather than clamping it', (string)$scOut2);
$ve = (string)file_get_contents($root . '/tests/validate_environment.php');
is_(strpos($ve, "'zlib' => 'gzinflate', 'xmlreader' => 'XMLReader', 'iconv' => 'iconv'") !== false, 'validate_environment reports the three optional extensions the readers need');
is_(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] === '5.18.81', 'manifest version is 5.18.81');

echo "\n19. Weakened copies — each caught by the scenario that guards it\n";
$mutants = [
    ['lib/MediaPolicy.php', "        return self::enabled(\$config) && self::flag(\$config['ai_media_document'] ?? null);", "        return self::flag(\$config['ai_media_document'] ?? null);",
     'ai_media_document alone turns documents on (media off no longer wins)', function (array $m): bool { return ($m['t17_policy']['document_alone'] ?? null) === true; }],
    ['workers/MediaWorker.php', "        return (string)(\$row['kind'] ?? '') === 'document' && MediaPolicy::documentEnabled(\$this->config);", "        return (string)(\$row['kind'] ?? '') === 'document';",
     'the worker ignores the document flag', function (array $m): bool { return ($m['t16']['replies_added'] ?? 0) >= 1 || ($m['t16']['row']['status'] ?? '') === 'understood'; }],
    ['lib/OoxmlArchive.php', "        if (\$e['usize'] > MediaPolicy::DOCUMENT_ZIP_MAX_MEMBER_BYTES) {", "        if (false) {",
     'the declared-size cap is gone (a 20 MiB member inflates)', function (array $m): bool { return ($m['t_refusals']['bomb_true']['reason'] ?? '') !== 'too_large_document'; }],
    ['lib/OoxmlArchive.php', "            \$data = \$e['usize'] === 0 ? '' : @gzinflate(\$raw, \$e['usize']);\n            if (\$data === false) throw new DocumentRefused('malformed_document', 'member ' . \$name . ' did not inflate within its declared size');",
     "            \$data = @gzinflate(\$raw);\n            if (\$data === false) throw new DocumentRefused('malformed_document', 'member ' . \$name . ' did not inflate');\n            \$e['usize'] = strlen(\$data);",
     'zlib\'s cap is gone and the member\'s real size is trusted (a lying member inflates)', function (array $m): bool { return ($m['t_refusals']['bomb_lying']['reason'] ?? '') !== 'malformed_document'; }],
    ['lib/OoxmlArchive.php', "        if (!self::allowed(\$name)) throw new DocumentRefused('malformed_document', 'member not on the allow-list');", "        // allow-list removed",
     'any member of the archive can be opened', function (array $m): bool { return strpos((string)($m['t_nested']['payload'] ?? ''), 'malformed_document') !== 0; }],
    ['lib/DocxReader.php', "        if (preg_match('/<!DOCTYPE|<!ENTITY/i', \$xml) === 1) throw new DocumentRefused('malformed_document', 'DTD in an XML part');", "        // DTD guard removed",
     'a DOCTYPE in a part is accepted', function (array $m): bool { return ($m['t_refusals']['doctype']['reason'] ?? '') !== 'malformed_document'; }],
    ['lib/DocumentSniffer.php', "            if (preg_match(self::CT_MACRO, \$ct) === 1) throw new DocumentRefused('unsupported_document', 'macro-enabled Office file');", "            // macro check removed",
     'a Word document carrying a vbaProject part is read', function (array $m): bool { return ($m['t_refusals']['docx_with_vba']['reason'] ?? '') !== 'unsupported_document'; }],
    ['lib/PdfReader.php', "        \$facts['encrypted'] = preg_match('/\\/Encrypt\\s*(?:\\d+\\s+\\d+\\s+R|<<)/', \$bytes) === 1;", "        \$facts['encrypted'] = false;",
     'an encrypted PDF is not recognised as such', function (array $m): bool { return ($m['t_pdf_encrypted']['row']['reason'] ?? '') !== 'password_protected'; }],
    ['lib/DocumentClassifier.php', "        if (PaymentEvidence::looksLikePayment('general', \$text, \$sigWords)) return ['class' => 'payment_proof', 'route' => 'human', 'reason' => 'class', 'hits' => ['payment:words']];",
     "        if (PaymentEvidence::looksLikePayment('general', \$text, \$sigWords)) return ['class' => 'general', 'route' => 'brain', 'reason' => 'class', 'hits' => ['payment:words']];",
     'payment evidence is routed to the assistant', function (array $m): bool { return ($m['t12']['replies_added'] ?? 0) >= 1 || ($m['t12_words']['replies_added'] ?? 0) >= 1; }],
    ['lib/DocumentClassifier.php', "        \$secret = ReplyPrivacyGuard::secretShapesIn(\$text);", "        \$secret = [];",
     'credentials are no longer detected (a password reaches the assistant)', function (array $m): bool { return ($m['t_credential']['replies_added'] ?? 0) >= 1 || ($m['t_credential']['row']['kind'] ?? '') !== 'document_evidence'; }],
    ['lib/DocumentClassifier.php', "        if (self::decided(\$hits['identity_document'])) return self::human('identity_document', \$hits['identity_document']);", "        // identity routing removed",
     'an identity document is no longer named as one (the KYC hand-over is lost)', function (array $m): bool { return ($m['t_identity']['esc_reason'] ?? '') !== ($m['t_identity']['expected_reason'] ?? 'x'); }],
    ['lib/DocumentExtraction.php', "        'credential' => 'none', 'identity_document' => 'none',", "        'credential' => 'excerpt', 'identity_document' => 'excerpt',",
     'the identity and credential records keep content', function (array $m): bool { return (string)($m['t_identity']['row']['text'] ?? '') !== '' || (string)($m['t_credential']['row']['text'] ?? '') !== ''; }],
    ['lib/DocumentExtraction.php', "        \$labelled = \$evidence ? self::evidenceLabel(\$class, \$understanding, \$caption) : self::label(\$kind, \$facts, !empty(\$r['truncated']), \$understanding, \$caption);",
     "        \$labelled = \$evidence ? self::evidenceLabel(\$class, \$understanding, \$caption) : self::label(\$kind, \$facts, !empty(\$r['truncated']), \$understanding . \"\\n\" . (string)(\$row['file_name'] ?? ''), \$caption);",
     'the file name travels to the assistant', function (array $m): bool { return ($m['t1']['message_has_filename'] ?? false) === true; }],
    ['lib/DocumentExtraction.php', "        \$out = self::LABEL . ' — ' . \$name . (\$bits !== [] ? ', ' . implode(', ', \$bits) : '') . ']' . \"\\n\" . \$text;", "        \$out = \$text;",
     'the extract is no longer labelled', function (array $m): bool { return ($m['t3']['ctx_message_labelled'] ?? true) === false; }],
    ['lib/DishNetAiBrain.php', "        \$document = \$ctx['document'] ?? null;\n        if (is_array(\$document)) {", "        \$document = \$ctx['document'] ?? null;\n        if (false) {",
     'the prompt no longer says the message is an extract', function (array $m): bool { return ($m['t3']['prompt_block'] ?? true) === false; }],
    ['lib/DocumentExtraction.php', "WHERE id = ? AND status <> 'understood'\");", "WHERE id = ?\");",
     'the understood guard is gone (a duplicate event queues a second turn)', function (array $m): bool { return ($m['t4']['replies_added'] ?? 1) >= 2 || ($m['t4']['direct'] ?? '') !== 'already_understood'; }],
    ['workers/MediaWorker.php', "        \$this->handover(\$row, DocumentExtraction::handoverReason('failed', \$reason, \$detail));", "        // handover removed",
     'a permanent failure no longer hands over', function (array $m): bool { return ($m['t_uncertain']['state'] ?? '') !== 'needs_human'; }],
    ['lib/DocumentExtraction.php', "            // The one ai.reply event: the shape evo_webhook.php queues for a typed message, plus where it came from.", "            (\$this->log)('info', 'extract: ' . \$understanding);",
     'the extract is logged', function (array $m): bool { return ($m['t15']['log_has_extract'] ?? false) === true; }],
    ['lib/DocumentDeadline.php', "        if (microtime(true) >= \$this->until) throw new DocumentTooSlow(\$step);", "        // never",
     'the deadline is never enforced', function (array $m): bool { return ($m['t_refusals']['too_slow']['reason'] ?? '') !== 'too_slow'; }],
    ['lib/ReplyPrivacyGuard.php', "        if (!empty(\$permitted['commitments'])) {", "        if (false) {",
     'the commitment phrases are gone (a payment confirmation from a document is sent)', function (array $m): bool { return ($m['t_guard']['leaked'] ?? 0) >= 1; }],
    ['lib/DocumentClassifier.php', "        if (\$weak !== []) return ['class' => null, 'route' => 'human', 'reason' => 'classification_uncertain', 'hits' => array_values(array_unique(\$weak))];", "        if (false) return [];",
     'uncertain → general → AI', function (array $m): bool { return ($m['t_uncertain']['replies_added'] ?? 0) >= 1; }],
    ['lib/DocumentClassifier.php', "        if (!\$sawEverything) return ['class' => null, 'route' => 'human', 'reason' => 'classification_incomplete', 'hits' => ['scan:incomplete']];", "        if (false) return [];",
     'text the readers could not finish is called harmless', function (array $m): bool { return ($m['t_incomplete']['replies_added'] ?? 0) >= 1; }],
    ['lib/ConversationService.php', "            \$message = \\InboundMedia::unwrap(\$message);", "            \$message = \$message;",
     'the D-11 fix is gone (a wrapped captioned document is dropped again)', function (array $m): bool { return isset($m['t_d11']) && !isset($m['t_d11']['stored']['w1']); }],
    ['lib/InboundMedia.php', "        for (\$depth = 0; \$depth < self::MAX_WRAP_DEPTH; \$depth++) {", "        for (\$depth = 0; \$depth < 1; \$depth++) {",
     'only one wrapper level is unwrapped again', function (array $m): bool { return isset($m['t_d11']) && !isset($m['t_d11']['inbound']['w2']); }],
    ['run_media_worker.php', "if (\$mediaMemoryLimit > 0 && \$mediaMemoryLimit < 256 * 1024 * 1024) @ini_set('memory_limit', '256M');", "// the raise removed",
     'the runner no longer raises a low CLI memory limit (a 12 MiB file is refused for memory under 64M)', function (array $m): bool { return ($m['c8']['row']['reason'] ?? '') === 'too_large_document' || ($m['c8']['alert_budget'] ?? 0) >= 1; }, 'cli'],
];
$keysOfInterest = ['t17_policy' => 1, 't16' => 1, 't_refusals' => 1, 't_nested' => 1, 't_pdf_encrypted' => 1, 't_pdf_text' => 1, 't_pdf_no_text' => 1, 'c8' => 1, 't12' => 1, 't12_words' => 1, 't_credential' => 1, 't_identity' => 1, 't1' => 1, 't3' => 1, 't4' => 1, 't_uncertain' => 1, 't15' => 1, 't_guard' => 1, 't_incomplete' => 1, 't_d11' => 1];
foreach ($mutants as $mut) {
    [$rel, $old, $new, $what, $flipped] = $mut;
    $drv = $mut[5] ?? 'core';   // a sixth element names the driver whose facts the detector reads: 'cli' for the real runner
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "copy — the anchor in {$rel} is unique, so the copy is weakened ({$what})", 'occurrences: ' . $n);
    $out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($copy . '/tests/test_document_media.php') . ' --driver=' . $drv . ' 2>/dev/null');
    $m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
    is_($n === 1 && $m !== [] && $flipped($m), "caught — {$what}", $m === [] ? 'driver output: ' . substr($out, 0, 400) : j(array_intersect_key($m, $keysOfInterest)));
    exec('rm -rf ' . escapeshellarg($copy));
}
$out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($root . '/tests/test_document_media.php') . ' --driver=core 2>/dev/null');
$m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
$outCli = (string)shell_exec('php ' . escapeshellarg($root . '/tests/test_document_media.php') . ' --driver=cli 2>/dev/null');
$mCli = json_decode((string)strrchr("\n" . trim($outCli), "\n"), true) ?: [];
$flips = 0; foreach ($mutants as $mut) { $facts = ($mut[5] ?? 'core') === 'cli' ? $mCli : $m; if ($facts !== [] && $mut[4]($facts)) $flips++; }
is_($m !== [] && $mCli !== [] && $flips === 0, 'control: the real tree, driven the same way (core and cli), trips none of the ' . count($mutants) . ' catches', j(['facts' => count($m), 'cli_facts' => count($mCli), 'flips' => $flips]));

foreach ([$evoSrv, $ucrmSrv] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
