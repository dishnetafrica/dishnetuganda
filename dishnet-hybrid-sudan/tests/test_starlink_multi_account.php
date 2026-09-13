<?php
declare(strict_types=1);
/**
 * test_starlink_multi_account.php — one login, several Starlink accounts.
 *
 * Starlink lets one login switch between accounts, and DishNet Uganda has
 * several. The account whose cookie we first imported turned out to have no
 * active kit, while the one customer bound in uCRM sits on a different
 * account — so a store holding a single session could only ever see whichever
 * account somebody last happened to be looking at.
 *
 * The bug that made this urgent: importCookie() loaded the ACTIVE record and
 * overwrote it, so every second account imported silently replaced the first.
 * A box with four Starlink accounts could hold exactly one.
 *
 * What these insist on:
 *
 *   · importing a second account ADDS it; the first keeps its own cookie
 *   · the cookie names its own account, so a person need not type it
 *   · switching is explicit, and switching to an account we do not hold FAILS
 *     rather than quietly creating a blank one
 *   · the single-session file already on the server reads without being
 *     rewritten, keeps its cookie and its state, and survives a second import
 *   · the keep-alive touches EVERY account and puts the selection back
 */
require_once dirname(__DIR__) . '/lib/StarlinkSessionStore.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

const A1 = 'ACC-DF-15744579-40001-43';
const A2 = 'ACC-DF-15973474-59163-60';

function freshStore(): array
{
    $base = sys_get_temp_dir() . '/dn_sl_multi_' . bin2hex(random_bytes(4));
    @mkdir($base . '/plugins/dishnet-hybrid-sudan', 0777, true);
    @mkdir($base . '/data', 0777, true);
    putenv('DN_PLUGIN_ROOT=' . $base . '/plugins/dishnet-hybrid-sudan');
    return [new StarlinkSessionStore($base . '/plugins/dishnet-hybrid-sudan', $base . '/data'), $base];
}

echo "\nA second account is added, not substituted\n";
[$s] = freshStore();
$r1 = $s->importCookie('Starlink.Com.Sso=aaa; starlink.com.account_number=' . A1, 'tester');
$r2 = $s->importCookie('Starlink.Com.Sso=bbb; starlink.com.account_number=' . A2, 'tester');
t('the first import names its account', $r1['account'], A1);
t('and holds one',                      $r1['accounts_held'], 1);
t('the second names its own',           $r2['account'], A2);
t('and holds two',                      $r2['accounts_held'], 2);
t('both are listed',                    $s->accounts(), [A1, A2]);
t('the newest is active',               $s->active(), A2);
t('with its own cookie',                $s->cookie(), 'Starlink.Com.Sso=bbb; starlink.com.account_number=' . A2);

echo "\nSwitching, and refusing to invent\n";
is_($s->useAccount(strtolower(A1)), 'a switch ignores case');
t('the selection moved',   $s->active(), A1);
t('and the cookie with it', $s->cookie(), 'Starlink.Com.Sso=aaa; starlink.com.account_number=' . A1);
t('an account we do not hold is refused', $s->useAccount('ACC-NOT-HERE-1'), false);
t('and the selection is unchanged',       $s->active(), A1);
is_(!in_array('ACC-NOT-HERE-1', $s->accounts(), true), 'no blank account was created');

echo "\nPer-account state does not leak between accounts\n";
$s->useAccount(A1);
$s->markFailure('A1 is unwell');
$s->useAccount(A2);
t('A2 is untouched',            (string)$s->load()['state'], StarlinkSessionStore::STATE_ACTIVE);
t('and carries no error',       (string)$s->load()['last_error'], '');
$s->useAccount(A1);
is_(strpos((string)$s->load()['last_error'], 'A1 is unwell') !== false, 'while A1 kept its own');
t('and its own failure count',  (int)$s->load()['consecutive_failures'], 1);

echo "\nForgetting one leaves the other\n";
t('dropping an account we hold',     $s->forgetAccount(A2), true);
t('leaves just the one',             $s->accounts(), [A1]);
t('active falls back to it',         $s->active(), A1);
t('dropping one we do not hold fails', $s->forgetAccount(A2), false);

echo "\nA cookie with no account number still has somewhere to live\n";
[$s2] = freshStore();
$r = $s2->importCookie('Starlink.Com.Sso=ccc', 'tester');
t('it is held under a named placeholder', $r['account'], StarlinkSessionStore::KEY_UNKNOWN);
t('and its cookie is retrievable',        $s2->cookie(), 'Starlink.Com.Sso=ccc');
is_(strpos($r['account'], 'ACC') === false, 'and is not passed off as an account number');

echo "\nThe single-session file already on the server\n";
[$s3, $base3] = freshStore();
file_put_contents($s3->path(), json_encode([
    'account_email'  => 'accounts@dishnetuganda.com',
    'account_number' => A1,
    'cookie_enc'     => $s3->encrypt('Starlink.Com.Sso=legacy'),
    'imported_at'    => '2026-09-13 05:45:00', 'imported_by' => 'root',
    'state'          => StarlinkSessionStore::STATE_EXPIRED,
    'consecutive_failures' => 0,
], JSON_PRETTY_PRINT));
$s4 = new StarlinkSessionStore($base3 . '/plugins/dishnet-hybrid-sudan', $base3 . '/data');
t('it reads as one account, keyed by its own number', $s4->accounts(), [A1]);
t('which is active',        $s4->active(), A1);
t('its cookie survives',    $s4->cookie(), 'Starlink.Com.Sso=legacy');
t('and its state',          (string)$s4->load()['state'], StarlinkSessionStore::STATE_EXPIRED);
t('and the email it knew',  (string)$s4->load()['account_email'], 'accounts@dishnetuganda.com');
is_(strpos((string)file_get_contents($s4->path()), '"accounts"') === false,
    'and the file is NOT rewritten on a read — a rollback loses nothing');
$s4->importCookie('Starlink.Com.Sso=new; starlink.com.account_number=' . A2, 'tester');
t('adding a second keeps the legacy one', $s4->accounts(), [A1, A2]);
$s4->useAccount(A1);
t('with its cookie intact',               $s4->cookie(), 'Starlink.Com.Sso=legacy');

echo "\nAn empty store answers honestly\n";
[$s5] = freshStore();
t('no accounts',         $s5->accounts(), []);
t('no active one',       $s5->active(), '');
t('no cookie',           $s5->cookie(), '');
t('nothing to switch to', $s5->useAccount(A1), false);
t('state is absent',     (string)$s5->load()['state'], StarlinkSessionStore::STATE_ABSENT);

echo "\nThe keep-alive visits every account and puts the selection back\n";
$ka = (string)file_get_contents(dirname(__DIR__) . '/cron/starlink_keepalive.php');
is_(strpos($ka, '$store->accounts()') !== false, 'it iterates the accounts');
is_(strpos($ka, 'foreach ($accounts as') !== false, 'in a loop, not once');
is_(strpos($ka, '$restore = $store->active()') !== false, 'remembering the selection');
is_(strpos($ka, 'if ($restore !== \'\') $store->useAccount($restore)') !== false,
    'and restoring it at the end');
is_(preg_match('/^\s*exit\s*[(;]/m', $ka) === 0,
    'and still never exit()s — master.php includes it');

// Starlink authorises telemetryagg separately. A keep-alive that exercises
// only the account layer lets the telemetry one expire, which reads as
// "session accepted YES" beside a usage call answering 401.
is_(strpos($ka, 'StarlinkUsage::PATH') !== false,
    'it also touches the TELEMETRY layer, not just the account one');
is_(strpos($ka, 'NOT telemetry') !== false,
    'and says so plainly when one layer passes and the other does not');

// The observed session survived 3m25s. A heartbeat slower than that can never
// land in time, and no refresh endpoint mints a new token — so the interval is
// the only thing holding a session up.
$masterSrc = (string)file_get_contents(dirname(__DIR__) . '/cron/master.php');
preg_match("/'starlink_alive'\s*=>\s*\['interval'\s*=>\s*(\d+)/", $masterSrc, $km);
is_(isset($km[1]) && (int)$km[1] <= 180,
    'and runs faster than the shortest session we have measured ('
    . ($km[1] ?? '?') . 's)');

// ── The swap: one cookie, every account ─────────────────────────────────
//
// Starlink selects the account from the starlink.com.account_number COOKIE,
// not from the path. dishnet-data-report has done it this way for years and
// its own commit message says so: "primary cookie with account_number swap,
// always." Sending an account in the path that the cookie disagrees with gets
// a not_found that looks exactly like a missing endpoint — which produced two
// wrong conclusions here before the mechanism was read rather than inferred.
require_once dirname(__DIR__) . '/lib/StarlinkPortalConnector.php';

echo "\nThe account_number swap\n";
$jar = '_ga=x; Starlink.Com.Sso=abc; starlink.com.account_number=' . A1 . '; __stripe_mid=y';
$sw  = StarlinkPortalConnector::swapAccount($jar, strtolower(A2));
is_(strpos($sw, 'starlink.com.account_number=' . A2) !== false, 'the account is replaced and upper-cased');
is_(strpos($sw, A1) === false,                    'the old account is gone');
is_(strpos($sw, 'Starlink.Com.Sso=abc') !== false, 'every other cookie survives');
is_(strpos($sw, '__stripe_mid=y') !== false,       'including ones after it');
t('and only one account segment remains',
    substr_count($sw, 'account_number='), 1);

$none = '_ga=x; Starlink.Com.Sso=abc';
is_(strpos(StarlinkPortalConnector::swapAccount($none, A2),
    '; starlink.com.account_number=' . A2) !== false,
    'a jar without the segment gets one appended');

t('an empty account changes nothing', StarlinkPortalConnector::swapAccount($jar, ''), $jar);
t('an empty cookie stays empty',      StarlinkPortalConnector::swapAccount('', A2), '');
t('swapping twice does not stack',
    substr_count(StarlinkPortalConnector::swapAccount(
        StarlinkPortalConnector::swapAccount($jar, A2), A1), 'account_number='), 1);

echo "\nScoping a connector, without corrupting the stored session\n";
[$s6, $base6] = freshStore();
$s6->importCookie($jar, 'tester', '', A1);
$seen = [];
$conn = new StarlinkPortalConnector($s6, [], function (string $m, string $u, array $h) use (&$seen) {
    foreach ($h as $line) {
        if (stripos($line, 'cookie:') === 0) $seen[] = $line;
    }
    return ['code' => 200, 'body' => '{"ok":true}', 'cookies' => [], 'headers' => []];
});
$conn->raw('GET', '/api/anything');
is_(strpos($seen[0] ?? '', A1) !== false, 'unscoped, it asks as the cookie\'s own account', $seen[0] ?? '(no header)');

$conn->scopeTo(A2);
$conn->raw('GET', '/api/anything');
is_(strpos($seen[1] ?? '', A2) !== false, 'scoped, it asks as the other account', $seen[1] ?? '(no header)');
t('and describe() says so',  strpos($conn->describe(), A2) !== false, true);
t('scopedTo() reports it',   $conn->scopedTo(), A2);

// The swap must never be written back: the stored cookie keeps the account it
// was signed in as, or a rotated-cookie merge quietly moves the session.
$s6->useAccount(A1);
is_(strpos($s6->cookie(), A1) !== false && strpos($s6->cookie(), A2) === false,
    'the STORED cookie still says ' . A1 . ' — the swap never persisted', $s6->cookie());

$conn->scopeTo('');
$conn->raw('GET', '/api/anything');
is_(strpos($seen[2] ?? '', A1) !== false, 'clearing the scope goes back to the cookie\'s own account');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
