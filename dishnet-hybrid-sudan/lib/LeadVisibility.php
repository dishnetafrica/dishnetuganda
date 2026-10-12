<?php
declare(strict_types=1);

/**
 * LeadVisibility — "own leads only" for salespeople (5.18.89, docs/65 §AA, decision D7).
 *
 * The operator's decision (07 Oct): a salesperson sees only their own leads; admins and managers see everything. A
 * manager is whoever holds the existing All Leads permission ("Admins + 'All Leads' grant"). Because this changes what
 * every salesperson sees, it has its own switch, sales_own_leads_only, OFF by default, and Uganda only. OFF — and on
 * every other install — nothing here filters anything: every lead is visible to every salesperson, as it always was.
 *
 * ON, a viewer who is not a manager sees a lead only when it is theirs:
 *   - assigned to them (assigned_to), or
 *   - created by them (retailer_id), or
 *   - put on their call list for today by the daily rota (daily_assign_to with today's date) — the rota is how the team
 *     shares out calls, and a caller must be able to open what it gave them. A lead from a salesperson's own number is
 *     never put on anyone else's rota (OwnedLead).
 *
 * One rule, read wherever a salesperson reaches a lead: the Sales → Leads page (every list, every count, the lead
 * opened by id), the quote page's lead picker, the More menu's count, the four lead handlers that act on one lead by
 * its id (save_lead, send_lead_quote, update_lead_status, convert_lead) and the staff API's call log (log_call). A lead
 * the viewer may not see is answered as not found, never as forbidden. The All Leads page and the admin's lead tools
 * are managers' and admins' already, and are not filtered.
 *
 * PHP 7.4 compatible.
 */
final class LeadVisibility
{
    /** The switch. Absent means off. */
    const FLAG = 'sales_own_leads_only';

    /** ON only where the switch is set AND this install is Uganda. Anything unclear is off. */
    public static function applies(array $config, ?string $dataDir): bool
    {
        if (!filter_var($config[self::FLAG] ?? false, FILTER_VALIDATE_BOOLEAN)) return false;
        try {
            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            return \StaffJobsGate::applies($config, $dataDir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A manager: an admin, or someone granted All Leads — by their role (RBAC) or by a per-person grant — decided in the
     * same order as the page's own $can('all_leads') (public.php): the admin flag; then the role's RBAC grant; then, when
     * the person has their own module list, that list alone; otherwise the module's role default, which for All Leads is
     * the admin role only.
     *
     * @param mixed $rbac RbacService|null
     */
    public static function seesAll(array $viewer, $rbac = null): bool
    {
        if (!empty($viewer['is_admin'])) return true;
        $role = (string)($viewer['role'] ?? 'sales');
        if ($rbac !== null && method_exists($rbac, 'canLegacy')) {
            try {
                $roleIdOrSlug = $viewer['role_id'] ?? $viewer['role'] ?? $role;
                if ($rbac->canLegacy($roleIdOrSlug, 'all_leads')) return true;
            } catch (\Throwable $e) { /* RBAC tables may not exist yet: fall through, as the page does */ }
        }
        if (isset($viewer['modules']) && is_array($viewer['modules'])) return in_array('all_leads', $viewer['modules'], true);
        return $role === 'admin';
    }

    /** Is this lead the viewer's own (assigned, created, or on their call list today)? */
    public static function mine(array $lead, int $viewerId, string $today): bool
    {
        if ($viewerId <= 0) return false;
        if ((int)($lead['assigned_to'] ?? 0) === $viewerId) return true;
        if ((int)($lead['retailer_id'] ?? 0) === $viewerId) return true;
        return (int)($lead['daily_assign_to'] ?? 0) === $viewerId && (string)($lead['daily_assign_date'] ?? '') === $today;
    }

    /**
     * May this viewer see (and act on) this lead?
     *
     * @param mixed $rbac RbacService|null
     */
    public static function allows(array $lead, array $viewer, array $config, ?string $dataDir, $rbac = null, ?string $today = null): bool
    {
        if (!self::applies($config, $dataDir) || self::seesAll($viewer, $rbac)) return true;
        return self::mine($lead, (int)($viewer['id'] ?? 0), $today ?? date('Y-m-d'));
    }

    /**
     * The leads this viewer may see, keys preserved. OFF, or for a manager: the list unchanged.
     *
     * @param mixed $rbac RbacService|null
     */
    public static function filter(array $leads, array $viewer, array $config, ?string $dataDir, $rbac = null, ?string $today = null): array
    {
        if (!self::applies($config, $dataDir) || self::seesAll($viewer, $rbac)) return $leads;
        $id = (int)($viewer['id'] ?? 0);
        $day = $today ?? date('Y-m-d');
        return array_filter($leads, function ($l) use ($id, $day) { return is_array($l) && self::mine($l, $id, $day); });
    }
}
