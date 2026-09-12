<?php
declare(strict_types=1);
/**
 * test_manual_localisation.php — the staff manuals describe THIS country.
 *
 * Both were inherited whole from South Sudan. training.php and runbook.php
 * between them carried 24 South Sudan markers and not one Uganda one: staff
 * were taught to count an "SSP Bag" in a country that uses shillings, and the
 * runbook's incident page listed +211 921 443 002 as the person to call, with
 * a click-to-call link and a WhatsApp link. An agent following that page
 * during an outage would have phoned Juba.
 *
 * Nothing here checks prose quality. It checks that no country-specific fact
 * is welded into a file that is shipped to both countries — the values come
 * from configuration, or they are wrong somewhere.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root  = dirname(__DIR__);
$files = ['tabs/help/training.php', 'tabs/help/runbook.php',
          'tabs/help/faq.php', 'tabs/help/knowledge_base.php'];

echo "No hardcoded country facts in the staff manuals\n";
foreach ($files as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    // Strip comments — an explanation of what was wrong may name the old value.
    $code = preg_replace('!//.*$!m', '', $src);
    $code = preg_replace('!/\*.*?\*/!s', '', (string)$code) ?? '';

    is_(!preg_match('/\bSSP\b/', $code), "{$f}: no SSP");
    is_(!preg_match('/\+?211[\s\-]?9?\d{6,}/', $code), "{$f}: no South Sudan phone number");
    is_(!preg_match('/\bJuba\b/i', $code), "{$f}: no Juba");
    is_(!preg_match('/South Sudanese/i', $code), "{$f}: no South Sudanese currency name");
}

echo "\nThe values that vary by country come from configuration\n";
$rb = (string)file_get_contents($root . '/tabs/help/runbook.php');
is_(strpos($rb, 'CustomerContact::escalation') !== false,
    'runbook takes the escalation contact from CustomerContact');
is_(strpos($rb, 'CustomerContact::support') !== false, 'and the support line');
is_(strpos($rb, 'CustomerContact::accounts') !== false, 'and the accounts line');
is_(strpos($rb, 'report_timezone') !== false, 'and the backup hour from the timezone');

$tr = (string)file_get_contents($root . '/tabs/help/training.php');
is_(strpos($tr, 'dn_cur(') !== false, 'training takes the currency from dn_cur');
is_(strpos($tr, 'report_timezone') !== false, 'and the backup hour from the timezone');

echo "\nThe substitutions are PHP, not literal tags\n";
// training.php is a data array of single-quoted strings. A short-echo tag
// inside one renders as text on the page, which is how a fix becomes a worse
// bug. (And the closing tag is spelled out rather than written, because a
// literal one inside a // comment ends PHP mode — which it just did.)
is_(!preg_match("/'(?:head|body|title)'\s*=>\s*'[^']*<\?=/", $tr),
    'no short-echo tag is trapped inside a quoted string');
is_(substr_count($tr, '$_trCur') > 5, 'the currency variable is actually used');

echo "\nAnd the manual states the WhatsApp rule\n";
// The first version of this check passed on the phrase "is_admin only" in an
// unrelated sentence about creating staff accounts — it would have stayed
// green whether or not the rule was ever written down. A check that cannot
// fail is not a check.
is_((bool)preg_match('/WhatsApp is administrator-only/i', $tr),
    'the training manual states the rule in those words');
is_((bool)preg_match('/WhatsApp is administrator-only/i', $rb),
    'and so does the runbook');
is_(stripos($tr, 'wa_inbox_roles') !== false,
    'and names the one setting that can grant the shared inbox');
is_((bool)preg_match('/enforced by the server|not by hiding/i', $tr),
    'and says the server enforces it, not the menu');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
