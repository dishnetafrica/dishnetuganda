<?php
declare(strict_types=1);
namespace Dn\Delivery;

use Dn\Db\Database;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Devices\DeviceRegistry;
use Dn\Runtime\Bindings;
use Throwable;

/**
 * The MikroTik delivery adapter: carries an intent to a router over REST, and
 * reads back to confirm. (The instruction for G-C calls this the
 * "MikroTikDeliveryAdapter" and RestClient the "RouterOsClient"; the names
 * here predate it and are kept — docs/118 D-1.)
 *
 * Only Dn\Jobs may construct this (guarded). It is the single place in the
 * codebase that decides to open a connection to a router, and it can only do
 * so behind the F6-B gate — checked here AND at the socket in RestClient.
 *
 * What it will not do (docs/118 §B):
 *   - take a destination, a serial or a credential from an intent payload:
 *     the device row, read under the intent's own tenant context, is the only
 *     source (DeliveryTarget, D-6);
 *   - deliver to a device the registry does not record as connected or later,
 *     or change any device's state itself (D-4);
 *   - write to a router whose reported serial is not the registered one
 *     (D-5, H8 — VERSION/MODEL DEPENDENT);
 *   - treat a malformed answer as a state, or let an address or credential
 *     reach mt_intents.last_error (D-10).
 *
 * *** UNPROVEN ON HARDWARE. ***
 * docs/30 Artifact 13 rule 2 requires a real RouterOS CHR instance, because
 * "a fake MikroTik would pass while the real one rejects the command". The
 * tests for this class run against tests/fake_routeros.php, which speaks the
 * REST SHAPE and therefore proves this code's logic and nothing about
 * RouterOS's acceptance of it. tools/chr_harness.sh runs the same paths
 * against a real CHR and has not been run — see its header. Every
 * hardware-dependent assumption is labelled in docs/118 §C; none is
 * HARDWARE VERIFIED.
 */
final class RouterOsDelivery implements DeliveryPort
{
    /** Values that must never reach an error message. Filled as clients are built. */
    private array $scrub = [];

    /**
     * @param null|callable(array):RestClient $clientFactory seam for tests
     * @param bool $requireSerial refuse a router that reports no serial.
     *        True by default (fail closed). Only tools/chr_harness.sh may pass
     *        false: a CHR has no RouterBOARD (docs/30 Artifact 13 rule 3).
     */
    public function __construct(private $clientFactory = null, private bool $requireSerial = true) {}

    public function bindingName(): string { return 'routeros'; }
    public function isSimulated(): bool { return false; }

    public function deliver(Database $db, array $intent): DeliveryResult
    {
        try {
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
        } catch (Throwable $e) {
            // The worker records the message in mt_intents.last_error.
            throw new \RuntimeException($this->scrubbed($e->getMessage()), 0, $e);
        }
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
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent);
        if ($refusal !== null) { return $refusal; }
        if ($device === null) { return DeliveryResult::permanent('device.provision names no device'); }

        $client = $this->client($db, $device);
        $identity = $this->verifyIdentity($client, $device);
        if ($identity !== null) { return $identity; }

        $registry = new DeviceRegistry($db);
        $desired = json_decode((string) $registry->config($device['id'])['desired'], true) ?: [];

        foreach ($desired as $path => $values) {
            $res = $client->patch($path, is_array($values) ? $values : ['value' => $values]);
            if ($res['malformed']) {
                return DeliveryResult::retryable("router answered {$path} with a malformed response");
            }
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
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent);
        if ($refusal !== null) { return $refusal; }
        if ($device === null) { return DeliveryResult::permanent('session.disconnect names no device'); }
        $payload = DeliveryTarget::payload($intent);
        $nas = $payload['nas_session_id'] ?? null;
        if (!is_string($nas) || $nas === '') { return DeliveryResult::permanent('session.disconnect names no session'); }

        $client = $this->client($db, $device);
        $identity = $this->verifyIdentity($client, $device);
        if ($identity !== null) { return $identity; }

        // H6 — UNRESOLVED: that ip/hotspot/active/remove takes `.id`.
        $res = $client->post('ip/hotspot/active/remove', ['.id' => $nas]);
        if ($res['malformed']) { return DeliveryResult::retryable('router answered with a malformed response'); }
        if ($res['status'] >= 500) { return DeliveryResult::retryable('router error'); }
        // A session that is already gone is the outcome we wanted.
        return DeliveryResult::accepted();
    }

    private function assertRadiusBacked(Database $db, array $intent): DeliveryResult
    {
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent);
        if ($refusal !== null) { return $refusal; }
        if ($device === null) {
            // Not every voucher intent names a device — a batch spans a site.
            // Nothing to check, and nothing to do on a router.
            return DeliveryResult::accepted();
        }
        // H5 — VERSION/MODEL DEPENDENT: that ip/hotspot/profile carries use-radius.
        $res = $this->client($db, $device)->get('ip/hotspot/profile');
        if ($res['malformed']) { return DeliveryResult::retryable('router answered with a malformed response'); }
        if ($res['status'] >= 500) { return DeliveryResult::retryable('router error'); }
        foreach ((array) ($res['body'] ?? []) as $profile) {
            if (is_array($profile) && ($profile['use-radius'] ?? 'no') === 'yes') { return DeliveryResult::accepted(); }
        }
        return DeliveryResult::retryable('hotspot is not RADIUS-backed yet');
    }

    private function divergenceIsEmpty(Database $db, array $intent): bool
    {
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent);
        if ($refusal !== null || $device === null) { return false; }

        $client = $this->client($db, $device);
        if ($this->verifyIdentity($client, $device) !== null) { return false; }

        $registry = new DeviceRegistry($db);
        $desired = json_decode((string) $registry->config($device['id'])['desired'], true) ?: [];

        $actual = [];
        foreach (array_keys($desired) as $path) {
            $res = $client->get($path);
            if ($res['malformed'] || $res['status'] >= 400) { return false; }
            $actual[$path] = $res['body'];
        }
        $registry->setActual($device['id'], $actual);

        return $registry->divergence($device['id']) === [];
    }

    private function sessionIsGone(Database $db, array $intent): bool
    {
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent);
        if ($refusal !== null || $device === null) { return false; }
        $payload = DeliveryTarget::payload($intent);
        $res = $this->client($db, $device)->get('ip/hotspot/active');
        if ($res['malformed'] || $res['status'] >= 400) { return false; }
        foreach ((array) ($res['body'] ?? []) as $a) {
            if (is_array($a) && ($a['.id'] ?? null) === ($payload['nas_session_id'] ?? '')) { return false; }
        }
        return true;
    }

    /**
     * The identity read (docs/118 D-5; docs/31 A6 "Gateway reads serial over
     * the tunnel; it matches the registry"). A mismatch is permanent and
     * touches nothing: the device behind this tunnel address is not the
     * router the registry says it is. This is a consistency guard, not the
     * trust anchor — the WireGuard key at the transport layer is that, and
     * docs/30 §6.3 warns the serial may be spoofable.
     *
     * @return DeliveryResult|null null when the identity is confirmed
     */
    private function verifyIdentity(RestClient $client, array $device): ?DeliveryResult
    {
        $res = $client->routerboard();
        if ($res['malformed'] || $res['status'] >= 500) {
            return DeliveryResult::retryable('router identity could not be read');
        }
        $reported = is_array($res['body']) ? trim((string) ($res['body']['serial-number'] ?? '')) : '';
        if ($res['status'] >= 400 || $reported === '') {
            // H8: no RouterBOARD (CHR), or a version that spells the field
            // differently. Fail closed unless the harness said otherwise.
            return $this->requireSerial
                ? DeliveryResult::permanent('router reports no serial; identity cannot be confirmed')
                : null;
        }
        if (!hash_equals(strtoupper((string) $device['serial']), strtoupper($reported))) {
            return DeliveryResult::permanent(
                'router identity mismatch: the device behind this tunnel address is not the registered router');
        }
        return null;
    }

    private function client(Database $db, array $device): RestClient
    {
        if ($this->clientFactory !== null) {
            $this->scrub = array_values(array_unique(array_filter(
                array_merge($this->scrub, [(string) $device['tunnel_ip']]))));
            return ($this->clientFactory)($device);
        }
        // Belt and braces with RestClient: no factory means a real socket, and
        // a real socket is F6-B.
        Bindings::requireRealBindingsAllowed('RouterOsDelivery');
        $creds = (new DeviceRegistry($db))->credentials($device['id']);
        if ($creds === null) { throw new \RuntimeException('no credentials for device'); }
        $this->scrub = array_values(array_unique(array_filter(array_merge(
            $this->scrub, [$creds['password'], $creds['username'], (string) $device['tunnel_ip']]))));
        return new RestClient($device['tunnel_ip'], $creds['username'], $creds['password']);
    }

    private function scrubbed(string $text): string
    {
        foreach ($this->scrub as $s) { $text = str_replace($s, '[redacted]', $text); }
        return $text;
    }
}
