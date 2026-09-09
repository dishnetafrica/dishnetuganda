<?php
declare(strict_types=1);

/**
 * test_email_identity.php — the identity checker must fail loudly on Sudan
 * defaults and pass quietly on a configured Uganda box.
 *
 * The tool exists because a Ugandan quotation went out with a Juba Reply-To.
 * A checker that cannot tell those two states apart would have shipped that
 * bug just as happily, so both states are asserted here.
 */

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

echo "\nAn unconfigured box is caught before a customer sees it\n";

/** Run the tool against a data dir we control. */
function identity_run(string $dir): array
{
    $root = dirname(__DIR__);
    $cmd  = 'DN_DATA_DIR=' . escapeshellarg($dir)
          . ' UCRM_PLUGIN_DATA_DIR=' . escapeshellarg($dir)
          . ' php ' . escapeshellarg($root . '/tools/email_identity.php') . ' 2>&1';
    $out  = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
}

$tmp = sys_get_temp_dir() . '/dn_ident_' . getmypid();
@mkdir($tmp . '/sudan', 0777, true);
@mkdir($tmp . '/uganda', 0777, true);

// Nothing configured: the Sudan defaults are all that is left.
[$outS, $codeS] = identity_run($tmp . '/sudan');
is_($codeS === 1, 'an unconfigured box exits non-zero', 'exit: ' . $codeS);
is_(strpos($outS, 'info@dishnetafrica.com') !== false,
    'and names the Sudan address it would have replied to');
is_(strpos($outS, 'contact field(s) are still on the Sudan default') !== false,
    'and says so in words an operator can act on');
is_(strpos($outS, 'accent') !== false && strpos($outS, '7 contact field') === false,
    'the accent colour is reported but never counted as a contact detail');

echo "\nA configured Uganda box reports clean\n";

// Uganda on disk, and nothing passed in by any caller.
file_put_contents($tmp . '/uganda/config.json', json_encode([
    'email_company_name'  => 'DishNet Africa Limited',
    'email_locality'      => 'Kampala, Uganda',
    'email_website'       => 'dishnetuganda.com',
    'email_support_phone' => '+256 705 993 348',
    'email_support_wa'    => '256705993348',
    'email_reply_to'      => 'accounts@dishnetuganda.com',
]));
[$outU, $codeU] = identity_run($tmp . '/uganda');
is_($codeU === 0, 'a configured Uganda box exits clean', 'exit: ' . $codeU . "\n" . $outU);
is_(strpos($outU, 'accounts@dishnetuganda.com') !== false,
    'and confirms replies reach the Uganda inbox');
is_(strpos($outU, 'info@dishnetafrica.com') === false,
    'with no Sudan address anywhere in the report');
is_(substr_count($outU, 'DISK') === 6,
    'every contact field is read from disk, not defaulted', 'got: ' . $outU);

@array_map('unlink', glob($tmp . '/*/*.json') ?: []);
@rmdir($tmp . '/sudan'); @rmdir($tmp . '/uganda'); @rmdir($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
