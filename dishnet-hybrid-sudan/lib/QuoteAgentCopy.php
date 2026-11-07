<?php
declare(strict_types=1);
/**
 * QuoteAgentCopy — 5.18.104 Part P (P7, decision D16; root docs/73 §H.5): the agent who made a quote gets a visible
 * copy (Cc) of the quotation e-mail the PLUGIN sends — in the same message, never a second one.
 *
 * Uganda only, behind its own switch (quote_agent_cc_enabled, OFF until `tools/sales_followup.php on agent-cc`). Every
 * caller asks StaffJobsGate before it loads this file, so South Sudan never loads it; state() asks again (the second
 * lock). Every caller wraps every call in its own try/catch and falls back to 5.18.103's values, and this class still
 * catches inside: a P7 fault can never stop, change or double a quotation e-mail (P-D8).
 *
 *   the gate        state(), on(): the switch, the administrators' setting and automatic quoting, on the read the
 *                   send takes there (PluginConfig::read; ruling R19). False on any doubt.
 *   plugin mail     mailSetting(), preconditions(): email_settings.json alone, never MailService (which would ask uCRM).
 *   the rules       usableAddress(), toOf(), lineFits(), decide(), mergeCc(), skipped(), rcptOutcome(), afterSend(),
 *                   kycInFlight(), notesMark(), webhookAgent(), accountCounts(): pure, no I/O.
 *   resolution      forActor() (the app path), forWebhook() (the webhook path): reads only; null while P7 is off.
 *   records         markMaking(), markResult() (the maker hand-off, §H.5.5) and record() (one decision per composed
 *                   e-mail): ids, quote numbers, fixed codes and times only — never an address, a name or a phone.
 *   the check       check(): AC-ON, read-only over the tool's read-only connection (ruling R13), judging each decision
 *                   only by what its own row recorded (ruling R18).
 *
 * "The agent" of a KYC quote is Part F's owner rule 1, QuoteSendEvidence::kycAgent() (IP-1): one function, so the
 * daily list and the copy mean the same account. PHP 7.4.
 */
final class QuoteAgentCopy
{
    public const KEY    = 'quote_agent_cc_enabled';
    public const SINCE  = 'quote_agent_cc_since';
    public const ADMINS = 'quote_agent_cc_admins';

    /** KycService::QUOTE_CLAIM_SECONDS (lib/KycService.php:2043); quote_agent_copy A6 asserts the two equal. */
    public const IN_FLIGHT_SECONDS = 900;
    /** RFC 5322's line limit: extra headers go out raw and unfolded (lib/MailService.php:630-633). */
    public const CC_LINE_MAX = 998;

    public const MAKERS    = 'quote_agent_makers.json';
    public const DECISIONS = 'quote_agent_copies.json';

    public const CODES = ['added', 'already_copied', 'no_agent', 'agent_ambiguous', 'agent_unknown_id', 'agent_inactive',
        'admin_account', 'agent_no_email', 'agent_email_invalid', 'customer_unread', 'same_as_customer', 'header_too_long',
        'not_composed', 'error'];
    public const FLOOR_CODES = ['agent_email_invalid', 'same_as_customer', 'already_copied', 'header_too_long', 'error'];
    public const SOURCES = ['actor', 'first_decision', 'maker', 'maker_in_flight', 'kyc', 'kyc_in_flight', 'in_flight', 'none'];
    public const RULES   = ['', 'kyc_sales_person', 'kyc_submitter'];
    public const RCPTS   = ['accepted', 'refused', 'none', 'excluded'];
    public const PATHS   = ['plugin', 'webhook'];

    /** AC-ON's margins around the window (§H.5.10.4 rule 3). */
    private const MARGIN = 300;

    // ── the gate ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{on:bool, admins:bool, auto_quote:bool} */
    public static function state(string $pluginRoot, string $dataDir): array
    {
        $off = ['on' => false, 'admins' => false, 'auto_quote' => false];
        try {
            if (!class_exists('PluginConfig'))  require_once __DIR__ . '/PluginConfig.php';
            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            $cfg = PluginConfig::read($pluginRoot, $dataDir);          // the three files, merged; writes nothing
            if (!StaffJobsGate::applies($cfg, $dataDir) || (string)($cfg[self::KEY] ?? '') !== '1') return $off;
            return [
                'on'         => true,
                // D16: an administrator who makes a quote is the agent. Only a stored value other than 1 narrows it (PQ1).
                'admins'     => !array_key_exists(self::ADMINS, $cfg) || (string)$cfg[self::ADMINS] === '1',
                'auto_quote' => ($cfg['kyc_auto_quote_enabled'] ?? true) !== false,   // lib/KycCrmSync.php:312, lib/KycService.php:1050
            ];
        } catch (\Throwable $e) {
            return $off;
        }
    }

    public static function on(string $pluginRoot, string $dataDir): bool
    {
        return self::state($pluginRoot, $dataDir)['on'];
    }

    // ── plugin mail, from the file alone ─────────────────────────────────────────────────────────────────────────

    /**
     * email_settings.json as MailService reads it (lib/MailService.php:139-176), and nothing else — never uCRM.
     * use_ucrm_email on → 'ucrm_not_asked' (MailService would ask uCRM first, falling back to plugin SMTP); else
     * smtp_host set → 'plugin', the sender smtp_from, else smtp_user, else 'not read'; neither → 'none'; a file that exists
     * but cannot be read → 'unreadable'. The sender serves only accountCounts()'s information line (PQ4).
     *
     * @return array{configured:bool, via:string, sender:string}
     */
    public static function mailSetting(string $dataDir): array
    {
        try {
            $f = rtrim($dataDir, '/') . '/email_settings.json';
            $ec = [];
            if (is_file($f)) {
                $raw = is_readable($f) ? @file_get_contents($f) : false;
                if ($raw === false) return ['configured' => false, 'via' => 'unreadable', 'sender' => 'not read'];
                $d = json_decode((string)$raw, true);
                $ec = is_array($d) ? $d : [];                                   // MailService: json_decode(...) ?: []
            }
            if (!empty($ec['use_ucrm_email'])) return ['configured' => true, 'via' => 'ucrm_not_asked', 'sender' => 'not read'];
            if (trim((string)($ec['smtp_host'] ?? '')) !== '') {
                $s = trim((string)($ec['smtp_from'] ?? ''));
                if ($s === '') $s = trim((string)($ec['smtp_user'] ?? ''));
                return ['configured' => true, 'via' => 'plugin', 'sender' => $s !== '' ? $s : 'not read'];
            }
            return ['configured' => false, 'via' => 'none', 'sender' => 'not read'];
        } catch (\Throwable $e) {
            return ['configured' => false, 'via' => 'unreadable', 'sender' => 'not read'];
        }
    }

    /**
     * Row 1b's own precondition, in ruling R13's shape — the one place mailSetting() is mapped (A11-07). The code is
     * `on agent-cc`'s refusal only; AC-ON prints the same one line as a note, never a status (ruling R18).
     *
     * @return array{ok:bool, code:?string, lines:list<string>}
     */
    public static function preconditions(string $dataDir): array
    {
        try {
            $m = self::mailSetting($dataDir);
        } catch (\Throwable $e) {
            $m = ['configured' => false, 'via' => 'unreadable', 'sender' => 'not read'];
        }
        $line = [
            'plugin'         => 'plugin mail: plugin SMTP',
            'ucrm_not_asked' => "plugin mail: uCRM's mailer, not asked",
            'none'           => 'plugin mail is not configured (Settings → System → Email)',
            'unreadable'     => 'plugin mail is not configured (email_settings.json cannot be read)',
        ][$m['via']] ?? 'plugin mail is not configured (email_settings.json cannot be read)';
        $ok = $m['configured'] === true;
        return ['ok' => $ok, 'code' => $ok ? null : 'mail_not_configured', 'lines' => [$line]];
    }

    // ── the rules (pure) ─────────────────────────────────────────────────────────────────────────────────────────

    public static function usableAddress(string $raw): string
    {
        $a = strtolower(trim($raw));
        if ($a === '' || strlen($a) > 254) return '';
        if (preg_match('/[\x00-\x20\x7F,;<>"()\[\]\\\\]/', $a)) return '';   // no CR, LF, space, list, quoted or display form
        if (substr_count($a, '@') !== 1) return '';
        return filter_var($a, FILTER_VALIDATE_EMAIL) !== false ? $a : '';
    }

    /** The To both paths choose: the billing contact's e-mail, else the first contact's (QuotationService :1356-1366, dispatcher :463-475). */
    public static function toOf(array $client): string
    {
        $contacts = is_array($client['contacts'] ?? null) ? $client['contacts'] : [];
        foreach ($contacts as $c) if (!empty($c['isBilling']) && !empty($c['email'])) return (string)$c['email'];
        foreach ($contacts as $c) if (!empty($c['email'])) return (string)$c['email'];
        return '';
    }

    /** Whether "Cc: " and the list, joined as both paths join it, stays within RFC 5322's 998 octets. */
    public static function lineFits(array $cc): bool
    {
        return strlen('Cc: ' . implode(', ', array_map('strval', $cc))) <= self::CC_LINE_MAX;
    }

    /** @return array{address:string, code:string, source:string, rule:string, agent_id:?int} */
    public static function decide(?array $row, ?int $agentId, string $source, string $rule, string $to, array $alreadyCc,
                                  bool $admins): array
    {
        $skip = function (string $code) use ($agentId, $source, $rule): array {
            return ['address' => '', 'code' => $code, 'source' => $source, 'rule' => $rule, 'agent_id' => $agentId];
        };
        if (!class_exists('StaffDirectory')) require_once __DIR__ . '/StaffDirectory.php';
        if ($row === null) return $skip('agent_unknown_id');
        if (!StaffDirectory::isActive($row)) return $skip('agent_inactive');
        if (StaffDirectory::isAdmin($row) && !$admins) return $skip('admin_account');
        if (StaffDirectory::email($row) === '') return $skip('agent_no_email');
        $addr = self::usableAddress(StaffDirectory::email($row));
        if ($addr === '') return $skip('agent_email_invalid');
        $toLc = strtolower(trim($to));
        if ($toLc === '') return $skip('customer_unread');
        if ($addr === $toLc) return $skip('same_as_customer');
        foreach ($alreadyCc as $c) if ($addr === strtolower(trim((string)$c))) return $skip('already_copied');
        if (!self::lineFits(array_merge(array_values($alreadyCc), [$addr]))) return $skip('header_too_long');
        return ['address' => $addr, 'code' => 'added', 'source' => $source, 'rule' => $rule, 'agent_id' => $agentId];
    }

    /**
     * @return array{0:array<int,string>,1:string} the client's own copies exactly as given, plus the agent once — code '' —
     *  or the list unchanged and the FLOOR_CODES code it left the agent out for. Never the To.
     */
    public static function mergeCc(array $cc, string $agent, string $to): array
    {
        if (!class_exists('MailService')) require_once __DIR__ . '/MailService.php';
        $own = array_values($cc);
        $a = self::usableAddress($agent);
        if ($a === '') return [$own, 'agent_email_invalid'];
        if ($a === strtolower(trim(MailService::bareAddress($to)))) return [$own, 'same_as_customer'];
        foreach ($own as $x) if ($a === strtolower(trim(MailService::bareAddress((string)$x)))) return [$own, 'already_copied'];
        $out = array_merge($own, [$a]);
        return self::lineFits($out) ? [$out, ''] : [$own, 'header_too_long'];
    }

    /** A decision turned into a skip with the given code (the floor's, or the app path's own mergeCc). */
    public static function skipped(array $p7, string $code): array
    {
        $p7['address'] = '';
        $p7['code'] = in_array($code, self::CODES, true) ? $code : 'error';
        return $p7;
    }

    /** Whether the relay took the agent's RCPT, from MailService's own log steps (lib/MailService.php:481-483); never the address out. */
    public static function rcptOutcome(array $log, string $address): string
    {
        if ($address === '') return 'none';
        foreach ($log as $s) {
            if (!is_array($s) || ($s['step'] ?? '') !== 'rcpt_cc') continue;
            $m = (string)($s['msg'] ?? '');
            if ($m === 'cc ' . $address) return 'accepted';
            if (strpos($m, 'cc ' . $address . ' refused:') === 0) return 'refused';
        }
        return 'none';
    }

    /** The webhook's decision as the dispatcher carried it out. @return array{0:array,1:string} [decision, rcpt] */
    public static function afterSend(array $p7, array $r): array
    {
        if ((string)($p7['address'] ?? '') === '') return [$p7, 'none'];
        $rc = (string)($r['agent_rcpt'] ?? '');
        if ($rc === '') return [self::skipped($p7, 'not_composed'), 'none'];           // returned before composing
        if ($rc === 'excluded') {                                                    // the floor dropped it
            $c = (string)($r['agent_code'] ?? '');
            return [self::skipped($p7, in_array($c, self::FLOOR_CODES, true) ? $c : 'error'), 'excluded'];
        }
        return [$p7, in_array($rc, ['accepted', 'refused', 'none'], true) ? $rc : 'none'];
    }

    /** Positive evidence that the plugin is making a quote for this KYC application now (§H.5.4.3 step 4, §H.5.5). */
    public static function kycInFlight(array $a, int $now, bool $autoQuote): bool
    {
        $qid = strtoupper(trim((string)($a['quote_id'] ?? '')));
        if (!in_array($qid, ['', '0', 'PENDING'], true)) return false;
        if (!empty($a['quote_created'])) return false;
        // Make the quotation's claim: stamped after any refusal (KycService :2263-2264), possibly ahead of time(); posts while
        // an older quote_error is still set (cleared after the POST, :2213). It outweighs both, on either side of now.
        $c = $a['quote_make_claim_ts'] ?? null;
        if (is_numeric($c) && abs($now - (int)$c) < self::IN_FLIGHT_SECONDS) return true;
        // The form and the uCRM retry: only with the marks of a quote about to be made.
        if (!$autoQuote) return false;                                              // KycService :2450, KycCrmSync :366
        if (!empty($a['quote_refused']) || !empty($a['quote_error'])) return false;
        if (!empty($a['quote_debug']) || !empty($a['crm_linked_existing'])) return false;   // KycService :1262,:1269,:2464; KycCrmSync :477
        if (trim((string)($a['quote_ref'] ?? '')) === '') return false;             // 'PENDING' from save means "to be quoted" (:2780-2781)
        foreach (['submitted_at', 'crm_synced_at'] as $k) {                         // both written by date() on this server
            $t = is_string($a[$k] ?? null) ? strtotime((string)$a[$k]) : false;
            if ($t !== false && $now - (int)$t >= 0 && $now - (int)$t < self::IN_FLIGHT_SECONDS) return true;
        }
        return false;
    }

    /** Where the entity carries adminNotes (null = not carried): whether they hold the plugin's mark for this candidate. */
    public static function notesMark(?string $adminNotes, string $kind, int $id): bool
    {
        if ($adminNotes === null) return true;
        if ($kind === 'maker') return strpos($adminNotes, 'Plugin ref:') !== false;                     // QuotationService :754, :762
        return $id > 0 && (bool)preg_match('/(?:application|App) #' . $id . '(?!\d)/', $adminNotes);   // KycService :419, :1877, :2177
    }

    /** @return array{0:?int,1:string,2:string,3:string} [agent id, source, rule, code — '' when an agent was found] */
    public static function webhookAgent(?array $first, array $makersByQuote, array $appsByQuote, array $makersOfClient,
                                        array $appsOfClient, array $retailers, int $now, bool $autoQuote, ?string $adminNotes): array
    {
        if (!class_exists('QuoteSendEvidence')) require_once __DIR__ . '/QuoteSendEvidence.php';
        $found = function (?int $id, string $source, string $rule): array {
            return [$id, $source, $rule, ($id !== null && $id > 0) ? '' : 'no_agent'];
        };
        if ($first !== null) {                                                       // 1. the quote's first decision (P-D16)
            $id = (int)($first['agent_id'] ?? 0);
            if ($id > 0) return [$id, 'first_decision', in_array($first['rule'] ?? '', self::RULES, true) ? (string)$first['rule'] : '', ''];
            return [null, 'first_decision', '', (($first['code'] ?? '') === 'agent_ambiguous') ? 'agent_ambiguous' : 'no_agent'];
        }
        $made = array_values(array_filter($makersByQuote, function ($m) { return is_array($m) && ($m['state'] ?? '') === 'made'; }));
        if (count($made) > 1) return [null, 'maker', '', 'agent_ambiguous'];
        if (count($made) === 1) return $found((int)($made[0]['maker_id'] ?? 0) ?: null, 'maker', '');
        $apps = array_values(array_filter($appsByQuote, function ($a) {
            return is_array($a) && strtoupper(trim((string)($a['quote_id'] ?? ''))) !== 'PENDING';
        }));
        if (count($apps) > 1) return [null, 'kyc', '', 'agent_ambiguous'];
        if (count($apps) === 1) {
            $k = QuoteSendEvidence::kycAgent($apps[0], $retailers);                      // Part F's rule 1 (IP-1)
            return $found($k['id'], 'kyc', $k['id'] !== null ? (string)$k['source'] : '');
        }
        $mf = array_values(array_filter($makersOfClient, function ($m) use ($now, $adminNotes) {
            return is_array($m) && ($m['state'] ?? '') === 'making' && is_numeric($m['at'] ?? null)
                && $now - (int)$m['at'] >= 0 && $now - (int)$m['at'] < self::IN_FLIGHT_SECONDS
                && self::notesMark($adminNotes, 'maker', 0);
        }));
        $af = array_values(array_filter($appsOfClient, function ($a) use ($now, $autoQuote, $adminNotes) {
            return is_array($a) && self::kycInFlight($a, $now, $autoQuote) && self::notesMark($adminNotes, 'kyc', (int)($a['id'] ?? 0));
        }));
        if (count($mf) + count($af) > 1) return [null, 'in_flight', '', 'agent_ambiguous'];
        if (count($mf) === 1) return $found((int)($mf[0]['maker_id'] ?? 0) ?: null, 'maker_in_flight', '');
        if (count($af) === 1) {
            $k = QuoteSendEvidence::kycAgent($af[0], $retailers);
            return $found($k['id'], 'kyc_in_flight', $k['id'] !== null ? (string)$k['source'] : '');
        }
        return [null, 'none', '', 'no_agent'];
    }

    /**
     * Counts only, for `on`, `show` and AC-N1: active accounts with a usable address, active accounts without one,
     * active administrators (copied unless `set agent-cc-admins 0`), and active accounts whose usable address equals the
     * configured Reply-To or the sender read from the file (information for PQ4). $company: ['reply_to' => …, 'sender' => …].
     *
     * @return array{active_usable:int, active_unusable:int, administrators:int, company_mailbox:int}
     */
    public static function accountCounts(array $retailers, array $company): array
    {
        if (!class_exists('StaffDirectory')) require_once __DIR__ . '/StaffDirectory.php';
        $mine = [];
        foreach (['reply_to', 'sender'] as $k) {
            $a = self::usableAddress((string)($company[$k] ?? ''));
            if ($a !== '') $mine[$a] = true;
        }
        $out = ['active_usable' => 0, 'active_unusable' => 0, 'administrators' => 0, 'company_mailbox' => 0];
        foreach ($retailers as $r) {
            if (!is_array($r) || !StaffDirectory::isActive($r)) continue;
            $a = self::usableAddress(StaffDirectory::email($r));
            if ($a === '') $out['active_unusable']++;
            else $out['active_usable']++;
            if (StaffDirectory::isAdmin($r)) $out['administrators']++;
            if ($a !== '' && isset($mine[$a])) $out['company_mailbox']++;
        }
        return $out;
    }

    // ── resolution (reads only; null while P7 is off; never throws) ───────────────────────────────────────────────

    /**
     * The app path (§H.5.4.1): the account the quote was made as, re-read by id — never the session's copy.
     *
     * @return array{address:string, code:string, source:string, rule:string, agent_id:?int, decided_at:string}|null
     */
    public static function forActor($store, string $pluginRoot, string $dataDir, array $retailer, string $to, array $alreadyCc): ?array
    {
        $state = self::state($pluginRoot, $dataDir);
        if (!$state['on']) return null;
        $at = gmdate('Y-m-d H:i:s');                                                // the moment P7 decides (ruling R18)
        $id = 0;
        try {
            $id = (int)($retailer['id'] ?? 0);
            if ($id <= 0) return ['address' => '', 'code' => 'no_agent', 'source' => 'actor', 'rule' => '', 'agent_id' => null, 'decided_at' => $at];
            $row = $store->findOne('retailers.json', 'id', $id);
            $d = self::decide(is_array($row) ? $row : null, $id, 'actor', '', $to, $alreadyCc, $state['admins']);
            $d['decided_at'] = $at;
            return $d;
        } catch (\Throwable $e) {
            return ['address' => '', 'code' => 'error', 'source' => 'actor', 'rule' => '', 'agent_id' => $id > 0 ? $id : null, 'decided_at' => $at];
        }
    }

    /**
     * The webhook path (§H.5.4.3): the quote's first decision, a maker record, a KYC application holding the quote id,
     * exactly one quote positively in flight for this client, else none. It builds no MailService and reads no sender.
     *
     * @return array{address:string, code:string, source:string, rule:string, agent_id:?int, decided_at:string}|null
     */
    public static function forWebhook($store, string $pluginRoot, string $dataDir, array $config, int $quoteId, int $clientId,
                                      array $client, array $quote): ?array
    {
        $state = self::state($pluginRoot, $dataDir);
        if (!$state['on']) return null;
        $at = gmdate('Y-m-d H:i:s');                                                // the moment P7 decides (ruling R18)
        try {
            $first = $store->findAll(self::DECISIONS, 'quote_id', $quoteId)[0] ?? null;
            $makersByQuote  = $store->findAll(self::MAKERS, 'quote_id', $quoteId);
            $appsByQuote    = $store->findAll('kyc_applications.json', 'quote_id', $quoteId);
            $makersOfClient = $clientId > 0 ? $store->findAll(self::MAKERS, 'client_id', $clientId) : [];
            $appsOfClient   = $clientId > 0 ? $store->findAll('kyc_applications.json', 'crm_client_id', $clientId) : [];
            $retailers      = $store->load('retailers.json');
            [$id, $source, $rule, $code] = self::webhookAgent(is_array($first) ? $first : null, $makersByQuote, $appsByQuote,
                $makersOfClient, $appsOfClient, is_array($retailers) ? $retailers : [], time(),
                $state['auto_quote'] && (($config['kyc_auto_quote_enabled'] ?? true) !== false),     // both readings must say on
                is_string($quote['adminNotes'] ?? null) ? $quote['adminNotes'] : null);
            if ($code !== '') {
                return ['address' => '', 'code' => $code, 'source' => $source, 'rule' => $rule, 'agent_id' => $id, 'decided_at' => $at];
            }
            if (!class_exists('CustomerEmailDispatcher')) require_once __DIR__ . '/CustomerEmailDispatcher.php';
            if (!class_exists('EmailRecipients')) require_once __DIR__ . '/EmailRecipients.php';
            $to = self::toOf($client);
            $already = CustomerEmailDispatcher::ccEnabled($config) ? EmailRecipients::ccFor($client, $to) : [];
            $row = $store->findOne('retailers.json', 'id', (int)$id);
            $d = self::decide(is_array($row) ? $row : null, $id, $source, $rule, $to, $already, $state['admins']);
            $d['decided_at'] = $at;
            return $d;
        } catch (\Throwable $e) {
            return ['address' => '', 'code' => 'error', 'source' => 'none', 'rule' => '', 'agent_id' => null, 'decided_at' => $at];
        }
    }

    // ── records (writes; never throw) ────────────────────────────────────────────────────────────────────────────

    /** The hand-off (§H.5.5): who is making a quote for which client, just before the POST. Its row id, or 0. */
    public static function markMaking($store, string $pluginRoot, string $dataDir, int $clientId, array $retailer): int
    {
        try {
            $maker = (int)($retailer['id'] ?? 0);
            if ($clientId <= 0 || $maker <= 0 || !self::state($pluginRoot, $dataDir)['on']) return 0;
            $row = $store->appendWithId(self::MAKERS, ['client_id' => $clientId, 'maker_id' => $maker, 'quote_id' => null,
                'state' => 'making', 'at' => time(), 'settled_at' => null]);
            return (int)($row['id'] ?? 0);
        } catch (\Throwable $e) {
            self::lost('maker', $e);
            return 0;
        }
    }

    /** A row still 'making' becomes 'made' (quote id above 0) or 'failed' (0); any other state is left alone. */
    public static function markResult($store, int $rowId, int $quoteId): void
    {
        if ($rowId <= 0) return;
        try {
            $row = $store->findOne(self::MAKERS, 'id', $rowId);
            if (!is_array($row) || ($row['state'] ?? '') !== 'making') return;
            $store->updateOne(self::MAKERS, 'id', $rowId, ['state' => $quoteId > 0 ? 'made' : 'failed',
                'quote_id' => $quoteId > 0 ? $quoteId : null, 'settled_at' => time()]);
        } catch (\Throwable $e) {
            self::lost('maker result', $e);
        }
    }

    /** One decision, after the send: exactly the eleven declared fields beside the store's id — no address, name or phone. */
    public static function record($store, string $path, int $quoteId, string $number, array $p7, bool $sentOk, string $rcpt): void
    {
        try {
            $code = (string)($p7['code'] ?? 'error');
            $agent = isset($p7['agent_id']) && (int)$p7['agent_id'] > 0 ? (int)$p7['agent_id'] : null;
            $store->appendWithId(self::DECISIONS, [
                'quote_id'   => $quoteId,
                'number'     => $number,
                'path'       => $path,
                'outcome'    => $code === 'added' ? 'added' : 'skipped',
                'code'       => $code,
                'source'     => (string)($p7['source'] ?? ''),
                'rule'       => (string)($p7['rule'] ?? ''),
                'agent_id'   => $agent,
                'rcpt'       => $rcpt,
                'send_ok'    => $sentOk ? 1 : 0,
                'decided_at' => (string)($p7['decided_at'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            self::lost('decision', $e);
        }
    }

    /** One fixed line for a lost write: what, and the exception's class — never its message. */
    private static function lost(string $what, \Throwable $e): void
    {
        try {
            if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';
            PluginLog::write('quote_agent_copy', $what . ' not written (' . get_class($e) . ')');
        } catch (\Throwable $e2) {
        }
    }

    // ── the check, AC-ON (read-only; ruling R13's row P) ─────────────────────────────────────────────────────────

    /**
     * AC-ON (§H.5.10.4). Reads the book through $ro alone (tables checked in sqlite_master first, never created), the
     * settings files and quote_mail.log; writes nothing. Each decision is judged only by what its own row recorded and
     * placed by its decided_at (ruling R18); what P7 could not have known is printed, never a FAIL.
     *
     * @return array{status:string, counts:array<string,int>, notes:list<string>}
     *  status: 'PASS' | 'FAIL <code>' | 'NOT_YET too_soon|no_traffic'
     */
    public static function check(\PDO $ro, string $pluginRoot, string $dataDir, int $now): array
    {
        $counts = [];
        $notes = [];
        try {
            if (!class_exists('PluginConfig')) require_once __DIR__ . '/PluginConfig.php';
            $cfg = PluginConfig::read($pluginRoot, $dataDir);
            // Rule 2: plugin mail as it reads now — a note, never a status.
            $notes[] = self::preconditions($dataDir)['lines'][0];
            $notes[] = 'kyc_quote_send_via_crm: ' . self::viaCrmState($cfg);
            // Rule 1 (root docs/73 §H.5.10.4; rulings R3, R18): the since-stamp read as the other parts read theirs (sinceOf).
            $since = self::sinceOf($cfg[self::SINCE] ?? null);
            if ($since === null) return ['status' => 'FAIL since_unreadable', 'counts' => $counts, 'notes' => $notes];
            // Rule 3: the window.
            $lo = $since + self::MARGIN;
            $hi = $now - self::MARGIN;
            if ($lo > $hi) return ['status' => 'NOT_YET too_soon', 'counts' => $counts, 'notes' => $notes];
            $inWin = function (int $t) use ($lo, $hi): bool { return $t >= $lo && $t <= $hi; };

            // Rule 4: the e-mails and the decisions.
            $lines = self::mailLines($dataDir);                                    // [[t, number], …]
            $rows = self::sentRows($ro);                                           // [[t, quote id, status], …] (every quotation row)
            $decs = self::decisionRows($ro);                                       // decoded rows
            $p1Lines = [];
            $p1Since = [];
            foreach ($lines as [$t, $n]) {
                if ($inWin($t)) $p1Lines[$n] = ($p1Lines[$n] ?? 0) + 1;
                if ($t >= $since) $p1Since[$n] = ($p1Since[$n] ?? 0) + 1;
            }
            $p3Rows = [];
            $rowStatus = [];
            foreach ($rows as [$t, $qid, $status]) {
                $rowStatus[$qid] = $status;
                if ($status === 'sent' && $t !== null && $inWin($t)) $p3Rows[] = $qid;
            }
            $bad = false;
            $placed = [];                                                          // decisions with a readable decided_at
            foreach ($decs as $d) {
                $t = self::stampOf($d['decided_at'] ?? null);
                if ($t === null) { $bad = true; continue; }                         // rule 9: never drops out of the counts
                $d['_t'] = $t;
                $placed[] = $d;
            }
            $fromSince = array_values(array_filter($placed, function ($d) use ($since) { return $d['_t'] >= $since; }));
            $inWindow  = array_values(array_filter($placed, function ($d) use ($inWin) { return $inWin($d['_t']); }));

            // Rule 5.
            $emails = array_sum($p1Lines) + count($p3Rows);
            if ($emails === 0 && $inWindow === [] && !$bad) {
                return ['status' => 'NOT_YET no_traffic', 'counts' => $counts, 'notes' => $notes];
            }

            // The printed counts, per path (decisions placed in the window).
            foreach (self::PATHS as $path) {
                $px = $path === 'plugin' ? 'p1_' : 'p3_';
                $mine = array_values(array_filter($inWindow, function ($d) use ($path) { return ($d['path'] ?? '') === $path; }));
                $counts[$px . 'decisions'] = count($mine);
                $counts[$px . 'added'] = 0;
                $counts[$px . 'skipped'] = 0;
                foreach (['accepted', 'refused', 'none', 'excluded'] as $rc) $counts[$px . 'rcpt_' . $rc] = 0;
                $counts[$px . 'sent_carrying_copy'] = 0;
                foreach (['none', 'accepted', 'refused'] as $rc) $counts[$px . 'failed_carrying_copy_rcpt_' . $rc] = 0;
                $counts[$px . 'failed_without_copy'] = 0;
                foreach ($mine as $d) {
                    $added = ($d['outcome'] ?? '') === 'added';
                    $code = (string)($d['code'] ?? '');
                    if ($added) $counts[$px . 'added']++;
                    else {
                        $counts[$px . 'skipped']++;
                        if (in_array($code, self::CODES, true)) $counts[$px . 'skipped_' . $code] = ($counts[$px . 'skipped_' . $code] ?? 0) + 1;
                    }
                    $rc = (string)($d['rcpt'] ?? '');
                    if (in_array($rc, self::RCPTS, true) && ($added || $rc === 'excluded')) $counts[$px . 'rcpt_' . $rc]++;
                    $src = (string)($d['source'] ?? '');
                    if (in_array($src, self::SOURCES, true)) $counts[$px . 'source_' . $src] = ($counts[$px . 'source_' . $src] ?? 0) + 1;
                    $ok = (int)($d['send_ok'] ?? 0) === 1;
                    if ($added && $ok) $counts[$px . 'sent_carrying_copy']++;
                    // Rule 7: a copy that rode on a failed send — a count with its path's line, never a status.
                    if ($added && !$ok && in_array($rc, ['none', 'accepted', 'refused'], true)) $counts[$px . 'failed_carrying_copy_rcpt_' . $rc]++;
                    if (!$added && !$ok) $counts[$px . 'failed_without_copy']++;
                }
                $failed = $counts[$px . 'failed_carrying_copy_rcpt_none'] + $counts[$px . 'failed_carrying_copy_rcpt_accepted']
                        + $counts[$px . 'failed_carrying_copy_rcpt_refused'];
                if ($failed > 0) {
                    $notes[] = ($path === 'plugin' ? 'path 1' : 'path 3') . ': failed carrying a copy ' . $failed
                        . ($path === 'plugin' ? " — handed to uCRM's /send" : ' — a lost e-mail: resend with tools/quote_email_send.php');
                }
                if ($counts[$px . 'failed_without_copy'] > 0) {
                    $notes[] = ($path === 'plugin' ? 'path 1' : 'path 3') . ': failed without a copy ' . $counts[$px . 'failed_without_copy']
                        . ($path === 'plugin' ? " — handed to uCRM's /send" : ' — a lost e-mail: resend with tools/quote_email_send.php');
                }
            }

            // Printed, never a FAIL: forgotten, sent again, row not settled, no log line.
            $forgotten = 0; $unsettled = 0; $okByQuote = []; $noLine = 0; $p1InWin = [];
            foreach ($inWindow as $d) {
                if ((int)($d['send_ok'] ?? 0) !== 1) continue;
                $qid = (int)($d['quote_id'] ?? 0);
                if (($d['path'] ?? '') === 'webhook') {
                    if (!array_key_exists($qid, $rowStatus)) $forgotten++;
                    elseif ($rowStatus[$qid] !== 'sent') $unsettled++;
                } elseif (($d['path'] ?? '') === 'plugin') {
                    $n = (string)($d['number'] ?? '');
                    $p1InWin[$n] = ($p1InWin[$n] ?? 0) + 1;
                }
            }
            foreach ($fromSince as $d) {
                if ((int)($d['send_ok'] ?? 0) === 1) {
                    $k = (string)($d['path'] ?? '') . '|' . (int)($d['quote_id'] ?? 0);
                    $okByQuote[$k] = ($okByQuote[$k] ?? 0) + 1;
                }
            }
            foreach ($p1InWin as $n => $k) $noLine += max(0, $k - ($p1Since[$n] ?? 0));
            $again = count(array_filter($okByQuote, function ($k) { return $k > 1; }));
            $counts['forgotten_resend_tool'] = $forgotten;
            $counts['sent_again'] = $again;
            $counts['row_not_settled'] = $unsettled;
            $counts['no_log_line'] = $noLine;
            if ($forgotten > 0) $notes[] = 'forgotten (resend tool): ' . $forgotten;
            if ($again > 0) $notes[] = 'sent again: ' . $again;
            if ($unsettled > 0) $notes[] = 'row not settled: ' . $unsettled;
            if ($noLine > 0) $notes[] = 'no log line: ' . $noLine;

            // Rule 6: the positive control on the hooks, paired — e-mails windowed, decisions taken from since on.
            $p3Ok = []; $p1Ok = [];
            foreach ($fromSince as $d) {
                if ((int)($d['send_ok'] ?? 0) !== 1) continue;
                if (($d['path'] ?? '') === 'webhook') $p3Ok[(int)($d['quote_id'] ?? 0)] = true;
                if (($d['path'] ?? '') === 'plugin') {
                    $n = (string)($d['number'] ?? '');
                    $p1Ok[$n] = ($p1Ok[$n] ?? 0) + 1;
                }
            }
            foreach ($p3Rows as $qid) {
                if (empty($p3Ok[$qid])) return ['status' => 'FAIL decision_missing', 'counts' => $counts, 'notes' => $notes];
            }
            foreach ($p1Lines as $n => $k) {
                if ($k > ($p1Ok[$n] ?? 0)) return ['status' => 'FAIL decision_missing', 'counts' => $counts, 'notes' => $notes];
            }
            // Rule 8: an added row that names no agent — the row alone, never today's accounts or setting.
            foreach ($fromSince as $d) {
                if (($d['outcome'] ?? '') !== 'added') continue;
                $src = (string)($d['source'] ?? '');
                if ((int)($d['agent_id'] ?? 0) <= 0 || in_array($src, ['in_flight', 'none'], true) || !in_array($src, self::SOURCES, true)) {
                    return ['status' => 'FAIL added_without_agent', 'counts' => $counts, 'notes' => $notes];
                }
            }
            // Rule 9: a value outside its fixed list, or a decided_at that is not a stamp.
            if ($bad) return ['status' => 'FAIL unknown_code', 'counts' => $counts, 'notes' => $notes];
            foreach ($fromSince as $d) {
                if (!in_array((string)($d['code'] ?? ''), self::CODES, true) || !in_array((string)($d['path'] ?? ''), self::PATHS, true)
                    || !in_array((string)($d['source'] ?? ''), self::SOURCES, true) || !in_array((string)($d['rule'] ?? ''), self::RULES, true)
                    || !in_array((string)($d['rcpt'] ?? ''), self::RCPTS, true) || !in_array((string)($d['outcome'] ?? ''), ['added', 'skipped'], true)) {
                    return ['status' => 'FAIL unknown_code', 'counts' => $counts, 'notes' => $notes];
                }
            }
            return ['status' => 'PASS', 'counts' => $counts, 'notes' => $notes];
        } catch (\Throwable $e) {
            $notes[] = 'the check could not read (' . get_class($e) . ')';
            return ['status' => 'FAIL check_error', 'counts' => $counts, 'notes' => $notes];
        }
    }

    /**
     * quote_agent_cc_since as unix time, read as every other part reads the since-stamp the tool writes for it: through
     * UtcClock::parse (a bare 'Y-m-d H:i:s' or 'Y-m-d' as UTC; ISO 8601 with 'Z' or an offset, or a trailing UTC/GMT,
     * honoured as written — lib/QuoteDailyList.php's quote_daily_list_since), or unix seconds in digits, as
     * lib/QuoteTracker.php reads quote_tracking_since. Null when neither gives a positive time (rule 1, since_unreadable).
     * A correct switch-on must never read FAIL for its stamp's form (root docs/73 §H.5.10.4 rule 1; ruling R18); decided_at,
     * which P7 writes itself, keeps stampOf's one strict form (rule 9).
     */
    private static function sinceOf($v): ?int
    {
        if (is_bool($v) || !is_scalar($v)) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        if (!class_exists('UtcClock')) require_once __DIR__ . '/UtcClock.php';
        $t = ctype_digit($s) ? (int)$s : UtcClock::parse($s);
        return $t > 0 ? $t : null;
    }

    /** A UTC stamp in the storage form 'Y-m-d H:i:s' (UtcClock::stamp), as unix time; null when it is not one. */
    private static function stampOf($s): ?int
    {
        if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
        $t = strtotime($s . ' UTC');
        return ($t === false || gmdate('Y-m-d H:i:s', $t) !== $s) ? null : $t;
    }

    private static function viaCrmState(array $cfg): string
    {
        if (!array_key_exists('kyc_quote_send_via_crm', $cfg)) return 'unset';
        $v = $cfg['kyc_quote_send_via_crm'];
        return ($v === 0 || $v === '0' || $v === false) ? '0' : 'on';
    }

    /** quote_mail.log's sent lines: their stamp and the quote's number only (the text between '] ' and the first ' -> '). */
    private static function mailLines(string $dataDir): array
    {
        $f = rtrim($dataDir, '/') . '/quote_mail.log';
        $out = [];
        if (!is_file($f) || !is_readable($f)) return $out;
        $h = @fopen($f, 'rb');
        if ($h === false) return $out;
        while (($l = fgets($h)) !== false) {
            $l = rtrim($l, "\r\n");
            if (substr($l, -5) !== ' sent' || !preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] /', $l, $m)) continue;
            $rest = substr($l, strlen($m[0]));
            $p = strpos($rest, ' -> ');
            if ($p === false) continue;
            $t = self::stampOf($m[1]);
            if ($t === null) continue;
            $out[] = [$t, substr($rest, 0, $p)];
        }
        fclose($h);
        return $out;
    }

    private static function hasTable(\PDO $ro, string $t): bool
    {
        $st = $ro->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$t]);
        return $st->fetchColumn() !== false;
    }

    /** customer_email_log's quotation rows: [created_at as unix time or null, quote id, status]. */
    private static function sentRows(\PDO $ro): array
    {
        if (!self::hasTable($ro, 'customer_email_log')) return [];
        $out = [];
        $st = $ro->query("SELECT dedupe_key, status, created_at FROM customer_email_log WHERE template = 'quotation' AND dedupe_key LIKE 'quotation:%'");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = substr((string)$r['dedupe_key'], strlen('quotation:'));
            if (!ctype_digit($id)) continue;
            $out[] = [self::stampOf((string)$r['created_at']), (int)$id, (string)$r['status']];
        }
        return $out;
    }

    /** quote_agent_copies' rows, decoded (a store collection: id + data). */
    private static function decisionRows(\PDO $ro): array
    {
        if (!self::hasTable($ro, 'quote_agent_copies')) return [];
        $out = [];
        foreach ($ro->query('SELECT data FROM quote_agent_copies ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $j) {
            $d = json_decode((string)$j, true);
            $out[] = is_array($d) ? $d : ['decided_at' => null];
        }
        return $out;
    }
}
