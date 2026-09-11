<?php
/**
 * test_empty_never_erases.php — an unfilled field is not an instruction to erase.
 *
 * Declaring the mailbox password in manifest.json was enough to blank a
 * working password. uCRM re-materialises its config.json from the manifest and
 * writes every declared field it holds no value for as "". A plain
 * array_merge then let that empty string beat the real value — which had been
 * restored from the ConfigVault moments earlier, precisely so that a
 * re-install would not lose it.
 *
 * The vault already refuses to "replace a vault with emptiness". The readers
 * that consume it did not follow the same rule, so the vault preserved a
 * secret that the very next merge threw away.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_empty_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0755, true);

/** Run a snippet with $GLOBALS['dataDir'] pointed at our directory. */
function inSub(string $root, string $dir, string $php): string
{
    $code = '<?php $GLOBALS["dataDir"] = ' . var_export($dir, true) . ';'
          . 'chdir(' . var_export($root, true) . ');' . $php;
    $f = $dir . '/probe_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($f, $code);
    $out = (string)shell_exec('php ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return trim($out);
}

// uCRM's config.json, as it looks when the manifest declares a field nobody
// has filled in yet.
file_put_contents($tmp . '/config.json', json_encode([
    'email_ai_mailbox_pw' => '',
    'email_ai_mailbox'    => '',
    'billing_model'       => '',
    'currency_code'       => '',
    'email_company_name'  => 'DishNet Africa Limited',
]));

echo "\nThe dispatcher's reader\n";
$got = inSub($root, $tmp,
    'require "lib/EmailTemplate.php"; require "lib/CustomerEmailDispatcher.php";'
  . '$e = CustomerEmailDispatcher::effectiveConfig(["email_ai_mailbox_pw" => "real-password",'
  . '  "email_ai_mailbox" => "accounts@dishnetuganda.com"]);'
  . 'echo $e["email_ai_mailbox_pw"], "|", $e["email_ai_mailbox"], "|", $e["email_company_name"];');
[$pw, $mbox, $brand] = array_pad(explode('|', $got), 3, '');
is_($pw === 'real-password', 'an unfilled password on disk does not erase a live one', $got);
is_($mbox === 'accounts@dishnetuganda.com', 'nor an unfilled mailbox address', $got);
is_($brand === 'DishNet Africa Limited',
    'while a real value on disk still wins, which is the whole point of the file', $got);

echo "\nThe same rule in the dunning reader\n";
file_put_contents($tmp . '/kyc_config.json', json_encode(['billing_model' => 'prepaid']));
file_put_contents($tmp . '/config.json', json_encode(['billing_model' => '']));
$got = inSub($root, $tmp,
    'require "lib/OverdueDunningHelpers.php"; $c = _dunningEffectiveConfig();'
  . 'echo ($c["billing_model"] ?? "(absent)");');
is_($got === 'prepaid',
    'a later empty file does not blank the prepaid setting that gates dunning', $got);

echo "\nAnd in the currency reader\n";
file_put_contents($tmp . '/kyc_config.json', json_encode(['currency_code' => 'UGX']));
file_put_contents($tmp . '/config.json', json_encode(['currency_code' => '']));
$got = inSub($root, $tmp,
    'require "lib/currency.php"; $c = dn_book_effective_config();'
  . 'echo ($c["currency_code"] ?? "(absent)");');
is_($got === 'UGX', 'the ledger currency cannot be blanked by an empty field', $got);

echo "\nOrder still matters for real values\n";
file_put_contents($tmp . '/config.json',     json_encode(['currency_code' => 'SSP']));
file_put_contents($tmp . '/kyc_config.json', json_encode(['currency_code' => 'UGX']));
$got = inSub($root, $tmp,
    'require "lib/currency.php"; $c = dn_book_effective_config();'
  . 'echo ($c["currency_code"] ?? "(absent)");');
is_($got === 'UGX', 'the last file to name a value still wins when it names one', $got);

echo "\nA key present only as empty is still carried\n";
file_put_contents($tmp . '/config.json',     json_encode(['brand_new_key' => '']));
@unlink($tmp . '/kyc_config.json');
$got = inSub($root, $tmp,
    'require "lib/currency.php"; $c = dn_book_effective_config();'
  . 'echo array_key_exists("brand_new_key", $c) ? "present" : "dropped";');
is_($got === 'present',
    'nothing is hidden — an empty value only loses against a real one', $got);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
