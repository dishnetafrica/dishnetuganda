# 83 — The Admin cross-customer read boundary: evidence and design

**Status: DESIGN AND EVIDENCE ONLY. No privilege created, no role granted, no
migration written, no production change.** `DenyAllIdentity` still admits
nobody. The customer RLS boundary is untouched.

---

# 1. ADMIN READ SURFACE

The eleven estate reads declared in `AdminRoutes`, each already
capability-gated and currently answering `501 estate_access_not_authorized`.

| # | Endpoint | Capability | Cross-customer required? |
|---|---|---|---|
| R1 | `GET /admin/customers` | `customers.read` | **yes** — the list *is* the estate |
| R2 | `GET /admin/customers/{id}` | `customers.read` | yes |
| R3 | `GET /admin/sites` | `sites.read` | yes |
| R4 | `GET /admin/routers` | `routers.read` | yes |
| R5 | `GET /admin/routers/{id}` | `routers.read` | yes |
| R6 | `GET /admin/plans` | `plans.read` | yes |
| R7 | `GET /admin/vouchers` | `vouchers.read` | yes |
| R8 | `GET /admin/voucher-batches` | `vouchers.read` | yes |
| R9 | `GET /admin/sessions` | `sessions.read` | yes |
| R10 | `GET /admin/intents` | `intents.read` | yes |
| R11 | `GET /admin/audit` | `audit.read` | yes |
| R12 | `GET /admin/health` | `health.read` | **no** — process facts only, no table |

---

# 2. TABLE / COLUMN MAP

**Measured from the live schema**, not from migration text. 21 `mt_*` tables:
**18 tenant-scoped, every one `RLS + FORCE`**. Three are not tenant-scoped:
`mt_customers` (keyed on `id`, still `RLS + FORCE`), and `mt_profiles` /
`mt_migrations`, which carry **no RLS at all**.

### 2.1 Per-read detail

| Read | Tables | Columns exposed (AdminProjection) | Tenant-scoped | Direct table access needed? | Leak risk |
|---|---|---|---|---|---|
| R1/R2 customers | `mt_customers` | id, name, ucrm_client_id, status, created_at | RLS+FORCE on `id` | **no** | low — no secret column |
| R3 sites | `mt_sites` | id, customer_id, service_id, name, location, created_at | yes | no | low |
| R4/R5 routers | `mt_devices` | id, customer_id, site_id, serial, model, ros_version, tunnel_ip, name, state, wan_*, staged_*, claimed_at, last_seen_at | yes | no | **`wg_pubkey` must be excluded** |
| — device config | `mt_device_config` | **none** | yes | **no — not read at all** | desired config is provisioning internals |
| — device secrets | `mt_device_secrets` | **none** | yes | **never** | **`secret_sealed`** |
| R6 plans | `mt_plans` (+ `mt_profiles`) | plan commercial + technical fields | plans yes; **profiles have NO RLS** | profiles: yes, trivially | low |
| R7 vouchers | `mt_vouchers` | id, customer_id, batch_id, plan_id, site_id, state, price, currency, duration, timestamps | yes | no | **`code` must be excluded** |
| R8 batches | `mt_voucher_batches` | id, customer_id, site_id, plan_id, counts, state, timestamps | yes | no | low |
| R9 sessions | `mt_sessions` | id, customer_id, voucher_id, nas_identifier, framed_ip, timestamps, bytes | yes | no | low |
| R10 intents | `mt_intents` | id, customer_id, kind, state, payload, attempts, last_error, target, timestamps | yes | no | **`payload`/`last_error` may carry router-shaped detail** |
| R11 audit | `mt_audit_log` | id, customer_id, actor, actor_kind, action, target, source, detail, at | yes | no | **`detail` is free-form jsonb** |
| — telemetry | `mt_uplink_samples` | not yet on the surface | yes | no | low |
| — entitlements | `mt_entitlements` | not yet on the surface | yes | no | low |

### 2.2 The six security-bearing columns, and where they must stop

Found by scanning every column name for `secret|password|hash|token|sealed|privkey|pubkey|code|credential`:

| Column | Admin exposure |
|---|---|
| `mt_auth_codes.code_hash` | **never** — table not reachable from the admin surface |
| `mt_auth_sessions.token_hash` | **never** |
| `mt_principals.credential_hash` | **never** |
| `mt_device_secrets.secret_sealed` | **never** — no admin read touches this table |
| `mt_devices.wg_pubkey` | **excluded** from `AdminProjection::ROUTER` |
| `mt_vouchers.code` | **excluded** from `AdminProjection::VOUCHER` — a batch print is a deliberate audited action, not a side effect of opening a list |

Four tables are therefore **out of the admin read boundary entirely**:
`mt_auth_codes`, `mt_auth_sessions`, `mt_device_secrets`, `mt_device_config`.
`mt_principals` is out until a screen needs it, and then only without
`credential_hash`.

### 2.3 Two findings worth acting on

**F-A. `mt_profiles` needs no privilege at all.** It has no RLS, no tenant
column, and is DishNet-owned reference data. R6 can join it freely — one fewer
thing to grant.

**F-B. `mt_intents.payload`, `mt_intents.last_error` and `mt_audit_log.detail`
are free-form.** They are not secrets *by name*, so a name-based guard will
never catch what they carry — and `AdminProjection` currently passes all three
through. A router error string or an intent payload could contain anything a
future code path puts there. **This is the one real leak surface in the design**,
and §5 tests it rather than trusting it.

---

# 3. SECURITY BOUNDARY OPTIONS

| | Option | Verdict |
|---|---|---|
| **O1** | Superuser / `BYPASSRLS` on a login role | **Rejected** — forbidden, and it would undo the isolation work wholesale |
| **O2** | Disable `FORCE ROW LEVEL SECURITY` | **Rejected** — the owner is bound deliberately; this is load-bearing (docs/72 §A.4, docs/73 M10 both turned on it) |
| **O3** | A permissive policy on the **login** role `dnb_admin` | **Rejected** — that role would then read any column of any row with arbitrary SQL. This is O1 wearing a policy |
| **O4** | HTTP-supplied `app.customer_id`, iterating customers | **Rejected on two grounds.** It makes a request-supplied value the authority, which is forbidden; and it is **circular** — enumerating customers requires an estate read of `mt_customers` first. It is also O(n) round trips per screen |
| **O5** | Views owned by a privileged role | **Workable but weaker** — a view takes no arguments, so filtering and paging happen after the fact, and `security_invoker` defaulting differs by version. No advantage over O6 |
| **O6** | **`SECURITY DEFINER` read projections** owned by a dedicated NOLOGIN role, `EXECUTE` granted to `dnb_admin`, which holds **no table privileges** | **Recommended — §4** |

### 3.1 The `USING (true)` question, answered directly

The brief forbids `USING(true)` shortcuts. That prohibition must be read
precisely, because **row-level narrowing is impossible for an estate read by
definition** — the requirement *is* all rows.

So the narrowing cannot be row-level. It is:

1. **Column-level** — the function's fixed `RETURNS TABLE (…)` list. A column
   absent there cannot be selected by any caller, ever.
2. **Reachability-level** — `dnb_admin` gets `EXECUTE` and **nothing else**. It
   cannot `SELECT` the base table, so it cannot write its own query. The policy
   is attached to a **NOLOGIN** role nobody can connect as.

`USING (true)` on a login role that can run arbitrary SQL is a bypass.
`USING (true)` on a NOLOGIN owner role reachable only through a fixed-column
function is **the mechanism itself**, not a shortcut around it. **This
distinction is the whole design, and it is yours to accept or reject.**

### 3.2 This is not a new mechanism — it already exists here

```sql
CREATE FUNCTION mt_devices_samplable()
RETURNS TABLE (id uuid, tunnel_ip text, customer_id uuid, wan_interface text)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT d.id, d.tunnel_ip, d.customer_id, d.wan_interface
    FROM mt_devices d WHERE …
$$;
```

Owned by `dnb_def_work` (NOLOGIN), `EXECUTE` granted to `dnb_worker` only, which
holds no table privilege on `mt_devices`. Migration 017 labels the matching
policy *"cross-customer background work: one worker, whole fleet."*

**The worker already reads the whole estate across customers by exactly this
route, and it was reviewed and accepted.** The admin boundary is a **new
instance of an existing, reviewed mechanism** — not a new kind of privilege.
That is the strongest evidence in this report.

---

# 4. RECOMMENDED MECHANISM

```
  Admin API  →  staff identity + capability  →  mt_admin_read_*()  →  approved rows/columns
                (AdminIdentityPort)              SECURITY DEFINER
                                                 owned by dnb_def_admin (NOLOGIN)
```

**Properties, each of which is testable:**

1. One function per read (R1–R11), each `SECURITY DEFINER SET search_path =
   public, pg_temp` — matching all 24 existing definer functions.
2. Owned by a **new NOLOGIN** role `dnb_def_admin`, created
   `NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS`, member of
   nothing — the house pattern from migration 017.
3. `dnb_admin` receives **`EXECUTE` only**. No `SELECT`, `INSERT`, `UPDATE` or
   `DELETE` on any table in the boundary.
4. Each function's `RETURNS TABLE` is **exactly** the matching
   `AdminProjection` allowlist, so the column contract exists in two places that
   a test compares — and a column added to a table appears in neither.
5. `mt_device_secrets`, `mt_device_config`, `mt_auth_codes`, `mt_auth_sessions`
   are **not reachable**: no function names them.
6. **No function takes a tenant context**, and none reads
   `mt_current_customer()`. Estate scope is a property of the function, not of
   the caller's session — so a request cannot influence it.
7. RLS stays **ENABLED and FORCED on every table**. Nothing is disabled anywhere.
8. `REVOKE ALL … FROM PUBLIC` then one `GRANT EXECUTE`, under
   `SET LOCAL ROLE dnb_def_admin`, exactly as migration 017 does — and
   `mt_revoke_public_execute()` must be re-run, because a `proacl IS NULL`
   function is invisible to an ACL sweep (docs/73 §1.1).

**Writes are out of scope.** This boundary is **read-only**. Admin mutations
already exist as provisioning definer functions and stay behind the identity
provider decision — and docs/72 §A.4 found `mt_device_assign` writes no audit
row, which must be fixed before any admin write route is bound.

---

# 5. TEST PLAN

| | Assertion |
|---|---|
| T1 | `dnb_admin` holds **no** table privilege on any boundary table — `has_table_privilege` false for SELECT/INSERT/UPDATE/DELETE |
| T2 | `dnb_admin` **cannot** `SELECT * FROM mt_customers` directly — permission denied |
| T3 | `dnb_def_admin` is NOLOGIN, `NOBYPASSRLS`, not superuser, and a member of no role |
| T4 | Every boundary table still reports `relrowsecurity` **and** `relforcerowsecurity` after the migration |
| T5 | Each function's returned column set **equals** the matching `AdminProjection` allowlist — compared programmatically, not by eye |
| T6 | No function body references `mt_device_secrets`, `mt_device_config`, `mt_auth_codes`, `mt_auth_sessions`, `credential_hash`, `secret_sealed`, `wg_pubkey` or `mt_vouchers.code` |
| T7 | Every function pins `search_path` |
| T8 | `PUBLIC` holds `EXECUTE` on none of them |
| T9 | **Customer isolation is unchanged** — the whole existing customer suite re-run: A still cannot see B, `/me/*` still 404s on foreign ids |
| T10 | Setting `app.customer_id` before calling an admin function **changes nothing** — the estate read is not influenced by a caller-supplied context |
| T11 | Two customers seeded; each admin read returns rows from **both**, proving the boundary works rather than silently returning nothing (the docs/72 §A.4 trap) |
| T12 | **F-B leak test** — an intent payload, a `last_error` and an audit `detail` are seeded with a marker string; the test asserts the marker's *visibility is deliberate*, and that no secret-shaped value passes through |
| T13 | A capability-less staff role still gets 403 before any function is called |
| T14 | With `DenyAllIdentity` bound, every route is 401 and **no function is executed at all** |

---

# 6. OPEN DECISIONS

| | Decision | Needed before |
|---|---|---|
| **D-1** | **Accept or reject §3.1** — a permissive policy on a NOLOGIN owner role, reachable only through fixed-column functions | any implementation |
| **D-2** | **F-B** — what to do about `mt_intents.payload`, `last_error` and `mt_audit_log.detail`. Pass through, redact by rule, or withhold from list endpoints and expose only on a single-record read | the intents and audit reads |
| **D-3** | Whether R11 (audit) is estate-wide for Admin only, per docs/42 §4 (*"Audit Log — Yes, only role"*) | the audit read |
| **D-4** | Whether telemetry and entitlements join the admin surface now or later | scope |

**Unchanged and recorded:** staff identity provider **OPEN** · reseller
commercial model **deferred** · Q2 **OPEN** · `dnb_site_nas` **NOT CHOSEN** ·
F6-B **NOT AUTHORIZED** · production census **NOT OBTAINED** · production
changes **NONE**.
