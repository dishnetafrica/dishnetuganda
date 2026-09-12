-- 069_contact_optouts.sql — who has asked us to stop messaging them.
--
-- Built BEFORE any proactive outbound exists, deliberately.
--
-- Until now the assistant has only ever REPLIED: every message it sent was
-- invited by one the customer had just sent, so there was nothing to opt out
-- of. A follow-up system breaks that invariant on its first send. This table
-- has to exist, and be honoured by every automatic send path, before the
-- first unsolicited message is possible — not alongside it.
--
-- SCOPE is the part worth reading twice. "Stop messaging me" means stop
-- bothering me; it does not usually mean "ignore me if I write to you". A
-- customer who opts out and then asks a question should still get an answer,
-- or the assistant simply looks broken. So:
--
--   proactive  we never start a conversation. Replies still happen.
--   all        nothing automatic at all, including replies.
--
-- 'all' is the one to use when somebody is angry; 'proactive' is what a plain
-- STOP means.

CREATE TABLE IF NOT EXISTS contact_optouts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    phone         TEXT    NOT NULL,
    channel       TEXT    NOT NULL DEFAULT '*',        -- '*' = every channel
    scope         TEXT    NOT NULL DEFAULT 'proactive',-- proactive | all
    crm_client_id INTEGER,
    active        INTEGER NOT NULL DEFAULT 1,          -- 0 = superseded by re-consent
    reason        TEXT    NOT NULL,                    -- customer_request|not_interested|staff|bounce
    source        TEXT    NOT NULL,                    -- keyword|ai_classified|admin|api
    evidence      TEXT,                                -- the exact message that caused it
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by    TEXT    NOT NULL DEFAULT 'system',
    lifted_at     TEXT,
    lifted_by     TEXT
);

-- One live opt-out per phone per channel. A second insert for the same pair
-- is refused by the database rather than quietly duplicating, so "are they
-- opted out" has exactly one answer.
CREATE UNIQUE INDEX IF NOT EXISTS idx_optout_live
    ON contact_optouts(phone, channel) WHERE active = 1;

CREATE INDEX IF NOT EXISTS idx_optout_client
    ON contact_optouts(crm_client_id) WHERE crm_client_id IS NOT NULL;

-- An opt-out is a record of something a person asked us for. Lifting one sets
-- active = 0 and writes a new row; it never rewrites the original, so the
-- history of what they asked for survives a later change of mind.
CREATE TRIGGER IF NOT EXISTS optout_never_deleted
BEFORE DELETE ON contact_optouts
BEGIN
    SELECT RAISE(ABORT, 'an opt-out is never deleted — lift it instead');
END;
