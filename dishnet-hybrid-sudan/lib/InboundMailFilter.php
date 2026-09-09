<?php
/**
 * InboundMailFilter — decides which incoming mail the AI is allowed to answer.
 *
 * This class exists to prevent one specific disaster. An auto-replying mailbox
 * that answers a bounce, an out-of-office, or another autoresponder creates a
 * loop: two machines writing to each other until somebody notices. A loop from
 * dishnetuganda.com would destroy a sending reputation that is only weeks old
 * and was expensive to build — the domain authentication, the SPF and DMARC
 * records, the relay's trust.
 *
 * So the default is silence. A message is answered only if nothing here
 * objects, and every rule is written to fail towards ignoring.
 *
 * Pure: no database, no network, no clock beyond what is passed in. Every
 * decision is a function of the message, which is what makes it testable.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class InboundMailFilter
{
    /**
     * Header names that mark a message as machine-generated. RFC 3834 defines
     * Auto-Submitted for exactly this purpose; the rest are what mail systems
     * actually send in practice.
     */
    const AUTO_HEADERS = [
        'auto-submitted', 'x-auto-response-suppress', 'x-autoreply',
        'x-autorespond', 'x-mailer-daemon', 'x-failed-recipients',
    ];

    /** Headers that mark a mailing list or bulk send. */
    const LIST_HEADERS = [
        'list-id', 'list-unsubscribe', 'list-post', 'list-help',
        'x-mailchimp-id', 'x-campaign-id', 'x-sg-eid',
    ];

    /** Local-parts that never belong to a person expecting a reply. */
    const ROBOT_LOCALPARTS = [
        'no-reply', 'noreply', 'no_reply', 'donotreply', 'do-not-reply',
        'mailer-daemon', 'mailerdaemon', 'postmaster', 'bounce', 'bounces',
        'notifications', 'notification', 'newsletter', 'news', 'marketing',
        'automated', 'auto', 'daemon', 'root', 'cron', 'nobody',
    ];

    /**
     * Subject openings that mark an automatic response. Matched at the START
     * of the subject so a customer writing "my out of office is broken" is
     * still answered.
     */
    const AUTO_SUBJECT_PREFIXES = [
        'out of office', 'automatic reply', 'auto:', 'autoreply',
        'undeliverable', 'undelivered mail', 'delivery status notification',
        'mail delivery failed', 'returned mail', 'failure notice',
        'delivery has failed', 'message blocked', 'read:', 'not read:',
        'abwesenheitsnotiz', 'réponse automatique',
    ];

    /**
     * Should this message be left alone?
     *
     * @param array  $headers  lower-cased header name => value
     * @param string $from     the envelope/From address
     * @param string $subject
     * @param array  $ourAddresses  every address this platform sends as
     * @return array{ignore:bool, reason:string}
     */
    public static function assess(array $headers, string $from, string $subject, array $ourAddresses = []): array
    {
        $h = [];
        foreach ($headers as $k => $v) $h[strtolower(trim((string)$k))] = trim((string)$v);

        // ── 1. Our own mail, coming back to us ──────────────────────────────
        // The single most dangerous case: the platform answering itself.
        $fromAddr = strtolower(self::addressOf($from));
        foreach ($ourAddresses as $ours) {
            if ($fromAddr !== '' && $fromAddr === strtolower(self::addressOf((string)$ours))) {
                return self::ignore('the message is from one of our own addresses');
            }
        }
        // Our own outgoing mail carries this; a reply to it will not.
        if (isset($h['x-dishnet-auto'])) {
            return self::ignore('the message carries our own automation header');
        }

        // ── 2. Bounces and delivery reports ─────────────────────────────────
        // An empty Return-Path is the null sender: by RFC 5321 that IS a
        // bounce, and answering it is the classic loop.
        if (isset($h['return-path']) && in_array(trim($h['return-path'], " \t<>"), ['', '<>'], true)) {
            return self::ignore('null Return-Path — this is a bounce');
        }
        $ctype = strtolower($h['content-type'] ?? '');
        if (strpos($ctype, 'multipart/report') !== false
            || strpos($ctype, 'message/delivery-status') !== false) {
            return self::ignore('a delivery status report');
        }

        // ── 3. Machine-generated ────────────────────────────────────────────
        foreach (self::AUTO_HEADERS as $name) {
            if (!isset($h[$name])) continue;
            // Auto-Submitted: no means a human sent it, and is explicitly
            // allowed by RFC 3834.
            if ($name === 'auto-submitted' && strtolower($h[$name]) === 'no') continue;
            return self::ignore("the {$name} header marks this as automatic");
        }
        $prec = strtolower($h['precedence'] ?? '');
        if (in_array($prec, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
            return self::ignore("Precedence: {$prec}");
        }

        // ── 4. Mailing lists and campaigns ──────────────────────────────────
        foreach (self::LIST_HEADERS as $name) {
            if (isset($h[$name])) return self::ignore("the {$name} header marks a bulk send");
        }

        // ── 5. Robot senders ────────────────────────────────────────────────
        $local = strtolower(explode('@', $fromAddr)[0] ?? '');
        foreach (self::ROBOT_LOCALPARTS as $robot) {
            // Exact, or the address is clearly built around it
            // (no-reply-4821@, bounces+abc@). Substring alone would catch
            // "Antonio" for "auto".
            if ($local === $robot
                || strpos($local, $robot . '-') === 0
                || strpos($local, $robot . '+') === 0
                || strpos($local, $robot . '.') === 0
                || strpos($local, $robot . '_') === 0) {
                return self::ignore("the sender is {$robot}@, which does not expect a reply");
            }
        }

        // ── 6. Automatic-response subjects ──────────────────────────────────
        $subj = strtolower(trim($subject));
        // Strip any number of Re:/Fwd: so "Re: Out of office" is still caught.
        $subj = preg_replace('/^(\s*(re|fwd|fw|aw|sv)\s*:\s*)+/i', '', $subj);
        foreach (self::AUTO_SUBJECT_PREFIXES as $pfx) {
            if (strpos($subj, $pfx) === 0) {
                return self::ignore("the subject opens with \"{$pfx}\"");
            }
        }

        // ── 7. A message with no sender we can reply to ─────────────────────
        if ($fromAddr === '' || !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
            return self::ignore('no usable sender address');
        }

        return ['ignore' => false, 'reason' => ''];
    }

    /**
     * A second line of defence that does not depend on reading headers right.
     *
     * Even with every rule above correct, a misconfigured correspondent can
     * bounce replies back at us. If we have already written to this address
     * several times in a short window, stop — whatever is happening, more
     * mail will not improve it.
     *
     * @param int $repliesSent  replies already sent to this address in $window
     * @return array{ignore:bool, reason:string}
     */
    public static function rateLimit(int $repliesSent, int $maxPerWindow = 3, int $windowHours = 24): array
    {
        if ($repliesSent >= $maxPerWindow) {
            return self::ignore("already replied {$repliesSent} times to this address in {$windowHours}h "
                              . '— stopping in case this is a loop');
        }
        return ['ignore' => false, 'reason' => ''];
    }

    /** The bare address out of "Name <a@b.c>" or "a@b.c". */
    public static function addressOf(string $v): string
    {
        if (preg_match('/<([^>]+)>/', $v, $m)) return trim($m[1]);
        return trim($v);
    }

    private static function ignore(string $why): array
    {
        return ['ignore' => true, 'reason' => $why];
    }
}
