<?php
declare(strict_types=1);

require_once __DIR__ . '/LeadMatcher.php';

/**
 * AiLeadService — turn a qualified conversation into a sales record.
 *
 * WhatsApp stays where the team works. This exists so a real opportunity is
 * also written somewhere structured, instead of living only in a thread
 * somebody has to remember to scroll back through.
 *
 * TWO GATES, and the second one is the important half.
 *
 * The model decides there is an opportunity and emits <<LEAD ...>>. That alone
 * never writes. This service refuses unless a deterministic floor is met: a
 * stated REQUIREMENT, plus at least one of location, customer type, or a
 * request for a quotation. A model asked "should this be a lead?" says yes far
 * too often — "How much is Starlink?" would become a pipeline entry, and a
 * pipeline full of people who asked one question is a pipeline nobody reads.
 *
 * NOTHING IS INVENTED. Every field the conversation did not establish stays
 * null. That is enforced here rather than requested in the prompt, because a
 * prompt is a request and this is the record a salesperson will act on.
 *
 * NEVER BLOCKED ON AN AGENT. This install has no sales agents configured; a
 * lead is created unassigned rather than not created, and the existing
 * round-robin picks it up when agents exist.
 *
 * NEVER COSTS A REPLY. Called from the worker's never-throw zone, after the
 * customer already has their answer.
 */
class AiLeadService
{
    /** Fields the AI may set. Anything else it emits is ignored. */
    private const FIELDS = [
        'customer_name', 'company', 'location', 'customer_type', 'requirement',
        'users_devices', 'existing_internet', 'recommended_solution',
        'recommended_plan', 'recommended_hardware', 'public_ip_required',
        'cctv_remote_access', 'quote_requested', 'ai_summary',
    ];

    private $store;
    private array $config;
    private ?\PDO $pdo;

    public function __construct($store, array $config = [], ?\PDO $pdo = null)
    {
        $this->store  = $store;
        $this->config = $config;
        $this->pdo    = $pdo;
    }

    /** Is lead capture switched on at all? Absent means no. */
    public function enabled(): bool
    {
        return filter_var($this->config['ai_lead_capture'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Gate 2. Enough established fact to be worth a salesperson's time?
     *
     * @return string '' when it qualifies, otherwise why it does not
     */
    public function whyNotQualified(array $f): string
    {
        $has = fn(string $k) => trim((string)($f[$k] ?? '')) !== '';
        if (!$has('requirement')) {
            return 'no requirement stated — a question about price or coverage is not an opportunity';
        }
        if (!$has('location') && !$has('customer_type')
            && !filter_var($f['quote_requested'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return 'a requirement but nothing to act on — no location, no customer type, no quote asked for';
        }
        return '';
    }

    /**
     * Create or update the lead for this conversation.
     *
     * @return array{ok:bool, action:string, lead_id:int|null, reason:string}
     */
    public function capture(array $fields, string $phone, int $convId, string $source = 'whatsapp_ai'): array
    {
        if (!$this->enabled())  return $this->no('disabled', 'ai_lead_capture is off');
        if (LeadMatcher::key($phone) === '') return $this->no('skipped', 'no usable phone number');

        $clean = $this->clean($fields);

        // The floor gates CREATION, not enrichment. Once a lead exists the
        // opportunity is established, and a later message adding "they already
        // have MTN fibre" must not be thrown away because that one message did
        // not restate the requirement. Gating updates too meant a lead could
        // never learn anything after the message that created it.
        $existing = $this->existing($phone, $convId);
        if ($existing === null) {
            $why = $this->whyNotQualified($clean);
            if ($why !== '') return $this->no('skipped', $why);
        } elseif (!$this->hasAnything($clean)) {
            return $this->no('skipped', 'nothing new to record on lead #' . $existing);
        }

        $result = ['ok' => false, 'action' => 'skipped', 'lead_id' => null, 'reason' => ''];

        // withLock so two messages arriving together cannot both decide the
        // lead does not exist and both create it.
        $this->store->withLock('leads.json', function (array $leads) use (
            $clean, $phone, $convId, $source, &$result
        ): array {
            // A conversation already linked to a lead IS that lead, whatever
            // the phone matcher would say.
            $idx = $this->linkedIndex($leads, $convId);
            if ($idx === null) $idx = LeadMatcher::findIndex($leads, $phone);

            $now = date('Y-m-d H:i:s');
            if ($idx !== null) {
                $leads[$idx] = $this->merge($leads[$idx], $clean, $convId, $now);
                $result = ['ok' => true, 'action' => 'updated',
                           'lead_id' => (int)$leads[$idx]['id'], 'reason' => ''];
                return ['records' => $leads, 'result' => true];
            }

            $lead = $this->newLead($clean, $phone, $convId, $source, $now);
            $lead['id'] = $this->nextId($leads);
            $leads[]    = $lead;
            $result = ['ok' => true, 'action' => 'created',
                       'lead_id' => (int)$lead['id'], 'reason' => ''];
            return ['records' => $leads, 'result' => true];
        });

        if ($result['ok'] && $result['lead_id']) {
            $this->linkConversation($convId, (int)$result['lead_id']);
            $this->audit($result['action'], $phone, $convId, (int)$result['lead_id'], $clean);
        }
        return $result;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Keep only known fields; blank and "unknown" both become null. */
    private function clean(array $in): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            $v = $in[$k] ?? null;
            if (is_string($v)) {
                $v = trim($v);
                // The model writes "unknown" when it means null. Storing the
                // word would put the string "unknown" in front of a salesperson
                // as though it were something the customer said.
                if ($v === '' || in_array(mb_strtolower($v), ['unknown', 'n/a', 'na', 'none', 'null'], true)) {
                    $v = null;
                }
            }
            $out[$k] = $v;
        }
        foreach (['public_ip_required', 'cctv_remote_access'] as $k) {
            $v = $in[$k] ?? null;
            $v = is_string($v) ? mb_strtolower(trim($v)) : $v;
            $out[$k] = in_array($v, ['yes', 'true', '1', true, 1], true) ? 'yes'
                     : (in_array($v, ['no', 'false', '0', false, 0], true) ? 'no' : null);
        }
        $out['quote_requested'] = filter_var($in['quote_requested'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $out;
    }

    private function newLead(array $f, string $phone, int $convId, string $source, string $now): array
    {
        return array_merge($f, [
            'phone'           => $phone,
            'customer_name'   => $f['customer_name'] ?? $phone,
            'service_type'    => 'starlink',
            'source'          => $source,
            'source_detail'   => 'Qualified by the assistant in conversation c' . $convId,
            'conversation_id' => $convId,
            'priority'        => $this->priority($f),
            'status'          => 'open',
            'ai_qualified'    => true,
            // No agent is invented. cron_leads.php assigns unassigned leads to
            // the lightest-loaded active agent when there is one.
            'retailer_id'     => 0,
            'assigned_to'     => null,
            'assigned_name'   => '',
            'notes'           => (string)($f['ai_summary'] ?? ''),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    /**
     * Fill gaps; never overwrite with null, never clobber a human.
     *
     * A salesperson who corrects a lead must not have it undone by the next
     * message the customer sends.
     */
    private function merge(array $lead, array $f, int $convId, string $now): array
    {
        foreach (self::FIELDS as $k) {
            if ($k === 'ai_summary' || $k === 'quote_requested') continue;
            $new = $f[$k] ?? null;
            if ($new === null || $new === '') continue;
            $old = $lead[$k] ?? null;
            if ($old === null || $old === '') $lead[$k] = $new;
        }
        if (!empty($f['quote_requested'])) $lead['quote_requested'] = true;

        // The summary is appended, not replaced: the story of the conversation
        // is worth more than its latest paragraph.
        $sum = trim((string)($f['ai_summary'] ?? ''));
        if ($sum !== '' && strpos((string)($lead['ai_summary'] ?? ''), $sum) === false) {
            $lead['ai_summary'] = trim(((string)($lead['ai_summary'] ?? '')) . "\n" . $sum);
            $lead['notes']      = $lead['ai_summary'];
        }
        $lead['conversation_id'] = $lead['conversation_id'] ?? $convId;
        $lead['ai_qualified']    = true;
        $lead['priority']        = $this->priority($f + $lead);
        $lead['updated_at']      = $now;
        return $lead;
    }

    /** Urgency from what was actually established, never from enthusiasm. */
    private function priority(array $f): string
    {
        if (!empty($f['quote_requested']))                    return 'high';
        if (($f['public_ip_required'] ?? '') === 'yes')        return 'high';
        if (trim((string)($f['customer_type'] ?? '')) !== ''
            && trim((string)($f['location'] ?? '')) !== '')    return 'medium';
        return 'low';
    }

    /** The id of the lead this conversation or phone already has, if any. */
    private function existing(string $phone, int $convId): ?int
    {
        $leads = $this->store->load('leads.json') ?? [];
        $idx = $this->linkedIndex($leads, $convId);
        if ($idx === null) $idx = LeadMatcher::findIndex($leads, $phone);
        return $idx === null ? null : (int)($leads[$idx]['id'] ?? 0);
    }

    /** Anything worth writing at all? An all-null emission is not an update. */
    private function hasAnything(array $f): bool
    {
        foreach (self::FIELDS as $k) {
            if ($k === 'quote_requested') continue;
            if (($f[$k] ?? null) !== null && $f[$k] !== '') return true;
        }
        return !empty($f['quote_requested']);
    }

    private function linkedIndex(array $leads, int $convId): ?int
    {
        if ($convId <= 0) return null;
        foreach ($leads as $i => $l) {
            if (is_array($l) && (int)($l['conversation_id'] ?? 0) === $convId) return (int)$i;
        }
        return null;
    }

    private function nextId(array $leads): int
    {
        $max = 0;
        foreach ($leads as $l) if (is_array($l)) $max = max($max, (int)($l['id'] ?? 0));
        return $max + 1;
    }

    private function linkConversation(int $convId, int $leadId): void
    {
        if ($convId <= 0 || $this->pdo === null) return;
        try {
            $this->pdo->prepare("UPDATE wa_conversations SET lead_id = ? WHERE id = ?")
                      ->execute([$leadId, $convId]);
        } catch (\Throwable $e) { /* the lead exists; the link is a convenience */ }
    }

    /** Every AI-made CRM change, with why. Debugging and accountability. */
    private function audit(string $action, string $phone, int $convId, int $leadId, array $f): void
    {
        try {
            $this->store->withLock('ai_crm_actions.json', function (array $rows) use (
                $action, $phone, $convId, $leadId, $f
            ): array {
                $rows[] = [
                    'at'              => gmdate('Y-m-d H:i:s'),
                    'action'          => 'lead_' . $action,
                    'phone'           => $phone,
                    'conversation_id' => $convId,
                    'lead_id'         => $leadId,
                    'requirement'     => $f['requirement'] ?? null,
                    'quote_requested' => !empty($f['quote_requested']),
                ];
                if (count($rows) > 2000) $rows = array_slice($rows, -2000);
                return ['records' => $rows, 'result' => true];
            });
        } catch (\Throwable $e) { /* an audit line must never cost the lead */ }
    }

    private function no(string $action, string $reason): array
    {
        return ['ok' => false, 'action' => $action, 'lead_id' => null, 'reason' => $reason];
    }
}
