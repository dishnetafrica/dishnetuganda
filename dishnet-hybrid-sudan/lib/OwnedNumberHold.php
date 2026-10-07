<?php
declare(strict_types=1);

/**
 * OwnedNumberHold — automatic follow-ups are held on a salesperson's own WhatsApp number (5.18.89, docs/65 §AA item 6).
 *
 * On a salesperson's number the assistant tells the customer that the salesperson will follow up personally (D3), and
 * every automated send from a person's own number adds to that number's ban risk (§L). So, with the channel registry
 * on, the follow-up pipeline leaves every staff-owned channel alone at each of its three steps, the way it already
 * leaves a colleague's number alone:
 *
 *   - followup_scan.php never opens one. The channels are left out of its query, so they take none of its places and
 *     write nothing to its log on every cycle;
 *   - followup_run.php closes one that is open, before any model call;
 *   - followup_send.php closes one that is approved, before any send.
 *
 * wa_followups_on_owned_numbers (default OFF) lifts the hold. With the registry off (every install but Uganda, or the
 * switch off) nothing is held and nothing here changes. The three department numbers are never held. Should the
 * registry be on but unreadable, every channel that is not a department's is held: a follow-up that waits can be sent
 * later, one sent from a person's number cannot be taken back.
 *
 * PHP 7.4 compatible.
 */
final class OwnedNumberHold
{
    /** The switch that lifts the hold. Absent means held. */
    const FLAG = 'wa_followups_on_owned_numbers';

    /** The close reason's detail, in the follow-up's own log. */
    const REASON = "a salesperson's own number: they follow up personally (" . self::FLAG . ' is off)';

    /** Is the hold in force at all? */
    private bool $on;

    /** @var array<string,true>|null the staff-owned channel ids; null when the registry could not be read */
    private ?array $staff;

    private function __construct(bool $on, ?array $staff)
    {
        $this->on    = $on;
        $this->staff = $staff;
    }

    public static function forInstall(array $config, ?string $dataDir, ?\PDO $pdo): self
    {
        if (filter_var($config[self::FLAG] ?? false, FILTER_VALIDATE_BOOLEAN)) return new self(false, []);
        if (!class_exists('ChannelRegistry')) require_once __DIR__ . '/ChannelRegistry.php';
        if (!\ChannelRegistry::enabled($config, $dataDir)) return new self(false, []);
        if ($pdo === null) return new self(true, null);
        try {
            $reg = new \ChannelRegistry($pdo);
            // Migration 087 absent: the registry routes the three departments and nothing else, so no conversation is
            // on a salesperson's number to hold.
            if (!$reg->available()) return new self(false, []);
            $staff = [];
            foreach ($reg->rows() as $id => $row) {
                if ((string)($row['owner_type'] ?? '') === 'staff') $staff[(string)$id] = true;
            }
            return new self(true, $staff);
        } catch (\Throwable $e) {
            error_log('[followup] the channel registry could not be read, so follow-ups wait on every number but the '
                    . 'three departments: ' . $e->getMessage());
            return new self(true, null);
        }
    }

    /** Is a follow-up on this channel held? */
    public function holds(string $channel): bool
    {
        if (!$this->on) return false;
        if (in_array($channel, \ChannelRegistry::DEPARTMENT, true)) return false;
        return $this->staff === null ? true : isset($this->staff[$channel]);
    }

    /**
     * The same rule as an SQL condition on a channel column: a leading " AND …" and its arguments, or ['', []] when
     * nothing is held.
     *
     * @return array{0:string,1:string[]}
     */
    public function sqlExclusion(string $column): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column)) {
            throw new \InvalidArgumentException('not a column name');
        }
        if (!$this->on) return ['', []];
        if ($this->staff === null) {
            $d = array_values(\ChannelRegistry::DEPARTMENT);
            return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($d), '?')) . ')', $d];
        }
        if ($this->staff === []) return ['', []];
        $ids = array_map('strval', array_keys($this->staff));
        return [' AND ' . $column . ' NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}
