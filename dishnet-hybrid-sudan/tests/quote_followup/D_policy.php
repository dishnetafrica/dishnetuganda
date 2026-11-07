<?php
declare(strict_types=1);
/**
 * Section D of tests/test_quote_followup.php — the chase's schedule (5.18.104, root docs/73 §H.2.10.1, §H.2.10.3,
 * §H.2.10.5, §H.2.14 D). TEST ONLY.
 *
 * QuoteChasePolicy: 24/72/168 h from the quotation's own send; steps 1 and 2 moved forward to the window's next opening;
 * Sunday never open; the 48 h and 96 h floors after a late send; step 3 moved back to 16:00 on the last open day, and
 * skipped_floor; the validity cut at 20:00 on valid_until; a narrowed window — every computation in a literal Kampala zone,
 * with timezone=Africa/Juba stored and the process clock elsewhere. Then the schedule in the book: QuoteChase::on(),
 * open() (the holds first, the phone's enquiries superseded on every conversation, step 1 anchored on the quotation's
 * send, never the customer's last message) and draftDue() (stale drafts expired, the uCRM status read with get() only, at
 * most three times a run with its own timeouts, the variant alternating per handset). Every person and number is
 * fictitious; the uCRM client is a fake that counts its methods.
 */

/** A fake uCRM client: counts get(), records withTimeouts(), refuses anything else (C9). */
final class QfFakeCrm
{
    public $gets = 0;
    public $timeouts = null;
    public $other = 0;
    /** @var array<int,int> quote id => uCRM status */
    public $status = [];
    public function withTimeouts(int $c, int $t): self { $this->timeouts = [$c, $t]; return $this; }
    public function get(string $path): ?array
    {
        $this->gets++;
        return preg_match('#^billing/quotes/(\d+)$#', $path, $m) ? ['id' => (int)$m[1], 'status' => $this->status[(int)$m[1]] ?? 1] : null;
    }
    public function post(string $p, array $b = []) { $this->other++; return null; }
    public function patch(string $p, array $b = []) { $this->other++; return null; }
    public function put(string $p, array $b = []) { $this->other++; return null; }
    public function delete(string $p) { $this->other++; return null; }
    public function request(string $m, string $p, $b = null) { $this->other++; return null; }
}

/** The chase's configuration: the tracker's, with the chase on since $notBefore (UTC). */
function qf_ccfg(array $extra = [], string $notBefore = '2026-11-01 00:00:00'): array
{
    return qf_tcfg($extra + ['quote_chase_enabled' => '1', 'quote_chase_not_before' => $notBefore, 'evo_api_url' => 'http://127.0.0.1:9',
                             'evo_api_key' => 'fake-key-qf']);
}

/** A tracker row written directly (the shape QuoteTracker derives); its quote_key. */
function qf_track_row(\PDO $p, string $ref, string $phone, int $conv, array $o = []): string
{
    $o += ['sent_at' => '2026-11-01 07:00:00', 'valid_until' => '2026-11-20', 'channel' => 'support', 'spoke' => $conv, 'spoke_channel' => null,
           'backfilled' => 0, 'quote_id' => null, 'made_by' => 'manual'];
    $ev = qf_qmsg($p, $conv, 'quote_manual', qf_ref_text($ref), $o['sent_at']);
    $p->prepare('INSERT INTO quote_tracker (quote_key, quote_ref, quote_id, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id,
                                            sent_channel, valid_until, spoke_conv_id, spoke_channel, backfilled)
                 VALUES (?, ?, ?, ?, ?, \'quote_manual\', ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute(['ref:' . $ref, $ref, $o['quote_id'], $phone, $ev, $o['made_by'], $o['sent_at'], $conv, $o['channel'], $o['valid_until'],
                 $o['spoke'] ?: null, $o['spoke'] ? ($o['spoke_channel'] ?? $o['channel']) : null, $o['backfilled']]);
    return 'ref:' . $ref;
}

function qf_fu(\PDO $p, string $key): ?array
{
    $st = $p->prepare('SELECT f.* FROM followups f JOIN quote_tracker t ON t.followup_id = f.id WHERE t.quote_key = ?');
    $st->execute([$key]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
}

$QF_SECTIONS['D'] = ['title' => 'The chase\'s schedule, opening and drafting (§H.2.10.1, §H.2.10.3, §H.2.10.5)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChasePolicy', 'QuoteChaseText', 'QuoteChase'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $K = function (?int $t): ?string { return $t === null ? null : (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(QF_TZ))->format('D Y-m-d H:i'); };
    $due = function (int $step, string $sent, string $vu = '2026-11-20', ?string $prev = null, array $w = [8, 20]) use ($K): array {
        $d = QuoteChasePolicy::dueAt($step, qf_t($sent), $vu, $prev === null ? null : qf_t($prev), $w);
        return [$K($d['at']), $d['code']];
    };

    $ctx->run('D01 the schedule: 24, 72 and 168 hours from the quotation\'s own send', function () use ($ctx, $due) {
        $ctx->eq([$due(1, '2026-11-02 10:00:00'), $due(2, '2026-11-02 10:00:00'), $due(3, '2026-11-02 10:00:00')],
                 [['Tue 2026-11-03 10:00', null], ['Thu 2026-11-05 10:00', null], ['Mon 2026-11-09 10:00', null]],
                 'D01 control: Monday 10:00 → Tuesday, Thursday and the next Monday at 10:00');
        $ctx->eq(QuoteChasePolicy::SCHEDULE, [1 => 24, 2 => 72, 3 => 168], 'D01 SCHEDULE is [1 => 24, 2 => 72, 3 => 168]');
    });

    $ctx->run('D02 steps 1 and 2 move forward to the window\'s next opening', function () use ($ctx, $due) {
        $ctx->eq([$due(1, '2026-11-02 19:30:00'), $due(1, '2026-11-02 21:00:00'), $due(1, '2026-11-02 06:00:00'), $due(2, '2026-11-02 20:00:00')],
                 [['Tue 2026-11-03 19:30', null], ['Wed 2026-11-04 08:00', null], ['Tue 2026-11-03 08:00', null], ['Fri 2026-11-06 08:00', null]],
                 'D02 19:30 stays; 21:00 → 08:00 the next morning; 06:00 → 08:00 the same day; 20:00 is outside');
    });

    $ctx->run('D03 Sunday is never open (QM5)', function () use ($ctx, $due) {
        $ctx->eq([$due(1, '2026-11-07 10:00:00'), $due(2, '2026-11-05 12:00:00')], [['Mon 2026-11-09 08:00', null], ['Mon 2026-11-09 08:00', null]],
                 'D03 a step due on Sunday leaves on Monday at 08:00');
        $ctx->eq([QuoteChasePolicy::inWindow(qf_t('2026-11-08 10:00:00'), [8, 20]), QuoteChasePolicy::inWindow(qf_t('2026-11-07 10:00:00'), [8, 20])],
                 [false, true], 'D03 inWindow(): Sunday 10:00 no, Saturday 10:00 yes');
    });

    $ctx->run('D04 the floors: step 2 never sooner than 48 h after a late step 1, step 3 never sooner than 96 h after step 2', function () use ($ctx, $due) {
        $ctx->eq($due(2, '2026-11-02 10:00:00', '2026-11-20', '2026-11-04 09:00:00'), ['Fri 2026-11-06 09:00', null],
                 'D04 step 1 sent late on Wednesday 09:00: step 2 on Friday 09:00, not Thursday');
        $ctx->eq($due(2, '2026-11-02 10:00:00', '2026-11-20', '2026-11-03 10:00:00'), ['Thu 2026-11-05 10:00', null], 'D04 control: on time, the schedule');
        $ctx->eq($due(3, '2026-11-02 10:00:00', '2026-11-20', '2026-11-06 10:00:00'), [null, 'skipped_floor'],
                 'D04 step 2 sent on Friday 10:00: step 3 on Monday 10:00 is 72 h after it — skipped_floor');
        $ctx->eq($due(3, '2026-11-02 10:00:00', '2026-11-20', '2026-11-05 10:00:00'), ['Mon 2026-11-09 10:00', null], 'D04 control: 96 h after step 2, sent');
    });

    $ctx->run('D05 step 3 = min(sent + 168 h, 16:00 on valid_until), moved BACK to 16:00 on the last open day', function () use ($ctx, $due) {
        $ctx->eq([$due(3, '2026-11-02 10:00:00', '2026-11-07'), $due(3, '2026-11-02 10:00:00', '2026-11-08'), $due(3, '2026-11-02 17:30:00'),
                  $due(3, '2026-11-02 07:00:00'), $due(3, '2026-11-02 10:00:00', '2026-11-06')],
                 [['Sat 2026-11-07 16:00', null], ['Sat 2026-11-07 16:00', null], ['Mon 2026-11-09 16:00', null],
                  ['Sat 2026-11-07 16:00', null], ['Fri 2026-11-06 16:00', null]],
                 'D05 valid until Saturday: Saturday 16:00; until Sunday: back to Saturday 16:00; 17:30 → 16:00 that day; 07:00 (out of hours) → the last open day 16:00; until Friday: Friday 16:00');
    });

    $ctx->run('D06 the validity cut: nothing after 20:00 on valid_until (past_validity)', function () use ($ctx, $due) {
        $ctx->eq([$due(1, '2026-11-02 10:00:00', '2026-11-03'), $due(2, '2026-11-02 10:00:00', '2026-11-03'), $due(1, '2026-11-02 21:00:00', '2026-11-03')],
                 [['Tue 2026-11-03 10:00', null], [null, 'past_validity'], [null, 'past_validity']],
                 'D06 valid until Tuesday: step 1 Tuesday 10:00; step 2 (Thursday) and a step 1 moved to Wednesday 08:00 are past it');
        $n = QuoteChasePolicy::next(2, qf_t('2026-11-02 10:00:00'), '2026-11-03', qf_t('2026-11-03 10:00:00'), [8, 20]);
        $ctx->eq($n, ['step' => null, 'at' => null, 'skipped' => [2 => 'past_validity', 3 => 'past_validity']], 'D06 next(): no step left after the cut');
        $n2 = QuoteChasePolicy::next(3, qf_t('2026-11-02 10:00:00'), '2026-11-20', qf_t('2026-11-06 10:00:00'), [8, 20]);
        $ctx->eq([$n2['step'], $n2['skipped']], [null, [3 => 'skipped_floor']], 'D06 next(): skipped_floor leaves none');
    });

    $ctx->run('D07 a narrowed window (set window 8 18; hour_to 15)', function () use ($ctx, $due) {
        $ctx->eq([$due(1, '2026-11-02 18:30:00', '2026-11-20', null, [8, 18]), $due(1, '2026-11-02 18:30:00')],
                 [['Wed 2026-11-04 08:00', null], ['Tue 2026-11-03 18:30', null]], 'D07 18:30 is outside 8-18: the next opening; inside 8-20 it stays');
        $ctx->eq($due(3, '2026-11-02 15:30:00', '2026-11-20', null, [8, 15]), ['Mon 2026-11-09 14:00', null],
                 'D07 hour_to 15: step 3 back to 14:00, inside the window');
    });

    $ctx->run('D08 a literal Kampala zone, whatever the process clock and a stored timezone say', function () use ($ctx, $due) {
        $want = [$due(1, '2026-11-02 21:00:00'), $due(3, '2026-11-02 10:00:00', '2026-11-08')];
        $was = date_default_timezone_get();
        date_default_timezone_set(implode('/', ['Africa', 'Juba']));   // named in parts: tests/test_timezone.php scans this file
        $juba = [$due(1, '2026-11-02 21:00:00'), $due(3, '2026-11-02 10:00:00', '2026-11-08')];
        date_default_timezone_set(implode('/', ['America', 'New_York']));
        $ny = [$due(1, '2026-11-02 21:00:00'), $due(3, '2026-11-02 10:00:00', '2026-11-08')];
        date_default_timezone_set($was);
        $ctx->eq([$juba, $ny], [$want, $want], 'D08 the same answers under Africa/Juba and America/New_York process zones');
        $b = $ctx->book(qf_ccfg(['timezone' => implode('/', ['Africa', 'Juba'])]), 'd');
        $b2 = $ctx->book(qf_ccfg(), 'd');
        $ctx->eq([QuoteChase::on($b['cfg'], $b['data']), QuoteChase::on($b2['cfg'], $b2['data'])], [false, true],
                 'D08 QuoteChase::on() is false with timezone=Africa/Juba stored; control: Kampala');
    });

    $ctx->run('D09 on(): Uganda, tracking, the chase and the ledger', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd');
        $ss = $ctx->book(['tenant_profile' => 'south-sudan', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on', 'quote_tracking_enabled' => '1', 'quote_chase_enabled' => '1'], 'd');
        $ctx->eq([QuoteChase::on($b['cfg'], $b['data']), QuoteChase::on(['quote_chase_enabled' => '0'] + $b['cfg'], $b['data']),
                  QuoteChase::on(['quote_tracking_enabled' => ''] + $b['cfg'], $b['data']), QuoteChase::on(['wa_send_ledger' => 'off'] + $b['cfg'], $b['data']),
                  QuoteChase::on($ss['cfg'], $ss['data'])], [true, false, false, false, false],
                 'D09 on; then off with the chase, tracking or the ledger off, and on South Sudan');
    });

    $ctx->run('D10 open(): step 1 anchored on the quotation\'s send, the holds set first (QM8)', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo'];
        $c = qf_conv($p, '256772502001', 'support');
        qf_in($p, $c, 'hello (test)', '2026-10-30 09:00:00');
        $p->prepare("UPDATE wa_conversations SET last_customer_at = '2026-11-01 09:00:00' WHERE id = ?")->execute([$c]);   // the customer, a day later
        $k = qf_track_row($p, 'Q-8001', '256772502001', $c, ['sent_at' => '2026-11-02 07:00:00']);
        $o = QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        $f = qf_fu($p, $k);
        $ctx->eq([$o['opened'], $f['kind'] ?? null, $f['due_at'] ?? null, $f['last_customer_at'] ?? null, (int)($f['max_attempts'] ?? 0), $f['sales_stage'] ?? null, $f['opened_by'] ?? null],
                 [1, 'quote', '2026-11-03 07:00:00', '2026-11-02 07:00:00', 3, 'quoted', 'quote_chase'],
                 'D10 opened: kind quote, step 1 due 24 h after the quotation, last_customer_at = sent_at, three attempts, quoted, by quote_chase');
        $ctx->eq([$f['topic'] ?? null, $f['product'] ?? null, $f['context_summary'] ?? null, $f['quote_key'] ?? null],
                 ['quote', 'Q-8001', 'quotation Q-8001, valid until 2026-11-20', $k], 'D10 the quotation as context; the quote_key');
        $o2 = QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:10:00'), time() + 60);
        $ctx->eq([$o2['opened'], (int)$p->query("SELECT COUNT(*) FROM followups WHERE kind = 'quote'")->fetchColumn()], [0, 1], 'D10 one chase per quotation, ever');
    });

    $ctx->run('D11 open(): before switch-on, backfilled, never wrote, escalation and colleague are holds, never chases', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg([], '2026-11-02 06:00:00'), 'd'); $p = $b['pdo'];
        $c1 = qf_conv($p, '256772502011'); qf_in($p, $c1, 'hi (test)', '2026-10-30 09:00:00');
        $k1 = qf_track_row($p, 'Q-8011', '256772502011', $c1, ['sent_at' => '2026-11-02 05:00:00']);
        $c2 = qf_conv($p, '256772502012'); qf_in($p, $c2, 'hi (test)', '2026-10-30 09:00:00');
        $k2 = qf_track_row($p, 'Q-8012', '256772502012', $c2, ['sent_at' => '2026-11-02 07:00:00', 'backfilled' => 1]);
        $c3 = qf_conv($p, '256772502013');
        $k3 = qf_track_row($p, 'Q-8013', '256772502013', $c3, ['sent_at' => '2026-11-02 07:00:00', 'spoke' => 0]);
        $c4 = qf_conv($p, '256772502014'); qf_in($p, $c4, 'I will report you to the regulator (test)', '2026-10-30 09:00:00');
        $c4b = qf_conv($p, '256772502014', 'sales'); qf_in($p, $c4b, 'hi (test)', '2026-10-31 09:00:00');
        $k4 = qf_track_row($p, 'Q-8014', '256772502014', $c4b, ['sent_at' => '2026-11-02 07:00:00', 'channel' => 'support']);
        $c5 = qf_conv($p, '256772502015'); qf_in($p, $c5, 'hi (test)', '2026-10-30 09:00:00');
        qf_msg($p, $c5, ['body' => 'our notice: no refund is due (test)', 'agent_name' => 'DishNet Plugin', 'event_key' => 'ops_quote_text', 'sent_at' => '2026-11-01 09:00:00']);
        $k5 = qf_track_row($p, 'Q-8015', '256772502015', $c5, ['sent_at' => '2026-11-02 07:00:00']);
        QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        $holds = [];
        foreach ([$k1, $k2, $k3, $k4, $k5] as $k) { $r = qf_trow($p, $k); $holds[$k] = [$r['chase_hold'], $r['followup_id'] === null ? null : 'chase']; }
        $ctx->eq($holds, [$k1 => ['before_switch_on', null], $k2 => ['before_switch_on', null], $k3 => ['never_wrote', null],
                          $k4 => ['escalation', null], $k5 => [null, 'chase']],
                 'D11 sent before quote_chase_not_before and backfilled: before_switch_on; never wrote (cold off): never_wrote; an escalation word in the customer\'s own inbound row on another conversation: escalation; our own notice naming one: no hold (QM24)');
    });

    $ctx->run('D12 open(): every open enquiry follow-up of the phone, on every conversation, closed superseded (R-06; QM42)', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo'];
        $sup = qf_conv($p, '256772502021', 'support'); qf_in($p, $sup, 'hi (test)', '2026-10-30 09:00:00');
        $sal = qf_conv($p, '256772502021', 'sales');
        $other = qf_conv($p, '256772502022', 'sales');
        $svc = new FollowUpService($p);
        foreach ([$sup, $sal, $other] as $cv) {
            $p->prepare("UPDATE wa_conversations SET last_customer_at = '2026-10-30 09:00:00' WHERE id = ?")->execute([$cv]);
            $svc->open(['id' => $cv, 'channel' => 'support', 'phone' => $cv === $other ? '256772502022' : '256772502021', 'last_customer_at' => '2026-10-30 09:00:00']);
        }
        $k = qf_track_row($p, 'Q-8021', '256772502021', $sup, ['sent_at' => '2026-11-02 07:00:00']);
        $o = QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        $rs = [];
        foreach ([$sup, $sal, $other] as $cv) {
            $st = $p->prepare("SELECT close_reason FROM followups WHERE conversation_id = ? AND COALESCE(kind,'enquiry') = 'enquiry'");
            $st->execute([$cv]); $rs[] = $st->fetchColumn();
        }
        $ctx->eq([$o['superseded'], $o['opened'], $rs], [2, 1, ['superseded', 'superseded', null]],
                 'D12 both of the phone\'s enquiries closed superseded (its support and its sales conversation); another phone\'s left open; the chase opened');
    });

    $ctx->run('D13 draftDue(): stale drafts expired first; then the due row drafted, in the window, approved as quote_chase', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo'];
        $c = qf_conv($p, '256772502031'); qf_in($p, $c, 'hi (test)', '2026-10-30 09:00:00');
        $k = qf_track_row($p, 'Q-8031', '256772502031', $c, ['sent_at' => '2026-11-02 07:00:00']);
        QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        $crm = new QfFakeCrm();
        $early = QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-03 09:59:00'), time() + 60, $crm);
        $ctx->eq($early['drafted'], 0, 'D13 control: not yet due');
        $d = QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-03 10:00:00'), time() + 60, $crm);
        $dr = $p->query("SELECT * FROM followup_drafts ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $ctx->eq([$d['drafted'], $d['approved'], $dr['status'], $dr['decided_by'], $dr['model'], $dr['edited_body'], $dr['verdict']],
                 [1, 1, 'approved', 'auto:quote_chase', 'template:quote_chase_v1:1A', null, 'SEND'], 'D13 drafted and approved as auto:quote_chase, template 1A');
        $ctx->eq($dr['body'], QuoteChaseText::render(1, 'A', 'Q-8031', '2026-11-20', null), 'D13 the body is the department wording');
        $ev = $p->query("SELECT actor FROM followup_events WHERE event = 'drafted'")->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq($ev, ['quote_chase'], 'D13 logged with actor quote_chase, never ai');
        $p->exec("UPDATE followup_drafts SET decided_at = '2026-11-03 07:00:00' WHERE id = " . (int)$dr['id']);
        $x = QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-04 09:00:00'), time() + 60, $crm);
        $ctx->eq([$x['expired'], $p->query('SELECT status FROM followup_drafts WHERE id = ' . (int)$dr['id'])->fetchColumn(), $x['drafted']], [1, 'expired', 1],
                 'D13 the next Kampala day: the draft approved yesterday expired first (K2-21), and a fresh one drafted (QM20)');
        $night = QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-04 21:00:00'), time() + 60, $crm);
        $ctx->eq([$night['outside_window'], $night['drafted']], [1, 0], 'D13 outside the window nothing is drafted');
    });

    $ctx->run('D14 the uCRM status: get() only, at most three a run, 3 s / 5 s (C9; QM49, QM50)', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo'];
        $keys = [];
        foreach (range(1, 5) as $i) {
            $ph = '25677250204' . $i;
            $c = qf_conv($p, $ph); qf_in($p, $c, 'hi (test)', '2026-10-30 09:00:00');
            $keys[$i] = qf_track_row($p, 'Q-804' . $i, $ph, $c, ['sent_at' => '2026-11-02 07:00:00', 'quote_id' => 8040 + $i]);
        }
        QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        $crm = new QfFakeCrm(); $crm->status = [8041 => 2, 8042 => 3];
        $d = QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-03 10:00:00'), time() + 60, $crm);
        $ctx->eq([$crm->gets, $crm->other, $crm->timeouts], [3, 0, [3, 5]], 'D14 three GETs, nothing but get(), with withTimeouts(3, 5)');
        $ctx->eq([qf_trow($p, $keys[1])['status'], qf_trow($p, $keys[2])['status'], $d['stopped'], $d['drafted'], $d['ucrm_wait']],
                 ['accepted', 'declined', 2, 1, 2], 'D14 accepted (2) and declined (3) stop the chase; one drafted; two wait for the next run');
        $ctx->eq((new FollowUpService($p))->get((int)qf_trow($p, $keys[1])['followup_id'])['close_reason'], 'converted', 'D14 the accepted quotation\'s chase closed converted');
        $none = QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-03 10:05:00'), time() + 3, $crm);
        $ctx->eq([$crm->gets, $none['drafted']], [3, 0], 'D14 with no budget left (JobBudget::mayStart(until, 5)): no GET starts, nothing drafted');
    });

    $ctx->run('D15 the variant alternates per handset: the opposite of the latest quote draft there, A when none', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo'];
        $mk = function (string $ph, string $ch) use ($p): string {
            $c = qf_conv($p, $ph, $ch); qf_in($p, $c, 'hi (test)', '2026-10-30 09:00:00');
            return qf_track_row($p, 'Q-' . substr($ph, -4), $ph, $c, ['sent_at' => '2026-11-02 07:00:00', 'channel' => $ch === 'sales' ? 'support' : $ch,
                                                                               'spoke_channel' => $ch]);
        };
        $k1 = $mk('256772502051', 'support'); $k2 = $mk('256772502052', 'account'); $k3 = $mk('256772502053', 'sales'); $k4 = $mk('256772502054', 'support');
        QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        QuoteChase::draftDue($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-03 10:00:00'), time() + 60, new QfFakeCrm());
        $v = [];
        foreach ([$k1, $k2, $k3, $k4] as $k) {
            $st = $p->prepare('SELECT d.model FROM followup_drafts d JOIN quote_tracker t ON t.followup_id = d.followup_id WHERE t.quote_key = ?');
            $st->execute([$k]); $v[$k] = substr((string)$st->fetchColumn(), -2);
        }
        $ctx->eq($v, [$k1 => '1A', $k2 => '1B', $k3 => '1A', $k4 => '1A'],
                 'D15 support then account (one handset): A, B; sales (its own handset): A; the next on support\'s handset: A again (after B)');
    });

    $ctx->run('D16 open(): a chase spoken to on an accounts conversation leaves through account, never accounts (§H.2.10.2)', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo'];
        $c = qf_conv($p, '256772502061', 'accounts'); qf_in($p, $c, 'hi (test)', '2026-10-30 09:00:00');
        $k = qf_track_row($p, 'Q-8061', '256772502061', $c, ['sent_at' => '2026-11-02 07:00:00', 'channel' => 'account', 'spoke_channel' => 'accounts']);
        $c2 = qf_conv($p, '256772502062', 'sales'); qf_in($p, $c2, 'hi (test)', '2026-10-30 09:00:00');
        $k2 = qf_track_row($p, 'Q-8062', '256772502062', $c2, ['sent_at' => '2026-11-02 07:00:00', 'spoke_channel' => 'sales']);
        QuoteChase::open($p, $b['cfg'], $b['data'], $b['store'], qf_t('2026-11-02 11:00:00'), time() + 60);
        $ctx->eq([qf_fu($p, $k)['channel'] ?? null, qf_fu($p, $k2)['channel'] ?? null], ['account', 'sales'], 'D16 accounts → account; control: sales stays sales');
    });

    $ctx->run('D17 demand(): the chases due by today\'s window close, by the handset they leave from (§H.2.5.4)', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'd'); $p = $b['pdo']; $svc = new FollowUpService($p);
        $mk = function (string $ph, string $ch, string $due) use ($p, $svc): void {
            $c = qf_conv($p, $ph, $ch);
            $k = qf_track_row($p, 'Q-' . substr($ph, -4), $ph, $c, ['sent_at' => '2026-11-01 07:00:00']);
            $svc->openQuote(['id' => $c, 'channel' => $ch, 'phone' => $ph], ['quote_key' => $k, 'ref' => substr($k, 4), 'valid_until' => '2026-11-20',
                            'sent_at' => '2026-11-01 07:00:00', 'due_at' => $due]);
        };
        $mk('256772502071', 'support', '2026-11-02 07:00:00'); $mk('256772502072', 'account', '2026-11-02 16:00:00');
        $mk('256772502073', 'sales', '2026-11-02 09:00:00'); $mk('256772502074', 'sales', '2026-11-02 17:30:00');   // 20:30 Kampala: after the close
        $ctx->eq(QuoteChase::demand($p, $b['cfg'], $b['data'], qf_t('2026-11-02 10:00:00')), ['i:dishnet-ug' => 2, 'i:sales-ug' => 1],
                 'D17 support and account on one handset (2), sales on its own (1); one due after 20:00 not counted');
    });
}];
