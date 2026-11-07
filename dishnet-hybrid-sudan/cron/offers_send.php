<?php
declare(strict_types=1);
// 5.18.104 P6 (root docs/73 §H.4.10): offers on the Evolution numbers. Included by cron/master.php in its own scope:
// everything here lives inside the closure, which is given master's deadline (cron/master.php:109-110) and nothing else.
if (PHP_SAPI !== 'cli') return;
$__offersDeadline = isset($_m_deadline) ? (int)$_m_deadline : null;
(function (?int $deadline): void {
    $root = dirname(__DIR__);
    require_once $root . '/lib/error_handler.php';
    require_once $root . '/lib/bootstrap_data.php';
    require_once $root . '/lib/SqliteStore.php';
    require_once $root . '/lib/PluginConfig.php';
    require_once $root . '/lib/OfferSwitch.php';
    $dataDir = getDataDir($root);
    $config  = PluginConfig::load($root, $dataDir);
    if (!OfferSwitch::live($config, $dataDir)) return;            // before any store is opened
    require_once $root . '/lib/OfferSender.php';
    $r = OfferSender::forInstall($root, $dataDir, $config)->runOnce($deadline);
    if ($r['sent'] || $r['uncertain'] || $r['failed'] || $r['skipped'] || $r['deferred'] || $r['code'] !== '') {
        echo gmdate('Y-m-d H:i:s') . sprintf(" [offers_send] sent=%d uncertain=%d failed=%d skipped=%d deferred=%d handsets=%d code=%s\n",
            $r['sent'], $r['uncertain'], $r['failed'], $r['skipped'], $r['deferred'], $r['handsets'], $r['code']);
    }
})($__offersDeadline);
unset($__offersDeadline);
