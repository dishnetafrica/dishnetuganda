# 31 — Airtel Money: a way to pay DishNet (5.18.31 + website)

**25 September 2026.** Plugin `dishnet-hybrid-sudan` 5.18.30 → 5.18.31, and
the website `dishnet-web-uganda`. Not Domain B.

The operator sent a photo of DishNet's Airtel Money sign: *"airtel money —
ACCEPTED HERE"*, a QR code labelled **DISHNET AFRICA LTD**, *"FREE OF
CHARGE"*, *"Dial \*185\*9#"*, **Merchant ID 4428146**, *"Airtel Money is
regulated by Bank of Uganda."* They asked to study how people in Uganda pay
with it, and to use it as a payment option in DishNet, on the website, and in
the assistant.

## 1. How a customer pays a merchant ID

Airtel's own pages could not be opened from this session (egress blocked).
Search results quoting Airtel Uganda's *Airtel Money Pay* page agree with
the sign:

1. Dial **\*185\*9#** (Airtel Money Pay).
2. Enter the **merchant ID**, 4428146.
3. Enter the **amount**. Some sources say the payer may add a note, such as
   an invoice number.
4. Confirm with the **Airtel Money PIN**.

Or, in the **My Airtel app**, scan the merchant's QR code.

- **Free to the customer.** The merchant pays Airtel's fee, not the payer —
  the sign says *"FREE OF CHARGE"*.
- **Both sides get an SMS** with the details, including a transaction ID.
- **Nothing connects the payment to uCRM.** The money reaches DishNet's
  Airtel merchant account; the customer's invoice stays unpaid until a
  person records it. So the customer must send the transaction ID, and
  billing matches it.

What is not established here: the exact wording of each USSD screen, and
whether it shows the merchant name before the PIN. The website and the
assistant's answer are written to be true either way.

## 2. Where DishNet tells customers how to pay — before this change

| Channel | Said | Source |
| --- | --- | --- |
| The assistant on WhatsApp | Ecobank bank transfer only | `ai_fact_payment` (a setting) |
| Quotation summary on WhatsApp | "💳 Cash / Transfer / Card" | `webhook.php` quote.add, fixed text |
| Invoice and other e-mails | bank details only | `CustomerEmails` "How to pay", `email_bank_*` |
| Suspension, restoration and other account messages | a link | `contact_pay_url` → the website's `/pay` |
| Website `pay.html` | *"Message us on WhatsApp for the current MTN and Airtel merchant codes"* | static page |
| Website FAQ | "We accept MTN Mobile Money and Airtel Money", no code | static page |

The account messages all point at `dishnetuganda.com/pay`, so the pay page
reaches every one of them.

## 3. What changed

**Website (`dishnet-web-uganda/site`).**

- `pay.html` opens with **Pay with Airtel Money**:
  - the merchant ID, the merchant name DISHNET AFRICA LTD, and `*185*9#`;
  - four numbered steps, with *"If you are asked for a reference, enter your
    invoice or quotation number"*;
  - the My Airtel app (the QR code at the office, or the merchant ID);
  - **after you pay**: send the transaction ID on WhatsApp, with a
    pre-written message.
- The heading no longer promises that *"every payment reflects on your
  account automatically"*. A merchant payment does not.
- The third card asked customers to message for "MTN and Airtel merchant
  codes". It now covers MTN, bank and cash: message billing.
- The FAQ answer, and its FAQPage structured data, give the same steps.
- `verify-address.py` and `verify-site.sh` pass: 57 pages, 0 broken
  references, no price, one WhatsApp number. Checked in a browser at desktop
  and phone width, with no horizontal scroll.

**Plugin 5.18.31.**

- New setting **`pay_airtel_merchant`**, digits only. Unset changes nothing
  — South Sudan has no Airtel Money.
  - The **quotation summary** on WhatsApp gets *"💳 Airtel Money: Merchant ID
    4428146, dial \*185\*9#"*, then the old *"Cash / Transfer / Card"*.
    Nothing offered before is taken away.
  - The e-mails' **How to pay** list Airtel Money first, then the bank.
  - `tools/ai_facts.php --uganda` adds it to the preset payment answer.
  - `lib/PaymentOptions.php` holds all of this in one place.
- **The WhatsApp bold hazard.** WhatsApp pairs asterisks into bold, and
  `*185*9#` contains two. If any other asterisk shares its line, WhatsApp can
  swallow the code's first one, and the customer dials `185*9#`.
  - The plugin's own line ends with the code and carries no other asterisk.
  - When the assistant's payment answer contains a USSD code, its prompt now
    tells it the same. An answer without a code produces the same prompt as
    before.
- **The assistant's answer is a setting, `ai_fact_payment`.** The new text
  names Airtel Money and keeps the Ecobank transfer exactly as it was. It
  ends: send the transaction ID or the transfer confirmation here.
  - The accounts rule already hands *"payments the customer says they
    already made"* to a person, so that is who matches the ID.
  - The reply guard lets the merchant ID and the code through: seven digits
    do not look like a phone number or an amount. It still refuses an
    account number the answer does not contain (tested as the control).

**Tests.**

- `tests/test_airtel_money.php`, 58 assertions:
  - the setting, parsed;
  - the quotation summary through the real `webhook.php` under `php -S`,
    sending to the fake Evolution API, which keeps the whole message (the
    dry-run log keeps only 200 bytes);
  - every e-mail, with the setting set, unset, blank and unreadable;
  - the assistant's prompt, and the reply guard with a control;
  - the facts preset, run as the operator runs it.
- 13 weakened copies are each caught by counted failures.
- The full plugin suite passed twice: 188 files, 7,112 counted assertions,
  0 failed.

## 4. Deploy and switch on

The plugin:

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/deploy-hybrid.sh
```

Then the two settings, as the plugin folder's owner:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key pay_airtel_merchant --value 4428146
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key ai_fact_payment --value 'Pay by Airtel Money or by bank transfer. Airtel Money: dial *185*9#, enter Merchant ID 4428146, the amount and your Airtel Money PIN — it is free of charge for the customer. The merchant name is DISHNET AFRICA LTD, and at our office customers can also scan the Airtel Money QR code with the My Airtel app. Bank transfer: DishNet Africa Limited at Ecobank Uganda Limited, Head Office branch, Plot 4 Parliament Avenue, Kampala. UGX account number 7247510191. Enter the account name and number exactly as written, and use your own name or your quotation number as the payment reference. After paying either way, send the Airtel Money transaction ID or the transfer confirmation here so we can match it to your order.'
```

- Both were rehearsed on a scratch copy. Each value landed in the settings
  file and in the store copy, and the payment text was byte-identical to the
  tested one.
- The settings tool prints a note about account numbers after the second
  line. That is its standard warning.

**The website** is a separate deployment. In EasyPanel, project `web`, app
`web-uganda`: **Deploy**. It must build from the branch that carries the
change, as for the shop on 16 September ([21](21-shop-on-the-website.md)).

**Rollback.**

- `--clear` on either setting restores the old wording at once.
- For the code, redeploy `d52b30a`.
- For the website, redeploy the previous build.

## 5. Open — not decided here

- **MTN MoMo.** The website says, on over twenty pages, that DishNet accepts
  MTN Mobile Money. No MTN merchant code has been given. Send one and it gets
  the same treatment; if MTN is not accepted, those claims should go.
- **In-app and portal payment.** The pay page still offers "In the app →
  Invoices → Pay … MTN MoMo or Airtel Money" and portal mobile money. The
  app is not published (the site's own README lists the APK as a
  placeholder), and the DPO integration is a plan. Left as found; to be
  confirmed or removed.
- **`dishnet-ai`.** If the separate DishNet AI plugin still answers
  customers, its free-text *"Extra instructions for the AI"* field in uCRM
  needs the same payment text. That field is not in this repository.
- **The office QR.** The pages say the QR code is at the office — read from
  the photo of a desk sign. If it is somewhere else, one line changes.
