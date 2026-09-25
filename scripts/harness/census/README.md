# Census harness — `scripts/dnb-staging-census.sh` exercised end to end

Evidence for `dishnet-hybrid-sudan/docs/123` §D. It runs the **real** census
script unchanged against a **fake `docker`** (`bin/docker`) that describes a
server built of files: staging's three containers, the Phase-0 RADIUS
PostgreSQL, UISP's PostgreSQL, other services whose settings carry secrets, and
two swarm services. Each fake PostgreSQL container is mapped onto **real
databases of the local cluster**, so every catalog query and both census runs
execute for real; the staging database is the one the staff-login harness
builds (migrations 001–028 and the simulated estate).

The stub **refuses a catalog query that does not run in a read-only session**,
and anything the script does not call fails loudly.

Scenarios: the expected server; a Domain-B container outside staging with a
password inside its DSN; a swarm service carrying `DNB_DSN`; the Domain-B ledger
in a database outside staging; a PostgreSQL container that cannot be opened; a
database that is listed but cannot be opened; an O-1 violation in staging; a
census file that is not the reviewed one; an unreachable staging database.
Every planted secret is searched for in the output.

It shares the staff-login harness's guard, so it **refuses to run anywhere that
could be the server**.

    HSIM_SANDBOX=yes-this-is-not-the-server scripts/harness/census/scenarios.sh

`HSIM_CSCRIPT=<path>` runs the scenarios against another copy of the script;
`docs/123` §D records four deliberately broken copies and what each fails.
