# Starlink Installation — Research Report

**Status:** research only. No code or manual has been changed on the strength of this document.
**Date:** 12 September 2026
**Scope:** DishNet Africa Ltd, Uganda. Plugin `dishnet-hybrid-sudan`, branch `claude/study-this-jhe2eg`.

---

## 0. What I could and could not read — read this first

This matters more than any conclusion below, because it determines which parts you can rely on.

**Blocked.** The environment this research ran in cannot fetch any external URL. `starlink.com`,
the PDF mirrors, Wikipedia and YouTube all fail identically at the egress proxy
(`gateway answered 403 to CONNECT`). So I did **not** read:

- `installation_guide_standard_kit.pdf` — not opened
- the video library at `starlink.com/videos/4` — not viewed
- any Starlink Help Center article — not opened
- the Kenya installation video — **not watched, and no transcript retrieved**

**Available.** Web *search* works. It returns official `starlink.com` URLs with extracted passages.
Every requirement quoted in §1 came through that route, from `starlink.com` documents, and I have
cited the document each came from. That is good evidence but it is **not the same as having read
the guides end to end**, and I will not pretend otherwise: a setup guide is a sequence, and I have
seen quoted fragments of the sequence, not all of it.

**What this means for you.** §§5–7, 9 and 10 are about *our own code*. I read that directly and
those sections are as reliable as anything I have given you. §§1–4 and 8 are about Starlink's
requirements, and they are built from cited official passages plus clearly-marked inference. Before
any of §8 becomes training material a person should open the PDFs and confirm the step sequences.

Two honest ways forward, at the end of the report in §11.

**On the Kenya video:** you were right to rank it as practical reference, not authority. As it
happens I could not watch it at all, so nothing in this report derives from it. Where a claim is
about African installation conditions it is marked as inference or as a question to answer, never
as something I observed.

---

## 1. What Starlink officially requires

Everything in this section is quoted or closely paraphrased from `starlink.com` documents, with the
source named. Figures are theirs, not mine.

### 1.1 The clear-sky requirement — the one hard number

> Each Starlink should have a clear view of the sky **20° elevation above the horizon, 360° around
> the azimuth**. If you have obstructions > 20° elevation in any direction, the connection may be
> occasionally interrupted.

This is the single most important technical constraint in the whole installation, and it is the one
a rushed site survey gets wrong. It is not "point it at the sky" — it is a defined cone, and a
tree that clears it in September may not in March.

Starlink is explicit that the App is the instrument, not the technician's judgement:

> Use the obstruction tool in the App to ensure you have selected a suitable mounting location.

> Objects that obstruct the connection between your Starlink and the satellite, such as a tree
> branch, pole, or roof, will cause service interruptions.

And on what to do when ground level will not give it:

> If you could not find a clear field of view from the ground level, consider installing in an
> elevated location, like a roof, pole, or wall.

Sources: [How to check for obstructions](https://starlink.com/support/article/bcbf0078-be81-d345-4bce-ebbcfa196f56),
[Why do I need a clear field of view](https://starlink.com/support/article/2ae8f1d0-b09a-28fa-4b21-8dbe678dff62),
[Mini Kit Setup Guide](https://starlink.com/public-files/installation_guide_mini_kit.pdf).

### 1.2 Power, router and cable

> Plug the Router into a power outlet using the power cable and power supply. Route the Starlink
> cable to your Router and plug it into the port number 1 indicated with the antenna symbol on the
> back.

> Plug the cable into your Starlink and ensure the plug is fully inserted such that the plug face
> is flush with the surface.

That last line is a real failure mode, not a pleasantry — a plug that is nearly seated passes a
visual check and fails intermittently in weather.

Sources: [Standard 4X Setup Guide](https://starlink.com/public-files/installation_guide_standard_kit.pdf),
[Gen 3 Router Setup Guide](https://starlink.com/public-files/Gen3RouterSetupGuideStandardGen2.pdf),
[Standard Pipe Adapter Install Guide](https://starlink.com/public-files/installation_guide_standard_pipe_adapter.pdf).

### 1.3 App, alignment, Wi-Fi

> Scan the QR code to download the Starlink App. On your device, find and connect to the STARLINK
> network in your WiFi settings. Once connected, a browser window will open prompting you to enter
> a new SSID (Network name) and password.

> Step through the Starlink install process on the Starlink App. Once connected, an alert on the
> App will show if you need to rotate Starlink to be properly aligned. Click the alert to use the
> alignment tool.

Note the direction of authority: the dish self-orients and the **App tells you** if rotation is
needed. A technician who "aims" it by eye is working against the product.

Source: [Standard 4X Setup Guide](https://starlink.com/public-files/installation_guide_standard_kit.pdf).

### 1.4 Mounting

Starlink publishes a separate install guide per mount. The ones relevant to us:

| Mount | Official guide |
|---|---|
| Standard wall mount | [installation_guide_standard_wall_mount.pdf](https://starlink.com/public-files/installation_guide_standard_wall_mount.pdf) |
| Long wall mount | [Long_Wall_Mount_Guide_Rectangular.pdf](https://starlink.com/shop/assets/documents/v3/Long_Wall_Mount_Guide_Rectangular.pdf.pdf) |
| Pivot mount | [installation_guide_standard_pivot_mount.pdf](https://starlink.com/public-files/installation_guide_standard_pivot_mount.pdf) |
| Ground pole mount | [Ground_Pole_Mount_Guide_Rectangular.pdf](https://shop.starlink.com/assets/documents/v3/Ground_Pole_Mount_Guide_Rectangular.pdf.pdf) |
| Pipe adapter | [installation_guide_standard_pipe_adapter.pdf](https://starlink.com/public-files/installation_guide_standard_pipe_adapter.pdf) |
| Roof rack mount | [installation_guide_standard_roof rack_mount.pdf](https://starlink.com/public-files/installation_guide_standard_roof%20rack_mount.pdf) |
| Mini pipe adapter / flat mount | [installation_guide_mini_pipe_adapter_and_flat_mount.pdf](https://starlink.com/public-files/installation_guide_mini_pipe_adapter_and_flat_mount.pdf) |

Per-kit setup guides: [Standard 4](https://starlink.com/public-files/installation_guide_standard4_kit.pdf),
[Standard 4X](https://starlink.com/public-files/installation_guide_standard_kit.pdf),
[Mini](https://starlink.com/public-files/installation_guide_mini_kit.pdf),
[Mini X](https://starlink.com/public-files/installation_guide_mini_x_kit.pdf),
[Performance](https://starlink.com/public-files/installation_guide_performance_kit.pdf),
[Enterprise](https://starlink.com/public-files/installation_guide_enterprise_kit.pdf).

**I have not read these individually.** Before a mount-specific procedure goes into the manual,
the guide for that mount must be opened. Torque figures, fixing counts and substrate rules differ
per mount and are exactly the sort of detail that must never be guessed.

### 1.5 Safety, drilling and lightning

> Wear appropriate eye, hand, and face protection and avoid studs, electrical wiring, and water
> lines when drilling.

> Drill at a slight downward angle from the home interior to the home exterior and thoroughly
> apply sealant.

On lightning — and read this carefully, because the wording is deliberately limited:

> If Starlink is used in a lightning-prone area, an external lightning protection system (lightning
> rod, ground rod, surge protector, etc.) may **reduce product susceptibility** to lightning. For
> added protection during a lightning storm, or when it is left unattended and unused for long
> periods of time, unplug the product from the wall AC outlet and disconnect the antenna cable.

Starlink does **not** ship lightning protection and does not claim it makes the product safe. It
says an external system *may reduce susceptibility*. Anything DishNet tells a Ugandan customer
about lightning protection is DishNet's own commitment, not Starlink's — see §3.

Sources: the wall mount and pipe adapter install guides above.

---

## 2. What a professional installation should look like

Derived from §1 plus ordinary field-service practice. Marked as such — this is the shape I propose,
not something Starlink publishes as a checklist.

1. **Confirm before travelling** — customer, address, contact, kit type, plan, access arrangements.
2. **Site survey on arrival** — walk the property before opening the box. Candidate mount points,
   cable path, power position, router location.
3. **Obstruction check at each candidate** — App obstruction tool, at the actual proposed height.
   Not from the ground if the dish will be on the roof; the result is only valid where it was taken.
4. **Choose the location and mount** — the location with a clean 20°/360° result, reachable by
   cable, on sound structure.
5. **Mount** — per that mount's official guide. Sealant on every penetration.
6. **Route the cable** — protected from abrasion, with a drip loop, no strain on either connector,
   plug seated flush.
7. **Power and router** — router at the customer's chosen point, cable into port 1 (antenna symbol).
8. **App setup** — connect to STARLINK SSID, set SSID and password, step through the App flow.
9. **Alignment** — only if the App raises the alert; use its tool.
10. **Activation / service line** — see §5. This is an account operation, not a physical one.
11. **Connectivity test** — online, obstruction result recorded, alert list clear.
12. **Speed and latency test** — recorded, with the time of day noted.
13. **Customer handover** — SSID and password, App walkthrough, what weather does, who to call.
14. **CRM update** — §4 and §9.
15. **Completion** — job closed, kit bound to the customer, photos attached.

The ordering that matters: **obstruction check before mounting, not after.** The expensive failure
is a dish bolted to a roof that then fails its obstruction check, because the fix is new holes in
someone's roof.

---

## 3. What is different for Uganda / Africa

Marked honestly by confidence, because I could not watch the Kenya video and I have no field
observations of my own.

**Well founded:**

- **Lightning.** Uganda sits in one of the most lightning-intense regions on earth; Kampala's storm
  season is severe. Starlink's own position (§1.5) is only that external protection *may reduce
  susceptibility*. A roof-mounted dish on a metal mast is a genuine exposure, and DishNet needs a
  stated policy: what we fit, what we charge, what we promise, and what we tell the customer to do
  during a storm. **This is a business decision, not a technical lookup — I am flagging it, not
  answering it.**
- **Mains power quality.** Outages and sags are routine. The dish and router both need mains. Any
  meaningful uptime promise implies UPS or inverter backup, which is a quotable line item and an
  installation step, and changes the handover conversation.
- **Roof construction.** Corrugated iron on timber purlins behaves differently from the substrates
  the mount guides assume. Fixing method and sealing need a Uganda-specific answer per roof type.
- **Regulatory.** Starlink operation in Uganda sits under UCC licensing. Existing plugin content
  already references UCC guidance. **Do not let an SOP or manual imply a regulatory position — that
  belongs to whoever holds the licence, and it must be confirmed, not inferred.**

**Plausible, unverified — do not write into a manual without confirming:**

- Dust and its effect on service intervals.
- Long dry-season sun on cable jackets and mounts.
- Whether the seasonal tree-growth margin should be a formal survey rule.

**What the Kenya video was supposed to answer and did not:** the practical texture — how a real
African install is sequenced, what the technician actually carries, which improvisations appear.
That gap is still open; see §11.

---

## 4. What we should capture at installation

Your list, with what each is *for*, since a field that no system reads is a field a technician
learns to skip.

| Field | Why it exists | Where it belongs |
|---|---|---|
| uCRM client id | The identity everything else hangs from | `equipment_assignments.crm_client_id` ✅ exists |
| uCRM service id | Which subscription this kit serves | `crm_service_id` ✅ column, ❌ never filled |
| Installation address | Dispatch, support, warranty | uCRM client |
| GPS of the dish | Revisits, obstruction disputes, fleet map | ❌ nothing stores it |
| Kit type | Mini / Standard 4 / 4X — drives support answers | stock category (partial) |
| **Kit serial (KIT…)** | Binds the box to the customer | `kit_serial` ✅ |
| **Terminal ID (ut…)** | Starlink's identity for the dish | `terminal_id` ✅ column, ❌ never filled |
| **Router ID** | What suspension/unblock acts on | `router_id` ✅ column, ❌ never filled |
| **Service line (SL-…)** | The subscription on Starlink's side | `starlink_service_line` ✅ column, ❌ never filled |
| **Starlink account (ACC-…)** | Which of our accounts holds it | `starlink_account` ✅ column, ❌ never filled |
| Mount type | Maintenance, future obstruction work | ❌ nothing |
| Obstruction result | The evidence the site was viable | ❌ nothing |
| Before / after photos | Dispute evidence, workmanship record | ⚠️ `install_upload_photo` exists — keyed to a **ticket**, not to the assignment |
| Cable route notes | The next technician's time | ❌ nothing |
| Router status / online | Did it actually work | partially, via usage data |
| Speed + latency at handover | Baseline for every later complaint | ❌ nothing |
| Technician | Accountability | check-in captures it ✅ |
| Install date/time | Warranty, billing start | check-in/out ✅ |
| Customer acceptance | The signature that closes the dispute | ❌ nothing for installs |

The five bold rows are the ones that make or break §5.

---

## 5. How kit identity should map to the CRM — the critical bridge

### 5.1 The identifiers, and where each comes from

From your own live data earlier in this session (first-party evidence, our own account):

| Identifier | Example | Origin |
|---|---|---|
| Starlink account | `ACC-DF-15973474-59163-60` | Our Starlink account |
| Service line | `SL-DF-16046613-35504-0` | Created when a subscription is activated |
| Kit serial | `KIT404246364BX6` | Printed on the box/hardware |
| Terminal ID | `ut01301694-01e07c1c-59d52912` | The dish itself |
| Router ID | (no `Router-` prefix, per our schema) | The router |

Starlink's Enterprise API uses this same vocabulary: the user-terminals endpoint returns
`userTerminalId`, `kitSerialNumber`, `dishSerialNumber` and linked routers, and service line detail
returns `userTerminalId` and `kitSerialNumber`
([telemetry API](https://starlink.com/support/article/90109cc2-c7ec-31ff-d160-0a87f16ef759),
[API auth](https://starlink.com/support/article/c3be63c6-a7a3-c054-cbb3-2602fac52ccb),
[activation via API](https://starlink.com/support/article/4aa53c87-3b38-619c-1db8-cf59711e2aa5)).

**Our schema already speaks Starlink's language.** That is worth keeping.

### 5.2 The chain

```
uCRM Client ──< uCRM Service ──1:1── equipment_assignments ──1:1── stock_unit (physical kit)
                                            │
                                            ├── starlink_account      ACC-…
                                            ├── starlink_service_line SL-…    ← the subscription
                                            ├── terminal_id           ut…     ← the dish
                                            ├── router_id                     ← the router
                                            └── kit_serial            KIT…    ← the box
                                                        │
                                    data-report plugin ──┴── sl_usage.json (matched on kit_serial)
```

The design is already right: **ownership is an integer (`crm_client_id`), never a name or a parsed
string**, and each Starlink identifier is uniquely indexed while the assignment is live, so a
reverse lookup returns exactly one customer or none.

### 5.3 Where it breaks today

`equipment_assignments` has all five identifier columns, with unique partial indexes on four of
them. `StockService::install()` accepts all five. **And no screen sends any of them.**

Verified: `tabs/admin/stock_inout.php`, `tabs/support/stock_hub.php` and
`tabs/support/my_equipment.php` are the only callers of `stock_install`, and between them they
send `client_name`, `crm_client_id`, `note` and (in one) `job_id`. Nothing else.

So in practice every assignment is created as:

```
crm_client_id         ✅ set
kit_serial            ✅ copied from the stock unit
crm_service_id        ✗ 0 → NULL
starlink_account      ✗ ''
starlink_service_line ✗ ''
terminal_id           ✗ ''
router_id             ✗ ''
```

Consequences, all of which follow directly:

1. **Usage matching works only by `kit_serial`.** `KitUsage` reads `sl_usage.json` from the sibling
   data-report plugin and matches on serial. If that file keys anything by terminal or router, we
   cannot join it.
2. **The per-service uniqueness index never engages**, because `crm_service_id` is NULL. A customer
   with two services and two kits has no defined mapping — exactly the case migration 068's comment
   says the index exists to prevent.
3. **Suspension/unblock cannot resolve a customer from a router.** The block manager works from the
   data plugin's own router list, not from our authoritative binding.
4. **The strongest identifiers are the missing ones.** A kit serial is a sticker; the terminal ID is
   the dish. When a kit is swapped under warranty the serial changes and nothing else does.

This is the single highest-value gap in the whole report, and it is entirely ours to close — no
Starlink dependency, no API needed. **The moment to capture these identifiers is the installation,
because that is the only moment when a person is physically holding the hardware.**

---

## 6. What our code already supports

Read directly; reliable.

- **`migrations/068_equipment_assignments.sql`** — the authoritative binding. Ownership as an
  integer. Unique partial indexes (`released_at IS NULL`) on unit, service, kit serial, terminal,
  router and service line. `RAISE(ABORT)` triggers so a released assignment cannot be edited and no
  assignment can ever be deleted. These are *database* guarantees, not conventions.
- **`lib/EquipmentAssignment.php`** — `assign`, `release`, `replaceUnit` (warranty swap),
  `resolve` (reverse lookup from any identifier), `addIdentifiers`, `correctIdentifier`,
  `conflicts`, full history per client and per unit.
- **`lib/CrmKitAttribute.php`** — writes the kit onto the uCRM service as a *label*, one-way, and
  reports disagreement rather than obeying or overwriting it. The direction is deliberate.
- **`lib/StarlinkFleet.php` + `tabs/admin/starlink_fleet.php`** — the fleet view, built on the
  binding, keeping three kinds of not-knowing distinct.
- **`lib/KitUsage.php`** — usage from the data-report plugin's `sl_usage.json`, by kit serial.
- **`tools/binding_doctor.php`** — diagnoses weak/missing bindings, can repair and learn identifiers.
- **`lib/StockService.php`** — refuses to install against a name without a uCRM client id, and
  refuses to silently re-point a unit reserved for another customer.
- **Field ops** — `install_checkin` / `install_checkout` / `install_mark_ready` with GPS lat/lon,
  staff live map and daily trail; patches the uCRM **scheduling job** status.
- **Install photos** — `install_upload_photo` (`includes/api/api_support.php`) accepts a base64
  image with a free-form `photo_type` (default `site`), writes
  `uploads/install_photos/<ticket_id>/<type>_<timestamp>.jpg`, records it against the **Splynx
  ticket** store, and `tabs/admin/photo_manager.php` lists the folder. `install_add_note` likewise.
  So photo capture *does* exist — I said otherwise in an earlier draft and was wrong.
- **`lib/StarlinkPortalConnector.php` / `StarlinkSessionStore.php`** — the live Starlink connection.
  See §10; this is not an API client.

We are further along than the question implied. The skeleton is sound; it is under-fed.

---

## 7. What is missing

**Binding (highest value):**
1. No UI captures `terminal_id`, `router_id`, `starlink_service_line`, `starlink_account` — ever.
2. No UI captures `crm_service_id`, so the service-level binding is unused.
3. No technician-facing path creates or completes an assignment; binding happens at stock issue.

**Installation record:**
4. No obstruction result stored.
5. No speed/latency baseline stored.
6. **Install photos exist but hang off the wrong thing.** They are filed under a *ticket id*, in the
   Splynx ticket store, while `install_checkin` works against a *uCRM scheduling job*, and the
   equipment binding knows about neither. Whether those two id spaces are the same on this install
   is **an open question I have not answered** — `install_checkin` accepts `job_id` *or*
   `ticket_id` into the same variable, which is exactly the kind of ambiguity that hides a mismatch.
   Until that is settled, a photo cannot be reliably traced from a customer's kit.
   `photo_type` is also free-form, so "before" and "after" are conventions, not values.
7. No mount type, cable route, or dish GPS.
8. No customer acceptance/signature for installations.

**Process:**
9. No Starlink installation SOP exists in the manuals at all.
10. Nothing distinguishes *physical install* from *Starlink activation* from *DishNet service start*
    — §8's separation is not represented anywhere in the system or the training.

---

## 8. What should be added to the Uganda manual

Proposed structure. **Every technical step must be checked against the official guide for that kit
and mount before it is taught** — see §0.

1. **What Starlink is and how the link works** — enough for a technician to explain weather fade.
2. **The clear-sky rule** — 20° elevation, 360° azimuth, cited; the App is the instrument.
3. **Site survey** — candidate points, obstruction check at true height, seasonal tree margin.
4. **Choosing a mount** — decision guide, each pointing to the official guide (never restated).
5. **Cable routing** — drip loop, abrasion, seating flush, sealant.
6. **Power and router** — port 1, UPS discussion.
7. **App setup, SSID and password, alignment.**
8. **Activation vs installation** — see the separation below.
9. **Testing and what "good" is** — speed, latency, obstruction clear, time of day recorded.
10. **Capturing identifiers** — which stickers, where, why the terminal ID outlives the serial.
11. **Customer handover** — SSID/password, App, weather, who to call.
12. **CRM completion** — the fields, and what each is for.
13. **Safety** — drilling, working at height, lightning, storm guidance.
14. **Troubleshooting and maintenance.**

Marked **"not yet implemented"** anywhere the manual describes a capture the system cannot do —
which, today, is most of §4.

---

## 9. What should be added to the CRM / Fleet workflow

Ordered by value per unit of work.

1. **Capture the Starlink identifiers at install.** Add the five fields to the install screens and
   pass them through the call that already accepts them. Smallest change, largest effect: it turns
   the existing indexes and reverse lookup from theory into function.
2. **Require `crm_service_id`.** Bind kit to *service*, not just client, so a two-service customer
   is unambiguous.
3. **A technician installation-completion step** that records obstruction result, speed, latency,
   mount type, dish GPS, photos and customer acceptance, and completes the binding in one action.
   Part of this is joining up what already exists rather than building new: GPS check-in, photo
   upload and the binding are three separate mechanisms today, keyed on three different things.
   **First settle whether the scheduling-job id and the ticket id are the same number here** — that
   answer decides whether this is a wiring job or a data-model job.
4. **Extend `binding_doctor`** to report "live assignments with no terminal ID" as a named weakness.
5. **Fleet screen**: surface which identifiers a kit is missing, so the gap is visible to whoever
   can fix it.

**The separation you asked for — which system owns what:**

| Fact | Owner | Not owned by |
|---|---|---|
| Who the customer is | **uCRM** | Starlink |
| What they pay for, and billing | **uCRM** | Starlink |
| Which physical kit they hold | **`equipment_assignments`** | uCRM attribute (label only) |
| Subscription on Starlink's side | **Starlink** (service line) | uCRM |
| Terminal/router identity | **Starlink** (hardware) | us — we record, never mint |
| Data usage / telemetry | **Starlink** → data-report plugin | uCRM |
| Suspend / unblock | **Starlink**, acted on via router | uCRM |
| Installation record | **should be ours — does not exist yet** | — |

The rule that keeps this honest: **we never invent a Starlink identifier.** Migration 068 already
says so — an identifier we do not have is empty, and an empty one never matches.

---

## 10. Starlink API / Fleet limitations we must not assume away

1. **We do not have an API integration.** `StarlinkPortalConnector` drives *undocumented internal
   web endpoints* (`/api/webagg/v2/accounts/service-lines`, `/api/accounts/v3/accounts/contact`,
   `/auth-rp/auth/user`) using an **imported browser session cookie**. That is not a supported
   interface and carries no compatibility promise.
2. **There is no login.** The class says so plainly: a human imports a session; when it can no
   longer be refreshed, a human imports another. Any workflow assuming unattended access is wrong.
3. **Sessions die.** We have already seen this — the dead cookie for `ACC-DF-15973474-59163-60` is
   still outstanding. Design for absence, not presence.
4. **The official API exists but is gated.** Access is for **Starlink Authorized Resellers or
   larger Business/Enterprise customers**, with credentials issued through an account manager, and
   documentation at `starlink.readme.io`
   ([getting started](https://starlink.com/support/article/90109cc2-c7ec-31ff-d160-0a87f16ef759),
   [auth](https://starlink.com/support/article/c3be63c6-a7a3-c054-cbb3-2602fac52ccb)).
   **Whether DishNet qualifies is a commercial question I cannot answer from here** — it is worth
   asking the account manager, because it would replace the cookie entirely.
5. **A terminal belongs to one account.** Starlink does not permit two accounts to own the same
   terminal; an already-assigned kit must be released by the previous account. (Third-party
   sources; consistent with our schema's one-live-owner rule, but **confirm officially** before
   this becomes a customer-facing promise.)
6. **Do not assume the API activates service.** Starlink documents activation via API for eligible
   accounts, but we have no such access today, so activation is a manual portal operation.
7. **Do not assume telemetry is real time.** Ours arrives as a file another plugin writes.
8. **Do not assume the usage file keys by anything but kit serial** — that is all `KitUsage` uses,
   and it is why capturing terminal IDs is worth doing regardless.

---

## 11. What I recommend next

The binding work (§9 items 1–2) needs **no further Starlink research** — it is our own schema,
our own screens, and the columns already exist. It is the highest-value change available and it
can start whenever you approve it.

The SOP and manual work (§§2, 8) **should not** start until the official guides are actually read,
for the reason in §0. Two ways to get there:

- **You fetch, I read.** Download the PDFs you care about — Standard 4X, Mini, and the two or three
  mounts we actually fit in Kampala — and paste or attach them. I will work from the real text.
- **Ask the account manager.** If DishNet qualifies for reseller/Enterprise API access, that
  changes §10 substantially and is worth knowing before we build around a cookie.

I would also still like a practical African-installation reference, since the Kenya video did not
reach me — a description from your own technicians of how a Kampala install actually goes would be
better than the video anyway, and it is the part no official PDF will ever contain.
