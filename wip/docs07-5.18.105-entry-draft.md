<!-- DRAFT of the root docs/07 entry for 5.18.105, kept in the repository so that a container restart cannot lose it.
     The marks ⟦PENDING: …⟧ stand for results still to come: the rehearsal's record, the suite's two passes and the
     owner's word to push the release. It moves into docs/07-change-control.md when they are in. Until then root
     docs/74 §S is the record, and nothing here says the release is pushed or deployed: it is neither. -->

## 8–13 Nov — 5.18.105: on Uganda, no phone number in any log line — 35 edits in 19 files take the number out of 37 log statements, and the sales report's BY NUMBER table lines up again; nothing else changes — no message, send, stored record, setting or migration — and every sales follow-up switch stays as found. `release/5.18.105` = `f764699`, one commit on 5.18.104 (`994d660`), built; LOCAL ONLY, not pushed — it is pushed only on the owner's word; `scripts/deploy-5.18.105.sh` pinned to it; the release suite twice on it: ⟦PENDING: both passes on `f764699` — files, passed, failed, and whether only the seven known container checks fail⟧; the rehearsal's record: ⟦PENDING: the record run — ok, failed, and which checks failed⟧; NOT deployed

**Request.** The owner's rulings of 8 and 9 Nov (5.18.104's known limit 13, above). On 8 Nov: 5.18.104 deploys as
built, and 5.18.105 masks the four older log lines that carry a phone number, ready before step 12 (`offers-live`) is
switched on. Later on 9 Nov, after the design (root `docs/74`) found about 25 more such lines on Uganda every day:
5.18.105 masks **every** log line that can carry a phone number on Uganda, not only those four; the chase (step 6) does
not wait for it; `offers-live` still does. The report's column fault, seen in the live report on 9 Nov, is in scope too.

**Where the server stands, 13 Nov.** 5.18.104 is live since 9 Nov. Steps 1 (`report`) and 2 (`ack`) are on since 9 Nov,
step 3 (`tracking`) since 13 Nov, 05:27:38 UTC. Steps 4 and 5 can come on from 14 Nov, step 6 (`chase`) from 16 Nov at
the earliest. None of them waits for this release. Only step 12 waits.

**What the owner does next, in order.** Each command is in this entry's runbook, in a block of its own; the rollback has
a section of its own.
1. **Nothing yet.** Two results are still missing above: the suite's two passes and the rehearsal's record run.
2. **Give the word to push `release/5.18.105`.** The branch is local only. The deploy fetches it from the remote, so it
   cannot run before the push.
3. **Run the deploy on the server.** A `--check` first is wise: it reads, refuses or passes, and changes nothing. Then
   the deploy. Send back the log file, never a copy of the terminal.
4. **Run `--after-only`** at least 20 minutes after the copy, then the quarantine's own `--check`. Run `--after-only`
   once more the next day after 15:00 UTC.
5. **Step 12 (`offers-live`) may follow once 5.18.105 serves and its `--after-only` has passed**, when its turn comes
   in the ladder's order. Steps 1–6 go on meanwhile as planned.

### Release notes (log text only; root `docs/74`, the release's `SAFETY.md`)

- **On Uganda, the log lines the plugin itself put a phone number into no longer get one.** 35 edits (LN-01…LN-35) in
  19 files cover 37 log statements: one edit, in the Evolution sync's log writer, covers three. They are the four lines
  of 5.18.104's known limit 13 and 33 more. What is left on purpose, and what the search may have missed, is in known
  limits 4 and 7.
- **Which lines, by kind:**
  - the opt-out line the WhatsApp webhook writes on each new STOP (LN-01);
  - the webhook log (`webhook_log.json`): the welcome, every `invoice.add`, a new service, a quotation, a credit note, a
    client message, and the accountant's alert after a payment is deleted (LN-16…LN-23);
  - the quotation and receipt WhatsApps in `quote_wa_log.json` (LN-11…LN-15);
  - the invoice job's two lines, a new invoice's WhatsApp (LN-09) and the renewal reminder (LN-10), and the overdue
    reminders (LN-26, LN-27);
  - the lead jobs (LN-28…LN-32), the watchdog (LN-08) and the two WhatsApp sync jobs (LN-24, LN-25);
  - the staff messages: the support leader's morning summary (LN-05), the nightly reconcile (LN-06), the cash-carry
    reminder (LN-07) and the staff SSP report (LN-02);
  - a quotation WhatsApp that failed (LN-03) and a CRM job created by the WhatsApp auto-reply (LN-04);
  - three admin actions in the Activity Log: an install confirmation (LN-33), an edit to a KYC application (LN-34) and
    a refund WhatsApp (LN-35; known limit 9).
- **What stands in the number's place:**
  - **Usually nothing.** The line already names its record: a job, quote, invoice or payment number, or a name.
  - **A record id**, where the line named nothing else: conversation #, client #, lead # or the invoice number (LN-01,
    LN-09, LN-10, LN-25, LN-27, LN-30…LN-32).
  - **`••••` and the last two digits**, or `none` where there is no number, in the webhook log's three phone fields
    (LN-17…LN-19). A new helper, `PluginLog::tail()`, writes it. The Sales numbers admin already shows this form.
  - **`<number>`**, where free text passes through the existing `PluginLog::mask()`: the error text of a failed quotation
    WhatsApp (LN-03) and every line of `evo_sync.log` (LN-24).
  - **`changed`**, for the `mobile` and `note` fields of a KYC edit (LN-34). The other nine fields keep their before and
    after values.
- **The report's BY NUMBER table lines up again** (`tools/sales_followup_report.php`). It now pads each cell by
  characters, not bytes, so a masked number such as `••••57` and a four-digit count print as two fields. The report's
  JSON rows are unchanged.
- **The screens that show these logs show the new text, and a search by number changes.** New entries can no longer be
  found by a full phone number in the Failed Queue's CRM Events search or in the Activity Log's filter. The client id,
  the invoice number and the name stay searchable.
- **Unchanged:** every message and every send; every stored record; every screen's code; every setting — the release
  reads no new one; the migrations — none is added, and 091 stays the last; and every sales follow-up switch. The
  deploy never runs the switch tool, and a rollback goes back to 5.18.104 with every step as it was.
- **South Sudan:** the lines it shares with Uganda lose their numbers too, because the release gates nothing by tenant.
  Nothing else changes there. Lines that only South Sudan runs are left as they are (known limit 4), except the staff
  SSP report's line (LN-02), which the owner named; test section B holds that job's sends and stored rows equal to
  `994d660`'s.

**Files** (against `994d660`): 88 — 43 added, 45 changed; 4,131 insertions, 71 deletions; no migration.

- **Outside the tests, 23 files, all changed, none added:**
  - the 19 log files, each named in the release's `SAFETY.md`: the two webhooks, twelve job scripts, two classes under
    `lib/` and three form handlers under `includes/`; `webhook.php` carries eight of the 35 edits and `cron_quote_wa.php`
    five;
  - `lib/PluginLog.php`, which gains `tail()`;
  - `tools/sales_followup_report.php`, the column fix;
  - `SAFETY.md`, a new 5.18.105 section: 67 lines added, none removed;
  - `manifest.json`, the version line only.
- **Tests, 65 files:**
  - 43 added: `tests/test_log_mask.php`, the 41 files of `tests/log_mask/`, and `tests/fixtures/r105_revert.php`;
  - 22 changed: the older boundary tests re-pointed, their weakened-copy files, and the version pins (below).

**Ancestry.** `f764699` (5.18.105, release) ← `994d660` (5.18.104, release; DEPLOYED 9 Nov, copy 03:50:25 UTC). The
release is one commit, squashed from five working commits (`d3e9b7d` … `add6118`, kept locally under
`refs/keep/wip/5.18.105`); its tree is identical to `add6118`'s. It was built in three stages — C1 the code, C2 the
tests, C3 the re-pointed boundaries — each reviewed by someone who had not built it, then fixed and rechecked. The
development branch keeps the release as a patch (`wip/release-5.18.105.patch`; compared on 13 Nov, byte for byte the
difference between the two commits), so a container restart cannot lose it.

### Tests

**The release's own test is one new file, `tests/test_log_mask.php`. Its 73 weakened copies are each caught. The
suite's two passes on `f764699` have not finished.**

- **`tests/test_log_mask.php`** (sections under `tests/log_mask/`): ⟦PENDING: its passed and failed counts in each
  pass, and whether they are the same⟧. Every person and number in it is fictitious.
  - **A — each masked line, driven through its real path:** a served page, a sandboxed job run, or a direct call with
    fakes, in 21 groups (G01–G21). There is one drive per line; LN-24's covers its three statements. For each line: it
    is written, found by its fixed words (the positive control); no form of the test number is in it; and no run of
    nine digits or more is left. `PluginLog::tail()` and `PluginLog::mask()` are tested themselves.
  - **B — South Sudan:** `cron/staff_ssp_report.php` sends and stores what `994d660`'s does. Only its log line moves.
  - **C — the chase's precondition:** the two strings step 6 looks for in `evo_webhook.php` are each there once, and
    `on chase` still reaches `W2: installed`.
  - **D — the boundary against `994d660`, read from Git (D1–D7):** only the declared files change; `migrations/` is
    the same, 091 last; the 13 held files (the switch tool, its two classes, `cron/master.php` and nine more) are byte
    for byte the same; every edit carries the release's marker; no new setting is read; and an edit in a runtime file
    touches log statements and comments only, with a short declared list of exceptions.
  - **E — the logging rule:** E1, no log statement the release adds names a phone variable, except as the whole
    argument of `tail()` or `mask()`. E2, the same over every log statement in the plugin, against a list of the sites
    left on purpose: a new log line that carries a phone fails, and so does a list entry that matches nothing.
  - **F — the report's columns**, counted in characters: every BY NUMBER row's `conv` field ends under the header's,
    with a space before it; the JSON rows are unchanged.
  - **The 73 weakened copies.** Each plants one fault in a copy of the plugin, and the section that guards it must
    fail. 35 put `994d660`'s line back, one per LN. 19 put a whole log file back to `994d660`'s. 2 stub `tail()` and
    `mask()`. Then B 2, C 2, D 8, E 4 and F 1. A copy whose anchor is not found exactly once is a failure, never a skip.
- **The layer, `tests/fixtures/r105_revert.php`, lets every older boundary test still judge 5.18.104's tree.** Those
  tests compare the plugin with older releases; to them each 5.18.105 edit is an undeclared change. The layer takes
  5.18.105 out first, against `994d660` read from Git, and each older test then judges what is left, unchanged.
  - **Through the layer** (`r105_less()`, the tree with every marked 5.18.105 edit put back): `quote_followup` N and M01,
    `quote_agent_copy` J, `catalogue_direct` I, `quote_from_ucrm` I, the switch tool's TL14 (its main scan), and
    `test_quote_company` I4.
  - **By exact text** (`r105_revert()`), for the one file two tests hold as text, `includes/post/post_cashbook.php`:
    `test_bank_boundaries` (A and F) and `test_cashbook_physical_list` M. `test_bank_boundaries` A also checks that the
    revert gives back `994d660`'s bytes exactly.
  - **Re-pointed on purpose, never weakened.** Nine weakened copies plant an edit 5.18.105 did not make, and the older
    test must still fail on it: QN8, QN8b and QN11 (N), MI7 (`catalogue_direct` I), MI5 and MI7 (`quote_from_ucrm` I),
    W-r105a (`test_quote_company` I4), BM-r105 (`test_bank_boundaries` A) and W-r105 (`test_cashbook_physical_list` M).
    Each first asserts that its edit stands where the layer leaves it in place, or it counts as a failure. `SAFETY.md`'s
    list of these copies does not name QN8b; the test's own comment does.
  - **The control on the controls, N09:** N's own checks, run with 5.18.105 left in, must fail on 5.18.105's files and
    edits. So the layer is what makes N pass.
  - **The version pin, 5.18.104 → 5.18.105,** moves in eight test files: the five `test_distributor_*`,
    `test_bank_boundaries`, `test_cashbook_physical_list` and `test_quote_company` (I5).
  - ⟦PENDING: each re-pointed file's count in both passes, beside its count on `994d660`⟧
- **The release: the full suite twice on `f764699`** — 317 files, 5.18.104's 316 and `test_log_mask.php`.
  - **Pass ⟦PENDING: its name⟧:** ⟦PENDING: begun and ended (UTC); files, passed, failed, EXIT⟧.
  - **Pass ⟦PENDING: its name⟧:** ⟦PENDING: begun and ended (UTC); files, passed, failed, EXIT; whether every file's
    count equals the first pass's⟧.
  - **Known to fail in this container only** (5.18.104's known limit 11): seven checks in four files —
    `test_install_authorisation` 379/2, `test_mail_quote` 91/1, `test_migration_integrity` 27/1 and `test_secure_file`
    22/3. They read so on `994d660` in 5.18.104's passes A3 and B. ⟦PENDING: whether both passes fail exactly these
    seven, word for word, and nothing else⟧
  - **Two cut passes do not count.** Pass A was cut by a container restart in file 189; to that point 11,788 passed
    and 4 failed. Pass A2 began on 13 Nov at 05:29:32 UTC and was cut by a restart; its log ends at 10:19:34 UTC inside
    file 230 of 317, with 15,127 passed and 4 failed. In each, the 4 were in the three of those four files the pass had
    reached.
  - ⟦PENDING: the comparison with 5.18.104's pass B (316 files, 22,578 passed, 7 failed), file by file⟧
- **T1 and T4, the deploy script's signatures, are carried unchanged:** `548 passed, 0 failed rc=0` and
  `20 passed, 0 failed, 0 skipped rc=0`. The design infers that the release adds no test to the set the server runs;
  both passes measure T1 again. ⟦PENDING: `test_cash_in_hand_p3b`'s count in both passes⟧

### `scripts/deploy-5.18.105.sh` — 14,744 lines, sha256 `c028cde545e042ba…`

**What is new.** It deploys over a sales follow-up that is switched on, and it changes no switch. It never runs the
switch tool's `on`, `off`, `skip`, `set` or `erase`, nor `scripts/sales-followup.sh`, in any mode. The steps that are on
keep running through the deploy and through a rollback. The script reads every switch before and after, and fails if
one moved. The release adds no migration and no setting.

**How it is made.** From 5.18.104's script as committed (sha256 `1aafc15bd1cdb1d7…`) by asserted edits
(`wip/derive-5.18.105/derive105.py`, root `docs/74` §H.4.1). An asserted edit checks itself: its anchor is found the
stated number of times, it is replaced that many times, and the result holds the new text. Any failure exits non-zero
and writes nothing. The release commit is a parameter. The derivation refuses one that is not cut on `994d660` in a
straight line, or whose manifest does not say 5.18.105; a build commit further along that line is derived only with a
warning, and the script's own stage A then refuses it. It ran on `f764699`, whose parent is `994d660`. Every pin, file
list, count and sha256 is read off Git, never typed. Eleven parts, in the order they run, 300 edits:

- **A** identity: the pin, the baseline (`994d660`, live), the earlier releases' state files, the three sha256 lists;
- **B** the carried checks the release moves: no migration, master's two pins, the copy's time mark (`evo_webhook.php`);
- **LK** the ladder's rule (below), and 5.18.104's checks that assumed every switch off, made to read a live ladder;
- **R** the rollback: back to `994d660`, every switch kept;
- **V**, **O**, **P** 5.18.104's voice, offers and agent-copy checks, made to read a live ladder;
- **L** the masked lines' checks (below);
- **H** what the operator reads: the header, the options, the summary, the rollback in its own block;
- **C** the carried texts that named the wrong release;
- **Z** the retired readings removed, and the rules asserted over the whole result.

Before anything is written, the result and both wrappers pass `bash -n`. A check over the whole text, that the ladder is
kept, must refuse four weakened copies, each for its own reason: the clear put back; LK-CA1 removed; the switch-off
rollback called again in the automatic rollback; the row count taken without its filter. 5.18.104's folder check,
carried, must refuse three more. The derivation was run twice, byte for byte the same. The script itself never ran in
it. The first build had 277 edits (`2db0431`); trial 1's fixes made 284 (`cbbc985`); sweep 1's made 294 (`863c870`);
two comments corrected after trial 3 made 296 (`0214b50`); four carried pipelines made 300 (`be8930c`).

**The two wrappers.** `scripts/sales-followup.sh` is unchanged (89 lines, sha256 `bbde2d04…`).
`scripts/sales-followup-report.sh` is written again by the derivation (158 lines, sha256 `85b93f0b36493cf3…`), because
the report's column fix is in the release. It is pinned to `f764699`. The five installed files it reads are the same in
5.18.104 and 5.18.105, so it runs before the deploy and after a rollback too. It fetches the release branch itself, and
stops, having changed nothing, where the checkout holds no `f764699`.

**New — the ladder kept as found (LK).** The ladder is the sales follow-up's steps and their switches. A switch is a
key, and a key can live in four places: `<data>/kyc_config.json`, its row in the book, `<plugin>/data/config.json` and
`<data>/config.json`. The script reads all four, read-only. The log carries key and step names only. Each value goes to
the 0600 sheet and the state file as a 16-character keyed hash, never as a value. The hash key is kept in
`/root/dnb-5.18.105/lk-key-5.18.105`, mode 0600, so that a later run can compare.

- **LK-G1** (stage A, and `--check`): the keys can be read in all four places, and no switch or record key sits in a
  `config.json`, where the tool could not turn it off. Otherwise NO-GO. A step that is on is never a refusal: a note
  names the steps on and skipped. If step 12 is already on, a note in capitals says so.
- **LK-B1** (after `DEPLOY` is typed, before the copy): the same reading, equal to LK-G1's key by key and place by
  place. It proves nobody ran the switch tool while the script waited. Otherwise NO-GO, and nothing is copied.
- **LK-CA1** (after the copy): every key in every place equals LK-B1's. It proves the deploy moved no switch. Otherwise
  FAIL.
- **LK-CA2** (after the copy): no row fell in the sales follow-up's tables, each migration's self-check row is
  unchanged, and the four jobs keep their schedule entries. It proves nothing was lost. The daily list deletes its own
  `list_last` rows by design, so those are left out of the count.
- **LK-ON** (after the copy): each step that is on is checked through the installed tool on a fresh copy, beside the
  same check at stage A. PASS before and FAIL after is a FAIL. It proves the steps still pass under the new code.
- **LK-AO** (`--after-only`): names every key changed since the deploy, as a note: those are the operator's own steps.
  Without the key file it says "cannot be compared", never "the same".
- **LK-RB0** (before `ROLLBACK` is typed): the ladder as it stands, kept for the comparison.
- **LK-RB1** (after the put-back): every key in every place and the tool's `show` are as before, and no table's rows
  fell. It proves the rollback moved no switch and lost no row.

**New — the masked lines (LM).** The script carries no source text. For each of the release's 39 changed blocks — the
35 masked log lines and four more: the helper, the report's column fix in two blocks, and `webhook.php`'s loader — it
holds the line count and sha256 of the old text and of the new.

- **LM-G1** (stage A): in the release's files from Git each new block stands once and each old block nowhere. In
  5.18.104's files it is the other way round, which is the control. It proves the script and the build match. Otherwise
  NO-GO.
- **LM-G2** (stage A): the release's `evo_webhook.php` still holds the two strings `on chase` requires. It proves the
  masking did not break step 6's precondition. Otherwise NO-GO.
- **LM-CA1** (after the copy): the same two readings on the installed files. It proves the masked lines are installed.
- **LM-CA2** (after the copy): PHP's log holds no old-form opt-out line stamped 60 s or more after the copy. One there
  is a FAIL. In the other log files the release's lines write to, records with a run of 9 or more digits are counted, a
  note. If PHP's log cannot be read, as at 5.18.104's deploy, it says NOT ESTABLISHED, a note.
- **LM-ON** (`--after-only`): LM-CA1 and LM-CA2 again.
- **LM-RB1** (after a rollback): the old blocks are back. It says in capitals that 35 log lines write numbers again.

**No migration.** Stage A refuses a pin that carries one (A0). 089, 090 and 091 must be on the book as 5.18.104 left
them (QF-G1). A book that ends at 088 is told to open the plugin in uCRM once and run again. A part-applied book is
refused.

**`--clear-switches` is refused**, before anything runs (exit 64): 5.18.105 never clears a ladder key. To turn a step
off, the operator uses `scripts/sales-followup.sh off` and the step's name, in a command of its own.

**5.18.104's checks, carried.** Where one required every switch off, it now reads the switch as found. QF-CA5, VN-CA1
and OF-CA1 must equal stage A's reading. QF-CA3, VN-CA2 and AC-CA1 must agree with their own key. QF-G4 is retired:
LK-G1 takes its place. The switch-off rollback's checks are retired with it: QF-RB1–QF-RB3, VN-RB1–VN-RB4, VN-RB6,
OF-RB1 and AC-RB1. V3g has no exception left: any change of an AI or WhatsApp setting during the run is a FAIL.

**Modes.** As 5.18.104's — a deploy, `--check`, `--after-only`, `--rollback`, `--redeploy`, `--move-list-only`,
`--check-bank` and the flags — less `--clear-switches`. The rollback puts 5.18.104 (`994d660`) back by the documented
deploy alone: no switch tool, no off-hook, no put-back of rows or keys by hand. Every switch is kept. Its section is
below.

**Found by the trials and a sweep, fixed in the derivation** (root `docs/74` §S.4):

- **T1-a** (trial 1): AC-CA2 refused in every deploy and `--after-only` (`refused=tree_p7`). Its harness refused a
  baseline that holds `lib/QuoteAgentCopy.php`. That was right while the baseline was 5.18.103. 5.18.104 carries the
  file as the release does, so both trees must hold it.
- **T1-b** (trial 1): every rollback failed its reference check (`batch1:event-processor`). It still expected
  5.18.103's worker-owned list in the event processor. A rollback of 5.18.105 leaves 5.18.104's.
- **Sweep 1** (13 Nov): both defects were carried lines that still took the baseline for 5.18.103. So two readers went
  through the script for more of that kind, in the branches a trial does not take, and a third checked each finding.
  Seven were confirmed and fixed:
  - one false pass: after a rollback, a book with none of the four offer tables was a note, and is now a FAIL;
  - one wrong instruction: QF-G1's words for a book that ends at 088 said to deploy 5.18.104 first, and now say to
    open the plugin once (above);
  - five false wordings, in notes, an ok line and two failure hints, which spoke of 089–091 as this deploy's own.

  The header's dates for the ladder's steps, written on 9 Nov as a plan, were corrected with them.
- **After trial 3:** two carried comments, never printed, still described the old world: the rollback's target as
  5.18.103, and QF-RB1's writes. Both were corrected in the derivation (`0214b50`). Nothing that runs changed.
- **After the record run's first start** (below): four checks carried from 5.18.104's script still read a list through
  a pipe into a quiet `grep`: the report's headline (QF-N3), the doctor's two readings (QF-G2) and 090's verdict
  (VN-G7). Under `pipefail` such a pipeline can read "no match" although the line is there; the script's own header
  describes the hazard and forbids the form.
  - **What it could do:** the last three could turn a pass into a refusal at stage A, with nothing changed. The first
    could only print "could not be read" in a note. None could turn a fault into a pass.
  - **The fix** (`be8930c`): each now reads its variable as a here-string, which delivers the same bytes. The old form
    and the new agree on 18 inputs. The derivation now refuses a script in which a quiet `grep` reads from a pipe;
    with one of the four edits taken out it refuses and names the line.
- ⟦PENDING: what the record run finds in the script, if anything⟧

### The rehearsal

**In short.** The rehearsal runs the real script in a sandbox, over a sales follow-up switched on through the real
tool, as production has it. Three development trials ran on 13 Nov. The first two found two defects in the deploy
script and two in the rehearsal itself, all fixed; the third was clean but for the three inherited checks. The record
run's first start was cut after 20 minutes, by a fault of an older script that the chain replays; four lines of this
script that shared the fault's form were corrected. Its second and third starts were cut by restarts of the
container, after 8 and 71 minutes. The record run: ⟦PENDING: the record run, in one sentence⟧

It is `scripts/harness/deploy-5.18.105/rehearse.sh` (1,175 lines, sha256 `abfc59935858fbb1…`, as committed in
`0214b50`) with two helpers: `rh105q.py`, the rehearsal's own reader, and `rh105-ref.php`, the release's own code as
a reference. ⟦PENDING: whether the record run ran on these bytes⟧

**What it is chained on.** 5.18.104's rehearsal, read up to just before its last section, 104-10. The cut is asserted.
That rehearsal builds every earlier sandbox the same way. At the cut, 5.18.103 is serving, 5.18.104's added files are on
disk, no ladder key is set, and 089–091 are in the ledger.

**How it judges.** Every pin is read from the script. Every expectation is computed from Git or from the release's own
code: no commit, count of files or hash is written in the rehearsal. `rh105q.py` is written in Python, opens the book
read-only and never goes through the release's code, so the script's readings are set against an independent one.
Everything seeded is fictitious. It refuses to run where the server could be.

**What each section proves:**

- **105-0** — the script agrees with Git: the pins, the list of files, no migration, the masked lines' list, the
  header's commands each alone, the retired checks silent, the report wrapper's pins.
- **105-pre** — it makes production's state. Four offer rows that 5.18.104's rehearsal had planted are removed first.
  `--check` refuses while 5.18.103 serves: "deploy 5.18.104 first". Then 5.18.104 is deployed again through its real
  script, and the ladder is walked through the real tool: steps 1–5 on (L1); then steps 1–6 on, one chase sent and one
  STOP, which writes one old-form line (L2); then step 11 on with steps 7–9 skipped (L3), and step 12 on (L4).
- **105-c** — `--check` over L2 and over L1 reads GO. LK-G1 names exactly the steps the rehearsal's own reader finds on.
  Nothing changes: no backup, no state file, no key file.
- **105-1** — each refusal comes before anything changes, and its weakened copy is caught: a switch key held by a
  `config.json` (a setting held there is only a note); a part-applied book; a key changed through the tool while the
  `DEPLOY` question waits (LK-B1: nothing copied); `--clear-switches`, refused at once.
- **105-4** — the deploy over L2, `DEPLOY` typed, reads PASSED. Every ladder key is byte-identical in the four places by
  the rehearsal's own reading. No tool ran. No table fell. One master cycle after the copy runs each job as its switch
  says. The rollback is printed once, after the verdict, in a block of its own. Inside the same deploy the daily list's
  clean-up is held across the copy: LK-CA2 still passes, and a copy without its filter fails. Then the same deploy over
  L4, where step 12 is already on: LK-G1's note in capitals, and the key unchanged.
- **105-5** — `--after-only` reads PASSED, and LK-AO names no key. Then the owner's next steps go through the installed
  tool (`skip voice-dry-run`, `on offers-test`, `on offers-live`), and LK-AO names exactly the keys those commands
  wrote. With the key file moved aside it says "cannot be compared". Then four masked lines, LN-01 to LN-04, are driven
  through 5.18.105's code as installed — LN-01 through the installed webhook, the other three from a copy of the
  installed plugin, one file of which (`evo_webhook.php`) is read against Git as the control: each line is written
  once in its masked form, with no number in it.
- **105-6** — the rollback, `ROLLBACK` typed, with steps 1–6, 11 and 12 on and 7–9 skipped, reads PASSED. 5.18.104
  is back, file by file. No tool ran. Every key and the tool's `show` are the same. The step-12 notice is in capitals,
  its `off` command alone on its line and never run. LM-RB1's count is in capitals. Its control: a STOP after the
  rollback writes the old form again.
- **105-6v** — a deploy whose sign-in page redirects rolls itself back. Every key is kept and no `off` runs.
- **105-7** — the weakened copies of the script, each caught by the check it weakens: three `--check` copies and five
  deploy copies, among them the clear put back and the switch-off rollback called again. Then `--redeploy` over the
  live ladder reads PASSED, and LM-ON made to expect the old blocks reads FAIL.
- **105-10** — what the rehearsal left behind, over 5.18.104's records and 5.18.105's alike. No run of the script
  called Evolution. uCRM was asked GETs only. No seeded name or number is in any terminal, log, sheet or state file.
  The LK key file is 0600, and its value is in no terminal, log, sheet or state file. No record the plugin logged
  after the copy holds a number, with the four driven lines present as the positive controls.

**Reviewed three times before any run:** 13, 8 and 9 confirmed defects, each checked against the code, fixed and
rechecked. Those that change the design are root `docs/74` §S.2 item 4.

**Trials.** The development trials ran from a saved state of the chain.

- **Trial 1** (13 Nov, 17:59–19:07 UTC; the ladder not walked, its two states taken from 5.18.104's own snapshots):
  **2,680 ok, 20 failed** — the three inherited checks (98-0, 99-0 and 100-0 in 5.18.104's record) and 17 that were two
  defects of the script, T1-a and T1-b (above). Nothing else failed: each weakened copy was caught, and the drives of
  LN-01 to LN-04 passed.
- **Trial 2** (13 Nov, 19:09–20:26 UTC; the ladder walked through the real tool; the script with T1-a and T1-b fixed):
  **2,707 ok, 5 failed** — the three inherited checks and two expectations of the rehearsal, both fixed in the
  rehearsal. No check of the script failed wrongly. Its FAIL lines were the weakened copies', the planted sign-in
  fault's, and step 12's check in two `--after-only` runs, which rightly failed on rows the earlier rehearsal had
  left on the book (T2-b). The walk, 19 checks, passed whole on its first run.
  - **T2-a:** 105-4f counted the day's `list_last` rows as its own two. Once the ladder is walked, the day already
    holds the row of the walk's own list run. The two planted rows are now counted as a difference.
  - **T2-b:** step 12's check failed in `--after-only` (`ledger_unmapped`). 5.18.104's rehearsal ends with four offer
    rows it planted by hand in the send ledger, and the release's check rightly counts an offer row there that belongs
    to no recipient as a fault. Production has none. They are now removed at the cut.
- **Trial 3** (13 Nov, 20:28–21:43 UTC; the ladder walked; T2-a and T2-b fixed): **2,710 ok, 3 failed** — the three
  inherited checks alone. Every FAIL line in the script's own outputs was a weakened copy's or the planted sign-in
  fault's.
- **The record run's first start** (13 Nov, 21:45–22:05 UTC, on `0214b50`) was stopped by hand in the 5.18.95 stage, at
  380 ok and 1 failed. A run with a fourth failure cannot be the record. Its log is kept.
  - **The failure was not this release's.** It was check 3s' of 5.18.95's rehearsal: 5.18.95's own script refused a
    pin it had read correctly seconds before.
  - **The cause is the pipe hazard** (the script section, above), in the older script, which the chain runs as it
    was deployed. The shell writes a list one line at a time, the quiet `grep` stops at its first match, and a writer
    cut off mid-list fails the whole pipeline. It takes an unlucky moment, likelier on a busy machine: two review
    readers were working beside the run.
  - **Shown directly:** with the writer slowed, the pipeline reads 141 where it should read 0. Tried 8,000 times
    afterwards, idle and with the machine loaded, it did not happen again. It is rare.
- **A short trial of the corrected script** (13 Nov, 22:14–22:21 UTC, on `be8930c`; stopped by hand after 105-c):
  33 ok, 0 failed. `--check` read GO over both ladder states, with QF-G2 and VN-G7 ok and QF-N3's report printed.
- **The record run's second start** (13 Nov, 22:22–22:30 UTC, on `be8930c`) was cut by a restart of the container, at
  148 ok and 0 failed. Nothing of the run's caused it, as far as can be seen. A cut run does not count.
- **The record run's third start** (13 Nov, 22:34–23:45 UTC, on `be8930c`, nothing beside it) was cut by a second
  restart of the container, in the 5.18.98 stage, at 1,020 ok and 1 failed: the first of the three inherited checks
  (98-0). The 5.18.95 stage, where the first start had failed, passed whole.
- **The record run**, the whole chain unrestored:
  ⟦PENDING: the record run — its times, its ok and failed counts, which checks failed; or that it could not be run⟧

### Runbook

As root on the server. Each command is its own paste. After each run send back its **log file**, never a copy of the
terminal and never the cash sheet. Each log is 0600. The deploy script's runs go under `/root/dnb-5.18.105/`, the
quarantine's `--check` under `/root/dnb-stray-quarantine/`, and the switch tool's wrapper writes its own under
`/root/dnb-sales/`.

**The script changes no switch.** The steps that are on stay on, and keep running through the deploy. It turns no step
on and no step off. Make no KYC registration and no quote to try the release: the plugin WhatsApps every new quote to
its customer.

0. **What must be true first.**
   - **5.18.104 is live.** It is, since 9 Nov (copy 03:50:25 UTC). The script refuses any other live version.
   - **`release/5.18.105` (`f764699`) must be on GitHub, and it is not there yet.** Today it is local only, and it is
     pushed only on the owner's word: ⟦PENDING: the owner's word, and the date of the push⟧. The `--check` and deploy
     commands below fetch it, and their `&&` stops them if that fails. Until the push nothing can be deployed, and the
     report's wrapper stops too, having changed nothing: it is pinned to `f764699`.
   - **The development branch carries the script and both wrappers.** The `--check` and deploy commands below pull
     it. Since `be8930c` (13 Nov) it holds `scripts/deploy-5.18.105.sh` at sha256 `c028cde545e042ba…`.
     ⟦PENDING: the commit the record run ran on, and whether anything changed the script after it⟧
   - **The quarantine's `--check` has PASSED since 5.18.104's copy.** It has: 9 Nov, begun 04:39:36 UTC. Stage A reads
     that record (A14). Run it again only if A14 asks for it, which it does after any later copy or rollback; it then
     prints this command. Never run it while a deploy, an `--after-only` or a rollback is running. Its own command:

         ( umask 077 && cd /opt/dishnet && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee /root/dnb-stray-quarantine/check-$(date -u +%Y%m%dT%H%M%SZ).log )

1. **`--check`, read-only. Wise before the deploy.** It runs stage A alone: nothing is backed up, copied, deployed or
   switched. It writes only its own 0600 cash sheet and row digest. LK-G1's note names the steps that are on, and
   LM-G1 and LM-G2 read ok. It ends GO or NO-GO. A GO prints `5.18.105 (check): GO — stage A holds.` and then the deploy
   command, with a flag only where a check needed one (each flag is your explicit choice); with none, it is step 3's.
   A NO-GO changed nothing: send the log file and stop. Its own command:

       cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.105 && mkdir -p /root/dnb-5.18.105 && bash scripts/deploy-5.18.105.sh --check 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/check-$(date -u +%Y%m%dT%H%M%SZ).log )

2. **Tell every admin and the agents** that no screen, no message and no switch changes. Only the text of the logs
   does, and with it one search: a new entry can no longer be found by a full phone number in the Failed Queue's CRM
   Events search or in the Activity Log's filter. The client id, the invoice number and the name stay searchable.
   While the deploy, its `--after-only` or a rollback runs, no one runs `scripts/sales-followup.sh`, and no one uses
   the Salesperson numbers card, ECO Bank (keep no statement), Send Quotation, the KYC form, `tools/set_config.php` or
   Settings. A switch changed then fails the checks that compare before and after (LK-B1, LK-CA1). From the moment the
   deploy starts until its last line, no admin touches the uCRM catalogue screen, makes or removes a uCRM link, saves a
   plan or a kit, or changes uCRM's Products, Service Plans or client attributes.
3. **Deploy**, at a quiet hour: not between 14:55 and 15:10 UTC (the evening WhatsApp, 18:00 Kampala), and not while
   the morning list is being sent (step 4: Monday to Saturday, 08:00–11:00 Kampala; this second rule is inferred, not
   measured). It runs stage A again, then asks you to type `DEPLOY`. Its own command:

       cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.105 && mkdir -p /root/dnb-5.18.105 && bash scripts/deploy-5.18.105.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )

   Expected: the verdict line `5.18.105 (deploy): PASSED.`, with 0 failed and some notes. The rollback's block is
   printed after it; do not run it. Every ladder key reads as the run found it (LK-B1, LK-CA1). No row fell (LK-CA2).
   The masked lines are installed (LM-CA1). No migration was added. The summary names the steps that are on.
   - A refusal before `DEPLOY` changed nothing. A refusal by LK-B1, just after `DEPLOY`, copied nothing. Send the log
     file and stop.
   - A deploy whose public sign-in page redirects rolls itself back at once, every switch kept, and says so.
   - If it ends FAILED, send the log file. Do not roll back on your own unless staff or customers are affected.
   - Run it once. A second run that finds 5.18.105 serving skips the deploy and runs the after-checks.
   - The cash sheet is read on the server only (`less`, its path in the log). Never paste it, copy it or send it.
4. **`--after-only`**, at least 20 minutes after the copy (the summary prints the time). It is read-only. Its own
   command:

       cd /opt/dishnet && bash scripts/deploy-5.18.105.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/after-$(date -u +%Y%m%dT%H%M%SZ).log )

   Expected: the verdict line `5.18.105 (after): PASSED.`; the rollback's block is printed after it, and is not to be
   run. LM-ON reads the masked lines installed. LK-AO names the keys
   changed since the deploy: none, unless you turned a step on in between. Each step that is on is judged by its own
   check; a step that is off reads `OFF — not judged`. Then, on its own, the quarantine's `--check`, with the command in
   step 0. Run `--after-only` again the next day after 15:00 UTC, once the evening WhatsApp has run on the new code,
   and after each step of the ladder you turn on.
5. **Send back** the log file of each run, from the folder its command names: `check-…`, `deploy-…` and `after-…`
   under `/root/dnb-5.18.105/`, and the quarantine's under `/root/dnb-stray-quarantine/`. Never a copy of the terminal,
   and never the cash sheet.

**The ladder, after the deploy — yours alone, one step at a time, in its order.** Nothing is done again: the steps that
were on are still on, and behave as under 5.18.104. Only their log lines carry no number. The steps and their commands
are 5.18.104's runbook, above, unchanged: the tool is 5.18.104's, byte for byte. Never run it while the deploy script
runs.

- **Where it stands on 13 Nov:** steps 1 (`report`) and 2 (`ack`) on since 9 Nov; step 3 (`tracking`) on since 13 Nov,
  05:27:38 UTC; steps 4 and 5 from 14 Nov at the earliest; step 6 (`chase`) from 16 Nov at the earliest. Steps 1–6 do
  not wait for 5.18.105. Where the ladder stands, read-only:

      cd /opt/dishnet && bash scripts/sales-followup.sh show

- **Step 12, `offers-live` — only after 5.18.105's `--after-only` has PASSED.** The owner ruled on 9 Nov that step 12
  waits for 5.18.105. The design (root `docs/74` §A) makes that exact: 5.18.105 serves, and its `--after-only` has
  passed. The tool does not check which release serves: that PASSED is the check. Step 12 also needs every step before
  it on, skipped or optional, and `offers-test` on with its check at PASS, as before. Then:

      cd /opt/dishnet && bash scripts/sales-followup.sh on offers-live

  then its check:

      cd /opt/dishnet && bash scripts/sales-followup.sh --check offers-live

  Then an admin presses Start on the *WhatsApp offers* screen, as 5.18.104's runbook says, and you run `--after-only`
  again (step 4).

### Rollback — its own block, never pasted with the deploy

**Only if it is needed.** It puts 5.18.104 back and changes no switch. Do not roll back on your own unless staff or
customers are affected; send the log file first. Every deploy and `--after-only` prints the command after the verdict,
on its own. To stop one part of the sales follow-up, do not roll back: turn its step off with the switch tool, in a
command of its own, never while this script runs.

The rollback asks you to type `ROLLBACK` before it changes anything. As root, on its own:

    cd /opt/dishnet && bash scripts/deploy-5.18.105.sh --rollback 2>&1 | ( umask 077; exec tee /root/dnb-5.18.105/rollback-$(date -u +%Y%m%dT%H%M%SZ).log )

**Before `ROLLBACK` is typed** it reads the ladder as it stands (LK-RB0). **If step 12 (`offers-live`) is on, it prints
a notice in capitals.** The notice says that 5.18.104 writes phone numbers again in the 35 log lines 5.18.105 masked,
the opt-out line among them, while offers run, against the owner's ruling that step 12 waits for 5.18.105. The script
never turns the step off, and the ladder never blocks the rollback. Change no switch while the script waits. Your own
alternative, if you choose it: answer anything but `ROLLBACK` — nothing has been changed — then turn `offers-live` off
with its own command, alone, and then run the rollback again. The script prints that command and never runs it:

    cd /opt/dishnet && bash scripts/sales-followup.sh off offers-live

A chase that is on gets a plain note: its STOPs write the number again, as they did before 5.18.105.

**What it does.** It puts `994d660` back by the documented deploy, and nothing else: no switch tool, no off-hook, no
put-back of rows or keys by hand. Then it checks the result (RB105): master is 5.18.104's, with the four jobs last;
every ladder key in every place and the tool's `show` are as before, and no table's rows fell (LK-RB1); the open quote
follow-ups
and the running offers are counted, a note; the old lines are back (LM-RB1). The last line reads
`5.18.105 (rollback): PASSED.`

**What it leaves:**

- 5.18.104 serving, every file 5.18.105 changed back at 5.18.104's bytes;
- every switch as it found it: each step that is on keeps running, and open quote follow-ups and running offers carry
  on;
- 089, 090 and 091, their tables and all data: the copy never touches the data folder;
- the 43 files 5.18.105 adds, all tests, on disk and reached by nothing;
- ECO Bank and every statement kept, the uCRM catalogue, the quoting organization, the channel registry and every
  number's owner, exactly as they are.

**What comes back:** phone numbers in the 35 masked log lines. By the owner's ruling, step 12 is not switched on while
5.18.104 serves.

**After it.** Run the quarantine's `--check`, its own command, in the runbook's step 0: stage A of any later deploy
needs one begun after the rollback's copy. A later deploy of 5.18.105 goes with `--redeploy`, which the rollback's
summary prints as its own command, to run only once the cause is understood. **Only if the script cannot run**, every
deploy and `--after-only` also prints the put-back of the code by hand, one command, alone: it counts nothing and runs
no switch tool. Send the log file before doing anything by hand.

**Send back** the rollback's log file, `rollback-…` under `/root/dnb-5.18.105/`, and the quarantine check's. Never a
copy of the terminal, and never the cash sheet.

### Known limits (recorded — the release's `SAFETY.md`, `docs/74` §H.2 and §S.2)

1. **The masking holds only from the moment 5.18.105 serves.** Lines already written stay; the release changes code
   and rewrites no data. The capped logs drop old entries as new ones arrive (`webhook_log.json` keeps 300 entries,
   `quote_wa_log.json` 200 and `activity_log.json` 500; `evo_sync.log` is cut back to its last 400 lines once it
   passes 500). `invoice_notify_cron.log`, `data/plugin.log` and PHP's own log keep them until rotated. No clean-up
   tool is designed.
2. **A rollback brings the numbers back in all 37 statements.** 5.18.104's code writes them again. The script says so
   in capitals (LM-RB1); it counts them as 35 log lines, one of which covers three statements. The rollback is never
   blocked, because it is an emergency act. If step 12 is on, the script says before `ROLLBACK` is typed that offers
   would run on unmasked code, and names the operator's own way to turn step 12 off first.
3. **Only phone numbers are masked.** The ruling covers numbers only, so names, amounts and record ids stay. E-mail
   addresses are not ruled on: no line is edited for one, and they change only where a line passes through `mask()`
   (limit 6). So a line that keeps a name logs that name as it is: if a customer's name in uCRM or a KYC field holds
   a number, it is logged. The two places where the code itself puts a number in a name are both masked (the
   watchdog, and an AI lead with no name). A KYC edit (LN-34) still logs before and after for the names, the address
   and the company name.
4. **Lines left unmasked on purpose** (K-01…K-18):
   - **Lines only South Sudan runs, or that nothing runs on Uganda** (K-01, K-03…K-12). They change nothing on the
     server this release is for. K-04 is held byte for byte by 5.18.104, K-01 would break a South Sudan test that
     compares with 5.18.49, and K-11 and K-12 are in scripts the plugin does not run. Each stays on test E2's list, so
     any change to it is seen.
   - **Text that holds a number only if someone else put one there** (K-13…K-17, and the error messages beside the
     masked lines): error messages, uCRM's and URA's answers, an invoice record logged whole, the values of a failed
     database write, and error traces. No code that raises an error on these paths is known to put a number in its
     message, but that is not proven. Such text is masked only where its line was edited anyway (LN-03, LN-24).
   - **The dry-run capture** (K-02), a mode that is off by default and exists to show the whole message. Its live
     value was not read.
   - **The operator's own tools** (K-18). They print to the operator's terminal, not to a log.
5. **Stores that code reads back are not logs, and keep the number:** the Message Log (`notification_audit_log`),
   `quotes_log.json`, a KYC application's own audit record, `evo_sync_state.json` and the dry-run capture file. Code
   reads them back, so they are records, not logs, and out of scope.
6. **What `PluginLog::mask()` catches, and what it does not** (LN-03, LN-24). It replaces runs of seven or more
   characters of digits, spaces, brackets and hyphens that start and end with a digit. A number written with dots or
   slashes, or in words, passes. It also replaces e-mail-like text (limit 3), and dates and long references, which
   the design calls acceptable in `evo_sync.log` and in an error text.
7. **What the search for such lines may still have missed:** a whole record handed to a log (K-17), and a value that
   reaches a log under a name that does not say phone, beyond the cases found. From now on test E2 fails on any new
   log statement that names a phone variable.
8. **Whether some of the 35 lines ever run on Uganda is not established.** This was read from the code, not from the
   server. LN-02 runs only where SSP is bookable. LN-04 is unlikely. LN-24, LN-25, LN-26 and LN-27 are unknown: the
   live settings were not read. All are masked all the same.
9. **LN-35 is reached only by a hand-made request.** The design said an accountant reaches it by recording a refund.
   The build found that no form or script in the repository posts the field its handler reads: the panel's cashbook
   form posts another field, and the retailer app goes through the API. The line is masked all the same, and its test
   posts the form by hand.
10. **Five older faults the tests met — recorded, not changed.** Each was measured in the tests' harness, on
    `994d660`'s own files; the harness is not the server. 5.18.105 changes none of them. Each is the owner's to decide,
    for a later release. The first two are in jobs that run on Uganda.
    - **Renewal reminders are never sent.** The job's stamp (`renewal_remind_log`) is not on the store's list of flat
      tables, so it is never read back, and every run is a first-run dry run.
    - **The queued quotation PDFs are dropped.** `quote_wa_state` is not on that list either: the waiting PDFs are
      never found, and the list is saved back empty.
    - **The `cashbook_add_entry` handler answers 500 after it has done its work.** A class it needs
      (`TransactionIntegrityGuard`) is not loaded on its path. No screen posts to it (limit 9).
    - **`cron/cashbook_reconcile.php` stops before its send on a book fresh from the migrations.** It needs three
      tables the store makes on first use and a column the cashbook adds when it is first opened. A live book has them.
    - **`cron_wa_sync.php` run alone stops at every row.** It uses a class (`WaInbound`) before the file that loads
      it. Under `cron/master.php` an earlier job of the same process has loaded it.
11. **Observed in passing and not changed.** `cron_wa_sync.php` runs unless `wa_sync_enabled` is false, and the feed
    address it polls defaults to the South Sudan WASender feed. If it is on and the feed returns rows, Uganda would take
    in that feed's messages. This is inferred from the defaults; the live value was not checked.
12. **The `071_dpo_payments.sql` checksum mismatch is untouched** (the 9 Nov deploy's record, above).
13. **The switch tool does not check for 5.18.105.** The tool and its two classes are 5.18.104's, byte for byte. So
    nothing in the plugin refuses step 12 before this release serves, or after a rollback. The order is the operator's
    to keep: step 12 only once 5.18.105 is deployed and its `--after-only` has passed.
14. **The design's eight questions (`docs/74` §Q) ship with their defaults.** `docs/74` records no answer to any of
    them. Limits 1 to 4, and in the release notes what stands in the number's place and the search change, are those
    defaults.
15. **5.18.104's known limits are not re-stated here.** This release changes log text, the report's padding,
    `SAFETY.md`, the version line and tests. Three of those limits are touched:
    - **Its limit 11** (four test files fail in this container and nowhere else on record) is read again by the two
      passes: ⟦PENDING: the same seven FAIL lines, or not⟧.
    - **Its limit 12** (one wrong sentence in `SAFETY.md`, Operator action, step 3 of 5.18.103's section) is still not
      corrected. This release only adds its own section to that file.
    - **Its limit 13** (phone numbers in four older log lines) is what this release answers, once it serves.
