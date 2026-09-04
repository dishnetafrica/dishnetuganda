<?php
declare(strict_types=1);
/**
 * Knowledge Base — the one place approved AI answers are edited.
 *
 * Every row here feeds the SAME system prompt used by the WhatsApp AI and
 * the website chat (and any future channel). Change an answer once, both
 * channels answer the new way on their next message. TBC rows force the
 * holding line + escalation instead of improvisation.
 */
if (!isset($store, $config)) { echo '<p>Context missing.</p>'; return; }
require_once dirname(__DIR__, 2) . '/lib/KnowledgeBase.php';

$pdo = $store->getPdo();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('csrfCheck') && csrfCheck()) {
    $act = (string)($_POST['kb_action'] ?? '');
    $key = strtoupper(trim((string)($_POST['item_key'] ?? '')));
    try {
        if ($act === 'save' && $key !== '' && preg_match('/^[A-Z0-9_]{3,60}$/', $key)) {
            $pdo->prepare("INSERT INTO knowledge_items (item_key, kind, title, answer, wa_answer, updated_by)
                           VALUES (?,?,?,?,?,?)
                           ON CONFLICT(item_key) DO UPDATE SET
                             kind=excluded.kind, title=excluded.title, answer=excluded.answer,
                             wa_answer=excluded.wa_answer, status='approved',
                             updated_by=excluded.updated_by,
                             updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')")
                ->execute([
                    $key,
                    in_array($_POST['kind'] ?? '', ['fact','rule','tbc'], true) ? $_POST['kind'] : 'fact',
                    mb_substr(trim((string)($_POST['title'] ?? $key)), 0, 200),
                    mb_substr(trim((string)($_POST['answer'] ?? '')), 0, 2000),
                    mb_substr(trim((string)($_POST['wa_answer'] ?? '')), 0, 1000),
                    (string)($_SESSION['agent_name'] ?? $_SESSION['user_name'] ?? 'admin'),
                ]);
            $flash = ['ok', "Saved {$key} — both AI channels use it from their next message."];
        }
        if ($act === 'toggle' && $key !== '') {
            $pdo->prepare("UPDATE knowledge_items
                              SET status = CASE status WHEN 'approved' THEN 'disabled' ELSE 'approved' END,
                                  updated_at = strftime('%Y-%m-%dT%H:%M:%SZ','now')
                            WHERE item_key = ?")->execute([$key]);
            $flash = ['ok', "Toggled {$key}."];
        }
    } catch (\Throwable $e) {
        $flash = ['err', 'Save failed: ' . htmlspecialchars($e->getMessage())];
    }
}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM knowledge_items ORDER BY
                           CASE kind WHEN 'fact' THEN 0 WHEN 'rule' THEN 1 ELSE 2 END, item_key")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { /* table appears after migration 064 */ }

$edit = null;
$editKey = strtoupper(trim((string)($_GET['edit'] ?? '')));
foreach ($rows as $r) if ($r['item_key'] === $editKey) $edit = $r;
$kindChip = fn(string $k): string => ['fact'=>'#177245','rule'=>'#0e7490','tbc'=>'#a05a00'][$k] ?? '#5d6570';
?>
<div style="max-width:1050px">
  <h2 style="margin:6px 0 2px">Knowledge Base — one brain, every channel</h2>
  <p style="color:#666;margin:0 0 14px">
    These approved answers feed the <b>same AI prompt</b> behind WhatsApp and the website chat.
    Edit once — every channel changes. <b>TBC</b> topics force the holding line + escalation.
    Prices are <i>never</i> stored here: they come live from uCRM.
  </p>

  <?php if ($flash): ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;color:#fff;background:<?= $flash[0]==='ok'?'#177245':'#b3261e' ?>"><?= $flash[1] ?></div>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;background:#fff3cd;border:1px solid #eadfa9">
      No knowledge yet. Run the seed once:
      <code>php tools/seed_knowledge.php</code>
    </div>
  <?php endif; ?>

  <form method="post" style="border:1px solid #ddd;border-radius:10px;padding:14px 16px;margin-bottom:18px;background:#fafafa">
    <?= function_exists('csrfField') ? csrfField() : '' ?>
    <input type="hidden" name="kb_action" value="save">
    <b><?= $edit ? 'Edit item' : 'Add item' ?></b>
    <div style="display:grid;grid-template-columns:220px 130px 1fr;gap:10px;margin-top:10px">
      <input name="item_key" placeholder="ITEM_KEY (A-Z, 0-9, _)" required
             value="<?= htmlspecialchars($edit['item_key'] ?? '') ?>" <?= $edit?'readonly':'' ?>
             style="padding:7px;font-family:monospace">
      <select name="kind" style="padding:7px">
        <?php foreach (['fact','rule','tbc'] as $k): ?>
          <option value="<?= $k ?>" <?= ($edit['kind'] ?? 'fact')===$k?'selected':'' ?>><?= $k ?></option>
        <?php endforeach; ?>
      </select>
      <input name="title" placeholder="Topic / title" required
             value="<?= htmlspecialchars($edit['title'] ?? '') ?>" style="padding:7px">
    </div>
    <textarea name="answer" rows="4" placeholder="Approved answer (facts/rules). Leave empty for tbc."
      style="width:100%;margin-top:10px;padding:8px"><?= htmlspecialchars($edit['answer'] ?? '') ?></textarea>
    <textarea name="wa_answer" rows="2" placeholder="Optional short WhatsApp form"
      style="width:100%;margin-top:8px;padding:8px"><?= htmlspecialchars($edit['wa_answer'] ?? '') ?></textarea>
    <div style="margin-top:10px"><button style="padding:8px 18px">Save (both channels)</button>
      <?php if ($edit): ?><a href="?page=knowledge_base" style="margin-left:10px">cancel</a><?php endif; ?></div>
  </form>

  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <tr style="text-align:left;border-bottom:2px solid #ddd">
      <th style="padding:6px">Key</th><th>Kind</th><th>Title</th><th>Answer</th><th>Status</th><th>Updated</th><th></th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr style="border-bottom:1px solid #eee;vertical-align:top;<?= $r['status']!=='approved'?'opacity:.5':'' ?>">
      <td style="padding:6px;font-family:monospace"><?= htmlspecialchars($r['item_key']) ?></td>
      <td><span style="background:<?= $kindChip($r['kind']) ?>;color:#fff;border-radius:10px;padding:2px 9px;font-size:11px"><?= $r['kind'] ?></span></td>
      <td><?= htmlspecialchars($r['title']) ?></td>
      <td style="max-width:420px"><?= htmlspecialchars(mb_substr($r['answer'],0,160)) ?><?= mb_strlen($r['answer'])>160?'…':'' ?></td>
      <td><?= htmlspecialchars($r['status']) ?></td>
      <td style="color:#888;white-space:nowrap"><?= htmlspecialchars(substr((string)$r['updated_at'],0,10)) ?><br><span style="font-size:11px"><?= htmlspecialchars($r['updated_by']) ?></span></td>
      <td style="white-space:nowrap">
        <a href="?page=knowledge_base&edit=<?= urlencode($r['item_key']) ?>">edit</a>
        <form method="post" style="display:inline;margin-left:8px">
          <?= function_exists('csrfField') ? csrfField() : '' ?>
          <input type="hidden" name="kb_action" value="toggle">
          <input type="hidden" name="item_key" value="<?= htmlspecialchars($r['item_key']) ?>">
          <button style="border:none;background:none;color:#b3261e;cursor:pointer;padding:0">
            <?= $r['status']==='approved'?'disable':'enable' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
