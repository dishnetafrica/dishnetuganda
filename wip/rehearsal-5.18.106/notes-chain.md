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
