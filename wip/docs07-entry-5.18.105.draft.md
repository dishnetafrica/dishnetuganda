## 8–16 Nov — 5.18.105: on Uganda, no phone number in any log line — 35 edits in 19 files take the number out of 37 log statements, the four of 5.18.104's known limit 13 and 33 more; where a line named nothing else a record id stands in its place, and the webhook log's three phone fields keep `••••` and the last two digits; the sales report's BY NUMBER table lines up again; no message, send, screen, stored record, setting or migration changes, and the deploy and its rollback keep every sales follow-up switch exactly as found; South Sudan's shared lines lose their numbers too, and nothing else changes there. `release/5.18.105` = `7835906`, one commit on 5.18.104 (`994d660`), re-cut once on 14 Nov for two test fixes that change no runtime file, pushed 15 Nov (10:15 UTC) on the owner's word, to deploy after pass B2; `scripts/deploy-5.18.105.sh` pinned to it; the release suite on it (317 files): pass B1 (14 Nov 11:48:16–18:12:07 UTC) 22,979 passed and 7 failed, only the seven checks in four files that fail in this container (5.18.104's known limit 11); pass B2 «B2: its date and times (UTC), passed and failed, and whether only the same seven checks failed»; passes A and A2 (each cut by a container restart) and A3 (a pass in pieces, after which the release was re-cut), all on the first cut `f764699`, do not count; the rehearsal's record, the whole chain unrestored, 2,710 ok and 3 failed, only the three inherited checks; NOT deployed

**Request.** The owner's rulings of 8 and 9 Nov, recorded in 5.18.104's known limit 13 (above). 5.18.104's rehearsal had
found, in its offers week (5o13), that the WhatsApp webhook writes an opted-out customer's number into PHP's log each
time it records a new STOP, and three older lines also log a number. On 8 Nov the owner ruled that 5.18.104 deploys as
built, and that 5.18.105 masks those four lines and is ready before step 12 (`offers-live`) is switched on. On 9 Nov
they ruled that step 6 (`chase`) waits for 5.18.105 too. Later on 9 Nov, once the design (root `docs/74`) had found about
25 more log lines that carry a customer's or a staff member's number on Uganda every day, they replaced that ruling:
5.18.105 masks every log line that can carry a phone number on Uganda, not only the four (option B); the chase comes on
once tracking has run 72 hours, without waiting; `offers-live` still waits for 5.18.105. The report's column fault, seen
in the live report on 9 Nov (the 9 Nov entry above), is in scope too (root `docs/74`, status). The design read three
inputs: the masking spec (48 sites), the deploy and tests spec, and an adversarial review of both, whose eleven gaps are
each settled in `docs/74` §B.1. Its eight questions for the owner (`docs/74` §Q) each carry a default that ships if
unanswered. No answer to any of them is recorded in `docs/74` or here, so by `docs/74` §Q each default ships. On 15 Nov the owner gave the word to push: *"Push now,
deploy after B2"* (`docs/74` §S.1). `release/5.18.105` was pushed at 10:15 UTC.

### Release notes (Uganda only; root `docs/74`, the release's `SAFETY.md`)

- **On Uganda, no log line the plugin writes carries a phone number any more.** 35 edits, LN-01…LN-35, in 19 files,
  cover 37 log statements: LN-24 masks every line in the Evolution sync's log writer (`evoLog`), and so covers its three
  statements. They are the four lines of 5.18.104's known limit 13 and 33 more (`docs/74` §A, §H.1). What is left on
  purpose, and what the search may have missed, is in known limits 4 and 7.
- **Which lines, by kind** (`docs/74` §H.1.1–§H.1.19):
  - the opt-out line the WhatsApp webhook writes on each new STOP (LN-01, `evo_webhook.php`);
  - the uCRM webhook's log, `webhook_log.json`: the welcome, every `invoice.add`, a zero-amount invoice, a new service
    not yet active, a quotation, a credit note, a client message, and the accountant's alert after a payment is deleted
    (LN-16…LN-23, `webhook.php`);
  - the quotation and receipt WhatsApps in `quote_wa_log.json` (LN-11…LN-15, `cron_quote_wa.php`);
  - the invoice job's new-invoice WhatsApp (LN-09) and renewal reminder (LN-10), and the overdue reminders (LN-26,
    LN-27);
  - the lead jobs (LN-28…LN-32), the watchdog (LN-08) and the two WhatsApp sync jobs (LN-24, LN-25);
  - the staff messages: the support leader's morning summary (LN-05), the cashbook reconcile (LN-06), the cash-carry
    reminder (LN-07) and the staff SSP report (LN-02);
  - a quotation WhatsApp that failed (LN-03) and a CRM job made by the WhatsApp auto-reply (LN-04);
  - three admin actions in the Activity Log: an installation confirmation (LN-33), an edit to a KYC application (LN-34)
    and a refund WhatsApp (LN-35; known limit 9).
- **What stands in the number's place** (`docs/74` §A; §Q Q2, Q5, Q7):
  - **usually nothing:** the line already names its record — a job, quote, invoice or payment number, or a name;
  - **a record id**, where the line named nothing else: conversation #, client #, lead # or the invoice number (LN-01,
    LN-09, LN-10, LN-25, LN-27, LN-30…LN-32);
  - **`••••` and the last two digits**, or `none` where there is no number, in the webhook log's three phone fields
    (LN-17…LN-19). A new helper writes it, `PluginLog::tail()`: the form the Sales numbers admin already shows;
  - **`<number>`**, where free text goes through the existing `PluginLog::mask()`: a failed quotation WhatsApp's error
    text (LN-03) and every line of `evo_sync.log` (LN-24), whose chat id (JID) becomes `<e-mail>`;
  - **`changed`**, for the `mobile` and `note` fields of a KYC edit (LN-34). The other nine fields keep their before and
    after values.
- **The report's BY NUMBER table pads by characters, not bytes** (`tools/sales_followup_report.php`, `docs/74` §H.1.23).
  A masked number such as `••••57` and a four-digit count print as two fields again. The report's JSON rows are
  unchanged.
- **Searching by a full number.** New entries can no longer be found by a full phone number in the Failed Queue's CRM
  Events search or in the Activity Log's filter. The client id, the invoice number and the name stay searchable
  (`docs/74` §Q Q3).
- **Nothing else changes.** No message or send, no screen's code, no stored record. No new setting is read. No migration
  is added; 091 stays the last. Test section D7 holds every runtime edit to log statements and comments, with three
  declared exceptions (`tail()` and the report's two hunks) and four declared lines (`docs/74` §A, §H.3.4 D).
- **Every sales follow-up switch stays as found.** The deploy never runs the switch tool. A rollback goes back to
  5.18.104 (`994d660`) with every step still running, and brings the numbers back in all 37 statements (known limit 2).
  Step 12 (`offers-live`) may follow once 5.18.105 serves and its `--after-only` has passed; steps 1–6 do not wait
  (`docs/74` §A, §H.4.7, §S.5).
- **Rules for whoever edits next** (the release's `SAFETY.md`):
  - on Uganda no log line carries a phone number: a new or changed line names an id, a count or a code, and a number
    goes through `PluginLog::tail()` or is not logged;
  - 5.18.104's rule 1, "a redeploy starts dark", governs a redeploy of 5.18.104 only;
  - the boundary is `tests/log_mask/declared.php`; it loads the one list and marker, `R105_DECLARED` and `R105_MARK`,
    from `tests/fixtures/r105_revert.php`. Edit the list and the marker together, never one without the other;
  - names, e-mail addresses and amounts are not ruled on.
- **South Sudan.** The release gates nothing by tenant, so the lines South Sudan shares with Uganda lose their numbers
  too; nothing else changes there. Lines that only South Sudan runs, or that nothing runs, are left (known limit 4),
  except the staff SSP report's line (LN-02), which the owner named. Test section B holds that job's sends and stored
  rows equal to `994d660`'s (`docs/74` §A, §H.3.4 B).
- **Held byte for byte** (`R105_HELD`, test section D3): the switch tool and its two ladder classes, `cron/master.php`,
  `cron/event_processor.php`, `lib/NotificationService.php`, `lib/AlertService.php`, `workers/StarlinkMailWorker.php`,
  the report wrapper's five library files, and `migrations/`.

**Files** (against `994d660`): 89 — 43 added, 46 changed; 4,175 insertions, 76 deletions; no migration.

- **Outside the tests, 23 files, all changed, none added** (160 insertions, 37 deletions):
  - the 19 log files, each named in the release's `SAFETY.md`: the two webhooks (`webhook.php`, `evo_webhook.php`),
    twelve job scripts (five under `cron/`, seven `cron_*.php`), two classes (`lib/QuotationService.php`,
    `lib/WaAutoReplyService.php`) and three form handlers under `includes/`. `webhook.php` carries eight of the 35 edits
    and the `PluginLog` loader (18 lines added, 8 removed); `cron_quote_wa.php` carries five (10 added, 5 removed);
  - `lib/PluginLog.php`, which gains `tail()` (7 lines added);
  - `tools/sales_followup_report.php`, the column fix (4 added, 1 removed);
  - `SAFETY.md`, a new 5.18.105 section at the top of the VERSION LOG: 74 lines added, none removed;
  - `manifest.json`, the version line only, 5.18.104 → 5.18.105.
- **Tests, 66 files** (4,015 insertions, 39 deletions):
  - 43 added: `tests/test_log_mask.php`; the 41 files of `tests/log_mask/` — six sections (A–F), six files of weakened
    copies, 21 drive groups (`g01`…`g21`), two fakes and six helpers; and `tests/fixtures/r105_revert.php`;
  - 23 changed: the older boundary tests re-pointed and their weakened-copy files, the version pin in eight files, and
    the re-cut's two fixes (`tests/test_quote_summary.php`, `tests/sales_followup_tool/TL_tool.php`) (below).

**Ancestry.** `7835906` (5.18.105, release; committed 14 Nov, 11:40:59 UTC) ← `994d660` (5.18.104, release; DEPLOYED
9 Nov, copy 03:50:25 UTC — its record above; steps 1 and 2 on since 9 Nov, step 3 since 13 Nov, steps 4 and 5 since
15 Nov — the 13 Nov entry above). The release is one commit. It was built in three stages, each reviewed by someone who
had not built it, then fixed and rechecked: C1 the code, C2 the tests, C3 the re-pointed boundaries. The first cut,
`f764699` (committed 9 Nov, 16:03:04 UTC; 88 files, 4,131 insertions, 71 deletions), was squashed from five working
commits (`d3e9b7d` … `add6118`, kept locally under `refs/keep/wip/5.18.105`); its tree is `add6118`'s. The development
branch keeps the release as a patch (`wip/release-5.18.105.patch`, byte for byte `git diff 994d660 7835906`), so a
container restart cannot lose it.

**The re-cut, 14 Nov** (`docs/74` §S.1, §S.2 items 6 and 7). The first cut's suite pass A3 failed two checks of the
release's own suite (Tests, below): `test_quote_summary` M, a hold of `webhook.php` that the design's list of re-points
missed, and 5.18.104's TL40 control, which the date had broken. Both were fixed in four files — `SAFETY.md`,
`tests/fixtures/r105_revert.php`, `tests/sales_followup_tool/TL_tool.php` and `tests/test_quote_summary.php` (48
insertions, 9 deletions) — as their own commit, `d623fc1`, on `f764699`. A fix laid on top would not do: the pin must be
one commit on `994d660` (`docs/74` §H.4.1). So the release was cut again as one commit on `994d660`, with `d623fc1`'s
tree. Every runtime file is `f764699`'s, byte for byte. Both are kept locally: the first cut as
`refs/keep/5.18.105-cut1`, the fix as `refs/keep/5.18.105-fix-d623fc1`. The deploy script was derived again on the new
pin (its section, below).

### Tests

- **The release's new test — 1 file, `tests/test_log_mask.php`, its sections in `tests/log_mask/`: 384 passed, 0 failed
  in pass B1, and 73 weakened copies, every one caught** (`docs/74` §S.1, §S.3). «B2: test_log_mask's count in pass B2,
  and whether it equals B1's 384/0». Every person and number in it is fictitious; nothing leaves 127.0.0.1.
  - **A — each masked line driven through its real path:** a served page, a sandboxed job run or a direct call with
    fakes, in 21 groups (G01–G21), one drive per LN; LN-24's covers its three statements. For each line: A1 it is
    written, found by its fixed words (the positive control); A2 no form of the test number is in it; A3 no run of nine
    digits or more is left. `PluginLog::tail()` and `PluginLog::mask()` are tested themselves.
  - **B — South Sudan:** `cron/staff_ssp_report.php` sends and stores what `994d660`'s does; only its log line moves.
  - **C — the chase's precondition:** the two strings step 6 reads in `evo_webhook.php` each occur once,
    `lib/SalesFollowupChecks.php` is `994d660`'s byte for byte, and `on chase` still reaches `W2: installed`.
  - **D — the boundary against `994d660`, read from Git (D1–D7):** only the declared files change, and none is removed;
    `migrations/` is the same, 091 last; the held files are byte for byte the same; every hunk carries the release's
    marker and none 5.18.104's; only the manifest's version line moves; `php -l` and the PHP 7.4 rules hold, and no new
    `$config` key is read; a runtime hunk touches only log statements and comments, with the declared exceptions.
  - **E — the logging rule:** E1, no log statement the release adds names a phone variable, except as the whole argument
    of `tail()` or `mask()`; E2, the same over every log statement in the plugin, against an allow-list keyed by file
    and the statement's fixed words, never by line number — a new log line that carries a phone fails, and so does an
    entry that matches nothing; E3 is A3 on every drive.
  - **F — the report's columns**, counted in characters: every BY NUMBER row's `conv` field ends under the header's,
    with a space before it; the JSON rows are unchanged.
  - **The 73 weakened copies.** Each plants one fault in a copy of the plugin, and the section that guards it must fail.
    A, 56: 35 put `994d660`'s line back, one per LN; 19 put a whole log file back to `994d660`'s, where every LN of the
    file must fail its A2; 2 stub `tail()` and `mask()`. Then B 2, C 2, D 8, E 4 and F 1. A copy whose anchor is not
    found exactly once, or whose planted edit stands inside a 5.18.105 marker's window, is a failure, never a skip.
  - **New fixtures:** `tests/fixtures/r105_revert.php`, the layer (below), and two fakes under `tests/log_mask/`, the
    Evolution chat endpoints (`fake_evo_chats.php`) and a WhatsML feed (`fake_whatsml_feed.php`). The shared
    `tests/fixtures/fake_evo_server.php` is not changed.
- **Rewritten on purpose, never weakened** (`docs/74` §H.3.2, §H.3.3, §S.2 items 2, 6 and 7; the release's
  `SAFETY.md`). These are the 23 changed test files. To the older boundary tests a 5.18.105 hunk is an unmarked change,
  and a 5.18.105 file is undeclared. The layer, `tests/fixtures/r105_revert.php`, takes 5.18.105 out first, against `994d660` read from Git, and
  each older test then judges what is left, unchanged. 5.18.105's own hunks are judged once, by section D.
  - **Through the layer** (`r105_less()`): `quote_followup` N and M01, `quote_agent_copy` J, `catalogue_direct` I and
    `quote_from_ucrm` I from their I0, TL14's main scan and its set of files from Git, `test_quote_company` I4 for the
    files outside `QC_I4_102`, and, since the re-cut, `test_quote_summary` M (`webhook.php` through `r105_file()`).
  - **By exact text** (`r105_revert()`), for `includes/post/post_cashbook.php`, which two tests hold as text:
    `test_bank_boundaries` (A, and the blocks F reads) and `test_cashbook_physical_list` M. A checks the result against
    `994d660`'s bytes from Git.
  - **Ten weakened copies plant an edit 5.18.105 did not make**, and the older test must still fail on it: QN11, QN8
    and QN8b (N), MI7 (`catalogue_direct` I), MI5 and MI7 (`quote_from_ucrm` I), W-r105a (`test_quote_company` I4),
    BM-r105 (`test_bank_boundaries` A), W-r105 (`test_cashbook_physical_list` M), and `test_quote_summary` M's own
    control. Each first asserts, from the planted file, that its edit stands outside every 5.18.105 marker's window, or
    it counts as a failure. QN8b puts 5.18.104's own QN8 back nine lines above the I0 re-point's marker, so that N04 is
    proved to bite again; for it only a marker in or above the planted hunk counts, the layer's own rule. `SAFETY.md`'s
    list does not name QN8b; the test's own comment does.
  - **N00 and N09.** N00: Git supplies `994d660`, and the tree judged was built — its opt-out line reads `994d660`'s
    form, and `tests/test_log_mask.php` is absent. N09, the control on the controls: N01, N03, N05 and N06, run on the
    judged tree with 5.18.105 left in, each fail on 5.18.105's files and hunks. So the layer is what makes N pass.
  - **The version pin, 5.18.104 → 5.18.105,** in eight files: the five `test_distributor_*`, `test_bank_boundaries`
    (and its weakened copy), `test_cashbook_physical_list` and `test_quote_company` (I5).
  - **The re-cut's two fixes** (found by pass A3 on the first cut):
    - **`test_quote_summary` M** holds `webhook.php` byte for byte against `f2055baf`, 5.18.101's release, once
      5.18.104's two P7 hunks are taken out. The design's list of re-points (`docs/74` §H.1.24) did not name it, so it
      read the raw file, and 5.18.105's nine `webhook.php` hunks made it fail (57 passed, 1 failed); its one-character
      control still passed, proving nothing. It now reads `webhook.php` through the layer. A new control asserts that
      the layered file holds no `PluginLog` line and that the raw file fails; the one-character control is planted
      outside every marker's window. Three weakened copies each fail it: the raw file read again, the plant moved inside
      LN-20's hunk, and the layer replaced by `994d660`'s whole file. A read of every test against the 23 files 5.18.105
      changes outside the tests (14 Nov, read-only) found no other hold left unpointed.
    - **5.18.104's TL40 control** built its control book with every step switched on ten days before `time()`, and a
      fixture chase fixed on 2 Nov at 10:00 Kampala. From 12 Nov 07:00 UTC the chase fell before
      `quote_chase_not_before`, Q3-ON answered exactly as Q5-ON, and the control failed on every run (396 passed,
      1 failed). Meanwhile TL40's six weakened copies counted as caught whatever they did. It is now judged at one fixed
      moment, 7 Nov 2026 12:00 Kampala, the day of 5.18.104's two passes; nothing else in the file changes. Weakened
      copies: the chase removed, or the moment one second past the edge, fails the control; at the edge exactly it
      passes; Q3-ON dispatched as Q5-ON fails TL40's dispatch check, a fault the broken control had hidden.
    - **Run alone on the fixed tree** (14 Nov, 10:41–11:47 UTC, one at a time, before the re-cut): `test_quote_summary`
      59/0; TL40 11/0; `test_log_mask` 384/0; `test_sales_followup_tool` 397/0; `quote_followup` N and M 291/0;
      `quote_agent_copy` J 116/0; `catalogue_direct` I 107/0; `quote_from_ucrm` I with its MI copies 46/0.
  - **In pass B1:** `test_quote_followup` 768/0; `test_quote_summary` 59/0 (58 in 5.18.104's passes);
    `test_sales_followup_tool` 397/0 (as in 5.18.104's). Against 5.18.104's pass B, nine re-pointed files grew by 1 to 8
    checks each. «B2: each re-pointed file's count in passes B1 and B2, beside its count in 5.18.104's pass B».
- **The release: the full suite twice on `7835906`**, exactly as pushed, in a frozen, clean worktree, one pass after
  the other, with nothing beside them (`docs/74` §S.3, §H.7 step 5):
  - **Pass B1**, 14 Nov 11:48:16 – 18:12:07 UTC, in one piece, no restart: **317 files, 22,979 passed, 7 failed,
    EXIT=1.** The seven are this container's, in four files (5.18.104's known limit 11): `test_install_authorisation`
    379/2, `test_mail_quote` 91/1, `test_migration_integrity` 27/1 and `test_secure_file` 22/3, exactly as in
    5.18.104's passes. Nothing else failed: L05 read 768/0, `test_quote_summary` 59/0, `test_sales_followup_tool` 397/0.
  - **Pass B2**, planned from Monday 16 Nov, 00:00 UTC: «B2: begun and ended (UTC), in one piece or not; files, passed,
    failed, EXIT; whether its FAIL lines are B1's seven, word for word, and whether every file's count equals B1's».
  - **Against `994d660`'s pass B** (316 files, 22,578 passed, 7 failed):
    - 1 file more, `test_log_mask`, with 384 passed;
    - nine re-pointed files grow by 1 to 8 checks each, 17 between them (22,979 − 22,578 − 384);
    - every other file's count is equal.

    So 22,578 + 384 + 17 = 22,979, and the seven failures are the same. «B2: the same comparison for pass B2».
  - **Days and hours.** Some of 5.18.104's tests depend on the day or hour they run (known limit 16). So both passes run
    on Monday-to-Saturday Kampala days, outside Sunday daytime UTC and before 20 Nov 21:00 UTC, and files 35–36
    (`test_cashbook_physical_list`) never run inside 21:00–24:00 UTC. B1 ran on a Kampala Saturday, CK12 and HK11 among
    its files, before 21:00 UTC. «B2: the day and hours it ran, against these rules».
  - **The reading rule for B2, written before it runs** (`docs/74` §S.3). `test_master_104.php`'s D03 `--all` fails by
    chance (known limit 16), and a pass runs it twice: in `test_master_104.php` itself and through `test_quote_followup`
    L05. If B2 fails `D03 --all`, there or through L05, and nothing else beyond the seven container-only checks, that
    failure is this limit. «B2: whether B2 failed D03 `--all`, and where».
  - **The passes on the first cut, `f764699`, do not count.** They are recorded as a diagnostic:
    - **Pass A** was cut by a container restart in file 189: to that point 11,788 passed and 4 failed, all in the three
      container-only files it had reached (the 13 Nov entry above).
    - **Pass A2** began 13 Nov at 05:29:32 UTC and was cut by a restart. Its log ends at 10:19:34 UTC inside file 230
      of 317, with 15,127 passed and 4 failed, in three of the four container-only files.
    - **Pass A3** began 14 Nov at 01:13:12 UTC. Two restarts cut it: its log stops at 05:20:21 UTC and again at 06:37:03
      UTC, each time inside file 230 (`test_quote_from_ucrm.php`). Each time it was resumed from the start of that file (05:32:10 and 06:53:40 UTC),
      in the same frozen worktree and order, with a `RESUME` line in its log: one pass in pieces, not an uninterrupted
      one. It ended at 10:36:43 UTC: **317 files, 22,975 passed, 10 failed.** Seven are the container-only checks. One
      is `test_quote_followup` L05 (767/1), D03 `--all` failing by chance (known limit 16). Two are the re-cut's fixes,
      `test_quote_summary` M (57/1) and TL40's control (`test_sales_followup_tool` 396/1). They made the first cut
      wrong, so the release was re-cut, and A3 counts for neither pass. Beside 5.18.104's pass B, file by file, every
      other difference is a count that 5.18.105's own tests and re-points add.
- **T1 and T4, the script's signatures, are 5.18.104's unchanged**: `548 passed, 0 failed rc=0` and `20 passed,
  0 failed, 0 skipped rc=0` (`scripts/deploy-5.18.105.sh`, `T1_SIGNATURE` and `T4_SIGNATURE`). The release adds no test
  to the set the server runs (`docs/74` §H.3.2, inferred there). Pass B1 compared every file's count with 5.18.104's
  pass B and found only `test_log_mask` and the nine re-pointed files different, so its reading of
  `test_cash_in_hand_p3b` equals 5.18.104's, 548/0 (inferred from that comparison; the figure itself is not recorded). «B2: `test_cash_in_hand_p3b`'s count in pass B2».

### Known limits (recorded, accepted — the release's `SAFETY.md`, `docs/74`)

1. **The masking holds only from the moment 5.18.105 serves** (`docs/74` §H.2.7, §Q Q6). Lines already written stay:
   the release changes code and rewrites no data. The capped logs drop old entries as new ones arrive:
   `webhook_log.json` keeps 300 entries, `quote_wa_log.json` 200 and `activity_log.json` 500; `evo_sync.log` is cut back
   to its last 400 lines once it passes 500. `invoice_notify_cron.log`, `data/plugin.log` and PHP's own log keep them
   until rotated. No clean-up tool is designed.
2. **A rollback brings the numbers back in all 37 statements** (§H.2.8, §Q Q8). `994d660`'s code writes them again. The
   script says so in capitals (LM-RB1), counting 35 log lines, one of which covers three statements. The rollback is
   never blocked: it is an emergency act. If step 12 is on, the script says before `ROLLBACK` is typed that offers would
   run on unmasked code, and names the operator's own command to turn step 12 off, on its own line.
3. **Only phone numbers are masked** (§Q Q1, Q7; §H.2.3, §H.2.9). Names, amounts and record ids stay. E-mail addresses
   are not ruled on; they change only where a line passes through `mask()`. A line that keeps a name logs it as it is,
   so a uCRM name or a KYC field that holds a number is logged. The two places where the code itself puts a number in a
   name are both masked: the watchdog's, and an AI lead with no name. A KYC edit (LN-34) still logs before and after for
   the names, the address and the company name.
4. **Lines left on purpose, K-01…K-18** (§H.1.20, §H.2.1, §H.2.2):
   - **lines only South Sudan runs, or that nothing runs on Uganda** (K-01, K-03…K-12). They change nothing on the
     server this release is for. K-04 is held byte for byte by 5.18.104, K-01 would break a South Sudan test that
     compares with 5.18.49, and K-11 and K-12 are not run by the plugin. Each stays on test E2's list, so any change to
     it is seen;
   - **text that holds a number only if someone else put one there** (K-13…K-17, and the error messages beside masked
     lines): error messages, uCRM's and URA's answers, a whole invoice record, SQL parameters, traces. No code that
     raises an error on these paths is known to put a number in its message, but that is not proven. Such text is masked
     only where its line was edited anyway (LN-03, LN-24);
   - **the dry-run capture** (K-02), off by default, whose purpose is to show the message; its live value was not read;
   - **the operator's own tools** (K-18), which print to the operator's terminal, not to a log.
5. **Stores that code reads back are not logs, and keep the number** (§H.2.5): the Message Log
   (`notification_audit_log`), `quotes_log.json`, a KYC application's own audit record, `evo_sync_state.json` and the
   dry-run capture file.
6. **What `PluginLog::mask()` catches** (§H.2.4; LN-03, LN-24): runs of seven or more characters of digits, spaces,
   brackets and hyphens that start and end with a digit. A number written with dots or slashes, or in words, passes. It
   also replaces dates and long references, and it puts the text on one line, no longer than 400 characters
   (`lib/PluginLog.php`); both are accepted in `evo_sync.log` and in an error text.
7. **What the search may still have missed** (§H.2.6): a whole array passed to a log (K-17), and a value that reaches a
   log under a name that does not say phone, beyond the cases found. From now on test E2 fails on any new log statement
   that names a phone variable.
8. **Whether some of the 35 lines ever run on Uganda is not established** (§H.1). This was read from the code, not from
   the server. LN-02 runs only where SSP is bookable; LN-04 is unlikely; LN-24, LN-25, LN-26 and LN-27 are unknown, the
   live settings not read. All are masked all the same.
9. **LN-35 is reached only by a hand-made request** (§S.2 item 1). The design said an accountant reaches it by recording
   a refund. No form or script in the repository posts the field its handler reads: the panel's cashbook form posts
   another field, and the retailer app goes through the API. The line is masked all the same, and its drive (G21) posts
   the form by hand.
10. **Five older faults the drives met — recorded, not changed** (§S.2 item 3). Each reads the same on `994d660`'s own
    files, measured in the drive's harness, not on the server. Each is the owner's to decide, for a later release. The
    first two are in jobs that run on Uganda (§S.5).
    - **Renewal reminders are never sent.** `renewal_remind_log` is not in `lib/SqliteStore.php`'s flat tables, so its
      stamp is never read back and every run is a first-run dry run (G09).
    - **The queued quotation PDFs are dropped.** `quote_wa_state` is not in that list either: `pdf_pending` is never
      found, and is saved back empty (G10).
    - **The `cashbook_add_entry` handler answers 500 after it has done its work:** `TransactionIntegrityGuard` is not
      loaded on its path (G21). No screen posts to it (limit 9).
    - **`cron/cashbook_reconcile.php` stops before its send on a book fresh from the migrations** (G06). It needs three
      tables the store makes on first use and a column the cashbook adds when first opened; a live book has them.
    - **`cron_wa_sync.php` run alone stops at every row:** it uses `WaInbound` before the file that loads it (G16).
      Under `cron/master.php` an earlier job of the same process has loaded it.
11. **Observed, not in scope** (§H.2.10). `cron_wa_sync.php` polls `wa_feed_url`, which defaults to the South Sudan
    WASender feed, unless `wa_sync_enabled` is false. If it is on and the feed returns rows, Uganda would take in that
    feed's messages. Inferred from the defaults; the live value was not checked.
12. **The `071_dpo_payments.sql` checksum mismatch is untouched** (§H.2.11; the 9 Nov deploy's record, above).
13. **The switch tool does not check for 5.18.105.** It and its two ladder classes are 5.18.104's byte for byte
    (`R105_HELD`, test D3), so nothing in the plugin refuses step 12 before this release serves, or after a rollback.
    The order is the operator's to keep: step 12 only once 5.18.105 is deployed and its `--after-only` has passed
    (`docs/74` §H.4.7).
14. **The design's eight questions ship with their defaults** (`docs/74` §Q): no answer to any of them is recorded in
    `docs/74` or here.
    Limits 1–4, and in the release notes what stands in the number's place and the search change, are those defaults.
15. **5.18.104's known limits stand, except that its limit 1 no longer describes the server:** steps 1 and 2 have been
    on since 9 Nov, step 3 since 13 Nov, and steps 4 and 5 since 15 Nov (the 13 Nov entry above), and this release keeps
    them as found. This release changes log statements, adds `PluginLog::tail()` and the loaders that `webhook.php`,
    `lib/QuotationService.php` and `cron_evo_sync.php` need, the report's padding, `SAFETY.md`, the version line and
    tests. Three of 5.18.104's limits bear on it:
    - **its limit 11** (four test files fail in this container and nowhere else on record): pass B1 failed the same
      seven checks in the same four files. «B2: whether pass B2 failed the same seven, word for word, and nothing else»;
    - **its limit 12** (one wrong sentence in 5.18.103's section of `SAFETY.md`, Operator action, step 3) is still not
      corrected: this release only adds its own section to that file;
    - **its limit 13** (phone numbers in four older log lines) is what this release answers, once it serves.
16. **Some of 5.18.104's tests depend on the day or hour they run, and D03 `--all` fails by chance** (`docs/74` §S.3).
    A read of every test for the real clock against fixed dates (14 Nov, read-only) found these, besides TL40, which is
    fixed in the re-cut. None is 5.18.105's, none is changed here, and each is recorded for a later release:
    - CK12 and HK11 claim a send on today's Kampala date and fail on a Kampala Sunday, when the send window is closed.
      CK12 also fails on every run from 20 Nov 21:00 UTC, when its quotation, valid until 20 Nov, has expired;
    - on a Sunday from about 06:00 to 19:00 UTC, `test_pilot_safety` fails, and `test_notify_evo_retry`,
      `test_sales_pilot` and `test_multi_number_routing` skip their follow-up cases;
    - wa_offers K07 has passed since 9 Nov without exercising its recent-offer exemption;
    - voice_ack K02 will fail from 31 Dec 2026;
    - `test_tick_auto_pull_log` can fail at hh:59:5x UTC;
    - **`test_master_104.php` D03 `--all`** compares `tools/cron_status.php`'s output with `221c9dc`'s and masks only the
      header's `(Ns ago,` figure, not each job's last-run column. Measured on 15 Nov, 05:52–06:44 UTC, on `7835906`:
      38 of 530 runs failed (7.2%), every one on that line. In a scratch worktree, removed afterwards, 11 of 300 runs
      differed only in job 1's last-run column (`60s` against `61s`); with that column masked too, 0 of 300 failed. So
      it fails in about 4 to 7% of runs. A pass runs D03 twice, so it meets this roughly one time in ten (7 to 14%).
      No deploy script runs it on the server (T1 and T4 only). Its fix, for a later release: mask that column too. The
      reading rule for pass B2 is in Tests, above.
17. **Git's short form** (`docs/74` §S.4, development trial 6). At 16,384 packed objects or more a checkout writes
    8-character short commit ids, and the script's check (5.18.94's) refuses at A0 before anything is changed. The
    rehearsal's clone crossed that count on 14 Nov (16,421 objects). If `--check` or the deploy stops with that
    sentence, the runbook's step 1 gives the command to run first.

### `scripts/deploy-5.18.105.sh` — 14,745 lines, sha256 `c70820cd69af899b…`

**What is new.** The script deploys 5.18.105 over a sales follow-up that is switched on, and it keeps every switch
exactly as it finds it. It never runs the switch tool's `on`, `off`, `skip`, `set` or `erase`, nor
`scripts/sales-followup.sh`, in any mode: not in `--check`, the deploy, `--after-only` or the rollback. The steps that
are on keep running through the deploy and through a rollback. Every ladder key is read before and after, in each of
the four places it can live; a key that moved is a NO-GO before the copy and a FAIL after it. The release adds no
migration and no setting. 5.18.104 must be the live plugin: the script refuses any other live commit or version.

**How it is made.** From 5.18.104's script exactly as committed (14,499 lines, sha256 `1aafc15bd1cdb1d7…`) by asserted
edits (`wip/derive-5.18.105/derive105.py`, root `docs/74` §H.4.1). Each edit asserts its anchor, its count, the number
of replacements and its result; any failure exits non-zero and writes nothing. The release commit is a parameter. The
derivation refuses a pin whose manifest does not say 5.18.105, and one not cut on `994d660`; a build commit further
along a straight line from `994d660` is derived only with a warning, and the script's own A0 then refuses it. Every pin,
file list, count and sha256 is read off Git (`994d660` → the pin), never typed. Eleven parts, in the order they run,
300 edits:

- **A** identity: the pin (`7835906`, short and full); the baseline (`994d660`, 5.18.104, live); the run's folder
  `/root/dnb-5.18.105`; the earlier releases' state files — `PREV_STATE` is now `/root/dnb-5.18.104/state-5.18.104.env`,
  and `PREV8` (5.18.97) is added so that its quarantine record is not dropped; and the three sha256 lists: the 386 files
  5.18.105 must not touch, each at `994d660`'s sha256 and asserted the same at the pin; the 46 files it changes, at
  `994d660`'s sha256 (what must be live before, and what a rollback must put back); and the 89 files of the delta at the
  pin (46 changed, 43 added, the added ones all tests);
- **B** the carried checks the delta moves: A0 (no migration; 089–091 held at the pin by sha256 and md5); master's two
  pins, both `b59e7ce56ec7ca5d…`, 5.18.104's `cron/master.php`, which 5.18.105 keeps; the event processor's Uganda
  signature (5.18.104's); the copy's time mark, now `evo_webhook.php`, a file 5.18.105 changes; R7's ledger "the same";
- **LK** the ladder rule (below), and 5.18.104's checks that assumed every switch off, made to read a live ladder;
- **R** the rollback: back to `994d660`, every switch kept (its section, below);
- **V**, **O**, **P** 5.18.104's voice, offers and agent-copy checks, made live-aware;
- **L** the masked lines' checks (below);
- **H** what the operator reads: the header, the options, the stages, the summary and its rollback block;
- **C** the carried texts that named the wrong release, each fact read off Git;
- **Z** the retired readings removed, and the rules asserted over the whole result.

Then a check over the whole text that the ladder is kept, beside four weakened copies it must refuse, each for its own
reason: the clear call put back (K1); LK-CA1 removed (K2); QF-RB1 called again in V1's automatic rollback (K3);
`quote_followup_state`'s "never fell" count taken without its `list_last:%` filter (K4). 5.18.104's folder check,
carried, with its three weakened copies. `bash -n` on the result and both wrappers. Every round derived it twice, byte
for byte the same. The script itself never ran in the derivation.

**Its history**, one commit per round: 277 edits in the first build (`2db0431`, 9 Nov, pinned to the first cut
`f764699`); 284 with trial 1's two fixes (`cbbc985`); 294 with sweep 1's seven (`863c870`); 296 with two comments
corrected after trial 3 (`0214b50`); 300 with the four pipelines (`be8930c`). **Derived again for the re-cut** on 14 Nov
(`2a41c9d`): the same eleven parts and 300 edits, run on `7835906`. Only the pin, the counts (89 files, 46 changed,
386 kept) and the sha256 values of the four files the re-cut changed moved. On `f764699` it was 14,744 lines, sha256
`c028cde545e042ba…`: the script trial 5 ran. No commit since `2a41c9d` has touched it or either wrapper, on the
development branch or on `origin/claude/study-this-jhe2eg`.

**The two wrappers beside it.**
- `scripts/sales-followup.sh` (89 lines, sha256 `bbde2d041884994b…`) is 5.18.104's, unchanged. Its comment that the
  deploy's `--rollback` runs QF-RB1 "before it puts 5.18.103 back" describes `deploy-5.18.104.sh`, and stays true of
  it; it does not describe 5.18.105's rollback (root `docs/74` §H.4.7).
- `scripts/sales-followup-report.sh` (158 lines, sha256 `7b78faf6751e51b0…`) is written again by the derivation,
  because the report's column fix is in the release. It takes the report from Git at `7835906` (its sha256
  `691ef613ba20c584…`, checked) and asserts that the five installed files it reads equal that commit's bytes. Those five
  are 5.18.104's too, so it runs before the deploy and after a rollback. It fetches `release/5.18.105` itself when the
  checkout lacks `7835906`; if it still lacks it, it stops, having changed nothing.

**New — the ladder kept as found (LK; root `docs/74` §H.4.3).** The keys are those of `SalesFollowupLadder::allKeys()`
and `settingKeys()`, read through the pin's `lib/SalesFollowupLadder.php` (`994d660`'s bytes, held by sha256). Each is
read, read-only, in four places: `<data>/kyc_config.json`, its row in the book (opened read-only),
`<plugin>/data/config.json` and `<data>/config.json`. The log carries counts, step names and key names only. Each value
goes to the 0600 cash sheet and the state file as the first 16 hex of an HMAC-SHA256, never as a value. The HMAC key is
24 random bytes, kept in `/root/dnb-5.18.105/lk-key-5.18.105` (mode 0600), never printed, never in the state file or
the sheet; `--check` uses a key of its own and writes none.

- **LK-G1** (stage A, and `--check`): NO-GO if a place cannot be read, or if an `allKeys()` key is held by a
  `config.json`. The tool's `off` would then read back `FAIL key_outside_overrides`, and a step must always be able to
  go off. A `settingKeys()` key held there is a note. A step that is on is never a refusal: a note names the steps on
  and skipped, the ledger, the counts per place and every key where file and store disagree. A NOTE IN CAPITALS if
  `offers_live_enabled` is set: step 12 is already on under 5.18.104, against the owner's ruling; the deploy is the
  remedy, and changes the key neither way. `quote_chase_enabled` set is a plain note.
- **LK-B1** (after `DEPLOY` is typed, before the copy): the same reading, equal to LK-G1's key by key and place by
  place. Otherwise NO-GO — *the switch tool was run meanwhile* — and nothing is copied.
- **LK-CA1** (after the copy): every key in every place equal to LK-B1's. Otherwise FAIL, the key and place named in
  the log, both hashes on the sheet.
- **LK-CA2** (after the copy, against the baseline taken at the copy's start): no row fell in the sales follow-up's
  tables (the tracking, ledger, list, voice, offer and agent-copy tables; `quote_followup_state` counted only over its
  rows not `list_last:%`, because the daily list deletes those itself); every `_schema_checks` row unchanged; the four
  jobs keep their schedule entries. Claims after the copy, and `quote_followup_state`'s whole count, are notes.
- **LK-ON** (the deploy run): each step that is on, judged by the installed tool's `--check` on a fresh copy, against
  the same `--check` through the pin's tool at stage A. PASS before and FAIL after is a FAIL; anything else, a note.
- **LK-AO** (`--after-only`): the reading under the kept key against the deploy's, a note naming every key changed
  since — the operator's own steps. Without the key file it says the keys *cannot be compared*, never "the same".
- **LK-RB0** and **LK-RB1**: the rollback's (its section, below).

**New — the masked lines (LM; root `docs/74` §H.4.5).** The script carries no source text. It pins the build's 39
hunks from Git (`LM_HUNKS`): the 35 masked log lines, LN-01…LN-35 (37 log statements), and four more —
`PluginLog::tail()`, the report's column fix in two blocks, and `webhook.php`'s loader. Each entry holds the line count
and sha256 of the old text and of the new, taken as blocks of lines, the 5.18.105 markers left out. A block is found as
a run of that many lines whose joined text has that sha256. Blocks, never single lines: LN-03's added loader line
already stands elsewhere in `lib/QuotationService.php`.

- **LM-G1** (stage A, a refusal): in the pin's files from Git each new block stands once and each old block nowhere; on
  `994d660`, the control, the other way round.
- **LM-G2** (stage A, a refusal): the two strings that step 6's `on chase` precondition needs, read at run time from
  the pin's `lib/SalesFollowupChecks.php`, each once in the pin's `evo_webhook.php`.
- **LM-CA1** (after the copy): LM-G1's reading on the installed files.
- **LM-CA2** (after the copy; counts only, never a line): an old-form opt-out line in PHP's log stamped 60 s or more
  after the copy is a FAIL; one stamped earlier, a request in flight, is a note. An unreadable PHP log reads NOT
  ESTABLISHED, a note, as it did at 5.18.104's deploy. In the other files 5.18.105's lines write to, the records stamped
  60 s or more after the copy that hold a run of 9 or more digits are counted per file, a note: other values there can be
  long numbers that are not a person's.
- **LM-ON** (`--after-only`): LM-CA1 and LM-CA2 again; a late old-form opt-out line is still a FAIL.
- **LM-RB1** (after a rollback): each old block back once and each new block gone, and a note in capitals that 35 log
  lines write phone numbers again.

**No migration.** A0 refuses a pin that carries one. QF-G1 accepts one form of the book only: 089, 090 and 091
applied as 5.18.104 left them. A book that ends at 088 is told that 5.18.104's code has not opened it yet: open the
plugin in uCRM once, then run again (root `docs/74` §S.2 item 5). A part-applied book is refused. QF-G2's trial prints
`071_dpo_payments.sql:checksum_mismatch`, as 5.18.104's run did: the book's history, a note, not judged.

**5.18.104's checks, carried and made live-aware** (root `docs/74` §H.4.4):
- QF-G4 is retired; LK-G1 takes its place. QF-G2, VN-G7 and OF-G2 accept only "none pending".
- QF-CA5, VN-CA1 and OF-CA1 must equal stage A's readings through the pin's code.
- QF-CA3 must list each of the four jobs `OFF (switch)` exactly when its flag is unset on the copy. VN-CA2's media
  switch and AC-CA1's `on()` must agree with their own keys.
- QF-CA2's and QF-CA3's dark-build judgements are LK-CA2's; VN-CA4's keys are LK-CA1's and its rows a note; OF-CA3's
  late counts a note. AC-CA2 compares with `994d660`. R7 reads the ledger the same, 091 last.
- V3g has no exception left: any change of an `ai_`, `evo_`, `openai_` or `claude_` key during the run is a FAIL.
- Retired with the switch-off rollback: QF-RB1–QF-RB3, VN-RB1–VN-RB4, VN-RB6 (the instruction to rotate the OpenAI
  key: the voice steps keep running), OF-RB1 and AC-RB1. VN-RB5, OF-RB2 and AC-RB2 are notes (*they continue under
  `994d660` as their switches say*).
- Every other check of 5.18.104's is carried, re-pointed only where 5.18.105 moves what it reads: VN-G1 reads LK-G1;
  VN-G4 compares the pin's whole fromMe branch with `994d660`'s; QF-G3's counts are re-read from the pin; QF-CA1 and
  OF-G1 read 089–091 and the offers *as 5.18.104 left them*; QC-RB1 points at the files 5.18.105 adds.

**Modes.** As 5.18.104's — a deploy; `--check`; `--after-only`; `--rollback`; `--redeploy`, `--move-list-only`,
`--check-bank`; the flags each the operator's explicit choice — less one:

- **The deploy:** stage A; `DEPLOY` typed; then, before the checkout, LK-B1, a 6 s pause (`busy_timeout` plus 1 s) and
  the baselines (QF's, VN's, OF's and LK-CA2's) at one moment; then the copy, V, R, Z and the summary. A deploy that
  finds 5.18.105 already serving runs the `--after-only` checks instead.
- **`--check`:** stage A alone, stopped before the backup: nothing is backed up, copied or deployed, and no key file is
  written. A GO prints the deploy command, with a flag only where a check needed one.
- **`--after-only`:** read-only. LM-ON, LK-AO and the after-the-copy checks again, and the ON checks of each step that
  is on (QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2, AC-ON1), through the installed tool's `--check` on a
  copy; a step that is off is not judged. At least 20 minutes after the copy, again the next day after 15:00 UTC, and
  after each step of the ladder.
- **`--rollback`:** `ROLLBACK` typed, after LK-RB0 and the step-12 notice; `994d660` put back by the documented deploy
  alone; then RB105 (its section, below).
- **`--redeploy`:** after its own rollback. BR1 counts the 43 files 5.18.105 left on disk; QF-G1 reads 5.18.104's
  tables; the ladder rule holds as on the first deploy.
- **`--clear-switches` is refused** at parse, before anything runs (exit 64): *5.18.105 never clears a ladder key; turn
  a step off yourself with `scripts/sales-followup.sh off <step>`, its own command*.
- **V1's automatic rollback**, for a deploy whose public sign-in page redirects, runs no switch tool and keeps every
  key. RB105 reads it once it verifies, LK-RB1 then compared with the deploy's LK-B1.

**Its rules for the operator** (the header; root `docs/74` §H.4.7):
- Not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00 Kampala), and not while the morning list is being sent
  (step 4; the script marks this rule inferred).
- Do not run `scripts/sales-followup.sh` while the deploy, its `--after-only` or its rollback runs: LK-B1 and LK-CA1
  would read the change.
- Do not use the Salesperson numbers card, ECO Bank (keep no statement), Send Quotation, the KYC form,
  `tools/set_config.php` or Settings while it runs, nor during its `--after-only` or its rollback. From the deploy's
  start to its last line no admin touches the uCRM catalogue screen, a uCRM link, a plan or a kit, or uCRM's Products,
  Service Plans or client attributes.
- A14 needs the quarantine's `--check` begun after the last copy or rollback. Today that is the one of 9 Nov, begun
  04:39:36 UTC, after 5.18.104's copy.
- The deploy and its rollback are never in one copyable block.

**What it never does**, beyond 5.18.104's list (the header's is the full one): write a key of the ladder; run the
switch tool in any form but `show`, `--check` and `doctor`, on copies; close a quote follow-up, pause an offer or settle a
claim; apply or undo a migration; send anything but AC-CA2's drive to its own loopback relay; call Evolution, Splynx or a
model; print the OpenAI key, a phone, a person's name or a staff id. Its writes are the documented deploy (or rollback),
the backup under `/root/dnb-5.18.105/`, the state file, the LK key file, the cash sheets, its copies in the container's
`/tmp` (removed), and the copy's time on the files the release changes or adds.

**Reviewed before any trial.** The derivation was reviewed, and its findings D1–D6 were fixed in it
(`wip/derive-5.18.105/partR.py`, `partZ.py`, `partC.py`, `partL.py`): D1, LK-RB1 is not judged by a `--rollback` that
rolled nothing back; D2 and D3, the step-12 notice and LM-RB1 count the 35 masked lines (`LM_LN_N`), never all 39
entries; D4, A0's, Q-G1's and Q-CA1's printed kept-file lists no longer name `includes/post/post_cashbook.php` and
`cron_quote_wa.php`, which 5.18.105 changes; D5, stage-A texts that called 5.18.103 live or 5.18.104 the release are
re-pointed; D6, LM-G2's two strings are read at run time from the pin's `lib/SalesFollowupChecks.php`, never carried.

**Found by the trials, a sweep and the record run's first start, fixed in the derivation** (root `docs/74` §S.4):
- **T1-a** (trial 1): AC-CA2 refused in every deploy and `--after-only` (`refused=tree_p7`). Its harness refused a
  baseline that holds `lib/QuoteAgentCopy.php`. That was right while the baseline was 5.18.103; 5.18.104 carries P7 as
  the pin does, so both trees must hold it.
- **T1-b** (trial 1): every rollback failed its reference check (`batch1:event-processor`). It still expected
  5.18.103's worker-owned list, where a rollback of 5.18.105 leaves 5.18.104's, which is the pin's.
- **Sweep 1** (13 Nov, beside trial 2): both defects were carried lines that still took the baseline for 5.18.103. Two
  readers went through the script for more of that kind, in the branches a trial does not take, and a third checked
  each finding. Seven were confirmed and fixed:
  - one false pass: after a rollback, a book with none of the four offer tables was a note ("091 never applied"); it is
    now a FAIL;
  - one wrong instruction: QF-G1's words for a book that ends at 088 (above);
  - five false words, in notes, an ok line and two failure hints, which spoke of 089–091 as this deploy's own.

  The header's dates for the ladder's steps, written on 9 Nov as a plan, were corrected with them.
- **After trial 3:** two comments that named the wrong rollback target were corrected — comments only, the script's
  edits 295 and 296.
- **After the record run's first start** (the rehearsal, below): four lines carried from 5.18.104's script still read a
  list through a pipe into a quiet `grep` — the report's headline (QF-N3), the doctor's two readings (QF-G2) and 090's
  verdict (VN-G7). Under `pipefail` such a pipeline can read "no match" although the line is there; the script's own
  header describes the hazard.
  - **What it could do:** the last three could turn a pass into a refusal at stage A, with nothing changed; the first
    could only print "could not be read" in a note. None could turn a fault into a pass.
  - **The fix**, the script's edits 297 to 300: each reads its variable as a here-string, the same bytes. 18 inputs
    were tried; the old form and the new agree on each. The derivation's last part now refuses a script in which a
    quiet `grep` reads straight from a pipe; with one of the four edits taken out, it refuses and names the line.
- **The whole script read for the same hazard** (14 Nov; two readers working apart; nothing run). Both count 686 pipes
  in 474 pipelines. Where the script judges a pipeline's status (40 pipelines by one count, 42 by the other), the
  reader reads its input to the end. The pipelines whose reader can stop early (58 `head` stages, 7 `grep -m1`, the
  `awk`s that exit, 13 functions used as readers) give only text: nothing judges their status. No further line was found,
  and the script is unchanged. That `tar` reads an archive from Git to its end (8 judged pipelines) is known of `tar`,
  not measured here.
- **The record run found nothing more in the script**: 5.18.105's own sections read 153 ok, 0 failed.

### The rehearsal — `scripts/harness/deploy-5.18.105/rehearse.sh`, 1,175 lines, sha256 `abfc59935858fbb1…`

**What it builds.** It is chained on 5.18.104's rehearsal (`scripts/harness/deploy-5.18.104/rehearse.sh`, 4,522
lines, sha256 `d217e8b1a62b7839…`, unchanged), read up to just before that rehearsal's last section, 104-10. The cut is
asserted in Python:
- the anchor (104-10's heading) exactly once, at line 4378, after line 900;
- the last section heading before it 104-7c's;
- that section's last non-empty line exactly `fi   # LIVE104`, once;
- its last check 7c16's, once.

One asserted edit points that rehearsal's own folder line (line 112) at its own folder, so its helpers are still found:
the anchor once, replaced once, the new line once and the old line nowhere. After sourcing, every function and variable
it needs must be defined, and `LIVE104=yes`. 5.18.104's rehearsal builds every earlier sandbox from its own rehearsal,
back to 5.18.93's. At the cut, 104-7c has ended with a rollback and the quarantine's `--check`: 5.18.103 is serving,
5.18.104's added files are on disk, no ladder key is set, and 089–091 are in the ledger.

Two helpers beside it:
- `rh105q.py` (826 lines), the rehearsal's own reader of the ladder in its four places, the table counts and the log
  forms. It is written in Python, opens the book read-only and never goes through the release's code, so the script's
  readings are set against an independent one.
- `rh105-ref.php` (161 lines), the release's own code as the reference.

**Its guards.** It refuses to start:
- where the server could be (`/data/ucrm`, `/opt/dishnet`, a Docker socket, a `/root/dnb-*` path);
- unless the deploy script is pinned short and full and pins T1's count;
- unless its static guard reads the script as `lk=1 qftool=0 wrapper=0 verbs=0` — the `lk` mode's line present, and no
  switch-tool verb invoked outside printed text — with its control, three planted copies (a `qf_tool` verb, the wrapper,
  the tool by path), each refused;
- unless `deploy-5.18.104.sh` is pinned to the baseline 5.18.105 names;
- unless both wrappers are committed and unchanged.

**How it judges.** Every pin is read from the script. Every expectation is computed from Git or from the release's own
code: no commit, count of files or hash is written in the rehearsal. Everything seeded is fictitious — every name and
text an `RH104SECRET-` value, every phone a number of the rehearsal's own range — and listed in 0600 pattern files for
the sweeps. The clock is the chain's (5.18.104's `rh104-clock`), which only moves forward.

**What each section proves** (root `docs/74` §H.6, as built):
- **105-0** — the script agrees with Git: the pins and the delta, no migration, `COPY_MARK` in the delta, the kept and
  changed lists with the four files that moved from one to the other, `LM_HUNKS`, the header's commands each alone, the
  retired codes silent, the report wrapper's pins.
- **105-pre** — production's state.
  - pre-a0: four offer claims that 5.18.104's rehearsal planted raw are removed first, by the ledger's own 400-day rule
    under a clock of that step's own (without that clock the ledger refuses the removal: the step's control).
    Production has none.
  - pre-a: the cut's state, read by the rehearsal's own reader.
  - 105-1a, here because 5.18.103 serves: `--check` refuses — *deploy 5.18.104 first*.
  - pre-b: 5.18.104 deployed again through its real script, `--redeploy`, `DEPLOY` typed: PASSED.
  - pre-c: the quarantine's `--check`.
  - pre-d: the ladder walked through the real tool: steps 1–5 on (L1).
  - pre-e: step 6 on, one chase sent and one STOP through the real webhook under 5.18.104, which writes one old-form
    line (L2, the most likely state at the deploy).
  - L3: step 1b on, steps 7–9 recorded skipped, step 11 on. L4: L3 and step 12 on.
- **105-c** — `--check` over L2 and over L1 reads GO; LK-G1 names exactly the steps the rehearsal's own reader finds
  on; LM-G1 and LM-G2 ok. Nothing changes: the keys byte-identical, the book's digest the same, no backup, no state
  file, no key file, the vault as it was.
- **105-1** — each refusal before anything changes, and its weakened copy caught: an `allKeys()` key held by
  `<data>/config.json` (LK-G1); a setting held there, only a note (gap 11); a part-applied book (QF-G1); a setting
  changed through the tool, and `on agent-cc`, while the `DEPLOY` question waits (LK-B1, nothing copied);
  `--clear-switches`, refused at parse (exit 64).
- **105-4** — the deploy over L2, `DEPLOY` typed: PASSED. LK-CA1 ok, and the four places' ladder keys byte-identical by
  the rehearsal's own reading; no tool ran; no table fell; one master cycle after the copy dispatches each job exactly as
  its flag reads; LK-ON; LM-CA1 and LM-CA2's counts; the rollback printed once, after the verdict, in a block of its
  own. Inside the same deploy (gap 4) the daily list's clean-up is held across the copy's start: LK-CA2 still passes,
  and the copy without its `list_last:%` filter fails. Then the same deploy over L4: LK-G1's note in capitals, every key
  unchanged, and its rollback prints the step-12 notice with the `off` command alone, never run.
- **105-5** — `--after-only` PASSED; LK-AO names no key; LM-ON passes; QF-ON judges steps 1–6. Then, through the
  installed tool, the owner's order: `skip voice-dry-run`, `on offers-test`, both wordings tested, its check PASS,
  `on offers-live`. LK-AO then names exactly the keys those commands wrote, as the rehearsal's own reading finds them
  changed. Gap 5's control: with the key file moved aside, it says the keys cannot be compared. Then four masked lines
  are driven through the installed 5.18.105, each in a log of its own — LN-01 a STOP through the installed webhook,
  LN-02 the staff report through the installed master on a scratch book, LN-03 a quotation WhatsApp whose sender throws,
  LN-04 a support job against the rehearsal's own fake CRM on 127.0.0.1. Each line is written once, in its masked form,
  with no form of the number.
- **105-6** — the rollback, `ROLLBACK` typed, with steps 1–6, 11 and 12 on and 7–9 recorded skipped: PASSED.
  5.18.104 is back by sha256. No tool ran: the closed follow-ups, paused offers and settled recipients are unchanged by
  the rehearsal's own count. LK-RB1 holds and the tool's `show` reads the same. The step-12 notice is in capitals, its
  `off` command printed alone and never run. LM-RB1's count is in capitals; its control, a STOP after the rollback,
  writes the old form again. V3g passes with no exception. Then the quarantine's `--check`: PASSED.
- **105-6v** — a deploy whose sign-in page redirects rolls itself back; every key kept, no `off` run.
- **105-7** — three `--check` copies (`MASTER_BASE` left at 5.18.104's script's value, `5400be20…`, 5.18.92's master;
  the Uganda signature left as 5.18.104's; VN-G4's *less W1* kept), each refused by the check it weakens; five deploy copies (the clear put back with LK-CA1
  removed; the clear alone; QF-CA5's 5.18.104 form; QF-RB1's clear called again in V1; a key not kept between runs),
  each caught; `--redeploy` over the live ladder PASSED, BR1 counting 5.18.105's files on disk; LM-ON made to expect
  the old blocks reads FAIL.
- **105-10** — what it left behind, over 5.18.104's records and 5.18.105's alike: no run of the script called
  Evolution; uCRM was asked GETs only; no seeded name or number in any terminal, log, sheet or state file; the LK key
  file 0600, its value nowhere; no record the plugin wrote after the copy holds a number in any form 5.18.105 masks
  (chosen by each record's own stamp, never by byte offset), with the four driven lines present as the positive
  controls; the checkout as the run found it.

**Weakened copies and controls.** Sixteen weakened copies of the script, each an asserted edit of its own text, each
caught by the check it weakens: five for 105-1 (105-1a''s run in 105-pre, while 5.18.103 serves), LK-B1's (run after
105-4, so that 105-4 is a first deploy of 5.18.105, as production's will be), gap 4's, the three of 105-7a, the five of
105-7b and 105-7d's. The rehearsal's own instruments have controls
of their own: the static guard's three planted copies; each drive's positive control; gap 5's control; the clock zone
control of the log sweep.

**Two deviations, printed in every run as `RH-NOTE`s, accepted:** production's 5.18.104 was a first deploy, the
rehearsal's a redeploy over the book 5.18.104's rehearsal ran on; and 105-1e's weakened copy runs after 105-4's first
deploy (above). A third, the development shortcut that restores pre-d and pre-e from 5.18.104's own snapshots, is printed
only in a development trial; the record run walks.

**Reviewed three times before any run:** 13, 8 and 9 confirmed defects, each checked against the code, fixed and
rechecked. The third round confirmed 9 and refuted 2 (`b981f9c`). Those that amend the design are root `docs/74` §S.2
item 4.

**Trials, each alone.** The development trials ran from a saved state of the chain (root `docs/74` §S.4):
- **Trial 1** (13 Nov, 17:59–19:07 UTC; the ladder states taken from 5.18.104's own snapshots): **2,680 ok, 20 failed**
  — the three inherited checks, and 17 that were two defects of the script, T1-a and T1-b, each fixed in the
  derivation. Nothing else failed: each weakened copy was caught, and the drives of LN-01…LN-04 passed.
- **Trial 2** (13 Nov, 19:09–20:26 UTC; the ladder walked through the real tool; the script with T1-a and T1-b fixed):
  **2,707 ok, 5 failed** — the three inherited checks and two expectations of the rehearsal, both fixed in the
  rehearsal. No check of the script failed but where a weakened copy or a planted fault made it. The walk (pre-d and
  pre-e, 19 checks) passed whole on its first run.
  - **T2-a:** 105-4f counted the day's `list_last` rows as its own two. Once the ladder is walked, the day already holds
    the row of the walk's own list run. The two planted rows are now counted as a difference.
  - **T2-b:** step 12's check failed in `--after-only` (`ledger_unmapped`), rightly, on the four offer claims 5.18.104's
    rehearsal had planted raw. They are now removed at the cut (pre-a0).
- **Trial 3** (13 Nov, 20:28–21:43 UTC; every fix above): **2,710 ok, 3 failed** — the three inherited checks alone.
  Every FAIL line the script printed was a weakened copy's or the planted sign-in fault's.
- **The record run's first start** (13 Nov, 21:45 UTC, on `0214b50`) was stopped by hand at 22:05 UTC, at 380 ok and
  1 failed, early in the 5.18.96 stage. A run with a fourth failure cannot be the record; its log is kept.
  - **The failure was not this release's.** It was check 3s' of 5.18.95's rehearsal, which runs a weakened copy of
    5.18.95's script. On a line the copy keeps unchanged from the script, it refused a pin that the copy run before it
    had read correctly two seconds earlier.
  - **The cause is the pipe hazard** (the script, above), in the older script, which the chain runs as it was. The
    shell writes a list one line at a time, the quiet `grep` stops at its first match, and a writer cut off mid-list
    fails the whole pipeline. It takes an unlucky moment. Two review readers were working beside the run; a busy machine
    may make such a moment likelier, but that was not measured.
  - **Shown directly:** with the writer slowed, the pipeline reports a failure (status 141: the writer was cut off)
    although the `grep` had found its line (status 0); the same check read from a here-string reports success. Tried
    8,000 times afterwards, idle and with the machine loaded, it did not happen again. It is rare.
- **Trial 4, a short one** (13 Nov, 22:14–22:21 UTC, on `be8930c`; stopped by hand after 105-c): 33 ok, 0 failed in
  5.18.105's own sections. `--check` read GO over both ladder states, with QF-G2 and VN-G7 ok and QF-N3's report
  printed.
- **The record run's second start** (22:22 UTC, on `be8930c`, nothing beside it) was cut after 8 minutes by a restart
  of the container, at 148 ok and 0 failed. **Its third start** (22:34 UTC) was cut after 71 minutes by another
  restart, in the 5.18.98 stage, at 1,020 ok and 1 failed, the first inherited check (98-0); the 5.18.95 stage passed
  whole. Nothing of the runs caused the restarts, as far as can be seen. A cut run does not count.
- **Trial 5** (13 Nov, 23:48 – 14 Nov, 01:12 UTC; the ladder walked; on `d433d90`, whose script and rehearsal are
  `be8930c`'s, the script pinned to `f764699`): **2,710 ok, 3 failed** — the three inherited checks alone. 5.18.105's
  own sections read 153 ok, 0 failed. The outputs holding a FAIL line are those of six weakened copies and of the
  planted sign-in fault, the same seven as in trial 3.
- **Trial 6, of the script derived again on `7835906`** (14 Nov; sha256 `c70820cd69af899b…`, as committed in
  `2a41c9d`, the trial on `7bb7a3e`; the ladder walked):
  - **Its first start** (18:13–18:15 UTC) was stopped by hand at 30 ok and 25 failed. Both deploy scripts it ran,
    5.18.104's and 5.18.105's, refused at A0 with 5.18.94's check: *this checkout writes commits in a short form of 8
    characters*. The sandbox's clone held 16,421 packed objects, and from 16,384 Git writes its short commit ids with 8
    characters. The check did its job. Two temporary branches were deleted (kept under `refs/keep/`), and the trial's
    runner, never committed, now sets `core.abbrev 7` on the clone if it writes 8 characters — the remedy the refusal
    prints. It did not have to in the second start (16,381 objects).
  - **Its second start** (18:17–19:47 UTC): **2,710 ok, 3 failed** — the three inherited checks alone. 5.18.105's own
    sections read 153 ok, 0 failed, as in trial 5.

**What was found, by kind.** In the script: T1-a and T1-b (trial 1); sweep 1's seven (a read beside trial 2); two
comments after trial 3; the four latent pipelines (after the record run's first start); all fixed in the derivation. In
the rehearsal: T2-a and T2-b (trial 2). None was in the release's own code: each was the script's or the rehearsal's
(root `docs/74` §S.4).

**The record**, the whole chain in one piece, from 5.18.93's rehearsal to 5.18.105's, unrestored, nothing beside it:
begun 14 Nov at 19:53:46 UTC on `a7b52e7`, ended 15 Nov at 05:41:41 UTC; the script as committed in `2a41c9d`, the
rehearsal as committed in `0214b50`. **2,710 ok, 3 failed** — the three inherited checks alone, 98-0, 99-0 and 100-0.
The chain before 5.18.105's own sections read 2,557 ok and those three; 5.18.105's own read **153 ok, 0 failed**, as in
trials 5 and 6. Its clone wrote the 7-character short form. No restart cut it, and its last check found this checkout as
the run had found it. Recorded in `d0e6d7f`.

### Runbook

As root on the server; each command its own paste; send back each run's **log file**, never a copy of the terminal and
never a cash sheet. Each log is 0600 and goes where its command puts it: the deploy script's runs under
`/root/dnb-5.18.105/`; the report's under `/root/dnb-report/`; the quarantine's `--check` under
`/root/dnb-stray-quarantine/`; the switch tool's wrapper writes its own, `/root/dnb-sales/followup-<UTC>.log`, and prints its path at the end. Send each log from the folder its command
names. **The script changes no switch:** the steps that are on stay on and keep running through the deploy; it turns
none on and none off. Make no KYC registration and no quote to try the release: the plugin WhatsApps every new quote to
its customer.

**The deploy**

0. **What must be true first.**
   - **5.18.104 is live**, since 9 Nov (copy 03:50:25 UTC). The script refuses any other live commit or version.
   - **`release/5.18.105` = `7835906` is on GitHub**, pushed on 15 Nov at 10:15 UTC on the owner's word (*"Push now,
     deploy after B2"*). The `--check` and deploy commands below fetch it, and their `&&` stops them if that fails;
     every run of the script also fetches it when the checkout lacks it.
   - **`claude/study-this-jhe2eg` carries `scripts/deploy-5.18.105.sh`** (sha256 `c70820cd69af899b…`) and both
     wrappers, since `2a41c9d` (14 Nov); no later commit has touched the three. The commands below pull it. The record
     run and the server's `--check` ran these bytes.
   - **Pass B2 has ended.** «B2: when pass B2 on `7835906` ended; its files, passed and failed counts; and whether
     nothing failed beyond the seven container-only checks — or, beside them, only `D03 --all` (in
     `test_master_104.php` or through L05), which root `docs/74` §S.3 reads as its known limit». The owner's word is to
     deploy after B2.
   - **The quarantine's `--check` has PASSED since 5.18.104's copy**: 9 Nov, begun 04:39:36 UTC. Stage A's A14 reads
     that record. No copy or rollback has happened since, and the server's `--check` of 15 Nov read GO. Run it again
     only if A14 asks for it, which it does after any later copy or rollback (it then prints this command); never while
     a deploy, an `--after-only` or a rollback runs. Its own command:

         ( umask 077 && cd /opt/dishnet && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee /root/dnb-stray-quarantine/check-$(date -u +%Y%m%dT%H%M%SZ).log )

1. **`--check`, read-only — it read GO on the server on 15 Nov** (begun 10:23:30 UTC, the checkout at `7e9a746`, the
   script's bytes as committed in `2a41c9d`): 78 ok, 0 failed, 62 notes; nothing was changed. LK-G1 found steps 1–3 on
   (report, ack, tracking), and LK-ON read each PASS through the pin's tool on a copy; LM-G1 and LM-G2 ok; Z1 the live
   vault untouched (root `docs/74` §S.5). Steps 4 and 5 came on after it, on 15 Nov at 10:50:40 and 10:58:46 UTC. A
   second `--check` is optional: the deploy runs stage A again before it asks for `DEPLOY`, and reads the ladder as it
   then stands. If you run one, its own command:

       cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.105 && mkdir -p /root/dnb-5.18.105 && bash scripts/deploy-5.18.105.sh --check 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/check-$(date -u +%Y%m%dT%H%M%SZ).log )

   It ends `5.18.105 (check): GO — stage A holds.` and prints the deploy command, or NO-GO, having changed nothing: send
   the log file and stop. One refusal has its own remedy. If it stops with *this checkout writes commits in a short form
   of 8 characters*, run the command it prints, alone, then the `--check` again; it stops before anything is changed.
   Git writes 8 characters once a checkout holds 16,384 packed objects, and a rehearsal's clone crossed that count on
   14 Nov. The command:

       git -C /opt/dishnet config core.abbrev 7

2. **Tell every admin and the agents** that no screen, no message and no switch changes at the deploy: only the text of
   the logs does. One search changes with it: a new entry can no longer be found by a full phone number in the Failed
   Queue's CRM Events search or in the Activity Log's filter; the client id, the invoice number and the name stay
   searchable (root `docs/74` Q3). While the deploy, its `--after-only` or a rollback runs, no one runs
   `scripts/sales-followup.sh`, and no one uses the Salesperson numbers card, ECO Bank (keep no statement), Send
   Quotation, the KYC form, `tools/set_config.php` or Settings: a change made then fails the checks that compare before
   and after. From the moment the deploy starts until its last line, no admin touches the uCRM catalogue screen, makes or
   removes a uCRM link, saves a plan or a kit, or changes uCRM's Products, Service Plans or client attributes.
3. **Deploy**, after pass B2, at a quiet hour: not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00 Kampala),
   and not while the morning list is being sent (step 4, on since 15 Nov: Monday to Saturday 08:00–11:00 Kampala, which
   is 05:00–08:00 UTC; the script marks this rule inferred). Step 6 (`on chase`), due from 05:28 UTC on 16 Nov, does not
   wait for 5.18.105 (the owner's ruling, 9 Nov), but **hold it until 5.18.106 is deployed** (root `docs/75` §F, 15 Nov):
   it puts enquiry sends into the ledger's claim mode, which today would close the refused drafts and send the stale
   backlog; and whenever it comes on, never while this script runs. The deploy runs stage A again, then asks you to type `DEPLOY`. Its own command:

       cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.105 && mkdir -p /root/dnb-5.18.105 && bash scripts/deploy-5.18.105.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )

   Expected: `5.18.105 (deploy): PASSED.`, 0 failed, with notes. LK-B1 and LK-CA1: every ladder key as the run found it.
   LK-CA2: no row fell. LK-ON: no step that passed before fails now. LM-CA1: the masked lines installed. R7: the ledger
   the same, 091 last — no migration. The summary's *the screens*: none changes. The rollback's block comes after the
   verdict, on its own: do not run it.
   - Any refusal before `DEPLOY` changed nothing. LK-B1's refusal, just after `DEPLOY`, copied nothing. Send the log
     file and stop.
   - A deploy whose public sign-in page redirects rolls itself back at once (V1), every switch kept, and says so.
   - If it ends FAILED, send the log file. Do not roll back on your own unless staff or customers are affected.
   - Run it once. A second run that finds 5.18.105 serving skips the deploy and runs the `--after-only` checks.
   - The cash sheet is read on the server only (`less`, its path in the log). Never paste it, copy it or send it.
4. **`--after-only`**, at least 20 minutes after the copy (the summary prints the time) — read-only:

       cd /opt/dishnet && bash scripts/deploy-5.18.105.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/after-$(date -u +%Y%m%dT%H%M%SZ).log )

   Expected: `5.18.105 (after): PASSED.` LM-ON: the masked lines installed, and no old-form opt-out line since the copy.
   LK-AO: the keys changed since the deploy, a note — none, unless a step was turned on in between (step 6, for
   example, whose keys it then names). QF-ON0–QF-ON5, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2 and AC-ON1 judge each step
   that is on; a step that is off reads `OFF — not judged`. R19 is conclusive once master has run the retry job after
   the copy. Then, on its own, the quarantine's `--check`, with the command in step 0: the next deploy's A14 needs one
   begun after this copy. Run `--after-only` again the next day after 15:00 UTC, once the evening WhatsApp has run on
   the new code, and after each step of the ladder.

**What you see.** No screen of the plugin's or of uCRM's changes, and customers get the same messages as before. On
Uganda, the log lines the release masks stop carrying a phone number from the copy on; lines already written stay as
they are (root `docs/74` §H.2.7). The report (`scripts/sales-followup-report.sh`, pinned to `7835906`) prints its BY
NUMBER table in two clean columns again; it already does so before the deploy, since it takes the report from Git.

**The steps after the deploy — yours alone, one at a time, in the ladder's order.** Nothing is done again: the steps
that were on are still on and behave as under 5.18.104; only their log lines carry no number. Their commands are
5.18.104's runbook, above, unchanged: the tool is 5.18.104's, byte for byte. Never run it while the deploy script runs.
Where the ladder stands, read-only:

    cd /opt/dishnet && bash scripts/sales-followup.sh show

- **On 15 Nov**, steps 1–5 are on: report and ack since 9 Nov; tracking since 13 Nov, its check PASS on 15 Nov; list
  and voice-measure since 15 Nov. Step 6 (`chase`) may come on from 05:28 UTC on 16 Nov, without waiting for 5.18.105; it is held until
  5.18.106 is deployed (root `docs/75` §F), and so are `offers-test` and `offers-live`, whose switches also put enquiry
  sends into claim mode.
- **Step 12, `offers-live` — only once 5.18.105 serves and its `--after-only` has PASSED** (the owner's ruling, 9 Nov;
  root `docs/74` §A). 5.18.104's tool does not check which release serves: that PASSED is the check. Step 12 also needs
  every step before it on, skipped or optional, and `offers-test` on with its check at PASS, as before. Then:

      cd /opt/dishnet && bash scripts/sales-followup.sh on offers-live

  then its check:

      cd /opt/dishnet && bash scripts/sales-followup.sh --check offers-live

  Then an admin presses Start on the *WhatsApp offers* screen, as 5.18.104's runbook says, and you run `--after-only`
  again (step 4).

### Rollback — its own block, never pasted with the deploy

Only if it is needed. It puts 5.18.104 back and changes no switch. Every deploy and `--after-only` prints its command
after the verdict, on its own. Do not roll back on your own unless staff or customers are affected; send the log file
first. To stop one part of the sales follow-up, do not roll back: turn its step off with the switch tool, `off` and the
step's name, in a command of its own, never while this script runs.

The rollback asks you to type `ROLLBACK` before it changes anything. As root, on its own:

    cd /opt/dishnet && bash scripts/deploy-5.18.105.sh --rollback 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/rollback-$(date -u +%Y%m%dT%H%M%SZ).log )

**Before `ROLLBACK` is typed** (root `docs/74` §H.5.3), read-only: LK-RB0 — the ladder's keys in the four places,
under the deploy's kept key (or a key of the run's own if the key file is gone), the rows, and the tool's `show` on a
copy. **If step 12 is on**, a notice in capitals says that `994d660` writes phone numbers again in the 35 lines 5.18.105
masked, the opt-out line among them, while offers run, against the owner's ruling. The script never turns the step off,
and the ladder never blocks the rollback (the default of root `docs/74` Q8, which ships unless the owner rules
otherwise). Change no switch while it waits. Your
own alternative, if you choose it: answer anything but `ROLLBACK` — nothing has been changed — then turn `offers-live`
off with the command the notice prints alone on its line (`bash scripts/sales-followup.sh off offers-live`, from
`/opt/dishnet`), and then run the rollback again. A chase that is on gets a plain note: its STOPs write the number again,
as they did before 5.18.105.

**What it does, in order.** It checks out `994d660`, runs the documented deploy (`scripts/deploy-hybrid.sh`), returns
the checkout to its branch, gives the files 5.18.105 changes or adds the time of this copy, and records that time
(`RB_COPY_AT`, read off `evo_webhook.php`). Nothing else: no switch tool, no off-hook, no put-back of rows or keys by
hand. Then RB105 (§H.5.4), read-only:
- the 46 files 5.18.105 changed, at `994d660`'s sha256; the 386 it kept, unchanged; master `b59e7ce5…`, 5.18.104's,
  with `quote_chase`, `quote_daily_list`, `ai_media` and `offers_send` registered last; the event processor at
  5.18.104's signatures;
- LK-RB1: every ladder key in every place equal to LK-RB0's, the tool's `show` the same line for line, and no table's
  rows fell (FAIL otherwise);
- the open quote follow-ups and the running offers counted, a note: they are 5.18.104's own and carry on;
- LM-RB1: each old block back, and the note in capitals that 35 log lines write phone numbers again;
- R7 the ledger the same, 091 last; V3g with no exception; the 43 files 5.18.105 adds (tests only) on disk and reached
  by no runtime file (QC-RB1); the web-direct applications with no quotation counted again (QC-RB2); and the carried
  checks — the sign-off, the owner rule, ECO Bank, the documents and the quarantine.

The last line reads `5.18.105 (rollback): PASSED.` It refuses a copy that stopped part-way, and prints the code's
put-back by hand.

**What it brings back:** 5.18.104 (`994d660`), and with it a phone number in each of the 35 masked lines (37 log
statements; root `docs/74` §H.2.8). No rotation of the OpenAI key is asked for: VN-RB6's instruction is retired, the
voice steps keep running, and 5.18.104's WhatsApp AI screen says only *set* or *not set*.

**What stays:** every switch as found — each step that is on keeps running, and open quote follow-ups and running
offers carry on; 089's, 090's and 091's tables and every row, and P7's collections (the copy never touches the data
folder); the 43 added test files, on disk; ECO Bank, its switch and every statement kept; the uCRM catalogue, the quoting
organization, the channel registry and every number's owner, exactly as they are.

**After it.** Run the quarantine's `--check`, its own command, as in the runbook's step 0: A14 of any later deploy needs
one begun after `RB_COPY_AT`. By the owner's ruling, step 12 is not switched on while 5.18.104 serves. A later deploy of
5.18.105 goes with `--redeploy` — the rollback's summary prints it, its own command, to run only once the cause is
understood: BR1 counts the 43 files 5.18.105 left on disk, QF-G1 reads 5.18.104's tables, and the ladder rule holds as on
the first deploy. **Only if the script cannot run**, every deploy and `--after-only` also prints the code's put-back by
hand, one command, alone: it checks out `994d660` and runs the documented deploy, counts nothing and runs no switch tool.
Send the log file before doing anything by hand. Send back the rollback's log file, `rollback-…` under
`/root/dnb-5.18.105/`, and the quarantine check's.
