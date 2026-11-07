<?php
declare(strict_types=1);

/**
 * OfferSchema — whether migration 091 is in place, object by object (5.18.104 P6, root docs/73 §H.4.5; ruling R9).
 *
 * check() returns a list of fixed problems; an empty list means sound. It never counts "N tables" on its own (R-12): it
 * names each of 091's 25 objects with its type, reads each trigger's stored SQL for its END (the runner's splitter cuts a
 * body short at any word ending in those letters), the columns the sender and the checks depend on, the self-check row
 * 091 wrote into _schema_checks under its own filename, and the runner's record of the file. Read-only. PHP 7.4.
 */
final class OfferSchema
{
    const FILE = '091_wa_offers.sql';
    const EXPECTED = 25;
    const TABLES = ['wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events'];
    const INDEXES = ['idx_wo_live', 'idx_wo_state', 'idx_wor_once', 'idx_wor_next', 'idx_wor_phone', 'idx_wot_offer', 'idx_woe_offer'];
    const TRIGGERS = ['wo_never_deleted', 'wo_content_fixed', 'wo_closed_is_final', 'wo_state_path',
                      'wor_never_deleted', 'wor_final', 'wor_identity_fixed', 'wor_phone_erase_only', 'wor_outcome_fixed', 'wor_marks_once',
                      'wot_never_deleted', 'wot_final', 'woe_never_deleted', 'woe_never_changed'];
    const COLUMNS = ['wa_offer_recipients' => ['attempt', 'echo', 'judged_at', 'day_max'], 'wa_offer_tests' => ['echo', 'ledger']];

    /** @return string[] missing:<name> | wrong_type:<name> | trigger_body:<name> | column_missing:<t>.<c> | schema_check_missing | schema_check_count | not_applied */
    public static function check(\PDO $pdo): array
    {
        $bad = [];
        try {
            $st = $pdo->prepare('SELECT type, sql FROM sqlite_master WHERE name = ?');
            foreach (['table' => self::TABLES, 'index' => self::INDEXES, 'trigger' => self::TRIGGERS] as $type => $names) {
                foreach ($names as $n) {
                    $st->execute([$n]);
                    $r = $st->fetch(\PDO::FETCH_ASSOC);
                    $st->closeCursor();
                    if (!is_array($r)) { $bad[] = 'missing:' . $n; continue; }
                    if ((string)$r['type'] !== $type) { $bad[] = 'wrong_type:' . $n; continue; }
                    if ($type === 'trigger' && strtoupper(substr(rtrim((string)$r['sql']), -3)) !== 'END') $bad[] = 'trigger_body:' . $n;
                }
            }
            foreach (self::COLUMNS as $t => $cols) {
                $have = [];
                foreach ($pdo->query('PRAGMA table_info(' . $t . ')')->fetchAll(\PDO::FETCH_ASSOC) as $c) $have[(string)$c['name']] = true;
                foreach ($cols as $c) if (!isset($have[$c])) $bad[] = 'column_missing:' . $t . '.' . $c;
            }
        } catch (\Throwable $e) {
            $bad[] = 'unreadable';
            return $bad;
        }
        try {
            $st = $pdo->prepare('SELECT expected, found FROM _schema_checks WHERE migration = ?');
            $st->execute([self::FILE]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($r)) $bad[] = 'schema_check_missing';
            elseif ((int)$r['expected'] !== self::EXPECTED || (int)$r['found'] !== self::EXPECTED) $bad[] = 'schema_check_count';
        } catch (\Throwable $e) {
            $bad[] = 'schema_check_missing';
        }
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM _migrations WHERE filename = ?');
            $st->execute([self::FILE]);
            if ((int)$st->fetchColumn() !== 1) $bad[] = 'not_applied';
        } catch (\Throwable $e) {
            $bad[] = 'not_applied';
        }
        return $bad;
    }
}
