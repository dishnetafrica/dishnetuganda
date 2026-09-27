<?php
/**
 * StaffDirectory — who a staff account is, for the Uganda staff and job rules of 5.18.50 (docs/44, release A).
 *
 * Pure functions over the rows of retailers.json; nothing here writes. Every caller guards itself with
 * StaffJobsGate first, so South Sudan never reaches this class.
 *
 *   - linkedUcrmUser(): M7. A uCRM user id counts only when it was saved through the verified picker, which records
 *     the id and the account's e-mail beside it (ucrm_link). An id stored any other way — typed before 5.18.50, forced
 *     by the South Sudan lists, written by an older release after a rollback — matches nobody, whether or not it has
 *     been cleared. Changing the account's e-mail breaks the link too: it is re-verified or it does not count.
 *   - phoneOf(): J3's rule on use — the tenant's international form, or null. Reads, never rewrites, the stored number.
 *   - activeStaffByPhone(): J8. The whole international number, exactly — never the last nine digits, which would
 *     confuse a +211 number with a +256 one — against active staff accounts only. Dealer accounts (sales/retailer,
 *     field agent, collection agent) are not staff (docs/44 §15.9).
 */
final class StaffDirectory
{
    /** The roles that take jobs (J2, J6). An administrator takes jobs too. */
    public const JOB_ROLES = ['support', 'support_leader', 'support_engineer', 'admin'];

    /** DishNet staff for J8. Dealer roles — sales, field_agent, collection — are not in it. */
    public const STAFF_ROLES = ['admin', 'accountant', 'field_accountant', 'support', 'support_leader', 'support_engineer'];

    /** Where the verified picker records a link beside ucrm_user_id. */
    public const LINK_KEY = 'ucrm_link';

    public static function role(array $row): string
    {
        return strtolower(trim((string)($row['role'] ?? '')));
    }

    public static function isAdmin(array $row): bool
    {
        return !empty($row['is_admin']);
    }

    public static function isActive(array $row): bool
    {
        return !empty($row['is_active']);
    }

    public static function takesJobs(array $row): bool
    {
        return self::isAdmin($row) || in_array(self::role($row), self::JOB_ROLES, true);
    }

    public static function isStaff(array $row): bool
    {
        return self::isAdmin($row) || in_array(self::role($row), self::STAFF_ROLES, true);
    }

    public static function email(array $row): string
    {
        return strtolower(trim((string)($row['email'] ?? '')));
    }

    /** M7: the verified uCRM user id, or 0 when the stored id was not saved through the picker for this e-mail. */
    public static function linkedUcrmUser(array $row): int
    {
        $link = $row[self::LINK_KEY] ?? null;
        if (!is_array($link)) return 0;
        $id = (int)($row['ucrm_user_id'] ?? 0);
        if ($id <= 0 || (int)($link['user_id'] ?? 0) !== $id) return 0;
        $email = self::email($row);
        if ($email === '' || (string)($link['email'] ?? '') !== $email) return 0;
        return $id;
    }

    /** The record the picker writes beside ucrm_user_id. $by is the saving administrator's account id. */
    public static function makeLink(int $ucrmUserId, string $email, int $by): array
    {
        return [
            'user_id'     => $ucrmUserId,
            'email'       => strtolower(trim($email)),
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'verified_by' => $by,
        ];
    }

    /** Active rows whose VERIFIED link is this uCRM user. More than one is ambiguous; callers refuse to guess. */
    public static function byUcrmUser(array $rows, int $ucrmUserId): array
    {
        if ($ucrmUserId <= 0) return [];
        return array_values(array_filter($rows, function ($r) use ($ucrmUserId) {
            return is_array($r) && self::isActive($r) && self::linkedUcrmUser($r) === $ucrmUserId;
        }));
    }

    /** Another active row already holding this verified link, if any. */
    public static function linkedElsewhere(array $rows, int $ucrmUserId, int $exceptRowId): ?array
    {
        foreach (self::byUcrmUser($rows, $ucrmUserId) as $r) {
            if ((int)($r['id'] ?? 0) !== $exceptRowId) return $r;
        }
        return null;
    }

    /** J3 on use: the tenant's international form of the stored number, or null. Never writes. */
    public static function phoneOf(array $row, TenantProfile $tenant): ?string
    {
        if (!class_exists('PhoneNumber')) require_once __DIR__ . '/PhoneNumber.php';
        $raw = trim((string)($row['phone'] ?? ''));
        return $raw === '' ? null : PhoneNumber::international($raw, $tenant);
    }

    /** J8: the active STAFF account whose whole international number is this one, or null. */
    public static function activeStaffByPhone(array $rows, string $phone, TenantProfile $tenant): ?array
    {
        $want = (string)preg_replace('/\D+/', '', $phone);
        if (strlen($want) < 10) return null;
        foreach ($rows as $r) {
            if (!is_array($r) || !self::isActive($r) || !self::isStaff($r)) continue;
            $p = self::phoneOf($r, $tenant);
            if ($p !== null && (string)preg_replace('/\D+/', '', $p) === $want) return $r;
        }
        return null;
    }
}
