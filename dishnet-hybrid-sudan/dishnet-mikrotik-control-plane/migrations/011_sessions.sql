-- 011 — sessions and accounting ingestion
--
-- RADIUS accounting arrives over UDP. Three things follow, and each is a bug
-- if it is not handled:
--
--   RETRANSMITS ARE NORMAL. A NAS that does not get a reply resends. Ingest
--     must be idempotent, not merely tolerant.
--   PACKETS REORDER. An Interim-Update can arrive after the Stop it precedes.
--     It must not reopen a closed session, and it must not regress counters.
--   COUNTERS ARE 32-BIT. Acct-Input-Octets wraps at 4 GiB. The high bits ride
--     in Acct-Input-Gigawords, and a deployment that ignores them silently
--     under-reports every session over 4 GiB. That is a reporting bug that
--     looks like light usage rather than like a fault.

CREATE TABLE mt_sessions (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id       uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  voucher_id        uuid REFERENCES mt_vouchers(id) ON DELETE RESTRICT,

  -- The NAS's own identity for this session. Unique WITH the NAS, because
  -- two routers can independently choose the same Acct-Session-Id.
  acct_session_id   text NOT NULL,
  nas_identifier    text NOT NULL DEFAULT '',
  radius_username   text NOT NULL,

  mac               text,
  ip                text,

  bytes_in          bigint NOT NULL DEFAULT 0,
  bytes_out         bigint NOT NULL DEFAULT 0,

  state             text NOT NULL DEFAULT 'open'
                    CHECK (state IN ('open','closed','reaped')),
  terminate_cause   text,

  started_at        timestamptz NOT NULL DEFAULT now(),
  last_seen_at      timestamptz NOT NULL DEFAULT now(),
  ended_at          timestamptz,

  UNIQUE (nas_identifier, acct_session_id)
);
CREATE INDEX mt_sessions_customer_ix ON mt_sessions (customer_id, state, started_at DESC);
CREATE INDEX mt_sessions_open_ix     ON mt_sessions (last_seen_at) WHERE state = 'open';
CREATE INDEX mt_sessions_voucher_ix  ON mt_sessions (voucher_id);

ALTER TABLE mt_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_sessions FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_sessions_isolation ON mt_sessions
  USING (customer_id = mt_current_customer())
  WITH CHECK (customer_id = mt_current_customer());

-- Accounting is evidence of what was used. It is not editable after the fact.
CREATE OR REPLACE FUNCTION mt_sessions_no_delete() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  RAISE EXCEPTION 'sessions are closed, not deleted' USING ERRCODE = 'DN409';
END $$;
CREATE TRIGGER mt_sessions_no_delete BEFORE DELETE ON mt_sessions
  FOR EACH ROW EXECUTE FUNCTION mt_sessions_no_delete();

-- ---------------------------------------------------------------------------
-- Ingest one accounting record.
--
-- SECURITY DEFINER because accounting arrives from the network side with no
-- customer context: the username is the only identity presented, and which
-- customer it belongs to is the answer rather than the input. The username is
-- namespaced per customer (migration 010), so the lookup IS the authorization.
--
-- Returns the session id, or NULL when the username is unknown — an unknown
-- username must not create a session belonging to nobody.
CREATE OR REPLACE FUNCTION mt_session_account(
  p_status    text,          -- Start | Interim-Update | Stop
  p_username  text,
  p_session   text,
  p_nas       text,
  p_in_octets bigint,
  p_out_octets bigint,
  p_mac       text,
  p_ip        text,
  p_cause     text
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE hu record; sid uuid; cur record;
BEGIN
  SELECT voucher_id, customer_id INTO hu
    FROM mt_hotspot_users WHERE radius_username = p_username;
  IF NOT FOUND THEN RETURN NULL; END IF;

  SELECT * INTO cur FROM mt_sessions
   WHERE nas_identifier = COALESCE(p_nas, '') AND acct_session_id = p_session;

  IF NOT FOUND THEN
    -- A Stop or Interim for a session we never saw Start for still has to
    -- land: the Start packet may simply have been the one that was lost.
    INSERT INTO mt_sessions
      (customer_id, voucher_id, acct_session_id, nas_identifier, radius_username,
       mac, ip, bytes_in, bytes_out, state, terminate_cause, ended_at)
    VALUES
      (hu.customer_id, hu.voucher_id, p_session, COALESCE(p_nas, ''), p_username,
       p_mac, p_ip, GREATEST(COALESCE(p_in_octets, 0), 0), GREATEST(COALESCE(p_out_octets, 0), 0),
       CASE WHEN p_status = 'Stop' THEN 'closed' ELSE 'open' END,
       CASE WHEN p_status = 'Stop' THEN p_cause ELSE NULL END,
       CASE WHEN p_status = 'Stop' THEN now() ELSE NULL END)
    RETURNING id INTO sid;
    RETURN sid;
  END IF;

  -- A closed session is final. A late Interim — reordered, or a retransmit
  -- overtaken by its own Stop — must not reopen it or move its numbers.
  IF cur.state <> 'open' THEN
    RETURN cur.id;
  END IF;

  UPDATE mt_sessions
     SET -- GREATEST, never assignment: a retransmitted earlier Interim would
         -- otherwise shrink the counters and under-report the session.
         bytes_in  = GREATEST(bytes_in,  COALESCE(p_in_octets,  0)),
         bytes_out = GREATEST(bytes_out, COALESCE(p_out_octets, 0)),
         mac = COALESCE(p_mac, mac),
         ip  = COALESCE(p_ip,  ip),
         last_seen_at = now(),
         state = CASE WHEN p_status = 'Stop' THEN 'closed' ELSE state END,
         terminate_cause = CASE WHEN p_status = 'Stop' THEN p_cause ELSE terminate_cause END,
         ended_at = CASE WHEN p_status = 'Stop' THEN now() ELSE ended_at END
   WHERE id = cur.id;

  RETURN cur.id;
END $$;

-- ---------------------------------------------------------------------------
-- Close sessions whose NAS stopped talking.
--
-- An Accounting-Stop lost with the tunnel leaves a row open forever
-- (docs/49 §2.2). Left alone, "devices online" drifts upward and never
-- returns, and any future concurrency feature would refuse paying guests
-- seats held by sessions that ended hours ago.
--
-- Reaped is its own state, not 'closed': a session nobody told us about
-- ending is weaker evidence than one that reported its own Stop, and
-- reconciliation should be able to tell them apart.
CREATE OR REPLACE FUNCTION mt_sessions_reap(p_stale interval)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer;
BEGIN
  UPDATE mt_sessions
     SET state = 'reaped', ended_at = last_seen_at,
         terminate_cause = 'no accounting update within threshold'
   WHERE state = 'open' AND last_seen_at < now() - p_stale;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;

GRANT EXECUTE ON FUNCTION mt_session_account(text,text,text,text,bigint,bigint,text,text,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_sessions_reap(interval) TO dnb_app;
