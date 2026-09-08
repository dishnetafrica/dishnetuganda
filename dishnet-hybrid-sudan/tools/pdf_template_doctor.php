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

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$argvAll  = $argv;
$keep     = in_array('--keep', $argvAll, true);
$quoteId  = 0;
foreach ($argvAll as $i => $a) {
    if ($a === '--quote' && isset($argvAll[$i + 1])) $quoteId = (int)$argvAll[$i + 1];
}

$pass = 0; $warn = 0; $failn = 0;
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
foreach (['quote-templates' => 'quote', 'invoice-templates' => 'invoice'] as $ep => $what) {
    $list = $crm->get($ep);
    if ($list === null) {
        nv("uCRM did not serve /{$ep} (" . json_encode($crm->getLastError()) . ") — check the template in the uCRM UI instead");
        continue;
    }
    if (!$list) { wr("no {$what} templates returned"); continue; }
    echo "  {$what} templates:\n";
    foreach ($list as $t) {
        $nm = (string)($t['name'] ?? ('#' . ($t['id'] ?? '?')));
        $id = (string)($t['id'] ?? '?');
        $ug = stripos($nm, 'uganda') !== false;
        printf("    %-6s %-38s %s\n", $id, $nm, $ug ? '← Uganda' : '');
    }
    $hasUg = false;
    foreach ($list as $t) if (stripos((string)($t['name'] ?? ''), 'uganda') !== false) $hasUg = true;
    $hasUg ? ok("a Uganda {$what} template is installed")
           : no("NO Uganda {$what} template is installed — uCRM will render the Sudan one");
}

line();
echo "3) The actual PDF a customer would receive\n";
if ($quoteId <= 0) {
    $quotes = $crm->get('quotes?limit=1&order=createdDate&direction=DESC');
    if (!$quotes) $quotes = $crm->get('quotes?limit=1');
    if (is_array($quotes) && $quotes) $quoteId = (int)($quotes[0]['id'] ?? 0);
}
if ($quoteId <= 0) {
    nv('no quote exists yet — create one, then rerun with --quote <id>');
} else {
    $pdf = $crm->getRawContent("quotes/{$quoteId}/pdf");
    if ($pdf === null || strncmp((string)$pdf, '%PDF', 4) !== 0) {
        nv("quote {$quoteId}: uCRM did not return a PDF (" . json_encode($crm->getLastError()) . ')');
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
            $found ? no('the PDF contains: ' . implode(', ', $found) . ' — this is the Sudan template')
                   : ok('no Juba, South Sudan, +211 or dishnetafrica in the PDF text');

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
printf("RESULT: %d pass · %d warn · %d fail\n", $pass, $warn, $failn);
if ($failn) {
    echo "\nA failure above means a Uganda customer would receive a South Sudan\n";
    echo "document. Install the template from ucrm_pdf_templates/quotation_uganda/\n";
    echo "in uCRM (System → Customising → Quote templates), set it as the default,\n";
    echo "then rerun this.\n";
}
exit($failn ? 1 : 0);
