<?php
declare(strict_types=1);
namespace Dn\Jobs;

use Dn\Db\Database;
use Dn\Notify\CodeEnvelope;
use Dn\Notify\SignInMessage;
use Dn\Notify\SmsResult;
use Dn\Notify\SmsSender;

/**
 * The only thing that sends a sign-in code (docs/127 §C, migration 032).
 *
 * Every tick: close what can no longer be sent (expire), then — only when a
 * real sender is configured — claim a few messages under a lease, open each
 * envelope IN MEMORY, send, and settle. The plaintext code exists nowhere but
 * in this loop's variables for the length of one send.
 *
 * Crash safety is the intent worker's: a claim carries a 60-second lease, and
 * a message whose worker died is claimed again while it has attempts left. The
 * cost is that a message may be sent twice — the same code, so harmless.
 *
 * NOTHING SECRET IS WRITTEN. Not to the database (the reasons stored are the
 * sender's fixed vocabulary), and not to the log: runOnce() returns counts.
 */
final class SmsWorker
{
    public function __construct(
        private Database $db,
        private SmsSender $sender,
        private ?CodeEnvelope $envelope = null,
    ) {}

    /** @return array{expired:int,claimed:int,sent:int,retrying:int,failed:int} */
    public function runOnce(int $limit = 10): array
    {
        $out = ['expired' => (int) ($this->db->one('SELECT mt_auth_sms_expire() AS n')['n'] ?? 0),
                'claimed' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0];
        if (!$this->sender->isConfigured()) {
            // Nothing can be sent, so nothing is claimed: no attempt is spent
            // on any message, and each one ends as 'expired'.
            return $out;
        }
        $envelope = $this->envelope ??= new CodeEnvelope();

        $rows = $this->db->query('SELECT id, phone, sealed, attempt FROM mt_auth_sms_claim(?)', [$limit]);
        $out['claimed'] = count($rows);
        foreach ($rows as $row) {
            $res   = $this->deliver($envelope, $row);
            $state = $this->db->one('SELECT mt_auth_sms_settle(?,?,?,?,?) AS s',
                [$row['id'], (int) $row['attempt'], $res->outcome, $res->providerRef, $res->error])['s'] ?? null;
            match ($state) {
                'sent'              => $out['sent']++,
                'queued'            => $out['retrying']++,
                'failed', 'expired' => $out['failed']++,
                default             => null,    // a stale claim: another worker holds it now
            };
        }
        return $out;
    }

    private function deliver(CodeEnvelope $envelope, array $row): SmsResult
    {
        try {
            $code = $envelope->open((string) $row['sealed'], (string) $row['phone']);
        } catch (\Throwable) {
            // It will never open: DNB_SECRET_KEY changed, or the row was altered.
            return SmsResult::failed('the sealed code did not open');
        }
        try {
            return $this->sender->send((string) $row['phone'], SignInMessage::text($code));
        } catch (\Throwable) {
            // A sender is not supposed to throw; if one does, its message is
            // not trusted to be free of secrets, so it is not kept.
            return SmsResult::retry('the sender failed unexpectedly');
        }
    }
}
