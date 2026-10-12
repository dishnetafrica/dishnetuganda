<?php
declare(strict_types=1);

require_once __DIR__ . '/ChannelContext.php';
require_once __DIR__ . '/StaffDirectory.php';

/**
 * LineOwner — the salesperson a WhatsApp number belongs to (5.18.89, docs/65 §AA).
 *
 * A staff-owned channel names its owner by a retailers.json id (ChannelContext::ownerStaffId). Three things are read
 * from that row, each for one use and nothing else:
 *
 *   - the FIRST NAME, for the assistant's one persona line (D3: "<first name>'s assistant at DishNet"). Letters,
 *     apostrophe and hyphen only, at most 30 characters, so nothing a staff record holds can reach the model as an
 *     instruction;
 *   - the PHONE, for the hand-over alert (D4). Server side only: never into a prompt, never into a log line;
 *   - the full NAME and the id, for the lead that is assigned to them (D5).
 *
 * No owner — and the number behaves as a department's — for a department or partner channel, a channel that is not
 * active, an owner who is missing or not active, or an owner with no usable first name. On any error: no owner.
 *
 * PHP 7.4 compatible.
 */
final class LineOwner
{
    private int $id;
    private string $name;
    private string $firstName;
    private ?string $phone;

    private function __construct(int $id, string $name, string $firstName, ?string $phone)
    {
        $this->id        = $id;
        $this->name      = $name;
        $this->firstName = $firstName;
        $this->phone     = $phone;
    }

    /**
     * The owner of the number a conversation is on, or null.
     *
     * @param mixed $store the plugin store (retailers.json)
     */
    public static function of(?ChannelContext $ctx, $store, array $config = [], ?string $dataDir = null): ?self
    {
        try {
            if ($ctx === null || $ctx->ownerType() !== 'staff' || !$ctx->isActive()) return null;
            $id = (int)($ctx->ownerStaffId() ?? 0);
            if ($id <= 0 || $store === null) return null;
            $row = $store->findOne('retailers.json', 'id', $id);
            if (!is_array($row) || (int)($row['id'] ?? 0) !== $id || !StaffDirectory::isActive($row)) return null;
            $name  = trim((string)($row['name'] ?? ''));
            $first = self::firstNameOf($name);
            if ($first === '') return null;
            $phone = null;
            if (trim((string)($row['phone'] ?? '')) !== '') {
                if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
                $phone = StaffDirectory::phoneOf($row, \TenantProfile::current($config, $dataDir));
            }
            return new self($id, $name, $first, ($phone !== null && $phone !== '') ? $phone : null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The first word of a name, as the persona may say it: letters, apostrophe and hyphen, at most 30 characters. */
    public static function firstNameOf(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name));
        $w = is_array($parts) ? (string)($parts[0] ?? '') : '';
        $w = (string)preg_replace("/[^\\p{L}'\\-]/u", '', $w);
        $w = trim($w, "'-");
        if ($w === '') return '';
        return mb_substr(mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), 0, 30);
    }

    public function id(): int { return $this->id; }
    /** The full name, for the lead's assigned_name. */
    public function name(): string { return $this->name; }
    /** The persona's word: the first name only. */
    public function firstName(): string { return $this->firstName; }
    /** SERVER-SIDE ONLY: the number the hand-over alert goes to, international form, or null if none is on record. */
    public function phone(): ?string { return $this->phone; }
}
