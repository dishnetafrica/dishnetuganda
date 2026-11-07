-- 091_wa_offers.sql — 5.18.104 P6 (root docs/73 §H.4.5, D11): offers on the Evolution numbers. Additive; read and
-- written only behind OfferSwitch (Uganda, offers_test_enabled / offers_live_enabled). South Sudan: empty tables.

CREATE TABLE IF NOT EXISTS wa_offers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    title          TEXT    NOT NULL,                    -- for staff only, never sent
    wording_1      TEXT    NOT NULL,
    wording_2      TEXT    NOT NULL,
    wording_3      TEXT,
    stop_line      TEXT    NOT NULL,                    -- OfferText::STOP_LINE, stamped at creation
    image_file     TEXT,                                -- basename in <data>/offer_images/, or NULL
    image_mime     TEXT,
    image_bytes    INTEGER,
    image_sha256   TEXT,
    content_hash   TEXT    NOT NULL,                    -- sha256 of the wordings, the stop line and image_sha256
    audience_size  TEXT    NOT NULL DEFAULT '100',
    state          TEXT    NOT NULL DEFAULT 'draft',
    live_slot      INTEGER,                             -- 1 while running or paused, else NULL (O-D1)
    warm_days      INTEGER,                             -- the settings in force at the start, recorded
    recent_days    INTEGER,
    queued_n       INTEGER,                             -- recipients in the snapshot
    created_by_id  INTEGER NOT NULL,
    created_by     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    started_at     TEXT,
    started_by     TEXT,
    closed_at      TEXT,
    closed_by      TEXT,
    close_reason   TEXT,                                -- stopped | done | expired | discarded
    CHECK (audience_size IN ('100', '200', 'all')),
    CHECK (state IN ('draft', 'running', 'paused', 'stopped', 'done')),
    CHECK (live_slot IS NULL OR live_slot = 1),
    CHECK ((state IN ('running', 'paused')) = (live_slot IS NOT NULL)),
    CHECK (state IN ('draft', 'stopped') OR started_at IS NOT NULL),
    CHECK (length(trim(title)) BETWEEN 1 AND 80),
    CHECK (length(wording_1) BETWEEN 20 AND 900 AND length(wording_2) BETWEEN 20 AND 900),
    CHECK (wording_3 IS NULL OR length(wording_3) BETWEEN 20 AND 900),
    CHECK (wording_2 <> wording_1 AND (wording_3 IS NULL OR (wording_3 <> wording_1 AND wording_3 <> wording_2))),
    CHECK ((image_file IS NULL AND image_sha256 IS NULL AND image_mime IS NULL AND image_bytes IS NULL)
        OR (image_file IS NOT NULL AND length(image_sha256) = 64
            AND image_mime IN ('image/jpeg', 'image/png', 'image/webp') AND image_bytes BETWEEN 1 AND 4194304))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wo_live  ON wa_offers(live_slot) WHERE live_slot IS NOT NULL;
CREATE INDEX        IF NOT EXISTS idx_wo_state ON wa_offers(state, id);

CREATE TABLE IF NOT EXISTS wa_offer_recipients (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    offer_id        INTEGER NOT NULL,
    phone           TEXT,                               -- international digits of the Uganda number; NULL once erased (Part F's A20, ruling R10)
    rank            INTEGER NOT NULL,                   -- 1 = the most recent chat at the start
    conversation_id INTEGER NOT NULL,                   -- the conversation last written on (re-read at the send)
    channel         TEXT    NOT NULL,
    handset         TEXT    NOT NULL,                   -- WaSendBudget::handset(): 'i:' . lower(trim(instance)) (C3)
    last_in_at      TEXT    NOT NULL,                   -- UTC, the customer's last message on an Evolution number, at the start
    state           TEXT    NOT NULL DEFAULT 'queued',
    not_before      TEXT,                               -- UTC: a deferral
    attempt         INTEGER NOT NULL DEFAULT 0,         -- +1 at each claim, for this row's own CAS only; the ledger ref is offer:<offer_id>:<id> (ruling R2)
    claim_token     TEXT,
    claimed_at      TEXT,
    judged_at       TEXT,                               -- UTC, when judge() decided; step 4 writes it (ruling R18)
    day_max         INTEGER,                            -- the per-handset offer ceiling step 5 applied (ruling R18)
    ledger_claim    INTEGER,                            -- WaSendBudget's claim handle, an int (Part F §H.2.5.1); written BEFORE the gateway is called
    variant         INTEGER,                            -- chosen with the ledger claim, before the send
    wa_message_id   TEXT,
    echo            TEXT,                               -- claimed | late | none: the echo claim's outcome at the send (O-D18)
    sent_at         TEXT,                               -- UTC, when the gateway returned (sent or may-have-been)
    settled_at      TEXT,
    reason          TEXT,                               -- a fixed code (§H.4.8, §H.4.10)
    replied_at      TEXT,                               -- first message from this number within 72 h of sent_at
    stop_at         TEXT,                               -- first opt-out or stop word within 72 h of sent_at
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (state IN ('queued', 'claimed', 'sent', 'uncertain', 'failed', 'skipped')),
    CHECK (variant IS NULL OR variant BETWEEN 1 AND 3),
    CHECK (echo IS NULL OR echo IN ('claimed', 'late', 'none')),
    CHECK (attempt >= 0),
    CHECK (handset GLOB 'i:?*'),
    CHECK (state <> 'claimed' OR (claim_token IS NOT NULL AND claimed_at IS NOT NULL AND attempt >= 1)),
    CHECK (state NOT IN ('sent', 'uncertain') OR sent_at IS NOT NULL),
    CHECK (state NOT IN ('sent', 'uncertain') OR (judged_at IS NOT NULL AND day_max IS NOT NULL)),
    CHECK (day_max IS NULL OR day_max BETWEEN 1 AND 60),
    CHECK (state <> 'sent' OR (variant IS NOT NULL AND wa_message_id IS NOT NULL AND echo IS NOT NULL)),
    CHECK (phone IS NULL OR (length(phone) BETWEEN 10 AND 15 AND phone NOT GLOB '*[^0-9]*'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wor_once  ON wa_offer_recipients(offer_id, phone);   -- one send per recipient per offer
CREATE INDEX        IF NOT EXISTS idx_wor_next  ON wa_offer_recipients(offer_id, state, handset, rank);
CREATE INDEX        IF NOT EXISTS idx_wor_phone ON wa_offer_recipients(phone, sent_at);

CREATE TABLE IF NOT EXISTS wa_offer_tests (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    offer_id       INTEGER NOT NULL,
    content_hash   TEXT    NOT NULL,                    -- what was tested
    variant        INTEGER NOT NULL,
    staff_id       INTEGER NOT NULL,                    -- the admin; the number is that account's own, read at the send, never stored
    channel        TEXT    NOT NULL,
    handset        TEXT    NOT NULL,                    -- WaSendBudget::handset()
    state          TEXT    NOT NULL DEFAULT 'sending',
    reason         TEXT,
    wa_message_id  TEXT,
    echo           TEXT,                                -- claimed | late | none
    ledger         TEXT,                                -- 'recorded', or the fixed code WaSendBudget::record() answered
    started_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    settled_at     TEXT,
    CHECK (state IN ('sending', 'sent', 'uncertain', 'failed')),
    CHECK (variant BETWEEN 1 AND 3),
    CHECK (handset GLOB 'i:?*'),
    CHECK (echo IS NULL OR echo IN ('claimed', 'late', 'none')),
    CHECK (state <> 'sent' OR (wa_message_id IS NOT NULL AND echo IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_wot_offer ON wa_offer_tests(offer_id, content_hash, variant, state);

CREATE TABLE IF NOT EXISTS wa_offer_events (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    offer_id  INTEGER NOT NULL,
    action    TEXT    NOT NULL,   -- created|edited|discarded|tested|started|paused|resumed|stopped|done|expired|
                                  -- paused_by_switch|paused_by_rollback|paused_stop_rate
    by_id     INTEGER,            -- the staff account; NULL = the system
    by_name   TEXT    NOT NULL,   -- the account's name, or 'system'
    detail    TEXT,               -- counts and fixed codes only
    at        TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_woe_offer ON wa_offer_events(offer_id, id);

CREATE TRIGGER IF NOT EXISTS wo_never_deleted BEFORE DELETE ON wa_offers
BEGIN SELECT RAISE(ABORT, 'wa_offers: an offer is history and is never deleted'); END;
CREATE TRIGGER IF NOT EXISTS wo_content_fixed
BEFORE UPDATE OF title, wording_1, wording_2, wording_3, stop_line, image_file, image_mime, image_bytes, image_sha256,
                 content_hash, audience_size ON wa_offers WHEN OLD.state <> 'draft'
BEGIN SELECT RAISE(ABORT, 'wa_offers: what an offer says is fixed once it has started'); END;
CREATE TRIGGER IF NOT EXISTS wo_closed_is_final BEFORE UPDATE ON wa_offers WHEN OLD.state IN ('stopped', 'done')
BEGIN SELECT RAISE(ABORT, 'wa_offers: a stopped or finished offer is final'); END;
CREATE TRIGGER IF NOT EXISTS wo_state_path BEFORE UPDATE OF state ON wa_offers
WHEN (NEW.state = 'draft' AND OLD.state <> 'draft') OR (OLD.state = 'draft' AND NEW.state IN ('paused', 'done'))
BEGIN SELECT RAISE(ABORT, 'wa_offers: no such change of state'); END;

CREATE TRIGGER IF NOT EXISTS wor_never_deleted BEFORE DELETE ON wa_offer_recipients
BEGIN SELECT RAISE(ABORT, 'wa_offer_recipients: a recipient row is history and is never deleted'); END;
CREATE TRIGGER IF NOT EXISTS wor_final BEFORE UPDATE OF state ON wa_offer_recipients
WHEN OLD.state IN ('sent', 'uncertain', 'failed', 'skipped')
BEGIN SELECT RAISE(ABORT, 'wa_offer_recipients: a settled recipient is final'); END;
CREATE TRIGGER IF NOT EXISTS wor_identity_fixed BEFORE UPDATE OF offer_id, rank, last_in_at ON wa_offer_recipients
BEGIN SELECT RAISE(ABORT, 'wa_offer_recipients: who and in which order is fixed'); END;
CREATE TRIGGER IF NOT EXISTS wor_phone_erase_only BEFORE UPDATE OF phone ON wa_offer_recipients WHEN NEW.phone IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'wa_offer_recipients: a phone may only be erased'); END;
CREATE TRIGGER IF NOT EXISTS wor_outcome_fixed
BEFORE UPDATE OF conversation_id, channel, handset, attempt, ledger_claim, variant, wa_message_id, echo, sent_at,
                 reason, judged_at, day_max
ON wa_offer_recipients WHEN OLD.state IN ('sent', 'uncertain', 'failed', 'skipped')
BEGIN SELECT RAISE(ABORT, 'wa_offer_recipients: what happened is fixed'); END;
CREATE TRIGGER IF NOT EXISTS wor_marks_once BEFORE UPDATE OF replied_at, stop_at ON wa_offer_recipients
WHEN (OLD.replied_at IS NOT NULL AND NEW.replied_at IS NOT OLD.replied_at)
  OR (OLD.stop_at IS NOT NULL AND NEW.stop_at IS NOT OLD.stop_at)
BEGIN SELECT RAISE(ABORT, 'wa_offer_recipients: a reply or a STOP is recorded once'); END;

CREATE TRIGGER IF NOT EXISTS wot_never_deleted BEFORE DELETE ON wa_offer_tests
BEGIN SELECT RAISE(ABORT, 'wa_offer_tests: a test is history and is never deleted'); END;
CREATE TRIGGER IF NOT EXISTS wot_final BEFORE UPDATE OF state ON wa_offer_tests
WHEN OLD.state IN ('sent', 'uncertain', 'failed')
BEGIN SELECT RAISE(ABORT, 'wa_offer_tests: a settled test is final'); END;
CREATE TRIGGER IF NOT EXISTS woe_never_deleted BEFORE DELETE ON wa_offer_events
BEGIN SELECT RAISE(ABORT, 'wa_offer_events: the trail is never deleted'); END;
CREATE TRIGGER IF NOT EXISTS woe_never_changed BEFORE UPDATE ON wa_offer_events
BEGIN SELECT RAISE(ABORT, 'wa_offer_events: the trail is never changed'); END;

-- The self-check (C2), into _schema_checks, the one table 089, 090 and 091 share. The CREATE below is 089's statement
-- byte for byte (ruling R9; one test compares the three texts), so 091 verifies itself even where 089's statement failed.
-- Its CHECK (found = expected) refuses a short count, and that refusal is not swallowed, so migration.log says PARTIAL for
-- 091 and the deploy refuses (OF-M2). The runner strips this comment before the split (lib/MigrationRunner.php:94); the
-- INSERT counts by name and holds neither CREATE nor INDEX, so no error of it is swallowed (:226-233; wa_offers A, OM82).
CREATE TABLE IF NOT EXISTS _schema_checks (
  migration  TEXT PRIMARY KEY,
  expected   INTEGER NOT NULL,
  found      INTEGER NOT NULL,
  checked_at TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (found = expected));
INSERT INTO _schema_checks (migration, expected, found)
SELECT '091_wa_offers.sql', 25, count(*) FROM sqlite_master
 WHERE name IN ('wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events',
                'idx_wo_live', 'idx_wo_state', 'idx_wor_once', 'idx_wor_next', 'idx_wor_phone', 'idx_wot_offer', 'idx_woe_offer',
                'wo_never_deleted', 'wo_content_fixed', 'wo_closed_is_final', 'wo_state_path',
                'wor_never_deleted', 'wor_final', 'wor_identity_fixed', 'wor_phone_erase_only', 'wor_outcome_fixed', 'wor_marks_once',
                'wot_never_deleted', 'wot_final', 'woe_never_deleted', 'woe_never_changed');
