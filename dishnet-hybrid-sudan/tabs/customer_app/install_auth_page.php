<?php
/**
 * install_auth_page.php — the customer's Installation Authorisation page: public.php?page=install_auth&t=<token>
 * (5.18.82, docs/61 §3, docs/63; the brief's phases 3, 4, 11 and 15).
 *
 * What the customer sees is the RECORD — the snapshots taken when the request was made and the terms version it names —
 * never a live price, a live job or the latest terms. The only input that selects anything is the token in the link:
 * 32 random bytes, looked up by SHA-256 (lib/InstallAuth.php), scoped to exactly one job; no id, name or amount is
 * carried in the URL or accepted from the form. A GET never changes the record (it records that the page was viewed,
 * once a day). The POST carries the token, the action, the terms hash the page showed, the confirmation box and a name;
 * the server re-reads the record and accepts with one statement that requires it to be pending, unexpired and showing
 * those very terms — a second click reads "Installation already authorised." and changes nothing.
 *
 * Reachable only where StaffJobsGate reads Uganda AND install_auth_enabled is on; everywhere else it is a 404 page.
 * HTTPS is required (a loopback request, as in the tests, is allowed); every failure is one neutral page; every
 * address is rate-limited through a hashed ledger that keeps no address. No script runs on the page; styles are inline
 * and nothing is loaded from anywhere (Content-Security-Policy: default-src 'none').
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/InstallAuth.php';
require_once dirname(__DIR__, 2) . '/lib/InstallationTerms.php';
require_once dirname(__DIR__, 2) . '/lib/TenantProfile.php';
require_once dirname(__DIR__, 2) . '/lib/CustomerSession.php';
require_once dirname(__DIR__, 2) . '/lib/timezone.php';

$iaConfig  = is_array($config ?? null) ? $config : [];
$iaDataDir = (isset($dataDir) && is_string($dataDir) && $dataDir !== '') ? $dataDir : null;

function iaEsc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function iaHeaders(int $code): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

/** The whole document around a body: the dark top bar with the legal entity, the content, the footer. */
function iaPage(string $title, string $body, array $b): string
{
    $entity = iaEsc($b['entity']);
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . iaEsc($b['brand']) . ' &mdash; ' . iaEsc($title) . '</title>'
        . '<style>'
        . ':root{--red:#D41C1C;--dark:#141414;--gray:#6B6B6B;--line:#E6E6E6;--off:#F5F5F5;--green:#15803d;}'
        . '*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--off);color:var(--dark);line-height:1.55;-webkit-font-smoothing:antialiased}'
        . '.top{background:var(--dark);color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}'
        . '.top .ent{font-weight:800;letter-spacing:.06em;text-transform:uppercase;font-size:14px}.top .tag{font-size:11px;opacity:.7}'
        . '.swoosh{height:5px;background:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%)}'
        . '.wrap{max-width:720px;margin:0 auto;padding:20px 16px 48px}'
        . '.card{background:#fff;border-radius:14px;padding:22px 20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:1px solid var(--line)}'
        . 'h1{font-size:22px;line-height:1.25;letter-spacing:.01em;margin-bottom:6px}h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray);margin:0 0 10px}'
        . 'p{margin:0 0 12px}.sub{color:var(--gray);font-size:14px}'
        . 'table.f{width:100%;border-collapse:collapse}table.f td{padding:9px 0;border-bottom:1px solid var(--line);font-size:14px;vertical-align:top}table.f td:first-child{color:var(--gray);width:42%;padding-right:10px}'
        . 'table.f td:last-child{font-weight:700;text-align:right}table.f tr.total td{font-size:16px;border-bottom:none;padding-top:12px}'
        . '.terms{max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:14px 16px;background:#FAFAFA;font-size:13.5px}'
        . '.terms h3{font-size:14px;margin:14px 0 4px}.terms p{margin:0 0 8px}.terms .pre{white-space:pre-wrap}'
        . '.meta{font-size:12px;color:var(--gray);margin-top:8px;word-break:break-all}'
        . '.agree{display:flex;gap:12px;align-items:flex-start;background:#FFF7F7;border:1px solid #F3C9C9;border-radius:10px;padding:14px;margin:16px 0}.agree input{width:22px;height:22px;flex-shrink:0;margin-top:2px}'
        . 'label.lbl{display:block;font-size:13px;color:var(--gray);margin:12px 0 4px}input.txt,textarea.txt{width:100%;border:1.5px solid #D9D9D9;border-radius:10px;padding:11px 12px;font-size:15px;font-family:inherit}'
        . '.btn{display:block;width:100%;border:none;border-radius:12px;padding:16px;font-size:16px;font-weight:800;letter-spacing:.03em;cursor:pointer;text-transform:uppercase}'
        . '.btn.ok{background:var(--green);color:#fff}.btn.no{background:#fff;color:#8A1C1C;border:2px solid #E0B4B4;margin-top:10px}'
        . '.state{text-align:center;padding:10px 0}.state .ico{font-size:44px;line-height:1}.state h1{margin-top:8px}'
        . '.ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:18px;font-weight:800;background:#F1F5F9;border-radius:10px;padding:10px 14px;display:inline-block;margin:8px 0}'
        . '.err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:14px}'
        . '.foot{text-align:center;color:var(--gray);font-size:12px;padding:10px 16px 30px}'
        . '</style></head><body>'
        . '<div class="top"><div class="ent">' . $entity . '</div><div class="tag">Starlink Installation Authorisation</div></div><div class="swoosh"></div>'
        . '<div class="wrap">' . $body . '</div>'
        . '<div class="foot">' . $entity . ($b['locality'] !== '' ? ' &middot; ' . iaEsc($b['locality']) : '') . ($b['support'] !== '' ? '<br>Questions? Call or WhatsApp ' . iaEsc($b['support']) : '') . '</div>'
        . '</body></html>';
}

/** One neutral page for every refusal: what to do next, and nothing about why beyond what the holder already knows. */
function iaNeutral(int $code, string $icon, string $title, string $text, array $b): void
{
    iaHeaders($code);
    echo iaPage($title, '<div class="card state"><div class="ico">' . $icon . '</div><h1>' . iaEsc($title) . '</h1><p class="sub">' . iaEsc($text) . '</p></div>', $b);
    exit;
}

// ── The brand words, from the tenant profile ─────────────────────────────────
$iaBrand = ['entity' => 'DishNet Africa Limited', 'brand' => 'DishNet Africa', 'locality' => '', 'support' => ''];
try {
    $iaProfile = TenantProfile::current($iaConfig, $iaDataDir);
    $e = trim($iaProfile->legalEntity());  if ($e !== '' && $e !== TenantProfile::notConfigured()) $iaBrand['entity'] = $e;
    $t = trim($iaProfile->tradingName()); if ($t !== '' && $t !== TenantProfile::notConfigured()) $iaBrand['brand'] = $t;
    $l = trim($iaProfile->locality());    if ($l !== '' && $l !== TenantProfile::notConfigured()) $iaBrand['locality'] = $l;
    require_once dirname(__DIR__, 2) . '/lib/CustomerContact.php';
    $iaBrand['support'] = trim(CustomerContact::support($iaConfig));
} catch (\Throwable $e) { /* the defaults stand */ }

// ── The gate: Uganda and the flag, or a 404 like any page that does not exist ─
if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {
    iaNeutral(404, '🔍', 'Page not found', 'This page is not available.', $iaBrand);
}

// ── HTTPS, or loopback (the tests drive the real page over 127.0.0.1) ────────
$iaRemote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$iaLoop   = in_array($iaRemote, ['127.0.0.1', '::1'], true) || strpos($iaRemote, '127.') === 0;
if (!CustomerSession::isHttps() && !$iaLoop) {
    iaNeutral(403, '🔒', 'Secure connection required', 'Please open the link from your message again, using the https address.', $iaBrand);
}

// ── Rate limit, per address, hashed; the ledger keeps no address ─────────────
$iaMethod  = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$iaIsPost  = $iaMethod === 'POST';
$iaAddress = $iaRemote;
if (($iaLoop || strpos($iaRemote, '10.') === 0 || strpos($iaRemote, '172.') === 0 || strpos($iaRemote, '192.168.') === 0) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $iaAddress = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);   // behind uCRM's nginx the proxy is local; the client is the first hop
}
$iaPdo = $store->getPdo();
[$iaLimit, $iaWindow] = $iaIsPost ? InstallAuth::RATE_POST : InstallAuth::RATE_PAGE;
if (!InstallAuth::rateAllow($iaPdo, $iaIsPost ? 'post' : 'page', $iaAddress, $iaLimit, $iaWindow)) {
    iaNeutral(429, '⏳', 'Too many attempts', 'Please wait a few minutes and open the link again.', $iaBrand);
}

// ── The token names the record, or nothing ───────────────────────────────────
$iaToken = (string)($iaIsPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$iaRow   = InstallAuth::byToken($iaPdo, $iaToken);
if ($iaRow === null) {
    iaNeutral(404, '🔗', 'This link is not valid', 'This link is not valid or has expired. Please contact DishNet for a new one.', $iaBrand);
}
$iaRow = InstallAuth::expireIfDue($iaPdo, $iaRow);
$iaTz  = dn_tz_obj($iaConfig);
$iaH   = InstallAuth::hydrate($iaRow);
$iaP   = (array)$iaH['price'];
$iaS   = (array)$iaH['scope'];
$iaCur = (string)($iaP['currency'] ?? 'UGX');

/** The acceptance, as a page: reference, time, terms. Shown to the holder of the link after the fact, as often as asked. */
function iaAcceptedPage(array $h, \DateTimeZone $tz, array $b, bool $justNow): void
{
    iaHeaders(200);
    $body = '<div class="card state"><div class="ico">✅</div><h1>' . ($justNow ? 'Installation authorised' : iaEsc(InstallAuth::ALREADY)) . '</h1>'
          . '<p class="sub">' . ($justNow ? 'Thank you. Your authorisation has been recorded and our team has been notified.' : 'This installation was authorised earlier. Nothing has changed.') . '</p>'
          . '<div class="ref">' . iaEsc((string)$h['acceptance_reference']) . '</div>'
          . '<table class="f"><tr><td>Installation Job</td><td>' . (int)$h['job_id'] . '</td></tr>'
          . '<tr><td>Accepted</td><td>' . iaEsc(InstallAuth::when((string)$h['accepted_at'], $tz)) . '</td></tr>'
          . '<tr><td>Terms</td><td>' . iaEsc((string)$h['terms_version']) . '</td></tr>'
          . '<tr><td>Terms hash</td><td style="font-weight:400;font-size:12px;word-break:break-all">' . iaEsc((string)$h['terms_hash']) . '</td></tr></table>'
          . '<p class="sub" style="margin-top:14px">Keep the reference: it identifies your authorisation. A confirmation has been sent to the contact details DishNet holds for you.</p></div>';
    echo iaPage('Installation authorised', $body, $b);
    exit;
}

function iaDeclinedPage(array $h, array $b, bool $justNow): void
{
    iaHeaders(200);
    $body = '<div class="card state"><div class="ico">⛔</div><h1>Installation declined</h1>'
          . '<p class="sub">' . ($justNow ? 'We have recorded that you did not authorise this installation. No work will be carried out, and DishNet will contact you.' : 'This installation request was declined. If you have changed your mind, please contact DishNet and a new request will be sent.') . '</p>'
          . '<table class="f"><tr><td>Installation Job</td><td>' . (int)$h['job_id'] . '</td></tr><tr><td>Reference</td><td>' . iaEsc((string)$h['acceptance_reference']) . '</td></tr></table></div>';
    echo iaPage('Installation declined', $body, $b);
    exit;
}

// ── A record that is no longer pending answers with its state, whatever was asked ─
switch ((string)$iaRow['status']) {
    case 'accepted':  iaAcceptedPage($iaH, $iaTz, $iaBrand, false);
    case 'declined':  iaDeclinedPage($iaH, $iaBrand, false);
    case 'cancelled': iaNeutral(410, '🔗', 'This request has been withdrawn', 'DishNet has withdrawn this authorisation request. If an installation is still planned, a new request will be sent to you.', $iaBrand);
    case 'expired':   iaNeutral(410, '⌛', 'This link has expired', 'The link in your message has expired. Please contact DishNet for a new one.', $iaBrand);
    case 'pending':   break;
    default:          iaNeutral(404, '🔗', 'This link is not valid', 'This link is not valid or has expired. Please contact DishNet for a new one.', $iaBrand);
}

// ── POST: accept or decline, decided by one statement in InstallAuth ─────────
$iaError = '';
if ($iaIsPost) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'accept') {
        $agree = (string)($_POST['agree'] ?? '') === '1';
        $name  = InstallAuth::oneLine((string)($_POST['name'] ?? ''), 80);
        $shown = (string)($_POST['terms_hash'] ?? '');
        if (!$agree) {
            $iaError = 'Please tick the confirmation box to authorise the installation.';
        } elseif ($name === '') {
            $iaError = 'Please enter your name.';
        } elseif (!preg_match('/^[0-9a-f]{64}$/', $shown)) {
            $iaError = 'The page did not send its terms correctly. Please reload the link from your message and try again.';
        } else {
            $res = InstallAuth::accept($iaPdo, $iaRow, $shown, $name);
            switch ($res['outcome']) {
                case 'accepted':
                    // The record is committed. Only now are people told, and nothing they do can touch it.
                    try {
                        require_once dirname(__DIR__, 2) . '/lib/InstallAuthNotifier.php';
                        (new InstallAuthNotifier(svc('crm'), $store, svc('notify'), $iaConfig, $iaDataDir))->afterAcceptance($res['row']);
                    } catch (\Throwable $e) {
                        error_log('[install_auth] notifications after acceptance of job #' . (int)$iaRow['job_id'] . ' failed: ' . get_class($e));
                    }
                    iaAcceptedPage(InstallAuth::hydrate($res['row']), $iaTz, $iaBrand, true);
                    // exit
                case 'already':
                    iaAcceptedPage(InstallAuth::hydrate($res['row']), $iaTz, $iaBrand, false);
                    // exit
                case 'terms_mismatch':
                    iaNeutral(409, '📄', 'The terms have changed', 'The Installation Terms shown to you are not the ones on record for this request. Please reload the link from your message and read them again before accepting.', $iaBrand);
                    // exit
                case 'expired':
                    iaNeutral(410, '⌛', 'This link has expired', 'The link in your message has expired. Please contact DishNet for a new one.', $iaBrand);
                    // exit
                case 'not_pending':
                    $st = (string)$res['row']['status'];
                    if ($st === 'declined') iaDeclinedPage(InstallAuth::hydrate($res['row']), $iaBrand, false);
                    iaNeutral(410, '🔗', 'This request has been withdrawn', 'DishNet has withdrawn this authorisation request. If an installation is still planned, a new request will be sent to you.', $iaBrand);
                    // exit
                default:
                    iaNeutral(400, '🔗', 'This link is not valid', 'This link is not valid or has expired. Please contact DishNet for a new one.', $iaBrand);
            }
        }
    } elseif ($action === 'decline') {
        $res = InstallAuth::decline($iaPdo, $iaRow, (string)($_POST['reason'] ?? ''));
        switch ($res['outcome']) {
            case 'declined':    iaDeclinedPage(InstallAuth::hydrate($res['row']), $iaBrand, true);
            case 'already':     iaAcceptedPage(InstallAuth::hydrate($res['row']), $iaTz, $iaBrand, false);
            case 'not_pending': if ((string)$res['row']['status'] === 'declined') iaDeclinedPage(InstallAuth::hydrate($res['row']), $iaBrand, false);
                                iaNeutral(410, '🔗', 'This request has been withdrawn', 'DishNet has withdrawn this authorisation request.', $iaBrand);
            case 'expired':     iaNeutral(410, '⌛', 'This link has expired', 'The link in your message has expired. Please contact DishNet for a new one.', $iaBrand);
            default:            iaNeutral(400, '🔗', 'This link is not valid', 'This link is not valid or has expired. Please contact DishNet for a new one.', $iaBrand);
        }
    } else {
        $iaError = 'Please use the buttons on the page.';
    }
}

// ── GET (or a POST that needs another look): the page itself ─────────────────
if (!$iaIsPost) InstallAuth::markViewed($iaPdo, $iaRow);

$termsVersion = (string)$iaRow['terms_version'];
$termsText    = InstallationTerms::text($termsVersion);
$termsHash    = (string)$iaRow['terms_hash'];
$termsOk      = $termsText !== null && InstallationTerms::matches($termsVersion, $termsHash);
$iaIntentDecline = (string)($_GET['intent'] ?? '') === 'decline';

$facts = [
    'Job Number'            => (string)(int)$iaH['job_id'],
    'Customer'              => (string)$iaH['customer_name'],
    'Installation Location' => (string)($iaS['location'] ?? ''),
    'Service'               => (string)($iaS['service'] ?? ''),
    'Equipment'             => (string)($iaS['equipment'] ?? ''),
    'Scheduled'             => (string)($iaS['scheduled_label'] ?? ''),
];
$charges = ['Installation Charges' => InstallAuth::money($iaP['installation'] ?? 0, $iaCur)];
$charges['Transport Charges'] = (float)($iaP['transport'] ?? 0) > 0 ? InstallAuth::money($iaP['transport'], $iaCur) : 'None';
if ((float)($iaP['other'] ?? 0) > 0) $charges[(string)($iaP['other_label'] ?? 'Other agreed charges')] = InstallAuth::money($iaP['other'], $iaCur);
else $charges['Other agreed charges'] = 'None';

$body  = '<div class="card"><h1>Starlink Installation Authorisation</h1>'
       . '<p class="sub">Please review the installation details and charges below, read the Installation Terms, and then accept or decline. '
       . 'Nothing is authorised until you confirm on this page.</p>'
       . '<h2 style="margin-top:14px">Installation</h2><table class="f">';
foreach ($facts as $k => $v) { if ($v === '') continue; $body .= '<tr><td>' . iaEsc($k) . '</td><td>' . iaEsc($v) . '</td></tr>'; }
$body .= '</table>';
$body .= '<h2 style="margin-top:18px">Charges</h2><table class="f">';
foreach ($charges as $k => $v) $body .= '<tr><td>' . iaEsc($k) . '</td><td>' . iaEsc($v) . '</td></tr>';
$body .= '<tr class="total"><td>Total</td><td>' . iaEsc(InstallAuth::money($iaP['total'] ?? 0, $iaCur)) . '</td></tr></table>'
       . '<p class="meta">Reference ' . iaEsc((string)$iaH['acceptance_reference']) . ' &middot; this link is valid until ' . iaEsc(InstallAuth::when((string)$iaRow['token_expires_at'], $iaTz)) . '</p>'
       . '</div>';

// The terms: the version this record names, rendered from this code, and shown only while the hash still matches the text.
$body .= '<div class="card" id="terms"><h2>Installation Terms &middot; ' . iaEsc($termsVersion) . '</h2>';
if ($termsOk) {
    $body .= '<div class="terms">';
    foreach (InstallationTerms::sections($termsVersion) as $sec) {
        if ($sec['n'] === 0) { $body .= '<p class="pre">' . iaEsc($sec['body']) . '</p>'; continue; }
        $body .= '<h3>' . $sec['n'] . '. ' . iaEsc($sec['title']) . '</h3><p class="pre">' . iaEsc($sec['body']) . '</p>';
    }
    $body .= '</div><p class="meta">SHA-256 ' . iaEsc($termsHash) . '</p>';
} else {
    $body .= '<div class="err">The Installation Terms this request refers to cannot be shown by this page right now. Please contact DishNet before accepting.</div>';
}
$body .= '</div>';

$action = iaEsc(dn_plugin_public($iaConfig) . '?page=install_auth');
$body .= '<div class="card" id="decide">';
if ($iaError !== '') $body .= '<div class="err">' . iaEsc($iaError) . '</div>';
if ($termsOk) {
    $body .= '<form method="post" action="' . $action . '">'
           . '<input type="hidden" name="t" value="' . iaEsc($iaToken) . '"><input type="hidden" name="action" value="accept">'
           . '<input type="hidden" name="terms_hash" value="' . iaEsc($termsHash) . '">'
           . '<label class="agree"><input type="checkbox" name="agree" value="1" required' . ((string)($_POST['agree'] ?? '') === '1' ? ' checked' : '') . '>'
           . '<span>I confirm that I have reviewed and accepted the Installation Terms and authorise ' . iaEsc($iaBrand['entity'])
           . ' to proceed with the installation described in this Installation Job.</span></label>'
           . '<label class="lbl" for="iaName">Your name</label>'
           . '<input class="txt" id="iaName" type="text" name="name" maxlength="80" required value="' . iaEsc((string)($_POST['name'] ?? $iaH['customer_name'])) . '">'
           . '<div style="height:14px"></div>'
           . '<button class="btn ok" type="submit">Accept &amp; authorise installation</button>'
           . '</form>';
}
$body .= '<form method="post" action="' . $action . '" id="decline" style="margin-top:' . ($termsOk ? '18px' : '0') . '">'
       . '<input type="hidden" name="t" value="' . iaEsc($iaToken) . '"><input type="hidden" name="action" value="decline">'
       . '<label class="lbl" for="iaReason">If you do not wish to proceed, you may tell us why (optional)</label>'
       . '<textarea class="txt" id="iaReason" name="reason" rows="2" maxlength="300"' . ($iaIntentDecline ? ' autofocus' : '') . '></textarea>'
       . '<button class="btn no" type="submit">Decline installation</button>'
       . '</form></div>';

iaHeaders($iaError !== '' ? 422 : 200);
echo iaPage('Starlink Installation Authorisation', $body, $iaBrand);
exit;
