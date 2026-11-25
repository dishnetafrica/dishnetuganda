<?php
/**
 * values-5.18.107.php — READ-ONLY: the production values 5.18.107's build needs (root docs/76 §N), read on the live
 * install. It is fed on stdin to `php` inside the uCRM container, as the book's owner, by scripts/values-5.18.107.sh,
 * which checks the installed code first. Arguments: the plugin folder, then the data folder.
 *
 * It changes nothing:
 *   - the settings through PluginConfig::read() (never load(), which refreshes the vault), the vault only for the
 *     three tenant keys' presence, and the book's kyc_config row by name — never a whole file printed;
 *   - the book opened SQLITE_OPEN_READONLY with query_only on, inside one read transaction that is rolled back, and
 *     only when its -wal and -shm already exist (so the open creates nothing); total_changes() is printed and must be 0;
 *   - uCRM only through GetOnlyCrm: GET on four paths (service-plans, products, invoices, organizations); every other
 *     verb, path and helper refused;
 *   - the product lookup with the cache off (catalogue_cache_seconds 0: the cache file is neither read nor written).
 *
 * It prints no phone number, no person's name, no message text, no secret: a text setting is "set, n bytes, sha256
 * prefix, digit counts"; an invoice number only its shape (<6d>); counts, product names and prices. As a last guard
 * every run of nine or more digits is withheld, and the wrapper masks again.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

$P = rtrim((string)($argv[1] ?? ''), '/');
$D = rtrim((string)($argv[2] ?? ''), '/');

$n9 = 0;
$say = function (string $s = '') use (&$n9): void {
    echo preg_replace_callback('/\+?\d(?:[ \-]?\d){8,}/', function () use (&$n9) { $n9++; return '<9+ digits withheld>'; }, $s), "\n";
};
$head = function (string $t) use ($say): void { $say(''); $say('── ' . $t . ' ' . str_repeat('─', max(3, 100 - mb_strlen($t)))); };
$j = function ($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };
$yn = function ($b): string { return $b ? 'yes' : 'no'; };
// a stored time, printed with a T so a date and a time never read as one long run of digits
$stamp = function ($s): string {
    $s = trim((string)$s);
    if ($s === '') return '-';
    return preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?/', $s) ? str_replace(' ', 'T', substr($s, 0, 19)) : 'unparsed (' . strlen($s) . ' bytes)';
};
$sha = function (string $s): string { return substr(hash('sha256', $s), 0, 12); };
$d5 = function (string $s): int { return (int)preg_match_all('/\d{5,}/', $s); };
$phoneShaped = function (string $s): int { return (int)preg_match_all('/(?<!\d)(?:\+?\d[\d\s-]{8,}\d)(?!\d)/', $s); };

/** A text value: set or not, its size, a hash prefix and two digit counts — never the text. */
$txt = function ($v) use ($sha, $d5, $phoneShaped): string {
    if ($v === null) return 'not set';
    if (!is_scalar($v)) return 'not text (' . gettype($v) . ')';
    $t = trim((string)$v);
    if ($t === '') return 'empty';
    if (strtolower($t) === 'omit') return '"omit"';
    return 'set, ' . strlen($t) . ' bytes / ' . mb_strlen($t) . ' chars, sha256 ' . $sha($t)
        . ', digit runs of 5+: ' . $d5($t) . ', phone-shaped: ' . $phoneShaped($t);
};

/** A switch-like value: its JSON class, and the value itself only when it is a short token with no long digit run. */
$cls = function (array $a, string $k): string {
    if (!array_key_exists($k, $a)) return 'absent';
    $v = $a[$k];
    if ($v === null) return 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (abs((float)$v) < 1000000) ? (string)$v : 'a number of ' . strlen((string)(int)$v) . ' digits';
    if (is_array($v)) return 'a list/object of ' . count($v);
    $t = trim((string)$v);
    if ($t === '') return '""';
    if (preg_match('#^[A-Za-z0-9,._:/\-]{1,40}$#', $t) && strpos($t, '//') === false && !preg_match('/\d{5,}/', $t)) return '"' . $t . '"';
    return 'text of ' . strlen($t) . ' bytes';
};
$bool = function ($v): bool { return filter_var($v ?? false, FILTER_VALIDATE_BOOLEAN); };

$say('== 5.18.107 production values — read-only — ' . gmdate('Y-m-d\TH:i:s\Z') . ' ==');
if ($P === '' || $D === '' || !is_file($P . '/manifest.json')) { $say('STOP: the plugin folder was not given or holds no manifest; nothing was read'); exit(2); }

foreach (['PluginConfig', 'ConfigVault', 'CrmApiClient', 'DishNetTools', 'TenantProfile', 'StaffJobsGate', 'ShopCatalogue',
          'QuoteOrganization', 'UcrmCatalogue', 'UcrmQuoteLinks', 'UcrmQuoteLines', 'MediaPolicy', 'FlyerAsset', 'KitTaxNote',
          'PlanFenceGuard', 'KnowledgeBase', 'WaSendBudget', 'QuoteTracker', 'QuoteChase'] as $l) {
    require_once $P . '/lib/' . $l . '.php';
}
if (!function_exists('dn_tz')) require_once $P . '/lib/timezone.php';

/** The only uCRM client this reader uses: GET on four paths, nothing else. */
final class GetOnlyCrm extends CrmApiClient
{
    public static array $asked = []; public static int $refused = 0; public static array $raw = []; public static array $codes = [];
    private const PATHS = '#^(service-plans|products|invoices|organizations)(\?|$)#';
    public static function of(CrmApiClient $c): self { return new self($c->getBaseUrl(), $c->getAppKey(), $c->getAuthHeader()); }
    private static function no(string $w): RuntimeException { self::$refused++; return new RuntimeException('GET only: ' . $w . ' refused'); }
    public function get(string $path): ?array
    {
        if (!preg_match(self::PATHS, $path)) throw self::no('GET ' . preg_replace('/\?.*$/', '', $path));
        self::$asked[] = $path;
        $r = parent::get($path);
        self::$raw[$path] = $r;
        $e = $this->getLastError();
        self::$codes[$path] = $r === null ? (isset($e['http_code']) ? 'HTTP ' . (int)$e['http_code'] : (isset($e['curl_error']) ? 'no connection' : 'no answer')) : 'ok';
        return $r;
    }
    public function post(string $path, array $p = []): ?array { throw self::no('POST'); }
    public function patch(string $path, array $p = []): ?array { throw self::no('PATCH'); }
    public function delete(string $path): ?array { throw self::no('DELETE'); }
    public function upload(string $filePath, array $fields = [], string $clientId = ''): ?array { throw self::no('upload'); }
    public function getRawContent(string $path): ?string { throw self::no('raw read'); }
    public function testConnection(string $pluginRoot = '', array $config = []): array { throw self::no('connection test'); }
    public function createPaymentSafe(array $payload, string $uniqueRef = ''): array { throw self::no('payment'); }
    public function findExistingPayment(int $clientId, float $amount, string $uniqueRef = ''): ?array { throw self::no('payment lookup'); }
    public function getWebhooks(): array { throw self::no('webhooks'); }
    public function createWebhook(string $url, array $events = [], bool $verifySsl = true): ?array { throw self::no('webhook'); }
    public function updateWebhook(int $id, array $data): ?array { throw self::no('webhook'); }
    public function deleteWebhook(int $id): bool { throw self::no('webhook'); }
    public function findWebhookByUrl(string $urlPattern): ?array { throw self::no('webhook'); }
}

$rc = 0;
$db = null;
try {
    // ── the settings: the workers' view, and the book's kyc_config row (the screens' and the old path's view) ──────────
    $cfg = PluginConfig::read($P, $D);

    $book = $D . '/plugin.sqlite3';
    $bookWhy = '';
    if (!is_file($book)) $bookWhy = 'no plugin.sqlite3 in the data folder';
    elseif (!is_file($book . '-wal') || !is_file($book . '-shm')) $bookWhy = 'its -wal/-shm files are absent (no plugin process has it open), so it was not opened: opening it would create them';
    else {
        $db = new PDO('sqlite:' . $book, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $db->exec('PRAGMA query_only = ON');
        $db->exec('BEGIN');
    }
    $tbl = function (string $n) use (&$db): bool {
        if ($db === null) return false;
        return (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $db->quote($n))->fetchColumn() > 0;
    };
    $cols = function (string $n) use (&$db): array {
        $o = [];
        foreach ($db->query('PRAGMA table_info(' . $db->quote($n) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) $o[] = (string)$c['name'];
        return $o;
    };
    $q = function (string $sql) use (&$db): array { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
    $row = [];
    if ($db !== null && $tbl('kyc_config')) {
        $data = $db->query('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1')->fetchColumn();
        $row = is_string($data) ? (json_decode($data, true) ?: []) : [];
    }
    $json1 = false;
    if ($db !== null) { try { $json1 = (int)$db->query("SELECT json_valid('{}')")->fetchColumn() === 1; } catch (Throwable $e) { $json1 = false; } }

    $say('PHP ' . PHP_VERSION . ' · SQLite ' . ($db !== null ? (string)$db->query('SELECT sqlite_version()')->fetchColumn() : '(book not opened)')
        . ' · JSON functions: ' . $yn($json1) . ' · reading as uid ' . (function_exists('posix_geteuid') ? posix_geteuid() : '?'));
    $say('the book: ' . ($db !== null ? 'opened read-only (query_only, one read transaction)' : 'NOT READ — ' . $bookWhy));
    $say('the kyc_config row: ' . ($db === null ? 'not read' : ($row ? count($row) . ' keys' : 'absent or empty')));
    $say('"worker" below is PluginConfig::read (the three settings files, then the vault), what the workers read; "row" is the book\'s kyc_config row, what the admin screens, master\'s job flags and the old auto-reply path read.');
    $both = function (string $k) use ($cfg, $row, $cls, $db): string {
        return 'worker ' . $cls($cfg, $k) . ' · row ' . ($db === null ? 'not read' : $cls($row, $k));
    };
    $bothTxt = function (string $k) use ($cfg, $row, $txt, $db): string {
        $a = $cfg[$k] ?? null; $b = $row[$k] ?? null;
        $same = trim((string)(is_scalar($a) ? $a : '')) === trim((string)(is_scalar($b) ? $b : ''));
        return 'worker ' . $txt($a) . ' · row ' . ($db === null ? 'not read' : ($same ? 'the same' : $txt($b)));
    };

    // ── 1. AI settings ───────────────────────────────────────────────────────────────────────────────────────────────
    $head('1. AI settings (§K step 2, §D, Part N gate)');
    foreach (['ai_enabled', 'ai_hardware_expert', 'ai_sales_on_all_numbers', 'ai_qualification'] as $k) {
        $say(sprintf('%-28s %s → the worker reads it as %s', $k, $both($k), $bool($cfg[$k] ?? false) ? 'ON' : 'off'));
    }
    $mode = trim((string)($cfg['bot_instructions_mode'] ?? 'append'));
    $say(sprintf('%-28s %s → the worker reads it as %s', 'bot_instructions_mode', $both('bot_instructions_mode'), $mode === 'override' ? 'override' : 'append'));
    $say(sprintf('%-28s %s', 'bot_custom_instructions', $bothTxt('bot_custom_instructions')));
    $custom = trim((string)($cfg['bot_custom_instructions'] ?? ''));
    $say('  override in effect on the worker (mode "override" AND custom text not empty; the knowledge base and business facts then leave the prompt): ' . $yn($custom !== '' && $mode === 'override'));
    $fl = FlyerAsset::find($cfg, $D);
    $say('flyer: ' . ($fl === null ? 'none (the assistant is never told <<FLYER>> exists)' : $fl['kind']));
    foreach (array_keys(FlyerAsset::EXTS) as $ext) {
        $f = $D . '/' . FlyerAsset::FILE_BASE . '.' . $ext;
        if (is_file($f)) { $s = (int)@filesize($f); $say('  ' . basename($f) . ': ' . round($s / 1024) . ' KB, ' . ($s > 0 && $s <= FlyerAsset::MAX_FILE_BYTES ? 'usable' : 'REFUSED (empty or over 4 MB)')); }
    }
    $u = trim((string)($cfg['wa_flyer_url'] ?? ''));
    $say('  wa_flyer_url: ' . ($u === '' ? 'not set' : 'set, scheme ' . (preg_match('#^https://#i', $u) ? 'https' : (preg_match('#^http://#i', $u) ? 'http' : 'neither (ignored)'))));

    // ── 2. Operator facts ────────────────────────────────────────────────────────────────────────────────────────────
    $head('2. Operator facts (§D, Part K floor; content never printed)');
    foreach (['ai_fact_kit_taxes', 'ai_fact_prices', 'ai_fact_delivery', 'ai_fact_payment', 'ai_fact_office', 'ai_fact_location_pin',
              'stock_statement', 'ai_fact_business_cap', 'quote_company_phone'] as $k) {
        $say(sprintf('%-22s %s', $k, $bothTxt($k)));
    }
    $kn = KitTaxNote::note($cfg);
    $say('the kit tax note the worker appends: ' . ($kn === '' ? 'none (hardware module off, or "omit")' : ($kn === KitTaxNote::DEFAULT_NOTE ? 'the code\'s default sentence' : 'the operator\'s own wording')));
    $pn = PlanFenceGuard::note($cfg);
    $say('the business-plan note: ' . ($pn === '' ? 'none ("omit")' : ($pn === PlanFenceGuard::DEFAULT_NOTE ? 'the code\'s default sentence' : 'the operator\'s own wording')));

    // ── 3. The knowledge base ────────────────────────────────────────────────────────────────────────────────────────
    $head('3. The knowledge base: OFFICE_LOCATION (W.3)');
    if ($db === null) $say('not read: the book was not opened');
    elseif (!$tbl('knowledge_items')) $say('no knowledge_items table');
    else {
        $by = $q("SELECT kind, status, COUNT(*) n FROM knowledge_items GROUP BY kind, status ORDER BY kind, status");
        $say('rows by kind and status: ' . ($by ? implode(', ', array_map(function ($r) use ($cls) { return $cls(['k' => $r['kind']], 'k') . '/' . $cls(['k' => $r['status']], 'k') . ' ' . (int)$r['n']; }, $by)) : 'none'));
        $ol = $q("SELECT kind, status, answer, wa_answer, updated_at FROM knowledge_items WHERE item_key = 'OFFICE_LOCATION'");
        if (!$ol) $say('OFFICE_LOCATION: NO ROW');
        foreach ($ol as $r) {
            $wa = trim((string)$r['wa_answer']); $an = trim((string)$r['answer']); $lim = KnowledgeBase::answerLimit($cfg);
            $say('OFFICE_LOCATION: kind ' . $cls(['k' => $r['kind']], 'k') . ', status ' . $cls(['k' => $r['status']], 'k') . ', approved (rendered): ' . $yn($r['status'] === 'approved') . ', updated ' . $stamp($r['updated_at']));
            $say('  answer: ' . $txt($an) . ($an !== '' ? ', over the prompt\'s cut of ' . $lim . ': ' . $yn(mb_strlen($an) > $lim) : ''));
            $say('  wa_answer: ' . $txt($wa) . ($wa !== '' ? ', over the 300-char chat cut: ' . $yn(mb_strlen($wa) > 300) : ''));
        }
        $v = (int)$db->query("SELECT COUNT(*) FROM knowledge_items WHERE item_key <> 'OFFICE_LOCATION' AND upper(trim(item_key)) = 'OFFICE_LOCATION'")->fetchColumn();
        $say('rows spelled like OFFICE_LOCATION but not exactly it: ' . $v);
    }

    // ── 4. The tenant ────────────────────────────────────────────────────────────────────────────────────────────────
    $head('4. The tenant: which source selects Uganda (W.1)');
    $files = new ReflectionMethod('PluginConfig', 'files'); $files->setAccessible(true);
    $norm = new ReflectionMethod('PluginConfig', 'normalise'); $norm->setAccessible(true);
    $noVault = $norm->invoke(null, $files->invoke(null, $P, $D));
    $selCls = function (array $a, string $k): string {
        if (!array_key_exists($k, $a)) return 'absent';
        $v = $a[$k];
        if ($v === null) return 'null';
        if (!is_scalar($v)) return 'not text';
        $t = trim((string)$v);
        if ($t === '') return '""';
        if ($k === TenantProfile::SELECTOR_KEY) { $s = strtolower($t); return in_array($s, TenantProfile::IDS, true) ? 'selects ' . $s : 'unrecognised (' . strlen($t) . ' bytes)'; }
        $c = strtoupper($t);
        return $c === 'UGX' ? 'selects uganda' : (($c === 'SSP' || $c === 'USD') ? 'selects south-sudan' : 'unrecognised (' . strlen($t) . ' bytes)');
    };
    $srcs = ['plugin data/config.json' => $P . '/data/config.json', 'data folder config.json' => $D . '/config.json', 'data folder kyc_config.json' => $D . '/kyc_config.json'];
    foreach ($srcs as $label => $f) {
        $a = is_file($f) ? (is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : null) : [];
        if ($a === null) { $say($label . ': UNREADABLE by this user'); continue; }
        $say(sprintf('%-28s %s', $label . ':', implode(' · ', array_map(function ($k) use ($a, $selCls) { return $k . ' ' . $selCls($a, $k); }, TenantProfile::SELECTOR_SOURCES))));
    }
    $say(sprintf('%-28s %s', 'the row:', $db === null ? 'not read' : implode(' · ', array_map(function ($k) use ($row, $selCls) { return $k . ' ' . $selCls($row, $k); }, TenantProfile::SELECTOR_SOURCES))));
    $decider = function (array $c) use ($selCls): string {
        $s = strtolower(trim((string)($c[TenantProfile::SELECTOR_KEY] ?? '')));
        if (in_array($s, TenantProfile::IDS, true)) return TenantProfile::SELECTOR_KEY;
        if (array_key_exists('currency_code', $c) && $c['currency_code'] !== null) return 'currency_code';
        if (array_key_exists('cashbook_base_currency', $c) && $c['cashbook_base_currency'] !== null) return 'cashbook_base_currency';
        return 'nothing';
    };
    $idNo = TenantProfile::resolveId($noVault);
    $say('without the vault: ' . $idNo . ' (decided by ' . $decider($noVault) . ($decider($noVault) === 'nothing' ? ': south-sudan is the default, so nothing selects Uganda' : '') . ')');
    $filled = ConfigVault::fill($P, $D, $noVault, TenantProfile::SELECTOR_SOURCES);
    $say('with the vault, as TenantProfile::current() reads it: ' . TenantProfile::resolveId($filled) . ' (decided by ' . $decider($filled) . ')');
    $say('the gate the workers ask (StaffJobsGate::applies on the worker view): ' . $yn(StaffJobsGate::applies($cfg, $D)));
    $vp = ConfigVault::path($P, $D);
    $branch = getenv('DN_VAULT_FILE') ? 'DN_VAULT_FILE' : (basename($vp) === 'config_vault.json' ? 'the data folder\'s config_vault.json (the plugins folder is not writable by this user)' : 'the plugins folder\'s .dishnet-sudan.vault.json');
    $vj = (is_file($vp) && is_readable($vp)) ? json_decode((string)file_get_contents($vp), true) : null;
    $say('the vault: ' . $branch . '; present ' . $yn(is_file($vp)) . ', readable ' . $yn(is_readable($vp)) . ', valid ' . $yn(is_array($vj))
        . (is_array($vj) ? '; ' . implode(' · ', array_map(function ($k) use ($vj, $selCls) { return $k . ' ' . $selCls((array)($vj['config'] ?? []), $k); }, TenantProfile::SELECTOR_SOURCES)) : ''));
    foreach (['uganda', 'south-sudan'] as $pid) {
        $pf = $P . '/profiles/' . $pid . '.json';
        $st = @stat($pf);
        $say('profiles/' . $pid . '.json: present ' . $yn(is_file($pf)) . ', readable ' . $yn(is_readable($pf)) . ', valid ' . $yn(is_array(json_decode((string)@file_get_contents($pf), true)))
            . ($st ? ', owner ' . $st['uid'] . ':' . $st['gid'] . ', mode ' . substr(sprintf('%o', $st['mode']), -4) : ''));
    }
    try { $say('TenantProfile::load(uganda): ' . TenantProfile::load('uganda')->id()); } catch (Throwable $e) { $say('TenantProfile::load(uganda): FAILED (' . get_class($e) . ')'); }

    // ── 5. Hand-overs and alerts ─────────────────────────────────────────────────────────────────────────────────────
    $head('5. Hand-overs and alerts (Part K, Part X, W.3)');
    $say(sprintf('%-28s %s', 'ai_handover_message', $bothTxt('ai_handover_message')));
    $form = function ($v): string {
        $t = preg_replace('/[^0-9+]/', '', (string)($v ?? ''));
        if ($t === '') return 'not set (alerts off)';
        $digits = ltrim($t, '+');
        if ($t[0] === '+') $f = 'international with +';
        elseif (strpos($digits, '256') === 0) $f = 'digits starting with 256';
        elseif (strpos($digits, '211') === 0) $f = 'digits starting with 211';
        elseif ($digits[0] === '0') $f = 'local, starting with 0';
        else $f = 'other';
        return $f . ', ' . strlen($digits) . ' digits';
    };
    $say(sprintf('%-28s worker %s · row %s', 'alert_whatsapp', $form($cfg['alert_whatsapp'] ?? null), $db === null ? 'not read' : $form($row['alert_whatsapp'] ?? null)));
    $ap = $cfg['alert_patience_minutes'] ?? null;
    $say(sprintf('%-28s %s → %d minutes', 'alert_patience_minutes', $both('alert_patience_minutes'), $ap === null ? 10 : (int)$ap));
    $hc = $cfg['wa_handover_copy_central'] ?? null;
    $say(sprintf('%-28s %s → %s', 'wa_handover_copy_central', $both('wa_handover_copy_central'), ($hc === null || $hc === '') ? 'ON (unset)' : ($bool($hc) ? 'ON' : 'off')));
    $hm = $cfg['wa_human_cooldown_minutes'] ?? null;
    $say(sprintf('%-28s %s → %d minutes', 'wa_human_cooldown_minutes', $both('wa_human_cooldown_minutes'), ($hm === null || $hm === '' || !is_numeric($hm)) ? 1440 : max(0, (int)$hm)));
    $pm = $cfg['wa_parked_max_minutes'] ?? null;
    $say(sprintf('%-28s %s → %d minutes', 'wa_parked_max_minutes', $both('wa_parked_max_minutes'), ($pm === null || $pm === '' || !is_numeric($pm)) ? 120 : max(1, (int)$pm)));
    $say(sprintf('%-28s %s (no code reads it yet; 5.18.107 adds it)', 'no_ai_alert_cooldown_hours', $both('no_ai_alert_cooldown_hours')));
    $say('voice switches: ' . implode(' · ', array_map(function ($k) use ($cfg, $cls) { return $k . ' ' . $cls($cfg, $k); },
        ['ai_media_enabled', 'ai_media_voice', 'ai_transcription_provider', 'ai_media_voice_handover', 'ai_media_voice_reply', 'ai_media_voice_reply_channels'])));
    $say('  privacy line date ok: ' . $yn(MediaPolicy::privacyLineOk($cfg)) . ' · reply approval date ' . $cls($cfg, 'ai_media_voice_reply_approved') . ' · OpenAI key stored: ' . $yn(MediaPolicy::keySet($cfg)));
    $chans = ['sales', 'support', 'account'];
    if ($db !== null && $tbl('wa_channels')) {
        foreach ($q('SELECT channel_id, role, status FROM wa_channels ORDER BY channel_id') as $r) {
            if (!in_array((string)$r['channel_id'], $chans, true)) $chans[] = (string)$r['channel_id'];
        }
    }
    $say('the voice rung on each number: ' . implode(' · ', array_map(function ($c) use ($cfg, $D) { return $c . ' ' . MediaPolicy::voiceMode($cfg, $D, $c); }, $chans)));
    if ($db !== null && $tbl('wa_channels')) {
        $say('the numbers registered: ' . implode(' · ', array_map(function ($r) use ($cls) { return $cls(['k' => $r['channel_id']], 'k') . ' ' . $cls(['k' => $r['role']], 'k') . '/' . $cls(['k' => $r['status']], 'k'); }, $q('SELECT channel_id, role, status FROM wa_channels ORDER BY channel_id'))));
    }

    // ── 6. Quotations ────────────────────────────────────────────────────────────────────────────────────────────────
    $head('6. Quotations: the switches and the invoice number\'s shape');
    foreach (['quote_tracking_enabled', 'quote_chase_enabled', 'wa_send_ledger', 'timezone'] as $k) $say(sprintf('%-24s %s', $k, $both($k)));
    $say('quote tracking on (the worker view): ' . $yn(QuoteTracker::on($cfg, $D)) . ' · quote chase on: ' . $yn(QuoteChase::on($cfg, $D)) . ' · the zone in use: ' . dn_tz($cfg));

    $qo = QuoteOrganization::setting($cfg, $D);
    $rawCrm = CrmApiClient::fromUcrm($P, $cfg);
    $crm = $rawCrm->isConfigured() ? GetOnlyCrm::of($rawCrm) : null;
    $shape = function ($s): string { return (string)preg_replace_callback('/\d+/', function ($m) { return '<' . strlen($m[0]) . 'd>'; }, (string)$s); };
    if ($crm === null) $say('uCRM: no connection is configured; nothing was asked');
    else {
        $inv = $crm->get('invoices?limit=5&order=createdDate&direction=DESC');
        if (!is_array($inv) || array_key_exists('raw', $inv)) $say('latest invoices: not read (' . GetOnlyCrm::$codes['invoices?limit=5&order=createdDate&direction=DESC'] . ')');
        else {
            $dates = array_map(function ($r) { return (string)($r['createdDate'] ?? ''); }, $inv);
            $desc = true; for ($i = 1; $i < count($dates); $i++) if (strcmp($dates[$i], $dates[$i - 1]) > 0) $desc = false;
            $say('the ' . count($inv) . ' latest invoices (newest first honoured: ' . ($desc ? 'yes' : 'NO — "latest" not established') . '):');
            foreach ($inv as $r) {
                if (!is_array($r)) continue;
                $num = array_key_exists('number', $r) ? $r['number'] : null; $inum = array_key_exists('invoiceNumber', $r) ? $r['invoiceNumber'] : null;
                $say('  number ' . (array_key_exists('number', $r) ? 'shape ' . $shape($num) : 'absent') . ' · invoiceNumber ' . (array_key_exists('invoiceNumber', $r) ? 'shape ' . $shape($inum) : 'absent')
                    . ((array_key_exists('number', $r) && array_key_exists('invoiceNumber', $r)) ? ' · equal ' . $yn((string)$num === (string)$inum) : '')
                    . ' · proforma key ' . $yn(array_key_exists('proforma', $r) || array_key_exists('isProforma', $r)) . ' · created ' . $stamp(str_replace('T', ' ', (string)($r['createdDate'] ?? ''))));
            }
        }
        $orgs = $crm->get('organizations');
        if (!is_array($orgs) || array_key_exists('raw', $orgs)) $say('organizations: not read (' . GetOnlyCrm::$codes['organizations'] . ')');
        else {
            $say(count($orgs) . ' organization(s):');
            foreach ($orgs as $o) {
                if (!is_array($o)) continue;
                $keys = [];
                foreach ($o as $k => $v) {
                    if (!preg_match('/(invoice|proforma|quote).*(prefix|length|init|number)/i', (string)$k)) continue;
                    if (is_string($v) && stripos((string)$k, 'prefix') !== false) $keys[] = $k . ' ' . ($v === '' ? '""' : 'shape ' . $shape($v));
                    elseif (is_int($v) || (is_string($v) && ctype_digit($v))) $keys[] = $k . ' ' . (stripos((string)$k, 'length') !== false ? (string)(int)$v : strlen((string)$v) . ' digits');
                    else $keys[] = $k . ' ' . gettype($v);
                }
                $say('  the quoting organization: ' . $yn(isset($o['id']) && is_int($qo['id'] ?? null) && (int)$o['id'] === $qo['id']) . ' · selected ' . $yn(!empty($o['selected']))
                    . ' · phone set ' . $yn(trim((string)($o['phone'] ?? '')) !== '') . ' · numbering: ' . ($keys ? implode(', ', $keys) : 'no such keys'));
            }
        }
    }

    // ── 7. The old auto-reply path (Part X) ──────────────────────────────────────────────────────────────────────────
    $head('7. The old auto-reply path (Part X): wa_webhook.php and the WhatsApp sync');
    foreach (['wa_bot_enabled', 'wa_auto_reply_enabled', 'wa_accounts_autoreply_enabled', 'wa_sync_enabled'] as $k) $say(sprintf('%-30s %s', $k, $both($k)));
    $say('  the old path answers (row: !empty(wa_bot_enabled) || !empty(wa_auto_reply_enabled)): ' . ($db === null ? 'not read' : $yn(!empty($row['wa_bot_enabled']) || !empty($row['wa_auto_reply_enabled'])))
        . ' · the sync job runs (row: (bool)(wa_sync_enabled ?? true)): ' . ($db === null ? 'not read' : $yn((bool)($row['wa_sync_enabled'] ?? true))));
    $say('wa_webhook_secret set: worker ' . $yn(trim((string)($cfg['wa_webhook_secret'] ?? '')) !== '') . ' · row ' . ($db === null ? 'not read' : $yn(trim((string)($row['wa_webhook_secret'] ?? '')) !== '')) . ' (empty = every POST is accepted)');
    $tz = new DateTimeZone(dn_tz($cfg));
    $since30 = (new DateTime('now', $tz))->modify('-30 days');
    $wl = $D . '/wa_webhook_log.json';
    if (!is_file($wl)) $say('wa_webhook_log.json: absent (nothing has ever posted to wa_webhook.php and been logged)');
    else {
        $L = json_decode((string)@file_get_contents($wl), true);
        if (!is_array($L)) $say('wa_webhook_log.json: unreadable or not a list');
        else {
            $by = []; $first = null; $last = null; $in = 0;
            foreach ($L as $e) {
                $t = is_array($e) ? (string)($e['received_at'] ?? '') : '';
                $dt = $t !== '' ? DateTime::createFromFormat('Y-m-d H:i:s', $t, $tz) : false;
                if ($dt === false) continue;
                if ($first === null || $dt < $first) $first = $dt; if ($last === null || $dt > $last) $last = $dt;
                if ($dt >= $since30) { $in++; $ev = (string)($e['event'] ?? '?'); $ev = preg_match('/^[a-z_]{1,30}$/', $ev) ? $ev : 'other'; $by[$ev] = ($by[$ev] ?? 0) + 1; }
            }
            $say('wa_webhook_log.json: ' . count($L) . ' entries (newest ' . ($last ? $last->format('Y-m-d\TH:i') : '-') . ', oldest ' . ($first ? $first->format('Y-m-d\TH:i') : '-') . '); last 30 days: ' . $in
                . ($by ? ' (' . implode(', ', array_map(function ($k, $v) { return $k . ' ' . $v; }, array_keys($by), $by)) . ')' : '')
                . ((count($L) >= 500 && $first && $first >= $since30) ? ' — all 500 kept entries are inside the window: a floor only' : ''));
        }
    }
    if ($db !== null) {
        $cnt = function (string $label, string $table, string $sql) use ($tbl, $db, $say, $q): void {
            if (!$tbl($table)) { $say($label . ': no ' . $table . ' table'); return; }
            try { $rows = $q($sql); $say($label . ': ' . ($rows ? implode(', ', array_map(function ($r) { $v = array_values($r); return count($v) > 1 ? (is_scalar($v[0]) && preg_match('/^[A-Za-z0-9 ()._:-]{0,40}$/', (string)$v[0]) ? $v[0] : 'other') . ' ' . (int)$v[1] : (string)(int)$v[0]; }, $rows)) : '0')); }
            catch (Throwable $e) { $say($label . ': could not be read (' . get_class($e) . ')'); }
        };
        if ($json1) $cnt('inbound stored by the old path (wa_messages, source webhook/cron), last 30 days', 'wa_messages', "SELECT json_extract(metadata,'$.source') s, COUNT(*) n FROM wa_messages WHERE direction = 'in' AND created_at >= datetime('now','-30 days') AND json_valid(metadata) AND json_extract(metadata,'$.source') IN ('webhook','cron') GROUP BY s");
        else $cnt('inbound stored by the old path (wa_messages, source webhook), last 30 days', 'wa_messages', "SELECT COUNT(*) FROM wa_messages WHERE direction = 'in' AND created_at >= datetime('now','-30 days') AND metadata LIKE '%\"source\":\"webhook\"%'");
        $cnt('the old path\'s replies (wa_messages), last 30 days', 'wa_messages', "SELECT agent_name, COUNT(*) n FROM wa_messages WHERE direction = 'out' AND ((role = 'bot' AND agent_name LIKE 'Auto-Reply (%') OR (role = 'agent' AND agent_name = 'DishNet Bot' AND metadata IS NULL)) AND created_at >= datetime('now','-30 days') GROUP BY agent_name");
        $cnt('conversations the old path answered (bot_replied_at), last 30 days', 'wa_conversations', "SELECT COUNT(*) FROM wa_conversations WHERE bot_replied_at >= datetime('now','-30 days','-3 hours')");
        $cnt('the old path\'s message-log events, last 30 days', 'notification_audit_log', "SELECT event, COUNT(*) n FROM notification_audit_log WHERE event IN ('auto_reply_support','auto_reply_accounts','wa_media_ack','wa_media_received','wa_prompt_risk') AND sent_at >= datetime('now','-30 days','-3 hours') GROUP BY event");
        $cnt('wa_media_debug rows, last 30 days', 'wa_media_debug', "SELECT COUNT(*) FROM wa_media_debug WHERE created_at >= datetime('now','-30 days')");
        $cnt('wa_pusher_processed rows, last 30 days', 'wa_pusher_processed', "SELECT COUNT(*) FROM wa_pusher_processed WHERE processed_at >= datetime('now','-30 days')");
        $cnt('support tickets from the old bot, last 30 days', 'support_tickets', "SELECT COUNT(*) FROM support_tickets WHERE source = 'whatsapp_bot' AND created_at >= datetime('now','-30 days')");
    }
    $pl = $P . '/data/plugin.log';
    if (!is_file($pl) || !is_readable($pl)) $say('plugin.log: ' . (is_file($pl) ? 'not readable by this user' : 'absent'));
    else {
        $h = fopen($pl, 'rb'); $first = null; $c = ['start' => 0, 'disabled' => 0, 'fetched' => 0, 'err_inbound' => 0, 'err_load' => 0, 'err_http' => 0];
        $cut = gmdate('Y-m-d H:i:s', time() - 30 * 86400); $lines = 0; $cur = '';
        while (($ln = fgets($h)) !== false) {
            $lines++;
            if (preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\]/', $ln, $m)) { $cur = $m[1]; if ($first === null) $first = $cur; }
            if ($cur === '' || $cur < $cut) continue;
            if (strpos($ln, '=== WA Sync Start ===') !== false) $c['start']++;
            elseif (strpos($ln, 'WA sync disabled.') !== false) $c['disabled']++;
            elseif (preg_match('/Fetched \d+ new messages/', $ln)) $c['fetched']++;
            elseif (strpos($ln, 'ERROR pkId=') !== false) { if (strpos($ln, 'WaInbound') !== false) $c['err_load']++; else $c['err_inbound']++; }
            elseif (preg_match('/ERROR: (curl failed|HTTP|Invalid response)/', $ln)) $c['err_http']++;
        }
        fclose($h);
        $say('plugin.log: ' . $lines . ' lines, first stamp ' . $stamp($first) . '; last 30 days — sync starts ' . $c['start'] . ', "sync disabled" ' . $c['disabled'] . ', "Fetched N new messages" ' . $c['fetched']
            . ', ERROR pkId (WaInbound load order) ' . $c['err_load'] . ', ERROR pkId (other) ' . $c['err_inbound'] . ', feed errors ' . $c['err_http']);
    }
    $ss = $D . '/wa_sync_state.json';
    $S = is_file($ss) ? json_decode((string)@file_get_contents($ss), true) : null;
    $say('wa_sync_state.json: ' . (!is_array($S) ? (is_file($ss) ? 'unreadable' : 'absent') : 'last sync ' . $stamp($S['last_sync_at'] ?? '') . ', last batch ' . (int)($S['last_batch_size'] ?? 0)
        . ', stored ' . (int)($S['last_stored'] ?? 0) . ', errors ' . (is_array($S['last_errors'] ?? null) ? count($S['last_errors']) : (int)($S['last_errors'] ?? 0)) . ', total ' . (int)($S['total_synced'] ?? 0)));

    // ── 8. The catalogue ─────────────────────────────────────────────────────────────────────────────────────────────
    $head('8. The catalogue (Part Q §D.2): names, prices and order as uCRM gives them');
    $money = function ($v): string { return is_numeric($v) ? 'UGX ' . number_format((float)$v, (floor((float)$v) == (float)$v) ? 0 : 2) : 'no price'; };
    $orgMatch = function (array $r) use ($qo): string {
        if (!array_key_exists('organizationId', $r)) return 'org absent';
        if ($r['organizationId'] === null) return 'org null';
        return 'org ' . ((is_int($qo['id'] ?? null) && (int)$r['organizationId'] === $qo['id']) ? 'the quoting one' : 'ANOTHER');
    };
    $flags = function (array $r) use ($orgMatch, $yn): string {
        return 'taxable ' . $yn(!empty($r['taxable']) || (array_key_exists('taxId', $r) && $r['taxId'] !== null))
            . (array_key_exists('isActive', $r) ? ', active ' . $yn($r['isActive'] !== false) : '')
            . (array_key_exists('archived', $r) ? ', archived ' . $yn($r['archived'] === true) : '') . ', ' . $orgMatch($r);
    };
    if ($crm === null) $say('not read: no uCRM connection');
    else {
        $ai = $cfg; $ai['catalogue_cache_seconds'] = 0;
        $tools = new DishNetTools(null, $ai, $P);
        $rp = new ReflectionProperty(DishNetTools::class, 'crm'); $rp->setAccessible(true); $rp->setValue($tools, $crm);
        $res = $tools->getProducts();
        // a failed GET is null, which getProducts() reads as an empty list and calls ok: the reader goes by what uCRM answered
        $cS = GetOnlyCrm::$codes['service-plans?limit=200'] ?? 'not asked'; $cP = GetOnlyCrm::$codes['products?limit=200'] ?? 'not asked';
        if (empty($res['ok']) || $cS !== 'ok' || $cP !== 'ok') $say('the catalogue could NOT be read from uCRM (service-plans: ' . $cS . ', products: ' . $cP . ')');
        else {
            $d = $res['data'];
            $rawP = is_array(GetOnlyCrm::$raw['products?limit=200'] ?? null) ? GetOnlyCrm::$raw['products?limit=200'] : [];
            $rawS = is_array(GetOnlyCrm::$raw['service-plans?limit=200'] ?? null) ? GetOnlyCrm::$raw['service-plans?limit=200'] : [];
            $say('uCRM gave ' . count($rawS) . ' service plan(s) and ' . count($rawP) . ' product(s)' . ((count($rawS) >= 200 || count($rawP) >= 200) ? ' — a list of 200 may have been cut' : '')
                . '; the assistant keeps ' . (int)$d['count'] . ' active plan(s), ' . (int)$d['hardware_count'] . ' one-time item(s), ' . (int)$d['accessory_count'] . ' accessory(ies), and drops ' . (int)$d['hardware_plan_mirrors'] . ' plan mirror(s)'
                . ($d['hardware_error'] !== null ? ' (products could not be read)' : ''));
            $say('');
            $say('PLANS, in uCRM\'s order:');
            foreach ($d['products'] as $i => $p) {
                $month = null;
                foreach ((array)($p['_raw']['periods'] ?? []) as $per) {
                    if (is_array($per) && (!isset($per['enabled']) || $per['enabled']) && (int)($per['period'] ?? 0) === 1 && isset($per['price']) && is_numeric($per['price'])) $month = (float)$per['price'];
                }
                $say(sprintf('  %2d. %s — %s every %s month(s); 1-month period %s; id %s', $i + 1, $j($p['name']), $money($p['price']), $j($p['period_months']), $money($month), $j($p['id'])));
            }
            $say('ONE-TIME ITEMS (the assistant\'s hardware list), in uCRM\'s order:');
            foreach ($d['hardware'] as $i => $h) $say(sprintf('  %2d. %s — %s; id %s; %s', $i + 1, $j($h['name']), $money($h['price']), $j($h['id']), $flags((array)$h['_raw'])));
            $say('ACCESSORIES, in uCRM\'s order:');
            foreach ($d['accessories'] as $i => $h) $say(sprintf('  %2d. %s — %s; id %s; %s', $i + 1, $j($h['name']), $money($h['price']), $j($h['id']), $flags((array)$h['_raw'])));

            $say('');
            $say('THE ITEMS 5.18.107 PRICES (exactly one row each is required):');
            $cat = ShopCatalogue::load($P);
            $hw = $d['hardware']; $acc = $d['accessories'];
            $findBy = function (array $rows, array $names): array {
                $keys = array_map([ShopCatalogue::class, 'nameKey'], $names); $o = [];
                foreach ($rows as $i => $r) if (in_array(ShopCatalogue::nameKey((string)($r['name'] ?? '')), $keys, true)) $o[] = [$i, $r];
                return $o;
            };
            $show = function (string $label, array $hits, string $where) use ($say, $j, $money): void {
                $say('  ' . $label . ': ' . count($hits) . ' in ' . $where . ($hits ? ' — ' . implode('; ', array_map(function ($h) use ($j, $money) { return '#' . ($h[0] + 1) . ' ' . $j($h[1]['name']) . ' ' . $money($h[1]['price']) . ' (id ' . $j($h[1]['id']) . ')'; }, $hits)) : ''));
            };
            foreach (['standard-kit' => 'the Standard kit', 'mini-kit' => 'the Mini kit'] as $slug => $label) {
                $it = ShopCatalogue::bySlug($cat, $slug);
                $show($label . ' (its match list)', $it ? $findBy($hw, $it['match']) : [], 'the one-time items');
            }
            $cand = [];
            foreach ($hw as $i => $r) { $k = DishNetTools::catalogueKey((string)($r['name'] ?? '')); if (strpos($k, 'mini') !== false || strpos($k, 'standard') !== false) $cand[] = '#' . ($i + 1) . ' ' . $j($r['name']); }
            $say('    one-time items naming "mini" or "standard": ' . ($cand ? implode('; ', $cand) : 'none'));
            foreach (['pivot-mount-standard' => 'the Standard Pivot Mount', 'pivot-mount-mini' => 'the Mini Pivot Mount'] as $slug => $label) {
                $it = ShopCatalogue::bySlug($cat, $slug);
                $m = $it ? $it['match'] : [];
                $show($label, $findBy($acc, $m), 'the accessories');
                $inHw = $findBy($hw, $m); if ($inHw) $show('  …and', $inHw, 'the one-time items (a spelling difference)');
                $rawHits = $findBy(array_map([DishNetTools::class, 'mapHardwareItem'], array_values(array_filter($rawP, 'is_array'))), $m);
                $say('    in the raw products: ' . count($rawHits));
            }
            foreach (['Residential (up to 350 Mbps)', 'Residential Lite (up to 100 Mbps)'] as $pn) {
                $key = DishNetTools::catalogueKey($pn); $hits = [];
                foreach ($d['products'] as $i => $p) if (DishNetTools::catalogueKey((string)($p['name'] ?? '')) === $key) $hits[] = [$i, $p];
                $show('the plan ' . $j($pn), $hits, 'the active plans');
            }
            $res2 = [];
            foreach ($d['products'] as $i => $p) if (strpos(DishNetTools::catalogueKey((string)($p['name'] ?? '')), 'residential') === 0) $res2[] = '#' . ($i + 1) . ' ' . $j($p['name']);
            $say('    every active plan starting "Residential": ' . ($res2 ? implode('; ', $res2) : 'none'));
            $ins = [];
            foreach (array_values(array_filter($rawP, 'is_array')) as $i => $r) if (stripos((string)($r['name'] ?? ''), 'install') !== false) $ins[] = '#' . ($i + 1) . ' ' . $j($r['name']) . ' ' . $money($r['price'] ?? null) . ' (id ' . $j($r['id'] ?? null) . ')';
            $say('  products naming "install" (shown only; the code never matches the installation by name): ' . ($ins ? implode('; ', $ins) : 'none'));

            $say('');
            $dup = function (string $label, array $rows, callable $key) use ($say, $j): void {
                $g = [];
                foreach ($rows as $r) { if (!is_array($r)) continue; $k = $key((string)($r['name'] ?? '')); if ($k !== '') $g[$k][] = (string)($r['name'] ?? ''); }
                $two = array_filter($g, function ($v) { return count($v) > 1; });
                $say('duplicates ' . $label . ': ' . ($two ? implode('; ', array_map(function ($v) use ($j) { return implode(' = ', array_map($j, $v)); }, $two)) : 'none'));
            };
            $dup('among the active plans', $d['products'], [DishNetTools::class, 'catalogueKey']);
            $dup('among the raw products (the catalogue key)', $rawP, [DishNetTools::class, 'catalogueKey']);
            $dup('among the raw products (the shop\'s exact-name key)', $rawP, [ShopCatalogue::class, 'nameKey']);
            $dup('among the one-time items', $hw, [DishNetTools::class, 'catalogueKey']);
        }
    }

    // ── 9. The installation link and the uCRM catalogue switch ───────────────────────────────────────────────────────
    $head('9. The 5.18.102 installation link and the uCRM catalogue switch (W.6)');
    $qoRow = $db === null ? null : QuoteOrganization::setting($row, $D);
    $qShow = function (array $s): string { return ($s['on'] ? 'on' : 'off') . ', source ' . $s['source'] . ($s['error'] !== '' ? ', error ' . $s['error'] : '') . ', id present ' . (is_int($s['id'] ?? null) ? 'yes' : 'no'); };
    $say('the quoting organization: worker ' . $qShow($qo) . ' · row ' . ($qoRow === null ? 'not read' : $qShow($qoRow)));
    $say('the uCRM catalogue gate open: worker ' . $yn(UcrmCatalogue::gateOpen($qo)) . ' · row ' . ($qoRow === null ? 'not read' : $yn(UcrmCatalogue::gateOpen($qoRow))));
    $recs = function (string $t) use ($db, $tbl): ?array {
        if ($db === null || !$tbl($t)) return null;
        $o = [];
        foreach ($db->query('SELECT data FROM [' . $t . '] ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) as $r) { $x = json_decode((string)$r, true); if (is_array($x)) $o[] = $x; }
        return $o;
    };
    if ($db === null) $say('the link and the switch: not read (the book was not opened)');
    else {
        $lr = $recs('ucrm_quote_links');
        $kits = [];
        if ($tbl('kyc_devices')) {
            $kc = $cols('kyc_devices');
            if ($kc === ['id', 'data']) $kits = $recs('kyc_devices') ?? [];
            else $kits = $q('SELECT * FROM kyc_devices ORDER BY id ASC');
        }
        if ($lr === null) $say('the installation link: no ucrm_quote_links table (no link was ever saved)');
        else {
            $links = UcrmQuoteLinks::fromRecords($lr, $kits);
            foreach (['worker' => $qo, 'row' => $qoRow] as $lab => $s) {
                $jd = $links->judge('install', null, is_int($s['id'] ?? null) ? $s['id'] : null);
                $rec = $jd['record'];
                $say('the installation link (' . $lab . '\'s organization): ' . $jd['state'] . ' · active records ' . count($jd['records'])
                    . ($rec ? ' · product id ' . $j($rec['product_id'] ?? null) . ' · its organization matches ' . $yn(is_int($s['id'] ?? null) && is_numeric($rec['organization_id'] ?? null) && (int)$rec['organization_id'] === $s['id']) . ' · linked ' . $stamp($rec['linked_at'] ?? '') : ''));
            }
            if ($crm !== null && UcrmCatalogue::gateOpen($qo)) {
                $token = trim((string)($cfg['crm_auth_token'] ?? ''));
                if ($token === '') { $qc = $crm; $kind = 'the plugin\'s own key'; }
                else { $url = trim((string)($cfg['crm_base_url'] ?? '')); $qc = new GetOnlyCrm($url !== '' ? $url : rtrim($rawCrm->getBaseUrl(), '/'), $token, 'x-auth-token'); $kind = 'the admin token'; }
                $line = (new UcrmQuoteLines($qc, $qo, $links))->installLine();
                $l = $line['line'] ?? null;
                $say('the installation at use (UcrmQuoteLines::installLine, with ' . $kind . '): ' . (!empty($line['ok']) ? 'ok' : 'REFUSED, why ' . $j($line['why'] ?? ''))
                    . (is_array($l) ? ' · ' . $j($l['label'] ?? ($l['description'] ?? null)) . ' ' . $money($l['price'] ?? null) . ' · product id ' . $j($l['productId'] ?? null) : ''));
            }
        }
        $cr = $recs('ucrm_catalogue');
        if ($cr === null) $say('the uCRM catalogue switch: no ucrm_catalogue table (never switched) → off');
        else {
            $inForce = function (string $k) use ($cr): int { $n = 0; foreach ($cr as $r) if (($r['kind'] ?? null) === $k && ($r['ended_at_ts'] ?? null) === null) $n++; return $n; };
            $say('the uCRM catalogue switch: mode records in force ' . $inForce('mode') . ' · direct mode on (worker gate) ' . $yn(UcrmCatalogue::fromRecords($cr, UcrmCatalogue::gateOpen($qo))->direct())
                . ' · hidden ' . $inForce('hidden') . ', standard ' . $inForce('standard') . ', install ' . $inForce('install') . ' record(s) in force');
        }
    }

    // ── 10. Bursts ───────────────────────────────────────────────────────────────────────────────────────────────────
    $head('10. Bursts (W.1): typed ai.reply events with a newer typed one for the same conversation within 15 minutes');
    if ($db === null) $say('not read: the book was not opened');
    elseif (!$tbl('events')) $say('no events table');
    else {
        $notVoice = $json1 ? "COALESCE(CASE WHEN json_valid(payload) THEN json_extract(payload,'$.origin') END,'') <> 'voice'" : "payload NOT LIKE '%\"origin\":\"voice\"%'";
        $chan = $json1 ? "CASE WHEN json_valid(payload) THEN json_extract(payload,'$.channel') END" : "''";
        $base = "WITH typed AS (SELECT id, entity_id, created_at, status, $chan AS channel FROM events WHERE event_type = 'ai.reply' AND created_by = 'evo_webhook'"
              . " AND entity_type = 'conversation' AND entity_id > 0 AND $notVoice AND created_at >= datetime('now','-7 days')),"
              . " flagged AS (SELECT t.*, EXISTS (SELECT 1 FROM typed n WHERE n.entity_id = t.entity_id AND n.id > t.id AND n.created_at <= datetime(t.created_at,'+15 minutes')) AS has_newer FROM typed t) ";
        $r = $q($base . 'SELECT COUNT(*) typed, COALESCE(SUM(has_newer),0) newer, COUNT(DISTINCT CASE WHEN has_newer THEN entity_id END) convs, COUNT(DISTINCT entity_id) allconvs FROM flagged')[0];
        $say('last 7 days: ' . (int)$r['typed'] . ' typed events in ' . (int)$r['allconvs'] . ' conversations; ' . (int)$r['newer'] . ' had a newer typed one within 15 minutes, in ' . (int)$r['convs'] . ' conversations');
        if ($json1) $say('  by number: ' . implode(', ', array_map(function ($x) use ($cls) { return $cls(['k' => $x['channel']], 'k') . ' ' . (int)$x['newer'] . '/' . (int)$x['n']; }, $q($base . 'SELECT channel, COUNT(*) n, COALESCE(SUM(has_newer),0) newer FROM flagged GROUP BY channel ORDER BY channel'))));
        $say('  the older event\'s status (with a newer one): ' . implode(', ', array_map(function ($x) use ($cls) { return $cls(['k' => $x['status']], 'k') . ' ' . (int)$x['n']; }, $q($base . 'SELECT status, COUNT(*) n FROM flagged WHERE has_newer GROUP BY status ORDER BY status'))));
        $say('  typed events with no conversation (entity_id 0), last 7 days: ' . (int)$db->query("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply' AND created_by = 'evo_webhook' AND entity_id = 0 AND created_at >= datetime('now','-7 days')")->fetchColumn());
        $say('  how far back ai.reply events reach: ' . implode(', ', array_map(function ($x) use ($cls, $stamp) { return $cls(['k' => $x['status']], 'k') . ' ' . (int)$x['n'] . ' since ' . $stamp($x['m']); }, $q("SELECT status, COUNT(*) n, MIN(created_at) m FROM events WHERE event_type = 'ai.reply' GROUP BY status ORDER BY status"))));
    }

    // ── 11. Transcription ────────────────────────────────────────────────────────────────────────────────────────────
    $head('11. Transcription (W.4, W-O5)');
    $sm = $cfg['ai_media_openai_stt_model'] ?? null;
    $smt = trim((string)($sm ?? ''));
    $say('ai_media_openai_stt_model: worker ' . ($sm === null ? 'not set' : ($smt === '' ? 'empty' : (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $smt) ? '"' . $smt . '"' : $txt($smt))))
        . ' · row ' . ($db === null ? 'not read' : $cls($row, 'ai_media_openai_stt_model')) . ' → the model in use: ' . (MediaPolicy::sttModel($cfg) === '' ? 'NONE (not on the allow-list: no call is made)' : MediaPolicy::sttModel($cfg)));
    if ($db === null) $say('not read: the book was not opened');
    else {
        if ($tbl('wa_media')) {
            $say('transcripts, last 7 days, by model and rung: ' . (($x = $q("SELECT COALESCE(provider_model,'') model, COALESCE(voice_rung,'') rung, COUNT(*) n FROM wa_media WHERE kind = 'audio' AND status = 'understood' AND provider_model IS NOT NULL AND provider_model <> '' AND created_at >= datetime('now','-7 days') GROUP BY model, rung ORDER BY n DESC"))
                ? implode(', ', array_map(function ($r) use ($cls) { return $cls(['k' => $r['model']], 'k') . '/' . $cls(['k' => $r['rung']], 'k') . ' ' . (int)$r['n']; }, $x)) : 'none'));
            $say('the last ten transcribed notes: ' . (($x = $q("SELECT provider_model, voice_rung, created_at FROM wa_media WHERE kind = 'audio' AND status = 'understood' AND provider_model IS NOT NULL ORDER BY id DESC LIMIT 10"))
                ? implode('; ', array_map(function ($r) use ($cls, $stamp) { return $cls(['k' => $r['provider_model']], 'k') . ' ' . $cls(['k' => $r['voice_rung']], 'k') . ' ' . $stamp($r['created_at']); }, $x)) : 'none'));
            $say('voice notes by rung recorded, last 7 days: ' . (($x = $q("SELECT COALESCE(voice_rung,'(none)') rung, COUNT(*) n FROM wa_media WHERE kind = 'audio' AND created_at >= datetime('now','-7 days') GROUP BY rung ORDER BY rung"))
                ? implode(', ', array_map(function ($r) use ($cls) { return $cls(['k' => $r['rung']], 'k') . ' ' . (int)$r['n']; }, $x)) : 'none'));
        } else $say('no wa_media table');
        if ($tbl('wa_voice_calls')) {
            $say('provider calls, last 7 days, by model, outcome and reason: ' . (($x = $q("SELECT model, outcome, COALESCE(reason,'') reason, COUNT(*) n FROM wa_voice_calls WHERE created_at >= datetime('now','-7 days') GROUP BY model, outcome, reason ORDER BY n DESC"))
                ? implode(', ', array_map(function ($r) use ($cls) { return $cls(['k' => $r['model']], 'k') . '/' . $cls(['k' => $r['outcome']], 'k') . ($r['reason'] !== '' ? '/' . $cls(['k' => $r['reason']], 'k') : '') . ' ' . (int)$r['n']; }, $x)) : 'none'));
        } else $say('no wa_voice_calls table');
    }
    $say('not read here: step 7\'s own check (tools/sales_followup.php --check voice-dry-run), due on 26 Nov');

    // ── the end ──────────────────────────────────────────────────────────────────────────────────────────────────────
    $head('Done');
    if ($db !== null) {
        $tc = (int)$db->query('SELECT total_changes()')->fetchColumn();
        $db->exec('ROLLBACK'); $db = null;
        $say('the book: changes made ' . $tc . ($tc === 0 ? ' (none)' : ' — UNEXPECTED'));
        if ($tc !== 0) $rc = 1;
    }
    $say('uCRM asked (GET only): ' . (GetOnlyCrm::$asked ? implode(', ', array_map(function ($p) { return preg_replace('/\?.*$/', '', $p) . ' ' . (GetOnlyCrm::$codes[$p] ?? '?'); }, GetOnlyCrm::$asked)) : 'nothing') . '; anything else refused: ' . GetOnlyCrm::$refused);
} catch (Throwable $e) {
    $say('STOPPED at ' . basename($e->getFile()) . ':' . $e->getLine() . ' — ' . get_class($e) . '; nothing was changed');
    if ($db !== null) { try { $db->exec('ROLLBACK'); } catch (Throwable $e2) {} }
    $rc = 1;
}
$say('runs of 9+ digits withheld: ' . $n9);
$say($rc === 0 ? 'READ-ONLY — nothing was changed' : 'READ-ONLY — nothing was changed (the reading did not finish; send the log as it is)');
exit($rc);
