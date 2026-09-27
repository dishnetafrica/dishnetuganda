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
