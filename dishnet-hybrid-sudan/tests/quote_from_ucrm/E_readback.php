<?php
declare(strict_types=1);
/**
 * E — the created quote, read back (docs/71 §F.6): each item's label and price and the total, against what was sent.
 * uCRM's quote.add has already messaged the customer, so this detects; it cannot prevent. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1): the submissions carry the web form's marker (QfuCtx::submit's default) over the linked
 * seed, so the lines read back are the linked products' (E0 says so); nothing else in E changes.
 */

$QFU_SECTIONS['E'] = ['title' => 'The created quote, read back against the lines sent', 'run' => function (QfuCtx $c) {
    $fresh = function (array $set = []) use ($c) {
        $c->ctl('kyc', '/__test/reset?scenario=uganda');
        $c->ctl('kyc', '/__test/set', ['catalogue' => true] + $set);
    };
    $app = function (array $r): array { $a = (array)($r['value']['apps'] ?? []); return $a === [] ? [] : (array)end($a); };

    // E0 — the control: what uCRM made is what was sent
    $fresh();
    $a = $app($c->submit(['install_by' => 'dishnet']));
    $c->eq([isset($a['quote_mismatch']), array_column((array)($a['quote_readback']['items'] ?? []), 'has_product_id'), !empty($a['quote_created'])],
        [false, [true, true, true], true], 'E0 the control: the same lines and total — no mismatch; each item\'s productId noted');
    $c->eq([array_column((array)($a['quote_items'] ?? []), 'productId'), $a['quote_link_ids'] ?? null], [[12, 55, 70], [1, 2, 3]],
        'E0 … the lines read back against are the linked products\', quoted under the links 1, 2, 3');

    // E1 — uCRM priced the lines otherwise
    $fresh(['quote_mode' => 'substitute']);
    $a = $app($c->submit(['install_by' => 'dishnet']));
    $c->eq([$a['quote_mismatch']['sent_total'] ?? null, $a['quote_mismatch']['created_total'] ?? null], [1800, 1830], 'E1 uCRM used other prices: quote_mismatch, sent 1800, made 1830');

    // E2 — uCRM added tax
    $fresh(['quote_mode' => 'tax']);
    $a = $app($c->submit(['install_by' => 'dishnet']));
    $c->eq([$a['quote_mismatch']['sent_total'] ?? null, $a['quote_mismatch']['created_total'] ?? null], [1800, 2124], 'E2 uCRM added tax: quote_mismatch, made 2124');

    // E3 — uCRM ignored the productId: not a mismatch, but noted
    $fresh(['quote_mode' => 'ignore_product']);
    $a = $app($c->submit(['install_by' => 'dishnet']));
    $c->eq([isset($a['quote_mismatch']), array_column((array)($a['quote_readback']['items'] ?? []), 'type')], [false, ['custom', 'custom', 'custom']],
        'E3 uCRM made custom lines (ignoring productId): no mismatch, the type noted');

    // E4 — uCRM refused a line with a productId: no quote, the error kept, as for any refused POST
    $fresh(['quote_mode' => 'reject_product']);
    $a = $app($c->submit(['install_by' => 'dishnet']));
    $c->eq([!empty($a['quote_created']), isset($a['quote_error']), !empty($a['wa_quote_pending'])], [false, true, false], 'E4 uCRM refused it (422): no quote, quote_error kept, nothing queued');

    // E5 — the POST's answer had no items: one GET reads them
    $fresh(['quote_mode' => 'no_items']);
    $a = $app($c->submit(['install_by' => 'dishnet']));
    $gets = array_values(array_filter($c->requests('kyc'), fn($x) => $x === 'GET /billing/quotes/77'));
    $c->eq([count($gets), isset($a['quote_mismatch']), count((array)($a['quote_readback']['items'] ?? []))], [1, false, 3],
        'E5 the answer had no items: one GET billing/quotes/77, compared, no mismatch');

    // E6 — the gate off: nothing read back, nothing written
    $fresh(['quote_mode' => 'substitute']);
    $a = $app($c->submit(['install_by' => 'dishnet'], ['cfg' => ['ucrm_quote_organization_id' => '0']]));
    $c->eq([isset($a['quote_mismatch']), isset($a['quote_readback'])], [false, false], 'E6 Uganda with the setting 0: no read-back (5.18.101\'s record)');
}];
