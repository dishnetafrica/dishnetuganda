# 126 — The operator-owner login (J-1): the first owner from the Admin panel, and what an operator still needs to sign in

**Status:** review written **before** code, 2026-09-24 (§A–§C). The build record
follows in §D: **built and proved in development; nothing deployed.** Nothing here is HARDWARE
VERIFIED; F6-B stays NOT AUTHORIZED.

**Why now.** After migration 030 reached staging (`docs/125` §F), the operator
was offered the next step: *"letting an operator log in themselves to manage
their own vouchers and plans (the operator-owner login). That needs your
go-ahead before I start."* The operator replied **"go-ahead"**. `docs/116` §J
J-1 reserved the Admin route that creates an operator's people for its own
instruction; this is that instruction.

---

## A. What exists — measured

| Piece | State |
|---|---|
| **The creator** | `mt_admin_principal_create(operator, kind, display_name, phone, capabilities, actor)` — migration 027. Owned by `dnb_def_prov`, EXECUTE **`dnb_adminwrite` only**, target operator **explicit** (the Admin plane never sets tenant context), audit `principal.created` with `actor_kind = 'staff'` and the operator in the detail. It validates kind, display name and actor; an owner's capability list must be empty. **It does not validate the phone's form and does not check the operator's status** |
| **The route** | `POST /api/v1/admin/customers/{customer_id}/principals` (`customers.write`) — **declared, answers 501** (`docs/116` J-1) |
| **The read** | `GET /api/v1/admin/principals` — bound since 027; phone, email and every credential withheld |
| **Sign-in** | `POST /api/v1/auth/request-code` and `/auth/verify` exist: a six-digit code, 10 minutes, 5 requests per phone per 15 minutes, one uniform failure. **The code is delivered nowhere.** `Authenticator::issueCode()` returns it and the route answers only `{status: sent}`; `DNB_EXPOSE_OTP=1` puts it in the response for tests. **No SMS or messaging provider exists in Domain B or anywhere in this repository** (searched). The only messaging gateway on the server is Domain A's WhatsApp gateway, used for Starlink sales |
| **Sign-in and the operator's status** | `mt_auth_issue_code`, `mt_auth_verify_code` and `mt_auth_resolve_token` check the **principal's** status only. **Nothing checks the operator's**: the people of a suspended operator can still sign in. Finding **F-J1-1** |
| **The operator app** | `public/pwa/` holds the data layer only (`api.js`, `store.js`). The screens are in `prototype/dishnet-customer-pwa-prototype.html`; `docs/82` §8 records assembling them into a served page as *"the remaining mechanical step"*. **Not done** |
| **What the install serves** | the Admin panel (`panel/`) and the Admin API. `public/` is **excluded** from the package — *"the Customer PWA API front controller, which nothing here serves"* (`plugin/plugin.json`) |

---

## B. What "an operator can sign in" needs — four pieces

| # | Piece | Whose decision | State |
|---|---|---|---|
| 1 | **A login exists** — DishNet staff create the operator's owner, with a phone number | decided (`docs/116` §B: the first owner is created by nobody else) | **this document's build** |
| 2 | **The code reaches the phone** — a delivery channel | **the operator's**: it needs an outside account, a sender and a budget | **open** — §B.1 |
| 3 | **The operator app is served** — the screens assembled, `public/` added to the package, the customer routes served beside the panel | follows from the go-ahead; reverses the RC1 exclusion, which was recorded *because nothing served it* | not started |
| 4 | **The operator's status is enforced at sign-in** (F-J1-1) | a migration to three authentication functions | **before piece 3 goes live** |

### B.1 The delivery channel — the question for the operator

| Option | For | Against |
|---|---|---|
| **SMS through a provider** (recommended) | works on every phone; the standard channel for sign-in codes; Domain B's own dependency | an account with a provider operating in Uganda, a sender name, a cost per message |
| WhatsApp through the existing gateway | already on the server; free per message | it is **Domain A's** gateway and number (`CLAUDE.md`: no change to Domain A without explicit authorisation); a sign-in code sent through an unofficial WhatsApp client risks the number the Starlink business depends on |
| Decide later | nothing is bought yet | nobody outside DishNet can sign in |

**Never, on any channel:** a code shown to DishNet staff to read out (codes are
stored as hashes, and a relayed code is a credential in a second person's
hands), and never `DNB_EXPOSE_OTP` on a public host — with it, anyone who knows
an operator's number could sign in as them.

---

## C. Decisions for J-1

| # | Decision | Reason |
|---|---|---|
| D-1 | **Scope:** bind `POST /api/v1/admin/customers/{customer_id}/principals` on `dnb_adminwrite` through the existing function, and add the owner form to the operator's page. **No migration**, no change to the function, no change to sign-in | the instruction, and nothing it does not need |
| D-2 | **Body:** `kind` (`owner` or `staff`, default `owner`), `display_name` (1–120), `phone` (required), `capabilities` (staff only). A field the server derives — `id`, `actor`, `customer_id`, `operator`, `operator_id`, `status`, `created_at`, `last_login_at`, `credential_hash` — is **400, refused rather than ignored**. Contact data is not taken here (U-6: `mt_principals` is not a copy of anyone's contact record) The operator comes **from the path**, the one explicit target the Admin plane allows (D-AUTH-3) | the 030 routes' shape |
| D-3 | **The phone is required and stored in one canonical form:** spaces, dashes, dots and parentheses removed, then `+` and 8–15 digits (`^\+[1-9][0-9]{7,14}$`, E.164). A principal without a phone cannot sign in, and this route exists to create a login | `mt_auth_issue_code` looks the phone up **exactly**; two spellings of one number would be two keys |
| D-4 | **The sign-in side must apply the same canonical form** when the operator app is served (piece 3). Recorded as a requirement of that step, not built here: the customer routes are not served, and changing them is that step's review | one canonical form, applied at both ends |
| D-5 | **A duplicate phone is 409 `phone unavailable`** — the customer plane's wording, which says nothing about *where* the number is in use. The Admin projection withholds phones, and this route must not become a way round that (P-B) | P-B |
| D-6 | **A replay is refused by the phone's unique index**, as `docs/108` decided for principal creation: the insert fails before the audit row is written, in one transaction, so a retry writes **no second row and no second audit row**. No idempotency key: the function has none, and adding one is a migration nothing needs. The panel disables its button while sending and, on 409, reloads the list so a person who was in fact added is visible | RULE I-1 holds: nothing is written twice |
| D-7 | **Kind and capabilities are checked before the call**: an owner with capabilities is 400; a staff member's list must name only `op.*` capabilities from `OpCapability::ALL` and never `op.staff.manage`. The table's CHECKs stay the floor | a refusal a person can read, with the database beneath |
| D-8 | **An unknown operator is 404** (the foreign key refuses it; nothing is written). **The operator's status is not checked** — the function does not, and F-J1-1 must be fixed where sign-in happens, not in one creator | one fix in the right place |
| D-9 | **Capability `customers.write`** (Admin, Sales), as declared since 027 | unchanged |
| D-10 | **Response 201** with the new person read back through the Admin projection: id, operator, kind, name, status, capabilities, created, last sign-in — **never the phone** | the projection's allowlist |
| D-11 | **Panel:** the operator's page lists *People who can sign in* (from the bound projection) and offers *Add an owner* (name and phone). The copy says plainly that **sign-in codes are not delivered yet**, so nobody can sign in until that is decided (§B.1). The fourth method goes in `panel/onboarding.js` | nothing may imply a login that cannot happen |
| D-12 | **Manifest:** the route moves to `writes.bound` (eight); `declared_unbound` keeps plans, voucher batches and disconnect | the served surface equals the declared one |
| D-13 | **Simulator and fixtures unchanged:** they call the function directly (`docs/116` J-12). The simulator's synthetic phones are not in the canonical form; they never pass through this route | recorded, not repaired |

---

## D. Build record

### D.1 What was built — no migration

| File | What |
|---|---|
| `src/Auth/Phone.php` | the canonical form (D-3): separators removed, then E.164 or nothing. The one function the sign-in side must also use (D-4) |
| `src/Admin/OnboardingAdmin.php` | `addPrincipal()` — migration 027's `mt_admin_principal_create` on `dnb_adminwrite`. An unknown operator returns null (404); a duplicate phone is *phone unavailable*; a CHECK refusal is its reason, and a constraint's name is never shown |
| `src/Api/AdminRoutes.php` | the route (D-2 … D-10), and its removal from the 501 list |
| `plugin/plugin.json` | eight bound estate writes, three declared-unbound (D-12) |
| `panel/api.js`, `panel/onboarding.js`, `panel/app.js` | the principals read; `addOwner`, the fourth onboarding call; *People who can sign in* and *Add an owner* on the operator's page (D-11) |
| `plugin/doc/INSTALL.md` | one row, saying nobody can sign in yet |

### D.2 Proofs

**`tests/test_operator_owner_login.php`, 71 assertions**, grouped as:

- **the route** — capability matrix, 501 without a write connection;
- **refusals** — every derived field, kind, name, eight malformed phones, owner-with-capabilities, an unknown capability, `op.staff.manage` for staff, a malformed or unknown operator, and nothing written by any of them;
- **creation** — 201 through the projection, the phone canonical in the row and absent from the answer and the audit detail, one audit row by the signed-in subject;
- **refusal of repeats** — the same request, another spelling of the same number and the same number under another operator are all *phone unavailable*, name nobody, and write no second row and no second audit row;
- **staff from the Admin plane**;
- **sign-in at the API level** — see below;
- **the panel, the manifest, the repository**.

**The owner signs in at the API level.** A code issued for the canonical number verifies. It gives a session for that owner and that operator, resolved live as kind `owner`. `GET /me` answers with the operator's name, and another operator's location is invisible. What is missing is only the delivery of the code.

**Two gaps are asserted, not hidden.** Each assertion fails the day its gap is fixed, and must then be rewritten:

- **D-4**: the same person typing the number with spaces is not found by today's sign-in side.
- **F-J1-1**: the owner of a *suspended* operator can still sign in.

**Controls on the controls — five weakened copies of the route, each caught:**

| Copy | Caught by |
|---|---|
| the phone stored as typed, not canonical | 14 of 71: every malformed-phone refusal, the canonical row, the second-spelling refusal |
| `customer_id` accepted from the body | 2: the derived-field refusal, and the "nothing written" count |
| the phone added to the answer | 4: the projection's field list and three needle checks |
| the route gated on `customers.read` | 2: NOC and Support are no longer refused |
| the owner-with-capabilities check removed | 1: the answer becomes 409 — **the function's own check still refused it**, so the floor beneath the route held |

**Deliberately updated assertions**, each with its reason in the text:

- `test_admin_ui` §8: four onboarding calls, with the owner-creation shapes added to the verbs forbidden elsewhere.
- `test_installability`, `test_plugin_boundary`, `test_router_lifecycle_provision` and `test_operator_onboarding` §7–§8: eight bound writes, three unbound.
- `test_operator_staff` §13: its 501 assertion still passed, but for a different reason — that router has no write connection. It now asserts that reason by name rather than passing on a false premise.

| Check | Result |
|---|---|
| full suite | **37 suites, 3,577 assertions, 0 failed**, twice (was 36 / 3,500) |
| `plugin/bin/install-test.sh` | **85 of 85** |
| package | **124 files**, content digest **`82b8f4bff4fec002849218db3937ddf544ffe3bf2f40885aebb98e1757be8991`** |

### D.3 Driven in a real browser

Headless Chromium drove the screens against a fresh local install, signed in as
*sales* with the development sign-in. The run:

1. An operator was created. Its page showed *People who can sign in*, with the
   note that nobody can sign in yet.
2. An owner was added with the number typed as `+256 772 555 010`. They were
   listed as *owner*, and the number appeared **nowhere** in the page.
3. The same number typed `+256772555010` was refused as *already in use*. The
   list still showed one person.
4. A national form, `0772 555 011`, was refused with the reason.

The database held one owner, with the phone stored as `+256772555010`, and two
audit rows: `customer.created` and `principal.created`, both by `dev`, `staff`.

The failed requests were:

- the sign-in gate's first 401;
- the two deliberate refusals, 409 and 400;
- the browser's own request for `/favicon.ico`, which answered 404 because the
  panel declares no icon — not a fault.

### D.4 What comes next

- **Staging:** a command with no migration, pinned to this build's commit. After
  it, DishNet staff can add owners on staging, but nobody can sign in yet.
- **The operator's decision, §B.1:** how sign-in codes reach a phone.
- **Then, each with its own review:** close F-J1-1 (a migration to the three
  authentication functions); apply the canonical form at sign-in (D-4); serve
  the operator app (piece 3).
