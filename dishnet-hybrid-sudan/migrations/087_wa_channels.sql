-- 087_wa_channels.sql — the WhatsApp channel registry (5.18.86, docs/65 §J; multi-number Batch 1 part D).
--
-- One row per WhatsApp number DishNet operates. Until now the plugin knew three fixed departments — sales, support,
-- account — each bound to one Evolution instance by a configuration key (evo_instance_sales / _support / _account), and the
-- department was the identity: a second sales number had nowhere to go. A row here binds one number's Evolution instance
-- to a channel id, a role (the brain's sales / support / account behaviour), an owner, a territory and its switches.
--
-- Dark: nothing reads this table unless multi_number_channels_enabled is ON, and only on Uganda (lib/ChannelRegistry.php).
-- Additive: nothing in 001-086 is touched; no conversation, message, lead or event is rewritten.
--
-- The three existing numbers are its first three rows, under their PRESENT ids — 'sales', 'support', 'account', the very
-- strings stored in wa_conversations.channel — so every stored conversation is already keyed by its channel id. Their
-- Evolution instance is NOT copied here: it stays where it is configured today (the evo_instance_* keys and their legacy
-- fallbacks, resolved by EvolutionApiService::configInstanceMap), so this migration guesses no production value and a change
-- made on the uCRM Configuration screen keeps working exactly as before. evo_instance is NULL on exactly those three rows.
--
-- What is never stored: a credential. The Evolution API key stays in the configuration; a business number is data on its
-- row and appears masked wherever the plugin writes a log (the audit trail below included).
CREATE TABLE IF NOT EXISTS wa_channels (
    channel_id          TEXT    PRIMARY KEY,                    -- 'sales' | 'support' | 'account' | e.g. 'sales-002', 'retailer-001'
    evo_instance        TEXT,                                   -- the Evolution instance name, exactly; NULL = the department's configured key
    business_number     TEXT,                                   -- E.164 of the number, from Evolution's own report once verified; NULL until then
    display_name        TEXT    NOT NULL,                       -- how staff see it, e.g. 'Sales — Kampala 2'
    role                TEXT    NOT NULL,                       -- the brain's role on this number: sales | support | account
    owner_type          TEXT    NOT NULL DEFAULT 'department',  -- department | staff (a retailers.json staff row) | partner (dist_partners)
    owner_staff_id      INTEGER,                                -- retailers.json id, when owner_type = staff
    owner_partner_id    INTEGER,                                -- dist_partners.id, when owner_type = partner
    territory_region_id INTEGER,                                -- dist_regions.id, or NULL
    portfolio_scope     TEXT    NOT NULL DEFAULT 'all',         -- own | territory | all — what "this number's customers" means (later batches)
    ai_enabled          INTEGER NOT NULL DEFAULT 1,             -- 0: messages are stored, the AI does not answer on this number
    handover_to         TEXT    NOT NULL DEFAULT 'department',  -- owner | department | central (later batches)
    status              TEXT    NOT NULL DEFAULT 'active',      -- active | paused | disabled | retired; anything but active is refused
    verified_at         TEXT,                                   -- when the instance <-> number binding was checked
    verified_by         TEXT,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (role IN ('sales', 'support', 'account')),
    CHECK (owner_type IN ('department', 'staff', 'partner')),
    CHECK (portfolio_scope IN ('own', 'territory', 'all')),
    CHECK (ai_enabled IN (0, 1)),
    CHECK (handover_to IN ('owner', 'department', 'central')),
    CHECK (status IN ('active', 'paused', 'disabled', 'retired')),
    -- Only the three department rows take their instance from configuration; every other channel names its own.
    CHECK ((evo_instance IS NULL AND channel_id IN ('sales', 'support', 'account'))
        OR (evo_instance IS NOT NULL AND length(trim(evo_instance)) > 0)),
    -- The owner id matches the owner type, and nothing else.
    CHECK ((owner_type = 'department' AND owner_staff_id IS NULL AND owner_partner_id IS NULL)
        OR (owner_type = 'staff'      AND owner_staff_id IS NOT NULL AND owner_partner_id IS NULL)
        OR (owner_type = 'partner'    AND owner_partner_id IS NOT NULL AND owner_staff_id IS NULL))
);

-- One instance, one channel — case-insensitive, as the webhook compares it. One number, one channel.
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_channels_instance ON wa_channels (lower(evo_instance)) WHERE evo_instance IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_channels_number   ON wa_channels (business_number)     WHERE business_number IS NOT NULL;

-- The trail of every change to a channel: who, what, from, to, why. Append-only, as a property of the database.
CREATE TABLE IF NOT EXISTS wa_channel_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id  TEXT    NOT NULL,
    action      TEXT    NOT NULL,             -- seeded | created | status | ai_enabled | instance | number | owner
    old_value   TEXT,
    new_value   TEXT,                          -- a business number is written masked, never whole
    actor       TEXT    NOT NULL,
    reason      TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_wa_channel_log_channel ON wa_channel_log (channel_id, id);

CREATE TRIGGER IF NOT EXISTS wa_channel_log_append_only_update
BEFORE UPDATE ON wa_channel_log
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'wa_channel_log: the channel trail is append-only; a row is never changed');
END;

CREATE TRIGGER IF NOT EXISTS wa_channel_log_append_only_delete
BEFORE DELETE ON wa_channel_log
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'wa_channel_log: the channel trail is append-only; a row is never deleted');
END;

-- A channel is retired, never deleted: conversations, leads and the trail keep naming it.
CREATE TRIGGER IF NOT EXISTS wa_channels_never_deleted
BEFORE DELETE ON wa_channels
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'wa_channels: a channel is retired, never deleted');
END;

-- The three numbers that exist today, under the ids their conversations already carry.
INSERT OR IGNORE INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, portfolio_scope, ai_enabled, handover_to, status)
VALUES ('sales',   NULL, 'Sales',    'sales',   'department', 'all', 1, 'department', 'active');
INSERT OR IGNORE INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, portfolio_scope, ai_enabled, handover_to, status)
VALUES ('support', NULL, 'Support',  'support', 'department', 'all', 1, 'department', 'active');
INSERT OR IGNORE INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, portfolio_scope, ai_enabled, handover_to, status)
VALUES ('account', NULL, 'Accounts', 'account', 'department', 'all', 1, 'department', 'active');

INSERT INTO wa_channel_log (channel_id, action, new_value, actor, reason)
SELECT 'sales', 'seeded', 'department channel; instance from evo_instance_sales', 'migration 087', 'the number that exists today'
WHERE NOT EXISTS (SELECT 1 FROM wa_channel_log WHERE channel_id = 'sales' AND action = 'seeded');
INSERT INTO wa_channel_log (channel_id, action, new_value, actor, reason)
SELECT 'support', 'seeded', 'department channel; instance from evo_instance_support (or evo_instance_name)', 'migration 087', 'the number that exists today'
WHERE NOT EXISTS (SELECT 1 FROM wa_channel_log WHERE channel_id = 'support' AND action = 'seeded');
INSERT INTO wa_channel_log (channel_id, action, new_value, actor, reason)
SELECT 'account', 'seeded', 'department channel; instance from evo_instance_account (or evo_accounts_instance_name)', 'migration 087', 'the number that exists today'
WHERE NOT EXISTS (SELECT 1 FROM wa_channel_log WHERE channel_id = 'account' AND action = 'seeded');
