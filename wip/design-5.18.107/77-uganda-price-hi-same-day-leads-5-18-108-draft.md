# 77 — Uganda: a "Hi" after a price reply, a same-day reminder, name and email, and leads (5.18.108 draft, carried from docs/76 on 25 November 2026)

**Status:** DRAFT, carried over; nothing is built. **Not reviewed as a design of its own.**

- **Why this file exists.** On 25 Nov the owner split the next version in two. 5.18.107 keeps the quotation built in
  code (Part Q), quoted customers (Part K), the "assistant off" list (Part X), F-1, F-7, F-8 and the week's items
  (docs/76). **5.18.108 gets Parts H, S, N and L**, carried here word for word from docs/76 as it stood on 21 Nov after
  six reviews.
- **The section letters are docs/76's**, kept so that every cross-reference still resolves: §C (Part H), §F (Part S),
  §G (Part N) and §H (Part L) are here. **A reference to any other section (§A, §B, §D, §E, §I, §J, §X, §R, …) means
  docs/76's.** The parts of §K, §L, §M, §N, §O and §P that belong to these four parts are collected at the end of this
  file under their own letters.
- **Before 5.18.108 is built**, this draft is re-mapped against 5.18.107's code and reviewed, for three reasons:
  - it was written against `release/5.18.106`, and 5.18.107 changes the reply path it builds on (Parts Q, K and X, and
    the week's items in docs/76 §W, among them one reply per burst of messages);
  - **migration 092 is now Part X's alone** (`wa_no_ai`). `wa_price_hi` (§C.2) and `wa_conversations.lead_ask_at`
    (§G) move to a migration of 5.18.108's own, numbered after whatever 5.18.107 ships;
  - **`turn.lead_ask` (§G.2) is not built in 5.18.107.** 5.18.107 adds the `turn` leaf with one boolean,
    `quotation_offer`. Part N adds `lead_ask` to it.
- **What review 6 asked for here and was never reviewed** (docs/76 §R.7): the changes in §B.6, §H (the switch list,
  §H.1, §H.10), O1b, O1c and these parts' tests.
- **The owner's decisions for these parts are still open**: O1, O1b, O1c, O2, O3, O4, O5, O6, O8, O9 and O12 (below).
  They are asked when 5.18.108 is designed. O7, O10, O11 and O13 were answered on 25 Nov (docs/76 §O).
- **Scope:** Uganda only (`StaffJobsGate::applies`), each part with its own switch, off by default; South Sudan byte for
  byte unchanged. 5.18.108 is cut from 5.18.107 and can be deployed only after it.

Paths below are relative to `dishnet-hybrid-sudan/` unless they start with `docs/` (this repository's root `docs/`).

**Asked for** (owner, 18–20 Nov; paraphrased, no customer data; numbered as in docs/76):

1. **A "Hi" after prices.** After the assistant gives prices, if the customer does not answer within 4 minutes, send a
   short "Hi" from our side, so the customer feels a person is there. Choices recorded on 20 Nov:
   - the text is a setting, default "Hi";
   - an on/off switch, off until the owner turns it on;
   - at most once per conversation;
   - silent if the customer wrote back, a colleague replied or took over, the customer said STOP, or the number is staff;
   - the same sending limits as the reminders;
   - every send logged.
3. **A same-day reminder**, instead of the first reminder only after 24 hours.
4. **Name and email:** ask for them after 4–5 messages.
5. **uCRM leads.**
   - Create one also for a customer who gave only their number, filed under the number until a name arrives.
   - Set the client custom attribute **Lead source** (key `leadSource`, attribute 8, not visible in the client zone) to
     "WhatsApp (online)".
   - Fix the three gaps: a late name, a late email, an existing client matched by phone.

---

## C. Part H — one "Hi" after a reply that gave prices

### C.1 What counts as "a reply that gave prices"

The turn counts as priced when **any** of these holds:

- **Text.** `guardReply` sets `$ai['priced']` at the end of every path that returns a reply to send:
  - **When it is computed.** After `PlanFenceGuard`, `KitTaxNote`, any Part Q block and any Part K floor, so it describes
    the text actually sent.
  - **The test.** An amount read by `ReplyPrivacyGuard::amountsIn($reply, plain)` is in **`permittedPrices()`**, or in
    this turn's **block figures**. `permittedPrices()` is a new helper split out of `permittedAmounts()`, which reuses
    it, so the guard's allowlist is unchanged. The block figures are §D.6's separate list.
  - **What `permittedPrices()` holds.** Catalogue-derived values only:
    - hardware and accessory prices and their permitted subset sums;
    - the network-equipment and access-point/router multiples;
    - plan prices ×1–12.

    Never the customer's words, the prompt's digit runs, the customer, account, services, customer-phone or push-name
    walks, or any other scalar. So a payment number, the office number or a figure the customer typed never makes a
    reply priced.
  - **A Part K refusal** is never priced.
- **Quotation.** A Part Q block was placed in the reply.
- **Flyer.** `maybeSendFlyer()` now returns the plans flyer row it **stored** this turn (its id and time), or null.
  - The turn counts when a row came back. `$ai['send_flyer']` is never read for this: it stays set when the flyer was
    skipped (no file, the cooldown, a failed send).
  - The flyer counts because it carries the plan list, and the FLYER rule tells the model to keep the text short
    (`lib/DishNetAiBrain.php:577-584`).

**Not priced:**
- a `SAFE_FALLBACK` reply;
- a turn that escalated, since a person owes this customer an answer;
- a Part K refusal;
- a voice acknowledgement;
- a photo or document turn (the prompt ties neither to prices, `lib/MediaLibrary.php:274-312`);
- a reply that may have been sent (it returns before the bookkeeping, §B.1).

**The anchor** is the turn's **last** outbound row: the stored flyer row when one went, otherwise the text row.

### C.2 The record — `wa_price_hi` (migration 092)

**One row per priced turn:**
- **who and where:** `id`, `conversation_id`, `channel`, `phone` (international digits);
- **the turn:** `inbound_message_id`, the priced turn's own inbound row, found by the event's `wa_message_id`;
- **the anchor:** `anchor_message_id` UNIQUE, `anchor_at` (UTC), and `due_at` = anchor + `price_hi_minutes`;
- **the claim:** `claim_token`, `claimed_at`, `send_started_at`;
- **the outcome:** `outcome` (`NULL` · `claimed` · `sent` · `skipped` · `failed` · `uncertain`), `reason` (a fixed code),
  `wa_message_id`;
- **when:** `created_at`, `settled_at`.

**Where it is written.** In `AiReplyWorker::handle()`'s never-throw section, **after the photo, document and flyer steps
(after `:342`) and before the lead block (`:347`)**, so the flyer's outcome is known.

**What it must have.**
- `handle()` keeps the id `storeMessage()` returns for the text row. If that is null, the row is looked up by
  `wa_message_id`.
- **If the anchor row, or the turn's inbound row, cannot be found, no `wa_price_hi` row is written.** Neither id is
  ever null.

**When it is written.** Only when the switch is on and `convId > 0`. An error writing it costs the "Hi", never the
reply.

**Any later reply settles a pending row.** Every AI reply on the conversation, priced or not, settles a still-pending
row as `skipped: superseded`, from the same section.

**The migration** follows 089–091:
- it checks itself into `_schema_checks`;
- its comments and statements obey the splitter's rules;
- there is no `CREATE` or `INDEX` in the self-check.

### C.3 The timer — exact when possible, master as the safety net

1. **Sleepers, spawned by `run_worker.php`, never by `handle()`.**
   - `handle()` records each distinct **15-second bucket** among the `due_at` values it writes during the run.
   - After `AiReplyWorker::run()` returns, when WorkerBase has already released its lock, `run_worker.php` spawns one
     `php cron/price_hi.php --wake <due_at>` per recorded bucket. It uses the webhook's `@exec` pattern
     (`evo_webhook.php:490-516`).
   - The spawned shell **closes every descriptor above 2 before it execs PHP**, so a sleeper never keeps a lock its parent
     held. That includes master's own lock, when `run_worker` runs inside master.
   - Uganda only. South Sudan's `run_worker.php` spawns nothing.
2. **The script.**
   - It **refuses a `--wake` more than 30 minutes away, judged on the requested `due_at` before any rounding**. Since
     `price_hi_minutes` is at most 30, it never refuses a legitimate wake.
   - It sleeps until the **end of that wake's 15-second bucket**, then runs one pass. The pass handles every row due by
     then.
   - A per-bucket lock file keeps it to one sleeper per bucket.
3. **Master.** A Uganda-only job, `price_hi` (interval 60, so every cycle), runs the same pass. A row waits for master only
   when its sleeper died or its spawn failed. It is then about 5 minutes late, still inside the 10-minute lateness limit.
4. **One pass at a time**, under `flock` on the job's lock. The order inside a pass, as `OfferSender` does it:
   1. **The stale sweep, two-sided** (`OfferSender.php:261-272`):
      - a row `claimed` for longer than one send can last **with `send_started_at` set** is settled `uncertain`
        (`stale_claim`) and never sent;
      - one **without it** goes back to `NULL`, since it never reached WhatsApp.
   2. **The claim.** Each due row is claimed with a token: `UPDATE … SET outcome = 'claimed', claim_token = ?,
      claimed_at = … WHERE id = ? AND outcome IS NULL`.
   3. **The gates.** Gates 1–14 (§C.4) run on the claimed row. A skip settles it `WHERE id = ? AND outcome = 'claimed'
      AND claim_token = ?`.
   4. **The release.** Gate 15 (spacing or the hourly cap) **releases** the claim, setting `outcome = NULL` under the
      token. The row is pending again, and gate 2 or a later reply can still end it.
   5. **The stamp.** `send_started_at` is stamped under the token **immediately before** `sendText`.
5. **Too late is never.** A row due more than `price_hi_max_late_minutes` ago (default 10) is settled `skipped: too_late`
   and never sent.

### C.4 The gates, run on the claimed row in this order

| # | Gate | Code |
|---|---|---|
| 1 | switch off, not Uganda, `ai_enabled` off, or the sending ledger off (`wa_send_ledger` not `on`): gates 14 and 15 read `wa_auto_sends`, which is written only while the ledger is on | `off` |
| 2 | more than `price_hi_max_late_minutes` past `due_at` | `too_late` |
| 3 | any outbound row with an id above `anchor_message_id` on **any conversation of the phone that is not category `staff`**: assistant, plugin (a quotation sent on the support number included) or agent | `superseded` |
| 4 | the customer wrote, in either way: (a) an inbound row with an id above `inbound_message_id` on any conversation of the phone; (b) a `wa_seen_marks` row with direction `in` for the phone, on any channel, with `seen_at >= anchor_at`. No time clause on (a): ids are what order them | `replied` |
| 5 | a person acted: `HumanTakeover::since($pdo, $phone, $convIds, anchor) !== null`, which is time-bounded (§I.1) | `person` |
| 6 | the AI's pause is running (`human_active` and within `wa_human_cooldown_minutes`) | `paused` |
| 7 | an `ai.reply` or `ai.voice_ack` for any conversation of the phone is `pending`, `processing`, or `failed` with attempts left | `busy` |
| 8 | a live quotation for the phone, or `unknown`, when Part K's switch is on (`QuoteInPlay::forPhone`); this fails towards fewer messages | `quoted` |
| 9 | a colleague's or staff number (`ColleagueNumbers`, `StaffDirectory`), or a number on the "assistant off" list (Part X) | `staff` / `no_ai` |
| 10 | `AutomationPolicy::refusal()` not empty | `policy` |
| 11 | `OwnedNumberHold` holds the channel | `held` |
| 12 | `ContactOptOut::blocks(phone, channel, CLASS_PROACTIVE)`, which is what STOP records | `opted_out` |
| 13 | outside the reminders' window, `FollowUpPolicy::withinSendingWindow` (Monday to Saturday, 08:00–20:00), unless the owner chooses otherwise (O2) | `window` |
| 14 | another row of this conversation (`id <> ?`) **sent, uncertain or claimed** within the period of decision O1; then, if the owner chooses O1b, an automated message of the person kinds already went to this phone today (`phone_today`, `lib/WaSendBudget.php:319-326`); then, under decision O1c, the daily limit for the "Hi" | `once` / `phone_today` / `daily_cap` |
| 15 | the handset's ledger spacing or hourly proactive cap not met, read from `wa_auto_sends` as `WaSendBudget::claim` reads it (`:275-317`) | **releases** the claim (§C.3.4) |

### C.5 The send, the row and the ledger

1. **The send.** `EvolutionApiService::sendText($channel, $phone, $text, ContactOptOut::CLASS_PROACTIVE)`. A STOP then
   blocks it at the transport as well.
2. **On success:**
   - **Claim the echo first** (`EvoWebhookGuard::claim`, as `followup_send.php:329-336` does). An unclaimed short "Hi" is
     stored as "Team" and sets `human_active` for good.
   - **Then store it:** direction out, role **`assistant`**, agent **`DishNet AI`**, the `wa_message_id`, metadata
     `{source: price_hi, price_hi_id}`.
   - **Record it in the ledger:** `WaSendBudget::record($handset, 'other', 'price_hi:<id>', 'sent')`.
   - **Settle the row** `sent`.
3. **On failure:** the row is settled `failed`. It is never retried.
4. **When it may have been sent:** the row is settled `uncertain`, and the ledger row is recorded as may-have-been-sent,
   as `followup_send` and `VoiceAck` do, so spacing counts it. It is never sent again. The remaining risk is in §P.
5. **A colleague's typed "Hi" is never treated as automation**, however much it looks like `price_hi_text`.

**Why not a new ledger kind.** `wa_auto_sends.kind` is fixed by a CHECK (089:86-100). Rebuilding the append-only ledger
under its `was_never_deleted` and `was_identity_fixed` triggers would put the record of every automated send at risk.

**What being `other` means.**
- It is not proactive (`WaSendBudget::PROACTIVE`).
- It counts in every claimer's spacing (`:275-283`).
- It does not count in the proactive caps or `phone_today`.

**So:**
- **A "Hi" never cancels or skips another sender for the day.** It is not proactive, so it counts in neither the caps nor
  `phone_today`. Billing reminders, win-back and briefs are record-only and are never deferred.
- **It can delay one.** Every claimer on the same handset is deferred `spacing` until the "Hi" row's `claimed_at` plus
  `wa_send_gap_min_s` (default 60 s). This holds whichever customer that claimer is for. The claimers wait for their next
  pass: quote chase steps, claim-mode enquiry follow-ups (`followup_send`, up to about 300 s later), offers (released to
  `retry_at`), the staff daily list and voice acks.
- Gate 15 holds the "Hi" to spacing and the hourly cap. Gate 14 adds O1b's per-person rule and O1c's daily limit, if the
  owner chooses them.
- **Without O1c, the "Hi" is bounded only** by the window, by spacing (about one a minute per handset), by the hourly
  gate when other senders have filled the hour, and by O1. That departs from the recorded "the same sending limits as the
  reminders", so O1c asks the owner.

### C.6 Settings, screen, tool, log

| Key | Default | Notes |
|---|---|---|
| `price_hi_enabled` | off | refuses to turn on while `wa_send_ledger` is not `on` (gate 1) |
| `price_hi_text` | `Hi` | at most 160 characters; **refused if it contains a digit** |
| `price_hi_minutes` | 4 | 2–30 |
| `price_hi_max_late_minutes` | 10 | 1–60 |
| `price_hi_daily_cap` | — | only under O1c (b): the "Hi"'s own daily limit per WhatsApp number |
| period (O1), window (O2), per-person rule (O1b), daily limit (O1c) | — | the owner's decisions |

**O1c in the code's terms.**
- **Option (a)** counts, inside gate 14 only, on the handset and the Kampala `local_day`:
  - the proactive rows of `wa_auto_sends` (`proactive = 1`);
  - plus the "Hi"'s own rows (kind `other`, `ref LIKE 'price_hi:%'`).

  It skips the "Hi" once that count reaches `wa_send_daily_cap` (default 60), the ledger's per-handset daily cap for
  claimed automatic messages: quote chase steps, offers, claim-mode follow-ups. In the recorded state no enquiry reminder
  claims: they run in record mode, limited only by `followup_daily_cap` (3 per number per UTC day, §B.5). Counting the
  "Hi" against that limit instead would allow about three a day per number.
- **Option (b)** counts only the "Hi"'s own rows against `price_hi_daily_cap`.
- **Either way** the count is read in the pass, so `WaSendBudget::claim()` and every other sender are unchanged. Both
  counts read `wa_auto_sends`, which is written only while the ledger is on; that is why gate 1 requires it.

- **Where they are set:** Engage → WhatsApp → AI setup, and `tools/set_config.php` (declared in `$FLAGS`).
- **The log:** one line per decision, with the conversation id and the reason code, **never the phone**.

### C.7 How the "Hi" meets the rest

- **Enquiry ladder.**
  - The "Hi" moves `last_agent_at` only. The ladder counts from `last_customer_at`, so the "Hi" neither resets nor
    blocks it.
  - A customer who answers the "Hi" starts a new quiet spell and closes any open follow-up (CASE C,
    `cron/followup_close.php:53-58`).
  - Since the "Hi" never cancels another sender, **a customer can get a "Hi" and a same-day reminder on the same day.**
- **Quote chase and offers** are never cancelled by the "Hi". At most one spacing gap or their next pass delays them.
  With O1b, the "Hi" yields to them when they went first.
- **Part X:** a number on the "assistant off" list never gets a "Hi" (gate 9). A priced reply cannot happen there anyway:
  the assistant does not answer.
- **Quotations.**
  - A quoted customer gets no "Hi": a Part K refusal is never priced (§C.1), and gate 8 skips a live quotation at send
    time.
  - Gate 3 skips the row when a quotation is sent on the support or accounts number during the wait.

---

## F. Part S — a same-day reminder

### F.1 The schedule becomes a function

`FollowUpPolicy::schedule(array $config, ?string $dataDir): array` replaces the constant's readers:
- on Uganda with `followup_same_day_enabled` on: `[1 => N, …]`, with N = `followup_first_hours` (default 3, range
  1–12), in the shape the owner chooses (decision O3);
- otherwise: `[1 => 24, 2 => 72]`, exactly as today.

**South Sudan does not change.**

**How it reaches the readers.** `FollowUpService` is built with a PDO only, at 14 production sites. So the schedule is
passed in where it is needed, and the constructor stays as it is:

| Reader | How it gets the schedule |
|---|---|
| `open()` | from `cron/followup_scan.php:163`, passed in |
| `recordSend()` | from `cron/followup_send.php:386`, passed in, for its `nextDueAfterSend` call (`lib/FollowUpService.php:780`) |
| `cron/followup_scan.php:100` and `isFollowable` (`lib/FollowUpPolicy.php:404`) | from the config |
| `FollowUpPolicy::dueAt` | takes the schedule as a parameter. `open()` passes what it got from the scan; `nextDueAfterSend` passes what it got from `recordSend`, both for the customer anchor and in its gap lines (`:264-265`) |
| `tools/followup_doctor.php:88`, `:108` and `:113` | the same config as `:88`: the candidate query, the "qualifies N h after" message and the `isFollowable()` call |
| the training page (`tabs/help/training.php:340`) and the owner's report (`scripts/enquiry-followup-report.php:290-325`) | text and query updated |

**`nextDueAfterSend` uses the schedule passed in for both the customer anchor and the floor.**
- **The "replace" shape `[1 => N, 2 => 72]` only:** step 2 comes no earlier than 24 h after the step-1 send.
- **Everywhere else** the gap formula stays: send + (SCHEDULE[n] − SCHEDULE[n−1]).
  - With `[1 => 24, 2 => 72]` that is 48 h, checked by value on South Sudan and on Uganda with the switch off.
  - Under the "add" shape `[1 => N, 2 => 24, 3 => 72]` every step uses it: step 2 comes at send + (24 − N), and step 3
    at send + 48 h.
- **Under "add",** the per-row `max_attempts` written at `:125` is the Uganda value, because the gate and the close job
  read only the row.

### F.2 What "same day" gives in practice

- A customer who writes at 10:00 gets the reminder at about 13:00 (N = 3), once the run has judged it.
- A customer who writes after 17:00 gets it at the next opening, 08:00 the next working day. The window is unchanged.

### F.3 At most one enquiry follow-up a day per person

With S on, the run and the sender refuse a second enquiry follow-up to the same phone on the same Kampala day, whatever
the ledger mode. The row is deferred to the next opening. Without this, every reply from the customer starts a new spell,
and each spell earns its own same-day reminder.

### F.4 Capacity: the owner must raise two numbers

- **`followup_daily_cap`** is **3 per number per UTC day** (recorded live). With a same-day step, the first three
  same-day reminders use the whole day's cap. O3 asks for the new value.
- **`followup_run_limit`**: 2 every 10 minutes judges 288 follow-ups a day, each a model call.

### F.5 The drafter

- The evaluator's prompt is told when this is the same day as the customer's message, so the model never writes
  "yesterday".
- A `WAIT` verdict on a same-day step is capped at the next step's due time (`lib/FollowUpEvaluator.php:238-240`).

### F.6 Settings

| Key | Default |
|---|---|
| `followup_same_day_enabled` | off |
| `followup_first_hours` | 3 |
| `followup_daily_cap` | raised by the owner |

---

## G. Part N — name and email after N messages

### G.1 The count

The inbound rows of the conversation (`wa_messages`, `direction = 'in'`).

**Counted:**
- text rows;
- voice notes (`media_type = 'audio'`), whether the body is still `[AUDIO]` or already a transcript.

**Not counted:**
- the STOP message;
- the bare placeholders `[IMAGE]`, `[DOCUMENT]`, `[VIDEO]` and `[STICKER]`;
- a bare `[LOCATION]`; a readable pin (`[LOCATION] lat, lng …`) counts as text.

Reactions are never stored. `wa_conversations.message_count` cannot serve: it counts both directions.

### G.2 When

The conditions fall in two groups.
- **Items 1–7** are decided in `buildContext()`, before the model is asked. They set `turn.lead_ask`, which travels into
  the context as §D.4 describes, sales and legacy channels alike. The leaf controls only §G.3.2's "end without a question
  of your own" line.
- **Items 8–9** depend on the model's reply. They are checked again **after the guard**, before `lead_ask_text` is
  appended (§G.3.1).

The offer flag is never used as a stand-in for item 8. With Q on, that would switch Part N off on almost every prospect
turn.

1. **The LEAD instruction is in this turn's prompt.** One shared predicate, beside the prospect predicate, so the gate
   cannot drift from `buildSystemPrompt`:
   - `ai_lead_capture` is on;
   - `ai_qualification` is on;
   - the number sells;
   - and the prompt is not in override mode with a non-empty custom text.

   Otherwise nothing could record the answer, so the question is not asked and `lead_ask_at` is not used up.
2. **The prospect test**, as one shared predicate that `prospectRules` and Part N both use. It takes explicit inputs,
   with three exits:
   - (a) a selling number;
   - (b) no identified customer;
   - (c) not an ambiguous identity.

   **On exit (b):** the worker decides it, because only the worker holds the client id and the lead store.
   - **Where it applies:** on the sales number only, the contract path.
   - **What happens:** when the matched uCRM client is one this plugin created as a lead, the worker passes the brain a
     context **without `customer`**. That means the lead carries `crm_created_by_plugin`, the client is still `isLead`,
     and it has no service. `prospectRules` then works as for any prospect, and the sign-up posture is skipped (§H.4).
   - **Support and accounts keep today's posture** for such a client: `customer` stays present, with no prospect block.
     Their legacy context also loads `account` and `services` for an identified caller, so dropping `customer` alone
     would contradict itself. An accounts prompt would say "not identified" next to an ACCOUNT block.
3. **Something will hold the answer.** A lead already exists for the phone, or `lead_contact_enabled` is on (Part L
   creates one). Otherwise the answer could fall below the lead floor and be recorded nowhere.
4. **The count** has reached `lead_ask_after` (decision O9, default 4).
5. **No conversation of this phone** has `lead_ask_at` set, so a person is asked once, whatever number they write to.
6. **The lead for this phone lacks a real name or an email.**
7. **No live quotation** (when Part K is on).
8. **(After the guard.) The turn placed no Part Q block.** The question waits for the next eligible turn.
9. **(After the guard.) The reply is not** a `SAFE_FALLBACK` (a guard block or a guard error), a Part K refusal or floor, a
   builder refusal at reply time, or an escalation (`$ai['escalate']` set).

When items 1–7 hold, the worker sets **`turn.lead_ask`** in `buildContext()`. The question is appended only when the leaf
is set **and** items 8 and 9 hold after the guard.

### G.3 How

1. **The question.** After the guard, when `turn.lead_ask` is set and items 8–9 hold, code adds `lead_ask_text` as the
   last line of the reply. Its default follows decision **O12**:
   - **(a), recommended:** "So we can send you a written quotation, may I have your full name and email address?"
   - **(b):** "So our team can follow up with you, may I have your full name and email address?"
2. **One question only.** On that turn the both-paths helper (§E.2.1) reads `turn.lead_ask` and adds: "this turn, end
   without a question of your own; the system adds one".
3. **Marking it asked.** `lead_ask_at` is written **only when the question was actually appended**:
   - in the never-throw section after a successful send;
   - in its own try/catch, in the may-have-been-sent branch (`:290-297`), when the text sent carried it.

   Otherwise it stays empty, and the question moves to the next eligible turn.
4. **The answer** is recorded as today: `<<LEAD {name, email}>>` → `AiLeadService`. Part L makes a real name replace the
   number.
5. **What the answer turn sets off**, under today's prospect rules:
   - The model is told that a customer who wants a quotation sent by email is a hand-over. It confirms the team will
     send it, records `quote_requested` and the email in the LEAD line, and writes `<<ESCALATE>>` (or `<<QUOTE>>`).
   - The lead is captured first (`:347-394`).
   - Then the conversation becomes `needs_human`, and staff are alerted at most once per 30 minutes.
   - Enquiry follow-ups, Part S's included, are drafted but not sent automatically. No "Hi" follows.
   - The AI does not pause, and open follow-ups are not closed as human: neither happens for `needs_human`.
   - Under O12 (a) this is the intended outcome: every answer becomes a quotation for the salesperson to send. Under (b)
     the neutral wording makes the hand-over less likely, but does not prevent it.

### G.4 Settings

| Key | Default |
|---|---|
| `lead_ask_enabled` | off; **needs** `ai_lead_capture`, `ai_qualification` and a prompt not overridden by custom text; the AI setup screen, the settings tool and the deploy script's A4 check report all three |
| `lead_ask_after` | 4 |
| `lead_ask_text` | the question in §G.3 |

After the switch is on, the owner removes the NAME AND EMAIL paragraph from Extra instructions.

---

## H. Part L — leads and uCRM

**Which switch each item obeys** (Uganda only throughout):
- **only the creation of new number-only leads** (item 1's `captureContact()`): `lead_contact_enabled`, and inside it
  `AiLeadService::enabled()` as item 1 says;
- **keyed on what is stored, whatever the switch says:** item 1's O4 skip in `cron_leads` and `cron_lead_alerts`, the
  marker's clearing with `alertable_at`, and item 2's "a name equal to the phone counts as empty" all follow the stored
  `number_only` marker; item 4 follows the stored provenance. So switching L off neither releases marked leads into the
  drip and the alerts, nor hides for good a lead whose customer later gives a name or a requirement;
- items 3, 5, 6 and 7, and item 8's check of an explicit organization: `lead_contact_enabled`, inside the sync that
  `ai_crm_lead_sync` runs. With `lead_contact_enabled` off, the sync writes no `crm_created_by_plugin`, makes no
  `GET custom-attributes` and sets no new flag, so it is today's apart from item 10;
- item 9 (shown to staff): always; it only reads;
- item 10 (the cutoff): `ai_crm_lead_sync` on, whatever `lead_contact_enabled` says.

1. **A number-only lead.**
   - **When:** Part N's count is reached, §G.2's predicates 1 and 2 hold, and no lead exists for the phone.
   - **How:** `AiLeadService::captureContact()` creates one with `source 'whatsapp_online'`, `customer_name` = the phone,
     **requirement left empty**, and a separate marker field, `number_only = 1`.
     - A later LEAD requirement fills the empty field through the existing `merge()`.
     - No placeholder ever sits in `requirement`.
   - **When the marker clears.** Inside the same `withLock` merge, as soon as the stored lead holds either:
     - a `customer_name` whose `LeadMatcher::key` differs from the phone's; or
     - a non-empty requirement.
   - **Which switches it obeys:** `AiLeadService::enabled()` (`ai_lead_capture`), exactly as `capture()` does.
   - **Origin and owner:** it takes the same `withOrigin()` and `withOwner()` as `capture()`.
     - **On a salesperson's own number** it is created assigned to that line's owner, protected by
       `OwnedLead::isProtected` from creation, so the drip never sees it.
     - **On every other number** it stays unassigned.
   - **Alerts (decision O4).** Recommended: while `number_only` is set, the lead is **skipped by `cron_leads` and
     `cron_lead_alerts`**, and shown as "WhatsApp (online), number only". Each job gets a test.
   - **The alert clock restarts when the marker clears.**
     - The merge writes `alertable_at` in UTC (`UtcClock::stamp()`), and `cron_lead_alerts` reads it with
       `UtcClock::parse` and measures from **the later of `alertable_at` and `assigned_at`**, falling back to
       `created_at`. An `assigned_at` written by the spawned worker is in UTC but read as Kampala time, so it can only
       look earlier than it was, never later; the later-of rule therefore still picks the right stamp. So a later drip assignment, or the 72-hour stale reassignment
       (which re-stamps `assigned_at`), starts the sequence again on its own schedule.
     - Today the AI merge is the only writer that can give an existing lead a name or a requirement. The Sales → Leads
       edit form posts `update_lead`, which nothing handles. Any future lead editor must clear `number_only` and write
       `alertable_at` by the same rule.
     - No `wa_*` alert flag is ever set while the marker is on.
     - Without this, a lead assigned at creation on a salesperson's number would be escalated to the supervisor about 5
       minutes after the agent's first alert, because its deadline would run from the creation-time assignment.
   - **The opportunity floor:** it bypasses `whyNotQualified` on purpose, and `test_ai_lead_capture.php:46-61` is
     re-pointed deliberately.
2. **A late name.** The merge treats a `customer_name` equal to the phone (compared by `LeadMatcher::key`) as empty, so
   the first real name replaces it and the marker clears. A name a salesperson typed is never overwritten.
3. **Provenance.** Only the create branch of `UcrmLeadSync` (inside its `withLock` callback, near `:180`) writes
   `crm_created_by_plugin = 1`. `writeBack` (`:523`) does not. A lead whose `crm_client_id` came from before 5.18.107, or
   from a match by phone, has no such field, and takes the matched-by-phone path below.
4. **A synced lead stays a prospect on the sales number.** A client carrying the plugin's provenance, still `isLead` and
   with no service, does not make the caller "identified" there: the worker drops `customer` (§G.2). Support and accounts
   keep today's posture. Leads a colleague or the KYC form created keep the sign-up posture everywhere.
5. **Lead source.** After a create, `UcrmLeadSync` sets the attribute in its own `PATCH clients/{id}` call, never in the
   create payload.
   - It finds the attribute by key `leadSource` (`GET custom-attributes`, cached per pass).
   - The value is `lead_source_value` (default `WhatsApp (online)`).
   - It does this only on clients with the plugin's provenance.
   - A missing or doubled attribute is a flag on the lead, never a failed create.
   - The id is never hard-coded: attribute 1 on Uganda is the EFRIS TIN.
6. **A late name in uCRM.** For a client with provenance: `GET clients/{id}`; only while its name is still the
   placeholder, `PATCH` firstName and lastName.
7. **A late email in uCRM.**
   - **A client with provenance and no email:** the email is added to the contact, as
     `CustomerIdentityService::writeEmailToCrm` does.
   - **Any other client** (decision O5). Recommended: only when that client has **no email at all**, and only after
     `CustomerIdentity::same` confirms the match with the country check. Otherwise the lead is flagged for staff.
8. **The organization.**
   - 5.18.99's rule stays: with `ucrm_lead_organization_id` unset, the lead goes to the quoting organization, checked
     against `GET organizations`; otherwise `quote_org_missing`.
   - The only new check: **an explicit `ucrm_lead_organization_id` is validated against `GET organizations`**. If it is
     not listed, nothing is created and the lead is flagged.
   - Decision O6 asks which organization the owner wants.
9. **Visible to staff.** The lead card shows `crm_sync_flag` and `crm_sync_error`, read-only.
10. **Only leads created after the switch-on** (decision O8).
    - **The stamp's writer.** No screen saves `ai_crm_lead_sync`; its only writer is `tools/set_config.php`. A hook there,
      beside the InstallAuth one (`:618`), writes `ai_crm_lead_sync_since` (UTC) in the same `saveOverrides` call when
      the switch goes from off to on.
      - A re-save while it is already on keeps the old stamp.
      - `--clear ai_crm_lead_sync` leaves the stamp alone, and the next switch-on overwrites it.
      - The stamp is declared so that `--key` refuses it: it cannot be typed.
    - **The cutoff.** `syncLead` returns `skipped` (`created before the sync was on`, shown as `crm_sync_flag`) for any
      lead created earlier. `created_at` cannot be used: its zone is the writing process's (§B.6). So `capture()` and
      `captureContact()` also write `created_at_utc` with `UtcClock::stamp()`, and the cutoff compares that with
      `ai_crm_lead_sync_since` through `UtcClock::parse`. Only `AiReplyWorker` queues the sync (`:384`), and only for the
      leads those two write.
    - **No stamp.** On Uganda, with the switch on and the stamp missing or unreadable (an older tool, a hand-edited
      override), `syncLead` returns `skipped` (`sync start time not recorded`). That fails closed. South Sudan reads no
      stamp and skips nothing. "On Uganda" is decided the codebase's way, by `TenantProfile::current(config, dataDir)`,
      as `QuoteOrganization::setting` does.
    - **No creation time.** A lead whose `created_at_utc` is missing or unreadable is `skipped` with its own reason
      (`lead creation time not recorded`), never synced: the same fail-closed rule. Such leads predate the deploy, so
      O8 already keeps them out.
    - **So** an old lead given a late name or a LEAD merge never reaches uCRM by itself.

**Settings:**
- `lead_contact_enabled` (off; needs `ai_lead_capture`);
- `lead_source_value`;
- `ai_crm_lead_sync_since` (written by the tool, never typed).

---

## K. The owner's settings after the deploy (these parts)

Carried from docs/76 §K, steps 3–5. Steps 0–2 (X, K and Q) are 5.18.107's.

3. **H:** check that `wa_send_ledger` is `on` (the switch refuses otherwise), then `price_hi_enabled` on.
4. **N and L.**
   - Check that `ai_lead_capture` and `ai_qualification` are on, and that the prompt is not in override mode with custom
     text.
   - Then turn on `lead_ask_enabled` and `lead_contact_enabled`, and remove the NAME AND EMAIL paragraph.
   - `ai_crm_lead_sync` goes on only on the owner's word, after decision O6.
5. **S:** raise `followup_daily_cap`, then turn on `followup_same_day_enabled`.

---

## L. Tests (these parts)

Carried from docs/76 §L. New test files: `test_price_hi.php`, `test_followup_same_day.php`,
`test_lead_contact.php`. How cases are driven, and the weakened copies, as docs/76 §L says.

**H — the "Hi"**
- **What counts as priced:**
  - a flyer-only priced turn is anchored on the flyer row;
  - a failed flyer, or one held by the cooldown, is anchored on the text row;
  - a payment number, an office number or a figure the customer typed is not priced.
- **The customer moves first:**
  - two inbound messages, the second answered without a price, send no "Hi";
  - a photo stored after the priced turn's inbound row, before the anchor or in the same second, sends no "Hi";
  - a reply on another number of the phone sends no "Hi".
- **Our side moves first:**
  - a quotation sent on the support number during the wait sends no "Hi";
  - a live quotation at send time sends no "Hi".
- **The timer:**
  - two priced turns in one worker run, in different buckets, are each sent within 15 seconds of their own `due_at`
    with no master pass;
  - `price_hi_minutes` = 30 with an early spawn is accepted;
  - a worker killed by a fatal while a sleeper is alive does not make the next `run_worker` skip.
- **Claims and limits:**
  - the first "Hi" is not refused by its own claim;
  - a spacing deferral is sent on a later pass, or ended by gate 2, and never becomes uncertain;
  - a stale claim from a pass that died before the send neither becomes uncertain nor blocks a later "Hi";
  - an uncertain "Hi" is never sent twice.
- **Every silence the owner named**, each gate alone, each with a weakened copy that removes only that gate:
  - gate 5: a colleague takes over in the Inbox during the wait, with no outbound row → `person`;
  - gate 6: the AI pause is running at send time → `paused`;
  - gate 7: an `ai.reply` or `ai.voice_ack` for the phone is pending, processing, or failed with attempts left → `busy`;
  - gate 9: the number becomes a staff or colleague number after the priced turn → `staff`; the number is put on the
    "assistant off" list → `no_ai`;
  - gates 10 and 11: the policy refuses the channel → `policy`; an owned-number hold → `held`;
  - gate 12: STOP recorded for the phone before the send, on this channel or another → `skipped: opted_out`, not
    `failed`;
  - gate 13: due outside Monday to Saturday, 08:00–20:00 → `window`;
  - gate 14: a second priced turn after a sent, uncertain or claimed "Hi" inside the O1 period → `once`; with O1b,
    another automated message already sent to the phone that day → `phone_today`; with O1c, the daily limit reached →
    `daily_cap`.
- **The daily limit (O1c):**
  - under (a), the handset's proactive rows plus its earlier "Hi" rows for the Kampala day reaching
    `wa_send_daily_cap` → `daily_cap`. "Hi"s alone, with no other sender that day, also reach it. A weakened copy that
    counts only `proactive = 1` rows (so a "Hi" never counts) is caught;
  - under (b), only the "Hi" rows count, against `price_hi_daily_cap`;
  - the count reads each row's Kampala `local_day`, never its UTC date: a row written between 00:00 and 03:00 Kampala
    time, which is still the previous UTC day, counts towards that Kampala day;
  - `WaSendBudget::claim()` and the other senders are unchanged.
- **The ledger off:** with `wa_send_ledger` not `on`, the pass ends every due row `off` (gate 1) and sends nothing, and
  `price_hi_enabled` refuses to turn on. A weakened copy without the ledger check is caught.

**S — the same-day reminder**
- the one-a-day rule in record mode;
- the schedule reaching `open()` and `recordSend()`;
- **the doctor:** with same-day on, the candidate window and the printed hours both use `followup_first_hours`; with it
  off, they print 24;
- step 2 at exactly send + 48 h on South Sudan and on Uganda with the switch off;
- **the "add" shape `[1 => N, 2 => 24, 3 => 72]`:**
  - after a punctual step-1 send, step 2 is due at exactly `last_customer_at` + 24 h, which is send + (24 − N);
  - after step 2, step 3 is due at exactly send + 48 h;
  - a weakened copy in which `dueAt` reads the constant must fail;
  - a weakened copy that applies the 24-hour step-2 floor under "add" must fail;
- **the "replace" shape `[1 => N, 2 => 72]`:** step 2 comes no earlier than 24 h after the step-1 send.

**N and L — name, email and leads**
- **The count:** what it includes and leaves out.
- **No question is asked when:**
  - `ai_lead_capture` is off;
  - `ai_qualification` is off;
  - override mode with custom text;
  - L is off and no lead exists;
  - on the support number with sales-on-all off.
- **When the question is asked:**
  - a Q-and-N turn defers it;
  - a guard-blocked turn, an escalated turn, and a turn whose builder refused at reply time each carry no question and
    leave `lead_ask_at` empty;
  - a may-have-been-sent reply counts as asked only when it carried the question;
  - a second number of the same phone is not asked again;
  - one worker run carries the "no question" line only on the turn N fires;
  - each case has a weakened copy that appends on the leaf alone.
- **The answer turn:** under O12 (a), the lead is captured with the email, `needs_human` is set and one alert is sent;
  under O12 (b), the neutral wording.
- **A synced lead on the accounts number** with sales-on-all on: the prompt never holds both "not identified" and an
  ACCOUNT block.
- **Leads and uCRM:**
  - a synced lead stays a prospect, while a KYC-created lead keeps the posture;
  - a linked (not created) client receiving a late email takes the O5 path;
  - a number-only lead on a salesperson's number is assigned and protected; on a central number it stays unassigned
    until the marker clears;
  - an old lead after switch-on is not synced;
  - an explicit organization that is not listed is refused.
- **The number-only lead's fields:**
  - a number-only lead, then a LEAD with a requirement: the stored requirement is the model's, and the marker clears;
  - a number-only lead, then a LEAD with only a location: the marker stays, and no alert goes;
  - a weakened copy that seeds a placeholder requirement fails the first case.
- **The alert clock:**
  - a number-only lead on a salesperson's number gets its name hours later. Three runs of `cron_lead_alerts`, at the
    clear time, then 45 and 60 minutes later, give the agent's alert, then the warning, then the escalation;
  - a number-only lead on a central number clears, and the drip assigns it hours later; and a lead whose marker cleared
    is later reassigned by the 72-hour stale rule. In both, the runs are timed from the assignment or reassignment:
    - at it: the agent's alert;
    - 5 minutes later: nothing (no warning, no escalation);
    - 45 minutes after: the warning;
    - 60 minutes after: the escalation;
  - a weakened copy that keeps the creation-time clock is caught; one that reads `alertable_at` ahead of a later
    `assigned_at` is caught at the 5-minute run, where it escalates.
- **L switched off:** with L on, a number-only lead is created on a salesperson's number and another on a central
  number; then L goes off.
  - runs of `cron_leads` and `cron_lead_alerts` give no drip and no alert;
  - a LEAD with a requirement, or a name alone, then clears the marker and writes `alertable_at`;
  - the sequence that follows is the agent's alert, nothing at +5 minutes, the warning at +45 and the escalation at +60,
    timed from the clear or from the later drip assignment;
  - a weakened copy that gates the skip on the switch is caught at the +5-minute run, by the escalation.
- **The sync cutoff:**
  - off → on writes the stamp, and a re-save keeps it;
  - a missing stamp on Uganda gives `skipped`, and nothing goes to uCRM;
  - a lead with no `created_at_utc`, or an unreadable one, gives `skipped` (`lead creation time not recorded`);
  - the worker on UTC, the install on Africa/Kampala, and a lead created seconds after the stamp: synced, both when the
    sync runs in the same process and when it runs under the Kampala zone. A weakened copy that converts `created_at`
    from the install's zone is caught;
  - South Sudan is unchanged.
- **Which switch:** J8 under `ug_off` and `ug_off_word`, given the stamp and with `lead_contact_enabled` off, is still
  ce7ac388's byte for byte (no `crm_created_by_plugin`, no `GET custom-attributes`, no flag). A §D case with
  `lead_contact_enabled` on: provenance is written, Lead source is PATCHed when the fake serves `leadSource`, and a
  missing attribute sets the flag.

**Deliberate re-points for these parts**, each with its reason written in the test (carried from docs/76 §L):
- `test_ai_lead_capture.php:46-61`;
- `test_ucrm_lead_sync.php` §3;
- **the sync cutoff's re-points.** Each existing case that switches the sync on with no stamp **on a Uganda tenant**
  (`TenantProfile::current`) is given a stamp, with the reason written in the test:
  - `test_quote_company.php` §D, its Uganda cases (`qc_book('uganda')`), so that the `quote_org_missing` cases still
    reach their own reason. Its seeded lead (`:1239`) also gets a `created_at_utc` later than the stamp;
  - `tests/fixtures/quote_company_journey.php` J8, scenarios `ug_off` and `ug_off_word`. The stamp, and a
    `created_at_utc` on the seeded lead (`:598`), are given on both trees that §S compares byte for byte (ce7ac388's
    ignores both), so their `leads` tables stay equal and, with `lead_contact_enabled` off (§H), the comparison still
    holds;
  - `test_multi_number_routing.php` check 20 (two clients created, both leads linked). Its leads are created by the real
    worker, which writes `created_at_utc`, so only a stamp from before that run is needed, whatever zone
    `mn_open_zone` picks.
- `test_master_104` (job list and order) and `tools/cron_status.php`;
- the cadence tests (Uganda only);

---

## M. Build order (these parts)

Carried from docs/76 §M, steps 4–6.

4. **H:** migration 092, the record, the sleepers and the job, the gates, the send.
5. **N and L:** the column (092), the count, the predicates, the question, the number-only lead, the provenance, the
   uCRM paths, the sync cutoff.
6. **S:** the schedule function and its readers. Last, because it waits on decision O3.

---

## N. Production values to read before the build (these parts)

From docs/76 §N, the values only these parts need; read again when 5.18.108 is designed:

- **Leads:** `ai_lead_capture`, `ai_qualification`, `ucrm_lead_organization_id`, `lead_alert_enabled`,
  `lead_auto_assign_enabled`, `lead_drip_size`, `lead_assignee_ids`, and the number of active sales agents.
- **Follow-ups:** `followup_daily_cap`, `followup_run_limit`, `followup_auto_send`, `followup_enabled`; and for the
  "Hi", `wa_send_ledger`, `wa_send_daily_cap`, `wa_send_hourly_cap`, `wa_send_gap_min_s`, `wa_human_cooldown_minutes`.
- **The server:** whether a per-minute crontab runs master.
- **uCRM:** the organizations; the client attribute with key `leadSource` (its id, expected 8).
- **Volumes:** conversations per day reaching 4 inbound messages; replies per day that §C.1 would call priced.

---

## O. Decisions for the owner (these parts; open)

Carried from docs/76 §O.

| | Question | Recommended |
|---|---|---|
| **O1** | "Hi" at most once per conversation. As recorded this means **ever**, since a conversation is one row per number and channel. Or **once a day**? | the recorded choice, **ever**, unless a returning customer should get another "Hi" on another day |
| **O1b** | Should the "Hi" be skipped when another automated message (reminder, quote chase step, offer, enquiry follow-up) already went to the customer that day? Whatever you choose, a "Hi" never stops a later reminder, chase step or offer that day. | **no**: the "Hi" follows a live chat within minutes, and it stays bound by spacing |
| **O1c** | Should the "Hi" have a daily limit per WhatsApp number? Without one, it is bounded only by the hours (O2), spacing (about one a minute, so about 60 an hour per number) and O1. The hourly cap holds a "Hi" only when other automatic messages (quote chase steps, offers, claim-mode follow-ups) have already filled the hour; "Hi"s never count towards it. The options: (a) the sending ledger's daily limit per number (`wa_send_daily_cap`, default 60 a day), shared with quote chase steps and offers, the "Hi"s counting towards it; (b) a separate daily limit for the "Hi" alone; (c) no daily limit. Today's enquiry reminders do not use (a): their limit is 3 per number per day (`followup_daily_cap`), which would allow only about three "Hi"s a day. | **(a)**: the ledger already spaces the "Hi"; its daily limit adds the only count that includes the "Hi"s |
| **O2** | "Hi" hours | **the reminders' hours**, Monday to Saturday 08:00–20:00 (as recorded). Outside them it is skipped, not kept for the morning. |
| **O3** | Same-day reminder: **replace** the 24-hour reminder (same day + 3 days, two reminders) or **add** one (same day + next day + 3 days, three)? After how many hours? The new daily limit per number (today 3)? | replace; after 3 hours; a daily limit set from the volume in §N |
| **O4** | A number-only lead: (a) assigned like any lead, with the agent's alert, the warning and the supervisor escalation; (b) in Sales → Leads with no alerts until a name or a requirement arrives (assigned at once only on a salesperson's own number, as today); (c) only in uCRM | **(b)** |
| **O5** | A late email for a client already in uCRM, matched by phone | only if that client has no email at all; otherwise flag it for staff |
| **O6** | Which uCRM organization do leads go to? Today, with no setting, they go to the quoting organization (company 1, the 5.18.99 rule). Keep that, or name another? | keep the quoting organization unless you say otherwise |
| **O8** | Leads captured before the uCRM sync is switched on: send them? | no: only leads created after the switch-on (enforced by a recorded time) |
| **O9** | Ask for name and email after how many customer messages? | 4 |
| **O12** | The name-and-email question. (a) "So we can send you a written quotation, may I have your full name and email address?": when the customer answers, the assistant hands the chat to the salesperson to send a quotation (alert, chat marked as needing a reply, no automatic follow-ups after it). (b) "So our team can follow up with you, …": the hand-over is less likely, but can still happen. | **(a)**: it is what you asked the question for ("so we can send them quotation") |

---

## P. Known limits (these parts)

Carried from docs/76 §P.

- **The timer.** The "Hi" arrives 4 minutes, plus at most 15 seconds, after the reply while its sleeper survives.
  Otherwise it arrives at master's next cycle, about 5 minutes later, or never if that is past the lateness limit.
- **An uncertain "Hi"** has no message id, so its echo cannot be claimed. If it was delivered:
  - it is stored as "Team";
  - it pauses the AI for the cooldown;
  - through F-3, it keeps the ladder off that conversation.

  The pass counts these in its log line (docs/73 §H.4.21).
- **The text-only classifier.** What the flyer image shows is never read. A reply that only refers back to an earlier
  flyer, with no amount, is not priced.
- **A same-day reminder for a message after 17:00** goes out the next morning.
