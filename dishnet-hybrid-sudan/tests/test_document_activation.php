<?php
declare(strict_types=1);
/**
 * test_document_activation.php — Batch 5 of the AI communication layer (docs/60): DOCUMENT ACTIVATION SAFETY.
 *
 * The document LADDER (MediaPolicy::documentMode): ai_media_enabled fetches; ai_media_document alone is the DRY RUN — a
 * document is extracted, classified and RECORDED on the wa_media row and the stored message's metadata, and nothing else
 * happens: nobody is told, no AI turn is queued, the stored body the model reads as history is untouched, a caption is
 * answered as text by the webhook as it always was; ai_media_document_handover adds the person (a human-only class, a refusal,
 * a failed fetch, a queue that gave up); ai_media_document_reply adds the assistant's turn for a harmless document and the
 * caption-once rule (evo_webhook.php 9b). Each rung needs every rung below it. Plus the lost-worker guard (a row claimed as
 * many times as its event allows is settled dead instead of being fetched for ever), the counters tool (tools/media_status.php,
 * read-only, codes and counts only) and the privacy of every line written. The REPLY rung's behaviour is proved by
 * tests/test_document_media.php and tests/test_document_pdf.php, whose configurations climb the whole ladder.
 *
 * Two scenarios return FACTS: `core`, in-process against the fake Evolution and the fake uCRM; `cli`, the real plugin under
 * php -S (SjSandbox) with run_media_worker.php over the CLI and the real webhook. Driver mode:
 * php tests/test_document_activation.php --driver=core|cli (env DN_T_EVO_PORT, DN_T_UCRM_PORT).
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

/** A brain that never leaves the process and parses its canned answer with the real marker parser. */
class VaFakeBrain extends DishNetAiBrain
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

const VA_MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const VA_GENERAL   = ['We are a school in Gulu with three buildings.', 'Can one dish connect all of them?', 'The buildings are 40 metres apart and the main block has an iron roof.'];
const VA_RECEIPT   = ['MTN Mobile Money', 'You have sent UGX 150,000 to DishNet Africa.', 'Transaction ID 7G4K2Q9', 'Status: successful', 'Balance UGX 23,400'];
const VA_INVOICE   = ['TAX INVOICE No. INV-2026-0042', 'Bill to: Example Trading Ltd', 'Amount due: UGX 1,690,000', 'Due date: 30 October 2026', 'Account 0012345678'];
const VA_IDENTITY  = ['REPUBLIC OF UGANDA', 'NATIONAL IDENTIFICATION CARD', 'Surname: TESTSURNAME', 'Given names: TEST PERSON', 'Date of birth: 01.01.1990', 'NIN: CF00000000TEST'];
const VA_CREDENTIAL = "Router admin login\nusername: admin\npassword: hunter2xyz99\naws key AKIAABCDEFGHIJKLMNOP\n";
const VA_QUOTATION = ['QUOTATION No. Q-2026-19', 'Standard kit, qty 1, unit price 1,690,000', 'Valid until 30 October 2026'];
/** What must never appear in a log line, a counter or the tool's output. */
const VA_NEEDLES = ['school in Gulu', '150,000', '7G4K2Q9', 'TESTSURNAME', 'hunter2xyz99', 'AKIAABCDEFGHIJKLMNOP', 'INV-2026-0042', 'Q-2026-19',
                    '256772001', 'plan.docx', 'momo.docx', 'I have paid', 'site plan', '@s.whatsapp.net'];

/** The four flags → the mode and the three predicates, as a pure function of configuration. */
function va_matrix(): array
{
    $out = [];
    foreach ([0, 1] as $m) foreach ([0, 1] as $d) foreach ([0, 1] as $h) foreach ([0, 1] as $r) {
        $cfg = ['ai_media_enabled' => (string)$m, 'ai_media_document' => (string)$d, 'ai_media_document_handover' => (string)$h, 'ai_media_document_reply' => (string)$r];
        $out["{$m}{$d}{$h}{$r}"] = ['mode' => MediaPolicy::documentMode($cfg), 'document' => MediaPolicy::documentEnabled($cfg),
                                   'handover' => MediaPolicy::documentHandoverEnabled($cfg), 'reply' => MediaPolicy::documentReplyEnabled($cfg)];
    }
    return $out;
}

// ── Scenario 1: core, in-process ──────────────────────────────────────────────────────────────────────
function va_core(string $root, int $evoPort, int $ucrmPort): array
{
    $tmp = sys_get_temp_dir() . '/dn_va_' . bin2hex(random_bytes(4));
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
    $ALERT = '256700000999';
    // The base configuration is the DRY RUN: the two lower rungs on, the two upper rungs off. Probes override per case.
    $base = ['evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'TESTKEY', 'evo_instance_sales' => 'dishnet_ug', 'evo_instance_support' => 'dishnet_ug',
             'ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_document' => '1', 'ai_media_document_handover' => '0', 'ai_media_document_reply' => '0',
             'ai_media_max_bytes' => 131072, 'ai_media_document_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'ai_media_document_timeout_s' => 5,
             'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
             'crm_base_url' => "http://127.0.0.1:{$ucrmPort}", 'crm_auth_token' => 'test-key',
             'alert_whatsapp' => $ALERT, 'ai_handover_message' => $HOLD, 'wa_human_cooldown_minutes' => 30, 'data_dir' => $tmp];
    $HANDOVER = ['ai_media_document_handover' => '1'];
    $REPLY    = ['ai_media_document_handover' => '1', 'ai_media_document_reply' => '1'];
    $f = ['tmp' => $tmp];
    $logAll = '';
    $texts   = function () use ($evoPort): array { return vd_state($evoPort)['text_calls'] ?? []; };
    $fetches = function () use ($evoPort): int { return count(vd_state($evoPort)['media_fetch_calls'] ?? []); };
    $serve = function (string $waId, string $bytes, string $mime = VA_MIME_DOCX, string $fileName = 'plan.docx') use ($evoPort): void {
        vd_hit($evoPort, '/__test/media', ['for_id' => $waId, 'base64' => base64_encode($bytes), 'mimetype' => $mime, 'fileName' => $fileName]);
    };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $workerReplies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply' AND created_by = 'media_worker'"); };
    $escs = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'"); };
    $lastEsc = function () use ($pdo): array {
        $r = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'wa.escalation' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['by' => $r['created_by'] ?? null, 'reason' => (string)((json_decode((string)($r['payload'] ?? ''), true) ?: [])['reason'] ?? '')];
    };
    $conv  = function (string $phone) use ($svc): int { return (int)$svc->ensureConversation($phone, 'sales', 'Document Tester', 'test')['id']; };
    $state = function (int $cid) use ($svc): string { return (string)($svc->getConversation($cid)['state'] ?? ''); };
    $body  = function (int $msgId) use ($pdo): string { return (string)$pdo->query("SELECT body FROM wa_messages WHERE id = {$msgId}")->fetchColumn(); };
    $meta  = function (int $msgId) use ($pdo): ?array { return (json_decode((string)$pdo->query("SELECT metadata FROM wa_messages WHERE id = {$msgId}")->fetchColumn(), true) ?: [])['document'] ?? null; };
    $holdingTo = function (string $phone) use ($texts, $HOLD): int { return count(array_filter($texts(), function ($c) use ($phone, $HOLD) { return ($c['number'] ?? '') === $phone && trim((string)$c['text']) === $HOLD; })); };
    $alerts = function () use ($texts, $ALERT): array { return array_values(array_map(function ($c) { return (string)$c['text']; }, array_filter($texts(), function ($c) use ($ALERT) { return ($c['number'] ?? '') === $ALERT; }))); };
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
    $mkMedia = function (array $ov = []) use ($store, $base): MediaWorker { $w = new MediaWorker($store, array_merge($base, $ov), 30, 10); $w->useDocumentOcr(null); return $w; };
    $run = function ($worker) use (&$logAll) { ob_start(); try { $r = $worker->run(); } finally { $out = (string)ob_get_clean(); $logAll .= $out; } return ['r' => $r, 'log' => $out]; };
    $mkReply = function (string $canned, array $ov = []) use ($store, $base): array {
        $w = new AiReplyWorker($store, array_merge($base, $ov), 30, 10);
        $b = new VaFakeBrain(array_merge($base, $ov)); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        return [$w, $b];
    };
    $eventStatus = function (int $id) use ($pdo): string { return (string)$pdo->query("SELECT status FROM events WHERE id = {$id}")->fetchColumn(); };
    /** One document through the worker under a configuration: the facts every mode and every outcome shares. */
    $probe = function (string $waId, string $phone, ?string $bytes, array $o = []) use ($docm, $serve, $run, $mkMedia, $replies, $workerReplies, $escs, $state, $holdingTo, $alerts, $lastEsc, $body, $meta, $pdo, $fetches, $eventStatus): array {
        $n = $docm($waId, $phone, (string)($o['caption'] ?? ''), (array)($o['env'] ?? []));
        if ($bytes !== null) $serve($waId, $bytes, (string)($o['mime'] ?? VA_MIME_DOCX), (string)($o['fileName'] ?? 'plan.docx'));
        if (isset($o['attempts'])) $pdo->prepare("UPDATE wa_media SET attempts = ?, status = 'extracting' WHERE id = ?")->execute([(int)$o['attempts'], $n['media_id']]);
        $rc = $replies(); $wc = $workerReplies(); $ec = $escs(); $a0 = count($alerts()); $f0 = $fetches(); $h0 = $holdingTo($phone);
        $r = $run($mkMedia((array)($o['cfg'] ?? [])));
        $row = vd_row($pdo, $waId);
        return ['n' => $n, 'run' => $r['r'], 'log' => $r['log'], 'row' => vd_brief($row), 'row_reason' => (string)($row['failure_reason'] ?? ''),
                'replies_added' => $replies() - $rc, 'worker_replies_added' => $workerReplies() - $wc, 'escalations_added' => $escs() - $ec, 'fetches_added' => $fetches() - $f0,
                'state' => $state($n['cid']), 'holding_added' => $holdingTo($phone) - $h0, 'alerts_added' => array_slice($alerts(), $a0), 'esc' => $lastEsc(),
                'msg_body' => $body($n['msg_id']), 'meta' => $meta($n['msg_id']), 'event_status' => $eventStatus((int)$n['event']),
                'outcome_line' => preg_match('/document outcome=(\w+) kind=(\S+) class=(\S+) reason=(\S+) mode=(\w+) ms=(\d+) attempts=(\d+)/', $r['log'], $m) === 1
                                  ? ['outcome' => $m[1], 'kind' => $m[2], 'class' => $m[3], 'reason' => $m[4], 'mode' => $m[5], 'ms' => (int)$m[6], 'attempts' => (int)$m[7]] : null];
    };

    $f['matrix'] = va_matrix();

    // ── D. The DRY RUN: recorded, nobody told, nothing answered, the body untouched ──────────────────────────
    // The conversation opens with a typed greeting the assistant answers, so a later typed turn has a history to show.
    $cidG = $conv('256772001001');
    $svc->storeMessage($cidG, ['direction' => 'in', 'role' => 'customer', 'body' => 'Hello', 'wa_message_id' => 'VA-0-TEXT']);
    $bus->emit('ai.reply', 'conversation', $cidG, ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772001001', 'message' => 'Hello',
        'push_name' => 'Document Tester', 'wa_message_id' => 'VA-0-TEXT', 'remote_jid' => '256772001001@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    [$rw0] = $mkReply('Hello! How can I help you today?'); $run($rw0);
    $generalDocx = vd_docx(VA_GENERAL);
    $f['d_general'] = $probe('VA-D-GEN', '256772001001', $generalDocx, ['fileName' => 'plan.docx', 'env' => ['fileName' => 'plan.docx']]);
    // A later typed turn: the model's history must show the placeholder and never the extract.
    $svc->storeMessage($cidG, ['direction' => 'in', 'role' => 'customer', 'body' => 'It is in Gulu town', 'wa_message_id' => 'VA-D-TEXT']);
    $bus->emit('ai.reply', 'conversation', $cidG, ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772001001', 'message' => 'It is in Gulu town',
        'push_name' => 'Document Tester', 'wa_message_id' => 'VA-D-TEXT', 'remote_jid' => '256772001001@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    [$rw1, $brain1] = $mkReply('Thank you. A colleague will confirm coverage for Gulu.'); $run($rw1);
    $hist = (array)($brain1->lastContext['history'] ?? []);
    $histText = implode("\n", array_map(function ($h) { return (string)($h['text'] ?? ''); }, $hist));
    $f['d_general_hist'] = ['turns' => count($hist), 'has_placeholder' => strpos($histText, '[DOCUMENT]') !== false, 'has_extract' => strpos($histText, 'school in Gulu') !== false,
                            'has_label' => strpos($histText, DocumentExtraction::LABEL) !== false, 'ctx_has_document' => array_key_exists('document', $brain1->lastContext ?? []),
                            'ctx_json_has_extract' => strpos(json_encode($brain1->lastContext ?? []), 'school in Gulu') !== false, 'brain_calls' => $brain1->calls];
    $f['d_receipt']    = $probe('VA-D-PAY', '256772001002', vd_docx(VA_RECEIPT), ['caption' => 'I have paid', 'fileName' => 'momo.docx', 'env' => ['fileName' => 'momo.docx']]);
    $f['d_identity']   = $probe('VA-D-ID', '256772001003', vd_docx(VA_IDENTITY));
    $f['d_credential'] = $probe('VA-D-CRED', '256772001004', vd_docx([VA_CREDENTIAL]));
    $f['d_encrypted']  = $probe('VA-D-ENC', '256772001005', vd_pdf('encrypted'), ['mime' => 'application/pdf', 'env' => ['mimetype' => 'application/pdf']]);
    $f['d_scanned']    = $probe('VA-D-SCAN', '256772001006', vd_pdf('scanned'), ['mime' => 'application/pdf', 'env' => ['mimetype' => 'application/pdf']]);
    $f['d_fetchfail']  = $probe('VA-D-404', '256772001007', null);   // nothing served: the fake answers 404 → fetch_failed, permanent

    // ── H. The HAND-OVER mode: a person for the human-only classes and the refusals; the harmless document only recorded ─
    $f['h_general']   = $probe('VA-H-GEN', '256772001011', $generalDocx, ['cfg' => $HANDOVER, 'caption' => 'Here is our site plan']);
    $f['h_receipt']   = $probe('VA-H-PAY', '256772001012', vd_docx(VA_RECEIPT), ['cfg' => $HANDOVER, 'caption' => 'I have paid']);
    $f['h_encrypted'] = $probe('VA-H-ENC', '256772001013', vd_pdf('encrypted'), ['cfg' => $HANDOVER, 'mime' => 'application/pdf', 'env' => ['mimetype' => 'application/pdf']]);
    $f['h_fetchfail'] = $probe('VA-H-404', '256772001014', null, ['cfg' => $HANDOVER]);

    // ── R. The REPLY mode, as Slices 4a/4b built it (test_document_media proves it in depth) ────────────────────
    $f['r_general'] = $probe('VA-R-GEN', '256772001021', $generalDocx, ['cfg' => $REPLY, 'caption' => 'Here is our site plan']);
    $f['r_receipt'] = $probe('VA-R-PAY', '256772001022', vd_docx(VA_RECEIPT), ['cfg' => $REPLY, 'caption' => 'I have paid']);

    // ── B. Bypass attempts: a higher flag without the one below it ────────────────────────────────────────────
    $f['bypass'] = [
        'reply_without_handover'    => $probe('VA-B-1', '256772001031', $generalDocx, ['cfg' => ['ai_media_document_reply' => '1']]),
        'handover_without_document' => $probe('VA-B-2', '256772001032', vd_docx(VA_RECEIPT), ['cfg' => ['ai_media_document' => '0', 'ai_media_document_handover' => '1', 'ai_media_document_reply' => '1']]),
        'all_without_media'         => $probe('VA-B-3', '256772001033', $generalDocx, ['cfg' => ['ai_media_enabled' => '0', 'ai_media_document_handover' => '1', 'ai_media_document_reply' => '1']]),
    ];

    // ── M. The human-only classes never make an AI turn, in any mode ──────────────────────────────────────────
    $classes = ['receipt' => vd_docx(VA_RECEIPT), 'invoice' => vd_docx(VA_INVOICE), 'identity' => vd_docx(VA_IDENTITY), 'credential' => vd_docx([VA_CREDENTIAL]), 'quotation' => vd_docx(VA_QUOTATION)];
    $modes = ['dry_run' => [], 'handover' => $HANDOVER, 'reply' => $REPLY];
    $i = 40; $hm = [];
    foreach ($modes as $mName => $cfg) {
        foreach ($classes as $cName => $bytes) {
            $i++;
            $t = $probe("VA-M-{$mName}-{$cName}", '2567720010' . $i, $bytes, ['cfg' => $cfg]);
            $hm["{$mName}/{$cName}"] = ['status' => $t['row']['status'], 'kind' => $t['row']['kind'], 'replies_added' => $t['replies_added'], 'worker_replies_added' => $t['worker_replies_added'],
                                       'escalations_added' => $t['escalations_added'], 'mode' => $t['meta']['mode'] ?? null, 'class' => $t['meta']['classification'] ?? null,
                                       'body_rewritten' => $t['meta']['body_rewritten'] ?? null];
        }
    }
    $f['human_matrix'] = $hm;
    $f['worker_replies_total'] = $workerReplies();

    // ── Q. The lost-worker guard: a row claimed as often as its event allows is settled dead, never fetched again ──
    $f['retry'] = [
        'handover' => $probe('VA-Q-H', '256772001071', $generalDocx, ['cfg' => $HANDOVER, 'attempts' => 5]),
        'dry'      => $probe('VA-Q-D', '256772001072', $generalDocx, ['attempts' => 5]),
        'control'  => $probe('VA-Q-C', '256772001073', $generalDocx, ['cfg' => $HANDOVER, 'attempts' => 4]),   // one claim still allowed: fetched and read
    ];
    // The shared queue's own semantics, pinned as they are (Batch 5 changes none of them): a claim counts no attempt, a
    // reported failure counts one, a stale lock released counts none, the fifth reported failure is dead.
    $qid = $bus->emit('batch5.probe', 'conversation', 1, ['x' => 1], 5, 'test');
    $attemptsOf = function (int $id) use ($pdo): array { $r = $pdo->query("SELECT status, attempts, error FROM events WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC) ?: []; return ['status' => $r['status'] ?? null, 'attempts' => (int)($r['attempts'] ?? -1), 'error' => (string)($r['error'] ?? '')]; };
    $bus->consume(5, 'va-probe', ['batch5.probe']);
    $afterClaim = $attemptsOf($qid);
    $pdo->prepare("UPDATE events SET locked_at = datetime('now', '-20 minutes') WHERE id = ?")->execute([$qid]);
    $bus->consume(5, 'va-probe-2', ['batch5.probe']);   // releaseStale() runs first, then the claim
    $afterStale = $attemptsOf($qid);
    $dead = false; $failsToDead = 0;
    for ($k = 0; $k < 6 && !$dead; $k++) { $dead = $bus->fail($qid, 'probe failure'); $failsToDead++; }
    $f['bus'] = ['after_claim' => $afterClaim, 'after_stale' => $afterStale, 'fails_to_dead' => $failsToDead, 'final' => $attemptsOf($qid)];

    // ── Z. Default-off and the bottom rung ──────────────────────────────────────────────────────────────────
    $f['defaults'] = ['no_keys' => MediaPolicy::documentMode(['ai_enabled' => '1']), 'reply_alone' => MediaPolicy::documentMode(['ai_media_document_reply' => '1']),
                      'media_only' => $probe('VA-Z-1', '256772001081', $generalDocx, ['cfg' => ['ai_media_document' => '0']])];

    // ── P. Privacy: the log, and the counters tool against this very database ───────────────────────────────
    file_put_contents($tmp . '/kyc_config.json', json_encode(['ai_media_enabled' => '1', 'ai_media_document' => '1', 'ai_media_document_handover' => '1']));
    $vault = (string)(getenv('DN_VAULT_FILE') ?: ($tmp . '/vault.json'));
    $toolCmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' DN_VAULT_FILE=' . escapeshellarg($vault) . ' php ' . escapeshellarg($root . '/tools/media_status.php') . ' --days 30';
    $toolText = (string)shell_exec($toolCmd . ' 2>&1; echo "RC=$?"');
    $toolJsonRaw = (string)shell_exec($toolCmd . ' --json 2>/dev/null');
    $toolJson = json_decode($toolJsonRaw, true) ?: [];
    $needles = function (string $hay): array { $hits = []; foreach (VA_NEEDLES as $nd) if (stripos($hay, $nd) !== false) $hits[] = $nd; if (strpos($hay, base64_encode(VA_GENERAL[0])) !== false) $hits[] = 'base64'; return $hits; };
    $f['privacy'] = ['log_hits' => $needles($logAll), 'log_has_outcome_lines' => preg_match_all('/document outcome=/', $logAll) ?: 0,
                     'tool_rc0' => strpos($toolText, 'RC=0') !== false, 'tool_text_hits' => $needles($toolText), 'tool_json_hits' => $needles($toolJsonRaw),
                     'tool_json' => $toolJson, 'tool_text_head' => substr($toolText, 0, 300),
                     'db_counts' => ['received' => $count("SELECT COUNT(*) FROM wa_messages WHERE direction = 'in' AND media_type = 'document'"),
                                     'recorded' => $count("SELECT COUNT(*) FROM wa_media WHERE kind = 'document'"), 'understood' => $count("SELECT COUNT(*) FROM wa_media WHERE kind = 'document' AND status = 'understood'"),
                                     'dead' => $count("SELECT COUNT(*) FROM wa_media WHERE kind = 'document' AND status = 'dead'"), 'worker_lost' => $count("SELECT COUNT(*) FROM wa_media WHERE failure_reason = 'worker_lost'"),
                                     'handovers' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'media_worker'"), 'worker_replies' => $workerReplies(),
                                     'encrypted' => $count("SELECT COUNT(*) FROM wa_media WHERE failure_reason = 'password_protected'"), 'fetch_failed' => $count("SELECT COUNT(*) FROM wa_media WHERE failure_reason = 'fetch_failed'")]];
    vd_hit($evoPort, '/__test/reset');
    return $f;
}

// ── Scenario 2: the real webhook and the CLI runner in the real plugin tree ───────────────────────────────────
function va_cli(string $root): array
{
    $HOLD = 'Let me get a colleague to help you with that.';
    $s = SjSandbox::start($root, ['ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_document' => '1', 'ai_media_document_handover' => '0', 'ai_media_document_reply' => '0',
        'tenant_profile' => 'uganda', 'ai_currency' => 'UGX', 'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
        'ai_media_max_bytes' => 131072, 'ai_media_document_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'ai_media_document_timeout_s' => 5,
        'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD], 'va');
    $pdo = $s->store()->getPdo();
    $f = [];
    $post  = function (array $env) use ($s): array { return $s->http('POST', "{$s->base}?page=evo_webhook", $env, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]); };
    $flag  = function (array $ov) use ($s): void { $cfg = array_merge($s->cfg, $ov); $s->store()->save('kyc_config.json', $cfg); file_put_contents($s->data . '/kyc_config.json', json_encode($cfg)); };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $lastReplyBy = function () use ($pdo): string { return (string)$pdo->query("SELECT created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetchColumn(); };
    $runner = function () use ($s): string { return (string)shell_exec('cd ' . escapeshellarg($s->plug) . ' && DN_DATA_DIR=' . escapeshellarg($s->data) . ' DN_VAULT_FILE=' . escapeshellarg($s->vault) . ' php run_media_worker.php 2>&1'); };
    $serve = function (string $waId, string $bytes, string $mime = VA_MIME_DOCX, string $fileName = 'plan.docx') use ($s): void {
        $s->http('POST', "{$s->evo}/__test/media", ['for_id' => $waId, 'base64' => base64_encode($bytes), 'mimetype' => $mime, 'fileName' => $fileName]);
    };
    $msg = function (string $waId) use ($pdo): array { return $pdo->query("SELECT body, metadata FROM wa_messages WHERE wa_message_id = '{$waId}'")->fetch(PDO::FETCH_ASSOC) ?: []; };
    $modeOf = function (string $waId) use ($msg): ?string { return (json_decode((string)($msg($waId)['metadata'] ?? ''), true) ?: [])['document']['mode'] ?? null; };
    $stateOf = function (string $phone) use ($pdo): string { return (string)$pdo->query("SELECT state FROM wa_conversations WHERE phone = '{$phone}'")->fetchColumn(); };
    $holdingTo = function (string $phone) use ($s, $HOLD): int { return count(array_filter($s->texts(), function ($c) use ($phone, $HOLD) { return ($c['number'] ?? '') === $phone && trim((string)$c['text']) === $HOLD; })); };
    $alertsWith = function (string $needle) use ($s): int { return count(array_filter($s->texts(), function ($c) use ($needle) { return ($c['number'] ?? '') === '256700000999' && strpos((string)$c['text'], $needle) !== false; })); };
    $generalDocx = vd_docx(VA_GENERAL);
    $textIn = function (string $phone, string $id) use ($s, $replies, $lastReplyBy): array { $r0 = $replies(); $r = $s->evoInbound($phone, 'Hello, do you cover Gulu?', 'sj-sales', $id); return ['http' => $r[0], 'queued' => $r[2]['queued'] ?? null, 'replies_added' => $replies() - $r0, 'by' => $lastReplyBy()]; };

    // W1 — DRY RUN: a captioned general document. The webhook answers the caption as text (queued 1); the runner records and queues nothing.
    $r = $post(vd_envelope('document', 'VA-W-1', '256772001101', ['caption' => 'Here is our site plan', 'fileName' => 'plan.docx']));
    $serve('VA-W-1', $generalDocx);
    $r1 = $replies(); $out1 = $runner();
    $f['w1'] = ['webhook' => ['http' => $r[0], 'queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null],
                'runner_clean' => preg_match('/Fatal|Warning|Notice|Deprecated/i', $out1) === 0 && stripos($out1, 'school in Gulu') === false, 'row' => vd_brief(vd_row($pdo, 'VA-W-1')),
                'replies_by_runner' => $replies() - $r1, 'last_by' => $lastReplyBy(), 'body' => (string)($msg('VA-W-1')['body'] ?? ''), 'mode' => $modeOf('VA-W-1'),
                'state' => $stateOf('256772001101'), 'holding' => $holdingTo('256772001101'), 'texts' => count($s->texts()), 'text_turn' => $textIn('256772001101', 'VA-W-1-T')];
    // W2 — DRY RUN: a receipt. Recorded as evidence, nobody told.
    $r = $post(vd_envelope('document', 'VA-W-2', '256772001102', ['caption' => 'I have paid', 'fileName' => 'momo.docx']));
    $serve('VA-W-2', vd_docx(VA_RECEIPT), VA_MIME_DOCX, 'momo.docx');
    $runner();
    $f['w2'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'row' => vd_brief(vd_row($pdo, 'VA-W-2')), 'mode' => $modeOf('VA-W-2'), 'body' => (string)($msg('VA-W-2')['body'] ?? ''),
                'state' => $stateOf('256772001102'), 'holding' => $holdingTo('256772001102'), 'alert' => $alertsWith('nothing was recorded or marked paid'), 'escalations' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'")];
    // W3 — HAND-OVER mode: the receipt goes to a person; the caption is still answered as text by the webhook.
    $flag(['ai_media_document_handover' => '1']);
    $r = $post(vd_envelope('document', 'VA-W-3', '256772001103', ['caption' => 'I have paid', 'fileName' => 'momo.docx']));
    $serve('VA-W-3', vd_docx(VA_RECEIPT), VA_MIME_DOCX, 'momo.docx');
    $r3 = $replies(); $runner();
    $f['w3'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'row' => vd_brief(vd_row($pdo, 'VA-W-3')), 'mode' => $modeOf('VA-W-3'), 'body' => (string)($msg('VA-W-3')['body'] ?? ''),
                'replies_by_runner' => $replies() - $r3, 'state' => $stateOf('256772001103'), 'holding' => $holdingTo('256772001103'), 'alert' => $alertsWith('nothing was recorded or marked paid'),
                'alert_has_amount' => $alertsWith('150,000') + $alertsWith('7G4K2Q9'), 'text_turn' => $textIn('256772001103', 'VA-W-3-T')];
    // W4 — REPLY mode: the caption is carried by the document turn (queued 0) and the runner queues the one AI turn.
    $flag(['ai_media_document_handover' => '1', 'ai_media_document_reply' => '1']);
    $r = $post(vd_envelope('document', 'VA-W-4', '256772001104', ['caption' => 'Here is our site plan', 'fileName' => 'plan.docx']));
    $serve('VA-W-4', $generalDocx);
    $r4 = $replies(); $runner();
    $ev = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $p = json_decode((string)($ev['payload'] ?? ''), true) ?: [];
    $f['w4'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'row' => vd_brief(vd_row($pdo, 'VA-W-4')), 'mode' => $modeOf('VA-W-4'), 'replies_by_runner' => $replies() - $r4,
                'event_by' => $ev['created_by'] ?? null, 'origin' => $p['origin'] ?? null, 'message_has_caption' => strpos((string)($p['message'] ?? ''), 'Here is our site plan') !== false,
                'body_labelled' => strpos((string)($msg('VA-W-4')['body'] ?? ''), DocumentExtraction::LABEL) === 0, 'text_turn' => $textIn('256772001104', 'VA-W-4-T')];
    // W5 — every flag off again: a text message is answered as always, and a document is stored and fetched only when the media flag is on.
    $flag(['ai_media_document' => '0', 'ai_media_document_handover' => '0', 'ai_media_document_reply' => '0']);
    $f['w5'] = ['text_turn' => $textIn('256772001105', 'VA-W-5-T')];
    // T — the counters tool through the real tree, and the two settings through set_config
    [$rcT, $outT] = $s->run('tools/media_status.php', ['--days', '1']);
    [$rcJ, $outJ] = $s->run('tools/media_status.php', ['--days', '1', '--json']);
    $needles = function (string $hay): array { $hits = []; foreach (VA_NEEDLES as $nd) if (stripos($hay, $nd) !== false) $hits[] = $nd; return $hits; };
    $jsonStart = strpos($outJ, '{');
    $f['tool'] = ['rc' => $rcT, 'rc_json' => $rcJ, 'text_hits' => $needles($outT), 'json' => $jsonStart === false ? [] : (json_decode(substr($outJ, $jsonStart), true) ?: []), 'json_hits' => $needles($outJ), 'text_head' => substr($outT, 0, 200)];
    [$rcA, $outA] = $s->run('tools/set_config.php', ['--key', 'ai_media_document_handover', '--value', '1']);
    [$rcB, $outB] = $s->run('tools/set_config.php', ['--key', 'ai_media_document_reply', '--value', '0']);
    $f['set_config'] = ['handover' => ['rc' => $rcA, 'saved' => strpos($outA, 'ai_media_document_handover = 1') !== false], 'reply' => ['rc' => $rcB, 'saved' => strpos($outB, 'ai_media_document_reply = 0') !== false],
                        'mode_after' => MediaPolicy::documentMode(array_merge($s->cfg, ['ai_media_document' => '1', 'ai_media_document_handover' => '1', 'ai_media_document_reply' => '0']))];
    $f['ai_log'] = ['hits' => $needles((string)@file_get_contents($s->data . '/ai_platform.log')), 'outcome_lines' => preg_match_all('/document outcome=/', (string)@file_get_contents($s->data . '/ai_platform.log')) ?: 0];
    $s->http('GET', "{$s->evo}/__test/reset");
    $s->stop();
    return $f;
}

// ── Driver mode: one JSON line ───────────────────────────────────────────────────────────────────────
if ($driver !== null) {
    if ($driver === 'cli') {
        $facts = va_cli($root);
    } else {
        $evo = (int)getenv('DN_T_EVO_PORT'); $ucrm = (int)getenv('DN_T_UCRM_PORT');
        $srvE = $srvU = null;
        if ($evo <= 0)  { [$srvE, $evo]  = vd_boot($root . '/tests/fixtures/fake_evo_server.php', 9720, 'FAKE-EVO-TEST'); }
        if ($ucrm <= 0) { [$srvU, $ucrm] = vd_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9790, 'FAKE-UCRM-TEST'); }
        $facts = va_core($root, $evo, $ucrm);
        foreach ([$srvE, $srvU] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    echo json_encode($facts), "\n";
    exit(0);
}

// ── Main ─────────────────────────────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; } }
function j($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

[$evoSrv, $evoPort]   = vd_boot($root . '/tests/fixtures/fake_evo_server.php', 9720, 'FAKE-EVO-TEST');
[$ucrmSrv, $ucrmPort] = vd_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9790, 'FAKE-UCRM-TEST');
$c = va_core($root, $evoPort, $ucrmPort);

echo "1. The ladder: sixteen flag combinations, four modes, no bypass\n";
$mx = $c['matrix'];
$expect = [];
foreach ($mx as $k => $v) {
    [$m, $d, $h, $r] = str_split((string)$k);   // a key such as 1101 became an integer key
    $mode = ($m !== '1' || $d !== '1') ? 'off' : ($h === '1' && $r === '1' ? 'reply' : ($h === '1' ? 'handover' : 'dry_run'));
    $expect[$k] = ['mode' => $mode, 'document' => $m === '1' && $d === '1', 'handover' => $m === '1' && $d === '1' && $h === '1', 'reply' => $m === '1' && $d === '1' && $h === '1' && $r === '1'];
}
is_(count($mx) === 16 && $mx === $expect, 'every combination of the four flags gives the mode the ladder says: a rung is on only with every rung below it', j(array_diff_assoc(array_map('json_encode', $mx), array_map('json_encode', $expect))));
is_($mx['1101']['mode'] === 'dry_run' && $mx['1101']['reply'] === false, 'reply without hand-over is the dry run, not a reply', j($mx['1101']));
is_($mx['1011']['mode'] === 'off' && $mx['0111']['mode'] === 'off', 'hand-over and reply without the document flag, or without the media flag, are off', j([$mx['1011'], $mx['0111']]));
is_($mx['0000']['mode'] === 'off' && $c['defaults']['no_keys'] === 'off' && $c['defaults']['reply_alone'] === 'off', 'no key set is off; the reply key alone is off', j($c['defaults']));
is_(MediaPolicy::DOCUMENT_MODES === ['off', 'dry_run', 'handover', 'reply'], 'the four mode words are declared once');

echo "\n2. The DRY RUN: recorded, nobody told, nothing answered, the stored body untouched\n";
$d = $c['d_general'];
is_($d['row']['status'] === 'understood' && $d['row']['kind'] === 'extraction' && strpos((string)$d['row']['text'], 'school in Gulu') !== false, 'a general document is read and RECORDED (understanding, extraction) exactly as production would record it', j($d['row']));
is_($d['replies_added'] === 0 && $d['worker_replies_added'] === 0 && $d['escalations_added'] === 0 && $d['holding_added'] === 0 && $d['alerts_added'] === [] && $d['state'] !== 'needs_human',
    'NO ai.reply event, NO hand-over, no alert, no holding line, the conversation untouched', j([$d['replies_added'], $d['escalations_added'], $d['holding_added'], $d['alerts_added'], $d['state']]));
is_($d['msg_body'] === '[DOCUMENT]' && ($d['meta']['mode'] ?? null) === 'dry_run' && ($d['meta']['body_rewritten'] ?? null) === false && ($d['meta']['classification'] ?? null) === 'general',
    'the stored body stays "[DOCUMENT]"; the record lives in the metadata: mode dry_run, classification, body_rewritten false', j([$d['msg_body'], $d['meta']]));
is_(is_array($d['meta']) && array_key_exists('ms', $d['meta']) && is_int($d['meta']['ms']) && $d['meta']['ms'] >= 0, 'the processing time is recorded as a number of milliseconds', j($d['meta']['ms'] ?? null));
is_($d['event_status'] === 'done' && ($d['run']['processed'] ?? 0) === 1 && ($d['run']['failed'] ?? -1) === 0, 'the media event is acknowledged', j($d['run']));
is_(strpos($d['log'], 'dry run: recorded, no AI turn') !== false && is_array($d['outcome_line']) && $d['outcome_line']['outcome'] === 'recorded' && $d['outcome_line']['class'] === 'general'
    && $d['outcome_line']['kind'] === 'docx' && $d['outcome_line']['mode'] === 'dry_run' && $d['outcome_line']['attempts'] === 1,
    'the log says dry run, and carries ONE structured outcome line: recorded, docx, general, dry_run, attempts 1', j([$d['outcome_line'], substr($d['log'], 0, 400)]));
$h = $c['d_general_hist'];
is_($h['brain_calls'] === 1 && $h['turns'] >= 2 && $h['has_placeholder'] && $h['has_extract'] === false && $h['has_label'] === false && $h['ctx_has_document'] === false && $h['ctx_json_has_extract'] === false,
    'a LATER typed turn shows the model "[DOCUMENT]" in its history and never the extract, the label or a document key — the dry run leaks nothing through history', j($h));
$p = $c['d_receipt'];
is_($p['row']['status'] === 'understood' && $p['row']['kind'] === 'document_evidence' && $p['replies_added'] === 0 && $p['escalations_added'] === 0 && $p['holding_added'] === 0 && $p['alerts_added'] === [] && $p['state'] !== 'needs_human',
    'a payment receipt in the dry run: recorded as evidence, NO hand-over, no alert, no holding line, no AI turn', j([$p['row'], $p['replies_added'], $p['escalations_added'], $p['state']]));
is_($p['msg_body'] === 'I have paid' && ($p['meta']['mode'] ?? null) === 'dry_run' && ($p['meta']['evidence'] ?? null) === true && strpos($p['log'], 'dry run: recorded, nobody told') !== false,
    'its caption stays the stored body; the metadata says evidence, dry_run; the log says nobody told', j([$p['msg_body'], $p['meta']]));
foreach (['d_identity' => 'identity_document', 'd_credential' => 'credential'] as $k => $cls) {
    $t = $c[$k];
    is_($t['row']['kind'] === 'document_evidence' && (string)$t['row']['text'] === '' && ($t['meta']['classification'] ?? null) === $cls && $t['replies_added'] === 0 && $t['escalations_added'] === 0,
        "{$cls} in the dry run: recorded with NO excerpt, classified, nobody told, no AI turn", j($t['row']));
}
foreach (['d_encrypted' => 'password_protected', 'd_scanned' => 'provider_missing', 'd_fetchfail' => 'fetch_failed'] as $k => $reason) {
    $t = $c[$k];
    is_($t['row']['status'] === 'failed' && $t['row_reason'] === $reason && $t['replies_added'] === 0 && $t['escalations_added'] === 0 && $t['holding_added'] === 0 && $t['state'] !== 'needs_human' && $t['event_status'] === 'done',
        "{$reason} in the dry run: the refusal is recorded on the row and nobody is told", j([$t['row'], $t['row_reason'], $t['escalations_added'], $t['state'], $t['event_status']]));
}
is_(is_array($c['d_encrypted']['outcome_line']) && $c['d_encrypted']['outcome_line']['outcome'] === 'failed' && $c['d_encrypted']['outcome_line']['reason'] === 'password_protected'
    && is_array($c['d_fetchfail']['outcome_line']) && $c['d_fetchfail']['outcome_line']['outcome'] === 'failed' && $c['d_fetchfail']['outcome_line']['reason'] === 'fetch_failed',
    'a refusal and a failed fetch each leave one structured outcome line with the reason code', j([$c['d_encrypted']['outcome_line'], $c['d_fetchfail']['outcome_line']]));

echo "\n3. The HAND-OVER mode: the person, and still no AI turn\n";
$hg = $c['h_general'];
is_($hg['row']['status'] === 'understood' && $hg['row']['kind'] === 'extraction' && $hg['replies_added'] === 0 && $hg['escalations_added'] === 0 && $hg['msg_body'] === 'Here is our site plan' && ($hg['meta']['mode'] ?? null) === 'handover'
    && strpos($hg['log'], 'hand-over mode: recorded, no AI turn') !== false,
    'a harmless document in hand-over mode: recorded, no AI turn, no hand-over (nothing is wrong with it), the caption stays the body', j([$hg['row'], $hg['replies_added'], $hg['escalations_added'], $hg['msg_body'], $hg['meta']['mode'] ?? null]));
$hp = $c['h_receipt'];
is_($hp['row']['kind'] === 'document_evidence' && $hp['replies_added'] === 0 && $hp['escalations_added'] === 1 && $hp['esc']['by'] === 'media_worker' && $hp['esc']['reason'] === PaymentEvidence::handoverReason()
    && $hp['state'] === 'needs_human' && $hp['holding_added'] === 1 && count($hp['alerts_added']) === 1 && strpos($hp['alerts_added'][0], 'nothing was recorded or marked paid') !== false,
    'a payment receipt in hand-over mode: evidence, ONE hand-over by the media worker with the payment wording, needs_human, the alert, the holding line — and no AI turn', j([$hp['row'], $hp['escalations_added'], $hp['esc'], $hp['state'], $hp['holding_added'], $hp['alerts_added']]));
is_($hp['msg_body'] === 'I have paid' && ($hp['meta']['mode'] ?? null) === 'handover' && ($hp['meta']['body_rewritten'] ?? null) === false && strpos($hp['alerts_added'][0] ?? '', '150,000') === false,
    'the stored body is still the caption (no excerpt enters the model\'s history); the alert carries no amount', j([$hp['msg_body'], $hp['meta']]));
$he = $c['h_encrypted'];
is_($he['row_reason'] === 'password_protected' && $he['escalations_added'] === 1 && $he['esc']['reason'] === DocumentExtraction::handoverReason('failed', 'password_protected', 'encrypted PDF') && $he['state'] === 'needs_human' && $he['replies_added'] === 0,
    'an encrypted PDF in hand-over mode is handed to a person with the refusal wording', j([$he['row_reason'], $he['esc'], $he['state']]));
$hf = $c['h_fetchfail'];
is_($hf['row_reason'] === 'fetch_failed' && $hf['escalations_added'] === 1 && strpos($hf['esc']['reason'], 'a document could not be fetched (fetch_failed)') === 0 && $hf['state'] === 'needs_human',
    'a document the gateway will not hand over (a 4xx) is handed to a person in hand-over mode', j([$hf['row_reason'], $hf['esc'], $hf['state']]));

echo "\n4. The REPLY mode, as before this batch\n";
$rg = $c['r_general'];
is_($rg['row']['kind'] === 'extraction' && $rg['replies_added'] === 1 && $rg['worker_replies_added'] === 1 && $rg['escalations_added'] === 0 && ($rg['meta']['mode'] ?? null) === 'reply' && ($rg['meta']['body_rewritten'] ?? null) === true
    && strpos($rg['msg_body'], DocumentExtraction::LABEL) === 0 && strpos($rg['msg_body'], 'Here is our site plan') !== false && is_array($rg['outcome_line']) && $rg['outcome_line']['outcome'] === 'understood',
    'a harmless captioned document on the reply rung: the one AI turn, the body rewritten under its label with the caption, outcome understood', j([$rg['row'], $rg['replies_added'], $rg['meta'], substr($rg['msg_body'], 0, 80)]));
$rp = $c['r_receipt'];
is_($rp['row']['kind'] === 'document_evidence' && $rp['replies_added'] === 0 && $rp['escalations_added'] === 1 && strpos($rp['msg_body'], sprintf(DocumentExtraction::LABEL_EVIDENCE, 'payment evidence')) === 0 && ($rp['meta']['mode'] ?? null) === 'reply',
    'a receipt on the reply rung: evidence, the hand-over, the evidence label in the body, no AI turn', j([$rp['row'], $rp['escalations_added'], substr($rp['msg_body'], 0, 80)]));

echo "\n5. A higher flag never bypasses a lower one\n";
$b = $c['bypass'];
is_($b['reply_without_handover']['row']['status'] === 'understood' && $b['reply_without_handover']['replies_added'] === 0 && $b['reply_without_handover']['msg_body'] === '[DOCUMENT]' && ($b['reply_without_handover']['meta']['mode'] ?? null) === 'dry_run',
    'reply on, hand-over off: the dry run — recorded, no AI turn, the body untouched', j($b['reply_without_handover']['row']));
is_($b['handover_without_document']['row']['status'] === 'fetched' && (string)$b['handover_without_document']['row']['text'] === '' && $b['handover_without_document']['escalations_added'] === 0 && $b['handover_without_document']['replies_added'] === 0,
    'hand-over and reply on, document off: fetched only (Batch 1), nothing read, nobody told', j($b['handover_without_document']['row']));
is_($b['all_without_media']['row']['status'] === 'skipped' && $b['all_without_media']['row_reason'] === 'media_disabled' && $b['all_without_media']['fetches_added'] === 0 && $b['all_without_media']['replies_added'] === 0,
    'every document flag on, media off: skipped, nothing fetched', j([$b['all_without_media']['row'], $b['all_without_media']['row_reason']]));
is_($c['defaults']['media_only']['row']['status'] === 'fetched' && $c['defaults']['media_only']['replies_added'] === 0 && $c['defaults']['media_only']['escalations_added'] === 0,
    'media on, document off (the bottom rung): fetched and wiped, nothing else — the rollback state of every higher rung', j($c['defaults']['media_only']['row']));

echo "\n6. The human-only classes never make an AI turn, in any mode\n";
$hm = $c['human_matrix'];
$bad = [];
foreach ($hm as $k => $v) {
    [$mode, $cls] = explode('/', $k);
    if ($v['replies_added'] !== 0 || $v['worker_replies_added'] !== 0 || $v['kind'] !== 'document_evidence' || $v['status'] !== 'understood') $bad[] = $k . ' ' . j($v);
    if ($mode === 'dry_run' && $v['escalations_added'] !== 0) $bad[] = $k . ' handed over in the dry run';
    if ($mode !== 'dry_run' && $v['escalations_added'] !== 1) $bad[] = $k . ' not handed over';
    if ($v['mode'] !== $mode) $bad[] = $k . ' recorded mode ' . j($v['mode']);
    if ($v['body_rewritten'] !== ($mode === 'reply')) $bad[] = $k . ' body_rewritten ' . j($v['body_rewritten']);
}
is_(count($hm) === 15 && $bad === [], 'fifteen combinations (receipt, invoice, identity, credential, quotation × dry run, hand-over, reply): evidence every time, no AI turn ever, a person only above the dry run, the body rewritten only on the reply rung', j($bad));
is_($c['worker_replies_total'] === 1, 'across the whole scenario the media worker queued exactly ONE AI turn: the one harmless document on the reply rung (' . $c['worker_replies_total'] . ')', j($c['worker_replies_total']));

echo "\n7. The lost-worker guard, and the shared queue left as it is\n";
$q = $c['retry'];
is_($q['handover']['row']['status'] === 'dead' && $q['handover']['row_reason'] === 'worker_lost' && $q['handover']['fetches_added'] === 0 && $q['handover']['event_status'] === 'done'
    && $q['handover']['escalations_added'] === 1 && strpos($q['handover']['esc']['reason'], 'a document could not be read after every attempt') === 0 && $q['handover']['state'] === 'needs_human' && $q['handover']['holding_added'] === 1,
    'a row already claimed five times (the queue allows five) is settled DEAD without a fetch, the event acknowledged, and in hand-over mode a person is told as for a dead letter', j([$q['handover']['row'], $q['handover']['row_reason'], $q['handover']['fetches_added'], $q['handover']['event_status'], $q['handover']['esc'], $q['handover']['state']]));
is_(is_array($q['handover']['outcome_line']) && $q['handover']['outcome_line']['outcome'] === 'dead' && $q['handover']['outcome_line']['reason'] === 'worker_lost', 'its outcome line says dead, worker_lost', j($q['handover']['outcome_line']));
is_($q['dry']['row']['status'] === 'dead' && $q['dry']['row_reason'] === 'worker_lost' && $q['dry']['fetches_added'] === 0 && $q['dry']['escalations_added'] === 0 && $q['dry']['holding_added'] === 0 && $q['dry']['alerts_added'] === [] && $q['dry']['state'] === 'needs_human',
    'in the dry run the same row is settled dead and marked for the inbox (Batch 1), with no alert and no message', j([$q['dry']['row'], $q['dry']['escalations_added'], $q['dry']['state']]));
is_($q['control']['row']['status'] === 'understood' && $q['control']['fetches_added'] === 1 && $q['control']['row']['attempts'] === 5,
    'control: a row claimed four times is fetched and read on its fifth, allowed claim', j($q['control']['row']));
$bus = $c['bus'];
is_($bus['after_claim']['status'] === 'processing' && $bus['after_claim']['attempts'] === 0, 'EventBus: a claim counts no attempt (unchanged)', j($bus['after_claim']));
is_($bus['after_stale']['status'] === 'processing' && $bus['after_stale']['attempts'] === 0 && strpos($bus['after_stale']['error'], '[stale lock released]') !== false,
    'EventBus: a stale lock is released and re-claimed with attempts still 0 — the shared property Batch 5 leaves alone and the worker guards against', j($bus['after_stale']));
is_($bus['fails_to_dead'] === 5 && $bus['final']['status'] === 'dead' && $bus['final']['attempts'] === 5, 'EventBus: five reported failures make an event dead (unchanged)', j($bus));

echo "\n8. Privacy: the log and the counters carry codes and counts, never content\n";
$pv = $c['privacy'];
is_($pv['log_hits'] === [] && $pv['log_has_outcome_lines'] >= 20, 'the worker log holds no extract, amount, reference, identity, credential, number, file name, caption or JID — across ' . $pv['log_has_outcome_lines'] . ' outcome lines', j($pv['log_hits']));
is_($pv['tool_rc0'] && $pv['tool_text_hits'] === [] && $pv['tool_json_hits'] === [], 'tools/media_status.php runs (rc 0) and neither its text nor its JSON carries any of the needles', j([$pv['tool_text_hits'], $pv['tool_json_hits'], $pv['tool_text_head']]));
$tj = $pv['tool_json']; $dc = $pv['db_counts'];
is_(($tj['mode'] ?? null) === 'handover' && ($tj['flags']['ai_media_document_reply'] ?? null) === 'off' && ($tj['flags']['ai_document_provider'] ?? null) === 'none', 'the tool reports the mode and the flags as the configuration files say', j($tj['flags'] ?? null));
is_(($tj['documents_received'] ?? -1) === $dc['received'] && ($tj['documents']['recorded_for_the_worker'] ?? -1) === $dc['recorded'] && ($tj['documents']['extraction']['understood'] ?? -1) === $dc['understood'],
    'received, recorded and understood match the tables', j([$tj['documents_received'] ?? null, $tj['documents']['recorded_for_the_worker'] ?? null, $tj['documents']['extraction']['understood'] ?? null, $dc]));
is_(($tj['documents']['extraction']['encrypted'] ?? -1) === $dc['encrypted'] && ($tj['documents']['fetch']['failed'] ?? -1) === $dc['fetch_failed'] && ($tj['documents']['extraction']['scanned_no_provider'] ?? -1) === 1,
    'encrypted, failed fetches and scanned-without-provider are counted by reason', j($tj['documents']['extraction'] ?? null));
is_(($tj['documents']['handovers'] ?? -1) === $dc['handovers'] && ($tj['documents']['ai_turns_queued'] ?? -1) === $dc['worker_replies'] && ($tj['documents']['retries']['worker_lost'] ?? -1) === $dc['worker_lost'] && ($tj['documents']['fetch']['dead'] ?? -1) === $dc['dead'],
    'hand-overs, AI turns queued for documents, worker-lost rows and dead rows match the tables', j([$tj['documents']['handovers'] ?? null, $tj['documents']['ai_turns_queued'] ?? null, $tj['documents']['retries'] ?? null, $tj['documents']['fetch']['dead'] ?? null, $dc]));
$cl = $tj['documents']['classification'] ?? [];
is_(($cl['by_mode']['dry_run'] ?? 0) >= 7 && ($cl['by_mode']['handover'] ?? 0) >= 6 && ($cl['by_mode']['reply'] ?? 0) >= 2 && ($cl['human_only'] ?? 0) >= 15 && ($cl['harmless_ai_eligible'] ?? 0) >= 3 && ($cl['stored_body_rewritten'] ?? -1) === ($cl['by_mode']['reply'] ?? -2),
    'classifications are counted by class and by mode, human-only and harmless apart, and the body was rewritten exactly as often as the reply rung ran', j($cl));
is_(($tj['documents']['retries']['most_attempts_on_a_row'] ?? 0) >= 5 && ($tj['documents']['retries']['rows_with_more_than_one_attempt'] ?? 0) >= 3 && isset($tj['documents']['duration_ms']['classified_p95']) && isset($tj['documents']['queue']['dead']),
    'retries, durations and the queue are reported', j([$tj['documents']['retries'] ?? null, $tj['documents']['duration_ms'] ?? null, $tj['documents']['queue'] ?? null]));
$toolCode = (string)file_get_contents($root . '/tools/media_status.php');
is_(preg_match('/SELECT[^;]*\b(body|understanding|caption|file_name|remote_jid|sha256|phone|display_name)\b/i', $toolCode) === 0 && strpos($toolCode, 'SQLITE_OPEN_READONLY') !== false && strpos($toolCode, 'cliDataDir(') !== false,
    'the tool never selects a body, an understanding, a caption, a file name, a JID, a hash or a number; it opens the database read-only through the data-directory guard');

echo "\nC. The real webhook and the CLI runner\n";
$w = va_cli($root);
$w1 = $w['w1'];
is_(($w1['webhook']['http'] ?? 0) === 200 && ($w1['webhook']['queued'] ?? -1) === 1 && ($w1['webhook']['media_queued'] ?? 0) === 1 && $w1['last_by'] === 'evo_webhook',
    'DRY RUN, a captioned document: the webhook answers the caption as text (one ai.reply by evo_webhook) and records the document', j($w1['webhook']));
is_($w1['row']['status'] === 'understood' && $w1['row']['kind'] === 'extraction' && $w1['replies_by_runner'] === 0 && $w1['mode'] === 'dry_run' && $w1['body'] === 'Here is our site plan' && $w1['state'] !== 'needs_human' && $w1['holding'] === 0 && $w1['texts'] === 0 && $w1['runner_clean'] === true,
    'the runner reads and records it, queues nothing, sends nothing, raises nothing; the body is still the caption', j([$w1['row'], $w1['replies_by_runner'], $w1['mode'], $w1['body'], $w1['state'], $w1['texts'], $w1['runner_clean']]));
is_(($w1['text_turn']['queued'] ?? 0) === 1 && $w1['text_turn']['by'] === 'evo_webhook', 'a typed message in the dry run is queued for the assistant as always', j($w1['text_turn']));
$w2 = $w['w2'];
is_($w2['webhook_queued'] === 1 && $w2['row']['kind'] === 'document_evidence' && $w2['mode'] === 'dry_run' && $w2['body'] === 'I have paid' && $w2['state'] !== 'needs_human' && $w2['holding'] === 0 && $w2['alert'] === 0 && $w2['escalations'] === 0,
    'DRY RUN, a receipt: evidence recorded, nobody told, no alert, no holding line', j($w2));
$w3 = $w['w3'];
is_($w3['webhook_queued'] === 1 && $w3['row']['kind'] === 'document_evidence' && $w3['mode'] === 'handover' && $w3['body'] === 'I have paid' && $w3['replies_by_runner'] === 0 && $w3['state'] === 'needs_human' && $w3['holding'] === 1 && $w3['alert'] === 1 && $w3['alert_has_amount'] === 0,
    'HAND-OVER mode, a receipt: the caption still answered as text, the person told (alert without the amount, holding line), no AI turn, the body untouched', j($w3));
is_(($w3['text_turn']['queued'] ?? 0) === 1 && $w3['text_turn']['by'] === 'evo_webhook', 'a typed message in hand-over mode is queued for the assistant as always', j($w3['text_turn']));
$w4 = $w['w4'];
is_($w4['webhook_queued'] === 0 && $w4['row']['kind'] === 'extraction' && $w4['mode'] === 'reply' && $w4['replies_by_runner'] === 1 && $w4['event_by'] === 'media_worker' && $w4['origin'] === 'document' && $w4['message_has_caption'] && $w4['body_labelled'],
    'REPLY mode: the caption is carried by the document turn (the webhook queues no text turn), the runner queues the one AI turn with the caption, the body is labelled', j($w4));
is_(($w4['text_turn']['queued'] ?? 0) === 1 && $w4['text_turn']['by'] === 'evo_webhook' && ($w['w5']['text_turn']['queued'] ?? 0) === 1 && $w['w5']['text_turn']['by'] === 'evo_webhook',
    'a typed message is queued for the assistant in reply mode and with every document flag off: the text path is untouched by the ladder', j([$w4['text_turn'], $w['w5']['text_turn']]));
$tl = $w['tool'];
is_($tl['rc'] === 0 && $tl['rc_json'] === 0 && $tl['text_hits'] === [] && $tl['json_hits'] === [] && ($tl['json']['documents']['recorded_for_the_worker'] ?? 0) >= 4 && ($tl['json']['documents']['classification']['by_mode']['dry_run'] ?? 0) >= 2,
    'the counters tool runs in the real tree, rc 0, no needle in either form, and counts what the runner did', j([$tl['rc'], $tl['rc_json'], $tl['text_hits'], $tl['json_hits'], $tl['text_head']]));
is_($w['set_config']['handover']['rc'] === 0 && $w['set_config']['handover']['saved'] && $w['set_config']['reply']['rc'] === 0 && $w['set_config']['reply']['saved'] && $w['set_config']['mode_after'] === 'handover',
    'set_config accepts the two new bool keys, and hand-over on with reply off is the hand-over mode', j($w['set_config']));
is_($w['ai_log']['hits'] === [] && $w['ai_log']['outcome_lines'] >= 4, 'ai_platform.log in the real tree carries outcome lines and none of the needles', j($w['ai_log']));

echo "\nD. Wiring\n";
is_(strpos(vd_codeOf($root . '/evo_webhook.php'), "if (\$mediaEvent && (string)(\$media['kind'] ?? '') === 'document' && MediaPolicy::documentReplyEnabled(\$config)) {") !== false,
    'the webhook skips the caption\'s text turn only on the reply rung, through the policy');
$mwCode = vd_codeOf($root . '/workers/MediaWorker.php'); $deCode = vd_codeOf($root . '/lib/DocumentExtraction.php');
is_(strpos($mwCode, "'ai_media_document") === false && strpos($deCode, "'ai_media_document") === false && strpos($mwCode, 'MediaPolicy::documentHandoverEnabled($this->config)') !== false && strpos($deCode, 'MediaPolicy::documentMode($this->config)') !== false,
    'the worker and the extraction read the rungs through the policy only, never a flag by name');
is_(strpos($deCode, "if (\$reply) {") !== false && strpos($deCode, "UPDATE wa_messages SET metadata = ? WHERE id = ? AND media_type = 'document'") !== false,
    'the body is rewritten under one condition — the reply rung — and otherwise only the metadata is written');
is_(strpos($mwCode, "if ((int)\$row['attempts'] >= \$maxAttempts) {") !== false && strpos($mwCode, "REASON_WORKER_LOST = 'worker_lost'") !== false, 'the lost-worker guard reads the event\'s own max_attempts and names its reason once');
$ebCode = vd_codeOf($root . '/lib/EventBus.php');
$ebConsume = substr($ebCode, strpos($ebCode, 'function consume('), strpos($ebCode, 'function ack(') - strpos($ebCode, 'function consume('));
$ebStale   = substr($ebCode, strpos($ebCode, 'function releaseStale('), strpos($ebCode, 'function getDeadLetters(') - strpos($ebCode, 'function releaseStale('));
$claimSet  = substr($ebConsume, strpos($ebConsume, "SET status = 'processing'"), strpos($ebConsume, 'WHERE id IN') - strpos($ebConsume, "SET status = 'processing'"));
is_($claimSet !== '' && strpos($claimSet, 'attempts') === false && $ebStale !== '' && strpos($ebStale, 'attempts') === false && strpos($ebStale, "'failed'") !== false,
    'EventBus is unchanged in kind: neither the claim nor the stale release touches attempts (the shared semantics Batch 5 did not alter)', j([$claimSet, substr($ebStale, 0, 120)]));
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'ai_media_document_handover' => ['bool',") !== false && strpos($sc, "'ai_media_document_reply' => ['bool',") !== false, 'set_config.php manages the two new rungs');
is_(count(glob($root . '/migrations/08[6-9]_*.sql') ?: []) === 0, 'no migration: the mode and the time live in the stored message\'s metadata');
is_(is_file(dirname($root) . '/docs/60-document-activation-safety-2026-10-05.md'), 'the activation safety record exists (docs/60)');
is_(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] === '5.18.81', 'manifest version is 5.18.81');

echo "\nE. Weakened copies — each caught by the scenario that guards it\n";
$mutants = [
    ['lib/MediaPolicy.php', "        return self::documentHandoverEnabled(\$config) && self::flag(\$config['ai_media_document_reply'] ?? null);", "        return self::documentEnabled(\$config) && self::flag(\$config['ai_media_document_reply'] ?? null);",
     'the reply rung no longer needs the hand-over rung', function (array $m): bool { return ($m['matrix']['1101']['mode'] ?? '') !== 'dry_run'; }],
    ['lib/MediaPolicy.php', "        return self::documentEnabled(\$config) && self::flag(\$config['ai_media_document_handover'] ?? null);", "        return self::enabled(\$config) && self::flag(\$config['ai_media_document_handover'] ?? null);",
     'the hand-over rung no longer needs the document rung', function (array $m): bool { return ($m['matrix']['1011']['handover'] ?? false) === true; }],
    ['lib/DocumentExtraction.php', "                    if (\$reply) {\n                        \$this->pdo->prepare(\"UPDATE wa_messages SET body = ?, metadata = ?", "                    if (true) {\n                        \$this->pdo->prepare(\"UPDATE wa_messages SET body = ?, metadata = ?",
     'the stored body is rewritten in every mode (the extract reaches the model\'s history in a dry run)', function (array $m): bool { return ($m['d_general']['msg_body'] ?? '') !== '[DOCUMENT]'; }],
    ['lib/DocumentExtraction.php', "            if (!\$reply) {\n                // dry_run or handover: a harmless document is recorded and that is all", "            if (false) {\n                // dry_run or handover: a harmless document is recorded and that is all",
     'the AI turn is queued in every mode', function (array $m): bool { return ($m['d_general']['replies_added'] ?? 0) >= 1; }],
    ['lib/DocumentExtraction.php', "                if (!\$tell) {", "                if (false) {",
     'a human-only class is handed over in the dry run', function (array $m): bool { return ($m['d_receipt']['escalations_added'] ?? 0) >= 1; }],
    ['workers/MediaWorker.php', "        if (\$this->documentHandoverWanted(\$row)) {\n            \$this->log('warn', sprintf('media #%d: document not read", "        if (\$this->documentWanted(\$row)) {\n            \$this->log('warn', sprintf('media #%d: document not read",
     'a refusal is handed over in the dry run', function (array $m): bool { return ($m['d_encrypted']['escalations_added'] ?? 0) >= 1; }],
    ['workers/MediaWorker.php', "        if ((int)\$row['attempts'] >= \$maxAttempts) {", "        if (false) {",
     'the lost-worker guard is gone (a row claimed five times is fetched a sixth time)', function (array $m): bool { return ($m['retry']['handover']['row']['status'] ?? '') !== 'dead' || ($m['retry']['handover']['fetches_added'] ?? 0) >= 1; }],
    ['workers/MediaWorker.php', "        if (\$row !== [] && (\$this->voiceWanted(\$row) || \$this->imageWanted(\$row) || \$this->documentHandoverWanted(\$row))) {", "        if (\$row !== [] && (\$this->voiceWanted(\$row) || \$this->imageWanted(\$row) || \$this->documentWanted(\$row))) {",
     'a dead document in the dry run alerts a person', function (array $m): bool { return ($m['retry']['dry']['escalations_added'] ?? 0) >= 1; }],
    ['workers/MediaWorker.php', "            (string)(\$o['reason'] ?? '-') ?: '-', MediaPolicy::documentMode(\$this->config), (int)(\$o['ms'] ?? 0), (int)(\$fresh['attempts'] ?? 0)));",
     "            (string)(\$o['reason'] ?? '-') ?: '-', MediaPolicy::documentMode(\$this->config), (int)(\$o['ms'] ?? 0), (int)(\$fresh['attempts'] ?? 0)) . ' file=' . (string)(\$fresh['file_name'] ?? ''));",
     'the outcome line carries the file name', function (array $m): bool { return in_array('plan.docx', (array)($m['privacy']['log_hits'] ?? []), true) || in_array('momo.docx', (array)($m['privacy']['log_hits'] ?? []), true); }],
    ['tools/media_status.php', "printf(\"documents received (stored messages):  %d\\n\", \$out['documents_received']);", "printf(\"documents received (stored messages):  %d\\n\", \$out['documents_received']); foreach (\$pdo->query('SELECT understanding FROM wa_media') as \$r) echo (string)\$r['understanding'], \"\\n\";",
     'the counters tool prints the recorded text', function (array $m): bool { return ($m['privacy']['tool_text_hits'] ?? []) !== []; }],
    ['evo_webhook.php', "MediaPolicy::documentReplyEnabled(\$config)) {", "MediaPolicy::documentEnabled(\$config)) {",
     'the webhook skips the caption\'s text turn in the dry run (a captioned document goes unanswered)', function (array $m): bool { return ($m['w1']['webhook']['queued'] ?? 1) === 0; }, 'cli'],
];
$keysOfInterest = ['matrix' => 1, 'd_general' => 1, 'd_receipt' => 1, 'd_encrypted' => 1, 'retry' => 1, 'privacy' => 1, 'w1' => 1];
foreach ($mutants as $mut) {
    [$rel, $old, $new, $what, $flipped] = $mut;
    $drv = $mut[5] ?? 'core';
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "copy — the anchor in {$rel} is unique, so the copy is weakened ({$what})", 'occurrences: ' . $n);
    $out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($copy . '/tests/test_document_activation.php') . ' --driver=' . $drv . ' 2>/dev/null');
    $m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
    if ($drv === 'core' && $m !== []) { $m['matrix'] = $m['matrix'] ?? []; }
    is_($n === 1 && $m !== [] && $flipped($m), "caught — {$what}", $m === [] ? 'driver output: ' . substr($out, 0, 400) : j(array_intersect_key($m, $keysOfInterest)));
    exec('rm -rf ' . escapeshellarg($copy));
}
$out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($root . '/tests/test_document_activation.php') . ' --driver=core 2>/dev/null');
$m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
$outCli = (string)shell_exec('php ' . escapeshellarg($root . '/tests/test_document_activation.php') . ' --driver=cli 2>/dev/null');
$mCli = json_decode((string)strrchr("\n" . trim($outCli), "\n"), true) ?: [];
$flips = 0; foreach ($mutants as $mut) { $facts = ($mut[5] ?? 'core') === 'cli' ? $mCli : $m; if ($facts !== [] && $mut[4]($facts)) $flips++; }
is_($m !== [] && $mCli !== [] && $flips === 0, 'control: the real tree, driven the same way (core and cli), trips none of the ' . count($mutants) . ' catches', j(['facts' => count($m), 'cli_facts' => count($mCli), 'flips' => $flips]));

foreach ([$evoSrv, $ucrmSrv] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
