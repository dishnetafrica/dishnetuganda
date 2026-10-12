<?php
declare(strict_types=1);

require_once __DIR__ . '/ChannelRegistry.php';
require_once __DIR__ . '/StaffDirectory.php';
require_once __DIR__ . '/EvolutionApiService.php';
require_once __DIR__ . '/InternalNumbers.php';

/**
 * SalesNumbersAdmin — the "Salesperson numbers" card on the WhatsApp AI screen (5.18.89, docs/65 §AA.1 item 1).
 *
 * Admin only: the screen is, and every action here checks again. Every change to a number goes through ChannelRegistry,
 * which writes the row and its trail row in one transaction, with the admin's name and a reason. This class adds the
 * screen's own rules:
 *
 *   - a number is ADDED switched off (disabled), owned by an active salesperson, on an Evolution instance that is no
 *     department's and no other number's, and that Evolution reports;
 *   - its business number is READ from Evolution's own report for that instance (the paired account), never typed, and
 *     only while the instance is connected;
 *   - it is SWITCHED ON only once its number is verified, its owner is still active, and the channel registry
 *     (multi_number_channels_enabled) is on: the go-live order of §AA.3 — the three departments on the registry first,
 *     this number after;
 *   - a RETIRED number takes no further change, and retiring needs a ticked confirmation;
 *   - the three department numbers are listed here, never changed here: their instance is configuration ("Numbers").
 *     5.18.90 (docs/65 §AD): their NUMBER is verified here — read from Evolution's report for the instance the
 *     configuration gives them, beside that instance — so a salesperson's number can recognise a department's message
 *     and never answer it. No salesperson's number is switched on, and none answers or sends anything automated, until
 *     every department instance's number is verified for the instance it is configured with.
 *
 * The card shows on Uganda wherever migration 087 has run, with the registry switch on or off: a number may be prepared
 * while nothing routes to it. On every other install it is not shown and nothing here runs.
 *
 * PHP 7.4 compatible.
 */
final class SalesNumbersAdmin
{
    /** The roles a salesperson's staff row carries: the three the Leads page's daily rota calls. */
    const SALES_ROLES = ['sales', 'sales_staff', 'field_agent'];

    /** A number added here is sales-001, sales-002, …; an id is never reused (a channel is never deleted). */
    const ID_PREFIX = 'sales-';

    /** The wa_action values this card answers. */
    const ACTIONS = ['sn_add', 'sn_pair', 'sn_verify', 'sn_webhook', 'sn_ai', 'sn_status', 'sn_owner', 'sn_verify_department'];

    private \PDO $pdo;
    /** @var mixed the plugin store (retailers.json) */
    private $store;
    private array $config;
    private ?string $dataDir;
    private \EvolutionApiService $evo;
    private \ChannelRegistry $reg;
    /** @var array<string,array>|null Evolution's instances by name, read once per request */
    private ?array $live = null;

    /** @param mixed $store */
    public function __construct(\PDO $pdo, $store, array $config, ?string $dataDir, \EvolutionApiService $evo)
    {
        $this->pdo     = $pdo;
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = $dataDir;
        $this->evo     = $evo;
        $this->reg     = new \ChannelRegistry($pdo, $store);
    }

    /** Is the card shown? Uganda, and the registry's tables present. The registry switch may be off. */
    public static function shown(array $config, ?string $dataDir, ?\PDO $pdo): bool
    {
        if ($pdo === null) return false;
        try {
            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            if (!\StaffJobsGate::applies($config, $dataDir)) return false;
            return (new \ChannelRegistry($pdo))->available();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Is the channel registry switched on here (multi_number_channels_enabled, Uganda)? */
    public function registryOn(): bool
    {
        return \ChannelRegistry::enabled($this->config, $this->dataDir);
    }

    // ── Actions ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array    $post       the submitted form
     * @param array    $admin      the signed-in staff row
     * @param callable $webhookUrl fn(): string — the plugin's webhook address with its secret; '' when it cannot be
     *                             worked out. Called only by the webhook action, and the address is never shown.
     * @return array{ok:bool,text:string,qr?:array}
     */
    public function handle(string $act, array $post, array $admin, callable $webhookUrl): array
    {
        if (empty($admin['is_admin'])) return self::no('Only an administrator can change a WhatsApp number.');
        $actor  = self::actorOf($admin);
        $reason = mb_substr(trim((string)($post['reason'] ?? '')), 0, 300);
        try {
            switch ($act) {
                case 'sn_add':     return $this->add($post, $actor, $reason);
                case 'sn_pair':    return $this->pair($this->channel($post));
                case 'sn_verify':  return $this->verify($this->channel($post), $actor, $reason);
                case 'sn_webhook': return $this->webhook($this->channel($post), $webhookUrl);
                case 'sn_ai':      return $this->ai($this->channel($post), $post, $actor, $reason);
                case 'sn_status':  return $this->status($this->channel($post), $post, $actor, $reason);
                case 'sn_owner':   return $this->owner($this->channel($post), $post, $actor, $reason);
                case 'sn_verify_department': return $this->verifyDepartment($this->department($post), $actor, $reason);
            }
        } catch (\InvalidArgumentException $e) {
            return self::no($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[sales-numbers] ' . $act . ' failed: ' . $e->getMessage());
            return self::no('That could not be saved, and nothing was changed.');
        }
        return self::no('Unknown action.');
    }

    private function add(array $post, string $actor, string $reason): array
    {
        $inst = trim((string)($post['instance'] ?? ''));
        if ($inst === '') return self::no('Choose the Evolution instance of the salesperson\'s number.');
        if (!isset($this->live()[$inst])) {
            return self::no('Evolution does not report an instance called ' . $inst . ' — reload the page and choose one from the list.');
        }
        if (!in_array($inst, $this->freeInstances(), true)) {
            return self::no('That instance is already a number\'s — a department\'s or another salesperson\'s.');
        }
        $staffId = (int)($post['owner_staff_id'] ?? 0);
        $person  = $this->salesperson($staffId);
        $name    = trim((string)($post['display_name'] ?? ''));
        if ($name === '') $name = 'Sales — ' . trim((string)($person['name'] ?? ''));
        $id = $this->nextId();
        $this->reg->create([
            'channel_id'      => $id,
            'evo_instance'    => $inst,
            'display_name'    => mb_substr($name, 0, 80),
            'role'            => 'sales',
            'owner_type'      => 'staff',
            'owner_staff_id'  => $staffId,
            'portfolio_scope' => 'own',
            'handover_to'     => 'owner',
            'ai_enabled'      => true,
            'status'          => 'disabled',
        ], $actor, $reason !== '' ? $reason : 'added on the WhatsApp AI screen',
           \EvolutionApiService::configInstanceMap($this->config));
        return self::yes("Added {$id} for " . trim((string)($person['name'] ?? '')) . '. It is switched off: pair it, verify '
                       . 'its number and register its webhook, then switch it on.');
    }

    private function pair(array $row): array
    {
        $inst = (string)$row['evo_instance'];
        $r = $this->evo->connect($inst);
        if (!empty($r['ok']) && ((string)($r['qr'] ?? '') !== '' || (string)($r['pairing_code'] ?? '') !== '')) {
            return ['ok' => true, 'text' => 'Scan this with the phone of ' . (string)$row['display_name'] . '.',
                    'qr' => ['channel' => (string)$row['display_name'], 'instance' => $inst,
                             'qr' => (string)($r['qr'] ?? ''), 'code' => (string)($r['pairing_code'] ?? '')]];
        }
        return self::no(!empty($r['ok']) ? 'Evolution returned no QR — this number may already be connected.'
                                         : 'Evolution refused: ' . (string)($r['error'] ?? ''));
    }

    private function verify(array $row, string $actor, string $reason): array
    {
        $inst = (string)$row['evo_instance'];
        $live = $this->live()[$inst] ?? null;
        if ($live === null) return self::no('Evolution does not report the instance ' . $inst . ' — reload the page.');
        if (empty($live['connected'])) return self::no('Not connected yet: pair the phone first (Show QR code), then verify.');
        $digits = self::phoneOf($live);
        if ($digits === '') return self::no(self::noPhone($live));
        $this->reg->verifyNumber((string)$row['channel_id'], '+' . $digits, $actor,
            $reason !== '' ? $reason : 'read from Evolution\'s report for instance ' . $inst);
        $this->refreshReported();
        return self::yes('Verified: ' . (string)$row['display_name'] . ' is the number ending '
                       . \ChannelRegistry::mask('+' . $digits) . ', as Evolution reports it.');
    }

    /**
     * 5.18.90 (docs/65 §AD): a department's number, read from Evolution's report for the instance the configuration gives
     * that department — never typed — and recorded beside that instance. Works with the registry off: the departments are
     * verified first, then the registry is switched on. A department sharing an earlier department's instance (support
     * and account, in production) is covered by that department's number.
     */
    private function verifyDepartment(string $id, string $actor, string $reason): array
    {
        $cfg  = \EvolutionApiService::configInstanceMap($this->config);
        $inst = trim((string)($cfg[$id] ?? ''));
        if ($inst === '') return self::no('No Evolution instance is configured for the ' . $id . ' number, so there is nothing to verify.');
        foreach (\ChannelRegistry::DEPARTMENT as $d) {
            if ($d === $id) break;
            if (mb_strtolower(trim((string)($cfg[$d] ?? ''))) === mb_strtolower($inst)) {
                return self::no('The ' . $id . ' number uses the ' . $d . ' number\'s instance: verify ' . $d . '; its number covers both.');
            }
        }
        $live = $this->live()[$inst] ?? null;
        if ($live === null) return self::no('Evolution does not report the instance ' . $inst . ' — reload the page.');
        if (empty($live['connected'])) return self::no('The ' . $id . ' number is not connected in Evolution, so its number cannot be read.');
        $digits = self::phoneOf($live);
        if ($digits === '') return self::no(self::noPhone($live));
        // A department that takes no instance's inbound under its recorded number any more — covered by an earlier
        // department on the same instance, or moved since its number was verified — gives that number up if it is this one.
        $verified   = $this->reg->verifiedInstances();
        $releasable = [];
        foreach (\ChannelRegistry::DEPARTMENT as $d) {
            if ($d === $id) continue;
            $di = mb_strtolower(trim((string)($cfg[$d] ?? '')));
            $covered = false;
            foreach (\ChannelRegistry::DEPARTMENT as $e) {
                if ($e === $d) break;
                if ($di !== '' && mb_strtolower(trim((string)($cfg[$e] ?? ''))) === $di) $covered = true;
            }
            if ($di === '' || $covered || mb_strtolower(trim((string)($verified[$d] ?? ''))) !== $di) $releasable[] = $d;
        }
        $this->reg->verifyDepartmentNumber($id, '+' . $digits, $actor,
            $reason !== '' ? $reason : 'read from Evolution\'s report for instance ' . $inst, $inst, $releasable);
        $this->refreshReported();
        return self::yes('Verified: the ' . $id . ' number is the one ending ' . \ChannelRegistry::mask('+' . $digits)
                       . ', as Evolution reports it for instance ' . $inst . '.');
    }

    /**
     * 5.18.90 (docs/65 §AD): what an admin is told after changing a department's instance — '' when every department
     * number is still verified for its instance, otherwise the sentence that sends them back to Verify number.
     */
    public function departmentGapNote(): string
    {
        $g = $this->departmentGaps();
        return $g === [] ? '' : ' Verify the department number on the Salesperson numbers card (' . implode(', ', $g)
            . ') for the instance it now has: until it is, no salesperson number answers or sends anything automated.';
    }

    /**
     * 5.18.90: the departments whose number is not yet verified for the instance they are configured with ([] = all
     * verified). Read here from the registry and the configuration, never through a service that may not have read them.
     *
     * @return string[]
     */
    public function departmentGaps(): array
    {
        try {
            return \InternalNumbers::gapsWithReported($this->reg->rows(), \EvolutionApiService::configInstanceMap($this->config),
                                                      $this->reg->verifiedInstances(), $this->store);
        } catch (\Throwable $e) {
            return ['unreadable'];
        }
    }

    private function webhook(array $row, callable $webhookUrl): array
    {
        $url = (string)$webhookUrl();
        if ($url === '') {
            return self::no('Cannot work out this plugin\'s public address — set it under Plugin address, then try again.');
        }
        $r = $this->evo->setWebhook((string)$row['evo_instance'], $url);
        return !empty($r['ok'])
            ? self::yes('Evolution will now send the messages of ' . (string)$row['display_name'] . ' to this plugin.')
            : self::no('Evolution refused: ' . (string)($r['error'] ?? ''));
    }

    private function ai(array $row, array $post, string $actor, string $reason): array
    {
        $on = (string)($post['value'] ?? '') === '1';
        $this->reg->setAiEnabled((string)$row['channel_id'], $on, $actor,
            $reason !== '' ? $reason : ($on ? 'assistant switched on' : 'assistant switched off'));
        return self::yes($on ? 'The assistant answers on ' . (string)$row['display_name'] . ' while the number is on.'
                             : 'The assistant no longer answers on ' . (string)$row['display_name'] . '; messages are kept.');
    }

    private function status(array $row, array $post, string $actor, string $reason): array
    {
        $to = (string)($post['status'] ?? '');
        if (!in_array($to, \ChannelRegistry::STATUSES, true)) return self::no('Unknown status.');
        $id = (string)$row['channel_id'];
        if ($to === 'active') {
            if (!$this->registryOn()) {
                return self::no('Not yet: switch the channel registry on first (multi_number_channels_enabled) and check '
                              . 'the three department numbers on it. Then switch this number on.');
            }
            if (trim((string)($row['business_number'] ?? '')) === '' || trim((string)($row['verified_at'] ?? '')) === '') {
                return self::no('Verify the number first: pair the phone, then press Verify number.');
            }
            // 5.18.90 (docs/65 §AD.9): what Evolution last reported for this number's own instance, as the policy reads it
            // — a re-paired phone, or an owner with no phone number, is refused here as the policy would refuse it.
            $now = $this->reportedFor((string)($row['evo_instance'] ?? ''));
            if ($now !== '' && $now !== (string)preg_replace('/\D+/', '', (string)$row['business_number'])) {
                return self::no($now === \InternalNumbers::NO_PHONE
                    ? 'Evolution reports this instance\'s owner with no phone number (a WhatsApp @lid), so its number cannot be confirmed. Pair the phone again with Show QR code, then verify.'
                    : 'Evolution now reports another number for this instance: verify the number again first.');
            }
            if (!$this->ownerActive($row)) {
                return self::no('Its owner is not an active staff member: give the number a new owner first.');
            }
            $gaps = $this->departmentGaps();   // 5.18.90 (docs/65 §AD)
            if ($gaps !== []) {
                return self::no('Not yet: verify the department numbers first (' . implode(', ', $gaps) . ') — Verify number on '
                              . 'each department below — so this number can never answer one of them.');
            }
        }
        if ($to === 'retired' && (string)($post['confirm'] ?? '') !== 'yes') {
            return self::no('Tick the box to confirm: a retired number is never used again.');
        }
        $this->reg->setStatus($id, $to, $actor, $reason !== '' ? $reason : 'set on the WhatsApp AI screen');
        $said = ['active' => 'switched on', 'paused' => 'paused', 'disabled' => 'switched off', 'retired' => 'retired'];
        return self::yes((string)$row['display_name'] . ' is ' . $said[$to] . '.');
    }

    private function owner(array $row, array $post, string $actor, string $reason): array
    {
        $staffId = (int)($post['owner_staff_id'] ?? 0);
        $person  = $this->salesperson($staffId);
        $this->reg->setOwner((string)$row['channel_id'], $staffId, $actor,
            $reason !== '' ? $reason : 'new owner set on the WhatsApp AI screen');
        return self::yes((string)$row['display_name'] . ' now belongs to ' . trim((string)($person['name'] ?? '')) . '.');
    }

    // ── What the card shows ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Every registry number: the three departments first (read-only here), then the others, oldest first. The business
     * number is masked; the trail is the last three rows.
     *
     * @param array $detected EvolutionApiService::listInstances(), as the screen read it ([] when Evolution was not reached)
     * @return array<int,array>
     */
    public function view(array $detected): array
    {
        $live = [];
        foreach ($detected as $d) if (is_array($d)) $live[(string)($d['name'] ?? '')] = $d;
        $staff = $this->staffById();
        $cfg   = \EvolutionApiService::configInstanceMap($this->config);
        // 5.18.90 (docs/65 §AD): which department takes each configured instance's inbound (its number covers the
        // others on that instance), and which department numbers still need verifying.
        $firstOf = [];
        foreach (\ChannelRegistry::DEPARTMENT as $d) {
            $k = mb_strtolower(trim((string)($cfg[$d] ?? '')));
            if ($k !== '' && !isset($firstOf[$k])) $firstOf[$k] = $d;
        }
        $gaps = $this->departmentGaps();
        $out = [];
        foreach ($this->reg->rows() as $id => $r) {
            $id   = (string)$id;
            $dept = in_array($id, \ChannelRegistry::DEPARTMENT, true);
            $inst = $dept ? (string)($cfg[$id] ?? '') : (string)($r['evo_instance'] ?? '');
            $owner = null;
            if ((string)($r['owner_type'] ?? '') === 'staff') {
                $o = $staff[(int)($r['owner_staff_id'] ?? 0)] ?? null;
                $owner = ['id' => (int)($r['owner_staff_id'] ?? 0), 'name' => $o !== null ? trim((string)($o['name'] ?? '')) : '',
                          'active' => $o !== null && \StaffDirectory::isActive($o)];
            }
            $out[] = [
                'id'           => $id,
                'department'   => $dept,
                'display_name' => (string)($r['display_name'] ?? $id),
                'instance'     => $inst,
                'state'        => $inst === '' ? 'no instance' : (string)($live[$inst]['state'] ?? ($live ? 'not in Evolution' : 'unknown')),
                'connected'    => $inst !== '' && !empty($live[$inst]['connected']),
                'number'       => \ChannelRegistry::mask(isset($r['business_number']) ? (string)$r['business_number'] : null),
                'verified_at'  => (string)($r['verified_at'] ?? ''),
                'verified_by'  => (string)($r['verified_by'] ?? ''),
                'status'       => (string)($r['status'] ?? ''),
                'ai'           => (int)($r['ai_enabled'] ?? 0) === 1,
                'handover_to'  => (string)($r['handover_to'] ?? ''),
                'owner'        => $owner,
                'trail'        => array_slice($this->reg->trail($id), -3),
                // 5.18.90: a department number's own verification, and a number Evolution now reports differently.
                'covered_by'   => $dept && $inst !== '' ? (string)($firstOf[mb_strtolower($inst)] ?? $id) : '',
                'needs_verify' => $dept && in_array($id, $gaps, true),
                'live_differs' => self::liveReason($live[$inst] ?? null, isset($r['business_number']) ? (string)$r['business_number'] : '') !== '',
                'live_reason'  => self::liveReason($live[$inst] ?? null, isset($r['business_number']) ? (string)$r['business_number'] : ''),
            ];
        }
        return $out;
    }

    /**
     * 5.18.90: why the number recorded can no longer be confirmed from Evolution's live report — 'number': the instance is
     * paired with another number; 'no_phone': with an owner that has no phone number (an @lid); '' when it can.
     */
    private static function liveReason(?array $live, string $recorded): string
    {
        $rec = (string)preg_replace('/\D+/', '', $recorded);
        if ($rec === '' || $live === null) return '';
        $now = self::phoneOf($live);
        if ($now !== '') return $rec !== $now ? 'number' : '';
        return self::lidOwner($live) ? 'no_phone' : '';
    }

    /** 5.18.90: what the record says Evolution last reported for $instance — InternalNumbers::reportedFor, from the store. */
    private function reportedFor(string $instance): string
    {
        if ($this->store === null || trim($instance) === '') return '';
        $n = \InternalNumbers::fromRows([], [], [], [], null);
        $n->useStore($this->store);
        return $n->reportedFor($instance);
    }

    /** The instances a new number may use: reported by Evolution, and nobody's yet. @return string[] */
    public function freeInstances(): array
    {
        $taken = [];
        foreach (\EvolutionApiService::configInstanceMap($this->config) as $i) {
            if (trim((string)$i) !== '') $taken[mb_strtolower(trim((string)$i))] = true;
        }
        foreach ($this->reg->rows() as $r) {
            $i = trim((string)($r['evo_instance'] ?? ''));
            if ($i !== '') $taken[mb_strtolower($i)] = true;
        }
        $out = [];
        foreach (array_keys($this->live()) as $name) {
            if (!isset($taken[mb_strtolower((string)$name)])) $out[] = (string)$name;
        }
        return $out;
    }

    /** Which number uses an instance, for the "Found in Evolution" card: lower-case instance => channel id. */
    public function instanceOwners(): array
    {
        $out = [];
        foreach ($this->reg->rows() as $id => $r) {
            $i = trim((string)($r['evo_instance'] ?? ''));
            if ($i !== '') $out[mb_strtolower($i)] = (string)$id;
        }
        return $out;
    }

    /** The people a number may belong to: active staff with a sales role. @return array<int,string> id => name */
    public function salespeople(): array
    {
        $out = [];
        foreach ($this->staffById() as $id => $r) {
            if (\StaffDirectory::isActive($r) && in_array(\StaffDirectory::role($r), self::SALES_ROLES, true)) {
                $out[$id] = trim((string)($r['name'] ?? '')) !== '' ? trim((string)$r['name']) : ('staff #' . $id);
            }
        }
        asort($out);
        return $out;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────────────────

    /** A number this card may change: it exists, it is not a department's, and it is not retired. */
    private function channel(array $post): array
    {
        $id = trim((string)($post['channel_id'] ?? ''));
        if (in_array($id, \ChannelRegistry::DEPARTMENT, true)) {
            throw new \InvalidArgumentException('The department numbers are changed under Numbers, not here.');
        }
        if (!preg_match(\ChannelRegistry::ID_PATTERN, $id)) throw new \InvalidArgumentException('Unknown number.');
        $row = $this->reg->row($id);
        if ($row === null) throw new \InvalidArgumentException('Unknown number.');
        if ((string)($row['status'] ?? '') === 'retired') {
            throw new \InvalidArgumentException((string)$row['display_name'] . ' is retired and takes no further change.');
        }
        return $row;
    }

    /** 5.18.90: a department id from the form, or a refusal. */
    private function department(array $post): string
    {
        $id = trim((string)($post['channel_id'] ?? ''));
        if (!in_array($id, \ChannelRegistry::DEPARTMENT, true)) throw new \InvalidArgumentException('Unknown department number.');
        return $id;
    }

    /**
     * 5.18.90 (docs/65 §AD): the phone number an instance's report names — digits, or '' when the owner is an @lid (no
     * phone number at all: nothing to verify, and a gap stays open). A report without 'jid_phone' is read as before.
     */
    private static function phoneOf(array $live): string
    {
        $raw = array_key_exists('jid_phone', $live) ? (string)$live['jid_phone'] : (string)($live['phone'] ?? '');
        return (string)preg_replace('/\D+/', '', $raw);
    }

    /** An owner Evolution reports with digits but no phone JID: an @lid, which has no phone number at all. */
    private static function lidOwner(array $live): bool
    {
        return array_key_exists('jid_phone', $live) && (string)$live['jid_phone'] === ''
            && (string)preg_replace('/\D+/', '', (string)($live['phone'] ?? '')) !== '';
    }

    /** Why there is nothing to verify: an @lid owner never has a phone number; an owner not reported yet may soon. */
    private static function noPhone(array $live): string
    {
        return self::lidOwner($live)
            ? 'Evolution reports this instance\'s owner with no phone number (a WhatsApp @lid), so it cannot be verified. Pair the phone again with Show QR code, then verify.'
            : 'Evolution reports no phone number for this instance yet — try again in a minute.';
    }

    /**
     * 5.18.90 (docs/65 §AD): a number just verified from Evolution's live report is never contradicted by an older one —
     * what Evolution reports now is recorded, as the webhook guard records it every run. A failure leaves the guard's last.
     */
    private function refreshReported(): void
    {
        try {
            if ($this->store !== null && $this->live() !== []) \InternalNumbers::recordReported($this->store, array_values($this->live()));
        } catch (\Throwable $e) {
            error_log('[channels] the numbers Evolution reports could not be recorded after a verification: ' . $e->getMessage());
        }
    }

    /** An active staff row with a sales role, or a refusal. */
    private function salesperson(int $staffId): array
    {
        $r = $this->staffById()[$staffId] ?? null;
        if ($r === null) throw new \InvalidArgumentException('Choose the salesperson the number belongs to.');
        if (!\StaffDirectory::isActive($r)) throw new \InvalidArgumentException('That staff member is not active.');
        if (!in_array(\StaffDirectory::role($r), self::SALES_ROLES, true)) {
            throw new \InvalidArgumentException('A salesperson\'s number belongs to a salesperson (role sales, sales_staff or field_agent).');
        }
        return $r;
    }

    private function ownerActive(array $row): bool
    {
        if ((string)($row['owner_type'] ?? '') !== 'staff') return false;
        $o = $this->staffById()[(int)($row['owner_staff_id'] ?? 0)] ?? null;
        return $o !== null && \StaffDirectory::isActive($o);
    }

    /** @return array<int,array> retailers.json rows by id */
    private function staffById(): array
    {
        $out = [];
        foreach ((array)($this->store !== null ? ($this->store->load('retailers.json') ?? []) : []) as $r) {
            if (is_array($r) && (int)($r['id'] ?? 0) > 0) $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** @return array<string,array> Evolution's instances by name */
    private function live(): array
    {
        if ($this->live === null) {
            $this->live = [];
            foreach ($this->evo->listInstances() as $d) $this->live[(string)$d['name']] = $d;
        }
        return $this->live;
    }

    private function nextId(): string
    {
        $max = 0;
        foreach (array_keys($this->reg->rows()) as $id) {
            if (preg_match('/^' . preg_quote(self::ID_PREFIX, '/') . '(\d+)$/', (string)$id, $m)) $max = max($max, (int)$m[1]);
        }
        return sprintf('%s%03d', self::ID_PREFIX, $max + 1);
    }

    /** Who made a change, as the trail names them: the admin's name and staff id. */
    private static function actorOf(array $admin): string
    {
        $name = trim((string)($admin['name'] ?? ''));
        $id   = (int)($admin['id'] ?? 0);
        return ($name !== '' ? $name : 'admin') . ' (#' . $id . ')';
    }

    private static function yes(string $text): array { return ['ok' => true, 'text' => $text]; }
    private static function no(string $text): array { return ['ok' => false, 'text' => $text]; }
}
