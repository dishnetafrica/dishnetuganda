<?php
declare(strict_types=1);

/**
 * ████████████████ FAKE DPO SERVER — TEST ONLY ████████████████
 *
 * This is NOT DPO Pay. It exists so the payment layer can be exercised
 * without a merchant account, and so result codes a real test account
 * rarely produces can be driven on demand.
 *
 * It matters more here than for most integrations: DPO has no sandbox host
 * — test and live share one URL and differ only by company token — so the
 * ONLY way to rehearse code 903, a forged push, or a mid-verify timeout is
 * a server we control.
 *
 * Run under php -S as a router:
 *     php -S 127.0.0.1:9400 tests/fixtures/fake_dpo_server.php
 *
 * It routes on the <Request> element, so one port serves both the v6
 * createToken endpoint and the v7 verifyToken endpoint, as the client
 * points at whichever URL it was given.
 *
 * Behaviour is driven by the CompanyRef, so a test names the outcome it
 * wants in the reference it sends:
 *
 *   ref contains NOCCY   → createToken 904  currency not supported
 *   ref contains LIMIT   → createToken 905  amount over limit
 *   ref contains PAIDREF → createToken 940  CompanyRef already exists and paid
 *   ref contains BADXML  → an HTML error page instead of XML
 *   ref contains CSTALL  → sleeps 8s, so the client timeout wins
 *   ref contains NOTOKEN → Result 000 with no TransToken (a liar's success)
 *   anything else        → 000 with a TEST- token
 *
 * Tags are matched by substring, so they are chosen NOT to contain one
 * another — an earlier version used CUR and WRONGCUR, and the wrong-currency
 * case silently became a currency refusal that never minted a token.
 *
 * and at verify time, by the token the test holds:
 *
 *   token of a ref containing AUTH   → 001 Authorized (NOT paid)
 *   …                        UNDER   → 002, amount 1000 less than asked
 *   …                        OVER    → 002, amount 1000 more than asked
 *   …                        WAIT    → 900 not paid yet
 *   …                        DECLINE → 901 declined
 *   …                        EXPIRE  → 903 past the payment time limit
 *   …                        CANCEL  → 904 cancelled
 *   …                        NOAMT   → 000 but with NO amount/currency at all
 *   …                        ALTCCY  → 000 in a different currency
 *   …                        VSTALL  → sleeps 8s at verify
 *   anything else                    → 000 Transaction Paid, figures echoed
 *
 * Every token it mints is prefixed TEST- so nothing it returns can be
 * mistaken for a live DPO transaction token.
 */

header('Content-Type: text/xml; charset=utf-8');
header('X-Fake-Dpo: TEST-ONLY');

$stateFile = sys_get_temp_dir() . '/fake_dpo_state_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['seq' => 0, 'tx' => []];

function fd_save(): void { @file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state'])); }

function fd_xml(array $fields): void
{
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n<API3G>\n";
    foreach ($fields as $k => $v) echo "<{$k}>" . htmlspecialchars((string)$v, ENT_XML1, 'UTF-8') . "</{$k}>\n";
    echo "</API3G>";
    exit;
}

$raw = (string)file_get_contents('php://input');

// A probe with no body identifies the server to the harness.
if (trim($raw) === '') {
    echo '<?xml version="1.0" encoding="utf-8"?><API3G><Result>TEST-SERVER-SIGNATURE</Result></API3G>';
    exit;
}

$prev = libxml_use_internal_errors(true);
try { $in = new SimpleXMLElement($raw, LIBXML_NONET); } catch (\Throwable $e) { $in = null; }
libxml_clear_errors();
libxml_use_internal_errors($prev);

if ($in === null) fd_xml(['Result' => '804', 'ResultExplanation' => 'Error in XML']);

$request = trim((string)($in->Request ?? ''));
$token   = trim((string)($in->CompanyToken ?? ''));

// The credential IS the body field. An empty one is 801, an unknown one 802 —
// exactly as DPO's own table says, so the client's handling of both is real.
if ($token === '')                 fd_xml(['Result' => '801', 'ResultExplanation' => 'Request missing company token']);
if (strpos($token, 'TEST') !== 0)  fd_xml(['Result' => '802', 'ResultExplanation' => 'Company token does not exist']);

$has = static fn(string $hay, string $needle): bool => stripos($hay, $needle) !== false;

// ── createToken ─────────────────────────────────────────────────────────
if ($request === 'createToken') {
    $ref = trim((string)($in->Transaction->CompanyRef ?? ''));
    $amt = (float)($in->Transaction->PaymentAmount ?? 0);
    $cur = trim((string)($in->Transaction->PaymentCurrency ?? ''));

    if ($ref === '')            fd_xml(['Result' => '950', 'ResultExplanation' => 'Request missing mandatory fields - CompanyRef']);
    if ($has($ref, 'BADXML'))   { header('Content-Type: text/html'); echo '<html><body>502 Bad Gateway</body></html>'; exit; }
    if ($has($ref, 'CSTALL'))   { sleep(8); }
    if ($has($ref, 'NOCCY'))    fd_xml(['Result' => '904', 'ResultExplanation' => 'Currency not supported']);
    if ($has($ref, 'LIMIT'))    fd_xml(['Result' => '905', 'ResultExplanation' => 'The transaction amount has exceeded your allowed transaction limit']);
    if ($has($ref, 'PAIDREF'))  fd_xml(['Result' => '940', 'ResultExplanation' => 'CompanyREF already exists and paid']);
    if ($has($ref, 'NOTOKEN'))  fd_xml(['Result' => '000', 'ResultExplanation' => 'Transaction created']);

    // A reference already created returns the SAME token — DPO enforces
    // CompanyRef uniqueness, and a fake that forgot would hide double-charges.
    foreach ($state['tx'] as $t => $rec) {
        if (($rec['ref'] ?? '') === $ref) {
            fd_xml(['Result' => '000', 'ResultExplanation' => 'Transaction created',
                    'TransToken' => $t, 'TransRef' => $rec['trans_ref']]);
        }
    }

    $state['seq']++;
    $tok  = 'TEST-TOK-' . str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    $tref = 'TEST-REF-' . str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    $state['tx'][$tok] = ['ref' => $ref, 'amount' => $amt, 'currency' => $cur, 'trans_ref' => $tref];
    fd_save();

    fd_xml(['Result' => '000', 'ResultExplanation' => 'Transaction created',
            'TransToken' => $tok, 'TransRef' => $tref]);
}

// ── verifyToken ─────────────────────────────────────────────────────────
if ($request === 'verifyToken') {
    $tok = trim((string)($in->TransactionToken ?? ''));
    $rec = $state['tx'][$tok] ?? null;
    if ($rec === null) fd_xml(['Result' => '902', 'ResultExplanation' => 'Data mismatch - unknown transaction token']);

    $ref = (string)$rec['ref'];
    $amt = (float)$rec['amount'];
    $cur = (string)$rec['currency'];

    if ($has($ref, 'VSTALL'))  { sleep(8); }
    if ($has($ref, 'AUTH'))    fd_xml(['Result' => '001', 'ResultExplanation' => 'Authorized', 'CompanyRef' => $ref,
                                       'TransactionAmount' => number_format($amt, 2, '.', ''), 'TransactionCurrency' => $cur]);
    if ($has($ref, 'WAIT'))    fd_xml(['Result' => '900', 'ResultExplanation' => 'Transaction not paid yet', 'CompanyRef' => $ref]);
    if ($has($ref, 'DECLINE')) fd_xml(['Result' => '901', 'ResultExplanation' => 'Transaction declined', 'CompanyRef' => $ref]);
    if ($has($ref, 'EXPIRE'))  fd_xml(['Result' => '903', 'ResultExplanation' => 'The transaction passed the Payment Time Limit', 'CompanyRef' => $ref]);
    if ($has($ref, 'CANCEL'))  fd_xml(['Result' => '904', 'ResultExplanation' => 'Transaction cancelled', 'CompanyRef' => $ref]);
    if ($has($ref, 'UNDER'))   fd_xml(['Result' => '002', 'ResultExplanation' => 'Transaction overpaid/underpaid', 'CompanyRef' => $ref,
                                       'TransactionAmount' => number_format($amt - 1000, 2, '.', ''), 'TransactionCurrency' => $cur]);
    if ($has($ref, 'OVER'))    fd_xml(['Result' => '002', 'ResultExplanation' => 'Transaction overpaid/underpaid', 'CompanyRef' => $ref,
                                       'TransactionAmount' => number_format($amt + 1000, 2, '.', ''), 'TransactionCurrency' => $cur]);
    // Paid, but DPO tells us nothing about the figures. The client must report
    // this as unconfirmed, not as agreement.
    if ($has($ref, 'NOAMT'))   fd_xml(['Result' => '000', 'ResultExplanation' => 'Transaction Paid', 'CompanyRef' => $ref,
                                       'CustomerCreditType' => 'MobileMoney']);
    if ($has($ref, 'ALTCCY'))  fd_xml(['Result' => '000', 'ResultExplanation' => 'Transaction Paid', 'CompanyRef' => $ref,
                                       'TransactionAmount' => number_format($amt, 2, '.', ''), 'TransactionCurrency' => 'KES',
                                       'CustomerCreditType' => 'MobileMoney']);

    fd_xml(['Result' => '000', 'ResultExplanation' => 'Transaction Paid', 'CompanyRef' => $ref,
            'TransactionAmount'   => number_format($amt, 2, '.', ''),
            'TransactionCurrency' => $cur,
            'TransactionApproval' => 'TEST-APPROVAL-' . substr(md5($tok), 0, 8),
            'CustomerName'        => 'Test Customer',
            'CustomerCreditType'  => $has($ref, 'CARD') ? 'Visa' : 'MobileMoney']);
}

fd_xml(['Result' => '803', 'ResultExplanation' => 'No request or error in Request type name']);
