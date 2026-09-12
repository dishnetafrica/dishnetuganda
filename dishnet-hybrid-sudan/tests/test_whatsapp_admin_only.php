<?php
declare(strict_types=1);
/**
 * test_whatsapp_admin_only.php — WhatsApp is an administrator capability, and
 * the BACKEND is what enforces it.
 *
 * Before this, 22 of the 33 wa_* API actions had no permission check at all —
 * wa_send_reply, wa_send_image, wa_send_media, wa_send_document,
 * wa_send_quote_pdf, wa_trigger_sync among them. Every one was reachable by
 * name from any signed-in session, so hiding the menu hid a link and stopped
 * nobody.
 *
 * The test that matters here is not "an admin can" — it is "a sales agent who
 * knows the URL cannot".
 */
require_once dirname(__DIR__) . '/lib/WhatsAppAccess.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$W = 'WhatsAppAccess';

echo "An administrator may do everything\n";
foreach (['wa_send_reply', 'wa_update_keys', 'wa_toggle_bot', 'wa_trigger_sync',
          'wa_conversations', 'wa_anything_added_next_year'] as $a) {
    t("admin: {$a}", WhatsAppAccess::allows(true, $a, [], 'admin'), true);
}

echo "\nNobody else may, by default — including every send path\n";
foreach (['sales_agent', 'support', 'support_leader', 'accountant', 'field_accountant',
          'retailer', ''] as $role) {
    foreach (['wa_send_reply', 'wa_send_image', 'wa_send_media', 'wa_send_document',
              'wa_send_quote_pdf', 'wa_reply'] as $a) {
        if (WhatsAppAccess::allows(false, $a, [], $role)) {
            echo "  FAIL {$role} can {$a}\n"; $fail++;
        } else { $pass++; }
    }
}
echo "  ok   7 roles × 6 send actions all refused\n";

echo "\nConnection, configuration and sessions are never grantable\n";
// These are the ones the requirement names explicitly. No configuration key
// may open them to anybody, so they are tested WITH the inbox grant switched
// on for that very role.
$granted = ['wa_inbox_roles' => 'support,support_leader'];
foreach (['wa_update_keys', 'wa_toggle_bot', 'wa_test_webhook', 'wa_run_sync',
          'wa_sync_reset', 'wa_sync_test', 'wa_trigger_sync', 'wa_sync_status',
          'wa_debug', 'wa_media_debug', 'wa_auto_link', 'wa_crm_raw_debug',
          'wa_sync_log'] as $a) {
    t("support, even when granted the inbox: {$a}",
        WhatsAppAccess::allows(false, $a, $granted, 'support'), false);
}

echo "\nThe inbox seam — the one thing that IS grantable\n";
t('support cannot read the inbox by default',
    WhatsAppAccess::allows(false, 'wa_conversations', [], 'support'), false);
t('and can once granted',
    WhatsAppAccess::allows(false, 'wa_conversations', $granted, 'support'), true);
t('and may then reply',
    WhatsAppAccess::allows(false, 'wa_send_reply', $granted, 'support'), true);
t('but a role not on the list still cannot',
    WhatsAppAccess::allows(false, 'wa_conversations', $granted, 'sales_agent'), false);

echo "\nThe grant cannot be used to switch the rule off\n";
// 'all' or '*' in a config value must never mean "everybody".
foreach (['all', '*', 'admin', 'ALL', ' * '] as $sneaky) {
    $cfg = ['wa_inbox_roles' => $sneaky];
    t("'{$sneaky}' grants nobody", WhatsAppAccess::inboxRoles($cfg), []);
}
t('an empty setting grants nobody', WhatsAppAccess::inboxRoles([]), []);
t('a real list is read',            WhatsAppAccess::inboxRoles(['wa_inbox_roles' => 'support, tech']),
    ['support', 'tech']);

echo "\nTabs\n";
foreach (['whatsapp', 'wa_ai_setup', 'engage_failed_queue'] as $tab) {
    t("{$tab} is admin-only even with the inbox granted",
        WhatsAppAccess::allowsTab(false, $tab, $granted, 'support'), false);
}
t('wa_inbox follows the grant',  WhatsAppAccess::allowsTab(false, 'wa_inbox', $granted, 'support'), true);
t('and is closed without it',    WhatsAppAccess::allowsTab(false, 'wa_inbox', [], 'support'), false);
t('admin opens every tab',       WhatsAppAccess::allowsTab(true, 'whatsapp', [], 'admin'), true);

echo "\nThe guard is wired where it has to be\n";
$api = file_get_contents(dirname(__DIR__) . '/includes/api/api_whatsapp.php');
is_(strpos($api, 'WhatsAppAccess::allows(') !== false, 'the API consults the rule');
// It has to run BEFORE any action, not inside one of them.
$guardPos  = strpos($api, 'WhatsAppAccess::allows(');
$firstAct  = strpos($api, "\$act === 'wa_");
is_($guardPos !== false && $firstAct !== false && $guardPos < $firstAct,
    'and does so before the first action is reached');
is_(strpos($api, "strpos((string)(\$act ?? ''), 'wa_') === 0") !== false,
    'covering every wa_ action, including ones not yet written');

foreach (['whatsapp', 'wa_inbox', 'wa_ai_setup', 'failed_queue'] as $f) {
    $t = file_get_contents(dirname(__DIR__) . "/tabs/engage/{$f}.php");
    is_(strpos($t, 'WhatsAppAccess::allowsTab') !== false, "tabs/engage/{$f}.php guards itself");
}

$pub = file_get_contents(dirname(__DIR__) . '/public.php');
foreach (['whatsapp', 'wa_inbox', 'wa_ai_setup', 'engage_failed_queue'] as $tab) {
    is_((bool)preg_match("/'" . preg_quote($tab, '/') . "'\s*=>\s*'\*admin'/", $pub),
        "{$tab} is *admin in the permission map");
}
// The specific hole: a tab with no entry is allowed for everyone.
is_((bool)preg_match("/'wa_ai_setup'\s*=>\s*'\*admin'/", $pub),
    'wa_ai_setup has an entry at all — it had none, and no entry means everyone');

echo "\nEvery action in the file is accounted for\n";
// A wa_ action that is neither admin-only nor on the inbox list would be
// refused for non-admins, which is correct — but the list should still be
// deliberate rather than accidental.
preg_match_all("/\\\$act === '(wa_[a-z0-9_]+)'/", $api, $m);
$actions = array_values(array_unique($m[1]));
$unknown = array_diff($actions, WhatsAppAccess::INBOX_ACTIONS);
printf("  %d actions: %d shared-inbox, %d administrator-only\n",
    count($actions), count($actions) - count($unknown), count($unknown));
is_(count($actions) > 25, 'the scan found the real action list', (string)count($actions));
foreach ($actions as $a) {
    if (WhatsAppAccess::allows(false, $a, [], 'sales_agent')) {
        echo "  FAIL sales_agent can reach {$a} with no grant\n"; $fail++;
    }
}
echo "  ok   no action is reachable by a sales agent with no grant\n"; $pass++;

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
