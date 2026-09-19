<?php
declare(strict_types=1);

/**
 * PlanFenceGuard — the priority-data fact, attached in code rather than asked for.
 *
 * ── WHY THIS IS NOT A PROMPT RULE ───────────────────────────────────────
 *
 * It was one, for a day. 5.18.22 put it in the qualification block: a customer
 * saying they are a business is not a reason to quote a Business plan; the tier
 * numbers are amounts of priority data; the line drops to about 1 Mbps when
 * they run out; lead with the higher-capacity Residential plan.
 *
 * It was verified present in the live prompt — 29,029 characters, all of it
 * there. Then it was measured against what customers actually received. Of 21
 * replies to someone who had just said "business", 18 named a Business plan
 * and never mentioned Residential at all. That is 86%, against 91% in the
 * eight days before the rule existed. The model matches the word to the
 * product with the same name in it, and 7,000 characters of instruction in
 * front of that did not move it.
 *
 * One of those replies told a customer setting up a Wi-Fi hotspot that they
 * would "likely need a public IP for better management" — a justification the
 * model invented for a capped plan that is worst for exactly that use.
 *
 * So the fact is no longer requested. It is appended here, deterministically,
 * to any reply naming a Business plan without naming a Residential one. This
 * is the same doctrine FollowUpEvaluator states for account facts: telling a
 * model what to say is a request, and a request fails silently the first time
 * the model is confused. The difference is that omission cannot work here —
 * the Business plans must stay quotable, because customers ask for them by
 * name and are entitled to the price — so the enforcement is addition instead.
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────
 *
 * It does not block, rewrite, or second-guess the recommendation. A customer
 * who wants a Business plan may have one: they have been told, and it is their
 * money. This adds one sentence of fact so that the choice is an informed one.
 *
 * It names no price. Prices come from uCRM and only from uCRM, so a figure
 * written here would become a second catalogue that goes quietly stale. The
 * wording lives in config, so an operator changes it in Settings without a
 * release, and "omit" switches it off entirely — the same contract every other
 * operator fact in this plugin already honours.
 */
final class PlanFenceGuard
{
    /** Overridden by ai_fact_business_cap. "omit" turns the fence off. */
    public const DEFAULT_NOTE =
        'The Business tier numbers — 50 GB, 500 GB and 1 TB — are amounts of priority data, '
      . 'not speeds. When that data is used up the connection drops to about 1 Mbps until more '
      . 'is bought. Starlink Residential has unlimited standard data at the same 400 Mbps download.';

    /**
     * A Business PLAN, not the word "business".
     *
     * "it is for my business" and "business hours" must not trip this; the
     * tier number or the word plan is what makes it a product reference.
     */
    public static function namesBusinessPlan(string $reply): bool
    {
        return (bool)preg_match('/\bbusiness\s*(?:50|500|1\s*tb|plans?\b)/i', $reply);
    }

    /** Residential, Residential Lite — either means the alternative was offered. */
    public static function namesResidentialPlan(string $reply): bool
    {
        return (bool)preg_match('/\bresidential\b/i', $reply);
    }

    /** The operator's wording, or ours; '' when they have switched it off. */
    public static function note(array $config): string
    {
        $v = trim((string)($config['ai_fact_business_cap'] ?? ''));
        if ($v === '') return self::DEFAULT_NOTE;
        return strtolower($v) === 'omit' ? '' : $v;
    }

    /**
     * @return array{reply:string, appended:bool, reason:string}
     */
    public static function apply(string $reply, array $config): array
    {
        $keep = static fn(string $why): array
            => ['reply' => $reply, 'appended' => false, 'reason' => $why];

        if (trim($reply) === '')              return $keep('empty reply');
        $note = self::note($config);
        if ($note === '')                     return $keep('ai_fact_business_cap is omit');
        if (!self::namesBusinessPlan($reply)) return $keep('no Business plan named');
        if (self::namesResidentialPlan($reply)) {
            return $keep('Residential is already named — the customer has the comparison');
        }
        // The model sometimes gets there on its own. Saying it twice in one
        // message reads like a machine, and undermines the sentence that matters.
        if (preg_match('/priority data|1\s*mbps/i', $reply)) {
            return $keep('the reply already carries the fact in its own words');
        }
        if (strpos($reply, $note) !== false)  return $keep('the note is already present');

        return ['reply' => rtrim($reply) . "\n\n" . $note, 'appended' => true,
                'reason' => 'Business plan named without Residential'];
    }
}
