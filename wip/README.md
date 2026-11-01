# wip/ — work in progress, kept here only so a container restart cannot lose it

`release-5.18.103.patch` is `git diff 1a0ea5f..<the local release/5.18.103 tip>`: the 5.18.103 release being built
(root `docs/72` §G), not yet a release. Apply with `git apply` on a checkout of `origin/release/5.18.102` (`1a0ea5f`).
It is replaced at each milestone and the folder is removed when the release branch is pushed on the operator's word.
Nothing here is deployed or deployable.
