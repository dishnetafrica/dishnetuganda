<?php
declare(strict_types=1);

/**
 * InternalNumbers — DishNet's own WhatsApp numbers, and the people a salesperson number belongs to (5.18.90, docs/65 §AD).
 *
 * The pre-pilot safety fix. One DishNet line answering another — an AI salesperson's number answering a department's,
 * or two salesperson numbers answering each other once the human pause ran out — was possible because nothing on the
 * inbound path knew DishNet's own numbers: the J8 colleague rule (StaffDirectory::STAFF_ROLES) knows staff, not lines.
 * This class is the one place that knows them. It defines nothing new: every number in it comes from a record the
 * plugin already keeps.
 *
 *   DISHNET LINES (refused on every number, departments included)
 *     - wa_channels.business_number of every channel that is not retired — the salesperson numbers verified on the card,
 *       and, since 5.18.90, the three department numbers verified there too (read from Evolution's own report, never
 *       typed);
 *     - the DishNet numbers the configuration already names: wa_support_number, wa_accounts_number (the WASender-era
 *       lines), web_chat_whatsapp and evo_number_sales (the website's hand-off number);
 *     - the number Evolution reports for each of its instances, as cron/wa_webhook_guard.php last read them (it reads
 *       them every run anyway) — so a number re-paired to another phone, a department moved to a new instance, or a line
 *       this plugin does not route is DishNet's own within one guard run, before anyone verifies anything. Membership
 *       only: it never counts as a verified number and never closes a gap.
 *   PEOPLE ON DISHNET'S SIDE (refused on a salesperson's number only — a department keeps the J8 rule exactly)
 *     - the phone of record of the active owner of every salesperson number that is not retired (the ownership model);
 *     - the configured internal alert recipients, alert_whatsapp and whatsapp_admin_phone.
 *
 * Matching is the whole number, digits only, exact — never the last nine digits, a prefix, a name, the text, the time
 * or the pause. Fewer than eight digits never matches. An @lid owner's digits are never a number: such an instance is
 * recorded as reporting NO phone number, which matches no recorded number — so a row verified on it counts as unverified
 * again, and a department on it as a gap, until it is verified (which an @lid owner cannot be).
 *
 * The configured numbers are read from the configuration given and, where it lacks one, from the plugin store's own
 * kyc_config — where the Settings form saves wa_support_number, wa_accounts_number and whatsapp_admin_phone on an
 * SQLite install — exactly as cron/wa_watchdog.php fills its configuration.
 *
 * The lines come from the same wa_channels read the routing is built from, so they cannot be "unreadable" while the
 * registry routes anything (EvolutionApiService::forStore falls back to the three departments as configured when that
 * read fails, and no salesperson number is then received at all). The people and Evolution's reported numbers are read
 * lazily from the plugin store (retailers.json, the tenant profile, wa_evo_numbers.json); a failure there adds nobody
 * and says so once in the log — it never refuses a customer.
 *
 * Completeness (gaps): every department instance the configuration names has its number recorded on the department
 * that takes its inbound (the first of sales, support, account naming it — routing's own rule; support and account share
 * one in production), verified FOR THAT INSTANCE. A department moved to another instance since its number was verified
 * is a gap again; so is one whose instance Evolution now reports with ANOTHER number (re-paired to another phone, as the
 * webhook guard last read it). While any gap remains no salesperson number answers or sends anything automated: it would
 * not recognise that department's number.
 *
 * Never logs a number. PHP 7.4 compatible.
 */
final class InternalNumbers
{
    const DISHNET_LINE = 'dishnet_line';
    const LINE_OWNER   = 'line_owner';
    const ALERT_NUMBER = 'alert_number';

    /** Configuration keys that name one of DishNet's own WhatsApp numbers. */
    const CONFIG_LINE_KEYS = ['wa_support_number', 'wa_accounts_number', 'web_chat_whatsapp', 'evo_number_sales'];
    /** Configuration keys that name an internal alert recipient: a person on DishNet's side. */
    const ALERT_KEYS = ['alert_whatsapp', 'whatsapp_admin_phone'];
    /** The departments, in the order the inbound map has always been built. */
    const DEPARTMENTS = ['sales', 'support', 'account'];
    /** What reportedFor() says for an instance whose owner Evolution reports with no phone number (an @lid): never a number. */
    const NO_PHONE = 'no-phone';
    /** Nothing shorter is a whole number. */
    const MIN_DIGITS = 8;
    /**
     * The store file cron/wa_webhook_guard.php writes: the number Evolution reports for each instance, as a LIST of
     * {number, instance, at} records — SqliteStore keeps a list's records whole, where a keyed object outside its
     * $FLAT_TABLES would lose its keys on the way back.
     */
    const REPORTED_FILE = 'wa_evo_numbers.json';

    /** @var array<string,string> digits => channel id ('' for a configured number) */
    private array $lines;
    /** @var string[] department ids whose number is not recorded for their instance */
    private array $gaps;
    /** @var array<string,array{0:string,1:string}> department => [its instance, lower case; its recorded number, digits] */
    private array $deptNumbers = [];
    private array $config;
    private ?string $dataDir;
    /** @var array<string,true> the staff ids that own a salesperson number */
    private array $ownerIds;
    /** @var mixed the plugin store (retailers.json), or null */
    private $store = null;
    /** @var array<string,string>|null digits => class, built on first use */
    private ?array $people = null;
    /** @var array<string,true>|null every number Evolution last reported, for any instance; read on first use */
    private ?array $reported = null;
    /** @var array<string,string>|null lower-case instance => the number Evolution last reported for it */
    private ?array $reportedBy = null;
    /** @var array<string,true>|null the configured DishNet numbers (CONFIG_LINE_KEYS), built on first use */
    private ?array $configLines = null;
    /** @var array|null the configuration, gap-filled from the store's kyc_config; built on first use */
    private ?array $merged = null;

    /** @var array<string,bool> */
    private static array $said = [];

    private function __construct(array $lines, array $gaps, array $ownerIds, array $config, ?string $dataDir)
    {
        $this->lines    = $lines;
        $this->gaps     = $gaps;
        $this->ownerIds = $ownerIds;
        $this->config   = $config;
        $this->dataDir  = $dataDir;
    }

    /**
     * @param array<string,array>  $rows      wa_channels rows by channel id (ChannelRegistry::rows())
     * @param array<string,string> $configMap department => configured instance (EvolutionApiService::configInstanceMap)
     * @param array<string,string> $verified  department => the instance its number was verified for
     *                                        (ChannelRegistry::verifiedInstances())
     */
    public static function fromRows(array $rows, array $configMap, array $verified, array $config, ?string $dataDir): self
    {
        $lines = [];
        $owners = [];
        foreach ($rows as $id => $r) {
            if (!is_array($r) || (string)($r['status'] ?? '') === 'retired') continue;
            $d = self::digits((string)($r['business_number'] ?? ''));
            if ($d !== '') $lines[$d] = (string)$id;
            if ((string)($r['owner_type'] ?? '') === 'staff' && (int)($r['owner_staff_id'] ?? 0) > 0) {
                $owners[(string)(int)$r['owner_staff_id']] = true;
            }
        }
        $n = new self($lines, self::gapsOf($rows, $configMap, $verified), $owners, $config, $dataDir);
        $seen = [];
        foreach (self::DEPARTMENTS as $dept) {   // the departments that take an instance's inbound, as gapsOf reads them
            $inst = mb_strtolower(trim((string)($configMap[$dept] ?? '')));
            if ($inst === '' || isset($seen[$inst])) continue;
            $seen[$inst] = true;
            $n->deptNumbers[$dept] = [$inst, self::digits((string)($rows[$dept]['business_number'] ?? ''))];
        }
        return $n;
    }

    /**
     * The gaps exactly as the policy counts them (gaps()): gapsOf, and every department whose instance Evolution now
     * reports with another number, or with none, as $store last recorded it. For the card's gate and note,
     * tools/channels.php and tools/set_config.php — so what they say is what the automated paths enforce. No store: gapsOf.
     *
     * @param mixed $store anything with load(file): the plugin store, or InternalNumbers::reader()
     * @return string[]
     */
    public static function gapsWithReported(array $rows, array $configMap, array $verified, $store): array
    {
        $n = self::fromRows($rows, $configMap, $verified, [], null);
        $n->useStore($store);
        return $n->gaps();
    }

    /**
     * A read-only stand-in for the plugin store that reads only the numbers Evolution reports, straight from the plugin
     * database (as SqliteStore keeps a list of records: one JSON row each). For tools/channels.php, which writes nothing —
     * opening SqliteStore would run the migrations. A missing table reads as nothing recorded.
     */
    public static function reader(\PDO $pdo)
    {
        return new class($pdo) {
            private $pdo;
            public function __construct(\PDO $pdo) { $this->pdo = $pdo; }
            public function load(string $file): array
            {
                if ($file !== InternalNumbers::REPORTED_FILE) return [];
                $t = (string)preg_replace('/[^a-zA-Z0-9_]/', '_', (string)preg_replace('/\.json$/', '', $file));
                $q = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
                $q->execute([$t]);
                if ($q->fetchColumn() === false) return [];
                $out = [];
                foreach ($this->pdo->query('SELECT data FROM [' . $t . '] ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $j) {
                    $r = json_decode((string)$j, true);
                    if (is_array($r)) $out[] = $r;
                }
                return $out;
            }
        };
    }

    /**
     * The departments whose number is not recorded for the instance they are configured with. Not a re-paired phone: for
     * that, gapsWithReported().
     *
     * @return string[]
     */
    public static function gapsOf(array $rows, array $configMap, array $verified): array
    {
        $gaps = [];
        $seen = [];
        foreach (self::DEPARTMENTS as $dept) {
            $inst = mb_strtolower(trim((string)($configMap[$dept] ?? '')));
            if ($inst === '' || isset($seen[$inst])) continue;   // no instance, or an earlier department takes its inbound
            $seen[$inst] = true;
            $number = self::digits((string)($rows[$dept]['business_number'] ?? ''));
            $for    = mb_strtolower(trim((string)($verified[$dept] ?? '')));
            if ($number === '' || $for !== $inst) $gaps[] = $dept;
        }
        return $gaps;
    }

    /** The people's numbers need the plugin store; without it they are simply not known (never a refusal). */
    public function useStore($store): void
    {
        if ($store !== null && $this->store === null) {
            $this->store       = $store;
            $this->people      = null;
            $this->reported    = null;
            $this->reportedBy  = null;
            $this->configLines = null;
            $this->merged      = null;
        }
    }

    /**
     * Why this sender is DishNet's own: '' for a customer; otherwise dishnet_line (on any channel), or line_owner /
     * alert_number (only when $linesOnly is false: a salesperson's number — on a department the J8 rule stands as it
     * was, and a channel that is no salesperson number is never asked about people).
     */
    public function senderClass(string $phone, bool $linesOnly): string
    {
        $d = self::digits($phone);
        if ($d === '') return '';
        if (isset($this->lines[$d]) || isset($this->configLines()[$d]) || isset($this->reported()[$d])) return self::DISHNET_LINE;
        if ($linesOnly) return '';
        $p = $this->people();
        return $p[$d] ?? '';
    }

    /** The channel whose number this is ('' when it is not a registry line's): for a log line, never the number. */
    public function lineOf(string $phone): string
    {
        $d = self::digits($phone);
        return $d !== '' && isset($this->lines[$d]) ? $this->lines[$d] : '';
    }

    /**
     * @return string[] the departments whose number is not recorded for their instance, or whose instance Evolution now
     *                  reports with another number ([] = complete)
     */
    public function gaps(): array
    {
        $g = $this->gaps;
        foreach ($this->deptNumbers as $dept => [$inst, $digits]) {
            if (in_array($dept, $g, true) || $digits === '') continue;
            $now = $this->reportedFor($inst);
            if ($now !== '' && $now !== $digits) $g[] = $dept;   // re-paired since it was verified (NO_PHONE included)
        }
        return array_values(array_intersect(self::DEPARTMENTS, $g));
    }

    /**
     * The number Evolution last reported for $instance (digits); NO_PHONE when it reported the owner with no phone number
     * (an @lid), which equals no recorded number; '' when nothing is recorded for it.
     */
    public function reportedFor(string $instance): string
    {
        $i = mb_strtolower(trim($instance));
        if ($i === '') return '';
        $this->reported();
        return (string)($this->reportedBy[$i] ?? '');
    }

    public function complete(): bool
    {
        return $this->gaps() === [];
    }

    /**
     * Record the number Evolution reports for each of its instances (cron/wa_webhook_guard.php, every run, with the
     * channel registry on; and the card, at every verification). One record PER INSTANCE — two instances may report the
     * same number (one phone, two linked devices), and each must be compared with its own row. The whole list is
     * replaced: a number no instance reports any more is no longer one of these (a verified number stays a line through
     * wa_channels). An @lid owner is recorded as reporting NO phone number (never its digits); a short number, or an
     * instance with no owner at all, is left out.
     *
     * @param mixed $store
     * @param array $instances EvolutionApiService::listInstances()
     * @return int how many phone numbers were recorded
     */
    public static function recordReported($store, array $instances): int
    {
        $rows = [];
        $at   = gmdate('Y-m-d H:i:s');
        foreach ($instances as $i) {
            if (!is_array($i)) continue;
            $d    = self::digits((string)($i['jid_phone'] ?? ''));
            $name = trim((string)($i['name'] ?? ''));
            if ($name === '') continue;
            if ($d !== '') {
                $rows[mb_strtolower($name)] = ['number' => $d, 'instance' => $name, 'at' => $at];
            } elseif (array_key_exists('jid_phone', $i) && self::digits((string)($i['phone'] ?? '')) !== '') {
                // An owner with digits but no phone JID: an @lid. Its number cannot be known, so nothing verified on this
                // instance can be confirmed any more.
                $rows[mb_strtolower($name)] = ['number' => '', 'no_phone' => true, 'instance' => $name, 'at' => $at];
            }
        }
        $store->save(self::REPORTED_FILE, array_values($rows));
        return count(array_filter($rows, function ($r) { return $r['number'] !== ''; }));
    }

    /** @return array<string,true> the numbers the configuration names as DishNet's own lines */
    private function configLines(): array
    {
        if ($this->configLines !== null) return $this->configLines;
        $out = [];
        $cfg = $this->cfg();
        foreach (self::CONFIG_LINE_KEYS as $k) {
            $d = self::configured($cfg, $this->dataDir, (string)($cfg[$k] ?? ''));
            if ($d !== '') $out[$d] = true;
        }
        return $this->configLines = $out;
    }

    /** The configuration given, each empty or missing key filled from the store's kyc_config (as wa_watchdog does). */
    private function cfg(): array
    {
        if ($this->merged !== null) return $this->merged;
        $cfg = $this->config;
        if ($this->store !== null) {
            try {
                foreach ((array)($this->store->load('kyc_config.json') ?? []) as $k => $v) {
                    if ($v === null || $v === '' || is_array($v)) continue;
                    if (!array_key_exists($k, $cfg) || $cfg[$k] === '' || $cfg[$k] === null) $cfg[$k] = $v;
                }
            } catch (\Throwable $e) {
                self::sayOnce('[channels] the stored settings could not be read, so only the configuration files\' DishNet '
                            . 'numbers are recognised: ' . $e->getMessage());
            }
        }
        return $this->merged = $cfg;
    }

    /** @return array<string,true> every reported number; [] without a store, or when it cannot be read */
    private function reported(): array
    {
        if ($this->reported !== null) return $this->reported;
        if ($this->store === null) return [];
        $out = []; $by = [];
        try {
            foreach ((array)($this->store->load(self::REPORTED_FILE) ?? []) as $r) {
                if (!is_array($r)) continue;
                $inst = mb_strtolower(trim((string)($r['instance'] ?? '')));
                if (!empty($r['no_phone'])) {          // never a member: there is no number to recognise
                    if ($inst !== '') $by[$inst] = self::NO_PHONE;
                    continue;
                }
                $d = self::digits((string)($r['number'] ?? ''));
                if ($d === '') continue;
                $out[$d] = true;
                if ($inst !== '') $by[$inst] = $d;
            }
        } catch (\Throwable $e) {
            self::sayOnce('[channels] the numbers Evolution reports could not be read, so only the recorded DishNet lines '
                        . 'are recognised: ' . $e->getMessage());
        }
        $this->reportedBy = $by;
        return $this->reported = $out;
    }

    /** @return array<string,string> digits => class */
    private function people(): array
    {
        if ($this->people !== null) return $this->people;
        $out = [];
        try {
            $cfg = $this->cfg();
            foreach (self::ALERT_KEYS as $k) {
                $d = self::configured($cfg, $this->dataDir, (string)($cfg[$k] ?? ''));
                if ($d !== '') $out[$d] = self::ALERT_NUMBER;
            }
            if ($this->ownerIds !== [] && $this->store !== null) {
                if (!class_exists('StaffDirectory')) require_once __DIR__ . '/StaffDirectory.php';
                $tenant = self::tenant($this->config, $this->dataDir);
                foreach ((array)($this->store->load('retailers.json') ?? []) as $s) {
                    if (!is_array($s) || !isset($this->ownerIds[(string)(int)($s['id'] ?? 0)]) || !\StaffDirectory::isActive($s)) continue;
                    $d = $tenant !== null ? self::digits((string)\StaffDirectory::phoneOf($s, $tenant)) : '';
                    if ($d !== '' && !isset($out[$d])) $out[$d] = self::LINE_OWNER;
                }
            }
        } catch (\Throwable $e) {
            self::sayOnce('[channels] the owners\' and alert numbers could not be read, so only DishNet\'s own lines are '
                        . 'recognised on the salesperson numbers: ' . $e->getMessage());
        }
        return $this->people = $out;
    }

    /** A configured number in whole international form, digits only; '' when it is empty or cannot be read. */
    private static function configured(array $config, ?string $dataDir, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        try {
            $tenant = self::tenant($config, $dataDir);
            if ($tenant !== null) {
                if (!class_exists('PhoneNumber')) require_once __DIR__ . '/PhoneNumber.php';
                $n = \PhoneNumber::international($raw, $tenant);
                if ($n !== null) return self::digits($n);
            }
        } catch (\Throwable $e) { /* the raw digits below */ }
        return self::digits($raw);
    }

    /** @return \TenantProfile|null */
    private static function tenant(array $config, ?string $dataDir)
    {
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            return \TenantProfile::current($config, $dataDir);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function digits(string $s): string
    {
        $d = (string)preg_replace('/\D+/', '', $s);
        return strlen($d) >= self::MIN_DIGITS ? $d : '';
    }

    private static function sayOnce(string $line): void
    {
        if (isset(self::$said[$line])) return;
        self::$said[$line] = true;
        error_log($line);
    }
}
