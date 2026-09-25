# 119 — Physical MikroTik test handoff: the HARDWARE VERIFIED gate

**Status: HANDOFF — documentation only. Nothing was implemented, no migration
was added (migrations still end at 027, no `028*` exists), no production
system, database, FreeRADIUS configuration or router was touched, and no
physical MikroTik was available while this was written. Every command below
is an UNVERIFIED proposal until a unit accepts it. Nothing in this document is
HARDWARE VERIFIED, and writing a test does not verify it.**

Written 2026-09-23 after G-C was accepted at `9a09cc7`. G-B proved *who is
DishNet staff*, T-2 *what an operator's people may do*, G-C *how an authorised
action would reach a router* — all in software. The fourth layer,
`physical MikroTik → WireGuard → RouterOS REST → HotSpot → RADIUS → accounting`,
cannot be proved from a development database. This document is what the
DishNet engineer holds at the bench when a **dedicated, DishNet-owned** test
unit is available. It adds nothing to `docs/31`/`docs/32`; it collects, in one
checklist, every item the repository still labels *HARDWARE VERIFIED pending*
and says exactly what result would settle each one.

---

## 0. Read this first

### 0.1 The rules that bind the session

| Rule | Source |
|---|---|
| **Every RouterOS command here is UNVERIFIED** — a proposal to be confirmed on your unit, never known-good procedure. When one is wrong, record what you ran and what it said verbatim, correct the source file (`docs/32`, `docs/35`) and commit the correction. *"Didn't work" is not a finding; the error text is.* | `docs/32` Prime Directive, *When a command is wrong* |
| **Step 0 first.** Do not begin Test A until `docs/32` §0.5's gate holds: every inventory row OK or substituted, argument spellings confirmed and written down, export/restore confirmed, the file corrected and committed | `docs/32` §0.5; `docs/00` §13 |
| **Labels.** `DOCUMENTED` (in MikroTik's or our documentation) · `VERSION/MODEL DEPENDENT` (known to differ) · `HARDWARE VERIFIED` (*the physical unit accepted it and you saw the result*) · `UNRESOLVED` (tried and failed, or not attempted). Only a physical result moves a label; a label that quietly disappears becomes a fact nobody checked | `docs/00` §1, §13 |
| **A PASS verifies one model at one RouterOS version.** Record the exact `/system/resource/print` version string on every row. The supported list is fixed at what was tested, never at what is expected to work | `docs/31` §2, §10 |
| **B1 is an architectural gate, not a tuning parameter.** Its result is recorded as PUSH, POLL or UNRESOLVED and then *decided upon*; this session implements nothing either way | `docs/00` §14; `docs/32` B.idle; §4.J below |
| **Never record** the management password, the WireGuard private key, the RADIUS shared secret or the PostgreSQL password. Record *where* they are stored. A `freeradius -X` log contains the database password: treat it as a secret and delete it | `docs/31` §9; `docs/32` P.3; `docs/36` §6.5; `docs/00` §15.7 |
| **A negative result needs a positive control.** A `0 rows`, an empty capture or a silent probe has more than one cause; every check below pairs a refusal with the same check succeeding where it should | `docs/00` §15.6; CLAUDE.md *Credible evidence* |
| **Not a customer router.** The unit is DishNet-owned and dedicated to this test. Tests C3, D1 and E1–E4 are destructive (§5) | `docs/31` §1.1 |

### 0.2 Checklist identifiers

Rows here are numbered **`HW-<category><n>`** (`HW-A1` … `HW-K7`). **`docs/31`'s
Tests A–E and `docs/32`'s Step 0, Run Sheets A–E and `B.idle` keep their own
numbering and are cited by it** — `HW-B6` is not "Test B6". Where a row *is* a
`docs/31`/`docs/32` step, the row says so and adds nothing to the procedure.

### 0.3 The three kinds of evidence — do not let them blur

| | Can support | Cannot support |
|---|---|---|
| **Software proof** (the suite: 33 / 3,026 / 0) | that the control plane refuses forged destinations, gates real bindings, never confirms on a malformed answer | anything about what RouterOS accepts (`tests/fake_routeros.php` header) |
| **CHR / lab evidence** (`tools/chr_harness.sh`, unrun) | REST path acceptance on a CHR at one version | serial identity, reset behaviour, radio, HotSpot on metal (`docs/30` Artifact 13 rule 3) — see §6 |
| **Physical evidence** (this document) | a `HARDWARE VERIFIED` label for *that model at that version* | any claim about a model or version not on the bench |

---

## 1. Scope of the physical session

### 1.1 In scope — preparation and hardware validation only

`docs/00` §12 *Allowed now*: physical Step 0 · RouterOS command validation ·
WireGuard validation · REST validation · HotSpot/RADIUS validation ·
reset/restore validation · CGNAT testing · **B1 idle testing**. Nothing else.

### 1.2 The Domain-B software stays out of the loop

The worker, the adapter (`RouterOsDelivery`), the REST client and the Admin
panel are **not connected to the router** during this session. **F6-B stays
NOT AUTHORIZED: `DN_ALLOW_REAL_BINDINGS` is never set**, `DN_DELIVERY` stays
unset or `null`, and no intent is delivered to hardware. Category C and D below
exercise, **by hand with `curl` from the gateway** (`docs/32` §0.2b), the exact
REST paths and bodies the adapter would send (§3), so the session proves what
the software *would* meet without running it. A green session does not open
F6-B; F6-B is its own authorisation (`docs/114` §K G-F).

### 1.3 The only server-side changes the session may make

The gateway is the Phase-0 stack **on installation B, the live Uganda host**
(`docs/00` §10): native WireGuard `wg0` `10.66.0.1/24` UDP 51820; FreeRADIUS
`dn-phase0-radius` bound to `10.66.0.1:1812/1813`; `dn-phase0-postgres` on
`127.0.0.1:5433`; the remaining RADIUS client `dn-test-mikrotik` at
`10.66.0.11`; `radcheck`/`radreply`/`radacct`/`nas` all at **0 rows**
(`docs/36` §6.6). `docs/41` §8 permits *continued read-only use* of that stack,
and `docs/35`/`docs/36` already prescribe exactly two additions for a router
test. **Those two, and nothing else, are the permitted changes:**

| Permitted | How, exactly | Undo afterwards |
|---|---|---|
| one `[Peer]` block for the test unit in `/etc/wireguard/wg0.conf`, `AllowedIPs = 10.66.0.11/32` | `docs/35` *Server side*: back up, append, `chmod 600`, **`wg syncconf wg0 <(wg-quick strip wg0)` — never `systemctl restart`** | remove the block the same way |
| the test voucher rows `t1-TESTCODE01` in `radcheck`/`radreply` (and `Expiration` for HW-G6) | `docs/36` §2.6 / `docs/33` §6.6 | delete **by username** as `docs/36` §6.6 did, including `radacct` |

**Constraints that remain in force throughout** (`docs/00` §10): do not enable
UFW; do not touch iptables, Docker, Swarm, EasyPanel, Traefik, the existing
PostgreSQL or Redis, or the timezone; do not restart Docker; do not create an
EasyPanel service. A RadSec listener for HW-H5 would be a third change
(`docs/33` category B *optional*) — **raise it before making it**. Anything
beyond this table is out of scope for the session and is raised, not done.

### 1.4 Out of scope, stated so it is not done by momentum

- No production change; no customer router; no reset of customer hardware.
- No `HARDWARE VERIFIED` claim in any document except by the §7 template with
  evidence attached; no change to B1's status except by the recorded J result.
- No migration 028; no change under `src/`, `migrations/`, `tests/`, `plugin/`.
- No simulated or CHR result presented as physical evidence (§6).
- No G-C2, B-3, O-1, G-D, production TLS, `staff:bootstrap`, deployment.
- **No poll architecture** — not designed, not sketched in code, not "just a
  flag" — whatever J records (`docs/00` §14; `docs/33` §5.4).
- The production census (`docs/79`) remains a separate handoff and is untouched
  by this one.

---

## 2. Preconditions and the Step 0 inventory

### 2.1 Bench and connections (`docs/31` §1, `docs/32` P.1–P.2)

One dedicated DishNet-owned unit (`docs/31` §1.1 recommends two so one may be
destroyed; with **one** spare, §5 orders the destructive tests last). Ethernet,
a laptop with Ethernet, a phone for HW-E4. Connections: **Starlink Residential
(required — the primary deployment condition, `docs/31` §0.1)**, MTN or Airtel
LTE, and the office fixed line as the control. Gateway: the Phase-0 host of
§1.3; confirm `wg show` lists the interface before touching the router
(`docs/33` §7 checks 1–4), and confirm UDP 51820 is reachable from outside
**by a handshake from a laptop, not by `nc`** — `nc -u -z` reports success
against ports where nothing listens (`docs/36` §6.1b).

### 2.2 Device record — filled at unboxing, before any command (`docs/32` P.3)

```
Unit ID (yours):        ____________
Model:                  ____________
Serial (sticker):       ____________
Serial (routerboard):   ____________   match? Y / N        ← HW-A1
RouterOS as shipped:    ____________
RouterOS after upgrade: ____________   (bench only, never remotely — docs/35 §1)
Upgrade duration:       ____________
WG public key:          ____________   (43 base64 chars + '=' ? Y / N ← HW-A6)
Tunnel /32:             10.66.____.____
Staged by / at:         ____________
```

`/system/resource/print`, `/system/routerboard/print`, `/system/package/print`
before anything is configured (`docs/31` §1.4; `docs/35` §0).

### 2.3 Step 0 inventory — `docs/32` §0.1, reproduced exactly

Run each on the unit. Record **OK** (menu exists, prints), **MISSING** or
**ERROR** (verbatim). A `print` is non-destructive and proves the menu exists.
Slashes are used; if the unit rejects them, record that and use spaces
(`docs/32` syntax note).

| # | command | needed for | inventory item | result |
|---|---|---|---|---|
| 1 | `/system/resource/print` | identity, version | **model, RouterOS version** | |
| 2 | `/system/routerboard/print` | serial | **board / serial** | |
| 3 | `/system/identity/print` | staging §3.1.3 | **identity** | |
| 4 | `/system/package/print` | capability check | **packages** | |
| 5 | `/interface/wireguard/print` | **the tunnel — critical** | **WireGuard** | |
| 6 | `/interface/wireguard/peers/print` | tunnel peer + handshake | WireGuard | |
| 7 | `/ip/address/print` | tunnel address | **addresses** | |
| 8 | `/ip/firewall/filter/print` | management lockdown | **firewall** | |
| 9 | `/ip/service/print` | REST availability | **services** | |
| 10 | `/certificate/print` | REST over TLS | **certificates** | |
| 11 | `/system/scheduler/print` | **watchdog — critical** | **scheduler / scripts** | |
| 12 | `/system/script/print` | watchdog | scheduler / scripts | |
| 13 | `/ip/hotspot/print` | Test A9 | **HotSpot** | |
| 14 | `/ip/pool/print` | hotspot addressing | **pool** | |
| 15 | `/radius/print` | Test A10 | **RADIUS** | |
| 16 | `/radius/incoming/print` | **CoA — verify separately** | RADIUS (CoA) | |
| 17 | `/queue/simple/print` | bandwidth enforcement | **queues** | |
| 18 | `/tool/fetch` (with no args, read the error) | backend check-in | **fetch** | |
| 19 | `/file/print` | rollback file storage | **files** | |
| 20 | `/system/clock/print` | timestamps, cert validity | **clock** | |
| 21 | `/system/default-configuration/print` | **Test E4** — manual says it exists | **default configuration** | |
| 22 | `/ip/service/print` shows `www-ssl` | **REST** — manual: `/rest` needs it | services (REST) | |

**If #5 is MISSING**, the WireGuard package is absent or the version is v6:
stop, resolve, restart Step 0 (`docs/32` §0.1).

### 2.4 Argument validation (`docs/32` §0.2), key generation (§0.3), REST (§0.2b)

Create, print, delete — and **record the exact spelling your version accepts**
for `endpoint-address`, `endpoint-port`, `allowed-address`,
`persistent-keepalive`. The last one is the argument the entire push-vs-poll
question rests on. These are HW-B2, HW-B3, HW-A6, HW-C1–C4 below; the
commands are quoted there from `docs/32`/`docs/35` unchanged.

### 2.5 Export and restore — required by Step 0, and not the same thing (`docs/32` §0.4)

```
/export file=step0-baseline
/file/print
/system/reset-configuration run-after-reset=step0-baseline.rsc
```

**A successful export proves a file was written. It does not prove the router
can be brought back from it.** HW-I1 is the export; HW-I2 is the restore, run
**on the bench with physical access**, and the §0.5 gate requires **both**.
The watchdog (HW-I3/I4) and every recovery path in `docs/31` rest on HW-I2.

### 2.6 The gate to Test A (`docs/32` §0.5)

Every §2.3 row OK or with a recorded working substitute · §2.4 spellings
written into `docs/32` · §2.5 restore confirmed · **`docs/32` corrected and
committed**. Then, and only then, `docs/32` Run Sheet A.

---

## 3. What the software will send — the REST surface the session must exercise

Read from `src/Delivery/RouterOs/RestClient.php`, `src/Delivery/RouterOsDelivery.php`
and `src/Jobs/UplinkSampler.php` at `9a09cc7`. Basic authentication over
`https://<tunnel_ip>/rest/<path>`, self-signed certificate accepted **for
tunnel addresses only**, connect timeout **5 s**, total **10 s**. Any host
outside `10.66.0.0/16` is refused before a socket opens.

| Method and path | Body | Used for | Register |
|---|---|---|---|
| `GET system/routerboard` | — | identity check before every write: `serial-number` must equal the registry serial, else **permanent refusal** | H8 |
| `GET system/resource` | — | `version`, `board-name` | H9 |
| `GET system/identity` | — | `name` | H10 |
| `GET ip/hotspot/profile` | — | *is the HotSpot RADIUS-backed?* — looks for a profile with `use-radius = yes`; read-back for confirm | H5 |
| `PATCH <desired path>` | the desired values as JSON | desired-state delivery. **The only desired path anywhere in the repository is `ip/hotspot/profile` with `{"use-radius":"yes"}`** — a PATCH on the *collection* path, no `.id` in the URL | new: HW-D2 |
| `GET <desired path>` | — | confirm is a read-back, never the write's 2xx; divergence = desired vs actual | `docs/55` §E, §F |
| `POST ip/hotspot/active/remove` | `{".id": "<active entry id>"}` | disconnect | H6 |
| `GET ip/hotspot/active` | — | confirm the entry is gone (matches on `.id`) | H6 |
| `GET interface` | — | uplink sample: expects `rx-bits-per-second` / `tx-bits-per-second` on the WAN interface | H7 |

**Classification the adapter applies** (`docs/118` D-10): 2xx with JSON →
accepted; 2xx with a non-JSON body → `malformed`, retryable, **never a
confirmation**; **5xx → retryable**; **4xx → permanent** for a write (*the
router understood and refused*). So **which status class RouterOS uses for a
refused value decides whether a queue retries or stops** — record it (HW-C5,
HW-C6, HW-D6).

Two facts measured in the repository, recorded so the hardware evidence is
gathered for them — **they are software findings, not hardware findings**:

- The production `session.disconnect` intent (`mt_session_disconnect_request`,
  migration 024) carries `{session_id}` only, while the adapter needs a device
  (`payload.device_id` or a `device` target) and a HotSpot `.id`
  (`payload.nas_session_id`). Neither is derivable today because a session
  cannot be attributed to a router (`docs/91`; `docs/68` §2.7 D-2). HW-F6,
  HW-G5 and HW-K5 gather what the router supplies so that gap can be designed
  from evidence.
- `RouterOsLimits` records that `Mikrotik-Rate-Limit` 2³²−1 and
  `shared-users` 65535 were **never read off a MikroTik**, and that a verified
  limit requires *a bisection on a unit of that model and firmware: set,
  apply, read back*. HW-F4 and HW-E6 are that bisection.

---

## 4. The checklist

Column key: **Command / action** is quoted from `docs/32`/`docs/35` where the
protocol specifies it and is an UNVERIFIED proposal everywhere (§0.1);
**Evidence** names what to save (never a secret); **M/V?** is *Model/version
dependent*; **Gates** names the decision or register row the result feeds.
RESULT vocabulary is PASS · FAIL · NOT RUN, except category J: PUSH · POLL ·
UNRESOLVED.

### A. Router identity

What it gates: the claim model (`docs/30` §6.3 — *a serial is claimed when the
tunnel comes up carrying it*; *assume it can be spoofed and treat
first-claim-wins as the real protection*), `mt_devices.serial` / `wg_pubkey` /
`tunnel_ip` as the three identities (`docs/118` §A.3), the adapter's identity
check (D-5, H8), and the Admin register route's input shapes.

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-A1 | `/system/routerboard/print`; read the sticker | `serial-number`, `model`, `firmware` printed | console output; photo of the sticker; §2.2 record | reported serial **equals** the sticker | differ, or no serial reported | **yes** (`docs/31` §1.4: *if they differ, the registry design changes*) | `docs/30` §6.3; H8; `mt_devices.serial` |
| HW-A2 | from the gateway: `curl -sk -u <user>:<pass> https://10.66.0.11/rest/system/routerboard` | HTTP 200, JSON with a key spelled exactly `serial-number` equal to HW-A1 | status line + body (contains no credential) | 200 · key `serial-number` present · value = HW-A1 | 404 · key spelled otherwise · value differs · empty | **yes** | D-5 — the adapter refuses permanently on mismatch or missing serial (`RouterOsDelivery::verifyIdentity`) |
| HW-A3 | `/system/resource/print`; `GET …/rest/system/resource` | `version`, `board-name`, `architecture-name` | both outputs | REST keys `version` and `board-name` present and equal to the console | keys absent or named otherwise | yes | H9 (DOCUMENTED → verified for this unit) |
| HW-A4 | `/system/identity/set name=DN-<serial-tail>` (`docs/35` §2), then `/system/identity/print` and `GET …/rest/system/identity` | `name = DN-<serial-tail>` on both | both outputs | equal | REST key not `name`, or differs | yes | H10; `docs/31` §3.1 step 3 |
| HW-A5 | **Investigation, not pass/fail.** With physical access and a `full` group user, look for any supported way to change what `/system/routerboard/print` reports (menu help `?`, `/system/routerboard/settings`, documentation for *this* version). Do **not** flash firmware | either a mechanism exists or none is found | what was tried, verbatim | — record **SPOOFABLE (how)** or **NOT FOUND** | — | yes | `docs/30` §6.3 *REQUIRES VERIFICATION*; H8. Either answer leaves *first-claim-wins* as the protection; a SPOOFABLE result forbids ever treating the serial as a trust anchor |
| HW-A6 | `/interface/wireguard/add name=wg-keytest`; `/interface/wireguard/print detail`; then remove (`docs/32` §0.3) | a public key is shown; the private key is hidden or clearly marked | output with the **public** key only | public key shown **and** is 43 base64 characters + `=`; private key not printed | private key printed in clear (record it as a finding — do **not** record the key) | yes | `docs/30` §6.2 the private key never leaves the device; the register route's key shape `^[A-Za-z0-9+/]{43}=$` |
| HW-A7 | if a second unit exists: HW-A1 on it | a different serial | both records | distinct | equal (a duplicate serial breaks the UNIQUE registry) | yes | `mt_devices.serial UNIQUE` |

### B. WireGuard transport

What it gates: the transport layer of `docs/30` Artifact 3b (*carry management
traffic to a router with no public IP*), the staging procedure `docs/31` §3.1
steps 5–8, `docs/35` §4–5 and §8, the management-network rule
`Dn\Devices\TunnelAddress` (`10.66.0.0/16`), and the *"plug it in and it
joins"* promise (`docs/31` §8 item 1).

Subnet note, recorded so it is not mistaken for a conflict: `docs/31` and
`docs/33` propose `10.66.0.0/16`; the built gateway is `10.66.0.1/24`
(`docs/00` §10) and `docs/35` uses `/24`; the software accepts any address in
`10.66.0.0/16`. **Use the gateway's actual mask and record it.**

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-B1 | `/interface/wireguard/print` (inventory #5) | prints without error | output | OK | MISSING / ERROR → **stop Step 0** | yes | everything below |
| HW-B2 | `/interface/wireguard/add name=wg-test listen-port=13231` · `/interface/wireguard/print detail` · `/interface/wireguard/remove [find name=wg-test]` (`docs/32` §0.2) | a chosen `listen-port` is accepted | output | accepted and printed | argument rejected (record the accepted name) | yes | `docs/35` §4 |
| HW-B3 | `/interface/wireguard/peers/add interface=wg-test public-key="…" endpoint-address=1.2.3.4 endpoint-port=51820 allowed-address=10.66.0.0/16 persistent-keepalive=25s` (`docs/32` §0.2) | accepted, or an error naming the argument | the **exact accepted spelling** of all four arguments, written into `docs/32` | all four accepted under the recorded spelling | `persistent-keepalive` not accepted under any spelling → architecture finding (`docs/32` *When a command is wrong* item 4) | **yes** | `docs/35` §4; **B1** rests on the keepalive |
| HW-B4 | HW-B3 with `endpoint-address=<DNS name of the gateway>`, then change the name's address in DNS and observe | accepted; re-resolves after a change, or resolves once at boot | outputs, timings | accepted **and** re-resolves | rejected, or resolves once only | yes | `docs/33` §5.3 (a name spares re-staging 200 units) — UNRESOLVED |
| HW-B5 | stage per `docs/35` §4: `/interface/wireguard/add name=wg-dishnet listen-port=13231 …`, peer with the gateway public key `ftGs/7LjO/aVmKJ9xS/fz+QDt73JcGI+X3vgSCZpJT4=`, endpoint `209.97.137.203:51820`, `allowed-address`, `persistent-keepalive=25s`; `/ip/address/add address=10.66.0.11/<mask> interface=wg-dishnet`; server side per §1.3 | router: `/interface/wireguard/peers/print detail` shows a recent handshake, `/ping 10.66.0.1 count=4` → 4 replies · gateway: `wg show` latest handshake, `ping 10.66.0.11` → replies | both `wg show` outputs (public keys only), both ping outputs | **both directions** work (`docs/35` §8 — *outbound alone proves nothing*) | either direction silent → `timeout 30 tcpdump -ni any udp port 51820` on the gateway (`docs/35` *What will probably go wrong first*) | yes | transport; `docs/31` A4–A5 |
| HW-B6 | `docs/32` A2–A5: connect WAN to the office line, power on, **start a timer**; stop when the gateway's `ping 10.66.0.11` replies | tunnel comes up unaided | T₀, T, `/ip/address/print` showing the WAN lease | **T < 300 s**, nobody touched the router | ≥ 300 s, or a human touched it | connection-dependent | `docs/31` §8 item 1; §8.1 row *A5 over 5 min* |
| HW-B7 | from the gateway on **each** connection: `ping -M do -s 1400 10.66.0.11` then 1380, 1360, 1340, 1300 (`docs/32` B.mtu) | largest size that passes, per line | the table B.mtu: Starlink · LTE · office | recorded for all three; WireGuard MTU set below the smallest, or per-device if they differ | any line not measured | **connection**-dependent, not model | H11; `docs/31` §5.1 (*a large config push failing while ping succeeds*) |
| HW-B8 | after `docs/35` §5 (`/ip/firewall/filter/add chain=input action=accept in-interface=wg-dishnet … place-before=0`; disable telnet/ftp/www/api/api-ssl): from a host **outside the tunnel** probe the router's WAN address for 443, 22, 8291, 80, 8728 | nothing answers on the WAN | probe output from outside; `/ip/firewall/filter/print` and `/ip/service/print` from the router | REST/SSH/Winbox unreachable from WAN **and** REST reachable on the tunnel (HW-C4, the control) | anything answers on the WAN → **do not ship any unit** | yes | `docs/32` gate item 7; `docs/35` §5–6 |
| HW-B9 | `docs/32` C1 (pull WAN during a push) and C4 (stop the gateway 1 h, restart) | tunnel returns unaided; time to return | timings | returns with no physical intervention both times | needs a hand | connection-dependent | `docs/31` §8 item 3; informs the intent backoff ceiling `min(3600, 2^attempts·15)` s — **recorded, not changed** |

### C. REST management

What it gates: `docs/30` Artifact 3b — *RouterOS REST becomes the management
interface* and *REQUIRES VERIFICATION that RouterOS will serve REST on a
self-signed certificate* (H1); the client's timeouts (H11) and its answer
classification (H12); the uplink sampler (H7).

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-C1 | `/ip/service/print` (inventory #9, #22) | a `www-ssl` row exists | output | present | absent → REST unavailable on this version | **yes** | `docs/30` 3b (REST is 7.1beta4+) |
| HW-C2 | `/certificate/add name=dn-rest common-name="DN-XXXXXXXX" key-size=2048` · `/certificate/sign dn-rest` · `/certificate/print` (`docs/35` §6; signing is asynchronous — wait) | a signed self-signed certificate | output | signed | error, or never finishes | yes | H1 |
| HW-C3 | `/ip/service/set www-ssl certificate=dn-rest disabled=no address=10.66.0.0/<gateway mask>` · `/ip/service/print` (`docs/35` §6) | `www-ssl` enabled, bound to the tunnel network | output | enabled and address-restricted | `address=` not accepted → REST would listen on the WAN (see HW-B8) | yes | `docs/35` §6 *a security control, not a preference* |
| HW-C4 | `curl -sk -u <user>:<pass> https://10.66.0.11/rest/system/resource` from the gateway (`docs/32` §0.2b) | 200, JSON | status + body | **200 and JSON** with `version` | TLS refused · HTML · non-JSON · 404 | **yes** | **H1**, H9; `RestClient` (peer verification off for tunnel addresses only) |
| HW-C5 | same call with a **wrong** password, `-i` | a 4xx with a JSON body | status + body | **4xx and JSON of the shape `{"error":…,"message":…}`** | 2xx · HTML · empty body | yes | H12; the adapter's 4xx = permanent rule |
| HW-C6 | `GET …/rest/system/nonexistent` · `PATCH …/rest/ip/hotspot/profile` with `{"use-radius":"maybe"}` | status class and body shape for an unknown path and for a refused value | statuses + bodies | both answered with **4xx JSON** | either is **5xx** (the adapter would *retry* a refusal) or non-JSON | yes | **H12** — decides retry vs stop for every refused write |
| HW-C7 | across **every** REST call of the session, note any 2xx whose body is not JSON | none | the list (or "none observed over N calls") | none observed | any observed → `malformed` path is live | yes | H12 (an absence over one session narrows it for the paths exercised; it does not prove a universal) |
| HW-C8 | `time curl -sk -u … https://10.66.0.11/rest/system/resource` on Starlink, LTE and office, idle and busy | wall time per call | the times | every call < **5 s** to connect and < **10 s** total | any call exceeds | connection-dependent | **H11** — the constants in `RestClient` |
| HW-C9 | = HW-B8's REST half | REST answers on the tunnel and **not** on the WAN | as HW-B8 | as HW-B8 | as HW-B8 | yes | `docs/32` gate item 7 |
| HW-C10 | `GET …/rest/interface` | per-interface JSON; note whether `rx-bits-per-second` / `tx-bits-per-second` appear, and which flags exist (`running`, `disabled`) | body | the two rate keys are present on the WAN interface **or** the endpoint that carries them is recorded | neither found and none recorded | **yes** | **H7** (`UplinkSampler` reads exactly these keys); HW-K3 |

### D. RouterOS provisioning — desired-state delivery over REST

What it gates: `docs/30` §4.2 Phase 2 (*configured step by step, each step
recorded*), Artifact 11 (*each step idempotent, so a retry resumes*), the
desired-state contract `mt_device_config.desired` keyed by RouterOS path
(`docs/55` §B, §F), `RouterOsDelivery::provision()` and the at-least-once
delivery model of migration 008. **A refusal here is an architecture finding,
not a typo.**

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-D1 | `GET …/rest/ip/hotspot/profile` | a JSON array; each element has `.id`, `name` and a key spelled `use-radius` | body | `use-radius` present | key absent or spelled otherwise | **yes** | **H5** (`assertRadiusBacked`); `test_devices` divergence fixtures |
| HW-D2 | `PATCH …/rest/ip/hotspot/profile` body `{"use-radius":"yes"}` — **exactly what the adapter sends: the collection path, no `.id` in the URL** | 2xx, or a refusal saying an id is required | status + body; then HW-D3 | 2xx **and** HW-D3 shows the change | 4xx/405 (an id is required in the path) · 2xx **without** the change (*accepted ≠ honoured*) | **yes** | the desired-state contract and `provision()`; if the path must carry `.id`, that is a design decision for `docs/55` §B — **record, do not patch around it** |
| HW-D3 | `GET …/rest/ip/hotspot/profile` after HW-D2 | `use-radius: yes` on the target profile, `.id` unchanged | body | read-back equals desired | differs, or `.id` changed | yes | *confirm is a read-back* (`docs/55` §E); `divergenceIsEmpty()` |
| HW-D4 | `docs/32` A8: the twelve steps **from the gateway over the tunnel**, one at a time — WAN · bridge · DHCP server · DNS · IP pool · HotSpot · RADIUS client · walled garden · firewall · NAT · queues · NTP + identity — `/export file=a8-step-NN` after each | each step applied; consecutive exports differ only by that step | the twelve exports (they **are** the provisioning record, `docs/30` §11); which mechanism each step needed — REST, or scripting/`/import` | all twelve applied with **no local access** | any step needed local access; record every step that could **not** be done over REST — that is evidence against *REST as the steady-state interface* for that object | yes | `docs/30` 3b; `docs/31` A8; `docs/35` *pushed later* table |
| HW-D5 | re-send HW-D2's identical PATCH a second time | harmless | status + HW-D3 read-back | 2xx, no duplicate object, read-back unchanged | a second profile appears, or an error | yes | migration 008: *every operation delivered through this queue must be idempotent at the far end* |
| HW-D6 | `docs/32` C5: push a deliberately invalid configuration (e.g. HW-C6's bad value; a malformed body) | rejected, recorded, router still manageable | status class; `/export` before and after identical | **4xx**, nothing applied, tunnel and REST still answer | 5xx for a refused value · partial application · management lost | yes | H12; `docs/31` §8 item 3 (C5) |
| HW-D7 | `docs/32` C1 (pull WAN mid-A8) and C2 (**cut power mid-A8**) | staged config intact; tunnel returns; the push **resumes**, not restarts | exports before/after; timings | recovers with no physical intervention, converges to the intended config | needs a hand, or config corrupted | yes | `docs/31` §8 item 3; §8.1 row *C2* |
| HW-D8 | after the tunnel is proven: change one provisioned value **locally on the console**, then `GET` it over REST | the read-back shows the local change | both outputs | REST reflects console changes at once | REST returns a stale value | yes | `diverged` detection — *divergence is computed, never stored* (`docs/55` §F) |

### E. HotSpot

What it gates: the enforcement layer of Artifact 3b (*DishNet builds no
captive portal — the login page is a template served by HotSpot*), `docs/31`
A9, the `docs/80` §7 items *HotSpot server/profile creation order* and
*`Mikrotik-Group` semantics*, and H3.

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-E1 | `/ip/hotspot/print` · `/ip/pool/print` (inventory #13, #14) | menus exist | output | OK | MISSING | yes | A8 steps 5–6 |
| HW-E2 | create pool → user profile → server profile → server on the bridge, **in whichever order the unit accepts**; record it | a working order | the exact commands and order that worked, written into `docs/35`/`docs/32` | HotSpot server active | no order works without the setup wizard (record that too) | **yes** | *HotSpot server/profile creation order* — UNRESOLVED (`docs/80` §7) |
| HW-E3 | `/radius/add service=hotspot address=10.66.0.1 secret=<never recorded> …` (`docs/31` §2.1 names `/radius/add`; confirm arguments with `?`), then profile `use-radius=yes` (HW-D2) | RADIUS client points at the **tunnel** address | `/radius/print` (secret masked) | address is `10.66.0.1`, not a public one | a public address staged by habit (`docs/33` §3.3) | yes | `docs/30` §5.1 through-tunnel for Phase 0; `docs/33` §3.3 |
| HW-E4 | `docs/32` A9: phone joins the SSID | redirected to a login page | screenshot | redirect appears | none | yes | `docs/31` A9; Artifact 3b |
| HW-E5 | A8 step 8 walled garden: one allowed host reachable before login, another not | as stated | outputs | both hold | either fails | yes | `docs/31` A8 |
| HW-E6 | **bisection** of `shared-users` on the HotSpot user profile (`/ip/hotspot/user/profile` — confirm the menu): set, apply, **read back**, find the largest value accepted **and honoured** | a number for this model and version | the value and the read-back | largest honoured value recorded | accepted but not honoured, or not run | **yes** | **H3**; `RouterOsLimits` (*accepting a value is not honouring it*) |
| HW-E7 | with HW-F2 working: add `Mikrotik-Group = <a HotSpot user profile name>` to `radreply` for the test voucher; log in; `/ip/hotspot/active/print detail` | the session adopts that user profile, or does not | outputs | behaviour recorded either way | not run | **yes** | *`Mikrotik-Group` semantics* — UNRESOLVED (`docs/80` §7); the profile plane of `docs/55` (`mt_profiles`) |
| HW-E8 | `/ip/hotspot/active/print detail` **and** `GET …/rest/ip/hotspot/active` while a guest is logged in | one entry with `.id` (record its format, e.g. `*A`), `user`, address, uptime | both outputs | `.id` and `user` present over REST | entry absent over REST, or no `.id` | yes | H6 (`sessionIsGone()` matches on `.id`); HW-G5 |

### F. RADIUS authentication

What it gates: the AAA layer (*authenticate and account for subscribers* —
FreeRADIUS, never the router), `docs/31` A10–A11, `docs/36`'s server-side
proofs extended to a real NAS, D-2 (*what NAS identifier the router actually
supplies*, `docs/68` §2.4a — *no MikroTik has authenticated here*), and the
Decision 2a anchor (*the trustworthy anchor is the source address, not
`NAS-Identifier`*, `docs/68` §2.4). **The authorize query stays username-only
during this session; the C-b query change is a separately authorised step
(`docs/70` §8.3) and is not made here.**

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-F1 | **control, before the router:** re-insert the `t1-TESTCODE01` rows (`docs/36` §2.6); `docker exec dn-phase0-radius radtest t1-TESTCODE01 t1-TESTCODE01 10.66.0.1 0 <secret>` | `Access-Accept` **with** `Mikrotik-Rate-Limit`, `Session-Timeout`, `Acct-Interim-Interval` | output (secret not saved) | Accept with all three attributes | Accept with **no** attributes is a failure (`docs/36` §3.1) | no | the server half; positive control for HW-F2 |
| HW-F2 | `docs/32` A10: the guest enters `t1-TESTCODE01` on the HotSpot page | FreeRADIUS answers Accept; Internet works | `radpostauth` row; filtered container log (**never a raw `-X` log**, `docs/36` §6.5) | Accept from the **router** and the guest browses | Reject/timeout while HW-F1 passes → the NAS half (client entry, secret, address, tunnel) | **yes** | `docs/31` A10; Artifact 3b AAA |
| HW-F3 | `docs/32` A11: speed test; `/ip/hotspot/active/print detail` | at or below `5M/5M`; a session time limit of 3600 s visible | measured vs configured | measured ≤ configured, limit visible | attributes ignored by the router | **yes** | `docs/31` A11; the profile plane |
| HW-F4 | **bisection** of `Mikrotik-Rate-Limit` via `radreply`: set, log in, read back the applied limit on the router, find the largest value honoured | a number for this model and version | value and read-back | largest honoured value recorded | accepted but not applied, or not run | **yes** | **H2**; `RouterOsLimits` |
| HW-F5 | a wrong code on the login page | `Access-Reject`; no session; no `radacct` row | `radpostauth` reject row; `radacct` count unchanged | rejected, nothing accounted | a session opens | no | the Model B / P2 path is unbuilt; this only records the NAS's behaviour on reject |
| HW-F6 | capture the attributes the router sends: on the gateway `tcpdump -ni wg0 udp port 1812 -vv` during HW-F2 (the password is encrypted; the code is a test code) | `User-Name`, `NAS-IP-Address`, `NAS-Identifier`, `Called-Station-Id`, `Calling-Station-Id`, `NAS-Port-Type`, and the **source address** of the packet | the attribute list and values | source address is **`10.66.0.11`** (the tunnel address) and the NAS attributes are recorded | packets arrive from any other address, or capture not taken | **yes** | **D-2** (`docs/68` §2.7 — a NAS identifier must exist in the control plane and map to a device; it does not); Decision 2a's source-address anchor; HW-K5 |
| HW-F7 | during HW-F2: `ss -ulnp \| grep -E '1812\|1813'` and `tcpdump -ni eth0 udp port 1812` on the gateway | RADIUS bound to `10.66.0.1` only; nothing on the public interface | outputs | bound to the tunnel address; public capture empty **with** the wg0 capture non-empty as control | any public-interface packet | no | `docs/33` §3.2; `docs/36` item 7 |

### G. Accounting

What it gates: `docs/31` A12–A14, `docs/36` §3.2 / §6.4 (*count the `radacct`
rows for one session*), `AccountingIngest` (reads `Acct-Session-Id`,
`NAS-Identifier`, the octets **and their Gigawords**), the session model
`mt_sessions (nas_identifier, acct_session_id) UNIQUE`, and the stale-session
reaping design (`docs/55` §H; `docs/49` §2.2).

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-G1 | after HW-F2 login: `SELECT acctsessionid, acctuniqueid, username, nasipaddress, framedipaddress, callingstationid, acctstarttime FROM radacct ORDER BY radacctid DESC LIMIT 3;` in `dn-phase0-postgres` | one Start row | the row (no secret in it) | exactly one row for the session, `nasipaddress` recorded | no row · two rows | **yes** | `docs/31` A12; `docs/36` §3.2 |
| HW-G2 | wait ≥ 300 s while the guest browses; re-query; `tcpdump -ni wg0 udp port 1813 -vv` for one Interim | `acctupdatetime` advances, octets grow, **same row** | rows; whether `Acct-Input-Gigawords`/`Acct-Output-Gigawords` appear in the packet | same row updated; Gigawords presence recorded | a **second** row appears (attribute set varies between packet types → narrow `acct_unique`, `docs/36` §6.4) | **yes** | `docs/36` §6.4; `AccountingIngest` Gigawords (RFC 2869) |
| HW-G3 | `docs/32` A14: log the guest out, or let the limit expire; re-query | `acctstoptime`, `acctterminatecause`, final octets on the **same** row | the row | closed in place with a cause | a third row, or no Stop | yes | `docs/31` A14 |
| HW-G4 | `SELECT count(*) FROM radacct WHERE acctsessionid = '<id>';` | `1` | output | exactly **1** across Start/Interim/Stop | > 1 | **yes** | `docs/36` §6.4 — the thing the server test could not settle |
| HW-G5 | for the same live session record **both** HW-E8's HotSpot `.id` and `radacct.acctsessionid` | two identifiers | both values side by side | recorded, with whether one is derivable from the other | not recorded | **yes** | H6 and the disconnect design (§3 — today's intent carries neither) |
| HW-G6 | `docs/32` A13 in two halves: (a) let `Session-Timeout` elapse on a live session; (b) set `Expiration` a few minutes out in `radcheck` (`docs/33` §6.6) and retry the login after it | (a) access stops **with no intervention**, Stop row with a cause; (b) the same code is refused | rows, timings | both halves as stated | either needs a hand | yes | `docs/31` §8 item 6 (*including automatic expiry*) |
| HW-G7 | during a live session: stop the gateway for 10 min (`docs/32` C4 pattern), restart | does the router retry `Accounting-Stop` / Interim when the tunnel returns? does the row close? | rows, timings | behaviour recorded either way | not run | **yes** | `docs/49` §2.2 (C8′ — a Stop lost with the tunnel leaves the row open); reaping (`docs/55` §H) |

### H. Disconnect and CoA

What it gates: `docs/30` §5 (*CoA and Disconnect — REQUIRES VERIFICATION per
model*), §5.2 (*RadSec may solve the CGNAT inbound problem for CoA*), `docs/31`
E6, H6, the item *disconnect without HotSpot reload* (`docs/80` §7), and the
`session.disconnect` blocker (`docs/108`) — which this session informs, not
fixes.

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-H1 | inventory #16 `/radius/incoming/print`; enable incoming CoA on **this** menu — candidate `/radius/incoming/set accept=yes port=3799`, confirm the arguments with `?` | accepts incoming on 3799 | the accepted spelling | enabled | menu present but cannot be enabled | **yes** | `docs/33` §3.1 (CoA → router :3799) |
| HW-H2 | from the gateway, over the tunnel, with a live session: `echo 'User-Name=t1-TESTCODE01,Acct-Session-Id=<id>,Framed-IP-Address=<ip>' \| radclient 10.66.0.11:3799 disconnect <secret>` (inside `dn-phase0-radius`, `docs/36` §3.5) | `Disconnect-ACK`; the session ends; a Stop row with an admin cause | radclient output (secret not saved); `radacct` row; **which attributes had to be present** to match (try `User-Name` alone, then with `Acct-Session-Id`, then `Framed-IP-Address`) | ACK and the session is gone | NAK · timeout · ACK but session persists | **yes** | `docs/30` §5; the revoke path of Decisions 1/7 |
| HW-H3 | as the adapter would: `POST …/rest/ip/hotspot/active/remove` body `{".id":"<HW-E8 id>"}`; then `GET …/rest/ip/hotspot/active` | 2xx and the entry gone; a Stop row appears | status, both bodies, `radacct` | entry removed **and** accounted | 4xx/405 (path or `.id` wrong — note the adapter treats a 4xx here as *already gone*) · 2xx but entry remains | **yes** | **H6** (*fails silently if wrong*); `sessionIsGone()` |
| HW-H4 | after HW-H2 and HW-H3: does the guest's traffic stop within seconds **without** restarting the HotSpot server, and do other guests stay connected? | yes / no, time to effect | timings; second guest's session untouched | stops without reload; others unaffected | needs a reload, or others drop | yes | *disconnect without HotSpot reload* — UNRESOLVED (`docs/80` §7) |
| HW-H5 | **optional, worth one extra hour (`docs/31` E6):** `/radius/add protocol=radsec …` (needs a RadSec listener — a third server change, §1.3: raise first); establish from the router; send a Disconnect **from the server down that connection** | revocation works with no inbound reachability | outputs | Disconnect honoured over RadSec | refused, or CoA not carried | **yes** | `docs/30` §5.2 — **de-risks the entire B1-fails branch** |
| HW-H6 | optional: `CoA-Request` changing `Mikrotik-Rate-Limit` mid-session | new limit applied without re-login | before/after speed test | applied | ignored | yes | `docs/30` §5 mid-session plan change |
| HW-H7 | HW-H2 again **after the router has been idle 30 min** (with J running) | immediate ACK, or none until the router's next keepalive | timing vs `wg show wg0 latest-handshakes` | recorded (this is J's evidence for server-initiated AAA traffic) | not run | connection-dependent | `docs/33` §3.1 *server-initiated — this is what B1 tests*; `docs/30` §5.2 |

### I. Reset and recovery

What it gates: `docs/30` Artifact 3b (*bootstrap / recovery → RouterOS
scripting, `/import`, `run-after-reset`*) and Artifact 11 (the rollback target
is the post-bootstrap export, so rollback lands on a *manageable* router),
`docs/31` Tests D and E, `docs/32` §0.4 and Run Sheets D/E, and the
customer-presses-reset support path.

| ID | Command / action | Expected observation | Evidence to capture | PASS when | FAIL when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-I1 | `/export file=step0-baseline` · `/file/print`; read the file back (`docs/31` §2.1 `/export` completeness) | file present and complete | the export | present, and re-reading it lists the staged objects | missing or truncated | yes | Step 0 §0.4; the rollback target |
| HW-I2 | `/system/reset-configuration run-after-reset=step0-baseline.rsc` — **bench, physical access** | the file survives the reset **and** applies; the router is manageable on the tunnel afterwards | before/after exports; time to return | restored and manageable | file gone · not applied · router needs hands | **yes** | **Step 0 §0.4 gate**; the watchdog; Artifact 11. *A successful export alone is not a proven restore path* |
| HW-I3 | `docs/32` D.1 pieces 1–6: a scheduler entry fires · can be one-shot · a script can run `reset-configuration run-after-reset=` · the file survives and applies · the entry can be deleted before firing · router reachable after firing | six recorded syntaxes | the **exact working syntax** for each, written into `docs/32` | all six | any piece impossible → the watchdog design changes (`docs/32` §D) | **yes** | Test D; `docs/31` §3.3 (*ILLUSTRATIVE, NOT PROCEDURE*) |
| HW-I4 | `docs/32` D.0 commit-confirm pattern and/or the continuous watchdog; then D1 (push a filter rule that drops the tunnel), D2 (time it), D3 (24 h healthy, no fire) | reverts to the baseline; tunnel returns; no spurious fire | design chosen and why; D2 minutes; D3 log | D1 reverts **and** D3 never fires | either fails → *no safe remote reconfiguration at all* (`docs/31` §8.1) | **yes** | `docs/31` §8 item 4 — **blocks Phase 2 outright if it fails** |
| HW-I5 | `docs/32` Run Sheet E: E1 `/system/reset-configuration` · E2 hard reset (button held at boot) · E3 `reset-configuration run-after-reset=staged.rsc` · **E4** can a custom default configuration survive a **hard** reset (`/system/default-configuration/print`, any supported mechanism on this version) · E5 if E4 is negative: the phone-support instruction | staging gone? restores? survives? | Y/N per row; E4 mechanism if yes | E1–E3 documented, **E4 answered either way**, RMA / re-staging path written and owned if E4 is NO | E4 left blank | **yes** | `docs/31` §8 item 5; §8.1 row *E4 negative — an RMA path is required before first shipment* |
| HW-I6 | `docs/32` C3: **cut power during a RouterOS upgrade** — see §5 | may brick → Netinstall | honest record | recovers without Netinstall | bricks → *upgrades are bench-only, forever* (`docs/31` §8.1) | **yes** | `docs/31` C3; `docs/35` §1 |

### J. B1 — push versus poll: the architectural gate

**Read `docs/00` §14 and `docs/32` B.idle before this section.** `B.idle`
determines whether the future control plane is PUSH or POLL. Migration 008's
header says *"Nothing here says WHEN an intent reaches a router"*; the frozen
guard forbids any push- or poll-implying string in the service; the intent
model is correct under both outcomes (`docs/42` §0). **This session measures.
It does not decide, and it implements nothing** — a POLL result is an input to
redesigning `docs/30` §4.2 Phase 2 *before* anything is written, never a licence
to write a poller because it is convenient; a PUSH result does not open F6-B.

**What counts as push reachability, stated so a handshake cannot be mistaken
for it.** A WireGuard handshake proves the *router* reached the gateway. Push
viability is the converse: a packet the **gateway** originates, while the
router has been idle, is answered **because the NAT mapping was open when the
gateway's packet arrived** — not because the router's next keepalive happened
to refresh it a moment later. The two look identical to a bare `ping` and are
told apart only by capture:

| Signature | On the gateway | Meaning |
|---|---|---|
| **Direct** | the first gateway-originated packet is answered within the connection's normal RTT; `wg show wg0 latest-handshakes` is **unchanged** at that instant; `tcpdump -ni <public if> udp port 51820 and host <router public IP>` shows the gateway's outbound packet **before** any inbound one | the mapping was open — **push evidence for that interval** (with the staged keepalive, which is part of the product) |
| **Refreshed** | replies begin only after an **inbound** packet from the router, or `latest-handshakes` jumps at the moment replies start | the gateway's packets were dropped at the NAT until the router refreshed it — **the POLL signature** (`docs/32`: *replies only after the router's own keepalive fires*) |

| ID | Command / action | Expected observation | Evidence to capture | Record | UNRESOLVED when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-J0 | install the idle probe (`docs/33` §6.7 `b1-idle-probe.sh`, every 30 min, pointed at `10.66.0.11` **only after** the tunnel is up); start `tcpdump -ni <public if> -w b1-<line>.pcap udp port 51820 and host <router public IP>` | a timestamped log and a capture, with **no other gateway traffic** to the router | `/var/log/b1-idle-probe.log`, the pcap | — | probe or capture missing | — | `docs/33` §6.7 (*a single ping every 30 minutes does not hold a NAT mapping open — the gap is what is being measured*) |
| HW-J1 | **Starlink Residential (`docs/32` B1, required):** after the tunnel is established send **no** gateway traffic; at **30 min**, **2 h** and **overnight (8 h+)** initiate from the gateway: `ping -c1 -W3 10.66.0.11` **and** `curl -sk -u … https://10.66.0.11/rest/system/resource` (management traffic, not only ICMP); note `wg show wg0 latest-handshakes` immediately before and after | immediate or delayed reply at each interval | per interval: reply time, handshake timestamps before/after, the pcap excerpt, the probe log lines; `persistent-keepalive` in use | **PUSH** if every interval is *Direct* · **POLL** if any interval is *Refreshed* or unanswered | any interval not run; the line is not real Starlink Residential; the capture that separates *Direct* from *Refreshed* is missing | connection-dependent | **`docs/00` §14**; `docs/30` §4.2 Phase 2; `docs/31` §8 item 2 |
| HW-J2 | the same on **MTN or Airtel LTE** (B2) | as above | as above | PUSH / POLL for this line | as above | connection-dependent | `docs/31` §8.1 row *B1 but not B2* → per-connection profiles from measurement |
| HW-J3 | the same on the **office fixed line** (B3, the control) | reachable throughout | as above | control result | not run | connection-dependent | if B1/B2 fail and B3 passes, it is the CGNAT (`docs/31` §5) |
| HW-J4 | **NAT mapping lifetime (optional, informs the keepalive value):** on Starlink, set `persistent-keepalive` to a large value or off, then probe from the gateway at 30 s, 60 s, 120 s, 300 s, 600 s after the last router packet; restore `25s` afterwards | the interval after which gateway-initiated packets stop arriving | the timings | the measured lifetime, and therefore whether 25 s has margin or must be lowered (`docs/31` §3.1: *it may need to be lower on one of them*) | not run (leaves the keepalive value a convention) | connection-dependent | `docs/31` §3.1 step 6; `docs/32` B.idle *lowered to?* |
| HW-J5 | HW-H7: a **server-initiated Disconnect** after 30 min idle | immediate or delayed | as HW-J1 | evidence for the CoA half of the same question | not run | connection-dependent | `docs/30` §5.2; `docs/33` §3.1 |

**Recording rule for J.** The result is written per line as **PUSH**, **POLL**
or **UNRESOLVED**, in the §7 template and in the `docs/32` B.idle table, with
the pcap and probe log attached. **`docs/00` §14's status line changes only by
the operator's commit of that evidence**, and only for the lines measured. A
result on the office line alone is UNRESOLVED for the product, because the
product is deployed behind Starlink Residential (`docs/31` §0.1).

### K. State ownership and `last_seen_at`

What it gates: D-4 — *whether a confirmed delivery may drive `provisioned`,
and who moves a router to `connected`, is UNRESOLVED* (`docs/118` D-4); the
signal inventory `src/Network/SignalReport.php`, where WAN link, WireGuard
tunnel, RADIUS health, HotSpot service and *last contact* are **UNMEASURED —
no source exists** and `mt_devices.last_seen_at` is **written by nothing**
(`docs/90`); and *sessions cannot be attributed to a router* (`docs/91`). **No
test here passes or fails; each records what signal the hardware can supply,
so those decisions can be taken on evidence instead of invented.** Nothing
here authorises a writer, a state transition or a projection.

| ID | Command / action | Expected observation | Evidence to capture | Record | Not recorded when | M/V? | Gates |
|---|---|---|---|---|---|---|---|
| HW-K1 | on the gateway, `wg show wg0 latest-handshakes` and `wg show wg0 transfer`: (a) router idle with the staged keepalive, sampled every minute for 10 min; (b) router **unplugged** for 10 min; (c) after reconnection | how the handshake timestamp and counters behave in each state | the three series | whether the peer's handshake age is a truthful *last contact* signal, and its lag | any state unmeasured | no | `SignalReport` *wireguard* and *last_seen* (*a handshake timestamp from the WireGuard peer, or a reachability probe*); D-4 |
| HW-K2 | inventory #18 `/tool/fetch`: can the router fetch `https://10.66.0.1/…` (or any tunnel-side URL) over the tunnel? | fetch exists and reaches the tunnel side, or not | output | whether a **device-reported heartbeat / check-in** is possible on this version | not tried | yes | `SignalReport` *last_seen* needs (*a device-reported heartbeat*); the watchdog's *backend check-in* (`docs/31` §3.3) — **a mechanism is recorded, none is chosen: which one supplies `last_seen_at` is the B1 question** |
| HW-K3 | `GET …/rest/interface` (HW-C10): per-interface flags; unplug the WAN cable and re-read | `running` (or equivalent) changes with the cable | both bodies | which key reflects link state, and its lag | not read | yes | `SignalReport` *wan_link* needs (*a RouterOS read-back of interface status*) |
| HW-K4 | `GET …/rest/ip/hotspot` with the server enabled, then disabled from the console | which flags change | both bodies | what a *running* HotSpot looks like over REST | not read | yes | `SignalReport` *hotspot* needs; `docs/93` (*never derive HotSpot liveness from service status*) |
| HW-K5 | from HW-F6 / HW-G1: the values of `NAS-IP-Address`, `NAS-Identifier`, `Called-Station-Id` and the packet **source address**, and whether `NAS-IP-Address` equals the **tunnel address** | the candidate attributes for mapping a session to a device | the values | which attribute, if any, is **registry-derived and unique per device** (the tunnel `/32` is; a `NAS-Identifier` is configured text) | HW-F6 not captured | yes | **D-2** (`docs/68` §2.7); `docs/91` *not attributable*; the `session.disconnect` gap (§3) |
| HW-K6 | during HW-B6 and HW-D4 record four timestamps: first handshake · first REST 200 · last A8 step applied · first read-back equal to desired | a bring-up timeline | the timeline | the observable moments that *connected* and *provisioned* could be pinned to | timeline incomplete | connection-dependent | **D-4** — who moves `connected` / `provisioned` in production; the lifecycle trigger (migration 012) |
| HW-K7 | HW-D8 repeated for one HotSpot object and one firewall rule | REST read-back reveals a console change | outputs | how fast a divergence becomes visible over REST | not run | yes | `diverged` (`docs/55` §F); the reconcile intent |

---

## 5. Destructive tests and their order — one spare unit

`docs/31` §1.1 asks for two reference units *so one can be destroyed by
testing while the other stays known-good*. With **one** DishNet-owned spare:

| Order | Tests | Why here |
|---|---|---|
| 1 | Step 0 (§2), A, B, C, D, E, F, G, H, K, J | non-destructive; J's overnight runs can overlap the rest |
| 2 | HW-I1, HW-I2, HW-I3, HW-I4 (D1–D3) | recoverable by design — the point of the test — but validated with hands on the unit |
| 3 | HW-I5 E1, E3, then E2 and **E4** | a hard reset erases staging; E3/E4 are what would bring it back |
| **last, and optional with one unit** | HW-I6 (C3, power cut during a RouterOS upgrade) | may need Netinstall; **with a single unit the engineer decides whether to run it at all**. A deferred C3 is recorded **UNRESOLVED**, never as passed |

Nothing in this order changes a test's definition; it only protects the unit
that every other row depends on.

---

## 6. CHR — lab evidence only

`tools/chr_harness.sh` is *"THE REAL CHECK — AND IT HAS NOT BEEN RUN"*. It is
**optional** and remains so. If it is run:

- every result is labelled **CHR / LAB EVIDENCE**, never `HARDWARE VERIFIED`;
- it can inform HW-C4–C7, HW-D1–D3, HW-D5–D6, HW-E7, HW-H3 (REST path and body
  acceptance on the CHR's version), and nothing in A (no RouterBOARD serial —
  the adapter's `requireSerial: false` exists for the harness only), nothing in
  I (no factory-reset behaviour), nothing that needs a radio (HW-E4), nothing in
  J (no CGNAT), nothing in K1;
- a green CHR run does not satisfy `docs/31` §8, does not move a label in §8
  below, and does not authorise F6-B (`docs/30` Artifact 13 rule 3; `docs/118`
  §F).

---

## 7. Test-result template — fill during the physical session

One line per row of §4, in this exact column order. **RESULT** is PASS · FAIL ·
NOT RUN (category J: PUSH · POLL · UNRESOLVED; A5 and K: RECORDED). **MODEL /
VERSION** is the model and the exact `/system/resource/print` version string.
**GATE** is the §4 *Gates* cell, copied. **EVIDENCE** is a filename or a
`docs/32` line — never a secret.

| TEST ID | COMMAND | OBSERVATION | EVIDENCE | RESULT | MODEL / VERSION | GATE |
|---|---|---|---|---|---|---|
| HW-A1 | | | | | | |
| HW-A2 | | | | | | |
| … one line per §4 row … | | | | | | |
| HW-K7 | | | | | | |

```
════════════════════════════════════════════════════════════════════
PHYSICAL TEST EVIDENCE       unit ______   engineer ______   date ______
════════════════════════════════════════════════════════════════════
Model ____________  Serial (sticker) ____________  = routerboard? Y / N
RouterOS shipped ____________   RouterOS tested ____________   channel ______
Gateway wg0 mask /24 | /16      Tunnel /32 10.66.____.____
Lines used:  Starlink Residential Y/N   LTE (MTN/Airtel) Y/N   Office Y/N

STEP 0   inventory 22/22 OK or substituted  Y/N   §0.2 spellings written  Y/N
         §0.4 export AND restore confirmed  Y/N   docs/32 corrected+committed Y/N

A identity   A1 __ A2 __ A3 __ A4 __ A5 (SPOOFABLE / NOT FOUND) __ A6 __ A7 __
B transport  B1 __ B2 __ B3 __ B4 __ B5 __ B6 T=____s  B7 MTU S/L/O ___/___/___  B8 __ B9 __
C REST       C1 __ C2 __ C3 __ C4 __ C5 __ C6 (4xx|5xx) __ C7 __ C8 max ____s  C9 __ C10 __
D provision  D1 __ D2 __ D3 __ D4 __/12 over REST ____  D5 __ D6 __ D7 __ D8 __
E hotspot    E1 __ E2 __ E3 __ E4 __ E5 __ E6 shared-users max ______  E7 __ E8 .id format ____
F radius     F1 __ F2 __ F3 measured ____  F4 rate-limit max ______  F5 __ F6 __ F7 __
G accounting G1 __ G2 gigawords Y/N  G3 __ G4 rows/session __  G5 .id vs acctsessionid ____  G6 __ G7 __
H disconnect H1 __ H2 attrs needed ______  H3 __ H4 __ H5 (opt) __ H6 (opt) __ H7 __
I recovery   I1 __ I2 __ I3 __/6  I4 D1 __ D2 ____min D3 __   I5 E1 __ E2 __ E3 __ E4 (YES/NO) __  I6 __
J B1         Starlink: 30m __ 2h __ overnight __  → PUSH / POLL / UNRESOLVED
             LTE:      30m __ 2h __ overnight __  → PUSH / POLL / UNRESOLVED
             Office:   30m __ 2h __ overnight __  (control)
             keepalive used ____  lowered to ____  J4 mapping lifetime ____s
K state      K1 __ K2 fetch Y/N  K3 key ______  K4 __  K5 NAS-IP = tunnel? Y/N  K6 __ K7 __
────────────────────────────────────────────────────────────────────
docs/31 §8 gate items 1–6 + docs/32 item 7:   1 __ 2 __ 3 __ 4 __ 5 __ 6 __ 7 __
ACCEPTED FOR PRODUCTION (this model, this version):   YES / NO
Blocking findings: _____________________________________________________
Attachments: exports before/after · wg show · pcaps · probe log · radacct rows ·
             radpostauth rows · timings · photographs of the CGNAT setups
NOT recorded here: management password · WG private key · RADIUS secret ·
                   PostgreSQL password · any -X log
════════════════════════════════════════════════════════════════════
```

---

## 8. Physical Gate Status — every item still pending, with its label today

**The `HARDWARE VERIFIED` column is empty by construction.** A mark moves into
it only with the test ID, the evidence file and the model/version string from
§7, and it then reads *verified for that model at that version*. Labels are
`docs/00` §13's; rows are the `docs/118` §F register plus the items this
document surfaced from the adapter source (marked †).

| Item | Tests | HARDWARE VERIFIED | DOCUMENTED | VERSION/MODEL DEPENDENT | UNRESOLVED |
|---|---|---|---|---|---|
| H1 — REST served on a self-signed certificate with no further configuration | HW-C2, HW-C4 | | | ● | |
| H2 — largest `Mikrotik-Rate-Limit` the unit honours | HW-F4 | | ● (never read off a MikroTik — `RouterOsLimits`) | | |
| H3 — largest HotSpot `shared-users` the unit honours | HW-E6 | | ● (same) | | |
| H5 — `ip/hotspot/profile` carries `use-radius` | HW-D1 | | | ● | |
| H6 — `ip/hotspot/active/remove` takes `.id` | HW-H3, HW-E8 | | | | ● |
| H7 — `interface` returns `rx-bits-per-second` / `tx-bits-per-second` | HW-C10 | | | ● | |
| H8 — `system/routerboard` `serial-number` equals the sticker | HW-A1, HW-A2 | | | ● | |
| H8′ — whether the serial can be spoofed on a customer-controlled device | HW-A5 | | | | ● |
| H9 — `system/resource` returns `version`, `board-name` over REST | HW-A3 | | ● | | |
| H10 — `system/identity` returns `name` over REST | HW-A4 | | ● | | |
| H11 — a REST call over the tunnel completes within 5 s connect / 10 s total | HW-C8 | | | | ● |
| H11′ — tunnel MTU per connection | HW-B7 | | | | ● |
| H12 — a refused request answers 4xx JSON; no 2xx ever carries a non-JSON body | HW-C5, HW-C6, HW-C7, HW-D6 | | | | ● |
| **B1 — push versus poll** | HW-J0–J5 | | | | ● (`docs/00` §14) |
| keepalive value sufficient on Starlink and LTE | HW-J4 | | | | ● |
| WireGuard peer argument spelling on the target version | HW-B3 | | | | ● |
| DNS endpoint accepted and re-resolved | HW-B4 | | | | ● |
| HotSpot server/profile creation order | HW-E2 | | | | ● |
| `Mikrotik-Group` semantics | HW-E7 | | | | ● |
| disconnect takes effect without a HotSpot reload | HW-H4 | | | | ● |
| CoA / Disconnect-Request honoured, and which attributes it needs (per model) | HW-H1, HW-H2 | | | | ● |
| RadSec carries CoA down the NAS-initiated connection (E6) | HW-H5 | | | | ● |
| reset / `run-after-reset` restores a manageable router | HW-I2, HW-I5 E3 | | | | ● |
| a custom default configuration survives a hard reset (E4) | HW-I5 E4 | | | | ● |
| watchdog: fires on breakage, never spuriously (D1/D3) | HW-I4 | | | | ● |
| † PATCH on the collection path `ip/hotspot/profile` is accepted and honoured (the desired-state contract) | HW-D2, HW-D3 | | | | ● |
| † far-end idempotency of a repeated PATCH | HW-D5 | | | | ● |
| † status class RouterOS uses for a refused value (4xx stops, 5xx retries) | HW-C6, HW-D6 | | | | ● |
| provisioning delivery confirmed on a device (A8 over the tunnel) | HW-D4, HW-D7 | | | | ● |
| HotSpot on the router (A9) | HW-E4 | | | | ● |
| RADIUS authentication from a real NAS (A10–A11) | HW-F2, HW-F3 | | | | ● |
| what NAS identifier and source address the router supplies (D-2) | HW-F6, HW-K5 | | | | ● |
| accounting from real sessions; one `radacct` row per session (A12–A14, `docs/36` §6.4) | HW-G1–G4, HW-G6 | | | | ● |
| † HotSpot `.id` versus `Acct-Session-Id` for one session | HW-G5, HW-E8 | | | | ● |
| lost `Accounting-Stop` across a tunnel drop (C8′) | HW-G7 | | | | ● |
| a source for `mt_devices.last_seen_at` (what signal exists) | HW-K1, HW-K2 | | | | ● |
| who moves a router to `connected` / `provisioned` (D-4) | HW-K6 | | | | ● |
| REST read-back of link state and HotSpot state (the UNMEASURED signals) | HW-K3, HW-K4 | | | | ● |
| power cut during a RouterOS upgrade (C3) | HW-I6 | | | | ● |
| the words *"MikroTik activation proven"* | — | not said | | | |

---

## 9. After the session — how results flow back, and what stays closed

1. **Correct at source.** Every wrong command goes back into `docs/32` and
   `docs/35` with the error text; a *missing capability* (not a renamed
   argument) is raised as an architecture finding against `docs/30`
   (`docs/32` *When a command is wrong* item 4).
2. **Fill `docs/31` §7**, one column per model × version tested, and fix the
   supported model list at what was tested.
3. **Move labels by evidence only:** `docs/00` §13/§14 (B1's status line by the
   J result), `docs/118` §F, and §8 above — each with the test ID and the
   evidence file. A PASS on one unit is `HARDWARE VERIFIED` for that model at
   that version, nothing wider.
4. **Undo the two server-side additions** of §1.3 exactly as `docs/36` §6.6 did,
   and record it.
5. **Commit the corrected run book and the filled templates.** That corrected
   file is the real deliverable (`docs/32` Prime Directive).

**Whatever the results, none of the following is opened by this session:**
F6-B (`DN_ALLOW_REAL_BINDINGS`) · G-C2 · B-3 · O-1 (the production census
`docs/79` is still that handoff) · G-D / G-E · migration 028 · any device-state
automation · a poll or push implementation · a `last_seen_at` writer · a
production install. Each is its own authorisation (`docs/114` §K), taken after
the evidence is read, not during the session that produces it.

## 10. What this document changed

Nothing outside documentation: this file, one row in `docs/69`, one section in
`CLAUDE.md`. No file under `src/`, `migrations/`, `tests/`, `plugin/` or
`panel/` was touched; the full suite was re-run after the documentation change
and its totals are recorded in the commit message. No production system,
database, router or FreeRADIUS configuration was contacted. No label moved. B1
is still UNRESOLVED. Nothing became HARDWARE VERIFIED.
