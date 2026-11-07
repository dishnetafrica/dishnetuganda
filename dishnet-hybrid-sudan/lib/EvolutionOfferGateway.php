<?php
declare(strict_types=1);

require_once __DIR__ . '/OfferGateway.php';
require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/EvolutionApiService.php';
require_once __DIR__ . '/AutomationPolicy.php';
require_once __DIR__ . '/EvoWebhookGuard.php';
require_once __DIR__ . '/WaSendBudget.php';

/**
 * EvolutionOfferGateway — the Evolution driver of OfferGateway (5.18.104 P6, root docs/73 §H.4.11; O-D11, O-D18).
 *
 * It reuses EvolutionApiService::sendMedia/sendText, the path the AI's flyer takes (workers/AiReplyWorker.php), never
 * NotificationService::sendImage (a public URL, the Message Log, and a retry queue outside the ledger and the window).
 * The opt-out and the automated-send policy refuse inside sendText/sendMedia, before any POST; a POST that may have left
 * is never repeated (requestUg on Uganda). The echo of our own message is claimed in evo_webhook_seen at once, before
 * this returns, so the webhook drops it as ours; when the claim finds the id already there the echo came first: 'late'.
 *
 * Built over a PDO with anything that has load(file) for the policy's line owners — the sender's store, or
 * OfferAudience::reader() — and never a store of its own. The echo guard is built at the first send(): its constructor
 * runs CREATE TABLE IF NOT EXISTS, which the switch tool's query_only connection refuses. Its opt-out reader is the same
 * PDO, so the send's own refusal reads the book the audience read. Codes never carry transport text, which can name an
 * instance. PHP 7.4.
 */
final class EvolutionOfferGateway implements OfferGateway
{
    /** Evolution's client timeout for an offer send (O-D8). */
    const TIMEOUT_S = 15;

    /** @var \PDO */ private $pdo;
    /** @var array */ private $config;
    /** @var \EvolutionApiService */ private $evo;
    /** @var \EvoWebhookGuard|null */ private $guard = null;

    /** @param mixed $staffReader anything with load(file): the sender's store, or OfferAudience::reader() */
    public function __construct(\PDO $pdo, array $config, ?string $dataDir, $staffReader)
    {
        $this->pdo    = $pdo;
        $this->config = $config;
        $this->evo    = \EvolutionApiService::forStore($config, $pdo, $dataDir, self::TIMEOUT_S);
        $this->evo->setOptOut(new \ContactOptOut($pdo));
        if ($staffReader !== null) $this->evo->useStaffStore($staffReader);
    }

    public function name(): string
    {
        return 'evolution';
    }

    public function handsetFor(string $channel): string
    {
        $i = trim($this->evo->instanceFor($channel));
        return $i === '' ? '' : WaSendBudget::handset($i);
    }

    public function refusal(string $channel, string $phone): string
    {
        return $this->evo->automationPolicy()->refusal($channel, $phone);
    }

    public function transient(string $code): bool
    {
        return \AutomationPolicy::transient($code);
    }

    /** The service, for the audience's DishNet-number list (its policy's InternalNumbers with the registry on). */
    public function service(): \EvolutionApiService
    {
        return $this->evo;
    }

    public function send(OfferMessage $m): OfferSendResult
    {
        try {
            if (is_array($m->image) && (string)($m->image['payload'] ?? '') !== '') {
                $res = $this->evo->sendMedia($m->channel, $m->phone, 'image', (string)$m->image['payload'], $m->text, '', $m->class);
            } else {
                $res = $this->evo->sendText($m->channel, $m->phone, $m->text, $m->class);
            }
        } catch (\Throwable $e) {
            return new OfferSendResult(OfferSendResult::NOT_SENT, 'send_failed');
        }
        // At once: claim our own echo, before anything is classified, stored or settled (O-D18).
        $id = trim((string)($res['data']['key']['id'] ?? $res['key']['id'] ?? ''));
        $echo = 'none';
        if ($id !== '') {
            try {
                $echo = $this->guard()->claim($id, $this->evo->instanceFor($m->channel), $m->echoEvent) ? 'claimed' : 'late';
            } catch (\Throwable $e) {
                $echo = 'late';
            }
        }
        if (\EvolutionApiService::policyRefused($res)) {
            $why = \EvolutionApiService::policyReason($res);
            return new OfferSendResult(OfferSendResult::REFUSED, $why === '' ? 'policy' : $why, '', \AutomationPolicy::transient($why), $echo);
        }
        if (!empty($res['suppressed'])) return new OfferSendResult(OfferSendResult::OPTED_OUT, 'opted_out', '', false, $echo);
        $ok = (!isset($res['ok']) || !empty($res['ok'])) && empty($res['error']);
        if ($ok) {
            return $id !== '' ? new OfferSendResult(OfferSendResult::SENT, '', $id, false, $echo)
                              : new OfferSendResult(OfferSendResult::MAYBE_SENT, 'no_message_id', '', false, $echo);
        }
        if (\EvolutionApiService::mayHaveBeenSent($res)) {
            return new OfferSendResult(OfferSendResult::MAYBE_SENT, 'may_have_been_sent', $id, false, $echo);
        }
        return new OfferSendResult(OfferSendResult::NOT_SENT, self::codeOf($res), '', false, $echo);
    }

    /** A fixed code for a send that certainly did not go; never the error text. */
    private static function codeOf($res): string
    {
        $e = is_array($res) ? (string)($res['error'] ?? '') : '';
        $http = is_array($res) ? (int)($res['http'] ?? 0) : 0;
        if (strpos($e, 'No Evolution instance configured') === 0) return 'no_instance';
        if ($e === 'Evolution API is not configured') return 'not_configured';
        if (strpos($e, \EvolutionApiService::NOT_SENT) === 0 || strpos($e, 'Connection failed') === 0) return 'transport_not_sent';
        if ($http >= 400 && $http < 500) return 'http_4xx';
        return 'send_failed';
    }

    private function guard(): \EvoWebhookGuard
    {
        if ($this->guard === null) $this->guard = new \EvoWebhookGuard($this->pdo, $this->config);
        return $this->guard;
    }
}
