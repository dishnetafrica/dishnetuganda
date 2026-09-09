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
     * @return array{0:string,1:string}  [pdf bytes or '', 'ucrm'|'plugin'|'none']
     */
    public static function fetch($crm, string $dataDir, array $config, int $quoteId, array $client = []): array
    {
        try {
            foreach (["billing/quotes/{$quoteId}/pdf", "quotes/{$quoteId}/pdf"] as $ep) {
                $try = $crm->getRawContent($ep);
                if (is_string($try) && strncmp($try, '%PDF', 4) === 0) return [$try, 'ucrm'];
            }

            $quote = $crm->get("billing/quotes/{$quoteId}") ?: $crm->get("quotes/{$quoteId}");
            if (!is_array($quote) || !$quote) return ['', 'none'];

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

            $bytes = (string)@file_get_contents($path);
            return strncmp($bytes, '%PDF', 4) === 0 ? [$bytes, 'plugin'] : ['', 'none'];
        } catch (\Throwable $e) {
            error_log('[QuotePdfSource] ' . $e->getMessage());
            return ['', 'none'];
        }
    }
}
