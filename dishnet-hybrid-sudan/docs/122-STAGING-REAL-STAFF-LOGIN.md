# 122 — Real DishNet staff login on the staging estate (roadmap step 4): review, harness evidence, one-command handover

**Status:** review and harness evidence written **before** the handover,
2026-09-23. **RESULT: SWITCHED 2026-09-23 21:41:56 UTC** — every step passed on
the first attempt; the trusted-proxy address was proved without correction (§E). **Staging only:** nothing
here touches production, and nothing here is HARDWARE VERIFIED (`docs/119` is
unchanged). **No application code changed.** Everything used exists since G-B
(`docs/114` §O, migration 026) and the build on staging is the one `docs/121` §H
deployed, content digest `1bc95524…7a7af74b`.

**The instruction.** After steps 1 and 2 (`docs/121`) the operator said *"go with
your recommendation"*, then *"ok go ahead"*. The recommendation was to take
**step 4 of the roadmap before step 3**: replace the credential-less
**development identity** on the staging panel with the **real DishNet staff
login**. It is configuration, not code, and it replaces the literal actor `dev`
on every audit row with a named person. Step 3 (operator onboarding) is large and
needs the O-1 census decision first.

---

## A. What governs this

| Rule | Where | What it means here |
|---|---|---|
| Provider selection is explicit and never falls back | `docs/114` §G.8, `StaffIdentityFactory` | `DN_STAFF_IDENTITY=dishnet` binds the real provider; with the development gate beside it the process **refuses to start**. The API is recreated **without** `DN_DEV_STAFF_IDENTITY` |
| A session is issued only over TLS | `docs/114` §G.6/§H, `TransportPolicy`, `DishnetStaffIdentity::login` | behind Traefik, PHP knows TLS only from `X-Forwarded-Proto: https`, **believed only when `REMOTE_ADDR` equals an entry of `DN_TRUSTED_PROXY` exactly** — no CIDR. Otherwise every sign-in answers **403 `insecure_transport`** |
| Second factor mandatory before a public hostname | `docs/114` D-AUTH-5 | `DN_STAFF_REQUIRE_TOTP` stays **unset (required)** |
| The first administrator is created on the server, once | `docs/114` §C.2, `plugin.php staff:bootstrap` | refuses once any staff row exists; prints the generated password once and stores it nowhere |
| CSRF | `Csrf` | with `DN_PORTAL_ORIGIN` set, a mutating request that carries an `Origin` must carry exactly that one |
| The doctor is the preflight | `Doctor::identity()` | run **without** `--disposable`, which would downgrade the development-only blockers |
| G-D is *"Not production"* | `docs/114` §K | this rehearses G-D's list on **staging**: TLS proxy and trusted-proxy configuration, `staff:bootstrap`, second factor enrolled, sign-in over TLS, sign-out, revocation |

### A.1 The trusted-proxy address is PROVED, not looked up

What address Traefik's connections carry when they reach the API is a property
of this host's Docker networking. Traefik is a swarm task reaching the API
through the gateway publish `172.17.0.1:8099` (`docs/120` §15.8.1). **Expected,
not assumed:** that connection is DNAT'ed to the API container and, leaving the
swarm's gateway bridge for the `dnb-staging` bridge, masqueraded to the
`dnb-staging` bridge gateway, the same address the loopback publish presents.

**The API's log cannot answer the question.** PHP's built-in server writes no
request line for a request its router script handles — only `<addr> Accepted`
and `<addr> Closing` (php-src PHP-8.3, `sapi/cli/php_cli_server.c`,
`php_cli_server_dispatch`: a handled router request goes to
`php_cli_server_request_shutdown` without `php_cli_server_log_response`).
Measured locally: through `plugin/bin/serve.php`, `GET /app.js` logs only
*Accepted* and *Closing*; the same file served without a router logs
`[200]: GET /app.js`. So the log says which addresses connected, never which
connection was the browser's.

**So the script proves it** (step 4). It takes a first candidate from the log's
*Accepted* addresses, preferring the `dnb-staging` bridge gateway. It then signs
in through Traefik with a wrong password:

| Answer | Meaning | Action |
|---|---|---|
| **401 `invalid_credentials`** | TLS was recognised from the candidate: the attempt reached the credential check | **proved** |
| **403 `insecure_transport`**, arrived from another address | the candidate is wrong; the *Accepted* line written during the attempt names the right one | recreate the API with that address and try **once** more |
| **403**, arrived from the candidate itself | Traefik sent no `X-Forwarded-Proto: https` | roll back |
| anything else | not understood | roll back |

**The widening is measured too** (step 6). A host-local client asserting
`X-Forwarded-Proto: https` over the loopback publish either reaches the
credential check (**401**: loopback shares the trusted address, so any process
on the host can obtain a cookie over plain HTTP — with a valid password **and**
authenticator code) or does not (**403**). The run reports which. On staging
this is the same class as the stage-2 widening (`docs/120` §15.8.1), accepted
for synthetic data.

### A.2 A correction made before the handover

The first draft read the address from `GET /app.js` lines in the API's log. A.1
shows those lines are never written under a router script, so that draft would
have stopped every time with *"no browser request in the log"*. The harness in
§D found it. The script was rewritten to prove the address by the sign-in
attempt before anything was handed over.

---

## B. Decisions

| # | Decision | Why |
|---|---|---|
| **D-1** | staging only; one command; no code change | everything used exists since G-B |
| **D-2** | `DN_TRUSTED_PROXY` = a candidate from the log, **proved by the script's own sign-in attempt through Traefik** and corrected at most once (A.1) | an exact-match setting must be proved, never assumed |
| **D-3** | first administrator `dishnet-admin` (display *DishNet Administrator*), overridable with `STAFF_USER=` / `STAFF_DISPLAY=` | `staff:bootstrap` refuses a second run; the script skips it when a staff row exists |
| **D-4** | the second factor stays required | D-AUTH-5 |
| **D-5** | `DN_PORTAL_ORIGIN=https://portal-staging.dishnetuganda.com` | the documented production posture |
| **D-6** | the stop-gap basic auth leaves the Traefik route **only after** the real provider is live on loopback and the doctor reports 0 blockers; the rate limit, headers and certificate stay | basic auth existed only because the development identity had no credential (`docs/120` §6, §10) |
| **D-7** | any failed proof **rolls back both** the route file (the copy taken at step 0) and the container (its own previous identity settings, read from `docker inspect`) | the operator is left exactly where they started |
| **D-8** | the SSH-tunnel path (`http://127.0.0.1:8099`) loads the sign-in page but cannot sign in | plain HTTP is not TLS, by design |
| **D-9** | rollback to the demonstration posture = re-run the stage-2 command | `scripts/dnb-staging-stage2.sh` recreates the API with the development identity and rewrites the route with basic auth; the staff row stays, harmlessly. By construction, **not** executed in the harness (it needs a real Traefik service, DNS and certificate) |
| **D-10** | the doctor runs in production posture and must report **0 blockers** | a WARN is reported, a BLOCKER rolls back |
| **D-11** | only `dnb-staging-api` is recreated; the run asserts the container delta | the stage-1 posture |
| **D-12** | the administrator is created **after** the proof, never before | a rollback must never leave a live password behind for a login that was switched off |
| **D-13** | the password goes to the terminal (`/dev/tty`, which `tee` does not capture), or to a **0600 file** when no terminal exists; if the bootstrap output ever cannot be parsed, the **whole** output goes there instead | the password is never logged and never lost |

---

## C. The handover — one command, as root on the server

```sh
curl -fsSL -o /root/dnb-staff-login.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-staff-login.sh \
  && sh /root/dnb-staff-login.sh 2>&1 | tee /root/dnb-staging-evidence/staff-login-$(date -u +%Y%m%dT%H%M%SZ).log
```

| Step | What it does | On failure |
|---|---|---|
| 0 | read-only checks; the deployed build and its digest; the API's current identity settings (kept for the rollback); the route's shape; the candidate address; snapshots of every container and of the route file | stops; nothing changed |
| 1 | recreates `dnb-staging-api` with `DN_STAFF_IDENTITY=dishnet`, the candidate `DN_TRUSTED_PROXY`, `DN_PORTAL_ORIGIN`, **no** development identity, the same two publishes | previous container restored |
| 2 | loopback: `GET /session` → 401, `provider: dishnet`, `can_authenticate: true`, `mode: credentials`; `POST /session` over plain HTTP → **403 `insecure_transport`** | restored |
| 3 | `plugin.php doctor` in production posture; **0 blockers** (a WARN *no staff on record* is expected here) | restored |
| 4 | removes basic auth from the route (atomic replace inside the watched directory, YAML-checked where PyYAML exists); waits for Traefik to hand the session route to the API; **the proof** of A.1, with at most one correction | route **and** container restored |
| 5 | `staff:bootstrap` when no staff row exists; the sign-in box goes to the terminal only (D-13) | route and container restored |
| 6 | the widening measurement; the container delta; the result line; the rollback command | reported |

**What the operator sees afterwards** at `https://portal-staging.dishnetuganda.com/`:
no browser prompt; the panel's own sign-in card; after the first password
sign-in the panel asks to set up an authenticator and shows a **setup key**
(and an `otpauth://` line) once — there is no QR code, so the key is typed or
pasted into the app; after a code is confirmed the estate appears, and
*My account* changes the password. From then on a sign-in needs the password
**and** a code. Every write is audited to the username, not to `dev`.

---

## D. Harness evidence — the real script, run against a fake Docker and a fake Traefik

`scripts/harness/staff-login/` (outside the package). It runs
`scripts/dnb-staging-staff-login.sh` **unchanged** against: a fake `docker` whose
`dnb-staging-api` is a real `php -S` process serving the **release package at
digest `1bc95524…`** with exactly the environment each `docker run` names; a
real PostgreSQL database with the real installer, migrations 001–028 and the
simulated estate (5 routers); the stage-2 route file taken **verbatim** from
`dnb-staging-stage2.sh`; and a fake Traefik (TLS on 127.0.0.1:443, basic auth
while the route file names it, the file re-read per request) that proxies **from
a chosen source address**. It refuses to run on anything that could be the
server (four independent guards, each proved by the scenarios first).

**Result: 108 assertions, 0 failed**, twice.

| Scenario | Set-up | Asserted outcome |
|---|---|---|
| guard | no sandbox flag; EasyPanel's mail route present; foreign `/opt/dnb-staging` | each refused, exit 2 |
| **A** | Traefik from the gateway, under a real pseudo-terminal | proved on the first attempt; doctor 23 ok / 1 warn / 0 blockers; plain HTTP 403; administrator created **after** the proof; box on the terminal, **not in the log**; widening measured as applying (401); one staff row |
| **B** | Traefik from another address, no terminal | corrected once; proved from the measured address; password in a **0600 file**; widening measured as not applying (403) |
| C | Traefik sends no `X-Forwarded-Proto` | exit 1 without claiming a wrong address; **route byte-identical** to stage 2; development identity back; **no staff row**; basic auth answers 401 again; no temp file |
| C2 | the same, from another address | one correction, still refused; the same rollback |
| E | a doctor blocker | exit 1 before the route changes; the same rollback |
| F | the new container fails to start | exit 1; the same rollback |
| D | a second run after success | idempotent: provider recognised, route unchanged, bootstrap skipped |
| D3 | a re-run whose proof fails | the **real provider** is restored, not the development identity; staff row kept |
| G | a bootstrap output no parser expects | the whole output, with the password, delivered to the 0600 file; not in the log |

**The operator's first sign-in, through the fake Traefik** (after A, B and G):
one-time password → **200**, second factor pending; estate → **403
`second_factor_required`**; enrol → setup key; confirm the RFC 6238 code →
**200**; estate → **200, 5 routers**; password change → **200**; sign-out →
**204**; the old cookie replayed → **401**; the one-time password again →
**401**; the new password without a code → **401**; with the next code →
**200**. Audit: `staff.created` by `cli:root`, then `staff.login` ×2,
`staff.totp_enrolled`, `staff.password_changed`, `staff.logout`, all
`actor_kind = staff`; no password or key in any audit detail.

**Controls on the controls.** The scenarios were run against four deliberately
broken copies of the script (`HSIM_SCRIPT=`):

| Defect | Assertions that fail |
|---|---|
| the administrator created before the proof (a clean reorder) | exactly 4: A's ordering check, and *no staff row was created* in C, C2 and E |
| the sign-in box printed to the log | 25, including *the one-time password is NOT in the script's log* and the 0600-file checks |
| the route restore does nothing | 4: *route byte-identical* and *basic auth's 401 again* in C and C2 |
| the reorder with `RUN` still undefined | 67 — the script crashes; recorded because it was the first, less precise attempt at the first defect |

**What the harness cannot prove:** the address Traefik's connections carry on
the real host, Traefik's real reload timing and headers, the real certificate,
and the panel's screens in a browser (it drove the API). The script proves the
first three on the server; the operator's browser proves the last.

---

## E. Result — SWITCHED 2026-09-23 21:41:56 UTC

The operator ran the command at 21:41:49 UTC. Every step passed on the first
attempt; the script's own lines are quoted where they carry the measurement.

| Step | Measured on the server |
|---|---|
| 0 | build `dishnet-mikrotik-0.1.0-rc1`, content digest `1bc95524…7a7af74b` (the `docs/121` §H build); identity before: the development identity only; `dnb-staging` bridge gateway `172.22.0.1`; route in the stage-2 shape; **all 71 connections in the API's log came from `172.22.0.1`**, so that was the candidate; staff rows 0 |
| 1 | API up at once with `DN_STAFF_IDENTITY=dishnet`, `DN_TRUSTED_PROXY=172.22.0.1`, `DN_PORTAL_ORIGIN=https://portal-staging.dishnetuganda.com`, second factor required |
| 2 | `GET /session` → 401 `provider: dishnet`, `can_authenticate: true`, `mode: credentials`, `second_factor: required`; `POST /session` over plain HTTP → **403 `insecure_transport`** |
| 3 | doctor, production posture: **24 checks, 23 ok, 1 warn (no staff yet, expected), 0 blockers**; PHP 8.3.33; PostgreSQL 16.15; the cluster rejects a wrong password; the burned credentials are dead on all six roles |
| 4 | basic auth removed (YAML checked); Traefik handed the session route to the API after **2 s**; the wrong-password sign-in through Traefik → **401 `invalid_credentials`, arriving from `172.22.0.1` — PROVED on the first attempt, no correction** |
| 5 | `dishnet-admin` created (id `4db5eaf3…`, actor `cli:root`); the password went to the terminal and is **not** in the log |
| 6 | **the widening applies** (the loopback probe reached the credential check, 401); only `dnb-staging-api` changed; staff rows 1 |

**A.1's expectation is now a measurement:** Traefik's connections reach the API
from the `dnb-staging` bridge gateway, the same address as every connection
through either publish.

### E.1 The widening, measured — and what production needs instead

On this host any client that reaches either publish — a process on the host
through `127.0.0.1:8099`, or any container through `172.17.0.1:8099` — arrives
as `172.22.0.1`. Such a client can assert `X-Forwarded-Proto: https` and receive
a session cookie over plain HTTP, though only with a valid password **and** an
authenticator code. **Accepted for staging**, where the data is synthetic.

> **Binding on G-E (production):** the address named in `DN_TRUSTED_PROXY` must
> be reachable by the TLS proxy **alone**. A bridge gateway shared by every
> published-port connection does not meet that. How to meet it — the API on the
> proxy's own network, or a publish no other client can reach — is a G-E design
> decision, not taken here.

### E.2 The one-time password came back in the chat

The operator pasted the **terminal**, and the terminal showed the sign-in box, so
the one-time password is in the chat transcript. The script kept it out of its
log, which is all a script controls. Consequences and handling:

- It stops being useful at the operator's **first authenticator enrolment**
  (from then on every sign-in needs a code) and stops working at the **first
  password change** (proved in the harness: *the one-time password no longer
  signs in → 401*). Advised: sign in, enrol, change the password, now.
- Until that enrolment, anyone holding it could sign in and enrol **their** own
  authenticator first. If the operator's own sign-in is ever refused, the
  account is to be treated as taken, and recovered on the server.
- **This is the second time.** Stage 2's basic-auth password came back the same
  way (`docs/120` §15.8.6). *"Paste this whole output back"* is read as *copy the
  terminal*. **Lesson, binding on every later handover script that shows a
  secret:** deliver it where a copy of the terminal cannot carry it — a 0600
  file the operator reads separately — or pause, then clear the screen and its
  scrollback before the result block; and ask for the **log file**, not the
  terminal.

Recorded **without** the password. Nothing in the repository carries it.
