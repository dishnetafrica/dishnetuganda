# 63 — F6: Voucher Redemption — Hostile Read-Only Audit

Scope: `5c40fc8`. **Read-only. Nothing was fixed.** Every claim was produced by execution against a
database built from migrations 001–018, or by reading the code path it describes. Probe in
`tools/audit/f6_redeem.php`.

**Verdict: PROVEN VULNERABLE at the database boundary; NOT REACHABLE over HTTP today.** Both halves
matter, and the second is the part docs/58 and docs/62 did not establish.

---

## 1–4. What the function is

| | |
|---|---|
| Function | `mt_voucher_redeem(p_code text)` |
| Owner | `dnb_def_net` |
| Security | `SECURITY DEFINER`, `search_path` pinned to `public, pg_temp` |
| EXECUTE granted to | `dnb_def_net`, **`dnb_app`** |
| Tables reached | `mt_vouchers` only — `UPDATE … RETURNING` |
| Other functions reached | none |

```sql
UPDATE mt_vouchers v
   SET state = 'active', activated_at = now(),
       expires_at = now() + (v.duration_s || ' seconds')::interval
 WHERE v.code = p_code AND v.state = 'unused'
RETURNING v.id, v.customer_id, v.duration_s, v.expires_at;
```

## 5. How ownership is derived

**It is not.** The only predicates are `code` and `state = 'unused'`. There is no tenant context in
the query, no join to a service or site, and no comparison against `mt_current_customer()`.

This is deliberate, and the code says so:

> *"NOT customer-scoped: redemption arrives from the network side, which presents a code and nothing
> else. Which customer it belongs to is the answer, not the input."*

That is the correct design **for a guest at a captive portal**. The defect is not the derivation —
it is that the same privilege sits on `dnb_app`, the role that serves authenticated customer
requests. Structurally identical to F1, which was remediated by moving RADIUS ingestion to its own
identity.

## 6–7. Attack: P against Q, with a known code

Customers P and Q, each with a plan, a voucher and a hotspot user. As `dnb_app` inside **P's**
tenant context, redeeming **Q's** code:

| Question | Result |
|---|---|
| Does a caller-supplied code select another customer's voucher? | **YES — REDEEMED** |
| Read Q's voucher row? | **no** — RLS still hides it before and after |
| **Mutate Q's voucher?** | **YES — `unused → active`, `activated_at` and `expires_at` set** |
| Create or modify Q's hotspot user? | no — 1 → 1 |
| Create or modify Q's session? | no — 0 → 0 |
| Alter Q's accounting? | no — 0 → 0 bytes |
| Bypass the customer policy generally? | no — only this function's own row |

So the blast radius is **exactly one thing: burning another customer's voucher.** It is a
destructive write, not a disclosure of Q's data and not a foothold into Q's sessions or accounting.

The damage is real in product terms: the voucher moves to `active` with a live expiry, so the guest
Q sold it to finds it already consumed, and Q has no way to see what happened (below).

## 8. What the return value discloses

| Field | Disclosed |
|---|---|
| `voucher_id` | Q's voucher uuid |
| **`customer_id`** | **Q's customer uuid — a tenant identifier handed to a caller who is not Q** |
| `duration_s`, `expires_at` | yes |
| price, plan, site, batch | no |

The `customer_id` disclosure is the one that matters: it hands a tenant identifier to whoever
presents a code, including an unauthenticated guest at a portal once a route exists.

## 9. Two findings this audit adds

**9a. Redemption is not audited at all.** After a successful cross-customer redemption,
`mt_audit_log` contained **0 rows**. Nothing records that a voucher was redeemed, by whom, or from
where — so the attack above is not merely undetected, it is *unrecordable* after the fact. The
victim cannot distinguish "a guest used it" from "another customer burned it".

**9b. A code is not bound to the site it was issued for.** `mt_vouchers` carries `site_id`, and
redemption ignores it. Even within one customer, a code issued for one site is redeemable at any
other. That is a product-rule gap independent of the cross-customer issue, and the acceptance
target in the brief — *"only redeem a voucher legitimately available to that customer's
service/site"* — is not met today even for the owner's own vouchers.

## 10. Reachability — the part that changes the severity

| Question | Answer |
|---|---|
| HTTP routes referencing redemption | **0** |
| PHP callers of `VoucherService::redeem()` outside tests | **0** |
| Is it wired into the Customer PWA path? | no |
| Is it wired into any internal route? | no |

`VoucherService::redeem()` exists and is tested, and **nothing calls it**. The captive-portal
redemption flow has not been built. So today F6 is reachable only by an actor who can already
execute SQL as `dnb_app` — the same precondition as F9.

docs/58 §4 F6 said the function is "callable from a customer-authenticated path". **That was an
inference, not a measurement, and it is wrong: no such path exists.** Correcting it does not make
the finding go away — it dates it. The moment a portal route is added, F6 becomes reachable by an
unauthenticated guest, and the natural place to add that route is the same work that builds the
redemption flow.

## 11. Enumerability and rate limiting

- Alphabet 32 characters (`0`, `O`, `1`, `I` excluded), length 10 → keyspace **1.13 × 10¹⁵**.
  Blind guessing is impractical.
- **No rate limiting inside the function**, and none applies in the application because there is no
  route. A failed redemption is indistinguishable from an unknown code, which is correct, and it
  also leaves no trace, which is not.
- So the realistic path to another customer's code is not brute force — it is any place a code is
  legitimately visible: a printed batch, a shared screen, an operator, or a support ticket.

## 12. Legitimate paths still work

| | |
|---|---|
| P redeems its own code | **REDEEMED**, as intended |
| Replay of an already-redeemed code | refused |
| Unknown code | refused, **identically** — no oracle |

## 13. Classification

| Item | Class |
|---|---|
| Caller-supplied code selects another customer's voucher | **PROVEN VULNERABLE** |
| Cross-customer **mutation** (`unused → active`) | **PROVEN VULNERABLE** |
| Tenant identifier returned to a non-owner | **PROVEN VULNERABLE** |
| Redemption is unaudited | **PROVEN VULNERABLE** (new) |
| Code not bound to its issuing site | **PROVEN VULNERABLE** (new, product-rule) |
| Cross-customer **read** of the voucher row | **PROVEN SECURE** — RLS holds |
| Hotspot users, sessions, accounting | **PROVEN SECURE** — untouched |
| Replay, unknown-code oracle | **PROVEN SECURE** |
| Reachable over HTTP today | **PROVEN SECURE** — no route exists |
| docs/58's "callable from a customer-authenticated path" | **REJECTED** — no such path |
| Behaviour once a portal route exists | **UNPROVEN** — that route is not written |

## 14. What remediation will have to decide (not decided here)

1. **Which identity may redeem.** The F1 precedent suggests a fourth network-side identity rather
   than `dnb_app` — but redemption is initiated by a *guest*, not by RADIUS, so it may warrant its
   own role rather than reusing `dnb_radius`.
2. **Whether the return value may name a customer.** The caller needs the duration; it does not
   obviously need the tenant uuid.
3. **Whether a code is bound to a site**, and what happens to codes already issued if it becomes so.
4. **What redemption records**, given it currently records nothing.
5. **Whether an authenticated customer should be able to redeem at all**, or whether that is purely
   a guest action — which is the question the four-actor model would ask, and the answer shapes
   where the privilege lands.

None of these is a fix, and none was applied. **Stopping here for remediation instructions.**
