-- 086_install_authorisation.sql — Customer Installation Authorisation for a Starlink installation job (5.18.82, docs/61, docs/63).
--
-- The job itself is uCRM's scheduling job; the plugin holds no job table. These three tables sit BESIDE that job, keyed by
-- its id: the one authorisation record a job can carry, the append-only trail of what happened to it, and a hashed
-- rate ledger for the public acceptance page. Uganda only, and only while install_auth_enabled is on (lib/InstallAuth.php);
-- on every other install they stay empty. Additive: nothing in 001-085 is touched, and older code ignores them.
--
-- What is NOT stored, by decision (docs/61 D11): the browser's address or agent. What is NEVER stored: the raw acceptance
-- token — only its SHA-256 — so a copy of the database cannot authorise anything.

-- One row per uCRM job. A declined, cancelled or expired request may be superseded by a new one on the same row (a new
-- reference and a new token); an accepted record is never overwritten — its timestamp, terms version and hash stand.
CREATE TABLE IF NOT EXISTS install_auth (
    job_id               INTEGER PRIMARY KEY,                  -- uCRM scheduling job id (the "Installation Job number")
    crm_client_id        INTEGER NOT NULL DEFAULT 0,           -- uCRM client the job belongs to
    seq                  INTEGER NOT NULL,                     -- the counter inside acceptance_reference, never reused
    acceptance_reference TEXT    NOT NULL UNIQUE,              -- ACC-YYYYMMDD-NNNNNN (Kampala's date at the request)
    status               TEXT    NOT NULL DEFAULT 'pending',   -- pending | accepted | declined | cancelled | expired
    terms_version        TEXT    NOT NULL,                     -- InstallationTerms::VERSION at the request, e.g. INSTALLATION-TERMS-v1.0
    terms_hash           TEXT    NOT NULL,                     -- SHA-256 of the exact terms text the customer was shown
    price_snapshot       TEXT    NOT NULL,                     -- JSON: currency, installation, transport, other, other_label, total
    scope_snapshot       TEXT    NOT NULL,                     -- JSON: title, service, equipment, location, scheduled, scheduled_label, duration_min
    customer_name        TEXT    NOT NULL DEFAULT '',
    customer_phone       TEXT    NOT NULL DEFAULT '',          -- the number the request went to (uCRM contacts[0]); '' when none
    customer_email       TEXT    NOT NULL DEFAULT '',          -- the address the request went to (uCRM contacts[0]); '' when none
    requested_by         INTEGER NOT NULL,                     -- retailers.id of the staff member who requested it
    requested_by_name    TEXT    NOT NULL DEFAULT '',
    requested_at         TEXT    NOT NULL,                     -- UTC, Y-m-d H:i:s
    token_hash           TEXT    NOT NULL UNIQUE,              -- SHA-256 of the 32 random bytes in the link; the raw token is NEVER stored
    token_expires_at     TEXT    NOT NULL,                     -- UTC; max(requested + install_auth_link_days, scheduled date + 3 days) (D7)
    viewed_at            TEXT,                                 -- the last day the page was opened with a valid link
    accepted_at          TEXT,                                 -- UTC; set once, never changed
    accepted_method      TEXT,                                 -- web_link
    accepted_by_name     TEXT,                                 -- the name typed on the acceptance page
    declined_at          TEXT,
    decline_reason       TEXT,                                 -- the customer's own words, optional
    cancelled_at         TEXT,
    cancel_reason        TEXT,                                 -- staff reason, or job_deleted
    created_at           TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (status IN ('pending', 'accepted', 'declined', 'cancelled', 'expired'))
);
CREATE INDEX IF NOT EXISTS idx_install_auth_client ON install_auth(crm_client_id);
CREATE INDEX IF NOT EXISTS idx_install_auth_status ON install_auth(status, token_expires_at);

-- Append-only: what happened to the authorisation, in order. actor_kind says who: the customer (through the page), a staff
-- member (retailers.id in actor_id) or the system (the webhook, an expiry). detail is a short code or count — never a phone,
-- an e-mail, a token or a message text. The vocabulary is a CHECK so a misspelt event is a database error, not a silent gap.
CREATE TABLE IF NOT EXISTS install_auth_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id      INTEGER NOT NULL,
    event       TEXT    NOT NULL,
    actor_kind  TEXT    NOT NULL,                              -- customer | staff | system
    actor_id    INTEGER,                                       -- retailers.id for staff; NULL otherwise
    detail      TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (actor_kind IN ('customer', 'staff', 'system')),
    CHECK (event IN (
        'INSTALLATION_TERMS_SENT', 'INSTALLATION_TERMS_VIEWED', 'INSTALLATION_ACCEPTED', 'INSTALLATION_DECLINED',
        'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT', 'INSTALLATION_TECHNICIAN_NOTIFIED', 'INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED',
        'INSTALLATION_STARTED', 'INSTALLATION_COMPLETED', 'CUSTOMER_SIGNED_OFF', 'INSTALLATION_DISPUTED',
        'INSTALLATION_START_BLOCKED', 'INSTALLATION_REQUEST_CANCELLED', 'INSTALLATION_LINK_EXPIRED'
    ))
);
CREATE INDEX IF NOT EXISTS idx_install_auth_events_job ON install_auth_events(job_id, id);

CREATE TRIGGER IF NOT EXISTS install_auth_events_no_update
BEFORE UPDATE ON install_auth_events
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth_events: the authorisation trail is append-only; events are never changed');
END;

CREATE TRIGGER IF NOT EXISTS install_auth_events_no_delete
BEFORE DELETE ON install_auth_events
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth_events: the authorisation trail is append-only; events are never deleted');
END;

-- The public page's rate ledger, on the 082 pattern: rkey is an opaque bucket ('page:<sha256>' or 'post:<sha256>' of the
-- caller's address under a fixed label); no raw address is stored, and rows older than the window are purged on every write.
CREATE TABLE IF NOT EXISTS install_auth_rate (
    rkey  TEXT    NOT NULL,
    at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_install_auth_rate ON install_auth_rate(rkey, at);
