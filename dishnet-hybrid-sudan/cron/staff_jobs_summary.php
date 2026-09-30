#!/usr/bin/env php
<?php
require_once __DIR__ . '/../lib/crm_url.php';
// Note: No strict_types - included from master.php
require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();

/**
 * cron/staff_jobs_summary.php — DishNet Hybrid Telecom
 *
 * Purpose:
 *   Sends each active DishNet staff member a personalised WhatsApp morning
 *   brief listing their UCRM scheduling jobs for today (and any overdue
 *   open/pending jobs from previous days).
 *
 *   Replaces the manual "Hi Rupesh, here are your jobs" daily message.
 *
 * Runs at: 07:30 EAT daily (30 min after Bidal's summary)
 * Called by: cron/master.php
 * Or manually: php cron/staff_jobs_summary.php
 *
 * Requirements:
 *   - retailer must have: role=support|support_leader|admin, is_active=true,
 *     phone set, ucrm_user_id set
 *   - CRM API must be configured (crm_base_url + crm_app_key in kyc_config)
 *   - WA sender must be configured (wa_plugin_url etc)
 *
 * If a staff member has no ucrm_user_id, a single admin alert is sent
 * listing all unmapped staff (so admin knows to fix it).
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/CustomerContact.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);

// ── CRM client ────────────────────────────────────────────────────────────
// 5.18.54 (docs/46 row 25, S-3): `new CrmApiClient($config)` passes the settings array where the constructor takes the
// address, a TypeError on the first line: the brief has never been sent. On Uganda it is built the way every other job
// builds it, each person's jobs are found through their VERIFIED uCRM link (docs/44 M7: a stored id alone could hand one
// person another's job list), a person is sent the brief at most once a day, and as a staff message (an opt-out on a
// staff number never blocks their work). Elsewhere the 5.18.53 code runs, unchanged.
require_once dirname(__DIR__) . '/lib/NotifyGate.php';
$_sbUg = NotifyGate::applies(NotifyGate::STAFF_SIDE, is_array($config) ? $config : [], $dataDir);
if ($_sbUg) {
    require_once dirname(__DIR__) . '/lib/StaffDirectory.php';
    require_once dirname(__DIR__) . '/lib/ContactOptOut.php';
}
// It has never reached anyone, so it can be held back: staff_jobs_brief = 0 stops it (tools/set_config.php). Unset, on.
if ($_sbUg) {
    $_sbSwitch = $config['staff_jobs_brief'] ?? '';
    if ($_sbSwitch !== '' && $_sbSwitch !== null && !filter_var($_sbSwitch, FILTER_VALIDATE_BOOLEAN)) {
        log_msg_staff_jobs('Switched off (staff_jobs_brief) — no brief today.');
        return;
    }
}
$crm = $_sbUg ? CrmApiClient::fromUcrm(dirname(__DIR__), $config) : new CrmApiClient($config);
if (!$crm->isConfigured()) {
    log_msg_staff_jobs('CRM not configured — cannot fetch scheduling jobs. Exiting.');
    return;
}

$todayStr = date('Y-m-d');
$nowTs    = time();

// ── Load all retailers ────────────────────────────────────────────────────
$allRetailers = $store->load('retailers.json') ?? [];

// Staff eligible for job notifications
$staff = array_filter($allRetailers, fn($r) =>
    ($_sbUg ? (is_array($r) && StaffDirectory::isActive($r) && StaffDirectory::takesJobs($r))
            : (in_array($r['role'] ?? 'sales', ['support', 'support_leader', 'admin']) && !empty($r['is_active']))) &&
    empty($r['on_leave']) &&
    !empty($r['phone'])
);

if (empty($staff)) {
    log_msg_staff_jobs('No active support staff found — exiting.');
    return;
}

// ── Uganda: every open job, read once ────────────────────────────────────
// uCRM ignores every assignee filter (cron/jobs_cache.php), so the 5.18.53 query below, asked per person with
// assigneeId, answers with EVERYONE's jobs: fixed as it stood, the brief would have handed each person the whole list,
// customers' names included. On Uganda the jobs are read once and each person gets those assigned to them, as My Jobs
// does. A read that fails sends no brief at all: none is better than a wrong one.
$_sbJobs = [];
if ($_sbUg) {
    require_once dirname(__DIR__) . '/lib/TenantProfile.php';
    $_sbTenant = TenantProfile::current(is_array($config) ? $config : [], $dataDir);
    $_sbFrom   = date('Y-m-d', strtotime('-7 days'));
    $_sbRead   = $crm->get("scheduling/jobs?limit=500&dateFrom={$_sbFrom}&statuses[]=0&statuses[]=1");
    if (!is_array($_sbRead)) {
        log_msg_staff_jobs('CRM API error reading the jobs: ' . json_encode($crm->getLastError()) . ' — no brief today');
        return;
    }
    $_sbJobs = array_values(array_filter($_sbRead, 'is_array'));
    if (count($_sbJobs) >= 500) log_msg_staff_jobs('WARNING: uCRM returned ' . count($_sbJobs) . ' jobs, its page limit — a brief may miss jobs past it');
}

// ── Track unmapped staff for admin alert ─────────────────────────────────
$unmapped = [];

// ── Send each staff member their jobs ────────────────────────────────────
foreach ($staff as $person) {
    $name        = $person['name'] ?? 'Staff';
    $phone       = preg_replace('/[^0-9+]/', '', $person['phone'] ?? '');
    $ucrmUserId  = $_sbUg ? StaffDirectory::linkedUcrmUser($person) : (int)($person['ucrm_user_id'] ?? 0);

    if (!$ucrmUserId) {
        $unmapped[] = $name;
        log_msg_staff_jobs("Skipping {$name} — " . ($_sbUg ? 'no verified uCRM link' : 'no ucrm_user_id set'));
        continue;
    }

    if ($_sbUg) {
        // The number as every job message reads it (J3): the tenant's international form, or nobody.
        $phone = (string)StaffDirectory::phoneOf($person, $_sbTenant);
        if ($phone === '') {
            log_msg_staff_jobs("Skipping {$name} — no usable phone number");
            continue;
        }
        $jobs = array_values(array_filter($_sbJobs, fn($j) => (int)($j['assignedUserId'] ?? 0) === $ucrmUserId));
    } else {
    // Fetch today's jobs assigned to this user from UCRM
    // Also fetch overdue open/pending from last 7 days
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    // UCRM status codes: 0=Pending, 1=Open, 2=Closed
    $qs = "?assigneeId={$ucrmUserId}&limit=100"
        . "&statuses[]=0&statuses[]=1"
        . "&dateFrom={$sevenDaysAgo}&dateTo={$todayStr}";

    $jobs = $crm->get('scheduling/jobs' . $qs);

    if ($jobs === null) {
        log_msg_staff_jobs("CRM API error for {$name}: " . json_encode($crm->getLastError()));
        continue;
    }

    if (!is_array($jobs)) $jobs = [];
    }

    // Split: today vs overdue
    $todayJobs   = [];
    $overdueJobs = [];

    foreach ($jobs as $j) {
        $jDate = substr($j['date'] ?? '', 0, 10);
        $jStatus = (int)($j['status'] ?? 1);
        if ($jDate === $todayStr) {
            $todayJobs[] = $j;
        } elseif ($jDate < $todayStr && $jStatus !== 2) {
            $overdueJobs[] = $j;
        }
    }

    // Sort today's jobs by time
    usort($todayJobs, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

    $countToday   = count($todayJobs);
    $countOverdue = count($overdueJobs);
    // 0=Pending, 1=Open, 2=Closed
    $countPending = count(array_filter($todayJobs, fn($j) => (int)($j['status'] ?? 1) === 0));
    $countOpen    = count(array_filter($todayJobs, fn($j) => (int)($j['status'] ?? 1) === 1));
    $countInProg  = 0; // covered by pending/open

    $date = date('D d M Y');
    $firstName = explode(' ', $name)[0];

    // Build message
    $msg  = "📋 *Daily Jobs Update*\n";
    $msg .= "Hi {$firstName} DishNet,\n";
    $msg .= "Here are your jobs for today ({$date}):\n\n";

    if ($countToday === 0 && $countOverdue === 0) {
        $msg .= "✅ No jobs scheduled for today.\n";
        $msg .= "Have a great day! 😊\n";
    } else {
        if ($countPending > 0) $msg .= "⏳ Pending Jobs: {$countPending}\n";
        if ($countOpen    > 0) $msg .= "🟡 New/Open Jobs: {$countOpen}\n";
        // In Progress no longer a separate status in UCRM numeric codes
        $msg .= "📦 Total Today: {$countToday}\n";

        if ($countOverdue > 0) {
            $msg .= "⚠️ Overdue (not closed): {$countOverdue}\n";
        }

        // List today's jobs (max 8, keep it readable on WhatsApp)
        if ($countToday > 0) {
            $msg .= "\n*Today's Schedule:*\n";
            $shown = array_slice($todayJobs, 0, 8);
            foreach ($shown as $j) {
                $time    = date('h:i A', strtotime($j['date'] ?? ''));
                $title   = $j['title'] ?? 'Job';
                $client  = $j['client']['name'] ?? '';
                $status  = ucfirst(strtolower($j['status'] ?? 'open'));
                $statusCode = (int)($j['status'] ?? 1);
                switch ($statusCode) {
                    case 0:  $statusEmoji = '⏳'; break;
                    case 1:  $statusEmoji = '🟡'; break;
                    case 2:  $statusEmoji = '✅'; break;
                    default: $statusEmoji = '📌';
                }
                $msg .= "{$statusEmoji} {$time} — {$title}";
                if ($client) $msg .= " ({$client})";
                $msg .= "\n";
            }
            if ($countToday > 8) {
                $msg .= "  _(+ " . ($countToday - 8) . " more — see full list below)_\n";
            }
        }

        if ($countOverdue > 0) {
            $msg .= "\n⚠️ *Overdue jobs needing attention:*\n";
            foreach (array_slice($overdueJobs, 0, 3) as $j) {
                $jDate  = date('d M', strtotime($j['date'] ?? ''));
                $title  = $j['title'] ?? 'Job';
                $client = $j['client']['name'] ?? '';
                $msg .= "  • {$jDate}: {$title}";
                if ($client) $msg .= " ({$client})";
                $msg .= "\n";
            }
            if ($countOverdue > 3) {
                $msg .= "  _(+ " . ($countOverdue - 3) . " more overdue)_\n";
            }
        }

        $msg .= "\n🔍 *Full details & updates:*\n";
        // On Uganda the link opens My Jobs: without page=dashboard it is the sign-in page, which sends a signed-in person
        // to their role's dashboard instead.
        $msg .= "🔗 " . dn_plugin_public($config) . ($_sbUg ? '?page=dashboard&tab=scheduling' : '?tab=scheduling') . "\n";
        $msg .= "\nPlease start with pending jobs and work through your list systematically.\n";
        $msg .= "Have a productive day! 🛠\n";
        $msg .= "Need support? 📞 " . CustomerContact::support($config) . "\n";
        $msg .= "– DishNET Operations Team";
    }

    // Send via WASender (support channel)
    if ($_sbUg) {
        if (!$notify->dedupMark('STAFFBRIEF:' . ((int)($person['id'] ?? 0) ?: $phone) . ':' . $todayStr)) {
            log_msg_staff_jobs("Skipping {$name} — today's brief was already sent");
            continue;
        }
        $notify->sendVia('support', $phone, $msg, 'staff_jobs_summary', [], ContactOptOut::CLASS_STAFF);
        // What the provider answered, and never the number: "accepted" is WhatsApp taking it, not the phone showing it.
        $_sbOk = !empty($notify->lastSendResult()['success']);
        log_msg_staff_jobs(($_sbOk ? "Brief accepted by WhatsApp for {$name}" : "Brief NOT sent to {$name} (see the Message Log)")
            . " — today:{$countToday} overdue:{$countOverdue}");
    } else {
    $notify->sendRaw($phone, $msg, 'staff_jobs_summary');

    log_msg_staff_jobs("Sent to {$name} ({$phone}) — today:{$countToday} overdue:{$countOverdue}");
    }

    // Small delay between sends to avoid rate limiting
    usleep(500000); // 0.5 seconds
}

// ── Alert admin about unmapped staff ─────────────────────────────────────
// On Uganda once a day, like the briefs: a second run the same day tells the administrator nothing new.
if (!empty($unmapped) && (!$_sbUg || $notify->dedupMark('STAFFBRIEF:unmapped:' . $todayStr))) {
    $names = implode(', ', $unmapped);
    $adminMsg = "⚠️ *Staff Jobs Summary — Mapping Alert*\n\n"
        . ($_sbUg ? "The following staff members have no verified uCRM user link and "
                  : "The following staff members have no UCRM User ID set and ")
        . "did NOT receive today's job summary:\n\n"
        . implode("\n", array_map(fn($n) => "  • {$n}", $unmapped)) . "\n\n"
        . ($_sbUg ? "Fix: Plugin → Manage Retailers → Edit each person → pick their uCRM user (the link is verified by e-mail).\n"
                  : "Fix: Plugin → Manage Retailers → Edit each person → set UCRM User ID.\n")
        . "Find IDs at: " . dn_crm_web($config) . "/nms/settings/users";
    $notify->sendAdmin($adminMsg, 'staff_jobs_unmapped_alert');
    log_msg_staff_jobs("Admin alert sent — unmapped staff: {$names}");
}

log_msg_staff_jobs('Staff jobs summary cron complete.');
// Renamed from log_msg(). master.php includes every scheduled script into one
// process, so two scripts declaring the same function name is a redeclare
// fatal — E_COMPILE_ERROR, which no try/catch can catch. crm_sync declared
// log_msg() first and jobs_cache died on it, stopping the cycle at job 21.
// Guarding with function_exists() would stop the fatal and silently route
// this script's lines into another script's log file, so: unique names.

function log_msg_staff_jobs(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [staff_jobs_summary] ' . $msg . "\n";
}
