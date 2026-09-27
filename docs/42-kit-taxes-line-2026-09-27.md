# 42 — A Starlink kit price says what it includes: URA taxes and the UCC registration fee

27 September 2026.

- **Plugin 5.18.48** — the taxes line under a kit price. Built and rehearsed, **not yet deployed**. It includes
  5.18.47, the total check (docs/41 §9), which was never deployed on its own. The command is in §5.
- **The quotation PDF** says the same once staff load the new Uganda quotation template into uCRM (§4). The deploy
  cannot do that part.

**Uganda only.** Where the hardware module is off — South Sudan — nothing changes, and that is measured (§2.4).

## 1. What the operator asked

At 12:31 pm on 27 Sep a customer (number withheld here) asked on WhatsApp for *"starlink the big one Not mini"*. The
assistant quoted the Starlink Standard Kit at 2,649,000 UGX one-time, a professional installation at 150,000 UGX
and the Residential plan at 329,000 UGX a month. It said nothing about tax. The operator asked:

> *"for kits can we clearlyu mentiond all the taxes are inclusive including UCC registaraion feees and URA taxes so
> users feel more relvant ?"*

Two choices, both the recommended ones:

- **The wording — "All taxes included":**
  > The kit price includes all taxes — URA taxes and the UCC registration fee are already in it. Nothing is added
  > on top.
- **The quotation PDF — "Yes, match it":** clause 2 says the same on a quote that carries a kit.

The sentence names no amount and no rate. Prices still come only from uCRM.

## 2. On WhatsApp: the line under a kit price

### 2.1 The rule (`lib/KitTaxNote.php`)

After a reply has passed the price check, and after the Business-plan note where there is one, the plugin adds the
sentence as its own paragraph at the end. All of these must hold:

- **the hardware module is on** (`ai_hardware_expert`) — the Uganda install;
- **the catalogue has a Starlink kit:** a HARDWARE item whose name holds both "Starlink" and "Kit" (the Standard
  Kit, the Mini Kit). Accessories are never read, so the "Travel Kit | Mini" case never counts;
- **the reply states that kit's price**, read the way the price check reads amounts: "2,649,000" and "2649000"
  alike;
- **it is not said already:** the sentence is not in the reply, and the reply does not mention both UCC and tax in
  its own words. Twice in one message reads like a machine.

Nothing is added to a reply that gives only a monthly price, only an accessory's price, or no price, nor to a
refused reply: the customer gets the safe fallback and a person takes over.

**Where:** the replies the assistant writes with uCRM's catalogue in hand (`workers/AiReplyWorker.php`), which is
how the Sales number is answered. The older Support and Accounts auto-reply (`lib/WaAutoReplyService.php`)
is given no catalogue and no prices at all, so no kit price from uCRM can appear there, and it is unchanged.

The live reply of 27 Sep now goes out as it was, with this after it:

```
…Would you like to proceed with this option? 😊

The kit price includes all taxes — URA taxes and the UCC registration fee are already in it. Nothing is added on top.
```

The plugin log records each one: `conv N: kit tax note appended — a Starlink kit price was quoted`.

### 2.2 Why code adds it, not the model

- **The prompt already carried a tax fact** (`ai_fact_prices`, docs/27 GAP 4), and the reply of 27 Sep still said
  nothing about tax.
- **The same was measured before.** The Business-plan rule was in the live prompt, and 18 of 21 replies to a
  business ignored it (`lib/PlanFenceGuard.php`). Since then the plugin appends that note itself. The kit line
  works the same way, and it runs after the price check, so the check never reads it as the model's own words.
- **It comes back in the history.** Once sent, the sentence is part of the conversation the model sees next, so
  the model may repeat it. The check compares a reply only with the system prompt, which holds no history, so a
  repeat is not refused today. The sentence is also listed with the operator's own texts, beside the Business-plan
  note, so a repeat stays allowed even if the prompt one day quotes it.

### 2.3 The setting: `ai_fact_kit_taxes`

| Value | What customers get |
|---|---|
| unset | the approved sentence (§1) |
| `omit` | no line |
| any other text | that text, as written |

`tools/set_config.php` manages it like the other facts, and warns when the text holds a digit: an amount or a rate
there would be a price that did not come from uCRM. To switch it off, as the plugin's owner:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key ai_fact_kit_taxes --value omit
```

### 2.4 South Sudan

The line needs the hardware module, which South Sudan has off. Measured in `tests/test_kit_tax_note.php`: through
the real worker, a South Sudan reply that quotes a kit price goes out unchanged, and the list of operator texts is
the one it had.

## 3. On the quotation PDF: clause 2

Clause 2 of the Uganda quotation template, as the repository has held it since `2bfad82`:

> **2. Currency & Pricing:** All prices in Ugandan Shillings (UGX). No VAT is charged on this quotation. Prices may
> change with 30 days' notice. Any new or increased government tax, levy or regulatory fee introduced after
> acceptance may be passed on at cost.

(Where uCRM puts tax lines on a quote, the second sentence reads "VAT is itemised in the totals on page 1.")

On a quote that carries a Starlink kit and no tax lines, the second sentence is now the approved one:

> **2. Currency & Pricing:** All prices in Ugandan Shillings (UGX). The kit price includes all taxes — URA taxes and
> the UCC registration fee are already in it. Nothing is added on top. Prices may change with 30 days' notice. Any
> new or increased government tax, levy or regulatory fee introduced after acceptance may be passed on at cost.

- **A quote with tax lines** keeps "VAT is itemised in the totals on page 1."
- **A quote with no kit** keeps "No VAT is charged on this quotation."
- **A kit** is an item whose label holds "Starlink" and "Kit", each written in capitals, lower case or with a first
  capital (Starlink / starlink / STARLINK, Kit / kit / KIT). The template may use only what uCRM's copy already
  uses, and that has no lower-case filter. So "StarLink" would not count. The names the assistant quoted on 27 Sep
  ("Starlink Standard Kit") do.
- **Why the old sentence had to go on a kit quote:** "No VAT is charged on this quotation" beside a chat that says
  the price includes all taxes reads as a contradiction.

**Read the clause whole once.** Its last sentence already said that a new or increased government tax, introduced
after acceptance, may be passed on. It now follows "Nothing is added on top." The two say different things (the
price today, a change in the law later), but that is legal wording, and it is yours to judge, not mine to change.

**Proved with real Twig** (`scripts/harness/quotation-template/rehearse.sh`). The template renders on Twig 3 and
Twig 2.16 under a sandbox that permits only what the `2bfad82` template uses: `for`, `if`, `set`, `escape`,
`length`. So uCRM needs nothing new. Eight quotes, 27 checks on each version:

- a kit first, a kit last, in lower case and in capitals;
- no kit, a Starlink service with no kit, and the Travel Kit case;
- a kit with tax lines.

On all eight, **the rest of the page is byte-identical to the baseline's** once the sentence is set back. Three
weakened copies each fail: the kit sentence never printed, any Starlink item taken for a kit, and a filter the
baseline never used.

## 4. Loading it into uCRM (staff, in the browser)

The template lives inside uCRM, so the plugin deploy does not change it. It goes in by the same route as the
invoice template did on 26 Sep (docs/38 §7.2).

1. **Look first.** Open the newest quotation's PDF in uCRM.
   - Page 1 says "UCC Authorised Starlink Installer · Kampala, Uganda", and page 2's clause 2 begins "All prices in
     Ugandan Shillings (UGX)": uCRM uses the Uganda template. Go on to step 2.
   - It says Juba, South Sudan or USD: quotes use the South Sudan template, as invoices did until 26 Sep. Uploading
     would then change the whole document, not one sentence. **Send a screenshot first.**
2. **Upload** `template-quotation-uganda-2026-09-27.zip` in uCRM's quote templates. That list sits beside the
   invoice templates, where v2 went in on 26 Sep. If uCRM asks for a name, call it "Quotation Uganda 27 Sep".
3. **Give it to the organization** as its quote template, as you did for invoice v2.
4. **The proof is the next real quotation with a Starlink kit.** Its PDF's clause 2 should read the new sentence.
   Quotations already made keep their PDF. **Do not make a test quote for a real customer:** the plugin sends
   every new quotation to the client on WhatsApp.
5. **If staff changed the quote template inside uCRM** and want to keep those changes, paste only the new
   clause 2 line instead of uploading. It is line 273 of
   `dishnet-hybrid-sudan/ucrm_pdf_templates/quotation_uganda/template.html.twig` and is self-contained.

**The ZIP** is built like uCRM's own exports: `template.html.twig` then `template.css` at the top level, deflated,
at a fixed timestamp. Both entries are byte-identical to the repository:

| File | sha256 |
|---|---|
| `template.html.twig` | `31164915422688bad322da5d83ae76928e732cebcebe0908cd0109247f7b9446` |
| `template.css` | `654e406a5ebccb0fe44b059bf45170890b69f3e8190e1fbb7aedf825748c846e` |
| the ZIP | `2a01039dee4a8685b2616bf49598a8a8d15a625f640dcdc19a245df607994de3` |

## 5. Deploy 5.18.48, and what to send back

### 5.1 The deploy script

`scripts/deploy-5.18.48.sh`, pinned to the plugin commit `65b1ace`. Same stages as 5.18.47's (docs/41 §10.1):

- **A** — before-evidence and the backup, then GO or NO-GO;
- **B** — the documented deploy;
- **V** — the public pages, the `:8443` door and the loop check;
- **Q** — the quotation summary;
- **AI** — the assistant, asked eleven questions (a few cents; nothing is sent to anyone);
- **F** — the summary.

Stage AI gains two checks:

- **The report names the line in force.** "the approved wording" is ok. "your own wording" and "OFF (omit)" are
  notes, because both are your choice. With the hardware module off it is a failure: no reply could carry the
  line, and on the Uganda install that module is on.
- **The count of replies that carried it.** Whether a reply quotes a kit price is up to the model. So "added under
  N of the replies" is ok, and none this time is a note, not a failure.

**Do not run the 5.18.47 command.** It checks for the commit the branch no longer ends on, so it stops at stage A
and changes nothing. 5.18.48 carries all of 5.18.47.

### 5.2 The command

Run as root on the server:

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-5.18.48 && bash scripts/deploy-5.18.48.sh 2>&1 | tee /root/dnb-5.18.48/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
```

Then send back **the log file** from `/root/dnb-5.18.48/`, not a copy of the terminal.

### 5.3 What the log should show

- stage A: `GO`;
- stage AI:
  - `ok AI a Starlink kit price carries the approved taxes line …`;
  - the Asked line ending `; N with the kit tax note added`;
  - then either `ok AI the taxes line was added under N of the replies …` or the note that no reply quoted a kit
    price this time;
- 5.18.47's total checks, as docs/41 §10.3 lists them;
- in the summary, `taxes line` quoting the approved sentence;
- the last line: `5.18.48: PASSED.`

## 6. Proofs

- **`tests/test_kit_tax_note.php` — 73 assertions**, including eleven weakened copies, each caught:
  - the approved wording word for word, and what counts as a kit;
  - the live reply of 27 Sep gets exactly the sentence;
  - seven replies that must not get it, each for its stated reason;
  - through the real worker, with the context the worker builds from the catalogue:
    - Uganda appends, and the log line reads as in §2.1;
    - South Sudan is unchanged;
    - a refused reply gets the fallback and no line;
    - a Business-plan quote with a kit carries the fence note, then the kit line;
  - the setting's help text and warning;
  - the template's clause, read statically.
- **The check tool** (`scripts/harness/ai-check/rehearse.sh`), against a 5.18.46 install and a 5.18.48 install:
  **284/284, twice**. On 5.18.46 no line appears; on 5.18.48 the kit quote carries it. Three more weakened copies of
  the tool are caught.
- **The quotation template:** 27/27 on each Twig version and three controls failing as they should (§3). **8/8
  rehearsal checks, twice.**
- **The full plugin suite:** 214 files, **twice**, 0 failed.
- **The deploy script** (`scripts/harness/deploy-5.18.48/rehearse.sh`): **146/146 on two consecutive runs**. Its scenarios
  include the line switched off (a note), the hardware module off (a failure), and the count never read (caught).
- **Found by that rehearsal, before anything shipped.** With the line switched off, the run passed with a note,
  yet the summary at the end still said every kit price "now ends" with the approved sentence — something no
  customer would receive. The summary now repeats what the check tool's report found: the approved sentence, your
  own wording, switched off, or what the report says instead. The rehearsal had also been reading the whole log
  for the sentence, which the report and the summary print too, so its check that a reply carries it could pass
  on the summary alone. It now counts the sentence only on the assistant's replies, with a control that the
  sentence is printed three times and counted once. A weakened copy of the script that quotes the sentence
  whatever the report says is caught.

## 7. Not changed, and open

- **GAP 4, for quotes with no kit.** Clause 2 still says "No VAT is charged on this quotation" there. The assistant's
  tax fact (`ai_fact_prices`, docs/27 GAP 4) says listed prices include VAT. Which is right for plans and
  accessories is yours to say; nothing has been written for you.
- **The WhatsApp quotation summary** (5.18.46) does not carry the line. It can, if you want it — say so.
- **The plugin's own quotation PDF**, drawn only when uCRM gives none, says "All prices in UGX, taxes included where
  applicable." That agrees with the line, so it is unchanged.
- **Which quote template uCRM uses** is not known yet: §4 step 1.
- **Still to see from 5.18.46:** the next quotation made in uCRM should show One-time / First month (then … per
  month) / Total. The same quotation can prove §4 step 4.
- **Noticed while testing, not changed:** `tests/test_customer_pwa.php` leaves its `php -S` server running after
  every suite run. It starts the server through a shell, and `proc_terminate` stops only the shell. Harmless on the
  server (the suite does not run there); in a test sandbox, stop stray servers before trusting a result.
