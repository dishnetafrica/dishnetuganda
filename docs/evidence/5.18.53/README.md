# 5.18.53 — evidence

The build record is **docs/44 §16.29**. These files are what it rests on. Nothing here comes from the server: every run
is local, against fakes. Nothing had been deployed when they were made.

| File | What it is |
|---|---|
| `suite-run1-tally.txt`, `suite-run2-tally.txt` | The full plugin suite (`tests/run.sh`), twice, on plugin commit `6b71ea6`, in the main checkout: assertions passed and failed per file, the totals, the exit code and the duration |
| `suite-preliminary-tally.txt` | The same suite once more, run just before the commit on the identical files |
| `job-records-race-run1.txt` | The whole output of `test_job_records_race.php` in suite run 1: the race with and without the fix, 5.18.52 exactly, each line of the plugin log, South Sudan, twelve weakened copies |
| `php81/lint81.txt` | `php -l` under PHP 8.1.34 (php-wasm, the server's version) on every PHP file 5.18.53 changes |
| `php81/drive.php`, `php81/out81.txt`, `php81/out84.txt` | A driver that runs the plugin-log helper and the open read, with and without `closeCursor()`: its output under PHP 8.1.34 and 8.4.19, identical but for the version line |
| `rehearsal-run1.log`, `rehearsal-run2.log` | `scripts/harness/deploy-5.18.53/rehearse.sh`, twice, on the committed deploy script (sha256 `399568eed23fd37c…`): every check, with its scenario |
| `rehearsed-deploy.log` | What the operator's terminal and log would show for the deploy, from rehearsal run 2 (sandbox paths shortened to `<sandbox>`) |
| `rehearsed-deploy-branch-ahead.log` | The same deploy with a later release already on the branch: 5.18.53 goes in by its hash |
| `rehearsed-after-only.log` | `--after-only`, three hours after the deploy, with nothing planted |
| `rehearsed-rollback.log` | The same for `--rollback` |

**Facts to read these runs with.**
- The preliminary run ran 05:17–05:36 UTC on 30 September, on the files later committed as `6b71ea6`. Run 1 ran
  05:36–05:54 and run 2 06:08–06:27 UTC, on the commit. The three runs' counts are identical, file by file.
- Against 5.18.52's runs (`7ad465e`, `docs/evidence/5.18.52/email-suite-run*-tally.txt`), all 226 files have the same
  counts; `test_job_records_race.php` (46) is new.
- **A first attempt at run 2 was stopped by this session's 30-minute limit on background commands**, after 135 files,
  each with run 1's counts, and no failure. Run 2 was then run again from the start; its tally is the one here.
- The two rehearsals ran while the suite was running (run 1, then the stopped attempt at run 2), as 5.18.52's did.
- `test_customer_pwa.php` still leaves its `php -S` server running after a run (§16.14); each run's was stopped by hand.
- **One rehearsal expectation was wrong before these runs, and was corrected in the harness, not the script:** it took
  the installed files' time to be 7ad465e's commit time. `git archive` of a path (`7ad465e:dir`) stamps the current
  time instead. The first rehearsal, on the script before its commit, failed that one check (197 passed, 1 failed);
  the check now compares with the installed file's time read just before the deploy.

Personal data: none. The suites and the rehearsal use made-up accounts, and the rehearsal checks that its log prints
none of them. The test's own *"refused"* message carries a made-up number and address, to prove both are masked.
