<?php
declare(strict_types=1);
/**
 * api_followups.php — the staff approval workflow.
 *
 * Without this the system generates AI follow-ups that sit in a table nobody
 * can act on, which is a worse outcome than not building it: work done, no
 * value, and a growing pile of stale drafts about customers whose situation
 * has moved on.
 *
 * Every action here is a person's decision and is recorded as one.
 */
// Included by includes/api_handlers.php after auth, like every other
// api_*.php: $act, $ok2/$er2, $store, $dataDir, $retailer and $isAdmin are
// already in scope, and a handler claims the request only by matching $act.
if (strpos((string)($act ?? ''), 'fu_') !== 0) return;
if (!$isAdmin) $er2('Admin only', 403);

require_once dirname(__DIR__, 2) . '/lib/FollowUpService.php';
require_once dirname(__DIR__, 2) . '/lib/FollowUpPolicy.php';
require_once dirname(__DIR__, 2) . '/lib/ContactOptOut.php';
require_once dirname(__DIR__, 2) . '/lib/ConversationService.php';

$_fuSvc  = new FollowUpService($store->getPdo());
$_fuConv = new ConversationService($dataDir, $store->getPdo());
$_fuOO   = ContactOptOut::fromStore($store);
$_fuWho  = 'staff:' . (string)($retailer['name'] ?? $retailer['username'] ?? 'unknown');

$fuAction = substr((string)$act, 3);   // 'fu_drafts' -> 'drafts'

/** Everything a person needs to judge one draft, in one object. */
$fuPack = function (array $d) use ($_fuConv, $_fuSvc): array {
    $convId = (int)$d['conversation_id'];
    $conv   = $_fuConv->getConversation($convId) ?? [];
    $msgs   = $_fuConv->getMessages($convId, 30);
    $level  = FollowUpPolicy::contentLevel($conv);
    return [
        'draft_id'    => (int)$d['id'],
        'followup_id' => (int)$d['followup_id'],
        'verdict'     => (string)$d['verdict'],
        'reason'      => (string)$d['reason'],
        'message'     => (string)($d['body'] ?? ''),
        'trigger'     => (string)$d['trigger_note'],
        'attempt'     => (int)$d['attempt'],
        'max_attempts'=> (int)$d['max_attempts'],
        'due_at'      => (string)($d['due_at'] ?? ''),
        'quiet_since' => (string)($d['last_customer_at'] ?? ''),
        'created_at'  => (string)$d['created_at'],
        'phone'       => (string)$d['phone'],
        'channel'     => (string)$d['channel'],
        'conversation_id' => $convId,
        'customer'    => [
            'name'      => (string)($conv['crm_client_name'] ?? ''),
            'client_id' => (int)($conv['crm_client_id'] ?? 0),
            'lead_id'   => (int)($conv['lead_id'] ?? 0),
            // The provenance, spelled out — this is what tells the approver
            // whether the message may mention the customer's account at all.
            'link_method' => (string)($conv['crm_link_method'] ?? ''),
            'link_at'     => (string)($conv['crm_link_at'] ?? ''),
            'may_discuss' => $level,
        ],
        'enquiry'     => [
            'topic'       => (string)($d['topic'] ?? ''),
            'product'     => (string)($d['product'] ?? ''),
            'sales_stage' => (string)($d['sales_stage'] ?? ''),
            'objection'   => (string)($d['objection'] ?? ''),
            'summary'     => (string)($d['context_summary'] ?? ''),
        ],
        'thread'      => array_map(static fn($m) => [
            'role' => (string)($m['role'] ?? ''),
            'body' => (string)($m['body'] ?? ''),
            'at'   => (string)($m['sent_at'] ?? ''),
        ], $msgs),
        'history'     => array_map(static fn($e) => [
            'event' => (string)$e['event'], 'detail' => (string)$e['detail'],
            'actor' => (string)$e['actor'], 'at' => (string)$e['created_at'],
        ], $_fuSvc->events((int)$d['followup_id'], 40)),
    ];
};

if ($fuAction === 'drafts') {
    $out = array_map($fuPack, $_fuSvc->pendingDrafts(100));
    $ok2(['drafts' => $out, 'count' => count($out)]);
}

if ($fuAction === 'open') {
    $ok2(['followups' => $_fuSvc->allOpen(200)]);
}

if ($fuAction === 'approve') {
    $id   = (int)($_POST['draft_id'] ?? 0);
    $body = (string)($_POST['message'] ?? '');
    $note = (string)($_POST['note'] ?? '');
    if ($id <= 0) $er2('draft_id required.', 422);
    $r = $_fuSvc->approve($id, $_fuWho, $body, $note);
    if (empty($r['ok'])) $er2((string)($r['error'] ?? 'could not approve'), 409);
    $ok2(['approved' => true, 'message' => $r['body'],
          'note' => 'Queued. It goes out at the next send cycle, inside Kampala hours.']);
}

if ($fuAction === 'reject') {
    $id   = (int)($_POST['draft_id'] ?? 0);
    $note = (string)($_POST['note'] ?? '');
    if ($id <= 0) $er2('draft_id required.', 422);
    $r = $_fuSvc->reject($id, $_fuWho, $note);
    if (empty($r['ok'])) $er2((string)($r['error'] ?? 'could not reject'), 409);
    // Rejecting the message is not the same as abandoning the customer, so
    // the follow-up stays open unless the person also says to close it.
    if (!empty($_POST['close'])) {
        $d = $_fuSvc->getDraft($id);
        if ($d) $_fuSvc->close((int)$d['followup_id'], 'staff_cancelled', $note, $_fuWho);
    }
    $ok2(['rejected' => true]);
}

if ($fuAction === 'close') {
    $fuId   = (int)($_POST['followup_id'] ?? 0);
    $reason = (string)($_POST['reason'] ?? 'staff_cancelled');
    $note   = (string)($_POST['note'] ?? '');
    if ($fuId <= 0) $er2('followup_id required.', 422);
    if (!in_array($reason, ['staff_cancelled', 'not_interested', 'human_closed',
                            'converted', 'escalated'], true)) {
        $er2('unknown reason.', 422);
    }
    $fu = $_fuSvc->get($fuId);
    if ($fu === null) $er2('no such follow-up.', 404);

    // "Not interested" is a statement about the customer's wishes, so it also
    // records an opt-out. Closing one follow-up would otherwise leave them to
    // be picked up again by the next enquiry.
    if ($reason === 'not_interested') {
        $_fuOO->add((string)$fu['phone'], [
            'scope' => ContactOptOut::SCOPE_PROACTIVE, 'reason' => 'not_interested',
            'source' => 'admin', 'evidence' => $note,
            'crm_client_id' => (int)($fu['crm_client_id'] ?? 0), 'created_by' => $_fuWho,
        ]);
    }
    $_fuSvc->close($fuId, $reason, $note, $_fuWho);
    $ok2(['closed' => true, 'reason' => $reason,
          'opted_out' => $reason === 'not_interested']);
}

if ($fuAction === 'optouts') {
    $ok2(['optouts' => $_fuOO->live(200)]);
}

if ($fuAction === 'optout_lift') {
    $phone = (string)($_POST['phone'] ?? '');
    $chan  = (string)($_POST['channel'] ?? '*');
    if ($phone === '') $er2('phone required.', 422);
    $ok2($_fuOO->lift($phone, $chan, $_fuWho));
}
