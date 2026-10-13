<?php
/**
 * expect.php — what P0 must print, computed two ways that share no code with P0:
 *   - the plugin's own CashbookService (cashInHand(), bookBase()) and currency helpers, from the sandbox copy;
 *   - an independent classification of every row in PHP (no GROUP BY, no SQL arithmetic).
 * It constructs CashbookService, which alters tables and refreshes the (sandbox) vault, so the rehearsal runs it BEFORE
 * it records the files P0 must leave as they are.
 *
 *   DN_VAULT_FILE=<sandbox vault> php expect.php <sandbox plugin root> <data dir> <adjustment id or -1>  > expected.json
 */
declare(strict_types=1);
[$_, $root, $data, $adjId] = $argv + [null, '', '', '-1'];
$adjId = (int)$adjId;
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
$GLOBALS['dataDir'] = $data;
require_once "$root/lib/SqliteStore.php";
require_once "$root/lib/CashbookService.php";
require_once "$root/lib/currency.php";
require_once "$root/lib/PluginConfig.php";
$store = SqliteStore::create($data);
$cb    = new CashbookService($store, $data);
$base  = $cb->bookBase();
$curs  = dn_book_currencies(PluginConfig::read($root, $data));
$row   = $store->load('kyc_config.json');
$rowB  = $row ? dn_book_base($row) : null;
$rowC  = $row ? dn_book_currencies($row) : [];
$shown = $row ? $rowC : dn_book_currencies([]);
$all   = $curs; foreach ($shown as $c) if (!in_array($c, $all, true)) $all[] = $c;

$pdo  = $store->getPdo();
$acc  = [];
foreach ($pdo->query('SELECT id, kind FROM cb_accounts') as $a) $acc[(int)$a['id']] = (string)$a['kind'];
$rows = $pdo->query('SELECT * FROM cb_ledger')->fetchAll(PDO::FETCH_ASSOC);

$out = ['base' => $base, 'currencies' => $curs, 'row_base' => $rowB, 'row_currencies' => $rowC,
        'shown' => $shown, 'per' => []];
foreach ($all as $cur) {
    foreach (['' => 'all', 'dishnet' => 'dishnet'] as $proj => $key) {
        $cls = ['U' => [0, 0.0], 'ADJ' => [0, 0.0], 'CASH' => [0, 0.0], 'MOMO' => [0, 0.0], 'BANK' => [0, 0.0], 'OTHER' => [0, 0.0], 'ORPHAN' => [0, 0.0]];
        $pend = ['pending_approval' => [0, 0.0], 'rejected' => [0, 0.0]];
        foreach ($rows as $r) {
            if (in_array($r['status'], ['voided', 'voided_reconcile'], true)) continue;
            $c = $r['currency'];
            $match = $cur === $base ? ($c === $base || $c === null || $c === '') : $c === $cur;
            if (!$match) continue;
            if ($proj !== '' && $r['project'] !== $proj) continue;
            $v = ($cur === 'SSP' ? (float)($r['ssp_amount'] ?? 0) : (float)$r['amount']) * ($r['direction'] === 'in' ? 1 : -1);
            $aid = (int)($r['account_id'] ?? 0);
            $k = (int)$r['id'] === $adjId ? 'ADJ' : ($aid === 0 ? 'U' : (!isset($acc[$aid]) ? 'ORPHAN'
                : ['cash' => 'CASH', 'momo' => 'MOMO', 'bank' => 'BANK'][$acc[$aid]] ?? 'OTHER'));
            $cls[$k][0]++; $cls[$k][1] += $v;
            if ($proj === '' && isset($pend[$r['status']])) { $pend[$r['status']][0]++; $pend[$r['status']][1] += $v; }
        }
        $U = $cls['U'][1]; $A = $cls['ADJ'][1]; $C = $cls['CASH'][1] + $cls['MOMO'][1];
        $out['per'][$cur][$key] = [
            'today'  => $cb->cashInHand($cur, $proj),
            'cls'    => $cls,
            'pend'   => $pend,
            'opts'   => ['A' => round($U + $A + $C, 2), 'B' => round($U + $C, 2), 'C' => round($U + $A, 2), 'D' => round($U, 2)],
        ];
    }
}
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
