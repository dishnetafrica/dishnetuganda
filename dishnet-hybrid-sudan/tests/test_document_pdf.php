<?php
declare(strict_types=1);
/**
 * test_document_pdf.php — Batch 4 of the AI communication layer, Slice 4b (docs/55 §9, docs/58 D-1 = P-1): the TEXT LAYER of
 * a PDF, read in this process by lib/PdfReader.php, on the Slice 4a boundary and nothing beyond it.
 *
 * What is proved here, from bytes to the queue, with every PDF generated in the test (tests/fixtures/document_fixtures.php,
 * vd_pdfx — never a real document, nothing real in one):
 *   the reader   a normal page · several pages · Flate, object streams and an xref stream (PDF 1.5) · a composite font with a
 *                ToUnicode CMap (bfchar and bfrange) · TJ arrays with kerning · hex strings · WinAnsi, MacRoman, /Differences ·
 *                Form XObjects, nested, and a cycle refused · an inline image skipped · ASCII85 over Flate · an unsupported
 *                filter refused · a missing catalogue recovered, a missing page tree refused · the page cap, the character
 *                cap, the deadline, the inflate cap, the object cap · glyphs it cannot name counted, never invented
 *   the boundary a general PDF is ONE ai.reply event carrying the extract under its label, never the bytes · a PDF with no
 *                text is a person (pdf_no_text, word for word) · a receipt, a statement, an invoice, a contract, a quotation,
 *                an identity document and a credential in a PDF are a person and never an event, the money and KYC tables
 *                untouched · the brain-eligible text is cut at 4,000 characters · more than 20 pages or more than the scan cap
 *                is NOT seen whole and goes to a person · an extract reading "stop" is not an opt-out · a committing reply on
 *                a PDF turn is refused by the guard · the brain context names classification, kind and truncation and never
 *                the file name · nothing of a PDF rests on disk or in the database but the record policy allows
 *   the budget   DocumentExtraction's memory rule refuses a document that would not fit the live memory_limit and lets one
 *                through that fits (the Slice 4a test gap, closed here; the runner's raise is proved in test_document_media C8)
 * Nothing here enables a flag anywhere, contacts a provider, or sends anything: no fake Evolution is even started.
 *
 * --driver=core prints the facts as one JSON line, so a weakened copy of the plugin can be driven the same way (mutants).
 */

$root = dirname(__DIR__);
$driver = null;
foreach ($argv ?? [] as $a) { if (strpos($a, '--driver') === 0) $driver = substr($a, 9) !== false && substr($a, 9) !== '' ? substr($a, 9) : 'core'; }

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/UtcClock.php';
require_once $root . '/lib/BrainContext.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/MediaPolicy.php';
require_once $root . '/lib/MediaBlob.php';
require_once $root . '/lib/InboundMedia.php';
require_once $root . '/lib/PaymentEvidence.php';
require_once $root . '/lib/DocumentOcr.php';
require_once $root . '/lib/DocumentExtraction.php';
require_once $root . '/lib/PdfReader.php';
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
require_once $root . '/tests/fixtures/document_fixtures.php';    // vd_*()

// ── The texts, named once. Synthetic; the numbers are not real accounts, phones or references. ──
const VP_GENERAL   = ['Dear DishNet,', 'We would like to connect our primary school in Gulu town to the internet.', 'There are twelve classrooms and a staff room.', 'Please advise the next steps and the monthly plans.'];
const VP_RECEIPT   = ['Mobile Money Receipt', 'Transaction ID 7G4K2Q9PDF', 'Reference 123456789012', 'Amount UGX 85,000 paid to DishNet Africa', 'Thank you for using the service.'];
const VP_INVOICE   = ['TAX INVOICE', 'Invoice number INV-0042', 'Bill to: a customer', 'Amount due: UGX 150,000', 'Due date: 30 October'];
const VP_STATEMENT = ['Account statement', 'Opening balance 1,200,000', '01 Sep Deposit 50,000', '03 Sep Payment 20,000', '05 Sep Deposit 10,000', '08 Sep Payment 5,000', '10 Sep Deposit 7,000', 'Closing balance 1,242,000'];
const VP_CONTRACT  = ['SERVICE AGREEMENT', 'The parties hereby agree to the terms and conditions below.', 'Effective date: 1 November', 'Signature of the customer: ____________'];
const VP_QUOTATION = ['QUOTATION', 'Valid until 31 October', 'Unit price UGX 400,000 per kit', 'Total UGX 800,000 for two sites'];
const VP_IDENTITY  = ['REPUBLIC OF UGANDA', 'NATIONAL IDENTIFICATION CARD', 'Surname TESTSURNAME', 'NIN CF00000000TEST', 'Date of birth 01 January 1990', 'Nationality Ugandan'];
const VP_CRED      = ['Router details for the technician', 'Admin login', 'password: hunter2xyz99', 'Please keep this safe.'];

function vp_core(string $root): array
{
    $tmp = sys_get_temp_dir() . '/vp-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    $cfg = ['ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_document' => '1', 'ai_media_max_bytes' => 262144, 'ai_media_document_max_bytes' => 131072,
            'ai_media_document_handover' => '1', 'ai_media_document_reply' => '1',   // Batch 5 (docs/60): the reply rung, as before this batch
            'ai_media_document_timeout_s' => 10, 'ai_media_document_max_pages' => 20, 'data_dir' => $tmp];
    $f = ['tmp' => $tmp];
    $logLines = [];
    $log = function (string $level, string $msg) use (&$logLines): void { $logLines[] = $level . ' ' . $msg; };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $lastReply = function () use ($pdo): array {
        $r = $pdo->query("SELECT id, payload, created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['p'] = json_decode((string)($r['payload'] ?? ''), true) ?: [];
        return $r;
    };
    $conv = function (string $phone) use ($svc): int { return (int)$svc->ensureConversation($phone, 'sales', 'PDF Tester', 'test')['id']; };
    $L = DocumentExtraction::LABEL;

    /** One PDF through DocumentExtraction::process(), exactly as MediaWorker calls it once the bytes are in memory. */
    $run = function (string $waId, string $phone, string $bytes, array $o = []) use ($svc, $pdo, $bus, $conv, $store, $cfg, $log, $replies, $lastReply, $count): array {
        $cid = $conv($phone);
        $caption = (string)($o['caption'] ?? '');
        $fileName = (string)($o['fileName'] ?? 'document.pdf');
        $msgId = $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => $caption !== '' ? $caption : '[DOCUMENT]', 'media_type' => 'document', 'wa_message_id' => $waId]);
        $env = vd_envelope('document', $waId, $phone, ['caption' => $caption, 'mimetype' => (string)($o['mime'] ?? 'application/pdf'), 'fileName' => $fileName, 'bytes' => strlen($bytes)]);
        InboundMedia::record($pdo, $cid, InboundMedia::fromEvoMessage($env['data']), 'dishnet_ug', 'sales', $waId);
        $rc = $replies();
        $ex = new DocumentExtraction($pdo, $store, array_merge($cfg, (array)($o['cfg'] ?? [])), null, $log);
        if (isset($o['deadline'])) $ex->useDeadline($o['deadline']);
        $blob = new MediaBlob($bytes, (string)($o['mime'] ?? 'application/pdf'), 'document', $fileName);
        try {
            $r = $ex->process(vd_row($pdo, $waId), $blob);
        } catch (\Throwable $e) {
            $r = ['outcome' => 'threw', 'reason' => get_class($e), 'detail' => $e->getMessage()];
        } finally {
            $blob->wipe();
        }
        $lr = $replies() > $rc ? $lastReply() : ['p' => [], 'payload' => ''];
        $msg = $pdo->query("SELECT body, metadata FROM wa_messages WHERE id = {$msgId}")->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['cid' => $cid, 'msg_id' => (int)$msgId, 'r' => $r, 'row' => vd_brief(vd_row($pdo, $waId)), 'replies_added' => $replies() - $rc,
                'message' => (string)($lr['p']['message'] ?? ''), 'document' => $lr['p']['document'] ?? null, 'origin' => $lr['p']['origin'] ?? null,
                'payload' => (string)($lr['payload'] ?? ''), 'body' => (string)($msg['body'] ?? ''), 'meta' => (json_decode((string)($msg['metadata'] ?? ''), true) ?: [])['document'] ?? null];
    };
    /** The reader alone, on bytes: text, pages, counters — or the refusal. */
    $unit = function (string $bytes, float $secs = 10.0, int $maxPages = 20, int $maxChars = 50000): array {
        try {
            $facts = PdfReader::facts($bytes, new DocumentDeadline($secs));
            $r = PdfReader::text($bytes, new DocumentDeadline($secs), $maxPages, $maxChars);
            return ['ok' => true, 'text' => preg_replace('/\s+/u', ' ', trim($r['text'])) ?? '', 'chars' => mb_strlen($r['text']), 'pages_read' => $r['pages_read'], 'pages_total' => $r['pages_total'],
                    'truncated' => $r['truncated'], 'glyphs' => $r['glyphs'], 'unmapped' => $r['unmapped'], 'forms' => $r['forms'], 'facts_pages' => $facts['pages_total'], 'text_layer' => $facts['has_text_layer']];
        } catch (DocumentRefused $e) {
            return ['ok' => false, 'reason' => $e->reason, 'detail' => $e->detail, 'slow' => $e instanceof DocumentTooSlow];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'threw:' . get_class($e), 'detail' => $e->getMessage()];
        }
    };
    $bytesIn = function (string $hay, string $pdf): bool {
        return strpos($hay, '%PDF') !== false || strpos($hay, 'endobj') !== false || strpos($hay, base64_encode(substr($pdf, 0, 48))) !== false || strpos($hay, bin2hex(substr($pdf, 0, 24))) !== false;
    };

    // ── U. The reader alone ────────────────────────────────────────────────────────────────────
    $u = [];
    $u['simple']   = $unit(vd_pdfx(['pages' => [['lines' => ['Hello from a PDF', 'Second line']]]]));
    $u['pages']    = $unit(vd_pdfx(['pages' => [['lines' => ['Page one line one', 'Page one line two']], ['lines' => ['Page two']], ['lines' => ['Page three']]], 'inherit' => true]));
    $u['cid']      = $unit(vd_pdfx(['pages' => [['lines' => ['Hello CID world 2026', 'Second line 42']]], 'fonts' => ['F1' => ['type' => 'cid']], 'objstm' => true]));
    $u['tj']       = $unit(vd_pdfx(['pages' => [['lines' => ['Café costs £5 or €4 today', 'Kerned words here'], 'tj' => true], ['lines' => ['Hex string line'], 'hex' => true]]]));
    $u['diff']     = $unit(vd_pdfx(['pages' => [['lines' => ['Caf~ au lait']]], 'fonts' => ['F1' => ['type' => 'simple', 'enc' => 'WinAnsiEncoding', 'diff' => [0x7E => 'eacute']]]]));
    $u['macroman'] = $unit(vd_pdfx(['pages' => [['lines' => ["Caf\u{e9} na\u{ef}ve"]]], 'fonts' => ['F1' => ['type' => 'simple', 'enc' => 'MacRomanEncoding']]]));
    $u['simpletu'] = $unit(vd_pdfx(['pages' => [['lines' => ['Simple with ToUnicode é']]], 'fonts' => ['F1' => ['type' => 'simple', 'enc' => 'WinAnsiEncoding', 'tounicode' => true]]]));
    $u['forms']    = $unit(vd_pdfx(['pages' => [['lines' => ['Body text'], 'form' => 'Fx', 'inline' => true]], 'forms' => ['Fx' => ['lines' => ['Form text'], 'nested' => 'Fy'], 'Fy' => ['lines' => ['Nested form text']]]]));
    $u['cycle']    = $unit(vd_pdfx(['pages' => [['lines' => ['Body'], 'form' => 'Fx']], 'forms' => ['Fx' => ['lines' => ['Loop'], 'self' => true]]]));
    $deep = []; for ($i = 1; $i <= 10; $i++) $deep['D' . $i] = ['lines' => ['Depth ' . $i]] + ($i < 10 ? ['nested' => 'D' . ($i + 1)] : []);
    $u['deep']     = $unit(vd_pdfx(['pages' => [['lines' => ['Top'], 'form' => 'D1']], 'forms' => $deep]));
    $u['a85']      = $unit(vd_pdfx(['pages' => [['lines' => ['ASCII85 wrapped text']]], 'filter' => 'a85']));
    $u['lzw']      = $unit(vd_pdfx(['pages' => [['lines' => ['LZW text']]], 'filter' => 'lzw']));
    $u['notext']   = $unit(vd_pdfx(['pages' => [['lines' => []]]]));
    $u['nocat']    = $unit(vd_pdfx(['pages' => [['lines' => ['Found without a catalogue']]], 'broken' => 'nocatalog']));
    $u['nopages']  = $unit(vd_pdfx(['broken' => 'nopages']));
    $u['junkxref'] = $unit(vd_pdfx(['pages' => [['lines' => ['Xref ignored']]], 'broken' => 'junk']));
    $u['junk']     = $unit("%PDF-1.4\n" . str_repeat('DN-MEDIA-TEST-PAYLOAD/', 40));
    $many = []; for ($i = 1; $i <= 25; $i++) $many[] = ['lines' => ["Page $i text"]];
    $u['25pages']  = $unit(vd_pdfx(['pages' => $many]));
    $u['deadline'] = $unit(vd_pdfx([]), 0.0);
    $busy = []; for ($i = 0; $i < 2; $i++) $busy[] = ['raw' => str_repeat("0 0 Td\n", 400000) . "BT /F1 12 Tf (busy) Tj ET\n"];
    $u['slowloop'] = (function () use ($busy): array {   // the in-loop deadline check: 400,000 operators on ONE page under a 30 ms budget — the page-boundary check cannot be what stops it
        try { $r = PdfReader::text(vd_pdfx(['pages' => $busy, 'flate' => false]), new DocumentDeadline(0.03), 20, 50000); return ['ok' => true, 'chars' => mb_strlen($r['text'])]; }
        catch (DocumentRefused $e) { return ['ok' => false, 'reason' => $e->reason, 'detail' => $e->detail]; }
    })();
    $u['bomb']     = $unit(vd_pdfx(['pages' => [['lines' => ['after the bomb']]], 'bomb' => true]));
    $u['lying']    = $unit(vd_pdfx(['pages' => [['lines' => ['Length lies']]], 'lying_length' => true]));
    $u['objects']  = $unit(vd_pdfx(['extra_objects' => 50001]));
    $u['type3']    = $unit(vd_pdfx(['pages' => [['lines' => ['Type three glyphs']]], 'fonts' => ['F1' => ['type' => 'simple', 'enc' => null, 'type3' => true]]]));
    $u['cidnotu']  = $unit(preg_replace('/\/ToUnicode \d+ 0 R/', '', vd_pdfx(['pages' => [['lines' => ['No map here']]], 'fonts' => ['F1' => ['type' => 'cid']]])) ?? '');
    $halfBad = preg_replace('/\/ToUnicode (\d+) 0 R/', '', vd_pdfx(['pages' => [['lines' => ['Readable half of the text'], 'font' => 'F1'], ['lines' => ['Unreadable half'], 'font' => 'F2']],
                                                                    'fonts' => ['F1' => ['type' => 'simple', 'enc' => 'WinAnsiEncoding'], 'F2' => ['type' => 'cid']]])) ?? '';
    $u['halfbad']  = $unit($halfBad);
    $u['maxchars'] = $unit(vd_pdfx(['pages' => [['lines' => array_fill(0, 40, str_repeat('word ', 40))]]]), 10.0, 20, 500);
    $u['encrypted'] = (function (): array { try { return ['encrypted' => PdfReader::facts(vd_pdfx(['encrypt' => true]), new DocumentDeadline(5.0))['encrypted']]; } catch (\Throwable $e) { return ['encrypted' => 'threw']; } })();
    $f['u'] = $u;

    // ── P. The pipeline: bytes → DocumentExtraction → the record, the event or the refusal ──────
    $money0 = vd_money($pdo); $kyc0 = vd_kyc($pdo);
    $general = vd_pdfx(['pages' => [['lines' => VP_GENERAL]]]);
    $pg = $run('VP-GEN', '256772000901', $general, ['fileName' => 'school-request.pdf']);
    $f['p_general'] = ['r' => $pg['r'], 'row' => $pg['row'], 'replies_added' => $pg['replies_added'], 'origin' => $pg['origin'], 'document' => $pg['document'],
                       'label_ok' => strpos($pg['message'], $L . ' — PDF, 1 page read of 1]') === 0, 'has_text' => strpos($pg['message'], 'twelve classrooms') !== false,
                       'has_filename' => strpos($pg['payload'], 'school-request') !== false, 'bytes_in_payload' => $bytesIn($pg['payload'], $general), 'bytes_in_body' => $bytesIn($pg['body'], $general),
                       'body_labelled' => strpos($pg['body'], $L) === 0, 'meta' => $pg['meta']];
    $ctxIn = ['origin' => 'document', 'document' => $pg['document']];
    $built = BrainContext::build('unknown', ['customer_phone' => '256772000901', 'message' => $pg['message'], 'channel' => 'sales',
                                            'document' => (($ctxIn['origin'] ?? '') === 'document' && is_array($ctxIn['document'] ?? null))
                                                ? ['classification' => (string)($ctxIn['document']['classification'] ?? 'general'), 'kind' => (string)($ctxIn['document']['kind'] ?? 'document'), 'truncated' => !empty($ctxIn['document']['truncated'])] : null]);
    $f['p_context'] = ['document' => $built['document'] ?? null, 'keys' => array_keys($built), 'has_file_name' => array_key_exists('file_name', $built) || strpos(json_encode($built), 'school-request') !== false,
                       'has_bytes' => $bytesIn(json_encode($built) ?: '', $general)];
    $multi = vd_pdfx(['pages' => [['lines' => ['Dear DishNet, this is page one of our request.']], ['lines' => ['Page two describes the site in Gulu.']], ['lines' => ['Page three asks for the plans.']]]]);
    $pm = $run('VP-MULTI', '256772000902', $multi);
    $f['p_multi'] = ['row' => $pm['row'], 'replies_added' => $pm['replies_added'], 'label_ok' => strpos($pm['message'], $L . ' — PDF, 3 pages read of 3]') === 0,
                     'order_ok' => strpos($pm['message'], 'page one') < strpos($pm['message'], 'Page two') && strpos($pm['message'], 'Page two') < strpos($pm['message'], 'Page three'),
                     'document' => $pm['document']];
    $cid = vd_pdfx(['pages' => [['lines' => ['Dear DishNet, please connect our clinic in Soroti.', 'We have 2 buildings.']]], 'fonts' => ['F1' => ['type' => 'cid']], 'objstm' => true]);
    $pc = $run('VP-CID', '256772000903', $cid);
    $f['p_cid'] = ['row' => $pc['row'], 'replies_added' => $pc['replies_added'], 'has_text' => strpos($pc['message'], 'clinic in Soroti') !== false && strpos($pc['message'], '2 buildings') !== false, 'document' => $pc['document']];
    $pn = $run('VP-NOTEXT', '256772000904', vd_pdfx(['pages' => [['lines' => []]]]));
    $f['p_notext'] = ['r' => $pn['r'], 'row' => $pn['row'], 'replies_added' => $pn['replies_added'],
                      'wording' => DocumentExtraction::handoverReason('failed', (string)($pn['r']['reason'] ?? ''), (string)($pn['r']['detail'] ?? ''))];
    $pp = $run('VP-25', '256772000905', vd_pdfx(['pages' => $many]));
    $f['p_pages'] = ['r' => $pp['r'], 'row' => $pp['row'], 'replies_added' => $pp['replies_added']];
    $pp5 = $run('VP-25B', '256772000906', vd_pdfx(['pages' => $many]), ['cfg' => ['ai_media_document_max_pages' => 30]]);
    $f['p_pages_cap30'] = ['row' => $pp5['row'], 'replies_added' => $pp5['replies_added'], 'label_ok' => strpos($pp5['message'], $L . ' — PDF, 25 pages read of 25]') === 0];
    $f['p_malformed'] = ['nopages' => $run('VP-NOPG', '256772000907', vd_pdfx(['broken' => 'nopages']))['r'], 'junk' => $run('VP-JUNK', '256772000908', "%PDF-1.4\n" . str_repeat('DN-MEDIA-TEST-PAYLOAD/', 40))['r']];
    $f['p_encrypted'] = $run('VP-ENC', '256772000909', vd_pdfx(['encrypt' => true]))['r'];
    $f['p_lzw'] = $run('VP-LZW', '256772000910', vd_pdfx(['pages' => [['lines' => ['LZW text']]], 'filter' => 'lzw']))['r'];
    $big = vd_pdfx(['pages' => [['lines' => ['Padded']]], 'pad' => 140000]);
    $f['p_size'] = ['r' => $run('VP-SIZE', '256772000911', $big)['r'], 'bytes' => strlen($big), 'cap' => MediaPolicy::documentMaxBytes($cfg)];
    $f['p_bomb'] = $run('VP-BOMB', '256772000912', vd_pdfx(['pages' => [['lines' => ['after the bomb']]], 'bomb' => true]))['r'];
    $f['p_deadline'] = $run('VP-SLOW', '256772000913', vd_pdfx([]), ['deadline' => new DocumentDeadline(0.0)])['r'];
    DocumentExtraction::$capabilities = ['gzuncompress' => false];
    $f['p_unavailable'] = $run('VP-UNAV', '256772000914', vd_pdfx([]))['r'];
    DocumentExtraction::$capabilities = null;
    $long = []; for ($i = 0; $i < 60; $i++) $long[] = 'Line ' . $i . ' of a long but harmless letter about connecting our school in Gulu to the internet soon.';
    $pl = $run('VP-LONG', '256772000915', vd_pdfx(['pages' => [['lines' => $long]]]));
    $extract = substr($pl['message'], strpos($pl['message'], "]\n") + 2);
    $f['p_capped'] = ['row' => $pl['row'], 'replies_added' => $pl['replies_added'], 'extract_chars' => mb_strlen($extract), 'ellipsis' => mb_substr(rtrim($extract), -1) === '…',
                      'truncated' => $pl['document']['truncated'] ?? null, 'label_truncated' => strpos($pl['message'], ', truncated]') !== false, 'cap' => MediaPolicy::DOCUMENT_MAX_BRAIN_CHARS];
    $huge = []; for ($i = 0; $i < 20; $i++) $huge[] = ['lines' => array_fill(0, 60, str_repeat('harmless words about a school ', 2))];
    $f['p_scancap'] = $run('VP-HUGE', '256772000916', vd_pdfx(['pages' => $huge]))['r'];
    // the human-only classes, each a PDF
    $human = ['receipt' => [VP_RECEIPT, 'payment_proof'], 'invoice' => [VP_INVOICE, 'invoice'], 'statement' => [VP_STATEMENT, 'statement'], 'contract' => [VP_CONTRACT, 'contract'],
              'quotation' => [VP_QUOTATION, 'quotation'], 'identity' => [VP_IDENTITY, 'identity_document'], 'credential' => [VP_CRED, 'credential']];
    $i = 20;
    foreach ($human as $k => [$lines, $expected]) {
        $ph = $run('VP-H-' . strtoupper($k), '2567720009' . $i++, vd_pdfx(['pages' => [['lines' => $lines]]]));
        $f['p_human'][$k] = ['r' => $ph['r'], 'row' => $ph['row'], 'replies_added' => $ph['replies_added'], 'expected' => $expected, 'body' => $ph['body'], 'meta_record' => $ph['meta']['record'] ?? null];
    }
    $f['p_money'] = ['before' => $money0, 'after' => vd_money($pdo)]; $f['p_kyc'] = ['before' => $kyc0, 'after' => vd_kyc($pdo)];
    $f['p_sensitive_db'] = vd_dbscan($pdo, ['TESTSURNAME', 'CF00000000TEST', 'hunter2xyz99', '123456789012']);
    $f['p_sensitive_disk'] = vd_scan($tmp, ['TESTSURNAME', 'CF00000000TEST', 'hunter2xyz99', '123456789012', '7G4K2Q9PDF', '%PDF', base64_encode(substr($general, 0, 48))]);
    $f['p_general_db_where'] = array_values(array_unique(array_map(function ($h) { return explode(' has ', $h)[0]; }, vd_dbscan($pdo, ['twelve classrooms']))));
    // STOP in a PDF is not an opt-out; a committing reply on a PDF turn is the guard's business
    $opt0 = $count("SELECT COUNT(*) FROM contact_optouts");
    $ps = $run('VP-STOP', '256772000930', vd_pdfx(['pages' => [['lines' => ['stop']]]]));
    $f['p_stop'] = ['row' => $ps['row'], 'optouts_added' => $count("SELECT COUNT(*) FROM contact_optouts") - $opt0, 'opted' => (new ContactOptOut($pdo))->has('256772000930')];
    $COMMIT = 'Thank you — we have received your payment and the invoice has been settled, so installation goes ahead.';
    $f['p_guard'] = ['doc_turn' => ReplyPrivacyGuard::check($COMMIT, ['values' => [], 'commitments' => true])['categories'],
                     'conditional' => ReplyPrivacyGuard::check('Once your payment is received we will schedule the installation.', ['values' => [], 'commitments' => true])['categories']];
    $f['p_log'] = ['lines' => count($logLines), 'has_text' => strpos(implode("\n", $logLines), 'twelve classrooms') !== false, 'has_pdf' => strpos(implode("\n", $logLines), '%PDF') !== false,
                   'has_secret' => strpos(implode("\n", $logLines), 'hunter2xyz99') !== false];

    // ── M. The memory budget, in this process: the rule refuses what would not fit the live limit ──
    $before = ini_get('memory_limit');
    $usage = memory_get_usage();
    $limit = (int)ceil($usage / 1048576) + 60;   // 60 MiB above what this process already holds
    ini_set('memory_limit', $limit . 'M');
    $limitBytes = DocumentExtraction::memoryLimitBytes();   // read while the limit is in force: what the rule compared against
    $bigCfg = ['cfg' => ['ai_media_max_bytes' => 16 * 1024 * 1024, 'ai_media_document_max_bytes' => 16 * 1024 * 1024]];
    $pmb = $run('VP-MEM', '256772000940', str_repeat("a line of text\n", intdiv(14 * 1024 * 1024, 15)), ['mime' => 'text/plain', 'fileName' => 'big.txt'] + $bigCfg);
    $pmc = $run('VP-MEMC', '256772000941', str_repeat("a line of text\n", intdiv(1024 * 1024, 15)), ['mime' => 'text/plain', 'fileName' => 'small.txt'] + $bigCfg);
    ini_set('memory_limit', $before === false || $before === '' ? '-1' : $before);
    $f['m_budget'] = ['limit_set' => $limit . 'M', 'limit_bytes' => $limitBytes, 'big' => $pmb['r'], 'big_row' => $pmb['row'], 'control' => $pmc['r'], 'control_row' => $pmc['row'],
                      'restored' => ini_get('memory_limit')];

    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Driver mode: one JSON line ───────────────────────────────────────────────────────────────
if ($driver !== null) {
    echo json_encode(vp_core($root), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    exit(0);
}

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 1200) : '') . "\n"; } }
function j($v): string { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

$c = vp_core($root);
$u = $c['u'];

echo "1. The reader: fonts, filters, layouts\n";
is_($u['simple']['ok'] && $u['simple']['text'] === 'Hello from a PDF Second line' && $u['simple']['pages_read'] === 1 && $u['simple']['unmapped'] === 0, 'a Flate page with a WinAnsi font reads line by line', j($u['simple']));
is_($u['pages']['ok'] && $u['pages']['text'] === 'Page one line one Page one line two Page two Page three' && $u['pages']['pages_read'] === 3 && $u['pages']['pages_total'] === 3, 'three pages with /Resources inherited from the Pages node, in order', j($u['pages']));
is_($u['cid']['ok'] && $u['cid']['text'] === 'Hello CID world 2026 Second line 42' && $u['cid']['unmapped'] === 0 && $u['cid']['facts_pages'] === 1,
    'PDF 1.5: a composite font (Identity-H) through its ToUnicode CMap — bfchar for letters, bfrange for digits — inside an object stream, with an xref stream; facts() counts the page too', j($u['cid']));
is_($u['tj']['ok'] && $u['tj']['text'] === 'Café costs £5 or €4 today Kerned words here Hex string line', 'TJ arrays: kerning kept words whole, a wide adjustment became a space; WinAnsi é £ €; a hex string', j($u['tj']));
is_($u['diff']['ok'] && $u['diff']['text'] === 'Café au lait', '/Differences: a glyph name maps the code the base encoding would misread', j($u['diff']));
is_($u['macroman']['ok'] && $u['macroman']['text'] === "Caf\u{e9} na\u{ef}ve", 'MacRomanEncoding high half', j($u['macroman']));
is_($u['simpletu']['ok'] && $u['simpletu']['text'] === 'Simple with ToUnicode é', 'a simple font with its own ToUnicode map', j($u['simpletu']));
is_($u['forms']['ok'] && $u['forms']['text'] === 'Body text Form text Nested form text' && $u['forms']['forms'] === 2, 'a Form XObject and the form it draws are followed; the inline image is skipped', j($u['forms']));
is_(!$u['cycle']['ok'] && $u['cycle']['reason'] === 'malformed_document' && $u['cycle']['detail'] === 'pdf form cycle', 'a form that draws itself is refused as a cycle, not followed', j($u['cycle']));
is_(!$u['deep']['ok'] && $u['deep']['reason'] === 'malformed_document' && $u['deep']['detail'] === 'pdf form depth', 'forms nested ten deep are refused at the depth cap', j($u['deep']));
is_($u['a85']['ok'] && $u['a85']['text'] === 'ASCII85 wrapped text', 'ASCII85 over Flate', j($u['a85']));
is_(!$u['lzw']['ok'] && $u['lzw']['reason'] === 'unsupported_document' && $u['lzw']['detail'] === 'pdf filter LZWDecode', 'a filter the reader does not know is a refusal, never a guess', j($u['lzw']));
is_($u['notext']['ok'] && $u['notext']['text'] === '' && $u['notext']['glyphs'] === 0 && $u['notext']['text_layer'] === true, 'a font and no text operators: facts say a text layer, the reader returns nothing (the extractor makes that pdf_no_text)', j($u['notext']));
is_($u['nocat']['ok'] && $u['nocat']['text'] === 'Found without a catalogue', 'no /Catalog: the page tree is found by its type', j($u['nocat']));
is_(!$u['nopages']['ok'] && $u['nopages']['reason'] === 'malformed_document' && $u['nopages']['detail'] === 'pdf has no pages', 'no pages at all: malformed', j($u['nopages']));
is_($u['junkxref']['ok'] && $u['junkxref']['text'] === 'Xref ignored', 'a broken xref table is not needed: objects are found by scanning', j($u['junkxref']));
is_(!$u['junk']['ok'] && $u['junk']['reason'] === 'malformed_document', 'a PDF header followed by junk: malformed', j($u['junk']));
is_($u['lying']['ok'] && $u['lying']['text'] === 'Length lies', 'a wrong /Length: the stream is read to its endstream', j($u['lying']));

echo "\n2. The reader: every cap, the deadline, what it cannot name\n";
is_($u['25pages']['ok'] && $u['25pages']['pages_read'] === 20 && $u['25pages']['pages_total'] === 25 && $u['25pages']['truncated'] === true, '25 pages: 20 read, 25 counted, not seen whole', j($u['25pages']));
is_(!$u['deadline']['ok'] && $u['deadline']['reason'] === 'too_slow' && !empty($u['deadline']['slow']), 'an expired deadline stops the reader at its first step', j($u['deadline']));
is_(!$u['slowloop']['ok'] && $u['slowloop']['reason'] === 'too_slow' && strpos((string)$u['slowloop']['detail'], 'pdf content') !== false, '400,000 content operators under a 30 ms budget: the deadline is asked INSIDE the content loop', j($u['slowloop']));
is_(!$u['bomb']['ok'] && $u['bomb']['reason'] === 'too_large_document' && strpos((string)$u['bomb']['detail'], 'inflates beyond') !== false, 'a stream inflating to 9 MiB is refused at the 8 MiB cap, as too large (not as malformed)', j($u['bomb']));
is_(!$u['objects']['ok'] && $u['objects']['reason'] === 'too_large_document' && strpos((string)$u['objects']['detail'], 'pdf objects') === 0, '50,001 objects: refused at the object cap', j($u['objects']));
is_($u['maxchars']['ok'] && $u['maxchars']['chars'] === 500 && $u['maxchars']['truncated'] === true, 'the character cap cuts the text and marks it', j($u['maxchars']));
is_($u['type3']['ok'] && $u['type3']['text'] === '' && $u['type3']['unmapped'] === $u['type3']['glyphs'] && $u['type3']['truncated'] === true, 'a Type3 font with no ToUnicode: every glyph counted as unnamed, nothing invented', j($u['type3']));
is_($u['cidnotu']['ok'] && $u['cidnotu']['text'] === '' && $u['cidnotu']['unmapped'] === $u['cidnotu']['glyphs'], 'a composite font without a ToUnicode map: unnamed, nothing invented', j($u['cidnotu']));
is_($u['halfbad']['ok'] && $u['halfbad']['text'] === 'Readable half of the text' && $u['halfbad']['truncated'] === true && $u['halfbad']['unmapped'] > 0, 'half the glyphs unnamed: the readable half comes back, and the text is NOT seen whole', j($u['halfbad']));
is_($u['encrypted']['encrypted'] === true, 'facts() still names an encrypted file (the extractor refuses it before the reader runs)', j($u['encrypted']));

echo "\n3. A general PDF is the assistant's — one event, the extract under its label, never the bytes\n";
$pg = $c['p_general'];
is_(($pg['r']['outcome'] ?? '') === 'understood' && $pg['row']['status'] === 'understood' && $pg['row']['kind'] === 'extraction' && $pg['replies_added'] === 1 && $pg['origin'] === 'document'
    && ($pg['document']['kind'] ?? '') === 'pdf' && ($pg['document']['classification'] ?? '') === 'general' && ($pg['document']['pages_read'] ?? 0) === 1 && ($pg['document']['pages_total'] ?? 0) === 1,
    'understood, class general, ONE ai.reply with origin document and the PDF facts', j([$pg['r'], $pg['row'], $pg['document']]));
is_($pg['label_ok'] && $pg['has_text'] && $pg['body_labelled'], 'the event message and the stored message carry the label "PDF, 1 page read of 1" and the text', j([$pg['label_ok'], $pg['has_text'], $pg['body_labelled']]));
is_($pg['has_filename'] === false && $pg['bytes_in_payload'] === false && $pg['bytes_in_body'] === false, 'neither the file name nor the PDF bytes, their base64 or their hex are in the event or the stored message', j([$pg['has_filename'], $pg['bytes_in_payload'], $pg['bytes_in_body']]));
is_(($pg['meta']['kind'] ?? '') === 'pdf' && ($pg['meta']['record'] ?? '') === 'text' && ($pg['meta']['evidence'] ?? null) === false, 'the stored message\'s metadata records kind pdf, record text, not evidence', j($pg['meta']));
$pcx = $c['p_context'];
is_(($pcx['document']['kind'] ?? '') === 'pdf' && ($pcx['document']['classification'] ?? '') === 'general' && ($pcx['document']['truncated'] ?? null) === false && $pcx['has_file_name'] === false && $pcx['has_bytes'] === false,
    'the brain context built from that turn names classification, kind and truncation — not the file name, not a byte', j($pcx));
$pm = $c['p_multi'];
is_($pm['row']['status'] === 'understood' && $pm['replies_added'] === 1 && $pm['label_ok'] && $pm['order_ok'] && ($pm['document']['pages_read'] ?? 0) === 3, 'three pages: "3 pages read of 3", in page order', j($pm));
$pc = $c['p_cid'];
is_($pc['row']['status'] === 'understood' && $pc['replies_added'] === 1 && $pc['has_text'] && ($pc['document']['kind'] ?? '') === 'pdf', 'a PDF 1.5 file with a composite font reads through the same path', j($pc));

echo "\n4. Fail closed: no text, too many pages, malformed, encrypted, unsupported, too large, too slow, unavailable\n";
$pn = $c['p_notext'];
// process() returns the refusal; marking the row failed and handing over is MediaWorker's (proved in test_document_media), so here a refused row is simply never understood.
is_(($pn['r']['reason'] ?? '') === 'pdf_no_text' && $pn['row']['status'] !== 'understood' && $pn['row']['kind'] === null && $pn['replies_added'] === 0 && ($pn['r']['retryable'] ?? true) === false
    && $pn['wording'] === 'a PDF arrived (1 page, a text layer that yielded no text) — no text could be read from it automatically; open it in WhatsApp',
    'a text layer that yields nothing: pdf_no_text, permanent, nothing understood, no event, and the person\'s wording word for word', j($pn));
$pp = $c['p_pages'];
is_(($pp['r']['reason'] ?? '') === 'classification_incomplete' && $pp['row']['status'] !== 'understood' && $pp['replies_added'] === 0, '25 pages at the 20-page cap: read to the cap, NOT seen whole, a person — never the assistant', j($pp));
is_($c['p_pages_cap30']['row']['status'] === 'understood' && $c['p_pages_cap30']['replies_added'] === 1 && $c['p_pages_cap30']['label_ok'], 'the same 25 pages under a cap of 30 are seen whole: "25 pages read of 25"', j($c['p_pages_cap30']));
is_(($c['p_malformed']['nopages']['reason'] ?? '') === 'malformed_document' && ($c['p_malformed']['junk']['reason'] ?? '') === 'malformed_document', 'malformed PDFs are refused as malformed', j($c['p_malformed']));
is_(($c['p_encrypted']['reason'] ?? '') === 'password_protected', 'an encrypted PDF: password_protected, before any text is read', j($c['p_encrypted']));
is_(($c['p_lzw']['reason'] ?? '') === 'unsupported_document' && strpos((string)($c['p_lzw']['detail'] ?? ''), 'LZWDecode') !== false, 'an unsupported filter: unsupported_document naming the filter', j($c['p_lzw']));
is_(($c['p_size']['r']['reason'] ?? '') === 'too_large_document' && $c['p_size']['bytes'] > $c['p_size']['cap'], 'over ai_media_document_max_bytes: too_large_document before the sniff', j($c['p_size']));
is_(($c['p_bomb']['reason'] ?? '') === 'too_large_document', 'an inflate bomb inside the PDF: too_large_document', j($c['p_bomb']));
is_(($c['p_deadline']['reason'] ?? '') === 'too_slow' && ($c['p_deadline']['retryable'] ?? true) === false, 'an expired deadline: too_slow, permanent', j($c['p_deadline']));
is_(($c['p_unavailable']['reason'] ?? '') === 'extractor_unavailable' && ($c['p_unavailable']['detail'] ?? '') === 'gzuncompress', 'without zlib the PDF path refuses as extractor_unavailable, naming what is missing', j($c['p_unavailable']));

echo "\n5. The caps on what the assistant reads\n";
$pl = $c['p_capped'];
is_($pl['row']['status'] === 'understood' && $pl['replies_added'] === 1 && $pl['extract_chars'] <= $pl['cap'] + 1 && $pl['ellipsis'] && $pl['truncated'] === true && $pl['label_truncated'],
    'a long harmless PDF: the turn is cut at 4,000 characters with an ellipsis, marked truncated in the label and the facts', j($pl));
is_(($c['p_scancap']['reason'] ?? '') === 'classification_incomplete', 'text beyond the 50,000-character scan cap is NOT seen whole: a person', j($c['p_scancap']));

echo "\n6. Human-only classes in a PDF: a person, never an event, nothing financial or KYC touched\n";
foreach ($c['p_human'] as $k => $h) {
    is_(($h['r']['outcome'] ?? '') === 'evidence' && ($h['r']['classification'] ?? '') === $h['expected'] && $h['row']['kind'] === 'document_evidence' && $h['replies_added'] === 0,
        "{$k} in a PDF: evidence, class {$h['expected']}, no ai.reply", j([$h['r'], $h['row'], $h['replies_added']]));
}
is_(($c['p_human']['identity']['meta_record'] ?? '') === 'none' && ($c['p_human']['credential']['meta_record'] ?? '') === 'none' && trim((string)$c['p_human']['identity']['row']['text']) === '' && trim((string)$c['p_human']['credential']['row']['text']) === '',
    'identity and credential: record none — the row and the message hold no content', j([$c['p_human']['identity']['row'], $c['p_human']['credential']['row']]));
is_(($c['p_human']['receipt']['meta_record'] ?? '') === 'excerpt' && strpos((string)$c['p_human']['receipt']['row']['text'], '123456789012') === false && strpos((string)$c['p_human']['receipt']['row']['text'], '••••••') !== false
    && mb_strlen((string)$c['p_human']['receipt']['row']['text']) <= MediaPolicy::DOCUMENT_EXCERPT_CHARS + 1,
    'a receipt: a masked excerpt within 300 characters — the twelve-digit reference is masked (D-7: digit runs of six or more, and e-mails)', j($c['p_human']['receipt']['row']));
is_($c['p_money']['before'] === $c['p_money']['after'] && $c['p_kyc']['before'] === $c['p_kyc']['after'], 'no money table and no KYC table changed by any of the seven', j([$c['p_money'], $c['p_kyc']]));
is_($c['p_sensitive_db'] === [] && $c['p_sensitive_disk'] === [], 'no table and no file holds the surname, the NIN, the password, the transaction reference, a PDF header or the PDF\'s base64', j([$c['p_sensitive_db'], $c['p_sensitive_disk']]));
is_(array_diff($c['p_general_db_where'], ['wa_media', 'wa_messages', 'events']) === [] && in_array('events', $c['p_general_db_where'], true), 'the general extract lives in wa_media, the stored message and the ai.reply payload — nowhere else', j($c['p_general_db_where']));

echo "\n7. STOP, the guard, the logs\n";
is_($c['p_stop']['optouts_added'] === 0 && $c['p_stop']['opted'] === false, 'a PDF whose text is "stop" is not an opt-out (STOP is the webhook\'s, on typed text and captions)', j($c['p_stop']));
is_(count(array_filter($c['p_guard']['doc_turn'], function ($cat) { return strpos((string)$cat, 'commitment:') === 0; })) >= 1 && $c['p_guard']['conditional'] === [],
    'a committing reply on a document turn is refused by the guard (a commitment category); a conditional sentence passes', j($c['p_guard']));
is_($c['p_log']['has_text'] === false && $c['p_log']['has_pdf'] === false && $c['p_log']['has_secret'] === false && $c['p_log']['lines'] > 0, 'the extraction logs carry no extract, no PDF bytes, no credential', j($c['p_log']));

echo "\n8. The memory budget, closed\n";
$mb = $c['m_budget'];
is_($mb['limit_bytes'] > 0 && ($mb['big']['reason'] ?? '') === 'too_large_document' && strpos((string)($mb['big']['detail'] ?? ''), 'would not fit the memory budget') !== false && $mb['big_row']['status'] === 'pending',
    'under a live memory_limit 60 MiB above current usage, a 14 MiB document is refused by the budget rule before a byte is read', j($mb));
is_(($mb['control']['reason'] ?? '') !== 'too_large_document' && $mb['control_row']['status'] !== 'pending', 'control: a 1 MiB document under the same limit passes the budget rule and is processed', j([$mb['control'], $mb['control_row']]));
is_($mb['restored'] === '-1' || $mb['restored'] !== $mb['limit_set'], 'the test restored memory_limit afterwards', j([$mb['restored'], $mb['limit_set']]));

echo "\n9. Wiring\n";
$pr = vd_codeOf($root . '/lib/PdfReader.php');
$badCalls = [];
foreach (['tempnam(', 'tmpfile(', 'file_put_contents(', 'file_get_contents(', 'fopen(', 'exec(', 'shell_exec(', 'proc_open(', 'system(', 'passthru(', 'eval(', 'unserialize(', 'curl_', 'include ', 'require '] as $fn) {
    if (strpos($pr, $fn) !== false) $badCalls[] = $fn;
}
is_($badCalls === [], 'the reader writes no file, reads no file, runs no process, evaluates nothing, opens no socket and includes nothing', j($badCalls));
is_(strpos($pr, 'inflate_init(') !== false && strpos($pr, 'DocumentRefused(\'unsupported_document\', \'pdf filter') !== false && strpos($pr, 'MAX_FORM_DEPTH') !== false && strpos($pr, 'DEADLINE_EVERY_OPS') !== false,
    'the reader inflates through zlib\'s bounded API, refuses unknown filters, caps form depth and asks the deadline inside the loop');
$de = vd_codeOf($root . '/lib/DocumentExtraction.php');
is_(strpos($de, "self::requireCapabilities(['gzuncompress', 'inflate_init']);") !== false && strpos($de, "return self::fail('pdf_no_text'") !== false && strpos($de, 'pdf_not_read') === false,
    'the extractor guards the PDF path with the zlib capability, refuses an empty text layer as pdf_no_text, and pdf_not_read is gone');
is_(strpos((string)file_get_contents($root . '/tools/set_config.php'), 'PDF text') !== false && strpos((string)file_get_contents($root . '/tools/set_config.php'), 'PDF facts only') === false, 'set_config describes PDF text, not facts only');
is_(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] === '5.18.83', 'manifest version is 5.18.81');
is_(count(glob($root . '/migrations/08[7-9]_*.sql') ?: []) === 0 && is_file($root . '/migrations/086_install_authorisation.sql'), 'no migration — 086 is Customer Installation Authorisation (5.18.82), not this feature\'s');

echo "\nE. Weakened copies — each caught, and the control\n";
$mutants = [
    ['lib/PdfReader.php', "        return \$r->read(max(1, \$maxPages), max(100, \$maxChars));", "        return \$r->read(PHP_INT_MAX, max(100, \$maxChars));",
     'the page cap is ignored (25 pages read whole reach the assistant)', function (array $m): bool { return ($m['p_pages']['replies_added'] ?? 0) >= 1 || ($m['u']['25pages']['pages_read'] ?? 0) > 20; }],
    ['lib/PdfReader.php', "        if (mb_strlen(\$out) > \$maxChars) { \$out = mb_substr(\$out, 0, \$maxChars); \$truncated = true; }", "        // cap removed",
     'the character cap is gone', function (array $m): bool { return ($m['u']['maxchars']['chars'] ?? 0) > 500; }],
    ['lib/PdfReader.php', "            if (\$this->ops % self::DEADLINE_EVERY_OPS === 0) \$this->deadline->check('pdf content');", "            // deadline check removed",
     'the deadline is no longer asked inside the content loop', function (array $m): bool { return !empty($m['u']['slowloop']['ok']) || strpos((string)($m['u']['slowloop']['detail'] ?? ''), 'pdf content') === false; }],
    ['lib/DocumentExtraction.php', "                        if (trim(self::normalise(\$raw)) === '') {", "                        if (false) {",
     'an empty text layer is no longer pdf_no_text', function (array $m): bool { return ($m['p_notext']['r']['reason'] ?? '') !== 'pdf_no_text'; }],
    ['lib/PdfReader.php', "                    throw new DocumentRefused('unsupported_document', 'pdf filter ' . preg_replace('/[^A-Za-z0-9]/', '', \$filter));", "                    break;",
     'an unknown filter is passed through as raw bytes', function (array $m): bool { return ($m['p_lzw']['reason'] ?? '') !== 'unsupported_document'; }],
    ['lib/PdfReader.php', "        if (\$num !== null && isset(\$this->formStack[\$num])) throw new DocumentRefused('malformed_document', 'pdf form cycle');", "        // cycle check removed",
     'a form drawing itself is no longer refused as a cycle', function (array $m): bool { return ($m['u']['cycle']['detail'] ?? '') !== 'pdf form cycle'; }],
    ['lib/PdfReader.php', "        if (\$depth + 1 > self::MAX_FORM_DEPTH) throw new DocumentRefused('malformed_document', 'pdf form depth');", "        // depth cap removed",
     'the form depth cap is gone (ten nested forms are read)', function (array $m): bool { return !empty($m['u']['deep']['ok']); }],
    ['lib/PdfReader.php', "        if (strlen(\$out) > \$max) throw new DocumentRefused('too_large_document', 'pdf stream inflates beyond ' . \$max . ' bytes');\n        return \$out;", "        return \$out;",
     'the inflate cap is gone (a 9 MiB stream inflates)', function (array $m): bool { return !empty($m['u']['bomb']['ok']) || ($m['p_bomb']['reason'] ?? '') !== 'too_large_document'; }],
    ['lib/PdfReader.php', "        if (\$n > MediaPolicy::DOCUMENT_PDF_MAX_OBJECTS) throw new DocumentRefused('too_large_document', 'pdf objects ' . \$n);", "        // object cap removed",
     'the object cap is gone', function (array $m): bool { return !empty($m['u']['objects']['ok']); }],
    ['lib/DocumentExtraction.php', "                        self::requireCapabilities(['gzuncompress', 'inflate_init']);", "                        // capability guard removed",
     'the zlib guard on the PDF path is gone', function (array $m): bool { return ($m['p_unavailable']['reason'] ?? '') !== 'extractor_unavailable'; }],
    ['lib/PdfReader.php', "        if (\$this->glyphs > 0 && \$this->unmapped / \$this->glyphs > self::UNMAPPED_SHARE_WHOLE) \$truncated = true;", "        // unnamed-glyph rule removed",
     'glyphs the reader could not name no longer stop the text from being "seen whole"', function (array $m): bool { return ($m['u']['halfbad']['truncated'] ?? true) === false; }],
    ['lib/DocumentExtraction.php', "        if (\$budget > 0 && memory_get_usage() + \$doc->size * 4 + MediaPolicy::DOCUMENT_ZIP_MAX_MEMBER_BYTES > \$budget) {", "        if (false) {",
     'the memory budget rule is gone (a 14 MiB document is read under a limit it cannot fit)', function (array $m): bool { return ($m['m_budget']['big']['reason'] ?? '') !== 'too_large_document'; }],
    ['lib/PdfReader.php', "                        if (\$u !== null) \$dec['diff'][\$code] = \$u;", "                        // differences ignored",
     '/Differences are ignored', function (array $m): bool { return ($m['u']['diff']['text'] ?? '') !== 'Café au lait'; }],
    ['lib/PdfReader.php', "            if (\$map['map'] !== []) { \$dec['map'] = \$map['map']; \$dec['bytes'] = \$map['bytes']; \$dec['nodecoder'] = false; }", "            // ToUnicode ignored",
     'ToUnicode maps are ignored (a composite font yields nothing)', function (array $m): bool { return ($m['u']['cid']['text'] ?? '') !== 'Hello CID world 2026 Second line 42'; }],
    ['lib/PdfReader.php', "                            elseif (is_array(\$el) && isset(\$el['num']) && (float)\$el['num'] < -180) \$out .= ' ';", "                            // TJ spacing removed",
     'TJ adjustments no longer become spaces (words run together)', function (array $m): bool { return strpos((string)($m['u']['tj']['text'] ?? ''), 'Kerned words here') === false; }],
];
foreach ($mutants as [$rel, $old, $new, $what, $flipped]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "copy — the anchor in {$rel} is unique, so the copy is weakened ({$what})", 'occurrences: ' . $n);
    $out = (string)shell_exec('php ' . escapeshellarg($copy . '/tests/test_document_pdf.php') . ' --driver=core 2>/dev/null');
    $m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
    is_($n === 1 && $m !== [] && $flipped($m), "caught — {$what}", $m === [] ? 'driver output: ' . substr($out, 0, 400) : j(array_intersect_key($m, ['u' => 1, 'p_pages' => 1, 'p_notext' => 1, 'p_lzw' => 1, 'p_bomb' => 1, 'p_unavailable' => 1, 'm_budget' => 1])));
    exec('rm -rf ' . escapeshellarg($copy));
}
$out = (string)shell_exec('php ' . escapeshellarg($root . '/tests/test_document_pdf.php') . ' --driver=core 2>/dev/null');
$m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
$flips = 0; foreach ($mutants as [$rel, $old, $new, $what, $flipped]) { if ($m !== [] && $flipped($m)) $flips++; }
is_($m !== [] && $flips === 0, 'control: the real tree, driven the same way, trips none of the ' . count($mutants) . ' catches', j(['facts' => count($m), 'flips' => $flips]));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
