# wip/ — work in progress, kept here only so a container restart cannot lose it

`release-5.18.103.patch` is `git diff 1a0ea5f..<the local release/5.18.103 tip>`: the 5.18.103 release being built
(root `docs/72` §G), not yet a release. Apply with `git apply` on a checkout of `origin/release/5.18.102` (`1a0ea5f`).
It is replaced at each milestone and the folder is removed when the release branch is pushed on the operator's word.
Nothing here is deployed or deployable.

`release-5.18.104.patch` is `git diff 221c9dc..994d660`: the 5.18.104 release (root `docs/73`), squashed on
`origin/release/5.18.103` (`221c9dc`) as the local commit `994d660`, under its release suite, not yet a release. Apply with
`git apply` on a checkout of `221c9dc`. Kept here only so a container restart cannot lose it; the release branch is
pushed on the operator's word alone. Nothing here is deployed or deployable.

`derive-5.18.103/` derives `scripts/deploy-5.18.103.sh` from `scripts/deploy-5.18.102.sh` by asserted text edits
(each part asserts its anchor, its count, its replacements and its result): `python3 -I derive103.py REPO RELEASE_REPO
PIN_FULL OUT`, with RELEASE_REPO a checkout of release/5.18.103 at PIN_FULL. NOT for use yet: the pin is a work-in-progress
commit, the script is unrehearsed and under review; it is re-derived on the final release commit.
`scripts/deploy-5.18.103.sh` is that derivation's current output, committed so the rehearsal being written can read it:
NOT for use — it pins a work-in-progress commit that is not cut on 1a0ea5f, so its stage A refuses every run (A0), and
release/5.18.103 is not on origin.

`tests-5.18.103/` holds three tools used on the release's copy of `tests/test_quote_from_ucrm.php`, none of them part
of the release. `plantable_qfu.php TREE` reads every weakened copy and says which cannot be planted on TREE (each
anchor exactly once, applied in order as the runner applies them); it writes nothing. `reanchor_qfu.py TREE` rewrites
the 20 copies whose code 5.18.103 moved (19) or that 5.18.102's round 11 had already left unplantable (ML79's refusal
copy) so each plants the same fault where the code now lives; every edit asserts its anchor, count and result.
`remaining_qfu.php TREE LOG` prints, as one anchored --mutants regex, the copies a log does not show caught.
