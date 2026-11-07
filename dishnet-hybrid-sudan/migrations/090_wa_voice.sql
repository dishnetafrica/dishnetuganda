CREATE TABLE IF NOT EXISTS wa_media (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id    INTEGER NOT NULL,
    message_id         INTEGER,
    wa_message_id      TEXT    NOT NULL,
    instance           TEXT    NOT NULL,
    channel            TEXT    NOT NULL,
    remote_jid         TEXT    NOT NULL,
    from_me            INTEGER NOT NULL DEFAULT 0,
    kind               TEXT    NOT NULL,
    mimetype           TEXT,
    file_name          TEXT,
    caption            TEXT,
    declared_bytes     INTEGER,
    seconds            INTEGER,
    status             TEXT    NOT NULL DEFAULT 'pending',
    attempts           INTEGER NOT NULL DEFAULT 0,
    failure_reason     TEXT,
    fetched_bytes      INTEGER,
    fetched_mimetype   TEXT,
    sha256             TEXT,
    understanding      TEXT,
    understanding_kind TEXT,
    created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    ptt                INTEGER,
    voice_rung         TEXT,
    local_seconds      INTEGER,
    provider           TEXT,
    provider_model     TEXT,
    provider_ms        INTEGER,
    provider_seconds   INTEGER,
    transcript_chars   INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_media_wa_message_id ON wa_media(wa_message_id);
CREATE INDEX IF NOT EXISTS idx_wa_media_status ON wa_media(status, created_at);
CREATE INDEX IF NOT EXISTS idx_wa_media_conversation ON wa_media(conversation_id, id);

CREATE TABLE IF NOT EXISTS wa_voice_calls (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id          INTEGER NOT NULL,
    conversation_id   INTEGER NOT NULL,
    attempt           INTEGER NOT NULL,
    model             TEXT    NOT NULL,
    local_day         TEXT    NOT NULL,
    seconds_reserved  INTEGER NOT NULL CHECK (seconds_reserved >= 0),
    seconds_billed    INTEGER CHECK (seconds_billed IS NULL OR seconds_billed >= 0),
    cap_day_s         INTEGER NOT NULL CHECK (cap_day_s >= 0),
    voice_rung        TEXT    NOT NULL CHECK (voice_rung IN ('fetch_only','dry_run','handover','reply')),
    outcome           TEXT    NOT NULL DEFAULT 'started' CHECK (outcome IN ('started','ok','failed')),
    reason            TEXT,
    http              INTEGER,
    ms                INTEGER,
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    settled_at        TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_voice_calls_attempt ON wa_voice_calls(media_id, attempt);
CREATE INDEX IF NOT EXISTS idx_wa_voice_calls_day ON wa_voice_calls(local_day);
CREATE INDEX IF NOT EXISTS idx_wa_voice_calls_conv ON wa_voice_calls(conversation_id, local_day);

CREATE TABLE IF NOT EXISTS wa_voice_acks (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    wa_message_id    TEXT    NOT NULL,
    conversation_id  INTEGER NOT NULL,
    channel          TEXT    NOT NULL,
    handset          TEXT    NOT NULL,
    outcome          TEXT    NOT NULL CHECK (outcome IN ('claimed','sent','uncertain','failed','skipped')),
    reason           TEXT,
    variant          TEXT    CHECK (variant IS NULL OR variant IN ('A','B')),
    persona          INTEGER NOT NULL DEFAULT 0 CHECK (persona IN (0,1)),
    ledger_ref       TEXT,
    period_hours     INTEGER,
    gap_s            INTEGER,
    local_day        TEXT    NOT NULL,
    sent_at          TEXT,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (outcome = 'skipped' OR (period_hours IS NOT NULL AND gap_s IS NOT NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_voice_acks_msg ON wa_voice_acks(wa_message_id);
CREATE INDEX IF NOT EXISTS idx_wa_voice_acks_conv ON wa_voice_acks(conversation_id, created_at);
CREATE INDEX IF NOT EXISTS idx_wa_voice_acks_handset ON wa_voice_acks(handset, local_day);

CREATE TABLE IF NOT EXISTS _schema_checks (
  migration  TEXT PRIMARY KEY,
  expected   INTEGER NOT NULL,
  found      INTEGER NOT NULL,
  checked_at TEXT NOT NULL DEFAULT (datetime('now')),
  CHECK (found = expected));

INSERT INTO _schema_checks (migration, expected, found)
SELECT '090_wa_voice.sql', 79,
       (SELECT COUNT(*) FROM pragma_table_info('wa_media') WHERE name IN (
            'id','conversation_id','message_id','wa_message_id','instance','channel','remote_jid','from_me','kind',
            'mimetype','file_name','caption','declared_bytes','seconds','status','attempts','failure_reason',
            'fetched_bytes','fetched_mimetype','sha256','understanding','understanding_kind','created_at','updated_at',
            'ptt','voice_rung','local_seconds','provider','provider_model','provider_ms','provider_seconds',
            'transcript_chars'))
     + (SELECT COUNT(*) FROM pragma_table_info('wa_voice_calls') WHERE name IN (
            'id','media_id','conversation_id','attempt','model','local_day','seconds_reserved','seconds_billed',
            'cap_day_s','voice_rung','outcome','reason','http','ms','created_at','settled_at'))
     + (SELECT COUNT(*) FROM pragma_table_info('wa_voice_acks') WHERE name IN (
            'id','wa_message_id','conversation_id','channel','handset','outcome','reason','variant','persona',
            'ledger_ref','period_hours','gap_s','local_day','sent_at','created_at','updated_at'))
     + (SELECT COUNT(*) FROM sqlite_master
         WHERE tbl_name IN ('wa_media','wa_voice_calls','wa_voice_acks')
           AND name IN ('wa_media','wa_voice_calls','wa_voice_acks',
                        'idx_wa_media_wa_message_id','idx_wa_media_status','idx_wa_media_conversation',
                        'idx_wa_voice_calls_attempt','idx_wa_voice_calls_day','idx_wa_voice_calls_conv',
                        'idx_wa_voice_acks_msg','idx_wa_voice_acks_conv','idx_wa_voice_acks_handset'))
     + (SELECT COUNT(*) FROM sqlite_master
         WHERE name IN ('idx_wa_media_wa_message_id','idx_wa_voice_calls_attempt','idx_wa_voice_acks_msg')
           AND instr(upper(sql), 'UNIQUE') > 0);
