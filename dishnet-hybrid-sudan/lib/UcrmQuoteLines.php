<?php
declare(strict_types=1);

require_once __DIR__ . '/CrmApiClient.php';
require_once __DIR__ . '/PublicPriceFeed.php';
require_once __DIR__ . '/QuoteOrganization.php';
require_once __DIR__ . '/UcrmQuoteLinks.php';   // 5.18.102 (D14, docs/71 §R.1.5, F-6): the link judge every resolver holds

/**
 * UcrmQuoteLines — 5.18.102 (Uganda, docs/71 §D, §R): every line of a plugin quotation, and the cash a Cash sale
 * collects, priced from uCRM's Products, never from the plugin's own price fields or the browser's.
 *
 * "uCRM Products are the true price list" (the operator, 20 Oct 2026). Quotation 000314 (27 Oct) showed what the
 * plugin's own prices did: custom lines at subscription_plans.json's customer_price and at whatever price text the
 * browser posted, and a cash amount computed apart from the quote. One class prices the form, the cash amount and
 * every quote, so the three cannot disagree.
 *
 * 5.18.102 D14 (the operator, 28 Oct: "Pick the product per item … Nothing is guessed"): a plugin item reaches uCRM
 * only through the link an admin made on the Plans or Hardware screen (UcrmQuoteLinks). An item without a valid link
 * is unpriced. No name is matched, no spelling ambiguity refuses anything, and ucrm_product_id is never read.
 *
 * The rules, in the order the design gives them:
 *
 *   read     GET products?limit=500 and GET service-plans?limit=500, with the client the quote is made with. A read
 *            succeeds only when both answers are JSON lists with no 'raw' key (CrmApiClient's answer to a body that
 *            is not JSON) and each holds fewer rows than the limit. A failure is never cached and nothing from it is
 *            used. The catalogue keeps what it filtered out (excluded), each service plan's key, plan_keys over the
 *            unfiltered service-plan list — exactly the map the quotation's WhatsApp summary builds (webhook.php,
 *            kept byte for byte) — and that list's row count (docs/71 §R.3.2).
 *   rows     isActive === false or archived === true is ignored where the field exists; a row carrying an
 *            organizationId other than the quoting organization is ignored.
 *   link     the item's one active record, with its row's current stamp, under the quoting organization (§R.1.1).
 *   plan     the linked product and the linked service plan, by id: both counted; the product passes "every";
 *            fewer than 200 service plans in all (6c); no other service plan, counted or not, keyed like the linked
 *            one (6a); exactly one enabled period-1 price; PublicPriceFeed::planPrice of the plan equal to it (6b);
 *            the product's price equal to it; product and service plan keyed alike. The unit is always 'month'.
 *   kit      the kyc_devices.json row by id (never the browser's title or price), then its linked product, by id:
 *            counted, passing "every", not keyed like any listed service plan, counted or not (rule 7), and not the
 *            installation's product while the row is offered (rule 8). Quantity at least 1.
 *   install  the installation's linked product, by id — never a name — with rules 7 and 8; quantity 1 per
 *            application, only when the answer is "DishNet installs".
 *   every    a price above 0 with at most 2 decimals; not taxable and no taxId (uCRM could add tax to the line, and
 *            the cash would not equal the quotation's total); an invoice label, when set, keyed like the name.
 *   Fiber    always unpriced on this installation: its fee and product 244 are South Sudan's.
 *   entry    a sale is priced only when it came from this release's web KYC form (§R.4.2): anything else is 'app'.
 *
 * resolve() answers exactly one of: no_plan (package_choice names no plan row — today's refusal, not "unpriced"),
 * unpriced (with one reason, all or nothing), or priced (the lines and their total), with the sale's entry point and
 * the link records the lines came from. Every name in a reason passes through QuoteOrganization::plain: the staff app
 * prints the agent's message as HTML.
 *
 * Used only where QuoteOrganization::setting()['on'] (D7): Uganda, unless ucrm_quote_organization_id is 0 or off.
 * South Sudan never constructs it.
 */
final class UcrmQuoteLines
{
    /** Marks lines this release resolved (docs/71 §F.3). Never inferred from productId. */
    public const SOURCE       = 'ucrm-5.18.102';
    /** 5.18.102 (D14, §R.2.4): only the form's display word for an unlinked installation line — never matched. */
    public const INSTALL_NAME = 'Professional Installation';
    public const LIMIT        = 500;
    /** 5.18.102 (§R.3.1 rule 6c): the WhatsApp summary reads service-plans?limit=200 (webhook.php, kept). */
    public const SUMMARY_PLAN_LIMIT = 200;
    /** Seconds a successful read may be reused to draw a page (never at submission). */
    public const PAGE_TTL     = 60;
    /** The page-draw timeouts (docs/71 §D.1): seconds to connect, seconds in all. */
    public const PAGE_CONNECT = 3;
    public const PAGE_TOTAL   = 5;
    public const CACHE_FILE   = 'ucrm_quote_catalogue_cache.json';
    /** 5.18.102 (D14, §R.3.2): the page cache's shape version; a cache without it is a miss. */
    public const CACHE_SHAPE  = 'd103-1';   // 5.18.103 (docs/72 §G.3): excluded_names added; 5.18.102's 'd14-1' is a miss both ways
    /** 5.18.103 (§G.5): the last read that succeeded, any age — drawn out of date, never priced from. */
    public const LAST_GOOD_FILE = 'ucrm_quote_catalogue_last_good.json';

    private const NO_INSTALL_CONNECTIONS = ['Shifting Connection', 'Ownership Change'];
    /** The reason when a sale that must say who installs does not (docs/71 §E.1); KycService words its own refusal. */
    public const NO_INSTALL_ANSWER = 'the sale does not say who installs (DishNet, or the customer)';
    /** 5.18.102 (D14, §R.4.1, §R.4.2, E-3): a sale not from this release's web KYC form. It ends at its cause. */
    public const ENTRY_REASON = 'this sale did not come from the KYC form of this release (the retailer app, the mobile API, a form opened before the update, or an application saved before it), so it is not priced from uCRM';
    /** 5.18.102: the web KYC form's own marker (§R.4.2): ucrm_quote_form exactly this string. */
    public const FORM_MARKER  = '5.18.102';
    /** 5.18.103 (docs/72 §G G-9): the direct form's marker; with the switch on, only it is the entry 'web-direct'. */
    public const DIRECT_MARKER = '5.18.103-direct';
    /** 5.18.103 (G-8): what a direct sale stores as quote_sale_entry — read by 5.18.102 as 'app'. */
    public const DIRECT_ENTRY  = 'web-direct';
    /** 5.18.103 (§G.7): marks lines direct mode resolved. Never inferred from productId. */
    public const DIRECT_SOURCE = 'ucrm-direct-5.18.103';
    /** 5.18.103 (§G.5, §G.6): the most of one item, and the most items, a direct sale carries. */
    public const MAX_QTY  = 99;
    public const MAX_CART = 30;
    public const FIBER_REASON = 'Fiber is not priced from uCRM on this installation';
    /** 5.18.102: the installation's own name in reasons (it has no plugin row). */
    private const INSTALL_ROW = 'Installation';

    /**
     * What the agent and the Orders card are told (docs/71 §F.5, §G, §H, §R.4.1, §R.4.7). {REASON} is a resolver reason,
     * already plain; {AMOUNT} and {MAX} are formatted by the caller with the book's currency.
     *   P1           a Credit sale that could not be priced: registered, no quotation
     *   P1_UNLINKED  P1 for a web sale whose reason is "not linked": an admin makes it from the Orders card (E-2)
     *   P2           a retried Cash application whose lines do not total the cash collected: no quotation
     *   P3           a quotation over kyc_auto_quote_max_amount: no quotation
     */
    public const TEXTS = [
        'P1'            => 'No quotation was made: {REASON}. Make the quotation in uCRM (Send Quotation, or ask an admin).',
        'P1_UNLINKED'   => 'No quotation was made: {REASON}. Do not make it by hand — once the item is linked, an admin makes it from the Orders card.',
        'P2'            => 'No quotation was made: the customer paid {AMOUNT} at registration — make the quotation in uCRM for that amount.',
        'P3'            => 'No quotation was made: it is over the auto-quote limit of {MAX} — make the quotation in uCRM.',
        'CASH_UNPRICED' => 'No usable price in uCRM: {REASON}. Nothing was saved and no money was taken. Register as Credit, or ask an admin.',
        'CASH_NO_TOTAL' => 'This Cash sale did not send the total the form showed, so it cannot be checked against uCRM\'s prices. Nothing was saved and no money was taken. Use the web KYC form, or register as Credit.',
        'CASH_CHANGED'  => 'Prices in uCRM changed since the form was opened — reload the form. Nothing was saved and no money was taken.',
        'CASH_RELINKED' => 'An admin changed which uCRM product an item on this sale is linked to since the form was opened — reload the form. Nothing was saved and no money was taken.',
        'CASH_ZERO'     => 'This sale totals nothing in uCRM, so it cannot be a Cash sale. Nothing was saved and no money was taken.',
        'CASH_NO_INSTALL' => 'This sale does not say who installs (DishNet, or the customer), so it cannot be priced from uCRM. Nothing was saved and no money was taken. Choose who installs on the web KYC form, or register as Credit.',
        // 5.18.103 (docs/72 §G.6, §G.8): direct mode's own texts
        'P1_DIRECT'       => 'No quotation was made: {REASON}. Make the quotation in uCRM by hand, or — once the reason is put right — an admin makes it from the Orders card.',
        'CASH_ITEM_CHANGED' => 'An item on this sale changed in uCRM since the form was opened — reload the form. Nothing was saved and no money was taken.',
        'DIRECT_OFF'      => 'This form was opened under the uCRM catalogue, which an admin has since turned off — reload the form. Nothing was saved and no money was taken.',
        'DIRECT_NO_PLAN'  => 'Please select a service plan.',
        'DIRECT_CART'     => 'The sale\'s hardware could not be read — reload the form. Nothing was saved and no money was taken.',
        'GATE_OFF_DIRECT' => 'No quotation was made: a sale from the uCRM catalogue is not quoted while the quoting organization is off — make the quotation in uCRM.',
        'WEB_SWITCH_ON'   => 'This registration was made before the uCRM catalogue was turned on, and its items are the plugin\'s, which are no longer linked — make its quotation in uCRM by hand.',
    ];

    public static function text(string $code, array $vars = []): string
    {
        return strtr(self::TEXTS[$code] ?? self::TEXTS['P1'], $vars);
    }

    private $crm;
    private array $setting;
    /** 5.18.102 (D14): the link judge — required, so a construction site without one is a TypeError, never a name match */
    private UcrmQuoteLinks $links;
    private ?array $cat = null;
    /** @var int number of catalogue reads this instance made (tests assert one per submission) */
    private int $reads = 0;
    /** 5.18.103 (§G G-3): while true, a row uCRM no longer lists is reasoned in direct mode's words (no link to make). */
    private bool $directWords = false;

    /**
     * @param mixed $crm             the client the quote is made with (quoteClient())
     * @param array $setting         QuoteOrganization::setting(): 'on' and the quoting organization 'id'
     * @param UcrmQuoteLinks $links  5.18.102 (D14, §R.4): the links an admin made — the only join to uCRM
     * @param array|null $catalogue  a catalogue already read (forPage()'s cache); null reads on first use
     */
    public function __construct($crm, array $setting, UcrmQuoteLinks $links, ?array $catalogue = null)
    {
        $this->crm     = $crm;
        $this->setting = $setting;
        $this->links   = $links;
        $this->cat     = $catalogue;
    }

    /** The gate (D7): QuoteOrganization's, read through its own method (SAFETY 5.18.99). */
    public static function applies(array $cfg, ?string $dataDir): bool
    {
        return !empty(QuoteOrganization::setting($cfg, $dataDir)['on']);
    }

    /**
     * The client a KYC quote is made with — the rule postQuote() and handleExisting() apply: the admin token when
     * crm_auth_token is set (crm_base_url if given, else the plugin's own base URL), else the plugin's own key. The
     * catalogue is read with the same client, so a token that cannot read Products fails here, not after the quote.
     */
    public static function quoteClient($pluginCrm, array $cfg)
    {
        $token = trim((string)($cfg['crm_auth_token'] ?? ''));
        if ($token === '') return $pluginCrm;
        $url = trim((string)($cfg['crm_base_url'] ?? ''));
        return new CrmApiClient($url !== '' ? $url : rtrim((string)$pluginCrm->getBaseUrl(), '/'), $token, 'x-auth-token');
    }

    /**
     * A resolver for drawing a page (the KYC form, Send Quotation, the Plans and Hardware screens): short timeouts, and
     * a successful read reused for PAGE_TTL seconds from $dataDir. A failure is never written, so the next draw tries
     * again. 5.18.102 (D14): the links are never cached — the caller reads $links live — and a cache without this
     * release's shape version is a miss.
     */
    public static function forPage($crm, array $setting, string $dataDir, UcrmQuoteLinks $links): self
    {
        $file = rtrim($dataDir, '/') . '/' . self::CACHE_FILE;
        $raw  = @file_get_contents($file);
        if (is_string($raw)) {
            $c = json_decode($raw, true);
            if (self::cacheShaped($c) && (time() - (int)($c['at'] ?? 0)) < self::PAGE_TTL) {   // 5.18.103: the shape, one test
                return new self($crm, $setting, $links, $c);
            }
        }
        $short = method_exists($crm, 'withTimeouts') ? $crm->withTimeouts(self::PAGE_CONNECT, self::PAGE_TOTAL) : $crm;
        $self  = new self($short, $setting, $links);
        $cat   = $self->catalogue();
        if (!empty($cat['ok'])) {
            $json = json_encode($cat + ['at' => time(), 'shape' => self::CACHE_SHAPE]);
            @file_put_contents($file, $json, LOCK_EX);
            @file_put_contents(rtrim($dataDir, '/') . '/' . self::LAST_GOOD_FILE, $json, LOCK_EX);   // 5.18.103 (§G.5)
        }
        return $self;
    }

    /** The page cache forPage() keeps in $dataDir, removed (docs/71 §G: the reload after "prices changed" must read uCRM). */
    public static function dropPageCache(string $dataDir): void
    {
        if ($dataDir !== '') @unlink(rtrim($dataDir, '/') . '/' . self::CACHE_FILE);
    }

    public function reads(): int
    {
        return $this->reads;
    }

    /** 5.18.102 (D14): the link judge this resolver prices through — the screens read a link's state from it. */
    public function links(): UcrmQuoteLinks
    {
        return $this->links;
    }

    // ── the catalogue ──────────────────────────────────────────────────────

    /**
     * Products and service plans, read once per instance and filtered to the rows that count. 5.18.102 (D14, §R.3.2):
     * also what was filtered out and why, every service plan's key, plan_keys and the service-plan row count — the
     * summary's own list, unfiltered.
     *
     * @return array{ok:bool,reason:string,products:array,plans:array,excluded:array,plan_key_of:array,
     *               plan_keys:array,plan_rows:int}
     */
    public function catalogue(): array
    {
        if ($this->cat !== null) return $this->cat;
        $this->reads++;
        // 5.18.103 (docs/72 §G.3): excluded_names beside excluded, in a failure as in a success
        $fail = ['ok' => false, 'reason' => "uCRM's products could not be read", 'products' => [], 'plans' => [],
                 'excluded' => ['products' => [], 'plans' => []], 'excluded_names' => ['products' => [], 'plans' => []],
                 'plan_key_of' => [], 'plan_keys' => [], 'plan_rows' => 0];
        // The second list is not asked for once the first has failed: a uCRM that does not answer costs one wait, not two.
        try {
            $products = $this->crm->get('products?limit=' . self::LIMIT);
            if (!self::isList($products) || count($products) >= self::LIMIT) return $this->cat = $fail;
            $plans = $this->crm->get('service-plans?limit=' . self::LIMIT);
        } catch (\Throwable $e) {
            return $this->cat = $fail;
        }
        if (!self::isList($plans) || count($plans) >= self::LIMIT) return $this->cat = $fail;

        // 5.18.102 (§R.3.2): what was filtered out, by id and why — "archived", "not active" and "another organization's"
        // are reasons of their own, and an id in neither list is "no longer listed" (a read never holds 500 rows).
        $excluded = ['products' => [], 'plans' => []];
        $exNames  = ['products' => [], 'plans' => []];   // 5.18.103 (docs/72 §G.3): the screen names them, never matched
        foreach (['products' => $products, 'plans' => $plans] as $kind => $rows) {
            foreach ($rows as $row) {
                $why = $this->exclusion($row);
                if ($why !== '' && is_numeric($row['id'] ?? null)) {
                    $excluded[$kind][(int)$row['id']] = $why;
                    $exNames[$kind][(int)$row['id']]  = is_scalar($row['name'] ?? null) ? (string)$row['name'] : '';
                }
            }
        }
        // 5.18.102 (§R.3.1 rules 6–7): the quotation summary's own map (webhook.php's whQuotePlans, mirrored exactly):
        // every row service-plans returns, counted or not; rows that are not arrays skipped; an empty key ignored.
        $planKeyOf = [];
        $planKeys  = [];
        foreach ($plans as $p) {
            if (!is_array($p)) continue;
            $k = PublicPriceFeed::planKey((string)($p['name'] ?? ''));
            if (is_numeric($p['id'] ?? null)) $planKeyOf[(int)$p['id']] = $k;
            if ($k !== '') $planKeys[$k] = ($planKeys[$k] ?? 0) + 1;
        }
        return $this->cat = [
            'ok'          => true,
            'reason'      => '',
            'products'    => array_values(array_filter($products, [$this, 'counts'])),
            'plans'       => array_values(array_filter($plans, [$this, 'counts'])),
            'excluded'    => $excluded,
            'excluded_names' => $exNames,
            'plan_key_of' => $planKeyOf,
            'plan_keys'   => $planKeys,
            'plan_rows'   => count($plans),
        ];
    }

    /** A JSON list as CrmApiClient returns one: no 'raw' key, keys 0..n-1, every row an array. */
    private static function isList($a): bool
    {
        if (!is_array($a) || array_key_exists('raw', $a)) return false;
        if ($a === []) return true;
        if (array_keys($a) !== range(0, count($a) - 1)) return false;
        foreach ($a as $row) if (!is_array($row)) return false;
        return true;
    }

    /** Does a product or service-plan row count (docs/71 §D.2)? */
    private function counts(array $row): bool
    {
        return $this->exclusion($row) === '';
    }

    /** 5.18.102 (§R.3.2): '' when the row counts, else why not: 'inactive', 'archived' or 'organization'. */
    private function exclusion(array $row): string
    {
        if (array_key_exists('isActive', $row) && $row['isActive'] === false) return 'inactive';
        if (array_key_exists('archived', $row) && $row['archived'] === true) return 'archived';
        if (array_key_exists('organizationId', $row) && $row['organizationId'] !== null) {
            $org = $this->setting['id'] ?? null;
            if (!is_int($org) || (int)$row['organizationId'] !== $org) return 'organization';
        }
        return '';
    }

    // ── one item, by its link (5.18.102, D14, docs/71 §R.4) ─────────────────

    /**
     * The plan line for a subscription_plans row ($kind 'plan'), or the refusal for a kyc_packages row ($kind
     * 'package', reached by resolve()'s fallback): a package is never linkable and never takes a plan link with its id.
     *
     * @return array{ok:bool,reason:string,line:?array,link:?int,unlinked:bool,why:string}  link: the record in force
     */
    public function planLine(array $row, string $kind = 'plan'): array
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return self::no($cat['reason'], 'unreadable');
        $shown = self::rowShown('plan', $row);
        if ($kind !== 'plan') return self::no("{$shown} is a retailer-app package, which is not linked to uCRM", 'package');
        if (UcrmQuoteLinks::isFiber('plan', $row)) return self::no(self::FIBER_REASON, 'fiber');
        $j = $this->links->judge('plan', $row, $this->orgId());
        if ($j['state'] !== 'ok') return self::linkRefusal($j['state'], $shown, 'Plans');
        $rec = $j['record'];
        return $this->planRules($shown, (int)($rec['product_id'] ?? 0), (int)($rec['service_plan_id'] ?? 0),
            (int)($rec['id'] ?? 0), true);
    }

    /**
     * The line for a kyc_devices.json row (a kit or an accessory), by its link.
     *
     * @return array{ok:bool,reason:string,line:?array,link:?int,unlinked:bool,why:string}
     */
    public function kitLine(array $row, int $qty = 1): array
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return self::no($cat['reason'], 'unreadable');
        $shown = self::rowShown('kit', $row);
        if (UcrmQuoteLinks::isFiber('kit', $row)) return self::no(self::FIBER_REASON, 'fiber');
        $j = $this->links->judge('kit', $row, $this->orgId());
        if ($j['state'] !== 'ok') return self::linkRefusal($j['state'], $shown, 'Hardware');
        $rec = $j['record'];
        return $this->hardwareRules('kit', $row, $shown, (int)($rec['product_id'] ?? 0), (int)($rec['id'] ?? 0), true, max(1, $qty));
    }

    /**
     * The installation line (D2, D8, §R.2.4): its linked product, by id — the name Professional Installation is never
     * matched.
     *
     * @return array{ok:bool,reason:string,line:?array,link:?int,unlinked:bool,why:string}
     */
    public function installLine(): array
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return self::no($cat['reason'], 'unreadable');
        $j = $this->links->judge('install', null, $this->orgId());
        if ($j['state'] !== 'ok') return self::linkRefusal($j['state'], self::INSTALL_ROW, 'Hardware');
        $rec = $j['record'];
        return $this->hardwareRules('install', null, self::INSTALL_ROW, (int)($rec['product_id'] ?? 0), (int)($rec['id'] ?? 0), true, 1);
    }

    /**
     * 5.18.102 (D14, docs/71 §R.3.1 rules 2 and 4–8): may this item be linked to this product (and, for a plan, this
     * service plan)? The save asks it before it writes, over a fresh read (rule 3), and the picker per entry. The rules
     * are the very functions the line methods apply at use, so save and use cannot apply different rules. $item is
     * 'plan', 'kit' or 'install'; $row the plugin row as rule 2 read it (null for the installation). $shown names the
     * subject of a reason (default: the plugin row's own name; the picker may pass a product's name), through
     * QuoteOrganization::plain. Rule 8 at save answers with the other item's name; its kit half refuses any kit row, offered
     * or not, as kitLine does at use (5.18.102 D14 review round 2, FX-money-1), and its installation half counts the offered
     * kit rows only, as installLine does.
     *
     * @return array{ok:bool,reason:string,line:?array,link:?int,unlinked:bool,why:string}  line: what it would quote
     */
    public function candidate(string $item, ?array $row, int $productId, ?int $servicePlanId = null, ?string $shown = null): array
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return self::no("uCRM's products could not be read — nothing was linked", 'unreadable');
        if (!in_array($item, UcrmQuoteLinks::ITEMS, true) || ($item !== 'install' && $row === null)) {
            return self::no('there is nothing to link', 'item');
        }
        $who = $shown !== null ? QuoteOrganization::plain($shown) : ($item === 'install' ? self::INSTALL_ROW : self::rowShown($item, $row));
        if ($who === '') $who = $item === 'install' ? self::INSTALL_ROW : self::rowShown($item, $row);
        if ($row !== null && UcrmQuoteLinks::isFiber($item, $row)) return self::no(self::FIBER_REASON, 'fiber');
        if ($item === 'plan') return $this->planRules($who, $productId, (int)($servicePlanId ?? 0), null, false);
        return $this->hardwareRules($item, $row, $who, $productId, null, false, 1);
    }

    /**
     * 5.18.102 (§R.3.1 rules 4–6, §R.3.2): a plan's product and service plan, by id — at use ($atUse, $link the record in
     * force) or for a candidate link at save.
     */
    private function planRules(string $shown, int $pid, int $spid, ?int $link, bool $atUse): array
    {
        $product = $this->listed('products', $pid);
        if ($product === null) return self::no($this->unlisted('products', $pid, $shown, 'product', $atUse), 'unlisted', $link);
        $bad = $this->badProduct($product, $shown);
        if ($bad !== '') return self::no($bad, 'bad', $link);
        $sp = $this->servicePlanRules($spid, $shown, $atUse);
        if ($sp['reason'] !== '') return self::no($sp['reason'], $sp['why'], $link);
        $key   = $sp['key'];
        $month = $sp['month'];
        $price = round((float)$product['price'], 2);
        if (abs($price - $month) > 0.0001) {
            return self::no("{$shown}'s price in Products (" . self::money($price) . ') differs from its service plan ('
                . self::money($month) . ')', 'price_differs', $link);
        }
        if ($key === '' || PublicPriceFeed::planKey((string)($product['name'] ?? '')) !== $key) {
            return self::no("{$shown}'s uCRM product and service plan are not spelled alike, so the quotation's WhatsApp summary could not show it as monthly — an admin fixes the names in uCRM", 'spelled_apart', $link);
        }
        return self::yes([
            'productId' => (int)$product['id'],
            'label'     => (string)$product['name'],
            'price'     => $price,
            'quantity'  => 1,
            'unit'      => 'month',
        ], $link);
    }

    /**
     * 5.18.102 (§R.3.1 rules 4, 5, 7 and 8, §R.3.2): a kit's or the installation's product, by id — at use ($atUse,
     * $link the record in force) or for a candidate link at save. Rule 7 over the unfiltered service-plan list; rule 8
     * through the judge's one method, with the quoting organization handed on (F-4).
     */
    private function hardwareRules(string $item, ?array $row, string $shown, int $pid, ?int $link, bool $atUse, int $qty): array
    {
        $product = $this->listed('products', $pid);
        if ($product === null) return self::no($this->unlisted('products', $pid, $shown, 'product', $atUse), 'unlisted', $link);
        $bad = $this->badProduct($product, $shown);
        if ($bad !== '') return self::no($bad, 'bad', $link);
        if ($this->likePlan($product)) {
            return self::no("{$shown} is spelled like a service plan in uCRM, so it cannot be quoted as hardware", 'like_plan', $link);
        }
        $clash = $this->links->rule8((int)$product['id'], $this->orgId());
        // 5.18.102 D14 review (D14-R8-INACTIVE-KIT; round 2, FX-money-1): a kit is refused the installation's product
        // whatever the row's offered state, at save as at use (UcrmQuoteLinks::set() checks the same inside the lock), so a
        // kit row deactivated with its link left in force never prices beside the installation, and an inactive row cannot
        // be linked to it; an inactive row's refusal names no deactivation. Never blocks the installation: rule8()'s kits
        // are the offered ones, at save and at use alike
        if ($item === 'kit' && $clash['install']) {
            return self::no($atUse
                ? "{$shown} and the installation are linked to the same uCRM product — an admin links one of them again"
                : UcrmQuoteLinks::rule8Refusal('kit', (string)($product['name'] ?? ''), (int)$product['id'], (string)(($row ?? [])['title'] ?? ''), (int)(($row ?? [])['id'] ?? 0), UcrmQuoteLinks::offeredKit($row ?? [])),
                'rule8', $link);
        }
        if ($item === 'install' && $clash['kits'] !== []) {
            $kit = $clash['kits'][0];
            $kitTitle = (string)($kit['title'] ?? '');
            return self::no($atUse
                ? QuoteOrganization::plain($kitTitle !== '' ? $kitTitle : 'A hardware item') . ' and the installation are linked to the same uCRM product — an admin links one of them again'
                : UcrmQuoteLinks::rule8Refusal('install', (string)($product['name'] ?? ''), (int)$product['id'], $kitTitle, (int)($kit['id'] ?? 0)),
                'rule8', $link);
        }
        return self::yes(self::line($product, max(1, $qty), $item === 'install' ? 'job' : 'piece'), $link);
    }

    /**
     * 5.18.102 (§R.3.1 rule 6, the service plan's own part): counted; fewer than 200 service plans in all (6c); no other
     * listed service plan keyed like it (6a); exactly one enabled 1-month price; planPrice equal to it (6b).
     *
     * @return array{reason:string,why:string,plan:?array,key:string,month:?float}
     */
    private function servicePlanRules(int $spid, string $shown, bool $atUse): array
    {
        $cat = $this->catalogue();
        $out = ['reason' => '', 'why' => '', 'plan' => null, 'key' => '', 'month' => null];
        if ((int)$cat['plan_rows'] >= self::SUMMARY_PLAN_LIMIT) {
            return ['reason' => 'uCRM lists 200 service plans or more, more than the quotation\'s WhatsApp summary reads — an admin removes unused service plans in uCRM', 'why' => 'plan_rows'] + $out;
        }
        $plan = $this->listed('plans', $spid);
        if ($plan === null) return ['reason' => $this->unlisted('plans', $spid, $shown, 'service plan', $atUse), 'why' => 'unlisted'] + $out;
        $key = PublicPriceFeed::planKey((string)($plan['name'] ?? ''));
        if ($key !== '' && (int)($cat['plan_keys'][$key] ?? 0) !== 1) {
            return ['reason' => "{$shown}'s uCRM service plan is spelled like another service plan in uCRM (active or not), so the quotation's WhatsApp summary could show the other one's monthly price — an admin renames one in uCRM", 'why' => 'plan_twin'] + $out;
        }
        $month = self::monthPrice($plan);
        if ($month === null) return ['reason' => "{$shown} has no 1-month price in uCRM", 'why' => 'no_month'] + $out;
        $summaryPrice = PublicPriceFeed::planPrice($plan);
        if ($summaryPrice === null || abs(round($summaryPrice, 2) - $month) > 0.0001) {
            return ['reason' => "{$shown}'s uCRM service plan carries a price that is not its 1-month price, so the quotation's WhatsApp summary could show the wrong monthly price — an admin fixes the service plan in uCRM", 'why' => 'plan_price'] + $out;
        }
        return ['reason' => '', 'why' => '', 'plan' => $plan, 'key' => $key, 'month' => $month];
    }

    /**
     * 5.18.102 (D14, docs/71 §R.2.3): why the picker lists a service plan disabled — '' when it may be picked. The
     * service plan's own part of rule 6, the very function a link is judged by; $shown names it in the reason.
     */
    public function servicePlanReason(int $servicePlanId, string $shown): string
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return (string)$cat['reason'];
        return $this->servicePlanRules($servicePlanId, QuoteOrganization::plain($shown), false)['reason'];
    }

    /**
     * 5.18.102 (D14, docs/71 §R.2.3): why the picker lists a product disabled for an item — '' when it may be picked.
     * For a plan, rules 4 and 5 (whether it pairs with a service plan is the save's question, candidate()); for a kit or
     * the installation, rules 4, 5, 7 and 8, through candidate(). $shown names the product in the reason.
     */
    public function productReason(string $item, ?array $row, int $productId, string $shown): string
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return (string)$cat['reason'];
        $who = QuoteOrganization::plain($shown);
        if ($item !== 'plan') return $this->candidate($item, $row, $productId, null, $who)['reason'];
        $product = $this->listed('products', $productId);
        if ($product === null) return $this->unlisted('products', $productId, $who, 'product', false);
        return $this->badProduct($product, $who);
    }

    /** 5.18.102: the plugin row's own name, fit for a reason — the name the admin finds on the Plans or Hardware screen. */
    private static function rowShown(string $item, ?array $row): string
    {
        $name = (string)(($row ?? [])[$item === 'plan' ? 'name' : 'title'] ?? '');
        return QuoteOrganization::plain($name !== '' ? $name : ($item === 'plan' ? 'The plan' : 'A hardware item'));
    }

    // ── a whole sale ───────────────────────────────────────────────────────

    /** Does this sale ask "Who installs?" (docs/71 §E.1): Starlink, other than Shifting Connection and Ownership Change. */
    public static function needsInstallAnswer(string $customerType, string $connectivity): bool
    {
        return strcasecmp(trim($customerType), 'StarLink') === 0
            && !in_array(trim($connectivity), self::NO_INSTALL_CONNECTIONS, true);
    }

    /**
     * Price one sale.
     *
     * @param array $sale package_choice, device_id, hw_cart (array of {id, qty}), kitQty, customer_type,
     *                    connectivity_type, install_by ('dishnet' | 'customer' | anything else = not answered),
     *                    entry ('web' only from this release's web KYC form; anything else, or none, is 'app')
     * @param mixed $store the plugin's store (subscription_plans.json, kyc_packages.json, kyc_devices.json)
     * @return array{status:string,reason:string,lines:array,total:?float,offer:?array,device:?array,
     *               plan_price:?float,device_price:?float,plan_label:?string,entry:string,link_ids:array,unlinked:bool}
     */
    public function resolve(array $sale, $store): array
    {
        $packageId = (int)($sale['package_choice'] ?? 0);
        $kind  = 'plan';
        $offer = $packageId > 0 ? $store->findOne('subscription_plans.json', 'id', $packageId) : null;
        if (!$offer && $packageId > 0) {
            $offer = $store->findOne('kyc_packages.json', 'id', $packageId);
            $kind  = 'package';   // 5.18.102 (D14, §R.4.2): the item kind is part of the key — never a plan link
        }
        $device = null;
        $deviceId = (int)($sale['device_id'] ?? 0);
        if ($deviceId > 0) $device = $store->findOne('kyc_devices.json', 'id', $deviceId) ?: null;

        // 5.18.102 (D14, §R.4.2): the entry point, read once — 'web' only from this release's web KYC form.
        $entry = ($sale['entry'] ?? null) === 'web' ? 'web' : 'app';
        $out = ['status' => 'no_plan', 'reason' => '', 'lines' => [], 'total' => null,
                'offer' => $offer ?: null, 'device' => $device, 'plan_price' => null, 'device_price' => null,
                'plan_label' => null, 'entry' => $entry, 'link_ids' => [], 'unlinked' => false];
        if (!$offer) return $out;

        $out['status'] = 'unpriced';
        // 5.18.102 (D14, §R.4.2, E-1): the entry rule, right after the lookups — a sale that is not 'web' (the retailer
        // app, the mobile API, a form opened before the update, an application saved before it) is never priced.
        if ($entry !== 'web') { $out['reason'] = self::ENTRY_REASON; return $out; }
        $type = (string)($sale['customer_type'] ?? '');
        if (strcasecmp(trim($type), 'Fiber') === 0) {
            $out['reason'] = self::FIBER_REASON;
            return $out;
        }
        $cat = $this->catalogue();
        if (!$cat['ok']) { $out['reason'] = $cat['reason']; return $out; }

        $lines = [];
        $links = [];
        $plan = $this->planLine($offer, $kind);
        if (!$plan['ok']) { $out['reason'] = $plan['reason']; $out['unlinked'] = $plan['unlinked']; return $out; }
        $lines[] = $plan['line'];
        $links[] = $plan['link'];
        $out['plan_price'] = $plan['line']['price'];
        $out['plan_label'] = $plan['line']['label'];

        $cart = is_array($sale['hw_cart'] ?? null) ? $sale['hw_cart'] : [];
        // 5.18.102 (D14, §R.4.4): each kit, and the installation, by its link; every line's record id goes to $links.
        if ($cart !== []) {
            foreach ($cart as $item) {
                $id  = is_array($item) ? (int)($item['id'] ?? 0) : 0;
                $row = $id > 0 ? $store->findOne('kyc_devices.json', 'id', $id) : null;
                if (!$row) { $out['reason'] = 'an item in the cart is not in the plugin\'s hardware list'; return $out; }
                $kit = $this->kitLine($row, max(1, (int)($item['qty'] ?? 1)));
                if (!$kit['ok']) { $out['reason'] = $kit['reason']; $out['unlinked'] = $kit['unlinked']; return $out; }
                $lines[] = $kit['line'];
                $links[] = $kit['link'];
                if ($out['device_price'] === null) $out['device_price'] = $kit['line']['price'];
            }
        } elseif ($device) {
            $kit = $this->kitLine($device, max(1, (int)($sale['kitQty'] ?? 1)));
            if (!$kit['ok']) { $out['reason'] = $kit['reason']; $out['unlinked'] = $kit['unlinked']; return $out; }
            $lines[] = $kit['line'];
            $links[] = $kit['link'];
            $out['device_price'] = $kit['line']['price'];
        }

        if (self::needsInstallAnswer($type, (string)($sale['connectivity_type'] ?? ''))) {
            $by = (string)($sale['install_by'] ?? '');
            if ($by !== 'dishnet' && $by !== 'customer') {
                $out['reason'] = self::NO_INSTALL_ANSWER;
                return $out;
            }
            if ($by === 'dishnet') {
                $inst = $this->installLine();
                if (!$inst['ok']) { $out['reason'] = $inst['reason']; $out['unlinked'] = $inst['unlinked']; return $out; }
                $lines[] = $inst['line'];
                $links[] = $inst['link'];
            }
        }

        $out['status']   = 'priced';
        $out['lines']    = $lines;
        $out['total']    = self::total($lines);
        $out['link_ids'] = self::linkIds($links);   // 5.18.102 (§R.4.4): what linesRecord stores as quote_link_ids
        return $out;
    }

    /** 5.18.102 (D14, §R.4.4, §R.4.7): a set of link record ids — positive ints, unique, sorted ascending. */
    public static function linkIds($ids): array
    {
        $out = [];
        foreach (is_array($ids) ? $ids : [] as $v) {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int)$v > 0) $out[(int)$v] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * For a retry of stored lines (docs/71 §F.4, §R.4.4): '' when the lines may be quoted as stored, else the reason.
     * 5.18.102 (D14): each record of $linkIds is judged the way a link is judged at use — still the one in force for its
     * item, and its item still resolving now — and each stored line must be one of those links' products, at the price
     * stored (2 dp). A reason that says a price changed says it in words, never with the figure. Marked lines without
     * quote_link_ids are refused the same way. Reads the catalogue fresh through this instance.
     */
    public function confirmLines(array $lines, $linkIds, $store): string
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) return $cat['reason'];
        $labels = [];
        foreach ($lines as $l) {
            $lbl = is_array($l) && is_scalar($l['label'] ?? null) ? (string)$l['label'] : '';
            $labels[is_array($l) ? (int)($l['productId'] ?? 0) : 0] = QuoteOrganization::plain($lbl !== '' ? $lbl : 'An item');
        }
        $first = $labels !== [] ? (string)reset($labels) : 'An item';
        $changed = function (string $label): string {
            return "the uCRM link of {$label} was changed after this application was saved";
        };
        $repriced = function (string $label): string {
            return "{$label}'s price in uCRM changed after this application was saved";
        };

        // 5.18.102 (D14, §R.4.4): the stored set is a non-empty list of record ids, every one well formed.
        if (!is_array($linkIds) || $linkIds === []) return $changed($first);
        foreach ($linkIds as $v) {
            if (!((is_int($v) || (is_string($v) && ctype_digit($v))) && (int)$v > 0)) return $changed($first);
        }
        $current = [];   // product id → the line its link resolves to now
        foreach (self::linkIds($linkIds) as $id) {
            $rec   = $this->links->record($id);
            $pid   = is_array($rec) ? (int)($rec['product_id'] ?? 0) : 0;
            $label = $labels[$pid] ?? $first;
            if ($rec === null || ($rec['ended_at_ts'] ?? null) !== null) return $changed($label);
            $item  = (string)($rec['item'] ?? '');
            $rowId = (int)($rec['row_id'] ?? 0);
            $ans   = null;
            if ($item === 'plan') {
                $row = $store->findOne('subscription_plans.json', 'id', $rowId);
                if (is_array($row)) $ans = $this->planLine($row);
            } elseif ($item === 'kit') {
                $row = $store->findOne('kyc_devices.json', 'id', $rowId);
                if (is_array($row)) $ans = $this->kitLine($row);
            } elseif ($item === 'install') {
                $ans = $this->installLine();
            }
            if ($ans === null || $ans['link'] !== $id) {
                return ($ans !== null && $ans['why'] === 'fiber') ? $ans['reason'] : $changed($label);
            }
            if (!$ans['ok']) return $ans['why'] === 'price_differs' ? $repriced($label) : $ans['reason'];
            $current[(int)$ans['line']['productId']] = $ans['line'];
        }
        foreach ($lines as $l) {
            $pid   = is_array($l) ? (int)($l['productId'] ?? 0) : 0;
            $label = $labels[$pid] ?? $first;
            if ($pid <= 0 || !isset($current[$pid])) return $changed($label);
            if (abs(round((float)$current[$pid]['price'], 2) - round((float)($l['price'] ?? 0), 2)) > 0.0001) return $repriced($label);
        }
        return '';
    }

    /** Σ price × quantity, rounded to 2 dp: the one total every place uses. */
    public static function total(array $lines): float
    {
        $t = 0.0;
        foreach ($lines as $l) {
            if (!is_array($l)) continue;
            $t += round((float)($l['price'] ?? 0), 2) * max(1, (int)($l['quantity'] ?? 1));
        }
        return round($t, 2);
    }

    /**
     * Is a posted total a plain decimal (digits and one optional '.', no separators) equal to $total at 2 dp?
     * Missing → false (docs/71 §G, D10).
     */
    public static function postedTotalMatches($posted, float $total): bool
    {
        if (!is_string($posted) && !is_int($posted) && !is_float($posted)) return false;
        $s = trim((string)$posted);
        if ($s === '' || !preg_match('/^[0-9]+(\.[0-9]+)?$/', $s)) return false;
        return abs(round((float)$s, 2) - round($total, 2)) < 0.0001;
    }

    /**
     * 5.18.102 (D14, §R.4.7): do the link records the form posted (ucrm_links_seen: record ids, comma-joined) equal the
     * set this sale resolved through? Missing or malformed → false.
     */
    public static function seenLinksMatch($posted, array $linkIds): bool
    {
        if (!is_string($posted)) return false;
        $s = trim($posted);
        if ($s === '' || !preg_match('/^[0-9]+(,[0-9]+)*$/', $s)) return false;
        $seen = self::linkIds(explode(',', $s));
        return $seen !== [] && $seen === self::linkIds($linkIds);
    }

    // ── 5.18.103 (Uganda, root docs/72 §G): the catalogue straight from uCRM ("direct mode") ────────────────

    /**
     * 5.18.103 (§G.4): a line's token — the first 16 hex of sha256 over its productId, label, price in integer cents and
     * unit. Computed here only: drawn into the page beside the item, posted back (ucrm_items_seen), compared as a set.
     */
    public static function token(array $line): string
    {
        $cents = (string)(int)round(((float)($line['price'] ?? 0)) * 100);
        return substr(hash('sha256', (int)($line['productId'] ?? 0) . '|' . (string)($line['label'] ?? '') . '|' . $cents
            . '|' . (string)($line['unit'] ?? '')), 0, 16);
    }

    /** 5.18.103 (§G.6): do the posted tokens (comma-joined, 16 hex each) equal $tokens, as sets? Missing, malformed → false. */
    public static function seenItemsMatch($posted, array $tokens): bool
    {
        if (!is_string($posted)) return false;
        $s = trim($posted);
        if ($s === '' || !preg_match('/^[0-9a-f]{16}(,[0-9a-f]{16})*$/', $s)) return false;
        $seen = array_values(array_unique(explode(',', $s)));
        $want = array_values(array_unique(array_map('strval', $tokens)));
        sort($seen);
        sort($want);
        return $want !== [] && $seen === $want;
    }

    /** 5.18.103 (§G.6): the plan pair a direct form posts (ucrm_plan, "<servicePlanId>:<productId>"), or null. */
    public static function parsePlan($v): ?array
    {
        if (!is_string($v) || !preg_match('/^([1-9][0-9]{0,8}):([1-9][0-9]{0,8})$/', trim($v), $m)) return null;
        return ['sp' => (int)$m[1], 'p' => (int)$m[2]];
    }

    /**
     * 5.18.103 (§G.6): the cart a direct form posts (ucrm_cart_json, [{"p":id,"q":n}]): at most MAX_CART entries, each two
     * positive integers, q 1–MAX_QTY; a product posted twice is merged, its quantities added (still at most MAX_QTY). []
     * for no hardware; null for anything that cannot be read or breaks a limit — the whole cart, never part of it.
     */
    public static function parseCart($v): ?array
    {
        if ($v === null || $v === '') return [];
        if (is_string($v)) {
            $v = json_decode($v, true);
            if (!is_array($v)) return null;
        }
        if (!is_array($v)) return null;
        if ($v === []) return [];
        if (array_keys($v) !== range(0, count($v) - 1) || count($v) > self::MAX_CART) return null;
        $qty = [];
        foreach ($v as $e) {
            if (!is_array($e)) return null;
            $p = $e['p'] ?? null;
            $q = $e['q'] ?? null;
            if (is_string($p) && ctype_digit($p)) $p = (int)$p;
            if (is_string($q) && ctype_digit($q)) $q = (int)$q;
            if (!is_int($p) || $p <= 0 || $p > 999999999 || !is_int($q) || $q < 1 || $q > self::MAX_QTY) return null;
            $qty[$p] = ($qty[$p] ?? 0) + $q;
            if ($qty[$p] > self::MAX_QTY) return null;
        }
        $out = [];
        foreach ($qty as $p => $q) $out[] = ['p' => (int)$p, 'q' => (int)$q];
        return $out;
    }

    /**
     * 5.18.103 (§G.7, §G.8): the uCRM ids a direct application stores (quote_item_ids), checked — {plan:{sp,p}, hw:[{p,q}],
     * install: id|null} — or null when they cannot be read.
     */
    public static function itemIds($v): ?array
    {
        if (!is_array($v)) return null;
        $plan = $v['plan'] ?? null;
        if (!is_array($plan) || !is_int($plan['sp'] ?? null) || !is_int($plan['p'] ?? null) || $plan['sp'] <= 0 || $plan['p'] <= 0) return null;
        $hw = self::parseCart($v['hw'] ?? []);
        if ($hw === null || ($hw !== [] && $hw != ($v['hw'] ?? null))) return null;
        $inst = $v['install'] ?? null;
        if ($inst !== null && (!is_int($inst) || $inst <= 0)) return null;
        return ['plan' => ['sp' => $plan['sp'], 'p' => $plan['p']], 'hw' => $hw, 'install' => $inst];
    }

    /**
     * 5.18.103 (§G.5): the last catalogue read that succeeded, whatever its age, kept beside the page cache — drawn, marked
     * out of date and with Cash disabled, only when the page's own read fails. Never priced from: the submission reads
     * uCRM again. null when there is none.
     */
    public static function lastGood($crm, array $setting, string $dataDir, UcrmQuoteLinks $links): ?self
    {
        $raw = @file_get_contents(rtrim($dataDir, '/') . '/' . self::LAST_GOOD_FILE);
        if (!is_string($raw)) return null;
        $c = json_decode($raw, true);
        return self::cacheShaped($c) ? new self($crm, $setting, $links, $c) : null;
    }

    /**
     * 5.18.103 (§G.5): the resolver a direct-mode page draws from — forPage()'s (the page timeouts, the 60-second cache),
     * or, when that read fails, the last good copy, marked stale (drawn out of date, Cash disabled, never priced from).
     * Direct mode reads no link: the judge it holds is empty. $pluginCrm is the plugin's own client (quoteClient() picks
     * the admin token when one is set).
     *
     * @return array{uql:self,stale:bool}
     */
    public static function forDirectPage($pluginCrm, array $cfg, array $setting, string $dataDir): array
    {
        $links  = UcrmQuoteLinks::fromRecords([], []);
        $client = self::quoteClient($pluginCrm, $cfg);
        $uql    = self::forPage($client, $setting, $dataDir, $links);
        if (!empty($uql->catalogue()['ok'])) return ['uql' => $uql, 'stale' => false];
        $lg = self::lastGood($client, $setting, $dataDir, $links);
        return $lg !== null ? ['uql' => $lg, 'stale' => true] : ['uql' => $uql, 'stale' => false];
    }

    /**
     * 5.18.103 (§G.6, §G.9): a resolver that reads uCRM fresh, for a submission, a send or a save — never a cache.
     */
    public static function forDirectUse($pluginCrm, array $cfg, array $setting): self
    {
        return new self(self::quoteClient($pluginCrm, $cfg), $setting, UcrmQuoteLinks::fromRecords([], []));
    }

    /**
     * 5.18.103 (§G.4): what the forms offer, from one catalogue read — the plan pairs (G-4), the hardware (G-5), the
     * installation (G-6) — and every service plan and product left out, with its reason and a fixed why code, for the
     * uCRM catalogue screen and the deploy's count.
     *
     * @param int|null $installId the installation product in force (UcrmCatalogue::installProductId()), or null
     * @param int[]    $keptOff   the products an admin keeps off the forms (UcrmCatalogue::hiddenIds())
     * @return array{ok:bool,reason:string,plans:array,hardware:array,install:array,not_offered:array}
     */
    public function offered(?int $installId, array $keptOff): array
    {
        $cat = $this->catalogue();
        if (!$cat['ok']) {
            return ['ok' => false, 'reason' => $cat['reason'], 'plans' => [], 'hardware' => [],
                    'install' => ['ok' => false, 'reason' => $cat['reason'], 'why' => 'unreadable', 'line' => null], 'not_offered' => []];
        }
        $prev = $this->directWords;
        $this->directWords = true;
        try {
            $off = [];
            foreach ($keptOff as $k) if (is_int($k) && $k > 0) $off[$k] = true;
            $notOffered = [];
            $plans = [];
            $planProducts = [];
            foreach ($cat['plans'] as $sp) {
                $spid = (int)($sp['id'] ?? 0);
                if ($spid <= 0) continue;
                $pair = $this->directPair($spid, null);
                if (!$pair['ok']) {
                    $notOffered[] = ['kind' => 'plan', 'id' => $spid, 'name' => (string)($sp['name'] ?? ''), 'reason' => $pair['reason'], 'why' => $pair['why']];
                    continue;
                }
                $pid = (int)$pair['line']['productId'];
                $planProducts[$pid] = true;
                $plans[] = $pair['line'] + ['sp' => $spid, 'p' => $pid, 'name' => (string)($sp['name'] ?? ''),
                    'speed' => (string)($sp['downloadSpeed'] ?? $sp['download_speed'] ?? ''), 'key' => $pair['key'],
                    'token' => self::token($pair['line'])];
            }
            $hardware = [];
            $accessory = self::accessoryKeys();
            foreach ($cat['products'] as $prod) {
                $pid = (int)($prod['id'] ?? 0);
                if ($pid <= 0 || isset($planProducts[$pid]) || ($installId !== null && $pid === $installId)) continue;
                $name = (string)($prod['name'] ?? '');
                $it = $this->directItem($pid, 1);
                if (!$it['ok']) {
                    $notOffered[] = ['kind' => 'product', 'id' => $pid, 'name' => $name, 'reason' => $it['reason'], 'why' => $it['why']];
                    continue;
                }
                if (isset($off[$pid])) {
                    $notOffered[] = ['kind' => 'product', 'id' => $pid, 'name' => $name,
                        'reason' => QuoteOrganization::plain($name) . ' is kept off the forms by an admin', 'why' => 'kept_off'];
                    continue;
                }
                $group = (class_exists('ShopCatalogue') && isset($accessory[ShopCatalogue::nameKey($name)])) ? 'accessory' : 'kit';
                $hardware[] = $it['line'] + ['p' => $pid, 'group' => $group, 'token' => self::token($it['line'])];
            }
            foreach (['plans' => 'plan', 'products' => 'product'] as $kind => $one) {
                foreach ((array)($cat['excluded'][$kind] ?? []) as $id => $why) {
                    $name = (string)($cat['excluded_names'][$kind][$id] ?? '');
                    $notOffered[] = ['kind' => $one, 'id' => (int)$id, 'name' => $name,
                        'reason' => $this->unlisted($kind, (int)$id, $this->directShown($kind, (int)$id), $one === 'plan' ? 'service plan' : 'product'),
                        'why' => (string)$why];
                }
            }
            $byPrice = function (array $a, array $b): int {
                return [$a['price'], $a['label']] <=> [$b['price'], $b['label']];
            };
            usort($plans, $byPrice);
            usort($hardware, function (array $a, array $b) use ($byPrice): int {
                return [$a['group'] === 'kit' ? 0 : 1] <=> [$b['group'] === 'kit' ? 0 : 1] ?: $byPrice($a, $b);
            });
            $inst = $this->directInstall($installId);
            return ['ok' => true, 'reason' => '', 'plans' => $plans, 'hardware' => $hardware,
                    'install' => $inst + ['p' => $inst['ok'] ? (int)$inst['line']['productId'] : null,
                                          'token' => $inst['ok'] ? self::token($inst['line']) : null],
                    'not_offered' => $notOffered];
        } finally {
            $this->directWords = $prev;
        }
    }

    /**
     * 5.18.103 (§G.6): price one direct sale — the shape resolve() answers, link_ids always [], plus item_ids (what the
     * application stores, priced or not), tokens (every line's) and why (the reason's fixed code). Every item must be
     * offered now (G-4, G-5: not kept off, not the installation), the installation set and passing when DishNet installs.
     *
     * @param array $sale saleFromPost()/saleFromApp(): direct_plan {sp,p}|null, direct_cart [{p,q}]|null, customer_type,
     *                    connectivity_type, install_by, entry ('web-direct' only from the direct form, the switch on)
     * @param bool  $applyKeptOff false only where a sale already made is re-read and kept-off must not apply (never at
     *                    submission, never at the retry of an unpriced sale — docs/72 §G.8)
     */
    public function resolveDirect(array $sale, ?int $installId, array $keptOff, bool $applyKeptOff = true): array
    {
        $plan  = is_array($sale['direct_plan'] ?? null) ? $sale['direct_plan'] : null;
        $cart  = array_key_exists('direct_cart', $sale) ? $sale['direct_cart'] : [];
        $entry = ($sale['entry'] ?? null) === self::DIRECT_ENTRY ? self::DIRECT_ENTRY : 'app';
        $out = ['status' => 'no_plan', 'reason' => '', 'why' => '', 'lines' => [], 'total' => null, 'offer' => null,
                'device' => null, 'plan_price' => null, 'device_price' => null, 'plan_label' => null, 'entry' => $entry,
                'link_ids' => [], 'unlinked' => false, 'item_ids' => null, 'tokens' => [], 'cart_rows' => []];
        if ($plan === null || !is_int($plan['sp'] ?? null) || !is_int($plan['p'] ?? null)) return $out;
        if ($cart === null || !is_array($cart)) {
            $out['status'] = 'invalid';
            $out['reason'] = self::text('DIRECT_CART');
            $out['why']    = 'cart';
            return $out;
        }
        $out['item_ids'] = ['plan' => ['sp' => $plan['sp'], 'p' => $plan['p']], 'hw' => array_values($cart), 'install' => null];
        $out['status']   = 'unpriced';
        // the cart the application keeps (KycService::directCartJson, G-7): names and prices once uCRM is read, else none
        foreach ($cart as $c) $out['cart_rows'][] = ['title' => '', 'price' => null, 'qty' => (int)$c['q']];
        $type = (string)($sale['customer_type'] ?? '');
        if ($entry !== self::DIRECT_ENTRY) return self::directNo($out, self::ENTRY_REASON, 'entry');
        if (strcasecmp(trim($type), 'Fiber') === 0) return self::directNo($out, self::FIBER_REASON, 'fiber');
        $cat = $this->catalogue();
        if (!$cat['ok']) return self::directNo($out, $cat['reason'], 'unreadable');

        // The names the application keeps (offer_name, device_title, hw_cart_json, G-7), read whether or not it prices.
        $out['offer'] = ['name' => $this->directName('products', $plan['p'])];
        foreach ($cart as $i => $c) {
            $row = $this->listed('products', (int)$c['p']);
            $out['cart_rows'][$i] = ['title' => $this->directName('products', (int)$c['p']),
                'price' => $row !== null && is_numeric($row['price'] ?? null) ? round((float)$row['price'], 2) : null, 'qty' => (int)$c['q']];
        }
        if ($cart !== []) {
            $first = $this->listed('products', (int)$cart[0]['p']);
            $out['device'] = ['title' => $this->directName('products', (int)$cart[0]['p']),
                              'price' => $first !== null && is_numeric($first['price'] ?? null) ? round((float)$first['price'], 2) : null];
        }

        $off = $this->offered($installId, $applyKeptOff ? $keptOff : []);
        $prev = $this->directWords;
        $this->directWords = true;
        try {
            $lines = [];
            $pl = null;
            foreach ($off['plans'] as $row) {
                if ($row['sp'] === $plan['sp'] && $row['p'] === $plan['p']) { $pl = $row; break; }
            }
            if ($pl === null) {
                $pair = $this->directPair($plan['sp'], $plan['p']);
                return self::directNo($out, $pair['ok'] ? $this->directShown('plans', $plan['sp']) . ' is not offered on the forms' : $pair['reason'],
                    $pair['ok'] ? 'not_offered' : $pair['why']);
            }
            $lines[] = self::lineOf($pl);
            $out['plan_price'] = $pl['price'];
            $out['plan_label'] = $pl['label'];
            foreach ($cart as $c) {
                $p = (int)$c['p'];
                $hw = null;
                foreach ($off['hardware'] as $row) if ($row['p'] === $p) { $hw = $row; break; }
                if ($hw === null) {
                    $shown = $this->directShown('products', $p);
                    if ($installId !== null && $p === $installId) return self::directNo($out, "{$shown} is the installation product, so it cannot be sold as hardware", 'install_product');
                    $it = $this->directItem($p, 1);
                    if (!$it['ok']) return self::directNo($out, $it['reason'], $it['why']);
                    if ($applyKeptOff && in_array($p, $keptOff, true)) return self::directNo($out, "{$shown} is kept off the forms by an admin", 'kept_off');
                    return self::directNo($out, "{$shown} is not offered on the forms", 'not_offered');
                }
                $line = self::lineOf($hw);
                $line['quantity'] = (int)$c['q'];
                $lines[] = $line;
                if ($out['device_price'] === null) $out['device_price'] = $hw['price'];
            }
            if (self::needsInstallAnswer($type, (string)($sale['connectivity_type'] ?? ''))) {
                $by = (string)($sale['install_by'] ?? '');
                if ($by !== 'dishnet' && $by !== 'customer') return self::directNo($out, self::NO_INSTALL_ANSWER, 'no_install_answer');
                if ($by === 'dishnet') {
                    if (empty($off['install']['ok'])) return self::directNo($out, (string)$off['install']['reason'], (string)$off['install']['why']);
                    $lines[] = $off['install']['line'];
                    $out['item_ids']['install'] = (int)$off['install']['line']['productId'];
                }
            }
        } finally {
            $this->directWords = $prev;
        }
        $out['status'] = 'priced';
        $out['lines']  = $lines;
        $out['total']  = self::total($lines);
        $out['tokens'] = array_values(array_map([self::class, 'token'], $lines));
        return $out;
    }

    /**
     * 5.18.103 (§G.8): for a retry of a direct application's stored lines — '' when they may be quoted as stored, else the
     * reason. Each stored id is judged again by the rules (the plan pair as G-4, each product as G-5 but without
     * "kept off" and without the installation in force, the installation product as stored), and every stored line must
     * be its item's line now in product id and price (2 dp). A price that changed is said in words, never with the figure.
     */
    public function confirmDirect(array $lines, $itemIds): string
    {
        $ids = self::itemIds($itemIds);
        if ($ids === null) return 'the uCRM items this application was saved with could not be read';
        $cat = $this->catalogue();
        if (!$cat['ok']) return $cat['reason'];
        $labels = [];
        foreach ($lines as $l) {
            $lbl = is_array($l) && is_scalar($l['label'] ?? null) ? (string)$l['label'] : '';
            $labels[is_array($l) ? (int)($l['productId'] ?? 0) : 0] = QuoteOrganization::plain($lbl !== '' ? $lbl : 'An item');
        }
        $repriced = function (string $label): string {
            return "{$label}'s price in uCRM changed after this application was saved";
        };
        $prev = $this->directWords;
        $this->directWords = true;
        try {
            $current = [];
            $pair = $this->directPair($ids['plan']['sp'], $ids['plan']['p']);
            if (!$pair['ok']) return $pair['why'] === 'price_differs' ? $repriced($labels[$ids['plan']['p']] ?? 'The plan') : $pair['reason'];
            $current[(int)$pair['line']['productId']] = $pair['line'];
            foreach ($ids['hw'] as $h) {
                $it = $this->directItem((int)$h['p'], (int)$h['q']);
                if (!$it['ok']) return $it['reason'];
                $current[(int)$h['p']] = $it['line'];
            }
            if ($ids['install'] !== null) {
                $in = $this->directInstall($ids['install']);
                if (!$in['ok']) return $in['reason'];
                $current[(int)$ids['install']] = $in['line'];
            }
        } finally {
            $this->directWords = $prev;
        }
        $changed = 'the items of this application changed after it was saved';
        if (count($lines) !== count($current)) return $changed;
        $seen = [];
        foreach ($lines as $l) {
            $pid = is_array($l) ? (int)($l['productId'] ?? 0) : 0;
            if ($pid <= 0 || !isset($current[$pid]) || isset($seen[$pid])) return $changed;
            $seen[$pid] = true;
            if (abs(round((float)$current[$pid]['price'], 2) - round((float)($l['price'] ?? 0), 2)) > 0.0001) return $repriced($labels[$pid] ?? 'An item');
        }
        return '';
    }

    /**
     * 5.18.103 (§G G-12): a standard quotation (UcrmCatalogue's record) priced live against offered(): usable only when
     * its plan, every hardware item and — when it includes it — the installation are offered now; never a partial bundle.
     * One implementation for the KYC form, Send Quotation, the uCRM catalogue screen and the deploy's check.
     *
     * @return array{ok:bool,reason:string,lines:array,total:?float,tokens:array}
     */
    public static function standardFrom(array $std, array $off): array
    {
        $no = function (string $r): array {
            return ['ok' => false, 'reason' => $r, 'lines' => [], 'total' => null, 'tokens' => []];
        };
        if (empty($off['ok'])) return $no((string)($off['reason'] ?? "uCRM's products could not be read"));
        $sp = (int)($std['plan']['sp'] ?? 0);
        $pp = (int)($std['plan']['p'] ?? 0);
        $lines = [];
        $plan = null;
        foreach ((array)($off['plans'] ?? []) as $row) {
            if ((int)$row['sp'] === $sp && (int)$row['p'] === $pp) { $plan = $row; break; }
        }
        if ($plan === null) return $no('its plan is not offered now — see the uCRM catalogue screen');
        $lines[] = self::lineOf($plan);
        foreach ((array)($std['hw'] ?? []) as $h) {
            $hit = null;
            foreach ((array)($off['hardware'] ?? []) as $row) {
                if ((int)$row['p'] === (int)($h['p'] ?? 0)) { $hit = $row; break; }
            }
            if ($hit === null) return $no('a hardware item in it (uCRM product #' . (int)($h['p'] ?? 0) . ') is not offered now — see the uCRM catalogue screen');
            $line = self::lineOf($hit);
            $line['quantity'] = max(1, (int)($h['q'] ?? 1));
            $lines[] = $line;
        }
        if (!empty($std['install'])) {
            if (empty($off['install']['ok'])) return $no((string)($off['install']['reason'] ?? 'the installation product is not usable'));
            $lines[] = $off['install']['line'];
        }
        return ['ok' => true, 'reason' => '', 'lines' => $lines, 'total' => self::total($lines),
                'tokens' => array_values(array_map([self::class, 'token'], $lines))];
    }

    /** 5.18.103: a plan pair by its service plan, and — when $p is given — that product (G-4). */
    private function directPair(int $spid, ?int $p): array
    {
        $cat   = $this->catalogue();
        $shown = $this->directShown('plans', $spid);
        $sp = $this->servicePlanRules($spid, $shown, true);
        if ($sp['reason'] !== '') return self::no($sp['reason'], $sp['why']) + ['key' => ''];
        $key = $sp['key'];
        if ($key === '') return self::no("{$shown} has no name a uCRM product could be spelled like", 'plan_no_product') + ['key' => ''];
        $cands = [];   // G-4: the counted products keyed like it, counted before any judging
        foreach ($cat['products'] as $prod) {
            if (is_array($prod) && PublicPriceFeed::planKey((string)($prod['name'] ?? '')) === $key) $cands[] = $prod;
        }
        if ($cands === []) return self::no("no uCRM product is spelled like the service plan {$shown}, so it cannot be a quotation line", 'plan_no_product') + ['key' => $key];
        if (count($cands) > 1) return self::no("two uCRM products are spelled like the service plan {$shown} — an admin removes or renames one in uCRM", 'plan_two_products') + ['key' => $key];
        $pid = (int)($cands[0]['id'] ?? 0);
        if ($p !== null && $pid !== $p) return self::no("the uCRM product of {$shown} changed since the form was opened", 'plan_product_changed') + ['key' => $key];
        return $this->planRules($shown, $pid, $spid, null, true) + ['key' => $key];
    }

    /** 5.18.103: a product as hardware (G-5) — counted, badProduct, not spelled like a plan; kept-off and the installation are the callers'. */
    private function directItem(int $pid, int $qty): array
    {
        $shown   = $this->directShown('products', $pid);
        $product = $this->listed('products', $pid);
        if ($product === null) return self::no($this->unlisted('products', $pid, $shown, 'product', true), 'unlisted');
        $bad = $this->badProduct($product, $shown);
        if ($bad !== '') return self::no($bad, 'bad');
        if ($this->likePlan($product)) return self::no("{$shown} is spelled like a service plan in uCRM, so it cannot be quoted as hardware", 'like_plan');
        return self::yes(self::line($product, max(1, $qty), 'piece'), null);
    }

    /** 5.18.103: the installation product (G-6) — set, counted, badProduct, not spelled like a plan. */
    private function directInstall(?int $pid): array
    {
        if ($pid === null || $pid <= 0) return self::no('the installation product is not set — an admin sets it on the uCRM catalogue screen', 'install_unset');
        $shown   = $this->directShown('products', $pid);
        $product = $this->listed('products', $pid);
        if ($product === null) return self::no('the installation product: ' . $this->unlisted('products', $pid, $shown, 'product', true), 'unlisted');
        $bad = $this->badProduct($product, $shown);
        if ($bad !== '') return self::no('the installation product: ' . $bad, 'bad');
        if ($this->likePlan($product)) return self::no("the installation product {$shown} is spelled like a service plan in uCRM", 'like_plan');
        return self::yes(self::line($product, 1, 'job'), null);
    }

    /** 5.18.103: a uCRM row's name fit for a reason — counted or filtered out — else "uCRM product #id". */
    private function directShown(string $kind, int $id): string
    {
        $n = $this->directName($kind, $id);
        return $n !== '' ? $n : 'uCRM ' . ($kind === 'plans' ? 'service plan' : 'product') . ' #' . $id;
    }

    /** 5.18.103: a uCRM row's name, through QuoteOrganization::plain — counted or filtered out — or ''. */
    private function directName(string $kind, int $id): string
    {
        $row = $this->listed($kind, $id);
        $n = $row !== null ? (string)($row['name'] ?? '') : (string)($this->catalogue()['excluded_names'][$kind][$id] ?? '');
        return QuoteOrganization::plain($n);
    }

    /** 5.18.103: an offered row back to the line shape every quote carries. */
    private static function lineOf(array $row): array
    {
        return ['productId' => (int)$row['productId'], 'label' => (string)$row['label'], 'price' => (float)$row['price'],
                'quantity' => (int)($row['quantity'] ?? 1), 'unit' => (string)$row['unit']];
    }

    /** 5.18.103: a direct sale left unpriced, with its reason and why code. */
    private static function directNo(array $out, string $reason, string $why): array
    {
        $out['status'] = 'unpriced';
        $out['reason'] = $reason;
        $out['why']    = $why;
        return $out;
    }

    /** 5.18.103: the website's accessory names (ShopCatalogue), for the kit / accessory grouping only — never a price. */
    private static function accessoryKeys(): array
    {
        try {
            if (!class_exists('ShopCatalogue')) require_once __DIR__ . '/ShopCatalogue.php';
            return ShopCatalogue::accessoryNameKeys(dirname(__DIR__));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 5.18.103 (§G.3): a page cache or last-good copy of this release's shape (age aside). */
    private static function cacheShaped($c): bool
    {
        return is_array($c) && !empty($c['ok']) && ($c['shape'] ?? null) === self::CACHE_SHAPE
            && is_array($c['products'] ?? null) && is_array($c['plans'] ?? null) && is_array($c['excluded'] ?? null)
            && is_array($c['excluded_names'] ?? null)
            && is_array($c['plan_keys'] ?? null) && is_array($c['plan_key_of'] ?? null) && is_int($c['plan_rows'] ?? null);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** 5.18.102 (F-4): the quoting organization's id this resolver hands the judge, on every call. */
    private function orgId(): ?int
    {
        $id = $this->setting['id'] ?? null;
        return is_int($id) ? $id : null;
    }

    /** 5.18.102: a counted product ('products') or service plan ('plans') by id, or null. */
    private function listed(string $kind, int $id): ?array
    {
        if ($id <= 0) return null;
        foreach ($this->catalogue()[$kind] as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null) && (int)$row['id'] === $id) return $row;
        }
        return null;
    }

    /**
     * 5.18.102 (§R.3.1 rule 4, §R.4.1): why a product or service plan is not counted — filtered out, or not listed. At use
     * it names the plugin row and the remedy; for a candidate at save, the uCRM id the admin picked.
     */
    private function unlisted(string $kind, int $id, string $shown, string $what, bool $atUse = true): string
    {
        $why   = (string)($this->catalogue()['excluded'][$kind][$id] ?? '');
        $words = ['archived' => 'archived', 'inactive' => 'not active', 'organization' => 'another organization\'s'];
        $state = $words[$why] ?? '';
        if ($this->directWords) {   // 5.18.103 (§G G-3): nothing to link in direct mode — the remedy is in uCRM
            return $state === '' ? "{$shown} is no longer listed in uCRM" : "{$shown} is {$state} in uCRM";
        }
        if (!$atUse) {
            return $state === '' ? "uCRM does not list {$what} #{$id} — nothing was linked"
                                 : "uCRM {$what} #{$id} is {$state} in uCRM — nothing was linked";
        }
        return $state === ''
            ? "{$shown} is linked to a uCRM {$what} that uCRM no longer lists — an admin links it again"
            : "{$shown} is linked to a uCRM {$what} that is {$state} in uCRM — an admin links it again";
    }

    /** 5.18.102 (§R.3.1 rule 7): is the product's name keyed like any listed service plan, counted or not? */
    private function likePlan(array $product): bool
    {
        $k = PublicPriceFeed::planKey((string)($product['name'] ?? ''));
        return $k !== '' && isset($this->catalogue()['plan_keys'][$k]);
    }

    /** The service plan's one enabled period-1 price at 2 dp, or null when it has none, or more than one. */
    private static function monthPrice(array $plan): ?float
    {
        $month = [];
        foreach ((array)($plan['periods'] ?? []) as $per) {
            if (is_array($per) && !empty($per['enabled']) && (int)($per['period'] ?? 0) === 1) $month[] = $per;
        }
        if (count($month) !== 1 || !isset($month[0]['price']) || !is_numeric($month[0]['price'])) return null;
        return round((float)$month[0]['price'], 2);
    }

    /** 5.18.102 (§R.4.1, E-4): the reason for a link that is not in force; "not linked" is marked for P1_UNLINKED. */
    private static function linkRefusal(string $state, string $shown, string $screen): array
    {
        if ($state === 'two') {
            return self::no("{$shown} has two uCRM links recorded — an admin links it again", 'two');
        }
        if ($state === 'replaced') {
            return self::no("{$shown} was linked before this plugin row was replaced — an admin links it again", 'replaced');
        }
        if ($state === 'organization') {
            return self::no("{$shown} was linked for another quoting organization — an admin links it again", 'organization');
        }
        $r = self::no("{$shown} is not linked to a uCRM product yet — an admin links it on the {$screen} screen", 'unlinked');
        $r['unlinked'] = true;
        return $r;
    }

    /** '' when the product may be quoted (docs/71 §D.4 "every line"), else the reason. */
    private function badProduct(array $p, string $shown): string
    {
        $price = $p['price'] ?? null;
        if (!is_int($price) && !is_float($price) && !(is_string($price) && is_numeric($price))) {
            return "{$shown} has no price in uCRM";
        }
        $f = (float)$price;
        if ($f <= 0) return "{$shown}'s price in uCRM is 0";
        if (abs($f * 100 - round($f * 100)) > 0.000001) return "{$shown}'s price in uCRM has more than 2 decimals";
        if (!empty($p['taxable']) || (array_key_exists('taxId', $p) && $p['taxId'] !== null)) {
            return "{$shown} is set as taxable in uCRM; the cash would not equal the quotation's total";
        }
        if ((int)($p['id'] ?? 0) <= 0) return "{$shown} has no product id in uCRM";
        $label = trim((string)($p['invoiceLabel'] ?? ''));
        if ($label !== '' && PublicPriceFeed::planKey($label) !== PublicPriceFeed::planKey((string)($p['name'] ?? ''))) {
            return "{$shown}'s invoice label in uCRM differs from its name";
        }
        return '';
    }

    private static function line(array $p, int $qty, string $unit): array
    {
        $u = trim((string)($p['unit'] ?? ''));
        return [
            'productId' => (int)$p['id'],
            'label'     => (string)$p['name'],
            'price'     => round((float)$p['price'], 2),
            'quantity'  => max(1, $qty),
            'unit'      => $u !== '' ? $u : $unit,
        ];
    }

    /** 5.18.102: a refusal; $link is the record in force when the link itself was found valid. */
    private static function no(string $reason, string $why = '', ?int $link = null): array
    {
        return ['ok' => false, 'reason' => $reason, 'line' => null, 'link' => $link, 'unlinked' => false, 'why' => $why];
    }

    /** 5.18.102: a resolved line, with the link record it came from (null for a candidate at save). */
    private static function yes(array $line, ?int $link): array
    {
        return ['ok' => true, 'reason' => '', 'line' => $line, 'link' => $link, 'unlinked' => false, 'why' => ''];
    }

    private static function money(float $v): string
    {
        return number_format($v, abs($v - round($v)) < 0.0001 ? 0 : 2);
    }
}
