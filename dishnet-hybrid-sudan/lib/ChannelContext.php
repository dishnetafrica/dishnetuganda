<?php
declare(strict_types=1);

/**
 * ChannelContext — one WhatsApp number, as the code that answers on it may know it (5.18.86, docs/65 §G).
 *
 * Built by ChannelRegistry from a wa_channels row. Immutable. Two halves, and the line between them is the point:
 *
 *   SERVER-SIDE ONLY — the Evolution instance and the business number. They say which pipe a message travels on.
 *   The instance is an infrastructure identifier (BrainContext::NEVER_PRESENT); the business number is ours. Neither
 *   is ever handed to the model: forBrain() does not carry them, and nothing that builds a prompt may call
 *   instance() or businessNumber().
 *
 *   FOR THE BRAIN — the role (sales | support | account: which of the brain's existing behaviours answers on this
 *   number), and the persona, territory and portfolio of a number that belongs to somebody rather than to a
 *   department. In Batch 1 no channel has a persona, the territory is an id the brain cannot use, and the portfolio is
 *   a later batch's question; forBrain() carries them so the boundary exists before anything crosses it, and
 *   BrainContext admits a key only once it decides what the model does (its own rule).
 *
 * PHP 7.4 compatible.
 */
final class ChannelContext
{
    private string $id;
    private string $role;
    private string $displayName;
    private string $instance;
    private ?string $businessNumber;
    private string $ownerType;
    private ?int $ownerStaffId;
    private ?int $ownerPartnerId;
    private ?int $territoryRegionId;
    private string $portfolioScope;
    private bool $aiEnabled;
    private string $handoverTo;
    private string $status;

    /**
     * @param array  $row      a wa_channels row
     * @param string $instance the instance this channel resolves to now: its own column, or, for a department row, the
     *                         configured key (EvolutionApiService::configInstanceMap). '' when it has none.
     */
    public function __construct(array $row, string $instance)
    {
        $this->id                = (string)($row['channel_id'] ?? '');
        $this->role              = (string)($row['role'] ?? '');
        $this->displayName       = (string)($row['display_name'] ?? '');
        $this->instance          = trim($instance);
        $bn                      = trim((string)($row['business_number'] ?? ''));
        $this->businessNumber    = $bn !== '' ? $bn : null;
        $this->ownerType         = (string)($row['owner_type'] ?? 'department');
        $this->ownerStaffId      = isset($row['owner_staff_id'])      && $row['owner_staff_id']      !== null ? (int)$row['owner_staff_id'] : null;
        $this->ownerPartnerId    = isset($row['owner_partner_id'])    && $row['owner_partner_id']    !== null ? (int)$row['owner_partner_id'] : null;
        $this->territoryRegionId = isset($row['territory_region_id']) && $row['territory_region_id'] !== null ? (int)$row['territory_region_id'] : null;
        $this->portfolioScope    = (string)($row['portfolio_scope'] ?? 'all');
        $this->aiEnabled         = (int)($row['ai_enabled'] ?? 1) === 1;
        $this->handoverTo        = (string)($row['handover_to'] ?? 'department');
        $this->status            = (string)($row['status'] ?? 'active');
    }

    public function id(): string            { return $this->id; }
    /** sales | support | account — the brain behaviour that answers on this number. */
    public function role(): string          { return $this->role; }
    public function displayName(): string   { return $this->displayName; }
    public function status(): string        { return $this->status; }
    public function isActive(): bool        { return $this->status === 'active'; }
    public function aiEnabled(): bool       { return $this->aiEnabled; }
    public function ownerType(): string     { return $this->ownerType; }
    public function ownerStaffId(): ?int    { return $this->ownerStaffId; }
    public function ownerPartnerId(): ?int  { return $this->ownerPartnerId; }
    public function territoryRegionId(): ?int { return $this->territoryRegionId; }
    public function portfolioScope(): string { return $this->portfolioScope; }
    public function handoverTo(): string    { return $this->handoverTo; }

    /** One of the three numbers that existed before the registry. */
    public function isDepartment(): bool
    {
        return $this->ownerType === 'department' && in_array($this->id, ChannelRegistry::DEPARTMENT, true);
    }

    /** The owner as one id, or null for a department number. */
    public function ownerId(): ?int
    {
        if ($this->ownerType === 'staff')   return $this->ownerStaffId;
        if ($this->ownerType === 'partner') return $this->ownerPartnerId;
        return null;
    }

    /** SERVER-SIDE ONLY. The Evolution instance a send on this channel goes through. Never into a prompt or a log. */
    public function instance(): string { return $this->instance; }

    /** SERVER-SIDE ONLY. Our number on this channel, once verified; null until then. Never into a prompt or a log. */
    public function businessNumber(): ?string { return $this->businessNumber; }

    /**
     * What the brain may be told about the number it is answering on. No instance, no number, no owner id, no status.
     *
     * @return array{role:string, persona:?string, territory:?string, portfolio:string}
     */
    public function forBrain(): array
    {
        return [
            'role'      => $this->role,
            // No channel has a persona yet (Batch 3 decides the first one's wording, with its owner's consent).
            'persona'   => null,
            // An id is no use to the model and a region NAME is not established for any channel yet.
            'territory' => null,
            'portfolio' => $this->portfolioScope,
        ];
    }

    /** For a log line: the id and the role. Never the instance, never a number. */
    public function describe(): string
    {
        return $this->id . ' (' . $this->role . ')';
    }
}
