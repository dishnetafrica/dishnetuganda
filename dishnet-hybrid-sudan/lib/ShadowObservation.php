<?php
declare(strict_types=1);

require_once __DIR__ . '/ShadowCompare.php';

/**
 * ShadowObservation — read the B3.4 shadow log back as counts.
 *
 * The observation window asks one question: on real Uganda traffic, do the
 * controlled customer tools and the legacy support/accounts prompts agree?
 * The answer has to come out as numbers, because the log deliberately holds
 * no values to report.
 *
 * ── IT READS; IT NEVER WRITES ───────────────────────────────────────────
 *
 * aggregate() opens the log the worker already writes, counts, and closes it.
 * No second file, no table, no cache. A measurement instrument that starts
 * keeping its own copy is how a temporary observation becomes a permanent
 * data store nobody remembers creating — and this one would be keeping a copy
 * of exactly the thing B3.4 refused to log.
 *
 * ── NOTHING FROM THE LOG TEXT REACHES THE RESULT ────────────────────────
 *
 * Every token is matched against ShadowCompare's own constants, and the
 * CONSTANT is what gets counted, never the matched text. The same
 * constructive pattern as BrainContext and ShopBotPayload: a line is not
 * filtered for bad content, it is rebuilt from a known vocabulary or thrown
 * away.
 *
 * A line that does not match is counted as unparseable and discarded. It is
 * never echoed and never returned. If something unexpected ever reaches that
 * log, printing it here to find out what it was is the single move that turns
 * a logging bug into a disclosure — so the report gives a count and tells the
 * operator to close the window and go and look with their own eyes, under the
 * access controls that exist for that.
 *
 * Conversation ids are legitimately in the log and are NOT reported. What a
 * migration decision needs is how MANY distinct conversations a difference
 * spans; the ids themselves add nothing to that.
 *
 * This class leaves with B3.5.
 */
final class ShadowObservation
{
    /** Below this many distinct conversations, a difference is not a finding. */
    public const MIN_CUSTOMERS = 3;

    /** The prefix WorkerBase::log() puts on every line, and the shadow's shape. */
    private const LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[[^\]]+\] \[(?:info|warn)\] '
                       . 'conv (\d+): shadow (.+?)\s*$/';
    /** One fact verdict: name=verdict or name=verdict:reason+reason. */
    private const FACT = '/^([a-z_]+)=([a-z_]+)(?::([a-z_+]+))?$/';

    /**
     * Count what the shadow has seen.
     *
     * @param string $since 'Y-m-d H:i:s', compared as a string because that
     *                      format sorts correctly. Worker timestamps are local.
     */
    public static function aggregate(string $logFile, string $since = ''): array
    {
        $out = [
            'lines_scanned' => 0, 'shadow_lines' => 0, 'in_window' => 0,
            'turns'           => [],   // channel => turns
            'conversations'   => [],   // channel => [conv => true]   cardinality only
            'verdicts'        => [],   // channel => fact => verdict => n
            'reasons'         => [],   // channel => fact => reason  => n
            'divergent_convs' => [],   // channel => fact => reason => [conv => true]
            'skipped' => 0, 'failed' => [], 'unparseable' => 0,
            'first_seen' => '', 'last_seen' => '',
        ];

        $fh = @fopen($logFile, 'r');
        if (!$fh) return $out;

        while (($line = fgets($fh)) !== false) {
            $out['lines_scanned']++;
            if (strpos($line, ': shadow ') === false) continue;
            $out['shadow_lines']++;

            if (preg_match(self::LINE, $line, $m) !== 1) { $out['unparseable']++; continue; }
            [, $ts, $conv, $rest] = $m;
            if ($since !== '' && $ts < $since) continue;
            $out['in_window']++;
            if ($out['first_seen'] === '' || $ts < $out['first_seen']) $out['first_seen'] = $ts;
            if ($ts > $out['last_seen']) $out['last_seen'] = $ts;

            if ($rest === 'skipped — CRM not configured') { $out['skipped']++; continue; }
            if (preg_match('/^failed — ([A-Za-z_\\\\]+)$/', $rest, $f) === 1) {
                // The exception CLASS, and only when it looks like a class name.
                $out['failed'][$f[1]] = ($out['failed'][$f[1]] ?? 0) + 1;
                continue;
            }

            $parsed = self::facts($rest);
            if ($parsed === null) { $out['unparseable']++; continue; }
            [$chan, $facts] = $parsed;

            $out['turns'][$chan] = ($out['turns'][$chan] ?? 0) + 1;
            $out['conversations'][$chan][$conv] = true;
            foreach ($facts as [$fact, $verdict, $reasons]) {
                $out['verdicts'][$chan][$fact][$verdict] =
                    ($out['verdicts'][$chan][$fact][$verdict] ?? 0) + 1;
                foreach ($reasons as $rs) {
                    $out['reasons'][$chan][$fact][$rs] = ($out['reasons'][$chan][$fact][$rs] ?? 0) + 1;
                    $out['divergent_convs'][$chan][$fact][$rs][$conv] = true;
                }
            }
        }
        fclose($fh);
        return $out;
    }

    /**
     * Rebuild one line's verdicts from the vocabulary, or refuse it.
     *
     * Returns the CONSTANTS matched, never the text. Anything the grammar does
     * not cover — an unknown channel, fact, verdict or reason, a stray token,
     * an empty tail — makes the whole line null rather than a partial reading:
     * a line half-understood is a line whose other half is unexplained, and an
     * unexplained half is the one that might carry a value.
     *
     * @return array{0:string,1:array<int,array{0:string,1:string,2:array<int,string>}>}|null
     */
    private static function facts(string $rest): ?array
    {
        $tok  = preg_split('/\s+/', trim($rest)) ?: [];
        $chan = (string)array_shift($tok);
        if (!isset(ShadowCompare::CHANNEL_FACTS[$chan]) && $chan !== 'other') return null;
        if ($tok === []) return null;

        $facts = [];
        foreach ($tok as $t) {
            if (preg_match(self::FACT, $t, $p) !== 1) return null;
            [, $fact, $verdict] = $p;
            $why = $p[3] ?? '';
            if (!in_array($fact, ShadowCompare::FACTS, true)) return null;
            if (!in_array($verdict, ShadowCompare::VERDICTS, true)) return null;
            $reasons = [];
            foreach (($why === '' ? [] : explode('+', $why)) as $rs) {
                if (!in_array($rs, ShadowCompare::REASONS, true)) return null;
                // The constant, found by identity — not the matched substring.
                $reasons[] = ShadowCompare::REASONS[array_search($rs, ShadowCompare::REASONS, true)];
            }
            $facts[] = [
                ShadowCompare::FACTS[array_search($fact, ShadowCompare::FACTS, true)],
                ShadowCompare::VERDICTS[array_search($verdict, ShadowCompare::VERDICTS, true)],
                $reasons,
            ];
        }
        return [$chan === 'other' ? 'other' : $chan, $facts];
    }

    /**
     * How widely a difference is spread.
     *
     * Deliberately refuses to call anything systematic on fewer than three
     * distinct conversations, however many turns they produced. One chatty
     * customer with an odd record is not a pattern, and a migration decision
     * taken from one account is the mistake this window exists to avoid.
     */
    public static function spread(int $hits, int $turns, int $convs): string
    {
        if ($convs < self::MIN_CUSTOMERS) return 'TOO FEW CUSTOMERS TO CLASSIFY';
        if ($turns <= 0)                  return 'NONE';
        $share = $hits / $turns;
        if ($share >= 0.90) return 'SYSTEMATIC';
        if ($share >= 0.50) return 'WIDESPREAD';
        return 'INTERMITTENT';
    }

    /**
     * The report, as a string.
     *
     * Integers, and the vocabulary words aggregate() already matched. No text
     * from the log reaches it.
     */
    public static function render(array $r, string $logFile, string $since, ?array $snap): string
    {
        $bar = str_repeat('-', 68);
        $o  = "\n  B3.4 SHADOW OBSERVATION\n  " . $bar . "\n";
        $o .= "  log            " . $logFile . "\n";
        if ($snap !== null && isset($snap['recorded_at'])) {
            $o .= "  window opened  " . (string)$snap['recorded_at'] . "\n";
        }
        if ($since !== '') $o .= "  since          " . $since . "\n";
        $o .= "  lines scanned  " . number_format((float)$r['lines_scanned']) . "\n";
        $o .= "  shadow lines   " . number_format((float)$r['shadow_lines'])
            . ($since !== '' ? ' (' . number_format((float)$r['in_window']) . ' in window)' : '') . "\n";
        if ($r['first_seen'] !== '') {
            $o .= "  first / last   " . $r['first_seen'] . "  ->  " . $r['last_seen'] . "\n";
        }

        $o .= "\n  TURNS SHADOWED\n";
        $seen = false;
        foreach (['support', 'account', 'other'] as $c) {
            if (!isset($r['turns'][$c])) continue;
            $seen = true;
            $o .= sprintf("      %-9s %6d turns across %d distinct conversations\n",
                          $c, (int)$r['turns'][$c], count($r['conversations'][$c] ?? []));
        }
        if (!$seen) $o .= "      none\n";

        $o .= "\n  VERDICTS\n";
        $seen = false;
        foreach ($r['verdicts'] as $chan => $facts) {
            foreach ($facts as $fact => $vs) {
                $seen = true;
                ksort($vs);
                $parts = [];
                foreach ($vs as $v => $n) $parts[] = sprintf('%s=%d', $v, (int)$n);
                $o .= sprintf("      %-9s %-15s %s\n", $chan, $fact, implode('  ', $parts));
            }
        }
        if (!$seen) $o .= "      none\n";

        $o .= "\n  REASONS, AND HOW WIDELY THEY SPREAD\n";
        $seen = false;
        foreach ($r['reasons'] as $chan => $facts) {
            $turns = (int)($r['turns'][$chan] ?? 0);
            foreach ($facts as $fact => $rs) {
                arsort($rs);
                foreach ($rs as $reason => $n) {
                    $seen = true;
                    $convs = count($r['divergent_convs'][$chan][$fact][$reason] ?? []);
                    $o .= sprintf("      %-9s %-15s %-16s %5d turns / %3d conversations   %s\n",
                                  $chan, $fact, $reason, (int)$n, $convs,
                                  self::spread((int)$n, $turns, $convs));
                }
            }
        }
        if (!$seen) $o .= "      none - the two readers agreed on everything seen\n";

        $o .= "\n  HEALTH\n";
        $o .= sprintf("      skipped (CRM not configured)  %d\n", (int)$r['skipped']);
        if ($r['failed'] === []) {
            $o .= "      shadow failures               0\n";
        } else {
            foreach ($r['failed'] as $cls => $n) {
                $o .= sprintf("      shadow failures               %d x %s\n", (int)$n, (string)$cls);
            }
        }
        $o .= sprintf("      unparseable shadow lines      %d%s\n", (int)$r['unparseable'],
                      $r['unparseable'] > 0 ? '   <- INVESTIGATE' : '');
        if ($r['unparseable'] > 0) {
            $o .= "\n      An unparseable line is NOT printed here. Something is writing to\n";
            $o .= "      this log in a shape ShadowCompare cannot produce. Close the window\n";
            $o .= "      (php tools/shadow_observe.php --end) before going to look at it.\n";
        }

        $o .= "\n  " . $bar . "\n";
        $o .= "  Counts only. No customer name, number, id, amount, date or method\n";
        $o .= "  passes through this report, and no log line is echoed.\n\n";
        return $o;
    }
}
