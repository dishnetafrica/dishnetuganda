<?php
declare(strict_types=1);

require_once __DIR__ . '/crm_url.php';

/**
 * EfrisInvoicePdf — the fiscal e-invoice document, rendered by the SAME
 * Chromium PDF service the quotation PDF already uses (pdf_service_url).
 * uCRM's native invoice PDF is untouched; this document exists because a
 * plugin cannot inject fiscal data into uCRM's own renderer.
 *
 * Labelling rules (enforced in buildHtml, tested in tests/test_efris_pdf):
 *   - no FISCALISED transaction  → "EFRIS STATUS: NOT FISCALISED"
 *   - fiscalised in TEST         → "TEST FISCALISED — SIMULATED … NOT AN
 *     OFFICIAL URA FISCAL DOCUMENT" (never presented as official)
 *   - fiscalised in PRODUCTION   → "EFRIS FISCALISED" with the FDN,
 *     verification code and QR data EXACTLY as URA returned them
 *     (unreachable in Phase 1: the production connector refuses to run).
 * Every fiscal value printed comes from the stored efris_transactions row —
 * this class cannot invent one because it never composes fiscal data.
 */
class EfrisInvoicePdf
{
    private string $dataDir;
    private array  $config;

    public function __construct(string $dataDir, array $config = [])
    {
        $this->dataDir = $dataDir;
        $this->config  = $config;
    }

    /** Pure HTML build — public so tests cover labelling without a PDF service. */
    public function buildHtml(array $model, ?array $tx): string
    {
        $inv    = $model['invoice'] ?? [];
        $seller = $model['seller'] ?? [];
        $buyer  = $model['buyer'] ?? [];
        $items  = $model['items'] ?? [];
        $totals = $model['totals'] ?? [];
        $cur    = htmlspecialchars((string)($inv['currency'] ?? ''));
        $money  = function ($n) use ($cur): string {
            return $cur . ' ' . number_format((float)$n, 2);
        };
        $h = 'htmlspecialchars';

        // ── Items rows ──
        $rows = ''; $n = 1;
        foreach ($items as $it) {
            $bg = ($n % 2 === 0) ? 'background:#fafafa;' : '';
            $taxCell = '—';
            if (!empty($it['tax'])) {
                $rate = $it['tax']['rate'];
                $taxCell = ($rate !== null ? rtrim(rtrim(number_format((float)$rate, 2), '0'), '.') . '%' : '')
                         . ' ' . $money($it['tax']['amount'] ?? 0);
            }
            $cat = $it['tax_category'] !== null
                ? '<br><span style="font-size:8px;color:#9B9B9B;text-transform:uppercase;">' . $h((string)$it['tax_category']) . '</span>' : '';
            $rows .= '<tr style="' . $bg . '">'
                . '<td style="padding:8px 10px;font-size:10px;border-bottom:1px solid #f0f0f0;color:#9B9B9B;">' . $n . '</td>'
                . '<td style="padding:8px 10px;font-size:10px;border-bottom:1px solid #f0f0f0;"><strong>' . $h((string)$it['label']) . '</strong>' . $cat . '</td>'
                . '<td style="padding:8px 10px;font-size:10px;border-bottom:1px solid #f0f0f0;text-align:right;">' . $h((string)$it['qty']) . '</td>'
                . '<td style="padding:8px 10px;font-size:10px;border-bottom:1px solid #f0f0f0;text-align:right;">' . $money($it['unit_price']) . '</td>'
                . '<td style="padding:8px 10px;font-size:10px;border-bottom:1px solid #f0f0f0;text-align:right;">' . trim($taxCell) . '</td>'
                . '<td style="padding:8px 10px;font-size:10px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:700;">' . $money($it['line_total']) . '</td>'
                . '</tr>';
            $n++;
        }

        // ── EFRIS banner + fiscal block ──
        $status = is_array($tx) ? (string)($tx['status'] ?? '') : '';
        $env    = is_array($tx) ? (string)($tx['environment'] ?? '') : '';
        if ($status === 'FISCALISED' && $env === 'production') {
            $banner = '<div style="background:#065f46;color:#fff;padding:10px 14px;font-size:12px;font-weight:800;letter-spacing:1px;">EFRIS FISCALISED</div>';
            $fiscal = $this->fiscalRows($tx, false);
        } elseif ($status === 'FISCALISED') {
            $banner = '<div style="background:#b45309;color:#fff;padding:10px 14px;font-size:12px;font-weight:800;letter-spacing:1px;">'
                    . 'EFRIS STATUS: TEST FISCALISED — SIMULATED VALUES, NOT AN OFFICIAL URA FISCAL DOCUMENT</div>';
            $fiscal = $this->fiscalRows($tx, true);
        } elseif ($status === 'NEEDS_ADJUSTMENT') {
            $banner = '<div style="background:#991b1b;color:#fff;padding:10px 14px;font-size:12px;font-weight:800;letter-spacing:1px;">'
                    . 'EFRIS: INVOICE CHANGED AFTER FISCALISATION — CREDIT/DEBIT NOTE REQUIRED</div>';
            $fiscal = $this->fiscalRows($tx, $env !== 'production');
        } else {
            $banner = '<div style="background:#374151;color:#fff;padding:10px 14px;font-size:12px;font-weight:800;letter-spacing:1px;">'
                    . 'EFRIS STATUS: NOT FISCALISED</div>';
            $fiscal = '<div style="font-size:9px;color:#6B6B6B;padding:8px 14px;">This document is not a fiscal invoice. '
                    . 'It becomes one only when URA EFRIS confirms fiscalisation.</div>';
        }

        $payLabel = ['paid' => 'PAID', 'partial' => 'PARTIALLY PAID', 'unpaid' => 'UNPAID'][(string)($inv['payment_status'] ?? '')] ?? '';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
@page{margin:0;size:A4}
body{font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#141414;line-height:1.45;margin:0;padding:0}
*{box-sizing:border-box}table{border-collapse:collapse}
</style></head><body>'
        . '<table style="width:100%;background:#141414;" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding:18px 28px 14px;">'
        . '<span style="font-size:28px;font-weight:900;color:#fff;">DishNet</span>'
        . '<div style="height:3px;width:115px;background:linear-gradient(to right,#D41C1C,#E8521A,#FF7A35);border-radius:2px;margin-top:2px;"></div>'
        . '</td><td style="padding:18px 28px 14px;text-align:right;color:#fff;">'
        . '<div style="font-size:16px;font-weight:800;">E-INVOICE ' . $h((string)($inv['number'] ?? '')) . '</div>'
        . '<div style="font-size:10px;opacity:.8;">Issued ' . $h((string)($inv['issued_date'] ?? '')) . '</div>'
        . ($payLabel !== '' ? '<div style="font-size:10px;font-weight:800;margin-top:2px;">' . $payLabel . '</div>' : '')
        . '</td></tr></table>'
        . $banner
        . '<table style="width:100%;" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="width:50%;padding:14px 28px;vertical-align:top;">'
        . '<div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#9B9B9B;font-weight:700;">Seller</div>'
        . '<div style="font-weight:800;">' . $h((string)($seller['legal_name'] ?? '')) . '</div>'
        . '<div style="font-size:10px;color:#6B6B6B;">TIN: ' . $h((string)($seller['tin'] ?? '')) . '</div>'
        . '<div style="font-size:10px;color:#6B6B6B;">' . $h((string)($seller['address'] ?? '')) . '</div>'
        . '<div style="font-size:10px;color:#6B6B6B;">' . $h((string)($seller['phone'] ?? '')) . ' · ' . $h((string)($seller['email'] ?? '')) . '</div>'
        . '</td><td style="width:50%;padding:14px 28px;vertical-align:top;">'
        . '<div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#9B9B9B;font-weight:700;">Buyer</div>'
        . '<div style="font-weight:800;">' . $h((string)($buyer['name'] ?? '')) . '</div>'
        . ($buyer['tin'] !== '' ? '<div style="font-size:10px;color:#6B6B6B;">TIN: ' . $h((string)$buyer['tin']) . '</div>' : '')
        . '<div style="font-size:10px;color:#6B6B6B;">' . $h((string)($buyer['address'] ?? '')) . '</div>'
        . '<div style="font-size:10px;color:#6B6B6B;">' . $h((string)($buyer['phone'] ?? '')) . ' ' . $h((string)($buyer['email'] ?? '')) . '</div>'
        . '</td></tr></table>'
        . '<table style="width:100%;margin-top:4px;" cellpadding="0" cellspacing="0">'
        . '<tr style="background:#f6f6f6;">'
        . '<th style="padding:7px 10px;font-size:8px;text-align:left;text-transform:uppercase;letter-spacing:1px;color:#6B6B6B;">#</th>'
        . '<th style="padding:7px 10px;font-size:8px;text-align:left;text-transform:uppercase;letter-spacing:1px;color:#6B6B6B;">Item</th>'
        . '<th style="padding:7px 10px;font-size:8px;text-align:right;text-transform:uppercase;letter-spacing:1px;color:#6B6B6B;">Qty</th>'
        . '<th style="padding:7px 10px;font-size:8px;text-align:right;text-transform:uppercase;letter-spacing:1px;color:#6B6B6B;">Unit</th>'
        . '<th style="padding:7px 10px;font-size:8px;text-align:right;text-transform:uppercase;letter-spacing:1px;color:#6B6B6B;">VAT</th>'
        . '<th style="padding:7px 10px;font-size:8px;text-align:right;text-transform:uppercase;letter-spacing:1px;color:#6B6B6B;">Total</th>'
        . '</tr>' . $rows . '</table>'
        . '<table style="width:42%;margin-left:58%;margin-top:8px;" cellpadding="0" cellspacing="0">'
        . '<tr><td style="padding:5px 14px;font-size:10px;color:#6B6B6B;">Subtotal</td>'
        . '<td style="padding:5px 14px;font-size:10px;text-align:right;font-weight:700;">' . $money($totals['subtotal'] ?? 0) . '</td></tr>'
        . '<tr><td style="padding:5px 14px;font-size:10px;color:#6B6B6B;">VAT</td>'
        . '<td style="padding:5px 14px;font-size:10px;text-align:right;font-weight:700;">' . $money($totals['tax_total'] ?? 0) . '</td></tr>'
        . '<tr><td style="padding:8px 14px;font-size:13px;font-weight:800;color:#fff;background:#141414;">Total</td>'
        . '<td style="padding:8px 14px;font-size:13px;font-weight:800;text-align:right;color:#fff;background:#141414;">' . $money($totals['grand'] ?? 0) . '</td></tr>'
        . '</table>'
        . '<div style="margin:16px 28px;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">' . $fiscal . '</div>'
        . '<div style="margin:0 28px 20px;font-size:8px;color:#9B9B9B;">'
        . $h((string)($seller['legal_name'] ?? '')) . ' · TIN ' . $h((string)($seller['tin'] ?? ''))
        . ' · Generated ' . gmdate('Y-m-d H:i') . ' UTC</div>'
        . '</body></html>';
    }

    private function fiscalRows(array $tx, bool $isTest): string
    {
        $h = 'htmlspecialchars';
        $tag = $isTest
            ? '<span style="background:#fef3c7;color:#92400e;font-size:8px;font-weight:800;padding:2px 6px;border-radius:3px;margin-left:8px;">TEST VALUE — NOT ISSUED BY URA</span>'
            : '';
        $row = function (string $label, string $value) use ($h, $tag): string {
            if ($value === '') return '';
            return '<div style="padding:6px 14px;border-bottom:1px solid #f0f0f0;font-size:10px;">'
                 . '<span style="color:#6B6B6B;display:inline-block;width:160px;">' . $label . '</span>'
                 . '<strong>' . $h($value) . '</strong>' . $tag . '</div>';
        };
        return $row('Fiscal Document Number', (string)($tx['fdn'] ?? ''))
             . $row('Verification Code', (string)($tx['verification_code'] ?? ''))
             . $row('EFRIS Reference', (string)($tx['efris_reference'] ?? ''))
             . $row('Fiscalised At', (string)($tx['fiscalised_at'] ?? ''))
             . $row('QR Data', (string)($tx['qr_data'] ?? ''));
        // The scannable QR image itself is Phase-2 work: it is rendered from
        // the QR payload URA returns, in the format the official spec defines.
    }

    /** Render to PDF via the same Chromium service the quote PDF uses. */
    public function generate(array $model, ?array $tx): array
    {
        $html = $this->buildHtml($model, $tx);
        $invNum = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($model['invoice']['number'] ?? 'invoice'));

        $dir = $this->dataDir . '/efris_pdf';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $htmlFile = $dir . '/einv_' . $invNum . '.html';
        $pdfFile  = $dir . '/einv_' . $invNum . '.pdf';
        file_put_contents($htmlFile, $html);

        $pdfServiceUrl = $this->config['pdf_service_url']
            ?? 'https://n8n-dishnet-pdf-service.4zz82b.easypanel.host/forms/chromium/convert/html';
        $cfile = new \CURLFile($htmlFile, 'text/html', 'index.html');
        $ch = curl_init($pdfServiceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['files' => $cfile],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $pdf  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        @unlink($htmlFile);

        if ($http !== 200 || !$pdf || strlen($pdf) < 100) {
            return ['error' => "PDF service failed: HTTP {$http} {$err}"];
        }
        file_put_contents($pdfFile, $pdf);
        return ['pdf_path' => $pdfFile, 'filename' => 'E-Invoice-' . $invNum . '.pdf'];
    }
}
