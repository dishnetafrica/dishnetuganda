<?php
declare(strict_types=1);

require_once __DIR__ . '/EquipmentAssignment.php';
require_once __DIR__ . '/StarlinkServiceState.php';
require_once __DIR__ . '/SecureFile.php';

/**
 * KitSlMap — the pairings a person types, because discovery cannot find them.
 *
 * ── WHY A TYPED MAP IS NOT A WORKAROUND ─────────────────────────────────
 *
 * It is how the working installation works. dishnet-data-report's own sync
 * log, South Sudan, 13 Sep 2026:
 *
 *     Source 3.5 (manual KIT→SL map): 295 gap-filled, 13 new SL entries
 *     created, 1 disagreements (kept existing)
 *     Total: 367 service lines.
 *
 * 295 of 367. Four fifths of a fleet that syncs every two hours without
 * anyone touching it are reachable only because somebody once typed the
 * pairing in. Starlink's own listing does not carry the kit serial for a
 * Mini, and our sl_svc_cache shows the same hole in Uganda: 15 service
 * lines, 0 with a kit number.
 *
 * So this is the mechanism, not a patch over one. The operator maps kit to
 * account once, and from then on the collector has what the endpoint needs.
 *
 * ── GAP-FILL, NEVER OVERWRITE ───────────────────────────────────────────
 *
 * An equipment_assignments row was written when somebody stood at the
 * install and recorded what they fitted. A line in this map was typed later,
 * possibly from a spreadsheet. When the two disagree the assignment wins and
 * the disagreement is reported — the same call the data plugin makes, and
 * for the same reason: silently preferring the typed value would let a
 * transcription error quietly move one customer's gigabytes onto another's.
 *
 * ── WHERE IT LIVES ──────────────────────────────────────────────────────
 *
 * Our own data directory, which survives an upgrade. The data plugin keeps
 * its copy inside the directory uCRM deletes, and two files went that way
 * the day this was written. A map that took an afternoon to assemble is not
 * something to lose to an upgrade.
 *
 * ── FORMAT ──────────────────────────────────────────────────────────────
 *
 *     KIT303053206=SL-DF-10670815-91149-8
 *     # blank lines and comments are fine
 *
 * Deliberately identical to what the data plugin's box accepts and to what
 * tools/dr_kit_map.php --paste emits, so one map moves between the two
 * without editing.
 */
final class KitSlMap
{
    /** Plain JSON, in our own data directory. No secret is in it. */
    public const FILE = 'kit_sl_map.json';

    /** @var array<string,string> KIT serial => service line */
    private array $pairs = [];
    private string $savedAt = '';
    private string $dir;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/');
        $this->read();
    }

    // ── Parsing ─────────────────────────────────────────────────────────────

    /**
     * Read pasted text into pairs, refusing anything it cannot be sure of.
     *
     * A wrong pair does not fail loudly — it fetches a real service line's
     * usage and files it under the wrong kit, and a customer reads someone
     * else's gigabytes on their bill. A line we reject costs somebody thirty
     * seconds. So the shapes are checked, and a line that does not look like
     * a kit serial paired with a service line is returned as an error rather
     * than stored on the hope that it was meant.
     *
     * @return array{pairs:array<string,string>, errors:array<int,string>, read:int}
     */
    public static function parse(string $text): array
    {
        $pairs  = [];
        $errors = [];
        $read   = 0;
        $seenLine = [];   // service line => kit that claimed it first

        foreach (preg_split('/\R/', $text) ?: [] as $n => $raw) {
            $line = trim((string)$raw);
            if ($line === '' || $line[0] === '#') continue;
            $read++;
            $no = 'line ' . ($n + 1) . ': ';

            if (strpos($line, '=') === false) {
                $errors[] = $no . 'no "=" — expected KIT…=SL…';
                continue;
            }
            [$kitRaw, $slRaw] = explode('=', $line, 2);
            $kit = EquipmentAssignment::clean($kitRaw);
            $sl  = StarlinkServiceState::normalise($slRaw);

            if ($kit === '' || $sl === '') {
                $errors[] = $no . 'one side is empty';
                continue;
            }
            if (stripos($kit, 'KIT') !== 0) {
                $errors[] = $no . '"' . $kit . '" is not a kit serial (they start KIT…)';
                continue;
            }
            if (stripos($sl, 'SL') !== 0) {
                $errors[] = $no . '"' . $sl . '" is not a service line (they start SL…)';
                continue;
            }
            if (isset($pairs[$kit]) && $pairs[$kit] !== $sl) {
                $errors[] = $no . $kit . ' is already mapped to ' . $pairs[$kit]
                          . ' — keeping that one, ignoring ' . $sl;
                continue;
            }
            if (isset($seenLine[$sl]) && $seenLine[$sl] !== $kit) {
                $errors[] = $no . $sl . ' is already mapped to ' . $seenLine[$sl]
                          . ' — a service line belongs to one kit, ignoring ' . $kit;
                continue;
            }
            $pairs[$kit]   = $sl;
            $seenLine[$sl] = $kit;
        }
        return ['pairs' => $pairs, 'errors' => $errors, 'read' => $read];
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    public function pairs(): array { return $this->pairs; }
    public function count(): int   { return count($this->pairs); }
    public function savedAt(): string { return $this->savedAt; }
    public function path(): string { return $this->dir . '/' . self::FILE; }

    /** The service line for a kit, or '' when it is not mapped. */
    public function lineFor(string $kit): string
    {
        return $this->pairs[EquipmentAssignment::clean($kit)] ?? '';
    }

    /** The kit for a service line, or '' when it is not mapped. */
    public function kitFor(string $serviceLine): string
    {
        $sl = StarlinkServiceState::normalise($serviceLine);
        foreach ($this->pairs as $kit => $line) {
            if ($line === $sl) return $kit;
        }
        return '';
    }

    /** Back out as text, for the box a person pastes into. */
    public function asText(): string
    {
        $out = [];
        foreach ($this->pairs as $kit => $line) $out[] = $kit . '=' . $line;
        return implode("\n", $out);
    }

    // ── Writing ─────────────────────────────────────────────────────────────

    /**
     * Replace the whole map with what was pasted.
     *
     * Replace, not merge: the box shows the current map, so what comes back
     * IS the intended map, and a merge would make a deletion impossible —
     * a person who removes a wrong line would find it still there.
     *
     * A paste that parses to nothing is refused, for the same reason the
     * collector refuses to write an empty usage file: an accidental empty
     * box should not silently discard an afternoon's work.
     *
     * @return array{ok:bool, stored:int, read:int, errors:array<int,string>, why:string}
     */
    public function replace(string $text): array
    {
        $p = self::parse($text);
        if ($p['pairs'] === []) {
            return ['ok' => false, 'stored' => 0, 'read' => $p['read'], 'errors' => $p['errors'],
                    'why' => $p['read'] === 0
                        ? 'nothing pasted — the existing map was left alone'
                        : 'not one line parsed — the existing map was left alone'];
        }
        $this->pairs   = $p['pairs'];
        $this->savedAt = gmdate('Y-m-d H:i:s');
        $r = SecureFile::write($this->path(), (string)json_encode(
            ['saved_at' => $this->savedAt, 'pairs' => $this->pairs],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return !empty($r['ok'])
            ? ['ok' => true, 'stored' => count($this->pairs), 'read' => $p['read'],
               'errors' => $p['errors'], 'why' => '']
            : ['ok' => false, 'stored' => 0, 'read' => $p['read'], 'errors' => $p['errors'],
               'why' => 'could not write ' . $this->path()];
    }

    private function read(): void
    {
        $path = $this->path();
        if (!is_file($path)) return;
        $raw = json_decode((string)@file_get_contents($path), true);
        if (!is_array($raw)) return;
        $this->savedAt = (string)($raw['saved_at'] ?? '');
        foreach ((array)($raw['pairs'] ?? []) as $kit => $line) {
            $k = EquipmentAssignment::clean((string)$kit);
            $l = StarlinkServiceState::normalise((string)$line);
            if ($k !== '' && $l !== '') $this->pairs[$k] = $l;
        }
    }

    // ── Applying ────────────────────────────────────────────────────────────

    /**
     * Gap-fill live assignments so the collector has what the endpoint needs.
     *
     * Three things are filled and nothing is ever replaced:
     *   · a missing service line, from this map, keyed by kit serial;
     *   · a missing account, from the service cache, keyed by that line —
     *     the endpoint takes account AND line, and the map carries only the
     *     line, so the account still has to come from somewhere;
     *   · nothing else.
     *
     * A disagreement is recorded, not applied. See the class note.
     *
     * @param array<int,array<string,mixed>> $assignments liveAssignments() rows
     * @return array{assignments:array, filled_lines:int, filled_accounts:int,
     *               disagreements:array<int,array<string,string>>, unused:array<int,string>}
     */
    public function apply(array $assignments, ?StarlinkServiceState $svc = null): array
    {
        $filledLines = 0;
        $filledAccts = 0;
        $disagree    = [];
        $usedKits    = [];

        foreach ($assignments as $i => $a) {
            $kit  = EquipmentAssignment::clean((string)($a['kit_serial'] ?? ''));
            if ($kit === '') continue;
            $line = StarlinkServiceState::normalise((string)($a['starlink_service_line'] ?? ''));
            $mapped = $this->lineFor($kit);

            if ($mapped !== '') {
                $usedKits[$kit] = true;
                if ($line === '') {
                    $assignments[$i]['starlink_service_line'] = $mapped;
                    $line = $mapped;
                    $filledLines++;
                } elseif ($line !== $mapped) {
                    // The install record wins. Say so, loudly enough to fix.
                    $disagree[] = ['kit' => $kit, 'assignment' => $line, 'map' => $mapped];
                }
            }

            $acct = StarlinkServiceState::normalise((string)($a['starlink_account'] ?? ''));
            if ($acct === '' && $line !== '' && $svc !== null) {
                $fromCache = StarlinkServiceState::normalise((string)($svc->forLine($line)['account'] ?? ''));
                if ($fromCache !== '') {
                    $assignments[$i]['starlink_account'] = $fromCache;
                    $filledAccts++;
                }
            }
        }

        // Mapped kits no live assignment claims. Not an error — a kit may be
        // in stock or released — but worth showing, because a typo in a kit
        // serial looks exactly like this and nothing else would reveal it.
        $unused = [];
        foreach (array_keys($this->pairs) as $kit) {
            if (!isset($usedKits[$kit])) $unused[] = $kit;
        }

        return ['assignments' => array_values($assignments),
                'filled_lines' => $filledLines, 'filled_accounts' => $filledAccts,
                'disagreements' => $disagree, 'unused' => $unused];
    }
}
