<?php
// 5.18.103 (Uganda, root docs/72 §G.3): with the uCRM catalogue switched on, this screen gives way to it — one card, and
// nothing below runs (no link store opened, no uCRM read). With the switch off, or the gate closed, the screen is 5.18.102's.
require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';
if (UcrmCatalogue::forConfig($store, is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)->direct()) {
    echo '<div class="card" style="padding:20px;max-width:720px;"><h3 style="margin-top:0;">Hardware</h3>'
       . '<p>On Uganda, plans and hardware come straight from uCRM — the uCRM catalogue is on. Prices are changed in uCRM.</p>'
       . '<p><a class="btn btn-primary" href="?page=dashboard&amp;tab=ucrm_catalogue">See the uCRM catalogue</a></p></div>';
    return;
}
?>
<?php
// Currency label for every money figure on this screen. The Sudan/SS
// builds hardcoded '$'; the amounts themselves are whatever currency the
// uCRM organisation bills in, so the label now follows config.
$curSym = htmlspecialchars(trim((string)(($config['currency_symbol'] ?? '') ?: 'UGX'))) . ' ';
?>
<?php
// Tab: hardware
// Extracted from public.php on 2026-03-15
        $allHW = $store->load('kyc_devices.json');
        $editHW = null;
        $hwEditId = (int)($_GET['edit_hw'] ?? 0);
        if ($hwEditId) { foreach ($allHW as $h2) { if ((int)($h2['id']??0)===$hwEditId) { $editHW=$h2; break; } } }
        // What the assistant can already show for each item. The media name is
        // the equipment title through the same key rule, so a row and its
        // picture are matched by name with nothing to maintain by hand.
        require_once dirname(__DIR__, 2) . '/lib/MediaLibrary.php';
        $_hwPhotos = MediaLibrary::all($dataDir);
        $_hwDocs   = MediaLibrary::documents($dataDir);
        $hwByType = ['starlink'=>[],'fiber'=>[],'general'=>[]];
        foreach ($allHW as $h2) { $ht = strtolower($h2['type']??'starlink'); $hwByType[$ht][] = $h2; }
        // ── 5.18.102 (Uganda, D14, docs/71 §R.2.2–§R.2.4): the "uCRM link" column, the picker panel and the badge ────────
        // Drawn only where the quoting-organization gate is on: elsewhere $_lk102 stays null, the link store is never opened
        // (§R.1.5) and every line below the gate is 5.18.101's. A row's state is the resolver's own answer — planLine,
        // kitLine and installLine through forPage, the links read live from the store — so this screen and the KYC form
        // cannot disagree. Every uCRM-sourced or stored string drawn here goes through h(), attribute values included; the
        // one name a script needs (Unlink's confirmation) travels in a data- attribute. Controls are drawn for admins only.
        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';
        $_lk102 = null;
        $_lk102Set = QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
        if (!empty($_lk102Set['on'])) {
            require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)
            $_lk102 = ['set' => $_lk102Set, 'org' => is_int($_lk102Set['id'] ?? null) ? $_lk102Set['id'] : null,
                       'cfg' => is_array($config ?? null) ? $config : [], 'crm' => isset($crm) ? $crm : null,
                       'admin' => !empty($isAdmin), 'cur' => $curSym, 'store' => $store, 'links' => null, 'state' => null,
                       'res' => null, 'also' => [],
                       'web' => rtrim((string)dn_crm_web(is_array($config ?? null) ? $config : []), '/')];
            try {
                $_lk102['links'] = UcrmQuoteLinks::fromStore($store, $_lk102Set);
                // The link states' own texts (§R.4.1, E-4), whether or not uCRM answers: the resolver's, over no catalogue.
                $_lk102['state'] = new UcrmQuoteLines(null, $_lk102Set, $_lk102['links'], ['ok' => true, 'reason' => '',
                    'products' => [], 'plans' => [], 'excluded' => ['products' => [], 'plans' => []], 'plan_key_of' => [],
                    'plan_keys' => [], 'plan_rows' => 0]);
                $_lk102['res'] = UcrmQuoteLines::forPage(UcrmQuoteLines::quoteClient($_lk102['crm'], $_lk102['cfg']), $_lk102Set,
                    (string)$dataDir, $_lk102['links']);
            } catch (\Throwable $e) {
                error_log('[hardware] 5.18.102: the uCRM links could not be drawn (' . get_class($e) . ')');
            }

            // One item's answer: the resolver's line, or (stateOnly) the link state's own text.
            $_lk102Ans = function (string $item, ?array $row, bool $stateOnly) use (&$_lk102): array {
                $r = $stateOnly ? $_lk102['state'] : $_lk102['res'];
                if ($r === null) return ['ok' => false, 'reason' => "uCRM's products could not be read", 'line' => null, 'link' => null, 'why' => 'unreadable'];
                if ($item === 'plan') return $r->planLine($row ?? []);
                if ($item === 'kit')  return $r->kitLine($row ?? [], 1);
                return $r->installLine();
            };
            // uCRM's product id, as the "#id 🔗" link to its uCRM page the column has always drawn.
            $_lk102Prod = function (int $pid) use (&$_lk102): string {
                return '<a href="' . h($_lk102['web'] . '/crm/system/items/products/' . $pid) . '" target="_blank" rel="noopener" title="View in uCRM"'
                    . ' style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#'
                    . (int)$pid . ' 🔗</a>';
            };
            // Where Link / Change opens the item's panel.
            $_lk102Href = function (string $item, int $rowId): string {
                if ($item === 'plan') return '?page=dashboard&tab=subscription_plans&ucrm_link_plan=' . (int)$rowId . '#ucrm-link';
                if ($item === 'kit')  return '?page=dashboard&tab=hardware&ucrm_link_hw=' . (int)$rowId . '#ucrm-link';
                return '?page=dashboard&tab=hardware&ucrm_link_install=1#ucrm-link';
            };
            $_lk102Btn = function (string $label, string $href): string {
                return '<a href="' . h($href) . '" style="display:inline-block;background:#2563eb;color:#fff;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:600;text-decoration:none;">' . h($label) . '</a>';
            };
            // Unlink: a POST after a confirmation whose row name is read from a data- attribute, never put into JS source.
            $_lk102Unlink = function (string $item, int $rowId, string $name): string {
                return '<form method="POST" style="display:inline;" data-confirm="'
                    . h('Unlink ' . ($name !== '' ? $name : 'this item') . '? Cash sales of it will be refused until it is linked again.')
                    . '" onsubmit="return confirm(this.dataset.confirm);">' . csrfField()
                    . '<input type="hidden" name="action" value="ucrm_link_clear">'
                    . '<input type="hidden" name="item" value="' . h($item) . '">'
                    . '<input type="hidden" name="row_id" value="' . (int)$rowId . '">'
                    . '<button type="submit" style="background:#fff;color:#b91c1c;border:1px solid #fecaca;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:600;cursor:pointer;">Unlink</button></form>';
            };
            $_lk102Tag = function (string $text, string $bg, string $fg): string {
                return '<span style="background:' . $bg . ';color:' . $fg . ';font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">' . h($text) . '</span>';
            };
            // "also linked to": the other rows of one kind whose link in force names the same product (§R.3.1, Q16).
            $_lk102AlsoAdd = function (string $item, array $rows) use (&$_lk102): void {
                if ($_lk102['links'] === null) return;
                foreach ($rows as $r) {
                    if (!is_array($r) || UcrmQuoteLinks::isFiber($item, $r)) continue;
                    $j = $_lk102['links']->judge($item, $r, $_lk102['org']);
                    if ($j['state'] !== 'ok') continue;
                    $_lk102['also'][(int)($j['record']['product_id'] ?? 0)][] = [(int)($r['id'] ?? 0), (string)($r[$item === 'plan' ? 'name' : 'title'] ?? '')];
                }
            };
            // An item with no link in force (none, two, replaced, another organization): what the badge counts.
            $_lk102Unlinked = function (string $item, array $rows) use (&$_lk102): int {
                $n = 0;
                foreach ($rows as $r) {
                    if (!is_array($r) || empty($r['is_active']) || UcrmQuoteLinks::isFiber($item, $r)) continue;
                    if ($_lk102['links'] === null || $_lk102['links']->judge($item, $r, $_lk102['org'])['state'] !== 'ok') $n++;
                }
                return $n;
            };

            // The "uCRM link" cell of one item (§R.2.2's table, one branch per state).
            $_lk102Cell = function (string $item, ?array $row) use (&$_lk102, $_lk102Ans, $_lk102Prod, $_lk102Href, $_lk102Btn, $_lk102Unlink, $_lk102Tag): string {
                $rowId = $item === 'install' ? 0 : (int)(($row ?? [])['id'] ?? 0);
                $name  = $item === 'install' ? 'Installation' : (string)(($row ?? [])[$item === 'plan' ? 'name' : 'title'] ?? '');
                if ($row !== null && UcrmQuoteLinks::isFiber($item, $row)) {
                    return $_lk102Tag('Fiber: not priced from uCRM on this installation', '#f3f4f6', '#6b7280');
                }
                if ($_lk102['links'] === null || $_lk102['state'] === null) {
                    return $_lk102Tag('the uCRM link could not be read', '#f3f4f6', '#6b7280');
                }
                $admin = !empty($_lk102['admin']);
                $j = $_lk102['links']->judge($item, $row, $_lk102['org']);
                if ($j['state'] === 'none') {
                    return $_lk102Tag('not linked — Cash sales of it are refused', '#fef3c7', '#92400e')
                        . ($admin ? ' ' . $_lk102Btn('Link', $_lk102Href($item, $rowId)) : '');
                }
                $acts = $admin ? '<div style="margin-top:4px;white-space:nowrap;">' . $_lk102Btn('Change', $_lk102Href($item, $rowId)) . ' '
                               . $_lk102Unlink($item, $rowId, $name) . '</div>' : '';
                $reasonDiv = function (string $reason): string {
                    return '<div style="color:#b91c1c;font-size:11px;margin-top:3px;white-space:normal;">' . h($reason) . '</div>';
                };
                $spId = function (array $rec): string {
                    return ' <span style="font-family:monospace;font-size:11px;color:#475569;">service plan #' . (int)($rec['service_plan_id'] ?? 0) . '</span>';
                };
                $recorded = function (array $rec) use ($item): string {
                    return '<div style="color:#dc2626;font-size:11px;margin-top:3px;white-space:normal;">as recorded at link time: '
                        . h((string)($rec['product_name'] ?? '')) . ($item === 'plan' ? ' · ' . h((string)($rec['service_plan_name'] ?? '')) : '') . '</div>';
                };
                if ($j['state'] === 'two') {
                    $ids = [];
                    foreach ($j['records'] as $r) $ids[] = $_lk102Prod((int)($r['product_id'] ?? 0));
                    return implode(' ', $ids) . $reasonDiv((string)$_lk102Ans($item, $row, true)['reason']) . $acts;
                }
                $rec = (array)$j['record'];
                $head = $_lk102Prod((int)($rec['product_id'] ?? 0)) . ($item === 'plan' ? $spId($rec) : '');
                if ($j['state'] !== 'ok') {   // replaced, or linked for another quoting organization
                    return $head . $recorded($rec) . $reasonDiv((string)$_lk102Ans($item, $row, true)['reason']) . $acts;
                }
                $ans = $_lk102Ans($item, $row, false);
                if (empty($ans['ok']) || !is_array($ans['line'] ?? null)) {
                    return $head . $recorded($rec) . $reasonDiv((string)($ans['reason'] ?? '')) . $acts;
                }
                $line = $ans['line'];
                $cur  = (string)$_lk102['cur'];
                $out  = $_lk102Prod((int)$line['productId']) . ' <span style="font-weight:600;">' . h((string)$line['label']) . '</span> · '
                      . $cur . number_format((float)$line['price'], 2);
                if ($item === 'plan') {
                    $sp = null;
                    $cat = $_lk102['res'] !== null ? $_lk102['res']->catalogue() : ['plans' => []];
                    foreach ((array)($cat['plans'] ?? []) as $p) {
                        if (is_array($p) && (int)($p['id'] ?? 0) === (int)($rec['service_plan_id'] ?? 0)) { $sp = $p; break; }
                    }
                    $out .= '<div style="font-size:11px;color:#475569;margin-top:2px;">service plan #' . (int)($rec['service_plan_id'] ?? 0) . ' '
                          . h(is_array($sp) ? (string)($sp['name'] ?? '') : '') . ' · ' . $cur . number_format((float)$line['price'], 2) . '/month</div>';
                }
                $out .= '<div style="font-size:11px;color:#166534;font-weight:700;margin-top:2px;">✓ quotes</div>'
                      . '<div style="font-size:10px;color:#64748b;">linked by ' . h((string)($rec['linked_by_name'] ?? '')) . ', '
                      . h(substr((string)($rec['linked_at'] ?? ''), 0, 10)) . '</div>';
                $others = [];
                foreach ((array)($_lk102['also'][(int)$line['productId']] ?? []) as $o) {
                    if ($item === 'install' || (int)$o[0] !== $rowId) $others[] = h($o[1]);
                }
                if ($others !== []) {
                    $out .= '<div style="font-size:11px;color:#92400e;margin-top:2px;white-space:normal;">also linked to: ' . implode(', ', $others) . '</div>';
                }
                return $out . $acts;
            };

            // The picker panel of one item (§R.2.3): a fresh read, the counted rows sorted by name, the unusable listed but
            // disabled with their reasons, twins marked by their own name key, nothing pre-selected but the item's own link
            // in force. Admins only; its form posts ucrm_link_save with csrfField() inside it.
            $_lk102Panel = function (string $item, ?array $row, int $rowId) use (&$_lk102, $_lk102Prod, $_lk102Unlink): string {
                $tab   = $item === 'plan' ? 'subscription_plans' : 'hardware';
                $name  = $item === 'install' ? 'Installation' : (string)(($row ?? [])[$item === 'plan' ? 'name' : 'title'] ?? '');
                $what  = $item === 'plan' ? 'plan' : ($item === 'kit' ? 'kit' : 'installation');
                $cur   = (string)$_lk102['cur'];
                $title = $item === 'install' ? 'Installation (when DishNet installs)' : ($name !== '' ? $name : ($item === 'plan' ? 'Plan #' : 'Hardware row #') . $rowId);
                $o  = '<div class="kyc-card" id="ucrm-link" style="border-left:4px solid #2563eb;">'
                    . '<div class="kyc-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">'
                    . '<span><i class="bi bi-link-45deg"></i> uCRM link — ' . h($title) . '</span>'
                    . '<a href="?page=dashboard&amp;tab=' . $tab . '" style="color:#6b7280;font-size:12px;">Close</a></div>'
                    . '<div class="kyc-card-body">';
                $end = '</div></div>';
                $note = function (string $text, string $fg): string {
                    return '<div style="font-size:12px;color:' . $fg . ';margin-bottom:8px;">' . h($text) . '</div>';
                };
                if ($item !== 'install' && $row === null) {
                    return $o . $note(($item === 'plan' ? 'Plan #' : 'Hardware row #') . $rowId . ' is not on this screen.', '#b91c1c') . $end;
                }
                if ($row !== null && UcrmQuoteLinks::isFiber($item, $row)) {
                    return $o . $note('Fiber: not priced from uCRM on this installation', '#6b7280') . $end;
                }
                if ($_lk102['links'] === null) return $o . $note('the uCRM links could not be read — nothing can be linked now; try again', '#b91c1c') . $end;
                $links = $_lk102['links'];
                $j = $links->judge($item, $row, $_lk102['org']);

                // The read: fresh, through the quoting client, with the page timeouts — never the 60 s page cache.
                $pick = null;
                $cat  = ['ok' => false];
                try {
                    $qc    = UcrmQuoteLines::quoteClient($_lk102['crm'], $_lk102['cfg']);
                    $short = (is_object($qc) && method_exists($qc, 'withTimeouts')) ? $qc->withTimeouts(UcrmQuoteLines::PAGE_CONNECT, UcrmQuoteLines::PAGE_TOTAL) : $qc;
                    $pick  = new UcrmQuoteLines($short, $_lk102['set'], $links);
                    $cat   = $pick->catalogue();
                } catch (\Throwable $e) {
                    $cat = ['ok' => false];
                }
                if (empty($cat['ok']) || $pick === null) {
                    $o .= $note('uCRM\'s products could not be read — nothing can be linked now; try again', '#b91c1c');
                } else {
                    $preP = $j['state'] === 'ok' ? (int)($j['record']['product_id'] ?? 0) : 0;
                    $preS = ($j['state'] === 'ok' && $item === 'plan') ? (int)($j['record']['service_plan_id'] ?? 0) : 0;
                    $rowKey = $item === 'install' ? '' : PublicPriceFeed::planKey($name);
                    $alike = function (array $a) use ($rowKey): int {
                        return ($rowKey !== '' && PublicPriceFeed::planKey((string)($a['name'] ?? '')) === $rowKey) ? 0 : 1;
                    };
                    $order = function (array $a, array $b) use ($alike): int {
                        if ($alike($a) !== $alike($b)) return $alike($a) - $alike($b);
                        $c = strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                        return $c !== 0 ? $c : ((int)($a['id'] ?? 0) - (int)($b['id'] ?? 0));
                    };
                    $mark = function (string $text, string $bg, string $fg): string {
                        return ' <span style="background:' . $bg . ';color:' . $fg . ';font-size:10px;padding:1px 6px;border-radius:8px;font-weight:600;">' . h($text) . '</span>';
                    };
                    $o .= '<form method="POST">' . csrfField()
                        . '<input type="hidden" name="action" value="ucrm_link_save">'
                        . '<input type="hidden" name="item" value="' . h($item) . '">'
                        . '<input type="hidden" name="row_id" value="' . (int)$rowId . '">';

                    // Products: the counted rows, those spelled like this item first, then by name
                    $prods = array_values(array_filter((array)($cat['products'] ?? []), 'is_array'));
                    $byKey = [];
                    foreach ($prods as $p) {
                        $k = PublicPriceFeed::planKey((string)($p['name'] ?? ''));
                        if ($k !== '') $byKey[$k][] = (int)($p['id'] ?? 0);
                    }
                    usort($prods, $order);
                    $o .= '<div style="font-weight:700;font-size:13px;margin:4px 0 6px;">Products in uCRM (' . count($prods) . ')</div>';
                    if ($rowKey !== '' && count($byKey[$rowKey] ?? []) >= 2) {
                        $o .= $note('Two products in uCRM are spelled like this ' . $what . ' — a hint only; pick by uCRM id.', '#92400e');
                    }
                    foreach ($prods as $p) {
                        $pid   = (int)($p['id'] ?? 0);
                        $pname = (string)($p['name'] ?? '');
                        $why   = $pick->productReason($item, $row, $pid, $pname);
                        $k     = PublicPriceFeed::planKey($pname);
                        $marks = '';
                        if ($rowKey !== '' && $k === $rowKey) $marks .= $mark('spelled like this ' . $what, '#dbeafe', '#1e40af');
                        $twins = $k !== '' ? array_values(array_diff($byKey[$k] ?? [], [$pid])) : [];
                        if ($twins !== []) $marks .= $mark('another product in uCRM is spelled the same (#' . implode(', #', array_map('intval', $twins)) . ')', '#fef3c7', '#92400e');
                        $price = $p['price'] ?? null;
                        $unit  = trim((string)($p['unit'] ?? ''));
                        $o .= '<label style="display:block;padding:6px 8px;border-bottom:1px solid #f1f5f9;font-size:12px;' . ($why !== '' ? 'color:#9ca3af;' : 'cursor:pointer;') . '">'
                            . '<input type="radio" name="product_id" value="' . $pid . '"' . ($pid === $preP ? ' checked' : '') . ($why !== '' ? ' disabled' : '') . ' required> '
                            . $_lk102Prod($pid) . ' <strong>' . h($pname) . '</strong> · '
                            . (is_numeric($price) ? $cur . number_format((float)$price, 2) : 'no price') . ($unit !== '' ? ' / ' . h($unit) : '')
                            . $marks . ($why !== '' ? '<div style="color:#b91c1c;font-size:11px;">' . h($why) . '</div>' : '') . '</label>';
                    }
                    if ($prods === []) $o .= $note('uCRM lists no product that counts here.', '#6b7280');

                    // Service plans (a plan only): the counted rows, twins marked by their own key over the full list
                    if ($item === 'plan') {
                        $plans = array_values(array_filter((array)($cat['plans'] ?? []), 'is_array'));
                        $keyOf = (array)($cat['plan_key_of'] ?? []);
                        usort($plans, $order);
                        $o .= '<div style="font-weight:700;font-size:13px;margin:14px 0 6px;">Service plans in uCRM (' . count($plans) . ')</div>';
                        foreach ($plans as $sp) {
                            $sid   = (int)($sp['id'] ?? 0);
                            $sname = (string)($sp['name'] ?? '');
                            $why   = $pick->servicePlanReason($sid, $sname);
                            $k     = PublicPriceFeed::planKey($sname);
                            $marks = '';
                            if ($rowKey !== '' && $k === $rowKey) $marks .= $mark('spelled like this plan', '#dbeafe', '#1e40af');
                            $twins = [];
                            if ($k !== '') {
                                foreach ($keyOf as $oid => $ok) if ((string)$ok === $k && (int)$oid !== $sid) $twins[] = (int)$oid;
                            }
                            if ($twins !== []) $marks .= $mark('another service plan in uCRM is spelled the same (#' . implode(', #', $twins) . ')', '#fef3c7', '#92400e');
                            $month = [];
                            foreach ((array)($sp['periods'] ?? []) as $per) {
                                if (is_array($per) && !empty($per['enabled']) && (int)($per['period'] ?? 0) === 1 && is_numeric($per['price'] ?? null)) $month[] = (float)$per['price'];
                            }
                            $o .= '<label style="display:block;padding:6px 8px;border-bottom:1px solid #f1f5f9;font-size:12px;' . ($why !== '' ? 'color:#9ca3af;' : 'cursor:pointer;') . '">'
                                . '<input type="radio" name="service_plan_id" value="' . $sid . '"' . ($sid === $preS ? ' checked' : '') . ($why !== '' ? ' disabled' : '') . ' required> '
                                . '<span style="font-family:monospace;font-size:11px;color:#475569;">#' . $sid . '</span> <strong>' . h($sname) . '</strong> · '
                                . (count($month) === 1 ? $cur . number_format($month[0], 2) . '/month' : 'no 1-month price')
                                . $marks . ($why !== '' ? '<div style="color:#b91c1c;font-size:11px;">' . h($why) . '</div>' : '') . '</label>';
                        }
                        if ($plans === []) $o .= $note('uCRM lists no service plan that counts here.', '#6b7280');
                    }
                    $o .= '<div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
                        . '<button type="submit" class="btn-kyc-submit" style="padding:8px 16px;font-size:13px;">Save the link</button>'
                        . '<span style="font-size:11px;color:#64748b;">Nothing is pre-selected but this item\'s own current link. uCRM is read again when you save.</span>'
                        . '</div></form>';
                }
                if ($j['records'] !== []) {
                    $o .= '<div style="margin-top:10px;">' . $_lk102Unlink($item, $rowId, $name) . '</div>';
                }

                // The item's history: every ended record, with what was quoted under it
                $hist = $links->history($item, $rowId);
                if ($hist !== []) {
                    $o .= '<div style="font-weight:700;font-size:13px;margin:16px 0 6px;">Earlier links (' . count($hist) . ')</div>';
                    foreach (array_reverse($hist) as $hr) {
                        $hid = (int)($hr['id'] ?? 0);
                        try {
                            $n = UcrmQuoteLinks::countQuoted($_lk102['store'], $_lk102['set'], [$hid]);
                            $q = ' · quoted under it ' . (int)$n['cash'] . ' Cash, ' . (int)$n['credit'] . ' Credit';
                        } catch (\Throwable $e) {
                            $q = ' · what was quoted under it could not be counted';
                        }
                        $o .= '<div style="font-size:11px;color:#475569;padding:4px 0;border-bottom:1px solid #f8fafc;">'
                            . $_lk102Prod((int)($hr['product_id'] ?? 0)) . ' ' . h((string)($hr['product_name'] ?? ''))
                            . ($item === 'plan' ? ' · service plan #' . (int)($hr['service_plan_id'] ?? 0) . ' ' . h((string)($hr['service_plan_name'] ?? '')) : '')
                            . ' — linked by ' . h((string)($hr['linked_by_name'] ?? '')) . ', from ' . h((string)($hr['linked_at'] ?? ''))
                            . ' to ' . h((string)($hr['ended_at'] ?? '')) . ' — ' . h((string)($hr['ended_why'] ?? '')) . h($q)
                            . ' · <a href="?page=dashboard&amp;tab=applications&amp;quote_link=' . $hid . '">Orders</a></div>';
                    }
                }
                return $o . $end;
            };

            // This screen's rows: the "also linked to" map and the badge's count (§R.2.2).
            $_lk102AlsoAdd('kit', (array)$allHW);
            $_lk102Count = $_lk102Unlinked('kit', (array)$allHW);
        }
    ?>

<div class="stat-grid">
    <div class="stat-card teal"><div class="stat-label">Total Hardware</div><div class="stat-value"><?= count($allHW) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Starlink Kits</div><div class="stat-value"><?= count($hwByType['starlink']??[]) ?></div></div>
    <div class="stat-card green"><div class="stat-label">Fiber Equipment</div><div class="stat-value"><?= count($hwByType['fiber']??[]) ?></div></div>
    <div class="stat-card orange"><div class="stat-label">General / Accessories</div><div class="stat-value"><?= count($hwByType['general']??[]) ?></div></div>
</div>

<!-- Add/Edit Hardware -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-plus-circle"></i> <?= $editHW ? 'Edit Hardware' : 'Add Hardware' ?></div>
    <div class="kyc-card-body">
        <form method="POST">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="save_hardware">
            <?php if ($editHW): ?><input type="hidden" name="hw_id" value="<?= $editHW['id'] ?>"><?php endif; ?>
            <div class="resp-grid-6" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 80px;gap:12px;align-items:end;">
                <div>
                    <label class="form-label">Equipment Name *</label>
                    <input type="text" name="hw_title" class="form-control" value="<?= h($editHW['title'] ?? '') ?>" required placeholder="e.g. WiFi ONU, Starlink Mini Kit">
                </div>
                <div>
                    <label class="form-label">Type</label>
                    <select name="hw_type" class="form-control">
                        <option value="starlink" <?= ($editHW['type']??'')==='starlink'?'selected':'' ?>>Starlink</option>
                        <option value="fiber" <?= ($editHW['type']??'')==='fiber'?'selected':'' ?>>Fiber</option>
                        <option value="general" <?= ($editHW['type']??'')==='general'?'selected':'' ?>>General / Accessories</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Buy Price ($)</label>
                    <input type="number" name="hw_buy" class="form-control" step="0.01" min="0" value="<?= $editHW['buy_price'] ?? '' ?>" placeholder="0.00">
                </div>
                <div>
                    <label class="form-label">Sell Price ($) *</label>
                    <input type="number" name="hw_sell" class="form-control" step="0.01" min="0" value="<?= $editHW['sell_price'] ?? '' ?>" required placeholder="0.00">
                </div>
                <div style="display:flex;align-items:center;padding-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;">
                        <input type="checkbox" name="hw_active" <?= ($editHW['is_active'] ?? true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#28a745;">
                        Active
                    </label>
                </div>
                <div>
                    <button type="submit" class="btn-kyc-submit" style="padding:10px 14px;font-size:13px;width:100%;">
                        <?= $editHW ? 'Update' : 'Add' ?>
                    </button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:10px;">
                <div>
                    <label class="form-label">SKU / Product Code</label>
                    <input type="text" name="hw_sku" class="form-control" value="<?= h($editHW['sku'] ?? '') ?>" placeholder="e.g. GN-233, SL-MINI">
                </div>
<?php if ($_lk102 === null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2, Q8): not drawn where the gate is on — save_hardware ignores it there */ ?>
                <div>
                    <label class="form-label">UCRM Product ID</label>
                    <input type="number" name="hw_ucrm_product_id" class="form-control" min="0" value="<?= (int)($editHW['ucrm_product_id'] ?? 0) ?>" placeholder="0" style="font-family:monospace;">
                </div>
<?php endif; ?>
                <div>
                    <label class="form-label">Description</label>
                    <input type="text" name="hw_description" class="form-control" value="<?= h($editHW['description'] ?? '') ?>" placeholder="Short product description">
                </div>
            </div>
            <?php if ($editHW): ?><div style="margin-top:6px;"><a href="?page=dashboard&tab=hardware" style="color:#6b7280;font-size:12px;">Cancel</a></div><?php endif; ?>
        </form>
    </div>
</div>

<?php if ($_lk102 !== null && !empty($_lk102['admin']) && (isset($_GET['ucrm_link_hw']) || ($_GET['ucrm_link_install'] ?? null) === '1')): /* 5.18.102 (Uganda, D14, docs/71 §R.2.3, §R.2.4): one kit's, or the installation's, picker panel — admins only */
    $_lk102Item = isset($_GET['ucrm_link_hw']) ? 'kit' : 'install';
    $_lk102Want = ($_lk102Item === 'kit' && is_string($_GET['ucrm_link_hw']) && ctype_digit($_GET['ucrm_link_hw']) && strlen($_GET['ucrm_link_hw']) <= 9) ? (int)$_GET['ucrm_link_hw'] : 0;
    $_lk102Row  = null;
    if ($_lk102Item === 'kit') { foreach ((array)$allHW as $_lk102H) { if (is_array($_lk102H) && $_lk102Want > 0 && (int)($_lk102H['id'] ?? 0) === $_lk102Want) { $_lk102Row = $_lk102H; break; } } } ?>
<?= $_lk102Panel($_lk102Item, $_lk102Row, $_lk102Want) ?>
<?php endif; ?>
<!-- Starlink Hardware -->
<div class="kyc-card">
    <div class="kyc-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span><i class="bi bi-broadcast"></i> Starlink Hardware</span>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the active kits not linked; no Sync buttons beside a link picker */ ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <?php if ($_lk102Count > 0): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">⚠️ <?= (int)$_lk102Count ?> active item(s) not linked</span>
            <?php endif; ?>
        </div>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
        <?php $hwUnmapped = count(array_filter($allHW, fn($h2) => empty($h2['ucrm_product_id']) && !empty($h2['is_active']))); ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <?php if ($hwUnmapped > 0): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">⚠️ <?= $hwUnmapped ?> not synced</span>
            <?php else: ?>
            <span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">✅ All synced</span>
            <?php endif; ?>
            <button onclick="bulkSyncHardware()" style="background:#2563eb;color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;">🔄 Sync All to UCRM</button>
        </div>
<?php endif; ?>
    </div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the "uCRM link" column */ ?>
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="min-width:200px;">uCRM link</th><th style="text-align:center;">Shown to customers</th><th>Status</th><th>Actions</th></tr></thead>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="text-align:center;">UCRM</th><th style="text-align:center;">Shown to customers</th><th>Status</th><th>Actions</th></tr></thead>
<?php endif; ?>
            <tbody>
            <?php foreach ($hwByType['starlink']??[] as $hw):
                $hwProfit = ($hw['sell_price']??0) - ($hw['buy_price']??0);
                $hwMargin = ($hw['sell_price']??0) > 0 ? round($hwProfit / $hw['sell_price'] * 100, 1) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($hw['title']) ?><?php if(!empty($hw['sku'])): ?> <span style="font-size:10px;background:#e8f4fd;color:#1565c0;padding:1px 5px;border-radius:3px;font-family:monospace;"><?= h($hw['sku']) ?></span><?php endif; ?><br><span style="font-size:11px;color:#6b7280;"><?= h($hw['price']??'') ?><?php if(!empty($hw['description'])): ?> — <?= h($hw['description']) ?><?php endif; ?></span></td>
                <td style="text-align:right;color:#dc3545;"><?= $curSym ?><?= number_format($hw['buy_price']??0, 2) ?></td>
                <td style="text-align:right;color:#28a745;font-weight:700;"><?= $curSym ?><?= number_format($hw['sell_price']??0, 2) ?></td>
                <td style="text-align:right;font-weight:600;"><?= $curSym ?><?= number_format($hwProfit, 2) ?> <span style="font-size:10px;color:#6b7280;">(<?= $hwMargin ?>%)</span></td>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the kit's link, as the resolver judges it now */ ?>
                <td style="font-size:12px;"><?= $_lk102Cell('kit', is_array($hw) ? $hw : []) ?></td>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
                <td style="text-align:center;">
                    <?php if (!empty($hw['ucrm_product_id'])): ?>
                        <a href="<?= dn_crm_web($config) ?>/crm/system/items/products/<?= (int)$hw['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$hw['ucrm_product_id'] ?> 🔗</a>
                    <?php else: ?>
                        <button onclick="syncHw(<?= (int)$hw['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                    <?php endif; ?>
                </td>
<?php endif; ?>
                <?php
                  $_mk   = MediaLibrary::key((string)($hw['title'] ?? ''));
                  $_pho  = $_mk !== '' ? ($_hwPhotos[$_mk] ?? null) : null;
                  $_doc  = $_mk !== '' ? ($_hwDocs[$_mk]   ?? null) : null;
                ?>
                <td style="text-align:center;font-size:11px;white-space:nowrap;">
                  <?php foreach ([['image','Photo',$_pho],['document','PDF',$_doc]] as $_m): ?>
                    <?php
                      list($_mKind,$_mLabel,$_mItem) = $_m;
                      $_cap = $_mItem !== null ? (string)($_mItem['caption'] ?? '') : '';
                    ?>
                    <?php if ($_mItem !== null): ?>
                      <span style="display:inline-block;white-space:nowrap;margin:0 3px;">
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove the <?= $_mLabel ?> for <?= h($hw['title']) ?>?');">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="delete_hw_media">
                          <input type="hidden" name="media_kind" value="<?= $_mKind ?>">
                          <input type="hidden" name="media_name" value="<?= h($_mk) ?>">
                          <button type="submit" title="Remove the <?= $_mLabel ?> — the assistant will stop sending it"
                            style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:5px;padding:2px 7px 2px 7px;font-size:11px;font-weight:700;cursor:pointer;">&#10003; <?= $_mLabel ?></button>
                        </form><form method="POST" style="display:inline" onsubmit="return dnCaption(this);">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="caption_hw_media">
                          <input type="hidden" name="media_kind" value="<?= $_mKind ?>">
                          <input type="hidden" name="media_name" value="<?= h($_mk) ?>">
                          <input type="hidden" name="media_caption" value="<?= h($_cap) ?>">
                          <input type="hidden" name="dn_label" value="<?= h($hw['title']) ?> — <?= $_mLabel ?>">
                          <button type="submit"
                            title="<?= $_cap !== ''
                                    ? 'Sent with: ' . h($_cap)
                                    : 'No caption — this ' . strtolower($_mLabel) . ' arrives on its own. Click to write one.' ?>"
                            style="background:<?= $_cap !== '' ? '#eff6ff' : '#fef3c7' ?>;color:<?= $_cap !== '' ? '#1d4ed8' : '#92400e' ?>;border:1px solid <?= $_cap !== '' ? '#bfdbfe' : '#fde68a' ?>;border-left:none;border-radius:0 5px 5px 0;margin-left:-4px;padding:2px 6px;font-size:11px;font-weight:700;cursor:pointer;"><?= $_cap !== '' ? '&#9998;' : '&#9888;' ?></button>
                        </form>
                      </span>
                    <?php else: ?>
                      <label style="display:inline-block;background:#f3f4f6;color:#6b7280;border:1px dashed #d1d5db;border-radius:5px;padding:2px 7px;margin:0 3px;font-weight:600;cursor:pointer;"
                             title="Upload a <?= $_mLabel ?> for <?= h($hw['title']) ?>">+ <?= $_mLabel ?>
                        <form method="POST" enctype="multipart/form-data" style="display:none">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="upload_hw_media">
                          <input type="hidden" name="media_kind" value="<?= $_mKind ?>">
                          <input type="hidden" name="hw_title" value="<?= h($hw['title']) ?>">
                          <input type="hidden" name="media_caption" value="">
                          <input type="hidden" name="dn_label" value="<?= h($hw['title']) ?> — <?= $_mLabel ?>">
                          <input type="file" name="media_file"
                                 accept="<?= $_mKind === 'document' ? '.pdf' : '.jpg,.jpeg,.png,.webp' ?>"
                                 onchange="dnCaptionOnUpload(this.form); this.form.submit();">
                        </form>
                      </label>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </td>
                <td><?= !empty($hw['is_active']) ? '<span class="badge-approved">Active</span>' : '<span class="badge-rejected">Inactive</span>' ?></td>
                <td>
                    <a href="?page=dashboard&tab=hardware&edit_hw=<?= $hw['id'] ?>" style="color:#D41C1C;font-size:11px;font-weight:600;text-decoration:none;margin-right:8px;"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_hardware"><input type="hidden" name="hw_id" value="<?= $hw['id'] ?>"><button type="submit" style="background:none;border:none;color:#dc3545;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i></button></form>
                    <?= csrfField() ?>
                </td>
            </tr>
            <?php endforeach; if(empty($hwByType['starlink']??[])): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px;">No Starlink hardware.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.4, Q1): the installation line's uCRM product — linked like a kit, never found by its name */ ?>
<div class="kyc-card" style="border-left:4px solid #2563eb;">
    <div class="kyc-card-header"><span><i class="bi bi-tools"></i> Installation (when DishNet installs)</span></div>
    <div class="kyc-card-body">
        <div style="font-size:12px;color:#475569;margin-bottom:8px;">The line a Uganda quotation carries when the answer to "Who installs?" is DishNet. It is priced from the uCRM product linked here, never found by its name, and it may not share a product with a kit the KYC form offers.</div>
        <div style="font-size:12px;"><?= $_lk102Cell('install', null) ?></div>
    </div>
</div>
<?php endif; ?>
<!-- Fiber Installation Fee (separate config — auto-added to every Fiber quote) -->
<div class="kyc-card" style="border-left:4px solid #059669;">
    <div class="kyc-card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span><i class="bi bi-tools"></i> Fiber Installation Fee <span style="font-size:11px;color:#059669;font-weight:600;">(auto-added to every Fiber KYC quote)</span></span>
    </div>
    <div class="kyc-card-body">
        <form method="POST" style="display:flex;flex-wrap:wrap;gap:14px;align-items:end;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_install_fee">
            <div>
                <label class="form-label">Installation Fee ($)</label>
                <input type="number" name="fiber_install_fee" class="form-control" step="0.01" min="0" value="<?= (float)($config['fiber_install_fee'] ?? 100) ?>" style="width:140px;font-size:18px;font-weight:900;">
            </div>
            <div>
                <label class="form-label">UCRM Product ID <small style="color:#64748b;">(links to CRM product catalog)</small></label>
                <input type="number" name="fiber_install_product_id" class="form-control" min="0" value="<?= (int)($config['fiber_install_product_id'] ?? 244) ?>" style="width:140px;font-family:monospace;">
            </div>
            <div>
                <?php $_instProdId = (int)($config['fiber_install_product_id'] ?? 244); ?>
                <?php if ($_instProdId > 0): ?>
                <a href="<?= dn_crm_web($config) ?>/crm/system/items/products/<?= $_instProdId ?>" target="_blank" style="font-family:monospace;font-size:12px;background:#eff6ff;color:#1d4ed8;padding:6px 12px;border-radius:8px;font-weight:700;text-decoration:none;display:inline-block;">
                    #<?= $_instProdId ?> 🔗 View in CRM
                </a>
                <?php endif; ?>
            </div>
            <div>
                <button type="submit" class="btn-kyc-submit" style="padding:10px 18px;font-size:13px;">💾 Save</button>
            </div>
        </form>
        <div style="font-size:11px;color:#64748b;margin-top:8px;">
            Currently: <strong><?= $curSym ?><?= number_format((float)($config['fiber_install_fee'] ?? 100), 2) ?></strong> added to every Fiber quote as "Installation Fee" line item.
            UCRM Product #<?= (int)($config['fiber_install_product_id'] ?? 244) ?> is used for inventory/invoice linking.
        </div>
    </div>
</div>

<!-- Fiber Hardware -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-ethernet"></i> Fiber Equipment</div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the "uCRM link" column */ ?>
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="min-width:200px;">uCRM link</th><th>Status</th><th>Actions</th></tr></thead>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="text-align:center;">UCRM</th><th>Status</th><th>Actions</th></tr></thead>
<?php endif; ?>
            <tbody>
            <?php foreach ($hwByType['fiber']??[] as $hw):
                $hwProfit = ($hw['sell_price']??0) - ($hw['buy_price']??0);
                $hwMargin = ($hw['sell_price']??0) > 0 ? round($hwProfit / $hw['sell_price'] * 100, 1) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($hw['title']) ?><?php if(!empty($hw['sku'])): ?> <span style="font-size:10px;background:#e8f4fd;color:#1565c0;padding:1px 5px;border-radius:3px;font-family:monospace;"><?= h($hw['sku']) ?></span><?php endif; ?><br><span style="font-size:11px;color:#6b7280;"><?= h($hw['price']??'') ?><?php if(!empty($hw['description'])): ?> — <?= h($hw['description']) ?><?php endif; ?></span></td>
                <td style="text-align:right;color:#dc3545;"><?= $curSym ?><?= number_format($hw['buy_price']??0, 2) ?></td>
                <td style="text-align:right;color:#28a745;font-weight:700;"><?= $curSym ?><?= number_format($hw['sell_price']??0, 2) ?></td>
                <td style="text-align:right;font-weight:600;"><?= $curSym ?><?= number_format($hwProfit, 2) ?> <span style="font-size:10px;color:#6b7280;">(<?= $hwMargin ?>%)</span></td>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the kit's link, as the resolver judges it now */ ?>
                <td style="font-size:12px;"><?= $_lk102Cell('kit', is_array($hw) ? $hw : []) ?></td>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
                <td style="text-align:center;">
                    <?php if (!empty($hw['ucrm_product_id'])): ?>
                        <a href="<?= dn_crm_web($config) ?>/crm/system/items/products/<?= (int)$hw['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$hw['ucrm_product_id'] ?> 🔗</a>
                    <?php else: ?>
                        <button onclick="syncHw(<?= (int)$hw['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                    <?php endif; ?>
                </td>
<?php endif; ?>
                <td><?= !empty($hw['is_active']) ? '<span class="badge-approved">Active</span>' : '<span class="badge-rejected">Inactive</span>' ?></td>
                <td>
                    <a href="?page=dashboard&tab=hardware&edit_hw=<?= $hw['id'] ?>" style="color:#D41C1C;font-size:11px;font-weight:600;text-decoration:none;margin-right:8px;"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_hardware"><input type="hidden" name="hw_id" value="<?= $hw['id'] ?>"><button type="submit" style="background:none;border:none;color:#dc3545;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i></button></form>
                    <?= csrfField() ?>
                </td>
            </tr>
            <?php endforeach; if(empty($hwByType['fiber']??[])): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;">No Fiber equipment.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div><!-- General / Accessories -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-tools"></i> General Equipment & Accessories</div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the "uCRM link" column */ ?>
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="min-width:200px;">uCRM link</th><th>Status</th><th>Actions</th></tr></thead>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="text-align:center;">UCRM</th><th>Status</th><th>Actions</th></tr></thead>
<?php endif; ?>
            <tbody>
            <?php foreach ($hwByType['general']??[] as $hw):
                $hwProfit = ($hw['sell_price']??0) - ($hw['buy_price']??0);
                $hwMargin = ($hw['sell_price']??0) > 0 ? round($hwProfit / $hw['sell_price'] * 100, 1) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($hw['title']) ?><?php if(!empty($hw['sku'])): ?> <span style="font-size:10px;background:#e8f4fd;color:#1565c0;padding:1px 5px;border-radius:3px;font-family:monospace;"><?= h($hw['sku']) ?></span><?php endif; ?><br><span style="font-size:11px;color:#6b7280;"><?= h($hw['price']??'') ?><?php if(!empty($hw['description'])): ?> — <?= h($hw['description']) ?><?php endif; ?></span></td>
                <td style="text-align:right;color:#dc3545;"><?= $curSym ?><?= number_format($hw['buy_price']??0, 2) ?></td>
                <td style="text-align:right;color:#28a745;font-weight:700;"><?= $curSym ?><?= number_format($hw['sell_price']??0, 2) ?></td>
                <td style="text-align:right;font-weight:600;"><?= $curSym ?><?= number_format($hwProfit, 2) ?> <span style="font-size:10px;color:#6b7280;">(<?= $hwMargin ?>%)</span></td>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the kit's link, as the resolver judges it now */ ?>
                <td style="font-size:12px;"><?= $_lk102Cell('kit', is_array($hw) ? $hw : []) ?></td>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
                <td style="text-align:center;">
                    <?php if (!empty($hw['ucrm_product_id'])): ?>
                        <a href="<?= dn_crm_web($config) ?>/crm/system/items/products/<?= (int)$hw['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$hw['ucrm_product_id'] ?> 🔗</a>
                    <?php else: ?>
                        <button onclick="syncHw(<?= (int)$hw['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                    <?php endif; ?>
                </td>
<?php endif; ?>
                <td><?= !empty($hw['is_active']) ? '<span class="badge-approved">Active</span>' : '<span class="badge-rejected">Inactive</span>' ?></td>
                <td>
                    <a href="?page=dashboard&tab=hardware&edit_hw=<?= $hw['id'] ?>" style="color:#D41C1C;font-size:11px;font-weight:600;text-decoration:none;margin-right:8px;"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_hardware"><input type="hidden" name="hw_id" value="<?= $hw['id'] ?>"><button type="submit" style="background:none;border:none;color:#dc3545;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i></button></form>
                    <?= csrfField() ?>
                </td>
            </tr>
            <?php endforeach; if(empty($hwByType['general']??[])): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;">No general equipment.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const _HW_TK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';
// A photo or PDF is sent WITH its caption. Without one the customer gets a
// bare image, or a PDF attachment with nothing telling them what it is.
function dnCaptionText(form) {
    return (form.querySelector('input[name="dn_label"]') || {}).value || 'this file';
}

// On upload: ask, but never lose the file they just chose if they cancel.
function dnCaptionOnUpload(form) {
    var answer = window.prompt(
        'Caption for ' + dnCaptionText(form) + '\n\n' +
        'This is the message sent with the file. Leave it empty to send the\n' +
        'file on its own — you can add one later from this screen.',
        '');
    form.querySelector('input[name="media_caption"]').value = answer === null ? '' : answer;
    return true;
}

// On edit: cancelling means leave it exactly as it is.
function dnCaption(form) {
    var field  = form.querySelector('input[name="media_caption"]');
    var answer = window.prompt(
        'Caption for ' + dnCaptionText(form) + '\n\n' +
        'This is the message sent with the file. Clear it to send the file\n' +
        'on its own.',
        field.value);
    if (answer === null) return false;
    field.value = answer;
    return true;
}

function syncHw(hwId, btn) {
    btn.disabled = true; btn.textContent = '⏳';
    fetch('?page=api&action=sync_hardware_to_ucrm', {
          credentials:'same-origin',
          method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _HW_TK },
        body: JSON.stringify({ hw_id: hwId })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.data && d.data.ucrm_product_id) {
            btn.outerHTML = '<a href="' + <?= json_encode(dn_crm_web($config) . '/crm/system/items/products/') ?> + d.data.ucrm_product_id + '" target="_blank" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#' + d.data.ucrm_product_id + ' 🔗</a>';
        } else {
            btn.textContent = '❌'; btn.disabled = false;
            alert('Sync failed: ' + (d.message || 'Unknown error'));
        }
    }).catch(function(){ btn.textContent = '❌'; btn.disabled = false; });
}
function bulkSyncHardware() {
    if (!confirm('Sync all unmapped hardware to UCRM products now?')) return;
    fetch('?page=api&action=push_products_to_ucrm&dry_run=0', {
        headers: { 'Authorization': 'Bearer ' + _HW_TK }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
<?php /* 5.18.102 (Uganda, docs/71 §K.3, D12): the push is refused (409) where the quoting-organization gate is on — say so, never "Done". Emitted only there */ require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php'; if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']): ?>
        if (d.status === 'error') { alert(d.message || 'Not done.'); return; }
<?php endif; ?>
        var s = (d.data||d).summary || {};
        alert('✅ Done!\nCreated: ' + (s.total_created||0) + '\nSkipped: ' + (s.total_skipped||0) + '\nFailed: ' + (s.total_failed||0) + '\n\nRefreshing…');
        window.location.reload();
    }).catch(function(e){ alert('Error: ' + e.message); });
}
</script>





