<?php
declare(strict_types=1);

/**
 * followup_send.php — send follow-ups a PERSON has approved. Nothing else.
 *
 * There is no path in this file from "the AI decided" to "the customer
 * received". It reads followup_drafts where status = 'approved', which only a
 * human action sets. Automatic sending is a later milestone and will be a
 * different decision, not a flag flipped here.
 *
 * ── THE ECHO ────────────────────────────────────────────────────────────
 *
 * Evolution posts every outbound message back as fromMe, and the webhook
 * reads an unrecognised fromMe message as a colleague typing on the handset —
 * which stands the AI down for 24 hours. Our own follow-up must never look
 * like that, so its message id is claimed in EvoWebhookGuard the instant
 * Evolution returns it, before the echo can arrive as a separate request.
 * AiReplyWorker does the same thing for the same reason.
 */
if (PHP_SAPI !== 'cli') return;

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/StoreInterface.php';
require_once $pluginRoot . '/lib/JsonStore.php';
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/ConversationService.php';
require_once $pluginRoot . '/lib/ContactOptOut.php';
require_once $pluginRoot . '/lib/EvolutionApiService.php';
require_once $pluginRoot . '/lib/AutomationPolicy.php';
require_once $pluginRoot . '/lib/PlanFenceGuard.php';
require_once $pluginRoot . '/lib/EvoWebhookGuard.php';
require_once $pluginRoot . '/lib/FollowUpPolicy.php';
require_once $pluginRoot . '/lib/FollowUpService.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

// 5.18.104 (root docs/73 §H.2.10.6, S2-01, K2-11): two passes, each behind its own switch. The enquiry pass runs only while
// followup_enabled, exactly as 5.18.103; the quote pass only while QuoteChase::on() (Uganda, tracking and the chase on, the
// ledger on, Kampala). With both off this is 5.18.103's early return. A test that includes this file may set
// $_qf_test_now, the run's clock (unix time); nothing else sets it.
require_once $pluginRoot . '/lib/QuoteChase.php';
$enqOn   = !empty($config['followup_enabled']);
$quoteOn = QuoteChase::on($config, $dataDir);
if (!$enqOn && !$quoteOn) return;
$fsTest  = isset($_qf_test_now) ? (int)$_qf_test_now : null;
$fsClock = function () use ($fsTest): int { return $fsTest ?? time(); };

$pdo     = $store->getPdo();
$svc     = new FollowUpService($pdo);
$convSvc = new ConversationService($dataDir, $pdo);
$oo      = ContactOptOut::fromStore($store);
// 5.18.86 (docs/65 §I): the constructor's service unless the channel registry is on (Uganda, behind
// multi_number_channels_enabled); then a conversation's channel id resolves through wa_channels.
$evo     = EvolutionApiService::forStore($config, $pdo, $dataDir);
$evo->useStaffStore($store);   // 5.18.90 (docs/65 §AD): the salesperson numbers' owners, for the policy
$guard   = new EvoWebhookGuard($pdo, $config);
$now     = gmdate('Y-m-d H:i:s', $fsClock());   // 5.18.104 (root docs/73 §H.2.10.6): the run's clock; time() but in a test

// 5.18.50 (docs/44 J8, M1): on Uganda an approved follow-up to a colleague's number is closed, never sent.
// Elsewhere $colleagues is null and nothing here changes.
require_once $pluginRoot . '/lib/ColleagueNumbers.php';
$colleagues = ColleagueNumbers::forInstall($config, $dataDir, $store);

// 5.18.89 (docs/65 §AA item 6): an approved follow-up on a salesperson's own number is closed, never sent — they follow
// up personally, and every automated send from a person's number adds to its ban risk. wa_followups_on_owned_numbers
// lifts the hold. Registry off: nothing is held and nothing here changes.
require_once $pluginRoot . '/lib/OwnedNumberHold.php';
$ownedHold = OwnedNumberHold::forInstall($config, $dataDir, $pdo);

$sent = 0; $held = 0; $failed = 0;

// 5.18.104 (root docs/73 §H.2.10.6; rulings R18, R19; B11-02): the ledger as this run read it, its budget, and the quote
// pass's lock. With the ledger on, a run starts no send unless JobBudget::mayStart($fsUntil, 20) (the Evolution
// timeout). South Sudan, where the ledger can never be on (StaffJobsGate), takes no fresh read.
$ledgerOn = WaSendBudget::on($config, $dataDir);
$fsUg     = StaffJobsGate::applies($config, $dataDir);
$fsUntil  = $fsClock() + JobBudget::seconds(45, isset($_m_deadline) ? (int)$_m_deadline : null, $fsClock());
$fsCounts = [];
$fsCount  = function (string $k) use (&$fsCounts): void { $fsCounts[$k] = ($fsCounts[$k] ?? 0) + 1; };

// 5.18.90 (docs/65 §AD): a draft held on a number that may not send now (paused, not verified, the department numbers not
// recorded, moved) stays approved and takes none of this run's places, so every other approved follow-up still goes.
// Registry off: '' — the query exactly as before.
[$heldSql, $heldArgs] = $evo->automationPolicy()->sqlHeld('f.channel');

// 5.18.104 (root docs/73 §H.2.10.6; B11-02): quote drafts FIRST (priority), under the quote pass's own lock, taken without
// waiting before any draft is listed: while another holder has it the quote pass is skipped this run (quote_pass_busy)
// and the enquiry pass goes on. Holding it, the pass reads its switches again from the files (PluginConfig::read, never
// this run's $config), and lists only while QuoteChase::on() holds on that read: the oldest approved draft per handset,
// at most 10. Then, only while followup_enabled, the enquiry drafts — per handset too while enquiry claims.
$qpLock = null; $qc = null; $quoteDrafts = [];
$fsHandsetOf = function (string $ch) use ($evo): string { return WaSendBudget::handset($evo->instanceFor($ch)); };
try {   // 5.18.104 (root docs/73 §H.2.10.6, B11-02): the lock's taking to the loop's end; released in the finally, however this ends
if ($quoteOn) {
    $qpLock = QuoteChase::passLock($dataDir);
    if ($qpLock === null) {
        $fsCount('quote_pass_busy');
    } else {
        $qc = PluginConfig::read($pluginRoot, $dataDir);
        if (QuoteChase::on($qc, $dataDir)) {
            foreach (QuoteChase::perHandset($svc->approvedQuoteDrafts(50, $heldSql, $heldArgs), $fsHandsetOf, 10) as $qd) {
                $quoteDrafts[] = ['_kind' => 'quote'] + $qd;
            }
        } else {
            QuoteChase::passUnlock($qpLock); $qpLock = null;
            $fsCount('quote_switch_off');
        }
    }
}
$enqDrafts = [];
if ($enqOn) foreach ($svc->approvedDrafts(10, $heldSql, $heldArgs) as $d) {   // 5.18.103's list, only while followup_enabled
    $enqDrafts[] = $d;
}
if ($enqOn && $ledgerOn && WaSendBudget::enquiryClaims($config)) $enqDrafts = QuoteChase::perHandset($enqDrafts, $fsHandsetOf, 10);

foreach (array_merge($quoteDrafts, $enqDrafts) as $d) {
    $dKind = (string)($d['_kind'] ?? 'enquiry');   // 5.18.104 (root docs/73 §H.2.10.6): which pass this draft is in
    if ($dKind === 'enquiry' && $qpLock !== null) { QuoteChase::passUnlock($qpLock); $qpLock = null; }   // the quote pass has ended
    $fuId  = (int)$d['followup_id'];
    $phone = (string)$d['phone'];
    $chan  = (string)$d['channel'];
    $body  = trim((string)($d['edited_body'] ?? '')) !== ''
           ? (string)$d['edited_body'] : (string)$d['body'];

    if (trim($body) === '') {
        $svc->recordFailure($fuId, (int)$d['id'], 'the approved draft has no message', 'sender');
        $failed++;
        continue;
    }

    // Re-check the things that can change between approval and send. A person
    // may have approved this yesterday; the customer may have replied, opted
    // out, or been taken over since.
    $conv = $convSvc->getConversation((int)$d['conversation_id']);
    if ($conv === null) {
        $svc->close($fuId, 'cancelled', 'the conversation no longer exists', 'sender');
        $held++;
        continue;
    }
    if ($colleagues !== null && ($colleagues->isColleague($phone) || $colleagues->isColleagueConversation($conv))) {
        $svc->close($fuId, 'staff', "a colleague's number", 'sender');
        $held++;
        continue;
    }
    if ($ownedHold->holds($chan)) {
        $svc->close($fuId, 'cancelled', OwnedNumberHold::REASON, 'sender');
        $held++;
        continue;
    }
    $v = $oo->blocks($phone, $chan, ContactOptOut::CLASS_PROACTIVE);
    if ($v['blocked']) {
        $svc->close($fuId, 'opted_out', $v['reason'], 'sender');
        $held++;
        continue;
    }
    // 5.18.104 (root docs/73 §H.2.10.6 item 1; R104-02): the untimed human_active check and the reply-on-this-conversation
    // check are the enquiry's; a quote draft's are QuoteChase::preSendCheck's, time-bounded, across every conversation.
    if ($dKind === 'enquiry' && (string)($conv['state'] ?? '') === 'human_active') {
        $svc->close($fuId, 'human_closed', 'a colleague took over after approval', 'sender');
        $held++;
        continue;
    }
    if ($dKind === 'enquiry' && (string)($conv['last_customer_at'] ?? '') > (string)($d['decided_at'] ?? '')) {
        $svc->close($fuId, 'replied', 'the customer wrote back after this was approved', 'sender');
        $held++;
        continue;
    }
    $win = FollowUpPolicy::withinSendingWindow($now);
    if (!$win['ok']) { $held++; continue; }   // wait for the window; stay approved

    // 5.18.104 (root docs/73 §H.2.10.6 item 2): a quote draft's own checks, all local reads, on the read taken with the
    // lock. A failure closes the follow-up and sets the tracker, or expires a stale or edited draft; nothing is claimed.
    $pc = ['ok' => true, 'cold' => false];
    if ($dKind === 'quote') {
        $pc = QuoteChase::preSendCheck($pdo, $qc, $dataDir, $d, $fsClock(), $store);
        if (!$pc['ok']) { $held++; $fsCount('quote_' . (string)$pc['code']); continue; }
    }

    // The same fence the reply paths carry. A follow-up is a message to a
    // customer like any other, and this cron reaches Evolution directly —
    // neither ReplyPrivacyGuard nor this runs unless it is called here. The
    // drafts seen so far name no plan, but "following up on the Business 500
    // you asked about" is one model call away, and it would have gone out
    // without the cap being mentioned once.
    $fence = PlanFenceGuard::apply($body, $config);
    if ($fence['appended']) {
        $body = $fence['reply'];
        $svc->log($fuId, (int)$d['conversation_id'], 'fenced',
                  'priority-data fact appended before sending', 'system');
    }

    // 5.18.86 (docs/65 §I): with the channel registry on, a follow-up leaves only on its own conversation's number. A
    // paused number keeps it approved for later; a number switched off for good closes it. Never sent from another.
    if ($evo->registryOn() && $evo->instanceFor($chan) === '') {
        $cx = $evo->channelContext($chan);
        if ($cx === null || $cx->status() !== 'paused') {
            $svc->close($fuId, 'cancelled', 'the number this chat belongs to is not active (channel ' . $chan . ')', 'sender');
        }
        $held++;
        continue;
    }

    // 5.18.90 (docs/65 §AD): the automated-send policy, read fresh: the number's assistant on, the number active and,
    // for a salesperson's, verified with the department numbers recorded; the recipient not DishNet's own. A reason that
    // may clear by itself keeps the draft approved for later; a final one closes it. Nothing is sent from another number.
    $why = $evo->automationPolicy()->refusal($chan, $phone);
    if ($why !== '') {
        if (!AutomationPolicy::transient($why)) {
            $svc->close($fuId, $why, 'nothing automated may go to this chat on channel ' . $chan, 'sender');
        }
        $held++;
        continue;
    }

    // 5.18.104 (root docs/73 §H.2.10.6 item 4; §H.2.6.4; rulings R18, R19; Q15-F04, Q16-F05): the ledger, LAST of the
    // checks, so a held draft never consumes a claim. Every decision here is made on the files read again immediately
    // before it ($fc), never on this run's $config: the switches, whether an enquiry claims, records or is sent as
    // 5.18.103 sends it, the owned-number hold, and the ledger's own window, caps and spacing (built from $fc).
    $fsMode = ''; $fsClaim = 0; $fsLedger = null; $fsInst = ''; $fsRef = 'fu:' . $fuId . ':' . (int)($d['attempt'] ?? 1);
    $fc = null;
    if ($dKind === 'quote' || $fsUg) {
        if ($ledgerOn && !JobBudget::mayStart($fsUntil, 20, $fsClock())) { $held++; $fsCount('budget'); continue; }
        $fc = PluginConfig::read($pluginRoot, $dataDir);
        $fcLedger = WaSendBudget::on($fc, $dataDir);
        if ($dKind === 'quote') {
            if (!QuoteChase::on($fc, $dataDir) || (!empty($pc['cold']) && !QuoteChase::cold($fc))) { $held++; $fsCount('quote_switch_off'); continue; }
            $fsMode = 'claim';
        } elseif ($ledgerOn) {
            if (empty($fc['followup_enabled'])) { $held++; $fsCount('enquiry_off'); continue; }
            if ($fcLedger) $fsMode = WaSendBudget::enquiryClaims($fc) ? 'claim' : 'record';
        } elseif ($fcLedger) {
            $held++; $fsCount('ledger_came_on');   // the next run, begun with the ledger on, claims or records it
            continue;
        }
        if ($fsMode !== '' && OwnedNumberHold::forInstall($fc, $dataDir, $pdo)->holds($chan)) {
            $svc->close($fuId, 'cancelled', OwnedNumberHold::REASON, 'sender');
            $held++;
            continue;
        }
        if ($fsMode !== '') {
            $fsLedger = WaSendBudget::forInstall($fc, $dataDir, $store, null, $fsTest === null ? null : $fsClock);
            $fsInst   = $evo->instanceFor($chan);
        }
        if ($fsMode === 'claim') {
            if ($dKind === 'quote' && !QuoteChase::stillApproved($pdo, (int)$d['id'], $fuId)) { $held++; $fsCount('quote_moved'); continue; }
            $cl = $fsLedger->claim($fsInst, $dKind, $fsRef, ['phone' => $phone]);
            if (empty($cl['ok'])) {
                $code = (string)($cl['defer'] ?? 'ledger_unknown');
                if ($code === 'already_sent') {
                    if ($dKind === 'quote') $svc->armAfterUncertain($fuId, (int)$d['id'], 'the ledger holds this send already', 'sender',
                                                                    ['window' => WaSendBudget::window($fc), 'at' => $fsClock()]);
                    else $svc->recordUncertain($fuId, (int)$d['id'], 'the ledger holds this send already', 'sender');
                } elseif ($code === 'tries_exhausted') {
                    $svc->close($fuId, 'send_failed', 'three tries did not send it', 'sender');
                }
                $held++; $fsCount('ledger_' . $code);
                continue;
            }
            $fsClaim = (int)$cl['claim'];
            if ($dKind === 'quote') {
                $stop = QuoteChase::afterClaimStop($pdo, $fc, $dataDir, $d, $fsClock());
                if ($stop !== null) {
                    $fsLedger->settle($fsClaim, 'failed', ['detail' => QuoteChase::STOPPED_AFTER_CLAIM]);
                    $held++; $fsCount('quote_' . QuoteChase::STOPPED_AFTER_CLAIM);
                    continue;
                }
            }
        }
    }

    $res = $evo->sendText($chan, $phone, $body, ContactOptOut::CLASS_PROACTIVE);
    if (EvolutionApiService::policyRefused($res)) {   // refused at the send itself: nothing left
        // 5.18.104 (root docs/73 §H.2.10.6 items 6 and 12): a claim is settled failed (not sent). An enquiry the ledger only
        // records writes nothing here: item 12 records a send once step 9's outcome is known, and a refused send never reaches
        // it; a row written now would keep its ref, and record()'s INSERT OR IGNORE would drop the later real send (§H.2.5.5).
        if ($fsClaim > 0) $fsLedger->settle($fsClaim, 'failed', ['detail' => 'policy']);
        $why = EvolutionApiService::policyReason($res);
        if (!AutomationPolicy::transient($why)) {
            $svc->close($fuId, $why, 'refused at the send itself (channel ' . $chan . ')', 'sender');
        }
        $held++;
        continue;
    }

    // Claim our own echo FIRST — before storing, before bookkeeping. The echo
    // is a separate HTTP request and could in principle arrive while we are
    // still writing rows; claiming the id here means the webhook drops it at
    // its idempotency check instead of reading it as a colleague's message.
    $waId = (string)($res['data']['key']['id'] ?? $res['key']['id'] ?? '');
    if ($waId !== '') {
        try {
            $guard->claim($waId, $evo->registryOn() ? $evo->instanceFor($chan)
                                                     : (string)($config['evo_instance_' . $chan] ?? ''),
                          $dKind === 'quote' ? QuoteChase::ECHO_SOURCE : 'followup.send');   // 5.18.104 (root docs/73 §H.2.10.6 item 7)
        } catch (\Throwable $e) { /* dedupe is a backstop */ }
    }

    $ok = empty($res['suppressed']) && (!isset($res['ok']) || !empty($res['ok']))
          && empty($res['error']);

    // 5.18.104 (root docs/73 §H.2.10.6 items 8 and 12; §H.2.5.8): after the echo claim, the claim settled with the id the
    // send returned — a may-have-been-sent result failed with maybe_sent (stored uncertain, never retried) — or, for an
    // enquiry while the ledger records, the same in record()'s one form, with the phone.
    $fsMaybe = !$ok && EvolutionApiService::mayHaveBeenSent($res);
    if ($fsClaim > 0) {
        $fsLedger->settle($fsClaim, $ok ? 'sent' : 'failed', ['maybe_sent' => $fsMaybe, 'wa_message_id' => $waId, 'detail' => $ok ? null : 'evo_error']);
    } elseif ($fsMode === 'record') {
        $fsLedger->record($fsInst, 'enquiry', $fsRef, $ok ? 'sent' : 'failed',
                          ['maybe_sent' => $fsMaybe, 'wa_message_id' => $waId, 'detail' => $ok ? null : 'evo_error', 'phone' => $phone]);
    }

    if (!$ok) {
        // 5.18.104 (root docs/73 §H.2.10.6, "Uncertain"): a quote step that may have been sent is counted as taken and the
        // next step armed from the claim's moment; it is never sent again.
        if ($dKind === 'quote' && $fsMaybe) {
            $svc->armAfterUncertain($fuId, (int)$d['id'], (string)$res['error'], 'sender', ['window' => WaSendBudget::window($fc), 'at' => $fsClock()]);
            $failed++;
            continue;
        }
        // 5.18.54 (docs/46 row 31, N-1), Uganda: a failed draft stays approved and goes again in five minutes. When it may
        // already have reached the customer, that is the same follow-up twice, so it is set aside instead, and says why.
        if (EvolutionApiService::mayHaveBeenSent($res)) {
            require_once $pluginRoot . '/lib/NotifyGate.php';
            if (NotifyGate::applies(NotifyGate::EVO_RETRY, $config, $dataDir)) {
                $svc->recordUncertain($fuId, (int)$d['id'], (string)$res['error'], 'sender');
                $failed++;
                continue;
            }
        }
        $svc->recordFailure($fuId, (int)$d['id'],
            (string)($res['error'] ?? 'the send failed'), 'sender');
        $failed++;
        continue;
    }

    // Put it in the conversation so the inbox and the AI both see what we sent.
    // 5.18.104 (root docs/73 §H.2.10.6 item 10; A15, R-10): a chase is stored as 'DishNet Plugin', never 'DishNet'.
    try {
        $convSvc->storeMessage((int)$d['conversation_id'], $dKind === 'quote' ? QuoteChase::storedRow($body, $waId, $fuId, $d) : [
            'direction' => 'out', 'role' => 'agent', 'body' => $body,
            'agent_name' => 'DishNet', 'wa_message_id' => $waId ?: null,
            'metadata' => ['source' => 'followup', 'followup_id' => $fuId],
        ]);
    } catch (\Throwable $e) { /* the customer has it; bookkeeping must not retry */ }

    $svc->recordSend($fuId, (int)$d['id'], $body, $waId,
                     (string)($d['decided_by'] ?? 'staff'),
                     $dKind === 'quote' ? ['window' => WaSendBudget::window($fc), 'at' => $fsClock()] : null);   // 5.18.104 (root docs/73 §H.2.10.6 item 11)
    $sent++;
}
} finally {
    if ($qpLock !== null) QuoteChase::passUnlock($qpLock);   // 5.18.104 (root docs/73 §H.2.10.6, B11-02)
}

if ($sent || $held || $failed) {
    echo date('Y-m-d H:i:s') . " [followup_send] sent={$sent} held={$held} failed={$failed}\n";
}
if ($fsCounts !== []) {   // 5.18.104 (root docs/73 §H.2.14 M): fixed codes and counts only
    ksort($fsCounts);
    $fsLine = [];
    foreach ($fsCounts as $k => $v) $fsLine[] = $k . '=' . (int)$v;
    echo date('Y-m-d H:i:s') . ' [followup_send] ' . implode(' ', $fsLine) . "\n";
}
