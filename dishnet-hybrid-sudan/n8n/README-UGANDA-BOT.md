# DishNet Uganda AI Bot — n8n workflow

`DishNet_Uganda_AI_Bot_v1.0.json` — ported from the South Sudan bot
(`DishNet AI Bot v3.7`), 139 nodes, imports inactive.

## What is different from South Sudan

Not a find-and-replace. Each of these is a deliberate change.

| | South Sudan | Uganda |
|---|---|---|
| Prices | `dn_products` table | **live uCRM feed** — `prices.php` |
| Currency | USD / SSP | UGX, from the feed |
| Products | Fiber + Starlink + Ruijie + MikroTik | Starlink only |
| Coverage | 33 Juba fiber areas | everywhere, clear sky |
| Tables | `dn_*` | `ug_*` |
| Redis | `dn:*` | `ug:*` |
| Chat memory | `dishnet_chat_memory` | `dishnet_ug_chat_memory` |
| Timezone | Africa/Juba | Africa/Kampala |
| Webhook | `/webhook/dishnet-ai` | `/webhook/dishnet-ai-ug` |
| Office | Tomping, Juba | 4th Floor Acacia Mall, Kampala |
| Payment | "never share bank details" | Ecobank details given in chat |
| Outside price tool | `jumia_price_search` (USD) | **removed** |

### Prices come from uCRM, not a copy

The South Sudan bot keeps its catalogue in a Postgres table. Uganda's real
catalogue is in uCRM, and the plugin already publishes it:

```
https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/prices.php
```

`Load Products` calls that feed. Nothing is duplicated, so nothing can drift —
a second copy of prices is how two systems come to disagree in front of a
customer. The feed is built by `PublicPriceFeed`, which structurally cannot
include cost or margin.

The prompt therefore contains **no prices at all**. It says: quote only what is
in the list supplied with this message, exactly, and if the list is empty quote
nothing and route to Sales.

### The `jumia_price_search` tool was removed

It returned USD prices from a third-party endpoint. In a UGX market, with uCRM
as the single source of truth, a second price source contradicting the first in
the same conversation is the failure this port exists to avoid.

### Separate storage, deliberately

Uganda's tables and Redis keys are prefixed `ug_` and `ug:`. If both countries
ran on one database with the `dn_` names, Ugandan leads would land in South
Sudan's pipeline. That is not hypothetical: on 10 September, Uganda's handover
alerts had been going to a +211 number for weeks because the alert number was
inherited from the Sudan config, and the LTE cron was polling a Sudan server
because its URL defaulted to one.

## Before you enable it

**1. Staff numbers — nine places, all currently empty.**

Every recipient list ships as `[]` rather than inheriting South Sudan's staff.
Empty means that alert reaches nobody, so fill them or you will not be told:

`Build Staff Recipients` · `Build HOT Recipients` · `Build Support Recipients` ·
`Build Handoff Recipients` · `Build Alerts` · `Build Watchdog Alerts` ·
`Format Digest` · `QA · Build Report` · `QA · Build Critical`

Use people's handsets. **Not** 256703834115 or 256705993348 — those are the
plugin's own WhatsApp numbers, and an alert sent to one arrives as a customer
message, gets answered, and the answer lands back on the sender.

**2. Two secrets.**

- `Ctx · Plugin Lookup` → `EDIT-ME-WEBHOOK-SECRET`
- `Sync · Fetch UCRM Clients` → `EDIT-ME-UCRM-APP-KEY`

**3. Credentials** — re-select after import: Postgres (32 nodes), Evolution API
(14), Redis (14), OpenAI (5).

**4. Run `▶ SETUP (click Execute once)`** to create the `ug_*` tables.

**5. Point Evolution's webhook** for `dishnet_richard` at
`/webhook/dishnet-ai-ug`.

## The thing that will bite you

**Turn the plugin's WhatsApp AI off first**, or both will answer every message
and each customer gets two replies:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/config_trace.php ai_enabled
```

Set `ai_enabled` to false once the n8n bot is live. Changing the Evolution
webhook to n8n does most of this by itself — the plugin stops receiving
messages — but `ai_enabled` is the switch that makes it certain.

The plugin keeps doing everything else: invoices, EFRIS, email, quotations,
stock, the Starlink session. Only the WhatsApp reply moves.

## Still open

- **Opening hours** are deliberately absent — the prompt refuses to state any
  and offers to confirm. Add them to section 3 when you publish them.
- **Website page links.** Only `https://dishnetuganda.com` is referenced. The
  prompt is told not to invent page addresses; add real ones when they exist.
- **Site survey.** The plugin's knowledge base says a technician confirms sky
  view "at survey"; your team told a customer there is no site survey. This
  prompt says only "a clear view of the sky" and does not mention a survey
  either way. Decide which is true and state it.
