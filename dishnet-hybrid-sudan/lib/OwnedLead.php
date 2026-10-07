<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffDirectory.php';

/**
 * OwnedLead — a lead that came in on a salesperson's own WhatsApp number, and is still theirs (5.18.89, docs/65 §AA, D5).
 *
 * The operator's decision (07 Oct): a lead from a salesperson's own number belongs to that salesperson and is never
 * moved by the automatic distribution. A lead is protected while all of these hold:
 *
 *   - its origin is a staff-owned channel (channel_owner_type = staff, written by the AI worker from the registry);
 *   - it is still assigned to that channel's owner — an admin who deliberately gives it to somebody else takes it out of
 *     the protection, and the lead's history says so;
 *   - that owner is still an active staff member — a lead must never be stranded with somebody who has left, so once the
 *     account is deactivated the ordinary rules apply to it again.
 *
 * Read by cron_leads.php (the 72 h reassignment and its 48 h warning skip it), by the admin's smart distribution (it
 * never enters the pool) and by the Leads page's daily rota (it is on nobody else's call list). The drip never sees it:
 * it is assigned from the moment it is created. An admin's deliberate moves — one lead reassigned by hand, or every
 * open lead of someone on leave handed to a colleague — still move it, and take it out of the protection.
 *
 * PHP 7.4 compatible.
 */
final class OwnedLead
{
    /**
     * @param array $lead      a leads.json row
     * @param array $retailers the retailers.json rows (to see whether the owner is still active)
     */
    public static function isProtected(array $lead, array $retailers): bool
    {
        if ((string)($lead['channel_owner_type'] ?? '') !== 'staff') return false;
        $owner = (int)($lead['channel_owner_id'] ?? 0);
        if ($owner <= 0 || (int)($lead['assigned_to'] ?? 0) !== $owner) return false;
        foreach ($retailers as $r) {
            if (is_array($r) && (int)($r['id'] ?? 0) === $owner) return StaffDirectory::isActive($r);
        }
        return false;
    }
}
