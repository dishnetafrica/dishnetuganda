-- 085_wa_media.sql — a record of every voice note, image, document or video a customer sends (AI communication layer,
-- Batch 1 — the media foundation; docs/55 §9, docs/07 4–5 Oct 2026).
--
-- Until now a media message was stored as "[AUDIO]" / "[IMAGE]" / "[DOCUMENT]" with its type and nothing else: no
-- mimetype, no file name, no size, and nothing to fetch it with later (ConversationService::importEvoMessage). The AI
-- was not queued and the customer heard nothing. This table keeps what Evolution's getBase64FromMediaMessage needs — the
-- message key — plus what the webhook announced about the file, and the state of the fetch. It NEVER holds the media
-- itself: bytes live in memory for the length of one worker turn and are discarded; sha256 and size are what remain.
--
-- Written only when ai_media_enabled is on (evo_webhook.php, step 8c). Read by MediaWorker. Additive: older code
-- ignores it; the text/location path is unchanged. One row per WhatsApp message id — the idempotency key — so a
-- duplicate webhook delivery or a re-queued event cannot make a second fetch.
CREATE TABLE IF NOT EXISTS wa_media (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id    INTEGER NOT NULL,                 -- wa_conversations.id
    message_id         INTEGER,                          -- wa_messages.id of the stored placeholder row, when known
    wa_message_id      TEXT    NOT NULL,                 -- Evolution/WhatsApp key.id — UNIQUE below; the idempotency key
    instance           TEXT    NOT NULL,                 -- the Evolution instance the message arrived on
    channel            TEXT    NOT NULL,                 -- sales | support | account, as the webhook mapped it
    remote_jid         TEXT    NOT NULL,                 -- key.remoteJid, needed to fetch (never logged)
    from_me            INTEGER NOT NULL DEFAULT 0,       -- key.fromMe
    kind               TEXT    NOT NULL,                 -- audio | image | document | video | sticker (InboundMedia::KINDS)
    mimetype           TEXT,                             -- as announced by the webhook, e.g. audio/ogg; codecs=opus
    file_name          TEXT,                             -- documents: the customer's file name, as announced
    caption            TEXT,                             -- the caption, if any (already answered as text by ai.reply)
    declared_bytes     INTEGER,                          -- fileLength from the webhook, if present
    seconds            INTEGER,                          -- audio: duration announced
    status             TEXT    NOT NULL DEFAULT 'pending',
                                                         -- pending | fetching | fetched | understood | failed | unsupported | skipped | dead
    attempts           INTEGER NOT NULL DEFAULT 0,
    failure_reason     TEXT,                             -- a fixed code (MediaFetcher::REASONS), never a URL or a token
    fetched_bytes      INTEGER,                          -- actual size after fetch
    fetched_mimetype   TEXT,                             -- as Evolution reported it
    sha256             TEXT,                             -- of the fetched bytes: evidence and dedupe without retention
    understanding      TEXT,                             -- Batch 2+: the text the brain is given (transcript / description / extraction)
    understanding_kind TEXT,                             -- Batch 2+: transcript | description | extraction
    created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_media_wa_message_id ON wa_media(wa_message_id);
CREATE INDEX IF NOT EXISTS idx_wa_media_status ON wa_media(status, created_at);
CREATE INDEX IF NOT EXISTS idx_wa_media_conversation ON wa_media(conversation_id, id);
