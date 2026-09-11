<?php
declare(strict_types=1);

/**
 * HardwareKnowledge — what we know about the dishes, and when we last checked.
 *
 * "Which Starlink should I buy?" is not a product question. It is a question
 * about a building, a number of people, a power supply and whether the thing
 * ever has to move. Answering it from a plan list gets it wrong in both
 * directions: a family sold a Mini that cannot cover the house, or a couple in
 * a flat sold High Performance because it sounded better.
 *
 * Two rules make this safe to put in front of a model.
 *
 * DATED, NOT PERMANENT. Starlink changes hardware generations. Every model
 * carries verified_on, and past stale_after_days the block tells the assistant
 * to confirm rather than assert. A specification stated confidently eighteen
 * months after anyone checked it is exactly the kind of plausible wrong answer
 * the rest of this system exists to prevent.
 *
 * NULL IS NOT A VALUE. A field nobody has verified renders as "not verified",
 * never as none, standard, or unlimited. High Performance ships with several
 * nulls on purpose — it is the honest state of what we have confirmed, and the
 * assistant says so instead of filling the gap.
 *
 * Prices are not here and never will be. uCRM is the only source for money.
 */
class HardwareKnowledge
{
    /** @return array{stale_after_days:int, models:array} */
    public static function load(string $file): array
    {
        $j = @json_decode((string)@file_get_contents($file), true);
        if (!is_array($j) || empty($j['models'])) return ['stale_after_days' => 180, 'models' => []];
        return [
            'stale_after_days' => (int)($j['stale_after_days'] ?? 180),
            'models'           => (array)$j['models'],
        ];
    }

    /**
     * The prompt block. Empty string when there is nothing verified, so an
     * install without this file behaves exactly as it did before.
     *
     * @param string|null $today  injectable for tests; defaults to now
     */
    public static function promptBlock(string $file, ?string $today = null): string
    {
        $kb = self::load($file);
        if (!$kb['models']) return '';

        $now   = strtotime($today ?? 'today') ?: time();
        $stale = max(1, $kb['stale_after_days']);
        $any   = false;

        $p = "\nSTARLINK HARDWARE — what we have verified.\n"
           . "Recommend from this, never from memory of Starlink's marketing.\n";

        foreach ($kb['models'] as $m) {
            $name = trim((string)($m['model'] ?? ''));
            if ($name === '') continue;
            $any = true;

            $verified = (string)($m['verified_on'] ?? '');
            $age  = $verified !== '' ? (int)floor(($now - (strtotime($verified) ?: $now)) / 86400) : null;
            $old  = $age === null || $age > $stale;

            $p .= "\n- " . $name;
            $aka = array_filter((array)($m['aka'] ?? []));
            if ($aka) $p .= " (customers also call it: " . implode(', ', $aka) . ")";
            $p .= "\n";

            $line = function (string $label, $v) use (&$p) {
                if ($v === null || $v === '' || $v === []) {
                    $p .= "  {$label}: not verified — say you will confirm it, never fill the gap.\n";
                    return;
                }
                $p .= "  {$label}: " . (is_array($v) ? implode('; ', $v) : (string)$v) . "\n";
            };

            if (!empty($m['summary'])) $p .= "  " . (string)$m['summary'] . "\n";
            $line('Wi-Fi', $m['wifi'] ?? null);
            $line('Ethernet', $m['ethernet'] ?? null);
            $line('Power', $m['power_avg_w'] ?? null);

            // Rendered with the caveat attached, every time. Detached from it,
            // this number becomes a promise about Wi-Fi in a building, which is
            // the most damaging thing on the page.
            if (!empty($m['coverage_m2'])) {
                // Asked "what is the coverage radius from the service room?", the
                // assistant answered "approximately 297 square meters" — an area
                // reported as a radius, for a trading centre expecting a hundred
                // users, from a figure that describes a domestic router. The
                // caveat was there; it was not strong enough to stop the number
                // being used as an answer it cannot be.
                $p .= "  Wi-Fi area: the maker quotes about " . (int)$m['coverage_m2']
                    . " m2 for the router in ideal conditions. It is an AREA, never a RADIUS, "
                    . "and never a promise — walls, floors and people cut it down hard. Do NOT "
                    . "offer it as the answer to \"how far does the signal reach\", and never "
                    . "use it to size a trading centre, a hotspot, a school, a church, a hotel "
                    . "or anywhere the public connects. Those need access points and someone "
                    . "to design the network.\n";
            } else {
                $line('Coverage', null);
            }

            if (array_key_exists('portable', $m) && $m['portable'] !== null) {
                $p .= "  Portable: " . ($m['portable'] ? 'yes, designed to move' : 'no, fixed installation') . "\n";
            }
            if (!empty($m['best_for']))    $p .= "  Suits: " . implode('; ', (array)$m['best_for']) . "\n";
            if (!empty($m['limitations'])) $p .= "  Watch out: " . implode(' ', (array)$m['limitations']) . "\n";

            if ($old) {
                $p .= "  ⚠ These figures were last verified "
                    . ($verified !== '' ? "on {$verified}, over {$stale} days ago" : "at an unknown date")
                    . ". Starlink changes hardware generations, so give them as "
                    . "what we hold and offer to confirm the current specification.\n";
            }
        }

        if (!$any) return '';

        $p .= "\nHOW TO USE THIS:\n"
            . "- Recommend hardware from the customer's situation: how many people, how big "
            . "the building, fixed or moving, what power it runs on. Never because one costs "
            . "more, and never because one is cheapest.\n"
            . "- THE DISH IS NOT THE WI-FI. The dish brings the connection to the building; "
            . "the router and any access points carry it around inside. A bigger dish does not "
            . "fix a weak signal in a back bedroom. If someone describes a coverage problem, "
            . "say that plainly — selling them a larger dish for it would not work.\n"
            . "- NEVER answer an Ethernet, router or third-party equipment question generically. "
            . "The answer differs by model and generation. Ask which Starlink they have, then "
            . "answer for that one. MikroTik, UniFi, Ubiquiti, Fortinet, Sophos, Cisco, TP-Link "
            . "and switches or firewalls in general all depend on the kit in front of them.\n"
            . "- Anything beyond the kit — extra access points, a mesh, switches, a firewall, "
            . "structured cabling, a longer cable run — is quoted separately, never folded into "
            . "the kit price and never promised at a figure you do not have.\n"
            . "- Solar and battery sizing covers the WHOLE load: dish, router, access points, "
            . "switches, and how many hours a day it runs. Never size a system from the dish's "
            . "figure alone, and never state a panel or battery size we have not confirmed.\n"
            . "- The dish needs a clear view of the sky. Trees, walls and taller buildings "
            . "matter more than the model chosen. Where the site sounds difficult — obstructions, "
            . "a pole, a long cable run, tower work — take the details and arrange a site survey "
            . "rather than confirming it will work.\n"
            . "- If someone already has Starlink and wants their own router, that is a network "
            . "question, not a sale: establish the model and what they are connecting.\n"
            . "- Where these notes do not cover what they asked, say you will confirm the exact "
            . "model and current specification. Being right matters more than sounding certain.\n";

        return $p;
    }
}
