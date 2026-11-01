<?php
// 5.18.103's rehearsal (root docs/72 §G.2, §G.3): the uCRM catalogue's settings an admin makes on the uCRM catalogue
// screen, written into the store through the RELEASE's own writers — UcrmCatalogue::setInstall(), ::setHidden(),
// ::saveStandard(), ::endStandard() and ::setMode() of the code root it is given: the INSTALLED plugin once 5.18.103
// serves, or the pin's from Git (a scratch copy of the book, or a state a rolled-back run of this release left) — each
// called as the screen's post handler calls it (includes/post/post_sync.php: the judged ids passed in, the actor an
// admin's account), and, for states those writers never make, a raw planter. Synthetic: run by the rehearsal as the
// store's user, with a vault of its own:
//   php rh103-cat.php <code root> <data dir> <ops as JSON: [{"op":…}, …]> [time zone]
// The time zone is the one the records are stamped in (the server's plugin stamps "at" with date(): Africa/Kampala); UTC
// when none is given. The gate the writers re-read inside their lock is QuoteOrganization::setting() over the Uganda
// profile, as the sandbox has it (quoting organization #1). The ops:
//   {"op":"install","product":<id>,"actor":<account id>}                 — setInstall(); its kind (set, already, clash, gate)
//   {"op":"hidden","product":<id>,"hidden":true|false,"actor":<id>}      — setHidden(); its kind (hidden, shown, already, gate)
//   {"op":"standard","replace":null|<id>,"plan":{"sp":<id>,"p":<id>},"hw":[{"p":<id>,"q":<n>}],"install":true|false,"actor":<id>}
//        — saveStandard(), the title a marked secret of the rehearsal's; its kind (saved, invalid, full, changed, clash, gate)
//   {"op":"end","id":<record id>,"actor":<id>}                           — endStandard(); its kind (ended, changed, gate)
//   {"op":"mode","on":true|false,"judged":<installation product id>|null,"actor":<id>} — setMode(); its kind (on, off,
//        already, changed, gate)
//   {"op":"raw","record":{…}}  — one record appended exactly as given (its id max+1 when none, "at"/"at_ts" stamped when
//        absent): a state the writers never make, such as two switch records in force
// Every name it writes — the products' display names, the standard quotations' titles, the actor's name — is a marked
// secret of the rehearsal's (RH103QSECRET-…): the script under rehearsal may put them on its 0600 sheet and nowhere
// else, and the sweeps look for them everywhere. Prints one line of JSON — the kinds, the new records' ids and the
// table's counts (records, in force, the switch as the release reads it) — never a name or a price. Refuses any other op,
// a code root without the release's files, and a run without a vault of its own.
declare(strict_types=1);
ini_set('display_errors', 'stderr');   // a notice must never reach stdout, which carries the one line the rehearsal reads
[$_, $root, $data, $ops, $zone] = $argv + [null, '', '', '', 'UTC'];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
foreach (['lib/SqliteStore.php', 'lib/UcrmCatalogue.php', 'lib/QuoteOrganization.php', 'lib/TenantProfile.php', 'profiles/uganda.json'] as $f) {
    if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
}
if (!is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no store\n"); exit(2); }
if (!@date_default_timezone_set((string)$zone)) { fwrite(STDERR, "refusing: an unknown time zone\n"); exit(2); }
$list = json_decode((string)$ops, true);
if (!is_array($list) || $list === []) { fwrite(STDERR, "refusing: no ops given\n"); exit(2); }
foreach ($list as $o) {
    $op = is_array($o) ? (string)($o['op'] ?? '') : '';
    if (!in_array($op, ['install', 'hidden', 'standard', 'end', 'mode', 'raw'], true)) { fwrite(STDERR, "refusing: an op this helper does not know\n"); exit(2); }
    if ($op === 'raw' && (!is_array($o['record'] ?? null) || !in_array((string)($o['record']['kind'] ?? ''), ['mode', 'install', 'hidden', 'standard'], true))) {
        fwrite(STDERR, "refusing: a raw record carries one of the release's kinds\n"); exit(2);
    }
}
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'TenantProfile', 'QuoteOrganization', 'UcrmCatalogue'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$config = ['tenant_profile' => 'uganda'];   // the sandbox's quoting organization: #1, from the Uganda profile (Q-G3)
$actorOf = function (array $o): array { return ['id' => (int)($o['actor'] ?? 0), 'name' => 'RH103QSECRET-admin']; };
$kinds = []; $ids = []; $n = 0;
try {
    foreach ($list as $o) {
        $op = (string)$o['op'];
        $r = null;
        if ($op === 'install') {
            $pid = (int)($o['product'] ?? 0);
            $r = UcrmCatalogue::setInstall($s, $pid, 'RH103QSECRET-catname-' . $pid, $actorOf($o), $config, null);
        } elseif ($op === 'hidden') {
            $pid = (int)($o['product'] ?? 0);
            $r = UcrmCatalogue::setHidden($s, $pid, !empty($o['hidden']), 'RH103QSECRET-catname-' . $pid, $actorOf($o), $config, null);
        } elseif ($op === 'standard') {
            $std = ['title' => 'RH103QSECRET-std-' . bin2hex(random_bytes(3)), 'plan' => $o['plan'] ?? null, 'hw' => $o['hw'] ?? null, 'install' => $o['install'] ?? null];
            $rep = isset($o['replace']) && $o['replace'] !== null ? (int)$o['replace'] : null;
            $r = UcrmCatalogue::saveStandard($s, $rep, $std, $actorOf($o), $config, null);
        } elseif ($op === 'end') {
            $r = UcrmCatalogue::endStandard($s, (int)($o['id'] ?? 0), $actorOf($o), $config, null);
        } elseif ($op === 'mode') {
            $j = isset($o['judged']) && $o['judged'] !== null ? (int)$o['judged'] : null;
            $r = UcrmCatalogue::setMode($s, !empty($o['on']), $j, $actorOf($o), $config, null);
        } else {
            $rec = (array)$o['record'];
            $new = $s->withLock(UcrmCatalogue::FILE, function (array $records) use ($rec) {
                $records = array_values(array_filter($records, 'is_array')); $max = 0;
                foreach ($records as $x) $max = max($max, is_numeric($x['id'] ?? null) ? (int)$x['id'] : 0);
                if (!isset($rec['id'])) $rec['id'] = $max + 1;
                $ts = time();
                $rec += ['by_id' => 0, 'by_name' => 'RH103QSECRET-admin', 'at' => date('Y-m-d H:i:s', $ts), 'at_ts' => $ts,
                         'ended_at' => null, 'ended_at_ts' => null, 'ended_by_id' => null];
                $records[] = $rec;
                return ['records' => $records, 'result' => (int)$rec['id']];
            });
            $kinds[] = 'raw'; $ids[] = (int)$new; $n++;
            continue;
        }
        $kinds[] = (string)($r['kind'] ?? '?');
        if (is_array($r['record'] ?? null) && isset($r['record']['id']) && !in_array((string)$r['kind'], ['already'], true)) $ids[] = (int)$r['record']['id'];
        $n++;
    }
} catch (Throwable $e) { fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . "\n"); exit(3); }
// the table as the release reads it — never load(), which would create it where no op has
$pdo = $s->getPdo();
$has = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ucrm_catalogue'")->fetchColumn() !== false;
$all = $has ? array_values(array_filter((array)$s->load(UcrmCatalogue::FILE), 'is_array')) : [];
$now = UcrmCatalogue::fromRecords($all, true);
$inf = 0; $modes = 0;
foreach ($all as $x) if (($x['ended_at_ts'] ?? null) === null) { $inf++; if (($x['kind'] ?? null) === 'mode') $modes++; }
echo json_encode(['ops' => $n, 'kinds' => $kinds, 'ids' => $ids, 'records' => count($all), 'inforce' => $inf,
                  'switch' => $modes === 0 ? 'none' : ($modes > 1 ? 'two' : ($now->direct() ? 'on' : 'off'))]), "\n";
