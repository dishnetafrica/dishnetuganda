<?php
declare(strict_types=1);
/**
 * TEST ONLY (5.18.104, root docs/73 §H.2.14 TL33; B11-02, IO-8), B9-fix1: one OfferSender::runOnce() of a plugin tree, in
 * a process of its own, as cron/offers_send.php runs it — the configuration read from the files, Part F's real ledger,
 * the real Evolution gateway — with only the clock injected (unix time), so a test can hold its send at the fake
 * Evolution while the switch tool runs `off sending` beside it. Prints the run's answer as one JSON line.
 *
 *   php run_offers.php <tree> <data folder> <unix time>
 */
if (PHP_SAPI !== 'cli' || count($argv) !== 4) { fwrite(STDERR, "usage: run_offers.php <tree> <data> <unix time>\n"); exit(2); }
[, $tree, $data, $t] = $argv;
date_default_timezone_set('UTC');
foreach (['bootstrap_data', 'timezone', 'StoreInterface', 'SqliteStore', 'PluginConfig', 'OfferSender'] as $l) require_once $tree . '/lib/' . $l . '.php';
$config = PluginConfig::read($tree, $data);
$at = (int)$t;
$r = OfferSender::forInstall($tree, $data, $config, ['clock' => function () use ($at): int { return $at; }])->runOnce(null);
echo json_encode($r) . "\n";
