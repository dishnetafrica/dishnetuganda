<?php
declare(strict_types=1);

require_once __DIR__ . '/ChannelRegistry.php';
require_once __DIR__ . '/InternalNumbers.php';

/**
 * AutomationPolicy — may anything AUTOMATED go out on this channel, to this number? (5.18.90, docs/65 §AD)
 *
 * The pre-pilot safety fix's one rule, used by every automated path that can reach a salesperson's number: the AI
 * worker (before the model is asked, and again at the send), the follow-up scan, run and send, the unanswered-chat
 * watchdog, and EvolutionApiService itself for every reply-class and proactive-class send. It has NO transport: it reads
 * the channel registry and nothing else, so a cron that must never reach Evolution (followup_run) can ask it.
 *
 * Dark: with the channel registry off (every install but Uganda, and Uganda until multi_number_channels_enabled) it
 * refuses nothing, and every path is exactly what it was. With it on, an automated message may leave only when
 *   - the channel is known and ACTIVE, and its assistant is ON (wa_channels.ai_enabled) — the switch now stops
 *     follow-ups too, not only replies;
 *   - for a salesperson's number: its number is verified — and Evolution does not now report another for its instance
 *     — it is still on the instance the routing was built with, and the department numbers are all recorded
 *     (InternalNumbers::gaps) — without them it could not tell a department's message from a customer's;
 *   - the channel resolves to an instance;
 *   - the recipient is not DishNet's own (InternalNumbers::senderClass).
 * Each refusal names its reason. Nothing ever falls back to another number.
 *
 * TRANSIENT reasons may clear by themselves (a paused number, a move, a verification still to be done, a registry that
 * could not be read): a follow-up waits, the AI worker hands the chat to a person. The others are final for that message
 * — a department with no instance at all among them, closed as the sender has closed it since 5.18.86.
 * The worker says nothing at all for SILENT ones: the team has the message, and the number is DishNet's own or the
 * assistant is off.
 *
 * Human sends are not automated and are never asked about here: an Inbox reply leaves on its conversation's number with
 * its assistant on or off, as before.
 *
 * PHP 7.4 compatible.
 */
final class AutomationPolicy
{
    const TRANSIENT = ['channel_paused', 'instance_changed', 'channel_unverified',
                       'internal_numbers_incomplete', 'registry_unreadable'];
    const SILENT    = ['internal_recipient', 'channel_assistant_disabled', 'internal_numbers_incomplete'];

    /** off: refuses nothing (registry off) · on: the registry was read · unreadable: on, but the read failed */
    private string $mode;
    /** @var array<string,ChannelContext> */
    private array $contexts;
    /** @var array<string,string> channel => instance, as the routing resolved it */
    private array $c2i;
    /** @var array<string,array> channel => wa_channels row, as read with the routing */
    private array $rows;
    private ?InternalNumbers $internal;
    private ?\PDO $pdo;

    private function __construct(string $mode, array $contexts = [], array $c2i = [], array $rows = [],
                                 ?InternalNumbers $internal = null, ?\PDO $pdo = null)
    {
        $this->mode     = $mode;
        $this->contexts = $contexts;
        $this->c2i      = $c2i;
        $this->rows     = $rows;
        $this->internal = $internal;
        $this->pdo      = $pdo;
    }

    /** The registry is off: nothing is refused, nothing is classified. */
    public static function inert(): self
    {
        return new self('off');
    }

    /**
     * For a path that has no EvolutionApiService (the follow-up scan and run, the watchdog). Off exactly when
     * EvolutionApiService::forStore would be off; a registry that cannot be read refuses every number but the three
     * departments, as OwnedNumberHold does.
     *
     * @param mixed $store the plugin store, for the owners' phones of record; null leaves them out
     */
    public static function forInstall(array $config, ?string $dataDir, ?\PDO $pdo, $store = null): self
    {
        if ($pdo === null || !\ChannelRegistry::enabled($config, $dataDir)) return self::inert();
        try {
            $reg = new \ChannelRegistry($pdo);
            if (!$reg->available()) return self::inert();
            if (!class_exists('EvolutionApiService')) require_once __DIR__ . '/EvolutionApiService.php';
            $cm = \EvolutionApiService::configInstanceMap($config);   // a static read of the configuration: no transport
            $p  = self::fromRouting($reg->routing($cm), $reg->rows(), $reg->verifiedInstances(), $cm, $config, $dataDir, $pdo);
            $p->useStore($store);
            $p->warnIfIncomplete();
            return $p;
        } catch (\Throwable $e) {
            error_log('[channels] the channel registry could not be read, so nothing automated leaves on any number but '
                    . 'the three departments: ' . $e->getMessage());
            return new self('unreadable');
        }
    }

    /**
     * From a routing already read (EvolutionApiService::forStore reads it once for both).
     *
     * @param array      $routing   ChannelRegistry::routing()
     * @param array|null $rows      ChannelRegistry::rows(); null when only the routing is known (a test's useRegistry):
     *                              then no salesperson number counts as verified
     * @param array      $verified  ChannelRegistry::verifiedInstances()
     * @param array      $configMap EvolutionApiService::configInstanceMap()
     */
    public static function fromRouting(array $routing, ?array $rows, array $verified, array $configMap, array $config,
                                       ?string $dataDir, ?\PDO $pdo): self
    {
        $contexts = (array)($routing['contexts'] ?? []);
        if ($rows === null) {
            $rows = [];
            foreach ($contexts as $id => $ctx) {
                if (!$ctx instanceof ChannelContext) continue;
                $rows[(string)$id] = ['channel_id' => (string)$id, 'status' => $ctx->status(), 'ai_enabled' => $ctx->aiEnabled() ? 1 : 0,
                                      'business_number' => $ctx->businessNumber(), 'owner_type' => $ctx->ownerType(),
                                      'owner_staff_id' => $ctx->ownerType() === 'staff' ? $ctx->ownerStaffId() : null,
                                      'evo_instance' => $ctx->isDepartment() ? null : $ctx->instance()];
            }
        }
        return new self('on', $contexts, (array)($routing['channel_to_instance'] ?? []), $rows,
                        InternalNumbers::fromRows($rows, $configMap, $verified, $config, $dataDir), $pdo);
    }

    /** Is the registry on here (read, or failed to read)? */
    public function active(): bool
    {
        return $this->mode !== 'off';
    }

    /** @param mixed $store the plugin store, for the salesperson numbers' owners */
    public function useStore($store): void
    {
        if ($this->internal !== null) $this->internal->useStore($store);
    }

    public function internal(): ?InternalNumbers
    {
        return $this->internal;
    }

    /** Are the department numbers all recorded? True with the registry off: there is no salesperson number to protect. */
    public function numbersComplete(): bool
    {
        if ($this->mode === 'off') return true;
        return $this->internal !== null && $this->internal->complete();
    }

    /** @return string[] */
    public function gaps(): array
    {
        if ($this->mode === 'off') return [];
        return $this->internal !== null ? $this->internal->gaps() : ['unreadable'];
    }

    /**
     * Is a message on $channel from $phone from DishNet's own side? '' for a customer; otherwise the class
     * (InternalNumbers::DISHNET_LINE everywhere; LINE_OWNER / ALERT_NUMBER on a salesperson's number only — a channel the
     * registry routes that is not a department — never on a department, a website chat or a notification thread).
     * Registry off, or not readable: '' — nobody is ever re-filed or ignored because something could not be read.
     */
    public function senderClass(string $channel, string $phone): string
    {
        if ($this->mode !== 'on' || $this->internal === null) return '';
        $salesperson = isset($this->contexts[$channel]) && !self::isDepartment($channel);
        return $this->internal->senderClass($phone, !$salesperson);
    }

    /**
     * Why nothing automated may leave on $channel now; '' when it may. $fresh re-reads the channel's row, so a number
     * switched off a moment ago — after this process read the registry — is refused at once.
     */
    public function channelRefusal(string $channel, bool $fresh = true): string
    {
        if ($this->mode === 'off') return '';
        $dept = self::isDepartment($channel);
        if ($this->mode === 'unreadable') return $dept ? '' : 'registry_unreadable';
        $ctx = $this->contexts[$channel] ?? null;
        if ($ctx === null) return 'unknown_channel';
        $row = $this->rows[$channel] ?? null;
        if ($fresh && $this->pdo !== null) {
            try {
                $s = $this->pdo->prepare('SELECT status, ai_enabled, business_number, verified_at, evo_instance
                                            FROM wa_channels WHERE channel_id = ?');
                $s->execute([$channel]);
                $r = $s->fetch(\PDO::FETCH_ASSOC);
                if (is_array($r))  $row = $r;
                elseif (!$dept)    return 'unknown_channel';
                else               $row = null;   // a missing department row reads as the routing reads it: active, on
            } catch (\Throwable $e) {
                if (!$dept) return 'registry_unreadable';
            }
        }
        $status = $row !== null ? (string)($row['status'] ?? 'active') : $ctx->status();
        if ($status !== 'active') return 'channel_' . $status;
        $ai = $row !== null ? (int)($row['ai_enabled'] ?? 1) === 1 : $ctx->aiEnabled();
        if (!$ai) return 'channel_assistant_disabled';
        if (!$dept) {
            if ($row === null || trim((string)($row['business_number'] ?? '')) === '' || trim((string)($row['verified_at'] ?? '')) === '') {
                return 'channel_unverified';
            }
            if (strcasecmp(trim((string)($row['evo_instance'] ?? '')), $ctx->instance()) !== 0) return 'instance_changed';
            // Re-paired to another phone since it was verified, as Evolution last reported it: unverified until verified again.
            $now = $this->internal !== null ? $this->internal->reportedFor($ctx->instance()) : '';
            if ($now !== '' && $now !== (string)preg_replace('/\D+/', '', (string)($row['business_number'] ?? ''))) return 'channel_unverified';
            if (!$this->numbersComplete()) return 'internal_numbers_incomplete';
        }
        // No instance in the routing this process read: a department has none configured (final); a salesperson's number
        // was off when it was read and has been switched on since (it waits for the next read, never closes).
        if (trim((string)($this->c2i[$channel] ?? '')) === '') return $dept ? 'no_instance' : 'instance_changed';
        return '';
    }

    /** Why nothing automated may go to $phone on $channel now; '' when it may. */
    public function refusal(string $channel, string $phone, bool $fresh = true): string
    {
        $r = $this->channelRefusal($channel, $fresh);
        if ($r !== '') return $r;
        return $this->senderClass($channel, $phone) !== '' ? 'internal_recipient' : '';
    }

    /**
     * The channels something automated may leave on, as an SQL condition on a channel column — an ALLOW-list: a leading
     * " AND col IN (…)" and its arguments, or ['', []] with the registry off. A channel the registry does not route (a
     * website chat, a notification thread, a number refused for a conflict) is left out with every refused one, so the
     * follow-up scan never opens what followup_run would only close again — which, reopened every scan, would take every
     * run's places. Unreadable: the three departments alone.
     *
     * @return array{0:string,1:string[]}
     */
    public function sqlExclusion(string $column): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column)) throw new \InvalidArgumentException('not a column name');
        if ($this->mode === 'off') return ['', []];
        if ($this->mode === 'unreadable') {
            $d = array_values(\ChannelRegistry::DEPARTMENT);
            return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($d), '?')) . ')', $d];
        }
        $ids = [];
        foreach (array_keys($this->contexts) as $id) {
            if ($this->channelRefusal((string)$id, false) === '') $ids[] = (string)$id;
        }
        if ($ids === []) return [' AND 1 = 0', []];
        return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /**
     * The channels whose automated messages wait rather than end — a TRANSIENT channel-level reason — as an SQL condition
     * on a channel column: for the follow-up sender, so drafts held on them take none of its places and every other
     * approved follow-up still goes. A leading " AND …" and its arguments, or ['', []]. Unreadable: every channel but the
     * three departments.
     *
     * @return array{0:string,1:string[]}
     */
    public function sqlHeld(string $column): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column)) throw new \InvalidArgumentException('not a column name');
        if ($this->mode === 'off') return ['', []];
        if ($this->mode === 'unreadable') {
            $d = array_values(\ChannelRegistry::DEPARTMENT);
            return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($d), '?')) . ')', $d];
        }
        $ids = [];
        foreach (array_keys($this->contexts) as $id) {
            $r = $this->channelRefusal((string)$id, false);
            if ($r !== '' && self::transient($r)) $ids[] = (string)$id;
        }
        if ($ids === []) return ['', []];
        return [' AND ' . $column . ' NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /**
     * Say once in this process, loudly, that the registry is on while a department number is not verified for its
     * instance: no salesperson number answers or sends anything until it is, and the departments recognise only the
     * numbers recorded. Nothing when complete, or with the registry off.
     */
    public function warnIfIncomplete(): void
    {
        static $said = false;
        if ($said || $this->mode !== 'on' || $this->numbersComplete()) return;
        $said = true;
        error_log('[channels] the channel registry is on but the department numbers are not all verified for their '
                . 'instances (' . implode(', ', $this->gaps()) . '): no salesperson number answers or sends anything '
                . 'automated until they are — WhatsApp AI screen → Salesperson numbers → Verify number');
    }

    /** A reason that may clear by itself: a follow-up waits for it rather than being closed. */
    public static function transient(string $reason): bool
    {
        return in_array($reason, self::TRANSIENT, true);
    }

    /** A reason the AI worker answers with silence: the team has the message, and nobody is told. */
    public static function silent(string $reason): bool
    {
        return in_array($reason, self::SILENT, true);
    }

    private static function isDepartment(string $channel): bool
    {
        return in_array($channel, \ChannelRegistry::DEPARTMENT, true);
    }
}
