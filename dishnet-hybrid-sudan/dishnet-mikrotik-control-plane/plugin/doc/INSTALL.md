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
| Authenticate staff | **no** — see *The identity gate* below |
| Reach a real MikroTik router | **no** — F6-B is not authorized |
| Publish a RADIUS credential | **no** |
| Redeem a voucher | **no** |

## Requirements

- PHP **8.1+** with `pdo_pgsql`, `json`, `openssl`
- PostgreSQL **14+**, **preferably an instance of its own** — see *Why its own
  instance*
- a superuser on that instance, for step 2 only
- a directory the account that serves HTTP can read

No Redis. No Docker. No writable runtime directory. Nothing is written to disk
at runtime.

## Why its own instance

PostgreSQL roles belong to the **cluster**, not the database. This plugin
creates twelve. Installing onto the cluster that serves UCRM would add twelve
roles visible there — granted nothing in it, but present — and uninstalling
would drop them cluster-wide.

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

## The identity gate

The production identity binding is `DenyAllIdentity`, which admits nobody, and
staff authentication is **not built** (W-4 is open). So out of the box every API
route answers **401** and the panel renders an empty shell — correctly.

To look at the panel, set the development gate:

```sh
DN_DEV_STAFF_IDENTITY=yes-development-only php -S 127.0.0.1:8099 plugin/bin/serve.php
```

That is a **demonstration** installation. It fabricates a staff identity and
must not be used where anyone but you can reach it. It refuses to start
alongside `DN_ALLOW_REAL_BINDINGS`.

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
