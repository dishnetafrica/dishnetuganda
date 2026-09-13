<?php
declare(strict_types=1);

require_once __DIR__ . '/StarlinkServiceState.php';

/**
 * StarlinkAccounts — the fleet seen from Starlink's side rather than uCRM's.
 *
 * ── WHY A SECOND VIEW OF THE SAME FLEET ─────────────────────────────────
 *
 * starlink_fleet.php answers "who is my customer and what are they using".
 * That is the question the business asks. It is not the question that gets a
 * broken fleet working again, because the things that break — a dead cookie,
 * a service line nobody bound, a kit Starlink has never heard of — all belong
 * to a Starlink ACCOUNT, and the customer view has no row to hang them on. A
 * line that no assignment claims simply does not appear.
 *
 * The data plugin learned this and grew a Starlink Accounts tab beside its
 * Clients tab. This is the same split, on our own binding.
 *
 * ── IT REGROUPS, IT DOES NOT RE-FETCH ───────────────────────────────────
 *
 * Every figure here comes from StarlinkFleet::build() — the same rows, the
 * same usage, the same live state. Two screens computing "GB this month"
 * from two sources is how they end up disagreeing, and then nobody believes
 * either. So this takes the built fleet and groups it. Nothing is recomputed.
 *
 * ── THE FOUR THINGS IT IS FOR ───────────────────────────────────────────
 *
 * Each is a different job for a different person, so they are never merged
 * into one "problem" count:
 *
 *   no_session      we hold no cookie for this account — nothing can be read
 *                   until somebody pastes one.
 *   unbound_lines   Starlink has lines here that no assignment claims. Some
 *                   are stock. Some are a customer whose install was never
 *                   recorded, and that one is invisible everywhere else.
 *   no_account      an assignment names no Starlink account. The usage
 *                   endpoint takes the account in the path, so these can
 *                   never be collected, however good the cookie is.
 *   no_usage        bound, live, and nothing collected. A real gap, and not
 *                   the same as a customer who used nothing.
 */
final class StarlinkAccounts
{
    /**
     * @param array $fleet    StarlinkFleet::build() output
     * @param array $sessions ['ACC-…' => ['state'=>string,'last_ok'=>string]] accounts we hold
     * @return array{accounts:array<int,array<string,mixed>>, summary:array<string,int>,
     *               orphans:array<int,array<string,mixed>>}
     */
    public static function group(array $fleet, array $sessions = []): array
    {
        $byAcct  = [];
        $orphans = [];   // assignments with no Starlink account at all

        foreach ((array)($fleet['rows'] ?? []) as $r) {
            $acct = StarlinkServiceState::normalise((string)($r['account'] ?? ''));
            $live = is_array($r['live'] ?? null) ? $r['live'] : [];
            $use  = is_array($r['usage'] ?? null) ? $r['usage'] : [];
            $kit  = [
                'kit_serial'   => (string)($r['kit_serial'] ?? ''),
                'client_id'    => (int)($r['client_id'] ?? 0),
                'client_name'  => (string)($r['client_name'] ?? ''),
                'service_line' => (string)($r['service_line'] ?? ''),
                'status'       => (string)($live['status'] ?? ''),
                'label'        => (string)($live['label'] ?? ''),
                'used_gb'      => $use['used_gb'] ?? null,
                'cycle'        => (string)($use['cycle'] ?? ''),
                'why_no_usage' => ($use['used_gb'] ?? null) === null ? (string)($use['reason'] ?? '') : '',
            ];
            if ($acct === '') { $orphans[] = $kit; continue; }
            $byAcct[$acct]['kits'][] = $kit;
        }

        foreach ((array)($fleet['unclaimed'] ?? []) as $u) {
            $acct = StarlinkServiceState::normalise((string)($u['account'] ?? ''));
            // A line with no account on it is still worth showing; it goes
            // under its own heading rather than being dropped.
            $byAcct[$acct === '' ? '(no account on the line)' : $acct]['unclaimed'][] = $u;
        }

        // An account we hold a cookie for but have nothing bound in is not an
        // empty row to skip — it is the normal state before the first install,
        // and seeing it is how somebody knows the cookie took.
        foreach (array_keys($sessions) as $acct) {
            $a = StarlinkServiceState::normalise((string)$acct);
            if ($a !== '' && !isset($byAcct[$a])) $byAcct[$a] = [];
        }

        $out = [];
        foreach ($byAcct as $acct => $d) {
            $kits      = $d['kits'] ?? [];
            $unclaimed = $d['unclaimed'] ?? [];
            $held      = isset($sessions[$acct]);

            $active = 0; $gb = 0.0; $collected = 0;
            foreach ($kits as $k) {
                if ($k['status'] === 'active') $active++;
                if ($k['used_gb'] !== null) { $gb += (float)$k['used_gb']; $collected++; }
            }

            $needs = [];
            if (!$held && ($kits !== [] || $unclaimed !== [])) $needs[] = 'no_session';
            if ($unclaimed !== [])                             $needs[] = 'unbound_lines';
            if ($active > 0 && $collected === 0)               $needs[] = 'no_usage';

            usort($kits, static fn(array $x, array $y): int =>
                strcmp($x['kit_serial'], $y['kit_serial']));

            $out[] = [
                'account'        => (string)$acct,
                'session_held'   => $held,
                'session_state'  => (string)($sessions[$acct]['state']   ?? ''),
                'session_last_ok'=> (string)($sessions[$acct]['last_ok'] ?? ''),
                'kits'           => $kits,
                'unclaimed'      => $unclaimed,
                'total_kits'     => count($kits),
                'active_kits'    => $active,
                'collected_kits' => $collected,
                'used_gb'        => round($gb, 1),
                'needs'          => $needs,
            ];
        }

        // Accounts that need something first, then the busiest. A screen whose
        // top row is always the thing to do next is worth more than one sorted
        // alphabetically for tidiness.
        usort($out, static function (array $a, array $b): int {
            $n = count($b['needs']) <=> count($a['needs']);
            if ($n !== 0) return $n;
            $k = $b['total_kits'] <=> $a['total_kits'];
            return $k !== 0 ? $k : strcmp($a['account'], $b['account']);
        });

        return ['accounts' => $out, 'orphans' => $orphans,
                'summary'  => self::summarise($out, $orphans, $sessions)];
    }

    /** @return array<string,int> */
    private static function summarise(array $accounts, array $orphans, array $sessions): array
    {
        $s = ['accounts' => count($accounts), 'sessions_held' => count($sessions),
              'kits' => 0, 'active' => 0, 'unbound_lines' => 0,
              'accounts_no_session' => 0, 'accounts_no_usage' => 0,
              'no_account' => count($orphans)];
        foreach ($accounts as $a) {
            $s['kits']          += $a['total_kits'];
            $s['active']        += $a['active_kits'];
            $s['unbound_lines'] += count($a['unclaimed']);
            if (in_array('no_session', $a['needs'], true)) $s['accounts_no_session']++;
            if (in_array('no_usage',   $a['needs'], true)) $s['accounts_no_usage']++;
        }
        return $s;
    }

    /**
     * A per-account snapshot of what the session store holds.
     *
     * Selecting an account to read it changes what is selected, so the
     * operator's choice is restored afterwards. Getting that wrong would make
     * merely LOOKING at this screen change which account the next collection
     * runs against.
     *
     * @return array<string,array{state:string,last_ok:string}>
     */
    public static function sessionSnapshot(StarlinkSessionStore $store): array
    {
        $restore = $store->active();
        $out     = [];
        foreach ($store->accounts() as $acct) {
            if (!$store->useAccount($acct)) continue;
            $rec = $store->load();
            $out[StarlinkServiceState::normalise($acct)] = [
                'state'   => (string)($rec['state'] ?? ''),
                'last_ok' => (string)($rec['last_ok_at'] ?? $rec['last_checked_at'] ?? ''),
            ];
        }
        if ($restore !== '') $store->useAccount($restore);
        return $out;
    }
}
