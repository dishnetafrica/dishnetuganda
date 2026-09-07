<?php
declare(strict_types=1);

require_once __DIR__ . '/EfrisGoodsStore.php';
require_once __DIR__ . '/EfrisClient.php';

/**
 * EfrisGoodsService — goods/services registration (T130) and stock
 * maintenance (T131), the URA checklist's Q1/Q2/Q7.
 *
 * Same hard rules as invoicing: environment disabled/production never sends;
 * EFRIS references are stored verbatim; stock quantities are OPERATOR facts
 * (what physically arrived or was adjusted) — this layer never invents a
 * number. The local stock_qty is a mirror maintained from successful T131
 * acknowledgements plus EFRIS's own invoice-time decrements (URA reduces
 * stock server-side when a fiscalised invoice sells a stocked item).
 *
 * Saving a goods row with a commodity code WRITES THROUGH to
 * efris_commodity_map.json, so invoice-line mapping and the registry can
 * never disagree about an item's URA code.
 */
class EfrisGoodsService
{
    private $store;               // SqliteStore
    private array $config;
    private EfrisGoodsStore $goods;
    private EfrisClient $client;
    private string $dataDir;

    public function __construct($store, array $config, string $dataDir, ?EfrisClient $client = null)
    {
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = $dataDir;
        $this->goods   = new EfrisGoodsStore($store->getPdo());
        $this->client  = $client ?: EfrisClient::forConfig($config);
    }

    public function registry(): EfrisGoodsStore { return $this->goods; }

    /** Create/update a catalogue row (no network). */
    public function save(array $f): array
    {
        $name = trim((string)($f['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => 'The item needs a name (it must match the uCRM invoice line label).'];
        $vat = trim((string)($f['vat_category'] ?? 'standard'));
        if (!in_array($vat, EfrisGoodsStore::VAT_CATEGORIES, true)) {
            return ['ok' => false, 'error' => 'VAT category must be one of: ' . implode(', ', EfrisGoodsStore::VAT_CATEGORIES)];
        }
        if (isset($f['unit_price']) && $f['unit_price'] !== '' && (float)$f['unit_price'] < 0) {
            return ['ok' => false, 'error' => 'Unit price cannot be negative'];
        }
        $id = $this->goods->upsert([
            'name' => $name,
            'goods_code' => trim((string)($f['goods_code'] ?? '')),
            'commodity_code' => trim((string)($f['commodity_code'] ?? '')),
            'unit' => trim((string)($f['unit'] ?? 'each')) ?: 'each',
            'unit_price' => $f['unit_price'] ?? '',
            'vat_category' => $vat,
            'stocked' => !empty($f['stocked']),
        ]);
        $this->writeThroughCommodityMap();
        return ['ok' => true, 'id' => $id];
    }

    /** Registry rows with a commodity code become the invoice-mapping source. */
    private function writeThroughCommodityMap(): void
    {
        $map = [];
        $byItem = [];
        foreach ((array)$this->store->load('efris_commodity_map.json') as $r) {
            if (!empty($r['item'])) { $map[] = $r; $byItem[strtolower((string)$r['item'])] = count($map) - 1; }
        }
        foreach ($this->goods->all() as $g) {
            if (trim((string)$g['commodity_code']) === '') continue;
            $key = strtolower((string)$g['name']);
            $row = ['item' => (string)$g['name'], 'code' => (string)$g['commodity_code']];
            if (isset($byItem[$key])) $map[$byItem[$key]] = $row; else $map[] = $row;
        }
        $this->store->save('efris_commodity_map.json', $map);
    }

    /** T130 — register one item with EFRIS. */
    public function register(int $goodsId): array
    {
        $g = $this->goods->get($goodsId);
        if (!$g) return ['ok' => false, 'error' => 'Unknown goods row'];
        if (!$this->client->isUsable()) {
            return ['ok' => false, 'error' => $this->client->refusalReason()];
        }
        $missing = [];
        if (trim((string)$g['commodity_code']) === '') $missing[] = 'URA commodity code';
        if (trim((string)$g['goods_code']) === '')     $missing[] = 'your own goods code (SKU)';
        if ($missing) {
            return ['ok' => false, 'error' => "'{$g['name']}' cannot be registered without: " . implode(' and ', $missing) . '.'];
        }

        $r = $this->client->uploadGoods(['goods' => [
            'name'           => (string)$g['name'],
            'goods_code'     => (string)$g['goods_code'],
            'commodity_code' => (string)$g['commodity_code'],
            'unit'           => (string)$g['unit'],
            'unit_price'     => $g['unit_price'] !== null ? (float)$g['unit_price'] : null,
            'currency'       => (string)$g['currency'],
            'vat_category'   => (string)$g['vat_category'],
            'stocked'        => (bool)$g['stocked'],
        ]]);

        if ($r['ok'] && is_array($r['content'])) {
            $ref = trim((string)($r['content']['goodsReference'] ?? $r['content']['referenceNo'] ?? ''));
            if ($ref === '') {
                $msg = 'EFRIS said success but returned no goods reference — NOT marked registered';
                $this->goods->update($goodsId, ['status' => EfrisGoodsStore::ST_ERROR, 'response_message' => $msg]);
                return ['ok' => false, 'error' => $msg];
            }
            $this->goods->update($goodsId, [
                'status' => EfrisGoodsStore::ST_REGISTERED,
                'efris_reference' => $ref,
                'response_message' => 'OK',
                'registered_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->log("goods '{$g['name']}' REGISTERED ref={$ref}");
            return ['ok' => true, 'reference' => $ref, 'goods' => $this->goods->get($goodsId)];
        }

        $this->goods->update($goodsId, [
            'status' => EfrisGoodsStore::ST_ERROR,
            'response_message' => $r['error'],
        ]);
        $this->log("goods '{$g['name']}' registration failed: {$r['error']}");
        return ['ok' => false, 'error' => $r['error'], 'goods' => $this->goods->get($goodsId)];
    }

    /**
     * T131 — stock movement. $op: 'increase' (purchase/opening arrival) or
     * 'decrease' (damage, loss, correction). Quantities are operator facts.
     */
    public function adjustStock(int $goodsId, string $op, float $qty,
                                string $reason, string $note, string $actor): array
    {
        $g = $this->goods->get($goodsId);
        if (!$g) return ['ok' => false, 'error' => 'Unknown goods row'];
        if (!in_array($op, ['increase', 'decrease'], true)) {
            return ['ok' => false, 'error' => 'Stock operation must be increase or decrease'];
        }
        if ($qty <= 0)             return ['ok' => false, 'error' => 'Quantity must be positive'];
        if (trim($reason) === '')  return ['ok' => false, 'error' => 'A stock movement needs its reason (e.g. "purchase", "damaged")'];
        if (!(int)$g['stocked'])   return ['ok' => false, 'error' => "'{$g['name']}' is not a stocked item — mark it stocked first"];
        if ($g['status'] !== EfrisGoodsStore::ST_REGISTERED) {
            return ['ok' => false, 'error' => "'{$g['name']}' must be registered with EFRIS (T130) before stock can move"];
        }
        if ($op === 'decrease' && $qty > (float)$g['stock_qty'] + 1e-9) {
            return ['ok' => false, 'error' => "Cannot decrease {$qty} — only " . (float)$g['stock_qty'] . " on hand"];
        }
        if (!$this->client->isUsable()) {
            return ['ok' => false, 'error' => $this->client->refusalReason()];
        }

        $r = $this->client->stockMaintain(['stock' => [
            'goods_code' => (string)($g['goods_code'] !== '' ? $g['goods_code'] : $g['name']),
            'op'         => $op,
            'qty'        => $qty,
            'reason'     => trim($reason),
            'note'       => trim($note),
            'unit'       => (string)$g['unit'],
            'unit_price' => $g['unit_price'] !== null ? (float)$g['unit_price'] : 0.0,
        ]]);

        if ($r['ok'] && is_array($r['content'])) {
            $newQty = (float)$g['stock_qty'] + ($op === 'increase' ? $qty : -$qty);
            $this->goods->update($goodsId, ['stock_qty' => $newQty]);
            $this->goods->logStock($goodsId, $op, $qty, trim($reason), trim($note),
                $r['request_id'], 'OK', (string)($r['content']['stockReference'] ?? 'OK'), $actor);
            $this->log("stock '{$g['name']}' {$op} {$qty} → {$newQty}");
            return ['ok' => true, 'stock_qty' => $newQty, 'goods' => $this->goods->get($goodsId)];
        }

        $this->goods->logStock($goodsId, $op, $qty, trim($reason), trim($note),
            $r['request_id'], 'ERROR', $r['error'], $actor);
        $this->log("stock '{$g['name']}' {$op} {$qty} FAILED: {$r['error']}");
        return ['ok' => false, 'error' => $r['error']];
    }

    /**
     * EFRIS decrements stock server-side when a fiscalised invoice sells a
     * stocked item; mirror that locally so the registry matches URA's view.
     * Called by EfrisService after a successful T109 fiscalisation.
     */
    public function mirrorInvoiceSale(array $modelItems, string $invoiceNumber): void
    {
        foreach ($modelItems as $it) {
            $g = $this->goods->findByName((string)($it['label'] ?? ''));
            if (!$g || !(int)$g['stocked'] || $g['status'] !== EfrisGoodsStore::ST_REGISTERED) continue;
            $qty = (float)($it['qty'] ?? 0);
            if ($qty <= 0) continue;
            $newQty = (float)$g['stock_qty'] - $qty;
            $this->goods->update((int)$g['id'], ['stock_qty' => $newQty]);
            $this->goods->logStock((int)$g['id'], 'decrease', $qty, 'sale',
                'fiscalised invoice ' . $invoiceNumber, '', 'OK',
                'EFRIS decrements stock on fiscalisation — local mirror', 'system');
        }
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->dataDir . '/efris.log',
            '[' . gmdate('Y-m-d H:i:s') . '] [goods] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
