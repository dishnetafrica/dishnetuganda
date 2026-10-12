<?php
declare(strict_types=1);

require_once __DIR__ . '/ContactOptOut.php';

/**
 * EvolutionApiService — DishNet's Evolution API adapter.
 *
 * One place that knows how to talk to Evolution API. Nothing else in the plugin
 * should build an Evolution URL or hold the API key.
 *
 * Verified against Evolution API v2.3.7 (the version the DishNet instance
 * reports). Endpoint set is the intersection of the two Evolution clients
 * already running in production here — lib/EvolutionApiClient.php and
 * ShopBot's EvolutionGateway — so nothing below is speculative.
 *
 * Channel routing: DishNet runs one WhatsApp number per business channel.
 * This class maps channel <-> instance in both directions, because the webhook
 * needs instance -> channel and the sender needs channel -> instance.
 *
 * Configuration lives in kyc_config.json inside UCRM's pluginDataDir. That
 * directory is outside the plugin tree and outside git, which is where the API
 * key belongs. There is no .env in a UCRM plugin — this store IS the
 * plugin's configuration mechanism.
 *
 *   evo_api_url            https://evo.example.host       (no trailing slash)
 *   evo_api_key            <secret>
 *   evo_instance_sales     dishnet_sales
 *   evo_instance_support   dishnet_support
 *   evo_instance_account   dishnet_account
 *
 * PHP 7.4 compatible. Pure curl, no dependencies.
 */
class EvolutionApiService
{
    /** Business channels. These strings appear in wa_conversations.channel. */
    const CHANNEL_SALES   = 'sales';
    const CHANNEL_SUPPORT = 'support';
    const CHANNEL_ACCOUNT = 'account';

    /** Channels we accept from a webhook or a send call. */
    const CHANNELS = [self::CHANNEL_SALES, self::CHANNEL_SUPPORT, self::CHANNEL_ACCOUNT];

    /**
     * 5.18.54 (docs/46 row 31, N-1), Uganda: how a failed send's error begins when the transport, not Evolution, ended
     * it. NOT_SENT: the request never left, so nothing can have reached WhatsApp. MAYBE_SENT: it left and no answer
     * came back, so the customer may have the message; it is never sent again automatically.
     */
    const NOT_SENT   = 'Not sent — ';
    const MAYBE_SENT = 'May have been sent — ';

    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    /** channel => instance name */
    private array $channelToInstance = [];
    /** lowercased instance name => channel */
    private array $instanceToChannel = [];

    private array $lastError = [];

    /** Resolved lazily; injectable for tests. false = not yet looked for. */
    private $optOut = false;
    private ?string $optOutDataDir = null;

    /** 5.18.54 (docs/46 row 31): the configuration, for NotifyGate; and its answer, once asked. */
    private array $gateConfig = [];
    private ?bool $noResendUg = null;

    /**
     * Give this service an opt-out list, or null to disable the check.
     *
     * Tests inject one. Production does not need to: the service finds its own,
     * because there are 27 places this class is constructed and a gate that has
     * to be wired up 27 times is a gate that is missing somewhere.
     */
    public function setOptOut(?ContactOptOut $o): void { $this->optOut = $o; }

    /** @return ContactOptOut|null */
    private function optOut()
    {
        if ($this->optOut === false) {
            if (!class_exists('ContactOptOut')) {
                $f = __DIR__ . '/ContactOptOut.php';
                if (is_file($f)) require_once $f;
            }
            $this->optOut = class_exists('ContactOptOut')
                ? ContactOptOut::resolve($this->optOutDataDir) : null;
        }
        return $this->optOut;
    }

    /**
     * Refuse a send the customer has opted out of.
     *
     * @return array|null the refusal to return to the caller, or null to proceed
     */
    private function optOutRefusal(string $phone, string $channel, string $class): ?array
    {
        $o = $this->optOut();
        if ($o === null) return null;
        $v = $o->blocks($phone, $channel, $class);
        if (!$v['blocked']) return null;
        error_log('[evo] send suppressed: ' . $v['reason']);
        return ['success' => false, 'suppressed' => true, 'optout_id' => $v['optout_id'],
                'error' => 'recipient has opted out: ' . $v['reason']];
    }

    public function __construct(array $config, int $timeout = 20)
    {
        $this->optOutDataDir = isset($config['_data_dir']) ? (string)$config['_data_dir'] : null;
        $this->baseUrl = self::normaliseBaseUrl((string)($config['evo_api_url'] ?? ''));
        $this->apiKey  = trim((string)($config['evo_api_key'] ?? ''));
        $this->timeout = $timeout;
        $this->gateConfig = $config;

        $map = self::configInstanceMap($config);

        foreach ($map as $channel => $instance) {
            if ($instance === '') continue;
            $this->channelToInstance[$channel] = $instance;
            // First write wins, and $map iterates sales -> support -> account.
            // One instance serving several channels therefore resolves to
            // SALES for inbound routing. Last-write-wins did the opposite: a
            // legacy accounts field naming the same instance silently stole
            // the mapping, and no amount of assigning sales in the UI could
            // ever fix it, because account was written after sales each load.
            $key = mb_strtolower($instance);
            if (!isset($this->instanceToChannel[$key])) {
                $this->instanceToChannel[$key] = $channel;
            }
        }
    }

    /**
     * The instance the configuration binds to each of the three department numbers, in the order the inbound map is
     * built (sales, support, account). Values may be ''. The constructor reads exactly this; 5.18.86 made it a function
     * so the channel registry reads the same answer rather than a copy of the rule (docs/65 §E).
     *
     * @return array<string,string> channel => instance
     */
    public static function configInstanceMap(array $config): array
    {
        // Preferred, explicit per-channel config.
        $map = [
            self::CHANNEL_SALES   => trim((string)($config['evo_instance_sales']   ?? '')),
            self::CHANNEL_SUPPORT => trim((string)($config['evo_instance_support'] ?? '')),
            self::CHANNEL_ACCOUNT => trim((string)($config['evo_instance_account'] ?? '')),
        ];

        // Backward compatibility with the single-instance config that shipped
        // before three numbers existed. Only fills gaps — never overrides.
        if ($map[self::CHANNEL_SUPPORT] === '') {
            $map[self::CHANNEL_SUPPORT] = trim((string)($config['evo_instance_name'] ?? ''));
        }
        if ($map[self::CHANNEL_ACCOUNT] === '') {
            $map[self::CHANNEL_ACCOUNT] = trim((string)($config['evo_accounts_instance_name'] ?? ''));
        }
        return $map;
    }

    // ── The channel registry (5.18.86, docs/65 §F–§I) ────────────────────────
    //
    // Dark unless multi_number_channels_enabled is ON on a Uganda install. Then, and only then, this service routes by
    // channel id through wa_channels: a channel that is not active has no instance (every send on it fails closed), an
    // instance the registry has switched off is REFUSED rather than unknown, and a reply may leave only on the
    // instance its message arrived on. OFF, forStore() returns exactly what `new EvolutionApiService($config)` returns.

    /** @var array{refused: array<string,string>, contexts: array<string,ChannelContext>}|null null = registry off */
    private ?array $registry = null;

    /**
     * The service every customer-facing path should build: the constructor's, plus the channel registry where it is on.
     *
     * A registry that cannot be read leaves the three department numbers exactly as configured and routes no other
     * channel — the switch must never take the existing numbers down — and says so once in the log.
     */
    public static function forStore(array $config, ?\PDO $pdo, ?string $dataDir, int $timeout = 20): self
    {
        $evo = new self($config, $timeout);
        if ($pdo === null) return $evo;
        if (!class_exists('ChannelRegistry')) require_once __DIR__ . '/ChannelRegistry.php';
        if (!\ChannelRegistry::enabled($config, $dataDir)) return $evo;
        try {
            $reg = new \ChannelRegistry($pdo);
            if (!$reg->available()) {
                self::sayOnce('[channels] ' . \ChannelRegistry::FLAG . ' is on but the channel registry is not installed '
                            . '(migration 087) — the three department numbers route as configured; no other channel does');
                return $evo;
            }
            // 5.18.90 (docs/65 §AD): the automated-send policy is built from the same read, so DishNet's own numbers can
            // never be "unreadable" while this service routes anything; a failed read leaves the departments as configured.
            $cm      = self::configInstanceMap($config);
            $routing = $reg->routing($cm);
            if (!class_exists('AutomationPolicy')) require_once __DIR__ . '/AutomationPolicy.php';
            $policy  = \AutomationPolicy::fromRouting($routing, $reg->rows(), $reg->verifiedInstances(), $cm, $config, $dataDir, $pdo);
            $evo->useRegistry($routing);
            $evo->policy = $policy;
            $policy->warnIfIncomplete();
        } catch (\Throwable $e) {
            self::sayOnce('[channels] the channel registry could not be read — the three department numbers route as '
                        . 'configured; no other channel does: ' . $e->getMessage());
        }
        return $evo;
    }

    /** @param array $routing ChannelRegistry::routing() */
    public function useRegistry(array $routing): void
    {
        $this->channelToInstance = (array)($routing['channel_to_instance'] ?? []);
        $this->instanceToChannel = (array)($routing['instance_to_channel'] ?? []);
        $this->registry = [
            'refused'  => (array)($routing['refused'] ?? []),
            'contexts' => (array)($routing['contexts'] ?? []),
        ];
        $this->policy = null;   // rebuilt from this routing when first asked (forStore sets the full one)
    }

    // ── The automated-send policy (5.18.90, docs/65 §AD) ─────────────────────
    //
    // Every reply-class and proactive-class send on a registry-on service — the AI's reply, its photos, documents and
    // flyer, the hand-over's holding line, a follow-up — and the typing indicator are asked AutomationPolicy first: the
    // channel known, active, its assistant on, a salesperson's number verified and the department numbers recorded, and
    // the recipient not DishNet's own. A refusal is returned, never sent, never sent from another number, and carries
    // policy_refused so no caller mistakes it for a transport failure and retries it. Staff and transactional sends
    // (an Inbox reply, a staff alert) are not automated customer messages and are not asked. Registry off: nothing here
    // runs.

    /** The classes that are automated customer messages: ContactOptOut::CLASS_REPLY and ContactOptOut::CLASS_PROACTIVE. */
    const AUTOMATED_CLASSES = ['reply', 'proactive'];

    /** How a policy refusal's error begins. */
    const POLICY_REFUSED = 'Not sent — refused by the automated-send policy: ';

    /** @var \AutomationPolicy|null null until asked, or until forStore sets it */
    private $policy = null;

    /** The policy this service sends under: inert with the registry off. */
    public function automationPolicy(): \AutomationPolicy
    {
        if ($this->policy === null) {
            if (!class_exists('AutomationPolicy')) require_once __DIR__ . '/AutomationPolicy.php';
            $this->policy = $this->registry === null ? \AutomationPolicy::inert()
                : \AutomationPolicy::fromRouting(['channel_to_instance' => $this->channelToInstance,
                                                  'contexts' => $this->registry['contexts']],
                                                 null, [], self::configInstanceMap($this->gateConfig), $this->gateConfig, null, null);
        }
        return $this->policy;
    }

    /** The plugin store, so the policy knows the salesperson numbers' owners. Registry off: nothing. @param mixed $store */
    public function useStaffStore($store): void
    {
        if ($this->registry === null) return;
        $policy = $this->automationPolicy();
        $policy->useStore($store);
        // 5.18.90 (docs/65 §AD.9): a gap only the store shows — a re-pair the guard recorded — is said now; once a process.
        $policy->warnIfIncomplete();
    }

    /** Why the assistant may not answer on this channel: '' | ai_disabled | internal_numbers_incomplete. */
    public function aiRefusal(string $channel): string
    {
        if ($this->registry === null) return '';
        $ctx = $this->registry['contexts'][$channel] ?? null;
        if ($ctx === null) return '';
        if (!$ctx->aiEnabled()) return 'ai_disabled';
        if (!$ctx->isDepartment() && !$this->automationPolicy()->numbersComplete()) return 'internal_numbers_incomplete';
        return '';
    }

    /** Was this send result a policy refusal (nothing left)? */
    public static function policyRefused($result): bool
    {
        return is_array($result) && !empty($result['policy_refused']);
    }

    /** The policy's reason for a refusal, '' for any other result. */
    public static function policyReason($result): string
    {
        return self::policyRefused($result) ? (string)($result['policy_reason'] ?? '') : '';
    }

    /** @return array|null the refusal to return instead of sending, or null to go on */
    private function policyRefusal(string $channel, string $phone, string $class): ?array
    {
        if ($this->registry === null || !in_array($class, self::AUTOMATED_CLASSES, true)) return null;
        $why = $this->automationPolicy()->refusal($channel, $phone);
        if ($why === '') return null;
        error_log('[evo] automated send refused on channel ' . $channel . ': ' . $why . ' — nothing sent, from any number');
        $this->lastError = ['message' => self::POLICY_REFUSED . $why, 'http' => 0];
        return ['ok' => false, 'http' => 0, 'data' => [], 'error' => self::POLICY_REFUSED . $why,
                'policy_refused' => true, 'policy_reason' => $why];
    }

    /** Is this service routing through the channel registry? */
    public function registryOn(): bool
    {
        return $this->registry !== null;
    }

    /** The registry's view of a channel, or null (registry off, or no such channel). */
    public function channelContext(string $channel): ?ChannelContext
    {
        return $this->registry['contexts'][$channel] ?? null;
    }

    /** 'mapped' — routes to a channel; 'refused' — the registry knows it and has switched it off; 'unknown'. */
    public function instanceState(string $instance): string
    {
        $key = mb_strtolower(trim($instance));
        if (isset($this->instanceToChannel[$key])) return 'mapped';
        if (isset($this->registry['refused'][$key])) return 'refused';
        return 'unknown';
    }

    /** May the assistant answer on this channel? Always, unless the registry says its AI is off (or, 5.18.90, cannot be safe). */
    public function channelAllowsAi(string $channel): bool
    {
        // 5.18.90 (docs/65 §AD): nor on a salesperson's number while a department number is not recorded.
        return $this->aiRefusal($channel) === '';
    }

    /**
     * May a reply to a message that arrived on $inboundInstance leave on $channel NOW? (docs/65 §I)
     *
     * Only meaningful with the registry on. The channel must be known and active, must resolve to an instance, and
     * that instance must be the one the message came in on: if the channel was moved to another number in between, a
     * reply from the new one would reach the customer from a number they never wrote to. Nothing is sent on a refusal.
     *
     * @return array{ok:bool, reason:string, context:?ChannelContext}
     *   reason: '' | unknown_channel | channel_<status> | ai_disabled | no_instance | no_inbound_instance | instance_mismatch
     *           | internal_numbers_incomplete (5.18.90)
     */
    public function replyRoute(string $channel, string $inboundInstance): array
    {
        $ctx = $this->channelContext($channel);
        if ($ctx === null)       return ['ok' => false, 'reason' => 'unknown_channel', 'context' => null];
        if (!$ctx->isActive())   return ['ok' => false, 'reason' => 'channel_' . $ctx->status(), 'context' => $ctx];
        if (!$ctx->aiEnabled())  return ['ok' => false, 'reason' => 'ai_disabled', 'context' => $ctx];
        $now = $this->instanceFor($channel);
        if ($now === '')         return ['ok' => false, 'reason' => 'no_instance', 'context' => $ctx];
        $in = trim($inboundInstance);
        if ($in === '')          return ['ok' => false, 'reason' => 'no_inbound_instance', 'context' => $ctx];
        if (strcasecmp($in, $now) !== 0) return ['ok' => false, 'reason' => 'instance_mismatch', 'context' => $ctx];
        // 5.18.90 (docs/65 §AD): last, so every other reason keeps its precedence — a salesperson's number cannot yet tell a
        // department's message from a customer's, so it does not answer (silently: the team has the message).
        if (!$ctx->isDepartment() && !$this->automationPolicy()->numbersComplete()) {
            return ['ok' => false, 'reason' => 'internal_numbers_incomplete', 'context' => $ctx];
        }
        return ['ok' => true, 'reason' => '', 'context' => $ctx];
    }

    /** @var array<string,bool> */
    private static array $said = [];

    private static function sayOnce(string $line): void
    {
        if (isset(self::$said[$line])) return;
        self::$said[$line] = true;
        error_log($line);
    }

    /**
     * Trim a pasted Evolution URL back to its API root.
     *
     * Evolution's own welcome page advertises the manager URL, so /manager is
     * the natural thing to copy -- and it fails in a way that hides itself: the
     * manager is a single-page app, so GET /manager/instance/fetchInstances
     * returns the app's HTML with a 200, which reads as "connected, no
     * instances", while every POST 404s. That combination cost a long
     * afternoon, so the suffix is stripped here rather than diagnosed again.
     */
    public static function normaliseBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') return '';

        // Strip UI paths that are not the API root.
        foreach (['/manager', '/dashboard'] as $suffix) {
            if (substr(strtolower($url), -strlen($suffix)) === $suffix) {
                $url = substr($url, 0, -strlen($suffix));
            }
        }
        return rtrim($url, '/');
    }

    // ── Configuration ────────────────────────────────────────────────────────

    /**
     * Enough to call Evolution at all: a URL and a key.
     *
     * Deliberately separate from isConfigured(). Listing and creating instances
     * must work BEFORE any channel is mapped -- otherwise you would need an
     * instance assigned in order to see the list you assign instances from.
     */
    public function canReachApi(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /** Fully set up: reachable AND at least one number mapped to an instance. */
    public function isConfigured(): bool
    {
        return $this->canReachApi() && $this->channelToInstance !== [];
    }

    /** Which instance serves this channel? Empty string when unmapped. */
    public function instanceFor(string $channel): string
    {
        return $this->channelToInstance[$channel] ?? '';
    }

    /**
     * Which channel does this instance belong to?
     * Returns '' for an instance we do not know — the webhook MUST reject those
     * rather than guessing, or one number's traffic lands in another's context.
     */
    public function channelFor(string $instance): string
    {
        return $this->instanceToChannel[mb_strtolower(trim($instance))] ?? '';
    }

    public function configuredChannels(): array
    {
        return array_keys($this->channelToInstance);
    }

    /** Safe for logs and admin screens — never includes the key. */
    public function describe(): array
    {
        return [
            'base_url'   => $this->baseUrl,
            'key_set'    => $this->apiKey !== '',
            'key_length' => strlen($this->apiKey),   // a length is not a secret
            'channels'   => $this->channelToInstance,
        ];
    }

    public function getLastError(): array { return $this->lastError; }

    // ── Instances ────────────────────────────────────────────────────────────

    public function fetchInstances(): array
    {
        return $this->request('GET', '/instance/fetchInstances');
    }

    /**
     * Instances as a flat list: name, connection state, phone, profile name.
     *
     * Evolution nests these differently across builds -- sometimes under an
     * "instance" key, sometimes flat -- and names the phone ownerJid, number or
     * owner depending on version. This normalises all of it so the caller can
     * just show the operator what is connected.
     *
     * @return array<int,array{name:string,state:string,connected:bool,phone:string,profile:string}>
     */
    public function listInstances(): array
    {
        $r = $this->fetchInstances();
        if (empty($r['ok']) || !is_array($r['data'])) return [];

        $out = [];
        foreach ($r['data'] as $row) {
            if (!is_array($row)) continue;
            $i = isset($row['instance']) && is_array($row['instance']) ? $row['instance'] : $row;

            $name = (string)($i['name'] ?? ($i['instanceName'] ?? ''));
            if ($name === '') continue;

            $state = (string)($i['connectionStatus'] ?? ($i['state'] ?? ($i['status'] ?? 'unknown')));
            $jid   = (string)($i['ownerJid'] ?? ($i['owner'] ?? ($i['number'] ?? '')));

            $out[] = [
                'name'      => $name,
                'state'     => $state,
                // Evolution says 'open' on some builds, 'connected' on others.
                'connected' => in_array(strtolower($state), ['open', 'connected'], true),
                'phone'     => self::phoneFromJid($jid) ?: self::normalisePhone($jid),
                'profile'   => (string)($i['profileName'] ?? ($i['profileStatus'] ?? '')),
                // 5.18.90 (docs/65 §AD): the number only when the owner is a phone JID — '' for an @lid owner, whose
                // digits are not a phone number. InternalNumbers::recordReported reads this, never 'phone'.
                'jid_phone' => self::phoneFromJid($jid),
            ];
        }
        usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        return $out;
    }

    /** 'open' = connected, 'connecting', 'close', or null when unreachable. */
    public function connectionState(string $instance): ?string
    {
        $r = $this->request('GET', '/instance/connectionState/' . rawurlencode($instance));
        if (!$r['ok']) return null;
        $d = $r['data'];
        return $d['instance']['state'] ?? ($d['state'] ?? null);
    }

    /** Connection state for every configured channel. For the admin screen. */
    public function channelHealth(): array
    {
        $out = [];
        foreach ($this->channelToInstance as $channel => $instance) {
            $state = $this->connectionState($instance);
            $out[$channel] = [
                'instance'  => $instance,
                'state'     => $state ?? 'unreachable',
                'connected' => $state === 'open',
            ];
        }
        return $out;
    }

    /**
     * Create an instance. Evolution generates a QR straight away.
     *
     * integration WHATSAPP-BAILEYS is the QR-pairing mode (as opposed to the
     * Meta Cloud API mode), which is what a normal WhatsApp number uses.
     */
    public function createInstance(string $name): array
    {
        return $this->request('POST', '/instance/create', [
            'instanceName' => $name,
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
        ]);
    }

    /**
     * Ask for a pairing QR.
     *
     * Returns ['qr' => data-uri or '', 'pairing_code' => string]. Evolution
     * rotates the code every few seconds and gives up after a limited number
     * of attempts, so treat what comes back as valid for about a minute.
     *
     * An already-connected instance returns no QR — check connectionState
     * first if you need to distinguish that from a failure.
     */
    /**
     * Reconnect an instance, and get a pairing code when a number is given.
     *
     * A Baileys session drops on its own — dishnet_richard went from open to
     * close inside seven minutes — and the QR route needs somebody in front of
     * the Evolution manager with the handset. Evolution issues a pairing code
     * instead when the number is passed, which can be read down a phone line
     * and typed into WhatsApp > Linked devices > Link with phone number.
     */
    public function connect(string $instance, string $number = ''): array
    {
        $path = '/instance/connect/' . rawurlencode($instance);
        $number = preg_replace('/\D+/', '', $number);
        if ($number !== '') $path .= '?number=' . rawurlencode($number);

        $r = $this->request('GET', $path);
        if (!$r['ok']) return $r;

        $d  = $r['data'];
        $qr = (string)($d['base64'] ?? ($d['qrcode']['base64'] ?? ''));
        // Some builds return raw base64, others a full data URI.
        if ($qr !== '' && strpos($qr, 'data:') !== 0) {
            $qr = 'data:image/png;base64,' . $qr;
        }

        $r['qr']           = $qr;
        $r['pairing_code'] = (string)($d['pairingCode'] ?? ($d['code'] ?? ''));
        return $r;
    }

    /** Sign a number out of WhatsApp without deleting the instance. */
    public function logoutInstance(string $instance): array
    {
        return $this->request('DELETE', '/instance/logout/' . rawurlencode($instance));
    }

    // ── Webhook management ───────────────────────────────────────────────────

    /**
     * Point an instance's webhook at us.
     *
     * $url should already carry the shared secret, because Evolution v2 does
     * not sign webhook payloads — the secret in the URL is the authentication.
     * See EvoWebhookGuard.
     */
    /**
     * What Evolution actually has registered for this instance. The local
     * token existing proves nothing -- on 25 Aug a preflight passed while
     * Evolution had no webhook at all, because registration had failed after
     * the secret file was created. Only Evolution can answer this.
     */
    public function getWebhook(string $instance): array
    {
        return $this->request('GET', '/webhook/find/' . rawurlencode($instance));
    }

    public function setWebhook(string $instance, string $url, array $events = []): array
    {
        if (!$events) {
            $events = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE'];
        }
        return $this->request('POST', '/webhook/set/' . rawurlencode($instance), [
            'webhook' => [
                'enabled'  => true,
                'url'      => $url,
                'byEvents' => false,
                'base64'   => false,
                'events'   => $events,
            ],
        ]);
    }

    public function findWebhook(string $instance): array
    {
        return $this->request('GET', '/webhook/find/' . rawurlencode($instance));
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /**
     * Send text on a business channel.
     *
     * Channel-addressed rather than instance-addressed on purpose: callers
     * should say "reply on the account number", not name an instance.
     */
    /**
     * @param string $class ContactOptOut::CLASS_* — what kind of message this is.
     *   The default is the most restricted one on purpose: a send site added
     *   later that forgets to classify itself is treated as something we
     *   started, and an opt-out stops it. Forgetting to label marketing as
     *   marketing should cost us a send, not cost a customer their choice.
     */
    public function sendText(string $channel, string $phone, string $text,
                             string $class = ContactOptOut::CLASS_PROACTIVE): array
    {
        $refusal = $this->optOutRefusal($phone, $channel, $class);
        if ($refusal !== null) return $refusal;
        $refusal = $this->policyRefusal($channel, $phone, $class);   // 5.18.90 (docs/65 §AD)
        if ($refusal !== null) return $refusal;

        $instance = $this->requireInstance($channel);
        if ($instance === '') {
            return $this->fail("No Evolution instance configured for channel '{$channel}'");
        }
        return $this->request('POST', '/message/sendText/' . rawurlencode($instance), [
            'number' => self::normalisePhone($phone),
            'text'   => $text,
        ]);
    }

    /**
     * Send media. $media is a public URL or base64 payload.
     * $mediaType: image | video | document | audio
     */
    public function sendMedia(
        string $channel,
        string $phone,
        string $mediaType,
        string $media,
        string $caption = '',
        string $fileName = '',
        string $class = ContactOptOut::CLASS_PROACTIVE
    ): array {
        $refusal = $this->optOutRefusal($phone, $channel, $class);
        if ($refusal !== null) return $refusal;
        $refusal = $this->policyRefusal($channel, $phone, $class);   // 5.18.90 (docs/65 §AD)
        if ($refusal !== null) return $refusal;

        $instance = $this->requireInstance($channel);
        if ($instance === '') {
            return $this->fail("No Evolution instance configured for channel '{$channel}'");
        }
        $body = [
            'number'    => self::normalisePhone($phone),
            'mediatype' => $mediaType,
            'media'     => $media,
        ];
        if ($caption  !== '') $body['caption']  = $caption;
        if ($fileName !== '') $body['fileName'] = $fileName;

        return $this->request('POST', '/message/sendMedia/' . rawurlencode($instance), $body);
    }

    public function sendImage(string $channel, string $phone, string $media, string $caption = ''): array
    {
        return $this->sendMedia($channel, $phone, 'image', $media, $caption);
    }

    /** Invoices and quotations go out this way. */
    public function sendDocument(string $channel, string $phone, string $media, string $fileName,
                                string $caption = '',
                                string $class = ContactOptOut::CLASS_PROACTIVE): array
    {
        return $this->sendMedia($channel, $phone, 'document', $media, $caption, $fileName, $class);
    }

    public function markAsRead(string $channel, string $remoteJid, string $messageId, bool $fromMe = false): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/markMessageAsRead/' . rawurlencode($instance), [
            'readMessages' => [[
                'remoteJid' => $remoteJid,
                'fromMe'    => $fromMe,
                'id'        => $messageId,
            ]],
        ]);
    }

    /**
     * The bytes of a media message a customer sent, from Evolution (v2: POST /chat/getBase64FromMediaMessage/{instance}).
     *
     * Batch 1 of the AI communication layer (docs/55 §9). The webhook stays registered with base64 OFF — a payload
     * carrying the file would meet its 512 KB body cap — so the media worker asks for the file afterwards, by the
     * message key the webhook recorded. The answer carries `base64`, `mimetype`, `fileName` and `size`; MediaFetcher
     * validates it. convertToMp4 is always false: a voice note is wanted as sent. The timeout is the constructor's.
     *
     * @param array $key remoteJid, id, fromMe — the message key, exactly as Evolution delivered it
     */
    public function getBase64FromMediaMessage(string $channel, array $key): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/getBase64FromMediaMessage/' . rawurlencode($instance), [
            'message' => ['key' => [
                'remoteJid' => (string)($key['remoteJid'] ?? ''),
                'fromMe'    => !empty($key['fromMe']),
                'id'        => (string)($key['id'] ?? ''),
            ]],
            'convertToMp4' => false,
        ]);
    }

    /**
     * Typing indicator. Worth sending before an AI reply — the model takes a
     * few seconds and silence reads as being ignored.
     */
    public function sendTyping(string $channel, string $phone, int $durationMs = 3000): array
    {
        // 5.18.90 (docs/65 §AD): the indicator is the assistant's, and goes only where its reply could.
        $refusal = $this->policyRefusal($channel, $phone, self::AUTOMATED_CLASSES[0]);
        if ($refusal !== null) return $refusal;
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/sendPresence/' . rawurlencode($instance), [
            'number'   => self::normalisePhone($phone),
            'presence' => 'composing',
            'delay'    => $durationMs,
        ]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function findMessages(string $channel, string $remoteJid, int $pageSize = 50, int $page = 1): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/findMessages/' . rawurlencode($instance), [
            'where'  => ['key' => ['remoteJid' => $remoteJid]],
            'page'   => $page,
            'offset' => $pageSize,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Digits only. Evolution rejects '+' and spaces on the number field. */
    public static function normalisePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /** '211912345678@s.whatsapp.net' -> '211912345678'. Returns '' for @lid. */
    public static function phoneFromJid(string $jid): string
    {
        if ($jid === '' || strpos($jid, '@lid') !== false) return '';
        $left = explode('@', $jid)[0];
        return self::normalisePhone($left);
    }

    private function requireInstance(string $channel): string
    {
        return $this->instanceFor($channel);
    }

    private function fail(string $message): array
    {
        $this->lastError = ['message' => $message, 'http' => 0];
        return ['ok' => false, 'http' => 0, 'data' => [], 'error' => $message];
    }

    /**
     * 5.18.54 (docs/46 rows 30, 31): whether a failed send may nonetheless have reached the customer, read from the
     * error this class wrote. It may have if the request left and no answer came back, or if a gateway in front of
     * Evolution timed out or broke off (502, 504). Such a send is never repeated automatically; a person decides.
     *
     * @param array|string $result a send's result, or its error text
     */
    public static function mayHaveBeenSent($result): bool
    {
        $e = is_array($result) ? (string)($result['error'] ?? '') : (string)$result;
        if (strpos($e, self::MAYBE_SENT) === 0) return true;
        return (bool)preg_match('/\[HTTP 50[24] on (POST|PUT|PATCH|DELETE) /', $e);
    }

    /** 5.18.54 (docs/46 row 31): Uganda only, asked once per instance. */
    private function noResend(): bool
    {
        if ($this->noResendUg === null) {
            try {
                require_once __DIR__ . '/NotifyGate.php';
                $dd = $this->gateConfig['_data_dir'] ?? ($GLOBALS['dataDir'] ?? null);
                $this->noResendUg = NotifyGate::applies(NotifyGate::EVO_RETRY, $this->gateConfig,
                                                        is_string($dd) && $dd !== '' ? $dd : null);
            } catch (\Throwable $e) {
                $this->noResendUg = false;
            }
        }
        return $this->noResendUg;
    }

    /**
     * 5.18.54 (docs/46 row 31, N-1), Uganda: request(), keeping the promise its comment makes.
     *
     * curl reports every transport failure the same way; what separates them is whether the request had left.
     * - Until curl is about to send it (the connection and any TLS handshake done: pre-transfer time 0, nothing
     *   uploaded), nothing can have reached Evolution, and trying again is safe.
     * - After that, Evolution may have taken the message and only its answer was lost, so a second POST could send it
     *   twice. The POST fails at once, and its error says it may have been sent.
     * - Reads are retried as before. A read's retry keeps its own body: the branch below overwrites it with the
     *   response, and a read that met a 500 with a plain-text body then died of a TypeError.
     * - An HTML answer now names its status, so that a 502 or 504 from a gateway can be told from a refusal.
     */
    private function requestUg(string $method, string $path, ?array $body): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return $this->fail('Evolution API is not configured');
        }
        $read = $method === 'GET';
        for ($attempt = 1; ; $attempt++) {
            $ch = curl_init($this->baseUrl . $path);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['apikey: ' . $this->apiKey, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_POSTREDIR      => 7,   // keep POST across a redirect
            ];
            if (!$read) {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                $opts[CURLOPT_POSTFIELDS]    = json_encode($body ?? []);
            }
            curl_setopt_array($ch, $opts);

            $raw       = curl_exec($ch);
            $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $unsent    = (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) == 0.0
                      && (float)curl_getinfo($ch, CURLINFO_SIZE_UPLOAD) == 0.0;
            curl_close($ch);

            if ($raw === false) {
                if (($read || $unsent) && $attempt < 3) {
                    usleep(200000 * $attempt);
                    continue;
                }
                if ($read) return $this->fail('Connection failed: ' . $curlError);
                return $this->fail($unsent ? self::NOT_SENT . 'no connection to Evolution: ' . $curlError
                                           : self::MAYBE_SENT . 'no answer from Evolution: ' . $curlError);
            }

            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                $text = ltrim((string)$raw);
                if ($text !== '' && ($text[0] === '<' || stripos($text, '<!doctype') === 0)) {
                    $msg = 'Got an HTML page, not the API. Check the URL is the API root '
                         . '(no /manager on the end).';
                    $detail = $msg . ' [HTTP ' . $httpCode . ' on ' . $method . ' ' . $path . ']';
                    $this->lastError = ['message' => $msg, 'http' => $httpCode, 'path' => $path, 'detail' => $detail];
                    return ['ok' => false, 'http' => $httpCode, 'data' => [], 'error' => $detail];
                }
                $data = ['raw' => mb_substr((string)$raw, 0, 500)];
            }

            if ($httpCode >= 500 && $read && $attempt < 3) {
                usleep(200000 * $attempt);
                continue;
            }

            if ($httpCode >= 400) {
                $msg = $data['message'] ?? ($data['error'] ?? ('HTTP ' . $httpCode));
                if (is_array($msg)) $msg = implode('; ', array_map('strval', $msg));
                $msg = (string)$msg;
                $detail = $msg . ' [HTTP ' . $httpCode . ' on ' . $method . ' ' . $path . ']';
                $this->lastError = ['message' => $msg, 'http' => $httpCode,
                                    'path' => $path, 'method' => $method, 'detail' => $detail];
                return ['ok' => false, 'http' => $httpCode, 'data' => $data, 'error' => $detail];
            }

            $this->lastError = [];
            return ['ok' => true, 'http' => $httpCode, 'data' => $data, 'error' => ''];
        }
    }

    /**
     * One HTTP call.
     *
     * Retries idempotent reads and connection-level failures. A POST that
     * actually reached Evolution is never retried — a duplicate WhatsApp
     * message to a customer is worse than a failed send we can report.
     */
    private function request(string $method, string $path, array $body = null, int $attempt = 1): array
    {
        if ($this->noResend()) return $this->requestUg($method, $path, $body);   // 5.18.54, docs/46 row 31
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return $this->fail('Evolution API is not configured');
        }

        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['apikey: ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 7,   // keep POST across a redirect
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS]    = json_encode($body ?? []);
        }
        curl_setopt_array($ch, $opts);

        $raw       = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Connection never completed — safe to retry regardless of method.
        if ($raw === false) {
            if ($attempt < 3) {
                usleep(200000 * $attempt);
                return $this->request($method, $path, $body, $attempt + 1);
            }
            return $this->fail('Connection failed: ' . $curlError);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            // A 200 carrying HTML means we reached a web page, not the API --
            // typically the manager UI. Treat it as a failure, not as an empty
            // result, so it cannot masquerade as a working connection.
            $body = ltrim((string)$raw);
            if ($body !== '' && ($body[0] === '<' || stripos($body, '<!doctype') === 0)) {
                $msg = 'Got an HTML page, not the API. Check the URL is the API root '
                     . '(no /manager on the end).';
                $this->lastError = ['message' => $msg, 'http' => $httpCode, 'path' => $path, 'detail' => $msg];
                return ['ok' => false, 'http' => $httpCode, 'data' => [], 'error' => $msg];
            }
            $data = ['raw' => mb_substr((string)$raw, 0, 500)];
        }

        if ($httpCode >= 500 && $method === 'GET' && $attempt < 3) {
            usleep(200000 * $attempt);
            return $this->request($method, $path, $body, $attempt + 1);
        }

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? ($data['error'] ?? ('HTTP ' . $httpCode));
            if (is_array($msg)) $msg = implode('; ', array_map('strval', $msg));
            $msg = (string)$msg;

            // "Not Found" on its own is undiagnosable. Say which call failed
            // and with what status, so a routing problem can be told apart
            // from a genuine rejection.
            $detail = $msg . ' [HTTP ' . $httpCode . ' on ' . $method . ' ' . $path . ']';

            $this->lastError = ['message' => $msg, 'http' => $httpCode,
                                'path' => $path, 'method' => $method, 'detail' => $detail];
            return ['ok' => false, 'http' => $httpCode, 'data' => $data, 'error' => $detail];
        }

        $this->lastError = [];
        return ['ok' => true, 'http' => $httpCode, 'data' => $data, 'error' => ''];
    }
}
