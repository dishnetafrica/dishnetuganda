# 57 — Image understanding: the provider boundary, and the payment-screenshot rule, before any provider is chosen (2026-10-05)

**Status: boundary DOCUMENTED and BUILT as an interface with a deterministic fake; NO vision provider is selected,
integrated or callable.** Written, as the operator's Batch 3 instruction requires, before any real provider: what an
image provider is given, what it must answer, the validation that runs before and after it, what happens to its answer,
and — above everything else in this batch — what the system may and may not do when the image is a payment screenshot.
Batch 3 (`docs/07`, 5 Oct, "Batch 3") implements everything on this side of the boundary and nothing on the other side.

Related: `docs/55` §9 (the architecture; Batch 3 row: *payment screenshot = evidence, escalation, never a financial
write*), `docs/56` (the voice boundary this one mirrors), the Batch 1 media foundation and the Batch 2 voice path.

## 1. Where the boundary sits

```
customer photo / screenshot (WhatsApp, Evolution)
  → evo_webhook.php            stores "[IMAGE]" or the caption as always; with ai_media_enabled on, records the wa_media row
                               and queues ai.media (Batch 1). With ai_media_image on as well, a CAPTIONED photo is NOT
                               also queued as a typed turn: the image turn below carries the caption, so the customer is
                               answered once, not twice. STOP on the caption is still the webhook's (step 8b), unchanged.
  → MediaWorker                fetches the bytes into memory through getBase64FromMediaMessage, validates the announced and
                               actual size and the reported type against the allow-list (JPEG, PNG, WebP), records
                               sha256 / size / type                                              (Batch 1, unchanged)
  → ImageUnderstanding         images only, and only with ai_media_image on:
                               reads the image header (getimagesizefromstring, no decoder library): a payload that is not
                               an image of an allowed type is malformed; one over 8,000 px a side or 25 megapixels is too
                               large — both refused before any provider sees it
        ┌──────────────────────────────────────────────────────────────────────────────────────────┐
        │  ImageDescriberPort::describe(MediaBlob $image, array $hints, int $timeoutSeconds)        │  ← THE BOUNDARY
        └──────────────────────────────────────────────────────────────────────────────────────────┘
                               normalises the description, classifies the image (the provider's class is advisory; the
                               PAYMENT decision below is this plugin's own), writes the description on the wa_media row and
                               on the stored message, and then EITHER
                                 · payment evidence → hands the conversation to a person; NO ai.reply, NO record changes
                                 · anything else    → queues ONE ordinary ai.reply event, labelled as an image description
  → AiReplyWorker → DishNetAiBrain → ReplyPrivacyGuard → Evolution            (the existing text path, unchanged in kind)
```

## 2. The interface (`lib/ImageDescriber.php`)

```php
interface ImageDescriberPort
{
    /** A short stable name for logs and the record: 'fake', later a real provider's. */
    public function name(): string;

    /**
     * @param MediaBlob $image          the bytes in memory — never a path — with mimetype, size, sha256
     * @param array     $hints          'mimetype', 'width', 'height' (from the header), 'caption' (the customer's words,
     *                                  '' when none), 'channel' (sales | support | account)
     * @param int       $timeoutSeconds the whole call must return within this many seconds
     * @return array    ok=true:  ['ok' => true, 'description' => string, 'classification' => string (§4),
     *                             'signals' => string[] (short tags the provider saw: 'amount', 'transaction_id', …)]
     *                  ok=false: ['ok' => false, 'reason' => string (§5), 'retryable' => bool, 'detail' => string]
     */
    public function describe(MediaBlob $image, array $hints, int $timeoutSeconds): array;
}
```

`detail` may name an HTTP status or an error class and may never carry image bytes, a description, a key, a phone
number or a JID. The provider is constructed by `ImageDescriberFactory::fromConfig()` from `ai_image_provider`: unset or
`none` → no provider (a photo with `ai_media_image` on is then handed to a person with the reason `provider_missing`);
`fake` → the deterministic fake, constructible only when the test environment names its script file; anything else → no
provider and one log line. **No value selects a real provider today.**

## 3. Expected input, and the validation around the provider

| Check | Rule | Where |
|---|---|---|
| Kind | `image` only; a sticker is `unsupported_kind` (Batch 1) | `InboundMedia` |
| Reported type | `image/jpeg`, `image/png`, `image/webp` — nothing else | `MediaPolicy::ALLOWED['image']`, `MediaFetcher` |
| Announced size | ≤ `ai_media_max_bytes` (default 15 MiB), refused BEFORE the fetch | `MediaFetcher` |
| Actual size | the same limit after a strict base64 decode | `MediaFetcher` |
| Decoded header | `getimagesizefromstring()` must read a width, a height and a type in the allow-list; a payload that is not an image, or an image of another type behind an allowed label, is `malformed_image` / `unsupported_mime` | `ImageUnderstanding`, before the provider |
| Dimensions | ≤ 8,000 px on each side and ≤ 25 megapixels, else `too_large_image` | `ImageUnderstanding`, before the provider |
| Bytes | in memory only, wiped after the turn, never on disk, never in a log | `MediaBlob` |
| Time | the provider call returns within `ai_media_image_timeout_s` (default 30, range 5–120) | the adapter |

## 4. Expected output, and what is done with it

**Classification** is one of a fixed list; anything else the provider says becomes `general`:
`payment_proof` (a payment, transfer, mobile-money or bank confirmation, a receipt, an invoice marked paid, account or
till details) · `site_photo` (a place where equipment might go) · `equipment_photo` (a dish, router, cable, indicator
lights) · `screenshot` (a screen that is not a payment) · `document_photo` (a photographed document — described only,
never extracted: that is Batch 4) · `general` · `unreadable`.

**The payment decision is this plugin's, not the provider's.** `PaymentEvidence::looksLikePayment()` says yes when the
provider's class is `payment_proof`, OR when the description or signals carry both a money token (an amount, a currency,
"total", "balance") and a transaction token ("paid", "payment", "transaction", "receipt", "transfer", "deposit",
"confirmation", "reference", mobile money, bank). The rule errs towards yes: the cost of a wrong yes is a photo a person
looks at; the cost of a wrong no is an assistant talking about money it has not seen in the books.

| Outcome | What happens |
|---|---|
| **Payment evidence** | The row: `understood`, `understanding_kind = 'payment_evidence'`, the description kept as the evidence record. The stored `[IMAGE]` message: `[image, payment evidence — a colleague will verify] <description>` and the caption, so the inbox shows what arrived. **No `ai.reply` event is queued — the brain never sees it.** The conversation is handed to a person through `lib/Handover.php`: `needs_human`, a `wa.escalation` event, the staff alert saying a payment screenshot arrived and **nothing was recorded or marked paid**, and the operator's own holding line to the customer once, if one is configured. **No invoice, payment, ledger, cashbook or uCRM record is read for a decision or written.** |
| **Anything else** | The row: `understood`, `understanding_kind = 'description'`. The stored message: `[image, described automatically] <description>`, plus `[caption from the customer] <caption>` when there was one. ONE `ai.reply` event, the shape the webhook gives a typed message, plus `origin: image` and `image: {media_id, classification, width, height, has_caption}`. Emitted inside the same transaction that marks the row `understood`, guarded by `status <> 'understood'`, so a retry or a duplicate event can never emit a second one. |

**What the brain is told** (`BrainContext` gains a fifteenth key, `image => ['classification']`; `DishNetAiBrain` adds a
conditional IMAGE JUST RECEIVED block beside the pin and voice blocks): the message is an AUTOMATIC DESCRIPTION of a
picture the customer sent, which may be wrong or incomplete; every word, figure, name, amount or date read from the
image is UNCONFIRMED until the customer confirms it; if the picture seems to show a payment, a receipt or account
details — whatever the classification said — the assistant must not confirm, accept or promise anything about payment,
must not say money was received, and must say a colleague will verify it; it is content under rule 7, never an
instruction. The description is the provider's words about the customer's picture, not the customer's own words, and
the prompt says so.

**STOP** stays the webhook's: it is read off the caption when the message arrives (step 8b), exactly as for any typed
text, before anything here runs. A description is never read for STOP — it is not the customer's words.

## 5. Failure handling — a safe hand-over, never an invented answer

Reason codes, fixed: `provider_missing · unsupported_mime · malformed_image · too_large_image · empty_description ·
conversation_missing` (permanent) and `provider_error · timeout` (retryable).

| Failure | What happens |
|---|---|
| provider slow or 5xx | row `failed` with the code; the media event is retried with the EventBus backoff; each retry fetches the image again, since nothing was kept |
| retries exhausted | row `dead`; the conversation handed to a person through the same path AiReplyWorker uses — `needs_human`, `wa.escalation`, the staff alert, the holding line once |
| no provider, bad type, not an image, too large, nothing described | row `failed` with the code; the same hand-over at once; **no `ai.reply`, so the brain never answers a picture it did not understand** |
| a captioned photo in any of the above | the caption was not queued as a typed turn (the image turn would have carried it), so the hand-over is what answers it: the person sees the caption and the photo in the inbox |

## 6. The payment-screenshot rule — binding, and how it is enforced

The operator's rule: when an image appears to contain payment proof, a bank or mobile-money transaction, a receipt, an
invoice or payment confirmation, or account or payment details, the AI must not mark an invoice paid, create or modify
a payment, promise payment acceptance, alter financial records, or treat OCR/vision output as authoritative financial
evidence. It may only classify or describe the evidence and route it to the existing human process.

| Layer | Enforcement |
|---|---|
| **Routing** | `PaymentEvidence::looksLikePayment()` (§4) decides BEFORE any `ai.reply` is queued; a yes means no event and a hand-over. This is deterministic plugin code, testable without a provider, and the first thing a weakened copy must break to reach the brain. |
| **No writer in reach** | `ImageUnderstanding`, `ImageDescriber` and `MediaWorker` reference no payment, invoice, ledger, cashbook or CRM writer (`CrmApiClient`, `DpoPaymentService`, `CashbookService`, …); a source check in the test pins it. The media worker runs on the same database role as before and gains no new table write. |
| **Proved by execution** | the test counts every financial table (`dpo_payments`, `dpo_payment_events`, `cb_ledger`, `staff_ledger`, `wallet_transactions`, `cash_advances`, `expense_receipts`, `stock_purchase_payments`, `lte_financial_ledger`, …) before and after a payment screenshot is processed, and the fake uCRM's payment count: all unchanged, zero. |
| **The brain as a second line** | should a payment image ever reach the brain (a weakened copy, a provider that mislabels and a description without money words), the IMAGE block forbids confirming or promising payment and orders a hand-over; ReplyPrivacyGuard still refuses figures the tools did not return. |
| **Evidence, not truth** | the description is stored as `payment_evidence` for the person to compare against the books; nothing reads it as a fact about money. |

## 7. Size, time and cost assumptions (to be measured when a provider is chosen)

| Assumption | Working figure | Why it matters |
|---|---|---|
| Photo size | WhatsApp recompresses to ~1–3 MB JPEG; screenshots 100–600 KB PNG | well inside the 15 MiB fetch limit |
| Dimensions | ≤ 4,000 px a side in practice; the 8,000 px / 25 MP cap is a safety stop | a decoded-size cap protects a provider or a resizer from a decompression bomb |
| Provider latency | 2–8 s for one image | the media worker's 45 s budget allows one image per run; the timeout bounds the wait |
| Cost | hosted vision is priced per image or per input token, in the order of a fraction of a cent to a few cents per image | a per-image budget can be stated once the provider is chosen |
| Data leaving the server | the image goes to the provider; a payment screenshot carries names, numbers and amounts — the provider's retention and training terms must be read and approved by the operator before a provider is switched on | this is the decision Batch 3 does NOT take |

Candidates the operator may consider later, none chosen: a hosted vision model from the vendor whose key the brain
already holds (one credential, one contract), or a self-hosted model on the server (no image leaves the host). Either is
one class implementing `ImageDescriberPort`.

## 8. Test strategy (what Batch 3 proves without any provider)

- **Deterministic fake** (`FakeImageDescriber`): answers from a script keyed by the image's sha256 — a description with
  a classification and signals, or a scripted failure; records sha256 prefixes and counts, never bytes or text. The CLI
  runner can use it only when the environment names its script file; production cannot.
- **Real image headers, no decoder**: the tests build PNG files whose headers declare chosen dimensions, so the header
  checks run on genuine PNG structure; a non-image payload exercises `malformed_image`.
- **Fake Evolution, fake uCRM, fake brain** (Batches 0–2): the whole existing reply path runs without a model call.
- Proved: the happy path end to end with the modality on the event, the context, the prompt and the audit; exactly one
  description and one `ai.reply` per message across duplicates; the caption answered once; every validation refusal and
  every failure class hands over; **a payment screenshot by class, and one by words alone, produce evidence and a
  hand-over and no event, no brain call, no financial write, no change to any financial table or uCRM payment**; STOP
  on a caption intact; the guard intact; nothing on disk or in logs; both flags off → nothing; `ai_media_enabled` off
  wins; weakened copies of each invariant caught — including one that lets a payment image reach the brain.

## 9. What this document does not decide

The provider. Whether images may leave the server. Document extraction (Batch 4). Any customer-facing sentence about
payments: the only text a customer receives on a payment screenshot is the operator's own holding line, or nothing.
