"""docs/75 §L addendum for the two reviews of the derived deploy-5.18.106.sh (DOC-L8; DL11, FE-G1, L8-4, review 2).

Usage: python3 -I -B doc75.py REPO   — asserted edits; writes nothing unless every anchor is found exactly once.
"""
import glob
import os
import sys

R = sys.argv[1]
fs = glob.glob(os.path.join(R, "docs", "75-*.md"))
if len(fs) != 1:
    raise SystemExit(f"DOC75: {len(fs)} files match docs/75-*.md")
P = fs[0]
t = open(P, encoding="utf-8").read()


def rep(old, new):
    global t
    c = t.count(old)
    if c != 1:
        raise SystemExit(f"DOC75: anchor found {c} times: {old[:100]!r}")
    t = t.replace(old, new)
    if t.count(new) != 1:
        raise SystemExit(f"DOC75: replacement not found once: {new[:100]!r}")


# §L.4 — FE-G1 is the one refusal; U4's capitals note
rep("**`--check` prints them and writes nothing**. None refuses. It never calls",
    "**`--check` prints them and writes nothing**. None refuses but one, **FE-G1 (DL11, added 18 Nov)**: when U4 reads\n"
    "`followup_enabled` off as the jobs read it (a whole reading), stage A — and so `--check` — stops with NO-GO and\n"
    "changes nothing, printing the owner's `set_config --key followup_enabled --value 1` command alone on its line, never\n"
    "run; a U4 that could not be read refuses nothing. It never calls")
rep("  active: a limit that is not a positive number disables them), the ledger's mode, and `StaffJobsGate::applies()` (if\n"
    "  false, F1–F5 are inert, said in capitals).\n",
    "  active: a limit that is not a positive number disables them), the ledger's mode, and `StaffJobsGate::applies()` (if\n"
    "  false, F1–F5 are inert, said in capitals). *(18 Nov, review 2)* Where stage A's reading keeps UJ-ON from judging F3\n"
    "  for the life of the deploy — `StaffJobsGate::applies()` false, or the age limit not a positive number — U4 says so\n"
    "  in capitals before `DEPLOY` is asked, with the way out (do not deploy on this reading; put it right and run it\n"
    "  again): a note, never a refusal (DL2). `followup_enabled` off is FE-G1's.\n")

# §L.5 — UJ-ON's three gate notes
rep("    positive number and the same at both. A condition measured false at stage A or now: a note naming the condition that\n"
    "    did not hold, which does not hold back PASSED.",
    "    positive number and the same at both. A condition measured false at stage A or now: a note naming the condition that\n"
    "    did not hold, which does not hold back PASSED. *(18 Nov, reviews 1 and 2)* Three notes, by where it failed:\n"
    "    **false now** — F3 has nothing to do, or another limit to do it by; once it holds again, the next `--after-only`\n"
    "    run after a close pass has run under it judges F3 (a run before that pass judges by passes made while it did not\n"
    "    hold, and can read FAIL) — unless stage A's gate failed too, when this deploy is not judged (next); **false at\n"
    "    stage A** — F3 may run now, but\n"
    "    stage A's reading is fixed in the state file for the life of the deploy, so UJ-ON cannot judge F3 for it, and a\n"
    "    judged UJ-ON needs a rollback and then a `--redeploy`; **stage A's gate held and only the age limit differs now** —\n"
    "    not judged on this run: with the limit set back to stage A's and a close pass run after that, the next\n"
    "    `--after-only` judges F3 (a run before that pass can read FAIL; or a rollback and a `--redeploy`, to keep the new\n"
    "    limit). The summary's *check it*\n"
    "    follows the note this run gave.")

# §L.8 — followup_enabled on, in its order; the offer after any deploy that followed the latest rollback
rep("- **The three claim switches off** (CS-G1 refuses otherwise).\n",
    "- **The three claim switches off** (CS-G1 refuses otherwise).\n"
    "- **`followup_enabled` on** (FE-G1 refuses otherwise; DL11, 18 Nov). Under the interim of 18 Nov it is off and step 6\n"
    "  on: before the deploy, **step 6 off first** (and steps 11 and 12 if on), **THEN `followup_enabled` on** — each its\n"
    "  own command, alone, never the reverse: under 5.18.105, `followup_enabled` on while a claim switch is on is §F's hazard\n"
    "  (claim mode closes the refused drafts after three tries each, then sends the stale backlog). A rollback's summary\n"
    "  says the same before *deploy again*.\n")
rep("- **After a rollback, `followup_auto_send` stays off.** A later PASSED `--after-only` of a `--redeploy`, finding it off,\n"
    "  reads the latest rollback record — the newest `RB_COPY_AT` among `rollback-state-*.env` and the kept\n"
    "  `state-*.env.prev-*` files, read as A14 reads `COPY_AT` and `RB_COPY_AT` (S105:11878-11889) — and only when that record\n"
    "  carries `AS_RB1=written` says so and prints the owner's own command to turn it on (`--value 1`, the form above), alone,\n"
    "  as the owner's choice.\n",
    "- **After a rollback, `followup_auto_send` stays off.** The PASSED `--after-only` of a deploy that followed the latest\n"
    "  rollback — this state file's `COPY_AT` later than that rollback's `RB_COPY_AT`, `--redeploy` typed or not *(18 Nov,\n"
    "  review 1: L8-4)* — finding it off, reads the latest rollback record — the newest `RB_COPY_AT` among\n"
    "  `rollback-state-*.env` and the kept `state-*.env.prev-*` files, read as A14 reads `COPY_AT` and `RB_COPY_AT`\n"
    "  (S105:11878-11889) — and only when that record\n"
    "  carries `AS_RB1=written` says so and prints the owner's own command to turn it on (`--value 1`, the form above), alone,\n"
    "  as the owner's choice. *(18 Nov, review 2 and its re-checks)* A V1 rollback of this deploy that wrote\n"
    "  `followup_auto_send`, may have, or read it on (any `AS_RB1` in this deploy's state file but `off`,\n"
    "  `not-written:owner-unknown` or `not-written:unread`, its copy made or not) offers nothing: its own stop said what\n"
    "  it left, and a key it read on was not the earlier rollback's doing. One that read it off, or could not read it,\n"
    "  set nothing off, so the latest earlier rollback's record decides.\n")

# §L.9 — DL2 except FE-G1; DL11; the two questions left to the owner
rep("| DL2 | the readings of §L.4 are notes, never a refusal | §I calls them a report; they are the record of the state before the permanent closes |\n",
    "| DL2 | the readings of §L.4 are notes, never a refusal — except FE-G1 (DL11) | §I calls them a report; they are the record of the state before the permanent closes |\n")
rep("| DL10 | ME-CA1's NOT ESTABLISHED is a note in every mode | where master's output goes depends on the host; a check that can never be established must not block |\n",
    "| DL10 | ME-CA1's NOT ESTABLISHED is a note in every mode | where master's output goes depends on the host; a check that can never be established must not block |\n"
    "| DL11 | *(18 Nov)* FE-G1: stage A refuses — NO-GO, nothing changed — when U4 reads `followup_enabled` off as the jobs read it; it prints the owner's `--value 1` command, alone, never run; a U4 that could not be read refuses nothing. Before the deploy, step 6 off first, THEN `followup_enabled` on | UJ-ON judges F3 by stage A's reading for the life of the deploy (`UJ_A`), so a deploy taken with it off could never prove F3; the interim of 18 Nov turns it off while step 6 runs, and the reverse order is §F's hazard under 5.18.105 |\n"
    "\n"
    "Left to the owner (18 Nov, review 2), and not built: whether FE-G1 should also refuse when `StaffJobsGate::applies()`\n"
    "is false or the age limit is not a positive number (today U4's capitals note says so before `DEPLOY`); and whether §F's\n"
    "steps should be withheld after a UJ-ON gate note (today a gate note never holds back PASSED, §L.5).\n")

# §L.10 — the rehearsal's new cases
rep("  printing the full command in its by-hand paragraph; and a run whose age limit at stage A differs from the one now\n"
    "  (UJ-ON's gate note, then PASSED).\n",
    "  printing the full command in its by-hand paragraph; and a run whose age limit at stage A differs from the one now\n"
    "  (UJ-ON's limit-only note, then PASSED; judged once the limit is set back and a close pass has run);\n"
    "- *(18 Nov)* **FE-G1 (DL11)**: `followup_enabled` off at stage A — NO-GO, nothing changed, its command alone on its\n"
    "  line; run as printed, then GO; U4 unreadable — no refusal. **A plain deploy after a rollback** (no `--redeploy`),\n"
    "  whose PASSED `--after-only` prints the turn-back-on command; **a V1 that wrote, or read it on, and whose put-back\n"
    "  failed**, its `--after-only` offering nothing; **a V1 that found it already off after a typed rollback**, its `--after-only`\n"
    "  offering the typed rollback's command. **U4's capitals note** on a book whose age limit is not a positive number,\n"
    "  before `DEPLOY`, and in `--check` without the DEPLOY clause. **UJ-ON's notes and the summary's *check it***: the\n"
    "  limit alone differing, then set back (judged after a close pass, and a run before that pass); a gate condition\n"
    "  false now, then holding again (the same); each with *check it* saying the same.\n")

open(P, "w", encoding="utf-8").write(t)
print(f"DOC75 wrote {P}")
