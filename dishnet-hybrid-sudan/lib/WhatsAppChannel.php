<?php
declare(strict_types=1);

/**
 * WhatsAppChannel — the provider PORT for distributor notifications (docs/49 §3.3, §4.1).
 *
 * The distributor-notification layer is written against this port, NEVER against
 * Evolution directly, so there is always exactly ONE adapter bound live for a
 * given stream and never a second, conflicting WhatsApp integration (the
 * assignment's explicit constraint). docs/49 §3 keeps the transport decision
 * (Evolution vs the official Cloud API) open; the port is what lets a future
 * CloudApiWhatsAppChannel replace the Evolution one for this traffic without a
 * rewrite.
 *
 * PILOT POSTURE (Bhavin B-4; the "no live WhatsApp sends" boundary): the bound
 * adapter is NullWhatsAppChannel — it sends NOTHING. An approved distributor
 * alert is QUEUED, not delivered. Connecting a real number (the Evolution
 * adapter below, or a Cloud-API one later) is a separate, explicitly-approved
 * step; nothing in this phase binds it. The Evolution adapter exists so the port
 * has its one real adapter and the seam is proven, but it is constructed NOWHERE
 * in the pilot's wiring.
 */
interface WhatsAppChannel
{
    /**
     * Attempt to send. In the pilot the bound adapter is Null, so this returns
     * sent=false with a reason and nothing leaves the server.
     * @return array{sent:bool, detail:string}
     */
    public function send(string $phone, string $text): array;

    /** A short name for logs/UI ('none', 'evolution', …). */
    public function name(): string;

    /** Whether this adapter actually delivers to a real number. */
    public function isLive(): bool;
}

/**
 * The bound adapter in the pilot. It delivers nothing — an approved alert is
 * queued and a human/operator connects a real transport in a later, approved
 * step. This is the direct analogue of the Domain-B NullDelivery binding: the
 * spine is proven end to end; the transport is deliberately absent.
 */
final class NullWhatsAppChannel implements WhatsAppChannel
{
    public function send(string $phone, string $text): array
    {
        return ['sent' => false, 'detail' => 'queued — live WhatsApp sending is not enabled in the distributor pilot'];
    }
    public function name(): string { return 'none'; }
    public function isLive(): bool { return false; }
}

/**
 * The one real adapter: a thin wrapper over the EXISTING EvolutionApiService,
 * sending from a DishNet-controlled channel. DEFINED for the port, but NOT bound
 * anywhere in the pilot — binding it is a separate, explicitly-approved step
 * (docs/49 §3.3, §10 Phase 5). It is CLASS_STAFF because the recipient is the
 * distributor's own number and a customer's opt-out must never suppress a
 * distributor's operational alert (docs/49 §11); the distributor's own consent
 * is checked upstream in DistributorNotifier, before anything reaches a channel.
 */
final class EvolutionWhatsAppChannel implements WhatsAppChannel
{
    private EvolutionApiService $evo;
    private string $channel;

    public function __construct(EvolutionApiService $evo, string $channel = EvolutionApiService::CHANNEL_SALES)
    {
        $this->evo = $evo;
        $this->channel = $channel;
    }

    public function send(string $phone, string $text): array
    {
        $r = $this->evo->sendText($this->channel, $phone, $text, ContactOptOut::CLASS_STAFF);
        // Success as NotificationService::sendViaEvolution reads it: not suppressed,
        // no error, and ok (if present) true.
        $ok = is_array($r) && empty($r['suppressed']) && empty($r['error'])
            && (!isset($r['ok']) || !empty($r['ok']));
        return ['sent' => $ok, 'detail' => is_array($r) ? (string)($r['error'] ?? ($ok ? 'sent' : 'send failed')) : 'send failed'];
    }
    public function name(): string { return 'evolution'; }
    public function isLive(): bool { return true; }
}
