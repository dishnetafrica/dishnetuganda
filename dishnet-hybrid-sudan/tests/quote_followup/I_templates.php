<?php
declare(strict_types=1);
/**
 * Section I of tests/test_quote_followup.php — the templates (5.18.104, root docs/73 §H.2.10.4, §H.2.10.8, §H.2.14 I; root
 * docs/72 §H.1, D9; K2-20, gate B-1). TEST ONLY.
 *
 * The golden bytes: the six department wordings as root docs/72 §H.1 gives them and the three literal salesperson forms as
 * root docs/73 §H.2.10.4 gives them, typed here independently of lib/QuoteChaseText.php; the three composed forms pinned
 * as proposed and gated on sf_persona_confirmed (QM48: none rendered while it is unset; day 3 held, day 7 B); an owner line
 * with no usable first name given each step's department wording, with sf_persona_confirmed set and unset (QM68); no digit
 * but {ref}'s and {date}'s, no currency, plan or name slot, STOP in each, scanForEscalation false, PlanFenceGuard never
 * appending; approve() taking the unchanged text the screen posts (\r\n) and refusing an edit (QM45, QM61); the template
 * id; and the close-reason mapping of §H.2.10.8.
 */

/** root docs/72 §H.1 (D9), as the owner approved it on 4 Nov — typed here, never read from the code under test. */
const QF_GOLD_DEPARTMENT = [
    '1A' => 'Hello, this is DishNet. Do you have any questions about quotation {ref}? Reply here and we will help, or we can book your installation. Reply STOP if you do not want these messages.',
    '1B' => 'Hello from DishNet. We sent you quotation {ref}. Is anything unclear, or shall we book your installation? Just reply here. Reply STOP to stop these messages.',
    '3A' => 'Hello, a quick follow-up on quotation {ref} from DishNet. We can answer any question about the kit, the plan or the installation — just reply here. Reply STOP to stop these messages.',
    '3B' => 'Hello, DishNet here about quotation {ref}. If you would like to compare options or need help deciding, reply here and we will guide you. Reply STOP to stop these messages.',
    '7A' => 'Hello, quotation {ref} is valid until {date}. If you would like to go ahead, or need another option, reply here and we will take care of it. Reply STOP to stop these messages.',
    '7B' => 'Hello from DishNet. Your quotation {ref} is valid until {date}. Reply here to go ahead or to ask for a different option. Reply STOP to stop these messages.',
];
/** root docs/73 §H.2.10.4: the literal forms (approved by D9) and the composed forms (proposed, gate B-1). */
const QF_GOLD_LITERAL = [
    '1A' => "Hello, this is {first name}'s assistant at DishNet. Do you have any questions about quotation {ref}? Reply here and we will help, or we can book your installation. Reply STOP if you do not want these messages.",
    '1B' => "Hello, this is {first name}'s assistant at DishNet. We sent you quotation {ref}. Is anything unclear, or shall we book your installation? Just reply here. Reply STOP to stop these messages.",
    '7B' => "Hello, this is {first name}'s assistant at DishNet. Your quotation {ref} is valid until {date}. Reply here to go ahead or to ask for a different option. Reply STOP to stop these messages.",
];
const QF_GOLD_COMPOSED = [
    '3A' => "Hello, this is {first name}'s assistant at DishNet. A quick follow-up on quotation {ref}. We can answer any question about the kit, the plan or the installation — just reply here. Reply STOP to stop these messages.",
    '3B' => "Hello, this is {first name}'s assistant at DishNet, about quotation {ref}. If you would like to compare options or need help deciding, reply here and we will guide you. Reply STOP to stop these messages.",
    '7A' => "Hello, this is {first name}'s assistant at DishNet. Quotation {ref} is valid until {date}. If you would like to go ahead, or need another option, reply here and we will take care of it. Reply STOP to stop these messages.",
];

$QF_SECTIONS['I'] = ['title' => 'The templates, approve() and the close-reason mapping (§H.2.10.4, §H.2.10.8)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChaseText', 'QuoteTracker', 'FollowUpService', 'EmailReplyPolicy', 'PlanFenceGuard'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $steps = [1 => '1', 2 => '3', 3 => '7'];
    $fill = function (string $t, string $first = '') { return strtr($t, ['{first name}' => $first, '{ref}' => 'Q-9001', '{date}' => '20 November']); };

    $ctx->run('I01 the golden bytes: the department six and the three literal forms, as approved', function () use ($ctx, $steps, $fill) {
        $dep = []; $lit = [];
        foreach ($steps as $step => $day) {
            foreach (['A', 'B'] as $v) {
                $dep[$day . $v] = QuoteChaseText::render($step, $v, 'Q-9001', '2026-11-20', null);
                if (isset(QF_GOLD_LITERAL[$day . $v])) $lit[$day . $v] = QuoteChaseText::render($step, $v, 'Q-9001', '2026-11-20', 'Grace');
            }
        }
        $ctx->eq($dep, array_map($fill, QF_GOLD_DEPARTMENT), 'I01 render(): the six department wordings, byte for byte root docs/72 §H.1');
        $ctx->eq($lit, array_map(function ($t) use ($fill) { return $fill($t, 'Grace'); }, QF_GOLD_LITERAL),
                 'I01 render(): the three literal salesperson forms, byte for byte root docs/73 §H.2.10.4, with sf_persona_confirmed unset');
        $ctx->eq([QuoteChaseText::DEPARTMENT, QuoteChaseText::LITERAL, QuoteChaseText::COMPOSED], [QF_GOLD_DEPARTMENT, QF_GOLD_LITERAL, QF_GOLD_COMPOSED],
                 'I01 the constants equal the approved texts and the proposals exactly');
        $ctx->eq(QuoteChaseText::date('2026-11-20') . '|' . QuoteChaseText::date('2026-12-01'), '20 November|1 December', 'I01 {date} is valid_until written j F');
    });

    $ctx->run('I02 the composed forms are proposed and gated on sf_persona_confirmed (QM48)', function () use ($ctx, $fill) {
        $unset = [QuoteChaseText::render(2, 'A', 'Q-9001', '2026-11-20', 'Grace'), QuoteChaseText::render(2, 'B', 'Q-9001', '2026-11-20', 'Grace'),
                  QuoteChaseText::render(3, 'A', 'Q-9001', '2026-11-20', 'Grace')];
        $ctx->eq($unset, [null, null, null], 'I02 none of 3A, 3B, 7A is rendered on a salesperson\'s line while sf_persona_confirmed is unset');
        $ctx->eq([QuoteChaseText::form(2, 'A', 'Grace', false), QuoteChaseText::form(3, 'A', 'Grace', false), QuoteChaseText::form(1, 'B', 'Grace', false)],
                 [['key' => '3A', 'persona' => true, 'held' => 'persona_wording_pending'], ['key' => '7B', 'persona' => true, 'held' => null],
                  ['key' => '1B', 'persona' => true, 'held' => null]],
                 'I02 form(): day 3 held (persona_wording_pending), day 7 always B, day 1 as it alternates');
        $ctx->eq([QuoteChaseText::render(2, 'A', 'Q-9001', '2026-11-20', 'Grace', true), QuoteChaseText::render(3, 'A', 'Q-9001', '2026-11-20', 'Grace', true),
                  QuoteChaseText::form(2, 'B', 'Grace', true)],
                 [$fill(QF_GOLD_COMPOSED['3A'], 'Grace'), $fill(QF_GOLD_COMPOSED['7A'], 'Grace'), ['key' => '3B', 'persona' => true, 'held' => null]],
                 'I02 control: confirmed, the composed forms render with the first name');
        $ctx->eq([QuoteChaseText::personaConfirmed([]), QuoteChaseText::personaConfirmed(['sf_persona_confirmed' => 'yes']),
                  QuoteChaseText::personaConfirmed(['sf_persona_confirmed' => '2026-11-05'])], [false, false, true], 'I02 sf_persona_confirmed holds only as a date');
    });

    $ctx->run('I03 an owner line with no usable first name: each step\'s department wording, confirmed or not (QM68)', function () use ($ctx, $steps, $fill) {
        $got = []; $want = [];
        foreach ([false, true] as $conf) {
            foreach ($steps as $step => $day) {
                foreach (['A', 'B'] as $v) {
                    $f = QuoteChaseText::form($step, $v, '', $conf);
                    $got[($conf ? 'c' : 'u') . $day . $v] = [$f['held'], $f['persona'], QuoteChaseText::render($step, $v, 'Q-9001', '2026-11-20', '', $conf)];
                    $want[($conf ? 'c' : 'u') . $day . $v] = [null, false, $fill(QF_GOLD_DEPARTMENT[$day . $v])];
                }
            }
        }
        $ctx->eq($got, $want, 'I03 never held, never the prefix on an empty name: the department wording byte for byte §H.1');
    });

    $ctx->run('I04 the template rules: digits, currency and plan, names, STOP, escalation, the fence', function () use ($ctx, $steps) {
        $texts = [];
        foreach ($steps as $step => $day) foreach (['A', 'B'] as $v) {
            $texts['d' . $day . $v] = (string)QuoteChaseText::render($step, $v, 'Q-9001', '2026-11-20', null);
            $p = QuoteChaseText::render($step, $v, 'Q-9001', '2026-11-20', 'Grace', true);
            if ($p !== null) $texts['p' . $day . $v] = $p;
        }
        $bad = [];
        foreach ($texts as $k => $t) {
            $rest = str_replace(['Q-9001', '20 November'], '', $t);
            if (preg_match('/\d/', $rest)) $bad[] = "{$k}: a digit";
            if (preg_match('/\b(UGX|USD|SSP|KES|shillings?|price|cost|\$|Business|Residential|Standard|Mini|Roam|Priority)\b/i', $t)) $bad[] = "{$k}: currency or plan";
            if (strpos($t, 'STOP') === false) $bad[] = "{$k}: no STOP";
            if (EmailReplyPolicy::scanForEscalation($t)['escalate']) $bad[] = "{$k}: escalation word";
            if (PlanFenceGuard::apply($t, [])['appended']) $bad[] = "{$k}: the fence appends";
        }
        $ctx->eq([count($texts), $bad], [12, []], 'I04 twelve texts: no digit but {ref}\'s and {date}\'s, no currency or plan, STOP in each, no escalation word, the fence a no-op');
        $slots = [];
        foreach (array_merge(QuoteChaseText::DEPARTMENT, QuoteChaseText::LITERAL, QuoteChaseText::COMPOSED) as $t) {
            preg_match_all('/\{([a-z ]+)\}/', $t, $m);
            foreach ($m[1] as $s) $slots[$s] = true;
        }
        ksort($slots);
        $ctx->eq(array_keys($slots), ['date', 'first name', 'ref'], 'I04 the only slots are {ref}, {date} and {first name}: no customer name');
    });

    $ctx->run('I05 the template id: template:quote_chase_v1:<day><A|B>[p]', function () use ($ctx) {
        $ctx->eq([QuoteChaseText::templateId(1, 'A', false), QuoteChaseText::templateId(2, 'B', true), QuoteChaseText::templateId(3, 'B', false)],
                 ['template:quote_chase_v1:1A', 'template:quote_chase_v1:3Bp', 'template:quote_chase_v1:7B'], 'I05 ids');
        $ctx->eq([QuoteChaseText::parseId('template:quote_chase_v1:3Bp'), QuoteChaseText::parseId('template:quote_chase_v1:7A'), QuoteChaseText::parseId('claude-x')],
                 [[2, 'B', true], [3, 'A', false], null], 'I05 parseId() reads them back; a model name is not one');
    });

    $ctx->run('I06 approve(): the unchanged text the screen posts is approved unedited; an edit refused (QM45, QM61)', function () use ($ctx) {
        $b = $ctx->book(qf_ccfg(), 'i'); $p = $b['pdo']; $svc = new FollowUpService($p);
        $c = qf_conv($p, '256772502101'); qf_in($p, $c, 'hi (test)', '2026-10-30 09:00:00');
        $k = qf_track_row($p, 'Q-9101', '256772502101', $c, ['sent_at' => '2026-11-02 07:00:00']);
        $fid = $svc->openQuote(['id' => $c, 'channel' => 'support', 'phone' => '256772502101'],
                               ['quote_key' => $k, 'ref' => 'Q-9101', 'valid_until' => '2026-11-20', 'sent_at' => '2026-11-02 07:00:00', 'due_at' => '2026-11-03 07:00:00']);
        $body = (string)QuoteChaseText::render(1, 'A', 'Q-9101', '2026-11-20', null);
        $d1 = $svc->draftTemplate($fid, $body, QuoteChaseText::templateId(1, 'A', false));
        $posted = str_replace("\n", "\r\n", $body . "\n");   // the screen's textarea: CRLF, a trailing newline
        $r1 = $svc->approve((int)$d1['id'], 'staff:Tester', $posted);
        $row = $svc->getDraft((int)$d1['id']);
        $ctx->eq([$r1['ok'] ?? null, $row['status'], $row['edited_body']], [true, 'approved', null], 'I06 the unchanged text (CRLF, trimmed) is approved, edited_body NULL');
        $p->exec("UPDATE followup_drafts SET status = 'expired' WHERE id = " . (int)$d1['id']);
        $d2 = $svc->draftTemplate($fid, $body, QuoteChaseText::templateId(1, 'A', false));
        $r2 = $svc->approve((int)$d2['id'], 'staff:Tester', $body . ' Our price is lower this week.');
        $ctx->eq([$r2['ok'] ?? null, $r2['error'] ?? null, $svc->getDraft((int)$d2['id'])['status']],
                 [false, 'A quotation reminder is sent only in the approved wording; reject it and write to the customer from the Inbox.', 'pending'],
                 'I06 an edited quotation reminder is refused with the screen\'s message, and stays pending');
        $e = $svc->open(['id' => qf_conv($p, '256772502102'), 'channel' => 'support', 'phone' => '256772502102', 'last_customer_at' => '2026-10-30 09:00:00']);
        $d3 = $svc->draft((int)$e['id'], ['verdict' => 'SEND', 'message' => 'Hello, any questions? (test)', 'reason' => 'test']);
        $r3 = $svc->approve((int)$d3['id'], 'staff:Tester', 'Hello, edited by staff (test)');
        $ctx->eq([$r3['ok'] ?? null, $svc->getDraft((int)$d3['id'])['edited_body']], [true, 'Hello, edited by staff (test)'], 'I06 control: an enquiry draft keeps 5.18.103\'s edit');
    });

    $ctx->run('I07 the close-reason mapping (§H.2.10.8)', function () use ($ctx) {
        $m = [];
        foreach (['replied', 'opted_out', 'human_closed', 'staff_cancelled', 'escalated', 'converted', 'not_interested', 'staff', 'exhausted',
                  'send_failed', 'cancelled', 'channel_assistant_disabled', 'rollback'] as $r) {
            $m[$r] = QuoteTracker::closeOutcome($r);
        }
        $S = function (?string $s, ?string $h = null) { return ['status' => $s, 'hold' => $h]; };
        $ctx->eq($m, ['replied' => $S('answered'), 'opted_out' => $S('opted_out'), 'human_closed' => $S('taken'), 'staff_cancelled' => $S('taken'),
                      'escalated' => $S('taken'), 'converted' => $S('accepted'), 'not_interested' => $S('declined'), 'staff' => $S('colleague'),
                      'exhausted' => $S(null), 'send_failed' => $S(null, 'send_failed'), 'cancelled' => $S(null, 'cancelled'),
                      'channel_assistant_disabled' => $S(null, 'channel_assistant_disabled'), 'rollback' => $S(null, 'rollback')],
                 'I07 every close reason maps as the table says; exhausted stays open; the rest stay open with their code as chase_hold');
    });
}];
