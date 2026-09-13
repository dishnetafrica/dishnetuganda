<?php
declare(strict_types=1);
/**
 * test_starlink_session_page.php — pasting a cookie in a browser, safely.
 *
 * The CLI tool refuses anything but a terminal because a cookie on a command
 * line survives in shell history and is visible to anyone running ps. That
 * reason does not apply to a form field, and pasting 5,239 characters through
 * `docker exec -it` already produced one truncated paste.
 *
 * But a page that takes a live session credential has to earn it. These check
 * the things that would make it a liability rather than a convenience.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$page = (string)file_get_contents(dirname(__DIR__) . '/tabs/admin/starlink_session.php');
$pub  = (string)file_get_contents(dirname(__DIR__) . '/public.php');

echo "\nOnly an administrator, and it says so before anything else\n";
is_(strpos($page, "\$retailer['is_admin']") !== false, 'the page guards itself');
is_(preg_match('/is_admin.*\n.*Admin access required.*\n.*return;/', $page) === 1
    || strpos($page, 'Admin access required') < strpos($page, 'ss_action'),
    'and returns before it looks at any input');
is_(strpos($pub, "'starlink_session'     => '*admin'") !== false,
    'and the permission map says admin only');
is_(strpos($pub, "'starlink_session' => 'tabs/admin/starlink_session.php'") !== false,
    'the route exists, so the guard is actually reachable');
is_(strpos($pub, "'id'=>'starlink_session'") !== false, 'and it is listed for admins');

echo "\nThe cookie never travels anywhere it would be logged\n";
is_(strpos($page, "\$_GET['ss_cookie']") === false, 'it is never read from the URL');
is_(substr_count($page, "REQUEST_METHOD'] === 'POST'") >= 3, 'every action is POST-gated');
is_(substr_count($page, 'csrfCheck()') >= 3, 'and every one checks CSRF');
is_(substr_count($page, 'csrfField()') >= 3, 'with a token in every form');

echo "\nIt is never echoed back\n";
is_(strpos($page, 'value="<?= $h($_POST[\'ss_cookie\']') === false,
    'the textarea does not round-trip the paste');
is_(strpos($page, "\$_POST['ss_cookie'] = '';") !== false,
    'and the posted value is cleared after import');
is_(strpos($page, '$ssStore->cookie()') === false,
    'the page never asks the store for the cookie in the clear');
is_(preg_match('/echo[^;]*cookie_enc/', $page) === 0, 'nor for the encrypted one');

echo "\nA browser paste is cleaned the way a browser breaks it\n";
// Textareas wrap long pastes. A jar carrying newlines is rejected by Starlink
// with nothing to explain why.
is_(strpos($page, "preg_replace('/\\s*[\\r\\n]+\\s*/', ' '") !== false,
    'newlines from a wrapped paste are folded to spaces');

echo "\nAn accepted paste is proved, not assumed\n";
is_(strpos($page, '->verify()') !== false, 'it asks Starlink whether the cookie works');
is_(strpos($page, 'did NOT accept it') !== false,
    'and says so when Starlink refuses — a stored-but-dead cookie looks like success otherwise');

echo "\nIt tells the operator the two things that catch people out\n";
is_(strpos($page, 'Signing out revokes') !== false,
    'that signing out of starlink.com kills the imported session');
is_(strpos($page, 'one per account') !== false || strpos($page, 'Why one per account') !== false,
    'and that usage needs a session per account');

echo "\nSwitching and forgetting go through the store, not the file\n";
is_(strpos($page, '$ssStore->useAccount(') !== false,  'switching uses the store');
is_(strpos($page, '$ssStore->forgetAccount(') !== false, 'forgetting uses the store');
is_(strpos($page, 'confirm(') !== false, 'and forgetting asks first');

echo "\nThe listing restores the operator's selection\n";
// Rendering the roster walks every account by selecting it. Leaving the last
// one selected would silently move which account everything else acts on.
is_(preg_match('/endforeach;\s*\$ssStore->useAccount\(\$active\);/', $page) === 1,
    'after listing, the active account is put back');

echo "\nAnd it is reachable by a person, not only by URL\n";
// The sidebar is hand-written links, not generated from the tab array in
// public.php. Registering a route and a permission makes a tab addressable;
// only this file makes it findable, and a tab nobody can see is a tab nobody
// uses.
$nav = (string)file_get_contents(dirname(__DIR__) . '/includes/navigation.php');
is_(strpos($nav, 'tab=starlink_session') !== false, 'the sidebar links to it');
is_(strpos($nav, 'Starlink Sessions') !== false,    'with a label a person can read');
is_(strpos($nav, "\$tab==='starlink_session'?'active'") !== false,
    'and highlights when you are on it');
// It sits in the admin block — the same guard the page and the map assert.
$adminBlock = substr($nav, (int)strpos($nav, 'if($isAdmin)'));
is_(strpos($adminBlock, 'tab=starlink_session') !== false,
    'inside the admin-only section of the sidebar');

echo "\nImporting collects, because that is the only moment a session is warm\n";
// A Starlink token lasts minutes and USING it does not extend it: imported
// 07:58:08, last accepted 08:05:03, expired — with the keep-alive dispatching
// on schedule throughout. So an hourly collector finds a dead session almost
// every time, and the paste is the only reliable trigger.
is_(strpos($page, 'function ssCollectNow') !== false, 'the page can collect');
is_(preg_match('/verify\(\).*\n(.*\n)*?.*ssCollectNow/', $page) === 1,
    'and does it straight after the import is VERIFIED, not before');
is_(strpos($page, "=== 'collect'") !== false && strpos($page, 'value="collect"') !== false,
    'with a button for the window just after a paste');
is_(strpos($page, 'using it does not extend it') !== false,
    'and the page tells the operator why, so the cadence is not a mystery');

// Collecting must go through the same guards as everything else here.
$collectBlock = substr($page, (int)strpos($page, "=== 'collect'"), 200);
is_(strpos($collectBlock, 'csrfCheck()') !== false, 'the collect action checks CSRF too');
is_(strpos($page, "\$u->save(\$dataDir, \$res['rows'])") !== false,
    'it saves through StarlinkUsage, so the empty-file refusal still applies');
is_(strpos($page, 'no usage collected') !== false,
    'and reports a failed collection rather than showing success');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
