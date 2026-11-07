<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * sales_followup.php — the one switch tool of 5.18.104 (Uganda only; root docs/73 §H.2.12; contract C1, C6).
 *
 *   php tools/sales_followup.php show
 *   php tools/sales_followup.php on <step> [--opt value | --opt=value] [--accept-not-yet "<reason>"]
 *   php tools/sales_followup.php skip <step> --reason "<text>"
 *   php tools/sales_followup.php off <step>|sending|all [--rollback]
 *   php tools/sales_followup.php --check [<step>]
 *   php tools/sales_followup.php doctor
 *   php tools/sales_followup.php set <key|alias> <value> [<value>]
 *   php tools/sales_followup.php erase                     (reads one number from the terminal, echo off)
 *
 * Steps, in their one order: report (1), agent-cc (1b, optional), ack (2), tracking (3), list (4), voice-measure (5),
 * chase (6), voice-dry-run (7), voice-staff (8), voice-reply (9), cold (10, optional), offers-test (11), offers-live (12).
 * The table is lib/SalesFollowupLadder.php; a switch refuses to come on while an earlier step is off (C6).
 *
 * CLI only, as tools/set_config.php is. It refuses unless this process's uid owns the data folder, and every command
 * that writes refuses on any tenant but Uganda. Keys are written only through PluginConfig::saveOverrides (which refuses
 * secrets, so the OpenAI key can never be set here). Two connections, both opened here and by nothing it calls (ruling
 * R13): $ro, read-only (PRAGMA query_only=ON), for every check, precondition, read-back, `show`, `doctor` and the
 * offers preview, with total_changes() asserted 0 on it at the end; and $rw, SqliteStore::create()'s, opened only by a
 * command that writes and only when plugin.sqlite3 already exists, for `erase` and the off-hooks. Output is counts,
 * fixed codes and dates; a handset is named by role and index; never a phone, a name or a secret. Exit 0 done, 2
 * refused (nothing saved), 3 a check, a hook or an assertion failed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/UtcClock.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/PluginLog.php';
require_once $root . '/lib/StaffJobsGate.php';
require_once $root . '/lib/SalesFollowupLadder.php';

$SF_NOW  = time();
$SF_ARGS = sf_parse(array_slice($argv, 1));
$SF_DATA = cliDataDir($root);
exit(sf_main($root, $SF_DATA, $SF_ARGS, $SF_NOW));

// ── the command line ────────────────────────────────────────────────────────────────────────────────────────────────

/** [command, positionals, options]: every option as `--opt value` or `--opt=value` (ruling R5); --rollback is a flag. */
function sf_parse(array $args): array
{
    $cmd = (string)($args[0] ?? '');
    $pos = []; $opts = [];
    for ($i = 1, $n = count($args); $i < $n; $i++) {
        $a = (string)$args[$i];
        if (strpos($a, '--') !== 0) { $pos[] = $a; continue; }
        $name = substr($a, 2);
        if (strpos($name, '=') !== false) { [$name, $v] = explode('=', $name, 2); $opts[$name] = $v; continue; }
        if ($name === 'rollback') { $opts[$name] = true; continue; }
        if ($i + 1 < $n && strpos((string)$args[$i + 1], '--') !== 0) { $opts[$name] = (string)$args[++$i]; continue; }
        $opts[$name] = '';
    }
    if ($cmd === '--check') { $cmd = 'check'; }
    elseif (strpos($cmd, '--check=') === 0) { array_unshift($pos, substr($cmd, 8)); $cmd = 'check'; }
    return [$cmd, $pos, $opts];
}

function sf_out(string $line): void { echo $line . "\n"; }

function sf_refuse(string $code, array $lines = []): int
{
    foreach ($lines as $l) sf_out('  ' . $l);
    sf_out('REFUSED ' . $code . ' — nothing was saved');
    return 2;
}

function sf_main(string $root, string $dataDir, array $parsed, int $now): int
{
    [$cmd, $pos, $opts] = $parsed;
    $writes = in_array($cmd, ['on', 'off', 'skip', 'set', 'erase'], true);
    $reads  = in_array($cmd, ['show', 'check', 'doctor'], true);
    if (!$writes && !$reads) { sf_usage(); return $cmd === '' || $cmd === 'help' || $cmd === '--help' ? 0 : 2; }

    // Rule 1: the data folder's owner, and Uganda.
    if (!is_dir($dataDir)) return sf_refuse('no_data_folder');
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    if ($uid < 0 || @fileowner($dataDir) !== $uid) return sf_refuse('not_owner', ['run it as the user that owns the data folder']);
    $config = PluginConfig::read($root, $dataDir);
    if (!StaffJobsGate::applies($config, $dataDir)) {
        if ($cmd === 'show') { sf_out('not this install'); return 0; }
        return sf_refuse('not_uganda', ['5.18.104 switches exist on Uganda only']);
    }
    $db = rtrim($dataDir, '/') . '/plugin.sqlite3';
    if ($writes && !is_file($db)) return sf_refuse('no_book', ['plugin.sqlite3 does not exist here, and this tool never creates one']);

    $ro = sf_ro($db);
    $rc = 0;
    switch ($cmd) {
        case 'show':   $rc = sf_show($ro, $root, $dataDir, $config, $now); break;
        case 'check':  $rc = sf_check_cmd($ro, $root, $dataDir, $config, $pos, $now); break;
        case 'doctor': $rc = sf_doctor($ro, $root, $dataDir); break;
        case 'on':     $rc = sf_on($ro, $root, $dataDir, $config, $pos, $opts, $now); break;
        case 'skip':   $rc = sf_skip($ro, $root, $dataDir, $config, $pos, $opts, $now); break;
        case 'off':    $rc = sf_off($ro, $root, $dataDir, $config, $pos, $opts, $now); break;
        case 'set':    $rc = sf_set($ro, $root, $dataDir, $config, $pos, $now); break;
        case 'erase':  $rc = sf_erase($root, $dataDir, $config, $now); break;
    }
    // Ruling R13: the read-only connection wrote nothing, whoever was given it.
    $changed = (int)$ro->query('SELECT total_changes()')->fetchColumn();
    if ($changed !== 0) { sf_out('FAIL ro_changed — the read-only connection changed ' . $changed . ' rows'); return 3; }
    return $rc;
}

function sf_usage(): void
{
    sf_out('usage: sales_followup.php show | on <step> [options] | skip <step> --reason "<text>" | off <step>|sending|all [--rollback]');
    sf_out('       | --check [<step>] | doctor | set <key|alias> <value> | erase');
    sf_out('steps: ' . implode(', ', SalesFollowupLadder::steps()));
}

/** $ro: a second connection to the live book, query_only, never SqliteStore::create (an empty memory book when none exists). */
function sf_ro(string $db): \PDO
{
    $ro = new \PDO(is_file($db) ? 'sqlite:' . $db : 'sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $ro->exec('PRAGMA busy_timeout = 5000');
    $ro->exec('PRAGMA query_only = ON');
    return $ro;
}

/** $rw: the one writable connection (ruling R13): SqliteStore::create()'s, on a book that already exists. */
function sf_rw(string $root, string $dataDir): \PDO
{
    require_once $root . '/lib/StoreInterface.php';
    require_once $root . '/lib/SqliteStore.php';
    return SqliteStore::create($dataDir)->getPdo();
}

/** A memoised checker over ruling R13's dispatch: each check runs once per command, on $ro. */
function sf_checker(\PDO $ro, string $root, string $dataDir, array &$config, int $now): callable
{
    $memo = [];
    return function (string $id) use (&$memo, $ro, $root, $dataDir, &$config, $now): array {
        if (!isset($memo[$id])) $memo[$id] = SalesFollowupLadder::check($ro, $config, $dataDir, $root, $id, $now);
        return $memo[$id];
    };
}

/** Write keys through PluginConfig::saveOverrides only; null unsets (never `none`, never 0). */
function sf_save(string $dataDir, array $changes): bool
{
    if ($changes === []) return true;
    [$ok] = PluginConfig::saveOverrides($dataDir, $changes);
    return (bool)$ok;
}

// ── --check ─────────────────────────────────────────────────────────────────────────────────────────────────────────

function sf_check_cmd(\PDO $ro, string $root, string $dataDir, array $config, array $pos, int $now): int
{
    $checker = sf_checker($ro, $root, $dataDir, $config, $now);
    $step = (string)($pos[0] ?? '');
    if ($step === '') {
        $top = null;
        foreach (SalesFollowupLadder::steps() as $s) if (SalesFollowupLadder::isOn($config, $s)) $top = $s;
        if ($top === null) { sf_out('OFF — not judged'); return 0; }
        $st = SalesFollowupLadder::stepStatus($top, $checker);
        sf_out($top . ': ' . $st['status']);
        return $st['kind'] === 'FAIL' ? 3 : 0;
    }
    if (SalesFollowupLadder::row($step) === null) return sf_refuse('unknown_step');
    if (!SalesFollowupLadder::isOn($config, $step)) { sf_out($step . ': OFF — not judged'); return 0; }
    $st = SalesFollowupLadder::stepStatus($step, $checker);
    sf_out($step . ': ' . $st['status']);
    foreach ($st['parts'] as $id => $r) {
        sf_out('  ' . $id . ': ' . SalesFollowupLadder::status((string)($r['status'] ?? ''))['status']);
        $counts = [];
        foreach ((array)($r['counts'] ?? []) as $k => $v) $counts[] = $k . '=' . (int)$v;
        if ($counts !== []) sf_out('    counts: ' . implode(' ', $counts));
        foreach ((array)($r['notes'] ?? []) as $n) sf_out('    note: ' . (string)$n);
    }
    sf_coverage_note($st);
    return $st['kind'] === 'FAIL' ? 3 : 0;
}

/** Coordinator item 9f: step 2 at FAIL coverage refuses every later `on` until `off ack`, then `on ack`, re-stamp it. */
function sf_coverage_note(array $st): void
{
    $va = SalesFollowupLadder::status((string)($st['parts']['VA-ON1']['status'] ?? ''));
    if ($va['kind'] === 'FAIL' && $va['code'] === 'coverage') {
        sf_out('  step 2 reads FAIL coverage: an acknowledgement event was decided with no decision row. Every later `on` is');
        sf_out('  refused until `off ack` and then `on ack`, which re-stamps wa_voice_ack_since and judges from there.');
    }
}

// ── on ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

function sf_on(\PDO $ro, string $root, string $dataDir, array $config, array $pos, array $opts, int $now): int
{
    $step = (string)($pos[0] ?? '');
    $row = SalesFollowupLadder::row($step);
    if ($row === null || count($pos) !== 1) return sf_refuse('unknown_step', ['steps: ' . implode(', ', SalesFollowupLadder::steps())]);
    $accept = array_key_exists('accept-not-yet', $opts) ? (string)$opts['accept-not-yet'] : null;
    if ($accept !== null && !SalesFollowupLadder::reasonOk($accept)) return sf_refuse('reason_invalid', ['--accept-not-yet takes a reason, with no run of 9 or more digits']);
    unset($opts['accept-not-yet']);

    // Rule 5, first half: the row's options, validated before any precondition is called (A12-03).
    $v = SalesFollowupLadder::validateOptions($step, $opts, sf_known_channels($ro, $root, $config, $dataDir), $now);
    if (!$v['ok']) return sf_refuse((string)$v['code'], [$v['line']]);

    // Rules 2, 3, 4: order, no earlier FAIL running, the needs.
    $checker = sf_checker($ro, $root, $dataDir, $config, $now);
    $a = SalesFollowupLadder::admit($config, $step, $accept, $now, $checker);
    if (!$a['ok']) {
        if (strpos((string)$a['code'], 'earlier_fail:ack') === 0) sf_coverage_note(SalesFollowupLadder::stepStatus('ack', $checker));
        return sf_refuse((string)$a['code'], $a['lines']);
    }
    foreach ($a['lines'] as $l) sf_out('  ' . $l);

    // Rule 5, second half: the row's own precondition.
    $p = SalesFollowupLadder::precondition($ro, $config, $dataDir, $step);
    foreach ($p['lines'] as $l) sf_out('  ' . $l);
    if (!$p['ok']) return sf_refuse((string)$p['code']);

    $changes = [];
    if ($step === 'chase') {
        sf_out('Type yes to switch the quote chase on:');
        if (strtolower(trim(sf_read_line(false))) !== 'yes') return sf_refuse('not_confirmed');
    }
    if ($step === 'cold') {
        sf_out('Type the state of support\'s assistant, as it is now (one line, no number):');
        $s = trim(sf_read_line(false));
        if (!SalesFollowupLadder::reasonOk($s)) return sf_refuse('statement_invalid', ['the statement is required, with no run of 9 or more digits']);
        $changes['sf_cold_statement'] = $s;
    }
    if ($step === 'offers-test') sf_offers_preview($ro, $root, $config, $dataDir, $now);
    if ($step === 'agent-cc') sf_agent_cc_lines($ro, $root, $config, $dataDir, $p);

    foreach ($row['switch'] as $k => $val) $changes[$k] = $val;
    if ($row['stamp'] !== null) $changes[$row['stamp']] = UtcClock::stamp($now);
    foreach ($v['keys'] as $k => $val) $changes[$k] = $val;
    if ($row['ledger'] && !SalesFollowupLadder::ledgerOn($config)) {
        $changes['wa_send_ledger'] = 'on';
        $changes['wa_send_ledger_since'] = UtcClock::stamp($now);
    }
    if ($step === 'chase' && trim((string)($config['sf_chase_first_on'] ?? '')) === '') $changes['sf_chase_first_on'] = UtcClock::stamp($now);
    $changes['sf_skip_' . $step] = null;
    $changes['sf_override_' . $step] = null;
    foreach ($a['overrides'] as $k => $val) $changes[$k] = $val;
    if (!in_array('sf_skip_' . $step, SalesFollowupLadder::allKeys(), true)) unset($changes['sf_skip_' . $step]);

    if (!sf_save($dataDir, $changes)) return sf_refuse('save_failed', ['kyc_config.json could not be written']);
    sf_interplay($ro, $root, $config, $dataDir, $step, $now);

    // The row's after-`on` read-back (row 1b, §H.2.12.2).
    if ($step === 'agent-cc') {
        SalesFollowupLadder::load('P');
        if (empty(QuoteAgentCopy::state($root, $dataDir)['on'])) {
            sf_save($dataDir, ['quote_agent_cc_enabled' => null, 'quote_agent_cc_since' => null]);
            sf_out('FAIL gate_reads_off — the copy does not read on after the write; both keys were unset again');
            return 2;
        }
    }
    sf_out('ON ' . $step . ': ' . implode(', ', array_keys(array_filter($changes, function ($x) { return $x !== null; }))));
    PluginLog::write('sales_followup', $step . ' on by cli');
    return 0;
}

/** The channel ids --channels may name: the registry's, with it on; the three departments otherwise. */
function sf_known_channels(\PDO $ro, string $root, array $config, string $dataDir): array
{
    require_once $root . '/lib/ChannelRegistry.php';
    if (!ChannelRegistry::enabled($config, $dataDir)) return ChannelRegistry::DEPARTMENT;
    try {
        return array_map('strval', array_keys((new ChannelRegistry($ro))->rows()));
    } catch (\Throwable $e) {
        return [];
    }
}

/** One line from the terminal; with $silent, its echo off (erase). */
function sf_read_line(bool $silent): string
{
    $tty = function_exists('posix_isatty') && @posix_isatty(STDIN);
    if ($silent && $tty) @shell_exec('stty -echo 2>/dev/null');
    $l = fgets(STDIN);
    if ($silent && $tty) { @shell_exec('stty echo 2>/dev/null'); echo "\n"; }
    return $l === false ? '' : rtrim($l, "\r\n");
}

/** What `on` prints in this part and in the others (§H.2.12.6). */
function sf_interplay(\PDO $ro, string $root, array $config, string $dataDir, string $step, int $now): void
{
    switch ($step) {
        case 'ack':
            sf_out('the ledger starts recording every automated send; nothing is deferred yet');
            break;
        case 'chase':
            $n = 0;
            try {
                $n = (int)$ro->query("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id
                                       WHERE d.status = 'approved' AND f.closed_at IS NULL AND COALESCE(f.kind, 'enquiry') = 'enquiry'")->fetchColumn();
            } catch (\Throwable $e) { $n = 0; }
            sf_out('enquiry follow-ups now share each number\'s budget and the one-message-a-day rule (' . $n . ' approved drafts); on reminder days,'
                 . ' chases and enquiry follow-ups wait for the morning reminders; chases no longer use the enquiry engine\'s daily cap');
            break;
        case 'voice-staff':
            sf_out('every voice note now hands its chat to a person; a quote chase already stops at the voice note itself (answered)');
            break;
        case 'offers-test':
            sf_out('offers use only what each number has left after today\'s chases, lists and enquiry follow-ups, and wait for the morning reminders');
            break;
        case 'agent-cc':
            sf_out('nothing on WhatsApp changes');
            break;
        case 'skip-ack':
            sf_out('the ledger comes on without the voice acknowledgement');
            break;
    }
}

/**
 * `on offers-test`'s baseline preview: an audience of size "all", counts only, on $ro with the rows read once (A13-01):
 * OfferAudience::build()'s total (the size of `eligible`, every eligible phone at size all), its `by_handset` counts with
 * each handset named by role and index (§H.2.5.7), and its `left_out` and `waiting` counts by reason code.
 */
function sf_offers_preview(\PDO $ro, string $root, array $config, string $dataDir, int $now): void
{
    try {
        SalesFollowupLadder::load('O');
        if (!class_exists('OfferAudience')) require_once $root . '/lib/OfferAudience.php';
        $rows = OfferAudience::storedRows($ro);
        $a = OfferAudience::build($ro, $config, $dataDir, $rows['retailers'], $rows['kyc_config'], 'all', $now);
        sf_out('baseline audience (size all): ' . count((array)$a['eligible']) . ' eligible');
        $labels = sf_handset_labels($config);
        foreach ((array)$a['by_handset'] as $h => $n) sf_out('  handset ' . sf_handset_label($labels, (string)$h) . ': ' . (int)$n);
        foreach ((array)$a['left_out'] as $r => $n) sf_out('  left out ' . preg_replace('/[^a-z0-9_:]/', '', (string)$r) . ': ' . (int)$n);
        foreach ((array)$a['waiting'] as $r => $n) sf_out('  waiting ' . preg_replace('/[^a-z0-9_:]/', '', (string)$r) . ': ' . (int)$n);
    } catch (\Throwable $e) {
        sf_out('baseline audience: not read (' . preg_replace('/[^a-z0-9_:]/', '', strtolower($e->getMessage())) . ')');
    }
}

/** The department handsets by role and index (SalesFollowupChecks::handsetLabels()). */
function sf_handset_labels(array $config): array
{
    if (!class_exists('SalesFollowupChecks')) SalesFollowupLadder::load('F');
    return SalesFollowupChecks::handsetLabels($config);
}

/** A handset's label; a line outside the three departments is given the next index, never its instance name. */
function sf_handset_label(array &$labels, string $h): string
{
    if (!isset($labels[$h])) $labels[$h] = 'h' . (count($labels) + 1) . ' (another line)';
    return $labels[$h];
}

/**
 * `on agent-cc`'s lines (Part P §H.5.10.3): the two plugin quotation paths' states — path 1's `quote_email_via_plugin`
 * from email_settings.json, path 3's master switch with the quotation's through CustomerEmailDispatcher::enabled() —
 * `email_cc_contacts` through ::ccEnabled(), never as literals (§H.5.10.1), and `kyc_quote_send_via_crm`; plugin mail;
 * accountCounts() on the rows read through $ro.
 */
function sf_agent_cc_lines(\PDO $ro, string $root, array $config, string $dataDir, array $pre): void
{
    SalesFollowupLadder::load('P');
    sf_out('From now on, when the plugin e-mails a quotation, the account that made it is copied, visibly, in the same e-mail.'
         . ' uCRM\'s own quotation e-mails are not changed.');
    $GLOBALS['dataDir'] = $dataDir;                          // CustomerEmailDispatcher::effectiveConfig() reads this folder's files
    require_once $root . '/lib/CustomerEmailDispatcher.php';
    $f = rtrim($dataDir, '/') . '/email_settings.json';
    $es = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $path1 = is_array($es) && !empty($es['quote_email_via_plugin']);
    $master = CustomerEmailDispatcher::masterEnabled($config);
    $path3 = CustomerEmailDispatcher::enabled('quotation', $config);
    sf_out('  quote_email_via_plugin (email_settings.json): ' . ($path1 ? 'on' : 'off'));
    sf_out('  the webhook\'s quotation e-mail: ' . ($path3 ? 'on' : 'off') . ' (customer e-mails ' . ($master ? 'on' : 'off') . ')');
    sf_out('  email_cc_contacts: ' . (CustomerEmailDispatcher::ccEnabled($config) ? 'on' : 'off'));
    $via = array_key_exists('kyc_quote_send_via_crm', $config) ? $config['kyc_quote_send_via_crm'] : null;
    $via0 = $via === 0 || $via === '0' || $via === false;
    sf_out('  kyc_quote_send_via_crm: ' . ($via === null ? 'unset' : ($via0 ? '0' : 'on')));
    if ($via0) sf_out('  for KYC quotes the plugin\'s e-mail is the only quotation e-mail; one that fails is lost, and AC-ON says so');
    if (!$path1 && !$path3) sf_out('  no plugin quotation e-mail is on — the copy will have nothing to ride on');
    try {
        if (!class_exists('OfferAudience')) require_once $root . '/lib/OfferAudience.php';
        $rows = OfferAudience::storedRows($ro);
        $m = QuoteAgentCopy::mailSetting($dataDir);
        require_once $root . '/lib/EmailTemplate.php';
        $c = QuoteAgentCopy::accountCounts($rows['retailers'], ['reply_to' => EmailTemplate::replyTo($config), 'sender' => (string)($m['sender'] ?? 'not read')]);
        foreach ($c as $k => $n) sf_out('  accounts ' . $k . ': ' . (int)$n);
    } catch (\Throwable $e) {
        sf_out('  accounts: not read');
    }
}

// ── skip ────────────────────────────────────────────────────────────────────────────────────────────────────────────

function sf_skip(\PDO $ro, string $root, string $dataDir, array $config, array $pos, array $opts, int $now): int
{
    $step = (string)($pos[0] ?? '');
    $row = SalesFollowupLadder::row($step);
    if ($row === null) return sf_refuse('unknown_step');
    if ($step === 'agent-cc') return sf_refuse('optional', ['optional: the order passes it while it is off']);
    if ($row['noskip']) return sf_refuse('not_skippable', ['step ' . $row['n'] . ' cannot be skipped']);
    if (SalesFollowupLadder::isOn($config, $step)) return sf_refuse('step_on', ['turn it off first: a FAIL is turned off or skipped, never stepped past']);
    $reason = (string)($opts['reason'] ?? '');
    if (!SalesFollowupLadder::reasonOk($reason)) return sf_refuse('reason_invalid', ['--reason "<text>" is required, with no run of 9 or more digits']);
    $mark = UtcClock::stamp($now) . '|' . trim($reason);
    $changes = ['sf_skip_' . $step => $mark];
    $cascade = SalesFollowupLadder::skipCascade($step);
    foreach ($cascade as $d) {
        if (!SalesFollowupLadder::isOn($config, $d)) $changes['sf_skip_' . $d] = UtcClock::stamp($now) . '|cascade from ' . $step;
    }
    if ($step === 'ack' && !SalesFollowupLadder::ledgerOn($config)) {
        $changes['wa_send_ledger'] = 'on';
        $changes['wa_send_ledger_since'] = UtcClock::stamp($now);
    }
    if (!sf_save($dataDir, $changes)) return sf_refuse('save_failed');
    sf_out('SKIPPED ' . $step . ($cascade !== [] ? '; also skipped: ' . implode(', ', $cascade) : ''));
    if ($step === 'ack') sf_interplay($ro, $root, $config, $dataDir, 'skip-ack', $now);
    PluginLog::write('sales_followup', $step . ' skip by cli');
    return 0;
}

// ── off ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** How long the tool waits for a lock: the 70 s bound, shortened (never lengthened) by DN_SF_LOCK_WAIT_S. */
function sf_lock_wait(): int
{
    $e = getenv('DN_SF_LOCK_WAIT_S');
    $w = SalesFollowupLadder::LOCK_WAIT_S;
    if (is_string($e) && ctype_digit($e)) $w = min($w, (int)$e);
    return $w;
}

/** Take a lock under the data folder with LOCK_EX, polling for at most the wait; the handle, or null. */
function sf_lock(string $dataDir, string $which)
{
    $file = $which === 'quote' ? 'quote_send.lock' : 'offers_send.lock';
    $fp = @fopen(rtrim($dataDir, '/') . '/' . $file, 'c');
    if ($fp === false) return null;
    $until = microtime(true) + sf_lock_wait();
    do {
        if (flock($fp, LOCK_EX | LOCK_NB)) return $fp;
        usleep(200000);
    } while (microtime(true) < $until);
    fclose($fp);
    return null;
}

function sf_unlock(&$fp): void
{
    if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    $fp = null;
}

function sf_off(\PDO $ro, string $root, string $dataDir, array $config, array $pos, array $opts, int $now): int
{
    $what = (string)($pos[0] ?? '');
    $rollback = !empty($opts['rollback']);
    if ($what === '' || ($what !== 'all' && $what !== 'sending' && SalesFollowupLadder::row($what) === null)) return sf_refuse('unknown_step');
    if ($rollback && $what !== 'all') return sf_refuse('rollback_all_only', ['--rollback goes with `off all`']);
    $why = $rollback ? 'rollback' : 'switch';
    $plan = SalesFollowupLadder::offPlan($what);
    $rw = sf_rw($root, $dataDir);
    $rc = 0; $cleared = []; $wasOn = [];
    $locks = ['quote' => null, 'offers' => null];
    $stop = false;
    foreach ($plan as $i => [$kind, $name]) {
        if ($stop) break;
        if ($kind === 'step') {
            $keys = SalesFollowupLadder::rowKeys($name);
            foreach (SalesFollowupLadder::locksOf($name) as $l) {
                if ($locks[$l] !== null) continue;
                $locks[$l] = sf_lock($dataDir, $l);
                if ($locks[$l] === null) { sf_out('FAIL lock_busy ' . $name . ' — its keys stay set'); $rc = 3; $stop = true; break; }
            }
            if ($stop) break;
            $hook = SalesFollowupLadder::hookOf($name);
            if ($hook !== null && $hook[1] === 'before') {
                if (!sf_hook($rw, $config, $dataDir, $name, $why)) { $rc = 3; $stop = true; break; }
                if ($rollback && $hook[0] === 'O' && !sf_assert_offers($ro)) { $rc = 3; $stop = true; break; }
            }
            foreach ($keys as $k) if (sf_set_now($config, $k)) $wasOn[] = $k;
            if (!sf_save($dataDir, array_fill_keys($keys, null))) { sf_out('FAIL save_failed ' . $name); $rc = 3; $stop = true; break; }
            $cleared = array_merge($cleared, $keys);
            sf_out('OFF ' . $name);
            $config = PluginConfig::read($root, $dataDir);
            // the quote pass's lock is held from before row 6's hook until step 6's keys are cleared, then released
            $next = $plan[$i + 1] ?? null;
            if ($locks['quote'] !== null && ($next === null || $next[0] !== 'step' || !in_array('quote', SalesFollowupLadder::locksOf($next[1]), true))) sf_unlock($locks['quote']);
            if ($hook !== null && $hook[1] === 'after') {
                if (!sf_hook($rw, $config, $dataDir, $name, $why)) $rc = 3;
                if ($rollback && $name === 'voice-measure' && !sf_assert_voice($ro, $config)) $rc = 3;
            }
        } elseif ($kind === 'ledger') {
            foreach (['quote', 'offers'] as $l) {
                if ($locks[$l] !== null) continue;
                $locks[$l] = sf_lock($dataDir, $l);
                if ($locks[$l] === null) { sf_out('FAIL lock_busy sending — the ledger stays on'); $rc = 3; $stop = true; break; }
            }
            if ($stop) break;
            foreach (SalesFollowupLadder::LEDGER_KEYS as $k) if (sf_set_now($config, $k)) $wasOn[] = $k;
            if (!sf_save($dataDir, array_fill_keys(SalesFollowupLadder::LEDGER_KEYS, null))) { sf_out('FAIL save_failed sending'); $rc = 3; $stop = true; break; }
            $cleared = array_merge($cleared, SalesFollowupLadder::LEDGER_KEYS);
            sf_unlock($locks['offers']); sf_unlock($locks['quote']);
            sf_out('OFF sending — every ledger built from now on answers ledger_off; the chase, the list, the acknowledgement and offers go inert');
            $config = PluginConfig::read($root, $dataDir);
        } else {
            $keys = SalesFollowupLadder::lastKeys();
            foreach ($keys as $k) if (sf_set_now($config, $k)) $wasOn[] = $k;
            if (!sf_save($dataDir, array_fill_keys($keys, null))) { sf_out('FAIL save_failed records'); $rc = 3; $stop = true; break; }
            $cleared = array_merge($cleared, $keys);
            sf_out('OFF the record keys and agent-cc');
            $config = PluginConfig::read($root, $dataDir);
        }
    }
    sf_unlock($locks['offers']); sf_unlock($locks['quote']);
    if ($what !== 'all' && $what !== 'sending' && count($plan) > 1) {
        sf_out('also off: ' . implode(', ', array_slice(array_map(function ($a) { return $a[1]; }, $plan), 0, -1)) . ' (each needs what was switched off)');
    }
    // The read-back of every cleared key through PluginConfig::read (§H.2.12.2; QF-RB1 item 7).
    if (!sf_read_back($root, $dataDir, $cleared)) $rc = 3;
    if (in_array('quote_agent_cc_enabled', $cleared, true)) {
        SalesFollowupLadder::load('P');
        if (!empty(QuoteAgentCopy::state($root, $dataDir)['on'])) { sf_out('FAIL key_outside_overrides — the agent copy still reads on'); $rc = 3; }
        else sf_out('quotation e-mails are as 5.18.103 from the next one');
    }
    if ($rollback && !$stop) {
        if (!sf_assert_all_unset($ro, $root, $dataDir)) $rc = 3;
        try {
            $n = (int)$ro->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'wa_voice_calls'")->fetchColumn() > 0
               ? (int)$ro->query('SELECT COUNT(*) FROM wa_voice_calls')->fetchColumn() : 0;
            if ($n > 0) sf_out('VN-RB6: OpenAI was called from here; rotate the OpenAI key on OpenAI\'s own pages');
        } catch (\Throwable $e) { /* nothing to say */ }
    }
    foreach (array_values(array_unique($wasOn)) as $k) sf_out('was on: ' . $k);
    PluginLog::write('sales_followup', $what . ' off by cli' . ($rollback ? ' (rollback)' : ''));
    return $rc;
}

/** Whether a key reads set now (anything but absent or an empty string). */
function sf_set_now(array $config, string $k): bool
{
    return array_key_exists($k, $config) && $config[$k] !== null && $config[$k] !== '' && $config[$k] !== false;
}

/** Run one off-hook on $rw; its counts printed, a throw reported by a fixed code and never its message. */
function sf_hook(\PDO $rw, array $config, string $dataDir, string $step, string $why): bool
{
    try {
        $c = SalesFollowupLadder::runHook($rw, $config, $dataDir, $step, $why);
    } catch (\Throwable $e) {
        sf_out('FAIL off_hook ' . $step);
        return false;
    }
    if (is_array($c) && $c !== []) {
        $p = [];
        foreach ($c as $k => $v) $p[] = $k . '=' . (int)$v;
        sf_out('  hook ' . $step . ': ' . implode(' ', $p));
    }
    return true;
}

/** QF-RB1, Part O's end state: no offer running, no recipient claimed. */
function sf_assert_offers(\PDO $ro): bool
{
    try {
        if ((int)$ro->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'wa_offers'")->fetchColumn() === 0) return true;
        $run = (int)$ro->query("SELECT COUNT(*) FROM wa_offers WHERE state = 'running'")->fetchColumn();
        $cl = (int)$ro->query("SELECT COUNT(*) FROM wa_offer_recipients WHERE state = 'claimed'")->fetchColumn();
    } catch (\Throwable $e) {
        sf_out('FAIL rollback_assert offers'); return false;
    }
    if ($run + $cl === 0) return true;
    sf_out('FAIL rollback_assert offers — running ' . $run . ', claimed ' . $cl);
    return false;
}

/** QF-RB1, Part V's end state: no pending audio row, and Part V's keys unset. */
function sf_assert_voice(\PDO $ro, array $config): bool
{
    $left = 0;
    try {
        if ((int)$ro->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'wa_media'")->fetchColumn() > 0) {
            $left = (int)$ro->query("SELECT COUNT(*) FROM wa_media WHERE kind = 'audio' AND status = 'pending'")->fetchColumn();
        }
    } catch (\Throwable $e) { $left = -1; }
    $set = [];
    foreach (['voice-measure', 'voice-dry-run', 'voice-staff', 'voice-reply'] as $s) {
        foreach (SalesFollowupLadder::rowKeys($s) as $k) if (sf_set_now($config, $k)) $set[] = $k;
    }
    if ($left === 0 && $set === []) return true;
    sf_out('FAIL rollback_assert voice — pending audio ' . $left . ', keys still set ' . count($set));
    return false;
}

/**
 * The read-back: a key `off` unset that still reads set through PluginConfig::read is held by data/config.json or
 * <data>/config.json, which show through (§H.2.12.2): FAIL key_outside_overrides, the file named and never the value.
 */
function sf_read_back(string $root, string $dataDir, array $keys): bool
{
    $now = PluginConfig::read($root, $dataDir);
    $ok = true;
    foreach (array_values(array_unique($keys)) as $k) {
        if (!sf_set_now($now, $k)) continue;
        $file = 'another file';
        foreach (['data/config.json' => $root . '/data/config.json', '<data>/config.json' => rtrim($dataDir, '/') . '/config.json'] as $label => $path) {
            $j = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
            if (is_array($j) && array_key_exists($k, $j)) { $file = $label; break; }
        }
        sf_out('FAIL key_outside_overrides ' . $k . ' — held by ' . $file);
        $ok = false;
    }
    return $ok;
}

/** QF-RB1 item 7: no key of allKeys() is set in the overrides file or the store row, nor reads set at all. */
function sf_assert_all_unset(\PDO $ro, string $root, string $dataDir): bool
{
    $ok = sf_read_back($root, $dataDir, SalesFollowupLadder::allKeys());
    $file = json_decode((string)@file_get_contents(rtrim($dataDir, '/') . '/kyc_config.json'), true);
    $store = [];
    try {
        if ((int)$ro->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'kyc_config'")->fetchColumn() > 0) {
            $j = $ro->query('SELECT data FROM [kyc_config] WHERE id = 0')->fetchColumn();
            $store = is_string($j) ? (json_decode($j, true) ?: []) : [];
        }
    } catch (\Throwable $e) { $store = []; }
    foreach (SalesFollowupLadder::allKeys() as $k) {
        if ((is_array($file) && array_key_exists($k, $file)) || array_key_exists($k, $store)) {
            sf_out('FAIL rollback_assert ' . $k . ' — still in the overrides file or the store row');
            $ok = false;
        }
    }
    if ($ok) sf_out('rollback: every 5.18.104 key is unset');
    return $ok;
}

// ── set ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

function sf_set(\PDO $ro, string $root, string $dataDir, array $config, array $pos, int $now): int
{
    $name = (string)($pos[0] ?? '');
    $values = array_slice($pos, 1);
    $ids = [];
    try {
        require_once $root . '/lib/StaffDirectory.php';
        if (!class_exists('OfferAudience')) require_once $root . '/lib/OfferAudience.php';
        foreach (OfferAudience::storedRows($ro)['retailers'] as $r) {
            if (is_array($r) && isset($r['id']) && StaffDirectory::isActive($r)) $ids[] = (int)$r['id'];
        }
    } catch (\Throwable $e) { $ids = []; }
    $v = SalesFollowupLadder::validateSet($name, $values, $config, $ids, $now);
    if (!$v['ok']) return sf_refuse((string)$v['code'], [$v['line']]);
    if (!sf_save($dataDir, $v['changes'])) return sf_refuse('save_failed');
    foreach ($v['changes'] as $k => $val) sf_out('SET ' . $k . ' = ' . sf_shown($k, $val));
    if (isset($v['changes']['ai_media_openai_stt_model']) && $v['changes']['ai_media_openai_stt_model'] !== 'whisper-1') {
        sf_out('D2 says Whisper; a change is the owner\'s decision');
    }
    sf_out('a setting binds the next claim only; a claim already made is judged by the limits its row records');
    PluginLog::write('sales_followup', 'set ' . implode(',', array_keys($v['changes'])) . ' by cli');
    return 0;
}

/**
 * A stored value as the tool prints it: the typed statement as `set`, the daily list's accounts as a count (no staff id
 * reaches the output or the wrapper's log, §H.2.12.1), every other value as it is.
 */
function sf_shown(string $k, $v): string
{
    if ($k === 'sf_cold_statement') return 'set';
    if ($k === 'quote_daily_list_staff') {
        $ids = array_filter(array_map('trim', explode(',', is_scalar($v) ? (string)$v : '')), 'strlen');
        return 'set: ' . count($ids) . ' account' . (count($ids) === 1 ? '' : 's');
    }
    return is_scalar($v) ? (string)$v : (string)json_encode($v);
}

// ── erase ───────────────────────────────────────────────────────────────────────────────────────────────────────────

function sf_erase(string $root, string $dataDir, array $config, int $now): int
{
    sf_out('Type the number to erase (it is not shown):');
    $raw = sf_read_line(true);
    require_once $root . '/lib/PhoneNumber.php';
    require_once $root . '/lib/TenantProfile.php';
    try {
        $intl = PhoneNumber::international($raw, TenantProfile::current($config, $dataDir));
    } catch (\Throwable $e) { $intl = null; }
    $p = $intl === null ? '' : ltrim((string)$intl, '+');
    if ($p === '' || !ctype_digit($p)) return sf_refuse('not_a_number');
    $rw = sf_rw($root, $dataDir);
    require_once $root . '/lib/QuoteTracker.php';
    require_once $root . '/lib/FollowUpService.php';
    $has = function (string $t) use ($rw): bool {
        $s = $rw->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $s->execute([$t]);
        return (int)$s->fetchColumn() > 0;
    };
    $n = ['tracker' => 0, 'ledger' => 0, 'marks' => 0, 'offers' => 0, 'media' => 0, 'followups' => 0];
    $exec = function (string $sql, array $a) use ($rw): int { $s = $rw->prepare($sql); $s->execute($a); return $s->rowCount(); };
    $rw->exec('BEGIN IMMEDIATE');
    try {
        $keys = [];
        if ($has('quote_tracker')) {
            $st = $rw->prepare("SELECT quote_key FROM quote_tracker WHERE phone = ?");
            $st->execute([$p]);
            $keys = $st->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($keys as $k) QuoteTracker::setStatus($rw, (string)$k, 'opted_out', 'erased', $now, 'sales_followup');
            $n['tracker'] = $exec("UPDATE quote_tracker SET phone = 'erased' WHERE phone = ?", [$p]);
        }
        $svc = new FollowUpService($rw);
        if ($svc->hasKind()) {
            $in = $keys === [] ? "''" : implode(',', array_fill(0, count($keys), '?'));
            $st = $rw->prepare("SELECT id FROM followups WHERE kind = 'quote' AND closed_at IS NULL AND (phone IN (?, ?) OR quote_key IN ({$in}))");
            $st->execute(array_merge([$p, '+' . $p], array_map('strval', $keys)));
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) if ($svc->close((int)$id, 'opted_out', 'erased', 'sales_followup')) $n['followups']++;
        }
        if ($has('wa_auto_sends')) $n['ledger'] = $exec('UPDATE wa_auto_sends SET phone = NULL WHERE phone = ?', [$p]);
        if ($has('wa_seen_marks')) $n['marks'] = $exec("UPDATE wa_seen_marks SET phone = '' WHERE phone = ?", [$p]);
        if ($has('wa_offer_recipients')) $n['offers'] = $exec('UPDATE wa_offer_recipients SET phone = NULL WHERE phone = ?', [$p]);
        if ($has('wa_media')) {
            // Part V §H.3.3's union (ruling R16): the JID of the number, and every row of a conversation with exactly that phone.
            $n['media'] = $exec("UPDATE wa_media SET remote_jid = '' WHERE remote_jid <> '' AND (remote_jid = ?
                                   OR conversation_id IN (SELECT id FROM wa_conversations WHERE phone = ?))", [$p . '@s.whatsapp.net', $p]);
        }
        $rw->exec('COMMIT');
    } catch (\Throwable $e) {
        try { $rw->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing was committed */ }
        sf_out('FAIL erase — nothing was changed');
        return 3;
    }
    $total = array_sum($n);
    foreach ($n as $k => $v) sf_out('erased ' . $k . ': ' . $v);
    PluginLog::write('sales_followup', 'erase by cli: ' . $total . ' rows');
    return 0;
}

// ── show and doctor ─────────────────────────────────────────────────────────────────────────────────────────────────

function sf_show(\PDO $ro, string $root, string $dataDir, array $config, int $now): int
{
    $has = function (string $t) use ($ro): bool {
        $s = $ro->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $s->execute([$t]);
        return (int)$s->fetchColumn() > 0;
    };
    sf_out('5.18.104 sales follow-up — the ladder');
    foreach (SalesFollowupLadder::steps() as $s) {
        $row = SalesFollowupLadder::row($s);
        $label = $s === 'agent-cc' ? '1b agent-cc (optional, P7)' : $row['n'] . ' ' . $s . ($row['optional'] ? ' (optional)' : '');
        if (SalesFollowupLadder::isOn($config, $s)) {
            $since = SalesFollowupLadder::sinceOf($config, $s);
            $state = 'on' . ($since !== null ? ' since ' . UtcClock::stamp($since) : '');
        } elseif (SalesFollowupLadder::isSkipped($config, $s)) {
            $state = 'skipped (' . substr((string)$config['sf_skip_' . $s], 0, 19) . ')';
        } else {
            $state = 'off';
        }
        if ($s === 'agent-cc') {
            $state .= '; administrators: ' . ((string)($config['quote_agent_cc_admins'] ?? '1') === '1' || !array_key_exists('quote_agent_cc_admins', $config) ? 'included' : 'excluded');
        }
        sf_out('  ' . $label . ': ' . $state);
        if (trim((string)($config['sf_override_' . $s] ?? '')) !== '') sf_out('    accepted NOT_YET: ' . substr((string)$config['sf_override_' . $s], 0, 19));
    }
    sf_out('  the ledger: ' . (SalesFollowupLadder::ledgerOn($config) ? 'on since ' . (string)($config['wa_send_ledger_since'] ?? '?') : 'off'));
    sf_out('keys:');
    foreach (array_merge(SalesFollowupLadder::allKeys(), SalesFollowupLadder::settingKeys()) as $k) {
        if (!sf_set_now($config, $k)) continue;
        sf_out('  ' . $k . ' = ' . sf_shown($k, $config[$k]));
    }
    sf_out('dn_tz(): ' . dn_tz($config) . (dn_tz($config) === SalesFollowupLadder::TZ ? '' : '  (WARNING: not Africa/Kampala)'));
    sf_out('openai key: ' . (trim((string)($config['openai_api_key'] ?? '')) !== '' ? 'set' : 'not set'));
    sf_out('VoiceAckLimits::of(): ' . (VoiceAckLimits::of($config) === null ? 'INVALID' : 'valid'));
    if ($has('wa_auto_sends')) {
        require_once $root . '/lib/WaSendBudget.php';
        require_once $root . '/lib/SalesFollowupChecks.php';
        $b = WaSendBudget::onConnection($ro, $config, $dataDir);
        foreach (SalesFollowupChecks::handsetLabels($config) as $h => $label) {
            $c = $b->counts($h);
            $p = [];
            foreach ($c as $k => $v) $p[] = $k . '=' . (substr((string)$k, -3) === '_at' ? ((int)$v > 0 ? str_replace(' ', 'T', UtcClock::stamp((int)$v)) : '-') : (int)$v);
            sf_out('  ' . $label . ': ' . implode(' ', $p));
        }
    }
    if ($has('_schema_checks')) {
        foreach (['089_quote_followups.sql', '090_wa_voice.sql', '091_wa_offers.sql'] as $f) {
            $s = $ro->prepare('SELECT COUNT(*) FROM _schema_checks WHERE migration = ? AND found = expected');
            $s->execute([$f]);
            sf_out('_schema_checks ' . $f . ': ' . ((int)$s->fetchColumn() > 0 ? 'present' : 'absent'));
        }
    }
    if ($has('quote_tracker')) {
        foreach ($ro->query("SELECT status, COALESCE(chase_hold, '-') AS h, COUNT(*) AS n FROM quote_tracker GROUP BY status, h ORDER BY status, h")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            sf_out('tracker ' . $r['status'] . ' hold ' . $r['h'] . ': ' . (int)$r['n']);
        }
    }
    if ($has('wa_seen_marks')) {
        $s = $ro->prepare("SELECT substr(seen_at, 1, 10) AS d, direction, COUNT(*) AS n FROM wa_seen_marks WHERE seen_at >= ? GROUP BY d, direction ORDER BY d");
        $s->execute([UtcClock::stamp($now - 7 * 86400)]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) sf_out('marks ' . $r['d'] . ' ' . $r['direction'] . ': ' . (int)$r['n']);
    }
    try {
        $n = (int)$ro->query("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL")->fetchColumn();
        sf_out('open quote follow-ups: ' . $n);
    } catch (\Throwable $e) { /* followups.kind absent */ }
    return 0;
}

function sf_doctor(\PDO $ro, string $root, string $dataDir): int
{
    $rc = 0;
    $hasSc = (int)$ro->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '_schema_checks'")->fetchColumn() > 0;
    $log = @file(rtrim($dataDir, '/') . '/migration.log') ?: [];
    foreach (['089_quote_followups.sql', '090_wa_voice.sql', '091_wa_offers.sql'] as $f) {
        $ok = false;
        if ($hasSc) {
            $s = $ro->prepare('SELECT COUNT(*) FROM _schema_checks WHERE migration = ? AND found = expected');
            $s->execute([$f]);
            $ok = (int)$s->fetchColumn() > 0;
        }
        sf_out(($ok ? 'PASS ' : 'FAIL schema_check_missing:') . $f);
        if (!$ok) $rc = 3;
        $last = '';
        foreach ($log as $l) if (strpos($l, $f) !== false) $last = trim($l);
        sf_out('  migration.log: ' . ($last !== '' ? $last : 'no line'));
    }
    $names = ['_schema_checks', 'quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'quote_followup_state', 'wa_seen_marks',
              'idx_qt_quote_id', 'idx_qt_open', 'idx_qt_phone', 'idx_qt_owner', 'idx_fu_quote', 'idx_was_ref', 'idx_was_day',
              'idx_was_time', 'idx_was_phone', 'idx_was_waid', 'idx_qls_day', 'idx_wsm_msg', 'idx_wsm_phone',
              'qt_never_deleted', 'qt_status_once', 'qt_identity_fixed', 'qt_phone_erase_only', 'was_never_deleted',
              'was_settled_once', 'was_identity_fixed', 'was_phone_erase_only', 'qls_never_deleted', 'wsm_never_deleted',
              'wsm_never_changed', 'wsm_phone_erase_only'];
    $present = [];
    foreach ($ro->query('SELECT name FROM sqlite_master')->fetchAll(\PDO::FETCH_COLUMN) as $n) $present[(string)$n] = true;
    $cols = ['followups' => ['kind', 'quote_key'], 'wa_auto_sends' => ['win_from', 'win_to', 'cap_day', 'cap_hour'], 'quote_list_sends' => ['sections']];
    $missing = [];
    foreach ($names as $n) if (!isset($present[$n])) $missing[] = $n;
    $total = count($names);
    foreach ($cols as $t => $cs) {
        $have = [];
        if (isset($present[$t])) foreach ($ro->query('PRAGMA table_info(' . $t . ')')->fetchAll(\PDO::FETCH_ASSOC) as $c) $have[(string)$c['name']] = true;
        foreach ($cs as $c) { $total++; if (!isset($have[$c])) $missing[] = $t . '.' . $c; }
    }
    sf_out('089 names: ' . ($total - count($missing)) . ' of ' . $total . ' present' . ($missing !== [] ? ' (absent: ' . implode(', ', $missing) . ')' : ''));
    $pl = PluginLog::path();
    if (is_file($pl) && is_readable($pl)) {
        $slow = [];
        foreach (@file($pl) ?: [] as $l) if (strpos($l, 'SLOW JOB') !== false) $slow[] = trim($l);
        foreach (array_slice($slow, -20) as $l) sf_out('  ' . $l);
        sf_out('SLOW JOB lines: ' . count($slow));
    } else {
        sf_out('note: the master log is not readable here');
    }
    return $rc;
}
