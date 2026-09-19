<?php
declare(strict_types=1);
namespace Dn\Http;

use Dn\Db\Database;

/**
 * Replay protection for state-changing routes.
 *
 * begin() returns:
 *   ['replay' => false]                        first time — caller proceeds
 *   ['replay' => true, 'status'=>…, 'body'=>…] completed — caller returns it
 *   ['conflict' => …]                          same key, different body, or
 *                                              a request still in flight
 *
 * The digest matters: a client reusing a key with a DIFFERENT body is a bug,
 * and answering it with the previous response would hide that bug behind a
 * plausible-looking success.
 */
final class Idempotency
{
    public function __construct(private Database $db) {}

    public function begin(
        string $customerId, ?string $principalId, string $key,
        string $endpoint, array $requestBody
    ): array {
        $digest = hash('sha256', json_encode($requestBody, JSON_THROW_ON_ERROR));

        $existing = $this->db->one(
            'SELECT request_digest, state, response_status, response_body
               FROM mt_idempotency WHERE customer_id = ? AND key = ?',
            [$customerId, $key]
        );

        if ($existing !== null) {
            if (!hash_equals($existing['request_digest'], $digest)) {
                return ['conflict' => 'key reused with a different request body'];
            }
            if ($existing['state'] === 'in_flight') {
                return ['conflict' => 'a request with this key is still in flight'];
            }
            return [
                'replay' => true,
                'status' => (int) $existing['response_status'],
                'body'   => json_decode((string) $existing['response_body'], true),
            ];
        }

        $this->db->exec(
            'INSERT INTO mt_idempotency
               (customer_id, principal_id, key, endpoint, request_digest)
             VALUES (?,?,?,?,?)',
            [$customerId, $principalId, $key, $endpoint, $digest]
        );
        return ['replay' => false];
    }

    public function complete(string $customerId, string $key, int $status, array $body): void
    {
        $this->db->exec(
            "UPDATE mt_idempotency
                SET state = 'completed', response_status = ?, response_body = ?::jsonb,
                    completed_at = now()
              WHERE customer_id = ? AND key = ?",
            [$status, json_encode($body, JSON_THROW_ON_ERROR), $customerId, $key]
        );
    }
}
