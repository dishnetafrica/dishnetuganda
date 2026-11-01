# 72 — Uganda: one release for sales — the catalogue straight from uCRM, an AI that hears, sees and reads, quotes chased, a daily list and a read-only report (design, 1 Nov 2026)

Status: **decisions recorded; design in progress; nothing built, nothing deployed.** The base is the live release,
`release/5.18.102` (`1a0ea5f`), never the development branch's tip: that tip lacks 5.18.99–5.18.102 (§C.4).

## A. The request (the owner, 1 Nov 2026)

- *"remove all the hardware and internet plans for plugin we have and use what is in the ucrm make it simple"*, and
  *"show all the things of hardware … in plugin as well same like website and give standard one click predefined
  quote"*. Linking each plugin row to a uCRM item (5.18.102, D14) proved hard to do by hand.
- *"make our ai smart enough that it can handle voice message send by the customer and location they are sending and
  documents if they sent"*, with South Sudan's n8n bot as a reference only (*"ugadna one is best"*).
- *"auto follow … otherwise our all advert money is in lost … how many people we send quote but we never followed
  up"*: measure, across every number connected to the sales and accounts AI, the quotes that were never followed up,
  and plan the follow-up as a sales expert would.
- *"send marketing message to all the customer in ucrm … follow up on email as well … increase our follow up and after
  sales relation with customers"*, as Starlink does for its customers.

## B. Decisions (the owner, 1 Nov 2026, each chosen from the options put to them)

| # | Decision |
|---|---|
| D1 | **Plans and hardware come from uCRM**, not from the plugin's own lists, for the KYC form and its quotation; a standard quotation can be made in one click (its contents: §E, open) |
| D2 | **The AI service for voice notes, photos and documents is OpenAI**, as in South Sudan: Whisper for voice, GPT-4o for images and PDFs. The customer's voice notes, photos and documents are sent to OpenAI to be read |
| D3 | **A quote with no reply is chased on day 1, day 3 and day 7**, from the number the customer spoke to, 08:00–20:00 Kampala, never on Sunday, stopping on any reply, a payment, STOP or a person taking over; each salesperson also gets a daily list of open quotes |
| D4 | **One release, switched on step by step**: every new part ships OFF and is turned on one at a time, each with its own check |
| D5 | **Until then, 5.18.102 stays live** and the admin links its 5 plans (`docs/07`, "5.18.102 DEPLOYED") |

Advice given and not yet decided (§E): e-mail broadcasts through uCRM's own Tools → Messaging, which sends e-mail (and
SMS only where a gateway is set), never WhatsApp; WhatsApp marketing to all customers only through the official
WhatsApp Business Platform, with approved templates and opt-out — never mass-sent from the Evolution numbers, which
WhatsApp may ban.

## C. What exists today (read-only discovery, 1 Nov 2026)

### C.1 South Sudan's n8n bot (`DishNet_AI_Bot_v3.9`, 140 nodes; read redacted, never copied into this repository)

- **Answers live, and well:** voice notes transcribed (OpenAI, default model), images and documents read (GPT-4o),
  location pins taken, several quick messages queued and answered as one (Redis and a short wait), human takeover
  pauses it, HOT-lead and hand-off alerts to staff, a staff watchdog every 15 minutes, an 18:00 digest, a QA judge.
- **It sends no follow-up to a customer.** Its "follow-up" is a staff alert about unanswered chats; a lead's
  `next_followup_at` is written and never read.
- **Not to be copied:** an unauthenticated webhook that takes the instance, server and key from the request body
  (forged sends); a key inline in a tool URL and an Evolution key copied in plaintext; customers matched by the last
  nine digits of a phone number; no opt-out; and wiring that leaves its digest, QA judge and alert dedupe probably
  inert.

### C.2 Uganda, live (5.18.102)

- **Location pins** are taken and given to the AI (country bounds only; no town or area).
- **The AI worker and brain** answer text, with prices read live from uCRM.
- **A follow-up engine** (migration 070; scan, run, send, close; FollowUpPolicy 08:00–20:00, 24 h and 72 h after the
  customer's last message, opt-out, AutomationPolicy per number). Its switches on the server are not established
  here. **It never chases a quote:** nothing links the quote records to it, and a quote sent from the notification
  number, or to a KYC customer who never wrote on WhatsApp, is never followed up.
- **Quotes** are sent by `cron_quote_wa.php` and the webhook, claimed once (`wa_sent_quotes`) and logged
  (`quotes_log.json`), with no field for a reply, an acceptance or a chase.
- **A voice note, a photo without a caption and a document get no answer at all**: stored as `[AUDIO]`, `[IMAGE]`,
  `[DOCUMENT]`, the AI not queued; staff learn of it from the watchdog after 10 minutes, 07:00–21:00.
- **No batching**: each quick message gets its own AI turn.

### C.3 Uganda, built and dark (the development branch only)

The AI communication layer of 4–5 Oct (`docs/07`; root `docs/55`–`58`): the media foundation (`wa_media`,
`InboundMedia`, `MediaWorker`), voice (`VoiceTranscription`), images (`ImageUnderstanding`, payment screenshots always to
a person), documents and PDF text (`DocumentExtraction`, `PdfReader`, a four-rung activation ladder), each behind
`ai_media_*` switches that default off, **with no provider adapter**: only `none` or `fake`. Never released.

### C.4 The base

The development tip and the live release diverged after 5.18.74. The release carries 5.18.99–5.18.102 (the quoting
organization, the uCRM resolver and links); the tip carries the dark media layer. **This release is cut on
`release/5.18.102` and ports what it takes from the development branch onto it**, file by file, as 5.18.88 did for
Batch 1.

## D. Scope of the release (to be designed in §F; each part OFF until switched on)

1. **A read-only report** (first, and usable before the release): per number — sales, accounts, support, each
   salesperson — over 30 and 90 days, counts only: conversations, quotes sent, quotes the customer answered, quotes
   chased, quotes that became a registration or a payment. No name, phone or figure in its output.
2. **The catalogue from uCRM (D1):** the KYC form offers uCRM's own service plans and products; no plugin row, no link
   to make; a standard quotation in one click.
3. **Voice, photos, documents (D2):** the dark layer ported onto the live release with an OpenAI adapter; payment
   screenshots, identity documents, statements and contracts always to a person; STOP understood in a voice note.
4. **Batching:** a customer's quick messages answered once.
5. **Location:** the pin mapped to a town or area for coverage and the installer, said as a colleague's to confirm.
6. **Quote follow-up (D3):** every quote tracked with its owner and chased day 1, 3 and 7 under the rules of D3; an
   e-mail copy where uCRM holds an address; a daily list per salesperson.
7. **After-sales:** welcome, day-3 check-in, day-30 rating with a review and referral ask, bill reminders, outage
   notices — WhatsApp and e-mail, quiet hours, STOP.

## E. Open — only the operator can answer

- The standard one-click quotation: which kit, plan and installation it holds, and who may press it.
- The wording of each chase and each after-sales message (the release proposes; the operator approves).
- Whether the follow-ups also go by e-mail, from which sender.
- The Privacy Policy's line on processors, now that voice notes and photos go to OpenAI.
- WhatsApp marketing: whether to open an official WhatsApp Business Platform account (Meta's verification is the
  operator's).

## F. Design

To follow.
