# Installing the DishNet MikroTik control plane

This is **Domain B**. It owns its own PostgreSQL database, its own roles, its
own HTTP surface and its own identity model. It is **not** a UCRM plugin: it
reads and writes nothing belonging to UCRM, UISP or Starlink, and installing it
changes none of them.

## What this release can and cannot do

| | |
|---|---|
| Serve the Admin panel against a simulated estate | **yes** |
| Read real Domain-B data once you put some there | **yes** |
| Authenticate DishNet staff | **yes, when you bind it** — see *The identity gate* below. Off by default; needs TLS in front of PHP |
| Register a router, assign it to an operator, record its lifecycle and queue its configuration over the Admin API — and from the panel's forms | **yes** (G-C, `docs/118`; migration 028, `docs/121`): `POST /api/v1/admin/routers`, `…/routers/{id}/assign`, `…/routers/{id}/state`, `…/routers/{id}/actions` (`push_config`) — every one audits the signed-in staff member as the actor; the action queues an intent that only the worker delivers |
| Create an operator, start its HotSpot service and add its locations over the Admin API — and from the panel's *Operators & sites* screens | **yes** (migration 030, `docs/125`): `POST /api/v1/admin/customers`, `…/customers/{id}/services`, `POST /api/v1/admin/sites` — each idempotent (an `idempotency_key` is required; a repeat answers 200 and records nothing) and audited with the signed-in staff member as the actor; a location's operator is read from its service, never sent. Plans, voucher batches and disconnect still answer 501 |
| Add an operator's owner — the person who will sign in for it — over the Admin API and from the operator's page | **yes** (`docs/126`, no migration): `POST /api/v1/admin/customers/{id}/principals` — a name and the phone number they sign in with, stored in one international form; a number already in use is refused as *phone unavailable*; the number is never returned. They sign in through the operator app (§7b) with a code the worker sends by SMS (§8) — **until an SMS sender is set, no code reaches anyone** |
| Send an operator's sign-in code by SMS | **yes, when you configure it** (migration 032, `docs/127` phase 2; migration 033, `docs/128`): the worker sends through Africa's Talking once an Admin enters the account in the panel (*Administration → SMS for sign-in*), or when the worker's own environment sets `DN_SMS=africastalking`, which wins — see *Sign-in codes by SMS* below. Off by default: nothing is sent until one of the two is set. The adapter is DOCUMENTED, UNVERIFIED until your account sends a real message |
| Serve the operator app — an operator's people sign in, issue and revoke vouchers, create and retire plans | **yes** (`docs/127` phase 3): `plugin/bin/serve-app.php`, **its own process on its own host name** — see §7b. Access points, billing and support say *not available yet*. **Guests cannot use the vouchers yet**: the Wi-Fi login that accepts them is not built |
| Reach a real MikroTik router | **no** — F6-B is not authorized. The worker's delivery binding (`DN_DELIVERY`) is `null` unless you say otherwise, `simulated` is an in-memory router that says so, and `routeros` refuses to start without the gate. **Nothing is HARDWARE VERIFIED** |
| Publish a RADIUS credential | **no** |
| Redeem a voucher | **no** |

## Requirements

- PHP **8.1+** with `pdo_pgsql`, `json`, `openssl`
- PostgreSQL **14+**, **preferably an instance of its own** — see *Why its own
  instance*. Migration 026 creates the `pgcrypto` extension (shipped with
  PostgreSQL, trusted, created by the non-superuser owner — no superuser step)
- a superuser on that instance, for step 2 only
- a directory the account that serves HTTP can read

No Redis. No Docker. No writable runtime directory. Nothing is written to disk
at runtime.

## Why its own instance

PostgreSQL roles belong to the **cluster**, not the database. This plugin
creates fifteen (seven login, eight definer). Installing onto the cluster that
serves UCRM would add fifteen roles visible there — granted nothing in it, but
present — and uninstalling would drop them cluster-wide.

If you must share a cluster, know that going in, and do not run the uninstall
while anything else depends on those role names.

## 1. Unpack and verify

```sh
tar -xzf dishnet-mikrotik-<version>.tar.gz -C /opt
cd /opt/dishnet-mikrotik-<version>
sha256sum -c SHA256SUMS          # must print no FAILED lines
```

## 2. Create the database and its owner — the privileged step

The plugin cannot do this and deliberately does not try: creating a database and
a role is a privileged act on your cluster, so it is a file you can read first.

```sh
psql -U postgres -d postgres \
     -v db=dnb -v owner=dnb \
     -v owner_pass="$(php -r 'echo bin2hex(random_bytes(24));')" \
     -f plugin/bin/bootstrap.sql
```

It creates exactly two things: a login role with `CREATEROLE` and `CREATEDB`
(**not** a superuser, **not** `BYPASSRLS`), and an empty database owned by it.
No table, no grant. Re-running it rotates nothing.

Record the owner password — step 4 needs it.

## 3. Require passwords

The roles this plugin creates have **no password at all** until step 5 gives
them one. Under a `trust` entry in `pg_hba.conf` that means anyone can be them.
Make sure the entries covering this database require `scram-sha-256`, then:

```sh
psql -U postgres -c 'SELECT pg_reload_conf()'
```

`plugin.php doctor` checks this by trying a deliberately wrong password, and
tells you if the cluster let it in.

## 4. Configure

```sh
install -d -m 0750 /etc/dnb
cp plugin/.env.example /etc/dnb/dnb.env
chmod 0640 /etc/dnb/dnb.env
$EDITOR /etc/dnb/dnb.env
```

Set at minimum `DNB_DSN`, `DNB_OWNER_USER`, `DNB_OWNER_PASS`.

Then choose how role credentials are provisioned:

- **You supply them** — fill in every `DNB_*_PASS`. The installer applies them
  on each run, so your secret manager stays the source of truth.
- **The installer generates them** — leave them empty and set
  `DNB_SECRETS_OUT=/etc/dnb/secrets.env`. It mints 32 random bytes per role,
  writes them to that file at mode `0600`, and prints nothing.

Either way, **no credential for this installation exists in the package or in
the repository it was built from.**

## 5. Preflight, then install

```sh
set -a; . /etc/dnb/dnb.env; set +a
php plugin/bin/plugin.php doctor        # must exit 0
php plugin/bin/plugin.php install
php plugin/bin/plugin.php status
```

If you generated secrets, source them from now on:

```sh
set -a; . /etc/dnb/dnb.env; . /etc/dnb/secrets.env; set +a
```

`install` is safe to re-run. It refuses to rotate a credential for a role that
predates this installation, because on a shared cluster that role may belong to
someone else.

**Upgrading.** Re-running `install` over an existing installation applies only
the migrations its ledger has not recorded. **Migration 029** (O-1, `docs/124`)
makes a site and its service belong to the same operator. On an estate where a
site already names another operator's service it **refuses and changes
nothing**, and its error counts the rows and names up to five. Before upgrading
an installation that holds real data, run the census from the repository
(`docs/79`) and resolve each reported row first.

## 6. Demonstration data (optional)

```sh
php plugin/bin/plugin.php simulate
```

Builds three customers, five routers across five lifecycle states, seventeen
vouchers, six sessions — every identifier prefixed `SIM-`, every screen marked
**SIMULATED**. It refuses to run if `DN_ALLOW_REAL_BINDINGS` is set.

## 7. Serve

```sh
php -S 127.0.0.1:8099 plugin/bin/serve.php
```

Reach it over an SSH tunnel:

```sh
ssh -L 8099:127.0.0.1:8099 <your-server>     # then open http://127.0.0.1:8099/
```

Any web server will do instead: serve `panel/` as static files and route
`/api/v1/admin/*` to `plugin/public/api.php`. The API reads the raw request
path, so if you mount it under a prefix your proxy must strip that prefix before
PHP sees it.

**This release ships no TLS and no process supervision.** Do not put the
built-in server on a public interface.

## 7b. Serve the operator app — its own process (`docs/127` phase 3)

The operator app — where an operator's people sign in, issue and revoke
vouchers and manage their plans — is served by a **second** process, never by
`serve.php`:

```sh
php_bin=$(command -v php)     # env -i clears PATH, so name php by its full path
env -i DNB_DSN="$DNB_DSN" DNB_APP_PASS="$DNB_APP_PASS" \
       DNB_TOKEN_PEPPER="$DNB_TOKEN_PEPPER" DNB_SECRET_KEY="$DNB_SECRET_KEY" \
       "$php_bin" -S 127.0.0.1:8098 plugin/bin/serve-app.php
```

- **It needs exactly those four variables.** Give it no Admin, worker, staff or
  RADIUS credential: it has no use for one, and the test suite starts it with
  nothing else.
- **Give it its own host name**, not a path beside the Admin panel. The Admin
  session cookie is then never sent to it.
- It passes exactly `/api/v1/auth/*`, `/api/v1/me` and `/api/v1/me/*` to
  `public/index.php`, serves `public/app/` and `public/pwa/`, and answers 404 to
  everything else — the Admin API and `/internal/*` included. Every answer
  carries a strict Content-Security-Policy.
- **Never point a web server's document root at `public/`.** That would expose
  `/internal/radius/accounting` and leave the front controller's own routing as
  the only guard. Use `serve-app.php`, or reproduce its allow-list exactly.
- **TLS in front, as for the panel.** The page holds a bearer token. Do not put
  the built-in server on a public interface.
- **`DNB_SECRET_KEY` must be the worker's**: this process seals sign-in codes
  and the worker opens them (§8). **Never set `DNB_EXPOSE_OTP` here.**
- A person signs in with the number the Admin panel recorded for them. The code
  reaches their phone only once an SMS sender is set: in the Admin panel, or
  in the worker's `DN_SMS` (§8).

## 8. The worker and its delivery binding

```sh
php bin/worker.php            # loops; --once runs a single pass
```

The worker is the only process that turns a queued intent into anything, and
**what it turns it into is chosen by `DN_DELIVERY`, never by fallback**:

| `DN_DELIVERY` | Binding | What happens to an intent |
|---|---|---|
| unset, or `null` | `NullDelivery` | nothing is delivered; the intent stays queued and says *no delivery path is configured* |
| `simulated` | `SimulatedRouterOs` | an **in-memory** router accepts and confirms; every result is tagged simulated, the audit actor carries `simulated-routeros`, and **no device state or read-back is written** — a simulator answering never makes a router `connected`, `provisioned` or `active`. Refuses to start if the F6-B gate is open |
| `routeros` | `RouterOsDelivery` | the real adapter over REST on the WireGuard tunnel. **Requires `DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized`** and refuses to start without it; the REST client checks the gate again before opening a socket. **Not authorized for this release** |
| anything else | — | the worker refuses to start |

The worker prints its binding on startup and `GET /api/v1/admin/health`
reports `delivery_configured` so the panel can never imply a router was
contacted. `POST /routers/{id}/actions` with `{"action": "push_config",
"idempotency_key": …}` queues a `device.provision` intent (migration 028,
`docs/121`); **only the worker delivers it, through the binding above** — with
`DN_DELIVERY` unset the job stays queued, under `simulated` an in-memory router
confirms it. A router must be assigned to an operator, recorded `connected` or
later, and carry a management address before a job is accepted; otherwise the
API answers 409 with the reason instead of queuing a job that would only fail
later. The other three actions answer 501 with the inventory's reason.

### Sign-in codes by SMS (migration 032, `docs/127` phase 2)

When an operator's person asks for a sign-in code, the API **seals** the code
and files it in an outbox in the same transaction; **the worker sends it**. The
request never waits on the provider. What the worker sends through is decided
by `DN_SMS` in **the worker's environment** first, and by the Admin panel when
that is unset — never by fallback:

| `DN_SMS` | Binding | What happens to a code |
|---|---|---|
| unset | `PanelSms` — **the Admin panel decides** (migration 033, `docs/128`) | whatever an Admin saved under *Administration → SMS for sign-in*. Until then nothing is sent, the message expires with its code, **no operator can sign in**, and the doctor warns. Once saved, the worker uses it **within a second, without a restart**. A setting it cannot use sends nothing, keeps the worker running, and the page says why |
| `null` | `NullSms` | nothing is sent, **whatever the panel says**; the page shows *set on the server (`DN_SMS`)* |
| `africastalking` | `AfricasTalkingSms` | sent through Africa's Talking. Needs `DNB_SMS_USERNAME` and `DNB_SMS_API_KEY`, and the `curl` extension; refuses to start without them. **The panel's settings are ignored**, and the page says so |
| anything else | — | the worker refuses to start |

In either place, the username `sandbox` selects the provider's sandbox, which
delivers to its simulator, **not to phones**.

**Setting it in the Admin panel** (migration 033, `docs/128`):

- Only an **Admin** can open the page (the capability `sms.manage`). It is
  bound only under the real staff login. The development identity gets 501.
- Enter the Africa's Talking username, the API key and, if you like, a sender
  name, then save. **The API seals the key before the database sees it**, under
  a key derived from `DNB_SECRET_KEY`. Only the worker opens it, in memory. The
  page never shows the key again, only whether one is set, and the form's key
  field is always empty. Leave the key field empty to keep the stored key. A new
  username needs its key typed again.
- Every change is audited as `sms.settings_changed`, with the Admin as the
  actor. The audit row holds the username and sender, and whether the key was
  set, replaced, kept or removed, but never the key. Saving identical settings
  records nothing.
- The page shows **what the worker did**, not what the form hoped: *in use*,
  *waiting for the worker*, *cannot use it* with the reason, or *set on the
  server*. It also shows the outbox's own counts for the last 24 hours: sent,
  refused, expired and unknown numbers. It shows no number and no code.
- **If `DNB_SECRET_KEY` ever changes**, the stored key no longer opens: the
  worker sends nothing, and the page says *type the key again*.

- **Only a person who could sign in is ever sent a code**: an active person of
  an active operator. An unknown number gets the same answer and is sent
  nothing, so the endpoint cannot be used to spend your SMS credit.
- **The API key is a secret.** Type it into the Admin panel, over HTTPS, as a
  signed-in Admin, or on the server into the worker's environment file, mode
  0600. Never put it in chat, a log, a command line or source control. The
  doctor reports it as *set (value withheld)*, from either place, and never
  opens it.
- **`DNB_SECRET_KEY` must be the same for the API and the worker**: the API
  seals with a key derived from it and the worker opens with the same one.
- **Never set `DNB_EXPOSE_OTP` on a reachable host**, and never read a code out
  to someone: the code goes to the phone and nowhere else.
- The adapter is **DOCUMENTED, UNVERIFIED**. The request format was taken from
  the provider's official SDK; the answer's status codes come from its
  documentation. It becomes verified when your account sends a real message.
- The worker needs **outbound HTTPS** to `api.africastalking.com`. Check that
  from the worker's network before relying on it.

## The identity gate

Out of the box the identity binding is `DenyAllIdentity`, which admits nobody:
every API route answers **401**, `POST /session` answers **501**, and the panel
says so instead of showing a form. Who may sign in is an explicit choice, and
there are exactly two ways to make it.

### Demonstration: the development identity

```sh
DN_DEV_STAFF_IDENTITY=yes-development-only php -S 127.0.0.1:8099 plugin/bin/serve.php
```

A fabricated identity with a role picker and no credential. It must not be
used where anyone but you can reach it, it refuses to start alongside
`DN_ALLOW_REAL_BINDINGS`, and it refuses to start alongside the real provider.

### Real: DishNet staff sign-in (migration 026)

```sh
DN_STAFF_IDENTITY=dishnet
DN_TRUSTED_PROXY=127.0.0.1        # the address of whatever terminates TLS
```

Passwords (bcrypt, cost 12) and authenticator codes (RFC 6238 TOTP) are
verified **inside PostgreSQL** by `mt_staff_login()`, in one transaction with
a decaying lockout (5 failures → 15 minutes, doubling, 24-hour ceiling, never
permanent) and the audit row. Sessions are opaque 256-bit cookies — HttpOnly,
Secure, SameSite=Strict — stored only as an HMAC, 8 hours absolute, and
revocable: signing out, disabling a person, changing their role or resetting
their password ends their sessions at once. No login role can read the
credential tables at all; `plugin.php doctor` and the suite assert it.

**The first administrator is created on the server, once:**

```sh
php plugin/bin/plugin.php staff:bootstrap alice --display "Alice A."
```

It prints the generated password **once** and stores it nowhere. Sign in with
it, set up an authenticator when the panel asks, then change the password from
*My account*. It refuses to run once any staff row exists; everyone after the
first is created from *DishNet staff* in the panel by an Admin.

**A second factor is required by default.** A password-only session can do
nothing but enrol an authenticator. `DN_STAFF_REQUIRE_TOTP=no` relaxes that
for a development machine; the doctor treats it as a blocker anywhere else,
because it is mandatory before a public hostname.

### TLS and the reverse proxy — required for the real provider

The real provider **refuses to issue a session over plain HTTP**
(`403 insecure_transport`): a cookie without the Secure flag is a session
anyone on the path can replay. PHP knows a request was TLS in two cases only:

1. PHP terminated TLS itself, or
2. a reverse proxy that terminated TLS says `X-Forwarded-Proto: https` **and
   its address is in `DN_TRUSTED_PROXY`**. The header is ignored from anyone
   else, so a client cannot claim TLS it did not have.

So a real deployment is: TLS at a reverse proxy on the same host (any of
nginx, Caddy or Apache will do), proxying to `php -S 127.0.0.1:8099
plugin/bin/serve.php` or to php-fpm, with `DN_TRUSTED_PROXY=127.0.0.1` and,
optionally, `DN_PORTAL_ORIGIN=https://<your hostname>` so a mutating request
from any other origin is refused. Nothing here configures that proxy for you,
and this document does not tell you to change a live server.

An SSH tunnel to plain HTTP is **not** TLS as far as this provider is
concerned: over a tunnel the real provider answers 403, and the demonstration
identity remains the way to look at the panel that way.

**A misconfigured real provider fails loudly.** A wrong `DN_STAFF_IDENTITY`
value, the development gate set beside it, or a staff-auth connection that
cannot open makes every request answer **500** with one line in the log. It
never quietly becomes deny-all, and never the development identity.

## Verify

```sh
php plugin/bin/plugin.php doctor
```

Every check should read `ok`. `skip` means a check could **not run** — it is not
a pass. `blocker` must be cleared before you rely on the installation.

To prove the whole lifecycle on a cluster of its own, without touching this one:

```sh
sh plugin/bin/install-test.sh
```
