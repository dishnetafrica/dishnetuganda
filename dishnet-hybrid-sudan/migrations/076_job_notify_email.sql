-- 076_job_notify_email.sql — the e-mail copy of each job message (docs/44 §16.16).
--
-- Since the operator's "i need" of 28 September 2026, each job message the notifier sends by WhatsApp also goes, the
-- same text, by e-mail to the staff account's own address. outcome stays the WhatsApp's; these two columns say what
-- happened to the e-mail. A NULL email_outcome means there was nobody to e-mail: no staff account, or two. Only Uganda
-- writes them; on every other install job_notify_events stays empty. Additive: older code ignores the columns, and a
-- second run finds them there and moves on.
ALTER TABLE job_notify_events ADD COLUMN email_outcome TEXT;   -- sent | failed | no_email | not_configured; NULL = nobody to e-mail
ALTER TABLE job_notify_events ADD COLUMN email_detail TEXT;    -- a short reason; never an address or a message text
