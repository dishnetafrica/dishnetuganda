<?php
declare(strict_types=1);
namespace Dn\Notify;

use Dn\Db\Database;

/**
 * The SMS sender the WORKER's environment chose (DN_SMS set; docs/127 S-5),
 * wrapped only so that the Admin panel can say so (docs/128 SS-9, SS-10).
 *
 * Where DN_SMS is set, the environment wins exactly as in phase 2 — including
 * 'africastalking' without its variables refusing to start, which happens
 * before this class is constructed — and the panel's settings are ignored. This
 * wrapper changes nothing about sending. It reports 'environment' through
 * mt_sms_worker_report(), once and then at most once a minute, so the page does
 * not offer a form whose values the worker would silently ignore.
 */
final class EnvironmentSms implements SmsSender
{
    private ?float $reportedAt = null;

    public function __construct(
        private readonly SmsSender $inner,
        private readonly Database $db,
        private readonly int $heartbeatSeconds = 60,
    ) {}

    /** The phase-2 name, 'null' or 'africastalking': the worker's log line is unchanged. */
    public function bindingName(): string { return $this->inner->bindingName(); }

    public function isConfigured(): bool
    {
        if ($this->reportedAt === null || microtime(true) - $this->reportedAt >= $this->heartbeatSeconds) {
            $this->reportedAt = microtime(true);
            try {
                $this->db->one('SELECT mt_sms_worker_report(?,?,?) AS r',
                    [null, 'environment', 'DN_SMS=' . $this->inner->bindingName() . ' in the worker\'s environment']);
            } catch (\Throwable) {
                // A status that cannot be written must never stop a code being sent.
            }
        }
        return $this->inner->isConfigured();
    }

    public function send(string $to, string $message): SmsResult
    {
        return $this->inner->send($to, $message);
    }
}
