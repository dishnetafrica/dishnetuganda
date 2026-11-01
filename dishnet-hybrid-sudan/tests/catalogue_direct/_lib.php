<?php
declare(strict_types=1);
/**
 * Shared by tests/test_catalogue_direct.php and its sections (5.18.103, root docs/72 §G). TEST ONLY.
 *
 * The sections run on tests/quote_from_ucrm/_lib.php's QfuCtx: child() runs a body on a copy of the judged tree's lib/
 * and profiles/ against the fake uCRM (fixtures/fake_ucrm_kyc.php). This file adds the direct-mode fixtures: a uCRM
 * catalogue with two plan pairs, kits, an accessory, the installation and rows each rule refuses, and the records of the
 * uCRM catalogue's store (ucrm_catalogue.json) a body seeds itself (cd_seed()). Every name, number and price is
 * fictitious.
 */

const CD_PLAN_RES = 6;     // service plan "Residential Standard (TEST)", paired with product 12
const CD_PLAN_BIZ = 7;     // service plan "Business Plus (TEST)", paired with product 17
const CD_KIT      = 55;    // "Standard Kit (TEST)"
const CD_MINI     = 56;    // "Mini Kit (TEST)"
const CD_ACC      = 57;    // "Pipe Adapter (TEST)"
const CD_INSTALL  = 70;    // "Professional Installation"

/** The service plans the fake serves in direct mode ($over: id => fields | null, as qfu_kyc_products). */
function cd_plans(array $over = []): array
{
    $mk = function (int $id, string $name, $price, array $more = []): array {
        return ['id' => $id, 'name' => $name, 'periods' => [['period' => 1, 'price' => $price, 'enabled' => true]],
                'downloadSpeed' => 100] + $more;
    };
    $p = [
        CD_PLAN_RES => $mk(CD_PLAN_RES, 'Residential Standard (TEST)', 100),
        CD_PLAN_BIZ => $mk(CD_PLAN_BIZ, 'Business Plus (TEST)', 300),
        8           => $mk(8, 'Lonely Plan (TEST)', 50),                    // no product spelled like it
    ];
    foreach ($over as $id => $set) {
        if ($set === null) { unset($p[$id]); continue; }
        $p[$id] = isset($p[$id]) ? array_merge($p[$id], $set) : $set;
    }
    return array_values($p);
}

/** The products the fake serves in direct mode ($over: id => fields | null). */
function cd_products(array $over = []): array
{
    $p = [
        12         => ['id' => 12,         'name' => 'Residential Standard (TEST)', 'price' => 100,  'unit' => 'Month', 'taxable' => false],
        17         => ['id' => 17,         'name' => 'Business Plus (TEST)',        'price' => 300,  'unit' => 'Month', 'taxable' => false],
        CD_KIT     => ['id' => CD_KIT,     'name' => 'Standard Kit (TEST)',         'price' => 1500, 'unit' => 'Pc',    'taxable' => false],
        CD_MINI    => ['id' => CD_MINI,    'name' => 'Mini Kit (TEST)',             'price' => 900,  'unit' => 'Pc',    'taxable' => false],
        CD_ACC     => ['id' => CD_ACC,     'name' => 'Pipe Adapter (TEST)',         'price' => 50,   'unit' => 'Pc',    'taxable' => false],
        CD_INSTALL => ['id' => CD_INSTALL, 'name' => 'Professional Installation',   'price' => 200,  'unit' => 'Job',   'taxable' => false],
        80         => ['id' => 80,         'name' => 'Free Thing (TEST)',           'price' => 0,    'unit' => 'Pc',    'taxable' => false],
        81         => ['id' => 81,         'name' => 'Old Kit (TEST)',              'price' => 700,  'unit' => 'Pc',    'taxable' => false, 'archived' => true],
    ];
    foreach ($over as $id => $set) {
        if ($set === null) { unset($p[$id]); continue; }
        $p[$id] = isset($p[$id]) ? array_merge($p[$id], $set) : $set;
    }
    return array_values($p);
}

/** Reset the fake to the Uganda scenario and serve the direct-mode catalogue (and any other state keys). */
function cd_fake(QfuCtx $c, array $set = []): void
{
    $c->ctl('kyc', '/__test/reset?scenario=uganda');
    $c->ctl('kyc', '/__test/set', $set + ['products' => cd_products(), 'service_plans' => cd_plans()]);   // the caller's keys win
}

/** One record of ucrm_catalogue.json in its stored shape, in force, by a fictitious admin. */
function cd_rec(int $id, string $kind, array $fields, array $more = []): array
{
    return array_merge(['id' => $id, 'kind' => $kind] + $fields + [
        'by_id' => 1, 'by_name' => 'Test Admin', 'at' => '2026-11-01 10:00:00', 'at_ts' => 1793527200 + $id,
        'ended_at' => null, 'ended_at_ts' => null, 'ended_by_id' => null,
    ], $more);
}

/** The usual records: the switch on (or off), the installation product, products kept off, standard quotations. */
function cd_records(bool $on = true, ?int $install = CD_INSTALL, array $hidden = [], array $standards = []): array
{
    $r = [cd_rec(1, 'mode', ['on' => $on])];
    if ($install !== null) $r[] = cd_rec(2, 'install', ['product_id' => $install, 'product_name' => 'Professional Installation']);
    $id = 10;
    foreach ($hidden as $pid) $r[] = cd_rec($id++, 'hidden', ['product_id' => $pid, 'product_name' => 'TEST #' . $pid]);
    foreach ($standards as $s) $r[] = cd_rec($id++, 'standard', $s);
    return $r;
}

/** A standard quotation's fields. */
function cd_std(string $title, int $sp, int $p, array $hw, bool $install): array
{
    return ['title' => $title, 'plan' => ['sp' => $sp, 'p' => $p], 'hw' => $hw, 'install' => $install];
}

/** PHP for a child body's first lines: the store's ucrm_catalogue.json set to $records ([] empties it; null leaves it). */
function cd_seed(?array $records): string
{
    if ($records === null) return '';
    return '$s->save("ucrm_catalogue.json", json_decode(' . var_export(json_encode($records), true) . ', true));' . "\n";
}

/** The child's resolver, direct mode: the plugin's client on the fake, the quoting organization 1, no link. */
function cd_uql_php(): string
{
    return '$__set = QuoteOrganization::setting($s->load("kyc_config.json") ?? [], $dir);
$__u = new UcrmQuoteLines($crm, $__set, UcrmQuoteLinks::fromRecords([], []));
$__cat = UcrmCatalogue::fromStore($s, $__set);' . "\n";
}

/** The direct form's post, over the KYC defaults QfuCtx::submit() adds: plan, cart, who installs, the marker. */
function cd_post(array $plan, array $cart, array $more = []): array
{
    return array_merge([
        'ucrm_quote_form' => '5.18.103-direct',
        'ucrm_plan'       => $plan[0] . ':' . $plan[1],
        'ucrm_cart_json'  => json_encode(array_map(function ($e) { return ['p' => $e[0], 'q' => $e[1]]; }, $cart)),
        'install_by'      => 'dishnet',
        'package_choice'  => null, 'device_id' => null, 'kitQty' => null,   // the direct form draws none of them
    ], $more);
}

/** A line's token, as the resolver computes it (for posts that must match). */
function cd_token(int $pid, string $label, $price, string $unit): string
{
    return substr(hash('sha256', $pid . '|' . $label . '|' . (string)(int)round(((float)$price) * 100) . '|' . $unit), 0, 16);
}
