<?php
declare(strict_types=1);
/**
 * The weakened copies of tests/test_wa_send_budget.php (5.18.104, root docs/73 §H.2.14: WB1-WB43 for LG, HK1-HK17 for
 * HK). Each is a copy of the plugin with one anchored edit (two where a guard has two layers, said so), judged by the
 * named section run on the copy, alone or with the cases named. Appended to $M by the runner. TEST ONLY.
 *
 * Not here, and why: WB25's trigger half and the schema's own copies are tests/quote_followup/mutants_A.php's. L-ON's
 * copies (5.18.104 (root docs/73 §H.2.14), stage B9) are at the end: HK11, HK12 and HK14 judged by HK's L-ON halves, and
 * HK15's HK form by HK03; HK15's, HK16's and HK17's CK forms and CK15 are tests/sales_followup_tool/mutants_CK.php's.
 */
$SC = 'lib/SalesFollowupChecks.php';   // 5.18.104 (root docs/73 §H.2.14): L-ON's copies
$W = 'lib/WaSendBudget.php';

// The tail every sendVia anchor ends with: what follows the hook there, and nowhere else.
$NSV_TAIL = <<<'TXT'
                } else {   // Q17-F01: WASender carried it; no handset, so a content-free count for L-ON
                    WaSendBudget::countWasender($this->store, $this->evoConfig, (string)$event);
                }
            } catch (\Throwable $e) { /* the send matters more than its record */ }
        }

        // Track result for retry mode
        $this->_lastSendSuccess = $success;
        $this->_lastHttpCode    = $httpCode;
        $this->_lastError       = $curlErr ?: ($success ? null : mb_substr((string)$response, 0, 500));

        $this->writeLog([
            'sender'  => $sender,
TXT;
$NSV_CALL = <<<'TXT'
                if ($evoRes !== null) {
                    WaSendBudget::recordNotify($this->store, $this->evoConfig, $this->evoChannelFor($sender),
                        (string)$event, (string)$to, (bool)$success, (string)($evoRes['error'] ?? ''), (string)$response);

TXT;
$HOOK = <<<'TXT'
        // 5.18.104 (root docs/73 §H.2.6.1, C3): an automated send from a handset goes into the one ledger — never deferred,
        // never refused; a record that cannot be written never stops a send. Uganda with the ledger on; nothing at all otherwise.
        if ((string)($this->evoConfig['wa_send_ledger'] ?? '') === 'on') {
            try {
                require_once __DIR__ . '/WaSendBudget.php';

TXT;
$DOC_TAIL = <<<'TXT'
        // Track result for retry mode
        $this->_lastSendSuccess = $success;
        $this->_lastHttpCode    = $httpCode;
        $this->_lastError       = $curlErr ?: ($success ? null : mb_substr((string)$response, 0, 500));

        $this->writeLog([
            'sender'   => $sender,
            'event'    => $event ?: 'document_send',
TXT;
$HOOK_BODY = $HOOK . $NSV_CALL . substr($NSV_TAIL, 0, strpos($NSV_TAIL, "\n\n") + 2);

$M = array_merge($M, [
    // ── LG ──────────────────────────────────────────────────────────────────────────────────────────────────────
    ['label' => 'WB1 the claim without BEGIN IMMEDIATE (two senders send twice)', 'file' => $W,
     'old' => "\$this->pdo->exec('BEGIN IMMEDIATE'); \$this->inTx = true;", 'new' => '$this->inTx = false;',
     'sections' => 'LG', 'cases' => 'LG34', 'pattern' => '/LG34 /'],
    ['label' => 'WB2 a ledger error read as ok', 'file' => $W,
     'old' => "            \$this->inTx = false;\n            return self::no('ledger_error', \$now + 300, false);",
     'new' => "            \$this->inTx = false;\n            return ['ok' => true, 'claim' => 0];",
     'sections' => 'LG', 'cases' => 'LG22', 'pattern' => '/LG22 /'],
    ['label' => 'WB3 (kind, ref) idempotency removed', 'file' => $W,
     'old' => 'FROM wa_auto_sends WHERE kind = ? AND ref = ? ORDER BY try', 'new' => 'FROM wa_auto_sends WHERE 0 AND kind = ? AND ref = ? ORDER BY try',
     'sections' => 'LG', 'cases' => 'LG01', 'pattern' => '/LG02 /'],
    ['label' => 'WB4 a stale in-flight claim re-sent', 'file' => $W,
     'old' => <<<'TXT'
                    $pdo->prepare("UPDATE wa_auto_sends SET outcome = 'uncertain', settled_at = ?, detail = 'stale'
                                    WHERE id = ? AND outcome IS NULL")->execute([UtcClock::stamp($now), (int)$r['id']]);
                    $this->txCommit(); $tx = false;
                    return self::no('already_sent', null, true);
TXT
     , 'new' => '                    $notSent++; continue;', 'sections' => 'LG', 'cases' => 'LG03', 'pattern' => '/LG04 /'],
    ['label' => 'WB5 a 4th try allowed', 'file' => $W, 'old' => 'if ($try > 3) return', 'new' => 'if ($try > 4) return',
     'sections' => 'LG', 'cases' => 'LG03', 'pattern' => '/LG05 /'],
    ['label' => 'WB6 not_sent counted in the caps', 'file' => $W,
     'old' => "AND local_day = ?\n                                       AND (outcome IS NULL OR outcome <> 'not_sent')\");\n                \$st->execute([\$h, \$day]);\n                \$used",
     'new' => "AND local_day = ?\");\n                \$st->execute([\$h, \$day]);\n                \$used",
     'sections' => 'LG', 'cases' => 'LG06', 'pattern' => '/LG06 /'],
    ['label' => 'WB7 the day on UTC', 'file' => $W, 'old' => "return self::local(\$t)->format('Y-m-d');", 'new' => "return gmdate('Y-m-d', \$t);",
     'sections' => 'LG', 'cases' => 'LG07', 'pattern' => '/LG07 /'],
    ['label' => 'WB8 the handset keyed on the channel id (support and account counted apart)', 'file' => $W,
     'old' => "\$b->record((string)(\$config['evo_instance_' . \$channel] ?? ''), \$kind,", 'new' => '$b->record($channel, $kind,',
     'sections' => 'LG', 'cases' => 'LG08', 'pattern' => '/LG08 /'],
    ['label' => 'WB9 no hourly cap', 'file' => $W, 'old' => 'if ((int)$hr[0] >= $capHour) return', 'new' => 'if (false) return',
     'sections' => 'LG', 'cases' => 'LG09', 'pattern' => '/LG09 /'],
    ['label' => 'WB10 spacing over the same kind only (a chase straight after an acknowledgement)', 'file' => $W,
     'old' => "+ gap_s) FROM wa_auto_sends\n                                      WHERE handset = ? AND (outcome IS NULL OR outcome <> 'not_sent')\");\n                \$st->execute([\$h]);",
     'new' => "+ gap_s) FROM wa_auto_sends\n                                      WHERE handset = ? AND kind = ? AND (outcome IS NULL OR outcome <> 'not_sent')\");\n                \$st->execute([\$h, \$kind]);",
     'sections' => 'LG', 'cases' => 'LG09', 'pattern' => '/LG10 /'],
    ['label' => 'WB11 spacing ignoring record rows', 'file' => $W,
     'old' => "+ gap_s) FROM wa_auto_sends\n                                      WHERE handset = ? AND (outcome IS NULL",
     'new' => "+ gap_s) FROM wa_auto_sends\n                                      WHERE handset = ? AND mode = 'claim' AND (outcome IS NULL",
     'sections' => 'LG', 'cases' => 'LG09', 'pattern' => '/LG11 /'],
    ['label' => 'WB12 voice_ack refused by the proactive day cap', 'file' => $W,
     'old' => "                if ((int)\$st->fetchColumn() >= \$capDay) return \$this->end(self::no('ack_ceiling', null, true));",
     'new' => "                if ((int)\$st->fetchColumn() >= \$capDay) return \$this->end(self::no('ack_ceiling', null, true));\n"
            . "                \$st2 = \$pdo->prepare(\"SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND local_day = ?\"); \$st2->execute([\$h, \$day]);\n"
            . "                if ((int)\$st2->fetchColumn() >= self::setting(\$this->config, 'wa_send_daily_cap')) return \$this->end(self::no('day_cap', \$now + 60, false));",
     'sections' => 'LG', 'cases' => 'LG12', 'pattern' => '/LG12 /'],
    ['label' => 'WB13 no acknowledgement ceiling', 'file' => $W,
     'old' => "if ((int)\$st->fetchColumn() >= \$capDay) return \$this->end(self::no('ack_ceiling'", 'new' => "if (false) return \$this->end(self::no('ack_ceiling'",
     'sections' => 'LG', 'cases' => 'LG12', 'pattern' => '/LG13 /'],
    ['label' => 'WB14 record-only proactive rows not counted', 'file' => $W,
     'old' => "WHERE handset = ? AND proactive = 1 AND local_day = ?\n                                       AND (outcome IS NULL",
     'new' => "WHERE handset = ? AND proactive = 1 AND mode = 'claim' AND local_day = ?\n                                       AND (outcome IS NULL",
     'sections' => 'LG', 'cases' => 'LG12', 'pattern' => '/LG14 /'],
    ['label' => 'WB15 no per-phone rule', 'file' => $W,
     'old' => "if ((int)\$st->fetchColumn() > 0) return \$this->end(self::no('phone_today'", 'new' => "if (false) return \$this->end(self::no('phone_today'",
     'sections' => 'LG', 'cases' => 'LG15', 'pattern' => '/LG15 /'],
    ['label' => 'WB16 the per-phone rule per handset', 'file' => $W,
     'old' => 'FROM wa_auto_sends WHERE phone = ? AND local_day = ?', 'new' => "FROM wa_auto_sends WHERE phone = ? AND handset = '{\$h}' AND local_day = ?",
     'sections' => 'LG', 'cases' => 'LG15', 'pattern' => '/LG16 /'],
    ['label' => 'WB17 no reservation (offers take the chases\' budget)', 'file' => $W,
     'old' => '$reserve = $this->reserve($kind, $h, $now, $day);', 'new' => '$reserve = 0;',
     'sections' => 'LG', 'cases' => 'LG17', 'pattern' => '/LG17 /'],
    ['label' => 'WB17b the enquiry drafts\' demand left out of the reservation', 'file' => $W,
     'old' => '        if ($p > 3) $sum += $this->demandEnquiry($h);', 'new' => '',
     'sections' => 'LG', 'cases' => 'LG36', 'pattern' => '/LG36 /'],
    ['label' => 'WB17c the reminders\' usual day left out of the offers\' reservation', 'file' => $W,
     'old' => '        if ($p > 4) $sum += $this->demandRecorded($h, $now, $day);', 'new' => '',
     'sections' => 'LG', 'cases' => 'LG36', 'pattern' => '/LG36 /'],
    ['label' => 'WB18 the window in the process clock (dn_tz_apply()\'s zone), not a literal Kampala', 'file' => $W,
     'old' => "return (new \\DateTime('@' . \$t))->setTimezone(new \\DateTimeZone(self::TZ));",
     'new' => "return (new \\DateTime('@' . \$t))->setTimezone(new \\DateTimeZone(date_default_timezone_get()));",
     'sections' => 'LG', 'cases' => 'LG18', 'pattern' => '/LG18 /'],
    ['label' => 'WB19 no zone refusal', 'file' => $W,
     'old' => "if (dn_tz(\$this->config) !== self::TZ) return self::no('zone', null, true);", 'new' => "if (false) return self::no('zone', null, true);",
     'sections' => 'LG', 'cases' => 'LG18', 'pattern' => '/LG19 /'],
    ['label' => 'WB20 Sunday allowed', 'file' => $W, 'old' => "if ((int)\$l->format('N') === 7) return false;", 'new' => 'if (false) return false;',
     'sections' => 'LG', 'cases' => 'LG18', 'pattern' => '/LG20 /'],
    ['label' => 'WB21 the ledger off answers ok', 'file' => $W,
     'old' => "if (!self::on(\$this->config, \$this->dataDir)) return self::no('ledger_off', null, true);", 'new' => "if (false) return self::no('ledger_off', null, true);",
     'sections' => 'LG', 'cases' => 'LG18', 'pattern' => '/LG21 /'],
    ['label' => 'WB22 record() throws into the caller', 'file' => $W,
     'old' => "        } catch (\\Throwable \$e) {\n            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . \$lineKind);\n            return 'error';\n        }",
     'new' => "        } catch (\\Throwable \$e) {\n            throw \$e;\n        }",
     'sections' => 'LG', 'cases' => 'LG22', 'pattern' => '/LG22 /'],
    ['label' => 'WB23 record() writes on South Sudan', 'file' => $W,
     'old' => "if (!StaffJobsGate::applies(\$this->config, \$this->dataDir)) return 'not_uganda';", 'new' => "if (false) return 'not_uganda';",
     'sections' => 'LG', 'cases' => 'LG22', 'pattern' => '/LG23 /'],
    ['label' => 'WB24 the handset not lower-cased or trimmed', 'file' => $W, 'old' => '        $s = strtolower(trim($instance));', 'new' => '        $s = $instance;',
     'sections' => 'LG', 'cases' => 'LG22', 'pattern' => '/LG24 /'],
    ['label' => 'WB25 settle allowed twice (its own guard; the trigger half is A\'s)', 'file' => $W,
     'old' => "WHERE id = ? AND outcome IS NULL');", 'new' => "WHERE id = ?');",
     'sections' => 'LG', 'cases' => 'LG22', 'pattern' => '/LG25 /'],
    ['label' => 'WB26 claim() not normalising its argument (LG26)', 'file' => $W,
     'old' => "        \$h = self::handset(\$handset);\n        if (\$h === '') return self::no('no_instance', null, true);",
     'new' => "        \$h = 'i:' . \$handset;\n        if (\$h === '') return self::no('no_instance', null, true);",
     'sections' => 'LG', 'cases' => 'LG26', 'pattern' => '/LG26 /'],
    ['label' => 'WB27 reminders_first removed (LG27)', 'file' => $W,
     'old' => 'if (!ReminderDay::done($schedule, $this->config, $this->dataDir, $now)) {', 'new' => 'if (false) {',
     'sections' => 'LG', 'cases' => 'LG27', 'pattern' => '/LG27 /'],
    ['label' => 'WB28 the orphan sweep removed (LG28)', 'file' => $W,
     'old' => '->execute([UtcClock::stamp($now), $h, UtcClock::stamp($now - self::IN_FLIGHT_S)]);', 'new' => '->execute([UtcClock::stamp($now), $h, UtcClock::stamp(0)]);',
     'sections' => 'LG', 'cases' => 'LG28', 'pattern' => '/LG28 /'],
    ['label' => 'WB28b the orphan sweep limited to the same ref (LG28)', 'file' => $W,
     'old' => "WHERE handset = ? AND outcome IS NULL AND claimed_at <= ?\")\n                ->execute([UtcClock::stamp(\$now), \$h,",
     'new' => "WHERE handset = ? AND outcome IS NULL AND claimed_at <= ? AND ref = '{\$ref}'\")\n                ->execute([UtcClock::stamp(\$now), \$h,",
     'sections' => 'LG', 'cases' => 'LG28', 'pattern' => '/LG28 /'],
    ['label' => 'WB29 the acknowledgement window fixed in code, ignoring ackPolicy()', 'file' => $W,
     'old' => "                \$pol = self::ackPolicy(\$this->config);\n                if (\$pol === null", 'new' => "                \$pol = VoiceAckLimits::DEFAULTS;\n                if (\$pol === null",
     'sections' => 'LG', 'cases' => 'LG29', 'pattern' => '/LG29 /'],
    ['label' => 'WB30a a window setting allowed to widen D3 (089\'s CHECK widened)', 'file' => 'migrations/089_quote_followups.sql',
     'old' => "OR (kind <> 'voice_ack' AND win_from BETWEEN 8 AND 19 AND win_to BETWEEN win_from + 1 AND 20)",
     'new' => "OR (kind <> 'voice_ack' AND win_from BETWEEN 0 AND 19 AND win_to BETWEEN win_from + 1 AND 23)",
     'sections' => 'LG', 'cases' => 'LG35', 'pattern' => '/LG35 /'],
    ['label' => 'WB30b a window setting allowed to widen D15 (VoiceAckLimits::RANGES widened)', 'file' => 'lib/VoiceAckLimits.php',
     'old' => "'hour_to'      => [8, 21],", 'new' => "'hour_to'      => [8, 23],",
     'sections' => 'LG', 'cases' => 'LG35', 'pattern' => '/LG35 /'],
    ['label' => 'WB31 settle() throwing on a row already settled', 'file' => $W,
     'old' => "if (\$n === 0) PluginLog::write('wa_send_ledger', 'late_settle');", 'new' => "if (\$n === 0) throw new \\RuntimeException('a claim is settled once');",
     'sections' => 'LG', 'cases' => 'LG28', 'pattern' => '/LG28 /'],
    ['label' => 'WB32 a may-have-been-sent result settled sent', 'file' => $W,
     'old' => '            $outcome = self::outcomeOf($result, $o);',
     'new' => "            \$outcome = (\$result === 'failed' && !empty(\$o['maybe_sent'])) ? 'sent' : self::outcomeOf(\$result, \$o);",
     'sections' => 'LG', 'cases' => 'LG29', 'pattern' => '/LG29 /'],
    ['label' => 'WB33 a quote, enquiry or offer claim with no phone answering ok', 'file' => $W,
     'old' => "if (\$phone === null) return self::no('no_phone', null, true);", 'new' => "if (false) return self::no('no_phone', null, true);",
     'sections' => 'LG', 'cases' => 'LG30', 'pattern' => '/LG30 /'],
    ['label' => 'WB34 the phone stored in the caller\'s form', 'file' => $W,
     'old' => "return \$intl === null ? null : ltrim(\$intl, '+');", 'new' => "return \$intl === null ? null : (string)preg_replace('/\\D+/', '', \$raw);",
     'sections' => 'LG', 'cases' => 'LG30', 'pattern' => '/LG30 /'],
    ['label' => 'WB35 ackPolicy() falling back to the defaults on an invalid stored value', 'file' => $W,
     'old' => '        return VoiceAckLimits::of($config);', 'new' => '        return VoiceAckLimits::of($config) ?? VoiceAckLimits::DEFAULTS;',
     'sections' => 'LG', 'cases' => 'LG29', 'pattern' => '/LG29 /'],
    ['label' => 'WB36 the reservation built from a caller\'s $handsetOf outside tests', 'file' => $W,
     'old' => "\$b->handsetOf = (getenv(self::TEST_SEAM_ENV) === '1') ? \$handsetOf : null;", 'new' => '$b->handsetOf = $handsetOf;',
     'sections' => 'LG', 'cases' => 'LG17', 'pattern' => '/LG17 /'],
    ['label' => 'WB37 record() answering recorded when it wrote nothing', 'file' => $W,
     'old' => "if ((string)(\$this->config['wa_send_ledger'] ?? '') !== 'on') return 'ledger_off';", 'new' => "if ((string)(\$this->config['wa_send_ledger'] ?? '') !== 'on') return 'recorded';",
     'sections' => 'LG', 'cases' => 'LG30', 'pattern' => '/LG30 /'],
    ['label' => 'WB38 retry_at returned as a UTC string', 'file' => $W,
     'old' => "'retry_at' => \$final ? null : \$retryAt,", 'new' => "'retry_at' => \$final ? null : (\$retryAt === null ? null : gmdate('c', \$retryAt)),",
     'sections' => 'LG', 'cases' => 'LG30', 'pattern' => '/LG30 /'],
    ['label' => 'WB39 record() storing failed + maybe_sent as not_sent', 'file' => $W,
     'old' => 'UtcClock::stamp($now), self::outcomeOf($result, $o), UtcClock::stamp($now)', 'new' => 'UtcClock::stamp($now), self::outcomeOf($result, []), UtcClock::stamp($now)',
     'sections' => 'LG', 'cases' => 'LG30', 'pattern' => '/LG30 /'],
    ['label' => 'WB40 onConnection()\'s claim() going on to claim', 'file' => $W,
     'old' => "if (\$this->connOnly) return self::no('not_claimable', null, true);", 'new' => "if (false) return self::no('not_claimable', null, true);",
     'sections' => 'LG', 'cases' => 'LG31', 'pattern' => '/LG31 /'],
    ['label' => 'WB41 claim() ignoring in_flight_s', 'file' => $W, 'old' => '|| $now + $inflight > $close) {', 'new' => '|| $now > $close) {',
     'sections' => 'LG', 'cases' => 'LG32', 'pattern' => '/LG32 /'],
    ['label' => 'WB42 claimed_at left to the insert\'s moment instead of the claim\'s', 'file' => $W,
     'old' => '$day, $gap, UtcClock::stamp($now),', 'new' => '$day, $gap, UtcClock::stamp(time()),',
     'sections' => 'LG', 'cases' => 'LG33', 'pattern' => '/LG33 /'],
    ['label' => 'WB43 the four limits taken from the defaults instead of the settings the claim read', 'file' => $W,
     'old' => '                               $from, $to, $capDay, $capHour]);',
     'new' => "                               self::DEFAULTS['wa_send_hour_from'], self::DEFAULTS['wa_send_hour_to'], \$capDay, \$capHour]);",
     'sections' => 'LG', 'cases' => 'LG33', 'pattern' => '/LG33 /'],

    // ── HK ──────────────────────────────────────────────────────────────────────────────────────────────────────
    ['label' => 'HK1 ops_low_balance mapped to other', 'file' => $W, 'old' => "        'ops_low_balance'        => 'reminder',\n", 'new' => '',
     'sections' => 'HK', 'cases' => 'HK01', 'pattern' => '/HK01 /'],
    ['label' => 'HK2 the hook reading the send before its result (a failure recorded as sent)', 'file' => 'lib/NotificationService.php',
     'old' => $NSV_CALL . $NSV_TAIL,
     'new' => str_replace('(bool)$success', 'true', $NSV_CALL) . $NSV_TAIL,
     'sections' => 'HK', 'cases' => 'HK02', 'pattern' => '/HK02 /'],
    ['label' => 'HK3 the WASender path recorded', 'file' => 'lib/NotificationService.php',
     'old' => $NSV_CALL . $NSV_TAIL,
     'new' => str_replace('if ($evoRes !== null) {', 'if (true) {', $NSV_CALL) . $NSV_TAIL,
     'sections' => 'HK', 'cases' => 'HK03', 'pattern' => '/HK03 /'],
    ['label' => 'HK4 quote_list: recorded by AlertService', 'file' => 'lib/AlertService.php',
     'old' => "=== 'on' && strpos(\$key, 'quote_list:') !== 0) {", 'new' => "=== 'on') {",
     'sections' => 'HK', 'cases' => 'HK04', 'pattern' => '/HK04 /'],
    ['label' => 'HK5 AlertService\'s hook failing on a fake with no instanceFor', 'file' => $W,
     'old' => "\$inst = (is_object(\$evo) && method_exists(\$evo, 'instanceFor')) ? (string)\$evo->instanceFor('sales') : '';",
     'new' => "\$inst = (string)\$evo->instanceFor('sales');",
     'sections' => 'HK', 'cases' => 'HK04', 'pattern' => '/HK04 /'],
    ['label' => 'HK6 enquiry claimed before the chase is on (the ledger\'s predicate)', 'file' => $W,
     'old' => "    public static function enquiryClaims(array \$config): bool\n    {\n",
     'new' => "    public static function enquiryClaims(array \$config): bool\n    {\n        return true;\n",
     'sections' => 'HK', 'cases' => 'HK06', 'pattern' => '/HK06 /'],
    ['label' => 'HK7 a send site added to the tree and left unclassified', 'file' => 'lib/AlertService.php',
     'old' => '    /** The number alerts go to. Empty string means alerts are disabled. */',
     'new' => "    public function hk7Probe(\$evo): void { \$evo->sendText('sales', '0', 'x'); }\n\n    /** The number alerts go to. Empty string means alerts are disabled. */",
     'sections' => 'HK', 'cases' => 'HK07', 'pattern' => '/HK07 /'],
    ['label' => 'HK8 the sendDocument hook removed (an invoice PDF missing from the ledger)', 'file' => 'lib/NotificationService.php',
     'old' => $HOOK_BODY . $DOC_TAIL, 'new' => $DOC_TAIL,
     'sections' => 'HK', 'cases' => 'HK02', 'pattern' => '/HK02 /'],
    ['label' => 'HK9 a person\'s event (wa_staff_reply) recorded', 'file' => $W,
     'old' => "string \$error, string \$raw): void\n    {\n        if (!class_exists('HumanTakeover')) require_once __DIR__ . '/HumanTakeover.php';\n        if (in_array(\$event, HumanTakeover::personEvents(), true)) return;\n",
     'new' => "string \$error, string \$raw): void\n    {\n        if (!class_exists('HumanTakeover')) require_once __DIR__ . '/HumanTakeover.php';\n",
     'sections' => 'HK', 'cases' => 'HK03', 'pattern' => '/HK03 /'],
    ['label' => 'HK10 the Starlink hook throwing into the sender (two layers: the hunk\'s catch and recordDirect\'s)',
     'edits' => [
        ['workers/StarlinkMailWorker.php',
         "                try { require_once dirname(__DIR__) . '/lib/WaSendBudget.php';",
         "                { require_once dirname(__DIR__) . '/lib/WaSendBudget.php';"],
        ['workers/StarlinkMailWorker.php',
         "                catch (\\Throwable \$e) { /* the order message matters more than its record */ }\n", ''],
        [$W, "        } catch (\\Throwable \$e) {\n            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . \$lineKind);\n        }\n    }\n\n    /** One more on",
             "        } catch (\\Throwable \$e) {\n            throw \$e;\n        }\n    }\n\n    /** One more on"],
     ],
     'sections' => 'HK', 'cases' => 'HK08', 'pattern' => '/HK08 /'],
    ['label' => 'HK10b recordDirect letting a throwable out (the hunk swallows it with no hook_write_lost line)', 'file' => $W,
     'old' => "        } catch (\\Throwable \$e) {\n            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . \$lineKind);\n        }\n    }\n\n    /** One more on",
     'new' => "        } catch (\\Throwable \$e) {\n            throw \$e;\n        }\n    }\n\n    /** One more on",
     'sections' => 'HK', 'cases' => 'HK08', 'pattern' => '/HK08 /'],
    ['label' => 'HK13 recordDirect() built without the data directory (the tenant read as South Sudan)', 'file' => 'workers/StarlinkMailWorker.php',
     'old' => 'WaSendBudget::recordDirect($this->pdo, $this->config, $dd, $this->evo,', 'new' => 'WaSendBudget::recordDirect($this->pdo, $this->config, null, $this->evo,',
     'sections' => 'HK', 'cases' => 'HK08', 'pattern' => '/HK08 /'],
    ['label' => 'HK16 the alert hook counting nothing when the sales instance is blank', 'file' => $W,
     'old' => "                if (is_array(\$r) && array_key_exists('http', \$r)) self::bump(\$store->getPdo(), 'noinstance:' . self::localDay(time()) . ':' . \$kind);\n",
     'new' => '',
     'sections' => 'HK', 'cases' => 'HK05', 'pattern' => '/HK05 /'],
    ['label' => 'HK17 a lost write leaving one hook_write_lost line per process', 'file' => $W,
     'old' => "        } catch (\\Throwable \$e) {\n            PluginLog::write('wa_send_ledger', 'hook_write_lost ' . \$lineKind);\n            return 'error';\n        }",
     'new' => "        } catch (\\Throwable \$e) {\n            static \$once = false;\n            if (!\$once) { \$once = true; PluginLog::write('wa_send_ledger', 'hook_write_lost ' . \$lineKind); }\n            return 'error';\n        }",
     'sections' => 'HK', 'cases' => 'HK10', 'pattern' => '/HK10 /'],
    // 5.18.104 (root docs/73 §H.2.14; A11-04, Q15-F03, Q16-F02, Q17-F01, Q19-F02): L-ON's copies, built with it in stage B9.
    ['label' => 'HK11 L-ON comparing the rows written before any send (the opt-out and the no-attempt skips both removed)', 'file' => $SC,
     'old' => "                if (substr(\$ev, -18) === '_suppressed_optout') continue;                   // written before any send\n"
            . "                if ((int)\$r['success'] === 0 && \$r['http_code'] === null) continue;        // written before any send\n",
     'new' => '',
     'sections' => 'HK', 'cases' => 'HK11', 'pattern' => '/HK11 HK12 L-ON PASS/'],
    ['label' => 'HK11b L-ON comparing a row with success 0 and no HTTP code (the unusable number, the PDF switched off)', 'file' => $SC,
     'old' => "                if ((int)\$r['success'] === 0 && \$r['http_code'] === null) continue;        // written before any send\n",
     'new' => '',
     'sections' => 'HK', 'cases' => 'HK11', 'pattern' => '/HK11 HK12 L-ON PASS/'],
    ['label' => 'HK12 L-ON mapping ops_alert_* rows through NOTIFY_KINDS', 'file' => $SC,
     'old' => "        if (strpos(\$event, 'ops_alert_') === 0) {", 'new' => "        if (false) {",
     'sections' => 'HK', 'cases' => 'HK11', 'pattern' => '/HK1[12] /'],
    ['label' => 'HK14 L-ON judging the rows of the switch\'s own Kampala day and the hour past it', 'file' => $SC,
     'old' => "        \$start = max(self::nextMidnight(\$since), \$since + self::MARGIN_S);", 'new' => "        \$start = \$since;",
     'sections' => 'HK', 'cases' => 'HK14', 'pattern' => '/HK14 on ack/'],
    ['label' => 'HK14b L-ON judging the rows of the switch\'s own Kampala day after its first hour (the midnight bound dropped)', 'file' => $SC,
     'old' => "        \$start = max(self::nextMidnight(\$since), \$since + self::MARGIN_S);", 'new' => "        \$start = \$since + self::MARGIN_S;",
     'sections' => 'HK', 'cases' => 'HK14', 'pattern' => '/HK14 on ack at 10:00/'],
    ['label' => 'HK14c L-ON judging the hour past a late-evening switch (the hour bound dropped)', 'file' => $SC,
     'old' => "        \$start = max(self::nextMidnight(\$since), \$since + self::MARGIN_S);", 'new' => "        \$start = self::nextMidnight(\$since);",
     'sections' => 'HK', 'cases' => 'HK14', 'pattern' => '/HK14 on ack at 23:50/'],
    ['label' => 'HK15 (HK form) the hook leaving a send WASender carried uncounted', 'file' => $W,
     'old' => "            self::bump(\$store->getPdo(), 'wasender:' . self::localDay(time()) . ':' . \$kind);", 'new' => '',
     'sections' => 'HK', 'cases' => 'HK03', 'pattern' => '/HK03 /'],
]);
