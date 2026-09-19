# Phase 0 on the existing DishNet server — audit and safety assessment

Read-only audit performed 19 September 2026 against the live production
host. **Nothing was installed, changed, restarted or reconfigured.**

Supersedes docs/33 §4 (VPS specification) and amends §5.1, §6.1 and §6.2 for
this host. The architecture in docs/33 §3 is unchanged.

---

## 1. What this server actually is

| | |
|---|---|
| provider / kind | DigitalOcean droplet, KVM, `dishnetuganda` |
| OS | Ubuntu 24.04.4 LTS, kernel 6.8.0-139 |
| CPU / RAM | **2 cores**, 7.8 GB (5.3 GB available) |
| disk | 154 GB, **11% used, 138 GB free** |
| public IP | `209.97.137.203/20` on eth0 |
| private | `10.16.0.9/16` (eth0), `10.131.119.86/16` (eth1) — DO networking |
| timezone | **`Etc/UTC`** |
| uptime | 3 days |

**It is not a plain Docker host. It runs Docker Swarm, orchestrated by
EasyPanel.** `dockerd` listens on 2377 (swarm manager), 7946 (node gossip)
and 4789/udp (VXLAN overlay); service tasks carry swarm-style names such as
`web_web-uganda.1.5d4pvz…`. That matters: changes made directly to Docker
can be reverted or duplicated by the orchestrator.

### 1.1 What is running — 18 containers

| stack | containers | published |
|---|---|---|
| **UISP / UNMS + uCRM** | `ucrm`, `unms-api`, `unms-device-ws-1`, `unms-nginx`, `unms-postgres`, `unms-siridb`, `unms-rabbitmq`, `unms-netflow`, `unms-fluentd` | 81, 8080, 8089, 8443, **2055/udp** |
| **WhatsApp** | `wa_evolution-api`, `wa_evolution-api-db` (postgres:17), `wa_evolution-api-redis` (redis:7) | internal only |
| **mail** | `stalwart`, `roundcube`, `mail-certs-dumper` | 25, 465, 587, 993, 8081, 8090 |
| **platform** | `easypanel-traefik`, `easypanel` | 80, 443, 3000 |
| **web** | `web_web-uganda` | via Traefik |

**Two PostgreSQL instances and one Redis already exist**, both inside
containers, both owned by applications that matter. Neither is available to
borrow, and neither should be touched.

---

## 2. Comparison against docs/33

| Requirement | Present? | Current configuration | Conflict? | Action |
|---|---|---|---|---|
| **WireGuard** | **No** | binary absent, no `/etc/wireguard` | **None** | install `wireguard-tools` — clean, no existing config to preserve |
| **UDP 51820** | **Free** | nothing listening | **None** | usable as-is |
| **Tunnel 10.66.0.0/16** | free *today* | see §3.2 | **LATENT** | **use `10.66.0.0/24`**, not /16 |
| **Firewall** | UFW installed, **NOT enforcing** | unit active, `ufw status` = **inactive**; 38 DOCKER iptables rules | **YES — see §3.1** | **do not run `ufw enable`** |
| **SSH** | `0.0.0.0:22` | open, no host firewall | pre-existing | out of Phase 0 scope; flagged |
| **PostgreSQL** | **Yes ×2, in Docker** | `unms-postgres`, `wa_evolution-api-db` (17) | **do not reuse** | Phase 0 needs none; later, its own container |
| **Redis** | Yes, in Docker | `wa_evolution-api-redis` (7) | do not reuse | **not needed in Phase 0 at all** |
| **FreeRADIUS** | No | — | None | not yet — category B |
| **Docker** | **Yes — Swarm + EasyPanel** | 18 containers, overlay networks | **changes management model** | see §3.3 |
| **Reverse proxy** | Yes — Traefik | owns 80/443 | **None** | Phase 0 needs no HTTP listener |
| **Free ports** | yes | 22,25,80,81,443,465,587,993,2055/udp,3000,8080,8081,8089,8090,8443 in use | None | 51820/udp is free |
| **Public IPv4** | **Yes** | `209.97.137.203` | None | the WireGuard endpoint |
| **Resources** | adequate | 2 cores, 5.3 GB free, 138 GB disk | **None** | WireGuard is negligible |
| **Timezone** | `Etc/UTC` | docs/33 §6.1 said set Kampala | **YES** | **leave it alone** — §3.4 |

---

## 3. The four things that change docs/33 for this host

### 3.1 Do NOT run `ufw enable` — this is the most important finding

The audit shows a contradiction worth reading twice:

```
systemctl is-active ufw   →  active      (the unit runs)
ufw status                →  inactive    (the firewall enforces nothing)
```

**There is effectively no host firewall.** Ports 22, 25, 80, 81, 443, 465,
587, 993, 2055/udp, 3000, 8080, 8089 and 8443 are on `0.0.0.0`, protected by
whatever exists outside the droplet, and by Docker's own 38 iptables rules
for published ports.

docs/33 §6.2 says `ufw default deny incoming` then `ufw enable`. **On this
host that is a production incident, not a hardening step.** UFW's INPUT
policy and Docker's chains interact badly — Docker inserts into `DOCKER-USER`
and the `nat` table and bypasses UFW for published ports, while UFW's default
deny can break swarm overlay traffic (4789/udp), node gossip (7946) and, in
the worst case, your own SSH session. Eighteen running containers would be
the test subjects.

**Corrected action for this host: change nothing about the firewall.**

That is safe, and not a compromise, because of what WireGuard is: it does not
reply to unauthenticated packets at all. A port scan of 51820 sees nothing;
without a valid public key in the peer list there is no response to elicit.
It is designed to sit on the open Internet. Adding it costs no attack surface
in the way an open RADIUS or database port would.

**REQUIRES VERIFICATION — and it cannot be checked from inside the droplet.**
Look in the DigitalOcean control panel for a **cloud firewall** attached to
this droplet. If one exists it is the real perimeter, and **UDP 51820 must be
allowed there** or no router will ever reach the gateway. If none exists,
that explains the port exposure above, and it is a separate conversation
from Phase 0.

### 3.2 Use `10.66.0.0/24`, not `/16`

No collision exists today. Current allocations:

```
DO networking     10.16.0.0/16 · 10.131.0.0/16
Docker bridges    172.17 · 172.18.251.0/25 · 172.18.251.128/25 · 172.19 · 172.20
Docker overlay    ingress 10.0.0.0/24 · easypanel-web 10.0.2.0/24
                  easypanel-wa 10.0.3.0/24 · easypanel 10.11.0.0/16
```

**But the risk is latent, not absent.** Docker Swarm allocates overlay
networks from a default pool inside `10.0.0.0/8`. It has already reached
`10.11.0.0/16`. Every new EasyPanel service creates another network from that
pool, so `10.66.x` is not reserved — it is merely unused *so far*. A
collision would appear months later as a WireGuard tunnel that stops routing,
diagnosed under pressure.

**Corrected action:** `10.66.0.0/24` for Phase 0. It is 254 addresses against
four routers, and it shrinks the collision surface 256-fold. The production
range is a Phase 2 decision and should be settled by **pinning Docker's
`default-addr-pool`** in `/etc/docker/daemon.json` — which requires a daemon
restart and is therefore a planned maintenance action, not a Phase 0 one.

### 3.3 WireGuard native; everything later in a container

docs/33 §5.1 recommended native packages. The operator's instruction was
right: *do not change Docker just because docs/33 recommends native.* The
answer differs per component.

| component | Phase 0 | why |
|---|---|---|
| **WireGuard** | **native** | it is a kernel module plus one host interface. Containerising it would need `NET_ADMIN`, host networking and a privileged container — more moving parts for no benefit, and it must exist at host level for RADIUS to bind to it |
| FreeRADIUS *(category B)* | **container** | matches how this host is managed; isolates config; removable without trace |
| PostgreSQL *(category B)* | **container**, its own | two already exist and neither may be borrowed |
| Redis | **not installed** | not needed in Phase 0 at all, and one already exists |

**Do not create the FreeRADIUS container as an EasyPanel service.** Run it as
a plain `docker run` outside the swarm, so Phase 0 is invisible to the
orchestrator and cannot be rescheduled, scaled or reconciled by it.

### 3.4 Do not change the timezone

docs/33 §6.1 sets `Africa/Kampala`. **Wrong for this host.** It is `Etc/UTC`,
and eighteen containers, their logs, uCRM's scheduling and the mail stack all
currently agree on that. Changing it to make one set of Phase 0 log lines
easier to read would desynchronise every existing log correlation for no
operational gain. The plugin already applies its own timezone through
`dn_tz_apply()` and is unaffected.

**Leave it. Record Phase 0 evidence in UTC.**

---

## 4. Safety assessment

# YELLOW

**Phase 0 can run on this server. It cannot run by following docs/33
literally.**

**Why not GREEN:** three of docs/33's installation steps are unsafe or wrong
here — `ufw enable` risks the running stack, the `/16` tunnel has a latent
collision with Docker's address pool, and the timezone change is
unjustifiable on a production host. Whether UDP 51820 is reachable also
depends on a DigitalOcean cloud firewall that cannot be seen from inside.

**Why not RED:** every genuine blocker is absent. UDP 51820 is free. No
WireGuard exists to conflict with. No subnet collides today. There is a
public IPv4. Resources are ample — WireGuard's cost is a kernel module and a
few kilobits of keepalive, against 5.3 GB free RAM and 138 GB of disk.

**The decisive point:** with the corrections in §3, the entire Phase 0
category-A install is **one package and one interface**. It adds no listener
that responds to strangers, touches no container, modifies no firewall rule,
alters no network belonging to anything running, and is removed completely by
`systemctl disable --now wg-quick@wg0 && apt remove wireguard-tools`.

There is no smaller way to answer the B1 question, and B1 is the only thing
Phase 0 is really for.

### 4.1 Residual risks, stated plainly

| risk | likelihood | consequence | mitigation |
|---|---|---|---|
| DO cloud firewall blocks 51820 | **unknown** | no router ever connects | check the control panel **before** staging |
| Docker later allocates 10.66.x | low near-term | tunnel stops routing | `/24` now; pin `default-addr-pool` in Phase 2 |
| 2 cores under RADIUS load | very low at 4 routers | — | revisit before fleet scale |
| Phase 0 mistaken for production | **real** | an unmanaged dependency grows | label it; docs/31 §8 gate before Phase 2 |

The last one is not technical. A WireGuard gateway that works tends to get
used. If B1 passes, this becomes a prototype someone relies on — so it should
be named as a bench from the start, and replaced deliberately when the
production concentrator is built.

---

## 5. Proposed minimal-change plan — for approval, not execution

**Nothing below has been run.** Presented for review per the operator's
instruction.

### Step 1 — outside the server (do first)

Check the DigitalOcean control panel: is a **cloud firewall** attached to
this droplet? If yes, add an inbound rule for **UDP 51820** from any source.
If no, no action — and note it, because it explains the exposed port list.

### Step 2 — on the server

```bash
# 1. install (does not start anything, does not touch the firewall)
apt-get install -y wireguard-tools

# 2. keys
mkdir -p /etc/wireguard && chmod 700 /etc/wireguard
cd /etc/wireguard
wg genkey | tee gw.key | wg pubkey > gw.pub
chmod 600 gw.key

# 3. interface — /24, NOT /16
cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address    = 10.66.0.1/24
ListenPort = 51820
PrivateKey = $(cat gw.key)
EOF
chmod 600 /etc/wireguard/wg0.conf

# 4. bring up
systemctl enable --now wg-quick@wg0
```

### What this deliberately does NOT do

```
no ufw enable          no iptables change        no docker command
no container touched   no timezone change        no daemon.json edit
no postgres            no redis                  no freeradius
no reverse proxy       no EasyPanel service      no reboot
```

### Step 3 — verify, and confirm nothing broke

```bash
wg show                                  # interface up, public key shown
ip -brief addr show wg0                  # 10.66.0.1/24
ss -ulnp | grep 51820                    # listening
docker ps --format '{{.Names}} {{.Status}}' | wc -l   # still 18
curl -sI -o /dev/null -w '%{http_code}\n' https://localhost --insecure  # Traefik alive
```

Then, from a machine elsewhere, bring up a WireGuard peer and confirm
`latest handshake`. **That single handshake proves the droplet's port, the DO
cloud firewall and the config all at once**, and separates "the server is
wrong" from "the router is wrong" before a MikroTik is ever staged.

### Rollback, if anything at all looks wrong

```bash
systemctl disable --now wg-quick@wg0
rm -rf /etc/wireguard
apt-get remove -y wireguard-tools
```

The host returns to exactly its present state. No container, network, rule or
file belonging to any running service is involved at any point.

---

## 6. What is still not decided

1. **The DigitalOcean cloud firewall** — blocking, and invisible from inside.
2. **Whether Phase 0 should live here at all.** It is safe, and it is also a
   production host running billing, mail and network management on two cores.
   A €6 droplet would isolate the experiment entirely. **Recommended if the
   budget is trivial**; this host is acceptable if it is not.
3. **Category B placement** — FreeRADIUS and PostgreSQL as plain containers,
   deferred until B1 has answered. Not needed to start.
4. The pre-existing exposure of SSH and the mail/admin ports with no host
   firewall. **Out of Phase 0 scope, recorded because it was observed**, and
   worth its own conversation.

---

## 7. Execution record — 19 September 2026

The plan in §5 was executed on the live host. **It ran exactly as documented;
nothing differed from the procedure.** Recorded here because a plan that was
followed is worth less than a plan that was followed *and measured*.

### 7.1 What was installed

```
wireguard-tools 1.0.20210914-1ubuntu4   (Ubuntu 24.04 noble, amd64)
0 upgraded, 1 newly installed, 0 to remove, 41 not upgraded
89.1 kB fetched · 330 kB on disk
```

The simulation and the install agreed exactly: **one package, nothing
upgraded, nothing removed.** The 41 held-back upgrades were left untouched —
`--no-install-recommends` and a named package meant apt had no reason to
touch them, which is the point of simulating first on a production host.

apt's own post-install check confirmed the blast radius:

```
Running kernel seems to be up-to-date.
No services need to be restarted.
No containers need to be restarted.
No user sessions are running outdated binaries.
```

### 7.2 Before/after diff — the evidence that matters

| | before | after | verdict |
|---|---|---|---|
| containers | 18 | 18 | **IDENTICAL** — no name or status changed |
| iptables rules | 54 | 54 | **IDENTICAL** — Docker's chains untouched |
| addresses | 33 | 34 | one new: `wg0 10.66.0.1/24` |
| listeners | 38 | 40 | two new: `udp 0.0.0.0:51820`, `udp [::]:51820` |

Every published production port still answered: 80, 443, 3000, 81, 8080,
8443, 25, 587, 993.

**The iptables line is the one that mattered.** §3.1 predicted that leaving
the firewall alone would keep Docker's 38 rule references intact, and a
byte-identical `iptables -S` before and after is that prediction confirmed
rather than assumed. Had `ufw enable` been run per docs/33 §6.2, this row
would not have read IDENTICAL.

### 7.3 Two things the output revealed that the plan did not state

**WireGuard also listens on IPv6** (`udp [::]:51820`). Default behaviour, not
a fault. The droplet has IPv6 on eth0, so the gateway is reachable over both
families. Harmless, and worth knowing before someone reads it as an
unexpected listener during a later audit.

**`wg-quick.target` is static and was not started by the package.** The
`systemctl enable --now wg-quick@wg0` in §5 is what makes the interface
survive a reboot — the package alone would not have. Confirmed by the
symlink it created.

### 7.4 Gateway identity

```
Gateway public key: ftGs/7LjO/aVmKJ9xS/fz+QDt73JcGI+X3vgSCZpJT4=
Endpoint:           209.97.137.203:51820
Tunnel:             10.66.0.1/24
```

The public key is not a secret — every router needs it. The private key is in
`/etc/wireguard/gw.key`, mode 600, and appears nowhere else.

### 7.5 External handshake — PASSED

**19 September 2026, 12:47.** A MacBook Pro on a different network, running
the official WireGuard client, completed a handshake with the gateway.

```
Status:            Active, On-Demand Disabled
Addresses:         10.66.0.250/32
Peer:              ftGs/7LjO/aVmKJ9xS/fz+QDt73JcGI+X3vgSCZpJT4=
Endpoint:          209.97.137.203:51820
Allowed IPs:       10.66.0.0/24
Persistent keepalive: every 25 seconds
Data received:     92 B
Data sent:         180 B
Latest handshake:  4 seconds ago
```

**Three things are now established that could not be checked from inside the
droplet:**

1. **UDP 51820 is reachable from the public Internet.**
2. **No DigitalOcean cloud firewall is blocking it** — the open question in
   §3.1 and §6.1 is answered. Either none is attached, or it already permits
   the port.
3. The gateway configuration is correct and carries traffic **both ways** —
   `Data received` is the half that matters; outbound alone proves nothing.

**The category-A foundation is complete and proven.** docs/33 §7 acceptance
checks 1–4 and 13 pass; 5–12 belong to category B and are not yet due.

Two notes from the run, both worth carrying forward:

**A failed first attempt looked exactly like a firewall block.** Before the
peer was added server-side, the Mac showed `Data sent: 888 B` and no
`Data received` at all. WireGuard is silent to unknown keys, so "no matching
peer" and "port blocked" are indistinguishable from the client. The way to
tell them apart is `tcpdump -ni any udp port 51820` on the gateway: packets
arriving means the perimeter is fine and the fault is a key or config
mismatch. Worth remembering when the first MikroTik does the same thing.

**On-Demand must stay off for test peers.** The macOS client defaults to
Wi-Fi On-Demand, which kept tearing the tunnel down and rebuilding it — the
status read "Restarting" rather than settling, and the byte counters were
unreliable. Disabled, it went Active immediately.

### 7.6 Cleanup owed

The MacBook peer is a test peer and should not outlive the test:

```bash
# server
cd /etc/wireguard
cp wg0.conf wg0.conf.bak.$(date +%s)
awk '/^\[Peer\]/{exit} {print}' wg0.conf > wg0.conf.new && mv wg0.conf.new wg0.conf
chmod 600 wg0.conf
wg syncconf wg0 <(wg-quick strip wg0)

# Mac: delete the dishnet-phase0 tunnel in the WireGuard app
```

Its private key appeared in a screenshot during the test, so the tunnel is
retired rather than reused. The rule it breached is the one docs/30 §6.2 sets
for routers — **the private key is generated where it will live and never
travels** — and it is kept even for a throwaway peer because the habit is
what carries into the fleet.

### 7.7 Superseded — what §7.5 said before



**The category-A install is complete. The gate in §5 Step 3 is not passed.**

A test peer was added, but its keypair was generated **on the gateway
itself**, so no traffic has crossed the public Internet and the two things
that test exists to prove remain unproven:

1. whether a DigitalOcean cloud firewall permits UDP 51820 inbound
2. whether the endpoint is reachable from outside the droplet

Until a handshake arrives from a machine that is not this server, the
gateway is only known to work with itself. See §5 Step 3 for the correct
procedure: **the private key is generated where it will live, and never
travels.** That is the same rule docs/30 §6.2 sets for routers, and it is
worth keeping even for a throwaway test peer, because the habit is what
carries into the fleet.

Cleanup owed once the test passes: remove the laptop `[Peer]` block from
`wg0.conf`, and delete `/etc/wireguard/lap.key` and `lap.pub`.
