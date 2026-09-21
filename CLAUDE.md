# Working in this repository

The Domain B / MikroTik control-plane work lives under **`dishnet-hybrid-sudan/`**.
Note there are two `docs/` directories in this repository; every path below is
from the repository root.

**Read `dishnet-hybrid-sudan/docs/69-DECISION-INDEX.md` first.** Before changing
anything it covers, read the authoritative decision document it points to. Where
the index and a decision document disagree, **the decision document is right.**

## Settled — do not reopen without an explicit instruction

- **F1–F13 are FROZEN.** Amendment only by the process in `docs/53` §5.
- **Decision 1 — CLOSED: Model B.** The AAA credential is published at
  redemption/activation, not at voucher issue. (`docs/66` §1)
- **Decision 3 — CLOSED: P2.** The portal receives *generated* AAA credentials;
  **the voucher code never enters the AAA credential path.** (`docs/67`)
- **Decision 7 — CLOSED:** a dedicated **AAA Publisher**, with the privilege and
  reconciliation boundary as documented. (`docs/66` §2)

## Proven ≠ chosen

- **Decision 2a is PROVEN only as a FreeRADIUS technical/security requirement.
  Its production mechanism is NOT CHOSEN.** (`docs/68` §2.4)
- **Huntgroups are a measured mechanism, not the selected production design.**
  Do not build on them as if they were. (`docs/68` §2.4c–e)

## Open and parked

- **Decision 2b is OPEN**, awaiting C20 customer evidence. **Do not infer
  A/B/C from this repository.** (`docs/68` §2.5–2.6)
- **Do not implement F6** until the implementation gate is explicitly opened.
- **C1 guard tests are mandatory at that gate**, not after it. (`docs/68` Part 1)
- **`s1_s2_probe.php` repair is a separate test-maintenance task** and must not
  be bundled into F6. (`docs/65` §15)

*(`docs/NN` above means `dishnet-hybrid-sudan/docs/NN-*.md`.)*

## Always

Do not modify production FreeRADIUS, production databases, schema, privileges,
deployment, or Domain A (Starlink) without explicit authorization.
