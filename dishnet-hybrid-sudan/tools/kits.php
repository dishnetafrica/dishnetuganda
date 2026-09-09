<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * kits.php — which kit went to which customer.
 *
 *   php tools/kits.php                          what we hold, and who has what
 *   php tools/kits.php --client 4021            what this customer has
 *   php tools/kits.php --kit KIT-0123-4567      one kit, and everywhere it has been
 *   php tools/kits.php --assign KIT-0123-4567 --client 4021 --by bhavin
 *   php tools/kits.php --return KIT-0123-4567 --by bhavin
 *
 * Starlink's own emails put kits in here as they are ordered, shipped and
 * activated. What those emails cannot say is whose hands a kit ended up in —
 * so assignment is always a person recording a handover, and their word wins
 * over anything inferred from an inbox.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/KitRegister.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$reg = new KitRegister($pdo);

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$has = function (string $f) use ($args) { return in_array($f, $args, true); };

function row(array $k): void
{
    printf("  %-18s %-10s %-9s %-16s %s\n",
        substr((string)$k['kit_id'], 0, 18),
        (string)$k['status'],
        ((int)$k['crm_client_id'] ?: '—'),
        substr((string)$k['order_reference'], 0, 16),
        substr((string)$k['assigned_at'], 0, 16));
}
function head(): void
{
    printf("\n  %-18s %-10s %-9s %-16s %s\n", 'KIT', 'STATUS', 'CLIENT', 'ORDER', 'ASSIGNED');
    printf("  %-18s %-10s %-9s %-16s %s\n", str_repeat('-', 18), str_repeat('-', 10),
        str_repeat('-', 9), str_repeat('-', 16), str_repeat('-', 16));
}

if ($has('--assign')) {
    $r = $reg->assign($value('--assign'), (int)$value('--client'),
                      $value('--by') ?: 'cli', $value('--note'));
    if (empty($r['ok'])) { echo "\n  " . $r['error'] . "\n\n"; exit(1); }
    echo "\n  Recorded.\n";
    if ($r['moved_from'] > 0) {
        echo "  This kit was with client {$r['moved_from']} — the move is in its history.\n";
    }
    echo "\n";
    exit(0);
}

if ($has('--return')) {
    $r = $reg->markReturned($value('--return'), $value('--by') ?: 'cli', $value('--note'));
    echo empty($r['ok']) ? "\n  " . $r['error'] . "\n\n" : "\n  Recorded as returned.\n\n";
    exit(empty($r['ok']) ? 1 : 0);
}

if ($value('--kit') !== '') {
    $k = $reg->find($value('--kit'));
    if ($k === null) { echo "\n  No kit by that number.\n\n"; exit(1); }
    head(); row($k);
    echo "\n  EVERYWHERE IT HAS BEEN\n";
    foreach ($reg->history((string)$k['kit_id']) as $h) {
        printf("    %-19s %-34s %s\n", substr((string)$h['at'], 0, 19),
            substr((string)$h['what'], 0, 34), (string)$h['who']);
    }
    echo "\n";
    exit(0);
}

if ($value('--client') !== '') {
    $kits = $reg->forClient((int)$value('--client'));
    if ($kits === []) { echo "\n  This customer holds no kit we know of.\n\n"; exit(1); }
    head();
    foreach ($kits as $k) row($k);
    echo "\n";
    exit(0);
}

$counts = $reg->counts();
echo "\n  STARLINK KITS\n";
if ($counts === []) {
    echo "\n  None recorded yet. They arrive from Starlink's own emails as orders\n";
    echo "  are confirmed and shipped, and from a person recording a handover:\n\n";
    echo "    php tools/kits.php --assign <kit> --client <id> --by <name>\n\n";
    exit(0);
}
foreach ($counts as $k => $v) printf("    %-10s %d\n", $k, $v);

$free = $reg->unassigned();
if ($free !== []) {
    echo "\n  NOBODY HAS THESE\n";
    head();
    foreach ($free as $k) row($k);
}
echo "\n  php tools/kits.php --kit <number>     one kit, and everywhere it has been\n";
echo "  php tools/kits.php --client <id>     what one customer holds\n\n";
exit(0);
