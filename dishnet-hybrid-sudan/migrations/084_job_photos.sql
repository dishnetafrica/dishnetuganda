-- 084_job_photos.sql — site photos on a uCRM job, and where the technician was when it was completed (5.18.66).
--
-- My Jobs on Uganda lets the assignee photograph the kit, the cable used and the router (lib/JobPhotos.php), and
-- records the browser's location when the job is marked completed. Real tables, deliberately: job_completions,
-- job_checkins and staff_live_locations are id-keyed JSON lists that SqliteStore::load() strips of their keys unless
-- the table is in $FLAT_TABLES, which is why the live map shows nothing. The files live under the data directory,
-- uploads/job_photos/<job id>/, named by the server; a row says where. Only Uganda reads or writes these tables; on
-- every other install they stay empty. Additive: older code ignores them.
CREATE TABLE IF NOT EXISTS job_photos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id        INTEGER NOT NULL,                   -- uCRM scheduling job id
    label         TEXT    NOT NULL,                   -- kit | cable | model | other (JobPhotos::LABELS; never free text)
    retailer_id   INTEGER NOT NULL,                   -- retailers.id of the person who took it
    ucrm_user_id  INTEGER,                            -- their VERIFIED uCRM link at the time (StaffDirectory::linkedUcrmUser)
    assignee_id   INTEGER,                            -- the job's assignedUserId as uCRM answered at the time
    technician    TEXT    NOT NULL DEFAULT '',        -- their display name at the time
    file_rel      TEXT    NOT NULL,                   -- uploads/job_photos/<job id>/<name>, under the data directory
    mime          TEXT    NOT NULL,
    bytes         INTEGER NOT NULL,                   -- as stored, after re-encoding
    width         INTEGER,
    height        INTEGER,
    sha256        TEXT    NOT NULL,                   -- of the stored file
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_job_photos_job ON job_photos(job_id, id);

-- One row per job: the browser's position when "Mark as Completed" was pressed, or the reason there was none.
-- Neither is taken on trust (JobPhotos::validateGps). A job completed twice (reopened in uCRM) keeps the latest.
CREATE TABLE IF NOT EXISTS job_completion_gps (
    job_id          INTEGER PRIMARY KEY,              -- uCRM scheduling job id
    retailer_id     INTEGER NOT NULL,                 -- retailers.id of the person who completed it
    lat             REAL,                             -- NULL with source = 'missing'
    lon             REAL,
    accuracy_m      REAL,                             -- the browser's own estimate, metres; NULL when it gave none
    source          TEXT    NOT NULL,                 -- browser | missing
    missing_reason  TEXT,                             -- the technician's words when there was no fix; never a number
    client_ts       TEXT,                             -- the fix's own timestamp as the browser reported it
    captured_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);
