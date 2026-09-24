<?php
declare(strict_types=1);
namespace Dn\Notify;

/**
 * The port every SMS provider is reached through (docs/127 S-5).
 *
 * Selected by DN_SMS in the WORKER's environment (SmsSenders::fromEnvironment)
 * and never by a request. A sender that is not configured is never asked to
 * send: the worker then claims nothing, and every queued message ends as
 * 'expired' — which is the truth.
 */
interface SmsSender
{
    /** 'null', 'africastalking', or a test double's own name. */
    public function bindingName(): string;

    /** False only for NullSms: nothing can be sent, so nothing is claimed. */
    public function isConfigured(): bool;

    /** @param string $to the canonical phone, E.164 */
    public function send(string $to, string $message): SmsResult;
}
