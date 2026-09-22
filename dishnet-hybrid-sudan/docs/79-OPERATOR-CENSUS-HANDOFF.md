# 79 — Operator handoff: run the production census

**For the person with access to the deployed Domain B control-plane database.**
Everything below is read-only. It changes nothing, repairs nothing, and reveals
no credential.

**Do not send credentials back — return the census output only.**

---

## 0. Before anything: find the right database

The control-plane database is **not** necessarily `dn-phase0-postgres` — that
container holds the **RADIUS** database (`radius`), which is a different
instance. The control plane uses its own DSN (`DNB_DSN`), and where it runs was
never settled (docs/55 §416).

**Take the DSN from the deployed Domain B service's own configuration** — its
environment, compose file or service definition — **not from memory and not from
this repository.**

> **If no Domain B service is deployed, stop here and say so, naming how you
> checked.** That is the answer to question 1 and no census is needed.

---

## 1. The command

```bash
cd <repo>/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane

psql -q -f tools/audit/production_census.sql > census.txt 2>&1
```

**The `2>&1` is required, not decoration.** The census prints through
`RAISE NOTICE`, which goes to **stderr**. Verified: a plain `>` captures 8 lines
of psql chatter and **none** of the census; `> census.txt 2>&1` captures the
whole transcript, all 6 sections.

## 2. Connection, without typing a password anywhere

Set the non-secret parts as environment variables and let `psql` obtain the
password itself:

```bash
export PGHOST=<host>          # or the socket directory
export PGPORT=<port>
export PGDATABASE=<control-plane database name>
export PGUSER=<role — see §3>
```

For the password, use **either**:

- **`~/.pgpass`** (preferred) — `chmod 600`, one line:
  `hostname:port:database:username:password`. `psql` reads it automatically and
  nothing is typed or echoed; **or**
- **`psql -W`**, which prompts and does not echo.

**Do not** put the password in the command line, in `PGPASSWORD`, in a shell
history, or in any message back to us.

Running inside the container instead is equally fine:

```bash
docker exec -i <container> psql -q -U <role> -d <database> \
  < tools/audit/production_census.sql > census.txt 2>&1
```

## 3. Minimum role requirements

| | Requirement | Why |
|---|---|---|
| **`BYPASSRLS`** (or superuser) | **essential** | These tables use `FORCE ROW LEVEL SECURITY`, which binds even the table owner. Without it **the counts can read 0 while rows exist** |
| `CONNECT` on the database, `USAGE` on schema `public` | essential | to run at all |
| `SELECT` on `mt_devices`, `mt_vouchers`, `mt_voucher_batches`, `mt_sites`, `mt_migrations` | essential | `BYPASSRLS` removes the row filter, not the need for `SELECT` |
| Any write privilege | **not required** | the script cannot write — §7 |

The script checks this itself and prints a warning **before and after** the
counts if the role cannot bypass RLS. **If that warning appears, the numbers are
lower bounds, not totals — re-run as a bypassing role.**

## 4. Confirming you are on the authoritative database

The output answers this itself; check three things in `census.txt`:

1. **§0** — `database`, `server address`, `port`, `user`. Confirm these match the
   deployed service's configured DSN.
2. **§1** — whether `mt_sites`, `mt_devices`, `mt_vouchers`,
   `mt_voucher_batches` and `mt_migrations` exist, and **every migration
   filename applied**. A control plane in real use has the full ledger.
3. If §1 reports the schema **absent**, that is authoritative for *that database
   only*. Say which DSN you used, so we can tell "wrong database" from "not
   deployed".

## 5. Saving the output

`census.txt` from §1. It is plain text, counts only. **Verified: it contains no
customer names, phone numbers, voucher codes, credentials or connection
strings.** The only occurrence of the word "password" anywhere in the transcript
is the script's own line *"identity only — no password or connection string is
read or printed."*

## 6. What to return

**The whole of `census.txt`** — all six sections, unedited:

| | |
|---|---|
| **§0** | role and RLS visibility, database identity |
| **§1** | deployment evidence — tables present, migrations applied |
| **§2** | `mt_devices` — totals, unsited, partial-null, cross-customer, orphaned, decommissioned-but-sited, shared tunnel IPs |
| **§3** | `mt_vouchers` and `mt_voucher_batches` — totals, NULL site, cross-customer, orphaned, **site-less vouchers broken down by state**, and **NULL-site batches that already contain vouchers** |
| **§4** | per-constraint blocking-row counts |
| **§5** | the caveats, including the RLS restatement |

Plus, in your own words, the §0 answer for the deployment questions the database
cannot answer: **(a)** no other database holds these tables, **(b)** nothing is
running against one, **(c)** the DSN you used is the one a deployment would use.

**Do not return** any credential, connection string, `.pgpass` content or
environment dump.

## 7. The warning

- **The census is read-only and enforced as such.** The whole run is inside
  `BEGIN; SET TRANSACTION READ ONLY;` and ends in `ROLLBACK`. Verified: an
  `UPDATE` inside such a transaction returns
  `ERROR: cannot execute UPDATE in a read-only transaction`. The engine refuses
  writes — this is not a promise in a comment.
- **Run nothing else.** Do **not** follow it with any repair, backfill,
  `UPDATE`, `DELETE`, `ALTER TABLE`, constraint addition, RLS change, or
  "quick fix" of anything it reports. **No migration is authorized.**
- **If it reports violations, that is the expected outcome of a census.** Send
  the numbers. The treatment for each class is designed (docs/74 §7) and
  **awaiting approval**; applying any of it now would destroy the evidence of
  what was there.
- If the script errors, send the error verbatim rather than editing the script.

---

## 8. What happens next

`AUTHORITATIVE PRODUCTION CENSUS → REVIEW EVIDENCE → DECIDE B → FINALIZE
BACKFILL/MIGRATION PLAN → EXPLICIT F6 AUTHORIZATION.`

Nothing is implemented until the evidence is reviewed. In particular
**`mt_voucher_batches.site_id NOT NULL` is an OPEN schema decision**, not a
pending consequence — see docs/78 §4.2.
