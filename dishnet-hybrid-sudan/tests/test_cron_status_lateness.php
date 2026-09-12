<?php
declare(strict_types=1);
/**
 * test_cron_status_lateness.php — a status tool that always shows red is worse
 * than no status tool.
 *
 * UCRM dispatches the plugin every ~300s (cron/master.php says so in its own
 * budget block). Six jobs declare 30–60s intervals, so they are ALWAYS four
 * minutes behind what they asked for. Judged against the declared interval,
 * every one of them printed OVERDUE on a perfectly healthy machine, for ever
 * — and reading that as a dead dispatcher cost a wrong diagnosis on the live
 * box before this existed.
 *
 * Lateness is measured against what a job can actually achieve. The two states
 * this pins apart are the ones that look identical in a single sample: a
 * healthy dispatcher mid-cycle, and one that has stopped.
 */
// The lateness rule, lifted from cron_status.php, exercised against the two
// cases that must never look alike: a healthy 5-minute dispatcher, and one
// that has actually stopped.
$DISPATCH = 300;
function late(int $interval, int $elapsed, int $DISPATCH): bool {
    $effective = max($interval, $DISPATCH);
    return ($elapsed - $effective) > $effective;
}
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $n\n"; }
    else { $fail++; printf("  FAIL %s: got %s want %s\n", $n, var_export($got,true), var_export($want,true)); } }

echo "Healthy: UCRM dispatched 3 minutes ago (the state on the live box)\n";
foreach ([['event_processor',30],['identity_worker',60],['wa_sync',60],
          ['crm_sync',60],['paid_access',60],['ai_reply',60]] as [$n,$iv]) {
    t("$n ({$iv}s declared) is not late at 180s", late($iv, 180, $DISPATCH), false);
}
t('a 240s job is not late at 180s',  late(240, 180, $DISPATCH), false);
t('a 300s job is not late at 180s',  late(300, 180, $DISPATCH), false);
t('nor at 299s — one cycle exactly', late(60,  299, $DISPATCH), false);

echo "\nStill healthy at the far edge of one missed cycle\n";
t('600s is one whole cycle missed, not yet flagged', late(60, 600, $DISPATCH), false);

echo "\nGenuinely stalled: the dispatcher has stopped\n";
t('60s job silent 11 minutes IS late',   late(60,  660, $DISPATCH), true);
t('30s job silent 11 minutes IS late',   late(30,  660, $DISPATCH), true);
t('60s job silent an hour IS late',      late(60, 3600, $DISPATCH), true);
t('a 3600s job silent 3 hours IS late',  late(3600, 10800, $DISPATCH), true);
t('but a 3600s job at 90 min is not',    late(3600, 5400, $DISPATCH), false);

echo "\nThe old rule, for contrast — why every row read OVERDUE\n";
$oldLate = fn(int $iv, int $el): bool => ($el - $iv) > $iv;
t('old rule flags a healthy 60s job at 180s', $oldLate(60, 180), true);
t('new rule does not',                        late(60, 180, $DISPATCH), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
