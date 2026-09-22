-- 005 — idempotency foundation
-- docs/30 Artifact 9: every state-changing route takes an Idempotency-Key and
-- replays the first response. A phone on a bad connection WILL retry, and a
-- second attempt must return the first answer, not perform the action twice.

CREATE TABLE mt_idempotency (
  key              text NOT NULL,
  customer_id      uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  principal_id     uuid REFERENCES mt_principals(id) ON DELETE SET NULL,
  endpoint         text NOT NULL,
  request_digest   text NOT NULL,   -- so the same key with a different body is caught
  response_status  integer,
  response_body    jsonb,
  state            text NOT NULL DEFAULT 'in_flight'
                   CHECK (state IN ('in_flight','completed')),
  created_at       timestamptz NOT NULL DEFAULT now(),
  completed_at     timestamptz,
  PRIMARY KEY (customer_id, key)
);
CREATE INDEX mt_idempotency_created_ix ON mt_idempotency (created_at);

COMMENT ON COLUMN mt_idempotency.request_digest IS
  'Replaying a key with a DIFFERENT body is a client bug, not a retry. Storing '
  'the digest lets it be rejected rather than silently answered with the '
  'previous response.';
