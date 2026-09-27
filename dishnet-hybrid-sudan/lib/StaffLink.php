<?php
/**
 * StaffLink — checks a requested staff ↔ uCRM user link before it is saved, on Uganda (docs/44 J2, D9, M7).
 *
 * The rules, all checked against what uCRM answers now:
 *   - the account's role takes jobs (support, support leader, support engineer, admin);
 *   - uCRM has the user (users/admins/{id} answers 200) and it is active;
 *   - the uCRM user's e-mail is the account's own e-mail, ignoring case and spaces (D9);
 *   - no other ACTIVE account holds a verified link to the same user.
 * A refusal changes nothing. So does a uCRM that cannot be reached: a link is never set unverified.
 * Each refusal reason is a cause, written to follow "The link was not changed: ", which every caller puts before it.
 * 0 is "— not linked —" and always allowed: clearing a link is the administrator's explicit choice (M5).
 */
final class StaffLink
{
    /**
     * @param array $row     the account as it will be saved (stored row merged with the form's changes)
     * @param int   $wanted  the uCRM user id chosen; 0 clears the link
     * @param array $rows    every account (retailers.json), for "already linked"
     * @param int   $adminId the administrator saving it, recorded on the link
     * @return array{ok:bool, action:string, reason:string, link:?array, unreachable:bool}
     *         action: link | clear | refuse
     */
    public static function verify(CrmApiClient $crm, array $row, int $wanted, array $rows, int $adminId): array
    {
        $out = function (bool $ok, string $action, string $reason, ?array $link = null, bool $unreachable = false): array {
            return ['ok' => $ok, 'action' => $action, 'reason' => $reason, 'link' => $link, 'unreachable' => $unreachable];
        };
        if ($wanted <= 0) return $out(true, 'clear', 'not linked to uCRM');
        if (!StaffDirectory::takesJobs($row)) {
            return $out(false, 'refuse', 'this role does not take jobs, so it is not linked to a uCRM user');
        }
        $email = StaffDirectory::email($row);
        if ($email === '') return $out(false, 'refuse', 'this account has no e-mail to match with uCRM');
        if (!$crm->isConfigured()) return $out(false, 'refuse', 'uCRM is not configured', null, true);

        $f = UcrmUsers::find($crm, $wanted);
        if ($f['status'] === 'unreachable') return $out(false, 'refuse', 'uCRM could not be reached', null, true);
        if ($f['status'] === 'absent')      return $out(false, 'refuse', "uCRM has no user #{$wanted}");
        $u = (array)$f['user'];
        if (!UcrmUsers::isActive($u)) return $out(false, 'refuse', "uCRM user #{$wanted} is not active");
        if (UcrmUsers::email($u) !== $email) {
            return $out(false, 'refuse', "uCRM user #{$wanted} has a different e-mail from this account — make them the same, then link again");
        }
        $other = StaffDirectory::linkedElsewhere($rows, $wanted, (int)($row['id'] ?? 0));
        if ($other !== null) return $out(false, 'refuse', "uCRM user #{$wanted} is already linked to another active account");

        return $out(true, 'link', "linked to uCRM user #{$wanted}", StaffDirectory::makeLink($wanted, $email, $adminId));
    }
}
