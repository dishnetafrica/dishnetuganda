<?php
// 5.18.104's rehearsal: its fixtures, written into the store through the CODE ROOT's own writers — the INSTALLED plugin's
// (5.18.103's before the deploy, 5.18.104's once it serves), never raw SQL: a staff account as the staff screen keeps it
// (SqliteStore::appendWithId('retailers.json', …), as 5.18.97's rehearsal writes its staff), a customer's conversation and
// its inbound messages as the webhook's processor stores them (ConversationService::ensureConversation, then
// WaInbound::normalise over an Evolution-shaped payload and ConversationService::storeMessage with WaMessageProcessor's
// fields), and an enquiry follow-up as the scan opens it, drafted and approved by a person (FollowUpService::open,
// ::draft, ::approve). Synthetic and fictitious: every name, phone and e-mail is the rehearsal's (RH104SECRET-…, the
// phones a range of its own, listed in its 0600 pattern files); staff first names only. Run as the store's user, with a
// vault of its own:
//   php rh104-seed.php <code root> <data dir> <ops as JSON: [{"op":…}, …]> [time zone]
// The ops:
//   {"op":"staff","name":…,"role":…,"phone":…|null,"email":…|null,"admin":bool,"active":bool}
//   {"op":"conv","phone":…,"channel":"sales"|"support"|"accounts","name":…,"msgs":[{"kind":"text"|"audio","text":…,"ago_min":n}]}
//        — every message inbound (the customer's); its Evolution id the rehearsal's (RH104…), so a re-run stores nothing twice
//   {"op":"enquiry","phone":…,"channel":…,"body":…,"by":…}   — the conversation's open follow-up, a SEND draft, approved by "by"
//   {"op":"quote","phone":…,"channel":…,"name":…,"ref":…,"in_text":…,"agent":…,"ago_min":n,"gap_min":m,"valid_days":d}
//        — a quotation sent on WhatsApp <ago_min> minutes ago, as the Send Quotation path stores its text (exactly as
//          rh104-live.php's quote mode does after the deploy): the customer's own question <gap_min> minutes before it, then
//          the quotation (ConversationService::storeMessage, outbound, event quote_manual, "📋 *Ref:* <ref>" and its
//          "⏳ *Valid Until:*" line — the send's Kampala date + <valid_days>). Its Evolution ids the rehearsal's (RH104…).
//   {"op":"quote_mail","path":"app"|"webhook","quote_id":n,"client_id":n,"number":…,"name":…,"mailer":<dir>[,"agent":<staff name>]}
//        — the quotation's e-mail through 5.18.103's own senders, to the rehearsal's fake relay on 127.0.0.1: <mailer> holds
//          the relay's email_settings.json (plain SMTP to 127.0.0.1, no password), never the live one (this sandbox has no
//          plugin mail: Part P's 5c finds it unset), and the client and its PDF are the stand-in uCRM's (127.0.0.1):
//            app      — path 1: QuotationService::emailQuotePdf() of the code root, its own seam for tests
//                       (newMailService(), "real callers get a MailService on the plugin data dir") given the relay's
//                       settings: the once-only claim, the client from uCRM, the PDF through QuotePdfSource, the send, and
//                       its line in <data dir>/quote_mail.log — the method's own writes, nothing of this helper's;
//            webhook  — path 3: what webhook.php's whQuotationEmail() does for a quote.add (the claim QEMAIL<id>,
//                       QuotePdfSource::fetch, CustomerEmailDispatcher::send('quotation', …, <quote id>, …), the claim
//                       released when the send fails), without the webhook's entry point (which would also send WhatsApp):
//                       the dispatcher's own customer_email_log row. The quotation e-mail's two switches are given on in
//                       the caller's config, as on a server whose admin turned them on; this sandbox's settings files are
//                       not changed, and where they hold a switch off the dispatcher says so and the op is refused.
//                       Where the code root holds lib/QuoteAgentCopy.php (5.18.104 serving), its two marked P7 hunks too, as
//                       whQuotationEmail runs them: on Uganda, QuoteAgentCopy::forWebhook() before the send (an address it
//                       resolves given to the dispatcher's agentCopy()), afterSend() and record() after it — the decision's
//                       fixed code in the op's output (p7=<code>, or p7=- when P7 is off).
//            app's "agent" — the actor: the retailers row of that name, given to emailQuotePdf() as the logged-in account
//                       the app path passes (QuoteAgentCopy::forActor's input); without it, [] (5.18.103's callers' shape).
//   {"op":"kyc_app","quote_id":n,"client_id":n,"sales_person":<staff name>}
//        — a KYC application holding a quote id, through the store's own record writer (SqliteStore::appendWithId, as the
//          KYC form keeps its applications): sales_person the staff name, retailer_id that account's, crm_client_id and
//          quote_id as the KYC flow records them once its quote is made — what the webhook path's agent rule reads
//          (QuoteSendEvidence::kycAgent, rule 1)
// Prints one line of JSON — per op its kind and the new row ids (staff, conversation, message, follow-up, draft, quote), and
// for an e-mail whether it was sent — never a name, a phone, an e-mail, a number or a body. Refuses any other op, a code root without the writers' files, a
// data dir without a store, and a run without a vault of its own.
declare(strict_types=1);
ini_set('display_errors', 'stderr');
[$_, $root, $data, $ops, $zone] = $argv + [null, '', '', '', 'UTC'];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
foreach (['lib/SqliteStore.php', 'lib/ConversationService.php', 'lib/WaInbound.php', 'lib/FollowUpService.php', 'lib/ContactOptOut.php', 'lib/FollowUpPolicy.php'] as $f) {
    if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
}
if (!is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no store\n"); exit(2); }
if (!@date_default_timezone_set((string)$zone)) { fwrite(STDERR, "refusing: an unknown time zone\n"); exit(2); }
$list = json_decode((string)$ops, true);
if (!is_array($list) || $list === []) { fwrite(STDERR, "refusing: no ops given\n"); exit(2); }
foreach ($list as $o) {
    $op = is_array($o) ? (string)($o['op'] ?? '') : '';
    if (!in_array($op, ['staff', 'conv', 'enquiry', 'quote', 'quote_mail', 'kyc_app'], true)) { fwrite(STDERR, "refusing: an op this helper does not know\n"); exit(2); }
    if ($op === 'kyc_app' && ((int)($o['quote_id'] ?? 0) <= 0 || (int)($o['client_id'] ?? 0) <= 0 || strpos((string)($o['sales_person'] ?? ''), 'RH104SECRET-') !== 0)) {
        fwrite(STDERR, "refusing: a KYC application needs its quote id, client id and the rehearsal's own staff name\n"); exit(2);
    }
    if ($op === 'quote_mail' && (!in_array((string)($o['path'] ?? ''), ['app', 'webhook'], true) || (int)($o['quote_id'] ?? 0) <= 0 || (int)($o['client_id'] ?? 0) <= 0
        || !is_file((string)($o['mailer'] ?? '') . '/email_settings.json'))) { fwrite(STDERR, "refusing: a quotation e-mail needs its path, quote id, client id and the relay's settings\n"); exit(2); }
    $vals = [];
    foreach (['name', 'phone', 'email', 'text', 'body', 'by', 'ref', 'in_text', 'agent', 'number', 'sales_person'] as $k) $vals[] = $o[$k] ?? null;
    foreach ((array)($o['msgs'] ?? []) as $m) $vals[] = is_array($m) ? ($m['text'] ?? null) : null;
    foreach ($vals as $v) {
        if (is_string($v) && $v !== '' && strpos($v, 'RH104SECRET-') === false && !preg_match('/^\+?2567001040[0-9]{2}$/', $v)) {
            fwrite(STDERR, "refusing: a value that is not the rehearsal's own (no RH104SECRET- mark, not its phone range)\n"); exit(2);
        }
    }
}
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'ContactOptOut', 'FollowUpPolicy', 'FollowUpService', 'WaInbound', 'ConversationService'] as $l) {
    if (is_file($root . '/lib/' . $l . '.php')) require_once $root . '/lib/' . $l . '.php';
}
$mailOps = array_filter($list, function ($o) { return (string)($o['op'] ?? '') === 'quote_mail'; });
if ($mailOps !== []) {
    foreach (['lib/QuotationService.php', 'lib/CustomerEmailDispatcher.php', 'lib/QuotePdfSource.php', 'lib/CrmApiClient.php', 'lib/MailService.php'] as $f) {
        if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
    }
    foreach (['QuotationService', 'CustomerEmailDispatcher', 'QuotePdfSource', 'CrmApiClient', 'MailService'] as $l) require_once $root . '/lib/' . $l . '.php';
    // Path 1's sender as the code root has it; only its own seam for tests is given the relay's settings
    final class Rh104AppQuote extends QuotationService
    {
        /** @var string */
        private $rhMail;
        public function __construct($store, string $dataDir, string $mailDir) { parent::__construct($store, $dataDir); $this->rhMail = $mailDir; }
        protected function newMailService(): MailService { return new MailService($this->rhMail); }
        public function rhEmail(int $quoteId, string $number, int $clientId, array $actor = []): array { return $this->emailQuotePdf($quoteId, $number, $clientId, $actor, 0.0); }
    }
}
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$pdo = $s->getPdo();
$conv = new ConversationService($data, $pdo);
$fu = new FollowUpService($pdo);
$out = []; $n = 0;
try {
    foreach ($list as $o) {
        $op = (string)$o['op'];
        if ($op === 'staff') {
            $r = $s->appendWithId('retailers.json', ['name' => (string)$o['name'], 'phone' => $o['phone'] ?? null, 'email' => $o['email'] ?? null,
                'role' => (string)($o['role'] ?? 'sales'), 'is_admin' => !empty($o['admin']), 'is_active' => !array_key_exists('active', $o) || !empty($o['active'])]);
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('staff');
            $out[] = ['op' => 'staff', 'id' => $id];
        } elseif ($op === 'conv') {
            $ch = (string)($o['channel'] ?? 'sales');
            $c = $conv->ensureConversation((string)$o['phone'], $ch, (string)$o['name'], 'webhook');
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException('conv');
            $mids = [];
            foreach ((array)($o['msgs'] ?? []) as $i => $m) {
                $at = time() - 60 * (int)($m['ago_min'] ?? 0);
                $digits = preg_replace('/\D/', '', (string)$o['phone']);
                $payload = ['key' => ['remoteJid' => $digits . '@s.whatsapp.net', 'fromMe' => false, 'id' => 'RH104' . strtoupper(substr(hash('sha256', $digits . '|' . $ch . '|' . $i), 0, 16))],
                            'pushName' => (string)$o['name'], 'messageTimestamp' => $at,
                            'message' => ((string)($m['kind'] ?? 'text')) === 'audio'
                                ? ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'seconds' => 7, 'ptt' => true]]
                                : ['conversation' => (string)($m['text'] ?? 'RH104SECRET-text')]];
                $msg = WaInbound::normalise($payload, 'webhook', $ch === 'accounts' ? 'accounts' : 'support');
                $msg['received_at'] = gmdate('Y-m-d H:i:s', $at);
                $mid = $conv->storeMessage($cid, [
                    'direction'     => 'in',
                    'role'          => 'customer',
                    'body'          => WaInbound::storableBody($msg),
                    'media_type'    => $msg['has_media'] ? (string)$msg['modality'] : null,
                    'media_url'     => (string)($msg['media_ref'] ?? '') ?: null,
                    'wa_message_id' => (string)($msg['message_id'] ?? '') ?: (string)$payload['key']['id'],
                    'metadata'      => WaInbound::mediaMetadata($msg),
                    'sent_at'       => (string)$msg['received_at'],
                ]);
                $mids[] = $mid === null ? 0 : (int)$mid;
            }
            $out[] = ['op' => 'conv', 'id' => $cid, 'messages' => $mids];
        } elseif ($op === 'quote') {
            $ch = (string)($o['channel'] ?? 'sales');
            $c = $conv->ensureConversation((string)$o['phone'], $ch, (string)$o['name'], 'webhook');
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException('quote: no conversation');
            $at = time() - 60 * (int)($o['ago_min'] ?? 0); $gap = 60 * (int)($o['gap_min'] ?? 5);
            $digits = preg_replace('/\D/', '', (string)$o['phone']);
            $in = $conv->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => (string)$o['in_text'],
                'wa_message_id' => 'RH104IN' . strtoupper(substr(hash('sha256', $digits . '|' . $o['ref']), 0, 16)), 'sent_at' => gmdate('Y-m-d H:i:s', $at - $gap)]);
            $valid = (new DateTime('@' . ($at + 86400 * (int)($o['valid_days'] ?? 7))))->setTimezone(new DateTimeZone('Africa/Kampala'))->format('d M Y');
            $body = "RH104SECRET-quotation for RH104SECRET-kit\n📋 *Ref:* " . (string)$o['ref'] . "\n⏳ *Valid Until:* " . $valid . "\n";
            $q = $conv->storeMessage($cid, ['direction' => 'out', 'role' => 'agent', 'agent_name' => (string)($o['agent'] ?? 'RH104SECRET-Grace'), 'body' => $body,
                'event_key' => 'quote_manual', 'wa_message_id' => 'RH104Q' . strtoupper(substr(hash('sha256', (string)$o['ref']), 0, 16)),
                'sent_at' => gmdate('Y-m-d H:i:s', $at)]);
            if ((int)$in <= 0 || (int)$q <= 0) throw new RuntimeException('quote: not stored');
            $out[] = ['op' => 'quote', 'id' => $cid, 'messages' => [(int)$in, (int)$q]];
        } elseif ($op === 'quote_mail') {
            $qid = (int)$o['quote_id']; $cl = (int)$o['client_id']; $num = (string)$o['number']; $mailer = (string)$o['mailer'];
            $why = function (string $w): string { return substr((string)preg_replace('/[^a-z :]/', '', strtolower($w)), 0, 80); };
            $p7code = '-';
            if ((string)$o['path'] === 'app') {
                $actor = [];
                if ((string)($o['agent'] ?? '') !== '') {                       // the logged-in account the app path passes: the row of that name
                    foreach ((array)$s->load('retailers.json') as $r) if (is_array($r) && (string)($r['name'] ?? '') === (string)$o['agent']) { $actor = $r; break; }
                    if ($actor === []) throw new RuntimeException('quote mail app: no account of that name');
                }
                [$ok, $w] = (new Rh104AppQuote($s, $data, $mailer))->rhEmail($qid, $num, $cl, $actor);
                if (!$ok) throw new RuntimeException('quote mail app: ' . $why((string)$w));
            } else {
                // whQuotationEmail's steps, in its order (webhook.php), its config the store's with the two switches on
                $cfg = array_merge((array)($s->load('kyc_config.json') ?: []), ['customer_emails_enabled' => true, 'customer_email_quotation' => true]);
                if (!CustomerEmailDispatcher::enabled('quotation', $cfg)) throw new RuntimeException('quote mail webhook: switched off on disk');
                if (!CustomerEmailDispatcher::claimOnce($pdo, "QEMAIL{$qid}")) throw new RuntimeException('quote mail webhook: already claimed');
                $crm = CrmApiClient::fromUcrm($root, $cfg);
                $client = $crm->get("clients/{$cl}") ?: [];
                [$pdf, $src] = QuotePdfSource::fetch($crm, $data, $cfg, $qid, $client, []);
                if ($src !== 'ucrm') { CustomerEmailDispatcher::releaseClaim($pdo, "QEMAIL{$qid}"); throw new RuntimeException('quote mail webhook: no pdf from the stand-in'); }
                $atts = $pdf !== '' ? [['name' => "Quotation-{$num}.pdf", 'mime' => 'application/pdf', 'content' => $pdf]] : [];
                $d = new CustomerEmailDispatcher($mailer, $cfg, $crm, $pdo);
                // 5.18.104's whQuotationEmail P7 hunks (webhook.php), where the code root has them: the agent resolved before the send
                $p7 = null;
                if (is_file($root . '/lib/QuoteAgentCopy.php') && is_file($root . '/lib/StaffJobsGate.php')) {
                    require_once $root . '/lib/StaffJobsGate.php';
                    if (StaffJobsGate::applies($cfg, (string)$data)) {
                        try {
                            require_once $root . '/lib/QuoteAgentCopy.php';
                            $p7 = QuoteAgentCopy::forWebhook($s, $root, (string)$data, $cfg, $qid, $cl, $client, []);
                            if ($p7 !== null && $p7['address'] !== '') $d->agentCopy($p7['address']);
                        } catch (\Throwable $e) { $p7 = null; }
                    }
                }
                $r = $d->send('quotation', ['client_id' => $cl], (string)($o['name'] ?? ''), [
                    'first_name' => (string)($client['firstName'] ?? ''), 'quote_number' => $num, 'total' => 0.0, 'amount' => 0.0, 'pdf_attached' => $pdf !== '',
                ], (string)$qid, $atts);
                if ($p7 !== null) {   // …and after it, the decision as the dispatcher carried it out (webhook.php's second hunk)
                    try {
                        [$p7, $p7Rcpt] = QuoteAgentCopy::afterSend($p7, $r);
                        QuoteAgentCopy::record($s, 'webhook', $qid, $num, $p7, !empty($r['sent']), $p7Rcpt);
                    } catch (\Throwable $e) {}
                    $p7code = substr((string)preg_replace('/[^a-z_]/', '', (string)($p7['code'] ?? '')), 0, 30);
                }
                if (empty($r['sent'])) { CustomerEmailDispatcher::releaseClaim($pdo, "QEMAIL{$qid}"); throw new RuntimeException('quote mail webhook: ' . $why((string)($r['reason'] ?? ''))); }
            }
            $out[] = ['op' => 'quote_mail', 'id' => $qid, 'path' => (string)$o['path'], 'sent' => true, 'p7' => $p7code];
        } elseif ($op === 'kyc_app') {
            $rid = 0;
            foreach ((array)$s->load('retailers.json') as $r) if (is_array($r) && (string)($r['name'] ?? '') === (string)$o['sales_person']) { $rid = (int)($r['id'] ?? 0); break; }
            if ($rid <= 0) throw new RuntimeException('kyc_app: no account of that name');
            $now = date('Y-m-d H:i:s');
            $r = $s->appendWithId('kyc_applications.json', ['retailer_id' => $rid, 'sales_person' => (string)$o['sales_person'], 'crm_client_id' => (int)$o['client_id'],
                'quote_id' => (int)$o['quote_id'], 'quote_created' => true, 'status' => 'approved', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            if ((int)($r['id'] ?? 0) <= 0) throw new RuntimeException('kyc_app');
            $out[] = ['op' => 'kyc_app', 'id' => (int)$r['id']];
        } else {
            $ch = (string)($o['channel'] ?? 'sales');
            $rows = $conv->findByPhone((string)$o['phone'], $ch);
            $c = is_array($rows[0] ?? null) ? $rows[0] : null;
            if ($c === null) throw new RuntimeException('enquiry: no conversation');
            $f = $fu->open($c, ['topic' => 'rh104', 'opened_by' => 'scan']);
            if (empty($f['ok'])) throw new RuntimeException('enquiry: not opened');
            $d = $fu->draft((int)$f['id'], ['verdict' => 'SEND', 'message' => (string)$o['body'], 'reason' => 'rh104', 'model' => 'rh104']);
            if (empty($d['ok'])) throw new RuntimeException('enquiry: not drafted');
            $a = $fu->approve((int)$d['id'], (string)$o['by']);
            if (empty($a['ok'])) throw new RuntimeException('enquiry: not approved');
            $out[] = ['op' => 'enquiry', 'id' => (int)$f['id'], 'created' => !empty($f['created']), 'draft' => (int)$d['id']];
        }
        $n++;
    }
} catch (Throwable $e) { fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . ' ' . preg_replace('/[^a-z :]/', '', strtolower($e->getMessage())) . "\n"); exit(3); }
echo json_encode(['ops' => $n, 'done' => $out]), "\n";
