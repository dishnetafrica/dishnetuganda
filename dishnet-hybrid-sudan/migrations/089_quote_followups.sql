-- 089_quote_followups.sql — 5.18.104 (root docs/73 §H.2.4): the quote follow-up tables, the one send ledger (C3),
-- the content-free "seen" marks and the self-check table _schema_checks.
--
-- Additive and dark: no UPDATE, no DELETE, no backfill. It runs on every install, South Sudan included, and only
-- Uganda's gated code (StaffJobsGate, with its own switch on) ever writes a row. Nothing here changes what 5.18.103 does.
--
-- The runner's hazards (lib/MigrationRunner.php) shape the text:
--   - every file is marked applied whatever happened, so the last statement verifies the file itself, and the
--     verdict is the _schema_checks row, never the log line;
--   - a failing statement is swallowed when it says "no such column" and contains CREATE, or "no such table" and
--     contains INDEX, so the self-check INSERT below contains neither word in any case;
--   - the trigger splitter ends a body at the three letters e-n-d with no left word boundary, so every trigger body
--     is exactly SELECT RAISE(ABORT, '...'); and no other word in a body ends in those letters.
-- The column notes below are stripped before the split (MigrationRunner::stripComments), so they change nothing.

CREATE TABLE IF NOT EXISTS _schema_checks (
  migration  TEXT PRIMARY KEY,
  expected   INTEGER NOT NULL,
  found      INTEGER NOT NULL,
  checked_at TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (found = expected));

CREATE TABLE IF NOT EXISTS quote_tracker (
  quote_key       TEXT PRIMARY KEY,                    -- 'ref:' || the number exactly as the customer's text shows it
  quote_ref       TEXT NOT NULL,
  quote_id        INTEGER,                             -- uCRM quote id when the number maps to one
  crm_client_id   INTEGER,
  phone           TEXT NOT NULL,                       -- digits, international; 'erased' after an erase (A20)
  evidence_msg_id INTEGER NOT NULL,                    -- wa_messages.id of the earliest confirming row
  evidence_event  TEXT NOT NULL,
  made_by         TEXT NOT NULL,                       -- kyc | lead | manual | ucrm | resend | lte
  sent_at         TEXT NOT NULL,                       -- UTC
  sent_conv_id    INTEGER NOT NULL,
  sent_channel    TEXT NOT NULL,                       -- the Evolution channel it left from: support | account
  valid_until     TEXT NOT NULL,                       -- Kampala date
  owner_staff_id  INTEGER,
  owner_source    TEXT NOT NULL DEFAULT 'none',
  spoke_conv_id   INTEGER, spoke_channel TEXT,
  backfilled      INTEGER NOT NULL DEFAULT 0,
  status          TEXT NOT NULL DEFAULT 'open',
  status_at       TEXT, status_reason TEXT,            -- a fixed code, e.g. unread_inbound
  chase_hold      TEXT,                                -- why no automatic chase; NULL = none
  followup_id     INTEGER,
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (status IN ('open','answered','opted_out','taken','paid','registered','accepted','declined',
                    'superseded','expired','colleague')),
  CHECK (backfilled IN (0,1)));
CREATE UNIQUE INDEX IF NOT EXISTS idx_qt_quote_id ON quote_tracker(quote_id) WHERE quote_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_qt_open  ON quote_tracker(status, sent_at) WHERE status = 'open';
CREATE INDEX IF NOT EXISTS idx_qt_phone ON quote_tracker(phone, sent_at);
CREATE INDEX IF NOT EXISTS idx_qt_owner ON quote_tracker(owner_staff_id, status);
CREATE TRIGGER IF NOT EXISTS qt_never_deleted BEFORE DELETE ON quote_tracker
BEGIN SELECT RAISE(ABORT, 'quote_tracker: a tracked quotation is history and is never deleted'); END;
CREATE TRIGGER IF NOT EXISTS qt_status_once BEFORE UPDATE OF status ON quote_tracker WHEN OLD.status <> 'open'
BEGIN SELECT RAISE(ABORT, 'quote_tracker: a quotation leaves open once'); END;
CREATE TRIGGER IF NOT EXISTS qt_identity_fixed BEFORE UPDATE OF quote_key, sent_at, evidence_msg_id ON quote_tracker
BEGIN SELECT RAISE(ABORT, 'quote_tracker: what was sent and when is fixed'); END;
CREATE TRIGGER IF NOT EXISTS qt_phone_erase_only BEFORE UPDATE OF phone ON quote_tracker WHEN NEW.phone <> 'erased'
BEGIN SELECT RAISE(ABORT, 'quote_tracker: a phone is fixed and may only be erased'); END;

ALTER TABLE followups ADD COLUMN kind TEXT NOT NULL DEFAULT 'enquiry';
ALTER TABLE followups ADD COLUMN quote_key TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_fu_quote ON followups(quote_key) WHERE quote_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS wa_auto_sends (             -- THE LEDGER (C3): every automated send per handset
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  handset       TEXT NOT NULL,                         -- 'i:' || lower(trim(Evolution instance)); never a channel id
  kind          TEXT NOT NULL,
  ref           TEXT NOT NULL,                         -- the sender's reference: (kind, ref) is one message
  try           INTEGER NOT NULL DEFAULT 1,
  mode          TEXT NOT NULL,                         -- claim: asked before the send | record: written after it
  proactive     INTEGER NOT NULL,                      -- 1: counts against the daily and hourly caps
  phone         TEXT,                                  -- customer kinds only: international digits, no '+', normalised by the ledger; NULL when unreadable or erased
  local_day     TEXT NOT NULL,                         -- Africa/Kampala date of the claim
  gap_s         INTEGER NOT NULL,                      -- what a proactive send must wait after this row
  claimed_at    TEXT NOT NULL DEFAULT (datetime('now')),
  outcome       TEXT,                                  -- NULL in flight | sent | uncertain | not_sent
  settled_at    TEXT,
  wa_message_id TEXT,                                  -- our own automation (C7)
  detail        TEXT,                                  -- a fixed code, never message text
  win_from      INTEGER,                               -- claim: the opening hour judged by (ruling R18)
  win_to        INTEGER,                               -- claim: the closing hour judged by
  cap_day       INTEGER,                               -- claim: the daily cap applied (voice_ack: its ceiling)
  cap_hour      INTEGER,                               -- claim: the hourly cap applied (voice_ack: NULL)
  CHECK (handset GLOB 'i:?*'),
  CHECK (kind IN ('quote','enquiry','staff_list','offer','reminder','winback','brief','voice_ack','handover_alert','other')),
  CHECK (proactive = (kind IN ('quote','enquiry','staff_list','offer','reminder','winback','brief'))),
  CHECK (mode IN ('claim','record')),
  CHECK (mode = 'record' OR kind IN ('quote','enquiry','staff_list','offer','voice_ack')),
  CHECK (mode = 'claim' OR outcome IS NOT NULL),
  CHECK (try BETWEEN 1 AND 3),
  CHECK (outcome IS NULL OR outcome IN ('sent','uncertain','not_sent')),
  CHECK (gap_s BETWEEN 0 AND 900),
  CHECK (mode = 'claim' OR (win_from IS NULL AND win_to IS NULL AND cap_day IS NULL AND cap_hour IS NULL)),
  CHECK (mode = 'record' OR (win_from IS NOT NULL AND win_to IS NOT NULL AND cap_day IS NOT NULL
                             AND (cap_hour IS NULL) = (kind = 'voice_ack'))),
  CHECK (win_from IS NULL
         OR (kind = 'voice_ack' AND win_from BETWEEN 7 AND 20 AND win_to BETWEEN win_from + 1 AND 21)
         OR (kind <> 'voice_ack' AND win_from BETWEEN 8 AND 19 AND win_to BETWEEN win_from + 1 AND 20)),
  CHECK (cap_day IS NULL OR cap_day BETWEEN 1 AND 60),
  CHECK (cap_hour IS NULL OR cap_hour BETWEEN 1 AND 30));
CREATE UNIQUE INDEX IF NOT EXISTS idx_was_ref   ON wa_auto_sends(kind, ref, try);
CREATE INDEX IF NOT EXISTS idx_was_day   ON wa_auto_sends(handset, local_day);
CREATE INDEX IF NOT EXISTS idx_was_time  ON wa_auto_sends(handset, claimed_at);
CREATE INDEX IF NOT EXISTS idx_was_phone ON wa_auto_sends(phone, local_day) WHERE phone IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_was_waid  ON wa_auto_sends(wa_message_id) WHERE wa_message_id IS NOT NULL;
CREATE TRIGGER IF NOT EXISTS was_never_deleted BEFORE DELETE ON wa_auto_sends
  WHEN OLD.claimed_at > datetime('now', '-400 days')
BEGIN SELECT RAISE(ABORT, 'wa_auto_sends: the ledger is kept for 400 days'); END;
CREATE TRIGGER IF NOT EXISTS was_settled_once BEFORE UPDATE OF outcome ON wa_auto_sends WHEN OLD.outcome IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'wa_auto_sends: a claim is settled once'); END;
CREATE TRIGGER IF NOT EXISTS was_identity_fixed BEFORE UPDATE OF handset, kind, ref, try, mode, proactive, local_day,
  gap_s, claimed_at, win_from, win_to, cap_day, cap_hour ON wa_auto_sends
BEGIN SELECT RAISE(ABORT, 'wa_auto_sends: what was claimed is fixed'); END;
CREATE TRIGGER IF NOT EXISTS was_phone_erase_only BEFORE UPDATE OF phone ON wa_auto_sends WHEN NEW.phone IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'wa_auto_sends: a phone may only be erased'); END;

CREATE TABLE IF NOT EXISTS quote_list_sends (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  list_date  TEXT NOT NULL,                            -- Kampala date
  owner_key  TEXT NOT NULL,                            -- 'staff:<retailers id>' | 'central'
  outcome    TEXT NOT NULL,
  item_count INTEGER NOT NULL DEFAULT 0,
  detail     TEXT,                                     -- a missed list's last code, digit_run or no_attempt
  sections   TEXT,                                     -- per section its count and listed quote_keys (JSON), no text
  created_at TEXT NOT NULL DEFAULT (datetime('now')),  -- a built list: the moment its build began to read
  CHECK (outcome IN ('sent','uncertain','not_sent','missed')),
  CHECK ((outcome = 'missed') = (sections IS NULL)));
CREATE UNIQUE INDEX IF NOT EXISTS idx_qls_day ON quote_list_sends(list_date, owner_key) WHERE outcome <> 'not_sent';
CREATE TRIGGER IF NOT EXISTS qls_never_deleted BEFORE DELETE ON quote_list_sends
BEGIN SELECT RAISE(ABORT, 'quote_list_sends: a list record is never deleted'); END;

-- quote_followup_state holds k/v rows, no phone and no text: tracker_cursor, tracker_ran and
-- ref_conflict:<quote_key> (the tracker); list_swept and list_last:<date>:<owner_key> (the list);
-- wasender:<local_day>:<kind> and noinstance:<local_day>:<kind> (the hooks)
CREATE TABLE IF NOT EXISTS quote_followup_state (k TEXT PRIMARY KEY, v TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')));

CREATE TABLE IF NOT EXISTS wa_seen_marks (             -- R-01, R-07: a message the webhook could not store; no text
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  direction     TEXT NOT NULL,                         -- in: a customer's | out: the handset's, with no text
  phone         TEXT NOT NULL,                         -- the customer's digits; '' for an inbound with no number, or once erased
  channel       TEXT NOT NULL,
  instance      TEXT NOT NULL,
  kind          TEXT NOT NULL,                         -- the envelope key, or lid_no_number
  wa_message_id TEXT NOT NULL,
  seen_at       TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (direction IN ('in','out')));
CREATE UNIQUE INDEX IF NOT EXISTS idx_wsm_msg   ON wa_seen_marks(wa_message_id);
CREATE INDEX IF NOT EXISTS idx_wsm_phone ON wa_seen_marks(phone, direction, seen_at);
CREATE TRIGGER IF NOT EXISTS wsm_never_deleted BEFORE DELETE ON wa_seen_marks
  WHEN OLD.seen_at > datetime('now', '-400 days')
BEGIN SELECT RAISE(ABORT, 'wa_seen_marks: a mark is kept for 400 days'); END;
CREATE TRIGGER IF NOT EXISTS wsm_never_changed BEFORE UPDATE OF direction, channel, instance, kind, wa_message_id, seen_at ON wa_seen_marks
BEGIN SELECT RAISE(ABORT, 'wa_seen_marks: a mark is never changed'); END;
CREATE TRIGGER IF NOT EXISTS wsm_phone_erase_only BEFORE UPDATE OF phone ON wa_seen_marks WHEN NEW.phone <> ''
BEGIN SELECT RAISE(ABORT, 'wa_seen_marks: a phone may only be erased'); END;
INSERT INTO _schema_checks (migration, expected, found)
SELECT '089_quote_followups.sql', 38,
   (SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN
      ('_schema_checks','quote_tracker','wa_auto_sends','quote_list_sends','quote_followup_state','wa_seen_marks'))
 + (SELECT COUNT(*) FROM sqlite_master WHERE type NOT IN ('table','trigger','view') AND name IN
      ('idx_qt_quote_id','idx_qt_open','idx_qt_phone','idx_qt_owner','idx_fu_quote','idx_was_ref','idx_was_day',
       'idx_was_time','idx_was_phone','idx_was_waid','idx_qls_day','idx_wsm_msg','idx_wsm_phone'))
 + (SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND sql LIKE '%END' AND name IN
      ('qt_never_deleted','qt_status_once','qt_identity_fixed','qt_phone_erase_only','was_never_deleted',
       'was_settled_once','was_identity_fixed','was_phone_erase_only','qls_never_deleted','wsm_never_deleted',
       'wsm_never_changed','wsm_phone_erase_only'))
 + (SELECT COUNT(*) FROM pragma_table_info('followups') WHERE name IN ('kind','quote_key'))
 + (SELECT COUNT(*) FROM pragma_table_info('wa_auto_sends') WHERE name IN ('win_from','win_to','cap_day','cap_hour'))
 + (SELECT COUNT(*) FROM pragma_table_info('quote_list_sends') WHERE name = 'sections');
