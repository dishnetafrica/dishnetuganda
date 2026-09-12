<?php
/**
 * followups.php — read what the assistant proposes, and decide.
 *
 * The whole first milestone ends here: a person between the AI and the
 * customer. Everything the approver needs to judge the message is on the card
 * — the thread, who we think this is and HOW SURE we are, what they asked
 * about, what is holding them back, and the assistant's reasoning — because a
 * decision made without the conversation in front of you is a rubber stamp.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/FollowUpService.php';
require_once dirname(__DIR__, 2) . '/lib/FollowUpPolicy.php';
require_once dirname(__DIR__, 2) . '/lib/ContactOptOut.php';
require_once dirname(__DIR__, 2) . '/lib/ConversationService.php';

$pdo     = $store->getPdo();
$config  = $store->load('kyc_config.json') ?? [];
$fuSvc   = new FollowUpService($pdo);
$fuConv  = new ConversationService($dataDir, $pdo);
$fuOO    = ContactOptOut::fromStore($store);
$enabled = !empty($config['followup_enabled']);

$drafts = [];
try { $drafts = $fuSvc->pendingDrafts(50); } catch (\Throwable $e) {}
$open = [];
try { $open = $fuSvc->allOpen(200); } catch (\Throwable $e) {}
$outs = [];
try { $outs = $fuOO->live(50); } catch (\Throwable $e) {}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ago = static function (string $utc): string {
    if ($utc === '') return '—';
    $s = time() - (int)strtotime($utc . ' UTC');
    if ($s < 3600)  return floor($s / 60) . 'm ago';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    return floor($s / 86400) . 'd ago';
};
$provenance = [
    'verified'     => ['ok',   'verified'],
    'manual'       => ['ok',   'linked by staff'],
    'ai_identified'=> ['ok',   'identified'],
    'phone_tail'   => ['warn', 'phone match only'],
    'bulk_rematch' => ['warn', 'phone match only'],
    'ambiguous'    => ['bad',  'ambiguous'],
];
?>
<style>
.fu{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
/* width:100% plus padding overflows the grid column without this, and the
   textarea ran past the card edge. */
.fu *{box-sizing:border-box;}
.fu-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
.fu-head h2{margin:0;font-size:19px;font-weight:800;color:#0F172A;}
.fu-sub{font-size:12.5px;color:#64748B;margin-top:4px;max-width:70ch;line-height:1.5;}
.fu-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.fu-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:9px 14px;min-width:88px;}
.fu-stat b{display:block;font-size:19px;font-weight:800;color:#0F172A;}
.fu-stat span{font-size:10px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
.fu-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.55;margin-bottom:16px;}
.fu-note.amber{background:#FFFBEB;border:1px solid #FDE68A;color:#78350F;}
.fu-note.grey{background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;}
.fu-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;margin-bottom:14px;overflow:hidden;}
.fu-top{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid #F1F5F9;}
.fu-who{font-weight:700;color:#0F172A;font-size:15px;}
.fu-id{font-size:11px;color:#94A3B8;font-family:'Courier New',monospace;margin-top:2px;}
.fu-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;}
.p-ok{background:#DCFCE7;color:#166534;} .p-warn{background:#FEF3C7;color:#92400E;}
.p-bad{background:#FEE2E2;color:#991B1B;} .p-grey{background:#F1F5F9;color:#64748B;}
.fu-body{padding:14px 16px;display:grid;gap:14px;grid-template-columns:minmax(0,1fr);}
@media(min-width:900px){.fu-body{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);}}
.fu-lbl{font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.fu-thread{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:10px;max-height:260px;overflow-y:auto;font-size:12.5px;line-height:1.55;}
.fu-msg{margin-bottom:7px;}
.fu-msg:last-child{margin-bottom:0;}
.fu-msg .r{font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.4px;}
.fu-msg.cust .r{color:#1D4ED8;} .fu-msg.ai .r{color:#7C3AED;} .fu-msg.staff .r{color:#166534;}
.fu-kv{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:12.5px;align-items:baseline;}
.fu-kv dt{color:#64748B;white-space:nowrap;}
.fu-kv dd{margin:0;color:#0F172A;}
.fu-reason{background:#F5F3FF;border-left:3px solid #7C3AED;padding:9px 12px;border-radius:0 6px 6px 0;font-size:12.5px;color:#4C1D95;line-height:1.5;}
.fu-msg-box{width:100%;min-height:92px;border:1px solid #CBD5E1;border-radius:8px;padding:10px 12px;font:inherit;font-size:13.5px;line-height:1.55;resize:vertical;color:#0F172A;background:#fff;}
.fu-acts{display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px;background:#F8FAFC;border-top:1px solid #F1F5F9;}
.fu-btn{border:1px solid #CBD5E1;background:#fff;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;color:#0F172A;}
.fu-btn:hover{background:#F1F5F9;}
.fu-btn.go{background:#166534;border-color:#166534;color:#fff;}
.fu-btn.go:hover{background:#14532D;}
.fu-btn.no{color:#991B1B;border-color:#FCA5A5;}
.fu-empty{padding:40px 20px;text-align:center;color:#64748B;font-size:13px;background:#fff;border:1px solid #E2E8F0;border-radius:12px;}
.fu-tbl{width:100%;border-collapse:collapse;font-size:12.5px;background:#fff;}
.fu-tbl th{text-align:left;padding:9px 12px;font-size:10px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.4px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;}
.fu-tbl td{padding:9px 12px;border-bottom:1px solid #F1F5F9;}
.fu-wrap{border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;overflow-x:auto;margin-bottom:22px;}
h3.fu-h{font-size:14px;font-weight:800;color:#0F172A;margin:26px 0 10px;}
</style>

<div class="fu">
  <div class="fu-head">
    <div>
      <h2>Customer follow-ups</h2>
      <div class="fu-sub">The assistant proposes; you decide. Nothing here reaches a customer
        until somebody approves it — there is no automatic sending in this release.</div>
    </div>
  </div>

  <div class="fu-stats">
    <div class="fu-stat"><b><?= count($drafts) ?></b><span>Awaiting you</span></div>
    <div class="fu-stat"><b><?= count($open) ?></b><span>Open</span></div>
    <div class="fu-stat"><b><?= count($outs) ?></b><span>Opted out</span></div>
  </div>

  <?php if (!$enabled): ?>
    <div class="fu-note amber"><strong>Follow-ups are switched off.</strong>
      Nothing is being scanned or evaluated. Set <code>followup_enabled</code> to turn on
      draft generation — sending still requires your approval on each message.</div>
  <?php endif; ?>

  <?php if (!$drafts): ?>
    <div class="fu-empty">
      Nothing waiting for you.<br>
      <?= $enabled ? 'Quiet conversations are checked every 10 minutes.' : 'Follow-ups are switched off.' ?>
    </div>
  <?php endif; ?>

  <?php foreach ($drafts as $d):
      $convId = (int)$d['conversation_id'];
      $conv   = $fuConv->getConversation($convId) ?? [];
      $msgs   = $fuConv->getMessages($convId, 20);
      $level  = FollowUpPolicy::contentLevel($conv);
      $pm     = (string)($conv['crm_link_method'] ?? '');
      $prov   = $provenance[$pm] ?? ['grey', $pm !== '' ? $pm : 'not linked'];
      $name   = trim((string)($conv['crm_client_name'] ?? ''));
      if ($name === '') $name = (string)$d['phone'];
  ?>
  <div class="fu-card" id="fu-card-<?= (int)$d['id'] ?>">
    <div class="fu-top">
      <div>
        <div class="fu-who"><?= $h($name) ?></div>
        <div class="fu-id"><?= $h($d['phone']) ?> · <?= $h($d['channel']) ?> · conversation #<?= $convId ?></div>
      </div>
      <div style="text-align:right;">
        <span class="fu-pill p-<?= $prov[0] ?>"><?= $h($prov[1]) ?></span>
        <div class="fu-id" style="margin-top:5px;">
          attempt <?= (int)$d['attempt'] ?> of <?= (int)$d['max_attempts'] ?> ·
          quiet <?= $h($ago((string)$d['last_customer_at'])) ?>
        </div>
      </div>
    </div>

    <div class="fu-body">
      <div>
        <div class="fu-lbl">The conversation</div>
        <div class="fu-thread">
          <?php foreach ($msgs as $m):
            $role = (string)($m['role'] ?? '');
            $cls  = $role === 'customer' ? 'cust' : ($role === 'staff' ? 'staff' : 'ai');
            $who  = $role === 'customer' ? 'Customer' : ($role === 'staff' ? 'Our team' : 'Assistant');
            if (trim((string)($m['body'] ?? '')) === '') continue; ?>
            <div class="fu-msg <?= $cls ?>"><span class="r"><?= $h($who) ?></span>
              · <?= $h(substr((string)$m['sent_at'], 0, 16)) ?><br><?= $h($m['body']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:12px;min-width:0;">
        <div>
          <div class="fu-lbl">What we know</div>
          <dl class="fu-kv">
            <dt>uCRM</dt><dd><?= ((int)($conv['crm_client_id'] ?? 0)) > 0
                ? 'client #' . (int)$conv['crm_client_id'] : 'not a customer yet' ?></dd>
            <?php if ((int)($conv['lead_id'] ?? 0) > 0): ?>
              <dt>Lead</dt><dd>#<?= (int)$conv['lead_id'] ?></dd><?php endif; ?>
            <dt>May discuss</dt><dd><?= $level === 'account'
                ? 'their account' : 'the enquiry only' ?></dd>
            <dt>Asked about</dt><dd><?= $h(($d['product'] ?? '') !== '' ? $d['product'] : '—') ?></dd>
            <dt>Stage</dt><dd><?= $h(($d['sales_stage'] ?? '') ?: 'unknown') ?></dd>
            <?php if (!empty($d['objection'])): ?>
              <dt>Holding back</dt><dd><?= $h($d['objection']) ?></dd><?php endif; ?>
            <dt>Due</dt><dd><?= $h(substr((string)$d['due_at'], 0, 16) ?: '—') ?> UTC</dd>
          </dl>
        </div>

        <div>
          <div class="fu-lbl">Why the assistant wants to write</div>
          <div class="fu-reason"><?= $h($d['reason']) ?>
            <?php if (!empty($d['trigger_note'])): ?>
              <div style="margin-top:6px;opacity:.8;font-size:11.5px;"><?= $h($d['trigger_note']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div>
          <div class="fu-lbl">Proposed message — edit before approving if you want</div>
          <textarea class="fu-msg-box" id="fu-body-<?= (int)$d['id'] ?>"><?= $h($d['body']) ?></textarea>
        </div>
      </div>
    </div>

    <div class="fu-acts">
      <button class="fu-btn go" onclick="fuApprove(<?= (int)$d['id'] ?>)">Approve &amp; queue</button>
      <button class="fu-btn" onclick="fuReject(<?= (int)$d['id'] ?>,false)">Reject message</button>
      <button class="fu-btn no" onclick="fuReject(<?= (int)$d['id'] ?>,true)">Reject &amp; close</button>
      <button class="fu-btn no" onclick="fuClose(<?= (int)$d['followup_id'] ?>,'not_interested')">Not interested</button>
      <button class="fu-btn" onclick="fuClose(<?= (int)$d['followup_id'] ?>,'human_closed')">I'll handle it</button>
      <button class="fu-btn" onclick="fuClose(<?= (int)$d['followup_id'] ?>,'converted')">Already converted</button>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($outs): ?>
    <h3 class="fu-h">Opted out — never contacted proactively</h3>
    <div class="fu-wrap">
      <table class="fu-tbl">
        <thead><tr><th>Phone</th><th>Scope</th><th>Reason</th><th>Recorded</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($outs as $o): ?>
          <tr>
            <td style="font-family:'Courier New',monospace;"><?= $h($o['phone']) ?></td>
            <td><?= $h($o['scope']) ?></td>
            <td><?= $h($o['reason']) ?></td>
            <td><?= $h(substr((string)$o['created_at'], 0, 16)) ?></td>
            <td style="text-align:right;">
              <button class="fu-btn" style="padding:4px 10px;font-size:12px;"
                onclick="fuLift('<?= $h($o['phone']) ?>','<?= $h($o['channel']) ?>')">Lift</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="fu-note grey">
    <strong>What this screen will not do.</strong> It will not send anything on its own.
    Approving queues a message, which goes out at the next send cycle and only inside
    08:00–20:00 Kampala, never on a Sunday. If the customer writes back, opts out, or a
    colleague replies before it goes, the send is cancelled and the follow-up closes.
  </div>
</div>

<script>
function fuPost(action, data, okMsg) {
  var body = new URLSearchParams(data);
  return fetch('?page=api&action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: body.toString()
    }).then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.error) { alert(j.error); return; }
        if (okMsg) alert(okMsg);
        location.reload();
      }).catch(function (e) { alert('Request failed: ' + e); });
}
function fuApprove(id) {
  var el = document.getElementById('fu-body-' + id);
  fuPost('fu_approve', {draft_id: id, message: el ? el.value : ''},
         'Queued. It goes out at the next send cycle, inside Kampala hours.');
}
function fuReject(id, close) {
  var note = prompt('Why are you rejecting this? (optional)') || '';
  fuPost('fu_reject', {draft_id: id, note: note, close: close ? 1 : ''});
}
function fuClose(fuId, reason) {
  var msg = reason === 'not_interested'
    ? 'Close this and stop contacting them proactively?'
    : 'Close this follow-up?';
  if (!confirm(msg)) return;
  fuPost('fu_close', {followup_id: fuId, reason: reason, note: ''});
}
function fuLift(phone, channel) {
  if (!confirm('Allow proactive messages to ' + phone + ' again?')) return;
  fuPost('fu_optout_lift', {phone: phone, channel: channel});
}
</script>
