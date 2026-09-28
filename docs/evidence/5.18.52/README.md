# 5.18.52 — evidence

The build record is **docs/44 §16.14**. These files are what it rests on. Nothing here comes from the server: every run
is local, against fakes. Nothing had been deployed when they were made.

| File | What it is |
|---|---|
| `suite-run1-tally.txt`, `suite-run2-tally.txt` | The full plugin suite (`tests/run.sh`), twice, on plugin commit `fc5c3b7`, in the main checkout: assertions passed and failed per file, the totals, the exit code and the duration |
| `suite-preliminary-tally.txt` | The same suite once more, run just before the commit on the identical files |
| `job-suites-run1.txt` | The whole output of the four job suites in run 1: `test_job_messages.php`, `test_job_notifier.php`, `test_job_notifications_day.php`, `test_job_status_truth.php` |
| `rehearsal-run1.log`, `rehearsal-run2.log` | `scripts/harness/deploy-5.18.52/rehearse.sh`, twice, on the committed deploy script (sha256 `2290c981…`): every check, with its scenario |
| `rehearsed-deploy.log` | What the operator's terminal and log would show for the deploy, from rehearsal run 2 (sandbox paths shortened to `<sandbox>`) |
| `rehearsed-deploy-branch-ahead.log` | The same deploy with a later release already on the branch: 5.18.52 goes in by its hash |
| `rehearsed-after-only.log` | `--after-only`, three hours after the deploy, with nothing planted |
| `rehearsed-rollback.log` | The same for `--rollback` |

**Facts to read the suite runs with.**
- **All three runs, 22:42–23:42 UTC, fell inside the 21:00–24:00 window** in which 5.18.51's badge test failed. It passes
  27 of 27 in all three.
- **`test_quote_tax_line.php` passes 31 of 31**, not 29 as in 5.18.51's evidence: these runs are in the main checkout,
  where its comparison with `4c01d1c` runs.
- **`test_customer_pwa.php` leaves a `php -S` server running after each run** (docs/44 §16.14, found on the way). It
  changes no result; the leftover servers were stopped by hand.
- **The deploy rehearsals ran while suite run 1 was running** (run 1 was 23:02–23:22 UTC; each rehearsal took about
  four minutes). Run 1's counts are identical, file by file, to run 2's and to the preliminary run's.

Personal data: none. The suites use made-up accounts, and so does the rehearsal; its log is checked to print none of
them.

## The e-mail copy — plugin commit `7ad465e` (docs/44 §16.16)

5.18.52 was rebuilt with the engineer's e-mail on 28 September; `fc5c3b7` above was never deployed. These files are
what §16.16 rests on. Everything here is local and runs against fakes, and nothing had been deployed when they were made.

| File | What it is |
|---|---|
| `email-suite-run1-tally.txt`, `email-suite-run2-tally.txt` | The full plugin suite, twice, on `7ad465e`, in the main checkout: assertions passed and failed per file, the totals, the exit code and the duration |
| `email-job-suites-run1.txt` | The whole output of the four job suites in that run 1 |
| `email-php81/` | Under PHP 8.1.34 (php-wasm, the server's version): `lint81.txt` (every PHP file of the change), `test_job_messages-php81.txt` (132 of 132), and `drive.php`, which drives the real notifier in-process; its output `out81.json` is byte-identical to `out84.json` under PHP 8.4.19 |
| `email-rehearsal-run1.log`, `email-rehearsal-run2.log` | `scripts/harness/deploy-5.18.52/rehearse.sh`, twice, on the deploy script of `e5d3264` |
| `email-rehearsed-deploy.log`, `email-rehearsed-deploy-branch-ahead.log`, `email-rehearsed-after-only.log`, `email-rehearsed-rollback.log` | As the operator's log would show them, from rehearsal run 2, with the sandbox path shortened to `<sandbox>` |
| `email-rehearsal-run3-at-approval.log` | The same rehearsal once more at the approval (docs/44 §16.19), on the branch tip `a6198c2`: 170/170 |

**Facts to read these runs with.**
- Run 1 ran 05:32–05:50 UTC, and run 2 05:51–06:08 UTC, beside both deploy rehearsals. Their counts are identical, file
  by file.
- Against `fc5c3b7`'s runs, only the three job suites differ: `test_job_messages.php` 107 → 132, `test_job_notifier.php`
  97 → 130 and `test_job_notifications_day.php` 46 → 50.
- `test_customer_pwa.php` still leaves its `php -S` server running after a run (§16.14); each run's was stopped by hand.

Personal data: none. The suites and the rehearsal use made-up accounts, and the rehearsal checks that its log prints
none of them.

## The job-log check — docs/44 §16.21

| File | What it is |
|---|---|
| `job-log-reader/reader.php` | The read-only check handed over for job #8: one job's lines from the plugin's webhook log, masked |
| `job-log-reader/sample-webhook_log.json` | A made-up log it was tested on: job #8's lines, a client event with the same id, jobs #80 and #9 |
| `job-log-reader/job-events.php` | The read-only list of every job event since a time, and what each did (§16.24's follow-up); tested on a made-up log under PHP 8.4.19 and 8.1.34 |
| `job-log-reader/out-php84.txt`, `job-log-reader/out-php81.txt` | Its output for jobs 8, 80, 9 and 7 under PHP 8.4.19 and 8.1.34 (php-wasm): identical |

Personal data: none. The sample's names, addresses and number are made up, and neither output prints them.

## PHP's code cache — docs/44 §16.23

| File | What it is |
|---|---|
| `opcache/repro.sh`, `opcache/repro-output.txt` | OPcache with the server's settings keeps serving a PHP file whose content changed while its modification second did not; one second later it does not. PHP 8.4.19 |
| `opcache/check.sh` | The read-only check handed over: each file 5.18.52 changed, its second in the 5.18.51 backup and now |
| `opcache/fix.sh` | The fix handed over: a new timestamp for the 20 installed files, each first checked against `7ad465e`; content unchanged |
| `opcache/simulation.txt` | Both run on a simulated install built from the two commits: the check before, the fix, the check after |
