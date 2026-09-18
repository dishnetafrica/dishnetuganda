-- Migration 072: coordinates of a WhatsApp location pin, on the message itself.
--
-- Before this, a pin was stored as the body text "[LOCATION]" and the latitude
-- and longitude were never read out of the webhook payload at all. The one
-- fact an installation depends on was discarded at the door.
--
-- Columns rather than a metadata key because these get read back: the worker
-- attaches the conversation's most recent pin to the lead it writes, and it
-- must not have to parse JSON in every message row to find one.
ALTER TABLE wa_messages ADD COLUMN location_lat REAL DEFAULT NULL;
ALTER TABLE wa_messages ADD COLUMN location_lng REAL DEFAULT NULL;

-- Finding the newest pin in a conversation is the only query these serve.
CREATE INDEX IF NOT EXISTS idx_wa_msg_location
    ON wa_messages(conversation_id, sent_at)
    WHERE location_lat IS NOT NULL;
