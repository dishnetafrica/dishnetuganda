<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';

/**
 * VoiceBudget — the cost bound on transcription: seconds per Kampala day and voice notes per conversation per day,
 * reserved before every call (5.18.104, root docs/73 §H.3.10).
 *
 * A `started` row in wa_voice_calls is written before the call, in one BEGIN IMMEDIATE with the two checks, so two
 * workers cannot both fit under the cap, and a crash after the reservation still counts. Every attempt counts (every
 * outcome is summed), by the packet-counted duration (VoiceDuration), never the announced one. The row records the
 * cap it applied (cap_day_s) and the rung the call is made at (voice_rung), which no later statement changes: the
 * checks judge each past call by them, never by the settings as they read now (ruling R18). The minutes and the
 * per-conversation maximum are those of the configuration VoiceTranscription::process read for this call (ruling R19).
 * PHP 7.4 compatible.
 */
final class VoiceBudget
{
    public const TZ = 'Africa/Kampala';
    public const RUNGS = ['fetch_only', 'dry_run', 'handover', 'reply'];

    /** @var \PDO */
    private $pdo;
    /** @var array */
    private $config;
    /** @var int|null a fixed clock for tests; null is time() */
    private $now;

    public function __construct(\PDO $pdo, array $config, ?int $now = null)
    {
        $this->pdo    = $pdo;
        $this->config = $config;
        $this->now    = $now;
    }

    /** Today in Kampala, the day a reservation counts against. */
    public function localDay(): string
    {
        return (new \DateTime('@' . ($this->now ?? time())))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /**
     * @return array ['ok' => true, 'id' => int] | ['ok' => false, 'reason' => 'budget_daily'|'budget_conversation'|'duplicate']
     */
    public function reserve(int $mediaId, int $convId, int $attempt, int $seconds, string $model, string $rung): array
    {
        if (!in_array($rung, self::RUNGS, true)) throw new \InvalidArgumentException('voice budget: unknown rung');
        $seconds = max(0, $seconds);
        $minutes = MediaPolicy::voiceDailyMinutes($this->config);
        $capDay  = $minutes * 60;
        $convMax = MediaPolicy::voiceConversationDailyMax($this->config);
        $day     = $this->localDay();

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->pdo->prepare('SELECT COALESCE(SUM(COALESCE(seconds_billed, seconds_reserved)), 0) FROM wa_voice_calls WHERE local_day = ?');
            $q->execute([$day]);
            $used = (int)$q->fetchColumn();
            if ($minutes === 0 || $used + $seconds > $capDay) {
                $this->pdo->exec('ROLLBACK');
                return ['ok' => false, 'reason' => 'budget_daily'];
            }
            $q = $this->pdo->prepare('SELECT DISTINCT media_id FROM wa_voice_calls WHERE conversation_id = ? AND local_day = ?');
            $q->execute([$convId, $day]);
            $notes = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
            if (count($notes) >= $convMax && !in_array($mediaId, $notes, true)) {
                $this->pdo->exec('ROLLBACK');
                return ['ok' => false, 'reason' => 'budget_conversation'];
            }
            $dup = $this->pdo->prepare('SELECT 1 FROM wa_voice_calls WHERE media_id = ? AND attempt = ?');
            $dup->execute([$mediaId, $attempt]);
            if ($dup->fetchColumn() !== false) {
                $this->pdo->exec('ROLLBACK');
                return ['ok' => false, 'reason' => 'duplicate'];
            }
            $this->pdo->prepare('INSERT INTO wa_voice_calls
                (media_id, conversation_id, attempt, model, local_day, seconds_reserved, cap_day_s, voice_rung, outcome)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'started\')')
                ->execute([$mediaId, $convId, $attempt, $model, $day, $seconds, $capDay, $rung]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->exec('COMMIT');
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing held */ }
            throw $e;
        }
    }

    /** The call's outcome, once: a row already settled is left as it is. */
    public function settle(int $id, string $outcome, int $http, int $ms, ?int $billedSeconds, string $reason = ''): bool
    {
        if (!in_array($outcome, ['ok', 'failed'], true)) $outcome = 'failed';
        $u = $this->pdo->prepare("UPDATE wa_voice_calls SET outcome = ?, http = ?, ms = ?, seconds_billed = ?, reason = ?,
                                   settled_at = datetime('now') WHERE id = ? AND outcome = 'started'");
        $u->execute([$outcome, $http > 0 ? $http : null, max(0, $ms), $billedSeconds !== null ? max(0, $billedSeconds) : null,
                     $reason !== '' ? $reason : null, $id]);
        return $u->rowCount() === 1;
    }
}
