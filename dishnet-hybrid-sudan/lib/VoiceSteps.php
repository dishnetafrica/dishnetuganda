<?php
declare(strict_types=1);

require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/VoiceAckLimits.php';
require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/HumanTakeover.php';
require_once __DIR__ . '/VoiceAck.php';

/**
 * VoiceSteps — every callable the switch tool's ladder dispatches to for Part V's rows (5.18.104, Uganda, root docs/73
 * §H.3.5, §H.3.20; rulings R6, R13, R14, R18).
 *
 *   preconditions($ro, $config, $dataDir, $step)       rows 2 (Part V's half), 5 and 7; rows 8 and 9 have none
 *   check($ro, $config, $dataDir, $id, $now)           VA-ON1 (step 2), VN-ON1 (5), VN-ON2 (7), VN-ON3 (8), VN-ON4 (9)
 *   offHook($rw, $config, $dataDir, $step, $why)       rows 2 ('ack') and 5 ('voice-measure')
 *
 * A check and a precondition read the book only through the tool's read-only `$ro`; a hook writes only through the
 * tool's `$rw`; nothing here opens a connection (ruling R13). A check returns `PASS`, `FAIL <code>` or
 * `NOT_YET too_soon|no_traffic` with counts and notes (ruling R6), and judges each past act at the moment it was decided,
 * from what its maker could read then, under the limits recorded with it — never the settings as they read now (ruling
 * R18). What its maker could not know is a count, never a FAIL. A later rung's rows never count against an earlier
 * check (own rung, §H.3.20). Output is counts, fixed codes and dates; never a key, a phone or a transcript. PHP 7.4.
 */
final class VoiceSteps
{
    /** The literal zone the checks compute hours and days in (§H.3.20). */
    public const TZ = 'Africa/Kampala';

    /** Each check's since-stamp key, minimum time on and the rung its own rows carry (§H.3.20). */
    const CHECKS = [
        'VA-ON1' => ['since' => 'wa_voice_ack_since',            'min_s' => 86400,  'rung' => null],
        'VN-ON1' => ['since' => 'ai_media_since',                'min_s' => 172800, 'rung' => 'fetch_only'],
        'VN-ON2' => ['since' => 'ai_media_voice_since',          'min_s' => 172800, 'rung' => 'dry_run'],
        'VN-ON3' => ['since' => 'ai_media_voice_handover_since', 'min_s' => 604800, 'rung' => 'handover'],
        'VN-ON4' => ['since' => 'ai_media_voice_reply_since',    'min_s' => 259200, 'rung' => 'reply'],
    ];

    /** The event window: events are deleted 30 days after they are processed (cron_maintenance's prune(30)). */
    const EVENT_WINDOW_S = 29 * 86400;
    /** VN-ON4's switch margin (§H.3.20). */
    const SWITCH_MARGIN_S = 60;
    /** VA-ON1: a claimed decision this old beside its ledger row is no longer in progress; the ledger's in-flight bound. */
    const IN_PROGRESS_S = 900;
    /** VN-ON3: a competing escalation this close before a holding line makes the line ambiguous. */
    const LINE_WINDOW_S = 300;
    /** VN-ON3: a holding line this soon after an acknowledgement is the R-19 fault. */
    const ACK_LINE_S = 600;
    /** The ledger's details for a row the sweep settled (Part F §H.2.5.3 steps 1 and 3a). */
    const SWEPT = ['stale_orphan', 'stale'];

    /** TEST SEAM: the hooks' bounded drain, in seconds (D: up to 3 min), and their poll interval. */
    public static $drainBoundS = 180;
    public static $drainPollUs = 500000;

    private function __construct() {}

    // ── preconditions ──────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, code:?string, lines:list<string>} */
    public static function preconditions(\PDO $ro, array $config, string $dataDir, string $step): array
    {
        $ok = function (array $lines): array { return ['ok' => true, 'code' => null, 'lines' => $lines]; };
        $no = function (string $code, array $lines): array { return ['ok' => false, 'code' => $code, 'lines' => $lines]; };
        switch ($step) {
            case 'ack':
                // Part V's half of row 2: the assistant on (run_worker.php returns early without it), the limits readable.
                if (!self::flag($config['ai_enabled'] ?? null)) return $no('ai_disabled', ['ai_enabled: off']);
                if (VoiceAckLimits::of($config) === null) return $no('limits_invalid', ['VoiceAckLimits: INVALID']);
                return $ok(['ai_enabled: on', 'VoiceAckLimits: valid']);
            case 'voice-measure':
                $row = self::schemaRow($ro, '090_wa_voice.sql');
                return $row ? $ok(['_schema_checks 090_wa_voice.sql: present'])
                            : $no('schema_check_missing:090_wa_voice.sql', ['_schema_checks 090_wa_voice.sql: absent']);
            case 'voice-dry-run':
                if (!MediaPolicy::privacyLineOk($config)) return $no('privacy_line_missing', ['ai_media_voice_privacy_line: not a date on or before today']);
                if (!MediaPolicy::keySet($config)) return $no('key_not_set', ['openai key: not set']);
                if (MediaPolicy::sttModel($config) === '') return $no('model_not_allowed', ['ai_media_openai_stt_model: not in the allow-list']);
                return $ok(['ai_media_voice_privacy_line: ok', 'openai key: set', 'ai_media_openai_stt_model: ' . MediaPolicy::sttModel($config)]);
            case 'voice-staff':
            case 'voice-reply':
                return $ok(['no own precondition']);
        }
        return $no('unknown_step', []);
    }

    // ── checks ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{status:string, counts:array<string,int>, notes:list<string>} */
    public static function check(\PDO $ro, array $config, string $dataDir, string $id, int $now): array
    {
        if (!isset(self::CHECKS[$id])) return self::res('FAIL unknown_check', [], []);
        $def = self::CHECKS[$id];
        $since = self::sinceOf($config[$def['since']] ?? null);
        if ($since === null) return self::res('FAIL since_unreadable', [], [$def['since'] . ': unset or unreadable']);
        $notes = [$def['since'] . ': ' . UtcClock::stamp($since)];
        if ($now < $since + $def['min_s']) return self::res('NOT_YET too_soon', [], $notes);
        try {
            switch ($id) {
                case 'VA-ON1': return self::vaOn1($ro, $config, $since, $now, $notes);
                case 'VN-ON1': return self::vnOn1($ro, $config, $since, $now, $notes);
                case 'VN-ON2': return self::vnOn2($ro, $config, $dataDir, $since, $now, $notes);
                case 'VN-ON3': return self::vnOn3($ro, $config, $since, $now, $notes);
                case 'VN-ON4': return self::vnOn4($ro, $config, $since, $now, $notes);
            }
        } catch (\Throwable $e) {
            return self::res('FAIL read_error', [], array_merge($notes, ['the check could not read the book (' . get_class($e) . ')']));
        }
        return self::res('FAIL unknown_check', [], $notes);
    }

    /** VA-ON1 (step 2): every acknowledgement once decided, by the limits it applied, its alert, its ledger pair, its echo. */
    private static function vaOn1(\PDO $ro, array $config, int $since, int $now, array $notes): array
    {
        $c = [];
        $fail = [];
        $sinceS = UtcClock::stamp($since);
        $bound = max($since, $now - self::EVENT_WINDOW_S);
        $aiOff = !self::flag($config['ai_enabled'] ?? null);

        $notesIn = (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE direction = 'in' AND media_type = 'audio' AND created_at >= ?", [$sinceS]);
        $c['voice_notes'] = $notesIn;
        $decs = self::all($ro, 'SELECT * FROM wa_voice_acks WHERE created_at >= ? ORDER BY id', [$sinceS]);
        if ($notesIn === 0 && $decs === []) return self::res('NOT_YET no_traffic', $c, $notes);
        $byWa = [];
        foreach ($decs as $d) {
            $byWa[(string)$d['wa_message_id']] = $d;
            $k = (string)$d['outcome'] . ($d['reason'] !== null && $d['reason'] !== '' ? ':' . $d['reason'] : '');
            self::inc($c, 'decision ' . $k);
            if ($d['variant'] !== null) self::inc($c, 'variant ' . $d['variant']);
            self::inc($c, 'channel ' . self::token((string)$d['channel']));
        }

        // Coverage: every decided event has its decision; an undecided one is a count, never FAIL.
        foreach (self::all($ro, "SELECT id, payload, status, attempts, max_attempts FROM events
                                  WHERE event_type = 'ai.voice_ack' AND created_at >= ?", [$sinceS]) as $e) {
            $p = json_decode((string)$e['payload'], true);
            $wa = is_array($p) ? trim((string)($p['wa_message_id'] ?? '')) : '';
            $decided = $e['status'] === 'done' || $e['status'] === 'dead'
                    || ($e['status'] === 'failed' && (int)$e['attempts'] >= (int)$e['max_attempts']);
            if (!$decided) { self::inc($c, $aiOff ? 'undecided ai_enabled_off' : 'undecided ai_enabled_on'); continue; }
            if ($wa === '' || !isset($byWa[$wa])) { self::inc($c, 'coverage_missing'); $fail[] = 'coverage'; }
        }

        // The ledger rows, by ref.
        $led = [];
        foreach (self::all($ro, "SELECT * FROM wa_auto_sends WHERE kind = 'voice_ack' ORDER BY id") as $l) $led[(string)$l['ref']] = $l;

        $claimAt = function (array $l): int { return UtcClock::parse((string)$l['claimed_at']); };
        foreach ($decs as $d) {
            $ref = 'ack:' . $d['wa_message_id'];
            $l = $led[$ref] ?? null;
            $out = (string)$d['outcome'];
            $reason = (string)($d['reason'] ?? '');
            $lo = $l === null ? 'none' : ($l['outcome'] === null ? 'in_flight' : (string)$l['outcome']);
            $swept = $l !== null && $l['outcome'] === 'uncertain' && in_array((string)$l['detail'], self::SWEPT, true);
            $ownSettled = in_array($lo, ['sent', 'not_sent'], true) || ($lo === 'uncertain' && !$swept);
            $pair = 'fail';
            if ($out === 'sent') {
                if ($lo === 'sent' && trim((string)$l['wa_message_id']) !== '') $pair = 'ok';
                elseif ($lo === 'in_flight') $pair = 'settle_not_landed';
                elseif ($swept) $pair = 'late_settle';
            } elseif ($out === 'uncertain') {
                if ($lo === 'uncertain') $pair = 'ok';
                elseif ($lo === 'in_flight') $pair = 'settle_not_landed';
            } elseif ($out === 'failed' || ($out === 'skipped' && in_array($reason, ['policy_at_send', 'opted_out'], true))) {
                if ($lo === 'not_sent') $pair = 'ok';
                elseif ($lo === 'in_flight') $pair = 'settle_not_landed';
                elseif ($swept) $pair = 'late_settle';
            } elseif ($out === 'claimed') {
                if ($l !== null) {
                    $age = $now - $claimAt($l);
                    if ($lo === 'in_flight') $pair = $age < self::IN_PROGRESS_S ? 'in_progress' : 'crashed';
                    elseif ($swept) $pair = 'crashed';
                    elseif ($ownSettled) $pair = $age < self::IN_PROGRESS_S ? 'in_progress' : 'bookkeeping_failed';
                }
            } elseif ($out === 'skipped') {
                if ($l === null) $pair = 'ok';
                elseif ($lo === 'in_flight' || $swept) $pair = 'claim_before_decision';
            }
            if ($pair === 'fail') { self::inc($c, 'ledger_mismatch'); $fail[] = 'ledger_mismatch'; }
            elseif ($pair !== 'ok') self::inc($c, $pair);
            if ($out === 'skipped' && $reason === 'person_active') self::inc($c, 'person_active');

            if (($out === 'sent' || $out === 'uncertain') && $l !== null) {
                $at = $claimAt($l);
                // Period: no other sent or uncertain decision of the conversation stored in the period it recorded.
                $period = (int)$d['period_hours'];
                $other = (int)self::one($ro, "SELECT COUNT(*) FROM wa_voice_acks WHERE conversation_id = ? AND id <> ?
                                                AND outcome IN ('sent','uncertain') AND created_at > ? AND created_at <= ?",
                                        [(int)$d['conversation_id'], (int)$d['id'], UtcClock::stamp($at - $period * 3600), UtcClock::stamp($at)]);
                if ($other > 0) { self::inc($c, 'limit_period'); $fail[] = 'limit_period'; }
                // Window: its claim's moment inside the hours its ledger row recorded.
                $h = (int)(new \DateTime('@' . $at))->setTimezone(new \DateTimeZone(self::TZ))->format('G');
                if ($l['win_from'] === null || $l['win_to'] === null || $h < (int)$l['win_from'] || $h >= (int)$l['win_to']) {
                    self::inc($c, 'limit_window'); $fail[] = 'limit_window';
                }
                // Ceiling: the handset's earlier voice_ack rows of its day, as the claim's step 5 counts them.
                $before = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND kind = 'voice_ack' AND local_day = ?
                                                 AND id < ? AND (outcome IS NULL OR outcome <> 'not_sent')",
                                         [(string)$l['handset'], (string)$l['local_day'], (int)$l['id']]);
                if ($l['cap_day'] === null || $before >= (int)$l['cap_day']) { self::inc($c, 'limit_ceiling'); $fail[] = 'limit_ceiling'; }
                // Gap: the latest earlier row of any kind on the handset, at least the gap it recorded before it.
                $prev = self::one($ro, "SELECT MAX(CAST(strftime('%s', claimed_at) AS INTEGER)) FROM wa_auto_sends
                                         WHERE handset = ? AND id < ? AND (outcome IS NULL OR outcome <> 'not_sent')",
                                  [(string)$l['handset'], (int)$l['id']]);
                if ($prev !== null && $prev !== false && $at < (int)$prev + (int)$d['gap_s']) { self::inc($c, 'limit_gap'); $fail[] = 'limit_gap'; }
                self::inc($c, 'limits_judged');

                // Alerts, inside the event window.
                if (UtcClock::parse((string)$d['created_at']) >= $bound) {
                    $esc = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'voice_ack'
                                                  AND entity_type = 'conversation' AND entity_id = ? AND created_at >= ? AND created_at <= ?",
                                          [(int)$d['conversation_id'], UtcClock::stamp(UtcClock::parse((string)$d['created_at']) - 1),
                                           UtcClock::stamp(UtcClock::parse((string)$d['updated_at']) + 120)]);
                    if ($esc === 0) { self::inc($c, 'alert_missing'); $fail[] = 'alert_missing'; }
                    else self::inc($c, 'alert ' . (string)$d['local_day']);
                } else {
                    self::inc($c, 'outside_event_window');
                }

                // A person row within 5 min after the claim: a count, split by kind, never FAIL.
                $after = self::all($ro, "SELECT event_key, agent_name FROM wa_messages WHERE conversation_id = ? AND direction = 'out'
                                           AND role = 'agent' AND created_at > ? AND created_at <= ?",
                                   [(int)$d['conversation_id'], UtcClock::stamp($at), UtcClock::stamp($at + 300)]);
                foreach ($after as $r) {
                    if (in_array((string)$r['event_key'], HumanTakeover::personEvents(), true)) { self::inc($c, 'person_after_ack inbox_reply'); break; }
                    if ((string)$r['agent_name'] === 'Team') { self::inc($c, 'person_after_ack team_row'); break; }
                }
                // Persona sends on a day before the date now set: a count (the decision recorded its flag, not the date).
                $pc = trim((string)($config['sf_persona_confirmed'] ?? ''));
                if ((int)$d['persona'] === 1 && $pc !== '' && strcmp((string)$d['local_day'], $pc) < 0) self::inc($c, 'persona_before_date');
            }

            // Echo, by identity: no person-labelled row carries this acknowledgement's own sent id.
            if ($out === 'sent' && $l !== null && trim((string)$l['wa_message_id']) !== '') {
                $echo = (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = ? AND agent_name IN ('Team', 'WhatsApp auto-reply')",
                                       [(string)$l['wa_message_id']]);
                if ($echo > 0) { self::inc($c, 'echo_as_person'); $fail[] = 'echo_as_person'; }
            }
        }

        // An orphan is not a fault until a later claim on its handset should have swept it.
        foreach ($led as $l) {
            if ($l['outcome'] !== null || UtcClock::parse((string)$l['claimed_at']) < $since) continue;
            self::inc($c, 'ledger_in_flight');
            $later = (int)self::one($ro, "SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND mode = 'claim' AND id > ? AND claimed_at > ?",
                                    [(string)$l['handset'], (int)$l['id'], UtcClock::stamp(UtcClock::parse((string)$l['claimed_at']) + self::IN_PROGRESS_S)]);
            if ($later > 0) { self::inc($c, 'ledger_orphan'); $fail[] = 'ledger_orphan'; }
        }

        // Rows: every stored acknowledgement is the assistant's, DishNet AI, with its id.
        foreach (self::all($ro, "SELECT role, agent_name, wa_message_id FROM wa_messages WHERE " . VoiceAck::metaHas('metadata', '"source":"voice_ack"') . " AND created_at >= ?", [$sinceS]) as $r) {
            if ($r['role'] !== 'assistant' || $r['agent_name'] !== 'DishNet AI' || trim((string)$r['wa_message_id']) === '') {
                self::inc($c, 'ack_row_shape'); $fail[] = 'ack_row_shape';
            }
        }
        $c['wa_media_ack_rows'] = (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE direction = 'out' AND role = 'agent'
                                                         AND agent_name = 'DishNet Bot' AND metadata IS NULL AND created_at >= ?", [$sinceS]);
        return self::verdict($fail, $c, $notes);
    }

    /** VN-ON1 (step 5): media can be fetched; R1 itself did nothing to anyone. */
    private static function vnOn1(\PDO $ro, array $config, int $since, int $now, array $notes): array
    {
        $c = [];
        $fail = [];
        $sinceS = UtcClock::stamp($since);
        $rows = self::all($ro, "SELECT * FROM wa_media WHERE kind = 'audio' AND created_at >= ? ORDER BY id", [$sinceS]);
        $c['audio_rows'] = count($rows);
        $notes[] = self::durationNote($ro);
        if (count($rows) < 20) return self::res('NOT_YET no_traffic', $c, $notes);

        $ok = 0; $bad = 0;
        foreach ($rows as $r) {
            if ($r['fetched_bytes'] !== null) { $ok++; continue; }
            if ((int)$r['attempts'] <= 0 || $r['status'] === 'fetching') continue;
            if ($r['status'] === 'failed' && (int)$r['attempts'] < 3) continue;          // a retry still to come
            if (in_array((string)$r['failure_reason'], ['fetch_failed', 'timeout', 'malformed', 'missing_identifier'], true)) $bad++;
        }
        $c['fetch_ok'] = $ok;
        $c['fetch_failed'] = $bad;
        if ($ok + $bad === 0) return self::res('NOT_YET no_traffic', $c, $notes);
        if ($ok * 100 < 90 * ($ok + $bad)) {
            return self::res('FAIL fetch_unavailable', $c, array_merge($notes,
                ['Evolution may not keep media; stop P4 here: off voice-measure, then skip voice-measure --reason <text>']));
        }

        $own = array_values(array_filter($rows, function ($r) { return $r['voice_rung'] === 'fetch_only'; }));
        $c['own_rung_rows'] = count($own);
        $owned = self::escalationOwners($ro, $sinceS);
        foreach ($own as $r) {
            if (self::bodyChanged($ro, $r)) { self::inc($c, 'r1_body_changed'); $fail[] = 'r1_acted'; }
            if (in_array((int)$r['id'], $owned, true)) { self::inc($c, 'r1_handed_over'); $fail[] = 'r1_acted'; }
            if (self::voiceTurns($ro, (int)$r['id']) !== []) { self::inc($c, 'r1_voice_turn'); $fail[] = 'r1_acted'; }
        }
        $calls = (int)self::one($ro, "SELECT COUNT(*) FROM wa_voice_calls WHERE voice_rung = 'fetch_only' AND created_at >= ?", [$sinceS]);
        if ($calls > 0) { $c['r1_calls'] = $calls; $fail[] = 'r1_called'; }
        $stuck = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status = 'processing'
                                        AND (locked_at IS NULL OR locked_at < ?)", [UtcClock::stamp($now - 120)]);
        if ($stuck > 0) { $c['left_processing'] = $stuck; $fail[] = 'left_processing'; }

        // Prints: the mix, the durations, the per-handset volume (F21's bound).
        $secs = [];
        $perDay = [];
        foreach ($rows as $r) {
            self::inc($c, 'mimetype ' . self::token(MediaPolicy::baseMime((string)($r['fetched_mimetype'] ?? $r['mimetype'] ?? ''))));
            if ($r['local_seconds'] !== null) $secs[] = (int)$r['local_seconds'];
            if ((int)$r['ptt'] === 1) self::inc($c, 'ptt');
            if (in_array((string)$r['failure_reason'], ['duration_mismatch', 'invalid_audio'], true)) self::inc($c, 'duration_refusals');
            $day = (new \DateTime('@' . UtcClock::parse((string)$r['created_at'])))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
            $k = strtolower((string)$r['instance']) . '|' . $day;
            $perDay[$k] = ($perDay[$k] ?? 0) + 1;
        }
        foreach (['p50' => 50, 'p95' => 95] as $n => $q) $c['local_seconds ' . $n] = self::pct($secs, $q);
        $c['local_seconds max'] = $secs === [] ? 0 : max($secs);
        $vals = array_values($perDay);
        $c['per_handset_day p50'] = self::pct($vals, 50);
        $c['per_handset_day p95'] = self::pct($vals, 95);
        $c['per_handset_day max'] = $vals === [] ? 0 : max($vals);
        return self::verdict($fail, $c, $notes);
    }

    /** VN-ON2 (step 7): OpenAI accepts the audio, within each call's own cap; the dry run said nothing to anyone. */
    private static function vnOn2(\PDO $ro, array $config, string $dataDir, int $since, int $now, array $notes): array
    {
        $c = [];
        $fail = [];
        $sinceS = UtcClock::stamp($since);
        $calls = self::all($ro, 'SELECT * FROM wa_voice_calls WHERE created_at >= ? ORDER BY id', [$sinceS]);
        $c['calls'] = count($calls);
        if (count($calls) < 10) return self::res('NOT_YET no_traffic', $c, $notes);

        $opus = (int)self::one($ro, "SELECT COUNT(*) FROM wa_voice_calls v JOIN wa_media m ON m.id = v.media_id
                                       WHERE v.created_at >= ? AND v.outcome = 'ok'
                                         AND lower(COALESCE(m.fetched_mimetype, m.mimetype, '')) LIKE 'audio/ogg%'", [$sinceS]);
        $c['ogg_ok'] = $opus;
        if ($opus === 0) {
            return self::res('FAIL opus_rejected', $c, array_merge($notes, ['OpenAI rejects WhatsApp Opus on this model; do not go on']));
        }
        $settled = 0; $ok = 0;
        foreach ($calls as $v) {
            if ($v['outcome'] === 'started') { self::inc($c, 'started'); continue; }
            $settled++;
            if ($v['outcome'] === 'ok') $ok++;
            else self::inc($c, 'reason ' . self::token((string)$v['reason']));
            if (in_array((string)$v['reason'], ['provider_auth', 'provider_model', 'provider_quota'], true)) $fail[] = (string)$v['reason'];
            // Each call within the daily cap its own reservation applied (ruling R18).
            $used = 0;
            foreach (self::all($ro, 'SELECT seconds_reserved, seconds_billed, settled_at FROM wa_voice_calls WHERE local_day = ? AND id < ?',
                               [(string)$v['local_day'], (int)$v['id']]) as $e) {
                $billedThen = $e['seconds_billed'] !== null && $e['settled_at'] !== null
                           && UtcClock::parse((string)$e['settled_at']) <= UtcClock::parse((string)$v['created_at']);
                $used += $billedThen ? (int)$e['seconds_billed'] : (int)$e['seconds_reserved'];
            }
            if ($used + (int)$v['seconds_reserved'] > (int)$v['cap_day_s']) { self::inc($c, 'over_own_cap'); $fail[] = 'over_cap'; }
        }
        $c['ok'] = $ok;
        if ($settled > 0 && $ok * 100 < 80 * $settled) $fail[] = 'ok_rate';

        $owned = self::escalationOwners($ro, $sinceS);
        foreach (self::all($ro, "SELECT * FROM wa_media WHERE kind = 'audio' AND voice_rung = 'dry_run' AND created_at >= ?", [$sinceS]) as $r) {
            self::inc($c, 'own_rung_rows');
            $acted = $r['understanding'] !== null || self::bodyChanged($ro, $r) || self::voiceTurns($ro, (int)$r['id']) !== []
                  || in_array((int)$r['id'], $owned, true);
            if ($acted) { self::inc($c, 'r2_acted'); $fail[] = 'r2_acted'; }
        }
        // Key (VN-K1): the stored key, read in memory and never printed, in none of the plugin's log files.
        $key = trim((string)($config['openai_api_key'] ?? ''));
        if ($key === '') {
            $notes[] = 'openai key: not set';
        } else {
            foreach (self::logFiles($dataDir) as $f) {
                $s = @file_get_contents($f);
                if (is_string($s) && strpos($s, $key) !== false) { self::inc($c, 'key_in_log'); $fail[] = 'key_in_log'; }
            }
        }
        // Key (VN-K2): the installed tab's Uganda branch prints an empty value for the stored key (a text check of the file).
        $tab = @file_get_contents(dirname(__DIR__) . '/tabs/engage/whatsapp.php');
        if (!is_string($tab) || !preg_match('/name="openai_api_key"[^>]*value="<\?= \$_aiKeyUg \? \'\' :/', $tab)) {
            self::inc($c, 'key_in_tab'); $fail[] = 'key_in_tab';
        }
        $c['voice_keyword_optouts'] = self::hasTable($ro, 'contact_optouts')
            ? (int)self::one($ro, "SELECT COUNT(*) FROM contact_optouts WHERE source = 'voice_keyword' AND created_at >= ?", [$sinceS]) : 0;
        return self::verdict($fail, $c, $notes);
    }

    /** VN-ON3 (step 8): every voice note handed to a person, once decided; bodies untouched; R-19's one message. */
    private static function vnOn3(\PDO $ro, array $config, int $since, int $now, array $notes): array
    {
        $c = [];
        $fail = [];
        $sinceS = UtcClock::stamp($since);
        $bound = max($since, $now - self::EVENT_WINDOW_S);
        $aiOff = !self::flag($config['ai_enabled'] ?? null);
        $rows = self::all($ro, "SELECT * FROM wa_media WHERE kind = 'audio' AND voice_rung = 'handover' AND created_at >= ? ORDER BY id", [$sinceS]);
        $c['own_rung_rows'] = count($rows);
        $owned = self::escalationOwners($ro, $sinceS);
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;

        foreach ($rows as $r) {
            // Body and AI, over every handover row.
            if (self::bodyChanged($ro, $r) || self::voiceTurns($ro, (int)$r['id']) !== []) { self::inc($c, 'r3_acted'); $fail[] = 'r3_acted'; }
            // Hand-overs, inside the event window, once decided.
            if (UtcClock::parse((string)$r['created_at']) < $bound) { self::inc($c, 'outside_event_window'); continue; }
            if ($r['status'] === 'skipped' && $r['failure_reason'] === 'media_disabled') { self::inc($c, 'media_disabled'); continue; }
            if ($r['status'] === 'dead' && $r['failure_reason'] === 'switched_off') { self::inc($c, 'dead_switched_off'); continue; }
            if (!self::mediaDecided($ro, (int)$r['id'])) { self::inc($c, $aiOff ? 'undecided ai_enabled_off' : 'undecided ai_enabled_on'); continue; }
            if (!in_array((int)$r['id'], $owned, true) && !self::ackEscalationNear($ro, $r)) { self::inc($c, 'not_handed_over'); $fail[] = 'not_handed_over'; }
        }

        // Holding lines (R-19), over the handover rows inside the event window.
        foreach (self::holdingLines($ro, $sinceS) as $line) {
            $lineT = UtcClock::parse((string)$line['created_at']);
            $ev = self::one($ro, "SELECT id || '|' || created_by || '|' || created_at FROM events WHERE event_type = 'wa.escalation'
                                   AND entity_type = 'conversation' AND entity_id = ? AND created_by IN ('media_worker', 'ai_reply_worker')
                                   AND created_at <= ? ORDER BY id DESC LIMIT 1", [(int)$line['conversation_id'], (string)$line['created_at']]);
            if (!is_string($ev) || $ev === '') continue;
            [$evId, $evBy, $evAt] = explode('|', $ev, 3);
            if ($evBy !== 'media_worker') continue;
            $owner = self::ownerOf($ro, (int)$evId);
            if ($owner === null || !isset($byId[$owner])) continue;
            if (UtcClock::parse((string)$byId[$owner]['created_at']) < $bound) continue;
            $ai = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'ai_reply_worker'
                                        AND entity_type = 'conversation' AND entity_id = ? AND created_at >= ? AND created_at <= ?",
                                 [(int)$line['conversation_id'], UtcClock::stamp($lineT - self::LINE_WINDOW_S), UtcClock::stamp($lineT)]);
            if ($ai > 0) { self::inc($c, 'ambiguous_line ai_reply_worker'); continue; }
            $against = [$owner];
            if (UtcClock::parse($evAt) === $lineT) {
                foreach (self::all($ro, "SELECT id FROM events WHERE event_type = 'wa.escalation' AND created_by = 'media_worker'
                                          AND entity_type = 'conversation' AND entity_id = ? AND id <> ? AND created_at >= ? AND created_at <= ?",
                                   [(int)$line['conversation_id'], (int)$evId, UtcClock::stamp($lineT - self::LINE_WINDOW_S), UtcClock::stamp($lineT)]) as $o) {
                    $o2 = self::ownerOf($ro, (int)$o['id']);
                    if ($o2 !== null && isset($byId[$o2]) && !in_array($o2, $against, true)) { $against[] = $o2; break; }
                }
            }
            $failed = 0;
            foreach ($against as $mid) {
                if (self::lineAfterKnownAck($ro, $byId[$mid], $lineT, $c)) $failed++;
            }
            if ($failed === count($against)) { self::inc($c, 'holding_line_after_ack'); $fail[] = 'holding_line_after_ack'; }
            elseif ($failed > 0) self::inc($c, 'ambiguous_line second_media_worker');
            else self::inc($c, 'holding_lines_judged');
        }
        foreach (self::all($ro, "SELECT local_day, COUNT(*) n FROM (SELECT date(created_at, '+3 hours') local_day FROM events
                                  WHERE event_type = 'wa.escalation' AND created_by = 'media_worker' AND created_at >= ?) GROUP BY local_day", [$sinceS]) as $r) {
            $c['alerts ' . $r['local_day']] = (int)$r['n'];
        }
        $notes[] = 'staff judge transcript quality (Luganda and Swahili above all); moving on is the owner\'s decision (--owner-approved on step 9)';
        return self::verdict($fail, $c, $notes);
    }

    /** VN-ON4 (step 9): each understood note on a listed number is one turn, judged as the reply worker could know it. */
    private static function vnOn4(\PDO $ro, array $config, int $since, int $now, array $notes): array
    {
        $c = [];
        $fail = [];
        $sinceS = UtcClock::stamp($since);
        $bound = max($since, $now - self::EVENT_WINDOW_S);
        $margin = $since + self::SWITCH_MARGIN_S;
        $aiOff = !self::flag($config['ai_enabled'] ?? null);
        $listed = function (string $ch) use ($config): bool {
            $v = strtolower(trim((string)($config['ai_media_voice_reply_channels'] ?? '')));
            $list = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
            return $list === ['all'] || ($ch !== '' && in_array(strtolower(trim($ch)), $list, true));
        };
        $owned = self::escalationOwners($ro, $sinceS);
        foreach (self::all($ro, "SELECT * FROM wa_media WHERE kind = 'audio' AND voice_rung = 'reply' AND created_at >= ? ORDER BY id", [$sinceS]) as $r) {
            $made = UtcClock::parse((string)$r['created_at']);
            if ($made < $margin) { self::inc($c, 'inside_switch_margin'); continue; }
            $turns = self::voiceTurns($ro, (int)$r['id']);
            if (!$listed((string)$r['channel'])) {
                // Scope: no voice turn answered on an unlisted number.
                foreach ($turns as $t) if (self::turnAnswered($ro, $t)) { self::inc($c, 'scope_answered_unlisted'); $fail[] = 'scope'; }
                continue;
            }
            if ($made < $bound) { self::inc($c, 'outside_event_window'); continue; }
            if ($r['status'] === 'understood') {
                if (count($turns) !== 1) { self::inc($c, 'turns_mismatch'); $fail[] = 'turns_mismatch'; continue; }
                $t = $turns[0];
                if ($t['status'] !== 'done' && $t['status'] !== 'dead') { self::inc($c, $aiOff ? 'undecided ai_enabled_off' : 'undecided ai_enabled_on'); continue; }
                if ($t['status'] === 'dead') {
                    $told = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'ai_reply_worker'
                                                   AND entity_type = 'conversation' AND entity_id = ? AND created_at >= ?",
                                           [(int)$r['conversation_id'], (string)$t['processed_at']]);
                    if ($told > 0) self::inc($c, 'dead_handed_over'); else { self::inc($c, 'turn_dead_untold'); $fail[] = 'turn_dead'; }
                    continue;
                }
                if (self::turnAnswered($ro, $t)) { self::inc($c, 'answered'); continue; }
                self::inc($c, self::unansweredCause($ro, $t, $r));
                continue;
            }
            if ($turns !== []) { self::inc($c, 'turns_mismatch'); $fail[] = 'turns_mismatch'; continue; }
            // A failure, handed over once its own event is decided.
            if ($r['status'] === 'dead' && $r['failure_reason'] === 'switched_off') { self::inc($c, 'dead_switched_off'); continue; }
            if (!in_array((string)$r['status'], ['failed', 'dead', 'unsupported'], true)) continue;
            if (!self::mediaDecided($ro, (int)$r['id'])) { self::inc($c, $aiOff ? 'scope undecided ai_enabled_off' : 'scope undecided ai_enabled_on'); continue; }
            if (!in_array((int)$r['id'], $owned, true)) { self::inc($c, 'failure_not_handed_over'); $fail[] = 'failure_not_handed_over'; }
        }
        // P5 on those numbers: no acknowledgement for a note stored past the switch margin.
        foreach (self::all($ro, "SELECT a.channel, m.created_at AS note_at FROM wa_voice_acks a JOIN wa_messages m ON m.wa_message_id = a.wa_message_id
                                  WHERE a.outcome IN ('sent','uncertain') AND a.created_at >= ?", [$sinceS]) as $a) {
            if (!$listed((string)$a['channel'])) continue;
            if (UtcClock::parse((string)$a['note_at']) < $margin) { self::inc($c, 'inside_switch_margin'); continue; }
            self::inc($c, 'ack_on_reply_number'); $fail[] = 'ack_on_reply_number';
        }
        $c['r13_handovers'] = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'ai_reply_worker'
                                                     AND payload LIKE '%that is off now%' AND created_at >= ?", [$sinceS]);
        $c['out_of_order_handovers'] = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'ai_reply_worker'
                                                     AND payload LIKE '%already been answered%' AND created_at >= ?", [$sinceS]);
        $c['f20_text_deferrals'] = (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply' AND error LIKE 'voice: an earlier voice note%' AND created_at >= ?", [$sinceS]);
        return self::verdict($fail, $c, $notes);
    }

    // ── off-hooks ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Rows 2 ('ack') and 5 ('voice-measure'), on the tool's $rw, after the step's keys clear (ruling R7). Returns counts;
     * throws on failure (the tool prints FAIL off_hook <step>, the keys stay cleared, the sequence goes on).
     *
     * @return array<string,int>
     */
    public static function offHook(\PDO $rw, array $config, string $dataDir, string $step, string $why): array
    {
        if ($step === 'ack') {
            // Wait, bounded, while the reply worker records each pending acknowledgement switched_off on its own fresh read
            // (step 13); then count what remains (VN-RB2, VN-RB4).
            $c = ['ack_events_at_start' => self::ackEvents($rw)];
            $c['waited_s'] = self::drain(function () use ($rw): int { return self::ackEvents($rw); });
            $c['ack_events_left'] = self::ackEvents($rw);
            $c['ack_events_left_processing'] = (int)self::one($rw, "SELECT COUNT(*) FROM events WHERE event_type = 'ai.voice_ack' AND status = 'processing'");
            return $c;
        }
        if ($step === 'voice-measure') {
            if (!self::hasTable($rw, 'wa_media')) return ['settled' => 0, 'voice_turns_left' => 0, 'waited_s' => 0];
            $st = $rw->prepare("UPDATE wa_media SET status = 'skipped', failure_reason = 'switched_off', updated_at = datetime('now')
                                 WHERE kind = 'audio' AND status = 'pending'");
            $st->execute();
            $c = ['settled' => $st->rowCount()];
            // Drain the voice turns (ruling R14): the reply worker hands each to a person on its own fresh read (R-13).
            $c['waited_s'] = self::drain(function () use ($rw): int { return self::voiceTurnsPending($rw); });
            $c['voice_turns_left'] = self::voiceTurnsPending($rw);
            $c['ai_media_events_pending'] = (int)self::one($rw, "SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status IN ('pending','failed','processing')");
            if ($why === 'rollback') {
                $left = (int)self::one($rw, "SELECT COUNT(*) FROM wa_media WHERE kind = 'audio' AND status = 'pending'");
                if ($left > 0) throw new \RuntimeException('voice-measure off-hook: pending audio rows remain');
            }
            return $c;
        }
        throw new \RuntimeException('no Part V off-hook for this step');
    }

    /** Poll $left() until it reads 0 or the bound passes; the seconds waited. */
    private static function drain(callable $left): int
    {
        $t0 = time();
        while ($left() > 0 && time() - $t0 < self::$drainBoundS) usleep(max(10000, (int)self::$drainPollUs));
        return time() - $t0;
    }

    private static function ackEvents(\PDO $pdo): int
    {
        return (int)self::one($pdo, "SELECT COUNT(*) FROM events WHERE event_type = 'ai.voice_ack' AND status IN ('pending', 'processing')");
    }

    private static function voiceTurnsPending(\PDO $pdo): int
    {
        return (int)self::one($pdo, "SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply' AND status IN ('pending', 'processing')
                                       AND payload LIKE '%\"origin\":\"voice\"%'");
    }

    // ── shared reads ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Which row each media_worker hand-over is (§H.3.20): its event names its conversation and no row, so it is the row the
     * worker settled last on that conversation at or before the event's created_at; on a tie in the second, a handover or
     * reply row. Returns the media ids that own at least one such event.
     *
     * @return int[]
     */
    private static function escalationOwners(\PDO $ro, string $sinceS): array
    {
        $out = [];
        foreach (self::all($ro, "SELECT id FROM events WHERE event_type = 'wa.escalation' AND created_by = 'media_worker'
                                  AND entity_type = 'conversation' AND created_at >= ?", [$sinceS]) as $e) {
            $o = self::ownerOf($ro, (int)$e['id']);
            if ($o !== null) $out[$o] = $o;
        }
        return array_values($out);
    }

    private static function ownerOf(\PDO $ro, int $eventId): ?int
    {
        $v = self::one($ro, "SELECT m.id FROM events e JOIN wa_media m ON m.conversation_id = e.entity_id
                              WHERE e.id = ? AND m.updated_at <= e.created_at
                              ORDER BY m.updated_at DESC, (m.voice_rung IN ('handover', 'reply')) DESC, m.id DESC LIMIT 1", [$eventId]);
        return ($v === null || $v === false) ? null : (int)$v;
    }

    /** The note's own body is no longer [AUDIO]. */
    private static function bodyChanged(\PDO $ro, array $r): bool
    {
        $b = self::one($ro, 'SELECT body FROM wa_messages WHERE wa_message_id = ?', [(string)$r['wa_message_id']]);
        return is_string($b) && $b !== '[AUDIO]';
    }

    /** The row's own voice turns: ai.reply events whose payload names it (voice.media_id). */
    private static function voiceTurns(\PDO $ro, int $mediaId): array
    {
        $out = [];
        foreach (self::all($ro, "SELECT * FROM events WHERE event_type = 'ai.reply' AND payload LIKE '%\"origin\":\"voice\"%'") as $e) {
            $p = json_decode((string)$e['payload'], true);
            if (is_array($p) && (int)($p['voice']['media_id'] ?? 0) === $mediaId) $out[] = $e;
        }
        return $out;
    }

    /** The row's own ai.media event is done or dead (or gone: a row whose event is missing is judged). */
    private static function mediaDecided(\PDO $ro, int $mediaId): bool
    {
        $evs = self::all($ro, "SELECT status, attempts, max_attempts FROM events WHERE event_type = 'ai.media' AND entity_type = 'media' AND entity_id = ?", [$mediaId]);
        if ($evs === []) return true;
        foreach ($evs as $e) {
            $decided = $e['status'] === 'done' || $e['status'] === 'dead';
            if (!$decided) return false;
        }
        return true;
    }

    /** A voice_ack hand-over on the row's conversation within 30 min of the row. */
    private static function ackEscalationNear(\PDO $ro, array $r): bool
    {
        $t = UtcClock::parse((string)$r['created_at']);
        return (int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'voice_ack'
                                      AND entity_type = 'conversation' AND entity_id = ? AND created_at >= ? AND created_at <= ?",
                              [(int)$r['conversation_id'], UtcClock::stamp($t - 1800), UtcClock::stamp($t + 1800)]) > 0;
    }

    private static function holdingLines(\PDO $ro, string $sinceS): array
    {
        return self::all($ro, "SELECT id, conversation_id, created_at FROM wa_messages WHERE direction = 'out' AND role = 'assistant'
                                 AND " . VoiceAck::metaHas('metadata', '"handover":true') . " AND created_at >= ? ORDER BY id", [$sinceS]);
    }

    /**
     * R-19 for one line given to one row: an acknowledgement of the conversation stored in an earlier second than the row's
     * updated_at (the worker could read it), within 10 min before the line. One stored later is a count.
     */
    private static function lineAfterKnownAck(\PDO $ro, array $row, int $lineT, array &$c): bool
    {
        $upd = UtcClock::parse((string)$row['updated_at']);
        foreach (self::all($ro, "SELECT created_at FROM wa_voice_acks WHERE conversation_id = ? AND outcome IN ('claimed','sent','uncertain')
                                  AND created_at <= ? AND created_at >= ?",
                           [(int)$row['conversation_id'], UtcClock::stamp($lineT), UtcClock::stamp($lineT - self::ACK_LINE_S)]) as $a) {
            if (UtcClock::parse((string)$a['created_at']) < $upd) return true;
            self::inc($c, 'ack_after_row_decided');
        }
        return false;
    }

    /** A DishNet AI assistant row of the turn's conversation, not a hand-over line or an acknowledgement, after its event. */
    private static function turnAnswered(\PDO $ro, array $t): bool
    {
        $until = $t['processed_at'] !== null && $t['processed_at'] !== '' ? (string)$t['processed_at'] : '9999-12-31 23:59:59';
        return (int)self::one($ro, "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND role = 'assistant'
                                      AND agent_name = 'DishNet AI' AND created_at >= ? AND created_at <= ?
                                      AND NOT " . VoiceAck::metaHas("COALESCE(metadata, '')", '"handover":true') . "
                                      AND NOT " . VoiceAck::metaHas("COALESCE(metadata, '')", '"source":"voice_ack"') . "",
                              [(int)$t['entity_id'], (string)$t['created_at'], $until]) > 0;
    }

    /** Why a done turn was not answered, by the first recorded cause (§H.3.20 VN-ON4); never FAIL. */
    private static function unansweredCause(\PDO $ro, array $t, array $r): string
    {
        $conv = (int)$t['entity_id'];
        $until = $t['processed_at'] !== null && $t['processed_at'] !== '' ? (string)$t['processed_at'] : '9999-12-31 23:59:59';
        if ((int)self::one($ro, "SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'ai_reply_worker'
                                   AND entity_type = 'conversation' AND entity_id = ? AND created_at >= ? AND created_at <= ?",
                           [$conv, (string)$t['created_at'], $until]) > 0) {
            return 'handed_over';
        }
        $labels = "'', 'DishNet AI', 'DishNet Plugin', 'WhatsApp auto-reply'";
        foreach (self::all($ro, "SELECT event_key, agent_name FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND role = 'agent'
                                  AND COALESCE(agent_name, '') NOT IN ({$labels}) AND created_at > ? AND created_at <= ? ORDER BY id",
                           [$conv, (string)$r['created_at'], $until]) as $m) {
            if (in_array((string)$m['event_key'], HumanTakeover::personEvents(), true)) return 'meanwhile_row inbox_reply';
            if ((string)$m['agent_name'] === 'Team') return 'meanwhile_row team_row';
            return 'meanwhile_row other';
        }
        if (strpos((string)($t['error'] ?? ''), 'parked:') === 0) return 'parked_dropped';
        return 'no_recorded_cause';
    }

    /** ai_media's last duration from the store's master_schedule row (a note, never judged; §H.3.20). */
    private static function durationNote(\PDO $ro): string
    {
        if (!self::hasTable($ro, 'master_schedule')) return 'ai_media last run: no master_schedule row';
        $raw = self::one($ro, 'SELECT data FROM master_schedule WHERE id = 0');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['ai_media']) || !is_array($d['ai_media'])) return 'ai_media last run: no ai_media entry';
        return 'ai_media last run: duration_ms ' . (int)($d['ai_media']['duration_ms'] ?? -1);
    }

    /** The plugin's log files a key could have landed in (VN-K1): the data folder's and the code folder's. */
    private static function logFiles(string $dataDir): array
    {
        $out = [];
        foreach ([$dataDir, dirname(__DIR__) . '/data'] as $dir) {
            if ($dir === '' || !is_dir($dir)) continue;
            foreach (glob(rtrim($dir, '/') . '/*.log') ?: [] as $f) $out[] = $f;
            foreach (glob(rtrim($dir, '/') . '/*log*.json') ?: [] as $f) $out[] = $f;
        }
        return array_values(array_unique($out));
    }

    private static function schemaRow(\PDO $ro, string $migration): bool
    {
        if (!self::hasTable($ro, '_schema_checks')) return false;
        return (int)self::one($ro, 'SELECT COUNT(*) FROM _schema_checks WHERE migration = ? AND found = expected', [$migration]) > 0;
    }

    // ── small helpers ──────────────────────────────────────────────────────────────────────────────────────────

    private static function verdict(array $fail, array $c, array $notes): array
    {
        return self::res($fail === [] ? 'PASS' : 'FAIL ' . $fail[0], $c, $notes);
    }

    private static function res(string $status, array $counts, array $notes): array
    {
        ksort($counts);
        return ['status' => $status, 'counts' => $counts, 'notes' => array_values($notes)];
    }

    private static function inc(array &$c, string $k, int $n = 1): void
    {
        $c[$k] = ($c[$k] ?? 0) + $n;
    }

    /** A since-stamp as the other parts read theirs: a unix time in digits, or a UTC stamp (UtcClock); null otherwise. */
    private static function sinceOf($v): ?int
    {
        if (is_bool($v) || !is_scalar($v)) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        $t = ctype_digit($s) ? (int)$s : UtcClock::parse($s);
        return $t > 0 ? $t : null;
    }

    private static function pct(array $vals, int $q): int
    {
        if ($vals === []) return 0;
        sort($vals);
        return (int)$vals[(int)min(count($vals) - 1, (int)ceil($q / 100 * count($vals)) - 1)];
    }

    private static function token(string $s): string
    {
        $t = (string)preg_replace('/[^A-Za-z0-9_\/.-]/', '', $s);
        return $t === '' ? 'none' : substr($t, 0, 40);
    }

    private static function hasTable(\PDO $pdo, string $name): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return $st->fetchColumn() !== false;
    }

    private static function all(\PDO $pdo, string $sql, array $args = []): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return mixed the first column of the first row, or null */
    private static function one(\PDO $pdo, string $sql, array $args = [])
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    private static function flag($v): bool
    {
        if ($v === null || $v === '') return false;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }
}
