# 5.18.50 (release A) — evidence

The build report is **docs/44 §16**. These files are what it rests on. Nothing here comes from the server: every run
is local, against fakes. Nothing had been deployed when they were made.

**Since then:** the operator deployed 5.18.50 on 27 September at 20:08 UTC: **PASSED, 41 ok / 0 failed / 1 note**.
That run is recorded in **docs/44 §16.9**. Its log files stay on the server, root only, in `/root/dnb-5.18.50/`: the
deploy log, and the logs of two rollback runs that stopped at their question.

| File | What it is |
|---|---|
| `suite-run1-tally.txt`, `suite-run2-tally.txt` | The full plugin suite (`tests/run.sh`), twice, on plugin commit `125fa0c`: assertions passed and failed per file, the totals, the exit code and the duration |
| `new-suites-run1.txt` | The whole output of the eight new suites in run 1, including each weakened copy and whether it was caught |
| `rehearsal-run1.log`, `rehearsal-run2.log` | `scripts/harness/deploy-5.18.50/rehearse.sh`, twice, on the committed deploy script: every check, with its scenario |
| `rehearsed-deploy.log` | What the operator's terminal and log would show for the deploy, from rehearsal run 1 (sandbox paths shortened to `<sandbox>`) |
| `rehearsed-rollback.log` | The same for `--rollback`, from rehearsal run 1 |

Personal data: none. The suites use made-up accounts (`…@example.test`, sample numbers such as `0772 123 456`); the
rehearsal's staff rows are made up too, and its log is checked to print none of them.
