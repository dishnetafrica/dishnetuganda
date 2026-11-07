<?php
declare(strict_types=1);
/**
 * Weakened copies for section A of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.14: MG1-MG7, and WB25's
 * trigger half). Each a one-fault copy of migration 089 that section A must catch. Appended to $M by the runner.
 * MG6 (090's or 091's _schema_checks CREATE differing from 089's): A07 compares every later migration that writes
 * _schema_checks; MG6-089 weakens 089's own copy of the text and MG6-090 (stage B5) 090's; 091's waits for 091. TEST ONLY.
 */
$G = 'migrations/089_quote_followups.sql';
$M = array_merge($M, [
    ['label' => 'MG1 a trigger body with a word ending in the three letters (QM37)', 'file' => $G,
     'old' => "BEGIN SELECT RAISE(ABORT, 'quote_list_sends: a list record is never deleted'); END;",
     'new' => "BEGIN SELECT RAISE(ABORT, 'quote_list_sends: a list record is never deleted') FROM (SELECT 1 AS legend); END;",
     'sections' => 'A', 'cases' => 'A01', 'pattern' => '/A01 /'],
    ['label' => 'MG2 the self-check count off by one (no row)', 'file' => $G,
     'old' => "SELECT '089_quote_followups.sql', 38,", 'new' => "SELECT '089_quote_followups.sql', 39,",
     'sections' => 'A', 'cases' => 'A01', 'pattern' => '/A01 /'],
    ['label' => 'MG3 the proactive CHECK dropped', 'file' => $G,
     'old' => "  CHECK (proactive = (kind IN ('quote','enquiry','staff_list','offer','reminder','winback','brief'))),\n", 'new' => '',
     'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
    ['label' => 'MG4a the self-check INSERT, as split, carrying INDEX (no such table swallowed; the log reads OK, the row missing)', 'file' => $G,
     'old' => "type NOT IN ('table','trigger','view')", 'new' => "type IN ('index')",
     'sections' => 'A', 'cases' => 'A06', 'pattern' => '/A06 /'],
    ['label' => 'MG4b the self-check INSERT, as split, carrying CREATE (no such column swallowed)', 'file' => $G,
     'old' => "SELECT '089_quote_followups.sql', 38,", 'new' => "SELECT '089_quote_followups.sql' AS recreated, 38,",
     'sections' => 'A', 'cases' => 'A06', 'pattern' => '/A06 /'],
    ['label' => 'MG5a the tracker\'s erase trigger allowing any new phone', 'file' => $G,
     'old' => "BEFORE UPDATE OF phone ON quote_tracker WHEN NEW.phone <> 'erased'", 'new' => 'BEFORE UPDATE OF phone ON quote_tracker WHEN 0',
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
    ['label' => 'MG5b the ledger\'s erase trigger allowing any new phone', 'file' => $G,
     'old' => 'BEFORE UPDATE OF phone ON wa_auto_sends WHEN NEW.phone IS NOT NULL', 'new' => 'BEFORE UPDATE OF phone ON wa_auto_sends WHEN 0',
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
    ['label' => 'MG5c the marks\' erase trigger allowing any new phone', 'file' => $G,
     'old' => "BEFORE UPDATE OF phone ON wa_seen_marks WHEN NEW.phone <> ''", 'new' => 'BEFORE UPDATE OF phone ON wa_seen_marks WHEN 0',
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
    ['label' => 'MG6-089 089\'s _schema_checks CREATE differing from D\'s text by one byte', 'file' => $G,
     'old' => "  migration  TEXT PRIMARY KEY,\n  expected   INTEGER NOT NULL,", 'new' => "  migration  TEXT PRIMARY KEY,\n  expected  INTEGER NOT NULL,",
     'sections' => 'A', 'cases' => 'A07', 'pattern' => '/A07 /'],
    // 5.18.104 (root docs/73 §H.3.3, §H.3.24, ruling R9): MG6 for 090, now that 090 exists (stage B5).
    ['label' => 'MG6-090 090\'s _schema_checks CREATE differing from 089\'s by one byte', 'file' => 'migrations/090_wa_voice.sql',
     'old' => "  migration  TEXT PRIMARY KEY,\n  expected   INTEGER NOT NULL,", 'new' => "  migration  TEXT PRIMARY KEY,\n  expected  INTEGER NOT NULL,",
     'sections' => 'A', 'cases' => 'A07', 'pattern' => '/A07 /'],
    // 5.18.104 (root docs/73 §H.4.5, §H.4.16, ruling R9): MG6 for 091, now that 091 exists (stage B7).
    ['label' => 'MG6-091 091\'s _schema_checks CREATE differing from 089\'s by one byte', 'file' => 'migrations/091_wa_offers.sql',
     'old' => "  migration  TEXT PRIMARY KEY,\n  expected   INTEGER NOT NULL,", 'new' => "  migration  TEXT PRIMARY KEY,\n  expected  INTEGER NOT NULL,",
     'sections' => 'A', 'cases' => 'A07', 'pattern' => '/A07 /'],
    ['label' => 'MG7a was_identity_fixed without the four limits', 'file' => $G,
     'old' => '  gap_s, claimed_at, win_from, win_to, cap_day, cap_hour ON wa_auto_sends', 'new' => '  gap_s, claimed_at ON wa_auto_sends',
     'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
    ['label' => 'MG7b a limit\'s CHECK dropped (cap_day)', 'file' => $G,
     'old' => "  CHECK (cap_day IS NULL OR cap_day BETWEEN 1 AND 60),\n", 'new' => '',
     'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
    ['label' => 'WB25-A settle allowed twice: was_settled_once never fires', 'file' => $G,
     'old' => 'BEFORE UPDATE OF outcome ON wa_auto_sends WHEN OLD.outcome IS NOT NULL', 'new' => 'BEFORE UPDATE OF outcome ON wa_auto_sends WHEN 0',
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
]);
