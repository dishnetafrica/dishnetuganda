# Redeploy harness — `scripts/dnb-staging-redeploy.sh` rehearsed end to end

Evidence for `dishnet-hybrid-sudan/docs/124` §F. It runs the **real** redeploy
script unchanged against a **fake `docker`** (`bin/docker`, extended from the
staff-login harness's stub with `restart`, the worker's environment and log, and
an `exec` that honours the role asked for). Every database the script touches
is **real PostgreSQL** on the local cluster, and the build it fetches comes from
a bare clone of this repository's **committed** branch — commit first, or the
digest will not match.

Each scenario starts from the staging state of 2026-09-23 22:08 UTC, rebuilt
from nothing: the 028 build reproduced from commit `9f95353` and checked against
its digest `1bc95524…`, the simulated estate at ledger 28 (the staff-login
harness's `reset.sh`), and the API switched to the real DishNet staff login by
the **real** `scripts/dnb-staging-staff-login.sh`.

Scenarios: the staging state now; a second run; a wrong digest pinned; a
cross-operator site before the run (GATE 1 must stop it); a violating row that
appears **after** the census (029 must refuse by itself and the previous tree
must be put back); the stage-2 posture with the development identity. Then
three broken copies of the script, each of which must be caught.

It shares the staff-login harness's guard, so it **refuses to run anywhere that
could be the server**, and it stops the sandbox API and fake Traefik when it
exits, so nothing is left holding ports 8099 and 443.

    HSIM_SANDBOX=yes-this-is-not-the-server bash scripts/harness/redeploy/scenarios.sh
