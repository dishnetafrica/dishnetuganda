<?php
declare(strict_types=1);

/**
 * DraftMailCopy — put the AI's draft in the mailbox, as a draft.
 *
 * The approval inbox that already exists on every desk is the mail client. A
 * reply waiting in Drafts can be read on a phone, edited in the same window
 * everyone already uses, and sent with the button they already know — and it
 * threads under the customer's own message, so the context is right there.
 * Nothing new to learn, and no second place to check.
 *
 * It is filed with the \Draft flag and never \Seen: a draft that arrives
 * pre-read is a draft nobody notices.
 *
 * WHAT THIS DOES NOT DO: it does not send, and it cannot. It hands a written
 * reply to a person and stops. If they then send it from webmail, that IS the
 * approval — but our own record will still say "pending", because the send
 * happened somewhere we cannot see. That gap is real and is the price of
 * using the mail client as the inbox; the alternative is a second approval
 * screen that nobody opens.
 */
class DraftMailCopy
{
    /**
     * Build the reply as RFC 5322 and file it in Drafts.
     *
     * @param array $settings  the email_settings.json map, as SentCopy takes
     * @param array $draft     a row from EmailDraftStore
     * @param array $config    plugin config, for the From identity
     *
     * @return array{ok:bool, error:string, folder:string}
     */
    public static function place(array $settings, array $draft, array $config): array
    {
        require_once __DIR__ . '/SentCopy.php';
        require_once __DIR__ . '/EmailTemplate.php';
        require_once __DIR__ . '/MailService.php';

        $body = trim((string)($draft['draft_body'] ?? ''));
        if ($body === '') {
            // An empty draft is one the assistant refused to write. Filing a
            // blank reply would put an empty message in front of a person with
            // no hint that it is deliberate.
            return ['ok' => false, 'error' => 'the draft is empty — nothing to file', 'folder' => ''];
        }

        $to = trim((string)($draft['from_addr'] ?? ''));
        if ($to === '') return ['ok' => false, 'error' => 'no recipient on the draft', 'folder' => ''];

        $brand = EmailTemplate::brand($config);
        $from  = $brand['reply_to'];
        if ($from === '') return ['ok' => false, 'error' => 'no From address configured', 'folder' => ''];

        $subject = (string)($draft['draft_subject'] ?? '');
        if (trim($subject) === '') $subject = 'Re: ' . (string)($draft['subject'] ?? 'your message');

        // Threading. In-Reply-To and References carry the customer's own
        // Message-ID, so every mail client files the reply under their message
        // rather than starting a conversation beside it.
        $extra = [];
        $their = trim((string)($draft['message_id'] ?? ''));
        if ($their !== '' && strpos($their, '@') !== false) {
            if ($their[0] !== '<') $their = '<' . trim($their, '<>') . '>';
            $extra['In-Reply-To'] = $their;
            $extra['References']  = $their;
        }

        // Marked as ours, and marked as a draft an assistant wrote. A colleague
        // opening it should never have to wonder who composed it, and the
        // header survives if it is forwarded internally.
        $extra['X-DishNet-Draft']  = 'assistant';
        $extra['X-DishNet-Review'] = trim((string)($draft['escalation'] ?? '')) !== ''
            ? (string)$draft['escalation'] : 'human approval required';

        // composeMime returns [headers, body] — the same shape the sender uses
        // one line before it opens SMTP, so a draft and a sent message are
        // built by exactly the same code.
        $mailer = new MailService(dirname(__DIR__));
        [$headerStr, $mimeBody] = $mailer->composeMime(
            self::header($brand['company_name'], $from),
            $to,
            $subject,
            self::html($body),
            $body,
            $extra
        );
        $raw = $headerStr . "\r\n" . $mimeBody;

        return SentCopy::append($settings, $raw, [
            'folders' => ['Drafts', 'INBOX.Drafts'],
            'special' => '\\Drafts',
            // \Draft so the client offers Edit rather than Reply. Never \Seen:
            // a draft that arrives already read is a draft nobody notices.
            'flags'   => '\\Draft',
            'purpose' => 'Drafts',
        ]);
    }

    /** "Name <address>", with the name quoted only when it needs to be. */
    public static function header(string $name, string $address): string
    {
        $name = trim($name);
        if ($name === '') return $address;
        return '"' . str_replace('"', '', $name) . '" <' . $address . '>';
    }

    /**
     * The plain text as HTML, and nothing more.
     *
     * Deliberately not the branded template. What a person edits in their mail
     * client is what the customer gets, and a reviewer who edits inside a
     * table-based email shell will break it without seeing that they have.
     */
    public static function html(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;'
             . 'line-height:1.55;color:#222;white-space:pre-wrap">' . $safe . '</div>';
    }
}
