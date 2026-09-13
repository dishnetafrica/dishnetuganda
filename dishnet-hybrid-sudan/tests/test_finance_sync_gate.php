<?php
declare(strict_types=1);
/**
 * test_finance_sync_gate.php — making a bound kit visible to Finance's sync.
 *
 * dishnet-starlink-finance creates kits itself, from uCRM, in
 * syncStarlinkServices(). It skips ours because it scans a fixed list of
 * service fields for /\b(KIT[A-Z0-9]{8,})\b/i and our kit number is in the
 * starlinkDetails custom attribute, which is not in that list; and because it
 * only considers services whose plan name contains "starlink".
 *
 * These pin the reproduction of Finance's gates (a drift there means we tell
 * the operator a kit is ready when Finance will still skip it), and the two
 * limits on what the tool is allowed to change.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

$root = dirname(__DIR__);
$src  = (string)file_get_contents($root . '/tools/finance_sync_gate.php');

/** Source with comments removed — a rule about behaviour belongs against code. */
function codeOnly2(string $s): string {
    $o = '';
    foreach (token_get_all($s) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}
$code = codeOnly2($src);

// The tool's three pure functions, lifted out so they can be exercised
// directly. Loading the tool itself would run it against the live database.
eval(substr($code, (int)strpos($code, 'function financeNoteText'),
            (int)strpos($code, '$live = $ea->liveAssignments();') - (int)strpos($code, 'function financeNoteText')));

echo "\nIt reproduces Finance's kit extraction exactly\n";
// Finance concatenates these eight fields and no others. A field we add that
// it does not read would make us call a kit ready that it will skip.
$svc = ['name' => '', 'note' => 'KIT404246364BX6', 'invoiceLabel' => '', 'street1' => '',
        'street2' => '', 'servicePlanName' => '', 'addressGpsLat' => '', 'contractLengthType' => ''];
t('finds a kit in the note', financeKit(financeNoteText($svc)), 'KIT404246364BX6');

$attrOnly = ['name' => 'Residential', 'note' => '', 'invoiceLabel' => '',
             'attributes' => [['key' => 'starlinkDetails', 'value' => 'KIT404246364BX6']]];
t('does NOT find one in a custom attribute — the whole problem',
  financeKit(financeNoteText($attrOnly)), '');

t('finds one in invoiceLabel', financeKit(financeNoteText(['invoiceLabel' => 'KIT404246364BX6'])), 'KIT404246364BX6');
t('and in the service name',  financeKit(financeNoteText(['name' => 'Site A KIT404246364BX6'])), 'KIT404246364BX6');
t('uppercases what it finds', financeKit(financeNoteText(['note' => 'kit404246364bx6'])), 'KIT404246364BX6');

echo "\nThe 8-character minimum is Finance's, not ours\n";
// Its regex is KIT[A-Z0-9]{8,}: eight or more characters AFTER the letters
// KIT. A shorter kit number is invisible to Finance however it is recorded,
// which is a fact about the kit, not about our write — so the tool warns
// rather than writing a note that could never work.
t('eight after KIT matches',       financeKit(financeNoteText(['note' => 'KIT12345678'])), 'KIT12345678');
t('seven after KIT does not',      financeKit(financeNoteText(['note' => 'KIT1234567'])),  '');
t('and four certainly does not',   financeKit(financeNoteText(['note' => 'KIT1234'])),     '');
t('our real kit is long enough',   financeKit(financeNoteText(['note' => 'KIT404246364BX6'])), 'KIT404246364BX6');
is_(financeTooShort('KIT1234567') === true,  'the tool knows a short kit is hopeless');
is_(financeTooShort('KIT404246364BX6') === false, 'and that a real one is not');

echo "\nThe plan-name gate is Finance's substring, not a word\n";
is_(financeStarlink('Starlink Residential') === true,  'Starlink matches');
is_(financeStarlink('STARLINK BUSINESS')    === true,  'and case does not matter');
is_(financeStarlink('Residential Lite ( up to 100 Mbps)') === false,
    'our Uganda plan does NOT match — which is why Finance skips it');
is_(financeStarlink('') === false, 'an empty plan name does not match');

echo "\nIt writes one field, and only to uCRM\n";
// The kit number goes where uCRM services already carry it in South Sudan.
// Anything else written from here would be us deciding something that is not
// ours to decide.
preg_match_all('/->patch\(\s*([^,]+),\s*(\[[^\]]*\])/', $code, $pm);
t('exactly one patch call', count($pm[0]), 1);
is_(strpos($pm[1][0] ?? '', 'clients/services/') !== false, 'against the service endpoint');
t('carrying only the note', trim($pm[2][0] ?? ''), "['note' => \$newNote]");

is_(strpos($code, "'servicePlanName' =>") === false, 'it never writes a plan name');
is_(strpos($code, "'price' =>") === false, 'and never a price');
is_(preg_match('/->(post|put|delete)\(/', $code) === 0, 'no other write verb is used');

echo "\nThe note is appended, never replaced\n";
// A service note can hold an engineer's comment. Overwriting it to insert a
// kit number would destroy something a person wrote.
is_(preg_match('/\$newNote\s*=\s*trim\(\$note === \'\' \? \$kit : \(\$note \. "\\\\n" \. \$kit\)\)/', $code) === 1,
    'an existing note is kept and the kit appended below it');

$note = "Installed by Moses\nrouter on the mast";
$kit  = 'KIT404246364BX6';
$new  = trim($note === '' ? $kit : ($note . "\n" . $kit));
is_(strpos($new, 'Installed by Moses') === 0, 'the original text survives');
t('and the kit is findable afterwards', financeKit(financeNoteText(['note' => $new])), $kit);

echo "\nReport is the default; writing is opted into\n";
is_(preg_match('/\$fix\s*=\s*in_array\(\'--fix\'/', $code) === 1, '--fix is required to write');
is_(preg_match('/if\s*\(!\$fix\)\s*\{[^}]*report only/s', $src) === 1,
    'without it the tool says so and writes nothing');

echo "\nIt refuses to guess when it cannot see\n";
is_(strpos($code, 'isConfigured()') !== false, 'an unreachable uCRM stops it');
is_(preg_match('/exit\(1\);/', $code) === 1 || substr_count($code, 'exit(1)') >= 1,
    'and it exits non-zero rather than reporting an empty all-clear');
is_(strpos($src, 'records no uCRM service') !== false,
    'an assignment with no service is blocked, not silently skipped');

echo "\nIt verifies its own write instead of trusting it\n";
// uCRM accepting a PATCH is not the same as the kit being findable afterwards.
is_(preg_match('/\$after\s*=\s*financeKit\(financeNoteText\(\$res\)\)/', $code) === 1,
    'it re-extracts the kit from what uCRM returned');
is_(strpos($src, 'UNSURE') !== false, 'and says so when the answer is not what it wrote');

echo "\nequipment_assignments stays the source of the kit number\n";
is_(strpos($code, '$ea->liveAssignments()') !== false, 'the kit comes from the binding');
is_(preg_match('/\$kit\s*=\s*strtoupper\(trim\(\(string\)\$a\[\'kit_serial\'\]\)\)/', $code) === 1,
    'read straight off the assignment, never composed');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
