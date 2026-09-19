-- 008 — the intent queue
--
-- docs/42 §0, frozen: Queued -> Sent -> Confirmed -> Failed -> Expired.
-- No screen issues a synchronous router command; every change to a router is
-- a row here first.
--
-- DELIVERY IS AT-LEAST-ONCE, AND THIS FILE DOES NOT PRETEND OTHERWISE.
--
-- A worker can die after sending a command but before recording that it sent
-- it. There is no way to distinguish that from dying before sending, so the
-- intent is retried and the command may arrive twice. Exactly-once delivery
-- across a process boundary is not available; what IS available is making the
-- second arrival harmless. That is what idempotency_key is for, and it is why
-- every operation delivered through this queue must be idempotent at the
-- far end.
--
-- Nothing here says WHEN an intent reaches a router. B1 is unresolved
-- (docs/49): neither push nor poll may be asserted, in a column name, a state
-- name, a comment or a message.

CREATE TABLE mt_intents (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id         uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  actor_principal_id  uuid REFERENCES mt_principals(id) ON DELETE SET NULL,
  actor_kind          text NOT NULL DEFAULT 'principal'
                      CHECK (actor_kind IN ('principal','staff','system')),

  kind                text NOT NULL,
  target_type         text,
  target_id           text,
  payload             jsonb NOT NULL DEFAULT '{}'::jsonb,

  state               text NOT NULL DEFAULT 'queued'
                      CHECK (state IN ('queued','sent','confirmed','failed','expired')),

  -- Replay protection for the ACTION, distinct from mt_idempotency which
  -- protects the HTTP response. A retried request must not enqueue twice.
  idempotency_key     text,

  attempts            integer NOT NULL DEFAULT 0,
  max_attempts        integer NOT NULL DEFAULT 5,
  next_attempt_at     timestamptz NOT NULL DEFAULT now(),

  -- The lease. A worker claims a row for a bounded time; if it dies, the
  -- lease lapses and the row becomes claimable again. This is what makes a
  -- crash recoverable rather than a lost intent.
  claimed_by          text,
  claimed_at          timestamptz,
  lease_expires_at    timestamptz,

  created_at          timestamptz NOT NULL DEFAULT now(),
  sent_at             timestamptz,
  confirmed_at        timestamptz,
  failed_at           timestamptz,
  deadline_at         timestamptz NOT NULL DEFAULT now() + interval '7 days',
  last_error          text
);

CREATE UNIQUE INDEX mt_intents_idem_uq
  ON mt_intents (customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX mt_intents_claimable_ix
  ON mt_intents (state, next_attempt_at) WHERE state IN ('queued','sent');
CREATE INDEX mt_intents_customer_ix ON mt_intents (customer_id, created_at DESC);

ALTER TABLE mt_intents ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_intents FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_intents_isolation ON mt_intents
  USING (customer_id = mt_current_customer())
  WITH CHECK (customer_id = mt_current_customer());

-- ---------------------------------------------------------------------------
-- The state machine, enforced by the database.
--
-- In application code a state machine is a convention: one forgotten branch
-- and an intent goes from confirmed back to queued, and a router is
-- reconfigured for a request the customer was told had completed.
CREATE OR REPLACE FUNCTION mt_intent_transition() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.state = OLD.state THEN RETURN NEW; END IF;

  IF OLD.state IN ('confirmed','failed','expired') THEN
    RAISE EXCEPTION 'intent % is terminal in state %, cannot move to %',
      OLD.id, OLD.state, NEW.state USING ERRCODE = 'DN409';
  END IF;

  IF NOT (
       (OLD.state = 'queued' AND NEW.state IN ('sent','failed','expired'))
    OR (OLD.state = 'sent'   AND NEW.state IN ('confirmed','failed','expired','queued'))
  ) THEN
    RAISE EXCEPTION 'illegal intent transition % -> %', OLD.state, NEW.state
      USING ERRCODE = 'DN409';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER mt_intents_transition BEFORE UPDATE ON mt_intents
  FOR EACH ROW EXECUTE FUNCTION mt_intent_transition();

-- ---------------------------------------------------------------------------
-- Claim work. SECURITY DEFINER because a worker runs without a customer
-- context: it serves every customer, so it cannot set app.customer_id before
-- knowing which row it gets.
--
-- FOR UPDATE SKIP LOCKED is what lets several workers run at once without
-- two of them claiming the same row, and without one blocking behind another.
CREATE OR REPLACE FUNCTION mt_intent_claim(p_worker text, p_lease interval, p_limit integer)
RETURNS SETOF mt_intents
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  RETURN QUERY
  WITH claimable AS (
    SELECT id FROM mt_intents
     WHERE state = 'queued'
       AND next_attempt_at <= now()
       AND deadline_at > now()
       AND (lease_expires_at IS NULL OR lease_expires_at < now())
     ORDER BY created_at
     FOR UPDATE SKIP LOCKED
     LIMIT p_limit
  )
  UPDATE mt_intents i
     SET claimed_by = p_worker, claimed_at = now(),
         lease_expires_at = now() + p_lease
    FROM claimable c WHERE i.id = c.id
  RETURNING i.*;
END $$;

-- Sweep intents past their deadline. Runs without a customer context.
CREATE OR REPLACE FUNCTION mt_intent_expire_overdue()
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer;
BEGIN
  UPDATE mt_intents
     SET state = 'expired', last_error = 'deadline passed', failed_at = now()
   WHERE state IN ('queued','sent') AND deadline_at <= now();
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;

GRANT EXECUTE ON FUNCTION mt_intent_claim(text,interval,integer) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_intent_expire_overdue()             TO dnb_app;
