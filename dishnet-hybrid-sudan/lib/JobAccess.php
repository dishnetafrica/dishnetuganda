<?php
/**
 * JobAccess — who may create and act on a job, checked on the server, on Uganda (docs/44 J6, D7, M7; release A).
 *
 *   create a job                 an administrator, a support leader, support, or support engineer (D7)
 *   each engineer chosen         active, a job-taking role, with a VERIFIED uCRM link (M7) to a live, active uCRM user
 *                                whose e-mail is the account's own (D9)
 *   act on a job                 its assignee (the caller's verified link equals the job's assignedUserId, both
 *                                non-zero), a support leader or an administrator
 *
 * The caller is re-read from the store for every job action, so a deactivated account is stopped at its next click,
 * not its next page. The job is read from uCRM for the check; a job with no assignee belongs to no one, so only a
 * leader or an administrator may act on it. An answer that carries no assignee field at all (docs/44 V4, unmeasured
 * on Uganda until the internal test) is treated the same way, with its own message.
 */
final class JobAccess
{
    /** Roles that may create a job, besides any administrator. support_engineer is D7. */
    public const CREATE_ROLES = ['support_leader', 'support', 'support_engineer'];

    public const NOT_YOURS   = 'This job is not assigned to you.';
    public const NO_ASSIGNEE = 'This job\'s assignee could not be read from uCRM, so only a support leader or an admin may act on it.';
    public const INACTIVE    = 'Your account is not active.';

    /** The caller as stored now — the row by id, and only while it is active. */
    public static function caller(array $me2, $store): ?array
    {
        $id = (int)($me2['id'] ?? 0);
        if ($id <= 0) return null;
        $row = $store->findOne('retailers.json', 'id', $id);
        return (is_array($row) && StaffDirectory::isActive($row)) ? $row : null;
    }

    public static function isLeader(array $row): bool
    {
        return StaffDirectory::role($row) === 'support_leader';
    }

    public static function canCreate(array $row): bool
    {
        return StaffDirectory::isAdmin($row) || in_array(StaffDirectory::role($row), self::CREATE_ROLES, true);
    }

    /** The assignee uCRM answered: > 0 an id, 0 nobody, null when the answer carries no assignee field. */
    public static function assigneeOf(array $job): ?int
    {
        if (!array_key_exists('assignedUserId', $job)) return null;
        return max(0, (int)$job['assignedUserId']);
    }

    public static function canActOn(array $row, ?int $assignee): bool
    {
        if (StaffDirectory::isAdmin($row) || self::isLeader($row)) return true;
        if ($assignee === null || $assignee <= 0) return false;
        $mine = StaffDirectory::linkedUcrmUser($row);
        return $mine > 0 && $mine === $assignee;
    }

    /** Why canActOn() said no, in words a technician can act on. */
    public static function refusal(?int $assignee): string
    {
        return $assignee === null ? self::NO_ASSIGNEE : self::NOT_YOURS;
    }

    /**
     * An account a job may be assigned to. $ucrmUsers is UcrmUsers::all(): null when uCRM did not answer, and then
     * nobody is assignable — a job is never assigned to an unverified user.
     */
    public static function assignable(array $row, ?array $ucrmUsers): bool
    {
        if (!StaffDirectory::isActive($row) || !StaffDirectory::takesJobs($row)) return false;
        $id = StaffDirectory::linkedUcrmUser($row);
        if ($id <= 0 || $ucrmUsers === null || !isset($ucrmUsers[$id]) || !is_array($ucrmUsers[$id])) return false;
        $u = $ucrmUsers[$id];
        return UcrmUsers::isActive($u) && UcrmUsers::email($u) === StaffDirectory::email($row);
    }

    /** The assignable accounts, by their verified uCRM user id. */
    public static function assignableByUcrmUser(array $rows, ?array $ucrmUsers): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && self::assignable($r, $ucrmUsers)) $out[StaffDirectory::linkedUcrmUser($r)] = $r;
        }
        return $out;
    }
}
