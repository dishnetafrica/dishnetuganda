<?php
declare(strict_types=1);
namespace Dn\Notify;

/**
 * What a sender says about one message — and nothing it cannot know.
 *
 *   sent    the provider ACCEPTED it (not "the phone received it": delivery
 *           reports are a separate, later signal this build does not read)
 *   retry   worth another attempt: a timeout, a 5xx, an unreadable answer
 *   failed  will fail identically forever: a refused number, a refused
 *           sender name, refused credentials
 *
 * `error` is built by the sender from a fixed vocabulary plus the provider's
 * numeric status — never the provider's free text, never the number, the
 * message or the key — because it is stored and printed.
 */
final class SmsResult
{
    public const SENT = 'sent', RETRY = 'retry', FAILED = 'failed';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $providerRef,
        public readonly ?string $error,
    ) {}

    public static function sent(?string $providerRef): self { return new self(self::SENT, $providerRef, null); }
    public static function retry(string $why): self         { return new self(self::RETRY, null, $why); }
    public static function failed(string $why): self        { return new self(self::FAILED, null, $why); }
}
