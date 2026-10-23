<?php
declare(strict_types=1);

/**
 * QuoteOrganization — 5.18.99 (Uganda): the one uCRM company app customers are filed under and app quotes are made for.
 *
 * The operator, 20 Oct 2026: "always company 1". Uganda's uCRM holds a second company, and the KYC form's old rule
 * (UcrmClientTarget: "prefer organization 2") filed new app customers there, so their quotations came out under the
 * wrong company. From 5.18.99, on the Uganda tenant:
 *
 *   - a KYC customer is created in the configured organization (UcrmClientTarget::forInstall);
 *   - an AI lead falls back to it when ucrm_lead_organization_id is unset (UcrmLeadSync);
 *   - BEFORE any app quote is made in uCRM, the client's organizationId is read, and a client filed under another
 *     organization is refused with a plain message for the salesperson (checkClient): nothing is created, nothing is
 *     sent. The salesperson is told to move the customer to the quoting company first;
 *   - the WhatsApp letterhead and the plugin's fallback PDF name the client's own organization.
 *
 * The gate is read exactly as QuoteTaxLine::applies reads its own — the Uganda tenant (TenantProfile, with the data
 * directory so a vault-only currency still selects it) — AND a setting: the explicit key ucrm_quote_organization_id,
 * else profiles/uganda.json quoting.ucrm_organization_id. 0/off/no/none/false switch it off (5.18.98's behaviour). An
 * invalid value never falls back to a guess: every user of the gate refuses. South Sudan, and any book where the gate
 * is off, never reads another key and never sends a request from here.
 *
 * Logged on a refusal: ids and organization numbers only — never a name, a phone, an amount or an organization's name.
 * The list of organizations is read live, memoised per instance only: a cache would let a company deleted or renamed
 * in uCRM keep answering, and would hide uCRM being down.
 *
 * PHP 7.4 compatible.
 */
final class QuoteOrganization
{
    public const KEY          = 'ucrm_quote_organization_id';
    public const PROFILE_PATH = 'quoting.ucrm_organization_id';
    private const OFF_WORDS   = ['0', 'off', 'no', 'none', 'false'];

    /** The salesperson-facing refusals (SPEC §1.4). {CLIENT_ORG}/{QUOTE_ORG} are "<name> (#N)" or "organization #N". */
    private const TEXTS = [
        'R1' => 'This customer is filed under {CLIENT_ORG} in uCRM. Move them to {QUOTE_ORG} in uCRM (or ask an admin to), then make the quote again.',
        'R2' => 'uCRM does not say which company this customer is filed under. Open the customer in uCRM and file them under {QUOTE_ORG} (or ask an admin to), then make the quote again.',
        'R3' => 'uCRM has no customer #{ID}. Check the customer\'s CRM ID, then make the quote again.',
        'R4' => 'uCRM could not be reached to check which company this customer is filed under, so no quotation was made. Try again in a minute; if it keeps happening, tell an admin.',
        'R5' => 'The company the app quotes under (uCRM organization #{M}) is not in uCRM, so no quotation was made. Tell an admin.',
        'R6' => 'The company the app quotes under is not set correctly, so no quotation was made. Tell an admin.',
        'R7' => 'uCRM refused the app\'s key while checking which company this customer is filed under, so no quotation was made. Tell an admin: trying again will not help.',
    ];

    /** {REASON} of the text shown after a KYC customer was created but its quotation refused (SPEC §2.2, K). */
    private const REASONS = [
        'R1' => 'uCRM filed them under {CLIENT_ORG}. Move them to {QUOTE_ORG} in uCRM (or ask an admin to).',
        'R2' => 'uCRM does not say which company they are filed under. File them under {QUOTE_ORG} in uCRM (or ask an admin to).',
        'R3' => 'uCRM did not find the new customer. Tell an admin first.',
        'R4' => 'uCRM could not be reached to check which company they are filed under. Wait a minute.',
        'R5' => 'the company the app quotes under is not set correctly. Tell an admin first.',
        'R6' => 'the company the app quotes under is not set correctly. Tell an admin first.',
        'R7' => 'uCRM refused the app\'s key. Tell an admin first.',
    ];

    private $crm;
    /** @var array{on:bool,id:?int,source:string,error:string} */
    private array $setting;
    /** @var callable|null where refusal lines go; null = error_log and PluginLog */
    private $log;
    /** @var array{list:?array,error:string}|null the organization list, once read */
    private ?array $orgs = null;
    private ?array $lastClient = null;

    public function __construct($crm, array $setting, ?callable $log = null)
    {
        $this->crm     = $crm;
        $this->setting = $setting;
        $this->log     = $log;
    }

    /**
     * Whether the Uganda quoting organization applies here, and which one. Never throws; anything unclear about the
     * tenant is "off", and anything unclear about the value is on with error 'invalid' (every user then refuses).
     *
     * @return array{on:bool,id:?int,source:string,error:string}
     */
    public static function setting(array $config, ?string $dataDir = null): array
    {
        $off = ['on' => false, 'id' => null, 'source' => 'off', 'error' => ''];
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            $profile = TenantProfile::current($config, ($dataDir !== null && $dataDir !== '') ? $dataDir : null);
            if ($profile->id() !== 'uganda') return $off;
        } catch (\Throwable $e) {
            return $off;
        }

        $v = $config[self::KEY] ?? null;
        if (is_string($v)) $v = trim($v);
        if ($v !== null && $v !== '') {
            if (is_string($v) && in_array(strtolower($v), self::OFF_WORDS, true)) {
                return ['on' => false, 'id' => null, 'source' => 'explicit-off', 'error' => ''];
            }
            if (is_string($v) && preg_match('/^[1-9][0-9]{0,8}$/', $v)) {
                return ['on' => true, 'id' => (int)$v, 'source' => 'setting', 'error' => ''];
            }
            if (is_int($v) && $v > 0) {
                return ['on' => true, 'id' => $v, 'source' => 'setting', 'error' => ''];
            }
            return ['on' => true, 'id' => null, 'source' => 'setting', 'error' => 'invalid'];
        }

        try {
            $p = $profile->get(self::PROFILE_PATH);
        } catch (\Throwable $e) {
            return $off;
        }
        if ($p === null) return $off;
        if (is_int($p) && $p > 0) return ['on' => true, 'id' => $p, 'source' => 'profile', 'error' => ''];
        return ['on' => true, 'id' => null, 'source' => 'profile', 'error' => 'invalid'];
    }

    /**
     * May an app quote be made in uCRM for this client? Only GETs: nothing is ever written here.
     *
     * @param string     $where  kyc-existing|kyc-new|kyc-retry|lead|manual|lte|debug-retry|deploy-check (for the log)
     * @param array|null $known  ['organizationId'=>?int, 'organizations'=>?array] from a create answer the caller holds
     * @return array{ok:bool,code:string,message:string,client_org:string,quote_org:string}
     */
    public function checkClient(int $clientId, string $where, ?array $known = null): array
    {
        $this->lastClient = null;
        if (empty($this->setting['on'])) return $this->pass();   // a safety net: callers ask only when the gate is on

        $m = $this->configuredId();
        if ($m === null) {
            $this->logLine($clientId, $where, 'the setting is invalid');
            return $this->refuse('R6', [], '', '');
        }

        // Steps 2–3: the client's organization, from the create answer when it carries one, else uCRM's client row.
        $n = null;
        if (is_array($known) && isset($known['organizationId']) && is_int($known['organizationId']) && $known['organizationId'] > 0) {
            $n = $known['organizationId'];
        } else {
            $c = $this->crm->get("clients/{$clientId}");
            if ($c === null) {
                $err  = method_exists($this->crm, 'getLastError') ? (array)$this->crm->getLastError() : [];
                $code = (int)($err['http_code'] ?? 0);
                if ($code === 404) {
                    $this->logLine($clientId, $where, 'no such client');
                    return $this->refuse('R3', ['{ID}' => (string)$clientId], '', '');
                }
                if ($code === 401 || $code === 403) {
                    $this->logLine($clientId, $where, 'uCRM refused the key (HTTP ' . $code . ')');
                    return $this->refuse('R7', [], '', '');
                }
                $this->logLine($clientId, $where, 'uCRM could not be read (' . self::describe($err) . ')');
                return $this->refuse('R4', [], '', '');
            }
            if (isset($c['raw'])) {
                $this->logLine($clientId, $where, 'uCRM could not be read (uCRM answered with something that is not JSON)');
                return $this->refuse('R4', [], '', '');
            }
            if ((int)($c['id'] ?? 0) !== $clientId) {
                $this->logLine($clientId, $where, 'no such client');
                return $this->refuse('R3', ['{ID}' => (string)$clientId], '', '');
            }
            $this->lastClient = $c;
            $n = (int)($c['organizationId'] ?? 0);
        }

        // Step 4: uCRM does not say.
        if ($n <= 0) {
            $rows = $this->rowsFrom($known);
            $q = self::orgLabel($m, $rows);
            $this->logLine($clientId, $where, 'uCRM gives no organization for the client, quotes are made under #' . $m);
            return $this->refuse('R2', ['{QUOTE_ORG}' => $q], '', $q);
        }

        // Step 5: the configured organization. A client cannot sit in a company uCRM does not have, so no list is read.
        if ($n === $m) return $this->pass();

        // Step 6: another organization. The list is read only to word the refusal.
        $rows = $this->rowsFrom($known);
        if ($rows === null) {
            $cl = 'organization #' . $n; $q = 'organization #' . $m;
            $this->logLine($clientId, $where, 'filed under organization #' . $n . ', quotes are made under #' . $m);
            return $this->refuse('R1', ['{CLIENT_ORG}' => $cl, '{QUOTE_ORG}' => $q], $cl, $q);
        }
        $ids = [];
        foreach ($rows as $r) if (is_array($r) && isset($r['id'])) $ids[] = (int)$r['id'];
        if (!in_array($m, $ids, true)) {
            $this->logLine($clientId, $where, 'organization #' . $m . ' is not in uCRM');
            return $this->refuse('R5', ['{M}' => (string)$m], '', '');
        }
        $cl = self::orgLabel($n, $rows); $q = self::orgLabel($m, $rows);
        $this->logLine($clientId, $where, 'filed under organization #' . $n . ', quotes are made under #' . $m);
        return $this->refuse('R1', ['{CLIENT_ORG}' => $cl, '{QUOTE_ORG}' => $q], $cl, $q);
    }

    /** The client row step 3 read on the last checkClient(), or null (the create answer decided, or the read failed). */
    public function lastClient(): ?array
    {
        return $this->lastClient;
    }

    /**
     * uCRM's organizations, read once per instance. A failed read is an error, never an empty list; the error says
     * why (UcrmClientTarget::describe), for a caller to put in its own sentence.
     *
     * @return array{list:?array,error:string}
     */
    public function organizations(): array
    {
        if ($this->orgs !== null) return $this->orgs;
        $rows = $this->crm->get('organizations');
        if ($rows === null) {
            $err = method_exists($this->crm, 'getLastError') ? (array)$this->crm->getLastError() : [];
            return $this->orgs = ['list' => null, 'error' => self::describe($err)];
        }
        if (isset($rows['raw'])) {
            return $this->orgs = ['list' => null, 'error' => 'uCRM answered with something that is not JSON'];
        }
        if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1)) {
            return $this->orgs = ['list' => null, 'error' => 'uCRM answered with something that is not a list'];
        }
        return $this->orgs = ['list' => array_values(array_filter($rows, 'is_array')), 'error' => ''];
    }

    /**
     * A name shown to staff, who see it rendered as HTML: no < > & " or control characters, trimmed, at most 80
     * characters ($max: QuotationService caps an exception's text at 120).
     */
    public static function plain(string $s, int $max = 80): string
    {
        $s = str_replace(['<', '>', '&', '"'], '', $s);
        $s = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
        return mb_substr(trim($s), 0, $max);
    }

    /** One of the refusal texts with its placeholders filled; R3 for an id that is not a number (Existing Customer). */
    public static function text(string $code, array $vars = []): string
    {
        return strtr(self::TEXTS[$code] ?? self::TEXTS['R4'], $vars);
    }

    /**
     * The text for a KYC customer created in uCRM whose quotation was refused (Q2, Q3). It names the one safe path:
     * submitting the form again is stopped by the phone check, and Existing Customer would debit the wallet again.
     */
    public static function kycText(array $refusal, int $clientId): string
    {
        $code = (string)($refusal['code'] ?? 'R4');
        $reason = strtr(self::REASONS[$code] ?? self::REASONS['R4'], [
            '{CLIENT_ORG}' => (string)($refusal['client_org'] ?? ''),
            '{QUOTE_ORG}'  => (string)($refusal['quote_org'] ?? ''),
        ]);
        return 'The customer is in uCRM as #' . $clientId . ', but no quotation was made: ' . $reason
             . ' Then make the quotation from Send Quotation → Manual with CRM ID ' . $clientId . '.'
             . ' Do not register this customer again, and do not use Existing Customer.';
    }

    /**
     * The organization fields of the plugin's fallback quotation PDF on Uganda, under the keys PluginQuotePdf reads.
     * The quote's own organization fields first; then the organization row of the client (else the configured one),
     * from one GET organizations. A failed read leaves those fields empty — never another tenant's values.
     */
    public static function pdfFields($crm, array $quote, array $client, array $setting): array
    {
        $want = (int)($client['organizationId'] ?? 0);
        if ($want <= 0) $want = (int)($setting['id'] ?? 0);
        $row = [];
        if ($want > 0) {
            $list = (new self($crm, $setting))->organizations()['list'];
            foreach ((array)$list as $o) {
                if (is_array($o) && (int)($o['id'] ?? 0) === $want) { $row = $o; break; }
            }
        }
        $s = function ($v): string { return is_scalar($v) ? trim((string)$v) : ''; };
        $street = $s($quote['organizationStreet1'] ?? '');
        if ($street !== '' && $s($quote['organizationStreet2'] ?? '') !== '') $street .= ', ' . $s($quote['organizationStreet2']);
        $name = $s($quote['organizationName'] ?? '');
        $city = $s($quote['organizationCity'] ?? '');
        return [
            'name'    => $name !== '' ? $name : $s($row['name'] ?? ''),
            'tax_id'  => (string)($quote['organizationTaxId'] ?? ''),
            'reg_no'  => (string)($quote['organizationRegistrationNumber'] ?? ''),
            'street1' => $street !== '' ? $street : $s($row['street1'] ?? ''),
            'city'    => $city !== '' ? $city : $s($row['city'] ?? ''),
            'phone'   => $s($row['phone'] ?? ''),
            'email'   => $s($row['email'] ?? ''),
            'website' => $s($row['website'] ?? ''),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────

    private function configuredId(): ?int
    {
        if (($this->setting['error'] ?? '') !== '') return null;
        $id = $this->setting['id'] ?? null;
        return (is_int($id) && $id > 0) ? $id : null;
    }

    /** The organization rows: the caller's, when it holds some, else one read. null when they cannot be read. */
    private function rowsFrom(?array $known): ?array
    {
        if (is_array($known) && is_array($known['organizations'] ?? null) && $known['organizations'] !== []) {
            return $known['organizations'];
        }
        return $this->organizations()['list'];
    }

    /** "<name> (#N)" when uCRM lists it with a name, else "organization #N": two companies are told apart by number. */
    private static function orgLabel(int $id, ?array $rows): string
    {
        foreach ((array)$rows as $r) {
            if (!is_array($r) || (int)($r['id'] ?? 0) !== $id) continue;
            $name = self::plain(is_scalar($r['name'] ?? null) ? (string)$r['name'] : '');
            if ($name !== '') return $name . ' (#' . $id . ')';
            break;
        }
        return 'organization #' . $id;
    }

    private function pass(): array
    {
        return ['ok' => true, 'code' => '', 'message' => '', 'client_org' => '', 'quote_org' => ''];
    }

    private function refuse(string $code, array $vars, string $clientOrg, string $quoteOrg): array
    {
        return ['ok' => false, 'code' => $code, 'message' => self::text($code, $vars),
                'client_org' => $clientOrg, 'quote_org' => $quoteOrg];
    }

    /** UcrmClientTarget's account of a failed call: the HTTP status and uCRM's own message, never the request. */
    private static function describe(array $lastError): string
    {
        if (!class_exists('UcrmClientTarget')) require_once __DIR__ . '/UcrmClientTarget.php';
        return UcrmClientTarget::describe($lastError);
    }

    /** One line per refusal, ids and organization numbers only. */
    private function logLine(int $clientId, string $where, string $what): void
    {
        $line = '[QuoteOrganization] no quote for client #' . $clientId . ' (' . $where . '): ' . $what;
        try {
            if ($this->log !== null) { ($this->log)($line); return; }
            self::writeLine($line);
        } catch (\Throwable $e) {
            error_log('[QuoteOrganization] refusal not logged: ' . $e->getMessage());
        }
    }

    /**
     * The refusal line for a CRM ID that is not a number (KycService, Existing Customer): there is no client number to
     * name, and the typed value is never logged. It goes where every other refusal line goes: PHP's log and the
     * plugin's log (data/plugin.log, "quotes").
     */
    public static function logNotANumber(string $where): void
    {
        try {
            self::writeLine('[QuoteOrganization] no quote for a CRM ID that is not a number (' . $where . '): no such client');
        } catch (\Throwable $e) {
            error_log('[QuoteOrganization] refusal not logged: ' . $e->getMessage());
        }
    }

    /** The default sink of every refusal line: PHP's log (masked) and the plugin's log. */
    private static function writeLine(string $line): void
    {
        if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';
        error_log(PluginLog::mask($line));
        PluginLog::write('quotes', $line);
    }
}
