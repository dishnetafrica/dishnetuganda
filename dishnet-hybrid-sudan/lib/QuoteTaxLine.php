<?php
/**
 * QuoteTaxLine — what every Uganda quotation says about tax (5.18.49, docs/42 §9).
 *
 * The operator, 27 Sep 2026, asked which was right for plans and accessories — the quotation's "No VAT is charged on
 * this quotation" or the assistant's "prices include VAT" — and answered: "we are giving quote including all the
 * taxes", and, for the WhatsApp quotation summary, "we are providing quote including UCC and URA charges". Then chose
 * the sentence below for every quotation, with or without a kit:
 *
 *   - the WhatsApp quotation summary, under the Total, from both builders: the quote.add webhook (a quote made in
 *     uCRM) and QuotationService::buildProformaMessage (the app and KYC quotes);
 *   - clause 2 of the Uganda quotation PDF (ucrm_pdf_templates/quotation_uganda), which staff load in uCRM;
 *   - the assistant's price fact (ai_fact_prices), which the operator sets with tools/set_config.php.
 *
 * A reply that quotes a kit price keeps its own line (KitTaxNote, 5.18.48). The sentence names no amount and no rate:
 * prices come from uCRM only.
 *
 * Uganda only (TenantProfile). South Sudan's quotation messages are unchanged, byte for byte.
 */
final class QuoteTaxLine
{
    /** The operator's wording, 27 Sep 2026 (docs/42 §9). The quotation PDF's clause 2 carries the same words. */
    public const TEXT = 'All prices include all taxes — URA taxes and UCC charges are already in them. Nothing is added on top.';

    /** Whether this install's quotations carry the line: Uganda's. Never throws; anything unclear is "no". */
    public static function applies(array $config, ?string $dataDir = null): bool
    {
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            return TenantProfile::current($config, ($dataDir !== null && $dataDir !== '') ? $dataDir : null)->id() === 'uganda';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
