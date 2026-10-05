# 60 — Document activation safety: the flag ladder, the dry run, the counters and the retry decision — Batch 5 (2026-10-05)

**Status: BUILT in development only (plugin 5.18.81), NOT deployed, NOT pushed; every flag off everywhere; no production
configuration touched; no customer-facing document AI exists anywhere.** This is the design record docs/59 §6 asked for
before any activation: the minimum safety layer between a frozen Slice 4b (`7901ffe`, held on `archive/slice-4b-2026-10-05`)
and a production that may one day read documents. It changes how the document path is *switched on*, not how a document is
read: `lib/PdfReader.php`, the readers, the classifier and the record policy are untouched. E-3 and E-6 stay **NOT MEASURED**
(§7). Everything stated as behaviour below is proved by `tests/test_document_activation.php` (§8) unless it says otherwise.

**Reading rule.** VERIFIED means read from the code at this commit or proved by a test named here; RECOMMENDED is a proposal
for the operator; UNKNOWN is what no code or test can establish. The terminology is docs/58's and docs/59's.

## 0. The answer in one paragraph

Four flags form a ladder, and each rung needs every rung below it: **fetch** (`ai_media_enabled`) → **process**
(`ai_media_document`: extract, classify and record — alone, that is the **dry run**, which tells nobody and answers nothing)
→ **hand over** (`ai_media_document_handover`: a human-only class or a refusal reaches a person) → **reply**
(`ai_media_document_reply`: the assistant answers a harmless document, and its caption once). A higher flag without the one
below it does nothing — proved for all sixteen combinations. The dry run records exactly what production would record, on the
`wa_media` row and in the stored message's metadata, and **never rewrites the stored body**, because the body is what the
model reads as history on a later turn; a dry run therefore cannot leak a document into any reply, now or later. A read-only
counters tool answers the operating questions in codes and counts, never content. The queue's retry gap from docs/59 §5.6 is
closed **for media rows inside the worker**, by a guard the shared `EventBus` does not have; the shared queue itself is
unchanged, and a separate remediation for it is designed and recommended as its own batch (§6).

## 1. What changed, and what did not — VERIFIED

| | |
|---|---|
| `lib/MediaPolicy.php` | `documentHandoverEnabled()`, `documentReplyEnabled()`, `documentMode()`, `DOCUMENT_MODES`; `documentEnabled()` unchanged |
| `lib/DocumentExtraction.php` | `complete()` is mode-aware: the body is rewritten and the `ai.reply` queued **only** in `reply`; a human-only class is returned as `evidence` only in `handover` and `reply`; otherwise the new outcome `recorded`; `metadata.document` gains `mode`, `ms`, `body_rewritten` |
| `workers/MediaWorker.php` | hands over only on the hand-over rung; the new `recorded` outcome; one structured outcome line per document; the **lost-worker guard** (§6.2) with the reason `worker_lost` |
| `evo_webhook.php` step 9b | the caption's text turn is skipped only on the **reply** rung |
| `tools/set_config.php` | the two new bool keys; the processing flag's description says *dry run* |
| `tools/media_status.php` | **new**, read-only, the counters (§4) |
| `manifest.json` | 5.18.81, and the fourteen test pins |
| **untouched** | the readers, the classifier, the record policy, the hand-over wordings, `lib/EventBus.php`, every migration (still 085), the voice and image paths, South Sudan, Domain B |

## 2. The ladder — VERIFIED (`MediaPolicy::documentMode`, proved over all sixteen combinations)

| Rung | Flag | Default | Needs | What it adds |
|---|---|---|---|---|
| 1 fetch | `ai_media_enabled` | **off** | — | the webhook records the media message and the worker fetches, validates and wipes the file — every supported kind |
| 2 process | `ai_media_document` | **off** | rung 1 | a document is extracted, classified and **recorded**; alone this is the **dry run** |
| 3 hand over | `ai_media_document_handover` | **off** | rungs 1–2 | the classification is acted on for a **person**: a human-only class, a refusal, a fetch that failed for good, a queue that gave up → `needs_human`, the alert, the holding line |
| 4 reply | `ai_media_document_reply` | **off** | rungs 1–3 | a harmless document, seen whole, becomes the customer's turn through the existing assistant; a caption is answered once, by that turn |

The mode word is the whole state: **off** (rung 1 or 2 off), **dry_run** (rungs 1–2), **handover** (1–3), **reply** (1–4).
`reply` without `handover` is `dry_run`; `handover` or `reply` without `document` is `off`; anything without `media` is `off`.
No key set is `off`. The worker, the extraction, the webhook and the counters tool all read the one function; none reads a flag
by name (asserted). `ai_enabled` still gates the runner and the reply worker above all of this, as before.

**Why rung 4 needs rung 3.** With replies on and hand-over off, a human-only document would be recorded in silence while its
caption went unanswered (step 9b skips the caption's text turn when the document turn is on); the ladder makes that state
unreachable instead of documenting it.

## 3. The four modes, outcome by outcome — VERIFIED

| Outcome | off | dry_run | handover | reply |
|---|---|---|---|---|
| harmless document (general, spreadsheet) | fetched and wiped (rung 1) or stored only | **recorded**: `understood` / `extraction`, the capped text in `understanding`; metadata `mode: dry_run`; **body untouched**; no event; nobody told | recorded as in dry_run; no event; nobody told (nothing is wrong with it) | the body rewritten under the label; **one `ai.reply`**; the caption carried by that turn |
| human-only class (payment proof, statement, invoice, contract, quotation, identity document, credential) | — | **recorded**: `understood` / `document_evidence`, the record policy's excerpt or nothing; body untouched; no hand-over; no event | **hand-over** with the class wording (alert, `needs_human`, holding line); body untouched; no event | hand-over as in handover; the body carries the evidence label and the masked excerpt (docs/58 D-7); no event |
| refusal (`password_protected`, `pdf_no_text`, `provider_missing`, `malformed_document`, `too_large_document`, `too_slow`, `classification_*`, …) | — | `failed` + reason on the row; **nobody told** | hand-over with the refusal wording | as handover |
| fetch failed for good (`fetch_failed` 4xx, `unsupported_mime`, `too_large`) | `failed` / `unsupported`, nobody told (Batch 1) | as off | hand-over *could not be fetched (<reason>)* | as handover |
| queue gave up, or a worker was lost (§6.2) | `dead`; the conversation marked `needs_human` for the inbox, no message (Batch 1) | as off | full hand-over *could not be read after every attempt* | as handover |
| the caption of a captioned document | answered as text by the webhook, as always | **answered as text by the webhook** | answered as text by the webhook | skipped at 9b: the document turn carries it; a human-only document's caption is covered by the holding line |
| a typed text message | answered as always | as always | as always | as always |

Three rules behind the table:

- **The record is the same in every mode.** `wa_media.understanding`, `understanding_kind` and the record policy (nothing for
  identity and credential; the masked excerpt for the five evidence classes; the capped text for a harmless document) do not
  depend on the mode. That is what the dry run exists to observe: what production would have recorded, with nobody affected.
- **The stored body is rewritten only in `reply`.** `AiReplyWorker::getMessagesForAi()` feeds the model the last twenty
  bodies of the conversation, so a body carrying an extract is read on the next typed turn whatever the flags say then. In
  `dry_run` and `handover` only `wa_messages.metadata` is written; the history shows `[DOCUMENT]` or the caption, exactly as
  before Batch 1. Proved by driving a later typed turn through the reply worker: the history holds the placeholder and
  neither the extract, the label nor a document key.
- **Nobody is told below rung 3.** No alert, no `needs_human`, no holding line, no `wa.escalation` event in the dry run, for
  any class and any refusal. The only visible consequence of a dry run is in the tables and the log.

## 4. Observability — VERIFIED

**One structured line per document** in `ai_platform.log`, written by the worker for every outcome:
`document outcome=<understood|recorded|evidence|failed|unsupported|dead> kind=<pdf|docx|…|-> class=<class|-> reason=<code|->
mode=<mode> ms=<n> attempts=<n>`. Never the text, the file name, the caption, the number, the JID or the hash (asserted against
a needle list across the whole run, and a weakened copy that adds the file name is caught).

**Three fields in `wa_messages.metadata.document`**: `mode` (the rung the row ran under), `ms` (the extraction's time) and
`body_rewritten`. No migration: the mode and the time live beside the classification the Slice 4a record already wrote.

**`tools/media_status.php [--days N] [--json]`** — read-only (the database is opened `SQLITE_OPEN_READONLY`; the data
directory comes through `cliDataDir()`), codes and counts only, for the window:

| The brief asked for | Where the tool reads it |
|---|---|
| received documents | `wa_messages` inbound rows with `media_type = 'document'` — flags or no flags |
| media fetch success / failure | `wa_media` status and `MediaFetcher::REASONS` codes |
| extraction success / failure | `wa_media` status and `DocumentExtraction::REASONS` codes |
| no-text, oversized, encrypted, malformed, unsupported, too slow, unclassifiable | the reason codes, named one by one |
| human-only / dry-run / AI-eligible classifications | `metadata.document.classification` against `DocumentClassifier::HUMAN_ONLY` and `BRAIN_ELIGIBLE`; `metadata.document.mode` |
| hand-overs | `wa.escalation` events created by `media_worker` |
| AI turns queued for documents | `ai.reply` events created by `media_worker` whose payload says `origin: document` (counted, never printed) |
| dead-letter events, stale locks, retried events | the `ai.media` events: status, `attempts`, the *[stale lock released]* marker |
| processing duration | `metadata.document.ms` p50 / p95 / max; the settled rows' age |
| retry count | `wa_media.attempts`: rows with more than one claim, the most on a row, `worker_lost` |

It never selects a body, an understanding, a caption, a file name, a JID, a hash or a number (asserted on its source), and a
weakened copy that prints the recorded text is caught. The voice and image rows are summarised at the fetch level only.

**Never logged** (the code's rule, now asserted): document contents and excerpts, PDF bytes and base64, credentials, customer
numbers and JIDs, file names, captions, the full extracted text.

## 5. Rollback — VERIFIED pattern

Flag first, code second, exactly as docs/59 §5.5: `ai_media_document_reply = 0` ends the assistant's document turns at the
next worker tick (the captions return to the text path); `ai_media_document_handover = 0` ends the hand-overs; `ai_media_document
= 0` ends the reading (documents are fetched and wiped, rung 1); `ai_media_enabled = 0` ends the recording, and the runner
settles pending rows `skipped`. Each step is proved as a behaviour in `test_document_activation.php` §5 (bypass and bottom rung).
Code rollback is code-only: no migration, no setting, no file on disk differs; a row written by 5.18.81 is read by 5.18.80 (the
metadata keys are additive). `set_config` writes the two new keys like every other bool.

## 6. The EventBus decision — the finding from docs/59 §5.6

### 6.1 The finding, restated

`EventBus::consume()` sets `processing` without touching `attempts`; `attempts` increments only in `fail()`; `releaseStale()`
turns a lock older than 300 s back into `failed` without touching `attempts`. A worker killed mid-event — a memory fatal, an
OOM kill, a restart — therefore leaves an event that is released and claimed again **indefinitely**. The property is shared by
every event type and predates Batch 1; the media path only makes a kill more plausible because it holds bytes. Both halves of
this are now **pinned by test** so the shared behaviour cannot drift unnoticed (a claim counts no attempt; a stale release
counts none; five reported failures are dead).

### 6.2 What Batch 5 does — the worker-level guard, VERIFIED

`wa_media.attempts` already counts every claim of a row (it is incremented at `fetching`). Before fetching, `MediaWorker` now
compares it with the event's own `max_attempts`: a row claimed as many times as the queue allows is settled **`dead`** with the
reason `worker_lost`, a person is told exactly as for the queue's own dead letter (full hand-over on rung 3 and above; the
Batch 1 inbox mark below it), the outcome line says `dead … worker_lost`, and the event is acknowledged. A reported failure
never reaches the guard first: on the fifth claim the row reads four, so the fifth failure still goes through `fail()` and
`onDead()` as before; on a sixth claim — which only a lost worker can produce — the guard fires. The control is proved: a row
claimed four times is fetched and read on its fifth, allowed claim.

Why here and not in `EventBus`: the brief forbids altering the shared semantics silently; the guard touches one table the
media worker already owns, changes nothing for `ai.reply`, `wa.escalation`, intents or any other type, and is what activation
needs now. Its cost: it binds media rows only.

### 6.3 What is designed and NOT built — the shared remediation, RECOMMENDED as its own batch

The shared fix has one safe shape: **`releaseStale()` counts the release as an attempt** — `attempts = attempts + 1`, and
`status = 'dead'` when that reaches `max_attempts` — because a stale lock is, by construction, a worker that did not report.
`consume()` must not increment (a successful claim is not a failure, and `fail()` would then double-count). Two things make it
a separate batch: a dead event produced by `releaseStale()` has no `onDead()` call, so each worker that stands for a waiting
person (`AiReplyWorker`, `MediaWorker`) needs a path that notices a dead row it did not kill; and the proof the brief
requires — that existing text events are unchanged — means a suite driving `ai.reply` through claim, defer, fail and dead
with the same results before and after. Neither belongs in an activation-safety batch, and the worker guard removes the
media exposure without them. **Decision for the operator (D-60-2):** schedule the shared remediation before the first
controlled live pilot, or accept that non-media event types keep today's property.

## 7. E-3 and E-6 — NOT MEASURED

Unchanged: **E-3 (the live fetch latency at 5–10 MiB) NOT MEASURED; E-6 (the live document envelope) NOT MEASURED.** Nothing
in this batch measures either, and nothing in it claims to. The safe measurement is docs/59 §2.6, option A — a staff-only,
read-only, flags-off protocol, with its never-print list — and it is preserved by reference; this record does not restate or
alter it. The dry run adds a second, slower route once a dark deployment exists: the counters tool reads E-3 off real traffic
(`fetch` success and the settled rows' age) and E-6 off the live `wa_media` rows, without a flag above rung 2.

## 8. Proofs

- `tests/test_document_activation.php` **92 passed, 0 failed**, twice: the sixteen-combination matrix, the dry run (the record, no event, no
  hand-over, the body untouched, a later typed turn seeing only the placeholder), the hand-over and reply modes, the bypass attempts, fifteen
  human-only-class × mode combinations with no AI turn, the lost-worker guard with its control, the queue's own semantics pinned, the privacy of
  every log line and of the counters tool, the real webhook and the real runner in a sandbox over all four modes with typed text still queued;
  **11 weakened copies each caught**, and the control (core and cli) trips none.
- `tests/test_document_media.php` 166 and `tests/test_document_pdf.php` 102: unchanged tallies on the reply rung.
- **Full suite:** **`tests/run.sh` 277 files, 12,991 passed, 0 failed, 0 skipped** (was 276 / 12,899 at 5.18.80: the new `test_document_activation` 92;
  no other suite moved). **Second run: 277 / 12,991 / 0 again**, every suite's tally identical. PHP warnings in either run: 5
  (test_dpo_endpoints 5). The South Sudan and tenant suites, unchanged: `test_notify_schedule_health` 19 · `test_staff_jobs_south_sudan` 51 · `test_tenant_profile` 108 · `test_email_no_sudan` 62 · `test_notify_tenant_text` 30 · `test_cashbook_tenant` 26 · `test_portal_tenant` 112 · `test_sales_support_tenant` 37 · `test_ai_country_facts` 21 · `test_phone_country` 26.
- `git diff --check` clean; `php -l` on every changed PHP file; no post-7.4 syntax in the plugin files; no secret-shaped value in the diff; the two
  banned values absent; no file under `dishnet-mikrotik-control-plane/` touched; no migration added (the last is still 085).

**Guards amended deliberately, each with its reason.** `test_document_media.php` and `test_document_pdf.php` now climb the whole
ladder in their configurations, so they keep proving the REPLY rung Slices 4a and 4b built (a configuration with the two lower
rungs alone is now the dry run, which they do not test); `test_document_media.php`'s step-9b wiring guard names the new
condition (`documentReplyEnabled`), because in the dry-run and hand-over modes the caption must be answered as text, and
`test_document_activation.php` proves that it is. The fourteen version pins moved to 5.18.81. No guard was deleted.

## 9. What this changes in the activation plan of docs/59 §3

The stages become rungs, and D-59-1 is resolved by construction rather than by decision:

| docs/59 stage | now |
|---|---|
| 0 / 0b | unchanged: all off; a dark deployment is its own approval (docs/59 §3.5) |
| 1 fetch only | rung 1, `ai_media_enabled` alone |
| **new: dry run** | rung 2: the classifications observed through `tools/media_status.php` with nobody affected; **RECOMMENDED at least 14 days and 10 documents** |
| **new: hand-over only** | rung 3: the human-only classes and the refusals reach a person; the holding line is the only customer-facing effect; still no AI content |
| 2 documents understood | rung 4: the assistant's turn for harmless documents — **the only customer-facing document AI, and the only step that needs the explicit approval docs/59 §3.4 asked for** |

Rollback at every rung is one flag down (§5). Nothing here authorises deploying or switching on anything.

## 10. Decisions for the operator — none taken here

- **D-60-1** — whether the dry run (rung 2) may be the first production rung after a dark deployment, and for how long.
- **D-60-2** — the shared `EventBus` remediation (§6.3): before the first controlled live pilot, or accepted as is for the
  non-media event types.
- **D-60-3** — whether the `worker_lost` reason should also raise the dead-letter alert the `event_processor` sends for queue
  dead letters (today it raises the hand-over on rung 3 and the inbox mark below it; the queue's own alert is for events the
  queue killed).
- **D-60-4** — whether a scheduled copy of `tools/media_status.php --json` is wanted (a controlled edit; today it is run by hand).

---

Frozen Slice 4b: `7901ffe` (plugin 5.18.80), held on `archive/slice-4b-2026-10-05`
Batch 5: plugin 5.18.81, development only, local commit
GitHub working branch head: `7b73813`
E-3: NOT MEASURED
E-6: NOT MEASURED
Production flags: OFF
`ai_document_provider`: `none`
Deployment: NO
Push: NO
