-- 086_install_authorisation.sql — Customer Installation Authorisation for a Starlink installation job (5.18.82, hardened in
-- 5.18.83 before it was ever deployed; docs/61, docs/63, docs/64).
--
-- The job itself is uCRM's scheduling job; the plugin holds no job table. These tables sit BESIDE that job, keyed by its id:
-- the one authorisation record a job can carry, the append-only trail of what happened to it, the activation snapshot of
-- the jobs already in progress when the feature was switched on (D3), and a hashed rate ledger for the public acceptance
-- page. Uganda only, and only while install_auth_enabled is on (lib/InstallAuth.php); on every other install they stay
-- empty. Additive: nothing in 001-085 is touched, and older code ignores them.
--
-- What is NOT stored, by decision (docs/61 D11): the browser's address or agent. What is NEVER stored: the raw acceptance
-- token — only its SHA-256 — so a copy of the database cannot authorise anything.

-- One row per uCRM job. A declined, cancelled or expired request may be superseded by a new one on the same row (a new
-- reference and a new token); an accepted record is never overwritten — its timestamp, terms version and hash stand. The
-- triggers below make that a property of the database, not only of the code that writes it.
CREATE TABLE IF NOT EXISTS install_auth (
    job_id               INTEGER PRIMARY KEY,                  -- uCRM scheduling job id (the "Installation Job number")
    crm_client_id        INTEGER NOT NULL DEFAULT 0,           -- uCRM client the job belongs to; an acceptance binds this client only
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
    CHECK (status IN ('pending', 'accepted', 'declined', 'cancelled', 'expired')),
    CHECK (status <> 'accepted' OR (accepted_at IS NOT NULL AND accepted_method IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS idx_install_auth_client ON install_auth(crm_client_id);
CREATE INDEX IF NOT EXISTS idx_install_auth_status ON install_auth(status, token_expires_at);

-- An accepted authorisation is final: not one of its columns changes, whoever writes — the page, a staff action, a tool or
-- a hand at the sqlite prompt. A dispute is an event beside it, never an edit of it.
CREATE TRIGGER IF NOT EXISTS install_auth_accepted_is_final
BEFORE UPDATE ON install_auth
FOR EACH ROW WHEN OLD.status = 'accepted'
BEGIN
    SELECT RAISE(ABORT, 'install_auth: an accepted authorisation is final; it is never changed');
END;

-- No record is ever deleted: a declined, withdrawn or expired request is part of the job's history too.
CREATE TRIGGER IF NOT EXISTS install_auth_never_deleted
BEFORE DELETE ON install_auth
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth: an authorisation record is never deleted');
END;

-- A request's status moves only along the lifecycle: pending to accepted, declined, cancelled or expired; and a declined,
-- cancelled or expired request back to pending only as a NEW request (a higher seq — a new reference and a new token).
CREATE TRIGGER IF NOT EXISTS install_auth_status_moves
BEFORE UPDATE OF status ON install_auth
FOR EACH ROW WHEN NEW.status IS NOT OLD.status AND NOT (
       (OLD.status = 'pending' AND NEW.status IN ('accepted', 'declined', 'cancelled', 'expired'))
    OR (OLD.status IN ('declined', 'cancelled', 'expired') AND NEW.status = 'pending' AND NEW.seq > OLD.seq)
)
BEGIN
    SELECT RAISE(ABORT, 'install_auth: that status change is not part of the authorisation lifecycle');
END;

-- What the customer is asked to accept — the terms, their hash, the charges, the scope, the customer and the job — is
-- fixed once the request is sent. Only a new request (a declined, cancelled or expired one superseded at a higher seq) may
-- carry new snapshots.
CREATE TRIGGER IF NOT EXISTS install_auth_request_is_fixed
BEFORE UPDATE ON install_auth
FOR EACH ROW WHEN (
       NEW.job_id IS NOT OLD.job_id
    OR (    (   NEW.terms_version IS NOT OLD.terms_version OR NEW.terms_hash IS NOT OLD.terms_hash
             OR NEW.price_snapshot IS NOT OLD.price_snapshot OR NEW.scope_snapshot IS NOT OLD.scope_snapshot
             OR NEW.acceptance_reference IS NOT OLD.acceptance_reference OR NEW.seq IS NOT OLD.seq
             OR NEW.crm_client_id IS NOT OLD.crm_client_id OR NEW.customer_name IS NOT OLD.customer_name
             OR NEW.customer_phone IS NOT OLD.customer_phone OR NEW.customer_email IS NOT OLD.customer_email)
        AND NOT (OLD.status IN ('declined', 'cancelled', 'expired') AND NEW.status = 'pending' AND NEW.seq > OLD.seq))
)
BEGIN
    SELECT RAISE(ABORT, 'install_auth: a request''s terms, charges, scope and customer are fixed once it is sent');
END;

-- Append-only: what happened to the authorisation, in order. actor_kind says who: the customer (through the page), a staff
-- member (retailers.id in actor_id) or the system (the webhook, an expiry, the activation). detail is a short code or count —
-- never a phone, an e-mail, a token or a message text. The vocabulary is a CHECK so a misspelt event is a database error,
-- not a silent gap.
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
        'INSTALLATION_START_BLOCKED', 'INSTALLATION_REQUEST_CANCELLED', 'INSTALLATION_LINK_EXPIRED',
        'INSTALLATION_EXEMPTED', 'INSTALLATION_STARTED_WITHOUT_ACCEPTANCE', 'INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE'
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

-- D3, made explicit: each time the feature is switched on (tools/set_config.php --key install_auth_enabled --value 1), the
-- Starlink installation jobs uCRM holds IN PROGRESS at that moment are read and recorded here, once. Those jobs — and only
-- those — may be completed without an acceptance. A job that reaches "in progress" later, by any path, is not exempt.
-- Both tables are append-only: an exemption is a fact about the moment of activation, not a setting.
CREATE TABLE IF NOT EXISTS install_auth_activations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    activated_at  TEXT    NOT NULL,                            -- UTC, the moment uCRM was read
    activated_by  TEXT    NOT NULL DEFAULT '',                 -- who ran it: the tool and the shell user
    jobs_read     INTEGER NOT NULL DEFAULT 0,                  -- in-progress jobs uCRM returned
    exempted      INTEGER NOT NULL DEFAULT 0                   -- of those, Starlink installation jobs recorded below
);

CREATE TABLE IF NOT EXISTS install_auth_exempt (
    job_id         INTEGER PRIMARY KEY,                        -- uCRM scheduling job id
    activation_id  INTEGER NOT NULL,                           -- install_auth_activations.id that saw it in progress
    crm_client_id  INTEGER NOT NULL DEFAULT 0,
    title          TEXT    NOT NULL DEFAULT '',
    recorded_at    TEXT    NOT NULL
);

CREATE TRIGGER IF NOT EXISTS install_auth_activations_no_update
BEFORE UPDATE ON install_auth_activations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth_activations: an activation is history; it is never changed');
END;

CREATE TRIGGER IF NOT EXISTS install_auth_activations_no_delete
BEFORE DELETE ON install_auth_activations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth_activations: an activation is history; it is never deleted');
END;

CREATE TRIGGER IF NOT EXISTS install_auth_exempt_no_update
BEFORE UPDATE ON install_auth_exempt
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth_exempt: an exemption records the moment of activation; it is never changed');
END;

CREATE TRIGGER IF NOT EXISTS install_auth_exempt_no_delete
BEFORE DELETE ON install_auth_exempt
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'install_auth_exempt: an exemption records the moment of activation; it is never deleted');
END;

-- The public page's rate ledger: rkey is an opaque bucket ('page:<sha256>' or 'post:<sha256>' of the link's own token hash
-- under a fixed label) — no address and no token is stored, and rows older than the window are purged on every write. A
-- link that names no record never reaches the ledger: the page writes nothing for it.
CREATE TABLE IF NOT EXISTS install_auth_rate (
    rkey  TEXT    NOT NULL,
    at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_install_auth_rate ON install_auth_rate(rkey, at);
