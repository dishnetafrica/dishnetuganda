# Phase 0 — Server Foundation

Companion to docs/30–32. Answers one question: **given a fresh VPS today,
what is installed, in what order, so that tomorrow a MikroTik on Starlink
Residential can begin Phase 0?**

**No DishNet application code is written or installed here.** There is no
control plane, no schema beyond what FreeRADIUS itself requires, and no
multi-tenant anything. This is the other half of the hardware test bench.

---

## 1. The short answer

**Derived from the tests, not from the component list.** Reading docs/31
Tests A–E and asking what each actually consumes:

| test | what it needs on the server |
|---|---|
| A1–A8 staging, tunnel, config push | **WireGuard only** (plus `ping`, `curl`) |
| **B1/B2 tunnel + idle reach** | **WireGuard only** |
| B.mtu | WireGuard only |
| C1–C5 interruption | WireGuard only |
| D watchdog | WireGuard only |
| E reset | WireGuard only |
| **A9–A14 hotspot, voucher, accounting** | FreeRADIUS + a database |
| E6 RadSec | FreeRADIUS + certificates |

**Seven of the eight need nothing but WireGuard.**

Including B1 idle-reach — the test that decides whether the management plane
is push or poll, and therefore shapes everything in Phase 2.

### 1.1 A refinement to the proposed sequence

The operator's sequence runs Test A, then Test B. **Split A instead:**

```
Day 1   VPS → firewall → WireGuard            (≈ 1 hour)
Day 1   Stage one router (docs/32 Step 0 first)
Day 1   Test A1–A8  — tunnel up, config pushed over it
Day 1   Test B1 starts — 30 min, 2 h, then LEAVE IT OVERNIGHT
        ────────────────────────────────────────────────────
Day 2   Read the overnight result. THE ARCHITECTURAL ANSWER.
Day 2   Install PostgreSQL + FreeRADIUS       (≈ 2 hours)
Day 2   Test A9–A14 — hotspot, voucher, accounting, expiry
Day 3+  Tests C, D, E, and E6 if wanted
```

**Why this ordering is better.** The overnight idle test is dead time — it
must elapse regardless. Starting it on day one means the answer is waiting
on day two, rather than two days later. And if B1 fails, you learn it before
installing FreeRADIUS, having spent one hour instead of three.

It also honours the instruction not to install application components before
the hardware architecture is proven: **on day one there is nothing on the
server but a kernel module and a firewall.**

---

## 2. Categories

### A — REQUIRED before the first MikroTik test

| component | why | note |
|---|---|---|
| **Linux, Debian 12 or Ubuntu 24.04 LTS** | host | both carry in-kernel WireGuard |
| **nftables or ufw** | only two ports may be open | done *before* WireGuard |
| **wireguard-tools** | the entire management transport | the kernel module is already present |
| **the idle probe** (§6.7, ~10 lines) | B1 overnight evidence without staying awake | not a monitoring stack |

That is the whole of category A. **One package and a firewall.**

### B — REQUIRED later in Phase 0

| component | needed for | when |
|---|---|---|
| **PostgreSQL** | `radacct` — accounting evidence in production shape | before A12 |
| **FreeRADIUS** (3.0.x acceptable, 3.2 preferred) | A10 auth, A12/A14 accounting | before A10 |
| FreeRADIUS PostgreSQL schema | ships with the package; applied as-is | with FreeRADIUS |
| one test voucher, one NAS entry | A10 | with FreeRADIUS |
| RadSec certificates | **only if running E6** | optional |
| a DNS name for the gateway | see §5.3 | recommended, not required |

**PostgreSQL is in B, not A, and is arguably optional even there.**
FreeRADIUS can authenticate from a flat `users` file and write accounting to
detail files, which would prove the hardware chain. PostgreSQL is
recommended anyway because it is ~30 minutes, the schema ships with the
package, and it puts the accounting evidence in the shape docs/30 §3
proposes — so an attribute that lands in the wrong column is discovered now
rather than in Phase 3. **If PostgreSQL setup stalls, fall back to flat
files and keep moving. It must never block B1.**

### C — NOT required yet

Explicitly postponed. Installing any of these before the gate in docs/31 §8
is work done against an unproven assumption.

| not now | why not |
|---|---|
| **Redis** | docs/30 uses it for live sessions, pairing tokens, rate limits, CoA queue. Phase 0 has no application, no pairing (pre-staged), no rate limiting. **Nothing would be in it.** |
| Nginx / Traefik | nothing to reverse-proxy. The control plane does not exist; REST lives on the router and is reached over the tunnel |
| public TLS certificates | no public HTTPS service. Router-side `www-ssl` uses a self-signed cert and is a docs/32 step, not a server one |
| Docker / Compose | see §5.4 |
| Kubernetes, HA, clustering | one VPS, four routers |
| monitoring stack | `journalctl`, `wg show` and the §6.7 probe are sufficient |
| systematic backup | nothing here is precious. Runbook corrections live in git; evidence is scp'd off |
| control plane, device registry, provisioning service | **Phase 2.** Building it now would encode B1's answer before B1 has given one |
| customer portal, reseller portal, mobile app, voucher UI | Phase 3+ |
| uCRM integration, billing, tenancy, multi-tenant code | docs/30 Phase 1 and 4 — independent of this bench |

---

## 3. Phase 0 server architecture

```
   ┌────────────── MikroTik (customer site, behind CGNAT) ─────────────┐
   │  HotSpot ── RADIUS client ──┐         REST /rest (www-ssl)        │
   │  WireGuard wg-dishnet ──────┼──────────────── outbound, UDP ──────┼──┐
   └─────────────────────────────┴─────────────────────────────────────┘  │
                                                                          │
   ═══════════════ CGNAT · Starlink Residential ═══════════════════════════
                                                                          │
   ┌──────────────────── VPS ─────────────────────────────────────────────┴─┐
   │  eth0  public  ── UDP 51820 WireGuard  ◄── the only inbound service     │
   │                └─ TCP 22 SSH (restricted)                              │
   │                                                                        │
   │  wg0   10.66.0.1/16  ── the management network                         │
   │        ├── operator: ping, curl → router's /rest         (outbound)    │
   │        └── FreeRADIUS listening ONLY on 10.66.0.1        (inbound)     │
   │              UDP 1812 auth · UDP 1813 acct                             │
   │                          │                                             │
   │                          ▼                                             │
   │             PostgreSQL on 127.0.0.1:5432 only                          │
   │             radcheck · radreply · radacct                              │
   └────────────────────────────────────────────────────────────────────────┘
```

### 3.1 Who talks to whom

| from | to | interface | direction |
|---|---|---|---|
| MikroTik | VPS :51820/udp | public | **outbound from the router** — this is what makes CGNAT survivable |
| operator | router `/rest` | wg0 | outbound over the tunnel |
| MikroTik RADIUS client | FreeRADIUS 10.66.0.1:1812/1813 | wg0 | inside the tunnel |
| FreeRADIUS | PostgreSQL 127.0.0.1:5432 | loopback | never leaves the host |
| FreeRADIUS (CoA) | router :3799 | wg0 | **server-initiated — this is what B1 tests** |

### 3.2 What is exposed publicly

**One UDP port. Nothing else, apart from SSH.**

- **Not** RADIUS. It listens on the tunnel address only. A RADIUS server on
  `0.0.0.0` is a shared secret exposed to the Internet, and FreeRADIUS
  defaults to listening on `*` — §6.5 changes that deliberately.
- **Not** PostgreSQL. Loopback only.
- **Not** REST. REST is on the *router*, reached through the tunnel.
  docs/32 gate item 7 confirms it is unreachable from the WAN.

### 3.3 RADIUS runs inside the tunnel for Phase 0

Per docs/30 §5.1, with the coupling acknowledged: if the tunnel drops,
hotspot logins stop. Acceptable on a bench, not in production — which is
why docs/30 keeps AAA transport a per-device setting so RadSec can be
adopted later without re-staging.

**Consequence for the router config:** its RADIUS server address is
`10.66.0.1`, not a public address. Worth stating because it is easy to
stage a public address by habit and only discover the exposure later.

---

## 4. Minimum VPS specification

A proof environment for four routers and a handful of sessions.

| | Phase 0 | note |
|---|---|---|
| CPU | **1–2 vCPU** | WireGuard is in-kernel; FreeRADIUS at this volume is idle |
| RAM | **2 GB** | 1 GB works; 2 GB is comfortable with PostgreSQL |
| Disk | **20–25 GB SSD** | OS, packages, a small `radacct` |
| **Public IPv4** | **required** | the WireGuard endpoint must be reachable. **The one hard requirement** |
| IPv6 | not required | |
| Bandwidth | **trivial** | see below |
| OS | Debian 12 / Ubuntu 24.04 LTS | |

**Bandwidth is trivial and that is a design property, not luck.** The tunnel
carries management traffic and RADIUS only — **customer Internet traffic
never traverses it**, per the operator's instruction in the brief. A managed
router costs a few kilobits of keepalive, not its subscribers' throughput.
This is worth remembering when the production concentrator is sized: it
scales with *device count*, not traffic.

### 4.1 Ports

| port | proto | exposure | for |
|---|---|---|---|
| 51820 | UDP | **public** | WireGuard |
| 22 | TCP | public, **restricted to known source IPs** | SSH |
| 1812, 1813 | UDP | **wg0 only** | RADIUS auth/acct |
| 2083 | TCP | wg0 only, **only if E6** | RadSec |
| 5432 | TCP | **127.0.0.1 only** | PostgreSQL |
| everything else | | **closed** | |

---

## 5. Decisions and their reasons

### 5.1 Native packages, not Docker

**Recommended: install natively.** Phase 0 is a debugging exercise. When
FreeRADIUS rejects a MikroTik request, the first move is `freeradius -X` in
the foreground reading live output; when the tunnel misbehaves, it is
`wg show` and `journalctl -u wg-quick@wg0`. A container layer between the
operator and those is friction at exactly the wrong moment.

Reproducibility comes from **this document** — the commands are the record.
Containerise in Phase 2 when the stack stops changing daily.

### 5.2 FreeRADIUS version

docs/30 proposed 3.2. **3.0.x is acceptable for Phase 0** — it authenticates,
accounts, and supports TLS home servers. Record what `apt policy freeradius`
offers and note it in the evidence. Nothing in Tests A–E needs 3.2
specifically; if the distro ships it, take it.

### 5.3 A DNS name for the gateway

**Recommended, not required.** A WireGuard peer can use a bare IP, and for
four routers re-staging on an IP change is trivial. But every staged router
carries the endpoint, so an IP change later means touching every device in
the field — and at 200 units that is not trivial.

Costs nothing now: `mgmt.dishnet…` pointed at the VPS, staged as a name from
the first unit.

**REQUIRES VERIFICATION** (docs/32 Step 0.2): does RouterOS accept a DNS name
in `endpoint-address`, and does it **re-resolve** after the address changes?
If it resolves once at boot only, the benefit is smaller than it looks.

### 5.4 Nothing application-shaped gets installed

Worth stating plainly because the temptation will be strong once the tunnel
works: **do not begin the control plane.** Its design depends on B1's answer.
Push-based and poll-based provisioning orchestrators are different programs,
and writing either before the test has answered is the assumption-encoding
failure these documents exist to avoid.

---

## 6. Installation

Debian 12 / Ubuntu 24.04. Run as root or with `sudo`.
**Commands here are ordinary Linux, not RouterOS — but the same rule applies:
if one errors, record it verbatim and correct this file.**

### 6.1 Harden the OS

```bash
apt update && apt upgrade -y
apt install -y ufw curl wireguard-tools

# SSH keys only
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

timedatectl set-timezone Africa/Kampala   # matches the plugin's timezone
```

### 6.2 Firewall — before WireGuard, not after

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow from <YOUR-OFFICE-IP> to any port 22 proto tcp   # restrict SSH
ufw allow 51820/udp                                        # WireGuard
ufw enable
ufw status verbose
```

Closed first, opened deliberately. If the office IP is dynamic, use
`ufw allow 22/tcp` and note it as a Phase 0 compromise to fix before
anything real runs here.

### 6.3 WireGuard — category A ends here

```bash
mkdir -p /etc/wireguard && chmod 700 /etc/wireguard
cd /etc/wireguard
wg genkey | tee gw.key | wg pubkey > gw.pub
chmod 600 gw.key

cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address    = 10.66.0.1/16
ListenPort = 51820
PrivateKey = $(cat gw.key)
EOF
chmod 600 /etc/wireguard/wg0.conf

systemctl enable --now wg-quick@wg0
wg show
```

**Do not use 192.168.88.0/24** — the MikroTik LAN default; it will collide.

Per staged router, append and reload:

```bash
cat >> /etc/wireguard/wg0.conf <<EOF

[Peer]
# unit 1 — serial <SERIAL>
PublicKey  = <router public key>
AllowedIPs = 10.66.0.11/32
EOF
systemctl restart wg-quick@wg0
```

Confirm the port is open **from another host**:

```bash
nmap -sU -p 51820 <VPS-IP>        # open|filtered is expected for UDP
```

UDP gives a weak answer. **The real proof is a handshake** — bring up a
WireGuard peer from a laptop and check `wg show` reports
`latest handshake`. Do that before staging a router; it separates "my
firewall is wrong" from "the router is wrong".

**Category A is now complete. Tests A1–A8, B, C, D and E can all run.**

### 6.4 PostgreSQL — category B

```bash
apt install -y postgresql
systemctl enable --now postgresql

sudo -u postgres psql <<'EOF'
CREATE USER radius WITH PASSWORD '<generated>';
CREATE DATABASE radius OWNER radius;
EOF

# loopback only — confirm, do not assume
ss -tulpn | grep 5432
```

Expect `127.0.0.1:5432`. If it shows `0.0.0.0:5432`, set
`listen_addresses = 'localhost'` in `postgresql.conf` and restart.

### 6.5 FreeRADIUS — category B

```bash
apt install -y freeradius freeradius-postgresql freeradius-utils
apt policy freeradius        # RECORD THE VERSION in the evidence

# the schema ships with the package — apply it as-is, do not hand-write one
zcat /usr/share/doc/freeradius-postgresql/schema.sql.gz 2>/dev/null \
  || cat /etc/freeradius/3.0/mods-config/sql/main/postgresql/schema.sql
# then: sudo -u postgres psql radius < <that file>
```

**Path REQUIRES VERIFICATION** — it moves between distributions and versions.
Locate it with `dpkg -L freeradius-postgresql | grep schema`.

Then:

```bash
cd /etc/freeradius/3.0                # or 3.2
ln -s ../mods-available/sql mods-enabled/sql
# mods-available/sql: driver rlm_sql_postgresql, dialect postgresql,
#                     server localhost, login/password/radius_db
```

**Bind RADIUS to the tunnel only** — this is the security step, and the
default is wrong for our purposes. In `sites-available/default`, both
listeners:

```
listen {
    type = auth
    ipaddr = 10.66.0.1      # NOT *, NOT 0.0.0.0
    port = 1812
}
listen {
    type = acct
    ipaddr = 10.66.0.1
    port = 1813
}
```

The router as a NAS, in `clients.conf` — its **tunnel** address:

```
client dn-unit-1 {
    ipaddr = 10.66.0.11
    secret = <generated, unique per device>
    shortname = dn-unit-1
    nas_type = other
}
```

One secret per device. A fleet-wide secret means one compromised router
compromises all of them.

```bash
systemctl enable --now freeradius
ss -ulpn | grep -E '1812|1813'     # must show 10.66.0.1, NOT 0.0.0.0
```

### 6.6 A test voucher

Minimum to prove A10–A14. Not a voucher system — two rows.

```sql
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('t1-TESTCODE01', 'Cleartext-Password', ':=', 't1-TESTCODE01');

INSERT INTO radreply (username, attribute, op, value) VALUES
  ('t1-TESTCODE01', 'Mikrotik-Rate-Limit',    '=',  '5M/5M'),
  ('t1-TESTCODE01', 'Session-Timeout',        '=',  '3600'),
  ('t1-TESTCODE01', 'Acct-Interim-Interval',  '=',  '300');
```

The `t1-` prefix is the tenant namespace from docs/30 §5 — used from the
first row so the convention is never retrofitted.

For A13 (automatic expiry) add `Expiration` a few minutes out and watch
access stop unaided. **That is the test**, not the timeout.

### 6.7 The idle probe — category A

B1 requires evidence at 30 min, 2 h and overnight. Ten lines beats staying
awake, and produces a timestamped record rather than a recollection.

```bash
cat > /usr/local/bin/b1-idle-probe.sh <<'EOF'
#!/bin/bash
# Phase 0 Test B1: can the GATEWAY reach the router after idle?
# Does not keep the tunnel warm — one probe, widely spaced.
TARGET="${1:?usage: b1-idle-probe.sh <router-tunnel-ip>}"
LOG=/var/log/b1-idle-probe.log
if ping -c1 -W3 "$TARGET" >/dev/null 2>&1; then R=REACHABLE; else R=UNREACHABLE; fi
HS=$(wg show wg0 latest-handshakes | awk '{print $2}' | head -1)
echo "$(date -Is) $TARGET $R last_handshake_epoch=$HS" >> "$LOG"
EOF
chmod +x /usr/local/bin/b1-idle-probe.sh

# every 30 minutes
echo "*/30 * * * * root /usr/local/bin/b1-idle-probe.sh 10.66.0.11" \
  > /etc/cron.d/b1-idle-probe
```

**A single ping every 30 minutes does not hold a NAT mapping open** the way
`persistent-keepalive` at 25s does — the gap is what is being measured.
`UNREACHABLE` lines followed by `REACHABLE` after the router transmits is
precisely the failure signature that means **poll, not push**.

---

## 7. Server acceptance test

**Run before connecting the first MikroTik.** A failure here would otherwise
be misdiagnosed as a router fault, and that is an expensive confusion.

| # | check | command | pass |
|---|---|---|---|
| 1 | WireGuard up | `wg show` | interface listed, key shown |
| 2 | service persists a reboot | `reboot`, then `wg show` | still up |
| 3 | **UDP port reachable** | WireGuard peer from a laptop elsewhere | **`latest handshake` appears** |
| 4 | tunnel addressing | `ip addr show wg0` | `10.66.0.1/16` |
| 5 | PostgreSQL up | `systemctl is-active postgresql` | `active` |
| 6 | PostgreSQL private | `ss -tulpn \| grep 5432` | `127.0.0.1` only |
| 7 | FreeRADIUS up | `systemctl is-active freeradius` | `active` |
| 8 | **RADIUS on the tunnel only** | `ss -ulpn \| grep 181` | `10.66.0.1`, **not `0.0.0.0`** |
| 9 | **auth works** | `radtest t1-TESTCODE01 t1-TESTCODE01 10.66.0.1 0 <secret>` | `Access-Accept` |
| 10 | attributes returned | same output | rate-limit and session-timeout present |
| 11 | **accounting works** | `radclient` Accounting-Start, then query `radacct` | a row appears |
| 12 | accounting closes | Accounting-Stop | row updated with stop time |
| 13 | **no unintended exposure** | `nmap -Pn <VPS-IP>` **from outside** | only 22 (and 51820/udp) |
| 14 | RADIUS not public | `radtest` against the **public** IP | **must fail / time out** |
| 15 | idle probe logging | `cat /var/log/b1-idle-probe.log` | timestamped lines |

**Checks 8, 13 and 14 are the security gate.** A `0.0.0.0` RADIUS listener
puts a shared secret on the public Internet, and it is the default. Confirm
14 from outside the VPS, not from the VPS.

### 7.1 Evidence

```
════════════════════════════════════════════════════════════
PHASE 0 SERVER ACCEPTANCE     date ______  operator ______
════════════════════════════════════════════════════════════
VPS provider/region ____________  public IPv4 ____________
OS ____________  kernel ____________
WireGuard (wg --version) ____________
PostgreSQL ____________  FreeRADIUS (apt policy) ____________
Gateway DNS name (if used) ____________
Tunnel subnet 10.66.0.0/16    gateway 10.66.0.1

  1 __  2 __  3 __  4 __  5 __  6 __  7 __  8 __
  9 __ 10 __ 11 __ 12 __ 13 __ 14 __ 15 __

nmap from outside — ports seen: __________________________
SERVER READY FOR FIRST MIKROTIK:   YES / NO
Blocking: _________________________________________________
════════════════════════════════════════════════════════════
```

**Never record:** the WireGuard private key, RADIUS secrets, or the
PostgreSQL password. Record where they are stored.

---

## 8. Ordered plan — fresh VPS to first router

> *"If I have a fresh VPS today, what do I install first, second, third, so
> that tomorrow I can put one MikroTik on Starlink Residential and begin?"*

### Today — about 90 minutes

```
 1. Provision the VPS. Debian 12 / Ubuntu 24.04, 2 vCPU, 2 GB, public IPv4.
 2. §6.1  harden — updates, SSH keys only, timezone
 3. §6.2  firewall — deny inbound, allow SSH (restricted) + 51820/udp
 4. §6.3  WireGuard — keys, wg0 at 10.66.0.1/16, enable, verify
 5. Acceptance checks 1–4 and 13. STOP if 13 shows anything unexpected.
 6. §6.7  install the idle probe (do not point it anywhere yet)
 7. (optional, 10 min) DNS name → VPS, and use it when staging
 8. In parallel, on the bench: docs/32 STEP 0 on one router.
    That is an hour of work needing no server at all.
```

**The server is now ready for its first router. Nothing else is installed.**

### Tomorrow — first router

```
 9. Stage the router per docs/31 §3, using the Step 0 syntax YOU confirmed.
10. Add its [Peer] to wg0.conf (§6.3). Restart. Assign 10.66.0.11.
11. Connect WAN to the office line. Run Test A1–A8.
12. Move it to Starlink Residential. Confirm the tunnel returns.
13. Point the idle probe at 10.66.0.11.
14. START THE IDLE TEST AND WALK AWAY.  ← the architectural gate
```

### Day after — the answer, then the rest

```
15. Read /var/log/b1-idle-probe.log.
       all REACHABLE  → push-based management is viable. Continue.
       any UNREACHABLE at 30m/2h/overnight
                      → STOP. Management is poll-based. docs/30 §4.2
                        Phase 2 is redesigned BEFORE anything is built.
16. §6.4 PostgreSQL · §6.5 FreeRADIUS · §6.6 test voucher   (~2 hours)
17. Acceptance checks 5–12, 14, 15.
18. Test A9–A14 — hotspot, voucher, RADIUS, accounting, expiry.
19. Tests C, D, E. E6 only if RadSec is wanted early.
20. docs/31 §8 gate. Only then does Phase 2 design begin.
```

### What is deliberately absent

No Redis. No reverse proxy. No public TLS. No Docker. No monitoring stack.
No control plane, registry, provisioning service, portal or tenancy code.
**Not one line of DishNet application code.**

Everything in docs/30 Phases 2–4 waits on step 15. That is the whole point:
the server foundation exists to get an answer out of hardware, and the
platform is designed around what the hardware proves rather than around
what we assumed it would.
