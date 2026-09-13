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

echo "\nIt reproduces Finance's plan-name expression, ?? and not ||\n";
// $planName = $svc['servicePlanName'] ?? $svc['name'] ?? '';
// This tool first checked both fields with an OR and reported a service READY
// that Finance will skip. ?? stops at the first key that is set, so a present
// servicePlanName means the service name is never consulted at all.
t('servicePlanName wins outright',
  financePlanName(['servicePlanName' => 'Residential Lite', 'name' => 'Starlink Residential']),
  'Residential Lite');
is_(financeStarlink(financePlanName(
        ['servicePlanName' => 'Residential Lite ( up to 100 Mbps)',
         'name' => 'Site : KIT404246364BX6  Service Plan: Starlink Residential'])) === false,
    'the real Uganda service FAILS the gate, however Starlink-ish its name is');
t('an empty servicePlanName does not fall through either',
  financePlanName(['servicePlanName' => '', 'name' => 'Starlink Residential']), '');
t('only an absent one reaches the name',
  financePlanName(['name' => 'Starlink Residential']), 'Starlink Residential');
t('and absent both is empty', financePlanName([]), '');

echo "\nIt writes two fields at most, each behind its own flag\n";
// The note carries the kit number. The plan name is a separate, louder change
// -- it shows on every invoice for every service on that plan -- so it has its
// own flag and is never done as a side effect of --fix.
preg_match_all('/->patch\(\s*([^,]+),\s*(\[[^\]]*\])/', $code, $pm);
t('exactly two patch calls', count($pm[0]), 2);
$patches = [];
foreach ($pm[1] as $i => $path) $patches[trim($pm[2][$i])] = trim($path);
is_(isset($patches["['note' => \$newNote]"]), 'one sends only the note');
is_(strpos($patches["['note' => \$newNote]"] ?? '', 'clients/services/') !== false,
    'to the service endpoint');
is_(isset($patches["['name' => \$newPlan]"]), 'the other sends only the plan name');
is_(strpos($patches["['name' => \$newPlan]"] ?? '', 'service-plans/') !== false,
    'to the service-plan endpoint');

is_(strpos($code, "'price' =>") === false, 'neither ever carries a price');
is_(preg_match('/->(post|put|delete)\(/', $code) === 0, 'no other write verb is used');

echo "\nThe plan rename is opted into separately\n";
is_(preg_match('/\$fixPlan\s*=\s*in_array\(\'--fix-plan\'/', $code) === 1, '--fix-plan exists');
is_(preg_match('/if\s*\(!\$fixPlan\)/', $code) === 1, 'and without it the rename is skipped');
is_(preg_match('/\$fix\s*\|\|\s*\$fixPlan|\$fixPlan\s*\|\|\s*\$fix/', $code) === 0,
    'the two flags are never conflated');
is_(strpos($src, 'every service on it, not only this one') !== false,
    'the blast radius is stated before it is done');
is_(preg_match('/financeStarlink\(\$got\)/', $code) === 1,
    'the rename is judged by Finance\'s own test on what uCRM returned');
is_(strpos($code, '$planDone[$planId]') !== false,
    'a plan shared by two kits is renamed once');

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
