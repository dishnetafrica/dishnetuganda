<?php
declare(strict_types=1);
/** TEST ONLY: tests/voice_ack's fake brain, loaded by va_load() once DishNetAiBrain is (5.18.104, root docs/73 §H.3.24). */
/** A fake brain: answers $canned (markers parsed as the real one parses them), records each context. */
class VaBrain extends DishNetAiBrain
{
    public $canned = 'VA-FAKE-ANSWER: thank you, we can help with that.';
    /** @var array[] */
    public $contexts = [];
    /** @var array<int,string> conversation id => canned answer for that conversation only */
    public $per = [];
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->contexts[] = $context;
        $text = $this->canned;
        $m = new \ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
        $m->setAccessible(true);
        $out = $m->invoke($this, $text);
        return is_array($out) ? $out : ['reply' => $text];
    }
    public function getLastUsage(): array { return []; }
}

/**
 * The Evolution client with the channel registry's answers planted (5.18.104 tests): registryOn() as set, each channel's
 * context from a row, and — through usePolicy() — the automated-send policy a registry of those rows would give
 * (AutomationPolicy::fromRouting, set into the service's own policy property): DishNet's own numbers and the policy's
 * refusals. Everything else is the real client against the fake Evolution.
 */
class VaEvo extends EvolutionApiService
{
    public $reg = true;
    /** @var array<string,array> channel => a wa_channels-shaped row */
    public $rows = [];
    /** @var array|null a planted replyRoute answer */
    public $route = null;
    /** @var array<string,string> channel => instance, beside the configured three (a handset of its own per case) */
    public $inst = [];
    public function registryOn(): bool { return $this->reg; }
    public function instanceFor(string $channel): string { return $this->inst[$channel] ?? parent::instanceFor($channel); }
    /** @var array|null a planted answer for the next text send (it is then cleared), e.g. a policy refusal at the send */
    public $sendAnswer = null;
    public function sendText(string $channel, string $phone, string $text, string $class = ContactOptOut::CLASS_PROACTIVE): array
    {
        if ($this->sendAnswer !== null) { $a = $this->sendAnswer; $this->sendAnswer = null; return $a; }
        return parent::sendText($channel, $phone, $text, $class);
    }
    public function channelContext(string $channel): ?ChannelContext
    {
        $r = $this->rows[$channel] ?? null;
        return $r === null ? null : new ChannelContext($r + ['channel_id' => $channel], $this->instanceFor($channel));
    }
    public function replyRoute(string $channel, string $inboundInstance): array
    {
        return $this->route ?? parent::replyRoute($channel, $inboundInstance);
    }
    /** The policy of a registry holding $rows (channel => row), set as this service's own. */
    public function usePolicy(array $rows, array $cfg): void
    {
        $ctx = []; $c2i = [];
        foreach ($rows as $ch => $r) { $ctx[$ch] = new ChannelContext($r + ['channel_id' => $ch], $this->instanceFor($ch)); $c2i[$ch] = $this->instanceFor($ch); }
        $p = AutomationPolicy::fromRouting(['channel_to_instance' => $c2i, 'contexts' => $ctx], null, [],
                                           EvolutionApiService::configInstanceMap($cfg), $cfg, null, null);
        $rp = new \ReflectionProperty(EvolutionApiService::class, 'policy'); $rp->setAccessible(true); $rp->setValue($this, $p);
    }
}

/** Set a worker's Evolution client (the reply worker's private $evo). */
function va_use_evo($worker, EvolutionApiService $evo): void
{
    $p = new \ReflectionProperty(get_class($worker), 'evo'); $p->setAccessible(true); $p->setValue($worker, $evo);
}
