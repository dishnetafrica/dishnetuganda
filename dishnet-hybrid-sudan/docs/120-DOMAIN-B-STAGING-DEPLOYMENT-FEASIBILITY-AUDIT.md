# 120 — Domain-B staging on the existing `dishnetuganda` server — READ-ONLY deployment feasibility audit

**Status: AUDIT. Nothing deployed. Nothing on any server changed, restarted,
created or contacted.** Requested 2026-09-23: make the current Domain-B build
(`e5db549`, G-C accepted at `9a09cc7`) reachable from a browser through the
existing DigitalOcean host — not a laptop, not a new VPS — while UCRM/UISP,
WhatsApp, mail, the website and every other production service stay untouched.
**Deployment waits for explicit approval of the proposal below; this document
executes none of it.**

> **Update 2026-09-23 14:02 UTC — the operator ran §1.2 on the server and
> pasted the output back. §1.3 records the result: the 19 September baseline
> is CONFIRMED, every go / no-go threshold of §9 is met, and two facts amend
> §3 and §6. Still nothing deployed.**

> **The one limit to state first.** This session cannot reach the
> `dishnetuganda` server: it has no SSH client, no credential, its egress is a
> proxy that refuses that host, and the standing rule (`CLAUDE.md`, `docs/78`
> §1.1) forbids SSH discovery, DSN guessing and production probing. **§1 is
> therefore the recorded baseline of 19 September 2026 (`docs/34`, `docs/36`,
> `docs/00` §10), labelled as such, plus a read-only verification script for
> the operator to run and paste back.** The package (§2) was inspected directly
> in the repository. Every row below says which of the two it is: **RECORDED**
> (dated, must be re-verified), **VERIFY** (the operator's script answers it) or
> **MEASURED** (read from the repository at `e5db549`).

---

## 0. Scope and method

| In scope | Method |
|---|---|
| the server's state | RECORDED baseline + the operator's read-only script (§1.2). No command in it writes, restarts or installs anything |
| the Domain-B package | MEASURED from the repository: manifest, installer, entry point, worker, env template, doctor, install test, packaging script |
| the deployment proposal | derived from both; **every step is written, none is run** |

**Not in scope, and not done:** any package install, container, database,
network, volume, DNS record, certificate, Traefik or EasyPanel change, firewall
change, port, production restart, or migration against any production
database. The Domain-B migrations (001–027) are applied only by
`plugin.php install` against the **new, separate** instance this proposal
creates, and only after approval.

---

## 1. Current server state

### 1.1 The recorded baseline — 19 September 2026, NOT re-verified here

From `docs/34` (read-only audit of the live host), `docs/36` (the Phase-0
build executed on it) and `docs/00` §10.

| | RECORDED value | Source |
|---|---|---|
| host | DigitalOcean droplet `dishnetuganda`, KVM, Ubuntu 24.04.4 LTS, kernel 6.8.0-139, `Etc/UTC` | `docs/34` §1 |
| CPU / RAM | **2 cores**, 7.8 GB RAM, **5.3 GB available** at audit time | `docs/34` §1 |
| disk | 154 GB, 11 % used, **138 GB free** | `docs/34` §1 |
| addresses | public `209.97.137.203/20` on eth0; private `10.16.0.9/16`, `10.131.119.86/16` | `docs/34` §1 |
| orchestration | **Docker Swarm, orchestrated by EasyPanel** — `dockerd` on 2377 / 7946 / 4789 udp; service tasks carry swarm names. *Changes made directly to Docker can be reverted or duplicated by the orchestrator* | `docs/34` §1 |
| containers | **18 production**: UISP/UNMS + uCRM (9: `ucrm`, `unms-api`, `unms-device-ws-1`, `unms-nginx`, `unms-postgres`, `unms-siridb`, `unms-rabbitmq`, `unms-netflow`, `unms-fluentd`); WhatsApp (3: `wa_evolution-api`, `wa_evolution-api-db` postgres:17, `wa_evolution-api-redis` redis:7); mail (3: `stalwart`, `roundcube`, `mail-certs-dumper`); platform (2: `easypanel-traefik`, `easypanel`); web (1: `web_web-uganda`) | `docs/34` §1.1 |
| **plus Phase 0** (added 19 Sep, plain `docker run`, outside the swarm) | `dn-phase0-postgres` postgres:16-alpine on **`127.0.0.1:5433`**, bridge `dn-phase0` (`172.21.0.0/16`), volume `dn-phase0-pgdata`; `dn-phase0-radius` freeradius 3.2.10, `--network host`, bound to `10.66.0.1:1812/1813`; WireGuard `wg0` native, `10.66.0.1/24`, UDP 51820 | `docs/36` §2, §6.1c; `docs/00` §10 |
| published ports | 22, 25, 80, 81, 443, 465, 587, 993, 2055/udp, 3000, 8080, 8081, 8089, 8090, 8443; **plus** 51820/udp and 127.0.0.1:5433 since Phase 0 | `docs/34` §2; `docs/36` |
| reverse proxy / TLS | **Traefik (`easypanel-traefik`) owns 80 and 443**; `web_web-uganda` is reached *via Traefik*; a `mail-certs-dumper` container exists, which implies Traefik holds ACME certificates that another stack consumes. **How EasyPanel writes Traefik's routing (labels or files) and which ACME resolver and entrypoint names exist were NOT recorded** | `docs/34` §1.1 — **VERIFY** |
| Docker networks | bridges `172.17`, `172.18.251.0/25`, `172.18.251.128/25`, `172.19`, `172.20` (+ `172.21.0.0/16` `dn-phase0`); overlays `ingress 10.0.0.0/24`, `easypanel-web 10.0.2.0/24`, `easypanel-wa 10.0.3.0/24`, `easypanel 10.11.0.0/16`. Swarm allocates overlays from `10.0.0.0/8`; `default-addr-pool` is not pinned | `docs/34` §3.2 |
| PostgreSQL instances | **three**, all containers, all owned: `unms-postgres` (UCRM/UISP), `wa_evolution-api-db` (WhatsApp, postgres:17), `dn-phase0-postgres` (FreeRADIUS `radius` database). **None may be borrowed** | `docs/34` §2; `docs/36` §1 |
| Redis | one, `wa_evolution-api-redis`. **Domain B uses no Redis** (`plugin.json` `requires.redis: false`) | `docs/34` §2 |
| firewall | UFW installed, **NOT enforcing**; 54 Docker iptables rules. **Never run `ufw enable` on this host** (a production incident, `docs/34` §3.1). A DigitalOcean cloud firewall, if any, is invisible from inside; UDP 51820 was proven reachable from outside | `docs/34` §3.1, §7.5 |
| host PHP | **not recorded.** uCRM runs PHP inside its container; whether the host has any PHP interpreter is unknown | **VERIFY** — it decides §3 |

**Constraints that remain in force on this host** (`docs/00` §10): do not
enable UFW; do not touch iptables, Docker or Swarm configuration or the daemon;
do not change the timezone; do not restart Docker, EasyPanel or Traefik; do not
reuse the existing PostgreSQL or Redis; **do not create an EasyPanel service
for Phase-0 components** — the reason (`docs/34` §3.3: *invisible to the
orchestrator, cannot be rescheduled, scaled or reconciled by it*) applies to a
Domain-B staging just as much.

### 1.2 Read-only verification — for the operator to run before anything else

**Every command reads. None writes, restarts, pulls, installs or creates,
and nothing is written to the server's disk: the block runs from a quoted
heredoc, not from a script file. It prints no secret: it never opens
`acme.json`, an env file, or any container's environment.** Run as root,
paste the whole output back. The deployment steps in §11 start by comparing it with §1.1 and **stop on
any deviation** that is not understood.

```sh
sh <<'DNB_VERIFY'
# docs/120 §1.2 — READ-ONLY server verification. Every command below only READS.
# Nothing is created, modified, restarted, removed, pulled or installed, and no
# file is written: this runs from a quoted heredoc, not from a script on disk.
# It prints no secret: it never opens acme.json, an env file or a container's
# environment.
set -u
sec() { printf '\n=== READ-ONLY: %s ===\n' "$1"; }

sec "host: CPU / RAM / disk / clock"
hostnamectl 2>/dev/null | sed -n '1,8p'; uname -r; uptime; nproc; free -m
grep -E 'MemTotal|MemAvailable' /proc/meminfo; cat /proc/loadavg
df -h / /var/lib/docker 2>/dev/null; timedatectl show -p Timezone 2>/dev/null

sec "docker / swarm state"
docker version --format 'client {{.Client.Version}} server {{.Server.Version}}'
docker info --format 'swarm={{.Swarm.LocalNodeState}} manager={{.Swarm.ControlAvailable}} nodes={{.Swarm.Nodes}} containers={{.Containers}} running={{.ContainersRunning}} images={{.Images}} storage={{.Driver}} root={{.DockerRootDir}}'

sec "containers (recorded 19 Sep: 18 production + 2 phase-0)"
docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
printf 'running now: '; docker ps -q | wc -l

sec "swarm services and stacks"
docker service ls 2>/dev/null; docker stack ls 2>/dev/null

sec "docker networks and subnets"
docker network ls
for n in $(docker network ls -q); do
  docker network inspect -f '{{.Name}} driver={{.Driver}} scope={{.Scope}} subnets={{range .IPAM.Config}}{{.Subnet}} {{end}} attached={{len .Containers}}' "$n"
done

sec "volumes and disk used by docker"
docker volume ls; docker system df

sec "published ports / listeners"
ss -tulpn | sort -k5

sec "traefik: how it is configured (arguments only; no certificate file is opened)"
docker ps --format '{{.Names}} {{.Image}}' | grep -i traefik || echo 'no container with traefik in its name'
T=$(docker ps --format '{{.Names}}' | grep -i traefik | head -1)
[ -n "$T" ] && docker inspect "$T" --format 'image={{.Config.Image}}{{"\n"}}cmd={{json .Config.Cmd}}{{"\n"}}args={{json .Args}}{{"\n"}}mounts={{range .Mounts}}{{.Source}} -> {{.Destination}}; {{end}}{{"\n"}}ports={{json .HostConfig.PortBindings}}'

sec "how easypanel attaches a domain today (traefik labels on every swarm service)"
for s in $(docker service ls -q 2>/dev/null); do
  n=$(docker service inspect "$s" --format '{{.Spec.Name}}')
  docker service inspect "$s" --format '{{json .Spec.Labels}} {{json .Spec.TaskTemplate.ContainerSpec.Labels}}' | tr ',' '\n' | grep -i traefik | sed "s/^/$n: /"
done

sec "easypanel files (listing only; acme.json is NOT opened)"
ls -la /etc/easypanel 2>/dev/null || echo 'no /etc/easypanel'
ls -la /etc/easypanel/traefik 2>/dev/null; ls -la /etc/easypanel/traefik/config 2>/dev/null

sec "existing postgres / redis / siridb (never reused)"
docker ps --format '{{.Names}} {{.Image}} {{.Ports}}' | grep -iE 'postgres|redis|siridb' || echo none-found

sec "phase-0 stack (must be unchanged)"
docker ps -a --filter name=dn-phase0 --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
docker network inspect dn-phase0 -f 'dn-phase0 subnet={{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null
docker volume inspect dn-phase0-pgdata -f 'dn-phase0-pgdata mountpoint={{.Mountpoint}}' 2>/dev/null
wg show 2>/dev/null || echo 'wg show unavailable'

sec "resources right now (one snapshot)"
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}'

sec "php on the host"
command -v php >/dev/null 2>&1 && php -v | head -1 || echo 'no php on the host'
dpkg -l 2>/dev/null | awk '/^ii  php/ {print $2, $3}'

sec "images already present (postgres:16-alpine expected from phase 0)"
docker images --format '{{.Repository}}:{{.Tag}} {{.Size}}' | grep -iE 'postgres|php|nginx|caddy|alpine' || echo none-matching

sec "ports the staging would use: 8099 (api on loopback); 5434 informational"
ss -tulpn | grep -E ':(8099|5434)\b' || echo 'free: neither 8099 nor 5434 is in use'

sec "firewall (status only)"
ufw status 2>/dev/null | head -3; printf 'iptables rules: '; iptables -S 2>/dev/null | wc -l

sec "docker daemon.json (address-pool pinning?)"
cat /etc/docker/daemon.json 2>/dev/null || echo 'no /etc/docker/daemon.json'

sec "dns: a lookup, not a change"
getent hosts portal-staging.dishnetuganda.com || echo 'portal-staging.dishnetuganda.com does not resolve'
getent hosts crm.dishnetuganda.com || echo 'crm.dishnetuganda.com does not resolve'
echo; echo '=== END OF READ-ONLY VERIFICATION ==='
DNB_VERIFY
```

**What the output must settle before §11 may start:** still 18 + 2 containers
and their names; swarm still `active` on one manager; Traefik's providers,
entrypoint names and ACME resolver (from `args=`); how EasyPanel attaches a
domain (the `traefik.*` labels of the web service); `MemAvailable` and disk
free today; whether the host has PHP at all; whether `postgres:16-alpine` is
already present (Phase 0 pulled it); that `8099` and `5434` are unused; that
the staging hostname does **not** resolve yet.

### 1.3 Verification result — the operator's read-only run, 2026-09-23 14:02 UTC

The §1.2 block was run as root on `dishnetuganda` and its whole output pasted
back. The block only reads, so the server is exactly as it was before the run.
Compared line by line with §1.1:

| Item | RECORDED 19 Sep | VERIFIED 23 Sep | Verdict |
|---|---|---|---|
| OS / kernel / timezone | Ubuntu 24.04.4, 6.8.0-139, `Etc/UTC` | same | unchanged |
| uptime | 3 days | 7 d 16 h — one boot around 16 Sep, none since Phase 0 (`dn-phase0-*` Up 4 days) | consistent |
| CPU / RAM | 2 cores, 5.3 GB available | 2 cores; MemTotal 7,941 MB; **MemAvailable 5,314 MB**; **swap 0**; load 0.25 / 0.40 / 0.36 | **≥ 2 GB — GO** |
| disk | 138 GB free, 11 % | **137 GB free, 12 %**; `/` and `/var/lib/docker` are one filesystem (`/dev/vda1`, 154 GB) | **≥ 20 GB — GO** |
| Docker / swarm | Swarm + EasyPanel | Docker **27.5.1**; swarm `active`, one node, manager; `overlay2`; 21 containers, 20 running, 21 images | unchanged |
| containers | 18 production + 2 Phase 0 | **the same 20 names, all Up**; one *exited* container is the superseded task of `web_web-uganda` from an update 7 days ago — normal swarm behaviour, not a failure | **all Up — GO** |
| swarm services | six | seven: the six plus **`web_web-sudan` at 0/0 replicas** (defined, scaled to zero; absent from the 19 Sep list); `docker stack ls` shows none — EasyPanel creates services directly | noted |
| networks | 5 bridges + 4 overlays | same subnets, plus `dn-phase0 172.21.0.0/16` and `dishnet-mail_default 172.20.0.0/16` (the mail stack's bridge). Bridges used: 172.17, 172.18.251.0/25, 172.18.251.128/25, 172.19, 172.20, 172.21 → **a new bridge would draw `172.22.0.0/16`**; nothing near `10.66` | unchanged |
| PostgreSQL / Redis | three PG, one Redis | `unms-postgres`, `wa_evolution-api-db` (postgres:17), `dn-phase0-postgres` (16-alpine on `127.0.0.1:5433`); `wa_evolution-api-redis` (redis:7); `unms-siridb` | unchanged — **none reused** |
| Phase 0 | per `docs/36` | both containers Up 4 days; `dn-phase0` bridge; `dn-phase0-pgdata` present; `wg0` up, public key `ftGs/7LjO/aVmKJ9xS/fz+QDt73JcGI+X3vgSCZpJT4=`, port 51820, **no peers** | unchanged |
| listeners | baseline list | baseline list + `51820/udp` (v4 and v6), `10.66.0.1:1812/1813`, `[::1]:1812/1813`, `127.0.0.1:18120`, `127.0.0.1:5433`; **8099 free; 5434 free** | **GO** |
| Traefik | version and configuration unknown | **`traefik:3.6.7`**; `args=["traefik"]` — **no CLI flags, so it is configured by files**; `/etc/easypanel/traefik → /data`; `acme.json` (128 KB) present; `default-domain.crt/.key`; **dynamic configuration = `config/main.yaml` (EasyPanel-written, 8,968 B, 16 Sep), `config/uisp.yaml` (3 Sep), `config/traefik-mail.yml` (11 Sep)**; **no swarm service carries a `traefik.*` label**; 80 and 443 published on IPv4 **and IPv6** | **§6 amended** |
| host PHP | unknown | **PHP 8.3.6 CLI is installed** (`php8.3-cli`, `-common`, `-opcache`, `-readline`) — **but not `php8.3-pgsql` and not `php8.3-fpm`**: `pdo_pgsql` is missing | **§3 amended** |
| images | unknown | **`postgres:16-alpine` present** (294 MB) — no pull for the database; **no PHP image** — the app image needs one pull from Docker Hub | noted |
| firewall | UFW inactive, 54 iptables rules | UFW inactive, **61 rules** — the +7 are Phase 0's `127.0.0.1:5433` publish (`docs/36` §6.1c) | unchanged |
| `daemon.json` | none | none — the address pool is still unpinned | unchanged |
| DNS | — | `portal-staging.dishnetuganda.com` **does not resolve**; `crm.dishnetuganda.com → 209.97.137.203` | as expected |
| by-product | `docs/98` §14 Q1 (installed version) UNVERIFIED | **UISP/UNMS 3.0.159, uCRM 4.5.33** (`ubnt/unms-crm:4.5.33`) | recorded for `docs/98` Q1; nothing acted on |

**Verdict: the baseline is confirmed and every go / no-go threshold of §9 is
met.** Stage 1 (§4, §11) may proceed **on explicit approval**, which this
document does not give itself.

Resource snapshot at the time of the run: the twenty containers together held
about **3.3 GB** of memory (largest: `ucrm` 730 MB, `unms-postgres` 450 MB,
`unms-api` 407 MB under a 4 GiB limit); CPU under 1 % everywhere; **no swap**,
so the 5.3 GB available is real memory, and the staging's estimated < 400 MB
fits with a wide margin.

Pre-existing, unchanged and out of this audit's scope (`docs/34` §6 item 4):
the swarm's `2377/tcp` and `7946` and every published production port listen
on all interfaces with no enforcing host firewall.

**Two amendments the run forces**, made in place below:

- **§3:** the host has PHP, but not the one extension the package needs. A
  native path would be `apt-get install php8.3-pgsql` (one package, as
  `wireguard-tools` was) plus two systemd units for the API and the worker.
  Viable, but it changes the host's package set and creates units; the
  container path changes neither. **Recommendation unchanged: containers.**
- **§6:** routing on this host is Traefik's **file provider**, not labels.
  EasyPanel writes `config/main.yaml`; two hand-added files (`uisp.yaml`,
  `traefik-mail.yml`) show that services outside EasyPanel — UISP's nginx and
  the mail stack — are routed by **adding a YAML file to
  `/etc/easypanel/traefik/config/`**. Stage 2 would therefore be one such file
  for the staging hostname. It is still a Traefik change under EasyPanel's
  directory and still its own approval; the entrypoint and resolver names it
  must use are inside `main.yaml` / `uisp.yaml`, which would be **read
  (read-only) only after stage 2 is approved**. The host listens on IPv6 too,
  so an `AAAA` record is a real question for that stage.

---

## 2. Current Domain-B package requirements — MEASURED at `e5db549`

### 2.1 What runs

| Piece | What it is | Process model |
|---|---|---|
| **Admin API** | `plugin/public/api.php`, one PHP front controller at the absolute path `/api/v1/admin/*` (`Request::fromGlobals()` reads the raw `REQUEST_URI`; **a prefix mount must be stripped before PHP sees it**) | one request, one process; connects as `dnb_adminapi` (read projections, zero table privileges) and, for the two bound router writes, `dnb_adminwrite` |
| **Admin panel** | `panel/` — five static files (`index.html`, `app.js`, `api.js`, `login.js`, `staff.js`), **no external asset, no CDN, no framework**; `api.js` calls the absolute path `/api/v1/admin/...`, so **the panel must be served at the origin root** on the same origin as the API | static files |
| **one-origin server** | `plugin/bin/serve.php` for `php -S`: routes `/api/*` to `api.php`, serves `panel/` with a `realpath` containment check (10 traversal paths asserted unreachable by the install test). *"PHP's built-in server is single-threaded and does not belong on a public interface"* — any web server that serves `panel/` statically and routes `/api/v1/admin/*` to `api.php` may replace it | single-threaded |
| **worker** | `bin/worker.php` — connects as `dnb_worker`, loops forever (`sleep(5)` between passes; `--once` for a single pass), expires overdue intents, claims and delivers. **The delivery binding is `DN_DELIVERY` and never falls back**: unset/`null` → nothing delivered; `simulated` → `SimulatedRouterOs`, in memory, every result tagged simulated, **refuses to start if the F6-B gate is open**; `routeros` → requires `DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized`, else throws. Prints its binding to stderr at start | one long-running process; the Admin **read** panel does not need it |
| **installer** | `plugin/bin/plugin.php` `doctor [--disposable]` · `install` · `status` · `simulate [--again]` · `uninstall --i-understand-this-drops-data` · `staff:bootstrap` | one-shot CLI, connects as the owner |
| **not in the package** | `public/` (the Customer PWA API front controller), `tests/`, `tools/`, `docs/`. **The staging serves the Admin panel and Admin API only** | — |
| **no second daemon** | `UplinkSampler` exists in `src/Jobs/` but **has no entry point** in `bin/`, `plugin/` or `tools/`; nothing schedules it | — |

### 2.2 Runtime requirements

| Requirement | Value | Source |
|---|---|---|
| PHP | **≥ 8.1**; extensions **`pdo_pgsql`, `json`, `openssl`** (the doctor checks version and each extension; `json` and `openssl` are built into PHP 8) | `plugin.json` `requires`; `Doctor::environment()` |
| PostgreSQL | **≥ 14**, **preferably an instance of its own**: the migrations create **15 cluster-wide roles** (7 login: `dnb_app`, `dnb_worker`, `dnb_admin`, `dnb_adminapi`, `dnb_adminwrite`, `dnb_radius`, `dnb_staffauth`; 8 NOLOGIN definer roles) plus the owner `dnb` from `bootstrap.sql` (CREATEROLE, CREATEDB, **not** superuser, **not** BYPASSRLS). Migration 026 creates `pgcrypto` (trusted; the non-superuser owner creates it) | `INSTALL.md` *Why its own instance*; `bootstrap.sql`; `install-test.sh` checks |
| a superuser | for `bootstrap.sql` only (creates the owner role and the empty database, nothing else) | `INSTALL.md` §2 |
| Redis / Docker | **neither is required** (`redis: false`, `docker: false`) | `plugin.json` |
| filesystem | a directory the serving account can read; **nothing is written to disk at runtime**; env files (`0640`/`0600`) and, if generated, one secrets file (`0600`, written once by `install`) | `INSTALL.md` *Requirements*, §4 |
| credentials | **none in the package or repository.** The migrations create every login role with no password; `install` applies `DNB_*_PASS` or generates them into `DNB_SECRETS_OUT`. `Database::connect()` has no defaults for role passwords — an unset one raises | `docs/97`; `Database.php:127-147` |
| pg_hba | the cluster **must require `scram-sha-256`**; the doctor tries a deliberately wrong password first and reports the cluster as trusting if it is accepted | `INSTALL.md` §3; `Doctor::credentials()` |
| artifact | `sh plugin/bin/package.sh` → `dishnet-mikrotik-0.1.0-rc1.tar.gz`: **116 files, 212 KB compressed**, content digest **`4e7467ad060c3974aa1f40c093c257eaf8408601612bdcaeead6eb6f916c798e`** (the digest of `SHA256SUMS`, stable across rebuilds — compare this one, never the archive's) — built in this session's scratchpad from `e5db549`; the two documentation commits since `9a09cc7` are excluded from the package, so it is the accepted G-C code | `package.sh`; this session |

### 2.3 Environment variables the code reads (complete, from `grep getenv`)

| Group | Variables | Staging value |
|---|---|---|
| **required** | `DNB_DSN` (PDO DSN, semicolons inside — quoted in a shell file, **unquoted in a Docker env-file**, see §11 step 6) · `DNB_TOKEN_PEPPER` (32+ random bytes, hex) | set |
| identities | `DNB_OWNER_USER/PASS` (install-time only) · `DNB_APP_*` · `DNB_WORKER_*` · `DNB_ADMIN_*` · `DNB_ADMINAPI_*` · `DNB_ADMINWRITE_*` · `DNB_RADIUS_*` · `DNB_STAFFAUTH_*` | generated by `install` into `DNB_SECRETS_OUT` |
| device credentials | `DNB_SECRET_KEY` — `SecretBox` refuses to run without it; the read panel does not use it; the worker's real adapter would. Set it (random) so nothing fails closed unexpectedly | set |
| install-time | `DNB_SECRETS_OUT`, `DNB_ROTATE_CREDENTIALS` | `DNB_SECRETS_OUT` set once |
| worker | **`DN_DELIVERY=simulated`** | set |
| identity | `DN_STAFF_IDENTITY` (**unset** → `DenyAllIdentity`, the production/default binding) · `DN_STAFF_REQUIRE_TOTP` (unset) · `DN_TRUSTED_PROXY` (unset in stage 1) · `DN_PORTAL_ORIGIN` (unset in stage 1; the Origin must then match the request's Host) | as stated |
| **development-only — the doctor's `UNSAFE_ENV`** | `DN_DEV_STAFF_IDENTITY` (BLOCKER outside `--disposable`, WARN with it) · `DNB_EXPOSE_OTP` · `DN_ALLOW_REAL_BINDINGS` · `DNB_INSPECT_USER/PASS` | **only `DN_DEV_STAFF_IDENTITY=yes-development-only`**, on the API container; **never** the other three |
| other | `DNB_INTERNAL_TOKEN` (the RADIUS accounting route — unset means it admits nobody; the route is not even served here) | unset |

### 2.4 Identity, TLS and CSRF semantics that shape the deployment

- **Provider selection is explicit and never falls back** (`StaffIdentityFactory`): `DN_STAFF_IDENTITY` unset + dev gate unset → `DenyAllIdentity` (401 everywhere, `POST /session` 501); dev gate exact value → `DevSessionIdentity`; `dishnet` → the real provider; dev gate **and** `dishnet` → **throws** → 500 on every request; the dev identity **throws** if `DN_ALLOW_REAL_BINDINGS` is set.
- **The development identity has no credential.** *"The environment gate IS the credential; the form only selects which ROLE to work as."* It is *"development only, under exactly the conditions … it must not be used where anyone but you can reach it"* (`INSTALL.md`). Its session is a signed, stateless token, **TTL 3600 s**, cookie HttpOnly + SameSite=Strict, `Secure` when the request was TLS **or any `X-Forwarded-Proto: https` header is present** (dev identity only; `docs/114` §N R-9). **Logout clears the cookie but does not revoke** the token. Under it, the `/staff` routes answer 501.
- **`Request::fromGlobals()`** records `https` only when PHP itself terminated TLS (`$_SERVER['HTTPS']` / `REQUEST_SCHEME`) and `ip` from **`REMOTE_ADDR`** — behind a proxy the audit `source` is the proxy's address. The real provider believes `X-Forwarded-Proto` only from `DN_TRUSTED_PROXY`; the staging does not bind the real provider.
- **CSRF is always on**: `api.php` passes no `Csrf`, so `AdminRoutes::build()` uses `Csrf::fromEnvironment()` — a mutating request needs an `Origin` equal to `DN_PORTAL_ORIGIN` or, unset, to its own `Host`; `Sec-Fetch-Site: cross-site` is refused; the body must be JSON. A request with no `Origin` (curl) passes.
- **Responses** carry `Cache-Control: no-store`; static files carry `X-Content-Type-Options: nosniff`. No HSTS, no `X-Frame-Options` — those belong to the reverse proxy in stage 2.
- **Fails loudly, never softly**: a bad provider value or an unreachable staff-auth connection → 500 on every request with one log line; an unreachable Admin read connection → the routes answer 501 *not configured*, never an empty estate.

### 2.5 What the doctor will say about this staging — and why that is correct

`php plugin/bin/plugin.php doctor --disposable` will report `DN_DEV_STAFF_IDENTITY`
as **WARN** (BLOCKER without `--disposable`) and the identity provider as
*DEVELOPMENT-ONLY — a fabricated identity; nobody real can sign in*. **A staging
that inspects the panel through the development identity is a demonstration
install by definition** (`docs/96` §J: *"a demonstration install is possible
today; an operational one is not"*). The WARN is the truth and is recorded, not
silenced. Everything else must read `ok`: PHP version and extensions, the two
required variables, the owner credential, the burned credentials dead, the
cluster requiring a password, the three other `UNSAFE_ENV` variables unset.

---

## 3. The safest deployment method

### 3.1 The three candidates against this host and this package

| | A — EasyPanel service (swarm) | B — standalone PHP behind the existing Traefik | C — the Phase-0 pattern: plain `docker run`, outside the swarm, loopback only |
|---|---|---|---|
| management model | inside the production orchestrator; EasyPanel stores the env (secrets) and rewrites Traefik's dynamic config; a swarm service joins an overlay from the `10.0.0.0/8` pool | containers or host PHP, plus a Traefik router that must be given to Traefik somehow — by EasyPanel, by hand-edited files under EasyPanel's control, or by docker labels the provider may or may not watch | **the mechanism already executed on this host for Domain-B-adjacent components** (`dn-phase0-postgres`, `dn-phase0-radius`, `docs/36`); invisible to EasyPanel and the swarm |
| touches Traefik / DNS / TLS | **yes** (through EasyPanel) — forbidden without approval | **yes**, and the least controlled way if by file or label | **no** |
| public exposure of the dev identity | yes unless EasyPanel adds an access middleware — **VERIFY it can** | yes unless the proxy adds one | **none** — `127.0.0.1:8099`, reached over SSH |
| host packages | none (image) | PHP on the host **or** an image | none on the host; one small image build |
| how the panel is reached | `https://portal-staging.dishnetuganda.com` | same | `ssh -L 8099:127.0.0.1:8099 <server>` then `http://127.0.0.1:8099/` — the path `INSTALL.md` §7 and `docs/96` §H already prescribe |
| `docs/00` §10 / `docs/34` §3.3 | **contradicts** *"do not create an EasyPanel service"* | neutral | **matches** |
| rollback | remove the service through EasyPanel; Traefik config regenerated | remove route + containers | `docker rm -f …`; the host returns to its present state (§12) |

### 3.2 The choice: C now, and the hostname only as an approved second stage

**Recommended: C — three plain containers on a dedicated bridge, outside the
swarm, not in EasyPanel, nothing published except the API on `127.0.0.1:8099`,
reached from your browser over an SSH tunnel.** It is the only option that
meets every prohibition in the request at once — no Traefik, no DNS, no
certificate, no EasyPanel, no new public port, no host package — **and** the
only one under which the development identity is reachable by exactly the
person holding the SSH key, which is the condition its own documentation sets.
It is also what `docs/96` §H wrote for this server and what the disposable
install test exercises end to end (85 checks).

**Stage 2 — `portal-staging.dishnetuganda.com` over HTTPS** is designed in §6.
It is a separate approval, because every HTTPS hostname on this host goes
through the Traefik that EasyPanel owns (80 and 443 are its), and because a
credential-less identity behind a public hostname needs an access layer in
front of it that this audit cannot verify EasyPanel offers.

**Why not host PHP.** VERIFIED 23 Sep (§1.3): the host has PHP 8.3.6 CLI but
**not `pdo_pgsql`** and not `php-fpm`, so a native path needs
`apt-get install php8.3-pgsql` plus systemd units for two processes. That is a
change to the production host's package set; one image that lives and dies
with `docker rm` is not. `docs/34` §3.3's rule for this host is *everything
later in a container*. The native path is recorded as **option B′**, viable
and not recommended.

---

## 4. Exactly what would be created — stage 1

Names carry `dnb-staging-` so nothing can be mistaken for production or for
Phase 0. Nothing joins the swarm; nothing is an EasyPanel service; nothing
listens on a public interface.

| Object | Kind | Definition |
|---|---|---|
| `/opt/dnb-staging/` | directory tree, root-owned | `app/` — the extracted artifact, mounted read-only; `env/runtime.env` (0640: DSN, pepper, secret key), `env/install.env` (0600: the owner credential, given only to the one-shot install containers) and `env/secrets.env` (0600, the seven role passwords, **written by `install`**); `build/Dockerfile` |
| `dnb-staging-php:8.3` | local image, ~90 MB | `FROM php:8.3-cli-alpine` + `apk add postgresql-dev` + `docker-php-ext-install pdo_pgsql`. Built once on the host from a two-line Dockerfile; no application code inside the image (the app is bind-mounted read-only), so the image is generic and disposable |
| `dnb-staging` | Docker **bridge** network (user-defined; Docker isolates it from every other bridge with `DOCKER-ISOLATION-STAGE-2`, as `docs/36` §6.1c measured for `dn-phase0`) | subnet from the `172.x` bridge pool — next free, probably `172.22.0.0/16`; nowhere near `10.66/24` or the overlays |
| `dnb-staging-pgdata` | Docker volume | the only persistent state |
| `dnb-staging-postgres` | container, `postgres:16-alpine` (the image Phase 0 already pulled — VERIFY it is present) | **no published port at all**; reachable only by name on `dnb-staging`; `POSTGRES_PASSWORD` set (so the image's default is `scram-sha-256` for network connections); `-c shared_buffers=64MB`; `--restart unless-stopped` |
| `dnb-staging-api` | container, `dnb-staging-php:8.3` | `php -S 0.0.0.0:8099 plugin/bin/serve.php` inside; published **`127.0.0.1:8099:8099` only**; env from `runtime.env` + `secrets.env` + `DN_DEV_STAFF_IDENTITY=yes-development-only` (**never** the owner credential); app mounted `:ro`; `--restart unless-stopped` |
| `dnb-staging-worker` | container, same image | `php bin/worker.php`; env from `runtime.env` + `secrets.env` + `DN_DELIVERY=simulated`; no dev identity; **no port**; `--restart unless-stopped` |
| **not created** | systemd unit (Docker's restart policy supervises), EasyPanel service, Traefik router, DNS record, certificate, public port, host package, cron, Redis, any change to `wg0`, `dn-phase0-*`, UCRM, UISP, WhatsApp, mail, website | — |

Inside the containers the clock is UTC, the same as the host and every other
container (`docs/34` §3.4). Logs go to Docker's json-file driver; the API logs
exception classes only, never messages that name a relation, and never a
credential (`api.php`).

---

## 5. Database isolation model — mandatory, and how it is met

| Rule from the request | How stage 1 satisfies it | How to verify after deployment |
|---|---|---|
| own PostgreSQL database and roles | a **new instance** (`dnb-staging-postgres`), one database `dnb` owned by `dnb`, the 15 plugin roles created by migrations 001–027 **in that cluster only** | `docker exec dnb-staging-postgres psql -U postgres -Atc "select rolname from pg_roles where rolname like 'dnb%'"` → 16 rows; the same query on **no other** instance is run — their role lists are not touched, so they need no check |
| do not reuse UCRM PostgreSQL | `unms-postgres` is never named in any DSN, network or command | `docker network inspect dnb-staging` lists exactly the three staging containers; `unms-postgres` is on other networks |
| do not add Domain-B tables to UCRM | the migrations run against `DNB_DSN`, which names `dnb-staging-postgres` only | `docker exec dnb-staging-postgres psql -U postgres -d dnb -Atc "select count(*) from mt_migrations"` → 27; nothing is run against `unms-postgres` |
| do not reuse production Redis | Domain B has no Redis dependency (`redis: false`) | no container has `wa_evolution-api-redis` in its environment or network |
| no access to UCRM tables or Domain-A databases | the staging network is a separate bridge; the app knows one host name; `plugin.json` `consumes_domain_a: false` is measured by the suite | `docker inspect dnb-staging-api --format '{{json .NetworkSettings.Networks}}'` shows `dnb-staging` only |
| preserve the security model of migrations 001–027 | they are applied unchanged by `plugin.php install` (the installer refuses to rotate a credential for a role that predates it, which cannot happen on a fresh cluster); `pg_hba` is the image's default `scram-sha-256` for TCP; the doctor's wrong-password control proves it | `install-test.sh`'s role checks, run by hand: no plugin role is superuser or BYPASSRLS; `dnb_adminapi`, `dnb_adminwrite`, `dnb_radius` hold zero table privileges; every `mt_*` table has FORCE RLS |
| the RADIUS instance | `dn-phase0-postgres` (`radius`) is **not** the control-plane database and is not touched; the AAA publisher is unbuilt | its container, network and volume are unchanged in the before/after evidence |

**Why a separate instance and not a separate database on an existing one:**
roles are cluster-wide (`INSTALL.md`), so installing into `unms-postgres` or
`dn-phase0-postgres` would put fifteen `dnb_*` roles beside UCRM's or
FreeRADIUS's, and `uninstall` would drop them cluster-wide. A second
`postgres:16-alpine` container costs tens of megabytes and removes the question.

---

## 6. Hostname and routing — what `portal-staging.dishnetuganda.com` would need (stage 2, NOT now)

Nothing here is done. DNS is **not** assumed to exist (the §1.2 lookup will
show it does not), and nothing creates it.

| Layer | Required configuration | Who owns it on this host |
|---|---|---|
| DNS | an `A` record `portal-staging.dishnetuganda.com → 209.97.137.203`; **Traefik listens on IPv6 too (VERIFIED)**, so whether to add an `AAAA` record is a stage-2 decision | the DNS provider for `dishnetuganda.com`; **the operator creates it, when approved** |
| TLS | a certificate from the ACME resolver Traefik already runs for the other hostnames (`mail-certs-dumper` implies one exists; its **name is unknown — VERIFY from `args=`**) | Traefik / EasyPanel |
| Traefik router | rule `Host(\`portal-staging.dishnetuganda.com\`)`, on the HTTPS entrypoint, `tls.certresolver=<the existing one>`, service → the staging app on port 8099 (or, better for a public hostname, an nginx + php-fpm pair replacing `php -S`, §10). **VERIFIED 23 Sep (§1.3): Traefik 3.6.7 is configured by the file provider — labels are not used on this host.** The mechanism is **one YAML file in `/etc/easypanel/traefik/config/`**, exactly as `uisp.yaml` and `traefik-mail.yml` already route UISP and the mail stack; the entrypoint and resolver names come from `main.yaml`, read only after approval | **Traefik, under EasyPanel's directory** — EasyPanel writes `main.yaml` there; a hand-added file coexists with it today (two do). **Still a Traefik change and therefore its own approval** |
| access control in front | **mandatory before the hostname exists**, because the development identity has no credential: an IP allow-list middleware limited to your address(es) **and** an HTTP basic-auth middleware; whether EasyPanel's UI exposes both is **VERIFY**; if not, stage 2 cannot use the development identity at all | EasyPanel / Traefik |
| path | the panel **must be at the root** of the hostname (`api.js` and `staff.js` call `/api/v1/admin/...` absolutely); a sub-path mount is not supported | — |
| headers | Traefik sets `X-Forwarded-Proto: https`, which makes the dev cookie `Secure`; set `DN_PORTAL_ORIGIN=https://portal-staging.dishnetuganda.com` so a mutating request from any other origin is refused; add HSTS / `X-Frame-Options: DENY` at the proxy | staging env + proxy |
| ports | none new: 443 is already Traefik's and already reachable from the Internet (the sites are served) | — |

**Alternative without Traefik (not recommended):** an nginx container publishing
a second HTTPS port (e.g. 8444) with a self-signed certificate, IP allow-list
and basic auth. It avoids EasyPanel but opens a new public port on a host with
no enforcing firewall, and the browser will warn on the certificate. Listed so
the option space is complete.

---

## 7. Authentication for a private staging inspection

| | Stage 1 (loopback + SSH tunnel) | Stage 2 (hostname) |
|---|---|---|
| binding | `DN_DEV_STAFF_IDENTITY=yes-development-only` on the **API container only**; `DN_STAFF_IDENTITY` **unset** — so the package's default and production posture, `DenyAllIdentity`, is unchanged in code and is what any process without that variable gets (the worker has it unset) | same |
| what you do | open `http://127.0.0.1:8099/` through the tunnel; the login card shows a **role picker** (Admin / NOC / Sales / Support); pick one; the session lasts 3600 s; `Sign out` clears the cookie | same, behind the proxy's basic auth and IP allow-list |
| who can reach it | **exactly the holder of an SSH key to the server** | anyone who passes the proxy's allow-list and basic auth — which is why both are mandatory |
| `staff:bootstrap` | **not run**; the `mt_staff` table stays empty | not run |
| production DishNet Staff authentication | **not enabled** (`DN_STAFF_IDENTITY` unset; the `/staff` routes answer 501 under the dev identity) | not enabled |
| development credentials exposed publicly | **none exist** — the dev identity has no credential; the gate variable lives only in the container's environment, never in the panel, never in a URL | the gate is still not a credential; the proxy's basic-auth password is the credential and is the operator's |
| the doctor | `doctor --disposable` → WARN on the gate; recorded | same |
| forbidden alongside it | `DN_ALLOW_REAL_BINDINGS` (the identity throws), `DN_STAFF_IDENTITY=dishnet` (refuses to coexist → 500 everywhere), `DNB_EXPOSE_OTP`, `DNB_INSPECT_*` | same |

The dev token cannot be revoked before expiry (`docs/114` §A). On a loopback
listener behind SSH that is acceptable; on a hostname it is one more reason the
proxy's own authentication must sit in front.

---

## 8. Network integrations — everything simulated, nothing real

| Integration | Stage 1 and 2 |
|---|---|
| MikroTik | **none.** `DN_DELIVERY=simulated`: `SimulatedRouterOs` answers from memory, tags every result `simulated`, writes no device state or read-back, and refuses to construct if the F6-B gate is set. `DN_ALLOW_REAL_BINDINGS` is **absent**; the REST client refuses its real transport without it (checked at the socket) |
| FreeRADIUS | **none.** No RADIUS client, no `DNB_INTERNAL_TOKEN`, the accounting route is not even served (the customer API front controller is not in the package); the AAA publisher is unbuilt; `dn-phase0-radius` is untouched |
| WireGuard | **none.** No peer, no tunnel address is used; `wg0` is untouched; `SignalReport` keeps the WireGuard signal UNMEASURED and the panel shows *no signal* |
| Domain A / uCRM | **none.** Zero uCRM references in Domain-B `src/` (`docs/110`); `consumes_domain_a: false` |
| the estate you will see | `plugin.php simulate`: 3 operators, 5 routers across five lifecycle states, 17 vouchers (all `unused`), 6 sessions — every identifier `SIM-`, every screen marked SIMULATED, `/health` reporting `delivery_binding: simulated-routeros`, `delivery_simulated: true` |
| what the worker will do | **idle, honestly.** The Admin plane cannot enqueue an intent (the router action route answers 501, `docs/118` D-2) and the customer API is not served, so no intent arrives; the worker loops, expires nothing, and prints its simulated binding at start. Its presence proves the process model, not delivery |

---

## 9. Resource impact

VERIFIED 23 Sep 14:02 UTC (§1.3): 2 cores, **MemAvailable 5,314 MB**, **137 GB
free**, load 0.25, no swap; the twenty running containers hold about 3.3 GB
between them. **Every threshold below is met.** Re-measure with §1.2 on the day
of deployment anyway; the numbers age.

| Component | Estimated steady state | Basis |
|---|---|---|
| `dnb-staging-postgres` (16-alpine, `shared_buffers=64MB`) | 60–150 MB RAM; < 100 MB disk for the simulated estate | idle Alpine PostgreSQL plus a 64 MB buffer pool |
| `dnb-staging-api` (`php -S`) | 20–40 MB RAM; CPU only while you click | single PHP process |
| `dnb-staging-worker` | 20–30 MB RAM; one query every 5 s | `sleep(5)` loop |
| image + layers | ~130 MB disk | `php:8.3-cli-alpine` + `pdo_pgsql` build |
| **total** | **< 400 MB RAM, < 500 MB disk, negligible CPU** | |
| existing PostgreSQL capacity | **zero impact**: separate instance; `unms-postgres`, `wa_evolution-api-db` and `dn-phase0-postgres` receive no connection and no new role | by construction |

**Go / no-go at deployment time:** `MemAvailable ≥ 2 GB`, disk free ≥ 20 GB on
`/var/lib/docker`'s filesystem, 1-minute load below the core count, and every
production container `Up`. Otherwise stop and report.

---

## 10. Security risks and controls

| Risk | Control in this proposal |
|---|---|
| the credential-less development identity reachable by someone else | stage 1: **loopback only**, reached over SSH — no listener on a public interface (verified with `ss -tlnp` and, from outside, a probe of 8099 that must not answer). Stage 2: proxy IP allow-list **and** basic auth, or no stage 2 |
| a real MikroTik or RADIUS contacted by accident | `DN_ALLOW_REAL_BINDINGS` absent; `DN_DELIVERY=simulated`; the simulator, the dev identity and the REST client each refuse independently if the gate appears; the doctor reports the gate variable |
| a production database touched | separate instance, separate bridge, one host name in one DSN; no `--network host`; no published database port; before/after evidence of every other container's networks |
| secrets at rest | `runtime.env` 0640, `install.env` 0600 and `secrets.env` 0600, root-owned, on the host; passed as container environment; the owner credential reaches only the one-shot install containers, never a serving process; **not** in EasyPanel, **not** in the image, **not** in the repository (`docs/97` B-1); the installer prints *where*, never *what*. Hardening option, not required for staging: split `secrets.env` so the API gets only `DNB_ADMINAPI_PASS`/`DNB_ADMINWRITE_PASS` and the worker only `DNB_WORKER_PASS` |
| Docker env-file quoting | `docker run --env-file` keeps quotes literally, so the shell-quoted `DNB_DSN="pgsql:…"` from `.env.example` **must be written unquoted** in the Docker env file (semicolons need no quoting there) — otherwise every connection fails with a DSN that begins with a quote |
| staging mistaken for production (`docs/34` §4.1) | `dnb-staging-` names, `SIM-` data, SIMULATED banners, `delivery_simulated: true`; date the deployment and plan its removal |
| swarm / EasyPanel reconciliation | plain containers outside the swarm are invisible to both (the Phase-0 precedent); restart policy `unless-stopped`; **never** `docker system prune` on this host without listing what it would remove |
| source disclosure through the panel | `serve.php` `realpath` containment (ten traversal paths asserted 404 by the install test); the app is mounted read-only; in stage 2 the web root is `panel/` only and `/api/v1/admin` is the sole PHP route |
| the built-in server on a hostname | not in stage 1 (loopback); stage 2 replaces `php -S` with nginx + php-fpm (`clear_env` must be off or every variable declared in the pool, or `getenv()` sees nothing) |
| dev token not revocable | 3600 s TTL; loopback-only in stage 1 |
| address-pool collision | a bridge from `172.x`, not from the `10.0.0.0/8` overlay pool; `10.66/24` untouched |
| the host firewall | unchanged; **nothing depends on UFW**; the only new listener is on `127.0.0.1` |
| unknown current state | §1.2 before §11; stop on deviation |

---

## 11. Exact deployment steps — WRITTEN, NOT EXECUTED, awaiting approval

Every command below is for the operator, after approval, on the server. Steps
0 and 1 change nothing. **If any check in step 0 deviates from §1.1 in a way
you do not understand, stop.**

```sh
# ── 0. verify, and capture the BEFORE evidence (read-only) ─────────────────
#   run the §1.2 block and keep its output as /root/dnb-staging-evidence/verify-before.txt
docker ps --format '{{.Names}}|{{.Status}}' | sort > /root/dnb-staging-evidence/containers.before
iptables -S | sort > /root/dnb-staging-evidence/iptables.before
ss -tulpn | sort > /root/dnb-staging-evidence/listeners.before
free -m > /root/dnb-staging-evidence/free.before; df -h > /root/dnb-staging-evidence/df.before
#   go/no-go (§9): MemAvailable >= 2 GB, disk free >= 20 GB, all production containers Up,
#   8099 and 5434 unused, portal-staging.dishnetuganda.com does NOT resolve.
#   (all of these held on 2026-09-23 14:02 UTC — §1.3; repeat on the day, do not reuse that run)

# ── 1. copy the artifact (built from e5db549 on the repository machine) ────
#   sh plugin/bin/package.sh  -> dist/dishnet-mikrotik-0.1.0-rc1.tar.gz  (116 files, 212 KB)
#   content digest must read 4e7467ad060c3974aa1f40c093c257eaf8408601612bdcaeead6eb6f916c798e
install -d -m 0750 /opt/dnb-staging/app /opt/dnb-staging/env /opt/dnb-staging/build
tar -xzf dishnet-mikrotik-0.1.0-rc1.tar.gz -C /opt/dnb-staging/app --strip-components=1
( cd /opt/dnb-staging/app && sha256sum -c SHA256SUMS --quiet ) && echo artifact-ok
sha256sum /opt/dnb-staging/app/SHA256SUMS      # must equal the content digest above

# ── 2. the PHP image (a Docker build on the host; no host package) ─────────
cat > /opt/dnb-staging/build/Dockerfile <<'EOF'
FROM php:8.3-cli-alpine
RUN apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql
EOF
docker build -t dnb-staging-php:8.3 /opt/dnb-staging/build
docker run --rm dnb-staging-php:8.3 php -m | grep -E '^(pdo_pgsql|json|openssl)$'   # three lines

# ── 3. the isolated network and the database instance (no published port) ─
docker network create dnb-staging
docker volume create dnb-staging-pgdata
PGSUPER=$(openssl rand -hex 24)                 # the host need not have PHP
docker run -d --name dnb-staging-postgres --network dnb-staging --restart unless-stopped \
  -e POSTGRES_PASSWORD="$PGSUPER" -v dnb-staging-pgdata:/var/lib/postgresql/data \
  postgres:16-alpine -c shared_buffers=64MB
sleep 5; docker logs dnb-staging-postgres --tail 3
docker inspect dnb-staging-postgres --format '{{json .HostConfig.PortBindings}}'   # must be null / {}

# ── 4. bootstrap: the privileged step, read plugin/bin/bootstrap.sql first ─
OWNERPASS=$(openssl rand -hex 24)
docker exec -i -e PGPASSWORD="$PGSUPER" dnb-staging-postgres \
  psql -U postgres -d postgres -v db=dnb -v owner=dnb -v owner_pass="$OWNERPASS" \
  < /opt/dnb-staging/app/plugin/bin/bootstrap.sql
#   expect: owner_role_present 1, database_present 1, owner_can_create_roles t, owner_is_superuser f

# ── 5. configure — Docker env-file syntax: NO quotes, one VAR=value per line ─
#   three files, so the serving processes never carry the owner credential:
#     runtime.env  what every process needs        install.env  the owner, install-time only
#     secrets.env  the 7 role passwords, WRITTEN BY `install` at 0600 (never by hand)
umask 077
cat > /opt/dnb-staging/env/runtime.env <<EOF
DNB_DSN=pgsql:host=dnb-staging-postgres;port=5432;dbname=dnb
DNB_TOKEN_PEPPER=$(openssl rand -hex 32)
DNB_SECRET_KEY=$(openssl rand -hex 32)
EOF
cat > /opt/dnb-staging/env/install.env <<EOF
DNB_OWNER_USER=dnb
DNB_OWNER_PASS=$OWNERPASS
DNB_SECRETS_OUT=/run/dnb/secrets.env
EOF
chmod 0640 /opt/dnb-staging/env/runtime.env; chmod 0600 /opt/dnb-staging/env/install.env
unset OWNERPASS PGSUPER
#   deliberately ABSENT everywhere: DN_ALLOW_REAL_BINDINGS, DN_STAFF_IDENTITY, DNB_EXPOSE_OTP, DNB_INSPECT_*

# ── 6. doctor, install, status — one-shot containers; app read-only; env dir rw so
#       `install` can write secrets.env into it (it appears on the host at 0600)
DNB_RUN='docker run --rm --network dnb-staging -v /opt/dnb-staging/app:/app:ro -v /opt/dnb-staging/env:/run/dnb -w /app --env-file /opt/dnb-staging/env/runtime.env --env-file /opt/dnb-staging/env/install.env'
IMG=dnb-staging-php:8.3
$DNB_RUN $IMG php plugin/bin/plugin.php doctor --disposable
#   before install: BLOCKERs about the missing schema are expected; "cluster requires a password" must read yes
$DNB_RUN $IMG php plugin/bin/plugin.php install
#   writes /opt/dnb-staging/env/secrets.env (7 role passwords, mode 0600) and prints WHERE, never WHAT
$DNB_RUN --env-file /opt/dnb-staging/env/secrets.env $IMG php plugin/bin/plugin.php status
$DNB_RUN --env-file /opt/dnb-staging/env/secrets.env $IMG php plugin/bin/plugin.php doctor --disposable
#   expect every row ok; the identity row reads deny-all (the gate is set only on the API container, step 8).
#   Any BLOCKER: stop.

# ── 7. the simulated estate (refuses if the F6-B gate were set) ─────────────
$DNB_RUN --env-file /opt/dnb-staging/env/secrets.env $IMG php plugin/bin/plugin.php simulate

# ── 8. the API on loopback, with the development identity — runtime + secrets only ─
docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped \
  -p 127.0.0.1:8099:8099 \
  -v /opt/dnb-staging/app:/app:ro -w /app \
  --env-file /opt/dnb-staging/env/runtime.env --env-file /opt/dnb-staging/env/secrets.env \
  -e DN_DEV_STAFF_IDENTITY=yes-development-only \
  $IMG php -S 0.0.0.0:8099 plugin/bin/serve.php

# ── 9. the worker, simulated delivery, no port, no dev identity ─────────────
docker run -d --name dnb-staging-worker --network dnb-staging --restart unless-stopped \
  -v /opt/dnb-staging/app:/app:ro -w /app \
  --env-file /opt/dnb-staging/env/runtime.env --env-file /opt/dnb-staging/env/secrets.env \
  -e DN_DELIVERY=simulated \
  $IMG php bin/worker.php
docker logs dnb-staging-worker --tail 2      # must print "bindingName":"simulated-routeros", delivery_simulated true

# ── 10. verify from the server, then the AFTER evidence ─────────────────────
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8099/                       # 200 (panel)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8099/api/v1/admin/health      # 401 (nobody signed in)
curl -s -o /dev/null -w '%{http_code}\n' --path-as-is http://127.0.0.1:8099/../src/Db/Database.php   # 404
ss -tlnp | grep 8099                          # 127.0.0.1:8099 ONLY — no 0.0.0.0, no [::]
docker ps --format '{{.Names}}|{{.Status}}' | sort > /root/dnb-staging-evidence/containers.after
diff /root/dnb-staging-evidence/containers.before /root/dnb-staging-evidence/containers.after
#   expect EXACTLY three added lines (dnb-staging-postgres, -api, -worker) and no other change
iptables -S | sort | diff /root/dnb-staging-evidence/iptables.before -      # additions only, all scoped to the new bridge / 127.0.0.1:8099
ss -tulpn | sort | diff /root/dnb-staging-evidence/listeners.before -        # one new listener: 127.0.0.1:8099
docker network inspect dnb-staging -f '{{range .Containers}}{{.Name}} {{end}}'   # the three, nothing else
for c in ucrm unms-nginx unms-postgres wa_evolution-api stalwart easypanel-traefik dn-phase0-postgres dn-phase0-radius; do
  docker inspect -f '{{.Name}} {{.State.Status}} started={{.State.StartedAt}}' $c; done   # all running, StartedAt unchanged
wg show | head -3                             # unchanged

# ── 11. from your machine: the browser, over SSH ─────────────────────────────
ssh -N -L 8099:127.0.0.1:8099 <user>@209.97.137.203
#   open http://127.0.0.1:8099/  -> role picker -> Admin -> the SIMULATED estate
```

**From outside the server, after step 10:** a probe of `209.97.137.203:8099`
must not answer (it is bound to loopback). If it answers, roll back (§12)
before anything else.

**Stage 2 (hostname), only after a separate approval:** DNS `A` record →
EasyPanel adds `portal-staging.dishnetuganda.com` routed to the staging app
with the existing ACME resolver, an IP allow-list and basic-auth middleware →
replace `php -S` with nginx + php-fpm (web root `panel/`, `/api/v1/admin` →
`api.php`) → set `DN_PORTAL_ORIGIN=https://portal-staging.dishnetuganda.com` →
verify from outside that the allow-list refuses other addresses. **None of
those steps is written in executable form here on purpose.**

---

## 12. Rollback plan

Stage 1 is removed completely, and the host returns to the state captured in
step 0. No production object is involved in any line.

```sh
docker rm -f dnb-staging-api dnb-staging-worker dnb-staging-postgres
docker network rm dnb-staging
docker volume rm dnb-staging-pgdata                      # destroys the staging database — intended
docker rmi dnb-staging-php:8.3
rm -rf /opt/dnb-staging /root/dnb-staging-evidence        # keep the evidence directory if you want the record
# verify
docker ps --format '{{.Names}}|{{.Status}}' | sort | diff /root/dnb-staging-evidence/containers.before -   # (before rm -rf) identical
iptables -S | sort | diff /root/dnb-staging-evidence/iptables.before -                                     # identical
ss -tulpn | sort | diff /root/dnb-staging-evidence/listeners.before -                                       # identical
```

The `postgres:16-alpine` image stays (Phase 0 uses it). Nothing in `wg0`,
`dn-phase0-*`, EasyPanel, Traefik, DNS or any production container was changed
by stage 1, so nothing there needs reverting. Stage 2's rollback would be:
remove the domain/service through EasyPanel, delete the DNS record.

---

## 13. Confirmation — this audit made ZERO server changes

This session **never connected to the `dishnetuganda` server**: no SSH, no
`psql`, no HTTP request, no DNS query against it, no Docker command on it. The
only commands run were in this development container — reading the repository,
building the artifact into a scratch directory, and the test suite against the
local development cluster. **No package was installed, no container, database,
network, volume, DNS record, certificate, route, firewall rule or port was
created or changed anywhere, and no Domain-B migration was run against any
production database.** The server-state section is the dated record plus a
verification script; it is not a fresh observation.

## 14. What needs your decision

1. **Approve stage 1** as written in §4/§11 (container-based, loopback + SSH,
   development identity, simulated delivery) — or amend it.
2. ~~Run §1.2 and return the output.~~ **DONE 2026-09-23 14:02 UTC** — §1.3:
   baseline confirmed, thresholds met, `postgres:16-alpine` present, host PHP
   present but without `pdo_pgsql`, Traefik file-configured.
3. **Stage 2** — whether `portal-staging.dishnetuganda.com` is wanted at all;
   if so, its Traefik route goes through EasyPanel, needs the DNS record, and
   needs both an IP allow-list and basic auth in front of the development
   identity. That is a separate approval on its own evidence.
4. Nothing else moves: F6-B, `staff:bootstrap`, `DN_STAFF_IDENTITY=dishnet`,
   G-C2, B-3, O-1, G-D, the physical-hardware gate (`docs/119`) and the
   production census (`docs/79`) are all unchanged by this audit.
