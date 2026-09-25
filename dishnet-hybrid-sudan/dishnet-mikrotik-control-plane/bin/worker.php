<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use Dn\Db\Database;
use Dn\Intents\IntentQueue;
use Dn\Jobs\IntentWorker;
use Dn\Jobs\SmsWorker;
use Dn\Notify\PanelSms;
use Dn\Notify\SmsSenders;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

// The WORKER role: it may call the cross-customer claim primitive, which the
// request-path role may not (docs/57 §10).
$db = Database::worker();
$q  = new IntentQueue($db);

// The delivery binding comes from the environment and NEVER falls back
// (docs/118 D-7): DN_DELIVERY unset → nothing is delivered; 'simulated' → an
// in-memory router that says so; 'routeros' → the real adapter, which needs
// the F6-B gate and throws here, before any work is claimed, without it.
$bindings = Bindings::fromEnvironment();
$delivery = $bindings->delivery();

// The SMS sender for sign-in codes (docs/127 S-5, docs/128 SS-9) never falls
// back either. DN_SMS set → the environment decides, as in phase 2:
// 'africastalking' without its username and key throws here, before anything
// is claimed. DN_SMS unset → the settings a DishNet Admin saved in the Admin
// panel, re-read every tick: none until someone sets them, so nothing is sent
// and queued codes expire; a setting that cannot be used sends nothing and is
// reported, and never stops the intents below.
$sms = SmsSenders::forWorker($db);

// The binding's name travels in the worker id, so every claim and every
// intent.confirmed / intent.failed audit row says which world produced it.
$workerId = gethostname() . ':' . getmypid() . ':' . $delivery->bindingName();
fwrite(STDERR, json_encode(['worker' => $workerId, 'bindings' => $bindings->describe(),
                            'sms' => $sms->bindingName()]) . "\n");

$w     = new IntentWorker($db, new TenantContext($db), $q, $delivery, $workerId);
$texts = new SmsWorker($db, $sms);

// Sign-in codes every second — someone is waiting at a sign-in screen. Intents
// every fifth second, the cadence they have always had. The SMS log line holds
// counts only: never a number, a code or a reason.
$once = in_array('--once', $argv, true);
$tick = 0;
$applied = null;
do {
    $s = $texts->runOnce();
    if ($s['claimed'] || $s['expired']) {
        fwrite(STDOUT, json_encode(['sms' => $s]) . "\n");
    }
    // What the worker applied from the panel, each time it changes: a version,
    // a state and a fixed reason. Never the key.
    if ($sms instanceof PanelSms && $sms->status() !== $applied) {
        $applied = $sms->status();
        fwrite(STDERR, json_encode(['sms_settings' => $applied]) . "\n");
    }
    if ($tick % 5 === 0) {
        $expired = $q->expireOverdue();
        $r = $w->runOnce();
        if ($r['claimed'] || $expired) {
            fwrite(STDOUT, json_encode($r + ['expired' => $expired]) . "\n");
        }
    }
    $tick++;
    if (!$once) { sleep(1); }
} while (!$once);
