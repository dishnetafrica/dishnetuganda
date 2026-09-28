#!/bin/bash
# job-walkthrough.sh — one test job, one step at a time (docs/44 §16.25, §16.26).
#
# Run on the server, from the checkout:   cd /opt/dishnet && bash scripts/job-walkthrough.sh
#
# It creates ONE test job in uCRM, for the test customer and the technician below, and walks through what the job
# notifier does, step by step. It asks before every step and does nothing you have not answered with Enter:
#   1. create the job                         → message 1 to the technician (WhatsApp and e-mail), and the customer's
#                                               "installation booked" e-mail
#   2. give it a new time                     → "Job #N has a new time"
#   3. the technician accepts it              → message 2, with the completion link (the technician, or an admin, does
#                                               this in DishNet; the script waits and checks)
#   4. take it away from the technician       → "Job #N is no longer assigned to you". uCRM keeps no time on a job
#                                               with nobody assigned (it answers 422), so the time goes too
#   5. give it back, with its time, Open      → message 1 again (offered only when step 4 took the job away)
#   6. delete it                              → "Job #N has been cancelled"
# After each step it waits for uCRM's notice to the plugin and prints what the plugin did: the plugin's log lines, the
# job's message history, the Message Log and uCRM's job. Answer s to skip a step, anything else to stop.
#
# It changes only the job it created: every change first reads the job back from uCRM and refuses unless the job's
# description carries this run's own mark. It sends real WhatsApp messages and e-mails: to the technician, and in
# step 1 to the customer. Everything it prints is masked: no name, number or e-mail address.
#
# Options: --client N (default 1)   --tech N (the technician's uCRM user id, default 1099)
#          --no-customer-email       (a title that is not an installation, so the customer gets no e-mail)
# It writes its own log file under /root/dnb-5.18.52/ and says where; send that file back.
#
# Read-only:  bash scripts/job-walkthrough.sh --facts N
#   What the plugin recorded for job N: the notifier's record (with DishNet's Accept claim), the job's history, uCRM's
#   notices about it and what the plugin did with each, the Message Log around them, and uCRM's job now. It changes
#   nothing and sends nothing, and writes its own log file under /root/dnb-5.18.52/.
set -u

CONTAINER="${UCRM_CONTAINER:-ucrm}"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/dishnet-hybrid-sudan}"
OUT="${OUT:-/root/dnb-5.18.52}"
ACCEPT_WAIT="${ACCEPT_WAIT:-60}"
CLIENT=1; TECH=1099; TITLE="TEST Starlink Installation (walk-through)"; MODE=walk; FACTS=""
while [ $# -gt 0 ]; do
  case "$1" in
    --client|--tech|--facts)
      [ $# -ge 2 ] || { echo "$1 takes a number"; exit 2; }
      case "$1" in --client) CLIENT="$2" ;; --tech) TECH="$2" ;; --facts) MODE=facts; FACTS="$2" ;; esac
      shift 2 ;;
    --no-customer-email) TITLE="TEST job (walk-through)"; shift ;;
    *) echo "unknown option: $1"; exit 2 ;;
  esac
done
case "$CLIENT$TECH" in *[!0-9]*|'') echo "--client and --tech take a number"; exit 2 ;; esac
if [ "$MODE" = facts ]; then case "$FACTS" in *[!0-9]*|'') echo "--facts takes a job number"; exit 2 ;; esac; fi
case "$ACCEPT_WAIT" in *[!0-9]*|'') ACCEPT_WAIT=60 ;; esac

mkdir -p "$OUT" || exit 1
TS="$(date -u +%Y%m%dT%H%M%SZ)"; RUN="wt-$TS-$$"

read -r -d '' HELPER <<'PHP'
<?php
// The walk-through's helper: runs inside the uCRM container as the plugin's user, with the plugin's own code.
error_reporting(E_ALL); ini_set('display_errors', 'stderr');
$plug = (string)($argv[1] ?? ''); $step = (string)($argv[2] ?? ''); $a = array_slice($argv, 3);
if (!is_file("$plug/manifest.json")) { echo "NO no plugin at $plug\n"; exit(1); }
chdir($plug);
require_once "$plug/lib/bootstrap_data.php";
$dataDir = getDataDir($plug);
foreach (['StoreInterface', 'SqliteStore', 'CrmApiClient', 'PluginConfig', 'TenantProfile', 'StaffJobsGate', 'StaffDirectory', 'JobTime'] as $c) {
    require_once "$plug/lib/$c.php";
}
require_once "$plug/lib/timezone.php";
$store  = SqliteStore::create($dataDir);
$config = (array)($store->load('kyc_config.json') ?? []);
$config = $config + PluginConfig::load($plug, $dataDir);
$crm    = CrmApiClient::fromUcrm($plug, $config);
$pdo    = $store->getPdo();
$tz     = dn_tz_obj($config);

function wt_mask(string $s): string {
    return (string)preg_replace(['/[^\s<>()"]+@[^\s<>()"]+/', '/\+\d[\d ()-]{7,}\d/', '/\b\d{9,}\b/', '/(notification sent to|No phone found for) .*/'],
                                ['<e-mail>', '<number>', '<number>', '$1 <name>'], $s);
}
function wt_log(string $dataDir): array {
    $l = json_decode((string)@file_get_contents($dataDir . '/webhook_log.json'), true);
    return is_array($l) ? $l : [];
}
function wt_max(PDO $pdo, string $sql): int { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; } }
function wt_initials(array $c): string {
    $n = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')) ?: trim((string)($c['companyName'] ?? ''));
    $i = array_map(function ($w) { return (function_exists('mb_substr') ? mb_strtoupper(mb_substr($w, 0, 1)) : strtoupper(substr($w, 0, 1))) . '.'; },
                   preg_split('/\s+/', $n, -1, PREG_SPLIT_NO_EMPTY));
    return $i ? implode(' ', $i) : '(no name)';
}
function wt_status($s): string {
    return ['0' => 'Open', '1' => 'In progress', '2' => 'Closed'][(string)$s] ?? ($s === null ? '(none)' : (string)$s);
}
function wt_when($iso, DateTimeZone $tz): string {
    try { return $iso ? (new DateTime((string)$iso))->setTimezone($tz)->format('D j M Y H:i') : '(no time)'; } catch (\Throwable $e) { return '(unreadable)'; }
}
/** The job, read back from uCRM, and whether this run created it: its description carries the run's mark. */
function wt_mine(CrmApiClient $crm, int $job, string $run): array {
    $j = $crm->get("scheduling/jobs/{$job}");
    if (!is_array($j)) return [null, false];
    return [$j, $run !== '' && strpos((string)($j['description'] ?? ''), "(run {$run})") !== false];
}

switch ($step) {
case 'mark':
    $wh = 0; foreach (wt_log($dataDir) as $e) $wh = max($wh, (int)($e['id'] ?? 0));
    echo "MARK $wh ", wt_max($pdo, 'SELECT coalesce(max(id), 0) FROM notification_audit_log'), ' ',
         wt_max($pdo, 'SELECT coalesce(max(id), 0) FROM job_notify_events'), "\n";
    break;

case 'preflight':
    [$client, $tech] = [(int)$a[0], (int)$a[1]]; $go = true;
    $say = function (bool $ok, string $t) use (&$go) { if (!$ok) $go = false; echo ($ok ? 'ok    ' : 'NO    '), $t, "\n"; };
    $m = json_decode((string)@file_get_contents("$plug/manifest.json"), true) ?: [];
    $v = (string)($m['information']['version'] ?? $m['version'] ?? '?');
    $wired = is_file("$plug/lib/JobNotifier.php") && strpos((string)@file_get_contents("$plug/webhook.php"), 'whJobNotify(') !== false;
    $say($wired, "the installed plugin is $v" . ($wired ? ', with the job notifier' : ', WITHOUT the job notifier (5.18.52 is needed)'));
    $say(StaffJobsGate::applies($config, $dataDir), 'this install reads Uganda, so the job notifier is on');
    $tables = wt_max($pdo, "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name IN ('job_notify_state', 'job_notify_events')") === 2;
    $say($tables, "the notifier's two tables exist");
    $c = $crm->get("clients/{$client}");
    $say(is_array($c), is_array($c) ? "customer: uCRM client #{$client}, initials " . wt_initials($c) . ', e-mail on file: '
        . (trim((string)($c['username'] ?? '')) !== '' || !empty($c['contacts'][0]['email']) ? 'yes' : 'no')
        : "customer: uCRM did not answer for client #{$client}");
    $rows = StaffDirectory::byUcrmUser((array)($store->load('retailers.json') ?? []), $tech);
    if (count($rows) !== 1) {
        $say(false, 'technician: ' . (count($rows) === 0 ? "no active staff account has a verified link to uCRM user #{$tech}"
            : count($rows) . " staff accounts have a verified link to uCRM user #{$tech}") . ' — nobody would get the messages');
    } else {
        $r = $rows[0]; $tenant = TenantProfile::current($config, $dataDir);
        $phone = StaffDirectory::phoneOf($r, $tenant) !== null; $mail = filter_var(StaffDirectory::email($r), FILTER_VALIDATE_EMAIL) !== false;
        $say($phone, "technician: uCRM user #{$tech} → staff account #" . (int)($r['id'] ?? 0) . ', verified link, active; usable WhatsApp number: '
            . ($phone ? 'yes' : 'NO') . '; e-mail: ' . ($mail ? 'yes' : 'no'));
    }
    $log = wt_log($dataDir);
    echo 'note  the plugin\'s webhook log has ', count($log), ' entries', $log !== [] ? ', the newest at ' . (string)($log[0]['received_at'] ?? '?') . ' (Kampala time)' : '', "\n";
    echo $go ? "GO\n" : "NO-GO\n";
    break;

case 'create':
    [$client, $tech, $run, $title, $date, $time] = [(int)$a[0], (int)$a[1], (string)$a[2], (string)$a[3], (string)$a[4], (string)$a[5]];
    if ($date === 'tomorrow') $date = (new DateTime('tomorrow', $tz))->format('Y-m-d');
    $when = JobTime::toUcrm($date, $time, $tz);
    if ($when === null) { echo "FAIL the date or time is not valid\n"; break; }
    $j = $crm->post('scheduling/jobs', ['title' => $title, 'date' => $when, 'duration' => 60, 'status' => 0, 'assignedUserId' => $tech,
        'clientId' => $client, 'description' => "Test job created by scripts/job-walkthrough.sh (run {$run}). Safe to delete."]);
    if (!is_array($j) || (int)($j['id'] ?? 0) <= 0) { echo 'FAIL ', wt_mask((string)json_encode($crm->getLastError())), "\n"; break; }
    echo 'JOB ', (int)$j['id'], ' ', wt_when($j['date'] ?? $when, $tz), "\n";
    break;

case 'patch':
    [$job, $run] = [(int)$a[0], (string)$a[1]];
    [$j, $mine] = wt_mine($crm, $job, $run);
    if ($j === null) { echo "FAIL uCRM did not answer for job #{$job}\n"; break; }
    if (!$mine) { echo "REFUSED job #{$job} was not created by this walk-through run; nothing was changed\n"; break; }
    $p = [];
    foreach (array_slice($a, 2) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'assignee') $p['assignedUserId'] = ($v === 'none') ? null : (int)$v;
        if ($k === 'status' && $v === 'open') $p['status'] = 0;
        if ($k === 'date' && $v === 'none') $p['date'] = null;
        elseif ($k === 'date') {
            [$d, $t] = array_pad(explode(' ', $v, 2), 2, '');
            if ($d === 'tomorrow') $d = (new DateTime('tomorrow', $tz))->format('Y-m-d');
            $p['date'] = JobTime::toUcrm($d, $t, $tz);
            if ($p['date'] === null) { echo "FAIL the date or time is not valid\n"; break 2; }
        }
    }
    $r = $crm->patch("scheduling/jobs/{$job}", $p);
    echo is_array($r) ? "OK\n" : 'FAIL ' . wt_mask((string)json_encode($crm->getLastError())) . "\n";
    break;

case 'delete':
    [$job, $run] = [(int)$a[0], (string)$a[1]];
    [$j, $mine] = wt_mine($crm, $job, $run);
    if ($j === null) { echo "FAIL uCRM did not answer for job #{$job}\n"; break; }
    if (!$mine) { echo "REFUSED job #{$job} was not created by this walk-through run; nothing was deleted\n"; break; }
    $r = $crm->delete("scheduling/jobs/{$job}");
    echo is_array($r) ? "OK\n" : 'FAIL ' . wt_mask((string)json_encode($crm->getLastError())) . "\n";
    break;

case 'wait':
    // uCRM's notice for this job and this change, after the mark: its "Received" line and the handler's lines after it.
    [$job, $ev, $mark, $timeout] = [(int)$a[0], (string)$a[1], (int)$a[2], (int)$a[3]];
    $deadline = time() + $timeout; $seen = null; $done = 0; $lines = [];
    while (time() <= $deadline) {
        $lines = []; $seen = null; $in = false;
        foreach (array_reverse(wt_log($dataDir)) as $e) {
            if ((int)($e['id'] ?? 0) <= $mark) continue;
            $m = (string)($e['message'] ?? ''); $d = (array)($e['data'] ?? []);
            if (strpos($m, 'Received UCRM webhook') === 0) {
                $in = ($m === "Received UCRM webhook: job.{$ev}" && (int)($d['entity_id'] ?? 0) === $job);
                if ($in && $seen === null) $seen = (int)$e['id'];
            }
            $own = preg_match('/^(Received UCRM webhook|job #\\d+|Customer email|Unhandled event type)/i', $m);
            if (($in && $own) || preg_match("/^job #{$job}\\b/i", $m)) $lines[] = [(string)($e['received_at'] ?? ''), wt_mask($m)];
        }
        $handled = false;
        foreach ($lines as $l) if (preg_match("/^(job #{$job}\\b|Unhandled event type)/i", $l[1])) $handled = true;
        if ($seen !== null && $handled) { if ($done === 0) $done = time() + 3; if (time() >= $done) break; }
        sleep(1);
    }
    foreach ($lines as $l) echo "LINE {$l[0]}  {$l[1]}\n";
    if ($seen === null) { echo "TIMEOUT no job.{$ev} notice for job #{$job} reached the plugin within {$timeout} s\n"; break; }
    $old = false; $handled = false;
    foreach ($lines as $l) {
        if (strpos($l[1], 'not switched on yet') !== false || strpos($l[1], 'Unhandled event type') === 0) $old = true;
        if (preg_match("/^(job #{$job}\\b|Unhandled event type)/i", $l[1])) $handled = true;
    }
    echo $old ? "OLD-CODE\n" : ($handled ? "HANDLED\n" : "UNANSWERED the notice reached the plugin, but no result line followed within {$timeout} s\n");
    break;

case 'report':
    [$job, $nal, $ev] = [(int)$a[0], (int)$a[1], (int)$a[2]];
    try {
        $st = $pdo->prepare('SELECT event, message, source, outcome, detail, email_outcome FROM job_notify_events WHERE job_id = ? AND id > ? ORDER BY id');
        $st->execute([$job, $ev]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo 'EVENT ', $r['event'], ' ', $r['message'] ?? '-', ' ', $r['source'], ' ', $r['outcome'], ' email=', $r['email_outcome'] ?? '-',
                 ($r['detail'] ?? '') !== '' ? ' (' . wt_mask((string)$r['detail']) . ')' : '', "\n";
        }
    } catch (\Throwable $e) { echo "EVENT none: the history could not be read\n"; }
    try {
        $st = $pdo->prepare('SELECT event, success, count(*) n FROM notification_audit_log WHERE id > ? GROUP BY event, success ORDER BY event');
        $st->execute([$nal]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'MLOG ', $r['event'], ' ', (int)$r['success'] ? 'sent' : 'not-sent', ' ', (int)$r['n'], "\n";
    } catch (\Throwable $e) { echo "MLOG none: the Message Log could not be read\n"; }
    $j = $crm->get("scheduling/jobs/{$job}");
    if (is_array($j)) {
        $s = ['0' => 'Open', '1' => 'In progress', '2' => 'Closed'][(string)($j['status'] ?? '')] ?? (string)($j['status'] ?? '?');
        echo 'UCRM status ', $s, '; assigned to ', (int)($j['assignedUserId'] ?? 0) > 0 ? 'uCRM user #' . (int)$j['assignedUserId'] : 'nobody',
             '; time ', wt_when($j['date'] ?? '', $tz), "\n";
    } else {
        $e = $crm->getLastError();
        echo (int)($e['http_code'] ?? 0) === 404 ? "UCRM the job is gone (404)\n" : "UCRM did not answer\n";
    }
    break;

case 'facts':
    // Read-only: what the plugin recorded for one job, and whether DishNet's Accept left its claim on it (docs/44 §16.26).
    $job = (int)$a[0]; $st = null; $hist = [];
    try {
        $q = $pdo->prepare('SELECT assignee_id, job_time, job_status, gone, accepted_by, updated_at FROM job_notify_state WHERE job_id = ?');
        $q->execute([$job]);
        $st = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($st === null) echo "STATE none: the notifier holds no record of job #{$job}\n";
        else echo 'STATE assigned to ', $st['assignee_id'] !== null ? 'uCRM user #' . (int)$st['assignee_id'] : 'nobody',
                  '; time ', (string)$st['job_time'] !== '' ? wt_when($st['job_time'] . ' UTC', $tz) : '(none)',
                  '; status ', wt_status($st['job_status']), (int)$st['gone'] ? '; deleted in uCRM' : '',
                  "; DishNet's Accept claim: ", $st['accepted_by'] !== null ? 'uCRM user #' . (int)$st['accepted_by'] : 'none',
                  '; last written ', (string)$st['updated_at'], " UTC\n";
    } catch (\Throwable $e) { echo "STATE unreadable: the notifier's record could not be read\n"; }
    try {
        $q = $pdo->prepare('SELECT * FROM job_notify_events WHERE job_id = ? ORDER BY id');
        $q->execute([$job]);
        $hist = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hist as $r) {
            echo 'HIST ', $r['created_at'], '  ', $r['event'], ' ', $r['message'] ?? '-', ' ', $r['source'], ' ', $r['outcome'],
                 ' email=', $r['email_outcome'] ?? '-', ($r['detail'] ?? '') !== '' ? ' (' . wt_mask((string)$r['detail']) . ')' : '', "\n";
        }
        if ($hist === []) echo "HIST none: no history row for job #{$job}\n";
    } catch (\Throwable $e) { echo "HIST unreadable: the history could not be read\n"; }
    // uCRM's notices about the job, oldest first: each "Received" line for it and the handler's lines after it.
    $first = null; $last = null; $in = false;
    foreach (array_reverse(wt_log($dataDir)) as $e) {
        $m = (string)($e['message'] ?? ''); $d = (array)($e['data'] ?? []); $at = (string)($e['received_at'] ?? '');
        if (strpos($m, 'Received UCRM webhook') === 0) $in = strpos($m, 'Received UCRM webhook: job.') === 0 && (int)($d['entity_id'] ?? 0) === $job;
        $own = preg_match('/^(Received UCRM webhook|job #\d+|Customer email|Unhandled event type)/i', $m) === 1;
        if (($in && $own) || preg_match("/^job #{$job}\\b/i", $m) === 1) {
            echo "LINE {$at}  ", wt_mask($m), "\n";
            if ($first === null) $first = $at;
            $last = $at;
        }
    }
    if ($first === null) echo "LINE none: the plugin's webhook log (it keeps the newest 300 entries) holds no notice about job #{$job}\n";
    // The Message Log around those notices, on the same clock: job messages and "Job Accepted", never a number or a text.
    if ($first !== null) {
        $from = date('Y-m-d H:i:s', (int)strtotime($first) - 120); $to = date('Y-m-d H:i:s', (int)strtotime($last) + 300);
        try {
            $q = $pdo->prepare("SELECT event, success, sent_at FROM notification_audit_log WHERE sent_at BETWEEN ? AND ?"
                . " AND (event LIKE 'job!_%' ESCAPE '!' OR event LIKE 'ops!_job!_accepted%' ESCAPE '!') ORDER BY id");
            $q->execute([$from, $to]); $n = 0;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $n++; echo 'MLOG ', $r['sent_at'], '  ', $r['event'], ' ', (int)$r['success'] ? 'sent' : 'not sent', "\n"; }
            if ($n === 0) echo "MLOG none between {$from} and {$to}\n";
        } catch (\Throwable $e) { echo "MLOG unreadable: the Message Log could not be read\n"; }
    } else {
        echo "MLOG not read: with no notice there is no time to look around\n";
    }
    $j = $crm->get("scheduling/jobs/{$job}");
    if (is_array($j)) {
        echo 'UCRM status ', wt_status($j['status'] ?? null), '; assigned to ',
             (int)($j['assignedUserId'] ?? 0) > 0 ? 'uCRM user #' . (int)$j['assignedUserId'] : 'nobody', '; time ', wt_when($j['date'] ?? '', $tz), "\n";
    } else {
        $err = $crm->getLastError();
        echo (int)($err['http_code'] ?? 0) === 404 ? "UCRM the job is gone (404)\n" : "UCRM did not answer\n";
    }
    // DishNet's Accept claims the job (accepted_by) before it sends message 2, and records message 2 once sent.
    $acc = array_values(array_filter($hist, function ($r) { return ($r['event'] ?? '') === 'accepted'; }));
    $claim = $st !== null && $st['accepted_by'] !== null;
    $prog = (is_array($j) && (string)($j['status'] ?? '') === '1') || ($st !== null && (string)($st['job_status'] ?? '') === '1');
    if ($acc !== []) {
        $r = end($acc);
        echo $r['outcome'] === 'sent' ? 'ACCEPT SENT ' : 'ACCEPT RECORDED ', "DishNet's Accept was recorded",
             $r['outcome'] === 'sent' ? ': message 2 went' : ', but message 2 was not sent (' . $r['outcome'] . ')', ', at ', $r['created_at'], " UTC\n";
    } elseif ($claim) {
        echo "ACCEPT CLAIMED DishNet's Accept claimed job #{$job} for uCRM user #", (int)$st['accepted_by'],
             " but recorded no message 2: a fault in the Accept path. Send this report back.\n";
    } elseif ($prog) {
        echo "ACCEPT NONE-PROGRESS DishNet's Accept left no claim on job #{$job}, yet it was In progress. Either it was set In progress",
             " outside DishNet's Accept (uCRM's own screen or app, which sends no message 2: docs/44 §16.12), or DishNet's Accept",
             " stopped before its claim.\n";
    } else {
        echo "ACCEPT NONE DishNet's Accept left no claim on job #{$job}, and neither uCRM nor the plugin's record has it In progress:",
             " Accept has not been pressed.\n";
    }
    break;

default:
    echo "NO unknown step\n"; exit(2);
}
PHP

say()  { printf '%s\n' "$*"; }
hr()   { say ""; say "── $* ──"; }
# The helper's own output on stdout; its errors on stderr, less the plugin's routine vault notice.
h()    { printf '%s' "$HELPER" | docker exec -i -u 1000:1000 "$CONTAINER" php -- "$IN_CONTAINER" "$@" 2> >(grep -v '^\[ConfigVault\] ' >&2); }
indent() { sed 's/^/     /'; }

JOB=""; DELETED=0; declare -a RESULT=()
finish() {
  hr "Summary"
  local r; for r in "${RESULT[@]+"${RESULT[@]}"}"; do say "  $r"; done
  if [ -n "$JOB" ] && [ "$DELETED" = 0 ]; then
    say "  The test job #$JOB is still in uCRM. Delete it there when you are done (Scheduling → the job → Delete),"
    say "  or run this script again and go through to step 6 with a new job."
  fi
  say ""
  say "  The whole run is in $LOG"
  say "  Send it back:  tail -n +1 $LOG"
}
# Enter or y does the step; s skips it; anything else stops the walk-through.
ask() {
  local a
  printf '\n  → %s\n    [Enter = yes, s = skip, q = stop]: ' "$1"
  IFS= read -r a || a=q
  case "$a" in
    ''|y|Y|yes) return 0 ;;
    s|S|skip) return 1 ;;
    *) say "  Stopped. Nothing more is done."; finish; exit 0 ;;
  esac
}
# The plugin's reaction to one change: the notice, then the job's history and the Message Log since the mark.
observe() {   # $1 = notice (add|edit|delete), $2 = expected event, $3 = expected message, $4 = step label
  local out v i
  say "  waiting for uCRM's notice to the plugin (job.$1, up to 90 s)…"
  out="$(h wait "$JOB" "$1" "$WH" 90)"
  printf '%s\n' "$out" | sed -n 's/^LINE /  log  /p'
  if printf '%s\n' "$out" | grep -q '^TIMEOUT'; then
    printf '%s\n' "$out" | sed -n 's/^TIMEOUT /  ✗ /p'
    say "    uCRM's own screen shows whether it sent one: Webhooks → Request log."
    RESULT+=("$4: ✗ no notice from uCRM reached the plugin"); return 1
  fi
  if printf '%s\n' "$out" | grep -q '^OLD-CODE'; then
    say "  ✗ The plugin answered with 5.18.51's words: the web server is still running the old code (docs/44 §16.23)."
    say "    Nothing will reach the technician until it runs 5.18.52. Stop here and send this log back."
    RESULT+=("$4: ✗ the web server ran the old code"); return 2
  fi
  printf '%s\n' "$out" | sed -n 's/^UNANSWERED /  ✗ /p'
  # The row for this change; a notice about an earlier change (an Accept, say) can arrive first, so look for up to 20 s.
  i=0
  while :; do
    out="$(h report "$JOB" "$NAL" "$EV")"
    printf '%s\n' "$out" | grep -q "^EVENT $2 $3 " && break
    [ $i -ge 10 ] && break; sleep 2; i=$((i+1))
  done
  printf '%s\n' "$out" | sed -n 's/^EVENT /  history  /p; s/^MLOG /  Message Log  /p; s/^UCRM /  uCRM now  /p'
  if printf '%s\n' "$out" | grep -q "^EVENT $2 $3 [a-z_]* sent "; then v="✓ as expected: the WhatsApp went"
    printf '%s\n' "$out" | grep -q "^EVENT $2 $3 [a-z_]* sent email=sent" && v="$v, and the e-mail was handed to the mail server"
  elif printf '%s\n' "$out" | grep -q "^EVENT $2 $3 "; then v="✗ \"$2\" was recorded, but the WhatsApp was not sent; see the history above"
  else v="✗ expected \"$2\" with message \"$3\"; see the history above"; fi
  say "  $v"; RESULT+=("$4: $v")
}
# On the old code: nothing more can be shown, and the test job should not stay behind.
stale_stop() {
  if ask "Delete the test job #$JOB now? (The old code will not message anyone about it.)"; then
    if [ "$(h delete "$JOB" "$RUN")" = OK ]; then DELETED=1; say "  deleted."; else say "  ✗ not deleted; delete it in uCRM."; fi
  fi
  finish; exit 1
}
remark() { local m; m="$(h mark)"; WH="$(printf '%s' "$m" | awk '{print $2}')"; NAL="$(printf '%s' "$m" | awk '{print $3}')"; EV="$(printf '%s' "$m" | awk '{print $4}')"; }
# Step 3's check: message 2 in the job's history; failing that, what the plugin's record says of DishNet's Accept.
# Sets ACC (SENT, RECORDED, CLAIMED, NONE-PROGRESS or NONE) and v, the verdict.
accept_check() {
  local i=0 f code
  say "  checking for message 2 (up to $ACCEPT_WAIT s)…"
  while :; do
    out="$(h report "$JOB" "$NAL0" "$EV0")"
    printf '%s\n' "$out" | grep -q '^EVENT accepted ' && break
    [ $((i*2)) -ge "$ACCEPT_WAIT" ] && break; sleep 2; i=$((i+1))
  done
  printf '%s\n' "$out" | sed -n 's/^EVENT /  history  /p; s/^MLOG /  Message Log  /p; s/^UCRM /  uCRM now  /p'
  if printf '%s\n' "$out" | grep -q '^EVENT accepted accepted [a-z_]* sent '; then ACC=SENT; v="✓ as expected: message 2 went"
    printf '%s\n' "$out" | grep -q '^EVENT accepted accepted [a-z_]* sent email=sent' && v="$v, and the e-mail was handed to the mail server"
  elif printf '%s\n' "$out" | grep -q '^EVENT accepted '; then ACC=RECORDED; v="✗ the Accept was recorded, but message 2 was not sent; see the history above"
  else
    f="$(h facts "$JOB")"
    printf '%s\n' "$f" | sed -n 's/^STATE /  plugin record  /p'
    code="$(printf '%s\n' "$f" | sed -n 's/^ACCEPT \([A-Z-]*\) .*/\1/p' | head -n 1)"
    case "$code" in
      CLAIMED) ACC=CLAIMED; v="✗ DishNet's Accept claimed job #$JOB but recorded no message 2: a fault in the Accept path. Send this log back." ;;
      NONE-PROGRESS) ACC=NONE-PROGRESS
        v="✗ uCRM shows job #$JOB In progress, but DishNet's Accept left no claim on it: it was set In progress outside DishNet's"
        v="$v Accept (uCRM's own screen or app sends no message 2), or DishNet's Accept stopped before its claim" ;;
      *) ACC=NONE; v="✗ no Accept yet: job #$JOB is not In progress, and DishNet's Accept left no claim on it" ;;
    esac
  fi
  say "  $v"
}
# What the plugin recorded for one job (read-only), grouped.
show_facts() {
  local f; f="$(h facts "$1")"
  hr "The notifier's record of job #$1"
  printf '%s\n' "$f" | sed -n 's/^STATE /  /p'
  hr "Its history (times UTC)"
  printf '%s\n' "$f" | sed -n 's/^HIST /  /p'
  hr "uCRM's notices about it, and what the plugin did (the plugin's webhook log, on its own clock: Kampala)"
  printf '%s\n' "$f" | sed -n 's/^LINE /  /p'
  hr "The Message Log around those notices: job messages and \"Job Accepted\" (the same clock)"
  printf '%s\n' "$f" | sed -n 's/^MLOG /  /p'
  hr "uCRM now"
  printf '%s\n' "$f" | sed -n 's/^UCRM /  /p'
  hr "DishNet's Accept"
  printf '%s\n' "$f" | sed -n 's/^ACCEPT [A-Z-]* /  /p'
  printf '%s\n' "$f" | grep -v '^\(STATE\|HIST\|LINE\|MLOG\|UCRM\|ACCEPT\) ' | sed '/^$/d; s/^/  (helper) /'
}

if [ "$MODE" = facts ]; then
  LOG="$OUT/job-facts-$FACTS-$TS.log"
  exec > >(tee -a "$LOG") 2>&1
  say "== what the plugin recorded for job #$FACTS — $TS =="
  say "  Read-only: this changes nothing and sends nothing."
  show_facts "$FACTS"
  say ""
  say "  The whole report is in $LOG"
  say "  Send it back:  tail -n +1 $LOG"
  exit 0
fi

LOG="$OUT/walkthrough-$TS.log"
LOCK="$OUT/walkthrough.lock"
mkdir "$LOCK" 2>/dev/null || { echo "another walk-through is running ($LOCK); stop it, or remove that directory if none is"; exit 1; }
trap 'rmdir "$LOCK" 2>/dev/null' EXIT
exec > >(tee -a "$LOG") 2>&1

say "== job walk-through — $TS — run $RUN =="
say "  A test job for uCRM client #$CLIENT, assigned to uCRM user #$TECH, titled \"$TITLE\"."
say "  It sends real WhatsApp messages and e-mails to the technician, and in step 1 an e-mail to the customer"
[ "$TITLE" = "TEST job (walk-through)" ] && say "  (not this time: the title is not an installation, so the customer gets none)."

hr "Before anything: what the plugin reads (changes nothing)"
PF="$(h preflight "$CLIENT" "$TECH")"
printf '%s\n' "$PF" | grep -v '^GO$\|^NO-GO$' | sed 's/^/  /'
if ! printf '%s\n' "$PF" | grep -qx 'GO'; then
  say ""; say "  NO-GO: fix what the NO lines say first. Nothing was created."; RESULT+=("before: NO-GO, nothing created"); finish; exit 1
fi

hr "Step 1 of 6: create the test job"
say "  Expected: uCRM tells the plugin (job.add). The technician gets message 1, \"New Job Has Been Assigned to You\""
say "  with the ✅ ACCEPT JOB link, by WhatsApp and by e-mail. The customer gets \"installation booked\" by e-mail."
if ask "Create the test job now (tomorrow 10:00, Kampala)?"; then
  remark; NAL0="$NAL"; EV0="$EV"
  out="$(h create "$CLIENT" "$TECH" "$RUN" "$TITLE" tomorrow 10:00)"
  case "$out" in
    JOB\ *) JOB="$(printf '%s' "$out" | awk '{print $2}')"; say "  created: job #$JOB, $(printf '%s' "$out" | cut -d' ' -f3-)" ;;
    *) say "  ✗ uCRM did not create the job: $out"; RESULT+=("step 1: ✗ not created"); finish; exit 1 ;;
  esac
  observe add assigned assigned "step 1 create"; [ $? = 2 ] && stale_stop
else
  say "  Skipped: without a job there is nothing more to do."; RESULT+=("step 1: skipped"); finish; exit 0
fi

hr "Step 2 of 6: give the job a new time (tomorrow 14:00)"
say "  Expected: job.edit. The technician gets \"Job #$JOB has a new time\", by WhatsApp and by e-mail."
if ask "Change the time now?"; then
  remark; out="$(h patch "$JOB" "$RUN" "date=tomorrow 14:00")"
  if [ "$out" = OK ]; then observe edit rescheduled new_time "step 2 new time"; [ $? = 2 ] && stale_stop
  else say "  ✗ $out"; RESULT+=("step 2: ✗ $out"); fi
else RESULT+=("step 2: skipped"); fi

hr "Step 3 of 6: the technician accepts the job"
say "  Ask the technician to open message 1, tap ✅ ACCEPT JOB, sign in to DishNet and tap ✔ Accept Job twice (the second"
say "  tap confirms). Or, signed in to DishNet as an admin: My Jobs → Job #$JOB → ✔ Accept Job; message 2 still goes to the"
say "  technician. Accept in DishNet, not in uCRM: a job set In progress in uCRM's own screen gets no message 2."
say "  Expected: message 2, \"Thank you for accepting the job!\", with the ✅ JOB COMPLETED link, by WhatsApp and e-mail."
if ask "Press Enter once Accept has been pressed"; then
  # The verdict goes into the summary at once, so a stop at the next question keeps it; a second try replaces it.
  accept_check; RESULT+=("step 3 accept: $v"); R3=$((${#RESULT[@]} - 1))
  if [ "$ACC" = NONE-PROGRESS ] && ask "Put job #$JOB back to Open, so DishNet shows Accept again, and try once more?"; then
    remark; out="$(h patch "$JOB" "$RUN" "status=open")"
    if [ "$out" = OK ]; then
      say "  waiting for uCRM's notice to the plugin (job.edit, up to 90 s)…"
      h wait "$JOB" edit "$WH" 90 | sed -n 's/^LINE /  log  /p; s/^TIMEOUT /  ✗ /p'
      say "  Job #$JOB is Open again. Now press Accept in DishNet: My Jobs → Job #$JOB → ✔ Accept Job, twice."
      if ask "Press Enter once Accept has been pressed in DishNet"; then accept_check; RESULT[$R3]="step 3 accept: $v (on the second try)"; fi
    else say "  ✗ putting it back to Open failed: ${out#FAIL }"; RESULT[$R3]="step 3 accept: $v; putting it back to Open failed: ${out#FAIL }"; fi
  fi
else RESULT+=("step 3: skipped"); fi

hr "Step 4 of 6: take the job away from the technician (nobody assigned, and so no time)"
say "  uCRM keeps no time on a job with nobody assigned: it answers 422, \"You must assign an user in order to set the"
say "  date.\" So the time goes too. Expected: job.edit. The technician gets \"Job #$JOB is no longer assigned to you\","
say "  by WhatsApp and by e-mail. Its date line reads \"Not scheduled yet\": the message shows the job as uCRM now has it."
TOOK=0
if ask "Take the job away from the technician now?"; then
  remark; out="$(h patch "$JOB" "$RUN" "assignee=none" "date=none")"
  if [ "$out" = OK ]; then TOOK=1; observe edit unassigned removed "step 4 take away"; [ $? = 2 ] && stale_stop
  else say "  ✗ uCRM refused: ${out#FAIL }"; RESULT+=("step 4: ✗ uCRM refused: ${out#FAIL }"); fi
else RESULT+=("step 4: skipped"); fi

hr "Step 5 of 6: give the job back to the technician"
say "  With tomorrow 14:00 again, and Open, so DishNet shows Accept again."
say "  Expected: job.edit. The technician gets message 1 again, with a new ✅ ACCEPT JOB link."
if [ "$TOOK" != 1 ]; then
  say "  Skipped: step 4 did not take the job away, so there is nothing to give back."
  RESULT+=("step 5: skipped (step 4 did not take the job away)")
elif ask "Give the job back to the technician now?"; then
  remark; out="$(h patch "$JOB" "$RUN" "assignee=$TECH" "date=tomorrow 14:00" "status=open")"
  if [ "$out" = OK ]; then observe edit assigned assigned "step 5 give back"; [ $? = 2 ] && stale_stop
  else say "  ✗ $out"; RESULT+=("step 5: ✗ $out"); fi
else RESULT+=("step 5: skipped"); fi

hr "Step 6 of 6: delete the test job"
say "  Expected: job.delete. The technician gets \"Job #$JOB has been cancelled\", by WhatsApp and by e-mail."
if ask "Delete the test job now?"; then
  remark; out="$(h delete "$JOB" "$RUN")"
  if [ "$out" = OK ]; then DELETED=1; observe delete cancelled cancelled "step 6 delete"; [ $? = 2 ] && stale_stop
  else say "  ✗ $out"; RESULT+=("step 6: ✗ $out"); fi
else RESULT+=("step 6: skipped"); fi

finish
