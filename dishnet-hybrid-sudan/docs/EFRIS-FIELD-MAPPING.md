# EFRIS field mapping — uCRM → internal model → official EFRIS

Status: **uCRM column FROZEN 2026-09-05 against live probe output (invoice
#000001, Family Shoppers, Starlink Mini Kit). Official column stays TBC until
the URA specification arrives (Phase 2).**

## Probe results (2026-09-05, live install)

1. **Item tax shape on this install: `tax1Id/tax2Id/tax3Id` integer
   references** — name and rate resolve through uCRM's `/taxes` registry
   (fetched and cached by `EfrisService::taxRegistry()`). Per-item tax
   AMOUNTS are deliberately left null in the internal model: computing them
   needs the organisation's pricing mode (tax-inclusive vs exclusive) plus
   the official EFRIS rounding rules — Phase-2 translation work. The
   invoice-level `totalTaxAmount` is authoritative meanwhile.
2. **Native tax identity exists in uCRM**: `client.companyTaxId` (TIN home)
   and `client.companyRegistrationNumber` (BRN) — now the primary source,
   with the EFRIS custom attributes as override (and the only home for NIN).
3. **`clientType`** (1 = residential person, 2 = company) decides buyer type;
   the TIN/company heuristic is only the fallback.
4. `dueDate` (full datetime) is this install's field; `maturityDate` remains
   a fallback. **`taxableSupplyDate` exists** and is carried in the model —
   the VAT time-of-supply EFRIS cares about.
5. `subtotal`, `totalTaxAmount`, `totalDiscount`, `taxes[]` (breakdown) are
   present at invoice level and preferred over re-summing items.
6. `proforma` flag exists — proformas are refused (only final invoices
   fiscalise).
7. `taxes` was `[]` and every `taxNId` null: **no VAT is configured in this
   uCRM yet**, consistent with VAT registration still being open with the
   accountant. The mapper correctly reports the tax category as unresolved
   until the operator maps it.

The mapper (`lib/EfrisInvoiceMapper.php`) is deliberately two-stage: uCRM →
internal model now, internal model → official T109 field names in Phase 2,
transcribed from the official URA specification. Nothing official is guessed.

Legend for the `Probe` column: ✔ = confirmed by code the plugin already runs
in production against this API; ? = confirm against the probe output.

## Seller (constants from plugin Configuration)

| Internal model | Source | Official EFRIS (TBC) | Probe |
|---|---|---|---|
| seller.tin | config `efris_tin` (1059140632) | sellerDetails.tin | n/a |
| seller.legal_name | config `efris_legal_name` | sellerDetails.legalName | n/a |
| seller.business_name | config `efris_business_name` | sellerDetails.businessName | n/a |
| seller.address | config `efris_address` | sellerDetails.address | n/a |
| seller.phone / email | config | sellerDetails.mobilePhone / emailAddress | n/a |
| seller.device_no | config `efris_device_no` (URA-issued) | basicInformation.deviceNo | n/a |

## Invoice

| Internal model | uCRM field | Official EFRIS (TBC) | Probe |
|---|---|---|---|
| invoice.ucrm_id | `id` | (internal reference only) | ✔ |
| invoice.number | `number` (fallback `invoiceNumber`) | basicInformation.invoiceNo/internal ref | ✔ (webhook.php reads it) |
| invoice.issued_date | `createdDate` | basicInformation.issuedDate | ✔ |
| invoice.due_date | `maturityDate` (fallback `dueDate`) | (payment terms section) | ✔ (webhook.php reads it) |
| invoice.currency | `currencyCode` | basicInformation.currency | ? |
| invoice.ucrm_status | `status` (0 draft/1 unpaid/2 partial/3 paid/4 void) | n/a — eligibility gate | ✔ (documented in webhook.php) |
| invoice.payment_status | derived from `status` | payWay/payment section | ✔ |
| invoice.amount_paid | `amountPaid` (fallback total−`amountToPay`) | payment section | ? |

## Buyer (uCRM client + custom attributes)

| Internal model | uCRM field | Official EFRIS (TBC) | Probe |
|---|---|---|---|
| buyer.name | `companyName` else firstName+lastName | buyerDetails.buyerLegalName | ✔ (quote PDF uses same) |
| buyer.type | derived: TIN or company ⇒ business | buyerDetails.buyerType enum | TBC enum values |
| buyer.tin | custom attribute *EFRIS TIN* | buyerDetails.buyerTin | ? (run attributes tool) |
| buyer.brn | custom attribute *EFRIS BRN* | buyerDetails.buyerBrn | ? |
| buyer.nin | custom attribute *EFRIS NIN* | buyerDetails.buyerNinBrn | ? |
| buyer.taxpayer_type | custom attribute *EFRIS Taxpayer Type* | buyerDetails (enum) | TBC enum values |
| buyer.address | `street1`,`street2`,`city` | buyerDetails.buyerAddress | ✔ (quote PDF uses same) |
| buyer.phone / email | first entry in `contacts[]` | buyerDetails.buyerMobilePhone/Email | ✔ (quote PDF uses same) |

## Items

| Internal model | uCRM field | Official EFRIS (TBC) | Probe |
|---|---|---|---|
| items[].label | `label` (fallbacks `name`,`description`) | goodsDetails[].item | ✔ |
| items[].qty | `quantity` | goodsDetails[].qty | ✔ |
| items[].unit_price | `price` | goodsDetails[].unitPrice | ✔ |
| items[].discount | `discountTotal` (fallback `discount`) | goodsDetails[].discount fields | ? |
| items[].line_total | `total` (fallback qty×price) | goodsDetails[].total | ✔ |
| items[].tax.{name,rate,amount,ucrm_tax_id} | **CONFIRMED: `tax1Id/2/3` reference + `/taxes` registry** (tolerant reader still accepts `taxes[]`, `tax{}`, embedded `tax1..3`, `taxRate`/`taxAmount` from other uCRM versions); shape recorded in meta.tax_shapes | goodsDetails[].taxRate / tax + taxDetails[] | ✔ probe 2026-09-05 |
| items[].tax_category | operator map (uCRM tax name or `id:N` → standard/zero_rated/exempt/non_taxable/other; `__no_tax__` decides tax-free lines) | EFRIS tax category enums | TBC enum codes |
| items[].commodity_code | operator map (uCRM item name → URA goods code) — **never guessed** | goodsDetails[].goodsCategoryId | codes from URA list |

## Totals

| Internal model | uCRM field | Official EFRIS (TBC) | Probe |
|---|---|---|---|
| totals.subtotal | Σ item line totals | summary/taxDetails | ? cross-check `subtotal` |
| totals.tax_total | Σ item tax amounts | taxDetails[] | ? cross-check invoice `taxes` |
| totals.grand | `total` | summary.grossAmount | ✔ |

## Response (stored verbatim in efris_transactions — never composed)

| Column | Phase-1 fake server | Official EFRIS (TBC exact names) |
|---|---|---|
| fdn | TEST-FDN-… | fiscal document number |
| verification_code | TEST-VERIFICATION-… | antifake/verification code |
| qr_data | TEST-QR\|… | QR payload (format per spec) |
| efris_reference | TEST-REF-… | EFRIS reference/transaction no |
| fiscalised_at | server timestamp | fiscalisation time |
| response_payload | full raw body | full raw body (audit) |

## To confirm with the probe (`php tools/efris_invoice_probe.php latest`)

1. Which tax shape this install's items actually carry (drives the frozen reader).
2. Exact names: `currencyCode`, `amountPaid`/`amountToPay`, invoice-level `taxes`, `subtotal`.
3. The custom-attribute keys uCRM generated for the four EFRIS attributes.
4. Whether discounts appear as `discountTotal`, negative lines, or both.
