<?php
declare(strict_types=1);
/**
 * Shared by Part V's media-chain tests (5.18.104, root docs/73 §H.3.24): tests/test_voice_media.php and its sections
 * (tests/voice_media/<Id>_*.php), tests/test_media_foundation.php, tests/test_migration_090.php,
 * tests/test_voice_duration.php, tests/test_openai_transcriber.php, tests/test_voice_key_never_printed.php and
 * tests/test_voice_south_sudan.php. TEST ONLY.
 *
 * Built on stage B1's harness (tests/quote_followup/_lib.php): QfCtx, the tree copy every class is loaded from, a fresh
 * book on the copy's migrations, the repository's fake Evolution, and the weakened-copy runner. Added here: the voice
 * configuration, a book whose kyc_config.json is the configuration (the media worker reads it afresh per event, ruling
 * R19), a voice note stored as the webhook stores it, the fake OpenAI on loopback, and the worker run in process.
 * Every person, number, key and message is fictitious; nothing leaves 127.0.0.1.
 */
if (!defined('QF_TZ')) throw new \LogicException('the test file names the zone (QF_TZ) before it loads voice_media/_lib.php');
require_once dirname(__DIR__) . '/quote_followup/_lib.php';
require_once dirname(__DIR__) . '/fixtures/ogg_opus.php';

/** The planted OpenAI key: fictitious, and searched for everywhere a key must never appear (§H.3.18). */
const VM_KEY = 'sk-test-VMSENTINEL-7f3a9c1e5b2d4a6f8e0c';
/** A Privacy Policy publish date in the past (§H.3.23). */
const VM_PRIVACY = '2026-10-20';
/** 221c9dc, the 5.18.103 release this part is judged against. */
const VM_BASE = '221c9dc';

/** Load the copy's media classes (once per process). */
function vm_load(QfCtx $ctx): string
{
    $t = $ctx->tree();
    foreach (['lib/StoreInterface.php', 'lib/SqliteStore.php', 'lib/PluginConfig.php', 'lib/EventBus.php', 'lib/UtcClock.php',
              'lib/StaffJobsGate.php', 'lib/ConversationService.php', 'lib/EvolutionApiService.php', 'lib/MediaPolicy.php',
              'lib/MediaBlob.php', 'lib/InboundMedia.php', 'lib/MediaFetcher.php', 'lib/Transcriber.php', 'lib/VoiceDuration.php',
              'lib/VoiceBudget.php', 'lib/OpenAiTranscriber.php', 'lib/VoiceTranscription.php', 'lib/Handover.php',
              'lib/AlertService.php', 'lib/ContactOptOut.php', 'lib/LineOwner.php', 'workers/WorkerBase.php',
              'workers/MediaWorker.php'] as $f) {
        require_once $t . '/' . $f;
    }
    return $t;
}

/**
 * P5's predicate, for the copies of a tree that does not hold lib/VoiceAck.php yet (stage B6 builds it): exactly
 * §H.3.5's `VoiceAck::enabled` = StaffJobsGate::applies && ai_enabled && wa_voice_ack_enabled. Written into the COPY
 * only, and only when the file is absent; the real file, once it exists, is what the tests then use.
 */
function vm_voiceack(QfCtx $ctx): void
{
    $f = $ctx->tree() . '/lib/VoiceAck.php';
    if (is_file($f)) return;
    file_put_contents($f, "<?php\ndeclare(strict_types=1);\n// TEST STAND-IN written into a test's copy (tests/voice_media/_lib.php): root docs/73 §H.3.5's predicate only.\n"
        . "final class VoiceAck\n{\n    public static function enabled(array \$config, ?string \$dataDir): bool\n    {\n"
        . "        \$on = function (\$v): bool { return is_bool(\$v) ? \$v : in_array(strtolower(trim((string)\$v)), ['1', 'true', 'on', 'yes'], true); };\n"
        . "        if (!\$on(\$config['ai_enabled'] ?? null) || !\$on(\$config['wa_voice_ack_enabled'] ?? null)) return false;\n"
        . "        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';\n"
        . "        return StaffJobsGate::applies(\$config, \$dataDir);\n    }\n}\n");
}

/** A Uganda configuration with the media switch on (R1) and the fake Evolution; every rung above it off. */
function vm_cfg(string $evo, array $over = []): array
{
    $c = [
        'tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'ai_enabled' => true, 'ai_media_enabled' => '1',
        'ai_media_since' => '2026-01-01 00:00:00',
        'evo_api_url' => $evo, 'evo_api_key' => 'vm-evo-key',
        'evo_instance_sales' => 'Sales-UG', 'evo_instance_support' => 'DishNet-UG', 'evo_instance_account' => 'DishNet-UG',
        'alert_whatsapp' => '256700000999', 'alert_hours_from' => 0, 'alert_hours_to' => 24,
        'ai_handover_message' => 'VM-HOLDING-LINE: a colleague will reply shortly.',
    ];
    foreach ($over as $k => $v) {
        if ($v === null) unset($c[$k]); else $c[$k] = $v;
    }
    return $c;
}

/** The keys of each rung, cumulative (§H.3.5): fetch_only, dry_run, handover, reply (with its channel list). */
function vm_rung(string $rung, string $channels = 'all'): array
{
    $k = ['ai_media_enabled' => '1'];
    if ($rung === 'fetch_only') return $k;
    $k += ['ai_media_voice' => '1', 'ai_transcription_provider' => 'openai', 'ai_media_voice_privacy_line' => VM_PRIVACY,
           'openai_api_key' => VM_KEY];
    if ($rung === 'dry_run') return $k;
    $k += ['ai_media_voice_handover' => '1'];
    if ($rung === 'handover') return $k;
    return $k + ['ai_media_voice_reply' => '1', 'ai_media_voice_reply_channels' => $channels,
                 'ai_media_voice_reply_approved' => '2026-10-25'];
}

/** A fresh book whose kyc_config.json is $cfg; DN_DATA_DIR points at it (the worker reads it there). */
function vm_book(QfCtx $ctx, array $cfg, string $tag = 'vm'): array
{
    vm_load($ctx);
    $b = $ctx->book($cfg, $tag);
    $b['cfg'] = $cfg;
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg));
    putenv('DN_DATA_DIR=' . $b['data']);
    return $b;
}

/** Change the configuration on disk (a null unsets a key) — what the switch tool's `on`/`off` would leave there. */
function vm_setcfg(array &$b, array $over): void
{
    foreach ($over as $k => $v) {
        if ($v === null) unset($b['cfg'][$k]); else $b['cfg'][$k] = $v;
    }
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($b['cfg']));
}

/** An Evolution messages.upsert envelope carrying a voice note (or another kind). */
function vm_envelope(string $phone, array $o = []): array
{
    $kind = (string)($o['kind'] ?? 'audio');
    $id   = (string)($o['id'] ?? ('VM-' . strtoupper($kind) . '-' . bin2hex(random_bytes(5))));
    $part = ['audio' => ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'seconds' => (int)($o['seconds'] ?? 3),
                                            'fileLength' => (int)($o['length'] ?? 6000)] + (array_key_exists('ptt', $o) ? ($o['ptt'] ? ['ptt' => true] : []) : ['ptt' => true])],
             'image' => ['imageMessage' => ['mimetype' => 'image/jpeg', 'fileLength' => 2000]],
             'document' => ['documentMessage' => ['mimetype' => 'application/pdf', 'fileName' => 'vm.pdf', 'fileLength' => 2000]],
             'text' => ['conversation' => (string)($o['text'] ?? 'VM hello')]][$kind];
    $message = $part;
    if (!empty($o['wrap'])) $message = [(string)$o['wrap'] => ['message' => $part]];
    return ['key' => ['id' => $id, 'fromMe' => !empty($o['from_me']), 'remoteJid' => $phone . '@s.whatsapp.net'],
            'message' => $message, 'messageType' => array_keys($part)[0], 'messageTimestamp' => time(), 'pushName' => 'VM'];
}

/**
 * A voice note stored as the webhook stores it (the [AUDIO] row, then the wa_media row, then its ai.media event).
 * @return array{conv:int, media:int, wa:string, event:int, msg:int}
 */
function vm_voice(array $b, string $phone, string $channel = 'sales', array $o = []): array
{
    $env = vm_envelope($phone, $o);
    $cs  = new ConversationService($b['data'], $b['pdo']);
    $conv = (int)$cs->importEvoMessage($env, $channel);
    $media = InboundMedia::fromEvoMessage($env);
    $id = (int)InboundMedia::record($b['pdo'], $conv, $media, (string)($o['instance'] ?? 'Sales-UG'), $channel, (string)$env['key']['id']);
    $ev = (new EventBus($b['pdo']))->emit('ai.media', 'media', $id, ['media_id' => $id], 3, 'evo_webhook');
    $msg = (int)$b['pdo']->query('SELECT id FROM wa_messages WHERE wa_message_id = ' . $b['pdo']->quote((string)$env['key']['id']))->fetchColumn();
    return ['conv' => $conv, 'media' => $id, 'wa' => (string)$env['key']['id'], 'event' => (int)$ev, 'msg' => $msg];
}

/** What the fake Evolution answers for one message's fetch. */
function vm_media(string $evo, string $waId, string $bytes, string $mime = 'audio/ogg; codecs=opus', array $o = []): void
{
    qf_http('POST', $evo . '/__test/media', ['base64' => base64_encode($bytes), 'mimetype' => $mime, 'for_id' => $waId] + $o);
}

function vm_evo_state(string $evo): array
{
    return (array)(qf_http('GET', $evo . '/__test/state')[1] ?? []);
}

/** The repository's fake OpenAI (tests/fixtures/fake_openai_server.php) on loopback, reset; its base URL, or ''. */
function vm_fake_openai(): string
{
    static $base = null;
    if ($base !== null) { qf_http('GET', $base . '/__test/reset'); return $base; }
    [$port] = qf_serve(escapeshellarg(dirname(__DIR__) . '/fixtures/fake_openai_server.php'), function (int $p): bool {
        return strpos(qf_http('GET', "http://127.0.0.1:{$p}/__test/state", null, [], 2)[2], 'FAKE-OPENAI-TEST') !== false;
    }, 13800);
    $base = $port === 0 ? '' : "http://127.0.0.1:{$port}";
    if ($base !== '') qf_http('GET', $base . '/__test/reset');
    return $base;
}

function vm_oa_next(string $oa, array $answers): void { qf_http('POST', $oa . '/__test/next', $answers); }
function vm_oa_calls(string $oa): array { return (array)((qf_http('GET', $oa . '/__test/state')[1] ?? [])['calls'] ?? []); }

/** The adapter pointed at the fake, built from a configuration (as the factory builds it from the fresh read). */
function vm_adapter(string $oa, array $cfg): OpenAiTranscriber
{
    return new OpenAiTranscriber($cfg, $oa . '/v1/audio/transcriptions');
}

/**
 * The media worker, in process, as run_media_worker.php constructs it ($o: max, batch, deadline), with the adapter
 * against the fake OpenAI injected unless $o['transcriber'] says otherwise (false: no provider at all). A test never lets
 * the factory build the adapter: its endpoint is OpenAI's own.
 * The worker's own configuration is $o['cfg'] (a stale read, for the ruling R19 cases) or the book's.
 */
function vm_run(array $b, string $oa, array $o = []): array
{
    $cfg = $o['cfg'] ?? $b['cfg'];
    $w = new MediaWorker($b['store'], $cfg, (int)($o['max'] ?? 90), (int)($o['batch'] ?? 1), $o['deadline'] ?? null);
    if (array_key_exists('transcriber', $o)) {
        $w->useTranscriber($o['transcriber'] === false ? null : $o['transcriber']);   // never the factory: it would name OpenAI itself
    } elseif ($oa !== '') {
        $w->useTranscriber(vm_adapter($oa, $b['cfg'] + ['openai_api_key' => VM_KEY]));
    }
    if (isset($o['before']) && is_callable($o['before'])) ($o['before'])($w);
    ob_start();
    try { $r = $w->run(); } finally { $log = (string)ob_get_clean(); }
    return ['run' => $r, 'log' => $log, 'worker' => $w];
}

/** Make every failed ai.media event due again (the EventBus backoff passed). */
function vm_due(array $b): void
{
    $b['pdo']->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE event_type = 'ai.media' AND status IN ('failed', 'pending')");
}

function vm_row(array $b, int $mediaId): array
{
    $r = $b['pdo']->query('SELECT * FROM wa_media WHERE id = ' . $mediaId)->fetch(\PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}

function vm_q(array $b, string $sql, array $p = []): array
{
    $st = $b['pdo']->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

/** Sends the fake Evolution received since $n: [instance, number, text] each. */
function vm_texts(string $evo, int $from = 0): array
{
    $out = [];
    foreach (array_slice((array)(vm_evo_state($evo)['text_calls'] ?? []), $from) as $c) $out[] = [(string)$c['instance'], (string)$c['number'], (string)$c['text']];
    return $out;
}

/** 221c9dc's plugin, from Git (tests and docs left out), extracted once; null when Git cannot supply it. */
function vm_base(QfCtx $ctx): ?string
{
    static $base = false;
    if ($base !== false) return $base;
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --show-toplevel 2>/dev/null'));
    $x = $ctx->tmp . '/base';
    @mkdir($x, 0700, true);
    $o = [];
    exec('git -C ' . escapeshellarg($top) . ' archive ' . VM_BASE . ' dishnet-hybrid-sudan | tar -x -C ' . escapeshellarg($x) . ' 2>&1', $o, $rc);
    $p = $x . '/dishnet-hybrid-sudan';
    if ($top === '' || $rc !== 0 || !is_file($p . '/evo_webhook.php')) return $base = null;
    exec('rm -rf ' . escapeshellarg($p . '/docs') . ' ' . escapeshellarg($p . '/prototype'));
    @mkdir($p . '/data', 0700, true);
    return $base = $p;
}

/** Run a PHP snippet in its own process with the given environment; [rc, stdout]. */
function vm_php(string $code, array $env = [], int $timeout = 120): array
{
    $f = tempnam(sys_get_temp_dir(), 'vmphp') . '.php';
    file_put_contents($f, $code);
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open('exec timeout ' . (int)$timeout . ' php ' . escapeshellarg($f), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $e);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    @unlink($f);
    return [$rc, (string)$out . ($rc !== 0 ? "\n[stderr] " . substr((string)$err, 0, 2000) : '')];
}

/** Every file under $dir (recursively) whose bytes contain $needle. */
function vm_files_with(string $dir, string $needle): array
{
    $hit = [];
    if (!is_dir($dir)) return $hit;
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getSize() > 50 * 1024 * 1024) continue;
        $s = @file_get_contents($f->getPathname());
        if (is_string($s) && $needle !== '' && strpos($s, $needle) !== false) $hit[] = $f->getPathname();
    }
    return $hit;
}

/**
 * Run run_media_worker.php as its own process against a book: from a folder that links every entry of the copy but
 * holds its own run_media_worker.php and a ucrm.json naming the book's data folder (getDataDir reads it there).
 * $master: null runs it as the command line does (with $args, e.g. ['--spawned']); an int includes it as
 * cron/master.php does, with $_m_deadline that many seconds from now. [rc, output].
 */
function vm_cli(QfCtx $ctx, array $b, array $args = [], ?int $master = null): array
{
    $t = $ctx->tree();
    $d = $ctx->dir('cli');
    foreach (scandir($t) ?: [] as $e) {
        if ($e === '.' || $e === '..' || $e === 'run_media_worker.php' || $e === 'ucrm.json') continue;
        symlink($t . '/' . $e, $d . '/' . $e);
    }
    copy($t . '/run_media_worker.php', $d . '/run_media_worker.php');
    file_put_contents($d . '/ucrm.json', json_encode(['pluginDataDir' => $b['data']]));
    $env = ['DN_DATA_DIR' => $b['data'], 'DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE'), 'TMPDIR' => (string)getenv('TMPDIR')];
    if ($master === null) {
        $code = '<?php $argv = ' . var_export(array_merge([$d . '/run_media_worker.php'], $args), true) . '; include ' . var_export($d . '/run_media_worker.php', true) . ';';
    } else {
        $code = '<?php $argv = ["cron/master.php"]; $_m_deadline = time() + ' . (int)$master . '; include ' . var_export($d . '/run_media_worker.php', true) . ';';
    }
    return vm_php($code, $env, 180);
}
