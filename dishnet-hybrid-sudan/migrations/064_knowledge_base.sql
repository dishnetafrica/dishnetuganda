-- 064: Central knowledge base for every customer-facing AI channel.
-- Additive only.
--
-- One row = one approved piece of knowledge, rule of conduct, or explicitly
-- open topic. KnowledgeBase.php renders the approved set into the shared
-- system prompt that BOTH the WhatsApp AI and the website chat use (they run
-- the same brain), so an answer is updated once — in the Knowledge Base admin
-- tab — and every channel changes together.
--
-- kind:
--   fact  — an approved answer the AI must use verbatim in substance
--   rule  — a conduct rule (never guarantee speed, escalate legal, …)
--   tbc   — a topic with NO approved answer yet: the AI must use the holding
--           line and escalate, never improvise
--
-- status: approved | disabled  (disabled rows are kept for history)

CREATE TABLE IF NOT EXISTS knowledge_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    item_key    TEXT    NOT NULL UNIQUE,      -- e.g. OFFICE_LOCATION
    kind        TEXT    NOT NULL DEFAULT 'fact',
    title       TEXT    NOT NULL,
    answer      TEXT    NOT NULL DEFAULT '',  -- approved full answer (facts) / rule text
    wa_answer   TEXT    NOT NULL DEFAULT '',  -- optional short WhatsApp form
    status      TEXT    NOT NULL DEFAULT 'approved',
    updated_by  TEXT    NOT NULL DEFAULT '',
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_kb_kind ON knowledge_items(kind, status);
