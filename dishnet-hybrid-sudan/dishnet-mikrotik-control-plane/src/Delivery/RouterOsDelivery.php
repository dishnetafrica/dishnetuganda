<?php
declare(strict_types=1);
namespace Dn\Delivery;

use Dn\Db\Database;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Devices\DeviceRegistry;
use Throwable;

/**
 * Carries an intent to a router over REST, and reads back to confirm.
 *
 * Only Dn\Jobs may construct this (guarded). It is the single place in the
 * codebase that opens a connection to a router.
 *
 * *** UNPROVEN ON HARDWARE. ***
 * docs/30 Artifact 13 rule 2 requires a real RouterOS CHR instance, because
 * "a fake MikroTik would pass while the real one rejects the command". The
 * tests for this class run against tests/fake_routeros.php, which speaks the
 * REST SHAPE and therefore proves this code's logic and nothing about
 * RouterOS's acceptance of it. tools/chr_harness.sh runs the same paths
 * against a real CHR and has not been run — see its header.
 */
final class RouterOsDelivery implements DeliveryPort
{
    /** @param null|callable(array):RestClient $clientFactory seam for tests */
    public function __construct(private $clientFactory = null) {}

    public function deliver(Database $db, array $intent): DeliveryResult
    {
        return match ($intent['kind']) {
            'device.provision'   => $this->provision($db, $intent),
            'session.disconnect' => $this->disconnect($db, $intent),
            // With RADIUS the credential lives in our database and FreeRADIUS
            // reads it; a router holds no per-voucher state. So publishing a
            // batch is not a router operation. What must be true is that the
            // hotspot is RADIUS-backed, which provisioning established and
            // confirm() re-reads.
            'voucher.publish',
            'voucher.revoke'     => $this->assertRadiusBacked($db, $intent),
            default              => DeliveryResult::permanent(
                                        'no delivery is defined for ' . $intent['kind']),
        };
    }

    public function confirm(Database $db, array $intent): bool
    {
        try {
            return match ($intent['kind']) {
                'device.provision'   => $this->divergenceIsEmpty($db, $intent),
                'session.disconnect' => $this->sessionIsGone($db, $intent),
                'voucher.publish',
                'voucher.revoke'     => $this->assertRadiusBacked($db, $intent)->accepted,
                default              => false,
            };
        } catch (Throwable) {
            // Cannot read it back means not confirmed. Never means confirmed.
            return false;
        }
    }

    // -----------------------------------------------------------------------
    private function provision(Database $db, array $intent): DeliveryResult
    {
        $device = $this->device($db, $intent);
        if ($device === null) { return DeliveryResult::permanent('device not found'); }
        if ($device['tunnel_ip'] === null) {
            return DeliveryResult::retryable('no tunnel address yet');
        }

        $client = $this->client($db, $device);
        $registry = new DeviceRegistry($db);
        $desired = json_decode((string) $registry->config($device['id'])['desired'], true) ?: [];

        foreach ($desired as $path => $values) {
            $res = $client->patch($path, is_array($values) ? $values : ['value' => $values]);
            if ($res['status'] >= 500) {
                return DeliveryResult::retryable("router returned {$res['status']} for {$path}");
            }
            if ($res['status'] >= 400) {
                // The router understood and refused. Retrying an identical
                // rejected request is how a queue spends itself on one row.
                return DeliveryResult::permanent("router rejected {$path} with {$res['status']}");
            }
        }
        return DeliveryResult::accepted();
    }

    private function disconnect(Database $db, array $intent): DeliveryResult
    {
        $device = $this->device($db, $intent);
        if ($device === null) { return DeliveryResult::permanent('device not found'); }
        $client = $this->client($db, $device);
        $payload = json_decode((string) $intent['payload'], true) ?: [];
        $res = $client->post('ip/hotspot/active/remove',
                             ['.id' => $payload['nas_session_id'] ?? '']);
        if ($res['status'] >= 500) { return DeliveryResult::retryable('router error'); }
        // A session that is already gone is the outcome we wanted.
        return DeliveryResult::accepted();
    }

    private function assertRadiusBacked(Database $db, array $intent): DeliveryResult
    {
        $device = $this->device($db, $intent);
        if ($device === null) {
            // Not every voucher intent names a device — a batch spans a site.
            // Nothing to check, and nothing to do on a router.
            return DeliveryResult::accepted();
        }
        $res = $this->client($db, $device)->get('ip/hotspot/profile');
        if ($res['status'] >= 500) { return DeliveryResult::retryable('router error'); }
        foreach ((array) ($res['body'] ?? []) as $profile) {
            if (($profile['use-radius'] ?? 'no') === 'yes') { return DeliveryResult::accepted(); }
        }
        return DeliveryResult::retryable('hotspot is not RADIUS-backed yet');
    }

    private function divergenceIsEmpty(Database $db, array $intent): bool
    {
        $device = $this->device($db, $intent);
        if ($device === null) { return false; }

        $registry = new DeviceRegistry($db);
        $client = $this->client($db, $device);
        $desired = json_decode((string) $registry->config($device['id'])['desired'], true) ?: [];

        $actual = [];
        foreach (array_keys($desired) as $path) {
            $res = $client->get($path);
            if ($res['status'] >= 400) { return false; }
            $actual[$path] = $res['body'];
        }
        $registry->setActual($device['id'], $actual);

        return $registry->divergence($device['id']) === [];
    }

    private function sessionIsGone(Database $db, array $intent): bool
    {
        $device = $this->device($db, $intent);
        if ($device === null) { return false; }
        $payload = json_decode((string) $intent['payload'], true) ?: [];
        $res = $this->client($db, $device)->get('ip/hotspot/active');
        if ($res['status'] >= 400) { return false; }
        foreach ((array) ($res['body'] ?? []) as $a) {
            if (($a['.id'] ?? null) === ($payload['nas_session_id'] ?? '')) { return false; }
        }
        return true;
    }

    private function device(Database $db, array $intent): ?array
    {
        $payload = json_decode((string) $intent['payload'], true) ?: [];
        $id = $payload['device_id'] ?? ($intent['target_type'] === 'device' ? $intent['target_id'] : null);
        return $id === null ? null : $db->one('SELECT * FROM mt_devices WHERE id = ?', [$id]);
    }

    private function client(Database $db, array $device): RestClient
    {
        if ($this->clientFactory !== null) { return ($this->clientFactory)($device); }
        $creds = (new DeviceRegistry($db))->credentials($device['id']);
        if ($creds === null) { throw new \RuntimeException('no credentials for device'); }
        return new RestClient($device['tunnel_ip'], $creds['username'], $creds['password']);
    }
}
