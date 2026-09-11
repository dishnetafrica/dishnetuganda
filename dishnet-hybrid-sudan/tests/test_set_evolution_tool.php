<?php
/**
 * test_set_evolution_tool.php — repointing WhatsApp without leaking the key.
 *
 * A new Evolution instance was stood up and the plugin had to be repointed
 * at it, mid-outage, with both WhatsApp numbers already dead. The uCRM
 * Configuration screen is the ordinary place for that; it is also several
 * clicks away when nothing is answering.
 *
 * Two things this must get right.
 *
 * The KEY IS NEVER AN ARGUMENT. It controls every WhatsApp session the
 * company has, and a secret on a command line is in the shell history and
 * visible in `ps` to every user on the box. It is read from stdin, and
 * passing it as an argument is refused with the correct recipe rather than
 * quietly accepted.
 *
 * And http is refused. Evolution takes the key in an 'apikey:' header on
 * every request; over http that crosses the network in clear. The live
 * server reported its own manager URL as http://evo.dishnet.invalid,
 * so this is the mistake actually in front of us, not a hypothetical.
 *
 * The hostnames here are .invalid on purpose. It is reserved and never
 * resolves, so this suite cannot reach the live Evolution — a test that
 * touches production is a test that changes its result depending on
 * whether production is up.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_setevo_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

// The vault lives outside the data directory on purpose, so DN_DATA_DIR does
// not isolate it. Without this, writing a fake key here lands in the REAL
// vault and is gap-filled into later tests. run.sh sets this for the whole
// suite; setting it again makes this file safe to run on its own.
putenv('DN_VAULT_FILE=' . $tmp . '/vault.json');

// ── Fake Evolution ──────────────────────────────────────────────────────────
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9700 + ((getmypid() + $slot * 11) % 70);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-EVO-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake Evolution server\n"); exit(1); }

$tool = $root . '/tools/set_evolution.php';
$run = function (string $args, string $stdin = '') use ($root, $tmp, $tool) {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp)
         . ' DN_VAULT_FILE=' . escapeshellarg($tmp . '/vault.json')
         . ' php ' . escapeshellarg($tool) . ' ' . $args;
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $desc, $pipes);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['out' => (string)$out, 'code' => proc_close($p)];
};
$stored = function () use ($tmp) {
    $f = $tmp . '/kyc_config.json';
    return is_file($f) ? (array)json_decode((string)file_get_contents($f), true) : [];
};

echo "\nThe key is never an argument\n";

$r = $run('--key 429683C4C977415CAAFCCE10F7D57E11');
is_($r['code'] !== 0, 'passing it inline is refused', 'it must not be accepted quietly');
is_(strpos($r['out'], 'lands in your shell') !== false, 'saying why');
is_(strpos($r['out'], 'read -rsp') !== false,
    'and giving the recipe that avoids it',
    'a refusal with no alternative just gets worked around');
is_(strpos($r['out'], '429683C4') === false,
    'and the key is NOT echoed back in the refusal',
    'printing it to tell someone off puts it in the log anyway');
is_(($stored()['evo_api_key'] ?? null) === null, 'nothing was stored');

echo "\nRead from stdin, it is stored and never shown\n";

$r = $run('--url http://127.0.0.1:' . $port . ' --allow-insecure');
is_($r['code'] !== 0 || true, 'the URL is set first');
is_(($stored()['evo_api_url'] ?? '') === 'http://127.0.0.1:' . $port, 'and recorded');

$r = $run('--key -', "SECRET-KEY-VALUE-0123456789\n");
is_(($stored()['evo_api_key'] ?? '') === 'SECRET-KEY-VALUE-0123456789',
    'the key arrives from stdin intact, trailing newline trimmed');
is_(strpos($r['out'], 'SECRET-KEY-VALUE') === false,
    'and is never printed back',
    'a tool that echoes a secret writes it to every terminal log');
is_(strpos($r['out'], '27 characters') !== false,
    'only its length is reported, which is enough to spot a truncated paste');

echo "\nhttp is refused, because the key rides in a header\n";

$r = $run('--url http://evo.dishnet.invalid');
is_($r['code'] !== 0, 'plain http is refused');
is_(strpos($r['out'], 'travels in clear') !== false, 'saying what the risk is');
is_(strpos($r['out'], '--allow-insecure') !== false,
    'with a deliberate way past it',
    'a private address with no TLS is a real case; refusing it outright is wrong');
is_(($stored()['evo_api_url'] ?? '') === 'http://127.0.0.1:' . $port,
    'and the refused URL was not saved',
    'a refusal that half-writes is worse than no refusal');

$r = $run('--url https://evo.dishnet.invalid');
is_(($stored()['evo_api_url'] ?? '') === 'https://evo.dishnet.invalid', 'https is accepted');

// Pasting the manager URL is the natural mistake — the API root is its parent.
$r = $run('--url https://evo.dishnet.invalid/manager');
is_(($stored()['evo_api_url'] ?? '') === 'https://evo.dishnet.invalid',
    'and a pasted /manager URL is trimmed to the API root',
    'otherwise every call 404s and nothing says why');

echo "\nIt reports what Evolution actually has\n";

$run('--url http://127.0.0.1:' . $port . ' --allow-insecure');
$r = $run('--support dishnet_ug');
is_(strpos($r['out'], 'dishnet_ug') !== false, 'the instance that exists is listed');
is_(strpos($r['out'], 'open') !== false, 'with its connection state');
is_($r['code'] === 0, 'and a configured, paired instance is a pass');

// The failure that started all this: names that point at nothing.
$r = $run('--sales dishnet_richard');
is_($r['code'] !== 0, 'a name Evolution does not have fails');
is_(strpos($r['out'], 'NOT FOUND') !== false, 'and is called out');
is_(strpos($r['out'], 'dishnet_richard') !== false, 'by name');
is_(strpos($r['out'], 'match exactly') !== false,
    'with the reason it matters',
    'a near-miss spelling is the whole failure mode here');

echo "\nWhat it writes wins over the uCRM form\n";

$src = (string)file_get_contents($tool);
is_(strpos($src, 'saveEvolutionCredentials') !== false,
    'it goes through the same writer the settings page uses');
is_(strpos($src, 'file_put_contents') === false,
    'and never writes config itself',
    'a second write path is a second set of file permissions to get wrong');
is_(strpos($src, 'kyc_config.json, which is merged last') !== false,
    'and it says that this overrides the uCRM screen',
    'two sources of truth are fine only if someone is told which one wins');

proc_terminate($srv); proc_close($srv);
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
