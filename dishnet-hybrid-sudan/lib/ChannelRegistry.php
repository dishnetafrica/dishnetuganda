<?php
declare(strict_types=1);

require_once __DIR__ . '/ChannelContext.php';

/**
 * ChannelRegistry — the WhatsApp numbers DishNet operates, one row each (5.18.86, docs/65 §D–§F; migration 087).
 *
 * Until 5.18.86 a number WAS its department: three configuration keys bound sales, support and account to three
 * Evolution instances, and a fourth number had nowhere to go. The registry names a number by a channel id and records,
 * beside its instance, the role the assistant plays on it, who owns it, its territory and its switches. Conversations
 * are keyed (phone, channel id), as they always were: the three department ids ARE the strings stored today.
 *
 * ── DARK, AND UGANDA ONLY ───────────────────────────────────────────────
 *
 * Nothing consults the registry unless multi_number_channels_enabled is ON and the install is Uganda (the staff-jobs
 * gate, asked with the data directory). OFF, every path keeps its 5.18.85 code: EvolutionApiService::forStore() returns
 * the service the constructor always built.
 *
 * ── WHAT IT NEVER DOES ──────────────────────────────────────────────────
 *
 * - It never guesses. An instance it does not know is unknown; a channel that is not active is refused, never routed
 *   to another channel; a registry channel naming an instance a department number is configured with is not routed at
 *   all (the department keeps it, as it always had it).
 * - It never stores a department number's instance. Those stay where they are configured, so a change on the uCRM
 *   Configuration screen keeps working; the department rows carry only their switches.
 * - It never holds a credential, and it never writes a whole business number anywhere but its own row: the trail
 *   masks it, and so does every line it logs.
 * - It never deletes. A channel is retired; the trail is append-only (both enforced by triggers in 087).
 *
 * PHP 7.4 compatible.
 */
final class ChannelRegistry
{
    /** The switch. Absent means off. */
    const FLAG = 'multi_number_channels_enabled';

    /** The three numbers that existed before the registry, in the order the inbound map has always been built. */
    const DEPARTMENT = ['sales', 'support', 'account'];

    /**
     * Ids no new channel may take: the department ids, and every other string some path already stores in
     * wa_conversations.channel with a meaning of its own — 'accounts' (notifications), 'web' (the website chat),
     * 'marketing' (the old chat import) — plus words that would read as a wildcard.
     */
    const RESERVED = ['sales', 'support', 'account', 'accounts', 'web', 'marketing', 'staff', 'email',
                      'all', 'any', 'none', 'default', 'unknown', 'department', 'central'];

    const ROLES       = ['sales', 'support', 'account'];
    const STATUSES    = ['active', 'paused', 'disabled', 'retired'];
    const OWNER_TYPES = ['department', 'staff', 'partner'];
    const PORTFOLIOS  = ['own', 'territory', 'all'];
    const HANDOVERS   = ['owner', 'department', 'central'];

    const ID_PATTERN       = '/^[a-z][a-z0-9-]{1,39}$/';
    const INSTANCE_PATTERN = '/^[A-Za-z0-9_.-]{1,100}$/';

    /** @var array<string,bool> a conflict is logged once per process, not on every request */
    private static array $saidConflict = [];

    private \PDO $pdo;
    /** Optional: the plugin store, to check a staff owner against retailers.json. */
    private $store;

    public function __construct(\PDO $pdo, $store = null)
    {
        $this->pdo   = $pdo;
        $this->store = $store;
    }

    /** ON only where the flag is set AND this install is Uganda. Anything unclear is off. */
    public static function enabled(array $config, ?string $dataDir): bool
    {
        if (!filter_var($config[self::FLAG] ?? false, FILTER_VALIDATE_BOOLEAN)) return false;
        try {
            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            return \StaffJobsGate::applies($config, $dataDir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Has migration 087 run here? */
    public function available(): bool
    {
        try {
            $s = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wa_channels'");
            return $s !== false && $s->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<string,array> every row, keyed by channel id: the three departments first, then by age */
    public function rows(): array
    {
        $out = [];
        $q = $this->pdo->query(
            "SELECT * FROM wa_channels
              ORDER BY CASE channel_id WHEN 'sales' THEN 0 WHEN 'support' THEN 1 WHEN 'account' THEN 2 ELSE 3 END,
                       created_at, channel_id");
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(string)$r['channel_id']] = $r;
        return $out;
    }

    public function row(string $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM wa_channels WHERE channel_id = ?');
        $s->execute([$id]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /**
     * The routing the registry produces, given the instances the configuration binds to the three departments.
     *
     * Departments first, in the order EvolutionApiService has always built its inbound map (sales, support, account),
     * with the same rule: the FIRST department naming an instance owns it for inbound. If that department is not
     * active, the instance is refused — it is never handed to the next department that shares it. Then every other
     * channel, oldest first.
     *
     * @param array<string,string> $configMap department channel => instance (EvolutionApiService::configInstanceMap)
     * @return array{channel_to_instance: array<string,string>, instance_to_channel: array<string,string>,
     *               refused: array<string,string>, contexts: array<string,ChannelContext>, conflicts: string[]}
     */
    public function routing(array $configMap): array
    {
        $rows = $this->rows();
        $c2i = []; $i2c = []; $refused = []; $contexts = []; $conflicts = [];

        foreach (self::DEPARTMENT as $id) {
            // A department row cannot be deleted (087's trigger). Should one be missing all the same, the number keeps
            // the behaviour it had before the registry existed, rather than going dark.
            $row = $rows[$id] ?? ['channel_id' => $id, 'role' => $id, 'display_name' => ucfirst($id),
                                  'owner_type' => 'department', 'status' => 'active', 'ai_enabled' => 1];
            $inst = trim((string)($configMap[$id] ?? ''));
            $ctx  = new ChannelContext($row, $inst);
            $contexts[$id] = $ctx;
            if ($inst === '') continue;
            $key = mb_strtolower($inst);
            if ($ctx->isActive()) $c2i[$id] = $inst;
            if (isset($i2c[$key]) || isset($refused[$key])) continue;   // an earlier department owns it for inbound
            if ($ctx->isActive()) $i2c[$key] = $id; else $refused[$key] = $id;
        }

        $configured = [];
        foreach ($configMap as $inst) {
            $inst = trim((string)$inst);
            if ($inst !== '') $configured[mb_strtolower($inst)] = true;
        }

        foreach ($rows as $id => $row) {
            if (in_array($id, self::DEPARTMENT, true)) continue;
            $inst = trim((string)($row['evo_instance'] ?? ''));
            $ctx  = new ChannelContext($row, $inst);
            $contexts[$id] = $ctx;
            if ($inst === '') continue;
            $key = mb_strtolower($inst);
            if (isset($configured[$key]) || isset($i2c[$key]) || isset($refused[$key])) {
                // Never routed: the instance belongs to a department number (or, impossible under 087's unique index,
                // another channel). Inbound on it stays where it always went; nothing is sent on this channel.
                $conflicts[] = $id;
                unset($contexts[$id]);
                if (!isset(self::$saidConflict[$id])) {
                    self::$saidConflict[$id] = true;
                    error_log('[channels] channel ' . $id . ' is NOT routed: its Evolution instance is already a department '
                            . 'number\'s — nothing is received or sent on it until that is corrected');
                }
                continue;
            }
            if ($ctx->isActive()) { $c2i[$id] = $inst; $i2c[$key] = $id; }
            else                  { $refused[$key] = $id; }
        }

        return ['channel_to_instance' => $c2i, 'instance_to_channel' => $i2c, 'refused' => $refused,
                'contexts' => $contexts, 'conflicts' => $conflicts];
    }

    // ── Writes. Each one is a row change and a trail row, in one transaction. ─────────────────────────────────────

    /**
     * A new channel: a number that is not one of the three departments.
     *
     * @param array $in channel_id, evo_instance, display_name, role; optional business_number, owner_type,
     *                  owner_staff_id, owner_partner_id, territory_region_id, portfolio_scope, ai_enabled, handover_to,
     *                  status (default 'disabled' — a new number is switched on deliberately, never by being created)
     * @param array<string,string> $configMap the department instances, which a new channel may never take
     */
    public function create(array $in, string $actor, string $reason, array $configMap = []): ChannelContext
    {
        $actor = $this->actor($actor);
        $id = trim((string)($in['channel_id'] ?? ''));
        if (!self::validId($id)) {
            throw new \InvalidArgumentException('channel id must be lower-case letters, digits and hyphens, 2 to 40 '
                                              . 'characters, starting with a letter, and not a reserved word');
        }
        if ($this->row($id) !== null) throw new \InvalidArgumentException("channel {$id} already exists");

        $inst = $this->instanceOrFail((string)($in['evo_instance'] ?? ''), $configMap, null);
        $role = (string)($in['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) throw new \InvalidArgumentException('role must be sales, support or account');
        $name = trim((string)($in['display_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) throw new \InvalidArgumentException('display name: 1 to 80 characters');

        $ownerType = (string)($in['owner_type'] ?? 'department');
        if (!in_array($ownerType, self::OWNER_TYPES, true)) throw new \InvalidArgumentException('owner type must be department, staff or partner');
        $staffId   = $ownerType === 'staff'   ? (int)($in['owner_staff_id'] ?? 0)   : 0;
        $partnerId = $ownerType === 'partner' ? (int)($in['owner_partner_id'] ?? 0) : 0;
        if ($ownerType === 'staff')   $this->staffOrFail($staffId);
        if ($ownerType === 'partner') $this->partnerOrFail($partnerId);
        $region = isset($in['territory_region_id']) && $in['territory_region_id'] !== null && $in['territory_region_id'] !== ''
                ? (int)$in['territory_region_id'] : null;
        if ($region !== null) $this->regionOrFail($region, $ownerType === 'partner' ? $partnerId : null);

        $portfolio = (string)($in['portfolio_scope'] ?? 'own');
        if (!in_array($portfolio, self::PORTFOLIOS, true)) throw new \InvalidArgumentException('portfolio must be own, territory or all');
        $handover = (string)($in['handover_to'] ?? 'department');
        if (!in_array($handover, self::HANDOVERS, true)) throw new \InvalidArgumentException('handover must be owner, department or central');
        $status = (string)($in['status'] ?? 'disabled');
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('status must be active, paused, disabled or retired');
        $ai = array_key_exists('ai_enabled', $in) ? (filter_var($in['ai_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1;
        $number = $this->numberOrNull($in['business_number'] ?? null);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO wa_channels (channel_id, evo_instance, business_number, display_name, role, owner_type,
                                          owner_staff_id, owner_partner_id, territory_region_id, portfolio_scope,
                                          ai_enabled, handover_to, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$id, $inst, $number, $name, $role, $ownerType,
                        $staffId > 0 ? $staffId : null, $partnerId > 0 ? $partnerId : null, $region,
                        $portfolio, $ai, $handover, $status]);
            $this->trailRow($id, 'created', null,
                sprintf('role=%s owner=%s status=%s ai=%s number=%s', $role, $ownerType, $status, $ai ? 'on' : 'off',
                        self::mask($number)), $actor, $reason);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return new ChannelContext((array)$this->row($id), $inst);
    }

    /** Switch a channel on, pause, disable or retire it. Department rows included: that is what an emergency needs. */
    public function setStatus(string $id, string $status, string $actor, string $reason): void
    {
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('status must be active, paused, disabled or retired');
        $this->change($id, 'status', 'status', $status, $actor, $reason);
    }

    /** Whether the assistant answers on this number. Off: messages are kept for the team, nobody is answered by the AI. */
    public function setAiEnabled(string $id, bool $on, string $actor, string $reason): void
    {
        $this->change($id, 'ai_enabled', 'ai_enabled', $on ? 1 : 0, $actor, $reason);
    }

    /** Move a channel to another Evolution instance. Never a department row: its instance is configuration. */
    public function setInstance(string $id, string $instance, string $actor, string $reason, array $configMap = []): void
    {
        if (in_array($id, self::DEPARTMENT, true)) {
            throw new \InvalidArgumentException("{$id} takes its instance from the configuration (evo_instance_{$id}), not from the registry");
        }
        $inst = $this->instanceOrFail($instance, $configMap, $id);
        $this->change($id, 'evo_instance', 'instance', $inst, $actor, $reason);
    }

    /** @return array<int,array> the trail of one channel, oldest first */
    public function trail(string $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM wa_channel_log WHERE channel_id = ? ORDER BY id');
        $s->execute([$id]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** A business number as anything outside its own row may show it: the last two digits. Never the whole number. */
    public static function mask(?string $number): string
    {
        $d = preg_replace('/\D+/', '', (string)$number);
        if ($d === null || $d === '') return 'none';
        return '••••' . substr($d, -2);
    }

    public static function validId(string $id): bool
    {
        return (bool)preg_match(self::ID_PATTERN, $id) && !in_array($id, self::RESERVED, true);
    }

    private function change(string $id, string $column, string $action, $value, string $actor, string $reason): void
    {
        $actor = $this->actor($actor);
        $row = $this->row($id);
        if ($row === null) throw new \InvalidArgumentException("no channel {$id}");
        $old = $row[$column];
        if ((string)$old === (string)$value) return;   // nothing changes, nothing is written
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE wa_channels SET {$column} = ?, updated_at = datetime('now') WHERE channel_id = ?")
                      ->execute([$value, $id]);
            $this->trailRow($id, $action, $old === null ? null : (string)$old, (string)$value, $actor, $reason);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function trailRow(string $id, string $action, ?string $old, ?string $new, string $actor, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO wa_channel_log (channel_id, action, old_value, new_value, actor, reason) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $action, $old, $new, $actor, mb_substr(trim($reason), 0, 300)]);
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '') throw new \InvalidArgumentException('every change to a channel names who made it');
        return mb_substr($actor, 0, 120);
    }

    /** @param array<string,string> $configMap */
    private function instanceOrFail(string $instance, array $configMap, ?string $self): string
    {
        $inst = trim($instance);
        if (!preg_match(self::INSTANCE_PATTERN, $inst)) {
            throw new \InvalidArgumentException('instance name: letters, digits, dot, dash and underscore, 1 to 100 characters');
        }
        $key = mb_strtolower($inst);
        foreach ($configMap as $dept => $di) {
            if (mb_strtolower(trim((string)$di)) === $key) {
                throw new \InvalidArgumentException("that instance is the {$dept} number's (configuration); one instance, one channel");
            }
        }
        $s = $this->pdo->prepare('SELECT channel_id FROM wa_channels WHERE lower(evo_instance) = ? AND channel_id <> ?');
        $s->execute([$key, (string)$self]);
        $other = $s->fetchColumn();
        if ($other !== false) throw new \InvalidArgumentException("that instance is already channel {$other}'s");
        return $inst;
    }

    private function numberOrNull($number): ?string
    {
        if ($number === null || trim((string)$number) === '') return null;
        $raw = trim((string)$number);
        if (!preg_match('/^\+?[0-9]{8,15}$/', $raw)) throw new \InvalidArgumentException('business number: international form, 8 to 15 digits');
        $n = '+' . ltrim($raw, '+');
        $s = $this->pdo->prepare('SELECT channel_id FROM wa_channels WHERE business_number = ?');
        $s->execute([$n]);
        if ($s->fetchColumn() !== false) throw new \InvalidArgumentException('that number is already another channel\'s');
        return $n;
    }

    private function staffOrFail(int $staffId): void
    {
        if ($staffId <= 0) throw new \InvalidArgumentException('a staff-owned channel names its owner (owner_staff_id)');
        if ($this->store === null) return;   // no store to look in: the schema's CHECK still requires the id
        if (!class_exists('StaffDirectory')) require_once __DIR__ . '/StaffDirectory.php';
        foreach ((array)($this->store->load('retailers.json') ?? []) as $r) {
            if (is_array($r) && (int)($r['id'] ?? 0) === $staffId) {
                if (!\StaffDirectory::isActive($r)) throw new \InvalidArgumentException("staff #{$staffId} is not active");
                return;
            }
        }
        throw new \InvalidArgumentException("no staff #{$staffId}");
    }

    private function partnerOrFail(int $partnerId): void
    {
        if ($partnerId <= 0) throw new \InvalidArgumentException('a partner-owned channel names its owner (owner_partner_id)');
        if (!$this->tableExists('dist_partners')) throw new \InvalidArgumentException('the distributor tables are not installed here');
        $s = $this->pdo->prepare('SELECT 1 FROM dist_partners WHERE id = ?');
        $s->execute([$partnerId]);
        if ($s->fetchColumn() === false) throw new \InvalidArgumentException("no distributor partner #{$partnerId}");
    }

    private function regionOrFail(int $regionId, ?int $partnerId): void
    {
        if (!$this->tableExists('dist_regions')) throw new \InvalidArgumentException('the distributor tables are not installed here');
        $s = $this->pdo->prepare('SELECT partner_id FROM dist_regions WHERE id = ?');
        $s->execute([$regionId]);
        $p = $s->fetchColumn();
        if ($p === false) throw new \InvalidArgumentException("no region #{$regionId}");
        if ($partnerId !== null && (int)$p !== $partnerId) {
            throw new \InvalidArgumentException("region #{$regionId} is not partner #{$partnerId}'s territory");
        }
    }

    private function tableExists(string $t): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $s->execute([$t]);
        return $s->fetchColumn() !== false;
    }
}
