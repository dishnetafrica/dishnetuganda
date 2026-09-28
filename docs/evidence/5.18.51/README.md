# 5.18.51 — evidence

The build record is **docs/44 §16.13**. These files are what it rests on. Nothing here comes from the server: every run
is local, against fakes. Nothing had been deployed when they were made.

| File | What it is |
|---|---|
| `suite-run1-tally.txt`, `suite-run2-tally.txt` | The full plugin suite (`tests/run.sh`), twice, on plugin commit `240f2f9`, in a git worktree the `nobody` user can read: assertions passed and failed per file, the totals, the exit code and the duration |
| `new-suite-run1.txt` | The whole output of the new suite, `tests/test_master_lock_release.php`, in run 1 |
| `rehearsal-run1.log`, `rehearsal-run2.log` | `scripts/harness/deploy-5.18.51/rehearse.sh`, twice, on the committed deploy script (sha256 `68bb012e…`): every check, with its scenario |
| `rehearsed-deploy.log` | What the operator's terminal and log would show for the deploy, from a third rehearsal run (sandbox paths shortened to `<sandbox>`) |
| `rehearsed-deploy-branch-ahead.log` | The same deploy with a later release already on the branch: 5.18.51 goes in by its hash |
| `rehearsed-rollback.log` | The same for `--rollback` |
| `badge-window-diagnostic.txt` | Why both suite runs show 2 failures: `test_job_status_truth.php` as written, and with its fake log on Kampala time |
| `webhook-log-clock-measurement.txt` | One real uCRM event through a Uganda sandbox's `public.php`: the webhook log is written in Kampala time, so the badge is right and the test's clock is wrong |
| `jobs-facts-rehearsal-run1.log`, `jobs-facts-rehearsal-run2.log` | `scripts/harness/jobs-facts/rehearse.sh`, twice, after the jobs facts report learned to count `job.edit` and `job.delete` as 5.18.51 logs them, before M4 relies on it (docs/44 §16.17): 391/391 each, 10 weakened copies caught |

**Two facts to read the suite runs with.**
- **Both runs show 2 failures:** `test_job_status_truth.php`'s two badge assertions. They fail only between 21:00 and
  24:00 UTC, and both runs fell inside that window. The test's fake webhook log uses the wrong clock; the badge itself
  is right (docs/44 §16.12). 5.18.52 corrects the test.
- **`test_quote_tax_line.php` ran 29 of its 31 assertions.** In a git worktree `.git` is a file, so the test skips its
  comparison with `4c01d1c` and says so. At the same plugin commit, in the main checkout, it passes 31 of 31.

Why a worktree the `nobody` user can read: `test_cli_data_dir.php` runs part of itself as `nobody`, which cannot enter
the session's scratch directory. The first attempt ran there, and 9 of that test's checks failed for that reason alone.

Personal data: none. The suites use made-up accounts, and so does the rehearsal; its log is checked to print none of
them.
