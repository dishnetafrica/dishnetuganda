# 30 — KYC customers never reached uCRM (5.18.28)

**25 September 2026.** Plugin `dishnet-hybrid-sudan` 5.18.27 → 5.18.28.
Not Domain B, not RADIUS, not the DishNet Portal: this is the uCRM plugin's
staff app (Add Customer → KYC wizard).

## 1. What was wrong

A customer registered through the staff app's KYC wizard was saved in the
plugin and never created in uCRM. Three things hid it:

- the agent was told **"Customer saved! CRM sync pending — will be created
  automatically"**, in green;
- the Orders screen counted the customer under **"In CRM ✓"**, because it
  counted status `new`, and a refused create is saved with status `new`;
- the job that was supposed to retry, `cron/kyc_crm_sync.php`, **never did
  anything**: it looked for `data/data.db` (nothing creates it; the database is
  `plugin.sqlite3` in the data directory), then called `SqliteStore`'s private
  constructor, then read four settings no screen writes. It returned at the
  first of those, every five minutes, and the scheduler logged a normal run.

All three KYC applications ever made on this install (12–24 September) were
stuck this way.

## 2. Evidence — measured on the server, read-only, by the operator

| Question | Answer |
| --- | --- |
| Organizations in uCRM | **one, id 1**. `GET organizations/2` → 404 Not Found |
| Client custom fields in uCRM | **1–4 only**: EFRIS TIN, EFRIS BRN, EFRIS NIN, EFRIS Taxpayer Type (5 is a service field). 36–43 do not exist |
| What the KYC create sends | `organizationId: 2`, and nine custom fields by number: 1 (as *Sales Person*), 36–43 |
| What uCRM answered | `404 {"code":404,"message":"Not Found"}`, twice in the plugin log |
| Is the endpoint there? | yes: `OPTIONS clients` → 405 `Allow: GET, POST`; `GET clients` → 200. The 404 is a *referenced record* that does not exist |
| KYC applications | 3, all `crm_sync_status=pending`, no uCRM id |
| The retry job | runs every 5 minutes in ~2 ms and does nothing |

The code was written for the South Sudan uCRM, where organization 2 and fields
1 and 36–43 exist. On this uCRM **field 1 is the EFRIS TIN**: had organization
2 existed, every KYC customer would have been created with the agent's name as
their tax identification number.

This was already half-known. `tools/org_probe.php`, run on 18 September for
5.18.20 ([07](07-change-control.md), 5.18.20 row), found one organization, id 1,
and recorded that *"the KYC literal `organizationId => 2` is wrong for this
install"*. The AI-lead path was fixed then (`lib/UcrmLeadSync.php`); the KYC
path was not.

## 3. What 5.18.28 changes

**Where a new client goes is decided against the connected uCRM**
(`lib/UcrmClientTarget.php`), before anything is sent:

| | Rule |
| --- | --- |
| organization | 2 if this uCRM has it; otherwise the only organization; otherwise **refuse** — never a guess between two companies |
| custom fields | the nine, unchanged, only if **all nine** exist here as client fields **and none is a tax field**; otherwise none |
| work order and tag | template 2/3 and tags 52–54 are South Sudan numbers too: used only where the whole South Sudan layout is present |

On this install that gives organization 1 and no custom fields: option (b)
from the diagnosis. On the South Sudan layout the request is **byte-identical
to before** (tested).

*Why not `UcrmLeadSync`'s rule (the organization most of the clients are
on)?* The standing rule is that the South Sudan install does not change
without new configuration. On a uCRM with organizations 2 and 7, "most
clients" could choose 7. Checking for the layout the code was written for
sends South Sudan exactly what it sent before, and refuses where it cannot
know.

**One rule for "is this a tax field"** (`lib/EfrisClientField.php`): what the
EFRIS invoice mapper already recognised (a key or name ending in tin/brn/nin,
or containing taxpayertype/buyertype), now shared. The two places that build
the sales-person index from custom field 1 (`main.php`,
`includes/api/api_crm_misc.php`) skip tax fields, so on this uCRM a TIN is
never listed as a sales person.

**The retry works** (`lib/KycCrmSync.php`, `cron/kyc_crm_sync.php`), every five
minutes, per application:

1. **claims** it with one conditional UPDATE, so the cron and an admin's
   Retry can never both create the same customer (a claim from a run that died
   is taken over after five minutes);
2. fits the stored request to this uCRM (it may carry organization 2);
3. **looks for a uCRM client with the same phone number** (last nine digits,
   the lookups the KYC form makes). If there is one it **stops** and marks the
   application *for a person to check*: after days of waiting someone may have
   typed the customer in by hand, and a duplicate client is worse than a
   customer who waited;
4. creates the client and finishes what the form would have done: the cash
   payment (under the same `kyc_auto_payment_enabled` switch, reference
   `KYC-<id>`), the quote where the admin token is set, and the work order and
   tag on the South Sudan layout only.

Back-off 5 min, 15 min, 45 min … up to 12 h; after 10 attempts it stops and
says so. Applications older than 30 days are never created automatically.

**The staff see what is true:**

- the KYC result says *"Customer saved in the plugin, but NOT in the CRM yet
  (uCRM answered HTTP 404: Not Found). It is retried automatically, and an
  admin can retry it now from Orders."*, in **amber**;
- the reason is kept on the application (`crm_sync_error`: HTTP status and
  uCRM's own message, never the request);
- Orders counts **In CRM** from the uCRM id, shows *Not in CRM yet* on the
  card with the reason, and gives an admin **Retry now** — or **Create in CRM
  anyway**, behind a confirmation, where a same-phone client was found, with a
  link to that client;
- registering the same phone again says the first registration is waiting for
  the CRM, instead of *"already registered"* with no way forward.

## 4. Tests

- `tests/test_kyc_crm_create.php` — **112 assertions**, against a fake uCRM
  shaped like both installs (`tests/fixtures/fake_ucrm_kyc.php`). It drives the
  real `KycService`, `KycCrmSync`, the real cron file in the server's directory
  layout (included the way `master.php` includes it), the Orders template and
  the real `post_kyc.php` handlers.
- **29 weakened copies of the fix, each caught by counted failures**, with the
  unmodified copy passing first as the control: no fitting, fields kept, no
  tax guard, guessing an organization, no claim, no phone check, the old cron,
  the old Orders count, the old message, a green flash, no backoff, no age
  limit, an unescaped reason, Retry shown to or accepted from agents, and
  more.
- `tests/test_cashbook_currency.php` now scans `lib/KycCrmSync.php` (it posts
  payments); a planted literal `'USD'` there fails it.
- Full plugin suite: **186 files, exit 0, twice.**

## 5. Deploy and verify — the operator's steps

**Before deploying**, look in uCRM for the three waiting customers by name
and phone (the Orders screen lists them). If any was typed in by hand, tell
me and do not deploy yet. The phone check stops the retry for a same-number
client, but a hand-typed client with a different number would not be caught.

```
cd /opt/dishnet && bash scripts/deploy-hybrid.sh --check     # note the live commit, for rollback
git pull origin claude/study-this-jhe2eg && bash scripts/deploy-hybrid.sh
```

Within ten minutes of the deploy, read the result (read-only; prints ids and
statuses, no names or phones):

```
docker exec -i ucrm php <<'PHP'
<?php
$db = '/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3';
$p = new PDO("sqlite:$db", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
foreach ($p->query('SELECT id, data FROM kyc_applications ORDER BY id') as $r) {
    $a = json_decode($r['data'], true) ?: [];
    printf("app %-4s crm_id=%-3s sync=%-7s attempts=%d  %s\n", $r['id'],
        empty($a['crm_client_id']) ? 'NO' : 'yes', $a['crm_sync_status'] ?? '-',
        (int)($a['crm_sync_attempts'] ?? 0), substr((string)($a['crm_sync_error'] ?? ''), 0, 110));
}
PHP
```

Expected: each application `crm_id=yes sync=synced`, or `sync=review` with
the uCRM client number to check. The three were Credit sales, so they arrive
in uCRM **as leads** (Clients → Leads), which is what the KYC form does for a
Credit sale. uCRM's plugin list keeps showing 5.18.27 until a ZIP is
installed ([DEPLOY.md](../DEPLOY.md)); the direct deploy runs the new code
either way.

**Rollback:** `git checkout <the commit --check reported> && bash
scripts/deploy-hybrid.sh`, then `git checkout claude/study-this-jhe2eg`
afterwards. The deploy never deletes, so the three new `lib/` files stay on
disk, unused by the older code. Clients already created in uCRM stay created.

## 6. Found on the way — not changed

- **Every payment turns a residential client into a company client.**
  `CrmApiClient::post('payments')` reads `clientType == 1` as "lead" and
  PATCHes it to 2. In uCRM, `clientType` 1 is *residential* and 2 is
  *company*; lead is `isLead`. So the first payment to a residential customer
  makes them a company, and EFRIS reads the buyer type from that. The KYC form
  has always done this for Cash sales, and the retry does exactly what the form
  does. The three waiting customers are Credit sales, with no payment.
- **`includes/api/api_cron_debug.php` is loaded before sign-in is checked**
  (`includes/api_handlers.php:66`, the token check is line 71):
  `kyc_quote_debug` answers with customer names, and `backup_download` is
  guarded by a key written in the source.
- **Flash messages are printed unescaped** (`public.php`, the `kyc-alert`
  block). The pre-existing "already registered under: {name}" message puts a
  stored name there as it is. The new messages carry no stored markup: the
  uCRM reason is cleaned, and the new duplicate message strips `< > & "` from
  the name and phone.
- `crm_dishnet_org_id` defaults to 2 in `public.php` and nothing reads it.
- A legacy `plugin.sqlite3` still sits inside the plugin directory on the
  server. The plugin uses the sibling data directory; the old file is unread.

## 7. The 5.18.28 deploy — 25 September 2026

- Deployed with `deploy-hybrid.sh`; the container served `0d20e05`. It had
  been running **5.18.27** (`90cf102`, a ZIP built on 19 September from this
  branch; the plugin's `build.json` says so), so the deploy changed only this
  fix. `90cf102` is the rollback point.
- The retry's first run, 11:55:07, took 2,415 ms:
  - **applications 1 and 3 were created in uCRM**, as leads;
  - **application 2 stopped for a check**: uCRM client #10 has the same phone
    number.
- Nothing was sent to anyone. The 5.18.28 retry made a quote only with an
  admin token, and none is set; it sends no WhatsApp.
- Measured the same day, read-only:
  - automatic quote: **on**; admin token: **not set**;
  - support-channel WhatsApp goes out through Evolution, `dishnet_ug`;
  - the support number printed in messages is +256 705 993 348.

## 8. What 5.18.29 changes

Until 25 September no KYC customer had ever reached uCRM on this install, so
the steps the form takes **after** a create had never run here. From the next
registration they do: the booking confirmation WhatsApp, the automatic quote
(e-mailed by uCRM, and its PDF on WhatsApp), and for Cash sales the payment.
Checking them found four things.

1. **The retry quoted differently from the form.** The form quotes the
   package, then the hardware (the cart, or the single kit), then for Fiber
   the installation fee, each linked to its uCRM product. The 5.18.28 retry
   quoted the package alone, and only with an admin token. Now:
   - one builder, `KycService::quoteItems()`, and one sender,
     `KycService::postQuote()`, serve both;
   - a refused create keeps the lines the form built (`quote_items`), and the
     retry quotes exactly those;
   - an application saved before 5.18.29 is rebuilt the form's way, at the
     price it was offered;
   - the same switch (`kyc_auto_quote_enabled`, on the Settings screen), the
     same limit (`kyc_auto_quote_max_amount`), the same credential (the admin
     token when set, else the plugin's key);
   - a refused quote is recorded as `quote_error` on the application, as the
     form records it.
2. **The booking confirmation lists Fiber and "DishNet 4G".** It offers every
   service the South Sudan install sells, with installation times, and says
   Starlink takes 1–2 working days. Uganda sells Starlink only, and its own
   FAQ says *"In Kampala and major cities, usually 1–3 working days after
   payment"*. New setting **`kyc_welcome_timeline`**:
   - unset: the South Sudan lines, byte for byte (tested);
   - text: printed as written, instead of those lines;
   - `omit`: no timeline at all.
3. **Nothing resolved a stopped application.** When the phone check finds the
   customer already in uCRM, Orders now offers **"✓ This is uCRM client
   #N"**:
   - admins only;
   - only a client the phone check itself found, only while the application
     waits for that check, and only if uCRM still has the client;
   - it records the id and creates nothing in uCRM: no client, payment,
     quote, work order or tag.
4. **The retry had no time budget.** `master.php` gives each job 60 seconds,
   and a job over it ends the whole scheduler run. The retry now stops
   starting creates after 40 seconds and leaves the rest for the next run,
   always attempting at least one.

**Tests:** `tests/test_kyc_crm_create.php`, 147 assertions: the shared builder
on its own, then the form and the retry through the fake uCRM (lines, product
links, credential used, limit, switch, refused quote), the time budget, the
link (each refusal, and that it writes nothing to uCRM), and the confirmation
unset, set and `omit`. The weakened copies and the full suite are recorded in
the commit.

**Deploy, then set the Uganda text** (as the plugin folder's owner, like the
earlier `set_config.php` notes):

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/deploy-hybrid.sh
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key kyc_welcome_timeline --value "🛰 Starlink: usually 1–3 working days after payment (Kampala and major cities)"
```

The text is the FAQ's; customers read it exactly as written, so change it
first if it should say something else. For application 2: open uCRM client
#10 from its Orders card. Press **This is uCRM client #10** if it is the same
customer, or **Create in CRM anyway** if it is someone else.

**Deployed 25 September 2026.** The container went from `0d20e05` to
`ea18fef`, and `set_config.php` saved `kyc_welcome_timeline` as *"🛰 Starlink:
usually 1–3 working days after payment (Kampala and major cities)"*.

- Application 2 still waits for a person to compare it with client #10.
- The assistant's own delivery fact (`ai_fact_delivery`) tells it not to
  promise a number of days. The confirmation now does, in the FAQ's words.
  Whether both should say the same is the operator's call; `omit` removes the
  timeline from the confirmation.
