<?php
declare(strict_types=1);

/**
 * NetworkEquipment — which one-time products are for spreading the Wi-Fi, and what each is for.
 *
 * ── WHY THIS EXISTS (docs/40) ───────────────────────────────────────────
 *
 * The operator priced an outdoor access point and a MikroTik in uCRM Products so that the
 * assistant could quote a customer who wants Wi-Fi over a compound, another building or a
 * trading centre. Measured on 27 Sep 2026, in the live conversations: it never did. It spoke of
 * "routers and access points" in general, handed over, and once told a customer who named the
 * very product that it had "no specific information about those access points in our system".
 * The products sat in HARDWARE as bare model names ("Ruijie Reyee RG-RAP6262(G)", "MikroTik L009
 * Series") beside the kits, in the list whose rule is "the kit, the installation, and any other
 * one-time charge" — so nothing said what they were for, and nothing kept them out of a home
 * quote.
 *
 * This names them. assets/shop/network.json holds, per role, the words a product name contains
 * and a label saying what the item is for. No price and no coverage figure lives there: prices
 * come from uCRM, and there is no figure for how far anything reaches.
 *
 * Used by DishNetAiBrain (the NETWORK EQUIPMENT block) and by AiReplyWorker (the amounts a reply
 * may state), so the prompt and the price check always agree on what is network equipment.
 */
final class NetworkEquipment
{
    const FILE = 'assets/shop/network.json';

    /** @var array<string,array> roles per plugin root, loaded once per process */
    private static $cache = [];

    /**
     * The roles, in match order.
     *
     * @return array<int,array{key:string,label:string,match:array<int,string>}>
     */
    public static function roles(string $pluginRoot): array
    {
        $root = rtrim($pluginRoot, '/');
        if (isset(self::$cache[$root])) return self::$cache[$root];
        $file = $root . '/' . self::FILE;
        $d = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $roles = [];
        foreach ((array)($d['roles'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $key   = trim((string)($r['key'] ?? ''));
            $label = trim((string)($r['label'] ?? ''));
            $match = array_values(array_filter(array_map(
                fn($m) => self::norm((string)$m), (array)($r['match'] ?? [])), 'strlen'));
            if (!preg_match('/^[a-z_]{2,40}$/', $key) || $label === '' || $match === []) continue;
            $roles[] = ['key' => $key, 'label' => $label, 'match' => $match];
        }
        return self::$cache[$root] = $roles;
    }

    /** Lower-cased, inner whitespace collapsed: how names and match strings are compared. */
    public static function norm(string $s): string
    {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
    }

    /**
     * The role a product name falls under, or null.
     *
     * @return array{key:string,label:string}|null
     */
    public static function roleFor(string $pluginRoot, string $name): ?array
    {
        $n = self::norm($name);
        if ($n === '') return null;
        foreach (self::roles($pluginRoot) as $r) {
            foreach ($r['match'] as $m) {
                if (strpos($n, $m) !== false) return ['key' => $r['key'], 'label' => $r['label']];
            }
        }
        return null;
    }

    /**
     * Split one-time products into the ones that are not network equipment and the ones that are.
     * Order is kept; network rows gain 'role' (the label) and 'role_key'.
     *
     * @param  array $items rows with at least 'name' and 'price'
     * @return array{0:array<int,array>,1:array<int,array>} [other, network]
     */
    public static function split(string $pluginRoot, array $items): array
    {
        $other = []; $network = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $role = self::roleFor($pluginRoot, (string)($it['name'] ?? ''));
            if ($role === null) { $other[] = $it; continue; }
            $network[] = $it + ['role' => $role['label'], 'role_key' => $role['key']];
        }
        // In the order a setup is built — router, access points, cable, connectors, consultancy —
        // whatever order uCRM returns them in. Stable: equal roles keep their uCRM order.
        $rank = array_flip(self::DISPLAY_ORDER);
        $keyed = [];
        foreach ($network as $i => $n) $keyed[] = [$rank[$n['role_key']] ?? count($rank), $i, $n];
        usort($keyed, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return [$other, array_map(fn($k) => $k[2], $keyed)];
    }

    /** How a setup is listed. A role not named here comes after these. */
    const DISPLAY_ORDER = ['router', 'access_point', 'cable', 'connectors', 'consultancy'];

    /** Test seam: forget what was loaded. */
    public static function reset(): void { self::$cache = []; }
}
