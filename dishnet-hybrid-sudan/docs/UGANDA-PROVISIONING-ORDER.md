# Uganda provisioning order

Why this document exists: every screen that came up empty this week was empty
for the same reason — something downstream was created before the thing it
reads. Finance builds kits from uCRM, so uCRM has to be right first. Our
portal reads our own binding, so the binding has to exist before a customer is
shown a login. data-report reads Finance, so Finance has to hold the kit before
its customer page can show anything.

Nothing here is new behaviour. It is the order the existing pieces already
require, written down so it is followed deliberately rather than discovered.

---

## Stage 0 — once per Starlink account, before any customer

These are not per-customer steps. Doing them once removes a whole class of
"the screen is empty" from every customer that follows.

### 0.1 The uCRM service plan name must contain "Starlink"

`dishnet-starlink-finance` decides whether a service is a Starlink service
with one test, in `syncStarlinkServices()`:

```php
$planName   = $svc['servicePlanName'] ?? $svc['name'] ?? '';
$isStarlink = stripos($planName, 'tarlink') !== false;
```

Note `??`, not `||`. When `servicePlanName` is set — it always is — the service
name is never consulted. A plan named "Residential Lite" is invisible to
Finance no matter how the service or the kit is named.

This is a plan-level fix, not a per-customer one: rename the plan once and
every service on it passes forever.

    php tools/finance_sync_gate.php              # report
    php tools/finance_sync_gate.php --fix-plan   # rename it in uCRM

The rename shows on every invoice and in the client zone for every service on
that plan, so read the report before running the fix.

### 0.2 Register each Starlink account in Finance

Finance's Starlink → Accounts screen starts empty and **cannot fill itself**:
it builds kits from uCRM, and uCRM carries no Starlink account number
anywhere. Until an account exists there, kits on it group under no account and
the account view reads "No KITs".

    php tools/starlink_accounts_list.php

lists every account visible on the box, where each was seen, and which are
missing from Finance. Add them with Finance's own **+ Add Account**. Nothing
in this plugin writes `sl_accounts.json`; Finance owns that file.

---

## Stage 1 — per customer, in uCRM

uCRM is the source of truth. Both sibling plugins read it and neither writes
it, so anything wrong here propagates to both.

### 1.1 Client

The uCRM client record. Its id is the identity every other system joins on —
`crm_client_id` in Finance, `crm_client_id` in data-report's filter,
`crm_client_id` on our binding.

### 1.2 Service, on the Starlink-named plan

Created against that client, on the plan from 0.1.

Name it the way South Sudan does:

    Site : KIT404246364BX6  Service Plan: Starlink Residential

That is not cosmetic. Finance scans a fixed list of fields for the kit number —

```php
$noteText = name . note . invoiceLabel . street1 . street2
          . servicePlanName . addressGpsLat . contractLengthType;
preg_match('/\b(KIT[A-Z0-9]{8,})\b/i', $noteText, $m);
```

— and the service **name** is the first of them. Eight characters after `KIT`
is a hard minimum: a shorter kit number cannot be matched by Finance wherever
it is written.

Set the service address too. Finance takes the install location from it, and
a blank address becomes a blank location on the kit.

### 1.3 Kit number on the `starlinkDetails` attribute

`dishnet-data-report` reads the service custom attribute first, normalising
the key by lower-casing and stripping spaces, underscores and hyphens, and
accepting any of `starlinkdetails`, `kitnumber`, `starlinkkit`, `kitno`, `kit`.

So the kit number needs to be in **two** places, and they are read by
different plugins:

| where | read by |
|---|---|
| `starlinkDetails` service attribute | data-report (its Priority 1) |
| the service name | Finance (it never reads attributes) |

Putting it in only one is how a kit shows up in one plugin and not the other.

    php tools/finance_sync_gate.php --fix   # appends it to the service note

### 1.4 Invoice, then payment

Unchanged from South Sudan. The invoice is raised in uCRM and the payment
recorded against it there. No plugin books a payment on uCRM's behalf outside
the DPO path, which is separately gated by its own kill switch.

---

## Stage 2 — bind the kit in this plugin

    Admin → Kit Intake

This writes `equipment_assignments`, which is the **authoritative** binding —
what the customer portal resolves usage through, what the Starlink Fleet
screen groups by, and what DPO trusts. It is not a copy of the attribute; the
attribute is operator input and this is the decision made from it.

The intake screen refuses rather than guesses: a kit held by another customer,
a service already taken, a kit not in stock, a service that disagrees with the
attribute. Each refusal names the corrective action.

    php tools/kit_intake.php            # dry run, changes nothing
    php tools/kit_intake.php --commit   # bind

Changing or deleting the `starlinkDetails` attribute afterwards does **not**
release or reassign the binding. Releasing is a deliberate act.

---

## Stage 3 — let Finance create its own kit

    Finance → Sync Starlink Services

Finance reads uCRM, finds the kit number in the service, and creates the kit
itself with billing, address, GPS, plan and `crm_client_id` filled from uCRM.
We generate nothing and write nothing into its directory — both plugins state
the same contract: a data file is owned by one plugin, others may read it and
must not write it.

Two things that waste a round trip:

- Finance caches uCRM services for 300 seconds. Pressing sync twice quickly
  does nothing the second time; use Auto Sync / Refresh CRM to force it.
- A sync run *before* a uCRM change judged the old data. Change uCRM first,
  then sync.

### 3.1 Link the kit to its Starlink account

Finance's sync cannot set this — the account number is not in uCRM. Use
Finance's own kit screen to attach the kit to the account registered in 0.2.
Until then the kit is correct but ungrouped, and the account shows 0 kits.

---

## Stage 4 — usage

Two different things, often confused:

**Our customer portal** resolves usage through `equipment_assignments` and our
own collector. It works as soon as Stage 2 is done.

**data-report's usage screens** read that plugin's own `sl_usage.json`, which
it fills from its own Starlink session. It never reads our data directory. If
its usage is empty, the fix is its Cookie Sync, not anything here.

---

## What each stage unblocks

| after | what starts working |
|---|---|
| 0.1 | Finance's sync stops skipping the service |
| 0.2 | Finance's account view can group kits |
| 1.2 / 1.3 | Finance and data-report can both find the kit number |
| 2 | customer portal usage, Starlink Fleet, DPO |
| 3 | Finance Inventory, data-report's customer page |
| 3.1 | Finance's account view shows the kit under its account |
| 4 | data-report's usage graphs |

## Checks

    php tools/finance_sync_gate.php          # the three gates Finance applies
    php tools/kit_intake.php                 # what would bind, and what is refused
    php tools/starlink_accounts_list.php     # accounts, and which Finance lacks
    php tools/binding_doctor.php             # one kit end to end

## Container dependency: poppler-utils

`dishnet-starlink-finance` extracts text from uploaded Starlink invoice PDFs
with `pdftotext`, which is not in the uCRM image. Without it, its
`PDFTextExtractor::commandExists()` passes the `null` from a failed `which`
straight into `trim()`, and PHP 8 turns a missing dependency into a fatal that
takes the whole page down:

    Uncaught TypeError: trim(): Argument #1 ($string) must be of type string,
    null given in .../lib/PDFTextExtractor.php:65

Install it in the container:

    docker exec -u root ucrm apk add --no-cache poppler-utils

**This does not survive a container rebuild.** A uCRM upgrade or an Easypanel
redeploy gives a fresh container without poppler and the same fatal returns.
Re-run it after any such change, or put it in the image.

Nothing in this plugin uses poppler; the dependency is Finance's alone. And
installing it hides rather than fixes the `trim(null)`, which will do the same
thing for any other binary that plugin probes for and does not find.

## Figures not to trust yet

Finance's dashboard shows monthly profit equal to monthly revenue at 100%
margin. Cost is zero because no purchases are recorded there, and the `$`
prefix is its own label on a UGX figure. Its `hardware_cost` comes from a
`default_Standard` table, not from any purchase invoice. None of it reaches
our cashbook.
