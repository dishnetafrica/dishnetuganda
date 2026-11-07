<?php
declare(strict_types=1);

/**
 * QuoteChaseText — the approved wording of the quote chase (5.18.104, Uganda, root docs/73 §H.2.10.4; root docs/72 §H.1,
 * D9; K2-20, gate B-1). Pure.
 *
 * The six department wordings are root docs/72 §H.1's, byte for byte. `{ref}` is the quotation's number and `{date}` its
 * valid_until written `j F` (in English: "20 November"). No price, plan or customer name; STOP in each.
 *
 * On a salesperson's own number the first words become "Hello, this is {first name}'s assistant at DishNet." (§H.1):
 *   - LITERAL forms (1A, 1B, 7B): the approved text opens with a stand-alone greeting naming DishNet and nothing else,
 *     and that sentence is replaced; no other word changes. Approved by D9, they ship enabled;
 *   - COMPOSED forms (3A, 3B, 7A): words are added or dropped. They are proposals, held until the owner confirms them:
 *     the dated setting `sf_persona_confirmed` (written only by `tools/sales_followup.php set persona-confirmed <date>`).
 *     Until it is set, day 3 is held there (`persona_wording_pending`) and day 7 always uses B. Their bytes change only by
 *     the owner's B-1 answer (R104-13).
 *   - an owner line with no usable first name is given the department wording, whatever the date.
 *
 * A step here is the chase's step, 1, 2 or 3 (QuoteChasePolicy); the wording is named by its day, 1, 3 or 7. A template
 * id is `template:quote_chase_v1:<day><A|B>[p]`, `p` for the salesperson form; it goes in followup_drafts.model.
 * PHP 7.4.
 */
final class QuoteChaseText
{
    const VERSION = 'quote_chase_v1';
    /** The day each step's wording is named by. */
    const DAY = [1 => 1, 2 => 3, 3 => 7];
    /** A held day-3 step on a salesperson's number, before sf_persona_confirmed. */
    const PERSONA_PENDING = 'persona_wording_pending';

    /** root docs/72 §H.1, byte for byte. */
    const DEPARTMENT = [
        '1A' => 'Hello, this is DishNet. Do you have any questions about quotation {ref}? Reply here and we will help, or we can book your installation. Reply STOP if you do not want these messages.',
        '1B' => 'Hello from DishNet. We sent you quotation {ref}. Is anything unclear, or shall we book your installation? Just reply here. Reply STOP to stop these messages.',
        '3A' => 'Hello, a quick follow-up on quotation {ref} from DishNet. We can answer any question about the kit, the plan or the installation — just reply here. Reply STOP to stop these messages.',
        '3B' => 'Hello, DishNet here about quotation {ref}. If you would like to compare options or need help deciding, reply here and we will guide you. Reply STOP to stop these messages.',
        '7A' => 'Hello, quotation {ref} is valid until {date}. If you would like to go ahead, or need another option, reply here and we will take care of it. Reply STOP to stop these messages.',
        '7B' => 'Hello from DishNet. Your quotation {ref} is valid until {date}. Reply here to go ahead or to ask for a different option. Reply STOP to stop these messages.',
    ];

    /** The salesperson's greeting sentence (§H.1). */
    const PERSONA_SENTENCE = "Hello, this is {first name}'s assistant at DishNet.";

    /** Literal forms: the department greeting replaced, nothing else (docs/73 §H.2.10.4). Approved; enabled. */
    const LITERAL = [
        '1A' => "Hello, this is {first name}'s assistant at DishNet. Do you have any questions about quotation {ref}? Reply here and we will help, or we can book your installation. Reply STOP if you do not want these messages.",
        '1B' => "Hello, this is {first name}'s assistant at DishNet. We sent you quotation {ref}. Is anything unclear, or shall we book your installation? Just reply here. Reply STOP to stop these messages.",
        '7B' => "Hello, this is {first name}'s assistant at DishNet. Your quotation {ref} is valid until {date}. Reply here to go ahead or to ask for a different option. Reply STOP to stop these messages.",
    ];

    /** Composed forms (docs/73 §H.2.10.4): PROPOSED, held until sf_persona_confirmed (gate B-1; QM48). */
    const COMPOSED = [
        '3A' => "Hello, this is {first name}'s assistant at DishNet. A quick follow-up on quotation {ref}. We can answer any question about the kit, the plan or the installation — just reply here. Reply STOP to stop these messages.",
        '3B' => "Hello, this is {first name}'s assistant at DishNet, about quotation {ref}. If you would like to compare options or need help deciding, reply here and we will guide you. Reply STOP to stop these messages.",
        '7A' => "Hello, this is {first name}'s assistant at DishNet. Quotation {ref} is valid until {date}. If you would like to go ahead, or need another option, reply here and we will take care of it. Reply STOP to stop these messages.",
    ];

    /** sf_persona_confirmed holds: a Y-m-d date. */
    public static function personaConfirmed(array $config): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($config['sf_persona_confirmed'] ?? '')));
    }

    /**
     * The form a step sends, or null when the step is held on this line.
     *
     * @param string|null $persona null on a department line; the owner's first name on a salesperson's own line ('' when
     *                             it has none usable: the department wording)
     * @return array{key:string, persona:bool, held:?string}
     */
    public static function form(int $step, string $variant, ?string $persona, bool $confirmed): array
    {
        $day = self::DAY[$step] ?? 0;
        $v = $variant === 'B' ? 'B' : 'A';
        if ($persona === null || $persona === '') return ['key' => $day . $v, 'persona' => false, 'held' => null];
        if ($confirmed) return ['key' => $day . $v, 'persona' => true, 'held' => null];
        if ($day === 3) return ['key' => $day . $v, 'persona' => true, 'held' => self::PERSONA_PENDING];
        if ($day === 7) $v = 'B';
        return ['key' => $day . $v, 'persona' => true, 'held' => null];
    }

    /**
     * The text of a step, or null when it is held (a composed form before sf_persona_confirmed) or the step is unknown.
     *
     * @param string $validUntil the Kampala date Y-m-d
     */
    public static function render(int $step, string $variant, string $ref, string $validUntil, ?string $persona, bool $confirmed = false): ?string
    {
        $day = self::DAY[$step] ?? 0;
        if ($day === 0) return null;
        $key = $day . ($variant === 'B' ? 'B' : 'A');
        if ($persona === null || $persona === '') {
            $tpl = self::DEPARTMENT[$key];
        } elseif (isset(self::LITERAL[$key])) {
            $tpl = self::LITERAL[$key];
        } elseif ($confirmed && isset(self::COMPOSED[$key])) {
            $tpl = self::COMPOSED[$key];
        } else {
            return null;
        }
        return strtr($tpl, ['{first name}' => (string)$persona, '{ref}' => $ref, '{date}' => self::date($validUntil)]);
    }

    /** Does $body equal the rendered text, after CRLF and outer whitespace? */
    public static function matches(string $body, int $step, string $variant, string $ref, string $validUntil, ?string $persona, bool $confirmed = false): bool
    {
        $want = self::render($step, $variant, $ref, $validUntil, $persona, $confirmed);
        return $want !== null && self::same($body, $want);
    }

    /** The comparison approve() and preSendCheck make: \r\n becomes \n, both sides trimmed. */
    public static function same(string $a, string $b): bool
    {
        return trim(str_replace("\r\n", "\n", $a)) === trim(str_replace("\r\n", "\n", $b));
    }

    /** template:quote_chase_v1:<day><A|B>[p] */
    public static function templateId(int $step, string $variant, bool $persona): string
    {
        return 'template:' . self::VERSION . ':' . (self::DAY[$step] ?? 0) . ($variant === 'B' ? 'B' : 'A') . ($persona ? 'p' : '');
    }

    /** A template id read back: [step, variant, persona], or null when it is not one of this version's. */
    public static function parseId(string $model): ?array
    {
        if (!preg_match('/^template:' . preg_quote(self::VERSION, '/') . ':([137])([AB])(p?)$/', trim($model), $m)) return null;
        $step = array_search((int)$m[1], self::DAY, true);
        return $step === false ? null : [(int)$step, $m[2], $m[3] === 'p'];
    }

    /** valid_until written `j F`, English month names (the plugin's locale is never asked). */
    public static function date(string $ymd): string
    {
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($ymd), $m)) return '';
        $mo = (int)$m[2];
        if ($mo < 1 || $mo > 12) return '';
        return (int)$m[3] . ' ' . $months[$mo - 1];
    }
}
