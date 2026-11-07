<?php
declare(strict_types=1);
/**
 * Section A — migration 091 and OfferSchema (5.18.104, root docs/73 §H.4.5, §H.4.16 A; ruling R9). TEST ONLY.
 *
 * 091 on a fresh book and on a book built to 090 with rows in its tables (every earlier table's rows unchanged); each of
 * its fourteen triggers refusing its act, with the act it allows beside it; the one-live-offer index; the CHECKs a
 * settled recipient needs (judged_at, day_max) and the ledger form of a handset; idx_wor_once with erased rows; the
 * self-check driven through the runner per error class on the statement as split (a trigger missing, _schema_checks
 * without its column, _schema_checks absent before 091); OfferSchema's problems. The comparison of 091's _schema_checks
 * CREATE with 089's is tests/test_quote_followup.php A07 (one test compares the three texts, ruling R9).
 */

/** A connection of its own on a book file, exceptions on. */
function wo_pdo(string $file): \PDO
{
    $p = new \PDO('sqlite:' . $file);
    $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $p->exec('PRAGMA busy_timeout = 5000');
    return $p;
}

/** The error a statement raises, '' when it runs. */
function wo_try(\PDO $pdo, string $sql, array $a = []): string
{
    try { $st = $pdo->prepare($sql); $st->execute($a); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
}

/** A book built to 090 by the copy's runner (091 held back), with a row in a handful of earlier tables. [pdo, file]. */
function wo_book090(QfCtx $ctx): array
{
    $mig = $ctx->dir('mig090');
    foreach (glob($ctx->tree() . '/migrations/*.sql') ?: [] as $f) {
        if (strcmp(basename($f), '091') < 0) copy($f, $mig . '/' . basename($f));
    }
    $d = $ctx->dir('b090');
    $pdo = wo_pdo($d . '/plugin.sqlite3');
    (new MigrationRunner($pdo, $mig, $d . '/migration.log'))->run();
    $pdo->exec("INSERT INTO contact_optouts (phone, channel, scope, reason, source) VALUES ('256700000101', '*', 'proactive', 'customer_request', 'keyword')");
    $pdo->exec("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, local_day, gap_s, outcome) VALUES ('i:sales-ug', 'reminder', 'r:1', 'record', 1, '2026-11-02', 60, 'sent')");
    $pdo->exec("INSERT INTO dist_contacts (partner_id, phone) VALUES (1, '+256700000102')");
    return [$pdo, $d];
}

/** Every user table's rows, hashed: name => sha1. */
function wo_hashes(\PDO $pdo, array $except = []): array
{
    $out = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $except, true)) continue;
        $out[$t] = sha1(json_encode($pdo->query('SELECT * FROM [' . $t . '] ORDER BY rowid')->fetchAll(\PDO::FETCH_ASSOC)));
    }
    return $out;
}

/** 091 alone, in a folder of its own (with $edit applied to its text when given), run by the copy's runner on $pdo. */
function wo_run091(QfCtx $ctx, \PDO $pdo, ?callable $edit = null): array
{
    $sql = (string)file_get_contents($ctx->tree() . '/migrations/091_wa_offers.sql');
    if ($edit !== null) $sql = $edit($sql);
    $d = $ctx->dir('one091');
    file_put_contents($d . '/091_wa_offers.sql', $sql);
    $log = $d . '/migration.log';
    $res = (new MigrationRunner($pdo, $d, $log))->run();
    return [$res, (string)@file_get_contents($log)];
}

$WO_SECTIONS['A'] = ['title' => 'Migration 091 and OfferSchema (§H.4.5; ruling R9)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();
    $now = UtcClock::stamp($T0);

    $ctx->run('A01 091 on a fresh book: 25 objects, the self-check row, OfferSchema sound', function () use ($ctx) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $ctx->eq(OfferSchema::check($p), [], 'A01 control: OfferSchema::check() is empty on a fresh book');
        $ctx->eq($p->query("SELECT migration, expected, found FROM _schema_checks WHERE migration = '091_wa_offers.sql'")->fetch(\PDO::FETCH_NUM),
                 ['091_wa_offers.sql', 25, 25], 'A01 the _schema_checks row reads 25/25 under the key 091_wa_offers.sql');
        $ctx->eq((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'wa_offer_schema_check'")->fetchColumn(), 0,
                 'A01 there is no wa_offer_schema_check table (one self-check table for 089, 090 and 091)');
        $n = [];
        foreach (OfferSchema::TABLES as $t) $n[$t] = (int)$p->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn();
        $ctx->eq($n, ['wa_offers' => 0, 'wa_offer_recipients' => 0, 'wa_offer_tests' => 0, 'wa_offer_events' => 0], 'A01 the four tables are empty');
        $ctx->eq([count(OfferSchema::TABLES), count(OfferSchema::INDEXES), count(OfferSchema::TRIGGERS)], [4, 7, 14], 'A01 four tables, seven indexes, fourteen triggers');
    });

    $ctx->run('A02 091 on a book built to 090, with rows: every earlier table unchanged (Uganda and South Sudan books alike)', function () use ($ctx) {
        foreach (['uganda', 'south-sudan'] as $tenant) {
            [$p] = wo_book090($ctx);
            $p->exec("CREATE TABLE IF NOT EXISTS kyc_config (id INTEGER PRIMARY KEY, data TEXT)");
            $p->prepare('INSERT OR REPLACE INTO kyc_config (id, data) VALUES (0, ?)')->execute([json_encode(['tenant_profile' => $tenant])]);
            $ctx->eq(OfferSchema::check($p)[0] ?? '', 'missing:wa_offers', "A02 control ({$tenant}): before 091 OfferSchema finds wa_offers missing");
            $before = wo_hashes($p);
            [$res, $log] = wo_run091($ctx, $p);
            $ctx->eq([$res[0]['status'] ?? null, strpos($log, 'OK: 091_wa_offers.sql') !== false], ['ok', true], "A02 ({$tenant}) 091 runs clean, logged OK");
            $after = wo_hashes($p, array_merge(OfferSchema::TABLES, ['_schema_checks', '_migrations', 'sqlite_sequence']));
            $ctx->eq(array_diff_assoc($after, $before), [], "A02 ({$tenant}) every earlier table's rows are unchanged");
            $ctx->eq(OfferSchema::check($p), [], "A02 ({$tenant}) OfferSchema::check() is empty after 091");
        }
    });

    $ctx->run('A03 the offer triggers: never deleted; content fixed once started; closed is final; no way back to draft', function () use ($ctx, $T0, $now) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $id = wo_offer($b, $T0);
        $ctx->eq(wo_try($p, 'UPDATE wa_offers SET wording_1 = ? WHERE id = ?', ['A changed first wording for the test (draft).', $id]), '',
                 'A03 control: a draft\'s wording can be changed');
        $ctx->t(strpos(wo_try($p, 'DELETE FROM wa_offers WHERE id = ?', [$id]), 'never deleted') !== false, 'A03 wo_never_deleted refuses a delete');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offers SET state = 'paused' WHERE id = ?", [$id]), 'no such change') !== false, 'A03 wo_state_path refuses draft → paused');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offers SET state = 'done', live_slot = NULL, started_at = ? WHERE id = ?", [$now, $id]), 'no such change') !== false,
                'A03 wo_state_path refuses draft → done');
        $ctx->eq(wo_try($p, "UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ? WHERE id = ?", [$now, $id]), '', 'A03 control: draft → running');
        $ctx->t(strpos(wo_try($p, 'UPDATE wa_offers SET wording_2 = ? WHERE id = ?', ['A changed second wording after the start.', $id]), 'fixed once') !== false,
                'A03 wo_content_fixed refuses a wording changed after the start');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offers SET audience_size = '200' WHERE id = ?", [$id]), 'fixed once') !== false, 'A03 … and the audience size');
        $ctx->eq(wo_try($p, "UPDATE wa_offers SET queued_n = 3 WHERE id = ?", [$id]), '', 'A03 control: a running offer\'s counts may change');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offers SET state = 'draft', live_slot = NULL WHERE id = ?", [$id]), 'no such change') !== false,
                'A03 wo_state_path refuses running → draft');
        $ctx->eq(wo_try($p, "UPDATE wa_offers SET state = 'stopped', live_slot = NULL, close_reason = 'stopped' WHERE id = ?", [$id]), '', 'A03 control: running → stopped');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offers SET closed_by = 'x' WHERE id = ?", [$id]), 'final') !== false, 'A03 wo_closed_is_final refuses any change to a stopped offer');
    });

    $ctx->run('A04 two live offers refused (idx_wo_live); a sent or uncertain row needs judged_at and day_max; the handset form', function () use ($ctx, $T0, $now) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $a = wo_offer($b, $T0); $c = wo_offer($b, $T0, ['title' => 'Second offer (test)']);
        $ctx->eq(wo_try($p, "UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ? WHERE id = ?", [$now, $a]), '', 'A04 control: one offer goes live');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ? WHERE id = ?", [$now, $c]), 'UNIQUE') !== false,
                'A04 a second live offer is refused by idx_wo_live');
        $ins = "INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, sent_at, judged_at, day_max,
                                                 variant, wa_message_id, echo, claim_token, claimed_at, attempt) VALUES (?, ?, 1, 1, 'sales', ?, ?, ?, ?, ?, ?, 1, ?, ?, 't', ?, 1)";
        $ctx->eq(wo_try($p, $ins, [$a, '256700000201', 'i:sales-ug', $now, 'sent', $now, $now, 20, 'WO-A04-1', 'claimed', $now]), '', 'A04 control: a sent row with every column');
        $ctx->t(strpos(wo_try($p, $ins, [$a, '256700000202', 'i:sales-ug', $now, 'sent', $now, null, 20, 'WO-A04-2', 'claimed', $now]), 'CHECK') !== false,
                'A04 a sent row without judged_at is refused');
        $ctx->t(strpos(wo_try($p, $ins, [$a, '256700000203', 'i:sales-ug', $now, 'uncertain', $now, $now, null, null, 'none', $now]), 'CHECK') !== false,
                'A04 an uncertain row without day_max is refused');
        $ctx->t(strpos(wo_try($p, $ins, [$a, '256700000204', 'Sales-UG', $now, 'queued', null, null, null, null, null, null]), 'CHECK') !== false,
                'A04 a handset written without i: (the raw instance name) is refused');
    });

    $ctx->run('A05 recipients: never deleted, settled is final, who and in which order fixed, a phone only erased, the outcome fixed, marks once', function () use ($ctx, $T0, $now) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $o = wo_offer($b, $T0);
        $p->prepare("UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ? WHERE id = ?")->execute([$now, $o]);
        $q = "INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at) VALUES (?, ?, ?, 1, 'sales', 'i:sales-ug', ?)";
        $p->prepare($q)->execute([$o, '256700000301', 1, $now]);
        $r = (int)$p->lastInsertId();
        $ctx->eq(wo_try($p, "UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 't', claimed_at = ?, attempt = 1 WHERE id = ?", [$now, $r]), '',
                 'A05 control: queued → claimed');
        $ctx->eq(wo_try($p, 'UPDATE wa_offer_recipients SET judged_at = ?, day_max = 20, ledger_claim = 5, variant = 1 WHERE id = ?', [$now, $r]), '',
                 'A05 control: a claimed row\'s outcome columns may be written');
        $ctx->t(strpos(wo_try($p, 'UPDATE wa_offer_recipients SET rank = 9 WHERE id = ?', [$r]), 'fixed') !== false, 'A05 wor_identity_fixed refuses a new rank');
        $ctx->t(strpos(wo_try($p, 'UPDATE wa_offer_recipients SET last_in_at = ? WHERE id = ?', [$now, $r]), 'fixed') !== false, 'A05 … and a new last_in_at');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offer_recipients SET phone = '256700000399' WHERE id = ?", [$r]), 'only be erased') !== false,
                'A05 wor_phone_erase_only refuses a phone changed to another number');
        $ctx->eq(wo_try($p, "UPDATE wa_offer_recipients SET state = 'sent', sent_at = ?, wa_message_id = 'WO-A05', echo = 'claimed' WHERE id = ?", [$now, $r]), '',
                 'A05 control: claimed → sent');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offer_recipients SET state = 'uncertain' WHERE id = ?", [$r]), 'final') !== false, 'A05 wor_final refuses a settled row\'s new state');
        foreach (['attempt = 2', "echo = 'late'", 'judged_at = NULL', 'day_max = 5', "reason = 'x'", 'variant = 2'] as $set) {
            $ctx->t(strpos(wo_try($p, "UPDATE wa_offer_recipients SET {$set} WHERE id = ?", [$r]), 'what happened is fixed') !== false,
                    "A05 wor_outcome_fixed refuses {$set} on a settled row");
        }
        $ctx->eq(wo_try($p, 'UPDATE wa_offer_recipients SET phone = NULL WHERE id = ?', [$r]), '', 'A05 an erase to NULL is allowed on a settled row (ruling R10)');
        $ctx->eq(wo_try($p, 'UPDATE wa_offer_recipients SET replied_at = ? WHERE id = ?', [$now, $r]), '', 'A05 control: a reply recorded once');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offer_recipients SET replied_at = '2026-11-03 10:00:00' WHERE id = ?", [$r]), 'recorded once') !== false,
                'A05 wor_marks_once refuses a second reply time');
        $ctx->eq(wo_try($p, 'UPDATE wa_offer_recipients SET stop_at = ? WHERE id = ?', [$now, $r]), '', 'A05 control: a STOP recorded once');
        $ctx->t(strpos(wo_try($p, 'UPDATE wa_offer_recipients SET stop_at = NULL WHERE id = ?', [$r]), 'recorded once') !== false, 'A05 … and never cleared');
        $ctx->t(strpos(wo_try($p, 'DELETE FROM wa_offer_recipients WHERE id = ?', [$r]), 'never deleted') !== false, 'A05 wor_never_deleted refuses a delete');
    });

    $ctx->run('A06 idx_wor_once: one row per phone per offer, two erased rows allowed', function () use ($ctx, $T0, $now) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $o = wo_offer($b, $T0);
        $q = "INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at) VALUES (?, ?, ?, 1, 'sales', 'i:sales-ug', ?)";
        $ctx->eq(wo_try($p, $q, [$o, '256700000401', 1, $now]), '', 'A06 control: a first row for the phone');
        $ctx->t(strpos(wo_try($p, $q, [$o, '256700000401', 2, $now]), 'UNIQUE') !== false, 'A06 a second row for the same phone in the same offer is refused');
        $ctx->eq([wo_try($p, $q, [$o, null, 3, $now]), wo_try($p, $q, [$o, null, 4, $now])], ['', ''], 'A06 two erased rows (NULL phones) in one offer are allowed');
        $ctx->t(strpos(wo_try($p, $q, [$o, '+256700000402', 5, $now]), 'CHECK') !== false, 'A06 a phone not in international digits is refused');
    });

    $ctx->run('A07 tests and the trail: a settled test is final, never deleted; the trail never changed or deleted', function () use ($ctx, $T0, $now) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $o = wo_offer($b, $T0);
        $p->prepare("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset) VALUES (?, 'h', 1, 7, 'sales', 'i:sales-ug')")->execute([$o]);
        $t = (int)$p->lastInsertId();
        $ctx->eq(wo_try($p, "UPDATE wa_offer_tests SET state = 'sent', wa_message_id = 'WO-A07', echo = 'claimed', ledger = 'recorded' WHERE id = ?", [$t]), '',
                 'A07 control: sending → sent');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offer_tests SET state = 'uncertain' WHERE id = ?", [$t]), 'final') !== false, 'A07 wot_final refuses a settled test\'s new state');
        $ctx->t(strpos(wo_try($p, 'DELETE FROM wa_offer_tests WHERE id = ?', [$t]), 'never deleted') !== false, 'A07 wot_never_deleted refuses a delete');
        $ev = (int)$p->query('SELECT MAX(id) FROM wa_offer_events')->fetchColumn();
        $ctx->t($ev > 0, 'A07 control: the offer\'s creation left an event');
        $ctx->t(strpos(wo_try($p, "UPDATE wa_offer_events SET detail = 'x' WHERE id = ?", [$ev]), 'never changed') !== false, 'A07 woe_never_changed refuses a change');
        $ctx->t(strpos(wo_try($p, 'DELETE FROM wa_offer_events WHERE id = ?', [$ev]), 'never deleted') !== false, 'A07 woe_never_deleted refuses a delete');
    });

    $ctx->run('A08 the self-check per error class, through the runner on the statement as split (ruling R9)', function () use ($ctx) {
        // a trigger missing before the self-check: CHECK constraint failed, PARTIAL, no row
        [$p] = wo_book090($ctx);
        [$res, $log] = wo_run091($ctx, $p, function (string $s): string {
            return (string)preg_replace('/CREATE TRIGGER IF NOT EXISTS woe_never_changed .*?END;\n/s', '', $s, 1);
        });
        $ctx->eq([$res[0]['status'] ?? null, strpos($log, 'PARTIAL: 091_wa_offers.sql') !== false, strpos($log, 'CHECK constraint failed') !== false],
                 ['partial', true, true], 'A08 one trigger dropped before the self-check: CHECK constraint failed, logged PARTIAL');
        $ctx->eq((int)$p->query("SELECT COUNT(*) FROM _schema_checks WHERE migration = '091_wa_offers.sql'")->fetchColumn(), 0, 'A08 … and no row');
        $ctx->t(in_array('missing:woe_never_changed', OfferSchema::check($p), true) && in_array('schema_check_missing', OfferSchema::check($p), true),
                'A08 … and OfferSchema names the trigger and the missing row');
        // _schema_checks without the column found: not swallowed
        [$p] = wo_book090($ctx);
        $p->exec('DROP TABLE _schema_checks');
        $p->exec('CREATE TABLE _schema_checks (migration TEXT PRIMARY KEY, expected INTEGER NOT NULL, checked_at TEXT)');
        [$res, $log] = wo_run091($ctx, $p);
        $ctx->eq([$res[0]['status'] ?? null, strpos($log, 'PARTIAL: 091_wa_offers.sql') !== false], ['partial', true],
                 'A08 _schema_checks lacking the column found: logged PARTIAL');
        $ctx->eq((int)$p->query('SELECT COUNT(*) FROM _schema_checks')->fetchColumn(), 0, 'A08 … and no row');
        // _schema_checks absent before 091: made by 091's own CREATE
        [$p] = wo_book090($ctx);
        $p->exec('DROP TABLE _schema_checks');
        [$res, $log] = wo_run091($ctx, $p);
        $ctx->eq($p->query("SELECT expected, found FROM _schema_checks WHERE migration = '091_wa_offers.sql'")->fetch(\PDO::FETCH_NUM), [25, 25],
                 'A08 _schema_checks absent before 091 is made by 091\'s own CREATE, and the row reads 25/25');
        $ctx->eq([$res[0]['status'] ?? null, OfferSchema::check($p)], ['ok', []], 'A08 … logged OK, OfferSchema sound');
    });

    $ctx->run('A09 OfferSchema: each problem named, never a bare count', function () use ($ctx) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $ctx->eq(OfferSchema::check($p), [], 'A09 control: sound');
        $p->exec('DROP INDEX idx_wor_phone');
        $ctx->eq(OfferSchema::check($p), ['missing:idx_wor_phone'], 'A09 an index dropped is named');
        $p->exec("UPDATE _schema_checks SET expected = 24, found = 24 WHERE migration = '091_wa_offers.sql'");
        $ctx->eq(OfferSchema::check($p), ['missing:idx_wor_phone', 'schema_check_count'], 'A09 a self-check row that is not 25/25');
        $p->exec("DELETE FROM _migrations WHERE filename = '091_wa_offers.sql'");
        $ctx->t(in_array('not_applied', OfferSchema::check($p), true), 'A09 a book whose runner never recorded 091');
        $p->exec('CREATE INDEX idx_wor_phone ON wa_offer_recipients(phone, sent_at)');
        $p->exec('DROP TRIGGER wor_marks_once');
        $p->exec("CREATE VIEW wor_marks_once AS SELECT 1");
        $ctx->t(in_array('wrong_type:wor_marks_once', OfferSchema::check($p), true), 'A09 an object of the wrong type is named');
    });

    $ctx->run('A10 a Start through the real gateway writes each row\'s handset in the ledger form 091\'s CHECK requires (O-D3, S2-03)', function () use ($ctx, $T0, $now) {
        $b = wo_book($ctx, wo_cfg(), 'a');
        $p = $b['pdo'];
        $ctl = wo_offer($b, $T0, ['title' => 'Control offer (test)']);
        $ctx->t(strpos(wo_try($p, "INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at) VALUES (?, '256700001009', 1, 1, 'sales', 'Sales-UG', ?)",
                              [$ctl, $now]), 'CHECK') !== false, 'A10 control: the raw instance name as a handset is refused by 091\'s CHECK');
        wo_warm($b, '256700001001', $T0, 86400);
        wo_warm($b, '256700001002', $T0, 90000, 'support');
        wo_warm($b, '256700001003', $T0, 93600, 'account');
        $err = '';
        try {
            [$id, $r] = wo_started($b, $T0, new EvolutionOfferGateway($p, $b['cfg'], $b['data'], $b['store']));
        } catch (\Throwable $e) {
            [$id, $r, $err] = [0, ['ok' => false], get_class($e) . ': ' . $e->getMessage()];
        }
        $ctx->t(!empty($r['ok']), 'A10 the Start through the real gateway is admitted', $err);
        $hs = $p->query("SELECT phone, handset FROM wa_offer_recipients WHERE offer_id = " . (int)$id . " ORDER BY phone")->fetchAll(\PDO::FETCH_NUM);
        $ctx->eq($hs, [['256700001001', 'i:sales-ug'], ['256700001002', 'i:dishnet-ug'], ['256700001003', 'i:dishnet-ug']],
                 'A10 every row\'s handset is WaSendBudget::handset(instance): i: and lower case, support and account one handset');
    });
}];
