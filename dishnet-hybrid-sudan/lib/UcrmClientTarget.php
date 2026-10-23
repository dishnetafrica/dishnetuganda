<?php
declare(strict_types=1);

require_once __DIR__ . '/EfrisClientField.php';
require_once __DIR__ . '/QuoteOrganization.php';   // 5.18.99: the Uganda quoting organization

/**
 * UcrmClientTarget — where a new uCRM client made by this plugin goes, on the
 * uCRM that is actually connected.
 *
 * The KYC create was written for the South Sudan uCRM. It names organization 2
 * and nine client custom fields by number: 1 Sales Person, 36 Priority, 37–40
 * the kit, 41 Package, 42 Device, 43 Ref. On the Uganda uCRM, measured on
 * 25 September 2026, organization 2 does not exist and neither do fields
 * 36–43, so every KYC customer was refused with "404 Not Found" and stayed in
 * the plugin. Field 1 exists there, and it is "EFRIS TIN".
 *
 * So the numbers are checked against this uCRM before anything is sent:
 *
 *   organization   2 when this uCRM has it, because that is the install the
 *                  code was written for; otherwise the only organization;
 *                  otherwise REFUSE. Never a guess between two companies.
 *   custom fields  the nine, unchanged, only when ALL nine exist here as client
 *                  fields and none of them is a tax field; otherwise none.
 *
 * An install where today's payload works is sent exactly today's payload — the
 * standing rule is that the South Sudan install does not change without new
 * configuration.
 *
 * 5.18.99 (Uganda, the operator, 20 Oct 2026: "always company 1"): built with
 * forInstall(), on the Uganda tenant the organization is the configured quoting
 * organization (QuoteOrganization: ucrm_quote_organization_id, else the Uganda
 * profile's quoting.ucrm_organization_id), checked against this uCRM's list. It is
 * never "prefer 2": that rule filed new app customers under Uganda's second
 * company. If the configured one is not listed, or the setting is not a number,
 * the answer is a refusal marked 'config' — never a guess. Where the gate is off
 * (South Sudan, or the setting 0/off) the rule below is unchanged, byte for byte.
 *
 * Nothing is decided without asking. If uCRM cannot be read the answer is an
 * error, nothing is sent, and the application waits for the retry job.
 */
final class UcrmClientTarget
{
    const LEGACY_ORGANIZATION  = 2;
    const LEGACY_ATTRIBUTE_IDS = [1, 36, 37, 38, 39, 40, 41, 42, 43];

    private $crm;
    /** @var int[]|null organization ids, once asked */
    private ?array $organizations = null;
    /** @var array<int,array>|null client custom fields by id, once asked */
    private ?array $clientFields = null;
    private string $orgError = '';
    private string $fieldError = '';
    /** 5.18.99: QuoteOrganization::setting() for this install; [] (the gate off) when built without one. */
    private array $quoteOrg;
    /** @var array[]|null 5.18.99: the organization rows read with the ids, for the quote check after the create */
    private ?array $organizationRows = null;

    public function __construct($crm, array $quoteOrg = [])
    {
        $this->crm = $crm;
        $this->quoteOrg = $quoteOrg;
    }

    /** 5.18.99: the target for this install — on Uganda, the configured quoting organization (QuoteOrganization). */
    public static function forInstall($crm, array $config, ?string $dataDir): self
    {
        return new self($crm, QuoteOrganization::setting($config, $dataDir));
    }

    /** @return array{org:?int,error:string,config?:bool} */
    public function organization(): array
    {
        // 5.18.99 (Uganda): the configured quoting organization, if this uCRM lists it; otherwise a refusal marked
        // 'config', because retrying cannot fix it. Never a guess, never "prefer 2".
        if (!empty($this->quoteOrg['on'])) {
            $want = $this->quoteOrg['id'] ?? null;
            if (($this->quoteOrg['error'] ?? '') !== '' || !is_int($want) || $want <= 0) {
                return ['org' => null, 'error' => 'the company Uganda customers are filed under is not set correctly, so the plugin will not guess which company to file the customer under. Tell an admin', 'config' => true];
            }
            $listed = $this->organizations();
            if ($listed === null) return ['org' => null, 'error' => $this->orgError];
            if (in_array($want, $listed, true)) return ['org' => $want, 'error' => ''];
            return ['org' => null, 'error' => 'uCRM does not list the company Uganda customers are filed under (organization #' . $want
                . '; it lists ' . ($listed === [] ? 'no organization' : 'organization #' . implode(', #', $listed))
                . '), so the plugin will not guess which company to file the customer under. Tell an admin', 'config' => true];
        }
        $orgs = $this->organizations();
        if ($orgs === null) return ['org' => null, 'error' => $this->orgError];
        if (in_array(self::LEGACY_ORGANIZATION, $orgs, true)) {
            return ['org' => self::LEGACY_ORGANIZATION, 'error' => ''];
        }
        if (count($orgs) === 1) return ['org' => $orgs[0], 'error' => ''];
        return ['org' => null, 'error' => $orgs === []
            ? 'uCRM has no organization to put the customer in'
            : 'uCRM has ' . count($orgs) . ' organizations and none of them is organization '
              . self::LEGACY_ORGANIZATION . ', so the plugin will not guess which one'];
    }

    /** @return array{legacy:bool,error:string} whether the nine numbered fields belong on this uCRM */
    public function customFields(): array
    {
        $fields = $this->clientFields();
        if ($fields === null) return ['legacy' => false, 'error' => $this->fieldError];
        foreach (self::LEGACY_ATTRIBUTE_IDS as $id) {
            if (!isset($fields[$id]))                         return ['legacy' => false, 'error' => ''];
            if (EfrisClientField::isTaxField($fields[$id]))   return ['legacy' => false, 'error' => ''];
        }
        return ['legacy' => true, 'error' => ''];
    }

    /**
     * The whole South Sudan layout: organization 2 and all nine fields. The
     * work-order templates and client tags the KYC flow uses are numbered for
     * that layout too, so they are only used where it is present.
     */
    public function legacyLayout(): bool
    {
        $o = $this->organization();
        $f = $this->customFields();
        return $o['error'] === '' && $f['error'] === ''
            && $o['org'] === self::LEGACY_ORGANIZATION && $f['legacy'];
    }

    /**
     * Fit a client-create payload to this uCRM: the organization it has, and
     * the custom fields only where they mean what the plugin means.
     *
     * @return array{payload:?array,error:string}
     */
    public function apply(array $payload): array
    {
        $o = $this->organization();
        if ($o['error'] !== '') {
            return ['payload' => null, 'error' => $o['error']] + (!empty($o['config']) ? ['config' => true] : []);   // 5.18.99
        }
        $f = $this->customFields();
        if ($f['error'] !== '') return ['payload' => null, 'error' => $f['error']];

        $payload['organizationId'] = $o['org'];
        if (!$f['legacy']) unset($payload['attributes']);
        return ['payload' => $payload, 'error' => ''];
    }

    /**
     * A short, safe account of a failed uCRM call — for the record, the agent
     * and the log. It carries the HTTP status and uCRM's own message; never the
     * request, which holds the customer's details.
     */
    public static function describe(array $lastError): string
    {
        if (isset($lastError['curl_error'])) {
            return 'uCRM could not be reached (' . self::clean((string)$lastError['curl_error']) . ')';
        }
        if (isset($lastError['http_code'])) {
            $resp = $lastError['response'] ?? null;
            $msg  = is_array($resp) ? (string)($resp['message'] ?? '') : '';
            $out  = 'uCRM answered HTTP ' . (int)$lastError['http_code'] . ($msg !== '' ? ': ' . self::clean($msg) : '');
            if (is_array($resp) && !empty($resp['errors']) && is_array($resp['errors'])) {
                $out .= ' (' . self::clean(implode(', ', array_keys($resp['errors']))) . ')';
            }
            return $out;
        }
        if (isset($lastError['plugin'])) return self::clean((string)$lastError['plugin']);
        return 'uCRM gave no answer';
    }

    /** Printable and short: this text is shown in the staff app, which renders it as HTML. */
    private static function clean(string $s): string
    {
        $s = preg_replace('/[^\p{L}\p{N} .,:;()#\/_\-]/u', '', $s) ?? '';
        return mb_substr(trim($s), 0, 160);
    }

    /** @return int[]|null */
    private function organizations(): ?array
    {
        if ($this->organizations !== null || $this->orgError !== '') return $this->organizations;
        $rows = $this->crm->get('organizations');
        if (!is_array($rows) || isset($rows['raw'])) {
            $this->orgError = 'the organizations could not be read: '
                . self::describe($this->crm->getLastError());
            return null;
        }
        $ids = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['id'])) $ids[] = (int)$r['id'];
        }
        $this->organizationRows = array_values(array_filter($rows, 'is_array'));   // 5.18.99: kept for the quote check
        return $this->organizations = $ids;
    }

    /** 5.18.99: the organization rows read with the ids (null when none were read); the quote check reuses them. */
    public function organizationRows(): ?array
    {
        return $this->organizationRows;
    }

    /** @return array<int,array>|null */
    private function clientFields(): ?array
    {
        if ($this->clientFields !== null || $this->fieldError !== '') return $this->clientFields;
        $rows = $this->crm->get('custom-attributes');
        if (!is_array($rows) || isset($rows['raw'])) {
            $this->fieldError = 'the custom fields could not be read: '
                . self::describe($this->crm->getLastError());
            return null;
        }
        $fields = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['id'])) continue;
            $type = (string)($r['attributeType'] ?? 'client');
            if ($type !== 'client') continue;   // service/invoice fields are not client fields
            $fields[(int)$r['id']] = $r;
        }
        return $this->clientFields = $fields;
    }
}
