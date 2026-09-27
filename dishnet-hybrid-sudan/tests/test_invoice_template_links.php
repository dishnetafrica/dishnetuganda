<?php
declare(strict_types=1);
/**
 * test_invoice_template_links.php — the Uganda invoice's PAY NOW leads to DishNet's own pay page (docs/38 §7.2, C-2).
 *
 * The two :8443 links measured inside Uganda invoice PDFs were this template's PAY NOW box: it printed uCRM's
 * invoice.onlinePaymentLink as the button and again as the address under it, and uCRM builds that link on the port
 * UISP was installed with — 8443, whose certificate a browser refuses. No uCRM or UISP setting changes that port on
 * UISP 3.0.159, so the template no longer uses uCRM's link at all: the button and the address under it are the
 * Uganda profile's pay_url. Staff make the same three edits in uCRM's template editor; this pins the copy they
 * paste from.
 *
 * The control rebuilds the template as it was (the three edits reversed) and shows every check rejects it.
 *
 * Also pinned here: PAID is decided by the amount due holding no digit 1–9, so it holds however uCRM writes a zero
 * (a list of spellings missed "UGX 0" with a non-breaking space). scripts/harness/invoice-template renders the
 * template with real Twig 2 and 3 under a sandbox limited to what uCRM already accepted in this template.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
$file = $root . '/ucrm_pdf_templates/invoice_uganda/template.html.twig';
$tpl  = (string)file_get_contents($file);
$pay  = (string)(json_decode((string)file_get_contents($root . '/profiles/uganda.json'), true)['pay_url'] ?? '');

/** The template as Twig will run it: comments removed. */
function code(string $tpl): string { return (string)preg_replace('/\{#.*?#\}/s', '', $tpl); }
/** The ONLINE PAYMENT box, from its marker to the {% endif %} that closes it. */
function box(string $tpl): string {
    $c = code($tpl);
    $i = strpos($c, '<!-- ONLINE PAYMENT -->');
    if ($i === false) return '';
    $j = strpos($c, '{% endif %}', $i);
    return $j === false ? '' : substr($c, $i, $j - $i);
}
/** Every check, so the control can run exactly the same ones. */
function checks(string $tpl, string $pay): array {
    $box = box($tpl);
    preg_match_all('/href="([^"]*)"/', $box, $h);
    return [
        'no uCRM online-payment link is used' => strpos(code($tpl), 'onlinePaymentLink') === false,
        'nothing on :8443'                    => strpos(code($tpl), ':8443') === false,
        'the box shows on every unpaid invoice' => strpos($box, '{% if not is_paid %}') !== false,
        'its only link is the pay page'       => $h[1] === [$pay],
        'the address under it is the pay page' => strpos($box, '>' . preg_replace('#^https://#', '', $pay) . '</div>') !== false,
    ];
}

echo "1) the Uganda profile names a pay page\n";
t('profiles/uganda.json pay_url', $pay, 'https://dishnetuganda.com/pay');

echo "2) the template's PAY NOW box\n";
t('the template has an ONLINE PAYMENT box', box($tpl) !== '', true);
foreach (checks($tpl, $pay) as $name => $ok) t($name, $ok, true);
t('the button still says PAY NOW with the amount due',
  strpos(box($tpl), 'PAY NOW &mdash; {{ totals.amountDue }}</a>') !== false, true);

echo "3) the control: the template as it was fails every check above\n";
$before = strtr($tpl, [
    '{% if not is_paid %}' => '{% if invoice.onlinePaymentLink and not is_paid %}',
    'href="' . $pay . '"'  => 'href="{{ invoice.onlinePaymentLink }}"',
    '>' . preg_replace('#^https://#', '', $pay) . '</div>' => '>{{ invoice.onlinePaymentLink }}</div>',
]);
t('the reversed copy differs from the template', $before !== $tpl, true);
t('the reversed copy prints the uCRM link three times', substr_count(code($before), 'invoice.onlinePaymentLink'), 3);
foreach (checks($before, $pay) as $name => $ok) {
    if ($name === 'nothing on :8443') continue;   // the port lives in uCRM's value, not in the template text
    t("rejected: $name", $ok, false);
}

echo "4) the three edits staff make are the three this copy carries\n";
$guard = (string)file_get_contents(dirname($root) . '/scripts/dnb-c2-check.sh');
t('the guard tells staff to replace the condition', strpos($guard, '{% if invoice.onlinePaymentLink and not is_paid %}   becomes   {% if not is_paid %}') !== false, true);
t('the guard points the button at the profile\'s pay_url', strpos($guard, 'becomes   href="$PAY_PAGE"') !== false, true);
t('the guard reads PAY_PAGE from profiles/uganda.json', strpos($guard, '"pay_url"') !== false && strpos($guard, 'profiles/uganda.json') !== false, true);

echo "5) PAID is decided by the digits, not by a list of spellings (rendered with Twig 2 and 3 by scripts/harness/invoice-template)\n";
$paidRule = "{% set is_paid = totals.amountDue is not empty %}\n"
          . "{% for d in ['1', '2', '3', '4', '5', '6', '7', '8', '9'] %}{% if d in totals.amountDue %}{% set is_paid = false %}{% endif %}{% endfor %}";
t('the template carries the digit rule', strpos(code($tpl), $paidRule) !== false, true);
t('and no list of spellings for a zero amount', preg_match('/totals\.amountDue\s+in\s+\[/', code($tpl)), 0);
t('PAY NOW and the status both follow is_paid', substr_count(code($tpl), 'is_paid') >= 4, true);
$listCopy = str_replace($paidRule, "{% set is_paid = totals.amountDue in ['UGX 0.00', 'UGX 0', 'USh 0.00', '0.00', '$0.00'] %}", $tpl);
t('control: a copy with the old list is told apart', [strpos(code($listCopy), $paidRule) !== false, preg_match('/totals\.amountDue\s+in\s+\[/', code($listCopy))], [false, 1]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
