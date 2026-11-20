# The chain's clone and Git's short form (18 Nov, measured)

- snap1 (14:51Z) stopped in 94-0: 11 FAIL lines, every one an 8-character short commit (69c87a6f, 247a4809, f4b4c96b).
  The chain clones the trial checkout (`git clone -q --no-hardlinks "$R"`, every refs/heads of the shared repository),
  then 5.18.94's harness repacks it (`repack -a -d`, `prune-packed`). Git's automatic short form goes to 8 characters
  at 16,384 packed objects. Measured: the clone held 16,605 (all branches); HEAD alone 15,421.
- The extra came from four local-only branches holding 5.18.104's development history: reh104-rec (396 objects beyond
  HEAD + release/* + the dev branches), reh104-rec2 (402), reh104-snap (378), wip/dev-5.18.104 (402). merge104 and
  reh103-t1 add 0. Each was moved to refs/keep/branch/<name> (same commit; clone copies only refs/heads and tags) and its
  worktree detached at the same commit. Nothing deleted.
- Re-measured on a fresh clone repacked as 94's harness does: 16,239 packed, 7-character form (69c87a6 247a480 f4b4c96
  bf29cbd 7835906). snap2 started 14:54Z.
- MARGIN: 145 packed objects. Each commit on a local branch adds about 6 objects (blob, the trees above it, the commit).
  Keep commits to the dev branch few until the record run; a squash at the end does not help (the old commits stay
  reachable from the pushed branch).
- This is the real server's condition too, by object count of its own clone: deploy scripts since 5.18.94 stop at A0
  with "STOP: this checkout writes commits in a short form of 8 characters" and print `git -C <checkout> config
  core.abbrev 7`. Not a defect; tell the owner the remedy is printed if they meet it at 5.18.106's deploy.
- Durable fix (later, not now): 5.18.94's 94-0 control asserts the fresh clone writes 7 characters "as the server's
  checkout recorded 69c87a6 on 11 Oct". Once the pushed branches alone pass 16,384, every future chain fails there.

# snap3 lost, snap4 (20 Nov)

- snap3 (04:40Z) stopped at 05:14Z in 96 with 502 ok / 0 FAIL: the container restarted (diag logs 05:55 ... 08:57Z).
  Its leftovers in /tmp (3 dnb-5.18.96-*-20261120T051455Z-3233 folders, the sandbox tmp.KtQsmJB2X6, 10 cut prefixes
  tmp.*) were read and removed by literal path; nothing else in /tmp had appeared since its tmp.before.
- snap4 started about 09:05Z, t106 at 2582cb7, clean.
- snap4's 97-1 1a FAILED twice (the check and its "...names 5.18.96's file"): its run (r97/run-167) never reached A14.
  It stopped at "the pin lacks files 5.18.97 is made of: migrations/088_bank_statements.sql(added)", although the same
  run's header read the usual delta (25 files: 14 changed, 11 added), as every other 5.18.97 run in the section did.
  That check is `printf '%s\n' "$ADDED" | grep -qxF` under `set -uo pipefail` (deploy-5.18.97.sh:2574): the hazard
  docs/74 records for the 105 record run's first start (a writer cut off mid-list fails the pipeline, status 141).
  The committed 5.18.97 script is inherited and is not changed. Nothing changed in the run (its quiet97 fields equal
  the want). 1a', 1b-1g passed. Not this release's; a record run with it would carry 2 more inherited FAIL lines.
