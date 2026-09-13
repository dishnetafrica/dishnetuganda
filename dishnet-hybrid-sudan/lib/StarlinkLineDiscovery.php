<?php
declare(strict_types=1);

require_once __DIR__ . '/EquipmentAssignment.php';
require_once __DIR__ . '/StarlinkServiceState.php';
require_once __DIR__ . '/StarlinkPortalConnector.php';
require_once __DIR__ . '/SecureFile.php';

/**
 * StarlinkLineDiscovery — ask Starlink which kit is on which line.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 *
 * The typed map works, and four fifths of South Sudan's fleet runs on one.
 * But `tools/dr_kit_map.php --paste` exported exactly one pair for Uganda,
 * because we only know a pairing where somebody recorded both halves at the
 * install. Fifteen lines, one pairing, and the other fourteen were going to
 * be typed by hand. That is a chore a machine should do.
 *
 * Starlink already knows. /api/webagg/v2/accounts/service-lines returns each
 * line with its terminal nested inside it. So we ask.
 *
 * ── PAIRING BY POSITION, NOT BY FIELD NAME ──────────────────────────────
 *
 * We do not know what Starlink calls the field, and the name has changed
 * before. Every guess at a field name is a thing that breaks silently the
 * day it is renamed — and silently is the problem, because a discovery that
 * returns nothing looks exactly like a fleet with nothing to discover.
 *
 * So the shape is ignored and the structure is used: walk the payload, and
 * the SMALLEST subtree containing exactly one SL-… and some KIT-… is the
 * pairing. That is true of any JSON where a line carries its own terminals,
 * whatever the keys are called, and it stays true when they are renamed.
 *
 * Identifiers are recognised by EquipmentAssignment::shapeOf(), which is the
 * same classifier the assignment form already trusts. No new pattern.
 *
 * ── WHERE IT REFUSES TO GUESS ───────────────────────────────────────────
 *
 * One line with two terminals is not a pairing, it is a question: a dish was
 * swapped and we cannot tell from the payload which one is fitted now.
 * Guessing there files a customer's usage under a kit sitting in a box. Those
 * are returned as ambiguous, for a person, and never stored.
 *
 * ── WHAT IT WILL NOT FIND ───────────────────────────────────────────────
 *
 * A line Starlink has no terminal against. Uganda's lines came back
 * `userTerminals: []` with `pendingActivation: true` — the pairing does not
 * exist yet at Starlink either, so nothing can discover it and the typed map
 * remains the only answer. The report says which lines those are and why,
 * because "found nothing" and "there is nothing to find" are different
 * problems and only one of them is fixed by asking again.
 */
final class StarlinkLineDiscovery
{
    /** Discovered pairs, kept apart from the typed map so neither overwrites the other. */
    public const FILE = 'sl_kit_discovered.json';

    /** Flags worth repeating back when a line has no terminal. */
    private const NOTE_KEYS = ['pendingactivation', 'active', 'hastelemetryaccess',
                               'servicelinestatus', 'status'];

    private StarlinkSessionStore $store;
    private array $config;
    /** @var callable|null */
    private $http;

    public function __construct(StarlinkSessionStore $store, array $config, ?callable $http = null)
    {
        $this->store  = $store;
        $this->config = $config;
        $this->http   = $http;
    }

    // ── The walk ────────────────────────────────────────────────────────────

    /**
     * Pair kits to lines by where they sit in the payload.
     *
     * @return array{pairs:array<string,string>, ambiguous:array<string,array<int,string>>,
     *               lines:array<int,string>, notes:array<string,array<string,mixed>>}
     */
    public static function pairsFrom(array $payload): array
    {
        $pairs = []; $ambiguous = []; $notes = [];
        $seen  = self::scan($payload, $pairs, $ambiguous, $notes);
        $lines = array_keys($seen['sls']);
        sort($lines);
        return ['pairs' => $pairs, 'ambiguous' => $ambiguous, 'lines' => $lines, 'notes' => $notes];
    }

    /**
     * @return array{sls:array<string,true>, kits:array<string,true>}
     */
    private static function scan($node, array &$pairs, array &$ambiguous, array &$notes): array
    {
        $sls = []; $kits = []; $flags = [];
        if (!is_array($node)) return ['sls' => $sls, 'kits' => $kits];

        foreach ($node as $key => $v) {
            if (is_array($v)) {
                $r = self::scan($v, $pairs, $ambiguous, $notes);
                foreach ($r['sls']  as $s => $_) $sls[$s]  = true;
                foreach ($r['kits'] as $k => $_) $kits[$k] = true;
                continue;
            }
            if (is_bool($v) || is_string($v) || is_numeric($v)) {
                if (is_string($key)
                    && in_array(strtolower($key), self::NOTE_KEYS, true)) {
                    $flags[$key] = $v;
                }
            }
            if (!is_string($v) && !is_numeric($v)) continue;

            switch (EquipmentAssignment::shapeOf((string)$v)) {
                case 'starlink_service_line':
                    $sls[StarlinkServiceState::normalise((string)$v)] = true;  break;
                case 'kit_serial':
                    $kits[EquipmentAssignment::clean((string)$v)] = true;      break;
            }
        }

        // The smallest scope holding exactly one line is that line's own record.
        if (count($sls) === 1) {
            $sl = (string)array_key_first($sls);
            if ($flags !== [] && !isset($notes[$sl])) $notes[$sl] = $flags;

            $unclaimed = [];
            foreach (array_keys($kits) as $k) {
                if (!isset($pairs[$k])) $unclaimed[] = $k;
            }
            if (count($unclaimed) === 1 && !isset($ambiguous[$sl])) {
                $pairs[$unclaimed[0]] = $sl;
            } elseif (count($unclaimed) > 1) {
                // A swapped dish. Which one is fitted now is not in here.
                sort($unclaimed);
                $ambiguous[$sl] = $unclaimed;
            }
        }
        return ['sls' => $sls, 'kits' => $kits];
    }

    // ── Asking ──────────────────────────────────────────────────────────────

    /**
     * Ask every account we hold a session for, and restore the selection.
     *
     * @return array{pairs:array<string,string>, ambiguous:array<string,array<int,string>>,
     *               report:array<int,array<string,string>>, unpaired:array<int,string>}
     */
    public function discover(): array
    {
        $restore   = $this->store->active();
        $pairs     = [];
        $ambiguous = [];
        $report    = [];
        $unpaired  = [];

        foreach ($this->store->accounts() as $acct) {
            if (!$this->store->useAccount($acct)) {
                $report[] = ['account' => $acct, 'status' => 'no_session',
                             'why' => 'could not select this account'];
                continue;
            }
            $conn = new StarlinkPortalConnector($this->store, $this->config, $this->http);
            $r    = $conn->get(StarlinkPortalConnector::LINES_PATH);

            if (empty($r['ok'])) {
                $why = (string)($r['error'] ?? '');
                $report[] = ['account' => $acct, 'status' => 'failed',
                             'why' => $why !== '' ? $why : 'HTTP ' . (int)($r['code'] ?? 0)];
                continue;
            }

            $found = self::pairsFrom((array)($r['data'] ?? []));
            foreach ($found['pairs'] as $kit => $line) $pairs[$kit] = $line;
            foreach ($found['ambiguous'] as $line => $kits) $ambiguous[$line] = $kits;

            $paired = array_flip($found['pairs']);
            foreach ($found['lines'] as $line) {
                if (isset($paired[$line]) || isset($found['ambiguous'][$line])) continue;
                $unpaired[] = $line;
                $note = $found['notes'][$line] ?? [];
                $bits = [];
                foreach ($note as $k => $v) {
                    $bits[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
                }
                $report[] = ['account' => $acct, 'status' => 'no_terminal', 'line' => $line,
                             'why' => $bits !== []
                                 ? 'Starlink returned no kit serial for this line (' . implode(' ', $bits) . ')'
                                 : 'Starlink returned no kit serial for this line'];
            }
            $report[] = ['account' => $acct, 'status' => 'asked',
                         'why' => count($found['pairs']) . ' pair(s) from '
                                . count($found['lines']) . ' line(s)'
                                . ($found['ambiguous'] !== []
                                   ? ', ' . count($found['ambiguous']) . ' ambiguous' : '')];
        }

        if ($restore !== '') $this->store->useAccount($restore);
        sort($unpaired);
        return ['pairs' => $pairs, 'ambiguous' => $ambiguous,
                'report' => $report, 'unpaired' => $unpaired];
    }

    // ── Keeping ─────────────────────────────────────────────────────────────

    /**
     * Store what was discovered, so the collector has it before the next ask.
     *
     * Refuses to write an empty result over a populated one, for the reason
     * every write in this plugin refuses it: one dead session must not erase
     * a fleet's worth of pairings that were right yesterday.
     */
    public static function save(string $dataDir, array $pairs): array
    {
        $path = rtrim($dataDir, '/') . '/' . self::FILE;
        if ($pairs === [] && self::load($dataDir) !== []) {
            return ['ok' => false, 'stored' => 0,
                    'why' => 'discovered nothing — keeping the pairs already stored'];
        }
        $r = SecureFile::write($path, (string)json_encode(
            ['discovered_at' => gmdate('Y-m-d H:i:s'), 'pairs' => $pairs],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return !empty($r['ok'])
            ? ['ok' => true, 'stored' => count($pairs), 'why' => '']
            : ['ok' => false, 'stored' => 0, 'why' => 'could not write ' . $path];
    }

    /** @return array<string,string> */
    public static function load(string $dataDir): array
    {
        $path = rtrim($dataDir, '/') . '/' . self::FILE;
        if (!is_file($path)) return [];
        $raw = json_decode((string)@file_get_contents($path), true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ((array)($raw['pairs'] ?? []) as $kit => $line) {
            $k = EquipmentAssignment::clean((string)$kit);
            $l = StarlinkServiceState::normalise((string)$line);
            if ($k !== '' && $l !== '') $out[$k] = $l;
        }
        return $out;
    }

    public static function discoveredAt(string $dataDir): string
    {
        $path = rtrim($dataDir, '/') . '/' . self::FILE;
        if (!is_file($path)) return '';
        $raw = json_decode((string)@file_get_contents($path), true);
        return is_array($raw) ? (string)($raw['discovered_at'] ?? '') : '';
    }
}
