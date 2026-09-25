-- 074_client_search_index_flags.sql — Phase 2 of the customer-login audit (plan §E.6).
--
-- The customer index gains the e-mail identifier and the eligibility facts
-- the sign-in gates read. NULL means "not yet synced": cron_sync fills the
-- columns on its next pass and a NULL flag never refuses anyone. Additive;
-- older code ignores the columns.
ALTER TABLE client_search_index ADD COLUMN email TEXT NOT NULL DEFAULT '';
ALTER TABLE client_search_index ADD COLUMN is_lead INTEGER;
ALTER TABLE client_search_index ADD COLUMN is_archived INTEGER;
ALTER TABLE client_search_index ADD COLUMN is_active INTEGER;
ALTER TABLE client_search_index ADD COLUMN client_type INTEGER;
ALTER TABLE client_search_index ADD COLUMN has_service INTEGER;
ALTER TABLE client_search_index ADD COLUMN has_invoice INTEGER;
CREATE INDEX IF NOT EXISTS idx_csi_email ON client_search_index(email COLLATE NOCASE);
