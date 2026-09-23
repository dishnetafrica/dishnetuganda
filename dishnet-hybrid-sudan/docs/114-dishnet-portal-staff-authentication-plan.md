# 114 — DishNet Portal: real staff authentication → real Admin operation

**Status: PLAN. Nothing implemented.** Requested 2026-09-23 as a direction
correction. Every *current state* line is measured from the repository at
`fbefede`; every *proposed* line is a design for approval, not a fact. Nothing
in production, in `migrations/`, in the identity seam or in the simulator has
been changed by this document.

---

## 0. Direction, recorded

The product is the **DishNet Portal**: **DishNet Admin** and the **Customer
PWA**, both over the **Domain-B API**, both DishNet-owned.

```
                    DISHNET PORTAL
                          │
              ┌───────────┴───────────┐
        DISHNET ADMIN            CUSTOMER PWA
              └───────────┬───────────┘
                     DOMAIN-B API
          ┌───────────────┼───────────────┐
      PostgreSQL       FreeRADIUS       WireGuard ── MikroTik fleet ── HotSpot
      RLS / audit
```

- **uCRM / UISP / Splynx are not the operating portal.** No Splynx login
  dependency, no staff-auth bridge, no Admin UI inside any of them. They may
  later be *integrations* for selected commercial data at the one audited link
  point `docs/110` already defines. **`docs/111`'s staff-identity path (U-7,
  S-1/S-2) is superseded by this document.** Its data-adapter half is parked,
  not chosen.
- **W-4 is now authorised to be designed.** *"Invent no staff credential
  store"* was the rule while the provider was undecided — `docs/81` §8.1 left
  the choice open on purpose. The provider is decided: **DishNet-owned.**
- Customer plane and guest path are unchanged: Decisions 1, 3, 7, 2a and 2b
  stand; the commercial voucher code never enters the AAA credential path.
- Architecture-first. Production untouched; O-1 stays behind the census; the
  simulator is never presented as hardware state.

---

## A. Current state — measured

| Component | State at `fbefede` |
|---|---|
| Identity seam | `AdminIdentityPort { identify(Request): ?StaffIdentity; providerName() }` · `StaffIdentity(subject, role, provider)` · `StaffRole` **admin / noc / sales / support**, each a capability set · `Capability` 21 constants · `guard()` requires a capability on every route: **401** without identity, **403** without the capability |
| Providers | `DenyAllIdentity` — the production binding, always `null` · `DevStaffIdentity` / `DevSessionIdentity` — gated on `DN_DEV_STAFF_IDENTITY=yes-development-only`, **throw** under `DN_ALLOW_REAL_BINDINGS`, and never fall back in either direction (asserted) |
| Sessions | `AdminSession`: stateless HMAC token, TTL 3600 s, key **derived** from `DNB_SECRET_KEY` under a label; cookie HttpOnly · SameSite=Strict · Secure over TLS. **No revocation** — logout clears the cookie only (asserted, deliberately) |
| Session routes | `GET / POST / DELETE /api/v1/admin/session`, none capability-gated. `POST` → **501 `production_authentication_unavailable`** when no issuer is bound |
| Admin reads | 15 GET routes over 13 `mt_admin_*()` projections as **`dnb_adminapi`** — EXECUTE only, **zero table privileges**; `code`, `wg_pubkey` and every secret column withheld; manifest asserted equal to the served surface |
| Admin writes | **7 declared-unbound POSTs, each answering 501** — routers, assign, actions, sites, plans, voucher-batches, disconnect. Migration 020 (W-1/W-2/W-3) is built: seven provisioning functions self-audit through `mt_audit_write()`; `dnb_adminwrite` holds EXECUTE on them and nothing else; **`Database::adminWrite()` is used by no route** |
| Audit | `mt_audit_log(customer_id NULL = system event, actor text, actor_kind CHECK IN (principal, staff, system), …)`, append-only by trigger; **no login role can INSERT** (022–025). `staff` already exists, system-scope rows need no schema change, and `mt_admin_audit()` carries no customer filter — so staff-lifecycle rows are Admin-visible as built |
| Precedent | Customer auth, migration 007: OTP by phone; code and token **hashed in PHP** with HMAC(`DNB_TOKEN_PEPPER`); definer functions; session row with `expires_at` / `revoked_at`; resolve **re-reads `status = 'active'` live**. EXECUTE to `dnb_app`. Not reachable from the Admin surface (asserted) |
| Extensions | **None** is created by any migration — `gen_random_uuid()` is core. **pgcrypto is not installed** |
| Bindings | `Bindings::defaults()` = `NullDelivery` + `NullPublisher`; the F6-B gate is `DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized`; nothing reaches a router; the simulator refuses to run under the gate |
| Deployment | `plugin/doc/INSTALL.md`: built-in PHP server on loopback, reached over SSH; *"ships no TLS and no process supervision"*; any web server may serve `panel/` and route `/api/v1/admin/*` to `plugin/public/api.php` |
| Panel | the seven gate states are built; role buttons appear only under the development identity; **no sign-out control**; bundle guards forbid SQL and credential *shapes* |
| Customer PWA surface | measured: `/auth/request-code`, `/auth/verify`, `/auth/logout`, `/me`, `/me/entitlements`, `/me/intents`, `/me/plans`, `/me/plans/{id}/retire`, `/me/services`, `/me/sessions`, `/me/sessions/{id}/disconnect`, `/me/sites`, `/me/sites/{id}`, `/me/uplink`, `/me/usage`, `/me/vouchers`, `/me/vouchers/{id}/revoke`. **`/me/access-points` does not exist.** `public/pwa/` is an API client and a store (250 lines) — no screens |
| Suite | 30 suites · 1,846 assertions · 0 failed |

---

## B. Exact missing pieces

| # | Missing | Why it blocks |
|---|---|---|
| 1 | **A staff principal store** — no table, no function, no role membership (W-4) | nobody exists to authenticate |
| 2 | **A credential** — no password, no second factor, no lockout | nothing to verify |
| 3 | **A server-side staff session with revocation** — the dev session is stateless and unrevocable by design | disable / logout must take effect immediately |
| 4 | **A production `AdminIdentityPort`** — `api.php` binds DenyAll or Dev only | the seam has no production implementation |
| 5 | **Staff lifecycle operations** — create · disable · role change · credential reset, audited | the first admin cannot be created, nor the second |
| 6 | **Anti-brute-force inside the login function** — counters transactional with the attempt (the `docs/89` shape) | a PHP-side counter can be bypassed by a second caller |
| 7 | **CSRF / origin policy** for a cookie-authenticated *mutating* API — SameSite=Strict exists; no Origin check, no bound mutation yet | the first bound POST needs it |
| 8 | **Binding of the 7 POSTs** to W-1 functions with the identity's subject as `p_actor` | today each answers 501 |
| 9 | **An Admin-plane voucher / plan issuer** — the 024 functions derive the tenant from `mt_current_customer()`, which is **NULL on the Admin plane** | "Create voucher" from Admin has no function to call |
| 10 | `mt_site_create` — blocked on **O-1** (census → migration) | `POST /sites` cannot be bound before it |
| 11 | `session.disconnect` replay safety (`docs/108`) | binding it for staff would replay the same duplicate |
| 12 | **Real hardware bindings** — F6-B gated; WireGuard, RouterOS REST, publisher inert | the network half of the slice |
| 13 | **Guest redemption path** — designed (`docs/87–89`), unbuilt; Decision 5 gated on hardware evidence | the guest half of the slice |
| 14 | **Deployment** — TLS termination, trusted-proxy rules, process supervision, a public hostname | an Admin URL on the Internet |
| 15 | **Panel** — a credential form, a sign-out control, a staff-management screen, self-service password / second-factor | the login is role buttons today |
| 16 | **Tests that pin the absence** of a credential store and of credential shapes in the bundle | each must be flipped **deliberately, with its control**, never quietly |

---

## C. Authentication architecture — minimum secure, DishNet-owned

**Principles.** Identity is established server-side only. The browser holds an
opaque cookie and nothing else. The HTTP roles keep **zero table privileges**.
Secret material **never leaves PostgreSQL**. Every state change is **one
definer transaction** with its counters and its audit row inside it. The
provider is selected explicitly and **never falls back**.

```
browser ──(username, password, TOTP)──▶ POST /session
                                          │  Database::adminWrite()  role dnb_adminwrite
                                          ▼
                              mt_staff_login()   SECURITY DEFINER, owner dnb_def_staff
                              ├─ lockout check (decaying, in-row)
                              ├─ crypt(password) = password_hash      pgcrypto, in-database
                              ├─ TOTP check                            pgcrypto hmac, in-database
                              ├─ counters reset / incremented          same transaction
                              ├─ INSERT mt_staff_sessions(token_hash)  hash only, never the token
                              └─ mt_audit_write(NULL, username, 'staff', 'staff.login')
                                          │
                       Set-Cookie: dnb_staff_session=<256-bit random>; HttpOnly; Secure; SameSite=Strict
                                          │
every request ──cookie──▶ DishnetStaffIdentity::identify()
                                          │  Database::adminApi()  role dnb_adminapi  (read only)
                                          ▼
                              mt_staff_session_resolve(token_hash)
                              → (username, role) only if not expired, not revoked, staff.status = 'active'
                                          │
                              StaffIdentity(subject = username, role, provider = 'dishnet')
                                          │
                              guard(capability) ──▶ handler ──▶ W-1 function(…, p_actor = username)
```

### C.1 Components (proposed)

| Piece | Design |
|---|---|
| `mt_staff` | **non-tenant** table: `id uuid`, `username text UNIQUE` (case-folded, **immutable**), `display_name`, `role text CHECK (admin\|noc\|sales\|support)` — one role per person, `status CHECK (active\|disabled)`, `password_hash` (bcrypt via pgcrypto), `password_set_at`, `totp_secret bytea`, `totp_confirmed_at`, `totp_last_step bigint` (replay guard), `failed_attempts int`, `locked_until timestamptz`, `last_failed_at`, `created_at/by`, `disabled_at/by` |
| `mt_staff_sessions` | `id`, `staff_id FK`, `token_hash text UNIQUE`, `created_at`, `expires_at` (**absolute**), `revoked_at`, `revoked_reason`, `source` (ip) |
| `dnb_def_staff` | **new NOLOGIN definer role**, owner of both tables and of every `mt_staff_*` function, created under the 017 pattern. **No new LOGIN role** (D-AUTH-1) |
| Token | 32 random bytes in PHP → cookie. Stored: `HMAC-SHA256(token, K)` with `K = HMAC(DNB_TOKEN_PEPPER, 'dnb:staff-session:v1')` — **label-derived, no new secret** (the `AdminSession` lesson) |
| Password | bcrypt cost 12 via `crypt(p, gen_salt('bf', 12))`, **verified in the database** (D-AUTH-2). Minimum 12 characters, no composition rules. An unknown username is verified against a fixed dummy hash so timing does not disclose existence |
| Second factor | TOTP (RFC 6238), 30 s step, ±1 step, replay guard by last step; **verified in the database** with pgcrypto `hmac()`. Secret shown **once**, at enrolment, to the authenticated user only. **Mandatory before gate G-D** (D-AUTH-5). Recovery = Admin reset, audited. No e-mail, no SMS dependency |
| Lockout | per-account, **decaying window, never a hard lock** (`docs/89`'s non-tradeable property applied to staff). Parameters are **proposed** (5 failures → 15 min, doubling, ceiling 24 h) and must be confirmed, not assumed (D-AUTH-4). Counters live **inside** `mt_staff_login()` |
| Uniform failure | unknown user · wrong password · wrong TOTP · locked · disabled → **identical 401 body**, comparable timing. Response uniformity is not tradeable here either |
| Session lifetime | **absolute**, proposed 8 h (D-AUTH-4); resolve is a pure read, so there is no sliding expiry to write. Revoked by logout, disable, role change, password reset |
| Provider | `DishnetStaffIdentity implements AdminIdentityPort` — reads the cookie, hashes, resolves, returns `StaffIdentity(username, role, 'dishnet')`. Bound only by explicit `DN_STAFF_IDENTITY=dishnet`; unset → `DenyAllIdentity` exactly as today; set together with the dev gate → **refuse to start**. Unlike the dev provider it **must** work under `DN_ALLOW_REAL_BINDINGS` — it is the production provider |
| Actor | `p_actor = username`, `actor_kind = 'staff'`, from the identity boundary, per W-1 — never a GUC, never a request field |
| CSRF | SameSite=Strict **plus** an `Origin` check against `DN_PORTAL_ORIGIN` and `Content-Type: application/json` on every mutating route. A cross-origin form POST is refused and a test proves it |

### C.2 Functions (all `SECURITY DEFINER SET search_path = public, pg_temp`, owned by `dnb_def_staff`)

| Function | Executes as | Writes | Audit action |
|---|---|---|---|
| `mt_staff_login(p_username, p_password, p_totp, p_token_hash, p_ttl, p_source)` | `dnb_adminwrite` | counters, session row | `staff.login`; `staff.locked` when a lock engages. Failed attempts are counters, not audit rows — telemetry, not business audit (`docs/89`) |
| `mt_staff_session_resolve(p_token_hash)` | `dnb_adminapi` | **none** | — |
| `mt_staff_logout(p_token_hash)` | `dnb_adminwrite` | `revoked_at` | `staff.logout` |
| `mt_staff_create(p_username, p_display, p_role, p_password, p_actor)` | `dnb_adminwrite` | staff row | `staff.created` |
| `mt_staff_disable(p_staff, p_actor)` | `dnb_adminwrite` | status + **revokes every live session in the same transaction** | `staff.disabled` |
| `mt_staff_set_role(p_staff, p_role, p_actor)` | `dnb_adminwrite` | role + revokes sessions | `staff.role_changed` |
| `mt_staff_reset_password(p_staff, p_password, p_actor)` | `dnb_adminwrite` | hash + revokes sessions | `staff.password_reset` |
| `mt_staff_change_password(p_self, p_current, p_new)` | `dnb_adminwrite` | hash + revokes *other* sessions | `staff.password_changed` |
| `mt_staff_totp_enrol(p_self)` / `mt_staff_totp_confirm(p_self, p_code)` | `dnb_adminwrite` | secret / confirmed_at | `staff.totp_enrolled` |

Every audit row: `customer_id NULL`, `actor = username` (or `cli:<os-user>` for
the bootstrap), `actor_kind = 'staff'`. `mt_audit_write` EXECUTE is granted to
`dnb_def_staff` the way 024 granted it to `dnb_def_comm`.

**First administrator.** `php plugin/bin/plugin.php staff:bootstrap <username>`
run by the operator **on the server**, through `dnb_adminwrite`, actor
`cli:<os-user>`; it **refuses if any staff row exists**; the initial password is
generated and printed **once** — never logged, never an environment variable.
Every later account is created in the portal by an Admin, audited.

### C.3 What is deliberately NOT in the design

No password recovery by e-mail or SMS · no "remember me" · no API keys · no
OAuth / OIDC / SAML · no per-IP counters as the primary control (a NAT'd office
shares one address, `docs/65` §9.3) · no login-time role selection (the dev
provider's role buttons disappear with it) · no second tenant hierarchy: a role
narrows **what**, never **which customers** (`docs/81` §8.3).

### C.4 The tenant rule on the Admin plane, stated so it cannot be misread

`mt_current_customer()` is **never set** on the Admin plane and no request
field ever supplies tenant context. An Admin operation may **name a target**
customer (assign this device to that customer, issue vouchers for that
customer): that is a validated parameter of a definer function that runs with
its own policy and checks the target exists, exactly as `mt_device_assign`
does today. RLS is not bypassed, weakened or consulted through the browser.

---

## D. Database / schema changes — migration 026 `staff_identity.sql` (proposed)

| Change | Note |
|---|---|
| `CREATE EXTENSION IF NOT EXISTS pgcrypto` | the schema's **first** extension. Trusted since PG 13, so the non-superuser owner can create it **only because it owns the database** (`bootstrap.sql`). Must be **proved by execution** in `test_installability` — an extension the owner cannot create is an install-time failure. If refused: Argon2id in PHP is the fallback, with the hash then leaving the database (D-AUTH-2) |
| role `dnb_def_staff` NOLOGIN | 017 pattern; membership to the owner `WITH INHERIT FALSE, SET TRUE` |
| tables `mt_staff`, `mt_staff_sessions` | **created under `SET LOCAL ROLE dnb_def_staff`**, not as the owner — migration 015's `ALTER DEFAULT PRIVILEGES` for `dnb_admin` is recorded **per granting role**, and 022 revoked only its INSERT. A credential table created by the owner would hand `dnb_admin` SELECT on password hashes by default. A test asserts **every login role, `dnb_admin` included, holds zero privileges** on both tables, with `dnb_def_staff` as the positive control |
| no RLS on the two tables | they are not tenant tables; access exists only through the functions. Documented in the migration so a later reader does not "fix" it |
| functions in C.2 + grants | `REVOKE ALL FROM PUBLIC`; EXECUTE exactly as the table in C.2; `mt_audit_write` EXECUTE to `dnb_def_staff` |
| `mt_audit_log` | **no change** |
| `Capability::STAFF_MANAGE = 'staff.manage'` | Admin only. The CHECK list on `mt_staff.role` is asserted equal to `StaffRole::cases()` |
| Not changed | RLS, tenant tables, `mt_auth_*`, the seven provisioning functions, `mt_admin_*` projections, O-1, the intent path |

Idempotency: the login/session functions have natural keys (`token_hash`
UNIQUE); staff creation has `username` UNIQUE — a replay is refused, not
duplicated (`docs/108`'s "existing UNIQUE" class).

---

## E. API changes (proposed)

| Route | Change |
|---|---|
| `POST /api/v1/admin/session` | body `{username, password, totp}` → **200** identity + cookie · **401** uniform `{error: 'invalid_credentials'}` · **400** malformed · **501** unchanged when the provider is deny-all. **Never a role in the body** |
| `GET /api/v1/admin/session` | **200** `{identity: {subject, role, capabilities, provider: 'dishnet', expires_at}}` · **401** `{can_authenticate: true, provider: 'dishnet', roles: []}` — `roles` is empty because the client no longer chooses one |
| `DELETE /api/v1/admin/session` | **204**, and now **revokes** |
| `POST /session/password`, `POST /session/totp`, `POST /session/totp/confirm` | self-service; authenticated; no capability beyond being signed in |
| `GET /staff`, `POST /staff`, `POST /staff/{id}/disable`, `POST /staff/{id}/role`, `POST /staff/{id}/password` | capability **`staff.manage`**; the read is a projection that withholds every hash and secret |
| `POST /routers`, `POST /routers/{id}/assign`, `POST /routers/{id}/actions` | **bound** to `mt_device_register`, `mt_device_assign`, and a new `mt_device_provision_request()` (`dnb_def_prov`, enqueues the intent the simulator already enqueues on the admin connection). `p_actor = identity.subject`, `source = request ip` |
| `POST /voucher-batches`, `POST /plans` | **bound** to new Admin-plane issuers taking an explicit target customer (D-AUTH-3). Idempotent through the existing `mt_idempotency(customer_id, key)` — the target customer **is** known here, so `docs/105`'s "nothing to key on" does not apply; the check runs **before** the mutation (RULE I-1) |
| `POST /sites` | stays 501 until **O-1** |
| `POST /sessions/{id}/disconnect` | stays 501 until the replay fix (`docs/108`) |
| all mutating routes | `Database::adminWrite()`; Origin + content-type check; 403 when the capability is absent, as today |
| `/health` | `identity.provider` reports `dishnet`; the SIMULATED / DOCUMENTED evidence banner is unchanged |
| `plugin/plugin.json` | `surface` becomes `read-write`; `writes.bound` lists the bound routes with capabilities; `declared_unbound` shrinks to sites and disconnect; the equality test is updated **in the same commit** |

---

## F. Frontend / login changes (proposed)

- **Gate:** when `provider === 'dishnet'` and `can_authenticate === true` the
  card is a **credential form** — username, password, one-time code — instead
  of role buttons. The seven states stay; `invalid` becomes reachable through
  the real UI. Nothing is stored client-side; the token lives only in the
  HttpOnly cookie.
- **Sign out** in the shell (calls `DELETE`, which now revokes). Any screen
  answering 401 routes to `onUnauthorized()` so an expired session shows
  **Session expired**, not "Not signed in".
- **Administration → Staff** (Admin only): list, create (initial password shown
  once), disable, change role, reset password. **Account**: change password,
  enrol second factor — the secret and its `otpauth://` URI shown as text; a QR
  code is a later nicety, not a dependency.
- **The bundle guards are amended deliberately, not weakened:** an
  `<input type="password">` and the word "Password" as a label are UI, not a
  credential; the guards keep forbidding credential *values* and SQL, and the
  amendment ships with its control (a planted `password: "x"` still fails).
- The Customer PWA is untouched by this plan.

---

## G. Audit and security requirements (binding on the build)

1. Every staff-lifecycle and session act is audited **inside** its definer
   function via `mt_audit_write` — exactly one row per act, `actor_kind =
   'staff'`, actor = username. A rejected act writes **no** row.
2. Failed logins are counters, lockouts are audit rows.
3. Uniform failure responses; dummy hash for unknown users; no account-state
   disclosure.
4. Decaying lockout, never a hard lock; parameters confirmed, not invented.
5. No login role can read or write `mt_staff*` — proved with a positive
   control; no function callable by an HTTP role **returns** a hash or a TOTP
   secret except enrolment, to the authenticated owner, once.
6. Cookie: 256-bit random, HttpOnly, SameSite=Strict, **Secure mandatory**
   outside the dev gate — the provider **refuses to issue** a session over
   plain HTTP unless a configured trusted proxy asserts `X-Forwarded-Proto:
   https`. Stored hashed under a label-derived key.
7. CSRF: SameSite + Origin + JSON content-type on every mutating route.
8. Provider selection explicit; no fallback; dev + dishnet together refuse.
9. Disable / role change / password reset revoke sessions **transactionally**;
   resolve re-reads `status` and `role` live, so enforcement is immediate
   even for a session row a later bug forgot to revoke.
10. Actor is a parameter from the identity boundary (W-1). Never a GUC.
11. `mt_current_customer()` is never set on the Admin plane; a target customer
    is a validated function parameter (C.4).
12. No login role gains an audit INSERT; `mt_audit_write` stays
    definer-only. The A-1 closure is preserved and asserted.
13. Logs never carry a password, a code or a token — exception class only, as
    `api.php` already does.
14. The dev provider keeps refusing under real bindings; the simulator keeps
    refusing under real bindings; `publisher_simulated` and the SIM- prefix
    keep the estate honest in every screen.
15. Every negative test carries a positive control and a control on the
    control (`docs/105` §0) — the login test must be shown to fail when the
    lockout is removed, the privilege test when a grant is added.

---

## H. Deployment requirements (for gate G-D; not authorised to execute)

| Requirement | Detail |
|---|---|
| Separate host / cluster | Domain B on its own PostgreSQL instance, **never** the cluster serving UISP/uCRM (`docs/96`: the roles are cluster-wide). Database on loopback only |
| TLS termination | a reverse proxy (nginx or Caddy) for `admin.dishnetuganda.com` — a **deployment value, not a code constant** — serving `panel/` as static files and proxying `/api/v1/admin/*` to PHP-FPM → `plugin/public/api.php`. The built-in server stays development-only |
| Trusted proxy | `DN_TRUSTED_PROXY=<proxy address>` so `X-Forwarded-Proto` / `X-Forwarded-For` are honoured **only** from it — Secure cookies and `source` depend on this |
| Configuration | `DN_STAFF_IDENTITY=dishnet` · `DN_PORTAL_ORIGIN=https://admin.dishnetuganda.com` · existing `DNB_TOKEN_PEPPER`, `DNB_ADMINAPI_*`, `DNB_ADMINWRITE_*` · **no** `DN_DEV_STAFF_IDENTITY` · **no** `DN_ALLOW_REAL_BINDINGS` until F6-B. Secrets provisioned by the installer per B-1; nothing in source control |
| First admin | `staff:bootstrap` on the server; second factor enrolled at first sign-in; every staff account enrolled before the hostname is public |
| Supervision & retention | systemd for PHP-FPM (and later the worker); log retention; **backups of the Domain-B database** — the audit log is append-only and losing it loses attribution |
| Network | 443 public for the portal; UDP 51820 for WireGuard; RADIUS on `wg0` only (`docs/33`); nothing else inbound |
| Runbook | `INSTALL.md` §7 and *The identity gate* rewritten for the real provider; the 67-check disposable install test extended to a **login over real TLS** |

---

## I. Completable and provable before physical MikroTik

| Item | Proof available without hardware |
|---|---|
| Migration 026, functions, `DishnetStaffIdentity`, session routes, staff routes | the suite, plus real HTTP through `serve.php` behind a local TLS-terminating proxy in the disposable install test |
| Capability matrix on real logins | admin / noc / sales / support each exercised against every bound route |
| Router register → assign → action (intent queued) | `IntentWorker` with `NullDelivery` reports `retryable('no delivery path is configured')` — proves queue, audit and actor; **proves nothing about a router**, and the screen says so |
| Admin voucher batch and plan for a target customer (D-AUTH-3) | rows visible in Admin **and** in that customer's `/me/vouchers`, and **not** in another customer's — tenant boundary proved from both sides with controls |
| Vouchers all `unused` | `LifecycleReport` keeps `activating / active / expired` unreachable; the estate stays truthful |
| Redemption path, attempt store, `dnb_portal`, AAA Publisher | **separately gated** (`docs/87` §H.1 P-2…P-7) but buildable and provable against the **disposable** FreeRADIUS; Decision 5's rate-limit dimension stays **UNMEASURED / OPEN** |
| Deployment rehearsal (G-D) | disposable VM, tarball, TLS, bootstrap, login, sign-out, revocation |

## J. Must wait for physical MikroTik

F6-B (`DN_ALLOW_REAL_BINDINGS`) · a WireGuard peer for a real router · B1
idle-reach · RouterOS REST over the tunnel · H1–H7 · provisioning delivery
confirmed · HotSpot on the router · RADIUS authentication from a real NAS ·
accounting rows from real sessions · the C-b authorize-query change (its own
authorisation, `docs/70` §8.3) · **Decision 5** · a writer for
`mt_devices.last_seen_at` · the words *"MikroTik activation proven"*.

---

## K. Acceptance gates

| Gate | Passes when |
|---|---|
| **G-A Design accepted** | this document accepted by commit hash; D-AUTH-1…7 answered |
| **G-B Staff identity built (development)** | migration 026 applied by `tests/run.sh`; **(1)** no login role can read or write `mt_staff*`, positive control `dnb_def_staff`; **(2)** unknown user / wrong password / wrong TOTP / locked / disabled → byte-identical 401; **(3)** lockout engages and decays, and the test fails when the lockout is removed; **(4)** disable revokes a **live** session — measured by a request that succeeded the instant before; **(5)** no HTTP-role-callable function returns a hash; **(6)** provider selection: unset → 401/501, `dishnet` → real, both gates → refuse to start, `dishnet` under real bindings → works; **(7)** cross-origin POST refused; **(8)** exactly one audit row per act, none for a refused act, actor = username; **(9)** bundle guard amended with its control; **(10)** insecure-cookie refusal outside the dev gate; **(11)** suite 0 failed, run twice; **(12)** real HTTP proof, not only in-process |
| **G-C First real operation** | router register / assign / action bound; matrix: noc **can**, sales and support **cannot**; audit rows carry the staff username; intent queued and `NullDelivery` reports retryable; manifest updated, equality test green; `declared_unbound` = sites + disconnect |
| **G-C2 Commercial slice** | D-AUTH-3 decided; Admin voucher batch and plan issued idempotently under `(customer, key)`; both-sides tenant proof; still all `unused` |
| **G-D Deployment readiness** | TLS proxy + trusted-proxy config; secrets by installer; `staff:bootstrap`; TOTP mandatory and enrolled; backups; `INSTALL.md` updated; disposable-VM rehearsal from the tarball including login over TLS, sign-out, revocation. **Not production** |
| **G-E Production install of Domain B** | operator authorisation; separate instance; **no real bindings**; O-1 untouched (site creation still waits for the census); production census still the handoff for O-1 |
| **G-F Hardware** | physical MikroTik → F6-B → H1–H7 → Decision 5 → redemption path live → guest authenticates → accounting appears in Admin. **Each its own authorisation.** |

---

## L. Decisions to answer before G-B (D-AUTH)

| # | Question | Recommendation |
|---|---|---|
| 1 | Which role executes login / logout? | **`dnb_adminwrite`** (writes → the write connection, as `dnb_app` executes `mt_auth_*` on the customer plane); resolve as `dnb_adminapi`. Alternative: a dedicated `dnb_staffauth` login role — one more credential to provision, tighter pre-auth blast radius |
| 2 | Where is the password verified? | **In PostgreSQL** (pgcrypto bcrypt): hashes never leave the database, counters are transactional with the attempt. Alternative: Argon2id in PHP, hash fetched per attempt |
| 3 | Admin-plane voucher / plan issuance for a target customer | **Build it** as new definer functions. Which definer role owns them is decided **by measurement** at build time: `dnb_def_comm` has no widening policy (that is what makes it safe for customers) and `dnb_def_net` widens `mt_vouchers` / `mt_hotspot_users` only — so either a new `dnb_def_commadmin` with its own explicit policies, or `dnb_def_prov`; not guessed here |
| 4 | Session lifetime and lockout parameters | proposed 8 h absolute; 5 → 15 min doubling, ceiling 24 h. **Operator confirms**; nothing here is measured |
| 5 | Second factor mandatory? | **Yes, before G-D** — the console controls a network estate from the public Internet |
| 6 | Audit subject | **username**, immutable, human-readable in `mt_audit_log.actor` |
| 7 | New capability `staff.manage` | **Admin only**; add to `Capability::ALL` and to the matrix test |

---

---

## M. D-AUTH-1…7 — measured and provisionally frozen 2026-09-23, then SUSPENDED the same day

> **Suspended before any code was written.** The tenancy re-evaluation in
> `docs/115` interrupts this build. Its finding — `mt_customers` *is* the
> Operator — leaves every decision below intact and changes one word:
> D-AUTH-3's *target customer* becomes *target operator*. Resumption is
> `docs/115` T-8, after T-1 is accepted.

Measured on `dnb_sim` at migration level 25 before a line of code was written.
Every row names what was run; nothing below is inferred.

### M.1 The measurements

| # | Measurement | Result |
|---|---|---|
| **M1** | functions `dnb_adminwrite` may EXECUTE (`has_function_privilege`, PUBLIC excluded) | **7**: `mt_customer_create`, `mt_device_register`, `mt_device_assign`, `mt_device_set_state`, `mt_device_set_secret`, `mt_device_set_desired`, `mt_device_set_wan` — every one a **write** |
| **M2** | table privileges per LOGIN role (`has_table_privilege` over every `public` table, SELECT/INSERT/UPDATE/DELETE) | `dnb_adminapi` **0/0/0/0** · `dnb_adminwrite` **0/0/0/0** · `dnb_radius` 0/0/0/0 · `dnb_app` 20/13/14/11 · `dnb_admin` 21/20/21/20 · `dnb_worker` 21/20/21/20 · owner `dnb` 21/21/21/21 |
| **M3** | `pg_default_acl` — default privileges, **per granting role** | exactly one granting role, the owner `dnb`: `dnb_app=r`, `dnb_worker=rwd`, `dnb_admin=rwd` on tables. **Any table the owner creates hands `dnb_admin` and `dnb_worker` SELECT/UPDATE/DELETE and `dnb_app` SELECT automatically.** No default ACL exists for any definer role |
| **M4** | pgcrypto on this cluster | available, version 1.3, **trusted = t** |
| **M5** | can the non-superuser, non-BYPASSRLS owner create it? | throwaway `dnb_m5` owned by `dnb` (`rolsuper=f`, `rolbypassrls=f`): `CREATE EXTENSION pgcrypto` → **CREATE EXTENSION**; `crypt('x', gen_salt('bf', 12))` → `$2a$12$…` **ok**; `hmac(…, 'sha1')` **ok**; database dropped, residue **0** |

### M.2 What the measurements decide

- **M1 rules out `dnb_adminwrite` for authentication.** The condition was
  *"unless measurement proves it can be reduced to exactly the required
  authentication operations without widening unrelated privileges."* It holds
  EXECUTE on seven write functions that the bound Admin routes will need; it
  cannot be reduced to the three authentication functions without removing
  the grants it exists for, and a pre-authentication code path connecting as
  a role that can call `mt_device_set_secret` is exactly the inheritance the
  decision forbids. **A dedicated login role it is.**
- **M2 shows the shape the new role must have:** `dnb_adminapi` and
  `dnb_adminwrite` both hold zero table privileges, and the test suite
  already proves that shape. `dnb_staffauth` copies it: EXECUTE on exactly
  three functions, nothing else, asserted the same way.
- **M3 is the finding that shapes the migration.** A `CREATE TABLE mt_staff`
  run as the owner would grant `dnb_admin` **SELECT on password hashes** and
  `dnb_worker` the same, silently, through a default ACL set in migration
  015 and only partly revoked since. The credential tables are therefore
  created **under `SET LOCAL ROLE dnb_def_staff`**, which has no default
  ACL, and a test asserts every login role — `dnb_admin` and `dnb_worker`
  included — holds zero privileges on them, with `dnb_def_staff` as the
  positive control.
- **M4–M5 make in-database verification available without a superuser.** The
  extension is trusted and the owner created it in a database it owns. That
  is the production shape too (`bootstrap.sql` makes the owner own the
  database), and `test_installability` must prove it on every run rather
  than assume it.

### M.3 The frozen decisions

| # | Decision | Frozen as |
|---|---|---|
| **D-AUTH-1** | authentication DB role | **`dnb_staffauth`** — a **LOGIN** role (a NOLOGIN role cannot serve a connection; the least-privilege *owner* is the NOLOGIN `dnb_def_staff`). EXECUTE on `mt_staff_login`, `mt_staff_session_resolve`, `mt_staff_logout` only; **zero table privileges, now and by default**; provisioned by the installer like the other six. `dnb_adminwrite` is **not** used for authentication (M1) |
| **D-AUTH-2** | password verification | **inside PostgreSQL**, pgcrypto bcrypt cost 12. **No function callable by any HTTP role returns a hash.** The HTTP role receives success/failure and `(staff id, username, role, factor)` only |
| **D-AUTH-3** | Admin-plane commercial writes | **dedicated Admin-plane functions** taking a **validated target customer** explicitly; `mt_current_customer()` is **never set** on the Admin plane; the target is authorised by the staff capability (`vouchers.generate`, `plans.write`); RLS, customer/site integrity and transactional audit preserved. **Built in G-C2, not G-B**; the owner role is chosen by measurement then |
| **D-AUTH-4** | session and lockout | session **8 h absolute**; lockout **5 failures → 15 min**, decaying (doubling per lock, ceiling 24 h, counter reset after a success or once the decay window passes); **no permanent lock**. Every value lives in **one** place, `mt_staff_policy()`, read by the login function and by the tests — no duplicated constants |
| **D-AUTH-5** | second factor | **TOTP mandatory before the public hostname.** `DN_STAFF_REQUIRE_TOTP` defaults to **required** whenever the `dishnet` provider is bound; a password-only session is admitted **only** to enrol, and every capability route answers 403 `second_factor_required` until it has. Development may set it to `no` (the controlled test mechanism). Secrets are generated and verified in the database and shown once, to the enrolling user |
| **D-AUTH-6** | audit identity | `actor` = **immutable username**; `mt_staff.id` (uuid) retained as the internal identity and carried in `detail`; `display_name` is mutable and appears nowhere in audit |
| **D-AUTH-7** | staff management | capability **`staff.manage`, Admin only**; NOC, Sales and Support cannot create, modify, disable or reset staff; every lifecycle mutation is audited **inside** its function |

*Nothing above is implemented. `DenyAllIdentity` remains the production
binding, W-4 is designed and OPEN, migrations end at 025, and the production
census remains the handoff for O-1.*
