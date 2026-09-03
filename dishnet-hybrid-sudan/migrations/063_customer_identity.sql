-- 063: DishNet customer email identities + Starlink mail events.
-- Additive only. Nothing existing is altered.
--
-- customer_identities: one permanent @dishnetuganda.com identity per uCRM
-- client. The UNIQUE constraints ARE the idempotency mechanism: replaying
-- client.add or retrying a provision can never mint a second address for the
-- same client, and never the same address for two clients. An identity is
-- permanent for the life of the customer relationship — package, kit and
-- billing changes never touch it; termination moves status to 'suspended'
-- (retention), never to deletion here. Deletion is a manual, policy-gated
-- admin act against the mail server, recorded by setting status='disabled'.

-- This table is deliberately its own work queue (pending_action + attempts +
-- updated_at) rather than riding the shared events queue: event_processor.php
-- claims unfiltered batches from that queue and acks types it does not know,
-- so a dedicated-worker job type parked there can be silently swallowed.
-- A row can never be swallowed.

CREATE TABLE IF NOT EXISTS customer_identities (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id      INTEGER NOT NULL UNIQUE,
    email          TEXT    NOT NULL UNIQUE,
    local_part     TEXT    NOT NULL,
    mailbox_type   TEXT    NOT NULL DEFAULT 'mailbox',   -- 'mailbox' (real, webmail) | 'alias'
    status         TEXT    NOT NULL DEFAULT 'pending',   -- pending|provisioned|failed|suspended|disabled
    pending_action TEXT,                                 -- provision|suspend|reactivate|NULL (queue)
    provider       TEXT    NOT NULL DEFAULT 'stalwart',
    provider_ref   TEXT,
    attempts       INTEGER NOT NULL DEFAULT 0,
    last_error     TEXT,
    created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    provisioned_at TEXT,
    suspended_at   TEXT
);

CREATE INDEX IF NOT EXISTS idx_ci_pending ON customer_identities(pending_action) WHERE pending_action IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_ci_status  ON customer_identities(status);
CREATE INDEX IF NOT EXISTS idx_ci_email  ON customer_identities(email);

-- starlink_events: every Starlink email processed from the intake mailbox,
-- exactly once (UNIQUE message_id). This table is also the AI audit trail:
-- what the model saw (subject/from + body hash), what it said (extracted_json,
-- confidence, ai_model), and what the system did about it (status).
CREATE TABLE IF NOT EXISTS starlink_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id      TEXT    NOT NULL UNIQUE,
    client_id       INTEGER,                              -- NULL = unmatched, needs human review
    identity_email  TEXT,
    from_addr       TEXT,
    subject         TEXT,
    received_at     TEXT,
    type            TEXT    NOT NULL DEFAULT 'OTHER',
    extracted_json  TEXT,
    confidence      REAL    NOT NULL DEFAULT 0,
    action_required INTEGER NOT NULL DEFAULT 0,
    ai_model        TEXT,
    body_sha256     TEXT,
    status          TEXT    NOT NULL DEFAULT 'new',       -- new|notified|alerted|resolved|ignored
    created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_sle_client  ON starlink_events(client_id, created_at);
CREATE INDEX IF NOT EXISTS idx_sle_status  ON starlink_events(status) WHERE status = 'new';
