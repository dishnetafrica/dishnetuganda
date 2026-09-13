#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();

/**
 * cron/dr_snapshot.php — hold a copy of dishnet-data-report's data daily.
 *
 * Purpose:
 *   uCRM DELETES <plugin>/data when a plugin is upgraded. dishnet-data-report
 *   keeps its Starlink session cookies, its router map and the record of
 *   which customers are currently blocked in exactly that directory, and its
 *   own backup writes inside it. tools/dr_snapshot.php has been able to copy
 *   all of it somewhere safe since it was written — but only when somebody
 *   remembered to run it before an upgrade nobody announces in advance.
 *
 *   Nobody remembered. This runs it on a schedule instead.
 *
 * What it does:
 *   Once a day, copies that plugin's ten files into
 *   <our data dir>/dr_snapshots/<UTC timestamp>/, skips the copy entirely
 *   when nothing has changed since the last one, and keeps a fortnight.
 *
 * What it does NOT do:
 *   Restore. Putting a stale router map back over a fresh one is its own way
 *   to lose the current state, so that stays a person's decision:
 *     php tools/dr_snapshot.php --list
 *     php tools/dr_snapshot.php --restore <name> --yes
 *
 * Called by:
 *   cron/master.php as 'dr_snapshot' (every 86400s)
 *   Or directly: php cron/dr_snapshot.php
 *
 * Exit protocol:
 *   `return;` and never exit() — master.php INCLUDES this file, and an exit
 *   here would abort every cron scheduled after it in the same tick.
 */

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';
require_once dirname(__DIR__) . '/lib/DrSnapshot.php';

// One root for both sides of this job. In production SiblingPlugin::pluginRoot()
// IS dirname(__DIR__); they differ only when a test stands the plugin up
// somewhere else, and a cron that read its siblings from the test tree while
// writing its snapshots into the real data directory would be worse than
// untestable — it would quietly do that on any install with DN_PLUGIN_ROOT set.
$_ds_root     = SiblingPlugin::pluginRoot();
$_ds_dataDir  = getDataDir($_ds_root);
$_ds_snapRoot = DrSnapshot::root($_ds_dataDir);
$_ds_drData   = SiblingPlugin::dataDir(DrSnapshot::PLUGIN);

$_ds_log = static function (string $msg): void {
    // Same voice as the other crons: one line, and only when it says something.
    echo '[dr_snapshot] ' . $msg . "\n";
};

$_ds_r = DrSnapshot::take($_ds_snapRoot, $_ds_drData, $_ds_dataDir, true);

switch ($_ds_r['status']) {
    case 'saved':
        $_ds_pruned = DrSnapshot::prune($_ds_snapRoot);
        $_ds_log("saved {$_ds_r['saved']} file(s) as {$_ds_r['name']}"
            . ($_ds_pruned ? ' · pruned ' . count($_ds_pruned) : ''));
        break;

    case 'unchanged':
        // Not worth a line every day. Silence here means the data is held.
        break;

    case 'not_installed':
        // Only worth saying once a day, and it is genuinely worth saying: it
        // means every cross-plugin figure on every screen is reading nothing.
        $_ds_log($_ds_r['reason']);
        break;

    default:
        $_ds_log('FAILED — ' . ($_ds_r['reason'] !== '' ? $_ds_r['reason'] : $_ds_r['status']));
        break;
}

unset($_ds_root, $_ds_dataDir, $_ds_snapRoot, $_ds_drData, $_ds_log, $_ds_r, $_ds_pruned);
return;
