<?php
declare(strict_types=1);

/**
 * PlanCatalogue — which plans the model is allowed to see.
 *
 * ── ENFORCEMENT BY OMISSION, APPLIED TO RECOMMENDATIONS ─────────────────
 *
 * FollowUpEvaluator states the doctrine for account facts: do not tell the
 * model to keep a secret, simply do not give it the secret. This is the same
 * move for plan recommendations, and it is here because the two weaker forms
 * were both tried and both measured.
 *
 * 5.18.22 asked the model, in the prompt, not to treat "we are a business" as
 * a reason to quote a Business plan. Verified present, 29,029 characters. Of
 * 21 replies to a customer who had just said "business", 18 named a Business
 * plan and never mentioned Residential — against 91% before the rule existed.
 *
 * 5.18.25 stopped asking and appended the priority-data fact in code. That
 * worked, and it revealed the deeper problem: a wrong recommendation with a
 * correct footnote is a message that contradicts itself. On 19 September a
 * customer asking for hotspot Wi-Fi was told "Business 500 for better
 * capacity, as it allows for more users", immediately followed by our own
 * note that Residential has unlimited data at the same speed. Both claims in
 * the model's paragraph were false: a public IP has nothing to do with user
 * count, and 500 GB is a cap that more users exhaust faster, not capacity.
 *
 * So the Business plans are no longer in the catalogue unless the
 * conversation has established a reason for one. The model is not asked to
 * resist the word "business". It has nothing else to offer.
 *
 * ── WHAT COUNTS AS A REASON ─────────────────────────────────────────────
 *
 * What a Business plan actually provides is a PUBLIC IP: reaching your own
 * network from outside. Cameras recording to a box on site do not need one;
 * watching them from elsewhere does. A hotspot does not need one at all,
 * however many people use it. So bare "CCTV" is not a trigger and neither is
 * "hotspot" — only remote access, the named technical requirements, more than
 * one site, or the customer naming a Business plan themselves.
 *
 * A customer who asks for Business by name gets Business. They have asked,
 * the price is theirs to know, and PlanFenceGuard still attaches the cap.
 */
final class PlanCatalogue
{
    /** Requirements only a public IP satisfies. Ordinary trade is not one. */
    private const HARD = [
        'public ip', 'static ip', 'port forward', 'port-forward',
        'vpn', 'remote desktop', 'rdp', 'remote access', 'remote monitoring',
        'access control', 'web server', 'own server', 'host a', 'hosting',
        'surveillance from', 'nvr',
    ];

    /** Watching something of theirs from somewhere else. */
    private const REMOTE = [
        'remotely', 'from anywhere', 'from my phone', 'from another',
        'when i am away', "when i'm away", 'while i am away', 'off site',
        'off-site', 'from outside', 'from home', 'from abroad', 'from kampala',
    ];

    /** Cameras only matter here when paired with watching them from elsewhere. */
    private const CAMERA = ['cctv', 'camera', 'ip cam', 'dvr'];

    /** More than one site is a public-IP requirement in its own right. */
    private const MULTISITE = [
        'multiple location', 'multiple site', 'two location', 'two site',
        'branches', 'branch office', 'link our office', 'link the office',
        'connect our office', 'several site',
    ];

    public static function isBusinessPlan(array $product): bool
    {
        return (bool)preg_match('/\bbusiness\b/i', (string)($product['name'] ?? ''));
    }

    /**
     * Everything the customer has said, lowercased. Ours is not evidence.
     *
     * The live worker writes history rows as ['role' => 'customer'|'dishnet',
     * 'text' => ...]. Reading only 'body' here would have made this function
     * see nothing but the current message in production, so a customer who
     * said "we need a VPN" three turns ago would have had the Business plans
     * withheld when they asked "which one?". Every key any producer uses is
     * read, and test_plan_catalogue pins the worker's shape.
     */
    public static function customerText(array $ctx): string
    {
        $parts = [(string)($ctx['message'] ?? '')];
        foreach ((array)($ctx['history'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $role = strtolower(trim((string)($m['role'] ?? ($m['direction'] ?? ''))));
            // Only what THEY wrote. Counting our own replies would let the
            // assistant justify its last recommendation with itself.
            if ($role !== 'customer' && $role !== 'in' && $role !== 'user') continue;
            $parts[] = (string)($m['text'] ?? ($m['body'] ?? ($m['content'] ?? '')));
        }
        return strtolower(implode(' ', $parts));
    }

    /**
     * @return array{yes:bool, why:string}
     */
    public static function needsBusiness(array $ctx): array
    {
        $t = self::customerText($ctx);
        if ($t === '') return ['yes' => false, 'why' => ''];

        // Asked for one by name. Their money, their question.
        if (preg_match('/\bbusiness\s*(?:50|500|1\s*tb)\b/i', $t)) {
            return ['yes' => true, 'why' => 'they named a Business plan'];
        }
        foreach (self::HARD as $k) {
            if (strpos($t, $k) !== false) return ['yes' => true, 'why' => $k];
        }
        foreach (self::MULTISITE as $k) {
            if (strpos($t, $k) !== false) return ['yes' => true, 'why' => $k];
        }
        // Cameras plus watching them from elsewhere. Either alone is not it:
        // local recording needs no public IP, and "from anywhere" about a
        // phone signal is not a camera requirement.
        $cam = false;
        foreach (self::CAMERA as $k) { if (strpos($t, $k) !== false) { $cam = true; break; } }
        if ($cam) {
            foreach (self::REMOTE as $k) {
                if (strpos($t, $k) !== false) {
                    return ['yes' => true, 'why' => 'cameras viewed ' . $k];
                }
            }
        }
        return ['yes' => false, 'why' => ''];
    }

    /**
     * The catalogue this conversation may see.
     *
     * @return array{products:array, filtered:int, why:string}
     */
    public static function forConversation(array $products, array $ctx): array
    {
        $need = self::needsBusiness($ctx);
        if ($need['yes']) {
            return ['products' => $products, 'filtered' => 0, 'why' => $need['why']];
        }
        $kept = [];
        $dropped = 0;
        foreach ($products as $p) {
            if (is_array($p) && self::isBusinessPlan($p)) { $dropped++; continue; }
            $kept[] = $p;
        }
        // Never hand back an empty catalogue: a plugin with only Business
        // plans loaded would leave the assistant with nothing to quote, and
        // silence about prices is a worse failure than showing all of them.
        if (!$kept) return ['products' => $products, 'filtered' => 0, 'why' => 'nothing else to show'];
        return ['products' => $kept, 'filtered' => $dropped, 'why' => ''];
    }

    /** What to say if they ask for something not in front of us. */
    public const ASK_RULE =
        "- Business plans are not listed above for this conversation. Do not name one and do not "
      . "quote a Business price from memory. If the customer asks about Business, or describes "
      . "needing to reach their own network from outside — their cameras from elsewhere, a VPN, a "
      . "server, or more than one site — say you can check the Business options and ask what they "
      . "need it for. A PUBLIC IP is what a Business plan provides: it is for reaching your network "
      . "from outside, and it has NOTHING to do with how many people or devices use the connection. "
      . "Never offer it as a way to support more users; a hotspot with many users needs unlimited "
      . "standard data, not a priority-data cap.\n";
}
