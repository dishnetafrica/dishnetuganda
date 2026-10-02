# Distributor portal OTP via the existing Evolution WhatsApp — design (read-only)

**Date:** 2026-10-02 · **Branch:** `claude/study-this-jhe2eg` · **Decision:** operator — *"use the existing Evolution API
WhatsApp integration; do not introduce a new SMS provider."*
**Status:** design only. **No code, migration, config, provider connection, real message, data, flag, or deployment.**
Portal stays disabled; Uganda and South Sudan behaviour preserved; Domain B untouched. **Supersedes `docs/52` §5**
(the SMS-vs-WhatsApp option set is closed in favour of Evolution).

Read from current code at HEAD `125d21f`. Facts are marked **[code-verified]** or **[assumption — runtime
verification required]**.

---

## 1. Reuse of existing infrastructure (no second integration; auth kept separate)

**What exists [code-verified]:**
- **Client:** `lib/EvolutionApiClient.php` — `sendText(phone,text)` → `POST /message/sendText/{instance}` with an
  `apikey:` header; `sendMedia`, `connectionState()`, `fetchInstances()`. Pure curl, PHP 7.4.
- **Service:** `lib/EvolutionApiService.php` — constructed from `$config`; maps **channels → instances** and back;
  `sendText($channel,$phone,$text,$class)`, `instanceFor()`, `channelFor()`, `isConfigured()`, `canReachApi()`,
  `instances()` (health list with a `connected` bool). `request()` returns `{ok, http, data, error}`.
- **The distributor PORT (already built, P3):** `lib/WhatsAppChannel.php` — an interface the distributor-notification
  layer is written against, "NEVER against Evolution directly, so there is always exactly ONE adapter." Adapters:
  `NullWhatsAppChannel` (**bound in the pilot — sends nothing**) and `EvolutionWhatsAppChannel` (a thin wrapper over
  the existing `EvolutionApiService`, **defined but bound nowhere**).

**Proposed reuse (the key design decision):** the OTP send goes **through the existing `WhatsAppChannel` port**, using
`EvolutionWhatsAppChannel` over the **existing** `EvolutionApiService`/`EvolutionApiClient`. Concretely, `PartnerApi`'s
`auth.request_code` already produces a `delivery` descriptor and passes it to an injected seam that is **null today**
(docs/51 §1); the seam becomes a tiny `PartnerOtpSender` that calls `WhatsAppChannel::send($verifiedPhone, $text)`.
- **No second Evolution integration** — it is the same client/service/port.
- **Not the customer-notification flow** — it does **not** go through `NotificationService` (the marketing/sales/
  customer path) at all. Distributor auth is its own send path with its own message text and its own audit class.
- **Separation from marketing/sales/customer** is enforced three ways: (a) a distinct send path (port, not
  `NotificationService`); (b) message **class `CLASS_STAFF`** (operational, never a marketing/proactive message); (c)
  its own audit/log line (`distributor_otp` event), never mixed with customer notification logs.

**Instance choice — operator decision (see §4).** The port's `EvolutionWhatsAppChannel` currently defaults to
`CHANNEL_SALES`; for auth separation the recommendation is a **dedicated instance** (a new `evo_instance_distributor`
the operator provisions) OR, with zero provisioning, an explicitly-chosen existing channel. Either way the **code path
is identical**; only the configured instance differs.

---

## 2. Recipient verification (server-side, verified-only, uniform)

[code-verified building blocks; wiring is the proposal]
- The recipient is resolved **only** server-side: the signed-in-attempt's account (`dist_partner_users.phone`, the OTP
  key) → its partner's **verified** contact. A code is sent **only** to a `dist_contacts` row with `verified = 1`
  (verification is an existing admin act, `dist_contact_verify` in `post_distributors.php`). An account with no
  verified number → **no send**.
- **The destination is never read from the login request.** `PartnerAuth::requestLoginCode` takes the phone only to
  *resolve the account*; the number the code is sent to is the account's stored verified contact, not the request field.
  A user cannot change the destination during authentication.
- **Anti-enumeration stays uniform [code-verified]:** `requestLoginCode` already returns the uniform `{status:"sent"}`
  for known/unknown/unverified alike; the delivery seam is simply not invoked when there is no verified recipient, so
  nothing in the response or timing distinguishes the cases.

**Open item for the wiring:** today `dist_partner_users.phone` and `dist_contacts.phone` are separate; the design must
resolve the account → its verified `dist_contacts` number (by `partner_id`, and — recommended — matching the account's
own phone), and **refuse to send if there is no verified match**. This resolver is server-side only.

---

## 3. OTP security (controls preserved; accepted ≠ delivered; failure handling)

**Preserved, already built & tested [code-verified] (docs/51 §1):** single-use code, expiry, per-account + per-IP send
caps, decaying lockout, TOTP second factor. Delivery does not change any of these — it only transmits the code the
existing logic already minted.

**Never logged or displayed [code-verified rule, to be upheld in the sender]:** the OTP code, the TOTP secret, the
Evolution `apikey`, and session tokens are never written to logs, audit rows, responses, or the message-send log. The
sender logs only an outcome + a non-secret reference (e.g. Evolution's returned message id, if any), never the code.

**Accepted ≠ delivered [code-verified]:** `EvolutionApiService::request()` returns `ok = true` on **HTTP 2xx from
Evolution** — that is Evolution *accepting* the request, not WhatsApp *delivering* it. The webhook (`evo_webhook.php`)
processes `MESSAGES_UPSERT` and `CONNECTION_UPDATE`; it **does not consume `MESSAGES_UPDATE` delivery acknowledgements**,
so **per-message delivery is not confirmable in the current code.** Therefore the design records the send outcome as
**`accepted` / `failed` / `unknown`**, and **never as `delivered`**. The user flow must not assert delivery ("if you
don't receive it, request again") — which the single-use code + rate cap already support.

**Failure modes and handling (proposed):**

| Condition | Detection [code-verified basis] | Handling |
|---|---|---|
| **Timeout / no connection** | `request()` returns `ok=false` with a connection error; Uganda runs the **`noResend`** posture (5.18.54, docs/46 row 31) that deliberately does **not** auto-resend a WhatsApp that may have gone | Record `unknown`; **do not auto-resend** (a resend could double-send a code); response stays uniform; the user may re-request within the rate cap (a new code supersedes the old) |
| **Provider error (4xx/5xx)** | `request()` → `ok=false`, `error`, `http` | Record `failed` with the non-secret reason; uniform response; no code leaked to logs |
| **Uncertain delivery (2xx but not delivered)** | `ok=true`, no delivery ack consumed | Record `accepted` (**not** delivered); rely on re-request |
| **Duplicate request** | existing per-account send cap + single pending code (`ON CONFLICT(user_id)` replaces) [code-verified] | The second request either throttles (uniform) or replaces the pending code; never two live codes |
| **WhatsApp instance unavailable** | `connectionState()` / `instances()[].connected` [code-verified] | A pre-send health check (or the send failing) → record `failed`/`unknown`; uniform response; ops sees it via the doctor/health, not the end user |
| **Number not on WhatsApp** | Evolution returns an error at send **[assumption — runtime verification]** | Record `failed`; uniform response; an admin re-verifies the contact |

---

## 4. Operational boundaries (exact instance & credentials — names only)

**Credentials/config keys [code-verified] — values never shown and never read here:**
- `evo_api_url` — the Evolution base URL.
- `evo_api_key` — the **single global API key** (one `apikey` header serves every instance).
- Instances: `evo_instance_sales` (`dishnet_sales`), `evo_instance_support` (`dishnet_support`),
  `evo_instance_account` (`dishnet_account`); legacy `evo_instance_name` / `evo_accounts_instance_name`.

**The exact instance for distributor OTP is a decision for you:**
- **Option D1 — dedicated instance (recommended):** provision a new WhatsApp number as `evo_instance_distributor`.
  Cleanest separation of auth traffic from sales/support/account; needs the operator to connect one number.
- **Option D2 — reuse an existing channel (zero provisioning):** send over `account` (or `support`), tagged as an auth
  message and logged separately. No new number, but auth shares a number with that channel's traffic.
  Either way the credential is the same `evo_api_key`; only the instance string differs.

**Capability confirmations:**
- **Auth-specific message is supportable [code-verified]:** `sendText(instance, number, text)` sends arbitrary text, so
  a dedicated OTP message ("Your DishNet distributor sign-in code is …") is straightforward; it need not reuse any
  customer template.
- **Accepted vs actual delivery is distinguishable only up to "accepted" [code-verified]:** the code can record
  Evolution's acceptance and the instance's `connected` health, but **cannot confirm WhatsApp delivery** without new
  `MESSAGES_UPDATE` handling (a separate, optional future enhancement). This matches your "do not mark delivered on
  accept" requirement — the design records `accepted`, never `delivered`.
- **No change to existing instances or customer messaging:** the design adds a new outbound auth message path; it does
  not alter `NotificationService`, the customer flows, or any instance's configuration. Nothing here sends a test or
  live message.

---

## 5. TOTP recovery (admin-only, audited, revokes sessions) — propose only

Unchanged from `docs/52` §5.4, restated as the standing proposal: a **staff-side, admin-only, audited TOTP reset**
(clears `totp_secret` + `totp_confirmed`, **revokes the distributor's live sessions in the same transaction**, writes an
audit row with the acting admin as actor, per the W-1 pattern). The distributor then re-enrols on next sign-in, which
still requires a fresh login code. **No self-service reset** (it would be a second account-takeover path). Not
implemented in this phase.

---

## 6. Deliverables summary

**Proposed design (one new thin piece + wiring):** a `PartnerOtpSender` that the `PartnerApi` delivery seam calls,
which formats the auth message and sends via `WhatsAppChannel::send()` bound to `EvolutionWhatsAppChannel` over the
existing `EvolutionApiService`; recipient resolved server-side to the account's verified contact; outcome recorded as
`accepted`/`failed`/`unknown` (never `delivered`); nothing secret logged.

**Exact code paths [code-verified]:** `lib/EvolutionApiClient.php::sendText`; `lib/EvolutionApiService.php::sendText`
(+`request`, `instances`); `lib/WhatsAppChannel.php` (`WhatsAppChannel`, `EvolutionWhatsAppChannel`,
`NullWhatsAppChannel`); `lib/ContactOptOut.php` (`CLASS_STAFF` never suppressed, line 94); `lib/PartnerApi.php`
(`auth.request_code` seam); `lib/PartnerAuth.php` (`requestLoginCode` delivery descriptor); `evo_webhook.php` (no
delivery-ack consumption). Config: `evo_api_url`, `evo_api_key`, `evo_instance_*`.

**Dependencies:** the Evolution instance + `evo_api_key` must be configured (they are, for the existing WhatsApp
features); the distributor's number must be a **verified** `dist_contacts` row; the portal flag stays **off**.

**Failure modes:** §3 table.

**Required tests (all synthetic; no real send):**
- **Fake Evolution server** (the repo already uses one for WhatsApp tests) — assert the OTP path calls `sendText` on
  the **chosen instance**, with the message text, to the **account's verified number**.
- Recipient: the number is taken from the account's verified contact, **never** from the request; an unverified/absent
  contact → **no send**, uniform `{status:"sent"}`.
- Accepted ≠ delivered: a 2xx fake response records `accepted`, not `delivered`; a 5xx/timeout records `failed`/
  `unknown` and does **not** resend.
- Secrets: the code/secret/apikey/token appear in **no** log, audit row, or response (search the captured output).
- Opt-out: a contact opted out of marketing still receives the OTP (`CLASS_STAFF`); a hard-blocked contact's handling
  is asserted per the chosen class.
- Weakened copies: a copy that reads the destination from the request fails the recipient test; a copy that records
  `delivered` on accept fails the accepted≠delivered test; a copy that logs the code fails the secrets test.
- **UG/SS preservation:** with the portal flag off, nothing changes; the SS tenant never reaches this path (the portal
  is Uganda-gated); the existing WhatsApp customer flows are byte-for-byte unaffected (no `NotificationService` change).

**Code-verified vs assumptions:**
- **Code-verified:** the client/service/port, config keys, `ok = accepted` semantics, no `MESSAGES_UPDATE` consumption,
  `CLASS_STAFF` bypasses opt-out, `connected` health, the Uganda `noResend` posture, the uniform anti-enumeration
  response.
- **Assumptions needing runtime verification (no live calls made here):** the exact `sendText` success JSON shape on
  the live v2.3.7 instance (message id availability); Evolution's error for a non-WhatsApp number; whether a dedicated
  `evo_instance_distributor` will be provisioned (operator); live delivery latency/reliability. **None can be confirmed
  without a live instance, and none is tested with a real send in this phase.**

---

## 7. What I need from you before any implementation

1. **Instance decision:** D1 (dedicated `evo_instance_distributor`, recommended) or D2 (reuse an existing channel —
   which one).
2. Approval to build the `PartnerOtpSender` + recipient resolver + tests **against the fake Evolution server only**
   (no live send, portal stays off), and the staff-side TOTP-reset (its own small change).
3. Confirmation that a **real** OTP send to a real distributor remains a **separate** later gate (staging rehearsal +
   your explicit go-ahead), after the staff-plane PD work (docs/52) you prioritise.

**I have changed nothing and await your decision.** Domain A's WhatsApp gateway remains off-limits; this uses only the
plugin's own Evolution integration.
