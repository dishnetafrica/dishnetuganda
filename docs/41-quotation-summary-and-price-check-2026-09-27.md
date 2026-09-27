# 41 — A quotation summary a customer can read, and a price check the model cannot walk past

27 September 2026. **Plugin 5.18.46: built, rehearsed, not yet deployed.** The deploy command is in §7.

**Uganda only.** South Sudan's quotation summary and price check are unchanged, and that is measured (§2.5, §3.4).

## 1. What the operator saw

On order 000114 the WhatsApp quotation summary said:

```
💰 Hardware: UGX 2,399,000
💰 Monthly: UGX 249,000
🏷️ Total: UGX 2,648,000
```

The operator wrote: *"here Monthly give wrong in message can you cehck this in ssytem it happen when we carete new
quoation then this text goes"*. Asked what looked wrong, the operator left it to how a customer would read it: *"i
will go with your recommandation think as customer if you get that message what you understood"*.

**How a customer reads it:**
- "Hardware" includes the installation, which is not hardware.
- "Monthly: 249,000" sits beside a total of 2,648,000 and does not say whether it is inside the total. It is:
  2,399,000 + 249,000 = 2,648,000. A customer can fairly read it as 2,648,000 now and 249,000 a month on top.
- Nothing says what is paid after the first month.

**The words were also chosen by guessing (measured).** The summary split the lines with one pattern:
- a line whose name held `kit`, `hardware`, `device`, `router`, `ont`, `onu`, `cable`, `nanostation`, `mikrotik` or
  `installation` was hardware;
- **everything else was "Monthly"**.

On a bigger-area quote that is badly wrong. For the setup of §2.2, the code as it was (`webhook.php` at `fa2d463`,
kept as the test's golden) prints:

```
💰 Hardware: UGX 3,877,000
💰 Monthly: UGX 1,848,500
```

The two access points, the connectors and the consultancy (1,519,500) are counted as monthly charges with the
plan. And a plan whose name holds "Monthly" was hardware, because "ont" is inside it.

**The operator's decision**, asked with the new message written out: **"Use the clearer message (Recommended)"**.

The operator then sent order 000117: *"this is latest i build quotation and its provided correct working"*. Its
numbers are right as well. §2.1 is what 5.18.46 sends for it.

## 2. What 5.18.46 sends (Uganda)

### 2.1 The lines

```
💰 One-time: UGX 2,399,000
💰 First month: UGX 249,000 (then UGX 249,000 per month)
🏷️ Total: UGX 2,648,000
```

This is order 000117, and 000114 too: the Mini Kit, the plan and the installation. The item lines above it, the
Total, the payment lines and everything else in the message are as before. The Total is still uCRM's.

### 2.2 How a line is sorted

- **A line is the monthly plan when it is spelled like one of uCRM's service plans.** The spelling is compared the
  way the price feed and the assistant already compare it (`PublicPriceFeed::planKey`): case ignored, "Starlink"
  dropped, letters and digits only. So "Residential Lite (up to 100 Mbps)" on a quote is uCRM's plan "Starlink
  Residential Lite ( up to 100 Mbps)".
- **Every other line is one-time.** There is no word list.
- uCRM's plans are read once, when the quotation webhook arrives (`GET service-plans`).
- **What follows the first month** is the plan's own monthly price in uCRM, not the quote line's. They differ when
  the quote carries a discount.

The bigger-area setup, at the live prices of 27 Sep: the Standard Kit, the installation, the MikroTik L009, two
Ruijie access points, cable, connectors, consultancy and the Residential plan. 5.18.46 sends:

```
💰 One-time: UGX 5,396,500
💰 First month: UGX 329,000 (then UGX 329,000 per month)
🏷️ Total: UGX 5,725,500
```

### 2.3 The other cases, each tested

| The quote | What the summary says |
|---|---|
| the plan three times (three months paid up front) | `First 3 months: UGX 747,000 (then UGX 249,000 per month)` |
| a first month quoted at 200,000 | `First month: UGX 200,000 (then UGX 249,000 per month)` |
| a plan whose name holds "Monthly" | the plan line, not hardware |
| one-time lines only, or the plan only | no split: the lines and the Total, as before |
| uCRM cannot list its plans | no split; asked once, not retried; the plugin log says *"quotation summary: uCRM did not list its service plans — sent without a one-time/monthly split"* |

Where uCRM cannot say which line is the plan, the summary does not guess. The customer still gets every line and
the Total.

### 2.4 Which install is "Uganda"

The one whose tenant profile is Uganda. This is `TenantProfile::current`, the rule the portal has used since
5.18.41, reading the same stored settings and vault.

**Reasoned, not measured on a live quotation.** On 27 Sep the live Terms page read Uganda (stage V2), through the
same function. The webhook reads the same sources as that page, with the same ones winning, and adds the
configuration files. So it reads Uganda too. The next quotation made after the deploy is the measurement (§7).

### 2.5 South Sudan: unchanged, measured

- The four South Sudan summaries are **byte-identical** to the code before the change: USD and no currency, each
  for an order and a bigger-area setup. The goldens were taken from `webhook.php` at `fa2d463`.
- Its uCRM is asked **nothing more**: the fake uCRM counts zero service-plan requests.

## 3. The price check reads amounts written without commas, and refuses an unfilled slot

### 3.1 What stage AI saw live on 27 Sep (5.18.45)

`deploy-5.18.45.sh` asked the live assistant its eleven questions (docs/40 §16). Three replies should never have
reached a customer:

- **B1** (cover 200 m around a hotspot) listed MikroTik 700,000, access point 700,000, cable 378,000, connectors
  19,500 and consultancy 100,000. It then wrote **"TOTAL for the setup: 1993500 UGX"**. The right total is
  **1,897,500**.
- **B2** (WiFi to another building) listed the same five items, then **"TOTAL: 1999500 UGX"**. Also 1,897,500.
- **A1, second turn** (about 50 people) listed the items, then **"TOTAL FOR SETUP: [Sum of setup costs]"**.

**The price check let all three through**: *"0 refused by the price check"*.

### 3.2 Why

- The check read only amounts written with separators (`1,999,500`, `1 999 500`).
- The prompt gives the assistant every price without them (`price 700000`), so the assistant writes them that way.
- A slot left as a template (`[Sum of setup costs]`) holds no amount at all.

### 3.3 What 5.18.46 changes (`ReplyPrivacyGuard`)

Where the hardware advice module is on (`ai_hardware_expert`, Uganda):

- **Plain amounts are read too:** five digits or more, standing alone or straight after a currency (`UGX1999500`,
  `USh 1999500`). Each must be a price or a permitted total, exactly as a separated one must.
- **What is not money stays out:**
  - a kit serial (`KIT304012345`) or an invoice number;
  - a WhatsApp link;
  - a date or a year;
  - a speed or a count.
- **An unfilled slot is refused:** a word in square brackets, such as `[total]`, `[Sum of setup costs]`,
  `[Customer Name]` or `[insert amount]`. A Markdown link (`[Pay here](https://…)`) is not a slot, and neither is a
  name in brackets (`Router 3 [Gen 3]`).
- **A refused reply is not sent.** As for every refusal before, the customer gets the safe fallback and the
  conversation is handed to a person. One security event is stored, and it holds no text of the reply.

Replayed against the live price list:
- B1, B2 and A1 are each refused.
- What the assistant got right still passes:
  - B1's second turn: 2 × 700000 = 1400000, TOTAL 2100000;
  - B3's two prices;
  - B4's two Starlink routers;
  - C1's home total.
- The Airtel Money merchant ID passes, because the payment answer puts it in the prompt.
- A number the customer wrote themselves passes.

### 3.4 South Sudan: unchanged

The module is off there, and so are both new checks. Every verdict is identical to the check without them
(tested).

### 3.5 The check tool

`scripts/dnb-ai-check.sh` judges with the installed plugin's own options, and says so:

> the price check also: reads amounts written without commas (700000 as well as 700,000), and refuses a reply with
> an unfilled slot such as [total]

A refused reply shows the amounts it could not match, or *"it left a template slot unfilled, such as [total]"*,
and the draft.

## 4. Not changed, and open

- **The prompt still prints prices without commas.** Printing them with commas, and giving the assistant the total
  instead of asking it to add, would prevent a wrong total rather than refuse it. That changes the Uganda prompt,
  so it is **proposal P10, for approval**.
- **P8** (the prompt-digits rule) and **P9** (the `<<ESCALATE reason>>` legend) are still open (docs/40).
- **From the 5.18.45 replies, noted only:**
  - **B4** named the two Starlink routers and asked which kit the customer has. It did not say the survey confirms
    the number.
  - **B4** also said the Router Mini "works with Mini kit" and Router 3 "with Standard kit". The catalogue says both
    fit the Standard 4, Standard 4 X, Mini and Gen 2 kits, and Router 3 also Gen 3.
  - **C1** priced the Mini Kit with the higher-capacity Residential plan (329,000), not Residential Lite (249,000).
- **uCRM changed a price between two runs.** The Ruijie access point was 1,100,000 at 06:14 (docs/40 §13.2) and
  700,000 at 07:55. The assistant quoted what uCRM said each time.

## 5. Proofs

- **`tests/test_quote_summary.php`: 39.**
  - It drives the real webhook under `php -S`, against a fake uCRM.
  - It covers South Sudan's four goldens, orders 000114 and 000117, the bigger-area setup and the cases of §2.3.
  - **Six weakened copies of `webhook.php` are each caught.**
- **`tests/test_price_check_plain.php`: 68.**
  - The live replies are checked against the live price list, with the shapes of §3.3.
  - It covers where the options come from, and the real worker handing a refused reply over.
  - **Eight weakened copies are each caught.**
- **`scripts/harness/ai-check/rehearse.sh`: 252/252**, against 5.18.45 and 5.18.46.
  - The fake provider writes what the live model wrote.
  - 5.18.45 lets both through (the control). 5.18.46 refuses both and says why.
- **Full plugin suite, twice:** 212 test files, 0 failed.
- **`scripts/harness/deploy-5.18.46/rehearse.sh`: 117/117 on two consecutive runs** (§6).

## 6. The deploy script

`scripts/deploy-5.18.46.sh`, pinned to `131712a`. It uses the same machinery as 5.18.45:
- the backup as fixed on 27 Sep (`VACUUM INTO` copies, tar's own words shown);
- the documented deploy;
- stage V, unchanged.

There is **no stage K**, because no knowledge row changes.

- **Q** checks that the installed `webhook.php` carries the new summary. No quotation is created here: the next one
  the team makes shows it.
- **AI** asks the same eleven questions. It also:
  - checks that the price check reads plain amounts and refuses slots;
  - reports a refused reply as a **note**, a measurement of the model and never a failure of the deploy;
  - reports that products spelled like a plan are read as the plan.

**The rehearsal** runs the real script against a sandbox container:
- The first run passes every check outside V; V answers a stand-in. It shows the fake model's three refusals and
  writes no knowledge row.
- Each of these gives the verdict it should:
  - a person's wording;
  - the hardware module off;
  - a 5.18.45 `webhook.php` installed;
  - no product spelled like a plan;
  - no AI key;
  - another commit served.
- **Seven weakened copies of the new checks are each caught:**
  - Q reading the checkout's webhook instead of the installed one;
  - the price-check line never required;
  - a refusal counted as a failure;
  - the refusals never reported;
  - no plan copy read as recognised;
  - questions not asked, reported as asked;
  - the matcher turned back into a pipe.
- **The backup under live writes**, as for 5.18.45:
  - the pre-fix script as the control;
  - four real failures;
  - **seven weakened copies caught**.

## 7. Deploy, and what to send back

1. **The command:**
   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
     && mkdir -p /root/dnb-5.18.46 \
     && bash scripts/deploy-5.18.46.sh 2>&1 | tee /root/dnb-5.18.46/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   It takes a backup and asks you to type `DEPLOY`. It then deploys, re-checks the public pages and asks the
   assistant the eleven questions. Send back **the log file**.
2. **What the log should show:**
   - **A:**
     - `plugin commit 131712a (expected 131712a)`;
     - the live commit `0850e59`, which is the rollback commit;
     - the backup `ok`;
     - `GO`.
   - **B:** `ok container serves 131712a`.
   - **V:** all `ok`, as on 27 Sep.
   - **Q:** `ok Q the installed webhook carries the 5.18.46 quotation summary`.
   - **AI:**
     - `ok AI the price check reads amounts written without commas and refuses an unfilled slot (5.18.46)`;
     - the eleven replies. If the model writes a wrong total or a slot again, that reply shows **refused** with the
       reason, and one `note` counts them. That is the check working: the customer would have had the fallback and
       a person.
   - **F:** `5.18.46: PASSED`.
3. **After the deploy, the next quotation made in uCRM** shows One-time / First month (then … per month) / Total.
   That is the live measurement of §2.4. Quotations already sent are not changed.
4. **Running it again is safe.** Once the container serves `131712a`, the deploy is skipped.
