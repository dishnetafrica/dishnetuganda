<?php
declare(strict_types=1);
/**
 * test_efris_pdf_link.php — 5.18.95 (A2/D1): an EFRIS fiscal-invoice PDF link is signed with the install's own
 * pdf_link_secret (PdfLinkToken), the key receipt and delivery links already use, and nothing else opens it.
 *
 * Proved over HTTP on the sandbox, with an invented PDF; nothing leaves the machine:
 *   1. a link minted by PdfLinkToken under the store copy's key opens the PDF, byte for byte, as application/pdf;
 *      yesterday's token still opens it (PdfLinkToken's two-day window);
 *   2. a token of the old scheme (the day HMAC under webhook_secret) no longer opens it; a wrong token, an empty one,
 *      and a missing file all get the same 404;
 *   3. control: the live code (f4b4c96) opens the PDF with the old-scheme token, so check 2 can fail; skipped, never
 *      passed, when Git cannot supply that tree;
 *   4. a weakened copy that skips the token check is caught.
 *
 *   php test_efris_pdf_link.php [--root=DIR] [--no-control] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-control', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';
require_once dirname(__DIR__) . '/lib/PdfLinkToken.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const PDF_NAME = 'einv_RH0000001.pdf';
const PDF_BYTES = "%PDF-1.4\n% RH-Client One invented test invoice\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
/** Start a sandbox on $tree with the invented PDF in data/efris_pdf/, and one request made so the link key exists. */
$open = function (string $tree, string $tag): SjSandbox {
    $s = SjSandbox::start($tree, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], $tag);
    @mkdir($s->data . '/efris_pdf', 0700, true);
    file_put_contents($s->data . '/efris_pdf/' . PDF_NAME, PDF_BYTES);
    $s->http('GET', "{$s->base}?page=login");
    return $s;
};
$get = function (SjSandbox $s, string $file, string $token): array {
    $ch = curl_init("{$s->base}?page=efris_pdf&file=" . rawurlencode($file) . '&token=' . rawurlencode($token));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60]);
    $body = (string)curl_exec($ch);
    $r = [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    curl_close($ch);
    return $r;
};
/** The old scheme's token: the day HMAC of the file name under the sandbox's configured webhook_secret. */
$oldToken = function (SjSandbox $s, string $file): string {
    return hash_hmac('sha256', $file . gmdate('Ymd'), (string)$s->cfg['webhook_secret']);
};
$storeCfg = function (SjSandbox $s): array {
    return (array)$s->store()->load('kyc_config.json');   // the store copy, as the server keeps it
};

$s = $open($root, 'sjefris');
$cfg = $storeCfg($s);
is_(PdfLinkToken::secret($cfg) !== '', 'the install has its own link key in the store copy (control)');

echo "1. A link signed with the install's key\n";
$r = $get($s, PDF_NAME, PdfLinkToken::mint(PDF_NAME, $cfg));
is_($r[0] === 200 && $r[1] === PDF_BYTES, 'opens the PDF byte for byte', $r[0] . ' ' . strlen($r[1]));
is_(strpos($r[2], 'application/pdf') === 0, 'as application/pdf', $r[2]);
$r = $get($s, PDF_NAME, PdfLinkToken::mint(PDF_NAME, $cfg, time() - 86400));
is_($r[0] === 200 && $r[1] === PDF_BYTES, "yesterday's token still opens it", (string)$r[0]);

echo "\n2. Nothing else opens it, and every refusal looks the same\n";
$r = $get($s, PDF_NAME, $oldToken($s, PDF_NAME));
is_($r[0] === 404 && $r[1] !== PDF_BYTES, 'the old-scheme token: 404', (string)$r[0]);
$r = $get($s, PDF_NAME, str_repeat('0', 64));
is_($r[0] === 404 && $r[1] === 'Not found', 'a wrong token: 404 Not found', $r[0] . ' ' . $r[1]);
$r = $get($s, PDF_NAME, '');
is_($r[0] === 404 && $r[1] === 'Not found', 'an empty token: 404 Not found', $r[0] . ' ' . $r[1]);
$r = $get($s, 'einv_RH0000002.pdf', PdfLinkToken::mint('einv_RH0000002.pdf', $cfg));
is_($r[0] === 404 && $r[1] === 'Not found', 'a missing file with a good token: the same 404', $r[0] . ' ' . $r[1]);
$s->stop();

echo "\n3. Control: the live code\n";
if (isset($opt['no-control'])) { $skip++; echo "  skip (--no-control)\n"; }
else {
    [$base, $why] = sj_baseline_tree('f4b4c96');
    if ($base === null) { $skip++; echo "  skip {$why}\n"; }
    else {
        $b = $open($base, 'sjefrisb');
        $r = $get($b, PDF_NAME, $oldToken($b, PDF_NAME));
        is_($r[0] === 200 && $r[1] === PDF_BYTES, 'f4b4c96: the old-scheme token opens the PDF (the scheme 5.18.95 retires)', (string)$r[0]);
        $b->stop();
    }
}

if (!isset($opt['no-mutants'])) {
    echo "\n4. Weakened copy, caught\n";
    [$tmp, $n] = sj_weakened_copy($root, 'efris_pdf.php', 'if (!PdfLinkToken::verify($file, $token, $linkCfg) || !is_file($path)) {', 'if (!is_file($path)) {');
    if ($n !== 1) is_(false, 'weakened copy: no token check', "anchor found {$n} times");
    else {
        $out = []; $rc = 0;
        exec(sprintf('%s %s --root=%s --no-control --no-mutants 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($tmp)), $out, $rc);
        $fails = count(preg_grep('/^\s+FAIL /', $out));
        is_($rc !== 0 && $fails > 0, "weakened copy caught: no token check ({$fails} checks failed)", 'rc=' . $rc);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\n{$pass} passed, {$fail} failed, {$skip} skipped\n";
exit($fail === 0 ? 0 : 1);
