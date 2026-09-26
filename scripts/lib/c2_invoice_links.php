<?php
// c2_invoice_links.php — READ-ONLY. `scripts/dnb-c2-check.sh --links` pipes this into the ucrm container, with the
// plugin's directory as the working directory:  php -- <client id>
//
// The two :8443 links inside the invoice PDF are the PAY NOW box of the Uganda invoice template, which prints
// uCRM's invoice.onlinePaymentLink twice — the button and the address under it (docs/38 §7.2). uCRM builds that link
// on the port UISP was installed with, and UISP 3.0.159 has no setting for it (the C-2 finding, 26 Sep 21:01).
//
// For the controlled customer's newest UNPAID invoice (the box is drawn on unpaid invoices only) this reports the
// links inside its PDF, every token masked (path segments that could carry one become {token} or {n}, query values
// {v}), the template uCRM names for that invoice, and the invoice templates uCRM has. It writes nothing, anywhere,
// and prints no app key, no token, no query value, no e-mail address or phone number.
//
// Lines starting "@@" are for the calling script, which never prints them:
//   "@@FETCH <url>"  uCRM's own payment page with :8443 removed — the calling script opens it on the HOST, through
//                    the public address a customer uses, and prints only each hop's status and the options it names;
//   "@@ <links on :8443> <other links> <ok|noinvoice|nopdf|noconfig>"  — always the last line.
declare(strict_types=1);
error_reporting(0);

$clientId = (int)($argv[1] ?? 1);

/** A URL with nothing in it that could open anything: host and port kept, token-like segments and values masked. */
function c2_mask_url(string $u): string
{
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host'])) return '{unreadable link}';
    $o = strtolower((string)($p['scheme'] ?? 'https')) . '://' . strtolower((string)$p['host'])
       . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    foreach (explode('/', (string)($p['path'] ?? '')) as $s) {
        if ($s === '') continue;
        if (preg_match('/^\d+$/', $s))                                         $o .= '/{n}';
        elseif (strlen($s) > 24 || (strlen($s) >= 8 && preg_match('/\d/', $s))) $o .= '/{token}';
        else                                                                   $o .= '/' . preg_replace('/[^A-Za-z0-9._-]/', '?', $s);
    }
    if (isset($p['query']) && $p['query'] !== '') {
        $k = [];
        foreach (explode('&', (string)$p['query']) as $kv) {
            $k[] = preg_replace('/[^A-Za-z0-9._\[\]-]/', '?', explode('=', $kv, 2)[0]) . '={v}';
        }
        $o .= '?' . implode('&', $k);
    }
    if (isset($p['fragment'])) $o .= '#{v}';
    return $o;
}

/** Free text from uCRM (a template's name): no e-mail address, no phone-like digits, one short line. */
function c2_mask_text(string $s): string
{
    $s = (string)preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '{e-mail}', $s);
    $s = (string)preg_replace('/\+?\d[\d\s-]{6,}\d/', '{digits}', $s);
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    return strlen($s) > 60 ? substr($s, 0, 57) . '...' : $s;
}

/** A phone number shown only as its country code and last three digits: enough to tell +256 from +211. */
function c2_mask_phone(string $p): string
{
    $d = (string)preg_replace('/\D/', '', $p);
    if ($d === '') return 'not set';
    return ((strpos(trim($p), '+') === 0 || strlen($d) > 10) ? '+' . substr($d, 0, 3) . ' ' : '') . '… ' . substr($d, -3);
}

/** An e-mail address shown only as its domain. */
function c2_mask_email(string $e): string
{
    $at = strrchr($e, '@');
    return ($at === false || strlen($at) < 2) ? 'not set' : '…' . $at;
}

/** Every http(s) URL inside a PDF: the raw bytes and each stream uCRM's renderer compressed. */
function c2_pdf_urls(string $pdf): array
{
    $t = [$pdf];
    if (preg_match_all("/stream\r?\n(.*?)\r?\nendstream/s", $pdf, $m)) {
        foreach ($m[1] as $s) {
            $u = @gzuncompress($s);
            if ($u === false) $u = @gzinflate($s);
            if ($u === false && strlen($s) > 2) $u = @gzinflate(substr($s, 2));
            if ($u !== false) $t[] = $u;
        }
    }
    $urls = [];
    foreach ($t as $x) {
        if (preg_match_all('#https?://[A-Za-z0-9.-]+(?::[0-9]+)?[^\s()<>"\'\\\\]*#', $x, $mm)) {
            foreach ($mm[0] as $u) $urls[$u] = ($urls[$u] ?? 0) + 1;
        }
    }
    return $urls;
}

function out(string $label, string $value): void { printf("  %-26s %s\n", $label, $value); }

require 'lib/CrmApiClient.php';
$crm = CrmApiClient::fromUcrm(getcwd(), []);
if (!$crm->isConfigured()) { echo "  note  uCRM is not configured for the plugin — nothing could be read\n@@ 0 0 noconfig\n"; exit; }

// The invoice: the controlled customer's newest unpaid one (status 1 unpaid, 2 partly paid).
$rows = $crm->get("invoices?clientId={$clientId}&statuses[]=1&statuses[]=2&limit=50");
if (!is_array($rows)) $rows = $crm->get("billing/invoices?clientId={$clientId}&statuses[]=1&statuses[]=2&limit=50");
$inv = null; $newest = 0;
foreach (is_array($rows) ? $rows : [] as $r) {
    if (is_array($r) && (int)($r['id'] ?? 0) > $newest) { $inv = $r; $newest = (int)$r['id']; }
}
echo "\n== The links inside client #{$clientId}'s newest unpaid invoice (read-only) ==\n";
if ($inv === null) {
    echo "  note  client #{$clientId} has no unpaid invoice. The PAY NOW box is drawn on unpaid invoices only, so there is\n";
    echo "        nothing to read. Run again with CLIENT_ID=<a client with an unpaid invoice>.\n";
    echo "@@ 0 0 noinvoice\n"; exit;
}
$num   = (string)($inv['number'] ?? $inv['id']);
$state = ((int)($inv['status'] ?? 0) === 2) ? 'partly paid' : 'unpaid';
$made  = substr((string)($inv['createdDate'] ?? ''), 0, 10);

// The template uCRM names for it, and the ones it has.
$tplName = [];
$tpls = $crm->get('invoice-templates');
foreach (is_array($tpls) ? $tpls : [] as $tp) {
    if (is_array($tp) && isset($tp['id'])) $tplName[(int)$tp['id']] = c2_mask_text((string)($tp['name'] ?? ''));
}
$tplId = array_key_exists('invoiceTemplateId', $inv) ? $inv['invoiceTemplateId'] : 'absent';
if ($tplId === 'absent')  $tplSays = 'uCRM does not say which template';
elseif ($tplId === null)  $tplSays = "uCRM's default template (the invoice names none)";
else                      $tplSays = 'template #' . (int)$tplId . (isset($tplName[(int)$tplId]) ? ' "' . $tplName[(int)$tplId] . '"' : '');
out('invoice', "{$num} · {$state} · created {$made} · {$tplSays}");
if ($tplName) {
    $l = []; foreach ($tplName as $id => $nm) $l[] = "#{$id} \"{$nm}\"";
    out("uCRM's invoice templates", implode(', ', $l));
} else {
    out("uCRM's invoice templates", 'not served by the API');
}

// The organization the invoice belongs to: a Uganda template prints its address, phone, e-mail and website, so a
// record still carrying another country's details would reappear there. Phone and e-mail are masked.
$orgId = (int)($inv['organizationId'] ?? 0);
if ($orgId === 0) { $cl = $crm->get("clients/{$clientId}"); $orgId = is_array($cl) ? (int)($cl['organizationId'] ?? 0) : 0; }
$orgs = $crm->get('organizations'); $org = null;
foreach (is_array($orgs) ? $orgs : [] as $o) {
    if (is_array($o) && ($orgId > 0 ? (int)($o['id'] ?? 0) === $orgId : !empty($o['selected']))) { $org = $o; break; }
}
if ($org === null && is_array($orgs) && count($orgs) === 1 && is_array(reset($orgs))) $org = reset($orgs);
if ($org === null) {
    out('its organization', 'not served by the API');
} else {
    $where = array_filter([c2_mask_text((string)($org['street1'] ?? '')), c2_mask_text((string)($org['city'] ?? ''))], 'strlen');
    out('its organization', c2_mask_text((string)($org['name'] ?? '')) . ' · ' . ($where ? implode(', ', $where) : 'no address')
        . ' · phone ' . c2_mask_phone((string)($org['phone'] ?? '')) . ' · e-mail ' . c2_mask_email((string)($org['email'] ?? '')));
    out('', 'website ' . (c2_mask_text((string)($org['website'] ?? '')) ?: 'not set')
        . ' · TIN ' . (preg_replace('/[^0-9A-Za-z-]/', '', (string)($org['taxId'] ?? '')) ?: 'not set in uCRM')
        . ' · Reg. No ' . (preg_replace('/[^0-9A-Za-z-]/', '', (string)($org['registrationNumber'] ?? '')) ?: 'not set in uCRM'));
}

$raw = $crm->getRawContent('invoices/' . (int)$inv['id'] . '/pdf');
if (!$raw) { echo "  note  uCRM served no PDF for invoice {$num}\n@@ 0 0 nopdf\n"; exit; }
$urls = c2_pdf_urls((string)base64_decode($raw));
$byMask = []; $on8443 = 0; $other = 0; $own = '';
foreach ($urls as $u => $n) {
    if (parse_url($u, PHP_URL_PORT) === 8443) { $on8443 += $n; if ($own === '') $own = $u; } else { $other += $n; }
    $k = c2_mask_url($u); $byMask[$k] = ($byMask[$k] ?? 0) + $n;
}
if (!$byMask) out('links in its PDF', 'none');
$first = true;
foreach ($byMask as $k => $n) { out($first ? 'links in its PDF' : '', "{$n} × {$k}"); $first = false; }
echo "  (a PDF is made when uCRM renders the invoice; one made before a template change may still show the old links)\n";

// uCRM's own payment page, as a customer would reach it on the public address: the same page without the port.
if ($own !== '') echo '@@FETCH ' . preg_replace('#^(https?://[^/:]+):8443(?=/|$)#i', '$1', $own) . "\n";
echo "@@ {$on8443} {$other} ok\n";
