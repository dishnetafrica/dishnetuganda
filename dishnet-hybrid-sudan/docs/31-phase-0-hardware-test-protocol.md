# Phase 0 — Hardware Test Protocol

**This is a bench protocol, to be executed by a person with hardware. No part
of it can be run or verified from the development environment — there is no
network path from there to a MikroTik, and no MikroTik to reach.**

Its purpose is to answer one question before any production code exists:

> Can DishNet pre-stage a MikroTik, ship it, have the customer plug it in
> behind an ordinary Ugandan Internet connection, and have it come under
> management, configure itself, serve a hotspot and account for a voucher —
> reliably, and recoverably when it goes wrong?

If the answer is no, the product described in the brief does not yet exist,
and the architecture changes before anything is built on it.

**Decision recorded 19 September 2026:** DishNet-supplied, pre-staged
hardware is the primary commercial model. Customer-owned MikroTik is a later
compatibility path and is **out of scope for Phase 0**. Consequently the
phone/QR local-bootstrap flow in docs/30 §4.2 Phase 1 is **not tested here**.
Staging happens on a DishNet bench, where none of it is hard.

---

## 0. What pre-staging changes, and what it does not

**Removed from the critical path entirely:**

| problem | why it is gone |
|---|---|
| factory-reset router knows nothing of DishNet | the bench teaches it before it ships |
| phone dual-connectivity (Wi-Fi + cellular) | no phone involved |
| non-Wi-Fi models cannot be reached by phone | bench uses Ethernet |
| QR pairing token lifetime, replay, nonce | no field pairing |
| RouterOS 6 upgrade in the field | bench upgrades before shipping |

**Not removed, and still the real risk:**

| problem | why it survives |
|---|---|
| **the tunnel through CGNAT** | the customer's connection is whatever it is |
| **NAT mapping expiry** | DishNet must reach *in*, not just receive |
| **MTU through CGNAT/PPPoE** | silent, and breaks large pushes only |
| **interrupted provisioning** | power and links fail in Kampala |
| **customer presses the reset button** | staging is erased; what then? |
| **WAN type differs from the bench** | staged for DHCP, delivered to PPPoE |

Tests A–E below target exactly those six. Everything pre-staging solved is
not re-tested.

### 0.1 The CGNAT case is DishNet's own product

**This deserves stating plainly because it changes what Test B means.**
Starlink Residential is CGNAT. DishNet's own residential customers have no
public IP. So the primary deployment condition for a managed MikroTik is
*behind a DishNet Starlink Residential connection*.

Test B is therefore not a synthetic edge case to tick off. It is the normal
case, and it must be run on an actual Starlink Residential line — not only
on mobile data — because the two behave differently: Starlink's CGNAT has
its own NAT timeout behaviour and its own MTU characteristics.

---

## 1. Bench setup

### 1.1 Hardware to obtain

Deliberately small, per the operator's instruction — 2 to 4 models, not 20.

| # | model | why this one |
|---|---|---|
| 2 | **hAP ac2** *or* **hAP ax2** | the reference Wi-Fi unit. Two, so one can be destroyed by testing while the other stays known-good |
| 1 | **hEX S** | the non-Wi-Fi case. Ethernet-only bench staging, and the model most likely to be sold to a reseller site |
| 1 | **RB5009** *(optional)* | only if resellers will be sold this class. Skip for Phase 0 if not |

**Choose one Wi-Fi model as the reference and commit to it.** Testing ac2
and ax2 in parallel doubles the matrix and answers nothing extra in Phase 0.
ax2 is the forward-looking choice; ac2 is the cheaper and more widely
stocked. This is a commercial decision, not a technical one.

### 1.2 Connections needed

| connection | purpose | notes |
|---|---|---|
| **Starlink Residential** | the real CGNAT case | **required** — this is the primary deployment condition |
| **MTN or Airtel LTE** | a second, different CGNAT | confirms it is not Starlink-specific |
| office fixed line | the bench | a public IP here makes failure diagnosis far easier |

### 1.3 The management gateway

A WireGuard endpoint with a **stable public IP or DNS name**. For Phase 0 a
single small VPS is sufficient — this is not yet the production concentrator
and must not be confused with it.

```
DishNet MGMT Gateway (Phase 0 bench)
  - public IPv4, one UDP port open (51820 or chosen)
  - wireguard-tools
  - tunnel subnet, e.g. 10.66.0.0/16   (must not collide with any
    customer LAN; 192.168.88.0/24 is MikroTik default — never use it)
  - one /32 per device, recorded against its serial
```

### 1.4 Record before touching anything

For each unit, before any configuration:

```
/system/resource/print          → version, board-name, architecture
/system/routerboard/print       → serial-number, model, firmware
/system/package/print           → which packages are present
```

**Record the serial from the sticker too, and confirm it matches
`routerboard.serial-number`.** The claim model in docs/30 §6.3 depends on
that serial being the device's real identity; if the printed and reported
serials differ, the registry design changes.

---

## 2. RouterOS version policy

**Baseline: RouterOS 7.x.** Per the operator's instruction, v6 is a
migration case and not a target.

Test **two** versions per model, and record both exactly:

1. **The version the unit ships with** — this is what a real device arrives
   running, and staging must cope with it or upgrade it.
2. **Current stable at the time of testing** — the version DishNet will
   standardise on.

**Do not take a version number from this document.** Read the current stable
from mikrotik.com on the day, record it in the matrix, and note the channel
(stable / long-term). If the shipped version is v6, record how long the
upgrade path to v7 takes and whether it needs an intermediate hop — that
time is part of the staging cost per unit.

### 2.1 Capabilities to confirm per model and version

Do not assume any of these. Confirm each, and record the command output.

**Documentation status, checked 19 September 2026.** The RouterOS manual
confirms each capability below *exists*. It does not confirm the syntax on
your unit, and it is not the authority for procedure — the device is. Marked
**[doc]** where the manual confirms the capability.

| capability | how to confirm |
|---|---|
| WireGuard present **[doc]** | `/interface/wireguard/print` does not error |
| REST API **[doc]** — 7.1beta4+, served at `/rest`, needs `www-ssl` | `/ip/service/print` shows `www-ssl`; enable it with a certificate; `GET https://<router>/rest/system/resource` returns JSON |
| REST on a **self-signed** certificate | generate one, bind `www-ssl`, call `/rest` over the tunnel. If it refuses, per-device PKI becomes a fleet cost |
| **RadSec** `protocol=radsec` **[doc]** | `/radius/add protocol=radsec ...` is accepted. **See E6 — worth one extra test** |
| `/system default-configuration` **[doc]** | `/system/default-configuration/print` — feeds E4 |
| certificate support | `/certificate/print` and a self-signed generate |
| scripting + scheduler | `/system/scheduler/print`, `/system/script/print` |
| HotSpot | `/ip/hotspot/print` |
| RADIUS + CoA | `/radius/print`, `/radius/incoming/print` |
| `/export` completeness | `/export file=baseline` then read it back |
| reset behaviour | §6, Test E |

---

## 3. The staging procedure (the thing being tested)

This is the bench procedure that becomes the product. Run it by hand in
Phase 0; automate it only once it is proven.

**Principle carried from docs/30 §4.2: stage the minimum.** Identity, a
management user, the tunnel, and a watchdog. Nothing else. HotSpot, RADIUS,
pools, firewall and queues are pushed later, from the backend, over the
tunnel — because that is the part that must be provably recoverable.

### 3.1 Steps

```
1. Unbox. Record serial, model, RouterOS version (§1.4).
2. Upgrade RouterOS to the standard version. Record before/after and duration.
3. Set identity:            /system/identity/set name=DN-<serial-tail>
4. Create the management user with a generated password (never a shared one).
   Record where that password is stored — it must end up encrypted at rest,
   per docs/30 §7.3.
5. Generate a WireGuard keypair ON THE ROUTER:
      /interface/wireguard/add name=wg-dishnet listen-port=13231
      /interface/wireguard/print   → read the PUBLIC key
   The private key never leaves the device. Register the PUBLIC key plus the
   serial in the registry. This is the trust relationship the operator
   described: hardware identity + DishNet-issued identity + management key.
6. Add the gateway as a peer, with keepalive:
      /interface/wireguard/peers/add interface=wg-dishnet \
         public-key="<gateway pubkey>" \
         endpoint-address=<gateway host> endpoint-port=<port> \
         allowed-address=10.66.0.0/16 persistent-keepalive=25s
7. Address the tunnel:  /ip/address/add address=10.66.x.y/32 interface=wg-dishnet
8. Allow the gateway in, on the tunnel only:
      /ip/firewall/filter — accept from 10.66.0.0/16 in-interface=wg-dishnet
      and confirm management is NOT reachable from the WAN interface.
9. Install the watchdog (§3.3).
10. /export file=staged   → keep this. It is the rollback target.
11. Register in the device registry: serial, model, version, WG public key,
    tunnel /32, staged-at, staged-by.
12. Power off. Ship.
```

**`persistent-keepalive` in step 6 is the single most important line in this
protocol.** WireGuard is UDP; behind CGNAT the NAT mapping closes when idle,
and once it closes DishNet can no longer initiate anything toward the
router. The keepalive is what holds the mapping open. 25s is the
conventional value. **Test B measures whether it is sufficient on Starlink
and on LTE**, and it may need to be lower on one of them.

### 3.2 What must NOT be staged

- No shared secret common across devices. One leaked unit must not
  compromise the fleet.
- No customer data. The device does not know who it belongs to; the registry
  does. Assignment happens server-side.
- No HotSpot, RADIUS, pools, queues or NAT — those are Phase 2 pushes, and
  the point is to prove they can be pushed and rolled back remotely.

### 3.3 The watchdog

The defence against locking DishNet out of a remote router. A scheduled
script that restores the last known-good export if the backend has not
checked in within a set window.

```
/system/scheduler/add name=dn-watchdog interval=10m \
  on-event="<script: if no backend check-in since N, /system/reset-configuration \
             run-after-reset=staged.rsc>"
```

**Test it deliberately in Test D.** An untested watchdog is worse than none:
it is a scheduled outage waiting for its condition to be met.

---

## 4. Test A — pre-staged device, end to end

**The headline test.** Everything else exists to explain a failure of this one.

| step | action | pass evidence |
|---|---|---|
| A1 | Stage per §3, power off, disconnect | `/export` matches the staged baseline |
| A2 | Connect WAN to the office line. Power on. **Start a timer.** | — |
| A3 | Router obtains WAN address | `/ip/address/print` shows a WAN lease |
| A4 | Tunnel comes up unaided | On the **gateway**: `wg show` lists the peer with a recent handshake |
| A5 | DishNet sees it | Gateway can `ping 10.66.x.y`. **Record time from A2.** |
| A6 | Identity confirmed | Gateway reads serial over the tunnel; it matches the registry |
| A7 | Assign to a test customer | Registry state → assigned |
| A8 | Push config **from the gateway, over the tunnel** — WAN, bridge, DHCP, DNS, pool, HotSpot, RADIUS, walled garden, firewall, NAT, queues, NTP | each step recorded individually; `/export` afterwards |
| A9 | HotSpot serves a login page | A phone joins the SSID and is redirected |
| A10 | RADIUS authenticates a voucher | FreeRADIUS logs Access-Accept; phone reaches the Internet |
| A11 | Bandwidth is enforced | Speed test at/below the plan rate. **Record measured vs configured.** |
| A12 | Accounting records | `radacct` has Start, then Interim, with growing octets |
| A13 | Voucher expires | Access stops **without manual intervention** |
| A14 | Session closes cleanly | `radacct` Stop row with final octets |

**Pass:** A2 → A5 under **five minutes** with no human touching the router,
and A9 → A14 complete.

**Record regardless of pass:** time to tunnel, time to full config, total
unattended time, and anything a customer would have had to do.

---

## 5. Test B — CGNAT, on the connection customers actually have

Run the **whole of Test A** on each connection below. Do not shortcut to
"the tunnel came up" — MTU problems appear only on large transfers, which
means A8 and A9, not A4.

| run | connection | why |
|---|---|---|
| **B1** | **Starlink Residential** | the primary deployment condition. **Required.** |
| **B2** | MTN or Airtel LTE | a different CGNAT implementation |
| **B3** | office fixed line | control — if B1/B2 fail and B3 passes, it is the CGNAT |

### 5.1 The two failures that hide

**NAT mapping expiry — the one that matters.** After the tunnel is up,
**leave it idle for 30 minutes with no traffic from DishNet**, then initiate
*from the gateway*:

```
gateway$ ping 10.66.x.y
gateway$ ssh / REST call to the router over the tunnel
```

**Pass:** the router answers without having initiated anything first.
**Fail:** it answers only after the router's own keepalive fires — meaning
DishNet cannot reliably reach in, and every management action becomes a wait.

Repeat at 30m, 2h, and overnight. Record `persistent-keepalive` and whether
it had to be lowered. **A failure here is a finding, not a defect** — it may
mean management must be poll-based rather than push-based, which is an
architectural change worth knowing before it is built.

**MTU.** Over the tunnel, from the gateway:

```
gateway$ ping -M do -s 1400 10.66.x.y      # then 1380, 1360, 1340
```

Record the largest size that passes on each connection, and set the
WireGuard MTU below it. **A large config push failing while ping succeeds is
the classic symptom.** Note that Starlink and LTE may differ, and if they do,
MTU must be set per-device from a measurement, not from a constant.

---

## 6. Tests C, D, E — the ways it goes wrong

### Test C — interrupted provisioning

Per the operator's requirement: no manual factory reset shall be needed.

| run | interruption | required outcome |
|---|---|---|
| C1 | Pull WAN mid-push (step A8) | router keeps staged config; tunnel returns when WAN does; push **resumes**, not restarts |
| C2 | **Cut power mid-push** | same. This is the likely field failure |
| C3 | Cut power **during RouterOS upgrade** | record honestly — this may brick and need Netinstall. If so, upgrade only on the bench, never remotely |
| C4 | Gateway down 1 hour | router reconnects unaided when it returns |
| C5 | Push a deliberately invalid config | rejected and recorded; router stays manageable |

**Pass:** in C1, C2, C4, C5 the router is reachable again with no physical
intervention, and provisioning converges to the intended configuration.

### Test D — the watchdog

| run | action | required outcome |
|---|---|---|
| D1 | Push a config that breaks management (e.g. a firewall rule dropping the tunnel) | watchdog fires; router reverts to `staged.rsc`; tunnel returns |
| D2 | Record how long D1 takes | this is the worst-case outage for a misconfiguration |
| D3 | Normal operation for 24h | watchdog does **not** fire spuriously |

D3 matters as much as D1. A watchdog that fires on a healthy device is a
fleet-wide outage.

### Test E — the customer presses reset

**Not in the brief, and it will happen.** A pre-staged router that is factory
reset has lost everything: identity, keys, tunnel. It is then a
customer-owned device — the path explicitly deferred to a later phase.

| run | action | record |
|---|---|---|
| E1 | Soft reset `/system/reset-configuration` | is staging gone? |
| E2 | Hard reset (button held at boot) | is staging gone? |
| E3 | Reset with `run-after-reset=staged.rsc`, file present | does it restore itself? |
| E4 | Investigate whether a **custom default configuration** can survive a hard reset. The manual confirms `/system/default-configuration/print` exists and that devices hold an internally stored default configuration, and that factory reset loads it **[doc]** | **REQUIRES VERIFICATION** — the manual confirms the mechanism exists, not that DishNet's configuration can become it. Highest-value unknown in Phase 0 |
| E5 | If E4 is negative: can `run-after-reset=staged.rsc` be made the standard recovery instruction given to a customer by phone? | a support path that does not need an RMA |
| **E6** | **RadSec CoA.** Configure `protocol=radsec`, establish it from the router, then send a Disconnect **from the server** over the existing NAS-initiated TLS connection | **does revocation work without inbound reachability?** |

**Why E6 is worth the extra hour.** CoA and Disconnect are server-initiated,
so they have the same inbound problem as management. If Test B1's idle-reach
fails, management goes poll-based — and voucher revocation goes with it,
because a reseller revoking a code would wait for the next poll. RadSec is
RADIUS over TLS on a connection the router opens outbound; if RouterOS
accepts CoA back down that connection, revocation stays immediate even in
the poll-based world. A positive result here de-risks the whole B1-fails
branch. See docs/30 §5.2.

**Why E4 matters more than it looks.** If staging can be made to survive a
factory reset, then a reset router re-joins DishNet by itself, the support
path for "I reset it" is "plug it back in", and the deferred customer-owned
flow becomes far less urgent. If it cannot, DishNet needs a documented RMA
or re-staging path **before** the first unit ships, because the first
customer to press that button will otherwise have a dead router and no route
back.

---

## 7. Compatibility matrix — to be filled by hand

One row per model × RouterOS version. Empty until tested; nothing here is
pre-filled, because a guess in this table is worse than a blank.

| | hAP ac2/ax2 (shipped ver) | hAP ac2/ax2 (standard ver) | hEX S | RB5009 |
|---|---|---|---|---|
| RouterOS version tested | | | | |
| Serial readable, matches sticker | | | | |
| Upgrade path + duration | | | | |
| WireGuard present | | | | |
| REST API reachable | | | | |
| Certificate generate | | | | |
| Scheduler/scripting | | | | |
| HotSpot | | | | |
| RADIUS auth | | | | |
| RADIUS accounting | | | | |
| CoA / Disconnect | | | | |
| Tunnel via Starlink (B1) | | | | |
| Tunnel via LTE (B2) | | | | |
| Gateway-initiated after 30m idle | | | | |
| Gateway-initiated after overnight | | | | |
| Working MTU | | | | |
| keepalive required | | | | |
| Survives WAN interruption (C1) | | | | |
| Survives power cut (C2) | | | | |
| Watchdog reverts (D1) | | | | |
| Survives factory reset (E4) | | | | |
| Staging time per unit | | | | |
| Time to ONLINE from power-on | | | | |
| **Accepted for production** | | | | |

**Staging time per unit is a commercial number, not a technical one.** At
scale it is a per-device cost in technician minutes, and it decides whether
staging is done in-house or by the supplier.

---

## 8. Pass / fail

**Phase 0 passes only if all of these hold on at least the reference Wi-Fi
model and the hEX S:**

1. Test A completes unattended, power-on to ONLINE in **under five minutes**.
2. Test B1 passes on a **real Starlink Residential** line — including
   gateway-initiated reach after 30 minutes idle.
3. C1, C2, C4 and C5 all recover with **no physical intervention**.
4. D1 reverts and D3 does not fire spuriously.
5. E1–E3 are documented, and E4 answered either way with a written support
   path if the answer is no.
6. The voucher lifecycle A10–A14 works, including automatic expiry.

**Anything short of that is a Phase 0 failure**, and a Phase 0 failure is a
cheap, successful outcome — it costs four routers and a week, against
building a platform on an assumption.

### 8.1 What each failure would mean

| fails | consequence for the architecture |
|---|---|
| A5 over 5 min | the "plug it in and it joins" promise needs re-wording before it is sold |
| **B1 idle reach** | management becomes **poll-based, not push-based** — a fundamental change to docs/30 §4.2 Phase 2, and far cheaper to learn now |
| B1 but not B2 | per-connection MTU/keepalive profiles, set from measurement |
| C2 | remote configuration is unsafe; staging must carry more, and push less |
| C3 | RouterOS upgrades never happen remotely — bench only, forever |
| D1 | no safe remote reconfiguration at all; this blocks Phase 2 outright |
| E4 negative | an RMA/re-staging path is required **before first shipment** |

---

## 9. Evidence to keep

For every run, keep:

- `/export` before and after — the diff **is** the provisioning record
- gateway `wg show` output at each stage
- timings, written down at the time rather than reconstructed
- FreeRADIUS `radacct` rows for A12/A14
- photographs of the physical setup for the CGNAT runs
- **every failure, in full** — a failure that is explained away in the
  moment is the one that returns at 200 units

**Do not record**: the management password, the WireGuard private key, or
the RADIUS shared secret. Record *where* they are stored, not what they are.

---

## 10. After Phase 0

Only once section 8 passes:

- fill the matrix, and **fix the supported model list** at what was tested —
  not at what is expected to work
- convert §3 into an automated staging tool, with its output diffed against
  the hand-staged baseline that passed
- then begin docs/30 Phase 1 (tenancy and the customer principal), which is
  independent of all of this and can be built in parallel by anyone not
  holding a screwdriver

**Nothing in docs/30 Phases 2–4 should start before §8 passes.** The whole
point of Phase 0 is that it is allowed to change the answer.
