<?php
/**
 * UcrmUsers — Uganda's real uCRM staff users, read live (docs/44 J2, release A).
 *
 * Replaces, on Uganda only, the hard-coded South Sudan lists as the source of a staff account's uCRM user. Nothing is
 * cached across requests: a link is checked against what uCRM answers at the moment it is saved.
 *
 * Measured on 27 Sep 2026 (docs/44 §13.1): GET users/admins lists them with id, email, firstName, lastName, username
 * and isActive, and users/admins/{id} answers 200 for a real id and 404 for anything else. No record carries a phone.
 */
final class UcrmUsers
{
    /** @return array<int,array>|null  id => user, or null when uCRM did not answer */
    public static function all(CrmApiClient $crm): ?array
    {
        $list = $crm->get('users/admins');
        if (!is_array($list)) return null;
        $out = [];
        foreach ($list as $u) {
            if (is_array($u) && (int)($u['id'] ?? 0) > 0) $out[(int)$u['id']] = $u;
        }
        ksort($out);
        return $out;
    }

    /**
     * One user, checked by uCRM itself.
     * @return array{status:string, user:?array}  status: found | absent (uCRM answered 404) | unreachable (anything else)
     */
    public static function find(CrmApiClient $crm, int $id): array
    {
        if ($id <= 0) return ['status' => 'absent', 'user' => null];
        $u = $crm->get("users/admins/{$id}");
        if (is_array($u) && (int)($u['id'] ?? 0) === $id) return ['status' => 'found', 'user' => $u];
        $code = (int)($crm->getLastError()['http_code'] ?? 0);
        return ['status' => $code === 404 ? 'absent' : 'unreachable', 'user' => null];
    }

    public static function email(array $u): string
    {
        return strtolower(trim((string)($u['email'] ?? '')));
    }

    public static function isActive(array $u): bool
    {
        return !empty($u['isActive']);
    }

    /** "#1099 First Last", or "#1099 username" when uCRM holds no name (as on 27 Sep). Never the e-mail. */
    public static function label(array $u): string
    {
        $name = trim((string)($u['firstName'] ?? '') . ' ' . (string)($u['lastName'] ?? ''));
        if ($name === '') $name = trim((string)($u['username'] ?? ''));
        return '#' . (int)($u['id'] ?? 0) . ($name !== '' ? ' ' . $name : '');
    }

    /** The one user with this e-mail, trimmed and lower-cased; null for none or for two. */
    public static function byEmail(array $users, string $email): ?int
    {
        $want = strtolower(trim($email));
        if ($want === '') return null;
        $hit = [];
        foreach ($users as $u) {
            if (is_array($u) && self::email($u) === $want) $hit[] = (int)($u['id'] ?? 0);
        }
        return count($hit) === 1 ? $hit[0] : null;
    }
}
