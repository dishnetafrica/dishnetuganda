<?php
declare(strict_types=1);

require_once __DIR__ . '/WhatsAppChannel.php';

/**
 * PartnerOtpSender — delivers a distributor-portal sign-in code over the EXISTING
 * Evolution WhatsApp integration (WS-A P4, docs/53; operator decision: reuse
 * Evolution, no new SMS provider; instance = the existing SUPPORT number, D2).
 *
 * It is the small piece that fills PartnerApi's delivery seam (docs/51 §1): the
 * seam is null in the pilot (partner_api.php sends nothing), and a later,
 * SEPARATELY-APPROVED step wires this sender in. Nothing here makes the live
 * entry send — see §"no accidental send" below.
 *
 * It goes through the WhatsAppChannel PORT — the same abstraction the distributor
 * notifications use — bound to EvolutionWhatsAppChannel over the EXISTING
 * EvolutionApiService. It is NOT a second Evolution integration, and it does NOT
 * go through NotificationService (the customer/marketing/sales path): distributor
 * authentication is its own send path, its own message text, message class
 * CLASS_STAFF (so no opt-out suppresses it and it is never a marketing message),
 * and its own audit line (dist_partner_auth_log). Existing support messaging is
 * untouched — this only ADDS an outbound auth message on the support instance.
 *
 * Recipient verification (docs/53 §2, the operator's explicit requirement):
 *   - The destination is resolved SERVER-SIDE from the account alone, by user_id.
 *     It is NEVER read from the authentication request, and cannot be changed
 *     during sign-in: send() ignores any phone in the descriptor and re-derives.
 *   - A code goes ONLY to the account's OWN verified contact: a dist_contacts row
 *     for the account's partner, verified = 1, whose number equals the account's
 *     sign-in number. No verified match -> NO SEND (and the uniform anti-
 *     enumeration response is preserved by the caller, which answers {status:sent}
 *     whether or not a code was actually delivered).
 *
 * Accepted != delivered (docs/53 §3): Evolution returning HTTP 2xx is ACCEPTANCE,
 * not WhatsApp delivery; the webhook does not consume MESSAGES_UPDATE, so delivery
 * is not confirmable. The outcome is recorded as accepted / failed / unknown /
 * no_recipient, and NEVER as 'delivered' (the log table has no such value).
 *
 * No auto-resend on an uncertain outcome (docs/53 §3): send() calls the channel
 * EXACTLY ONCE and never loops; a send that may have gone is recorded 'unknown'
 * and left for the user to re-request within the rate cap. (The transport's own
 * no-resend posture — EvolutionApiService under the Uganda noResend gate — means
 * even the single underlying POST is not retried once it has left.)
 *
 * Never logged or displayed: the code, the TOTP secret, the Evolution apikey and
 * session tokens appear in no log, audit row or response (docs/53 §3). This class
 * writes only ids + an outcome + a short non-secret note.
 */
final class PartnerOtpSender
{
    public const OUTCOME_ACCEPTED     = 'accepted';      // Evolution accepted (HTTP 2xx) — NOT delivery
    public const OUTCOME_FAILED       = 'failed';        // a definite failure (provider error / request did not leave)
    public const OUTCOME_UNKNOWN      = 'unknown';       // may have been sent; NEVER auto-resend
    public const OUTCOME_NO_RECIPIENT = 'no_recipient';  // no verified number for this account — nothing sent
    // There is deliberately NO 'delivered' outcome. Acceptance is not delivery.

    private \PDO $pdo;
    private WhatsAppChannel $channel;

    public function __construct(\PDO $pdo, WhatsAppChannel $channel)
    {
        $this->pdo = $pdo;
        $this->channel = $channel;
    }

    /**
     * Build a sender bound to the EXISTING SUPPORT instance (D2). The credential
     * and instance come from the plugin's existing Evolution config
     * (evo_api_url / evo_api_key / evo_instance_support); no new secret, no new
     * instance. CLASS_STAFF is applied inside EvolutionWhatsAppChannel.
     */
    public static function fromConfig(\PDO $pdo, array $config): self
    {
        require_once __DIR__ . '/EvolutionApiService.php';
        $evo = new EvolutionApiService($config);
        return new self($pdo, new EvolutionWhatsAppChannel($evo, EvolutionApiService::CHANNEL_SUPPORT));
    }

    /** For logs/health. */
    public function channelName(): string { return $this->channel->name(); }

    /**
     * The verified destination for an account, resolved SERVER-SIDE from user_id.
     * Returns ['contact_id'=>int, 'phone'=>string] or null (=> no send).
     *
     * Rules (docs/53 §2; mirrors DistributorNotifier::recipientFor's fail-safe
     * discipline, tied to the account's own number):
     *   - the account must exist and be active, with a sign-in number;
     *   - the destination must be a dist_contacts row of the account's partner,
     *     verified = 1, whose digits equal the account's sign-in number;
     *   - anything else -> null. The destination is never the request's number.
     */
    public function resolveRecipient(int $userId): ?array
    {
        $u = $this->account($userId);
        if ($u === null || (string)$u['status'] !== 'active') return null;
        $acctDigits = self::digits((string)$u['phone']);
        if ($acctDigits === '') return null;

        $st = $this->pdo->prepare("SELECT id, phone FROM dist_contacts WHERE partner_id = ? AND verified = 1");
        $st->execute([(int)$u['partner_id']]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $c) {
            if (self::digits((string)$c['phone']) === $acctDigits) {
                return ['contact_id' => (int)$c['id'], 'phone' => (string)$c['phone']];
            }
        }
        return null;
    }

    /**
     * Deliver one login code. $delivery is the descriptor PartnerAuth::
     * requestLoginCode returns: ['user_id'=>int, 'phone'=>?, 'code'=>string,
     * 'ttl'=>int]. The 'phone' in the descriptor is IGNORED — the destination is
     * re-derived from user_id (never the request).
     *
     * Calls the channel at most once; records a non-secret outcome; never throws
     * out (the dispatcher's seam is fire-and-forget). Returns the outcome for the
     * caller/tests; the HTTP response to the guest stays uniform regardless.
     *
     * @return array{outcome:string, sent:bool, contact_id:int}
     */
    public function send(array $delivery): array
    {
        $userId = (int)($delivery['user_id'] ?? 0);
        $code   = (string)($delivery['code'] ?? '');
        $ttl    = (int)($delivery['ttl'] ?? 600);

        $rc = ($userId > 0 && $code !== '') ? $this->resolveRecipient($userId) : null;
        if ($rc === null) {
            // No verified recipient (or a malformed descriptor): send NOTHING.
            $this->record($userId, 0, null, self::OUTCOME_NO_RECIPIENT, 'no verified number for this account', '');
            return ['outcome' => self::OUTCOME_NO_RECIPIENT, 'sent' => false, 'contact_id' => 0];
        }

        $text = self::message($code, $ttl);
        try {
            $res = $this->channel->send((string)$rc['phone'], $text);   // exactly once; no loop
        } catch (\Throwable $e) {
            // A transport that threw may or may not have delivered — treat as uncertain, never resend.
            $this->record($userId, (int)$rc['contact_id'], (int)$this->partnerOf($userId), self::OUTCOME_UNKNOWN, 'transport error', '');
            return ['outcome' => self::OUTCOME_UNKNOWN, 'sent' => false, 'contact_id' => (int)$rc['contact_id']];
        }

        $sent   = !empty($res['sent']);
        $detail = (string)($res['detail'] ?? '');
        if ($sent) {
            $outcome = self::OUTCOME_ACCEPTED;                 // accepted by Evolution — NOT confirmed delivered
        } elseif (self::maybeSent($detail)) {
            $outcome = self::OUTCOME_UNKNOWN;                  // may have gone — never auto-resend
        } else {
            $outcome = self::OUTCOME_FAILED;
        }
        $this->record($userId, (int)$rc['contact_id'], (int)$this->partnerOf($userId), $outcome, self::note($outcome, $detail), '');
        return ['outcome' => $outcome, 'sent' => $sent, 'contact_id' => (int)$rc['contact_id']];
    }

    /** The auth message. Auth-specific; carries the code and nothing else sensitive; never marketing. */
    public static function message(string $code, int $ttl): string
    {
        $minutes = max(1, (int)ceil(max(60, $ttl) / 60));
        return 'DishNet distributor portal: your sign-in code is ' . $code . '. '
             . 'It expires in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '. '
             . 'Do not share it. If you did not request it, ignore this message.';
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function account(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT id, partner_id, status, phone FROM dist_partner_users WHERE id = ?");
        $st->execute([$userId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function partnerOf(int $userId): int
    {
        $u = $this->account($userId);
        return $u ? (int)$u['partner_id'] : 0;
    }

    private static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Whether a failed send MAY nonetheless have reached WhatsApp — the plugin's
     * own classifier (EvolutionApiService::mayHaveBeenSent), with a transport-
     * agnostic fallback so the port abstraction holds for a future adapter.
     */
    private static function maybeSent(string $detail): bool
    {
        if (class_exists('EvolutionApiService') && method_exists('EvolutionApiService', 'mayHaveBeenSent')) {
            return (bool)\EvolutionApiService::mayHaveBeenSent($detail);
        }
        return strpos($detail, 'May have been sent') !== false
            || (bool)preg_match('/\[HTTP 50[24] on (POST|PUT|PATCH|DELETE) /', $detail);
    }

    /** A short, NON-SECRET note — never the code/secret/key/token. */
    private static function note(string $outcome, string $detail): string
    {
        if ($outcome === self::OUTCOME_ACCEPTED) return 'accepted by evolution';
        // $detail is an HTTP status / connection error about the CALL, never the message body. Cap it anyway.
        return mb_substr(trim($detail), 0, 200);
    }

    /**
     * The non-secret authentication-plane record. Best-effort for a send: the
     * message may already have left, so a logging failure must not crash the
     * dispatcher — it is noted via error_log instead. (The admin TOTP reset
     * audits atomically inside its own transaction, in PartnerAccounts.)
     */
    private function record(int $userId, int $contactId, ?int $partnerId, string $outcome, string $detail, string $actor): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO dist_partner_auth_log (user_id, partner_id, contact_id, event, outcome, detail, actor, at)
                 VALUES (?,?,?, 'otp_send', ?,?,?,?)"
            )->execute([
                $userId,
                (int)($partnerId ?? 0),
                $contactId > 0 ? $contactId : null,
                $outcome,
                mb_substr($detail, 0, 200),
                mb_substr($actor, 0, 160),
                time(),
            ]);
        } catch (\Throwable $e) {
            error_log('[PartnerOtpSender] could not record OTP send outcome (' . $outcome . '): ' . $e->getMessage());
        }
    }
}
