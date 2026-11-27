# §T.11 cases against scripts/deploy-5.18.107.sh (27 Nov; read-only map, the script at d790edf pinned to 771a3c1 — line numbers are that build's)

## Map of the §T.11 rehearsal cases to `scripts/deploy-5.18.107.sh`

**Notation**
- S = /home/user/dishnetuganda/scripts/deploy-5.18.107.sh.
- §T.11 is at /home/user/dishnetuganda/wip/design-5.18.107/76-uganda-quotation-quoted-no-ai-week-5-18-107-design.md:3386–3596.

**Helpers and exit status**
- `ok` prints "  ok    ", `bad` "  FAIL  ", `note` "  note  ", `notyet` "  wait  " (S:1825–1828).
- `stop` prints "  STOP: …", then "Nothing was changed." (or "Nothing further was done." once `TOUCHED=1`, S:13855), and exits 1 (S:1831).
- Run exit status: FAILED 1 (S:16291), NOT YET 3 (S:16292), PASSED 0.

**State file**
- File: `$OUT/state-5.18.107.env` (S:785).
- Keys written at DEPLOY:
  - S:13863: `QF_G1`, `QF_BASE`, `QF_COPY_AT`, `LK_MAP`, `LK_ON`, `LK_SKIP`, `LK_AT`.
  - S:13866: **`MG_G1`, `QF_COPY_UNIX`**.
  - S:13885: **`COPY_AT`**.
- `MG_CA2_SEEN=1|0|nowait` is written by `mg_after` in the deploy run only (S:8459–8461).
- `DB_BEFORE` is written at S:13773, `BD_A` at S:13790.
- `RB_COPY_AT` is written at S:13841.
- In `--after-only`, `mg_after` reads `MG_G1` and `QF_COPY_UNIX` (S:8405), `MG_CA2_SEEN` (S:8476), and `me_ca1` reads `COPY_AT` (S:8370).

### MG / QF / NA (stage A, `qf_stage` S:8504)
| Case | Lines | Grep | Notes |
|---|---|---|---|
| First deploy | 8526–8527 | `MG-G1 the copy's migration ledger has no 092` | `MG_G1=absent` |
| Trial line | 8564 | `extra=092_wa_no_ai.sql:ok:OK:7` | the line is cut to 600 characters |
| NA-G1's probes | 8604–8608 | `  wa_no_ai (NA-G1) p1=none` | no ok line of its own; folded into QF-G2 |
| QF-G2 PASS | 8635 | `applied, ok, its own migration.log OK with its 7 statements` | |
| neg092 | 8628 | `negative control (092) reset=0 status=partial partial=1 check=1 row=0` | rule at 8629–8632 |
| Pairing | 8631 → 8634 | `092-negative-control-pairing(MG-G1:absent;reset=1)` inside `NO-GO (QF-G2): the one migration trial` | MG-G1 still prints its "absent" ok line; other tokens (`092:…`, `NA-G1(…)`) may also be listed |
| Partial 092, or other bytes | 8530 | `NO-GO (MG-G1): the book holds a 092 that is not whole` | |
| Redeploy, 092 whole | 8528–8529, 8635, 8628 | `holds 092 whole`; `already in the copy's ledger, applied by an earlier deploy (MG-G1), no line logged`; `reset=1 status=partial` | `MG_G1=present` |

### NS (`ns_check` S:7521; the PHP verdicts are at S:6475–6487)
| Case | Line | Grep |
|---|---|---|
| A switch on in p, d or k | 7528 | `NO-GO (NS-G1): <key> in k reads on` (place letters, not file names; `ns_say` S:7517) |
| The positive control fails | 7524 | `NOT ESTABLISHED — THE SWITCH RULE'S POSITIVE CONTROL DID NOT HOLD` |
| `'0'` present | 7530 | `read off in every place their readers read` |
| Store row alone | 7531 | `the store row holds … reading on — reported, never judged` |
| A text key set | 7533 | `NS-G1 the texts and W.1's window, set per place` |
| NS-B1 | 7565 → 7528 | `it was turned on during this run, after stage A. Nothing was copied.`; exit 1 |

The NS-B1 plant has to happen while the script holds at the DEPLOY prompt (S:13852).

### Stage A's notes (`sa_stage` S:8053; never stops)
| Case | Line | Grep |
|---|---|---|
| Settings text v9 | 8066 | `v9 as the workers read it` |
| Settings text not v9 | 8066 | `NOT v9 as the workers read it` |
| StaffJobsGate false | 8078 | `SA STAFFJOBSGATE::APPLIES IS FALSE` |

`SA_TXT_V9` is at S:8028.

### The deploy, PASSED (`mg_after` S:8401, called at S:14456 after R1 at S:14416)
| Case | Lines | Grep |
|---|---|---|
| MG-CA1 | 8429 | `MG-CA1 092 on a fresh copy after the copy` |
| NA-CA1 | 8442 | `NA-CA1 wa_no_ai reads the way the worker reads it` |
| MG-CA2 | 8469 | `holds 092's OK line` |
| R7 applied | 14874 → 12063 | `moved by exactly 092, applied at a boot` (`db_judge` S:12051, `db_mig107` S:12045) |
| LK-CA2, one row more | 7628 | `but one added, exactly 092_wa_no_ai.sql:5/5` |
| 092 applied between extraction and `stamp_copy` | 8398 | the script sets no bound on `applied_at`; MG-CA1 stays ok |
| `QF_COPY_UNIX` note | 8434–8435 | `092 was applied before this copy began`, keyed on `MG_G1=absent` and `applied_at < QF_COPY_UNIX` |
| LK-B1's pause | 7573 | `waiting 6 s` |
| The baseline | 7578 | `QF_COPY_UNIX` taken from the `unix=` field of `QF_BASE` |
| LK-CA2 exact | 7627 | `every _schema_checks row unchanged across the copy (` |
| Redeploy note | 8436 | `applied by an earlier deploy, before this copy` |
| Straddle note | 8465–8466 | `the tar straddle`, only when ok1, ok2 and r1 all hold |
| WARNING not judged | 8467 | `not judged here:`; `R1 reads the installed migrations/092_wa_no_ai.sql other than the pin's` |

Detail on the straddle rows:
- `r1` is the sha256 of `$DEST/migrations/092_wa_no_ai.sql` against `MIG107_SHA` (S:8414, S:857).
- The WARNING is matched by its prefix (S:8455).
- R1's own FAIL is S:14416, `R1 installed files that differ:`.

### Failures after the copy (summary lead `MG_SAY`, S:8489–8495 and S:16147–16172)
| Case | Lines | Grep |
|---|---|---|
| `wa_no_ai` renamed | 8447 + 8431 | NA-CA1 `does not read the way the worker reads it`; MG-CA1 `is not whole (`; lead S:16150 `NO UGANDA CUSTOMER GETS AN AI REPLY` |
| Another md5 | 8431 | lead S:16161 `092 is not shown whole`, then `MG-G1 refuses every redeploy`, then `AI replies go on.` |
| No first boot | 8408–8420 | `is not in the live migration ledger after a wait of 60 s`; `MG_CA2_SEEN=nowait`; MG-CA2 S:8473 `NO BOOT HAD APPLIED 092 WHEN THE LOG WAS READ`; lead S:16150 |
| PARTIAL line | 8463 | `holds a PARTIAL line for 092`; lead S:16168 `MG-G1 would accept a redeploy` |

- In the no-first-boot case the wait reads the ledger 13 times, 5 s apart.
- In every FAIL case the verdict line (S:16179) reads `a failure after the copy means the rollback`.
- Then, each alone: `BD_OFF_CMD` (S:16266), `BD_DRAIN_CMD` (S:16268), `ROLLBACK_CMD` (S:16284).

### MG-CA2's missing line (S:8468–8485; the trim marker is the log's first line, S:8457)
| Case | Line | Grep |
|---|---|---|
| Stage R, OK line removed | 8474 | `NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT`; writes `MG_CA2_SEEN=0` |
| After-only, `MG_CA2_SEEN=0`, with or without a marker | 8478 | `STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0)` |
| `MG_CA2_SEEN=1` + marker | 8479 | `is the runner's trim marker — 071_dpo_payments.sql's checksum WARNING` |
| `MG_CA2_SEEN=1`, no marker | 8479 | `is no trim marker, so halving is not the cause` |
| `nowait` + marker at or after `applied_at` | 8482 | `stage R's wait timed out (MG_CA2_SEEN=nowait)` |
| `nowait` control (no marker) | 8483 | `NO OK LINE FOR 092, AND STAGE R'S WAIT TIMED OUT` |
| `MG_CA2_SEEN` removed | 8482 / 8483 | `the state file holds no MG_CA2_SEEN record;` or `THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD`, plus `is stamped before 092's applied_at` or `is no trim marker` |
| No `MG_G1` in the state file | 8470 | `THE STATE FILE HOLDS NO MG_G1 RECORD` |
| Redeploy with no line, or WARNING only | 8471 (+8466) | `no OK line for 092 in migration.log — a redeploy` |
| Sentinel row planted (`NA_SENTINEL_1`, S:861) | 8446 | `lists a number nobody can hold`; `MG_SAY=dat`, lead S:16158 |

### ME-CA1 (`me_ca1` S:8367, `me_say` S:8324)
| Case | Line | Grep |
|---|---|---|
| Deploy run | 8344 | `the deploy run makes no poll` |
| PASS | 8331 | `ME-CA1 PASS —` with the clause at S:8321 `errors master caught are not judged (plugin.log:` |
| `[master]` lines planted | 8319 | `caught errors judged from plugin.log: 0 ERROR` |
| A planted ERROR | 8313 | `ERROR line(s) of the four follow-up jobs` |
| (L, −1), then a later cycle | 8373–8389 → 8332 | `ME-CA1 waiting up to` … `ME-CA1 FAIL —` |
| No new cycle within the bound | 8336 | `master did not start a new cycle within the poll's bound` |
| A re-dispatch between two reads | 8335 | `the poll missed the window` |
| Unreadable schedule | 8333 | `MASTER'S OWN RECORD COULD NOT BE READ FROM THE LIVE STORE` |
| NOT YET summary | 16176–16178 | `(after-only): NOT YET —`, `run it again after`, then `AFTER_CMD`; exit 3 |

### ME-CA2 (S:8348)
| Case | Line | Grep |
|---|---|---|
| (L, −1) + later cycle | 8361 | `ME-CA2 FAIL —` |
| (L, −1), no later entry | 8359 inside the note at 8362 | `running since … (no later cycle yet)` |
| `notify_retry` | 8362 | the note line still prints; no FAIL line |

### The rollback
**`rb_pre107` (S:8937).** The prompt at S:13898 reads `Type ROLLBACK to put`; it carries `RB_PRE_SAY`, which says the switch's word.

| Case | Line | Grep |
|---|---|---|
| Switch off, no marks | 8962 / 8965 / 8971 | `RB107 waiting 125 s`, `again, 125 s later`, `drain holds` |
| Switch on | 8975 | `ANSWER ANYTHING BUT ROLLBACK BELOW`; the redeploy sentence `A REDEPLOY'S NS-G1 REFUSES WHILE IT READS ON` |
| …its commands | 8977, 8979 | the switch-off and the drain, each alone |
| Switch NOT READ | 8974 | `SWITCH NOT READ — ai_burst_merge_enabled: NOT READ (the pin is not staged:` |
| Step 5 (K and Q) | 8988 | `<key> COULD NOT BE READ (NS-G1'S RULE, ON LK-RB0)` |
| …its commands | 8990 | `K_OFF_CMD` / `Q_OFF_CMD`, defined at S:8909–8913, each logging to `setoff-<key>` |
| TD7 (a key reads on) | 8988, 8992 | `READS ON (NS-G1'S RULE, ON LK-RB0)`, then `it prints the line` |
| Settings text not v9 | 8998 | `settings text does not read v9` |
| RB-X | 9002, 9052 | `RB-X before the rollback`; `rb_x_say` at S:8919 |

**After the put-back.**

| Case | Line | Grep |
|---|---|---|
| `rb107` drain | 9054–9055 → 8890 | `HAND THESE CONVERSATIONS TO STAFF` (8896), `it lists no conversation` (8895), `IS INDETERMINATE — NO TYPED MESSAGE IN THE LAST DAY` (8894) |
| `RB_NOTHING` | 9029 (set at 13906) | `LK-RB1 not judged: this run rolled nothing back` |
| V1 | 13939 / 13944 / 13945 | `this run wrote no mark` (+`stage A counted leftover marks`); stop `rolled back (serves`; `the automatic rollback did not complete` |
| `do_rollback` | 13829–13844 | checkout failure: `git checkout bf29cbd failed` |

### Put-back paragraph and tools reading (`pb_para` S:9095)
**Readings and wording.**
- `pb_tool` (S:9064) hashes `$DEST/<file>` on the host against `PIN_SHA256S` (S:1397) and `PREV_CHANGED_SHA256S` (S:1315).
- `pb_word` returns `the pin's`, `bf29cbd's`, `absent`, `neither (an edit by hand)` or `not read`.
- The wording is chosen at S:9098–9100:
  - switch-off: `runs, and leaves '0'` / `is refused (undeclared)` / `may be refused`;
  - drain: `runs` / `is refused (an unknown step)` / `fails without reading anything` / `may fail (it may also run on bytes…` / `may be refused`;
  - `set_config`: `runs` / `is refused (unmanaged)` / `may be refused`.

**Lines it prints.**

| Line | Text |
|---|---|
| 9101 | `The tools installed now, by sha256 on the host` |
| 9102 | `The switch-off — it ` |
| 9103 | `The drain reading — it ` |
| 9107 | `A refusal changes nothing and saves nothing.` |
| 9108 | `Where the switch-off does not run` |
| 9115–9117 | the K and Q lines |
| 9124 | `Read at … One reading, not a drain.` |
| 9126 | `pre`: `Stage A refused before the pin was staged` |

**Callers.**

| Line | Site |
|---|---|
| 12406 | `BASE_DIFFERS` |
| 12373 | the manifest says 5.18.107 |
| 13891 | the deploy's record did not turn |
| 13905 | `rb` |
| 13945 | `v1` |
| 16288 | `sum` |

`pb_ready` (S:9079) runs before each caller.

**At the `BASE_DIFFERS` stop.**
- S:12399–12406 prints `these installed files are not 5.18.106's:`.
- `P3B_PIN=""` is set at S:12360, so the reading is `NOT READ (the pin is not yet staged)`.
- `DB_OWNER` is known by then (S:12334), so the K and Q lines print.

### `--redeploy`, and the printed commands
**`--redeploy`.**
- R7 S:12063: `its last row the same (`.
- LK-CA2 exact at S:7627; neg092 `reset=1`; MG-CA1 note at S:8436.
- NS-G1's ok line at S:7530.

**Printed commands.**
- `LOGW` S:1705.
- AFTER S:1706, ROLLBACK S:1707, REDEPLOY S:1712.
- BD_OFF, BD_DRAIN and W1_ON S:7330–7332.
- PUTBACK S:12317.
- Owner's steps S:16189–16239: `K_ON_CMD` 16212, `Q_ON_CMD` 16226, `W1_ON_CMD` 16235.
- Rollback block S:16259–16289.

---

## (1) Cases the script does not produce, or words differently

1. **ME-CA2, `notify_retry`.** §T.11 expects "no ME-CA2 line". S:8362 always prints a `note  ME-CA2 the seven other jobs…` line, and it does not name `notify_retry`. Assert "no `ME-CA2 FAIL` line" instead.
2. **ME-CA1, no new cycle.** §T.11 quotes "*master did not start one*". S:8336 says `master did not start a new cycle within the poll's bound`.
   - The poll's bound is the budget plus 360 s, and a budget below 0 counts as 0 (S:8377–8379). So the poll lasts at least 360 s however small the planted budget is.
3. **INDETERMINATE before ROLLBACK.** §T.11 expects INDETERMINATE with the capitals text before ROLLBACK. `rb_pre107` prints no INDETERMINATE wording.
   - With control 0, the counts carry `control 0` (S:8960/8966), the capitals at S:8975 and the drain command at S:8979 print, and the §K rule at S:8981 says `a reading whose control is 0 does not count`.
   - INDETERMINATE appears only in stage A's SA note (S:8085) and after the put-back (S:8894, via `rb107` or V1).
4. **The K/Q reason.** §T.11 quotes "*once 5.18.106's tools are installed, none can write these keys*". S:9115 and S:9119 say `once 5.18.106's tools are installed none can write it` (no comma, "it"). The old reason text appears nowhere ✓.
5. **"nothing saved" / "nothing is saved".** That substring never occurs. The script says `A refusal changes nothing and saves nothing` (S:9107, S:9117).
   - S:9107 is appended to the §K-rule line whenever the switch-off or the drain is not "runs", not beside the switch-off itself.
   - So in "only the ladder the pin's" it does print, because the drain is refused.
6. **"The reading said not made".** No such text exists. Each file reads `not read` (`pb_word`).
7. **SA settings text.** For the workers' view the script prints `NOT v9 as the workers read it`, in capitals. The store-row part says `not v9`.
8. **`--rollback` with the switch NOT READ.** K and Q read `COULD NOT BE READ`, not "not read". The cause is `lk_read` refusing with no pin (`lk=nopin`, S:7447).
9. **`rb107`'s drain note** (S:8896) lists conversation ids only. Event ids are printed only by `rb_pre107` (`rb_bd_ids`, S:8888).
10. **`MG_CA2_SEEN=1` with no marker.** The note contains the word "halving" (`so halving is not the cause`). Grep the positive `the runner halves the log` (S:8479/8482) to tell the branches apart.
11. **Other md5.** R7 also FAILs (`the migration ledger CHANGED`), because `db_mig107` compares the checksum prefix (S:12049). §T.11 does not mention this.
12. **MG-CA2's NOT ESTABLISHED readings are `note`s.** They never fail the run; §T.11's "never a PASS" holds only for MG-CA2's own line.
13. **The plant between the extraction and `stamp_copy`.** The script never compares with `COPY_AT`. Its only dated note is keyed on `QF_COPY_UNIX`, so this case prints no MG-CA1 note.
14. **NS-B1.** The message ends `Nothing was copied.` and `stop` then adds `Nothing further was done.`

## (2) Fault-injection hooks and fixture writers

| Plant | What the script reads | Where |
|---|---|---|
| 092 rows (partial, other checksum, missing) | live book `$PDD_IN/plugin.sqlite3`: `_migrations` (filename, checksum, `applied_at`) and `_schema_checks` | MG-G1/MG-CA1 S:6708–6731; `mgledger` S:6702 |
| A partial 092 | — | MigrationRunner marks partial as applied (`MigrationRunner.php`:243 at 771a3c1) |
| `wa_no_ai` with no ledger row; rename; active sentinel row | `wa_no_ai` in the same book | MG-G1, NA-CA1 (S:6733) |
| 092 log lines and trim marker | `$PDD_IN/migration.log`: `OK: 092_wa_no_ai.sql (`, `PARTIAL: 092_wa_no_ai.sql`, `WARNING: 092_wa_no_ai.sql has been modified after being applied (checksum mismatch)`; first line `[Y-m-d H:i:s] --- earlier entries trimmed ---` | read as UTC (S:8452–8457) |
| State-file edits | remove or alter `MG_CA2_SEEN`, `MG_G1`, `QF_COPY_UNIX`, `COPY_AT` in `$OUT/state-5.18.107.env` | — |
| Switches K, Q, W.1, text keys, settings text | p = `$IN_CONTAINER/data/config.json`, d = `$PDD_IN/config.json`, k = `$PDD_IN/kyc_config.json`, s = the store row | NS-B1's plant during the DEPLOY hold (S:13852) |
| Burst marks; the drain's control; `wa.no_ai_alert` | `events` table: `events.error` marks, typed `ai.reply` events in the last day, queued `wa.no_ai_alert` events | `BurstMerge` and the drain via `sa_bd` S:8034 |
| ME-CA1 / ME-CA2 | `master_schedule` row id 0 (JSON `last_run`/`duration_ms`); `kyc_config` row id 0 `master_budget_seconds` | `MS_PHP` S:8211 |
| `[master]` lines | `$IN_CONTAINER/data/plugin.log`, stamped in the plugin's zone (`dn_tz`), not UTC | `ME_PHP` |
| Poll cases | the fixture must write a later entry while the poll runs | S:8381–8389 |
| Installed files on the host (`$DEST`) | `migrations/092_wa_no_ai.sql` (R1 S:14416, r1 S:8414); the four tools files (`pb_tool`); any changed file bf29cbd has (`BASE_DIFFERS` S:12399); `.deployed-commit` (`live_commit`); the `cron/followup_send.php` mtime (`COPY_AT`) | — |
| Copy timing, no first boot | the sandbox's `$REPO/scripts/deploy-hybrid.sh`: the plant before `stamp_copy` (S:13873 → S:13880); suppressing the first boot | the rehearsal's clock (`clk_to104`) |
| Rollback that does not verify | `deploy-hybrid` exits 2 without writing the record (S:13834–13843) | — |
| Rollback whose checkout fails | `git checkout bf29cbd` (S:13832) | — |
| Pin unavailable in `--rollback` | P3B staging, `git archive` / `docker cp` | S:12526–12540 |
| DEPLOY and ROLLBACK holds | `/dev/tty` | S:13852, S:13899 |
| V1 | `PLUGIN_BASE?page=customer_login` answering with a redirect | S:13930ff |
