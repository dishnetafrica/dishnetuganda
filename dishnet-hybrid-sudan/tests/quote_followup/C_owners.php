<?php
declare(strict_types=1);
/**
 * Section C of tests/test_quote_followup.php — owners, F4 (5.18.104, root docs/73 §H.2.9.2, §H.2.9.4, §H.2.14 C; ruling
 * R18; Part P's IP-1). TEST ONLY.
 *
 * kycAgent() on the four DECLARED fixture cases (qf_kyc_agent_fixtures(), which Part P's quote_agent_copy A7 and C2 read
 * for their expected results), and through the tracker for rules 1 and 6; the full order, rule by rule; `pending`
 * resolved, then final at 1 h, every row's age judged at the run's one moment; `tracker_ran` written with that moment
 * only by a run that reached every pending row (Q16-F04); `tracker_cursor` written in the transaction that inserts the
 * rows it covers, so a run that dies before its commit leaves both as they were; a clash on idx_qt_quote_id recorded
 * once as ref_conflict:<quote_key> (Q17-F04). Every person here is fictitious.
 */

/**
 * The four declared fixture cases of owner rule 1 (§H.2.9.4; IP-1): one active sales_person match; two active matches
 * with an active submitter; an inactive match only, with an active submitter; neither an active name nor an active
 * submitter. Each: application, retailers, want (kycAgent()'s answer).
 */
function qf_kyc_agent_fixtures(): array
{
    $retailers = [
        ['id' => 21, 'name' => 'Test Seller Alpha', 'is_active' => true],
        ['id' => 22, 'name' => 'Test Twin Name', 'is_active' => true],
        ['id' => 23, 'name' => 'test twin name ', 'is_active' => true],
        ['id' => 24, 'name' => 'Test Seller Gone', 'is_active' => false],
        ['id' => 25, 'name' => 'Test Office Desk', 'is_active' => true],
        ['id' => 26, 'name' => 'Test Old Desk', 'is_active' => false],
    ];
    return [
        'one_active_name'    => ['application' => ['sales_person' => '  test seller ALPHA ', 'retailer_id' => 25], 'retailers' => $retailers,
                                 'want' => ['id' => 21, 'source' => 'kyc_sales_person']],
        'two_active_names'   => ['application' => ['sales_person' => 'Test Twin Name', 'retailer_id' => 25], 'retailers' => $retailers,
                                 'want' => ['id' => 25, 'source' => 'kyc_submitter']],
        'inactive_name_only' => ['application' => ['sales_person' => 'Test Seller Gone', 'retailer_id' => 25], 'retailers' => $retailers,
                                 'want' => ['id' => 25, 'source' => 'kyc_submitter']],
        'neither'            => ['application' => ['sales_person' => 'Test Seller Gone', 'retailer_id' => 26], 'retailers' => $retailers,
                                 'want' => ['id' => null, 'source' => 'none']],
    ];
}

$QF_SECTIONS['C'] = ['title' => 'Owners, F4 (§H.2.9.4), pending, tracker_ran, the cursor and ref_conflict (§H.2.9.2)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteSendEvidence', 'QuoteTracker'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $S = '2026-11-02 07:00:00';   // the quotations' send, UTC (Monday 10:00 Kampala)

    $ctx->run('C01 kycAgent() on the four declared fixture cases (IP-1)', function () use ($ctx) {
        $got = []; $want = [];
        foreach (qf_kyc_agent_fixtures() as $name => $f) {
            $got[$name] = QuoteSendEvidence::kycAgent($f['application'], $f['retailers']);
            $want[$name] = $f['want'];
        }
        $ctx->eq($got, $want, 'C01 one active name → kyc_sales_person; two active → the submitter; an inactive name only → the submitter; neither → none');
        $ctx->eq(QuoteSendEvidence::kycAgent([], []), ['id' => null, 'source' => 'none'], 'C01 an empty application: none');
    });

    /** A book with a quotation of number $ref to phone $ph sent at $S, and the facts $plant() adds. */
    $one = function (string $ref, string $ph, callable $plant, string $at = '2026-11-02 11:00:00') use ($ctx, $S): array {
        $b = $ctx->book(qf_tcfg(), 'c'); $p = $b['pdo'];
        foreach ([[51, 'Test Kyc Seller'], [52, 'Test Kyc Desk'], [53, 'Test Lead Owner'], [54, 'Test Log Maker'], [55, 'Test Line Owner'],
                  [56, 'Test Conv Lead'], [57, 'Test Client Seller']] as [$id, $n]) qf_staff($p, $id, $n);
        $conv = qf_conv($p, $ph);
        $plant($p, $conv);
        qf_qmsg($p, $conv, 'quote_manual', qf_ref_text($ref), $S);
        qf_track($b, $at);
        return [$b, qf_trow($p, 'ref:' . $ref)];
    };

    $ctx->run('C02 the full order, rule by rule (A12; QM30)', function () use ($ctx, $one, $S) {
        $rules = [
            1 => function (\PDO $p, int $c, string $ref) { qf_blob($p, 'kyc_applications', ['quote_ref' => $ref, 'quote_id' => '9' . substr($ref, -3), 'sales_person' => 'Test Kyc Seller', 'retailer_id' => 52, 'crm_client_id' => 801]); },
            2 => function (\PDO $p, int $c, string $ref) { qf_blob($p, 'quotes_log', ['type' => 'lead', 'quote_ref' => $ref, 'sent_by' => 'Test Log Maker']);
                                                           qf_blob($p, 'leads', ['quote_ref' => $ref, 'assigned_to' => 53]); },
            3 => function (\PDO $p, int $c, string $ref) { qf_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => $ref, 'sent_by' => ' test log maker ', 'crm_client_id' => 801]); },
            4 => function (\PDO $p, int $c, string $ref) { $p->exec("INSERT OR REPLACE INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id) VALUES ('sales-901', 'Test-Line', 'Test line', 'sales', 'staff', 55)");
                                                           $p->prepare("UPDATE wa_conversations SET channel = 'sales-901' WHERE id = ?")->execute([$c]);
                                                           qf_in($p, $c, 'Hello (test)', '2026-11-01 09:00:00'); },
            5 => function (\PDO $p, int $c, string $ref) { qf_in($p, $c, 'Hello (test)', '2026-11-01 09:00:00');
                                                           $p->exec('ALTER TABLE wa_conversations ADD COLUMN lead_id INTEGER DEFAULT NULL');
                                                           $l = qf_blob($p, 'leads', ['customer_name' => 'Test', 'assigned_to' => 56]);
                                                           $p->prepare('UPDATE wa_conversations SET lead_id = ? WHERE id = ?')->execute([$l, $c]); },
            6 => function (\PDO $p, int $c, string $ref) { qf_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => $ref, 'sent_by' => 'cron_quote_wa_flow_a', 'crm_client_id' => 801]);
                                                           qf_blob($p, 'kyc_applications', ['crm_client_id' => 801, 'sales_person' => 'Test Client Seller', 'retailer_id' => 52, 'submitted_at' => '2026-10-20 09:00:00', 'quote_ref' => 'OLD-1', 'quote_id' => 777]);
                                                           qf_blob($p, 'kyc_applications', ['crm_client_id' => 801, 'sales_person' => 'Test Kyc Seller', 'retailer_id' => 52, 'submitted_at' => '2026-11-02 11:00:00', 'quote_ref' => 'LATER-1', 'quote_id' => 778]); },
        ];
        $want = [1 => ['kyc_sales_person', 51], 2 => ['lead_owner', 53], 3 => ['quotes_log', 54], 4 => ['channel', 55], 5 => ['conv_lead', 56], 6 => ['kyc_client', 57], 7 => ['none', null]];
        $got = [];
        foreach ($want as $k => $_) {
            $ref = 'Q-60' . $k . '0';
            [, $r] = $one($ref, '25677250110' . $k, function (\PDO $p, int $c) use ($rules, $k, $ref) {
                foreach ($rules as $j => $plant) if ($j >= $k) $plant($p, $c, $ref);   // every rule from k on can yield; k must win
            });
            $got[$k] = [$r['owner_source'] ?? '?', $r['owner_staff_id'] === null ? null : (int)$r['owner_staff_id']];
        }
        $ctx->eq($got, $want, 'C02 with rules k…6 all able to yield, rule k wins, for every k; with none, none (rule 7: the central list, at 1 h)');
    });

    $ctx->run('C03 the rule-1 cases through the tracker: rule 1 and rule 6 both call kycAgent() (IP-1)', function () use ($ctx, $one) {
        $got = []; $want = [];
        $i = 0;
        foreach (qf_kyc_agent_fixtures() as $name => $f) {
            $i++;
            foreach (['rule1' => 1, 'rule6' => 6] as $rk => $rn) {
                $ref = 'Q-61' . $i . $rn;
                $b = $ctx->book(qf_tcfg(), 'c'); $p = $b['pdo'];
                foreach ($f['retailers'] as $r) qf_blob($p, 'retailers', $r);
                $c = qf_conv($p, '2567725012' . $i . $rn);
                if ($rn === 1) qf_blob($p, 'kyc_applications', $f['application'] + ['quote_ref' => $ref, 'quote_id' => '7' . $i . $rn, 'crm_client_id' => 900 + $i]);
                else {
                    qf_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => $ref, 'sent_by' => 'cron', 'crm_client_id' => 900 + $i]);
                    qf_blob($p, 'kyc_applications', $f['application'] + ['crm_client_id' => 900 + $i, 'submitted_at' => '2026-10-30 10:00:00', 'quote_ref' => 'OLD-' . $i, 'quote_id' => 600 + $i]);
                }
                qf_qmsg($p, $c, 'quote_manual', qf_ref_text($ref), '2026-11-02 07:00:00');
                qf_track($b, '2026-11-02 11:00:00');
                $r = qf_trow($p, 'ref:' . $ref);
                $got[$name][$rk] = [$r['owner_source'], $r['owner_staff_id'] === null ? null : (int)$r['owner_staff_id']];
                $w = $f['want'];
                $want[$name][$rk] = $w['id'] === null ? ['none', null] : [$rn === 1 ? $w['source'] : 'kyc_client', $w['id']];
            }
        }
        $ctx->eq($got, $want, 'C03 each declared case gives kycAgent()\'s answer under rule 1 and, as kyc_client, under rule 6');
    });

    $ctx->run('C04 pending resolved, then final at 1 h, every row judged at the run\'s one moment', function () use ($ctx) {
        $b = $ctx->book(qf_tcfg(), 'c'); $p = $b['pdo'];
        qf_staff($p, 71, 'Test Late Seller');
        $c1 = qf_conv($p, '256772501301'); qf_qmsg($p, $c1, 'quote_manual', qf_ref_text('Q-6301'), '2026-11-02 07:00:00');
        $c2 = qf_conv($p, '256772501302'); qf_qmsg($p, $c2, 'quote_manual', qf_ref_text('Q-6302'), '2026-11-02 07:00:00');
        $c3 = qf_conv($p, '256772501303'); qf_qmsg($p, $c3, 'quote_manual', qf_ref_text('Q-6303'), '2026-11-02 06:00:01');
        qf_track($b, '2026-11-02 10:59:59');   // 07:59:59 UTC: Q-6301 and Q-6302 are 59:59 old, Q-6303 1 h 59:58
        $src = function () use ($p) { return array_column($p->query('SELECT quote_ref, owner_source FROM quote_tracker ORDER BY quote_ref')->fetchAll(\PDO::FETCH_ASSOC), 'owner_source', 'quote_ref'); };
        $ctx->eq($src(), ['Q-6301' => 'pending', 'Q-6302' => 'pending', 'Q-6303' => 'none'], 'C04 no rule yields: under 1 h old pending, older none (final)');
        qf_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'Q-6301', 'sent_by' => 'Test Late Seller']);   // the log row lands late
        $r = qf_track($b, '2026-11-02 11:00:00');   // 08:00:00 UTC: exactly 1 h
        $ctx->eq([$src(), (int)qf_trow($p, 'ref:Q-6301')['owner_staff_id'], $r['owners_found'], $r['owners_final']],
                 [['Q-6301' => 'quotes_log', 'Q-6302' => 'none', 'Q-6303' => 'none'], 71, 1, 1],
                 'C04 a later run resolves the pending row its fact arrived for, and makes the other final at 1 h');
        $b2 = $ctx->book(qf_tcfg(), 'c'); $p2 = $b2['pdo'];
        qf_qmsg($p2, qf_conv($p2, '256772501304'), 'quote_manual', qf_ref_text('Q-6304'), '2026-11-02 07:00:00');
        qf_track($b2, '2026-11-02 10:30:00');
        $ctx->eq(qf_trow($p2, 'ref:Q-6304')['owner_source'], 'pending', 'C04 control: judged at the run\'s moment (30 min), never the wall clock: pending');
    });

    $ctx->run('C05 tracker_ran: the run\'s moment, written only by a run that reached every pending row (Q16-F04)', function () use ($ctx) {
        $b = $ctx->book(qf_tcfg(), 'c'); $p = $b['pdo'];
        qf_qmsg($p, qf_conv($p, '256772501401'), 'quote_manual', qf_ref_text('Q-6401'), '2026-11-02 07:00:00');
        $r = qf_track($b, '2026-11-02 10:20:00');
        $ctx->eq([$r['ran'], qf_state($p, 'tracker_ran')], [1, '2026-11-02 07:20:00'], 'C05 a full run writes tracker_ran = its moment (UTC)');
        $r2 = qf_track($b, '2026-11-02 10:40:00', null, time() - 1);   // its budget already spent
        $ctx->eq([$r2['ran'], $r2['cut'], qf_state($p, 'tracker_ran'), qf_trow($p, 'ref:Q-6401')['owner_source']], [0, 1, '2026-11-02 07:20:00', 'pending'],
                 'C05 a run its budget cut short, with a pending row left, writes none (the earlier moment stands)');
        $r3 = qf_track($b, '2026-11-02 10:50:00');
        $ctx->eq([$r3['ran'], qf_state($p, 'tracker_ran'), qf_trow($p, 'ref:Q-6401')['owner_source']], [1, '2026-11-02 07:50:00', 'pending'],
                 'C05 control: the next full run writes its own, and judges the pending row at it (50 min: still pending)');
        $u = (string)$p->query("SELECT updated_at FROM quote_followup_state WHERE k = 'tracker_ran'")->fetchColumn();
        $ctx->t(abs(strtotime($u . ' UTC') - time()) < 120, 'C05 its updated_at is set by the same statement (the wall clock), never the cursor\'s', $u);
    });

    $ctx->run('C06 tracker_cursor is written in the transaction that inserts its rows: a run that dies leaves both as they were (Q17-F04)', function () use ($ctx) {
        $b = $ctx->book(qf_tcfg(), 'c'); $p = $b['pdo'];
        qf_qmsg($p, qf_conv($p, '256772501501'), 'quote_manual', qf_ref_text('Q-6501'), '2026-11-02 07:00:00');
        qf_track($b, '2026-11-02 10:10:00');
        $cur0 = qf_state($p, 'tracker_cursor');
        qf_qmsg($p, qf_conv($p, '256772501502'), 'quote_manual', qf_ref_text('Q-6502'), '2026-11-02 07:30:00');
        qf_qmsg($p, qf_conv($p, '256772501503'), 'quote_manual', qf_ref_text('Q-6503-DIES'), '2026-11-02 07:31:00');
        $p->exec("CREATE TRIGGER qf_dies BEFORE INSERT ON quote_tracker WHEN NEW.quote_ref = 'Q-6503-DIES' BEGIN SELECT RAISE(ABORT, 'test: the run dies here'); END");
        $threw = '';
        try { qf_track($b, '2026-11-02 10:40:00'); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $ctx->eq([strpos($threw, 'the run dies here') !== false, qf_state($p, 'tracker_cursor'), qf_trow($p, 'ref:Q-6502')], [true, $cur0, null],
                 'C06 the run died before its commit: the cursor and the rows it covered are as they were');
        $p->exec('DROP TRIGGER qf_dies');
        qf_track($b, '2026-11-02 10:45:00');
        $ctx->eq([qf_trow($p, 'ref:Q-6502') !== null, qf_trow($p, 'ref:Q-6503-DIES') !== null, (int)qf_state($p, 'tracker_cursor') > (int)$cur0], [true, true, true],
                 'C06 control: the next run reads them again and commits rows and cursor together');
    });

    $ctx->run('C07 a clash on idx_qt_quote_id is recorded once as ref_conflict:<quote_key> (Q17-F04)', function () use ($ctx) {
        $b = $ctx->book(qf_tcfg(), 'c'); $p = $b['pdo'];
        $p->exec("CREATE TABLE IF NOT EXISTS wa_sent_quotes (quote_id INTEGER NOT NULL, quote_ref TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', sent_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (quote_id))");
        $p->exec("INSERT INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (6601, 'Q-6601', 'webhook')");
        qf_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'MAN-202611-066', 'crm_quote_id' => 6601, 'sent_by' => 'x']);
        $c = qf_conv($p, '256772501601');
        qf_qmsg($p, $c, 'ops_quote_created', qf_order_text('Q-6601'), '2026-11-02 07:00:00');
        qf_qmsg($p, $c, 'quote_manual', qf_ref_text('MAN-202611-066'), '2026-11-02 07:01:00');   // the same uCRM id under another number
        $r = qf_track($b, '2026-11-02 10:10:00');
        $ctx->eq([qf_trow($p, 'ref:Q-6601') !== null, qf_trow($p, 'ref:MAN-202611-066'), qf_state($p, 'ref_conflict:ref:MAN-202611-066'), $r['ref_conflict']],
                 [true, null, '6601', 1], 'C07 the second number of one uCRM id: no row, one ref_conflict record naming the id it clashed on');
        qf_qmsg($p, $c, 'quote_manual', qf_ref_text('MAN-202611-066'), '2026-11-02 08:00:00');   // sent again
        qf_track($b, '2026-11-02 11:10:00');
        $ctx->eq((int)$p->query("SELECT COUNT(*) FROM quote_followup_state WHERE k LIKE 'ref_conflict:%'")->fetchColumn(), 1, 'C07 recorded once, however often it is sent');
    });
}];
