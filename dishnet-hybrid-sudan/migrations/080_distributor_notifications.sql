-- 080_distributor_notifications.sql — distributor notifications, pilot core (WS-A P3, docs/49 §5/§7).
--
-- Adds the notification spine on top of the registry (078) and attribution (079):
--   dist_contacts         a distributor's VERIFIED notification number(s) (docs/49 §5, the verified-link model)
--   dist_notify_consent   per-distributor consent (CLASS_DISTRIBUTOR): a distributor can mute their own alerts
--   dist_notify_log       the draft->approve outbox + the dedup floor for distributor alerts
--
-- Rules baked in (docs/49 §7, §11; Bhavin B-4/B-5/B-6):
--   * DRAFT -> APPROVE, never auto-send. An event produces a DRAFT a human releases; nothing
--     is sent to a real number by this layer. (Mirrors the follow-ups screen: approve = queue.)
--   * NO LIVE SEND in the pilot. The approved row is queued only; connecting a real WhatsApp
--     number is a separate, explicitly-approved step. There is no transport bound here.
--   * VERIFIED recipient only. A number is used only when verified = 1; an unverified number
--     is never a destination. >1 verified resolves to ambiguous and is refused (fail safe).
--   * A customer's opt-out NEVER suppresses a distributor alert (CLASS_DISTRIBUTOR); only the
--     distributor's OWN mute does.
--   * ONE ALERT PER (distributor, event, entity). dedup_key is UNIQUE, so a webhook replay or a
--     double submit can never create a second draft — the row's own unique key is the claim.
--   * NEVER by phone for attribution/ownership — ownership comes from dist_customer_links (079),
--     keyed by the uCRM client id or the lead id. A phone here is only a verified DESTINATION.
--
-- Additive and self-contained: older code ignores these tables; every statement is idempotent;
-- nothing in 001-079 is touched. The whole feature stays behind the distributors_enabled flag and
-- is Uganda-gated in public.php, so Uganda (flag off) and South Sudan are unchanged until it is
-- deliberately enabled.

CREATE TABLE IF NOT EXISTS dist_contacts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_id  INTEGER NOT NULL,                 -- dist_partners.id
    phone       TEXT    NOT NULL,                 -- normalised international; a DESTINATION only, never an ownership key
    role        TEXT    NOT NULL DEFAULT 'owner',  -- owner | ops (display/intent only)
    verified     INTEGER NOT NULL DEFAULT 0,      -- 1 only after a deliberate verification step; never trusted from a form
    verified_at TEXT    NOT NULL DEFAULT '',
    verified_by TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by  TEXT    NOT NULL DEFAULT ''
);
-- One row per (partner, number): the same number is not added twice.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_contact_partner_phone ON dist_contacts(partner_id, phone);
CREATE INDEX        IF NOT EXISTS idx_dist_contact_partner       ON dist_contacts(partner_id);

CREATE TABLE IF NOT EXISTS dist_notify_consent (
    partner_id  INTEGER PRIMARY KEY,              -- dist_partners.id (one consent row per distributor)
    muted        INTEGER NOT NULL DEFAULT 0,      -- 1 = the distributor muted THEIR OWN alerts (never a customer opt-out)
    channel     TEXT    NOT NULL DEFAULT 'whatsapp',
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_by  TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS dist_notify_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_id   INTEGER NOT NULL,                -- the owning distributor (dist_partners.id)
    event        TEXT    NOT NULL,                -- lead_attributed | payment_received | customer_activated
    scope        TEXT    NOT NULL,                -- ucrm_client | lead (what the event is about)
    entity_id    TEXT    NOT NULL,                -- the uCRM client id or the lead id — NEVER a phone
    dedup_key    TEXT    NOT NULL,                -- 'DIST:<partner>:<event>:<scope>:<entity>' — the claim-before-build floor
    to_phone     TEXT    NOT NULL DEFAULT '',     -- the resolved VERIFIED destination, or '' if none is verified yet
    body         TEXT    NOT NULL DEFAULT '',     -- the drafted message (owning customer's OWN data only; privacy-guarded)
    status        TEXT    NOT NULL DEFAULT 'draft', -- draft | approved | sent | rejected | suppressed
    reason       TEXT    NOT NULL DEFAULT '',     -- why suppressed/held, or the send result note
    context_json TEXT    NOT NULL DEFAULT '',     -- the fields used to build, for the approver to judge
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by   TEXT    NOT NULL DEFAULT '',
    decided_by   TEXT    NOT NULL DEFAULT '',     -- who approved/rejected
    decided_at   TEXT    NOT NULL DEFAULT '',
    sent_at      TEXT    NOT NULL DEFAULT ''
);
-- One alert per (distributor, event, entity): the UNIQUE dedup key is the floor under
-- "exactly once" — a replayed webhook or a double submit hits it and no second draft is created.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_notify_dedup   ON dist_notify_log(dedup_key);
CREATE INDEX        IF NOT EXISTS idx_dist_notify_status  ON dist_notify_log(status);
CREATE INDEX        IF NOT EXISTS idx_dist_notify_partner ON dist_notify_log(partner_id);
