-- 075_job_notifications.sql — release B of J1–J8 (docs/44 §5.4, §16.12, §16.14).
--
-- Per uCRM job, who was last told what, and every change the job notifier saw. The notifier compares uCRM's job
-- now with the state row, in one BEGIN IMMEDIATE transaction, so each change sends exactly one message however many
-- paths see it and however often uCRM redelivers. title is kept for the "cancelled" notice: uCRM's job is gone by
-- then. accepted_by is the claim for the completion link after Accept: one per assignment, however many presses
-- race. Only Uganda reads or writes these tables; on every other install they stay empty. Additive: older code
-- ignores them.
CREATE TABLE IF NOT EXISTS job_notify_state (
    job_id       INTEGER PRIMARY KEY,          -- uCRM scheduling job id
    assignee_id  INTEGER,                      -- uCRM user id last told; NULL = nobody assigned
    job_time     TEXT    NOT NULL DEFAULT '',  -- the job's time as last told, UTC 'Y-m-d H:i'; '' = not scheduled
    job_status   INTEGER,                      -- uCRM status at the last observation
    title        TEXT    NOT NULL DEFAULT '',  -- uCRM's title at the last observation
    gone         INTEGER NOT NULL DEFAULT 0,   -- 1 once uCRM answered 404 for the job
    accepted_by  INTEGER,                      -- uCRM user id sent the completion link for this assignment; NULL = not yet
    version      INTEGER NOT NULL DEFAULT 1,
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- One row per message sent or refused, and per change recorded without a message. Never a phone number or a text.
CREATE TABLE IF NOT EXISTS job_notify_events (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id            INTEGER NOT NULL,
    event             TEXT    NOT NULL,  -- assigned | reassigned | rescheduled | unassigned | cancelled | closed | accepted
    message           TEXT,              -- assigned | accepted | reassigned_away | new_time | removed | cancelled; NULL = none
    from_assignee_id  INTEGER,
    to_assignee_id    INTEGER,
    from_time         TEXT,
    to_time           TEXT,
    source            TEXT    NOT NULL,  -- my_jobs | bulk | reschedule | ucrm_webhook | accept
    staff_id          INTEGER,           -- the staff account messaged (retailers.id), if any
    outcome           TEXT    NOT NULL,  -- sent | failed | recorded | no_staff_account | ambiguous_staff_account | no_usable_number
    detail            TEXT,              -- a short reason; never a phone number or a message text
    created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_job_notify_events_job ON job_notify_events(job_id, id);
