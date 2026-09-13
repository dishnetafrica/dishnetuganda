#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();

/**
 * cron/starlink_usage.php — collect Starlink usage on our own session.
 *
 * Purpose:
 *   dishnet-data-report fetches usage only for KITs typed into its Manual
 *   KIT → Service Line Map. Uganda's were never typed in, so its
 *   sl_usage.json has been `[]` on every run since the box was built, and the
 *   Fleet screen has had a correctly-bound customer and nothing to say about
 *   them.
 *
 *   We already hold the pairing that map exists to supply, so we make the
 *   call ourselves, against the endpoint proven on 2026-09-13:
 *     /api/telemetryagg/v1/data-usage/account/{acc}/service-line/{sl}/annotated
 *
 * Cadence:
 *   Hourly. Billing cycles move once a day; the reason not to go slower is
 *   that a Starlink access token dies in minutes, so a run that finds the
 *   session expired should get another chance before the day is out.
 *
 * What it will not do:
 *   Write an empty file. Nothing collected is not the same as nothing used,
 *   and an empty usage file reads as a fleet at zero on every screen.
 *
 * Exit protocol:
 *   `return;` and never exit() — master.php INCLUDES this, and an exit here
 *   would abort every cron scheduled after it in the same tick.
 */
if (PHP_SAPI !== 'cli') return;

$_su_root = dirname(__DIR__);
require_once $_su_root . '/lib/error_handler.php';
require_once $_su_root . '/lib/bootstrap_data.php';
require_once $_su_root . '/lib/StoreInterface.php';
require_once $_su_root . '/lib/JsonStore.php';
require_once $_su_root . '/lib/SqliteStore.php';
require_once $_su_root . '/lib/PluginConfig.php';
require_once $_su_root . '/lib/EquipmentAssignment.php';
require_once $_su_root . '/lib/StarlinkSessionStore.php';
require_once $_su_root . '/lib/StarlinkUsage.php';

$_su_dataDir = getDataDir($_su_root);
$_su_config  = PluginConfig::load($_su_root, $_su_dataDir);
$_su_session = new StarlinkSessionStore($_su_root, $_su_dataDir);

if ($_su_session->accounts() === []) return;   // no session imported here

try {
    $_su_ea = EquipmentAssignment::fromStore(SqliteStore::create($_su_dataDir));
    $_su_live = $_su_ea->liveAssignments();
} catch (\Throwable $e) {
    error_log('[starlink_usage] could not read assignments: ' . $e->getMessage());
    return;
}
if ($_su_live === []) return;                  // nothing bound, nothing to ask about

$_su_res  = (new StarlinkUsage($_su_session, $_su_config))->collect($_su_live);
$_su_rows = $_su_res['rows'];

if ($_su_rows === []) {
    // Worth one line: a fleet with bound customers and no readings is either a
    // dead session or a binding without an account, and both need a person.
    $_su_why = [];
    foreach ($_su_res['report'] as $r) $_su_why[$r['status']] = ($_su_why[$r['status']] ?? 0) + 1;
    $_su_bits = [];
    foreach ($_su_why as $k => $n) $_su_bits[] = $n . ' ' . $k;
    error_log('[starlink_usage] collected nothing — ' . implode(', ', $_su_bits)
            . '. Run tools/usage_collect.php for the detail.');
    return;
}

$_su_saved = (new StarlinkUsage($_su_session, $_su_config))->save($_su_dataDir, $_su_rows);
if (empty($_su_saved['ok'])) {
    error_log('[starlink_usage] ' . $_su_saved['why']);
}

unset($_su_root, $_su_dataDir, $_su_config, $_su_session, $_su_ea, $_su_live,
      $_su_res, $_su_rows, $_su_saved, $_su_why, $_su_bits);
return;
