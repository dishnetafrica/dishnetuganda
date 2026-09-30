# 5.18.54 — evidence

The build record is **docs/46**: §A the checklist, §B the build, §G the checks after deployment, §H the final report.
These files are what §H rests on. Nothing here comes from the server: every run is local, against fakes. Nothing had
been deployed when they were made.

| File | What it is |
|---|---|
| `suite-run1-tally.txt`, `suite-run2-tally.txt` | The full plugin suite (`tests/run.sh`), twice, on plugin commit `e8a8508`, in the main checkout: assertions passed and failed per file, the totals, the exit code and the duration |
| `suite-environment.txt` | The runner's own record: PHP and system versions, the check that nothing under `dishnet-hybrid-sudan/` differed from `e8a8508` before run 1 and after run 2, each run's start, duration and exit code |
| `php81/lint81.txt` | `php -l` under PHP 8.1.34 (php-wasm, the server's version) on each of the 90 PHP files 5.18.54 adds or changes |
| `rehearsal-run1.log`, `rehearsal-run2.log` | `scripts/harness/deploy-5.18.54/rehearse.sh`, twice, on the committed deploy script (sha256 `b606ad2f7d49ab3a…`): every check, with its scenario |
| `rehearsed-deploy.log` | What the operator's terminal and log would show for the deploy, from rehearsal run 2 (sandbox paths shortened to `<sandbox>`) |
| `rehearsed-deploy-branch-ahead.log` | The same deploy with a later release already on the branch: 5.18.54 goes in by its hash |
| `rehearsed-after-only.log` | `--after-only`, three hours after the deploy, with nothing planted |
| `rehearsed-rollback.log` | The same for `--rollback` |

**Facts to read these runs with.**

- Run 1 ran 14:02–14:31 UTC and run 2 14:31–15:01 UTC on 30 September, one after the other, in the main checkout, under
  PHP 8.4.19 (this machine's; the server runs 8.1.34, see `php81/`). **The two runs' counts are identical, file by
  file.**
- The deploy script and its rehearsal were committed during run 1 (`fde675c`), and the branch was pushed. That commit
  holds only `scripts/`, so the plugin's files were `e8a8508`'s throughout. The runner checked this before run 1 and
  after run 2: no tracked change and nothing in `git status` under `dishnet-hybrid-sudan/`.
- **Against 5.18.53's runs** (`docs/evidence/5.18.53/suite-run*-tally.txt`), file by file:
  - 226 files have the same counts;
  - 20 files are new: this work's suites;
  - one changed: `test_staff_jobs_south_sudan.php`, 37 → 51. The 14 new checks are the seven pages this work
    touches, now compared with 5.18.53's byte for byte, one control, and six weakened copies (docs/46 §B: "Found
    by the first full run", rows 22 and 39, and row 30).
- **A first attempt at these runs is not counted.** It ran in a git worktree under this session's private scratch
  directory and was stopped after 112 files.
  - There `test_cli_data_dir.php` failed 9 of its 19 checks. The test runs part of itself as the unprivileged user
    `nobody`, who cannot enter that directory. Measured: `nobody` cannot read the plugin's `lib/bootstrap_data.php`
    in the worktree, and can in the main checkout. docs/46 §B records the same finding from the first full run.
  - Stopping the attempt cut two files short.
- **Two trial rehearsals ran on the script before its commit.** The first failed 3 checks, from two faults, both fixed
  before the commit:
  - **in the script:** R14's line naming each copy's WhatsApp connection could be split by a deprecation notice. PHP
    8.4 prints it while loading `lib/EvolutionApiService.php`, which this work does not change; the server's PHP 8.1
    prints none. Two of the 3 failures came from this. Both answers are now computed before the line is printed.
  - **in the harness:** case 4w stamped its schedule with the rehearsal's start time, so *"10 min ago"* depended on
    how long the earlier cases took. It now stamps it when the case runs.

  That rehearsal then stopped at stage 8 with a shell syntax error. The harness had been edited while it was
  running, and bash reads a script as it goes: the fault was the edit, not the harness.

  The second trial passed: 227 checks, 0 failed. The committed script is byte for byte the one it ran (sha256
  `b606ad2f…`).
- The two rehearsals here ran while the suite was running, as 5.18.53's did.
- `test_customer_pwa.php` still leaves its `php -S` server running after a run. The runner stopped it after each
  run and recorded it in `suite-environment.txt`.

Personal data: none. The suites and the rehearsal use made-up accounts and numbers, and the rehearsal checks that its
log prints none of them.
