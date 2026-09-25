<?php
declare(strict_types=1);

require_once __DIR__ . '/DpoClient.php';
require_once __DIR__ . '/DpoPaymentStore.php';
require_once __DIR__ . '/DpoPaymentService.php';
require_once __DIR__ . '/crm_url.php';
require_once __DIR__ . '/ConfigVault.php';
require_once __DIR__ . '/bootstrap_data.php';

/**
 * DpoBootstrap — one place that builds the DPO service.
 *
 * Four entry points reach DPO: the portal's initiate call, the browser return,
 * DPO's server push and the reconcile cron. If each assembled its own client
 * they would drift — a different timeout here, a stale URL there, and
 * eventually one of them talking to the wrong environment. They all come
 * through this.
 *
 * The redirect, back and push URLs are DERIVED at call time from
 * dn_plugin_public(), never stored. That helper is already the single source
 * of truth for this install's public address, including the documented
 * override for when uCRM reports an internally-correct but externally-wrong
 * hostname. A URL copied into config would drift from it silently, and the
 * symptom would be customers landing nowhere after paying.
 */
final class DpoBootstrap
{
    /** The DPO settings that live in the vault. */
    const KEYS = ['dpo_enabled', 'dpo_environment', 'dpo_company_token', 'dpo_service_type',
                  'dpo_company_acc_ref', 'dpo_payment_method_uuid', 'dpo_ptl', 'dpo_ptl_type',
                  'dpo_test_clients', 'dpo_test_link_key'];

    /**
     * The config with anything DPO needs restored from the vault.
     *
     * Every caller hands us whatever $config it happens to hold, and in a web
     * request that is kyc_config.json straight off disk — public.php never
     * calls PluginConfig::load(), so it carries no vault values at all. A
     * company token stored ONLY in the vault would therefore read as "not
     * configured" on the admin screen AND refuse every payment, while sitting
     * safely on disk the whole time. Filling here means one answer everywhere.
     */
    public static function vaulted(array $config): array
    {
        $root = dirname(__DIR__);
        try { $dataDir = getDataDir($root); } catch (\Throwable $e) { $dataDir = $root . '/data'; }
        return ConfigVault::fill($root, $dataDir, $config, self::KEYS);
    }

    /** Where DPO sends the customer back. Also what you paste into DPO's portal. */
    public static function returnUrl(array $config): string
    {
        return dn_plugin_public($config) . '?page=dpo_return';
    }

    public static function backUrl(array $config): string
    {
        return dn_plugin_public($config) . '?page=dpo_return&cancelled=1';
    }

    /**
     * The URL DPO must POST its result to. It is NOT a createToken field, so
     * it has to be registered with DPO out of band — which is why the admin
     * screen shows it for copying rather than sending it.
     */
    public static function pushUrl(array $config): string
    {
        return dn_plugin_public($config) . '?page=dpo_push';
    }

    /** @param object $store SqliteStore */
    public static function service($store, array $config, $crm, ?callable $log = null): DpoPaymentService
    {
        $config = self::vaulted($config);
        $env = ((string)($config['dpo_environment'] ?? 'test')) === 'live' ? 'live' : 'test';

        $client = [
            'company_token'   => (string)($config['dpo_company_token'] ?? ''),
            'service_type'    => (string)($config['dpo_service_type'] ?? ''),
            'company_acc_ref' => (string)($config['dpo_company_acc_ref'] ?? ''),
            'environment'     => $env,
            // Omitted entirely when 0. Our own attempt_expires_at is what
            // actually expires an attempt, so this is a courtesy to DPO,
            // not a dependency.
            'ptl'             => (int)($config['dpo_ptl'] ?? 30),
            'ptl_type'        => (string)($config['dpo_ptl_type'] ?? 'minutes'),
            'timeout'         => (int)($config['dpo_timeout'] ?? 30),
        ];
        $fake = self::harnessUrl($env);
        if ($fake !== '') { $client['api_create'] = $fake; $client['api_verify'] = $fake; }
        $dpo = new DpoClient($client);

        $cfg = [
            'dpo_enabled'             => $config['dpo_enabled'] ?? false,
            'dpo_environment'         => $env,
            'dpo_payment_method_uuid' => (string)($config['dpo_payment_method_uuid'] ?? ''),
            'dpo_ptl'                 => (int)($config['dpo_ptl'] ?? 30),
            'dpo_currencies'          => self::currencies($config),
            'dpo_unpayable_statuses'  => self::statuses($config),
            'dpo_test_clients'        => self::testClients($config),
            'dpo_return_url'          => self::returnUrl($config),
            'dpo_back_url'            => self::backUrl($config),
        ];

        return new DpoPaymentService(DpoPaymentStore::fromStore($store), $dpo, $crm, $cfg, $log);
    }

    /**
     * The test harness's fake DPO, from DN_DPO_FAKE_URL — honoured only for a
     * loopback address and only in the test environment, so it can never
     * carry a company token off this machine or redirect live payments.
     */
    public static function harnessUrl(string $env): string
    {
        $u = (string)getenv('DN_DPO_FAKE_URL');
        if ($u === '' || $env !== 'test') return '';
        return preg_match('#^http://127\.0\.0\.1:\d{2,5}/$#', $u) === 1 ? $u : '';
    }

    /**
     * The uCRM clients who may pay while the environment is TEST.
     *
     * DPO's test cards are published, so with a test token switched on any
     * customer who reached Pay Now could "pay" a real invoice with one — and
     * the settlement would post a real payment in uCRM. In the test
     * environment only these clients see Pay Now, only they can start a
     * payment, and only their payments reach uCRM. Ignored when live.
     *
     * @return array<int,int>
     */
    public static function testClients(array $config): array
    {
        $raw = $config['dpo_test_clients'] ?? '';
        if (is_string($raw)) $raw = $raw === '' ? [] : preg_split('/[\s,]+/', trim($raw));
        $out = [];
        foreach ((array)$raw as $c) {
            if (is_numeric($c) && (int)$c > 0) $out[] = (int)$c;
        }
        return array_values(array_unique($out));
    }

    /**
     * The page DPO's reviewer opens: the test clients' unpaid invoices with a
     * Pay button, no sign-in. '' unless the environment is TEST and a link
     * has been made on the admin screen. The key in it is the only thing that
     * opens the page, so the admin screen can replace it at any time.
     */
    public static function testLinkUrl(array $config): string
    {
        $env = ((string)($config['dpo_environment'] ?? 'test')) === 'live' ? 'live' : 'test';
        $key = (string)($config['dpo_test_link_key'] ?? '');
        if ($env !== 'test' || strlen($key) < 16) return '';
        return dn_plugin_public($config) . '?page=dpo_test&k=' . rawurlencode($key);
    }

    /**
     * Currencies DPO will be asked for. Empty means "whatever the invoice
     * says" — which is only safe once DPO has confirmed the account takes it,
     * so the admin screen defaults this to the install's own currency.
     *
     * @return array<int,string>
     */
    public static function currencies(array $config): array
    {
        $raw = $config['dpo_currencies'] ?? '';
        if (is_string($raw)) $raw = $raw === '' ? [] : preg_split('/[\s,]+/', trim($raw));
        $out = [];
        foreach ((array)$raw as $c) {
            $c = strtoupper(trim((string)$c));
            if ($c !== '') $out[] = $c;
        }
        return array_values(array_unique($out));
    }

    /**
     * uCRM invoice statuses that may not be paid online.
     *
     * Deliberately config, not a constant: this plugin knows 4 = paid and
     * 6 = overdue from portal_data.php, and nothing establishes the code for
     * a VOID invoice. Rather than guess one and silently refuse the wrong
     * invoices — or worse, accept a void one — the list is set by whoever can
     * read it off the live instance.
     *
     * @return array<int,int>
     */
    public static function statuses(array $config): array
    {
        $raw = $config['dpo_unpayable_statuses'] ?? '';
        if (is_string($raw)) $raw = $raw === '' ? [] : preg_split('/[\s,]+/', trim($raw));
        $out = [];
        foreach ((array)$raw as $s) {
            if (is_numeric($s)) $out[] = (int)$s;
        }
        return array_values(array_unique($out));
    }

    /** What the admin screen shows, and what a preflight check asks. */
    public static function readiness(array $config): array
    {
        $config  = self::vaulted($config);
        $missing = [];
        if ((string)($config['dpo_company_token'] ?? '') === '')       $missing[] = 'company token';
        if ((string)($config['dpo_service_type'] ?? '') === '')        $missing[] = 'service type';
        if ((string)($config['dpo_payment_method_uuid'] ?? '') === '') $missing[] = 'uCRM payment method';
        if (self::currencies($config) === [])                          $missing[] = 'accepted currencies';
        return [
            'ready'        => $missing === [],
            'missing'      => $missing,
            'environment'  => ((string)($config['dpo_environment'] ?? 'test')) === 'live' ? 'live' : 'test',
            'enabled'      => in_array($config['dpo_enabled'] ?? false, [true, 1, '1', 'yes', 'on'], true),
            'test_clients' => self::testClients($config),
        ];
    }

    /**
     * Whether the portal draws Pay Now for this client. Display only — the
     * service refuses the same cases on its own — but a button a customer
     * cannot use is a question to the support line.
     */
    public static function payNowFor(array $config, int $clientId): bool
    {
        $r = self::readiness($config);
        if (!$r['enabled'] || !$r['ready'] || $clientId <= 0) return false;
        return $r['environment'] === 'live' || in_array($clientId, $r['test_clients'], true);
    }
}
