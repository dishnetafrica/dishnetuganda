# 58 — Document processing: the Batch 4 boundary review — DESIGN ONLY, before any code (2026-10-05)

**Status: DESIGN APPROVED 2026-10-05 with decisions D-1…D-11 (§B, the operator's choices recorded in §19); Slice 4a BUILT the
same day in the development schema only (5.18.79) — see §19. NOT deployed, NOT pushed; no migration, no library, no provider,
no flag on anywhere.** The design below is the one that was approved; §19 records what the build did and the three places it
sharpened the design. Batches 1–3 (`ce16324`, `73ae827`, `b08c9d4`) are unmodified. This document answers the operator's
fifteen questions for Batch 4 — the documents a customer sends over WhatsApp: PDF, Word, Excel, CSV, plain text — and ends
with the recommended scope (A), the decisions only the operator can take (B), what is prohibited (C) and the test plan (D).
Where it states a number it is a proposed default to be confirmed, never a measurement; where it states a fact about the
code it names the file. It was written after reading `docs/55`, `docs/56`, `docs/57`, the Batch 1 media contracts
(`lib/MediaPolicy.php`, `lib/MediaBlob.php`, `lib/InboundMedia.php`, `lib/MediaFetcher.php`, `workers/MediaWorker.php`,
`run_media_worker.php`, migration 085), the Batch 2 voice contracts (`lib/Transcriber.php`, `lib/VoiceTranscription.php`,
`lib/Handover.php`), the Batch 3 image contracts (`lib/ImageDescriber.php`, `lib/ImageUnderstanding.php`,
`lib/PaymentEvidence.php`), the webhook and conversation store (`evo_webhook.php`, `lib/ConversationService.php`), the
assistant, guard and audit path (`workers/AiReplyWorker.php`, `lib/BrainContext.php`, `lib/DishNetAiBrain.php`,
`lib/ReplyPrivacyGuard.php`) and every existing file or document handling path in the plugin (§2).

## 0. The one-paragraph answer

Batch 4 should extract documents **inside the plugin process, deterministically, with no library, no provider and no byte
leaving the server**, for the formats that can be read that way (Word `.docx`, Excel `.xlsx`, CSV, plain text), and should
**classify and route every document before anything reaches the assistant**: seven classes are human-only (payment proof,
statement, invoice, contract, quotation, identity document, credential) and never produce an AI turn; two (general,
spreadsheet) enter the EXISTING assistant exactly as a transcript or an image description does — one labelled `ai.reply`
event, a sixteenth `BrainContext` key, a conditional prompt block, the same guard, hand-over and audit. PDF text extraction
and OCR are each one decision away (§13, B) and are **not** in the recommended first slice: in it a PDF is read for its
facts (encrypted? scanned? how many pages?) and handed to a person with those facts. A document containing payment
evidence follows the Batch 3 rule unchanged: evidence, a person, nothing recorded.

## 1. Where the boundary sits

```
customer document (WhatsApp, Evolution: documentMessage / documentWithCaptionMessage)
  → evo_webhook.php            stores "[DOCUMENT]" or the caption as always; with ai_media_enabled on, records the wa_media
                               row (kind=document, mimetype, file_name, caption, declared_bytes) and queues ai.media
                               (Batch 1, unchanged). With ai_media_document on as well, a CAPTIONED document is NOT also
                               queued as a typed turn: the document turn — or the hand-over — answers it once (the Batch 3
                               step-9a rule, extended to documents). STOP on the caption stays the webhook's (step 8b).
  → MediaWorker                fetches the bytes into memory (getBase64FromMediaMessage), announced and actual size against
                               ai_media_max_bytes, the reported type against MediaPolicy::ALLOWED['document'], sha256
                               (Batch 1, unchanged)
  → DocumentExtraction         documents only, and only with ai_media_document on:
       1  sniff      the CONTENT decides the kind (PDF / OOXML / OLE2 / text) — never the label, never the file name
       2  refuse     encrypted, macro-enabled, legacy binary, nested or oversized archives, non-document bytes, over the caps
       3  extract    LOCAL, bounded, in memory: paragraphs and tables (docx), cells (xlsx, csv), lines (txt);
                     PDF: facts only in the first slice (§13 decides text and OCR)
       4  classify   deterministic words rules — this plugin's, like PaymentEvidence — into one of nine classes
       5  route      human-only class → the record (per its retention class) + Handover; NO ai.reply, NO brain
                     brain-eligible class → ONE ordinary ai.reply event, labelled, origin=document
        ┌───────────────────────────────────────────────────────────────────────────────────────────┐
        │  DocumentOcrPort::ocr(MediaBlob $doc, array $hints, int $timeoutSeconds)   ← THE ONLY       │
        │  PROVIDER BOUNDARY: scanned documents; NOT implemented in the first slice; provider `none`  │
        └───────────────────────────────────────────────────────────────────────────────────────────┘
  → AiReplyWorker → DishNetAiBrain → ReplyPrivacyGuard → Evolution          (the existing text path, unchanged in kind)
```

Everything above the OCR box is this plugin's code, runs without a network and is tested without a model. The box is empty
by design: `ai_document_provider` has one value, `none`, and the deterministic fake exists only for the tests.

## 2. What exists today (measured)

- **A document is stored, never read.** `ConversationService::importEvoMessage` stores `[DOCUMENT]` or the caption with
  `media_type = 'document'`; the webhook answers the caption alone as text (`evoExtractText`); the file is never fetched.
  With `ai_media_enabled` on (Batch 1 — off everywhere) the message is also recorded in `wa_media` and the worker fetches
  it, records size, type and sha256, and stops at `fetched`: no understanding, no reply (`docs/55` §12, DOCUMENT row).
  Migration 085 already reserves `understanding_kind = 'extraction'` for this batch.
- **Seven announced types are fetched today** (`MediaPolicy::ALLOWED['document']`): `application/pdf`,
  `application/msword`, `…wordprocessingml.document`, `application/vnd.ms-excel`, `…spreadsheetml.sheet`, `text/csv`,
  `text/plain`. Anything else is `unsupported_mime` at the fetch and is never kept. `InboundMedia::fromEvoMessage` keeps
  the announced `mimetype`, `fileName` (documents only), `caption` and `fileLength`; it reads no page count and no title.
- **The OUTBOUND document path is unrelated and untouched.** The assistant can send a document from the operator's own
  media library (`AiReplyWorker` sends through `MediaLibrary::findDocument` and `EvolutionApiService::sendMedia`, echo
  claimed as `ai.document`). It reads files staff placed in the plugin's folder; it never reads a customer's document, and
  Batch 4 does not touch it. The event name `ai.document` is therefore TAKEN: Batch 4 uses `origin: 'document'` on the
  ordinary `ai.reply` event, as voice and image do, and introduces no event type.
- **Existing file handling elsewhere in the plugin** — what Batch 4 may NOT reuse and must stay apart from:

  | Path | What it does | Where files live, who reads them, for how long | What Batch 4 takes from it |
  |---|---|---|---|
  | `lib/JobPhotos.php` (migration 084) | staff upload job photos: labels from a fixed list, an 8 MB cap, `getimagesize` must say JPEG/PNG/WebP, GD re-encodes, a server-built name, sha256 on the row | `uploads/job_photos/<job>/`; a role-checked route with `realpath` containment and `nosniff`; kept until deleted | the pattern — validate by content, cap, derive the name, record the hash — and nothing else: Batch 4 stores no file |
  | `lib/KycService.php`, `includes/post/post_kyc.php`, `includes/api/api_support.php` | ID and photo intake for KYC applications: a `finfo` allow-list (JPEG/PNG/GIF/WebP/PDF) in the service, weaker checks in the two upload closures; GD recompression; a `_meta.json` sidecar | `kyc_uploads/YYYY-MM/…` and `kyc_photos/`; the `?page=kyc_photo` route for four roles; kept indefinitely; a queued job can push to uCRM Documents | the destination of an identity document is THIS human process: Batch 4 never writes there, never reads there, and never decides anything about an application |
  | `lib/MediaLibrary.php` | the documents and photos the assistant may SEND: the one `%PDF-` magic check on an upload, 10 MB for PDFs, names rebuilt from a key | `photos/`, `documents/`; kept until removed | nothing — it is the outbound side |
  | `lib/ExpenseAdvanceService.php`, `lib/RechargeService.php`, the cash and install photo uploads | staff receipts and proofs; validation from an extension plus `finfo` match with an 8 MB cap down to none at all | `uploads/expense_receipts`, `uploads/…`; role-served; kept indefinitely | nothing; recorded because an uneven upload surface is the opposite of what Batch 4 builds |
  | `includes/api/api_whatsapp.php` `wa_upload_media` | staff attach a file to an outbound WhatsApp message: an extension check only, 16 MB | `uploads/wa/`; served to any logged-in user | nothing |
  | invoice, quote, delivery-note and receipt PDFs | fetched from uCRM (`%PDF` checked) or rendered (an external Chromium service, `wkhtmltopdf` as the fallback) and sent through `NotificationService::sendDocument` → Evolution `sendMedia` | `temp_pdf/` by token for 10 minutes; quotes, delivery notes and receipts kept | nothing — outbound |
  | `lib/XlsxReader.php` | an UNUSED `ZipArchive` + `simplexml` spreadsheet reader: no caller, no `LIBXML_NONET`, no entry or size cap | — | **not reused** — it needs a path, so a temporary file, and has no limits; left untouched, and Batch 4's reader carries a different name (`SheetReader`). Its removal is housekeeping for another day |
  | `tools/pdf_template_doctor.php` | a CLI tool that pulls `Tj`/`TJ` text out of Flate streams with `gzuncompress` / `gzinflate` | — | a precedent for P-1 (§13): PDF text without a library already exists here at tool grade, not production grade |
  | `lib/StarlinkInvoiceImport.php`, `tools/import_starlink_invoices.php` | Finance's CLI import parses `pdftotext -layout` output | `pdftotext` is **not in the uCRM image** and does not survive a container rebuild (`docs/UGANDA-PROVISIONING-ORDER.md`, "Container dependency: poppler-utils") | the measured fact behind P-3: on-host PDF tooling means a container of its own, not the uCRM image |
  | `lib/JmapMailbox.php`, `lib/EmailIntentClassifier.php` | inbound e-mail attachments are never opened: their names are listed, "an attachment … is always a reason for a person", and the prompt tells the assistant to acknowledge them and not guess what is inside | — | the precedent Batch 4 follows for every human-only class |
  | the restore and plugin-update zips (`includes/post/post_sync.php`, `includes/post/post_admin.php`) | admin-only `ZipArchive` reads with no size or ratio cap and a partial path filter | — | nothing — recorded as an observation for the operator, not Batch 4's to change |

  Two facts from that inventory bind the design. **No inbound WhatsApp document is content-sniffed today**: `MediaFetcher`
  trusts the announced type, and `test_media_foundation` proves a plain string labelled `application/pdf` is accepted as
  `fetched` — so Batch 4's sniff (§3) is the first content check a customer's document ever meets, as Batch 3's header
  read was for pictures. And **no file a customer sends is ever written to disk**, which Batch 4 keeps: nothing of a
  document can reach the Drive backup, which zips the data directory's upload folders (`lib/GoogleDriveBackup.php`).

- **The uCRM container's PHP runtime, as far as the repository records it:**

  | Fact | Evidence | Status |
  |---|---|---|
  | PHP **8.1.34** in the uCRM container; the source stays PHP 7.4 syntax | `SUDAN-EDITION.md`; `docs/07` (3 Oct); the pinned deploy scripts measure it | recorded |
  | the container is Alpine-based | `apk add` is the documented way to add `poppler-utils` to it | implied |
  | GD present | probed 2026-10-03 (`imagecreatefromjpeg`), `docs/07` | recorded |
  | `curl`, `pdo_sqlite`, `json`, `mbstring` | required by `tests/validate_environment.php`; used everywhere | required |
  | `fileinfo`; `simplexml` / `libxml` (with `LIBXML_NONET` in `lib/DpoClient.php`) | used on live paths without a guard | implied, not recorded |
  | `ZipArchive` | behind `class_exists` in some places, bare in others | optional in the code's own eyes — **unknown** |
  | `zlib` (`gzinflate`, `gzuncompress`) | used only by a CLI tool | **unknown** |
  | `XMLReader`, `DOMDocument` | **used nowhere in the plugin** | **unknown** |
  | `iconv` | used once, suppressed | **unknown** |
  | `pdftotext` / poppler, tesseract, LibreOffice, Ghostscript, ImageMagick | poppler documented as absent from the image; tesseract exists only as browser-side `tesseract.js`; the others have no record | **absent or unknown** |
  | the container's `php -m` | **never recorded** anywhere in the repository — the only `php -m` records are Domain B's staging image | **E-1** |
  | the CLI `memory_limit` the media runner inherits | not recorded; `run_media_worker.php` sets none; `workers/bootstrap.php` sets 256M but nothing loads it | **E-2** |

  Measured in THIS sandbox only (PHP 8.4, not the server): `zip`, `zlib`, `dom`, `xml`, `xmlreader`, `simplexml`,
  `mbstring`, `iconv`, `fileinfo` and `gd` loaded; `imagick` absent; no `pdftotext`, `tesseract`, `ghostscript` or
  `qpdf` binary. That says the test fixtures can be built and the readers exercised here; it says nothing about the
  server. The extractor must probe at run time (`function_exists('gzinflate')`, `class_exists('XMLReader')`,
  `function_exists('iconv')`) and refuse with `extractor_unavailable` rather than assume (§6.1).
- **The sibling plugin:** `dishnet-ai` has no document or PDF processing, no OCR and no document-AI call; it stores captions and placeholders and calls text-only chat endpoints. Nothing to reuse, nothing to keep consistent with.

### 2.1 Two live-path defects Batch 4 must fix first (measured, flag-independent)

- **D-A — a captioned document inside `documentWithCaptionMessage` is dropped before it is stored.**
  `ConversationService::importEvoMessage` reads the caption and sets `media_type = 'document'` only for a TOP-LEVEL
  `documentMessage`; the wrapper carries both one level down, so the body is empty and the type null, and the method
  returns null at "truly empty — skip". The webhook then logs *"message was not stored — not recorded for the media
  worker"* and `evoExtractText` has no wrapper case either, so no text turn is queued. `InboundMedia`'s own unwrap of
  that wrapper is therefore **unreachable on the live path** for this shape; it is exercised only by the unit test.
  `docs/55` already names the wrapper gap. Consequence today, with every flag off: a customer who sends a receipt with a
  caption may leave **no trace in the Inbox at all**. The fix is small and belongs to the conversation store and the
  webhook's text extraction, not behind any flag — a message that is not stored cannot be recorded for any batch:
  unwrap the same four wrappers `InboundMedia` knows (`documentWithCaptionMessage`, `ephemeralMessage`,
  `viewOnceMessage`, `viewOnceMessageV2`) in both places, store the caption with `media_type = 'document'`, and read
  the caption for STOP and the text turn. Whether Evolution 2.3.7 actually sends this wrapper is **not recorded**
  (E-6, §18); the fix is inert if it never does. Because it changes the live text path for one message shape, it is
  the operator's call (D-11): fix it as a flag-independent defect in the Batch 4 commit (recommended, as Batch 0 fixed
  the three lead-path defects), or first capture one real envelope on staging.
- **D-B — `InboundMedia::fromEvoMessage` unwraps ONE level** (`break` after the first wrapper), so an
  `ephemeralMessage` wrapping a `documentWithCaptionMessage` returns null and is never recorded. Fix: unwrap up to
  three levels, same list, with a fixture for the nested shape.

Both are proved by the webhook test's envelope fixtures, both are independent of `ai_media_document`, and neither
changes a message that is stored correctly today.

## 3. Question 1 — supported document types

| Announced / sniffed | In Batch 4 | How | Not supported, and why |
|---|---|---|---|
| **PDF** (`%PDF-` header) | **facts** in the first slice: encrypted? page count; a text layer or image-only pages? Then a person, with those facts. **Text layer**: read in this process since Slice 4b (D-1 = P-1, §20) — FlateDecode, object streams, simple fonts and ToUnicode CMaps; anything else, and a layer that yields no text, is a person. **Scanned pages** only after D-2 (OCR). | in-process | — |
| **Word `.docx`** (a zip with `[Content_Types].xml` and `word/document.xml`) | **yes** — paragraphs, headings and table cells, in document order | in-process: a minimal zip reader + `XMLReader` | headers, footers, footnotes, comments, tracked-change deletions, field codes, embedded objects and images are never read |
| **Excel `.xlsx`** (a zip with `xl/workbook.xml`) | **yes** — sheet names and cell values (cached values; shared and inline strings) | in-process | formulas are never evaluated; charts, pivot caches, external links, embedded objects are never read |
| **CSV** (`text/csv`, or text that parses as separated values) | **yes** — rows and cells; delimiter sniffed among `,` `;` and tab | in-process | — |
| **Plain text** (`text/plain`) | **yes** — lines | in-process | — |
| Legacy **`.doc` / `.xls`** (OLE2 magic `D0 CF 11 E0 A1 B1 1A E1`) | **no** — recorded and fetched as Batch 1 does today, then `unsupported_document` and a hand-over asking the customer for a PDF | — | binary OLE2 needs a library or a converter, and macros live there; D-5 asks whether Batch 1 should stop fetching them at all |
| Macro-enabled **`.docm` `.xlsm` `.xlsb` `.dotm` `.xltm`** | **no** — `unsupported_document` from `[Content_Types].xml` (the `macroEnabled` content types), whatever the label says | — | nothing here would ever run a macro; refusing them keeps the surface small and the rule plain |
| PowerPoint, OpenDocument, RTF, HTML, XML, JSON, EPUB | **no** — not in `ALLOWED`; refused at the fetch | — | no customer use case; each is its own parser |
| Archives (`.zip` `.rar` `.7z` `.gz` `.tar`), executables and scripts (`.exe` `.apk` `.js` `.vbs` `.bat` `.sh` `.jar` `.msi`), disk images | **no** — not in `ALLOWED`; a zip behind a Word label is refused by the sniff (no `[Content_Types].xml`, or one that is not Word or Excel) | — | nothing is ever executed or unpacked except the named OOXML members |
| Encrypted or password-protected anything | **no** — `password_protected`; a person; **never a prompt for the password** | — | §7 |
| An image sent *as a document* (a JPEG under a `.pdf` name, a PNG as `application/octet-stream`) | **no** — the sniff says image, the kind says document → `unsupported_mime`; a person | — | pictures enter through `imageMessage` and Batch 3; a photographed document stays `document_photo`, described only (`docs/57` §4) — Batch 4 does not OCR images |

**The content decides, never the label.** A file announced as PDF whose bytes do not begin `%PDF-` is not a PDF; a
`.docx` whose zip has no `word/document.xml` is not a Word document. The file name is a hint for the person reading the
alert — like the kind and the size — and is never an input to extraction, to classification (beyond being a signal, §6.4)
or to the model (§12).

## 4. Question 2 — limits

| Limit | Proposed default | Range / rule | Where enforced | Why this value |
|---|---|---|---|---|
| Fetch size, announced and actual | `ai_media_max_bytes` = 15 MiB (Batch 1) | 64 KiB – 64 MiB | `MediaFetcher`, before and after the fetch | unchanged |
| Document size | `ai_media_document_max_bytes` = **10 MiB**; the effective cap is the smaller of this and `ai_media_max_bytes` | 64 KiB – 64 MiB, refused outside | `DocumentExtraction`, before the sniff | the fetch holds Evolution's JSON answer (≈ 1.4 × the file), the base64 string (≈ 1.33 ×) and the decoded bytes at once — about 3.7 × the file — plus the inflated members; 10 MiB keeps the worst case near 60 MiB |
| PDF pages read | `ai_media_document_max_pages` = **20** | 1 – 200 | the PDF reader (D-1) | the first N pages; `pages_total` is recorded; beyond N the extract is `truncated`, not refused |
| Spreadsheet | 10 sheets · 2,000 rows scanned per sheet · 20,000 cells in all · a cell's text cut at 200 characters · the turn shows at most 40 rows × 12 columns of at most 2 sheets | constants `MediaPolicy::DOCUMENT_*`, not settings | the xlsx and csv readers | a chat reply does not need a ledger; the caps bound time and memory whatever the file declares |
| Text read (txt, csv) | 1 MiB | constant | the text reader | beyond → `truncated` |
| Text scanned for classification | 50,000 characters of the uncut extract | constant | the classifier | enough to find a receipt line on page three; never stored |
| Text given to the assistant | **4,000 characters**; the label says `truncated` when cut | constant `DOCUMENT_MAX_BRAIN_CHARS` | `DocumentExtraction::complete` | the voice cap is 4,000 and the image cap 2,000; a document turn is the customer's message, not a corpus |
| Evidence excerpt kept for a human-only class | 300 characters; digit runs of 6 or more and e-mail addresses masked | constant | the record (§11) | orientation for the person; the original stays in WhatsApp |
| Extraction time | `ai_media_document_timeout_s` = **20 s** | 5 – 60 s; a deadline checked between bounded steps: per zip member, per 1,000 XML nodes, per 200 rows, per PDF object | a deadline object passed through every reader | PHP cannot interrupt a loop, so the steps are bounded and the deadline is checked between them. Exceeding it is **permanent** (`too_slow`): a deterministic extraction that was slow will be slow again — unlike a provider timeout it is not retried |
| OOXML archive | ≤ 2,000 central-directory entries · only an allow-list of member names is ever opened (`[Content_Types].xml`, `_rels/.rels`, `word/document.xml`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/sharedStrings.xml`, `xl/worksheets/sheet*.xml`) · a member's DECLARED uncompressed size ≤ 16 MiB or it is refused before inflating · inflation through `gzinflate($raw, 16 MiB)`, so zlib stops at the cap and no partial gigabyte string is ever built · declared ≠ actual → `malformed_document` · zip64 fields, encrypted-entry flags and a member that is itself an archive → refused · ≤ 48 MiB inflated across the members read | constants | the minimal zip reader (§6.2) | an archive bomb is refused by its declared size, by zlib's cap and by the allow-list — in that order, each independent |
| PDF streams (D-1) | one stream inflated ≤ 8 MiB (`gzuncompress($raw, 8 MiB)`) · 64 MiB in all · ≤ 50,000 objects · reference depth ≤ 32 · `/Prev` chain ≤ 64 · page-tree depth ≤ 32 | constants | the PDF reader | the same bomb and loop defences; a PDF that needs more is `unreadable` → a person |
| Memory | `run_media_worker.php` states its own limit (`ini_set('memory_limit', '256M')`, as `workers/bootstrap.php` already does — for a runner nothing requires); the extractor compares the worst case (file × 3.7 + 16 MiB) with the remaining budget and refuses rather than fail half-way | measured at run time | `run_media_worker.php`, `DocumentExtraction` | this sandbox's CLI runs with no limit; the server's CLI limit is **not known** (E-2, §18) |

Nothing is clamped silently: as Batch 1 does, `tools/set_config.php` refuses a value outside its range.

## 5. Question 3 — processing location, and exactly what leaves DishNet

| Step | Where it runs | What leaves DishNet infrastructure |
|---|---|---|
| fetch | the plugin, inside the uCRM container, from Evolution on the same host | nothing — Evolution is DishNet's |
| sniff · refuse · extract · classify · route | **the plugin process: pure PHP, no library, no sidecar, no network call** | **nothing** |
| OCR of a scanned page | **not in the first slice**; when wanted, behind `DocumentOcrPort` (§13) | nothing until a provider is chosen — and the choice is the operator's |
| a human-only class (payment proof, statement, invoice, contract, quotation, identity document, credential) | the record and the hand-over, in the plugin | **nothing — not even to the assistant's model** |
| a brain-eligible class (general, spreadsheet) | the EXISTING assistant: `AiReplyWorker` → `DishNetAiBrain` | **the labelled, capped extract (≤ 4,000 characters) and the caption go to the model vendor the brain already uses** — `api.anthropic.com` or `api.openai.com`, by `ai_provider` (`lib/DishNetAiBrain.php`) — exactly as a typed message, a transcript and an image description already do. Not the file, not its name, not the full text, and no fact beyond the three leaves in §12 |

So the only egress Batch 4 adds is the one the plugin already has for every customer message, and only for the two classes
judged safe to answer. In the recommended scope no document, no page image and no OCR output leaves the server. There is
no external extraction provider and no external AI provider beyond the brain's own.

## 6. Question 4 — extraction architecture

### 6.1 Deterministic and local first

Order of preference, binding: (1) the content's own text, read by this plugin's code; (2) OCR only where there is no text
to read, only through the boundary in §13, only after the operator's decision; (3) never an external document service
because it is easier. The existing assistant is the only AI in the path; Batch 4 adds no second brain and no summariser —
the extract IS the customer's turn, labelled.

Run-time prerequisites are probed, never assumed: `gzinflate` (zlib) and `XMLReader` (xml) for OOXML, `iconv` or
`mb_convert_encoding` for re-coding text. Missing → `extractor_unavailable`, permanent, a person.
`tests/validate_environment.php` and the doctor report the extensions when the document flag is on (today the validator
requires only `curl`, `pdo_sqlite`, `json`, `mbstring`).

### 6.2 Readers (names proposed)

| Reader | Input | Output | Notes |
|---|---|---|---|
| `DocumentSniffer` | the bytes | kind (`pdf` · `docx` · `xlsx` · `csv` · `txt` · `ole2` · `unknown`) and facts | magic bytes first (`%PDF-`, `PK\x03\x04`, the OLE2 signature); text by a printable-ratio test and a valid-UTF-8 or Windows-1252 test with no NUL bytes; OOXML by `[Content_Types].xml`, where the `macroEnabled` types are refused |
| `OoxmlArchive` | the bytes | the named members, inflated under the caps | a minimal zip reader — central directory, local headers, stored and deflated members — written for this purpose so that **no temporary file is needed** (`ZipArchive` opens paths), zip64 and encrypted entries are refused and every limit in §4 is explicit. About 200 lines, fully testable with fixtures the test writes itself |
| `DocxReader` | `word/document.xml` | paragraphs; table rows as tab-separated cells; in order | `XMLReader` streaming with `LIBXML_NONET`, entity substitution OFF, no DTD loading; any `<!DOCTYPE` → `malformed_document` (OOXML never carries one, and a DTD is how billion-laughs and XXE arrive); `w:t` text, `w:p` → newline, `w:tab` → tab; `w:delText`, `w:instrText`, comments, footnotes and headers are never read |
| `SheetReader` | `xl/workbook.xml`, its rels, `xl/sharedStrings.xml`, `xl/worksheets/sheetN.xml` | per sheet: the name and rows of cell text | shared (`t="s"`), inline (`inlineStr`) and literal values; `<f>` ignored, only the cached `<v>`; numbers as written (a date serial is shown as its number — a limitation the label does not hide); caps per §4 |
| `TextReader` | the bytes | lines (txt) or rows (csv) | BOM stripped; UTF-8 validated, else re-coded from Windows-1252; `str_getcsv` per line with the sniffed delimiter; control characters out |
| `PdfReader` | the bytes | first slice: facts — `encrypted` (an `/Encrypt` entry in a trailer or xref stream dictionary), `pages_total` (page objects counted, `/Count` as a hint), `has_text_layer` (a `BT` operator or a `/Font` resource in the first pages' content), `image_only` (only `/Subtype /Image` XObjects drawn). After D-1: page text | the facts need no decompression beyond the first pages' content streams, each inflated under the §4 caps |
| `DocumentOcrPort` + `FakeDocumentOcr` | a scanned PDF's bytes | text per page, or a fixed reason with a retryable flag | **the boundary of §13, with no implementation but the deterministic fake, keyed by sha256 as the voice and image fakes are** |

### 6.3 Normalisation

`DocumentExtraction::normalise()`: control characters out, whitespace collapsed, paragraphs kept as single newlines,
table rows rendered as ` | `-separated cells, each sheet as `Sheet "<name>" (R rows, C columns)` followed by at most
40 × 12 cells, the whole cut at the brain cap with `…` and `truncated: true`. The classifier reads up to 50,000
characters of the uncut text; only the normalised, capped text is ever stored or sent — and only for the two
brain-eligible classes.

### 6.4 Classification — deterministic, this plugin's, like PaymentEvidence

Nine classes, decided in this precedence, the first match wins: `credential` → `identity_document` → `payment_proof` →
`statement` → `invoice` → `contract` → `quotation` → `spreadsheet` (by kind, when nothing above matched) → `general`.
Outcomes that are not classes: `encrypted`, `scanned` (a PDF with no text layer), `unreadable` (nothing left after
normalisation, or a reader gave up), `unsupported`.

| Class | Rule — words on the extracted text; the sheet names and the file name count as SIGNALS only | Route | Record kept (§11) | Hand-over reason (the operator's words to review, D-8) |
|---|---|---|---|---|
| `credential` | any `ReplyPrivacyGuard` SECRET_SHAPES hit — API key, bearer token, private key, JWT, connection string, `password:` / `secret=` / `token:` followed by a value — the shapes the guard already refuses in a reply, made callable | human-only | **none** | "a document containing what looks like a password or key arrived — do not forward it; advise the customer to change it; nothing was stored" |
| `identity_document` | passport · national id · "National Identification" · NIN · date of birth with nationality · "Republic of Uganda" with "identity" · driving permit · refugee card | human-only | **none** | "an identity document arrived — handle it under the KYC process; nothing was decided and nothing was stored" |
| `payment_proof` | `PaymentEvidence::looksLikePayment('general', $text, $signals)` — the Batch 3 rule verbatim: a money token AND a transaction token — with receipt and transaction words in the file name as signals | human-only | masked excerpt | `PaymentEvidence::handoverReason()` — "a payment screenshot or receipt arrived — nothing was recorded or marked paid; verify it against uCRM and the bank or mobile-money statement before touching any invoice" |
| `statement` | "statement" · "opening balance" / "closing balance" · five or more dated rows with amounts · a bank or mobile-money brand word | human-only | masked excerpt | "a bank or mobile-money statement arrived — verify it in the books; nothing was recorded" |
| `invoice` | "invoice" · "amount due" · "due date" · "tax invoice" · "bill to" · a VAT or TIN line | human-only | masked excerpt | "an invoice arrived — check it against uCRM; nothing was recorded or marked paid" |
| `contract` | "agreement" · "contract" · "terms and conditions" · "the parties" · "hereby agree" · "signature" / "signed" · "witness" · "effective date" | human-only | masked excerpt | "a contract or agreement arrived — a person must read it; nothing was accepted or approved" |
| `quotation` | "quotation" · "quote" · "proforma" · "valid until" · "unit price" with "total", and no invoice words | human-only in the first slice (D-3) | masked excerpt | "a quotation arrived — a person decides; no price was promised" |
| `spreadsheet` | kind xlsx or csv with none of the above | brain-eligible | the capped text | — |
| `general` | everything else | brain-eligible | the capped text | — |

The rule errs towards a person, as `docs/57` §4 does: a wrong "human" costs a colleague a look at a file; a wrong
"brain" would have the assistant discussing an invoice, a contract or an identity card. Every outcome that is not a class
(`encrypted`, `scanned`, `unreadable`, `unsupported`, a refusal, a dead letter) is also a person:
"a <kind> could not be read automatically (<reason>) — open it in WhatsApp". The customer hears what they hear today when
the assistant cannot answer: the operator's holding line once, if one is configured, or nothing.

### 6.5 Reason codes

Permanent, a person at once: `provider_missing` (a scanned document and no OCR provider) · `unsupported_mime` ·
`unsupported_document` · `malformed_document` · `too_large_document` · `password_protected` · `pdf_no_text` (Slice 4b: a
PDF whose text layer yielded no text; it replaced `pdf_not_read`, the first slice's placeholder) · `empty_extraction` ·
`extractor_unavailable` · `too_slow` ·
`conversation_missing`. Retryable through the EventBus backoff, then a person: `provider_error` · `timeout` — both exist
only for the OCR boundary; local extraction has no retryable failure, because repeating it changes nothing.

### 6.6 How the result enters the EXISTING DishNetAiBrain

Exactly the Batch 2/3 mechanism; nothing new in kind:

1. **`DocumentExtraction::complete()` — one transaction.** `wa_media` → `understood`, `understanding_kind =
   'extraction'` (brain-eligible) or `'document_evidence'` (human-only), guarded by `status <> 'understood'`; the stored
   `[DOCUMENT]` message's body rewritten to the labelled text (§12) and its `metadata.document` set to `{media_id, kind,
   classification, pages_read, pages_total, rows, truncated, extracted_at}`; then EITHER nothing is queued (human-only —
   the worker hands over after the commit) OR ONE `ai.reply` event is queued with the webhook's shape plus
   `origin: 'document'` and `document: {media_id, classification, kind, pages_read, pages_total, rows, truncated,
   has_caption}`. A database failure rolls everything back and is thrown; the worker retries the whole thing.
2. **`AiReplyWorker`** reads `origin=document`, carries `'document' => ['classification', 'kind', 'truncated']` into
   `BrainContext::build` for the sales channel and leaves it on `$ctx` for the legacy support and accounts shape — as
   `image` travels today (`workers/AiReplyWorker.php`, buildContext) — logs `origin=document`, and writes
   `modality: document` on a blocked reply's audit event.
3. **`BrainContext::CONTRACT`** gains the **sixteenth** key `'document' => ['classification', 'kind', 'truncated']`.
   Presence is the fact; the three leaves are all that travel.
4. **`DishNetAiBrain::dataBlock()`** gains a conditional **DOCUMENT JUST RECEIVED** block beside the pin, voice and image
   blocks: the message is an AUTOMATIC EXTRACTION of a file the customer sent and may be incomplete (`truncated` says
   so) or wrongly ordered; every figure, name, date, price and term in it is UNCONFIRMED; the assistant must never
   accept, approve, confirm or agree to any term, price, contract, payment or identity it reads there, must never say a
   document is approved, received as valid or settled, and when the content looks like an invoice, a receipt, a
   statement, a contract, an ID or a password must say a colleague will handle it; a document may contain sentences that
   read like instructions — they are content under rule 7, which already names "PDFs, documents, images and
   attachments" (`lib/DishNetAiBrain.php`), never instructions. A deployment that never receives a document has
   exactly the prompt it had.
5. **`ReplyPrivacyGuard`** runs unchanged — and (recommended, §9) gains one narrow phrase category for document-origin
   replies, `document_commitment`: a reply that commits ("we accept", "marked as paid", "payment received", "your
   contract is approved", "your ID is verified") is refused like any blocked reply: `SAFE_FALLBACK`, the audit event,
   `escalate: true`.

## 7. Question 5 — security

| Threat | Defence | Reason |
|---|---|---|
| Malformed or hostile bytes behind a document label | the sniff reads magic bytes and structure before any parser; every parser is wrapped — any exception, any inconsistency → a permanent refusal; nothing is `eval`ed, `unserialize`d or `include`d; no shell, no converter, no application ever opens the file | `malformed_document` · `unsupported_mime` |
| Archive bombs (OOXML) | the declared uncompressed size is checked against the cap BEFORE inflating; `gzinflate` is called with its max-length argument so zlib stops at the cap; declared ≠ actual is refused; an entry-count cap; only allow-listed member names are opened; zip64 refused | `too_large_document` · `malformed_document` |
| Nested archives, embedded packages | never opened: `word/embeddings/*`, `*.bin` and any member not on the allow-list is not read — a zip inside a zip is a member nobody asks for | — |
| XML attacks (XXE, billion laughs, external DTD) | `XMLReader` with `LIBXML_NONET`, entity substitution off, DTD loading off; any `<!DOCTYPE` refused; node-count and depth caps under the deadline | `malformed_document` |
| Executables, scripts, installers, APKs | not in `MediaPolicy::ALLOWED` → `unsupported_mime` at the fetch; a fetched file is only ever parsed as data | `unsupported_mime` |
| Macro-enabled Office files | refused by `[Content_Types].xml`; legacy OLE2 (`.doc`, `.xls`, where macros also live) refused by magic | `unsupported_document` |
| Password-protected or encrypted files | a PDF `/Encrypt` entry; an OOXML wrapped in an OLE2 `EncryptedPackage`; a zip entry with the encryption flag → refused; **the assistant never asks the customer for a password** (a person may) | `password_protected` |
| Oversized files | the announced size before the fetch, the base64 length and the decoded size (Batch 1), then the document cap, then the member and stream caps | `too_large` · `too_large_document` |
| Parser slowness, pathological inputs | the deadline checked between bounded steps; the caps bound every loop; `too_slow` is permanent, not retried | `too_slow` |
| Memory exhaustion | the runner's explicit `memory_limit`; the extractor compares the worst case with the remaining budget and refuses rather than fail half-way | `too_large_document` |
| Prompt injection inside a document | the extract is labelled automatic content, the prompt says so under rule 7, the guard checks the reply; a document is never a source of instructions | — |
| Raw document contents in logs | the worker's log lines carry kind, class, counts, a sha prefix and reason codes only (`MediaBlob::describe()`, `safeDetail()`); exception messages never carry text; the test scans every log, event payload and alert for the fixture sentences and secrets | — |
| Temporary files | none: every reader works on strings; nothing is written to disk — the reason the design avoids `ZipArchive` | — |

## 8. Question 6 — privacy, per content

| The document contains | What Batch 4 does | What is kept | What reaches the assistant |
|---|---|---|---|
| IDs, passports | class `identity_document` → a person, under the existing process (`lib/KycService.php` and its queue, `kyc_uploads/`, a person's decision — Batch 4 never writes there, never reads there, and never decides anything about an application); the alert names the class, never a number | **nothing** of the content — class, kind and counts only | nothing |
| Customer personal information inside a general document (names, addresses, phone numbers) | the extract is the customer's own message to us, as a typed message would be; the egress rules are unchanged — `BrainContext` adds no identity the backend did not establish | the capped extract on the row and in the stored message, as a transcript is kept | the capped extract, labelled |
| Bank or payment details | class `payment_proof` or `statement` → evidence and a person (§10) | a 300-character masked excerpt (digit runs of 6 or more and e-mail addresses masked) | nothing |
| Invoices | class `invoice` → a person — "check it against uCRM; nothing was recorded" | masked excerpt | nothing (D-4 asks whether a generic "how to pay" acknowledgement may be sent without reading the document) |
| Contracts | class `contract` → a person — "nothing was accepted or approved" | masked excerpt | nothing |
| Quotations | class `quotation` → a person in the first slice — "no price was promised" | masked excerpt | nothing (D-3) |
| Credentials, API keys | class `credential` → a person; the alert says a document with what looks like a password or key arrived, do not forward it, advise the customer to change it; **the value is never stored, logged, sent or repeated** | **nothing** | nothing |

The masked excerpt exists so that the person reading the Inbox knows what kind of thing arrived before opening WhatsApp;
the original stays in WhatsApp, where it already is. D-7 asks whether even the excerpt is wanted.

## 9. Question 7 — the human-only rules, and how each is enforced

Document understanding must never: mark an invoice paid · create a payment · alter a cashbook or ledger · approve a
contract · accept contractual terms · create a financial record · modify a customer's account balance · make an identity
or KYC decision · expose a credential.

| Layer | Enforcement |
|---|---|
| **Routing before any event** | the seven human-only classes produce NO `ai.reply`; the assistant never sees them. Deterministic plugin code, tested without a model, and the first thing a weakened copy must break to reach the brain |
| **No writer in reach** | `DocumentExtraction`, the readers, the classifier and `MediaWorker` reference no payment, invoice, ledger, cashbook, KYC or CRM writer — no `CrmApiClient`, `DpoPaymentService`, `CashbookService`, `KycCrmSync`, `CustomerIdentityService` — pinned by a comment-free source check; the media worker gains no table write beyond `wa_media` and `wa_messages` |
| **Proved by execution** | every financial table (the Batch 3 list: `dpo_payments`, `dpo_payment_events`, `cb_ledger`, `staff_ledger`, `wallet_transactions`, `cash_advances`, `expense_receipts`, `stock_purchase_payments`, `lte_financial_ledger`, …) and every KYC or identity table counted before and after a receipt, a statement, an invoice, a contract and an identity document are processed; the fake uCRM's payment and client counts unchanged |
| **The assistant as a second line** | the DOCUMENT block forbids accepting, approving, confirming or agreeing to anything read in a document and orders a hand-over for anything financial, contractual or identity-related |
| **The guard as a third line** | the existing checks (amounts the tools did not return, identifiers, secrets, internal phrases) plus the recommended `document_commitment` phrases on document-origin replies |
| **Evidence, not truth** | `understanding_kind = 'document_evidence'` is a record for a person to compare against the books and the CRM; nothing reads it as a fact about money, a contract or an identity |
| **Credentials** | detected first, stored nowhere, repeated nowhere — the alert names the class, never the value |

## 10. Question 8 — payment evidence in a document

**Yes: the same boundary, unchanged.** `PaymentEvidence::looksLikePayment()` (Batch 3) decides for documents as it does
for pictures — on the extracted text and signals, with the provider's class replaced by the document class. A yes means
`understanding_kind = 'document_evidence'`, no `ai.reply`, and `Handover::escalate()` with
`PaymentEvidence::handoverReason()` — the alert that says nothing was recorded or marked paid. The differences are only
these: a document yields more text than a vision description, so the words rule fires more readily (which errs the right
way); the record is a masked excerpt rather than the full text; and in the first slice a PDF receipt reaches the person
as `pdf_not_read` or `scanned`, with the same wording family, because its text is not read until D-1. A `statement` and
an `invoice` are routed the same way with their own reasons.

## 11. Question 9 — retention

| Material | Kept? | Where | How long |
|---|---|---|---|
| The original document (bytes) | **never** — in memory for one worker turn, `MediaBlob::wipe()` in `finally`; no temporary file, no cache | — | — |
| Inflated members, page streams, OCR output | **never** — strings freed with the turn; OCR does not exist in the first slice and inherits this rule when it does | — | — |
| Extracted text, brain-eligible class | the capped text the assistant was given (≤ 4,000 characters) | `wa_media.understanding`, the stored message body, the `ai.reply` payload | as long as the conversation — `wa_messages` has no retention job today — and the events table — `EventBus::prune()` exists and **has no caller** (E-4): the lifetime a typed message already has |
| Extracted text, human-only class | a 300-character masked excerpt; nothing for `identity_document` and `credential` | `wa_media.understanding`, the stored message body | as the conversation |
| Facts (kind, class, pages, rows, sha256, size, reason codes) | yes | `wa_media`, `wa_messages.metadata.document` | as the row |
| Logs | counts, codes, sha prefixes | `ai_platform.log` | as today |

No new persistence is introduced beyond what Batches 2 and 3 already do for a transcript and a description; two classes
persist less than that (nothing), five persist a masked excerpt. The open retention question is the existing one:
`wa_messages` and `events` keep customer text indefinitely. Batch 4 does not widen it; it inherits it (E-4).

## 12. Question 10 — exactly what enters BrainContext

The message — the customer's turn — is:

```
[document, text extracted automatically — Word document, 2 pages read of 2]        (or "…, truncated")
<the normalised extract, at most 4,000 characters>
[caption from the customer] <the caption, when there was one>
```

and the sixteenth contract key is `document => ['classification' (§6.4), 'kind' (pdf · docx · xlsx · csv · txt),
'truncated' (bool)]`. Nothing else: **not** the file name (often a person's name or an account number), not `media_id`,
not the sha256, not page or row counts beyond what the label says in words, not the conversation id, not the phone, not
the push name, not the provider. `BrainContext::NEVER_PRESENT` gains `file_name` with its reason. The existing rule —
internal conversation and customer identifiers never reach the model — holds by construction: `build()` constructs, it
does not filter, so a key not written does not travel, and a test asserts the context's JSON contains none of the
identifiers above.

## 13. Question 11 — the provider boundary

**The recommended first slice proposes NO external AI, OCR or document provider.** The one boundary it declares is
`DocumentOcrPort` (§6.2), with `ai_document_provider = none` as the only value and the deterministic `FakeDocumentOcr`
for the tests — the Batch 2/3 pattern — so that a scanned document already has a defined fate (`provider_missing` → a
person) and the day a provider is chosen it is one class behind an interface.

Should the operator want scanned documents read, these are the options, none chosen:

| | P-3 local OCR sidecar | P-4 hosted OCR or document service | P-5 the brain vendor's document input |
|---|---|---|---|
| Role | OCR of page images on the DishNet host: a container with tesseract and poppler, reachable only on the docker bridge | OCR and layout extraction | the model reads the PDF itself |
| Data sent | **nothing leaves DishNet** | the whole document | the whole document, to `api.anthropic.com` or `api.openai.com` |
| Expected latency | UNMEASURED; seconds per page on CPU is the working assumption | UNMEASURED; seconds per document is the working assumption | UNMEASURED |
| Expected cost | server CPU and one more container to run; no per-page fee | per page; UNMEASURED — to be quoted before any choice | per input token; UNMEASURED — a scanned page is many tokens |
| Failure mode | sidecar down → `provider_error`, retried, then a person | timeout or 5xx → retried, then a person; 4xx → a person | the same |
| Privacy implication | the document stays on the host | the document, with every ID, account and name it carries, leaves; retention and training terms must be read and approved first | the same, under the vendor contract the brain already has |
| Avoidable with local tooling? | it IS the local tooling | yes, by P-3 | yes, by P-3 |

For PDFs WITH a text layer the options are different and are decision **D-1**: **P-1** an in-house minimal PDF text
reader (FlateDecode streams, xref tables and xref streams, object streams, simple fonts and `ToUnicode` CMaps; anything
else → `unreadable` → a person; an estimated 600–900 lines with its own fixtures), **P-2** a bundled pure-PHP library (a
licence, size and maintenance decision the operator must take explicitly — "do not install libraries" stands until
then), or **P-3**'s `pdftotext`. The recommendation is P-1 as the second slice: it keeps the first slice dependency-free
and the second one on the host.

## 14. Question 12 — deterministic fixtures

Every fixture is **generated by the test** — byte-deterministic, synthetic, never a real customer document, with no real
name, number or account in it:

| Fixture | How the test builds it | Expected outcome |
|---|---|---|
| normal PDF | a minimal writer: header, catalogue, one page, one uncompressed content stream with `BT … Tj ET`, a correct xref table and trailer | first slice: facts `pages_total 1, has_text_layer true` → `pdf_not_read` → a person with the facts; after D-1: the text → `general` → one labelled turn |
| scanned PDF | a page whose content stream draws an image XObject only (`/Subtype /Image`, no `BT`) | `scanned` → `provider_missing` (no OCR) → a person; with `ai_document_provider = fake` → the fake's scripted text → classification as usual |
| malformed PDF | `%PDF-1.4` followed by random bytes; a second with a truncated xref | `malformed_document` → a person |
| oversized | announced over the cap (refused before the fetch); actual over the cap (after decode); within the fetch cap but over the document cap | `too_large` · `too_large_document` → a person |
| password-protected | a PDF with `/Encrypt` in its trailer (the content need not be truly encrypted: detection stops at the trailer, and the test asserts no page is read); a zip whose entry carries the encryption flag; an OLE2 `EncryptedPackage` | `password_protected` → a person; no password asked |
| payment receipt | a `.docx` and a `.txt`: "MTN Mobile Money … Transaction ID … UGX 150,000 … successful" (synthetic) | `payment_proof` → evidence, hand-over, no event, no brain call, no financial write |
| invoice | a `.docx`: "TAX INVOICE … Bill to … Amount due … Due date" | `invoice` → a person |
| contract | a `.docx`: "SERVICE AGREEMENT … the parties … hereby agree … Signature" | `contract` → a person |
| spreadsheet | an `.xlsx` with three sheets (shared and inline strings, numbers, a formula with a cached value), one sheet over the row cap; a `.csv` with a `;` delimiter and a BOM | `spreadsheet` → one labelled turn; the caps applied; `truncated` flagged; the formula never evaluated |
| credential-containing | a `.txt` with a `password: …` line, an `AKIA…`-shaped key and a PEM block (synthetic values) | `credential` → a person; the values appear in NO table, event, log, alert or message |
| identity document | a `.docx`: "REPUBLIC OF UGANDA … NATIONAL IDENTIFICATION … NIN … Date of birth" (synthetic words, no real pattern) | `identity_document` → a person; nothing of the content stored |
| duplicate, idempotent | the same `wa_message_id` delivered twice; the same `ai.media` event consumed twice; `complete()` called directly on an understood row | one row, one extraction, one event or one hand-over, one audit |
| archive bomb | an `.xlsx` whose `sharedStrings.xml` member declares 1 GiB; another whose member is 20 MiB of zeros deflated to a few KB | refused before inflating; refused at zlib's cap |
| nested archive, embedded package | a `.docx` carrying `word/embeddings/oleObject1.bin` and a `payload.zip` member | read normally; the extra members never opened (the reader records every member it inflated) |
| macro-enabled | a `.docm` content type behind a `.docx` label | `unsupported_document` |
| legacy binary | 512 bytes beginning with the OLE2 signature, announced `application/msword` | `unsupported_document` → a person asked for a PDF |
| wrong type behind the label | PNG bytes announced `application/pdf` | `unsupported_mime` |
| slow | a reader given an injected deadline of 0 s | `too_slow`, permanent, a person |
| caption | a general `.docx` with a caption; a receipt `.docx` with a caption | the caption under its own label in the turn / with the person in the hand-over; never answered twice |

## 15. Question 13 — flags, all OFF by default

| Key | Type | Default | Range | Meaning |
|---|---|---|---|---|
| `ai_media_enabled` | bool | **off** (exists, Batch 1) | — | the media foundation; off wins over everything below |
| `ai_media_document` | bool | **off** | — | documents are extracted, classified and routed; needs `ai_media_enabled` |
| `ai_media_document_max_bytes` | number | 10,485,760 | 65,536 – 67,108,864; refused outside | the document cap (§4) |
| `ai_media_document_max_pages` | number | 20 | 1 – 200 | PDF pages read (D-1) |
| `ai_media_document_timeout_s` | number | 20 | 5 – 60 | the extraction deadline |
| `ai_document_provider` | text | `none` | `none` only; the test-only `fake` is refused by `set_config`, as the voice and image providers' fakes are | the OCR boundary's provider |

`MediaPolicy::documentEnabled()` = `enabled() && flag(ai_media_document)`. Both flags stay off in every environment; the
operator's instruction decides when, and nothing in the code decides for them. No other flag: the classification rules,
the caps and the record policy are code, like `IMAGE_MAX_SIDE_PX`.

> **Superseded in part by `docs/60` (Batch 5, 5.18.81).** `ai_media_document` alone is now the **dry run** — extract, classify
> and record, nobody told, no AI turn, the stored body untouched. Two rungs above it, `ai_media_document_handover` and
> `ai_media_document_reply`, add the person and then the assistant; each needs every flag below it. The caps and the record
> policy in this table are unchanged.

## 16. Question 14 — compatibility, proved rather than asserted

| Property | Why it holds | How Batch 4 proves it |
|---|---|---|
| South Sudan unchanged | its configuration carries no media flag; `cron/master.php`'s `ai_media` job already exists only while `ai_media_enabled` is on (its `'flag'` attribute), and Batch 4 adds no job, no table and no migration; the webhook's new step runs only under `documentEnabled()` | `test_notify_schedule_health` §4 (South Sudan's job list identical to 5.18.53) and the Batch 1 OFF sections (`W1`, `W8`, `R2`, `R3`) unchanged in count |
| Domain B untouched | no file under `dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/` is in Batch 4's file list | `git diff --stat <previous release>..HEAD -- dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/` prints nothing, reported as for Batches 1–3 |
| The text path unchanged with the flags off | the webhook's caption-once step is behind `documentEnabled()`; with it off a captioned document is answered as text exactly as today — with ONE deliberate, flag-independent exception if the operator takes D-11: a wrapped captioned document that is dropped today would be stored and its caption answered, which is the defect fix of §2.1, not the document feature | the new test's OFF scenario compares the webhook's response and the queued `ai.reply` with Batch 1's |
| Location, voice and image paths unchanged | `MediaWorker` branches on `documentWanted()` AFTER `voiceWanted()` and `imageWanted()`; the DOCUMENT block and the contract key are conditional on a document turn | `test_voice_media` (74) and `test_image_media` (89) unchanged in count; `test_brain_context` renders the sales prompt with and without a document and asserts the without-case byte-identical to today's; `BrainContext::CONTRACT` grows by exactly one key |
| Media on, document off | a document row ends `fetched` with no understanding, no event and no hand-over — Batch 1's behaviour, byte for byte | the OFF scenario, in-process and through the CLI runner |
| Media off, document on | nothing is recorded (Batch 1 W1): `ai_media_enabled` off wins | the override scenario |

## 17. Question 15 — rollback

1. **The switch.** `tools/set_config.php ai_media_document false` — or leaving it unset. It takes effect on the next
   worker run: `documentWanted()` is false, a document row stops at `fetched` (Batch 1), the webhook answers the caption
   as text again, and no document reaches the assistant. Voice and image keep their own flags; Batches 1–3 are not
   touched by the switch.
2. **The code.** Batch 4 is one commit that adds new files and flag-guarded hunks, with **no migration**:
   `wa_media.understanding_kind` is free text (`extraction` is reserved in migration 085; `document_evidence` is one more
   value) and the facts live in `wa_messages.metadata`, which is JSON. A `git revert` of that commit leaves Batches 1–3
   exactly as they are; rows written by Batch 4 stay readable by older code, which ignores them.
3. **Nothing to clean up.** No file on disk, no new table, no new cron job, no new secret.

## 18. Assumptions, and the evidence still required

| | Evidence needed | Why it matters |
|---|---|---|
| E-1 | `php -m` inside the uCRM container — zlib, xml, xmlreader, iconv, mbstring — read-only | without zlib and xml the OOXML readers cannot run; the design fails closed, but would then hand over every `.docx` |
| E-2 | the CLI `memory_limit` the media runner inherits on the server | sets the safe document cap; this sandbox has none, the server is unknown |
| E-3 | how long Evolution takes to return the base64 of a 5–10 MiB document on the server | `ai_media_timeout_s` defaults to 20 s; a slow fetch is retried, but a cap that is always too short is a hand-over for every PDF |
| E-4 | whether `wa_messages` and `events` should have a retention period — `wa_messages` has none; `events` ARE pruned: `EventBus::prune(30)` is called by `cron_maintenance.php` Task 3B, daily at 02:00, completed events older than 30 days (this row said "no caller" when written — wrong; corrected by the Slice 4b evidence gate, §20) | Batch 4 inherits it; the decision is the operator's, separately |
| E-5 | what document types customers actually send — a count by announced mimetype from `wa_media` once Batch 1 is on | decides whether D-1 (PDF text) is worth building, and D-2 |
| E-6 | one real Evolution envelope of a captioned document from staging: does 2.3.7 send `documentWithCaptionMessage`, and nested under `ephemeralMessage`? | decides whether D-A is live today or latent; the fix is inert either way |

## A. Recommended Batch 4 scope

- **Slice 4a — recommended now, dark:** `MediaPolicy` document constants and `documentEnabled()`; `DocumentSniffer`,
  `OoxmlArchive`, `DocxReader`, `SheetReader`, `TextReader`, `PdfReader` (facts only); the two live-path fixes of §2.1 (D-A, subject to D-11; D-B); the classifier of §6.4, with the
  credential detector calling `ReplyPrivacyGuard`'s shapes; `DocumentOcrPort` + `FakeDocumentOcr` +
  `ai_document_provider = none`; `DocumentExtraction::process/complete` on the `MediaWorker` path — `documentWanted()`,
  the hand-over reasons per class, the SETTLED refinement, `onDead`; the webhook's caption-once step for documents;
  `AiReplyWorker` origin and modality; the sixteenth `BrainContext` key and `NEVER_PRESENT['file_name']`; the DOCUMENT
  prompt block; the `document_commitment` guard phrases; `tools/set_config.php` keys; `validate_environment.php`
  extension lines; `tests/test_document_media.php`; `docs/55` §10 and §12; `docs/07`; the manifest and the version pins
  as every batch. **No migration. No library. No provider. Nothing deployed. Both flags off.**
- **Slice 4b — after D-1:** the PDF text layer (P-1 recommended).
- **Slice 4c — after D-2:** OCR for scanned documents, behind `DocumentOcrPort`.
- **Out of every slice:** everything in C.

## B. Items requiring Bhavin's decision

| | Decision | Recommendation |
|---|---|---|
| D-1 | the PDF text layer: P-1 in-house reader, P-2 bundled library, P-3 sidecar `pdftotext`, or none | P-1, as slice 4b |
| D-2 | OCR for scanned documents: none, local sidecar (P-3), hosted (P-4), the brain vendor (P-5) | none until E-5 shows the need; P-3 if any |
| D-3 | may a `quotation` enter the assistant (which could then only quote published prices), or stay human-only | human-only in 4a |
| D-4 | an `invoice` sent by the customer: the holding line only, or also the generic "how to pay" text from `ai_fact_payment`, without reading the document | the holding line only in 4a |
| D-5 | legacy `.doc` / `.xls`: keep fetching them (Batch 1 today) and refuse at extraction, or remove them from `ALLOWED` so they are `unsupported` at the fetch | keep fetching; refuse at extraction with the "please send a PDF" reason |
| D-6 | the defaults — 10 MiB, 20 pages, 20 s, 4,000 characters to the assistant | as proposed |
| D-7 | keep a 300-character masked excerpt for the five evidence classes, or nothing at all | the excerpt |
| D-8 | the hand-over reasons (§6.4) and any customer-facing holding line are the operator's words | review the wording before the build |
| D-9 | run the document path on all three numbers (sales, support, account), or sales only | all three; the routing is identical |
| D-10 | a document that is payment evidence AND carries a question in its caption: any automatic acknowledgement beyond the holding line? | no |
| D-11 | the §2.1 D-A fix — a wrapped captioned document stored and its caption answered as text — changes the live text path for a shape that is dropped today: fix it in the Batch 4 commit as a flag-independent defect, or capture an envelope (E-6) first | fix it in the Batch 4 commit, as Batch 0 fixed its three |

## C. Items explicitly prohibited

- Marking an invoice paid, creating or changing a payment, altering a cashbook or ledger, creating a financial record,
  changing a balance — from any document, ever.
- Approving a contract, accepting contractual terms, confirming an identity or taking any KYC decision.
- Storing, logging, repeating or forwarding a credential found in a document.
- Treating extracted or OCR text as authoritative evidence of money, identity or agreement.
- Writing any document, member, page image or OCR output to disk, a log, an event payload or an alert.
- Sending a document, a page, its file name or its full text to any external service in slice 4a. The capped extract of
  a brain-eligible document to the brain's existing vendor is the one, pre-existing egress.
- Asking the customer for a document's password.
- Executing, converting or opening a document with any application; macros; shell-outs; temporary files.
- Selecting or integrating an OCR, PDF or document-AI provider, installing a library, adding a migration, or changing any
  flag or configuration before the decisions in B.
- Any change to the voice, image, location or text paths, to Domain B, or to South Sudan's behaviour.
- OCR of photographed documents sent as images (`document_photo` stays described-only, `docs/57` §4).

## D. Proposed test plan

`tests/test_document_media.php`, in the Batch 3 shape — facts-returning scenarios, `--driver=core|cli`, weakened copies
through `sj_weakened_copy`, controls on the controls:

1. **Core, in-process** (the fake Evolution with its `/__test/media` endpoint serving the generated bytes, the fake uCRM,
   the fake brain): the §14 matrix, each row with the `wa_media` state, the stored message, the event-count delta, the
   hand-over count, the money and KYC table counts and the fake uCRM's counts.
2. **The brain:** the labelled message; the `document` key with exactly three leaves; the DOCUMENT block present only on
   a document turn and the prompt byte-identical otherwise; the guard refusing a committing reply on a document turn;
   the audit event's `modality: document`; the context's JSON free of file name, ids, phone and sha.
3. **Idempotency:** duplicate delivery, duplicate event, the `understood` guard called directly.
   **The §2.1 fixes:** a `documentWithCaptionMessage` envelope is stored with its caption and `media_type = 'document'`,
   its STOP read, its text turn queued with the flags off and carried by the document turn with them on; the same
   nested under `ephemeralMessage`; a weakened copy of each fix caught.
4. **Flags:** document off with media on (Batch 1 behaviour), media off (nothing recorded), both on; `ai_enabled` off at
   the runner.
5. **Retention and leakage:** nothing on disk anywhere under the data and plugin directories; every fixture sentence,
   credential value and synthetic identifier searched for in the logs, `events`, `wa_media`, `wa_messages` and the
   alerts — present only where the retention class allows (the capped extract for general and spreadsheet; the masked
   excerpt for the evidence classes; nowhere for identity and credential).
6. **CLI** through `run_media_worker.php` in a real plugin tree (`SjSandbox`): a captioned general `.docx` answered once;
   a receipt `.docx` → hand-over, no event; document off → caption as text, file fetched only; media off → nothing;
   `ai_document_provider = fake` without its environment → fails closed → `provider_missing`.
7. **Weakened copies, each caught:** flag ignored · media-off override ignored · declared-size cap removed · zlib cap
   removed · member allow-list widened · `<!DOCTYPE` accepted · macro types accepted · `/Encrypt` ignored · payment
   words rule removed · credential detection removed · identity routing removed · evidence-class text persisted in full ·
   file name sent to the brain · label removed · prompt block removed · `understood` guard removed · hand-over removed ·
   extract logged · deadline check removed · guard commitment phrases removed; plus the control: an unweakened copy
   passes.
8. **Runs:** the new test; the neighbours (`test_media_foundation`, `test_voice_media`, `test_image_media`,
   `test_brain_context`, `test_handover_message`, `test_plan_fence`, `test_reply_privacy_guard`,
   `test_ai_security_policy`, `test_notify_schedule_health`, `test_cron_no_exit`, `test_config_one_truth`); the full
   suite twice; `git diff --check`; the secret-shaped-value scan; the Domain B diff empty; the South Sudan scheduler
   suite unchanged; counts reported exactly, as for Batches 1–3.

## 19. Build record — Slice 4a, 2026-10-05, development only (5.18.79)

**The operator's decisions** (verbatim in substance): D-1 no PDF text in 4a, P-1 in-house reader as slice 4b after 4a is
proven; D-2 no OCR — no tesseract, no sidecar, `DocumentOcrPort` + the fake as the empty boundary, `ai_document_provider`
stays `none`; D-3 quotations human-only; D-4 invoices human-only, holding line only, no "how to pay"; D-5 legacy `.doc`/`.xls`
kept at the fetch, refused at extraction as `unsupported_document`, a person asked for a PDF; D-6 10 MiB · 20 pages · 20 s ·
4,000 characters; D-7 the 300-character masked excerpt, nothing at all for identity and credential; D-8 the wording of §6.4,
tested word for word; D-9 all three numbers; D-10 no acknowledgement beyond the holding line; D-11 both wrapper fixes,
flag-independent, with envelope fixtures for every wrapper and the nested shapes. Plus the added rule: **classification must
fail closed** — uncertain, ambiguous, truncated before safe classification, a missing extension, malformed content or any
exception → a person; never `uncertain → general → AI`.

**What was built**, all of it in the plugin process with no library and no provider: `lib/DocumentDeadline.php` (the refusal
types and the deadline), `lib/OoxmlArchive.php` (the minimal zip reader: entries cap, zip64 and encrypted entries refused, an
ALLOW-LIST of members, the declared size checked before inflating, `gzinflate` capped at the declared size, length and CRC
checked), `lib/DocumentSniffer.php`, `lib/TextReader.php`, `lib/DocxReader.php`, `lib/SheetReader.php` (named so because the
unused `lib/XlsxReader.php` keeps its name), `lib/PdfReader.php` (facts only), `lib/DocumentOcr.php`,
`lib/DocumentClassifier.php`, `lib/DocumentExtraction.php`; `MediaPolicy` document constants and `documentEnabled()`;
`PaymentEvidence::hasMoneyToken()` / `hasTransactionToken()` (signals); the worker's document branch; the webhook's step 9b
and the D-11 unwrap in `evoExtractText`, `ConversationService::importEvoMessage` and `InboundMedia::unwrap()` (one list,
three levels); `AiReplyWorker` origin, context and `modality: document`; `BrainContext`'s sixteenth key and
`NEVER_PRESENT['file_name']`; the DOCUMENT block; `ReplyPrivacyGuard::secretShapesIn()` and the `document_commitment` phrases
(payment, contract, identity — completed facts only, never the conditional); the five `set_config` keys with their ranges and
the `none`-only provider; `validate_environment` reporting zlib, xmlreader and iconv; `run_media_worker.php` raising a CLI
`memory_limit` that is below 256M to 256M (higher or unlimited values are left unchanged; wording clarified in the Slice 4b
record, §20). No migration: `understanding_kind` gains the values `extraction` and `document_evidence`; the facts live in
`wa_messages.metadata.document`.

**Three places the build sharpened the design, recorded rather than hidden:**

1. **Classification precedence.** §6.4 put `payment_proof` before statement, invoice, contract and quotation. Measured on the
   fixtures: a bank statement has deposits and balances, an invoice has an amount and "pay by", so the Batch 3 money-and-
   transaction rule fired first and the person was told "a payment screenshot or receipt arrived" about a statement. The
   built order is: credential → identity → a DECISIVE phrase of statement / invoice / contract / quotation → the payment words
   rule → two supporting phrases of those four → spreadsheet → general. Every one of those classes is human-only, so the
   route is identical; only the person's wording is more precise.
2. **A spreadsheet beyond the scanning caps is `classification_incomplete`, a person** — not "truncated and shown". §4 said
   the caps bound what is scanned; the fail-closed rule then says text the classifier did not see all of is not harmless. A
   2,100-row sheet goes to a person. The brain cap (4,000 characters) still only cuts what the assistant is SHOWN of a sheet
   the classifier saw whole.
3. **The fail-closed rule is literal.** A sheet whose label row said "Sum" went to a person in the test, because "sum" is a
   money token. That is the rule working; the fixture was changed, the rule was not. Expect harmless documents with a stray
   money, transaction, invoice, contract or identity word to reach a person — the cost the rule accepts.

**A consequence of D-11, measured and recorded:** the same unwrap stores a disappearing (`ephemeralMessage`) text message and a
view-once document that were dropped before; their text is answered as any typed text is. `test_document_media.php` pins it.

**Proofs:** `tests/test_document_media.php` **161 / 0** — the eighteen areas of docs/58 §D, every fixture of §14 generated in
the test (a zip writer, Word, Excel, three PDFs, the wrapped envelopes), the eight human-only outcomes each with its hand-over
wording checked word for word, the money AND the identity/KYC tables counted before and after, the fake uCRM unchanged, nothing
sensitive in any table, file or log, the CLI runner through the real webhook and `run_media_worker.php` (seven cases, the
wrapped "stop" caption included), and **24 weakened copies each caught** (flags, the zip caps and allow-list, the DTD guard, the
macro check, `/Encrypt`, payment and credential and identity routing, the record policy, the file name, the label, the
prompt block, the understood guard, the hand-over, logging, the deadline, the guard phrases, uncertain → general, incomplete →
general, both D-11 fixes) with the control. The suite totals are in `docs/07`.

**Still not here, by decision:** PDF text (4b, D-1), OCR (4c, D-2), any provider, any deployment. `lib/XlsxReader.php` stays
unused and untouched.

---


## 20. Build record — Slice 4b, 2026-10-05, development only (5.18.80): the PDF text layer, P-1

**The operator's approval** (verbatim in substance): proceed with Slice 4b using the approved architecture and every existing
safety boundary; implement P-1, the in-house PDF text extraction; OCR stays out; no external document or OCR provider; the
Slice 4a classification and human-only safeguards preserved — payment proof, statement, invoice, contract, quotation,
identity, credential human-only and never an `ai.reply`; general and spreadsheet documents send only the already-approved
capped text to the existing AI vendor; the original document never leaves; every media and document flag off by default;
`ai_document_provider = none`; no financial write, payment confirmation, KYC decision, order creation or other business
action from document understanding; STOP, hand-over, privacy and `ReplyPrivacyGuard` behaviour preserved; Uganda and South
Sudan unchanged; Domain B untouched; the memory policy unchanged except where strictly required (it was not required); no
customer-facing automation. PDF scope: deterministic local text extraction only; the existing 20-page, 10 MiB, 20-second and
4,000-character limits; fail closed on malformed, encrypted, unsupported or unsafe PDFs; no OCR of scanned pages; a PDF with
no extractable text classified as needing a person, never guessed; no original bytes retained; only the Slice 4a retention
model's derived, capped information persisted.

**The evidence gate, as it stood when 4b was approved — recorded here exactly, and NOT converted into anything else:**

| | Status | What was established |
|---|---|---|
| E-1 | **MEASURED** | the uCRM container (`docker exec ucrm php -m`): PHP 8.1.34 CLI; zlib, xml, xmlreader, libxml, mbstring, iconv, fileinfo, zip, curl, pdo_sqlite all loaded — every extension 4a and 4b need |
| E-2 | **MEASURED** | the uCRM container's CLI `memory_limit` is **2048M**, from `/usr/local/etc/php/php.ini`; both launch paths of the media runner (the `main.php` tick that includes `cron/master.php`, and the webhook's background spawn) are CLI, so the runner's raise never fires there and the budget rule compares against 2 GiB |
| E-3 | **NOT MEASURED** | live Evolution media-fetch latency for 5 and 10 MiB documents. The staff-phone test on the live gateway could not be performed safely; the only measurement is the plugin's own fetch path against the test fake in the sandbox (0.09 s for 5 MiB, 0.21 s for 10 MiB, peak memory 2.7× the file), which says nothing about Evolution |
| E-4 | **MEASURED** (read-only code) | `wa_messages` has no retention; `events` are pruned after 30 days by `cron_maintenance.php` Task 3B, daily at 02:00, live at 5.18.74. §18's "`EventBus::prune()` has no caller" was wrong and is corrected above |
| E-5 | **MEASURED, incomplete** | production inbound documents: 33 in September, 5 in the first five days of October — about one a day, a floor (wrapped documents were dropped before storage until D-11); the inbound mix is 96% text, 2.7% images, 0.5% documents, 0.3–0.9% voice. **The MIME/type mix of documents remains unknown** — the live store keeps no mimetype and `wa_media` is not deployed |
| E-6 | **NOT MEASURED** | the actual Evolution 2.3.7 envelope of a captioned document. The live test could not be performed safely; the wrapper fixtures in the tests are this project's own and prove nothing about what the gateway sends |

> **Risk exception, approved by the operator:** Slice 4b is implemented with E-3 and E-6 unmeasured. What that leaves open:
> whether a 10 MiB document returns from Evolution within `ai_media_timeout_s` (a fetch that always times out is a hand-over
> for every such document, never an exposure), and whether D-11 is live or latent today (the fix is inert either way). Neither
> gap touches the data-egress or human-only boundaries. Both remain to be measured before any flag is turned on.

**What was built** — in the plugin process, no library, no provider, no migration, no byte leaving the server.

- **`lib/PdfReader.php` — `PdfReader::text()`** beside the Slice 4a `facts()`: objects found by scanning for `n g obj` with the
  LAST definition in the file winning (incremental updates append), object streams (`/Type /ObjStm`) opened so a PDF 1.5+
  file whose catalogue lives inside one still reads; **FlateDecode** through zlib's incremental API in 64 KiB pieces so a
  stream inflating beyond `DOCUMENT_PDF_MAX_STREAM_BYTES` (8 MiB) is refused as **too large** the moment it crosses the cap
  while one zlib cannot read is **malformed** (`gzuncompress()` returns false for both, which is why the incremental API);
  the PNG predictors xref and object streams use; **ASCIIHexDecode** and **ASCII85Decode**; **any other filter on a stream
  that must be read is `unsupported_document`**, naming the filter — never a guess; image streams are never read. The page
  tree is walked from `/Root` → `/Pages` with `/Resources` inherited, a cycle or a depth beyond 64 refused, more than 20,000
  nodes refused; without a catalogue the tree is found by its type; without a tree, `/Type /Page` objects in file order; with
  neither, `malformed_document`. The content stream's text operators (`Tj` `TJ` `'` `"`) are decoded through the font the
  stream selected: a **ToUnicode CMap** (codespace ranges, `bfchar`, `bfrange` with strings and arrays) where there is one,
  else the simple-font encodings (**WinAnsi, MacRoman, Standard**) with **`/Differences`** through a glyph-name table; a
  composite font without a ToUnicode map, a Type3 font without one, or a predefined CMap yields **nothing invented**: each
  such glyph is **counted**, and above a 10% share the text is **not "seen whole"** (`classification_incomplete`, a person).
  **Form XObjects** are followed to a depth of 8, never twice on the same drawing stack (a cycle is `malformed_document`),
  4,000 per document; inline images are skipped between `ID` and a delimited `EI`; `Td`/`TD`/`Tm`/`T*`/`'`/`"` break lines
  where the text matrix moves down, and a `TJ` adjustment wider than 180 thousandths becomes a space. Every loop has a
  number: 50,000 objects, 512 object streams, 2,000,000 operators, 512 fonts, 65,536 CMap entries, 64 KiB per string token,
  64 operands, 64 MiB inflated in all; **the deadline is asked between objects, between pages and every 2,000 operators**;
  the text is cut at the caller's character cap. `facts()` changed in one line: an object stream is still opened after text
  operators were seen, so a PDF 1.5 file's page count is right.
- **`lib/DocumentExtraction.php`** — the `pdf` case: encrypted → `password_protected` as before; a text layer →
  `requireCapabilities(['gzuncompress', 'inflate_init'])` (a missing zlib is now `extractor_unavailable`, not a misleading
  `malformed_document`) → `PdfReader::text()` with `documentMaxPages()` and the 50,000-character scan cap → the SAME
  normalisation, classification, record policy, label and one-event path every other kind takes; a layer that yields no
  text → **`pdf_no_text`**, permanent, the person told *"a PDF arrived (N pages, a text layer that yielded no text) — no text
  could be read from it automatically; open it in WhatsApp"*. **`pdf_not_read` is retired**: no path produces it. A scanned
  PDF still meets the empty OCR boundary (D-2). `pages_read` / `pages_total` reach the label ("PDF, 3 pages read of 3"), the
  stored message's metadata and the event's `document` facts.
- **Settings and copy**: `set_config` describes PDF text (the page cap: "a longer PDF is read up to the cap and goes to a
  person, never to the assistant"); `MediaPolicy`'s page-cap comment; the worker's docblock; `manifest.json` 5.18.80 and the
  fourteen version pins.
- **The Slice 4a memory-test gap, closed** (the planned controlled edit): `tests/test_document_media.php` C8 starts the REAL
  runner under `php -d memory_limit=64M` on a 12 MiB text file — with the raise it is read to the text cap
  (`classification_incomplete`, a person), and a weakened copy without the raise is refused for memory
  (`too_large_document`, "would not fit the memory budget"); `tests/test_document_pdf.php` §8 lowers the live `memory_limit`
  in-process and proves the budget rule refuses a 14 MiB document and passes a 1 MiB control, with its own weakened copy.
  The mutant loop gained an optional per-mutant driver so a detector can read the real runner's facts.
- **Wording cleanup**: §19 above, `docs/07`'s Slice 4a entry, and the `set_config` descriptions no longer say "a CLI memory
  limit below 256M" or "PDF facts only"; the 4a commit message (`4c931ef`) is left as the record it is.

**Three things found while building, recorded rather than hidden:**

1. **`gzuncompress()` with a cap cannot tell an oversize stream from a corrupt one** — both come back `false`. The first
   draft refused a 9 MiB stream as `malformed_document`; the incremental zlib API in pieces gives the honest reason.
2. **The 4a test's fixture set is NOT the gateway's envelope** — a point the operator made and the record keeps: every
   wrapper shape here is generated by the test. E-6 stays NOT MEASURED whatever the tests prove.
3. **An alphanumeric transaction reference survives the masked excerpt by design** — D-7 masks digit runs of six or more and
   e-mail addresses. A first assertion expected the reference masked; the policy, not the assertion, is the approved one, so
   the fixture carries a twelve-digit reference and the test proves THAT is masked. Widening D-7 would be its own decision.

**Proofs.**
- `tests/test_document_pdf.php` **102 passed, 0 failed**, 24 s, no fake server at all: the reader on 29 generated PDFs (a
  WinAnsi page; three pages with inherited resources; a composite font through its ToUnicode CMap inside an object stream
  with an xref stream; `TJ` kerning, hex strings, é £ €; `/Differences`; MacRoman; a simple font with ToUnicode; nested
  Form XObjects and an inline image; a form cycle; forms ten deep; ASCII85 over Flate; LZW; no text; no catalogue; no pages;
  a junk xref; junk bytes; 25 pages; an expired deadline; 400,000 operators under a 30 ms budget — stopped INSIDE the content
  loop; a 9 MiB inflate bomb; a lying `/Length`; 50,001 objects; Type3 and composite fonts without maps; half the glyphs
  unnamed; the character cap; an encrypted file); the pipeline from bytes to the queue (a general PDF is one `ai.reply` with
  the label, the text, origin `document`, kind `pdf`, never the file name, the bytes, their base64 or their hex; the brain
  context names classification, kind and truncation; three pages in order; `pdf_no_text` word for word; 25 pages at the cap
  a person and under a cap of 30 "25 pages read of 25"; malformed, encrypted, unsupported, oversized, bomb, expired,
  unavailable each with its reason; the 4,000-character cut with its ellipsis and `truncated`; text beyond the scan cap a
  person; the seven human-only classes from PDFs — evidence, no event, the money and KYC tables identical, identity and
  credential recording nothing, a receipt's twelve-digit reference masked; nothing sensitive and no PDF byte in any table or
  file; the general extract only in `wa_media`, the stored message and the event; "stop" in a PDF not an opt-out; a
  committing reply on a document turn refused by the guard; logs with no extract, no bytes, no credential; the memory budget);
  **15 weakened copies each caught, with the control**: the page cap, the character cap, the in-loop deadline, `pdf_no_text`,
  the unknown-filter refusal, the form cycle, the form depth, the inflate cap, the object cap, the zlib guard, the
  unnamed-glyph rule, the memory budget, `/Differences`, ToUnicode, `TJ` spacing.
- `tests/test_document_media.php` **166 passed, 0 failed** (was 161): a text PDF now READ into one turn with its label and
  text, never its bytes; `pdf_no_text` through the worker with the person's wording and the holding line; C8 under 64M;
  **25 weakened copies each caught** (the 24 of 4a plus the runner's raise, through the real runner), control on core and
  cli facts.
- **Full suite:** **`tests/run.sh` 276 files, 12,899 passed, 0 failed, 0 skipped** (was 275 / 12,792 at 5.18.79: the new `test_document_pdf` 102 and the 5 added to `test_document_media`; nothing else moved). **Second run: 276 / 12,899 / 0 again**, every suite's tally identical. PHP warnings in either run: 5. The South Sudan and tenant suites, unchanged: `test_notify_schedule_health` 19 · `test_staff_jobs_south_sudan` 51 · `test_tenant_profile` 108 · `test_email_no_sudan` 62 · `test_notify_tenant_text` 30 · `test_cashbook_tenant` 26 · `test_portal_tenant` 112 · `test_sales_support_tenant` 37 · `test_ai_country_facts` 21 · `test_phone_country` 26.
- `git diff --check` clean; no secret-shaped value; the two banned values absent; no file under
  `dishnet-mikrotik-control-plane/` touched; no migration (the last is still 085); every tenant and South Sudan suite with the
  tally it had.

**Not done, by decision:** OCR (D-2, slice 4c); any provider, library, migration, deployment or flag; the document type mix
(E-5) — it needs either `wa_media` recording in the dark or a flag-independent mimetype at import, both the operator's call;
E-3 and E-6, which stay NOT MEASURED until a safe live test exists.

**Rollback:** 4b is code only. Reverting the commit restores 5.18.79 exactly: no migration, no setting, no file on disk
changes between the two, and a 5.18.79 runner reads every row 4b wrote (`understanding_kind` values unchanged;
`wa_messages.metadata.document.kind = 'pdf'` is a string the 4a code already stores for scanned PDFs through the fake).

---

*Design approved; Slices 4a and 4b built in development only; nothing was pushed or deployed; no flag is on; no customer
document was processed; E-3 and E-6 remain NOT MEASURED.*
