<?php
/**
 * run_with_mail_class.php — TEST ONLY (5.18.54, docs/46 rows 23 and 40). Runs a plugin script with MailService already
 * loaded, as a master cycle started from public.php's piggyback cron has it (public.php loads MailService; main.php's
 * tick does not). Run from the plugin directory, as SjSandbox::run() does. Nothing leaves the machine.
 *
 *   php run_with_mail_class.php <script, relative to the plugin directory>
 */
$__dnScript = (string)($argv[1] ?? '');
if ($__dnScript === '' || !is_file(getcwd() . '/' . $__dnScript)) { fwrite(STDERR, "usage: run_with_mail_class.php <script>\n"); exit(2); }
require_once getcwd() . '/lib/MailService.php';
$argv = [$__dnScript];
$argc = 1;
include getcwd() . '/' . $__dnScript;
