<?php
declare(strict_types=1);
namespace Dn\Notify;

/**
 * Africa's Talking SMS (docs/127 S-6) — the first real sender.
 *
 * EVIDENCE, stated per piece rather than for the whole:
 *   MEASURED from the provider's official SDK (npm `africastalking` 0.7.9,
 *   "Official AfricasTalking node.js API wrapper", lib/common.js and
 *   lib/sms.js, read 2026-09-24): the live endpoint below; the sandbox
 *   endpoint, selected when the username is `sandbox`; POST with an `apikey`
 *   header and `Accept: application/json`; a form-encoded body of `username`,
 *   `to`, `message` and an optional `from`; success is HTTP 201 and nothing
 *   else.
 *   DOCUMENTED, UNVERIFIED — from the provider's published API as known, since
 *   its documentation host is blocked from the session that wrote this: the
 *   answer's `SMSMessageData.Recipients[].statusCode` and `messageId`, and the
 *   meaning of each status code below.
 * Nothing here is VERIFIED until the operator's own account sends a real
 * message from staging.
 *
 * WHAT IS NEVER WRITTEN ANYWHERE: the key, the number, the message. Every
 * reason this class returns is built from a fixed vocabulary plus the
 * provider's numeric status, never from the provider's free text — and the key
 * is scrubbed from it anyway, so a mistake here cannot publish it.
 *
 * The endpoint is NOT configurable from the environment: a configuration value
 * that could point this at another host would be a way to send every sign-in
 * code somewhere else. Only a test may pass a loopback endpoint, and nothing
 * but HTTPS or a loopback address is accepted at all.
 */
final class AfricasTalkingSms implements SmsSender
{
    public const LIVE    = 'https://api.africastalking.com/version1/messaging';
    public const SANDBOX = 'https://api.sandbox.africastalking.com/version1/messaging';

    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT         = 10;

    /** The provider accepted the message. */
    private const ACCEPTED  = [100 => 'Processed', 101 => 'Sent', 102 => 'Queued'];
    /** It will be refused identically on every attempt. */
    private const PERMANENT = [401 => 'RiskHold', 402 => 'InvalidSenderId', 403 => 'InvalidPhoneNumber',
                               404 => 'UnsupportedNumberType', 406 => 'UserInBlacklist',
                               409 => 'DoNotDisturbRejection'];
    /** Worth another attempt while the code is still valid. */
    private const TRANSIENT = [405 => 'InsufficientBalance', 407 => 'CouldNotRoute',
                               500 => 'InternalServerError', 501 => 'GatewayError', 502 => 'RejectedByGateway'];

    private string $endpoint;

    public function __construct(
        private string $username,
        private string $apiKey,
        private ?string $sender = null,
        ?string $endpoint = null,            // tests only: a loopback fake. Never read from the environment.
        private int $connectTimeout = self::CONNECT_TIMEOUT,
        private int $timeout = self::TIMEOUT,
    ) {
        if (trim($username) === '' || trim($apiKey) === '') {
            throw new \InvalidArgumentException("Africa's Talking needs a username and an API key");
        }
        if ($sender !== null && preg_match('/^[A-Za-z0-9 ._-]{1,15}$/', $sender) !== 1) {
            throw new \InvalidArgumentException('the SMS sender name must be 1–15 letters, digits, spaces, dots, dashes or underscores');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('the curl extension is required to send SMS');
        }
        $this->endpoint = $endpoint ?? (strtolower(trim($username)) === 'sandbox' ? self::SANDBOX : self::LIVE);
        if (!self::acceptableEndpoint($this->endpoint)) {
            throw new \InvalidArgumentException(
                'refusing an SMS endpoint that is not HTTPS; only a loopback test server may use plain HTTP');
        }
    }

    public static function acceptableEndpoint(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) { return false; }
        if ($p['scheme'] === 'https') { return true; }
        return $p['scheme'] === 'http' && in_array($p['host'], ['127.0.0.1', 'localhost', '[::1]'], true);
    }

    public function bindingName(): string { return 'africastalking'; }
    public function isConfigured(): bool  { return true; }
    public function isSandbox(): bool     { return $this->endpoint === self::SANDBOX; }

    public function send(string $to, string $message): SmsResult
    {
        $fields = ['username' => $this->username, 'to' => $to, 'message' => $message];
        if ($this->sender !== null) { $fields['from'] = $this->sender; }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['apikey: ' . $this->apiKey, 'Accept: application/json',
                                       'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $res = ($body === false || $errno !== 0)
            ? SmsResult::retry('transport: ' . self::transportReason($errno))
            : self::classify($status, (string) $body);
        return $this->scrubbed($res);
    }

    /**
     * The provider's answer, read. Public and static so the classification can
     * be proved case by case without a socket; send() is proved against a
     * loopback server separately.
     */
    public static function classify(int $status, string $body): SmsResult
    {
        if ($status === 401 || $status === 403) {
            return SmsResult::failed("the provider refused the credentials (HTTP {$status})");
        }
        if ($status >= 500 && $status <= 599) { return SmsResult::retry("HTTP {$status}"); }
        if ($status >= 400 && $status <= 499) { return SmsResult::failed("HTTP {$status}"); }
        if ($status !== 201) {
            // The SDK treats nothing but 201 as success, and neither does this.
            return SmsResult::retry("unexpected HTTP {$status}");
        }

        $j = json_decode($body, true);
        if (!is_array($j) || !is_array($j['SMSMessageData'] ?? null)) {
            return SmsResult::retry('malformed answer');
        }
        $recipients = $j['SMSMessageData']['Recipients'] ?? null;
        if (!is_array($recipients) || $recipients === [] || !is_array($recipients[0] ?? null)) {
            // Accepted as a request, sent to nobody — the provider's own word
            // for why is kept only when it is a single bare token.
            $said = (string) ($j['SMSMessageData']['Message'] ?? '');
            return SmsResult::failed('no recipient was accepted'
                . (preg_match('/^[A-Za-z]{1,40}$/', $said) === 1 ? " (provider said {$said})" : ''));
        }

        $r    = $recipients[0];
        $code = is_int($r['statusCode'] ?? null) ? $r['statusCode']
              : (is_string($r['statusCode'] ?? null) && ctype_digit($r['statusCode']) ? (int) $r['statusCode'] : null);
        if ($code === null) { return SmsResult::retry('malformed answer'); }

        if (isset(self::ACCEPTED[$code])) {
            $id = (string) ($r['messageId'] ?? '');
            return SmsResult::sent(preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $id) === 1 ? $id : null);
        }
        if (isset(self::PERMANENT[$code])) {
            return SmsResult::failed("provider status {$code} " . self::PERMANENT[$code]);
        }
        if (isset(self::TRANSIENT[$code])) {
            return SmsResult::retry("provider status {$code} " . self::TRANSIENT[$code]);
        }
        return SmsResult::retry("provider status {$code}");
    }

    private static function transportReason(int $errno): string
    {
        return match ($errno) {
            6       => 'could not resolve the provider',
            7       => 'could not connect to the provider',
            28      => 'timed out',
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'TLS failure',
            default => 'curl error ' . $errno,
        };
    }

    /** Belt and braces: whatever a reason says, it never says the key. */
    private function scrubbed(SmsResult $r): SmsResult
    {
        if ($r->error === null || !str_contains($r->error, $this->apiKey)) { return $r; }
        $e = str_replace($this->apiKey, '<withheld>', $r->error);
        return $r->outcome === SmsResult::RETRY ? SmsResult::retry($e) : SmsResult::failed($e);
    }
}
