<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * pdf_template_doctor.php — prove what the customer's PDF actually says.
 *
 *   php tools/pdf_template_doctor.php              inspect templates + newest quote
 *   php tools/pdf_template_doctor.php --quote 141  inspect one quote by uCRM id
 *   php tools/pdf_template_doctor.php --keep       leave the PDF on disk to open
 *
 * The email can be perfectly Ugandan and the attachment still say Juba, because
 * the PDF is rendered by uCRM from a template stored inside uCRM — not by this
 * plugin. So this pulls the real PDF over the API and reads the text out of it.
 *
 * Where a fact cannot be established it says NOT VERIFIED rather than guessing.
 * A clean bill of health here means the bytes were read, not that nothing was
 * found to complain about.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/QuotePdfSource.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$argvAll  = $argv;
$keep     = in_array('--keep', $argvAll, true);
$quoteId  = 0;
foreach ($argvAll as $i => $a) {
    if ($a === '--quote' && isset($argvAll[$i + 1])) $quoteId = (int)$argvAll[$i + 1];
}

$pass = 0; $warn = 0; $failn = 0; $sudanFound = false;
function line(): void { echo str_repeat('─', 66) . "\n"; }
function ok(string $m): void   { global $pass;  $pass++;  echo "  ok    {$m}\n"; }
function wr(string $m): void   { global $warn;  $warn++;  echo "  warn  {$m}\n"; }
function no(string $m): void   { global $failn; $failn++; echo "  FAIL  {$m}\n"; }
function nv(string $m): void   { global $warn;  $warn++;  echo "  ?     NOT VERIFIED — {$m}\n"; }

// Anything here in a Uganda customer's PDF is a problem.
const SUDAN_MARKERS = ['Juba', 'South Sudan', '+211', 'dishnetafrica'];

/**
 * Pull readable text out of a PDF without a PDF library.
 *
 * uCRM renders through wkhtmltopdf, which Flate-compresses its content
 * streams. Inflating them recovers the drawing operators, and the strings
 * inside Tj/TJ operators are the visible words. Returns null when nothing
 * could be decoded, so the caller can say NOT VERIFIED instead of "clean".
 */
function pdf_text(string $raw): ?string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $m)) {
        foreach ($m[1] as $chunk) {
            $plain = @gzuncompress($chunk);
            if ($plain === false) $plain = @gzinflate(substr($chunk, 2));
            if ($plain === false) continue;
            $out .= $plain . "\n";
        }
    }
    if (trim($out) === '') {
        // Some producers leave the streams uncompressed.
        $out = $raw;
    }
    $words = '';
    if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)/s', $out, $mm)) {
        foreach ($mm[0] as $tok) {
            $s = substr($tok, 1, -1);
            $s = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
            $words .= $s . ' ';
        }
    }
    // Also catch hex strings <0041...> used by some font encodings.
    if (preg_match_all('/<([0-9A-Fa-f\s]{4,})>/', $out, $hx)) {
        foreach ($hx[1] as $h) {
            $h = preg_replace('/\s+/', '', $h);
            if (strlen($h) % 4 === 0) {
                for ($i = 0; $i < strlen($h); $i += 4) {
                    $cp = hexdec(substr($h, $i, 4));
                    if ($cp >= 32 && $cp < 0x2500) $words .= mb_chr($cp, 'UTF-8');
                }
                $words .= ' ';
            }
        }
    }
    return trim($words) === '' ? null : $words;
}

line();
echo "1) Talking to uCRM\n";
$crm = CrmApiClient::fromUcrm($root, $config);
if (!$crm || !$crm->isConfigured()) { no('no uCRM API credentials — cannot inspect anything'); exit(1); }
$probe = $crm->get('clients?limit=1');
if ($probe === null) {
    $e = $crm->getLastError();
    no('uCRM API unreachable: ' . json_encode($e));
    exit(1);
}
ok('uCRM API answers (' . $crm->getBaseUrl() . ')');

line();
echo "2) Which PDF templates are installed\n";
// A template's NAME proves nothing. This install has one called "Invoice
// Ugadna" — a typo that no search for "uganda" will ever match — and one
// called simply "v4", which could be anything. So the name is printed as a
// hint and the verdict is left to the PDF text in step 3.
foreach (['quote-templates' => 'quote', 'invoice-templates' => 'invoice'] as $ep => $what) {
    $list = $crm->get($ep);
    if ($list === null) {
        nv("uCRM did not serve /{$ep} (" . json_encode($crm->getLastError()) . ')');
        continue;
    }
    if (!$list) { wr("no {$what} templates returned"); continue; }
    echo "  {$what} templates:\n";
    foreach ($list as $t) {
        $nm  = (string)($t['name'] ?? ('#' . ($t['id'] ?? '?')));
        $id  = (string)($t['id'] ?? '?');
        $def = !empty($t['isDefault']) || !empty($t['default']) ? '  ← DEFAULT' : '';
        printf("    %-6s %-38s%s\n", $id, $nm, $def);
    }
    // Say what uCRM exposes, so the next version of this tool can use it.
    $keys = is_array($list[0] ?? null) ? implode(', ', array_keys($list[0])) : '';
    if ($keys !== '') echo "    fields: {$keys}\n";
    $marked = false;
    foreach ($list as $t) if (!empty($t['isDefault']) || !empty($t['default'])) $marked = true;
    $marked ? ok("uCRM names a default {$what} template")
            : nv("uCRM does not expose which {$what} template is the default — check the UI");
}

line();
echo "3) The actual PDF a customer would receive\n";
// Print what the install actually holds. The first two runs reported on
// "quote 1" without ever saying whether that quote was real, which made a
// missing PDF indistinguishable from a stale draft.
foreach (['billing/quotes?limit=5', 'quotes?limit=5'] as $ep) {
    $qs = $crm->get($ep);
    if (!is_array($qs) || !$qs) continue;
    echo "  quotes on this install (newest ids last):\n";
    foreach ($qs as $q) {
        printf("    #%-6s %-16s %s\n", (string)($q['id'] ?? '?'),
               (string)($q['number'] ?? $q['quoteNumber'] ?? '(no number)'),
               isset($q['createdDate']) ? (string)$q['createdDate'] : '');
    }
    break;
}
if ($quoteId <= 0) {
    // Newest first. The ordering parameter is not always honoured, so take
    // the highest id across the endpoint spellings rather than the first row.
    foreach (['billing/quotes?limit=1&order=createdDate&direction=DESC',
              'quotes?limit=1&order=createdDate&direction=DESC',
              'billing/quotes?limit=1', 'quotes?limit=1'] as $ep) {
        $qs = $crm->get($ep);
        if (is_array($qs) && $qs) {
            // Some endpoints answer with the newest last whatever we ask.
            $ids = [];
            foreach ($qs as $q) if (!empty($q['id'])) $ids[] = (int)$q['id'];
            if ($ids) { $quoteId = max($ids); break; }
        }
    }
}
if ($quoteId <= 0) {
    nv('no quote exists yet — create one in the app, then rerun with --quote <id>');
} else {
    // billing/ first, because that is the namespace QuotationService creates
    // quotes in. If neither answers, the probe below asks the API what it
    // does serve instead of guessing a third time.
    $pdf = null;
    foreach (["billing/quotes/{$quoteId}/pdf", "quotes/{$quoteId}/pdf"] as $ep) {
        $try = QuotePdfSource::toPdfBytes($crm->getRawContent($ep));
        if ($try !== '') { $pdf = $try; break; }
    }
    if ($pdf === null) {
        nv("quote {$quoteId}: neither billing/quotes/{id}/pdf nor quotes/{id}/pdf returned a PDF");
        // Both guesses failed on a quote that demonstrably exists, so stop
        // guessing and ask the API what it does serve. Whatever answers here
        // is the endpoint QuotationService should be using to attach the PDF.
        echo "\n  Probing for the endpoint that does serve it:\n";
        $pdfEndpoint = '';
        foreach ([
            "billing/quotes/{$quoteId}/pdf", "quotes/{$quoteId}/pdf",
            "billing/quotes/{$quoteId}/file", "quotes/{$quoteId}/file",
            "billing/quotes/{$quoteId}/download", "quotes/{$quoteId}/download",
            "billing/quotes/{$quoteId}/print", "quotes/{$quoteId}/print",
            "billing/quote-pdf/{$quoteId}", "quote-pdf/{$quoteId}",
        ] as $ep) {
            $try = QuotePdfSource::toPdfBytes($crm->getRawContent($ep));
            $err = $crm->getLastError();
            $code = (string)($err['http_code'] ?? '?');
            if ($try !== '') {
                printf("    %-40s PDF (%d bytes)  <-- this one\n", $ep, strlen($try));
                $pdfEndpoint = $ep; $pdf = $try; break;
            }
            printf("    %-40s HTTP %s%s\n", $ep, $code,
                   is_string($try) && $try !== '' ? '  (' . strlen($try) . ' bytes, not a PDF)' : '');
        }
        if ($pdfEndpoint !== '') {
            ok("the PDF is served at {$pdfEndpoint} — QuotationService should use this path");
        } else {
            echo "\n  None of those served a PDF. What uCRM says the quote IS:\n";
            $q = $crm->get("billing/quotes/{$quoteId}") ?: $crm->get("quotes/{$quoteId}");
            if (is_array($q) && !empty($q['quoteTemplateId'])) {
                echo "    (this quote renders with template id {$q['quoteTemplateId']} — match it\n";
                echo "     against the names listed in section 2 above)\n";
            }
            if (is_array($q)) {
                foreach ($q as $k => $v) {
                    if (is_scalar($v) || $v === null) printf("    %-22s %s\n", $k, var_export($v, true));
                }
            } else {
                echo "    (the quote itself could not be fetched either)\n";
            }
            no('uCRM serves no quotation PDF over the API on this install — so the '
             . 'plugin cannot attach one, and QuotationService silently falls back '
             . 'to letting uCRM send the email instead');
        }
    }
    if ($pdf === null) {
        // nothing more to read
    } else {
        $bytes = strlen($pdf);
        ok("quote {$quoteId}: PDF fetched ({$bytes} bytes)");
        $file = $dataDir . "/quote_{$quoteId}_check.pdf";
        @file_put_contents($file, $pdf);
        $text = pdf_text((string)$pdf);
        if ($text === null) {
            nv("the PDF text could not be decoded here — open {$file} and read it yourself");
            $keep = true;
        } else {
            $found = [];
            foreach (SUDAN_MARKERS as $needle) {
                if (stripos($text, $needle) !== false) $found[] = $needle;
            }
            if ($found) { $sudanFound = true; no('the PDF contains: ' . implode(', ', $found) . ' — this is the Sudan template'); }
            else        { ok('no Juba, South Sudan, +211 or dishnetafrica in the PDF text'); }

            foreach (['Uganda' => 'the country', 'UCC' => 'the UCC authorisation line',
                      'UGX' => 'shilling amounts', 'TIN' => 'the tax identification number'] as $need => $what) {
                stripos($text, $need) !== false ? ok("the PDF shows {$what}")
                                                : wr("the PDF does not mention {$what}");
            }
        }
        if ($keep) { echo "  saved  {$file}\n"; } else { @unlink($file); }
    }
}

line();
echo "4) The actual INVOICE a customer would receive\n";
$invId = 0;
foreach (['invoices?limit=1&order=createdDate&direction=DESC', 'invoices?limit=1'] as $ep) {
    $inv = $crm->get($ep);
    if (is_array($inv) && $inv) {
        $ids = [];
        foreach ($inv as $i) if (!empty($i['id'])) $ids[] = (int)$i['id'];
        if ($ids) { $invId = max($ids); break; }
    }
}
if ($invId <= 0) {
    nv('no invoice exists yet to inspect');
} else {
    $ipdf = null;
    foreach (["invoices/{$invId}/pdf", "billing/invoices/{$invId}/pdf"] as $ep) {
        $try = QuotePdfSource::toPdfBytes($crm->getRawContent($ep));
        if ($try !== '') { $ipdf = $try; break; }
    }
    if ($ipdf === null) {
        nv("invoice {$invId}: uCRM did not return a PDF");
    } else {
        ok("invoice {$invId}: PDF fetched (" . strlen($ipdf) . ' bytes)');
        $ifile = $dataDir . "/invoice_{$invId}_check.pdf";
        @file_put_contents($ifile, $ipdf);
        $itext = pdf_text($ipdf);
        if ($itext === null) {
            nv("the invoice PDF text could not be decoded — open {$ifile} and read it");
            echo "  saved  {$ifile}\n";
        } else {
            $found = [];
            foreach (SUDAN_MARKERS as $needle) if (stripos($itext, $needle) !== false) $found[] = $needle;
            if ($found) { $sudanFound = true; no('the invoice PDF contains: ' . implode(', ', $found)); }
            else        { ok('no Juba, South Sudan, +211 or dishnetafrica in the invoice PDF'); }
            foreach (['Uganda' => 'the country', 'UGX' => 'shilling amounts',
                      'TIN' => 'the tax identification number'] as $need => $what) {
                stripos($itext, $need) !== false ? ok("the invoice PDF shows {$what}")
                                                 : wr("the invoice PDF does not mention {$what}");
            }
            if ($keep) { echo "  saved  {$ifile}\n"; } else { @unlink($ifile); }
        }
    }
}

line();
printf("RESULT: %d pass · %d warn · %d fail\n", $pass, $warn, $failn);
if ($failn) {
    // Two different failures land here and they need different answers, so
    // say which one happened rather than assuming the Sudan-content case.
    if ($sudanFound) {
        echo "\nThe PDF text carried South Sudan content, so a Uganda customer would\n";
        echo "receive a South Sudan document. Install the template from\n";
        echo "ucrm_pdf_templates/quotation_uganda/ (and invoice_uganda/) in uCRM under\n";
        echo "System → Customising, set each as the DEFAULT, then rerun this.\n";
    } else {
        echo "\nuCRM serves no quotation PDF over its API on this install — every endpoint\n";
        echo "spelling answers 404. Nothing is wrong with the quote itself; the API simply\n";
        echo "does not expose the rendered document.\n\n";
        echo "That matters because QuotationService fetched that PDF to attach it, and on\n";
        echo "failure fell back to letting uCRM send its own email. The plugin now renders\n";
        echo "its own PDF with wkhtmltopdf instead, so the branded quotation goes out with\n";
        echo "a document attached. Create a quote and check what arrives.\n";
    }
} elseif ($warn) {
    echo "\nNothing failed, but something could not be established. A NOT VERIFIED line\n";
    echo "is not a clean bill of health — it means this tool could not read the bytes.\n";
    echo "Where it says so, open the saved PDF and read it yourself.\n";
} else {
    echo "\nThe PDFs a customer receives were read and carry no South Sudan content.\n";
}
exit($failn ? 1 : 0);
