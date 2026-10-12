# 56 — Voice transcription: the provider boundary, before any provider is chosen (2026-10-05)

**Status: boundary DOCUMENTED and BUILT as an interface with a deterministic fake; NO transcription provider is
selected, integrated or callable.** This document exists because the operator's Batch 2 instruction says a provider may
not be selected or integrated until the boundary, the expected input and output, the size, time and cost assumptions and
the test strategy are written down. Batch 2 (`docs/07`, 5 Oct, "Batch 2") implements everything on this side of the
boundary and nothing on the other side.

Related: `docs/55` §9 (the architecture, Batch 2 row), the Batch 1 media foundation (`docs/07`, 5 Oct, "Batch 1").

## 1. Where the boundary sits

```
customer voice note (WhatsApp, Evolution)
  → evo_webhook.php            stores "[AUDIO]" as always; with ai_media_enabled on, records the wa_media row and
                               queues ai.media                                            (Batch 1, unchanged)
  → MediaWorker                fetches the bytes into memory through getBase64FromMediaMessage, validates type and
                               size, records sha256 / size / type                          (Batch 1, unchanged)
  → VoiceTranscription         audio only, and only with ai_media_voice on:
        ┌─────────────────────────────────────────────────────────────────────────────────────┐
        │  TranscriberPort::transcribe(MediaBlob $audio, array $hints, int $timeoutSeconds)    │  ← THE BOUNDARY
        └─────────────────────────────────────────────────────────────────────────────────────┘
                               normalises the text, labels it, writes it on the wa_media row and on the stored
                               message, runs the EXISTING STOP detection, and queues ONE ordinary ai.reply event
  → AiReplyWorker → DishNetAiBrain → ReplyPrivacyGuard → Evolution       (the existing text path, unchanged in kind)
```

Everything left of the boundary is this plugin's code and is tested without any network. Everything right of it is a
provider that does not exist in this repository yet. The worker never knows which provider answered; it knows the result
shape below and nothing else.

## 2. The interface (`lib/Transcriber.php`)

```php
interface TranscriberPort
{
    /** A short stable name for logs and the wa_media row: 'fake', later e.g. 'openai-whisper'. */
    public function name(): string;

    /**
     * @param MediaBlob $audio          the bytes in memory (never a path: nothing is on disk), with mimetype, size, sha256
     * @param array     $hints          'mimetype' (as announced), 'seconds' (announced duration, 0 unknown),
     *                                  'language' (BCP-47 hint or ''), 'channel' (sales|support|account)
     * @param int       $timeoutSeconds the whole call must return within this many seconds
     * @return array    ok=true:  ['ok' => true, 'text' => string, 'language' => string, 'duration_s' => float|null]
     *                  ok=false: ['ok' => false, 'reason' => REASON, 'retryable' => bool, 'detail' => string]
     */
    public function transcribe(MediaBlob $audio, array $hints, int $timeoutSeconds): array;
}
```

`REASON` is one of a fixed list, never free text from a provider: `timeout` and `provider_error` (retryable — the
EventBus retries the whole media event with its backoff, which re-fetches the audio because nothing was kept),
`invalid_audio`, `unsupported_audio`, `too_long`, `empty_transcript`, `provider_missing` (permanent — recorded on the row
and the conversation is handed to a person). `detail` may name an HTTP status or a provider error class; it may never
carry audio, a transcript, a key, a phone number or a JID.

A provider is constructed by `TranscriberFactory::fromConfig()` from `ai_transcription_provider`: unset or `none` → no
provider (a voice note with `ai_media_voice` on is then handed to a person with the reason `provider_missing`); `fake` →
the deterministic fake, constructible only when the test environment names its script file; any other value → no
provider and one log line. **No value selects a real provider today.**

## 3. Expected input

| Property | Value | Where enforced |
|---|---|---|
| Container / codec | `audio/ogg; codecs=opus` (every WhatsApp voice note); also mpeg/mp3/mp4/aac/amr/wav/webm audio files | `MediaPolicy::ALLOWED['audio']` (Batch 1) |
| Size | ≤ `ai_media_max_bytes` (default 15 MiB) — a voice note is 20–80 KB per 10 s | `MediaFetcher` (Batch 1), before and after the fetch |
| Duration | ≤ `ai_media_voice_max_seconds` (default 120, range 10–600), from the duration the webhook announced | `VoiceTranscription`, before the provider is called |
| Bytes | in memory only, wiped after the turn | `MediaBlob` (Batch 1) |
| Language | English and the Ugandan languages a customer may speak; no hint is sent in Batch 2 | — |

## 4. Expected output, and what is done with it

| Step | Rule |
|---|---|
| Normalisation | control characters removed, whitespace collapsed, trimmed, cut at 4,000 characters; empty → `empty_transcript` |
| Persistence | `wa_media.understanding` = the transcript, `understanding_kind = 'transcript'`, status `understood`; the stored `[AUDIO]` message's body becomes the labelled transcript so the inbox and the model's history show what was said. **No audio is kept anywhere.** |
| Label | the message the brain reads is `[voice message, transcribed] <transcript>`, and the brain's data block carries a VOICE MESSAGE section: the text is an automatic transcript, every name, figure and amount is unconfirmed, ask rather than guess. |
| STOP | `ContactOptOut::detect()` runs on the raw transcript exactly as the webhook runs it on typed text; a match records the opt-out (source `voice_keyword`) and the message is still answered, as a typed STOP is. |
| Delivery to the AI | ONE `ai.reply` event, the same shape the webhook emits for text, plus `origin: voice` and `voice: {media_id, seconds, chars}`. Emitted inside the same database transaction that marks the row `understood`, guarded by `status <> 'understood'`, so a retry or a duplicate event can never emit a second one. |
| Everything after | the existing worker: identity, history, brain, `ReplyPrivacyGuard`, plan fence, kit tax note, lead marker, escalation, audit — unchanged. The transcript is customer content under rule 7 of the system prompt. |

## 5. Failure handling — a safe hand-over, never an invented answer

| Failure | Classified as | What happens |
|---|---|---|
| provider slow or 5xx | `timeout` / `provider_error`, retryable | row `failed` with the code; the event is retried with the EventBus backoff (10 s, 30 s, 5 min, 30 min, 2 h); each retry fetches the audio again |
| retries exhausted | dead | row `dead`; the conversation is handed to a person through the SAME path AiReplyWorker uses: `needs_human`, a `wa.escalation` event, the staff alert, and the operator's `ai_handover_message` to the customer if one is configured and was not just said |
| no provider configured, audio too long, unreadable audio, nothing recognised | permanent | row `failed` with the code; the same hand-over at once; **no `ai.reply` event, so the brain never sees a guess** |
| transcript empty after normalisation | `empty_transcript`, permanent | the same |

The customer therefore never receives an AI reply built on a transcript the system could not obtain. What they receive
on failure is exactly what they receive today when the AI cannot answer a typed message: the operator's own holding line,
or nothing, while a person is alerted.

## 6. Size, time and cost assumptions (to be measured when a provider is chosen)

| Assumption | Working figure | Why it matters |
|---|---|---|
| Voice note length | 5–60 s typical; 120 s cap | the duration cap bounds cost and latency per message |
| Payload | 20–80 KB per 10 s of Opus | well inside every provider's upload limit (25 MB is the common ceiling) |
| Provider latency | 2–10 s for a 30 s note | the media worker's budget is 45 s per run; one note per run is the safe assumption, the timeout (default 30 s, range 5–120) bounds the wait |
| Cost | hosted speech-to-text is priced per audio minute, in the order of a cent per minute; a 30 s note is well under a cent | a flat per-note budget can be stated once the provider is chosen |
| Data leaving the server | the audio goes to the provider; its retention and training terms must be read and approved by the operator before a provider is switched on | this is the decision Batch 2 does NOT take |
| Failure rate | unknown until measured | the retry and hand-over path above is designed to be exercised, not exceptional |

Candidates the operator may consider later, none chosen: a hosted speech-to-text API from the same vendor whose key the
brain already holds (one credential, one contract), or a self-hosted open-source model on the server (no audio leaves the
host; CPU time and model quality to be measured). The adapter for either is one class implementing `TranscriberPort`.

## 7. Test strategy (what Batch 2 proves without any provider)

- **Deterministic fake** (`FakeTranscriber`): answers from a script keyed by the audio's sha256 — a transcript, or a
  scripted failure (`timeout`, `provider_error`, `invalid_audio`, empty). It records only sha256 prefixes and counts,
  never bytes. The CLI runner can use it only when the environment names its script file; production cannot.
- **Fake Evolution** (Batch 1): serves the audio bytes, records every fetch.
- **Fake brain** (`B0FakeBrain` from Batch 0): a canned answer parsed by the real marker parser, so the whole existing
  reply path runs — context, guard, send, store, escalate — without a model call.
- Proved: the happy path end to end; exactly one transcription and one `ai.reply` per message across duplicate events
  and retries; the label and the prompt block; STOP on the transcript; the guard blocking a reply the transcript induced;
  every failure class and the hand-over; nothing on disk, nothing in logs; both flags off → nothing; `ai_media_enabled`
  off wins over `ai_media_voice`; weakened copies of each invariant caught.
- When a real provider is chosen: the same tests run against the adapter with the provider stubbed at HTTP level, plus
  one measured session on a disposable number with the operator's consent, before any flag is set in production.

## 8. What this document does not decide

The provider. The language hint. The cost ceiling. Whether audio may leave the server at all. Each is the operator's,
and each is a one-class change behind the interface above when it is taken.
