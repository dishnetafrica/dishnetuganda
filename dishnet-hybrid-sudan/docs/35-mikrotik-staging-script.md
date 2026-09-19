# MikroTik staging script — Phase 0

What to configure on a router so it joins DishNet by itself. Bench
procedure, per docs/31 §3.

> **EVERY COMMAND HERE IS UNVERIFIED.** Written with no access to a MikroTik
> or to RouterOS. **Run docs/32 Step 0 first** — it takes about an hour and
> converts this file from a proposal into a procedure. When a command is
> wrong, record the error verbatim, correct this file, and commit it.

**Do this on the bench, with the router in front of you.** Several steps can
cut management access if they are wrong, and on a bench that costs a cable
rather than a site visit.

---

## What is staged, and what is deliberately not

| staged now | pushed later, over the tunnel |
|---|---|
| identity, RouterOS version | WAN, bridge, DHCP, DNS, pools |
| management user | HotSpot, login page, walled garden |
| **WireGuard tunnel + keepalive** | RADIUS client, accounting |
| firewall: management on tunnel only | NAT, queues, bandwidth profiles |
| REST over the tunnel | everything else |
| watchdog + rollback baseline | |

The reason for that split is docs/31 §3: the local step must be small and
fast, and everything complicated must happen where it can be observed,
retried and rolled back. A router that fails at hotspot configuration should
still be reachable; a router that fails at tunnel configuration is a site
visit.

---

## DishNet constants

```
Gateway public key : ftGs/7LjO/aVmKJ9xS/fz+QDt73JcGI+X3vgSCZpJT4=
Gateway endpoint   : 209.97.137.203 : 51820
Tunnel subnet      : 10.66.0.0/24
Gateway address    : 10.66.0.1
This router        : 10.66.0.11      ← unique per device, from the registry
```

**Nothing secret is in that list.** A public key is public; the endpoint is
public. Nothing in the staging bundle compromises another router.

---

## 0. Record before touching anything

```
/system/resource/print
/system/routerboard/print
/system/package/print
```

Write down: **serial-number**, model, RouterOS version, architecture. Compare
the reported serial against the sticker — docs/30 §6.3's claim model depends
on them matching.

## 1. RouterOS version

Baseline is 7.x. WireGuard needs v7; REST needs 7.1beta4+.

```
/system/package/update/check-for-updates
/system/package/update/install
```

**Bench only, never remotely** — a power cut mid-upgrade may need Netinstall
(docs/31 Test C3). Record before/after versions and how long it took; that
time is a real per-unit staging cost.

## 2. Identity and clock

```
/system/identity/set name="DN-XXXXXXXX"
/system/ntp/client/set enabled=yes servers=time.google.com,time.cloudflare.com
/system/clock/print
```

Use the serial's tail in the name. **NTP matters more than it looks** — a
certificate cannot validate against a wrong clock, and REST in step 6 depends
on one.

## 3. Management user

```
/user/add name=dnmgmt password="<GENERATE A UNIQUE ONE>" group=full \
    comment="DishNet management"
```

**A unique password per device, stored encrypted in the registry.** A shared
one means a single recovered router hands over the fleet. `group=full` is
Phase 0 convenience; narrow it to a purpose-built group before production.

Leave the `admin` account alone until the tunnel is proven. Disabling it
before you can get back in is how a bench unit becomes a paperweight.

## 4. WireGuard — the router makes its own key

```
/interface/wireguard/add name=wg-dishnet listen-port=13231 \
    comment="DishNet management"
/interface/wireguard/print detail
```

The `print detail` shows a **public key**. That is the only value that leaves
the device. **The private key is generated here and never travels** — docs/30
§6.2. Send the public key to whoever maintains the registry.

```
/interface/wireguard/peers/add interface=wg-dishnet \
    public-key="ftGs/7LjO/aVmKJ9xS/fz+QDt73JcGI+X3vgSCZpJT4=" \
    endpoint-address=209.97.137.203 \
    endpoint-port=51820 \
    allowed-address=10.66.0.0/24 \
    persistent-keepalive=25s \
    comment="DishNet gateway"

/ip/address/add address=10.66.0.11/24 interface=wg-dishnet \
    comment="DishNet management"
```

**`persistent-keepalive` is the single most important argument in this file.**
WireGuard is UDP; behind CGNAT the NAT mapping closes when idle, and once it
closes DishNet cannot initiate anything toward the router. Test B1 measures
whether 25s is sufficient on Starlink. **If your version rejects the argument
under this name, find the correct spelling and write it into this file before
going further.**

## 5. Firewall — management on the tunnel, never on the WAN

```
/ip/firewall/filter/add chain=input action=accept in-interface=wg-dishnet \
    comment="DishNet management over tunnel" place-before=0
/ip/firewall/filter/print
```

`place-before=0` puts it first; a default-config `drop` rule ahead of it
would silently defeat it. **Print and read the list** — do not assume the
ordering took.

```
/ip/service/set telnet disabled=yes
/ip/service/set ftp    disabled=yes
/ip/service/set www    disabled=yes
/ip/service/set api    disabled=yes
/ip/service/set api-ssl disabled=yes
/ip/service/print
```

REST supersedes the binary API, so it is switched off rather than left
listening.

## 6. REST, on the tunnel only

```
/certificate/add name=dn-rest common-name="DN-XXXXXXXX" key-size=2048
/certificate/sign dn-rest
/certificate/print
```

Signing is asynchronous — wait for it to finish before the next command.

```
/ip/service/set www-ssl certificate=dn-rest disabled=no address=10.66.0.0/24
/ip/service/print
```

**`address=10.66.0.0/24` is a security control, not a preference.** Without
it, REST listens on every interface including the WAN. docs/32 gate item 7
requires confirming from outside that it does not answer there.

## 7. Watchdog

**Do not copy a watchdog from this file.** docs/32 §D says to validate it in
six pieces first, and offers the simpler commit-confirm pattern as an
alternative. An untested watchdog is a scheduled outage waiting for its
condition.

Stage the rollback baseline now regardless:

```
/export file=dn-staged
/file/print
```

`dn-staged.rsc` is what a recovery reverts to — a manageable router, not a
factory-reset one.

## 8. Verify before disconnecting

```
/interface/wireguard/peers/print detail
/ping 10.66.0.1 count=4
```

Look for a recent handshake and four replies. **On the gateway**, confirm the
other half:

```
wg show
ping 10.66.0.11
```

Both directions, or it is not working. Outbound alone proves nothing — that
was the lesson of the MacBook test, where 888 B sent with nothing received
looked exactly like a firewall block.

## 9. Register, then ship

Record: serial, model, RouterOS version, **WireGuard public key**, tunnel
address, management password (encrypted), staged-at, staged-by.

---

## Server side — one block per router

```bash
cd /etc/wireguard
cp wg0.conf wg0.conf.bak.$(date +%s)
cat >> wg0.conf <<'EOF'

[Peer]
# DN-XXXXXXXX — serial XXXXXXXX
PublicKey  = <THE ROUTER'S PUBLIC KEY FROM STEP 4>
AllowedIPs = 10.66.0.11/32
EOF
chmod 600 wg0.conf
wg syncconf wg0 <(wg-quick strip wg0)
wg show
```

**`wg syncconf`, never `systemctl restart`.** Once routers are attached, a
restart drops every live tunnel; syncconf applies peer changes without
touching the others. Build the habit while there is only one.

`AllowedIPs` is a **/32** on the server — one address per router. A wider
mask would let one router claim another's traffic.

---

## What will probably go wrong first

**The tunnel will not come up, and it will look like a firewall problem.**
WireGuard is silent to unknown keys, so "the gateway does not have this
router's public key yet" and "UDP 51820 is blocked" are indistinguishable
from the router. On the gateway:

```bash
timeout 30 tcpdump -ni any udp port 51820
```

Packets arriving from the router's public IP → the perimeter is fine, it is a
key or config mismatch. Nothing at all → the packets never left the router's
network, and the fault is upstream of DishNet entirely.

That is exactly the symptom the MacBook produced on 19 September, and the
command that resolved it in seconds.
