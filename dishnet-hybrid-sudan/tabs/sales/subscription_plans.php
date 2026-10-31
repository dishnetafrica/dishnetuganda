<?php
// Currency label for every money figure on this screen. The Sudan/SS
// builds hardcoded '$'; the amounts themselves are whatever currency the
// uCRM organisation bills in, so the label now follows config.
$curSym = htmlspecialchars(trim((string)(($config['currency_symbol'] ?? '') ?: 'UGX'))) . ' ';
?>
<?php
// Tab: subscription_plans
// Extracted from public.php on 2026-03-15
        $allPlans = $store->load('subscription_plans.json');
        $editPlan = null;
        $editId = (int)($_GET['edit_plan'] ?? 0);
        if ($editId) { foreach ($allPlans as $ep) { if ((int)($ep['id']??0)===$editId) { $editPlan=$ep; break; } } }
        // Group by type
        $byType = ['starlink'=>[],'fiber'=>[],'sim'=>[]];
        foreach ($allPlans as $p) { $t = strtolower($p['type']??'starlink'); $byType[$t][] = $p; }
        $filterType = $_GET['plan_filter'] ?? 'all';
        $filteredPlans = $filterType === 'all' ? $allPlans : ($byType[$filterType] ?? []);
        $totalProfit = array_sum(array_map(fn($p)=>$p['profit']??0, array_filter($allPlans, fn($p)=>!empty($p['is_active']))));
        $activePlans = count(array_filter($allPlans, fn($p)=>!empty($p['is_active'])));
        $typeLabels = ['starlink'=>['Starlink','Starlink charges you','#E3F2FD','#1565C0'],'fiber'=>['Fiber','Supplier cost','#E8F5E9','#2E7D32'],'sim'=>['SIM','SIM network','#FFF3E0','#E65100']/* hidden */];
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
                error_log('[subscription_plans] 5.18.102: the uCRM links could not be drawn (' . get_class($e) . ')');
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
            $_lk102AlsoAdd('plan', (array)$allPlans);
            $_lk102Count = $_lk102Unlinked('plan', (array)$allPlans);
        }
    ?>

<!-- Stats per type -->
<div class="stat-grid">
    <div class="stat-card teal">
        <div class="stat-label">Starlink Plans</div>
        <div class="stat-value"><?= count($byType['starlink']) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $curSym ?><?= number_format(array_sum(array_map(fn($p)=>$p['profit']??0,$byType['starlink'])),2) ?> profit/mo</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Fiber Plans</div>
        <div class="stat-value"><?= count($byType['fiber']) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $curSym ?><?= number_format(array_sum(array_map(fn($p)=>$p['profit']??0,$byType['fiber'])),2) ?> profit/mo</div>
    </div>
    <div class="stat-card orange">
        <?php /* SIM Plans stat hidden */ ?>
        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $curSym ?><?= number_format(array_sum(array_map(fn($p)=>$p['profit']??0,$byType['sim'])),2) ?> profit/mo</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Total Monthly Profit</div>
        <div class="stat-value"><?= $curSym ?><?= number_format($totalProfit, 2) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $activePlans ?> active plans • <?php $mgs=array_filter(array_map(fn($p)=>$p['margin']??0,$allPlans)); echo $mgs ? round(array_sum($mgs)/count($mgs),1) : 0; ?>% avg margin</div>
    </div>
</div>

<!-- Add/Edit Plan Form -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-plus-circle"></i> <?= $editPlan ? 'Edit Plan #'.$editPlan['id'] : 'Add New Plan' ?></div>
    <div class="kyc-card-body">
        <form method="POST" id="planForm">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="save_plan">
            <?php if ($editPlan): ?><input type="hidden" name="plan_id" value="<?= $editPlan['id'] ?>"><?php endif; ?>
            <div class="resp-grid-5" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label class="form-label">Plan Name *</label>
                    <input type="text" name="plan_name" class="form-control" value="<?= h($editPlan['name'] ?? '') ?>" required placeholder="e.g. FIBER:100 - 60Mbps">
                </div>
                <div>
                    <label class="form-label">Service Type</label>
                    <select name="plan_type" class="form-control" id="planTypeSelect" onchange="planTypeChanged()">
                        <option value="starlink" <?= ($editPlan['type']??'')==='starlink'?'selected':'' ?>>Starlink</option>
                        <option value="fiber" <?= ($editPlan['type']??'')==='fiber'?'selected':'' ?>>Fiber</option>
                
                    </select>
                </div>
                <div>
                    <label class="form-label" id="costLabel">Your Cost *</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:9px;color:#6b7280;font-weight:700;font-size:11px;"><?= $curSym ?></span>
                        <input type="number" name="starlink_cost" class="form-control" step="0.01" min="0" style="padding-left:46px;"
                               value="<?= $editPlan['starlink_cost'] ?? '' ?>" required placeholder="0.00" oninput="calcPlanProfit()">
                    </div>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;" id="costHint">What Starlink charges you</div>
                </div>
                <div>
                    <label class="form-label">Customer Price *</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:9px;color:#6b7280;font-weight:700;font-size:11px;"><?= $curSym ?></span>
                        <input type="number" name="customer_price" class="form-control" step="0.01" min="0" style="padding-left:46px;"
                               value="<?= $editPlan['customer_price'] ?? '' ?>" required placeholder="0.00" oninput="calcPlanProfit()">
                    </div>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">What you charge customer</div>
                </div>
                <div>
                    <label class="form-label">Speed</label>
                    <input type="text" name="plan_speed" class="form-control" value="<?= h($editPlan['speed'] ?? '') ?>" placeholder="e.g. 60Mbps">
                </div>
            </div>
            <div class="resp-grid-5b" style="display:grid;grid-template-columns:1fr 1fr 60px 80px 100px;gap:12px;align-items:end;">
                <div>
                    <label class="form-label">Supplier / Provider</label>
                    <input type="text" name="plan_supplier" class="form-control" value="<?= h($editPlan['supplier'] ?? '') ?>" placeholder="e.g. 4G Telecom, Starlink">
                </div>
                <div id="planProfitPreview" style="padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:13px;font-weight:600;">
                    Profit: <span id="ppProfit" style="color:#28a745;"><?= $curSym ?>0.00</span> /mo
                    &nbsp;|&nbsp; Margin: <span id="ppMargin" style="color:#D41C1C;">0%</span>
                </div>
                <div>
                    <label class="form-label">Color</label>
                    <input type="color" name="plan_color" value="<?= h($editPlan['color'] ?? '#2196F3') ?>" style="width:100%;height:38px;border:1.5px solid #dee2e6;border-radius:6px;padding:2px;cursor:pointer;">
                </div>
                <div style="display:flex;align-items:center;padding-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;">
                        <input type="checkbox" name="plan_active" <?= ($editPlan['is_active'] ?? true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#28a745;">
                        Active
                    </label>
                </div>
                <div>
                    <button type="submit" class="btn-kyc-submit" style="padding:10px 18px;font-size:13px;width:100%;">
                        <i class="bi bi-<?= $editPlan ? 'pencil' : 'plus-circle' ?>"></i> <?= $editPlan ? 'Update' : 'Add' ?>
                    </button>
                </div>
            </div>
            <?php if ($editPlan): ?>
            <div style="margin-top:8px;"><a href="?page=dashboard&tab=subscription_plans" style="color:#6b7280;font-size:12px;">Cancel editing</a></div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($_lk102 !== null && !empty($_lk102['admin']) && isset($_GET['ucrm_link_plan'])): /* 5.18.102 (Uganda, D14, docs/71 §R.2.3): one plan's picker panel, admins only */
    $_lk102Want = (is_string($_GET['ucrm_link_plan']) && ctype_digit($_GET['ucrm_link_plan']) && strlen($_GET['ucrm_link_plan']) <= 9) ? (int)$_GET['ucrm_link_plan'] : 0;
    $_lk102Row  = null;
    foreach ((array)$allPlans as $_lk102P) { if (is_array($_lk102P) && $_lk102Want > 0 && (int)($_lk102P['id'] ?? 0) === $_lk102Want) { $_lk102Row = $_lk102P; break; } } ?>
<?= $_lk102Panel('plan', $_lk102Row, $_lk102Want) ?>
<?php endif; ?>
<!-- Filter Tabs -->
<div class="kyc-card">
    <div style="display:flex;align-items:center;gap:0;border-bottom:2px solid #f1f5f9;">
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=all"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='all'?'#2196F3':'transparent' ?>;color:<?= $filterType==='all'?'#2196F3':'#6b7280' ?>;margin-bottom:-2px;">
            All Plans <span style="background:#f1f5f9;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($allPlans) ?></span>
        </a>
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=starlink"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='starlink'?'#2196F3':'transparent' ?>;color:<?= $filterType==='starlink'?'#2196F3':'#6b7280' ?>;margin-bottom:-2px;">
            Starlink <span style="background:#fff5f5;color:#D41C1C;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($byType['starlink']) ?></span>
        </a>
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=fiber"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='fiber'?'#2E7D32':'transparent' ?>;color:<?= $filterType==='fiber'?'#2E7D32':'#6b7280' ?>;margin-bottom:-2px;">
            Fiber <span style="background:#E8F5E9;color:#2E7D32;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($byType['fiber']) ?></span>
        </a>
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=sim"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='sim'?'#E65100':'transparent' ?>;color:<?= $filterType==='sim'?'#E65100':'#6b7280' ?>;margin-bottom:-2px;">
            SIM <span style="background:#FFF3E0;color:#E65100;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($byType['sim']) ?></span>
        </a>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the active items not linked; no Sync buttons beside a link picker */ ?>
        <div style="margin-left:auto;padding:8px 14px;display:flex;align-items:center;gap:8px;">
            <?php if ($_lk102Count > 0): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                ⚠️ <?= (int)$_lk102Count ?> active item(s) not linked
            </span>
            <?php endif; ?>
        </div>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
        <?php
        $unmappedCount = count(array_filter($allPlans, fn($p) => empty($p['ucrm_product_id']) && !empty($p['is_active'])));
        ?>
        <div style="margin-left:auto;padding:8px 14px;display:flex;align-items:center;gap:8px;">
            <?php if ($unmappedCount > 0): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                ⚠️ <?= $unmappedCount ?> not synced to UCRM
            </span>
            <?php else: ?>
            <span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                ✅ All synced to UCRM
            </span>
            <?php endif; ?>
            <button onclick="bulkSyncPlans()" style="background:#2563eb;color:#fff;border:none;border-radius:7px;padding:6px 13px;font-size:12px;font-weight:600;cursor:pointer;">
                🔄 Sync All to UCRM
            </button>
        </div>
<?php endif; ?>
    </div>
    <div style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead>
                <tr style="background:#fafbfc;">
                    <th style="width:6px;"></th>
                    <th>Plan Name</th>
                    <th>Type</th>
                    <th>Speed</th>
                    <th>Supplier</th>
                    <th style="text-align:right;color:#dc3545;">Cost</th>
                    <th style="text-align:right;color:#28a745;">Revenue</th>
                    <th style="text-align:right;">Profit</th>
                    <th style="text-align:center;">Margin</th>
                    <th style="text-align:center;">Subs</th>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the "uCRM link" column */ ?>
                    <th style="min-width:220px;">uCRM link</th>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
                    <th style="text-align:center;">UCRM</th>
<?php endif; ?>
                    <th>Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filteredPlans as $pl):
                $profit = $pl['profit'] ?? (($pl['customer_price']??0) - ($pl['starlink_cost']??0));
                $margin = $pl['margin'] ?? (($pl['customer_price']??0) > 0 ? round(($profit / $pl['customer_price']) * 100, 1) : 0);
                $pType = strtolower($pl['type']??'starlink');
                $tl = $typeLabels[$pType] ?? $typeLabels['starlink'];
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td><div style="width:5px;height:32px;border-radius:3px;background:<?= h($pl['color'] ?? '#2196F3') ?>;"></div></td>
                    <td style="font-weight:700;font-size:13px;"><?= h($pl['name']) ?></td>
                    <td>
                        <span style="background:<?= $tl[2] ?>;color:<?= $tl[3] ?>;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;"><?= $tl[0] ?></span>
                    </td>
                    <td style="font-size:12px;font-weight:600;"><?= h($pl['speed'] ?? '-') ?></td>
                    <td style="font-size:12px;color:#6b7280;"><?= h($pl['supplier'] ?? '-') ?></td>
                    <td style="text-align:right;font-weight:600;<?= ($pl['starlink_cost']??0) > 0 ? 'color:#dc3545;background:#fef2f2;' : 'color:#d1d5db;' ?>"><?= ($pl['starlink_cost']??0) > 0 ? $curSym.number_format($pl['starlink_cost'],2) : '-' ?></td>
                    <td style="text-align:right;font-weight:600;color:#28a745;background:#f0fdf4;"><?= $curSym ?><?= number_format($pl['customer_price'] ?? 0, 2) ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $profit >= 0 ? '#28a745' : '#dc3545' ?>;"><?= $curSym ?><?= number_format($profit, 2) ?></td>
                    <td style="text-align:center;">
                        <div style="position:relative;display:inline-block;">
                            <span style="font-size:12px;font-weight:700;"><?= $margin ?>%</span>
                            <div style="width:40px;height:3px;background:#e5e7eb;border-radius:2px;margin-top:3px;">
                                <div style="width:<?= min(100, max(0, $margin)) ?>%;height:100%;background:<?= $profit >= 0 ? '#28a745' : '#dc3545' ?>;border-radius:2px;"></div>
                            </div>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <?php if (!empty($pl['active_subs'])): ?>
                            <span style="background:#d4edda;color:#28a745;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?= $pl['active_subs'] ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">-</span>
                        <?php endif; ?>
                    </td>
<?php if ($_lk102 !== null): /* 5.18.102 (Uganda, D14, docs/71 §R.2.2): the plan's link, as the resolver judges it now */ ?>
                    <td style="font-size:12px;"><?= $_lk102Cell('plan', is_array($pl) ? $pl : []) ?></td>
<?php else: /* 5.18.102: elsewhere, as it was */ ?>
                    <td style="text-align:center;">
                        <?php if (!empty($pl['ucrm_product_id'])): ?>
                            <a href="<?= dn_crm_web($config) ?>/crm/system/items/products/<?= (int)$pl['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$pl['ucrm_product_id'] ?> 🔗</a>
                        <?php else: ?>
                            <button onclick="syncPlan(<?= (int)$pl['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                        <?php endif; ?>
                    </td>
<?php endif; ?>
                    <td>
                        <?php if (!empty($pl['is_active'])): ?>
                            <span class="badge-approved">Active</span>
                        <?php else: ?>
                            <span class="badge-rejected">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <a href="?page=dashboard&tab=subscription_plans&edit_plan=<?= $pl['id'] ?>&plan_filter=<?= h($filterType) ?>" style="color:#D41C1C;font-weight:600;font-size:11px;text-decoration:none;margin-right:6px;">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this plan?');">
                        <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_plan">
                            <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
                            <button type="submit" style="background:none;border:none;color:#dc3545;font-weight:600;font-size:11px;cursor:pointer;">
                                <i class="bi bi-trash"></i> Del
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($filteredPlans)): ?>
                <tr><td colspan="12" style="text-align:center;color:#9ca3af;padding:30px;">
                    No <?= $filterType !== 'all' ? $filterType : '' ?> plans configured yet.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function planTypeChanged() {
    const t = document.getElementById('planTypeSelect').value;
    const labels = {starlink:['Starlink Cost','What Starlink charges you'],fiber:['Supplier Cost','What your fiber supplier charges'],sim:['Network Cost','SIM network cost (if any)']};
    const l = labels[t] || labels.starlink;
    document.getElementById('costLabel').textContent = l[0] + ' *';
    document.getElementById('costHint').textContent = l[1];
}
function calcPlanProfit() {
    const CUR = <?= json_encode($curSym) ?>;
    const cost = parseFloat(document.querySelector('input[name="starlink_cost"]')?.value) || 0;
    const price = parseFloat(document.querySelector('input[name="customer_price"]')?.value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? ((profit / price) * 100).toFixed(1) : 0;
    document.getElementById('ppProfit').textContent = CUR + profit.toFixed(2);
    document.getElementById('ppProfit').style.color = profit >= 0 ? '#28a745' : '#dc3545';
    document.getElementById('ppMargin').textContent = margin + '%';
    document.getElementById('ppMargin').style.color = profit >= 0 ? '#1565C0' : '#dc3545';
}
planTypeChanged();
<?php if ($editPlan): ?>
calcPlanProfit();
<?php endif; ?>

const _TK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';

function syncPlan(planId, btn) {
    btn.disabled = true; btn.textContent = '⏳';
    fetch('?page=api&action=sync_plan_to_ucrm', {
          credentials:'same-origin',
          method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _TK },
        body: JSON.stringify({ plan_id: planId })
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

function bulkSyncPlans() {
    if (!confirm('Sync all unmapped plans to UCRM products now?')) return;
    fetch('?page=api&action=push_products_to_ucrm&dry_run=0', {
        headers: { 'Authorization': 'Bearer ' + _TK }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
<?php /* 5.18.102 (Uganda, docs/71 §K.3, D12): the push is refused (409) where the quoting-organization gate is on — say so, never "Done". Emitted only there */ require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php'; if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)['on']): ?>
        if (d.status === 'error') { alert(d.message || 'Not done.'); return; }
<?php endif; ?>
        var s = (d.data||d).summary || {};
        alert('✅ Done!\nCreated: ' + (s.total_created||0) + '\nSkipped (already mapped): ' + (s.total_skipped||0) + '\nFailed: ' + (s.total_failed||0) + '\n\nRefreshing page…');
        window.location.reload();
    }).catch(function(e){ alert('Error: ' + e.message); });
}
</script>

