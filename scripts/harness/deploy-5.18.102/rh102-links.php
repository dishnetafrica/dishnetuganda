<?php
// 5.18.102's rehearsal (D14 derivation M-18): the uCRM links an admin makes on the Plans and Hardware screens, written
// into the store through the RELEASE's own writer — UcrmQuoteLinks::set() and ::end() of the code root it is given: the
// INSTALLED plugin once 5.18.102 serves, or, before the deploy, the pin's from Git (a link a rolled-back run of this
// release left, made as that run made it) — and, for states that writer refuses or never makes, a raw planter. Also the
// plan and kit saves around a link (a row deactivated, deleted or added: the Hardware screen's own acts, through the
// store's own writer). Synthetic: run by the rehearsal as the store's user, with a vault of its own:
//   php rh102-links.php <code root> <data dir> <ops as JSON: [{"op":…}, …]> [time zone]
// The time zone is the one the links are written in (the server's plugin writes linked_at with date(): Africa/Kampala);
// UTC when none is given. The ops:
//   {"op":"set","item":"plan|kit|install","row":<id>,"product":<id>,"plan":<service plan id>,"actor":<account id>}
//      — UcrmQuoteLinks::set(), the row and its stamp read from the store as the save reads them (findOne), the quoting
//      organization from the Uganda profile, as the sandbox has it; its kind (linked, already, changed, clash) is said
//   {"op":"end","item":…,"row":<id>,"why":"unlinked|row deleted","actor":<id>} — UcrmQuoteLinks::end()
//   {"op":"force","item":…,"row":<id>,"product":<id>,"plan":<id>,"actor":<id>} — what set() writes (the item's active
//      records ended as relinked, one fresh record), WITHOUT rule 8 and without the no-op: a link the release's writer
//      refuses (a kit linked to the installation's product), written as set() would have written it
//   {"op":"raw","record":{…}} — one record appended exactly as given (its id max+1 when none): a corrupt state, such as
//      two active records for one item, which the release never writes
//   {"op":"retime","id":<record id>,"linked_ts":<epoch>} — that record's linked_at_ts and its display string
//      linked_at, written in the zone given, as set() would have stamped them at that moment
//   {"op":"shift","by":<seconds>} — every record's linked_at_ts and ended_at_ts moved by the same amount, and their
//      display strings with them, in the zone given: links made that much earlier
//   {"op":"rowupd","file":"kyc_devices.json|subscription_plans.json","id":<id>,"set":{…}} — SqliteStore::updateOne()
//   {"op":"rowdel","file":…,"id":<id>} — the row removed (withLock): a row deleted, its link records left, as
//      5.18.101, which has no link screen, leaves them
//   {"op":"rowadd","file":…,"row":{…, "_rh102": true}} — SqliteStore::appendWithId() (MAX(id)+1: an id freed by
//      rowdel comes back), a created_at given as "rel:<strtotime phrase>" written in the zone given
// Every name it writes is a marked secret of the rehearsal's (RH102QSECRET-…), never printed by the script under
// rehearsal (D-R5) — the sweeps look for it everywhere, the sheets included. Prints one line of JSON — the kinds, the
// new record ids and the store's counts — never a name or a price. Refuses any other op or collection, a row without the
// rehearsal's marker, and a run without a vault of its own.
declare(strict_types=1);
[$_, $root, $data, $ops, $zone] = $argv + [null, '', '', '', 'UTC'];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
foreach (['lib/SqliteStore.php', 'lib/UcrmQuoteLinks.php', 'lib/QuoteOrganization.php', 'lib/TenantProfile.php', 'profiles/uganda.json'] as $f) {
    if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
}
if (!is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no store\n"); exit(2); }
if (!@date_default_timezone_set((string)$zone)) { fwrite(STDERR, "refusing: an unknown time zone\n"); exit(2); }
$list = json_decode((string)$ops, true);
if (!is_array($list) || $list === []) { fwrite(STDERR, "refusing: no ops given\n"); exit(2); }
$rowFiles = ['subscription_plans.json', 'kyc_devices.json'];
foreach ($list as $o) {
    $op = is_array($o) ? (string)($o['op'] ?? '') : '';
    if (!in_array($op, ['set', 'end', 'force', 'raw', 'retime', 'shift', 'rowupd', 'rowdel', 'rowadd'], true)) { fwrite(STDERR, "refusing: an op this helper does not know\n"); exit(2); }
    if (in_array($op, ['rowupd', 'rowdel', 'rowadd'], true) && !in_array((string)($o['file'] ?? ''), $rowFiles, true)) { fwrite(STDERR, "refusing: only the plan and kit collections\n"); exit(2); }
    if ($op === 'rowadd' && (!is_array($o['row'] ?? null) || ($o['row']['_rh102'] ?? null) !== true)) { fwrite(STDERR, "refusing: a rehearsal row carries \"_rh102\": true\n"); exit(2); }
    if (in_array($op, ['set', 'end', 'force'], true) && !in_array((string)($o['item'] ?? ''), ['plan', 'kit', 'install'], true)) { fwrite(STDERR, "refusing: an unknown item\n"); exit(2); }
}
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'TenantProfile', 'QuoteOrganization', 'UcrmQuoteLinks'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$config = ['tenant_profile' => 'uganda'];   // the sandbox's quoting organization: #1, from the Uganda profile (Q-G3)
$file = UcrmQuoteLinks::FILE;
$actorOf = function (array $o): array { return ['id' => (int)($o['actor'] ?? 0), 'name' => 'RH102QSECRET-admin']; };
$rowOf = function (string $item, int $id) use ($s): ?array {
    if ($item === 'install') return null;
    $r = $s->findOne($item === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json', 'id', $id);
    return is_array($r) ? $r : null;
};
$stamp = function (int $ts): string { return date('Y-m-d H:i:s', $ts); };
$kinds = []; $ids = []; $n = 0;
try {
    foreach ($list as $o) {
        $op = (string)$o['op'];
        if ($op === 'set') {
            $item = (string)$o['item']; $rid = $item === 'install' ? 0 : (int)($o['row'] ?? 0); $row = $rowOf($item, $rid);
            $pid = (int)($o['product'] ?? 0);
            $link = ['item' => $item, 'row_id' => $rid, 'row_stamp' => $row === null ? '' : UcrmQuoteLinks::rowStamp($row), 'product_id' => $pid,
                     'service_plan_id' => $item === 'plan' ? (int)($o['plan'] ?? 0) : null, 'row_name' => 'RH102QSECRET-linkrow-' . $rid,
                     'product_name' => 'RH102QSECRET-linkname-' . $pid, 'service_plan_name' => $item === 'plan' ? 'RH102QSECRET-linkname-sp' . (int)($o['plan'] ?? 0) : null];
            $r = UcrmQuoteLinks::set($s, $link, $actorOf($o), $config, null);
            $kinds[] = (string)$r['kind'];
            if (is_array($r['record'] ?? null) && $r['kind'] === 'linked') $ids[] = (int)$r['record']['id'];
        } elseif ($op === 'end') {
            $e = UcrmQuoteLinks::end($s, (string)$o['item'], (int)($o['row'] ?? 0), (string)($o['why'] ?? UcrmQuoteLinks::WHY_UNLINKED), $actorOf($o));
            $kinds[] = 'ended:' . count($e);
        } elseif ($op === 'force') {
            $item = (string)$o['item']; $rid = $item === 'install' ? 0 : (int)($o['row'] ?? 0); $row = $rowOf($item, $rid);
            if ($item !== 'install' && $row === null) { $kinds[] = 'norow'; continue; }
            $pid = (int)($o['product'] ?? 0); $actor = $actorOf($o);
            $new = $s->withLock($file, function (array $records) use ($item, $rid, $row, $pid, $o, $actor, $stamp) {
                $records = array_values(array_filter($records, 'is_array'));
                $ts = time(); $now = $stamp($ts); $max = 0;
                foreach ($records as $i => $r) {
                    $max = max($max, is_numeric($r['id'] ?? null) ? (int)$r['id'] : 0);
                    if (($r['item'] ?? null) !== $item || !is_numeric($r['row_id'] ?? null) || (int)$r['row_id'] !== $rid || ($r['ended_at_ts'] ?? null) !== null) continue;
                    $records[$i]['ended_at'] = $now; $records[$i]['ended_at_ts'] = $ts; $records[$i]['ended_by_id'] = $actor['id']; $records[$i]['ended_why'] = UcrmQuoteLinks::WHY_RELINKED;
                }
                $rec = ['id' => $max + 1, 'item' => $item, 'row_id' => $rid, 'row_stamp' => $row === null ? '' : UcrmQuoteLinks::rowStamp($row), 'product_id' => $pid,
                        'service_plan_id' => $item === 'plan' ? (int)($o['plan'] ?? 0) : null, 'organization_id' => 1,
                        'row_name' => $item === 'install' ? 'Installation' : 'RH102QSECRET-linkrow-' . $rid, 'product_name' => 'RH102QSECRET-linkname-' . $pid,
                        'service_plan_name' => $item === 'plan' ? 'RH102QSECRET-linkname-sp' . (int)($o['plan'] ?? 0) : null,
                        'linked_by_id' => $actor['id'], 'linked_by_name' => $actor['name'], 'linked_at' => $now, 'ended_at' => null, 'ended_by_id' => null,
                        'ended_why' => null, 'linked_at_ts' => $ts, 'ended_at_ts' => null];
                $records[] = $rec;
                return ['records' => $records, 'result' => $rec['id']];
            });
            $kinds[] = 'forced'; $ids[] = (int)$new;
        } elseif ($op === 'raw') {
            $rec = is_array($o['record'] ?? null) ? $o['record'] : [];
            $new = $s->withLock($file, function (array $records) use ($rec) {
                $records = array_values(array_filter($records, 'is_array')); $max = 0;
                foreach ($records as $r) $max = max($max, is_numeric($r['id'] ?? null) ? (int)$r['id'] : 0);
                if (!isset($rec['id'])) $rec['id'] = $max + 1;
                $records[] = $rec;
                return ['records' => $records, 'result' => (int)$rec['id']];
            });
            $kinds[] = 'raw'; $ids[] = (int)$new;
        } elseif ($op === 'retime' || $op === 'shift') {
            $k = $s->withLock($file, function (array $records) use ($o, $op, $stamp) {
                $records = array_values(array_filter($records, 'is_array')); $hit = 0;
                foreach ($records as $i => $r) {
                    if ($op === 'retime') {
                        if (!is_numeric($r['id'] ?? null) || (int)$r['id'] !== (int)($o['id'] ?? 0)) continue;
                        $t = (int)($o['linked_ts'] ?? 0);
                        $records[$i]['linked_at_ts'] = $t; $records[$i]['linked_at'] = $stamp($t); $hit++;
                        continue;
                    }
                    $by = (int)($o['by'] ?? 0);
                    foreach (['linked' => 'linked_at', 'ended' => 'ended_at'] as $p => $str) {
                        $t = $r[$p . '_at_ts'] ?? null;
                        if (!is_int($t)) continue;
                        $records[$i][$p . '_at_ts'] = $t + $by; $records[$i][$str] = $stamp($t + $by);
                    }
                    $hit++;
                }
                return ['records' => $records, 'result' => $hit];
            });
            $kinds[] = $op . ':' . (int)$k;
        } elseif ($op === 'rowupd') {
            $kinds[] = $s->updateOne((string)$o['file'], 'id', (int)($o['id'] ?? 0), (array)($o['set'] ?? [])) ? 'updated' : 'norow';
        } elseif ($op === 'rowdel') {
            $id = (int)($o['id'] ?? 0);
            $k = $s->withLock((string)$o['file'], function (array $rows) use ($id) {
                $out = []; $hit = 0;
                foreach ($rows as $r) { if (is_array($r) && is_numeric($r['id'] ?? null) && (int)$r['id'] === $id) { $hit++; continue; } $out[] = $r; }
                return ['records' => $out, 'result' => $hit];
            });
            $kinds[] = 'deleted:' . (int)$k;
        } else {
            $row = (array)$o['row'];
            foreach (['created_at', 'updated_at'] as $f) {
                if (is_string($row[$f] ?? null) && strpos($row[$f], 'rel:') === 0) {
                    $t = strtotime(substr($row[$f], 4));
                    if ($t === false) { fwrite(STDERR, "refusing: a relative time strtotime cannot read\n"); exit(2); }
                    $row[$f] = $stamp($t);
                }
            }
            $r = $s->appendWithId((string)$o['file'], $row);
            $kinds[] = 'added:' . (int)$r['id'];
        }
        $n++;
    }
} catch (Throwable $e) { fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . "\n"); exit(3); }
$all = array_values(array_filter((array)$s->load($file), 'is_array'));
$act = []; $two = 0;
foreach ($all as $r) if (($r['ended_at_ts'] ?? null) === null && is_string($r['item'] ?? null) && is_numeric($r['row_id'] ?? null)) $act[$r['item'] . '|' . (int)$r['row_id']] = ($act[$r['item'] . '|' . (int)$r['row_id']] ?? 0) + 1;
foreach ($act as $c) if ($c >= 2) $two++;
echo json_encode(['ops' => $n, 'kinds' => $kinds, 'ids' => $ids, 'records' => count($all), 'active' => array_sum($act), 'two' => $two]), "\n";
