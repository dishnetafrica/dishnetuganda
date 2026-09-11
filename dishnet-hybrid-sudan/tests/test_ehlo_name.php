<?php
/**
 * test_ehlo_name.php — what this server calls itself at EHLO.
 *
 * Sending through the company's own Stalwart failed at the first command:
 *
 *     550 5.5.0 Invalid EHLO domain.
 *
 * The plugin announced itself with gethostname(), which inside a container
 * is its hex id — "1c8f5997cf51". That is not a domain, and a strict server
 * is right to refuse it. Gmail never complained, so it went unnoticed for as
 * long as mail went through Gmail.
 *
 * The sender's own domain is the honest answer: mail from
 * no-reply@dishnetuganda.com announces dishnetuganda.com, which resolves and
 * matches the envelope.
 *
 * Three senders had the same line. Fixing only the one that failed would
 * leave the dunning mail and the daily report to fail the same way, on a
 * cron, where nobody is watching the output.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/MailService.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function eq($got, $want, string $m): void { is_($got === $want, $m, "got: $got\n       want: $want"); }

echo "\nThe sender's domain is what we announce\n";

eq(MailService::ehloName(['from' => 'no-reply@dishnetuganda.com']), 'dishnetuganda.com',
   'from the From address');
eq(MailService::ehloName(['user' => 'accounts@dishnetuganda.com']), 'dishnetuganda.com',
   'or the login when From is unset');
eq(MailService::ehloName(['from' => 'a@b.com', 'user' => 'c@d.com']), 'b.com',
   'From wins over the login');
eq(MailService::ehloName(['from' => '  no-reply@dishnetuganda.com  ']), 'dishnetuganda.com',
   'and surrounding space does not become part of it');
eq(MailService::ehloName(['from' => 'odd"name"@dishnetuganda.com']), 'dishnetuganda.com',
   'an @ inside the local part does not split it early',
   'splitting on the FIRST @ would announce a fragment of the address');

echo "\nAn operator can override it\n";
eq(MailService::ehloName(['ehlo' => 'mail.dishnetuganda.com', 'from' => 'a@b.com']),
   'mail.dishnetuganda.com', 'ehlo is honoured above everything');
eq(MailService::ehloName(['smtp_ehlo' => 'mx.dishnetuganda.com', 'from' => 'a@b.com']),
   'mx.dishnetuganda.com', 'and so is the smtp_ehlo spelling');
eq(MailService::ehloName(['ehlo' => '   ', 'from' => 'a@b.com']), 'b.com',
   'a blank override falls through rather than sending nothing');

echo "\nA container id never reaches the wire\n";

// The exact failure: a bare hostname with no dot is not a domain, and this
// is the last thing standing between the bug and a live SMTP session.
eq(MailService::ehloName([]), 'localhost',
   'with nothing to go on it says localhost, not the container id');
eq(MailService::ehloName(['from' => 'root@localhost']), 'localhost',
   'a dotless sender domain is not announced as a domain');
eq(MailService::ehloName(['from' => 'not-an-address']), 'localhost',
   'and neither is something with no @ at all');

$hn = (string)gethostname();
if ($hn !== '' && strpos($hn, '.') === false) {
    is_(MailService::ehloName([]) !== $hn,
        'this machine\'s own dotless hostname is refused too',
        'that is exactly the value Stalwart rejected');
} else {
    ok('(this machine has a dotted hostname, so the dotless case is covered above)');
}

echo "\nEvery SMTP sender in the plugin uses it\n";

// Three files opened an SMTP conversation. Two of them are crons, so their
// version of this bug would fail where nobody reads the output.
foreach (['lib/MailService.php'           => 'the quote and customer mail',
          'lib/OverdueDunningHelpers.php' => 'the overdue dunning cron',
          'lib/DailyReportService.php'    => 'the daily report cron'] as $f => $what) {
    $src = (string)file_get_contents($root . '/' . $f);
    is_(preg_match('/EHLO[^\\n]*gethostname\\s*\\(/', $src) !== 1,
        $what . ' does not announce gethostname()',
        $f . ' still has the original line');
    is_(strpos($src, 'ehloName(') !== false,
        '  and goes through the shared rule');
}

// The scope trap: each file names its settings differently, so a
// copy-pasted fix compiles and silently passes an undefined variable —
// which resolves to an empty array, falls through to gethostname(), and
// restores the very bug being fixed. Caught once already in this change.
$dun = (string)file_get_contents($root . '/lib/OverdueDunningHelpers.php');
is_(strpos($dun, 'ehloName($cfg)') === false,
    'and none of them passes a variable that does not exist there',
    'OverdueDunningHelpers names its settings $s; $cfg would be undefined');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
