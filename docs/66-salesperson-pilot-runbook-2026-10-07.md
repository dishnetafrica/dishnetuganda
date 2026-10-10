# 66 — The salesperson pilot: one number, on its own, end to end (runbook, 07 Oct 2026)

**What this is.** The operator's procedure for the first salesperson's own WhatsApp number on production. **Only step
1.1 (Add) and the card's Assistant off have been run, on 07 Oct** (the second correction below). Every step is the
operator's, in this order, and each one is verified before the next.

**Corrected 08 Oct (5.18.90, docs/65 §AD) — the pilot was stopped before step 5.** Two defects were found in 5.18.89,
the release this runbook was first written for:
- **one DishNet number's assistant could answer another's** once the human pause (5 minutes in production) had run out —
  the plugin did not know DishNet's own numbers;
- **follow-ups ignored the number's assistant switch** — with the assistant off, an automatic follow-up could still leave
  from the salesperson's number.

**Corrected 08 Oct (5.18.90's release preparation) — what production already holds.** As recorded on 07 Oct, 21:38–21:47
UTC, and not read again since: step 1.1 was done at 20:54 UTC — `sales-001` exists, instance [PILOT_INSTANCE], owner [the
salesperson] — and at 21:40 its assistant was switched off on the card. Nothing else was done: no QR code, no
verification, no webhook, the registry not switched on. **`sales-001` is never added again:** step 1 continues at 1.2,
and the checks below expect it.

**5.18.90 or later must be live before step 1.** It is its own release, push and deploy, each approved separately; none
of that is in this runbook. Everything below is then a setting, a card on the WhatsApp AI screen, or a read-only check.
*(Corrected 10 Oct: 5.18.91 is live since 10 Oct 02:53 UTC — the database safety fix, which changes nothing in this
runbook — and 5.18.92, the scheduler's lock fix, is being prepared; neither changes a step below.)*

The design is docs/65 §AA (and §AB, as built); the decisions taken on 07 Oct evening and the acceptance test are §AC.

**Names.** Documents never name the salesperson or print their number:

| Placeholder | Meaning |
|---|---|
| **[the salesperson]** | the pilot salesperson the operator chose on 07 Oct |
| **[PILOT_INSTANCE]** | the Evolution instance that will carry their number |
| **sales-001** | the channel id the card gave the number on 07 Oct (step 1.1, done). P3 confirms it. |
| **••••NN** | the last two digits of the number, as the card and `tools/channels.php` show it |
| **the test phone** | a phone used to play the customer. WhatsApp from these is kept for the team and never answered, so the test phone must be **none** of them: an active admin, support or accounts staff member's number on record (5.18.50); any DishNet WhatsApp number — sales, support, account, or a salesperson's number; the phone on record of a salesperson who owns a number; the alert numbers (`alert_whatsapp`, `whatsapp_admin_phone`) (5.18.90). A sales colleague who owns no number works. |

Commands run on the server as root. Each command is in its own block. The rollback is in its own section, R, and is
never combined with a step.

---

## 0. Before anything: the operator confirms (Phase 8)

Nothing is paired until all six are confirmed, and written down by the operator outside this repository:

1. **The SIM and number** — the number that will be [the salesperson]'s line, and that the SIM is in their phone.
   - **Existing or new?** If it is [the salesperson]'s *existing* WhatsApp number, every customer already chatting with
     it will be answered by the assistant from step 5 on. Step 4 (assistant off) shows that traffic first.
   - **WhatsApp Business greeting or away messages** on that phone: switch them off for the pilot. Each one reads as
     [the salesperson] typing and stands the assistant down on that chat. The plugin recognises such a text as automatic
     only once the same words have reached three other chats within seven days, and never if it is shorter than 40
     characters.
2. **The salesperson** — their staff record is active, its role is `sales`, `sales_staff` or `field_agent` (the card
   offers no one else), and it carries their phone. Without a phone the hand-over alert goes to the central number
   alone.
3. **The channel record** — created by step 1, switched off, owner [the salesperson].
4. **The instance name** — [PILOT_INSTANCE] exists in Evolution and is none of the three department numbers' instances
   (the card refuses those).
5. **The ownership** — [the salesperson] owns sales-001 on the card.
6. **The rollback** — section R, read before step 1.

## P. Read-only checks (before step 1; repeat after each step)

**P1. The release.** Expect the release that is live: `5.18.91` since 10 Oct (`5.18.90` when this was written), or
`5.18.92` once it is deployed.

```
docker exec ucrm php -r 'echo json_decode((string)file_get_contents("/data/ucrm/data/plugins/dishnet-hybrid-sudan/manifest.json"), true)["information"]["version"] ?? "?", "\n";'
```

**P2. The switches, and who can read the Inbox.** This prints five settings and nothing else, from both places the
plugin reads them: the configuration files, and the store row the web pages read. It opens the database read-only and
writes nothing. **Do not use `tools/set_config.php` with no arguments for this**: its full listing prints payment
settings, which must not be pasted anywhere.

```
docker exec ucrm php -r 'chdir("/data/ucrm/data/plugins/dishnet-hybrid-sudan"); require "lib/bootstrap_data.php"; require "lib/PluginConfig.php"; $d = cliDataDir(getcwd()); $f = PluginConfig::read(getcwd(), $d); try { $p = new PDO("sqlite:" . $d . "/plugin.sqlite3", null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]); $s = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); } catch (Throwable $e) { $s = null; } foreach (["multi_number_channels_enabled", "sales_own_leads_only", "wa_followups_on_owned_numbers", "wa_handover_copy_central", "wa_inbox_roles"] as $k) printf("%-31s files: %-16s store: %s\n", $k, json_encode($f[$k] ?? null), is_array($s) ? json_encode($s[$k] ?? null) : "unreadable");'
```

Expect, before step 1:
- all four switches `null` — not set;
- **`wa_inbox_roles` `null`, or naming no sales role.** The Inbox is not filtered by number. A role granted it reads
  every number's chats, so a sales role granted it would let one salesperson read another's customers — a STOP
  condition (Phase 12). Measured: `tests/test_sales_pilot.php`, L.

**P3. The registry.**

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/channels.php
```

Expect: the switch OFF, not in effect, installed; the three department rows — `sales`, `support`, `account` — each
routed as configured, with support and account sharing their instance as today; and `sales-001` (step 1.1, 07 Oct):
status `disabled`, `ai=off`, instance [PILOT_INSTANCE], no number. No other `sales-NNN` row. The last line reads
`department numbers: NOT all verified — sales, support` until step 1.5, and `department numbers: all verified for their
instances` after it.

**P4. The instance.** On the WhatsApp AI screen, *Found in Evolution* lists [PILOT_INSTANCE], marked as in use by
`sales-001`.
Then:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/channels.php --resolve [PILOT_INSTANCE]
```

Expect `UNKNOWN`: with the registry off the plugin stores nothing that arrives on it.

---

## 1. The number, on the card — the registry still OFF

WhatsApp AI screen → **Salesperson numbers**. Every action asks for a reason; give one.

1. **Add** — **done on 07 Oct; never again.** It was: instance [PILOT_INSTANCE], owner [the salesperson], display name
   as offered, and the card answered *"Added sales-001 for … It is switched off: pair it, verify its number and register
   its webhook, then switch it on."* A second Add would make another channel. Continue at 2, for `sales-001`.
2. **Show QR code** — [the salesperson] scans it on their phone: WhatsApp → Linked devices → Link a device.
3. **Verify number** — expect *"Verified: … the number ending ••••NN, as Evolution reports it."* **The two digits must be
   the confirmed number's.** If they are not, the wrong phone was paired: STOP, and unlink that device in WhatsApp. The
   same if it answers *"that number is already channel …'s"*: the phone of another DishNet number was paired (one phone
   can be linked to several instances). If it answers *"Evolution reports no phone number for this instance yet"*, press
   it again after a minute; if the answer stays, STOP. If it answers *"… owner with no phone number (a WhatsApp @lid),
   so it cannot be verified"*, STOP: nothing can be verified on that instance.
4. **Register webhook** — expect *"Evolution will now send the messages of … to this plugin."*

5. **The department numbers — Verify number** on the **sales** row, then on the **support** row (5.18.90). Each reads the
   number Evolution reports for that department's configured instance; nothing is typed. Expect *"Verified: the sales
   number is the one ending ••••NN, as Evolution reports it for instance …"*, and the same for support. Any other answer
   — *"that number is already channel …'s"*, *"… owner with no phone number (a WhatsApp @lid) …"*, or *"Evolution reports
   no phone number for this instance yet"* that stays after a minute — is a STOP: the department stays unverified, and no
   salesperson number can be switched on. The **account**
   row shares support's instance: it shows *"It shares the support number's instance, so that number covers it"* and has
   no button. Until both are verified the card says **Verify the department numbers first**, no salesperson number can be
   switched on, and step 2's command refuses. **Why:** without them the pilot number could not tell a message from a
   department's WhatsApp from a customer's, and would answer it. **Verify again** after any department number is given
   another instance (the screen says so at once) or its phone is paired to another number. Until then the pilot number
   answers and sends nothing:
   - at once, for a new instance;
   - within ten minutes, for a re-paired phone — once the webhook guard next reads Evolution's report. The guard reads
     nothing while the registry is off: a phone re-paired then is recognised only within ten minutes of switching the
     registry back on — which is why **Back on** (section R) verifies the department numbers first.

   The same holds for the pilot number itself. After **Show QR code** pairs it again, press **Verify number** again:
   until then it is unverified and answers nobody.

Check with P3: `sales-001`, status `disabled`, instance [PILOT_INSTANCE], owner `staff #<their id>`, number `••••NN`;
the last line `department numbers: all verified for their instances`.
The trail:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/channels.php --trail sales-001
```

Expect three rows: `created` and `ai_enabled` (off) from 07 Oct, then `number`, each with the admin's name.
`--trail sales` and `--trail support` each show a
`number` row and a `verified_instance` row.

**What customers see:** nothing new. Messages reach [the salesperson]'s phone as always. The plugin answers
`unknown_instance` and stores nothing.

## 2. The registry ON — the three department numbers alone

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key multi_number_channels_enabled --value 1
```

If it answers *"Not yet: the department numbers are not all verified"*, nothing was saved: do step 1.5 first.

Check with P2 (`multi_number_channels_enabled` `"1"`) and P3:
- the switch ON, in effect;
- `sales`, `support`, `account` routed exactly as before;
- `sales-001` refused (disabled).

**Test the departments** from the test phone:
- a message to the **sales** number, and one to the **support** number: each reply comes from the number written to;
- an admin's Inbox reply on each chat arrives from that same number.

Then watch the departments' traffic for as long as the operator decides (docs/65 §Q, phases 3–4). The acceptance test
found sales, support and account identical in every observable with the registry off, on alone, and on with the pilot:
the webhook, the assistant's context and prompt, the reply's number, the Inbox, the stand-down and the hand-over.

**The pilot number meanwhile:** still nothing stored (`channel_disabled`).

## 3. The two decided switches

**Own leads only** — before the number goes live (decided 07 Oct). **Tell the sales team first:** from this moment each
salesperson sees only their own leads — assigned to them, created by them, or on their call list today. Admins and
holders of *All Leads* see every lead.

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key sales_own_leads_only --value 1
```

Check: Sales → Leads, signed in as any salesperson, lists only theirs; as a manager or admin, every lead.

**Follow-ups from the salesperson's number** (decided 07 Oct). It acts only on salesperson numbers, and only with the
registry on. Since 5.18.90 a follow-up also leaves only while that number's **assistant is on**: with the assistant off
none is opened, drafted, approved or sent, whatever `followup_auto_send` says.

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key wa_followups_on_owned_numbers --value 1
```

Check with P2: both `"1"`.

## 4. The pilot number ON — the assistant OFF first

On the card, for sales-001:
1. **Assistant off** — already off since 07 Oct: P3 must read `ai=off`. Only if it does not, press it, and expect *"The
   assistant no longer answers on … messages are kept."*
2. **Switch on** — refused unless the registry is on, the number verified, its owner active and the department numbers
   verified (step 1.5). Expect *"… is switched on."*

Check:
- P3: `sales-001`, `active`, `ai=off`, routed;
- `--resolve [PILOT_INSTANCE]` → `channel sales-001 (through the registry)`.

**Test** from the test phone:
- message the pilot number. The chat appears in the Inbox as `sales-001`, and no assistant reply comes.
- an admin replies in the Inbox. **The reply must arrive from the pilot number**, not from sales or support.

If this is [the salesperson]'s existing number, customers' messages now appear in the Inbox too, unanswered by the
assistant. [The salesperson] answers on their phone as always.

## 5. The assistant ON

On the card, for sales-001: **Assistant on**.

**Test** from the test phone, each one checked before the next:
1. **A product question** — the reply comes **from the pilot number**, introduced as [the salesperson]'s assistant at
   DishNet. It never claims to be them.
2. **A quotation request** — Sales → Leads (as an admin): a new lead assigned to [the salesperson], channel sales-001.
3. **"Can I talk to a person?"** — the chat is marked as needing a person. [The salesperson]'s phone gets an alert
   **from the DishNet sales number**, and the central alert number a copy.
4. **[The salesperson] replies from their own phone** — the reply shows in the Inbox as the team's. The assistant stands
   down on that chat for the cooldown (`wa_human_cooldown_minutes`).
5. **The AI-to-AI loop test — mandatory (5.18.90).** Two messages, each from a DishNet number's own WhatsApp — a person
   typing on that number's phone or WhatsApp Web, never the plugin:
   - from the **sales department's** WhatsApp, to the pilot number: *"Hello, how much is the Standard kit?"*;
   - from the **pilot number's** WhatsApp ([the salesperson]'s phone), to the **sales department's** number: the same
     question.

   Then wait **ten minutes** — twice the human pause — and look at both phones. **Expect no reply on either.** In the
   Inbox both chats are there, unanswered, filed `staff`: the pilot number's chat with the sales number under `sales-001`,
   and the sales number's chat with the pilot number under `sales`. **Any reply from either number is a STOP condition.**
   Do this test again after every new salesperson number.

## 6. After activation (Phase 10)

| What | Where it shows | Expect |
|---|---|---|
| inbound message | the Inbox; `channels.php --resolve [PILOT_INSTANCE]` | the chat under `sales-001` |
| AI response | the test phone | from the pilot number |
| outbound sender | the test phone | the pilot number — never sales or support |
| Inbox response | the test phone | from the pilot number |
| hand-over | [the salesperson]'s phone; the central number; the Inbox | an alert from the DishNet sales number to both; the chat waiting for a person |
| follow-up | the follow-up queue, once a chat on the number has gone quiet for the policy's period | an approved one sent from the pilot number. It cannot be tested at once. |
| lead creation, ownership | Sales → Leads | [the salesperson]'s, channel `sales-001` |
| channel audit | `channels.php --trail sales-001` | created, ai_enabled (off — 07 Oct), number, status (active), ai_enabled (on) — each with the admin's name |
| DishNet numbers | step 5's loop test; the Inbox | a message from any DishNet number — a department's, a salesperson's, an owner's phone, the alert number — kept for the team, filed `staff`, never answered |
| assistant off | the card's **Assistant off**; the follow-up queue | no assistant reply, no typing indicator, no follow-up opened, drafted, approved or sent — an approved one is closed `channel_assistant_disabled`; a person's Inbox reply still leaves from the pilot number |
| no cross-visibility | Sales → Leads as another salesperson | none of [the salesperson]'s leads |

**When the number fails:**
- **A failed send is never sent from another number.** The reply is retried on the pilot number five times, over about
  forty minutes. Then a person is told — [the salesperson] and the central number — and the customer is sent nothing
  from any other number.
- **A send that may have gone** (no answer from WhatsApp) is never repeated; a person is told at once.
- **Disconnection:** the webhook guard notices within about ten minutes and alerts the central number, naming
  `sales-001`. [The salesperson] is not told (docs/65 §AB.2).
- **Lead alerts:** a salesperson who answers on WhatsApp without logging a call triggers the lead alerts' 60-minute
  escalation to the supervisor (docs/65 §AB.1 item 4).

## STOP conditions (Phase 12)

Stop at once, and roll back by section R, if any of these is seen:
- **any DishNet internal number causes an AI reply** — a department's number, a salesperson's number, a salesperson's own
  phone on record, or the alert number receives an assistant's reply, a typing indicator, a follow-up or a hand-over line
  from any DishNet number (5.18.90; step 5's loop test);
- **the assistant OFF still permits any automated outbound message** from that number — an assistant reply, a typing
  indicator, a photo or document, a follow-up (5.18.90);
- P3 shows `department numbers: NOT all verified` while a salesperson number is switched on, or the card says
  **Evolution now reports another number for this instance** or **… this instance's owner with no phone number**;
- a reply, an Inbox message, a hand-over line or a follow-up for a chat on the pilot number arrives from **any other
  number**;
- a salesperson can see another salesperson's leads, or P2 shows a sales role in `wa_inbox_roles`;
- P3 shows the pilot instance mapped to more than one channel, or `sales-001` *NOT ROUTED*;
- any change in how sales, support or account receive or reply;
- the verified ••••NN is not the confirmed number's;
- anything about the pilot that this runbook does not describe.

---

## R. Rollback — each level on its own, smallest first

Each level is complete by itself. Stop at the first that ends the problem. None deletes a channel, a conversation, a lead
or a trail row; the database refuses to delete a channel at all.

**R-1. The assistant only.** On the card, for sales-001: **Assistant off**. *(Corrected 08 Oct: in 5.18.89 an automatic
follow-up could still leave from the number after this; since 5.18.90 it cannot.)* From then on:
- the assistant answers nobody on that number: no reply, no typing indicator, no photo or document, no hand-over line;
- **automatic follow-ups stop too**: none is opened or drafted, a due one is closed before any model call, an approved
  one — automatic or a person's — is closed `channel_assistant_disabled`, never sent;
- messages are stored, and a person's Inbox reply still leaves from the pilot number.

The number itself stays on: customers' messages are still received and kept. **R-2 is the stronger shutdown.**

**R-2. The pilot number OFF** — the stronger shutdown. On the card, for sales-001: **Switch off**. From then on:
- the plugin neither stores nor sends anything on that number;
- a message already queued for it is never answered from any number, and a person is told;
- the Inbox refuses to send on it, and says so;
- an approved follow-up for it is closed, never sent.

Customers reach [the salesperson]'s phone directly, as before the pilot. Conversations, leads, the channel row and its
trail are kept: the trail gains one row. Sales, support and account are unaffected.

**R-3. The registry OFF.**

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key multi_number_channels_enabled --clear
```

Every number routes as it did before the pilot: the three departments as configured. The pilot instance is unknown
again, and nothing is stored or sent for it. Nothing is deleted, and the trail is unchanged.

**R-4. The two other switches** — only if the team's lead pages, and follow-ups, should be as they were before the
pilot. Each command in its own block:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key sales_own_leads_only --clear
```

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key wa_followups_on_owned_numbers --clear
```

After any level, repeat P2 and P3.

**Never:**
- delete or edit `wa_channels` by hand;
- unpair the phone as a rollback. It is not needed, and the number would have to be paired and verified again.

**Back on** after R-2 or R-3: undo the level — the switch back on (step 2's command), then **Switch on** on the card —
and repeat steps 4 and 5's tests. The acceptance test proves the number answers on its own number again (R4).
After R-3 (the registry was off), first press **Verify number** on the **sales** row, then the **support** row, and check
that no row on the card says *"Evolution now reports another number"* or *"… no phone number"*: the webhook guard records
nothing while the registry is off, and a verification records what Evolution reports now, for every instance. A pilot
number whose phone was re-paired meanwhile is refused by **Switch on** until it is verified again.

---

## Salesperson #2, #3, …

Only after the pilot has run as long as the operator decides, with no STOP condition, and on the operator's go.

For each number, **one at a time**:
1. section 0's confirmations;
2. step 1 (the card: add, QR, verify, webhook);
3. step 4 (on, assistant off; the Inbox test);
4. step 5 (assistant on; the four tests);
5. section 6.

The registry and the two switches are already on and are not touched again. Each number takes the next id (`sales-002`,
…). No code change and no deploy are needed. **Repeat step 5's loop test with each new number** — between it and the
pilot number, and between it and the sales number. Each new number and its owner's phone become DishNet numbers the
moment the number is verified and given its owner: the other numbers stop answering them. Two things to weigh as numbers
are added:
- **WhatsApp's ban risk** grows with every automated send from a person's number;
- **`wa_followups_on_owned_numbers` applies to every salesperson number at once**, not number by number.

## What the acceptance test proves, and what it cannot

`dishnet-hybrid-sudan/tests/test_sales_pilot.php` runs this whole sequence against the real plugin, with a fake Evolution
and a fake uCRM; docs/65 §AC has the detail. Since 5.18.90 it includes step 1.5, and
`dishnet-hybrid-sudan/tests/test_pilot_safety.php` adds the safety fix's tests 1–25 — DishNet numbers never answered,
message ids shared and fresh, the assistant off stopping follow-ups, refusals that never fall back — with weakened copies
of every new guard (docs/65 §AD). Neither can prove four things, which only the live steps above can:
- that the SIM is the right one;
- that Evolution pairs and stays connected;
- that WhatsApp delivers;
- how customers respond.
