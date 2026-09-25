# 32 — DPO Pay: checked against DPO's instructions, and a test link for their review (5.18.32)

**25 September 2026.** Plugin `dishnet-hybrid-sudan` 5.18.31 → 5.18.32. Not
Domain B. The technical record is `dishnet-hybrid-sudan/docs/UGANDA-DPO-PAY-BUILD-SPEC.md`
§9; this page is for the operator.

## 1. What DPO sent

An e-mail from DPO Pay's Country Director for Uganda, forwarded by the operator
with the words *"check this for DPO"*, and DPO's *Recurring Payments –
Implementation Guide* (a 6-page PDF marked *Classification – Public*).

- **The documents DPO asked for earlier** — DPO asks for them again. That is the
  operator's to send.
- **Option A**, the integration to build first:
  1. `createToken`;
  2. send the customer to `https://secure.3gdirectpay.com/payv3.php?ID=<token>`;
  3. `verifyToken` to check it was paid.
- **The endpoint:** `https://secure.3gdirectpay.com/API/v6/`.
- **Six test company tokens**, each with a *Test Product* and a *Test Service*
  service type. The PDF's last page gives one of them with *54842 – Test
  Product*, plus three test card numbers, expiry 12/28 and CVV 123.
- **Then:** *"share a test link/URL where we will verify to confirm the
  implementation has met the required standards and provide you with the LIVE
  API credentials."*

**No token is copied into this repository.** They are entered on the DPO Pay
admin screen, which never displays one.

## 2. What was checked, and what it found

DPO Pay was built in September (steps 1–10, before any credentials existed) from
DPO's published code. Checked against what DPO has now told us:

| | Our build | DPO says | 5.18.32 |
|---|---|---|---|
| createToken | `/API/v6/` | `/API/v6/` | unchanged |
| verifyToken | `/API/v7/` (DPO's published class) | `/API/v6/` | **`/API/v6/`** |
| checkout page | `payv2.php` (DPO's published class) | `payv3.php` | **`payv3.php`** |
| `PTL` / `PTLtype` | spelling unconfirmed | `hours` in the guide; `minutes` in DPO's own plugin | confirmed, unchanged |
| the verify answer | reads the amount, currency and card type | the V6 example has them, and no `CompanyRef` | settles — tested |
| customer phone | digits only, with the country code | the guide's example leaves the country code out; DPO's own plugins send it | unchanged |

Three problems turned up that DPO did not mention:

1. **In the test environment, every customer would have seen Pay Now.** A test
   payment would also have settled like a real one. DPO's test cards are in a
   public document, so anyone could have used one to clear a real invoice in
   uCRM while the test token was switched on. **Fixed:** in the test
   environment only named *test customers* can pay. A test payment for anybody
   else is held back for a person to look at and never posted to uCRM.
2. **DPO's reviewer had no way to pay.** The customer portal signs people in
   with a one-time code sent to the customer's own phone. **Fixed:** a test
   link (section 3).
3. **The "paid" invoice status was wrong.** It read 4 as paid; in uCRM 3 is
   paid and 4 is void. No invoice was ever wrongly payable, but the reasons
   shown were wrong. **Fixed:** only unpaid and part-paid invoices (1 and 2)
   can be paid online.

## 3. The test link

`https://<crm address>/crm/_plugins/dishnet-hybrid-sudan/public.php?page=dpo_test&k=<key>`

The DPO Pay admin screen shows the full link once it has been made. It opens
a page that lists the **test customers'** unpaid invoices, each with a **Pay with
DPO** button. The button starts a payment exactly as a customer's Pay Now does:

1. DPO's test page opens.
2. The reviewer pays with a test card.
3. DPO returns them to DishNet's payment result page.
4. That page confirms the payment with DPO, then says *Payment successful*, and
   offers **Back to the test page**.

- It opens **only in the Test environment** and **only with the key**.
  Otherwise it answers *Not found*.
- **Replace the test link** on the admin screen makes a new key; the old link
  stops working.
- The page does not pass its address on when the reviewer is sent to DPO, so
  DPO's page never sees the key. Search engines are told not to index it.

**What a test payment does in uCRM:** it records a real uCRM payment on the
test customer. The payment is marked `DPO Pay | … | Env: test` in its note. The
DPO screen leaves test rows out of its totals.

- Use a **client made only for this**, with no real person's phone or e-mail:
  the plugin sends a new invoice to the client by e-mail and WhatsApp.
- After DPO's review, the test payment and invoice can be deleted in uCRM.

## 4. What to do

**1. Deploy 5.18.32:**

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/deploy-hybrid.sh
```

**2. In uCRM**, create a test client, for example *"DPO Test Customer"*.

- Leave its phone and e-mail empty, or use DishNet's own.
- Give it one small invoice in UGX, for example 1,000.
- Note its client id: the number in the address when the client is open.

**3. In the plugin, open Admin → DPO Pay** and set:

- **Environment:** Test.
- **Company token:** one of the test tokens from DPO's e-mail.
- **Service type:** that token's *Test Service* number.
- **Currencies accepted:** `UGX`.
- **Test customers:** the test client's id.
- **Pay Now:** Enabled.
- **uCRM payment method:** check it is filled. It was set up on 13 September.

Then **Save settings**, and **Make the test link**. The top of the screen must say
**Saved.** in green. A red *NOT vaulted* means the backup failed: stop and send
what it says.

**4. Check that DPO accepts the token in UGX** before sending anything. This checks
the token and service type **saved in step 3**, so nothing is typed here:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/dpo_probe.php
```

- **Expected:** `createToken 000`, then `verifyToken 900` (*not paid yet*).
- **What a token looks like:** 36 characters in five groups,
  `XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX`. Its service types are the numbers
  listed under it in DPO's e-mail.
- **With `--ask`** (5.18.35), nothing typed or pasted at either prompt appears
  on the screen. That is expected: paste, then press Enter.
  - The tool answers `Company token: received, 36 characters (not shown)`.
  - It shows the service type back, and checks each answer as soon as it is
    given.
  - Anything else is refused before DPO is asked, and is described by its
    length only.
- **`904`:** that test account does not take UGX. Try another token from the
  e-mail without saving it. The token is typed without being shown:

  ```
  docker exec -it -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/dpo_probe.php --ask
  ```

  When one answers `000`, save that token and its service type on the admin
  screen.
- The tool never prints the token, and writes nothing on our side.

**5. Try the link yourself.** Pay with a test card from the last page of DPO's
PDF, using your own name. Expect:

- *Payment successful*;
- a payment on the test client in uCRM.

**6. Reply to DPO** with the items below. **Deploy 5.18.34 first** and copy the
addresses after it: until then they came out as
`https://crm.dishnetuganda.com:8443/…`, UISP's port with a self-signed
certificate. Neither may contain `:8443` ([33](33-links-without-port.md)).


- the test link;
- the **Push / notify URL** from the admin screen's *Give these to DPO* box —
  DPO registers it on the account, because it is not part of the API request;
- the documents they asked for again.

**7. Ask DPO:**

- Will the **live** account take **UGX**?
- Will **MTN Mobile Money** and **Airtel Money** show on DPO's page for Ugandan
  customers?
- The live **service type** and **company account ref**.
- **How are refunds done:** in DPO's portal, or through the API?
- The **transaction limits**.

**When the live credentials come:**

1. Enter the live token and service type.
2. Set **Environment: Live**. The test link stops working.
3. Pay Now then shows for every customer whose invoice is unpaid.

## 5. Recurring payments — DPO's PDF

Recurring payments save a customer's card on the first payment and charge it
again later:

1. The first payment is made on DPO's page, with `<AllowRecurrent>1</AllowRecurrent>`
   added to `createToken`.
2. `getSubscriptionToken` finds the saved card by e-mail or phone.
3. Each later charge is a new `createToken`, then `chargeTokenRecurrent`.

- **Cards only.** DPO's guide says mobile money does not support recurring
  payments, and most customers here pay by mobile money.
- **Not built.** DPO asks for Option A first.
- Building it needs decisions that are not technical: the customer's consent
  and terms for automatic charges, what happens when a charge fails, and how a
  customer stops it.

## 6. Tests

- `tests/test_dpo_review_link.php`: **85 checks.**
  - DPO's endpoint and page.
  - A V6-shaped verify answer settles.
  - The test environment admits only test customers, and quarantines a payment
    for anyone else.
  - The portal's Pay Now follows the same rule.
  - The test link, driven through `php -S` from the Pay button to *Payment
    successful*, against a fake DPO and a fake uCRM.
  - The DPO probe tool.
- Two existing DPO suites were updated where the endpoint, the checkout page or
  the statuses changed: `test_dpo_client` (69 checks) and
  `test_dpo_payment_service` (86). The other two, `test_dpo_endpoints` (74) and
  `test_dpo_screens` (51), pass unchanged.
- **16 weakened copies** of the new safeguards, each caught by counted failures.

## 7. Still open

- **UGX, MTN Mobile Money and Airtel Money on the live account.** Refunds,
  limits, the live service type. Section 4, step 7.
- **Where customers will find Pay Now.** It is in the plugin's customer
  portal. The website's *Customer portal* buttons open uCRM's own client zone,
  which does not have it. Decide before going live.
- **A refund and cancellation policy on the website.** The site has Terms of Use
  and a Privacy Policy but no refund policy. Card acquirers usually ask for one,
  and DPO's review may look.

## 8. On the server, 25 September 2026

- **5.18.32 deployed:** `1fd1478` over `f3ab37e`, *"✓ container now serves
  1fd1478"*.
- **The first probe run** answered *No company token is set*. That was correct:
  nothing had been entered yet.
- **The `--ask` run was not given a DPO token.** DPO answered 801, *Request
  missing company token*.
  - The text typed at the service-type prompt was not a service type either,
    most likely whatever was in the clipboard. It is not recorded here.
  - Whatever was entered at the hidden token prompt was sent to DPO as the
    token.
  - If either was a password or a key for anything, it has been exposed, and
    should be changed.
- **5.18.33:** the tool now refuses anything that is not shaped like a DPO token
  or service type, before sending it anywhere.
- **The second `--ask` run, the same day,** was the old tool: 5.18.33 was not
  deployed yet.
  - The token prompt reached DPO empty, and DPO answered 801 again.
  - What went in at the service-type prompt was again not a number, and that
    prompt showed it on the screen. It is not recorded here.
  - **5.18.35:** neither prompt shows anything now.
- **The token was saved on the screen, and the probe still said *No company
  token is set*.** Since the first build, no save made on the DPO Pay screen
  had reached the vault.
  - The screen sends its settings to the vault as one batch. Two of them,
    `dpo_currencies` and `dpo_unpayable_statuses`, were not keys the vault
    takes, and the vault refuses a whole batch for one such key.
  - The screen reported that in green, like a success.
  - The probe, the test page for DPO's reviewer, the return page, DPO's push
    and the reconcile job read only the vault, so for them the token did not
    exist. Only the screen and Pay Now saw it.
  - **5.18.36:** every one of them reads what the screen saved first. The
    batch goes through, a failure shows in red, and the probe says where its
    settings came from.
- **5.18.34:** the return, push and test addresses on the DPO screen, and the
  addresses Pay Now gives DPO, carried `:8443`. The screen read a copy of the
  settings without the setting that removes the port. Fixed; see
  [33](33-links-without-port.md).
