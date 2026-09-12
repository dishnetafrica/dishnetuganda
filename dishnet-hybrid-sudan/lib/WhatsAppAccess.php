<?php
declare(strict_types=1);

/**
 * WhatsAppAccess — WhatsApp is an administrator capability.
 *
 * One rule, in one place, asked by the tabs, the navigation and every API
 * action. Scattering the same check across 33 endpoints is how 22 of them came
 * to be missing it: each was written on its own day, and the ones that
 * happened to be written on a day somebody was thinking about permissions got
 * a check.
 *
 * ── WHY THE BACKEND, NOT THE MENU ───────────────────────────────────────
 *
 * Hiding a tab hides a link. It does not stop anybody. Every action here is
 * reachable by name — `?page=api&action=wa_send_reply` — from any signed-in
 * session, and before this existed a sales agent could send a WhatsApp
 * message to any customer by typing a URL. The menu is a convenience; this is
 * the control.
 *
 * ── THE ONE DELIBERATE SEAM ─────────────────────────────────────────────
 *
 * Reading and replying in the shared team inbox is arguably a different thing
 * from holding a WhatsApp connection of your own. The strict reading is the
 * default — administrators only — because a security rule should fail closed.
 * But a support desk that answers customers on WhatsApp will notice the day
 * this ships, so the inbox is one named config key rather than a code change:
 *
 *     wa_inbox_roles = "support,support_leader"
 *
 * Connection, configuration, numbers, sessions and settings are NEVER
 * grantable this way. Only reading and replying in the shared inbox is.
 */
final class WhatsAppAccess
{
    /**
     * Actions that are reading or replying in the SHARED team inbox.
     *
     * These are the only ones wa_inbox_roles can ever open up. Everything not
     * on this list — keys, webhooks, sync control, bot toggles, diagnostics —
     * is administrator-only whatever the configuration says.
     */
    public const INBOX_ACTIONS = [
        'wa_conversations', 'wa_thread_messages', 'wa_mark_read',
        'wa_crm_client_info', 'wa_customer_360', 'wa_close_thread',
        'wa_close_conversation', 'wa_categorise', 'wa_tickets',
        'wa_update_ticket', 'wa_quick_replies', 'wa_quick_action',
        'wa_send_reply', 'wa_reply', 'wa_send_document', 'wa_send_image',
        'wa_send_media', 'wa_upload_media', 'wa_send_quote_pdf',
        'wa_link_client',
    ];

    /** Tabs this rule governs. */
    public const TABS = ['whatsapp', 'wa_inbox', 'wa_ai_setup', 'engage_failed_queue'];

    /**
     * May this person use this WhatsApp action?
     *
     * @param bool   $isAdmin
     * @param string $action  the API action, or a tab id
     * @param array  $config  kyc_config.json
     * @param string $role    the signed-in user's role
     */
    public static function allows(bool $isAdmin, string $action, array $config, string $role = ''): bool
    {
        if ($isAdmin) return true;

        // Anything that is not shared-inbox work is administrator-only, full
        // stop. No configuration key reaches these.
        if (!in_array($action, self::INBOX_ACTIONS, true)) return false;

        return in_array($role, self::inboxRoles($config), true);
    }

    /** Roles granted the shared inbox, if any. Default: none. */
    public static function inboxRoles(array $config): array
    {
        $raw = trim((string)($config['wa_inbox_roles'] ?? ''));
        if ($raw === '') return [];          // administrators only — the default
        $out = [];
        foreach (explode(',', $raw) as $r) {
            $r = trim(strtolower($r));
            // 'admin' here would be meaningless and 'all' must never be a way
            // to switch the rule off wholesale.
            if ($r !== '' && $r !== 'admin' && $r !== 'all' && $r !== '*') $out[] = $r;
        }
        return $out;
    }

    /** May this person open this WhatsApp tab? */
    public static function allowsTab(bool $isAdmin, string $tab, array $config, string $role = ''): bool
    {
        if ($isAdmin) return true;
        // Only the inbox is ever grantable. Configuration, the AI setup screen
        // and the failed-message queue are administrator-only.
        if ($tab !== 'wa_inbox') return false;
        return in_array($role, self::inboxRoles($config), true);
    }

    /** The refusal a blocked caller receives. Says what the rule is, not what they lack. */
    public static function denial(): string
    {
        return 'WhatsApp is restricted to administrators.';
    }
}
