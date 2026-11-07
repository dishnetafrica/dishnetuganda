<?php
declare(strict_types=1);

/**
 * OfferGateway — how an offer leaves (5.18.104 P6, root docs/73 §H.4.11, D10). After ShopBot's WhatsAppGateway
 * (root docs/12-shopbot-inspection.md:83-88): one interface, a driver per transport. EvolutionOfferGateway today; a Meta
 * Cloud API driver when the official number exists, for offers only. The ledger claim and settle stay OUTSIDE the
 * gateway (OfferSender), so every driver is paced by the same WaSendBudget. PHP 7.4 compatible.
 *
 * A Meta driver later (not built; recorded for D10): handsetFor() would return a ledger key for the official number,
 * send() would map the offer to an approved marketing template, transient() would cover Meta's rate answers, and there
 * is no echo to claim ('none').
 */
interface OfferGateway
{
    /** 'evolution' | later 'meta_cloud' — stored nowhere, printed by the tool. */
    public function name(): string;

    /** The ledger's key for this channel, WaSendBudget::handset(instance); '' = cannot send here. */
    public function handsetFor(string $channel): string;

    /** Why nothing may go to $phone on $channel now: '' | a fixed code. */
    public function refusal(string $channel, string $phone): string;

    /** Whether that code may clear by itself (defer) rather than end the attempt (skip). */
    public function transient(string $code): bool;

    /**
     * One message, one attempt, never repeated inside. Where the transport echoes our own message back (Evolution
     * does), the echo is claimed BEFORE this returns and the outcome is in the result. Throws nothing: a failure is a result.
     */
    public function send(OfferMessage $m): OfferSendResult;
}

final class OfferMessage
{
    /** @var string */ public $channel;
    /** @var string digits */ public $phone;
    /** @var string the wording and the STOP line */ public $text;
    /** @var array|null ['payload' => base64, 'mime' => …] */ public $image;
    /** @var string 'offer.send' | 'offer.test' */ public $echoEvent;
    /** @var string ContactOptOut::CLASS_PROACTIVE | CLASS_STAFF */ public $class;

    public function __construct(string $channel, string $phone, string $text, ?array $image, string $echoEvent, string $class)
    {
        $this->channel   = $channel;
        $this->phone     = $phone;
        $this->text      = $text;
        $this->image     = $image;
        $this->echoEvent = $echoEvent;
        $this->class     = $class;
    }
}

final class OfferSendResult
{
    const SENT = 'sent'; const MAYBE_SENT = 'maybe_sent'; const NOT_SENT = 'not_sent';
    const REFUSED = 'refused'; const OPTED_OUT = 'opted_out';
    /** @var string */ public $outcome;
    /** @var string a fixed code, never transport text */ public $code;
    /** @var string */ public $messageId;
    /** @var bool */ public $transient;
    /** @var string 'claimed' | 'late' | 'none' (O-D18) */ public $echo;

    public function __construct(string $outcome, string $code = '', string $messageId = '', bool $transient = false, string $echo = 'none')
    {
        $this->outcome   = $outcome;
        $this->code      = $code;
        $this->messageId = $messageId;
        $this->transient = $transient;
        $this->echo      = $echo;
    }
}
