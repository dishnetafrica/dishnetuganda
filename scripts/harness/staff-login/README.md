# Staff-login harness — `scripts/dnb-staging-staff-login.sh` exercised end to end

Evidence for `dishnet-hybrid-sudan/docs/122` §D. It runs the **real** staging
script unchanged against:

- a **fake `docker`** (`bin/docker`) whose `dnb-staging-api` is a real
  `php -S` process serving the **release package** (built by
  `plugin/bin/package.sh`, content digest checked) with exactly the environment
  each `docker run` names;
- a **real PostgreSQL** database with the real installer, migrations 001–028
  and the simulated estate;
- a **fake Traefik** (`traefik.py`): TLS on 127.0.0.1:443, basic auth while the
  route file names it (the file is re-read per request), and a proxy to the API
  **from a chosen source address**, so the API's `REMOTE_ADDR` can be the
  gateway (scenario A) or something else (scenario B).

`scenarios.sh` covers the expected case, the one-step correction, both
proof-failure rollbacks, a doctor blocker, a failed container start, an
idempotent re-run, a failed re-run, and an unparseable bootstrap output. Then
`journey.sh` walks the operator's first sign-in through the proxy: one-time
password, authenticator enrolment, estate, password change, sign-out, replay.

**What it cannot prove:** how the real host's Docker networking presents
Traefik's connections to the API. The staging script proves that on the server
with its own sign-in attempt. Nothing here touches a real Docker daemon,
Traefik, DNS or server.

**It refuses to run on anything that could be the server** — it writes the same
paths (`/opt/dnb-staging`, `/etc/easypanel/traefik/config`,
`/root/dnb-staging-evidence`). `guard.sh` needs
`HSIM_SANDBOX=yes-this-is-not-the-server`, refuses if EasyPanel's route files
exist, if a real Docker daemon answers, or if `/opt/dnb-staging` was not created
by the harness. `scenarios.sh` proves the refusals first.

    HSIM_SANDBOX=yes-this-is-not-the-server scripts/harness/staff-login/scenarios.sh

`HSIM_SCRIPT=<path>` runs the same scenarios against another copy of the
script; `docs/122` §D records three deliberately broken copies and the
assertions each one fails.
