<?php
declare(strict_types=1);
namespace Dn\Notify;

use Dn\Db\Database;

/**
 * The SMS sender chosen in the Admin panel (migration 033, docs/128 SS-9, SS-10):
 * what the worker runs when DN_SMS is NOT set in its environment.
 *
 * The worker asks isConfigured() every tick. This answers by reading the one
 * settings row, and builds a new sender ONLY when the row's version changed:
 *   none            → NullSms: nothing is claimed and codes expire (phase 2's
 *                     truth, unchanged);
 *   africastalking  → the key is opened IN MEMORY and the adapter is built.
 * So a change saved in the panel is in use within a second, with no restart.
 *
 * A setting it cannot use — a key that does not open (DNB_SECRET_KEY changed),
 * or values the adapter refuses — sends NOTHING and says so. The worker keeps
 * running, because the intents are the same process. That is failing closed,
 * loudly; it is never a fallback to another provider.
 *
 * It reports what it is doing through mt_sms_worker_report(): on every change,
 * and at most once a minute otherwise. The panel then shows what the worker did,
 * not what a form hoped. Every reason is fixed text: none contains the key.
 */
final class PanelSms implements SmsSender
{
    private SmsSender $inner;
    private ?int $version = null;
    private string $state = 'off';
    private string $detail = 'not read yet';
    private ?float $reportedAt = null;

    /** @param (\Closure(string, string, ?string): SmsSender)|null $build tests only: the adapter against a loopback fake */
    public function __construct(
        private readonly Database $db,
        private ?SmsSettings $crypto = null,
        private readonly ?\Closure $build = null,
        private readonly int $heartbeatSeconds = 60,
    ) {
        $this->inner = new NullSms();
    }

    public function bindingName(): string { return 'panel'; }

    public function isConfigured(): bool
    {
        $this->refresh();
        return $this->inner->isConfigured();
    }

    public function send(string $to, string $message): SmsResult
    {
        return $this->inner->send($to, $message);
    }

    /** What the worker applied — for its log line and the tests. Never the key. */
    public function status(): array
    {
        return ['version' => $this->version, 'state' => $this->state,
                'binding' => $this->inner->bindingName(), 'detail' => $this->detail];
    }

    public function refresh(): void
    {
        $row = $this->db->one('SELECT provider, username, sender, key_sealed, version FROM mt_sms_settings_for_worker()');
        if ($row === null) {
            // Migration 033 inserts the row and nothing deletes it: its absence
            // is a fault to report, not a setting to guess.
            if ($this->version !== -1 || $this->due()) {
                $this->inner = new NullSms();
                [$this->version, $this->state, $this->detail] = [-1, 'unusable', 'the SMS settings row is missing'];
                $this->report();
            }
            return;
        }
        $version = (int) $row['version'];
        if ($version !== $this->version) {
            $this->apply($row);
            $this->version = $version;
            $this->report();
        } elseif ($this->due()) {
            $this->report();
        }
    }

    private function apply(array $row): void
    {
        $this->inner = new NullSms();
        if ($row['provider'] !== 'africastalking') {
            [$this->state, $this->detail] = ['off', 'no SMS sender is set in the Admin panel'];
            return;
        }
        $user   = (string) $row['username'];
        $sender = $row['sender'] === null ? null : (string) $row['sender'];
        try {
            $crypto = $this->crypto ??= new SmsSettings();
        } catch (\Throwable) {
            [$this->state, $this->detail] = ['unusable', 'DNB_SECRET_KEY is not set for the worker, so the stored key cannot be opened'];
            return;
        }
        try {
            $key = $crypto->open((string) $row['key_sealed'], $user);
        } catch (\Throwable) {
            [$this->state, $this->detail] = ['unusable',
                'the stored key does not open under this DNB_SECRET_KEY; type the key again in the Admin panel'];
            return;
        }
        try {
            $this->inner = $this->build !== null
                ? ($this->build)($user, $key, $sender)
                : new AfricasTalkingSms($user, $key, $sender);
        } catch (\Throwable) {
            // The adapter's own messages name no value, but nothing it says is
            // kept: the reason is fixed text.
            $this->inner = new NullSms();
            [$this->state, $this->detail] = ['unusable', "the Africa's Talking adapter refused these settings"];
            return;
        }
        $sandbox = $this->inner instanceof AfricasTalkingSms && $this->inner->isSandbox();
        [$this->state, $this->detail] = ['in_use',
            $this->inner->bindingName() . ($sandbox ? " — SANDBOX: the provider's simulator, not phones" : '')];
    }

    private function due(): bool
    {
        return $this->reportedAt === null || microtime(true) - $this->reportedAt >= $this->heartbeatSeconds;
    }

    private function report(): void
    {
        $this->reportedAt = microtime(true);
        try {
            $this->db->one('SELECT mt_sms_worker_report(?,?,?) AS r',
                [$this->version < 0 ? null : $this->version, $this->state, $this->detail]);
        } catch (\Throwable) {
            // A status that cannot be written must never stop a code being sent.
        }
    }
}
