# Phase 0 — Operator Run Book

Companion to `docs/31-phase-0-hardware-test-protocol.md`. That document says
*what* to prove and *why*. This one is what you hold at the bench.

**No production code exists or is being written. Phase 0 is hardware only.**

---

## PRIME DIRECTIVE — read before the first command

> **Every RouterOS command in this document is UNVERIFIED.**
> It was written without access to a MikroTik, to RouterOS, or to any
> network reaching either. Treat every command as a *proposal to be
> confirmed on your unit*, never as known-good procedure.

This is not routine caution. On three separate occasions in the work leading
to this document, something was written, passed its own test, and would have
failed in production — because the test encoded the same assumption as the
code. RouterOS syntax written from memory is exactly that failure mode, with
a router at the end of it instead of a unit test.

**Step 0 exists to convert this document from proposal to procedure.** Do not
skip it, and do not begin Test A until it is complete.

Where a command below turns out to be wrong: **correct it in this file and
commit the correction.** The corrected file is the real deliverable of Phase
0, more than any single test result.

### Syntax note

RouterOS 7 accepts both `/interface wireguard print` (spaces) and
`/interface/wireguard/print` (slashes). Slashes are used throughout here.
If your unit rejects them, record that and use spaces.

---

## STEP 0 — Command validation pass

**Run on one unit, before anything else. Roughly one hour.**

Purpose: establish which command families exist on *your* hardware at *your*
RouterOS version, before any test depends on them. A `print` on a menu is
non-destructive and proves the menu exists.

### 0.1 Menu inventory

Run each. Record: **OK** (menu exists, prints), **MISSING** (no such item),
or **ERROR** (prints something else — record it verbatim).

| # | command | needed for | result |
|---|---|---|---|
| 1 | `/system/resource/print` | identity, version | |
| 2 | `/system/routerboard/print` | serial | |
| 3 | `/system/identity/print` | staging §3.1.3 | |
| 4 | `/system/package/print` | capability check | |
| 5 | `/interface/wireguard/print` | **the tunnel — critical** | |
| 6 | `/interface/wireguard/peers/print` | tunnel peer + handshake | |
| 7 | `/ip/address/print` | tunnel address | |
| 8 | `/ip/firewall/filter/print` | management lockdown | |
| 9 | `/ip/service/print` | REST availability | |
| 10 | `/certificate/print` | REST over TLS | |
| 11 | `/system/scheduler/print` | **watchdog — critical** | |
| 12 | `/system/script/print` | watchdog | |
| 13 | `/ip/hotspot/print` | Test A9 | |
| 14 | `/ip/pool/print` | hotspot addressing | |
| 15 | `/radius/print` | Test A10 | |
| 16 | `/radius/incoming/print` | **CoA — verify separately** | |
| 17 | `/queue/simple/print` | bandwidth enforcement | |
| 18 | `/tool/fetch` (with no args, read the error) | backend check-in | |
| 19 | `/file/print` | rollback file storage | |
| 20 | `/system/clock/print` | timestamps, cert validity | |
| 21 | `/system/default-configuration/print` | **Test E4** — manual says it exists | |
| 22 | `/ip/service/print` shows `www-ssl` | **REST** — manual: `/rest` needs it | |

**Manual vs device.** The RouterOS documentation was checked on 19 September
2026 and confirms WireGuard, REST at `/rest` (7.1beta4+, via `www-ssl`),
RADIUS for HotSpot with accounting, RADIUS attributes overriding profile
parameters, HotSpot with remote RADIUS, `protocol=radsec`,
`run-after-reset`, and `/system default-configuration`. **That confirms the
capabilities exist. It does not confirm the syntax below, on your version,
on your hardware.** The manual is the authority for *what is possible*; this
unit is the authority for *what you will type*. Step 0 does not become
optional because a feature is documented.

**If #5 is MISSING**, the WireGuard package is absent or the version is v6.
Stop, resolve, and restart Step 0. Nothing in this protocol works without it.

### 0.2 Argument validation

A menu existing does not mean the arguments below are accepted. For each,
**create, print, and delete** on the bench unit. Record the exact accepted
form.

```
# WireGuard interface — does it accept a chosen listen-port?
/interface/wireguard/add name=wg-test listen-port=13231
/interface/wireguard/print detail
/interface/wireguard/remove [find name=wg-test]

# Peer — confirm the argument NAMES, which differ between versions
/interface/wireguard/peers/add interface=wg-test public-key="..." \
    endpoint-address=1.2.3.4 endpoint-port=51820 \
    allowed-address=10.66.0.0/16 persistent-keepalive=25s
```

**Record the exact spelling your version accepts** for: `endpoint-address`,
`endpoint-port`, `allowed-address`, `persistent-keepalive`. These are the
arguments most likely to differ, and `persistent-keepalive` is the one the
entire push-vs-poll question rests on. If it is not accepted under that name,
find what it is called and **write it into this file before continuing.**

### 0.2b REST over the tunnel — the management interface

Per docs/30 §3b, REST is the steady-state management interface and scripting
is the bootstrap/recovery one. Validate REST now, because everything in A8
depends on it.

```
/certificate/add name=dn-rest common-name=<router identity>
/certificate/sign dn-rest
/ip/service/set www-ssl certificate=dn-rest disabled=no
/ip/service/set www-ssl address=10.66.0.0/16        # tunnel only, never WAN
```

Then from the gateway, over the tunnel:

```
curl -sk -u <user>:<pass> https://10.66.x.y/rest/system/resource
```

| check | result |
|---|---|
| `www-ssl` accepts a self-signed certificate | |
| `/rest` returns JSON | |
| `/ip/service/set www-ssl address=` restricts it to the tunnel | |
| REST is **NOT** reachable from the WAN | |

**The last row is a security gate, not a curiosity.** Confirm it from a host
outside the tunnel before any unit ships.

### 0.3 Key generation — confirm the private key never leaves

```
/interface/wireguard/add name=wg-keytest
/interface/wireguard/print detail
```

Confirm: a public key is shown, and the private key is either hidden or
clearly marked. **Record whether the private key is displayable.** The design
in docs/30 §6.2 assumes it stays on the device; if RouterOS will print it,
that assumption needs revisiting — not because the design breaks, but because
an operator could accidentally copy it into a ticket.

### 0.4 Export and restore — the rollback path

```
/export file=step0-baseline
/file/print
```

Then confirm restoration works **before** relying on it:

```
/system/reset-configuration run-after-reset=step0-baseline.rsc
```

**Record: does the file survive the reset, and does it apply?** This is the
mechanism the watchdog depends on. If it does not work as expected, the
watchdog design in §D changes, and Test D changes with it.

**Do this on the bench with physical access.** If it goes wrong, you need
hands on the unit.

### 0.5 Gate

Do not proceed to Test A until:

- every row in 0.1 is OK or has a recorded, working substitute
- 0.2 argument spellings are confirmed and written into this file
- 0.4 export/restore is confirmed working
- **this file has been corrected and committed** to reflect what your
  hardware actually accepts

---

## PRE-FLIGHT

### P.1 Bill of materials

| item | qty | recorded |
|---|---|---|
| hAP ax2 **or** ac2 (pick one, commit) | 2 | model: ________ |
| hEX S | 1 | |
| RB5009 (only if in the commercial offering) | 0–1 | |
| WireGuard VPS, static public IP | 1 | IP: ________ |
| Starlink Residential line | 1 | |
| MTN/Airtel LTE | 1 | |
| Office fixed line | 1 | |
| Ethernet cables, laptop with Ethernet | | |

### P.2 Gateway setup (the VPS)

```
# on the VPS
apt install wireguard-tools
wg genkey | tee gw.key | wg pubkey > gw.pub

# /etc/wireguard/wg0.conf
[Interface]
Address    = 10.66.0.1/16
ListenPort = 51820
PrivateKey = <gw.key>

# one [Peer] block per device, added at staging time:
# [Peer]
# PublicKey  = <router public key>
# AllowedIPs = 10.66.x.y/32

systemctl enable --now wg-quick@wg0
```

**Confirm the UDP port is open from outside** before staging any router:

```
# from another host
nc -u -z -v <vps-ip> 51820
```

**Do not use 192.168.88.0/24 for the tunnel subnet.** It is the MikroTik LAN
default and will collide.

### P.3 Device record — one per unit, filled at unboxing

```
Unit ID (yours):        ____________
Model:                  ____________
Serial (sticker):       ____________
Serial (routerboard):   ____________   match? Y / N
RouterOS as shipped:    ____________
RouterOS after upgrade: ____________
Upgrade duration:       ____________
WG public key:          ____________
Tunnel /32:             10.66.____.____
Staged by / at:         ____________
```

**Never record here:** the management password, the WireGuard private key, or
the RADIUS secret. Record *where* they are stored.

---

## RUN SHEET A — pre-staged device, end to end

Reference: docs/31 §4. **Start a timer at A2 and do not stop it until A5.**

| step | command / action | what to look for | result |
|---|---|---|---|
| A1 | Stage per docs/31 §3. Then `/export file=staged` | export matches intended staging | |
| A2 | Connect WAN to office line, power on. **TIMER START** | — | T₀ = ____ |
| A3 | `/ip/address/print` | a WAN lease appears | |
| A4 | On VPS: `wg show` | peer listed, `latest handshake` recent | |
| A5 | On VPS: `ping 10.66.x.y` | replies. **TIMER STOP** | T = ____ s |
| A6 | From VPS over tunnel, read `/system/routerboard/print` | serial matches P.3 | |
| A7 | Mark assigned in your notes (no software yet) | — | |
| A8 | Push config **from the VPS over the tunnel**, one step at a time (below) | each step recorded separately | |
| A9 | Phone joins SSID | redirected to a login page | |
| A10 | Enter test voucher | FreeRADIUS logs `Access-Accept`; Internet works | |
| A11 | Speed test | at or below plan rate. Record measured: ____ | |
| A12 | `radacct` | Start row, then Interim, octets growing | |
| A13 | Wait for expiry | access stops **with no intervention** | |
| A14 | `radacct` | Stop row, final octets | |

### A8 — push order, each confirmed before the next

```
 1. WAN            2. bridge        3. DHCP server    4. DNS
 5. IP pool        6. HotSpot       7. RADIUS client  8. walled garden
 9. firewall      10. NAT          11. queues        12. NTP + identity
```

After each: `/export file=a8-step-NN`. **The diff between consecutive
exports is the provisioning record** and is the evidence for docs/30 §11.

**A8 PASS** = all twelve applied over the tunnel with no local access.

### Result

```
TEST A    PASS / FAIL       date ______  unit ______  operator ______
T(power-on → ONLINE): ______ s          target: < 300 s
Failed at step: ______   Notes: ______________________________________
```

---

## RUN SHEET B — CGNAT

Reference: docs/31 §5. **Run the whole of Run Sheet A on each line.** Do not
shortcut to "the tunnel came up" — MTU faults appear at A8/A9, not A4.

| run | line | A completes | T(→ONLINE) | notes |
|---|---|---|---|---|
| B1 | **Starlink Residential** | | | **the primary case** |
| B2 | MTN / Airtel LTE | | | |
| B3 | Office fixed | | | control |

### B.idle — the test that decides push vs poll

**This single result determines the management plane architecture.**

For each of B1 and B2, after the tunnel is established:

```
1. Note the time. Send NO traffic from the VPS.
2. Wait 30 minutes.
3. From the VPS, INITIATE:    ping 10.66.x.y
4. Record: does it reply immediately, or only after a delay?
5. Repeat at 2 hours, then overnight (8h+).
```

| idle | B1 Starlink | B2 LTE |
|---|---|---|
| 30 min — immediate reply? | | |
| 2 h — immediate reply? | | |
| overnight — immediate reply? | | |
| `persistent-keepalive` used | | |
| lowered to? (if needed) | | |

**PASS** — VPS-initiated traffic gets an immediate reply at every interval.
Push-based management is viable. Proceed.

**FAIL** — replies only after the router's own keepalive fires, or not at
all. **Stop.** Management must be poll-based: the router asks for work
rather than the backend pushing it. That is a different provisioning
system, and docs/30 §4.2 Phase 2 must be redesigned before any of it is
written. This is the cheapest possible place to learn it.

### B.mtu

From the VPS, over the tunnel, largest size that passes:

```
ping -M do -s 1400 10.66.x.y     # then 1380, 1360, 1340, 1300
```

| line | largest passing | WG MTU set to |
|---|---|---|
| B1 Starlink | | |
| B2 LTE | | |
| B3 Office | | |

**If B1 and B2 differ, MTU must be set per-device from measurement**, not
from a constant in a staging script.

---

## RUN SHEET C — interruption

Reference: docs/31 §6. **Required outcome throughout: no physical
intervention, no factory reset.**

| run | action | required | result |
|---|---|---|---|
| C1 | Pull WAN during A8 | staged config intact; tunnel returns; push **resumes** | |
| C2 | **Cut power during A8** | as C1. The likely field failure | |
| C3 | Cut power during RouterOS upgrade | **record honestly.** If it bricks → Netinstall → upgrades are bench-only, forever | |
| C4 | Stop the VPS 1 hour, restart | router reconnects unaided | |
| C5 | Push a deliberately invalid config | rejected, recorded, router still manageable | |

```
C1 __  C2 __  C3 __  C4 __  C5 __
Any run needing physical access?  Y / N   ← a Y is a Phase 0 failure
```

---

## RUN SHEET D — the watchdog

> **The command in docs/31 §3.3 is ILLUSTRATIVE, NOT PROCEDURE.** It was
> written without a RouterOS to test against. Validate it here, incrementally,
> before it is ever used on a shipped unit.

**Test only on a unit you can physically reach.** A watchdog that misfires on
a remote router is the fleet-wide outage docs/31 warns about.

### D.0 — a simpler design to validate first

The continuous watchdog in docs/31 §3.3 must track "time since last
check-in", which is fiddly in RouterOS scripting and easy to get subtly
wrong. **Consider validating the commit-confirm pattern instead** — the same
protection, much simpler semantics:

```
Before a risky push:
  1. Schedule a ONE-SHOT revert, N minutes out
  2. Push the configuration
  3. If management still works → DELETE the scheduled revert
  4. If management is lost   → it fires, and the router comes back
```

No state tracking, no clock arithmetic, and the failure mode is explicit. It
is the standard approach on other network platforms for the same reason.

**Validate both if time allows; adopt whichever proves reliable.** Record
which, and why, and correct docs/31 §3.3 to match.

### D.1 — build it up in pieces

| # | validate | result |
|---|---|---|
| 1 | A scheduler entry can be created and fires at all | |
| 2 | It can be created as one-shot (fires once, does not repeat) | |
| 3 | A script can run `/system/reset-configuration run-after-reset=` | |
| 4 | The rollback file survives and applies (confirms §0.4) | |
| 5 | The scheduler entry can be deleted before it fires | |
| 6 | After firing, the router is reachable on the tunnel again | |

**Record the exact working syntax for each.** That syntax, not my snippet, is
the production procedure.

### D.2 — the real tests

| run | action | required | result |
|---|---|---|---|
| D1 | Push a config that breaks the tunnel (e.g. a filter dropping it) | watchdog fires; router reverts; tunnel returns | |
| D2 | Time D1 from breakage to recovery | = worst-case misconfiguration outage: ____ min | |
| D3 | Leave a healthy unit running 24 h | watchdog does **NOT** fire | |

**D3 matters as much as D1.** A watchdog firing on a healthy device is worse
than no watchdog.

---

## RUN SHEET E — the reset button

Reference: docs/31 §6, Test E. The customer will press it.

| run | action | record |
|---|---|---|
| E1 | `/system/reset-configuration` | staging gone? Y/N |
| E2 | Hard reset (button held at boot) | staging gone? Y/N |
| E3 | `reset-configuration run-after-reset=staged.rsc` | restores itself? Y/N |
| E4 | **Can a custom default configuration survive a HARD reset?** Investigate `/system/default-configuration`, a defconf package, or any supported mechanism on your version | **the highest-value unknown in Phase 0** |

```
E4 RESULT:  YES / NO / NOT POSSIBLE ON THIS VERSION
Mechanism (if yes): ___________________________________________

If NO → an RMA / re-staging path is REQUIRED before the first unit
        ships. Write it, and name the person who owns it: ___________
```

---

## EVIDENCE TEMPLATE — one per unit

```
════════════════════════════════════════════════════════════════
PHASE 0 EVIDENCE      unit ______   operator ______   date ______
════════════════════════════════════════════════════════════════
Model ____________  Serial ____________  RouterOS ____________

STEP 0  command validation         COMPLETE / INCOMPLETE
        commands corrected in docs/32:  ______
        docs/32 corrections committed:  Y / N

TEST A  end to end                 PASS / FAIL
        T(power-on → ONLINE) ______ s      (target < 300)
        failed at step ______

TEST B  CGNAT
        B1 Starlink   PASS / FAIL      T ______ s
        B2 LTE        PASS / FAIL      T ______ s
        B3 Office     PASS / FAIL      T ______ s

        IDLE REACH — decides push vs poll
          30 min      B1 __  B2 __
          2 h         B1 __  B2 __
          overnight   B1 __  B2 __
          keepalive used ______

        MTU  B1 ______  B2 ______  B3 ______

TEST C  interruption
        C1 __  C2 __  C3 __  C4 __  C5 __
        physical access ever needed?  Y / N

TEST D  watchdog
        design validated: continuous / commit-confirm
        D1 __  D2 ______ min  D3 __

TEST E  reset
        E1 __  E2 __  E3 __
        E4 survives hard reset:  YES / NO
        RMA path written:        Y / N / n-a

────────────────────────────────────────────────────────────────
ACCEPTED FOR PRODUCTION:   YES / NO
Blocking findings:
  ______________________________________________________________
  ______________________________________________________________
Attachments: exports before/after · wg show · radacct rows ·
             timings · photographs of the CGNAT setups
════════════════════════════════════════════════════════════════
```

---

## WHEN A COMMAND IS WRONG

It will happen — see the Prime Directive. When it does:

1. **Record what you ran and what it said**, verbatim. "Didn't work" is not
   a finding; the error text is.
2. Find the correct form on your version (`?` at the menu, or the RouterOS
   documentation for **that** version).
3. **Correct this file** and commit it.
4. If the correction changes *what is possible* rather than *how it is
   spelled* — a missing capability, not a renamed argument — stop and raise
   it. That is an architecture finding, not a typo, and docs/30 may need to
   change.

A corrected run book that reflects real hardware is worth more than a clean
one written from assumptions. That is the entire lesson of the work that
produced it.

---

## THE GATE

Per docs/31 §8, all six must hold before Phase 1 hardware work continues and
before **any** of docs/30 Phases 2–4 begins:

```
[ ] 1. Test A unattended, power-on → ONLINE under 5 minutes
[ ] 2. Test B1 passes on real Starlink, INCLUDING idle reach
[ ] 3. C1, C2, C4, C5 recover with no physical intervention
[ ] 4. D1 reverts, D3 does not misfire
[ ] 5. E1–E3 documented, E4 answered, RMA path written if negative
[ ] 6. Voucher lifecycle A10–A14 works, including automatic expiry
[ ] 7. REST reachable ON the tunnel and NOT from the WAN (Step 0.2b)
```

**Failing this gate is a cheap, successful outcome.** Four routers and a week
against a platform built on an assumption.

docs/30 Phase 1 — tenancy and the customer principal — depends on none of
this and can proceed in parallel.
