<?php
declare(strict_types=1);

/**
 * ConfigVault — the plugin's configuration survives its own reinstallation.
 *
 * Everything this plugin is configured with lives inside the plugin folder:
 * uCRM writes the settings form to data/config.json, operator overrides sit in
 * data/kyc_config.json, and the webhook secret in data/webhook_secret. Delete
 * the plugin — or upgrade it the wrong way — and all three die with it, which
 * is exactly what happened on 25 Aug 2026: a re-install came back with the AI
 * disabled, no provider key and a fresh webhook secret that no longer matched
 * what Evolution had registered.
 *
 * The vault is a copy of the working configuration kept ONE LEVEL ABOVE the
 * plugin folder (the plugins root survives a plugin's deletion), refreshed
 * automatically on every config load, and applied as a GAP-FILLER:
 *
 *   - a key the current config already has — even set to false or off — is
 *     NEVER overridden. Turning the AI off stays off. Changing a key on the
 *     settings form wins immediately, and the vault re-learns the new value.
 *   - only a key that is entirely absent, or an empty string, is filled in.
 *   - a missing data/webhook_secret file is restored byte-for-byte, so the
 *     webhook registration held by Evolution keeps authenticating after a
 *     re-install instead of failing against a freshly generated secret.
 *
 * The vault holds secrets, so it is written 0600 and atomically. That is the
 * same exposure class as uCRM's own config.json in the plugin data dir, in a
 * location uCRM does not route (only plugin dirs are reachable via _plugins).
 * If the plugins root is not writable, the vault falls back to the data dir —
 * still surviving upgrades that keep the data dir, just not full deletion.
 */
class ConfigVault
{
    /** Configuration that must survive a re-install. Bools gap-fill only on absence. */
    const VAULT_KEYS = [
        'ai_enabled',
        'ai_provider',
        'ai_model',
        'openai_api_key',
        'claude_api_key',
        'evo_api_url',
        'evo_api_key',
        'evo_webhook_secret',
        'evo_instance_sales',
        'evo_instance_support',
        'evo_instance_account',
        'shopbot_ai_url',
        'shopbot_ai_token',
        'ai_tools_token',
        // EFRIS (Uganda e-invoicing): survive re-install like every other
        // credential — losing the device binding mid-quarter is not an option.
        'efris_environment',
        'efris_auto_submit',
        'efris_tin',
        'efris_device_no',
        'efris_test_api_url',
        'efris_production_api_url',
        'efris_private_key',
        // Ledger currency identity — losing it would silently re-book the
        // cashbook in the wrong base after a re-install.
        'currency_symbol',
        'currency_code',
        'cashbook_base_currency',
        'cashbook_currencies',
        'books_start_date',
        // Inbound mail: the address the AI reads and its password. A
        // re-install that lost these would silently stop the draft inbox,
        // and nobody notices mail that is not being read.
        'email_ai_jmap_url',
        'email_ai_mailbox',
        'email_ai_mailbox_pw',
        'email_ai_jmap_resolve',
        'email_ai_jmap_via',
    ];

    public static function path(string $pluginRoot, string $dataDir): string
    {
        // Tests must be able to put the vault somewhere of their own.
        //
        // The vault deliberately lives OUTSIDE the data directory so that it
        // survives a re-install — which also means DN_DATA_DIR does not move
        // it. A smoke test that ran the tools with a temporary data directory
        // therefore wrote its fake mailbox into the real vault, and because
        // the vault gap-fills missing keys, that fake would later be restored
        // over a genuine one. A test that can corrupt production config is
        // worse than the bug it was guarding against.
        //
        // Read from the environment, so only a CLI process that sets it is
        // affected; nothing a web request can reach.
        $override = (string)getenv('DN_VAULT_FILE');
        if ($override !== '') return $override;

        $parent = dirname(rtrim($pluginRoot, '/'));
        if (is_dir($parent) && is_writable($parent)) {
            return $parent . '/.dishnet-sudan.vault.json';
        }
        return rtrim($dataDir, '/') . '/config_vault.json';
    }

    /**
     * Gap-fill $config from the vault, restore the webhook secret file if it
     * is missing, then refresh the vault from the effective configuration.
     * Returns the possibly-augmented config. Never throws: configuration
     * loading must not be able to fail because of the safety net around it.
     */
    public static function apply(string $pluginRoot, string $dataDir, array $config): array
    {
        try {
            $file  = self::path($pluginRoot, $dataDir);
            $vault = [];
            if (is_file($file)) {
                $decoded = json_decode((string)@file_get_contents($file), true);
                if (is_array($decoded)) $vault = $decoded;
            }

            $restored = [];
            foreach (self::VAULT_KEYS as $k) {
                $missing = !array_key_exists($k, $config)
                    || (is_string($config[$k]) && trim($config[$k]) === '');
                if ($missing && array_key_exists($k, $vault['config'] ?? [])) {
                    $config[$k] = $vault['config'][$k];
                    $restored[] = $k;
                }
            }

            $secretFile = rtrim($dataDir, '/') . '/webhook_secret';
            // A fresh install's first load can run before the data directory
            // exists; without this, the secret restore silently skipped and
            // the refresh below then erased the vault's own copy.
            if (!is_dir($dataDir)) @mkdir($dataDir, 0700, true);
            if (!is_file($secretFile)
                && is_string($vault['webhook_secret_file'] ?? null)
                && $vault['webhook_secret_file'] !== ''
                && is_dir($dataDir)) {
                if (@file_put_contents($secretFile, $vault['webhook_secret_file']) !== false) {
                    @chmod($secretFile, 0600);
                    $restored[] = 'webhook_secret file';
                }
            }

            if ($restored) {
                // Names only — values are secrets.
                error_log('[ConfigVault] restored after re-install: ' . implode(', ', $restored));
            }

            self::refresh($file, $dataDir, $config, $vault);
        } catch (\Throwable $e) {
            error_log('[ConfigVault] ' . $e->getMessage());
        }
        return $config;
    }

    /**
     * Put values into the vault directly.
     *
     * The ordinary route for configuration is uCRM's own Configuration screen,
     * and this is not a replacement for it. It exists for the case where uCRM
     * is still holding an older manifest and has no field to type into yet —
     * a secret that cannot be entered anywhere is not more secure, it just
     * ends up in a shell command instead.
     *
     * Written through SecureFile deliberately. refresh() writes 0600, which is
     * right when the web process itself writes it and wrong the moment a tool
     * run as root through docker exec does: the file becomes root-owned, the
     * web user can no longer read it, and everything reports "not configured"
     * while the value sits there perfectly intact. That outage has already
     * happened once here, to email_settings.json.
     *
     * @param array<string,string> $pairs  VAULT_KEYS only; anything else is refused
     * @return array{ok:bool, error:string, stored:string[]}
     */
    public static function store(string $pluginRoot, string $dataDir, array $pairs): array
    {
        $stored = [];
        foreach (array_keys($pairs) as $k) {
            if (!in_array($k, self::VAULT_KEYS, true)) {
                return ['ok' => false, 'stored' => [],
                        'error' => "'{$k}' is not a vault key"];
            }
        }

        $file  = self::path($pluginRoot, $dataDir);
        $vault = [];
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) $vault = $decoded;
        }
        if (!isset($vault['config']) || !is_array($vault['config'])) $vault['config'] = [];

        foreach ($pairs as $k => $v) {
            $v = (string)$v;
            if (trim($v) === '') { unset($vault['config'][$k]); continue; }
            $vault['config'][$k] = $v;
            $stored[] = $k;
        }

        require_once __DIR__ . '/SecureFile.php';
        $r = SecureFile::write($file,
            (string)json_encode($vault, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (empty($r['ok'])) {
            return ['ok' => false, 'stored' => [], 'error' => (string)($r['error'] ?? 'write failed')];
        }
        // Names only. A log line is not a place for a password.
        error_log('[ConfigVault] stored: ' . implode(', ', $stored));
        return ['ok' => true, 'error' => '', 'stored' => $stored];
    }

    /** Write the vault only when its content actually changed. */
    private static function refresh(string $file, string $dataDir, array $config, array $previous = []): void
    {
        $snap = ['config' => []];
        foreach (self::VAULT_KEYS as $k) {
            if (array_key_exists($k, $config)
                && !(is_string($config[$k]) && trim($config[$k]) === '')) {
                $snap['config'][$k] = $config[$k];
            }
        }
        $secretFile = rtrim($dataDir, '/') . '/webhook_secret';
        if (is_file($secretFile)) {
            $s = trim((string)@file_get_contents($secretFile));
            if ($s !== '') $snap['webhook_secret_file'] = $s;
        } elseif (is_string($previous['webhook_secret_file'] ?? null)
                  && $previous['webhook_secret_file'] !== '') {
            // The file being absent right now is not a reason to forget it.
            // A vault that erases its cargo when delivery fails once is not a
            // vault — this exact hazard lost the secret on 25 Aug.
            $snap['webhook_secret_file'] = $previous['webhook_secret_file'];
        }
        if ($snap['config'] === [] && !isset($snap['webhook_secret_file'])) {
            return;   // nothing worth keeping; never replace a vault with emptiness
        }

        $json = json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_file($file) && (string)@file_get_contents($file) === $json) {
            return;
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    }
}
