-- 070_followups.sql — the follow-up state machine.
--
-- One row per OPEN ENQUIRY, anchored to the conversation rather than to a
-- CRM customer. That is the decision the whole design turns on: somebody
-- asking "how much is DishNet Home" is usually not a client yet, and
-- requiring crm_client_id would refuse to follow up exactly the people worth
-- following up. Canonical identity rides along when it exists, and its
-- PROVENANCE rides with it, because how we came to believe a phone number
-- belongs to a customer decides what an unsolicited message may say.

CREATE TABLE IF NOT EXISTS followups (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id  INTEGER NOT NULL,
    channel          TEXT    NOT NULL,      -- copied at open: the reply goes back here
    phone            TEXT    NOT NULL,      -- copied at open, for opt-out checks

    crm_client_id    INTEGER,               -- canonical identity, when known
    crm_link_method  TEXT,                  -- provenance AS AT OPEN, never re-read later
    lead_id          INTEGER,

    -- Enquiry context. Written by the scan from what it can see, then
    -- corrected by the AI, which reads the thread properly.
    topic            TEXT    NOT NULL DEFAULT 'unknown',
    product          TEXT,
    sales_stage      TEXT    NOT NULL DEFAULT 'unknown',
    objection        TEXT,
    context_summary  TEXT,

    opened_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    last_customer_at TEXT    NOT NULL,      -- UTC. Their last message when this opened
    due_at           TEXT,                  -- UTC. When the AI may next be ASKED
    attempts         INTEGER NOT NULL DEFAULT 0,
    max_attempts     INTEGER NOT NULL DEFAULT 2,
    last_sent_at     TEXT,

    closed_at        TEXT,
    close_reason     TEXT,
    opened_by        TEXT    NOT NULL DEFAULT 'scan'
);

-- THE structural guarantee. One open follow-up per conversation, enforced by
-- the database, so two workers racing on the same quiet customer cannot both
-- create one. Application logic alone would lose that race.
CREATE UNIQUE INDEX IF NOT EXISTS idx_fu_open_conv
    ON followups(conversation_id) WHERE closed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_fu_due
    ON followups(due_at) WHERE closed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_fu_client
    ON followups(crm_client_id) WHERE crm_client_id IS NOT NULL;

-- A closed follow-up is history: what we decided about a customer, and when.
-- Reopening one would overwrite that. A new enquiry opens a new row.
CREATE TRIGGER IF NOT EXISTS fu_closed_is_history
BEFORE UPDATE ON followups
WHEN OLD.closed_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'a closed follow-up is history — open a new one');
END;

CREATE TRIGGER IF NOT EXISTS fu_never_deleted
BEFORE DELETE ON followups
BEGIN
    SELECT RAISE(ABORT, 'follow-ups are never deleted');
END;

-- ── Drafts ──────────────────────────────────────────────────────────────
-- The first release stops here: the AI decides and writes, a person sends.
CREATE TABLE IF NOT EXISTS followup_drafts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    followup_id   INTEGER NOT NULL,
    attempt       INTEGER NOT NULL,
    verdict       TEXT    NOT NULL,        -- SEND|DO_NOT_SEND|WAIT|ESCALATE_TO_HUMAN
    reason        TEXT    NOT NULL,        -- the AI's reasoning, shown to the approver
    body          TEXT,                    -- the proposed message (SEND only)
    trigger_note  TEXT    NOT NULL DEFAULT '',  -- why this came up now
    model         TEXT,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    status        TEXT    NOT NULL DEFAULT 'pending',  -- pending|approved|rejected|sent|expired
    decided_at    TEXT,
    decided_by    TEXT,
    decided_note  TEXT,
    edited_body   TEXT                     -- what staff changed it to, if they did
);

-- One pending draft per follow-up. A second evaluation cannot queue a rival
-- message for the same customer while one is waiting to be read.
CREATE UNIQUE INDEX IF NOT EXISTS idx_draft_pending
    ON followup_drafts(followup_id) WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS idx_draft_status
    ON followup_drafts(status, created_at);

-- ── Sends ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS followup_sends (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    followup_id   INTEGER NOT NULL,
    draft_id      INTEGER,
    attempt       INTEGER NOT NULL,
    sent_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    channel       TEXT    NOT NULL,
    phone         TEXT    NOT NULL,
    body          TEXT    NOT NULL,        -- exactly what the customer received
    wa_message_id TEXT,
    decided_by    TEXT    NOT NULL         -- 'staff:<name>' in draft mode
);

CREATE INDEX IF NOT EXISTS idx_fus_fu ON followup_sends(followup_id, id);

-- What a customer actually received is evidence. It is never deleted.
CREATE TRIGGER IF NOT EXISTS fus_never_deleted
BEFORE DELETE ON followup_sends
BEGIN
    SELECT RAISE(ABORT, 'sent messages are never deleted');
END;

-- ── Audit ───────────────────────────────────────────────────────────────
-- created evaluated skipped drafted approved rejected sent failed cancelled
-- opted_out closed
CREATE TABLE IF NOT EXISTS followup_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    followup_id     INTEGER,
    conversation_id INTEGER,
    event           TEXT    NOT NULL,
    detail          TEXT,
    actor           TEXT    NOT NULL DEFAULT 'system',
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fue_fu   ON followup_events(followup_id, id);
CREATE INDEX IF NOT EXISTS idx_fue_conv ON followup_events(conversation_id, id);
CREATE INDEX IF NOT EXISTS idx_fue_when ON followup_events(created_at);
