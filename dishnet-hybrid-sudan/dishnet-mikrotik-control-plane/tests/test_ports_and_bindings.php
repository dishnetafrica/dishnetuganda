<?php
/**
 * F6-A — the external-system boundary.
 *
 * The brief's rule is "the simulator must never masquerade as a real router".
 * That is only true if it is ENFORCED, so this suite asserts the properties
 * that make it true: every result names its world, the real bindings cannot be
 * selected without explicit F6-B authorization, and the simulator refuses the
 * two things the closed decisions forbid rather than cheerfully accepting them.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Radius\NullPublisher;
use Dn\Radius\SimulatedPublisher;
use Dn\Runtime\Bindings;

$db = \Dn\Db\Database::owner();   // never touched: no binding below reads it

// ===========================================================================
t('DEFAULT — a process with no configuration publishes nothing, and says so');
$b = Bindings::defaults();
$d = $b->describe();
is_($d['publisher_binding'], 'null', 'the default publisher binding is null');
is_($d['publisher_simulated'], true, 'and it reports itself simulated');
is_($d['phase'], 'F6-A', 'the process reports itself as F6-A');
is_($d['real_bindings_allowed'], false, 'real bindings are not allowed by default');

$r = $b->publisher()->publish($db, ['radius_username' => 'u', 'secret' => 'generated-secret-1',
                                    'site_id' => 'site-a', 'expires_at' => null]);
is_($r->published, false, 'the null publisher publishes nothing');
is_($r->retryable, true, 'and says so retryably — the work is undone, not impossible');
is_($r->simulated, true, 'and every result carries the simulated flag');
is_($b->publisher()->confirm($db, 'u'), false, 'it confirms nothing either');

// ===========================================================================
t('GATE — a real binding cannot be selected without explicit F6-B authorization');
is_(Bindings::realBindingsAllowed(), false, 'the gate is closed in this process');
throws_(fn() => Bindings::requireRealBindingsAllowed('FreeRadiusPublisher'),
    'not authorized', 'asking for a real binding throws');
throws_(fn() => Bindings::requireRealBindingsAllowed('FreeRadiusPublisher'),
    Bindings::REAL_GATE_ENV, 'and the error names the variable that would open it');
// It must THROW, never silently hand back a simulator: a quiet downgrade is
// exactly how a fake ends up believed.
is_(getenv(Bindings::REAL_GATE_ENV), false, 'and no test or default sets that variable');

// ===========================================================================
t('SIMULATOR — publishes, confirms, and withdraws deterministically');
$s = new SimulatedPublisher();
$ok = $s->publish($db, ['radius_username' => 'cust-abc123', 'secret' => 'Zx9-generated-not-a-code',
                        'site_id' => 'site-a', 'expires_at' => null]);
is_($ok->published, true, 'a well-formed credential publishes');
is_($ok->simulated, true, 'and the result still says it was simulated');
is_($s->confirm($db, 'cust-abc123'), true, 'confirm reads it back');
is_($s->siteOf('cust-abc123'), 'site-a', 'bound to the site it was given');
is_($s->confirm($db, 'someone-else'), false, 'and confirms nothing it never published');

$s->unpublish($db, 'cust-abc123');
is_($s->confirm($db, 'cust-abc123'), false, 'unpublish withdraws it');
is_($s->unpublish($db, 'cust-abc123')->published, true,
    'and withdrawing it twice is idempotent, not an error — reconciliation needs that');

// ===========================================================================
t('DECISION 3 — a voucher code must never enter the AAA credential path');
// The boundary enforces it. A double that accepted this would teach the caller
// that publishing a voucher code is fine, which is the failure we are guarding.
foreach (['ABCD-EFGH', 'A3B4C5-D6E7F8', 'ZZZZ-2345-6789'] as $codeShaped) {
    $bad = $s->publish($db, ['radius_username' => 'cust-x', 'secret' => $codeShaped,
                             'site_id' => 'site-a', 'expires_at' => null]);
    is_($bad->published, false, "a secret shaped like a voucher code is refused: {$codeShaped}");
    is_($bad->retryable, false, '  and permanently — retrying can never make it right');
}
is_($s->confirm($db, 'cust-x'), false, 'nothing was published by any of those attempts');

// ===========================================================================
t('DECISION 2b — a credential with no site is refused, not published');
// docs/70 S5 measured that a credential with no site row authenticates
// nowhere. Publishing one would simulate a success the real system cannot
// produce.
foreach ([null, ''] as $noSite) {
    $bad = $s->publish($db, ['radius_username' => 'cust-y', 'secret' => 'generated-secret-2',
                             'site_id' => $noSite, 'expires_at' => null]);
    is_($bad->published, false, 'a site-less credential is refused');
    is_($bad->retryable, false, '  permanently — it is a contract error, not a timing one');
}

t('SIMULATOR — required fields');
foreach ([['', 'secret'], ['user', '']] as [$u, $sec]) {
    $bad = $s->publish($db, ['radius_username' => $u, 'secret' => $sec, 'site_id' => 'site-a']);
    is_($bad->published, false, 'username and secret are both required');
}

// ===========================================================================
t('SIMULATED WIRING — reports itself as a simulator at the health boundary');
$sim = Bindings::simulated();
$d2  = $sim->describe();
is_($d2['publisher_binding'], 'simulated', 'the binding names itself');
is_($d2['publisher_simulated'], true, 'and is flagged simulated for /health');
is_($d2['phase'], 'F6-A', 'still F6-A — a simulator is not an activation');

// ===========================================================================
t('CONTAINMENT — the publisher is not reachable from the HTTP surface');
// The same rule DeliveryPort has carried since step 7. Asserted here too so a
// future route cannot quietly mint a credential inside a request.
$root = dirname(__DIR__);
$leaks = [];
foreach (array_merge(glob($root . '/src/Http/*.php'), glob($root . '/src/Http/*/*.php'),
                     glob($root . '/src/Api/*.php')) as $f) {
    $body = strip_php_comments(file_get_contents($f));
    foreach (['RadiusPublisherPort', 'Dn\\Radius', 'SimulatedPublisher'] as $n) {
        if (str_contains($body, $n)) { $leaks[] = basename($f) . " -> {$n}"; }
    }
}
is_($leaks, [], 'no file in Http/ or Api/ can reach the AAA publisher');

exit(t_summary());
