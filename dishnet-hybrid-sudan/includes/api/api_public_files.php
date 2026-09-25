<?php
// ═══════════════════════════════════════════════════════════════
// PUBLIC FILE SERVING (pre-auth, token-checked) — split out in 5.18.37
// ═══════════════════════════════════════════════════════════════
// The four PDF-serving actions that WhatsApp/Evolution and customers fetch
// by link, each guarded by its own token. They were the only actions in
// api_cron_debug.php that had to stay reachable without a login; everything
// else in that file now runs behind the staff guard.
//
// Receipt and delivery-note tokens: PdfLinkToken (a key of its own since
// 5.18.37). Quotation tokens: QuotePdfToken. Temp PDFs: the unguessable
// token recorded in the .meta file beside the PDF.

    // ── PUBLIC: Serve temp PDF files (no auth — WhatsML fetches these) ────
    // GET ?page=api&action=serve_temp_pdf&file=inv_12345_abc.pdf&token=HMAC
    // Files auto-expire after 10 minutes. Token prevents guessing.
    if ($act === 'serve_temp_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $tempDir = $dataDir . '/temp_pdf';
        $path    = $tempDir . '/' . $file;
        $metaPath = $path . '.meta';

        if (!file_exists($path) || !file_exists($metaPath)) $er2('File not found or expired', 404);

        // Verify HMAC token
        $meta = json_decode(file_get_contents($metaPath), true) ?: [];
        if (!hash_equals($meta['token'] ?? '', $token)) $er2('Invalid token', 403);

        // Check expiry (10 min)
        if (time() - (int)($meta['created'] ?? 0) > 600) {
            @unlink($path);
            @unlink($metaPath);
            $er2('File expired', 410);
        }

        // Serve the PDF
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);

        // Clean up after serving
        @unlink($path);
        @unlink($metaPath);
        exit;
    }

    // ── PUBLIC: Serve quotation PDF files (no auth — Evolution fetches these) ──
    // GET ?page=api&action=serve_quote_pdf&file=quote_123_abc.pdf&token=HMAC
    // The token is QuotePdfToken::mint(): a daily HMAC over the file name,
    // accepted for today and yesterday (UTC), then dead. The .meta file beside
    // the PDF is metadata only — the display name — and is never consulted for
    // authorization. Its stored token was accepted until 5.18.0; it never
    // rotated, so every quotation URL ever logged stayed fetchable for good.
    if ($act === 'serve_quote_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $pdfDir   = $dataDir . '/quote_pdfs';
        $path     = $pdfDir . '/' . $file;
        $metaPath = $path . '.meta';

        // Only the PDFs: the same directory holds the .meta files, which carry
        // the customer's name and the quote total.
        if (!preg_match('/\.pdf$/i', $file) || !file_exists($path)) $er2('Quote PDF not found', 404);

        require_once dirname(__DIR__, 2) . '/lib/QuotePdfToken.php';
        if (!QuotePdfToken::verify($file, $token, (array)$config)) $er2('Invalid or expired token', 403);

        $meta = file_exists($metaPath) ? (json_decode((string)file_get_contents($metaPath), true) ?: []) : [];

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        $dispName = ($meta['filename'] ?? null) ?: str_replace('_', '-', $file);
        header('Content-Disposition: inline; filename="' . $dispName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    // ── PUBLIC: Serve delivery acknowledgment PDF (no auth — WASender fetches these) ──
    // GET ?page=api&action=serve_delivery_pdf&file=DishNet_starlink_KYC-2026-0347.pdf&token=HMAC
    // Permanent storage — these are legal documents. Token uses daily HMAC.
    if ($act === 'serve_delivery_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $pdfDir = $dataDir . '/delivery_pdfs';
        $path   = $pdfDir . '/' . $file;

        if (!file_exists($path)) $er2('Delivery PDF not found', 404);

        // Verify token — 5.18.37: PdfLinkToken (a key of its own) for today or
        // yesterday, the .meta token recorded at minting, or — only while
        // webhook_secret is a real value — the pre-5.18.37 scheme, so links
        // already in customers' hands keep opening for their last day.
        $valid  = false;
        $metaPath = $path . '.meta';
        $meta = [];
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true) ?: [];
            if (hash_equals((string)($meta['token'] ?? ''), $token)) $valid = true;
        }
        if (!$valid && PdfLinkToken::verify($file, $token, (array)$config)) $valid = true;
        if (!$valid && PdfLinkToken::legacyVerify($file, $token, (array)$config)) $valid = true;
        if (!$valid) $er2('Invalid token', 403);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        $dispName = ($meta['filename'] ?? null) ?: str_replace('_', '-', $file);
        header('Content-Disposition: inline; filename="' . $dispName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=604800'); // 7 days — legal docs don't change
        readfile($path);
        exit;
    }

    // ── PUBLIC: Serve payment receipt PDF (no auth — WASender fetches these) ──
    // GET ?page=api&action=serve_receipt_pdf&file=DishNet-Receipt-8386.pdf&token=HMAC
    if ($act === 'serve_receipt_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $pdfDir = $dataDir . '/receipt_pdfs';
        $path   = $pdfDir . '/' . $file;

        if (!file_exists($path)) $er2('Receipt PDF not found', 404);

        // 5.18.37: PdfLinkToken (own key), the minted .meta token, or the old
        // scheme only while webhook_secret is a real value (see serve_delivery_pdf).
        $valid  = false;
        $metaPath = $path . '.meta';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true) ?: [];
            if (hash_equals((string)($meta['token'] ?? ''), $token)) $valid = true;
        }
        if (!$valid && PdfLinkToken::verify($file, $token, (array)$config)) $valid = true;
        if (!$valid && PdfLinkToken::legacyVerify($file, $token, (array)$config)) $valid = true;
        if (!$valid) $er2('Invalid token', 403);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }
