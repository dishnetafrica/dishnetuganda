# deploy-5.18.106.sh — fix round 2 (18 Nov)

Review 2 (`review2-findings.txt`) confirmed every round-1 fix. It also found eleven new findings, all REAL and all minor:
each one is a printed text or a branch that says the wrong thing. None changes a verdict, a write or a refusal.

`fix2.py` applies the fixes as asserted edits to partR, partUJ, partH and partZ. Each edit checks its anchor's count,
the count of its replacement afterwards, and that the anchor is gone. It writes nothing unless every edit holds.

**Result:**
- 277 edits, unchanged.
- 16,191 lines.
- sha256 `8e28081e973a2d8306af2c3f94545c1471112469f354eb07a78ebf2a1aff0b84`.
- Two runs, byte-identical.
- `bash -n` is clean.
- Every partZ rule holds, and each weakened copy is refused by its own rule alone. That includes the seven new
  copies listed below.

## The findings and what changed

| Finding | Part | Change |
|---|---|---|
| RB2-wc | R, Z | The RB note's `written` and `written-check:*` share no arm any more. A write whose read-back did not hold says "whether anything else moved is not shown: AS-RB1's FAIL above names what differed", and never "nothing else moved". Z23 pins both arms. New copy: the two arms merged again. |
| RB4-sib | R | AS-RB2's `not-written` note now says "your own command, where its cause allows one, is printed …". This matches AS-RB1's FAIL and §L.7. For `code` and `file` there is no command. |
| L84-V1 | R, Z | `as_rb_last` also reads this deploy's own state file, where a V1 rollback records itself. `as_back_on` returns before anything else when this state file carries `AS_RB1` (a V1 of this deploy, its copy made or not). Z25 pins both. New copies: each one removed. |
| UJ2-G3, UJ-2-limit-text | UJ, Z | The age limit differing is kept apart (`lim`). Stage A's gate failing gives the stage-A note, and its dead "— or … set back" tail is gone. When stage A's gate held and only the limit is another now, there is a note of its own: set the limit back, wait for a close pass, then run `--after-only` again; or roll back and `--redeploy` to keep the new limit. Z20 pins the order and allows "cannot judge F3 for this deploy" once. New copies: the limit folded back into `ga`; the limit-only note saying it cannot be judged. |
| L8-1-pt1-unbuilt, FX1-1, L8-1-residual | UJ, Z | `uj_u4` now prints a capitals note when stage A's reading keeps UJ-ON from judging F3 (StaffJobsGate false, or the age limit not a positive number; followup_enabled off is FE-G1's, which stops). It is printed before DEPLOY is asked, in a deploy and in `--check`. "ANSWER ANYTHING BUT DEPLOY BELOW" is printed only where DEPLOY is asked. It stays a note (DL2). Z27 pins it. New copy: the line removed. |
| FX1-2 | H | Check it's else branch ends the rollback clause with a full stop, then says "This --after-only still reads the closes, its own command, alone:". |
| FX1-3 | H | The rollback summary's "deploy again" now gives the order: every claim switch off first, THEN followup_enabled on, each its own command, never the reverse, with §F's hazard. Then `--redeploy`. partH asserts that order and that the block prints no switch command. |
| DOC-L8 | docs/75 | Docs only. It is the docs/75 §L addendum, committed with the script (§L.4, §L.5, §L.8, §L.9 DL11, §L.10). |

## Stub runs (bash, no PHP; the functions are taken from the derived script)

- **`uj_u4`**
  - ug=0 and hours=misread, each alone and both together: the capitals note, naming the cause.
  - en=on, ug=1, hours=168: no note.
  - en=off: no note (FE-G1 stops).
  - `CHECK_ONLY` set: the DEPLOY clause is left out.
- **`uj_on`**
  - The limit alone differing (168 → 336): the new note.
  - Stage A failing, with the limit differing: the stage-A note, naming both, with no "set back" tail.
  - Stage A failing alone: the stage-A note.
  - A condition failing now: the "now" note, carrying the limit.
  - The whole gate holding: on to the verdict.
- **The RB note**, for each AS_RB1 value:
  - `written`: "nothing else moved but …".
  - `written-check:row-others`: "whether anything else moved is not shown".
  - `unknown`, `off`, `not-written` and empty: as before.
- **`as_rb_last` / `as_back_on`**, with a typed R1 written before the copy:
  - With nothing else: the offer is made.
  - With a V1 of this deploy, with or without its copy: no offer.
  - With a typed rollback after the copy: no offer.
  - Outside `--after-only`: no offer.
- **`as_rb2`**: the not-written note carries the new wording in rollback and V1 modes.

## Not done here, deliberately

- **Whether FE-G1 should also refuse on ug=0 or a misread limit.** That is a widening of DL11, and the owner's call.
  The capitals note informs; it does not decide.
- **Whether §F should withhold step 2 after a UJ-ON gate note.** That would overrule §L.5 and §L.8 in DL3's favour.
  It is recorded for the docs/75 addendum.
- **The optional `--check` GO clause and the optional check-it clause.** They are not needed once U4's note prints
  before DEPLOY and UJ-ON's limit-only note names its way out.

## Round 3 — the re-check of round 2 (`recheck2-findings.txt`, `fix3.py`)

Two read-only reviewers re-ran round 2. They confirmed every fix, and found four more, all minor. Three of them were
introduced by round 2.

| Finding | Part | Change |
|---|---|---|
| R2-V1OFF | R, Z | Round 2's guard returned on *any* `AS_RB1` in this deploy's state file. That hid the typed rollback's offer when V1 wrote nothing because the key was already off. `as_back_on` now returns only on `written`, `written-check:*` or `unknown:*`, that is, when this deploy's V1 wrote the key or may have. `as_rb_last` reads only the kept and rollback records again: a V1 that wrote nothing set nothing off. Z25 re-pinned. New copies: the loop reading `$STATE`; the guard returning on any `AS_RB1`. |
| Z20-RET | Z | Z20 now pins each gate note's `return 0` and the limit note's close-pass clause. Z27 pins U4's DEPLOY clause by mode. New copies: each note falling through to the verdict; the close pass dropped; the DEPLOY clause made unconditional. |
| UJ2-G3-r | UJ, H, Z | `uj_on` records which gate note it gave (`UJ_ON_GATE`: `now`, `limit`). The summary's check it then says when the next run judges F3, and no longer promises NOT YET where the run reads PASSED. New copies: the flag not set; check it promising a verdict regardless of the flag. |
| UJ-AO-CLOSES | H | Check it's else branch now says what the run reads: U1–U3 and the sender's closes and sends (UJ-CA). A close pass's own closes are counted only by a judged UJ-ON. |

**Result:**
- 277 edits.
- 16,196 lines.
- sha256 `c2a3d13d175b4fd848fc0b6aae8ca3c3a602dac4e219013a34e064fd6e20bf9d`.
- Two runs, byte-identical.
- `bash -n` is clean.
- Every partZ rule holds, and each copy is refused by its own rule alone.

**Stub runs:**
- The offer is made with no V1.
- The offer is made with V1 `off`, its copy recorded or not; it names the typed rollback.
- No offer with V1 `written`, `written-check` or `unknown`.
- `UJ_ON_GATE` is `limit` and `now` for those notes, and empty for the stage-A note.
- Check it prints each of its three texts.

## Round 4 — the re-check of round 3 (`recheck3-findings.txt`, `fix4.py`)

The re-check confirmed all of round 3. It found the docs/75 addendum contradicted by nothing in the script, and five
more minor findings:

| Finding | Part | Change |
|---|---|---|
| R3-NOW-CP, CHK-WAIT | UJ, H, Z | Once the gate holds again, or the limit is set back, the next `--after-only` must wait for a close pass made under it. A run before that judges F3 by passes made under the other setting, and can read FAIL: master stamps `followup_close`'s `last_run` even when the job returns at once. The 'now' note and both of check it's gate arms now say so. Check it no longer claims "until then it gives that note". |
| R3-V1NW | R, Z | `as_back_on` also returns when this deploy's V1 read the key on (`not-written:*` but `owner-unknown` and `unread`). The key then was not the earlier rollback's doing. The guard is an allow-list: only no V1, `off`, `not-written:owner-unknown` and `not-written:unread` go on to the earlier rollback's record. |
| R3-PIN-ARMS | Z, H | Z27 pins check it's three arms and its else text whole. partH asserts each. New copies: the 'now' arm removed; the limit arm promising NOT YET; the 'now' arm without its close pass; the else text saying it reads the closes. Z20 has a new copy: the 'now' note without its close pass. Z25 has a new copy: a V1 that read it on let through. |
| CITE-LAO | H | Both "may be on once its --after-only has PASSED" lines cite `root docs/75 §L.3, §L.8, DL3` (with `§F` on the first). |

Recorded, not built, because it matches §L.8 as written: a typed rollback that found the key already off is the
latest record, and it hides an earlier typed rollback's `written`. In that case no offer is made.

**Result:**
- 277 edits.
- 16,197 lines.
- sha256 `8b2bafb2c2771225c13c2c286cb499c6370ba61064c9e63f8e7dc4c44423aa3d`.
- Two runs, byte-identical.
- `bash -n` is clean.

**Stub runs:**
- The offer is made for no V1, `off`, `owner-unknown` and `unread`.
- No offer for `differ`, `refused`, `written`, `written-check` and `unknown`.
- The 'now' note carries the wait.
- Check it prints each arm.

## Round 5 — the re-check of round 4 (`recheck4-findings.txt`, `fix5.py`)

The re-check confirmed all five round-4 fixes. All 66 weakened copies are refused by their own rule alone. The
citations are true against the addendum.

It found one more, minor: **R4-NOW-STAGEA**. Where stage A's gate also failed, the 'now' note promised a judgement
once the gate held again and a close pass had run. That cannot happen for this deploy, and the same run's check it
said so. The note's wait clause is now conditional:
- With stage A's gate held, or stage A unread, the note carries the wait.
- With stage A's gate failed too, it says "Stage A's gate did not hold either, so UJ-ON will not judge F3 for this
  deploy whatever is put right now (a judged UJ-ON needs a rollback and then a --redeploy)".

Z20 re-pinned, with a new copy: the wait promised whatever stage A read. docs/75 §L.5 says the same (`doc75.py`).

**Result:**
- 277 edits.
- 16,197 lines.
- sha256 `3189cee22818fb3a7650d510fcb05614cc3da27ed8bd5c3e4c3453d7f258967d`.
- Two runs, byte-identical.
- `bash -n` is clean.

**Stub runs:** en off now with stage A held, and stage A unread: the wait. ug=0, and a misread limit, at stage A and
now: "will not judge F3 for this deploy".

The reviewer recorded two observations, not built:
- The CITE-LAO citations are pinned by partH's must list, not by partZ.
- An `AS_RB1` that could not be recorded reads as no V1. This predates round 4.
