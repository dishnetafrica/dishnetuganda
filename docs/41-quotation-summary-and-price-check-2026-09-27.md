# 41 — A quotation summary a customer can read, and a price check the model cannot walk past

27 September 2026.

- **Plugin 5.18.46** — deployed by the operator at 08:59 UTC and **PASSED** (§8). Two of the eleven live replies
  should have been refused and were not; why, and proposal P11, are in §8.2–§8.3.
- **Plugin 5.18.47** — P11 as the operator chose it: **the price check adds each total up.** Built and rehearsed,
  **not yet deployed** (§9). The deploy command is in §10.

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

## 8. The 5.18.46 deploy — 27 September 2026, 08:59 UTC

**PASSED: 40 ok, 0 failed, 0 notes.** `131712a` over `0850e59`.

- **A.** The backup, as at 07:54:
  - `plugin.sqlite3`, 22 MB: `VACUUM INTO` as `1000:1000`, integrity ok, 224 tables, the same sha256 on both sides;
  - no `dishnet.sqlite` on this install;
  - the data directory without the live databases, 98 MB, and the plugin's `data` folder, 100 KB;
  - tar exited 0; UISP health recorded; `GO`.
- **B.** The container serves `131712a`.
- **V.** All `ok`, and no fatal error in the container log since 08:59:08 UTC.
- **Q.** The installed webhook carries the new quotation summary.
- **AI.** Every line `ok`, the new price-check line and the plan copies (2) among them. Eleven model calls:
  *"0 refused by the price check · 1 with the Business-plan note added"*.

The operator pasted the terminal again. This command prints no secret; the log file is still the thing to send.

**The quotation summary has not yet been seen live.** The next quotation made in uCRM is that measurement (§2.4).

### 8.1 The eleven replies

| Question | Reply |
|---|---|
| A1 "a WiFi business in my trading centre" | Residential, unlimited; the Standard Kit, MikroTik, access point, cable and consultancy, then **"Total: UGX 4,627,000"** — **wrong: those five add up to 4,527,000**; the survey |
| A1 "50 people, unlimited, which package?" | Residential; the kit, installation, MikroTik, access point and cable (no price), then **"TOTAL TO GET CONNECTED: (Add total of kit, installation, router, access point, and cable)"** — **an unfilled slot, sent**; handed over with the reason "reason" (P9) |
| A2 "unlimited business plans?" | none; a Business plan is a block of priority data, then about 1 Mbps until more is bought — the approved fact |
| A3 "How much is Business 500?" | 285,000, and the Business-plan note appended |
| A4 "sell internet around my shop" | the higher-capacity Residential plan; a kit and network equipment; asks where the shop is and the area |
| B1 "cover 200 m around my hotspot" | the five items, **TOTAL: 1,897,500** — right; handed over with the reason "reason" (P9) |
| B1 "two access points and the MikroTik" | **2,100,000** — right |
| B2 "WiFi to my other building" | the five items, **1,897,500** — right; the survey |
| B3 "price of the access point and MikroTik" | 700,000 and 700,000 — right |
| B4 "3 floors; the upper floors have no WiFi" | Router 3 for each extra floor, 2 × 827000 = **1654000** — adds up, read without commas and passed; it did not ask which kit, offered only Router 3, and did not mention the survey |
| C1 "installed at my home" | Mini Kit + installation = **2,399,000**, then Residential Lite at 249,000 a month — right |

The model writes differently each run. B1 and B2 were wrong at 07:55 and right at 09:00; A1 was the other way
round. **One run is a sample, not a rate.**

### 8.2 Why two wrong replies were not refused — measured offline

Reproduced with the real check, the real prompt builder and the price list this log printed. The other accessories
are at their docs/19 shelf prices: the log prints only the two Starlink routers, and both match.

- **A1's "4,627,000" is a permitted total.** The check asks only whether a figure is some sum of listed prices, and
  4,627,000 is one. The Standard Kit, the installation and the MikroTik, with Router 3 and Router Mini, come to
  exactly that: one of 17 such sums.
  - It passes in 24 of the 28 orders uCRM could list the one-time items in.
  - It passes without the prompt too, so it is not the prompt-digits rule (P8).
- **This is the check's known limit (docs/40 §11.4, F-1), and it is wide.** With this price list:
  - about 4 in 10 round amounts between 1 and 6 million are permitted totals (41.6% in steps of 50,000);
  - around the right 4,527,000, every total off by 50,000, 100,000 or 200,000 either way passes.
- **A1's second reply wrote its slot in round brackets**, and stated no total at all. The slot rule of 5.18.46 reads
  square brackets only.

### 8.3 What would catch both — proposal P11, for approval

**A stated total must equal the lines listed with it, and a "TOTAL" with no figure is refused.** That is arithmetic
on the reply itself, so a coincidence with the price list cannot fool it.

It changes what the check refuses, and a model writes a list in more than one way:
- a unit price, then "2 × 827000 = 1654000";
- a list repeated just before its total;
- the monthly plan listed beside the one-time items.

So it needs every reply seen live so far (32, over three runs) as its test set, and approval. P10 (prices printed
with commas, and totals given to the assistant) would make these mistakes rarer; P11 would refuse the ones that
still happen.

### 8.4 Noted, not changed

- **P9:** two hand-overs again gave "reason" as their reason.
- **B4** did not ask which kit the customer has, and offered only Router 3.

## 9. 5.18.47 — the price check adds each total up (P11, as built)

The operator, asked how to stop wrong totals like 4,627,000 reaching customers, chose **"Build the total check
(Recommended)"**: add up the lines in each reply; refuse it if the stated total does not match, or if a "TOTAL" has
no figure; the customer gets the safe message and a person takes over; and test it first against every live reply,
so that no correct reply is refused.

**Uganda only.** It is switched by the hardware module (`ai_hardware_expert`), as 5.18.46's checks are.

### 9.1 The rule (`lib/ReplyTotals.php`)

A reply is refused for its total when:
- **a money TOTAL has no figure** — "TOTAL TO GET CONNECTED: (Add total of kit, …)", "TOTAL FOR SETUP: [Sum of
  setup costs]", or the label and then nothing (`total:missing`);
- **the total it states is not what the lines above it add up to** (`total:mismatch`). It is compared with:
  - the list lines directly above it, with and without the lines priced per month;
  - the same, plus a subtotal stated earlier;
  - and any total the reply already stated, so a summary that repeats its total is not checked against a part list;
- **a sum it writes out is wrong** — "2 × 700,000 = 1,500,000", wherever it stands (also `total:mismatch`).

A refused reply becomes the safe fallback and the conversation is handed to a person, as for every refusal.
Amounts are read exactly as the price check reads them: there is one definition (`ReplyPrivacyGuard::amountSpans`).

The price-list rule of 5.18.44–5.18.46 is unchanged and still applies to every amount. The totals rule is a second
question, asked of the reply itself, so a total that happens to equal some sum of our prices cannot pass it.

### 9.2 Read both ways, or left alone

A reply is refused only if **no reading** of it makes the total right.

**A count that is not beside its price is read both ways.** In "2 × Router 3 — 827,000", "For the two upper floors:
2 × Router Mini — 435,000" or "Router Mini x2 — 435,000", the price may be for one or for all. So the line counts as
827,000 or as 1,654,000, and a total that matches either is sent. Two exceptions:
- a count beside the price is arithmetic: "2 × 827,000" is 1,654,000;
- "each" with a count before the item says the price is for one: "2 × Router 3 — 827,000 each" is 1,654,000.

Beside a price, only "×" and a lower-case "x" mean "times". A capital X does not: in "Standard 4 or 4 X 377,000" it
is part of a product name.

**Left to the price-list rule**, because there is no total to check, or no sure way to read one:
- a line with two prices: "2,649,000 (or 2,249,000 for the Mini)";
- a price "each" or "per floor" with no count anywhere;
- two counts on one line;
- a list that could be read more than 64 ways;
- a total with no priced list above it;
- a total said in running prose: "That brings everything to 2,499,000 UGX in total";
- a label that is not money: "Total users: 50", "Total coverage: …";
- a sentence that says "total …:": "The total will depend on the cable length: …".

### 9.3 The live replies are the test set

`tests/fixtures/live_replies_2026-09-27.json` holds the 32 replies the live assistant wrote on 27 Sep (06:1x, 07:55
and 09:00 UTC). 30 have text; the two 06:1x fallbacks have no draft. It holds no personal data. Each reply is judged
against the price list of its own run: the Ruijie access point was 1,100,000 at 06:1x and 700,000 afterwards.

**Result: 5 refused, 25 sent exactly as written.**

| Run | What the reply said | 5.18.46 | 5.18.47 |
|---|---|---|---|
| 5.18.45, B1 | "TOTAL for the setup: 1993500 UGX" — the lines add up to 1,897,500 | refused: an amount | refused: an amount and its total |
| 5.18.45, B2 | "TOTAL: 1999500 UGX" — the lines add up to 1,897,500 | refused: an amount | refused: an amount and its total |
| 5.18.45, A1, second turn | "TOTAL FOR SETUP: [Sum of setup costs]" | refused: a slot | refused: a slot and a TOTAL with no figure |
| 5.18.46, A1 | "Total: UGX 4,627,000" — the lines add up to 4,527,000 | **sent** | **refused: its total** |
| 5.18.46, A1, second turn | "TOTAL TO GET CONNECTED: (Add total of …)" | **sent** | **refused: a TOTAL with no figure** |

The 25 sent include the shapes that made this hard: a total restated in a summary (5.18.44 B2), "1,100,000 UGX each
= 2,200,000 UGX", "2 x 827000 = 1654000", a list repeated just before its total, and the monthly plan listed beside
the one-time items.

### 9.4 Found before anything shipped

The check tool's rehearsal found one false refusal in the first build. Its floors reply, "For the two upper floors:
2 × Router Mini — UGX 435,000 each = UGX 870,000", was read as 435,000 = 870,000, because the count was not at the
start of the line. The reply is right, and it would have gone to a person.

Looking for the same mistake elsewhere found two more shapes. "Router Mini x2 — 435,000" and "2 × Router 3 —
1,654,000" (the line's own total) would both have been refused under a correct total. Neither appears in the live
replies, but both are ordinary ways to write a quote. That is why a count not beside its price is now read both
ways (§9.2). Nine shapes and six weakened copies hold it.

### 9.5 South Sudan: unchanged, measured

The rule runs only where the hardware module is on. With the module off, all 30 verdicts are identical to those of
the check with no options, which is what South Sudan ran under 5.18.46.

### 9.6 The check tool

`scripts/dnb-ai-check.sh` now reports:

```
  the price check adds up        each total: one that does not match the lines listed with it, or a TOTAL with no figure, is refused
```

A refused total is explained in the tool's output:
- "its total says 4,627,000; the lines listed with it add up to 4,527,000";
- "it gives a TOTAL with no figure";
- "a sum it wrote is wrong: it says 1,500,000, the figures come to 1,400,000".

### 9.7 Not changed, and open

- **The price-list rule still has its limit (F-1)** for an amount that is not a total. A wrong single price that
  equals a real sum of our prices still passes.
- **The price of reading a count both ways.** "2 × Router 3 — 827,000" followed by a total that forgot to multiply
  (827,000) is sent, because that total is right if 827,000 was the price for both. With "each" on the line it is
  refused.
- **P10**, for approval: print the prompt's prices with commas, and give the assistant the totals. It would make
  these mistakes rarer; 5.18.47 refuses the ones that still happen.
- **P8** and **P9** are still open (docs/40).

### 9.8 Proofs

- **`tests/test_price_check_totals.php`: 111.**
  - The 30 live replies, each against its own price list.
  - 20 shapes that add up, 13 that are wrong, and 11 that are left alone.
  - Where the option comes from, and who passes it. The real worker hands a refused total over, and its security
    event does not print the amount.
  - South Sudan's 30 verdicts are identical.
  - **Eighteen weakened copies are each caught.**
- **`tests/test_price_check_plain.php`: 68.** Its pinned options now include the totals rule, deliberately.
- **`scripts/harness/ai-check/rehearse.sh`: 273/273, twice**, against 5.18.46 and 5.18.47.
  - The fake provider writes a total that does not add up, though every figure in it is one of ours.
  - 5.18.46 sends it (the control). 5.18.47 refuses it and says by how much.
- **Full plugin suite, twice:** 213 test files, 0 failed.
- **`scripts/harness/deploy-5.18.47/rehearse.sh`: 125/125 on two consecutive runs** (§10.1).

## 10. Deploy 5.18.47, and what to send back

### 10.1 The deploy script

`scripts/deploy-5.18.47.sh`, pinned to `a9b46fb`. It is deploy-5.18.46.sh with three changes:
- stage AI has one new line, `the price check adds each total up … (5.18.47)`;
- the note that counts refused replies also names a total that does not add up;
- stage F says what changed for customers.

The backup, the documented deploy, stage V and stage Q are unchanged. There is no stage K.

**The rehearsal** runs the real script against a sandbox container, as for 5.18.46:
- The first run passes every check outside V. It shows the fake model's four refusals, counted in one note, and
  writes no knowledge row.
- With the hardware module off, the new line is a failure that names the module.
- **Eight weakened copies of the checks are each caught**: 5.18.46's seven, and the totals line never required.
- **The backup under live writes**, as before: the pre-fix script as the control, four real failures, and **seven
  weakened copies caught**.

### 10.2 The command

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
  && mkdir -p /root/dnb-5.18.47 \
  && bash scripts/deploy-5.18.47.sh 2>&1 | tee /root/dnb-5.18.47/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
```

It takes a backup and asks you to type `DEPLOY`. It then deploys, re-checks the public pages and asks the assistant
the eleven questions. Send back **the log file**.

### 10.3 What the log should show

- **A:**
  - `plugin commit a9b46fb (expected a9b46fb)`;
  - the live commit `131712a`, which is the rollback commit;
  - the backup `ok`;
  - `GO`.
- **B:** `ok container serves a9b46fb`.
- **V:** all `ok`, as on 27 Sep.
- **Q:** `ok Q the installed webhook carries the 5.18.46 quotation summary`. 5.18.47 keeps it.
- **AI:**
  - `ok AI the price check adds each total up: … (5.18.47)`, beside 5.18.46's line;
  - the eleven replies. If the model writes a total that does not add up, or a TOTAL with no figure, that reply
    shows **refused** with the reason, and one `note` counts the refusals. That is the check working: the customer
    would have had the fallback and a person.
- **F:** `5.18.47: PASSED`.

**Running it again is safe.** Once the container serves `a9b46fb`, the deploy is skipped.

**Still wanted:** the next quotation made in uCRM, which shows One-time / First month (then … per month) / Total.
That is the live measurement of §2.4.
