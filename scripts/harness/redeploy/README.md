# Redeploy harness — `scripts/dnb-staging-redeploy.sh` rehearsed end to end

Evidence for `dishnet-hybrid-sudan/docs/125` §E.1, the command that applies
migration 030. It runs the **real** redeploy script unchanged, against a **fake
`docker`**. The fake is `bin/docker`, extended from the staff-login harness's stub
with `restart`, the worker's environment and log, an `exec` that honours the role
asked for, and two fault-injection hooks: SQL planted just before the installer
runs, and just after it commits. Every database the script touches is **real
PostgreSQL** on the local cluster. The build it fetches comes from a bare clone of
this repository's **committed** history, so the pinned commit must be committed
first, or the digest will not match.

Each scenario starts from the staging state **now**, rebuilt from nothing:

1. the 028 build, reproduced from commit `9f95353` and checked against its digest
   `1bc95524…`;
2. the simulated estate, from the staff-login harness's `reset.sh`;
3. the API switched to the real DishNet staff login by the **real**
   `scripts/dnb-staging-staff-login.sh`;
4. migration 029 applied by the **real 029 command**, byte for byte the one the
   operator ran on 2026-09-24. It is read from commit `938c002` and checked
   against sha256 `c558e26a…`.

Scenarios:

- the staging state now;
- a second run;
- 029 not applied, where the command must refuse in step 0;
- a wrong digest pinned;
- a default privilege planted before the installer, which 030 must refuse by
  itself while the previous tree is put back;
- a role membership planted after the installer, which the catalogue cannot see
  and the execution test must find;
- a direct grant on the store planted after the installer;
- the stage-2 posture, with the development identity.

Then five broken copies of the script, each of which must be caught.

It shares the staff-login harness's guard, so it **refuses to run anywhere that
could be the server**. When it exits it stops the sandbox API and fake Traefik,
so nothing is left holding ports 8099 and 443. It also revokes the one role
membership a scenario plants: roles are cluster-wide, and that grant must never
outlive the run.

    HSIM_SANDBOX=yes-this-is-not-the-server bash scripts/harness/redeploy/scenarios.sh

The rehearsal of the 029 command (`docs/124` §F, 69/69), the harness's first
use, stays at commit `938c002`.
