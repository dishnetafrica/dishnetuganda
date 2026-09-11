<?php
declare(strict_types=1);

require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/OtpEmailTemplate.php';
require_once __DIR__ . '/EmailTemplate.php';

/**
 * OtpEmail — the one place a login code is turned into an email.
 *
 * It was inline in the API dispatcher, which is fine until you want to prove
 * it works. A test tool that rebuilds the same message beside it is not a
 * test of anything: the two drift, and the one you can run is the one that
 * is not wired up. So the dispatcher and the tool now call this.
 *
 * Three decisions live here, and they are the ones that go wrong:
 *
 *   FROM     the system sender if configured, header and envelope both, so
 *            replies and bounces do not land in the sales mailbox.
 *   REPLY-TO the operation the customer belongs to. Sudan's address was
 *            hardcoded here once and Ugandan customers were told to reply
 *            to Juba.
 *   FAILURE  reported, never thrown. WhatsApp is the primary channel for a
 *            code; email is the second one. A refused relay must not take
 *            down a login that already succeeded by other means.
 */
final class OtpEmail
{
    /**
     * @param array  $config  plugin config, for the Reply-To identity
     * @return array{ok:bool, error:string, log?:array, from?:string}
     */
    public static function send(array $config, string $dataDir, string $toEmail,
                                string $name, string $code, int $ttlMinutes): array
    {
        $firstName = explode(' ', trim($name))[0] ?: '';
        $subject   = OtpEmailTemplate::subject($code);
        $html      = OtpEmailTemplate::html($firstName, $code, $ttlMinutes, $config);
        $text      = OtpEmailTemplate::text($firstName, $code, $ttlMinutes, $config);

        $mailer = new MailService($dataDir);
        $from   = $mailer->systemFrom();

        $result = $mailer->send($toEmail, $name, $subject, $html, $text, [
            'Reply-To' => EmailTemplate::replyTo($config),
        ], [], $from);

        return [
            'ok'    => !empty($result['ok']),
            'error' => !empty($result['ok']) ? '' : (string)($result['error'] ?? 'Unknown SMTP error'),
            'log'   => $result['log'] ?? [],
            // What it actually went out as — '' meaning the ordinary sender.
            // A tool that has to guess this can only report that mail was
            // accepted, which is the question nobody was asking.
            'from'  => $from,
        ];
    }
}
