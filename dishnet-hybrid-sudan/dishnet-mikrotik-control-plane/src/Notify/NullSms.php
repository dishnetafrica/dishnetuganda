<?php
declare(strict_types=1);
namespace Dn\Notify;

/**
 * DN_SMS unset: nothing is sent, and this says so. The worker never claims a
 * message while this is bound, so no attempt is counted against a message and
 * each one ends as 'expired' when its code does.
 */
final class NullSms implements SmsSender
{
    public function bindingName(): string { return 'null'; }
    public function isConfigured(): bool  { return false; }

    public function send(string $to, string $message): SmsResult
    {
        return SmsResult::retry('no SMS sender is configured');
    }
}
