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
