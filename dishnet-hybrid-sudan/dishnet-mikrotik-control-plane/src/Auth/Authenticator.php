<?php
declare(strict_types=1);
namespace Dn\Auth;

use Dn\Db\Database;

/**
 * Turns a credential into a derived (principal, customer) pair — and nothing more.
 *
 * Everything here goes through the SECURITY DEFINER functions in migration 007,
 * because authentication happens before a tenant context exists and therefore
 * cannot run under the RLS policies it is trying to satisfy. See that file for
 * why the alternatives are worse.
 *
 * The customer id this produces is the ONLY customer id the system ever uses.
 * It is never read from a request (F4).
 */
final class Authenticator
{
    private const CODE_TTL  = 'PT10M';
    private const TOKEN_TTL = 'P30D';

    public function __construct(private Database $db) {}

    /**
     * Issue a sign-in code.
     *
     * Returns the plaintext code for the caller to deliver by SMS. In a real
     * deployment this goes to the SMS gateway and is never returned over HTTP;
     * the controller decides that, not this class.
     *
     * An unregistered phone gets a code row too, with no principal attached.
     * It can never verify, but it costs the same work and produces the same
     * response — so the endpoint cannot be used to discover who has an account.
     */
    public function issueCode(string $phone): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            $this->db->one('SELECT mt_auth_issue_code(?,?,?::interval) AS id',
                [$phone, $this->hash($code), self::CODE_TTL]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === 'DN429') { throw new RateLimited('rate limited', 0, $e); }
            throw $e;
        }
        return $code;
    }

    /** @return array{token:string,principal_id:string,customer_id:string}|null */
    public function verifyCode(string $phone, string $code): ?array
    {
        $row = $this->db->one(
            'SELECT principal_id, customer_id FROM mt_auth_verify_code(?,?)',
            [$phone, $this->hash($code)]
        );
        if ($row === null || $row['principal_id'] === null) { return null; }

        $token = bin2hex(random_bytes(32));
        $this->db->one('SELECT mt_auth_create_session(?,?,?,?::interval) AS id',
            [$row['principal_id'], $row['customer_id'], $this->hash($token), self::TOKEN_TTL]);

        // The plaintext token is returned once and never stored.
        return ['token' => $token,
                'principal_id' => $row['principal_id'],
                'customer_id'  => $row['customer_id']];
    }

    /**
     * Who this token is, RIGHT NOW. kind and capabilities come from the
     * principal row on every call (migration 027), never from the session and
     * never from the request: a demotion or a removed capability is enforced
     * on the next request, exactly as a disabled status already was.
     *
     * @return array{principal_id:string,customer_id:string,kind:string,capabilities:list<string>}|null
     */
    public function resolve(string $token): ?array
    {
        if ($token === '') { return null; }
        $row = $this->db->one(
            'SELECT principal_id, customer_id, kind, capabilities FROM mt_auth_resolve_token(?)',
            [$this->hash($token)]
        );
        if (!$row) { return null; }
        $row['capabilities'] = OpCapability::fromPg($row['capabilities'] ?? null);
        return $row;
    }

    public function revoke(string $token): bool
    {
        $r = $this->db->one('SELECT mt_auth_revoke_token(?) AS n', [$this->hash($token)]);
        return ((int) $r['n']) > 0;
    }

    /**
     * Tokens and codes are high-entropy already, so a plain keyed hash is the
     * right tool — not a password KDF, which would add latency to every
     * request for no gain against a 256-bit random token.
     */
    private function hash(string $secret): string
    {
        $key = getenv('DNB_TOKEN_PEPPER') ?: 'dev-pepper-not-for-production';
        return hash_hmac('sha256', $secret, $key);
    }
}
