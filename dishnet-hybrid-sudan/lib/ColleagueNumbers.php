<?php
/**
 * ColleagueNumbers — is this WhatsApp number a colleague's? For the follow-up engine on Uganda (docs/44 J8, M1;
 * release A).
 *
 * A conversation with an active DishNet staff account is never followed up: not opened by the scan, closed as "a
 * colleague's number" if one was opened before the deploy, and never sent. The number is compared whole, exactly as
 * J8 does at the webhook (StaffDirectory::activeStaffByPhone) — never the last nine digits, so a +211 number is not
 * taken for a +256 one. A conversation already filed 'staff' counts too.
 *
 * forInstall() answers null on every install that is not Uganda, and whenever the check cannot be set up; the callers
 * then behave exactly as in 5.18.49. The staff rows are read once, when the check is set up.
 */
final class ColleagueNumbers
{
    /** @var array */
    private $rows;
    /** @var TenantProfile */
    private $tenant;

    private function __construct(array $rows, TenantProfile $tenant)
    {
        $this->rows   = $rows;
        $this->tenant = $tenant;
    }

    /** @param object $store the plugin's store (load('retailers.json')) */
    public static function forInstall(array $config, ?string $dataDir, $store): ?self
    {
        try {
            require_once __DIR__ . '/StaffJobsGate.php';
            if (!StaffJobsGate::applies($config, $dataDir)) return null;
            require_once __DIR__ . '/StaffDirectory.php';
            require_once __DIR__ . '/TenantProfile.php';
            $rows = $store->load('retailers.json');
            return new self(is_array($rows) ? $rows : [], TenantProfile::current($config, $dataDir));
        } catch (\Throwable $e) {
            error_log('[ColleagueNumbers] check unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /** The whole number belongs to an active staff account. */
    public function isColleague(string $phone): bool
    {
        return StaffDirectory::activeStaffByPhone($this->rows, $phone, $this->tenant) !== null;
    }

    /** Filed 'staff', or the conversation's number is a colleague's. */
    public function isColleagueConversation(array $conv): bool
    {
        if (strtolower(trim((string)($conv['category'] ?? ''))) === 'staff') return true;
        return $this->isColleague((string)($conv['phone'] ?? ''));
    }
}
