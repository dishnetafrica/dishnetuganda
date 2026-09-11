<?php
/**
 * QuotePdfSource — get the quotation PDF, whatever this uCRM install offers.
 *
 * Some installs serve the rendered quote over the API; this one serves none —
 * every endpoint spelling answers 404 for a quote that plainly exists. So the
 * fetch is: ask uCRM both ways, and if neither answers, render it here with
 * wkhtmltopdf.
 *
 * Extracted from QuotationService because the quote email now has two entry
 * points — a quote created through the DishNet app, and one created in uCRM's
 * own screen, which arrives as a quote.add webhook. Both need the same PDF by
 * the same rules, and a second copy of this logic would drift.
 *
 * Never throws. An empty string means no PDF, and the caller decides what that
 * is worth.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class QuotePdfSource
{
    /**
     * Turn whatever CrmApiClient handed back into raw PDF bytes.
     *
     * getRawContent() ends with `return base64_encode($response)` — it has
     * always base64-encoded what it fetches, because its first caller needed
     * base64 for the WhatsApp media API. Every checker written since has
     * compared the result against '%PDF' and concluded there was no PDF: a
     * base64 PDF begins "JVBERi0".
     *
     * uCRM was serving the document correctly the whole time. The plugin threw
     * it away and drew its own, and the customer received a quotation that
     * looked nothing like the template the operator had designed. The doctor
     * built to investigate that made the identical comparison and reported,
     * confidently, that this uCRM serves no quotation PDF at all.
     *
     * @return string raw PDF bytes, or '' if this is not a PDF at all
     */
    public static function toPdfBytes($value): string
    {
        if (!is_string($value) || $value === '') return '';
        if (strncmp($value, '%PDF', 4) === 0) return $value;

        // base64_decode with strict mode: anything that is not valid base64 is
        // not a mis-encoded PDF, it is something else entirely.
        $decoded = base64_decode($value, true);
        if (is_string($decoded) && strncmp($decoded, '%PDF', 4) === 0) return $decoded;

        return '';
    }


    /**
     * @return array{0:string,1:string}  [pdf bytes or '', 'ucrm'|'plugin'|'none']
     */
    /**
     * @param array $quote  the quote as the caller already has it, so its
     *                      status can be read without another round trip
     * @return array{0:string,1:string}  [pdf bytes or '', 'ucrm'|'plugin'|'none']
     */
    public static function fetch($crm, string $dataDir, array $config, int $quoteId,
                                 array $client = [], array $quote = []): array
    {
        try {
            if (!$quote) {
                $quote = $crm->get("billing/quotes/{$quoteId}") ?: $crm->get("quotes/{$quoteId}") ?: [];
            }

            // uCRM does not render a PDF for a DRAFT quote — every endpoint
            // spelling answers 404 — and every quote is created as a draft.
            // Fetching straight away therefore always failed, we fell back to
            // rendering our own, and the customer received a document that
            // looked nothing like the template the operator had designed in
            // uCRM. The WhatsApp path has always known this: approve, wait,
            // then fetch.
            //
            // Approving is not a liberty being taken here. cron_quote_wa and
            // the quote.add WhatsApp branch already move every draft to Open
            // for exactly this reason; this only stops doing it twice.
            $status = (int)($quote['status'] ?? 1);
            if ($status === 0) {
                try { $crm->patch("billing/quotes/{$quoteId}", ['status' => 1]); }
                catch (\Throwable $e) { /* the fetch below decides, not this */ }
            }

            // Generation is not instant. Poll rather than sleeping a flat five
            // seconds: usually the second attempt has it, and the webhook is
            // holding uCRM's connection open the whole time.
            $waits = $status === 0 ? [1, 2, 3] : [0, 2];
            foreach ($waits as $wait) {
                if ($wait > 0) sleep($wait);
                foreach (["quotes/{$quoteId}/pdf", "billing/quotes/{$quoteId}/pdf"] as $ep) {
                    $bytes = self::toPdfBytes($crm->getRawContent($ep));
                    if ($bytes !== '') return [$bytes, 'ucrm'];
                }
            }

            // Only now render our own — an unbranded document beats none, but
            // it is the fallback, not the plan.
            if (!$quote) return ['', 'none'];

            require_once __DIR__ . '/PluginQuotePdf.php';
            $gen = new PluginQuotePdf($dataDir, $config);
            $res = $gen->generate($quote, $client, [
                'name'   => (string)($quote['organizationName'] ?? ''),
                'tax_id' => (string)($quote['organizationTaxId'] ?? ''),
                'reg_no' => (string)($quote['organizationRegistrationNumber'] ?? ''),
                'street' => (string)($quote['organizationStreet1'] ?? ''),
                'city'   => (string)($quote['organizationCity'] ?? ''),
            ]);
            $path = (string)($res['pdf_path'] ?? '');
            if ($path === '' || !is_file($path)) return ['', 'none'];

            $bytes = self::toPdfBytes((string)@file_get_contents($path));
            return $bytes !== '' ? [$bytes, 'plugin'] : ['', 'none'];
        } catch (\Throwable $e) {
            error_log('[QuotePdfSource] ' . $e->getMessage());
            return ['', 'none'];
        }
    }
}
