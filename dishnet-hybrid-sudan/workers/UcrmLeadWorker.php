<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';

/**
 * UcrmLeadWorker — carry AI-qualified leads into uCRM, off the reply path.
 *
 * On the queue rather than inline in AiReplyWorker for one reason: a customer
 * must never wait on uCRM. An inline write puts a CRM round trip between the
 * question and the answer, and a uCRM that is slow, restarting or briefly down
 * becomes a WhatsApp conversation that stalls — or, worse, a lead that is lost
 * because the reply path moved on.
 *
 * On the queue it gets what the queue already gives every other worker:
 * retries with backoff, a dead-letter after five attempts, and a record of
 * what happened. UcrmLeadSync is idempotent precisely because this will retry.
 */
final class UcrmLeadWorker extends WorkerBase
{
    protected function getEventTypes(): array
    {
        return ['crm.lead.sync'];
    }

    protected function handle(array $event): void
    {
        $p      = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $leadId = (int)($p['lead_id'] ?? 0);
        if ($leadId <= 0) {
            $this->log('warn', 'crm.lead.sync with no lead_id — dropped');
            return;
        }

        $root = dirname(__DIR__);
        require_once $root . '/lib/CrmApiClient.php';
        require_once $root . '/lib/LeadMatcher.php';
        require_once $root . '/lib/UcrmLeadSync.php';

        $sync = new \UcrmLeadSync(
            $this->store,
            $this->config,
            \CrmApiClient::fromUcrm($root, $this->config),
            $this->pdo
        );

        $r = $sync->syncLead($leadId);

        // Every outcome is said out loud. The whole reason this phase exists is
        // that a lead used to stop at leads.json and nothing anywhere noticed.
        if (!empty($r['ok'])) {
            $this->log('info', sprintf('lead #%d %s as uCRM client #%d',
                $leadId, (string)$r['action'], (int)$r['crm_client_id']));
            return;
        }

        // 'disabled' and 'skipped' are decisions, not faults: the switch is
        // off, or the number is ambiguous and a person must look. Retrying
        // those forever would bury the queue in work that cannot succeed.
        if (in_array($r['action'], ['disabled', 'skipped'], true)) {
            $this->log('info', sprintf('lead #%d not synced — %s', $leadId, (string)$r['reason']));
            return;
        }

        // A failure IS retried: uCRM restarting is the normal case here.
        throw new \RuntimeException('lead #' . $leadId . ' sync failed: ' . (string)$r['reason']);
    }

    /**
     * Five attempts spent. Said loudly, because a lead nobody knows about is
     * the failure this phase was built to end.
     */
    protected function onDead(array $event, \Throwable $e): void
    {
        $p      = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $leadId = (int)($p['lead_id'] ?? 0);
        $this->log('error', sprintf(
            'lead #%d NEVER reached uCRM after five attempts — it is in leads.json only: %s',
            $leadId, $e->getMessage()));
    }
}
