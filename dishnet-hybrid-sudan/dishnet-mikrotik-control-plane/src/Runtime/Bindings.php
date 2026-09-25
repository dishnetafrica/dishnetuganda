<?php
declare(strict_types=1);
namespace Dn\Runtime;

use Dn\Delivery\DeliveryPort;
use Dn\Delivery\NullDelivery;
use Dn\Delivery\RouterOsDelivery;
use Dn\Delivery\SimulatedRouterOs;
use Dn\Radius\NullPublisher;
use Dn\Radius\RadiusPublisherPort;
use Dn\Radius\SimulatedPublisher;

/**
 * Which external-system binding this process is running, and the gate that
 * keeps the real ones out of F6-A.
 *
 * F6-A builds the application. F6-B activates it against real MikroTik and
 * real FreeRADIUS, and is NOT authorized. The difference must be a property of
 * the running process that can be read and asserted — not a deployment note
 * somebody remembers.
 *
 * So: a real binding is selectable ONLY when DN_ALLOW_REAL_BINDINGS is set to
 * the exact string 'yes-f6b-authorized'. No default sets it, no test sets it,
 * and asking for a real binding without it throws rather than silently falling
 * back — a silent fallback to a simulator is how a fake ends up believed.
 *
 * DN_DELIVERY selects the worker's delivery binding (docs/118 D-7):
 *   unset / null   → NullDelivery       delivers nothing, says so
 *   simulated      → SimulatedRouterOs  in-memory router; refuses if the gate is open
 *   routeros       → RouterOsDelivery   REQUIRES the gate; throws without it
 *   anything else  → throws
 * There is no fallback in any direction, and RestClient checks the gate
 * again at the socket, so no construction path reaches a router without it.
 */
final class Bindings
{
    public const REAL_GATE_ENV   = 'DN_ALLOW_REAL_BINDINGS';
    public const REAL_GATE_VALUE = 'yes-f6b-authorized';
    public const DELIVERY_ENV    = 'DN_DELIVERY';
    public const DELIVERY_MODES  = ['null', 'simulated', 'routeros'];

    public function __construct(
        private readonly DeliveryPort $delivery,
        private readonly RadiusPublisherPort $publisher,
    ) {}

    /**
     * The default for every F6-A process: nothing is delivered, nothing is
     * published, and both say so.
     */
    public static function defaults(): self
    {
        return new self(new NullDelivery(), new NullPublisher());
    }

    /** The development/test wiring: deterministic doubles, clearly labelled. */
    public static function simulated(?DeliveryPort $delivery = null): self
    {
        return new self($delivery ?? new SimulatedRouterOs(), new SimulatedPublisher());
    }

    /**
     * The worker's wiring, from the environment. Throws — never falls back —
     * on an unknown mode, on a real binding without the gate, and on a
     * simulator inside a gated process.
     */
    public static function fromEnvironment(): self
    {
        $mode = self::configuredDeliveryName();
        $delivery = match ($mode) {
            'null'      => new NullDelivery(),
            'simulated' => new SimulatedRouterOs(),
            'routeros'  => (static function (): DeliveryPort {
                self::requireRealBindingsAllowed('RouterOsDelivery');
                return new RouterOsDelivery();
            })(),
            default     => throw new \RuntimeException(
                self::DELIVERY_ENV . "='{$mode}' is not a delivery binding; one of: "
                . implode(', ', self::DELIVERY_MODES)),
        };
        // The AAA publisher has no real binding to select yet (Decision 7's
        // publisher is unbuilt), so it stays null here whatever the mode.
        return new self($delivery, new NullPublisher());
    }

    /** What DN_DELIVERY asks for, normalised; 'null' when unset. Constructs nothing. */
    public static function configuredDeliveryName(): string
    {
        $v = strtolower(trim(getenv('DN_DELIVERY') ?: ''));   // literal: the manifest sweep reads it
        return $v === '' ? 'null' : $v;
    }

    /** True only when F6-B has been explicitly authorized in the environment. */
    public static function realBindingsAllowed(): bool
    {
        return (getenv(self::REAL_GATE_ENV) ?: '') === self::REAL_GATE_VALUE;
    }

    /**
     * @throws \RuntimeException when F6-B is not authorized. Deliberately not
     *         a fallback: the caller asked for the real world and must be told
     *         it cannot have it.
     */
    public static function requireRealBindingsAllowed(string $what): void
    {
        if (!self::realBindingsAllowed()) {
            throw new \RuntimeException(
                "{$what} is an F6-B binding and is not authorized: set "
                . self::REAL_GATE_ENV . " to enable it");
        }
    }

    public function delivery(): DeliveryPort { return $this->delivery; }
    public function publisher(): RadiusPublisherPort { return $this->publisher; }

    /**
     * What /api/v1/admin/health reports. Everything here is a fact about this
     * process, so a screen can never imply a router was contacted when it was
     * not. `delivery_configured` is what DN_DELIVERY asks the WORKER to bind;
     * the API process itself always holds the null delivery.
     */
    public function describe(): array
    {
        return [
            'delivery_binding'      => $this->delivery->bindingName(),
            'delivery_simulated'    => $this->delivery->isSimulated(),
            'delivery_configured'   => self::configuredDeliveryName(),
            'publisher_binding'     => $this->publisher->bindingName(),
            'publisher_simulated'   => $this->publisher->isSimulated(),
            'real_bindings_allowed' => self::realBindingsAllowed(),
            'phase'                 => self::realBindingsAllowed() ? 'F6-B' : 'F6-A',
        ];
    }
}
