<?php
declare(strict_types=1);

require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/VoiceAckLimits.php';

/**
 * SalesFollowupLadder — the one table of every 5.18.104 switch, and the engine that admits, skips and clears them
 * (5.18.104, Uganda, root docs/73 §H.2.12; contract C1, C6; rulings R5, R6, R7, R11, R13).
 *
 * The table is the single source (§H.2.12.2): tools/sales_followup.php, the checks, `off all`, the rollback, stage A's
 * rule and the key scan (TL14) all iterate it. Each row declares its keys, the options its `on` takes, its hard Needs
 * (each with its `pass_only` flag), its own precondition holders, its check, what its `off` also clears, and its
 * off-hook with the hook's place against the clearing of its keys. ORDER (every lower step on, skipped or optional;
 * no lower step left running at FAIL) is this engine's rule and is written in no row (ruling R6).
 *
 * Every setting of every part is declared once in SETTINGS (ruling R5): key, alias, type, range, owning part. Part V's
 * acknowledgement rows read their ranges from VoiceAckLimits (ruling R4) and hold no number here.
 *
 * The callables this engine dispatches to are ruling R13's table, all public static, each given the tool's read-only
 * connection $ro for a check or a precondition and its one writable connection $rw for a hook; nothing here opens a
 * connection. Output is counts, codes and dates; never a phone, a name or a key's secret value. PHP 7.4.
 */
final class SalesFollowupLadder
{
    /** The literal zone the ladder reads "today" in (a date option, a date setting). */
    public const TZ = 'Africa/Kampala';

    /** The minimum time on a needed step must have before its NOT_YET no_traffic may be accepted (§H.2.12.3). */
    const ACCEPT_AFTER_S = 259200;
    /** The longest the tool waits for the quote pass's lock or the offers sender's lock (B11-02). */
    const LOCK_WAIT_S = 70;
    /** TEST SEAM: key names left out of allKeys() and settingKeys() (wa_offers B02's keys_undeclared cases). Empty in production. */
    public static $drop = [];

    /**
     * The steps, in their one order (C6; row 1b after step 1, ruling R11). Per row:
     *   n         the C6 number          part      F | P | V | O
     *   optional  passed by ORDER whether on or off (1b and 10)
     *   voice     a voice rung: a voice step recorded skipped satisfies ORDER for later non-voice steps only
     *   switch    key => the value `on` writes; the first key is the one that says whether the step is on
     *   stamp     the since-stamp `on` writes (UTC, UtcClock::stamp)
     *   record    record keys the row writes; cleared only with the record keys, last (`off all`)
     *   ledger    `on` also writes the ledger's keys (step 2)
     *   options   the options its `on` takes, each required (§H.2.12.2, A12-03)
     *   needs     [step, check, pass_only, min_on_s]; step 'sending' is the ledger (wa_send_ledger = on)
     *   pre       the parts whose own precondition it runs, in order
     *   check     the check ids of its status (step 2: the worse of two)
     *   clears    the steps its `off` also clears (then theirs, recursively)
     *   hook      [part, place before|after its keys, when always|rollback]
     *   locks     the locks taken before its keys clear (B11-02)
     *   noskip    `skip` refused (step 1, row 1b)
     */
    const STEPS = [
        'report' => ['n' => '1', 'part' => 'F', 'optional' => false, 'voice' => false, 'switch' => [], 'stamp' => null,
            'record' => ['sf_report_ran_at'], 'ledger' => false,
            'options' => ['ran' => ['key' => 'sf_report_ran_at', 'type' => 'date']],
            'needs' => [], 'pre' => ['F'], 'check' => ['report'], 'clears' => [], 'hook' => null, 'locks' => [], 'noskip' => true],
        'agent-cc' => ['n' => '1b', 'part' => 'P', 'optional' => true, 'voice' => false, 'switch' => ['quote_agent_cc_enabled' => '1'],
            'stamp' => 'quote_agent_cc_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [], 'pre' => ['P'], 'check' => ['AC-ON'], 'clears' => [], 'hook' => null, 'locks' => [], 'noskip' => true],
        'ack' => ['n' => '2', 'part' => 'V', 'optional' => false, 'voice' => false, 'switch' => ['wa_voice_ack_enabled' => '1'],
            'stamp' => 'wa_voice_ack_since', 'record' => [], 'ledger' => true, 'options' => [],
            'needs' => [['step' => 'report', 'check' => 'report', 'pass_only' => false, 'min_on_s' => 0]],
            'pre' => ['F', 'V'], 'check' => ['L-ON', 'VA-ON1'], 'clears' => [], 'hook' => ['V', 'after', 'always'], 'locks' => [], 'noskip' => false],
        'tracking' => ['n' => '3', 'part' => 'F', 'optional' => false, 'voice' => false, 'switch' => ['quote_tracking_enabled' => '1'],
            'stamp' => 'quote_tracking_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'sending', 'check' => 'L-ON', 'pass_only' => false, 'min_on_s' => 0]],
            'pre' => [], 'check' => ['Q1-ON'], 'clears' => ['list', 'chase', 'cold', 'offers-test', 'offers-live'], 'hook' => null, 'locks' => [], 'noskip' => false],
        'list' => ['n' => '4', 'part' => 'F', 'optional' => false, 'voice' => false, 'switch' => ['quote_daily_list_enabled' => '1'],
            'stamp' => 'quote_daily_list_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'tracking', 'check' => 'Q1-ON', 'pass_only' => false, 'min_on_s' => 0]],
            'pre' => [], 'check' => ['Q2-ON'], 'clears' => [], 'hook' => null, 'locks' => [], 'noskip' => false],
        'voice-measure' => ['n' => '5', 'part' => 'V', 'optional' => false, 'voice' => true, 'switch' => ['ai_media_enabled' => '1'],
            'stamp' => 'ai_media_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'sending', 'check' => null, 'pass_only' => false, 'min_on_s' => 0]],
            'pre' => ['V'], 'check' => ['VN-ON1'], 'clears' => ['voice-dry-run', 'voice-staff', 'voice-reply'], 'hook' => ['V', 'after', 'always'], 'locks' => [], 'noskip' => false],
        'chase' => ['n' => '6', 'part' => 'F', 'optional' => false, 'voice' => false, 'switch' => ['quote_chase_enabled' => '1'],
            'stamp' => 'quote_chase_not_before', 'record' => ['sf_chase_first_on'], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'tracking', 'check' => 'Q1-ON', 'pass_only' => false, 'min_on_s' => 259200]],
            'pre' => ['F'], 'check' => ['Q3-ON'], 'clears' => ['cold'], 'hook' => ['F', 'before', 'rollback'], 'locks' => ['quote'], 'noskip' => false],
        'voice-dry-run' => ['n' => '7', 'part' => 'V', 'optional' => false, 'voice' => true,
            'switch' => ['ai_media_voice' => '1', 'ai_transcription_provider' => 'openai'],
            'stamp' => 'ai_media_voice_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'voice-measure', 'check' => 'VN-ON1', 'pass_only' => true, 'min_on_s' => 0]],
            'pre' => ['V'], 'check' => ['VN-ON2'], 'clears' => ['voice-staff', 'voice-reply'], 'hook' => null, 'locks' => [], 'noskip' => false],
        'voice-staff' => ['n' => '8', 'part' => 'V', 'optional' => false, 'voice' => true, 'switch' => ['ai_media_voice_handover' => '1'],
            'stamp' => 'ai_media_voice_handover_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'voice-dry-run', 'check' => 'VN-ON2', 'pass_only' => true, 'min_on_s' => 0]],
            'pre' => [], 'check' => ['VN-ON3'], 'clears' => ['voice-reply'], 'hook' => null, 'locks' => [], 'noskip' => false],
        'voice-reply' => ['n' => '9', 'part' => 'V', 'optional' => false, 'voice' => true, 'switch' => ['ai_media_voice_reply' => '1'],
            'stamp' => 'ai_media_voice_reply_since', 'record' => [], 'ledger' => false,
            'options' => ['channels' => ['key' => 'ai_media_voice_reply_channels', 'type' => 'channels'],
                          'owner-approved' => ['key' => 'ai_media_voice_reply_approved', 'type' => 'date']],
            'needs' => [['step' => 'voice-staff', 'check' => 'VN-ON3', 'pass_only' => true, 'min_on_s' => 0]],
            'pre' => [], 'check' => ['VN-ON4'], 'clears' => [], 'hook' => null, 'locks' => [], 'noskip' => false],
        'cold' => ['n' => '10', 'part' => 'F', 'optional' => true, 'voice' => false, 'switch' => ['quote_chase_cold' => '1'],
            'stamp' => null, 'record' => ['sf_cold_statement'], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'chase', 'check' => 'Q3-ON', 'pass_only' => false, 'min_on_s' => 259200]],
            'pre' => ['F'], 'check' => ['Q5-ON'], 'clears' => [], 'hook' => null, 'locks' => ['quote'], 'noskip' => false],
        'offers-test' => ['n' => '11', 'part' => 'O', 'optional' => false, 'voice' => false, 'switch' => ['offers_test_enabled' => '1'],
            'stamp' => null, 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'sending', 'check' => 'L-ON', 'pass_only' => true, 'min_on_s' => 0],
                        ['step' => 'tracking', 'check' => 'Q1-ON', 'pass_only' => false, 'min_on_s' => 0]],
            'pre' => ['O'], 'check' => ['offers-test'], 'clears' => ['offers-live'], 'hook' => ['O', 'before', 'always'], 'locks' => [], 'noskip' => false],
        'offers-live' => ['n' => '12', 'part' => 'O', 'optional' => false, 'voice' => false, 'switch' => ['offers_live_enabled' => '1'],
            'stamp' => 'offers_live_since', 'record' => [], 'ledger' => false, 'options' => [],
            'needs' => [['step' => 'offers-test', 'check' => 'offers-test', 'pass_only' => false, 'min_on_s' => 0]],
            'pre' => ['O'], 'check' => ['offers-live'], 'clears' => [], 'hook' => ['O', 'before', 'always'], 'locks' => [], 'noskip' => false],
    ];

    /** The ledger's keys (C3): written by step 2 or `skip ack`, cleared only by `off sending` or `off all`, last (ruling R6). */
    const LEDGER_KEYS = ['wa_send_ledger', 'wa_send_ledger_since'];

    /**
     * The one rollback sequence (rulings R7, R11): O (12, 11) → F (10, 6) → V (9, 8, 7, 5) → 4, 3 → 2 (P5's keys, then
     * the ledger last) → the record keys and Part P's two keys, last. `off <step>` clears its steps in this order too.
     */
    const SEQUENCE = ['offers-live', 'offers-test', 'cold', 'chase', 'voice-reply', 'voice-staff', 'voice-dry-run', 'voice-measure',
                      'list', 'tracking', 'ack'];

    /** The ladder's own record keys besides sf_skip_<step> and sf_override_<step> (§H.2.12.2). */
    const RECORD_KEYS = ['sf_report_ran_at', 'sf_cold_statement', 'sf_chase_first_on'];

    /**
     * Every setting of every part, once (§H.2.12.7; ruling R5): key => type, range, owning part, alias. An `int` row holds
     * min and max, or `ack` (its range is VoiceAckLimits::RANGES's, ruling R4); a pair (`gap`, `window`, `ack-hours`)
     * is validated together; `enum` lists its values or names its source; `date` is `past` (not after today) or `any`.
     */
    const SETTINGS = [
        'wa_send_daily_cap'                     => ['type' => 'int', 'min' => 1, 'max' => 60, 'part' => 'F', 'alias' => 'daily-cap'],
        'wa_send_hourly_cap'                    => ['type' => 'int', 'min' => 1, 'max' => 30, 'part' => 'F', 'alias' => 'hourly-cap'],
        'wa_send_gap_min_s'                     => ['type' => 'int', 'min' => 30, 'max' => 600, 'part' => 'F', 'pair' => 'gap'],
        'wa_send_gap_max_s'                     => ['type' => 'int', 'min' => 30, 'max' => 900, 'part' => 'F', 'pair' => 'gap'],
        'wa_send_hour_from'                     => ['type' => 'int', 'min' => 8, 'max' => 19, 'part' => 'F', 'pair' => 'window'],
        'wa_send_hour_to'                       => ['type' => 'int', 'min' => 9, 'max' => 20, 'part' => 'F', 'pair' => 'window'],
        'quote_daily_list_staff'                => ['type' => 'enum', 'values' => 'retailers', 'multi' => true, 'part' => 'F', 'alias' => 'list-staff'],
        'sf_persona_confirmed'                  => ['type' => 'date', 'when' => 'past', 'part' => 'F', 'alias' => 'persona-confirmed'],
        'wa_voice_ack_hour_from'                => ['type' => 'int', 'ack' => 'hour_from', 'part' => 'V', 'pair' => 'ack-hours'],
        'wa_voice_ack_hour_to'                  => ['type' => 'int', 'ack' => 'hour_to', 'part' => 'V', 'pair' => 'ack-hours'],
        'wa_voice_ack_gap_s'                    => ['type' => 'int', 'ack' => 'gap_s', 'part' => 'V', 'alias' => 'ack-gap'],
        'wa_voice_ack_daily_max'                => ['type' => 'int', 'ack' => 'daily_max', 'part' => 'V', 'alias' => 'ack-daily-max'],
        'wa_voice_ack_period_hours'             => ['type' => 'int', 'ack' => 'period_hours', 'part' => 'V'],
        'ai_media_max_bytes'                    => ['type' => 'int', 'min' => 65536, 'max' => 8388608, 'part' => 'V'],
        'ai_media_timeout_s'                    => ['type' => 'int', 'min' => 3, 'max' => 25, 'part' => 'V'],
        'ai_media_voice_timeout_s'              => ['type' => 'int', 'min' => 5, 'max' => 30, 'part' => 'V'],
        'ai_media_voice_max_seconds'            => ['type' => 'int', 'min' => 10, 'max' => 300, 'part' => 'V'],
        'ai_media_voice_daily_minutes'          => ['type' => 'int', 'min' => 0, 'max' => 600, 'part' => 'V'],
        'ai_media_voice_conversation_daily_max' => ['type' => 'int', 'min' => 1, 'max' => 50, 'part' => 'V'],
        'ai_media_openai_stt_model'             => ['type' => 'enum', 'values' => ['whisper-1', 'gpt-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe'], 'part' => 'V'],
        'ai_media_voice_privacy_line'           => ['type' => 'date', 'when' => 'past', 'part' => 'V'],
        'ai_media_voice_model_retires_on'       => ['type' => 'date', 'when' => 'any', 'part' => 'V'],
        'offers_warm_days'                      => ['type' => 'int', 'min' => 7, 'max' => 30, 'part' => 'O'],
        'offers_recent_days'                    => ['type' => 'int', 'min' => 7, 'max' => 60, 'part' => 'O'],
        'offers_daily_max_per_handset'          => ['type' => 'int', 'min' => 1, 'max' => 60, 'part' => 'O', 'within' => 'wa_send_daily_cap'],
        'offers_on_owned_numbers'               => ['type' => 'int', 'min' => 0, 'max' => 1, 'part' => 'O'],
        'offers_stop_pause_pct'                 => ['type' => 'int', 'min' => 1, 'max' => 20, 'part' => 'O'],
        'offers_max_days'                       => ['type' => 'int', 'min' => 1, 'max' => 30, 'part' => 'O'],
        'quote_agent_cc_admins'                 => ['type' => 'int', 'min' => 0, 'max' => 1, 'part' => 'P', 'alias' => 'agent-cc-admins'],
    ];

    /** The pairs `set` takes as one alias with two values, first then second (§H.2.12.7). */
    const PAIRS = [
        'gap'       => ['wa_send_gap_min_s', 'wa_send_gap_max_s'],
        'window'    => ['wa_send_hour_from', 'wa_send_hour_to'],
        'ack-hours' => ['wa_voice_ack_hour_from', 'wa_voice_ack_hour_to'],
    ];

    /**
     * The one declared list of pre-existing keys 5.18.104 reads and never writes (§H.2.12.4; ruling R10): Part F's reads,
     * Part V's (§H.3.5), Part O's shared reads (§H.4.6), Part P's (§H.5.10.1), and the Evolution and registry keys.
     */
    const PREEXISTING = [
        // Part F
        'timezone', 'followup_enabled', 'followup_auto_send', 'followup_daily_cap', 'followup_not_before',
        'multi_number_channels_enabled', 'wa_followups_on_owned_numbers', 'wa_force_accounts', 'kyc_messages_like_crm',
        'kyc_quote_validity_days', 'reminder_hour', 'reminder_until_hour',
        // Part V
        'ai_enabled', 'openai_api_key', 'ai_handover_message', 'wa_human_cooldown_minutes', 'wa_handover_copy_central',
        // Part P
        'quote_email_via_plugin', 'use_ucrm_email', 'smtp_host', 'smtp_from', 'smtp_user', 'sent_copy_enabled',
        'kyc_quote_send_via_crm', 'kyc_auto_quote_enabled',
        // the Evolution and registry keys Parts F, V and O read
        'evo_api_url', 'evo_api_key', 'evo_instance_sales', 'evo_instance_support', 'evo_instance_account',
        'evo_webhook_secret', 'tenant_profile', 'currency_code', 'cashbook_base_currency', 'alert_whatsapp',
        // the data directory a caller passes in its configuration (StarlinkMailWorker's hook reads it, HK13)
        '_data_dir',
    ];

    private function __construct() {}

    // ── the key accessors (ruling R5) ──────────────────────────────────────────────────────────────────────────

    /** The switch and record keys: what `off all` clears, TL7 checks and QF-G4 treats as NO-GO. @return string[] */
    public static function allKeys(): array
    {
        $out = [];
        foreach (self::STEPS as $name => $row) {
            foreach (self::rowKeys($name) as $k) $out[] = $k;
            foreach ($row['record'] as $k) $out[] = $k;
        }
        foreach (self::LEDGER_KEYS as $k) $out[] = $k;
        foreach (self::RECORD_KEYS as $k) $out[] = $k;
        foreach (array_keys(self::STEPS) as $name) {
            if (!self::STEPS[$name]['noskip']) $out[] = 'sf_skip_' . $name;
        }
        foreach (array_merge(array_keys(self::STEPS), ['sending']) as $name) $out[] = 'sf_override_' . $name;
        return array_values(array_diff(array_values(array_unique($out)), self::$drop));
    }

    /** Every part's declared settings: never cleared by `off`, never NO-GO. @return string[] */
    public static function settingKeys(): array
    {
        return array_values(array_diff(array_keys(self::SETTINGS), self::$drop));
    }

    /** The one declared list of pre-existing keys (TL14 consults it beside the two accessors). @return string[] */
    public static function preexistingKeys(): array
    {
        return self::PREEXISTING;
    }

    /** The keys a row's `off` unsets: its switch keys, its stamp, and its options' keys. @return string[] */
    public static function rowKeys(string $step): array
    {
        $row = self::STEPS[$step] ?? null;
        if ($row === null) return $step === 'sending' ? self::LEDGER_KEYS : [];
        $out = array_keys($row['switch']);
        if ($row['stamp'] !== null) $out[] = $row['stamp'];
        foreach ($row['options'] as $o) {
            if (!in_array($o['key'], $row['record'], true)) $out[] = $o['key'];
        }
        return $out;
    }

    /** The record keys `off all` clears last, with Part P's two keys (ruling R11). @return string[] */
    public static function lastKeys(): array
    {
        $out = [];
        foreach (self::allKeys() as $k) {
            if (strpos($k, 'sf_') === 0) $out[] = $k;
        }
        return array_values(array_unique(array_merge($out, self::rowKeys('agent-cc'))));
    }

    /** @return string[] the step names in their one order */
    public static function steps(): array
    {
        return array_keys(self::STEPS);
    }

    public static function row(string $step): ?array
    {
        return self::STEPS[$step] ?? null;
    }

    // ── a step's state ─────────────────────────────────────────────────────────────────────────────────────────

    /** A switch reads on: true, 1, or '1' / 'true' / 'on' / 'yes' in any case. */
    public static function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        return is_string($v) && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** The ledger is on: wa_send_ledger = 'on' exactly (WaSendBudget::on's key test). */
    public static function ledgerOn(array $config): bool
    {
        return (string)($config['wa_send_ledger'] ?? '') === 'on';
    }

    public static function isOn(array $config, string $step): bool
    {
        if ($step === 'sending') return self::ledgerOn($config);
        if ($step === 'report') return trim((string)($config['sf_report_ran_at'] ?? '')) !== '';
        $row = self::STEPS[$step] ?? null;
        if ($row === null) return false;
        $key = array_keys($row['switch'])[0];
        return self::truthy($config[$key] ?? null);
    }

    public static function isSkipped(array $config, string $step): bool
    {
        return trim((string)($config['sf_skip_' . $step] ?? '')) !== '';
    }

    /** The moment a step last came on (its since-stamp), or null when it has none or it cannot be read. */
    public static function sinceOf(array $config, string $step): ?int
    {
        $key = $step === 'sending' ? 'wa_send_ledger_since' : (self::STEPS[$step]['stamp'] ?? null);
        if ($step === 'report') $key = null;
        if ($key === null) return null;
        return self::parseTime($config[$key] ?? null);
    }

    /** A stored time: unix digits, or a stamp UtcClock reads; null when it gives no positive time. */
    public static function parseTime($v): ?int
    {
        if (is_int($v)) return $v > 0 ? $v : null;
        if (!is_string($v) || trim($v) === '') return null;
        $s = trim($v);
        $t = ctype_digit($s) ? (int)$s : UtcClock::parse($s);
        return $t > 0 ? $t : null;
    }

    /** The Kampala date of $t, Y-m-d. */
    public static function today(int $t): string
    {
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    // ── the one status vocabulary (ruling R6) ─────────────────────────────────────────────────────────────────

    /**
     * A check's status read strictly: PASS, FAIL <code>, NOT_YET too_soon|no_traffic|no_test. Anything else — WAIT, WARN,
     * an empty string, a note — is FAIL unknown_status, never taken as PASS (TL17).
     *
     * @return array{kind:string, code:string, status:string}
     */
    public static function status(string $s): array
    {
        $s = trim($s);
        if ($s === 'PASS') return ['kind' => 'PASS', 'code' => '', 'status' => 'PASS'];
        if (preg_match('/^FAIL ([A-Za-z0-9_:.\-]+)$/', $s, $m)) return ['kind' => 'FAIL', 'code' => $m[1], 'status' => $s];
        if (preg_match('/^NOT_YET (too_soon|no_traffic|no_test)$/', $s, $m)) return ['kind' => 'NOT_YET', 'code' => $m[1], 'status' => $s];
        return ['kind' => 'FAIL', 'code' => 'unknown_status', 'status' => 'FAIL unknown_status'];
    }

    /**
     * A step's one status: its check, or for step 2 the worse of L-ON and VA-ON1 (FAIL before NOT_YET before PASS; L-ON's
     * code where both give the status). $checker(id) answers a check's result array.
     *
     * @return array{kind:string, code:string, status:string, parts:array<string,array>}
     */
    public static function stepStatus(string $step, callable $checker): array
    {
        $row = self::STEPS[$step] ?? null;
        if ($row === null) return ['kind' => 'FAIL', 'code' => 'unknown_step', 'status' => 'FAIL unknown_step', 'parts' => []];
        $rank = ['PASS' => 0, 'NOT_YET' => 1, 'FAIL' => 2];
        $best = null; $parts = [];
        foreach ($row['check'] as $id) {
            $r = $checker($id);
            $parts[$id] = is_array($r) ? $r : [];
            $s = self::status((string)($parts[$id]['status'] ?? ''));
            if ($best === null || $rank[$s['kind']] > $rank[$best['kind']]) $best = $s;
        }
        $best = $best ?? self::status('');
        $best['parts'] = $parts;
        return $best;
    }

    // ── admission: rules 2, 3 and 4 (§H.2.12.3) ────────────────────────────────────────────────────────────────

    /**
     * Whether `on <step>` may proceed past ORDER, the running FAILs and the Needs. Rule 1 (tenant, uid) is the tool's;
     * rule 5 (options, then the row's own precondition) runs after this.
     *
     * @param ?string  $accept  the --accept-not-yet reason, or null
     * @param callable $checker fn(string $id): array{status, counts, notes}
     * @return array{ok:bool, code:?string, lines:list<string>, overrides:array<string,string>}
     */
    public static function admit(array $config, string $step, ?string $accept, int $now, callable $checker): array
    {
        $no = function (string $code, array $lines): array { return ['ok' => false, 'code' => $code, 'lines' => $lines, 'overrides' => []]; };
        $row = self::STEPS[$step] ?? null;
        if ($row === null) return $no('unknown_step', []);
        $lines = [];
        $lower = array_slice(self::steps(), 0, (int)array_search($step, self::steps(), true));
        // Rule 2: every lower step on, skipped, or optional (a skipped voice step never serves a voice step).
        foreach ($lower as $l) {
            if (self::STEPS[$l]['optional']) continue;
            if (self::isOn($config, $l)) continue;
            if (self::isSkipped($config, $l) && !(self::STEPS[$l]['voice'] && $row['voice'])) continue;
            return $no('order:' . $l, ['step ' . self::STEPS[$l]['n'] . ' (' . $l . ') is neither on nor recorded skipped']);
        }
        // Rule 3: no lower step that is on reads FAIL now (step 2 by its one status).
        foreach ($lower as $l) {
            if (!self::isOn($config, $l)) continue;
            $s = self::stepStatus($l, $checker);
            if ($s['kind'] === 'FAIL') {
                return $no('earlier_fail:' . $l, ['step ' . self::STEPS[$l]['n'] . ' (' . $l . ') reads ' . $s['status'] . ': turn it off or skip it first']);
            }
        }
        // Rule 4: every need on (not skipped), on long enough, its check at PASS or an accepted NOT_YET no_traffic.
        $overrides = [];
        foreach ($row['needs'] as $need) {
            $n = $need['step'];
            $label = $n === 'sending' ? 'the ledger' : 'step ' . self::STEPS[$n]['n'] . ' (' . $n . ')';
            if (!self::isOn($config, $n)) {
                return $no('need_off:' . $n, [$label . ' is not on' . (self::isSkipped($config, $n) ? ' (recorded skipped, which no need accepts)' : '')]);
            }
            $since = self::sinceOf($config, $n);
            if ($need['min_on_s'] > 0 && ($since === null || $now - $since < $need['min_on_s'])) {
                return $no('need_too_soon:' . $n, [$label . ' has not been on for ' . intdiv($need['min_on_s'], 3600) . ' h']);
            }
            if ($need['check'] === null) continue;
            $s = self::status((string)(($checker($need['check']))['status'] ?? ''));
            if ($s['kind'] === 'PASS') { $lines[] = $label . ': ' . $need['check'] . ' PASS'; continue; }
            if ($s['kind'] === 'NOT_YET' && $s['code'] === 'no_traffic' && !$need['pass_only']) {
                $old = trim((string)($config['sf_override_' . $n] ?? ''));
                $long = $since !== null && $now - $since >= self::ACCEPT_AFTER_S;
                if ($long && ($old !== '' || ($accept !== null && trim($accept) !== ''))) {
                    if ($old === '') $overrides['sf_override_' . $n] = UtcClock::stamp($now) . '|' . trim((string)$accept);
                    $lines[] = $label . ': ' . $need['check'] . ' NOT_YET no_traffic, accepted';
                    continue;
                }
                return $no('need_not_yet:' . $n, [$label . ': ' . $need['check'] . ' reads ' . $s['status']
                    . ($long ? ' (--accept-not-yet "<reason>" accepts it)' : ' (it may be accepted once it has been on for 72 h)')]);
            }
            return $no(($s['kind'] === 'FAIL' ? 'need_fail:' : 'need_not_pass:') . $n,
                       [$label . ': ' . $need['check'] . ' reads ' . $s['status'] . ($need['pass_only'] ? ' (this need is met only by PASS)' : '')]);
        }
        return ['ok' => true, 'code' => null, 'lines' => $lines, 'overrides' => $overrides];
    }

    // ── skip and off: the cascades and the one sequence ────────────────────────────────────────────────────────

    /** The steps a `skip` of $step also records skipped: every step whose Needs name it, recursively, in order. @return string[] */
    public static function skipCascade(string $step): array
    {
        $out = [];
        $todo = [$step];
        while ($todo !== []) {
            $s = array_shift($todo);
            foreach (self::STEPS as $name => $row) {
                foreach ($row['needs'] as $need) {
                    if ($need['step'] === $s && !in_array($name, $out, true) && $name !== $step) { $out[] = $name; $todo[] = $name; }
                }
            }
        }
        return array_values(array_filter(self::steps(), function ($n) use ($out) { return in_array($n, $out, true); }));
    }

    /** The steps `off <step>` clears: the step and its "off also clears", recursively, in the one sequence. @return string[] */
    public static function offCascade(string $step): array
    {
        if (!isset(self::STEPS[$step])) return [];
        $set = [$step];
        $todo = [$step];
        while ($todo !== []) {
            $s = array_shift($todo);
            foreach (self::STEPS[$s]['clears'] as $c) {
                if (!in_array($c, $set, true)) { $set[] = $c; $todo[] = $c; }
            }
        }
        return self::inSequence($set);
    }

    /** $steps ordered by the one sequence (row 1b, never in it, last). @return string[] */
    public static function inSequence(array $steps): array
    {
        $out = [];
        foreach (self::SEQUENCE as $s) if (in_array($s, $steps, true)) $out[] = $s;
        foreach ($steps as $s) if (!in_array($s, $out, true)) $out[] = $s;
        return $out;
    }

    /**
     * What `off <what>` does, in order: each action is [kind, name] with kind 'step' (its hook and keys), 'ledger' (the
     * ledger's keys, under both locks) or 'last' (the record keys and Part P's two keys). @return array<int,array{0:string,1:string}>
     */
    public static function offPlan(string $what): array
    {
        if ($what === 'all') {
            $plan = [];
            foreach (self::SEQUENCE as $s) $plan[] = ['step', $s];
            $plan[] = ['ledger', 'sending'];
            $plan[] = ['last', 'records'];
            return $plan;
        }
        if ($what === 'sending') return [['ledger', 'sending']];
        if ($what === 'agent-cc') return [['step', 'agent-cc']];
        $plan = [];
        foreach (self::offCascade($what) as $s) $plan[] = ['step', $s];
        return $plan;
    }

    /** A row's off-hook, or null: [part, place, when]. */
    public static function hookOf(string $step): ?array
    {
        return self::STEPS[$step]['hook'] ?? null;
    }

    /** The locks taken before $step's keys clear ('quote': the quote pass's; 'offers': the offers sender's). @return string[] */
    public static function locksOf(string $step): array
    {
        if ($step === 'sending') return ['quote', 'offers'];
        return self::STEPS[$step]['locks'] ?? [];
    }

    // ── the dispatch: ruling R13's table ───────────────────────────────────────────────────────────────────────

    /** Load a part's holder from this directory (each holder loads what it needs itself). */
    public static function load(string $part): void
    {
        $files = ['F' => ['SalesFollowupChecks', 'QuoteChase'], 'V' => ['VoiceSteps'], 'O' => ['OfferSwitch'], 'P' => ['QuoteAgentCopy']];
        foreach ($files[$part] ?? [] as $c) {
            if (!class_exists($c)) require_once __DIR__ . '/' . $c . '.php';
        }
    }

    /**
     * A check by id, dispatched to the callable ruling R13 names, given the tool's $ro. 'report' is the engine's own:
     * PASS once sf_report_ran_at is set.
     *
     * @return array{status:string, counts:array<string,int>, notes:list<string>}
     */
    public static function check(\PDO $ro, array $config, string $dataDir, string $pluginRoot, string $id, int $now): array
    {
        try {
            switch ($id) {
                case 'report':
                    return ['status' => self::isOn($config, 'report') ? 'PASS' : 'FAIL report_not_run', 'counts' => [], 'notes' => []];
                case 'AC-ON':
                    self::load('P');
                    return \QuoteAgentCopy::check($ro, $pluginRoot, $dataDir, $now);
                case 'L-ON': case 'Q1-ON': case 'Q2-ON': case 'Q3-ON': case 'Q5-ON':
                    self::load('F');
                    return \SalesFollowupChecks::check($ro, $config, $dataDir, $id, $now);
                case 'VA-ON1': case 'VN-ON1': case 'VN-ON2': case 'VN-ON3': case 'VN-ON4':
                    self::load('V');
                    return \VoiceSteps::check($ro, $config, $dataDir, $id, $now);
                case 'offers-test':
                    self::load('O');
                    return \OfferSwitch::checkTest($ro, $config, $dataDir, $now);
                case 'offers-live':
                    self::load('O');
                    return \OfferSwitch::checkLive($ro, $config, $dataDir, $now);
            }
        } catch (\Throwable $e) {
            return ['status' => 'FAIL check_threw', 'counts' => [], 'notes' => ['the check ' . $id . ' could not run (' . get_class($e) . ')']];
        }
        return ['status' => 'FAIL unknown_check', 'counts' => [], 'notes' => []];
    }

    /**
     * A row's own precondition: each part's half in order, the first refusal answering; ruling R13's shape. Row 1b's is
     * QuoteAgentCopy::preconditions($dataDir), given no connection; rows 8 and 9 have none.
     *
     * @return array{ok:bool, code:?string, lines:list<string>}
     */
    public static function precondition(\PDO $ro, array $config, string $dataDir, string $step): array
    {
        $row = self::STEPS[$step] ?? null;
        if ($row === null) return ['ok' => false, 'code' => 'unknown_step', 'lines' => []];
        $lines = [];
        foreach ($row['pre'] as $part) {
            self::load($part);
            try {
                switch ($part) {
                    case 'F': $p = \SalesFollowupChecks::preconditions($ro, $config, $dataDir, $step); break;
                    case 'V': $p = \VoiceSteps::preconditions($ro, $config, $dataDir, $step); break;
                    case 'O': $p = \OfferSwitch::preconditions($ro, $config, $dataDir, $step); break;
                    case 'P': $p = \QuoteAgentCopy::preconditions($dataDir); break;
                    default:  $p = ['ok' => false, 'code' => 'unknown_part', 'lines' => []];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'code' => 'precondition_threw', 'lines' => array_merge($lines, ['the precondition of part ' . $part . ' could not run (' . get_class($e) . ')'])];
            }
            $lines = array_merge($lines, array_map('strval', (array)($p['lines'] ?? [])));
            if (empty($p['ok'])) {
                $code = (string)($p['code'] ?? '');
                return ['ok' => false, 'code' => $code !== '' ? $code : 'precondition', 'lines' => $lines];
            }
        }
        return ['ok' => true, 'code' => null, 'lines' => $lines];
    }

    /**
     * A row's off-hook on the tool's $rw, $why 'switch' or 'rollback'. Returns its counts; throws on failure (the tool
     * reports it by the hook's place, §H.2.12.4). A hook whose `when` is rollback runs only in the rollback.
     *
     * @return array<string,int>|null null when the row has no hook to run now
     */
    public static function runHook(\PDO $rw, array $config, string $dataDir, string $step, string $why): ?array
    {
        $h = self::hookOf($step);
        if ($h === null) return null;
        if ($h[2] === 'rollback' && $why !== 'rollback') return null;
        self::load($h[0]);
        switch ($h[0]) {
            case 'F': return \QuoteChase::offHook($rw, $config, $dataDir, $step, $why);
            case 'V': return \VoiceSteps::offHook($rw, $config, $dataDir, $step, $why);
            case 'O': return \OfferSwitch::offHook($rw, $config, $dataDir, $step, $why);
        }
        throw new \RuntimeException('no holder for this hook');
    }

    // ── options and settings (rule 5; §H.2.12.7) ───────────────────────────────────────────────────────────────

    /**
     * A row's options, validated by their declarations before any precondition is called: each declared option present,
     * none undeclared, each value inside its declaration. $known lists the channel ids a `channels` option may name.
     *
     * @param array<string,string> $opts the options given (without the tool's own: accept-not-yet)
     * @return array{ok:bool, code:?string, line:string, keys:array<string,string>}
     */
    public static function validateOptions(string $step, array $opts, array $known, int $now): array
    {
        $no = function (string $code, string $line): array { return ['ok' => false, 'code' => $code, 'line' => $line, 'keys' => []]; };
        $row = self::STEPS[$step] ?? null;
        if ($row === null) return $no('unknown_step', 'no such step');
        foreach (array_keys($opts) as $o) {
            if (!isset($row['options'][$o])) return $no('option_undeclared', '--' . $o . ' is not an option of ' . $step);
        }
        $keys = [];
        foreach ($row['options'] as $name => $decl) {
            if (!array_key_exists($name, $opts) || trim((string)$opts[$name]) === '') return $no('option_missing', $step . ' needs --' . $name);
            $v = trim((string)$opts[$name]);
            if ($decl['type'] === 'date') {
                if (!self::dateOk($v, 'past', $now)) return $no('option_invalid', '--' . $name . ' must be a YYYY-MM-DD date not after today (Kampala)');
            } elseif ($decl['type'] === 'channels') {
                $v = strtolower($v);
                if ($v !== 'all') {
                    $ids = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
                    if ($ids === []) return $no('option_invalid', '--' . $name . ' names no channel');
                    foreach ($ids as $id) {
                        if (!in_array($id, $known, true)) return $no('option_invalid', '--' . $name . ' names a channel that is not known here');
                    }
                    $v = implode(',', array_values(array_unique($ids)));
                }
            }
            $keys[$decl['key']] = $v;
        }
        return ['ok' => true, 'code' => null, 'line' => '', 'keys' => $keys];
    }

    /** A YYYY-MM-DD date; 'past' also requires it not after today in Kampala. */
    public static function dateOk(string $v, string $when, int $now): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return false;
        return $when !== 'past' || strcmp($v, self::today($now)) <= 0;
    }

    /** A setting's row by key or alias: [keys, row of the first]. Null for anything not declared. */
    public static function settingOf(string $name): ?array
    {
        if (isset(self::PAIRS[$name])) return self::PAIRS[$name];
        if (isset(self::SETTINGS[$name])) return [$name];
        foreach (self::SETTINGS as $k => $r) {
            if (($r['alias'] ?? '') === $name) return [$k];
        }
        return null;
    }

    /** A setting's inclusive range [min, max] (an acknowledgement row: VoiceAckLimits::RANGES, ruling R4). */
    public static function rangeOf(string $key): ?array
    {
        $r = self::SETTINGS[$key] ?? null;
        if ($r === null || $r['type'] !== 'int') return null;
        if (isset($r['ack'])) return VoiceAckLimits::RANGES[$r['ack']] ?? null;
        return [(int)$r['min'], (int)$r['max']];
    }

    /**
     * `set <key|alias> <value…>`, validated by the declared row(s); nothing is saved on a refusal and nothing is clamped.
     * A switch key, a record key, a pre-existing key or an undeclared name is refused. $retailerIds lists the active
     * accounts' ids (for list-staff).
     *
     * @param string[] $values
     * @return array{ok:bool, code:?string, line:string, changes:array<string,string>}
     */
    public static function validateSet(string $name, array $values, array $config, array $retailerIds, int $now): array
    {
        $no = function (string $code, string $line): array { return ['ok' => false, 'code' => $code, 'line' => $line, 'changes' => []]; };
        if (in_array($name, self::allKeys(), true) || in_array($name, self::preexistingKeys(), true)) {
            return $no('not_a_setting', $name . ' is not a setting: `set` never writes a switch, a record or an existing key');
        }
        $keys = self::settingOf($name);
        if ($keys === null) return $no('undeclared', $name . ' is not a declared setting');
        $values = array_values(array_map(function ($v) { return trim((string)$v); }, $values));
        if (count($values) !== count($keys)) return $no('arity', $name . ' takes ' . count($keys) . ' value' . (count($keys) > 1 ? 's' : ''));
        $changes = [];
        foreach ($keys as $i => $k) {
            $r = self::SETTINGS[$k];
            $v = $values[$i];
            if ($r['type'] === 'int') {
                if (!preg_match('/^\d{1,9}$/', $v)) return $no('out_of_range', $k . ' takes a whole number');
                [$lo, $hi] = self::rangeOf($k);
                if ((int)$v < $lo || (int)$v > $hi) return $no('out_of_range', $k . ' must be within ' . $lo . '–' . $hi);
                $changes[$k] = (string)(int)$v;
            } elseif ($r['type'] === 'date') {
                if (!self::dateOk($v, $r['when'], $now)) return $no('out_of_range', $k . ' takes a YYYY-MM-DD date' . ($r['when'] === 'past' ? ' not after today' : ''));
                $changes[$k] = $v;
            } elseif ($r['type'] === 'enum' && ($r['values'] ?? null) === 'retailers') {
                $ids = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
                if ($ids === []) return $no('out_of_range', $k . ' takes one or more account ids');
                foreach ($ids as $id) {
                    if (!ctype_digit($id) || !in_array((int)$id, $retailerIds, true)) return $no('out_of_range', $k . ' takes active account ids only');
                }
                $changes[$k] = implode(',', array_values(array_unique($ids)));
            } elseif ($r['type'] === 'enum') {
                if (!in_array($v, (array)$r['values'], true)) return $no('out_of_range', $k . ' is one of ' . implode(', ', (array)$r['values']));
                $changes[$k] = $v;
            } else {
                return $no('undeclared', $k . ' has no type');
            }
        }
        // The pairs: the second bounded by the first, as the stored partner reads when only one is given.
        foreach (self::PAIRS as $pair => [$a, $b]) {
            if (!isset($changes[$a]) && !isset($changes[$b])) continue;
            $va = (int)($changes[$a] ?? self::storedInt($config, $a));
            $vb = (int)($changes[$b] ?? self::storedInt($config, $b));
            $lowB = $pair === 'gap' ? $va : $va + 1;
            if ($vb < $lowB) return $no('out_of_range', $b . ' must be at least ' . $lowB . ' with ' . $a . ' ' . $va);
        }
        foreach (self::SETTINGS as $k => $r) {
            if (!isset($r['within'])) continue;
            if (!isset($changes[$k])) continue;   // a narrower cap is never refused for another part's ceiling
            $cap = isset($changes[$r['within']]) ? (int)$changes[$r['within']] : self::storedInt($config, $r['within']);
            if ((int)$changes[$k] > $cap) return $no('out_of_range', $k . ' may not exceed ' . $r['within'] . ' (' . $cap . ')');
        }
        return ['ok' => true, 'code' => null, 'line' => '', 'changes' => $changes];
    }

    /** A setting as stored, or its default (the defaults each part's reader applies). */
    private static function storedInt(array $config, string $key): int
    {
        $v = $config[$key] ?? null;
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^\s*\d{1,9}\s*$/', $v)) return (int)trim($v);
        $r = self::SETTINGS[$key] ?? [];
        if (isset($r['ack'])) return (int)(VoiceAckLimits::DEFAULTS[$r['ack']] ?? 0);
        $defaults = ['wa_send_daily_cap' => 60, 'wa_send_hourly_cap' => 15, 'wa_send_gap_min_s' => 60, 'wa_send_gap_max_s' => 180,
                     'wa_send_hour_from' => 8, 'wa_send_hour_to' => 20, 'offers_daily_max_per_handset' => 20];
        return $defaults[$key] ?? 0;
    }

    /** A --reason (or a typed statement): free text with no run of 9 or more digits, so no phone enters the record. */
    public static function reasonOk(string $s): bool
    {
        return trim($s) !== '' && !preg_match('/\d{9,}/', (string)preg_replace('/[\s().+\-]/', '', $s));
    }
}
