# The Kit Number linkage — where every relationship actually lives

An inspection, not a proposal. Nothing here changes behaviour. It answers one
question: for each of the ten things the South Sudan workflow requires, where
does that relationship exist today — in uCRM, in the database, in which
plugin's code, on which screen.

Read from three codebases: `dishnet-hybrid-sudan` (this repo),
`dishnet-data-report` v2.8.80 and `dishnet-starlink-finance` v7.3.9, both
supplied as archives and read directly.

**One conflict is recorded in section 4 and deliberately not resolved.** It is
a decision, not a bug, and it is yours to make.

---

## 1. The short version

There is not one binding. There are **three**, in three different places,
maintained by three different mechanisms.

| # | Where it lives | What it binds | Who reads it as truth |
|---|---|---|---|
| **A** | uCRM **service custom attribute** (`starlinkDetails`) | service → kit | `dishnet-data-report` — its Priority 1 |
| **B** | `dishnet-starlink-finance/data/sl_kits.json` → `crm_client_id` | kit → client | `dishnet-data-report`'s **customer page gate** |
| **C** | `equipment_assignments` (SQLite, this plugin) | kit ↔ client ↔ service | every screen in this plugin, and the DPO payment path |

They are not kept in step with each other by anything. A kit can be right in
one and wrong in another, and today nothing reports that except
`tools/crm_kit_label.php`, which compares **A** against **C** only.

---

## 2. The chain, as implemented

```
CUSTOMER  ── uCRM client                     (manual — a person creates it)
    │
INVOICE   ── uCRM invoice                    (manual — uploaded/created by hand)
    │
SALE      ── uCRM payment                    (manual, or now DPO Pay)
    │
SERVICE   ── uCRM service                    (manual)
    │
    ├── custom attribute  starlinkDetails = KIT…        ← binding A
    │        read by dishnet-data-report, Priority 1
    │
    ├── equipment_assignments row                        ← binding C
    │        written ONLY by tools/assign_kit.php
    │
    └── sl_kits.json row (Finance)                       ← binding B
             written by Finance's add_kit form

                          │
                  STARLINK KIT NUMBER
                          │
               ┌──────────┴───────────┐
               │                      │
   data-report's own discovery   our own collection
   webagg userTerminals[0]       cron/starlink_usage.php
     .serialNumber (v2.8.46)     → OUR data dir
               │                      │
    dishnet-data-report/data/    dishnet-hybrid-sudan/data/
        sl_usage.json                sl_usage.json
               │                      │
      data-report client.php     our portal usage screen
```

Note the two collection paths never meet. Each plugin reads only its own
usage file (and Finance's); neither reads the other's.

---

## 3. Requirement by requirement

### 1 · "A customer must have an internal customer record"

**uCRM client.** Nothing in any plugin owns the customer. Every binding refers
to a uCRM client id.

- read here through `lib/CrmApiClient.php` → `clients/{id}`
- `equipment_assignments.crm_client_id` is `INTEGER NOT NULL` — the schema
  comment calls it *"uCRM client id — THE identity"*

### 2 · "The invoice/commercial transaction is maintained separately"

**uCRM invoice.** Correctly separate from everything Starlink. This plugin
never creates an invoice; it creates *payments* and lets uCRM apply them
(`CrmApiClient::createPaymentSafe`, `applyToInvoicesAutomatically`).

Finance holds a *second*, commercial view of invoices —
`sl_invoices.json`, `import_csv_invoices`, the Reconciliation tab — for
supplier-side reconciliation. That is a different thing from the customer's
uCRM invoice and the two are not confused anywhere I could find.

### 3 · "The Starlink account may be created manually"

Nothing automates it, and nothing assumes it was automated.

- Finance: `sl_accounts.json`, created via **+ Add Account** or CSV import
- on a kit: `starlink_account_number` / `account_number` / `account_id`
- here: `equipment_assignments.starlink_account` and
  `lib/StarlinkSessionStore.php`, which holds one session *per account*
- the Starlink Accounts screen (`tabs/admin/starlink_accounts.php`) groups the
  fleet by account precisely because an account is a real, separate thing

### 4 · "The Kit Number is stored against the customer's Service"

**This is binding A, and it is a uCRM service custom attribute.**

`dishnet-data-report/public.php:269` accepts any of these keys, normalised by
lower-casing and stripping spaces, underscores and hyphens:

```php
$kitAttrKeys = ['starlinkdetails', 'kitnumber', 'starlinkkit', 'kitno', 'kit'];
```

The value may be comma-separated; each candidate must match
`/^KIT[0-9A-Z]{4,}$/i`. If the attribute is empty there is a **fallback**: it
scans the service `name` and `invoiceLabel` for `\bKIT[0-9A-Z]{4,}\b`.

**On the Uganda box this is already satisfied.** uCRM service #1 carries
`starlinkDetails = KIT404246364BX6` — visible in the client zone. So step 4 of
the workflow is done here, today.

### 5 · "The Kit Number is the key used to associate equipment with our customer"

**This is the conflict. See section 4 below.**

### 6 · "Data Report synchronizes Starlink data"

Two independent discoveries, both in `dishnet-data-report`:

- **from uCRM** — `drFetchClientServices()` calls `/clients/{id}/services` and
  extracts kit numbers from the attribute (above)
- **from Starlink** — `cron.php:1147`, v2.8.46: the kit serial is read from
  `userTerminals[0].serialNumber` on the webagg service-line response, so
  data-report discovers SL → KIT for every active line on every run
  (`kit_serial_source = 'webagg.userTerminals'`)

The second is the same structural discovery this plugin implements in
`lib/StarlinkLineDiscovery.php`. They were arrived at separately.

### 7 · "Synchronized Starlink data reconciled against our records by Kit Number"

Partly, and in three unconnected places:

- `dishnet-data-report`: `lib/KitRegistryWriter.php` merges Finance's
  `sl_kits.json`, orders, invoices, live SLs and telemetry into
  `dr_kit_registry.json`, flagging `_metadata.in_finance_sl_kits` when Finance
  does not know about a kit
- `dishnet-starlink-finance`: `sync_starlink_services` + `autoEnrichKitData`
- here: `tools/crm_kit_label.php` compares the uCRM attribute against
  `equipment_assignments` and reports `match | missing | differs | no_service`

**Nothing reconciles all three.** There is no single report that says "A, B and
C agree about KIT…".

### 8 · "The system must show which customer a Starlink Kit belongs to"

Four screens answer this, from three different sources:

| Screen | Source | Plugin |
|---|---|---|
| Starlink Fleet | `equipment_assignments` (**C**) | this |
| Starlink Accounts | `equipment_assignments` (**C**) | this |
| Customer portal | `equipment_assignments` (**C**), sibling as fallback | this |
| data-report `client.php` | Finance `sl_kits.json` (**B**) | data-report |

### 9 · "Do not assume every Starlink account was created by our application"

Respected everywhere. Nothing provisions at Starlink. `StarlinkPortalConnector`
only reads; `tools/assign_kit.php` records a binding a person already made.

### 10 · "Do not remove the manual workflow"

Intact. Every manual entry point still exists: Finance's Add Account and Add
KIT, the uCRM service attribute, `tools/assign_kit.php`, manual invoice upload.

---

## 4. The conflict, stated plainly and not resolved

Requirement 5 says:

> **Do NOT treat the Kit Number as just a display field. It is the key
> identifier used to establish ownership/mapping.**

This plugin currently does the opposite, **deliberately and with a stated
reason**. From `lib/CrmKitAttribute.php`:

```
 * South Sudan puts the kit serial in a uCRM service attribute and treats it as
 * the binding. That is why a rename or a typo there could take a customer's
 * internet down. Uganda keeps the binding in equipment_assignments, where the
 * database can defend it.
 *
 *     equipment_assignments  ──writes──▶  uCRM service attribute
 *                            ◀─never reads for a decision─
 *
 * The attribute is a label. If someone edits it by hand, this reports the
 * disagreement — it does not obey it, and it does not silently overwrite it
 * either.
```

So on Uganda the attribute is **written from** the binding, never read as one.
`tools/crm_kit_label.php --commit` writes it; `--overwrite` is required to
replace a value a person typed, and without it a disagreement is reported
rather than destroyed.

**What each choice costs:**

| | Attribute as the key (South Sudan) | `equipment_assignments` as the key (Uganda today) |
|---|---|---|
| Entry | one field on the service, in uCRM, where the operator already is | a CLI tool that verifies stock, client, service ownership and prior claims |
| Typo | silently rebinds a customer's dish | refused — the serial must exist in stock |
| Two customers, one kit | possible | refused by a partial unique index |
| Audit | uCRM's own attribute history | append-only assignment history |
| Visible in uCRM | yes, natively | yes — written there as a label |
| Works if uCRM is down | no | yes |

**I am not choosing between these.** Three options exist, and the difference
matters:

- **(i) Leave it.** The attribute stays a label. Uganda keeps the defended
  binding. data-report keeps reading the attribute for its own purposes, which
  works because the label is written from the truth.
- **(ii) Make the attribute authoritative on Uganda too.** Faithful to South
  Sudan. Costs the database guarantees — a typed serial becomes a rebinding.
- **(iii) Make the attribute an accepted *input*, not the store.** A person
  types it on the service; a job reads it, validates it exactly as
  `assign_kit.php` does, and writes `equipment_assignments` — reporting
  anything it refuses. Entry where the operator already is, guarantees kept.

(iii) is what I would build if asked, but it is a design change and you said
not to redesign on assumption. Say which and I will implement it.

---

## 5. What is actually missing on Uganda right now

Not architecture — data.

| Binding | State on Uganda |
|---|---|
| **A** service attribute | ✅ present — `starlinkDetails = KIT404246364BX6` on service #1 |
| **B** Finance `sl_kits.json` | ❌ absent — Finance installed, 0 kits, file not created |
| **C** `equipment_assignments` | ✅ present — assignment #1, client #7, verified consistent with uCRM |

And the reason data-report's customer page says *No subscriptions found* is
**B**, specifically this gate in `client.php` (MODE 1B):

```php
$kCrmId = trim((string)($k['crm_client_id'] ?? $k['assigned_client_id'] ?? $k['crm_id'] ?? ''));
if ($kCrmId !== '' && $kCrmId === $jwtClientId) { $drClientKits[] = $k; }
...
if (empty($drClientKits)) { drNotFound(); }
```

It gates on **Finance's file**, not on the attribute — so having the Kit Number
on the service, which Uganda already does, is not enough to open that page.

A second, separate gap: **usage**. data-report reads only its own
`sl_usage.json` and Finance's. It never reads ours. Our 52 GB is in this
plugin's data directory, so even once a Finance kit row exists, that page shows
the kit with no data until data-report collects for itself — which needs its
own Starlink session, exactly as South Sudan has (its Cookie Sync tab,
*"auto-refreshed 3058×"*).

---

## 6. Nothing was changed

No code was modified to produce this document. The three bindings, the
one-way attribute write, and the manual entry points are all exactly as they
were.
