<?php
/**
 * test_set_config_tool.php — a settings screen must not lie about a number.
 *
 * The first version ran every value through FILTER_VALIDATE_BOOLEAN, which
 * counts only 1/true/on/yes as true. So wa_human_cooldown_minutes = 30
 * displayed as "OFF" — identical to the 0 that disables the stand-down rule
 * outright. Reading that screen, there was no way to tell a working thirty
 * minute cooldown from a setting that keeps the AI talking over colleagues.
 *
 * That mattered on the day: the human-takeover fix had just shipped, and
 * whether it did anything at all came down to that one number.
 *
 * So: booleans read ON/OFF, durations read as durations, text reads as text,
 * and 0 says what 0 does.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_setcfg_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

$run = function (array $args) use ($root, $tmp): array {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' php '
         . escapeshellarg($root . '/tools/set_config.php') . ' '
         . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
$store = function (array $kv) use ($tmp) { file_put_contents($tmp . '/kyc_config.json', json_encode($kv)); };
$saved = function (string $k) use ($tmp) {
    $j = @json_decode((string)@file_get_contents($tmp . '/kyc_config.json'), true);
    return is_array($j) ? ($j[$k] ?? null) : null;
};

echo "\nA duration is shown as a duration, never as a toggle\n";
$store(['wa_human_cooldown_minutes' => '30']);
[$c, $out] = $run([]);
is_(strpos($out, '30 minutes') !== false, '30 reads as "30 minutes"', $out);
is_(strpos($out, 'wa_human_cooldown_minutes   OFF') === false,
    'and never as OFF', 'this is the bug: 30 and 0 looked identical');

echo "\nAnd 0 says what 0 actually does\n";
$store(['wa_human_cooldown_minutes' => '0']);
[$c, $out] = $run([]);
is_(strpos($out, '0 minutes') !== false, 'it reads as "0 minutes"');
is_(strpos($out, 'NEVER stands down') !== false,
    'with the consequence spelled out',
    '0 disables the stand-down rule entirely — that is not a detail');

echo "\nA value that is not a number says so rather than being silently defaulted\n";
$store(['wa_human_cooldown_minutes' => 'yes']);
[$c, $out] = $run([]);
is_(strpos($out, '1440 default') !== false, 'it names the default it will fall back to');

echo "\nBooleans still read ON and OFF\n";
$store(['ai_qualification' => '1', 'ai_hardware_expert' => '0']);
[$c, $out] = $run([]);
is_(preg_match('/ai_qualification\s+ON/', $out) === 1, 'a set flag is ON');
is_(preg_match('/ai_hardware_expert\s+OFF/', $out) === 1, 'a cleared one is OFF');

echo "\nText settings are shown as the text a customer will read\n";
$store(['ai_currency' => 'ugx']);
[$c, $out] = $run([]);
is_(strpos($out, '"ugx"') !== false, 'quoted verbatim, case and all',
    'prices are printed next to this string exactly as typed');

echo "\nSetting one works, and reports what it did\n";
$store([]);
[$c, $out] = $run(['--key', 'ai_qualification', '--value', '1']);
is_($c === 0, 'exits clean');
is_((string)$saved('ai_qualification') === '1', 'and saved it');

echo "\nClearing returns it to the default\n";
[$c, $out] = $run(['--key', 'ai_qualification', '--clear']);
is_($c === 0 && $saved('ai_qualification') === null, 'the override is gone');

echo "\nThe values a customer reads get a note at the moment of setting\n";
// The only moment anyone is looking at this setting.
[$c, $out] = $run(['--key', 'ai_currency', '--value', 'ugx']);
is_(strpos($out, 'exactly as typed') !== false, 'lowercase currency is called out', $out);
is_(strpos($out, 'UGX') !== false, 'with what the flyer says');

[$c, $out] = $run(['--key', 'stock_statement', '--value', 'yes']);
is_(strpos($out, 'thin') !== false, 'a bare "yes" for stock is called out',
    'the assistant is told to answer stock questions from that line confidently');

[$c, $out] = $run(['--key', 'wa_human_cooldown_minutes', '--value', '0']);
is_(strpos($out, 'NEVER stands down') !== false, 'and so is a zero cooldown');
is_((string)$saved('wa_human_cooldown_minutes') === '0',
    'but it is still saved — the operator decides, the tool only tells them');

echo "\nQuotation branding is settable, and its default is named\n";
// An unset key here is not blank — QuotationService compiles a South Sudan
// phone number, so "not set" means Juba's number is on a Ugandan quote.
$store([]);
[$c, $out] = $run([]);
is_(strpos($out, 'quote_company_phone') !== false, 'the key is managed by the tool',
    'it was not, so the wrong number could not be corrected without a browser');
is_(strpos($out, '+211920000000') !== false,
    'and the default it falls back to is printed',
    'an unset key that silently means "Juba" has to say so');

[$c, $out] = $run(['--key', 'quote_company_phone', '--value', '+211920000000']);
is_(strpos($out, 'South Sudan number') !== false,
    'setting a +211 number on Ugandan quotes is called out', $out);
is_((string)$saved('quote_company_phone') === '+211920000000',
    'but still saved — the operator decides');

[$c, $out] = $run(['--key', 'quote_company_phone', '--value', '+256703834115']);
is_(strpos($out, 'South Sudan number') === false, 'a Uganda number draws no warning');

echo "\nAn unknown key is refused, and secrets are not managed here\n";
[$c, $out] = $run(['--key', 'claude_api_key', '--value', 'sk-test']);
is_($c !== 0, 'a key it does not manage is refused');
is_(strpos($out, 'encrypted') !== false, 'and points at the uCRM screen',
    'a secret typed into a shell lands in root history');
is_($saved('claude_api_key') === null, 'nothing was written');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
