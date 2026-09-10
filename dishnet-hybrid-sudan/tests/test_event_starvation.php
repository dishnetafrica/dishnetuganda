<?php
/**
 * test_event_starvation.php — one queue must not starve another.
 *
 * Reproduces, against a real EventBus, the failure that stopped the assistant
 * answering while twenty customers were messaging.
 *
 * consume($limit) claimed the $limit oldest events of ANY type. WorkerBase
 * then kept the ones it handled and released the rest. Seven stale events from
 * August that no worker handles sat permanently at the head of the ordering,
 * so once total pending crossed the batch size of 20, no ai.reply event fell
 * inside the batch. The AI worker got an empty list and stopped.
 *
 * Nothing looked wrong anywhere: the events were pending, unlocked, untried
 * and eligible; the cron was dispatching; the worker was running; the log had
 * no errors, because from the worker's side there simply was no work.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/EventBus.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

function freshBus(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL,
        payload TEXT, status TEXT NOT NULL DEFAULT 'pending',
        priority INTEGER NOT NULL DEFAULT 5, attempts INTEGER NOT NULL DEFAULT 0,
        max_attempts INTEGER NOT NULL DEFAULT 5, locked_by TEXT, locked_at TEXT,
        next_retry_at TEXT DEFAULT (datetime('now')),
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        processed_at TEXT, error TEXT, created_by TEXT)");
    return [$pdo, new EventBus($pdo)];
}

/** Older rows sort first, exactly as the real backlog did. */
function seed(PDO $pdo, string $type, int $n, string $day, int $priority = 3): void
{
    for ($i = 0; $i < $n; $i++) {
        $pdo->prepare("INSERT INTO events
            (event_type, entity_type, entity_id, payload, priority, created_at, next_retry_at)
            VALUES (?, 'conversation', 1, '{}', ?, ?, ?)")
            ->execute([$type, $priority, $day . ' 10:00:' . sprintf('%02d', $i), $day . ' 10:00:00']);
    }
}

echo "\nA backlog of another type does not hide our work\n";
list($pdo, $bus) = freshBus();
seed($pdo, 'stale.thing', 25, '2026-08-26');   // more than one batch, all older
seed($pdo, 'ai.reply',     3, '2026-09-10');   // the waiting customers

$got = $bus->consume(20, 'test', ['ai.reply']);
is_(count($got) === 3, 'all three customer events are claimed',
    'claimed ' . count($got) . ' — a full batch of older events used to crowd them out');
$types = array_unique(array_map(function ($e) { return $e['event_type']; }, $got));
is_($types === ['ai.reply'], 'and nothing else is claimed at all',
    'got: ' . implode(', ', $types));

echo "\nThe stale backlog is left alone, not consumed\n";
$still = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type='stale.thing' AND status='pending'")->fetchColumn();
is_($still === 25, 'the 25 unhandled events stay pending and untouched',
    $still . ' remain — claiming and releasing them is the work that starved us');

echo "\nWithout a type, it still behaves exactly as before\n";
// Other callers pass no types and must keep the old semantics.
list($pdo2, $bus2) = freshBus();
seed($pdo2, 'stale.thing', 5, '2026-08-26');
seed($pdo2, 'ai.reply',    2, '2026-09-10');
$any = $bus2->consume(20, 'test');
is_(count($any) === 7, 'an unfiltered claim takes everything eligible',
    'claimed ' . count($any));

echo "\nOrdering within our own type is unchanged\n";
list($pdo3, $bus3) = freshBus();
seed($pdo3, 'ai.reply', 1, '2026-09-01', 9);   // low priority, oldest
seed($pdo3, 'ai.reply', 1, '2026-09-10', 1);   // critical, newest
$ord = $bus3->consume(20, 'test', ['ai.reply']);
is_(count($ord) === 2 && (int)$ord[0]['priority'] === 1,
    'priority still wins over age',
    'a waiting customer must not sit behind a cleanup job');

echo "\nWorkerBase asks for its own types\n";
$wb = (string)file_get_contents($root . '/workers/WorkerBase.php');
is_(strpos($wb, "consume(\$limit, '', \$types)") !== false,
    'consumeFiltered passes them through to SQL',
    'filtering only after the claim is what caused this');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
