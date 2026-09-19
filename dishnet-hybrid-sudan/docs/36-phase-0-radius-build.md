# Phase 0 — RADIUS build (category B)

Isolated FreeRADIUS + PostgreSQL on the existing production server, prepared
while MikroTik Step 0 is researched and validated in parallel.

**Prerequisite, already met:** docs/34 §7.5 — WireGuard gateway externally
verified. `10.66.0.1` exists and answers.

**Nothing in this file touches:** `unms-postgres`, `wa_evolution-api-db`,
`wa_evolution-api-redis`, uCRM, EasyPanel, Traefik, Docker Swarm, the
firewall, or `/etc/wireguard/wg0.conf`.

---

## 1. Isolation design, and why each choice

| | choice | reason |
|---|---|---|
| orchestration | **plain `docker run`, outside the swarm** | docs/34 §3.3 — EasyPanel must not reschedule, scale or reconcile Phase 0 |
| PostgreSQL | **new container, `postgres:16-alpine`** | neither existing instance may be borrowed; both are application-owned |
| postgres port | **`127.0.0.1:5433`** | loopback only, and 5433 rather than 5432 so it is unmistakably ours |
| postgres network | user-defined bridge `dn-phase0` | isolated from the swarm overlays |
| FreeRADIUS | **`--network host`** | it must bind `10.66.0.1`, a host interface. A bridge container cannot |
| restart policy | `unless-stopped` | see §1.1 |
| config | bind-mounted from `/opt/dn-phase0/raddb` | editable and readable without entering the container |

### 1.1 The ordering dependency nobody wrote down

**FreeRADIUS binds `10.66.0.1`, which exists only while `wg0` is up.** At boot,
Docker may start the container before `wg-quick@wg0` has created the
interface. FreeRADIUS then fails to bind and exits.

`--restart unless-stopped` turns that into a self-healing race: the container
retries until the interface appears. A native systemd service would have
needed an explicit `After=wg-quick@wg0.service` override, which is one more
thing to get wrong. **This is the strongest argument for the container
choice, and it was not the reason docs/34 gave.**

Watch for it anyway: a container in a restart loop after a reboot means `wg0`
did not come up, and that is a gateway problem wearing a RADIUS costume.

---

## 2. Build — run in order, stop on any error

### 2.1 Directory and network

```bash
mkdir -p /opt/dn-phase0/raddb
cd /opt/dn-phase0
docker network create dn-phase0
```

### 2.2 PostgreSQL

```bash
# generate and KEEP this somewhere safe; it is not recorded in this file
PGPASS=$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)
echo "$PGPASS" > /opt/dn-phase0/.pgpass && chmod 600 /opt/dn-phase0/.pgpass
echo "  password written to /opt/dn-phase0/.pgpass"

docker run -d \
  --name dn-phase0-postgres \
  --network dn-phase0 \
  --restart unless-stopped \
  -e POSTGRES_DB=radius \
  -e POSTGRES_USER=radius \
  -e POSTGRES_PASSWORD="$PGPASS" \
  -p 127.0.0.1:5433:5432 \
  -v dn-phase0-pgdata:/var/lib/postgresql/data \
  postgres:16-alpine

sleep 5
docker logs dn-phase0-postgres --tail 5
ss -tlnp | grep 5433          # MUST show 127.0.0.1 only
```

**If `ss` shows `0.0.0.0:5433`, stop.** The publish spec is wrong and the
database is on the public Internet.

### 2.3 The FreeRADIUS schema — taken from the image, not hand-written

```bash
docker pull freeradius/freeradius-server:3.2.10

# locate it rather than assuming the path — it moves between versions
docker run --rm freeradius/freeradius-server:3.2.10 \
  find / -name schema.sql -path '*postgresql*' 2>/dev/null
```

**REQUIRES VERIFICATION — record the path it prints.** Then, substituting it:

```bash
docker run --rm freeradius/freeradius-server:3.2.10 \
  cat <THE PATH FROM ABOVE> > /opt/dn-phase0/schema.sql
wc -l /opt/dn-phase0/schema.sql

docker exec -i dn-phase0-postgres \
  psql -U radius -d radius < /opt/dn-phase0/schema.sql

docker exec dn-phase0-postgres \
  psql -U radius -d radius -c '\dt'
```

Expect `radcheck`, `radreply`, `radacct`, `radgroupcheck`, `radgroupreply`,
`radusergroup`, `radpostauth`, `nas`.

**Never hand-write this schema.** `radacct`'s columns are what FreeRADIUS
writes; a typo appears as silently missing accounting months later.

### 2.4 FreeRADIUS configuration

Extract the stock config so it can be edited outside the container:

```bash
docker create --name dn-tmp freeradius/freeradius-server:3.2.10
docker cp dn-tmp:/etc/freeradius/. /opt/dn-phase0/raddb/
docker rm dn-tmp
ls /opt/dn-phase0/raddb/
```

**REQUIRES VERIFICATION** — if `/opt/etc/raddb` is wrong for this image, find
it with `docker run --rm freeradius/freeradius-server:3.2.10 find / -name radiusd.conf`.

**Three edits, and only three.**

**(a) Bind to the tunnel — `raddb/sites-available/default`.** Both `listen`
blocks:

```
listen {
    type = auth
    ipaddr = 10.66.0.1        # NOT *, NOT 0.0.0.0
    port = 1812
}
listen {
    type = acct
    ipaddr = 10.66.0.1
    port = 1813
}
```

**This is the security control of the whole build.** FreeRADIUS defaults to
`*`. Left alone, the shared secret is answerable from the public Internet.

**(b) SQL — `raddb/mods-available/sql`:**

```
driver = "rlm_sql_postgresql"
dialect = "postgresql"
server   = "127.0.0.1"
port     = 5433
login    = "radius"
password = "<from /opt/dn-phase0/.pgpass>"
radius_db = "radius"
read_clients = yes
```

Then enable it and the accounting it drives:

```bash
ln -sf ../mods-available/sql /opt/dn-phase0/raddb/mods-enabled/sql
```

In `sites-available/default`, uncomment `sql` inside `authorize {}`,
`accounting {}` and `post-auth {}`.

**(c) The NAS — `raddb/clients.conf`.** One unique secret per device:

```
client dn-test-mikrotik {
    ipaddr    = 10.66.0.11
    secret    = "<GENERATE A UNIQUE ONE>"
    shortname = dn-test-mikrotik
    nas_type  = other
}
```

The address is the router's **tunnel** address, not a public one. A
fleet-wide secret means one recovered router compromises every other.

### 2.5 Start it

**First run in the foreground**, where FreeRADIUS explains itself:

```bash
docker run --rm -it \
  --name dn-phase0-radius-debug \
  --network host \
  -v /opt/dn-phase0/raddb:/etc/freeradius \
  freeradius/freeradius-server:3.2.10 \
  -X
```

`-X` prints every decision. Look for `Ready to process requests` — and for
the two `Listening on` lines showing **10.66.0.1**, not `*`. Ctrl-C, then:

```bash
docker run -d \
  --name dn-phase0-radius \
  --network host \
  --restart unless-stopped \
  -v /opt/dn-phase0/raddb:/etc/freeradius \
  freeradius/freeradius-server:3.2.10

docker logs dn-phase0-radius --tail 20
ss -ulnp | grep -E '1812|1813'      # MUST show 10.66.0.1
```

### 2.6 The test voucher

```bash
docker exec -i dn-phase0-postgres psql -U radius -d radius <<'EOF'
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('t1-TESTCODE01', 'Cleartext-Password', ':=', 't1-TESTCODE01');

INSERT INTO radreply (username, attribute, op, value) VALUES
  ('t1-TESTCODE01', 'Mikrotik-Rate-Limit',   '=', '5M/5M'),
  ('t1-TESTCODE01', 'Session-Timeout',       '=', '3600'),
  ('t1-TESTCODE01', 'Acct-Interim-Interval', '=', '300');

SELECT username, attribute, value FROM radcheck
UNION ALL SELECT username, attribute, value FROM radreply ORDER BY 1,2;
EOF
```

The `t1-` prefix is the tenant namespace from docs/30 §5 — used from the
first row so it is never retrofitted. **This is not a voucher system.** It is
four rows, for one hardware test.

---

## 3. Server-side verification, before the MikroTik exists

### 3.1 Authentication

```bash
docker exec dn-phase0-radius \
  radtest t1-TESTCODE01 t1-TESTCODE01 10.66.0.1 0 <THE NAS SECRET>
```

Wanted: **`Access-Accept`**, carrying `Mikrotik-Rate-Limit = "5M/5M"`,
`Session-Timeout = 3600`, `Acct-Interim-Interval = 300`.

**An Access-Accept with no attributes is a failure**, not a pass — it means
`radreply` is not being consulted and every plan would silently become
unlimited.

### 3.2 Accounting — Start, Interim, Stop

```bash
S=$(date +%s)
docker exec -i dn-phase0-radius sh -c \
  "echo 'User-Name=t1-TESTCODE01,Acct-Status-Type=Start,Acct-Session-Id=$S,NAS-IP-Address=10.66.0.11,Framed-IP-Address=10.66.0.99' \
   | radclient 10.66.0.1:1813 acct <THE NAS SECRET>"

docker exec dn-phase0-postgres psql -U radius -d radius \
  -c "SELECT acctsessionid, username, acctstarttime, acctstoptime FROM radacct ORDER BY radacctid DESC LIMIT 3;"
```

Repeat with `Acct-Status-Type=Interim-Update` plus
`Acct-Input-Octets=1000000,Acct-Output-Octets=5000000`, then
`Acct-Status-Type=Stop` with `Acct-Session-Time=600`.

Wanted: one row appears on Start, its octets grow on Interim, and
`acctstoptime` fills on Stop. **Three separate checks, not one** — Interim is
what the live dashboard reads, and it fails independently of Start.

### 3.3 Exposure — run from OUTSIDE the droplet

```bash
# from your Mac, NOT from the server
nmap -sU -p 1812,1813 209.97.137.203
nmap -Pn -p 5433 209.97.137.203
radtest t1-TESTCODE01 t1-TESTCODE01 209.97.137.203 0 anysecret
```

Wanted: **the radtest times out**, and neither port is open. If RADIUS
answers on the public IP, stop and fix the `ipaddr` in §2.4(a) before
anything else — the shared secret is exposed.

### 3.4 Nothing else moved

```bash
docker ps --format '{{.Names}}|{{.Status}}' | sort > /root/phase0-evidence/containers.radius
diff /root/phase0-evidence/containers.after /root/phase0-evidence/containers.radius
iptables -S | sort | diff /root/phase0-evidence/iptables.after - | head
wg show
```

The container diff shows **exactly two additions**. The iptables diff will
**not** be empty this time — Docker adds rules for the published
`127.0.0.1:5433`. Read them and confirm they are loopback-scoped and touch
nothing existing.

---

## 3.5 Verified against the real image — 19 September 2026

The placeholders in §2 are resolved. **Three of the assumed values were
wrong**, which is why §2.3 and §2.4 said to discover rather than assume.

| | documented guess | **verified** |
|---|---|---|
| image tag | `3.2` | **`3.2.10`** — a bare `3.2` tag does not exist |
| config root | `/opt/etc/raddb` | **`/etc/freeradius`** |
| schema | unknown | **`/etc/freeradius/mods-config/sql/main/postgresql/schema.sql`** |
| queries | unknown | `/etc/freeradius/mods-config/sql/main/postgresql/queries.conf` |
| daemon binary | `radiusd` | **`freeradius`** — Debian naming |
| entrypoint | — | `/docker-entrypoint.sh`, CMD `freeradius` |

**`radtest` and `radclient` are in the image**, at `/usr/bin/`. §3.1 and §3.2
run inside the container and need nothing on the host.

**An install was avoided by simulating it.** `apt-get install -s
freeradius-utils` on the host would have pulled seven packages —
`freeradius-common`, `freeradius-config`, `libfreeradius3`, `libtalloc2`,
`ssl-cert` and `make` — onto a production server, to provide tools the
container already had. The simulate-first rule from docs/34 §5 paid for
itself a second time.

**Pin the patch version, not the line.** `latest-3.2` moves; `3.2.10` does
not. A staging environment whose RADIUS version changes under it produces
test results that cannot be reproduced.

**Note the `main` schema.** The image ships six `postgresql/schema.sql`
files — ippool, dhcp, ippool-dhcp, main, moonshot-targeted-ids, cui. Only
`main` carries `radcheck`, `radreply` and `radacct`. Applying the wrong one
would create a valid-looking database with none of the tables FreeRADIUS
writes during accounting.

## 4. What is still NOT built

```
no control plane          no device registry      no provisioning service
no customer portal        no reseller portal      no voucher UI
no tenancy code           no uCRM integration     no billing
no Redis                  no RadSec               no CoA automation
```

RADIUS here has one purpose: to answer a MikroTik during A9–A14. Everything
else waits on docs/31 §8 and on B1's answer.

## 5. Teardown, if any of it misbehaves

```bash
docker rm -f dn-phase0-radius dn-phase0-postgres
docker network rm dn-phase0
docker volume rm dn-phase0-pgdata
rm -rf /opt/dn-phase0
```

The host returns to its post-WireGuard state. No production container,
network, volume or rule is involved at any point.

---

## 6. Execution record — 19 September 2026

Built on the live production host. Items 1–6 of the operator's evidence list
pass; 7–10 (exposure and production-untouched) remain.

### 6.1 Evidence

| # | check | result |
|---|---|---|
| 1 | PostgreSQL exposed only on 127.0.0.1:5433 | **PASS** — `LISTEN 127.0.0.1:5433` |
| 2 | FreeRADIUS listens only on 10.66.0.1:1812/1813 | **PASS** — after §6.3 |
| 3 | `radtest` → Access-Accept **with attributes** | **PASS** — `Mikrotik-Rate-Limit = "5M/5M"`, `Session-Timeout = 3600`, `Acct-Interim-Interval = 300` |
| 4 | Accounting-Start creates a `radacct` row | **PASS** |
| 5 | Interim updates that row | **PASS** — after §6.4 |
| 6 | Stop closes it | **PASS** — one row, 600s, `User-Request` |

### 6.2 Five things the plan got wrong

Recorded because the pattern is the point: **every one was a value written
from memory instead of read from the thing itself.**

| | assumed | actual |
|---|---|---|
| image tag | `3.2` | `3.2.10` — bare `3.2` does not exist |
| config root | `/opt/etc/raddb` | `/etc/freeradius` |
| daemon binary | `radiusd` | `freeradius` |
| `sql` module | a minimal block written by hand | needed 9 more variables the stock file defines |
| verification regex | `^[[:space:]]+sql$` | missed `-sql`, reported a correct file as broken |

The `sql` one cost three rounds. Writing the module config from scratch
discarded exactly the parts that were not memorable — eight table names, then
`group_attribute`. **Diffing the variable names against the stock file found
all of them at once**; the error-at-a-time loop before it found one per run:

```bash
docker run --rm --entrypoint cat <image> /etc/freeradius/mods-available/sql \
  | grep -oE '^[[:space:]]*[a-z_]+[[:space:]]*=' | sed 's/[[:space:]]//g; s/=$//' | sort -u > stock.v
grep -oE '^[[:space:]]*[a-z_]+[[:space:]]*=' mods-available/sql \
  | sed 's/[[:space:]]//g; s/=$//' | sort -u > mine.v
comm -23 stock.v mine.v
```

### 6.3 Two listeners the plan never mentioned

`sed` rewrote `ipaddr = *`, and the first `-X` run then showed:

```
Listening on auth address :: port 1812        ← ALL IPv6
Listening on acct address :: port 1813
Listening on proxy address * port 35821
```

Separate `listen` blocks use `ipv6addr`, not `ipaddr`. **The droplet has only
link-local IPv6 today, so nothing was reachable — but enabling IPv6 in the
DigitalOcean panel is a checkbox, and the shared secret would have become
answerable from the Internet with no change here.**

Worse, **the planned exposure test would have passed anyway**:
`nmap -sU -p 1812,1813 <ipv4>` cannot see an IPv6 listener. A test that only
looks where you expect the problem is not a test.

Fixed with `ipv6addr = ::1` and `proxy_requests = no`. Both seds needed a
second attempt — one line carried a trailing comment that defeated a `$`
anchor, the other had **two spaces** before its `=`.

### 6.4 Accounting split into two rows, and it was the test's fault

Interim inserted a second row instead of updating the first. Both rows had a
populated `acctuniqueid` — **and the two hashes differed**, which is what
named the cause: `rlm_acct_unique` hashes a fixed attribute set, and the
hand-built Start packet carried `NAS-Port=0` while Interim and Stop did not.

`acct_unique` was working correctly throughout. Identical attributes across
all three packets produced one row.

**This hands the hardware test something specific.** A real MikroTik should
send a consistent attribute set, but "should" is not "verified". During A12,
**count the `radacct` rows for one hotspot session.** More than one means
RouterOS varies its attributes between packet types, and the fix is narrowing
`acct_unique`'s key list — not a mystery to debug from scratch.

Had #4 alone been checked, this would have passed: the Start row appears
correctly either way. Splitting #4, #5 and #6 is what caught it.

### 6.5 Secrets handled during the build

- **The PostgreSQL password was printed by `rlm_sql_postgresql`** in `-X`
  output, which bypasses FreeRADIUS's own `<<secret>>` suppression. Rotated
  via `ALTER USER`, and the log deleted. **Standing rule: an `-X` log
  contains the database password. Treat it as a secret and delete it.**
- `mods-available/sql` was created mode 644 by `cat >`. Now 640, group
  `freerad` (gid 101).
- `docker cp` chowns extracted files to the local user, which **stripped the
  `freerad` group** from `accounting`, `clients.conf`, `radiusd.conf` and the
  whole `certs/` tree. FreeRADIUS drops privileges before instantiating
  modules, so it could not read its own config. Fixed with a recursive
  `chgrp` to gid 101 rather than by loosening permissions.

### 6.6 Owed before any of this faces a real router

- `client dn-localtest` (ipaddr `10.66.0.1`) — a test client pointing at the
  gateway itself. **Remove.**
- `client localhost` and `client localhost_ipv6` ship with the secret
  `testing123`. Harmless now (loopback only, and nothing listens on
  `127.0.0.1:1812`), but they are stock defaults in a config that will face
  real routers. **Remove.**
- Test rows `t1-TESTCODE01` and `dnp0-test-002`.
